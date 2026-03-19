<?php
// api/index.php — 단일 진입점 JSON API
// 사용 예:
//   GET  /api/?action=list&user_id=1&ym=2026-03
//   GET  /api/?action=summary&user_id=1&ym=2026-03
//   GET  /api/?action=breakdown&user_id=1&ym=2026-03
//   GET  /api/?action=categories&user_id=1
//   POST /api/?action=add    (form body: user_id, category_id, amount, description, tx_date)
//   POST /api/?action=delete (form body: id, user_id)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../transaction/create.php';
require_once __DIR__ . '/../transaction/list.php';
require_once __DIR__ . '/../transaction/delete.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 1);

try {
    switch ($action) {

        // ── 거래 목록 ────────────────────────────────────────────────
        case 'list':
            $ym         = $_GET['ym']          ?? null;
            $type       = $_GET['type']        ?? null;
            $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
            echo json_encode(getTransactions($userId, $ym, $type, $categoryId));
            break;

        // ── 월 요약 (수입/지출/잔액) ─────────────────────────────────
        case 'summary':
            $ym = $_GET['ym'] ?? date('Y-m');
            echo json_encode(getMonthlySummary($userId, $ym));
            break;

        // ── 카테고리별 지출 비중 ─────────────────────────────────────
        case 'breakdown':
            $ym = $_GET['ym'] ?? date('Y-m');
            echo json_encode(getCategoryBreakdown($userId, $ym));
            break;

        // ── 카테고리 목록 ────────────────────────────────────────────
        case 'categories':
            $pdo  = getConnection();
            $stmt = $pdo->prepare(
                "SELECT id, name, type, icon FROM categories
                 WHERE user_id = :uid ORDER BY type, name"
            );
            $stmt->execute([':uid' => $userId]);
            echo json_encode($stmt->fetchAll());
            break;

        // ── 거래 추가 ────────────────────────────────────────────────
        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST 요청만 허용됩니다.']);
                break;
            }
            $categoryId  = isset($_POST['category_id']) && $_POST['category_id'] !== ''
                            ? (int) $_POST['category_id'] : null;
            $amount      = (int) ($_POST['amount'] ?? 0);
            $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $txDate      = $_POST['tx_date'] ?? date('Y-m-d');
            $source      = in_array($_POST['source'] ?? '', ['manual','auto','sms','ocr'])
                            ? $_POST['source'] : 'manual';

            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '금액은 1원 이상이어야 합니다.']);
                break;
            }
            $id = insertTransaction($userId, $categoryId, $amount, $description, $txDate, $source);
            echo json_encode(['status' => 'ok', 'id' => $id]);
            break;

        // ── 거래 삭제 ────────────────────────────────────────────────
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST 요청만 허용됩니다.']);
                break;
            }
            $txId = (int) ($_POST['id'] ?? 0);
            if ($txId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '유효하지 않은 ID입니다.']);
                break;
            }
            $ok = deleteTransaction($txId, $userId);
            echo json_encode([
                'status'  => $ok ? 'ok' : 'error',
                'message' => $ok ? '삭제되었습니다.' : '해당 내역을 찾을 수 없습니다.',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '알 수 없는 action입니다.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB 오류: ' . $e->getMessage()]);
}
