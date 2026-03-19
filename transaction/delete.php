<?php
// transaction/delete.php — 거래 내역 삭제

require_once __DIR__ . '/../config/db.php';

function deleteTransaction(int $txId, int $userId): bool
{
    $pdo  = getConnection();
    $stmt = $pdo->prepare(
        "DELETE FROM transactions WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $txId, ':user_id' => $userId]);
    return $stmt->rowCount() > 0;
}

// ---------- HTTP POST 처리 ----------
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $txId   = (int) ($_POST['id']      ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 1);

    if ($txId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '유효하지 않은 ID입니다.']);
        exit;
    }

    $ok = deleteTransaction($txId, $userId);
    echo json_encode(['status' => $ok ? 'ok' : 'error',
                      'message' => $ok ? '삭제되었습니다.' : '해당 내역을 찾을 수 없습니다.']);
}
