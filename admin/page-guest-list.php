<?php
defined('ABSPATH') || exit;

function mmm_render_guest_list_page() {
    $events_dir  = mmm_events_dir();
    $meta_files  = glob( $events_dir . '/*-meta.json' ) ?: [];
    $selected    = isset( $_GET['event'] ) ? sanitize_title_with_dashes( $_GET['event'] ) : '';

    // Pagination / sort / search params (all server-side)
    $per_page   = 250;
    $pg         = max( 1, (int) ( $_GET['pg']     ?? 1 ) );
    $sort_col   = in_array( $_GET['sort'] ?? '', [ 'first_name', 'last_name', 'status' ], true )
                    ? $_GET['sort'] : 'first_name';
    $sort_dir   = ( $_GET['dir'] ?? '' ) === 'desc' ? 'desc' : 'asc';
    $search     = sanitize_text_field( $_GET['search'] ?? '' );

    // Nonces generated ONCE — not per row
    $checkin_nonce = wp_create_nonce( 'mmm_manual_checkin' );
    $undo_nonce    = wp_create_nonce( 'mmm_undo_checkin' );
    $poll_nonce    = wp_create_nonce( 'mmm_poll_checkins' );
    $edit_nonce    = wp_create_nonce( 'mmm_edit_guest' );
    $add_nonce     = wp_create_nonce( 'mmm_add_guest' );

    $event_meta   = null;
    $guests_page  = [];
    $checked_by_id   = [];
    $checked_by_name = [];
    $total_guests    = 0;
    $total_filtered  = 0;
    $total_pages     = 1;
    $total_checked   = 0;

    if ( $selected && mmm_event_exists( $selected ) ) {
        $event_meta = mmm_load_meta( $selected );

        // Load checkins (small file) for status lookup
        $checkins = mmm_load_checkins( $selected );
        foreach ( $checkins as $ci ) {
            $aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
            if ( $aid ) $checked_by_id[ $aid ] = $ci['time'] ?? '';
            $nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
            if ( $nm !== ' ' ) $checked_by_name[ $nm ] = $ci['time'] ?? '';
        }

        // Load guests (needed for rendering the list)
        $all_guests = mmm_load_guests( $selected );
        $total_guests = count( $all_guests );

        // Count total checked-in — checkins file is the source of truth;
        // duplicates are prevented at check-in time so count() is accurate.
        $total_checked = count( $checkins );

        // Apply search filter — preserves original array keys
        if ( $search !== '' ) {
            $sq = strtolower( $search );
            $all_guests = array_filter( $all_guests, function ( $g ) use ( $sq ) {
                return strpos( strtolower(
                    ( $g['first_name'] ?? '' ) . ' ' .
                    ( $g['last_name']  ?? '' ) . ' ' .
                    ( $g['phone']      ?? '' )
                ), $sq ) !== false;
            } );
        }

        // Apply sort — uasort preserves keys
        uasort( $all_guests, function ( $a, $b ) use ( $sort_col, $sort_dir ) {
            if ( $sort_col === 'status' ) {
                // sort checked-in rows first/last
                // We don't have check-in state here so use a placeholder sort
                $va = $vb = '';
            } else {
                $va = strtolower( $a[ $sort_col ] ?? '' );
                $vb = strtolower( $b[ $sort_col ] ?? '' );
            }
            $cmp = strcmp( $va, $vb );
            return $sort_dir === 'desc' ? -$cmp : $cmp;
        } );

        $total_filtered = count( $all_guests );
        $total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
        $pg             = min( $pg, $total_pages );
        $offset         = ( $pg - 1 ) * $per_page;

        // Slice current page — preserve_keys=true keeps original guest indices
        $guests_page = array_slice( $all_guests, $offset, $per_page, true );
        unset( $all_guests );
    }

    // Build base URL for pagination/sort links
    $base_url = add_query_arg( array_filter( [
        'page'   => 'mmm_guest_list',
        'event'  => $selected,
        'sort'   => $sort_col,
        'dir'    => $sort_dir,
        'search' => $search,
    ] ), admin_url( 'admin.php' ) );

    function mmm_sort_link( $col, $label, $current_col, $current_dir, $base_url ) {
        $new_dir = ( $col === $current_col && $current_dir === 'asc' ) ? 'desc' : 'asc';
        $arrow   = '';
        if ( $col === $current_col ) {
            $arrow = $current_dir === 'asc' ? ' &#9650;' : ' &#9660;';
        }
        $url = add_query_arg( [ 'sort' => $col, 'dir' => $new_dir, 'pg' => 1 ], $base_url );
        return '<a href="' . esc_url( $url ) . '" style="color:inherit; text-decoration:none;">' . esc_html( $label ) . $arrow . '</a>';
    }
    ?>
    <div class="wrap">
        <h1>Guest List</h1>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="mmm_guest_list">
            <label for="mmm-event-select">Select Event:</label>
            <select name="event" id="mmm-event-select">
                <option value="">-- Select an Event --</option>
                <?php foreach ( $meta_files as $mf ):
                    $m    = json_decode( file_get_contents( $mf ), true );
                    if ( ! $m || empty( $m['name'] ) ) continue;
                    $slug = basename( $mf, '-meta.json' );
                    echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $selected, false ) . '>' . esc_html( $m['name'] ) . '</option>';
                endforeach; ?>
            </select>
            <button type="submit" class="button button-primary" style="margin-left:8px;">Load</button>
        </form>

        <?php if ( $event_meta ): ?>

        <!-- Add Guest Button + Search -->
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
            <button id="mmm-add-guest-btn" class="button button-primary">+ Add Guest</button>
            <form method="get" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page"  value="mmm_guest_list">
                <input type="hidden" name="event" value="<?= esc_attr( $selected ); ?>">
                <input type="hidden" name="sort"  value="<?= esc_attr( $sort_col ); ?>">
                <input type="hidden" name="dir"   value="<?= esc_attr( $sort_dir ); ?>">
                <input type="hidden" name="pg"    value="1">
                <input type="text" name="search" id="mmm-search"
                       value="<?= esc_attr( $search ); ?>"
                       placeholder="Search name, phone…"
                       style="padding:4px 8px; width:280px; max-width:100%;" />
                <button type="submit" class="button">Search</button>
                <?php if ( $search !== '' ): ?>
                    <a href="<?= esc_url( remove_query_arg( [ 'search', 'pg' ], $base_url ) ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <p id="mmm-checkin-summary">
            <strong><?= esc_html( $event_meta['name'] ); ?></strong> &mdash;
            <span class="mmm-total"><?= $total_guests; ?></span> guests &mdash;
            <span style="color:#2e7d32; font-weight:600;"><span class="mmm-checked"><?= $total_checked; ?></span> checked in</span>,
            <span style="color:#999;"><span class="mmm-remaining"><?= ( $total_guests - $total_checked ); ?></span> remaining</span>
            <?php if ( $search !== '' ): ?>
                &mdash; <em style="color:#555;"><?= $total_filtered; ?> matching "<?= esc_html( $search ); ?>"</em>
            <?php endif; ?>
        </p>

        <table class="widefat fixed striped" style="margin-top:8px; table-layout:auto;">
            <thead>
                <tr>
                    <th><?= mmm_sort_link( 'first_name',      'Name',          $sort_col, $sort_dir, $base_url ); ?></th>
                    <th><?= mmm_sort_link( 'qr_id',           'AFSCME ID',     $sort_col, $sort_dir, $base_url ); ?></th>
                    <th><?= mmm_sort_link( 'phone',           'Phone',         $sort_col, $sort_dir, $base_url ); ?></th>
                    <th>Baseyard</th>
                    <th><?= mmm_sort_link( 'member_status',   'Member Status', $sort_col, $sort_dir, $base_url ); ?></th>
                    <th>Status</th>
                    <th style="width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody id="mmm-guest-tbody">
            <?php foreach ( $guests_page as $idx => $guest ):
                $is_checked = mmm_guest_is_checked_in( $guest, $checked_by_id, $checked_by_name );
                $name       = trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) );
                $aid_key    = strtolower( trim( $guest['qr_id'] ?? '' ) );
                $name_key   = strtolower( $name );
                $time_in    = $checked_by_id[ $aid_key ] ?? $checked_by_name[ $name_key ] ?? '';
            ?>
                <tr id="guest-row-<?= $idx; ?>"
                    data-idx="<?= esc_attr( $idx ); ?>"
                    data-guest='<?= esc_attr( json_encode( [
                        'first_name'      => $guest['first_name']      ?? '',
                        'last_name'       => $guest['last_name']       ?? '',
                        'qr_id'           => $guest['qr_id']           ?? '',
                        'phone'           => $guest['phone']           ?? '',
                        'member_status'   => $guest['member_status']   ?? '',
                        'bargaining_unit' => $guest['bargaining_unit'] ?? '',
                        'is_checked_in'   => $is_checked ? '1' : '0',
                    ] ) ); ?>'>
                    <td><?= esc_html( $name ); ?></td>
                    <td><?= esc_html( $guest['qr_id'] ?? '' ); ?></td>
                    <td><?= esc_html( $guest['phone'] ?? '' ); ?></td>
                    <td><?= esc_html( $guest['baseyard'] ?? '' ); ?></td>
                    <td><?= esc_html( $guest['member_status'] ?? '' ); ?></td>
                    <td id="guest-status-<?= $idx; ?>">
                        <?php if ( $is_checked ): ?>
                            <span style="color:#2e7d32; font-weight:600;">&#10003; <?= esc_html( $time_in ); ?></span>
                        <?php else: ?>
                            <span style="color:#999;">Not checked in</span>
                        <?php endif; ?>
                    </td>
                    <td id="guest-action-<?= $idx; ?>" style="white-space:nowrap;">
                        <button class="button button-small mmm-edit-btn" style="margin-right:4px;">Edit</button>
                        <?php if ( ! $is_checked ): ?>
                        <button class="button button-small mmm-manual-checkin">Check In</button>
                        <?php else: ?>
                        <button class="button button-small mmm-undo-checkin" style="color:#dc3545; border-color:#dc3545;">Remove</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ): ?>
        <div style="margin-top:12px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <?php if ( $pg > 1 ): ?>
                <a class="button" href="<?= esc_url( add_query_arg( 'pg', $pg - 1, $base_url ) ); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span style="padding:4px 8px;">Page <?= $pg; ?> of <?= $total_pages; ?> (<?= $total_filtered; ?> guests<?= $search ? ' matching' : ''; ?>)</span>
            <?php if ( $pg < $total_pages ): ?>
                <a class="button" href="<?= esc_url( add_query_arg( 'pg', $pg + 1, $base_url ) ); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Edit Modal -->
        <div id="mmm-edit-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; padding:24px; width:420px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 4px 24px rgba(0,0,0,0.25);">
                <h2 style="margin:0 0 16px;">Edit Guest</h2>
                <table style="width:100%; border-collapse:collapse;">
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">First Name</td>
                        <td><input type="text" id="edit-first-name" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Last Name</td>
                        <td><input type="text" id="edit-last-name" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">AFSCME ID</td>
                        <td><input type="text" id="edit-qr-id" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Phone</td>
                        <td><input type="text" id="edit-phone" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Unit Name</td>
                        <td><input type="text" id="edit-bargaining-unit" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Member Status</td>
                        <td><input type="text" id="edit-member-status" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Checked In</td>
                        <td><label><input type="checkbox" id="edit-checked-in" /> Mark as checked in</label></td></tr>
                </table>
                <p id="edit-modal-status" style="margin:10px 0 0; color:#dc3545; font-size:0.9em;"></p>
                <div style="margin-top:16px; display:flex; gap:8px;">
                    <button id="edit-save-btn" class="button button-primary">Save</button>
                    <button id="edit-cancel-btn" class="button">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Add Guest Modal -->
        <div id="mmm-add-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; padding:24px; width:420px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 4px 24px rgba(0,0,0,0.25);">
                <h2 style="margin:0 0 16px;">Add Guest</h2>
                <table style="width:100%; border-collapse:collapse;">
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">First Name</td>
                        <td><input type="text" id="add-first-name" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Last Name</td>
                        <td><input type="text" id="add-last-name" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">AFSCME ID</td>
                        <td><input type="text" id="add-qr-id" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Phone</td>
                        <td><input type="text" id="add-phone" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Unit Name</td>
                        <td><input type="text" id="add-bargaining-unit" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Member Status</td>
                        <td><input type="text" id="add-member-status" style="width:100%;" /></td></tr>
                    <tr><td style="padding:6px 8px 6px 0; font-weight:600; white-space:nowrap;">Checked In</td>
                        <td><label><input type="checkbox" id="add-checked-in" /> Mark as checked in</label></td></tr>
                </table>
                <p id="add-modal-status" style="margin:10px 0 0; color:#dc3545; font-size:0.9em;"></p>
                <div style="margin-top:16px; display:flex; gap:8px;">
                    <button id="add-save-btn" class="button button-primary">Add Guest</button>
                    <button id="add-cancel-btn" class="button">Cancel</button>
                </div>
            </div>
        </div>

        <script>
        var MMM_EVENT_SLUG   = '<?= esc_js( $selected ); ?>';
        var MMM_POLL_NONCE   = '<?= esc_js( $poll_nonce ); ?>';
        var MMM_CHECKIN_NONCE = '<?= esc_js( $checkin_nonce ); ?>';
        var MMM_UNDO_NONCE   = '<?= esc_js( $undo_nonce ); ?>';
        var MMM_EDIT_NONCE   = '<?= esc_js( $edit_nonce ); ?>';
        var MMM_ADD_NONCE    = '<?= esc_js( $add_nonce ); ?>';

        // ── Local check-in state: idx → time ─────────────────────────────────
        var localState = {};
        document.querySelectorAll('.mmm-undo-checkin').forEach(function (btn) {
            var row = btn.closest('tr');
            if (row) localState[row.dataset.idx] = 'checked in';
        });

        function statusEl(idx) { return document.getElementById('guest-status-' + idx); }
        function actionEl(idx) { return document.getElementById('guest-action-'  + idx); }

        function updateSummary() {
            var total   = parseInt(document.querySelector('.mmm-total').textContent) || 0;
            var checked = Object.keys(localState).length;
            // Adjust the "checked" and "remaining" spans relative to the page delta
            // (we track page-level deltas; total comes from PHP)
            var sum = document.getElementById('mmm-checkin-summary');
            if (!sum) return;
            var baseChecked   = parseInt(sum.querySelector('.mmm-checked').dataset.base   || sum.querySelector('.mmm-checked').textContent)   || 0;
            var baseRemaining = parseInt(sum.querySelector('.mmm-remaining').dataset.base || sum.querySelector('.mmm-remaining').textContent) || 0;
        }

        // ── Check-In / Undo ───────────────────────────────────────────────────
        function wireCheckinBtn(btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                var idx = row.dataset.idx;
                btn.disabled    = true;
                btn.textContent = 'Saving\u2026';
                var fd = new FormData();
                fd.append('action',           'mmm_manual_checkin');
                fd.append('event',            MMM_EVENT_SLUG);
                fd.append('guest_idx',        idx);
                fd.append('mmm_manual_nonce', MMM_CHECKIN_NONCE);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            localState[idx] = 'just now';
                            statusEl(idx).innerHTML = '';
                            var sp = document.createElement('span');
                            sp.style.cssText = 'color:#2e7d32; font-weight:600;';
                            sp.textContent   = '\u2713 just now';
                            statusEl(idx).appendChild(sp);
                            var undoBtn = document.createElement('button');
                            undoBtn.className        = 'button button-small mmm-undo-checkin';
                            undoBtn.style.color      = '#dc3545';
                            undoBtn.style.borderColor = '#dc3545';
                            undoBtn.textContent      = 'Remove';
                            var existing = actionEl(idx).querySelector('.mmm-manual-checkin');
                            if (existing) existing.replaceWith(undoBtn);
                            wireUndoBtn(undoBtn);
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Check In';
                            statusEl(idx).innerHTML = '';
                            var errSp = document.createElement('span');
                            errSp.style.color = '#dc3545';
                            errSp.textContent = res.data || 'Error';
                            statusEl(idx).appendChild(errSp);
                        }
                    })
                    .catch(function () { btn.disabled = false; btn.textContent = 'Check In'; });
            });
        }

        function wireUndoBtn(btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                var idx = row.dataset.idx;
                btn.disabled    = true;
                btn.textContent = 'Removing\u2026';
                var fd = new FormData();
                fd.append('action',         'mmm_undo_checkin');
                fd.append('event',          MMM_EVENT_SLUG);
                fd.append('guest_idx',      idx);
                fd.append('mmm_undo_nonce', MMM_UNDO_NONCE);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            delete localState[idx];
                            statusEl(idx).innerHTML = '';
                            var sp = document.createElement('span');
                            sp.style.color  = '#999';
                            sp.textContent  = 'Not checked in';
                            statusEl(idx).appendChild(sp);
                            var ciBtn = document.createElement('button');
                            ciBtn.className   = 'button button-small mmm-manual-checkin';
                            ciBtn.textContent = 'Check In';
                            var existing = actionEl(idx).querySelector('.mmm-undo-checkin');
                            if (existing) existing.replaceWith(ciBtn);
                            wireCheckinBtn(ciBtn);
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Remove';
                        }
                    })
                    .catch(function () { btn.disabled = false; btn.textContent = 'Remove'; });
            });
        }

        document.querySelectorAll('.mmm-manual-checkin').forEach(wireCheckinBtn);
        document.querySelectorAll('.mmm-undo-checkin').forEach(wireUndoBtn);

        // ── Auto-refresh polling (checkins file only) ─────────────────────────
        var pollTimer = null;
        function pollCheckins() {
            if (document.querySelector('.mmm-manual-checkin[disabled], .mmm-undo-checkin[disabled]')) return;
            var fd = new FormData();
            fd.append('action',         'mmm_poll_checkins');
            fd.append('event',          MMM_EVENT_SLUG);
            fd.append('mmm_poll_nonce', MMM_POLL_NONCE);
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) return;
                    var remote = res.data;

                    Object.keys(remote).forEach(function (idx) {
                        if (localState[idx] !== undefined) return;
                        localState[idx] = remote[idx];
                        var statusDiv = statusEl(idx);
                        var actionDiv = actionEl(idx);
                        if (!statusDiv || !actionDiv) return;
                        statusDiv.innerHTML = '';
                        var sp = document.createElement('span');
                        sp.style.cssText = 'color:#2e7d32; font-weight:600;';
                        sp.textContent   = '\u2713 ' + (remote[idx] || 'checked in');
                        statusDiv.appendChild(sp);
                        var undoBtn = document.createElement('button');
                        undoBtn.className         = 'button button-small mmm-undo-checkin';
                        undoBtn.style.color       = '#dc3545';
                        undoBtn.style.borderColor = '#dc3545';
                        undoBtn.textContent       = 'Remove';
                        var existing = actionDiv.querySelector('.mmm-manual-checkin, .mmm-undo-checkin');
                        if (existing) existing.replaceWith(undoBtn);
                        wireUndoBtn(undoBtn);
                    });

                    Object.keys(localState).forEach(function (idx) {
                        if (remote[idx] !== undefined) return;
                        delete localState[idx];
                        var statusDiv = statusEl(idx);
                        var actionDiv = actionEl(idx);
                        if (!statusDiv || !actionDiv) return;
                        statusDiv.innerHTML = '';
                        var sp = document.createElement('span');
                        sp.style.color = '#999';
                        sp.textContent = 'Not checked in';
                        statusDiv.appendChild(sp);
                        var ciBtn = document.createElement('button');
                        ciBtn.className   = 'button button-small mmm-manual-checkin';
                        ciBtn.textContent = 'Check In';
                        var existing = actionDiv.querySelector('.mmm-manual-checkin, .mmm-undo-checkin');
                        if (existing) existing.replaceWith(ciBtn);
                        wireCheckinBtn(ciBtn);
                    });
                })
                .catch(function () {});
        }

        function startPolling() { stopPolling(); pollTimer = setInterval(pollCheckins, 8000); }
        function stopPolling()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
        startPolling();
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stopPolling(); } else { pollCheckins(); startPolling(); }
        });

        // ── Search auto-submit (debounced 400ms) ──────────────────────────────
        var searchTimeout;
        var searchInput = document.getElementById('mmm-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    searchInput.closest('form').submit();
                }, 400);
            });
        }

        // ── Edit Modal ────────────────────────────────────────────────────────
        var editModal = document.getElementById('mmm-edit-modal');
        var editIdx   = null;

        function wireEditBtn(btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                editIdx = row.dataset.idx;
                var g   = JSON.parse(row.dataset.guest);
                document.getElementById('edit-first-name').value      = g.first_name      || '';
                document.getElementById('edit-last-name').value       = g.last_name       || '';
                document.getElementById('edit-qr-id').value           = g.qr_id           || '';
                document.getElementById('edit-phone').value           = g.phone           || '';
                document.getElementById('edit-bargaining-unit').value = g.bargaining_unit || '';
                document.getElementById('edit-member-status').value   = g.member_status   || '';
                document.getElementById('edit-checked-in').checked    = g.is_checked_in === '1';
                document.getElementById('edit-modal-status').textContent = '';
                editModal.style.display = 'flex';
            });
        }
        document.querySelectorAll('.mmm-edit-btn').forEach(wireEditBtn);

        document.getElementById('edit-cancel-btn').addEventListener('click', function () { editModal.style.display = 'none'; });
        editModal.addEventListener('click', function (e) { if (e.target === editModal) editModal.style.display = 'none'; });

        document.getElementById('edit-save-btn').addEventListener('click', function () {
            var saveBtn = this;
            saveBtn.disabled    = true;
            saveBtn.textContent = 'Saving\u2026';
            document.getElementById('edit-modal-status').textContent = '';

            var fn  = document.getElementById('edit-first-name').value;
            var ln  = document.getElementById('edit-last-name').value;
            var qr  = document.getElementById('edit-qr-id').value;
            var ph  = document.getElementById('edit-phone').value;
            var bu  = document.getElementById('edit-bargaining-unit').value;
            var ms  = document.getElementById('edit-member-status').value;
            var isCI = document.getElementById('edit-checked-in').checked;

            var fd = new FormData();
            fd.append('action',          'mmm_edit_guest');
            fd.append('mmm_edit_nonce',  MMM_EDIT_NONCE);
            fd.append('event',           MMM_EVENT_SLUG);
            fd.append('guest_idx',       editIdx);
            fd.append('first_name',      fn);
            fd.append('last_name',       ln);
            fd.append('qr_id',           qr);
            fd.append('phone',           ph);
            fd.append('bargaining_unit', bu);
            fd.append('member_status',   ms);
            fd.append('is_checked_in',   isCI ? '1' : '0');

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Save';
                    if (res.success) {
                        editModal.style.display = 'none';
                        var row = document.getElementById('guest-row-' + editIdx);
                        if (row) {
                            row.cells[0].textContent = (fn + ' ' + ln).trim();
                            row.cells[1].textContent = qr;
                            row.cells[2].textContent = ph;
                            row.cells[4].textContent = ms;
                            row.dataset.guest = JSON.stringify({ first_name: fn, last_name: ln, qr_id: qr, phone: ph, member_status: ms, bargaining_unit: bu, is_checked_in: isCI ? '1' : '0' });

                            if (isCI && !localState[editIdx]) {
                                localState[editIdx] = 'manual';
                                statusEl(editIdx).innerHTML = '';
                                var sp = document.createElement('span'); sp.style.cssText = 'color:#2e7d32; font-weight:600;'; sp.textContent = '\u2713 checked in';
                                statusEl(editIdx).appendChild(sp);
                                var undoBtn = document.createElement('button');
                                undoBtn.className = 'button button-small mmm-undo-checkin'; undoBtn.style.color = '#dc3545'; undoBtn.style.borderColor = '#dc3545'; undoBtn.textContent = 'Remove';
                                var ex = actionEl(editIdx).querySelector('.mmm-manual-checkin, .mmm-undo-checkin'); if (ex) ex.replaceWith(undoBtn);
                                wireUndoBtn(undoBtn);
                            } else if (!isCI && localState[editIdx]) {
                                delete localState[editIdx];
                                statusEl(editIdx).innerHTML = '';
                                var sp2 = document.createElement('span'); sp2.style.color = '#999'; sp2.textContent = 'Not checked in';
                                statusEl(editIdx).appendChild(sp2);
                                var ciBtn = document.createElement('button'); ciBtn.className = 'button button-small mmm-manual-checkin'; ciBtn.textContent = 'Check In';
                                var ex2 = actionEl(editIdx).querySelector('.mmm-manual-checkin, .mmm-undo-checkin'); if (ex2) ex2.replaceWith(ciBtn);
                                wireCheckinBtn(ciBtn);
                            }
                        }
                        pollCheckins();
                    } else {
                        document.getElementById('edit-modal-status').textContent = res.data || 'Error saving.';
                    }
                })
                .catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Save'; document.getElementById('edit-modal-status').textContent = 'Connection error.'; });
        });

        // ── Add Guest Modal ───────────────────────────────────────────────────
        var addModal = document.getElementById('mmm-add-modal');
        document.getElementById('mmm-add-guest-btn').addEventListener('click', function () {
            ['add-first-name','add-last-name','add-qr-id','add-phone','add-bargaining-unit','add-member-status'].forEach(function (id) { document.getElementById(id).value = ''; });
            document.getElementById('add-checked-in').checked = false;
            document.getElementById('add-modal-status').textContent = '';
            addModal.style.display = 'flex';
        });
        document.getElementById('add-cancel-btn').addEventListener('click', function () { addModal.style.display = 'none'; });
        addModal.addEventListener('click', function (e) { if (e.target === addModal) addModal.style.display = 'none'; });

        document.getElementById('add-save-btn').addEventListener('click', function () {
            var saveBtn = this;
            saveBtn.disabled    = true;
            saveBtn.textContent = 'Adding\u2026';
            document.getElementById('add-modal-status').textContent = '';

            var fn  = document.getElementById('add-first-name').value;
            var ln  = document.getElementById('add-last-name').value;
            var qr  = document.getElementById('add-qr-id').value;
            var ph  = document.getElementById('add-phone').value;
            var bu  = document.getElementById('add-bargaining-unit').value;
            var ms  = document.getElementById('add-member-status').value;
            var isCI = document.getElementById('add-checked-in').checked;

            var fd = new FormData();
            fd.append('action',          'mmm_add_guest');
            fd.append('mmm_add_nonce',   MMM_ADD_NONCE);
            fd.append('event',           MMM_EVENT_SLUG);
            fd.append('first_name',      fn);
            fd.append('last_name',       ln);
            fd.append('qr_id',           qr);
            fd.append('phone',           ph);
            fd.append('bargaining_unit', bu);
            fd.append('member_status',   ms);
            fd.append('is_checked_in',   isCI ? '1' : '0');

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Add Guest';
                    if (res.success) {
                        addModal.style.display = 'none';
                        var newIdx = res.data.guest_idx;

                        var tbody = document.getElementById('mmm-guest-tbody');
                        var tr = document.createElement('tr');
                        tr.id = 'guest-row-' + newIdx;
                        tr.setAttribute('data-idx',   newIdx);
                        tr.setAttribute('data-guest', JSON.stringify({ first_name: fn, last_name: ln, qr_id: qr, phone: ph, member_status: ms, bargaining_unit: bu, is_checked_in: isCI ? '1' : '0' }));

                        var fullName = (fn + ' ' + ln).trim();
                        [fullName, qr, ph, '', ms].forEach(function (val) {
                            var td = document.createElement('td'); td.textContent = val; tr.appendChild(td);
                        });

                        var statusTd = document.createElement('td');
                        statusTd.id = 'guest-status-' + newIdx;
                        var statusSp = document.createElement('span');
                        if (isCI) { statusSp.style.cssText = 'color:#2e7d32; font-weight:600;'; statusSp.textContent = '\u2713 just now'; localState[newIdx] = 'just now'; }
                        else       { statusSp.style.color = '#999'; statusSp.textContent = 'Not checked in'; }
                        statusTd.appendChild(statusSp);
                        tr.appendChild(statusTd);

                        var actionTd = document.createElement('td');
                        actionTd.id = 'guest-action-' + newIdx;
                        actionTd.style.whiteSpace = 'nowrap';
                        var editBtnEl = document.createElement('button');
                        editBtnEl.className = 'button button-small mmm-edit-btn'; editBtnEl.style.marginRight = '4px'; editBtnEl.textContent = 'Edit';
                        actionTd.appendChild(editBtnEl);
                        if (isCI) {
                            var undoBtnEl = document.createElement('button');
                            undoBtnEl.className = 'button button-small mmm-undo-checkin'; undoBtnEl.style.color = '#dc3545'; undoBtnEl.style.borderColor = '#dc3545'; undoBtnEl.textContent = 'Remove';
                            actionTd.appendChild(undoBtnEl);
                            wireUndoBtn(undoBtnEl);
                        } else {
                            var ciBtnEl = document.createElement('button');
                            ciBtnEl.className = 'button button-small mmm-manual-checkin'; ciBtnEl.textContent = 'Check In';
                            actionTd.appendChild(ciBtnEl);
                            wireCheckinBtn(ciBtnEl);
                        }
                        tr.appendChild(actionTd);
                        wireEditBtn(editBtnEl);
                        tbody.appendChild(tr);
                        pollCheckins();
                    } else {
                        document.getElementById('add-modal-status').textContent = res.data || 'Error adding guest.';
                    }
                })
                .catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Add Guest'; document.getElementById('add-modal-status').textContent = 'Connection error.'; });
        });
        </script>

        <?php else: ?>
            <?php if ( $selected ): ?><p>Event not found.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function mmm_guest_is_checked_in( $guest, $checked_by_id, $checked_by_name ) {
    $aid = strtolower( trim( $guest['qr_id'] ?? '' ) );
    if ( $aid && isset( $checked_by_id[ $aid ] ) ) return true;
    $nm  = strtolower( trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) ) );
    return isset( $checked_by_name[ $nm ] );
}

