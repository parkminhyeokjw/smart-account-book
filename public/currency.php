<?php
/* ══════════════════════════════════════════════
   통화와 환율
   - 기본 통화 선택 (검색 팝업)
   - 표시 형식 설정 팝업
   - 환율 설정 목록 (실시간 API)
══════════════════════════════════════════════ */

// 전체 통화 목록 (코드 => [한국어 이름, 기호])
$allCurrencies = [
  'KRW' => ['대한민국 원',           '₩'],
  'USD' => ['미국 달러',             '$'],
  'EUR' => ['유럽 유로',             '€'],
  'JPY' => ['일본 엔',               '¥'],
  'GBP' => ['영국 파운드',           '£'],
  'CNY' => ['중국 위안',             '¥'],
  'HKD' => ['홍콩 달러',             'HK$'],
  'TWD' => ['대만 달러',             'NT$'],
  'SGD' => ['싱가포르 달러',         'S$'],
  'AUD' => ['호주 달러',             'A$'],
  'CAD' => ['캐나다 달러',           'CA$'],
  'CHF' => ['스위스 프랑',           'Fr'],
  'SEK' => ['스웨덴 크로나',         'kr'],
  'NOK' => ['노르웨이 크로네',       'kr'],
  'DKK' => ['덴마크 크로네',         'kr'],
  'NZD' => ['뉴질랜드 달러',         'NZ$'],
  'THB' => ['태국 바트',             '฿'],
  'MYR' => ['말레이시아 링깃',       'RM'],
  'IDR' => ['인도네시아 루피아',     'Rp'],
  'PHP' => ['필리핀 페소',           '₱'],
  'VND' => ['베트남 동',             '₫'],
  'INR' => ['인도 루피',             '₹'],
  'PKR' => ['파키스탄 루피',         '₨'],
  'BDT' => ['방글라데시 타카',       '৳'],
  'LKR' => ['스리랑카 루피',         '₨'],
  'NPR' => ['네팔 루피',             '₨'],
  'MMK' => ['미얀마 짯',             'K'],
  'KHR' => ['캄보디아 리엘',         '៛'],
  'LAK' => ['라오스 킵',             '₭'],
  'MNT' => ['몽골 투그릭',           '₮'],
  'BND' => ['브루나이 달러',         'B$'],
  'MOP' => ['마카오 파타카',         'P'],
  'MVR' => ['몰디브 루피아',         'Rf'],
  'MXN' => ['멕시코 페소',           'MX$'],
  'BRL' => ['브라질 헤알',           'R$'],
  'ARS' => ['아르헨티나 페소',       '$'],
  'CLP' => ['칠레 페소',             '$'],
  'COP' => ['콜롬비아 페소',         '$'],
  'PEN' => ['페루 솔',               'S/'],
  'UYU' => ['우루과이 페소',         '$U'],
  'BOB' => ['볼리비아 볼리비아노',   'Bs'],
  'PYG' => ['파라과이 과라니',       '₲'],
  'VES' => ['베네수엘라 볼리바르',   'Bs.S'],
  'GYD' => ['가이아나 달러',         'G$'],
  'TTD' => ['트리니다드 달러',       'TT$'],
  'JMD' => ['자메이카 달러',         'J$'],
  'DOP' => ['도미니카 페소',         'RD$'],
  'HTG' => ['아이티 구르드',         'G'],
  'GTQ' => ['과테말라 케트살',       'Q'],
  'HNL' => ['온두라스 렘피라',       'L'],
  'NIO' => ['니카라과 코르도바',     'C$'],
  'CRC' => ['코스타리카 콜론',       '₡'],
  'PAB' => ['파나마 발보아',         'B/.'],
  'BSD' => ['바하마 달러',           'B$'],
  'BBD' => ['바베이도스 달러',       'Bds$'],
  'XCD' => ['동카리브 달러',         'EC$'],
  'CUP' => ['쿠바 페소',             '$'],
  'RUB' => ['러시아 루블',           '₽'],
  'UAH' => ['우크라이나 흐리브냐',   '₴'],
  'PLN' => ['폴란드 즐로티',         'zł'],
  'CZK' => ['체코 코루나',           'Kč'],
  'HUF' => ['헝가리 포린트',         'Ft'],
  'RON' => ['루마니아 레우',         'lei'],
  'BGN' => ['불가리아 레프',         'лв'],
  'ISK' => ['아이슬란드 크로나',     'kr'],
  'HRK' => ['크로아티아 쿠나',       'kn'],
  'RSD' => ['세르비아 디나르',       'din'],
  'ALL' => ['알바니아 렉',           'L'],
  'MKD' => ['북마케도니아 데나르',   'ден'],
  'BAM' => ['보스니아 마르크',       'KM'],
  'MDL' => ['몰도바 레이',           'L'],
  'BYN' => ['벨라루스 루블',         'Br'],
  'KZT' => ['카자흐스탄 텡게',       '₸'],
  'UZS' => ['우즈베키스탄 솜',       'сум'],
  'AZN' => ['아제르바이잔 마나트',   '₼'],
  'GEL' => ['조지아 라리',           '₾'],
  'AMD' => ['아르메니아 드람',       '֏'],
  'TJS' => ['타지키스탄 소모니',     'SM'],
  'TMT' => ['투르크메니스탄 마나트', 'T'],
  'KGS' => ['키르기스스탄 솜',       'с'],
  'TRY' => ['튀르키예 리라',         '₺'],
  'SAR' => ['사우디 리얄',           'SR'],
  'AED' => ['아랍에미리트 디르함',   'AED'],
  'KWD' => ['쿠웨이트 디나르',       'KD'],
  'BHD' => ['바레인 디나르',         'BD'],
  'OMR' => ['오만 리얄',             'OMR'],
  'QAR' => ['카타르 리얄',           'QR'],
  'JOD' => ['요르단 디나르',         'JD'],
  'ILS' => ['이스라엘 세켈',         '₪'],
  'LBP' => ['레바논 파운드',         'L£'],
  'IRR' => ['이란 리얄',             '﷼'],
  'IQD' => ['이라크 디나르',         'IQD'],
  'AFN' => ['아프가니스탄 아프가니', '؋'],
  'ZAR' => ['남아프리카 랜드',       'R'],
  'NGN' => ['나이지리아 나이라',     '₦'],
  'KES' => ['케냐 실링',             'KSh'],
  'GHS' => ['가나 세디',             'GH₵'],
  'ETB' => ['에티오피아 비르',       'Br'],
  'TZS' => ['탄자니아 실링',         'TSh'],
  'UGX' => ['우간다 실링',           'USh'],
  'RWF' => ['르완다 프랑',           'FRw'],
  'MAD' => ['모로코 디르함',         'MAD'],
  'DZD' => ['알제리 디나르',         'DZD'],
  'TND' => ['튀니지 디나르',         'TND'],
  'EGP' => ['이집트 파운드',         'E£'],
  'SDG' => ['수단 파운드',           'SDG'],
  'GNF' => ['기니 프랑',             'FG'],
  'GMD' => ['감비아 달라시',         'D'],
  'SLL' => ['시에라리온 레온',       'Le'],
  'LRD' => ['라이베리아 달러',       'L$'],
  'NAD' => ['나미비아 달러',         'N$'],
  'BWP' => ['보츠와나 풀라',         'P'],
  'ZMW' => ['잠비아 콰차',           'ZK'],
  'MZN' => ['모잠비크 메티칼',       'MT'],
  'AOA' => ['앙골라 콴자',           'Kz'],
  'CDF' => ['콩고 프랑',             'FC'],
  'MGA' => ['마다가스카르 아리아리', 'Ar'],
  'MUR' => ['모리셔스 루피',         '₨'],
  'XOF' => ['서아프리카 CFA 프랑',   'CFA'],
  'XAF' => ['중앙아프리카 CFA 프랑', 'CFA'],
  'FJD' => ['피지 달러',             'FJ$'],
  'PGK' => ['파푸아뉴기니 키나',     'K'],
  'SBD' => ['솔로몬 달러',           'SI$'],
  'TOP' => ['통가 파앙아',           'T$'],
  'WST' => ['사모아 탈라',           'WS$'],
  'VUV' => ['바누아투 바투',         'VT'],
  'XPF' => ['태평양 프랑',           'CFP'],
];

