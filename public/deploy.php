<?php
// deploy.php — 원격 배포 수신기
// 사용법: POST /public/deploy.php
//   Header: X-Deploy-Token: <TOKEN>
//   Body (JSON): { "path": "public/index.php", "content": "...base64..." }

define('DEPLOY_TOKEN', 'mab_deploy_8f3k2p9x7q'); // 고정 토큰

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'POST only']);
    exit;
}

$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if ($token !== DEPLOY_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Forbidden']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['path'], $body['content'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'path and content required']);
    exit;
}

// 경로 검증 — htdocs 내부만 허용
$base    = realpath(__DIR__ . '/..');
$relPath = ltrim($body['path'], '/');
$absPath = realpath($base . '/' . $relPath);

// realpath가 null이면 파일이 없는 것 — dirname으로 검증
if (!$absPath) {
    $absPath = $base . '/' . $relPath;
}
if (strpos($absPath, $base) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Path not allowed']);
    exit;
}

$content = base64_decode($body['content']);
if ($content === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Invalid base64']);
    exit;
}

// 디렉터리 생성
$dir = dirname($absPath);
if (!is_dir($dir)) mkdir($dir, 0755, true);

if (file_put_contents($absPath, $content) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Write failed']);
    exit;
}

// OPcache 무효화
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($absPath, true);
}

echo json_encode(['ok' => true, 'path' => $relPath, 'size' => strlen($content)]);
