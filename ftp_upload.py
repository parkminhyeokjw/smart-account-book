import ftplib
import os
import time

HOST = 'ftp.epizy.com'
USER = 'if0_41427872'
PASS = 'park20061226'
REMOTE_BASE = 'htdocs/public'
LOCAL_BASE = 'C:/xampp/htdocs/smart-account-book/public'

FILES = [
    'manifest.json',
    'manifest.php',
    'icon.svg',
    'icon-72.png',
    'icon-96.png',
    'icon-128.png',
    'icon-144.png',
    'icon-152.png',
    'icon-192.png',
    'icon-384.png',
    'icon-512.png',
    'favicon.png',
    'index.php',
    'login.php',
    'register.php',
    'forgot_password.php',
    'settings.php',
    'help.php',
    'premium.php',
    'currency.php',
    'design_settings.php',
    'admin_inquiries.php',
    'category_classify.php',
]

def upload_file(ftp, local_path, remote_path):
    with open(local_path, 'rb') as f:
        ftp.storbinary(f'STOR {remote_path}', f)

def connect():
    ftp = ftplib.FTP()
    ftp.connect(HOST, 21, timeout=30)
    ftp.login(USER, PASS)
    ftp.set_pasv(True)
    return ftp

failed = []
for fname in FILES:
    local = os.path.join(LOCAL_BASE, fname)
    remote = f'{REMOTE_BASE}/{fname}'
    for attempt in range(3):
        try:
            ftp = connect()
            print(f'Uploading {fname}...', end=' ')
            upload_file(ftp, local, remote)
            ftp.quit()
            print('OK')
            break
        except Exception as e:
            print(f'RETRY ({attempt+1}/3): {e}')
            time.sleep(3)
    else:
        print(f'FAILED: {fname}')
        failed.append(fname)

# API 파일 별도 업로드
API_FILES = [
    ('C:/xampp/htdocs/smart-account-book/api/index.php', 'htdocs/api/index.php'),
]
for local, remote in API_FILES:
    for attempt in range(3):
        try:
            ftp = connect()
            print(f'Uploading api/index.php...', end=' ')
            upload_file(ftp, local, remote)
            ftp.quit()
            print('OK')
            break
        except Exception as e:
            print(f'RETRY ({attempt+1}/3): {e}')
            time.sleep(3)
    else:
        failed.append('api/index.php')

if failed:
    print('\n실패한 파일:', failed)
else:
    print('\n모든 파일 업로드 완료!')
