<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>지출 패턴</title>
<script src="design_apply.js"></script>
<script src="currency_apply.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root { --theme-primary: #1D2C55; }

  body {
    font-family: 'Segoe UI', sans-serif;
    background: #f5f5f5;
    min-height: 100vh;
  }

  /* ── Header ── */
  .header {
    display: flex;
    align-items: center;
    height: 56px;
    background: var(--theme-primary, #1D2C55);
    color: #fff;
    padding: 0 4px;
    position: sticky; top: 0; z-index: 100;
  }
  .header-btn {
    background: none; border: none; color: #fff;
    font-size: 22px; width: 48px; height: 48px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
  }
  .header-btn:active { background: rgba(255,255,255,0.15); }
  .header-title { flex: 1; font-size: 18px; font-weight: 500; text-align: center; }

  /* ── Tabs ── */
  .tabs {
    display: flex;
    background: #fff;
    border-bottom: 1px solid #e0e0e0;
    position: sticky; top: 56px; z-index: 99;
  }
  .tab {
    flex: 1; text-align: center; padding: 13px 4px;
    font-size: 14px; font-weight: 500;
    color: #9E9E9E; cursor: pointer;
    border-bottom: 2px solid transparent;
    user-select: none;
    transition: color 0.15s, border-color 0.15s;
  }
  .tab.active {
    color: var(--theme-primary, #1D2C55);
    border-bottom-color: var(--theme-primary, #1D2C55);
  }

  /* ── Empty state ── */
  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 32px;
    text-align: center;
    min-height: calc(100vh - 112px);
  }
  .empty-state p {
    font-size: 16px;
    color: #555;
    line-height: 1.8;
    margin-bottom: 24px;
    white-space: pre-line;
  }

  /* ── Pattern list ── */
  .content { padding-bottom: 88px; }

  .pattern-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    background: #fff;
    border-bottom: 1px solid #e8e8e8;
    cursor: pointer;
  }
  .pattern-row:active { background: #f5f5f5; }
  .pattern-name { font-size: 15px; font-weight: 600; color: #212121; }
  .pattern-value { font-size: 14px; color: #555; text-align: right; }

  /* ── FAB ── */
  .fab {
    position: fixed; bottom: 24px; right: 20px;
    width: 60px; height: 60px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; font-size: 32px;
    border: none; border-radius: 16px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    z-index: 200;
  }
  .fab:active { filter: brightness(0.9); }

  /* ── Overlay ── */
  .overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 300;
    align-items: center;
    justify-content: center;
  }
  .overlay.show { display: flex; }

  /* ── Add Dialog ── */
  .dialog {
    background: #fff;
    width: calc(100% - 48px); max-width: 380px;
    border-radius: 8px; overflow: hidden;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
  }
  .dialog-header {
    padding: 14px 16px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; font-size: 16px; font-weight: 500;
  }
  .dialog-body { padding: 16px; }
  .dialog-field { margin-bottom: 14px; }
  .dialog-label { font-size: 13px; color: #757575; margin-bottom: 5px; }
  .dialog-input {
    width: 100%; border: 1px solid #bdbdbd;
    border-radius: 4px; padding: 9px 12px;
    font-size: 15px; outline: none;
  }
  .dialog-input:focus { border-color: var(--theme-primary, #1D2C55); }
  .dialog-footer {
    display: flex;
    border-top: 1px solid #eeeeee;
  }
  .dialog-footer button {
    flex: 1; padding: 13px;
    font-size: 15px; font-weight: 500;
    border: none; cursor: pointer;
  }
  .btn-cancel {
    background: #fff;
    color: var(--theme-primary, #1D2C55);
    border-right: 1px solid #eeeeee !important;
  }
  .btn-confirm {
    background: var(--theme-primary, #1D2C55);
    color: #fff;
  }
  .btn-cancel:active, .btn-confirm:active { filter: brightness(0.9); }
</style>
</head>
<body>

<?php
define('USER_ID', 1);
?>

<!-- Header -->
<header class="header">
  <button class="header-btn" onclick="history.back()">&#8592;</button>
  <span class="header-title">지출 패턴</span>
  <button class="header-btn">&#8942;</button>
</header>

<!-- Tabs -->
<div class="tabs" id="tabs">
  <div class="tab active" data-tab="0">마지막 지출일</div>
  <div class="tab" data-tab="1">지출 횟수</div>
  <div class="tab" data-tab="2">지출 금액</div>
</div>

<!-- Content -->
<div class="content" id="content">
  <div class="empty-state" id="emptyState">
    <p>지출내역이나 메모에 쓰인 키워드를 검색하여
지출 패턴을 파악하는 기능입니다.</p>
    <p>[ 사용 예제 ]
① 마지막으로 미용실 간 날
② 마지막으로 특정 음식점 간 날
③ 마지막으로 치킨 먹은 날
④ 이번 달 탄산음료 섭취 횟수
⑤ 이번 달 육류 섭취 횟수
...</p>
    <p>추가 버튼을 눌러서 사용해보세요!</p>
  </div>
  <div id="patternList" style="display:none;"></div>
</div>

<!-- FAB -->
<button class="fab" id="fabBtn">+</button>

<!-- ── Add Dialog ── -->
<div class="overlay" id="addOverlay">
  <div class="dialog">
    <div class="dialog-header">추가</div>
    <div class="dialog-body">
      <div class="dialog-field">
        <div class="dialog-label">지출 주제</div>
        <input class="dialog-input" type="text" id="addSubject" placeholder="">
      </div>
      <div class="dialog-field">
        <div class="dialog-label">포함할 키워드</div>
        <input class="dialog-input" type="text" id="addKeywords" placeholder="없음">
      </div>
      <div class="dialog-field">
        <div class="dialog-label">카테고리 필터</div>
        <input class="dialog-input" type="text" id="addCategory" placeholder="없음">
      </div>
      <div class="dialog-field">
        <div class="dialog-label">그룹 선택</div>
        <input class="dialog-input" type="text" id="addGroup" placeholder="그룹 없음">
      </div>
    </div>
    <div class="dialog-footer">
      <button class="btn-cancel" id="addCancelBtn">취소</button>
      <button class="btn-confirm" id="addConfirmBtn">확인</button>
    </div>
  </div>
</div>

<script>
(function () {
  var STORAGE_KEY = 'spending_patterns';
  var USER_ID     = <?= USER_ID ?>;
  var activeTab   = 0;

  function genId() { return 'sp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7); }

  function loadPatterns() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { return []; }
  }

  function savePatterns(arr) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
  }

  function formatDate(d) { return d ? d : '없음'; }

  function formatAmount(n) {
    if (typeof CURRENCY !== 'undefined' && CURRENCY && typeof CURRENCY.format === 'function') {
      return CURRENCY.format(n);
    }
    return Number(n).toLocaleString() + '원';
  }

  // ── Tabs ──
  document.getElementById('tabs').addEventListener('click', function (e) {
    var t = e.target.closest('.tab');
    if (!t) return;
    activeTab = parseInt(t.dataset.tab, 10);
    document.querySelectorAll('.tab').forEach(function (el) { el.classList.remove('active'); });
    t.classList.add('active');
    renderList(window._patternResults || []);
  });

  // ── Render ──
  function renderList(results) {
    window._patternResults = results;
    var patterns = loadPatterns();
    if (patterns.length === 0) {
      document.getElementById('emptyState').style.display = '';
      document.getElementById('patternList').style.display = 'none';
      return;
    }
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('patternList').style.display = '';

    var html = '';
    patterns.forEach(function (p) {
      var r = results.find(function (x) { return x.id === p.id; }) || {};
      var valueHtml = '';
      if (activeTab === 0) {
        valueHtml = formatDate(r.last_date);
      } else if (activeTab === 1) {
        valueHtml = (r.count_month !== undefined ? r.count_month : '-') + '회';
      } else {
        valueHtml = r.total_month !== undefined ? formatAmount(r.total_month) : '-';
      }
      html += '<div class="pattern-row">';
      html += '<span class="pattern-name">' + esc(p.subject) + '</span>';
      html += '<span class="pattern-value">' + valueHtml + '</span>';
      html += '</div>';
    });
    document.getElementById('patternList').innerHTML = html;
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Fetch data ──
  function fetchData() {
    var patterns = loadPatterns();
    if (patterns.length === 0) { renderList([]); return; }

    var payload = patterns.map(function (p) {
      return { id: p.id, subject: p.subject, keywords: p.keywords, category_id: p.category_id || '' };
    });

    fetch('../api/spending_pattern.php?action=query_all&user_id=' + USER_ID
      + '&patterns=' + encodeURIComponent(JSON.stringify(payload)))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.status === 'ok') {
          renderList(data.results || []);
        } else {
          renderList([]);
        }
      })
      .catch(function () { renderList([]); });
  }

  // ── FAB / Add Dialog ──
  document.getElementById('fabBtn').addEventListener('click', function () {
    document.getElementById('addSubject').value  = '';
    document.getElementById('addKeywords').value = '';
    document.getElementById('addCategory').value = '';
    document.getElementById('addGroup').value    = '';
    document.getElementById('addOverlay').classList.add('show');
  });

  document.getElementById('addCancelBtn').addEventListener('click', function () {
    document.getElementById('addOverlay').classList.remove('show');
  });

  document.getElementById('addOverlay').addEventListener('click', function (e) {
    if (e.target === this) document.getElementById('addOverlay').classList.remove('show');
  });

  document.getElementById('addConfirmBtn').addEventListener('click', function () {
    var subject  = document.getElementById('addSubject').value.trim();
    if (!subject) return;
    var keywords    = document.getElementById('addKeywords').value.trim();
    var category_id = document.getElementById('addCategory').value.trim();
    var group       = document.getElementById('addGroup').value.trim();

    var patterns = loadPatterns();
    patterns.push({
      id:          genId(),
      subject:     subject,
      keywords:    keywords || subject,
      category_id: category_id || '',
      group:       group || ''
    });
    savePatterns(patterns);
    document.getElementById('addOverlay').classList.remove('show');
    fetchData();
  });

  // ── Init ──
  fetchData();
})();
</script>

<script>
(function(){ var fs = localStorage.getItem('design_fontsize')||'보통'; document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1'; })();
</script>
</body>
</html>
