<?php
defined('ABSPATH') || exit;

// This file only renders the admin check-in viewer page.
// QR generator and AJAX handler are now moved to their respective files.

function mmm_render_checkin_view_page() {
    $upload_dir = wp_upload_dir();
    $events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
    $event_files = glob($events_dir . '/*.json');

    $selected_event = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
    ?>
    <div class="wrap" style="background:#fff; padding: 20px;">
        <h1 style="margin-bottom: 20px;">🕵️ View Event Check-Ins</h1>
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="mmm_view_checkins" />
            <label for="event">Select Event:</label>
            <select name="event" id="event">
                <option value="">-- Select an Event --</option>
                <?php foreach ($event_files as $filepath):
                    $data = json_decode(file_get_contents($filepath), true);
                    if (!$data || empty($data['name'])) continue;
                    $slug = sanitize_title_with_dashes($data['name']);
                    $selected = ($slug === $selected_event) ? 'selected' : '';
                    echo '<option value="' . esc_attr($slug) . '" ' . $selected . '>' . esc_html($data['name']) . '</option>';
                endforeach; ?>
            </select>
            <button type="submit" class="button button-primary" style="margin-left:10px;">Load</button>
        </form>

        <div id="checkin-results" style="font-family: sans-serif;">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #000;">
                        <th style="padding: 8px;">First Name</th>
                        <th style="padding: 8px;">Last Name</th>
                        <th style="padding: 8px;">Bargaining Unit</th>
                        <th style="padding: 8px;">Unit</th>
                        <th style="padding: 8px;">Employer</th>
                        <th style="padding: 8px;">Jurisdiction</th>
                        <th style="padding: 8px;">AFSCME ID</th>
                        <th style="padding: 8px;">Member Status</th>
                        <th style="padding: 8px;">Method</th>
                        <th style="padding: 8px;">Check-In Time</th>
                    </tr>
                </thead>
                <tbody id="checkin-table-body">
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const event = <?php echo json_encode($selected_event); ?>;
        if (!event) return;

        function loadCheckins() {
            fetch("<?php echo admin_url('admin-ajax.php'); ?>?action=mmm_get_checkins&event=" + event)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById("checkin-table-body");
                    tbody.innerHTML = "";
                    data.forEach(row => {
                        const tr = document.createElement("tr");
                        const fields = [
                            row.first_name, row.last_name, row.bargaining_unit,
                            row.unit_number, row.employer, row.jurisdiction,
                            row.afscme_id, row.member_status,
                            row.method ?? 'qr', row.time
                        ];
                        fields.forEach(value => {
                            const td = document.createElement("td");
                            td.style.padding = "8px";
                            td.textContent = value ?? '';
                            tr.appendChild(td);
                        });
                        tbody.appendChild(tr);
                    });
                });
        }

        loadCheckins();
        setInterval(loadCheckins, 10000);
    });
    </script>
<?php }

add_action('wp_ajax_mmm_get_checkins', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }
    $slug = sanitize_title_with_dashes($_GET['event'] ?? '');
    $upload_dir = wp_upload_dir();
    $filepath = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events/' . $slug . '.json';
    if (!file_exists($filepath)) wp_send_json([]);

    $data = json_decode(file_get_contents($filepath), true);
    $checkins = $data['checkins'] ?? [];
    wp_send_json($checkins);
});
