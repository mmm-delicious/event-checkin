<?php
/*
Template Name: Public Event Scanner
*/
defined('ABSPATH') || exit;

$event_slug = sanitize_title_with_dashes($_GET['event'] ?? '');
$upload_dir = wp_upload_dir();
$events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
$filepath   = trailingslashit($events_dir) . $event_slug . '.json';

if (!file_exists($filepath)) {
    ?><!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Event Not Found</title></head>
    <body style="text-align:center;padding:80px 20px;font-family:sans-serif;">
    <h2>Event not found.</h2><p>Please check the event URL.</p>
    </body></html><?php
    exit;
}

// Load event data for display
$event_data = json_decode(file_get_contents($filepath), true);
$event_name = $event_data['name'] ?? 'Event';
$event_date = !empty($event_data['created_at']) ? date('l, F j, Y', strtotime($event_data['created_at'])) : '';

// Determine guest list presence via phones index (avoids keeping full JSON in memory)
$phones_path = trailingslashit($events_dir) . $event_slug . '.phones.json';
$has_guests  = file_exists($phones_path) || !empty($event_data['guests']);
unset($event_data); // free memory — scanner page doesn't need guest records

$ajax_url    = admin_url('admin-ajax.php');
$plugin_url  = plugin_dir_url(__FILE__);
$default_area = preg_replace('/\D/', '', get_option('mmm_default_area_code', '808'));
$site_icon   = get_site_icon_url(128);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="mobile-web-app-capable" content="yes">
  <title><?php echo esc_html($event_name); ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html {
      height: 100%;
      -webkit-text-size-adjust: 100%;
      overscroll-behavior: none;
    }

    body {
      height: 100%;
      overflow: hidden;
      background: #f0f4f8;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      -webkit-tap-highlight-color: transparent;
      overscroll-behavior: none;
      touch-action: none; /* prevent pull-to-refresh on the body itself */
    }

    /* ── App shell ───────────────────────────────────────── */
    #app {
      display: flex;
      flex-direction: column;
      height: 100vh;
      height: 100dvh;
      max-width: 560px;
      margin: 0 auto;
    }

    /* ── App bar ─────────────────────────────────────────── */
    #app-header {
      background: #0073aa;
      color: #fff;
      padding-top: max(env(safe-area-inset-top, 0px), 14px);
      padding-bottom: 12px;
      padding-left: 16px;
      padding-right: 16px;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }
    #app-logo  { height: 52px; width: auto; max-width: 100%; object-fit: contain; margin-bottom: 2px; }
    #app-title { font-size: 1.15rem; font-weight: 700; text-align: center; line-height: 1.2; }
    #app-date  { font-size: 0.78rem; opacity: 0.85; }

    /* ── Toggle switch ───────────────────────────────────── */
    #toggle-row {
      flex-shrink: 0;
      padding: 10px 16px;
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: center;
    }

    .mode-toggle {
      display: flex;
      background: #e2e8f0;
      border-radius: 100px;
      padding: 3px;
      position: relative;
      cursor: pointer;
      touch-action: manipulation;
      user-select: none;
      -webkit-user-select: none;
    }

    .toggle-slider {
      position: absolute;
      top: 3px; bottom: 3px; left: 3px;
      width: calc(50% - 3px);
      background: #0073aa;
      border-radius: 100px;
      transition: left 0.2s cubic-bezier(.4,0,.2,1);
      pointer-events: none;
    }

    .mode-toggle.phone-mode .toggle-slider { left: 50%; }

    .toggle-opt {
      position: relative;
      z-index: 1;
      padding: 9px 28px;
      font-size: 0.95rem;
      font-weight: 700;
      color: #64748b;
      border-radius: 100px;
      transition: color 0.2s;
      white-space: nowrap;
    }

    .mode-toggle:not(.phone-mode) .toggle-opt:first-child { color: #fff; }
    .mode-toggle.phone-mode       .toggle-opt:last-child  { color: #fff; }

    /* ── Content screens ─────────────────────────────────── */
    #screen-qr,
    #screen-phone {
      flex: 1;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 0;
    }

    /* ── QR screen ───────────────────────────────────────── */
    #screen-qr { padding: 16px 20px; gap: 12px; }

    #start-camera-btn {
      flex-shrink: 0;
      padding: 14px 36px;
      font-size: 1.05rem;
      font-weight: 700;
      background: #0073aa;
      color: #fff;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      touch-action: manipulation;
      transition: opacity 0.15s;
    }
    #start-camera-btn:active { opacity: 0.8; }

    #scanner-controls {
      flex-shrink: 0;
      font-size: 0.9rem;
    }
    #camera-selector {
      padding: 6px 10px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #fff;
      touch-action: manipulation;
    }

    #qr-scanner {
      flex-shrink: 0;
      width: min(280px, 80vw);
      height: min(280px, 80vw);
      overflow: hidden;
      position: relative;
    }
    #qr-scanner video { width: 100% !important; height: 100% !important; object-fit: cover; }

    /* ── Phone screen ────────────────────────────────────── */
    #screen-phone { padding: 12px 20px 16px; }

    #phone-display {
      flex-shrink: 0;
      display: block;
      width: 100%;
      max-width: 380px;
      font-size: 2.2rem;
      font-weight: 700;
      text-align: center;
      letter-spacing: 4px;
      padding: 12px;
      background: #fff;
      border: 2px solid #cbd5e1;
      border-radius: 12px;
      color: #222;
      line-height: 1;
      touch-action: manipulation; /* prevents double-tap zoom on iOS Safari */
      user-select: none;
      -webkit-user-select: none;
    }
    #phone-display.has-value { border-color: #0073aa; color: #0073aa; }
    #phone-display.ph        { color: #94a3b8; font-size: 1.2rem; font-weight: 400; letter-spacing: 1px; }

    /* Dialpad fills all remaining height — no scroll */
    .dialpad-wrap {
      flex: 1;
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: 380px;
      min-height: 0;
      margin-top: 10px;
    }

    .dialpad {
      flex: 1;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      grid-template-rows: repeat(4, 1fr);
      gap: 8px;
      min-height: 0;
    }

    .dialpad-key {
      background: #fff;
      border: none;
      border-radius: 12px;
      font-size: clamp(1.3rem, 5vw, 1.8rem);
      font-weight: 700;
      color: #1e293b;
      cursor: pointer;
      touch-action: manipulation;
      box-shadow: 0 2px 4px rgba(0,0,0,0.10);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      user-select: none;
      -webkit-user-select: none;
      transition: background 0.1s;
    }
    .dialpad-key:active { background: #e2e8f0; }
    .dialpad-key .sub   { font-size: 0.5em; font-weight: 400; letter-spacing: 1px; color: #94a3b8; margin-top: 1px; }
    .dialpad-key.key-back { background: #f1f5f9; font-size: clamp(1rem, 4vw, 1.5rem); color: #64748b; }
    .dialpad-key.key-back:active { background: #e2e8f0; }

    #phone-search-btn {
      flex-shrink: 0;
      display: block;
      width: 100%;
      height: 52px;
      margin-top: 8px;
      font-size: 1.1rem;
      font-weight: 700;
      background: #0073aa;
      color: #fff;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      touch-action: manipulation;
      transition: opacity 0.15s;
    }
    #phone-search-btn:disabled { opacity: 0.35; cursor: default; }

    #phone-result {
      flex-shrink: 0;
      margin-top: 6px;
      font-size: 0.9rem;
      color: #dc2626;
      min-height: 20px;
      text-align: center;
    }

    /* ── Result overlay ──────────────────────────────────── */
    #overlay-message {
      display: none;
      position: fixed;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      z-index: 10000;
      padding: 28px 32px;
      border-radius: 20px;
      font-size: 1.45rem;
      font-weight: 700;
      background: #fff;
      box-shadow: 0 12px 48px rgba(0,0,0,0.30);
      border: 4px solid;
      text-align: center;
      width: 88vw;
      max-width: 460px;
      line-height: 1.4;
    }
    #overlay-message.ok   { border-color: #16a34a; color: #16a34a; }
    #overlay-message.warn { border-color: #d97706; color: #92400e; background: #fffbeb; }
    #overlay-message.err  { border-color: #dc2626; color: #dc2626; }

    /* ── Confirm overlay ─────────────────────────────────── */
    #confirm-backdrop {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.55);
      z-index: 9998;
    }
    #confirm-overlay {
      display: none;
      position: fixed;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      z-index: 9999;
      padding: 28px 24px 20px;
      border-radius: 20px;
      background: #fff;
      box-shadow: 0 12px 48px rgba(0,0,0,0.30);
      border: 3px solid #0073aa;
      text-align: center;
      width: 88vw;
      max-width: 400px;
    }
    #confirm-overlay h2 {
      margin: 0 0 4px;
      font-size: 0.88rem;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    #confirm-name {
      font-size: 1.9rem;
      font-weight: 800;
      margin: 8px 0 20px;
      color: #0073aa;
      line-height: 1.2;
    }
    .confirm-btns { display: flex; gap: 10px; }
    .confirm-btns button {
      flex: 1;
      height: 60px;
      font-size: 1.1rem;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      touch-action: manipulation;
    }
    .confirm-btns button:disabled { opacity: 0.5; }
    #confirm-yes { background: #16a34a; color: #fff; }
    #confirm-no  { background: #e2e8f0; color: #334155; }
  </style>
