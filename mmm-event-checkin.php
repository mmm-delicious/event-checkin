<?php
/**
 * Plugin Name: MMM Event Check-In
 * Description: Generate QR codes for user check-in and manage events.
 * Version: 2.5.2
 * Author: MMM Delicious
 * Developer: Mark McDonnell
 * Text Domain: mmm-event-checkin
 */

defined('ABSPATH') || exit;

// Constants
define('MMM_ECI_VERSION', '1.0.6');
define('MMM_ECI_PATH', plugin_dir_path(__FILE__));
define('MMM_ECI_URL', plugin_dir_url(__FILE__));

// Load all necessary files
require_once MMM_ECI_PATH . 'includes/class-qr-generator.php';
require_once MMM_ECI_PATH . 'includes/shortcodes.php';
require_once MMM_ECI_PATH . 'admin/class-admin-menu.php';
require_once MMM_ECI_PATH . 'admin/page-events.php';

// Register AJAX check-in handler in main plugin file
add_action('wp_ajax_mmm_checkin', function () {
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
});


// Load camera and QR scan scripts on check-in page
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_mmm_checkin') return;

    wp_enqueue_script('html5-qrcode', 'https://unpkg.com/html5-qrcode', [], null, true);
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
