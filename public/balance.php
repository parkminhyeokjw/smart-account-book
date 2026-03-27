<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>잔액 관리</title>
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
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .header-btn {
    background: none; border: none; color: #fff;
    font-size: 22px; width: 48px; height: 48px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
  }
  .header-btn:active { background: rgba(255,255,255,0.15); }
  .header-title { flex: 1; font-size: 18px; font-weight: 500; text-align: center; }

  /* ── Content ── */
  .content { padding-bottom: 88px; }

  /* Total row */
  .total-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    background: #fff;
    font-size: 16px; font-weight: 600;
    border-bottom: 1px solid #e0e0e0;
  }
  .total-label { color: #212121; }
  .total-amount { color: var(--theme-primary, #1D2C55); }

  .separator { height: 8px; background: #f0f0f0; }

  /* Group / Item rows */
  .group-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 16px;
    background: #fff;
    cursor: pointer;
    border-bottom: 1px solid #e8e8e8;
    font-size: 15px; font-weight: 500;
  }
  .group-row:active { background: #f5f5f5; }
  .group-left { display: flex; align-items: center; gap: 8px; }
  .group-arrow { font-size: 14px; color: #757575; width: 18px; text-align: center; }
  .group-name { color: #212121; }
  .group-amount { color: #1D2C55; font-weight: 500; }

  .item-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 16px 11px 40px;
    background: #fafafa;
    border-bottom: 1px solid #eeeeee;
    font-size: 14px;
    cursor: pointer;
  }
  .item-row:active { background: #f0f0f0; }
  .item-name { color: #424242; }
  .item-amount { color: #616161; }

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
    align-items: flex-end;
    justify-content: center;
  }
  .overlay.show { display: flex; }
  .overlay.center { align-items: center; }

  /* ── Bottom Sheet ── */
  .bottom-sheet {
    background: #fff;
    width: 100%; max-width: 480px;
    border-radius: 16px 16px 0 0;
    overflow: hidden;
  }
  .sheet-header {
    padding: 14px 16px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; font-size: 16px; font-weight: 500;
  }
  .sheet-section-divider {
    padding: 8px 16px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; font-size: 13px; font-weight: 500;
  }
  .sheet-item {
    display: flex; align-items: center; gap: 12px;
    padding: 15px 20px;
    font-size: 15px; color: #212121;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
  }
  .sheet-item:active { background: #f5f5f5; }
  .sheet-cancel {
    display: block; width: 100%;
    padding: 15px; font-size: 15px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; border: none;
    cursor: pointer; font-weight: 500;
  }
  .sheet-cancel:active { filter: brightness(0.9); }

  /* ── Dialog ── */
  .dialog {
    background: #fff;
    width: calc(100% - 48px); max-width: 360px;
    border-radius: 8px; overflow: hidden;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
  }
  .dialog-header {
    padding: 14px 16px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; font-size: 16px; font-weight: 500;
  }
  .dialog-body { padding: 20px 16px 8px; }
  .dialog-body input {
    width: 100%; border: 1px solid #bdbdbd;
    border-radius: 4px; padding: 10px 12px;
    font-size: 16px; outline: none;
  }
  .dialog-body input:focus { border-color: var(--theme-primary, #1D2C55); }
  .dialog-footer {
    display: flex; gap: 0;
    border-top: 1px solid #eeeeee;
    margin-top: 12px;
  }
  .dialog-footer button {
    flex: 1; padding: 13px;
    font-size: 15px; border: none;
    cursor: pointer; font-weight: 500;
  }
  .btn-cancel { background: #fff; color: var(--theme-primary, #1D2C55); border-right: 1px solid #eeeeee !important; }
  .btn-confirm { background: var(--theme-primary, #1D2C55); color: #fff; }
  .btn-cancel:active, .btn-confirm:active { filter: brightness(0.9); }

  /* Tip dialog */
  .tip-checkbox-row {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 16px 16px;
    font-size: 14px; color: #424242;
    cursor: pointer;
  }
  .tip-checkbox-row input { width: 18px; height: 18px; accent-color: var(--theme-primary, #1D2C55); }
  .tip-body { padding: 16px; font-size: 14px; color: #424242; line-height: 1.7; white-space: pre-line; }
  .tip-confirm-btn {
    display: block; width: 100%;
    padding: 13px; font-size: 15px;
    background: var(--theme-primary, #1D2C55);
    color: #fff; border: none;
    cursor: pointer; font-weight: 500;
  }
  .tip-confirm-btn:active { filter: brightness(0.9); }
</style>
</head>
<body>

<!-- Header -->
<header class="header">
  <button class="header-btn" onclick="history.back()">&#8592;</button>
  <span class="header-title">잔액 관리</span>
  <button class="header-btn">&#8942;</button>
</header>

<!-- Content -->
<div class="content" id="content"></div>

<!-- FAB -->
<button class="fab" id="fabBtn">+</button>

<!-- ── Tip Dialog ── -->
<div class="overlay center" id="tipOverlay">
  <div class="dialog">
    <div class="dialog-header">사용 팁</div>
    <div class="tip-body">심플하게 지출과 수입만을 관리하고 싶으신 분께서는 이 기능을 사용하지 않으셔도 됩니다.

만약 잔액관리 기능이 필요하신 분께서는 먼저 가계부의 내역을 입력해보시고 어느 정도 사용법을 익히신 후에 이 기능을 사용해보세요.</div>
    <label class="tip-checkbox-row">
      <input type="checkbox" id="tipCheck"> 이 팁을 다시 보지 않기
    </label>
    <button class="tip-confirm-btn" id="tipConfirmBtn">확인</button>
  </div>
</div>

<!-- ── Context Menu (bottom sheet) ── -->
<div class="overlay" id="contextOverlay">
  <div class="bottom-sheet">
    <div class="sheet-header" id="contextHeader"></div>
    <div class="sheet-item" id="ctxDetail"><span>🔍</span> 상세 내역</div>
    <div class="sheet-item" id="ctxTransfer"><span>↕</span> 금액 이체</div>
    <div class="sheet-item" id="ctxBalance"><span>🏛</span> 잔액 수정</div>
    <div class="sheet-section-divider">메뉴</div>
    <div class="sheet-item" id="ctxEdit"><span>✏</span> 수정</div>
    <div class="sheet-item" id="ctxDelete"><span>🗑</span> 삭제</div>
    <div class="sheet-item" id="ctxMulti"><span>✓</span> 다중 선택</div>
    <button class="sheet-cancel" id="ctxCancel">취소</button>
  </div>
</div>

<!-- ── FAB Menu (bottom sheet) ── -->
<div class="overlay" id="fabOverlay">
  <div class="bottom-sheet">
    <div class="sheet-item" id="fabAddAccount"><span>+</span> 계좌 추가</div>
    <div class="sheet-item" id="fabAddGroup"><span>+</span> 그룹 추가</div>
    <button class="sheet-cancel" id="fabCancel">취소</button>
  </div>
</div>

<!-- ── 잔액 수정 Dialog ── -->
<div class="overlay center" id="balanceOverlay">
  <div class="dialog">
    <div class="dialog-header">잔액 수정</div>
    <div class="dialog-body">
      <input type="number" id="balanceInput" placeholder="금액 입력">
    </div>
    <div class="dialog-footer">
      <button class="btn-cancel" id="balanceCancelBtn">취소</button>
      <button class="btn-confirm" id="balanceConfirmBtn">확인</button>
    </div>
  </div>
</div>

<!-- ── 수정 (edit name) Dialog ── -->
<div class="overlay center" id="editNameOverlay">
  <div class="dialog">
    <div class="dialog-header">수정</div>
    <div class="dialog-body">
      <input type="text" id="editNameInput" placeholder="이름 입력">
    </div>
    <div class="dialog-footer">
      <button class="btn-cancel" id="editNameCancelBtn">취소</button>
      <button class="btn-confirm" id="editNameConfirmBtn">확인</button>
    </div>
  </div>
</div>

<!-- ── 계좌 추가 Dialog ── -->
<div class="overlay center" id="addAccountOverlay">
  <div class="dialog">
    <div class="dialog-header">계좌 추가</div>
    <div class="dialog-body">
      <input type="text" id="addAccountName" placeholder="계좌 이름">
      <div style="margin-top:10px">
        <select id="addAccountGroup" style="width:100%;padding:10px 12px;font-size:15px;border:1px solid #bdbdbd;border-radius:4px;outline:none;"></select>
      </div>
    </div>
    <div class="dialog-footer">
      <button class="btn-cancel" id="addAccountCancelBtn">취소</button>
      <button class="btn-confirm" id="addAccountConfirmBtn">확인</button>
    </div>
  </div>
</div>

<!-- ── 그룹 추가 Dialog ── -->
<div class="overlay center" id="addGroupOverlay">
  <div class="dialog">
    <div class="dialog-header">그룹 추가</div>
    <div class="dialog-body">
      <input type="text" id="addGroupName" placeholder="그룹 이름">
    </div>
    <div class="dialog-footer">
      <button class="btn-cancel" id="addGroupCancelBtn">취소</button>
      <button class="btn-confirm" id="addGroupConfirmBtn">확인</button>
    </div>
  </div>
</div>

<script>
(function () {
  var STORAGE_KEY = 'balance_accounts';
  var TIP_KEY     = 'balance_tip_dismissed';

  var DEFAULT_DATA = [
    { id: 'g1', type: 'group', name: '현금', expanded: true,  items: [{ id: 'i1', name: '현금', amount: 0 }] },
    { id: 'g2', type: 'group', name: '은행', expanded: false, items: [] }
  ];

  // ── Helpers ──
  function genId() { return 'id_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7); }

  function loadData() {
    var raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      saveData(DEFAULT_DATA);
      return JSON.parse(JSON.stringify(DEFAULT_DATA));
    }
    try { return JSON.parse(raw); } catch (e) { return JSON.parse(JSON.stringify(DEFAULT_DATA)); }
  }

  function saveData(data) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  function formatAmount(n) {
    var num = parseFloat(n) || 0;
    return 'kr ' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function groupTotal(group) {
    return group.items.reduce(function (s, it) { return s + (parseFloat(it.amount) || 0); }, 0);
  }

  function totalAll(data) {
    return data.reduce(function (s, g) { return s + groupTotal(g); }, 0);
  }

  // ── Render ──
  function render() {
    var data    = loadData();
    var content = document.getElementById('content');
    var html    = '';

    // Total row
    html += '<div class="total-row">';
    html += '<span class="total-label">총 잔액</span>';
    html += '<span class="total-amount">' + formatAmount(totalAll(data)) + '</span>';
    html += '</div>';
    html += '<div class="separator"></div>';

    data.forEach(function (group) {
      var gTotal = groupTotal(group);
      var arrow  = group.expanded ? '&#8964;' : '&#8250;';
      html += '<div class="group-row" data-gid="' + group.id + '">';
      html += '<span class="group-left"><span class="group-arrow">' + arrow + '</span><span class="group-name">' + esc(group.name) + '</span></span>';
      html += '<span class="group-amount">' + formatAmount(gTotal) + '</span>';
      html += '</div>';

      if (group.expanded) {
        group.items.forEach(function (item) {
          html += '<div class="item-row" data-iid="' + item.id + '" data-gid="' + group.id + '">';
          html += '<span class="item-name">' + esc(item.name) + '</span>';
          html += '<span class="item-amount">' + formatAmount(item.amount) + '</span>';
          html += '</div>';
        });
      }
    });

    content.innerHTML = html;

    // Group toggle
    content.querySelectorAll('.group-row').forEach(function (el) {
      el.addEventListener('click', function () {
        var gid  = el.dataset.gid;
        var data = loadData();
        var g    = data.find(function (x) { return x.id === gid; });
        if (g) { g.expanded = !g.expanded; saveData(data); render(); }
      });
    });

    // Item click → context menu
    content.querySelectorAll('.item-row').forEach(function (el) {
      el.addEventListener('click', function () {
        openContext(el.dataset.gid, el.dataset.iid);
      });
    });
  }

  function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Tip dialog ──
  if (localStorage.getItem(TIP_KEY) !== '1') {
    document.getElementById('tipOverlay').classList.add('show');
  }
  document.getElementById('tipConfirmBtn').addEventListener('click', function () {
    if (document.getElementById('tipCheck').checked) {
      localStorage.setItem(TIP_KEY, '1');
    }
    document.getElementById('tipOverlay').classList.remove('show');
  });

  // ── Context Menu ──
  var _ctxGid = null, _ctxIid = null;

  function openContext(gid, iid) {
    _ctxGid = gid; _ctxIid = iid;
    var data = loadData();
    var g    = data.find(function (x) { return x.id === gid; });
    var item = g ? g.items.find(function (x) { return x.id === iid; }) : null;
    document.getElementById('contextHeader').textContent = item ? item.name : '';
    document.getElementById('contextOverlay').classList.add('show');
  }

  function closeContext() { document.getElementById('contextOverlay').classList.remove('show'); }

  document.getElementById('ctxCancel').addEventListener('click', closeContext);
  document.getElementById('contextOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeContext();
  });

  // 잔액 수정
  document.getElementById('ctxBalance').addEventListener('click', function () {
    closeContext();
    var data = loadData();
    var g    = data.find(function (x) { return x.id === _ctxGid; });
    var item = g ? g.items.find(function (x) { return x.id === _ctxIid; }) : null;
    if (!item) return;
    document.getElementById('balanceInput').value = item.amount;
    document.getElementById('balanceOverlay').classList.add('show');
  });
  document.getElementById('balanceCancelBtn').addEventListener('click', function () {
    document.getElementById('balanceOverlay').classList.remove('show');
  });
  document.getElementById('balanceConfirmBtn').addEventListener('click', function () {
    var val  = parseFloat(document.getElementById('balanceInput').value) || 0;
    var data = loadData();
    var g    = data.find(function (x) { return x.id === _ctxGid; });
    var item = g ? g.items.find(function (x) { return x.id === _ctxIid; }) : null;
    if (item) { item.amount = val; saveData(data); render(); }
    document.getElementById('balanceOverlay').classList.remove('show');
  });

  // 수정 (name)
  document.getElementById('ctxEdit').addEventListener('click', function () {
    closeContext();
    var data = loadData();
    var g    = data.find(function (x) { return x.id === _ctxGid; });
    var item = g ? g.items.find(function (x) { return x.id === _ctxIid; }) : null;
    if (!item) return;
    document.getElementById('editNameInput').value = item.name;
    document.getElementById('editNameOverlay').classList.add('show');
  });
  document.getElementById('editNameCancelBtn').addEventListener('click', function () {
    document.getElementById('editNameOverlay').classList.remove('show');
  });
  document.getElementById('editNameConfirmBtn').addEventListener('click', function () {
    var name = document.getElementById('editNameInput').value.trim();
    if (!name) return;
    var data = loadData();
    var g    = data.find(function (x) { return x.id === _ctxGid; });
    var item = g ? g.items.find(function (x) { return x.id === _ctxIid; }) : null;
    if (item) { item.name = name; saveData(data); render(); }
    document.getElementById('editNameOverlay').classList.remove('show');
  });

  // 삭제
  document.getElementById('ctxDelete').addEventListener('click', function () {
    closeContext();
    var data = loadData();
    var g    = data.find(function (x) { return x.id === _ctxGid; });
    if (g) {
      g.items = g.items.filter(function (x) { return x.id !== _ctxIid; });
      saveData(data); render();
    }
  });

  // ── FAB Menu ──
  document.getElementById('fabBtn').addEventListener('click', function () {
    document.getElementById('fabOverlay').classList.add('show');
  });
  document.getElementById('fabCancel').addEventListener('click', function () {
    document.getElementById('fabOverlay').classList.remove('show');
  });
  document.getElementById('fabOverlay').addEventListener('click', function (e) {
    if (e.target === this) document.getElementById('fabOverlay').classList.remove('show');
  });

  // 계좌 추가
  document.getElementById('fabAddAccount').addEventListener('click', function () {
    document.getElementById('fabOverlay').classList.remove('show');
    var data   = loadData();
    var sel    = document.getElementById('addAccountGroup');
    sel.innerHTML = '';
    data.forEach(function (g) {
      var opt = document.createElement('option');
      opt.value = g.id; opt.textContent = g.name;
      sel.appendChild(opt);
    });
    document.getElementById('addAccountName').value = '';
    document.getElementById('addAccountOverlay').classList.add('show');
  });
  document.getElementById('addAccountCancelBtn').addEventListener('click', function () {
    document.getElementById('addAccountOverlay').classList.remove('show');
  });
  document.getElementById('addAccountConfirmBtn').addEventListener('click', function () {
    var name = document.getElementById('addAccountName').value.trim();
    var gid  = document.getElementById('addAccountGroup').value;
    if (!name) return;
    var data = loadData();
    var g    = data.find(function (x) { return x.id === gid; });
    if (g) { g.items.push({ id: genId(), name: name, amount: 0 }); saveData(data); render(); }
    document.getElementById('addAccountOverlay').classList.remove('show');
  });

  // 그룹 추가
  document.getElementById('fabAddGroup').addEventListener('click', function () {
    document.getElementById('fabOverlay').classList.remove('show');
    document.getElementById('addGroupName').value = '';
    document.getElementById('addGroupOverlay').classList.add('show');
  });
  document.getElementById('addGroupCancelBtn').addEventListener('click', function () {
    document.getElementById('addGroupOverlay').classList.remove('show');
  });
  document.getElementById('addGroupConfirmBtn').addEventListener('click', function () {
    var name = document.getElementById('addGroupName').value.trim();
    if (!name) return;
    var data = loadData();
    data.push({ id: genId(), type: 'group', name: name, expanded: true, items: [] });
    saveData(data); render();
    document.getElementById('addGroupOverlay').classList.remove('show');
  });

  // ── Init ──
  render();
})();
</script>

<script>
(function(){ var fs = localStorage.getItem('design_fontsize')||'보통'; document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1'; })();
</script>
</body>
</html>
