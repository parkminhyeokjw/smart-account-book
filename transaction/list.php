<?php
// transaction/list.php — 거래 내역 조회

require_once __DIR__ . '/../config/db.php';

/**
 * 거래 내역 목록 조회
 *
 * @param int         $userId
 * @param string|null $yearMonth  'YYYY-MM' 형식 (null = 전체)
 * @param string|null $type       'income' | 'expense' | null
 * @param int|null    $categoryId
 * @return array
 */
function getTransactions(
    int    $userId,
    ?string $yearMonth  = null,
    ?string $type       = null,
    ?int    $categoryId = null
): array {
    $pdo    = getConnection();
    $where  = ['t.user_id = :user_id'];
    $params = [':user_id' => $userId];

    if ($yearMonth !== null) {
        $where[]               = "DATE_FORMAT(t.tx_date, '%Y-%m') = :ym";
        $params[':ym']         = $yearMonth;
    }
    if ($type !== null) {
        $where[]               = 'c.type = :type';
        $params[':type']       = $type;
    }
    if ($categoryId !== null) {
        $where[]               = 't.category_id = :cat_id';
        $params[':cat_id']     = $categoryId;
    }

    $sql = "SELECT
                t.id,
                t.amount,
                t.description,
                t.source,
                t.tx_date,
                t.created_at,
                c.name  AS category_name,
                c.type  AS category_type
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.tx_date DESC, t.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 월별 수입/지출 합계
 */
function getMonthlySummary(int $userId, string $yearMonth): array
{
    $pdo  = getConnection();
    $stmt = $pdo->prepare(
        "SELECT
             COALESCE(c.type, t.tx_type, 'expense') AS type,
             COALESCE(SUM(t.amount), 0) AS total
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :user_id
           AND DATE_FORMAT(t.tx_date, '%Y-%m') = :ym
         GROUP BY COALESCE(c.type, t.tx_type, 'expense')"
    );
    $stmt->execute([':user_id' => $userId, ':ym' => $yearMonth]);

    $result = ['income' => 0, 'expense' => 0];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($result[$row['type']])) {
            $result[$row['type']] = (int) $row['total'];
        }
    }
    $result['balance'] = $result['income'] - $result['expense'];
    return $result;
}

/**
 * 카테고리별 지출 합계 (월별)
 */
function getCategoryBreakdown(int $userId, string $yearMonth): array
{
    $pdo  = getConnection();
    $stmt = $pdo->prepare(
        "SELECT
             c.name  AS category,
             SUM(t.amount) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :user_id
           AND c.type = 'expense'
           AND DATE_FORMAT(t.tx_date, '%Y-%m') = :ym
         GROUP BY c.id, c.name
         ORDER BY total DESC"
    );
    $stmt->execute([':user_id' => $userId, ':ym' => $yearMonth]);
    return $stmt->fetchAll();
}
