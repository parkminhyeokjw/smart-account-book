/**
 * design_apply.js
 * <head> 안에서 defer/async 없이 동기 로드.
 * documentElement.style.setProperty() = 인라인 스타일 → 어떤 :root 규칙보다 우선순위 높음.
 */
(function () {
  var THEMES = {
    '화이트': '#1D2C55',
    '다크':   '#141E3C',
    '핑크':   '#E91E63',
    '브라운': '#6D4C41',
    '옐로우': '#F57F17',
    '블루':   '#1565C0',
    '틸':     '#00695C',
    '그린':   '#2E7D32',
    '레드':   '#C62828',
    '퍼플':   '#6A1B9A',
    '인디고': '#283593',
  };

  var AMOUNTS = {
    '빨간색': '#E53935',
    '파란색': '#1565C0',
    '초록색': '#2E7D32',
  };

  function apply() {
    var theme      = localStorage.getItem('design_theme')       || '화이트';
    var fontsize   = localStorage.getItem('design_fontsize')    || '보통';
    var colorMinus = localStorage.getItem('design_color_minus') || '빨간색';
    var colorPlus  = localStorage.getItem('design_color_plus')  || '파란색';

    var primary = THEMES[theme]       || '#1D2C55';
    var minus   = AMOUNTS[colorMinus] || '#E53935';
    var plus    = AMOUNTS[colorPlus]  || '#1565C0';

    // ── 인라인 스타일로 CSS 변수 주입 (어떤 :root 규칙보다 항상 우선) ──
    var r = document.documentElement;
    r.style.setProperty('--theme-primary', primary);
    r.style.setProperty('--color-minus',   minus);
    r.style.setProperty('--color-plus',    plus);

    // ── 글자 크기 ──
    r.setAttribute('data-fontsize', fontsize);
    if (document.body) {
      document.body.style.zoom =
        fontsize === '아주 크게' ? '1.2' :
        fontsize === '크게'      ? '1.1' : '1';
    }
  }

  // ── 즉시 실행 (렌더 전) ──
  apply();

  // ── bfcache 복원 시 재적용 (뒤로가기 후 구버전 방지) ──
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) apply();
  });

  // ── 설정 변경 후 현재 페이지 즉시 반영용 ──
  window.applyDesignSettings = apply;
})();
