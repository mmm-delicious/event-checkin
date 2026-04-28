<?php
/*
Template Name: Public Event Scanner
*/
defined('ABSPATH') || exit;

// Enforce HSTS for DL scanning — communicates to browsers to prefer HTTPS
if (!headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$event_slug  = sanitize_title_with_dashes($_GET['event'] ?? '');
$upload_dir  = wp_upload_dir();
$events_dir  = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
$meta_path   = trailingslashit($events_dir) . $event_slug . '-meta.json';
$guests_path = trailingslashit($events_dir) . $event_slug . '-guests.json';

if (!file_exists($meta_path)) {
    ?><!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Event Not Found</title></head>
    <body style="text-align:center;padding:80px 20px;font-family:sans-serif;">
    <h2>Event not found.</h2><p>Please check the event URL.</p>
    </body></html><?php
    exit;
}

// Load meta for display (tiny file — name, created_at, guest_count)
$event_meta = json_decode(file_get_contents($meta_path), true);
$event_name = $event_meta['name'] ?? 'Event';
$event_date = !empty($event_meta['created_at']) ? date('l, F j, Y', strtotime($event_meta['created_at'])) : '';
$has_guests = ( $event_meta['guest_count'] ?? 0 ) > 0 || file_exists($guests_path);
unset($event_meta);

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
  <!-- BarcodeDetector polyfill: ZXing-C++ WASM — only activates on browsers lacking native BarcodeDetector (Safari/iOS) -->
  <script defer src="<?php echo esc_url(MMM_ECI_URL . 'assets/js/barcode-detector-polyfill.js'); ?>"></script>
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

    /* ── Connection + queue badges ───────────────────────── */
    #conn-row {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.75rem;
      margin-top: 2px;
    }
    #conn-badge {
      display: flex;
      align-items: center;
      gap: 4px;
      background: rgba(255,255,255,0.15);
      border-radius: 20px;
      padding: 3px 9px 3px 6px;
      font-weight: 600;
    }
    #conn-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: #4ade80;
      flex-shrink: 0;
    }
    #conn-badge.offline #conn-dot { background: #f87171; }
    #queue-badge {
      display: none;
      background: #f59e0b;
      color: #1c1917;
      border-radius: 20px;
      padding: 3px 9px;
      font-weight: 700;
    }

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
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }
    #camera-selector {
      padding: 6px 10px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #fff;
      touch-action: manipulation;
    }
    #torch-btn {
      display: none;
      padding: 8px 20px;
      font-size: 0.9rem;
      font-weight: 600;
      border: 2px solid #cbd5e1;
      border-radius: 8px;
      background: #fff;
      color: #334155;
      cursor: pointer;
      touch-action: manipulation;
      transition: background 0.15s, border-color 0.15s;
    }
    #torch-btn.on {
      background: #fef9c3;
      border-color: #eab308;
      color: #713f12;
    }

    #qr-scanner {
      flex-shrink: 0;
      width: min(280px, 80vw);
      height: min(280px, 80vw);
      overflow: hidden;
      position: relative;
    }
    #qr-scanner video { width: 100% !important; height: 100% !important; object-fit: cover; display: block; }

    #qr-guide {
      position: absolute;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      width: 78%; height: 42%;
      border: 2px solid rgba(255,255,255,0.9);
      border-radius: 6px;
      box-shadow: 0 0 0 9999px rgba(0,0,0,0.40);
      pointer-events: none;
    }

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
      margin: 8px 0 6px;
      color: #0073aa;
      line-height: 1.2;
    }
    #confirm-method {
      font-size: 0.78rem;
      color: #64748b;
      margin-bottom: 20px;
      min-height: 1em;
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
    <div id="conn-row">
      <div id="conn-badge"><div id="conn-dot"></div><span id="conn-label">Online</span></div>
      <div id="queue-badge"></div>
    </div>
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
      <div>
        <label for="camera-selector">Camera: </label>
        <select id="camera-selector"></select>
      </div>
      <button id="torch-btn">&#x1F526; Torch</button>
    </div>
    <div id="qr-scanner">
      <video id="qr-video" playsinline autoplay muted></video>
      <div id="qr-guide"></div>
    </div>
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
  <div id="confirm-new-member" style="display:none; margin:6px 0 10px; padding:8px 12px; background:#fff7ed; border-radius:8px; border:1px solid #fed7aa; font-size:0.85rem; font-weight:700; color:#92400e;">&#9888; New Member &mdash; needs enrollment, please see a staff member</div>
  <div id="confirm-name"></div>
  <div id="confirm-method"></div>
  <div id="confirm-contact-missing" style="display:none; margin:10px 0 14px; padding:10px 12px; background:#fff7ed; border-radius:10px; border:1px solid #fed7aa; text-align:left;">
    <div id="confirm-contact-label" style="font-size:0.8rem; color:#92400e; font-weight:600; margin-bottom:8px;"></div>
    <div id="confirm-contact-fields"></div>
    <div style="display:flex; gap:8px; margin-top:8px; align-items:center;">
      <button id="confirm-contact-save" style="flex:1; height:38px; font-size:0.9rem; font-weight:700; background:#0073aa; color:#fff; border:none; border-radius:8px; cursor:pointer; touch-action:manipulation;">Save</button>
      <button id="confirm-contact-skip" style="height:38px; font-size:0.85rem; color:#92400e; background:none; border:none; cursor:pointer; touch-action:manipulation; text-decoration:underline;">Skip</button>
    </div>
    <div id="confirm-contact-status" style="font-size:0.78rem; color:#dc2626; margin-top:4px; min-height:1em;"></div>
  </div>
  <div class="confirm-btns">
    <button id="confirm-no">Cancel</button>
    <button id="confirm-yes">Check In</button>
  </div>
