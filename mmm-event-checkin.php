<?php
/**
 * Plugin Name: MMM Event Check-In
 * Description: Generate QR codes for user check-in and manage events.
 * Version: 3.3.2
 * Author: MMM Delicious
 * Developer: Mark McDonnell
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Text Domain: mmm-event-checkin
 */

defined('ABSPATH') || exit;

// Constants
define('MMM_ECI_VERSION', '3.3.2');
define('MMM_ECI_PATH', plugin_dir_path(__FILE__));
define('MMM_ECI_URL', plugin_dir_url(__FILE__));

// Load all necessary files
require_once MMM_ECI_PATH . 'includes/class-qr-generator.php';
require_once MMM_ECI_PATH . 'includes/shortcodes.php';
require_once MMM_ECI_PATH . 'admin/class-admin-menu.php';
require_once MMM_ECI_PATH . 'admin/page-events.php';

// Register AJAX check-in handler — both logged-in and public (nopriv) for public scanner page
add_action('wp_ajax_mmm_checkin', 'mmm_handle_checkin');
add_action('wp_ajax_nopriv_mmm_checkin', 'mmm_handle_checkin');

function mmm_handle_checkin() {
    if (empty($_POST['data'])) {
        wp_send_json_error('❌ No QR code data received.');
    }

    $token = sanitize_text_field($_POST['data']);
    $user = MMM_QR_Generator::get_user_by_token($token);

    if (!$user) {
        wp_send_json_error('❌ Invalid QR code.');
    }

    $event_name = sanitize_text_field($_POST['event'] ?? get_option('mmm_current_event', 'Default Event'));
    $upload_dir = wp_upload_dir();
    $events_dir = trailingslashit($upload_dir['basedir']) . 'mmm-event-checkin/events';
    $filepath = trailingslashit($events_dir) . sanitize_title_with_dashes($event_name) . '.json';

    if (!file_exists($filepath)) {
        wp_send_json_error('❌ Event file not found.');
    }

    $event_data = json_decode(file_get_contents($filepath), true);

    // Ensure checkins is initialized
    if (!isset($event_data['checkins']) || !is_array($event_data['checkins'])) {
        $event_data['checkins'] = [];
    }

    foreach ($event_data['checkins'] as $entry) {
        if (!empty($entry['email']) && $entry['email'] === $user->user_email) {
            wp_send_json_error("❌ Already checked in as {$user->first_name}.");
        }
    }


    $custom = [
        'member_status'     => get_user_meta($user->ID, 'member_status', true),
        'bargaining_unit'   => get_user_meta($user->ID, 'bargaining_unit', true),
        'island'            => get_user_meta($user->ID, 'island', true),
        'unit_number'       => get_user_meta($user->ID, 'unit_number', true),
        'employer'          => get_user_meta($user->ID, 'employer', true),
        'jurisdiction'      => get_user_meta($user->ID, 'jurisdiction', true),
        'job_title'         => get_user_meta($user->ID, 'job_title', true),
        'baseyard'          => get_user_meta($user->ID, 'baseyard', true),
        'afscme_id'         => get_user_meta($user->ID, 'afscme_id', true),
    ];

    $event_data['checkins'][] = array_merge([
        'first_name' => $user->first_name,
        'last_name'  => $user->last_name,
        'email'      => $user->user_email,
        'time'       => date_i18n('g:ia, l, F j, Y'),
    ], $custom);

    $result = file_put_contents($filepath, json_encode($event_data), LOCK_EX);
    if ($result === false) {
        error_log("❌ Failed to write to file: $filepath");
        wp_send_json_error("❌ Could not save check-in.");
    }

    error_log("✅ Successfully saved check-in to $filepath");
    wp_send_json_success("✅ Welcome {$user->first_name}, you are now checked in.");
}


// Phone number normalization helper — strips non-digits, returns last 10
function mmm_normalize_phone( $phone ) {
    $digits = preg_replace( '/\D/', '', $phone );
    return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
}

// Phone search — finds matching guests in event guest list
add_action( 'wp_ajax_mmm_search_by_phone',        'mmm_search_by_phone' );
add_action( 'wp_ajax_nopriv_mmm_search_by_phone', 'mmm_search_by_phone' );

