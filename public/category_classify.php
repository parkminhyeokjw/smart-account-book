<?php
require_once __DIR__ . '/../config/auth.php';
$userId = requireLogin();

// 카테고리 없는 지출 내역 조회
$pdo = getConnection();
$stmt = $pdo->prepare(
    "SELECT t.id, t.amount, t.description, t.tx_date, t.payment_method
     FROM transactions t
     WHERE t.user_id = :uid AND t.category_id IS NULL
     ORDER BY t.tx_date DESC, t.id DESC"
);
$stmt->execute([':uid' => $userId]);
$unclassified = $stmt->fetchAll();

// 카테고리 목록 (분류 셀렉트용)
$catStmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id=:uid ORDER BY type, name");
$catStmt->execute([':uid' => $userId]);
$categories = $catStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>카테고리 일괄 분류 — 마이가계부</title>
<script src="design_apply.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #fff;
  color: #212121;
  min-height: 100vh;
  padding-bottom: 60px;
}

/* ── 헤더 ── */
.cc-header {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--theme-primary, #e75480);
  display: flex;
  align-items: center;
  padding: 0 4px;
  height: 56px;
}
.cc-back {
  color: #fff;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 24px;
  text-decoration: none;
  border-radius: 50%;
  flex-shrink: 0;
}
.cc-back:active { background: rgba(255,255,255,.15); }
.cc-title {
  flex: 1;
  color: #fff;
  font-size: 20px;
  font-weight: 500;
  padding-left: 8px;
}

/* ── 탭바 ── */
.cc-tab-bar {
  background: #fce4ec;
  border-bottom: 1px solid #f48fb1;
  padding: 0 16px;
  height: 40px;
  display: flex;
  align-items: center;
  font-size: 14px;
  font-weight: 600;
  color: #c2185b;
}

/* ── 빈 상태 ── */
.cc-empty {
  text-align: center;
  padding: 80px 20px;
  font-size: 15px;
  color: #c2185b;
}

