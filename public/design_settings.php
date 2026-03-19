<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>디자인 설정 — 똑똑가계부</title>
<script src="design_apply.js"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
/* CSS 변수 fallback (design_apply.js 인라인 스타일이 항상 우선) */
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #fff;
  color: #212121;
  min-height: 100vh;
  padding-bottom: 60px;
}

/* ── 헤더 ── */
.s-header {
  position: sticky; top: 0; z-index: 100;
  background: var(--theme-primary,#455A64);
  display: flex; align-items: center;
  padding: 0 4px; height: 56px;
}
.s-header-icon {
  color: #fff; width: 48px; height: 48px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; border-radius: 50%; flex-shrink: 0;
}
.s-header-icon:active { background: rgba(255,255,255,.15); }
.s-header-icon .material-icons { font-size: 24px; }
.s-header-title { flex: 1; color: #fff; font-size: 20px; font-weight: 500; padding-left: 8px; }
.s-header-actions { display: flex; align-items: center; }

/* ── 리스트 ── */
.s-section {
  background: #EEEEEE;
  padding: 10px 16px 9px;
  font-size: 13px; font-weight: 700; color: #424242; letter-spacing: .2px;
}
.s-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 16px 13px;
  border-bottom: 1px solid #F0F0F0;
  cursor: pointer; background: #fff;
}
.s-item:active { background: #F5F5F5; }
.s-item-title { font-size: 18px; font-weight: 700; color: #212121; line-height: 1.3; }
.s-item-desc  { font-size: 14px; color: #757575; margin-top: 3px; line-height: 1.4; }

/* ── 팝업 오버레이 ── */
.popup-overlay {
  display: none;
  position: fixed; inset: 0; z-index: 500;
  background: rgba(0,0,0,.5);
  align-items: center; justify-content: center;
}
.popup-overlay.show { display: flex; }

.popup-box {
  background: #fff;
  border-radius: 4px;
  width: 88%; max-width: 400px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,.3);
  max-height: 80vh;
  display: flex; flex-direction: column;
}

.popup-hd {
  background: var(--theme-primary,#455A64);
  padding: 18px 20px;
  font-size: 18px; font-weight: 700; color: #fff;
  flex-shrink: 0;
}

.popup-list { overflow-y: auto; flex: 1; }

.popup-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #F0F0F0;
  cursor: pointer; background: #fff;
  font-size: 17px; color: #212121;
}
.popup-item:active { background: #F5F5F5; }
.popup-item:last-child { border-bottom: none; }

/* 라디오 커스텀 */
.popup-radio {
  width: 22px; height: 22px;
  border-radius: 50%;
  border: 2px solid #9E9E9E;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: border-color .15s;
}
.popup-radio.selected {
  border-color: var(--theme-primary,#455A64);
}
.popup-radio.selected::after {
  content: '';
  width: 12px; height: 12px;
  border-radius: 50%;
  background: var(--theme-primary,#455A64);
}

.popup-cancel {
  width: 100%; padding: 16px 0;
  background: var(--theme-primary,#455A64); color: #fff;
  border: none; font-size: 17px; font-weight: 700;
  cursor: pointer; flex-shrink: 0;
}
.popup-cancel:active { background: #37474F; }

/* ── 광고 바 ── */
.ad-bar {
  position: fixed; bottom: 0; left: 0; right: 0;
  height: 60px; background: #F5F5F5;
  border-top: 1px solid #E0E0E0;
  display: flex; align-items: center; padding: 0 12px; gap: 10px; z-index: 200;
}
.ad-badge { font-size: 11px; color: #fff; background: #9E9E9E; padding: 2px 5px; border-radius: 3px; flex-shrink: 0; }
.ad-text  { flex: 1; font-size: 13px; color: #424242; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ad-btn   { width: 36px; height: 36px; background: #E53935; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ad-btn .material-icons { color: #fff; font-size: 20px; }
</style>
</head>
<body>

<!-- ══ 헤더 ══ -->
<header class="s-header">
  <div class="s-header-icon" onclick="history.back()">
    <span class="material-icons">arrow_back</span>
  </div>
  <div class="s-header-title">디자인 설정</div>
  <div class="s-header-actions">
    <div class="s-header-icon"><span class="material-icons">lock_open</span></div>
    <div class="s-header-icon"><span class="material-icons">more_vert</span></div>
  </div>
</header>

<!-- ══ 리스트 ══ -->
<div class="s-list">

  <!-- 테마 설정 -->
  <div class="s-section">테마 설정</div>
  <div class="s-item" onclick="openPopup('theme')">
    <div>
      <div class="s-item-title">테마 설정</div>
      <div class="s-item-desc" id="lbl-theme">화이트</div>
    </div>
  </div>

  <!-- 글자 크기 -->
  <div class="s-section">글자 크기</div>
  <div class="s-item" onclick="openPopup('fontsize')">
    <div>
      <div class="s-item-title">글자 크기</div>
      <div class="s-item-desc" id="lbl-fontsize">보통</div>
    </div>
  </div>

  <!-- 보조 색상 -->
  <div class="s-section">보조 색상</div>
  <div class="s-item" onclick="openPopup('color_minus')">
    <div>
      <div class="s-item-title">금액 감소</div>
      <div class="s-item-desc" id="lbl-color_minus">빨간색</div>
    </div>
  </div>
  <div class="s-item" onclick="openPopup('color_plus')">
    <div>
      <div class="s-item-title">금액 증가</div>
      <div class="s-item-desc" id="lbl-color_plus">파란색</div>
    </div>
  </div>

</div>

<!-- ══ 팝업: 테마 설정 ══ -->
<div class="popup-overlay" id="popup-theme" onclick="closePopupBg(event,'popup-theme')">
  <div class="popup-box">
    <div class="popup-hd">테마 설정</div>
    <div class="popup-list" id="list-theme">
      <?php
      $themes = ['화이트','다크','핑크','브라운','옐로우','블루','틸','그린','레드','퍼플','인디고'];
      foreach ($themes as $t): ?>
      <div class="popup-item" onclick="selectOption('theme','<?= $t ?>')">
        <span><?= $t ?></span>
        <div class="popup-radio" id="radio-theme-<?= $t ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="popup-cancel" onclick="closePopup('popup-theme')">취소</button>
  </div>
</div>

<!-- ══ 팝업: 글자 크기 ══ -->
<div class="popup-overlay" id="popup-fontsize" onclick="closePopupBg(event,'popup-fontsize')">
  <div class="popup-box">
    <div class="popup-hd">글자 크기</div>
    <div class="popup-list" id="list-fontsize">
      <?php foreach (['보통','크게','아주 크게'] as $f): ?>
      <div class="popup-item" onclick="selectOption('fontsize','<?= $f ?>')">
        <span><?= $f ?></span>
        <div class="popup-radio" id="radio-fontsize-<?= $f ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="popup-cancel" onclick="closePopup('popup-fontsize')">취소</button>
  </div>
</div>

<!-- ══ 팝업: 금액 감소 색상 ══ -->
<div class="popup-overlay" id="popup-color_minus" onclick="closePopupBg(event,'popup-color_minus')">
  <div class="popup-box">
    <div class="popup-hd">금액 감소</div>
    <div class="popup-list" id="list-color_minus">
      <?php foreach (['빨간색','파란색','초록색'] as $c): ?>
      <div class="popup-item" onclick="selectOption('color_minus','<?= $c ?>')">
        <span><?= $c ?></span>
        <div class="popup-radio" id="radio-color_minus-<?= $c ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="popup-cancel" onclick="closePopup('popup-color_minus')">취소</button>
  </div>
</div>

<!-- ══ 팝업: 금액 증가 색상 ══ -->
<div class="popup-overlay" id="popup-color_plus" onclick="closePopupBg(event,'popup-color_plus')">
  <div class="popup-box">
    <div class="popup-hd">금액 증가</div>
    <div class="popup-list" id="list-color_plus">
      <?php foreach (['빨간색','파란색','초록색'] as $c): ?>
      <div class="popup-item" onclick="selectOption('color_plus','<?= $c ?>')">
        <span><?= $c ?></span>
        <div class="popup-radio" id="radio-color_plus-<?= $c ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="popup-cancel" onclick="closePopup('popup-color_plus')">취소</button>
  </div>
</div>

<!-- ══ 광고 바 ══ -->
<div class="ad-bar">
  <span class="ad-badge">광고</span>
  <span class="ad-text">광고 영역</span>
  <div class="ad-btn"><span class="material-icons">file_download</span></div>
</div>

<script>
// ── 기본값 ──
const DEFAULTS = {
  theme:       '화이트',
  fontsize:    '보통',
  color_minus: '빨간색',
  color_plus:  '파란색'
};

// ── 저장된 값 로드 ──
function getSetting(key) {
  return localStorage.getItem('design_' + key) || DEFAULTS[key];
}
function setSetting(key, val) {
  localStorage.setItem('design_' + key, val);
}

// ── 라디오 상태 렌더링 ──
function renderRadios(key, val) {
  document.querySelectorAll('[id^="radio-' + key + '-"]').forEach(el => {
    el.classList.remove('selected');
  });
  const el = document.getElementById('radio-' + key + '-' + val);
  if (el) el.classList.add('selected');
}

// ── 서브타이틀 업데이트 ──
function updateLabel(key, val) {
  const lbl = document.getElementById('lbl-' + key);
  if (lbl) lbl.textContent = val;
}

// ── 팝업 열기 ──
function openPopup(key) {
  renderRadios(key, getSetting(key));
  document.getElementById('popup-' + key).classList.add('show');
}

// ── 팝업 닫기 ──
function closePopup(id) {
  document.getElementById(id).classList.remove('show');
}
function closePopupBg(e, id) {
  if (e.target === document.getElementById(id)) closePopup(id);
}

// ── 선택 처리 ──
function selectOption(key, val) {
  setSetting(key, val);
  renderRadios(key, val);
  updateLabel(key, val);
  closePopup('popup-' + key);
  // 즉시 테마/색상/글자크기 반영
  if (window.applyDesignSettings) window.applyDesignSettings();
}

// ── 페이지 로드 시 현재값 표시 ──
['theme','fontsize','color_minus','color_plus'].forEach(key => {
  updateLabel(key, getSetting(key));
});
</script>
<script>
(function(){
  var fs = localStorage.getItem('design_fontsize') || '보통';
  document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1';
})();
</script>
</body>
</html>
