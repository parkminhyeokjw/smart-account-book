<?php
// ============================================================
// Google OAuth 2.0 Client ID 설정
// ============================================================
// Google Cloud Console (https://console.cloud.google.com/) 에서
// 1. 새 프로젝트 생성
// 2. Google Drive API 활성화
// 3. OAuth 동의 화면 설정
// 4. 사용자 인증 정보 → OAuth 클라이언트 ID 생성 (웹 애플리케이션)
//    - 승인된 JavaScript 원본: http://localhost (또는 서버 URL)
// 5. 발급된 클라이언트 ID를 아래에 입력
// ============================================================
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID_HERE');
