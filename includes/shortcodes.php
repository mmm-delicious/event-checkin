<?php
defined('ABSPATH') || exit;

function mmm_qr_shortcode() {
    if (!is_user_logged_in()) return 'Please log in to view your QR code.';
    $user_id = get_current_user_id();
    return MMM_QR_Generator::generate_user_qr($user_id);
}
add_shortcode('mmm_user_qr', 'mmm_qr_shortcode');

// Bust the token index whenever a user's afscme_id (or account) changes
add_action('updated_user_meta', function ($meta_id, $user_id, $meta_key) {
    if ($meta_key === 'afscme_id') delete_transient('mmm_token_index');
}, 10, 3);
add_action('user_register', function () { delete_transient('mmm_token_index'); });
add_action('delete_user',   function () { delete_transient('mmm_token_index'); });