function mmm_search_by_phone() {
    $raw_digits = preg_replace( '/\D/', '', $_POST['phone'] ?? '' );
    $event_slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );

    if ( ! $raw_digits || ! $event_slug ) {
        wp_send_json_error( '❌ Missing phone or event.' );
    }

    // Resolve 7-digit local input → prepend default area code
    $used_area_code = null;
    if ( strlen( $raw_digits ) === 7 ) {
        $default_area   = preg_replace( '/\D/', '', get_option( 'mmm_default_area_code', '808' ) );
        $phone          = $default_area . $raw_digits;
        $used_area_code = $default_area;
    } else {
        $phone = strlen( $raw_digits ) >= 10 ? substr( $raw_digits, -10 ) : '';
    }

    if ( ! $phone ) {
        wp_send_json_error( '❌ Enter 7 digits (local) or all 10 digits including area code.' );
    }

    $upload_dir = wp_upload_dir();
    $filepath   = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/events/' . $event_slug . '.json';

    if ( ! file_exists( $filepath ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }

    $event_data = json_decode( file_get_contents( $filepath ), true );
    $guests     = $event_data['guests'] ?? [];

    $matches = [];
    foreach ( $guests as $idx => $guest ) {
        $guest_phone = mmm_normalize_phone( $guest['phone'] ?? '' );
        if ( $guest_phone && $guest_phone === $phone ) {
            $token     = hash_hmac( 'sha256', $event_slug . '|' . $idx . '|' . $phone, AUTH_KEY );
            $matches[] = [
                'idx'        => $idx,
                'name'       => trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) ),
                'token'      => $token,
                'full_phone' => $phone,  // always 10 digits — used by confirm step
            ];
        }
    }

    if ( empty( $matches ) ) {
        $msg = '❌ No guest found with that phone number.';
        if ( $used_area_code ) {
            $msg .= ' (searched with area code ' . esc_html( $used_area_code ) . ' — try typing all 10 digits if different area code)';
        }
        wp_send_json_error( $msg );
    }

    wp_send_json_success( $matches );
}

// Phone confirm — verifies HMAC token and writes check-in
add_action( 'wp_ajax_mmm_checkin_by_phone',        'mmm_checkin_by_phone' );
add_action( 'wp_ajax_nopriv_mmm_checkin_by_phone', 'mmm_checkin_by_phone' );

function mmm_checkin_by_phone() {
    $event_slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );
    $idx        = (int) ( $_POST['idx'] ?? -1 );
    $phone      = mmm_normalize_phone( $_POST['phone'] ?? '' );
    $token      = sanitize_text_field( $_POST['token'] ?? '' );

    if ( ! $event_slug || $idx < 0 || ! $phone || ! $token ) {
        wp_send_json_error( '❌ Missing required fields.' );
    }

    $expected = hash_hmac( 'sha256', $event_slug . '|' . $idx . '|' . $phone, AUTH_KEY );
    if ( ! hash_equals( $expected, $token ) ) {
        wp_send_json_error( '❌ Invalid confirmation token.' );
    }

    $upload_dir = wp_upload_dir();
    $filepath   = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/events/' . $event_slug . '.json';

    if ( ! file_exists( $filepath ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }

    $event_data = json_decode( file_get_contents( $filepath ), true );
    $guest      = $event_data['guests'][ $idx ] ?? null;

    if ( ! $guest ) {
        wp_send_json_error( '❌ Guest record not found.' );
    }

    if ( ! isset( $event_data['checkins'] ) || ! is_array( $event_data['checkins'] ) ) {
        $event_data['checkins'] = [];
    }

    foreach ( $event_data['checkins'] as $entry ) {
        if ( ! empty( $entry['phone'] ) && mmm_normalize_phone( $entry['phone'] ) === $phone ) {
            wp_send_json_error( "❌ {$guest['first_name']} is already checked in." );
        }
    }

    $event_data['checkins'][] = [
        'first_name'     => $guest['first_name']     ?? '',
        'last_name'      => $guest['last_name']       ?? '',
        'phone'          => $phone,
        'email'          => $guest['email']           ?? '',
        'bargaining_unit'=> $guest['bargaining_unit'] ?? '',
        'unit_number'    => $guest['unit_number']     ?? '',
        'employer'       => $guest['employer']        ?? '',
        'jurisdiction'   => $guest['jurisdiction']    ?? '',
        'job_title'      => $guest['job_title']       ?? '',
        'baseyard'       => $guest['baseyard']        ?? '',
        'island'         => $guest['island']          ?? '',
        'member_status'  => $guest['member_status']   ?? '',
        'afscme_id'      => $guest['afscme_id']       ?? '',
        'time'           => date_i18n( 'g:ia, l, F j, Y' ),
        'method'         => 'phone',
    ];

    if ( file_put_contents( $filepath, json_encode( $event_data ), LOCK_EX ) === false ) {
        wp_send_json_error( '❌ Could not save check-in.' );
    }

    wp_send_json_success( "✅ Welcome {$guest['first_name']}, you are now checked in." );
}

// ── Guest CSV upload — Step 1: save temp file, return headers ────
add_action( 'wp_ajax_mmm_preview_guest_csv', 'mmm_ajax_preview_guest_csv' );

