console.log("✅ MMM QR Scanner JS loaded from: " + mmm_qr_ajax.ajaxurl);

document.addEventListener("DOMContentLoaded", function () {
  const scannerContainer = document.getElementById("qr-scanner");
  const resultContainer = document.getElementById("checkin-result");
  const cameraSelect = document.getElementById("camera-selector");

  if (!scannerContainer || !mmm_qr_ajax.current_event) return;

  const qrScanner = new Html5Qrcode("qr-scanner");
  let activeCameraId = null;
  let lastScannedText = null;
  let lastScanTime = 0;
  let scanLock = false;

    function handleScan(decodedText) {
      fetch(mmm_qr_ajax.ajaxurl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'mmm_checkin',
          data: btoa(decodedText),
        }),
      })
      .then(res => res.json())
      .then(response => {
        const overlay = document.getElementById('overlay-message');
        const successSound = document.getElementById('success-audio');
        const errorSound = document.getElementById('error-audio');

        if (response.success) {
          overlay.style.color = 'green';
          overlay.innerText = '✅ ' + response.data;
          successSound.play();
        } else {
          overlay.style.color = 'red';
          overlay.innerText = '❌ ' + response.data;
          errorSound.play();
        }

        overlay.style.display = 'block';

        // Wait, then reset the overlay and scanner
        setTimeout(() => {
          overlay.style.display = 'none';
          qrScanner.resume(); // if scanner is paused
        }, 4000);
      });
    }

    function onScanSuccess(decodedText, decodedResult) {
      if (scanLock) return;
      scanLock = true;

      qrScanner.pause();
      handleScan(decodedText);

      setTimeout(() => {
        scanLock = false;
      }, 4000);
    }


  function startCamera(cameraId) {
    activeCameraId = cameraId;
    qrScanner.start(cameraId, { fps: 10, qrbox: 250 }, onScanSuccess, console.warn);
  }

  Html5Qrcode.getCameras().then((devices) => {
    if (!devices.length) {
      resultContainer.innerText = "🚫 No cameras found.";
      return;
    }

    devices.forEach((cam, i) => {
      const option = document.createElement("option");
      option.value = cam.id;
      option.text = cam.label || `Camera ${i + 1}`;
      cameraSelect.appendChild(option);
    });

    cameraSelect.addEventListener("change", function () {
      if (qrScanner.getState() === Html5QrcodeScannerState.SCANNING) {
        qrScanner.stop().then(() => startCamera(this.value));
      } else {
        startCamera(this.value);
      }
    });

    startCamera(devices[0].id);
  });
});
