<?php
defined('ABSPATH') || exit;

function mmm_qr_shortcode() {
    if (!is_user_logged_in()) return 'Please log in to view your QR code.';
    $user_id = get_current_user_id();
    return MMM_QR_Generator::generate_user_qr($user_id);
}
add_shortcode('mmm_user_qr', 'mmm_qr_shortcode');
