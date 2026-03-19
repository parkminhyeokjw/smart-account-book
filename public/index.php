<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/google_oauth.php';
require_once __DIR__ . '/../transaction/list.php';

$userId    = requireLogin();
$yearMonth = $_GET['ym']  ?? date('Y-m');
$activeTab = $_GET['tab'] ?? 'expense';

/* ── 전체 검색 파라미터 ── */
$srchMode  = isset($_GET['srch']) && $_GET['srch'] === '1';
$srchKw    = trim($_GET['srch_kw']    ?? '');
$srchStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['srch_start'] ?? '') ? $_GET['srch_start'] : '';
$srchEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['srch_end']   ?? '') ? $_GET['srch_end']   : '';
$srchCat   = (int)($_GET['srch_cat'] ?? 0);
$srchPm    = trim($_GET['srch_pm']   ?? '');

$dt   = \DateTime::createFromFormat('Y-m', $yearMonth);
$prev = (clone $dt)->modify('-1 month')->format('Y-m');
$next = (clone $dt)->modify('+1 month')->format('Y-m');
$ymKorean   = $dt->format('Y') . '년 ' . $dt->format('m') . '월';
$daysInMonth = (int)$dt->format('t');

$pdo = getConnection();

/* ── 데이터 로드 ──────────────────────────────── */
// list.php의 getTransactions는 payment_method를 포함하지 않으므로 직접 쿼리
function fetchTx(int $uid, string $ym, ?string $type = null): array {
    $pdo = getConnection();
    
    $sql = "SELECT t.id, t.amount, t.description, t.payment_method, t.source,
                   t.tx_date, t.created_at, t.category_id,
                   COALESCE(c.name, '없음') AS category_name, 
                   COALESCE(c.type, t.tx_type) AS category_type
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.user_id = :uid 
              AND DATE_FORMAT(t.tx_date, '%Y-%m') = :ym";

    if ($type) {
        $sql .= " AND (c.type = :type OR (t.category_id IS NULL AND COALESCE(t.tx_type,'expense') = :type2))";
    }

    $sql .= " ORDER BY t.tx_date DESC, t.id DESC";

    $s = $pdo->prepare($sql);
    $s->bindValue(':uid', $uid, PDO::PARAM_INT);
    $s->bindValue(':ym', $ym, PDO::PARAM_STR);
    if ($type) {
        $s->bindValue(':type',  $type, PDO::PARAM_STR);
        $s->bindValue(':type2', $type, PDO::PARAM_STR);
    }

    $s->execute(); // 이제 여기서 개수 안 맞는다는 소리 절대 못 합니다!
    return $s->fetchAll();
}

/* ── 검색 전용 함수 ── */
function fetchSearchTx(int $uid, string $kw, string $start, string $end, int $catId, string $pm): array {
    $pdo    = getConnection();
    $where  = ["t.user_id = :uid"];
    $params = [':uid' => $uid];
    if ($kw !== '')    { $where[] = 't.description LIKE :kw'; $params[':kw'] = '%'.$kw.'%'; }
    if ($start !== '') { $where[] = 't.tx_date >= :start'; $params[':start'] = $start; }
    if ($end !== '')   { $where[] = 't.tx_date <= :end';   $params[':end']   = $end; }
    if ($catId > 0)    { $where[] = 't.category_id = :cat'; $params[':cat'] = $catId; }
    if ($pm !== '')    { $where[] = 't.payment_method = :pm'; $params[':pm'] = $pm; }
    $sql = "SELECT t.id, t.amount, t.description, t.payment_method, t.source,
                   t.tx_date, t.created_at, t.category_id,
                   c.name AS category_name, c.type AS category_type
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.tx_date DESC, t.id DESC LIMIT 500";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

if ($srchMode) {
    $srchResults = fetchSearchTx($userId, $srchKw, $srchStart, $srchEnd, $srchCat, $srchPm);
    $activeTab   = 'expense'; // 검색 결과는 expense 탭 레이아웃 재사용
}

$expenseTx = $srchMode ? [] : fetchTx($userId, $yearMonth, 'expense');
$incomeTx  = $srchMode ? [] : fetchTx($userId, $yearMonth, 'income');
$summary   = getMonthlySummary($userId, $yearMonth);
$breakdown = getCategoryBreakdown($userId, $yearMonth);

// 카테고리 목록
$catStmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id=:uid ORDER BY type,name");
$catStmt->execute([':uid' => $userId]);
$categories  = $catStmt->fetchAll();
$expenseCats = array_filter($categories, fn($c) => $c['type'] === 'expense');
$incomeCats  = array_filter($categories, fn($c) => $c['type'] === 'income');

/* ── 예산 탭 기간 계산 ───────────────────────── */
$btype = in_array($_GET['btype']??'', ['weekly','monthly','yearly'])
         ? $_GET['btype'] : 'monthly';
$todayDt = new \DateTime(date('Y-m-d'));

if ($btype === 'weekly') {
    $bweekStr = $_GET['bweek'] ?? null;
    if ($bweekStr && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bweekStr)) {
        $bWeekDt = new \DateTime($bweekStr);
    } else {
        $bWeekDt = new \DateTime();
        $dow = (int)$bWeekDt->format('N');   // 1=월 … 7=일
        $bWeekDt->modify('-' . ($dow - 1) . ' days');
    }
    $bPeriodStart = $bWeekDt->format('Y-m-d');
    $bPeriodEnd   = (clone $bWeekDt)->modify('+6 days')->format('Y-m-d');
    $bPrevPeriod  = (clone $bWeekDt)->modify('-7 days')->format('Y-m-d');
    $bNextPeriod  = (clone $bWeekDt)->modify('+7 days')->format('Y-m-d');
    $bPeriodLabel = $bPeriodStart . ' ~ ' . $bPeriodEnd;
    $bTotalDays   = 7;
    $bTypeLabel   = '주';
} elseif ($btype === 'yearly') {
    $bYear        = (int)($_GET['byear'] ?? date('Y'));
    $bPeriodStart = $bYear . '-01-01';
    $bPeriodEnd   = $bYear . '-12-31';
    $bPrevPeriod  = (string)($bYear - 1);
    $bNextPeriod  = (string)($bYear + 1);
    $bPeriodLabel = $bPeriodStart . ' ~ ' . $bPeriodEnd;
    $bTotalDays   = (int)(new \DateTime($bPeriodStart))->diff(new \DateTime($bPeriodEnd))->days + 1;
    $bTypeLabel   = $bYear . '년';
} else {
    $btype        = 'monthly';
    $bPeriodStart = $yearMonth . '-01';
    $bPeriodEnd   = $yearMonth . '-' . $daysInMonth;
    $bPrevPeriod  = $prev;
    $bNextPeriod  = $next;
    $bPeriodLabel = $bPeriodStart . ' ~ ' . $bPeriodEnd;
    $bTotalDays   = $daysInMonth;
    $bTypeLabel   = '달';
}

$bPeriodStartDt = new \DateTime($bPeriodStart);
$bPeriodEndDt   = new \DateTime($bPeriodEnd);

$bElapsed = $todayDt >= $bPeriodStartDt
    ? min($bTotalDays, $bPeriodStartDt->diff(
          $todayDt <= $bPeriodEndDt ? $todayDt : $bPeriodEndDt
      )->days + 1)
    : 0;
$bRemaining = $todayDt <= $bPeriodEndDt
    ? $todayDt->diff($bPeriodEndDt)->days
    : 0;

/* ── 예산 목록 로드 ─────────────────────────── */
$bStmt = $pdo->prepare(
    "SELECT * FROM budgets WHERE user_id=:uid AND budget_type=:bt ORDER BY id"
);
$bStmt->execute([':uid' => $userId, ':bt' => $btype]);
$budgetCards = $bStmt->fetchAll();

function calcBudgetSpent(int $uid, array $budget, string $from, string $to): int {
    $pdo    = getConnection();
    $where  = ["t.user_id=:uid", "c.type='expense'", "t.tx_date BETWEEN :from AND :to"];
    $params = [':uid'=>$uid, ':from'=>$from, ':to'=>$to];

    $catIds = array_filter(array_map('intval', explode(',', $budget['category_ids'])));
    if (!empty($catIds)) {
        $where[] = 't.category_id IN (' . implode(',', $catIds) . ')';
    }
    $pms = array_filter(array_map('trim', explode(',', $budget['payment_methods'])));
    if (!empty($pms)) {
        $ph = [];
        foreach ($pms as $i => $pm) { $params[':pm'.$i] = $pm; $ph[] = ':pm'.$i; }
        $where[] = 't.payment_method IN (' . implode(',', $ph) . ')';
    }
    $sql = "SELECT COALESCE(SUM(t.amount),0) FROM transactions t
            LEFT JOIN categories c ON c.id=t.category_id
            WHERE " . implode(' AND ', $where);
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
}

// 이전 호환용 (stats 탭 등에서 $budgets 사용)
$budgets = $budgetCards;

// 월별 지출 통계 (자료차트 탭용)
$statsStmt = $pdo->prepare("SELECT DATE_FORMAT(t.tx_date,'%Y-%m') AS ym, SUM(t.amount) AS total FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE t.user_id=:uid AND COALESCE(c.type,t.tx_type,'expense')='expense' GROUP BY ym ORDER BY ym DESC LIMIT 12");
$statsStmt->execute([':uid' => $userId]);
$monthlyStats = $statsStmt->fetchAll();
$avgExpense   = count($monthlyStats) ? (int)(array_sum(array_column($monthlyStats,'total'))/count($monthlyStats)) : 0;

/* ── 자료통계 탭 파라미터 ──────────────────── */
$stype     = in_array($_GET['stype']??'', ['ep','ec','ip','ic','cb']) ? $_GET['stype'] : 'ep';
$sunit     = in_array($_GET['sunit']??'', ['w','m','y','all','r365','r180','r30','r7','custom']) ? $_GET['sunit'] : 'w';
$sclassify = in_array($_GET['sclassify']??'', ['cat','pm','sub','dow','hour','desc']) ? $_GET['sclassify'] : 'cat';
$scat      = (int)($_GET['scat']  ?? 0);
$spm2      = trim($_GET['spm2']   ?? '');
$skw       = trim($_GET['skw']    ?? '');
$sdow      = (isset($_GET['sdow']) && $_GET['sdow'] !== '') ? (int)$_GET['sdow'] : -1;

// 자료통계 기간
$sPPrev = ''; $sPNext = ''; $scstart = ''; $scend = ''; $sgroupBy = 'm';
if ($sunit === 'w') {
    $swStr = $_GET['sweek'] ?? null;
    if ($swStr && preg_match('/^\d{4}-\d{2}-\d{2}$/', $swStr)) { $swDt = new \DateTime($swStr); }
    else { $swDt = new \DateTime(); $swDt->modify('-' . ((int)$swDt->format('N') - 1) . ' days'); }
    $sPStart = $swDt->format('Y-m-d');
    $sPEnd   = (clone $swDt)->modify('+6 days')->format('Y-m-d');
    $sPPrev  = (clone $swDt)->modify('-7 days')->format('Y-m-d');
    $sPNext  = (clone $swDt)->modify('+7 days')->format('Y-m-d');
    $sgroupBy = 'w';
    $sUnitLabel = '주 단위'; $sUnitLabelCat = '주별 통계'; $sAvgLabel = '12주 평균';
} elseif ($sunit === 'y') {
    $syear   = (int)($_GET['syear'] ?? date('Y'));
    $sPStart = $syear . '-01-01';
    $sPEnd   = $syear . '-12-31';
    $sPPrev  = (string)($syear - 1);
    $sPNext  = (string)($syear + 1);
    $sgroupBy = 'y';
    $sUnitLabel = '연 단위'; $sUnitLabelCat = '연별 통계'; $sAvgLabel = '연간 평균';
} elseif ($sunit === 'all') {
    $pdo2 = getConnection();
    $minDate = $pdo2->prepare("SELECT MIN(tx_date) FROM transactions WHERE user_id=:uid");
    $minDate->execute([':uid'=>$userId]);
    $sPStart = $minDate->fetchColumn() ?: date('Y-m-d');
    $sPEnd   = date('Y-m-d');
    $sgroupBy = 'm';
    $sUnitLabel = '전체 기간'; $sUnitLabelCat = '전체 기간'; $sAvgLabel = '전체 평균';
} elseif ($sunit === 'r365') {
    $sPStart = date('Y-m-d', strtotime('-364 days'));
    $sPEnd   = date('Y-m-d');
    $sgroupBy = 'm';
    $sUnitLabel = '최근 365일'; $sUnitLabelCat = '최근 365일'; $sAvgLabel = '365일 평균';
} elseif ($sunit === 'r180') {
    $sPStart = date('Y-m-d', strtotime('-179 days'));
    $sPEnd   = date('Y-m-d');
    $sgroupBy = 'm';
    $sUnitLabel = '최근 180일'; $sUnitLabelCat = '최근 180일'; $sAvgLabel = '180일 평균';
} elseif ($sunit === 'r30') {
    $sPStart = date('Y-m-d', strtotime('-29 days'));
    $sPEnd   = date('Y-m-d');
    $sgroupBy = 'w';
    $sUnitLabel = '최근 30일'; $sUnitLabelCat = '최근 30일'; $sAvgLabel = '30일 평균';
} elseif ($sunit === 'r7') {
    $sPStart = date('Y-m-d', strtotime('-6 days'));
    $sPEnd   = date('Y-m-d');
    $sgroupBy = 'r7';
    $sUnitLabel = '최근 7일'; $sUnitLabelCat = '최근 7일'; $sAvgLabel = '7일 평균';
} elseif ($sunit === 'custom') {
    $scstart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['scstart']??'') ? $_GET['scstart'] : date('Y-m-01');
    $scend   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['scend']??'')   ? $_GET['scend']   : date('Y-m-d');
    $sPStart = $scstart; $sPEnd = $scend;
    $sgroupBy = 'm';
    $sUnitLabel = '사용자 지정'; $sUnitLabelCat = '사용자 지정'; $sAvgLabel = '기간 평균';
} else {
    $sunit   = 'm';
    $sPStart = $yearMonth . '-01';
    $sPEnd   = $yearMonth . '-' . $daysInMonth;
    $sPPrev  = $prev; $sPNext = $next;
    $sgroupBy = 'm';
    $sUnitLabel = '월 단위'; $sUnitLabelCat = '월별 통계'; $sAvgLabel = '12달 평균';
}
$sPeriodLabel = $sPStart . ' ~ ' . $sPEnd;

// 통계 유형 레이블
$sTypeLabels = ['ep'=>'지출/기간','ec'=>'지출/분류','ip'=>'수입/기간','ic'=>'수입/분류','cb'=>'합산 통계'];
$sTypeLabel  = $sTypeLabels[$stype];

// 3번 버튼 레이블
if ($stype === 'ec' || $stype === 'ic') {
    $sBtn3Label = $sUnitLabelCat;
} else {
    if ($scat > 0) {
        $catRow = array_filter($categories, fn($c) => $c['id'] == $scat);
        $sBtn3Label = $catRow ? reset($catRow)['name'] : '전체';
    } elseif ($spm2 !== '') {
        $sBtn3Label = $spm2;
    } elseif ($skw !== '') {
        $sBtn3Label = '"'.$skw.'"';
    } else {
        $sBtn3Label = match($stype) { 'ip','ic'=>'전체 수입', 'cb'=>'전체 내역', default=>'전체 지출' };
    }
}

// 공통 필터 WHERE/params 빌더
function buildSF(int $catId, string $pm, string $kw, int $dow): array {
    $w=[]; $p=[];
    if ($catId > 0) { $w[] = 't.category_id=:sf_cat'; $p[':sf_cat']=$catId; }
    if ($pm !== '') { $w[] = 't.payment_method=:sf_pm'; $p[':sf_pm']=$pm; }
    if ($kw !== '') { $w[] = 't.description LIKE :sf_kw'; $p[':sf_kw']='%'.$kw.'%'; }
    if ($dow >= 0)  { $w[] = 'DAYOFWEEK(t.tx_date)=:sf_dow'; $p[':sf_dow']=$dow+1; }
    return [$w, $p];
}

// 기간별 집계
function statsPeriod(int $uid, string $txType, string $from, string $to, string $unit, array $fw, array $fp): array {
    $pdo = getConnection();
    $pe = match($unit) { 'w'=>"DATE_ADD(t.tx_date, INTERVAL (6-WEEKDAY(t.tx_date)) DAY)", 'y'=>"YEAR(t.tx_date)", 'r7'=>"t.tx_date", default=>"DATE_FORMAT(t.tx_date,'%Y-%m')" };
    $w = ["t.user_id=:uid","COALESCE(c.type,t.tx_type,'expense')=:tt","t.tx_date BETWEEN :from AND :to", ...$fw];
    $p = array_merge([':uid'=>$uid,':tt'=>$txType,':from'=>$from,':to'=>$to], $fp);
    $s = $pdo->prepare("SELECT $pe AS period, SUM(t.amount) AS total FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE ".implode(' AND ',$w)." GROUP BY period ORDER BY period DESC");
    $s->execute($p); return $s->fetchAll();
}

// 평균 (최근 12주/12달/5년)
function statsAvg(int $uid, string $txType, string $unit, int $catId): int {
    $pdo = getConnection();
    [$gb, $lim] = match($unit) { 'w'=>["YEARWEEK(t.tx_date,1)",12], 'y'=>["YEAR(t.tx_date)",5], default=>["DATE_FORMAT(t.tx_date,'%Y-%m')",12] };
    $w = ["t.user_id=:uid","COALESCE(c.type,t.tx_type,'expense')=:tt"]; $p = [':uid'=>$uid,':tt'=>$txType];
    if ($catId>0) { $w[]='t.category_id=:cat'; $p[':cat']=$catId; }
    $s = $pdo->prepare("SELECT SUM(t.amount) AS total FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE ".implode(' AND ',$w)." GROUP BY $gb ORDER BY $gb DESC LIMIT $lim");
    $s->execute($p); $rows=$s->fetchAll();
    return empty($rows) ? 0 : (int)(array_sum(array_column($rows,'total'))/count($rows));
}

// 분류별 집계
function statsCat(int $uid, string $txType, string $from, string $to, string $by, array $fw, array $fp): array {
    $pdo = getConnection();
    $w = ["t.user_id=:uid","COALESCE(c.type,t.tx_type,'expense')=:tt","t.tx_date BETWEEN :from AND :to", ...$fw];
    $p = array_merge([':uid'=>$uid,':tt'=>$txType,':from'=>$from,':to'=>$to], $fp);
    switch ($by) {
        case 'pm':   $gc = "t.payment_method";      $lc = "t.payment_method AS label";                  $ob = "total DESC"; break;
        case 'sub':  $gc = "t.description";          $lc = "IFNULL(t.description,'없음') AS label";      $ob = "total DESC"; break;
        case 'dow':  $gc = "WEEKDAY(t.tx_date)";     $lc = "WEEKDAY(t.tx_date) AS label";                $ob = "WEEKDAY(t.tx_date) ASC"; break;
        case 'hour': $gc = "HOUR(t.tx_date)";        $lc = "HOUR(t.tx_date) AS label";                   $ob = "HOUR(t.tx_date) ASC"; break;
        case 'desc': $gc = "t.description";          $lc = "IFNULL(t.description,'없음') AS label";      $ob = "total DESC"; break;
        default:     $gc = "t.category_id";          $lc = "IFNULL(c.name,'없음') AS label";             $ob = "total DESC"; break;
    }
    $s = $pdo->prepare("SELECT $lc, SUM(t.amount) AS total FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE ".implode(' AND ',$w)." GROUP BY $gc ORDER BY $ob");
    $s->execute($p); return $s->fetchAll();
}

// 합산 집계
function statsCombined(int $uid, string $from, string $to, string $unit, array $fw, array $fp): array {
    $pdo = getConnection();
    $pe = match($unit) { 'w'=>"DATE_ADD(t.tx_date, INTERVAL (6-WEEKDAY(t.tx_date)) DAY)", 'y'=>"YEAR(t.tx_date)", default=>"DATE_FORMAT(t.tx_date,'%Y-%m')" };
    $w = ["t.user_id=:uid","t.tx_date BETWEEN :from AND :to", ...$fw];
    $p = array_merge([':uid'=>$uid,':from'=>$from,':to'=>$to], $fp);
    $s = $pdo->prepare("SELECT $pe AS period, SUM(CASE WHEN COALESCE(c.type,t.tx_type,'expense')='income' THEN t.amount ELSE 0 END) AS inc, SUM(CASE WHEN COALESCE(c.type,t.tx_type,'expense')='expense' THEN t.amount ELSE 0 END) AS exp FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE ".implode(' AND ',$w)." GROUP BY period ORDER BY period DESC");
    $s->execute($p); return $s->fetchAll();
}

[$sf_where, $sf_params] = buildSF($scat, $spm2, $skw, $sdow);

// 실제 데이터 조회
$statsRows = [];
if ($stype === 'ep') {
    $statsRows = statsPeriod($userId, 'expense', $sPStart, $sPEnd, $sgroupBy, $sf_where, $sf_params);
    $statsAvgVal = statsAvg($userId, 'expense', $sgroupBy, $scat);
} elseif ($stype === 'ip') {
    $statsRows = statsPeriod($userId, 'income',  $sPStart, $sPEnd, $sgroupBy, $sf_where, $sf_params);
    $statsAvgVal = statsAvg($userId, 'income',  $sgroupBy, $scat);
} elseif ($stype === 'ec') {
    $statsRows = statsCat($userId, 'expense', $sPStart, $sPEnd, $sclassify, $sf_where, $sf_params);
    $statsAvgVal = 0;
} elseif ($stype === 'ic') {
    $statsRows = statsCat($userId, 'income',  $sPStart, $sPEnd, $sclassify, $sf_where, $sf_params);
    $statsAvgVal = 0;
} else {
    $statsRows = statsCombined($userId, $sPStart, $sPEnd, $sgroupBy, $sf_where, $sf_params);
    $statsAvgVal = 0;
}
$statsTotal = array_sum(array_column($statsRows, 'total'));

// 결제수단 목록 (실제 DB에 있는 것)
$pmStmt = $pdo->prepare("SELECT DISTINCT payment_method FROM transactions WHERE user_id=:uid ORDER BY payment_method");
$pmStmt->execute([':uid' => $userId]);
$existingPMs = array_column($pmStmt->fetchAll(), 'payment_method');

/* ── 헬퍼 ────────────────────────────────────── */
$DAYS_KO = ['일','월','화','수','목','금','토'];
function fmtDate(string $date): string {
    global $DAYS_KO;
    $d = new \DateTime($date);
    return $d->format('Y.m.d') . ' (' . $DAYS_KO[(int)$d->format('w')] . ')';
}
function groupByDate(array $txs): array {
    $g = [];
    foreach ($txs as $tx) $g[$tx['tx_date']][] = $tx;
    return $g;
}

$expenseGrouped = groupByDate($expenseTx);
$incomeGrouped  = groupByDate($incomeTx);

// 이번 달 전체 날짜 (최신순)
$allDays = [];
for ($d = $daysInMonth; $d >= 1; $d--)
    $allDays[] = sprintf('%s-%02d', $yearMonth, $d);

// 달력용 일별 지출/수입 합계 (JS에 전달)
$dailyExpense = [];
foreach ($expenseGrouped as $date => $txs)
    $dailyExpense[$date] = array_sum(array_column($txs, 'amount'));

$dailyIncome = [];
foreach ($incomeGrouped as $date => $txs)
    $dailyIncome[$date] = array_sum(array_column($txs, 'amount'));

// 달력 날짜별 거래 상세 (JS에 전달)
$calTxDataArr = [];
foreach ($expenseGrouped as $date => $txs) {
    $calTxDataArr[$date] = array_map(function($tx) use ($userId) {
        return [
            'id'      => (int)$tx['id'],
            'amt'     => (int)$tx['amount'],
            'desc'    => $tx['description'] ?? '',
            'pm'      => $tx['payment_method'] ?? '현금',
            'cat'     => (int)($tx['category_id'] ?? 0),
            'catName' => $tx['category_name'] ?? '',
            'catType' => $tx['category_type'] ?? 'expense',
            'txDate'  => $tx['tx_date'],
            'created' => $tx['created_at'] ?? '',
            'userId'  => $userId,
        ];
    }, $txs);
}
$incomeCalTxDataArr = [];
foreach ($incomeGrouped as $date => $txs) {
    $incomeCalTxDataArr[$date] = array_map(function($tx) use ($userId) {
        return [
            'id'      => (int)$tx['id'],
            'amt'     => (int)$tx['amount'],
            'desc'    => $tx['description'] ?? '',
            'pm'      => $tx['payment_method'] ?? '현금',
            'cat'     => (int)($tx['category_id'] ?? 0),
            'catName' => $tx['category_name'] ?? '',
            'catType' => $tx['category_type'] ?? 'income',
            'txDate'  => $tx['tx_date'],
            'created' => $tx['created_at'] ?? '',
            'userId'  => $userId,
        ];
    }, $txs);
}

// 차트 색상
$CHART_COLORS = ['#b8e000','#ff6b6b','#4ecdc4','#ffa94d','#845ef7','#339af0','#69db7c','#f783ac'];
$totalBreakdown = array_sum(array_column($breakdown, 'total'));

// 결제수단 전체 목록
$ALL_PAYMENTS = ['현금','신용카드','체크카드','계좌이체','간편결제','기타'];

/* ── 자료차트 탭 데이터 ─────────────────────────── */
$ctype     = in_array($_GET['ctype']??'',  ['ep','ec','ip','ic','cb']) ? $_GET['ctype'] : 'ep';
$cunit     = in_array($_GET['cunit']??'',  ['w','m','y','all','r365','r180','r30','r7','custom']) ? $_GET['cunit'] : 'w';
$cclassify = in_array($_GET['cclassify']??'', ['cat','pm','sub','dow','hour','desc']) ? $_GET['cclassify'] : 'cat';
$ccumul    = isset($_GET['ccumul']) && $_GET['ccumul'] === '1' ? 1 : 0;
$cfcat     = (int)($_GET['cfcat']  ?? 0);
$cfpm      = trim($_GET['cfpm']    ?? '');
$cfkw      = trim($_GET['cfkw']    ?? '');
$cfdow     = (isset($_GET['cfdow']) && $_GET['cfdow'] !== '') ? (int)$_GET['cfdow'] : -1;

