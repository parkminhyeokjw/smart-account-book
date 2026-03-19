<?php
// api/export_excel.php — 현재 월 지출 내역을 CSV로 다운로드
require_once __DIR__ . '/../config/db.php';

$userId = (int)($_GET['user_id'] ?? 1);
$ym     = $_GET['ym'] ?? date('Y-m');
$type   = $_GET['type'] ?? 'expense'; // expense | income | all

if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$start = $ym . '-01';
$end   = date('Y-m-t', strtotime($start));

$pdo = getConnection();

$typeFilter = '';
$params = [':uid' => $userId, ':start' => $start, ':end' => $end];

if ($type !== 'all') {
    $typeFilter = "AND c.type = :type";
    $params[':type'] = $type;
}

$sql = "SELECT t.tx_date, c.name AS category, c.type AS cat_type,
               t.amount, t.description, t.source
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :uid
          AND t.tx_date BETWEEN :start AND :end
          $typeFilter
        ORDER BY t.tx_date, t.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = '똑똑가계부_' . str_replace('-', '', $ym) . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// BOM for Excel UTF-8 recognition
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['날짜', '유형', '카테고리', '금액', '메모', '입력방법']);

$typeMap = ['income' => '수입', 'expense' => '지출'];
$sourceMap = ['manual' => '직접입력', 'auto' => '자동', 'sms' => '문자', 'ocr' => 'OCR'];

foreach ($rows as $r) {
    fputcsv($out, [
        $r['tx_date'],
        $typeMap[$r['cat_type']] ?? $r['cat_type'],
        $r['category'] ?? '미분류',
        $r['amount'],
        $r['description'] ?? '',
        $sourceMap[$r['source']] ?? $r['source'],
    ]);
}

fclose($out);
