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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #f5f5f5; color: #212121;
  max-width: 480px; margin: 0 auto; min-height: 100vh; overflow-x: hidden;
}

/* ── 헤더 ── */
.app-header {
  position: sticky; top: 0; z-index: 100;
  background: #455A64; color: #fff;
  height: 56px; padding: 0 14px;
  display: grid; grid-template-columns: 1fr auto 1fr; align-items: center;
}
.header-title { font-size: 18px; font-weight: 700; justify-self: start; }
.month-nav { display: flex; align-items: center; gap: 4px; justify-self: center; }
.header-actions { display: flex; align-items: center; gap: 2px; justify-self: end; }
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
}
.cal-btn:active { background: rgba(255,255,255,.2); }
.search-btn {
  background: none; border: none; color: #fff; font-size: 18px;
  cursor: pointer; padding: 4px 6px; border-radius: 4px; line-height: 1;
}
.search-btn:active { background: rgba(255,255,255,.2); }

/* ── 탭 패인 ── */
.tab-pane { display: none; padding-bottom: 72px; }
.tab-pane.active { display: block; }

/* ── 요약 카드 ── */
.summary-card { background: #455A64; color: #fff; padding: 14px 20px 20px; margin-bottom: 6px; }
.summary-row { display: flex; margin-top: 6px; }
.summary-col { flex: 1; text-align: center; }
.sum-label  { font-size: 11px; opacity: .75; }
.sum-value  { font-size: 17px; font-weight: 700; margin-top: 4px; }
.sum-income { color: #80cbc4; }
.sum-expense { color: #ef9a9a; }

/* ── 거래 목록 ── */
.date-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 16px 5px; font-size: 12px; font-weight: 700; color: #757575; background: #f5f5f5;
}
.tx-row {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px; background: #fff; border-bottom: 1px solid #f0f0f0; cursor: pointer;
}
.tx-row:active { background: #fafafa; }
.tx-icon {
  width: 38px; height: 38px; border-radius: 50%; background: #eceff1;
  display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}
.tx-info { flex: 1; min-width: 0; }
.tx-desc { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tx-cat  { font-size: 12px; color: #9e9e9e; margin-top: 2px; }
.tx-right { display: flex; flex-direction: row; align-items: center; gap: 8px; }
.tx-amt  { font-size: 15px; font-weight: 700; white-space: nowrap; }
.tx-amt.expense { color: #e53935; }
.tx-amt.income  { color: #00BCD4; }
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
.cal-dow:first-child { color: #e53935; }
.cal-dow:last-child  { color: #42a5f5; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 2px; }
.cal-cell {
  background: #fff; border-radius: 8px; padding: 6px 4px 5px;
  min-height: 54px; cursor: pointer; position: relative;
  display: flex; flex-direction: column; align-items: center;
}
.cal-cell:active { background: #f0f0f0; }
.cal-cell.today .cal-day { background: #455A64; color: #fff; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
.cal-cell.other-month { opacity: .35; }
.cal-day { font-size: 13px; font-weight: 600; color: #212121; line-height: 24px; }
.cal-day.sun { color: #e53935; }
.cal-day.sat { color: #42a5f5; }
.cal-dots { display: flex; gap: 2px; margin-top: 3px; flex-wrap: wrap; justify-content: center; }
.cal-dot { width: 5px; height: 5px; border-radius: 50%; }
.cal-dot.e { background: #e53935; }
.cal-dot.i { background: #00BCD4; }
.cal-amt { font-size: 9px; color: #e53935; margin-top: 2px; white-space: nowrap; overflow: hidden; width: 100%; text-align: center; }

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
  background: #455A64; border-radius: 20px 20px 0 0;
  padding: 14px 20px; display: flex; justify-content: space-between; align-items: center;
}
.day-sheet-title { color: #fff; font-size: 16px; font-weight: 700; }
.day-sheet-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.day-sheet-add { background: rgba(255,255,255,.25); border: none; color: #fff; font-size: 20px; font-weight: 700; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
.day-sheet-add:active { background: rgba(255,255,255,.4); }

/* ── 통계 ── */
.section-box { margin: 10px 14px; background: #fff; border-radius: 12px; padding: 18px; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.section-title { font-size: 14px; font-weight: 700; color: #424242; margin-bottom: 14px; }
/* 지출/수입 토글 */
.stats-type-toggle { display: flex; margin: 10px 16px 0; border-radius: 10px; overflow: hidden; border: 1.5px solid #e0e0e0; }
.st-btn { flex: 1; padding: 9px 0; border: none; background: #f5f5f5; font-size: 15px; font-weight: 700; color: #9e9e9e; cursor: pointer; font-family: inherit; transition: all .2s; }
.st-btn.on.expense { background: #00BFA5; color: #fff; }
.st-btn.on.income  { background: #36A2EB; color: #fff; }
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
.rg-btn.on { border-color: #455A64; color: #455A64; background: #eceff1; }
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
.daterange-hd { background: #455A64; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
.daterange-hd-title { color: #fff; font-size: 16px; font-weight: 700; }
.daterange-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.daterange-body { padding: 20px; }
.daterange-row { margin-bottom: 14px; }
.daterange-row label { display: block; font-size: 12px; font-weight: 700; color: #757575; margin-bottom: 6px; }
.daterange-row input { width: 100%; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 12px; font-size: 15px; outline: none; font-family: inherit; }
.daterange-row input:focus { border-color: #455A64; }
.daterange-presets { display: flex; gap: 6px; margin-bottom: 4px; }
.preset-btn { flex: 1; padding: 7px 0; border: 1px solid #e0e0e0; border-radius: 6px; background: #f5f5f5; font-size: 12px; font-weight: 600; color: #616161; cursor: pointer; font-family: inherit; }
.preset-btn:active { background: #eceff1; }
.daterange-apply { display: block; width: calc(100% - 40px); margin: 0 20px 20px; background: #455A64; color: #fff; border: none; border-radius: 10px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; }
.daterange-apply:active { opacity: .85; }
.stats-filter-btn { flex: 1; padding: 9px 0; border: none; background: none; border-radius: 8px; font-size: 14px; font-weight: 700; color: #9e9e9e; cursor: pointer; font-family: inherit; transition: all .2s; }
.stats-filter-btn.on { background: #fff; color: #455A64; box-shadow: 0 1px 6px rgba(0,0,0,.13); }
.donut-section { margin: 10px 16px 0; background: #fff; border-radius: 16px; padding: 12px 16px 10px; box-shadow: 0 1px 4px rgba(0,0,0,.07); overflow: visible; }
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
.ranking-section { margin: 8px 16px 0; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.ranking-header { padding: 14px 16px 10px; font-size: 13px; font-weight: 700; color: #757575; border-bottom: 1px solid #f5f5f5; }
.ranking-item { display: flex; align-items: center; gap: 12px; padding: 13px 16px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background .2s; }
.ranking-item:last-child { border-bottom: none; }
.ranking-item:active { background: #f5f5f5; }
.ranking-item.highlighted { background: #e8f4fd; }
.ranking-item.highlighted .rank-name { color: #1565C0; font-weight: 700; }
.rank-num { width: 18px; font-size: 12px; font-weight: 700; color: #bdbdbd; text-align: center; flex-shrink: 0; }
.rank-num.top { color: #455A64; }
.rank-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.rank-icon { font-size: 22px; width: 28px; text-align: center; flex-shrink: 0; }
.rank-info { flex: 1; min-width: 0; }
.rank-name { font-size: 14px; font-weight: 600; color: #212121; }
.rank-bar-wrap { margin-top: 5px; height: 4px; background: #f0f0f0; border-radius: 2px; overflow: hidden; }
.rank-bar { height: 100%; border-radius: 2px; transition: width .5s ease; }
.rank-right { text-align: right; flex-shrink: 0; }
.rank-pct { font-size: 11px; color: #9e9e9e; }
.rank-amt { font-size: 14px; font-weight: 700; color: #e53935; }

/* ── 카테고리 상세 시트 ── */
.catdet-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 450; align-items: flex-end; justify-content: center; }
.catdet-overlay.show { display: flex; }
.catdet-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; max-height: 75vh; display: flex; flex-direction: column; }
.catdet-hd { background: #455A64; border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
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
.widget-card { background:#fff; border-radius:16px; box-shadow:0 1px 8px rgba(0,0,0,.07); overflow:hidden; animation:widgetIn .3s cubic-bezier(.22,1,.36,1); position:relative; }
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
.wpop-item.danger { color:#e53935; }
/* 편집 패널 추가 버튼 */
.edit-row-plus { font-size:18px; color:#00BFA5; font-weight:400; flex-shrink:0; }
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
.rc-cmp-val.up   { color:#e53935; }
.rc-cmp-val.down { color:#00BCD4; }
.rc-cmp-val.this-month { font-size:20px; color:#455A64; }
.rc-cmp-arr { font-size:20px; color:#bdbdbd; }
/* 챔피언 / 후회 분석 */
.champ-header { background:linear-gradient(135deg,#607D8B,#455A64); padding:12px 16px; }
.champ-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.champ-body { padding:14px 16px 16px; }
.champ-row { display:flex; align-items:center; gap:12px; }
.champ-left { display:flex; align-items:center; gap:10px; flex:1; min-width:0; }
.champ-emoji { font-size:32px; line-height:1; flex-shrink:0; }
.champ-info { min-width:0; }
.champ-name { font-size:15px; font-weight:800; color:#212121; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.champ-cat  { font-size:11px; color:#9e9e9e; margin-top:2px; }
.champ-right { text-align:right; flex-shrink:0; }
.champ-amt  { font-size:22px; font-weight:900; color:#455A64; }
.champ-date { font-size:11px; color:#bdbdbd; margin-top:3px; }
.champ-pct-msg { font-size:12px; color:#9e9e9e; margin-top:10px; line-height:1.5; }
.champ-pct-msg span { font-weight:700; color:#455A64; }
.champ-feel-btns { display:flex; gap:8px; margin-top:12px; }
.champ-feel-btn { flex:1; padding:9px 0; border:2px solid #e0e0e0; border-radius:12px; background:#fafafa; font-size:13px; font-weight:700; color:#757575; cursor:pointer; transition:all .2s; }
.champ-feel-btn:active { opacity:.8; }
.champ-feel-btn.active.ok   { border-color:#43A047; background:#e8f5e9; color:#2e7d32; }
.champ-feel-btn.active.regret { border-color:#e53935; background:#ffebee; color:#c62828; }
.widget-card.champ-regret { box-shadow:0 0 0 2px #ef9a9a, 0 4px 20px rgba(229,57,53,.18); }
/* 요일 */
.dow-body { padding:20px; }
.dow-title { font-size:13px; font-weight:700; color:#424242; margin-bottom:14px; }
.dow-bars { display:flex; align-items:flex-end; gap:6px; height:80px; position:relative; }
.dow-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; position:relative; cursor:pointer; }
.dow-bar { width:100%; border-radius:4px 4px 0 0; background:#CFD8DC; transition:height .4s ease; min-height:4px; }
.dow-bar.peak  { background:linear-gradient(to top,#455A64,#607D8B); }
.dow-bar-label { font-size:10px; color:#9e9e9e; font-weight:600; }
.dow-bar-label.peak  { color:#455A64; font-weight:800; }
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
.toggle-input:checked + .toggle-slider { background:#00BFA5; }
.toggle-input:checked + .toggle-slider::before { transform:translateX(20px); }
/* 빈 상태 */
.report-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 24px; text-align:center; }
.report-empty-ico { font-size:52px; margin-bottom:14px; opacity:.5; }
.report-empty-msg { font-size:15px; color:#9e9e9e; line-height:1.7; }
/* 하단 고정 편집 바 */
.report-edit-bar { position:fixed; bottom:62px; left:50%; transform:translateX(-50%); width:100%; max-width:480px; z-index:150; background:rgba(255,255,255,.95); backdrop-filter:blur(6px); padding:10px 16px 12px; box-shadow:0 -2px 12px rgba(0,0,0,.08); }
.report-edit-btn { display:block; width:100%; background:#eceff1; color:#455A64; border:none; border-radius:12px; padding:13px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .15s; }
.report-edit-btn.on { background:#455A64; color:#fff; }
.report-edit-btn:active { opacity:.85; }
/* 생존 가이드 */
.surv-header { background:linear-gradient(135deg,#00796B,#00897B); padding:12px 16px; transition:background .3s; }
.surv-header.danger { background:linear-gradient(135deg,#c62828,#e53935); }
.surv-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.surv-body { padding:16px 20px 20px; text-align:center; transition:background .3s; }
.widget-card.surv-danger { background:#fff5f5; }
.surv-remaining { font-size:11px; color:#9e9e9e; margin-bottom:6px; }
.surv-remaining-amt { font-size:28px; font-weight:900; color:#212121; margin-bottom:14px; }
.surv-remaining-amt.positive { color:#00796B; }
.surv-remaining-amt.negative { color:#e53935; }
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
.surv-progress-bar.danger { background:linear-gradient(to right,#c62828,#e53935); }
.surv-progress-labels { display:flex; justify-content:space-between; font-size:10px; color:#9e9e9e; margin-bottom:12px; }
.surv-period-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.surv-period-btn { background:none; border:none; font-size:20px; color:#455A64; cursor:pointer; padding:2px 8px; border-radius:6px; font-family:inherit; line-height:1; transition:color .15s; }
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
.top3-amt  { font-size:15px; font-weight:800; color:#455A64; }
.top3-pct  { font-size:11px; color:#9e9e9e; margin-top:2px; }
.top3-bar-wrap { height:4px; background:#f0f0f0; border-radius:2px; margin-top:6px; }
.top3-bar { height:4px; border-radius:2px; background:linear-gradient(to right,#7B1FA2,#CE93D8); }
.top3-empty { text-align:center; padding:24px 0; color:#bdbdbd; font-size:14px; }
/* 소비 MBTI */
.mbti-header { background:linear-gradient(135deg,#7B1FA2,#9C27B0); padding:12px 16px; }
.mbti-header-label { font-size:12px; font-weight:700; color:rgba(255,255,255,.9); letter-spacing:.5px; }
.mbti-body { padding:16px 20px 20px; text-align:center; }
.mbti-emoji { font-size:40px; margin-bottom:6px; line-height:1; }
.mbti-code { font-size:34px; font-weight:900; color:#7B1FA2; letter-spacing:5px; margin-bottom:4px; font-family:monospace, sans-serif; }
.mbti-title { font-size:15px; font-weight:700; color:#212121; margin-bottom:10px; }
.mbti-desc { font-size:13px; color:#757575; line-height:1.75; margin-bottom:10px; padding:0 4px; }
.mbti-budget { font-size:13px; font-weight:600; color:#455A64; padding:8px 12px; background:#f5f5f5; border-radius:8px; margin-bottom:12px; line-height:1.5; }
.mbti-top3 { display:flex; justify-content:center; gap:6px; flex-wrap:wrap; }
.mbti-badge { font-size:11px; background:#f3e5f5; border-radius:20px; padding:4px 10px; color:#7B1FA2; font-weight:700; }

/* ── 나 ── */
/* ── 나 탭 ── */
.me-wrap { padding-bottom: 40px; }
.me-profile { background: linear-gradient(135deg,#455A64,#607D8B); padding: 32px 20px 28px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.me-avatar { width: 72px; height: 72px; border-radius: 50%; background: rgba(255,255,255,.2); border: 3px solid rgba(255,255,255,.4); display: flex; align-items: center; justify-content: center; font-size: 34px; }
.me-name  { font-size: 20px; font-weight: 800; color: #fff; }
.me-email { font-size: 12px; color: rgba(255,255,255,.7); margin-top: -4px; }
.me-streak { display: inline-flex; align-items: center; gap: 4px; background: rgba(255,255,255,.18); border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 700; color: #fff; margin-top: 2px; }
.me-login-btn { margin-top: 8px; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4); color: #fff; border-radius: 20px; padding: 8px 24px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-block; }
.me-section { margin: 16px 16px 0; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.me-section-title { font-size: 11px; font-weight: 800; color: #9e9e9e; letter-spacing: .6px; padding: 12px 16px 6px; }
.me-row { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-top: 1px solid #f5f5f5; cursor: pointer; transition: background .1s; }
.me-row:first-of-type { border-top: none; }
.me-row:active { background: #f5f5f5; }
.me-row-ico { font-size: 18px; width: 28px; text-align: center; flex-shrink: 0; }
.me-row-label { flex: 1; font-size: 15px; font-weight: 600; color: #212121; }
.me-row-value { font-size: 12px; color: #9e9e9e; }
.me-row-arrow { font-size: 16px; color: #c8c8c8; }
.me-row.danger .me-row-label { color: #e53935; }
.me-footer { text-align: center; padding: 28px 20px 16px; font-size: 12px; color: #bdbdbd; line-height: 1.7; }

/* ── 하단 탭바 ── */
.tab-bar { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 480px; background: #fff; border-top: 1px solid #e0e0e0; display: flex; height: 62px; z-index: 200; }
.t-btn { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; font-size: 10px; color: #9e9e9e; gap: 3px; padding: 0; }
.t-btn .ico { font-size: 22px; }
.t-btn.on { color: #455A64; font-weight: 700; }
.fab-wrap { flex: 1; display: flex; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; padding: 0; }
.fab { width: 52px; height: 52px; border-radius: 50%; background: #00BFA5; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 30px; line-height: 1; margin-top: -14px; box-shadow: 0 4px 14px rgba(0,191,165,.45); }
.fab:active { transform: scale(.93); }

/* ── 내역 액션 시트 ── */
.txa-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 450; align-items: flex-end; justify-content: center; }
.txa-overlay.show { display: flex; }
.txa-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; padding-bottom: 28px; }
.txa-hd { background: #455A64; border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
.txa-hd-title { color: #fff; font-size: 16px; font-weight: 700; }
.txa-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 24px; cursor: pointer; }
.txa-summary { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid #f0f0f0; }
.txa-icon { width: 44px; height: 44px; border-radius: 50%; background: #eceff1; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.txa-mid { flex: 1; min-width: 0; }
.txa-desc { font-size: 15px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txa-sub  { font-size: 12px; color: #9e9e9e; margin-top: 3px; }
.txa-amt  { font-size: 16px; font-weight: 700; white-space: nowrap; }
.txa-amt.expense { color: #e53935; }
.txa-amt.income  { color: #00BCD4; }
/* ── 사진 캐러셀 (액션시트/상세) ── */
.photo-carousel-wrap { position: relative; margin: 10px 20px 0; border-radius: 12px; overflow: hidden; background: #f0f0f0; border: 1px solid #e0e0e0; touch-action: pan-y; }
.photo-carousel-inner { display: flex; transition: transform .28s ease; will-change: transform; }
.photo-carousel-inner img { width: 100%; flex-shrink: 0; max-height: 180px; object-fit: contain; cursor: zoom-in; border-radius: 0; display: block; background: #f0f0f0; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); }
.photo-carousel-dots { display: flex; justify-content: center; gap: 7px; padding: 7px 0 2px; }
.photo-carousel-dot { width: 7px; height: 7px; border-radius: 50%; background: #d0d0d0; transition: background .2s, transform .2s; }
.photo-carousel-dot.on { background: #455A64; transform: scale(1.3); }
/* ── 모달 다중사진 ── */
.photo-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 13px; }
.photo-grid-item { position: relative; width: 68px; height: 68px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; flex-shrink: 0; background: #f5f5f5; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.photo-grid-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-grid-x { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,.58); color: #fff; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; padding: 0; }
.photo-add-btn { width: 68px; height: 68px; border-radius: 8px; border: 1.5px dashed #bbb; background: #f5f5f5; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; flex-shrink: 0; color: #aaa; }
.txa-menu { margin-top: 4px; }
.txa-item { display: flex; align-items: center; gap: 14px; padding: 15px 22px; font-size: 15px; cursor: pointer; border-bottom: 1px solid #f5f5f5; }
.txa-item:active { background: #f5f5f5; }
.txa-item .txa-ico { font-size: 20px; width: 24px; text-align: center; }
.txa-item.danger { color: #e53935; }

/* ── 상세정보 오버레이 ── */
.detail-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 460; align-items: flex-end; justify-content: center; }
.detail-overlay.show { display: flex; }
.detail-sheet { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; padding-bottom: 32px; }
.detail-hd { background: #455A64; border-radius: 20px 20px 0 0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
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
.search-bar { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #455A64; }
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
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 500; align-items: flex-end; justify-content: center; }
.overlay.show { display: flex; }
.modal { background: #fff; border-radius: 20px 20px 0 0; width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; padding-bottom: 28px; }
.modal-hd { background: #455A64; border-radius: 20px 20px 0 0; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
.modal-hd-title { color: #fff; font-size: 17px; font-weight: 700; }
.modal-x { background: none; border: none; color: rgba(255,255,255,.8); font-size: 26px; cursor: pointer; line-height: 1; }
.type-row { display: flex; margin: 16px 20px 0; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; }
.type-t { flex: 1; padding: 10px; border: none; background: #f5f5f5; font-size: 14px; font-weight: 600; color: #9e9e9e; cursor: pointer; }
.type-t.on.e { background: #e53935; color: #fff; }
.type-t.on.i { background: #00BCD4; color: #fff; }
.mform { padding: 14px 20px 0; }
.mf-label { font-size: 12px; font-weight: 700; color: #757575; margin-bottom: 5px; display: block; }
.mf-input, .mf-select { width: 100%; border: 1px solid #e0e0e0; border-radius: 8px; padding: 11px 14px; font-size: 15px; outline: none; margin-bottom: 13px; font-family: inherit; background: #fff; }
.mf-input:focus, .mf-select:focus { border-color: #455A64; }

/* 카테고리 행 (select + 추가버튼) */
.cat-row-wrap { display: flex; gap: 8px; margin-bottom: 13px; }
.cat-row-wrap .mf-select { margin-bottom: 0; flex: 1; }
.cat-add-btn { background: #455A64; color: #fff; border: none; border-radius: 8px; padding: 0 14px; font-size: 20px; cursor: pointer; flex-shrink: 0; }
.cat-add-btn:active { opacity: .8; }

/* 새 카테고리 입력 영역 */
.new-cat-box { display: none; background: #f9fbe7; border: 1px solid #e6ee9c; border-radius: 8px; padding: 12px; margin-bottom: 13px; }
.new-cat-box.show { display: block; }
.new-cat-row { display: flex; gap: 8px; }
.new-cat-emoji { width: 52px; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 6px; font-size: 18px; text-align: center; background: #fff; outline: none; }
.new-cat-name  { flex: 1; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: inherit; outline: none; background: #fff; }
.new-cat-name:focus { border-color: #455A64; }
.new-cat-save { background: #455A64; color: #fff; border: none; border-radius: 8px; padding: 0 14px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.new-cat-save:active { opacity: .8; }

/* 내용 + 사진 */
.desc-row { display: flex; gap: 8px; margin-bottom: 13px; }
.desc-row .mf-input { margin-bottom: 0; flex: 1; }
.photo-btn { background: #eceff1; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0 12px; font-size: 22px; cursor: pointer; flex-shrink: 0; }
.photo-btn:active { background: #cfd8dc; }
.photo-preview { display: none; margin-bottom: 13px; position: relative; }
.photo-preview img { width: 100%; max-height: 160px; object-fit: cover; border-radius: 10px; }
.photo-remove { position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,.55); color: #fff; border: none; border-radius: 50%; width: 26px; height: 26px; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

.modal-save { display: block; width: calc(100% - 40px); margin: 6px 20px 0; background: #455A64; color: #fff; border: none; border-radius: 10px; padding: 15px; font-size: 16px; font-weight: 700; cursor: pointer; }
.modal-save:active { opacity: .85; }

/* ── 다크모드 ── */
body.dark { background:#121212; color:#e0e0e0; }
body.dark .app-header { background:#263238; }
body.dark .summary-card { background:#263238; }
body.dark .tx-row { background:#1e1e1e; border-bottom-color:#2a2a2a; }
body.dark .tx-row:active { background:#252525; }
body.dark .tx-desc { color:#e0e0e0; }
body.dark .date-header { background:#121212; color:#9e9e9e; }
body.dark .tab-bar { background:#1e1e1e; border-top-color:#333; }
body.dark .t-btn { color:#757575; }
body.dark .t-btn.on { color:#90A4AE; }
body.dark .me-section { background:#1e1e1e; }
body.dark .me-row { border-top-color:#2a2a2a; }
body.dark .me-row:active { background:#252525; }
body.dark .me-row-label { color:#e0e0e0; }
body.dark .me-section-title { color:#666; }
body.dark .me-footer { color:#444; }
body.dark .widget-card { background:#1e1e1e; }
body.dark .edit-row { border-bottom-color:#2a2a2a; background:#1e1e1e; }
body.dark .edit-row-label { color:#c0c0c0; }
body.dark .report-edit-panel { background:#1e1e1e; }
body.dark .modal { background:#1e1e1e; }
body.dark .txa-sheet { background:#1e1e1e; }
body.dark .day-sheet { background:#1e1e1e; }
body.dark .catdet-sheet { background:#1e1e1e; }
body.dark .detail-sheet { background:#1e1e1e; }
body.dark .section-box { background:#1e1e1e; }
body.dark .donut-section { background:#1e1e1e; }
body.dark .ranking-section { background:#1e1e1e; }
body.dark .ranking-item { border-bottom-color:#2a2a2a; }
body.dark .ranking-item:active { background:#252525; }
body.dark .rank-name { color:#e0e0e0; }
body.dark .mf-input, body.dark .mf-select { background:#2a2a2a; border-color:#444; color:#e0e0e0; }
body.dark .type-t { background:#2a2a2a; color:#666; }
body.dark .cal-cell { background:#1e1e1e; }
body.dark .cal-cell:active { background:#252525; }
body.dark .cal-day { color:#c0c0c0; }
body.dark .cal-day.sun { color:#ef9a9a; }
body.dark .cal-day.sat { color:#90caf9; }
body.dark .search-overlay { background:#121212; }
body.dark .search-bar { background:#263238; }
body.dark .search-input { background:#2a2a2a; color:#e0e0e0; }
body.dark .rc-insight { color:#e0e0e0; }
body.dark .rc-cmp-val { color:#e0e0e0; }
body.dark .champ-name { color:#e0e0e0; }
body.dark .champ-body { background:#1e1e1e; }
body.dark .top3-cat { color:#e0e0e0; }
body.dark .top3-body { background:#1e1e1e; }
body.dark .top3-row { border-bottom-color:#2a2a2a; }
body.dark .mbti-title { color:#e0e0e0; }
body.dark .mbti-body { background:#1e1e1e; }
body.dark .mbti-budget { background:#2a2a2a; }
body.dark .dow-body { background:#1e1e1e; }
body.dark .dow-insight { color:#c0c0c0; }
body.dark .surv-body { background:#1e1e1e; }
body.dark .surv-remaining-amt { color:#e0e0e0; }
body.dark .surv-msg { color:#c0c0c0; }
body.dark .surv-input { background:#2a2a2a; border-color:#444; color:#e0e0e0; }
body.dark .surv-tabs { background:#2a2a2a; }
body.dark .surv-tab { color:#666; }
body.dark .surv-tab.active { background:#1e1e1e; }
body.dark .edit-panel-title { background:#1e1e1e; color:#666; border-bottom-color:#2a2a2a; }
body.dark .donut-center-amt { color:#e0e0e0; }
body.dark .txa-item { border-bottom-color:#2a2a2a; }
body.dark .txa-item:active { background:#252525; }
body.dark .detail-row { border-bottom-color:#2a2a2a; }
body.dark .detail-key { color:#666; }
body.dark .detail-val { color:#e0e0e0; }
body.dark .daterange-modal { background:#1e1e1e; }
body.dark .daterange-row input { background:#2a2a2a; border-color:#444; color:#e0e0e0; }
body.dark .daterange-row label { color:#9e9e9e; }
body.dark .preset-btn { background:#2a2a2a; border-color:#444; color:#9e9e9e; }
body.dark .widget-popover { background:#1e1e1e; }
body.dark .wpop-item { background:#1e1e1e; color:#e0e0e0; border-bottom-color:#2a2a2a; }
body.dark .tx-cat { color:#666; }
body.dark .me-row.danger .me-row-label { color:#ef9a9a; }
body.dark .new-cat-box { background:#2a2a2a; border-color:#555; }
body.dark .new-cat-emoji, body.dark .new-cat-name { background:#1e1e1e; border-color:#444; color:#e0e0e0; }
body.dark .report-edit-btn { background:#2a2a2a; color:#90A4AE; }
body.dark .report-edit-btn.on { background:#455A64; color:#fff; }
body.dark .report-edit-bar { background:rgba(18,18,18,.95); }
body.dark .empty-msg { color:#555; }
body.dark .tx-icon { background:#2a2a2a; }
body.dark .txa-icon { background:#2a2a2a; }
body.dark .txa-desc { color:#e0e0e0; }
body.dark .txa-sub { color:#666; }
body.dark .day-sheet-overlay { background:rgba(0,0,0,.7); }

/* ── 공통 센터 모달 오버레이 ── */
.center-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:600; align-items:center; justify-content:center; padding:16px; }
.center-overlay.show { display:flex; }
.center-modal { background:#fff; border-radius:16px; width:100%; max-width:400px; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,.2); }
.center-modal-hd { background:#455A64; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
.center-modal-hd-title { color:#fff; font-size:16px; font-weight:700; }
.center-modal-x { background:none; border:none; color:rgba(255,255,255,.8); font-size:24px; cursor:pointer; }
.center-modal-body { padding:16px 20px; max-height:65vh; overflow-y:auto; }
.center-modal-footer { padding:0 20px 20px; }
.center-modal-btn { display:block; width:100%; background:#455A64; color:#fff; border:none; border-radius:10px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; }
.center-modal-btn:active { opacity:.85; }
body.dark .center-modal { background:#1e1e1e; }
body.dark .center-modal-hd { background:#263238; }
body.dark .center-modal-body { background:#1e1e1e; }

/* ── 고정 지출 ── */
.fixed-item { display:flex; align-items:center; gap:10px; padding:12px 0; border-bottom:1px solid #f5f5f5; }
.fixed-item:last-child { border-bottom:none; }
.fixed-item-ico { font-size:22px; width:28px; text-align:center; flex-shrink:0; }
.fixed-item-info { flex:1; min-width:0; }
.fixed-item-name { font-size:14px; font-weight:700; color:#212121; }
.fixed-item-sub  { font-size:12px; color:#9e9e9e; margin-top:2px; }
.fixed-item-amt  { font-size:15px; font-weight:800; flex-shrink:0; }
.fixed-item-del  { background:none; border:none; font-size:18px; color:#e0e0e0; cursor:pointer; padding:4px; flex-shrink:0; }
.fixed-item-del:active { color:#e53935; }
.fixed-add-form { padding:12px; background:#f9f9f9; border-radius:10px; margin-top:10px; }
.fixed-add-row { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px; }
.fixed-add-row:last-child { margin-bottom:0; }
.fixed-add-input { flex:1; min-width:80px; border:1px solid #e0e0e0; border-radius:7px; padding:9px 10px; font-size:14px; outline:none; font-family:inherit; background:#fff; }
.fixed-add-input:focus { border-color:#455A64; }
.fixed-add-select { border:1px solid #e0e0e0; border-radius:7px; padding:9px 10px; font-size:13px; outline:none; font-family:inherit; background:#fff; }
.fixed-add-btn { background:#455A64; color:#fff; border:none; border-radius:7px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; flex-shrink:0; }
body.dark .fixed-add-form { background:#2a2a2a; }
body.dark .fixed-add-input, body.dark .fixed-add-select { background:#1e1e1e; border-color:#444; color:#e0e0e0; }
body.dark .fixed-item-name { color:#e0e0e0; }
body.dark .fixed-item { border-bottom-color:#2a2a2a; }

/* ── 카테고리 편집 ── */
.catedit-type-tabs { display:flex; border-bottom:2px solid #f0f0f0; margin-bottom:4px; }
.catedit-tab { flex:1; padding:10px 0; border:none; background:none; font-size:14px; font-weight:700; color:#9e9e9e; cursor:pointer; font-family:inherit; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s; }
.catedit-tab.on { color:#455A64; border-bottom-color:#455A64; }
.catedit-item { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid #f5f5f5; }
.catedit-item:last-child { border-bottom:none; }
.catedit-emoji-input { width:44px; height:38px; border:1px solid #e0e0e0; border-radius:7px; font-size:18px; text-align:center; background:#f5f5f5; outline:none; flex-shrink:0; }
.catedit-name-input { flex:1; border:1px solid #e0e0e0; border-radius:7px; padding:8px 10px; font-size:14px; font-family:inherit; outline:none; }
.catedit-name-input:focus { border-color:#455A64; }
.catedit-del { background:none; border:none; font-size:18px; color:#e0e0e0; cursor:pointer; padding:4px; flex-shrink:0; }
.catedit-del:active { color:#e53935; }
.catedit-add-row { display:flex; gap:6px; }
.catedit-add-emoji { width:44px; border:1px solid #e0e0e0; border-radius:7px; padding:8px 4px; font-size:18px; text-align:center; outline:none; font-family:inherit; flex-shrink:0; }
.catedit-add-name { flex:1; border:1px solid #e0e0e0; border-radius:7px; padding:8px 10px; font-size:14px; font-family:inherit; outline:none; }
.catedit-add-name:focus { border-color:#455A64; }
.catedit-add-btn { background:#455A64; color:#fff; border:none; border-radius:7px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer; flex-shrink:0; }
body.dark .catedit-type-tabs { border-bottom-color:#333; }
body.dark .catedit-tab.on { color:#90A4AE; border-bottom-color:#90A4AE; }
body.dark .catedit-item { border-bottom-color:#2a2a2a; }
body.dark .catedit-emoji-input, body.dark .catedit-name-input,
body.dark .catedit-add-emoji, body.dark .catedit-add-name { background:#2a2a2a; border-color:#444; color:#e0e0e0; }

/* ── 알림 설정 ── */
.notif-status { display:flex; align-items:center; gap:10px; padding:12px 0; border-bottom:1px solid #f5f5f5; margin-bottom:14px; }
.notif-status-dot { width:10px; height:10px; border-radius:50%; background:#bdbdbd; flex-shrink:0; }
.notif-status-dot.on { background:#43A047; }
.notif-status-text { flex:1; font-size:13px; color:#424242; line-height:1.5; }
.notif-time-row { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
.notif-time-label { font-size:13px; color:#757575; flex-shrink:0; }
.notif-time-input { flex:1; border:1px solid #e0e0e0; border-radius:8px; padding:10px 12px; font-size:16px; outline:none; font-family:inherit; }
.notif-time-input:focus { border-color:#455A64; }
body.dark .notif-status { border-bottom-color:#2a2a2a; }
body.dark .notif-status-text { color:#c0c0c0; }
body.dark .notif-time-label { color:#9e9e9e; }
body.dark .notif-time-input { background:#2a2a2a; border-color:#444; color:#e0e0e0; }

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
.help-section-title { font-size:13px; font-weight:800; color:#455A64; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.help-section-title::before { content:''; display:inline-block; width:3px; height:14px; background:#455A64; border-radius:2px; flex-shrink:0; }
.help-item { display:flex; align-items:flex-start; gap:8px; font-size:13px; color:#424242; line-height:1.65; margin-bottom:5px; }
.help-item::before { content:'•'; color:#455A64; font-weight:700; flex-shrink:0; margin-top:1px; }
body.dark .help-item { color:#c0c0c0; }
body.dark .help-section-title { color:#90A4AE; }
body.dark .help-section-title::before { background:#90A4AE; }
/* 고정지출 모달 */
.fx-cycle-btn {
  flex:1; padding:8px 0; font-size:14px; font-weight:600;
  border:1.5px solid #90A4AE; border-radius:8px;
  background:#fff; color:#546E7A; cursor:pointer; transition:all .15s;
}
.fx-cycle-btn.on { background:#455A64; color:#fff; border-color:#455A64; }
body.dark .fx-cycle-btn { background:#37474F; color:#CFD8DC; border-color:#607D8B; }
body.dark .fx-cycle-btn.on { background:#78909C; color:#fff; border-color:#78909C; }
.fx-dow-btn {
  flex:1; padding:7px 0; font-size:13px;
  border:1px solid #e0e0e0; border-radius:6px;
  background:#fff; color:#424242; cursor:pointer; transition:all .15s;
}
.fx-dow-btn.on { background:#455A64; color:#fff; border-color:#455A64; }
body.dark .fx-dow-btn { background:#37474F; color:#CFD8DC; border-color:#546E7A; }
body.dark .fx-dow-btn.on { background:#78909C; color:#fff; border-color:#78909C; }
</style>
</head>
<body class="<?= $darkMode ? 'dark' : '' ?>">

<!-- 헤더 -->
<div class="app-header">
  <div class="header-title">마이가계부</div>
  <div class="month-nav" id="monthNav">
    <button class="month-btn" onclick="changeMonth(-1)">‹</button>
    <span id="monthLabel" onclick="onMonthLabelClick()"></span>
    <button class="month-btn" onclick="changeMonth(1)">›</button>
  </div>
  <div class="header-actions" id="headerActions">
    <div id="haDefault" style="display:flex;align-items:center;gap:2px">
      <button class="search-btn" onclick="openSearch()" title="검색">🔍</button>
      <button class="cal-btn" onclick="toggleCalendar()" title="달력">📅</button>
    </div>
    <div id="haStats" class="header-period-filter" style="display:none">
      <button class="hpf-btn" id="hpf-week"  onclick="setStatsPeriod('week')">주</button>
      <button class="hpf-btn on" id="hpf-month" onclick="setStatsPeriod('month')">월</button>
      <button class="hpf-btn" id="hpf-year"  onclick="setStatsPeriod('year')">년</button>
    </div>
  </div>
</div>

<!-- ① 가계부 탭 -->
<div class="tab-pane active" id="pane-ledger">
  <div class="summary-card">
    <div class="summary-row">
      <div class="summary-col"><div class="sum-label">수입</div><div class="sum-value sum-income"  id="sumInc">₩0</div></div>
      <div class="summary-col"><div class="sum-label">지출</div><div class="sum-value sum-expense" id="sumExp">₩0</div></div>
      <div class="summary-col"><div class="sum-label">잔액</div><div class="sum-value"              id="sumBal">₩0</div></div>
    </div>
  </div>
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
    <button id="st-expense" class="st-btn on expense" onclick="setStatsType('expense')">지출</button>
    <button id="st-income"  class="st-btn income"     onclick="setStatsType('income')">수입</button>
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
        <button id="sg-category" class="rg-btn on" onclick="setStatsGroup('category')">카테고리</button>
        <button id="sg-payment"  class="rg-btn"    onclick="setStatsGroup('payment')">결제수단</button>
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
    <!-- 프로필 -->
    <div class="me-profile">
      <div class="me-avatar">👤</div>
      <?php if ($isLoggedIn): ?>
        <div class="me-name">
          <?=$userName?>님
          <?php if ($dbStats['badge']): ?>
            <span style="font-size:13px;font-weight:600;background:rgba(255,255,255,.22);border-radius:20px;padding:2px 10px;margin-left:6px;vertical-align:middle"><?=$dbStats['badge']?></span>
          <?php endif; ?>
        </div>
        <div class="me-email"><?=$userEmail?></div>
        <!-- DB 통계 카드 -->
        <div style="display:flex;gap:12px;margin-top:4px">
          <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:8px 16px;text-align:center">
            <div style="font-size:22px;font-weight:900;color:#fff"><?=$dbStats['month_count']?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.75);margin-top:2px">이번 달 기록</div>
          </div>
          <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:8px 16px;text-align:center">
            <div style="font-size:22px;font-weight:900;color:#fff" id="meStreak"><?=$dbStats['streak']?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.75);margin-top:2px">🔥 연속 기록일</div>
          </div>
        </div>
      <?php else: ?>
        <div class="me-name">비로그인</div>
        <div class="me-email">로그인하면 서버에 동기화됩니다</div>
        <a href="login.php" class="me-login-btn">로그인 / 회원가입</a>
      <?php endif; ?>
    </div>

    <!-- 기록 관리 -->
    <div class="me-section">
      <div class="me-section-title">기록 관리</div>
      <div class="me-row" onclick="openFixedModal()">
        <span class="me-row-ico">📌</span><span class="me-row-label">고정 지출 설정</span><span class="me-row-arrow">›</span>
      </div>
      <div class="me-row" onclick="openCatEditModal()">
        <span class="me-row-ico">🏷️</span><span class="me-row-label">카테고리 편집</span><span class="me-row-arrow">›</span>
      </div>
    </div>

    <!-- 앱 환경 -->
    <div class="me-section">
      <div class="me-section-title">앱 환경</div>
      <div class="me-row" onclick="openNotifModal()">
        <span class="me-row-ico">🔔</span><span class="me-row-label">푸시 알림</span><span class="me-row-value" id="notifRowValue">꺼짐</span><span class="me-row-arrow">›</span>
      </div>
      <div class="me-row" onclick="toggleDarkMode()">
        <span class="me-row-ico">🌙</span><span class="me-row-label">다크 모드</span>
        <label class="toggle-wrap" style="margin-left:auto;pointer-events:none">
          <input type="checkbox" class="toggle-input" id="darkToggle" style="pointer-events:none">
          <span class="toggle-slider"></span>
        </label>
      </div>
    </div>

    <!-- 데이터 -->
    <div class="me-section">
      <div class="me-section-title">데이터</div>
      <div class="me-row" onclick="openBackupModal()">
        <span class="me-row-ico">☁️</span><span class="me-row-label">백업 및 복구</span><span class="me-row-arrow">›</span>
      </div>
      <div class="me-row" onclick="openExportModal()">
        <span class="me-row-ico">📊</span><span class="me-row-label">엑셀로 내보내기</span><span class="me-row-arrow">›</span>
      </div>
      <div class="me-row danger" onclick="doDeleteAll()">
        <span class="me-row-ico">🗑️</span><span class="me-row-label">전체 내역 삭제</span><span class="me-row-arrow">›</span>
      </div>
    </div>

    <!-- 정보 -->
    <div class="me-section">
      <div class="me-section-title">정보</div>
      <div class="me-row" onclick="showToast('마이가계부 v1.0')">
        <span class="me-row-ico">ℹ️</span><span class="me-row-label">버전 정보</span><span class="me-row-value">v1.0</span>
      </div>
      <div class="me-row" onclick="openHelpModal()">
        <span class="me-row-ico">💬</span><span class="me-row-label">도움말</span><span class="me-row-arrow">›</span>
      </div>
      <?php if ($isLoggedIn): ?>
      <div class="me-row danger" onclick="location.href='logout.php'">
        <span class="me-row-ico">🚪</span><span class="me-row-label">로그아웃</span>
      </div>
      <?php endif; ?>
    </div>

    <div class="me-footer">마이가계부와 함께 현명한 소비를 이어가세요 ✨</div>
  </div>
</div>

<div class="widget-popover" id="widgetPopover"></div>

<!-- ── 고정 지출 설정 모달 ── -->
<div class="overlay" id="fixedModal" onclick="if(event.target===this)closeFixedModal()">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title">📌 고정 지출 설정</span>
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
        style="width:100%;background:#455A64;color:#fff;border:none;border-radius:8px;padding:12px;font-size:15px;font-weight:700;cursor:pointer">
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
        style="width:100%;background:#455A64;color:#fff;border:none;border-radius:8px;padding:12px;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:8px">
        ✅ 네, 지금 바로 기록할게요
      </button>
      <button onclick="submitFixed(false)"
        style="width:100%;background:#f5f5f5;color:#455A64;border:1px solid #ddd;border-radius:8px;padding:12px;font-size:14px;cursor:pointer">
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
          style="background:#455A64;color:#fff;border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">추가</button>
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
        <div class="help-item">서바이벌 가이드에서 예산을 설정하면 남은 금액을 알 수 있어요.</div>
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
      <div class="txa-item" onclick="showTxDetail()"><span class="txa-ico">📋</span> 상세정보</div>
      <div class="txa-item" onclick="editTx()"><span class="txa-ico">✏️</span> 수정</div>
      <div class="txa-item" onclick="copyTx()"><span class="txa-ico">📄</span> 복사</div>
      <div class="txa-item danger" onclick="deleteTxFromAction()"><span class="txa-ico">🗑️</span> 삭제</div>
    </div>
  </div>
</div>

<!-- 상세정보 시트 -->
<div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="detail-sheet">
    <div class="detail-hd">
      <span class="detail-hd-title">상세정보</span>
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
  <button class="t-btn on" id="tb-ledger" onclick="goTab('ledger')"><span class="ico">📒</span>가계부</button>
  <button class="t-btn"    id="tb-stats"  onclick="goTab('stats')"> <span class="ico">📊</span>통계</button>
  <button class="fab-wrap" onclick="openModal()"><div class="fab">＋</div></button>
  <button class="t-btn"    id="tb-report" onclick="goTab('report')"><span class="ico">📝</span>분석</button>
  <button class="t-btn"    id="tb-me"     onclick="goTab('me')">    <span class="ico">👤</span>나</button>
</div>

<!-- 내역 추가 모달 -->
<div class="overlay" id="modal" onclick="onOverlayClick(event)">
  <div class="modal">
    <div class="modal-hd">
      <span class="modal-hd-title" id="modalTitle">내역 추가</span>
      <button class="modal-x" onclick="closeModal()">×</button>
    </div>
    <div class="type-row">
      <button class="type-t on e" id="typeE" onclick="setType('expense')">지출</button>
      <button class="type-t"      id="typeI" onclick="setType('income')">수입</button>
    </div>
    <div class="mform">
      <label class="mf-label">금액 (원)</label>
      <input class="mf-input" id="fAmt" type="number" inputmode="numeric" placeholder="0">

      <label class="mf-label">카테고리</label>
      <div class="cat-row-wrap">
        <select class="mf-select" id="fCat"></select>
        <button class="cat-add-btn" onclick="toggleNewCat()" title="카테고리 편집">＋</button>
      </div>
      <!-- 새 카테고리 입력 -->
      <div class="new-cat-box" id="newCatBox">
        <div class="new-cat-row">
          <input class="new-cat-emoji" id="ncEmoji" type="text" maxlength="2" placeholder="😀">
          <input class="new-cat-name"  id="ncName"  type="text" placeholder="카테고리 이름">
          <button class="new-cat-save" onclick="saveNewCat()">추가</button>
        </div>
      </div>

      <label class="mf-label">내용 / 메모</label>
      <div class="desc-row">
        <input class="mf-input" id="fDesc" type="text" placeholder="예) 편의점, 버스">
        <button class="photo-btn" onclick="document.getElementById('photoInput').click()" title="사진 첨부">📷</button>
        <input type="file" id="photoInput" accept="image/*" style="display:none" onchange="onPhotoSelect(this)">
      </div>
      <!-- 사진 미리보기 (다중) -->
      <div id="photoGrid" style="display:none">
        <div class="photo-grid" id="photoGridItems"></div>
      </div>

      <label class="mf-label">결제수단</label>
      <select class="mf-select" id="fPay">
        <option value="현금">💵 현금</option>
        <option value="신용카드">💳 신용카드</option>
        <option value="체크카드">🏦 체크카드</option>
        <option value="계좌이체">🏧 계좌이체</option>
        <option value="카카오페이">🟡 카카오페이</option>
        <option value="네이버페이">🟢 네이버페이</option>
        <option value="토스">🔵 토스</option>
        <option value="기타">📌 기타</option>
      </select>

      <label class="mf-label">날짜</label>
      <input class="mf-input" id="fDate" type="date">
    </div>
    <button class="modal-save" onclick="saveTx()">저장</button>
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
  { id: 'survival',  label: '서바이벌 가이드',  icon: '💰' },
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
    const budgets = g.budgets || { week: g.budget||0, month:{}, year:{} };
    if (!budgets.month) budgets.month = {};
    if (!budgets.year)  budgets.year  = {};
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
  if (survGoal.mode === 'week')  return b.week || 0;
  if (survGoal.mode === 'year')  return (b.year[k])  || 0;
  return (b.month[k]) || 0;
}
// 현재 슬롯 예산 쓰기
function _setSurvBudget(val) {
  const b = survGoal.budgets, k = _survKey();
  if (survGoal.mode === 'week')       b.week     = val;
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
}
function persist()     { localStorage.setItem(SK,      JSON.stringify(txs)); }
function persistCats() { localStorage.setItem(CATS_SK, JSON.stringify(customCats)); }

// ── 유틸 ──────────────────────────────────────────────────────
const fmt = n => '₩' + Math.abs(n).toLocaleString('ko-KR');
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
  return `<div class="tx-row" onclick="${extraOnclick||''}openTxAction('${t.id}')">
    <div class="tx-icon">${getIcon(t.category)}</div>
    <div class="tx-info">
      <div class="tx-desc">${esc(t.description||t.category)}</div>
      <div class="tx-cat">${esc(t.category)}${t.payment?` · ${esc(t.payment)}`:''}</div>
    </div>
    <div class="tx-right">
      ${thumb}
      <div class="tx-amt ${t.type}">${t.type==='income'?'+':'-'}${fmt(t.amount)}</div>
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
  document.getElementById('monthNav').style.display      = isMe ? 'none' : 'flex';
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
  const nd = new Date(y, m-1+d, 1);
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
  if (statsPeriod === 'month') return `${y}년 ${parseInt(m)}월`;
  if (statsPeriod === 'year')  return `${y}년 전체`;
  const {from: wf, to: wt} = getWeekRange();
  const pad = s => s.slice(5).replace('-','.');
  return `${pad(wf)}~${pad(wt)}`;
}

function setMonthLabel() {
  const active = TABS.find(t => document.getElementById('pane-'+t)?.classList.contains('active'));
  if (active === 'stats') {
    document.getElementById('monthLabel').textContent = getStatsHeaderLabel();
  } else {
    const [y,m] = curMonth.split('-');
    document.getElementById('monthLabel').textContent = y+'년 '+parseInt(m)+'월';
  }
  // 챔피언 카드 월 네비 동기화
  const [ry,rm] = curMonth.split('-');
  const now = new Date();
  const nowYM = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  const cLabel = document.getElementById('rChampMLabel');
  if (cLabel) cLabel.textContent = ry+'년 '+parseInt(rm)+'월';
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
  document.getElementById('fPay').value = '현금';
  photosData = [];
  renderPhotoGrid();
  document.getElementById('newCatBox').classList.remove('show');
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
  bEl.style.color = bal<0 ? '#ef9a9a' : '#fff';

  if (!list.length) {
    document.getElementById('txList').innerHTML =
      '<div class="empty-msg">이번 달 내역이 없어요 😊<br>아래 <b>＋</b> 버튼으로 추가하세요!</div>';
    return;
  }
  const grouped = {};
  list.forEach(t => (grouped[t.date]=grouped[t.date]||[]).push(t));
  const dates = Object.keys(grouped).sort().reverse();
  document.getElementById('txList').innerHTML = dates.map(date=>{
    const rows = grouped[date];
    const dExp = rows.filter(t=>t.type==='expense').reduce((s,t)=>s+t.amount,0);
    const dInc = rows.filter(t=>t.type==='income').reduce((s,t)=>s+t.amount,0);
    const [,,dd] = date.split('-');
    const dow = ['일','월','화','수','목','금','토'][new Date(date).getDay()];
    let dayTotal='';
    if (dInc) dayTotal+=`<span style="color:#80cbc4">+${fmt(dInc)}</span> `;
    if (dExp) dayTotal+=`<span style="color:#ef9a9a">-${fmt(dExp)}</span>`;
    return `<div class="date-group">
      <div class="date-header"><span>${parseInt(dd)}일 (${dow})</span><span>${dayTotal}</span></div>
      ${rows.map(t=>txRowHtml(t)).join('')}
    </div>`;
  }).join('');
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
  if (statsPeriod === 'month') return `${y}년 ${parseInt(m)}월`;
  if (statsPeriod === 'year')  return `${y}년 전체`;
  const today = new Date();
  const from  = new Date(today); from.setDate(from.getDate()-6);
  return `${from.getMonth()+1}/${from.getDate()} ~ ${today.getMonth()+1}/${today.getDate()}`;
}

function renderStats() {
  const typeLabel = statsType === 'expense' ? '지출' : '수입';
  const grpLabel  = statsGroupBy === 'category' ? '카테고리' : '결제수단';
  const palette   = statsType === 'expense' ? COLORS_EXPENSE : COLORS_INCOME;
  const amtColor  = statsType === 'expense' ? '#e53935' : '#36A2EB';

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
  document.getElementById('donutCenterLabel').textContent = '총 ' + typeLabel;
  document.getElementById('rankingHeaderTitle').textContent = `${grpLabel}별 ${typeLabel} 순위`;

  if (!sorted.length) {
    emptyEl.innerHTML = `<div class="stats-empty-state">
      <div class="stats-empty-icon">📊</div>
      <div class="stats-empty-title">이 기간에는 내역이 없어요!</div>
      <div class="stats-empty-sub">${getStatsHeaderLabel()} 기간의<br>${typeLabel} 내역을 추가해 보세요</div>
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

  const labels  = sorted.map(([k]) => k);
  const amounts = sorted.map(([,v]) => v);
  const colors  = sorted.map((_,i) => palette[i % palette.length]);

  const ctx = document.getElementById('donutCanvas').getContext('2d');
  if (donutChart) donutChart.destroy();
  donutChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data: amounts, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 8, hoverBorderWidth: 0 }]
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
            const ttl = chart.data.datasets[0].data.reduce((a,b)=>a+b,0);
            const pct = Math.round(dp.raw / ttl * 100);
            el.innerHTML = `<span class="dtt-name">${dp.label}</span><span class="dtt-amt">${fmt(dp.raw)}</span><span class="dtt-pct">${pct}%</span>`;
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

  document.getElementById('rankingList').innerHTML = sorted.map(([k, amt], i) => {
    const pct      = Math.round(amt / total * 100);
    const color    = colors[i];
    const numClass = i < 3 ? 'rank-num top' : 'rank-num';
    return `<div class="ranking-item" id="rank-item-${i}"
        onclick="highlightChartSlice(${i});openGroupDetail('${esc(k)}')">
      <div class="${numClass}">${i+1}</div>
      <div class="rank-dot" style="background:${color}"></div>
      <div class="rank-icon">${getIco(k)}</div>
      <div class="rank-info">
        <div class="rank-name">${esc(k)}</div>
        <div class="rank-bar-wrap"><div class="rank-bar" style="width:${pct}%;background:${color}"></div></div>
      </div>
      <div class="rank-right">
        <div class="rank-pct">${pct}%</div>
        <div class="rank-amt" style="color:${amtColor}">${fmt(amt)}</div>
      </div>
    </div>`;
  }).join('');
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
  document.getElementById('catdetTitle').textContent = `${getIco(key)} ${key}  ${fmt(total)}`;
  document.getElementById('catdetBody').innerHTML = rows.length
    ? rows.map(t => txRowHtml(t)).join('')
    : '<div class="search-empty">내역이 없어요</div>';
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
    <div class="champ-header"><span class="champ-header-label">🏆 최고 지출</span></div>
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
        <button class="champ-feel-btn" id="rFeelOk"     onclick="setChampFeel('ok')">😊 만족해요</button>
        <button class="champ-feel-btn" id="rFeelRegret" onclick="setChampFeel('regret')">💸 아까워요</button>
      </div>
    </div>
  </div>`;
}
function widgetDowHTML() {
  return `<div class="widget-card" id="rDowCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="dow-body">
      <div class="dow-title">📅 요일별 소비 패턴</div>
      <div class="dow-bars"    id="rDowBars"></div>
      <div class="dow-insight" id="rDowInsight"></div>
    </div>
  </div>`;
}
function widgetSurvivalHTML() {
  return `<div class="widget-card" id="rSurvivalCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="surv-header" id="rSurvHeader"><span class="surv-header-label">💰 서바이벌 가이드</span></div>
    <div class="surv-body">
      <div class="surv-tabs">
        <button class="surv-tab" id="survTab-week"  onclick="setSurvMode('week')">주</button>
        <button class="surv-tab" id="survTab-month" onclick="setSurvMode('month')">월</button>
        <button class="surv-tab" id="survTab-year"  onclick="setSurvMode('year')">년</button>
      </div>
      <div class="surv-period-nav">
        <button class="surv-period-btn" onclick="survNav(-1)">‹</button>
        <span class="surv-period-label" id="rSurvNavLabel"></span>
        <button class="surv-period-btn" id="rSurvNavNext" onclick="survNav(1)">›</button>
      </div>
      <div class="surv-input-row">
        <span class="surv-input-label">목표 예산</span>
        <input class="surv-input" id="rSurvInput" type="text" inputmode="numeric" placeholder="미설정"
               oninput="onSurvBudgetInput(this)" onblur="saveSurvBudget()" onkeydown="if(event.key==='Enter')this.blur()">
        <span class="surv-input-unit">원</span>
      </div>
      <div class="surv-progress-wrap"><div class="surv-progress-bar" id="rSurvBar" style="width:0%"></div></div>
      <div class="surv-progress-labels">
        <span id="rSurvUsedPct">목표 미설정</span>
        <span id="rSurvPeriodLabel"></span>
      </div>
      <div class="surv-remaining" id="rSurvRemLabel">변동 지출 합계</div>
      <div class="surv-remaining-amt" id="rSurvAmt">₩0</div>
      <div class="surv-divider"></div>
      <div class="surv-msg" id="rSurvMsg">계산 중...</div>
    </div>
  </div>`;
}
function widgetTop3HTML() {
  return `<div class="widget-card" id="rTop3Card">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="top3-header"><span class="top3-header-label">🥇 카테고리 TOP 3</span></div>
    <div class="top3-body" id="rTop3Body"></div>
  </div>`;
}
function widgetMbtiHTML() {
  return `<div class="widget-card" id="rMbtiCard">
    <button class="widget-menu-btn" onclick="openWidgetAction(event, this.closest('.widget-card').id)">···</button>
    <div class="mbti-header"><span class="mbti-header-label">🧬 나의 소비 MBTI</span></div>
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
    <div class="edit-panel-title">＋ 항목 추가</div>
    ${hidden.map(w => `<div class="edit-row" onclick="addWidgetById('${w.id}')">
      <span class="edit-row-icon">${w.icon}</span>
      <span class="edit-row-label">${w.label}</span>
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
      <div class="report-empty-msg">아직 분석 항목이 없어요.<br>아래 토글을 켜서 추가해보세요! ✨</div>
    </div>`;
  } else {
    reportWidgets.forEach(id => { if (widgetMap[id]) html += widgetMap[id](); });
  }
  if (reportEditMode) html += editPanelHTML();

  wrap.innerHTML = html;
  renderReportEditBar();

  // 데이터 채우기
  const prev     = prevMonth(curMonth);
  const [,tm]    = curMonth.split('-');
  const [,pm]    = prev.split('-');
  const thisExps = monthOf(curMonth).filter(t => t.type === 'expense');
  const prevExps = monthOf(prev).filter(t => t.type === 'expense');
  const thisExp  = thisExps.reduce((s,t) => s + t.amount, 0);
  const prevExp  = prevExps.reduce((s,t) => s + t.amount, 0);
  const name     = USER_NAME || '사용자';

  if (reportWidgets.includes('insight')) {
    const diff = thisExp - prevExp;
    const pct  = prevExp > 0 ? Math.round(Math.abs(diff) / prevExp * 100) : null;

    let emoji, insight, sub, tag, tagClass;

    if (prevExp === 0 && thisExp === 0) {
      // 데이터 없음 — 첫 시작
      emoji = '🌱'; tagClass = 'neutral'; tag = '#기록시작';
      insight = `${name}님, 아직 이번 달 지출이 없어요!`;
      sub = '오늘부터 소비를 기록해봐요 ✏️';

    } else if (prevExp === 0) {
      // 지난달 0원 — 비율 계산 불가
      emoji = '🎉'; tagClass = 'neutral'; tag = '#이번달첫기록';
      insight = `${name}님, 이번 달은 현재까지 총 ${fmt(thisExp)}을 사용하셨네요!`;
      sub = '지난달 데이터가 없어 증감은 다음 달부터 확인할 수 있어요 📖';

    } else if (diff === 0) {
      emoji = '😐'; tagClass = 'neutral'; tag = '#균형유지';
      insight = `${name}님, 지난 달과 딱 같은 금액을 쓰셨어요.`;
      sub = '균형 잡힌 소비 패턴이네요.';

    } else if (diff < 0) {
      // 지출 감소
      if (pct >= 30) {
        emoji = '💚'; tagClass = 'good'; tag = '#알뜰살뜰';
        insight = `${name}님, 지난달보다 ${pct}% 확 줄었어요! 절약 고수시네요 👑`;
        sub = `${fmt(-diff)} 절약! 이대로 쭉 이어가봐요 💚`;
      } else if (pct >= 10) {
        emoji = '🎉'; tagClass = 'good'; tag = '#절약성공';
        insight = `${name}님, 지난달보다 ${pct}% 아껴 쓰셨어요! 대단해요 👏`;
        sub = `${fmt(-diff)} 절약했어요. 이대로 쭉!`;
      } else {
        emoji = '🙂'; tagClass = 'good'; tag = '#절약중';
        insight = `${name}님, 지난달보다 ${pct}% 줄었어요. 잘 하고 계세요!`;
        sub = `${fmt(-diff)} 절약했어요.`;
      }

    } else {
      // 지출 증가
      if (pct >= 50) {
        emoji = '🔴'; tagClass = 'danger'; tag = '#과소비경보';
        insight = `${name}님, 지출이 지난달보다 ${pct}% 급증했어요! 지갑이 비상이에요 😰`;
        sub = `지난달보다 ${fmt(diff)} 더 지출했어요. 점검이 필요해요!`;
      } else if (pct >= 30) {
        emoji = '📈'; tagClass = 'danger'; tag = '#지출급증';
        insight = `${name}님, 이번 달 지출이 지난달보다 ${pct}% 늘었어요. 지갑이 많이 얇아졌네요 😅`;
        sub = `지난달보다 ${fmt(diff)} 더 지출했어요.`;
      } else if (pct >= 10) {
        emoji = '⚠️'; tagClass = 'warn'; tag = '#지출주의';
        insight = `${name}님, 이번 달은 지난달보다 ${pct}% 더 쓰셨어요. 조금만 더 조절해봐요! 💪`;
        sub = `지난달보다 ${fmt(diff)} 더 지출했어요.`;
      } else {
        emoji = '📊'; tagClass = 'warn'; tag = '#소폭증가';
        insight = `${name}님, 지난달보다 ${pct}% 소폭 늘었어요. 아직 괜찮아요!`;
        sub = `지난달보다 ${fmt(diff)} 더 지출했어요.`;
      }
    }

    const tagEl = document.getElementById('rInsightTag');
    tagEl.textContent = tag;
    tagEl.className   = `rc-tag ${tagClass}`;
    document.getElementById('rLabelPrev').textContent = parseInt(pm) + '월 지출';
    document.getElementById('rLabelThis').textContent = parseInt(tm) + '월 지출';
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
    if (cLabel) cLabel.textContent = cy+'년 '+parseInt(cm)+'월';
    const cNext = document.getElementById('rChampMNext');
    const nowYM2 = new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0');
    if (cNext) cNext.disabled = curMonth >= nowYM2;

    const champ = thisExps.length ? thisExps.reduce((a,b) => a.amount >= b.amount ? a : b) : null;
    document.getElementById('rChampEmoji').textContent = champ ? getIcon(champ.category) : '💸';
    document.getElementById('rChampName').textContent  = champ ? (champ.description || champ.category) : '이번 달 지출 내역이 없어요';
    document.getElementById('rChampCat').textContent   = champ ? champ.category + (champ.payment ? ' · ' + champ.payment : '') : '';
    document.getElementById('rChampAmt').textContent   = champ ? fmt(champ.amount) : '₩0';
    document.getElementById('rChampDate').textContent  = champ ? champ.date.replace(/-/g,'.') + ' 지출' : '';
    const pctEl = document.getElementById('rChampPct');
    const feelBtns = document.getElementById('rChampFeelBtns');
    if (champ && thisExp > 0) {
      const pct = Math.round(champ.amount / thisExp * 100);
      pctEl.innerHTML = `이번 달 총 지출의 <span>${pct}%</span>가 이 한 번의 결제에서!`;
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
      {label:'월', js:1},
      {label:'화', js:2},
      {label:'수', js:3},
      {label:'목', js:4},
      {label:'금', js:5},
      {label:'토', js:6},
      {label:'일', js:0},
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
      const tipText = dowSum[i] > 0 ? `${s.label} ${fmt(dowSum[i])}` : `${s.label} 지출없음`;
      return `<div class="dow-bar-wrap" data-tip="${tipText}"
        onmouseenter="showDowTip(event,this)" onmouseleave="hideDowTip()"
        ontouchstart="showDowTip(event,this)" ontouchend="hideDowTipDelay()">
        ${crown}
        <div class="dow-bar${cls}" style="height:${h}px"></div>
        <div class="dow-bar-label${cls}">${s.label}</div>
      </div>`;
    }).join('');

    // 요일별 맞춤 멘트 (슬롯 인덱스: 0=월,1=화,2=수,3=목,4=금,5=토,6=일)
    const DOW_MSG = [
      `한 주의 시작부터 에너지를 많이 쓰셨네요!<br>월요병을 소비로 이겨내셨나요? 😂`,
      `평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱`,
      `평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱`,
      `평일의 꾸준한 소비가 쌓이고 있어요.<br>자잘한 지출만 줄여도 이번 달은 성공이에요! 🌱`,
      `신나는 불금! 주말의 시작과 함께 지출이 터졌네요 🍺`,
      `주말 FLEX 주의보! 🚨<br>예산 안에서 즐겨보는 건 어떨까요?`,
      `일요일 지출이 가장 커요!<br>내일부터 시작될 한 주를 위해 오늘은 조금 아껴봐요 🏠`,
    ];
    let dowInsight;
    if (!thisExps.length) {
      dowInsight = '아직 이번 달 지출 데이터가 없어요.';
    } else if (dowSum[peakIdx] === 0) {
      dowInsight = '지출이 골고루 분포되어 있어요!';
    } else {
      dowInsight = `이번 달 <b>${SLOTS[peakIdx].label}요일</b> 지출이 가장 많아요`
        + `<br><span class="hi-amt">${fmt(dowSum[peakIdx])}</span>`
        + ` <span class="lo-cnt">(총 ${dowCnt[peakIdx]}건)</span>`
        + `<br>${DOW_MSG[peakIdx]}`;
    }
    document.getElementById('rDowInsight').innerHTML = dowInsight;
  }

  // ── 서바이벌 가이드 ─────────────────────────────────────────
  if (reportWidgets.includes('survival')) fillSurvival();

  // ── 소비 MBTI ────────────────────────────────────────────────
  if (reportWidgets.includes('mbti')) {
    const MBTI_TYPES = [
      { keys:['식비','음식','밥','카페','커피','간식','분식'],     code:'EATJ', title:'미식가형',       emoji:'🍜', desc:'먹는 게 남는 거! 오늘도 맛집 탐방 중인 타입이에요. 지갑이 열리는 건 음식 앞에서뿐!' },
      { keys:['쇼핑','패션','의류','잡화','마트','백화점'],        code:'SHOP', title:'트렌드세터형',    emoji:'🛍️', desc:'쇼핑은 힐링! 눈 깜짝할 새 카트가 가득 차는 타입이에요. 오늘도 장바구니 투어 중?' },
      { keys:['문화','여가','영화','취미','레저','공연','게임'],    code:'YOLO', title:'욜로라이프형',    emoji:'🎭', desc:'경험에 아낌없이 투자! 인생은 한 번이니까요. 추억이 최고의 재테크예요!' },
      { keys:['교통','이동','주유','택시','지하철','버스','항공'], code:'MOVE', title:'무브먼트형',      emoji:'🚗', desc:'항상 어딘가로 이동 중! 바쁘고 활동적인 타입이에요. 오늘도 달리는 중이죠?' },
      { keys:['의료','건강','병원','약','헬스','피부'],            code:'HLTH', title:'건강제일형',      emoji:'💊', desc:'건강이 최우선! 몸 관리에 투자를 아끼지 않는 타입이에요. 건강이 진짜 재산이죠!' },
      { keys:['통신','인터넷','구독','스트리밍','디지털'],         code:'DIGI', title:'디지털노마드형',  emoji:'📱', desc:'디지털 라이프의 달인! 구독 서비스 없인 못 사는 타입이에요. 오늘도 스트리밍 중?' },
      { keys:['주거','생활','관리비','인테리어','가구'],           code:'HOME', title:'홈베이스형',      emoji:'🏠', desc:'집이 제일 좋아! 나만의 공간 꾸미는 데 진심인 타입이에요. 오늘도 홈카페 중?' },
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
        mbtiType = { code:'????', title:'데이터 수집 중', emoji:'🔍', desc:'이번 달 지출 내역을 추가하면 나만의 소비 MBTI가 분석돼요!' };
      } else {
        mbtiType = { code:'FREE', title:'자유분방형', emoji:'🌈', desc:'정해진 패턴 없이 자유롭게! 다양한 곳에 고루 지출하는 유연한 타입이에요.' };
      }
    }

    // 예산 수식어
    const thisInc2 = monthOf(curMonth).filter(t => t.type === 'income').reduce((s,t) => s + t.amount, 0);
    let budgetLine = '';
    if (thisInc2 > 0) {
      const ur = thisExp / thisInc2;
      if (ur < 0.6)       budgetLine = '절약 능력까지 갖춘 완벽한 소비러예요! 💪';
      else if (ur < 0.8)  budgetLine = '적당한 균형감각을 가진 소비러예요 👍';
      else if (ur <= 1.0) budgetLine = '거의 한계선! 조금만 더 아껴봐요 😅';
      else                budgetLine = '예산 초과! 다음 달엔 절약 모드 고고 😰';
    }

    document.getElementById('rMbtiEmoji').textContent = mbtiType.emoji;
    document.getElementById('rMbtiCode').textContent  = mbtiType.code;
    document.getElementById('rMbtiTitle').textContent = mbtiType.title;
    document.getElementById('rMbtiDesc').textContent  = mbtiType.desc;
    const budgetEl = document.getElementById('rMbtiBudget');
    if (budgetLine) { budgetEl.textContent = budgetLine; budgetEl.style.display = ''; }
    else            { budgetEl.style.display = 'none'; }

    // TOP 3 미니 배지
    const top3badges = sorted.slice(0, 3).map(([cat]) =>
      `<span class="mbti-badge">${getIcon(cat)} ${cat}</span>`
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
      body.innerHTML = `<div class="top3-empty">이번 달 지출 내역이 없어요</div>`;
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
            <div class="top3-cat">${getIcon(cat)} ${cat}</div>
            <div class="top3-sub">${cnt}건</div>
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
    ${reportEditMode ? '✅ 편집 완료' : '✏️ 분석 항목 편집'}
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
  el.textContent = streak > 0 ? `🔥 연속 기록 ${streak}일` : '아직 기록을 시작해봐요!';
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

// ── 서바이벌 가이드 로직 ────────────────────────────────────
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
  if (!budget && survGoal.mode === 'month') {
    // 자체 monthOffset으로 계산한 ym 사용
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
  if (pctEl) pctEl.textContent = budget > 0 ? `지출 ${Math.round(usageRate*100)}%` : '목표 미설정';
  const prdEl = document.getElementById('rSurvPeriodLabel');
  if (prdEl) prdEl.textContent = navLabel;

  // 금액 표시
  const amtEl = document.getElementById('rSurvAmt');
  if (amtEl) {
    amtEl.textContent = budget > 0 ? fmt(Math.abs(remaining)) : fmt(spent);
    amtEl.className = 'surv-remaining-amt' + ((remaining >= 0 || !budget) ? ' positive' : ' negative');
  }
  const remLbl = document.getElementById('rSurvRemLabel');
  if (remLbl) {
    if (!budget)                      remLbl.textContent = '변동 지출 합계 (고정비 제외)';
    else if (isDanger && remaining>0) remLbl.textContent = '🚨 예산 위험! 조금만 더 아껴써요';
    else if (remaining >= 0)          remLbl.textContent = '남은 예산';
    else                              remLbl.textContent = '초과 지출';
  }

  // 메시지
  const msgEl = document.getElementById('rSurvMsg');
  if (msgEl) {
    let msg;
    if (!budget) {
      msg = '목표 예산을 입력하면<br>하루 가용 금액을 알려드려요 💡';
    } else if (remaining <= 0) {
      msg = `${navLabel} 목표를 <b>${fmt(-remaining)}</b> 초과했어요 😰`;
    } else if (isPast || daysLeft === 0) {
      msg = `${navLabel} 예산 <b>${fmt(remaining)}</b> 절약했어요! 🎉`;
    } else if (isDanger) {
      msg = `남은 <b>${daysLeft}일</b> 동안 매일 <b>${fmt(Math.floor(remaining/daysLeft))}</b>씩만 써야 해요 🚨`;
    } else {
      msg = `남은 <b>${daysLeft}일</b> 동안 매일 <b>${fmt(Math.floor(remaining/daysLeft))}</b>씩 사용 가능해요! 💰`;
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
    `<div class="wpop-item${idx<=0?' disabled':''}" onclick="widgetMove(-1)">↑ 위로 이동</div>` +
    `<div class="wpop-item${idx>=reportWidgets.length-1?' disabled':''}" onclick="widgetMove(1)">↓ 아래로 이동</div>` +
    `<div class="wpop-item danger" onclick="widgetDeleteActive()">🗑️ 삭제</div>`;
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
  document.getElementById('fPay').value='현금';
  photosData=[];
  renderPhotoGrid();
  document.getElementById('newCatBox').classList.remove('show');
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
  document.getElementById('fAmt').value = t.amount;
  document.getElementById('fDesc').value = (t.description && t.description !== t.category) ? t.description : '';
  document.getElementById('fDate').value = t.date;
  document.getElementById('fPay').value = t.payment || '현금';
  // 카테고리 선택 (커스텀 포함)
  setTimeout(()=>{ document.getElementById('fCat').value = t.category; }, 0);
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

function buildCatSelect(type) {
  const el = document.getElementById('fCat');
  if (!el) return;
  let opts;
  if (IS_LOGGED_IN) {
    const cats = dbCats[type] || [];
    if (cats.length === 0) {
      // DB 카테고리 없으면 BASE_CATS 폴백
      const base = BASE_CATS[type].map(c => `<option value="${c}">${BASE_ICONS[c]||'📦'} ${c}</option>`);
      el.innerHTML = base.join('');
      return;
    }
    opts = cats.map(c => `<option value="${c.name}">${c.icon||'📦'} ${c.name}</option>`);
  } else {
    const base   = BASE_CATS[type].map(c => `<option value="${c}">${BASE_ICONS[c]||'📦'} ${c}</option>`);
    const custom = (customCats[type]||[]).map(c => `<option value="${c.name}">${c.emoji||'📦'} ${c.name}</option>`);
    opts = [...base, ...custom];
  }
  el.innerHTML = opts.join('');
}

// ── 커스텀 카테고리 (로그인 시 catEdit 모달로, 비로그인 시 inline) ──
function toggleNewCat() {
  if (IS_LOGGED_IN) {
    // 로그인: 카테고리 편집 모달로 이동
    closeModal();
    openCatEditModal();
  } else {
    document.getElementById('newCatBox').classList.toggle('show');
  }
}
function saveNewCat() {
  // 비로그인 전용 inline 추가
  const emoji = document.getElementById('ncEmoji').value.trim() || '📦';
  const name  = document.getElementById('ncName').value.trim();
  if (!name) { showToast('카테고리 이름을 입력해주세요'); return; }
  if (!customCats[curType]) customCats[curType] = [];
  if (customCats[curType].find(c => c.name === name)) { showToast('이미 있는 카테고리예요'); return; }
  customCats[curType].push({ emoji, name });
  persistCats();
  buildCatSelect(curType);
  document.getElementById('fCat').value = name;
  document.getElementById('ncEmoji').value = '';
  document.getElementById('ncName').value  = '';
  document.getElementById('newCatBox').classList.remove('show');
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

// ── 저장 ─────────────────────────────────────────────────────
function saveTx() {
  const amt  = parseInt(document.getElementById('fAmt').value);
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
  document.getElementById('txaSummary').innerHTML = `
    <div class="txa-summary">
      <div class="txa-icon">${getIcon(t.category)}</div>
      <div class="txa-mid">
        <div class="txa-desc">${esc(t.description||t.category)}</div>
        <div class="txa-sub">${esc(t.category)}${t.payment?' · '+esc(t.payment):''} · ${t.date}</div>
      </div>
      <div class="txa-amt ${t.type}">${t.type==='income'?'+':'-'}${fmt(t.amount)}</div>
    </div>
    ${photos.length ? buildCarousel(photos, 'txa') : ''}
  `;
  if (photos.length > 1) initCarousel('txa');
  document.getElementById('txaOverlay').classList.add('show');
}
function closeTxaOverlay(e) {
  if (e.target === document.getElementById('txaOverlay'))
    document.getElementById('txaOverlay').classList.remove('show');
}
function showTxDetail() {
  const t = txs.find(x => x.id === activeTxId);
  if (!t) return;
  const typeLabel = t.type==='expense'?'지출':'수입';
  document.getElementById('detailBody').innerHTML = `
    <div class="detail-row"><span class="detail-key">유형</span><span class="detail-val" style="color:${t.type==='expense'?'#e53935':'#00BCD4'}">${typeLabel}</span></div>
    <div class="detail-row"><span class="detail-key">금액</span><span class="detail-val" style="color:${t.type==='expense'?'#e53935':'#00BCD4'}">${t.type==='income'?'+':'-'}${fmt(t.amount)}</span></div>
    <div class="detail-row"><span class="detail-key">카테고리</span><span class="detail-val">${getIcon(t.category)} ${esc(t.category)}</span></div>
    <div class="detail-row"><span class="detail-key">내용</span><span class="detail-val">${esc(t.description||'-')}</span></div>
    <div class="detail-row"><span class="detail-key">결제수단</span><span class="detail-val">${esc(t.payment||'-')}</span></div>
    <div class="detail-row"><span class="detail-key">날짜</span><span class="detail-val">${t.date}</span></div>
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
  el.innerHTML = fixedItems.map(f => `
    <div style="display:flex;align-items:center;padding:11px 16px;border-bottom:1px solid #f5f5f5;gap:10px">
      <span style="font-size:20px">${f.type==='income'?'💰':'📌'}</span>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;color:#212121">${esc(f.name)}</div>
        <div style="font-size:12px;color:#9e9e9e;margin-top:2px">${fxCycleLabel(f)} · ${f.type==='income'?'수입':'지출'}</div>
      </div>
      <span style="font-size:14px;font-weight:700;color:${f.type==='income'?'#00BCD4':'#e53935'};white-space:nowrap">${f.type==='income'?'+':'-'}${fmt(f.amount)}</span>
      <button onclick="deleteFixed(${f.id})"
        style="background:none;border:1px solid #eee;border-radius:6px;color:#bdbdbd;cursor:pointer;padding:5px 8px;font-size:14px">🗑</button>
    </div>
  `).join('');
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
  el.innerHTML = cats.map(c => `
    <div style="display:flex;align-items:center;padding:11px 8px;border-bottom:1px solid #f5f5f5;gap:10px">
      <span style="font-size:22px;width:32px;text-align:center;flex-shrink:0">${esc(c.icon||c.emoji||'📦')}</span>
      <span style="flex:1;font-size:15px;color:#212121">${esc(c.name)}</span>
      <button onclick="deleteCatEditItem(${c.id||0},'${esc(c.name)}')"
        style="background:none;border:1px solid #eee;cursor:pointer;color:#bdbdbd;font-size:15px;padding:5px 9px;border-radius:6px;line-height:1">🗑</button>
    </div>
  `).join('');
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

// ── 초기화 ───────────────────────────────────────────────────
load();
loadFixed();
applyDarkMode();
startNotifScheduler();
setMonthLabel();
// DB 카테고리를 먼저 로드한 뒤 렌더 (로그인 상태에서 카테고리 즉시 반영)
loadDbCats(() => {
  renderLedger();
  autoApplyFixed();
});
</script>
</body>
</html>
