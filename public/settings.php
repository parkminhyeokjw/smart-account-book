<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>설정 — 똑똑가계부</title>
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
  padding-bottom: 60px; /* ad bar height */
}

/* ── 헤더 ── */
.s-header {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--theme-primary,#455A64);
  display: flex;
  align-items: center;
  padding: 0 4px;
  height: 56px;
}
.s-header-icon {
  color: #fff;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border-radius: 50%;
  flex-shrink: 0;
}
.s-header-icon:active { background: rgba(255,255,255,.15); }
.s-header-icon .material-icons { font-size: 24px; }
.s-header-title {
  flex: 1;
  color: #fff;
  font-size: 20px;
  font-weight: 500;
  padding-left: 8px;
}
.s-header-actions {
  display: flex;
  align-items: center;
}

/* ── 리스트 ── */
.s-list { background: #fff; }

/* 섹션 헤더 */
.s-section {
  background: #EEEEEE;
  padding: 10px 16px 9px;
  font-size: 13px;
  font-weight: 700;
  color: #424242;
  letter-spacing: .2px;
}

/* 일반 항목 */
.s-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 13px;
  border-bottom: 1px solid #F0F0F0;
  cursor: pointer;
  background: #fff;
  text-decoration: none;
}
.s-item:active { background: #F5F5F5; }
.s-item-text { flex: 1; }
.s-item-title {
  font-size: 18px;
  font-weight: 700;
  color: #212121;
  line-height: 1.3;
}
.s-item-desc {
  font-size: 14px;
  color: #757575;
  margin-top: 3px;
  line-height: 1.4;
}

/* 비밀번호 입력 — 비활성 스타일 */
.s-item.disabled .s-item-title { color: #9E9E9E; }

/* 체크박스 */
.s-checkbox {
  width: 22px;
  height: 22px;
  border: 2px solid #9E9E9E;
  border-radius: 3px;
  margin-left: 12px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background .15s, border-color .15s;
}
.s-checkbox.checked {
  background: var(--theme-primary,#455A64);
  border-color: var(--theme-primary,#455A64);
}
.s-checkbox.checked::after {
  content: '';
  display: block;
  width: 5px;
  height: 10px;
  border: 2px solid #fff;
  border-top: none;
  border-left: none;
  transform: rotate(45deg) translate(-1px, -1px);
}

/* ── 광고 바 ── */
.ad-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: 60px;
  background: #F5F5F5;
  border-top: 1px solid #E0E0E0;
  display: flex;
  align-items: center;
  padding: 0 12px;
  gap: 10px;
  z-index: 200;
}
.ad-badge {
  font-size: 11px;
  color: #fff;
  background: #9E9E9E;
  padding: 2px 5px;
  border-radius: 3px;
  flex-shrink: 0;
}
.ad-text {
  flex: 1;
  font-size: 13px;
  color: #424242;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.ad-btn {
  width: 36px;
  height: 36px;
  background: #E53935;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ad-btn .material-icons { color: #fff; font-size: 20px; }
</style>
</head>
<body>

<!-- ══ 헤더 ══ -->
<header class="s-header">
  <div class="s-header-icon" onclick="history.back()">
    <span class="material-icons">arrow_back</span>
  </div>
  <div class="s-header-title">설정</div>
  <div class="s-header-actions">
    <div class="s-header-icon">
      <span class="material-icons">lock_open</span>
    </div>
    <div class="s-header-icon">
      <span class="material-icons">more_vert</span>
    </div>
  </div>
</header>

<!-- ══ 설정 리스트 ══ -->
<div class="s-list">

  <!-- ─ 기본 설정 ─ -->
  <div class="s-section">기본 설정</div>

  <div class="s-item" onclick="location.href='design_settings.php'">
    <div class="s-item-text">
      <div class="s-item-title">디자인 설정</div>
      <div class="s-item-desc">메인 테마, 글자 크기와 색을 설정</div>
    </div>
  </div>

  <div class="s-item" onclick="location.href='currency.php'">
    <div class="s-item-text">
      <div class="s-item-title">통화와 환율</div>
      <div class="s-item-desc" id="settings-currency-desc">대한민국 원</div>
    </div>
  </div>

  <div class="s-item" onclick="location.href='category.php'">
    <div class="s-item-text">
      <div class="s-item-title">카테고리 편집</div>
      <div class="s-item-desc">지출과 수입 카테고리를 편집</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">결제수단 편집</div>
      <div class="s-item-desc">카드와 은행 목록을 편집</div>
    </div>
  </div>

  <!-- ─ 자동입력 설정 ─ -->
  <div class="s-section">자동입력 설정</div>

  <div class="s-item" onclick="toggleCheck('auto_push')">
    <div class="s-item-text">
      <div class="s-item-title">어플 푸쉬 알림 자동입력</div>
      <div class="s-item-desc">금융 어플 푸쉬 알림 자동입력 설정</div>
    </div>
    <div class="s-checkbox" id="chk-auto_push"></div>
  </div>

  <div class="s-item" onclick="toggleCheck('auto_sms')">
    <div class="s-item-text">
      <div class="s-item-title">문자 메세지 자동입력</div>
      <div class="s-item-desc">문자 메세지 자동입력 기능을 사용</div>
    </div>
    <div class="s-checkbox" id="chk-auto_sms"></div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">자동입력 장애 신고</div>
      <div class="s-item-desc">자동입력 되지 않은 알림과 문자를 신고</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">자동입력 방지 옵션</div>
      <div class="s-item-desc">자동입력을 선택적으로 방지</div>
    </div>
  </div>

  <!-- ─ 사용자 맞춤 설정 ─ -->
  <div class="s-section">사용자 맞춤 설정</div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">조작 방법 설정</div>
      <div class="s-item-desc">사용자 편의에 맞추어 조작 방법을 설정</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">카테고리 자동 분류</div>
      <div class="s-item-desc">카테고리 자동 분류 옵션을 설정</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">기간 시작일 설정</div>
      <div class="s-item-desc">한 달 시작일과 시작요일을 설정</div>
    </div>
  </div>

  <!-- ─ 세부 설정 ─ -->
  <div class="s-section">세부 설정</div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">지출과 수입 화면</div>
      <div class="s-item-desc">지출과 수입 화면의 세부 옵션을 설정</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">통계와 차트</div>
      <div class="s-item-desc">통계와 차트의 세부 옵션을 설정</div>
    </div>
  </div>

  <!-- ─ 잠금 기능 설정 ─ -->
  <div class="s-section">잠금 기능 설정</div>

  <div class="s-item" id="item-password_use" onclick="toggleCheck('password_use'); syncPasswordInput()">
    <div class="s-item-text">
      <div class="s-item-title">비밀번호 사용</div>
      <div class="s-item-desc">비밀번호를 사용합니다.</div>
    </div>
    <div class="s-checkbox" id="chk-password_use"></div>
  </div>

  <div class="s-item disabled" id="item-password_input">
    <div class="s-item-text">
      <div class="s-item-title">비밀번호 입력</div>
      <div class="s-item-desc">비밀번호를 입력하거나 수정</div>
    </div>
  </div>

  <!-- ─ 가계부 데이터 ─ -->
  <div class="s-section">가계부 데이터</div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">데이터 관리</div>
      <div class="s-item-desc">데이터를 백업하거나 복구</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">데이터 초기화</div>
      <div class="s-item-desc">가계부 데이터를 초기화</div>
    </div>
  </div>

  <!-- ─ 어플 정보 ─ -->
  <div class="s-section">어플 정보</div>

  <div class="s-item" onclick="location.href='premium.php'">
    <div class="s-item-text">
      <div class="s-item-title">프리미엄 구독</div>
      <div class="s-item-desc">프리미엄 버전으로 업그레이드</div>
    </div>
  </div>

  <div class="s-item">
    <div class="s-item-text">
      <div class="s-item-title">똑똑가계부 정보</div>
      <div class="s-item-desc">버전 1.0.0</div>
    </div>
  </div>

  <div class="s-item" style="cursor:default;">
    <div class="s-item-text">
      <div class="s-item-title" style="font-weight:400;">Cleveni Inc.</div>
      <div class="s-item-desc">cs@cleveni.com</div>
    </div>
  </div>

</div><!-- /.s-list -->

<!-- ══ 광고 바 ══ -->
<div class="ad-bar">
  <span class="ad-badge">광고</span>
  <span class="ad-text">광고 영역</span>
  <div class="ad-btn">
    <span class="material-icons">file_download</span>
  </div>
</div>

<script>
// ── 체크 상태를 localStorage에 저장/복원 ──
const KEYS = ['auto_push', 'auto_sms', 'password_use'];

function toggleCheck(key) {
  const current = localStorage.getItem('setting_' + key) === '1';
  const next = !current;
  localStorage.setItem('setting_' + key, next ? '1' : '0');
  renderCheck(key, next);
}

function renderCheck(key, checked) {
  const el = document.getElementById('chk-' + key);
  if (!el) return;
  el.classList.toggle('checked', checked);
}

function syncPasswordInput() {
  const enabled = localStorage.getItem('setting_password_use') === '1';
  const item = document.getElementById('item-password_input');
  if (item) item.classList.toggle('disabled', !enabled);
}

// 페이지 로드 시 복원
KEYS.forEach(key => {
  const saved = localStorage.getItem('setting_' + key) === '1';
  renderCheck(key, saved);
});
syncPasswordInput();

// 통화 설명 업데이트
(function(){
  const CNAMES = {"KRW":"대한민국 원","USD":"미국 달러","EUR":"유럽 유로","JPY":"일본 엔","GBP":"영국 파운드","CNY":"중국 위안","HKD":"홍콩 달러","TWD":"대만 달러","SGD":"싱가포르 달러","AUD":"호주 달러","CAD":"캐나다 달러","CHF":"스위스 프랑","THB":"태국 바트","MYR":"말레이시아 링깃","IDR":"인도네시아 루피아","PHP":"필리핀 페소","VND":"베트남 동","INR":"인도 루피"};
  const base = localStorage.getItem('currency_base') || 'KRW';
  const el   = document.getElementById('settings-currency-desc');
  if (el) el.textContent = (CNAMES[base] || base);
})();
</script>
<script>
(function(){
  var fs = localStorage.getItem('design_fontsize') || '보통';
  document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1';
})();
</script>
</body>
</html>
