<?php
/**
 * Plugin Name: MMM Event Check-In
 * Description: Generate QR codes for user check-in and manage events.
 * Version: 3.1.0
 * Author: MMM Delicious
 * Developer: Mark McDonnell
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Text Domain: mmm-event-checkin
 */

defined('ABSPATH') || exit;

// Constants
define('MMM_ECI_VERSION', '3.1.0');
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

    $result = file_put_contents($filepath, json_encode($event_data));
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
    $phone      = mmm_normalize_phone( $_POST['phone'] ?? '' );
    $event_slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );

    if ( ! $phone || ! $event_slug ) {
        wp_send_json_error( '❌ Missing phone or event.' );
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
                'idx'   => $idx,
                'name'  => trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) ),
                'token' => $token,
            ];
        }
    }

    if ( empty( $matches ) ) {
        wp_send_json_error( '❌ No guest found with that phone number.' );
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

    if ( file_put_contents( $filepath, json_encode( $event_data ) ) === false ) {
        wp_send_json_error( '❌ Could not save check-in.' );
    }

    wp_send_json_success( "✅ Welcome {$guest['first_name']}, you are now checked in." );
}

// ── Guest CSV upload — shared logic ──────────────────────────────
function mmm_process_guest_upload( $file, $event_name, $events_dir ) {
    if ( empty( $file['tmp_name'] ) || empty( $event_name ) ) {
        return [ 'success' => false, 'message' => 'Missing file or event name.' ];
    }
    if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
        return [ 'success' => false, 'message' => 'Invalid file upload.' ];
    }
    if ( $file['size'] > 2 * 1024 * 1024 ) {
        return [ 'success' => false, 'message' => 'File too large. Maximum 2MB.' ];
    }

    $slug       = sanitize_title_with_dashes( $event_name );
    $guest_path = trailingslashit( $events_dir ) . $slug . '.json';

    if ( ! file_exists( $guest_path ) ) {
        return [ 'success' => false, 'message' => 'Event not found.' ];
    }

    $rows    = array_map( 'str_getcsv', file( $file['tmp_name'] ) );
    $headers = array_map( function( $h ) { return strtolower( trim( str_replace( ' ', '_', $h ) ) ); }, $rows[0] );

    $field_map = [
        'first_name'      => [ 'first_name', 'first' ],
        'last_name'       => [ 'last_name', 'last' ],
        'phone'           => [ 'can2_phone', 'phone', 'phone_number', 'mobile', 'cell' ],
        'email'           => [ 'email', 'email_address' ],
        'member_status'   => [ 'member_status', 'status' ],
        'bargaining_unit' => [ 'bargaining_unit', 'unit' ],
        'unit_number'     => [ 'unit_number', 'unit_no' ],
        'employer'        => [ 'employer' ],
        'jurisdiction'    => [ 'jurisdiction' ],
        'job_title'       => [ 'job_title', 'title' ],
        'baseyard'        => [ 'baseyard', 'base_yard' ],
        'island'          => [ 'island' ],
        'afscme_id'       => [ 'afscme_id', 'afscme' ],
    ];

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

    $event_data           = json_decode( file_get_contents( $guest_path ), true );
    $event_data['guests'] = $guests;
    file_put_contents( $guest_path, json_encode( $event_data ) );

    return [ 'success' => true, 'message' => count( $guests ) . ' guests imported for ' . $event_name . '.', 'count' => count( $guests ) ];
}

// AJAX endpoint — handles multipart upload from JS fetch(), bypassing redirect issues
add_action( 'wp_ajax_mmm_upload_guests', 'mmm_ajax_upload_guests' );

function mmm_ajax_upload_guests() {
    check_ajax_referer( 'mmm_upload_guests', 'mmm_guests_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $upload_dir = wp_upload_dir();
    $events_dir = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/events';
    $result     = mmm_process_guest_upload(
        $_FILES['guest_csv'] ?? [],
        sanitize_text_field( $_POST['guest_event_name'] ?? '' ),
        $events_dir
    );

    if ( $result['success'] ) {
        wp_send_json_success( $result['message'] );
    } else {
        wp_send_json_error( $result['message'] );
    }
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
