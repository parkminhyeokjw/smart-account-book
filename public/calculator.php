<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>계산기</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: #f0f2f8;
  font-family: -apple-system, 'Segoe UI', sans-serif;
  overflow: hidden;
  user-select: none;
  -webkit-user-select: none;
}

/* ── Display ── */
.display {
  background: #364B6D;
  padding: 20px 20px 16px;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  align-items: flex-end;
  flex-shrink: 0;
  min-height: 120px;
}
.display-expr {
  font-size: 32px;
  font-weight: 300;
  color: rgba(255,255,255,0.7);
  text-align: right;
  word-break: break-all;
  line-height: 1.2;
  min-height: 38px;
  width: 100%;
}
.display-result {
  font-size: 44px;
  font-weight: 600;
  color: #fff;
  text-align: right;
  word-break: break-all;
  line-height: 1.15;
  min-height: 52px;
  width: 100%;
  margin-top: 4px;
}

/* ── Keyboard ── */
.keyboard {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  grid-template-rows: repeat(5, 1fr);
  gap: 1px;
  flex: 1;
  background: #d0d4de;
}

.key {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  font-weight: 500;
  cursor: pointer;
  outline: none;
  border: none;
  transition: filter 0.1s;
}
.key:active { filter: brightness(0.88); }

.key-number   { background: #fff;     color: #1a1a2e; }
.key-operator { background: #eef0f8;  color: #364B6D; font-weight: 700; }
.key-clear    { background: #fff0f0;  color: #C62828; font-weight: 700; }
.key-func     { background: #eef0f8;  color: #364B6D; font-size: 20px; }
.key-back     { background: #eef0f8;  color: #364B6D; font-size: 22px; }
.key-equals   { background: #364B6D;  color: #fff;    font-size: 32px; font-weight: 700; }
</style>
</head>
<body>

<!-- Display -->
<div class="display">
  <div class="display-expr"  id="displayExpr"></div>
  <div class="display-result" id="displayResult">0</div>
</div>

<!-- Keyboard: 5 rows × 4 cols -->
<div class="keyboard" id="keyboard">
  <!-- Row 1 -->
  <button class="key key-clear"    data-action="clear">C</button>
  <button class="key key-func"     data-action="paren">()</button>
  <button class="key key-func"     data-action="percent">%</button>
  <button class="key key-back"     data-action="backspace">⌫</button>
  <!-- Row 2 -->
  <button class="key key-number"   data-action="digit" data-val="7">7</button>
  <button class="key key-number"   data-action="digit" data-val="8">8</button>
  <button class="key key-number"   data-action="digit" data-val="9">9</button>
  <button class="key key-operator" data-action="op"    data-val="÷">÷</button>
  <!-- Row 3 -->
  <button class="key key-number"   data-action="digit" data-val="4">4</button>
  <button class="key key-number"   data-action="digit" data-val="5">5</button>
  <button class="key key-number"   data-action="digit" data-val="6">6</button>
  <button class="key key-operator" data-action="op"    data-val="×">×</button>
  <!-- Row 4 -->
  <button class="key key-number"   data-action="digit" data-val="1">1</button>
  <button class="key key-number"   data-action="digit" data-val="2">2</button>
  <button class="key key-number"   data-action="digit" data-val="3">3</button>
  <button class="key key-operator" data-action="op"    data-val="−">−</button>
  <!-- Row 5 -->
  <button class="key key-number"   data-action="digit" data-val="0">0</button>
  <button class="key key-number"   data-action="double-zero">00</button>
  <button class="key key-number"   data-action="dot">.</button>
  <button class="key key-equals"   data-action="equals">=</button>
</div>

<script>
(function () {
  var expr       = '';
  var afterEqual = false;

  var exprEl   = document.getElementById('displayExpr');
  var resultEl = document.getElementById('displayResult');

  function fmt(n) {
    return parseFloat(n.toPrecision(12)).toString();
  }

  function setDisplay(e, r) {
    exprEl.textContent   = e || '';
    resultEl.textContent = r !== undefined ? r : (e || '0');
  }

  function countOpen(s) {
    var o = 0;
    for (var i = 0; i < s.length; i++) {
      if (s[i] === '(') o++; else if (s[i] === ')') o--;
    }
    return o;
  }

  function safeEval(s) {
    var js = s.replace(/×/g,'*').replace(/÷/g,'/').replace(/−/g,'-').replace(/(\d+(?:\.\d+)?)%/g,'($1/100)');
    try {
      if (/[^0-9+\-*/.() ]/.test(js)) return null;
      var r = Function('"use strict";return('+js+')')();
      return isFinite(r) ? r : null;
    } catch(e) { return null; }
  }

  function isOp(c) { return c==='+' || c==='−' || c==='×' || c==='÷'; }
  function last()  { return expr.length ? expr[expr.length-1] : ''; }

  function liveResult() {
    var r = safeEval(expr);
    return (r !== null && expr !== '') ? fmt(r) : '';
  }

  function handleAction(action, val) {
    if (action === 'clear') {
      expr = ''; afterEqual = false;
      setDisplay('', '0'); return;
    }
    if (action === 'backspace') {
      afterEqual = false;
      expr = expr.slice(0, -1);
      setDisplay(expr, expr ? liveResult() || expr : '0'); return;
    }
    if (action === 'digit') {
      if (afterEqual) { expr = val; afterEqual = false; }
      else expr = (expr === '0') ? val : expr + val;
      setDisplay(expr, liveResult() || expr); return;
    }
    if (action === 'double-zero') {
      if (afterEqual) { expr = '0'; afterEqual = false; setDisplay(expr,'0'); return; }
      expr = (expr === '' || expr === '0') ? '0' : expr + '00';
      setDisplay(expr, liveResult() || expr); return;
    }
    if (action === 'dot') {
      afterEqual = false;
      var parts = expr.split(/[+\-×÷()]/);
      if (parts[parts.length-1].indexOf('.') === -1) {
        expr += (expr === '' || isOp(last()) || last() === '(') ? '0.' : '.';
      }
      setDisplay(expr, ''); return;
    }
    if (action === 'op') {
      afterEqual = false;
      if (expr === '') { if (val === '−') expr = '−'; setDisplay(expr || '0',''); return; }
      if (isOp(last())) expr = expr.slice(0,-1) + val;
      else if (last() === '.') expr = expr.slice(0,-1) + val;
      else expr += val;
      setDisplay(expr, ''); return;
    }
    if (action === 'paren') {
      afterEqual = false;
      var open = countOpen(expr);
      if (expr === '' || isOp(last()) || last() === '(') expr += '(';
      else if (open > 0) expr += ')';
      else expr += '×(';
      setDisplay(expr, liveResult() || ''); return;
    }
    if (action === 'percent') {
      afterEqual = false;
      if (expr && !isOp(last())) expr += '%';
      setDisplay(expr, liveResult() || ''); return;
    }
    if (action === 'equals') {
      if (!expr) return;
      var toEval = expr + ')'.repeat(Math.max(0, countOpen(expr)));
      var result = safeEval(toEval);
      if (result === null) {
        setDisplay(expr, '오류');
      } else {
        var disp = fmt(result);
        setDisplay(expr + '=', disp);
        expr = disp; afterEqual = true;
      }
    }
  }

  document.getElementById('keyboard').addEventListener('click', function(e) {
    var btn = e.target.closest('.key');
    if (!btn) return;
    handleAction(btn.dataset.action, btn.dataset.val || '');
  });

  setDisplay('', '0');
})();
</script>
</body>
</html>
