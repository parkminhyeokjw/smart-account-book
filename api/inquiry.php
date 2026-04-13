<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/webpush.php';

function ensureTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inquiries (
            id         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            user_id    INT UNSIGNED   DEFAULT NULL,
            user_name  VARCHAR(100)   NOT NULL DEFAULT '비회원',
            user_email VARCHAR(255)   NOT NULL DEFAULT '',
            subject    VARCHAR(200)   NOT NULL,
            body       TEXT           NOT NULL,
            is_read    TINYINT(1)     NOT NULL DEFAULT 0,
            created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inq_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 관리자 푸시 구독 테이블
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_push_subscriptions (
            id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint TEXT         NOT NULL,
            p256dh   TEXT         NOT NULL,
            auth     VARCHAR(100) NOT NULL,
            created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ep (endpoint(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// 관리자 푸시 알림 전송
function notifyAdmin(PDO $pdo, string $subject, string $userName): void {
    try {
        $subs = $pdo->query("SELECT endpoint, p256dh, auth FROM admin_push_subscriptions")->fetchAll();
        $payload = json_encode([
            'title' => '새 문의가 접수됐습니다 📬',
            'body'  => "[{$userName}] {$subject}",
        ]);
        foreach ($subs as $s) {
            send_web_push($s['endpoint'], $s['p256dh'], $s['auth'], $payload);
        }
    } catch (Exception $e) { /* 알림 실패해도 문의 등록은 유지 */ }
}

$method = $_SERVER['REQUEST_METHOD'];

// ── POST: 문의 등록 ──────────────────────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // 관리자 푸시 구독 저장 요청
    if (!empty($data['admin_push_subscribe'])) {
        if (empty($_SESSION['is_admin'])) {
            echo json_encode(['ok' => false, 'msg' => '권한 없음']); exit;
        }
        $ep  = trim($data['endpoint']         ?? '');
        $p   = trim($data['p256dh']           ?? '');
        $a   = trim($data['auth']             ?? '');
        if (!$ep || !$p || !$a) {
            echo json_encode(['ok' => false, 'msg' => '구독 정보 오류']); exit;
        }
        $pdo = getConnection();
        ensureTable($pdo);
        $pdo->prepare("
            INSERT INTO admin_push_subscriptions (endpoint, p256dh, auth)
            VALUES (:ep, :p, :a)
            ON DUPLICATE KEY UPDATE p256dh=:p2, auth=:a2
        ")->execute([':ep'=>$ep,':p'=>$p,':a'=>$a,':p2'=>$p,':a2'=>$a]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $subject = trim($data['subject'] ?? '');
    $body    = trim($data['body']    ?? '');

    if (!$subject || !$body) {
        echo json_encode(['ok' => false, 'msg' => '제목과 내용을 입력해주세요.']); exit;
    }
    if (mb_strlen($subject) > 200) {
        echo json_encode(['ok' => false, 'msg' => '제목은 200자 이내로 입력해주세요.']); exit;
    }
    if (mb_strlen($body) > 2000) {
        echo json_encode(['ok' => false, 'msg' => '내용은 2000자 이내로 입력해주세요.']); exit;
    }

    $pdo = getConnection();
    ensureTable($pdo);

    $userId    = !empty($_SESSION['user_id'])    ? (int)$_SESSION['user_id']   : null;
    $userName  = !empty($_SESSION['user_name'])  ? $_SESSION['user_name']       : '비회원';
    $userEmail = !empty($_SESSION['user_email']) ? $_SESSION['user_email']      : (trim($data['email'] ?? ''));

    $stmt = $pdo->prepare("
        INSERT INTO inquiries (user_id, user_name, user_email, subject, body)
        VALUES (:uid, :name, :email, :subject, :body)
    ");
    $stmt->execute([
        ':uid'     => $userId,
        ':name'    => $userName,
        ':email'   => $userEmail,
        ':subject' => $subject,
        ':body'    => $body,
    ]);

    // 관리자 푸시 알림
    notifyAdmin($pdo, $subject, $userName);

    echo json_encode(['ok' => true, 'msg' => '문의가 등록되었습니다. 빠르게 확인 후 답변드리겠습니다.']);
    exit;
}

// ── GET: 관리자 목록 조회 ────────────────────────────────────
if ($method === 'GET') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '권한이 없습니다.']); exit;
    }

    $pdo = getConnection();
    ensureTable($pdo);

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();
    $rows  = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC LIMIT $limit OFFSET $offset")->fetchAll();

    echo json_encode(['ok' => true, 'total' => $total, 'page' => $page, 'rows' => $rows]);
    exit;
}

// ── PATCH: 읽음 처리 ─────────────────────────────────────────
if ($method === 'PATCH') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '권한이 없습니다.']); exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($data['id'] ?? 0);

    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'id 누락']); exit; }

    $pdo = getConnection();
    ensureTable($pdo);
    $pdo->prepare("UPDATE inquiries SET is_read=1 WHERE id=:id")->execute([':id' => $id]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE: 관리자 푸시 구독 해제 ────────────────────────────
if ($method === 'DELETE') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '권한이 없습니다.']); exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ep   = trim($data['endpoint'] ?? '');
    if ($ep) {
        $pdo = getConnection();
        ensureTable($pdo);
        $pdo->prepare("DELETE FROM admin_push_subscriptions WHERE endpoint=:ep")->execute([':ep' => $ep]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
