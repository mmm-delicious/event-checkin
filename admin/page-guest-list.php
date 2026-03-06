<?php
defined('ABSPATH') || exit;

function mmm_render_guest_list_page() {
    $upload_dir  = wp_upload_dir();
    $events_dir  = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
    $event_files = glob($events_dir . '/*.json') ?: [];
    $selected    = isset($_GET['event']) ? sanitize_title_with_dashes($_GET['event']) : '';
    $event_data  = null;

    if ($selected) {
        $filepath = trailingslashit($events_dir) . $selected . '.json';
        if (file_exists($filepath)) {
            $event_data = json_decode(file_get_contents($filepath), true);
        }
    }
    ?>
    <div class="wrap">
        <h1>Guest List</h1>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="mmm_guest_list">
            <label for="mmm-event-select">Select Event:</label>
            <select name="event" id="mmm-event-select">
                <option value="">-- Select an Event --</option>
                <?php foreach ($event_files as $fp):
                    $d    = json_decode(file_get_contents($fp), true);
                    if (!$d || empty($d['name'])) continue;
                    $slug = sanitize_title_with_dashes($d['name']);
                    echo '<option value="' . esc_attr($slug) . '"' . selected($slug, $selected, false) . '>' . esc_html($d['name']) . '</option>';
                endforeach; ?>
            </select>
            <button type="submit" class="button button-primary" style="margin-left:8px;">Load</button>
        </form>

        <?php if ($event_data):
            $guests   = $event_data['guests'] ?? [];
            $checkins = $event_data['checkins'] ?? [];

            // Build lookup: who is checked in — by qr_id/afscme_id and by name
            $checked_by_id   = [];
            $checked_by_name = [];
            foreach ($checkins as $ci) {
                $aid = strtolower(trim($ci['afscme_id'] ?? ''));
                if ($aid) $checked_by_id[$aid] = $ci['time'] ?? '';
                $nm  = strtolower(trim(($ci['first_name'] ?? '') . ' ' . ($ci['last_name'] ?? '')));
                if ($nm !== ' ') $checked_by_name[$nm] = $ci['time'] ?? '';
            }

            $total   = count($guests);
            $checked = 0;
            foreach ($guests as $g) {
                if (mmm_guest_is_checked_in($g, $checked_by_id, $checked_by_name)) $checked++;
            }
        ?>

        <p>
            <strong><?= esc_html($event_data['name']); ?></strong> &mdash;
            <?= $total; ?> guests &mdash;
            <span style="color:#2e7d32; font-weight:600;"><?= $checked; ?> checked in</span>,
            <span style="color:#999;"><?= ($total - $checked); ?> remaining</span>
        </p>

        <table class="widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:25%">Name</th>
                    <th style="width:15%">QR / AFSCME ID</th>
                    <th style="width:15%">Phone</th>
                    <th style="width:25%">Status</th>
                    <th style="width:20%">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($guests as $idx => $guest):
                $is_checked = mmm_guest_is_checked_in($guest, $checked_by_id, $checked_by_name);
                $name       = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
                $aid_key    = strtolower(trim($guest['qr_id'] ?? ''));
                $name_key   = strtolower($name);
                $time_in    = $checked_by_id[$aid_key] ?? $checked_by_name[$name_key] ?? '';
            ?>
                <tr id="guest-row-<?= $idx; ?>">
                    <td><?= esc_html($name); ?></td>
                    <td><?= esc_html($guest['qr_id'] ?? ''); ?></td>
                    <td><?= esc_html($guest['phone'] ?? ''); ?></td>
                    <td id="guest-status-<?= $idx; ?>">
                        <?php if ($is_checked): ?>
                            <span style="color:#2e7d32; font-weight:600;">&#10003; <?= esc_html($time_in); ?></span>
                        <?php else: ?>
                            <span style="color:#999;">Not checked in</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$is_checked): ?>
                        <button class="button mmm-manual-checkin"
                            data-idx="<?= esc_attr($idx); ?>"
                            data-event="<?= esc_attr($selected); ?>"
                            data-nonce="<?= wp_create_nonce('mmm_manual_checkin'); ?>">
                            Check In
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        document.querySelectorAll('.mmm-manual-checkin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.disabled    = true;
                btn.textContent = 'Saving\u2026';
                const fd = new FormData();
                fd.append('action',           'mmm_manual_checkin');
                fd.append('event',            btn.dataset.event);
                fd.append('guest_idx',        btn.dataset.idx);
                fd.append('mmm_manual_nonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            document.getElementById('guest-status-' + btn.dataset.idx).innerHTML =
                                '<span style="color:#2e7d32; font-weight:600;">&#10003; just now</span>';
                            btn.closest('td').innerHTML = '';
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Check In';
                            const errEl = document.getElementById('guest-status-' + btn.dataset.idx);
                            errEl.innerHTML = '';
                            const errSpan = document.createElement('span');
                            errSpan.style.color = '#dc3545';
                            errSpan.textContent = res.data || 'Error';
                            errEl.appendChild(errSpan);
                        }
                    })
                    .catch(function () {
                        btn.disabled    = false;
                        btn.textContent = 'Check In';
                    });
            });
        });
        </script>

        <?php else: ?>
            <?php if ($selected): ?><p>Event not found.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function mmm_guest_is_checked_in( $guest, $checked_by_id, $checked_by_name ) {
    $aid = strtolower(trim($guest['qr_id'] ?? ''));
    if ($aid && isset($checked_by_id[$aid])) return true;
    $nm  = strtolower(trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? '')));
    return isset($checked_by_name[$nm]);
}

// AJAX: manual check-in from admin guest list
add_action('wp_ajax_mmm_manual_checkin', 'mmm_ajax_manual_checkin');

function mmm_ajax_manual_checkin() {
    check_ajax_referer('mmm_manual_checkin', 'mmm_manual_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized.');
    }

    $event_slug = sanitize_title_with_dashes($_POST['event'] ?? '');
    $guest_idx  = (int) ($_POST['guest_idx'] ?? -1);

    $upload_dir = wp_upload_dir();
    $filepath   = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events/' . $event_slug . '.json';

    if (!file_exists($filepath)) {
        wp_send_json_error('Event not found.');
    }

    $event_data = json_decode(file_get_contents($filepath), true);
    $guests     = $event_data['guests'] ?? [];

    if (!isset($guests[$guest_idx])) {
        wp_send_json_error('Guest not found.');
    }

    $g = $guests[$guest_idx];
    $event_data['checkins'][] = [
        'first_name'      => $g['first_name']      ?? '',
        'last_name'       => $g['last_name']       ?? '',
        'email'           => $g['email']           ?? '',
        'afscme_id'       => $g['qr_id']           ?? '',
        'member_status'   => $g['member_status']   ?? '',
        'bargaining_unit' => $g['bargaining_unit'] ?? '',
        'unit_number'     => $g['unit_number']     ?? '',
        'employer'        => $g['employer']        ?? '',
        'jurisdiction'    => $g['jurisdiction']    ?? '',
        'baseyard'        => $g['baseyard']        ?? '',
        'island'          => $g['island']          ?? '',
        'time'            => date_i18n('g:ia, l, F j, Y'),
        'method'          => 'manual',
    ];

    if (file_put_contents($filepath, json_encode($event_data), LOCK_EX) === false) {
        wp_send_json_error('Could not save check-in.');
    }

    wp_send_json_success('Checked in.');
}
