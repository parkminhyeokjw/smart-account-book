"""
InfinityFree FTP 자동 배포 스크립트
사용법: python deploy.py
"""
import ftplib
import os

FTP_HOST = 'ftp.epizy.com'
FTP_USER = 'if0_41427872'
FTP_PASS = 'park20061226'

FILES = [
    ('public/index.php',    '/htdocs/public/index.php'),
    ('api/index.php',       '/htdocs/api/index.php'),
    ('public/sw.js',        '/htdocs/public/sw.js'),
    ('public/design_apply.js', '/htdocs/public/design_apply.js'),
]

def deploy():
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, 21, timeout=30)
    ftp.login(FTP_USER, FTP_PASS)
    print('FTP 연결 완료')

    for local, remote in FILES:
        if not os.path.exists(local):
            continue
        remote_dir = remote.rsplit('/', 1)[0]
        ftp.cwd(remote_dir)
        with open(local, 'rb') as f:
            ftp.storbinary(f'STOR {remote.rsplit("/",1)[1]}', f)
        print(f'  업로드: {local}')

    ftp.quit()
    print('배포 완료!')

deploy()
