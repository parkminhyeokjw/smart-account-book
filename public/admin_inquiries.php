<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── 관리자 인증 ────────────────────────────────────────────────
// admin 비밀번호: 세션에 is_admin=true 가 없으면 비밀번호 확인
define('ADMIN_PASS', '1122');   // ← 원하는 비밀번호로 변경

if (isset($_POST['admin_pass'])) {
    if ($_POST['admin_pass'] === ADMIN_PASS) {
        $_SESSION['is_admin'] = true;
    } else {
        $loginError = '비밀번호가 틀렸습니다.';
    }
}
if (isset($_GET['logout_admin'])) {
    unset($_SESSION['is_admin']);
    header('Location: admin_inquiries.php');
    exit;
}

$isAdmin = !empty($_SESSION['is_admin']);

// ── 로그인 안 됐으면 비밀번호 폼 표시 ─────────────────────────
if (!$isAdmin): ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>관리자 로그인</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system,'Malgun Gothic',sans-serif; background:#F5F5F5;
       display:flex; align-items:center; justify-content:center; min-height:100vh; }
.card { background:#fff; border-radius:16px; padding:40px 32px; width:320px;
        box-shadow:0 4px 20px rgba(0,0,0,.1); }
h2 { font-size:22px; font-weight:700; margin-bottom:24px; text-align:center; color:#1E293B; }
input[type=password] { width:100%; padding:12px 16px; border:1px solid #E2E8F0;
  border-radius:8px; font-size:16px; outline:none; margin-bottom:12px; }
input[type=password]:focus { border-color:#2563EB; }
button { width:100%; padding:13px; background:#1E293B; color:#fff; border:none;
         border-radius:8px; font-size:16px; font-weight:600; cursor:pointer; }
button:hover { background:#0F172A; }
.err { color:#E11D48; font-size:14px; margin-top:8px; text-align:center; }
</style>
</head>
<body>
<div class="card">
  <h2>관리자 로그인</h2>
  <form method="post">
    <input type="password" name="admin_pass" placeholder="관리자 비밀번호" autofocus>
    <button type="submit">확인</button>
  </form>
  <?php if (!empty($loginError)): ?>
    <p class="err"><?= htmlspecialchars($loginError) ?></p>
  <?php endif; ?>
</div>
</body>
</html>
<?php
exit;
endif;

// ── 실제 데이터 로드 ────────────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
$pdo = getConnection();

// 테이블 없으면 생성
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inquiries (
        id         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED   DEFAULT NULL,
        user_name  VARCHAR(100)   NOT NULL DEFAULT '비회원',
        user_email VARCHAR(255)   NOT NULL DEFAULT '',
        subject    VARCHAR(200)   NOT NULL,
        body       TEXT           NOT NULL,
        is_read    TINYINT(1)     NOT NULL DEFAULT 0,
        created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_inq_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// 읽음 처리
if (!empty($_GET['mark_read'])) {
    $rid = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE inquiries SET is_read=1 WHERE id=:id")->execute([':id' => $rid]);
    header('Location: admin_inquiries.php');
    exit;
}

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$filter = $_GET['filter'] ?? 'all';

$where = $filter === 'unread' ? 'WHERE is_read=0' : '';
$total = (int)$pdo->query("SELECT COUNT(*) FROM inquiries $where")->fetchColumn();
$rows  = $pdo->query("SELECT * FROM inquiries $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset")->fetchAll();
$unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read=0")->fetchColumn();
$totalPages  = max(1, (int)ceil($total / $limit));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>문의 관리 — 마이가계부</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system,'Malgun Gothic','맑은 고딕',sans-serif;
       background:#F8FAFC; color:#1E293B; min-height:100vh; }

/* 헤더 */
.header {
  background:#1E293B; color:#fff;
  padding:16px 20px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:100;
}
.header h1 { font-size:20px; font-weight:700; }
.header-right { display:flex; align-items:center; gap:12px; }
.badge-unread {
  background:#E11D48; color:#fff;
  font-size:13px; font-weight:700;
  padding:3px 10px; border-radius:99px;
}
.logout-btn {
  font-size:14px; color:#94A3B8; text-decoration:none;
  padding:6px 12px; border:1px solid #475569; border-radius:6px;
}
.logout-btn:hover { color:#fff; border-color:#fff; }
.notif-btn {
  font-size:13px; font-weight:600;
  padding:6px 14px; border-radius:6px; border:none; cursor:pointer;
  background:#2563EB; color:#fff;
}
.notif-btn.on  { background:#22C55E; }
.notif-btn.off { background:#64748B; }

/* 필터 탭 */
.filter-bar {
  padding:12px 20px; background:#fff;
  border-bottom:1px solid #E2E8F0;
  display:flex; gap:8px;
}
.filter-btn {
  padding:7px 16px; border-radius:99px; font-size:14px; font-weight:600;
  border:none; cursor:pointer; text-decoration:none; display:inline-block;
}
.filter-btn.active { background:#1E293B; color:#fff; }
.filter-btn:not(.active) { background:#F1F5F9; color:#64748B; }

/* 테이블 컨테이너 */
.container { padding:20px; }
.summary { font-size:14px; color:#64748B; margin-bottom:14px; }

/* 카드 목록 */
.inq-card {
  background:#fff; border-radius:12px;
  border:1px solid #E2E8F0;
  margin-bottom:12px;
  overflow:hidden;
  transition:box-shadow .15s;
}
.inq-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
.inq-card.unread { border-left:4px solid #2563EB; }
.inq-head {
  padding:16px 18px 12px;
  cursor:pointer;
  display:flex; align-items:flex-start; gap:12px;
}
.inq-unread-dot {
  width:8px; height:8px; border-radius:50%; background:#2563EB;
  flex-shrink:0; margin-top:6px;
}
.inq-read-dot {
  width:8px; height:8px; border-radius:50%; background:transparent;
  flex-shrink:0; margin-top:6px;
}
.inq-main { flex:1; min-width:0; }
.inq-subject { font-size:16px; font-weight:600; color:#1E293B; margin-bottom:4px; }
.inq-meta { font-size:13px; color:#94A3B8; display:flex; gap:10px; flex-wrap:wrap; }
.inq-body {
  display:none;
  padding:0 18px 16px 38px;
  font-size:15px; color:#334155; line-height:1.7;
  border-top:1px solid #F1F5F9;
  white-space:pre-wrap; word-break:break-word;
}
.inq-actions {
  display:none;
  padding:10px 18px 14px 38px;
  gap:8px;
}
.inq-mark-btn {
  font-size:13px; font-weight:600;
  padding:7px 16px; border-radius:8px; border:none; cursor:pointer;
  background:#EEF2FF; color:#2563EB;
  text-decoration:none; display:inline-block;
}
.inq-mark-btn:hover { background:#DBEAFE; }

/* 페이지네이션 */
.pagination {
  display:flex; justify-content:center; gap:6px;
  margin-top:24px;
}
.pg-btn {
  padding:8px 14px; border-radius:8px; font-size:14px;
  border:1px solid #E2E8F0; background:#fff; color:#1E293B;
  cursor:pointer; text-decoration:none; display:inline-block;
}
.pg-btn.active { background:#1E293B; color:#fff; border-color:#1E293B; }
.pg-btn:hover:not(.active) { background:#F1F5F9; }

/* 빈 상태 */
.empty {
  text-align:center; padding:60px 20px;
  color:#94A3B8; font-size:16px;
}
.empty-icon { font-size:48px; margin-bottom:12px; }
</style>
</head>
<body>

<header class="header">
  <h1>문의 관리</h1>
  <div class="header-right">
    <?php if ($unreadCount > 0): ?>
      <span class="badge-unread">미읽음 <?= $unreadCount ?>건</span>
    <?php endif; ?>
    <button class="notif-btn" id="notif-btn" onclick="toggleNotif()">🔔 알림받기</button>
    <a href="?logout_admin=1" class="logout-btn">로그아웃</a>
  </div>
</header>

<div class="filter-bar">
  <a href="?filter=all&page=1"
     class="filter-btn <?= $filter==='all'?'active':'' ?>">전체</a>
  <a href="?filter=unread&page=1"
     class="filter-btn <?= $filter==='unread'?'active':'' ?>">
    미읽음<?= $unreadCount>0?" ({$unreadCount})":''; ?>
  </a>
</div>

<div class="container">
  <p class="summary">총 <?= $total ?>건 · <?= $page ?>/<?= $totalPages ?> 페이지</p>

  <?php if (empty($rows)): ?>
    <div class="empty">
      <div class="empty-icon">📭</div>
      <p>아직 접수된 문의가 없습니다.</p>
    </div>
  <?php else: ?>

    <?php foreach ($rows as $inq): ?>
      <?php $unread = !$inq['is_read']; ?>
      <div class="inq-card <?= $unread ? 'unread' : '' ?>" id="card-<?= $inq['id'] ?>">
        <div class="inq-head" onclick="toggleCard(<?= $inq['id'] ?>)">
          <div class="<?= $unread ? 'inq-unread-dot' : 'inq-read-dot' ?>"
               id="dot-<?= $inq['id'] ?>"></div>
          <div class="inq-main">
            <div class="inq-subject"><?= htmlspecialchars($inq['subject']) ?></div>
            <div class="inq-meta">
              <span><?= htmlspecialchars($inq['user_name']) ?></span>
              <?php if ($inq['user_email']): ?>
                <span><?= htmlspecialchars($inq['user_email']) ?></span>
              <?php endif; ?>
              <span><?= date('Y.m.d H:i', strtotime($inq['created_at'])) ?></span>
              <?php if (!$unread): ?><span style="color:#22C55E">✓ 읽음</span><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="inq-body" id="body-<?= $inq['id'] ?>"><?= htmlspecialchars($inq['body']) ?></div>
        <div class="inq-actions" id="act-<?= $inq['id'] ?>">
          <?php if ($unread): ?>
            <a href="?mark_read=<?= $inq['id'] ?>&filter=<?= $filter ?>&page=<?= $page ?>"
               class="inq-mark-btn">읽음으로 표시</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- 페이지네이션 -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?filter=<?= $filter ?>&page=<?= $i ?>"
           class="pg-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<script>
var openCard = null;

function toggleCard(id) {
  var body = document.getElementById('body-' + id);
  var act  = document.getElementById('act-'  + id);
  var isOpen = body.style.display === 'block';

  // 이미 열린 카드 닫기
  if (openCard && openCard !== id) {
    var ob = document.getElementById('body-' + openCard);
    var oa = document.getElementById('act-'  + openCard);
    if (ob) ob.style.display = 'none';
    if (oa) oa.style.display = 'none';
  }

  if (isOpen) {
    body.style.display = 'none';
    act.style.display  = 'none';
    openCard = null;
  } else {
    body.style.display = 'block';
    act.style.display  = 'flex';
    openCard = id;

    // AJAX 읽음 처리 (새로고침 없이)
    var dot  = document.getElementById('dot-'  + id);
    var card = document.getElementById('card-' + id);
    if (dot && dot.classList.contains('inq-unread-dot')) {
      fetch('../api/inquiry.php', {
        method: 'PATCH',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: id})
      }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) {
          dot.className  = 'inq-read-dot';
          card.classList.remove('unread');
          // 헤더 미읽음 배지 갱신
          updateUnreadBadge();
        }
      });
    }
  }
}

function updateUnreadBadge() {
  var dots  = document.querySelectorAll('.inq-unread-dot');
  var badge = document.querySelector('.badge-unread');
  var cnt   = dots.length;
  if (badge) {
    if (cnt > 0) badge.textContent = '미읽음 ' + cnt + '건';
    else badge.style.display = 'none';
  }
  var unreading = document.querySelector('a[href*="filter=unread"]');
  if (unreading) {
    unreading.textContent = cnt > 0 ? '미읽음 (' + cnt + ')' : '미읽음';
  }
}

// ── 푸시 알림 구독 ────────────────────────────────────────────
var VAPID_PUB = 'BMv50tM5lqdQRjnxCsMLgi9BWuyz49HXBk2x3jOOKTdfbH1bm0kdbAdoKg43iHWkJmffzQtKA01v3Lyp4wc1cOc';

function urlBase64ToUint8(b64) {
  var pad = '='.repeat((4 - b64.length % 4) % 4);
  var raw = atob((b64 + pad).replace(/-/g,'+').replace(/_/g,'/'));
  return Uint8Array.from(raw, function(c){ return c.charCodeAt(0); });
}

function updateNotifBtn(subscribed) {
  var btn = document.getElementById('notif-btn');
  if (!btn) return;
  if (subscribed) {
    btn.textContent = '🔔 알림 ON';
    btn.className   = 'notif-btn on';
  } else {
    btn.textContent = '🔔 알림받기';
    btn.className   = 'notif-btn';
  }
}

// 페이지 로드 시 현재 구독 상태 확인
if ('serviceWorker' in navigator && 'PushManager' in window) {
  navigator.serviceWorker.ready.then(function(reg) {
    reg.pushManager.getSubscription().then(function(sub) {
      updateNotifBtn(!!sub);
    });
  });
} else {
  document.getElementById('notif-btn').style.display = 'none';
}

function toggleNotif() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    alert('이 브라우저는 푸시 알림을 지원하지 않습니다.');
    return;
  }
  navigator.serviceWorker.ready.then(function(reg) {
    reg.pushManager.getSubscription().then(function(sub) {
      if (sub) {
        // 구독 해제
        sub.unsubscribe().then(function() {
          fetch('../api/inquiry.php', {
            method: 'DELETE',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({endpoint: sub.endpoint})
          });
          updateNotifBtn(false);
          alert('알림이 해제됐습니다.');
        });
      } else {
        // 구독 요청
        Notification.requestPermission().then(function(perm) {
          if (perm !== 'granted') { alert('알림 권한을 허용해주세요.'); return; }
          reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8(VAPID_PUB)
          }).then(function(newSub) {
            var j = newSub.toJSON();
            return fetch('../api/inquiry.php', {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({
                admin_push_subscribe: true,
                endpoint: j.endpoint,
                p256dh:   j.keys.p256dh,
                auth:     j.keys.auth
              })
            });
          }).then(function(r){ return r.json(); }).then(function(d) {
            if (d.ok) {
              updateNotifBtn(true);
              alert('알림 설정 완료! 문의가 오면 바로 알려드립니다 🔔');
            }
          }).catch(function(e) {
            alert('알림 설정 실패: ' + e.message);
          });
        });
      }
    });
  });
}
</script>
</body>
</html>
