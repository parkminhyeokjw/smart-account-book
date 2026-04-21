<?php
// api/push_cron.php — 매분 외부 크론(cron-job.org 등)에서 호출
// URL: https://smart-account-book.infinityfreeapp.com/api/push_cron.php?secret=ddgb_cron_9x2k7p

define('CRON_SECRET', 'ddgb_cron_9x2k7p');

if (($_GET['secret'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/webpush.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getConnection();

// 현재 시각 (Asia/Seoul)
$tz   = new DateTimeZone('Asia/Seoul');
$now  = new DateTime('now', $tz);
$currentTime = $now->format('H:i');

try {
    $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE notif_time = :t");
    $stmt->execute([':t' => $currentTime]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'table not found', 'time' => $currentTime]);
    exit;
}

$todayStr = $now->format('Y-m-d');

$noTxMessages = [
    '오늘 가계부 아직 안 썼어요! 잊기 전에 기록해보세요 📝',
    '오늘 지출, 아직 기록 안 하셨죠? 빠뜨리지 마세요 💸',
    '가계부 작성 시간이에요! 오늘 소비 내역을 남겨보세요 ✏️',
];

$sent   = 0;
$failed = 0;
foreach ($subs as $sub) {
    // 오늘 이 유저의 거래 내역 확인
    $stmt2 = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN tx_type='expense' THEN amount ELSE 0 END), 0) AS total_exp
         FROM transactions
         WHERE user_id = :uid AND tx_date = :today"
    );
    $stmt2->execute([':uid' => $sub['user_id'], ':today' => $todayStr]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    $cnt = (int)($row['cnt'] ?? 0);
    $exp = (int)($row['total_exp'] ?? 0);

    if ($cnt > 0) {
        $body = "오늘 {$cnt}건 잘 기록했어요! 오늘 지출은 " . number_format($exp) . "원이에요 💰";
    } else {
        $body = $noTxMessages[array_rand($noTxMessages)];
    }

    $result = send_web_push(
        $sub['endpoint'],
        $sub['p256dh'],
        $sub['auth'],
        json_encode(['title' => '마이가계부 📒', 'body' => $body])
    );
    $code = $result['code'];
    if ($code === 201 || $code === 200) {
        $sent++;
    } elseif ($code === 410 || $code === 404) {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE id=:id")->execute([':id' => $sub['id']]);
        $failed++;
    } else {
        $failed++;
    }
}

echo json_encode(['sent' => $sent, 'failed' => $failed, 'time' => $currentTime, 'total' => count($subs)]);