</head>
<body>

<div id="app">

  <div id="app-header">
    <?php if ($site_icon): ?>
    <img id="app-logo" src="<?php echo esc_url($site_icon); ?>" alt="">
    <?php endif; ?>
    <div id="app-title"><?php echo esc_html($event_name); ?></div>
    <?php if ($event_date): ?>
    <div id="app-date"><?php echo esc_html($event_date); ?></div>
    <?php endif; ?>
  </div>

  <?php if ($has_guests): ?>
  <div id="toggle-row">
    <div class="mode-toggle" id="mode-toggle" role="switch" aria-label="QR Scanner / Phone Entry">
      <div class="toggle-slider"></div>
      <div class="toggle-opt">&#x1F4F7; QR</div>
      <div class="toggle-opt">&#x260E; Phone</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- QR Scanner screen -->
  <div id="screen-qr">
    <button id="start-camera-btn">&#x1F4F7; Start Camera</button>
    <div id="scanner-controls" style="display:none">
      <label for="camera-selector">Camera: </label>
      <select id="camera-selector"></select>
    </div>
    <div id="qr-scanner"></div>
  </div>

  <?php if ($has_guests): ?>
  <!-- Phone entry screen -->
  <div id="screen-phone" style="display:none">
    <div id="phone-display" class="ph">Enter phone number</div>
    <div class="dialpad-wrap">
      <div class="dialpad">
        <button class="dialpad-key" data-d="1">1<span class="sub">&nbsp;</span></button>
        <button class="dialpad-key" data-d="2">2<span class="sub">ABC</span></button>
        <button class="dialpad-key" data-d="3">3<span class="sub">DEF</span></button>
        <button class="dialpad-key" data-d="4">4<span class="sub">GHI</span></button>
        <button class="dialpad-key" data-d="5">5<span class="sub">JKL</span></button>
        <button class="dialpad-key" data-d="6">6<span class="sub">MNO</span></button>
        <button class="dialpad-key" data-d="7">7<span class="sub">PQRS</span></button>
        <button class="dialpad-key" data-d="8">8<span class="sub">TUV</span></button>
        <button class="dialpad-key" data-d="9">9<span class="sub">WXYZ</span></button>
        <button class="dialpad-key key-back" id="dialpad-back">&#9003;</button>
        <button class="dialpad-key" data-d="0">0<span class="sub">+</span></button>
        <button class="dialpad-key" style="visibility:hidden"></button>
      </div>
      <button id="phone-search-btn" disabled>Search</button>
      <div id="phone-result"></div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- #app -->

