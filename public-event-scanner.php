<?php
/*
Template Name: Public Event Scanner
*/

get_header();
$event_slug = sanitize_title_with_dashes($_GET['event'] ?? '');
$upload_dir = wp_upload_dir();
$events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
$filepath = trailingslashit($events_dir) . $event_slug . '.json';

$event_data = file_exists($filepath) ? json_decode(file_get_contents($filepath), true) : null;

if (!$event_data) {
    echo '<div style="text-align:center; padding: 100px; font-size: 24px;">Invalid or missing event.</div>';
    get_footer();
    return;
}

$event_name = esc_html($event_data['name']);
$event_date = date('l, F j, Y', strtotime($event_data['created_at']));
$has_guests = !empty($event_data['guests']);
?>

<style>
  * { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
  body { background: #f0f0f0; font-family: sans-serif; text-align: center; margin: 0; }

  .mmm-scanner-wrap { padding: 24px 16px 60px; max-width: 600px; margin: 0 auto; }
  .mmm-logo { height: 70px; width: auto; max-width: 100%; object-fit: contain; }
  .mmm-event-title { font-size: 1.5rem; margin: 10px 0 2px; }
  .mmm-event-date  { font-size: 0.95rem; color: #666; margin: 0 0 18px; font-weight: normal; }

  /* QR scanner */
  #qr-scanner { margin: 0 auto 16px; width: 300px; height: 300px; overflow: hidden; position: relative; }
  #qr-scanner video { width: 100% !important; height: 100% !important; object-fit: cover; }
  #camera-selector {
    font-size: 0.95rem;
    padding: 6px 10px;
    border-radius: 6px;
    border: 1px solid #ccc;
    background: #fff;
    touch-action: manipulation;
  }
  #scanner-controls { margin-bottom: 16px; }

  #start-camera-btn {
    display: block;
    margin: 0 auto 16px;
    padding: 14px 32px;
    min-height: 52px;
    font-size: 1.1rem;
    font-weight: 700;
    background: #0073aa;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    touch-action: manipulation;
    transition: opacity 0.15s;
  }
  #start-camera-btn:active { opacity: 0.8; }

  /* Section divider */
  .mmm-section-divider {
    border: none;
    border-top: 2px solid #ddd;
    margin: 24px 0 20px;
  }
  .mmm-section-heading {
    font-size: 1rem;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 16px;
  }

  /* Mode tabs */
  .mmm-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
  .mmm-tab {
    flex: 1;
    padding: 14px;
    font-size: 1rem;
    font-weight: 700;
    background: #e0e0e0;
    color: #555;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    touch-action: manipulation;
    transition: background 0.15s, color 0.15s;
  }
  .mmm-tab.active { background: #0073aa; color: #fff; }
  .mmm-tab:active { opacity: 0.8; }

  /* Phone dialpad */
  .phone-search-wrap { margin: 8px auto 0; max-width: 360px; padding: 0 8px; }

  #phone-display {
    display: block;
    width: 100%;
    font-size: 2.2rem;
    font-weight: 700;
    text-align: center;
    letter-spacing: 4px;
    padding: 14px 12px;
    background: #fff;
    border: 2px solid #ccc;
    border-radius: 10px;
    color: #222;
    min-height: 66px;
    line-height: 1;
  }
  #phone-display.has-value { border-color: #0073aa; color: #0073aa; }
  #phone-display.placeholder { color: #aaa; font-size: 1.3rem; font-weight: 400; letter-spacing: 1px; }

  /* Dialpad grid */
  .dialpad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 12px 0 0;
  }
  .dialpad-key {
    background: #fff;
    border: none;
    border-radius: 10px;
    padding: 0;
    height: 72px;
    font-size: 1.6rem;
    font-weight: 700;
    color: #222;
    cursor: pointer;
    touch-action: manipulation;
    box-shadow: 0 2px 4px rgba(0,0,0,0.12);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: background 0.1s;
    user-select: none;
  }
  .dialpad-key:active { background: #e0e0e0; }
  .dialpad-key .sub { font-size: 0.55rem; font-weight: 400; letter-spacing: 1px; color: #888; margin-top: 1px; }
  .dialpad-key.key-backspace { background: #f5f5f5; font-size: 1.4rem; color: #555; }
  .dialpad-key.key-backspace:active { background: #e0e0e0; }

  #phone-search-btn {
    display: block;
    margin-top: 12px;
    width: 100%;
    padding: 18px;
    min-height: 60px;
    font-size: 1.2rem;
    font-weight: 700;
    background: #0073aa;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    touch-action: manipulation;
    transition: opacity 0.15s;
  }
  #phone-search-btn:disabled { opacity: 0.35; cursor: default; }
  #phone-result { margin-top: 12px; font-size: 1rem; color: #dc3545; min-height: 22px; }

  /* Success / error overlay */
  #overlay-message {
    display: none;
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 10000;
    padding: 32px 40px;
    border-radius: 18px;
    font-size: 1.8rem;
    font-weight: 700;
    background: #fff;
    box-shadow: 0 10px 40px rgba(0,0,0,0.25);
    border: 4px solid;
    text-align: center;
    width: 90vw;
    max-width: 460px;
  }

  /* Confirm overlay */
  #confirm-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 9998;
  }
  #confirm-overlay {
    display: none;
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
    padding: 32px 24px 24px;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    border: 3px solid #0073aa;
    text-align: center;
    width: 90vw;
    max-width: 400px;
  }
  #confirm-overlay h2 { margin: 0 0 4px; font-size: 1rem; color: #777; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
  #confirm-name { font-size: 2rem; font-weight: 800; margin: 8px 0 24px; color: #0073aa; line-height: 1.2; }
  .confirm-btns { display: flex; gap: 12px; }
  .confirm-btns button {
    flex: 1;
    padding: 18px 12px;
    min-height: 64px;
    font-size: 1.1rem;
    font-weight: 700;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    touch-action: manipulation;
  }
  .confirm-btns button:disabled { opacity: 0.5; }
  #confirm-yes { background: #28a745; color: #fff; font-size: 1.2rem; }
  #confirm-no  { background: #e0e0e0; color: #333; }
