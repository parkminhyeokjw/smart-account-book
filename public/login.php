<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = '이메일과 비밀번호를 입력해주세요.';
    } else {
        try {
            $pdo  = getConnection();
            $stmt = $pdo->prepare("SELECT id, name, email, password FROM users WHERE email = :e");
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch();

            if ($user && $user['password'] && password_verify($password, $user['password'])) {
                session_unset();
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                // remember-me 토큰 발급
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $rm_token = bin2hex(random_bytes(32));
                    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id=:uid")->execute([':uid'=>$user['id']]);
                    $pdo->prepare("INSERT INTO remember_tokens (user_id,token,expires_at) VALUES (:uid,:t,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([':uid'=>$user['id'],':t'=>$rm_token]);
                    setcookie('ddgb_rm', $rm_token, time()+30*86400, '/');
                } catch (Exception $e) {}
                header('Location: index.php');
                exit;
            } else {
                $error = '이메일 또는 비밀번호가 올바르지 않습니다.';
            }
        } catch (PDOException $e) {
            $error = 'DB 오류가 발생했습니다. 잠시 후 다시 시도해주세요.';
            error_log('Login PDO error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>로그인 — 마이가계부</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif;
  background: #F3F5FA;
  height: 100vh;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
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
  padding: 36px 28px 32px;
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

.card-body { padding: 20px 28px 24px; }

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
.btn-primary:active { opacity: .88; transform: scale(.99); }
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
.forgot-link {
  display: block;
  text-align: center;
  margin-top: 14px;
  font-size: 13px;
  color: #6B7A9E;
  text-decoration: none;
}
.forgot-link:hover { color: #1D2C55; text-decoration: underline; }
.guest-link {
  display: block;
  text-align: center;
  margin-top: 14px;
  font-size: 13px;
  color: #9e9e9e;
  text-decoration: none;
}
.guest-link:hover { color: #1D2C55; text-decoration: underline; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon"><span class="material-icons">account_balance_wallet</span></div>
    <h1>마이가계부</h1>
    <p>내 지출을 스마트하게 관리하세요</p>
  </div>

  <div class="card-body">
  <?php if ($error): ?>
  <div class="error"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <div class="field">
      <label>이메일</label>
      <input type="email" name="email" placeholder="example@email.com"
             value="<?=htmlspecialchars($_POST['email'] ?? '')?>" required autofocus>
    </div>
    <div class="field">
      <label>비밀번호</label>
      <div class="pw-wrap">
        <input type="password" name="password" id="loginPw" placeholder="비밀번호 입력" required>
        <button type="button" class="pw-eye" onclick="togglePw('loginPw',this)" tabindex="-1">
          <span class="material-icons" style="font-size:20px">visibility_off</span>
        </button>
      </div>
    </div>
    <button type="submit" class="btn-primary">로그인</button>
  </form>

  <a href="forgot_password.php" class="forgot-link">비밀번호를 잊으셨나요?</a>

  <div class="divider">또는</div>
  <a href="register.php" class="btn-secondary">회원가입</a>
  <a href="index.php" class="guest-link">로그인 없이 앱 사용하기</a>
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
