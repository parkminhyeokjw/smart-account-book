<?php
// transaction/create.php — 거래 내역 등록

require_once __DIR__ . '/../config/auth.php';

function insertTransaction(
    int    $userId,
    ?int   $categoryId,
    int    $amount,
    string $description,
    string $txDate,
    string $source = 'manual',
    string $paymentMethod = '현금',
    ?string $txType = null
): int {
    $pdo = getConnection();
    $stmt = $pdo->prepare(
        "INSERT INTO transactions
             (user_id, category_id, amount, description, payment_method, source, tx_date, tx_type)
         VALUES
             (:user_id, :category_id, :amount, :description, :payment_method, :source, :tx_date, :tx_type)"
    );
    $stmt->execute([
        ':user_id'        => $userId,
        ':category_id'    => $categoryId,
        ':amount'         => $amount,
        ':description'    => $description,
        ':payment_method' => $paymentMethod,
        ':source'         => $source,
        ':tx_date'        => $txDate,
        ':tx_type'        => $txType,
    ]);
    return (int) $pdo->lastInsertId();
}

// ---------- HTTP POST 처리 ----------
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $userId        = requireLogin(true);
    $categoryId    = isset($_POST['category_id']) && $_POST['category_id'] !== ''
                      ? (int) $_POST['category_id'] : null;
    $amount        = (int) ($_POST['amount'] ?? 0);
    $description   = trim($_POST['description'] ?? '');
    $txDate        = $_POST['tx_date'] ?? date('Y-m-d');
    $paymentMethod = trim($_POST['payment_method'] ?? '현금');
    $source        = in_array($_POST['source'] ?? '', ['manual','auto','sms','ocr'])
                      ? $_POST['source'] : 'manual';
    $txType        = in_array($_POST['tx_type'] ?? '', ['expense','income'])
                      ? $_POST['tx_type'] : null;

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '금액은 1원 이상이어야 합니다.']);
        exit;
    }

    $id = insertTransaction($userId, $categoryId, $amount, $description, $txDate, $source, $paymentMethod, $txType);
    echo json_encode(['status' => 'ok', 'id' => $id]);
}
