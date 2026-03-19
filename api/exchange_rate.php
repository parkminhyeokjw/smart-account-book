<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheDir  = __DIR__ . '/../cache';
$cacheFile = $cacheDir . '/exchange_rates.json';
$cacheTTL  = 3600; // 1시간 캐시

// 캐시가 신선하면 바로 반환
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    echo file_get_contents($cacheFile);
    exit;
}

// 외부 API 호출 (open.er-api.com — 무료, API 키 불필요)
$url  = 'https://open.er-api.com/v6/latest/KRW';
$data = null;

// cURL 우선
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'SmartAccountBook/1.0',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp) $data = $resp;
}

// fallback: file_get_contents
if (!$data && ini_get('allow_url_fopen')) {
    $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
    $data = @file_get_contents($url, false, $ctx);
}

if ($data) {
    $parsed = json_decode($data, true);
    if (!empty($parsed['result']) && $parsed['result'] === 'success') {
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
        file_put_contents($cacheFile, $data);
        echo $data;
        exit;
    }
}

// 오래된 캐시라도 반환, 없으면 에러
if (file_exists($cacheFile)) {
    echo file_get_contents($cacheFile);
} else {
    echo json_encode(['result' => 'error', 'rates' => new stdClass()]);
}
