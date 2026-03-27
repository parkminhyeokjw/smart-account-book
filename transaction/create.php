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

// HTTP 처리는 api/index.php 에서 담당합니다.
