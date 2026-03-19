<?php
// public/budget_save.php — 예산 저장/수정 엔드포인트
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST만 허용']);
    exit;
}

$id             = (int)($_POST['id'] ?? 0);
$userId         = (int)($_POST['user_id'] ?? 1);
$name           = htmlspecialchars(trim($_POST['name'] ?? '전체 예산'), ENT_QUOTES, 'UTF-8');
$budgetType     = in_array($_POST['budget_type'] ?? '', ['weekly','monthly','yearly'])
                    ? $_POST['budget_type'] : 'monthly';
$limitAmount    = (int)($_POST['limit_amount'] ?? 0);
$categoryIds    = trim($_POST['category_ids']    ?? '');
$paymentMethods = trim($_POST['payment_methods'] ?? '');
$yearMonth      = $_POST['year_month'] ?? date('Y-m');

if ($limitAmount <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'금액을 입력해주세요.']);
    exit;
}

$pdo = getConnection();

if ($id > 0) {
    $stmt = $pdo->prepare(
        "UPDATE budgets SET name=:n, budget_type=:bt, limit_amount=:amt,
         category_ids=:cids, payment_methods=:pms
         WHERE id=:id AND user_id=:uid"
    );
    $stmt->execute([
        ':n'=>$name, ':bt'=>$budgetType, ':amt'=>$limitAmount,
        ':cids'=>$categoryIds, ':pms'=>$paymentMethods,
        ':id'=>$id, ':uid'=>$userId
    ]);
    echo json_encode(['status'=>'ok','affected'=>$stmt->rowCount()]);
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO budgets (user_id, name, budget_type, limit_amount, category_ids, payment_methods, `year_month`)
         VALUES (:uid,:n,:bt,:amt,:cids,:pms,:ym)"
    );
    $stmt->execute([
        ':uid'=>$userId, ':n'=>$name, ':bt'=>$budgetType,
        ':amt'=>$limitAmount, ':cids'=>$categoryIds,
        ':pms'=>$paymentMethods, ':ym'=>$yearMonth
    ]);
    echo json_encode(['status'=>'ok','id'=>$pdo->lastInsertId()]);
}
