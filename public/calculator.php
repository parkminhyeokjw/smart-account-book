<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>계산기</title>
<script src="design_apply.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --theme-primary: #455A64;
    --theme-border: rgba(69, 90, 100, 0.35);
  }

  body {
    display: flex;
    flex-direction: column;
    height: 100dvh;
    height: 100vh;
    background: #fafafa;
    font-family: 'Segoe UI', sans-serif;
    overflow: hidden;
  }

  /* ── Header ── */
  .header {
    display: flex;
    align-items: center;
    height: 56px;
    background: var(--theme-primary, #455A64);
    color: #fff;
    padding: 0 4px;
    flex-shrink: 0;
  }
  .header-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 22px;
    width: 48px;
    height: 48px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .header-btn:active { background: rgba(255,255,255,0.15); }
  .header-title {
    flex: 1;
    font-size: 18px;
    font-weight: 500;
    text-align: center;
  }

  /* ── Display ── */
  .display-wrap {
    flex: 1;
    background: #fff;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 12px 16px 8px 16px;
    overflow: hidden;
    border-bottom: 1px solid #e0e0e0;
  }
  .display-scroll {
    overflow-y: auto;
    text-align: right;
    word-break: break-all;
  }
  .display-expr {
    font-size: 36px;
    color: #212121;
    line-height: 1.2;
    display: inline-block;
    border-right: 2px solid var(--theme-primary, #455A64);
    padding-right: 4px;
    min-height: 44px;
  }
  .display-result {
    font-size: 22px;
    color: #757575;
    margin-top: 4px;
    min-height: 28px;
    text-align: right;
  }

  /* ── Keyboard ── */
  .keyboard {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    grid-template-rows: 52px repeat(5, 68px);
    gap: 0;
    flex-shrink: 0;
    background: #ececec;
  }

  .key {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    color: var(--theme-primary, #455A64);
    background: #fff;
    border: 1px solid var(--theme-border, rgba(69,90,100,0.35));
    transition: background 0.1s;
    outline: none;
  }
  .key:active { background: #e8eef1; }

  /* Row 0: toolbar row */
  .key-toolbar {
    background: #f5f5f5;
    border: none;
    border-bottom: 1px solid #ddd;
    font-size: 24px;
  }
  .key-toolbar:active { background: #e8e8e8; }
  .key-globe { justify-content: flex-start; padding-left: 16px; }
  .key-spacer { cursor: default; }
  .key-backspace { justify-content: flex-end; padding-right: 16px; }

  /* Number keys */
  .key-number { color: #212121; }

  /* Operator keys */
  .key-operator { color: var(--theme-primary, #455A64); font-weight: 500; }

  /* Equals key */
  .key-equals {
    background: var(--theme-primary, #455A64);
    color: #fff;
    font-size: 32px;
    font-weight: 600;
    border: none;
  }
  .key-equals:active { filter: brightness(0.9); }

  /* C key */
  .key-clear { color: #B71C1C; }
</style>
</head>
<body>

<!-- Header -->
<header class="header">
  <button class="header-btn" onclick="history.back()" aria-label="뒤로">&#8592;</button>
  <span class="header-title">계산기</span>
  <button class="header-btn" aria-label="더보기">&#8942;</button>
</header>

<!-- Display -->
<div class="display-wrap" id="displayWrap">
  <div class="display-scroll" id="displayScroll">
    <div class="display-expr" id="displayExpr">0</div>
  </div>
  <div class="display-result" id="displayResult"></div>
</div>

<!-- Keyboard -->
<div class="keyboard" id="keyboard">
  <!-- Row 0 -->
  <button class="key key-toolbar key-globe"     data-action="globe">🌐</button>
  <button class="key key-toolbar key-spacer"    data-action="none"></button>
  <button class="key key-toolbar key-spacer"    data-action="none"></button>
  <button class="key key-toolbar key-backspace" data-action="backspace">⌫</button>
  <!-- Row 1 -->
  <button class="key key-clear"    data-action="clear">C</button>
  <button class="key key-operator" data-action="paren">()</button>
  <button class="key key-operator" data-action="percent">%</button>
  <button class="key key-operator" data-action="op" data-val="÷">÷</button>
  <!-- Row 2 -->
  <button class="key key-number"   data-action="digit" data-val="7">7</button>
  <button class="key key-number"   data-action="digit" data-val="8">8</button>
  <button class="key key-number"   data-action="digit" data-val="9">9</button>
  <button class="key key-operator" data-action="op"    data-val="×">×</button>
  <!-- Row 3 -->
  <button class="key key-number"   data-action="digit" data-val="4">4</button>
  <button class="key key-number"   data-action="digit" data-val="5">5</button>
  <button class="key key-number"   data-action="digit" data-val="6">6</button>
  <button class="key key-operator" data-action="op"    data-val="−">−</button>
  <!-- Row 4 -->
  <button class="key key-number"   data-action="digit" data-val="1">1</button>
  <button class="key key-number"   data-action="digit" data-val="2">2</button>
  <button class="key key-number"   data-action="digit" data-val="3">3</button>
  <button class="key key-operator" data-action="op"    data-val="+">+</button>
  <!-- Row 5 -->
  <button class="key key-number"   data-action="digit" data-val="0">0</button>
  <button class="key key-number"   data-action="double-zero">00</button>
  <button class="key key-number"   data-action="dot">.</button>
  <button class="key key-equals"   data-action="equals">=</button>
</div>

<script>
(function () {
  var expr       = '';       // current expression string
  var afterEqual = false;    // flag: last action was =

  var exprEl   = document.getElementById('displayExpr');
  var resultEl = document.getElementById('displayResult');
  var wrap     = document.getElementById('displayWrap');

  function setDisplay(e, r) {
    exprEl.textContent   = e || '0';
    resultEl.textContent = r || '';
    // auto-scroll to bottom
    wrap.scrollTop = wrap.scrollHeight;
  }

  function countOpen(s) {
    var open = 0;
    for (var i = 0; i < s.length; i++) {
      if (s[i] === '(') open++;
      else if (s[i] === ')') open--;
    }
    return open;
  }

  function safeEval(s) {
    // Replace display operators with JS operators
    var js = s
      .replace(/×/g, '*')
      .replace(/÷/g, '/')
      .replace(/−/g, '-')
      .replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');
    try {
      // Only allow safe characters
      if (/[^0-9+\-*/.() ]/.test(js)) return null;
      // eslint-disable-next-line no-new-func
      var result = Function('"use strict"; return (' + js + ')')();
      if (!isFinite(result)) return null;
      return result;
    } catch (e) {
      return null;
    }
  }

  function lastChar() {
    return expr.length ? expr[expr.length - 1] : '';
  }
  function isOperator(c) {
    return c === '+' || c === '−' || c === '×' || c === '÷';
  }

  function handleAction(action, val) {
    if (action === 'none') return;

    if (action === 'globe') {
      // placeholder — could navigate somewhere
      return;
    }

    if (action === 'clear') {
      expr       = '';
      afterEqual = false;
      setDisplay('0', '');
      return;
    }

    if (action === 'backspace') {
      afterEqual = false;
      if (expr.length > 0) {
        expr = expr.slice(0, -1);
      }
      setDisplay(expr || '0', '');
      return;
    }

    if (action === 'digit') {
      if (afterEqual) {
        expr       = val;
        afterEqual = false;
      } else {
        // If expression is just '0', replace with digit (unless decimal follows)
        if (expr === '0') {
          expr = val;
        } else {
          expr += val;
        }
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'double-zero') {
      if (afterEqual) {
        expr       = '0';
        afterEqual = false;
        setDisplay(expr, '');
        return;
      }
      if (expr === '' || expr === '0') {
        expr = '0';
      } else {
        expr += '00';
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'dot') {
      afterEqual = false;
      // Find the last number segment
      var parts = expr.split(/[+\-×÷()]/);
      var lastPart = parts[parts.length - 1];
      if (lastPart.indexOf('.') === -1) {
        if (expr === '' || isOperator(lastChar()) || lastChar() === '(') {
          expr += '0.';
        } else {
          expr += '.';
        }
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'op') {
      afterEqual = false;
      if (expr === '') {
        // Start with a unary minus (−) allowed
        if (val === '−') expr = '−';
        setDisplay(expr || '0', '');
        return;
      }
      var lc = lastChar();
      if (isOperator(lc)) {
        // Replace last operator
        expr = expr.slice(0, -1) + val;
      } else if (lc === '.') {
        // Close the decimal
        expr = expr.slice(0, -1) + val;
      } else {
        expr += val;
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'paren') {
      afterEqual = false;
      var open = countOpen(expr);
      if (expr === '' || isOperator(lastChar()) || lastChar() === '(') {
        expr += '(';
      } else if (open > 0) {
        expr += ')';
      } else {
        expr += '×(';
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'percent') {
      afterEqual = false;
      if (expr !== '' && !isOperator(lastChar())) {
        expr += '%';
      }
      setDisplay(expr, '');
      return;
    }

    if (action === 'equals') {
      if (expr === '') return;
      // Close any open parentheses
      var openCount = countOpen(expr);
      var toEval    = expr + ')'.repeat(openCount > 0 ? openCount : 0);
      var result    = safeEval(toEval);
      if (result === null) {
        setDisplay(expr, '오류');
      } else {
        // Show nice number
        var display = parseFloat(result.toPrecision(12)).toString();
        setDisplay(expr, '= ' + display);
        expr       = display;
        afterEqual = true;
      }
      return;
    }
  }

  // Attach click listeners
  document.getElementById('keyboard').addEventListener('click', function (e) {
    var btn = e.target.closest('.key');
    if (!btn) return;
    var action = btn.dataset.action;
    var val    = btn.dataset.val || '';
    handleAction(action, val);
  });

  // Initial display
  setDisplay('0', '');
})();
</script>

<script>
(function(){ var fs = localStorage.getItem('design_fontsize')||'보통'; document.body.style.zoom = fs==='아주 크게'?'1.2':fs==='크게'?'1.1':'1'; })();
</script>
</body>
</html>
