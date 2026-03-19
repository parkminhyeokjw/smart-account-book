<?php
// config/auth.php — 세션 인증 헬퍼

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * 로그인 확인. 미로그인 시:
 *  - $json=false → login.php 리다이렉트
 *  - $json=true  → 401 JSON 응답
 */
function requireLogin(bool $json = false): int
{
    if (empty($_SESSION['user_id'])) {
        if ($json) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => '로그인이 필요합니다.']);
            exit;
        }
        header('Location: /public/login.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}