</div>

<audio id="snd-ok"  preload="auto"><source src="<?php echo esc_url($plugin_url . 'assets/audio/success.mp3'); ?>" type="audio/mpeg"></audio>
<audio id="snd-err" preload="auto"><source src="<?php echo esc_url($plugin_url . 'assets/audio/error.mp3');   ?>" type="audio/mpeg"></audio>

<script>
(function () {
'use strict';

const AJAX       = <?php echo json_encode($ajax_url); ?>;
const EVENT      = <?php echo json_encode($event_slug); ?>;
const DEF_AREA   = <?php echo json_encode($default_area); ?>;
const HAS_GUESTS = <?php echo $has_guests ? 'true' : 'false'; ?>;
const PLUGIN_URL = <?php echo json_encode($plugin_url); ?>;
const QUEUE_KEY  = 'mmm_checkin_queue_' + EVENT;

const sndOk  = document.getElementById('snd-ok');
const sndErr = document.getElementById('snd-err');

// ── Connection + offline queue ────────────────────────────────────────
var connBadge  = document.getElementById('conn-badge');
var connLabel  = document.getElementById('conn-label');
var queueBadge = document.getElementById('queue-badge');

function updateConnectionBadge() {
  var online = navigator.onLine;
  connBadge.classList.toggle('offline', !online);
  connLabel.textContent = online ? 'Online' : 'Offline';
}

function updateQueueBadge() {
  var q = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
  if (q.length) {
    queueBadge.textContent  = q.length + ' queued';
    queueBadge.style.display = 'block';
  } else {
    queueBadge.style.display = 'none';
  }
}

function queueCheckin(code) {
  var q = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
  if (q.indexOf(code) === -1) q.push(code);
  localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
  updateQueueBadge();
}

function submitCheckin(code) {
  var body = 'action=mmm_checkin&data=' + encodeURIComponent(code) + '&event=' + encodeURIComponent(EVENT);
  return fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
    .then(function (r) { return r.json(); });
}

function flushQueue() {
  var q = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
  if (!q.length) return;
  var remaining = [];
  var promises = q.map(function (code) {
    return submitCheckin(code).then(function (res) {
      // already-checked-in is a success for queue purposes — remove from queue
      if (!res.success && res.data && res.data !== 'Already checked in') {
        remaining.push(code);
      }
    }).catch(function () {
      remaining.push(code); // network error — keep for next flush
    });
  });
  Promise.all(promises).then(function () {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(remaining));
    updateQueueBadge();
  });
}

// Initialise badge state + flush any queue left from a prior offline session
updateConnectionBadge();
updateQueueBadge();
if (navigator.onLine) { flushQueue(); }

window.addEventListener('online',  function () { updateConnectionBadge(); flushQueue(); });
window.addEventListener('offline', updateConnectionBadge);

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

// ── QR / Barcode Scanner (BarcodeDetector — native on Chrome, ZXing-WASM polyfill on Safari) ──
var startBtn     = document.getElementById('start-camera-btn');
var camControls  = document.getElementById('scanner-controls');
var camSelect    = document.getElementById('camera-selector');
var torchBtn     = document.getElementById('torch-btn');
var video        = document.getElementById('qr-video');
var detector     = null;
var qrLocked     = false;
var qrRunning    = false;
var qrStream     = null;
var qrRafId      = null;
var qrDeviceId   = null;
var scanInFlight = false;
var torchOn      = false;
var lastScanAt   = 0;
var SCAN_INTERVAL = 200; // ms between decode attempts

var WANT_FORMATS = ['qr_code','code_128','code_39','ean_13','ean_8','upc_a','upc_e','itf','data_matrix','pdf417'];

// Placeholder; assigned inside the HAS_GUESTS block below
var handleDlScan = null;

function ensureDetector() {
  if (detector) return Promise.resolve(detector);
  if (typeof BarcodeDetector === 'undefined') {
    return Promise.reject(new Error('BarcodeDetector not available'));
  }
  // Point the ZXing-WASM polyfill to our locally-bundled .wasm file (no CDN dependency)
  if (typeof BarcodeDetectionAPI !== 'undefined' && BarcodeDetectionAPI.prepareZXingModule) {
    BarcodeDetectionAPI.prepareZXingModule({
      overrides: {
        locateFile: function (path) {
          return path.endsWith('.wasm') ? PLUGIN_URL + 'assets/js/' + path : path;
        }
      }
    });
  }
  return BarcodeDetector.getSupportedFormats().then(function (supported) {
    var formats = WANT_FORMATS.filter(function (f) { return supported.indexOf(f) > -1; });
    detector = new BarcodeDetector({ formats: formats.length ? formats : ['qr_code'] });
    return detector;
  });
}

function scanLoop() {
  if (!qrRunning) return;
  var now = Date.now();
  if (!scanInFlight && !qrLocked && video.readyState >= 2 && (now - lastScanAt) >= SCAN_INTERVAL) {
    lastScanAt   = now;
    scanInFlight = true;
    detector.detect(video)
      .then(function (codes) { if (codes.length && !qrLocked) handleScan(codes[0].rawValue); })
      .catch(function () {})
      .finally(function () { scanInFlight = false; });
  }
  qrRafId = requestAnimationFrame(scanLoop);
}

function applyTrackEnhancements(track) {
  // Continuous autofocus — keeps focus sharp as DL moves in/out of frame
  track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});

  // Torch: show button only if the device supports it
  var caps = (typeof track.getCapabilities === 'function') ? track.getCapabilities() : {};
  if (caps.torch) {
    torchBtn.style.display = 'block';
  } else {
    torchBtn.style.display = 'none';
    torchOn = false;
    torchBtn.classList.remove('on');
  }
}

