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

    /**
     * Build (or return cached) token → user_id index.
     * Accepts N+1 on first build; subsequent calls within the TTL are O(1).
     * Invalidate by deleting the 'mmm_token_index' transient (e.g. after user import).
     */
    private static function get_token_index()
    {
        $cached = get_transient('mmm_token_index');
        if ($cached !== false) return $cached;

        $users = get_users(['fields' => ['ID', 'user_login']]);
        $index = [];
        foreach ($users as $u) {
            $afscme_id = get_user_meta($u->ID, 'afscme_id', true);
            $token = !empty($afscme_id)
                ? hash('sha256', 'afscme:' . $afscme_id)
                : hash('sha256', $u->ID . '|' . $u->user_login);
            $index[$token] = $u->ID;
        }

        set_transient('mmm_token_index', $index, HOUR_IN_SECONDS);
        return $index;
    }

    public static function get_user_by_token($token)
    {
        $index = self::get_token_index();
        if (!isset($index[$token])) return null;
        return get_user_by('id', $index[$token]);
    }
}