<!-- Overlays (full-viewport, outside #app) -->
<div id="overlay-message"></div>
<div id="confirm-backdrop"></div>
<div id="confirm-overlay">
  <h2>Confirm Check-In</h2>
  <div id="confirm-name"></div>
  <div class="confirm-btns">
    <button id="confirm-no">Cancel</button>
    <button id="confirm-yes">Check In</button>
  </div>
</div>

<audio id="snd-ok"  preload="auto"><source src="<?php echo esc_url($plugin_url . 'assets/audio/success.mp3'); ?>" type="audio/mpeg"></audio>
<audio id="snd-err" preload="auto"><source src="<?php echo esc_url($plugin_url . 'assets/audio/error.mp3');   ?>" type="audio/mpeg"></audio>

<script src="<?php echo esc_url($plugin_url . 'assets/js/html5-qrcode.min.js'); ?>"></script>
<script>
(function () {
'use strict';

const AJAX      = <?php echo json_encode($ajax_url); ?>;
const EVENT     = <?php echo json_encode($event_slug); ?>;
const DEF_AREA  = <?php echo json_encode($default_area); ?>;
const HAS_GUESTS = <?php echo $has_guests ? 'true' : 'false'; ?>;

const sndOk  = document.getElementById('snd-ok');
const sndErr = document.getElementById('snd-err');

// ── Result overlay ────────────────────────────────────────────────────
let overlayTimer = null;

function showOverlay(state, text) {
  var el = document.getElementById('overlay-message');
  el.className   = state; // 'ok' | 'warn' | 'err'
  el.textContent = text;
  el.style.display = 'block';
  try { (state === 'err' ? sndErr : sndOk).play(); } catch(e) {}
  if (overlayTimer) clearTimeout(overlayTimer);
  overlayTimer = setTimeout(function () {
    el.style.display = 'none';
    qrLocked = false;
  }, state === 'warn' ? 8000 : 5000);
}

// Handles both legacy string responses and new {message, warning} objects
function handleResponse(res, onDone) {
  if (!res.success) {
    showOverlay('err', res.data || '❌ Error.');
  } else if (res.data && typeof res.data === 'object') {
    showOverlay(res.data.warning ? 'warn' : 'ok', res.data.message);
  } else {
    showOverlay('ok', res.data);
  }
  if (onDone) onDone();
}

// ── QR Scanner ────────────────────────────────────────────────────────
var startBtn      = document.getElementById('start-camera-btn');
var camControls   = document.getElementById('scanner-controls');
var camSelect     = document.getElementById('camera-selector');
var qr            = new Html5Qrcode('qr-scanner');
var qrLocked      = false;
var qrRunning     = false;
var qrDeviceId    = null;

function startQr(id) {
  if (qrRunning) return;
  qr.start(id, { fps: 10, qrbox: 250 }, handleScan)
    .then(function () {
      qrRunning = true;
      startBtn.textContent = '\uD83D\uDCF7 Stop Camera';
      var opt = camSelect.options[camSelect.selectedIndex];
      var lbl = opt ? opt.text.toLowerCase() : '';
      var vid = document.querySelector('#qr-scanner video');
      if (vid) vid.style.transform = lbl.includes('front') ? 'scaleX(-1)' : '';
    })
    .catch(function (e) {
      console.warn('QR start:', e);
      startBtn.textContent = '\uD83D\uDCF7 Start Camera';
    });
}

function stopQr() {
  if (!qrRunning) return;
  qr.stop()
    .then(function () { qrRunning = false; startBtn.textContent = '\uD83D\uDCF7 Start Camera'; })
    .catch(function (e) { console.warn('QR stop:', e); });
}

function handleScan(decoded) {
  if (qrLocked) return;
  qrLocked = true;
  var body = 'action=mmm_checkin&data=' + encodeURIComponent(decoded) + '&event=' + encodeURIComponent(EVENT);
  fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
    .then(function (r) { return r.json(); })
    .then(function (res) { handleResponse(res); })
    .catch(function () { showOverlay('err', 'Connection error.'); });
}

startBtn.addEventListener('click', function () {
  if (qrRunning) { stopQr(); return; }
  if (qrDeviceId) { startQr(qrDeviceId); return; }
  Html5Qrcode.getCameras().then(function (devices) {
    if (!devices.length) { startBtn.textContent = 'No camera found'; return; }
    devices.sort(function (a, b) {
      var s = function (l) {
        l = (l || '').toLowerCase();
        if (l.includes('front'))                       return 10;
        if (l.includes('ultra') || l.includes('0.5')) return 5;
        if (l.includes('back')  || l.includes('rear')) return 0;
        return 3;
      };
      return s(a.label) - s(b.label);
    });
    devices.forEach(function (cam, i) {
      var o = document.createElement('option');
      o.value = cam.id;
      o.text  = cam.label || ('Camera ' + (i + 1));
      camSelect.appendChild(o);
    });
    if (devices.length > 1) camControls.style.display = 'block';
    qrDeviceId = devices[0].id;
    camSelect.addEventListener('change', function () {
      qrDeviceId = this.value;
      if (qrRunning) qr.stop().then(function () { qrRunning = false; startQr(qrDeviceId); });
    });
    startQr(qrDeviceId);
  }).catch(function (e) {
    console.warn('Camera:', e);
    startBtn.textContent = 'Camera access denied';
  });
});

if (HAS_GUESTS) {
  // ── Mode toggle ─────────────────────────────────────────────────────
  var toggle   = document.getElementById('mode-toggle');
  var scrQr    = document.getElementById('screen-qr');
  var scrPhone = document.getElementById('screen-phone');
  var phoneMode = false;

  toggle.addEventListener('click', function () {
    phoneMode = !phoneMode;
    toggle.classList.toggle('phone-mode', phoneMode);
    scrQr.style.display    = phoneMode ? 'none' : '';
    scrPhone.style.display = phoneMode ? ''     : 'none';
    if (phoneMode && qrRunning) stopQr();
  });

  // ── Dialpad ─────────────────────────────────────────────────────────
  var phoneDis  = document.getElementById('phone-display');
  var searchBtn = document.getElementById('phone-search-btn');
  var resultEl  = document.getElementById('phone-result');
  var backdrop  = document.getElementById('confirm-backdrop');
  var confirmOv = document.getElementById('confirm-overlay');
  var confirmNm = document.getElementById('confirm-name');
  var confirmYes = document.getElementById('confirm-yes');
  var confirmNo  = document.getElementById('confirm-no');

  var digits  = '';
  var pending = null;

  function fmt(d) {
    if (d.length > 7) return d.slice(0,3) + '-' + d.slice(3,6) + '-' + d.slice(6);
    if (d.length > 3) return d.slice(0,3) + '-' + d.slice(3);
    return d;
  }

  function refreshDisplay() {
    if (!digits) {
      phoneDis.textContent  = 'Enter phone number';
      phoneDis.className    = 'ph';
      searchBtn.textContent = 'Search';
    } else {
      phoneDis.textContent  = fmt(digits);
      phoneDis.className    = 'has-value';
      searchBtn.textContent = digits.length === 7 ? 'Search (' + DEF_AREA + ')' : 'Search';
    }
    searchBtn.disabled = !(digits.length === 7 || digits.length >= 10);
  }

  document.querySelectorAll('.dialpad-key[data-d]').forEach(function (k) {
    k.addEventListener('click', function () {
      if (digits.length >= 10) return;
      digits += this.dataset.d;
      resultEl.textContent = '';
      refreshDisplay();
    });
  });

  document.getElementById('dialpad-back').addEventListener('click', function () {
    if (!digits) return;
    digits = digits.slice(0, -1);
    resultEl.textContent = '';
    refreshDisplay();
  });

  function closeConfirm() {
    backdrop.style.display  = 'none';
    confirmOv.style.display = 'none';
    pending = null;
  }

  function openConfirm(name) {
    confirmNm.textContent   = name;
    backdrop.style.display  = 'block';
    confirmOv.style.display = 'block';
  }

  backdrop.addEventListener('click', closeConfirm);
  confirmNo.addEventListener('click', closeConfirm);

  searchBtn.addEventListener('click', function () {
    resultEl.textContent = '';
    searchBtn.disabled   = true;
    var body = 'action=mmm_search_by_phone&phone=' + encodeURIComponent(fmt(digits)) + '&event=' + encodeURIComponent(EVENT);
    fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        searchBtn.disabled = !(digits.length === 7 || digits.length >= 10);
        if (!res.success) {
          resultEl.textContent = res.data || 'No match found.';
          try { sndErr.play(); } catch(e) {}
          return;
        }
        var m = res.data[0];
        pending = { idx: m.idx, token: m.token, phone: m.full_phone };
        openConfirm(m.name);
      })
      .catch(function () {
        searchBtn.disabled = !(digits.length === 7 || digits.length >= 10);
        resultEl.textContent = 'Connection error.';
      });
  });

  confirmYes.addEventListener('click', function () {
    if (!pending) return;
    confirmYes.disabled = true;
    confirmNo.disabled  = true;
    var body = 'action=mmm_checkin_by_phone'
      + '&event='  + encodeURIComponent(EVENT)
      + '&idx='    + encodeURIComponent(pending.idx)
      + '&phone='  + encodeURIComponent(pending.phone)
      + '&token='  + encodeURIComponent(pending.token);
    fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        confirmYes.disabled = false;
        confirmNo.disabled  = false;
        closeConfirm();
        digits = '';
        refreshDisplay();
        resultEl.textContent = '';
        handleResponse(res);
      })
      .catch(function () {
        confirmYes.disabled = false;
        confirmNo.disabled  = false;
        closeConfirm();
        showOverlay('err', 'Connection error.');
      });
  });
}

})();
</script>
</body>
</html>
