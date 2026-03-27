<?php
// transaction/delete.php — 거래 내역 삭제

require_once __DIR__ . '/../config/auth.php';

function deleteTransaction(int $txId, int $userId): bool
{
    $pdo  = getConnection();
    $stmt = $pdo->prepare(
        "DELETE FROM transactions WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $txId, ':user_id' => $userId]);
    return $stmt->rowCount() > 0;
}

// HTTP 처리는 api/index.php 에서 담당합니다.
