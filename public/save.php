<?php
// public/save.php — 거래 저장 엔드포인트 (public 루트에서 접근 가능)
require_once __DIR__ . '/../transaction/create.php';
// create.php 가 POST 요청을 직접 처리하므로 include만으로 동작