function startQr(deviceId) {
  if (qrRunning) return;
  // 1080p gives the decoder more pixels for dense PDF417 barcodes on driver's licences
  var res = { width: { ideal: 1920 }, height: { ideal: 1080 } };
  var constraint = deviceId
    ? { video: Object.assign({ deviceId: { exact: deviceId } }, res) }
    : { video: Object.assign({ facingMode: { ideal: 'environment' } }, res) };
  ensureDetector()
    .then(function () { return navigator.mediaDevices.getUserMedia(constraint); })
    .then(function (stream) {
      qrStream        = stream;
      video.srcObject = stream;
      video.play().catch(function (e) {
        console.warn('video.play() rejected:', e);
      });
      qrRunning            = true;
      startBtn.textContent = '\uD83D\uDCF7 Stop Camera';
      var track = stream.getVideoTracks()[0];
      video.style.transform = (track.label || '').toLowerCase().includes('front') ? 'scaleX(-1)' : '';
      applyTrackEnhancements(track);
      // Build camera selector after permission is granted (labels are now populated)
      if (!qrDeviceId) {
        qrDeviceId = track.getSettings().deviceId || 'default';
        navigator.mediaDevices.enumerateDevices().then(function (devices) {
          var cams = devices.filter(function (d) { return d.kind === 'videoinput'; });
          if (cams.length < 2) return;
          cams.sort(function (a, b) {
            var score = function (l) {
              l = (l || '').toLowerCase();
              return l.includes('front') ? 10 : (l.includes('ultra') || l.includes('0.5')) ? 5 : (l.includes('back') || l.includes('rear')) ? 0 : 3;
            };
            return score(a.label) - score(b.label);
          });
          cams.forEach(function (cam, i) {
            var o = document.createElement('option');
            o.value = cam.deviceId;
            o.text  = cam.label || ('Camera ' + (i + 1));
            if (cam.deviceId === qrDeviceId) o.selected = true;
            camSelect.appendChild(o);
          });
          camControls.style.display = 'block';
          camSelect.addEventListener('change', function () {
            qrDeviceId = this.value;
            if (qrRunning) { stopQr(); startQr(qrDeviceId); }
          });
        });
      }
      qrRafId = requestAnimationFrame(scanLoop);
    })
    .catch(function (e) {
      console.warn('QR start:', e);
      startBtn.textContent = '\uD83D\uDCF7 Start Camera';
      var msg = (e && e.name === 'NotAllowedError')
        ? 'Camera permission denied. Please allow camera access and try again.'
        : 'Camera failed to start: ' + (e && e.message ? e.message : 'unknown error');
      showOverlay('err', msg);
    });
}