// 한국어 이름 기준 정렬
uasort($allCurrencies, fn($a, $b) => strcmp($a[0], $b[0]));

// 환율 데이터 가져오기 (캐시 포함)
$rates    = [];
$updated  = '';
$apiError = false;

$cacheFile = __DIR__ . '/../cache/exchange_rates.json';
$data      = null;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
    $data = json_decode(file_get_contents($cacheFile), true);
} else {
    $url = 'https://open.er-api.com/v6/latest/KRW';
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if (!$raw && ini_get('allow_url_fopen')) {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
    }
    if ($raw) {
        $parsed = json_decode($raw, true);
        if (!empty($parsed['result']) && $parsed['result'] === 'success') {
            if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0755, true);
            file_put_contents($cacheFile, $raw);
            $data = $parsed;
        }
    }
    if (!$data && file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $apiError = true;
    }
}

if (!empty($data['rates'])) {
    $rates   = $data['rates'];
    $updated = $data['time_last_update_utc'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>통화와 환율 — 마이가계부</title>
<script src="design_apply.js"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
/* CSS 변수 fallback */
html[data-fontsize="크게"] body { zoom: 1.1; }
html[data-fontsize="아주 크게"] body { zoom: 1.2; }
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body { font-family: -apple-system, 'Malgun Gothic', '맑은 고딕', sans-serif; background: #fff; color: #212121; min-height: 100vh; padding-bottom: 60px; }

/* ── 헤더 ── */
.s-header { position: sticky; top: 0; z-index: 100; background: var(--theme-primary, #1D2C55); display: flex; align-items: center; padding: 0 4px; height: 56px; }
.s-icon { color: #fff; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 50%; flex-shrink: 0; }
.s-icon:active { background: rgba(255,255,255,.15); }
.s-icon .material-icons { font-size: 24px; }
.s-title { flex: 1; color: #fff; font-size: 20px; font-weight: 500; padding-left: 8px; }

/* ── 리스트 ── */
.s-section { background: #EEEEEE; padding: 10px 16px 9px; font-size: 13px; font-weight: 700; color: #424242; }
.s-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 13px; border-bottom: 1px solid #F0F0F0; cursor: pointer; background: #fff; }
.s-item:active { background: #F5F5F5; }
.s-item-title { font-size: 18px; font-weight: 700; color: #212121; line-height: 1.3; }
.s-item-desc { font-size: 14px; color: #757575; margin-top: 3px; }

/* ── 환율 목록 ── */
.rate-item { padding: 14px 16px 13px; border-bottom: 1px solid #F0F0F0; background: #fff; }
.rate-name { font-size: 18px; font-weight: 700; color: #212121; }
.rate-val  { font-size: 14px; color: #757575; margin-top: 3px; }
.rate-loading { text-align: center; padding: 40px 20px; color: #aaa; font-size: 14px; }

/* ── 업데이트 시간 ── */
.updated-bar { padding: 8px 16px; font-size: 12px; color: #aaa; background: #fafafa; border-bottom: 1px solid #f0f0f0; }

/* ── 팝업 오버레이 ── */
.overlay { display: none; position: fixed; inset: 0; z-index: 500; background: rgba(0,0,0,.5); align-items: center; justify-content: center; }
.overlay.show { display: flex; }
.popup-box { background: #fff; border-radius: 4px; width: 88%; max-width: 420px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.3); max-height: 82vh; display: flex; flex-direction: column; }
.popup-hd { background: var(--theme-primary, #1D2C55); padding: 18px 20px; font-size: 18px; font-weight: 700; color: #fff; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; }
.popup-hd .material-icons { color: #fff; cursor: pointer; font-size: 22px; }

/* ── 검색창 ── */
.popup-search { padding: 10px 14px; border-bottom: 1px solid #e0e0e0; flex-shrink: 0; }
.popup-search input { width: 100%; border: 1px solid #ddd; border-radius: 6px; padding: 10px 14px; font-size: 15px; outline: none; font-family: inherit; }
.popup-search input:focus { border-color: var(--theme-primary, #1D2C55); }

/* ── 팝업 리스트 ── */
.popup-list { overflow-y: auto; flex: 1; }
.popup-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #F0F0F0; cursor: pointer; background: #fff; }
.popup-item:active { background: #F5F5F5; }
.popup-item-name { font-size: 17px; color: #212121; }
.popup-item-code { font-size: 13px; color: #757575; margin-top: 2px; }
.popup-radio { width: 22px; height: 22px; border-radius: 50%; border: 2px solid #9E9E9E; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.popup-radio.selected { border-color: var(--theme-primary, #1D2C55); }
.popup-radio.selected::after { content: ''; width: 12px; height: 12px; border-radius: 50%; background: var(--theme-primary, #1D2C55); }
.popup-cancel { width: 100%; padding: 16px 0; background: var(--theme-primary, #1D2C55); color: #fff; border: none; font-size: 17px; font-weight: 700; cursor: pointer; flex-shrink: 0; }
.popup-cancel:active { opacity: .85; }
.popup-no-result { padding: 40px 20px; text-align: center; color: #aaa; font-size: 14px; }

/* ── 표시 형식 팝업 ── */
.fmt-body { padding: 16px; overflow-y: auto; flex: 1; }
.fmt-row { display: flex; align-items: center; margin-bottom: 12px; }
.fmt-label { font-size: 15px; color: #424242; width: 72px; flex-shrink: 0; }
.fmt-input { flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 10px 14px; font-size: 15px; text-align: center; outline: none; font-family: inherit; cursor: pointer; background: #fff; }
.fmt-input:focus { border-color: var(--theme-primary, #1D2C55); }
.fmt-preview-label { font-size: 15px; color: #424242; margin-bottom: 8px; }
.fmt-preview-box { border: 1px solid #ddd; border-radius: 6px; padding: 14px; text-align: center; font-size: 18px; font-weight: 700; color: #212121; }
.fmt-foot { display: flex; border-top: 1px solid #eee; flex-shrink: 0; }
.fmt-foot button { flex: 1; padding: 16px 0; border: none; font-size: 16px; font-weight: 700; cursor: pointer; background: var(--theme-primary, #1D2C55); color: #fff; }
.fmt-foot button:first-child { background: #757575; }
.fmt-foot button:first-child:active, .fmt-foot button:last-child:active { opacity: .85; }

/* ── 광고 바 ── */
.ad-bar { position: fixed; bottom: 0; left: 0; right: 0; height: 60px; background: #F5F5F5; border-top: 1px solid #E0E0E0; display: flex; align-items: center; padding: 0 12px; gap: 10px; z-index: 200; }
.ad-badge { font-size: 11px; color: #fff; background: #9E9E9E; padding: 2px 5px; border-radius: 3px; flex-shrink: 0; }
.ad-text  { flex: 1; font-size: 13px; color: #424242; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ad-btn   { width: 36px; height: 36px; background: #E53935; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ad-btn .material-icons { color: #fff; font-size: 20px; }
</style>
</head>
<body>

<!-- ══ 헤더 ══ -->
<header class="s-header">
  <div class="s-icon" onclick="history.back()"><span class="material-icons">arrow_back</span></div>
  <div class="s-title">통화와 환율</div>
  <div class="s-icon" onclick="refreshRates()" title="환율 새로고침"><span class="material-icons">refresh</span></div>
</header>

<!-- ══ 기본 통화 ══ -->
<div class="s-section">기본 통화</div>
<div class="s-item" onclick="openCurrencyPopup()">
  <div>
    <div class="s-item-title" id="base-name">대한민국 원</div>
    <div class="s-item-desc" id="base-code">KRW</div>
  </div>
</div>
<div class="s-item" onclick="openFormatPopup()">
  <div>
    <div class="s-item-title">표시 형식</div>
    <div class="s-item-desc" id="format-preview">₩100,000</div>
  </div>
</div>

<!-- ══ 환율 설정 ══ -->
<div class="s-section">환율 설정</div>
<?php if ($apiError): ?>
<div class="updated-bar">⚠ 네트워크 오류 — 마지막 저장된 환율을 표시합니다.</div>
<?php elseif ($updated): ?>
<div class="updated-bar">업데이트: <?= htmlspecialchars(substr($updated, 0, 16)) ?> UTC</div>
<?php endif; ?>

<div id="rate-list">
<?php
foreach ($allCurrencies as $code => [$name, $symbol]) {
    if (!isset($rates[$code])) continue;
    // 기본 표시: 1 CODE = X KRW (rates는 KRW 기준, 즉 1KRW = rates[code] 이므로 역수)
    $rateVal = ($rates[$code] != 0) ? round(1 / $rates[$code], 5) : 0;
    echo '<div class="rate-item" data-code="'.htmlspecialchars($code).'" data-krwrate="'.htmlspecialchars($rates[$code]).'">';
    echo '  <div class="rate-name">'.htmlspecialchars($name).'</div>';
    echo '  <div class="rate-val rate-display">1 '.htmlspecialchars($code).' = <span class="rval">'.number_format($rateVal, 5).'</span> <span class="rbase">KRW</span></div>';
    echo '</div>';
}
?>
</div>

<!-- ══ 팝업: 기본 통화 선택 ══ -->
<div class="overlay" id="currencyOverlay" onclick="closeOverlay(event,'currencyOverlay')">
  <div class="popup-box">
    <div class="popup-hd">기본 통화</div>
    <div class="popup-search">
      <input type="text" id="currencySearch" placeholder="검색" oninput="filterCurrencies(this.value)">
    </div>
    <div class="popup-list" id="currencyList">
      <?php foreach ($allCurrencies as $code => [$name, $symbol]): ?>
      <div class="popup-item" data-code="<?= htmlspecialchars($code) ?>"
           data-name="<?= htmlspecialchars($name) ?>"
           onclick="selectCurrency('<?= htmlspecialchars($code) ?>')">
        <div>
          <div class="popup-item-name"><?= htmlspecialchars($name) ?></div>
          <div class="popup-item-code"><?= htmlspecialchars($code) ?></div>
        </div>
        <div class="popup-radio" id="radio-<?= htmlspecialchars($code) ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="popup-cancel" onclick="closePopup('currencyOverlay')">취소</button>
  </div>
</div>

<!-- ══ 팝업: 표시 형식 ══ -->
<div class="overlay" id="formatOverlay" onclick="closeOverlay(event,'formatOverlay')">
  <div class="popup-box">
    <div class="popup-hd">
      <span>표시 형식</span>
      <span class="material-icons" onclick="resetFormat()" title="초기화">delete</span>
    </div>
    <div class="fmt-body">
      <div class="fmt-row">
        <span class="fmt-label">기호</span>
        <input class="fmt-input" id="fmt-symbol" type="text" value="₩" oninput="updatePreview()">
      </div>
      <div class="fmt-row">
        <span class="fmt-label">띄어쓰기</span>
        <input class="fmt-input" id="fmt-spacing" type="text" placeholder="-" maxlength="2" oninput="updatePreview()">
      </div>
      <div class="fmt-row">
        <span class="fmt-label">기호위치</span>
        <div class="fmt-input" id="fmt-pos-btn" onclick="togglePosition()" style="cursor:pointer; user-select:none;">금액의 앞</div>
      </div>
      <div style="margin-top:16px;">
        <div class="fmt-preview-label">미리보기</div>
        <div class="fmt-preview-box" id="fmt-preview-box">₩100,000</div>
      </div>
    </div>
    <div class="fmt-foot">
      <button onclick="closePopup('formatOverlay')">취소</button>
      <button onclick="saveFormat()">확인</button>
    </div>
  </div>
</div>

<!-- ══ 광고 바 ══ -->
<div class="ad-bar">
  <span class="ad-badge">광고</span>
  <span class="ad-text">광고 영역</span>
  <div class="ad-btn"><span class="material-icons">file_download</span></div>
</div>

<!-- ══ 환율 데이터 전달 ══ -->
<script>
// PHP에서 JS로 데이터 전달
const CURRENCIES = <?= json_encode(array_map(fn($v) => ['name' => $v[0], 'symbol' => $v[1]], $allCurrencies), JSON_UNESCAPED_UNICODE) ?>;
const KRW_RATES  = <?= json_encode(!empty($rates) ? $rates : new stdClass()) ?>;
// KRW_RATES[code] = "1 KRW = X code" (code당 KRW 환율)

// ── 상태 ──
let baseCurrency = localStorage.getItem('currency_base')    || 'KRW';
let fmtSymbol    = localStorage.getItem('currency_symbol')  || '₩';
let fmtSpacing   = localStorage.getItem('currency_spacing') || '';
let fmtPos       = localStorage.getItem('currency_pos')     || 'before';
let fmtPosLabel  = fmtPos === 'before' ? '금액의 앞' : '금액의 뒤';

// ── 초기화 ──
function init() {
  updateBaseDisplay();
  updateFormatDisplay();
  updateRateList();
  renderCurrencyRadios();
}

// ── 기본 통화 표시 ──
function updateBaseDisplay() {
  const cur = CURRENCIES[baseCurrency];
  document.getElementById('base-name').textContent = cur ? cur.name : baseCurrency;
  document.getElementById('base-code').textContent = baseCurrency;
}

// ── 표시 형식 미리보기 ──
function formatAmount(amount) {
  const numStr = Number(amount).toLocaleString('ko-KR');
  const sp = fmtSpacing === '-' ? '' : fmtSpacing;
  return fmtPos === 'before'
    ? fmtSymbol + sp + numStr
    : numStr + sp + fmtSymbol;
}
function updateFormatDisplay() {
  document.getElementById('format-preview').textContent = formatAmount(100000);
}

// ── 환율 목록 갱신 ──
function updateRateList() {
  const baseRate = KRW_RATES[baseCurrency] ?? 1; // 1 KRW = baseRate [baseCurrency]
  document.querySelectorAll('#rate-list .rate-item').forEach(el => {
    const code     = el.dataset.code;
    const krwRate  = parseFloat(el.dataset.krwrate); // 1 KRW = krwRate [code]
    if (!krwRate) return;
    // 1 [code] = ? [baseCurrency]
    // 1 KRW = krwRate [code]  →  1 [code] = 1/krwRate KRW
    // 1 KRW = baseRate [base]  →  1 [code] = baseRate/krwRate [base]
    const rate = baseCurrency === 'KRW'
      ? (1 / krwRate)
      : (baseRate / krwRate);
    el.querySelector('.rval').textContent  = formatRate(rate);
    el.querySelector('.rbase').textContent = baseCurrency;
  });
}

function formatRate(r) {
  if (r >= 10000)  return Math.round(r).toLocaleString('ko-KR');
  if (r >= 100)    return r.toFixed(2);
  if (r >= 1)      return r.toFixed(4);
  return r.toFixed(5);
}

// ── 기본 통화 팝업 ──
function openCurrencyPopup() {
  document.getElementById('currencySearch').value = '';
  filterCurrencies('');
  renderCurrencyRadios();
  document.getElementById('currencyOverlay').classList.add('show');
}

function renderCurrencyRadios() {
  document.querySelectorAll('#currencyList .popup-radio').forEach(el => {
    el.classList.toggle('selected', el.id === 'radio-' + baseCurrency);
  });
  // 선택된 항목으로 스크롤
  const sel = document.getElementById('radio-' + baseCurrency);
  if (sel) sel.closest('.popup-item').scrollIntoView({block: 'nearest'});
}

function filterCurrencies(q) {
  q = q.trim().toLowerCase();
  let found = 0;
  document.querySelectorAll('#currencyList .popup-item').forEach(el => {
    const name = el.dataset.name.toLowerCase();
    const code = el.dataset.code.toLowerCase();
    const show = !q || name.includes(q) || code.includes(q);
    el.style.display = show ? '' : 'none';
    if (show) found++;
  });
  let nr = document.getElementById('no-result');
  if (!found) {
    if (!nr) {
      nr = document.createElement('div');
      nr.id = 'no-result';
      nr.className = 'popup-no-result';
      nr.textContent = '검색 결과가 없습니다.';
      document.getElementById('currencyList').appendChild(nr);
    }
    nr.style.display = '';
  } else if (nr) {
    nr.style.display = 'none';
  }
}

function selectCurrency(code) {
  baseCurrency = code;
  localStorage.setItem('currency_base', code);
  // 기호도 해당 통화 기호로 자동 업데이트
  const sym = CURRENCIES[code]?.symbol;
  if (sym) {
    fmtSymbol = sym;
    localStorage.setItem('currency_symbol', sym);
  }
  updateBaseDisplay();
  updateFormatDisplay();
  updateRateList();
  renderCurrencyRadios();
  closePopup('currencyOverlay');
}

// ── 표시 형식 팝업 ──
function openFormatPopup() {
  document.getElementById('fmt-symbol').value   = fmtSymbol;
  document.getElementById('fmt-spacing').value  = fmtSpacing;
  document.getElementById('fmt-pos-btn').textContent = fmtPosLabel;
  updatePreview();
  document.getElementById('formatOverlay').classList.add('show');
}

function togglePosition() {
  const isAfter = document.getElementById('fmt-pos-btn').textContent === '금액의 뒤';
  document.getElementById('fmt-pos-btn').textContent = isAfter ? '금액의 앞' : '금액의 뒤';
  updatePreview();
}

function updatePreview() {
  const sym     = document.getElementById('fmt-symbol').value;
  const sp      = document.getElementById('fmt-spacing').value.replace(/-/g, '');
  const posText = document.getElementById('fmt-pos-btn').textContent;
  const numStr  = (100000).toLocaleString('ko-KR');
  const preview = posText === '금액의 앞'
    ? sym + sp + numStr
    : numStr + sp + sym;
  document.getElementById('fmt-preview-box').textContent = preview;
}

function saveFormat() {
  fmtSymbol  = document.getElementById('fmt-symbol').value;
  fmtSpacing = document.getElementById('fmt-spacing').value.replace(/-/g, '');
  fmtPos     = document.getElementById('fmt-pos-btn').textContent === '금액의 앞' ? 'before' : 'after';
  fmtPosLabel = fmtPos === 'before' ? '금액의 앞' : '금액의 뒤';
  localStorage.setItem('currency_symbol',  fmtSymbol);
  localStorage.setItem('currency_spacing', fmtSpacing);
  localStorage.setItem('currency_pos',     fmtPos);
  updateFormatDisplay();
  closePopup('formatOverlay');
}

function resetFormat() {
  document.getElementById('fmt-symbol').value            = '₩';
  document.getElementById('fmt-spacing').value           = '';
  document.getElementById('fmt-pos-btn').textContent     = '금액의 앞';
  updatePreview();
}

// ── 환율 새로고침 (캐시 삭제 후 리로드) ──
function refreshRates() {
  const icon = document.querySelector('.s-header .material-icons[style], .s-header .material-icons');
  if (icon) { icon.style.animation = 'spin 1s linear infinite'; }
  fetch('../api/exchange_rate.php?refresh=' + Date.now())
    .then(r => r.json())
    .then(() => location.reload())
    .catch(() => location.reload());
}

// ── 팝업 닫기 ──
function closePopup(id) { document.getElementById(id).classList.remove('show'); }
function closeOverlay(e, id) { if (e.target.id === id) closePopup(id); }

// ── 초기 실행 ──
init();

// ── 글자 크기 ──
(function(){ var fs=localStorage.getItem('design_fontsize')||'보통'; document.body.style.zoom=fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1'; })();
</script>

<style>
@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
</style>
</body>
</html>