</style>

<div class="mmm-scanner-wrap">
  <img src="<?php echo esc_url(get_site_icon_url(128)); ?>" alt="Site Logo" class="mmm-logo" />
  <h1 class="mmm-event-title"><?php echo $event_name; ?></h1>
  <h3 class="mmm-event-date"><?php echo $event_date; ?></h3>

  <?php if ($has_guests): ?>
  <div class="mmm-tabs">
    <button class="mmm-tab active" data-tab="qr">&#x1F4F7; QR Scanner</button>
    <button class="mmm-tab" data-tab="phone">&#x260E; Phone</button>
  </div>
  <?php endif; ?>

  <!-- QR Scanner -->
  <div id="tab-qr">
    <button id="start-camera-btn">&#x1F4F7; Start Camera</button>
    <div id="scanner-controls" style="display:none;">
      <label for="camera-selector">Camera:</label>
      <select id="camera-selector"></select>
    </div>
    <div id="qr-scanner"></div>
  </div>

  <?php if ($has_guests): ?>
  <!-- Phone Check-In -->
  <div id="tab-phone" style="display:none;">
  <div class="phone-search-wrap">

      <div id="phone-display" class="placeholder">Enter phone number</div>

      <div class="dialpad">
        <button class="dialpad-key" data-digit="1">1<span class="sub">&nbsp;</span></button>
        <button class="dialpad-key" data-digit="2">2<span class="sub">ABC</span></button>
        <button class="dialpad-key" data-digit="3">3<span class="sub">DEF</span></button>
        <button class="dialpad-key" data-digit="4">4<span class="sub">GHI</span></button>
        <button class="dialpad-key" data-digit="5">5<span class="sub">JKL</span></button>
        <button class="dialpad-key" data-digit="6">6<span class="sub">MNO</span></button>
        <button class="dialpad-key" data-digit="7">7<span class="sub">PQRS</span></button>
        <button class="dialpad-key" data-digit="8">8<span class="sub">TUV</span></button>
        <button class="dialpad-key" data-digit="9">9<span class="sub">WXYZ</span></button>
        <button class="dialpad-key key-backspace" id="dialpad-back">&#9003;</button>
        <button class="dialpad-key" data-digit="0">0<span class="sub">+</span></button>
        <button class="dialpad-key" style="visibility:hidden;"></button>
      </div>

      <button id="phone-search-btn" disabled>Search</button>
      <div id="phone-result"></div>
    </div>
  </div>
  <?php endif; ?>

  <audio id="success-sound" preload="auto">
    <source src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/audio/success.mp3' ); ?>" type="audio/mpeg">
  </audio>
  <audio id="error-sound" preload="auto">
    <source src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/audio/error.mp3' ); ?>" type="audio/mpeg">
  </audio>
</div>

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