function mmm_ajax_preview_guest_csv() {
    check_ajax_referer( 'mmm_upload_guests', 'mmm_guests_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $file = $_FILES['guest_csv'] ?? [];
    if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
        wp_send_json_error( 'No file received.' );
    }

    // Validate extension (client MIME type is spoofable)
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $ext !== 'csv' ) {
        wp_send_json_error( 'Only .csv files are accepted.' );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/tmp';
    wp_mkdir_p( $tmp_dir );

    // Block HTTP access to temp dir
    $htaccess = $tmp_dir . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Deny from all\n" );
    }

    // Clean up temp files older than 2 hours
    foreach ( glob( $tmp_dir . '/*.csv' ) ?: [] as $old ) {
        if ( filemtime( $old ) < time() - 7200 ) {
            unlink( $old );
        }
    }

    $temp_key  = bin2hex( random_bytes( 16 ) );
    $temp_path = $tmp_dir . '/' . $temp_key . '.csv';
    move_uploaded_file( $file['tmp_name'], $temp_path );

    // Verify actual file size after saving (client-reported size is not trusted)
    if ( filesize( $temp_path ) > 2 * 1024 * 1024 ) {
        unlink( $temp_path );
        wp_send_json_error( 'File too large. Maximum 2MB.' );
    }

    // Parse headers and count data rows
    $fh = fopen( $temp_path, 'r' );
    if ( ! $fh ) {
        unlink( $temp_path );
        wp_send_json_error( 'Could not read uploaded file.' );
    }
    $raw_headers = fgetcsv( $fh );
    $row_count   = 0;
    while ( fgetcsv( $fh ) !== false ) {
        $row_count++;
    }
    fclose( $fh );

    if ( empty( $raw_headers ) ) {
        unlink( $temp_path );
        wp_send_json_error( 'CSV appears to be empty or unreadable.' );
    }

    // Strip UTF-8 BOM from first header (present in Excel-exported CSVs)
    $raw_headers[0] = ltrim( $raw_headers[0], "\xEF\xBB\xBF" );

    $headers    = array_map( 'trim', $raw_headers );
    $normalized = array_map( 'strtolower', $headers );

    // Best guess: QR ID column
    $qr_guess = null;
    foreach ( [ 'afscme_id', 'afscme', 'member_id', 'qr_id', 'id' ] as $c ) {
        $i = array_search( $c, $normalized );
        if ( $i !== false ) { $qr_guess = $headers[ $i ]; break; }
    }

    // Best guess: phone column
    $phone_guess = null;
    foreach ( [ 'can2_phone', 'phone', 'phone_number', 'mobile', 'cell' ] as $c ) {
        $i = array_search( $c, $normalized );
        if ( $i !== false ) { $phone_guess = $headers[ $i ]; break; }
    }

    wp_send_json_success( [
        'temp_key'    => $temp_key,
        'headers'     => $headers,
        'row_count'   => $row_count,
        'qr_guess'    => $qr_guess,
        'phone_guess' => $phone_guess,
        'filename'    => sanitize_file_name( $file['name'] ),
    ] );
}

// ── Guest CSV upload — Step 2: import with user-confirmed column mapping ─
add_action( 'wp_ajax_mmm_import_guest_csv', 'mmm_ajax_import_guest_csv' );

