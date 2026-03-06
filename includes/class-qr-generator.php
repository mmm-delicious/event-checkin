<?php
defined('ABSPATH') || exit;

class MMM_QR_Generator
{
    public static function generate_user_qr($user_id)
    {
        $user = get_user_by('id', $user_id);
        if (!$user) return '';

        // Prefer afscme_id if available
        $afscme_id = get_user_meta($user_id, 'afscme_id', true);
        $token_source = !empty($afscme_id) ? 'afscme:' . $afscme_id : $user->ID . '|' . $user->user_login;
        $token = hash('sha256', $token_source);

        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($token) . '&size=200x200';
        return '<img src="' . esc_url($qr_url) . '" alt="QR Code" />';
    }

    public static function get_user_by_token($token)
    {
        $users = get_users();
        foreach ($users as $user) {
            $afscme_id = get_user_meta($user->ID, 'afscme_id', true);

            // Match against afscme_id if present
            if (!empty($afscme_id)) {
                $expected = hash('sha256', 'afscme:' . $afscme_id);
                if (hash_equals($expected, $token)) return $user;
            }

            // Fallback to ID|login
            $fallback = hash('sha256', $user->ID . '|' . $user->user_login);
            if (hash_equals($fallback, $token)) return $user;
        }
        return null;
    }
}
