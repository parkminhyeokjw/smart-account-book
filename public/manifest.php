<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$dark = isset($_COOKIE['ddgb_dark']) && $_COOKIE['ddgb_dark'] === '1';
$theme = $dark ? '#0F172A' : '#1D2C55';
$bg    = $dark ? '#0F172A' : '#1D2C55';
echo json_encode([
  'name'             => '마이가계부',
  'short_name'       => '마이가계부',
  'description'      => '스마트 가계부 앱',
  'start_url'        => './index.php',
  'display'          => 'standalone',
  'orientation'      => 'portrait',
  'background_color' => $bg,
  'theme_color'      => $theme,
  'icons'            => [
    ['src'=>'icon-72.png',  'sizes'=>'72x72',   'type'=>'image/png'],
    ['src'=>'icon-96.png',  'sizes'=>'96x96',   'type'=>'image/png'],
    ['src'=>'icon-128.png', 'sizes'=>'128x128', 'type'=>'image/png'],
    ['src'=>'icon-144.png', 'sizes'=>'144x128', 'type'=>'image/png'],
    ['src'=>'icon-152.png', 'sizes'=>'152x152', 'type'=>'image/png'],
    ['src'=>'icon-192.png', 'sizes'=>'192x192', 'type'=>'image/png', 'purpose'=>'any maskable'],
    ['src'=>'icon-384.png', 'sizes'=>'384x384', 'type'=>'image/png'],
    ['src'=>'icon-512.png', 'sizes'=>'512x512', 'type'=>'image/png', 'purpose'=>'any maskable'],
    ['src'=>'icon.svg',     'sizes'=>'any',     'type'=>'image/svg+xml'],
  ],
], JSON_UNESCAPED_UNICODE);