function mmm_ajax_import_guest_csv() {
    check_ajax_referer( 'mmm_upload_guests', 'mmm_guests_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $temp_key   = preg_replace( '/[^a-f0-9]/', '', $_POST['temp_key']          ?? '' );
    $event_name = sanitize_text_field(          $_POST['guest_event_name']      ?? '' );
    $qr_col     = sanitize_text_field(          $_POST['qr_col']                ?? '' );
    $phone_col  = sanitize_text_field(          $_POST['phone_col']             ?? '' );

    if ( ! $temp_key || ! $event_name || ! $qr_col || ! $phone_col ) {
        wp_send_json_error( 'Missing required fields.' );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/tmp';
    $temp_path  = $tmp_dir . '/' . $temp_key . '.csv';

    if ( ! file_exists( $temp_path ) ) {
        wp_send_json_error( 'Upload session expired — please re-upload the file.' );
    }

    $events_dir = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/events';
    $guest_path = trailingslashit( $events_dir ) . sanitize_title_with_dashes( $event_name ) . '.json';

    if ( ! file_exists( $guest_path ) ) {
        unlink( $temp_path );
        wp_send_json_error( 'Event not found.' );
    }

    $fh2 = fopen( $temp_path, 'r' );
    if ( ! $fh2 ) {
        wp_send_json_error( 'Could not read upload session file.' );
    }
    // Strip UTF-8 BOM from first header
    $first_row = fgetcsv( $fh2 );
    $first_row[0] = ltrim( $first_row[0], "\xEF\xBB\xBF" );
    $rows = [ $first_row ];
    while ( ( $r = fgetcsv( $fh2 ) ) !== false ) {
        $rows[] = $r;
    }
    fclose( $fh2 );
    unlink( $temp_path );

    $headers = array_map( 'trim', $rows[0] );
    $norm    = array_map( 'strtolower', $headers );

    // Locate user-selected columns
    $qr_idx    = array_search( strtolower( trim( $qr_col ) ),    $norm );
    $phone_idx = array_search( strtolower( trim( $phone_col ) ), $norm );

    // Auto-detect supplementary columns
    $field_map = [
        'first_name'      => [ 'first_name', 'first' ],
        'last_name'       => [ 'last_name', 'last' ],
        'email'           => [ 'email', 'email_address' ],
        'member_status'   => [ 'member_status', 'status' ],
        'bargaining_unit' => [ 'bargaining_unit', 'unit' ],
        'unit_number'     => [ 'unit_number', 'unit_no' ],
        'employer'        => [ 'employer' ],
        'jurisdiction'    => [ 'jurisdiction' ],
        'job_title'       => [ 'job_title', 'title' ],
        'baseyard'        => [ 'baseyard', 'base_yard' ],
        'island'          => [ 'island' ],
    ];

    $col_idx = [];
    foreach ( $field_map as $canonical => $aliases ) {
        foreach ( $aliases as $alias ) {
            $pos = array_search( $alias, $norm );
            if ( $pos !== false ) { $col_idx[ $canonical ] = $pos; break; }
        }
    }

    $guests  = [];
    $skipped = 0;

    foreach ( array_slice( $rows, 1 ) as $row ) {
        if ( empty( array_filter( $row ) ) ) continue;

        $qr_val    = ( $qr_idx    !== false && isset( $row[ $qr_idx ] ) )    ? trim( $row[ $qr_idx ] )    : '';
        $phone_val = ( $phone_idx !== false && isset( $row[ $phone_idx ] ) ) ? trim( $row[ $phone_idx ] ) : '';

        // Skip rows missing both key identifier fields
        if ( $qr_val === '' && $phone_val === '' ) {
            $skipped++;
            continue;
        }

        $guest = [ 'qr_id' => $qr_val, 'phone' => $phone_val ];
        foreach ( $field_map as $canonical => $_ ) {
            $guest[ $canonical ] = isset( $col_idx[ $canonical ] )
                ? sanitize_text_field( $row[ $col_idx[ $canonical ] ] ?? '' )
                : '';
        }
        $guests[] = $guest;
    }

    $event_data           = json_decode( file_get_contents( $guest_path ), true );
    $event_data['guests'] = $guests;
    file_put_contents( $guest_path, json_encode( $event_data ), LOCK_EX );

    $msg = count( $guests ) . ' guests imported';
    if ( $skipped ) {
        $msg .= ' (' . $skipped . ' skipped — missing both QR ID and phone)';
    }
    wp_send_json_success( $msg . '.' );
}

// Load camera and QR scan scripts on check-in page
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_mmm_checkin') return;

    wp_enqueue_script('html5-qrcode', MMM_ECI_URL . 'assets/js/html5-qrcode.min.js', [], MMM_ECI_VERSION, true);
    wp_enqueue_script('mmm-qr-js', MMM_ECI_URL . 'assets/js/qr-scanner.js', ['jquery'], MMM_ECI_VERSION, true);

    wp_localize_script('mmm-qr-js', 'mmm_qr_ajax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'current_event' => get_option('mmm_current_event', ''),
        'success_audio' => plugin_dir_url(__FILE__) . 'assets/audio/success.mp3',
        'error_audio' => plugin_dir_url(__FILE__) . 'assets/audio/error.mp3',
    ]);
});


// Templates for Checkin Pages
add_filter('theme_page_templates', function ($templates) {
    $templates['public-event-scanner.php'] = 'Public Event Scanner';
    return $templates;
});

add_filter('template_include', function ($template) {
    if (is_page()) {
        $chosen_template = get_page_template_slug();
        if ($chosen_template === 'public-event-scanner.php') {
            return plugin_dir_path(__FILE__) . 'public-event-scanner.php';
        }
    }
    return $template;
});

add_filter('theme_page_templates', function ($templates) {
    $templates['checkin-result.php'] = 'Check-In Result';
    return $templates;
}, 11);

add_filter('template_include', function ($template) {
    if (is_page()) {
        $chosen_template = get_page_template_slug();
        if ($chosen_template === 'checkin-result.php') {
            return plugin_dir_path(__FILE__) . 'checkin-result.php';
        }
    }
    return $template;
}, 11);
