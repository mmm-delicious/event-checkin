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

    // Handle guest CSV upload
    if ( ! empty( $_FILES['guest_csv']['tmp_name'] ) && ! empty( $_POST['guest_event_name'] ) ) {
        check_admin_referer( 'mmm_upload_guests', 'mmm_guests_nonce' );

        if ( ! is_uploaded_file( $_FILES['guest_csv']['tmp_name'] ) ) {
            echo '<div class="notice notice-error"><p>Invalid file upload.</p></div>';
        } elseif ( $_FILES['guest_csv']['size'] > 2 * 1024 * 1024 ) {
            echo '<div class="notice notice-error"><p>File too large. Maximum 2MB.</p></div>';
        } else {
            $event_name  = sanitize_text_field( $_POST['guest_event_name'] );
            $slug        = sanitize_title_with_dashes( $event_name );
            $guest_path  = trailingslashit( $events_dir ) . $slug . '.json';

            if ( ! file_exists( $guest_path ) ) {
                echo '<div class="notice notice-error"><p>Event not found.</p></div>';
            } else {
                $rows    = array_map( 'str_getcsv', file( $_FILES['guest_csv']['tmp_name'] ) );
                $headers = array_map( function( $h ) { return strtolower( trim( str_replace( ' ', '_', $h ) ) ); }, $rows[0] );

                $field_map = [
                    'first_name'      => [ 'first_name', 'first' ],
                    'last_name'       => [ 'last_name', 'last' ],
                    'phone'           => [ 'phone', 'phone_number', 'mobile', 'cell' ],
                    'email'           => [ 'email', 'email_address' ],
                    'member_status'   => [ 'member_status', 'status' ],
                    'bargaining_unit' => [ 'bargaining_unit', 'unit' ],
                    'unit_number'     => [ 'unit_number', 'unit_no' ],
                    'employer'        => [ 'employer' ],
                    'jurisdiction'    => [ 'jurisdiction' ],
                    'job_title'       => [ 'job_title', 'title' ],
                    'baseyard'        => [ 'baseyard', 'base_yard' ],
                    'island'          => [ 'island' ],
                    'afscme_id'       => [ 'afscme_id', 'afscme', 'id' ],
                ];

                // Build column index map
                $col_idx = [];
                foreach ( $field_map as $canonical => $aliases ) {
                    foreach ( $aliases as $alias ) {
                        $pos = array_search( $alias, $headers );
                        if ( $pos !== false ) {
                            $col_idx[ $canonical ] = $pos;
                            break;
                        }
                    }
                }

                $guests = [];
                foreach ( array_slice( $rows, 1 ) as $row ) {
                    if ( empty( array_filter( $row ) ) ) continue;
                    $guest = [];
                    foreach ( $field_map as $canonical => $_ ) {
                        $guest[ $canonical ] = isset( $col_idx[ $canonical ] ) ? sanitize_text_field( $row[ $col_idx[ $canonical ] ] ?? '' ) : '';
                    }
                    $guests[] = $guest;
                }

                $event_data            = json_decode( file_get_contents( $guest_path ), true );
                $event_data['guests']  = $guests;
                file_put_contents( $guest_path, json_encode( $event_data ) );

                $count = count( $guests );
                echo '<div class="notice notice-success"><p>' . esc_html( $count ) . ' guests imported for <strong>' . esc_html( $event_name ) . '</strong>.</p></div>';
            }
        }
    }

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
            file_put_contents($filepath, json_encode($event_data));
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
                        <form method="POST" enctype="multipart/form-data" style="margin-top:4px;">
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
    <?php endif;
}
