<?php
/**
 * Plugin Name: User Event QR Check-In
 * Description: Generates a unique QR code for each user. Admins can create events and scan QR codes to log check-ins.
 * Version: 1.0.5
 * Author: MMM Delicious
 */

// Activation: Create tables
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ueq_events (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ueq_checkins (
        id INT NOT NULL AUTO_INCREMENT,
        event_id INT NOT NULL,
        user_id BIGINT NOT NULL,
        checkin_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;");
});

// Shortcode: Display user QR
add_shortcode('user_qr_code', function () {
    if (!is_user_logged_in()) return '<p>Please log in to see your QR code.</p>';
    $user_id = get_current_user_id();
    $user_hash = md5('user_' . $user_id);
    $qr_url = 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' . urlencode($user_hash);
    return "<img src='$qr_url' alt='User QR Code' />";
});

// Admin Menu
add_action('admin_menu', function () {
    add_menu_page('QR Events', 'QR Events', 'manage_options', 'ueq-events', 'ueq_events_page', 'dashicons-tickets-alt');
    add_submenu_page(null, 'Event Check-In', 'Event Check-In', 'manage_options', 'ueq-event-checkin', 'ueq_event_checkin_page');
});

function ueq_events_page() {
    global $wpdb;
    $events_table = $wpdb->prefix . 'ueq_events';

    if (isset($_POST['ueq_event_name'])) {
        $name = sanitize_text_field($_POST['ueq_event_name']);
        $wpdb->insert($events_table, ['name' => $name]);
        echo '<div class="updated"><p>Event created.</p></div>';
    }

    if (isset($_GET['delete_event'])) {
        $event_id = intval($_GET['delete_event']);
        $wpdb->delete($events_table, ['id' => $event_id]);
        $wpdb->delete($wpdb->prefix . 'ueq_checkins', ['event_id' => $event_id]);
        echo '<div class="updated"><p>Event deleted.</p></div>';
    }

    $events = $wpdb->get_results("SELECT * FROM $events_table ORDER BY created_at DESC");

    echo '<div class="wrap">
        <h1>QR Events</h1>
        <form method="post">
            <input type="text" name="ueq_event_name" placeholder="Event Name" required>
            <button type="submit" class="button button-primary">Create Event</button>
        </form>

        <h2>Existing Events</h2>
        <ul>';
    foreach ($events as $event) {
        $checkin_url = admin_url('admin.php?page=ueq-event-checkin&event_id=' . $event->id);
        $export_url = admin_url('admin.php?page=ueq-events&export_event=' . $event->id);
        $delete_url = admin_url('admin.php?page=ueq-events&delete_event=' . $event->id);
        echo "<li><strong>{$event->name}</strong> - 
            <a href='$checkin_url'>Check-In</a> | 
            <a href='$export_url'>Export</a> | 
            <a href='$delete_url' onclick=\"return confirm('Delete this event?')\">Delete</a></li>";
    }
    echo '</ul></div>';

    if (isset($_GET['export_event'])) {
        $event_id = intval($_GET['export_event']);
        $checkins = $wpdb->get_results($wpdb->prepare("SELECT u.user_email, m1.meta_value AS first_name, m2.meta_value AS last_name, c.checkin_time
            FROM {$wpdb->prefix}ueq_checkins c
            JOIN {$wpdb->users} u ON u.ID = c.user_id
            LEFT JOIN {$wpdb->usermeta} m1 ON m1.user_id = u.ID AND m1.meta_key = 'first_name'
            LEFT JOIN {$wpdb->usermeta} m2 ON m2.user_id = u.ID AND m2.meta_key = 'last_name'
            WHERE c.event_id = %d", $event_id));

        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=event_checkins_$event_id.csv");
        echo "Email,First Name,Last Name,Check-in Time\n";
        foreach ($checkins as $row) {
            echo "{$row->user_email},{$row->first_name},{$row->last_name},{$row->checkin_time}\n";
        }
        exit;
    }
}

function ueq_event_checkin_page() {
    if (!isset($_GET['event_id'])) {
        echo '<div class="wrap"><h1>No event selected.</h1></div>';
        return;
    }
    $event_id = intval($_GET['event_id']);
    echo '<div class="wrap">
        <h1>Event Check-In</h1>
        <div id="qr-reader" style="width:300px;"></div>
        <div id="qr-result"></div>
        <script src="https://unpkg.com/html5-qrcode@2.3.7/minified/html5-qrcode.min.js"></script>
        <script>
            const qrResult = document.getElementById('qr-result');
            const html5QrCode = new Html5Qrcode("qr-reader");
            function onScanSuccess(decodedText) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ueq_log_checkin',
                        event_id: "$event_id",
                        qr_hash: decodedText
                    })
                })
                .then(res => res.json())
                .then(data => {
                    qrResult.innerHTML = '<strong>' + data.message + '</strong>';
                });
                html5QrCode.stop();
            }
            Html5Qrcode.getCameras().then(devices => {
                if (devices.length) {
                    html5QrCode.start(
                        { facingMode: "environment" },
                        { fps: 10, qrbox: 250 },
                        onScanSuccess
                    );
                }
            });
        </script>
    </div>';
}

add_action('wp_ajax_ueq_log_checkin', function () {
    global $wpdb;
    $event_id = intval($_POST['event_id']);
    $qr_hash = sanitize_text_field($_POST['qr_hash']);
    $checkins_table = $wpdb->prefix . 'ueq_checkins';

    $users = get_users();
    foreach ($users as $user) {
        if (md5('user_' . $user->ID) === $qr_hash) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $checkins_table WHERE event_id = %d AND user_id = %d", $event_id, $user->ID));
            if (!$exists) {
                $wpdb->insert($checkins_table, ['event_id' => $event_id, 'user_id' => $user->ID]);
                wp_send_json(['success' => true, 'message' => "Check-in successful: {$user->display_name}"]);
            } else {
                wp_send_json(['success' => false, 'message' => "Already checked in."]);
            }
        }
    }
    wp_send_json(['success' => false, 'message' => "User not found."]);
});