torchBtn.addEventListener('click', function () {
  if (!qrStream) return;
  var track = qrStream.getVideoTracks()[0];
  if (!track) return;
  torchOn = !torchOn;
  track.applyConstraints({ advanced: [{ torch: torchOn }] })
    .then(function () {
      torchBtn.classList.toggle('on', torchOn);
      torchBtn.textContent = torchOn ? '\uD83D\uDD26 Torch On' : '\uD83D\uDD26 Torch';
    })
    .catch(function () {
      torchOn = false;
      torchBtn.classList.remove('on');
    });
});

function stopQr() {
  if (!qrRunning) return;
  if (qrRafId)  { cancelAnimationFrame(qrRafId); qrRafId = null; }
  if (qrStream) { qrStream.getTracks().forEach(function (t) { t.stop(); }); qrStream = null; }
  video.srcObject = null;
  qrRunning = false;
  startBtn.textContent = '\uD83D\uDCF7 Start Camera';
  torchOn = false;
  torchBtn.classList.remove('on');
  torchBtn.textContent = '\uD83D\uDD26 Torch';
  torchBtn.style.display = 'none';
}

// ── AAMVA PDF417 helpers ──────────────────────────────────────────────────────

function parseAamva(raw) {
  if (!raw || raw.charCodeAt(0) !== 64 /* '@' */) return null;
  if (raw.indexOf('ANSI ') === -1) return null;
  var fields = {};
  var lines = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
  for (var i = 0; i < lines.length; i++) {
    var line = lines[i];
    if (line.length < 4) continue;
    var key = line.substring(0, 3);
    if (!/^[A-Z]{3}$/.test(key)) continue;
    fields[key] = line.substring(3).trim();
  }
  var lastName  = (fields['DCS'] || '').replace(/,/g, '').trim();
  var firstName = fields['DCT'] || fields['DAC'] || '';
  if (firstName.indexOf(',') !== -1) firstName = firstName.split(',')[0].trim();
  var dob = fields['DBB'] || '';
  if (!lastName) return null;
  return { lastName: lastName, firstName: firstName, dob: dob };
}

function normalizeDobToIso(dob) {
  if (!/^\d{8}$/.test(dob)) return '';
  var mm = dob.substring(0, 2), dd = dob.substring(2, 4), yyyy = dob.substring(4, 8);
  var mi = parseInt(mm, 10), di = parseInt(dd, 10), yi = parseInt(yyyy, 10);
  var now = new Date().getFullYear();
  if (mi < 1 || mi > 12 || di < 1 || di > 31 || yi < 1924 || yi > now - 15) return '';
  return yyyy + '-' + mm + '-' + dd;
}

function sha256hex(str) {
  return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str))
    .then(function(buf) {
      return Array.from(new Uint8Array(buf)).map(function(b) {
        return b.toString(16).padStart(2, '0');
      }).join('');
    });
}

