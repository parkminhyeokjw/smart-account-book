# Smart Account Book — 프로젝트 분석 문서

## 1. 현재 상태 요약

| 항목 | 상태 |
|------|------|
| 프로젝트 디렉터리 | `~/smart-account-book` |
| 참조 스크립트 | `~/OneDrive/바탕 화면/account_book.py` |
| DB | MySQL |
| 기술 스택 | PHP + MySQL |

### 기존 스크립트 (`account_book.py`) 분석

```python
import datetime
today = datetime.date.today()
money = int(input("얼마를 사용했습니까?"))
print("오늘의 날짜:", today)
if money > 10000:
    print("지출과다!!!")
else:
    print("절약성공!!!")
```

**한계점**
- 데이터 저장 없음 (실행 후 소멸)
- 단일 항목만 입력 가능
- 카테고리·날짜 범위 검색 불가
- 반복 입력/자동화 없음

---

## 2. 기술 스택

| 계층 | 선택 |
|------|------|
| 언어 | PHP 8.1+ |
| DB | MySQL 8.0+ |
| DB 접근 | PDO (PHP Data Objects) |
| 웹 서버 | Apache / Nginx |
| 자동화 (반복 입력) | PHP CLI + Linux Cron |

---

## 3. DB 설계

### 3.1 ERD (Entity Relationship Diagram)

```
users ────────────────────────────┐
│ id (PK, AUTO_INCREMENT)         │
│ name          VARCHAR(100)      │   1 : N
│ monthly_budget INT UNSIGNED     │
│ created_at    DATETIME          │
└─────────────────────────────────┘
          │ 1
          │
          │ N
categories ───────────────────────┐
│ id (PK, AUTO_INCREMENT)         │
│ user_id  (FK → users.id)        │
│ name     VARCHAR(100)           │
│ type     ENUM('income','expense')│
│ icon     VARCHAR(50)            │
└─────────────────────────────────┘
          │ 1
          │
          │ N
transactions ─────────────────────┐
│ id (PK, AUTO_INCREMENT)         │
│ user_id     (FK → users.id)     │
│ category_id (FK → categories.id)│
│ amount      INT UNSIGNED        │
│ description VARCHAR(255)        │
│ source      ENUM('manual',      │
│              'auto','sms','ocr')│
│ tx_date     DATE                │
│ created_at  DATETIME            │
└─────────────────────────────────┘
          │ 1
          │
          │ N (optional)
receipts ─────────────────────────┐
│ id              PK              │
│ transaction_id  FK              │
│ image_path      VARCHAR(500)    │
│ raw_text        TEXT (OCR 결과) │
│ parsed_at       DATETIME        │
└─────────────────────────────────┘

budgets ───────────────────────────┐
│ id            PK                 │
│ user_id       FK → users.id      │
│ category_id   FK → categories.id │
│ limit_amount  INT UNSIGNED        │
│ year_month    CHAR(7) '2026-03'  │
└───────────────────────────────────┘

recurring_items ───────────────────┐
│ id            PK                 │
│ user_id       FK → users.id      │
│ category_id   FK → categories.id │
│ description   VARCHAR(255)        │
│ amount        INT UNSIGNED        │
│ day_of_month  TINYINT (1~28)     │
│ is_active     TINYINT(1)         │
└───────────────────────────────────┘
```

---

### 3.2 MySQL 스키마

전체 DDL은 `schema.sql` 파일을 참고하세요.

---

## 4. 자동 입력 (Auto-Input) 로직

### 4.1 흐름도

```
[입력 소스]
  ├─ 수동 입력 (HTML Form → PHP)
  ├─ SMS/알림 파싱  ──→  [PHP 정규식 파서]  ──→  [PDO INSERT]  ──→  MySQL
  ├─ 영수증 OCR     ──→  [PHP + Tesseract]  ──┘
  └─ 반복 항목      ──→  [PHP CLI + Cron]   ──────────────────────────────┘
```

---

### 4.2 DB 연결 (PDO)

```php
<?php
// config/db.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'account_book');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

function getConnection(): PDO {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
```

---

### 4.3 거래 수동 입력

```php
<?php
// transaction/create.php

require_once '../config/db.php';

function insertTransaction(
    int $userId,
    int $categoryId,
    int $amount,
    string $description,
    string $txDate,
    string $source = 'manual'
): int {
    $pdo = getConnection();
    $sql = "INSERT INTO transactions
                (user_id, category_id, amount, description, source, tx_date)
            VALUES
                (:user_id, :category_id, :amount, :description, :source, :tx_date)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'     => $userId,
        ':category_id' => $categoryId,
        ':amount'      => $amount,
        ':description' => $description,
        ':source'      => $source,
        ':tx_date'     => $txDate,
    ]);
    return (int) $pdo->lastInsertId();
}

// HTML Form 처리 예시
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = insertTransaction(
        userId:      (int) $_POST['user_id'],
        categoryId:  (int) $_POST['category_id'],
        amount:      (int) $_POST['amount'],
        description: htmlspecialchars($_POST['description']),
        txDate:      $_POST['tx_date']
    );
    echo json_encode(['status' => 'ok', 'id' => $id]);
}
```

