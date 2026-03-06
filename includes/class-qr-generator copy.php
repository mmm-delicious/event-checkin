<?php
class MMM_QR_Generator
{
    public static function generate_user_qr($user_id)
    {
        $user = get_user_by('id', $user_id);
        if (!$user) return '';

        // Stable, unique, secure hash
        if (!$user)
        $token = hash('sha256', $user->ID . '|' . $user->user_login);
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($token) . '&size=200x200';
        return '<img src="' . esc_url($qr_url) . '" alt="QR Code" />';
    }

    public static function get_user_by_token($token)
    {
        $users = get_users();
        foreach ($users as $user) {
            $expected = hash('sha256', $user->ID . '|' . $user->user_login);
            if (hash_equals($expected, $token)) {
                return $user;
            }
        }
        return null;
    }
}
