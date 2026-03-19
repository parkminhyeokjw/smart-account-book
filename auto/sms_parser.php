<?php
// auto/sms_parser.php — SMS(카드 알림) 파싱 후 자동 등록

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../transaction/create.php';

/**
 * 카드사 SMS 문자에서 금액·가맹점·날짜를 추출
 *
 * 지원 패턴 예시
 *  "[신한카드] 1,500원 승인 홍길동님 스타벅스 04/01 14:32 1234"
 *  "[KB국민카드] 승인 12,000원(일시불) 스타벅스 2026/04/01 1234"
 */
function parseCardSms(string $text): ?array
{
    // 패턴 1 — 신한, 삼성, 현대 형식: 금액원 ... 가맹점 MM/DD
    $p1 = '/(\d[\d,]+)\s*원.*?([가-힣a-zA-Z0-9\s]+?)\s+(\d{2}\/\d{2})/u';
    // 패턴 2 — KB 형식: 가맹점 YYYY/MM/DD
    $p2 = '/승인\s+(\d[\d,]+)\s*원.*?([가-힣a-zA-Z0-9\s]+?)\s+(\d{4}\/\d{2}\/\d{2})/u';

    if (preg_match($p2, $text, $m)) {
        $amount  = (int) str_replace(',', '', $m[1]);
        $txDate  = str_replace('/', '-', $m[3]);           // YYYY-MM-DD
        $merchant = trim($m[2]);
    } elseif (preg_match($p1, $text, $m)) {
        $amount  = (int) str_replace(',', '', $m[1]);
        [$month, $day] = explode('/', $m[3]);
        $txDate  = date('Y') . '-' . $month . '-' . $day;
        $merchant = trim($m[2]);
    } else {
        return null;
    }

    return [
        'amount'   => $amount,
        'merchant' => $merchant,
        'tx_date'  => $txDate,
        'source'   => 'sms',
    ];
}

/**
 * SMS 텍스트를 파싱하여 transactions 테이블에 INSERT
 * @return int|null  생성된 transaction.id, 실패 시 null
 */
function processSms(string $smsText, int $userId = 1, ?int $categoryId = null): ?int
{
    $data = parseCardSms($smsText);
    if ($data === null) {
        return null;
    }

    return insertTransaction(
        $userId,
        $categoryId,
        $data['amount'],
        $data['merchant'],
        $data['tx_date'],
        'sms'
    );
}

// ---------- CLI 테스트 ----------
if (php_sapi_name() === 'cli') {
    $sample = "[신한카드] 1,500원 승인 홍길동님 스타벅스 04/01 14:32 1234";
    $parsed = parseCardSms($sample);
    echo "파싱 결과: ";
    print_r($parsed);
}