---

### 4.4 SMS / 카드 알림 파싱

```php
<?php
// auto/sms_parser.php

function parseCardSms(string $text): ?array {
    /*
     * 예시 문자:
     * "[신한카드] 1,500원 승인 홍길동님 스타벅스 04/01 14:32 1234"
     */
    $pattern = '/(\d[\d,]+)원.*?([가-힣a-zA-Z0-9\s]+?)\s+(\d{2}\/\d{2}).*?(\d{4})/u';

    if (!preg_match($pattern, $text, $m)) {
        return null;
    }

    [, $amountStr, $merchant, $dateStr, $cardLast4] = $m;

    $amount = (int) str_replace(',', '', $amountStr);
    [$month, $day] = explode('/', $dateStr);
    $txDate = date('Y') . '-' . $month . '-' . $day;

    return [
        'amount'     => $amount,
        'merchant'   => trim($merchant),
        'card_last4' => $cardLast4,
        'tx_date'    => $txDate,
        'source'     => 'sms',
    ];
}

// 사용 예
$sms = "[신한카드] 1,500원 승인 홍길동님 스타벅스 04/01 14:32 1234";
$data = parseCardSms($sms);
if ($data) {
    insertTransaction(1, 2, $data['amount'], $data['merchant'], $data['tx_date'], 'sms');
}
```

---

### 4.5 영수증 OCR (Tesseract)

```php
<?php
// auto/ocr_receipt.php

function ocrReceipt(string $imagePath): array {
    // Tesseract CLI 호출 (서버에 tesseract 설치 필요)
    $safePath = escapeshellarg($imagePath);
    $rawText  = shell_exec("tesseract {$safePath} stdout -l kor+eng 2>/dev/null");

    // 금액 추출 (숫자 + 원)
    preg_match_all('/(\d[\d,]+)\s*원/u', $rawText ?? '', $matches);
    $amounts = array_map(fn($a) => (int) str_replace(',', '', $a), $matches[1]);
    $total   = $amounts ? max($amounts) : 0;

    return [
        'raw_text'     => $rawText,
        'total_amount' => $total,
    ];
}
```

---

### 4.6 반복 항목 자동 등록 (PHP CLI + Cron)

#### `auto/insert_recurring.php` (PHP CLI 스크립트)

```php
<?php
// auto/insert_recurring.php
// Cron: 매일 08:00 실행
// 0 8 * * * php /var/www/smart-account-book/auto/insert_recurring.php

require_once __DIR__ . '/../config/db.php';

$pdo  = getConnection();
$today = (int) date('j');  // 오늘 날짜 (1~31)

// recurring_items 테이블에서 오늘 실행할 항목 조회
$stmt = $pdo->prepare(
    "SELECT * FROM recurring_items
     WHERE day_of_month = :day AND is_active = 1"
);
$stmt->execute([':day' => $today]);
$items = $stmt->fetchAll();

foreach ($items as $item) {
    $pdo->prepare(
        "INSERT INTO transactions
             (user_id, category_id, amount, description, source, tx_date)
         VALUES
             (:user_id, :category_id, :amount, :description, 'auto', CURDATE())"
    )->execute([
        ':user_id'     => $item['user_id'],
        ':category_id' => $item['category_id'],
        ':amount'      => $item['amount'],
        ':description' => $item['description'],
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] 자동 등록: {$item['description']} {$item['amount']}원\n";
}
```

#### Cron 등록 방법

```bash
# crontab -e 에 추가
0 8 * * * php /var/www/smart-account-book/auto/insert_recurring.php >> /var/log/account_book_cron.log 2>&1
```

---

### 4.7 자동 입력 소스별 비교

| 소스 | 정확도 | 구현 난이도 | 비고 |
|------|--------|------------|------|
| 수동 입력 | 100% | 낮음 | HTML Form + PDO INSERT |
| SMS 파싱 | 85~95% | 중간 | preg_match, 카드사별 패턴 상이 |
| 영수증 OCR | 70~85% | 높음 | Tesseract CLI 또는 Google Vision API |
| 반복 자동 등록 | 100% | 낮음 | PHP CLI + Linux Cron |

---

## 5. 다음 개발 단계 제안

1. **Phase 1 – 기본 CRUD**: `schema.sql` 적용 후 PHP PDO로 수동 입력·조회 구현
2. **Phase 2 – 자동화**: SMS 파싱 + `recurring_items` 기반 Cron 스케줄러 추가
3. **Phase 3 – OCR**: 영수증 이미지 업로드 → Tesseract 파싱 기능 추가
4. **Phase 4 – UI**: Bootstrap 기반 웹 UI (월별 차트, 카테고리별 통계)