// ── Manual check-in — reads guest from guests file, writes checkins file only ─
add_action( 'wp_ajax_mmm_manual_checkin', 'mmm_ajax_manual_checkin' );

function mmm_ajax_manual_checkin() {
    check_ajax_referer( 'mmm_manual_checkin', 'mmm_manual_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $slug      = sanitize_title_with_dashes( $_POST['event']     ?? '' );
    $guest_idx = (int) ( $_POST['guest_idx']                     ?? -1 );

    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $guests = mmm_load_guests( $slug );
    if ( ! isset( $guests[ $guest_idx ] ) ) {
        wp_send_json_error( 'Guest not found.' );
    }

    $g        = $guests[ $guest_idx ];
    $checkins = mmm_load_checkins( $slug );
    $checkins[] = [
        'guest_idx'       => $guest_idx,
        'first_name'      => $g['first_name']      ?? '',
        'last_name'       => $g['last_name']        ?? '',
        'email'           => $g['email']            ?? '',
        'afscme_id'       => $g['qr_id']            ?? '',
        'phone'           => $g['phone']            ?? '',
        'member_status'   => $g['member_status']    ?? '',
        'bargaining_unit' => $g['bargaining_unit']  ?? '',
        'unit_number'     => $g['unit_number']      ?? '',
        'employer'        => $g['employer']         ?? '',
        'jurisdiction'    => $g['jurisdiction']     ?? '',
        'baseyard'        => $g['baseyard']         ?? '',
        'island'          => $g['island']           ?? '',
        'time'            => date_i18n( 'g:ia, l, F j, Y' ),
        'method'          => 'manual',
    ];

    if ( mmm_save_checkins( $slug, $checkins ) === false ) {
        wp_send_json_error( 'Could not save check-in.' );
    }

    wp_send_json_success( 'Checked in.' );
}

// ── Undo check-in — reads guest from guests file, writes checkins file only ──
add_action( 'wp_ajax_mmm_undo_checkin', 'mmm_ajax_undo_checkin' );

function mmm_ajax_undo_checkin() {
    check_ajax_referer( 'mmm_undo_checkin', 'mmm_undo_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $slug      = sanitize_title_with_dashes( $_POST['event']     ?? '' );
    $guest_idx = (int) ( $_POST['guest_idx']                     ?? -1 );

    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $guests = mmm_load_guests( $slug );
    if ( ! isset( $guests[ $guest_idx ] ) ) {
        wp_send_json_error( 'Guest not found.' );
    }

    $g   = $guests[ $guest_idx ];
    $aid = strtolower( trim( $g['qr_id'] ?? '' ) );
    $nm  = strtolower( trim( ( $g['first_name'] ?? '' ) . ' ' . ( $g['last_name'] ?? '' ) ) );

    $checkins = mmm_load_checkins( $slug );
    $checkins = array_values( array_filter( $checkins, function ( $ci ) use ( $aid, $nm, $guest_idx ) {
        // Remove by guest_idx if present (fast path for v3.7+ entries)
        if ( isset( $ci['guest_idx'] ) ) return (int) $ci['guest_idx'] !== $guest_idx;
        // Fallback: match by ID OR name — catches phone check-ins stored with afscme_id:''
        $ci_aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
        $ci_nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
        if ( $aid && $ci_aid === $aid ) return false;
        if ( $nm !== ' ' && $ci_nm === $nm ) return false;
        return true;
    } ) );

    if ( mmm_save_checkins( $slug, $checkins ) === false ) {
        wp_send_json_error( 'Could not save.' );
    }

    wp_send_json_success( 'Check-in removed.' );
}
