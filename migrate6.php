<?php
require_once __DIR__ . '/config/db.php';
$pdo = getConnection();

function run($pdo, $label, $sql) {
    try { $pdo->exec($sql); echo "OK  $label<br>"; }
    catch (PDOException $e) { echo "ERR $label: " . $e->getMessage() . "<br>"; }
}

// 유니크 제약 추가 (이미 있으면 무시)
run($pdo, 'categories 유니크 키 추가',
    "ALTER TABLE categories ADD UNIQUE KEY uniq_cat (user_id, name, type)"
);

// 현재 상태 출력
echo "<br><b>categories 인덱스:</b><br>";
foreach ($pdo->query("SHOW INDEX FROM categories")->fetchAll() as $r) {
    echo "  " . $r['Key_name'] . " / " . $r['Column_name'] . "<br>";
}
echo "<br><b>전체 rows:</b> " . $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
echo "<br><br><b style='color:red'>완료 후 migrate6.php를 삭제해주세요!</b>";
