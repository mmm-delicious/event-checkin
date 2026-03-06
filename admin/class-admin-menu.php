<?php
defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . 'page-events.php';
require_once plugin_dir_path(__FILE__) . 'admin-view-checkins.php';
require_once plugin_dir_path(__FILE__) . 'page-guest-list.php';


class MMM_Admin_Menu {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_plugin_pages']);
    }

    public static function add_plugin_pages() {
        // Top-level menu
        add_menu_page(
            'Event Check-In',           // Page title
            'Event Check-In',           // Menu label
            'manage_options',
            'mmm_checkin',
            'mmm_render_event_list',    // Function from page-events.php
            'dashicons-tickets-alt',
            30
        );

        // Submenu for live check-ins
        add_submenu_page(
            'mmm_checkin',
            'Check-In Monitor',
            'Check-In Monitor',
            'manage_options',
            'mmm_view_checkins',
            'mmm_render_checkin_view_page'
        );

        // Submenu for guest list + manual check-in
        add_submenu_page(
            'mmm_checkin',
            'Guest List',
            'Guest List',
            'manage_options',
            'mmm_guest_list',
            'mmm_render_guest_list_page'
        );
    }
}

MMM_Admin_Menu::init();