function normalizeName(name) {
  return name.toUpperCase().replace(/[^A-Z\-' ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
}

// ── handleScan — routes AAMVA barcodes to DL flow, others to QR flow ─────────

function handleScan(decoded) {
  if (qrLocked) return;
  qrLocked = true;

  // AAMVA driver's licence — only when a guest list is loaded
  if (HAS_GUESTS && handleDlScan) {
    var dl = parseAamva(decoded);
    if (dl) { handleDlScan(dl); return; }
  }

  if (!navigator.onLine) {
    queueCheckin(decoded);
    showOverlay('ok', 'Queued \u2014 will sync when back online');
    return;
  }
  submitCheckin(decoded)
    .then(function (res) { handleResponse(res); })
    .catch(function () { showOverlay('err', 'Connection error.'); });
}

startBtn.addEventListener('click', function () {
  if (qrRunning) { stopQr(); return; }
  if (qrDeviceId) { startQr(qrDeviceId); return; }
  startQr(null); // first tap — getUserMedia prompts permission; enumeration happens inside startQr
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
  var backdrop     = document.getElementById('confirm-backdrop');
  var confirmOv    = document.getElementById('confirm-overlay');
  var confirmNm    = document.getElementById('confirm-name');
  var confirmMthd  = document.getElementById('confirm-method');
  var confirmYes   = document.getElementById('confirm-yes');
  var confirmNo    = document.getElementById('confirm-no');

  var digits    = '';
  var pending   = null;
  var dlPending = null;

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
    confirmOv.style.borderColor  = '#0073aa';
    confirmNm.style.color        = '#0073aa';
    confirmYes.style.background  = '#16a34a';
    document.getElementById('confirm-new-member').style.display = 'none';
    pending   = null;
    dlPending = null;
  }

  function openConfirm(name, label, missing, memberStatus) {
    var ms = (memberStatus || '').toLowerCase().trim();
    var isNewMember = ms !== 'active' && ms !== 'y';

    document.getElementById('confirm-new-member').style.display = isNewMember ? 'block' : 'none';
    confirmOv.style.borderColor = isNewMember ? '#d97706' : '#0073aa';
    confirmNm.style.color       = isNewMember ? '#92400e' : '#0073aa';
    confirmYes.style.background = isNewMember ? '#d97706' : '#16a34a';

    confirmNm.textContent    = name;
    confirmMthd.textContent  = label || '';

    var contactSection = document.getElementById('confirm-contact-missing');
    var contactLabel   = document.getElementById('confirm-contact-label');
    var contactFields  = document.getElementById('confirm-contact-fields');
    var contactStatus  = document.getElementById('confirm-contact-status');
    contactStatus.textContent = '';

    if (missing && missing.length) {
      var missingLabel = missing.length === 2 ? 'phone & email' : missing[0];
      contactLabel.textContent = '⚠️ No ' + missingLabel + ' on file — add it now?';
      contactFields.innerHTML  = '';
      missing.forEach(function(f) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:8px; margin:4px 0;';
        var lbl = document.createElement('span');
        lbl.style.cssText = 'min-width:48px; font-size:0.82rem; font-weight:700; color:#78350f; text-transform:capitalize;';
        lbl.textContent   = f + ':';
        var inp = document.createElement('input');
        inp.type        = f === 'email' ? 'email' : 'tel';
        inp.id          = 'confirm-contact-' + f;
        inp.placeholder = f === 'email' ? 'email@example.com' : '808-555-1234';
        inp.style.cssText = 'flex:1; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; font-size:0.9rem;';
        row.appendChild(lbl);
        row.appendChild(inp);
        contactFields.appendChild(row);
      });
      contactSection.style.display = 'block';
    } else {
      contactSection.style.display = 'none';
    }

    backdrop.style.display  = 'block';
    confirmOv.style.display = 'block';
  }

  document.getElementById('confirm-contact-skip').addEventListener('click', function() {
    document.getElementById('confirm-contact-missing').style.display = 'none';
  });

  document.getElementById('confirm-contact-save').addEventListener('click', function() {
    var saveBtn    = this;
    var statusEl   = document.getElementById('confirm-contact-status');
    var newPhone   = (document.getElementById('confirm-contact-phone') || {value:''}).value.trim();
    var newEmail   = (document.getElementById('confirm-contact-email') || {value:''}).value.trim();
    if (!newPhone && !newEmail) { statusEl.textContent = 'Please fill in at least one field.'; return; }

    saveBtn.disabled    = true;
    saveBtn.textContent = 'Saving…';
    statusEl.textContent = '';

    var tokenType  = dlPending ? 'dl'    : 'phone';
    var authPhone  = pending   ? pending.phone  : '';
    var authDob    = dlPending ? dlPending.dobHash : '';
    var authToken  = dlPending ? dlPending.token  : (pending ? pending.token : '');
    var authIdx    = dlPending ? dlPending.idx    : (pending ? pending.idx   : -1);

    var body = 'action=mmm_update_guest_contact'
      + '&event='      + encodeURIComponent(EVENT)
      + '&idx='        + encodeURIComponent(authIdx)
      + '&token='      + encodeURIComponent(authToken)
      + '&token_type=' + encodeURIComponent(tokenType)
      + '&phone='      + encodeURIComponent(authPhone)
      + '&dob_hash='   + encodeURIComponent(authDob)
      + '&new_phone='  + encodeURIComponent(newPhone)
      + '&new_email='  + encodeURIComponent(newEmail);

    fetch(AJAX, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        saveBtn.disabled    = false;
        saveBtn.textContent = 'Save';
        if (res.success) {
          document.getElementById('confirm-contact-missing').style.display = 'none';
        } else {
          statusEl.textContent = res.data || 'Error saving.';
        }
      })
      .catch(function() {
        saveBtn.disabled    = false;
        saveBtn.textContent = 'Save';
        statusEl.textContent = 'Connection error.';
      });
  });

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
        openConfirm(m.name, null, m.missing || [], m.member_status || '');
      })
      .catch(function () {
        searchBtn.disabled = !(digits.length === 7 || digits.length >= 10);
        resultEl.textContent = 'Connection error.';
      });
  });

  confirmYes.addEventListener('click', function () {
    // ── DL confirmation ──────────────────────────────────────────────────────
    if (dlPending) {
      var p = dlPending;
      confirmYes.disabled = true;
      confirmNo.disabled  = true;
      var body = 'action=mmm_confirm_dl_checkin'
        + '&event='    + encodeURIComponent(EVENT)
        + '&idx='      + encodeURIComponent(p.idx)
        + '&dob_hash=' + encodeURIComponent(p.dobHash)
        + '&token='    + encodeURIComponent(p.token);
      fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          confirmYes.disabled = false;
          confirmNo.disabled  = false;
          closeConfirm();
          qrLocked = false;
          handleResponse(res);
        })
        .catch(function () {
          confirmYes.disabled = false;
          confirmNo.disabled  = false;
          closeConfirm();
          showOverlay('err', 'Connection error.');
          qrLocked = false;
        });
      return;
    }

    // ── Phone confirmation ───────────────────────────────────────────────────
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

  // ── DL scan handler (assigned here so confirm overlay refs are in scope) ──
  handleDlScan = function(parsed) {
    var lastName  = normalizeName(parsed.lastName);
    var firstName = normalizeName(parsed.firstName);

    if (lastName.length < 2) {
      showOverlay('err', '❌ License unreadable — try phone entry.');
      qrLocked = false;
      return;
    }

    var dobIso = normalizeDobToIso(parsed.dob);
    var hashPromise = dobIso ? sha256hex(dobIso) : Promise.resolve('');

    hashPromise.then(function(dobHash) {
      var body = 'action=mmm_checkin_by_dl'
        + '&event='      + encodeURIComponent(EVENT)
        + '&last_name='  + encodeURIComponent(lastName)
        + '&first_name=' + encodeURIComponent(firstName)
        + '&dob_hash='   + encodeURIComponent(dobHash);
      return fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            showOverlay('err', res.data || '❌ Not found on guest list — try phone entry.');
            qrLocked = false;
            return;
          }
          var matches = Array.isArray(res.data) ? res.data : [res.data];
          var m = matches[0];
          var label = m.tier === 1
            ? 'Matched by DOB + Last Name \u2713'
            : 'Matched by name only \u2014 verify photo ID';
          dlPending = { idx: m.idx, token: m.token, dobHash: m.dob_hash || '' };
          openConfirm(m.name, label, m.missing || [], m.member_status || '');
        });
    }).catch(function() {
      showOverlay('err', '❌ Connection error.');
      qrLocked = false;
    });
  };
}

})();
</script>
</body>
</html>