$cPPrev = ''; $cPNext = ''; $ccstart = ''; $ccend = ''; $cgroupBy = 'm'; $cyear = date('Y'); $cym2 = $yearMonth;
if ($cunit === 'w') {
    $cwStr = $_GET['cweek'] ?? null;
    if ($cwStr && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cwStr)) { $cwDt = new \DateTime($cwStr); }
    else { $cwDt = new \DateTime(); $cwDt->modify('-'.((int)$cwDt->format('N')-1).' days'); }
    $cPStart = $cwDt->format('Y-m-d');
    $cPEnd   = (clone $cwDt)->modify('+6 days')->format('Y-m-d');
    $cPPrev  = (clone $cwDt)->modify('-7 days')->format('Y-m-d');
    $cPNext  = (clone $cwDt)->modify('+7 days')->format('Y-m-d');
    $cgroupBy = 'w'; $cUnitLabel = '주 단위'; $cAvgLabel = '16주 평균';
} elseif ($cunit === 'y') {
    $cyear   = (int)($_GET['cyear'] ?? date('Y'));
    $cPStart = $cyear.'-01-01'; $cPEnd = $cyear.'-12-31';
    $cPPrev  = (string)($cyear-1); $cPNext = (string)($cyear+1);
    $cgroupBy = 'y'; $cUnitLabel = '연 단위'; $cAvgLabel = '5년 평균';
} elseif ($cunit === 'all') {
    $cPdo = getConnection();
    $cMinStmt = $cPdo->prepare("SELECT MIN(tx_date) FROM transactions WHERE user_id=:uid");
    $cMinStmt->execute([':uid'=>$userId]);
    $cPStart = $cMinStmt->fetchColumn() ?: date('Y-m-d'); $cPEnd = date('Y-m-d');
    $cgroupBy = 'm'; $cUnitLabel = '전체 기간'; $cAvgLabel = '전체 평균';
} elseif ($cunit === 'r365') {
    $cPStart = date('Y-m-d', strtotime('-364 days')); $cPEnd = date('Y-m-d');
    $cgroupBy = 'm'; $cUnitLabel = '최근 365일'; $cAvgLabel = '평균';
} elseif ($cunit === 'r180') {
    $cPStart = date('Y-m-d', strtotime('-179 days')); $cPEnd = date('Y-m-d');
    $cgroupBy = 'm'; $cUnitLabel = '최근 180일'; $cAvgLabel = '평균';
} elseif ($cunit === 'r30') {
    $cPStart = date('Y-m-d', strtotime('-29 days')); $cPEnd = date('Y-m-d');
    $cgroupBy = 'w'; $cUnitLabel = '최근 30일'; $cAvgLabel = '평균';
} elseif ($cunit === 'r7') {
    $cPStart = date('Y-m-d', strtotime('-6 days')); $cPEnd = date('Y-m-d');
    $cgroupBy = 'r7'; $cUnitLabel = '최근 7일'; $cAvgLabel = '평균';
} elseif ($cunit === 'custom') {
    $ccstart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ccstart']??'') ? $_GET['ccstart'] : date('Y-m-01');
    $ccend   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ccend']??'')   ? $_GET['ccend']   : date('Y-m-d');
    $cPStart = $ccstart; $cPEnd = $ccend;
    $cgroupBy = 'm'; $cUnitLabel = '사용자 지정'; $cAvgLabel = '평균';
} else {
    $cunit = 'm';
    $cym2  = preg_match('/^\d{4}-\d{2}$/', $_GET['cym']??'') ? $_GET['cym'] : $yearMonth;
    $cDt2  = \DateTime::createFromFormat('Y-m', $cym2);
    $cPStart = $cym2.'-01'; $cPEnd = $cym2.'-'.$cDt2->format('t');
    $cPPrev  = (clone $cDt2)->modify('-1 month')->format('Y-m');
    $cPNext  = (clone $cDt2)->modify('+1 month')->format('Y-m');
    $cgroupBy = 'm'; $cUnitLabel = '월 단위'; $cAvgLabel = '12달 평균';
}
$cPeriodLabel = $cPStart.' ~ '.$cPEnd;
$cTypeLabel   = ['ep'=>'지출/기간','ec'=>'지출/분류','ip'=>'수입/기간','ic'=>'수입/분류','cb'=>'합산 차트'][$ctype];
$cClassifyLabels2 = ['cat'=>'카테고리별','pm'=>'결제수단별','sub'=>'세부분류별','dow'=>'요일별','hour'=>'시간대별','desc'=>'내역별'];
$cClassifyLabel   = $cClassifyLabels2[$cclassify];
$cBtn3Label2 = ($ctype==='ec'||$ctype==='ic') ? $cClassifyLabel : match($ctype){'ip','ic'=>'전체 수입','cb'=>'전체 내역',default=>'전체 지출'};

// 차트 데이터 쿼리
$chartLabels = []; $chartValues = []; $chartValues2 = []; $chartValues3 = [];
$chartTotal  = 0;  $chartAvgVal  = 0;

function fmtChartPeriod(string $p, string $gby): string {
    if ($gby==='r7')   return substr($p,5);
    if ($gby==='w')    return (new \DateTime($p))->format('m.d');
    if ($gby==='m')    return str_replace('-','.',$p);
    return (string)$p;
}

[$cf_where, $cf_params] = buildSF($cfcat, $cfpm, $cfkw, $cfdow);

// 3번 버튼 레이블 (필터 적용 시 표시)
if ($ctype==='ec'||$ctype==='ic') {
    $cBtn3Label2 = $cClassifyLabel;
} elseif ($cfcat > 0) {
    $cfCatRow = array_filter($categories, fn($c) => $c['id'] == $cfcat);
    $cBtn3Label2 = $cfCatRow ? reset($cfCatRow)['name'] : '전체';
} elseif ($cfpm !== '') {
    $cBtn3Label2 = $cfpm;
} elseif ($cfkw !== '') {
    $cBtn3Label2 = '"'.$cfkw.'"';
} else {
    $cBtn3Label2 = match($ctype){'ip'=>'전체 수입','cb'=>'전체 내역',default=>'전체 지출'};
}

