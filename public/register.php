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
            $error = ($e->getCode() == 23000)
                ? '이미 사용 중인 이메일입니다.'
                : '회원가입 중 오류가 발생했습니다.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>회원가입 — 똑똑가계부</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #f5f5f5;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
}
.card {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 2px 16px rgba(0,0,0,.1);
  padding: 40px 28px 32px;
  width: 100%;
  max-width: 360px;
}
.logo {
  text-align: center;
  margin-bottom: 32px;
}
.logo .material-icons { font-size: 48px; color: #455A64; }
.logo h1 { font-size: 22px; font-weight: 700; color: #212121; margin-top: 8px; }
.logo p  { font-size: 13px; color: #9e9e9e; margin-top: 4px; }

.field { margin-bottom: 16px; }
.field label { display: block; font-size: 13px; font-weight: 600; color: #424242; margin-bottom: 6px; }
.field input {
  width: 100%;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 12px 14px;
  font-size: 15px;
  outline: none;
  transition: border-color .2s;
}
.field input:focus { border-color: #455A64; }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 44px; }
.pw-eye {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; padding: 4px;
  color: #9e9e9e; display: flex; align-items: center;
}
.pw-eye:hover { color: #455A64; }

.error {
  background: #ffebee;
  color: #c62828;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
}
.btn-primary {
  width: 100%;
  background: #455A64;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 14px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  margin-top: 4px;
}
.btn-primary:active { opacity: .85; }
.divider { text-align: center; color: #bdbdbd; font-size: 13px; margin: 20px 0; }
.btn-secondary {
  display: block;
  text-align: center;
  background: #eceff1;
  color: #455A64;
  border-radius: 8px;
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
    <span class="material-icons">account_balance_wallet</span>
    <h1>회원가입</h1>
    <p>나만의 가계부를 시작하세요</p>
  </div>

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
