<?php
require_once __DIR__ . '/config/db.php';
$pdo = getConnection();

function run($pdo, $label, $sql) {
    try { $pdo->exec($sql); echo "OK  $label<br>"; }
    catch (PDOException $e) { echo "ERR $label: " . $e->getMessage() . "<br>"; }
}

// name 단독 인덱스 제거 (있을 경우만)
$indexes = $pdo->query("SHOW INDEX FROM categories")->fetchAll();
$hasBadIndex = false;
foreach ($indexes as $idx) {
    if ($idx['Key_name'] === 'name') { $hasBadIndex = true; break; }
}
if ($hasBadIndex) {
    run($pdo, 'name 단독 인덱스 제거', "ALTER TABLE categories DROP INDEX `name`");
} else {
    echo "OK  name 인덱스 없음 (이미 정상)<br>";
}

// uniq_cat 없으면 추가
$hasUniq = false;
foreach ($indexes as $idx) {
    if ($idx['Key_name'] === 'uniq_cat') { $hasUniq = true; break; }
}
if (!$hasUniq) {
    run($pdo, 'uniq_cat 추가', "ALTER TABLE categories ADD UNIQUE KEY uniq_cat (user_id, name, type)");
} else {
    echo "OK  uniq_cat 이미 존재<br>";
}

// 인덱스 최종 확인
echo "<br><b>최종 인덱스:</b><br>";
foreach ($pdo->query("SHOW INDEX FROM categories")->fetchAll() as $r) {
    echo "  [{$r['Non_unique']}] {$r['Key_name']} / {$r['Column_name']}<br>";
}
echo "<br><b>전체 rows:</b> " . $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
echo "<br><br><b style='color:red'>완료 후 migrate7.php를 삭제해주세요!</b>";