<script src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/js/html5-qrcode.min.js' ); ?>"></script>
<script>
(function () {
  'use strict';

  const AJAX_URL        = <?php echo json_encode( admin_url('admin-ajax.php') ); ?>;
  const EVENT_SLUG      = <?php echo json_encode( $event_slug ); ?>;
  const DEFAULT_AREA    = <?php echo json_encode( preg_replace('/\D/', '', get_option('mmm_default_area_code', '808')) ); ?>;

  const successSound = document.getElementById('success-sound');
  const errorSound   = document.getElementById('error-sound');

  // ── Result overlay ───────────────────────────────────────────────
  function showOverlay(success, text) {
    const el = document.getElementById('overlay-message');
    el.textContent      = text;
    el.style.borderColor = success ? '#28a745' : '#dc3545';
    el.style.color       = success ? '#28a745' : '#dc3545';
    el.style.display     = 'block';
    try { (success ? successSound : errorSound).play(); } catch(e) {}
    setTimeout(() => { el.style.display = 'none'; qrLocked = false; }, 5000);
  }

  // ── QR Scanner ───────────────────────────────────────────────────
  const cameraSelect  = document.getElementById('camera-selector');
  const scannerControls = document.getElementById('scanner-controls');
  const startCameraBtn  = document.getElementById('start-camera-btn');
  const qr            = new Html5Qrcode('qr-scanner');
  let qrLocked   = false;
  let qrRunning  = false;
  let qrDeviceId = null;

  function startQr(deviceId) {
    if (qrRunning) return;
    qr.start(deviceId, { fps: 10, qrbox: 250 }, handleScan)
      .then(() => {
        qrRunning = true;
        startCameraBtn.textContent = '\uD83D\uDCF7 Stop Camera';
        // Mirror front camera; ensure back camera is not mirrored
        const selOpt = cameraSelect.options[cameraSelect.selectedIndex];
        const label  = selOpt ? selOpt.text.toLowerCase() : '';
        const video  = document.querySelector('#qr-scanner video');
        if (video) video.style.transform = label.includes('front') ? 'scaleX(-1)' : 'scaleX(1)';
      })
      .catch(err => {
        console.warn('QR start:', err);
        startCameraBtn.textContent = '\uD83D\uDCF7 Start Camera';
      });
  }

  function stopQr() {
    if (!qrRunning) return;
    qr.stop()
      .then(() => {
        qrRunning = false;
        startCameraBtn.textContent = '\uD83D\uDCF7 Start Camera';
      })
      .catch(err => console.warn('QR stop:', err));
  }

  function handleScan(decoded) {
    if (qrLocked) return;
    qrLocked = true;
    const body = 'action=mmm_checkin&data=' + encodeURIComponent(decoded) + '&event=' + encodeURIComponent(EVENT_SLUG);
    fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .then(r => r.json())
      .then(res => showOverlay(res.success, res.data))
      .catch(() => showOverlay(false, 'Connection error.'));
  }

  startCameraBtn.addEventListener('click', function () {
    if (qrRunning) {
      stopQr();
      return;
    }
    if (qrDeviceId) {
      startQr(qrDeviceId);
      return;
    }
    // First time — enumerate cameras (requires user gesture on iOS)
    Html5Qrcode.getCameras().then(devices => {
      if (!devices.length) {
        startCameraBtn.textContent = 'No camera found';
        return;
      }
      // Prefer standard back camera; deprioritise ultra-wide and front cameras
      devices.sort((a, b) => {
        const score = l => {
          l = (l || '').toLowerCase();
          if (l.includes('front'))                       return 10;
          if (l.includes('ultra') || l.includes('0.5')) return 5;
          if (l.includes('back') || l.includes('rear')) return 0;
          return 3;
        };
        return score(a.label) - score(b.label);
      });
      devices.forEach((cam, i) => {
        const opt = document.createElement('option');
        opt.value = cam.id;
        opt.text  = cam.label || ('Camera ' + (i + 1));
        cameraSelect.appendChild(opt);
      });
      if (devices.length > 1) {
        scannerControls.style.display = 'block';
      }
      qrDeviceId = devices[0].id;
      cameraSelect.addEventListener('change', function () {
        qrDeviceId = this.value;
        if (qrRunning) {
          qr.stop().then(() => { qrRunning = false; startQr(qrDeviceId); });
        }
      });
      startQr(qrDeviceId);
    }).catch(err => {
      console.warn('Camera access:', err);
      startCameraBtn.textContent = 'Camera access denied';
    });
  });

  <?php if ($has_guests): ?>
  // ── Tab toggle ───────────────────────────────────────────────────
  document.querySelectorAll('.mmm-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.mmm-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      const show = tab.dataset.tab;
      document.getElementById('tab-qr').style.display    = show === 'qr'    ? '' : 'none';
      document.getElementById('tab-phone').style.display = show === 'phone' ? '' : 'none';
      if (show !== 'qr' && qrRunning) stopQr();
    });
  });

  // ── Dialpad ──────────────────────────────────────────────────────
  const phoneDisplay   = document.getElementById('phone-display');
  const phoneSearchBtn = document.getElementById('phone-search-btn');
  const phoneResult    = document.getElementById('phone-result');
  const confirmBackdrop = document.getElementById('confirm-backdrop');
  const confirmOverlay = document.getElementById('confirm-overlay');
  const confirmName    = document.getElementById('confirm-name');
  const confirmYes     = document.getElementById('confirm-yes');
  const confirmNo      = document.getElementById('confirm-no');

  let rawDigits = '';
  let pendingConfirm = null;

  function formatPhone(digits) {
    if (digits.length > 6)
      return digits.slice(0,3) + '-' + digits.slice(3,6) + '-' + digits.slice(6);
    if (digits.length > 3)
      return digits.slice(0,3) + '-' + digits.slice(3);
    return digits;
  }

  function updateDisplay() {
    if (rawDigits.length === 0) {
      phoneDisplay.textContent = 'Enter phone number';
      phoneDisplay.className   = 'placeholder';
      phoneSearchBtn.textContent = 'Search';
    } else {
      phoneDisplay.textContent = formatPhone(rawDigits);
      phoneDisplay.className   = 'has-value';
      if (rawDigits.length === 7) {
        phoneSearchBtn.textContent = 'Search (' + DEFAULT_AREA + ')';
      } else {
        phoneSearchBtn.textContent = 'Search';
      }
    }
    var canSearch = rawDigits.length === 7 || rawDigits.length >= 10;
    phoneSearchBtn.disabled = !canSearch;
  }

  document.querySelectorAll('.dialpad-key[data-digit]').forEach(key => {
    key.addEventListener('click', function () {
      if (rawDigits.length >= 10) return;
      rawDigits += this.dataset.digit;
      phoneResult.textContent = '';
      updateDisplay();
    });
  });

  document.getElementById('dialpad-back').addEventListener('click', function () {
    if (rawDigits.length === 0) return;
    rawDigits = rawDigits.slice(0, -1);
    phoneResult.textContent = '';
    updateDisplay();
  });

  function closeConfirm() {
    confirmBackdrop.style.display = 'none';
    confirmOverlay.style.display  = 'none';
    pendingConfirm = null;
  }

  function openConfirm(name) {
    confirmName.textContent       = name;
    confirmBackdrop.style.display = 'block';
    confirmOverlay.style.display  = 'block';
  }

  confirmBackdrop.addEventListener('click', closeConfirm);
  confirmNo.addEventListener('click', closeConfirm);

  phoneSearchBtn.addEventListener('click', function () {
    phoneResult.textContent  = '';
    phoneSearchBtn.disabled  = true;
    const formattedPhone = formatPhone(rawDigits);

    const body = 'action=mmm_search_by_phone'
      + '&phone=' + encodeURIComponent(formattedPhone)
      + '&event=' + encodeURIComponent(EVENT_SLUG);

    fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .then(r => r.json())
      .then(res => {
        phoneSearchBtn.disabled = rawDigits.length < 10;
        if (!res.success) {
          phoneResult.textContent = res.data || 'No match found.';
          try { errorSound.play(); } catch(e) {}
          return;
        }
        const match = res.data[0];
        pendingConfirm = { idx: match.idx, token: match.token, phone: match.full_phone };
        openConfirm(match.name);
      })
      .catch(() => {
        phoneSearchBtn.disabled = rawDigits.length < 10;
        phoneResult.textContent = 'Connection error.';
      });
  });

  confirmYes.addEventListener('click', function () {
    if (!pendingConfirm) return;
    confirmYes.disabled = true;
    confirmNo.disabled  = true;

    const { idx, token, phone } = pendingConfirm;
    const body = 'action=mmm_checkin_by_phone'
      + '&event='  + encodeURIComponent(EVENT_SLUG)
      + '&idx='    + encodeURIComponent(idx)
      + '&phone='  + encodeURIComponent(phone)
      + '&token='  + encodeURIComponent(token);

    fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .then(r => r.json())
      .then(res => {
        confirmYes.disabled = false;
        confirmNo.disabled  = false;
        closeConfirm();
        rawDigits = '';
        updateDisplay();
        phoneResult.textContent = '';
        showOverlay(res.success, res.data);
      })
      .catch(() => {
        confirmYes.disabled = false;
        confirmNo.disabled  = false;
        closeConfirm();
        showOverlay(false, 'Connection error.');
      });
  });
  <?php endif; ?>

})();
</script>

<?php get_footer(); ?>
