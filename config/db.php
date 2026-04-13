<?php
// config/db.php — DB 연결 (InfinityFree 서버용)

define('DB_HOST',    'sql100.infinityfree.com');
define('DB_NAME',    'if0_41427872_db');
define('DB_USER',    'if0_41427872');
define('DB_PASS',    'park20061226');
define('DB_CHARSET', 'utf8mb4');

// 한국 시간(KST, UTC+9) 고정 — 서버가 UTC여도 날짜 계산이 한국 기준으로 동작
date_default_timezone_set('Asia/Seoul');

function getConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
    // MySQL도 한국 시간으로 — CURDATE(), NOW() 등이 KST 기준으로 동작
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}
