<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>프리미엄 구독 — 똑똑가계부</title>
<script src="design_apply.js"></script>
<script src="currency_apply.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #fff;
  color: #212121;
  min-height: 100vh;
  padding-bottom: 80px;
}

/* ── 헤더 ── */
.p-header {
  display: flex;
  align-items: center;
  padding: 8px 4px 8px 4px;
  height: 56px;
}
.p-header-icon {
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border-radius: 50%;
  flex-shrink: 0;
  font-size: 22px;
  color: #212121;
}
.p-header-icon:active { background: #F5F5F5; }
.p-header-title {
  flex: 1;
  font-size: 22px;
  font-weight: 700;
  color: #212121;
  padding-left: 4px;
}

/* ── 본문 ── */
.p-body {
  padding: 16px 24px 0;
}

/* ── 혜택 목록 ── */
.p-benefits {
  margin: 24px 0 32px;
}
.p-benefit {
  font-size: 20px;
  font-weight: 500;
  color: #212121;
  line-height: 1.5;
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}
.p-benefit::before {
  content: '•';
  flex-shrink: 0;
}

/* ── 플랜 카드 ── */
.p-plans {
  display: flex;
  gap: 12px;
  margin-bottom: 28px;
}
.p-plan {
  flex: 1;
  border: 1.5px solid #BDBDBD;
  border-radius: 12px;
  padding: 24px 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  transition: border-color .15s, border-width .15s;
}
.p-plan.selected {
  border: 3px solid #212121;
}
.p-plan-period {
  font-size: 18px;
  font-weight: 400;
  color: #9E9E9E;
}
.p-plan.selected .p-plan-period {
  font-weight: 700;
  color: #212121;
}
.p-plan-price {
  font-size: 26px;
  font-weight: 400;
  color: #9E9E9E;
}
.p-plan.selected .p-plan-price {
  font-weight: 700;
  color: #212121;
}

/* ── 설명 텍스트 ── */
.p-desc {
  font-size: 17px;
  color: #424242;
  line-height: 1.7;
  margin-bottom: 40px;
}

/* ── 이용약관 ── */
.p-terms {
  margin-bottom: 32px;
}
.p-term {
  font-size: 16px;
  color: #424242;
  line-height: 1.7;
  margin-bottom: 10px;
}

/* ── 구매 내역 복원 ── */
.p-restore {
  text-align: center;
  margin: 32px 0 24px;
}
.p-restore a {
  font-size: 17px;
  color: #212121;
  text-decoration: underline;
  cursor: pointer;
}

/* ── 하단 버튼 ── */
.p-cta {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 12px 16px;
  background: #fff;
}
.p-cta-btn {
  width: 100%;
  height: 56px;
  background: #212121;
  border: none;
  border-radius: 12px;
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  letter-spacing: .3px;
}
.p-cta-btn:active { background: #424242; }
</style>
</head>
<body>

<!-- ══ 헤더 ══ -->
<header class="p-header">
  <div class="p-header-icon" onclick="history.back()">✕</div>
  <div class="p-header-title">프리미엄 구독</div>
</header>

<!-- ══ 본문 ══ -->
<div class="p-body">

  <!-- 혜택 -->
  <div class="p-benefits">
    <div class="p-benefit">모든 광고 제거</div>
    <div class="p-benefit">모든 기능 잠금 해제</div>
  </div>

  <!-- 플랜 카드 -->
  <div class="p-plans">
    <div class="p-plan" id="plan-month" onclick="selectPlan('month')">
      <div class="p-plan-period">1개월</div>
      <div class="p-plan-price">₩2,900</div>
    </div>
    <div class="p-plan selected" id="plan-year" onclick="selectPlan('year')">
      <div class="p-plan-period">1년</div>
      <div class="p-plan-price">₩25,000</div>
    </div>
  </div>

  <!-- 설명 -->
  <div class="p-desc" id="plan-desc">
    3일의 무료 체험 기간이 제공됩니다.
    무료 체험 기간 중 구독의 취소가 가능합니다.
    그 후 구독을 취소할 때까지 매년 ₩25,000의 요금이
    자동으로 청구됩니다.
  </div>

  <!-- 이용약관 -->
  <div class="p-terms">
    <div class="p-term">(1) 환불은 어떠한 경우에도 불가능합니다.</div>
    <div class="p-term">(2) 프리미엄 기능은 임의로 추가 혹은 변경될 수 있습니다.</div>
    <div class="p-term">(3) 구독을 하지 않더라도 프리미엄 기능을 제외한 다른 기능들은 무료로 이용할 수 있습니다.</div>
    <div class="p-term">(4) 구독 기간이 끝나는 시점으로부터 최소 24시간 전까지 구독을 취소하지 않으면 구독이 자동으로 갱신됩니다.</div>
    <div class="p-term">(5) 구독 취소를 해도 잔여기간 동안에는 프리미엄 기능을 이용할 수 있습니다.</div>
    <div class="p-term">(6) 결제는 Google Play Store의 결제 시스템을 통해 진행이 되며 구독 정보는 사용자의 Google 계정에 귀속이 됩니다. 구독 취소는 앱의 설정 혹은 Google Play Store에서 하실 수 있습니다.</div>
  </div>

  <!-- 구매 내역 복원 -->
  <div class="p-restore">
    <a onclick="restorePurchase()">구매 내역 복원</a>
  </div>

</div><!-- /.p-body -->

<!-- ══ 하단 CTA ══ -->
<div class="p-cta">
  <button class="p-cta-btn" onclick="startTrial()">무료 체험 시작하기</button>
</div>

<script>
var currentPlan = 'year';

var DESCS = {
  month: '3일의 무료 체험 기간이 제공됩니다. 무료 체험 기간 중 구독의 취소가 가능합니다. 그 후 구독을 취소할 때까지 매월 ₩2,900의 요금이 자동으로 청구됩니다.',
  year:  '3일의 무료 체험 기간이 제공됩니다. 무료 체험 기간 중 구독의 취소가 가능합니다. 그 후 구독을 취소할 때까지 매년 ₩25,000의 요금이 자동으로 청구됩니다.'
};

function selectPlan(plan) {
  currentPlan = plan;
  document.getElementById('plan-month').classList.toggle('selected', plan === 'month');
  document.getElementById('plan-year').classList.toggle('selected', plan === 'year');
  document.getElementById('plan-desc').textContent = DESCS[plan];
}

function startTrial() {
  alert('준비 중인 기능입니다.');
}

function restorePurchase() {
  alert('준비 중인 기능입니다.');
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
