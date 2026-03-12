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
            $flag_by_id      = [];
            $flag_by_name    = [];
            foreach ($checkins as $ci) {
                $aid = strtolower(trim($ci['afscme_id'] ?? ''));
                if ($aid) {
                    $checked_by_id[$aid] = $ci['time'] ?? '';
                    $flag_by_id[$aid]    = $ci['upw_flag'] ?? '';
                }
                $nm = strtolower(trim(($ci['first_name'] ?? '') . ' ' . ($ci['last_name'] ?? '')));
                if ($nm !== ' ') {
                    $checked_by_name[$nm] = $ci['time'] ?? '';
                    $flag_by_name[$nm]    = $ci['upw_flag'] ?? '';
                }
            }

            $total   = count($guests);
            $checked = 0;
            foreach ($guests as $g) {
                if (mmm_guest_is_checked_in($g, $checked_by_id, $checked_by_name)) $checked++;
            }

            // Pagination — 100 guests per page
            $per_page     = 100;
            $current_page = max(1, (int) ($_GET['paged'] ?? 1));
            $total_pages  = max(1, (int) ceil($total / $per_page));
            $current_page = min($current_page, $total_pages);
            $page_guests  = array_slice($guests, ($current_page - 1) * $per_page, $per_page, true);
        ?>

        <p id="mmm-checkin-summary">
            <strong><?= esc_html($event_data['name']); ?></strong> &mdash;
            <span class="mmm-total"><?= $total; ?></span> guests &mdash;
            <span style="color:#2e7d32; font-weight:600;"><span class="mmm-checked"><?= $checked; ?></span> checked in</span>,
            <span style="color:#999;"><span class="mmm-remaining"><?= ($total - $checked); ?></span> remaining</span>
        </p>

        <?php if ($total_pages > 1): ?>
        <div style="margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span style="color:#555;">Page <?= $current_page; ?> of <?= $total_pages; ?> &mdash; showing guests <?= number_format(($current_page-1)*$per_page+1); ?>–<?= number_format(min($current_page*$per_page, $total)); ?></span>
            <?php if ($current_page > 1): ?>
            <a class="button" href="<?= esc_url(add_query_arg('paged', $current_page - 1)); ?>">&#8592; Prev</a>
            <?php endif; ?>
            <?php if ($current_page < $total_pages): ?>
            <a class="button" href="<?= esc_url(add_query_arg('paged', $current_page + 1)); ?>">Next &#8594;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <style>
            .mmm-upw-row { background: #fffbeb !important; }
            .mmm-upw-row td { border-left: 3px solid #f59e0b; }
            .mmm-upw-badge { display:inline-block; background:#fef3c7; color:#92400e; font-size:11px; font-weight:700; padding:1px 6px; border-radius:4px; border:1px solid #f59e0b; margin-left:4px; vertical-align:middle; }
        </style>

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
            <?php foreach ($page_guests as $idx => $guest):
                $is_checked = mmm_guest_is_checked_in($guest, $checked_by_id, $checked_by_name);
                $name       = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
                $aid_key    = strtolower(trim($guest['qr_id'] ?? ''));
                $name_key   = strtolower($name);
                $time_in    = $checked_by_id[$aid_key] ?? $checked_by_name[$name_key] ?? '';
                $upw_flag   = $flag_by_id[$aid_key] ?? $flag_by_name[$name_key] ?? '';
                $row_class  = ($is_checked && $upw_flag) ? 'mmm-upw-row' : '';
            ?>
                <tr id="guest-row-<?= $idx; ?>"
                    class="<?= esc_attr($row_class); ?>"
                    data-idx="<?= esc_attr($idx); ?>"
                    data-event="<?= esc_attr($selected); ?>"
                    data-checkin-nonce="<?= wp_create_nonce('mmm_manual_checkin'); ?>"
                    data-undo-nonce="<?= wp_create_nonce('mmm_undo_checkin'); ?>">
                    <td><?= esc_html($name); ?></td>
                    <td><?= esc_html($guest['qr_id'] ?? ''); ?></td>
                    <td><?= esc_html($guest['phone'] ?? ''); ?></td>
                    <td id="guest-status-<?= $idx; ?>">
                        <?php if ($is_checked): ?>
                            <span style="color:#2e7d32; font-weight:600;">&#10003; <?= esc_html($time_in); ?></span>
                            <?php if ($upw_flag): ?>
                            <span class="mmm-upw-badge" title="UPW member status flag">&#9888; <?= esc_html($upw_flag); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#999;">Not checked in</span>
                        <?php endif; ?>
                    </td>
                    <td id="guest-action-<?= $idx; ?>">
                        <?php if (!$is_checked): ?>
                        <button class="button mmm-manual-checkin">Check In</button>
                        <?php else: ?>
                        <button class="button mmm-undo-checkin" style="color:#dc3545; border-color:#dc3545;">Remove Check-In</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        const MMM_EVENT_SLUG  = '<?= esc_js($selected); ?>';
        const MMM_POLL_NONCE  = '<?= wp_create_nonce('mmm_poll_checkins'); ?>';

        // Local state: idx (string) → time string for checked-in guests
        const localState = {};
        document.querySelectorAll('.mmm-undo-checkin').forEach(function(btn) {
            const idx = btn.closest('tr').dataset.idx;
            const statusEl = document.getElementById('guest-status-' + idx);
            localState[idx] = statusEl ? statusEl.textContent.trim() : 'checked in';
        });

        function rowOf(btn)    { return btn.closest('tr'); }
        function statusEl(btn) { return document.getElementById('guest-status-' + rowOf(btn).dataset.idx); }
        function actionEl(btn) { return document.getElementById('guest-action-'  + rowOf(btn).dataset.idx); }

        function showStatus(btn, html) {
            statusEl(btn).innerHTML = html;
        }
        function showErr(btn, msg) {
            const span = document.createElement('span');
            span.style.color = '#dc3545';
            span.textContent = msg || 'Error';
            const el = statusEl(btn);
            el.innerHTML = '';
            el.appendChild(span);
        }

        function updateSummary() {
            const total   = document.querySelectorAll('tbody tr').length;
            const checked = Object.keys(localState).length;
            const summary = document.getElementById('mmm-checkin-summary');
            if (!summary) return;
            summary.querySelector('.mmm-total').textContent     = total;
            summary.querySelector('.mmm-checked').textContent   = checked;
            summary.querySelector('.mmm-remaining').textContent = total - checked;
        }

        function wireCheckinBtn(btn) {
            btn.addEventListener('click', function () {
                const row = rowOf(btn);
                btn.disabled    = true;
                btn.textContent = 'Saving\u2026';
                const fd = new FormData();
                fd.append('action',           'mmm_manual_checkin');
                fd.append('event',            row.dataset.event);
                fd.append('guest_idx',        row.dataset.idx);
                fd.append('mmm_manual_nonce', row.dataset.checkinNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            localState[row.dataset.idx] = 'just now';
                            showStatus(btn, '<span style="color:#2e7d32; font-weight:600;">&#10003; just now</span>');
                            const undoBtn = document.createElement('button');
                            undoBtn.className   = 'button mmm-undo-checkin';
                            undoBtn.style.color = '#dc3545';
                            undoBtn.style.borderColor = '#dc3545';
                            undoBtn.textContent = 'Remove Check-In';
                            actionEl(btn).innerHTML = '';
                            actionEl(btn).appendChild(undoBtn);
                            wireUndoBtn(undoBtn);
                            updateSummary();
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Check In';
                            showErr(btn, res.data);
                        }
                    })
                    .catch(function () { btn.disabled = false; btn.textContent = 'Check In'; });
            });
        }

        function wireUndoBtn(btn) {
            btn.addEventListener('click', function () {
                const row = rowOf(btn);
                btn.disabled    = true;
                btn.textContent = 'Removing\u2026';
                const fd = new FormData();
                fd.append('action',         'mmm_undo_checkin');
                fd.append('event',          row.dataset.event);
                fd.append('guest_idx',      row.dataset.idx);
                fd.append('mmm_undo_nonce', row.dataset.undoNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            delete localState[row.dataset.idx];
                            showStatus(btn, '<span style="color:#999;">Not checked in</span>');
                            const ciBtn = document.createElement('button');
                            ciBtn.className   = 'button mmm-manual-checkin';
                            ciBtn.textContent = 'Check In';
                            actionEl(btn).innerHTML = '';
                            actionEl(btn).appendChild(ciBtn);
                            wireCheckinBtn(ciBtn);
                            updateSummary();
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Remove Check-In';
                            showErr(btn, res.data);
                        }
                    })
                    .catch(function () { btn.disabled = false; btn.textContent = 'Remove Check-In'; });
            });
        }

        document.querySelectorAll('.mmm-manual-checkin').forEach(wireCheckinBtn);
        document.querySelectorAll('.mmm-undo-checkin').forEach(wireUndoBtn);

        // ── Auto-refresh polling ──────────────────────────────────────────────
        function pollCheckins() {
            // Skip if any action is in progress
            if (document.querySelector('.mmm-manual-checkin[disabled], .mmm-undo-checkin[disabled]')) return;

            const fd = new FormData();
            fd.append('action',          'mmm_poll_checkins');
            fd.append('event',           MMM_EVENT_SLUG);
            fd.append('mmm_poll_nonce',  MMM_POLL_NONCE);
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return;
                    const remote = res.data; // { idx: time }

                    // New check-ins: in remote but not localState
                    Object.keys(remote).forEach(function(idx) {
                        if (localState[idx] !== undefined) return;
                        localState[idx] = remote[idx];
                        const statusDiv = document.getElementById('guest-status-' + idx);
                        const actionDiv = document.getElementById('guest-action-'  + idx);
                        if (!statusDiv || !actionDiv) return;
                        const time = remote[idx] || 'checked in';
                        statusDiv.innerHTML = '<span style="color:#2e7d32; font-weight:600;">&#10003; ' + time + '</span>';
                        const undoBtn = document.createElement('button');
                        undoBtn.className        = 'button mmm-undo-checkin';
                        undoBtn.style.color       = '#dc3545';
                        undoBtn.style.borderColor = '#dc3545';
                        undoBtn.textContent       = 'Remove Check-In';
                        actionDiv.innerHTML = '';
                        actionDiv.appendChild(undoBtn);
                        wireUndoBtn(undoBtn);
                    });

                    // Removed check-ins: in localState but not remote
                    Object.keys(localState).forEach(function(idx) {
                        if (remote[idx] !== undefined) return;
                        delete localState[idx];
                        const statusDiv = document.getElementById('guest-status-' + idx);
                        const actionDiv = document.getElementById('guest-action-'  + idx);
                        if (!statusDiv || !actionDiv) return;
                        statusDiv.innerHTML = '<span style="color:#999;">Not checked in</span>';
                        const ciBtn = document.createElement('button');
                        ciBtn.className   = 'button mmm-manual-checkin';
                        ciBtn.textContent = 'Check In';
                        actionDiv.innerHTML = '';
                        actionDiv.appendChild(ciBtn);
                        wireCheckinBtn(ciBtn);
                    });

                    updateSummary();
                })
                .catch(function() {});
        }

        let pollInterval = setInterval(pollCheckins, 8000);
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(pollInterval);
            } else {
                pollCheckins();
                pollInterval = setInterval(pollCheckins, 8000);
            }
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

    mmm_locked_event_update( $filepath, function ( $event_data ) use ( $guest_idx ) {
        $guests = $event_data['guests'] ?? [];
        if ( ! isset( $guests[$guest_idx] ) ) {
            wp_send_json_error( 'Guest not found.' );
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
            'time'            => date_i18n( 'g:ia, l, F j, Y' ),
            'method'          => 'manual',
        ];
        return $event_data;
    } );

    wp_send_json_success('Checked in.');
}