/* ── 내역 목록 ── */
.cc-item {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid #f5f5f5;
  gap: 10px;
}
.cc-item-info { flex: 1; }
.cc-item-desc { font-size: 14px; font-weight: 600; color: #212121; }
.cc-item-sub  { font-size: 12px; color: #9e9e9e; margin-top: 2px; }
.cc-item-amt  { font-size: 14px; font-weight: 700; color: #e75480; white-space: nowrap; }
.cc-cat-select {
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 6px 8px;
  font-size: 13px;
  color: #424242;
  background: #fafafa;
  max-width: 120px;
}
.cc-save-btn {
  background: var(--theme-primary, #e75480);
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 7px 12px;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
}
.cc-save-btn:active { opacity: .8; }

/* ── 팁 모달 ── */
.tip-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 9000;
  align-items: center;
  justify-content: center;
}
.tip-overlay.show { display: flex; }
.tip-card {
  background: #fff;
  border-radius: 12px;
  width: 88%;
  max-width: 360px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
.tip-title {
  background: var(--theme-primary, #e75480);
  color: #fff;
  font-size: 17px;
  font-weight: 700;
  padding: 18px 20px;
}
.tip-body {
  padding: 24px 20px 16px;
  font-size: 15px;
  line-height: 1.75;
  color: #333;
}
.tip-body p + p { margin-top: 16px; }
.tip-no-show {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
  font-size: 14px;
  color: #555;
  cursor: pointer;
}
.tip-no-show input[type=checkbox] {
  width: 20px;
  height: 20px;
  accent-color: var(--theme-primary, #e75480);
  cursor: pointer;
  flex-shrink: 0;
}
.tip-confirm {
  display: block;
  width: 100%;
  padding: 18px 0;
  background: var(--theme-primary, #e75480);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  border: none;
  cursor: pointer;
}
.tip-confirm:active { opacity: .85; }
</style>
</head>
<body>

<!-- 헤더 -->
<div class="cc-header">
  <a class="cc-back" href="index.php">&#8592;</a>
  <div class="cc-title">카테고리 일괄 분류</div>
</div>

<!-- 탭바 -->
<div class="cc-tab-bar">지출내역</div>

<!-- 내역 목록 -->
<div id="ccList">
<?php if (empty($unclassified)): ?>
  <div class="cc-empty">카테고리가 분류되지 않은 내역이 없습니다</div>
<?php else: ?>
  <?php foreach ($unclassified as $tx): ?>
  <div class="cc-item" id="row-<?=(int)$tx['id']?>"
       data-amt="<?=(int)$tx['amount']?>"
       data-desc="<?=htmlspecialchars($tx['description']??'', ENT_QUOTES)?>"
       data-pm="<?=htmlspecialchars($tx['payment_method']??'현금', ENT_QUOTES)?>"
       data-date="<?=htmlspecialchars($tx['tx_date'])?>">
    <div class="cc-item-info">
      <div class="cc-item-desc"><?=htmlspecialchars($tx['description'] ?: '(내역 없음)')?></div>
      <div class="cc-item-sub"><?=$tx['tx_date']?> · <?=htmlspecialchars($tx['payment_method'])?></div>
    </div>
    <div class="cc-item-amt">₩<?=number_format((int)$tx['amount'])?></div>
    <select class="cc-cat-select" id="cat-<?=(int)$tx['id']?>">
      <option value="">선택</option>
      <?php foreach ($categories as $c): ?>
      <option value="<?=(int)$c['id']?>" data-type="<?=$c['type']?>">
        <?=htmlspecialchars($c['name'])?>
      </option>
      <?php endforeach; ?>
    </select>
    <button class="cc-save-btn" onclick="saveCategory(<?=(int)$tx['id']?>)">저장</button>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- 사용 팁 모달 -->
<div class="tip-overlay" id="tipModal">
  <div class="tip-card">
    <div class="tip-title">사용 팁</div>
    <div class="tip-body">
      <p>입력 된 내역 중 카테고리가 없는 내역만을 사용처별로 검색하여 일괄적으로 카테고리를 분류하는 기능입니다.</p>
      <p>카테고리를 한 번만 입력을 해 놓으시면 앞으로 실시간 자동입력 시 같은 내역은 따로 설정하지 않아도 자동으로 카테고리가 분류됩니다.</p>
    </div>
    <label class="tip-no-show">
      <input type="checkbox" id="tipNoShow"> 이 팁을 다시 보지 않기
    </label>
    <button class="tip-confirm" onclick="closeTip()">확인</button>
  </div>
</div>

<script>
// 페이지 진입 시 팁 표시 (localStorage로 다시 보지 않기 관리)
(function() {
  if (localStorage.getItem('ccTipHidden') !== '1') {
    document.getElementById('tipModal').classList.add('show');
  }
})();

function closeTip() {
  if (document.getElementById('tipNoShow').checked) {
    localStorage.setItem('ccTipHidden', '1');
  }
  document.getElementById('tipModal').classList.remove('show');
}

function saveCategory(txId) {
  const sel  = document.getElementById('cat-' + txId);
  const catId = sel.value;
  if (!catId) { alert('카테고리를 선택해 주세요.'); return; }

  const row  = document.getElementById('row-' + txId);
  const params = new URLSearchParams({
    id:             txId,
    category_id:    catId,
    amount:         row.dataset.amt,
    description:    row.dataset.desc,
    payment_method: row.dataset.pm,
    tx_date:        row.dataset.date,
  });

  fetch('update.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: params.toString()
  })
  .then(r => r.json())
  .then(d => {
    if (d.status === 'ok') {
      row.style.transition = 'opacity .3s';
      row.style.opacity = '0';
      setTimeout(() => {
        row.remove();
        if (!document.querySelector('.cc-item')) {
          document.getElementById('ccList').innerHTML =
            '<div class="cc-empty">카테고리가 분류되지 않은 내역이 없습니다</div>';
        }
      }, 300);
    } else {
      alert('저장 실패: ' + (d.message || '오류'));
    }
  })
  .catch(() => alert('서버 연결 오류'));
}
</script>
</body>
</html>
