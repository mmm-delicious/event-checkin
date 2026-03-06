<?php
function mmm_render_event_list() {
    $upload_dir = wp_upload_dir();
    $events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
    if (!file_exists($events_dir)) {
        wp_mkdir_p($events_dir);
    }

    // Handle event creation
    if (!empty($_POST['new_event_name'])) {
        $event_name = sanitize_text_field($_POST['new_event_name']);
        $filename = sanitize_title_with_dashes($event_name) . '.json';
        $filepath = trailingslashit($events_dir) . $filename;

        if (!file_exists($filepath)) {
            $event_data = [
                'name' => $event_name,
                'created_at' => current_time('mysql'),
                'checkins' => []
            ];
            file_put_contents($filepath, json_encode($event_data));
            echo '<div class="notice notice-success"><p>✅ Event created: <strong>' . esc_html($event_name) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error"><p>🚫 Event already exists.</p></div>';
        }
    }

    // Handle CSV export
    if (!empty($_POST['event_name']) && isset($_POST['export_csv'])) {
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
                'AFSCME ID', 'Checked in Time'
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
                    $entry['time'] ?? '',
                ]);
            }

            fclose($output);
            exit;
        }
    }

    // Handle event deletion
    if (!empty($_POST['delete_event'])) {
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
        <input type="text" name="new_event_name" required placeholder="Event Name" style="width: 300px;" />
        <button type="submit" class="button button-primary">Create Event</button>
    </form>
    <?php
    $event_files = glob($events_dir . '/*.json');
    if (!empty($event_files)): ?>
        <h2>Event List</h2>
        <table class="widefat">
            <thead>
                <tr><th>Event Name</th><th>Created</th><th>Export</th><th>Launch Scanner</th><th>Delete</th></tr>
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
                        <form method="POST">
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
    <?php endif;
}
