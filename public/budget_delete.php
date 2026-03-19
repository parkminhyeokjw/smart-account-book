<?php
// public/budget_delete.php — 예산 삭제 엔드포인트
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST만 허용']);
    exit;
}

$userId = requireLogin(true);
$id     = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'유효하지 않은 ID']);
    exit;
}

$pdo  = getConnection();
$stmt = $pdo->prepare("DELETE FROM budgets WHERE id=:id AND user_id=:uid");
$stmt->execute([':id'=>$id, ':uid'=>$userId]);

echo json_encode(['status'=>'ok','affected'=>$stmt->rowCount()]);
