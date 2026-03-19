/**
 * currency_apply.js
 * localStorage의 통화 설정을 읽어 모든 페이지의 금액 표시에 반영.
 * <head> 안에서 design_apply.js 직후에 로드.
 */
(function () {

  // ── 설정 읽기 (매번 최신값) ──
  function getSettings() {
    return {
      sym: localStorage.getItem('currency_symbol')  || '₩',
      sp:  localStorage.getItem('currency_spacing') || '',
      pos: localStorage.getItem('currency_pos')     || 'before'
    };
  }

  // ── 공용 포맷 함수 (다른 JS에서 CURRENCY.format(n) 으로 사용) ──
  function makeFmt(sym, sp, pos) {
    return function(n) {
      var s = Math.abs(Math.round(parseFloat(n))).toLocaleString('ko-KR');
      return pos === 'before' ? sym + sp + s : s + sp + sym;
    };
  }

  // 초기 window.CURRENCY 세팅
  (function initCurrency() {
    var cfg = getSettings();
    window.CURRENCY = {
      symbol: cfg.sym,
      spacing: cfg.sp,
      pos: cfg.pos,
      format: makeFmt(cfg.sym, cfg.sp, cfg.pos)
    };
  })();

  // ── [data-amt] 속성이 있는 요소 재포맷 ──
  function applyDataAmt(fmt) {
    document.querySelectorAll('[data-amt]').forEach(function (el) {
      var raw = parseFloat(el.dataset.amt);
      if (isNaN(raw)) return;
      var sign = el.dataset.sign || '';
      el.textContent = sign + fmt(raw);
    });
  }

  // ── PHP가 ₩ 로 출력한 텍스트를 일괄 교체 ──
  // (data-amt 없는 레거시 요소 커버)
  function replaceSymbols(sym, sp, pos, root) {
    if (sym === '₩' && sp === '' && pos === 'before') return; // 기본값이면 스킵
    root = root || document.body;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
    var node;
    while ((node = walker.nextNode())) {
      var v = node.nodeValue;
      if (!v.includes('₩')) continue;
      if (pos === 'before') {
        node.nodeValue = v.replace(/₩([\d,]+)/g, function (_, num) {
          return sym + sp + num;
        });
      } else {
        node.nodeValue = v.replace(/₩([\d,]+)/g, function (_, num) {
          return num + sp + sym;
        });
      }
    }
  }

  function applyAll() {
    // 매번 최신 localStorage 값을 읽어 window.CURRENCY 갱신
    var cfg = getSettings();
    var fmt = makeFmt(cfg.sym, cfg.sp, cfg.pos);
    window.CURRENCY = {
      symbol: cfg.sym,
      spacing: cfg.sp,
      pos: cfg.pos,
      format: fmt
    };
    applyDataAmt(fmt);
    replaceSymbols(cfg.sym, cfg.sp, cfg.pos);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyAll);
  } else {
    applyAll();
  }

  // bfcache 복원 시 재적용
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) applyAll();
  });

  window.applyCurrencySettings = applyAll;
})();
