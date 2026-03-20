<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = !empty($_SESSION['user_id']);
$userName   = htmlspecialchars($_SESSION['user_name']  ?? '');
$userEmail  = htmlspecialchars($_SESSION['user_email'] ?? '');
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
.stats-type-toggle { display: flex; margin: 14px 16px 0; border-radius: 10px; overflow: hidden; border: 1.5px solid #e0e0e0; }
.st-btn { flex: 1; padding: 11px 0; border: none; background: #f5f5f5; font-size: 15px; font-weight: 700; color: #9e9e9e; cursor: pointer; font-family: inherit; transition: all .2s; }
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
.donut-section { margin: 14px 16px 0; background: #fff; border-radius: 16px; padding: 20px 16px 16px; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.donut-period-label { text-align: center; font-size: 12px; color: #9e9e9e; margin-bottom: 12px; }
.donut-canvas-wrap { position: relative; width: 220px; margin: 0 auto; }
.donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.donut-center-label { font-size: 11px; color: #9e9e9e; }
.donut-center-amt { font-size: 19px; font-weight: 700; color: #212121; margin-top: 3px; }
.donut-empty { text-align: center; padding: 50px 20px; color: #bdbdbd; font-size: 15px; line-height: 1.8; }
.ranking-section { margin: 10px 16px 0; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
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

/* ── 보고서 ── */
.compare-row { display: flex; align-items: center; gap: 8px; }
.compare-col { flex: 1; text-align: center; }
.compare-label { font-size: 11px; color: #9e9e9e; margin-bottom: 5px; }
.compare-val { font-size: 19px; font-weight: 700; }
.compare-val.up   { color: #e53935; }
.compare-val.down { color: #00BCD4; }
.compare-arrow { color: #bdbdbd; font-size: 18px; flex-shrink: 0; }
.diff-msg { margin-top: 14px; padding-top: 13px; border-top: 1px solid #f0f0f0; text-align: center; font-size: 13px; color: #616161; }
.diff-msg .up   { color: #e53935; font-weight: 700; }
.diff-msg .down { color: #00BCD4; font-weight: 700; }
.cat-compare-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f5f5f5; font-size: 13px; }

/* ── 나 ── */
.profile-area { background: #455A64; color: #fff; padding: 28px 20px 24px; display: flex; align-items: center; gap: 14px; }
.profile-avatar { width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0; }
.profile-name  { font-size: 17px; font-weight: 700; }
.profile-email { font-size: 12px; opacity: .75; margin-top: 3px; }
.login-link { display: inline-block; margin-top: 10px; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4); color: #fff; border-radius: 20px; padding: 5px 16px; font-size: 13px; text-decoration: none; font-weight: 600; }
.me-list { margin-top: 6px; }
.me-item { display: flex; align-items: center; gap: 14px; padding: 15px 20px; background: #fff; border-bottom: 1px solid #f0f0f0; font-size: 15px; cursor: pointer; }
.me-item:active { background: #f5f5f5; }
.me-item span { font-size: 20px; }
.me-item.danger { color: #e53935; }

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
</style>
</head>
<body>

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

<!-- ④ 보고서 탭 -->
<div class="tab-pane" id="pane-report">
  <div class="section-box">
    <div class="section-title">이번 달 vs 지난 달 지출</div>
    <div class="compare-row">
      <div class="compare-col"><div class="compare-label" id="rLabelPrev"></div><div class="compare-val" id="rValPrev">₩0</div></div>
      <div class="compare-arrow">→</div>
      <div class="compare-col"><div class="compare-label" id="rLabelThis"></div><div class="compare-val" id="rValThis">₩0</div></div>
    </div>
    <div class="diff-msg" id="rDiff"></div>
  </div>
  <div class="section-box">
    <div class="section-title">카테고리별 비교</div>
    <div id="rCatCmp"></div>
  </div>
</div>

<!-- ⑤ 나 탭 -->
<div class="tab-pane" id="pane-me">
  <div class="profile-area">
    <div class="profile-avatar">👤</div>
    <div>
      <?php if ($isLoggedIn): ?>
        <div class="profile-name"><?=$userName?>님</div>
        <div class="profile-email"><?=$userEmail?></div>
      <?php else: ?>
        <div class="profile-name">비로그인 상태</div>
        <div class="profile-email">로그인하면 서버에 동기화됩니다</div>
        <a href="login.php" class="login-link">로그인 / 회원가입</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="me-list">
    <?php if ($isLoggedIn): ?>
    <div class="me-item danger" onclick="location.href='logout.php'"><span>🚪</span> 로그아웃</div>
    <?php endif; ?>
    <div class="me-item" onclick="doDeleteAll()"><span>🗑️</span> 전체 내역 삭제</div>
  </div>
</div>

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
      <div class="daterange-presets">
        <button class="preset-btn" onclick="setPreset('week')">이번 주</button>
        <button class="preset-btn" onclick="setPreset('month')">이번 달</button>
        <button class="preset-btn" onclick="setPreset('last-month')">지난 달</button>
      </div>
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
  <button class="t-btn"    id="tb-report" onclick="goTab('report')"><span class="ico">📋</span>보고서</button>
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
        <button class="cat-add-btn" onclick="toggleNewCat()" title="카테고리 추가">＋</button>
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
let statsType        = 'expense';
let statsGroupBy     = 'category';   // 'category' | 'payment'
let statsCustomActive = false;
let statsCustomFrom  = '';
let statsCustomTo    = '';
let donutChart       = null;

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
  if (BASE_ICONS[cat]) return BASE_ICONS[cat];
  const all = [...customCats.expense, ...customCats.income];
  const found = all.find(c => c.name === cat);
  return found ? found.emoji : '📦';
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
  const isMe    = name === 'me';
  const isStats = name === 'stats';
  document.getElementById('monthNav').style.display     = isMe ? 'none' : 'flex';
  document.getElementById('headerActions').style.display = isMe ? 'none' : 'flex';
  if (!isMe) {
    document.getElementById('haDefault').style.display = isStats ? 'none' : 'flex';
    document.getElementById('haStats').style.display   = isStats ? 'flex' : 'none';
  }
  document.getElementById('monthLabel').classList.toggle('picker-mode', isStats);
  setMonthLabel();
  if (name==='ledger')   { prevTab='ledger';   renderLedger(); }
  if (name==='calendar') { prevTab='calendar'; renderCalendar(); }
  if (name==='stats')    renderStats();
  if (name==='report')   renderReport();
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
  const [y,m] = curMonth.split('-').map(Number);
  const nd = new Date(y, m-1+d, 1);
  curMonth = nd.getFullYear()+'-'+String(nd.getMonth()+1).padStart(2,'0');
  setMonthLabel();
  const active = TABS.find(t => document.getElementById('pane-'+t).classList.contains('active'));
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
  const today = new Date();
  const from  = new Date(today); from.setDate(from.getDate()-6);
  const pad   = d => String(d).padStart(2,'0');
  return `${pad(from.getMonth()+1)}.${pad(from.getDate())}~${pad(today.getMonth()+1)}.${pad(today.getDate())}`;
}

function setMonthLabel() {
  const active = TABS.find(t => document.getElementById('pane-'+t)?.classList.contains('active'));
  if (active === 'stats') {
    document.getElementById('monthLabel').textContent = getStatsHeaderLabel();
    return;
  }
  const [y,m] = curMonth.split('-');
  document.getElementById('monthLabel').textContent = y+'년 '+parseInt(m)+'월';
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

function setStatsPeriod(p) {
  statsPeriod = p;
  statsCustomActive = false;
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
  const today = new Date();
  const from  = new Date(today); from.setDate(from.getDate()-6);
  return txs.filter(t => t.date >= from.toISOString().slice(0,10) && t.date <= today.toISOString().slice(0,10));
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
      datasets: [{ data: amounts, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 14, hoverBorderWidth: 3 }]
    },
    options: {
      cutout: '70%',
      rotation: -90,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ${c.label}: ${fmt(c.raw)} (${Math.round(c.raw/total*100)}%)` } }
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

// ── 보고서 렌더 ──────────────────────────────────────────────
function renderReport() {
  const prev=prevMonth(curMonth);
  const [,tm]=curMonth.split('-'); const [,pm]=prev.split('-');
  const thisExp=monthOf(curMonth).filter(t=>t.type==='expense').reduce((s,t)=>s+t.amount,0);
  const prevExp=monthOf(prev).filter(t=>t.type==='expense').reduce((s,t)=>s+t.amount,0);
  document.getElementById('rLabelPrev').textContent=parseInt(pm)+'월 지출';
  document.getElementById('rLabelThis').textContent=parseInt(tm)+'월 지출';
  document.getElementById('rValPrev').textContent=fmt(prevExp);
  const tEl=document.getElementById('rValThis');
  tEl.textContent=fmt(thisExp);
  tEl.className='compare-val'+(thisExp>prevExp?' up':thisExp<prevExp?' down':'');
  const diff=thisExp-prevExp, pct=prevExp>0?Math.round(Math.abs(diff)/prevExp*100):0;
  document.getElementById('rDiff').innerHTML=diff===0?'지난 달과 동일해요'
    :diff>0?`지난 달보다 <span class="up">${fmt(diff)} (${pct}%) 더 썼어요</span>`
           :`지난 달보다 <span class="down">${fmt(-diff)} (${pct}%) 절약! 🎉</span>`;
  const thisCats={},prevCats={};
  monthOf(curMonth).filter(t=>t.type==='expense').forEach(t=>thisCats[t.category]=(thisCats[t.category]||0)+t.amount);
  monthOf(prev).filter(t=>t.type==='expense').forEach(t=>prevCats[t.category]=(prevCats[t.category]||0)+t.amount);
  const allCats=[...new Set([...Object.keys(thisCats),...Object.keys(prevCats)])];
  document.getElementById('rCatCmp').innerHTML=!allCats.length
    ?'<div style="text-align:center;padding:20px;color:#bdbdbd">데이터 없음</div>'
    :allCats.map(cat=>{
        const tv=thisCats[cat]||0,pv=prevCats[cat]||0,d=tv-pv;
        return `<div class="cat-compare-row">
          <span>${getIcon(cat)} ${cat}</span>
          <span style="color:#9e9e9e">${fmt(pv)}</span><span style="color:#bdbdbd">→</span>
          <span style="font-weight:700;color:${d>0?'#e53935':d<0?'#00BCD4':'#424242'}">${fmt(tv)}</span>
        </div>`;
      }).join('');
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
  setType('expense');
  document.getElementById('modal').classList.add('show');
  setTimeout(()=>document.getElementById('fAmt').focus(),150);
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
  const base = BASE_CATS[type].map(c => `<option value="${c}">${BASE_ICONS[c]||'📦'} ${c}</option>`);
  const custom = (customCats[type]||[]).map(c => `<option value="${c.name}">${c.emoji} ${c.name}</option>`);
  document.getElementById('fCat').innerHTML = [...base, ...custom].join('');
}

// ── 커스텀 카테고리 ───────────────────────────────────────────
function toggleNewCat() {
  document.getElementById('newCatBox').classList.toggle('show');
}
function saveNewCat() {
  const emoji = document.getElementById('ncEmoji').value.trim() || '📦';
  const name  = document.getElementById('ncName').value.trim();
  if (!name) { alert('카테고리 이름을 입력해주세요.'); return; }
  const type = curType;
  if (!customCats[type]) customCats[type]=[];
  if (customCats[type].find(c=>c.name===name)) { alert('이미 있는 카테고리예요.'); return; }
  customCats[type].push({emoji, name});
  persistCats();
  buildCatSelect(type);
  // 새로 추가한 카테고리를 선택 상태로
  document.getElementById('fCat').value = name;
  document.getElementById('ncEmoji').value='';
  document.getElementById('ncName').value='';
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
function deleteTxFromAction() {
  document.getElementById('txaOverlay').classList.remove('show');
  if (!confirm('이 내역을 삭제할까요?')) return;
  txs = txs.filter(t => t.id !== activeTxId);
  persist(); renderLedger();
  if (calVisible) renderCalendar();
}

// ── 삭제 ─────────────────────────────────────────────────────
function askDelete(id) {
  if (!confirm('이 내역을 삭제할까요?')) return;
  txs=txs.filter(t=>t.id!==id); persist(); renderLedger();
  if (calVisible) renderCalendar();
}
function doDeleteAll() {
  if (!confirm('모든 내역을 삭제할까요?\n되돌릴 수 없습니다.')) return;
  txs=[]; persist(); alert('삭제 완료!');
}

// ── 초기화 ───────────────────────────────────────────────────
load();
setMonthLabel();
renderLedger();
</script>
</body>
</html>
