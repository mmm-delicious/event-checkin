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
    echo '<div style="text-align:center; padding: 100px; font-size: 24px;">🚫 Invalid or missing event.</div>';
    get_footer();
    return;
}

$event_name = esc_html($event_data['name']);
$event_date = date('l, F j, Y', strtotime($event_data['created_at']));
?>

<style>
  body {
    background: white;
    font-family: sans-serif;
    text-align: center;
  }
    #qr-scanner {
    margin: 30px auto;
    width: 300px;
    height: 300px;
    }
    #overlay-message {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 9999;
      padding: 30px 40px;
      border-radius: 16px;
      font-size: 2rem;
      font-weight: 600;
      background: #fff;
      color: #222;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
      border: 3px solid;
      text-align: center;
      min-width: 300px;
    }
    #camera-selector {
      width: auto;
      font-size: 0.9rem;
      padding: 4px 8px;
      border-radius: 4px;
      border: 1px solid #ccc;
      background: #fff;
    }
    #scanner-controls {
      margin-bottom: 15px;
      display: inline-block;
    }
</style>

<div style="padding-top: 50px">
<img src="<?php echo esc_url(get_site_icon_url(128)); ?>" alt="Site Logo"
     style="height: 80px; width: auto; max-width: 100%; object-fit: contain;" />
  <h1><?php echo $event_name; ?></h1>
  <h3><?php echo $event_date; ?></h3>

  <div id="scanner-controls" style="margin-bottom: 10px;">
    <label for="camera-selector">Select Camera:</label>
    <select id="camera-selector"></select>
  </div>

  <div id="qr-scanner"></div>
  <div id="checkin-result" style="margin-top: 15px;"></div>

  <audio id="success-sound" preload="auto">
    <source src="<?php echo plugin_dir_url(__FILE__) . 'assets/audio/success.mp3'; ?>" type="audio/mpeg">
  </audio>
  <audio id="error-sound" preload="auto">
    <source src="<?php echo plugin_dir_url(__FILE__) . 'assets/audio/error.mp3'; ?>" type="audio/mpeg">
  </audio>
</div>
<div id="overlay-message"></div>


<script src="https://unpkg.com/html5-qrcode"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const scanner = document.getElementById("qr-scanner");
  const resultBox = document.getElementById("checkin-result");
  const cameraSelect = document.getElementById("camera-selector");
  const successSound = document.getElementById("success-sound");
  const errorSound = document.getElementById("error-sound");

  const qr = new Html5Qrcode("qr-scanner");
  let locked = false;

  function displayMessage(success, text) {
    const overlay = document.getElementById("overlay-message");
    overlay.textContent = text;
    overlay.style.borderColor = success ? "#28a745" : "#dc3545";  // Bootstrap-style colors
    overlay.style.color = success ? "#28a745" : "#dc3545";
    overlay.style.display = "block";
    (success ? successSound : errorSound).play();
      setTimeout(() => {
        overlay.style.display = "none";
        locked = false;
      }, 5000);

  }

function handleScan(decoded) {
  if (locked) return;
  locked = true;

  fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=mmm_checkin&data=" + encodeURIComponent(decoded) + "&event=<?php echo urlencode($event_slug); ?>",
  })
  .then(res => res.json())
  .then(response => {
    displayMessage(response.success, response.data);
  })
  .catch(err => {
    console.warn("AJAX error:", err);
    displayMessage(false, "❌ Event not found or not created properly.");
  });
}

  Html5Qrcode.getCameras().then(devices => {
    if (!devices.length) return;
    devices.forEach((cam, i) => {
      const opt = document.createElement("option");
      opt.value = cam.id;
      opt.text = cam.label || `Camera ${i + 1}`;
      cameraSelect.appendChild(opt);
    });

    cameraSelect.addEventListener("change", function () {
      qr.stop().then(() => qr.start(this.value, { fps: 10, qrbox: 250 }, handleScan));
    });

    qr.start(devices[0].id, { fps: 10, qrbox: 250 }, handleScan);
  });
});
</script>

<?php get_footer(); ?>
