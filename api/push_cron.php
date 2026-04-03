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

$messages = [
    '오늘도 가계부 작성하셔야죠! ✏️',
    '오늘 소비 내역 기록하셨나요? 📝',
    '가계부 작성 시간이에요! 💰',
    '오늘 지출, 빠뜨리지 않으셨죠? 📒',
    '똑똑한 소비 습관, 오늘도 기록하세요! 🌟',
];
$body = $messages[array_rand($messages)];

$sent   = 0;
$failed = 0;
foreach ($subs as $sub) {
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
        // 만료된 구독 삭제
        $pdo->prepare("DELETE FROM push_subscriptions WHERE id=:id")->execute([':id' => $sub['id']]);
        $failed++;
    } else {
        $failed++;
    }
}

echo json_encode(['sent' => $sent, 'failed' => $failed, 'time' => $currentTime, 'total' => count($subs)]);