if ($ctype==='ec'||$ctype==='ic') {
    $txType = $ctype==='ec' ? 'expense' : 'income';
    $cRows  = statsCat($userId, $txType, $cPStart, $cPEnd, $cclassify, $cf_where, $cf_params);
    $chartTotal = array_sum(array_column($cRows,'total'));
    $dowN = ['월','화','수','목','금','토','일'];
    foreach ($cRows as $row) {
        if ($cclassify==='dow')       $chartLabels[] = $dowN[(int)$row['label']] ?? $row['label'];
        elseif ($cclassify==='hour')  $chartLabels[] = (int)$row['label'].'시';
        else                          $chartLabels[] = $row['label'] ?: '없음';
        $chartValues[] = (int)$row['total'];
    }
} elseif ($ctype==='ep'||$ctype==='ip') {
    $txType = $ctype==='ep' ? 'expense' : 'income';
    $cRows  = array_reverse(statsPeriod($userId, $txType, $cPStart, $cPEnd, $cgroupBy, $cf_where, $cf_params));
    $chartTotal  = array_sum(array_column($cRows,'total'));
    $chartAvgVal = statsAvg($userId, $txType, $cgroupBy, $cfcat);
    foreach ($cRows as $row) {
        $chartLabels[] = fmtChartPeriod($row['period'], $cgroupBy);
        $chartValues[] = (int)$row['total'];
    }
} else { // cb
    $cRows = array_reverse(statsCombined($userId, $cPStart, $cPEnd, $cgroupBy, $cf_where, $cf_params));
    foreach ($cRows as $row) {
        $chartLabels[] = fmtChartPeriod($row['period'], $cgroupBy);
        $inc = (int)$row['inc']; $exp = (int)$row['exp'];
        if ($ccumul) {
            // 누적 보기: 수입/지출 각각
            $chartValues2[] = $inc;
            $chartValues3[] = $exp;
        } else {
            // 합산: 수입 - 지출 (순수익, 음수 가능)
            $chartValues[] = $inc - $exp;
        }
    }
    $chartTotal = $ccumul
        ? array_sum($chartValues2) + array_sum($chartValues3)
        : array_sum(array_map('abs', $chartValues));
}
$cChartDataJson = json_encode([
    'labels'=>$chartLabels,'values'=>$chartValues,
    'values2'=>$chartValues2,'values3'=>$chartValues3,
    'total'=>$chartTotal,'avg'=>$chartAvgVal,
    'type'=>$ctype,'cumul'=>$ccumul,'avgLabel'=>$cAvgLabel,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>똑똑가계부</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="design_apply.js"></script>
<script src="currency_apply.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
/* ══ CSS 변수 fallback ══ (design_apply.js 인라인 스타일이 항상 우선) */
/* ══ 리셋 & 기본 ══ */
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
/* 스크롤바 공간 항상 예약 → 탭 전환 시 좌우 밀림 방지 */
html{overflow-y:scroll;scrollbar-gutter:stable;}
body{font-family:'Apple SD Gothic Neo','Noto Sans KR',sans-serif;background:#fff;color:#222;max-width:480px;margin:0 auto;min-height:100vh;}
button{font-family:inherit;}

/* ══ 헤더 ══ */
.hd{background:var(--theme-primary,#3c3c3c);color:#fff;display:flex;align-items:center;padding:0 14px;height:52px;position:sticky;top:0;z-index:100;}
.hd-menu{font-size:22px;cursor:pointer;margin-right:10px;}
.hd-title{font-size:18px;font-weight:700;flex:1;}
.hd-icons{display:flex;gap:16px;font-size:20px;cursor:pointer;}

/* ══ 탭 ══ */
.tabs{display:flex;border-bottom:1px solid #e0e0e0;background:#fff;position:sticky;top:52px;z-index:99;}
.tab{flex:1;text-align:center;padding:11px 0 9px;font-size:13px;color:#999;cursor:pointer;border-bottom:2.5px solid transparent;}
.tab.active{color:var(--theme-primary,#222);font-weight:700;border-bottom-color:var(--theme-primary,#222);}
.tab-panel{display:none;padding-bottom:90px;}
.tab-panel.active{display:block;}

/* ══ 월 컨트롤러 ══ */
/* ══ 월/필터 고정 영역 ══ */
.sticky-controls{position:sticky;top:90px;z-index:97;background:#fff;border-bottom:1px solid #f0f0f0;}
/* top 값은 JS가 실측 후 덮어씀 */
.month-bar{display:flex;align-items:center;gap:6px;padding:10px 12px;}
.month-btn{background:#f0f0f0;border:none;border-radius:20px;width:34px;height:34px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.month-label{flex:1;background:#f0f0f0;border-radius:20px;text-align:center;padding:7px 0;font-size:15px;font-weight:700;}
.month-total{background:#f0f0f0;border-radius:20px;padding:7px 14px;font-size:15px;font-weight:700;white-space:nowrap;}

/* ══ 필터 버튼 행 ══ */
.filter-row{display:flex;gap:6px;padding:0 12px 10px;}
.filter-btn{flex:1;background:#f0f0f0;border:none;border-radius:20px;padding:8px 0;font-size:13px;font-weight:600;cursor:pointer;color:#333;transition:background .15s;-webkit-tap-highlight-color:transparent;}
.filter-btn:focus,.filter-btn:focus-visible{outline:none;box-shadow:none;}
.filter-btn:active{opacity:.75;}
.filter-btn.on{background:var(--theme-primary,#3c3c3c);color:#fff;}
.filter-btn.on:focus,.filter-btn.on:focus-visible{outline:none;box-shadow:none;}

/* ══ 날짜 그룹 ══ */
.date-row{display:flex;align-items:center;justify-content:space-between;padding:7px 14px;cursor:pointer;}
.date-badge{background:#f0f0f0;border-radius:14px;padding:5px 12px;font-size:13px;color:#666;}
.date-badge.has{font-weight:700;color:#111;}
.date-total{font-size:14px;color:#aaa;}
.date-total.has{font-weight:700;color:#111;}
.tx-items{display:none;}

/* ══ 거래 행 ══ */
.tx-row{display:flex;align-items:center;padding:7px 14px 7px 26px;border-bottom:1px solid #f5f5f5;gap:6px;}
.tx-pm{font-size:12px;color:#888;min-width:44px;flex-shrink:0;}
.tx-cat{font-size:13px;color:#555;flex-shrink:0;}
.tx-desc{font-size:13px;flex:1;color:#444;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.tx-amt{font-size:14px;font-weight:600;color:var(--color-minus,#e53935);flex-shrink:0;}
.tx-del{background:none;border:none;color:#ccc;font-size:15px;cursor:pointer;padding:0 2px;}

/* ══ 수입 탭 ══ */
.income-summary{display:flex;justify-content:space-between;padding:8px 14px 4px;font-size:14px;border-bottom:1px solid #f0f0f0;}
.income-summary b{color:var(--color-plus,#1a9eff);}
.income-row{display:flex;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;gap:8px;}
.income-date{font-size:13px;color:#666;width:38px;flex-shrink:0;}
.income-cat{font-size:13px;color:#555;flex-shrink:0;}
.income-desc{font-size:13px;flex:1;color:#444;}
.income-amt{font-size:14px;font-weight:600;color:var(--color-plus,#1a9eff);}

/* ══ 예산 탭 ══ */
.budget-row{display:flex;align-items:center;padding:12px 14px;border-bottom:1px solid #f0f0f0;}
.budget-info{flex:1;}
.budget-name{font-size:14px;font-weight:700;}
.budget-bar-wrap{height:5px;background:#eee;border-radius:3px;margin-top:5px;width:100%;}
.budget-bar{height:5px;border-radius:3px;background:#b8e000;}
.budget-bar.over{background:var(--color-minus,#e53935);}
.budget-right{text-align:right;margin-left:12px;min-width:70px;}

/* ══ 통계 탭 ══ */
.stats-table{width:100%;border-collapse:collapse;font-size:14px;}
.stats-table th{padding:10px 14px;text-align:center;color:#666;border-bottom:1px solid #e0e0e0;font-weight:600;}
.stats-table td{padding:10px 14px;text-align:center;border-bottom:1px solid #f0f0f0;}
.stats-table td:first-child{text-align:left;color:#555;}
.stats-table td:nth-child(2){font-weight:700;}

/* ══ 차트 탭 ══ */
.chart-wrap{padding:16px 20px;display:flex;flex-direction:column;align-items:center;}
.chart-legend{width:100%;margin-top:12px;}
.legend-item{display:flex;align-items:center;gap:8px;padding:7px 14px;border-bottom:1px solid #f5f5f5;font-size:13px;}
.legend-dot{width:11px;height:11px;border-radius:50%;flex-shrink:0;}
.legend-name{flex:1;color:#444;}
.legend-pct{color:#999;font-size:12px;}
.legend-amt{font-weight:700;font-size:13px;}

/* ══ 달력 뷰 ══ */
.cal-wrap{overflow-x:auto;}
.cal-table{width:100%;border-collapse:collapse;font-size:12px;}
.cal-table th{padding:6px 0;text-align:center;font-weight:600;color:#444;border-bottom:1px solid #e0e0e0;}
.cal-table th.sun{color:#e53935;}
.cal-table th.sat{color:#1a9eff;}
.cal-table .week-col{width:42px;background:#fafafa;border-right:1px solid #eee;color:#888;font-size:11px;text-align:center;padding:4px 2px;vertical-align:middle;}
.cal-table td.day{width:40px;height:52px;vertical-align:top;padding:4px 2px 2px;border:1px solid #f0f0f0;text-align:center;cursor:pointer;}
.cal-table td.day .dn{font-size:13px;font-weight:600;}
.cal-table td.day .da{font-size:10px;color:#2e7d32;margin-top:2px;}
.cal-table td.day.sun .dn{color:#e53935;}
.cal-table td.day.sat .dn{color:#1a9eff;}
.cal-table td.day.other .dn{color:#ccc;}
.cal-table td.day.today{border:2px solid #2e7d32;}
.cal-table td.day.today .dn{color:#2e7d32;}
.cal-table tfoot td{padding:5px 2px;text-align:center;font-size:11px;border-top:1px solid #ddd;background:#fafafa;}
.cal-table tfoot .lbl{font-weight:700;color:#555;background:#fafafa;border-right:1px solid #eee;font-size:11px;}

/* ══ 빈 상태 ══ */
.empty{text-align:center;padding:70px 20px;color:#aaa;font-size:14px;}
.empty a{color:#666;text-decoration:underline;}

/* ══ 플로팅 버튼 ══ */
.fab{position:fixed;bottom:24px;right:20px;width:56px;height:56px;background:var(--theme-primary,#444);border-radius:16px;color:#fff;font-size:30px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.3);z-index:200;line-height:1;}

/* ══ 모달 공통 배경 ══ */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;}
.overlay.show{display:flex;}

/* ══ 사이드 드로어 ══ */
.side-drawer-overlay{display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.4);}
.side-drawer-overlay.show{display:block;}
.side-drawer{position:fixed;top:0;left:-280px;width:280px;height:100%;background:#fff;z-index:401;transition:left .25s ease;display:flex;flex-direction:column;}
.side-drawer-overlay.show .side-drawer{left:0;}
.drawer-top{background:var(--theme-primary,#4a4a4a);padding:20px 0 8px;}
.drawer-top-item{display:flex;align-items:center;gap:16px;padding:16px 24px;color:#fff;cursor:pointer;font-size:16px;}
.drawer-top-item:active{background:rgba(255,255,255,.1);}
.drawer-top-item i{font-size:20px;width:24px;text-align:center;}
.drawer-divider{height:1px;background:#e0e0e0;}
.drawer-bottom{padding:8px 0;}
.drawer-bottom-item{display:flex;align-items:center;gap:16px;padding:16px 24px;color:#222;cursor:pointer;font-size:16px;}
.drawer-bottom-item:active{background:#f5f5f5;}
.drawer-bottom-item i{font-size:20px;width:24px;text-align:center;color:#444;}

/* ══ 지출 등록 모달 (센터 다이얼로그, 지출내역1.jpg) ══ */
.add-dialog{background:#fff;border-radius:12px;width:92%;max-width:420px;margin:auto;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.25);}
.add-hd{background:var(--theme-primary,#3c3c3c);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:14px 16px;}
.add-hd-title{font-size:17px;font-weight:700;}
.add-body{padding:6px 0;}
.add-row{display:flex;align-items:center;border-bottom:1px solid #f0f0f0;padding:0 16px;min-height:50px;}
.add-label{font-size:14px;color:#444;width:68px;flex-shrink:0;font-weight:600;}
.add-inputs{display:flex;gap:8px;flex:1;padding:8px 0;}
.add-inputs input,.add-inputs select,.add-inputs textarea{border:1px solid #ddd;border-radius:6px;padding:7px 10px;font-size:14px;font-family:inherit;outline:none;background:#fff;}
.add-inputs input[type=number]{width:100%;}
.add-inputs input[type=text]{width:100%;}
.add-inputs textarea{width:100%;resize:none;height:58px;}
.add-inputs .half{flex:1;}
.add-type-btns{display:flex;gap:6px;flex:1;padding:8px 0;}
.add-type-btns button{flex:1;border:1px solid #ddd;border-radius:6px;padding:8px 4px;font-size:13px;cursor:pointer;background:#fff;color:#555;font-weight:600;}
.add-type-btns button.sel{background:var(--theme-primary,#3c3c3c);color:#fff;border-color:var(--theme-primary,#3c3c3c);}
.add-foot{display:flex;border-top:1px solid #eee;}
.add-foot button{flex:1;padding:14px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.add-foot button:not(:last-child){border-right:1px solid #555;}

/* ══ 필터 모달 (하단 시트, 지출내역2~4.jpg) ══ */
.filter-sheet{align-items:flex-end;}
.filter-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;max-height:85vh;display:flex;flex-direction:column;}
.filter-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:16px 18px;display:flex;justify-content:space-between;align-items:center;border-radius:16px 16px 0 0;flex-shrink:0;}
.filter-hd-title{font-size:16px;font-weight:700;}
.filter-list{overflow-y:auto;flex:1;min-height:0;}
.filter-item{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #f0f0f0;font-size:15px;}
.filter-item input[type=radio]{width:20px;height:20px;accent-color:var(--theme-primary,#3c3c3c);cursor:pointer;}
.filter-item input[type=checkbox]{width:18px;height:18px;accent-color:var(--theme-primary,#3c3c3c);cursor:pointer;border-radius:3px;}
.filter-section{background:var(--theme-primary,#3c3c3c);color:#fff;padding:9px 18px;font-size:13px;font-weight:700;flex-shrink:0;}
.filter-foot{display:flex;gap:0;border-top:1px solid #eee;flex-shrink:0;}
.filter-foot button{flex:1;padding:15px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.filter-foot button:first-child{background:#555;}
.filter-foot button:first-child:hover{background:#444;}
.cat-arrow{color:#aaa;margin-right:4px;}

/* ══ 필터 활성 표시 ══ */
.filter-btn.active-filter{background:var(--theme-primary,#3c3c3c);color:#fff;}

/* ══ 관리 메뉴 모달 (image_11) ══ */
.menu-sheet{align-items:flex-end;}
.menu-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;overflow:hidden;}
.menu-hd{background:var(--theme-primary,#3c3c3c);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 18px;}
.menu-hd-amt{font-size:20px;font-weight:700;}
.menu-item{display:flex;align-items:center;gap:16px;padding:16px 20px;border-bottom:1px solid #f0f0f0;cursor:pointer;font-size:16px;color:#222;}
.menu-item:active{background:#f5f5f5;}
.menu-item i{font-size:20px;width:24px;text-align:center;color:#444;}
.menu-section{background:var(--theme-primary,#3c3c3c);color:#fff;padding:9px 18px;font-size:13px;font-weight:700;}
.menu-cancel{width:100%;padding:16px;border:none;background:var(--theme-primary,#3c3c3c);color:#fff;font-size:16px;font-weight:700;cursor:pointer;}

/* ══ 달력 날짜 상세 모달 ══ */
.cal-day-tx-row{display:flex;align-items:center;padding:12px 18px;gap:8px;cursor:pointer;border-bottom:1px solid #f0f0f0;}
.cal-day-tx-row:active{background:#f5f5f5;}
.cal-day-tx-pm{font-size:11px;color:#888;min-width:36px;flex-shrink:0;}
.cal-day-tx-desc{flex:1;font-size:14px;color:#333;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cal-day-tx-amt{font-size:14px;font-weight:600;white-space:nowrap;}
.cal-day-add-btn{width:calc(100% - 32px);margin:12px 16px;padding:14px;border:none;background:var(--theme-primary,#3c3c3c);color:#fff;font-size:15px;font-weight:700;border-radius:10px;cursor:pointer;display:block;}

/* ══ 상세 정보 모달 (image_12) ══ */
.detail-sheet{align-items:flex-end;}
.detail-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;overflow:hidden;}
.detail-hd{background:var(--theme-primary,#3c3c3c);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 18px;}
.detail-hd-amt{font-size:20px;font-weight:700;}
.detail-hd i{font-size:20px;cursor:pointer;}
.detail-body{padding:12px 16px;}
.detail-row{border:1px solid #ddd;border-radius:8px;padding:14px 16px;text-align:center;font-size:15px;color:#333;margin-bottom:8px;}
.detail-row.muted{color:#aaa;}
.detail-foot{display:flex;}
.detail-foot button{flex:1;padding:15px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.detail-foot button:first-child{background:#555;border-right:1px solid #444;}

/* ══ 자료통계 ══ */
.stats-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.stats-tbl thead tr{border-bottom:2px solid #ddd;}
.stats-tbl th{padding:10px 6px;color:#555;font-weight:600;text-align:right;}
.stats-tbl th:first-child{text-align:left;}
.stats-tbl td{padding:10px 6px;border-bottom:1px solid #f0f0f0;text-align:right;vertical-align:top;}
.stats-tbl td:first-child{text-align:left;color:#444;}
.stats-tbl .sigma-row{border-top:2px solid #333;font-weight:700;}
.stats-tbl .sigma-row td{padding-top:12px;}
.stats-rank{color:#888;font-size:12px;margin-right:6px;}
.stats-pct{font-size:11px;color:#999;display:block;}
.stats-plus{color:#339af0;font-weight:700;}
.stats-minus{color:var(--color-minus,#e53935);font-weight:700;}
.stats-chart-wrap{padding:12px 14px 0;}
/* 통계 모달 공통 */
.stats-modal-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;overflow:hidden;}
.stats-modal-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:16px 18px;font-size:16px;font-weight:700;}
.stats-modal-item{display:flex;justify-content:space-between;align-items:flex-start;padding:16px 20px;border-bottom:1px solid #f0f0f0;cursor:pointer;}
.stats-modal-item:active{background:#f5f5f5;}
.stats-modal-item-name{font-size:15px;font-weight:600;color:#222;}
.stats-modal-item-desc{font-size:12px;color:#999;margin-top:2px;}
.stats-modal-cancel{width:100%;padding:16px 0;background:var(--theme-primary,#3c3c3c);color:#fff;border:none;font-size:16px;font-weight:700;cursor:pointer;}
.stats-modal-radio{width:20px;height:20px;flex-shrink:0;margin-top:2px;accent-color:var(--theme-primary,#3c3c3c);}
/* 커스텀 필터 모달 */
.stats-filter-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;max-height:90vh;display:flex;flex-direction:column;}
.stats-filter-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:14px 18px;font-size:16px;font-weight:700;flex-shrink:0;}
.stats-filter-body{padding:16px;overflow-y:auto;flex:1;min-height:0;}
.stats-filter-row{margin-bottom:14px;}
.stats-filter-label{font-size:13px;color:#666;margin-bottom:6px;}
.stats-filter-input{width:100%;border:1px solid #ddd;border-radius:8px;padding:12px 14px;font-size:14px;outline:none;text-align:center;color:#555;}
.stats-filter-input:focus{border-color:#555;color:#222;}
.stats-filter-foot{display:flex;flex-shrink:0;}
.stats-filter-foot button{flex:1;padding:15px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.stats-filter-foot button:first-child{background:#555;border-right:1px solid #444;}

/* ══ 자료차트 ══ */
.chart-cumul-btn{padding:6px 16px;border:2px solid #333;border-radius:20px;background:#fff;font-size:13px;font-weight:600;cursor:pointer;color:#333;}
.chart-donut-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;}

/* ══ 예산 카드 ══ */
.budget-card{padding:16px 14px;border-bottom:1px solid #ebebeb;cursor:pointer;}
.budget-card:active{background:#f7f7f7;}
.budget-card-hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.budget-card-name{font-size:15px;font-weight:700;color:#222;}
.budget-card-limit{font-size:15px;font-weight:700;color:#222;}
.budget-prog-wrap{height:16px;background:#e0e0e0;border-radius:8px;margin-bottom:10px;overflow:hidden;}
.budget-prog{height:100%;background:#555;border-radius:8px;min-width:0;transition:width .4s;}
.budget-prog.over{background:var(--color-minus,#e53935);}
.budget-stat-row{display:flex;justify-content:space-between;font-size:13px;padding:2px 0;color:#555;}
.budget-stat-row b{color:#222;font-weight:600;}
/* 예산 메뉴 모달 */
.budget-menu-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;overflow:hidden;}
.budget-menu-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:16px 18px;font-size:16px;font-weight:700;}
.budget-menu-item{display:flex;align-items:center;gap:14px;padding:16px 20px;border-bottom:1px solid #f0f0f0;font-size:15px;cursor:pointer;}
.budget-menu-item:active{background:#f5f5f5;}
.budget-menu-cancel{width:100%;padding:16px 0;background:var(--theme-primary,#3c3c3c);color:#fff;border:none;font-size:16px;font-weight:700;cursor:pointer;}
/* 예산 추가 선택 모달 */
.budget-type-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;overflow:hidden;}
.budget-type-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:16px 18px;font-size:16px;font-weight:700;display:flex;justify-content:space-between;align-items:center;}
.budget-type-item{padding:18px 20px;border-bottom:1px solid #f0f0f0;font-size:15px;cursor:pointer;}
.budget-type-item:active{background:#f5f5f5;}
.budget-type-cancel{width:100%;padding:16px 0;background:var(--theme-primary,#3c3c3c);color:#fff;border:none;font-size:16px;font-weight:700;cursor:pointer;}
/* 예산 폼 모달 */
.budget-form-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.budget-form-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:14px 18px;font-size:16px;font-weight:700;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
.budget-form-body{padding:18px 16px;overflow-y:auto;flex:1;min-height:0;}
.budget-form-row{margin-bottom:14px;}
.budget-form-label{font-size:13px;color:#666;margin-bottom:6px;}
.budget-form-input{width:100%;border:1px solid #ddd;border-radius:8px;padding:12px 14px;font-size:15px;text-align:center;outline:none;}
.budget-form-input:focus{border-color:#555;}
.budget-form-foot{display:flex;flex-shrink:0;min-height:54px;}
.budget-form-foot button{flex:1;padding:15px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.budget-form-foot button:first-child{background:#555;border-right:1px solid #444;}
/* 예산 선택 시트 (카테고리/결제수단) */
.budget-sel-box{background:#fff;border-radius:16px 16px 0 0;width:100%;max-width:480px;margin:0 auto;max-height:85vh;display:flex;flex-direction:column;}
.budget-sel-hd{background:var(--theme-primary,#3c3c3c);color:#fff;padding:16px 18px;font-size:15px;font-weight:700;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
.budget-sel-list{overflow-y:auto;flex:1;min-height:0;}
.budget-sel-item{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #f0f0f0;font-size:15px;}
.budget-sel-section{background:var(--theme-primary,#3c3c3c);color:#fff;padding:10px 20px;font-size:13px;font-weight:600;}
.budget-sel-foot{display:flex;flex-shrink:0;}
.budget-sel-foot button{flex:1;padding:15px 0;border:none;font-size:15px;font-weight:700;cursor:pointer;background:var(--theme-primary,#3c3c3c);color:#fff;}
.budget-sel-foot button:first-child{background:#555;border-right:1px solid #444;}

/* ══ 다중 선택 모드 ══ */
.multi-mode .tx-row{padding-left:10px;}
.tx-chk{display:none;width:18px;height:18px;margin-right:6px;accent-color:var(--theme-primary,#3c3c3c);flex-shrink:0;}
.multi-mode .tx-chk{display:inline-block;}
.multi-bar{display:none;position:fixed;bottom:0;left:0;right:0;max-width:480px;margin:0 auto;background:var(--theme-primary,#3c3c3c);color:#fff;padding:14px 16px;z-index:200;display:flex;justify-content:space-between;align-items:center;}
.multi-bar button{background:#555;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:14px;cursor:pointer;}
</style>
</head>
<body>

<!-- ══ 사이드 드로어 ══ -->
<style>
.drawer-user-card{display:flex;align-items:center;gap:12px;padding:20px 16px 16px;position:relative;}
.drawer-user-info{flex:1;min-width:0;}
.drawer-user-name{font-size:16px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.drawer-user-email{font-size:12px;color:rgba(255,255,255,.75);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.drawer-user-more{background:none;border:none;color:rgba(255,255,255,.85);cursor:pointer;padding:6px 10px;border-radius:8px;font-size:18px;font-weight:700;letter-spacing:2px;flex-shrink:0;line-height:1;}
.drawer-user-more:active{background:rgba(255,255,255,.15);}
.drawer-user-popup{display:none;position:absolute;top:calc(100% - 8px);right:12px;background:#fff;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.2);min-width:150px;z-index:500;overflow:hidden;}
.drawer-user-popup.show{display:block;}
.drawer-popup-item{display:flex;align-items:center;gap:10px;padding:13px 16px;font-size:14px;color:#212121;cursor:pointer;}
.drawer-popup-item:active{background:#f5f5f5;}
.drawer-popup-item i{font-size:18px;color:#555;}
.drawer-popup-sep{height:1px;background:#f0f0f0;}
</style>
<div class="side-drawer-overlay" id="sideDrawer" onclick="closeSideDrawer(event)">
  <div class="side-drawer">
    <div class="drawer-top">
      <!-- 유저 카드 -->
      <div class="drawer-user-card" id="drawerUserCard">
        <div class="drawer-user-info">
          <div class="drawer-user-name"><?=htmlspecialchars($_SESSION['user_name'] ?? '사용자')?>님</div>
          <div class="drawer-user-email"><?=htmlspecialchars($_SESSION['user_email'] ?? '')?></div>
        </div>
        <button class="drawer-user-more" onclick="toggleUserPopup(event)">···</button>
        <div class="drawer-user-popup" id="drawerUserPopup">
          <div class="drawer-popup-item" onclick="location.href='settings.php'">
            <span class="material-icons">manage_accounts</span> 계정 설정
          </div>
          <div class="drawer-popup-sep"></div>
          <div class="drawer-popup-item" onclick="location.href='logout.php'" style="color:#e53935;">
            <span class="material-icons" style="color:#e53935;">logout</span> 로그아웃
          </div>
        </div>
      </div>
      <div class="drawer-top-item" onclick="location.href='settings.php'"><i class="bi bi-gear-fill"></i> 설정</div>
      <div class="drawer-top-item" onclick="location.href='help.php'"><i class="bi bi-question-circle-fill"></i> 도움말</div>
      <div class="drawer-top-item" onclick="closeSideDrawer(event);openBkSheet()"><i class="bi bi-cloud-arrow-up-fill"></i> 백업과 복구</div>
    </div>
    <div class="drawer-divider"></div>
    <div class="drawer-bottom">
      <div class="drawer-bottom-item" onclick="location.href='calculator.php'"><i class="bi bi-calculator"></i> 계산기</div>
      <div class="drawer-bottom-item" onclick="location.href='balance.php'"><i class="bi bi-bank"></i> 잔액 관리</div>
      <div class="drawer-bottom-item" onclick="location.href='spending_pattern.php'"><i class="bi bi-bar-chart-fill"></i> 지출 패턴</div>
    </div>
  </div>
</div>
<script>
function toggleUserPopup(e) {
  e.stopPropagation();
  document.getElementById('drawerUserPopup').classList.toggle('show');
}
document.addEventListener('click', function() {
  var p = document.getElementById('drawerUserPopup');
  if (p) p.classList.remove('show');
});
</script>

<!-- ══ 헤더 ══ -->
<header class="hd">
  <span class="hd-menu" onclick="openSideDrawer()"><i class="bi bi-list"></i></span>
  <span class="hd-title">똑똑가계부</span>
  <div class="hd-icons">
    <i class="bi bi-search" onclick="openSearchModal()"></i>
    <i class="bi bi-three-dots-vertical" onclick="toggleMoreMenu(event)"></i>
    <!-- 드롭다운 -->
    <div id="moreMenu" class="more-menu" style="display:none">
      <div class="more-menu-item" onclick="closeMoreMenu();openSmsPermission()">이전 문자 등록</div>
      <div class="more-menu-item" onclick="closeMoreMenu();location.href='category_classify.php'">카테고리 일괄 분류</div>
      <div class="more-menu-item" onclick="closeMoreMenu();openFavorites()">즐겨찾기 편집</div>
      <div class="more-menu-item" onclick="closeMoreMenu();openExcelInfo()">엑셀 내보내기</div>
      <div class="more-menu-item" onclick="location.href='settings.php'">설정</div>
    </div>
  </div>
</header>

<!-- ══ 탭 ══ -->
<nav class="tabs">
<?php foreach(['expense'=>'지출내역','income'=>'수입내역','budget'=>'예산관리','stats'=>'자료통계','chart'=>'자료차트'] as $k=>$v): ?>
  <div class="tab <?= $activeTab===$k?'active':'' ?>" onclick="switchTab('<?=$k?>')"><?=$v?></div>
<?php endforeach; ?>
</nav>

<!-- ══════════════════════════════════════
     탭 1 : 지출내역
══════════════════════════════════════ -->
<div id="tab-expense" class="tab-panel <?= $activeTab==='expense'?'active':'' ?>">

<?php if ($srchMode): ?>
  <!-- ▼ 검색 결과 영역 -->
  <div class="srch-header">
    <span>검색 결과</span>
    <span class="srch-count"><?= count($srchResults) ?>건</span>
    <a class="srch-clear" href="?tab=expense">✕ 검색 초기화</a>
  </div>
  <?php if (empty($srchResults)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9E9E9E;font-size:15px">검색 결과가 없습니다.</div>
  <?php else: ?>
    <?php
    $srchByDate = [];
    foreach ($srchResults as $tx) { $srchByDate[$tx['tx_date']][] = $tx; }
    foreach ($srchByDate as $day => $txs):
        $dt2 = new \DateTime($day);
        $dows = ['일','월','화','수','목','금','토'];
        $dowLabel = $dows[(int)$dt2->format('w')];
        $dayTotal = array_sum(array_map(fn($t)=>$t['category_type']==='income'?0:$t['amount'], $txs));
    ?>
    <div class="date-row">
      <div class="date-badge has"><?= $dt2->format('Y.m.d') . ' (' . $dowLabel . ')' ?></div>
      <div class="date-total has" data-amt="<?= $dayTotal ?>">₩<?= number_format($dayTotal) ?></div>
    </div>
    <?php foreach ($txs as $tx): ?>
    <div class="tx-row"
         onclick="openTxMenu(this)"
         data-id="<?=(int)$tx['id']?>"
         data-pm="<?=htmlspecialchars($tx['payment_method']??'')?>"
         data-cat="<?=(int)$tx['category_id']?>"
         data-cat-name="<?=htmlspecialchars($tx['category_name']??'')?>"
         data-cat-type="<?=$tx['category_type']??'expense'?>"
         data-amt="<?=(int)$tx['amount']?>"
         data-desc="<?=htmlspecialchars($tx['description']??'')?>"
         data-tx-date="<?=$tx['tx_date']?>"
         data-created="<?=$tx['created_at']??''?>"
         data-user="<?=$userId?>">
      <div class="tx-cat"><?= htmlspecialchars($tx['category_name'] ?? '미분류') ?></div>
      <div class="tx-desc"><?= htmlspecialchars($tx['description'] ?? '') ?></div>
      <div class="tx-right">
        <span class="<?= $tx['category_type']==='income'?'income-amt':'tx-amt' ?>"
              data-amt="<?= (int)$tx['amount'] ?>">₩<?= number_format($tx['amount']) ?></span>
        <?php if ($tx['payment_method']): ?>
        <div class="tx-pm"><?= htmlspecialchars($tx['payment_method']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php else: ?>
  <!-- ▼ 월 컨트롤러 + 필터 고정 영역 -->
  <div class="sticky-controls">
    <div class="month-bar">
      <button class="month-btn" onclick="goMonth('<?=$prev?>')">←</button>
      <div class="month-label"><?=$ymKorean?></div>
      <button class="month-btn" onclick="goMonth('<?=$next?>')">→</button>
      <div class="month-total" data-amt="<?=(int)$summary['expense']?>">₩<?=number_format($summary['expense'])?></div>
    </div>
    <!-- 필터 버튼 -->
    <div class="filter-row">
      <button class="filter-btn" id="btnPM" onclick="openFilter('payment')">결제수단별</button>
      <button class="filter-btn" id="btnCat" onclick="openFilter('category')">카테고리별</button>
      <button class="filter-btn" id="btnCal" onclick="toggleCalendar()">달력 보기</button>
    </div>
  </div>

  <!-- 달력 뷰 (기본 숨김) -->
  <div id="calendarView" style="display:none">
    <?php
    // 달력 데이터 계산
    $firstDow = (int)(new \DateTime($yearMonth.'-01'))->format('w'); // 0=일
    $today = date('Y-m-d');

    // 요일별 합계/평균 계산
    $weekdayTotal = array_fill(0, 7, 0);
    $weekdayCount = array_fill(0, 7, 0);
    foreach ($expenseGrouped as $date => $txs) {
        $dow = (int)(new \DateTime($date))->format('w');
        $weekdayTotal[$dow] += array_sum(array_column($txs,'amount'));
        $weekdayCount[$dow]++;
    }
    ?>
    <div class="cal-wrap">
    <table class="cal-table">
      <thead>
        <tr>
          <th class="week-col"></th>
          <th class="sun">일</th><th>월</th><th>화</th><th>수</th><th>목</th><th class="">금</th><th class="sat">토</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $cellDay = 1 - $firstDow; // 첫 주 시작 (음수면 이전 달)
      $prevDt  = (clone $dt)->modify('-1 month');
      $prevDays = (int)$prevDt->format('t');
      $nextDay  = 1;

      for ($week = 0; $week < 6; $week++) {
          // 이 주에 이번 달 날짜가 있는지 확인
          $hasThisMonth = false;
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $cellDay + $dow;
              if ($d >= 1 && $d <= $daysInMonth) { $hasThisMonth = true; break; }
          }
          if (!$hasThisMonth && $week >= 4) break;

          // 주간 합계 계산
          $weekSum = 0;
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $cellDay + $dow;
              if ($d >= 1 && $d <= $daysInMonth) {
                  $key = sprintf('%s-%02d', $yearMonth, $d);
                  $weekSum += $dailyExpense[$key] ?? 0;
              }
          }
          echo '<tr>';
          echo '<td class="week-col">' . ($weekSum > 0 ? number_format($weekSum) : '0') . '</td>';
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $cellDay + $dow;
              $cls = 'day';
              if ($dow === 0) $cls .= ' sun';
              if ($dow === 6) $cls .= ' sat';

              if ($d < 1) {
                  $dispDay = $prevDays + $d;
                  $cls .= ' other';
                  $dateKey = $prevDt->format('Y-m') . sprintf('-%02d', $dispDay);
              } elseif ($d > $daysInMonth) {
                  $dispDay = $nextDay++;
                  $cls .= ' other';
                  $dateKey = '';
              } else {
                  $dispDay = $d;
                  $dateKey = sprintf('%s-%02d', $yearMonth, $d);
                  if ($dateKey === $today) $cls .= ' today';
              }

              $dayAmt = ($dateKey && isset($dailyExpense[$dateKey])) ? $dailyExpense[$dateKey] : 0;
              echo '<td class="'.$cls.'" onclick="calDayClick(\''.$dateKey.'\')">';
              echo '<div class="dn">'.$dispDay.'</div>';
              if ($dayAmt > 0) echo '<div class="da">'.number_format($dayAmt).'</div>';
              echo '</td>';
          }
          echo '</tr>';
          $cellDay += 7;
      }
      ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="lbl">평균</td>
          <?php for($d=0;$d<7;$d++): ?>
          <td><?= $weekdayCount[$d]>0 ? number_format((int)($weekdayTotal[$d]/$weekdayCount[$d])) : '0' ?></td>
          <?php endfor; ?>
        </tr>
        <tr>
          <td class="lbl">합계</td>
          <?php for($d=0;$d<7;$d++): ?>
          <td><?= number_format($weekdayTotal[$d]) ?></td>
          <?php endfor; ?>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>

  <!-- 목록 뷰 -->
  <div id="listView">
    <?php foreach ($allDays as $day):
      $hasTx  = isset($expenseGrouped[$day]);
      $dayTot = $hasTx ? array_sum(array_column($expenseGrouped[$day],'amount')) : 0;
    ?>
    <div class="day-group"
         data-date="<?=$day?>"
         data-total="<?=$dayTot?>">
      <div class="date-row" onclick="toggleDay(this)">
        <div class="date-badge <?=$hasTx?'has':''?>"
             onclick="openAddOnDate('<?=$day?>',event)"
             style="cursor:pointer"><?=fmtDate($day)?></div>
        <div class="date-total <?=$hasTx?'has':''?>" data-amt="<?=$hasTx?$dayTot:0?>">
          <?=$hasTx?'₩'.number_format($dayTot):'₩0'?>
        </div>
      </div>
      <?php if ($hasTx): ?>
      <div class="tx-items">
        <?php foreach ($expenseGrouped[$day] as $tx): ?>
        <div class="tx-row"
             onclick="openTxMenu(this)"
             data-id="<?=(int)$tx['id']?>"
             data-pm="<?=htmlspecialchars($tx['payment_method'])?>"
             data-cat="<?=(int)$tx['category_id']?>"
             data-cat-name="<?=htmlspecialchars($tx['category_name']??'')?>"
             data-cat-type="<?=$tx['category_type']??'expense'?>"
             data-amt="<?=(int)$tx['amount']?>"
             data-desc="<?=htmlspecialchars($tx['description']??'')?>"
             data-tx-date="<?=$tx['tx_date']?>"
             data-created="<?=$tx['created_at']??''?>"
             data-user="<?=$userId?>">
          <input type="checkbox" class="tx-chk" value="<?=(int)$tx['id']?>"
                 onclick="event.stopPropagation()" style="display:none">
          <span class="tx-pm"><?=htmlspecialchars($tx['payment_method'])?></span>
          <span class="tx-cat"><?=htmlspecialchars($tx['category_name']??'')?></span>
          <span class="tx-desc"><?=htmlspecialchars($tx['description']??'')?></span>
          <span class="tx-amt" data-amt="<?=(int)$tx['amount']?>">₩<?=number_format($tx['amount'])?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; // srchMode ?>
</div>

<!-- ══════════════════════════════════════
     탭 2 : 수입내역
══════════════════════════════════════ -->
<div id="tab-income" class="tab-panel <?= $activeTab==='income'?'active':'' ?>">

  <!-- ▼ 월 컨트롤러 + 필터 고정 영역 -->
  <div class="sticky-controls">
    <div class="month-bar">
      <button class="month-btn" onclick="goMonth('<?=$prev?>')">←</button>
      <div class="month-label"><?=$ymKorean?></div>
      <button class="month-btn" onclick="goMonth('<?=$next?>')">→</button>
      <div class="month-total" data-amt="<?=(int)$summary['income']?>">₩<?=number_format($summary['income'])?></div>
    </div>
    <div class="filter-row">
      <button class="filter-btn" id="btnIncomePM"  onclick="openIncomeFilter('payment')">결제수단별</button>
      <button class="filter-btn" id="btnIncomeCat" onclick="openIncomeFilter('category')">카테고리별</button>
      <button class="filter-btn" id="btnIncomeCal" onclick="toggleIncomeCal()">달력 보기</button>
    </div>
  </div>

  <!-- 수입 달력 뷰 -->
  <div id="incomeCalView" style="display:none">
    <?php
    $incFirstDow = (int)(new \DateTime($yearMonth.'-01'))->format('w');
    $incWeekdayTotal = array_fill(0, 7, 0);
    $incWeekdayCount = array_fill(0, 7, 0);
    foreach ($incomeGrouped as $date => $txs) {
        $dow = (int)(new \DateTime($date))->format('w');
        $incWeekdayTotal[$dow] += array_sum(array_column($txs,'amount'));
        $incWeekdayCount[$dow]++;
    }
    ?>
    <div class="cal-wrap">
    <table class="cal-table">
      <thead>
        <tr>
          <th class="week-col"></th>
          <th class="sun">일</th><th>월</th><th>화</th><th>수</th><th>목</th><th>금</th><th class="sat">토</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $incCellDay = 1 - $incFirstDow;
      $incPrevDt  = (clone $dt)->modify('-1 month');
      $incPrevDays = (int)$incPrevDt->format('t');
      $incNextDay  = 1;
      for ($week = 0; $week < 6; $week++) {
          $hasThisMonth = false;
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $incCellDay + $dow;
              if ($d >= 1 && $d <= $daysInMonth) { $hasThisMonth = true; break; }
          }
          if (!$hasThisMonth && $week >= 4) break;
          $weekSum = 0;
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $incCellDay + $dow;
              if ($d >= 1 && $d <= $daysInMonth) {
                  $key = sprintf('%s-%02d', $yearMonth, $d);
                  $weekSum += $dailyIncome[$key] ?? 0;
              }
          }
          echo '<tr>';
          echo '<td class="week-col">' . ($weekSum > 0 ? number_format($weekSum) : '0') . '</td>';
          for ($dow = 0; $dow < 7; $dow++) {
              $d = $incCellDay + $dow;
              $cls = 'day';
              if ($dow === 0) $cls .= ' sun';
              if ($dow === 6) $cls .= ' sat';
              if ($d < 1) {
                  $dispDay = $incPrevDays + $d;
                  $cls .= ' other';
                  $dateKey = '';
              } elseif ($d > $daysInMonth) {
                  $dispDay = $incNextDay++;
                  $cls .= ' other';
                  $dateKey = '';
              } else {
                  $dispDay = $d;
                  $dateKey = sprintf('%s-%02d', $yearMonth, $d);
                  if ($dateKey === $today) $cls .= ' today';
              }
              $dayAmt = ($dateKey && isset($dailyIncome[$dateKey])) ? $dailyIncome[$dateKey] : 0;
              echo '<td class="'.$cls.'" onclick="incomeCalDayClick(\''.$dateKey.'\')">';
              echo '<div class="dn">'.$dispDay.'</div>';
              if ($dayAmt > 0) echo '<div class="da" style="color:#339af0">'.number_format($dayAmt).'</div>';
              echo '</td>';
          }
          echo '</tr>';
          $incCellDay += 7;
      }
      ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="lbl">평균</td>
          <?php for($d=0;$d<7;$d++): ?>
          <td><?= $incWeekdayCount[$d]>0 ? number_format((int)($incWeekdayTotal[$d]/$incWeekdayCount[$d])) : '0' ?></td>
          <?php endfor; ?>
        </tr>
        <tr>
          <td class="lbl">합계</td>
          <?php for($d=0;$d<7;$d++): ?>
          <td><?= number_format($incWeekdayTotal[$d]) ?></td>
          <?php endfor; ?>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>

  <!-- 수입 목록 뷰 -->
  <div id="incomeListView">
    <div class="income-summary">
      <span>이번 달 수입-지출 :</span>
      <b data-amt="<?=abs((int)$summary['balance'])?>" data-sign="<?=$summary['balance']>=0?'+':'-'?>">₩<?=number_format($summary['balance'])?></b>
    </div>
    <?php if (empty($incomeTx)): ?>
      <div class="empty">이번 달 수입 내역이 없습니다.</div>
    <?php else: ?>
      <?php foreach ($incomeTx as $tx): ?>
      <div class="income-row income-filter-row"
           onclick="openTxMenu(this)"
           data-id="<?=(int)$tx['id']?>"
           data-pm="<?=htmlspecialchars($tx['payment_method']??'')?>"
           data-cat="<?=(int)$tx['category_id']?>"
           data-cat-name="<?=htmlspecialchars($tx['category_name']??'')?>"
           data-cat-type="<?=$tx['category_type']??'income'?>"
           data-amt="<?=(int)$tx['amount']?>"
           data-desc="<?=htmlspecialchars($tx['description']??'')?>"
           data-tx-date="<?=$tx['tx_date']?>"
           data-created="<?=$tx['created_at']??''?>"
           data-user="<?=$userId?>"
           style="cursor:pointer">
        <span class="income-date"><?=substr($tx['tx_date'],5)?></span>
        <span class="income-cat"><?=htmlspecialchars($tx['category_name']??'')?></span>
        <span class="income-desc"><?=htmlspecialchars($tx['description']??'')?></span>
        <span class="income-amt" data-amt="<?=(int)$tx['amount']?>">₩<?=number_format($tx['amount'])?></span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ══════════════════════════════════════
     탭 3 : 예산관리
══════════════════════════════════════ -->
<div id="tab-budget" class="tab-panel <?= $activeTab==='budget'?'active':'' ?>">

  <!-- ▼ 기간 컨트롤러 + 모드 버튼 (sticky) -->
  <div class="sticky-controls">
    <div class="month-bar">
      <button class="month-btn" onclick="goBudgetPrev()">←</button>
      <div class="month-label" style="font-size:13px"><?=$bPeriodLabel?></div>
      <button class="month-btn" onclick="goBudgetNext()">→</button>
    </div>
    <div class="filter-row">
      <button class="filter-btn <?=$btype==='weekly'?'on':''?>"
              onclick="switchBudgetType('weekly')">주별 예산</button>
      <button class="filter-btn <?=$btype==='monthly'?'on':''?>"
              onclick="switchBudgetType('monthly')">월별 예산</button>
      <button class="filter-btn <?=$btype==='yearly'?'on':''?>"
              onclick="switchBudgetType('yearly')">연별 예산</button>
    </div>
  </div>

  <?php if (empty($budgetCards)): ?>
    <div class="empty">예산을 추가해 주세요!<br><small style="color:#aaa;font-size:12px">우측 하단 + 버튼을 눌러 등록하세요</small></div>
  <?php else: ?>
    <?php foreach ($budgetCards as $b):
      $spent       = calcBudgetSpent($userId, $b, $bPeriodStart, $bPeriodEnd);
      $limit       = (int)$b['limit_amount'];
      $pct         = $limit > 0 ? min(100, round($spent / $limit * 100)) : 0;
      $appropriate = $limit > 0 ? (int)($limit / $bTotalDays * $bElapsed) : 0;
      $remaining   = $limit - $spent;
      $dailyAvail  = ($bRemaining > 0 && $remaining > 0) ? (int)($remaining / $bRemaining) : 0;
      $dLabel      = 'D-' . $bRemaining;
    ?>
    <div class="budget-card"
         onclick="openBudgetMenu(<?=(int)$b['id']?>, '<?=addslashes(htmlspecialchars($b['name']))?>', <?=$userId?>)">
      <div class="budget-card-hd">
        <span class="budget-card-name">| <?=htmlspecialchars($b['name'])?> |</span>
        <span class="budget-card-limit" data-amt="<?=(int)$limit?>">₩<?=number_format($limit)?></span>
      </div>
      <div class="budget-prog-wrap">
        <div class="budget-prog <?=$pct>=100?'over':''?>" style="width:<?=$pct?>%"></div>
      </div>
      <div class="budget-stat-row">
        <span>금일 기준 적정 사용금액</span>
        <b data-amt="<?=(int)$appropriate?>">₩<?=number_format($appropriate)?></b>
      </div>
      <div class="budget-stat-row">
        <span>현재 사용금액 (예정금액 포함)</span>
        <b data-amt="<?=(int)$spent?>">₩<?=number_format($spent)?></b>
      </div>
      <div class="budget-stat-row">
        <span>이번 <?=$bTypeLabel?> 남은 예산 (<?=$dLabel?>)</span>
        <b data-amt="<?=(int)max(0,$remaining)?>">₩<?=number_format(max(0,$remaining))?></b>
      </div>
      <div class="budget-stat-row">
        <span>평균 하루 사용 가능금액</span>
        <b data-amt="<?=(int)max(0,$dailyAvail)?>">₩<?=number_format(max(0,$dailyAvail))?></b>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════
     탭 4 : 자료통계
══════════════════════════════════════ -->
<div id="tab-stats" class="tab-panel <?= $activeTab==='stats'?'active':'' ?>">

  <!-- sticky 기간/필터 바 -->
  <div class="sticky-controls">
    <div class="month-bar">
      <button class="month-btn" onclick="goStatsPrev()" <?=$sPPrev?'':'style="visibility:hidden"'?>>←</button>
      <div class="month-label" style="font-size:13px"><?=$sPeriodLabel?></div>
      <button class="month-btn" onclick="goStatsNext()" <?=$sPNext?'':'style="visibility:hidden"'?>>→</button>
    </div>
    <div class="filter-row">
      <button class="filter-btn" id="sBtnType" onclick="openStatsModal('type')"><?=$sTypeLabel?></button>
      <?php if ($stype==='ec'||$stype==='ic'): ?>
        <?php $classifyLabels=['cat'=>'카테고리별','pm'=>'결제수단별','sub'=>'세부분류별','dow'=>'요일별','hour'=>'시간대별','desc'=>'내역별']; ?>
        <button class="filter-btn" id="sBtnUnit" onclick="openStatsModal('classify')"><?=$classifyLabels[$sclassify]?></button>
        <button class="filter-btn" id="sBtnScope" onclick="openStatsModal('unit')"><?=$sUnitLabelCat?></button>
      <?php else: ?>
        <button class="filter-btn" id="sBtnUnit" onclick="openStatsModal('unit')"><?=$sUnitLabel?></button>
        <button class="filter-btn <?=($scat>0||$spm2||$skw||$sdow>=0)?'active-filter':''?>"
                id="sBtnScope" onclick="openStatsModal('filter')"><?=htmlspecialchars($sBtn3Label)?></button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($statsRows)): ?>
    <div class="empty">해당 기간에 데이터가 없습니다.</div>
  <?php else: ?>

  <!-- 테이블 -->
  <table class="stats-tbl" style="margin:12px 0">
    <?php
    if ($stype === 'ep') {
        $h = ['종료일','지출 금액',$sAvgLabel];
    } elseif ($stype === 'ip') {
        $h = ['종료일','수입 금액',$sAvgLabel];
    } elseif ($stype === 'ec' || $stype === 'ic') {
        $amtLabel = $stype==='ec' ? '지출 금액' : '수입 금액';
        if ($sclassify === 'dow')       { $h = ['요일', $amtLabel]; }
        elseif ($sclassify === 'hour')  { $h = ['시간대', $amtLabel]; }
        else                            { $h = ['순위', $sclassify==='pm'?'결제수단':($sclassify==='sub'?'세부분류':($sclassify==='desc'?'내역':'카테고리')), $amtLabel]; }
    } else {
        $h = ['종료일','수입-지출','누적 금액'];
    }
    ?>
    <thead><tr>
      <?php foreach ($h as $hh): ?><th><?=$hh?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php
    $cumulative = 0;
    $rank = 1;
    foreach ($statsRows as $row):
      if ($stype === 'ep' || $stype === 'ip'):
        $pLabel = $row['period'];
        if ($sgroupBy === 'r7') {
            $pLabel = substr($row['period'], 5); // mm-dd
        } elseif ($sgroupBy === 'w') {
            $pLabel = (new \DateTime($row['period']))->format('y.m.d');
        } elseif ($sgroupBy === 'm') {
            $pLabel = str_replace('-', '.', $row['period']);
        }
    ?>
    <tr>
      <td><?=$pLabel?></td>
      <td><span data-amt="<?=(int)$row['total']?>">₩<?=number_format($row['total'])?></span></td>
      <td><span data-amt="<?=(int)$statsAvgVal?>">₩<?=number_format($statsAvgVal)?></span></td>
    </tr>
    <?php elseif ($stype === 'ec' || $stype === 'ic'):
      $pct = $statsTotal > 0 ? round($row['total']/$statsTotal*100,1) : 0;
      if ($sclassify === 'dow') {
          $dowNames = ['월','화','수','목','금','토','일'];
          $displayLabel = $dowNames[(int)$row['label']] ?? $row['label'];
      } elseif ($sclassify === 'hour') {
          $displayLabel = (int)$row['label'].'시';
      } else {
          $displayLabel = htmlspecialchars($row['label']??'없음');
      }
    ?>
    <?php if ($sclassify === 'dow' || $sclassify === 'hour'): ?>
    <tr>
      <td><?=$displayLabel?></td>
      <td><span data-amt="<?=(int)$row['total']?>">₩<?=number_format($row['total'])?></span><span class="stats-pct">(<?=$pct?>%)</span></td>
    </tr>
    <?php else: ?>
    <tr>
      <td><?=$rank?></td>
      <td><?=$displayLabel?><span class="stats-pct">(<?=$pct?>%)</span></td>
      <td><span data-amt="<?=(int)$row['total']?>">₩<?=number_format($row['total'])?></span><span class="stats-pct">(<?=$pct?>%)</span></td>
    </tr>
    <?php endif; ?>
    <?php $rank++; else: // combined
      $balance    = (int)$row['inc'] - (int)$row['exp'];
      $cumulative += $balance;
      $pLabel = $row['period'];
      if ($sgroupBy === 'r7') { $pLabel = substr($row['period'], 5); }
      elseif ($sgroupBy === 'w') { $pLabel = (new \DateTime($row['period']))->format('y.m.d'); }
      elseif ($sgroupBy === 'm') { $pLabel = str_replace('-','.',$row['period']); }
    ?>
    <tr>
      <td><?=$pLabel?></td>
      <td class="<?=$balance>=0?'stats-plus':'stats-minus'?>"><span data-amt="<?=abs($balance)?>" data-sign="<?=$balance>=0?'+':'-'?>"><?=($balance>=0?'+':'-')?>₩<?=number_format(abs($balance))?></span></td>
      <td class="<?=$cumulative>=0?'stats-plus':'stats-minus'?>"><span data-amt="<?=abs($cumulative)?>" data-sign="<?=$cumulative>=0?'+':'-'?>"><?=($cumulative>=0?'+':'-')?>₩<?=number_format(abs($cumulative))?></span></td>
    </tr>
    <?php endif; endforeach; ?>

    <?php if ($stype==='ec'||$stype==='ic'): ?>
    <?php if ($sclassify==='dow'||$sclassify==='hour'): ?>
    <tr class="sigma-row">
      <td>Σ 합계</td>
      <td><span data-amt="<?=(int)$statsTotal?>">₩<?=number_format($statsTotal)?></span><span class="stats-pct">(100%)</span></td>
    </tr>
    <?php else: ?>
    <tr class="sigma-row">
      <td>Σ</td>
      <td>총 합계<span class="stats-pct">(100%)</span></td>
      <td><span data-amt="<?=(int)$statsTotal?>">₩<?=number_format($statsTotal)?></span><span class="stats-pct">(100%)</span></td>
    </tr>
    <?php endif; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════
     탭 5 : 자료차트
══════════════════════════════════════ -->
<div id="tab-chart" class="tab-panel <?= $activeTab==='chart'?'active':'' ?>">

  <!-- sticky 기간/필터 바 -->
  <div class="sticky-controls">
    <div class="month-bar">
      <button class="month-btn" onclick="goChartPrev()" <?=$cPPrev?'':'style="visibility:hidden"'?>>←</button>
      <div class="month-label" style="font-size:13px"><?=$cPeriodLabel?></div>
      <button class="month-btn" onclick="goChartNext()" <?=$cPNext?'':'style="visibility:hidden"'?>>→</button>
    </div>
    <div class="filter-row">
      <button class="filter-btn" onclick="openChartModal('type')"><?=$cTypeLabel?></button>
      <?php if ($ctype==='ec'||$ctype==='ic'): ?>
        <button class="filter-btn" onclick="openChartModal('classify')"><?=$cClassifyLabel?></button>
        <button class="filter-btn" onclick="openChartModal('unit')"><?=$cUnitLabel?></button>
      <?php else: ?>
        <button class="filter-btn" onclick="openChartModal('unit')"><?=$cUnitLabel?></button>
        <button class="filter-btn <?=($cfcat>0||$cfpm||$cfkw||$cfdow>=0)?'active-filter':''?>"
                onclick="openChartModal('filter')"><?=htmlspecialchars($cBtn3Label2)?></button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($ctype==='cb'): ?>
  <div style="padding:10px 14px 0">
    <button class="chart-cumul-btn" onclick="toggleChartCumul()"><?=$ccumul?'합산 보기':'누적 보기'?></button>
  </div>
  <?php endif; ?>

  <?php if (empty($chartLabels) && $chartTotal===0): ?>
    <div class="empty">해당 기간에 데이터가 없습니다.</div>
  <?php else: ?>
  <div style="padding:16px 10px 24px">
    <?php if ($ctype==='ec'||$ctype==='ic'): ?>
    <div style="position:relative;max-width:300px;margin:0 auto">
      <canvas id="cPieChart"></canvas>
      <div class="chart-donut-center">
        <div style="font-size:11px;color:#888">총 합계 금액</div>
        <div style="font-size:14px;font-weight:700;color:#222" data-amt="<?=(int)$chartTotal?>">₩<?=number_format($chartTotal)?></div>
      </div>
    </div>
    <?php else: ?>
    <canvas id="cBarChart" style="max-height:280px"></canvas>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════
     자료차트 - 차트 종류 선택
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="chartTypeOverlay" onclick="closeOnBg(event,'chartTypeOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">차트 종류 선택</div>
    <?php foreach(['ep'=>['지출 기간 차트','기간 단위 지출 막대 차트'],'ec'=>['지출 분류 차트','카테고리나 카드별 지출 원형 차트'],'ip'=>['수입 기간 차트','기간 단위 수입 막대 차트'],'ic'=>['수입 분류 차트','카테고리나 카드별 수입 원형 차트'],'cb'=>['합산 차트','수입과 지출의 합산 차트']] as $k=>$v): ?>
    <div class="stats-modal-item" onclick="goChart('ctype','<?=$k?>')">
      <div>
        <div class="stats-modal-item-name"><?=$v[0]?></div>
        <div class="stats-modal-item-desc"><?=$v[1]?></div>
      </div>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <?php endforeach; ?>
    <button class="stats-modal-cancel" onclick="closeModal('chartTypeOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료차트 - 단위 선택
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="chartUnitOverlay" onclick="closeOnBg(event,'chartUnitOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">통계 단위 선택</div>
    <?php foreach(['all'=>'전체 기간','y'=>'연별 통계','m'=>'월별 통계','w'=>'주별 통계','r365'=>'최근 365일','r180'=>'최근 180일','r30'=>'최근 30일','r7'=>'최근 7일','custom'=>'사용자 지정'] as $k=>$v): ?>
    <div class="stats-modal-item" onclick="<?=$k==='custom'?'toggleChartCustomDate()':'goChart(\'cunit\',\''.$k.'\')'?>">
      <span class="stats-modal-item-name"><?=$v?></span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <?php endforeach; ?>
    <div id="chartCustomDateRow" style="display:<?=$cunit==='custom'?'block':'none'?>;padding:8px 12px;background:#f8f8f8;border-radius:8px;margin:4px 0">
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="date" id="ccStartInput" value="<?=htmlspecialchars($ccstart)?>" style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:13px">
        <span style="font-size:12px;color:#999">~</span>
        <input type="date" id="ccEndInput" value="<?=htmlspecialchars($ccend)?>" style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:13px">
        <button onclick="applyChartCustomDate()" style="padding:6px 14px;background:#333;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer">적용</button>
      </div>
    </div>
    <button class="stats-modal-cancel" onclick="closeModal('chartUnitOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료차트 - 커스텀 필터
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="chartFilterOverlay" onclick="closeOnBg(event,'chartFilterOverlay')">
  <div class="stats-filter-box">
    <div class="stats-filter-hd">차트 필터 선택</div>
    <div class="stats-filter-body">

      <div class="stats-filter-row">
        <div class="stats-filter-label">키워드 필터 입력</div>
        <input type="text" class="stats-filter-input" id="cf_kw" placeholder="입력"
               value="<?=htmlspecialchars($cfkw)?>">
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">카테고리 필터 선택</div>
        <select class="stats-filter-input" id="cf_cat">
          <option value="0">선택 (전체)</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?=$cat['id']?>" <?=$cfcat==$cat['id']?'selected':''?>>
            <?=htmlspecialchars($cat['name'])?> (<?=$cat['type']==='expense'?'지출':'수입'?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">결제수단 필터 선택</div>
        <select class="stats-filter-input" id="cf_pm">
          <option value="">선택 (전체)</option>
          <?php foreach ($ALL_PAYMENTS as $pm): ?>
          <option value="<?=htmlspecialchars($pm)?>" <?=$cfpm===$pm?'selected':''?>>
            <?=htmlspecialchars($pm)?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">요일 필터 선택</div>
        <select class="stats-filter-input" id="cf_dow">
          <option value="-1">선택 (전체)</option>
          <?php foreach(['월요일','화요일','수요일','목요일','금요일','토요일','일요일'] as $i=>$dn): ?>
          <option value="<?=$i?>" <?=$cfdow===$i?'selected':''?>><?=$dn?></option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>
    <div class="stats-filter-foot">
      <button onclick="resetChartFilter()">초기화</button>
      <button onclick="applyChartFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료차트 - 분류 기준 선택
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="chartClassifyOverlay" onclick="closeOnBg(event,'chartClassifyOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">분류 기준 선택</div>
    <?php foreach(['cat'=>'카테고리별','pm'=>'결제수단별','sub'=>'세부분류별','dow'=>'요일별','hour'=>'시간대별','desc'=>'내역별'] as $k=>$v): ?>
    <div class="stats-modal-item" onclick="goChart('cclassify','<?=$k?>')">
      <span class="stats-modal-item-name"><?=$v?></span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <?php endforeach; ?>
    <button class="stats-modal-cancel" onclick="closeModal('chartClassifyOverlay')">취소</button>
  </div>
</div>

<!-- ══ 플로팅 버튼 ══ -->
<button class="fab" onclick="openAdd()">+</button>

<!-- ══════════════════════════════════════
     지출 등록 모달 (지출내역1.jpg)
══════════════════════════════════════ -->
<div class="overlay" id="addOverlay" onclick="closeOnBg(event,'addOverlay')">
  <div class="add-dialog">
    <div class="add-hd">
      <span class="add-hd-title" id="addTitle">지출내역</span>
      <i class="bi bi-calculator" style="font-size:18px"></i>
    </div>
    <form id="addForm" onsubmit="submitAdd(event)">
      <input type="hidden" name="user_id" value="<?=$userId?>">
      <input type="hidden" name="source"  value="manual">
      <div class="add-body">

        <!-- 수입/지출 타입 -->
        <div class="add-row" id="typeRow">
          <span class="add-label">구분</span>
          <div class="add-type-btns">
            <button type="button" id="typeExpense" onclick="setAddType('expense')" class="sel">지출</button>
            <button type="button" id="typeIncome"  onclick="setAddType('income')">수입</button>
          </div>
        </div>

        <!-- 지출일 -->
        <div class="add-row">
          <span class="add-label">지출일</span>
          <div class="add-inputs">
            <input type="date" name="tx_date" class="half" value="<?=date('Y-m-d')?>" required>
            <input type="time" name="tx_time" class="half" value="<?=date('H:i')?>">
          </div>
        </div>

        <!-- 지출금액 -->
        <div class="add-row">
          <span class="add-label">지출금액</span>
          <div class="add-inputs">
            <input type="number" name="amount" min="1" required placeholder="입력" style="text-align:right">
          </div>
        </div>

        <!-- 지출내역 -->
        <div class="add-row">
          <span class="add-label" id="descLabel">지출내역</span>
          <div class="add-inputs">
            <input type="text" name="description" maxlength="255" placeholder="">
          </div>
        </div>

        <!-- 카테고리 -->
        <div class="add-row">
          <span class="add-label">카테고리</span>
          <div class="add-inputs">
            <select name="category_id" id="addCatSelect" class="half">
              <option value="">없음</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?=$cat['id']?>" data-type="<?=$cat['type']?>">
                <?=htmlspecialchars($cat['name'])?>
              </option>
              <?php endforeach; ?>
            </select>
            <select class="half" disabled style="color:#aaa;background:#f9f9f9">
              <option>없음</option>
            </select>
          </div>
        </div>

        <!-- 결제수단 -->
        <div class="add-row">
          <span class="add-label">결제</span>
          <div class="add-type-btns" id="pmBtns">
            <?php foreach(['현금','신용카드','체크카드','계좌이체'] as $i=>$pm): ?>
            <button type="button" onclick="setPM('<?=$pm?>')"
              class="<?=$i===0?'sel':''?>"
              style="font-size:12px;flex:none;padding:7px 8px">
              <?=$pm?>
            </button>
            <?php endforeach; ?>
            <input type="hidden" name="payment_method" id="pmInput" value="현금">
          </div>
        </div>

        <!-- 메모 -->
        <div class="add-row" style="align-items:flex-start;padding-top:8px;padding-bottom:8px">
          <span class="add-label" style="padding-top:6px">메모</span>
          <div class="add-inputs" style="flex:1">
            <textarea name="memo" placeholder=""></textarea>
          </div>
        </div>

      </div><!-- /add-body -->
      <div class="add-foot">
        <button type="button" onclick="closeModal('addOverlay')" style="background:#555">취소</button>
        <button type="submit">확인</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════
     결제수단 필터 모달 (지출내역2.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="pmFilterOverlay" onclick="closeOnBg(event,'pmFilterOverlay')">
  <div class="filter-box">
    <div class="filter-hd">
      <span class="filter-hd-title">결제수단 필터 선택</span>
      <i class="bi bi-x-lg" style="cursor:pointer" onclick="closeModal('pmFilterOverlay')"></i>
    </div>
    <div class="filter-list">
      <div class="filter-item">
        <span>전체 선택</span>
        <input type="radio" name="pmMode" value="all" id="pmAll" onchange="pmModeChange()">
      </div>
      <div class="filter-item">
        <span>전체 해제</span>
        <input type="radio" name="pmMode" value="none" id="pmNone" checked onchange="pmModeChange()">
      </div>
      <div class="filter-section">개별 선택</div>
      <?php foreach ($ALL_PAYMENTS as $pm): ?>
      <div class="filter-item">
        <span><?=htmlspecialchars($pm)?></span>
        <input type="checkbox" class="pm-chk" value="<?=htmlspecialchars($pm)?>"
               onchange="document.getElementById('pmNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="filter-foot">
      <button onclick="closeModal('pmFilterOverlay')">취소</button>
      <button onclick="applyPMFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     카테고리 필터 모달 (지출내역3~4.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="catFilterOverlay" onclick="closeOnBg(event,'catFilterOverlay')">
  <div class="filter-box">
    <div class="filter-hd">
      <span class="filter-hd-title">카테고리 필터 선택</span>
      <i class="bi bi-chevron-down" style="cursor:pointer" onclick="closeModal('catFilterOverlay')"></i>
    </div>
    <div class="filter-list">
      <div class="filter-item">
        <span>전체 선택</span>
        <input type="radio" name="catMode" value="all" id="catAll" onchange="catModeChange()">
      </div>
      <div class="filter-item">
        <span>전체 해제</span>
        <input type="radio" name="catMode" value="none" id="catNone" checked onchange="catModeChange()">
      </div>
      <div class="filter-section">개별 선택</div>
      <div class="filter-item">
        <span>없음 (카테고리 미지정)</span>
        <input type="checkbox" class="cat-chk" value="0"
               onchange="document.getElementById('catNone').checked=false">
      </div>
      <?php foreach ($expenseCats as $cat): ?>
      <div class="filter-item">
        <span><span class="cat-arrow">›</span><?=htmlspecialchars($cat['name'])?></span>
        <input type="checkbox" class="cat-chk" value="<?=$cat['id']?>"
               onchange="document.getElementById('catNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="filter-foot">
      <button onclick="closeModal('catFilterOverlay')">취소</button>
      <button onclick="applyCatFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     수입 결제수단 필터 모달
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="incomePMFilterOverlay" onclick="closeOnBg(event,'incomePMFilterOverlay')">
  <div class="filter-box">
    <div class="filter-hd">
      <span class="filter-hd-title">결제수단 필터 선택</span>
      <i class="bi bi-x-lg" style="cursor:pointer" onclick="closeModal('incomePMFilterOverlay')"></i>
    </div>
    <div class="filter-list">
      <div class="filter-item">
        <span>전체 선택</span>
        <input type="radio" name="incomePmMode" value="all" id="incomePmAll" onchange="incomePMModeChange()">
      </div>
      <div class="filter-item">
        <span>전체 해제</span>
        <input type="radio" name="incomePmMode" value="none" id="incomePmNone" checked onchange="incomePMModeChange()">
      </div>
      <div class="filter-section">개별 선택</div>
      <?php foreach ($ALL_PAYMENTS as $pm): ?>
      <div class="filter-item">
        <span><?=htmlspecialchars($pm)?></span>
        <input type="checkbox" class="income-pm-chk" value="<?=htmlspecialchars($pm)?>"
               onchange="document.getElementById('incomePmNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="filter-foot">
      <button onclick="closeModal('incomePMFilterOverlay')">취소</button>
      <button onclick="applyIncomePMFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     수입 카테고리 필터 모달
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="incomeCatFilterOverlay" onclick="closeOnBg(event,'incomeCatFilterOverlay')">
  <div class="filter-box">
    <div class="filter-hd">
      <span class="filter-hd-title">카테고리 필터 선택</span>
      <i class="bi bi-chevron-down" style="cursor:pointer" onclick="closeModal('incomeCatFilterOverlay')"></i>
    </div>
    <div class="filter-list">
      <div class="filter-item">
        <span>전체 선택</span>
        <input type="radio" name="incomeCatMode" value="all" id="incomeCatAll" onchange="incomeCatModeChange()">
      </div>
      <div class="filter-item">
        <span>전체 해제</span>
        <input type="radio" name="incomeCatMode" value="none" id="incomeCatNone" checked onchange="incomeCatModeChange()">
      </div>
      <div class="filter-section">개별 선택</div>
      <div class="filter-item">
        <span>없음 (카테고리 미지정)</span>
        <input type="checkbox" class="income-cat-chk" value="0"
               onchange="document.getElementById('incomeCatNone').checked=false">
      </div>
      <?php foreach ($incomeCats as $cat): ?>
      <div class="filter-item">
        <span><span class="cat-arrow">›</span><?=htmlspecialchars($cat['name'])?></span>
        <input type="checkbox" class="income-cat-chk" value="<?=$cat['id']?>"
               onchange="document.getElementById('incomeCatNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="filter-foot">
      <button onclick="closeModal('incomeCatFilterOverlay')">취소</button>
      <button onclick="applyIncomeCatFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     예산 - 액션 메뉴 (4.jpg)
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="budgetMenuOverlay" onclick="closeOnBg(event,'budgetMenuOverlay')">
  <div class="budget-menu-box">
    <div class="budget-menu-hd" id="budgetMenuTitle"></div>
    <div class="budget-menu-item" onclick="budgetMenuAction('edit')">
      <i class="bi bi-pencil"></i><span>수정</span>
    </div>
    <div class="budget-menu-item" onclick="budgetMenuAction('delete')">
      <i class="bi bi-trash"></i><span>삭제</span>
    </div>
    <div class="budget-menu-item" onclick="budgetMenuAction('detail')">
      <i class="bi bi-info-circle"></i><span>상세 정보</span>
    </div>
    <button class="budget-menu-cancel" onclick="closeModal('budgetMenuOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     예산 - 추가 유형 선택 (5.jpg)
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="budgetTypeOverlay" onclick="closeOnBg(event,'budgetTypeOverlay')">
  <div class="budget-type-box">
    <div class="budget-type-hd">
      <span>예산 추가</span>
      <i class="bi bi-question-circle"></i>
    </div>
    <div class="budget-type-item" onclick="openBudgetForm('total')">전체 예산</div>
    <div class="budget-type-item" onclick="openBudgetForm('cat')">카테고리 기준</div>
    <div class="budget-type-item" onclick="openBudgetForm('pm')">결제수단 기준</div>
    <div class="budget-type-item" onclick="openBudgetForm('catpm')">카테고리 + 결제수단 기준</div>
    <button class="budget-type-cancel" onclick="closeModal('budgetTypeOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     예산 - 폼 모달 (6.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="budgetFormOverlay" onclick="closeOnBg(event,'budgetFormOverlay')">
  <div class="budget-form-box">
    <div class="budget-form-hd">
      <span>예산 추가</span>
      <i class="bi bi-list-ul"></i>
    </div>
    <div class="budget-form-body">
      <input type="hidden" id="bfEditId" value="">
      <input type="hidden" id="bfBasisType" value="total">

      <div class="budget-form-row">
        <div class="budget-form-label">예산 이름</div>
        <input type="text" class="budget-form-input" id="bfName" value="전체 예산" maxlength="100">
      </div>

      <!-- 카테고리 선택 (cat / catpm) -->
      <div class="budget-form-row" id="bfCatRow" style="display:none">
        <div class="budget-form-label">카테고리</div>
        <button type="button" class="budget-form-input" style="cursor:pointer;color:#555"
                onclick="openBudgetCatSheet()">카테고리 선택 ›</button>
        <div id="bfCatSummary" style="font-size:12px;color:#888;margin-top:4px"></div>
        <input type="hidden" id="bfCatIds" value="">
      </div>

      <!-- 결제수단 선택 (pm / catpm) -->
      <div class="budget-form-row" id="bfPMRow" style="display:none">
        <div class="budget-form-label">결제수단</div>
        <button type="button" class="budget-form-input" style="cursor:pointer;color:#555"
                onclick="openBudgetPMSheet()">결제수단 선택 ›</button>
        <div id="bfPMSummary" style="font-size:12px;color:#888;margin-top:4px"></div>
        <input type="hidden" id="bfPMs" value="">
      </div>

      <div class="budget-form-row">
        <div class="budget-form-label">예산 금액</div>
        <input type="number" class="budget-form-input" id="bfAmount" placeholder="금액을 입력해주세요" min="1">
      </div>
    </div>
    <div class="budget-form-foot">
      <button onclick="closeModal('budgetFormOverlay')">취소</button>
      <button onclick="submitBudgetForm()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     예산 - 카테고리 선택 시트 (7.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="budgetCatSheetOverlay" onclick="closeOnBg(event,'budgetCatSheetOverlay')">
  <div class="budget-sel-box">
    <div class="budget-sel-hd">
      <span>예산에 포함 할 카테고리</span>
      <i class="bi bi-chevron-down" onclick="closeModal('budgetCatSheetOverlay')" style="cursor:pointer"></i>
    </div>
    <div class="budget-sel-list">
      <div class="budget-sel-item">
        <span>전체 선택</span>
        <input type="radio" name="bCatMode" value="all" id="bCatAll" onchange="bCatModeChange()">
      </div>
      <div class="budget-sel-item">
        <span>전체 해제</span>
        <input type="radio" name="bCatMode" value="none" id="bCatNone" checked onchange="bCatModeChange()">
      </div>
      <div class="budget-sel-section">개별 선택</div>
      <div class="budget-sel-item">
        <span>없음</span>
        <input type="checkbox" class="b-cat-chk" value="0"
               onchange="document.getElementById('bCatNone').checked=false">
      </div>
      <?php foreach ($expenseCats as $cat): ?>
      <div class="budget-sel-item">
        <span><span class="cat-arrow">›</span><?=htmlspecialchars($cat['name'])?></span>
        <input type="checkbox" class="b-cat-chk" value="<?=$cat['id']?>"
               onchange="document.getElementById('bCatNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="budget-sel-foot">
      <button onclick="closeModal('budgetCatSheetOverlay')">취소</button>
      <button onclick="applyBudgetCatSheet()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     예산 - 결제수단 선택 시트 (8.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="budgetPMSheetOverlay" onclick="closeOnBg(event,'budgetPMSheetOverlay')">
  <div class="budget-sel-box">
    <div class="budget-sel-hd">
      <span>예산에 포함 할 결제수단</span>
      <i class="bi bi-chevron-down" onclick="closeModal('budgetPMSheetOverlay')" style="cursor:pointer"></i>
    </div>
    <div class="budget-sel-list">
      <div class="budget-sel-item">
        <span>전체 선택</span>
        <input type="radio" name="bPMMode" value="all" id="bPMAll" onchange="bPMModeChange()">
      </div>
      <div class="budget-sel-item">
        <span>전체 해제</span>
        <input type="radio" name="bPMMode" value="none" id="bPMNone" checked onchange="bPMModeChange()">
      </div>
      <div class="budget-sel-section">개별 선택</div>
      <?php foreach ($ALL_PAYMENTS as $pm): ?>
      <div class="budget-sel-item">
        <span><?=htmlspecialchars($pm)?></span>
        <input type="checkbox" class="b-pm-chk" value="<?=htmlspecialchars($pm)?>"
               onchange="document.getElementById('bPMNone').checked=false">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="budget-sel-foot">
      <button onclick="closeModal('budgetPMSheetOverlay')">취소</button>
      <button onclick="applyBudgetPMSheet()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료통계 - 통계 종류 선택 (하나.jpg)
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="statsTypeOverlay" onclick="closeOnBg(event,'statsTypeOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">통계 종류 선택</div>
    <?php foreach(['ep'=>['지출 기간 통계','기간 단위 지출 금액 통계'],'ec'=>['지출 분류 통계','카테고리나 카드별 지출 금액 통계'],'ip'=>['수입 기간 통계','기간 단위 수입 금액 통계'],'ic'=>['수입 분류 통계','카테고리나 카드별 수입 금액 통계'],'cb'=>['합산 통계','수입과 지출의 합산 통계']] as $k=>$v): ?>
    <div class="stats-modal-item" onclick="goStats('stype','<?=$k?>')">
      <div>
        <div class="stats-modal-item-name"><?=$v[0]?></div>
        <div class="stats-modal-item-desc"><?=$v[1]?></div>
      </div>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <?php endforeach; ?>
    <button class="stats-modal-cancel" onclick="closeModal('statsTypeOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료통계 - 단위 선택 (일곱.jpg)
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="statsUnitOverlay" onclick="closeOnBg(event,'statsUnitOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">통계 단위 선택</div>
    <?php foreach(['all'=>'전체 기간','y'=>'연별 통계','m'=>'월별 통계','w'=>'주별 통계','r365'=>'최근 365일','r180'=>'최근 180일','r30'=>'최근 30일','r7'=>'최근 7일','custom'=>'사용자 지정'] as $k=>$v): ?>
    <div class="stats-modal-item" onclick="<?=$k==='custom'?'toggleCustomDate()':'goStats(\'sunit\',\''.$k.'\')'?>">
      <span class="stats-modal-item-name"><?=$v?></span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <?php endforeach; ?>
    <div id="customDateRow" style="display:<?=$sunit==='custom'?'block':'none'?>;padding:8px 12px;background:#f8f8f8;border-radius:8px;margin:4px 0">
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="date" id="scStartInput" value="<?=htmlspecialchars($scstart)?>" style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:13px">
        <span style="font-size:12px;color:#999">~</span>
        <input type="date" id="scEndInput" value="<?=htmlspecialchars($scend)?>" style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:13px">
        <button onclick="applyCustomDate()" style="padding:6px 14px;background:#333;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer">적용</button>
      </div>
    </div>
    <button class="stats-modal-cancel" onclick="closeModal('statsUnitOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료통계 - 분류 기준 선택
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="statsClassifyOverlay" onclick="closeOnBg(event,'statsClassifyOverlay')">
  <div class="stats-modal-box">
    <div class="stats-modal-hd">분류 기준 선택</div>
    <div class="stats-modal-item" onclick="goStats('sclassify','cat')">
      <span class="stats-modal-item-name">카테고리별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <div class="stats-modal-item" onclick="goStats('sclassify','pm')">
      <span class="stats-modal-item-name">결제수단별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <div class="stats-modal-item" onclick="goStats('sclassify','sub')">
      <span class="stats-modal-item-name">세부분류별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <div class="stats-modal-item" onclick="goStats('sclassify','dow')">
      <span class="stats-modal-item-name">요일별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <div class="stats-modal-item" onclick="goStats('sclassify','hour')">
      <span class="stats-modal-item-name">시간대별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <div class="stats-modal-item" onclick="goStats('sclassify','desc')">
      <span class="stats-modal-item-name">내역별</span>
      <input type="radio" class="stats-modal-radio" readonly>
    </div>
    <button class="stats-modal-cancel" onclick="closeModal('statsClassifyOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     자료통계 - 커스텀 필터 (여덟.jpg)
══════════════════════════════════════ -->
<div class="overlay filter-sheet" id="statsFilterOverlay" onclick="closeOnBg(event,'statsFilterOverlay')">
  <div class="stats-filter-box">
    <div class="stats-filter-hd">통계 필터 선택</div>
    <div class="stats-filter-body">

      <div class="stats-filter-row">
        <div class="stats-filter-label">키워드 필터 입력</div>
        <input type="text" class="stats-filter-input" id="sf_kw" placeholder="입력"
               value="<?=htmlspecialchars($skw)?>">
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">카테고리 필터 선택</div>
        <select class="stats-filter-input" id="sf_cat">
          <option value="0">선택 (전체)</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?=$cat['id']?>" <?=$scat==$cat['id']?'selected':''?>>
            <?=htmlspecialchars($cat['name'])?> (<?=$cat['type']==='expense'?'지출':'수입'?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">결제수단 필터 선택</div>
        <select class="stats-filter-input" id="sf_pm">
          <option value="">선택 (전체)</option>
          <?php foreach ($ALL_PAYMENTS as $pm): ?>
          <option value="<?=htmlspecialchars($pm)?>" <?=$spm2===$pm?'selected':''?>>
            <?=htmlspecialchars($pm)?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="stats-filter-row">
        <div class="stats-filter-label">요일 필터 선택</div>
        <select class="stats-filter-input" id="sf_dow">
          <option value="-1">선택 (전체)</option>
          <?php foreach(['월요일','화요일','수요일','목요일','금요일','토요일','일요일'] as $i=>$dn): ?>
          <option value="<?=$i?>" <?=$sdow===$i?'selected':''?>><?=$dn?></option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>
    <div class="stats-filter-foot">
      <button onclick="resetStatsFilter()">초기화</button>
      <button onclick="applyStatsFilter()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     JavaScript
══════════════════════════════════════ -->
<script>
const YM       = '<?=addslashes($yearMonth)?>';
const TODAY    = '<?=date('Y-m-d')?>';
const BTYPE    = '<?=$btype?>';
const B_PREV   = '<?=addslashes($bPrevPeriod)?>';
const B_NEXT   = '<?=addslashes($bNextPeriod)?>';
const B_YEAR   = <?=isset($bYear)?(int)$bYear:date('Y')?>;
let   currentTab = '<?=$activeTab?>';

/* ── 탭 ── */
function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
  document.querySelector(`.tab[onclick="switchTab('${tab}')"]`).classList.add('active');
  document.getElementById('tab-'+tab).classList.add('active');
  history.replaceState(null,'',`?ym=${YM}&tab=${tab}`);
  window.scrollTo(0, 0);
  if (tab === 'chart') renderChart();
}

function goMonth(ym) {
  const tab = new URLSearchParams(location.search).get('tab') || 'expense';
  location.href = `?ym=${ym}&tab=${tab}`;
}

/* ── 날짜 토글 ── */
function toggleDay(el) {
  const items = el.nextElementSibling;
  if (!items || !items.classList.contains('tx-items')) return;
  items.style.display = items.style.display === 'block' ? 'none' : 'block';
}

/* ── 달력 토글 ── */
let calVisible = false;
function toggleCalendar() {
  calVisible = !calVisible;
  document.getElementById('calendarView').style.display = calVisible ? 'block' : 'none';
  document.getElementById('listView').style.display     = calVisible ? 'none'  : 'block';
  document.getElementById('btnCal').textContent = calVisible ? '목록 보기' : '달력 보기';
  document.getElementById('btnCal').classList.toggle('on', calVisible);
}

window.calTxData       = <?= json_encode($calTxDataArr,       JSON_UNESCAPED_UNICODE) ?>;
window.incomeCalTxData = <?= json_encode($incomeCalTxDataArr, JSON_UNESCAPED_UNICODE) ?>;

function calDayClick(date) {
  if (!date) return;
  const txs = window.calTxData[date];
  if (txs && txs.length > 0) {
    openCalDaySheet(date, txs, 'expense');
  } else {
    openAdd();
    document.getElementById('addForm').querySelector('[name=tx_date]').value = date;
  }
}

/* ── 모달 ── */
function openAdd() {
  if (currentTab === 'budget') {
    document.getElementById('budgetTypeOverlay').classList.add('show');
    return;
  }
  // 이전 수정/복사 상태 초기화
  const form = document.getElementById('addForm');
  form.dataset.editId = '';
  form.dataset.isCopy = '0';
  form.reset();
  // 날짜를 오늘로, 결제수단 기본값 복원
  const today = new Date();
  const yyyy = today.getFullYear();
  const mm   = String(today.getMonth() + 1).padStart(2, '0');
  const dd   = String(today.getDate()).padStart(2, '0');
  form.querySelector('[name=tx_date]').value = `${yyyy}-${mm}-${dd}`;
  setPM('현금');
  const submitBtn = form.querySelector('button[type=submit]');
  submitBtn.textContent = '확인';
  submitBtn.disabled = false;

  // 신규 등록 시: 구분 토글 숨김 (현재 탭이 지출/수입을 결정)
  document.getElementById('typeRow').style.display = 'none';

  document.getElementById('addOverlay').classList.add('show');
  setAddType(currentTab === 'income' ? 'income' : 'expense');
}
/* ── 날짜 클릭 → 해당 날짜로 등록창 열기 ── */
function openAddOnDate(date, e) {
  e.stopPropagation(); // toggleDay 동작 막기
  openAdd();
  document.getElementById('addForm').querySelector('[name=tx_date]').value = date;
}

function openSideDrawer() {
  document.getElementById('sideDrawer').classList.add('show');
}
function closeSideDrawer(e) {
  const drawer = document.querySelector('.side-drawer');
  if (!drawer.contains(e.target)) {
    document.getElementById('sideDrawer').classList.remove('show');
  }
}
function closeModal(id) {
  document.getElementById(id).classList.remove('show');
  // 모달 닫을 때 포커스 해제 → 버튼 focus 잔류 방지
  if (document.activeElement) document.activeElement.blur();
}
function closeOnBg(e, id) {
  if (e.target.id === id) closeModal(id);
}

/* ── 지출 등록 폼 ── */
let currentAddType = 'expense';

function setAddType(type) {
  currentAddType = type;
  document.getElementById('addTitle').textContent  = type==='expense' ? '지출내역' : '수입내역';
  document.getElementById('typeExpense').className = type==='expense' ? 'sel' : '';
  document.getElementById('typeIncome').className  = type==='income'  ? 'sel' : '';
  // 카테고리 필터링
  document.querySelectorAll('#addCatSelect option[data-type]').forEach(opt => {
    opt.style.display = opt.dataset.type === type ? '' : 'none';
  });
  document.getElementById('addCatSelect').value = '';
  // 날짜 레이블
  document.querySelector('.add-row:nth-child(2) .add-label').textContent =
    type === 'expense' ? '지출일' : '수입일';
  document.querySelector('.add-row:nth-child(3) .add-label').textContent =
    type === 'expense' ? '지출금액' : '수입금액';
  document.getElementById('descLabel').textContent =
    type === 'expense' ? '지출내역' : '수입내역';
}

function setPM(pm) {
  document.getElementById('pmInput').value = pm;
  document.querySelectorAll('#pmBtns button').forEach(b => {
    b.className = b.textContent.trim() === pm ? 'sel' : '';
  });
}

function submitAdd(e) {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  btn.disabled = true;
  btn.textContent = '저장 중...';

  const fd = new FormData(e.target);

  // memo → description 합산
  const desc = (fd.get('description') || '').trim();
  const memo = (fd.get('memo') || '').trim();
  fd.set('description', desc + (desc && memo ? ' / ' : '') + memo);
  fd.delete('memo');
  fd.delete('tx_time');

  // 지출/수입 구분 전송 (카테고리 없을 때도 탭에 표시되도록)
  fd.set('tx_type', currentAddType);

  fetch('save.php', {method:'POST', body:fd})
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(d => {
      if (d.status === 'ok') {
        location.reload();
      } else {
        alert('저장 오류: ' + (d.message || '알 수 없는 오류'));
        btn.disabled = false;
        btn.textContent = '확인';
      }
    })
    .catch(err => {
      alert('서버 연결 오류: ' + err.message);
      btn.disabled = false;
      btn.textContent = '확인';
    });
}

/* ══ 결제수단 필터 ══ */
let activePMs    = new Set();
let pmHighlight  = false; // 버튼 진한색 여부

function openFilter(type) {
  const id = type === 'payment' ? 'pmFilterOverlay' : 'catFilterOverlay';
  document.getElementById(id).classList.add('show');
}

function pmModeChange() {
  const all  = document.getElementById('pmAll').checked;
  const none = document.getElementById('pmNone').checked;
  document.querySelectorAll('.pm-chk').forEach(chk => {
    if (all)  { chk.checked = true; }
    if (none) { chk.checked = false; }
  });
}

function applyPMFilter() {
  const all  = document.getElementById('pmAll').checked;
  const none = document.getElementById('pmNone').checked;

  if (all) {
    activePMs   = new Set();   // 전체 표시
    pmHighlight = true;        // 전체선택 → 진한색 유지
  } else if (none) {
    activePMs   = new Set();   // 전체 표시
    pmHighlight = false;       // 전체해제 → 일반색
  } else {
    activePMs = new Set();
    document.querySelectorAll('.pm-chk:checked').forEach(chk => activePMs.add(chk.value));
    pmHighlight = activePMs.size > 0; // 개별 선택 있으면 진한색
  }

  closeModal('pmFilterOverlay');
  document.getElementById('btnPM').classList.toggle('active-filter', pmHighlight);
  applyFilters();
}

/* ══ 카테고리 필터 ══ */
let activeCats = new Set();

function catModeChange() {
  const all  = document.getElementById('catAll').checked;
  const none = document.getElementById('catNone').checked;
  document.querySelectorAll('.cat-chk').forEach(chk => {
    if (all)  chk.checked = true;
    if (none) chk.checked = false;
  });
}

let catHighlight = false;

function applyCatFilter() {
  const all  = document.getElementById('catAll').checked;
  const none = document.getElementById('catNone').checked;

  if (all) {
    activeCats   = new Set();
    catHighlight = true;        // 전체선택 → 진한색 유지
  } else if (none) {
    activeCats   = new Set();
    catHighlight = false;       // 전체해제 → 일반색
  } else {
    activeCats = new Set();
    document.querySelectorAll('.cat-chk:checked').forEach(chk => activeCats.add(chk.value));
    catHighlight = activeCats.size > 0;
  }

  closeModal('catFilterOverlay');
  document.getElementById('btnCat').classList.toggle('active-filter', catHighlight);
  applyFilters();
}

/* ══ 필터 적용 ══ */
function applyFilters() {
  const showAll = activePMs.size === 0 && activeCats.size === 0;

  document.querySelectorAll('.day-group').forEach(group => {
    const rows = group.querySelectorAll('.tx-row');
    let visible = 0, total = 0;

    rows.forEach(row => {
      const pm  = row.dataset.pm;
      const cat = row.dataset.cat;
      const amt = parseInt(row.dataset.amt) || 0;

      const pmOk  = activePMs.size === 0 || activePMs.has(pm);
      const catOk = activeCats.size === 0 || activeCats.has(cat);

      if (pmOk && catOk) {
        row.style.display = '';
        visible++;
        total += amt;
      } else {
        row.style.display = 'none';
      }
    });

    // 날짜 행 업데이트
    const badge = group.querySelector('.date-badge');
    const dtotal = group.querySelector('.date-total');
    const hasTx  = visible > 0;
    badge.classList.toggle('has', hasTx);
    dtotal.classList.toggle('has', hasTx);
    dtotal.textContent = hasTx ? window.CURRENCY.format(total) : window.CURRENCY.format(0);

    // 거래 없는 날짜 숨김 여부 (필터 활성 시 내역 있는 날만 표시)
    if (!showAll && !hasTx && rows.length > 0) {
      group.style.display = 'none';
    } else {
      group.style.display = '';
    }
  });
}

/* ══ 수입내역 필터 ══ */
let activeIncomePMs  = new Set();
let activeIncomeCats = new Set();
let incomePMHighlight  = false;
let incomeCatHighlight = false;
let incomeCalVisible   = false;

function openIncomeFilter(type) {
  const id = type === 'payment' ? 'incomePMFilterOverlay' : 'incomeCatFilterOverlay';
  document.getElementById(id).classList.add('show');
}

function toggleIncomeCal() {
  incomeCalVisible = !incomeCalVisible;
  document.getElementById('incomeCalView').style.display  = incomeCalVisible ? 'block' : 'none';
  document.getElementById('incomeListView').style.display = incomeCalVisible ? 'none'  : 'block';
  document.getElementById('btnIncomeCal').textContent = incomeCalVisible ? '목록 보기' : '달력 보기';
  document.getElementById('btnIncomeCal').classList.toggle('on', incomeCalVisible);
}

function incomeCalDayClick(date) {
  if (!date) return;
  const txs = window.incomeCalTxData[date];
  if (txs && txs.length > 0) {
    openCalDaySheet(date, txs, 'income');
  } else {
    openAdd();
    document.getElementById('addForm').querySelector('[name=tx_date]').value = date;
  }
}

function openCalDaySheet(date, txs, type) {
  const [y, m, d] = date.split('-');
  document.getElementById('calDaySheetTitle').textContent =
    parseInt(y) + '년 ' + parseInt(m) + '월 ' + parseInt(d) + '일';
  const list = document.getElementById('calDaySheetList');
  list.innerHTML = '';
  txs.forEach(function(tx) {
    const row = document.createElement('div');
    row.className = 'cal-day-tx-row';
    row.dataset.id      = tx.id;
    row.dataset.amt     = tx.amt;
    row.dataset.pm      = tx.pm;
    row.dataset.cat     = tx.cat;
    row.dataset.catName = tx.catName;
    row.dataset.catType = tx.catType;
    row.dataset.desc    = tx.desc;
    row.dataset.txDate  = tx.txDate;
    row.dataset.created = tx.created;
    row.dataset.user    = tx.userId;
    row.onclick = function() {
      closeModal('calDaySheetOverlay');
      openTxMenu(row);
    };
    const color = type === 'income' ? '#339af0' : '#e53935';
    const sign  = type === 'income' ? '+' : '-';
    const label = tx.catName
      ? (tx.catName + (tx.desc ? ' · ' + tx.desc : ''))
      : (tx.desc || '-');
    row.innerHTML =
      '<span class="cal-day-tx-pm">' + tx.pm + '</span>' +
      '<span class="cal-day-tx-desc">' + label + '</span>' +
      '<span class="cal-day-tx-amt" style="color:' + color + '">' +
        sign + '₩' + tx.amt.toLocaleString('ko-KR') + '</span>';
    list.appendChild(row);
  });
  document.getElementById('calDaySheetAddBtn').onclick = function() {
    closeModal('calDaySheetOverlay');
    openAdd();
    document.getElementById('addForm').querySelector('[name=tx_date]').value = date;
  };
  document.getElementById('calDaySheetOverlay').classList.add('show');
}

function incomePMModeChange() {
  const all  = document.getElementById('incomePmAll').checked;
  const none = document.getElementById('incomePmNone').checked;
  document.querySelectorAll('.income-pm-chk').forEach(chk => {
    if (all)  chk.checked = true;
    if (none) chk.checked = false;
  });
}

function applyIncomePMFilter() {
  const all  = document.getElementById('incomePmAll').checked;
  const none = document.getElementById('incomePmNone').checked;
  if (all) {
    activeIncomePMs  = new Set();
    incomePMHighlight = true;
  } else if (none) {
    activeIncomePMs  = new Set();
    incomePMHighlight = false;
  } else {
    activeIncomePMs = new Set();
    document.querySelectorAll('.income-pm-chk:checked').forEach(chk => activeIncomePMs.add(chk.value));
    incomePMHighlight = activeIncomePMs.size > 0;
  }
  closeModal('incomePMFilterOverlay');
  document.getElementById('btnIncomePM').classList.toggle('active-filter', incomePMHighlight);
  applyIncomeFilters();
}

function incomeCatModeChange() {
  const all  = document.getElementById('incomeCatAll').checked;
  const none = document.getElementById('incomeCatNone').checked;
  document.querySelectorAll('.income-cat-chk').forEach(chk => {
    if (all)  chk.checked = true;
    if (none) chk.checked = false;
  });
}

function applyIncomeCatFilter() {
  const all  = document.getElementById('incomeCatAll').checked;
  const none = document.getElementById('incomeCatNone').checked;
  if (all) {
    activeIncomeCats  = new Set();
    incomeCatHighlight = true;
  } else if (none) {
    activeIncomeCats  = new Set();
    incomeCatHighlight = false;
  } else {
    activeIncomeCats = new Set();
    document.querySelectorAll('.income-cat-chk:checked').forEach(chk => activeIncomeCats.add(chk.value));
    incomeCatHighlight = activeIncomeCats.size > 0;
  }
  closeModal('incomeCatFilterOverlay');
  document.getElementById('btnIncomeCat').classList.toggle('active-filter', incomeCatHighlight);
  applyIncomeFilters();
}

function applyIncomeFilters() {
  document.querySelectorAll('.income-filter-row').forEach(row => {
    const pm  = row.dataset.pm;
    const cat = row.dataset.cat;
    const pmOk  = activeIncomePMs.size === 0  || activeIncomePMs.has(pm);
    const catOk = activeIncomeCats.size === 0 || activeIncomeCats.has(cat);
    row.style.display = (pmOk && catOk) ? '' : 'none';
  });
}

/* ══ 자료차트 renderChart ══ */
let _cChartInst = null;
function renderChart() {
  const CD = <?=$cChartDataJson?>;
  const PIE_COLORS = ['#b8e000','#ffd43b','#63e6be','#ff8787','#74c0fc','#da77f2','#ffa94d','#a9e34b','#f783ac','#4dabf7','#69db7c','#cc5de8'];
  const BAR_EXP = '#f08080';
  const BAR_INC = '#74c0fc';

  if (_cChartInst) { _cChartInst.destroy(); _cChartInst = null; }

  if (CD.type==='ec'||CD.type==='ic') {
    const ctx = document.getElementById('cPieChart');
    if (!ctx) return;
    _cChartInst = new Chart(ctx, {
      type:'doughnut',
      data:{
        labels: CD.labels,
        datasets:[{data:CD.values, backgroundColor:PIE_COLORS, borderColor:'#fff', borderWidth:2, hoverOffset:8}]
      },
      options:{
        cutout:'60%',
        plugins:{
          legend:{display:false},
          tooltip:{
            callbacks:{
              label(c){ const t=c.dataset.data.reduce((a,b)=>a+b,0); const p=t>0?(c.parsed/t*100).toFixed(2):0; return ' '+window.CURRENCY.format(c.parsed)+' ('+p+'%)'; },
              title(c){ return c[0].label; }
            },
            backgroundColor:'rgba(50,50,50,0.85)',bodyColor:'#fff',titleColor:'#fff',padding:10,cornerRadius:8
          }
        }
      }
    });
  } else {
    const ctx = document.getElementById('cBarChart');
    if (!ctx) return;
    let datasets = [];
    if (CD.type==='cb') {
      if (CD.cumul) {
        // 누적 보기: 수입·지출 각각
        datasets = [
          {label:'수입', data:CD.values2, backgroundColor:BAR_INC, borderRadius:3, minBarLength:4},
          {label:'지출', data:CD.values3, backgroundColor:BAR_EXP, borderRadius:3, minBarLength:4}
        ];
      } else {
        // 합산: 수입 - 지출 (순수익, 양수=파랑 음수=빨강)
        datasets = [{
          label:'합산(수입-지출)',
          data: CD.values,
          backgroundColor: CD.values.map(v => v >= 0 ? BAR_INC : BAR_EXP),
          borderRadius: 3
        }];
      }
    } else {
      const col = CD.type==='ip' ? BAR_INC : BAR_EXP;
      datasets = [{label: CD.type==='ip'?'수입':'지출', data:CD.values, backgroundColor:col, borderRadius:3}];
      if (CD.avg > 0) {
        datasets.push({label:CD.avgLabel||'평균', type:'line', data:CD.labels.map(()=>CD.avg),
          borderColor:'#888', borderDash:[4,4], borderWidth:1.5, pointRadius:0, fill:false});
      }
    }
    _cChartInst = new Chart(ctx, {
      type:'bar',
      data:{labels:CD.labels, datasets},
      options:{
        responsive:true,
        plugins:{
          legend:{display: CD.type==='cb' && CD.cumul},
          tooltip:{
            callbacks:{label(c){ return ' '+window.CURRENCY.format(Number(c.parsed.y)); }},
            backgroundColor:'rgba(50,50,50,0.85)',bodyColor:'#fff',titleColor:'#fff',padding:8,cornerRadius:6
          }
        },
        scales:{
          x:{grid:{display:false}, ticks:{font:{size:11}}},
          y:{ticks:{callback(v){return (v/10000).toFixed(1);}, font:{size:11}}, grid:{color:'#f0f0f0'}}
        }
      }
    });
  }
}
if(document.getElementById('tab-chart').classList.contains('active')) renderChart();

/* ══════════════════════════════════════
   예산 탭 JS
══════════════════════════════════════ */

/* ── 기간 이동 ── */
function switchBudgetType(bt) {
  location.href = `?ym=${YM}&tab=budget&btype=${bt}`;
}
function goBudgetPrev() {
  if (BTYPE === 'weekly')   location.href = `?ym=${YM}&tab=budget&btype=weekly&bweek=${B_PREV}`;
  else if (BTYPE === 'yearly') location.href = `?ym=${YM}&tab=budget&btype=yearly&byear=${B_YEAR-1}`;
  else                        location.href = `?ym=${B_PREV}&tab=budget&btype=monthly`;
}
function goBudgetNext() {
  if (BTYPE === 'weekly')   location.href = `?ym=${YM}&tab=budget&btype=weekly&bweek=${B_NEXT}`;
  else if (BTYPE === 'yearly') location.href = `?ym=${YM}&tab=budget&btype=yearly&byear=${B_YEAR+1}`;
  else                        location.href = `?ym=${B_NEXT}&tab=budget&btype=monthly`;
}

/* ── 예산 메뉴 ── */
let currentBudget = null;
function openBudgetMenu(id, name, uid) {
  currentBudget = {id, name, uid};
  document.getElementById('budgetMenuTitle').textContent = name;
  document.getElementById('budgetMenuOverlay').classList.add('show');
}
function budgetMenuAction(action) {
  if (!currentBudget) return;
  closeModal('budgetMenuOverlay');
  if (action === 'delete') {
    if (!confirm(`"${currentBudget.name}" 예산을 삭제할까요?`)) return;
    const fd = new FormData();
    fd.append('id', currentBudget.id);
    fd.append('user_id', currentBudget.uid);
    fetch('budget_delete.php', {method:'POST',body:fd})
      .then(r=>r.json())
      .then(d=>{ if(d.status==='ok') location.reload(); else alert(d.message); });
  } else if (action === 'edit') {
    // 수정: 폼 열기 (ID 세팅)
    document.getElementById('bfEditId').value = currentBudget.id;
    document.getElementById('bfName').value   = currentBudget.name;
    document.getElementById('bfAmount').value = '';
    setBudgetFormBasis('total');
    closeModal('budgetTypeOverlay');
    document.getElementById('budgetFormOverlay').classList.add('show');
  } else if (action === 'detail') {
    alert(`예산명: ${currentBudget.name}`);
  }
}

/* ── 예산 폼 열기 (유형별) ── */
function openBudgetForm(basisType) {
  closeModal('budgetTypeOverlay');
  document.getElementById('bfEditId').value = '';
  document.getElementById('bfCatIds').value = '';
  document.getElementById('bfPMs').value    = '';
  document.getElementById('bfCatSummary').textContent = '';
  document.getElementById('bfPMSummary').textContent  = '';
  document.getElementById('bfAmount').value = '';
  // 기본 이름 세팅
  const names = {total:'전체 예산', cat:'카테고리 기준', pm:'결제수단 기준', catpm:'카테고리+결제수단'};
  document.getElementById('bfName').value = names[basisType] || '전체 예산';
  setBudgetFormBasis(basisType);
  document.getElementById('budgetFormOverlay').classList.add('show');
}
function setBudgetFormBasis(bt) {
  document.getElementById('bfBasisType').value = bt;
  document.getElementById('bfCatRow').style.display = (bt==='cat'||bt==='catpm') ? '' : 'none';
  document.getElementById('bfPMRow').style.display  = (bt==='pm' ||bt==='catpm') ? '' : 'none';
}

/* ── 예산 카테고리 시트 ── */
function openBudgetCatSheet() {
  document.getElementById('budgetCatSheetOverlay').classList.add('show');
}
function bCatModeChange() {
  const all  = document.getElementById('bCatAll').checked;
  const none = document.getElementById('bCatNone').checked;
  document.querySelectorAll('.b-cat-chk').forEach(c => {
    if (all)  c.checked = true;
    if (none) c.checked = false;
  });
}
function applyBudgetCatSheet() {
  const checked = [...document.querySelectorAll('.b-cat-chk:checked')];
  if (document.getElementById('bCatAll').checked) {
    document.getElementById('bfCatIds').value = '';
    document.getElementById('bfCatSummary').textContent = '전체 카테고리';
  } else {
    const ids = checked.map(c => c.value).join(',');
    document.getElementById('bfCatIds').value = ids;
    const labels = checked.map(c => c.closest('.budget-sel-item').querySelector('span').textContent.trim());
    document.getElementById('bfCatSummary').textContent = labels.join(', ') || '선택 없음';
  }
  closeModal('budgetCatSheetOverlay');
}

/* ── 예산 결제수단 시트 ── */
function openBudgetPMSheet() {
  document.getElementById('budgetPMSheetOverlay').classList.add('show');
}
function bPMModeChange() {
  const all  = document.getElementById('bPMAll').checked;
  const none = document.getElementById('bPMNone').checked;
  document.querySelectorAll('.b-pm-chk').forEach(c => {
    if (all)  c.checked = true;
    if (none) c.checked = false;
  });
}
function applyBudgetPMSheet() {
  const checked = [...document.querySelectorAll('.b-pm-chk:checked')];
  if (document.getElementById('bPMAll').checked) {
    document.getElementById('bfPMs').value = '';
    document.getElementById('bfPMSummary').textContent = '전체 결제수단';
  } else {
    const vals = checked.map(c => c.value).join(',');
    document.getElementById('bfPMs').value = vals;
    document.getElementById('bfPMSummary').textContent = checked.map(c=>c.value).join(', ') || '선택 없음';
  }
  closeModal('budgetPMSheetOverlay');
}

/* ── 예산 저장 ── */
function submitBudgetForm() {
  const name   = document.getElementById('bfName').value.trim();
  const amount = parseInt(document.getElementById('bfAmount').value) || 0;
  if (!name)   { alert('예산 이름을 입력해주세요.'); return; }
  if (amount<1){ alert('금액을 입력해주세요.'); return; }

  const fd = new FormData();
  fd.append('user_id',         1);
  fd.append('name',            name);
  fd.append('budget_type',     BTYPE);
  fd.append('limit_amount',    amount);
  fd.append('category_ids',    document.getElementById('bfCatIds').value);
  fd.append('payment_methods', document.getElementById('bfPMs').value);
  fd.append('year_month',      YM);
  const editId = document.getElementById('bfEditId').value;
  if (editId) fd.append('id', editId);

  fetch('budget_save.php', {method:'POST', body:fd})
    .then(r => r.text())
    .then(txt => {
      let d;
      try { d = JSON.parse(txt); } catch(e) {
        alert('서버 오류:\n' + txt.substring(0, 300));
        return;
      }
      if (d.status==='ok') { closeModal('budgetFormOverlay'); location.reload(); }
      else alert('저장 오류: ' + (d.message||'알 수 없음'));
    })
    .catch(err => alert('통신 오류: ' + err.message));
}

/* ══════════════════════════════════════
   자료통계 탭 JS
══════════════════════════════════════ */
const S_TYPE  = '<?=$stype?>';
const S_UNIT  = '<?=$sunit?>';
const S_CLASSIFY = '<?=$sclassify?>';
const S_CAT   = '<?=$scat?>';
const S_PM2   = '<?=addslashes($spm2)?>';
const S_KW    = '<?=addslashes($skw)?>';
const S_DOW   = '<?=$sdow?>';
const S_PREV    = '<?=addslashes($sPPrev)?>';
const S_NEXT    = '<?=addslashes($sPNext)?>';
const S_CURR    = '<?=addslashes($sPStart)?>';
const S_YEAR    = <?=isset($syear)?(int)$syear:date('Y')?>;
const S_SCSTART = '<?=addslashes($scstart)?>';
const S_SCEND   = '<?=addslashes($scend)?>';

function buildStatsUrl(overrides) {
  const p = {
    ym:       YM,
    tab:      'stats',
    stype:    S_TYPE,
    sunit:    S_UNIT,
    sclassify:S_CLASSIFY,
    scat:     S_CAT,
    spm2:     S_PM2,
    skw:      S_KW,
    sdow:     S_DOW,
  };
  if (S_UNIT === 'w')      p.sweek = S_CURR;
  if (S_UNIT === 'y')      p.syear = S_YEAR;
  if (S_UNIT === 'custom') { p.scstart = S_SCSTART; p.scend = S_SCEND; }
  Object.assign(p, overrides);
  const qs = Object.entries(p).filter(([,v])=>v!==''&&v!==null&&v!==undefined)
             .map(([k,v])=>k+'='+encodeURIComponent(v)).join('&');
  return '?' + qs;
}

function goStats(param, value) {
  closeModal('statsTypeOverlay');
  closeModal('statsUnitOverlay');
  closeModal('statsClassifyOverlay');
  const extras = {[param]: value};
  // 통계 유형 변경 시 필터 초기화
  if (param === 'stype') { extras.scat=''; extras.spm2=''; extras.skw=''; extras.sdow=''; }
  location.href = buildStatsUrl(extras);
}

function goStatsPrev() {
  const extras = {};
  if (S_UNIT==='w') extras.sweek = S_PREV;
  else if (S_UNIT==='y') extras.syear = S_YEAR - 1;
  else extras.ym = S_PREV;
  location.href = buildStatsUrl(extras);
}
function goStatsNext() {
  const extras = {};
  if (S_UNIT==='w') extras.sweek = S_NEXT;
  else if (S_UNIT==='y') extras.syear = S_YEAR + 1;
  else extras.ym = S_NEXT;
  location.href = buildStatsUrl(extras);
}

function toggleCustomDate() {
  document.querySelectorAll('#statsUnitOverlay .stats-modal-radio').forEach(r => r.checked = false);
  const row = document.getElementById('customDateRow');
  row.style.display = row.style.display === 'none' ? 'block' : 'none';
}
function applyCustomDate() {
  const s = document.getElementById('scStartInput').value;
  const e = document.getElementById('scEndInput').value;
  if (!s || !e) return alert('날짜를 입력하세요.');
  location.href = buildStatsUrl({sunit:'custom', scstart:s, scend:e});
}

function openStatsModal(which) {
  if (which === 'type')     document.getElementById('statsTypeOverlay').classList.add('show');
  else if (which==='unit')  document.getElementById('statsUnitOverlay').classList.add('show');
  else if (which==='classify') document.getElementById('statsClassifyOverlay').classList.add('show');
  else                      document.getElementById('statsFilterOverlay').classList.add('show');
}

function applyStatsFilter() {
  const kw  = document.getElementById('sf_kw').value.trim();
  const cat = document.getElementById('sf_cat').value;
  const pm  = document.getElementById('sf_pm').value;
  const dow = document.getElementById('sf_dow').value;
  closeModal('statsFilterOverlay');
  location.href = buildStatsUrl({skw:kw, scat:cat, spm2:pm, sdow:dow});
}
function resetStatsFilter() {
  closeModal('statsFilterOverlay');
  location.href = buildStatsUrl({skw:'', scat:'', spm2:'', sdow:''});
}

/* ── 자료통계 바차트 렌더 ── */
(function renderStatsChart() {
  const canvas = document.getElementById('statsBarChart');
  if (!canvas) return;
  const rows = <?php
    $chartD = [];
    foreach ($statsRows as $r) {
      if (isset($r['period'])) {
        $lbl = $r['period'];
        if ($sunit==='w' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$lbl)) $lbl=(new \DateTime($lbl))->format('m.d');
        elseif ($sunit==='m') $lbl=str_replace('-','.',$lbl);
        $val = $stype==='cb' ? ((int)$r['inc']-(int)$r['exp']) : (int)$r['total'];
        $chartD[] = ['l'=>$lbl,'v'=>$val];
      } elseif (isset($r['label'])) {
        $chartD[] = ['l'=>$r['label'],'v'=>(int)$r['total']];
      }
    }
    echo json_encode(array_reverse($chartD), JSON_UNESCAPED_UNICODE);
  ?>;
  if (!rows.length) return;
  const _minusColor = getComputedStyle(document.documentElement).getPropertyValue('--color-minus').trim() || '#e53935';
  const colors = rows.map(r => r.v >= 0 ? '#555555' : _minusColor);
  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: rows.map(r=>r.l),
      datasets:[{ data: rows.map(r=>Math.abs(r.v)), backgroundColor: colors, borderRadius:4, borderSkipped:false }]
    },
    options:{
      responsive:true, plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>window.CURRENCY.format(c.raw)}}},
      scales:{y:{ticks:{callback:v=>window.CURRENCY.format(v)},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}
    }
  });
})();

/* ══ sticky-controls top 값을 실측으로 계산 ══ */
(function setStickyTop() {
  const hd   = document.querySelector('.hd');
  const tabs = document.querySelector('.tabs');
  if (!hd || !tabs) return;
  const top = hd.getBoundingClientRect().height + tabs.getBoundingClientRect().height;
  document.querySelectorAll('.sticky-controls').forEach(el => {
    el.style.top = top + 'px';
  });
})();
</script>

<!-- ══════════════════════════════════════
     관리 메뉴 모달 (image_11.png)
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="menuOverlay" onclick="closeOnBg(event,'menuOverlay')">
  <div class="menu-box">
    <div class="menu-hd">
      <span class="menu-hd-amt" id="menuAmt"></span>
      <i class="bi bi-search"></i>
    </div>

    <div class="menu-item" onclick="menuAction('detail')">
      <i class="bi bi-info-circle"></i>
      <span>상세 정보</span>
    </div>
    <div class="menu-item" onclick="menuAction('edit')">
      <i class="bi bi-pencil"></i>
      <span>수정</span>
    </div>
    <div class="menu-item" onclick="menuAction('delete')">
      <i class="bi bi-trash"></i>
      <span>삭제</span>
    </div>
    <div class="menu-item" onclick="menuAction('copy')">
      <i class="bi bi-copy"></i>
      <span>복사</span>
    </div>
    <div class="menu-item" onclick="menuAction('multi')">
      <i class="bi bi-check2"></i>
      <span>다중 선택</span>
    </div>

    <div class="menu-section">추가 기능</div>
    <div class="menu-item" onclick="menuAction('expand')">
      <i class="bi bi-plus-lg"></i>
      <span>메뉴 펼치기</span>
    </div>

    <button class="menu-cancel" onclick="closeModal('menuOverlay')">취소</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     달력 날짜 상세 모달
══════════════════════════════════════ -->
<div class="overlay menu-sheet" id="calDaySheetOverlay" onclick="closeOnBg(event,'calDaySheetOverlay')">
  <div class="menu-box">
    <div class="menu-hd" style="justify-content:center">
      <span id="calDaySheetTitle" style="font-size:16px;font-weight:700"></span>
    </div>
    <div id="calDaySheetList" style="max-height:50vh;overflow-y:auto"></div>
    <button class="cal-day-add-btn" id="calDaySheetAddBtn">+ 내역 추가</button>
    <button class="menu-cancel" onclick="closeModal('calDaySheetOverlay')">닫기</button>
  </div>
</div>

<!-- ══════════════════════════════════════
     상세 정보 모달 (image_12.png)
══════════════════════════════════════ -->
<div class="overlay detail-sheet" id="detailOverlay" onclick="closeOnBg(event,'detailOverlay')">
  <div class="detail-box">
    <div class="detail-hd">
      <span class="detail-hd-amt" id="detailAmt"></span>
      <i class="bi bi-trash" id="detailDelBtn" onclick="menuAction('delete')"></i>
    </div>
    <div class="detail-body">
      <div class="detail-row" id="detailDate"></div>
      <div class="detail-row" id="detailDay"></div>
      <div class="detail-row" id="detailAmtType"></div>
      <div class="detail-row" id="detailCat"></div>
      <div class="detail-row" id="detailPM"></div>
      <div class="detail-row muted" id="detailMemo"></div>
    </div>
    <div class="detail-foot">
      <button onclick="closeModal('detailOverlay');menuAction('edit')">수정</button>
      <button onclick="closeModal('detailOverlay')">확인</button>
    </div>
  </div>
</div>

<!-- ══ 다중 선택 하단 바 ══ -->
<div id="multiBar" style="display:none;position:fixed;bottom:0;left:0;right:0;max-width:480px;margin:0 auto;background:var(--theme-primary,#3c3c3c);color:#fff;padding:14px 16px;z-index:200;display:none;justify-content:space-between;align-items:center;">
  <span id="multiCount">0개 선택</span>
  <div style="display:flex;gap:8px;">
    <button onclick="deleteSelected()" style="background:#e53935;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;">삭제</button>
    <button onclick="cancelMulti()" style="background:#555;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;">취소</button>
  </div>
</div>

<script>
/* ══ 현재 선택된 거래 ══ */
let currentTx = null;

/* ══ 거래 행 클릭 → 관리 메뉴 ══ */
function openTxMenu(el) {
  currentTx = {
    id:       el.dataset.id,
    amt:      el.dataset.amt,
    pm:       el.dataset.pm,
    cat:      el.dataset.cat,
    catName:  el.dataset.catName,
    catType:  el.dataset.catType,
    desc:     el.dataset.desc,
    txDate:   el.dataset.txDate,
    created:  el.dataset.created,
    userId:   el.dataset.user,
  };
  document.getElementById('menuAmt').textContent = window.CURRENCY.format(parseInt(currentTx.amt));
  document.getElementById('menuOverlay').classList.add('show');
}

/* ══ 메뉴 동작 ══ */
function menuAction(action) {
  if (!currentTx) return;
  closeModal('menuOverlay');

  if (action === 'detail') {
    showDetail();
  } else if (action === 'edit') {
    openEditForm();
  } else if (action === 'delete') {
    closeModal('detailOverlay');
    if (confirm('이 내역을 삭제할까요?')) {
      const fd = new FormData();
      fd.append('id', currentTx.id);
      fd.append('user_id', currentTx.userId);
      fetch('delete.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => { if (d.status==='ok') location.reload(); else alert(d.message); });
    }
  } else if (action === 'copy') {
    openEditForm(true); // 복사 = 신규 저장
  } else if (action === 'multi') {
    startMultiSelect();
  }
}

/* ══ 상세 정보 표시 ══ */
const KO_DAYS = ['일요일','월요일','화요일','수요일','목요일','금요일','토요일'];

function showDetail() {
  const tx = currentTx;

  // 금액 헤더
  document.getElementById('detailAmt').textContent = window.CURRENCY.format(parseInt(tx.amt));

  // 날짜 포맷: "2026년 3월 16일 오전 10:55"
  let dateStr = tx.txDate; // YYYY-MM-DD
  let timeStr = '';
  if (tx.created) {
    const cr = tx.created; // "2026-03-16 10:55:00"
    const tPart = cr.split(' ')[1] || '';
    if (tPart) {
      const [h, m] = tPart.split(':').map(Number);
      const ampm = h < 12 ? '오전' : '오후';
      const h12  = h === 0 ? 12 : h > 12 ? h-12 : h;
      timeStr = ` ${ampm} ${h12}:${String(m).padStart(2,'0')}`;
    }
  }
  const [y, mo, d] = tx.txDate.split('-');
  document.getElementById('detailDate').textContent =
    `${y}년 ${parseInt(mo)}월 ${parseInt(d)}일${timeStr}`;

  // 요일 + 오늘 여부
  const txDt = new Date(tx.txDate + 'T00:00:00');
  const today = new Date(); today.setHours(0,0,0,0);
  const isToday = txDt.getTime() === today.getTime();
  document.getElementById('detailDay').textContent =
    KO_DAYS[txDt.getDay()] + (isToday ? ', 오늘' : '');

  // 금액 + 유형
  const typeStr = tx.catType === 'income' ? '수입' : '지출';
  document.getElementById('detailAmtType').textContent =
    window.CURRENCY.format(parseInt(tx.amt)) + ' (' + typeStr + ')';

  // 카테고리
  document.getElementById('detailCat').textContent =
    tx.catName ? tx.catName + ' (분류)' : '( 카테고리 없음 )';

  // 결제수단
  document.getElementById('detailPM').textContent =
    tx.pm ? tx.pm + ' (일시불)' : '현금 (일시불)';

  // 메모
  document.getElementById('detailMemo').textContent =
    tx.desc ? tx.desc : '( 메모 없음 )';

  document.getElementById('detailOverlay').classList.add('show');
}

/* ══ 수정 폼 열기 ══ */
function openEditForm(isCopy = false) {
  const tx = currentTx;

  // 기존 add 폼 재활용
  const form = document.getElementById('addForm');
  form.dataset.editId   = isCopy ? '' : tx.id;
  form.dataset.isCopy   = isCopy ? '1' : '0';

  // 수정/복사 시: 구분 토글 표시 (지출↔수입 변경 가능)
  document.getElementById('typeRow').style.display = '';

  // 헤더 타이틀
  setAddType(tx.catType === 'income' ? 'income' : 'expense');
  document.getElementById('addTitle').textContent = isCopy ? '복사하여 추가' : '수정';

  // 값 채우기
  form.querySelector('[name=tx_date]').value        = tx.txDate;
  form.querySelector('[name=amount]').value         = tx.amt;
  form.querySelector('[name=description]').value    = tx.desc;
  form.querySelector('[name=memo]').value           = '';
  document.getElementById('addCatSelect').value     = tx.cat;
  setPM(tx.pm);

  // 확인 버튼 텍스트
  const submitBtn = form.querySelector('button[type=submit]');
  submitBtn.textContent = isCopy ? '복사 저장' : '수정 저장';
  submitBtn.disabled = false;

  document.getElementById('addOverlay').classList.add('show');
}

/* ══ 저장 함수 수정 (수정/복사 분기) ══ */
const _origSubmitAdd = submitAdd;
function submitAdd(e) {
  e.preventDefault();
  const form   = e.target;
  const editId = form.dataset.editId;
  const isCopy = form.dataset.isCopy === '1';
  const btn    = form.querySelector('button[type=submit]');

  btn.disabled = true;
  btn.textContent = '저장 중...';

  const fd = new FormData(form);
  const desc = (fd.get('description') || '').trim();
  const memo = (fd.get('memo') || '').trim();
  fd.set('description', desc + (desc && memo ? ' / ' : '') + memo);
  fd.delete('memo');
  fd.delete('tx_time');

  let url = 'save.php';
  if (editId && !isCopy) {
    fd.append('id', editId);
    url = 'update.php';
  } else {
    // 신규 저장: tx_type 명시 (누락 시 income 항목이 expense로 분류됨)
    fd.set('tx_type', currentAddType);
  }

  const savedDate = fd.get('tx_date') || '';

  fetch(url, {method:'POST', body:fd})
    .then(r => { if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(d => {
      if (d.status === 'ok') {
        // 저장/수정된 날짜를 sessionStorage에 기록 → 리로드 후 자동 펼치기
        if (savedDate) sessionStorage.setItem('expandDate', savedDate);
        // 달력 상태 기록 → 리로드 후 복원
        if (calVisible)        sessionStorage.setItem('restoreCal', 'expense');
        else if (incomeCalVisible) sessionStorage.setItem('restoreCal', 'income');
        location.reload();
      } else {
        alert('오류: ' + (d.message || '알 수 없는 오류'));
        btn.disabled = false;
        btn.textContent = editId && !isCopy ? '수정 저장' : '확인';
      }
    })
    .catch(err => {
      alert('서버 오류: ' + err.message);
      btn.disabled = false;
      btn.textContent = '확인';
    });
}

/* ══ 다중 선택 ══ */
let multiMode = false;

function startMultiSelect() {
  multiMode = true;
  document.getElementById('listView').classList.add('multi-mode');
  document.querySelectorAll('.tx-chk').forEach(c => c.style.display = 'inline-block');
  document.getElementById('multiBar').style.display = 'flex';
  document.getElementById('multiCount').textContent = '0개 선택';
}

function cancelMulti() {
  multiMode = false;
  document.getElementById('listView').classList.remove('multi-mode');
  document.querySelectorAll('.tx-chk').forEach(c => { c.checked=false; c.style.display='none'; });
  document.getElementById('multiBar').style.display = 'none';
}

function updateMultiCount() {
  const n = document.querySelectorAll('.tx-chk:checked').length;
  document.getElementById('multiCount').textContent = n + '개 선택';
}

function deleteSelected() {
  const ids = [...document.querySelectorAll('.tx-chk:checked')].map(c => c.value);
  if (ids.length === 0) { alert('선택된 항목이 없습니다.'); return; }
  if (!confirm(ids.length + '개 항목을 삭제할까요?')) return;

  Promise.all(ids.map(id => {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('user_id', '<?=$userId?>');
    return fetch('delete.php', {method:'POST', body:fd}).then(r=>r.json());
  })).then(() => location.reload());
}

// tx-row 클릭 시 다중 선택 모드면 체크박스 토글
document.addEventListener('click', function(e) {
  if (!multiMode) return;
  const row = e.target.closest('.tx-row');
  if (!row) return;
  const chk = row.querySelector('.tx-chk');
  if (chk) { chk.checked = !chk.checked; updateMultiCount(); }
}, true);

/* ══════════════════════════════════════
   자료차트 JS
══════════════════════════════════════ */
const C_TYPE     = '<?=$ctype?>';
const C_UNIT     = '<?=$cunit?>';
const C_CLASSIFY = '<?=$cclassify?>';
const C_CUMUL    = <?=$ccumul?>;
const C_PREV     = '<?=addslashes($cPPrev)?>';
const C_CURR     = '<?=addslashes($cPStart)?>';
const C_NEXT     = '<?=addslashes($cPNext)?>';
const C_YEAR     = <?=(int)$cyear?>;
const C_YM       = '<?=addslashes($cym2)?>';
const C_CSTART   = '<?=addslashes($ccstart)?>';
const C_CEND     = '<?=addslashes($ccend)?>';
const C_FCAT     = '<?=$cfcat?>';
const C_FPM      = '<?=addslashes($cfpm)?>';
const C_FKW      = '<?=addslashes($cfkw)?>';
const C_FDOW     = '<?=$cfdow?>';

function buildChartUrl(overrides) {
  const p = {tab:'chart', ctype:C_TYPE, cunit:C_UNIT, cclassify:C_CLASSIFY, ccumul:C_CUMUL,
             cfcat:C_FCAT, cfpm:C_FPM, cfkw:C_FKW, cfdow:C_FDOW};
  if (C_UNIT==='w') p.cweek = C_CURR;
  if (C_UNIT==='y') p.cyear = C_YEAR;
  if (C_UNIT==='m') p.cym   = C_YM;
  if (C_UNIT==='custom') { p.ccstart=C_CSTART; p.ccend=C_CEND; }
  Object.assign(p, overrides);
  return '?'+Object.entries(p).filter(([,v])=>v!==''&&v!==null&&v!==undefined)
    .map(([k,v])=>k+'='+encodeURIComponent(v)).join('&');
}

function goChart(param, value) {
  closeModal('chartTypeOverlay'); closeModal('chartUnitOverlay'); closeModal('chartClassifyOverlay');
  location.href = buildChartUrl({[param]:value});
}
function goChartPrev() {
  const e={};
  if (C_UNIT==='w') e.cweek=C_PREV;
  else if (C_UNIT==='y') e.cyear=C_YEAR-1;
  else e.cym=C_PREV;
  location.href=buildChartUrl(e);
}
function goChartNext() {
  const e={};
  if (C_UNIT==='w') e.cweek=C_NEXT;
  else if (C_UNIT==='y') e.cyear=C_YEAR+1;
  else e.cym=C_NEXT;
  location.href=buildChartUrl(e);
}
function toggleChartCumul() {
  location.href=buildChartUrl({ccumul: C_CUMUL?0:1});
}
function openChartModal(which) {
  if (which==='type')          document.getElementById('chartTypeOverlay').classList.add('show');
  else if (which==='unit')     document.getElementById('chartUnitOverlay').classList.add('show');
  else if (which==='classify') document.getElementById('chartClassifyOverlay').classList.add('show');
  else                         document.getElementById('chartFilterOverlay').classList.add('show');
}
function applyChartFilter() {
  const kw  = document.getElementById('cf_kw').value.trim();
  const cat = document.getElementById('cf_cat').value;
  const pm  = document.getElementById('cf_pm').value;
  const dow = document.getElementById('cf_dow').value;
  closeModal('chartFilterOverlay');
  location.href = buildChartUrl({cfkw:kw, cfcat:cat, cfpm:pm, cfdow:dow});
}
function resetChartFilter() {
  closeModal('chartFilterOverlay');
  location.href = buildChartUrl({cfkw:'', cfcat:'0', cfpm:'', cfdow:'-1'});
}
function toggleChartCustomDate() {
  const row=document.getElementById('chartCustomDateRow');
  row.style.display=row.style.display==='none'?'block':'none';
}
function applyChartCustomDate() {
  const s=document.getElementById('ccStartInput').value;
  const e=document.getElementById('ccEndInput').value;
  if (!s||!e) return alert('날짜를 입력하세요.');
  location.href=buildChartUrl({cunit:'custom',ccstart:s,ccend:e});
}

</script>
<script>
// 글자 크기 zoom: body가 존재하는 시점에 적용
(function(){
  var fs = localStorage.getItem('design_fontsize') || '보통';
  document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1';
})();
</script>

<!-- ══════════════════════════════════════
     백업과 복구 모달 CSS
══════════════════════════════════════ -->
<style>
/* ── 공통 오버레이 ── */
.bk-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 1000;
  align-items: flex-end;
  justify-content: center;
}
.bk-overlay.show { display: flex; }
.bk-dialog-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 1100;
  align-items: center;
  justify-content: center;
}
.bk-dialog-overlay.show { display: flex; }

/* ── 바텀 시트 ── */
.bk-sheet {
  width: 100%;
  max-width: 480px;
  background: #fff;
  border-radius: 12px 12px 0 0;
  overflow: hidden;
}
.bk-sheet-header {
  background: var(--theme-primary, #455A64);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  font-size: 20px;
  font-weight: 700;
}
.bk-sheet-gear {
  font-size: 22px;
  cursor: pointer;
  opacity: .9;
}
.bk-sheet-item {
  padding: 18px 20px;
  font-size: 17px;
  color: #212121;
  border-bottom: 1px solid #F0F0F0;
  cursor: pointer;
  background: #fff;
}
.bk-sheet-item:active { background: #F5F5F5; }
.bk-sheet-section {
  background: var(--theme-primary, #455A64) !important;
  color: #fff !important;
  font-weight: 600;
  cursor: default;
}
.bk-sheet-cancel {
  display: block;
  width: 100%;
  padding: 18px;
  background: var(--theme-primary, #455A64);
  color: #fff;
  text-align: center;
  font-size: 17px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  font-family: inherit;
}
.bk-sheet-cancel:active { opacity: .85; }

/* ── 다이얼로그 ── */
.bk-dialog {
  width: calc(100% - 48px);
  max-width: 400px;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
}
.bk-dialog-header {
  background: var(--theme-primary, #455A64);
  color: #fff;
  padding: 16px 20px;
  font-size: 18px;
  font-weight: 700;
}
.bk-dialog-body {
  padding: 20px;
  font-size: 16px;
  color: #333;
  line-height: 1.8;
  max-height: 60vh;
  overflow-y: auto;
}
.bk-dialog-body p { margin-bottom: 14px; }
.bk-dialog-btns {
  display: flex;
  border-top: 1px solid #F0F0F0;
}
.bk-dialog-btn {
  flex: 1;
  padding: 16px;
  border: none;
  background: var(--theme-primary, #455A64);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
}
.bk-dialog-btn.cancel {
  background: #fff;
  color: #757575;
  border-right: 1px solid #F0F0F0;
}
.bk-dialog-btn:active { opacity: .85; }

/* ── 자동 백업 토글 ── */
.bk-auto-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 0;
  font-size: 15px;
  color: #424242;
}
.bk-toggle {
  position: relative;
  width: 44px;
  height: 24px;
  flex-shrink: 0;
}
.bk-toggle input { opacity: 0; width: 0; height: 0; }
.bk-toggle-slider {
  position: absolute; inset: 0;
  background: #ccc;
  border-radius: 24px;
  cursor: pointer;
  transition: background .2s;
}
.bk-toggle-slider::before {
  content: '';
  position: absolute;
  width: 18px; height: 18px;
  left: 3px; top: 3px;
  background: #fff;
  border-radius: 50%;
  transition: transform .2s;
}
.bk-toggle input:checked + .bk-toggle-slider {
  background: var(--theme-primary, #455A64);
}
.bk-toggle input:checked + .bk-toggle-slider::before {
  transform: translateX(20px);
}
.bk-interval-select {
  margin-top: 12px;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #E0E0E0;
  border-radius: 6px;
  font-size: 15px;
  font-family: inherit;
  color: #212121;
}
.bk-last-backup {
  font-size: 13px;
  color: #9E9E9E;
  margin-top: 10px;
}

/* ── 복구 파일 목록 ── */
.bk-file-item {
  padding: 12px 16px;
  border: 1px solid #E0E0E0;
  border-radius: 6px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: background .15s;
}
.bk-file-item:active, .bk-file-item.selected { background: #E3F2FD; border-color: var(--theme-primary, #455A64); }
.bk-file-name { font-size: 14px; font-weight: 600; color: #212121; }
.bk-file-date { font-size: 12px; color: #9E9E9E; margin-top: 2px; }
</style>

<!-- ══════════════════════════════════════
     백업 바텀 시트
══════════════════════════════════════ -->
<div class="bk-overlay" id="bkSheetOverlay" onclick="if(event.target===this)closeBkSheet()">
  <div class="bk-sheet">
    <div class="bk-sheet-header">
      <span>데이터 관리</span>
      <span class="bk-sheet-gear" onclick="openAutoBackupDialog()">⚙</span>
    </div>
    <div class="bk-sheet-item" onclick="openBackupInfoDialog()">구글 계정으로 백업</div>
    <div class="bk-sheet-item" onclick="openRestoreInfoDialog()">구글 계정에서 복구</div>
    <div class="bk-sheet-item" onclick="openAutoBackupDialog()">주기적 자동 백업</div>
    <div class="bk-sheet-item bk-sheet-section">핸드폰 변경 시 주의사항</div>
    <div class="bk-sheet-item" onclick="openPhoneChangeDialog()">핸드폰 변경 시 자료 옮기는 방법</div>
    <button class="bk-sheet-cancel" onclick="closeBkSheet()">취소</button>
  </div>
</div>

<!-- ── 구글 백업 안내 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkBackupInfoOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header">데이터 관리</div>
    <div class="bk-dialog-body">
      <p>구글 드라이브는 구글에서 무료로 제공하는 클라우드 파일 저장 서비스입니다.</p>
      <p>구글 드라이브에 자료를 백업해 놓으시면 기기의 갑작스런 분실이나 고장시에도 자료를 복구하실 수 있으며 핸드폰 교체 시에는 다른 기기로 자료를 편하게 옮기실 수도 있습니다.</p>
      <p>개인의 구글 계정과 연동이 되는 것이기 때문에 안전하게 자료를 보관하실 수 있습니다.</p>
      <p>'확인'을 누르신 후 구글 계정으로 로그인 해주세요!</p>
    </div>
    <div class="bk-dialog-btns">
      <button class="bk-dialog-btn cancel" onclick="closeBkDialog('bkBackupInfoOverlay')">취소</button>
      <button class="bk-dialog-btn" onclick="startGoogleBackup()">확인</button>
    </div>
  </div>
</div>

<!-- ── 구글 복구 안내 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkRestoreInfoOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header">데이터 관리</div>
    <div class="bk-dialog-body">
      <p>구글 드라이브에 저장된 백업 파일로 가계부 데이터를 복구합니다.</p>
      <p>복구를 진행하면 현재 기기의 가계부 데이터가 백업 파일의 데이터로 교체됩니다.</p>
      <p>복구 전 현재 데이터를 먼저 백업하는 것을 권장합니다.</p>
      <p>'확인'을 누르신 후 구글 계정으로 로그인 해주세요!</p>
    </div>
    <div class="bk-dialog-btns">
      <button class="bk-dialog-btn cancel" onclick="closeBkDialog('bkRestoreInfoOverlay')">취소</button>
      <button class="bk-dialog-btn" onclick="startGoogleRestore()">확인</button>
    </div>
  </div>
</div>

<!-- ── 복구 파일 선택 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkRestorePickOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header">백업 파일 선택</div>
    <div class="bk-dialog-body" id="bkRestoreFileList">
      <p style="color:#9E9E9E;text-align:center">파일 목록 불러오는 중...</p>
    </div>
    <div class="bk-dialog-btns">
      <button class="bk-dialog-btn cancel" onclick="closeBkDialog('bkRestorePickOverlay')">취소</button>
      <button class="bk-dialog-btn" id="bkRestoreConfirmBtn" onclick="doGoogleRestore()" disabled>복구</button>
    </div>
  </div>
</div>

<!-- ── 주기적 자동 백업 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkAutoBackupOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header">주기적 자동 백업</div>
    <div class="bk-dialog-body">
      <div class="bk-auto-row">
        <span>자동 백업 사용</span>
        <label class="bk-toggle">
          <input type="checkbox" id="bkAutoToggle" onchange="saveAutoBackupSettings()">
          <span class="bk-toggle-slider"></span>
        </label>
      </div>
      <select class="bk-interval-select" id="bkIntervalSelect" onchange="saveAutoBackupSettings()">
        <option value="daily">매일</option>
        <option value="weekly">매주</option>
        <option value="monthly">매월</option>
      </select>
      <div class="bk-last-backup" id="bkLastBackupLabel">마지막 백업: 없음</div>
    </div>
    <div class="bk-dialog-btns">
      <button class="bk-dialog-btn" onclick="closeBkDialog('bkAutoBackupOverlay')">확인</button>
    </div>
  </div>
</div>

<!-- ── 핸드폰 변경 주의사항 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkPhoneChangeOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header">핸드폰 변경 시 주의사항</div>
    <div class="bk-dialog-body">
      <p>핸드폰 변경 시 그냥 바꾸시면 가계부 자료를 이어서 적으실 수 없습니다. 아래 내용을 따라서 하시면 간단히 가계부 자료를 옮기실 수 있습니다.</p>
      <p><strong>[ 이전 핸드폰에서 ]</strong><br>
      좌측 메뉴 - 백업과 복구 - 구글 계정으로 백업 - 로그인 후 백업 파일 저장</p>
      <p><strong>[ 새 핸드폰에서 ]</strong><br>
      좌측 메뉴 - 백업과 복구 - 구글 계정에서 복구 - 로그인 후 위에서 백업한 파일 선택</p>
      <p style="color:var(--theme-primary,#455A64)">http://cafe.naver.com/clevmoney/4<br>
      <span style="color:#555;font-size:14px">(위 블로그에 더 자세히 적어놨습니다!)</span></p>
    </div>
    <div class="bk-dialog-btns">
      <button class="bk-dialog-btn" onclick="closeBkDialog('bkPhoneChangeOverlay')">확인</button>
    </div>
  </div>
</div>

<!-- ── 진행 중 / 완료 다이얼로그 ── -->
<div class="bk-dialog-overlay" id="bkProgressOverlay">
  <div class="bk-dialog">
    <div class="bk-dialog-header" id="bkProgressTitle">백업 중...</div>
    <div class="bk-dialog-body" id="bkProgressBody" style="text-align:center;padding:32px 20px;">
      <p id="bkProgressMsg">처리 중입니다. 잠시 기다려주세요.</p>
    </div>
    <div class="bk-dialog-btns" id="bkProgressBtns" style="display:none">
      <button class="bk-dialog-btn" onclick="closeBkDialog('bkProgressOverlay');location.reload()">확인</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     Google Identity Services + Drive API
══════════════════════════════════════ -->
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
const GOOGLE_CLIENT_ID = '<?= defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '' ?>';
const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const BACKUP_FILE_PREFIX = '똑똑가계부_백업_';

let _gTokenClient = null;
let _gAccessToken = null;
let _pendingAction = null; // 'backup' | 'restore'
let _selectedFileId = null;
let _selectedFileName = null;

// ── Google Token Client 초기화 ──
function initTokenClient() {
  if (!GOOGLE_CLIENT_ID || GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID_HERE') {
    alert('Google Client ID가 설정되지 않았습니다.\nconfig/google_oauth.php 파일에 클라이언트 ID를 입력하세요.');
    return false;
  }
  if (!_gTokenClient) {
    _gTokenClient = google.accounts.oauth2.initTokenClient({
      client_id: GOOGLE_CLIENT_ID,
      scope: DRIVE_SCOPE,
      callback: onTokenReceived,
    });
  }
  return true;
}

function onTokenReceived(resp) {
  if (resp.error) {
    showProgress('오류', '로그인이 취소되었거나 오류가 발생했습니다.', true);
    return;
  }
  _gAccessToken = resp.access_token;
  if (_pendingAction === 'backup') doBackupWithToken();
  else if (_pendingAction === 'restore') listDriveBackups();
}

// ── 시트 열기/닫기 ──
function openBkSheet() {
  document.getElementById('bkSheetOverlay').classList.add('show');
}
function closeBkSheet() {
  document.getElementById('bkSheetOverlay').classList.remove('show');
}

// ── 다이얼로그 닫기 ──
function closeBkDialog(id) {
  document.getElementById(id).classList.remove('show');
}

// ── 안내 다이얼로그 열기 ──
function openBackupInfoDialog() {
  closeBkSheet();
  document.getElementById('bkBackupInfoOverlay').classList.add('show');
}
function openRestoreInfoDialog() {
  closeBkSheet();
  document.getElementById('bkRestoreInfoOverlay').classList.add('show');
}
function openAutoBackupDialog() {
  closeBkSheet();
  loadAutoBackupSettings();
  document.getElementById('bkAutoBackupOverlay').classList.add('show');
}
function openPhoneChangeDialog() {
  document.getElementById('bkPhoneChangeOverlay').classList.add('show');
}

// ── 백업 시작 ──
function startGoogleBackup() {
  closeBkDialog('bkBackupInfoOverlay');
  _pendingAction = 'backup';
  if (!initTokenClient()) return;
  _gTokenClient.requestAccessToken({ prompt: '' });
}

// ── DB → JSON → Drive 업로드 ──
async function doBackupWithToken() {
  showProgress('백업 중...', 'DB에서 데이터를 내보내는 중...', false);

  let exportData;
  try {
    const res = await fetch('../api/backup.php?action=export&user_id=1');
    exportData = await res.text();
    const parsed = JSON.parse(exportData);
    if (!parsed.transactions) throw new Error('내보내기 실패');
  } catch (e) {
    showProgress('오류', '데이터 내보내기 중 오류가 발생했습니다: ' + e.message, true);
    return;
  }

  document.getElementById('bkProgressMsg').textContent = '구글 드라이브에 업로드 중...';

  const dateStr = new Date().toISOString().slice(0,10).replace(/-/g,'');
  const fileName = BACKUP_FILE_PREFIX + dateStr + '.json';
  const blob = new Blob([exportData], {type:'application/json'});

  try {
    // 기존 동명 파일 검색 후 삭제
    const searchRes = await fetch(
      `https://www.googleapis.com/drive/v3/files?q=name='${encodeURIComponent(fileName)}'&spaces=drive`,
      { headers: { Authorization: 'Bearer ' + _gAccessToken } }
    );
    const searchData = await searchRes.json();
    for (const f of searchData.files || []) {
      await fetch(`https://www.googleapis.com/drive/v3/files/${f.id}`,
        { method: 'DELETE', headers: { Authorization: 'Bearer ' + _gAccessToken } });
    }

    // 업로드
    const meta = JSON.stringify({ name: fileName, mimeType: 'application/json' });
    const form = new FormData();
    form.append('metadata', new Blob([meta], {type:'application/json'}));
    form.append('file', blob);

    const uploadRes = await fetch(
      'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
      { method: 'POST', headers: { Authorization: 'Bearer ' + _gAccessToken }, body: form }
    );
    if (!uploadRes.ok) throw new Error('업로드 실패');

    localStorage.setItem('backup_last_date', new Date().toISOString());
    showProgress('백업 완료', `${fileName}\n\n구글 드라이브에 백업이 완료되었습니다.`, true);
  } catch (e) {
    showProgress('오류', '구글 드라이브 업로드 중 오류: ' + e.message, true);
  }
}

// ── 복구 시작 ──
function startGoogleRestore() {
  closeBkDialog('bkRestoreInfoOverlay');
  _pendingAction = 'restore';
  if (!initTokenClient()) return;
  _gTokenClient.requestAccessToken({ prompt: '' });
}

// ── Drive에서 백업 파일 목록 가져오기 ──
async function listDriveBackups() {
  document.getElementById('bkRestorePickOverlay').classList.add('show');
  document.getElementById('bkRestoreFileList').innerHTML = '<p style="color:#9E9E9E;text-align:center">파일 목록 불러오는 중...</p>';
  document.getElementById('bkRestoreConfirmBtn').disabled = true;
  _selectedFileId = null;

  try {
    const res = await fetch(
      `https://www.googleapis.com/drive/v3/files?q=name+contains+'${BACKUP_FILE_PREFIX}'&orderBy=createdTime+desc&fields=files(id,name,createdTime)`,
      { headers: { Authorization: 'Bearer ' + _gAccessToken } }
    );
    const data = await res.json();
    const files = data.files || [];

    if (files.length === 0) {
      document.getElementById('bkRestoreFileList').innerHTML = '<p style="color:#9E9E9E;text-align:center">저장된 백업 파일이 없습니다.</p>';
      return;
    }

    let html = '';
    files.forEach(f => {
      const dt = new Date(f.createdTime).toLocaleString('ko-KR');
      html += `<div class="bk-file-item" id="bkf-${f.id}" onclick="selectBackupFile('${f.id}','${f.name}')">
        <div class="bk-file-name">${f.name}</div>
        <div class="bk-file-date">${dt}</div>
      </div>`;
    });
    document.getElementById('bkRestoreFileList').innerHTML = html;
  } catch (e) {
    document.getElementById('bkRestoreFileList').innerHTML = '<p style="color:#E53935">목록 조회 중 오류: ' + e.message + '</p>';
  }
}

function selectBackupFile(fileId, fileName) {
  document.querySelectorAll('.bk-file-item').forEach(el => el.classList.remove('selected'));
  document.getElementById('bkf-' + fileId)?.classList.add('selected');
  _selectedFileId = fileId;
  _selectedFileName = fileName;
  document.getElementById('bkRestoreConfirmBtn').disabled = false;
}

// ── Drive 파일 다운로드 → DB 복구 ──
async function doGoogleRestore() {
  if (!_selectedFileId) return;
  if (!confirm(`"${_selectedFileName}" 파일로 복구하시겠습니까?\n현재 데이터가 모두 교체됩니다.`)) return;

  closeBkDialog('bkRestorePickOverlay');
  showProgress('복구 중...', '파일을 다운로드하는 중...', false);

  try {
    const dlRes = await fetch(
      `https://www.googleapis.com/drive/v3/files/${_selectedFileId}?alt=media`,
      { headers: { Authorization: 'Bearer ' + _gAccessToken } }
    );
    const jsonText = await dlRes.text();
    document.getElementById('bkProgressMsg').textContent = 'DB를 복구하는 중...';

    const importRes = await fetch('../api/backup.php?action=import&user_id=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: jsonText,
    });
    const result = await importRes.json();

    if (result.status === 'ok') {
      showProgress('복구 완료', '복구가 완료되었습니다.\n확인을 누르면 앱이 새로고침됩니다.', true);
    } else {
      showProgress('오류', '복구 실패: ' + result.message, true);
    }
  } catch (e) {
    showProgress('오류', '복구 중 오류: ' + e.message, true);
  }
}

// ── 자동 백업 설정 ──
function loadAutoBackupSettings() {
  const enabled = localStorage.getItem('autoBackup_enabled') === '1';
  const interval = localStorage.getItem('autoBackup_interval') || 'weekly';
  const lastDate = localStorage.getItem('backup_last_date');

  document.getElementById('bkAutoToggle').checked = enabled;
  document.getElementById('bkIntervalSelect').value = interval;
  document.getElementById('bkLastBackupLabel').textContent =
    lastDate ? '마지막 백업: ' + new Date(lastDate).toLocaleString('ko-KR') : '마지막 백업: 없음';
}

function saveAutoBackupSettings() {
  const enabled = document.getElementById('bkAutoToggle').checked;
  const interval = document.getElementById('bkIntervalSelect').value;
  localStorage.setItem('autoBackup_enabled', enabled ? '1' : '0');
  localStorage.setItem('autoBackup_interval', interval);
}

// ── 자동 백업 체크 (페이지 로드 시) ──
function checkAutoBackup() {
  if (localStorage.getItem('autoBackup_enabled') !== '1') return;
  const lastDate = localStorage.getItem('backup_last_date');
  if (!lastDate) return;
  const interval = localStorage.getItem('autoBackup_interval') || 'weekly';
  const last = new Date(lastDate);
  const now = new Date();
  const diffMs = now - last;
  const diffDays = diffMs / (1000 * 60 * 60 * 24);
  const needBackup =
    (interval === 'daily'   && diffDays >= 1)  ||
    (interval === 'weekly'  && diffDays >= 7)  ||
    (interval === 'monthly' && diffDays >= 30);
  if (needBackup && initTokenClient()) {
    _pendingAction = 'backup';
    _gTokenClient.requestAccessToken({ prompt: '' });
  }
}

// ── 진행 다이얼로그 ──
function showProgress(title, msg, showBtn) {
  document.getElementById('bkProgressTitle').textContent = title;
  document.getElementById('bkProgressMsg').textContent = msg;
  document.getElementById('bkProgressBtns').style.display = showBtn ? 'flex' : 'none';
  document.getElementById('bkProgressOverlay').classList.add('show');
}

// 페이지 로드 시 자동 백업 체크
window.addEventListener('load', function() {
  // GIS 라이브러리 로드 확인 후 자동 백업 체크
  setTimeout(function() {
    if (typeof google !== 'undefined' && google.accounts) checkAutoBackup();
  }, 2000);
});

// 저장/수정 후 해당 날짜 자동 펼치기
(function() {
  const expandDate = sessionStorage.getItem('expandDate');
  if (!expandDate) return;
  sessionStorage.removeItem('expandDate');
  const group = document.querySelector('.day-group[data-date="' + expandDate + '"]');
  if (!group) return;
  const items = group.querySelector('.tx-items');
  if (items) {
    items.style.display = 'block';
    setTimeout(function() {
      group.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 150);
  }
})();

// 달력에서 저장 후 달력 뷰 복원
(function() {
  const restoreCal = sessionStorage.getItem('restoreCal');
  if (!restoreCal) return;
  sessionStorage.removeItem('restoreCal');
  if (restoreCal === 'expense') toggleCalendar();
  else if (restoreCal === 'income') toggleIncomeCal();
})();
</script>

<!-- ══════════════════════════════════════
     🔍 검색 모달 CSS + HTML + JS
══════════════════════════════════════ -->
<style>
/* ── 검색 모달 ── */
.srch-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 600;
  align-items: center;
  justify-content: center;
}
.srch-overlay.show { display: flex; }
.srch-card {
  width: calc(100% - 32px);
  max-width: 420px;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}
.srch-hd {
  background: var(--theme-primary, #455A64);
  color: #fff;
  padding: 16px 20px;
  font-size: 18px;
  font-weight: 700;
  flex-shrink: 0;
}
.srch-body {
  padding: 8px 20px 4px;
  overflow-y: auto;
}
.srch-row {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #F0F0F0;
  padding: 10px 0;
  gap: 12px;
}
.srch-label {
  width: 76px;
  font-size: 15px;
  color: #424242;
  flex-shrink: 0;
}
.srch-input, .srch-select {
  flex: 1;
  border: 1px solid #F0C0CC;
  border-radius: 5px;
  padding: 9px 12px;
  font-size: 15px;
  font-family: inherit;
  color: #212121;
  text-align: right;
  background: #FFF5F7;
  outline: none;
}
.srch-input::placeholder { color: #BDBDBD; }
.srch-input:focus, .srch-select:focus {
  border-color: var(--theme-primary, #455A64);
  background: #fff;
}
.srch-add-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 0;
  color: var(--theme-primary, #455A64);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
}
.srch-btns {
  display: flex;
  border-top: 1px solid #F0F0F0;
  flex-shrink: 0;
}
.srch-btn {
  flex: 1;
  padding: 16px;
  border: none;
  background: var(--theme-primary, #455A64);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
}
.srch-btn.cancel {
  background: #fff;
  color: #757575;
  border-right: 1px solid #F0F0F0;
}
.srch-btn:active { opacity: .85; }

/* ── 검색 결과 헤더 ── */
.srch-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background: #FFF5F7;
  border-bottom: 1px solid #F0C0CC;
  font-size: 15px;
  font-weight: 600;
  color: var(--theme-primary, #455A64);
}
.srch-count {
  background: var(--theme-primary, #455A64);
  color: #fff;
  border-radius: 12px;
  padding: 2px 10px;
  font-size: 13px;
  font-weight: 700;
}
.srch-clear {
  margin-left: auto;
  font-size: 13px;
  color: #9E9E9E;
  text-decoration: none;
  cursor: pointer;
}
/* SMS 권한 모달 */
.sms-perm-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 9000;
  align-items: center;
  justify-content: center;
}
.sms-perm-overlay.show { display: flex; }
.sms-perm-card {
  background: #fff;
  border-radius: 12px;
  width: 88%;
  max-width: 340px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
.sms-perm-title {
  background: #e75480;
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  padding: 16px 20px;
}
.sms-perm-body {
  padding: 22px 20px;
  font-size: 15px;
  line-height: 1.6;
  color: #222;
}
.sms-perm-btns {
  display: flex;
  border-top: 1px solid #eee;
}
.sms-perm-btn {
  flex: 1;
  padding: 16px 0;
  font-size: 15px;
  font-weight: 600;
  border: none;
  cursor: pointer;
  background: #e75480;
  color: #fff;
}
.sms-perm-btn.cancel {
  background: #e75480;
  border-right: 1px solid rgba(255,255,255,.3);
}
.sms-perm-btns.single .sms-perm-btn { border-right: none; }
</style>

<!-- SMS 권한 모달 1 -->
<div class="sms-perm-overlay" id="smsPermModal1">
  <div class="sms-perm-card">
    <div class="sms-perm-title">똑똑가계부</div>
    <div class="sms-perm-body">문자 메시지를 기반으로 한 가계부 자동 입력을 위하여 'SMS' 권한이 필요합니다.</div>
    <div class="sms-perm-btns single">
      <button class="sms-perm-btn confirm" onclick="smsPermStep2()">확인</button>
    </div>
  </div>
</div>

<!-- SMS 권한 모달 2 -->
<div class="sms-perm-overlay" id="smsPermModal2">
  <div class="sms-perm-card">
    <div class="sms-perm-title">똑똑가계부</div>
    <div class="sms-perm-body">이 작업을 하기 위해서는 '확인'을 누르신 후 '권한' &gt; 'SMS'를 허용해 주세요.</div>
    <div class="sms-perm-btns">
      <button class="sms-perm-btn cancel" onclick="smsPermClose()">취소</button>
      <button class="sms-perm-btn confirm" onclick="smsPermConfirm()">확인</button>
    </div>
  </div>
</div>

<!-- 검색 모달 -->
<div class="srch-overlay" id="searchOverlay" onclick="if(event.target===this)closeSearchModal()">
  <div class="srch-card">
    <div class="srch-hd" id="searchTitle">지출 내역 검색</div>
    <div class="srch-body">
      <div class="srch-row">
        <span class="srch-label">검색어</span>
        <input class="srch-input" id="srchKw" type="text" placeholder="입력"
               value="<?= htmlspecialchars($srchKw) ?>">
      </div>
      <div class="srch-row">
        <span class="srch-label">검색 시작일</span>
        <input class="srch-input" id="srchStart" type="date"
               value="<?= htmlspecialchars($srchStart) ?>" placeholder="-">
      </div>
      <div class="srch-row">
        <span class="srch-label">검색 종료일</span>
        <input class="srch-input" id="srchEnd" type="date"
               value="<?= htmlspecialchars($srchEnd) ?>" placeholder="-">
      </div>
      <div class="srch-row">
        <span class="srch-label">카테고리</span>
        <select class="srch-select" id="srchCat">
          <option value="0">-</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $srchCat === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="srch-row">
        <span class="srch-label">결제수단</span>
        <input class="srch-input" id="srchPm" type="text" placeholder="-"
               value="<?= htmlspecialchars($srchPm) ?>">
      </div>
      <div class="srch-add-row" onclick="alert('추가 조건은 준비 중입니다.')">
        <span style="font-size:20px">+</span> 검색 조건 추가
      </div>
    </div>
    <div class="srch-btns">
      <button class="srch-btn cancel" onclick="closeSearchModal()">취소</button>
      <button class="srch-btn" onclick="doSearch()">검색</button>
    </div>
  </div>
</div>

<script>
/* SMS 권한 2단계 모달 */
function openSmsPermission() {
  document.getElementById('smsPermModal1').classList.add('show');
}
function smsPermStep2() {
  document.getElementById('smsPermModal1').classList.remove('show');
  document.getElementById('smsPermModal2').classList.add('show');
}
function smsPermClose() {
  document.getElementById('smsPermModal2').classList.remove('show');
}
function smsPermConfirm() {
  document.getElementById('smsPermModal2').classList.remove('show');
  // TODO: SMS 권한 허용 후 실제 문자 불러오기 연동
}

function openSearchModal() {
  document.getElementById('searchOverlay').classList.add('show');
}
function closeSearchModal() {
  document.getElementById('searchOverlay').classList.remove('show');
}
function doSearch() {
  var kw    = document.getElementById('srchKw').value.trim();
  var start = document.getElementById('srchStart').value;
  var end   = document.getElementById('srchEnd').value;
  var cat   = document.getElementById('srchCat').value;
  var pm    = document.getElementById('srchPm').value.trim();
  var params = new URLSearchParams({tab:'expense', srch:'1'});
  if (kw)    params.set('srch_kw',    kw);
  if (start) params.set('srch_start', start);
  if (end)   params.set('srch_end',   end);
  if (cat && cat !== '0') params.set('srch_cat', cat);
  if (pm)    params.set('srch_pm',    pm);
  location.href = '?' + params.toString();
}

<?php if ($srchMode): ?>
// 검색 모드일 때 결과 페이지 로드 시 모달 자동 오픈 비활성화
<?php endif; ?>
</script>

<!-- ══════════════════════════════════════
     ⋮ 더보기 메뉴 CSS + 모달
══════════════════════════════════════ -->
<style>
/* ── 드롭다운 메뉴 ── */
.more-menu {
  position: absolute;
  top: 48px; right: 8px;
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(0,0,0,.22);
  min-width: 170px;
  z-index: 500;
  overflow: hidden;
}
.more-menu-item {
  padding: 14px 20px;
  font-size: 15px;
  color: #212121;
  cursor: pointer;
  white-space: nowrap;
}
.more-menu-item:not(:last-child) { border-bottom: 1px solid #F5F5F5; }
.more-menu-item:active { background: #F5F5F5; }

/* ── 공용 모달 오버레이 ── */
.mm-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 600;
  align-items: center;
  justify-content: center;
}
.mm-overlay.show { display: flex; }

/* ── 모달 카드 ── */
.mm-card {
  width: calc(100% - 48px);
  max-width: 400px;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}
.mm-hd {
  background: var(--theme-primary, #455A64);
  color: #fff;
  padding: 16px 20px;
  font-size: 18px;
  font-weight: 700;
  flex-shrink: 0;
}
.mm-body {
  padding: 20px;
  font-size: 16px;
  color: #333;
  line-height: 1.8;
  overflow-y: auto;
}
.mm-body p { margin-bottom: 14px; }
.mm-chk-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 16px;
  font-size: 15px;
  color: #424242;
}
.mm-chk-row input[type=checkbox] {
  width: 20px; height: 20px;
  accent-color: var(--theme-primary, #455A64);
  cursor: pointer;
  flex-shrink: 0;
}
.mm-btns {
  display: flex;
  border-top: 1px solid #F0F0F0;
  flex-shrink: 0;
}
.mm-btn {
  flex: 1;
  padding: 15px;
  border: none;
  background: var(--theme-primary, #455A64);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
}
.mm-btn.cancel {
  background: #fff;
  color: #757575;
  border-right: 1px solid #F0F0F0;
}
.mm-btn:active { opacity: .85; }

/* ── 즐겨찾기 목록 ── */
.fav-empty {
  text-align: center;
  color: #9E9E9E;
  font-size: 15px;
  padding: 24px 0;
}
.fav-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid #F5F5F5;
}
.fav-item-name { font-size: 15px; font-weight: 600; color: #212121; }
.fav-item-sub  { font-size: 13px; color: #9E9E9E; margin-top: 2px; }
.fav-del-btn {
  background: none; border: none;
  color: #9E9E9E; font-size: 18px; cursor: pointer; padding: 4px 8px;
}

/* ── 즐겨찾기 추가 폼 ── */
.fav-form-group { margin-bottom: 14px; }
.fav-form-label {
  font-size: 13px;
  color: #757575;
  margin-bottom: 5px;
  display: block;
}
.fav-form-input, .fav-form-select {
  width: 100%;
  border: 1px solid #E0E0E0;
  border-radius: 5px;
  padding: 9px 12px;
  font-size: 15px;
  font-family: inherit;
  color: #212121;
  outline: none;
}
.fav-form-input:focus, .fav-form-select:focus {
  border-color: var(--theme-primary, #455A64);
}
.fav-repeat-btns {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.fav-repeat-btn {
  padding: 6px 14px;
  border: 1px solid #E0E0E0;
  border-radius: 20px;
  font-size: 13px;
  background: #fff;
  color: #424242;
  cursor: pointer;
  font-family: inherit;
}
.fav-repeat-btn.active {
  background: var(--theme-primary, #455A64);
  color: #fff;
  border-color: var(--theme-primary, #455A64);
}

/* ── 엑셀 옵션 ── */
.excel-opt-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 0;
  border-bottom: 1px solid #F5F5F5;
  cursor: pointer;
  font-size: 16px;
  color: #212121;
}
.excel-opt-item:active { background: #F9F9F9; }
.excel-opt-icon {
  width: 36px; height: 36px;
  background: var(--theme-primary, #455A64);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 18px; flex-shrink: 0;
}
.excel-type-btns {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
.excel-type-btn {
  flex: 1;
  padding: 8px;
  border: 1px solid var(--theme-primary, #455A64);
  border-radius: 5px;
  background: #fff;
  color: var(--theme-primary, #455A64);
  font-size: 14px;
  font-family: inherit;
  cursor: pointer;
  font-weight: 600;
}
.excel-type-btn.active {
  background: var(--theme-primary, #455A64);
  color: #fff;
}
</style>

<!-- ── 카테고리 일괄 분류 팁 ── -->
<div class="mm-overlay" id="categorizeTipOverlay">
  <div class="mm-card">
    <div class="mm-hd">사용 팁</div>
    <div class="mm-body">
      <p>카테고리 일괄 분류는 카테고리가 지정되지 않은 내역들을 한꺼번에 분류할 수 있는 기능입니다.</p>
      <p>메모나 설명을 기반으로 자동으로 카테고리를 추천해 드리며, 수동으로 선택하여 일괄 적용할 수 있습니다.</p>
      <p>이 기능을 사용하면 카테고리 미분류 내역을 빠르게 정리할 수 있습니다.</p>
      <label class="mm-chk-row">
        <input type="checkbox" id="categorizeTipChk">
        이 팁을 다시 보지 않기
      </label>
    </div>
    <div class="mm-btns">
      <button class="mm-btn" onclick="closeCategorizeTip()">확인</button>
    </div>
  </div>
</div>

<!-- ── 즐겨찾기 편집 ── -->
<div class="mm-overlay" id="favListOverlay">
  <div class="mm-card">
    <div class="mm-hd" style="display:flex;justify-content:space-between;align-items:center">
      <span>즐겨찾기 편집</span>
      <span style="font-size:22px;cursor:pointer;font-weight:400" onclick="openFavAdd()">+</span>
    </div>
    <div class="mm-body" id="favListBody">
      <!-- JS로 렌더 -->
    </div>
    <div class="mm-btns">
      <button class="mm-btn" onclick="closeModal('favListOverlay')">닫기</button>
    </div>
  </div>
</div>

<!-- ── 즐겨찾기 추가 ── -->
<div class="mm-overlay" id="favAddOverlay">
  <div class="mm-card">
    <div class="mm-hd">즐겨찾기 추가</div>
    <div class="mm-body">
      <div class="fav-form-group">
        <label class="fav-form-label">반복 입력</label>
        <div class="fav-repeat-btns" id="favRepeatBtns">
          <button class="fav-repeat-btn active" onclick="setFavRepeat(this,'없음')">없음</button>
          <button class="fav-repeat-btn" onclick="setFavRepeat(this,'매일')">매일</button>
          <button class="fav-repeat-btn" onclick="setFavRepeat(this,'매주')">매주</button>
          <button class="fav-repeat-btn" onclick="setFavRepeat(this,'매월')">매월</button>
        </div>
      </div>
      <div class="fav-form-group">
        <label class="fav-form-label">지출 금액</label>
        <input class="fav-form-input" type="number" id="favAmt" placeholder="0">
      </div>
      <div class="fav-form-group">
        <label class="fav-form-label">메모</label>
        <input class="fav-form-input" type="text" id="favMemo" placeholder="메모 (선택)">
      </div>
      <div class="fav-form-group">
        <label class="fav-form-label">카테고리</label>
        <select class="fav-form-select" id="favCat">
          <option value="">선택 안함</option>
          <?php foreach ($expenseCats as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fav-form-group">
        <label class="fav-form-label">결제수단</label>
        <select class="fav-form-select" id="favPm">
          <option value="">선택 안함</option>
          <option value="현금">현금</option>
          <option value="신용카드">신용카드</option>
          <option value="체크카드">체크카드</option>
          <option value="계좌이체">계좌이체</option>
        </select>
      </div>
    </div>
    <div class="mm-btns">
      <button class="mm-btn cancel" onclick="closeModal('favAddOverlay')">취소</button>
      <button class="mm-btn" onclick="saveFavorite()">확인</button>
    </div>
  </div>
</div>

<!-- ── 엑셀 내보내기 안내 ── -->
<div class="mm-overlay" id="excelInfoOverlay">
  <div class="mm-card">
    <div class="mm-hd">엑셀 내보내기</div>
    <div class="mm-body">
      <p>현재 월(<span id="excelYmLabel"></span>)의 내역을 CSV 파일로 내보냅니다.</p>
      <p>내보낸 파일은 Microsoft Excel, 구글 스프레드시트 등에서 열 수 있습니다.</p>
      <div class="excel-type-btns">
        <button class="excel-type-btn active" id="excelTypeExp" onclick="setExcelType('expense',this)">지출</button>
        <button class="excel-type-btn" id="excelTypeInc" onclick="setExcelType('income',this)">수입</button>
        <button class="excel-type-btn" id="excelTypeAll" onclick="setExcelType('all',this)">전체</button>
      </div>
    </div>
    <div class="mm-btns">
      <button class="mm-btn cancel" onclick="closeModal('excelInfoOverlay')">취소</button>
      <button class="mm-btn" onclick="closeModal('excelInfoOverlay');openExcelSave()">확인</button>
    </div>
  </div>
</div>

<!-- ── 엑셀 저장 방법 선택 ── -->
<div class="mm-overlay" id="excelSaveOverlay">
  <div class="mm-card">
    <div class="mm-hd">저장 방법 선택</div>
    <div class="mm-body">
      <div class="excel-opt-item" onclick="doExcelDownload()">
        <div class="excel-opt-icon">⬇</div>
        <span>파일로 저장</span>
      </div>
      <div class="excel-opt-item" onclick="doExcelEmail()">
        <div class="excel-opt-icon">✉</div>
        <span>이메일로 전송</span>
      </div>
    </div>
    <div class="mm-btns">
      <button class="mm-btn cancel" onclick="closeModal('excelSaveOverlay')">취소</button>
    </div>
  </div>
</div>

<script>
/* ═══ ⋮ 더보기 메뉴 ═══ */
function toggleMoreMenu(e) {
  e.stopPropagation();
  var m = document.getElementById('moreMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
function closeMoreMenu() {
  document.getElementById('moreMenu').style.display = 'none';
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('#moreMenu') && !e.target.classList.contains('bi-three-dots-vertical')) {
    closeMoreMenu();
  }
});

/* ═══ 공통 모달 닫기 ═══ */
function closeModal(id) {
  document.getElementById(id).classList.remove('show');
}
// 오버레이 클릭 시 닫기
['categorizeTipOverlay','favListOverlay','favAddOverlay','excelInfoOverlay','excelSaveOverlay'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) {
    if (e.target === this) closeModal(id);
  });
});

/* ═══ 카테고리 일괄 분류 팁 ═══ */
function openCategorizeTip() {
  if (localStorage.getItem('categorizeTip_dismissed') === '1') {
    // 팁 스킵하고 바로 기능 화면으로 (현재는 안내만)
    alert('현재 카테고리가 없는 내역이 없습니다.');
    return;
  }
  document.getElementById('categorizeTipOverlay').classList.add('show');
}
function closeCategorizeTip() {
  if (document.getElementById('categorizeTipChk').checked) {
    localStorage.setItem('categorizeTip_dismissed', '1');
  }
  closeModal('categorizeTipOverlay');
}

/* ═══ 즐겨찾기 ═══ */
var _favRepeat = '없음';

function openFavorites() {
  renderFavList();
  document.getElementById('favListOverlay').classList.add('show');
}
function renderFavList() {
  var favs = JSON.parse(localStorage.getItem('favorites') || '[]');
  var body = document.getElementById('favListBody');
  if (favs.length === 0) {
    body.innerHTML = '<div class="fav-empty">등록된 즐겨찾기가 없습니다.</div>';
    return;
  }
  body.innerHTML = favs.map(function(f, i) {
    var sub = [f.repeat !== '없음' ? f.repeat : '', f.pm, f.cat_name].filter(Boolean).join(' · ');
    return '<div class="fav-item">'
      + '<div><div class="fav-item-name">' + (f.memo || '즐겨찾기 ' + (i+1)) + '</div>'
      + '<div class="fav-item-sub">' + window.CURRENCY.format(f.amt) + (sub ? ' · ' + sub : '') + '</div></div>'
      + '<button class="fav-del-btn" onclick="deleteFav(' + i + ')">✕</button>'
      + '</div>';
  }).join('');
}
function deleteFav(idx) {
  var favs = JSON.parse(localStorage.getItem('favorites') || '[]');
  favs.splice(idx, 1);
  localStorage.setItem('favorites', JSON.stringify(favs));
  renderFavList();
}
function openFavAdd() {
  document.getElementById('favAmt').value = '';
  document.getElementById('favMemo').value = '';
  document.getElementById('favCat').value = '';
  document.getElementById('favPm').value = '';
  _favRepeat = '없음';
  document.querySelectorAll('.fav-repeat-btn').forEach(function(b) {
    b.classList.toggle('active', b.textContent === '없음');
  });
  document.getElementById('favAddOverlay').classList.add('show');
}
function setFavRepeat(btn, val) {
  _favRepeat = val;
  document.querySelectorAll('.fav-repeat-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
}
function saveFavorite() {
  var amt  = parseInt(document.getElementById('favAmt').value) || 0;
  var memo = document.getElementById('favMemo').value.trim();
  var catEl = document.getElementById('favCat');
  var cat_name = catEl.options[catEl.selectedIndex]?.text || '';
  var pm = document.getElementById('favPm').value;
  if (!amt && !memo) { alert('금액 또는 메모를 입력해주세요.'); return; }
  var favs = JSON.parse(localStorage.getItem('favorites') || '[]');
  favs.push({ amt: amt, memo: memo, cat_id: catEl.value, cat_name: cat_name, pm: pm, repeat: _favRepeat });
  localStorage.setItem('favorites', JSON.stringify(favs));
  closeModal('favAddOverlay');
  renderFavList();
}

/* ═══ 엑셀 내보내기 ═══ */
var _excelType = 'expense';

function openExcelInfo() {
  var ym = '<?= date('Y-m') ?>';
  document.getElementById('excelYmLabel').textContent = ym.replace('-', '년 ') + '월';
  _excelType = 'expense';
  document.querySelectorAll('.excel-type-btn').forEach(function(b) { b.classList.remove('active'); });
  document.getElementById('excelTypeExp').classList.add('active');
  document.getElementById('excelInfoOverlay').classList.add('show');
}
function setExcelType(type, btn) {
  _excelType = type;
  document.querySelectorAll('.excel-type-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
}
function openExcelSave() {
  document.getElementById('excelSaveOverlay').classList.add('show');
}
function doExcelDownload() {
  var ym = '<?= date('Y-m') ?>';
  var url = '../api/export_excel.php?user_id=1&ym=' + ym + '&type=' + _excelType;
  var a = document.createElement('a');
  a.href = url;
  a.download = '';
  a.click();
  closeModal('excelSaveOverlay');
}
function doExcelEmail() {
  var ym = '<?= date('Y-m') ?>';
  var url = '../api/export_excel.php?user_id=1&ym=' + ym + '&type=' + _excelType;
  window.location.href = 'mailto:?subject=똑똑가계부 ' + ym + ' 내역&body=첨부 파일을 확인해주세요.%0A' + encodeURIComponent(url);
  closeModal('excelSaveOverlay');
}
</script>

</body>
</html>
