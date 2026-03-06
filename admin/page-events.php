<?php
defined('ABSPATH') || exit;

function mmm_render_event_list() {
    $upload_dir = wp_upload_dir();
    $events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
    if (!file_exists($events_dir)) {
        wp_mkdir_p($events_dir);
    }

    // Block direct HTTP access to event JSON files
    $htaccess = trailingslashit($events_dir) . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "<Files \"*.json\">\n  Order Allow,Deny\n  Deny from all\n</Files>\n");
    }

    // Also block directory listing
    $index = trailingslashit($events_dir) . 'index.php';
    if (!file_exists($index)) {
        file_put_contents($index, '<?php // Silence is golden.');
    }

    // Guest CSV upload is handled via AJAX (mmm_preview_guest_csv → mmm_import_guest_csv)

    // Handle event creation
    if (!empty($_POST['new_event_name'])) {
        check_admin_referer('mmm_create_event', 'mmm_create_nonce');
        $event_name = sanitize_text_field($_POST['new_event_name']);
        $slug = sanitize_title_with_dashes($event_name);
        $filename = $slug . '.json';
        $filepath = trailingslashit($events_dir) . $filename;

        // Check for slug collision — two different names that produce the same slug
        if (file_exists($filepath)) {
            $existing = json_decode(file_get_contents($filepath), true);
            $existing_name = $existing['name'] ?? $slug;
            if (strtolower($existing_name) !== strtolower($event_name)) {
                echo '<div class="notice notice-error"><p>🚫 An event with a conflicting name already exists: <strong>' . esc_html($existing_name) . '</strong>. Please choose a different name.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>🚫 Event already exists.</p></div>';
            }
        }

        if (!file_exists($filepath)) {
            $event_data = [
                'name' => $event_name,
                'created_at' => current_time('mysql'),
                'checkins' => []
            ];
            file_put_contents($filepath, json_encode($event_data), LOCK_EX);
            echo '<div class="notice notice-success"><p>✅ Event created: <strong>' . esc_html($event_name) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error"><p>🚫 Event already exists.</p></div>';
        }
    }

    // Handle CSV export
    if (!empty($_POST['event_name']) && isset($_POST['export_csv'])) {
        check_admin_referer('mmm_export_event', 'mmm_export_nonce');
        $event_name = sanitize_text_field($_POST['event_name']);
        $filename = sanitize_title_with_dashes($event_name) . '.json';
        $filepath = trailingslashit($events_dir) . $filename;

        if (file_exists($filepath)) {
            ob_clean();
            flush();

            $event_data = json_decode(file_get_contents($filepath), true);
            $checkins = $event_data['checkins'] ?? [];

            $csv_filename = sanitize_file_name("{$event_data['name']} - " . date('F-j-Y') . ".csv");

            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=\"$csv_filename\"");
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'First Name', 'Last Name', 'Bargaining Unit', 'Unit', 'Employer',
                'Jurisdiction', 'Job Title', 'Baseyard', 'Island', 'Member Status',
                'AFSCME ID', 'Phone', 'Check-In Method', 'Checked in Time'
            ]);

            foreach ($checkins as $entry) {
                fputcsv($output, [
                    $entry['first_name'] ?? '',
                    $entry['last_name'] ?? '',
                    $entry['bargaining_unit'] ?? '',
                    $entry['unit_number'] ?? '',
                    $entry['employer'] ?? '',
                    $entry['jurisdiction'] ?? '',
                    $entry['job_title'] ?? '',
                    $entry['baseyard'] ?? '',
                    $entry['island'] ?? '',
                    $entry['member_status'] ?? '',
                    $entry['afscme_id'] ?? '',
                    $entry['phone'] ?? '',
                    $entry['method'] ?? 'qr',
                    $entry['time'] ?? '',
                ]);
            }

            fclose($output);
            exit;
        }
    }

    // Handle event deletion
    if (!empty($_POST['delete_event'])) {
        check_admin_referer('mmm_delete_event', 'mmm_delete_nonce');
        $filename = sanitize_title_with_dashes($_POST['delete_event']) . '.json';
        $filepath = trailingslashit($events_dir) . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
            echo '<div class="notice notice-success"><p>🗑️ Event deleted: <strong>' . esc_html($_POST['delete_event']) . '</strong></p></div>';
        }
    }

    ?>
    <h2>Create New Event</h2>
    <form method="POST" style="margin-bottom: 20px;">
        <?php wp_nonce_field('mmm_create_event', 'mmm_create_nonce'); ?>
        <input type="text" name="new_event_name" required placeholder="Event Name" style="width: 300px;" />
        <button type="submit" class="button button-primary">Create Event</button>
    </form>
    <?php
    $event_files = glob($events_dir . '/*.json');
    if (!empty($event_files)): ?>
        <h2>Event List</h2>
        <table class="widefat">
            <thead>
                <tr><th>Event Name</th><th>Created</th><th>Guests</th><th>Export</th><th>Launch Scanner</th><th>Delete</th></tr>
            </thead>
            <tbody>
            <?php foreach ($event_files as $filepath):
                $event_data = json_decode(file_get_contents($filepath), true);
                if (!$event_data || empty($event_data['name']) || empty($event_data['created_at'])) continue;
                $slug = sanitize_title_with_dashes($event_data['name']);
                $scanner_url = site_url("/event-check-in/?event=$slug");
            ?>
                <tr>
                    <td><?= esc_html($event_data['name']); ?></td>
                    <td><?= date('F j, Y', strtotime($event_data['created_at'])); ?></td>
                    <td>
                        <?php
                        $guest_count = count( $event_data['guests'] ?? [] );
                        if ( $guest_count > 0 ) {
                            echo '<small>' . esc_html( $guest_count ) . ' guests</small><br>';
                        }
                        ?>
                        <form method="POST" enctype="multipart/form-data" class="mmm-guest-upload-form" style="margin-top:4px;">
                            <?php wp_nonce_field( 'mmm_upload_guests', 'mmm_guests_nonce' ); ?>
                            <input type="hidden" name="guest_event_name" value="<?= esc_attr( $event_data['name'] ); ?>">
                            <input type="file" name="guest_csv" accept=".csv" required style="font-size:0.8rem; max-width:140px;">
                            <button type="submit" class="button" style="margin-top:4px;">Upload Guests</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST">
                            <?php wp_nonce_field('mmm_export_event', 'mmm_export_nonce'); ?>
                            <input type="hidden" name="event_name" value="<?= esc_attr($event_data['name']); ?>">
                            <input type="hidden" name="export_csv" value="1" />
                            <button type="submit" class="button">Export Check-ins</button>
                        </form>
                    </td>
                    <td>
                        <a href="<?= esc_url($scanner_url); ?>" target="_blank" class="button button-secondary">Launch Scanner</a>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this event?');">
                            <?php wp_nonce_field('mmm_delete_event', 'mmm_delete_nonce'); ?>
                            <input type="hidden" name="delete_event" value="<?= esc_attr($event_data['name']); ?>">
                            <button type="submit" class="button" style="color: red; font-weight: bold;">❌</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No events found.</p>
    <?php endif; ?>

    <script>
    (function () {
      function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }
      function setStatus(el, type, msg) {
        el.style.color   = type === 'ok' ? 'green' : 'red';
        el.style.fontWeight = 'bold';
        el.textContent   = (type === 'ok' ? '\u2705 ' : '\u274C ') + msg;
      }
      function headerOptions(headers, selected) {
        return headers.map(function (h) {
          return '<option value="' + esc(h) + '"' + (h === selected ? ' selected' : '') + '>' + esc(h) + '</option>';
        }).join('');
      }

      document.querySelectorAll('.mmm-guest-upload-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var btn       = form.querySelector('button[type="submit"]');
          var eventName = form.querySelector('[name="guest_event_name"]').value;
          var nonce     = form.querySelector('[name="mmm_guests_nonce"]').value;
          var statusEl  = form.querySelector('.upload-status');
          if (!statusEl) {
            statusEl = document.createElement('p');
            statusEl.className = 'upload-status';
            form.appendChild(statusEl);
          }

          btn.disabled    = true;
          btn.textContent = 'Reading file\u2026';
          statusEl.textContent = '';

          // Step 1: upload file, get headers back
          var fd = new FormData(form);
          fd.append('action', 'mmm_preview_guest_csv');
          fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (!res.success) {
                btn.disabled    = false;
                btn.textContent = 'Upload Guests';
                setStatus(statusEl, 'err', res.data);
                return;
              }
              showMappingUI(form, res.data, eventName, nonce);
            })
            .catch(function () {
              btn.disabled    = false;
              btn.textContent = 'Upload Guests';
              setStatus(statusEl, 'err', 'Connection error. Try again.');
            });
        });
      });

      function showMappingUI(form, data, eventName, nonce) {
        form.innerHTML =
          '<p style="margin:0 0 8px">' +
            '<strong>' + esc(data.filename) + '</strong> &mdash; ' + data.row_count + ' rows detected.' +
          '</p>' +
          '<table style="border-collapse:collapse; width:100%; margin-bottom:8px">' +
            '<tr>' +
              '<td style="padding:5px 10px 5px 0; white-space:nowrap; font-weight:600">QR Code ID column:</td>' +
              '<td style="padding:5px 0"><select id="mmm-qr-col" style="width:100%; padding:3px 6px">' +
                headerOptions(data.headers, data.qr_guess) +
              '</select></td>' +
            '</tr>' +
            '<tr>' +
              '<td style="padding:5px 10px 5px 0; white-space:nowrap; font-weight:600">Phone Number column:</td>' +
              '<td style="padding:5px 0"><select id="mmm-phone-col" style="width:100%; padding:3px 6px">' +
                headerOptions(data.headers, data.phone_guess) +
              '</select></td>' +
            '</tr>' +
          '</table>' +
          '<p style="font-size:0.82em; color:#666; margin:0 0 10px">Rows missing both fields will be skipped.</p>' +
          '<button id="mmm-do-import" class="button button-primary">Import</button>' +
          '&nbsp;<button id="mmm-cancel-import" class="button">Cancel</button>' +
          '<p class="upload-status" style="margin:8px 0 0"></p>';

        form.querySelector('#mmm-cancel-import').addEventListener('click', function () {
          location.reload();
        });

        form.querySelector('#mmm-do-import').addEventListener('click', function () {
          var importBtn = this;
          var statusEl  = form.querySelector('.upload-status');
          importBtn.disabled    = true;
          importBtn.textContent = 'Importing\u2026';

          // Step 2: send column mapping + temp key
          var fd = new FormData();
          fd.append('action',           'mmm_import_guest_csv');
          fd.append('mmm_guests_nonce', nonce);
          fd.append('guest_event_name', eventName);
          fd.append('temp_key',         data.temp_key);
          fd.append('qr_col',           form.querySelector('#mmm-qr-col').value);
          fd.append('phone_col',        form.querySelector('#mmm-phone-col').value);

          fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res.success) {
                setStatus(statusEl, 'ok', res.data);
                importBtn.style.display = 'none';
                form.querySelector('#mmm-cancel-import').textContent = 'Close';
              } else {
                importBtn.disabled    = false;
                importBtn.textContent = 'Import';
                setStatus(statusEl, 'err', res.data);
              }
            })
            .catch(function () {
              importBtn.disabled    = false;
              importBtn.textContent = 'Import';
              setStatus(statusEl, 'err', 'Connection error. Try again.');
            });
        });
      }
    })();
    </script>
<?php
}
