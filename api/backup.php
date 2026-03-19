<?php
// api/backup.php — 가계부 데이터 백업/복구 API
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$action = $_GET['action'] ?? '';
$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 1);

// ── 내보내기 ──────────────────────────────────────────────
if ($action === 'export') {
    $pdo = getConnection();

    $cats = $pdo->prepare("SELECT * FROM categories WHERE user_id = :uid ORDER BY id");
    $cats->execute([':uid' => $userId]);

    $txs = $pdo->prepare("SELECT * FROM transactions WHERE user_id = :uid ORDER BY tx_date, id");
    $txs->execute([':uid' => $userId]);

    $budgets = $pdo->prepare("SELECT * FROM budgets WHERE user_id = :uid ORDER BY id");
    $budgets->execute([':uid' => $userId]);

    $recur = $pdo->prepare("SELECT * FROM recurring_items WHERE user_id = :uid ORDER BY id");
    $recur->execute([':uid' => $userId]);

    echo json_encode([
        'version'         => '1.0',
        'app'             => '똑똑가계부',
        'created_at'      => date('c'),
        'user_id'         => $userId,
        'categories'      => $cats->fetchAll(),
        'transactions'    => $txs->fetchAll(),
        'budgets'         => $budgets->fetchAll(),
        'recurring_items' => $recur->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 가져오기 ──────────────────────────────────────────────
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['transactions'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '유효하지 않은 백업 파일입니다.']);
        exit;
    }

    $pdo = getConnection();
    $pdo->beginTransaction();

    try {
        // 외래키 제약 일시 해제
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $pdo->prepare("DELETE FROM budgets WHERE user_id = :uid")->execute([':uid' => $userId]);
        $pdo->prepare("DELETE FROM recurring_items WHERE user_id = :uid")->execute([':uid' => $userId]);
        $pdo->prepare("DELETE FROM transactions WHERE user_id = :uid")->execute([':uid' => $userId]);
        $pdo->prepare("DELETE FROM categories WHERE user_id = :uid")->execute([':uid' => $userId]);

        // 카테고리
        $stmtCat = $pdo->prepare(
            "INSERT INTO categories (id, user_id, name, type, icon) VALUES (:id,:uid,:name,:type,:icon)
             ON DUPLICATE KEY UPDATE name=VALUES(name), type=VALUES(type), icon=VALUES(icon)"
        );
        foreach ($data['categories'] ?? [] as $c) {
            $stmtCat->execute([
                ':id' => $c['id'], ':uid' => $userId,
                ':name' => $c['name'], ':type' => $c['type'], ':icon' => $c['icon'] ?? null,
            ]);
        }

        // 거래 내역
        $stmtTx = $pdo->prepare(
            "INSERT INTO transactions (id, user_id, category_id, amount, description, source, tx_date, created_at)
             VALUES (:id,:uid,:cat,:amt,:desc,:src,:date,:cat_at)
             ON DUPLICATE KEY UPDATE amount=VALUES(amount)"
        );
        foreach ($data['transactions'] ?? [] as $t) {
            $stmtTx->execute([
                ':id' => $t['id'], ':uid' => $userId,
                ':cat' => $t['category_id'], ':amt' => $t['amount'],
                ':desc' => $t['description'], ':src' => $t['source'] ?? 'manual',
                ':date' => $t['tx_date'], ':cat_at' => $t['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }

        // 예산
        $stmtBudget = $pdo->prepare(
            "INSERT INTO budgets (id, user_id, category_id, limit_amount, year_month)
             VALUES (:id,:uid,:cat,:lim,:ym)
             ON DUPLICATE KEY UPDATE limit_amount=VALUES(limit_amount)"
        );
        foreach ($data['budgets'] ?? [] as $b) {
            $stmtBudget->execute([
                ':id' => $b['id'], ':uid' => $userId,
                ':cat' => $b['category_id'], ':lim' => $b['limit_amount'], ':ym' => $b['year_month'],
            ]);
        }

        // 반복 항목
        $stmtRec = $pdo->prepare(
            "INSERT INTO recurring_items (id, user_id, category_id, description, amount, day_of_month, is_active)
             VALUES (:id,:uid,:cat,:desc,:amt,:day,:act)
             ON DUPLICATE KEY UPDATE description=VALUES(description), amount=VALUES(amount)"
        );
        foreach ($data['recurring_items'] ?? [] as $r) {
            $stmtRec->execute([
                ':id' => $r['id'], ':uid' => $userId,
                ':cat' => $r['category_id'], ':desc' => $r['description'],
                ':amt' => $r['amount'], ':day' => $r['day_of_month'], ':act' => $r['is_active'] ?? 1,
            ]);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->commit();
        echo json_encode(['status' => 'ok', 'message' => '복구가 완료되었습니다.'], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '복구 중 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => '알 수 없는 action입니다.'], JSON_UNESCAPED_UNICODE);
