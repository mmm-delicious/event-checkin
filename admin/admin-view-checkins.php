<?php
defined('ABSPATH') || exit;

function mmm_render_checkin_view_page() {
    $events_dir      = mmm_events_dir();
    $meta_files      = glob( $events_dir . '/*-meta.json' ) ?: [];
    $selected_event  = isset( $_GET['event'] ) ? sanitize_title_with_dashes( $_GET['event'] ) : '';
    $dashboard_nonce = wp_create_nonce( 'mmm_dashboard_nonce' );
    ?>
    <div class="wrap" style="background:#fff; padding:20px; max-width:1200px;">
        <h1>Check-In Monitor</h1>

        <form method="get" style="margin-bottom:20px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="page" value="mmm_view_checkins" />
            <label for="mmm-event-select"><strong>Select Event:</strong></label>
            <select name="event" id="mmm-event-select" style="padding:4px 8px;">
                <option value="">-- Select an Event --</option>
                <?php foreach ( $meta_files as $mf ):
                    $m = json_decode( file_get_contents( $mf ), true );
                    if ( ! $m || empty( $m['name'] ) ) continue;
                    $slug = basename( $mf, '-meta.json' );
                    $sel  = ( $slug === $selected_event ) ? 'selected' : '';
                    echo '<option value="' . esc_attr( $slug ) . '" ' . $sel . '>' . esc_html( $m['name'] ) . '</option>';
                endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Load</button>
        </form>

        <div id="mmm-dashboard" style="display:<?= $selected_event ? 'block' : 'none'; ?>">

            <!-- Stats Bar -->
            <div style="display:flex; flex-wrap:wrap; gap:24px; background:#f8f8f8; border:1px solid #ddd; border-radius:6px; padding:16px 20px; margin-bottom:20px; font-size:1.05rem;">
                <div>Total Guests: <strong id="stat-total">—</strong></div>
                <div style="color:#2e7d32;">Checked In: <strong id="stat-checked">—</strong></div>
                <div style="color:#777;">Not Checked In: <strong id="stat-not-checked">—</strong></div>
                <div>Check-In %: <strong id="stat-pct">—</strong></div>
            </div>

            <!-- Charts Row -->
            <div style="display:flex; gap:20px; margin-bottom:24px; flex-wrap:wrap; align-items:stretch; min-height:320px;">
                <div style="flex:0 0 280px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px; display:flex; flex-direction:column;">
                    <h3 style="margin:0 0 12px; font-size:1rem; flex-shrink:0;">Check-In Progress</h3>
                    <div style="flex:1; position:relative;">
                        <canvas id="chart-doughnut"></canvas>
                    </div>
                </div>
                <div style="flex:1 1 360px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px; display:flex; flex-direction:column;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; flex-shrink:0;">
                        <h3 style="margin:0; font-size:1rem;">Breakdown by:</h3>
                        <select id="chart-breakdown-field" style="padding:3px 8px;">
                            <option value="bargaining_unit">Unit Name</option>
                            <option value="member_status">Member Status</option>
                            <option value="employer">Employer</option>
                            <option value="island">Island</option>
                            <option value="job_title">Job Title</option>
                            <option value="baseyard">Baseyard</option>
                            <option value="method">Method</option>
                        </select>
                    </div>
                    <div style="flex:1; position:relative;">
                        <canvas id="chart-bar"></canvas>
                    </div>
                </div>
            </div>

            <!-- Checked-In Guest Table -->
            <h2 style="margin-bottom:8px;">Checked-In Guests</h2>
            <table class="widefat fixed striped" id="checkin-table" style="table-layout:auto;">
                <thead>
                    <tr>
                        <?php
                        $cols = [
                            'name'            => 'Name',
                            'afscme_id'       => 'AFSCME ID',
                            'phone'           => 'Phone',
                            'bargaining_unit' => 'Unit Name',
                            'member_status'   => 'Member Status',
                            'time'            => 'Check-In Time',
                        ];
                        foreach ( $cols as $key => $label ): ?>
                        <th class="mmm-sort-hdr" data-col="<?= esc_attr( $key ); ?>"
                            style="cursor:pointer; user-select:none; white-space:nowrap; padding:8px 10px;">
                            <?= esc_html( $label ); ?><span class="sort-arrow" style="font-size:0.75em;"></span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="checkin-table-body">
                    <tr><td colspan="6" style="color:#999; padding:12px;">Loading…</td></tr>
                </tbody>
            </table>
            <p style="color:#999; font-size:0.82rem; margin-top:6px;">Auto-refreshes every 10 seconds.</p>
        </div>
    </div>

    <script>
    (function () {
        var EVENT_SLUG      = <?= json_encode( $selected_event ); ?>;
        var DASHBOARD_NONCE = <?= json_encode( $dashboard_nonce ); ?>;
        var AJAX_URL        = <?= json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        if (!EVENT_SLUG) return;

        var sortCol       = 'time';
        var sortDir       = 'desc';
        var doughnutChart = null;
        var barChart      = null;
        var pollTimer     = null;
        var tableTimer    = null;

        document.addEventListener('DOMContentLoaded', function () {
            initCharts();
            fetchDashboard();
            fetchTable();
            startPolling();

            document.querySelectorAll('.mmm-sort-hdr').forEach(function (th) {
                th.addEventListener('click', function () {
                    var col = this.dataset.col;
                    if (sortCol === col) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortCol = col;
                        sortDir = 'asc';
                    }
                    renderArrows();
                    renderTable(window._lastCheckins || []);
                });
            });

            document.getElementById('chart-breakdown-field').addEventListener('change', function () {
                updateBarChartFromBreakdown(window._lastBreakdown || {});
            });
        });

        // Pause polling when tab is hidden, resume on show
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopPolling();
            } else {
                fetchDashboard();
                fetchTable();
                startPolling();
            }
        });

        function startPolling() {
            stopPolling();
            pollTimer  = setInterval(fetchDashboard, 10000); // stats + charts every 10 s
            tableTimer = setInterval(fetchTable,      30000); // table every 30 s
        }
        function stopPolling() {
            if (pollTimer)  { clearInterval(pollTimer);  pollTimer  = null; }
            if (tableTimer) { clearInterval(tableTimer); tableTimer = null; }
        }

        function initCharts() {
            var dCtx = document.getElementById('chart-doughnut').getContext('2d');
            doughnutChart = new Chart(dCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Checked In', 'Not Checked In'],
                    datasets: [{ data: [0, 0], backgroundColor: ['#2e7d32', '#e0e0e0'], borderWidth: 2, borderColor: ['#fff', '#fff'] }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            var bCtx = document.getElementById('chart-bar').getContext('2d');
            barChart = new Chart(bCtx, {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Checked In', data: [], backgroundColor: '#2e7d32' }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
                }
            });
        }

        // Stats + chart poll — tiny payload (~5 KB), runs every 10 s
        function fetchDashboard() {
            var url = AJAX_URL + '?action=mmm_get_dashboard_data&event=' +
                      encodeURIComponent(EVENT_SLUG) + '&_wpnonce=' + encodeURIComponent(DASHBOARD_NONCE);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) return;
                    var d = res.data;
                    document.getElementById('stat-total').textContent       = d.guests_total;
                    document.getElementById('stat-checked').textContent     = d.checked_in;
                    document.getElementById('stat-not-checked').textContent = d.not_checked_in;
                    document.getElementById('stat-pct').textContent         = d.percentage + '%';
                    doughnutChart.data.datasets[0].data = [d.checked_in, d.not_checked_in];
                    doughnutChart.update();
                    window._lastBreakdown = d.breakdown || {};
                    updateBarChartFromBreakdown(window._lastBreakdown);
                })
                .catch(function () {});
        }

        // Table data — separate poll every 30 s, not tied to chart refresh
        function fetchTable() {
            var url = AJAX_URL + '?action=mmm_get_checkin_table&event=' +
                      encodeURIComponent(EVENT_SLUG) + '&_wpnonce=' + encodeURIComponent(DASHBOARD_NONCE);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) return;
                    window._lastCheckins = res.data || [];
                    renderTable(window._lastCheckins);
                })
                .catch(function () {});
        }

        // Uses pre-aggregated server data — no client-side reduction of full checkins array
        function updateBarChartFromBreakdown(breakdown) {
            var field  = document.getElementById('chart-breakdown-field').value;
            var counts = breakdown[field] || {};
            var labels = Object.keys(counts).sort();
            barChart.data.labels           = labels;
            barChart.data.datasets[0].data = labels.map(function (l) { return counts[l]; });
            barChart.update();
        }

        function renderTable(checkins) {
            var col    = sortCol;
            var dir    = sortDir;
            var sorted = checkins.slice().sort(function (a, b) {
                var va, vb;
                if (col === 'time') {
                    va = a.timestamp || 0;
                    vb = b.timestamp || 0;
                    return dir === 'asc' ? va - vb : vb - va;
                }
                va = ((a[col] || '') + '').toLowerCase();
                vb = ((b[col] || '') + '').toLowerCase();
                if (va < vb) return dir === 'asc' ? -1 :  1;
                if (va > vb) return dir === 'asc' ?  1 : -1;
                return 0;
            });

            var tbody = document.getElementById('checkin-table-body');
            if (!sorted.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="color:#999; padding:12px;">No check-ins yet.</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            sorted.forEach(function (row) {
                var tr = document.createElement('tr');
                ['name', 'afscme_id', 'phone', 'bargaining_unit', 'member_status', 'time'].forEach(function (key) {
                    var td = document.createElement('td');
                    td.style.padding = '7px 10px';
                    td.textContent   = row[key] || '';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        }

        function renderArrows() {
            document.querySelectorAll('.mmm-sort-hdr').forEach(function (th) {
                var arrow = th.querySelector('.sort-arrow');
                if (th.dataset.col === sortCol) {
                    arrow.textContent = sortDir === 'asc' ? ' \u25B2' : ' \u25BC';
                } else {
                    arrow.textContent = '';
                }
            });
        }
    })();
    </script>
    <?php
}

// ── Dashboard data endpoint — returns stats + checkins only (no guests array) ──
add_action( 'wp_ajax_mmm_get_dashboard_data', 'mmm_ajax_get_dashboard_data' );

function mmm_ajax_get_dashboard_data() {
    check_ajax_referer( 'mmm_dashboard_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $slug = sanitize_title_with_dashes( $_GET['event'] ?? '' );
    if ( ! $slug ) {
        wp_send_json_error( 'Missing event.' );
    }
    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $meta     = mmm_load_meta( $slug );
    $checkins = mmm_load_checkins( $slug );

    // Count checked-in using only the checkins file
    // guest_count comes from meta (no guests file load needed)
    $checked_in   = count( $checkins );
    $guests_total = $meta['guest_count'] ?? 0;
    $not_checked  = max( 0, $guests_total - $checked_in );
    $percentage   = $guests_total > 0 ? round( ( $checked_in / $guests_total ) * 100, 1 ) : 0;

    // Pre-aggregate breakdown for all chart fields — avoids sending raw checkins array
    $chart_fields = [ 'bargaining_unit', 'member_status', 'employer', 'island', 'job_title', 'baseyard', 'method' ];
    $breakdown    = [];
    foreach ( $chart_fields as $field ) {
        $counts = [];
        foreach ( $checkins as $ci ) {
            $val = trim( $ci[ $field ] ?? '' );
            if ( $val === '' ) $val = '(blank)';
            $counts[ $val ] = ( $counts[ $val ] ?? 0 ) + 1;
        }
        $breakdown[ $field ] = $counts;
    }

    wp_send_json_success( [
        'guests_total'   => $guests_total,
        'checked_in'     => $checked_in,
        'not_checked_in' => $not_checked,
        'percentage'     => $percentage,
        'breakdown'      => $breakdown,   // ~5 KB vs 2–4 MB raw checkins array
    ] );
}

// ── Checkin table data — separate endpoint, loaded on init and every 30 s ───
add_action( 'wp_ajax_mmm_get_checkin_table', 'mmm_ajax_get_checkin_table' );

function mmm_ajax_get_checkin_table() {
    check_ajax_referer( 'mmm_dashboard_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $slug = sanitize_title_with_dashes( $_GET['event'] ?? '' );
    if ( ! $slug || ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $checkins = mmm_load_checkins( $slug );
    $rows     = [];
    foreach ( $checkins as $ci ) {
        $rows[] = [
            'name'            => trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ),
            'afscme_id'       => $ci['afscme_id']       ?? '',
            'phone'           => $ci['phone']           ?? '',
            'bargaining_unit' => $ci['bargaining_unit'] ?? '',
            'member_status'   => $ci['member_status']   ?? '',
            'time'            => $ci['time']            ?? '',
            'timestamp'       => strtotime( $ci['time'] ?? '' ) ?: 0,
        ];
    }
    wp_send_json_success( $rows );
}

// Legacy endpoint — kept for backward compat, now reads checkins file only
add_action( 'wp_ajax_mmm_get_checkins', function () {
    check_ajax_referer( 'mmm_dashboard_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $slug = sanitize_title_with_dashes( $_GET['event'] ?? '' );
    wp_send_json( mmm_load_checkins( $slug ) );
} );
