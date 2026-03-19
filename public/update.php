<?php
// public/update.php — 거래 수정 엔드포인트

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST만 허용']);
    exit;
}

$id            = (int) ($_POST['id']      ?? 0);
$userId        = (int) ($_POST['user_id'] ?? 1);
$categoryId    = isset($_POST['category_id']) && $_POST['category_id'] !== ''
                  ? (int) $_POST['category_id'] : null;
$amount        = (int) ($_POST['amount']  ?? 0);
$description   = trim($_POST['description']    ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? '현금');
$txDate        = $_POST['tx_date'] ?? date('Y-m-d');

if ($id <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '유효하지 않은 데이터입니다.']);
    exit;
}

$pdo  = getConnection();
$stmt = $pdo->prepare(
    "UPDATE transactions
     SET category_id    = :cat,
         amount         = :amt,
         description    = :desc,
         payment_method = :pm,
         tx_date        = :date
     WHERE id = :id AND user_id = :uid"
);
$stmt->execute([
    ':cat'  => $categoryId,
    ':amt'  => $amount,
    ':desc' => $description,
    ':pm'   => $paymentMethod,
    ':date' => $txDate,
    ':id'   => $id,
    ':uid'  => $userId,
]);

echo json_encode(['status' => 'ok', 'affected' => $stmt->rowCount()]);