// AJAX: remove check-in for a guest
add_action('wp_ajax_mmm_undo_checkin', 'mmm_ajax_undo_checkin');

function mmm_ajax_undo_checkin() {
    check_ajax_referer('mmm_undo_checkin', 'mmm_undo_nonce');
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

    mmm_locked_event_update( $filepath, function ( $event_data ) use ( $guest_idx ) {
        $guests = $event_data['guests'] ?? [];
        if ( ! isset( $guests[$guest_idx] ) ) {
            wp_send_json_error( 'Guest not found.' );
        }
        $g   = $guests[$guest_idx];
        $aid = strtolower( trim( $g['qr_id'] ?? '' ) );
        $nm  = strtolower( trim( ( $g['first_name'] ?? '' ) . ' ' . ( $g['last_name'] ?? '' ) ) );

        // Remove all check-in entries that match this guest (by ID if available, otherwise by name)
        $event_data['checkins'] = array_values( array_filter(
            $event_data['checkins'] ?? [],
            function ( $ci ) use ( $aid, $nm ) {
                $ci_aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
                $ci_nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
                // Remove entries matching by ID OR by name — mirrors mmm_guest_is_checked_in logic.
                // Matching both catches phone check-ins (stored with afscme_id:'') and any other
                // entry where the ID field was empty.
                if ( $aid && $ci_aid === $aid ) return false;
                if ( $nm !== ' ' && $ci_nm === $nm ) return false;
                return true;
            }
        ) );

        return $event_data;
    } );

    wp_send_json_success('Check-in removed.');
}
