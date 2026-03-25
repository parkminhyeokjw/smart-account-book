<?php
require_once __DIR__ . '/config/db.php';
$pdo = getConnection();

function run($pdo, $label, $sql) {
    try { $pdo->exec($sql); echo "OK  $label<br>"; }
    catch (PDOException $e) { echo "ERR $label: " . $e->getMessage() . "<br>"; }
}

// categories 테이블 생성 (없을 때만)
run($pdo, 'categories 테이블 생성',
    "CREATE TABLE IF NOT EXISTS categories (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        name       VARCHAR(50)  NOT NULL,
        icon       VARCHAR(10)  NOT NULL DEFAULT '📦',
        type       ENUM('expense','income') NOT NULL DEFAULT 'expense',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// 현재 컬럼 확인
echo "<br><b>categories 컬럼:</b> ";
echo implode(', ', $pdo->query("SHOW COLUMNS FROM categories")->fetchAll(PDO::FETCH_COLUMN));
echo "<br><b>현재 rows:</b> " . $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
echo "<br><br><b style='color:red'>완료 후 migrate5.php를 삭제해주세요!</b>";
