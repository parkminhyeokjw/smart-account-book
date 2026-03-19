<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$action    = $_GET['action']    ?? '';
$user_id   = intval($_GET['user_id'] ?? 1);

function queryKeyword($pdo, $user_id, $keyword, $category_id = null) {
    $like = '%' . $keyword . '%';
    $month_start = date('Y-m-01');

    // last_date
    $sql = "SELECT MAX(tx_date) AS last_date FROM transactions WHERE user_id = ? AND description LIKE ?";
    $params = [$user_id, $like];
    if ($category_id !== null && $category_id !== '') {
        $sql .= " AND category_id = ?";
        $params[] = intval($category_id);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $last_date = $row['last_date'] ? date('Y-m-d', strtotime($row['last_date'])) : null;

    // count_month
    $sql2 = "SELECT COUNT(*) AS cnt FROM transactions WHERE user_id = ? AND description LIKE ? AND tx_date >= ?";
    $params2 = [$user_id, $like, $month_start];
    if ($category_id !== null && $category_id !== '') {
        $sql2 .= " AND category_id = ?";
        $params2[] = intval($category_id);
    }
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute($params2);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    $count_month = intval($row2['cnt']);

    // total_month
    $sql3 = "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE user_id = ? AND description LIKE ? AND tx_date >= ?";
    $params3 = [$user_id, $like, $month_start];
    if ($category_id !== null && $category_id !== '') {
        $sql3 .= " AND category_id = ?";
        $params3[] = intval($category_id);
    }
    $stmt3 = $pdo->prepare($sql3);
    $stmt3->execute($params3);
    $row3 = $stmt3->fetch(PDO::FETCH_ASSOC);
    $total_month = intval($row3['total']);

    return [
        'keyword'     => $keyword,
        'last_date'   => $last_date,
        'count_month' => $count_month,
        'total_month' => $total_month,
    ];
}

try {
    $pdo = getConnection();

    if ($action === 'query') {
        $keywords_raw = $_GET['keywords'] ?? '';
        $category_id  = $_GET['category_id'] ?? null;
        $keywords = array_filter(array_map('trim', explode(',', $keywords_raw)));

        if (empty($keywords)) {
            echo json_encode(['status' => 'error', 'message' => 'keywords required']);
            exit;
        }

        // Return result for first keyword (single-keyword query)
        $keyword = reset($keywords);
        $result = queryKeyword($pdo, $user_id, $keyword, $category_id);
        echo json_encode(array_merge(['status' => 'ok'], $result));

    } elseif ($action === 'query_all') {
        $patterns_raw = $_GET['patterns'] ?? '[]';
        $patterns = json_decode($patterns_raw, true);
        if (!is_array($patterns)) {
            echo json_encode(['status' => 'error', 'message' => 'invalid patterns']);
            exit;
        }

        $results = [];
        foreach ($patterns as $pattern) {
            $id          = $pattern['id']          ?? null;
            $subject     = $pattern['subject']     ?? '';
            $keywords_str = $pattern['keywords']   ?? '';
            $category_id = $pattern['category_id'] ?? null;

            $keywords = array_filter(array_map('trim', explode(',', $keywords_str)));

            $last_date   = null;
            $count_month = 0;
            $total_month = 0;

            foreach ($keywords as $kw) {
                $r = queryKeyword($pdo, $user_id, $kw, $category_id);
                // Aggregate: latest last_date, sum counts and totals
                if ($r['last_date'] !== null) {
                    if ($last_date === null || $r['last_date'] > $last_date) {
                        $last_date = $r['last_date'];
                    }
                }
                $count_month += $r['count_month'];
                $total_month += $r['total_month'];
            }

            $results[] = [
                'id'          => $id,
                'subject'     => $subject,
                'last_date'   => $last_date,
                'count_month' => $count_month,
                'total_month' => $total_month,
            ];
        }

        echo json_encode(['status' => 'ok', 'results' => $results]);

    } else {
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
