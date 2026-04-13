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
    ?string $txType = null,
    ?string $photos = null
): int {
    $pdo = getConnection();
    // photos 컬럼 없으면 자동 추가
    try {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN photos MEDIUMTEXT DEFAULT NULL");
    } catch (PDOException $e) { /* 이미 있으면 무시 */ }

    $stmt = $pdo->prepare(
        "INSERT INTO transactions
             (user_id, category_id, amount, description, payment_method, source, tx_date, tx_type, photos)
         VALUES
             (:user_id, :category_id, :amount, :description, :payment_method, :source, :tx_date, :tx_type, :photos)"
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
        ':photos'         => $photos,
    ]);
    return (int) $pdo->lastInsertId();
}

// HTTP 처리는 api/index.php 에서 담당합니다.
