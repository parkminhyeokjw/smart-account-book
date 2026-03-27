<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$step    = 1;   // 1=이메일 입력, 2=이름 확인+새 비밀번호
$error   = '';
$success = '';
$foundId = null;

// ── STEP 2: 이름 + 새 비밀번호 제출 ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step2'])) {
    $userId   = (int)($_POST['uid']       ?? 0);
    $name     = trim($_POST['name']       ?? '');
    $pw       = $_POST['new_password']    ?? '';
    $pw2      = $_POST['new_password2']   ?? '';

    if (!$userId || !$name || !$pw || !$pw2) {
        $error = '모든 항목을 입력해주세요.';
        $step  = 2;
        $foundId = $userId;
    } elseif ($pw !== $pw2) {
        $error = '새 비밀번호가 일치하지 않습니다.';
        $step  = 2;
        $foundId = $userId;
    } elseif (strlen($pw) < 6) {
        $error = '비밀번호는 6자 이상이어야 합니다.';
        $step  = 2;
        $foundId = $userId;
    } else {
        try {
            $pdo  = getConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id=:id AND name=:name");
            $stmt->execute([':id' => $userId, ':name' => $name]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = '이름이 올바르지 않습니다.';
                $step  = 2;
                $foundId = $userId;
            } else {
                $hashed = password_hash($pw, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password=:p WHERE id=:id")
                    ->execute([':p' => $hashed, ':id' => $userId]);
                $success = '비밀번호가 성공적으로 변경됐어요! 새 비밀번호로 로그인해주세요.';
                $step = 0; // done
            }
        } catch (PDOException $e) {
            $error = 'DB 오류가 발생했습니다.';
            $step  = 2;
            $foundId = $userId;
        }
    }

// ── STEP 1: 이메일 제출 ─────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1'])) {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = '이메일을 입력해주세요.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '올바른 이메일 형식이 아닙니다.';
    } else {
        try {
            $pdo  = getConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email=:e");
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = '등록된 이메일이 아닙니다.';
            } else {
                $step    = 2;
                $foundId = $user['id'];
            }
        } catch (PDOException $e) {
            $error = 'DB 오류가 발생했습니다.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>비밀번호 찾기 — 똑똑가계부</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #F3F5FA;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
}
.card {
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 8px 32px rgba(29,44,85,.12);
  width: 100%;
  max-width: 360px;
  overflow: hidden;
}
.logo {
  background: linear-gradient(135deg, #1D2C55 0%, #2A3D6E 100%);
  text-align: center;
  padding: 28px 28px 24px;
}
.logo .material-icons { font-size: 40px; color: rgba(255,255,255,.9); }
.logo h1 { font-size: 20px; font-weight: 700; color: #fff; margin-top: 8px; }
.logo p  { font-size: 13px; color: rgba(255,255,255,.65); margin-top: 4px; line-height: 1.5; }

.card-body { padding: 24px 28px 32px; }

.steps { display: flex; align-items: center; justify-content: center; margin-bottom: 24px; gap: 8px; }
.step-dot {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700;
}
.step-dot.active { background: #1D2C55; color: #fff; }
.step-dot.done   { background: #2979FF; color: #fff; }
.step-dot.pending{ background: #EEF1FB; color: #6B7A9E; border: 1px solid #E4E8F2; }
.step-line { width: 32px; height: 2px; background: #E4E8F2; }
.step-line.done { background: #2979FF; }

.field { margin-bottom: 16px; }
.field label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.field input {
  width: 100%;
  border: 1.5px solid #E4E8F2;
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 15px;
  outline: none;
  transition: border-color .2s;
  background: #FAFBFD;
}
.field input:focus { border-color: #2979FF; background: #fff; }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 44px; }
.pw-eye {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; padding: 4px;
  color: #9e9e9e; display: flex; align-items: center;
}
.pw-eye:hover { color: #1D2C55; }

.error {
  background: #FFF0F0; color: #C62828;
  border-radius: 10px; padding: 10px 14px;
  font-size: 13px; margin-bottom: 16px;
  border-left: 3px solid #EF4444;
}
.success {
  background: #F0FDF4; color: #166534;
  border-radius: 10px; padding: 14px;
  font-size: 14px; text-align: center; margin-bottom: 20px;
  line-height: 1.6; border-left: 3px solid #22C55E;
}
.btn-primary {
  width: 100%;
  background: linear-gradient(135deg, #1D2C55 0%, #2A3D6E 100%); color: #fff;
  border: none; border-radius: 10px;
  padding: 14px; font-size: 16px; font-weight: 700;
  cursor: pointer; margin-top: 4px;
  box-shadow: 0 4px 12px rgba(29,44,85,.3);
}
.btn-primary:active { opacity: .88; }
.back-link {
  display: block; text-align: center;
  margin-top: 18px; font-size: 13px;
  color: #6B7A9E; text-decoration: none;
}
.back-link:hover { color: #1D2C55; text-decoration: underline; }
.hint {
  background: #EEF4FF; border-radius: 10px;
  padding: 10px 14px; font-size: 13px; color: #2979FF;
  margin-bottom: 16px; line-height: 1.5;
}
</style>
</head>
<body>
<div class="card">

  <!-- ── 완료 화면 ───────────────────────────────────────────── -->
  <?php if ($step === 0): ?>
  <div class="logo">
    <span class="material-icons">check_circle</span>
    <h1>비밀번호 변경 완료</h1>
  </div>
  <div class="card-body">
    <div class="success"><?=htmlspecialchars($success)?></div>
    <a href="login.php" class="btn-primary" style="display:block;text-align:center;text-decoration:none;line-height:1.4;padding:14px">로그인하러 가기</a>
  </div>

  <!-- ── STEP 1: 이메일 입력 ──────────────────────────────────── -->
  <?php elseif ($step === 1): ?>
  <div class="logo">
    <span class="material-icons">lock_reset</span>
    <h1>비밀번호 찾기</h1>
    <p>가입 시 사용한 이메일을 입력하세요</p>
  </div>

  <div class="card-body">
  <div class="steps">
    <div class="step-dot active">1</div>
    <div class="step-line"></div>
    <div class="step-dot pending">2</div>
  </div>

  <?php if ($error): ?>
  <div class="error"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="step1" value="1">
    <div class="field">
      <label>가입한 이메일</label>
      <input type="email" name="email" placeholder="example@email.com"
             value="<?=htmlspecialchars($_POST['email'] ?? '')?>" required autofocus>
    </div>
    <button type="submit" class="btn-primary">다음</button>
  </form>
  <a href="login.php" class="back-link">← 로그인으로 돌아가기</a>
  </div>

  <!-- ── STEP 2: 이름 확인 + 새 비밀번호 ──────────────────────── -->
  <?php elseif ($step === 2): ?>
  <div class="logo">
    <span class="material-icons">lock_reset</span>
    <h1>비밀번호 재설정</h1>
    <p>본인 확인 후 새 비밀번호를 입력하세요</p>
  </div>

  <div class="card-body">
  <div class="steps">
    <div class="step-dot done">1</div>
    <div class="step-line done"></div>
    <div class="step-dot active">2</div>
  </div>

  <?php if ($error): ?>
  <div class="error"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <div class="hint">가입 시 입력한 <b>이름</b>을 입력하면 비밀번호를 재설정할 수 있어요.</div>

  <form method="post">
    <input type="hidden" name="step2" value="1">
    <input type="hidden" name="uid" value="<?=(int)$foundId?>">
    <div class="field">
      <label>이름 (본인 확인)</label>
      <input type="text" name="name" placeholder="가입 시 이름"
             value="<?=htmlspecialchars($_POST['name'] ?? '')?>" required autofocus>
    </div>
    <div class="field">
      <label>새 비밀번호 <span style="color:#9e9e9e;font-weight:400">(6자 이상)</span></label>
      <div class="pw-wrap">
        <input type="password" name="new_password" id="pw1" placeholder="새 비밀번호 입력" required>
        <button type="button" class="pw-eye" onclick="togglePw('pw1',this)" tabindex="-1">
          <span class="material-icons" style="font-size:20px">visibility_off</span>
        </button>
      </div>
    </div>
    <div class="field">
      <label>새 비밀번호 확인</label>
      <div class="pw-wrap">
        <input type="password" name="new_password2" id="pw2" placeholder="새 비밀번호 재입력" required>
        <button type="button" class="pw-eye" onclick="togglePw('pw2',this)" tabindex="-1">
          <span class="material-icons" style="font-size:20px">visibility_off</span>
        </button>
      </div>
    </div>
    <button type="submit" class="btn-primary">비밀번호 변경</button>
  </form>
  <a href="login.php" class="back-link">← 로그인으로 돌아가기</a>
  </div>

  <?php endif; ?>
</div>
<script>
function togglePw(id, btn) {
  var inp  = document.getElementById(id);
  var icon = btn.querySelector('.material-icons');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.textContent = inp.type === 'password' ? 'visibility_off' : 'visibility';
}
</script>
</body>
</html>
