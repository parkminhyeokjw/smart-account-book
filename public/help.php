<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_helpUserName  = htmlspecialchars($_SESSION['user_name']  ?? '');
$_helpUserEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$_helpLoggedIn  = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>도움말 — 똑똑가계부</title>
<script src="design_apply.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #F5F5F5;
  color: #212121;
  min-height: 100vh;
}

/* ── 헤더 ── */
.h-header {
  position: sticky; top: 0; z-index: 100;
  background: #fff;
  display: flex; align-items: center;
  height: 56px; padding: 0 4px;
  border-bottom: 1px solid #E0E0E0;
}
.h-icon {
  width: 48px; height: 48px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; border-radius: 50%; font-size: 22px; color: #212121; flex-shrink: 0;
}
.h-icon:active { background: #F5F5F5; }
.h-title { flex: 1; font-size: 22px; font-weight: 700; padding-left: 4px; }

/* ── 검색 바 ── */
.h-search {
  background: #fff;
  display: flex; align-items: center;
  padding: 10px 16px; gap: 8px;
  border-bottom: 1px solid #E0E0E0;
}
.h-search input {
  flex: 1; border: none; outline: none;
  font-size: 17px; font-family: inherit; color: #212121; background: transparent;
}
.h-search input::placeholder { color: #9E9E9E; }
.h-search-icon { font-size: 22px; color: #757575; }

/* ── 목록 ── */
.h-list { background: #fff; }
.h-item {
  padding: 18px 20px;
  font-size: 17px; color: #212121;
  border-bottom: 1px solid #F0F0F0;
  cursor: pointer;
}
.h-item:active { background: #F5F5F5; }
.h-item.hidden { display: none; }

/* ── 상세 뷰 ── */
#detail-view { display: none; background: #fff; min-height: 100vh; }
.detail-title {
  font-size: 20px; font-weight: 700;
  padding: 24px 20px 16px;
}
.detail-body {
  padding: 0 20px 40px;
  font-size: 17px; color: #424242; line-height: 1.8;
}
.detail-body p { margin-bottom: 20px; }
.detail-body strong { font-size: 18px; font-weight: 700; color: #212121; }
.detail-link {
  color: #E91E63; word-break: break-all;
  font-size: 16px; margin-top: 24px; display: block;
}

/* ── 문의하기 ── */
.contact-section {
  background: #fff;
  margin-top: 8px;
  border-top: 1px solid #E0E0E0;
}
.contact-header {
  padding: 18px 20px 14px;
  font-size: 18px; font-weight: 700; color: #212121;
}
.contact-sub {
  padding: 0 20px 14px;
  font-size: 14px; color: #757575; line-height: 1.6;
}
.contact-form { padding: 0 16px 32px; }
.cf-field { margin-bottom: 12px; }
.cf-label {
  display: block;
  font-size: 13px; font-weight: 600; color: #424242;
  margin-bottom: 6px;
}
.cf-label .req { color: #E91E63; margin-left: 2px; }
.cf-input, .cf-textarea {
  width: 100%; padding: 12px 14px;
  border: 1px solid #E0E0E0; border-radius: 10px;
  font-size: 16px; font-family: inherit; color: #212121;
  outline: none; background: #fff;
  -webkit-appearance: none;
}
.cf-input:focus, .cf-textarea:focus { border-color: #212121; }
.cf-textarea { resize: none; height: 120px; line-height: 1.6; }
.cf-submit {
  width: 100%; padding: 15px;
  background: #212121; color: #fff;
  border: none; border-radius: 12px;
  font-size: 17px; font-weight: 700;
  cursor: pointer; margin-top: 4px;
}
.cf-submit:active { background: #424242; }
.cf-msg {
  margin-top: 10px; padding: 12px 14px;
  border-radius: 10px; font-size: 15px; font-weight: 500;
  display: none;
}
.cf-msg.ok  { background: #E8F5E9; color: #2E7D32; }
.cf-msg.err { background: #FFEBEE; color: #C62828; }
.cf-char { font-size: 12px; color: #9E9E9E; text-align: right; margin-top: 4px; }
</style>
</head>
<body>

<!-- ══ 헤더 ══ -->
<header class="h-header">
  <div class="h-icon" id="back-btn" onclick="goBack()">←</div>
  <div class="h-title">도움말</div>
  <div class="h-icon">⋮</div>
</header>

<!-- ══ 목록 뷰 ══ -->
<div id="list-view">
  <div class="h-search">
    <input type="text" id="search-input" placeholder="검색" oninput="filterItems(this.value)">
    <span class="h-search-icon">🔍</span>
  </div>
  <div class="h-list" id="help-list">
    <div class="h-item" onclick="showDetail(0)">핸드폰 변경 시 자료 옮기는 방법 (1)</div>
    <div class="h-item" onclick="showDetail(1)">핸드폰 변경 시 자료 옮기는 방법 (2)</div>
    <div class="h-item" onclick="showDetail(2)">카드 등록은 따로 하지 않아도 되나요?</div>
    <div class="h-item" onclick="showDetail(3)">카드사 은행 어플 푸쉬 알림 연동하는법?</div>
    <div class="h-item" onclick="showDetail(4)">가계부 자료는 안전하게 보관되나요?</div>
    <div class="h-item" onclick="showDetail(5)">신용카드 결제대금 따로 기록하는 법</div>
    <div class="h-item" onclick="showDetail(6)">달력모드를 처음화면으로 고정하려면?</div>
    <div class="h-item" onclick="showDetail(7)">가계부에 비밀번호를 설정하려면?</div>
    <div class="h-item" onclick="showDetail(8)">데이터를 백업하려면?</div>
    <div class="h-item" onclick="showDetail(9)">업데이트를 꼭 해야 하나요?</div>
    <div class="h-item" onclick="showDetail(10)">문자 자동입력이 제대로 되지 않아요!</div>
    <div class="h-item" onclick="showDetail(11)">특정 카드 문자만 자동입력 방지하려면?</div>
    <div class="h-item" onclick="showDetail(12)">한 카드회사의 여러 카드를 구분하는법?</div>
    <div class="h-item" onclick="showDetail(13)">지출/수입 내역을 입력하려면?</div>
    <div class="h-item" onclick="showDetail(14)">지출/수입 내역을 수정하려면?</div>
    <div class="h-item" onclick="showDetail(15)">지출/수입 내역을 삭제하려면?</div>
    <div class="h-item" onclick="showDetail(16)">지출/수입 내역을 검색하려면?</div>
    <div class="h-item" onclick="showDetail(17)">지출/수입 내역을 엑셀파일로 내보내려면?</div>
    <div class="h-item" onclick="showDetail(18)">지출/수입 카테고리를 설정하려면?</div>
    <div class="h-item" onclick="showDetail(19)">다른 월의 지출/수입 내역을 보려면?</div>
    <div class="h-item" onclick="showDetail(20)">돈 모아서 내 카드로 결제</div>
    <div class="h-item" onclick="showDetail(21)">일정 금액 분류 나누기</div>
    <div class="h-item" onclick="showDetail(22)">어플 설치 전의 내역 문자를 입력하려면?</div>
    <div class="h-item" onclick="showDetail(23)">자주 입력하는 내역의 관리 방법?</div>
    <div class="h-item" onclick="showDetail(24)">적금 등의 고정지출 자동 입력은?</div>
    <div class="h-item" onclick="showDetail(25)">한 달의 시작일을 변경하려면?</div>
    <div class="h-item" onclick="showDetail(26)">원하는 결제수단을 등록하려면?</div>
    <div class="h-item" onclick="showDetail(27)">예산관리 기능이란?</div>
    <div class="h-item" onclick="showDetail(28)">예산을 입력하려면?</div>
    <div class="h-item" onclick="showDetail(29)">예산을 수정하거나 삭제하려면?</div>
    <div class="h-item" onclick="showDetail(30)">수입 금액과 전체 예산이 달라요!</div>
    <div class="h-item" onclick="showDetail(31)">적정 사용 예산이 정해지는 기준?</div>
    <div class="h-item" onclick="showDetail(32)">주별 예산, 월별 예산, 연별 예산이란?</div>
    <div class="h-item" onclick="showDetail(33)">이전에 입력한 예산을 복사하려면?</div>
    <div class="h-item" onclick="showDetail(34)">'상위 예산 분배'란 무엇인가요?</div>
    <div class="h-item" onclick="showDetail(35)">사용할 수 있는 통계의 종류는?</div>
    <div class="h-item" onclick="showDetail(36)">자료통계 탭 화면의 구성은?</div>
    <div class="h-item" onclick="showDetail(37)">자료 통계에서 목록을 누르면?</div>
    <div class="h-item" onclick="showDetail(38)">자료 통계를 엑셀파일로 내보내려면?</div>
    <div class="h-item" onclick="showDetail(39)">사용할 수 있는 차트의 종류는?</div>
    <div class="h-item" onclick="showDetail(40)">자료차트 탭 화면의 구성은?</div>
    <div class="h-item" onclick="showDetail(41)">차트의 내용을 클릭하면?</div>
  </div>

  <!-- ══ 문의하기 섹션 ══ -->
  <div class="contact-section" id="contact-section">
    <div class="contact-header">문의하기</div>
    <p class="contact-sub">도움말에서 해결되지 않는 문제가 있으시면 아래 양식으로 문의해 주세요.</p>
    <div class="contact-form">
      <?php if (!$_helpLoggedIn): ?>
      <div class="cf-field">
        <label class="cf-label">이름</label>
        <input type="text" id="cf-name" class="cf-input" placeholder="이름을 입력해주세요" maxlength="50">
      </div>
      <div class="cf-field">
        <label class="cf-label">이메일 <span style="color:#9E9E9E;font-weight:400">(선택)</span></label>
        <input type="email" id="cf-email" class="cf-input" placeholder="답변 받을 이메일 (선택사항)">
      </div>
      <?php endif; ?>
      <div class="cf-field">
        <label class="cf-label">제목 <span class="req">*</span></label>
        <input type="text" id="cf-subject" class="cf-input" placeholder="문의 제목을 입력해주세요" maxlength="200">
      </div>
      <div class="cf-field">
        <label class="cf-label">내용 <span class="req">*</span></label>
        <textarea id="cf-body" class="cf-textarea" placeholder="문의 내용을 자세히 적어주세요" maxlength="2000"
                  oninput="updateChar(this)"></textarea>
        <div class="cf-char"><span id="cf-char-count">0</span>/2000</div>
      </div>
      <button class="cf-submit" onclick="submitInquiry()">문의 등록</button>
      <div class="cf-msg" id="cf-msg"></div>
    </div>
  </div>
</div>

<!-- ══ 상세 뷰 ══ -->
<div id="detail-view">
  <div class="detail-title" id="detail-title"></div>
  <div class="detail-body" id="detail-body"></div>
</div>

<script>
var ITEMS = [
  {
    title: '핸드폰 변경 시 자료 옮기는 방법 (1)',
    body: `<p><strong>1. 이전 폰에서</strong></p>
<p>좌측 메뉴를 열기 - 백업과 복구 - 구글 계정으로 백업</p>
<p><strong>2. 새 폰에서</strong></p>
<p>좌측 메뉴를 열기 - 백업과 복구 - 구글 계정에서 복구 - 백업한 파일 선택</p>
<a class="detail-link" href="#">http://cafe.naver.com/clevmoney/4</a>`
  },
  {
    title: '핸드폰 변경 시 자료 옮기는 방법 (2)',
    body: `<p><strong>1. 이전 폰에서</strong></p>
<p>좌측 메뉴를 열기 - 백업과 복구 - 파일로 백업 - 백업 파일을 이메일 또는 클라우드로 저장</p>
<p><strong>2. 새 폰에서</strong></p>
<p>저장한 백업 파일을 새 폰으로 이동 후 좌측 메뉴 - 백업과 복구 - 파일에서 복구 - 백업 파일 선택</p>
<a class="detail-link" href="#">http://cafe.naver.com/clevmoney/5</a>`
  },
  {
    title: '카드 등록은 따로 하지 않아도 되나요?',
    body: `<p>별도의 카드 등록 없이도 앱을 사용할 수 있습니다.</p>
<p>문자 자동입력 기능을 사용하면 카드 결제 문자를 받을 때 자동으로 내역이 입력됩니다.</p>
<p>결제수단 편집에서 카드와 은행 계좌를 미리 등록해두면 내역 입력 시 더욱 편리하게 선택할 수 있습니다.</p>
<p>설정 → 결제수단 편집에서 원하는 카드/은행을 추가해보세요.</p>`
  },
  {
    title: '카드사 은행 어플 푸쉬 알림 연동하는법?',
    body: `<p><strong>1. 알림 접근 권한 허용</strong></p>
<p>스마트폰 설정 → 알림 → 알림 접근 → 똑똑가계부 허용</p>
<p><strong>2. 앱 내 설정</strong></p>
<p>설정 → 자동입력 설정 → 어플 푸쉬 알림 자동입력 활성화</p>
<p><strong>3. 카드사/은행 앱에서</strong></p>
<p>각 카드사/은행 앱의 결제 알림 설정을 켜두면 결제 즉시 자동으로 가계부에 입력됩니다.</p>`
  },
  {
    title: '가계부 자료는 안전하게 보관되나요?',
    body: `<p>모든 가계부 자료는 사용자의 기기 내부 데이터베이스에 저장됩니다.</p>
<p>자료가 외부 서버로 전송되지 않으므로 개인정보 유출 걱정이 없습니다.</p>
<p>단, 기기 초기화나 앱 삭제 시 데이터가 삭제될 수 있으므로 정기적인 백업을 권장합니다.</p>
<p>백업 방법: 좌측 메뉴 → 백업과 복구 → 구글 계정으로 백업</p>`
  },
  {
    title: '신용카드 결제대금 따로 기록하는 법',
    body: `<p>신용카드는 사용 시점과 실제 결제 시점이 다릅니다.</p>
<p><strong>방법 1: 사용 시점에 기록</strong></p>
<p>카드 결제 문자를 받을 때마다 지출로 입력합니다. 이 방법이 실제 소비를 추적하기에 적합합니다.</p>
<p><strong>방법 2: 결제일에 기록</strong></p>
<p>매월 카드 결제일에 전체 결제금액을 한 번에 지출로 입력합니다.</p>
<p>카테고리를 '카드대금'으로 설정하면 통계에서 구분하기 쉽습니다.</p>`
  },
  {
    title: '달력모드를 처음화면으로 고정하려면?',
    body: `<p>현재 버전에서는 달력 탭을 기본 시작 화면으로 고정하는 기능을 지원하지 않습니다.</p>
<p>앱 실행 시 항상 지출 내역 탭이 먼저 표시됩니다.</p>
<p>하단 탭에서 달력 아이콘을 탭하여 달력 모드로 전환할 수 있습니다.</p>
<p>추후 업데이트에서 시작 화면 설정 기능이 추가될 예정입니다.</p>`
  },
  {
    title: '가계부에 비밀번호를 설정하려면?',
    body: `<p><strong>비밀번호 설정 방법</strong></p>
<p>1. 설정 → 잠금 기능 설정 → 비밀번호 사용 활성화</p>
<p>2. 비밀번호 입력 항목이 활성화되면 클릭하여 4자리 비밀번호 입력</p>
<p>3. 확인을 위해 동일한 비밀번호를 한 번 더 입력</p>
<p>설정 후 앱 실행 시마다 비밀번호 입력 화면이 표시됩니다.</p>
<p><strong>주의:</strong> 비밀번호를 잊어버리면 앱 데이터를 초기화해야 할 수 있습니다.</p>`
  },
  {
    title: '데이터를 백업하려면?',
    body: `<p><strong>구글 계정 백업 (권장)</strong></p>
<p>좌측 메뉴(≡) → 백업과 복구 → 구글 계정으로 백업</p>
<p>구글 드라이브에 자동으로 저장되므로 기기가 바뀌어도 복구 가능합니다.</p>
<p><strong>파일 백업</strong></p>
<p>좌측 메뉴(≡) → 백업과 복구 → 파일로 백업</p>
<p>생성된 백업 파일을 이메일, 클라우드 등 원하는 곳에 저장하세요.</p>
<p>정기적인 백업으로 소중한 가계부 데이터를 지키세요.</p>`
  },
  {
    title: '업데이트를 꼭 해야 하나요?',
    body: `<p>업데이트는 필수가 아니지만 적극 권장합니다.</p>
<p>업데이트를 통해 다음과 같은 혜택을 받을 수 있습니다:</p>
<p>• 새로운 기능 추가<br>• 버그 수정 및 안정성 향상<br>• 보안 취약점 패치<br>• 성능 개선</p>
<p>단, 업데이트 전 반드시 데이터 백업을 먼저 진행하시기 바랍니다.</p>`
  },
  {
    title: '문자 자동입력이 제대로 되지 않아요!',
    body: `<p><strong>확인사항 1: 문자 읽기 권한</strong></p>
<p>설정 → 앱 → 똑똑가계부 → 권한 → 문자메시지 허용</p>
<p><strong>확인사항 2: 자동입력 설정</strong></p>
<p>앱 설정 → 자동입력 설정 → 문자 메시지 자동입력 활성화</p>
<p><strong>확인사항 3: 문자 형식</strong></p>
<p>일부 카드사/은행은 문자 형식이 달라 인식이 안 될 수 있습니다. 이 경우 자동입력 장애 신고 기능으로 신고해주시면 빠르게 업데이트됩니다.</p>`
  },
  {
    title: '특정 카드 문자만 자동입력 방지하려면?',
    body: `<p>특정 카드나 은행의 문자를 자동입력에서 제외하려면:</p>
<p>설정 → 자동입력 설정 → 자동입력 방지 옵션</p>
<p>목록에서 제외하고 싶은 카드사/은행을 선택하면 해당 문자는 자동입력되지 않습니다.</p>
<p>또는 내역 입력 시 나타나는 문자 팝업에서 '이 카드 무시하기' 옵션을 선택할 수도 있습니다.</p>`
  },
  {
    title: '한 카드회사의 여러 카드를 구분하는법?',
    body: `<p>같은 카드회사의 여러 카드를 구분하려면 결제수단 편집에서 각 카드를 별도로 등록하세요.</p>
<p>설정 → 결제수단 편집 → + 추가</p>
<p>카드 이름을 구체적으로 입력하세요. 예: "신한 체크카드", "신한 신용카드"</p>
<p>문자 자동입력 시 카드번호 뒷자리로 카드를 구분할 수 있도록 카드번호 마지막 4자리를 등록해두면 더욱 정확하게 구분됩니다.</p>`
  },
  {
    title: '지출/수입 내역을 입력하려면?',
    body: `<p><strong>방법 1: 하단 + 버튼</strong></p>
<p>화면 우측 하단의 + 버튼을 탭합니다.</p>
<p><strong>방법 2: 날짜 탭</strong></p>
<p>지출/수입 탭에서 날짜 행의 + 아이콘을 탭합니다.</p>
<p>금액, 카테고리, 결제수단, 메모를 입력하고 저장합니다.</p>
<p>날짜는 기본적으로 오늘 날짜가 선택되며, 탭하여 변경할 수 있습니다.</p>`
  },
  {
    title: '지출/수입 내역을 수정하려면?',
    body: `<p>수정하려는 내역을 탭하면 상세 화면이 열립니다.</p>
<p>상세 화면 우측 상단의 수정(✏) 아이콘을 탭합니다.</p>
<p>금액, 카테고리, 결제수단, 메모, 날짜 등을 수정한 후 저장합니다.</p>
<p>또는 내역을 길게 눌러 편집 모드로 진입할 수도 있습니다.</p>`
  },
  {
    title: '지출/수입 내역을 삭제하려면?',
    body: `<p><strong>방법 1: 개별 삭제</strong></p>
<p>삭제할 내역을 탭 → 상세 화면 → 삭제(🗑) 아이콘 탭</p>
<p><strong>방법 2: 스와이프 삭제</strong></p>
<p>내역을 왼쪽으로 스와이프하면 삭제 버튼이 나타납니다.</p>
<p><strong>주의:</strong> 삭제된 내역은 복구할 수 없으니 주의하세요.</p>`
  },
  {
    title: '지출/수입 내역을 검색하려면?',
    body: `<p>지출 또는 수입 탭 상단의 🔍 검색 아이콘을 탭합니다.</p>
<p>검색창에 금액, 카테고리명, 메모 내용 등 원하는 키워드를 입력하면 관련 내역이 필터링되어 표시됩니다.</p>
<p>날짜 범위, 카테고리, 결제수단별로 필터를 적용하여 더욱 세밀하게 검색할 수 있습니다.</p>`
  },
  {
    title: '지출/수입 내역을 엑셀파일로 내보내려면?',
    body: `<p>지출 또는 수입 탭 상단의 더보기(⋮) 메뉴를 탭합니다.</p>
<p>'엑셀로 내보내기' 옵션을 선택합니다.</p>
<p>내보낼 기간을 선택한 후 내보내기 버튼을 탭하면 .xlsx 파일이 생성됩니다.</p>
<p>생성된 파일은 이메일로 전송하거나 클라우드에 저장할 수 있습니다.</p>`
  },
  {
    title: '지출/수입 카테고리를 설정하려면?',
    body: `<p>설정 → 카테고리 편집</p>
<p>기존 카테고리를 탭하면 이름과 아이콘을 수정할 수 있습니다.</p>
<p>하단 + 버튼으로 새 카테고리를 추가할 수 있습니다.</p>
<p>카테고리를 길게 누르면 순서를 변경하거나 삭제할 수 있습니다.</p>
<p>지출 카테고리와 수입 카테고리를 각각 별도로 관리할 수 있습니다.</p>`
  },
  {
    title: '다른 월의 지출/수입 내역을 보려면?',
    body: `<p>화면 상단의 월 표시(예: 2024년 3월)를 탭합니다.</p>
<p>좌우 화살표(〈 〉)를 탭하여 이전/다음 달로 이동할 수 있습니다.</p>
<p>또는 월 표시를 탭하면 월 선택 팝업이 열려 원하는 년도와 월을 바로 선택할 수 있습니다.</p>`
  },
  {
    title: '돈 모아서 내 카드로 결제',
    body: `<p>목돈을 모아 카드 결제하는 방식은 다음과 같이 기록하세요.</p>
<p><strong>적금/저축 시</strong></p>
<p>매월 저축하는 금액을 '저축' 카테고리로 지출 처리합니다.</p>
<p><strong>실제 결제 시</strong></p>
<p>모은 돈으로 결제할 때는 '지출'로 기록하고, 저축에서 인출한 금액은 '수입'으로 기록하여 상계합니다.</p>
<p>이렇게 하면 실제 소비 흐름을 정확하게 추적할 수 있습니다.</p>`
  },
  {
    title: '일정 금액 분류 나누기',
    body: `<p>하나의 결제를 여러 카테고리로 분류하려면:</p>
<p>내역 입력 시 '분할 입력' 기능을 사용합니다.</p>
<p>예: 마트에서 식료품 30,000원 + 생활용품 20,000원을 한 번에 결제한 경우</p>
<p>총 50,000원 중 식료품 30,000원, 생활용품 20,000원으로 분할하여 각각 다른 카테고리로 입력할 수 있습니다.</p>
<p>내역 입력 화면에서 '분할' 버튼을 탭하세요.</p>`
  },
  {
    title: '어플 설치 전의 내역 문자를 입력하려면?',
    body: `<p>앱 설치 전에 받은 카드/은행 문자도 소급하여 입력할 수 있습니다.</p>
<p>설정 → 자동입력 설정 → 과거 문자 불러오기</p>
<p>기간을 선택하면 해당 기간의 금융 문자를 불러와 한꺼번에 확인하고 입력할 수 있습니다.</p>
<p>불필요한 문자는 개별로 제외할 수 있습니다.</p>`
  },
  {
    title: '자주 입력하는 내역의 관리 방법?',
    body: `<p>자주 입력하는 내역은 '즐겨찾기' 기능으로 빠르게 재입력할 수 있습니다.</p>
<p><strong>즐겨찾기 등록</strong></p>
<p>내역 입력 후 상세 화면에서 별★ 아이콘을 탭하면 즐겨찾기에 저장됩니다.</p>
<p><strong>즐겨찾기 사용</strong></p>
<p>+ 버튼 탭 → 즐겨찾기 탭 → 원하는 내역 선택</p>
<p>금액, 날짜 등을 확인하고 바로 저장하면 됩니다.</p>`
  },
  {
    title: '적금 등의 고정지출 자동 입력은?',
    body: `<p>매월 고정적으로 발생하는 지출은 '정기 거래' 기능을 사용하세요.</p>
<p><strong>정기 거래 등록</strong></p>
<p>설정 → 사용자 맞춤 설정 → 정기 거래 → + 추가</p>
<p>금액, 카테고리, 결제일을 입력하면 해당 날짜에 자동으로 내역이 입력됩니다.</p>
<p>월세, 적금, 보험료, 구독 서비스 등에 활용하세요.</p>`
  },
  {
    title: '한 달의 시작일을 변경하려면?',
    body: `<p>기본 설정은 매월 1일이 한 달의 시작입니다.</p>
<p>급여일 기준으로 가계부를 관리하려면 시작일을 변경하세요.</p>
<p>설정 → 사용자 맞춤 설정 → 기간 시작일 설정</p>
<p>원하는 날짜(1~31일)를 선택하면 해당 날짜부터 다음 달 같은 날 전날까지가 한 달로 집계됩니다.</p>`
  },
  {
    title: '원하는 결제수단을 등록하려면?',
    body: `<p>설정 → 결제수단 편집 → + 버튼</p>
<p>카드사/은행 선택 → 카드 이름 입력 → 저장</p>
<p>등록한 결제수단은 내역 입력 시 선택 목록에 나타납니다.</p>
<p>자주 사용하는 결제수단을 상단에 고정하려면 길게 눌러 드래그하여 순서를 변경하세요.</p>`
  },
  {
    title: '예산관리 기능이란?',
    body: `<p>예산관리 기능은 카테고리별 또는 전체 지출 목표를 설정하고 실제 지출과 비교하여 과소비를 방지하는 기능입니다.</p>
<p>예산 탭(하단 메뉴)에서 사용할 수 있습니다.</p>
<p><strong>주요 기능</strong></p>
<p>• 월별/주별/연별 예산 설정<br>• 카테고리별 세부 예산<br>• 예산 대비 지출 진행률 표시<br>• 초과 시 경고 알림</p>`
  },
  {
    title: '예산을 입력하려면?',
    body: `<p>하단 메뉴에서 예산 탭을 탭합니다.</p>
<p>우측 상단 + 버튼 또는 '예산 추가' 버튼을 탭합니다.</p>
<p>예산 기간(월별/주별/연별)과 금액을 입력합니다.</p>
<p>카테고리별 세부 예산을 추가하려면 '카테고리 예산 추가'를 탭하여 각 카테고리에 금액을 배정합니다.</p>
<p>저장하면 예산 탭에서 진행률을 확인할 수 있습니다.</p>`
  },
  {
    title: '예산을 수정하거나 삭제하려면?',
    body: `<p><strong>수정</strong></p>
<p>예산 탭에서 수정할 예산 항목을 탭 → 수정 아이콘(✏) 탭 → 금액 변경 후 저장</p>
<p><strong>삭제</strong></p>
<p>예산 항목을 탭 → 삭제 아이콘(🗑) 탭 → 확인</p>
<p>또는 예산 항목을 길게 눌러 삭제 옵션을 선택할 수도 있습니다.</p>`
  },
  {
    title: '수입 금액과 전체 예산이 달라요!',
    body: `<p>예산은 수입과 별개로 직접 설정하는 값입니다.</p>
<p>수입이 자동으로 예산에 반영되지 않는 것이 정상입니다.</p>
<p>수입과 동일하게 예산을 맞추려면:</p>
<p>예산 탭 → 예산 수정 → 수입 금액과 동일하게 입력</p>
<p>또는 '수입 기반 자동 예산' 기능을 활성화하면 수입 입력 시 자동으로 예산이 업데이트됩니다.</p>`
  },
  {
    title: '적정 사용 예산이 정해지는 기준?',
    body: `<p>적정 사용 예산은 다음 공식으로 계산됩니다:</p>
<p><strong>적정 사용 예산 = 전체 예산 × (오늘까지 경과 일수 / 해당 월 총 일수)</strong></p>
<p>예: 월 예산 300,000원, 30일 중 10일 경과 → 적정 사용 100,000원</p>
<p>실제 지출이 적정 사용액보다 적으면 초록색, 많으면 빨간색으로 표시됩니다.</p>`
  },
  {
    title: '주별 예산, 월별 예산, 연별 예산이란?',
    body: `<p><strong>월별 예산</strong></p>
<p>한 달(기간 시작일 기준) 단위로 지출 목표를 설정합니다. 가장 일반적으로 사용됩니다.</p>
<p><strong>주별 예산</strong></p>
<p>일주일 단위로 지출 목표를 설정합니다. 매주 리셋되어 주간 소비 습관을 관리하기 좋습니다.</p>
<p><strong>연별 예산</strong></p>
<p>1년 단위의 큰 지출 계획을 관리할 때 사용합니다. 여행, 가전 구입 등 연간 특별 지출에 적합합니다.</p>`
  },
  {
    title: '이전에 입력한 예산을 복사하려면?',
    body: `<p>예산 탭 상단의 더보기(⋮) 메뉴를 탭합니다.</p>
<p>'이전 예산 복사' 옵션을 선택합니다.</p>
<p>복사할 월을 선택하면 해당 월의 예산 구조가 현재 월에 동일하게 적용됩니다.</p>
<p>복사 후 개별 항목을 수정할 수 있습니다.</p>`
  },
  {
    title: "'상위 예산 분배'란 무엇인가요?",
    body: `<p>상위 예산 분배는 전체 예산을 카테고리별로 자동으로 배분하는 기능입니다.</p>
<p>전체 예산을 입력한 후 '상위 예산 분배'를 탭하면 이전 달의 지출 비율을 기반으로 각 카테고리에 예산이 자동으로 분배됩니다.</p>
<p>자동 분배 후 각 카테고리의 예산을 수동으로 조정할 수 있습니다.</p>
<p>이 기능을 사용하면 예산 설정 시간을 크게 절약할 수 있습니다.</p>`
  },
  {
    title: '사용할 수 있는 통계의 종류는?',
    body: `<p>똑똑가계부는 다양한 통계를 제공합니다:</p>
<p>• <strong>기간별 통계</strong>: 일별, 주별, 월별, 연별 지출/수입 합계<br>• <strong>카테고리별 통계</strong>: 카테고리별 지출 비율 및 금액<br>• <strong>결제수단별 통계</strong>: 카드/계좌별 사용 내역<br>• <strong>수지 통계</strong>: 수입/지출/잔액 흐름<br>• <strong>요일별/시간대별 통계</strong>: 소비 패턴 분석</p>`
  },
  {
    title: '자료통계 탭 화면의 구성은?',
    body: `<p>자료통계 탭은 다음과 같이 구성됩니다:</p>
<p><strong>상단</strong>: 통계 종류 선택 탭 (지출/수입/수지)</p>
<p><strong>중단</strong>: 분류 기준 선택 (기간/카테고리/결제수단 등)</p>
<p><strong>표</strong>: 각 항목별 금액과 비율</p>
<p><strong>합계 행</strong>: 선택 기간의 전체 합계</p>
<p>표의 항목을 탭하면 해당 기간/카테고리의 상세 내역 목록을 볼 수 있습니다.</p>`
  },
  {
    title: '자료 통계에서 목록을 누르면?',
    body: `<p>통계 표에서 특정 항목(기간 또는 카테고리)을 탭하면 해당 항목의 세부 내역 목록이 표시됩니다.</p>
<p>예: 3월 식비 항목을 탭 → 3월에 식비로 지출된 모든 내역 목록 표시</p>
<p>각 내역을 다시 탭하면 해당 내역의 상세 화면으로 이동합니다.</p>`
  },
  {
    title: '자료 통계를 엑셀파일로 내보내려면?',
    body: `<p>자료통계 탭 상단의 더보기(⋮) 메뉴를 탭합니다.</p>
<p>'엑셀로 내보내기' 옵션을 선택합니다.</p>
<p>내보낼 기간과 통계 종류를 선택합니다.</p>
<p>내보내기 버튼을 탭하면 .xlsx 파일이 생성되며 이메일 또는 클라우드로 저장할 수 있습니다.</p>`
  },
  {
    title: '사용할 수 있는 차트의 종류는?',
    body: `<p>똑똑가계부는 다양한 차트를 제공합니다:</p>
<p>• <strong>파이차트</strong>: 카테고리별 지출 비율을 원형으로 표시<br>• <strong>막대차트</strong>: 기간별 지출/수입을 막대로 비교<br>• <strong>선형차트</strong>: 지출 추이를 선으로 표시<br>• <strong>누적차트</strong>: 월별 누적 지출/수입 흐름</p>`
  },
  {
    title: '자료차트 탭 화면의 구성은?',
    body: `<p><strong>상단</strong>: 차트 종류 선택 (파이/막대/선형 등)</p>
<p><strong>중단</strong>: 차트 표시 영역 (탭하면 상세 정보 표시)</p>
<p><strong>하단</strong>: 범례 및 요약 정보</p>
<p>차트 위에서 좌우로 스와이프하면 이전/다음 달의 차트로 이동합니다.</p>
<p>두 손가락으로 확대/축소하여 특정 구간을 자세히 볼 수 있습니다.</p>`
  },
  {
    title: '차트의 내용을 클릭하면?',
    body: `<p>차트의 특정 항목을 탭하면 해당 항목의 상세 정보가 표시됩니다.</p>
<p><strong>파이차트</strong>: 조각을 탭 → 해당 카테고리의 금액과 비율, 내역 목록</p>
<p><strong>막대차트</strong>: 막대를 탭 → 해당 기간의 지출/수입 합계, 내역 목록</p>
<p><strong>선형차트</strong>: 점을 탭 → 해당 날짜의 지출 내역</p>
<p>내역 목록에서 개별 항목을 다시 탭하면 상세 화면으로 이동합니다.</p>`
  }
];

var inDetail = false;

function showDetail(idx) {
  var item = ITEMS[idx];
  document.getElementById('detail-title').textContent = item.title;
  document.getElementById('detail-body').innerHTML = item.body;
  document.getElementById('list-view').style.display = 'none';
  document.getElementById('detail-view').style.display = 'block';
  inDetail = true;
  window.scrollTo(0, 0);
}

function goBack() {
  if (inDetail) {
    document.getElementById('detail-view').style.display = 'none';
    document.getElementById('list-view').style.display = 'block';
    inDetail = false;
    window.scrollTo(0, 0);
  } else {
    history.back();
  }
}

function filterItems(q) {
  q = q.trim().toLowerCase();
  document.querySelectorAll('.h-item').forEach(function(el) {
    el.classList.toggle('hidden', q && !el.textContent.toLowerCase().includes(q));
  });
  // 검색 시 문의하기 섹션 숨김/표시
  var cs = document.getElementById('contact-section');
  if (cs) cs.style.display = q ? 'none' : '';
}

function updateChar(el) {
  var c = document.getElementById('cf-char-count');
  if (c) c.textContent = el.value.length;
}

function submitInquiry() {
  var subject = (document.getElementById('cf-subject') || {}).value || '';
  var body    = (document.getElementById('cf-body')    || {}).value || '';
  var msgEl   = document.getElementById('cf-msg');
  var btn     = document.querySelector('.cf-submit');

  subject = subject.trim();
  body    = body.trim();

  <?php if (!$_helpLoggedIn): ?>
  var nameVal  = ((document.getElementById('cf-name')  || {}).value || '').trim();
  var emailVal = ((document.getElementById('cf-email') || {}).value || '').trim();
  if (!nameVal) { showMsg('이름을 입력해주세요.', false); return; }
  <?php endif; ?>

  if (!subject) { showMsg('제목을 입력해주세요.', false); return; }
  if (!body)    { showMsg('내용을 입력해주세요.', false); return; }

  btn.disabled    = true;
  btn.textContent = '등록 중...';

  var payload = { subject: subject, body: body };
  <?php if (!$_helpLoggedIn): ?>
  payload.name  = nameVal;
  payload.email = emailVal;
  <?php endif; ?>

  fetch('../api/inquiry.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    showMsg(d.msg, d.ok);
    if (d.ok) {
      // 폼 초기화
      document.getElementById('cf-subject').value = '';
      document.getElementById('cf-body').value    = '';
      var cc = document.getElementById('cf-char-count');
      if (cc) cc.textContent = '0';
      <?php if (!$_helpLoggedIn): ?>
      var ni = document.getElementById('cf-name');
      var ei = document.getElementById('cf-email');
      if (ni) ni.value = '';
      if (ei) ei.value = '';
      <?php endif; ?>
    }
  })
  .catch(function() { showMsg('오류가 발생했습니다. 잠시 후 다시 시도해주세요.', false); })
  .finally(function() {
    btn.disabled    = false;
    btn.textContent = '문의 등록';
  });
}

function showMsg(text, ok) {
  var el = document.getElementById('cf-msg');
  if (!el) return;
  el.textContent  = text;
  el.className    = 'cf-msg ' + (ok ? 'ok' : 'err');
  el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  if (ok) setTimeout(function(){ el.style.display='none'; }, 5000);
}
</script>
<script>
(function(){
  var fs = localStorage.getItem('design_fontsize') || '보통';
  document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1';
})();
</script>
</body>
</html>
