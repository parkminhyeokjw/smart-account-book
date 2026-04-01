<?php
// api/index.php — 단일 진입점 JSON API

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../transaction/create.php';
require_once __DIR__ . '/../transaction/list.php';
require_once __DIR__ . '/../transaction/delete.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$userId = requireLogin(true);

// CSV/JSON 내보내기는 Content-Type을 덮어써야 하므로 early exit
if ($action === 'export_csv') {
    $pdo    = getConnection();
    $ym     = $_GET['ym'] ?? null;
    $where  = ['t.user_id = :uid'];
    $params = [':uid' => $userId];
    if ($ym) { $where[] = "DATE_FORMAT(t.tx_date,'%Y-%m') = :ym"; $params[':ym'] = $ym; }
    $sql  = "SELECT t.tx_date AS 날짜,
                    CASE COALESCE(c.type, t.tx_type, 'expense') WHEN 'income' THEN '수입' ELSE '지출' END AS 유형,
                    COALESCE(c.name,'미분류') AS 카테고리,
                    t.description AS 내용,
                    t.amount AS 금액,
                    COALESCE(t.payment_method,'') AS 결제수단
             FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
             WHERE " . implode(' AND ', $where) . " ORDER BY t.tx_date, t.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fname = '마이가계부_' . ($ym ?? '전체') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    if ($rows) fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if ($action === 'export_json') {
    $pdo   = getConnection();
    $txSt  = $pdo->prepare(
        "SELECT t.*, c.name AS category_name, c.type AS category_type
         FROM transactions t LEFT JOIN categories c ON c.id=t.category_id
         WHERE t.user_id=:uid ORDER BY t.tx_date DESC, t.id DESC"
    );
    $txSt->execute([':uid' => $userId]);
    $catSt = $pdo->prepare("SELECT * FROM categories WHERE user_id=:uid ORDER BY type, name");
    $catSt->execute([':uid' => $userId]);
    $fxSt  = $pdo->prepare("SELECT * FROM fixed_expenses WHERE user_id=:uid ORDER BY day_of_month");
    try { $fxSt->execute([':uid' => $userId]); $fxRows = $fxSt->fetchAll(); }
    catch (PDOException $e) { $fxRows = []; }
    $fname = '마이가계부_DB백업_' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"');
    echo json_encode([
        'version'        => 2,
        'exported_at'    => date('c'),
        'transactions'   => $txSt->fetchAll(),
        'categories'     => $catSt->fetchAll(),
        'fixed_expenses' => $fxRows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    switch ($action) {

        // ── 거래 목록 ────────────────────────────────────────────────
        case 'list':
            $ym         = $_GET['ym']          ?? null;
            $type       = $_GET['type']        ?? null;
            $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
            echo json_encode(getTransactions($userId, $ym, $type, $categoryId));
            break;

        // ── 월 요약 ──────────────────────────────────────────────────
        case 'summary':
            $ym = $_GET['ym'] ?? date('Y-m');
            echo json_encode(getMonthlySummary($userId, $ym));
            break;

        // ── 카테고리별 지출 비중 ─────────────────────────────────────
        case 'breakdown':
            $ym = $_GET['ym'] ?? date('Y-m');
            echo json_encode(getCategoryBreakdown($userId, $ym));
            break;

        // ── 카테고리 목록 (없으면 기본값 시딩) ──────────────────────
        case 'categories':
            $pdo  = getConnection();
            $defaults = [
                ['식비','🍚','expense'],['교통','🚌','expense'],['쇼핑','🛍️','expense'],
                ['의료','💊','expense'],['문화','🎬','expense'],['통신','📱','expense'],
                ['주거','🏠','expense'],['기타','📦','expense'],
                ['급여','💰','income'],['용돈','🎁','income'],['기타수입','💵','income'],
            ];
            $chk = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id=:uid AND name=:n AND type=:t");
            $ins = $pdo->prepare("INSERT INTO categories (user_id,name,icon,type) VALUES (:uid,:n,:i,:t)");
            foreach ($defaults as [$n,$i,$t]) {
                $chk->execute([':uid'=>$userId,':n'=>$n,':t'=>$t]);
                if ((int)$chk->fetchColumn() === 0) {
                    $ins->execute([':uid'=>$userId,':n'=>$n,':i'=>$i,':t'=>$t]);
                }
            }
            $stmt = $pdo->prepare("SELECT id, name, type, icon FROM categories WHERE user_id=:uid ORDER BY type, id");
            $stmt->execute([':uid'=>$userId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ── 거래 추가 ────────────────────────────────────────────────
        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST 요청만 허용됩니다.']);
                break;
            }
            $categoryId  = isset($_POST['category_id']) && $_POST['category_id'] !== ''
                            ? (int) $_POST['category_id'] : null;
            $amount      = (int) ($_POST['amount'] ?? 0);
            $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $txDate      = $_POST['tx_date'] ?? date('Y-m-d');
            $source      = in_array($_POST['source'] ?? '', ['manual','auto','sms','ocr'])
                            ? $_POST['source'] : 'manual';
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '금액은 1원 이상이어야 합니다.']);
                break;
            }
            $id = insertTransaction($userId, $categoryId, $amount, $description, $txDate, $source);
            echo json_encode(['status' => 'ok', 'id' => $id]);
            break;

        // ── 거래 삭제 ────────────────────────────────────────────────
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST 요청만 허용됩니다.']);
                break;
            }
            $txId = (int) ($_POST['id'] ?? 0);
            if ($txId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '유효하지 않은 ID입니다.']);
                break;
            }
            $ok = deleteTransaction($txId, $userId);
            echo json_encode([
                'status'  => $ok ? 'ok' : 'error',
                'message' => $ok ? '삭제되었습니다.' : '해당 내역을 찾을 수 없습니다.',
            ]);
            break;

        // ── 거래 수정 ────────────────────────────────────────────────
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $txId  = (int) ($_POST['id'] ?? 0);
            $amt   = (int) ($_POST['amount'] ?? 0);
            $desc  = trim($_POST['description'] ?? '');
            $date  = trim($_POST['date'] ?? '');
            $pay   = trim($_POST['payment'] ?? '');
            $type  = in_array($_POST['type'] ?? '', ['expense','income']) ? $_POST['type'] : 'expense';
            $catName = trim($_POST['category'] ?? '');
            if ($txId <= 0 || $amt <= 0 || !$date) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'필수값 누락']); break; }
            $pdo = getConnection();
            // category_id 조회 (없으면 null)
            $cs = $pdo->prepare("SELECT id FROM categories WHERE user_id=:uid AND name=:n LIMIT 1");
            $cs->execute([':uid'=>$userId, ':n'=>$catName]);
            $catId = $cs->fetchColumn() ?: null;
            $pdo->prepare("UPDATE transactions SET amount=:a, description=:d, tx_date=:dt, payment_method=:p, tx_type=:t, category_id=:c WHERE id=:id AND user_id=:uid")
                ->execute([':a'=>$amt,':d'=>$desc,':dt'=>$date,':p'=>$pay,':t'=>$type,':c'=>$catId,':id'=>$txId,':uid'=>$userId]);
            echo json_encode(['status' => 'ok']);
            break;

        // ── 카테고리 수정 ────────────────────────────────────────────
        case 'categories_update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $catId = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $icon  = trim($_POST['icon'] ?? '📦');
            if (!$catId || !$name) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'id/name 필요']); break; }
            $pdo  = getConnection();
            $pdo->prepare("UPDATE categories SET name=:n, icon=:i WHERE id=:id AND user_id=:uid")
                ->execute([':n'=>$name,':i'=>$icon,':id'=>$catId,':uid'=>$userId]);
            echo json_encode(['status' => 'ok']);
            break;

        // ── 카테고리 추가 ────────────────────────────────────────────
        case 'categories_add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $name = trim($_POST['name'] ?? '');
            $icon = trim($_POST['icon'] ?? '📦');
            $type = in_array($_POST['type'] ?? '', ['expense','income']) ? $_POST['type'] : 'expense';
            if (!$name) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'name 필요']); break; }
            $pdo  = getConnection();
            $pdo->prepare("INSERT INTO categories (user_id,name,type,icon) VALUES (:uid,:n,:t,:i)")
                ->execute([':uid'=>$userId,':n'=>$name,':t'=>$type,':i'=>$icon]);
            $newId = (int)$pdo->lastInsertId();
            $allStmt = $pdo->prepare("SELECT id, name, type, icon FROM categories WHERE user_id=:uid ORDER BY type, id");
            $allStmt->execute([':uid'=>$userId]);
            echo json_encode(['status'=>'ok','id'=>$newId,'categories'=>$allStmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── 카테고리 삭제 ────────────────────────────────────────────
        case 'categories_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $catId = (int)($_POST['id'] ?? 0);
            if (!$catId) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'id 필요']); break; }
            $pdo = getConnection();
            $pdo->prepare("DELETE FROM categories WHERE id=:id AND user_id=:uid")
                ->execute([':id'=>$catId,':uid'=>$userId]);
            $allStmt2 = $pdo->prepare("SELECT id, name, type, icon FROM categories WHERE user_id=:uid ORDER BY type, id");
            $allStmt2->execute([':uid'=>$userId]);
            echo json_encode(['status'=>'ok','categories'=>$allStmt2->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── 프로필 통계 ──────────────────────────────────────────────
        case 'profile':
            $pdo = getConnection();
            $ym  = date('Y-m');
            // 이번 달 거래 횟수
            $s1 = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id=:uid AND DATE_FORMAT(tx_date,'%Y-%m')=:ym");
            $s1->execute([':uid'=>$userId,':ym'=>$ym]);
            $monthCount = (int)$s1->fetchColumn();
            // 연속 기록일 (최근 90일)
            $s2 = $pdo->prepare("SELECT DISTINCT DATE(tx_date) AS d FROM transactions WHERE user_id=:uid AND tx_date >= DATE_SUB(CURDATE(),INTERVAL 90 DAY) ORDER BY d DESC");
            $s2->execute([':uid'=>$userId]);
            $dateSet = array_column($s2->fetchAll(PDO::FETCH_ASSOC), 'd');
            $streak  = 0;
            $check   = in_array(date('Y-m-d'), $dateSet) ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
            while (in_array($check, $dateSet)) {
                $streak++;
                $check = date('Y-m-d', strtotime($check . ' -1 day'));
            }
            // 배지
            $badge = '';
            if      ($monthCount >= 50) $badge = '자산 수비대 🛡️';
            elseif  ($monthCount >= 20) $badge = '절약 탐험가 🧭';
            elseif  ($monthCount >=  5) $badge = '기록 새싹 🌱';
            echo json_encode(['month_count'=>$monthCount,'streak'=>$streak,'badge'=>$badge]);
            break;

        // ── 고정 지출 목록 ───────────────────────────────────────────
        case 'fixed_list':
            $pdo  = getConnection();
            $stmt = $pdo->prepare(
                "SELECT f.id, f.name, f.amount, f.type,
                        COALESCE(f.cycle,'monthly') AS cycle,
                        f.day_of_month, f.day_of_week, f.month_of_year,
                        f.category_id, c.name AS category_name
                 FROM fixed_expenses f
                 LEFT JOIN categories c ON c.id=f.category_id
                 WHERE f.user_id=:uid ORDER BY f.id"
            );
            $stmt->execute([':uid'=>$userId]);
            echo json_encode($stmt->fetchAll());
            break;

        // ── 고정 지출 추가 ───────────────────────────────────────────
        case 'fixed_add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $name        = trim($_POST['name'] ?? '');
            $amount      = (int)($_POST['amount'] ?? 0);
            $type        = in_array($_POST['type']??'', ['expense','income']) ? $_POST['type'] : 'expense';
            $cycle       = in_array($_POST['cycle']??'', ['weekly','monthly','yearly']) ? $_POST['cycle'] : 'monthly';
            $dayOfMonth  = max(1, min(31, (int)($_POST['day_of_month'] ?? 1)));
            $dayOfWeek   = isset($_POST['day_of_week'])   && $_POST['day_of_week']   !== '' ? (int)$_POST['day_of_week']   : null;
            $monthOfYear = isset($_POST['month_of_year']) && $_POST['month_of_year'] !== '' ? (int)$_POST['month_of_year'] : null;
            $catId       = isset($_POST['category_id'])   && $_POST['category_id']   !== '' ? (int)$_POST['category_id']   : null;
            $applyNow    = ($_POST['apply_now'] ?? '0') === '1';

            if (!$name || $amount <= 0) {
                http_response_code(400);
                echo json_encode(['status'=>'error','message'=>'이름과 금액이 필요합니다.']);
                break;
            }
            $pdo = getConnection();
            $pdo->prepare(
                "INSERT INTO fixed_expenses (user_id,name,amount,type,cycle,day_of_month,day_of_week,month_of_year,category_id)
                 VALUES (:uid,:n,:a,:t,:cy,:dom,:dow,:moy,:c)"
            )->execute([':uid'=>$userId,':n'=>$name,':a'=>$amount,':t'=>$type,
                        ':cy'=>$cycle,':dom'=>$dayOfMonth,':dow'=>$dayOfWeek,':moy'=>$monthOfYear,':c'=>$catId]);
            $newId = (int)$pdo->lastInsertId();

            $applied = 0;
            if ($applyNow) {
                // 소급 날짜: 지정한 날짜(monthly→이번달 dom, yearly→이번달 dom)로 기록
                $year   = (int)date('Y');
                $mon    = (int)date('n');
                $maxDay = (int)date('t', mktime(0, 0, 0, $mon, 1, $year)); // cal 확장 없이 월 마지막날 계산
                if ($cycle === 'monthly') {
                    $dom    = min($dayOfMonth, $maxDay);
                    $txDate = sprintf('%04d-%02d-%02d', $year, $mon, $dom);
                } elseif ($cycle === 'yearly') {
                    $moy    = $monthOfYear ?: $mon;
                    $maxD2  = (int)date('t', mktime(0, 0, 0, $moy, 1, $year));
                    $dom    = min($dayOfMonth, $maxD2);
                    $txDate = sprintf('%04d-%02d-%02d', $year, $moy, $dom);
                } else {
                    $txDate = date('Y-m-d'); // weekly는 오늘
                }
                $chkYm = substr($txDate, 0, 7);
                // 기존 거래 조회 (중복이어도 ID 반환해서 txs에 추가 가능하게)
                $chk = $pdo->prepare("SELECT id FROM transactions WHERE user_id=:uid AND LEFT(tx_date,7)=:ym AND payment_method='자동' AND description=:desc AND amount=:amt LIMIT 1");
                $chk->execute([':uid'=>$userId,':ym'=>$chkYm,':desc'=>$name,':amt'=>$amount]);
                $existingId = $chk->fetchColumn();
                if ($existingId) {
                    // 이미 있으면 기존 ID 반환
                    $txDbId = (int)$existingId;
                    $applied = 1;
                } else {
                    $pdo->prepare("INSERT INTO transactions (user_id,category_id,amount,description,payment_method,source,tx_date,tx_type) VALUES (:uid,:cid,:amt,:desc,'자동','manual',:dt,:type)")
                        ->execute([':uid'=>$userId,':cid'=>$catId,':amt'=>$amount,':desc'=>$name,':dt'=>$txDate,':type'=>$type]);
                    $txDbId = (int)$pdo->lastInsertId();
                    $applied = 1;
                }
            }
            echo json_encode(['status'=>'ok','id'=>$newId,'applied'=>$applied,'tx_date'=>$txDate??null,'tx_db_id'=>$txDbId??null]);
            break;

        // ── 고정 지출 삭제 ───────────────────────────────────────────
        case 'fixed_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $fxId = (int)($_POST['id'] ?? 0);
            if (!$fxId) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'id 필요']); break; }
            $pdo = getConnection();
            $pdo->prepare("DELETE FROM fixed_expenses WHERE id=:id AND user_id=:uid")
                ->execute([':id'=>$fxId,':uid'=>$userId]);
            echo json_encode(['status'=>'ok']);
            break;

        // ── 고정 지출 자동 적용 (앱 접속 시 호출) ────────────────────
        case 'fixed_apply':
            $pdo    = getConnection();
            $year   = (int)date('Y');
            $mon    = (int)date('n');
            $todayD = (int)date('j');
            $todayW = (int)date('w'); // 0=일,1=월...6=토
            $today  = date('Y-m-d');
            $maxDay = cal_days_in_month(CAL_GREGORIAN, $mon, $year);

            $stmt = $pdo->prepare("SELECT * FROM fixed_expenses WHERE user_id=:uid");
            $stmt->execute([':uid'=>$userId]);
            $fixed = $stmt->fetchAll();
            $added = 0;
            $newItems = [];
            // 중복 체크: source='fixed' ENUM 문제 우회, payment_method='자동' + 이번달 기준으로 체크
            $chkSt = $pdo->prepare(
                "SELECT COUNT(*) FROM transactions
                 WHERE user_id=:uid AND LEFT(tx_date,7)=:ym AND description=:desc AND amount=:amt AND payment_method='자동'"
            );
            $insSt = $pdo->prepare("INSERT INTO transactions (user_id,category_id,amount,description,payment_method,source,tx_date,tx_type) VALUES (:uid,:cid,:amt,:desc,'자동','manual',:dt,:type)");

            foreach ($fixed as $f) {
                $cycle = $f['cycle'] ?? 'monthly';
                $targetDt = null;

                if ($cycle === 'weekly') {
                    if ((int)$f['day_of_week'] === $todayW) $targetDt = $today;
                } elseif ($cycle === 'monthly') {
                    $dom = min((int)$f['day_of_month'], $maxDay);
                    if ($dom <= $todayD) $targetDt = sprintf('%04d-%02d-%02d', $year, $mon, $dom);
                } elseif ($cycle === 'yearly') {
                    $moy = (int)$f['month_of_year'];
                    $maxD2 = cal_days_in_month(CAL_GREGORIAN, $moy, $year);
                    $dom = min((int)$f['day_of_month'], $maxD2);
                    if ($moy === $mon && $dom <= $todayD)
                        $targetDt = sprintf('%04d-%02d-%02d', $year, $mon, $dom);
                }

                if (!$targetDt) continue;

                // 중복 체크: 이번 달에 같은 이름·금액·자동 결제가 이미 있으면 스킵
                $ym = substr($targetDt, 0, 7); // 'YYYY-MM'
                $chkSt->execute([':uid'=>$userId,':ym'=>$ym,':desc'=>$f['name'],':amt'=>$f['amount']]);
                if ($chkSt->fetchColumn() > 0) continue;

                $insSt->execute([':uid'=>$userId,':cid'=>$f['category_id'],':amt'=>$f['amount'],':desc'=>$f['name'],':dt'=>$targetDt,':type'=>$f['type']]);
                $added++;
                $newItems[] = [
                    'id'          => 'fixed_apply_' . $pdo->lastInsertId(),
                    'type'        => $f['type'],
                    'amount'      => (int)$f['amount'],
                    'category'    => $f['name'],
                    'description' => $f['name'],
                    'date'        => $targetDt,
                    'payment'     => '자동',
                    'photos'      => [],
                ];
            }
            echo json_encode(['status'=>'ok','added'=>$added,'items'=>$newItems]);
            break;

        // ── 설정 저장 (다크모드, 알림시간 등) ───────────────────────
        case 'settings_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $key     = trim($_POST['key'] ?? '');
            $val     = trim($_POST['value'] ?? '');
            $allowed = ['dark_mode','notif_time','notif_enabled','nickname','avatar_color','avatar_img','surv_budget'];
            if (!in_array($key, $allowed)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid key']); break; }
            if (!isset($_SESSION['settings'])) $_SESSION['settings'] = [];
            $_SESSION['settings'][$key] = $val;
            $pdo = getConnection();
            // 컬럼 없으면 추가 (MySQL 5.x 호환: 에러 무시)
            try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN nickname VARCHAR(40) DEFAULT ''"); } catch(PDOException $e2){}
            try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN avatar_color TINYINT DEFAULT 0"); } catch(PDOException $e2){}
            try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN avatar_img LONGTEXT DEFAULT NULL"); } catch(PDOException $e2){}
            try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN surv_budget TEXT DEFAULT NULL"); } catch(PDOException $e2){}
            try {
                $pdo->prepare("INSERT INTO user_settings (user_id) VALUES (:uid) ON DUPLICATE KEY UPDATE user_id=user_id")
                    ->execute([':uid'=>$userId]);
                $pdo->prepare("UPDATE user_settings SET `$key`=:val WHERE user_id=:uid")
                    ->execute([':val'=>$val,':uid'=>$userId]);
            } catch (PDOException $e) {}
            echo json_encode(['status'=>'ok']);
            break;

        // ── 설정 조회 ────────────────────────────────────────────────
        case 'settings_get':
            $pdo = getConnection();
            try {
                // 컬럼 누락 대비: 없으면 추가
                try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN nickname VARCHAR(40) DEFAULT ''"); } catch(PDOException $e2){}
                try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN avatar_img LONGTEXT DEFAULT NULL"); } catch(PDOException $e2){}
                try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN surv_budget TEXT DEFAULT NULL"); } catch(PDOException $e2){}
                $stmt = $pdo->prepare("SELECT dark_mode, notif_time, notif_enabled, COALESCE(nickname,'') AS nickname, COALESCE(avatar_color,0) AS avatar_color, COALESCE(avatar_img,'') AS avatar_img, COALESCE(surv_budget,'') AS surv_budget FROM user_settings WHERE user_id=:uid");
                $stmt->execute([':uid'=>$userId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC)
                    ?: ['dark_mode'=>0,'notif_time'=>'21:00','notif_enabled'=>0,'nickname'=>'','avatar_color'=>0,'avatar_img'=>'','surv_budget'=>''];
            } catch (PDOException $e) {
                $settings = ['dark_mode'=>0,'notif_time'=>'21:00','notif_enabled'=>0,'nickname'=>'','avatar_color'=>0,'avatar_img'=>'','surv_budget'=>''];
            }
            // 세션 값 우선
            if (!empty($_SESSION['settings'])) {
                foreach ($_SESSION['settings'] as $k => $v) {
                    if (isset($settings[$k])) $settings[$k] = $v;
                }
            }
            echo json_encode($settings);
            break;

        // ── 전체 내역 삭제 (2단계 확인) ─────────────────────────────
        case 'truncate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            if (($_POST['confirm'] ?? '') !== 'DELETE_ALL_CONFIRMED') {
                http_response_code(400);
                echo json_encode(['status'=>'error','message'=>'확인 코드가 올바르지 않습니다.']);
                break;
            }
            $pdo     = getConnection();
            $stmt    = $pdo->prepare("DELETE FROM transactions WHERE user_id=:uid");
            $stmt->execute([':uid'=>$userId]);
            $deleted = $stmt->rowCount();
            echo json_encode(['status'=>'ok','deleted'=>$deleted]);
            break;

        // ── 내역 추가 (카테고리명 → ID 자동 변환) ─────────────────
        case 'add_tx':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error']); break; }
            $amt     = (int)($_POST['amount'] ?? 0);
            $desc    = trim($_POST['description'] ?? '');
            $date    = trim($_POST['date'] ?? '');
            $type    = in_array($_POST['type'] ?? '', ['expense','income']) ? $_POST['type'] : 'expense';
            $catName = trim($_POST['category'] ?? '');
            $pay     = trim($_POST['payment'] ?? '') ?: '현금';
            if ($amt <= 0 || !$date) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'필수값 누락']); break; }
            $pdo = getConnection();
            $cs  = $pdo->prepare("SELECT id FROM categories WHERE user_id=:uid AND name=:n LIMIT 1");
            $cs->execute([':uid'=>$userId, ':n'=>$catName]);
            $catId = $cs->fetchColumn() ?: null;
            $newId = insertTransaction($userId, $catId, $amt, $desc ?: $catName, $date, 'manual', $pay, $type);
            echo json_encode(['status'=>'ok','db_id'=>$newId]);
            break;

        // ── 전체 내역 동기화 (새 기기 로그인 시) ──────────────────
        case 'sync_pull':
            $pdo  = getConnection();
            $stmt = $pdo->prepare(
                "SELECT t.id, t.amount, t.description, t.tx_date,
                        COALESCE(c.type, t.tx_type, 'expense') AS tx_type,
                        COALESCE(t.payment_method, '')          AS payment_method,
                        COALESCE(c.name, '기타')                AS category_name
                 FROM transactions t
                 LEFT JOIN categories c ON c.id = t.category_id
                 WHERE t.user_id = :uid
                 ORDER BY t.tx_date DESC, t.id DESC"
            );
            $stmt->execute([':uid' => $userId]);
            $rows   = $stmt->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[] = [
                    'db_id'       => (int)$r['id'],
                    'amount'      => (int)$r['amount'],
                    'description' => $r['description'],
                    'date'        => $r['tx_date'],
                    'type'        => $r['tx_type'],
                    'category'    => $r['category_name'],
                    'payment'     => $r['payment_method'],
                ];
            }
            echo json_encode(['status'=>'ok','transactions'=>$result]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '알 수 없는 action입니다.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB 오류: ' . $e->getMessage()]);
}
