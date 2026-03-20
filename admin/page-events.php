<?php
defined('ABSPATH') || exit;

/**
 * Sanitize a cell value for CSV export to prevent formula injection.
 * Prefixes cells beginning with =, +, -, @, |, % with a single quote.
 */
function mmm_csv_escape( $value ) {
    $value = (string) $value;
    if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@', '|', '%' ], true ) ) {
        return "'" . $value;
    }
    return $value;
}

function mmm_render_event_list() {
    $events_dir = mmm_events_dir();
    if ( ! file_exists( $events_dir ) ) {
        wp_mkdir_p( $events_dir );
    }

    // Block direct HTTP access to event JSON files (.htaccess for Apache)
    $htaccess = trailingslashit( $events_dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "<Files \"*.json\">\n  Order Allow,Deny\n  Deny from all\n</Files>\n" );
    }
    $index = trailingslashit( $events_dir ) . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, '<?php // Silence is golden.' );
    }

    // Handle settings save
    if ( isset( $_POST['mmm_save_settings'] ) ) {
        check_admin_referer( 'mmm_settings', 'mmm_settings_nonce' );
        $area_code = preg_replace( '/\D/', '', $_POST['mmm_default_area_code'] ?? '808' );
        $area_code = substr( $area_code, 0, 3 );
        if ( strlen( $area_code ) === 3 ) {
            update_option( 'mmm_default_area_code', $area_code );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Invalid area code — must be 3 digits.</p></div>';
        }
    }

    // Handle event creation — creates all 3 split files
    if ( ! empty( $_POST['new_event_name'] ) ) {
        check_admin_referer( 'mmm_create_event', 'mmm_create_nonce' );
        $event_name = sanitize_text_field( $_POST['new_event_name'] );
        $slug       = sanitize_title_with_dashes( $event_name );
        $p          = mmm_event_paths( $slug );

        if ( file_exists( $p['meta'] ) || file_exists( $p['legacy'] ) ) {
            echo '<div class="notice notice-error"><p>Event already exists.</p></div>';
        } else {
            file_put_contents( $p['meta'], json_encode( [
                'name'        => $event_name,
                'created_at'  => current_time( 'mysql' ),
                'guest_count' => 0,
            ] ), LOCK_EX );
            file_put_contents( $p['guests'],   json_encode( [ 'guests'   => [] ] ), LOCK_EX );
            file_put_contents( $p['checkins'], json_encode( [ 'checkins' => [] ] ), LOCK_EX );
            echo '<div class="notice notice-success"><p>Event created: <strong>' . esc_html( $event_name ) . '</strong></p></div>';
        }
    }

    // Handle CSV export — reads checkins file only
    if ( ! empty( $_POST['event_name'] ) && isset( $_POST['export_csv'] ) ) {
        check_admin_referer( 'mmm_export_event', 'mmm_export_nonce' );
        $event_name = sanitize_text_field( $_POST['event_name'] );
        $slug       = sanitize_title_with_dashes( $event_name );

        if ( mmm_event_exists( $slug ) ) {
            ob_clean();
            flush();

            $meta     = mmm_load_meta( $slug );
            $checkins = mmm_load_checkins( $slug );
            $csv_filename = sanitize_file_name( ( $meta['name'] ?? $slug ) . ' - ' . date( 'F-j-Y' ) . '.csv' );

            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $csv_filename ) . '"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            $output = fopen( 'php://output', 'w' );
            fputcsv( $output, [
                'First Name', 'Last Name', 'Bargaining Unit', 'Unit', 'Employer',
                'Jurisdiction', 'Job Title', 'Baseyard', 'Island', 'Member Status',
                'AFSCME ID', 'Phone', 'Check-In Method', 'Checked in Time',
            ] );
            foreach ( $checkins as $entry ) {
                fputcsv( $output, array_map( 'mmm_csv_escape', [
                    $entry['first_name']      ?? '',
                    $entry['last_name']       ?? '',
                    $entry['bargaining_unit'] ?? '',
                    $entry['unit_number']     ?? '',
                    $entry['employer']        ?? '',
                    $entry['jurisdiction']    ?? '',
                    $entry['job_title']       ?? '',
                    $entry['baseyard']        ?? '',
                    $entry['island']          ?? '',
                    $entry['member_status']   ?? '',
                    $entry['afscme_id']       ?? '',
                    $entry['phone']           ?? '',
                    $entry['method']          ?? 'qr',
                    $entry['time']            ?? '',
                ] ) );
            }
            fclose( $output );
            exit;
        }
    }

    // Handle event deletion — deletes all 3 split files + legacy
    if ( ! empty( $_POST['delete_event'] ) ) {
        check_admin_referer( 'mmm_delete_event', 'mmm_delete_nonce' );
        $slug = sanitize_title_with_dashes( $_POST['delete_event'] );
        $p    = mmm_event_paths( $slug );
        foreach ( [ 'meta', 'guests', 'checkins', 'legacy' ] as $key ) {
            if ( file_exists( $p[ $key ] ) ) unlink( $p[ $key ] );
        }
        echo '<div class="notice notice-success"><p>Event deleted: <strong>' . esc_html( $_POST['delete_event'] ) . '</strong></p></div>';
    }

    // Build event list from meta files only — no large guest arrays loaded
    $meta_files  = glob( $events_dir . '/*-meta.json' ) ?: [];
    // Also surface unmigrated legacy events
    $legacy_files = glob( $events_dir . '/*.json' ) ?: [];
    $legacy_files = array_filter( $legacy_files, function ( $f ) {
        return strpos( $f, '-meta.json' ) === false
            && strpos( $f, '-guests.json' ) === false
            && strpos( $f, '-checkins.json' ) === false;
    } );
    // Migrate legacy events so their meta files appear
    foreach ( $legacy_files as $lf ) {
        $slug = basename( $lf, '.json' );
        mmm_migrate_event( $slug );
    }
    // Re-glob after migration
    $meta_files = glob( $events_dir . '/*-meta.json' ) ?: [];

    ?>
    <h2>Settings</h2>
    <form method="POST" style="margin-bottom:24px;">
        <?php wp_nonce_field( 'mmm_settings', 'mmm_settings_nonce' ); ?>
        <table class="form-table" style="width:auto">
            <tr>
                <th scope="row"><label for="mmm_default_area_code">Default Area Code</label></th>
                <td>
                    <input type="text" id="mmm_default_area_code" name="mmm_default_area_code"
                        value="<?php echo esc_attr( get_option( 'mmm_default_area_code', '808' ) ); ?>"
                        maxlength="3" size="4" style="font-size:1.1rem; width:60px; text-align:center;" />
                    <p class="description">Used when a phone number is entered without an area code (7 digits). Default: 808.</p>
                </td>
            </tr>
        </table>
        <button type="submit" name="mmm_save_settings" class="button button-secondary">Save Settings</button>
    </form>

    <h2>Create New Event</h2>
    <form method="POST" style="margin-bottom:20px;">
        <?php wp_nonce_field( 'mmm_create_event', 'mmm_create_nonce' ); ?>
        <input type="text" name="new_event_name" required placeholder="Event Name" style="width:300px;" />
        <button type="submit" class="button button-primary">Create Event</button>
    </form>

    <?php if ( ! empty( $meta_files ) ): ?>
        <h2>Event List</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Created</th>
                    <th>Guests</th>
                    <th>Export</th>
                    <th>Launch Scanner</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $meta_files as $meta_file ):
                $meta = json_decode( file_get_contents( $meta_file ), true );
                if ( ! $meta || empty( $meta['name'] ) ) continue;
                $slug        = basename( $meta_file, '-meta.json' );
                $scanner_url = site_url( '/event-check-in/?event=' . $slug );
                $guest_count = $meta['guest_count'] ?? 0;
            ?>
                <tr>
                    <td><?= esc_html( $meta['name'] ); ?></td>
                    <td><?= isset( $meta['created_at'] ) ? esc_html( date( 'F j, Y', strtotime( $meta['created_at'] ) ) ) : '—'; ?></td>
                    <td>
                        <?php if ( $guest_count > 0 ): ?>
                            <span style="color:#2e7d32; font-weight:600;">&#10003; <?= esc_html( $guest_count ); ?> guests loaded</span><br>
                            <small style="color:#666;">Replace list:</small><br>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data" class="mmm-guest-upload-form" style="margin-top:4px;">
                            <?php wp_nonce_field( 'mmm_upload_guests', 'mmm_guests_nonce' ); ?>
                            <input type="hidden" name="guest_event_name" value="<?= esc_attr( $meta['name'] ); ?>">
                            <input type="file" name="guest_csv" accept=".csv" required style="font-size:0.8rem; max-width:140px;">
                            <button type="submit" class="button" style="margin-top:4px;"><?= $guest_count > 0 ? 'Replace List' : 'Upload Guests'; ?></button>
                        </form>
                    </td>
                    <td>
                        <form method="POST">
                            <?php wp_nonce_field( 'mmm_export_event', 'mmm_export_nonce' ); ?>
                            <input type="hidden" name="event_name" value="<?= esc_attr( $meta['name'] ); ?>">
                            <input type="hidden" name="export_csv" value="1" />
                            <button type="submit" class="button">Export Check-ins</button>
                        </form>
                    </td>
                    <td>
                        <a href="<?= esc_url( $scanner_url ); ?>" target="_blank" class="button button-secondary">Launch Scanner</a>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this event?');">
                            <?php wp_nonce_field( 'mmm_delete_event', 'mmm_delete_nonce' ); ?>
                            <input type="hidden" name="delete_event" value="<?= esc_attr( $meta['name'] ); ?>">
                            <button type="submit" class="button" style="color:red; font-weight:bold;">&#10007;</button>
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
        el.style.color      = type === 'ok' ? 'green' : 'red';
        el.style.fontWeight = 'bold';
        el.textContent      = (type === 'ok' ? '\u2705 ' : '\u274C ') + msg;
      }
      function headerOptions(headers, selected) {
        return headers.map(function (h) {
          return '<option value="' + esc(h) + '"' + (h === selected ? ' selected' : '') + '>' + esc(h) + '</option>';
        }).join('');
      }

      var optionalFields = [
        { key: 'first_name',      label: 'First Name' },
        { key: 'last_name',       label: 'Last Name' },
        { key: 'email',           label: 'Email' },
        { key: 'member_status',   label: 'Member Status' },
        { key: 'bargaining_unit', label: 'Unit Name' },
        { key: 'unit_number',     label: 'Unit Number' },
        { key: 'employer',        label: 'Employer' },
        { key: 'jurisdiction',    label: 'Jurisdiction' },
        { key: 'job_title',       label: 'Job Title' },
        { key: 'baseyard',        label: 'Baseyard' },
        { key: 'island',          label: 'Island' },
      ];

      function optionalHeaderOptions(headers, selected) {
        var skip = '<option value="">(skip)</option>';
        return skip + headers.map(function (h) {
          return '<option value="' + esc(h) + '"' + (h === selected ? ' selected' : '') + '>' + esc(h) + '</option>';
        }).join('');
      }

      function showMappingUI(form, data, eventName, nonce) {
        var guesses = data.field_guesses || {};
        var optRows = optionalFields.map(function (f) {
          return '<tr>' +
            '<td style="padding:4px 10px 4px 0; white-space:nowrap; color:#555;">' + esc(f.label) + '</td>' +
            '<td style="padding:4px 0"><select id="mmm-col-' + f.key + '" style="width:100%; padding:2px 4px; font-size:0.9em;">' +
              optionalHeaderOptions(data.headers, guesses[f.key] || '') +
            '</select></td>' +
          '</tr>';
        }).join('');

        form.innerHTML =
          '<p style="margin:0 0 8px">' +
            '<strong>' + esc(data.filename) + '</strong> &mdash; ' + data.row_count + ' rows detected.' +
          '</p>' +
          '<table style="border-collapse:collapse; width:100%; margin-bottom:8px">' +
            '<tr>' +
              '<td style="padding:5px 10px 5px 0; white-space:nowrap; font-weight:700">QR Code ID column <span style="color:#c00">*</span></td>' +
              '<td style="padding:5px 0"><select id="mmm-qr-col" style="width:100%; padding:3px 6px">' +
                headerOptions(data.headers, data.qr_guess) +
              '</select></td>' +
            '</tr>' +
            '<tr>' +
              '<td style="padding:5px 10px 5px 0; white-space:nowrap; font-weight:700">Phone Number column <span style="color:#c00">*</span></td>' +
              '<td style="padding:5px 0"><select id="mmm-phone-col" style="width:100%; padding:3px 6px">' +
                headerOptions(data.headers, data.phone_guess) +
              '</select></td>' +
            '</tr>' +
            '<tr><td colspan="2" style="padding:8px 0 4px; font-size:0.85em; color:#555; font-weight:600; border-top:1px solid #ddd;">Optional field mapping &mdash; select (skip) to leave blank</td></tr>' +
            optRows +
          '</table>' +
          '<p style="font-size:0.82em; color:#666; margin:0 0 10px">Rows missing both required fields will be skipped.</p>' +
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

          var fd = new FormData();
          fd.append('action',           'mmm_import_guest_csv');
          fd.append('mmm_guests_nonce', nonce);
          fd.append('guest_event_name', eventName);
          fd.append('temp_key',         data.temp_key);
          fd.append('qr_col',           form.querySelector('#mmm-qr-col').value);
          fd.append('phone_col',        form.querySelector('#mmm-phone-col').value);
          optionalFields.forEach(function (f) {
            var el = form.querySelector('#mmm-col-' + f.key);
            if (el) fd.append('col_' + f.key, el.value);
          });

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
    })();
    </script>
<?php
}
