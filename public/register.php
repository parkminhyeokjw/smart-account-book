<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']      ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (!$name || !$email || !$password || !$password2) {
        $error = '모든 항목을 입력해주세요.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '올바른 이메일 형식이 아닙니다.';
    } elseif ($password !== $password2) {
        $error = '비밀번호가 일치하지 않습니다.';
    } elseif (strlen($password) < 6) {
        $error = '비밀번호는 6자 이상이어야 합니다.';
    } else {
        try {
            $pdo    = getConnection();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (:n, :e, :p)");
            $stmt->execute([':n' => $name, ':e' => $email, ':p' => $hashed]);
            $userId = (int)$pdo->lastInsertId();

            // 신규 사용자 기본 카테고리 생성
            $defaults = [
                [$userId,'급여','income','wallet'],     [$userId,'용돈','income','gift'],
                [$userId,'기타수입','income','plus-circle'],
                [$userId,'식비','expense','restaurant'], [$userId,'교통','expense','car'],
                [$userId,'쇼핑','expense','cart'],       [$userId,'의료','expense','hospital'],
                [$userId,'문화','expense','music'],      [$userId,'통신','expense','phone'],
                [$userId,'주거','expense','home'],       [$userId,'기타','expense','etc'],
            ];
            $cs = $pdo->prepare("INSERT INTO categories (user_id, name, type, icon) VALUES (?,?,?,?)");
            foreach ($defaults as $r) $cs->execute($r);

            $_SESSION['user_id']    = $userId;
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $code = (string)$e->getCode();
            if ($code === '23000' || strpos($e->getMessage(), 'Duplicate') !== false) {
                $error = '이미 사용 중인 이메일입니다.';
            } else {
                $error = '회원가입 중 오류가 발생했습니다. (' . htmlspecialchars($e->getMessage()) . ')';
            }
            error_log('Register PDO error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>회원가입 — 마이가계부</title>
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
  padding: 32px 28px 28px;
}
.logo-icon {
  width: 64px; height: 64px;
  background: rgba(255,255,255,.15);
  border-radius: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.logo-icon .material-icons { font-size: 34px; color: #fff; }
.logo h1 { font-size: 22px; font-weight: 700; color: #fff; margin-top: 0; }
.logo p  { font-size: 13px; color: rgba(255,255,255,.65); margin-top: 5px; }

.card-body { padding: 24px 28px 32px; }

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
  background: #FFF0F0;
  color: #C62828;
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
  border-left: 3px solid #EF4444;
}
.btn-primary {
  width: 100%;
  background: linear-gradient(135deg, #1D2C55 0%, #2A3D6E 100%);
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 14px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  margin-top: 4px;
  box-shadow: 0 4px 12px rgba(29,44,85,.3);
}
.btn-primary:active { opacity: .88; }
.divider { text-align: center; color: #C8D0E0; font-size: 13px; margin: 20px 0; }
.btn-secondary {
  display: block;
  text-align: center;
  background: #EEF1FB;
  color: #1D2C55;
  border-radius: 10px;
  padding: 13px;
  font-size: 15px;
  font-weight: 600;
  text-decoration: none;
}
.btn-secondary:active { opacity: .8; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon"><span class="material-icons">account_balance_wallet</span></div>
    <h1>회원가입</h1>
    <p>나만의 가계부를 시작하세요</p>
  </div>

  <div class="card-body">
  <?php if ($error): ?>
  <div class="error"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post">
    <div class="field">
      <label>이름</label>
      <input type="text" name="name" placeholder="홍길동"
             value="<?=htmlspecialchars($_POST['name'] ?? '')?>" required>
    </div>
    <div class="field">
      <label>이메일</label>
      <input type="email" name="email" placeholder="example@email.com"
             value="<?=htmlspecialchars($_POST['email'] ?? '')?>" required>
    </div>
    <div class="field">
      <label>비밀번호 <span style="color:#9e9e9e;font-weight:400">(6자 이상)</span></label>
      <div class="pw-wrap">
        <input type="password" name="password" id="regPw" placeholder="비밀번호 입력" required>
        <button type="button" class="pw-eye" onclick="togglePw('regPw',this)" tabindex="-1">
          <span class="material-icons" style="font-size:20px">visibility_off</span>
        </button>
      </div>
    </div>
    <div class="field">
      <label>비밀번호 확인</label>
      <div class="pw-wrap">
        <input type="password" name="password2" id="regPw2" placeholder="비밀번호 재입력" required>
        <button type="button" class="pw-eye" onclick="togglePw('regPw2',this)" tabindex="-1">
          <span class="material-icons" style="font-size:20px">visibility_off</span>
        </button>
      </div>
    </div>
    <button type="submit" class="btn-primary">가입하기</button>
  </form>

  <div class="divider">이미 계정이 있으신가요?</div>
  <a href="login.php" class="btn-secondary">로그인</a>
  </div>
</div>
<script>
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  var icon = btn.querySelector('.material-icons');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.textContent = 'visibility';
  } else {
    inp.type = 'password';
    icon.textContent = 'visibility_off';
  }
}
</script>
</body>
</html>
