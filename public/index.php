<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = !empty($_SESSION['user_id']);
$userName   = htmlspecialchars($_SESSION['user_name']  ?? '');
$userEmail  = htmlspecialchars($_SESSION['user_email'] ?? '');

// ── 로그인 시 DB 통계 + 설정 로드 ──────────────────────────
$darkMode    = false;
$dbStats     = ['month_count' => 0, 'streak' => 0, 'badge' => ''];
$notifTime   = '21:00';

if ($isLoggedIn) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = getConnection();
    $uid = (int)$_SESSION['user_id'];

    // 이번 달 거래 횟수
    $ym  = date('Y-m');
    $s1  = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id=:uid AND DATE_FORMAT(tx_date,'%Y-%m')=:ym");
    $s1->execute([':uid' => $uid, ':ym' => $ym]);
    $dbStats['month_count'] = (int)$s1->fetchColumn();

    // 연속 기록일
    $s2 = $pdo->prepare("SELECT DISTINCT DATE(tx_date) AS d FROM transactions WHERE user_id=:uid AND tx_date >= DATE_SUB(CURDATE(),INTERVAL 90 DAY) ORDER BY d DESC");
    $s2->execute([':uid' => $uid]);
    $dateSet = array_column($s2->fetchAll(PDO::FETCH_ASSOC), 'd');
    $streak  = 0;
    $check   = in_array(date('Y-m-d'), $dateSet) ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
    while (in_array($check, $dateSet)) {
        $streak++;
        $check = date('Y-m-d', strtotime($check . ' -1 day'));
    }
    $dbStats['streak'] = $streak;

    // 배지
    $mc = $dbStats['month_count'];
    if      ($mc >= 50) $dbStats['badge'] = '자산 수비대 🛡️';
    elseif  ($mc >= 20) $dbStats['badge'] = '절약 탐험가 🧭';
    elseif  ($mc >=  5) $dbStats['badge'] = '기록 새싹 🌱';

    // 설정 로드 (다크모드, 알림)
    try {
        $ss = $pdo->prepare("SELECT dark_mode, notif_time FROM user_settings WHERE user_id=:uid");
        $ss->execute([':uid' => $uid]);
        $row = $ss->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $darkMode  = (bool)$row['dark_mode'];
            $notifTime = $row['notif_time'] ?: '21:00';
        }
    } catch (PDOException $e) { /* 테이블 없으면 무시 */ }

    // 세션 설정 우선
    if (!empty($_SESSION['settings']['dark_mode'])) $darkMode = (bool)$_SESSION['settings']['dark_mode'];
    if (!empty($_SESSION['settings']['notif_time'])) $notifTime = $_SESSION['settings']['notif_time'];

    // ── 카테고리 로드 (기본값 항상 보장) ────────────────────────
    $userCats = ['expense' => [], 'income' => []];
    try {
        $defaults = [
            ['식비','🍚','expense'],['교통','🚌','expense'],['쇼핑','🛍️','expense'],
            ['의료','💊','expense'],['문화','🎬','expense'],['통신','📱','expense'],
            ['주거','🏠','expense'],['기타','📦','expense'],
            ['급여','💰','income'],['용돈','🎁','income'],['기타수입','💵','income'],
        ];
        // 유저별로 없는 기본값만 INSERT (SELECT 후 없으면 삽입)
        $chk = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id=:uid AND name=:n AND type=:t");
        $ins = $pdo->prepare("INSERT INTO categories (user_id,name,icon,type) VALUES (:uid,:n,:i,:t)");
        foreach ($defaults as [$n,$i,$t]) {
            $chk->execute([':uid'=>$uid,':n'=>$n,':t'=>$t]);
            if ((int)$chk->fetchColumn() === 0) {
                $ins->execute([':uid'=>$uid,':n'=>$n,':i'=>$i,':t'=>$t]);
            }
        }
        $sc2 = $pdo->prepare("SELECT id, name, type, icon FROM categories WHERE user_id=:uid ORDER BY type, id");
        $sc2->execute([':uid' => $uid]);
        foreach ($sc2->fetchAll() as $cat) {
            $userCats[$cat['type']][] = $cat;
        }
    } catch (PDOException $e) { /* categories 테이블 없으면 무시 */ }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>마이가계부</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ══ 디자인 시스템 ══════════════════════════════════════════════ */
:root {
  --p:       #1E293B;
  --p-dark:  #0F172A;
  --p-mid:   #334155;
  --p-light: #EEF1FB;
  --accent:  #2563EB;
  --bg:      #F8FAFC;
  --surface: #FFFFFF;
  --border:  #E2E8F0;
  --text1:   #1E293B;
  --text2:   #64748B;
  --income:  #2563EB;
  --expense: #E11D48;
  --shadow:  0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.05);
  --r:       12px;
}

@keyframes fadeUp   { from { opacity:0; transform:translateY(6px); }  to { opacity:1; transform:none; } }
@keyframes fadeIn      { from { opacity:0; }                              to { opacity:1; } }
@keyframes fadeScaleIn { from { opacity:0; transform:scale(.95); }        to { opacity:1; transform:scale(1); } }
@keyframes slideUp  { from { transform:translateY(30px); opacity:0; } to { transform:none; opacity:1; } }

* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
  font-family: 'Noto Sans KR', -apple-system, 'Malgun Gothic', sans-serif;
  background: var(--bg); color: var(--text1);
  max-width: 480px; margin: 0 auto; min-height: 100vh; overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
}
/* ₩ 기호 스타일 */
.w-sym { font-size: .78em; font-weight: 400; opacity: .65; letter-spacing: 0; }
/* 카테고리 아이콘 래퍼 — bg는 인라인으로 설정 */
.cat-ic { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 15px; }

/* ── 헤더 ── */
.app-header {
  position: sticky; top: 0; z-index: 100;
  background: #364B6D; color: #fff;
  height: 56px; padding: 0 14px;
  display: flex; justify-content: space-between; align-items: center;
  box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
}
.header-title { display:flex; align-items:center; gap:6px; }
.header-logo-text { font-size: 16px; font-weight: 700; letter-spacing: .3px; }
.header-center-title {
  position: absolute; left: 50%; transform: translateX(-50%);
  font-size: 18px; font-weight: 800; color: #fff; pointer-events: none; display: none;
}
/* 통계 탭 — 헤더에 월 크게 */
.app-header.stats-mode .header-title { display: flex; }
.app-header.stats-mode .header-actions { margin-left: auto; }
.stats-header-month {
  font-size: 22px; font-weight: 800; color: #fff; display: none; letter-spacing: -.3px;
}
/* 나 탭 — 헤더+배너 한 덩어리 */
.app-header.me-mode { background: #364A6D; box-shadow: none; }
.app-header.me-mode .header-center-title { color: #fff; font-size: 20px; font-weight: 800; }
/* 가계부/달력 탭 — 진한 네이비 헤더 */
.app-header.ledger-mode {
  background: #364B6D !important;
  color: #fff !important;
  box-shadow: none !important;
  border-bottom: none !important;
}
.app-header.ledger-mode .header-logo-text { color: #fff !important; }
.app-header.ledger-mode .header-title svg { filter: none !important; }
.app-header.ledger-mode .search-btn,
.app-header.ledger-mode .cal-btn { color: rgba(255,255,255,.85) !important; }
/* 통계 탭 주/월/년 필 */
.header-period-filter { display: flex; background: rgba(255,255,255,.18); border-radius: 20px; padding: 3px 4px; gap: 0; }
.hpf-btn { background: none; border: none; color: rgba(255,255,255,.7); font-size: 13px; font-weight: 700; padding: 5px 10px; border-radius: 16px; cursor: pointer; font-family: inherit; white-space: nowrap; }
.hpf-btn.on { background: rgba(255,255,255,.3); color: #fff; }
.hpf-btn:active { background: rgba(255,255,255,.2); }
.month-nav { display: flex; align-items: center; gap: 4px; }
.header-actions { display: flex; align-items: center; gap: 2px; }
.month-btn {
  background: none; border: none; color: #fff;
  font-size: 22px; cursor: pointer; padding: 2px 7px; border-radius: 4px; line-height: 1;
}
.month-btn:active { background: rgba(255,255,255,.2); }
.month-btn:disabled { color: rgba(255,255,255,.25); cursor: default; }
.month-btn:disabled:active { background: none; }
#monthLabel { font-size: 14px; font-weight: 600; min-width: 70px; text-align: center; }
.cal-btn {
  background: none; border: none; color: #fff; font-size: 18px;
  cursor: pointer; padding: 4px 6px; border-radius: 4px; line-height: 1;
  display:flex; align-items:center; justify-content:center;
}
.cal-btn:active { background: rgba(255,255,255,.2); }
.search-btn {
  background: none; border: none; color: #fff; font-size: 18px;
  cursor: pointer; padding: 4px 6px; border-radius: 4px; line-height: 1;
  display:flex; align-items:center; justify-content:center;
}
.search-btn:active { background: rgba(255,255,255,.2); }

/* ── 탭 패인 ── */
.tab-pane { display: none; padding-bottom: 72px; }
.tab-pane.active { display: block; animation: fadeUp .22s ease; }

/* ── 요약 스트립 (헤더 아래 고정, 카드가 네이비 구간에 절반 걸치게) ── */
.sum-strip {
  position: sticky; top: 56px; z-index: 90;
  background: var(--bg);
  border: none; box-shadow: none;
}
/* 월 네비 */
.sum-strip .month-nav {
  justify-content: space-between;
  background: #364B6D;
  padding: 10px 16px 40px; /* 하단 40px = 가계부탭 카드 겹침 공간 */
  margin: 0;
}
/* 통계/분석 탭은 sumCols 없으므로 패딩 줄임 */
.sum-strip.no-cols .month-nav {
  padding: 10px 0 14px;
}
.sum-strip .month-btn { color: rgba(255,255,255,.75) !important; font-size: 28px; background:none; border:none; cursor:pointer; padding:2px 8px; border-radius:4px; line-height:1; }
.sum-strip .month-btn:active { background: rgba(255,255,255,.1); }
.sum-strip .month-btn:disabled { color: rgba(255,255,255,.25) !important; cursor:default; }
.sum-strip #monthLabel { font-size: 16px !important; font-weight: 700; color: #fff !important; min-width: 80px; text-align: center; }
.sum-strip #monthLabel.picker-mode { background: rgba(255,255,255,.15); border-radius: 6px; padding: 3px 10px; cursor: pointer; color: #fff; }
.sum-strip #monthLabel.picker-mode::after { content: ' ▾'; font-size: 10px; opacity: .8; }
/* 카드 컬럼 — 위로 당겨서 네이비 영역에 절반 걸치게 */
.sum-cols {
  display: flex; gap: 10px;
  padding: 0 16px 16px;
  margin-top: -32px; /* 네이비 구간으로 32px 올라감 */
  position: relative; z-index: 2;
}
.sum-col {
  flex: 1; background: #ffffff !important;
  border-radius: 16px !important;
  padding: 14px 8px 16px !important;
  text-align: center; border: none !important;
  box-shadow: 0 8px 20px rgba(0,0,0,.08) !important;
}
.sum-col-label { font-size: 12px; font-weight: 600; color: #64748B; letter-spacing: .2px; }
.sum-col-value { font-size: 16px; font-weight: 800; margin-top: 6px; color: var(--text1); }
.sum-income  { color: #2563EB !important; }
.sum-expense { color: #E11D48 !important; }

/* 거래 내역 섹션 제목 */
.tx-section-title {
  padding: 20px 20px 4px;
  font-size: 17px; font-weight: 800; color: #1E293B;
  background: transparent;
}
/* ── 거래 목록 ── */
.date-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 20px 6px; font-size: 12px; font-weight: 700; color: var(--text2); background: transparent;
}
.tx-row {
  display: flex; align-items: center; gap: 14px;
  padding: 16px 20px !important;
  background: #ffffff !important;
  border-radius: 14px !important;
  margin: 8px 16px !important;
  box-shadow: 0 2px 12px rgba(0,0,0,.06) !important;
  border-bottom: none !important;
  cursor: pointer;
}
.tx-row:active { background: #F8FAFC !important; }
.tx-icon {
  width: 46px; height: 46px; border-radius: 50%; background: #F1F5F9;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  overflow: hidden;
}
.tx-info { flex: 1; min-width: 0; }
.tx-desc { font-size: 14px; font-weight: 600; color: #1E293B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tx-cat  { font-size: 12px; color: #64748B; margin-top: 2px; }
.tx-right { display: flex; flex-direction: row; align-items: center; gap: 8px; margin-left: auto; flex-shrink: 0; }
.tx-amt  { font-size: 15px; font-weight: 700; white-space: nowrap; letter-spacing: -.3px; }
.tx-amt.expense { color: #E11D48 !important; }
.tx-amt.income  { color: #2563EB !important; }
.tx-thumb-slot {
  width: 42px; height: 42px; border-radius: 8px; flex-shrink: 0;
  background: #f0f0f0; border: 1.5px dashed #d0d0d0;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; cursor: pointer; position: relative;
}
.tx-thumb-slot.empty { cursor: default; opacity: .55; }
.tx-thumb-slot img {
  width: 100%; height: 100%; object-fit: cover; display: block;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,.12);
  border: 1px solid rgba(0,0,0,.08);
}
.empty-msg { text-align: center; padding: 60px 20px; color: #bdbdbd; font-size: 15px; line-height: 1.9; }

/* ── 달력 뷰 ── */
.cal-grid-wrap { padding: 10px 12px 0; }
.cal-dow-row { display: grid; grid-template-columns: repeat(7,1fr); margin-bottom: 2px; }
.cal-dow { text-align: center; font-size: 11px; font-weight: 700; color: #9e9e9e; padding: 4px 0; }
.cal-dow:first-child { color: #EF4444; }
.cal-dow:last-child  { color: #42a5f5; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 2px; }
.cal-cell {
  background: #fff; border-radius: 8px; padding: 6px 4px 5px;
  min-height: 54px; cursor: pointer; position: relative;
  display: flex; flex-direction: column; align-items: center;
}
.cal-cell:active { background: #f0f0f0; }
.cal-cell.today .cal-day { background: var(--p); color: #fff; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
.cal-cell.other-month { opacity: .35; }
.cal-day { font-size: 13px; font-weight: 600; color: #212121; line-height: 24px; }
.cal-day.sun { color: #EF4444; }
.cal-day.sat { color: #42a5f5; }
.cal-dots { display: flex; gap: 2px; margin-top: 3px; flex-wrap: wrap; justify-content: center; }
.cal-dot { width: 5px; height: 5px; border-radius: 50%; }
.cal-dot.e { background: var(--expense); }
.cal-dot.i { background: var(--income); }
.cal-amt { font-size: 9px; color: var(--expense); margin-top: 2px; white-space: nowrap; overflow: hidden; width: 100%; text-align: center; }

/* 날짜 클릭 시 내역 슬라이드 */
.day-sheet-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.45); z-index: 400; align-items: flex-end; justify-content: center;
}
.day-sheet-overlay.show { display: flex; }
.day-sheet {
  background: #fff; border-radius: 20px 20px 0 0;
  width: 100%; max-width: 480px; max-height: 70vh; overflow-y: auto; padding-bottom: 24px;
}
.day-sheet-hd {
  background: var(--p); border-radius: 20px 20px 0 0;
  padding: 14px 20px; display: flex; justify-content: space-between; align-items: center;
}
.day-sheet-title { color: #fff; font-size: 16px; font-weight: 700; }
.day-sheet-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.day-sheet-add { background: rgba(255,255,255,.25); border: none; color: #fff; font-size: 20px; font-weight: 700; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
.day-sheet-add:active { background: rgba(255,255,255,.4); }

/* ── 통계 ── */
.section-box { margin: 10px 14px; background: var(--surface); border-radius: var(--r); padding: 18px; box-shadow: var(--shadow); }
.section-title { font-size: 14px; font-weight: 700; color: var(--text1); margin-bottom: 14px; }
/* 지출/수입 토글 — 사진처럼 크고 둥근 필 스타일 */
.stats-type-toggle { display: flex; margin: 14px 16px 4px; background: #EAEEF5; border-radius: 50px; padding: 4px; border: none; gap: 0; }
.st-btn { flex: 1; padding: 11px 0; border: none; background: none; font-size: 16px; font-weight: 700; color: #9CA3AF; cursor: pointer; font-family: inherit; transition: all .22s; border-radius: 50px; }
.st-btn.on.expense { background: var(--expense); color: #fff; box-shadow: 0 2px 10px rgba(239,68,68,.25); }
.st-btn.on.income  { background: var(--accent); color: #fff; box-shadow: 0 2px 10px rgba(41,121,255,.25); }
/* 헤더 우측 기간 필터 버튼 (통계 탭 전용) */
.header-period-filter { display: flex; gap: 2px; background: rgba(255,255,255,.15); border-radius: 8px; padding: 2px; }
.hpf-btn { background: none; border: none; color: rgba(255,255,255,.7); font-size: 12px; font-weight: 700; padding: 5px 9px; border-radius: 6px; cursor: pointer; font-family: inherit; }
.hpf-btn.on { background: rgba(255,255,255,.28); color: #fff; }
.hpf-btn:active { background: rgba(255,255,255,.2); }
/* 랭킹 헤더 인라인 그룹 토글 */
.ranking-header { padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f5f5f5; }
.ranking-header-title { font-size: 13px; font-weight: 700; color: #757575; }
.rank-group-toggle { display: flex; gap: 3px; }
.rg-btn { padding: 4px 9px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; font-size: 12px; font-weight: 600; color: #9e9e9e; cursor: pointer; font-family: inherit; }
.rg-btn.on { border-color: var(--accent); color: var(--accent); background: #EEF4FF; }
/* 빈 상태 */
.stats-empty-state { text-align: center; padding: 52px 24px 36px; }
.stats-empty-icon { font-size: 50px; margin-bottom: 14px; }
.stats-empty-title { font-size: 16px; font-weight: 700; color: #424242; margin-bottom: 8px; }
.stats-empty-sub { font-size: 13px; color: #9e9e9e; line-height: 1.7; }
/* monthLabel 클릭 가능 모드 */
#monthLabel.picker-mode { background: rgba(255,255,255,.2); border-radius: 6px; padding: 3px 8px; cursor: pointer; }
#monthLabel.picker-mode::after { content: ' ▾'; font-size: 10px; opacity: .8; }
/* 기간 선택 모달 */
.daterange-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 600; align-items: center; justify-content: center; padding: 20px; }
.daterange-overlay.show { display: flex; }
.daterange-modal { background: #fff; border-radius: 16px; width: 100%; max-width: 340px; overflow: hidden; }
.daterange-hd { background: var(--p); padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
.daterange-hd-title { color: #fff; font-size: 16px; font-weight: 700; }
.daterange-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.daterange-body { padding: 20px; }
.daterange-row { margin-bottom: 14px; }
.daterange-row label { display: block; font-size: 12px; font-weight: 700; color: #757575; margin-bottom: 6px; }
.daterange-row input { width: 100%; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 12px; font-size: 15px; outline: none; font-family: inherit; }
.daterange-row input:focus { border-color: var(--accent); }
.daterange-presets { display: flex; gap: 6px; margin-bottom: 4px; }
.preset-btn { flex: 1; padding: 7px 0; border: 1px solid #e0e0e0; border-radius: 6px; background: #f5f5f5; font-size: 12px; font-weight: 600; color: #616161; cursor: pointer; font-family: inherit; }
.preset-btn:active { background: #eceff1; }
.daterange-apply { display: block; width: calc(100% - 40px); margin: 0 20px 20px; background: var(--p); color: #fff; border: none; border-radius: var(--r); padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; }
.daterange-apply:active { opacity: .85; }
.stats-filter-btn { flex: 1; padding: 9px 0; border: none; background: none; border-radius: 8px; font-size: 14px; font-weight: 700; color: #9e9e9e; cursor: pointer; font-family: inherit; transition: all .2s; }
.stats-filter-btn.on { background: #fff; color: var(--accent); box-shadow: 0 1px 6px rgba(0,0,0,.13); }
.donut-section { margin: 10px 16px 0; background: var(--surface); border-radius: 16px; padding: 20px 16px 18px; box-shadow: 0 2px 12px rgba(0,0,0,.07); overflow: visible; }
.donut-period-label { text-align: center; font-size: 12px; color: #9e9e9e; margin-bottom: 8px; }
.donut-canvas-wrap { position: relative; width: 190px; margin: 0 auto; overflow: visible; }
.donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.donut-center-label { font-size: 11px; color: #9e9e9e; }
.donut-center-amt { font-size: 19px; font-weight: 700; color: #212121; margin-top: 3px; }
.donut-empty { text-align: center; padding: 50px 20px; color: #bdbdbd; font-size: 15px; line-height: 1.8; }
#donutTooltip {
  position: fixed; pointer-events: none; z-index: 9999;
  background: rgba(40,40,40,.88); color: #fff; border-radius: 8px;
  padding: 6px 10px; display: flex; align-items: center; gap: 6px;
  font-size: 12px; white-space: nowrap; opacity: 0;
  transition: opacity .15s; backdrop-filter: blur(4px);
}
.dtt-name { font-weight: 700; }
.dtt-amt  { font-weight: 600; }
.dtt-pct  { opacity: .7; font-size: 11px; }
.ranking-section { margin: 10px 16px 0; background: var(--surface); border-radius: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
.ranking-header { padding: 14px 16px 10px; font-size: 13px; font-weight: 700; color: #757575; border-bottom: 1px solid #f5f5f5; }
.ranking-item { display: flex; align-items: center; gap: 12px; padding: 18px 16px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background .2s; }
.ranking-item:last-child { border-bottom: none; }
.ranking-item:active { background: #f5f5f5; }
.ranking-item.highlighted { background: #EEF4FF; }
.ranking-item.highlighted .rank-name { color: #2979FF; font-weight: 700; }
.rank-num { width: 18px; font-size: 12px; font-weight: 700; color: #bdbdbd; text-align: center; flex-shrink: 0; }
.rank-num.top { color: var(--p); }
.rank-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.rank-icon { font-size: 22px; width: 28px; text-align: center; flex-shrink: 0; }
.rank-info { flex: 1; min-width: 0; }
.rank-name { font-size: 14px; font-weight: 600; color: #212121; }
.rank-bar-wrap { margin-top: 5px; height: 4px; background: #f0f0f0; border-radius: 2px; overflow: hidden; }
.rank-bar { height: 100%; border-radius: 2px; transition: width .5s ease; }
.rank-right { text-align: right; flex-shrink: 0; }
.rank-pct { font-size: 11px; color: #9e9e9e; }
.rank-amt { font-size: 14px; font-weight: 700; color: var(--expense); }

/* ── 카테고리 상세 시트 ── */
.catdet-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 450; align-items: flex-end; justify-content: center; }
.catdet-overlay.show { display: flex; }
.catdet-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; max-height: 75vh; display: flex; flex-direction: column; }
.catdet-hd { background: var(--p); border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.catdet-title { color: #fff; font-size: 16px; font-weight: 700; }
.catdet-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.catdet-body { overflow-y: auto; padding-bottom: 16px; }

/* ── 결산 ── */
@keyframes widgetIn    { from { opacity:0; transform:translateY(-10px) scale(.97); } to { opacity:1; transform:translateY(0) scale(1); } }
@keyframes widgetOut   { from { opacity:1; transform:scale(1); } to { opacity:0; transform:scale(.94) translateY(-5px); } }
@keyframes editPanelIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
/* champ body month nav */
.champ-mnav { display:flex; align-items:center; justify-content:flex-end; gap:0; margin-bottom:10px; }
.champ-mnav-btn { background:none; border:none; font-size:22px; color:#90A4AE; cursor:pointer; padding:2px 8px; line-height:1; font-family:inherit; transition:opacity .15s; }
.champ-mnav-btn:active { opacity:.6; }
.champ-mnav-btn:disabled { color:#e0e0e0; cursor:default; }
.champ-mnav-label { font-size:12px; color:#607D8B; font-weight:700; min-width:80px; text-align:center; }
.report-month-label { font-size:15px; font-weight:700; color:#212121; min-width:90px; text-align:center; }
.report-wrap { padding: 8px 16px 100px; display: flex; flex-direction: column; gap: 14px; }
/* 공통 위젯 카드 */
.widget-card { background:var(--surface); border-radius:var(--r); box-shadow:var(--shadow); overflow:hidden; animation:widgetIn .3s cubic-bezier(.22,1,.36,1); position:relative; }
/* 위젯 메뉴 버튼 (···) */
.widget-menu-btn { position:absolute; top:8px; right:10px; background:none; border:none; cursor:pointer; padding:4px 6px; color:#d0d0d0; font-size:18px; letter-spacing:1px; line-height:1; z-index:2; border-radius:6px; transition:color .15s, background .15s; font-family:inherit; }
.widget-menu-btn:hover, .widget-menu-btn:active { color:#757575; background:rgba(0,0,0,.05); }
/* 위젯 팝오버 */
@keyframes popoverIn { from { opacity:0; transform:scale(.9) translateY(-4px); } to { opacity:1; transform:scale(1) translateY(0); } }
.widget-popover { position:fixed; background:#fff; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.18); min-width:136px; z-index:700; overflow:hidden; animation:popoverIn .15s ease; display:none; }
.wpop-item { display:flex; align-items:center; gap:10px; padding:12px 14px; font-size:14px; font-weight:600; color:#212121; cursor:pointer; border-bottom:1px solid #f5f5f5; white-space:nowrap; transition:background .1s; }
.wpop-item:last-child { border-bottom:none; }
.wpop-item:active { background:#f5f5f5; }
.wpop-item.disabled { color:#c8c8c8; pointer-events:none; }
.wpop-item.danger { color:#EF4444; }
/* 편집 패널 추가 버튼 */
.edit-row-plus { font-size:18px; color:var(--accent); font-weight:400; flex-shrink:0; }
/* 인사이트 */
.rc-body { padding:20px; }
.rc-tag { display:inline-block; font-size:11px; font-weight:800; padding:3px 10px; border-radius:20px; margin-bottom:10px; letter-spacing:.3px; }
.rc-tag.good    { background:#e8f5e9; color:#2e7d32; }
.rc-tag.warn    { background:#fff3e0; color:#e65100; }
.rc-tag.danger  { background:#ffebee; color:#c62828; }
.rc-tag.neutral { background:#f5f5f5; color:#757575; }
.rc-emoji { font-size:34px; text-align:center; margin-bottom:10px; }
.rc-insight { font-size:15px; font-weight:700; color:#212121; text-align:center; line-height:1.6; }
.rc-sub { font-size:12px; color:#9e9e9e; text-align:center; margin-top:6px; }
.rc-compare { display:flex; align-items:center; justify-content:center; gap:10px; margin-top:14px; padding-top:14px; border-top:1px solid #f5f5f5; }
.rc-cmp-col { text-align:center; }
.rc-cmp-label { font-size:11px; color:#9e9e9e; margin-bottom:4px; }
.rc-cmp-val { font-size:17px; font-weight:700; color:#212121; }
.rc-cmp-val.up   { color:var(--expense); }
.rc-cmp-val.down { color:var(--income); }
.rc-cmp-val.this-month { font-size:20px; color:var(--accent); }
.rc-cmp-arr { font-size:20px; color:#bdbdbd; }
/* 챔피언 / 후회 분석 */
.champ-header { background:linear-gradient(135deg,var(--p) 0%,var(--p-mid) 100%); padding:12px 16px; }
.champ-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.champ-body { padding:14px 16px 16px; }
.champ-row { display:flex; align-items:center; gap:12px; }
.champ-left { display:flex; align-items:center; gap:10px; flex:1; min-width:0; }
.champ-emoji { flex-shrink:0; display:flex; }
.champ-info { min-width:0; }
.champ-name { font-size:15px; font-weight:800; color:#212121; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.champ-cat  { font-size:11px; color:#9e9e9e; margin-top:2px; }
.champ-right { text-align:right; flex-shrink:0; }
.champ-amt  { font-size:22px; font-weight:900; color:var(--accent); }
.champ-date { font-size:11px; color:#bdbdbd; margin-top:3px; }
.champ-pct-msg { font-size:12px; color:#9e9e9e; margin-top:10px; line-height:1.5; }
.champ-pct-msg span { font-weight:700; color:var(--accent); }
.champ-feel-btns { display:flex; gap:8px; margin-top:12px; }
.champ-feel-btn { flex:1; padding:9px 0; border:2px solid #e0e0e0; border-radius:12px; background:#fafafa; font-size:13px; font-weight:700; color:#757575; cursor:pointer; transition:all .2s; }
.champ-feel-btn:active { opacity:.8; }
.champ-feel-btn.active.ok   { border-color:#43A047; background:#e8f5e9; color:#2e7d32; }
.champ-feel-btn.active.regret { border-color:#EF4444; background:#ffebee; color:#c62828; }
.widget-card.champ-regret { box-shadow:0 0 0 2px #ef9a9a, 0 4px 20px rgba(229,57,53,.18); }
/* 요일 */
.dow-body { padding:20px; }
.dow-title { font-size:13px; font-weight:700; color:#424242; margin-bottom:14px; }
.dow-bars { display:flex; align-items:flex-end; gap:6px; height:80px; position:relative; }
.dow-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; position:relative; cursor:pointer; }
.dow-bar { width:100%; border-radius:4px 4px 0 0; background:#CFD8DC; transition:height .4s ease; min-height:4px; }
.dow-bar.peak  { background:linear-gradient(to top,#1D2C55,#2979FF); }
.dow-bar-label { font-size:10px; color:#9e9e9e; font-weight:600; }
.dow-bar-label.peak  { color:var(--accent); font-weight:800; }
.dow-crown { position:absolute; top:-18px; left:50%; transform:translateX(-50%); font-size:13px; line-height:1; pointer-events:none; }
.dow-tip { position:fixed; background:rgba(30,30,30,.88); color:#fff; font-size:12px; font-weight:700; padding:5px 10px; border-radius:8px; pointer-events:none; white-space:nowrap; z-index:800; opacity:0; transition:opacity .15s; }
@keyframes toastIn { from { opacity:0; transform:translateX(-50%) translateY(10px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }
@keyframes toastOut { from { opacity:1; } to { opacity:0; } }
.app-toast { position:fixed; bottom:80px; left:50%; transform:translateX(-50%); background:rgba(30,30,30,.9); color:#fff; font-size:13px; font-weight:700; padding:10px 20px; border-radius:24px; z-index:900; pointer-events:none; white-space:nowrap; animation:toastIn .25s ease; }
.dow-avg-line { position:absolute; left:0; right:0; border-top:1.5px dashed #90A4AE; pointer-events:none; }
.dow-avg-tag { position:absolute; right:0; font-size:9px; color:#607D8B; font-weight:700; background:#fff; padding:0 3px; transform:translateY(-100%); white-space:nowrap; }
.dow-insight { margin-top:14px; padding-top:14px; border-top:1px solid #f5f5f5; font-size:14px; color:#424242; line-height:1.9; text-align:center; }
.dow-insight .hi-amt  { font-weight:800; color:#00897B; }
.dow-insight .lo-cnt  { font-size:12px; color:#bdbdbd; }
/* 편집 패널 */
.report-edit-panel { background:#fff; border-radius:16px; box-shadow:0 1px 8px rgba(0,0,0,.07); overflow:hidden; animation:editPanelIn .28s cubic-bezier(.22,1,.36,1); }
.edit-panel-title { padding:13px 16px 11px; font-size:12px; font-weight:700; color:#9e9e9e; letter-spacing:.5px; border-bottom:1px solid #f5f5f5; }
.edit-row { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px solid #f5f5f5; }
.edit-row:last-child { border-bottom:none; }
.edit-row-handle { font-size:18px; color:#bdbdbd; cursor:grab; user-select:none; flex-shrink:0; }
.edit-row-icon   { font-size:20px; flex-shrink:0; }
.edit-row-label  { flex:1; font-size:14px; font-weight:600; color:#424242; }
/* 토글 스위치 */
.toggle-wrap { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
.toggle-input { opacity:0; width:0; height:0; position:absolute; }
.toggle-slider { position:absolute; cursor:pointer; inset:0; background:#ddd; border-radius:26px; transition:background .25s; }
.toggle-slider::before { content:''; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .28s cubic-bezier(.22,1,.36,1); box-shadow:0 1px 4px rgba(0,0,0,.2); }
.toggle-input:checked + .toggle-slider { background:var(--p); }
.toggle-input:checked + .toggle-slider::before { transform:translateX(20px); }
/* 빈 상태 */
.report-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 24px; text-align:center; }
.report-empty-ico { font-size:52px; margin-bottom:14px; opacity:.5; }
.report-empty-msg { font-size:15px; color:#9e9e9e; line-height:1.7; }
/* 하단 고정 편집 바 */
.report-edit-bar { position:fixed; bottom:62px; left:50%; transform:translateX(-50%); width:100%; max-width:480px; z-index:150; background:rgba(255,255,255,.95); backdrop-filter:blur(6px); padding:10px 16px 12px; box-shadow:0 -2px 12px rgba(0,0,0,.08); }
.report-edit-btn { display:block; width:100%; background:#EEF4FF; color:var(--accent); border:none; border-radius:var(--r); padding:13px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .15s; }
.report-edit-btn.on { background:var(--accent); color:#fff; }
.report-edit-btn:active { opacity:.85; }
/* 생존 가이드 */
.surv-header { background:linear-gradient(135deg,#00796B,#00897B); padding:12px 16px; transition:background .3s; }
.surv-header.danger { background:linear-gradient(135deg,#c62828,#EF4444); }
.surv-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.surv-body { padding:16px 20px 20px; text-align:center; transition:background .3s; }
.widget-card.surv-danger { background:#fff5f5; }
.surv-remaining { font-size:11px; color:#9e9e9e; margin-bottom:6px; }
.surv-remaining-amt { font-size:28px; font-weight:900; color:#212121; margin-bottom:14px; }
.surv-remaining-amt.positive { color:#00796B; }
.surv-remaining-amt.negative { color:#EF4444; }
.surv-divider { height:1px; background:#f5f5f5; margin:0 0 14px; }
.surv-msg { font-size:14px; font-weight:600; color:#424242; line-height:1.7; }
/* 서바이벌 목표 탭 / 입력 */
.surv-tabs { display:flex; gap:3px; background:#f5f5f5; border-radius:8px; padding:3px; margin-bottom:12px; }
.surv-tab { flex:1; padding:6px 0; font-size:12px; font-weight:700; border:none; background:none; border-radius:6px; color:#9e9e9e; cursor:pointer; transition:all .15s; font-family:inherit; }
.surv-tab.active { background:#fff; color:#00796B; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.surv-input-row { display:flex; align-items:center; gap:6px; margin-bottom:10px; }
.surv-input-label { font-size:11px; color:#9e9e9e; flex-shrink:0; white-space:nowrap; }
.surv-input { flex:1; border:1px solid #e0e0e0; border-radius:6px; padding:7px 10px; font-size:14px; font-weight:700; color:#212121; text-align:right; outline:none; font-family:inherit; min-width:0; }
.surv-input:focus { border-color:#00796B; }
.surv-input-unit { font-size:12px; color:#9e9e9e; flex-shrink:0; }
.surv-progress-wrap { height:6px; background:#eee; border-radius:3px; margin-bottom:5px; overflow:hidden; }
.surv-progress-bar { height:100%; background:linear-gradient(to right,#00796B,#26A69A); border-radius:3px; transition:width .5s ease; }
.surv-progress-bar.warn { background:linear-gradient(to right,#F57C00,#FFA726); }
.surv-progress-bar.danger { background:linear-gradient(to right,#c62828,#EF4444); }
.surv-progress-labels { display:flex; justify-content:space-between; font-size:10px; color:#9e9e9e; margin-bottom:12px; }
.surv-period-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.surv-period-btn { background:none; border:none; font-size:20px; color:var(--accent); cursor:pointer; padding:2px 8px; border-radius:6px; font-family:inherit; line-height:1; transition:color .15s; }
.surv-period-btn:disabled { color:#d0d0d0; cursor:default; }
.surv-period-label { font-size:12px; font-weight:700; color:#424242; text-align:center; flex:1; }
/* 카테고리 TOP 3 */
.top3-header { background:linear-gradient(135deg,#6A1B9A,#8E24AA); padding:12px 16px; }
.top3-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.top3-body { padding:16px 20px 20px; }
.top3-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f5f5f5; }
.top3-row:last-child { border-bottom:none; }
.top3-rank { font-size:20px; width:28px; text-align:center; flex-shrink:0; }
.top3-info { flex:1; min-width:0; }
.top3-cat  { font-size:14px; font-weight:700; color:#212121; }
.top3-sub  { font-size:11px; color:#9e9e9e; margin-top:2px; }
.top3-right { text-align:right; flex-shrink:0; }
.top3-amt  { font-size:15px; font-weight:800; color:var(--accent); }
.top3-pct  { font-size:11px; color:#9e9e9e; margin-top:2px; }
.top3-bar-wrap { height:4px; background:#f0f0f0; border-radius:2px; margin-top:6px; }
.top3-bar { height:4px; border-radius:2px; background:linear-gradient(to right,#7B1FA2,#CE93D8); }
.top3-empty { text-align:center; padding:24px 0; color:#bdbdbd; font-size:14px; }
/* 소비 MBTI */
.mbti-header { background:linear-gradient(135deg,#7B1FA2,#9C27B0); padding:12px 16px; }
.mbti-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.mbti-body { padding:16px 20px 20px; text-align:center; }
.mbti-emoji { font-size:40px; margin-bottom:6px; line-height:1; }
.mbti-code { font-size:34px; font-weight:900; color:#6D28D9; letter-spacing:5px; margin-bottom:4px; font-family:monospace, sans-serif; }
.mbti-title { font-size:15px; font-weight:700; color:#212121; margin-bottom:10px; }
.mbti-desc { font-size:13px; color:#757575; line-height:1.75; margin-bottom:10px; padding:0 4px; }
.mbti-budget { font-size:13px; font-weight:600; color:var(--accent); padding:8px 12px; background:#EEF4FF; border-radius:8px; margin-bottom:12px; line-height:1.5; }
.mbti-top3 { display:flex; justify-content:center; gap:6px; flex-wrap:wrap; }
.mbti-badge { font-size:11px; background:#f3e5f5; border-radius:20px; padding:4px 10px; color:#7B1FA2; font-weight:700; }

/* ── 나 ── */
/* ── 나 탭 ── */
#pane-me { overflow: hidden; height: calc(100vh - 56px - 64px); padding-bottom: 0 !important; }
.me-wrap { position: relative; height: 100%; overflow: hidden; }
.me-subpage { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: var(--bg); opacity: 0; pointer-events: none; transition: opacity .18s ease; overflow-y: auto; z-index: 10; }
.me-subpage.active { opacity: 1; pointer-events: auto; }
.me-subpage-hd { display: flex; align-items: center; padding: 0 16px; height: 56px; background: #364A6D; flex-shrink: 0; position: sticky; top: 0; z-index: 1; }
.me-subpage-back { background: none; border: none; cursor: pointer; padding: 4px; color: #fff; display: flex; align-items: center; margin-right: 8px; }
.me-subpage-back svg { width: 22px; height: 22px; stroke-width: 2; color: #fff; }
.me-subpage-title { font-size: 17px; font-weight: 700; color: #fff; }
.me-profile { background: var(--bg); padding: 0 20px 20px; display: flex; flex-direction: column; align-items: center; gap: 0; }
.me-profile-banner { width: calc(100% + 40px); height: 80px; background: #364A6D; margin: 0 -20px; flex-shrink: 0; }
.me-app-title { display: none; }
.me-avatar { width: 90px; height: 90px; border-radius: 50%; background: #D1D5DB; border: 3px solid #fff; display: flex; align-items: center; justify-content: center; margin-top: -45px; margin-bottom: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.15); overflow: hidden; }
.me-name  { font-size: 18px; font-weight: 800; color: var(--text1); margin-bottom: 4px; text-align: center; }
.me-email { font-size: 13px; color: var(--text2); margin-top: 0; text-align: center; margin-bottom: 14px; }
.me-streak { display: none; }
.me-login-btn { margin-top: 8px; background: var(--p); border: none; color: #fff; border-radius: 20px; padding: 10px 28px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-block; margin-bottom: 14px; }
.me-stats-row { display: flex; width: 100%; background: #fff; border-radius: 14px; box-shadow: var(--shadow); border: 1.5px solid var(--border); overflow: hidden; margin-bottom: 4px; }
.me-stat-col { flex: 1; padding: 14px 8px; text-align: center; }
.me-stat-col:first-child { border-right: 1px solid var(--border); }
.me-stat-num { font-size: 24px; font-weight: 900; color: var(--text1); }
.me-stat-label { font-size: 11px; color: var(--text2); margin-top: 3px; }
.me-stat-streak-text { font-size: 14px; font-weight: 700; color: var(--text1); margin-bottom: 2px; }
.me-section { margin: 20px 16px 0; background: var(--surface); border-radius: var(--r); overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); }
.me-section-title { font-size: 12px; font-weight: 800; color: var(--text2); letter-spacing: .5px; padding: 14px 16px 8px; }
.me-row { display: flex; align-items: center; gap: 12px; padding: 16px 16px; border-top: 1px solid var(--border); cursor: pointer; transition: background .1s; }
.me-row:first-of-type { border-top: none; }
.me-row:active { background: #f5f5f5; }
.me-row-ico { font-size: 18px; width: 28px; text-align: center; flex-shrink: 0; display:flex; align-items:center; justify-content:center; }
.me-row-ico svg { width:18px; height:18px; stroke-width:1.75; color:var(--text2); }
.me-row-label { flex: 1; font-size: 15px; font-weight: 600; color: #212121; }
.me-row-value { font-size: 12px; color: #9e9e9e; }
.me-row-arrow { font-size: 16px; color: #c8c8c8; }
.me-row.danger .me-row-label { color: #EF4444; }
.me-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 20px 16px 0; }
.me-grid-item { background: var(--surface); border-radius: 16px; padding: 20px 8px 16px; display: flex; flex-direction: column; align-items: center; gap: 10px; cursor: pointer; box-shadow: var(--shadow); border: 1px solid var(--border); transition: background .12s; }
.me-grid-item:active { background: #eef2ff; }
.me-grid-icon { width: 48px; height: 48px; border-radius: 50%; background: #EEF1FB; display: flex; align-items: center; justify-content: center; }
.me-grid-icon svg { width: 22px; height: 22px; stroke-width: 1.75; color: #364B6D; }
.me-grid-label { font-size: 13px; font-weight: 700; color: var(--text1); text-align: center; }
.me-footer { text-align: center; padding: 28px 20px 16px; font-size: 12px; color: #bdbdbd; line-height: 1.7; }

/* ── 하단 탭바 ── */
.tab-bar { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 480px; background: #ffffff; border-top: 1px solid #F1F5F9; display: flex; height: 64px; z-index: 200; box-shadow: 0 -4px 20px rgba(0,0,0,.05); }
.t-btn { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; font-size: 10px; color: #94A3B8; gap: 3px; padding: 0; }
.t-btn .ico { font-size: 22px; }
.t-btn .ico-sv { width:22px; height:22px; stroke-width:1.5; display:block; }
.t-btn.on { color: #1E293B; font-weight: 700; }
.fab-wrap { flex: 1; display: flex; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; padding: 0; }
.fab { width: 58px; height: 58px; border-radius: 50%; background: #364B6D; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 32px; line-height: 1; margin-top: -20px; box-shadow: 0 6px 20px rgba(30,41,59,.35); transition: transform .15s; }
.fab:active { transform: scale(.92); }

/* ── 내역 액션 시트 ── */
.txa-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 450; align-items: flex-end; justify-content: center; }
.txa-overlay.show { display: flex; }
.txa-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; padding-bottom: 28px; }
.txa-hd { background: var(--p); border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
.txa-hd-title { color: #fff; font-size: 16px; font-weight: 700; }
.txa-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.txa-summary { display: flex; align-items: center; gap: 14px; padding: 20px 20px; border-bottom: 1px solid #f0f0f0; }
.txa-icon { width: 46px; height: 46px; border-radius: 50%; background: var(--p-light); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; color: var(--p); }
.txa-mid { flex: 1; min-width: 0; }
.txa-desc { font-size: 15px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txa-sub  { font-size: 12px; color: #9e9e9e; margin-top: 3px; }
.txa-amt  { font-size: 16px; font-weight: 700; white-space: nowrap; }
.txa-amt.expense { color: var(--expense); }
.txa-amt.income  { color: var(--income); }
/* ── 사진 캐러셀 (액션시트/상세) ── */
.photo-carousel-wrap { position: relative; margin: 10px 20px 0; border-radius: 12px; overflow: hidden; background: #f0f0f0; border: 1px solid #e0e0e0; touch-action: pan-y; }
.photo-carousel-inner { display: flex; transition: transform .28s ease; will-change: transform; }
.photo-carousel-inner img { width: 100%; flex-shrink: 0; max-height: 180px; object-fit: contain; cursor: zoom-in; border-radius: 0; display: block; background: #f0f0f0; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); }
.photo-carousel-dots { display: flex; justify-content: center; gap: 7px; padding: 7px 0 2px; }
.photo-carousel-dot { width: 7px; height: 7px; border-radius: 50%; background: #d0d0d0; transition: background .2s, transform .2s; }
.photo-carousel-dot.on { background: var(--p); transform: scale(1.3); }
/* ── 모달 다중사진 ── */
.photo-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 13px; }
.photo-grid-item { position: relative; width: 68px; height: 68px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; flex-shrink: 0; background: #f5f5f5; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.photo-grid-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-grid-x { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,.58); color: #fff; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; padding: 0; }
.photo-add-btn { width: 68px; height: 68px; border-radius: 8px; border: 1.5px dashed #bbb; background: #f5f5f5; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; flex-shrink: 0; color: #aaa; }
.txa-menu { margin-top: 4px; }
.txa-item { display: flex; align-items: center; gap: 14px; padding: 18px 22px; font-size: 15px; cursor: pointer; border-bottom: 1px solid #f5f5f5; }
.txa-item:active { background: #f5f5f5; }
.txa-item .txa-ico { font-size: 20px; width: 24px; text-align: center; }
.txa-item.danger { color: #EF4444; }

/* ── 상세정보 오버레이 ── */
.detail-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 460; align-items: flex-end; justify-content: center; }
.detail-overlay.show { display: flex; }
.detail-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; padding-bottom: 32px; }
.detail-hd { background: var(--p); border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
.detail-hd-title { color: #fff; font-size: 16px; font-weight: 700; }
.detail-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.detail-row { display: flex; justify-content: space-between; align-items: center; padding: 13px 20px; border-bottom: 1px solid #f5f5f5; }
.detail-key { font-size: 13px; color: #9e9e9e; }
.detail-val { font-size: 14px; font-weight: 600; color: #212121; text-align: right; max-width: 60%; }
.detail-photo-wrap { padding: 14px 20px 0; }
.detail-photo-wrap img { width: 100%; max-height: 200px; object-fit: cover; border-radius: 10px; cursor: zoom-in; }

/* ── 검색 오버레이 ── */
.search-overlay { display: none; position: fixed; inset: 0; background: #fff; z-index: 600; flex-direction: column; }
.search-overlay.show { display: flex; }
.search-bar { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--p); }
.search-input { flex: 1; border: none; border-radius: 8px; padding: 10px 14px; font-size: 15px; outline: none; font-family: inherit; }
.search-close { background: none; border: none; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; white-space: nowrap; padding: 4px 8px; }
.search-results { flex: 1; overflow-y: auto; padding-bottom: 20px; }
.search-empty { text-align: center; padding: 60px 20px; color: #bdbdbd; font-size: 15px; }

/* ── 사진 풀스크린 뷰어 ── */
.photo-viewer { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 900; align-items: center; justify-content: center; }
.photo-viewer.show { display: flex; }
.photo-viewer img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; }
.photo-viewer-x { position: absolute; top: 16px; right: 18px; background: rgba(255,255,255,.15); border: none; color: #fff; font-size: 28px; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }

/* ── 모달 ── */
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 500; align-items: center; justify-content: center; padding: 16px; }
.overlay.show { display: flex; }
.overlay.show .modal { animation: fadeScaleIn .22s cubic-bezier(.22,1,.36,1); }
.overlay.show .modal,
.txa-overlay.show .txa-sheet,
.day-sheet-overlay.show .day-sheet,
.catdet-overlay.show .catdet-sheet,
.detail-overlay.show .detail-sheet { animation: slideUp .25s cubic-bezier(.22,1,.36,1); }
.center-overlay.show .center-modal { animation: fadeIn .2s ease; }
.modal { background: var(--surface); border-radius: 20px; width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; padding-bottom: 28px; }
.modal-hd { background: var(--p); border-radius: 20px 20px 0 0; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
.modal-hd-title { color: #fff; font-size: 17px; font-weight: 700; }
.modal-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 26px; cursor: pointer; line-height: 1; }
.type-row { display: flex; margin: 16px 20px 0; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; }
.type-t { flex: 1; padding: 10px; border: none; background: #f5f5f5; font-size: 14px; font-weight: 600; color: #9e9e9e; cursor: pointer; }
.type-t.on.e { background: var(--expense); color: #fff; }
.type-t.on.i { background: var(--income); color: #fff; }
.mform { padding: 14px 20px 0; }
.mf-label { font-size: 12px; font-weight: 700; color: #757575; margin-bottom: 5px; display: block; }
.mf-input, .mf-select { width: 100%; border: 1px solid #e0e0e0; border-radius: 8px; padding: 11px 14px; font-size: 15px; outline: none; margin-bottom: 13px; font-family: inherit; background: #fff; }
.mf-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239e9e9e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.mf-input:focus, .mf-select:focus { border-color: var(--accent); }

/* 카테고리 행 (select + 추가버튼) */
.cat-row-wrap { display: flex; gap: 8px; margin-bottom: 13px; }
.cat-row-wrap .mf-select { margin-bottom: 0; flex: 1; }
.cat-add-btn { background: var(--p); color: #fff; border: none; border-radius: 8px; padding: 0 14px; font-size: 20px; cursor: pointer; flex-shrink: 0; }
.cat-add-btn:active { opacity: .8; }
/* 커스텀 카테고리 드롭다운 */
.cat-custom-select { position: relative; flex: 1; }
.cat-cs-trigger { width: 100%; border: 1px solid #e0e0e0; border-radius: 8px; padding: 11px 12px 11px 14px; font-size: 15px; background: #fff; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 6px; font-family: inherit; color: #212121; }
.cat-cs-trigger:focus { outline: none; border-color: var(--accent); }
.cat-cs-trigger span:first-child { flex: 1; }
.cat-cs-arrow { color: #9e9e9e; flex-shrink: 0; display: flex; align-items: center; }
.cat-cs-dropdown { display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 550; max-height: 220px; overflow-y: auto; }
.cat-cs-dropdown.open { display: block; }
.cat-cs-option { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; transition: background .1s; }
.cat-cs-option:hover { background: #f5f7ff; }
.cat-cs-option.selected { background: #EEF4FF; font-weight: 700; }
.cat-cs-option-icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cat-cs-option-name { flex: 1; font-size: 14px; color: #212121; }
.cat-cs-del { background: #fee2e2; border: none; cursor: pointer; color: #ef4444; padding: 5px 10px; border-radius: 6px; font-size: 18px; font-weight: 700; line-height: 1; flex-shrink: 0; }
.cat-cs-del:hover { background: #fecaca; }
body.dark .cat-cs-trigger { background: #1a2638; border-color: #263447; color: #e0e0e0; }
body.dark .cat-cs-dropdown { background: #1a2638; border-color: #263447; }
body.dark .cat-cs-option:hover { background: #1e2d3f; }
body.dark .cat-cs-option.selected { background: #1e2d3f; }

/* 새 카테고리 입력 영역 */
.new-cat-box { display: none; background: #f9fbe7; border: 1px solid #e6ee9c; border-radius: 8px; padding: 12px; margin-bottom: 13px; }
.new-cat-box.show { display: block; }
.new-cat-row { display: flex; gap: 8px; align-items: center; }
.nc-icon-btn { background: none; border: none; padding: 0; cursor: pointer; flex-shrink: 0; }
.nc-icon-preview { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform .15s; }
.nc-icon-btn:active .nc-icon-preview { transform: scale(.88); }
.new-cat-name  { flex: 1; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: inherit; outline: none; background: #fff; }
.new-cat-name:focus { border-color: var(--accent); }
.new-cat-save { background: var(--p); color: #fff; border: none; border-radius: 8px; padding: 0 14px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; height: 40px; }
.new-cat-save:active { opacity: .8; }
/* 아이콘 피커 */
.icon-picker-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 600; align-items: center; justify-content: center; padding: 16px; }
.icon-picker-overlay.show { display: flex; }
.icon-picker-box { background: #fff; border-radius: 20px; width: 100%; max-width: 360px; max-height: 75vh; display: flex; flex-direction: column; overflow: hidden; animation: fadeScaleIn .2s cubic-bezier(.22,1,.36,1); }
.icon-picker-hd { padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f0; flex-shrink: 0; }
.icon-picker-title { font-size: 15px; font-weight: 700; color: #212121; }
.icon-picker-x { background: none; border: none; font-size: 24px; cursor: pointer; color: #9e9e9e; line-height: 1; padding: 0 2px; }
.icon-picker-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; padding: 16px; overflow-y: auto; }
.icon-opt { display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; padding: 6px 4px; border-radius: 12px; transition: background .15s; }
.icon-opt:active { background: #f0f0f0; }
.icon-opt.selected { background: #EEF4FF; }
.icon-opt-circle { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.icon-opt.selected .icon-opt-circle { outline: 3px solid var(--accent); outline-offset: 2px; }
.icon-opt-label { font-size: 11px; color: #555; text-align: center; line-height: 1.2; }

/* 내용 + 사진 */
.desc-row { display: flex; gap: 8px; margin-bottom: 13px; }
.desc-row .mf-input { margin-bottom: 0; flex: 1; }
.photo-btn { background: #eceff1; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0 12px; font-size: 22px; cursor: pointer; flex-shrink: 0; }
.photo-btn:active { background: #cfd8dc; }
.photo-preview { display: none; margin-bottom: 13px; position: relative; }
.photo-preview img { width: 100%; max-height: 160px; object-fit: cover; border-radius: 10px; }
.photo-remove { position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,.55); color: #fff; border: none; border-radius: 50%; width: 26px; height: 26px; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

.modal-save { display: block; width: calc(100% - 40px); margin: 6px 20px 0; background: var(--accent); color: #fff; border: none; border-radius: var(--r); padding: 15px; font-size: 16px; font-weight: 500; cursor: pointer; box-shadow: 0 4px 12px rgba(41,121,255,.3); transition: opacity .15s; }
.modal-save:active { opacity: .85; }

/* ── 다크모드 ── */
body.dark { background:#0d1117; color:#cbd5e1; }
body.dark .app-header { background:linear-gradient(135deg,#0F172A,#1e293b); }
body.dark .app-header.ledger-mode { background: #0F172A !important; }
body.dark .summary-card { background:linear-gradient(135deg,#1e293b,#0f172a); }
body.dark .tx-row { background:#131c27; border-bottom-color:#1e293b; }
body.dark .tx-row:active { background:#1a2638; }
body.dark .tx-desc { color:#cbd5e1; }
body.dark .tx-icon { background:#1e293b; }
body.dark .date-header { background:#0d1117; color:#64748b; }
body.dark .tab-bar { background:#131c27; border-top-color:#1e293b; }
body.dark .sum-strip { background:#131c27; border-bottom-color:#1e293b; }
body.dark .sum-strip .month-btn { color:#cbd5e1; }
body.dark .sum-strip #monthLabel { color:#cbd5e1; }
body.dark .sum-col { background:#1a2638; border-color:#263447; }
body.dark .sum-col-label { color:#64748b; }
body.dark .sum-col-value { color:#e0e0e0; }
body.dark .me-profile { background:#131c27; border-bottom-color:#1e293b; }
body.dark .me-app-title { background:linear-gradient(135deg,#1e293b,#0f172a); }
body.dark .me-avatar { background:#263447; border-color:#1e293b; }
body.dark .me-name { color:#e0e0e0; }
body.dark .me-email { color:#64748b; }
body.dark .me-stats-row { background:#1a2638; border-color:#263447; }
body.dark .me-stat-col:first-child { border-right-color:#263447; }
body.dark .me-stat-num { color:#e0e0e0; }
body.dark .me-stat-streak-text { color:#e0e0e0; }
body.dark .me-section { border-color:#1e293b; }
body.dark .t-btn { color:#475569; }
body.dark .t-btn.on { color:#94a3b8; }
body.dark .me-section { background:#131c27; }
body.dark .me-row { border-top-color:#1e293b; }
body.dark .me-row:active { background:#1a2638; }
body.dark .me-row-label { color:#cbd5e1; }
body.dark .me-section-title { color:#475569; }
body.dark .me-footer { color:#334155; }
body.dark .me-grid-item { background:#131c27; border-color:#1e293b; }
body.dark .me-grid-item:active { background:#1a2638; }
body.dark .me-grid-icon { background:#1e2d42; }
body.dark .me-grid-icon svg { color:#94a3b8; }
body.dark .me-grid-label { color:#cbd5e1; }
body.dark .widget-card { background:#131c27; }
body.dark .edit-row { border-bottom-color:#1e293b; background:#131c27; }
body.dark .edit-row-label { color:#c0c0c0; }
body.dark .report-edit-panel { background:#131c27; }
body.dark .modal { background:#131c27; }
body.dark .txa-sheet { background:#131c27; }
body.dark .day-sheet { background:#131c27; }
body.dark .catdet-sheet { background:#131c27; }
body.dark .detail-sheet { background:#131c27; }
body.dark .section-box { background:#131c27; }
body.dark .donut-section { background:#131c27; }
body.dark .ranking-section { background:#131c27; }
body.dark .ranking-item { border-bottom-color:#1e293b; }
body.dark .ranking-item:active { background:#1a2638; }
body.dark .rank-name { color:#e0e0e0; }
body.dark .mf-input, body.dark .mf-select { background:#1a2638; border-color:#263447; color:#e0e0e0; }
body.dark .type-t { background:#1a2638; color:#666; }
body.dark .cal-cell { background:#131c27; }
body.dark .cal-cell:active { background:#1a2638; }
body.dark .cal-day { color:#c0c0c0; }
body.dark .cal-day.sun { color:#ef9a9a; }
body.dark .cal-day.sat { color:#90caf9; }
body.dark .search-overlay { background:#0d1117; }
body.dark .search-bar { background:linear-gradient(135deg,#1e293b,#0f172a); }
body.dark .search-input { background:#1a2638; color:#cbd5e1; }
body.dark .rc-insight { color:#e0e0e0; }
body.dark .rc-cmp-val { color:#e0e0e0; }
body.dark .champ-name { color:#e0e0e0; }
body.dark .champ-body { background:#131c27; }
body.dark .top3-cat { color:#e0e0e0; }
body.dark .top3-body { background:#131c27; }
body.dark .top3-row { border-bottom-color:#1e293b; }
body.dark .mbti-title { color:#e0e0e0; }
body.dark .mbti-body { background:#131c27; }
body.dark .mbti-budget { background:#1a2638; }
body.dark .dow-body { background:#131c27; }
body.dark .dow-insight { color:#c0c0c0; }
body.dark .surv-body { background:#131c27; }
body.dark .surv-remaining-amt { color:#e0e0e0; }
body.dark .surv-msg { color:#c0c0c0; }
body.dark .surv-input { background:#1a2638; border-color:#263447; color:#e0e0e0; }
body.dark .surv-tabs { background:#1a2638; }
body.dark .surv-tab { color:#666; }
body.dark .surv-tab.active { background:#131c27; }
body.dark .edit-panel-title { background:#131c27; color:#666; border-bottom-color:#1e293b; }
body.dark .donut-center-amt { color:#e0e0e0; }
body.dark .txa-item { border-bottom-color:#1e293b; }
body.dark .txa-item:active { background:#1a2638; }
body.dark .detail-row { border-bottom-color:#1e293b; }
body.dark .detail-key { color:#666; }
body.dark .detail-val { color:#e0e0e0; }
body.dark .daterange-modal { background:#131c27; }
body.dark .daterange-row input { background:#1a2638; border-color:#263447; color:#e0e0e0; }
body.dark .daterange-row label { color:#9e9e9e; }
body.dark .preset-btn { background:#1a2638; border-color:#263447; color:#9e9e9e; }
body.dark .widget-popover { background:#131c27; }
body.dark .wpop-item { background:#131c27; color:#e0e0e0; border-bottom-color:#1e293b; }
body.dark .tx-cat { color:#666; }
body.dark .me-row.danger .me-row-label { color:#ef9a9a; }
body.dark .new-cat-box { background:#1a2638; border-color:#263447; }
body.dark .new-cat-name { background:#131c27; border-color:#263447; color:#e0e0e0; }
body.dark .icon-picker-box { background:#131c27; }
body.dark .icon-picker-hd { border-color:#263447; }
body.dark .icon-picker-title { color:#e0e0e0; }
body.dark .icon-opt-label { color:#94a3b8; }
body.dark .icon-opt:active { background:#1a2638; }
body.dark .icon-opt.selected { background:#1a2638; }
body.dark .report-edit-btn { background:#1a2638; color:#94a3b8; }
body.dark .report-edit-btn.on { background:#2979FF; color:#fff; }
body.dark .report-edit-bar { background:rgba(13,17,23,.95); }
body.dark .empty-msg { color:#555; }
body.dark .txa-icon { background:#2a2a3e; }
body.dark .txa-desc { color:#e0e0e0; }
body.dark .txa-sub { color:#666; }
body.dark .day-sheet-overlay { background:rgba(0,0,0,.7); }

/* ── 공통 센터 모달 오버레이 ── */
.center-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:600; align-items:center; justify-content:center; padding:16px; }
.center-overlay.show { display:flex; }
.center-modal { background:#fff; border-radius:16px; width:100%; max-width:400px; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,.2); }
.center-modal-hd { background:var(--p); padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
.center-modal-hd-title { color:#fff; font-size:16px; font-weight:700; }
.center-modal-x { background:none; border:none; color:rgba(255,255,255,.8); font-size:24px; cursor:pointer; }
.center-modal-body { padding:16px 20px; max-height:65vh; overflow-y:auto; }
.center-modal-footer { padding:0 20px 20px; }
.center-modal-btn { display:block; width:100%; background:var(--p); color:#fff; border:none; border-radius:var(--r); padding:13px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; }
.center-modal-btn:active { opacity:.85; }
body.dark .center-modal { background:#131c27; }
body.dark .center-modal-hd { background:linear-gradient(135deg,#1e293b,#0f172a); }
body.dark .center-modal-body { background:#131c27; }

/* ── 고정 지출 ── */
.fixed-item { display:flex; align-items:center; gap:10px; padding:12px 0; border-bottom:1px solid #f5f5f5; }
.fixed-item:last-child { border-bottom:none; }
.fixed-item-ico { font-size:22px; width:28px; text-align:center; flex-shrink:0; }
.fixed-item-info { flex:1; min-width:0; }
.fixed-item-name { font-size:14px; font-weight:700; color:#212121; }
.fixed-item-sub  { font-size:12px; color:#9e9e9e; margin-top:2px; }
.fixed-item-amt  { font-size:15px; font-weight:800; flex-shrink:0; }
.fixed-item-del  { background:none; border:none; font-size:18px; color:#e0e0e0; cursor:pointer; padding:4px; flex-shrink:0; }
.fixed-item-del:active { color:#EF4444; }
.fixed-add-form { padding:12px; background:#f9f9f9; border-radius:10px; margin-top:10px; }
.fixed-add-row { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px; }
.fixed-add-row:last-child { margin-bottom:0; }
.fixed-add-input { flex:1; min-width:80px; border:1px solid #e0e0e0; border-radius:7px; padding:9px 10px; font-size:14px; outline:none; font-family:inherit; background:#fff; }
.fixed-add-input:focus { border-color:var(--p); }
.fixed-add-select { border:1px solid #e0e0e0; border-radius:7px; padding:9px 10px; font-size:13px; outline:none; font-family:inherit; background:#fff; }
.fixed-add-btn { background:var(--p); color:#fff; border:none; border-radius:7px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; flex-shrink:0; }
body.dark .fixed-add-form { background:#1a2638; }
body.dark .fixed-add-input, body.dark .fixed-add-select { background:#131c27; border-color:#263447; color:#e0e0e0; }
body.dark .fixed-item-name { color:#e0e0e0; }
body.dark .fixed-item { border-bottom-color:#1e293b; }

/* ── 카테고리 편집 ── */
.catedit-type-tabs { display:flex; border-bottom:2px solid #f0f0f0; margin-bottom:4px; }
.catedit-tab { flex:1; padding:10px 0; border:none; background:none; font-size:14px; font-weight:700; color:#9e9e9e; cursor:pointer; font-family:inherit; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s; }
.catedit-tab.on { color:var(--p); border-bottom-color:var(--p); }
.catedit-item { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid #f5f5f5; }
.catedit-item:last-child { border-bottom:none; }
.catedit-emoji-input { width:44px; height:38px; border:1px solid #e0e0e0; border-radius:7px; font-size:18px; text-align:center; background:#f5f5f5; outline:none; flex-shrink:0; }
.catedit-name-input { flex:1; border:1px solid #e0e0e0; border-radius:7px; padding:8px 10px; font-size:14px; font-family:inherit; outline:none; }
.catedit-name-input:focus { border-color:var(--p); }
.catedit-del { background:none; border:none; font-size:18px; color:#e0e0e0; cursor:pointer; padding:4px; flex-shrink:0; }
.catedit-del:active { color:#EF4444; }
.catedit-add-row { display:flex; gap:6px; }
.catedit-add-emoji { width:44px; border:1px solid #e0e0e0; border-radius:7px; padding:8px 4px; font-size:18px; text-align:center; outline:none; font-family:inherit; flex-shrink:0; }
.catedit-add-name { flex:1; border:1px solid #e0e0e0; border-radius:7px; padding:8px 10px; font-size:14px; font-family:inherit; outline:none; }
.catedit-add-name:focus { border-color:var(--p); }
.catedit-add-btn { background:var(--p); color:#fff; border:none; border-radius:7px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer; flex-shrink:0; }
body.dark .catedit-type-tabs { border-bottom-color:#1e293b; }
body.dark .catedit-tab.on { color:#94a3b8; border-bottom-color:#94a3b8; }
body.dark .catedit-item { border-bottom-color:#1e293b; }
body.dark .catedit-emoji-input, body.dark .catedit-name-input,
body.dark .catedit-add-emoji, body.dark .catedit-add-name { background:#1a2638; border-color:#263447; color:#e0e0e0; }

/* ── 알림 설정 ── */
.notif-status { display:flex; align-items:center; gap:10px; padding:12px 0; border-bottom:1px solid #f5f5f5; margin-bottom:14px; }
.notif-status-dot { width:10px; height:10px; border-radius:50%; background:#bdbdbd; flex-shrink:0; }
.notif-status-dot.on { background:#43A047; }
.notif-status-text { flex:1; font-size:13px; color:#424242; line-height:1.5; }
.notif-time-row { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
.notif-time-label { font-size:13px; color:#757575; flex-shrink:0; }
.notif-time-input { flex:1; border:1px solid #e0e0e0; border-radius:8px; padding:10px 12px; font-size:16px; outline:none; font-family:inherit; }
.notif-time-input:focus { border-color:var(--p); }
body.dark .notif-status { border-bottom-color:#1e293b; }
body.dark .notif-status-text { color:#c0c0c0; }
body.dark .notif-time-label { color:#9e9e9e; }
body.dark .notif-time-input { background:#1a2638; border-color:#263447; color:#e0e0e0; }

/* ── 삭제 확인 (빨간) ── */
.danger-modal { background:#fff; border-radius:16px; width:100%; max-width:340px; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,.25); }
.danger-modal-hd { background:#c62828; padding:14px 20px; }
.danger-modal-hd-title { color:#fff; font-size:16px; font-weight:700; }
.danger-modal-body { padding:24px 20px 16px; text-align:center; }
.danger-modal-icon { font-size:52px; margin-bottom:14px; }
.danger-modal-msg { font-size:16px; font-weight:700; color:#212121; line-height:1.5; margin-bottom:8px; }
.danger-modal-sub { font-size:13px; color:#9e9e9e; line-height:1.6; }
.danger-modal-footer { display:flex; gap:8px; padding:8px 20px 24px; }
.danger-modal-cancel  { flex:1; background:#f5f5f5; color:#424242; border:none; border-radius:10px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; }
.danger-modal-confirm { flex:1; background:#c62828; color:#fff; border:none; border-radius:10px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; }
.danger-modal-cancel:active { opacity:.8; }
.danger-modal-confirm:active { opacity:.8; }
body.dark .danger-modal { background:#1e1e1e; }
body.dark .danger-modal-msg { color:#e0e0e0; }
body.dark .danger-modal-cancel { background:#2a2a2a; color:#c0c0c0; }

/* ── 백업/복구 옵션 ── */
.backup-option { display:flex; align-items:center; gap:14px; padding:16px 0; border-bottom:1px solid #f5f5f5; cursor:pointer; transition:opacity .15s; }
.backup-option:last-child { border-bottom:none; }
.backup-option:active { opacity:.6; }
.backup-option-ico { font-size:30px; flex-shrink:0; width:38px; text-align:center; }
.backup-option-info { flex:1; }
.backup-option-title { font-size:15px; font-weight:700; color:#212121; }
.backup-option-sub { font-size:12px; color:#9e9e9e; margin-top:3px; line-height:1.4; }
body.dark .backup-option { border-bottom-color:#2a2a2a; }
body.dark .backup-option-title { color:#e0e0e0; }

/* ── 내보내기 옵션 ── */
.export-option { display:flex; align-items:center; gap:14px; padding:16px 0; border-bottom:1px solid #f5f5f5; cursor:pointer; transition:opacity .15s; }
.export-option:last-child { border-bottom:none; }
.export-option:active { opacity:.6; }
.export-option-ico { font-size:28px; flex-shrink:0; width:34px; text-align:center; }
.export-option-info { flex:1; }
.export-option-title { font-size:15px; font-weight:700; color:#212121; }
.export-option-sub { font-size:12px; color:#9e9e9e; margin-top:2px; }
body.dark .export-option { border-bottom-color:#2a2a2a; }
body.dark .export-option-title { color:#e0e0e0; }

/* ── 도움말 ── */
.help-section { margin-bottom:20px; }
.help-section-title { font-size:13px; font-weight:800; color:var(--p); margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.help-section-title::before { content:''; display:inline-block; width:3px; height:14px; background:var(--p); border-radius:2px; flex-shrink:0; }
.help-item { display:flex; align-items:flex-start; gap:8px; font-size:13px; color:#424242; line-height:1.65; margin-bottom:5px; }
.help-item::before { content:'•'; color:var(--p); font-weight:700; flex-shrink:0; margin-top:1px; }
body.dark .help-item { color:#c0c0c0; }
body.dark .help-section-title { color:#94a3b8; }
body.dark .help-section-title::before { background:#94a3b8; }
/* 고정지출 모달 */
.fx-cycle-btn {
  flex:1; padding:8px 0; font-size:14px; font-weight:600;
  border:1.5px solid #90A4AE; border-radius:8px;
  background:#fff; color:#546E7A; cursor:pointer; transition:all .15s;
}
.fx-cycle-btn.on { background:var(--p); color:#fff; border-color:var(--p); }
body.dark .fx-cycle-btn { background:#37474F; color:#CFD8DC; border-color:#607D8B; }
body.dark .fx-cycle-btn.on { background:#78909C; color:#fff; border-color:#78909C; }
.fx-dow-btn {
  flex:1; padding:7px 0; font-size:13px;
  border:1px solid #e0e0e0; border-radius:6px;
  background:#fff; color:#424242; cursor:pointer; transition:all .15s;
}
.fx-dow-btn.on { background:var(--p); color:#fff; border-color:var(--p); }
body.dark .fx-dow-btn { background:#37474F; color:#CFD8DC; border-color:#546E7A; }
body.dark .fx-dow-btn.on { background:#78909C; color:#fff; border-color:#78909C; }
#currencyGrid::-webkit-scrollbar { display:none; }
</style>
</head>
<body class="<?= $darkMode ? 'dark' : '' ?>">

<!-- 헤더 -->
<div class="app-header ledger-mode" id="appHeader">
  <div class="header-title">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="3" y="3" width="18" height="18" rx="5" fill="rgba(255,255,255,0.25)"/>
      <path d="M7 12h10M7 8.5h6M7 15.5h8" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>
      <circle cx="17.5" cy="8.5" r="1.5" fill="#fff" opacity=".9"/>
    </svg>
    <span class="header-logo-text">마이가계부</span>
  </div>
  <span class="stats-header-month" id="statsHeaderMonth"></span>
  <!-- 통계 탭 정중앙 날짜 네비 -->
  <div id="statsHdrNav" style="display:none;position:absolute;left:50%;transform:translateX(-50%);align-items:center;gap:2px;">
    <button class="month-btn" onclick="changeMonth(-1)" style="font-size:20px;padding:2px 6px;">‹</button>
    <span id="statsHdrLabel" style="font-size:13px;font-weight:700;color:#fff;min-width:90px;text-align:center;white-space:nowrap;"></span>
    <button class="month-btn" onclick="changeMonth(1)" id="statsHdrBtnNext" style="font-size:20px;padding:2px 6px;">›</button>
  </div>
  <!-- 분석 탭 정중앙 날짜 네비 -->
  <div id="reportHdrNav" style="display:none;position:absolute;left:50%;transform:translateX(-50%);align-items:center;gap:2px;">
    <button class="month-btn" onclick="changeMonth(-1)" style="font-size:20px;padding:2px 6px;">‹</button>
    <span id="reportHdrLabel" style="font-size:13px;font-weight:700;color:#fff;min-width:90px;text-align:center;white-space:nowrap;"></span>
    <button class="month-btn" onclick="changeMonth(1)" id="reportHdrBtnNext" style="font-size:20px;padding:2px 6px;">›</button>
  </div>
  <div class="header-center-title" id="headerCenterTitle">마이가계부</div>
  <div class="header-actions" id="headerActions">
    <div id="haDefault" style="display:flex;align-items:center;gap:2px">
      <button class="search-btn" onclick="openSearch()" title="검색"><i data-lucide="search" style="width:20px;height:20px;stroke-width:1.75"></i></button>
      <button class="cal-btn" onclick="toggleCalendar()" title="달력"><i data-lucide="calendar" style="width:20px;height:20px;stroke-width:1.75"></i></button>
    </div>
    <div id="haStats" class="header-period-filter" style="display:none">
      <button class="hpf-btn" id="hpf-week"  onclick="setStatsPeriod('week')"  data-i18n="period.week">주</button>
      <button class="hpf-btn on" id="hpf-month" onclick="setStatsPeriod('month')" data-i18n="period.month">월</button>
      <button class="hpf-btn" id="hpf-year"  onclick="setStatsPeriod('year')"  data-i18n="period.year">년</button>
    </div>
  </div>
</div>

<!-- 요약 스트립 (월 네비 + 3컬럼 요약) -->
<div id="sumStrip" class="sum-strip">
  <div class="month-nav" id="monthNav">
    <button class="month-btn" onclick="changeMonth(-1)">‹</button>
    <span id="monthLabel" onclick="onMonthLabelClick()"></span>
    <button class="month-btn" onclick="changeMonth(1)" id="monthBtnNext">›</button>
  </div>
  <div class="sum-cols" id="sumCols">
    <div class="sum-col">
      <div class="sum-col-label" data-i18n="lbl.income">수입</div>
      <div class="sum-col-value sum-income" id="sumInc">₩0</div>
    </div>
    <div class="sum-col">
      <div class="sum-col-label" data-i18n="lbl.expense">지출</div>
      <div class="sum-col-value sum-expense" id="sumExp">₩0</div>
    </div>
    <div class="sum-col">
      <div class="sum-col-label" data-i18n="lbl.balance">잔액</div>
      <div class="sum-col-value" id="sumBal">₩0</div>
    </div>
  </div>
</div>

<!-- ① 가계부 탭 -->
<div class="tab-pane active" id="pane-ledger">
  <div id="txList"></div>
</div>

<!-- ② 달력 뷰 (📅 토글) -->
<div class="tab-pane" id="pane-calendar">
  <div class="cal-grid-wrap">
    <div class="cal-dow-row">
      <div class="cal-dow">일</div><div class="cal-dow">월</div><div class="cal-dow">화</div>
      <div class="cal-dow">수</div><div class="cal-dow">목</div><div class="cal-dow">금</div>
      <div class="cal-dow">토</div>
    </div>
    <div class="cal-grid" id="calGrid"></div>
  </div>
</div>

<!-- ③ 통계 탭 -->
<div class="tab-pane" id="pane-stats">
  <!-- 지출/수입 토글 -->
  <div class="stats-type-toggle">
    <button id="st-expense" class="st-btn on expense" onclick="setStatsType('expense')" data-i18n="lbl.expense">지출</button>
    <button id="st-income"  class="st-btn income"     onclick="setStatsType('income')" data-i18n="lbl.income">수입</button>
  </div>
  <!-- 도넛 차트 -->
  <div class="donut-section" id="donutSection">
    <div class="donut-canvas-wrap">
      <canvas id="donutCanvas"></canvas>
      <div class="donut-center">
        <div class="donut-center-label" id="donutCenterLabel">총 지출</div>
        <div class="donut-center-amt" id="donutCenterAmt">₩0</div>
      </div>
    </div>
  </div>
  <!-- 비어있을 때 -->
  <div id="statsEmpty" style="display:none"></div>
  <!-- 랭킹 리스트 -->
  <div class="ranking-section" id="rankingSection" style="margin-bottom:20px">
    <div class="ranking-header">
      <span class="ranking-header-title" id="rankingHeaderTitle">카테고리별 지출 순위</span>
      <div class="rank-group-toggle">
        <button id="sg-category" class="rg-btn on" onclick="setStatsGroup('category')" data-i18n="lbl.category">카테고리</button>
        <button id="sg-payment"  class="rg-btn"    onclick="setStatsGroup('payment')"  data-i18n="lbl.payment">결제수단</button>
      </div>
    </div>
    <div id="rankingList"></div>
  </div>
</div>

<!-- ④ 결산 탭 -->
<div class="tab-pane" id="pane-report">
  <div class="report-wrap" id="reportWrap"></div>
</div>

<!-- ⑤ 나 탭 -->
<div class="tab-pane" id="pane-me">
  <div class="me-wrap">

    <!-- 홈 화면 -->
    <div id="meHome" style="height:100%;overflow:hidden">
      <div class="me-profile">
        <div class="me-profile-banner"></div>
        <div class="me-avatar">
          <svg viewBox="0 0 90 90" width="90" height="90" xmlns="http://www.w3.org/2000/svg">
            <circle cx="45" cy="34" r="17" fill="#9CA3AF"/>
            <ellipse cx="45" cy="78" rx="28" ry="22" fill="#9CA3AF"/>
          </svg>
        </div>
        <?php if ($isLoggedIn): ?>
          <div class="me-name">
            <?=$userName?>님
            <?php if ($dbStats['badge']): ?>
              <span style="font-size:12px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:20px;padding:3px 10px;margin-left:6px;vertical-align:middle"><?=$dbStats['badge']?></span>
            <?php endif; ?>
          </div>
          <div class="me-email"><?=$userEmail?></div>
          <div class="me-stats-row">
            <div class="me-stat-col">
              <div class="me-stat-num"><?=$dbStats['month_count']?></div>
              <div class="me-stat-label" data-i18n="me.monthRecord">이번 달 기록</div>
            </div>
            <div class="me-stat-col">
              <div class="me-stat-streak-text" id="meStreak"><?= $dbStats['streak'] > 0 ? $dbStats['streak'].'일 연속 🔥' : '아직 기록을 시작해봐요! 🔥'?></div>
              <div class="me-stat-label" data-i18n="me.streakDays">연속 기록일</div>
            </div>
          </div>
        <?php else: ?>
          <div class="me-name" data-i18n="me.notLoggedIn">비로그인</div>
          <div class="me-email" data-i18n="me.syncInfo">로그인하면 서버에 동기화됩니다</div>
          <a href="login.php" class="me-login-btn" data-i18n="me.loginBtn">로그인 / 회원가입</a>
        <?php endif; ?>
      </div>

      <div class="me-grid">
        <div class="me-grid-item" onclick="openMePage('appSettings')">
          <div class="me-grid-icon"><i data-lucide="settings"></i></div>
          <span class="me-grid-label" data-i18n="grid.settings">앱 설정</span>
        </div>
        <div class="me-grid-item" onclick="showToast(tr('toast.coming'))">
          <div class="me-grid-icon"><i data-lucide="zap"></i></div>
          <span class="me-grid-label" data-i18n="grid.upgrade">업그레이드</span>
        </div>
        <div class="me-grid-item" onclick="openHelpModal()">
          <div class="me-grid-icon"><i data-lucide="help-circle"></i></div>
          <span class="me-grid-label" data-i18n="grid.help">도움말</span>
        </div>
        <div class="me-grid-item" onclick="openMePage('data')">
          <div class="me-grid-icon"><i data-lucide="database"></i></div>
          <span class="me-grid-label" data-i18n="grid.data">데이터</span>
        </div>
        <div class="me-grid-item" onclick="showToast(tr('toast.coming'))">
          <div class="me-grid-icon"><i data-lucide="message-circle"></i></div>
          <span class="me-grid-label" data-i18n="grid.contact">문의하기</span>
        </div>
      </div>
    </div>

    <!-- 앱 설정 페이지 -->
    <div id="mePageAppSettings" class="me-subpage">
      <div class="me-subpage-hd">
        <button class="me-subpage-back" onclick="closeMePage()"><i data-lucide="chevron-left"></i></button>
        <span class="me-subpage-title" data-i18n="page.appSettings">앱 설정</span>
      </div>
      <div class="me-section">
        <div class="me-section-title" data-i18n="section.records">기록 관리</div>
        <div class="me-row" onclick="openFixedModal()">
          <span class="me-row-ico"><i data-lucide="pin"></i></span><span class="me-row-label" data-i18n="row.fixedExpense">고정 지출 설정</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="openCatEditModal()">
          <span class="me-row-ico"><i data-lucide="tag"></i></span><span class="me-row-label" data-i18n="row.categories">카테고리 편집</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="openPayEditModal()">
          <span class="me-row-ico"><i data-lucide="credit-card"></i></span><span class="me-row-label" data-i18n="row.payments">결제수단 편집</span><span class="me-row-arrow">›</span>
        </div>
      </div>
      <div class="me-section">
        <div class="me-section-title" data-i18n="section.environment">앱 환경</div>
        <div class="me-row" onclick="openCurrencyModal()">
          <span class="me-row-ico"><i data-lucide="coins"></i></span><span class="me-row-label" data-i18n="row.currency">기본 통화</span><span class="me-row-value" id="currencyRowValue">₩ KRW</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="openNotifModal()">
          <span class="me-row-ico"><i data-lucide="bell"></i></span><span class="me-row-label" data-i18n="row.notifications">푸시 알림</span><span class="me-row-value" id="notifRowValue">꺼짐</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="toggleDarkMode()">
          <span class="me-row-ico"><i data-lucide="moon"></i></span><span class="me-row-label" data-i18n="row.theme">다크 모드</span>
          <label class="toggle-wrap" style="margin-left:auto;pointer-events:none">
            <input type="checkbox" class="toggle-input" id="darkToggle" style="pointer-events:none">
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="me-row" onclick="openFontSizeModal ? openFontSizeModal() : null">
          <span class="me-row-ico"><i data-lucide="type"></i></span><span class="me-row-label" data-i18n="row.fontSize">글꼴 크기</span><span class="me-row-value" id="fontSizeRowValue">보통</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="openLangModal()">
          <span class="me-row-ico"><i data-lucide="globe"></i></span><span class="me-row-label" data-i18n="row.language">언어</span><span class="me-row-value" id="langRowValue">한국어</span><span class="me-row-arrow">›</span>
        </div>
      </div>
    </div>

    <!-- 데이터 페이지 -->
    <div id="mePageData" class="me-subpage">
      <div class="me-subpage-hd">
        <button class="me-subpage-back" onclick="closeMePage()"><i data-lucide="chevron-left"></i></button>
        <span class="me-subpage-title" data-i18n="page.data">데이터</span>
      </div>
      <div class="me-section">
        <div class="me-section-title" data-i18n="section.dataManagement">데이터 관리</div>
        <div class="me-row" onclick="openBackupModal()">
          <span class="me-row-ico"><i data-lucide="cloud"></i></span><span class="me-row-label" data-i18n="row.backup">백업 및 복구</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row" onclick="openExportModal()">
          <span class="me-row-ico"><i data-lucide="download"></i></span><span class="me-row-label" data-i18n="row.export">엑셀로 내보내기</span><span class="me-row-arrow">›</span>
        </div>
        <div class="me-row danger" onclick="doDeleteAll()">
          <span class="me-row-ico"><i data-lucide="trash-2"></i></span><span class="me-row-label" data-i18n="row.deleteAll">전체 내역 삭제</span><span class="me-row-arrow">›</span>
        </div>
      </div>
    </div>

  </div>
</div>

<div class="widget-popover" id="widgetPopover"></div>

<!-- ── 고정 지출 설정 모달 ── -->
<div class="overlay" id="fixedModal" onclick="if(event.target===this)closeFixedModal()">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title">고정 지출 설정</span>
      <button class="modal-x" onclick="closeFixedModal()">×</button>
    </div>

    <!-- 등록된 목록 -->
    <div id="fixedList" style="max-height:36vh;overflow-y:auto;padding:0 4px"></div>

    <!-- 추가 폼 -->
    <div style="padding:12px 16px 16px;border-top:1px solid #f0f0f0">
      <div style="font-size:12px;color:#9e9e9e;margin-bottom:10px">새 고정 항목 추가</div>

      <!-- 이름 + 금액 -->
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <input id="fxName" placeholder="항목명 (예: 월세)"
          style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none">
        <input id="fxAmt" type="text" inputmode="numeric" placeholder="금액"
          style="width:100px;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none;text-align:right"
          oninput="fmtFixedAmt(this)">
      </div>

      <!-- 수입/지출 + 카테고리 -->
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <select id="fxType" style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 10px;font-size:14px;outline:none;background:#fff">
          <option value="expense">💸 지출</option>
          <option value="income">💰 수입</option>
        </select>
        <select id="fxCat" style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 10px;font-size:14px;outline:none;background:#fff">
          <option value="">카테고리 선택</option>
        </select>
      </div>

      <!-- 주기 선택 -->
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <button class="fx-cycle-btn on" data-cycle="weekly"  onclick="setFxCycle('weekly')">매주</button>
        <button class="fx-cycle-btn"    data-cycle="monthly" onclick="setFxCycle('monthly')">매달</button>
        <button class="fx-cycle-btn"    data-cycle="yearly"  onclick="setFxCycle('yearly')">매년</button>
      </div>

      <!-- 매주 → 요일 -->
      <div id="fxWeekRow" style="display:flex;gap:4px;margin-bottom:8px">
        <?php foreach(['일','월','화','수','목','금','토'] as $i=>$d): ?>
        <button class="fx-dow-btn<?=$i===1?' on':''?>" data-dow="<?=$i?>" onclick="setFxDow(<?=$i?>)"><?=$d?></button>
        <?php endforeach; ?>
      </div>

      <!-- 매달 → 일 선택 -->
      <div id="fxMonthRow" style="display:none;margin-bottom:8px">
        <select id="fxDom" style="width:100%;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none;background:#fff">
          <?php for($d=1;$d<=31;$d++) echo "<option value='$d'>매달 {$d}일</option>"; ?>
        </select>
      </div>

      <!-- 매년 → 월 + 일 선택 -->
      <div id="fxYearRow" style="display:none;margin-bottom:8px;display:none">
        <div style="display:flex;gap:8px">
          <select id="fxMoy" style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none;background:#fff">
            <?php for($m=1;$m<=12;$m++) echo "<option value='$m'>{$m}월</option>"; ?>
          </select>
          <select id="fxDomY" style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none;background:#fff">
            <?php for($d=1;$d<=31;$d++) echo "<option value='$d'>{$d}일</option>"; ?>
          </select>
        </div>
      </div>


      <button onclick="addFixed()"
        style="width:100%;background:var(--p);color:#fff;border:none;border-radius:var(--r);padding:12px;font-size:15px;font-weight:700;cursor:pointer">
        + 추가하기
      </button>
    </div>
  </div>
</div>

<!-- ── 고정지출 소급 확인 팝업 ── -->
<div class="overlay" id="fxRetroModal" style="display:none;z-index:1200">
  <div class="modal" style="max-width:340px">
    <div class="modal-hd">
      <span class="modal-hd-title">📅 이미 지난 날짜예요!</span>
    </div>
    <div style="padding:20px 20px 8px">
      <p id="fxRetroMsg" style="font-size:15px;line-height:1.6;color:#37474F;margin-bottom:20px"></p>
      <button onclick="submitFixed(true)"
        style="width:100%;background:var(--p);color:#fff;border:none;border-radius:var(--r);padding:12px;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:8px">
        ✅ 네, 지금 바로 기록할게요
      </button>
      <button onclick="submitFixed(false)"
        style="width:100%;background:var(--bg);color:var(--p);border:1px solid var(--border);border-radius:var(--r);padding:12px;font-size:14px;cursor:pointer">
        다음 달부터만 적용할게요
      </button>
    </div>
  </div>
</div>

<!-- ── 카테고리 편집 모달 ── -->
<div class="overlay" id="catEditModal" onclick="if(event.target===this)closeCatEditModal()">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title">🏷️ 카테고리 편집</span>
      <button class="modal-x" onclick="closeCatEditModal()">×</button>
    </div>
    <div style="padding:0 20px">
      <div class="catedit-type-tabs">
        <button class="catedit-tab on" id="cetab-expense" onclick="setCatEditType('expense')">지출</button>
        <button class="catedit-tab" id="cetab-income"  onclick="setCatEditType('income')">수입</button>
      </div>
    </div>
    <div id="catEditList" style="padding:0 12px;max-height:46vh;overflow-y:auto"></div>
    <div style="padding:10px 16px 16px;border-top:1px solid #f0f0f0;margin-top:4px">
      <div style="font-size:12px;color:#9e9e9e;margin-bottom:8px">새 카테고리 추가</div>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="ceEmoji" type="text" maxlength="2" placeholder="😀"
          style="width:48px;text-align:center;border:1px solid #e0e0e0;border-radius:8px;padding:10px 4px;font-size:18px;outline:none">
        <input id="ceName" type="text" placeholder="카테고리 이름"
          style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none"
          onkeydown="if(event.key==='Enter')addCatEdit()">
        <button onclick="addCatEdit()"
          style="background:var(--p);color:#fff;border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">추가</button>
      </div>
    </div>
  </div>
</div>

<!-- ── 결제수단 편집 모달 ── -->
<div class="overlay" id="payEditModal" onclick="if(event.target===this)closePayEditModal()">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title">💳 결제수단 편집</span>
      <button class="modal-x" onclick="closePayEditModal()">×</button>
    </div>
    <div id="payEditList" style="padding:0 12px;max-height:46vh;overflow-y:auto"></div>
    <div style="padding:10px 16px 16px;border-top:1px solid #f0f0f0;margin-top:4px">
      <div style="font-size:12px;color:#9e9e9e;margin-bottom:8px">새 결제수단 추가</div>
      <div style="display:flex;gap:8px;align-items:center">
        <div id="peIconPreview" onclick="openIconPicker('pay-edit')"
          style="width:42px;height:42px;border-radius:50%;background:#607D8B;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer">
          <i data-lucide="credit-card" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>
        </div>
        <input id="peName" type="text" placeholder="결제수단 이름"
          style="flex:1;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none"
          onkeydown="if(event.key==='Enter')addPayEdit()">
        <button onclick="addPayEdit()"
          style="background:var(--p);color:#fff;border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">추가</button>
      </div>
    </div>
  </div>
</div>

<!-- ── 알림 설정 모달 ── -->
<div class="center-overlay" id="notifModal" onclick="if(event.target===this)closeNotifModal()">
  <div class="center-modal">
    <div class="center-modal-hd">
      <span class="center-modal-hd-title">🔔 알림 설정</span>
      <button class="center-modal-x" onclick="closeNotifModal()">×</button>
    </div>
    <div class="center-modal-body">
      <div class="notif-status">
        <div class="notif-status-dot" id="notifDot"></div>
        <div class="notif-status-text" id="notifStatusText">알림 권한 확인 중...</div>
      </div>
      <div class="notif-time-row">
        <span class="notif-time-label">알림 시간</span>
        <input class="notif-time-input" id="notifTimeInput" type="time" value="21:00">
      </div>
      <div style="font-size:12px;color:#9e9e9e;line-height:1.7">
        설정한 시간에 가계부 작성 리마인드를 보내드립니다.<br>
        앱이 열려 있을 때 작동합니다.
      </div>
    </div>
    <div class="center-modal-footer">
      <button class="center-modal-btn" id="notifPermBtn" onclick="handleNotifPermission()">권한 허용 후 저장</button>
    </div>
  </div>
</div>

<!-- ── 백업/복구 모달 ── -->
<div class="center-overlay" id="backupModal" onclick="if(event.target===this)closeBackupModal()">
  <div class="center-modal">
    <div class="center-modal-hd">
      <span class="center-modal-hd-title">☁️ 백업 및 복구</span>
      <button class="center-modal-x" onclick="closeBackupModal()">×</button>
    </div>
    <div class="center-modal-body">
      <div class="backup-option" onclick="doBackup()">
        <div class="backup-option-ico">⬇️</div>
        <div class="backup-option-info">
          <div class="backup-option-title">데이터 백업 (JSON 다운로드)</div>
          <div class="backup-option-sub">모든 내역·카테고리·설정을 파일로 저장합니다</div>
        </div>
      </div>
      <div class="backup-option" onclick="document.getElementById('restoreFileInput').click()">
        <div class="backup-option-ico">⬆️</div>
        <div class="backup-option-info">
          <div class="backup-option-title">데이터 복구 (파일 업로드)</div>
          <div class="backup-option-sub">백업 JSON 파일로 복원합니다 (현재 데이터 덮어쓰기)</div>
        </div>
      </div>
      <input type="file" id="restoreFileInput" accept=".json,application/json" style="display:none" onchange="doRestore(this)">
    </div>
  </div>
</div>

<!-- ── 엑셀 내보내기 모달 ── -->
<div class="center-overlay" id="exportModal" onclick="if(event.target===this)closeExportModal()">
  <div class="center-modal">
    <div class="center-modal-hd">
      <span class="center-modal-hd-title">📊 엑셀로 내보내기</span>
      <button class="center-modal-x" onclick="closeExportModal()">×</button>
    </div>
    <div class="center-modal-body">
      <div class="export-option" onclick="doExportCSV('month');closeExportModal()">
        <div class="export-option-ico">📅</div>
        <div class="export-option-info">
          <div class="export-option-title">이번 달 내역</div>
          <div class="export-option-sub" id="exportMonthLabel"></div>
        </div>
      </div>
      <div class="export-option" onclick="doExportCSV('all');closeExportModal()">
        <div class="export-option-ico">📋</div>
        <div class="export-option-info">
          <div class="export-option-title">전체 기간 내역</div>
          <div class="export-option-sub" id="exportAllLabel"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── 전체 삭제 확인 모달 ── -->
<div class="center-overlay" id="deleteAllModal" onclick="if(event.target===this)closeDeleteAllModal()">
  <div class="danger-modal">
    <div class="danger-modal-hd">
      <div class="danger-modal-hd-title">⚠️ 전체 내역 삭제</div>
    </div>
    <div class="danger-modal-body">
      <div class="danger-modal-icon">🗑️</div>
      <div class="danger-modal-msg">정말로 모든 데이터를 삭제할까요?</div>
      <div class="danger-modal-sub">삭제된 데이터는 복구할 수 없습니다.<br>삭제 전에 반드시 백업하세요.</div>
    </div>
    <div class="danger-modal-footer">
      <button class="danger-modal-cancel" onclick="closeDeleteAllModal()">취소</button>
      <button class="danger-modal-confirm" onclick="confirmDeleteAll()">모두 삭제</button>
    </div>
  </div>
</div>

<!-- ── 도움말 모달 ── -->
<!-- ── 통화 선택 모달 ── -->
<div class="overlay" id="currencyModal" onclick="if(event.target===this)closeCurrencyModal()">
  <div class="modal" style="max-width:360px">
    <div class="modal-hd">
      <span class="modal-hd-title" data-i18n="row.currency">기본 통화</span>
      <button class="modal-x" onclick="closeCurrencyModal()">×</button>
    </div>
    <div style="padding:12px 16px 0">
      <div style="display:flex;align-items:center;gap:8px;border:1.5px solid #e0e0e0;border-radius:10px;padding:8px 12px;background:#f9f9f9">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="currencySearch" type="text" placeholder="통화 검색..." oninput="renderCurrencyGrid(this.value)"
          style="border:none;outline:none;background:transparent;font-size:14px;width:100%;color:#374151">
      </div>
    </div>
    <div id="currencyGrid" style="overflow-y:auto;max-height:60vh;scrollbar-width:none;-ms-overflow-style:none"></div>
  </div>
</div>

<!-- ── 언어 선택 모달 ── -->
<div class="overlay" id="langModal" onclick="if(event.target===this)closeLangModal()">
  <div class="modal" style="max-width:320px">
    <div class="modal-hd">
      <span class="modal-hd-title" data-i18n="row.language">언어</span>
      <button class="modal-x" onclick="closeLangModal()">×</button>
    </div>
    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div id="langOpt한국어" onclick="setLang('한국어')" style="border:2px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;font-size:14px;font-weight:600">🇰🇷 한국어</div>
      <div id="langOpt영어"  onclick="setLang('영어')"  style="border:2px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;font-size:14px;font-weight:600">🇺🇸 English</div>
      <div id="langOpt일본어" onclick="setLang('일본어')" style="border:2px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;font-size:14px;font-weight:600">🇯🇵 日本語</div>
      <div id="langOpt중국어" onclick="setLang('중국어')" style="border:2px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;font-size:14px;font-weight:600">🇨🇳 中文</div>
      <div id="langOpt스페인어" onclick="setLang('스페인어')" style="border:2px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;font-size:14px;font-weight:600;grid-column:span 2">🇪🇸 Español</div>
    </div>
  </div>
</div>

<div class="overlay" id="helpModal" onclick="if(event.target===this)closeHelpModal()">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title">💬 도움말</span>
      <button class="modal-x" onclick="closeHelpModal()">×</button>
    </div>
    <div style="padding:16px 20px 28px">
      <div class="help-section">
        <div class="help-section-title">가계부 탭</div>
        <div class="help-item">하단 <b>+</b> 버튼으로 지출·수입을 기록하세요.</div>
        <div class="help-item">거래 항목을 탭하면 수정·삭제·상세보기가 가능해요.</div>
        <div class="help-item">📅 버튼으로 달력 뷰를, 🔍로 내역을 검색해요.</div>
        <div class="help-item">상단 ‹ › 버튼으로 월을 이동할 수 있어요.</div>
      </div>
      <div class="help-section">
        <div class="help-section-title">통계 탭</div>
        <div class="help-item">카테고리별·결제수단별 지출 비중을 확인하세요.</div>
        <div class="help-item">우측 상단에서 주·월·년 단위로 조회 가능해요.</div>
        <div class="help-item">도넛 차트를 탭하면 해당 카테고리 내역을 볼 수 있어요.</div>
      </div>
      <div class="help-section">
        <div class="help-section-title">분석 탭</div>
        <div class="help-item">이번 달 소비 패턴을 위젯으로 분석해드립니다.</div>
        <div class="help-item">위젯 우측 <b>···</b> 버튼으로 추가·제거·순서 변경이 가능해요.</div>
        <div class="help-item">목표 예산에서 예산을 설정하면 남은 금액을 알 수 있어요.</div>
        <div class="help-item">최고 지출 항목에서 😊/💸 버튼으로 소비를 돌아보세요.</div>
      </div>
      <div class="help-section">
        <div class="help-section-title">나 탭 — 데이터 관리</div>
        <div class="help-item"><b>고정 지출 설정</b>: 월세·통신비 등 정기 지출을 등록하면 지정일에 자동 기록돼요.</div>
        <div class="help-item"><b>카테고리 편집</b>: 이모지와 이름을 변경하거나 새 카테고리를 추가할 수 있어요.</div>
        <div class="help-item"><b>백업/복구</b>: JSON 파일로 데이터를 안전하게 보관하고 복원하세요.</div>
        <div class="help-item"><b>엑셀 내보내기</b>: 이번 달 또는 전체 내역을 CSV로 다운로드할 수 있어요.</div>
        <div class="help-item"><b>푸시 알림</b>: 원하는 시간에 가계부 작성 리마인드를 받을 수 있어요.</div>
      </div>
      <div class="help-section">
        <div class="help-section-title">앱 정보</div>
        <div class="help-item">마이가계부 v1.0 — 현명한 소비 습관을 만들어드립니다.</div>
        <div class="help-item">데이터는 기기 브라우저에 저장되며 서버에도 연동됩니다.</div>
      </div>
    </div>
  </div>
</div>
<div class="dow-tip" id="dowTip"></div>

<!-- 날짜별 내역 시트 -->
<div class="day-sheet-overlay" id="daySheet" onclick="closeDaySheet(event)">
  <div class="day-sheet">
    <div class="day-sheet-hd">
      <span class="day-sheet-title" id="daySheetTitle"></span>
      <div style="display:flex;align-items:center;gap:8px">
        <button class="day-sheet-add" onclick="openModalForDate()">＋</button>
        <button class="day-sheet-x" onclick="document.getElementById('daySheet').classList.remove('show')">×</button>
      </div>
    </div>
    <div id="daySheetBody"></div>
  </div>
</div>

<!-- 내역 액션 시트 -->
<div class="txa-overlay" id="txaOverlay" onclick="closeTxaOverlay(event)">
  <div class="txa-sheet">
    <div class="txa-hd">
      <span class="txa-hd-title">내역</span>
      <button class="txa-x" onclick="document.getElementById('txaOverlay').classList.remove('show')">×</button>
    </div>
    <div id="txaSummary"></div>
    <div class="txa-menu">
      <div class="txa-item" onclick="showTxDetail()"><span class="txa-ico">📋</span> <span data-i18n="btn.detail">상세정보</span></div>
      <div class="txa-item" onclick="editTx()"><span class="txa-ico">✏️</span> <span data-i18n="btn.edit">수정</span></div>
      <div class="txa-item" onclick="copyTx()"><span class="txa-ico">📄</span> <span data-i18n="btn.copy">복사</span></div>
      <div class="txa-item danger" onclick="deleteTxFromAction()"><span class="txa-ico">🗑️</span> <span data-i18n="btn.delete">삭제</span></div>
    </div>
  </div>
</div>

<!-- 상세정보 시트 -->
<div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="detail-sheet">
    <div class="detail-hd">
      <span class="detail-hd-title" data-i18n="lbl.detail">상세정보</span>
      <button class="detail-x" onclick="document.getElementById('detailOverlay').classList.remove('show')">×</button>
    </div>
    <div id="detailBody"></div>
  </div>
</div>

<!-- 기간 선택 모달 -->
<div class="daterange-overlay" id="daterangeOverlay" onclick="if(event.target===this)closeDateRange()">
  <div class="daterange-modal">
    <div class="daterange-hd">
      <span class="daterange-hd-title">기간 선택</span>
      <button class="daterange-x" onclick="closeDateRange()">×</button>
    </div>
    <div class="daterange-body">
      <div class="daterange-row"><label>시작일</label><input type="date" id="drFrom"></div>
      <div class="daterange-row"><label>종료일</label><input type="date" id="drTo"></div>
    </div>
    <button class="daterange-apply" onclick="applyDateRange()">적용</button>
  </div>
</div>

<!-- 카테고리 상세 시트 -->
<div class="catdet-overlay" id="catdetOverlay" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="catdet-sheet">
    <div class="catdet-hd">
      <span class="catdet-title" id="catdetTitle"></span>
      <button class="catdet-x" onclick="document.getElementById('catdetOverlay').classList.remove('show')">×</button>
    </div>
    <div class="catdet-body" id="catdetBody"></div>
  </div>
</div>

<!-- 검색 오버레이 -->
<div class="search-overlay" id="searchOverlay">
  <div class="search-bar">
    <input class="search-input" id="searchInput" type="text" placeholder="내용, 카테고리, 금액 검색..." oninput="doSearch(this.value)">
    <button class="search-close" onclick="closeSearch()">닫기</button>
  </div>
  <div class="search-results" id="searchResults">
    <div class="search-empty">검색어를 입력하세요</div>
  </div>
</div>

<!-- 사진 풀스크린 뷰어 -->
<div class="photo-viewer" id="photoViewer" onclick="document.getElementById('photoViewer').classList.remove('show')">
  <img id="photoViewerImg" src="" alt="사진">
  <button class="photo-viewer-x" onclick="document.getElementById('photoViewer').classList.remove('show')">×</button>
</div>

<!-- 하단 탭바 -->
<div class="tab-bar">
  <button class="t-btn on" id="tb-ledger" onclick="goTab('ledger')"><i data-lucide="book-open" class="ico-sv"></i><span data-i18n="tab.ledger">가계부</span></button>
  <button class="t-btn"    id="tb-stats"  onclick="goTab('stats')"><i data-lucide="bar-chart-2" class="ico-sv"></i><span data-i18n="tab.stats">통계</span></button>
  <button class="fab-wrap" onclick="openModal()"><div class="fab">＋</div></button>
  <button class="t-btn"    id="tb-report" onclick="goTab('report')"><i data-lucide="file-text" class="ico-sv"></i><span data-i18n="tab.report">분석</span></button>
  <button class="t-btn"    id="tb-me"     onclick="goTab('me')"><i data-lucide="user" class="ico-sv"></i><span data-i18n="tab.me">나</span></button>
</div>

<!-- 내역 추가 모달 -->
<div class="overlay" id="modal" onclick="onOverlayClick(event)">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title" id="modalTitle">내역 추가</span>
      <button class="modal-x" onclick="closeModal()">×</button>
    </div>
    <div class="type-row">
      <button class="type-t on e" id="typeE" onclick="setType('expense')" data-i18n="lbl.expense">지출</button>
      <button class="type-t"      id="typeI" onclick="setType('income')" data-i18n="lbl.income">수입</button>
    </div>
    <div class="mform">
      <label class="mf-label" data-i18n="form.amount">금액 (원)</label>
      <input class="mf-input" id="fAmt" type="text" inputmode="numeric" placeholder="0" oninput="formatAmtInput(this)">

      <label class="mf-label" data-i18n="form.category">카테고리</label>
      <div class="cat-row-wrap">
        <div class="cat-custom-select" id="catCustomSelect">
          <button class="cat-cs-trigger" id="catCsTrigger" type="button" onclick="toggleCatDropdown()">
            <span id="catCsLabel" data-i18n="form.catSelect">선택</span><span class="cat-cs-arrow"><i data-lucide="chevron-down" style="width:16px;height:16px;stroke-width:2"></i></span>
          </button>
          <div class="cat-cs-dropdown" id="catCsDropdown"></div>
        </div>
        <input type="hidden" id="fCat">
        <button class="cat-add-btn" onclick="toggleNewCat()" title="카테고리 편집">＋</button>
      </div>
      <!-- 새 카테고리 입력 -->
      <div class="new-cat-box" id="newCatBox">
        <div class="new-cat-row">
          <button class="nc-icon-btn" id="ncIconBtn" onclick="openIconPicker('cat')" type="button">
            <div class="nc-icon-preview" id="ncIconPreview" style="background:#607D8B">
              <i data-lucide="package" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>
            </div>
          </button>
          <input class="new-cat-name" id="ncName" type="text" data-i18n-ph="form.catName" placeholder="카테고리 이름">
          <button class="new-cat-save" onclick="saveNewCat()" data-i18n="btn.add">추가</button>
        </div>
      </div>

      <label class="mf-label" data-i18n="form.payment">결제수단</label>
      <div class="cat-row-wrap">
        <div class="cat-custom-select" id="payCustomSelect">
          <button class="cat-cs-trigger" id="payCsTrigger" type="button" onclick="togglePayDropdown()">
            <span id="payCsLabel">현금</span><span class="cat-cs-arrow"><i data-lucide="chevron-down" style="width:16px;height:16px;stroke-width:2"></i></span>
          </button>
          <div class="cat-cs-dropdown" id="payCsDropdown"></div>
        </div>
        <input type="hidden" id="fPay" value="현금">
        <button class="cat-add-btn" onclick="toggleNewPay()" title="결제수단 추가">＋</button>
      </div>
      <div class="new-cat-box" id="newPayBox">
        <div class="new-cat-row">
          <button class="nc-icon-btn" id="npIconBtn" onclick="openIconPicker('pay')" type="button">
            <div class="nc-icon-preview" id="npIconPreview" style="background:#607D8B">
              <i data-lucide="credit-card" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>
            </div>
          </button>
          <input class="new-cat-name" id="npName" type="text" data-i18n-ph="form.payName" placeholder="결제수단 이름">
          <button class="new-cat-save" onclick="saveNewPay()" data-i18n="btn.add">추가</button>
        </div>
      </div>

      <label class="mf-label" data-i18n="form.desc">내용 / 메모</label>
      <div class="desc-row">
        <input class="mf-input" id="fDesc" type="text" data-i18n-ph="form.descPh" placeholder="예) 편의점, 버스">
        <button class="photo-btn" onclick="document.getElementById('photoInput').click()" title="사진 첨부">📷</button>
        <input type="file" id="photoInput" accept="image/*" style="display:none" onchange="onPhotoSelect(this)">
      </div>
      <!-- 사진 미리보기 (다중) -->
      <div id="photoGrid" style="display:none">
        <div class="photo-grid" id="photoGridItems"></div>
      </div>

      <label class="mf-label" data-i18n="form.date">날짜</label>
      <input class="mf-input" id="fDate" type="date">
    </div>
    <button class="modal-save" onclick="saveTx()">저장</button>
  </div>
</div>

<!-- 아이콘 피커 -->
<div class="icon-picker-overlay" id="iconPickerOverlay" onclick="onIconPickerBg(event)">
  <div class="icon-picker-box">
    <div class="icon-picker-hd">
      <span class="icon-picker-title">아이콘 선택</span>
      <button class="icon-picker-x" onclick="closeIconPicker()">×</button>
    </div>
    <div class="icon-picker-grid" id="iconPickerGrid"></div>
  </div>
</div>

<script>
// ── 상수 ──────────────────────────────────────────────────────
const SK      = 'ddgb_v1';
const CATS_SK = 'ddgb_cats_v1';
const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
const USER_NAME    = <?= json_encode($_SESSION['user_name'] ?? '') ?>;
const TABS = ['ledger','calendar','stats','report','me'];

const BASE_CATS = {
  expense: ['식비','교통','쇼핑','의료','문화','통신','주거','기타'],
  income:  ['급여','용돈','기타수입']
};
const BASE_ICONS = {
  '식비':'🍔','교통':'🚌','쇼핑':'🛒','의료':'💊','문화':'🎬',
  '통신':'📱','주거':'🏠','기타':'📦','급여':'💰','용돈':'🎁','기타수입':'➕'
};

// ── 통일 라인 아이콘 시스템 (Lucide Icons) ────────────────────
const CAT_ICON_MAP = {
  /* ── 지출 ── */
  '식비':      { lu:'utensils',           bg:'#E74C3C', c:'#fff' },
  '교통':      { lu:'bus',                bg:'#F39C12', c:'#fff' },
  '쇼핑':      { lu:'shopping-bag',       bg:'#8E44AD', c:'#fff' },
  '의료':      { lu:'heart-pulse',        bg:'#E91E8C', c:'#fff' },
  '문화':      { lu:'film',               bg:'#2196F3', c:'#fff' },
  '통신':      { lu:'smartphone',         bg:'#00BCD4', c:'#fff' },
  '주거':      { lu:'home',               bg:'#27AE60', c:'#fff' },
  '기타':      { lu:'package',            bg:'#1ABC9C', c:'#fff' },
  '생활':      { lu:'shopping-cart',      bg:'#1ABC9C', c:'#fff' },
  /* ── 수입 ── */
  '급여':      { lu:'briefcase',          bg:'#C8860A', c:'#fff' },
  '용돈':      { lu:'gift',               bg:'#E91E63', c:'#fff' },
  '기타수입':  { lu:'coins',              bg:'#2563EB', c:'#fff' },
  /* ── 결제수단 ── */
  '현금':      { lu:'banknote',           bg:'#4CAF50', c:'#fff' },
  '신용카드':  { lu:'credit-card',        bg:'#7C3AED', c:'#fff' },
  '체크카드':  { lu:'credit-card',        bg:'#1565C0', c:'#fff' },
  '계좌이체':  { lu:'landmark',           bg:'#1E293B', c:'#fff' },
  '카카오페이':{ lu:'circle-dollar-sign', bg:'#FDD835', c:'#5D4037' },
  '네이버페이':{ lu:'circle-dollar-sign', bg:'#2E7D32', c:'#fff' },
  '토스':      { lu:'circle-dollar-sign', bg:'#1565C0', c:'#fff' },
};
const _IC_DEF = { lu:'receipt', bg:'#607D8B', c:'#fff' };

function _icMeta(cat) { return CAT_ICON_MAP[cat] || _IC_DEF; }

// Lucide 아이콘 HTML 생성 — lucide.createIcons() 로 SVG 변환
function getIconHtml(cat, size) {
  const m = _icMeta(cat);
  const sz = size || 20;
  return `<i data-lucide="${m.lu}" class="lc-icon" style="width:${sz}px;height:${sz}px;color:${m.c};stroke-width:1.75"></i>`;
}
function getIconBg(cat) { return _icMeta(cat).bg; }
function getIconColor(cat) { return _icMeta(cat).c; }

// Lucide 아이콘 DOM 갱신 (innerHTML 업데이트 후 호출)
let _lcTimer;
function refreshIcons() {
  if (!window.lucide) return;
  clearTimeout(_lcTimer);
  _lcTimer = setTimeout(() => lucide.createIcons(), 0);
}

// ── 통화 ──────────────────────────────────────────────────────
const CURRENCY_SK = 'app_currency';
const CURRENCY_LIST = [
  {code:'KRW',symbol:'₩',name:'대한민국 원'},{code:'USD',symbol:'$',name:'미국 달러'},
  {code:'EUR',symbol:'€',name:'유럽 유로'},{code:'JPY',symbol:'¥',name:'일본 엔'},
  {code:'GBP',symbol:'£',name:'영국 파운드'},{code:'CNY',symbol:'¥',name:'중국 위안'},
  {code:'HKD',symbol:'HK$',name:'홍콩 달러'},{code:'TWD',symbol:'NT$',name:'대만 달러'},
  {code:'SGD',symbol:'S$',name:'싱가포르 달러'},{code:'AUD',symbol:'A$',name:'호주 달러'},
  {code:'CAD',symbol:'CA$',name:'캐나다 달러'},{code:'CHF',symbol:'Fr',name:'스위스 프랑'},
  {code:'SEK',symbol:'kr',name:'스웨덴 크로나'},{code:'NOK',symbol:'kr',name:'노르웨이 크로네'},
  {code:'DKK',symbol:'kr',name:'덴마크 크로네'},{code:'NZD',symbol:'NZ$',name:'뉴질랜드 달러'},
  {code:'THB',symbol:'฿',name:'태국 바트'},{code:'MYR',symbol:'RM',name:'말레이시아 링깃'},
  {code:'IDR',symbol:'Rp',name:'인도네시아 루피아'},{code:'PHP',symbol:'₱',name:'필리핀 페소'},
  {code:'VND',symbol:'₫',name:'베트남 동'},{code:'INR',symbol:'₹',name:'인도 루피'},
  {code:'PKR',symbol:'₨',name:'파키스탄 루피'},{code:'BDT',symbol:'৳',name:'방글라데시 타카'},
  {code:'LKR',symbol:'₨',name:'스리랑카 루피'},{code:'NPR',symbol:'₨',name:'네팔 루피'},
  {code:'MMK',symbol:'K',name:'미얀마 짯'},{code:'KHR',symbol:'៛',name:'캄보디아 리엘'},
  {code:'LAK',symbol:'₭',name:'라오스 킵'},{code:'MNT',symbol:'₮',name:'몽골 투그릭'},
  {code:'BND',symbol:'B$',name:'브루나이 달러'},{code:'MOP',symbol:'P',name:'마카오 파타카'},
  {code:'MVR',symbol:'Rf',name:'몰디브 루피아'},
  {code:'MXN',symbol:'MX$',name:'멕시코 페소'},{code:'BRL',symbol:'R$',name:'브라질 헤알'},
  {code:'ARS',symbol:'$',name:'아르헨티나 페소'},{code:'CLP',symbol:'$',name:'칠레 페소'},
  {code:'COP',symbol:'$',name:'콜롬비아 페소'},{code:'PEN',symbol:'S/',name:'페루 솔'},
  {code:'UYU',symbol:'$U',name:'우루과이 페소'},{code:'BOB',symbol:'Bs',name:'볼리비아 볼리비아노'},
  {code:'PYG',symbol:'₲',name:'파라과이 과라니'},{code:'VES',symbol:'Bs.S',name:'베네수엘라 볼리바르'},
  {code:'GYD',symbol:'G$',name:'가이아나 달러'},{code:'TTD',symbol:'TT$',name:'트리니다드 달러'},
  {code:'JMD',symbol:'J$',name:'자메이카 달러'},{code:'DOP',symbol:'RD$',name:'도미니카 페소'},
  {code:'HTG',symbol:'G',name:'아이티 구르드'},{code:'GTQ',symbol:'Q',name:'과테말라 케트살'},
  {code:'HNL',symbol:'L',name:'온두라스 렘피라'},{code:'NIO',symbol:'C$',name:'니카라과 코르도바'},
  {code:'CRC',symbol:'₡',name:'코스타리카 콜론'},{code:'PAB',symbol:'B/.',name:'파나마 발보아'},
  {code:'BSD',symbol:'B$',name:'바하마 달러'},{code:'BBD',symbol:'Bds$',name:'바베이도스 달러'},
  {code:'XCD',symbol:'EC$',name:'동카리브 달러'},{code:'CUP',symbol:'$',name:'쿠바 페소'},
  {code:'RUB',symbol:'₽',name:'러시아 루블'},{code:'UAH',symbol:'₴',name:'우크라이나 흐리브냐'},
  {code:'PLN',symbol:'zł',name:'폴란드 즐로티'},{code:'CZK',symbol:'Kč',name:'체코 코루나'},
  {code:'HUF',symbol:'Ft',name:'헝가리 포린트'},{code:'RON',symbol:'lei',name:'루마니아 레우'},
  {code:'BGN',symbol:'лв',name:'불가리아 레프'},{code:'ISK',symbol:'kr',name:'아이슬란드 크로나'},
  {code:'HRK',symbol:'kn',name:'크로아티아 쿠나'},{code:'RSD',symbol:'din',name:'세르비아 디나르'},
  {code:'ALL',symbol:'L',name:'알바니아 렉'},{code:'MKD',symbol:'ден',name:'북마케도니아 데나르'},
  {code:'BAM',symbol:'KM',name:'보스니아 마르크'},{code:'MDL',symbol:'L',name:'몰도바 레이'},
  {code:'BYN',symbol:'Br',name:'벨라루스 루블'},{code:'KZT',symbol:'₸',name:'카자흐스탄 텡게'},
  {code:'UZS',symbol:'сум',name:'우즈베키스탄 솜'},{code:'AZN',symbol:'₼',name:'아제르바이잔 마나트'},
  {code:'GEL',symbol:'₾',name:'조지아 라리'},{code:'AMD',symbol:'֏',name:'아르메니아 드람'},
  {code:'TJS',symbol:'SM',name:'타지키스탄 소모니'},{code:'TMT',symbol:'T',name:'투르크메니스탄 마나트'},
  {code:'KGS',symbol:'с',name:'키르기스스탄 솜'},{code:'TRY',symbol:'₺',name:'튀르키예 리라'},
  {code:'SAR',symbol:'SR',name:'사우디 리얄'},{code:'AED',symbol:'AED',name:'아랍에미리트 디르함'},
  {code:'KWD',symbol:'KD',name:'쿠웨이트 디나르'},{code:'BHD',symbol:'BD',name:'바레인 디나르'},
  {code:'OMR',symbol:'OMR',name:'오만 리얄'},{code:'QAR',symbol:'QR',name:'카타르 리얄'},
  {code:'JOD',symbol:'JD',name:'요르단 디나르'},{code:'ILS',symbol:'₪',name:'이스라엘 세켈'},
  {code:'LBP',symbol:'L£',name:'레바논 파운드'},{code:'IRR',symbol:'﷼',name:'이란 리얄'},
  {code:'IQD',symbol:'IQD',name:'이라크 디나르'},{code:'AFN',symbol:'؋',name:'아프가니스탄 아프가니'},
  {code:'ZAR',symbol:'R',name:'남아프리카 랜드'},{code:'NGN',symbol:'₦',name:'나이지리아 나이라'},
  {code:'KES',symbol:'KSh',name:'케냐 실링'},{code:'GHS',symbol:'GH₵',name:'가나 세디'},
  {code:'ETB',symbol:'Br',name:'에티오피아 비르'},{code:'TZS',symbol:'TSh',name:'탄자니아 실링'},
  {code:'UGX',symbol:'USh',name:'우간다 실링'},{code:'RWF',symbol:'FRw',name:'르완다 프랑'},
  {code:'MAD',symbol:'MAD',name:'모로코 디르함'},{code:'DZD',symbol:'DZD',name:'알제리 디나르'},
  {code:'TND',symbol:'TND',name:'튀니지 디나르'},{code:'EGP',symbol:'E£',name:'이집트 파운드'},
  {code:'SDG',symbol:'SDG',name:'수단 파운드'},{code:'GNF',symbol:'FG',name:'기니 프랑'},
  {code:'GMD',symbol:'D',name:'감비아 달라시'},{code:'SLL',symbol:'Le',name:'시에라리온 레온'},
  {code:'LRD',symbol:'L$',name:'라이베리아 달러'},{code:'NAD',symbol:'N$',name:'나미비아 달러'},
  {code:'BWP',symbol:'P',name:'보츠와나 풀라'},{code:'ZMW',symbol:'ZK',name:'잠비아 콰차'},
  {code:'MZN',symbol:'MT',name:'모잠비크 메티칼'},{code:'AOA',symbol:'Kz',name:'앙골라 콴자'},
  {code:'CDF',symbol:'FC',name:'콩고 프랑'},{code:'MGA',symbol:'Ar',name:'마다가스카르 아리아리'},
  {code:'MUR',symbol:'₨',name:'모리셔스 루피'},{code:'XOF',symbol:'CFA',name:'서아프리카 CFA 프랑'},
  {code:'XAF',symbol:'CFA',name:'중앙아프리카 CFA 프랑'},{code:'FJD',symbol:'FJ$',name:'피지 달러'},
  {code:'PGK',symbol:'K',name:'파푸아뉴기니 키나'},{code:'SBD',symbol:'SI$',name:'솔로몬 달러'},
  {code:'TOP',symbol:'T$',name:'통가 파앙아'},{code:'WST',symbol:'WS$',name:'사모아 탈라'},
  {code:'VUV',symbol:'VT',name:'바누아투 바투'},{code:'XPF',symbol:'CFP',name:'태평양 프랑'},
];
function getCurrSymbol() {
  try { const c = JSON.parse(localStorage.getItem(CURRENCY_SK)); return (c && c.symbol) ? c.symbol : '₩'; } catch { return '₩'; }
}
function getCurrCode() {
  try { const c = JSON.parse(localStorage.getItem(CURRENCY_SK)); return (c && c.code) ? c.code : 'KRW'; } catch { return 'KRW'; }
}

// ₩ 기호를 작게 처리한 금액 HTML
const fmtH = n => `<span class="w-sym">${getCurrSymbol()}</span>${Math.abs(n).toLocaleString()}`;

// ── 상태 ──────────────────────────────────────────────────────
let txs        = [];
let customCats = { expense: [], income: [] }; // [{emoji, name}]
let curMonth   = new Date().toISOString().slice(0,7);
let curType    = 'expense';
let photosData = []; // base64 array
let calVisible = false;
let prevTab    = 'ledger'; // 달력 닫을 때 돌아갈 탭
let activeTxId   = null;
let editingTxId  = null;
let daySheetDate = null;
let statsPeriod      = 'month';
let weekOffset       = 0;  // 0=이번주, 1=지난주, ...
let statsType        = 'expense';
let statsGroupBy     = 'category';   // 'category' | 'payment'
let statsCustomActive = false;
let statsCustomFrom  = '';
let statsCustomTo    = '';
let donutChart       = null;
const WIDGET_DEFS = [
  { id: 'insight',   label: '이번 달 요약',    icon: '📊' },
  { id: 'champion',  label: '최고 지출 항목',  icon: '🏆' },
  { id: 'dayofweek', label: '요일별 소비 패턴', icon: '📅' },
  { id: 'survival',  label: '목표 예산',  icon: '💰' },
  { id: 'mbti',      label: '나의 소비 MBTI',   icon: '🧬' },
  { id: 'top3cats',  label: '카테고리 TOP 3',   icon: '🥇' },
];
const WIDGETS_SK = 'ddgb_widgets_v1';
let reportWidgets  = JSON.parse(localStorage.getItem(WIDGETS_SK) || '["insight","champion","dayofweek"]');
let reportEditMode = false;
const SURV_SK  = 'ddgb_surv_v1';
let survGoal = (() => {
  try {
    const g = JSON.parse(localStorage.getItem(SURV_SK)||'{}');
    const budgets = g.budgets || { week: {}, month:{}, year:{} };
    if (!budgets.month) budgets.month = {};
    if (!budgets.year)  budgets.year  = {};
    // 구버전 호환: week가 숫자면 객체로 변환
    if (typeof budgets.week === 'number') budgets.week = {};
    return { mode: g.mode||'month', budgets, weekOffset: g.weekOffset||0, monthOffset: g.monthOffset||0, yearOffset: g.yearOffset||0 };
  } catch { return { mode:'month', budgets:{ week:0, month:{}, year:{} }, weekOffset:0, monthOffset:0, yearOffset:0 }; }
})();
// 현재 모드·기간에 해당하는 예산 키 반환
function _survKey() {
  const today = new Date();
  if (survGoal.mode === 'week') {
    const dSinceM = (today.getDay() + 6) % 7; // 월=0, 일=6
    const mon = new Date(today);
    mon.setDate(today.getDate() - dSinceM - (survGoal.weekOffset||0) * 7);
    return 'w_' + localDateStr(mon);
  }
  if (survGoal.mode === 'year') return String(today.getFullYear() - (survGoal.yearOffset||0));
  // month 모드: curMonth 무관, 자체 monthOffset으로 계산
  const d = new Date(today.getFullYear(), today.getMonth() - (survGoal.monthOffset||0), 1);
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
}
// 현재 슬롯 예산 읽기
function _getSurvBudget() {
  const b = survGoal.budgets, k = _survKey();
  if (survGoal.mode === 'week')  return (b.week[k])  || 0;
  if (survGoal.mode === 'year')  return (b.year[k])  || 0;
  return (b.month[k]) || 0;
}
// 현재 슬롯 예산 쓰기
function _setSurvBudget(val) {
  const b = survGoal.budgets, k = _survKey();
  if (survGoal.mode === 'week')       b.week[k]  = val;
  else if (survGoal.mode === 'year')  b.year[k]  = val;
  else                                b.month[k] = val;
}

// ── 로드 ──────────────────────────────────────────────────────
function load() {
  try { txs = JSON.parse(localStorage.getItem(SK)||'[]'); } catch { txs=[]; }
  // 구버전 photo → photos 마이그레이션
  txs = txs.map(t => {
    if (t.photo && !t.photos) { t.photos = [t.photo]; delete t.photo; }
    if (!t.photos) t.photos = [];
    return t;
  });
  try { customCats = JSON.parse(localStorage.getItem(CATS_SK)||'{"expense":[],"income":[]}'); }
  catch { customCats = {expense:[],income:[]}; }
  // 저장된 커스텀 카테고리 아이콘을 CAT_ICON_MAP에 복원
  [...(customCats.expense||[]), ...(customCats.income||[])].forEach(c => {
    if (c.lu && c.bg) CAT_ICON_MAP[c.name] = { lu: c.lu, bg: c.bg, c: '#fff' };
  });
}
function persist()     { localStorage.setItem(SK,      JSON.stringify(txs)); }
function persistCats() { localStorage.setItem(CATS_SK, JSON.stringify(customCats)); }

// ── 유틸 ──────────────────────────────────────────────────────
const fmt = n => getCurrSymbol() + Math.abs(n).toLocaleString();
const fmtShort = n => {
  const a = Math.abs(n);
  const sym = getCurrSymbol();
  if (getCurrCode() === 'KRW') {
    if (a >= 1000000000000) { const v = a/1000000000000; return sym+(v%1?v.toFixed(1)+'조':v+'조'); }
    if (a >= 100000000)     { const v = a/100000000;     return sym+(v%1?v.toFixed(1)+'억':v+'억'); }
    if (a >= 10000)         { const v = a/10000;          return sym+(v%1?v.toFixed(1)+'만':v+'만'); }
  }
  return sym+a.toLocaleString();
};
const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function monthOf(ym)   { return txs.filter(t => t.date.startsWith(ym)); }
function prevMonth(ym) {
  const [y,m] = ym.split('-').map(Number);
  const d = new Date(y, m-2, 1);
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
}
function getIcon(cat) {
  // DB 카테고리 최우선
  const dbAll   = [...(dbCats.expense||[]), ...(dbCats.income||[])];
  const dbFound = dbAll.find(c => c.name === cat);
  if (dbFound) return dbFound.icon || '📦';
  // BASE_ICONS 하드코딩 폴백
  if (BASE_ICONS[cat]) return BASE_ICONS[cat];
  // 로컬 localStorage 폴백
  const all   = [...(customCats.expense||[]), ...(customCats.income||[])];
  const found = all.find(c => c.name === cat);
  return found ? (found.emoji || found.icon || '📦') : '📦';
}

// ── tx-row HTML 공통 빌더 ───────────────────────────────────
function txRowHtml(t, extraOnclick) {
  const photos = Array.isArray(t.photos) ? t.photos : (t.photo ? [t.photo] : []);
  const firstPhoto = photos[0] || null;
  const thumb = firstPhoto
    ? `<div class="tx-thumb-slot" onclick="event.stopPropagation();openPhoto('${firstPhoto}')">
         <img src="${firstPhoto}" alt="">
       </div>`
    : '';
  const ibg = getIconBg(t.category);
  const sign = t.type === 'income' ? '+' : '−';
  return `<div class="tx-row" onclick="${extraOnclick||''}openTxAction('${t.id}')">
    <div class="tx-icon" style="background:${ibg}">${getIconHtml(t.category)}</div>
    <div class="tx-info">
      <div class="tx-desc">${(t.description && t.description !== t.category) ? esc(t.description) : dn(t.category, CAT_NAME_MAP)}</div>
      <div class="tx-cat">${dn(t.category, CAT_NAME_MAP)}${t.payment?` · ${dn(t.payment, PAY_NAME_MAP)}`:''}</div>
    </div>
    <div class="tx-right">
      ${thumb}
      <div class="tx-amt ${t.type}">${sign}${fmtH(t.amount)}</div>
    </div>
  </div>`;
}

// ── 사진 캐러셀 빌더 ────────────────────────────────────────
function buildCarousel(photos, id) {
  const slides = photos.map(src =>
    `<img src="${src}" alt="" onclick="openPhoto('${src}')">`
  ).join('');
  const dots = photos.length > 1
    ? `<div class="photo-carousel-dots" id="dots-${id}">` +
      photos.map((_,i) => `<div class="photo-carousel-dot${i===0?' on':''}" onclick="goSlide('${id}',${i})"></div>`).join('') +
      `</div>`
    : '';
  return `<div class="photo-carousel-wrap" id="car-${id}">
    <div class="photo-carousel-inner" id="inner-${id}">${slides}</div>
  </div>${dots}`;
}
function initCarousel(id) {
  const wrap = document.getElementById('car-'+id);
  const inner = document.getElementById('inner-'+id);
  if (!wrap || !inner) return;
  let cur = 0, startX = 0, dx = 0, dragging = false;
  wrap.addEventListener('touchstart', e => { startX = e.touches[0].clientX; dragging = true; dx = 0; }, {passive:true});
  wrap.addEventListener('touchmove', e => { if (!dragging) return; dx = e.touches[0].clientX - startX; }, {passive:true});
  wrap.addEventListener('touchend', () => {
    if (!dragging) return; dragging = false;
    const count = inner.children.length;
    if (dx < -40 && cur < count-1) cur++;
    else if (dx > 40 && cur > 0) cur--;
    goSlide(id, cur);
  });
}
function goSlide(id, idx) {
  const inner = document.getElementById('inner-'+id);
  if (!inner) return;
  const count = inner.children.length;
  idx = Math.max(0, Math.min(count-1, idx));
  inner.style.transform = `translateX(-${idx*100}%)`;
  const dots = document.getElementById('dots-'+id);
  if (dots) dots.querySelectorAll('.photo-carousel-dot').forEach((d,i)=>d.classList.toggle('on',i===idx));
}

// ── 탭 전환 ──────────────────────────────────────────────────
function goTab(name) {
  calVisible = (name === 'calendar');
  TABS.forEach(t => {
    document.getElementById('pane-'+t).classList.toggle('active', t===name);
    const b = document.getElementById('tb-'+t);
    if (b) b.classList.toggle('on', t===name);
  });
  const isMe     = name === 'me';
  const isStats  = name === 'stats';
  const isReport = name === 'report';
  const isLedger = (name === 'ledger' || name === 'calendar');
  // 요약 스트립: 가계부/달력 탭만 표시 (분석은 헤더 네비로 대체)
  const sumStrip = document.getElementById('sumStrip');
  sumStrip.style.display = isLedger ? 'block' : 'none';
  document.getElementById('sumCols').style.display = isLedger ? 'flex' : 'none';
  sumStrip.classList.toggle('no-cols', !isLedger);
  // 헤더 모드
  const appHeader = document.getElementById('appHeader');
  appHeader.classList.toggle('stats-mode', isStats);
  appHeader.classList.toggle('me-mode', isMe);
  appHeader.classList.toggle('ledger-mode', isLedger);
  appHeader.style.position = isMe ? 'relative' : '';
  document.querySelector('.header-title').style.visibility = isMe ? 'hidden' : 'visible';
  document.getElementById('headerCenterTitle').style.display = isMe ? 'block' : 'none';
  document.getElementById('statsHeaderMonth').style.display = 'none';
  document.getElementById('statsHdrNav').style.display = isStats ? 'flex' : 'none';
  document.getElementById('reportHdrNav').style.display = isReport ? 'flex' : 'none';
  document.getElementById('headerActions').style.display = isMe ? 'none' : 'flex';
  if (!isMe) {
    document.getElementById('haDefault').style.display = (isStats || isReport) ? 'none' : 'flex';
    document.getElementById('haStats').style.display   = isStats ? 'flex' : 'none';
  }
  document.getElementById('monthLabel').classList.toggle('picker-mode', isStats);
  setMonthLabel();
  if (name==='ledger')   { prevTab='ledger';   renderLedger(); }
  if (name==='calendar') { prevTab='calendar'; renderCalendar(); }
  if (name==='stats')    renderStats();
  if (name==='report')   renderReport();
  if (name==='me')       renderMeStreak();
  // 결산 외 탭에서는 편집 버튼 숨김
  const editBar = document.getElementById('reportEditBar');
  if (editBar) editBar.style.display = name === 'report' ? 'block' : 'none';
}

// ── 달력 토글 ────────────────────────────────────────────────
function toggleCalendar() {
  if (calVisible) {
    goTab('ledger');
  } else {
    goTab('calendar');
  }
}

// ── 월 이동 ──────────────────────────────────────────────────
function changeMonth(d) {
  const active = TABS.find(t => document.getElementById('pane-'+t).classList.contains('active'));
  if (active === 'stats' && statsPeriod === 'week') {
    weekOffset -= d; // ‹(d=-1)→+1(지난주), ›(d=1)→-1(다음주)
    if (weekOffset < 0) weekOffset = 0; // 미래로 못 감
    setMonthLabel();
    renderStats();
    return;
  }
  const [y,m] = curMonth.split('-').map(Number);
  const step = (active === 'stats' && statsPeriod === 'year') ? d * 12 : d;
  const nd = new Date(y, m-1+step, 1);
  curMonth = nd.getFullYear()+'-'+String(nd.getMonth()+1).padStart(2,'0');
  setMonthLabel();
  if (active==='ledger')   renderLedger();
  if (active==='calendar') renderCalendar();
  if (active==='stats')    renderStats();
  if (active==='report')   renderReport();
}
function getStatsHeaderLabel() {
  if (statsCustomActive && statsCustomFrom && statsCustomTo) {
    const f = statsCustomFrom.slice(5).replace('-','.');
    const t = statsCustomTo.slice(5).replace('-','.');
    return `${f}~${t}`;
  }
  const [y,m] = curMonth.split('-');
  if (statsPeriod === 'month') return fmtYearMonth(y, m);
  if (statsPeriod === 'year')  return tr('stats.yearTotal').replace('{y}', y);
  const {from: wf, to: wt} = getWeekRange();
  const pad = s => s.slice(5).replace('-','.');
  return `${pad(wf)}~${pad(wt)}`;
}

function setMonthLabel() {
  const active = TABS.find(t => document.getElementById('pane-'+t)?.classList.contains('active'));
  const [y,m] = curMonth.split('-');
  const monthText = fmtYearMonth(y, m);
  if (active === 'stats') {
    document.getElementById('monthLabel').textContent = getStatsHeaderLabel();
    const sh = document.getElementById('statsHdrLabel');
    if (sh) sh.textContent = getStatsHeaderLabel();
  } else {
    document.getElementById('monthLabel').textContent = monthText;
  }
  const rh = document.getElementById('reportHdrLabel');
  if (rh) rh.textContent = monthText;
  // 챔피언 카드 월 네비 동기화
  const [ry,rm] = curMonth.split('-');
  const now = new Date();
  const nowYM = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  const cLabel = document.getElementById('rChampMLabel');
  if (cLabel) cLabel.textContent = fmtYearMonth(ry, rm);
  const cNext = document.getElementById('rChampMNext');
  if (cNext) cNext.disabled = curMonth >= nowYM;
  updateNextBtn(active);
}

function updateNextBtn(active) {
  const btn = document.querySelector('.month-nav .month-btn:last-child');
  if (!btn) return;
  const now = new Date();
  const nowYM = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  let disabled = false;
  if (active === 'stats') {
    if (statsPeriod === 'week')  disabled = weekOffset <= 0;
    if (statsPeriod === 'month') disabled = curMonth >= nowYM;
    if (statsPeriod === 'year')  disabled = curMonth.slice(0,4) >= String(now.getFullYear());
  } else {
    disabled = curMonth >= nowYM;
  }
  btn.disabled = disabled;
  const hdrBtn = document.getElementById('statsHdrBtnNext');
  if (hdrBtn) hdrBtn.disabled = disabled;
  const rBtn = document.getElementById('reportHdrBtnNext');
  if (rBtn) rBtn.disabled = curMonth >= nowYM;
}

// ── 달력 렌더 ────────────────────────────────────────────────
function renderCalendar() {
  const [y,m] = curMonth.split('-').map(Number);
  const today = new Date().toISOString().slice(0,10);
  const firstDay = new Date(y, m-1, 1).getDay();
  const lastDate = new Date(y, m, 0).getDate();
  const prevLastDate = new Date(y, m-1, 0).getDate();

  // 거래 맵 {date: {exp, inc}}
  const map = {};
  txs.forEach(t => {
    if (!map[t.date]) map[t.date] = {exp:0,inc:0};
    if (t.type==='expense') map[t.date].exp += t.amount;
    else map[t.date].inc += t.amount;
  });

  let cells = '';
  // 이전 달 빈 칸
  for (let i=0; i<firstDay; i++) {
    const d = prevLastDate - firstDay + 1 + i;
    cells += `<div class="cal-cell other-month"><span class="cal-day">${d}</span></div>`;
  }
  // 이번 달
  for (let d=1; d<=lastDate; d++) {
    const dateStr = y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const dow = new Date(dateStr).getDay();
    const isToday = dateStr===today ? 'today' : '';
    const dayClass = dow===0?'sun':dow===6?'sat':'';
    const info = map[dateStr];
    let dots='', amtStr='';
    if (info) {
      if (info.exp) dots += '<span class="cal-dot e"></span>';
      if (info.inc) dots += '<span class="cal-dot i"></span>';
      if (info.exp) amtStr = '-'+fmt(info.exp).replace('₩','');
    }
    cells += `<div class="cal-cell ${isToday}" onclick="openDaySheet('${dateStr}')">
      <span class="cal-day ${dayClass}">${d}</span>
      <div class="cal-dots">${dots}</div>
      ${amtStr?`<div class="cal-amt">${amtStr}</div>`:''}
    </div>`;
  }
  // 다음 달 빈 칸
  const total = firstDay + lastDate;
  const remain = total % 7 === 0 ? 0 : 7 - (total % 7);
  for (let d=1; d<=remain; d++) {
    cells += `<div class="cal-cell other-month"><span class="cal-day">${d}</span></div>`;
  }
  document.getElementById('calGrid').innerHTML = cells;
}

// ── 날짜 내역 시트 ────────────────────────────────────────────
function openModalForDate() {
  document.getElementById('daySheet').classList.remove('show');
  editingTxId = null;
  document.getElementById('modalTitle').textContent = '내역 추가';
  document.getElementById('fDate').value = daySheetDate || new Date().toISOString().slice(0,10);
  document.getElementById('fAmt').value = '';
  document.getElementById('fDesc').value = '';
  buildPaySelect('현금');
  photosData = [];
  renderPhotoGrid();
  document.getElementById('newCatBox').classList.remove('show');
  resetIconPicker();
  resetPayIconPicker();
  setType('expense');
  document.getElementById('modal').classList.add('show');
  setTimeout(() => document.getElementById('fAmt').focus(), 150);
}

function openDaySheet(dateStr) {
  daySheetDate = dateStr;
  const [,, dd] = dateStr.split('-');
  const dow = ['일','월','화','수','목','금','토'][new Date(dateStr).getDay()];
  document.getElementById('daySheetTitle').textContent = parseInt(dd)+'일 ('+dow+')';
  const rows = txs.filter(t=>t.date===dateStr);
  if (!rows.length) {
    document.getElementById('daySheetBody').innerHTML = '<div class="empty-msg" style="padding:30px">내역이 없어요</div>';
  } else {
    document.getElementById('daySheetBody').innerHTML = rows.map(t=>
      txRowHtml(t, `document.getElementById('daySheet').classList.remove('show');`)
    ).join('');
  }
  document.getElementById('daySheet').classList.add('show');
}
function closeDaySheet(e) {
  if (e.target===document.getElementById('daySheet'))
    document.getElementById('daySheet').classList.remove('show');
}

// ── 가계부 렌더 ──────────────────────────────────────────────
function renderLedger() {
  const list = monthOf(curMonth);
  let inc=0, exp=0;
  list.forEach(t => t.type==='income' ? inc+=t.amount : exp+=t.amount);
  document.getElementById('sumInc').textContent = fmt(inc);
  document.getElementById('sumExp').textContent = fmt(exp);
  const bal = inc-exp;
  const bEl = document.getElementById('sumBal');
  bEl.textContent = (bal<0?'-':'')+fmt(bal);
  // 큰 금액일 때 글자 크기 축소
  [document.getElementById('sumInc'), document.getElementById('sumExp'), bEl].forEach(el => {
    const len = el.textContent.replace(/[₩,\-]/g,'').length;
    el.style.fontSize = len >= 10 ? '11px' : len >= 8 ? '13px' : '';
  });
  bEl.style.color = bal<0 ? 'var(--expense)' : 'var(--income)';

  if (!list.length) {
    document.getElementById('txList').innerHTML =
      `<div class="empty-msg">${tr('ledger.empty')}</div>`;
    applyLang();
    return;
  }
  const grouped = {};
  list.forEach(t => (grouped[t.date]=grouped[t.date]||[]).push(t));
  const dates = Object.keys(grouped).sort().reverse();
  const DOW_KEYS = ['day.sun','day.mon','day.tue','day.wed','day.thu','day.fri','day.sat'];
  document.getElementById('txList').innerHTML =
    `<div class="tx-section-title" data-i18n="section.txHistory">${tr('section.txHistory')}</div>` +
    dates.map(date=>{
    const rows = grouped[date];
    const dExp = rows.filter(t=>t.type==='expense').reduce((s,t)=>s+t.amount,0);
    const dInc = rows.filter(t=>t.type==='income').reduce((s,t)=>s+t.amount,0);
    const [,,dd] = date.split('-');
    const dowIdx = new Date(date).getDay();
    const dow = tr(DOW_KEYS[dowIdx]);
    const dateLabel = tr('fmt.dateGroup').replace('{d}', parseInt(dd)).replace('{dow}', dow);
    let dayTotal='';
    if (dInc) dayTotal+=`<span style="color:#93C5FD">+${fmt(dInc)}</span> `;
    if (dExp) dayTotal+=`<span style="color:#FCA5A5">-${fmt(dExp)}</span>`;
    return `<div class="date-group">
      <div class="date-header"><span>${dateLabel}</span><span>${dayTotal}</span></div>
      ${rows.map(t=>txRowHtml(t)).join('')}
    </div>`;
  }).join('') + '';
  refreshIcons();
  applyLang();
}

// ── 통계 ─────────────────────────────────────────────────────
// 지출 팔레트 (웜톤)
const COLORS_EXPENSE = [
  '#FF6384','#FF9F40','#FFCE56','#FF6347','#F06292',
  '#E57373','#FFB74D','#FF8A65','#FFAB40','#FF80AB',
  '#FFA726','#EF5350'
];
// 수입 팔레트 (쿨톤/민트)
const COLORS_INCOME = [
  '#36A2EB','#4BC0C0','#26C6DA','#66BB6A','#42A5F5',
  '#26A69A','#80DEEA','#80CBC4','#81D4FA','#A5D6A7',
  '#4DB6AC','#4FC3F7'
];

const PAYMENT_ICONS = {
  '현금':'💵','신용카드':'💳','체크카드':'🏦','계좌이체':'🏧',
  '카카오페이':'🟡','네이버페이':'🟢','토스':'🔵','기타':'📌'
};

function setStatsType(type) {
  statsType = type;
  document.getElementById('st-expense').classList.toggle('on', type === 'expense');
  document.getElementById('st-income').classList.toggle('on',  type === 'income');
  renderStats();
}

function setStatsGroup(g) {
  statsGroupBy = g;
  document.getElementById('sg-category').classList.toggle('on', g === 'category');
  document.getElementById('sg-payment').classList.toggle('on',  g === 'payment');
  // rg-btn 동기화
  document.querySelectorAll('.rg-btn').forEach(b =>
    b.classList.toggle('on', b.id === 'sg-' + (g === 'category' ? 'category' : 'payment'))
  );
  renderStats();
}

function localDateStr(d) {
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}
function getWeekRange() {
  const today = new Date();
  const day = today.getDay(); // 0=일, 6=토
  const sun = new Date(today);
  sun.setDate(today.getDate() - day - weekOffset * 7); // 해당 주 일요일
  const sat = new Date(sun);
  sat.setDate(sun.getDate() + 6); // 해당 주 토요일
  return { from: localDateStr(sun), to: localDateStr(sat) };
}

function setStatsPeriod(p) {
  statsPeriod = p;
  statsCustomActive = false;
  if (p === 'week') weekOffset = 0;
  ['week','month','year'].forEach(k =>
    document.getElementById('hpf-'+k).classList.toggle('on', k===p)
  );
  setMonthLabel();
  renderStats();
}

function getStatsTxs() {
  if (statsCustomActive && statsCustomFrom && statsCustomTo) {
    return txs.filter(t => t.date >= statsCustomFrom && t.date <= statsCustomTo);
  }
  if (statsPeriod === 'month') return monthOf(curMonth);
  if (statsPeriod === 'year') {
    const y = curMonth.slice(0,4);
    return txs.filter(t => t.date.startsWith(y));
  }
  const {from: wf, to: wt} = getWeekRange();
  return txs.filter(t => t.date >= wf && t.date <= wt);
}

function getStatsPeriodLabel() {
  if (statsCustomActive && statsCustomFrom && statsCustomTo)
    return `${statsCustomFrom} ~ ${statsCustomTo}`;
  const [y,m] = curMonth.split('-');
  if (statsPeriod === 'month') return fmtYearMonth(y, m);
  if (statsPeriod === 'year')  return tr('stats.yearTotal').replace('{y}', y);
  const today = new Date();
  const from  = new Date(today); from.setDate(from.getDate()-6);
  return `${from.getMonth()+1}/${from.getDate()} ~ ${today.getMonth()+1}/${today.getDate()}`;
}

function renderStats() {
  const typeLabel = statsType === 'expense' ? tr('lbl.expense') : tr('lbl.income');
  const grpLabel  = statsGroupBy === 'category' ? tr('lbl.category') : tr('lbl.payment');
  const palette   = statsType === 'expense' ? COLORS_EXPENSE : COLORS_INCOME;
  const amtColor  = statsType === 'expense' ? '#EF4444' : '#3B82F6';

  const getKey  = t => statsGroupBy === 'payment' ? (t.payment || '기타') : t.category;
  const getIco  = k => statsGroupBy === 'payment' ? (PAYMENT_ICONS[k]||'💳') : getIcon(k);

  const filtered = getStatsTxs().filter(t => t.type === statsType);
  const total    = filtered.reduce((s,t) => s+t.amount, 0);
  const totals   = {};
  filtered.forEach(t => { const k=getKey(t); totals[k]=(totals[k]||0)+t.amount; });
  const sorted   = Object.entries(totals).sort((a,b) => b[1]-a[1]);

  const emptyEl    = document.getElementById('statsEmpty');
  const donutSec   = document.getElementById('donutSection');
  const rankingSec = document.getElementById('rankingSection');

  // 헤더 라벨 동기화
  setMonthLabel();
  document.getElementById('donutCenterLabel').textContent = tr('stats.totalLabel').replace('{type}', typeLabel);
  document.getElementById('rankingHeaderTitle').textContent = tr('stats.rankFmt').replace('{group}', grpLabel).replace('{type}', typeLabel);

  if (!sorted.length) {
    emptyEl.innerHTML = `<div class="stats-empty-state">
      <div class="stats-empty-icon">📊</div>
      <div class="stats-empty-title">${tr('stats.noData')}</div>
      <div class="stats-empty-sub">${tr('stats.noDataSub').replace('{period}', getStatsHeaderLabel()).replace('{type}', typeLabel)}</div>
    </div>`;
    emptyEl.style.display    = 'block';
    donutSec.style.display   = 'none';
    rankingSec.style.display = 'none';
    if (donutChart) { donutChart.destroy(); donutChart = null; }
    return;
  }
  emptyEl.style.display    = 'none';
  donutSec.style.display   = 'block';
  rankingSec.style.display = 'block';

  document.getElementById('donutCenterAmt').textContent = fmt(total);

  const labels  = sorted.map(([k]) => statsGroupBy==='payment' ? dn(k, PAY_NAME_MAP) : dn(k, CAT_NAME_MAP));
  const amounts = sorted.map(([,v]) => v);
  const colors  = sorted.map(([k]) => getIconBg(k));

  // 3% 미만 항목을 '기타'로 묶기
  const threshold = total * 0.03;
  const mainItems = [], etcItems = [];
  sorted.forEach(([k, v], i) => {
    if (v >= threshold) mainItems.push({ label: k, amt: v, color: colors[i] });
    else etcItems.push({ label: k, amt: v });
  });
  const etcTotal = etcItems.reduce((s, x) => s + x.amt, 0);
  const _nameMap = statsGroupBy === 'payment' ? PAY_NAME_MAP : CAT_NAME_MAP;
  const chartLabels  = mainItems.map(x => dn(x.label, _nameMap));
  const chartAmounts = mainItems.map(x => x.amt);
  const chartColors  = mainItems.map(x => x.color);
  if (etcTotal > 0) {
    chartLabels.push(tr('lbl.other') + ' (' + etcItems.map(x => dn(x.label, _nameMap)).join(', ') + ')');
    chartAmounts.push(etcTotal);
    chartColors.push('#B0BEC5');
  }

  const ctx = document.getElementById('donutCanvas').getContext('2d');
  if (donutChart) donutChart.destroy();
  donutChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: chartLabels,
      datasets: [{ data: chartAmounts, backgroundColor: chartColors, borderWidth: 2, borderColor: '#fff', hoverOffset: 8, hoverBorderWidth: 0 }]
    },
    options: {
      cutout: '70%',
      rotation: -90,
      layout: { padding: 10 },
      plugins: {
        legend: { display: false },
        tooltip: {
          enabled: false,
          external: function({ chart, tooltip }) {
            let el = document.getElementById('donutTooltip');
            if (!el) {
              el = document.createElement('div');
              el.id = 'donutTooltip';
              document.body.appendChild(el);
            }
            if (tooltip.opacity === 0) { el.style.opacity = '0'; return; }
            const dp  = tooltip.dataPoints[0];
            const amt = chartAmounts[dp.dataIndex];
            const pct = Math.round(amt / total * 100);
            el.innerHTML = `<span class="dtt-name">${dp.label}</span><span class="dtt-amt">${fmt(amt)}</span><span class="dtt-pct">${pct}%</span>`;
            const rect = chart.canvas.getBoundingClientRect();
            let x = rect.left + tooltip.caretX + 14;
            let y = rect.top  + tooltip.caretY - 14;
            if (x + 160 > window.innerWidth) x = rect.left + tooltip.caretX - 170;
            el.style.left    = x + 'px';
            el.style.top     = y + 'px';
            el.style.opacity = '1';
          }
        }
      },
      onClick: (_e, els) => { if (els.length > 0) highlightRankItem(els[0].index); },
      animation: { animateRotate: true, duration: 700 }
    }
  });

  document.getElementById('rankingList').innerHTML = sorted.map(([k, amt], i) => { // eslint-disable-line
    const pct      = Math.round(amt / total * 100);
    const color    = colors[i];
    const numClass = i < 3 ? 'rank-num top' : 'rank-num';
    return `<div class="ranking-item" id="rank-item-${i}"
        onclick="highlightChartSlice(${i});openGroupDetail('${esc(k)}')">
      <div class="${numClass}">${i+1}</div>
      <div class="rank-dot" style="background:${color}"></div>
      <div class="rank-icon" style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:${getIconBg(k)}">${getIconHtml(k,14)}</div>
      <div class="rank-info">
        <div class="rank-name">${statsGroupBy==='payment' ? dn(k, PAY_NAME_MAP) : dn(k, CAT_NAME_MAP)}</div>
        <div class="rank-bar-wrap"><div class="rank-bar" style="width:${pct}%;background:${color}"></div></div>
      </div>
      <div class="rank-right">
        <div class="rank-pct">${pct}%</div>
        <div class="rank-amt" style="color:${amtColor}">${fmtH(amt)}</div>
      </div>
    </div>`;
  }).join('');
  refreshIcons();
}

function highlightRankItem(idx) {
  document.querySelectorAll('.ranking-item').forEach((el, i) =>
    el.classList.toggle('highlighted', i === idx)
  );
  const t = document.getElementById('rank-item-'+idx);
  if (t) t.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  clearTimeout(window._rankHL);
  window._rankHL = setTimeout(() =>
    document.querySelectorAll('.ranking-item').forEach(el => el.classList.remove('highlighted'))
  , 2500);
}

function highlightChartSlice(idx) {
  if (!donutChart) return;
  donutChart.setActiveElements([{ datasetIndex: 0, index: idx }]);
  donutChart.tooltip.setActiveElements([{ datasetIndex: 0, index: idx }], { x: 0, y: 0 });
  donutChart.update('active');
}

function openGroupDetail(key) {
  const getKey = t => statsGroupBy === 'payment' ? (t.payment || '기타') : t.category;
  const getIco = k => statsGroupBy === 'payment' ? (PAYMENT_ICONS[k]||'💳') : getIcon(k);
  const rows = getStatsTxs()
    .filter(t => t.type === statsType && getKey(t) === key)
    .sort((a,b) => b.date.localeCompare(a.date));
  const total = rows.reduce((s,t) => s+t.amount, 0);
  const _ck = statsGroupBy === 'payment' ? '기타' : key;
  document.getElementById('catdetTitle').innerHTML =
    `<span style="display:inline-flex;align-items:center;gap:8px">${getIconHtml(_ck,16)} ${statsGroupBy==='payment' ? dn(key, PAY_NAME_MAP) : dn(key, CAT_NAME_MAP)}</span>
     <span style="font-size:14px;font-weight:400;opacity:.8;margin-left:8px">${fmtH(total)}</span>`;
  refreshIcons();
  document.getElementById('catdetBody').innerHTML = rows.length
    ? rows.map(t => txRowHtml(t)).join('')
    : `<div class="search-empty">${tr('empty.noRecords')}</div>`;
  document.getElementById('catdetOverlay').classList.add('show');
}

// 하위 호환
function openCatDetail(cat) { openGroupDetail(cat); }

// ── 기간 선택 ────────────────────────────────────────────────
function onMonthLabelClick() {
  const active = TABS.find(t => document.getElementById('pane-'+t)?.classList.contains('active'));
  if (active === 'stats') openDateRangePicker();
}

function openDateRangePicker() {
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('drFrom').value = statsCustomActive ? statsCustomFrom : curMonth + '-01';
  document.getElementById('drTo').value   = statsCustomActive ? statsCustomTo   : today;
  document.getElementById('daterangeOverlay').classList.add('show');
}

function closeDateRange() {
  document.getElementById('daterangeOverlay').classList.remove('show');
}

function setPreset(p) {
  const today = new Date();
  const toStr = today.toISOString().slice(0,10);
  if (p === 'week') {
    const from = new Date(today); from.setDate(from.getDate()-6);
    document.getElementById('drFrom').value = from.toISOString().slice(0,10);
    document.getElementById('drTo').value   = toStr;
  } else if (p === 'month') {
    document.getElementById('drFrom').value = curMonth + '-01';
    document.getElementById('drTo').value   = toStr;
  } else if (p === 'last-month') {
    const prev = prevMonth(curMonth);
    const lastDay = new Date(parseInt(prev.slice(0,4)), parseInt(prev.slice(5,7)), 0).getDate();
    document.getElementById('drFrom').value = prev + '-01';
    document.getElementById('drTo').value   = prev + '-' + String(lastDay).padStart(2,'0');
  }
}

function applyDateRange() {
  const from = document.getElementById('drFrom').value;
  const to   = document.getElementById('drTo').value;
  if (!from || !to) { alert('시작일과 종료일을 선택해주세요.'); return; }
  if (from > to) { alert('종료일이 시작일보다 빠를 수 없어요.'); return; }
  statsCustomFrom   = from;
  statsCustomTo     = to;
  statsCustomActive = true;
  // 기간 버튼 선택 해제
  ['week','month','year'].forEach(k =>
    document.getElementById('hpf-'+k).classList.remove('on')
  );
  closeDateRange();
  renderStats();
}

// ── 결산 위젯 HTML 생성 ────────────────────────────────────
const CARD_IDS = { insight:'rInsightCard', champion:'rChampCard', dayofweek:'rDowCard', survival:'rSurvivalCard', mbti:'rMbtiCard', top3cats:'rTop3Card' };
function widgetInsightHTML() {
  return `<div class="widget-card" id="rInsightCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="rc-body">
      <div class="rc-tag neutral" id="rInsightTag">#분석중</div>
      <div class="rc-emoji" id="rEmoji">📊</div>
      <div class="rc-insight" id="rInsight">분석 중...</div>
      <div class="rc-sub" id="rInsightSub"></div>
      <div class="rc-compare">
        <div class="rc-cmp-col"><div class="rc-cmp-label" id="rLabelPrev"></div><div class="rc-cmp-val" id="rValPrev">₩0</div></div>
        <div class="rc-cmp-arr">→</div>
        <div class="rc-cmp-col"><div class="rc-cmp-label" id="rLabelThis"></div><div class="rc-cmp-val" id="rValThis">₩0</div></div>
      </div>
    </div>
  </div>`;
}
function widgetChampHTML() {
  return `<div class="widget-card" id="rChampCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="champ-header"><span class="champ-header-label">🏆 <span data-i18n="widget.champ">최고 지출</span></span></div>
    <div class="champ-body">
      <div class="champ-mnav">
        <button class="champ-mnav-btn" id="rChampMPrev" onclick="changeMonth(-1)">‹</button>
        <span class="champ-mnav-label" id="rChampMLabel"></span>
        <button class="champ-mnav-btn" id="rChampMNext" onclick="changeMonth(1)">›</button>
      </div>
      <div class="champ-row">
        <div class="champ-left">
          <div class="champ-emoji" id="rChampEmoji">💸</div>
          <div class="champ-info">
            <div class="champ-name" id="rChampName">—</div>
            <div class="champ-cat"  id="rChampCat"></div>
          </div>
        </div>
        <div class="champ-right">
          <div class="champ-amt"  id="rChampAmt">₩0</div>
          <div class="champ-date" id="rChampDate"></div>
        </div>
      </div>
      <div class="champ-pct-msg" id="rChampPct"></div>
      <div class="champ-feel-btns" id="rChampFeelBtns" style="display:none">
        <button class="champ-feel-btn" id="rFeelOk"     onclick="setChampFeel('ok')">😊 <span data-i18n="widget.feelOk">만족해요</span></button>
        <button class="champ-feel-btn" id="rFeelRegret" onclick="setChampFeel('regret')">💸 <span data-i18n="widget.feelRegret">아까워요</span></button>
      </div>
    </div>
  </div>`;
}
function widgetDowHTML() {
  return `<div class="widget-card" id="rDowCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="dow-body">
      <div class="dow-title">📅 <span data-i18n="widget.dow">요일별 소비 패턴</span></div>
      <div class="dow-bars"    id="rDowBars"></div>
      <div class="dow-insight" id="rDowInsight"></div>
    </div>
  </div>`;
}
function widgetSurvivalHTML() {
  return `<div class="widget-card" id="rSurvivalCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="surv-header" id="rSurvHeader"><span class="surv-header-label">💰 <span data-i18n="widget.survival">목표 예산</span></span></div>
    <div class="surv-body">
      <div class="surv-tabs">
        <button class="surv-tab" id="survTab-week"  onclick="setSurvMode('week')"  data-i18n="period.week">주</button>
        <button class="surv-tab" id="survTab-month" onclick="setSurvMode('month')" data-i18n="period.month">월</button>
        <button class="surv-tab" id="survTab-year"  onclick="setSurvMode('year')"  data-i18n="period.year">년</button>
      </div>
      <div class="surv-period-nav">
        <button class="surv-period-btn" onclick="survNav(-1)">‹</button>
        <span class="surv-period-label" id="rSurvNavLabel"></span>
        <button class="surv-period-btn" id="rSurvNavNext" onclick="survNav(1)">›</button>
      </div>
      <div class="surv-input-row">
        <span class="surv-input-label" data-i18n="widget.survival">목표 예산</span>
        <input class="surv-input" id="rSurvInput" type="text" inputmode="numeric" data-i18n-ph="surv.notSet" placeholder="미설정"
               oninput="onSurvBudgetInput(this)" onblur="saveSurvBudget()" onkeydown="if(event.key==='Enter')this.blur()">
        <span class="surv-input-unit" data-i18n="widget.survUnit">원</span>
      </div>
      <div class="surv-progress-wrap"><div class="surv-progress-bar" id="rSurvBar" style="width:0%"></div></div>
      <div class="surv-progress-labels">
        <span id="rSurvUsedPct" data-i18n="surv.noGoal">목표 미설정</span>
        <span id="rSurvPeriodLabel"></span>
      </div>
      <div class="surv-remaining" id="rSurvRemLabel" data-i18n="surv.totalVar">변동 지출 합계</div>
      <div class="surv-remaining-amt" id="rSurvAmt">₩0</div>
      <div class="surv-divider"></div>
      <div class="surv-msg" id="rSurvMsg" data-i18n="widget.calculating">계산 중...</div>
    </div>
  </div>`;
}
function widgetTop3HTML() {
  return `<div class="widget-card" id="rTop3Card">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="top3-header"><span class="top3-header-label">🥇 <span data-i18n="widget.top3">카테고리 TOP 3</span></span></div>
    <div class="top3-body" id="rTop3Body"></div>
  </div>`;
}
function widgetMbtiHTML() {
  return `<div class="widget-card" id="rMbtiCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="mbti-header"><span class="mbti-header-label">🧬 <span data-i18n="widget.mbti">나의 소비 MBTI</span></span></div>
    <div class="mbti-body">
      <div class="mbti-emoji" id="rMbtiEmoji">🌈</div>
      <div class="mbti-code"  id="rMbtiCode">????</div>
      <div class="mbti-title" id="rMbtiTitle">분석 중...</div>
      <div class="mbti-desc"  id="rMbtiDesc"></div>
      <div class="mbti-budget" id="rMbtiBudget" style="display:none"></div>
      <div class="mbti-top3"  id="rMbtiTop3"></div>
    </div>
  </div>`;
}
function editPanelHTML() {
  const hidden = WIDGET_DEFS.filter(w => !reportWidgets.includes(w.id));
  if (!hidden.length) return '';
  return `<div class="report-edit-panel">
    <div class="edit-panel-title">＋ ${tr('report.addWidget')}</div>
    ${hidden.map(w => `<div class="edit-row" onclick="addWidgetById('${w.id}')">
      <span class="edit-row-icon">${w.icon}</span>
      <span class="edit-row-label">${tr('wdef.'+w.id)}</span>
      <span class="edit-row-plus">＋</span>
    </div>`).join('')}
  </div>`;
}

// ── 결산 렌더 ──────────────────────────────────────────────
function renderReport() {
  const wrap = document.getElementById('reportWrap');
  const widgetMap = { insight: widgetInsightHTML, champion: widgetChampHTML, dayofweek: widgetDowHTML, survival: widgetSurvivalHTML, mbti: widgetMbtiHTML, top3cats: widgetTop3HTML };

  let html = '';
  if (reportWidgets.length === 0) {
    html = `<div class="report-empty">
      <div class="report-empty-ico">📭</div>
      <div class="report-empty-msg">${tr('report.empty')}</div>
    </div>`;
  } else {
    reportWidgets.forEach(id => { if (widgetMap[id]) html += widgetMap[id](); });
  }
  if (reportEditMode) html += editPanelHTML();

  wrap.innerHTML = html;
  renderReportEditBar();
  applyLang();

  // 데이터 채우기
  const prev     = prevMonth(curMonth);
  const [,tm]    = curMonth.split('-');
  const [,pm]    = prev.split('-');
  const thisExps = monthOf(curMonth).filter(t => t.type === 'expense');
  const prevExps = monthOf(prev).filter(t => t.type === 'expense');
  const thisExp  = thisExps.reduce((s,t) => s + t.amount, 0);
  const prevExp  = prevExps.reduce((s,t) => s + t.amount, 0);
  const name     = USER_NAME || tr('lbl.user');

  if (reportWidgets.includes('insight')) {
    const diff = thisExp - prevExp;
    const pct  = prevExp > 0 ? Math.round(Math.abs(diff) / prevExp * 100) : null;

    let emoji, insight, sub, tag, tagClass;

    if (prevExp === 0 && thisExp === 0) {
      emoji = '🌱'; tagClass = 'neutral'; tag = tr('insight.tagStart');
      insight = tr('insight.noData').replace('{name}', name);
      sub = tr('insight.noDataSub');

    } else if (prevExp === 0) {
      emoji = '🎉'; tagClass = 'neutral'; tag = tr('insight.tagFirst');
      insight = tr('insight.firstRecord').replace('{name}', name).replace('{amt}', fmt(thisExp));
      sub = tr('insight.firstRecordSub');

    } else if (diff === 0) {
      emoji = '😐'; tagClass = 'neutral'; tag = tr('insight.tagEqual');
      insight = tr('insight.equal').replace('{name}', name);
      sub = tr('insight.equalSub');

    } else if (diff < 0) {
      if (pct >= 30) {
        emoji = '💚'; tagClass = 'good'; tag = tr('insight.tagThrifty');
        insight = tr('insight.down30').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.down30Sub').replace('{amt}', fmt(-diff));
      } else if (pct >= 10) {
        emoji = '🎉'; tagClass = 'good'; tag = tr('insight.tagSaved');
        insight = tr('insight.down10').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.downSub').replace('{amt}', fmt(-diff));
      } else {
        emoji = '🙂'; tagClass = 'good'; tag = tr('insight.tagSaving');
        insight = tr('insight.downSmall').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.downSub').replace('{amt}', fmt(-diff));
      }

    } else {
      if (pct >= 50) {
        emoji = '🔴'; tagClass = 'danger'; tag = tr('insight.tagOverspend');
        insight = tr('insight.up50').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.upSub').replace('{amt}', fmt(diff));
      } else if (pct >= 30) {
        emoji = '📈'; tagClass = 'danger'; tag = tr('insight.tagUpBig');
        insight = tr('insight.up30').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.upSub').replace('{amt}', fmt(diff));
      } else if (pct >= 10) {
        emoji = '⚠️'; tagClass = 'warn'; tag = tr('insight.tagUpWarn');
        insight = tr('insight.up10').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.upSub').replace('{amt}', fmt(diff));
      } else {
        emoji = '📊'; tagClass = 'warn'; tag = tr('insight.tagUpSmall');
        insight = tr('insight.upSmall').replace('{name}', name).replace('{pct}', pct);
        sub = tr('insight.upSub').replace('{amt}', fmt(diff));
      }
    }

    const tagEl = document.getElementById('rInsightTag');
    tagEl.textContent = tag;
    tagEl.className   = `rc-tag ${tagClass}`;
    document.getElementById('rLabelPrev').textContent = tr('report.monthExpense').replace('{m}', parseInt(pm));
    document.getElementById('rLabelThis').textContent = tr('report.monthExpense').replace('{m}', parseInt(tm));
    document.getElementById('rValPrev').textContent   = fmt(prevExp);
    const valEl = document.getElementById('rValThis');
    valEl.textContent = fmt(thisExp);
    valEl.className = 'rc-cmp-val this-month' + (thisExp > prevExp ? ' up' : thisExp < prevExp ? ' down' : '');
    document.getElementById('rEmoji').textContent     = emoji;
    document.getElementById('rInsight').textContent  = insight;
    document.getElementById('rInsightSub').textContent = sub;
  }

  if (reportWidgets.includes('champion')) {
    // 월 네비 라벨 채우기 (renderReport 후 DOM 새로 생성되므로 여기서 직접 설정)
    const [cy,cm] = curMonth.split('-');
    const cLabel = document.getElementById('rChampMLabel');
    if (cLabel) cLabel.textContent = fmtYearMonth(cy, cm);
    const cNext = document.getElementById('rChampMNext');
    const nowYM2 = new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0');
    if (cNext) cNext.disabled = curMonth >= nowYM2;

    const champ = thisExps.length ? thisExps.reduce((a,b) => a.amount >= b.amount ? a : b) : null;
    const _ce = document.getElementById('rChampEmoji');
    if (champ) {
      const _cm = _icMeta(champ.category);
      _ce.innerHTML = `<span style="width:56px;height:56px;border-radius:50%;background:${_cm.bg};display:flex;align-items:center;justify-content:center"><i data-lucide="${_cm.lu}" style="width:26px;height:26px;color:${_cm.c};stroke-width:1.75"></i></span>`;
      lucide.createIcons();
    } else { _ce.innerHTML = '<span style="font-size:32px">💸</span>'; }
    document.getElementById('rChampName').textContent  = champ ? ((champ.description && champ.description !== champ.category) ? champ.description : dn(champ.category, CAT_NAME_MAP)) : tr('report.noExpense');
    document.getElementById('rChampCat').textContent   = champ ? dn(champ.category, CAT_NAME_MAP) + (champ.payment ? ' · ' + dn(champ.payment, PAY_NAME_MAP) : '') : '';
    document.getElementById('rChampAmt').innerHTML   = champ ? fmtH(champ.amount) : fmtH(0);
    document.getElementById('rChampDate').textContent  = champ ? champ.date.replace(/-/g,'.') + ' ' + tr('lbl.expense') : '';
    const pctEl = document.getElementById('rChampPct');
    const feelBtns = document.getElementById('rChampFeelBtns');
    if (champ && thisExp > 0) {
      const pct = Math.round(champ.amount / thisExp * 100);
      pctEl.innerHTML = tr('report.champPct').replace('{pct}', `<span>${pct}%</span>`);
      feelBtns.style.display = 'flex';
      const feelKey = 'ddgb_champ_feel_' + curMonth;
      const saved = localStorage.getItem(feelKey) || '';
      _applyChampFeel(saved);
    } else {
      pctEl.textContent = '';
      feelBtns.style.display = 'none';
      _applyChampFeel('');
    }
  }

  if (reportWidgets.includes('dayofweek')) {
    // 표시 순서: 월화수목금토일
    // JS getDay(): 0=일,1=월,2=화,3=수,4=목,5=금,6=토
    // SLOTS[i].js = 해당 슬롯의 JS 요일 번호 (명시적 테이블, 변환 배열 없음)
    const SLOTS = [
      {label:tr('day.mon'), js:1, msgKey:'dow.msg.mon'},
      {label:tr('day.tue'), js:2, msgKey:'dow.msg.tue'},
      {label:tr('day.wed'), js:3, msgKey:'dow.msg.wed'},
      {label:tr('day.thu'), js:4, msgKey:'dow.msg.thu'},
      {label:tr('day.fri'), js:5, msgKey:'dow.msg.fri'},
      {label:tr('day.sat'), js:6, msgKey:'dow.msg.sat'},
      {label:tr('day.sun'), js:0, msgKey:'dow.msg.sun'},
    ];

    // JS 요일 → 슬롯 인덱스 역방향 맵
    const jsToSlot = {};
    SLOTS.forEach((s,i) => { jsToSlot[s.js] = i; });

    const dowSum = [0,0,0,0,0,0,0];
    const dowCnt = [0,0,0,0,0,0,0];
    thisExps.forEach(t => {
      const [y,m,d] = t.date.split('-').map(Number);
      const jsDay   = new Date(y, m-1, d).getDay();  // 로컬 요일
      const slot    = jsToSlot[jsDay];
      dowSum[slot] += t.amount;
      dowCnt[slot]++;
    });
    const maxSum  = Math.max(...dowSum, 1);
    const peakIdx = dowSum.indexOf(Math.max(...dowSum));

    // BAR_MAX: 컨테이너80px - 라벨14px - gap4px = 62px → 왕관은 absolute이므로 높이에 영향 없음
    const BAR_MAX = 62;
    const barsEl = document.getElementById('rDowBars');
    barsEl.innerHTML = SLOTS.map((s,i) => {
      const h      = dowSum[i] > 0 ? Math.max(4, Math.round((dowSum[i] / maxSum) * BAR_MAX)) : 4;
      const isPeak = i === peakIdx && dowSum[i] > 0;
      const cls    = isPeak ? ' peak' : '';
      const crown  = isPeak ? `<div class="dow-crown">👑</div>` : '';
      const tipText = dowSum[i] > 0 ? `${s.label} ${fmt(dowSum[i])}` : `${s.label} ${tr('dow.noExpense')}`;
      return `<div class="dow-bar-wrap" data-tip="${tipText}"
        onmouseenter="showDowTip(event,this)" onmouseleave="hideDowTip()"
        ontouchstart="showDowTip(event,this)" ontouchend="hideDowTipDelay()">
        ${crown}
        <div class="dow-bar${cls}" style="height:${h}px"></div>
        <div class="dow-bar-label${cls}">${s.label}</div>
      </div>`;
    }).join('');

    let dowInsight;
    if (!thisExps.length) {
      dowInsight = tr('dow.noData');
    } else if (dowSum[peakIdx] === 0) {
      dowInsight = tr('dow.balanced');
    } else {
      dowInsight = tr('dow.peak').replace('{day}', SLOTS[peakIdx].label)
        + `<br><span class="hi-amt">${fmt(dowSum[peakIdx])}</span>`
        + ` <span class="lo-cnt">(${tr('dow.txCount').replace('{n}', dowCnt[peakIdx])})</span>`
        + `<br>${tr(SLOTS[peakIdx].msgKey)}`;
    }
    document.getElementById('rDowInsight').innerHTML = dowInsight;
  }

  // ── 목표 예산 ─────────────────────────────────────────
  if (reportWidgets.includes('survival')) fillSurvival();

  // ── 소비 MBTI ────────────────────────────────────────────────
  if (reportWidgets.includes('mbti')) {
    const MBTI_TYPES = [
      { keys:['식비','음식','밥','카페','커피','간식','분식'],     code:'EATJ', tkey:'mbti.title.eatj', emoji:'🍜', dkey:'mbti.desc.eatj' },
      { keys:['쇼핑','패션','의류','잡화','마트','백화점'],        code:'SHOP', tkey:'mbti.title.shop', emoji:'🛍️', dkey:'mbti.desc.shop' },
      { keys:['문화','여가','영화','취미','레저','공연','게임'],    code:'YOLO', tkey:'mbti.title.yolo', emoji:'🎭', dkey:'mbti.desc.yolo' },
      { keys:['교통','이동','주유','택시','지하철','버스','항공'], code:'MOVE', tkey:'mbti.title.move', emoji:'🚗', dkey:'mbti.desc.move' },
      { keys:['의료','건강','병원','약','헬스','피부'],            code:'HLTH', tkey:'mbti.title.hlth', emoji:'💊', dkey:'mbti.desc.hlth' },
      { keys:['통신','인터넷','구독','스트리밍','디지털'],         code:'DIGI', tkey:'mbti.title.digi', emoji:'📱', dkey:'mbti.desc.digi' },
      { keys:['주거','생활','관리비','인테리어','가구'],           code:'HOME', tkey:'mbti.title.home', emoji:'🏠', dkey:'mbti.desc.home' },
    ];
    const catMap = {};
    thisExps.forEach(t => { catMap[t.category] = (catMap[t.category] || 0) + t.amount; });
    const sorted = Object.entries(catMap).sort((a, b) => b[1] - a[1]);
    const topCat = sorted[0] ? sorted[0][0] : null;
    let mbtiType = null;
    if (topCat) {
      for (const t of MBTI_TYPES) {
        if (t.keys.some(k => topCat.includes(k))) { mbtiType = t; break; }
      }
    }
    if (!mbtiType) {
      if (!thisExps.length) {
        mbtiType = { code:'????', tkey:'mbti.title.none', emoji:'🔍', dkey:'mbti.desc.none' };
      } else {
        mbtiType = { code:'FREE', tkey:'mbti.title.free', emoji:'🌈', dkey:'mbti.desc.free' };
      }
    }

    // 예산 수식어
    const thisInc2 = monthOf(curMonth).filter(t => t.type === 'income').reduce((s,t) => s + t.amount, 0);
    let budgetLine = '';
    if (thisInc2 > 0) {
      const ur = thisExp / thisInc2;
      if (ur < 0.6)       budgetLine = tr('mbti.budget.great');
      else if (ur < 0.8)  budgetLine = tr('mbti.budget.ok');
      else if (ur <= 1.0) budgetLine = tr('mbti.budget.warn');
      else                budgetLine = tr('mbti.budget.over');
    }

    document.getElementById('rMbtiEmoji').textContent = mbtiType.emoji;
    document.getElementById('rMbtiCode').textContent  = mbtiType.code;
    document.getElementById('rMbtiTitle').textContent = tr(mbtiType.tkey);
    document.getElementById('rMbtiDesc').textContent  = tr(mbtiType.dkey);
    const budgetEl = document.getElementById('rMbtiBudget');
    if (budgetLine) { budgetEl.textContent = budgetLine; budgetEl.style.display = ''; }
    else            { budgetEl.style.display = 'none'; }

    // TOP 3 미니 배지
    const top3badges = sorted.slice(0, 3).map(([cat]) =>
      `<span class="mbti-badge"><i class="${_icMeta(cat).fa}" style="color:${_icMeta(cat).c}"></i> ${dn(cat, CAT_NAME_MAP)}</span>`
    ).join('');
    document.getElementById('rMbtiTop3').innerHTML = top3badges;
  }

  // ── 카테고리 TOP 3 ──────────────────────────────────────────
  if (reportWidgets.includes('top3cats')) {
    const catMap = {};
    thisExps.forEach(t => { catMap[t.category] = (catMap[t.category] || 0) + t.amount; });
    const sorted = Object.entries(catMap).sort((a,b) => b[1]-a[1]).slice(0,3);
    const MEDALS = ['🥇','🥈','🥉'];
    const body = document.getElementById('rTop3Body');
    if (!sorted.length) {
      body.innerHTML = `<div class="top3-empty">${tr('report.noExpense')}</div>`;
    } else {
      const maxAmt = sorted[0][1];
      body.innerHTML = sorted.map(([cat, amt], i) => {
        const pct = thisExp > 0 ? Math.round(amt / thisExp * 100) : 0;
        const barW = Math.round(amt / maxAmt * 100);
        // 해당 카테고리 거래 건수
        const cnt = thisExps.filter(t => t.category === cat).length;
        return `<div class="top3-row">
          <div class="top3-rank">${MEDALS[i]}</div>
          <div class="top3-info">
            <div class="top3-cat"><i class="${_icMeta(cat).fa}" style="color:${_icMeta(cat).c};margin-right:6px"></i>${dn(cat, CAT_NAME_MAP)}</div>
            <div class="top3-sub">${tr('lbl.cntFmt').replace('{n}', cnt)}</div>
            <div class="top3-bar-wrap"><div class="top3-bar" style="width:${barW}%"></div></div>
          </div>
          <div class="top3-right">
            <div class="top3-amt">${fmt(amt)}</div>
            <div class="top3-pct">${pct}%</div>
          </div>
        </div>`;
      }).join('');
    }
  }
}

function renderReportEditBar() {
  let bar = document.getElementById('reportEditBar');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'reportEditBar';
    bar.className = 'report-edit-bar';
    document.body.appendChild(bar);
  }
  bar.innerHTML = `<button class="report-edit-btn${reportEditMode ? ' on' : ''}" onclick="toggleReportEdit()">
    ${reportEditMode ? tr('report.editDone') : tr('report.editStart')}
  </button>`;
  bar.style.display = 'block';
}

function toggleReportEdit() {
  reportEditMode = !reportEditMode;
  renderReport();
}

// ── 나 탭 ────────────────────────────────────────────────────
function renderMeStreak() {
  const el = document.getElementById('meStreak');
  if (!el) return;
  // 지출/수입 기록 있는 날짜 고유 집합
  const dates = new Set(txs.map(t => t.date));
  let streak = 0;
  const today = localDateStr(new Date());
  let check = new Date();
  // 오늘 기록 없으면 어제부터 카운트
  if (!dates.has(today)) check.setDate(check.getDate() - 1);
  while (true) {
    const d = localDateStr(check);
    if (!dates.has(d)) break;
    streak++;
    check.setDate(check.getDate() - 1);
  }
  el.textContent = streak > 0 ? tr('me.streak').replace('{n}', streak) : tr('me.streakZero');
}

// ── 요일 툴팁 ────────────────────────────────────────────────
let _dowTipTimer = null;
function showDowTip(e, el) {
  const tip = document.getElementById('dowTip');
  tip.textContent = el.dataset.tip;
  tip.style.opacity = '1';
  const src = e.touches ? e.touches[0] : e;
  const x = Math.min(src.clientX + 8, window.innerWidth - tip.offsetWidth - 8);
  const y = src.clientY - 36;
  tip.style.left = x + 'px';
  tip.style.top  = y + 'px';
}
function hideDowTip() {
  document.getElementById('dowTip').style.opacity = '0';
}
function hideDowTipDelay() {
  clearTimeout(_dowTipTimer);
  _dowTipTimer = setTimeout(hideDowTip, 1200);
}

// ── 후회 분석 로직 ──────────────────────────────────────────
function _applyChampFeel(feel) {
  const card  = document.getElementById('rChampCard');
  const btnOk = document.getElementById('rFeelOk');
  const btnRg = document.getElementById('rFeelRegret');
  if (!card) return;
  card.classList.toggle('champ-regret', feel === 'regret');
  if (btnOk)  { btnOk.classList.toggle('active', feel === 'ok');     btnOk.classList.toggle('ok', feel === 'ok'); }
  if (btnRg)  { btnRg.classList.toggle('active', feel === 'regret'); btnRg.classList.toggle('regret', feel === 'regret'); }
}
let _toastTimer = null;
function showToast(msg) {
  const old = document.querySelector('.app-toast');
  if (old) old.remove();
  clearTimeout(_toastTimer);
  const el = document.createElement('div');
  el.className = 'app-toast';
  el.textContent = msg;
  document.body.appendChild(el);
  _toastTimer = setTimeout(() => {
    el.style.animation = 'toastOut .3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, 1800);
}
function setChampFeel(feel) {
  const feelKey = 'ddgb_champ_feel_' + curMonth;
  const cur = localStorage.getItem(feelKey) || '';
  const next = cur === feel ? '' : feel;
  if (next) localStorage.setItem(feelKey, next);
  else localStorage.removeItem(feelKey);
  _applyChampFeel(next);
  if (next === 'ok')     showToast('좋은 선택이었어요! 😊');
  else if (next === 'regret') showToast('다음엔 조금만 참아봐요! 💪');
}

// ── 목표 예산 로직 ────────────────────────────────────
function setSurvMode(mode) {
  survGoal.mode = mode;
  survGoal.weekOffset  = 0;
  survGoal.monthOffset = 0;
  survGoal.yearOffset  = 0;
  localStorage.setItem(SURV_SK, JSON.stringify(survGoal));
  fillSurvival();
}
function survNav(dir) {
  // 모든 모드에서 자체 offset만 변경 → fillSurvival만 호출 (화면 이동 없음)
  if      (survGoal.mode === 'week')  survGoal.weekOffset  = Math.max(0, (survGoal.weekOffset||0)  - dir);
  else if (survGoal.mode === 'year')  survGoal.yearOffset  = Math.max(0, (survGoal.yearOffset||0)  - dir);
  else                                survGoal.monthOffset = Math.max(0, (survGoal.monthOffset||0) - dir);
  localStorage.setItem(SURV_SK, JSON.stringify(survGoal));
  fillSurvival();
}
function onSurvBudgetInput(el) {
  const raw = el.value.replace(/[^0-9]/g, '');
  const num = parseInt(raw) || 0;
  el.value = raw ? Number(raw).toLocaleString() : '';
  _setSurvBudget(Math.max(0, num));
}
function saveSurvBudget() {
  localStorage.setItem(SURV_SK, JSON.stringify(survGoal));
  fillSurvival();
}

function fillSurvival() {
  const card = document.getElementById('rSurvivalCard');
  if (!card) return;

  const FIXED_KEYS = ['주거','관리비','전기','수도','보험','통신','인터넷','고정','구독','월세','렌탈','할부'];
  const today = new Date();
  const nowYM  = today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0');

  // 탭 활성화
  ['week','month','year'].forEach(m => {
    const btn = document.getElementById('survTab-'+m);
    if (btn) btn.className = 'surv-tab' + (survGoal.mode===m?' active':'');
  });
  // 입력값 동기화
  const inp = document.getElementById('rSurvInput');
  if (inp && document.activeElement !== inp) {
    const cur = _getSurvBudget();
    inp.value = cur > 0 ? cur.toLocaleString() : '';
  }

  let navLabel, rangeLabel, spent, daysLeft, isPast;

  if (survGoal.mode === 'week') {
    const wOff = survGoal.weekOffset || 0;
    const dSinceM = (today.getDay() + 6) % 7; // 월=0 … 일=6
    const mon = new Date(today);
    mon.setDate(today.getDate() - dSinceM - wOff * 7);
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    const monStr = localDateStr(mon), sunStr = localDateStr(sun);

    isPast    = wOff > 0;
    daysLeft  = isPast ? 0 : Math.max(Math.round((sun - today) / 86400000) + 1, 1);
    spent     = txs.filter(t => t.type==='expense' && t.date>=monStr && t.date<=sunStr
                             && !FIXED_KEYS.some(k=>t.category.includes(k)))
                   .reduce((s,t)=>s+t.amount, 0);
    navLabel  = wOff===0 ? '이번 주' : wOff===1 ? '지난 주' : `${wOff}주 전`;
    rangeLabel= `${mon.getMonth()+1}/${mon.getDate()}(월) ~ ${sun.getMonth()+1}/${sun.getDate()}(일)`;

  } else if (survGoal.mode === 'year') {
    const yOff = survGoal.yearOffset || 0;
    const y    = today.getFullYear() - yOff;
    const endOfYear = new Date(y, 11, 31);
    isPast    = yOff > 0;
    daysLeft  = isPast ? 0 : Math.max(Math.round((endOfYear - today) / 86400000) + 1, 1);
    spent     = txs.filter(t => t.type==='expense' && t.date.startsWith(String(y))
                             && !FIXED_KEYS.some(k=>t.category.includes(k)))
                   .reduce((s,t)=>s+t.amount, 0);
    navLabel  = yOff===0 ? '올해' : yOff===1 ? '작년' : `${y}년`;
    rangeLabel= `${y}년`;

  } else {
    const mOff = survGoal.monthOffset || 0;
    const md   = new Date(today.getFullYear(), today.getMonth() - mOff, 1);
    const cy2  = md.getFullYear(), cm2 = md.getMonth() + 1;
    const ym   = cy2+'-'+String(cm2).padStart(2,'0');
    const lastDay = new Date(cy2, cm2, 0).getDate();
    isPast   = mOff > 0;
    daysLeft = mOff === 0 ? Math.max(lastDay - today.getDate() + 1, 1) : 0;
    spent    = txs.filter(t => t.type==='expense' && t.date.startsWith(ym)
                            && !FIXED_KEYS.some(k=>t.category.includes(k)))
                  .reduce((s,t)=>s+t.amount, 0);
    navLabel  = mOff===0 ? '이번 달' : mOff===1 ? '지난 달' : `${cy2}년 ${cm2}월`;
    rangeLabel= mOff===0 ? '이번 달' : `${cy2}년 ${cm2}월`;
  }

  // period nav 레이블 + 다음 버튼 비활성화
  const navLabelEl = document.getElementById('rSurvNavLabel');
  if (navLabelEl) navLabelEl.textContent = rangeLabel;
  const navNext = document.getElementById('rSurvNavNext');
  if (navNext) {
    const atPresent = (survGoal.mode==='week'  && (survGoal.weekOffset||0)===0)  ||
                      (survGoal.mode==='year'  && (survGoal.yearOffset||0)===0)  ||
                      (survGoal.mode==='month' && (survGoal.monthOffset||0)===0);
    navNext.disabled = atPresent;
  }

  // 예산 결정 (미설정 시 해당 월 수입으로 대체)
  let budget = _getSurvBudget();
  const isUnset = budget === 0;
  // 주 모드 + 과거 주 + 미설정 → 미설정 표시
  const isWeekPastUnset = survGoal.mode === 'week' && isPast && isUnset;
  if (!budget && survGoal.mode === 'month') {
    const _mOff = survGoal.monthOffset || 0;
    const _md   = new Date(today.getFullYear(), today.getMonth() - _mOff, 1);
    const _ym   = _md.getFullYear()+'-'+String(_md.getMonth()+1).padStart(2,'0');
    budget = txs.filter(t=>t.type==='income' && t.date.startsWith(_ym))
                .reduce((s,t)=>s+t.amount, 0);
  }

  const remaining = budget - spent;
  const usageRate = budget > 0 ? spent / budget : 0;
  const isDanger  = budget > 0 && usageRate > 0.8;
  const isWarn    = budget > 0 && usageRate > 0.6 && usageRate <= 0.8;

  // 위험 상태
  const survHdr = card.querySelector('.surv-header');
  if (isDanger) { card.classList.add('surv-danger');    survHdr.classList.add('danger'); }
  else          { card.classList.remove('surv-danger'); survHdr.classList.remove('danger'); }

  // 프로그레스 바
  const barEl = document.getElementById('rSurvBar');
  if (barEl) {
    barEl.style.width = Math.min(usageRate * 100, 100) + '%';
    barEl.className = 'surv-progress-bar' + (isDanger ? ' danger' : isWarn ? ' warn' : '');
  }
  const pctEl = document.getElementById('rSurvUsedPct');
  if (pctEl) pctEl.textContent = isWeekPastUnset ? '미설정' : budget > 0 ? `지출 ${Math.round(usageRate*100)}%` : '목표 미설정';
  const prdEl = document.getElementById('rSurvPeriodLabel');
  if (prdEl) prdEl.textContent = navLabel;

  // 금액 표시
  const amtEl = document.getElementById('rSurvAmt');
  if (amtEl) {
    amtEl.textContent = isWeekPastUnset ? tr('surv.notSet') : budget > 0 ? fmt(Math.abs(remaining)) : fmt(spent);
    amtEl.className = 'surv-remaining-amt' + ((remaining >= 0 || !budget) ? ' positive' : ' negative');
  }
  const remLbl = document.getElementById('rSurvRemLabel');
  if (remLbl) {
    if (isWeekPastUnset)              remLbl.textContent = tr('surv.weekNoGoal');
    else if (!budget)                 remLbl.textContent = tr('surv.totalVar');
    else if (isDanger && remaining>0) remLbl.textContent = tr('surv.danger');
    else if (remaining >= 0)          remLbl.textContent = tr('surv.remaining');
    else                              remLbl.textContent = tr('surv.over');
  }

  // 입력란: 미설정 과거 주는 비워두기
  const inp2 = document.getElementById('rSurvInput');
  if (inp2 && document.activeElement !== inp2 && isWeekPastUnset) inp2.value = '';

  // 메시지
  const msgEl = document.getElementById('rSurvMsg');
  if (msgEl) {
    let msg;
    if (isWeekPastUnset) {
      msg = tr('surv.msgNoGoal').replace('{period}', navLabel);
    } else if (!budget) {
      msg = tr('surv.msgEnterGoal');
    } else if (remaining <= 0) {
      msg = tr('surv.msgOver').replace('{period}', navLabel).replace('{amt}', fmt(-remaining));
    } else if (isPast || daysLeft === 0) {
      msg = tr('surv.msgSaved').replace('{period}', navLabel).replace('{amt}', fmt(remaining));
    } else if (isDanger) {
      msg = tr('surv.msgDanger').replace('{days}', daysLeft).replace('{daily}', fmt(Math.floor(remaining/daysLeft)));
    } else {
      msg = tr('surv.msgOk').replace('{days}', daysLeft).replace('{daily}', fmt(Math.floor(remaining/daysLeft)));
    }
    msgEl.innerHTML = msg;
  }
}

let activeWidgetId = null;
function openWidgetAction(e, cardId) {
  e.stopPropagation();
  const idMap = { rInsightCard:'insight', rChampCard:'champion', rDowCard:'dayofweek', rSurvivalCard:'survival', rMbtiCard:'mbti', rTop3Card:'top3cats' };
  activeWidgetId = idMap[cardId];
  const idx = reportWidgets.indexOf(activeWidgetId);
  const pop = document.getElementById('widgetPopover');
  pop.innerHTML =
    `<div class="wpop-item${idx<=0?' disabled':''}" onclick="widgetMove(-1)">${tr('wpop.moveUp')}</div>` +
    `<div class="wpop-item${idx>=reportWidgets.length-1?' disabled':''}" onclick="widgetMove(1)">${tr('wpop.moveDown')}</div>` +
    `<div class="wpop-item danger" onclick="widgetDeleteActive()">${tr('wpop.delete')}</div>`;
  const btn  = e.currentTarget;
  const rect = btn.getBoundingClientRect();
  pop.style.top     = (rect.bottom + 4) + 'px';
  pop.style.left    = 'auto';
  pop.style.right   = (window.innerWidth - rect.right) + 'px';
  pop.style.display = 'block';
  setTimeout(() => document.addEventListener('click', closeWidgetPopover, { once: true }), 0);
}
function closeWidgetPopover() {
  document.getElementById('widgetPopover').style.display = 'none';
  activeWidgetId = null;
}
function widgetMove(dir) {
  const idx = reportWidgets.indexOf(activeWidgetId);
  const newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= reportWidgets.length) return;
  [reportWidgets[idx], reportWidgets[newIdx]] = [reportWidgets[newIdx], reportWidgets[idx]];
  localStorage.setItem(WIDGETS_SK, JSON.stringify(reportWidgets));
  closeWidgetPopover();
  renderReport();
}
function widgetDeleteActive() {
  const id = activeWidgetId;
  closeWidgetPopover();
  const card = document.getElementById(CARD_IDS[id]);
  if (card) {
    card.style.animation = 'widgetOut .22s ease forwards';
    setTimeout(() => {
      reportWidgets = reportWidgets.filter(w => w !== id);
      localStorage.setItem(WIDGETS_SK, JSON.stringify(reportWidgets));
      renderReport();
    }, 220);
  }
}
function addWidgetById(id) {
  const order = WIDGET_DEFS.map(w => w.id);
  reportWidgets = order.filter(w => reportWidgets.includes(w) || w === id);
  localStorage.setItem(WIDGETS_SK, JSON.stringify(reportWidgets));
  renderReport();
}

// ── 모달 ─────────────────────────────────────────────────────
function openModal() {
  editingTxId = null;
  document.getElementById('modalTitle').textContent = '내역 추가';
  document.getElementById('fDate').value=new Date().toISOString().slice(0,10);
  document.getElementById('fAmt').value='';
  document.getElementById('fDesc').value='';
  buildPaySelect('현금');
  photosData=[];
  renderPhotoGrid();
  document.getElementById('newCatBox').classList.remove('show');
  document.getElementById('newPayBox').classList.remove('show');
  // DB 카테고리가 아직 없으면 먼저 로드 후 열기
  if (IS_LOGGED_IN && dbCats.expense.length === 0 && dbCats.income.length === 0) {
    loadDbCats(() => { setType('expense'); document.getElementById('modal').classList.add('show'); setTimeout(()=>document.getElementById('fAmt').focus(),150); });
  } else {
    setType('expense');
    document.getElementById('modal').classList.add('show');
    setTimeout(()=>document.getElementById('fAmt').focus(),150);
  }
}

function fillModal(t, titleText) {
  document.getElementById('modalTitle').textContent = titleText;
  setType(t.type);
  document.getElementById('fAmt').value = t.amount.toLocaleString('ko-KR');
  document.getElementById('fDesc').value = (t.description && t.description !== t.category) ? t.description : '';
  document.getElementById('fDate').value = t.date;
  buildPaySelect(t.payment || '현금');
  // 카테고리 선택 (커스텀 포함)
  setTimeout(()=>{ selectCatOption(t.category); }, 0);
  photosData = Array.isArray(t.photos) ? [...t.photos] : (t.photo ? [t.photo] : []);
  renderPhotoGrid();
  document.getElementById('newCatBox').classList.remove('show');
  document.getElementById('modal').classList.add('show');
}
function closeModal() { document.getElementById('modal').classList.remove('show'); }
function onOverlayClick(e) { if(e.target===document.getElementById('modal')) closeModal(); }

function setType(type) {
  curType=type;
  document.getElementById('typeE').className='type-t'+(type==='expense'?' on e':'');
  document.getElementById('typeI').className='type-t'+(type==='income'?' on i':'');
  buildCatSelect(type);
}

function buildCatSelect(type, keepVal) {
  const hidden = document.getElementById('fCat');
  if (!hidden) return;
  const cur = keepVal || hidden.value;

  let baseCatNames, dbCustomList, localCustomList;
  if (IS_LOGGED_IN) {
    const baseNames = BASE_CATS[type] || [];
    baseCatNames  = (dbCats[type] || []).filter(c => baseNames.includes(c.name)).map(c => c.name);
    dbCustomList  = (dbCats[type] || []).filter(c => !baseNames.includes(c.name));
    localCustomList = [];
  } else {
    baseCatNames  = BASE_CATS[type] || [];
    dbCustomList  = [];
    localCustomList = (customCats[type] || []);
  }

  const dropdown = document.getElementById('catCsDropdown');
  if (!dropdown) return;

  let html = baseCatNames.map(name => {
    const m = _icMeta(name);
    return `<div class="cat-cs-option${name===cur?' selected':''}" onclick="selectCatOption('${esc(name)}')">
      <span class="cat-cs-option-icon" style="background:${m.bg}">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span class="cat-cs-option-name">${dn(name, CAT_NAME_MAP)}</span>
    </div>`;
  }).join('');

  html += dbCustomList.map(c => {
    const m = _icMeta(c.name);
    return `<div class="cat-cs-option${c.name===cur?' selected':''}" onclick="selectCatOption('${esc(c.name)}')">
      <span class="cat-cs-option-icon" style="background:${m.bg}">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span class="cat-cs-option-name">${esc(c.name)}</span>
      <button class="cat-cs-del" onclick="event.stopPropagation();deleteCustomCat(null,'${esc(c.name)}',${c.id||0})" type="button">−</button>
    </div>`;
  }).join('');

  html += localCustomList.map((c, i) => {
    const m = _icMeta(c.name);
    return `<div class="cat-cs-option${c.name===cur?' selected':''}" onclick="selectCatOption('${esc(c.name)}')">
      <span class="cat-cs-option-icon" style="background:${m.bg}">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span class="cat-cs-option-name">${esc(c.name)}</span>
      <button class="cat-cs-del" onclick="event.stopPropagation();deleteCustomCat(${i},null,null)" type="button">−</button>
    </div>`;
  }).join('');

  dropdown.innerHTML = html;
  refreshIcons();

  // 선택값 설정
  const allNames = [...baseCatNames, ...dbCustomList.map(c=>c.name), ...localCustomList.map(c=>c.name)];
  const first = allNames[0] || '';
  const val = allNames.includes(cur) ? cur : first;
  hidden.value = val;
  const lbl = document.getElementById('catCsLabel');
  if (lbl) lbl.textContent = val ? dn(val, CAT_NAME_MAP) : tr('form.catSelect');
}

function toggleCatDropdown() {
  const dd = document.getElementById('catCsDropdown');
  if (!dd) return;
  const isOpen = dd.classList.contains('open');
  dd.classList.toggle('open', !isOpen);
  if (!isOpen) {
    setTimeout(() => document.addEventListener('click', closeCatDropdownOutside, { once: true }), 0);
  }
}
function closeCatDropdownOutside(e) {
  const wrap = document.getElementById('catCustomSelect');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('catCsDropdown')?.classList.remove('open');
  }
}
function selectCatOption(name) {
  document.getElementById('fCat').value = name;
  const lbl = document.getElementById('catCsLabel');
  if (lbl) lbl.textContent = dn(name, CAT_NAME_MAP);
  document.getElementById('catCsDropdown')?.classList.remove('open');
}

// ── 커스텀 카테고리 (로그인 시 catEdit 모달로, 비로그인 시 inline) ──
// ── 결제수단 추가 ─────────────────────────────────────────────
const DEFAULT_PAYS = ['현금','신용카드','체크카드','계좌이체','카카오페이','네이버페이','토스','기타'];
const CAT_NAME_MAP = {
  '식비':'cat.dining','교통':'cat.transport','쇼핑':'cat.shopping','의료':'cat.medical',
  '문화':'cat.culture','통신':'cat.telecom','주거':'cat.housing','기타':'cat.other',
  '급여':'cat.salary','용돈':'cat.allowance','기타수입':'cat.otherIncome',
};
const PAY_NAME_MAP = {
  '현금':'pay.cash','신용카드':'pay.credit','체크카드':'pay.debit','계좌이체':'pay.transfer',
  '카카오페이':'pay.kakao','네이버페이':'pay.naver','토스':'pay.toss','기타':'pay.other','자동':'pay.auto',
};
function dn(name, map) { const k = map[name]; return k ? tr(k) : name; }
const CUSTOM_PAYS_SK = 'custom_pays';
let customPays = JSON.parse(localStorage.getItem(CUSTOM_PAYS_SK) || '[]');

function buildPaySelect(selected) {
  const hidden = document.getElementById('fPay');
  const val = selected || hidden?.value || '현금';
  const allPays = [...DEFAULT_PAYS, ...customPays];
  const dropdown = document.getElementById('payCsDropdown');
  if (!dropdown) return;
  dropdown.innerHTML = DEFAULT_PAYS.map(p => {
    const m = _icMeta(p);
    return `<div class="cat-cs-option${p===val?' selected':''}" onclick="selectPayOption('${esc(p)}')">
      <span class="cat-cs-option-icon" style="background:${m.bg}">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span class="cat-cs-option-name">${dn(p, PAY_NAME_MAP)}</span>
    </div>`;
  }).join('') + customPays.map((p, i) => {
    const m = _icMeta(p);
    return `<div class="cat-cs-option${p===val?' selected':''}" onclick="selectPayOption('${esc(p)}')">
      <span class="cat-cs-option-icon" style="background:${m.bg}">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span class="cat-cs-option-name">${esc(p)}</span>
      <button class="cat-cs-del" onclick="event.stopPropagation();deleteCustomPay(${i})" type="button">−</button>
    </div>`;
  }).join('');
  refreshIcons();
  const finalVal = allPays.includes(val) ? val : allPays[0] || '현금';
  if (hidden) hidden.value = finalVal;
  const lbl = document.getElementById('payCsLabel');
  if (lbl) lbl.textContent = dn(finalVal, PAY_NAME_MAP);
}
function togglePayDropdown() {
  const dd = document.getElementById('payCsDropdown');
  if (!dd) return;
  const isOpen = dd.classList.contains('open');
  dd.classList.toggle('open', !isOpen);
  if (!isOpen) setTimeout(() => document.addEventListener('click', closePayDropdownOutside, { once: true }), 0);
}
function closePayDropdownOutside(e) {
  const wrap = document.getElementById('payCustomSelect');
  if (wrap && !wrap.contains(e.target)) document.getElementById('payCsDropdown')?.classList.remove('open');
}
function selectPayOption(name) {
  document.getElementById('fPay').value = name;
  const lbl = document.getElementById('payCsLabel');
  if (lbl) lbl.textContent = dn(name, PAY_NAME_MAP);
  document.getElementById('payCsDropdown')?.classList.remove('open');
}
function deleteCustomPay(idx) {
  const name = customPays[idx];
  if (!name) return;
  if (!confirm('"' + name + '" 결제수단을 삭제할까요?')) return;
  customPays.splice(idx, 1);
  localStorage.setItem(CUSTOM_PAYS_SK, JSON.stringify(customPays));
  buildPaySelect('현금');
  showToast('결제수단이 삭제됐어요');
}
function toggleNewPay() {
  document.getElementById('newPayBox').classList.toggle('show');
  if (document.getElementById('newPayBox').classList.contains('show'))
    setTimeout(() => document.getElementById('npName').focus(), 50);
}
function saveNewPay() {
  const name = document.getElementById('npName').value.trim();
  if (!name) { showToast('결제수단 이름을 입력해주세요'); return; }
  if ([...DEFAULT_PAYS, ...customPays].includes(name)) { showToast('이미 있는 결제수단이에요'); return; }
  customPays.push(name);
  localStorage.setItem(CUSTOM_PAYS_SK, JSON.stringify(customPays));
  CAT_ICON_MAP[name] = { lu: selectedPayIcon.lu, bg: selectedPayIcon.bg, c: '#fff' };
  buildPaySelect(name);
  document.getElementById('npName').value = '';
  document.getElementById('newPayBox').classList.remove('show');
  resetPayIconPicker();
  showToast('결제수단이 추가됐어요');
}

// ── 아이콘 피커 ───────────────────────────────────────────────
const ICON_OPTIONS = [
  { lu:'utensils',        bg:'#E74C3C', label:'음식'    },
  { lu:'shopping-bag',    bg:'#8E44AD', label:'쇼핑'    },
  { lu:'bus',             bg:'#F39C12', label:'교통'    },
  { lu:'car',             bg:'#E67E22', label:'자동차'  },
  { lu:'home',            bg:'#27AE60', label:'주거'    },
  { lu:'heart-pulse',     bg:'#E91E8C', label:'건강'    },
  { lu:'smartphone',      bg:'#00BCD4', label:'통신'    },
  { lu:'film',            bg:'#2196F3', label:'문화'    },
  { lu:'briefcase',       bg:'#C8860A', label:'급여'    },
  { lu:'gift',            bg:'#E91E63', label:'선물'    },
  { lu:'plane',           bg:'#3498DB', label:'여행'    },
  { lu:'coffee',          bg:'#795548', label:'카페'    },
  { lu:'wine',            bg:'#C62828', label:'술'      },
  { lu:'graduation-cap',  bg:'#1565C0', label:'교육'    },
  { lu:'dumbbell',        bg:'#FF5722', label:'스포츠'  },
  { lu:'dog',             bg:'#6D4C41', label:'반려동물'},
  { lu:'wrench',          bg:'#607D8B', label:'수리'    },
  { lu:'monitor',         bg:'#455A64', label:'전자제품'},
  { lu:'shirt',           bg:'#9C27B0', label:'의류'    },
  { lu:'baby',            bg:'#E91E63', label:'아동'    },
  { lu:'carrot',          bg:'#FF9800', label:'채소'    },
  { lu:'scissors',        bg:'#EC407A', label:'뷰티'    },
  { lu:'users',           bg:'#26A69A', label:'사교'    },
  { lu:'piggy-bank',      bg:'#4CAF50', label:'저축'    },
  { lu:'heart-handshake', bg:'#F44336', label:'기부'    },
  { lu:'cigarette',       bg:'#78909C', label:'담배'    },
  { lu:'coins',           bg:'#2563EB', label:'기타수입'},
  { lu:'package',         bg:'#1ABC9C', label:'기타'    },
];
const PAY_ICON_OPTIONS = [
  { lu:'credit-card',        bg:'#7C3AED', label:'신용카드' },
  { lu:'banknote',           bg:'#4CAF50', label:'현금'     },
  { lu:'landmark',           bg:'#1E293B', label:'계좌이체' },
  { lu:'wallet',             bg:'#C8860A', label:'지갑'     },
  { lu:'smartphone',         bg:'#FDD835', label:'간편결제' },
  { lu:'qr-code',            bg:'#00897B', label:'QR결제'  },
  { lu:'circle-dollar-sign', bg:'#1565C0', label:'페이'     },
  { lu:'badge-dollar-sign',  bg:'#E53935', label:'포인트'   },
  { lu:'gift',               bg:'#E91E63', label:'상품권'   },
  { lu:'building-2',         bg:'#37474F', label:'은행'     },
  { lu:'piggy-bank',         bg:'#43A047', label:'저금통'   },
  { lu:'coins',              bg:'#F57F17', label:'동전'     },
  { lu:'repeat',             bg:'#0288D1', label:'자동이체' },
  { lu:'package',            bg:'#607D8B', label:'기타'     },
];
const INCOME_ICON_OPTIONS = [
  { lu:'briefcase',          bg:'#C8860A', label:'급여'     },
  { lu:'coins',              bg:'#2563EB', label:'용돈'     },
  { lu:'trending-up',        bg:'#2E7D32', label:'투자'     },
  { lu:'building-2',         bg:'#37474F', label:'이자'     },
  { lu:'gift',               bg:'#E91E63', label:'선물'     },
  { lu:'piggy-bank',         bg:'#43A047', label:'저금'     },
  { lu:'hand-coins',         bg:'#F57F17', label:'부수입'   },
  { lu:'landmark',           bg:'#1E293B', label:'환급'     },
  { lu:'badge-dollar-sign',  bg:'#E53935', label:'보너스'   },
  { lu:'repeat',             bg:'#0288D1', label:'정기수입' },
  { lu:'wallet',             bg:'#7C3AED', label:'지갑'     },
  { lu:'banknote',           bg:'#4CAF50', label:'현금'     },
  { lu:'star',               bg:'#FF9800', label:'포상'     },
  { lu:'package',            bg:'#607D8B', label:'기타'     },
];
let selectedCatIcon = { lu: 'package', bg: '#607D8B' };
let selectedPayIcon = { lu: 'credit-card', bg: '#7C3AED' };
let _iconPickerMode = 'cat'; // 'cat' | 'pay'
const ICON_LABEL_TR = {
  '음식':'ico.food','쇼핑':'ico.shopping','교통':'ico.transport','자동차':'ico.car',
  '주거':'ico.housing','건강':'ico.health','통신':'ico.telecom','문화':'ico.culture',
  '급여':'ico.salary','선물':'ico.gift','여행':'ico.travel','카페':'ico.cafe',
  '술':'ico.alcohol','교육':'ico.education','스포츠':'ico.sports','반려동물':'ico.pet',
  '수리':'ico.repair','전자제품':'ico.electronics','의류':'ico.clothing','아동':'ico.child',
  '채소':'ico.grocery','뷰티':'ico.beauty','사교':'ico.social','저축':'ico.savings',
  '기부':'ico.donation','담배':'ico.smoking','기타수입':'ico.otherIncome','기타':'ico.other',
  '신용카드':'ico.credit','현금':'ico.cash','계좌이체':'ico.transfer','지갑':'ico.wallet',
  '간편결제':'ico.easypay','QR결제':'ico.qr','페이':'ico.pay','포인트':'ico.points',
  '상품권':'ico.voucher','은행':'ico.bank','저금통':'ico.piggyBank','동전':'ico.coins',
  '자동이체':'ico.autoTransfer',
  '용돈':'ico.allowance','투자':'ico.invest','이자':'ico.interest','저금':'ico.save',
  '부수입':'ico.sideIncome','환급':'ico.refund','보너스':'ico.bonus',
  '정기수입':'ico.regularIncome','포상':'ico.reward',
};
function trIconLabel(label) { const k = ICON_LABEL_TR[label]; return k ? tr(k) : label; }

function openIconPicker(mode) {
  _iconPickerMode = mode || 'cat';
  const current = (_iconPickerMode === 'pay' || _iconPickerMode === 'pay-edit') ? selectedPayIcon : selectedCatIcon;
  let opts;
  if (_iconPickerMode === 'pay' || _iconPickerMode === 'pay-edit') opts = PAY_ICON_OPTIONS;
  else if (curType === 'income') opts = INCOME_ICON_OPTIONS;
  else opts = ICON_OPTIONS;
  const grid = document.getElementById('iconPickerGrid');
  grid.innerHTML = opts.map((o, i) => `
    <div class="icon-opt${o.lu === current.lu ? ' selected' : ''}" onclick="selectIcon(${i})">
      <div class="icon-opt-circle" style="background:${o.bg}">
        <i data-lucide="${o.lu}" style="width:22px;height:22px;color:#fff;stroke-width:1.75"></i>
      </div>
      <div class="icon-opt-label">${trIconLabel(o.label)}</div>
    </div>`).join('');
  document.getElementById('iconPickerOverlay').classList.add('show');
  lucide.createIcons();
}
function closeIconPicker() {
  document.getElementById('iconPickerOverlay').classList.remove('show');
}
function onIconPickerBg(e) {
  if (e.target === document.getElementById('iconPickerOverlay')) closeIconPicker();
}
function selectIcon(idx) {
  const opts = (_iconPickerMode === 'pay' || _iconPickerMode === 'pay-edit') ? PAY_ICON_OPTIONS : (curType === 'income' ? INCOME_ICON_OPTIONS : ICON_OPTIONS);
  const icon = opts[idx];
  let previewId;
  if (_iconPickerMode === 'pay') previewId = 'npIconPreview';
  else if (_iconPickerMode === 'pay-edit') previewId = 'peIconPreview';
  else previewId = 'ncIconPreview';
  if (_iconPickerMode === 'pay' || _iconPickerMode === 'pay-edit') selectedPayIcon = icon;
  else selectedCatIcon = icon;
  const preview = document.getElementById(previewId);
  if (preview) {
    preview.style.background = icon.bg;
    preview.innerHTML = `<i data-lucide="${icon.lu}" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>`;
  }
  lucide.createIcons();
  closeIconPicker();
}
function resetIconPicker() {
  selectedCatIcon = { lu: 'package', bg: '#607D8B' };
  const preview = document.getElementById('ncIconPreview');
  preview.style.background = '#607D8B';
  preview.innerHTML = `<i data-lucide="package" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>`;
  lucide.createIcons();
}
function resetPayIconPicker() {
  selectedPayIcon = { lu: 'credit-card', bg: '#607D8B' };
  const preview = document.getElementById('npIconPreview');
  if (!preview) return;
  preview.style.background = '#607D8B';
  preview.innerHTML = `<i data-lucide="credit-card" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>`;
  lucide.createIcons();
}

function renderCustomCatList() {
  const el = document.getElementById('customCatList');
  if (!el) return;
  let cats;
  if (IS_LOGGED_IN) {
    const baseCatNames = BASE_CATS[curType] || [];
    cats = (dbCats[curType] || []).filter(c => !baseCatNames.includes(c.name));
  } else {
    cats = (customCats[curType] || []);
  }
  if (!cats.length) { el.innerHTML = ''; return; }
  el.innerHTML = '<div style="border-top:1px solid #f0f0f0;margin-top:6px">' + cats.map((c, i) => {
    const m = _icMeta(c.name);
    const delArg = IS_LOGGED_IN ? `deleteCustomCat(null,'${esc(c.name)}',${c.id||0})` : `deleteCustomCat(${i},null,null)`;
    return `<div style="display:flex;align-items:center;gap:8px;padding:7px 0;${i<cats.length-1?'border-bottom:1px solid #f0f0f0':''}">
      <span style="width:30px;height:30px;border-radius:50%;background:${m.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i data-lucide="${m.lu}" style="width:14px;height:14px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span style="flex:1;font-size:13px;font-weight:600;color:#374151">${esc(c.name)}</span>
      <button onclick="${delArg}" type="button"
        style="background:none;border:1px solid #fecaca;border-radius:7px;cursor:pointer;color:#ef4444;padding:4px 8px;font-size:16px;line-height:1">−</button>
    </div>`;
  }).join('') + '</div>';
  lucide.createIcons();
}
function deleteCustomCat(idx, name, id) {
  if (IS_LOGGED_IN) {
    if (!confirm('"' + name + '" 카테고리를 삭제할까요?')) return;
    dbCats[curType] = (dbCats[curType]||[]).filter(c => String(c.id) !== String(id));
    buildCatSelect(curType);
    renderLedger();
    showToast('카테고리가 삭제됐어요');
    const fd = new FormData(); fd.append('id', id);
    fetch('../api/?action=categories_delete', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json()).then(res => {
        if (res.status === 'ok' && Array.isArray(res.categories)) {
          dbCats.expense = res.categories.filter(c => c.type === 'expense');
          dbCats.income  = res.categories.filter(c => c.type === 'income');
          buildCatSelect(curType);
        }
      }).catch(() => {});
  } else {
    const catName = (customCats[curType] || [])[idx]?.name;
    if (!catName) return;
    if (!confirm('"' + catName + '" 카테고리를 삭제할까요?')) return;
    customCats[curType].splice(idx, 1);
    persistCats();
    buildCatSelect(curType);
    renderLedger();
    showToast('카테고리가 삭제됐어요');
  }
}
function toggleNewCat() {
  const box = document.getElementById('newCatBox');
  box.classList.toggle('show');
}
function saveNewCat() {
  const name = document.getElementById('ncName').value.trim();
  if (!name) { showToast(tr('toast.catEmptyName')); return; }
  const icon = selectedCatIcon.lu + '|' + selectedCatIcon.bg;

  if (IS_LOGGED_IN) {
    // 로그인: 이미 있는지 확인
    const existing = [...(dbCats[curType]||[])];
    if (existing.find(c => c.name === name)) { showToast(tr('toast.catDuplicate')); return; }
    // 로컬에 임시 추가
    CAT_ICON_MAP[name] = { lu: selectedCatIcon.lu, bg: selectedCatIcon.bg, c: '#fff' };
    const tempId = 'tmp_' + Date.now();
    if (!Array.isArray(dbCats[curType])) dbCats[curType] = [];
    dbCats[curType] = [...dbCats[curType], { id: tempId, name, icon, type: curType }];
    buildCatSelect(curType);
    document.getElementById('fCat').value = name;
    document.getElementById('ncName').value = '';
    resetIconPicker();
    document.getElementById('newCatBox').classList.remove('show');
    showToast(tr('toast.catAdded'));
    // 서버 저장
    const fd = new FormData();
    fd.append('name', name); fd.append('icon', icon); fd.append('type', curType);
    fetch('../api/?action=categories_add', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'ok') {
          if (Array.isArray(res.categories) && res.categories.length > 0) {
            dbCats.expense = res.categories.filter(c => c.type === 'expense');
            dbCats.income  = res.categories.filter(c => c.type === 'income');
          } else {
            const item = (dbCats[curType]||[]).find(c => c.id === tempId);
            if (item && res.id) item.id = res.id;
          }
          buildCatSelect(curType);
        }
      }).catch(() => {});
  } else {
    if (!customCats[curType]) customCats[curType] = [];
    if (customCats[curType].find(c => c.name === name)) { showToast(tr('toast.catDuplicate')); return; }
    customCats[curType].push({ emoji: selectedCatIcon.lu, lu: selectedCatIcon.lu, bg: selectedCatIcon.bg, name });
    CAT_ICON_MAP[name] = { lu: selectedCatIcon.lu, bg: selectedCatIcon.bg, c: '#fff' };
    persistCats();
    buildCatSelect(curType);
    document.getElementById('fCat').value = name;
    document.getElementById('ncName').value = '';
    resetIconPicker();
    document.getElementById('newCatBox').classList.remove('show');
    showToast(tr('toast.catAdded'));
  }
}

// ── 사진 ─────────────────────────────────────────────────────
function onPhotoSelect(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const reader = new FileReader();
  reader.onload = e => {
    photosData.push(e.target.result);
    renderPhotoGrid();
  };
  reader.readAsDataURL(file);
  input.value = '';
}
function removePhoto(idx) {
  photosData.splice(idx, 1);
  renderPhotoGrid();
}
function renderPhotoGrid() {
  const grid = document.getElementById('photoGrid');
  const items = document.getElementById('photoGridItems');
  if (!photosData.length) { grid.style.display = 'none'; items.innerHTML = ''; return; }
  grid.style.display = 'block';
  items.innerHTML = photosData.map((src, i) =>
    `<div class="photo-grid-item">
      <img src="${src}" alt="" onclick="openPhoto('${src}')">
      <button class="photo-grid-x" onclick="removePhoto(${i})">✕</button>
    </div>`
  ).join('') +
  `<div class="photo-add-btn" onclick="document.getElementById('photoInput').click()">＋</div>`;
}
function openPhoto(src) {
  document.getElementById('photoViewerImg').src = src;
  document.getElementById('photoViewer').classList.add('show');
}

// ── 검색 ──────────────────────────────────────────────────────
function openSearch() {
  document.getElementById('searchInput').value = '';
  document.getElementById('searchResults').innerHTML = '<div class="search-empty">검색어를 입력하세요</div>';
  document.getElementById('searchOverlay').classList.add('show');
  setTimeout(() => document.getElementById('searchInput').focus(), 150);
}
function closeSearch() {
  document.getElementById('searchOverlay').classList.remove('show');
}
function doSearch(q) {
  q = q.trim().toLowerCase();
  const el = document.getElementById('searchResults');
  if (!q) { el.innerHTML = '<div class="search-empty">검색어를 입력하세요</div>'; return; }
  const results = txs.filter(t =>
    (t.description||'').toLowerCase().includes(q) ||
    (t.category||'').toLowerCase().includes(q) ||
    String(t.amount).includes(q) ||
    (t.payment||'').toLowerCase().includes(q)
  ).sort((a,b) => b.date.localeCompare(a.date));
  if (!results.length) { el.innerHTML = '<div class="search-empty">검색 결과가 없어요</div>'; return; }
  el.innerHTML = results.map(t => txRowHtml(t)).join('');
}

// ── 금액 입력 콤마 포맷 ───────────────────────────────────────
function formatAmtInput(el) {
  const pos = el.selectionStart;
  const before = el.value.length;
  const raw = el.value.replace(/[^0-9]/g, '');
  el.value = raw ? parseInt(raw).toLocaleString('ko-KR') : '';
  const diff = el.value.length - before;
  el.setSelectionRange(pos + diff, pos + diff);
}

// ── 저장 ─────────────────────────────────────────────────────
function saveTx() {
  const amt  = parseInt(document.getElementById('fAmt').value.replace(/,/g,''));
  const cat  = document.getElementById('fCat').value;
  const desc = document.getElementById('fDesc').value.trim();
  const date = document.getElementById('fDate').value;
  const pay  = document.getElementById('fPay').value;
  if (!amt||amt<=0) { alert('금액을 입력해주세요.'); return; }
  if (!date)        { alert('날짜를 선택해주세요.');  return; }
  if (editingTxId) {
    const idx = txs.findIndex(t => t.id === editingTxId);
    if (idx !== -1) {
      txs[idx] = { ...txs[idx], type:curType, amount:amt, category:cat, description:desc||cat, date, payment:pay, photos:[...photosData] };
      delete txs[idx].photo; // 구버전 필드 제거
    }
    editingTxId = null;
  } else {
    txs.push({ id:Date.now()+Math.random().toString(36).slice(2), type:curType, amount:amt, category:cat, description:desc||cat, date, payment:pay, photos:[...photosData] });
  }
  persist();
  closeModal();
  if (date.slice(0,7)!==curMonth) { curMonth=date.slice(0,7); setMonthLabel(); }
  if (calVisible) { renderCalendar(); } else { goTab('ledger'); }
}

// ── 내역 액션 시트 ───────────────────────────────────────────
function openTxAction(id) {
  // 다른 열린 시트 먼저 닫기
  document.getElementById('catdetOverlay').classList.remove('show');
  document.getElementById('daySheet').classList.remove('show');
  activeTxId = id;
  const t = txs.find(x => x.id === id);
  if (!t) return;
  const photos = Array.isArray(t.photos) ? t.photos : (t.photo ? [t.photo] : []);
  const _sign = t.type === 'income' ? '+' : '−';
  document.getElementById('txaSummary').innerHTML = `
    <div class="txa-summary">
      <div class="txa-icon" style="background:${getIconBg(t.category)}">${getIconHtml(t.category,18)}</div>
      <div class="txa-mid">
        <div class="txa-desc">${(t.description && t.description !== t.category) ? esc(t.description) : dn(t.category, CAT_NAME_MAP)}</div>
        <div class="txa-sub">${dn(t.category, CAT_NAME_MAP)}${t.payment?' · '+dn(t.payment, PAY_NAME_MAP):''} · ${t.date}</div>
      </div>
      <div class="txa-amt ${t.type}">${_sign}${fmtH(t.amount)}</div>
    </div>
    ${photos.length ? buildCarousel(photos, 'txa') : ''}
  `;
  if (photos.length > 1) initCarousel('txa');
  document.getElementById('txaOverlay').classList.add('show');
  refreshIcons();
}
function closeTxaOverlay(e) {
  if (e.target === document.getElementById('txaOverlay'))
    document.getElementById('txaOverlay').classList.remove('show');
}
function showTxDetail() {
  const t = txs.find(x => x.id === activeTxId);
  if (!t) return;
  const typeLabel = t.type==='expense' ? tr('lbl.expense') : tr('lbl.income');
  document.getElementById('detailBody').innerHTML = `
    <div class="detail-row"><span class="detail-key">${tr('lbl.type')}</span><span class="detail-val" style="color:${t.type==='expense'?'#EF4444':'#3B82F6'}">${typeLabel}</span></div>
    <div class="detail-row"><span class="detail-key">${tr('lbl.amount')}</span><span class="detail-val" style="color:${t.type==='expense'?'#EF4444':'#3B82F6'}">${t.type==='income'?'+':'−'}${fmtH(t.amount)}</span></div>
    <div class="detail-row"><span class="detail-key">${tr('lbl.category')}</span><span class="detail-val" style="display:flex;align-items:center;gap:6px"><i class="${_icMeta(t.category).fa}" style="color:${_icMeta(t.category).c}"></i>${dn(t.category, CAT_NAME_MAP)}</span></div>
    <div class="detail-row"><span class="detail-key">${tr('lbl.content')}</span><span class="detail-val">${esc(t.description||'-')}</span></div>
    <div class="detail-row"><span class="detail-key">${tr('lbl.payment')}</span><span class="detail-val">${dn(t.payment, PAY_NAME_MAP) || '-'}</span></div>
    <div class="detail-row"><span class="detail-key">${tr('lbl.date')}</span><span class="detail-val">${t.date}</span></div>
    ${(()=>{ const ph=Array.isArray(t.photos)?t.photos:(t.photo?[t.photo]:[]); return ph.length?buildCarousel(ph,'det'):''; })()}
  `;
  document.getElementById('txaOverlay').classList.remove('show');
  document.getElementById('detailOverlay').classList.add('show');
  const ph2=Array.isArray(t.photos)?t.photos:(t.photo?[t.photo]:[]);
  if (ph2.length > 1) initCarousel('det');
}
function editTx() {
  const t = txs.find(x => x.id === activeTxId);
  if (!t) return;
  document.getElementById('txaOverlay').classList.remove('show');
  editingTxId = activeTxId;
  fillModal(t, '내역 수정');
}
function copyTx() {
  const t = txs.find(x => x.id === activeTxId);
  if (!t) return;
  document.getElementById('txaOverlay').classList.remove('show');
  editingTxId = null;
  fillModal({...t, date: new Date().toISOString().slice(0,10)}, '내역 복사');
}
function _removeTx(id) {
  const t = txs.find(x => x.id === id);
  txs = txs.filter(x => x.id !== id);
  persist(); renderLedger();
  if (calVisible) renderCalendar();
  // DB에서도 삭제 (db_id가 있는 항목만)
  if (IS_LOGGED_IN && t && t.db_id) {
    const fd = new FormData(); fd.append('id', t.db_id);
    fetch('../api/?action=delete', { method:'POST', body:fd, credentials:'same-origin' }).catch(()=>{});
  }
}
function deleteTxFromAction() {
  document.getElementById('txaOverlay').classList.remove('show');
  if (!confirm('이 내역을 삭제할까요?')) return;
  _removeTx(activeTxId);
}
// ── 삭제 ─────────────────────────────────────────────────────
function askDelete(id) {
  if (!confirm('이 내역을 삭제할까요?')) return;
  _removeTx(id);
}
// ── 다크 모드 ────────────────────────────────────────────────
const DARK_SK = 'ddgb_dark_v1';
let isDark = localStorage.getItem(DARK_SK) === '1';
function applyDarkMode() {
  document.body.classList.toggle('dark', isDark);
  const toggle = document.getElementById('darkToggle');
  if (toggle) toggle.checked = isDark;
}
function toggleDarkMode() {
  isDark = !isDark;
  localStorage.setItem(DARK_SK, isDark ? '1' : '0');
  applyDarkMode();
  showToast(isDark ? '🌙 다크 모드로 전환됐어요' : '☀️ 라이트 모드로 전환됐어요');
  if (IS_LOGGED_IN) {
    const fd = new FormData();
    fd.append('key', 'dark_mode'); fd.append('value', isDark ? '1' : '0');
    fetch('../api/?action=settings_save', { method: 'POST', body: fd }).catch(() => {});
  }
}

// ── 고정 지출 (DB 연동) ──────────────────────────────────────
const FIXED_SK = 'ddgb_fixed_v1';
let fixedItems = [];
let _fxCycle = 'weekly';
let _fxDow   = 1; // 월요일

function loadFixed() {
  try { fixedItems = JSON.parse(localStorage.getItem(FIXED_SK)||'[]'); } catch { fixedItems=[]; }
}
function persistFixed() { localStorage.setItem(FIXED_SK, JSON.stringify(fixedItems)); }

function setFxCycle(c) {
  _fxCycle = c;
  document.querySelectorAll('.fx-cycle-btn').forEach(b => {
    b.classList.toggle('on', b.dataset.cycle === c);
  });
  document.getElementById('fxWeekRow').style.display  = c==='weekly'  ? 'flex'  : 'none';
  document.getElementById('fxMonthRow').style.display = c==='monthly' ? 'block' : 'none';
  document.getElementById('fxYearRow').style.display  = c==='yearly'  ? 'block' : 'none';
}
function setFxDow(d) {
  _fxDow = d;
  document.querySelectorAll('.fx-dow-btn').forEach(b => {
    b.classList.toggle('on', parseInt(b.dataset.dow) === d);
  });
}
function fxCycleLabel(f) {
  const c = f.cycle || 'monthly';
  const days = ['일','월','화','수','목','금','토'];
  if (c === 'weekly')  return `매주 ${days[f.day_of_week]||''}요일`;
  if (c === 'monthly') return `매달 ${f.day_of_month}일`;
  if (c === 'yearly')  return `매년 ${f.month_of_year}월 ${f.day_of_month}일`;
  return '';
}

function openFixedModal() {
  document.getElementById('fixedModal').classList.add('show');
  // 카테고리 셀렉트 채우기
  if (IS_LOGGED_IN) {
    fetch('../api/?action=categories', { credentials:'same-origin' })
      .then(r => r.json()).then(cats => {
        const sel = document.getElementById('fxCat');
        const cur = sel.value;
        sel.innerHTML = '<option value="">카테고리 선택</option>';
        cats.filter(c => c.type === (document.getElementById('fxType').value || 'expense'))
            .forEach(c => { sel.innerHTML += `<option value="${c.id}">${c.icon||'📦'} ${esc(c.name)}</option>`; });
        sel.value = cur;
      }).catch(() => {});
  }
  loadFixedList();
}
function loadFixedList() {
  if (IS_LOGGED_IN) {
    document.getElementById('fixedList').innerHTML = '<div style="text-align:center;padding:16px;color:#bdbdbd;font-size:13px">불러오는 중...</div>';
    fetch('../api/?action=fixed_list', { credentials:'same-origin' })
      .then(r => r.json()).then(data => { fixedItems = data; renderFixedList(); })
      .catch(() => { loadFixed(); renderFixedList(); });
  } else {
    loadFixed(); renderFixedList();
  }
}
function closeFixedModal() {
  document.getElementById('fixedModal').classList.remove('show');
}
function renderFixedList() {
  const el = document.getElementById('fixedList');
  if (!fixedItems.length) {
    el.innerHTML = '<div style="text-align:center;padding:20px 0;color:#bdbdbd;font-size:13px">등록된 고정 항목이 없어요<br>아래에서 추가해보세요</div>';
    return;
  }
  el.innerHTML = fixedItems.map(f => {
    const _fi = f.type === 'income'
      ? { fa:'fa-solid fa-coins', bg:'#DCFCE7', c:'#22C55E' }
      : { fa:'fa-solid fa-calendar-check', bg:'#FEE2E2', c:'#EF4444' };
    return `
    <div style="display:flex;align-items:center;padding:15px 16px;border-bottom:1px solid #f5f5f5;gap:12px">
      <span style="width:40px;height:40px;border-radius:50%;background:${_fi.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="${_fi.fa}" style="color:${_fi.c};font-size:15px"></i>
      </span>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;color:#111827">${esc(f.name)}</div>
        <div style="font-size:12px;color:#9e9e9e;margin-top:3px">${fxCycleLabel(f)} · ${f.type==='income'?'수입':'지출'}</div>
      </div>
      <span style="font-size:14px;font-weight:700;color:${f.type==='income'?'#3B82F6':'#EF4444'};white-space:nowrap">${f.type==='income'?'+':'−'}${fmtH(f.amount)}</span>
      <button onclick="deleteFixed(${f.id})"
        style="background:none;border:1px solid #e2e8f0;border-radius:7px;color:#94a3b8;cursor:pointer;padding:6px 9px;display:flex;align-items:center">
        <i data-lucide="trash-2" style="width:14px;height:14px;stroke-width:1.75"></i>
      </button>
    </div>`;
  }).join('');
  refreshIcons();
}
function fmtFixedAmt(el) {
  const raw = el.value.replace(/[^0-9]/g,'');
  el.value = raw ? Number(raw).toLocaleString() : '';
}
// 수입/지출 변경 시 카테고리 목록 갱신
document.addEventListener('DOMContentLoaded', () => {
  const fxType = document.getElementById('fxType');
  if (fxType) fxType.addEventListener('change', () => { if(document.getElementById('fixedModal').classList.contains('show')) openFixedModal(); });
  // 주기 버튼 초기 스타일
  setFxCycle('weekly');
  // 초기 탭(가계부)은 흰 헤더 모드
  document.getElementById('appHeader').classList.add('ledger-mode');
});

// 소급 확인 팝업용 임시 저장
let _pendingFxFd = null;

function addFixed() {
  const name   = document.getElementById('fxName').value.trim();
  const amtRaw = document.getElementById('fxAmt').value.replace(/[^0-9]/g,'');
  const amount = parseInt(amtRaw)||0;
  const type   = document.getElementById('fxType').value;
  const catId  = document.getElementById('fxCat').value;
  if (!name)       { showToast('항목명을 입력해주세요'); return; }
  if (amount <= 0) { showToast('금액을 입력해주세요'); return; }

  const catSelect = document.getElementById('fxCat');
  const catName   = catId && catSelect.selectedIndex > 0
    ? catSelect.options[catSelect.selectedIndex].text.replace(/^[^\s]+\s/, '') // 이모지 제거
    : name;

  const fd = new FormData();
  fd.append('name', name); fd.append('amount', amount);
  fd.append('type', type); fd.append('cycle', _fxCycle);
  fd.append('category_name', catName);
  if (catId) fd.append('category_id', catId);

  let dom = 1, moy = 0;
  if (_fxCycle === 'weekly') {
    fd.append('day_of_week', _fxDow);
    fd.append('day_of_month', 1);
  } else if (_fxCycle === 'monthly') {
    dom = parseInt(document.getElementById('fxDom').value) || 1;
    fd.append('day_of_month', dom);
  } else {
    moy = parseInt(document.getElementById('fxMoy').value)  || 1;
    dom = parseInt(document.getElementById('fxDomY').value) || 1;
    fd.append('month_of_year', moy);
    fd.append('day_of_month', dom);
  }

  // 소급 확인: monthly/yearly이고 해당 날짜가 오늘 이전이면 팝업
  if (IS_LOGGED_IN && _fxCycle !== 'weekly') {
    const now   = new Date();
    const today = now.getDate();
    const mon   = now.getMonth() + 1; // 1-based
    const year  = now.getFullYear();
    let isPast  = false;

    if (_fxCycle === 'monthly' && dom < today) {
      isPast = true;
      const monNames = ['1월','2월','3월','4월','5월','6월','7월','8월','9월','10월','11월','12월'];
      const label = `${monNames[mon-1]} ${dom}일`;
      document.getElementById('fxRetroMsg').textContent =
        `이미 지난 날짜네요! 이번 달(${label}) 내역에도 지금 바로 기록할까요?`;
    } else if (_fxCycle === 'yearly' && moy === mon && dom < today) {
      isPast = true;
      document.getElementById('fxRetroMsg').textContent =
        `이미 지난 날짜네요! 올해(${moy}월 ${dom}일) 내역에도 지금 바로 기록할까요?`;
    }

    if (isPast) {
      _pendingFxFd = fd;
      document.getElementById('fxRetroModal').style.display = 'flex';
      return;
    }
  }

  // 소급 없이 바로 등록
  fd.append('apply_now', '0');
  submitFixed(false, fd);
}

function submitFixed(applyNow, fdOverride) {
  document.getElementById('fxRetroModal').style.display = 'none';
  const fd = fdOverride || _pendingFxFd;
  _pendingFxFd = null;
  if (!fd) return;

  fd.set('apply_now', applyNow ? '1' : '0');

  const btn = document.querySelector('#fixedModal button[onclick="addFixed()"]');
  if (btn) { btn.disabled = true; btn.textContent = '등록 중...'; }

  if (IS_LOGGED_IN) {
    fetch('../api/?action=fixed_add', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (btn) { btn.disabled = false; btn.textContent = '+ 추가하기'; }
        if (res.status === 'ok') {
          document.getElementById('fxName').value = '';
          document.getElementById('fxAmt').value  = '';
          loadFixedList();
          closeFixedModal();
          if (res.applied > 0 && res.tx_date) {
            // txs에 없으면 추가 (기존 항목 중복 방지)
            const dbId   = res.tx_db_id || null;
            const alreadyInTxs = dbId
              ? txs.some(t => t.db_id == dbId)
              : txs.some(t => t.description === fd.get('name') && t.date === res.tx_date);
            if (!alreadyInTxs) {
              const catName = fd.get('category_name') || fd.get('name');
              txs.push({
                id:          dbId ? 'dbtx_' + dbId : 'fixed_' + Date.now(),
                db_id:       dbId,
                type:        fd.get('type') || 'expense',
                amount:      parseInt(fd.get('amount')) || 0,
                category:    catName,
                description: fd.get('name'),
                date:        res.tx_date,
                payment:     '자동',
                photos:      []
              });
              persist();
            }
            // 소급된 날짜의 월로 이동 후 렌더
            curMonth = res.tx_date.slice(0, 7);
            setMonthLabel();
            renderLedger();
            if (calVisible) renderCalendar();
            showToast('달력에 기록됐어요! 📌');
          } else {
            showToast('고정 항목이 추가됐어요 📌');
          }
        } else {
          showToast('추가 실패: ' + (res.message||'오류'));
        }
      })
      .catch(() => {
        if (btn) { btn.disabled = false; btn.textContent = '+ 추가하기'; }
        showToast('오류가 발생했어요. 다시 시도해주세요.');
      });
  } else {
    if (btn) { btn.disabled = false; btn.textContent = '+ 추가하기'; }
    const name = fd.get('name'); const amount = parseInt(fd.get('amount'));
    const type = fd.get('type');
    fixedItems.push({ id: Date.now(), name, amount, type, cycle: _fxCycle, day_of_month: 1, day_of_week: _fxDow });
    persistFixed(); renderFixedList();
    document.getElementById('fxName').value = '';
    document.getElementById('fxAmt').value  = '';
    showToast('고정 항목이 추가됐어요 📌');
  }
}
function deleteFixed(id) {
  const fx = fixedItems.find(f => String(f.id) === String(id));
  if (!fx) return;
  if (!confirm(`"${fx.name}" 고정 항목을 삭제할까요?`)) return;

  // 이미 자동 생성된 연관 내역 찾기 (description 일치 + payment=자동)
  const linked = txs.filter(t => t.description === fx.name && t.payment === '자동');
  let deleteLinked = false;
  if (linked.length > 0) {
    deleteLinked = confirm(`이번 달에 이미 기록된 "${fx.name}" 내역 ${linked.length}건도 함께 삭제할까요?\n\n취소하면 고정 항목 설정만 삭제되고 내역은 유지됩니다.`);
  }

  if (IS_LOGGED_IN) {
    const fd = new FormData(); fd.append('id', id);
    fetch('../api/?action=fixed_delete', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json()).then(() => {
        loadFixedList();
        if (deleteLinked && linked.length > 0) {
          txs = txs.filter(t => !(t.description === fx.name && t.payment === '자동'));
          persist(); renderLedger();
          if (calVisible) renderCalendar();
          // DB에서도 연관 내역 삭제
          linked.forEach(t => {
            if (t.db_id) {
              const fd2 = new FormData(); fd2.append('id', t.db_id);
              fetch('../api/?action=delete', { method:'POST', body:fd2, credentials:'same-origin' }).catch(()=>{});
            }
          });
          showToast(`"${fx.name}" 설정과 내역 ${linked.length}건을 삭제했어요`);
        } else {
          showToast('고정 항목이 삭제됐어요');
        }
      });
  } else {
    fixedItems = fixedItems.filter(f => String(f.id) !== String(id));
    persistFixed(); renderFixedList();
    if (deleteLinked && linked.length > 0) {
      txs = txs.filter(t => !(t.description === fx.name && t.payment === '자동'));
      persist(); renderLedger();
      if (calVisible) renderCalendar();
    }
    showToast('삭제됐어요');
  }
}
function autoApplyFixed() {
  if (!IS_LOGGED_IN) return;
  fetch('../api/?action=fixed_apply', { method:'POST', body:new FormData(), credentials:'same-origin' })
    .then(r => r.json()).then(res => {
      if (res.added > 0 && Array.isArray(res.items) && res.items.length > 0) {
        // 로컬 txs에 없는 항목만 추가 (중복 방지)
        // 이중 중복 방지: id 기반 + description+date 기반
        const existingIds  = new Set(txs.map(t => t.id));
        const existingKeys = new Set(txs.map(t => t.description + '|' + t.date));
        res.items.forEach(item => {
          const key = item.description + '|' + item.date;
          if (!existingIds.has(item.id) && !existingKeys.has(key)) {
            item.db_id = parseInt(item.id.split('_').pop()) || null;
            txs.push(item);
          }
        });
        persist();
        renderLedger();
        if (calVisible) renderCalendar();
        showToast(`고정 항목 ${res.added}건이 자동 등록됐어요 📌`);
      }
    }).catch(() => {});
}

// ── 카테고리 편집 (DB 연동) ──────────────────────────────────
let catEditType = 'expense';
// PHP가 페이지 로드 시 직접 심어줌 → AJAX 불필요
let dbCats = <?= json_encode($userCats ?? ['expense'=>[],'income'=>[]], JSON_UNESCAPED_UNICODE) ?>;

function openCatEditModal() {
  catEditType = 'expense';
  document.getElementById('cetab-expense').classList.add('on');
  document.getElementById('cetab-income').classList.remove('on');
  document.getElementById('catEditModal').classList.add('show');
  // PHP가 이미 dbCats를 채워줬으므로 바로 렌더
  renderCatEditList();
}
function closeCatEditModal() {
  document.getElementById('catEditModal').classList.remove('show');
  // 닫을 때 내역추가 팝업 카테고리 select 동기화
  buildCatSelect(curType);
}
function setCatEditType(t) {
  catEditType = t;
  document.getElementById('cetab-expense').classList.toggle('on', t==='expense');
  document.getElementById('cetab-income').classList.toggle('on', t==='income');
  renderCatEditList();
}
function renderCatEditList() {
  const el   = document.getElementById('catEditList');
  const cats = IS_LOGGED_IN ? (dbCats[catEditType]||[]) : (customCats[catEditType]||[]);
  if (!cats.length) {
    el.innerHTML = '<div style="text-align:center;padding:28px 0;color:#bdbdbd;font-size:14px">카테고리가 없어요<br><span style="font-size:12px">아래에서 추가해보세요</span></div>';
    return;
  }
  el.innerHTML = cats.map(c => {
    const m = _icMeta(c.name);
    return `
    <div style="display:flex;align-items:center;padding:14px 8px;border-bottom:1px solid #f0f0f0;gap:14px">
      <span style="width:42px;height:42px;border-radius:50%;background:${m.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i data-lucide="${m.lu}" style="width:18px;height:18px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span style="flex:1;font-size:15px;font-weight:600;color:#111827">${esc(c.name)}</span>
      <button onclick="deleteCatEditItem(${c.id||0},'${esc(c.name)}')"
        style="background:none;border:1px solid #e2e8f0;cursor:pointer;color:#94a3b8;padding:7px 10px;border-radius:8px;line-height:1;display:flex;align-items:center">
        <i data-lucide="trash-2" style="width:14px;height:14px;stroke-width:1.75"></i>
      </button>
    </div>`;
  }).join('');
  refreshIcons();
}
function deleteCatEditItem(id, name) {
  if (!confirm('"' + name + '" 카테고리를 삭제할까요?')) return;
  if (IS_LOGGED_IN) {
    // ① 즉시 로컬에서 제거 (UI 먼저)
    dbCats[catEditType] = (dbCats[catEditType]||[]).filter(c => String(c.id) !== String(id));
    renderCatEditList();
    buildCatSelect(curType);
    renderLedger();
    showToast('카테고리가 삭제됐어요');
    // ② 백그라운드 서버 동기화
    const fd = new FormData(); fd.append('id', id);
    fetch('../api/?action=categories_delete', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'ok' && Array.isArray(res.categories) && res.categories.length >= 0) {
          dbCats.expense = res.categories.filter(c => c.type === 'expense');
          dbCats.income  = res.categories.filter(c => c.type === 'income');
          renderCatEditList();
          buildCatSelect(curType);
        }
      })
      .catch(() => {});
  } else {
    customCats[catEditType] = (customCats[catEditType]||[]).filter(c => c.name !== name);
    persistCats(); renderCatEditList(); renderLedger();
    showToast('카테고리가 삭제됐어요');
  }
}
function addCatEdit() {
  const icon = document.getElementById('ceEmoji').value.trim() || '📦';
  const name = document.getElementById('ceName').value.trim();
  if (!name) { showToast('카테고리 이름을 입력해주세요'); return; }
  if (IS_LOGGED_IN) {
    // ① 즉시 로컬에 추가 (UI 먼저)
    if (!Array.isArray(dbCats[catEditType])) dbCats[catEditType] = [];
    const tempId = 'tmp_' + Date.now();
    dbCats[catEditType] = [...dbCats[catEditType], { id: tempId, name, icon, type: catEditType }];
    document.getElementById('ceEmoji').value = '';
    document.getElementById('ceName').value  = '';
    renderCatEditList();
    buildCatSelect(curType);
    showToast('카테고리가 추가됐어요 🏷️');
    // ② 백그라운드 서버 저장
    const fd = new FormData();
    fd.append('name', name); fd.append('icon', icon); fd.append('type', catEditType);
    fetch('../api/?action=categories_add', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'ok') {
          // 서버에서 받은 전체 목록으로 교체 (tempId → 실제 ID)
          if (Array.isArray(res.categories) && res.categories.length > 0) {
            dbCats.expense = res.categories.filter(c => c.type === 'expense');
            dbCats.income  = res.categories.filter(c => c.type === 'income');
          } else {
            // 전체 목록 없으면 tempId만 실제 ID로 교체
            const item = (dbCats[catEditType]||[]).find(c => c.id === tempId);
            if (item && res.id) item.id = res.id;
          }
          renderCatEditList();
          buildCatSelect(curType);
        }
      })
      .catch(() => {}); // 이미 로컬에 추가됨, 오류 무시
  } else {
    if (!customCats[catEditType]) customCats[catEditType] = [];
    customCats[catEditType].push({ emoji: icon, name });
    persistCats();
    document.getElementById('ceEmoji').value = '';
    document.getElementById('ceName').value  = '';
    renderCatEditList();
    showToast('카테고리가 추가됐어요 🏷️');
  }
}

// ── 결제수단 편집 모달 ─────────────────────────────────────
let _payEditIcon = { lu: 'credit-card', bg: '#607D8B' };

function openMePage(name) {
  const id = name === 'appSettings' ? 'mePageAppSettings' : 'mePageData';
  document.getElementById(id).classList.add('active');
  refreshIcons();
}
function closeMePage() {
  document.querySelectorAll('.me-subpage').forEach(p => p.classList.remove('active'));
}
function openPayEditModal() {
  _payEditIcon = { lu: 'credit-card', bg: '#607D8B' };
  const prev = document.getElementById('peIconPreview');
  if (prev) { prev.style.background = '#607D8B'; prev.innerHTML = `<i data-lucide="credit-card" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>`; }
  document.getElementById('peName').value = '';
  document.getElementById('payEditModal').classList.add('show');
  renderPayEditList();
  refreshIcons();
}
function closePayEditModal() {
  document.getElementById('payEditModal').classList.remove('show');
  buildPaySelect();
}
function renderPayEditList() {
  const el = document.getElementById('payEditList');
  const customRows = customPays.map((p, i) => {
    const m = _icMeta(p);
    return `<div style="display:flex;align-items:center;padding:12px 8px;border-bottom:1px solid #f0f0f0;gap:12px">
      <span style="width:38px;height:38px;border-radius:50%;background:${m.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i data-lucide="${m.lu}" style="width:16px;height:16px;color:${m.c};stroke-width:1.75"></i>
      </span>
      <span style="flex:1;font-size:14px;font-weight:600;color:#111827">${esc(p)}</span>
      <button onclick="deletePayEditItem(${i})"
        style="background:none;border:1px solid #e2e8f0;cursor:pointer;color:#94a3b8;padding:7px 10px;border-radius:8px;line-height:1;display:flex;align-items:center">
        <i data-lucide="trash-2" style="width:14px;height:14px;stroke-width:1.75"></i>
      </button>
    </div>`;
  }).join('');
  el.innerHTML = customRows || '<div style="text-align:center;padding:28px 0;color:#bdbdbd;font-size:14px">추가한 결제수단이 없어요<br><span style="font-size:12px">아래에서 추가해보세요</span></div>';
  refreshIcons();
}
function deletePayEditItem(idx) {
  const name = customPays[idx];
  if (!name) return;
  if (!confirm('"' + name + '" 결제수단을 삭제할까요?')) return;
  customPays.splice(idx, 1);
  localStorage.setItem(CUSTOM_PAYS_SK, JSON.stringify(customPays));
  renderPayEditList();
  showToast('결제수단이 삭제됐어요');
}
function addPayEdit() {
  const name = document.getElementById('peName').value.trim();
  if (!name) { showToast('결제수단 이름을 입력해주세요'); return; }
  if ([...DEFAULT_PAYS, ...customPays].includes(name)) { showToast('이미 있는 결제수단이에요'); return; }
  customPays.push(name);
  localStorage.setItem(CUSTOM_PAYS_SK, JSON.stringify(customPays));
  CAT_ICON_MAP[name] = { lu: selectedPayIcon.lu, bg: selectedPayIcon.bg, c: '#fff' };
  document.getElementById('peName').value = '';
  selectedPayIcon = { lu: 'credit-card', bg: '#607D8B' };
  const prev = document.getElementById('peIconPreview');
  if (prev) { prev.style.background = '#607D8B'; prev.innerHTML = `<i data-lucide="credit-card" style="width:18px;height:18px;color:#fff;stroke-width:1.75"></i>`; }
  refreshIcons();
  renderPayEditList();
  showToast('결제수단이 추가됐어요');
}

// ── 푸시 알림 ────────────────────────────────────────────────
const NOTIF_SK = 'ddgb_notif_v1';
let _notifInterval = null;
function openNotifModal() {
  const saved = JSON.parse(localStorage.getItem(NOTIF_SK)||'{}');
  document.getElementById('notifTimeInput').value = saved.time || '21:00';
  updateNotifStatus();
  document.getElementById('notifModal').classList.add('show');
}
function closeNotifModal() { document.getElementById('notifModal').classList.remove('show'); }
function updateNotifStatus() {
  const dot  = document.getElementById('notifDot');
  const text = document.getElementById('notifStatusText');
  const btn  = document.getElementById('notifPermBtn');
  if (!('Notification' in window)) {
    text.textContent = '이 브라우저는 알림을 지원하지 않아요.';
    btn.disabled = true;
    return;
  }
  const perm = Notification.permission;
  if (perm === 'granted') {
    dot.classList.add('on');
    text.textContent = '알림이 허용되어 있습니다';
    btn.textContent  = '저장';
  } else if (perm === 'denied') {
    dot.classList.remove('on');
    text.textContent = '알림이 차단됐습니다. 브라우저 설정에서 허용해주세요.';
    btn.textContent  = '닫기';
  } else {
    dot.classList.remove('on');
    text.textContent = '알림 권한을 허용해야 합니다';
    btn.textContent  = '권한 허용 후 저장';
  }
  // 알림 행 값 업데이트
  const saved   = JSON.parse(localStorage.getItem(NOTIF_SK)||'{}');
  const rowVal  = document.getElementById('notifRowValue');
  if (rowVal) rowVal.textContent = (saved.enabled && perm === 'granted') ? (saved.time||'켜짐') : '꺼짐';
}
function handleNotifPermission() {
  if (!('Notification' in window)) { showToast('알림을 지원하지 않는 브라우저예요'); return; }
  if (Notification.permission === 'denied') { closeNotifModal(); return; }
  const time = document.getElementById('notifTimeInput').value;
  function save() {
    localStorage.setItem(NOTIF_SK, JSON.stringify({ time, enabled: true }));
    startNotifScheduler();
    updateNotifStatus();
    closeNotifModal();
    showToast(`매일 ${time}에 리마인드를 보내드릴게요 🔔`);
    if (IS_LOGGED_IN) {
      const fd = new FormData();
      fd.append('key', 'notif_time');    fd.append('value', time);
      fetch('../api/?action=settings_save', { method: 'POST', body: fd }).catch(() => {});
      const fd2 = new FormData();
      fd2.append('key', 'notif_enabled'); fd2.append('value', '1');
      fetch('../api/?action=settings_save', { method: 'POST', body: fd2 }).catch(() => {});
    }
  }
  if (Notification.permission === 'granted') { save(); return; }
  Notification.requestPermission().then(perm => {
    updateNotifStatus();
    if (perm === 'granted') save();
  });
}
function startNotifScheduler() {
  clearInterval(_notifInterval);
  const settings = JSON.parse(localStorage.getItem(NOTIF_SK)||'{}');
  if (!settings.enabled || !settings.time) return;
  const [hh, mm] = settings.time.split(':').map(Number);
  let _lastNotifDate = '';
  _notifInterval = setInterval(() => {
    if (Notification.permission !== 'granted') return;
    const now = new Date();
    if (now.getHours() !== hh || now.getMinutes() !== mm) return;
    const todayStr = localDateStr(now);
    if (_lastNotifDate === todayStr) return; // 하루 1회
    _lastNotifDate = todayStr;
    const todayTxs = txs.filter(t => t.date === todayStr);
    const todayExp = todayTxs.filter(t => t.type==='expense').reduce((s,t) => s+t.amount, 0);
    const msg = todayTxs.length > 0
      ? `오늘 ${todayTxs.length}건, 총 지출 ${fmt(todayExp)} 기록됐어요!`
      : '오늘 가계부를 아직 기록하지 않으셨어요. 잊지 마세요! 💪';
    new Notification('마이가계부 📒', { body: msg });
  }, 30000); // 30초마다 체크
}

// ── 백업 & 복구 ──────────────────────────────────────────────
function openBackupModal() { document.getElementById('backupModal').classList.add('show'); }
function closeBackupModal() { document.getElementById('backupModal').classList.remove('show'); }
function doBackup() {
  if (IS_LOGGED_IN) {
    window.location.href = '../api/?action=export_json';
    closeBackupModal();
    return;
  }
  const data = {
    version: 2,
    exported_at: new Date().toISOString(),
    transactions: txs,
    custom_categories: customCats,
    survival: survGoal,
    widgets: reportWidgets,
    fixed: fixedItems,
  };
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `마이가계부_백업_${localDateStr(new Date())}.json`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  closeBackupModal();
  showToast('백업 파일이 다운로드됐어요 ✅');
}
function doRestore(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    try {
      const data = JSON.parse(e.target.result);
      if (!Array.isArray(data.transactions)) throw new Error('올바른 백업 파일이 아닙니다');
      if (!confirm(`${data.transactions.length}건의 내역을 복구할까요?\n현재 데이터는 덮어씌워집니다.`)) return;
      txs = data.transactions;
      persist();
      if (data.custom_categories) { customCats = data.custom_categories; persistCats(); }
      if (data.survival)          { survGoal   = data.survival; localStorage.setItem(SURV_SK, JSON.stringify(survGoal)); }
      if (data.widgets)           { reportWidgets = data.widgets; localStorage.setItem(WIDGETS_SK, JSON.stringify(reportWidgets)); }
      if (data.fixed)             { fixedItems = data.fixed; persistFixed(); }
      renderLedger();
      if (calVisible) renderCalendar();
      closeBackupModal();
      showToast(`${txs.length}건의 내역이 복구됐어요 ✅`);
    } catch(err) {
      showToast('파일을 읽을 수 없어요: ' + err.message);
    }
    input.value = '';
  };
  reader.readAsText(file);
}

// ── CSV 내보내기 ──────────────────────────────────────────────
function openExportModal() {
  const [y, m]   = curMonth.split('-');
  const monthCnt = txs.filter(t => t.date.startsWith(curMonth)).length;
  document.getElementById('exportMonthLabel').textContent = `${y}년 ${parseInt(m)}월 · ${monthCnt}건`;
  document.getElementById('exportAllLabel').textContent   = `전체 ${txs.length}건`;
  document.getElementById('exportModal').classList.add('show');
}
function closeExportModal() { document.getElementById('exportModal').classList.remove('show'); }
function doExportCSV(range) {
  if (IS_LOGGED_IN) {
    const ym = range === 'month' ? curMonth : '';
    window.location.href = `../api/?action=export_csv&ym=${ym}`;
    return;
  }
  const data = range === 'month' ? txs.filter(t => t.date.startsWith(curMonth)) : [...txs];
  data.sort((a,b) => a.date.localeCompare(b.date));
  const rows = [['날짜','유형','카테고리','내용','금액(원)','결제수단']];
  data.forEach(t => {
    rows.push([
      t.date,
      t.type === 'income' ? '수입' : '지출',
      t.category || '',
      t.description || '',
      t.amount,
      t.payment || ''
    ]);
  });
  const csv = rows.map(r =>
    r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')
  ).join('\r\n');
  const bom  = '\uFEFF';
  const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `마이가계부_${range==='month'?curMonth:'전체'}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showToast('CSV 파일이 다운로드됐어요 📊');
}

// ── 전체 내역 삭제 ────────────────────────────────────────────
function doDeleteAll() { openDeleteAllModal(); }
function openDeleteAllModal()  { document.getElementById('deleteAllModal').classList.add('show'); }
let _deleteAllStep = 0;
function confirmDeleteAll() {
  _deleteAllStep++;
  if (_deleteAllStep === 1) {
    const footer = document.querySelector('#deleteAllModal .danger-modal-footer');
    footer.innerHTML = `
      <div style="width:100%;margin-bottom:10px;font-size:13px;color:#c62828;text-align:center;font-weight:600">
        정말요? 이 작업은 되돌릴 수 없어요.<br>아래 버튼을 한 번 더 눌러 확인하세요.
      </div>
      <button class="danger-modal-cancel" onclick="closeDeleteAllModal()">취소</button>
      <button class="danger-modal-confirm" style="background:#b71c1c" onclick="confirmDeleteAll()">완전히 삭제</button>`;
    return;
  }
  _deleteAllStep = 0;
  if (IS_LOGGED_IN) {
    const fd = new FormData();
    fd.append('confirm', 'DELETE_ALL_CONFIRMED');
    fetch('../api/?action=truncate', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'ok') {
          txs = []; persist();
          closeDeleteAllModal();
          renderLedger();
          if (calVisible) renderCalendar();
          showToast('모든 내역이 삭제됐어요 🗑️');
        } else {
          showToast('삭제에 실패했어요: ' + (res.message || ''));
        }
      })
      .catch(() => showToast('서버 오류가 발생했어요'));
  } else {
    txs = []; persist();
    closeDeleteAllModal();
    renderLedger();
    if (calVisible) renderCalendar();
    showToast('모든 내역이 삭제됐어요 🗑️');
  }
}
function closeDeleteAllModal() {
  document.getElementById('deleteAllModal').classList.remove('show');
  _deleteAllStep = 0;
  // 버튼 원상복구
  const footer = document.querySelector('#deleteAllModal .danger-modal-footer');
  if (footer) footer.innerHTML = `
    <button class="danger-modal-cancel" onclick="closeDeleteAllModal()">취소</button>
    <button class="danger-modal-confirm" onclick="confirmDeleteAll()">모두 삭제</button>`;
}

// ── 도움말 ────────────────────────────────────────────────────
function openHelpModal()  { document.getElementById('helpModal').classList.add('show'); }
function closeHelpModal() { document.getElementById('helpModal').classList.remove('show'); }

// ── DB 카테고리 로드 (로그인 시) ─────────────────────────────
function loadDbCats(callback) {
  if (!IS_LOGGED_IN) { if (callback) callback(); return; }
  fetch('../api/?action=categories', { credentials:'same-origin' })
    .then(r => r.json()).then(data => {
      // 빈 배열이 오면 덮어쓰지 않음 (PHP 초기값 보호)
      if (Array.isArray(data) && data.length > 0) {
        dbCats.expense = data.filter(c => c.type === 'expense');
        dbCats.income  = data.filter(c => c.type === 'income');
        buildCatSelect(curType);
      }
      if (callback) callback();
    }).catch(() => { if (callback) callback(); });
}

// ── 통화 모달 ─────────────────────────────────────────────────
function renderCurrencyGrid(q) {
  const cur = getCurrCode();
  const kw = (q || '').trim().toLowerCase();
  const list = kw
    ? CURRENCY_LIST.filter(c => c.code.toLowerCase().includes(kw) || c.name.toLowerCase().includes(kw) || c.symbol.toLowerCase().includes(kw))
    : CURRENCY_LIST;
  const grid = document.getElementById('currencyGrid');
  if (!list.length) {
    grid.innerHTML = `<div style="text-align:center;padding:32px;color:#9e9e9e;font-size:13px">검색 결과 없음</div>`;
    return;
  }
  grid.innerHTML = list.map((c, i) => `
    <div onclick="setCurrency('${c.code}','${c.symbol}','${c.name}')"
      style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;cursor:pointer;${i>0?'border-top:1px solid #f0f0f0':''}${c.code===cur?';background:#f5f7ff':''}">
      <span style="font-size:15px;color:#222;font-weight:${c.code===cur?'600':'400'}">${c.name} ( ${c.symbol} )</span>
      <span style="font-size:14px;font-weight:700;color:${c.code===cur?'#364A6D':'#9e9e9e'}">${c.code}</span>
    </div>`).join('');
}
function openCurrencyModal() {
  const inp = document.getElementById('currencySearch');
  if (inp) inp.value = '';
  renderCurrencyGrid('');
  document.getElementById('currencyModal').classList.add('show');
  setTimeout(() => { if (inp) inp.focus(); }, 100);
}
function closeCurrencyModal() {
  document.getElementById('currencyModal').classList.remove('show');
}
function setCurrency(code, symbol, name) {
  localStorage.setItem(CURRENCY_SK, JSON.stringify({code, symbol, name}));
  const rowVal = document.getElementById('currencyRowValue');
  if (rowVal) rowVal.textContent = symbol + ' ' + code;
  closeCurrencyModal();
  showToast('통화가 변경됐어요');
  renderLedger();
  if (document.getElementById('pane-stats')?.classList.contains('active')) renderStats();
}

// ── 다국어 ───────────────────────────────────────────────────
const LANG_SK = 'app_lang';
const LANG_CODE_MAP = {'한국어':'ko','영어':'en','일본어':'ja','중국어':'zh','스페인어':'es'};
const TRANSLATIONS = {
  ko: {
    'app.title':'마이가계부','tab.ledger':'가계부','tab.stats':'통계','tab.report':'분석','tab.me':'나',
    'grid.settings':'앱 설정','grid.upgrade':'업그레이드','grid.help':'도움말','grid.data':'데이터','grid.contact':'문의하기',
    'page.appSettings':'앱 설정','page.data':'데이터',
    'section.records':'기록 관리','section.environment':'앱 환경','section.dataManagement':'데이터 관리',
    'row.fixedExpense':'고정 지출 설정','row.categories':'카테고리 편집','row.payments':'결제수단 편집',
    'row.currency':'기본 통화','row.notifications':'푸시 알림','row.theme':'테마','row.fontSize':'글꼴 크기','row.language':'언어',
    'row.backup':'백업 및 복구','row.export':'엑셀로 내보내기','row.deleteAll':'전체 내역 삭제',
    'lbl.income':'수입','lbl.expense':'지출','lbl.balance':'잔액','lbl.category':'카테고리','lbl.payment':'결제수단',
    'lbl.type':'유형','lbl.amount':'금액','lbl.content':'내용','lbl.date':'날짜','lbl.other':'기타','lbl.cntFmt':'{n}건','lbl.user':'사용자',
    'day.sun':'일','day.mon':'월','day.tue':'화','day.wed':'수','day.thu':'목','day.fri':'금','day.sat':'토',
    'period.week':'주','period.month':'월','period.year':'년',
    'stats.rankTitle':'카테고리별 지출 순위',
    'form.amount':'금액 (원)','form.desc':'내용 / 메모','form.descPh':'예) 편의점, 버스','form.date':'날짜',
    'form.catName':'카테고리 이름','form.payName':'결제수단 이름','form.catSelect':'선택',
    'btn.save':'저장','btn.add':'추가','btn.close':'닫기',
    'btn.detail':'상세정보','btn.edit':'수정','btn.copy':'복사','btn.delete':'삭제',
    'lbl.detail':'상세정보',
    'form.amount':'금액 (원)','form.amountPh':'0',
    'form.category':'카테고리','form.catSelect':'선택',
    'form.payment':'결제수단',
    'form.desc':'내용 / 메모','form.descPh':'예) 편의점, 버스','form.date':'날짜',
    'form.catName':'카테고리 이름','form.payName':'결제수단 이름',
    'search.ph':'내용, 카테고리, 금액 검색...','search.empty':'검색어를 입력하세요',
    'modal.addTx':'내역 추가','modal.editTx':'내역 수정',
    'section.txHistory':'거래 내역',
    'ledger.empty':'이번 달 내역이 없어요<br>아래 <b>＋</b> 버튼으로 추가하세요!',
    'widget.champ':'최고 지출','widget.dow':'요일별 소비 패턴','widget.survival':'목표 예산',
    'widget.top3':'카테고리 TOP 3','widget.mbti':'나의 소비 MBTI',
    'widget.feelOk':'만족해요','widget.feelRegret':'아까워요',
    'widget.survNotSet':'미설정','widget.survUnit':'원','widget.survTotal':'변동 지출 합계','widget.calculating':'계산 중...',
    'stats.totalLabel':'총 {type}','stats.rankFmt':'{group}별 {type} 순위',
    'report.monthExpense':'{m}월 지출','report.noExpense':'이번 달 지출 내역이 없어요',
    'report.champPct':'이번 달 총 지출의 {pct}가 이 한 번의 결제에서!',
    'report.addWidget':'항목 추가',
    'wdef.insight':'이번 달 요약','wdef.champion':'최고 지출 항목','wdef.dayofweek':'요일별 소비 패턴',
    'wdef.survival':'목표 예산','wdef.mbti':'나의 소비 MBTI','wdef.top3cats':'카테고리 TOP 3',
    'me.streak':'🔥 연속 기록 {n}일','me.streakZero':'아직 기록을 시작해봐요!',
    'me.monthRecord':'이번 달 기록','me.streakDays':'연속 기록일',
    'me.notLoggedIn':'비로그인','me.syncInfo':'로그인하면 서버에 동기화됩니다','me.loginBtn':'로그인 / 회원가입',
    'dow.noExpense':'지출없음',
    'badge.guard':'자산 수비대 🛡️','badge.explorer':'절약 탐험가 🧭','badge.sprout':'기록 새싹 🌱',
    'cat.dining':'식비','cat.transport':'교통','cat.shopping':'쇼핑','cat.medical':'의료',
    'cat.culture':'문화','cat.telecom':'통신','cat.housing':'주거','cat.other':'기타',
    'cat.salary':'급여','cat.allowance':'용돈','cat.otherIncome':'기타수입',
    'pay.cash':'현금','pay.credit':'신용카드','pay.debit':'체크카드','pay.transfer':'계좌이체',
    'pay.kakao':'카카오페이','pay.naver':'네이버페이','pay.toss':'토스','pay.other':'기타','pay.auto':'자동',
    'stats.yearTotal':'{y}년 전체',
    'fmt.dateGroup':'{d}일 ({dow})',
    'empty.noRecords':'내역이 없어요',
    'stats.noData':'이 기간에는 내역이 없어요!',
    'stats.noDataSub':'{period} 기간의<br>{type} 내역을 추가해 보세요',
    'wpop.moveUp':'↑ 위로 이동','wpop.moveDown':'↓ 아래로 이동','wpop.delete':'🗑️ 삭제',
    'report.editDone':'✅ 편집 완료','report.editStart':'✏️ 분석 항목 편집',
    'toast.feelOk':'좋은 선택이었어요! 😊','toast.feelRegret':'다음엔 조금만 참아봐요! 💪',
    'report.empty':'아직 분석 항목이 없어요.<br>아래 토글을 켜서 추가해보세요! ✨',
    'surv.thisWeek':'이번 주','surv.lastWeek':'지난 주','surv.weeksAgo':'{n}주 전',
    'surv.thisYear':'올해','surv.lastYear':'작년','surv.yearFmt':'{y}년',
    'surv.thisMonth':'이번 달','surv.lastMonth':'지난 달',
    'surv.weekRange':'{m1}/{d1}(월) ~ {m2}/{d2}(일)',
    'surv.budgetPct':'지출 {pct}%','surv.noGoal':'목표 미설정','surv.notSet':'미설정',
    'surv.weekNoGoal':'이 주는 목표가 설정되지 않았어요',
    'surv.totalVar':'변동 지출 합계 (고정비 제외)',
    'surv.danger':'🚨 예산 위험! 조금만 더 아껴써요',
    'surv.remaining':'남은 예산','surv.over':'초과 지출',
    'surv.msgNoGoal':'{period}은 목표 예산이 없었어요 📭',
    'surv.msgEnterGoal':'목표 예산을 입력하면<br>하루 가용 금액을 알려드려요 💡',
    'surv.msgOver':'{period} 목표를 <b>{amt}</b> 초과했어요 😰',
    'surv.msgSaved':'{period} 예산 <b>{amt}</b> 절약했어요! 🎉',
    'surv.msgDanger':'남은 <b>{days}일</b> 동안 매일 <b>{daily}</b>씩만 써야 해요 🚨',
    'surv.msgOk':'남은 <b>{days}일</b> 동안 매일 <b>{daily}</b>씩 사용 가능해요! 💰',
    'insight.tagStart':'#기록시작','insight.tagFirst':'#이번달첫기록','insight.tagEqual':'#균형유지',
    'insight.tagThrifty':'#알뜰살뜰','insight.tagSaved':'#절약성공','insight.tagSaving':'#절약중',
    'insight.tagOverspend':'#과소비경보','insight.tagUpBig':'#지출급증','insight.tagUpWarn':'#지출주의','insight.tagUpSmall':'#소폭증가',
    'insight.noData':'{name}님, 아직 이번 달 지출이 없어요!',
    'insight.noDataSub':'오늘부터 소비를 기록해봐요 ✏️',
    'insight.firstRecord':'{name}님, 이번 달은 현재까지 총 {amt}을 사용하셨네요!',
    'insight.firstRecordSub':'지난달 데이터가 없어 증감은 다음 달부터 확인할 수 있어요 📖',
    'insight.equal':'{name}님, 지난 달과 딱 같은 금액을 쓰셨어요.',
    'insight.equalSub':'균형 잡힌 소비 패턴이네요.',
    'insight.down30':'{name}님, 지난달보다 {pct}% 확 줄었어요! 절약 고수시네요 👑',
    'insight.down30Sub':'{amt} 절약! 이대로 쭉 이어가봐요 💚',
    'insight.down10':'{name}님, 지난달보다 {pct}% 아껴 쓰셨어요! 대단해요 👏',
    'insight.downSmall':'{name}님, 지난달보다 {pct}% 줄었어요. 잘 하고 계세요!',
    'insight.downSub':'{amt} 절약했어요. 이대로 쭉!',
    'insight.up50':'{name}님, 지출이 지난달보다 {pct}% 급증했어요! 지갑이 비상이에요 😰',
    'insight.up30':'{name}님, 이번 달 지출이 지난달보다 {pct}% 늘었어요. 지갑이 많이 얇아졌네요 😅',
    'insight.up10':'{name}님, 이번 달은 지난달보다 {pct}% 더 쓰셨어요. 조금만 더 조절해봐요! 💪',
    'insight.upSmall':'{name}님, 지난달보다 {pct}% 소폭 늘었어요. 아직 괜찮아요!',
    'insight.upSub':'지난달보다 {amt} 더 지출했어요.',
    'export.all':'전체',
    'dow.noData':'아직 이번 달 지출 데이터가 없어요.',
    'dow.balanced':'지출이 골고루 분포되어 있어요!',
    'dow.peak':'이번 달 <b>{day}요일</b> 지출이 가장 많아요',
    'dow.txCount':'총 {n}건',
    'dow.msg.mon':'한 주의 시작부터 에너지를 많이 쓰셨네요!<br>월요병을 소비로 이겨내셨나요? 😂',
    'dow.msg.tue':'평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱',
    'dow.msg.wed':'평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱',
    'dow.msg.thu':'평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱',
    'dow.msg.fri':'신나는 불금! 주말의 시작과 함께 지출이 터졌네요 🍺',
    'dow.msg.sat':'주말 FLEX 주의보! 🚨<br>예산 안에서 즐겨보는 건 어떨까요?',
    'dow.msg.sun':'일요일 지출이 가장 커요!<br>내일부터 시작될 한 주를 위해 오늘은 조금 아껴봐요 🏠',
    'mbti.title.eatj':'미식가형','mbti.title.shop':'트렌드세터형','mbti.title.yolo':'욜로라이프형',
    'mbti.title.move':'무브먼트형','mbti.title.hlth':'건강제일형','mbti.title.digi':'디지털노마드형',
    'mbti.title.home':'홈베이스형','mbti.title.none':'데이터 수집 중','mbti.title.free':'자유분방형',
    'mbti.desc.eatj':'먹는 게 남는 거! 오늘도 맛집 탐방 중인 타입이에요. 지갑이 열리는 건 음식 앞에서뿐!',
    'mbti.desc.shop':'쇼핑은 힐링! 눈 깜짝할 새 카트가 가득 차는 타입이에요. 오늘도 장바구니 투어 중?',
    'mbti.desc.yolo':'경험에 아낌없이 투자! 인생은 한 번이니까요. 추억이 최고의 재테크예요!',
    'mbti.desc.move':'항상 어딘가로 이동 중! 바쁘고 활동적인 타입이에요. 오늘도 달리는 중이죠?',
    'mbti.desc.hlth':'건강이 최우선! 몸 관리에 투자를 아끼지 않는 타입이에요. 건강이 진짜 재산이죠!',
    'mbti.desc.digi':'디지털 라이프의 달인! 구독 서비스 없인 못 사는 타입이에요. 오늘도 스트리밍 중?',
    'mbti.desc.home':'집이 제일 좋아! 나만의 공간 꾸미는 데 진심인 타입이에요. 오늘도 홈카페 중?',
    'mbti.desc.none':'이번 달 지출 내역을 추가하면 나만의 소비 MBTI가 분석돼요!',
    'mbti.desc.free':'정해진 패턴 없이 자유롭게! 다양한 곳에 고루 지출하는 유연한 타입이에요.',
    'mbti.budget.great':'절약 능력까지 갖춘 완벽한 소비러예요! 💪',
    'mbti.budget.ok':'적당한 균형감각을 가진 소비러예요 👍',
    'mbti.budget.warn':'거의 한계선! 조금만 더 아껴봐요 😅',
    'mbti.budget.over':'예산 초과! 다음 달엔 절약 모드 고고 😰',
    'fx.weekly':'매주 {dow}요일','fx.monthly':'매달 {d}일','fx.yearly':'매년 {m}월 {d}일',
    'fx.pastDateMonth':'이미 지난 날짜네요! 이번 달({label}) 내역에도 지금 바로 기록할까요?',
    'fx.pastDateYear':'이미 지난 날짜네요! 올해({m}월 {d}일) 내역에도 지금 바로 기록할까요?',
    'toast.coming':'준비 중이에요','toast.langSet':'언어가 변경됐어요 ✓','toast.fontChanged':'글꼴 크기가 변경됐어요',
    'toast.catDeleted':'카테고리가 삭제됐어요','toast.catEmptyName':'카테고리 이름을 입력해주세요',
    'toast.catDuplicate':'이미 있는 카테고리예요','toast.catAdded':'카테고리가 추가됐어요 🏷️',
    'toast.payDeleted':'결제수단이 삭제됐어요','toast.payEmptyName':'결제수단 이름을 입력해주세요',
    'toast.payDuplicate':'이미 있는 결제수단이에요','toast.payAdded':'결제수단이 추가됐어요',
    'toast.fxEmptyName':'항목명을 입력해주세요','toast.fxEmptyAmt':'금액을 입력해주세요',
    'toast.fxSaved':'달력에 기록됐어요! 📌','toast.fxAdded':'고정 항목이 추가됐어요 📌',
    'toast.fxAddFail':'추가 실패: {msg}','toast.fxError':'오류가 발생했어요. 다시 시도해주세요.',
    'toast.fxDeleted':'고정 항목이 삭제됐어요','toast.deleted':'삭제됐어요',
    'toast.notifSet':'매일 {time}에 리마인드를 보내드릴게요 🔔',
    'toast.noNotifSupport':'알림을 지원하지 않는 브라우저예요',
    'toast.backupDown':'백업 파일이 다운로드됐어요 ✅','toast.backupError':'파일을 읽을 수 없어요: {err}',
    'toast.csvDown':'CSV 파일이 다운로드됐어요 📊',
    'toast.allDeleted':'모든 내역이 삭제됐어요 🗑️','toast.deleteFail':'삭제에 실패했어요: {msg}',
    'toast.serverError':'서버 오류가 발생했어요',
    'toast.inquirySent':'문의가 접수됐어요 ✓','toast.inquiryEmpty':'제목과 내용을 입력해주세요',
    'fx.loadingList':'불러오는 중...','fx.emptyList':'등록된 고정 항목이 없어요<br>아래에서 추가해보세요',
    'confirm.deleteLinked':'이번 달에 이미 기록된 "{name}" 내역 {count}건도 함께 삭제할까요?\n\n취소하면 고정 항목 설정만 삭제되고 내역은 유지됩니다.',
    'opt.selectCat':'카테고리 선택',
    'fontsize.normal':'보통','fontsize.large':'크게','fontsize.xlarge':'아주 크게',
    'lang.ko':'한국어','lang.en':'영어','lang.ja':'일본어','lang.zh':'중국어','lang.es':'스페인어',
    'alert.dateRange':'시작일과 종료일을 선택해주세요.','alert.dateOrder':'종료일이 시작일보다 빠를 수 없어요.',
    'alert.enterAmt':'금액을 입력해주세요.','alert.enterDate':'날짜를 선택해주세요.',
    'ico.food':'음식','ico.shopping':'쇼핑','ico.transport':'교통','ico.car':'자동차',
    'ico.housing':'주거','ico.health':'건강','ico.telecom':'통신','ico.culture':'문화',
    'ico.salary':'급여','ico.gift':'선물','ico.travel':'여행','ico.cafe':'카페',
    'ico.alcohol':'술','ico.education':'교육','ico.sports':'스포츠','ico.pet':'반려동물',
    'ico.repair':'수리','ico.electronics':'전자제품','ico.clothing':'의류','ico.child':'아동',
    'ico.grocery':'채소','ico.beauty':'뷰티','ico.social':'사교','ico.savings':'저축',
    'ico.donation':'기부','ico.smoking':'담배','ico.otherIncome':'기타수입','ico.other':'기타',
    'ico.credit':'신용카드','ico.cash':'현금','ico.transfer':'계좌이체','ico.wallet':'지갑',
    'ico.easypay':'간편결제','ico.qr':'QR결제','ico.pay':'페이','ico.points':'포인트',
    'ico.voucher':'상품권','ico.bank':'은행','ico.piggyBank':'저금통','ico.coins':'동전',
    'ico.autoTransfer':'자동이체',
    'ico.allowance':'용돈','ico.invest':'투자','ico.interest':'이자','ico.save':'저금',
    'ico.sideIncome':'부수입','ico.refund':'환급','ico.bonus':'보너스',
    'ico.regularIncome':'정기수입','ico.reward':'포상',
  },
  en: {
    'app.title':'My Account Book','tab.ledger':'Ledger','tab.stats':'Stats','tab.report':'Report','tab.me':'Me',
    'grid.settings':'Settings','grid.upgrade':'Upgrade','grid.help':'Help','grid.data':'Data','grid.contact':'Contact',
    'page.appSettings':'App Settings','page.data':'Data',
    'section.records':'Record Management','section.environment':'App Environment','section.dataManagement':'Data Management',
    'row.fixedExpense':'Fixed Expenses','row.categories':'Edit Categories','row.payments':'Edit Payments',
    'row.currency':'Default Currency','row.notifications':'Notifications','row.theme':'Theme','row.fontSize':'Font Size','row.language':'Language',
    'row.backup':'Backup & Restore','row.export':'Export to Excel','row.deleteAll':'Delete All Records',
    'lbl.income':'Income','lbl.expense':'Expense','lbl.balance':'Balance','lbl.category':'Category','lbl.payment':'Payment',
    'lbl.type':'Type','lbl.amount':'Amount','lbl.content':'Note','lbl.date':'Date','lbl.other':'Other','lbl.cntFmt':'{n} records','lbl.user':'User',
    'day.sun':'Sun','day.mon':'Mon','day.tue':'Tue','day.wed':'Wed','day.thu':'Thu','day.fri':'Fri','day.sat':'Sat',
    'period.week':'Wk','period.month':'Mo','period.year':'Yr',
    'stats.rankTitle':'Expense Ranking by Category',
    'form.amount':'Amount','form.desc':'Note / Memo','form.descPh':'e.g. Coffee, Bus','form.date':'Date',
    'form.catName':'Category name','form.payName':'Payment name','form.catSelect':'Select',
    'btn.save':'Save','btn.add':'Add','btn.close':'Close',
    'btn.detail':'Detail','btn.edit':'Edit','btn.copy':'Copy','btn.delete':'Delete',
    'lbl.detail':'Detail',
    'form.amount':'Amount','form.amountPh':'0',
    'form.category':'Category','form.catSelect':'Select',
    'form.payment':'Payment',
    'form.desc':'Note / Memo','form.descPh':'e.g. Coffee, Bus','form.date':'Date',
    'form.catName':'Category name','form.payName':'Payment name',
    'search.ph':'Search by note, category, amount...','search.empty':'Enter a search term',
    'modal.addTx':'Add Record','modal.editTx':'Edit Record',
    'section.txHistory':'Transactions',
    'ledger.empty':'No records this month<br>Tap <b>＋</b> to add one!',
    'widget.champ':'Top Expense','widget.dow':'Spending by Day of Week','widget.survival':'Budget Goal',
    'widget.top3':'Category TOP 3','widget.mbti':'Spending MBTI',
    'widget.feelOk':'Satisfied','widget.feelRegret':'Regret it',
    'widget.survNotSet':'Not set','widget.survUnit':'','widget.survTotal':'Total Variable Expenses','widget.calculating':'Calculating...',
    'stats.totalLabel':'Total {type}','stats.rankFmt':'{type} Ranking by {group}',
    'report.monthExpense':'Month {m} Expense','report.noExpense':'No expenses this month',
    'report.champPct':'{pct} of this month\'s total expenses in one transaction!',
    'report.addWidget':'Add Widget',
    'wdef.insight':'Monthly Summary','wdef.champion':'Top Expense','wdef.dayofweek':'Spending by Day',
    'wdef.survival':'Budget Goal','wdef.mbti':'Spending MBTI','wdef.top3cats':'Category TOP 3',
    'me.streak':'🔥 {n}-day streak','me.streakZero':'Start recording today!',
    'me.monthRecord':'This month','me.streakDays':'Streak days',
    'me.notLoggedIn':'Not logged in','me.syncInfo':'Log in to sync to server','me.loginBtn':'Login / Sign up',
    'dow.noExpense':'No expense',
    'badge.guard':'Asset Guardian 🛡️','badge.explorer':'Savings Explorer 🧭','badge.sprout':'Record Sprout 🌱',
    'cat.dining':'Dining','cat.transport':'Transport','cat.shopping':'Shopping','cat.medical':'Medical',
    'cat.culture':'Culture','cat.telecom':'Telecom','cat.housing':'Housing','cat.other':'Other',
    'cat.salary':'Salary','cat.allowance':'Allowance','cat.otherIncome':'Other Income',
    'pay.cash':'Cash','pay.credit':'Credit Card','pay.debit':'Debit Card','pay.transfer':'Bank Transfer',
    'pay.kakao':'KakaoPay','pay.naver':'NaverPay','pay.toss':'Toss','pay.other':'Other','pay.auto':'Auto',
    'stats.yearTotal':'{y} Total',
    'fmt.dateGroup':'{dow} {d}',
    'empty.noRecords':'No records',
    'stats.noData':'No records in this period!',
    'stats.noDataSub':'Add {type} records<br>for {period}',
    'wpop.moveUp':'↑ Move up','wpop.moveDown':'↓ Move down','wpop.delete':'🗑️ Delete',
    'report.editDone':'✅ Done Editing','report.editStart':'✏️ Edit Widgets',
    'toast.feelOk':'Great choice! 😊','toast.feelRegret':'Try to hold back next time! 💪',
    'report.empty':'No widgets yet.<br>Toggle items below to add them! ✨',
    'surv.thisWeek':'This week','surv.lastWeek':'Last week','surv.weeksAgo':'{n} weeks ago',
    'surv.thisYear':'This year','surv.lastYear':'Last year','surv.yearFmt':'{y}',
    'surv.thisMonth':'This month','surv.lastMonth':'Last month',
    'surv.weekRange':'{m1}/{d1}(Mon) ~ {m2}/{d2}(Sun)',
    'surv.budgetPct':'{pct}% used','surv.noGoal':'No goal set','surv.notSet':'Not set',
    'surv.weekNoGoal':'No goal was set for this week',
    'surv.totalVar':'Total variable expenses (excl. fixed)',
    'surv.danger':'🚨 Budget alert! Spend less!',
    'surv.remaining':'Remaining budget','surv.over':'Over budget',
    'surv.msgNoGoal':'No budget goal for {period} 📭',
    'surv.msgEnterGoal':'Enter a budget goal<br>to see your daily allowance 💡',
    'surv.msgOver':'{period} goal exceeded by <b>{amt}</b> 😰',
    'surv.msgSaved':'Saved <b>{amt}</b> vs {period} budget! 🎉',
    'surv.msgDanger':'Only <b>{daily}</b>/day for <b>{days}</b> days left 🚨',
    'surv.msgOk':'<b>{daily}</b>/day available for <b>{days}</b> days 💰',
    'insight.tagStart':'#StartRecording','insight.tagFirst':'#FirstRecord','insight.tagEqual':'#Balanced',
    'insight.tagThrifty':'#Thrifty','insight.tagSaved':'#SavedUp','insight.tagSaving':'#Saving',
    'insight.tagOverspend':'#Overspending','insight.tagUpBig':'#BigIncrease','insight.tagUpWarn':'#SpendingAlert','insight.tagUpSmall':'#SlightIncrease',
    'insight.noData':'{name}, no expenses this month yet!',
    'insight.noDataSub':'Start recording today ✏️',
    'insight.firstRecord':'{name}, you\'ve spent {amt} so far this month!',
    'insight.firstRecordSub':'No prior data — comparison available next month 📖',
    'insight.equal':'{name}, you spent exactly the same as last month.',
    'insight.equalSub':'Balanced spending pattern.',
    'insight.down30':'{name}, down {pct}% from last month! Savings pro 👑',
    'insight.down30Sub':'Saved {amt}! Keep it up 💚',
    'insight.down10':'{name}, down {pct}% from last month! Great job 👏',
    'insight.downSmall':'{name}, down {pct}% from last month. Nice work!',
    'insight.downSub':'Saved {amt}. Keep going!',
    'insight.up50':'{name}, expenses up {pct}% from last month! Budget alert 😰',
    'insight.up30':'{name}, expenses up {pct}% this month. Wallet getting thin 😅',
    'insight.up10':'{name}, spending is up {pct}% this month. Try to cut back! 💪',
    'insight.upSmall':'{name}, up {pct}% from last month. Still manageable!',
    'insight.upSub':'Spent {amt} more than last month.',
    'export.all':'Total',
    'dow.noData':'No expense data for this month yet.',
    'dow.balanced':'Spending is evenly distributed!',
    'dow.peak':'<b>{day}</b> has the highest spending this month',
    'dow.txCount':'{n} transactions',
    'dow.msg.mon':'Big energy spend to start the week!<br>Fighting the Monday blues with spending? 😂',
    'dow.msg.tue':'Steady weekday spending adding up.<br>Cut small expenses = successful month! 🌱',
    'dow.msg.wed':'Steady weekday spending adding up.<br>Cut small expenses = successful month! 🌱',
    'dow.msg.thu':'Steady weekday spending adding up.<br>Cut small expenses = successful month! 🌱',
    'dow.msg.fri':'TGIF spending surge! The weekend starts here 🍺',
    'dow.msg.sat':'Weekend FLEX alert! 🚨<br>How about enjoying it within budget?',
    'dow.msg.sun':'Sunday is your biggest spend day!<br>Save a little for the week ahead 🏠',
    'mbti.title.eatj':'Foodie','mbti.title.shop':'Trendsetter','mbti.title.yolo':'YOLO Life',
    'mbti.title.move':'On the Move','mbti.title.hlth':'Health First','mbti.title.digi':'Digital Nomad',
    'mbti.title.home':'Home Base','mbti.title.none':'Collecting Data','mbti.title.free':'Free Spirit',
    'mbti.desc.eatj':'Food is life! Always hunting for the next great meal. Your wallet only opens for food!',
    'mbti.desc.shop':'Shopping is therapy! Your cart fills up in no time. Daily shopping tour in progress?',
    'mbti.desc.yolo':'Investing in experiences without hesitation! You only live once. Memories are the best investment!',
    'mbti.desc.move':'Always on the move! Active and busy type. Still running today?',
    'mbti.desc.hlth':'Health is top priority! No holding back on body care. Your health is your true wealth!',
    'mbti.desc.digi':'Digital life master! Can\'t live without subscription services. Still streaming today?',
    'mbti.desc.home':'Home is best! Seriously into decorating your own space. Home café mode today?',
    'mbti.desc.none':'Add expense records this month to analyze your spending MBTI!',
    'mbti.desc.free':'Free-spirited! Flexible type that spends evenly across various areas.',
    'mbti.budget.great':'A perfect spender with great saving skills! 💪',
    'mbti.budget.ok':'A spender with balanced sense 👍',
    'mbti.budget.warn':'Almost at the limit! Try to save a little more 😅',
    'mbti.budget.over':'Over budget! Let\'s go saving mode next month 😰',
    'fx.weekly':'Every {dow}','fx.monthly':'Day {d} of every month','fx.yearly':'Every {m}/{d}',
    'fx.pastDateMonth':'This date has passed! Record it for this month ({label}) now?',
    'fx.pastDateYear':'This date has passed! Record it for this year ({m}/{d}) now?',
    'toast.coming':'Coming soon','toast.langSet':'Language updated ✓','toast.fontChanged':'Font size changed',
    'toast.catDeleted':'Category deleted','toast.catEmptyName':'Enter category name',
    'toast.catDuplicate':'Category already exists','toast.catAdded':'Category added 🏷️',
    'toast.payDeleted':'Payment method deleted','toast.payEmptyName':'Enter payment method name',
    'toast.payDuplicate':'Payment method already exists','toast.payAdded':'Payment method added',
    'toast.fxEmptyName':'Enter item name','toast.fxEmptyAmt':'Enter amount',
    'toast.fxSaved':'Recorded on calendar! 📌','toast.fxAdded':'Fixed item added 📌',
    'toast.fxAddFail':'Failed to add: {msg}','toast.fxError':'An error occurred. Please try again.',
    'toast.fxDeleted':'Fixed item deleted','toast.deleted':'Deleted',
    'toast.notifSet':'Daily reminder set for {time} 🔔',
    'toast.noNotifSupport':'Notifications not supported in this browser',
    'toast.backupDown':'Backup downloaded ✅','toast.backupError':'Cannot read file: {err}',
    'toast.csvDown':'CSV downloaded 📊',
    'toast.allDeleted':'All records deleted 🗑️','toast.deleteFail':'Deletion failed: {msg}',
    'toast.serverError':'Server error occurred',
    'toast.inquirySent':'Inquiry submitted ✓','toast.inquiryEmpty':'Enter title and content',
    'fx.loadingList':'Loading...','fx.emptyList':'No fixed items yet<br>Add one below',
    'confirm.deleteLinked':'"{name}" has {count} record(s) this month. Delete them too?\n\nCancel to remove only the fixed item setting.',
    'opt.selectCat':'Select category',
    'fontsize.normal':'Normal','fontsize.large':'Large','fontsize.xlarge':'X-Large',
    'lang.ko':'Korean','lang.en':'English','lang.ja':'Japanese','lang.zh':'Chinese','lang.es':'Spanish',
    'alert.dateRange':'Please select start and end dates.','alert.dateOrder':'End date cannot be before start date.',
    'alert.enterAmt':'Please enter an amount.','alert.enterDate':'Please select a date.',
    'ico.food':'Food','ico.shopping':'Shopping','ico.transport':'Transport','ico.car':'Car',
    'ico.housing':'Housing','ico.health':'Health','ico.telecom':'Telecom','ico.culture':'Culture',
    'ico.salary':'Salary','ico.gift':'Gift','ico.travel':'Travel','ico.cafe':'Cafe',
    'ico.alcohol':'Alcohol','ico.education':'Education','ico.sports':'Sports','ico.pet':'Pet',
    'ico.repair':'Repair','ico.electronics':'Electronics','ico.clothing':'Clothing','ico.child':'Child',
    'ico.grocery':'Grocery','ico.beauty':'Beauty','ico.social':'Social','ico.savings':'Savings',
    'ico.donation':'Donation','ico.smoking':'Smoking','ico.otherIncome':'Other Income','ico.other':'Other',
    'ico.credit':'Credit Card','ico.cash':'Cash','ico.transfer':'Bank Transfer','ico.wallet':'Wallet',
    'ico.easypay':'Easy Pay','ico.qr':'QR Pay','ico.pay':'Pay','ico.points':'Points',
    'ico.voucher':'Voucher','ico.bank':'Bank','ico.piggyBank':'Piggy Bank','ico.coins':'Coins',
    'ico.autoTransfer':'Auto Transfer',
    'ico.allowance':'Allowance','ico.invest':'Investment','ico.interest':'Interest','ico.save':'Saving',
    'ico.sideIncome':'Side Income','ico.refund':'Refund','ico.bonus':'Bonus',
    'ico.regularIncome':'Regular Income','ico.reward':'Reward',
  },
  ja: {
    'app.title':'マイ家計簿','tab.ledger':'家計簿','tab.stats':'統計','tab.report':'分析','tab.me':'マイ',
    'grid.settings':'設定','grid.upgrade':'アップグレード','grid.help':'ヘルプ','grid.data':'データ','grid.contact':'お問合せ',
    'page.appSettings':'アプリ設定','page.data':'データ',
    'section.records':'記録管理','section.environment':'アプリ環境','section.dataManagement':'データ管理',
    'row.fixedExpense':'固定支出設定','row.categories':'カテゴリ編集','row.payments':'支払方法編集',
    'row.currency':'デフォルト通貨','row.notifications':'プッシュ通知','row.theme':'テーマ','row.fontSize':'フォントサイズ','row.language':'言語',
    'row.backup':'バックアップ・復元','row.export':'Excelエクスポート','row.deleteAll':'全データ削除',
    'lbl.income':'収入','lbl.expense':'支出','lbl.balance':'残高','lbl.category':'カテゴリ','lbl.payment':'支払方法',
    'lbl.type':'種類','lbl.amount':'金額','lbl.content':'内容','lbl.date':'日付','lbl.other':'その他','lbl.cntFmt':'{n}件','lbl.user':'ユーザー',
    'day.sun':'日','day.mon':'月','day.tue':'火','day.wed':'水','day.thu':'木','day.fri':'金','day.sat':'土',
    'period.week':'週','period.month':'月','period.year':'年',
    'stats.rankTitle':'カテゴリ別支出ランキング',
    'form.amount':'金額','form.desc':'内容 / メモ','form.descPh':'例）コンビニ、バス','form.date':'日付',
    'form.catName':'カテゴリ名','form.payName':'支払方法名','form.catSelect':'選択',
    'btn.save':'保存','btn.add':'追加','btn.close':'閉じる',
    'btn.detail':'詳細','btn.edit':'編集','btn.copy':'コピー','btn.delete':'削除',
    'lbl.detail':'詳細情報',
    'form.amount':'金額 (円)','form.amountPh':'0',
    'form.category':'カテゴリ','form.catSelect':'選択',
    'form.payment':'支払方法',
    'form.desc':'内容 / メモ','form.descPh':'例) コンビニ、バス','form.date':'日付',
    'form.catName':'カテゴリ名','form.payName':'支払方法名',
    'search.ph':'内容・カテゴリ・金額で検索...','search.empty':'検索ワードを入力してください',
    'modal.addTx':'記録を追加','modal.editTx':'記録を編集',
    'section.txHistory':'取引履歴',
    'ledger.empty':'今月の履歴はありません<br><b>＋</b> ボタンで追加してください！',
    'widget.champ':'最高支出','widget.dow':'曜日別消費パターン','widget.survival':'予算目標',
    'widget.top3':'カテゴリ TOP 3','widget.mbti':'消費MBTI',
    'widget.feelOk':'満足','widget.feelRegret':'もったいない',
    'widget.survNotSet':'未設定','widget.survUnit':'円','widget.survTotal':'変動支出合計','widget.calculating':'計算中...',
    'stats.totalLabel':'総 {type}','stats.rankFmt':'{group}別{type}ランキング',
    'report.monthExpense':'{m}月支出','report.noExpense':'今月の支出履歴はありません',
    'report.champPct':'今月の総支出の{pct}がこの1回の決済から！',
    'report.addWidget':'項目追加',
    'wdef.insight':'月間サマリー','wdef.champion':'最高支出','wdef.dayofweek':'曜日別消費',
    'wdef.survival':'予算目標','wdef.mbti':'消費MBTI','wdef.top3cats':'カテゴリ TOP 3',
    'me.streak':'🔥 {n}日連続記録','me.streakZero':'今日から記録を始めましょう！',
    'me.monthRecord':'今月の記録','me.streakDays':'連続記録日',
    'me.notLoggedIn':'未ログイン','me.syncInfo':'ログインするとサーバーに同期されます','me.loginBtn':'ログイン / 会員登録',
    'dow.noExpense':'支出なし',
    'badge.guard':'資産守護隊 🛡️','badge.explorer':'節約探検家 🧭','badge.sprout':'記録の芽 🌱',
    'cat.dining':'食費','cat.transport':'交通','cat.shopping':'ショッピング','cat.medical':'医療',
    'cat.culture':'文化','cat.telecom':'通信','cat.housing':'住居','cat.other':'その他',
    'cat.salary':'給与','cat.allowance':'お小遣い','cat.otherIncome':'その他収入',
    'pay.cash':'現金','pay.credit':'クレジットカード','pay.debit':'デビットカード','pay.transfer':'銀行振込',
    'pay.kakao':'KakaoPay','pay.naver':'NaverPay','pay.toss':'Toss','pay.other':'その他','pay.auto':'自動',
    'stats.yearTotal':'{y}年全体',
    'fmt.dateGroup':'{d}日 ({dow})',
    'empty.noRecords':'記録がありません',
    'stats.noData':'この期間に記録はありません！',
    'stats.noDataSub':'{period}の<br>{type}記録を追加してください',
    'wpop.moveUp':'↑ 上に移動','wpop.moveDown':'↓ 下に移動','wpop.delete':'🗑️ 削除',
    'report.editDone':'✅ 編集完了','report.editStart':'✏️ 分析項目を編集',
    'toast.feelOk':'良い選択でした！ 😊','toast.feelRegret':'次は少し我慢してみましょう！ 💪',
    'report.empty':'まだ分析項目がありません。<br>トグルをオンにして追加してください！ ✨',
    'surv.thisWeek':'今週','surv.lastWeek':'先週','surv.weeksAgo':'{n}週前',
    'surv.thisYear':'今年','surv.lastYear':'去年','surv.yearFmt':'{y}年',
    'surv.thisMonth':'今月','surv.lastMonth':'先月',
    'surv.weekRange':'{m1}/{d1}(月) ~ {m2}/{d2}(日)',
    'surv.budgetPct':'支出 {pct}%','surv.noGoal':'目標未設定','surv.notSet':'未設定',
    'surv.weekNoGoal':'この週は目標が設定されていませんでした',
    'surv.totalVar':'変動支出合計（固定費除く）',
    'surv.danger':'🚨 予算危険！もう少し節約しましょう',
    'surv.remaining':'残り予算','surv.over':'超過支出',
    'surv.msgNoGoal':'{period}の予算目標はありませんでした 📭',
    'surv.msgEnterGoal':'予算目標を入力すると<br>1日の利用可能額を表示します 💡',
    'surv.msgOver':'{period}の目標を<b>{amt}</b>超過しました 😰',
    'surv.msgSaved':'{period}の予算<b>{amt}</b>を節約しました！ 🎉',
    'surv.msgDanger':'残り<b>{days}日</b>で毎日<b>{daily}</b>だけ使えます 🚨',
    'surv.msgOk':'残り<b>{days}日</b>で毎日<b>{daily}</b>使用可能です！ 💰',
    'insight.tagStart':'#記録開始','insight.tagFirst':'#今月初記録','insight.tagEqual':'#バランス維持',
    'insight.tagThrifty':'#節約上手','insight.tagSaved':'#節約成功','insight.tagSaving':'#節約中',
    'insight.tagOverspend':'#過消費警報','insight.tagUpBig':'#支出急増','insight.tagUpWarn':'#支出注意','insight.tagUpSmall':'#微増',
    'insight.noData':'{name}さん、今月はまだ支出がありません！',
    'insight.noDataSub':'今日から記録を始めましょう ✏️',
    'insight.firstRecord':'{name}さん、今月は現在まで合計{amt}を使っています！',
    'insight.firstRecordSub':'前月データがないため、増減は来月から確認できます 📖',
    'insight.equal':'{name}さん、先月とまったく同じ金額を使いました。',
    'insight.equalSub':'バランスの取れた消費パターンですね。',
    'insight.down30':'{name}さん、先月より{pct}%減りました！節約の達人ですね 👑',
    'insight.down30Sub':'{amt}節約！この調子で続けましょう 💚',
    'insight.down10':'{name}さん、先月より{pct}%節約しました！すごいです 👏',
    'insight.downSmall':'{name}さん、先月より{pct}%減りました。よくやっています！',
    'insight.downSub':'{amt}節約しました。この調子で！',
    'insight.up50':'{name}さん、支出が先月より{pct}%急増しました！緊急事態です 😰',
    'insight.up30':'{name}さん、今月の支出が先月より{pct}%増えました 😅',
    'insight.up10':'{name}さん、今月は先月より{pct}%多く使いました。少し調整しましょう！ 💪',
    'insight.upSmall':'{name}さん、先月より{pct}%微増しました。まだ大丈夫です！',
    'insight.upSub':'先月より{amt}多く支出しました。',
    'export.all':'全件',
    'dow.noData':'今月の支出データはまだありません。',
    'dow.balanced':'支出が均等に分布しています！',
    'dow.peak':'今月は<b>{day}曜日</b>の支出が最も多いです',
    'dow.txCount':'合計{n}件',
    'dow.msg.mon':'週の始まりから消費エネルギー全開！<br>月曜病を消費で乗り越えましたか？ 😂',
    'dow.msg.tue':'平日の堅実な消費が積み重なっています。<br>ちょこちょこ節約で今月は成功！ 🌱',
    'dow.msg.wed':'平日の堅実な消費が積み重なっています。<br>ちょこちょこ節約で今月は成功！ 🌱',
    'dow.msg.thu':'平日の堅実な消費が積み重なっています。<br>ちょこちょこ節約で今月は成功！ 🌱',
    'dow.msg.fri':'花金の爆発！週末の始まりと共に支出も爆発 🍺',
    'dow.msg.sat':'週末FLEX注意報！🚨<br>予算内で楽しんでみましょうか？',
    'dow.msg.sun':'日曜日の支出が最大！<br>来週のために少し節約しましょう 🏠',
    'mbti.title.eatj':'グルメ型','mbti.title.shop':'トレンドセッター型','mbti.title.yolo':'YOLO型',
    'mbti.title.move':'モバイル型','mbti.title.hlth':'健康第一型','mbti.title.digi':'デジタルノマド型',
    'mbti.title.home':'ホームベース型','mbti.title.none':'データ収集中','mbti.title.free':'自由奔放型',
    'mbti.desc.eatj':'食べることが一番！今日もグルメ探索中。お財布が開くのはグルメの前だけ！',
    'mbti.desc.shop':'ショッピングは癒し！気づけばカートがいっぱい。今日も買い物ツアー中？',
    'mbti.desc.yolo':'経験に惜しみなく投資！人生は一度きり。思い出が最高の投資！',
    'mbti.desc.move':'常にどこかへ移動中！活動的なタイプ。今日も走ってますか？',
    'mbti.desc.hlth':'健康が最優先！体のケアに投資を惜しまない。健康こそ本物の財産！',
    'mbti.desc.digi':'デジタルライフの達人！サブスクなしでは生きられないタイプ。今日もストリーミング中？',
    'mbti.desc.home':'家が一番！自分の空間を飾ることに熱心なタイプ。今日もホームカフェ中？',
    'mbti.desc.none':'今月の支出を追加すると消費MBTIが分析されます！',
    'mbti.desc.free':'決まったパターンなく自由に！様々な場所に均等に支出する柔軟なタイプ。',
    'mbti.budget.great':'節約能力も備えた完璧な消費者！ 💪',
    'mbti.budget.ok':'適度なバランス感覚を持つ消費者 👍',
    'mbti.budget.warn':'限界ライン！もう少し節約しましょう 😅',
    'mbti.budget.over':'予算超過！来月は節約モードで行こう 😰',
    'fx.weekly':'毎週{dow}曜日','fx.monthly':'毎月{d}日','fx.yearly':'毎年{m}月{d}日',
    'fx.pastDateMonth':'すでに過ぎた日付です！今月({label})の記録にも今すぐ記録しますか？',
    'fx.pastDateYear':'すでに過ぎた日付です！今年({m}月{d}日)の記録にも今すぐ記録しますか？',
    'toast.coming':'準備中です','toast.langSet':'言語が変更されました ✓','toast.fontChanged':'フォントサイズが変更されました',
    'toast.catDeleted':'カテゴリが削除されました','toast.catEmptyName':'カテゴリ名を入力してください',
    'toast.catDuplicate':'既に存在するカテゴリです','toast.catAdded':'カテゴリが追加されました 🏷️',
    'toast.payDeleted':'支払方法が削除されました','toast.payEmptyName':'支払方法名を入力してください',
    'toast.payDuplicate':'既に存在する支払方法です','toast.payAdded':'支払方法が追加されました',
    'toast.fxEmptyName':'項目名を入力してください','toast.fxEmptyAmt':'金額を入力してください',
    'toast.fxSaved':'カレンダーに記録されました！ 📌','toast.fxAdded':'固定項目が追加されました 📌',
    'toast.fxAddFail':'追加失敗: {msg}','toast.fxError':'エラーが発生しました。再試行してください。',
    'toast.fxDeleted':'固定項目が削除されました','toast.deleted':'削除されました',
    'toast.notifSet':'毎日{time}にリマインドをお送りします 🔔',
    'toast.noNotifSupport':'このブラウザは通知をサポートしていません',
    'toast.backupDown':'バックアップをダウンロードしました ✅','toast.backupError':'ファイルを読み込めません: {err}',
    'toast.csvDown':'CSVをダウンロードしました 📊',
    'toast.allDeleted':'全データが削除されました 🗑️','toast.deleteFail':'削除に失敗しました: {msg}',
    'toast.serverError':'サーバーエラーが発生しました',
    'toast.inquirySent':'お問合せを受け付けました ✓','toast.inquiryEmpty':'タイトルと内容を入力してください',
    'fx.loadingList':'読み込み中...','fx.emptyList':'固定項目がまだありません<br>下で追加してください',
    'confirm.deleteLinked':'今月に記録された"{name}"の{count}件も一緒に削除しますか？\n\nキャンセルすると固定設定のみ削除され記録は保持されます。',
    'opt.selectCat':'カテゴリを選択',
    'fontsize.normal':'普通','fontsize.large':'大きく','fontsize.xlarge':'とても大きく',
    'lang.ko':'韓国語','lang.en':'英語','lang.ja':'日本語','lang.zh':'中国語','lang.es':'スペイン語',
    'alert.dateRange':'開始日と終了日を選択してください。','alert.dateOrder':'終了日は開始日より前にできません。',
    'alert.enterAmt':'金額を入力してください。','alert.enterDate':'日付を選択してください。',
    'ico.food':'食事','ico.shopping':'ショッピング','ico.transport':'交通','ico.car':'車',
    'ico.housing':'住居','ico.health':'健康','ico.telecom':'通信','ico.culture':'文化',
    'ico.salary':'給与','ico.gift':'贈り物','ico.travel':'旅行','ico.cafe':'カフェ',
    'ico.alcohol':'お酒','ico.education':'教育','ico.sports':'スポーツ','ico.pet':'ペット',
    'ico.repair':'修理','ico.electronics':'電子機器','ico.clothing':'衣類','ico.child':'育児',
    'ico.grocery':'食材','ico.beauty':'美容','ico.social':'交際','ico.savings':'貯蓄',
    'ico.donation':'寄付','ico.smoking':'喫煙','ico.otherIncome':'その他収入','ico.other':'その他',
    'ico.credit':'クレジットカード','ico.cash':'現金','ico.transfer':'振込','ico.wallet':'財布',
    'ico.easypay':'簡単決済','ico.qr':'QR決済','ico.pay':'ペイ','ico.points':'ポイント',
    'ico.voucher':'商品券','ico.bank':'銀行','ico.piggyBank':'貯金箱','ico.coins':'小銭',
    'ico.autoTransfer':'自動振替',
    'ico.allowance':'お小遣い','ico.invest':'投資','ico.interest':'利子','ico.save':'貯金',
    'ico.sideIncome':'副収入','ico.refund':'還付','ico.bonus':'ボーナス',
    'ico.regularIncome':'定期収入','ico.reward':'報奨',
  },
  zh: {
    'app.title':'我的账本','tab.ledger':'账本','tab.stats':'统计','tab.report':'分析','tab.me':'我',
    'grid.settings':'设置','grid.upgrade':'升级','grid.help':'帮助','grid.data':'数据','grid.contact':'联系我们',
    'page.appSettings':'应用设置','page.data':'数据',
    'section.records':'记录管理','section.environment':'应用环境','section.dataManagement':'数据管理',
    'row.fixedExpense':'固定支出设置','row.categories':'编辑分类','row.payments':'编辑支付方式',
    'row.currency':'默认货币','row.notifications':'推送通知','row.theme':'主题','row.fontSize':'字体大小','row.language':'语言',
    'row.backup':'备份与恢复','row.export':'导出Excel','row.deleteAll':'删除全部记录',
    'lbl.income':'收入','lbl.expense':'支出','lbl.balance':'余额','lbl.category':'分类','lbl.payment':'支付方式',
    'lbl.type':'类型','lbl.amount':'金额','lbl.content':'内容','lbl.date':'日期','lbl.other':'其他','lbl.cntFmt':'{n}条','lbl.user':'用户',
    'day.sun':'日','day.mon':'一','day.tue':'二','day.wed':'三','day.thu':'四','day.fri':'五','day.sat':'六',
    'period.week':'周','period.month':'月','period.year':'年',
    'stats.rankTitle':'按分类支出排名',
    'form.amount':'金额','form.desc':'内容 / 备注','form.descPh':'例：便利店、公交','form.date':'日期',
    'form.catName':'分类名称','form.payName':'支付方式名称','form.catSelect':'选择',
    'btn.save':'保存','btn.add':'添加','btn.close':'关闭',
    'btn.detail':'详情','btn.edit':'编辑','btn.copy':'复制','btn.delete':'删除',
    'lbl.detail':'详细信息',
    'form.amount':'金额','form.amountPh':'0',
    'form.category':'类别','form.catSelect':'选择',
    'form.payment':'支付方式',
    'form.desc':'内容 / 备注','form.descPh':'例) 便利店、公交车','form.date':'日期',
    'form.catName':'类别名称','form.payName':'支付方式名称',
    'search.ph':'按内容、分类、金额搜索...','search.empty':'请输入搜索词',
    'modal.addTx':'添加记录','modal.editTx':'编辑记录',
    'section.txHistory':'交易记录',
    'ledger.empty':'本月暂无记录<br>点击 <b>＋</b> 添加！',
    'widget.champ':'最高支出','widget.dow':'按星期消费分析','widget.survival':'预算目标',
    'widget.top3':'分类 TOP 3','widget.mbti':'消费MBTI',
    'widget.feelOk':'满意','widget.feelRegret':'后悔',
    'widget.survNotSet':'未设置','widget.survUnit':'','widget.survTotal':'变动支出合计','widget.calculating':'计算中...',
    'stats.totalLabel':'总{type}','stats.rankFmt':'按{group}的{type}排名',
    'report.monthExpense':'{m}月支出','report.noExpense':'本月暂无支出记录',
    'report.champPct':'本月总支出的{pct}来自这一笔！',
    'report.addWidget':'添加项目',
    'wdef.insight':'月度摘要','wdef.champion':'最高支出','wdef.dayofweek':'按星期消费',
    'wdef.survival':'预算目标','wdef.mbti':'消费MBTI','wdef.top3cats':'分类 TOP 3',
    'me.streak':'🔥 连续记录 {n} 天','me.streakZero':'快来开始记录吧！',
    'me.monthRecord':'本月记录','me.streakDays':'连续记录天数',
    'me.notLoggedIn':'未登录','me.syncInfo':'登录后同步到服务器','me.loginBtn':'登录 / 注册',
    'dow.noExpense':'无支出',
    'badge.guard':'资产守卫 🛡️','badge.explorer':'节俭探索者 🧭','badge.sprout':'记录新芽 🌱',
    'cat.dining':'餐饮','cat.transport':'交通','cat.shopping':'购物','cat.medical':'医疗',
    'cat.culture':'文化','cat.telecom':'通讯','cat.housing':'住房','cat.other':'其他',
    'cat.salary':'工资','cat.allowance':'零花钱','cat.otherIncome':'其他收入',
    'pay.cash':'现金','pay.credit':'信用卡','pay.debit':'借记卡','pay.transfer':'银行转账',
    'pay.kakao':'KakaoPay','pay.naver':'NaverPay','pay.toss':'Toss','pay.other':'其他','pay.auto':'自动',
    'stats.yearTotal':'{y}年全年',
    'fmt.dateGroup':'{d}日 ({dow})',
    'empty.noRecords':'暂无记录',
    'stats.noData':'此期间没有记录！',
    'stats.noDataSub':'请添加{period}的<br>{type}记录',
    'wpop.moveUp':'↑ 上移','wpop.moveDown':'↓ 下移','wpop.delete':'🗑️ 删除',
    'report.editDone':'✅ 完成编辑','report.editStart':'✏️ 编辑分析项目',
    'toast.feelOk':'好的选择！ 😊','toast.feelRegret':'下次稍微忍一忍！ 💪',
    'report.empty':'还没有分析项目。<br>打开下方开关来添加吧！ ✨',
    'surv.thisWeek':'本周','surv.lastWeek':'上周','surv.weeksAgo':'{n}周前',
    'surv.thisYear':'今年','surv.lastYear':'去年','surv.yearFmt':'{y}年',
    'surv.thisMonth':'本月','surv.lastMonth':'上月',
    'surv.weekRange':'{m1}/{d1}(周一) ~ {m2}/{d2}(周日)',
    'surv.budgetPct':'支出 {pct}%','surv.noGoal':'未设目标','surv.notSet':'未设置',
    'surv.weekNoGoal':'本周没有设置目标',
    'surv.totalVar':'变动支出合计（不含固定费）',
    'surv.danger':'🚨 预算警报！再省一省吧',
    'surv.remaining':'剩余预算','surv.over':'超支',
    'surv.msgNoGoal':'{period}没有预算目标 📭',
    'surv.msgEnterGoal':'输入预算目标<br>即可查看每日可用金额 💡',
    'surv.msgOver':'{period}目标超出<b>{amt}</b> 😰',
    'surv.msgSaved':'{period}节省了<b>{amt}</b>！ 🎉',
    'surv.msgDanger':'剩余<b>{days}天</b>每天只能花<b>{daily}</b> 🚨',
    'surv.msgOk':'剩余<b>{days}天</b>每天可花<b>{daily}</b>！ 💰',
    'insight.tagStart':'#开始记录','insight.tagFirst':'#本月首记','insight.tagEqual':'#收支均衡',
    'insight.tagThrifty':'#精打细算','insight.tagSaved':'#节省成功','insight.tagSaving':'#节省中',
    'insight.tagOverspend':'#超支警报','insight.tagUpBig':'#支出暴增','insight.tagUpWarn':'#支出注意','insight.tagUpSmall':'#小幅增加',
    'insight.noData':'{name}，本月还没有支出！',
    'insight.noDataSub':'从今天开始记录吧 ✏️',
    'insight.firstRecord':'{name}，本月目前共花费了{amt}！',
    'insight.firstRecordSub':'没有上月数据，增减情况下月起可查看 📖',
    'insight.equal':'{name}，和上月花费完全一样。',
    'insight.equalSub':'均衡的消费模式。',
    'insight.down30':'{name}，比上月减少了{pct}%！真是节约达人 👑',
    'insight.down30Sub':'节省了{amt}！继续保持 💚',
    'insight.down10':'{name}，比上月节省了{pct}%！太棒了 👏',
    'insight.downSmall':'{name}，比上月减少了{pct}%。干得好！',
    'insight.downSub':'节省了{amt}。继续加油！',
    'insight.up50':'{name}，支出比上月暴增{pct}%！钱包告急 😰',
    'insight.up30':'{name}，本月支出比上月增加了{pct}%，花销明显增大 😅',
    'insight.up10':'{name}，本月比上月多花了{pct}%，稍微控制一下吧！ 💪',
    'insight.upSmall':'{name}，比上月小幅增加{pct}%，还算可以！',
    'insight.upSub':'比上月多花了{amt}。',
    'export.all':'共',
    'dow.noData':'本月还没有支出数据。',
    'dow.balanced':'支出分布均匀！',
    'dow.peak':'本月<b>{day}</b>的支出最多',
    'dow.txCount':'共{n}笔',
    'dow.msg.mon':'周一就消耗了大量能量！<br>用消费对抗周一综合症？ 😂',
    'dow.msg.tue':'工作日消费稳定累积。<br>减少小支出就是本月的成功！ 🌱',
    'dow.msg.wed':'工作日消费稳定累积。<br>减少小支出就是本月的成功！ 🌱',
    'dow.msg.thu':'工作日消费稳定累积。<br>减少小支出就是本月的成功！ 🌱',
    'dow.msg.fri':'周五大爆发！周末开始了 🍺',
    'dow.msg.sat':'周末FLEX警报！🚨<br>在预算内享受如何？',
    'dow.msg.sun':'周日支出最多！<br>为新的一周省一点吧 🏠',
    'mbti.title.eatj':'美食家型','mbti.title.shop':'潮流达人型','mbti.title.yolo':'YOLO型',
    'mbti.title.move':'行动派型','mbti.title.hlth':'健康至上型','mbti.title.digi':'数字游民型',
    'mbti.title.home':'宅家型','mbti.title.none':'数据收集中','mbti.title.free':'自由型',
    'mbti.desc.eatj':'民以食为天！今天也在寻找美食。只有美食才能打开钱包！',
    'mbti.desc.shop':'购物是治愈！转眼间购物车就满了。今天也在购物中？',
    'mbti.desc.yolo':'不惜投资体验！人生只有一次。回忆是最好的投资！',
    'mbti.desc.move':'总是在移动中！活跃型。今天还在奔跑吗？',
    'mbti.desc.hlth':'健康第一！不惜投资身体管理。健康才是真正的财富！',
    'mbti.desc.digi':'数字生活达人！没有订阅服务就活不了。今天还在流媒体中？',
    'mbti.desc.home':'家是最好的！热衷于装饰自己空间的类型。今天也在家咖啡模式？',
    'mbti.desc.none':'添加本月支出记录即可分析你的消费MBTI！',
    'mbti.desc.free':'不按固定模式自由消费！均匀分布在各处的灵活型。',
    'mbti.budget.great':'具备节约能力的完美消费者！ 💪',
    'mbti.budget.ok':'有适度平衡感的消费者 👍',
    'mbti.budget.warn':'几乎到达极限！再省一点吧 😅',
    'mbti.budget.over':'超支！下个月省钱模式出发 😰',
    'fx.weekly':'每周{dow}','fx.monthly':'每月{d}日','fx.yearly':'每年{m}月{d}日',
    'fx.pastDateMonth':'日期已过！现在记录到本月({label})的账单中吗？',
    'fx.pastDateYear':'日期已过！现在记录到今年({m}月{d}日)的账单中吗？',
    'toast.coming':'敬请期待','toast.langSet':'语言已更改 ✓','toast.fontChanged':'字体大小已更改',
    'toast.catDeleted':'分类已删除','toast.catEmptyName':'请输入分类名称',
    'toast.catDuplicate':'分类已存在','toast.catAdded':'分类已添加 🏷️',
    'toast.payDeleted':'支付方式已删除','toast.payEmptyName':'请输入支付方式名称',
    'toast.payDuplicate':'支付方式已存在','toast.payAdded':'支付方式已添加',
    'toast.fxEmptyName':'请输入项目名称','toast.fxEmptyAmt':'请输入金额',
    'toast.fxSaved':'已记录到日历！ 📌','toast.fxAdded':'固定项目已添加 📌',
    'toast.fxAddFail':'添加失败: {msg}','toast.fxError':'发生错误，请重试。',
    'toast.fxDeleted':'固定项目已删除','toast.deleted':'已删除',
    'toast.notifSet':'每天{time}提醒您 🔔',
    'toast.noNotifSupport':'此浏览器不支持通知',
    'toast.backupDown':'备份已下载 ✅','toast.backupError':'无法读取文件: {err}',
    'toast.csvDown':'CSV已下载 📊',
    'toast.allDeleted':'所有记录已删除 🗑️','toast.deleteFail':'删除失败: {msg}',
    'toast.serverError':'服务器发生错误',
    'toast.inquirySent':'问题已提交 ✓','toast.inquiryEmpty':'请输入标题和内容',
    'fx.loadingList':'加载中...','fx.emptyList':'还没有固定项目<br>在下方添加',
    'confirm.deleteLinked':'本月已有"{name}"的{count}条记录，一并删除吗？\n\n取消则只删除固定设置，记录保留。',
    'opt.selectCat':'选择分类',
    'fontsize.normal':'普通','fontsize.large':'大','fontsize.xlarge':'超大',
    'lang.ko':'韩语','lang.en':'英语','lang.ja':'日语','lang.zh':'中文','lang.es':'西班牙语',
    'alert.dateRange':'请选择开始日期和结束日期。','alert.dateOrder':'结束日期不能早于开始日期。',
    'alert.enterAmt':'请输入金额。','alert.enterDate':'请选择日期。',
    'ico.food':'餐饮','ico.shopping':'购物','ico.transport':'交通','ico.car':'汽车',
    'ico.housing':'住房','ico.health':'健康','ico.telecom':'通讯','ico.culture':'文化',
    'ico.salary':'工资','ico.gift':'礼物','ico.travel':'旅行','ico.cafe':'咖啡',
    'ico.alcohol':'酒水','ico.education':'教育','ico.sports':'运动','ico.pet':'宠物',
    'ico.repair':'维修','ico.electronics':'电子','ico.clothing':'服装','ico.child':'育儿',
    'ico.grocery':'蔬菜','ico.beauty':'美容','ico.social':'社交','ico.savings':'储蓄',
    'ico.donation':'捐款','ico.smoking':'烟草','ico.otherIncome':'其他收入','ico.other':'其他',
    'ico.credit':'信用卡','ico.cash':'现金','ico.transfer':'转账','ico.wallet':'钱包',
    'ico.easypay':'便捷支付','ico.qr':'二维码','ico.pay':'支付','ico.points':'积分',
    'ico.voucher':'礼券','ico.bank':'银行','ico.piggyBank':'存钱罐','ico.coins':'零钱',
    'ico.autoTransfer':'自动转账',
    'ico.allowance':'零花钱','ico.invest':'投资','ico.interest':'利息','ico.save':'存款',
    'ico.sideIncome':'副业','ico.refund':'退款','ico.bonus':'奖金',
    'ico.regularIncome':'定期收入','ico.reward':'奖励',
  },
  es: {
    'app.title':'Mi Libreta','tab.ledger':'Libro','tab.stats':'Estadísticas','tab.report':'Análisis','tab.me':'Yo',
    'grid.settings':'Ajustes','grid.upgrade':'Premium','grid.help':'Ayuda','grid.data':'Datos','grid.contact':'Contacto',
    'page.appSettings':'Ajustes','page.data':'Datos',
    'section.records':'Gestión de registros','section.environment':'Entorno','section.dataManagement':'Gestión de datos',
    'row.fixedExpense':'Gastos fijos','row.categories':'Editar categorías','row.payments':'Editar pagos',
    'row.currency':'Moneda predeterminada','row.notifications':'Notificaciones','row.theme':'Tema','row.fontSize':'Tamaño de fuente','row.language':'Idioma',
    'row.backup':'Copia de seguridad','row.export':'Exportar a Excel','row.deleteAll':'Eliminar todo',
    'lbl.income':'Ingresos','lbl.expense':'Gastos','lbl.balance':'Saldo','lbl.category':'Categoría','lbl.payment':'Pago',
    'lbl.type':'Tipo','lbl.amount':'Importe','lbl.content':'Nota','lbl.date':'Fecha','lbl.other':'Otros','lbl.cntFmt':'{n} registros','lbl.user':'Usuario',
    'day.sun':'Dom','day.mon':'Lun','day.tue':'Mar','day.wed':'Mié','day.thu':'Jue','day.fri':'Vie','day.sat':'Sáb',
    'period.week':'Sem','period.month':'Mes','period.year':'Año',
    'stats.rankTitle':'Ranking de gastos por categoría',
    'form.amount':'Importe','form.desc':'Nota / Memo','form.descPh':'Ej: Café, Autobús','form.date':'Fecha',
    'form.catName':'Nombre de categoría','form.payName':'Nombre de pago','form.catSelect':'Seleccionar',
    'btn.save':'Guardar','btn.add':'Añadir','btn.close':'Cerrar',
    'btn.detail':'Detalle','btn.edit':'Editar','btn.copy':'Copiar','btn.delete':'Eliminar',
    'lbl.detail':'Detalle',
    'form.amount':'Monto','form.amountPh':'0',
    'form.category':'Categoría','form.catSelect':'Seleccionar',
    'form.payment':'Método de pago',
    'form.desc':'Nota / Memo','form.descPh':'ej. Café, Bus','form.date':'Fecha',
    'form.catName':'Nombre de categoría','form.payName':'Nombre de método de pago',
    'search.ph':'Buscar por nota, categoría, importe...','search.empty':'Introduce un término de búsqueda',
    'modal.addTx':'Añadir registro','modal.editTx':'Editar registro',
    'section.txHistory':'Transacciones',
    'ledger.empty':'Sin registros este mes<br>Toca <b>＋</b> para añadir uno!',
    'widget.champ':'Mayor gasto','widget.dow':'Gastos por día de semana','widget.survival':'Presupuesto',
    'widget.top3':'Categoría TOP 3','widget.mbti':'MBTI de consumo',
    'widget.feelOk':'Satisfecho','widget.feelRegret':'Me arrepiento',
    'widget.survNotSet':'Sin definir','widget.survUnit':'','widget.survTotal':'Total gastos variables','widget.calculating':'Calculando...',
    'stats.totalLabel':'Total {type}','stats.rankFmt':'{type} por {group}',
    'report.monthExpense':'Mes {m} Gasto','report.noExpense':'Sin gastos este mes',
    'report.champPct':'{pct} del gasto total de este mes en una sola transacción!',
    'report.addWidget':'Añadir elemento',
    'wdef.insight':'Resumen mensual','wdef.champion':'Mayor gasto','wdef.dayofweek':'Gastos por día',
    'wdef.survival':'Presupuesto','wdef.mbti':'MBTI de consumo','wdef.top3cats':'Categoría TOP 3',
    'me.streak':'🔥 {n} días seguidos','me.streakZero':'¡Empieza a registrar hoy!',
    'me.monthRecord':'Este mes','me.streakDays':'Días seguidos',
    'me.notLoggedIn':'No conectado','me.syncInfo':'Inicia sesión para sincronizar','me.loginBtn':'Iniciar sesión / Registrarse',
    'dow.noExpense':'Sin gasto',
    'badge.guard':'Guardián de activos 🛡️','badge.explorer':'Explorador ahorrativo 🧭','badge.sprout':'Brote de registros 🌱',
    'cat.dining':'Comida','cat.transport':'Transporte','cat.shopping':'Compras','cat.medical':'Médico',
    'cat.culture':'Cultura','cat.telecom':'Telecom','cat.housing':'Vivienda','cat.other':'Otros',
    'cat.salary':'Salario','cat.allowance':'Paga','cat.otherIncome':'Otros ingresos',
    'pay.cash':'Efectivo','pay.credit':'Tarjeta crédito','pay.debit':'Tarjeta débito','pay.transfer':'Transferencia',
    'pay.kakao':'KakaoPay','pay.naver':'NaverPay','pay.toss':'Toss','pay.other':'Otros','pay.auto':'Auto',
    'stats.yearTotal':'Todo {y}',
    'fmt.dateGroup':'{dow} {d}',
    'empty.noRecords':'Sin registros',
    'stats.noData':'¡Sin registros en este período!',
    'stats.noDataSub':'Añade registros de {type}<br>para {period}',
    'wpop.moveUp':'↑ Subir','wpop.moveDown':'↓ Bajar','wpop.delete':'🗑️ Eliminar',
    'report.editDone':'✅ Listo','report.editStart':'✏️ Editar Widgets',
    'toast.feelOk':'¡Buena elección! 😊','toast.feelRegret':'¡Intenta aguantar la próxima vez! 💪',
    'report.empty':'Aún no hay elementos.<br>¡Activa los elementos de abajo para añadirlos! ✨',
    'surv.thisWeek':'Esta semana','surv.lastWeek':'Semana pasada','surv.weeksAgo':'Hace {n} semanas',
    'surv.thisYear':'Este año','surv.lastYear':'Año pasado','surv.yearFmt':'{y}',
    'surv.thisMonth':'Este mes','surv.lastMonth':'Mes pasado',
    'surv.weekRange':'{m1}/{d1}(Lun) ~ {m2}/{d2}(Dom)',
    'surv.budgetPct':'{pct}% gastado','surv.noGoal':'Sin objetivo','surv.notSet':'Sin definir',
    'surv.weekNoGoal':'No se definió objetivo para esta semana',
    'surv.totalVar':'Total gastos variables (excl. fijos)',
    'surv.danger':'🚨 ¡Alerta de presupuesto! Gasta menos',
    'surv.remaining':'Presupuesto restante','surv.over':'Exceso de gasto',
    'surv.msgNoGoal':'Sin objetivo de presupuesto para {period} 📭',
    'surv.msgEnterGoal':'Introduce un presupuesto<br>para ver tu gasto diario disponible 💡',
    'surv.msgOver':'Objetivo de {period} superado en <b>{amt}</b> 😰',
    'surv.msgSaved':'¡Ahorraste <b>{amt}</b> vs el objetivo de {period}! 🎉',
    'surv.msgDanger':'Solo <b>{daily}</b>/día para los <b>{days}</b> días restantes 🚨',
    'surv.msgOk':'<b>{daily}</b>/día disponibles para <b>{days}</b> días 💰',
    'insight.tagStart':'#EmpezarRegistro','insight.tagFirst':'#PrimerRegistro','insight.tagEqual':'#Equilibrado',
    'insight.tagThrifty':'#Ahorrador','insight.tagSaved':'#AhorroConseguido','insight.tagSaving':'#Ahorrando',
    'insight.tagOverspend':'#AlertaGasto','insight.tagUpBig':'#GranAumento','insight.tagUpWarn':'#AlertaGasto','insight.tagUpSmall':'#PequeñoAumento',
    'insight.noData':'{name}, ¡aún no hay gastos este mes!',
    'insight.noDataSub':'Empieza a registrar hoy ✏️',
    'insight.firstRecord':'{name}, ¡has gastado {amt} este mes hasta ahora!',
    'insight.firstRecordSub':'Sin datos previos — la comparación estará disponible el mes que viene 📖',
    'insight.equal':'{name}, gastaste exactamente lo mismo que el mes pasado.',
    'insight.equalSub':'Patrón de gasto equilibrado.',
    'insight.down30':'{name}, ¡bajó {pct}% respecto al mes pasado! Experto en ahorro 👑',
    'insight.down30Sub':'¡Ahorraste {amt}! Sigue así 💚',
    'insight.down10':'{name}, ¡{pct}% menos que el mes pasado! Muy bien 👏',
    'insight.downSmall':'{name}, {pct}% menos que el mes pasado. ¡Buen trabajo!',
    'insight.downSub':'Ahorraste {amt}. ¡Sigue así!',
    'insight.up50':'{name}, ¡los gastos subieron {pct}% respecto al mes pasado! Alerta 😰',
    'insight.up30':'{name}, los gastos subieron {pct}% este mes. La cartera se adelgaza 😅',
    'insight.up10':'{name}, este mes gastaste {pct}% más. ¡Intenta controlarte! 💪',
    'insight.upSmall':'{name}, subió {pct}% respecto al mes pasado. ¡Aún manejable!',
    'insight.upSub':'Gastaste {amt} más que el mes pasado.',
    'export.all':'Total',
    'dow.noData':'Aún no hay datos de gastos este mes.',
    'dow.balanced':'¡Los gastos están distribuidos uniformemente!',
    'dow.peak':'El <b>{day}</b> tiene el mayor gasto este mes',
    'dow.txCount':'{n} transacciones',
    'dow.msg.mon':'¡Mucha energía gastada para empezar la semana!<br>¿Combatiendo el lunes con el gasto? 😂',
    'dow.msg.tue':'El gasto constante entre semana se acumula.<br>¡Reducir pequeños gastos = mes exitoso! 🌱',
    'dow.msg.wed':'El gasto constante entre semana se acumula.<br>¡Reducir pequeños gastos = mes exitoso! 🌱',
    'dow.msg.thu':'El gasto constante entre semana se acumula.<br>¡Reducir pequeños gastos = mes exitoso! 🌱',
    'dow.msg.fri':'¡Explosión de TGIF! El fin de semana empieza 🍺',
    'dow.msg.sat':'¡Alerta FLEX de fin de semana! 🚨<br>¿Cómo disfrutarlo dentro del presupuesto?',
    'dow.msg.sun':'¡El domingo es tu mayor día de gasto!<br>Ahorra un poco para la semana que viene 🏠',
    'mbti.title.eatj':'Foodie','mbti.title.shop':'Trendsetter','mbti.title.yolo':'YOLO',
    'mbti.title.move':'Movilidad','mbti.title.hlth':'Salud primero','mbti.title.digi':'Nómada Digital',
    'mbti.title.home':'Hogareño','mbti.title.none':'Recopilando datos','mbti.title.free':'Espíritu libre',
    'mbti.desc.eatj':'¡La comida es lo primero! Siempre explorando restaurantes. ¡Tu cartera solo se abre por la comida!',
    'mbti.desc.shop':'¡Las compras son terapia! El carrito se llena sin darte cuenta. ¿Haciendo el tour de compras hoy?',
    'mbti.desc.yolo':'¡Invirtiendo sin dudarlo en experiencias! Solo se vive una vez. ¡Los recuerdos son la mejor inversión!',
    'mbti.desc.move':'¡Siempre en movimiento! Tipo activo y ocupado. ¿Todavía corriendo hoy?',
    'mbti.desc.hlth':'¡La salud es lo primero! Sin escatimar en el cuidado del cuerpo. ¡La salud es tu verdadera riqueza!',
    'mbti.desc.digi':'¡Maestro de la vida digital! No puedes vivir sin servicios de suscripción. ¿Todavía haciendo streaming?',
    'mbti.desc.home':'¡El hogar es lo mejor! Serio en decorar tu propio espacio. ¿Modo café en casa hoy?',
    'mbti.desc.none':'¡Añade registros de gastos este mes para analizar tu MBTI de consumo!',
    'mbti.desc.free':'¡Libre sin patrones fijos! Tipo flexible que gasta de manera uniforme en diversas áreas.',
    'mbti.budget.great':'¡Un consumidor perfecto con gran capacidad de ahorro! 💪',
    'mbti.budget.ok':'Un consumidor con sentido del equilibrio 👍',
    'mbti.budget.warn':'¡Casi en el límite! Intenta ahorrar un poco más 😅',
    'mbti.budget.over':'¡Presupuesto superado! El próximo mes en modo ahorro 😰',
    'fx.weekly':'Cada {dow}','fx.monthly':'Día {d} de cada mes','fx.yearly':'Cada {m}/{d}',
    'fx.pastDateMonth':'¡Esta fecha ya pasó! ¿Registrar en este mes ({label}) ahora?',
    'fx.pastDateYear':'¡Esta fecha ya pasó! ¿Registrar en este año ({m}/{d}) ahora?',
    'toast.coming':'Próximamente','toast.langSet':'Idioma actualizado ✓','toast.fontChanged':'Tamaño de fuente cambiado',
    'toast.catDeleted':'Categoría eliminada','toast.catEmptyName':'Introduce el nombre de la categoría',
    'toast.catDuplicate':'La categoría ya existe','toast.catAdded':'Categoría añadida 🏷️',
    'toast.payDeleted':'Método de pago eliminado','toast.payEmptyName':'Introduce el nombre del método de pago',
    'toast.payDuplicate':'El método de pago ya existe','toast.payAdded':'Método de pago añadido',
    'toast.fxEmptyName':'Introduce el nombre del elemento','toast.fxEmptyAmt':'Introduce el importe',
    'toast.fxSaved':'¡Registrado en el calendario! 📌','toast.fxAdded':'Elemento fijo añadido 📌',
    'toast.fxAddFail':'Error al añadir: {msg}','toast.fxError':'Ocurrió un error. Por favor, inténtalo de nuevo.',
    'toast.fxDeleted':'Elemento fijo eliminado','toast.deleted':'Eliminado',
    'toast.notifSet':'Recordatorio diario programado para las {time} 🔔',
    'toast.noNotifSupport':'Este navegador no soporta notificaciones',
    'toast.backupDown':'Copia de seguridad descargada ✅','toast.backupError':'No se puede leer el archivo: {err}',
    'toast.csvDown':'CSV descargado 📊',
    'toast.allDeleted':'Todos los registros eliminados 🗑️','toast.deleteFail':'Error al eliminar: {msg}',
    'toast.serverError':'Error de servidor',
    'toast.inquirySent':'Consulta enviada ✓','toast.inquiryEmpty':'Introduce título y contenido',
    'fx.loadingList':'Cargando...','fx.emptyList':'Sin elementos fijos aún<br>Añade uno abajo',
    'confirm.deleteLinked':'"{name}" tiene {count} registro(s) este mes. ¿Eliminarlos también?\n\nCancelar para eliminar solo la configuración del elemento fijo.',
    'opt.selectCat':'Seleccionar categoría',
    'fontsize.normal':'Normal','fontsize.large':'Grande','fontsize.xlarge':'Muy grande',
    'lang.ko':'Coreano','lang.en':'Inglés','lang.ja':'Japonés','lang.zh':'Chino','lang.es':'Español',
    'alert.dateRange':'Selecciona fecha de inicio y fin.','alert.dateOrder':'La fecha de fin no puede ser anterior a la de inicio.',
    'alert.enterAmt':'Por favor introduce un importe.','alert.enterDate':'Por favor selecciona una fecha.',
    'ico.food':'Comida','ico.shopping':'Compras','ico.transport':'Transporte','ico.car':'Coche',
    'ico.housing':'Vivienda','ico.health':'Salud','ico.telecom':'Telecom','ico.culture':'Cultura',
    'ico.salary':'Salario','ico.gift':'Regalo','ico.travel':'Viaje','ico.cafe':'Café',
    'ico.alcohol':'Alcohol','ico.education':'Educación','ico.sports':'Deporte','ico.pet':'Mascota',
    'ico.repair':'Reparación','ico.electronics':'Electrónica','ico.clothing':'Ropa','ico.child':'Infantil',
    'ico.grocery':'Verduras','ico.beauty':'Belleza','ico.social':'Social','ico.savings':'Ahorros',
    'ico.donation':'Donación','ico.smoking':'Tabaco','ico.otherIncome':'Otros ingresos','ico.other':'Otros',
    'ico.credit':'Tarjeta crédito','ico.cash':'Efectivo','ico.transfer':'Transferencia','ico.wallet':'Cartera',
    'ico.easypay':'Pago rápido','ico.qr':'Pago QR','ico.pay':'Pago','ico.points':'Puntos',
    'ico.voucher':'Vale','ico.bank':'Banco','ico.piggyBank':'Hucha','ico.coins':'Monedas',
    'ico.autoTransfer':'Transferencia auto',
    'ico.allowance':'Paga','ico.invest':'Inversión','ico.interest':'Interés','ico.save':'Ahorro',
    'ico.sideIncome':'Ingreso extra','ico.refund':'Reembolso','ico.bonus':'Bonus',
    'ico.regularIncome':'Ingreso regular','ico.reward':'Premio',
  },
};
function tr(key) {
  const name = localStorage.getItem(LANG_SK) || '한국어';
  const T = TRANSLATIONS[LANG_CODE_MAP[name] || 'ko'];
  return T[key] || TRANSLATIONS.ko[key] || key;
}
const _FONTSIZE_KEY = {'보통':'fontsize.normal','크게':'fontsize.large','아주 크게':'fontsize.xlarge'};
const _LANG_KEY = {'한국어':'lang.ko','영어':'lang.en','일본어':'lang.ja','중국어':'lang.zh','스페인어':'lang.es'};
function trFontSize(v) { return _FONTSIZE_KEY[v] ? tr(_FONTSIZE_KEY[v]) : v; }
function trLang(v) { return _LANG_KEY[v] ? tr(_LANG_KEY[v]) : v; }
function applyLang() {
  const name = localStorage.getItem(LANG_SK) || '한국어';
  const T = TRANSLATIONS[LANG_CODE_MAP[name] || 'ko'];
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const k = el.getAttribute('data-i18n'); if (T[k] !== undefined) el.textContent = T[k];
  });
  document.querySelectorAll('[data-i18n-ph]').forEach(el => {
    const k = el.getAttribute('data-i18n-ph'); if (T[k] !== undefined) el.placeholder = T[k];
  });
  const badgeEl = document.getElementById('meBadge');
  if (badgeEl) {
    const b = BADGE_COUNT >= 50 ? tr('badge.guard') : BADGE_COUNT >= 20 ? tr('badge.explorer') : BADGE_COUNT >= 5 ? tr('badge.sprout') : '';
    if (b) badgeEl.textContent = b;
  }
  const streakEl = document.getElementById('meStreak');
  if (streakEl && typeof txs === 'undefined') {
    const initStreak = <?= (int)($dbStats['streak'] ?? 0) ?>;
    streakEl.textContent = initStreak > 0 ? tr('me.streak').replace('{n}', initStreak) : tr('me.streakZero');
  }
  const rowVal = document.getElementById('langRowValue');
  if (rowVal) rowVal.textContent = trLang(name);
  const fszVal = document.getElementById('fontSizeRowValue');
  if (fszVal) { const fv = localStorage.getItem('design_fontsize')||'보통'; fszVal.textContent = trFontSize(fv); }
  const currVal = document.getElementById('currencyRowValue');
  if (currVal) { const sym = getCurrSymbol(); const code = getCurrCode(); currVal.textContent = sym + ' ' + code; }
}
function fmtYearMonth(y, m) {
  const lang = localStorage.getItem(LANG_SK) || '한국어';
  const code = LANG_CODE_MAP[lang] || 'ko';
  if (code === 'ko') return y + '년 ' + parseInt(m) + '월';
  if (code === 'ja') return y + '年' + parseInt(m) + '月';
  if (code === 'zh') return y + '年' + parseInt(m) + '月';
  const date = new Date(parseInt(y), parseInt(m) - 1, 1);
  const locale = code === 'es' ? 'es' : 'en';
  return date.toLocaleDateString(locale, { year: 'numeric', month: 'long' });
}
function setLang(name) {
  localStorage.setItem(LANG_SK, name);
  applyLang();
  closeLangModal();
  showToast(tr('toast.langSet'));
}
function openLangModal() {
  const cur = localStorage.getItem(LANG_SK) || '한국어';
  ['한국어','영어','일본어','중국어','스페인어'].forEach(v => {
    const el = document.getElementById('langOpt' + v);
    if (el) el.style.borderColor = (cur === v) ? '#364A6D' : '#e0e0e0';
  });
  document.getElementById('langModal').classList.add('show');
}
function closeLangModal() {
  document.getElementById('langModal').classList.remove('show');
}

// ── 초기화 ───────────────────────────────────────────────────
load();
loadFixed();
applyDarkMode();
startNotifScheduler();
setMonthLabel();
refreshIcons();
applyLang();
// DB 카테고리를 먼저 로드한 뒤 렌더 (로그인 상태에서 카테고리 즉시 반영)
loadDbCats(() => {
  renderLedger();
  autoApplyFixed();
});
</script>
</body>
</html>
