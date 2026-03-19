<?php
// config/db.php — DB 연결 (InfinityFree 서버용)

define('DB_HOST',    'sql100.infinityfree.com');
define('DB_NAME',    'if0_41427872_db');
define('DB_USER',    'if0_41427872');
define('DB_PASS',    'Oy7H125r8sEchW');
define('DB_CHARSET', 'utf8mb4');

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
    return $pdo;
}
