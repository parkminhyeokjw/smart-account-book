<?php
// auto/insert_recurring.php — 반복 항목 자동 등록
// Cron 예시 (매일 08:00):
//   0 8 * * * php "C:\Users\minjw\smart-account-book\auto\insert_recurring.php" >> "C:\Users\minjw\smart-account-book\auto\cron.log" 2>&1

require_once __DIR__ . '/../config/db.php';

$pdo   = getConnection();
$today = (int) date('j');   // 오늘 날짜 (1~31)

$stmt = $pdo->prepare(
    "SELECT * FROM recurring_items
     WHERE day_of_month = :day AND is_active = 1"
);
$stmt->execute([':day' => $today]);
$items = $stmt->fetchAll();

if (empty($items)) {
    echo "[" . date('Y-m-d H:i:s') . "] 오늘 등록할 반복 항목 없음\n";
    exit(0);
}

$insert = $pdo->prepare(
    "INSERT INTO transactions
         (user_id, category_id, amount, description, source, tx_date)
     VALUES
         (:user_id, :category_id, :amount, :description, 'auto', CURDATE())"
);

foreach ($items as $item) {
    $insert->execute([
        ':user_id'     => $item['user_id'],
        ':category_id' => $item['category_id'],
        ':amount'      => $item['amount'],
        ':description' => $item['description'],
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] 자동 등록 완료: "
        . $item['description'] . " " . number_format($item['amount']) . "원\n";
}
