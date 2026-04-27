<?php
/**
 * Plugin Name: Event Check-In
 * Description: Generate QR codes for user check-in and manage events.
 * Version: 3.16.3
 * Author: MMM Delicious
 * Developer: Mark McDonnell
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Text Domain: event-checkin
 */

defined('ABSPATH') || exit;

// Auto-updates via GitHub (checks every 48h to minimise plugins-page latency)
require_once plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
$mmm_eci_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/mmm-delicious/event-checkin/',
    __FILE__,
    'event-checkin'
);
$mmm_eci_updater->setBranch('main');
$mmm_eci_updater->scheduler->checkPeriod = 48; // setCheckPeriod() not available in bundled PUC v5p6

// Constants
define('MMM_ECI_VERSION', '3.16.3');
define('MMM_ECI_PATH', plugin_dir_path(__FILE__));
define('MMM_ECI_URL', plugin_dir_url(__FILE__));

// Load all necessary files
require_once MMM_ECI_PATH . 'includes/class-qr-generator.php';
require_once MMM_ECI_PATH . 'includes/shortcodes.php';
require_once MMM_ECI_PATH . 'admin/class-admin-menu.php';
require_once MMM_ECI_PATH . 'admin/page-events.php';

// ────────────────────────────────────────────────────────────────────────────
// FILE SPLIT HELPERS
// Each event is stored as three small files instead of one large combined file:
//   {slug}-meta.json     — name, created_at, guest_count  (tiny; used for dropdowns)
//   {slug}-guests.json   — { guests: [...] }              (write-once at import)
//   {slug}-checkins.json — { checkins: [...] }            (append-only at runtime)
// Legacy {slug}.json files are migrated transparently on first access.
// ────────────────────────────────────────────────────────────────────────────

function mmm_events_dir() {
    static $dir = null;
    if ( $dir === null ) {
        $up  = wp_upload_dir();
        $dir = trailingslashit( $up['basedir'] ) . 'mmm-event-checkin/events';
    }
    return $dir;
}

function mmm_event_paths( $slug ) {
    $base = trailingslashit( mmm_events_dir() ) . $slug;
    return [
        'meta'     => $base . '-meta.json',
        'guests'   => $base . '-guests.json',
        'checkins' => $base . '-checkins.json',
        'legacy'   => $base . '.json',
    ];
}

/**
 * One-time migration: split the old {slug}.json into three separate files.
 * Adds guest_idx to existing checkin entries so the poll endpoint never needs
 * to load the (large) guests file again.
 */
function mmm_migrate_event( $slug ) {
    $p = mmm_event_paths( $slug );
    if ( file_exists( $p['meta'] ) && file_exists( $p['guests'] ) && file_exists( $p['checkins'] ) ) {
        return true;
    }
    if ( ! file_exists( $p['legacy'] ) ) {
        return false;
    }

    $data     = json_decode( file_get_contents( $p['legacy'] ), true ) ?: [];
    $guests   = $data['guests']   ?? [];
    $checkins = $data['checkins'] ?? [];

    // Build reverse lookup to add guest_idx to existing checkin entries
    $idx_by_id   = [];
    $idx_by_name = [];
    foreach ( $guests as $idx => $g ) {
        $aid = strtolower( trim( $g['qr_id'] ?? '' ) );
        if ( $aid ) $idx_by_id[ $aid ] = $idx;
        $nm = strtolower( trim( ( $g['first_name'] ?? '' ) . ' ' . ( $g['last_name'] ?? '' ) ) );
        if ( $nm ) $idx_by_name[ $nm ] = $idx;
    }
    foreach ( $checkins as &$ci ) {
        if ( isset( $ci['guest_idx'] ) ) continue;
        $aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
        $nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
        if ( $aid && isset( $idx_by_id[ $aid ] ) ) {
            $ci['guest_idx'] = $idx_by_id[ $aid ];
        } elseif ( $nm && isset( $idx_by_name[ $nm ] ) ) {
            $ci['guest_idx'] = $idx_by_name[ $nm ];
        }
    }
    unset( $ci );

    if ( ! file_exists( $p['guests'] ) ) {
        file_put_contents( $p['guests'], json_encode( [ 'guests' => $guests ] ), LOCK_EX );
    }
    if ( ! file_exists( $p['checkins'] ) ) {
        file_put_contents( $p['checkins'], json_encode( [ 'checkins' => $checkins ] ), LOCK_EX );
    }
    if ( ! file_exists( $p['meta'] ) ) {
        file_put_contents( $p['meta'], json_encode( [
            'name'        => $data['name']       ?? $slug,
            'created_at'  => $data['created_at'] ?? current_time( 'mysql' ),
            'guest_count' => count( $guests ),
        ] ), LOCK_EX );
    }
    return true;
}

function mmm_event_exists( $slug ) {
    $p = mmm_event_paths( $slug );
    return file_exists( $p['meta'] ) || file_exists( $p['legacy'] );
}

function mmm_load_meta( $slug ) {
    mmm_migrate_event( $slug );
    $p = mmm_event_paths( $slug );
    if ( ! file_exists( $p['meta'] ) ) return null;
    return json_decode( file_get_contents( $p['meta'] ), true ) ?: null;
}

function mmm_save_meta( $slug, $meta ) {
    $p = mmm_event_paths( $slug );
    return file_put_contents( $p['meta'], json_encode( $meta ), LOCK_EX );
}

function mmm_load_guests( $slug ) {
    mmm_migrate_event( $slug );
    $p = mmm_event_paths( $slug );
    if ( ! file_exists( $p['guests'] ) ) return [];
    $d = json_decode( file_get_contents( $p['guests'] ), true );
    return $d['guests'] ?? [];
}

function mmm_save_guests( $slug, $guests ) {
    $p = mmm_event_paths( $slug );
    return file_put_contents( $p['guests'], json_encode( [ 'guests' => $guests ] ), LOCK_EX );
}

function mmm_load_checkins( $slug ) {
    mmm_migrate_event( $slug );
    $p = mmm_event_paths( $slug );
    if ( ! file_exists( $p['checkins'] ) ) return [];
    $d = json_decode( file_get_contents( $p['checkins'] ), true );
    return $d['checkins'] ?? [];
}

function mmm_save_checkins( $slug, $checkins ) {
    $p = mmm_event_paths( $slug );
    return file_put_contents( $p['checkins'], json_encode( [ 'checkins' => $checkins ] ), LOCK_EX );
}

/**
 * Atomically read → modify → write the checkins file under an exclusive lock.
 * Prevents race conditions when two check-ins arrive simultaneously.
 *
 * @param string   $slug      Event slug.
 * @param callable $modifier  Receives the checkins array; must return the modified array.
 *                             May call wp_send_json_error() (which exits) for validation failures.
 */
function mmm_locked_checkins_update( $slug, $modifier ) {
    $p  = mmm_event_paths( $slug );
    $fp = fopen( $p['checkins'], 'c+' );
    if ( ! $fp ) {
        wp_send_json_error( '❌ Could not open checkins file.' );
    }
    if ( ! flock( $fp, LOCK_EX ) ) {
        fclose( $fp );
        wp_send_json_error( '❌ Could not lock checkins file.' );
    }
    $raw      = stream_get_contents( $fp );
    $data     = json_decode( $raw, true );
    $checkins = ( is_array( $data ) && isset( $data['checkins'] ) ) ? $data['checkins'] : [];
    $checkins = $modifier( $checkins );
    ftruncate( $fp, 0 );
    rewind( $fp );
    fwrite( $fp, json_encode( [ 'checkins' => $checkins ] ) );
    flock( $fp, LOCK_UN );
    fclose( $fp );
}

/**
 * Validate event slug format — defense-in-depth beyond sanitize_title_with_dashes().
 */
function mmm_validate_slug( $slug ) {
    return $slug !== '' && preg_match( '/^[a-z0-9_-]+$/', $slug );
}


// ────────────────────────────────────────────────────────────────────────────
// QR CHECK-IN (WP user path — reads/writes checkins file only)
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_checkin', 'mmm_handle_checkin' );
add_action( 'wp_ajax_nopriv_mmm_checkin', 'mmm_handle_checkin' );

function mmm_handle_checkin() {
    if ( empty( $_POST['data'] ) ) {
        wp_send_json_error( '❌ No QR code data received.' );
    }

    $token      = sanitize_text_field( $_POST['data'] );
    $event_name = sanitize_text_field( $_POST['event'] ?? get_option( 'mmm_current_event', 'Default Event' ) );
    $slug       = sanitize_title_with_dashes( $event_name );

    // ── Path A: plugin-generated QR code (SHA-256 token → WP user) ───────────
    $user = MMM_QR_Generator::get_user_by_token( $token );
    if ( $user ) {
        if ( ! mmm_validate_slug( $slug ) || ! mmm_event_exists( $slug ) ) {
            wp_send_json_error( '❌ Event file not found.' );
        }
        $all_meta  = get_user_meta( $user->ID );
        $new_entry = [
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'email'           => $user->user_email,
            'afscme_id'       => $all_meta['afscme_id'][0]       ?? '',
            'member_status'   => $all_meta['member_status'][0]   ?? '',
            'bargaining_unit' => $all_meta['bargaining_unit'][0] ?? '',
            'unit_number'     => $all_meta['unit_number'][0]     ?? '',
            'employer'        => $all_meta['employer'][0]        ?? '',
            'jurisdiction'    => $all_meta['jurisdiction'][0]    ?? '',
            'job_title'       => $all_meta['job_title'][0]       ?? '',
            'baseyard'        => $all_meta['baseyard'][0]        ?? '',
            'island'          => $all_meta['island'][0]          ?? '',
            'time'            => date_i18n( 'g:ia, l, F j, Y' ),
            'method'          => 'qr',
        ];
        mmm_locked_checkins_update( $slug, function ( $checkins ) use ( $user, $new_entry ) {
            foreach ( $checkins as $entry ) {
                if ( ! empty( $entry['email'] ) && $entry['email'] === $user->user_email ) {
                    wp_send_json_error( "❌ Already checked in as {$user->first_name}." );
                }
            }
            $checkins[] = $new_entry;
            return $checkins;
        } );
        wp_send_json_success( "✅ Welcome {$user->first_name}, you are now checked in." );
    }

    // ── Path B: AFSCME card barcode → guest list lookup by qr_id ─────────────
    if ( ! mmm_validate_slug( $slug ) || ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( '❌ Invalid QR code.' );
    }

    $qr_lower   = strtolower( trim( $token ) );
    $index      = mmm_get_qr_index( $slug );
    if ( ! isset( $index[ $qr_lower ] ) ) {
        wp_send_json_error( '❌ Member not found.' );
    }

    $entry_data = $index[ $qr_lower ];
    $guest_idx  = $entry_data['idx'];
    $guest      = $entry_data['guest'];
    $name       = trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) );

    mmm_locked_checkins_update( $slug, function ( $checkins ) use ( $guest, $guest_idx, $name ) {
        foreach ( $checkins as $ci ) {
            if ( isset( $ci['guest_idx'] ) && (int) $ci['guest_idx'] === $guest_idx ) {
                wp_send_json_error( "❌ Already checked in as {$name}." );
            }
        }
        $checkins[] = [
            'guest_idx'       => $guest_idx,
            'first_name'      => $guest['first_name']      ?? '',
            'last_name'       => $guest['last_name']       ?? '',
            'email'           => $guest['email']           ?? '',
            'afscme_id'       => $guest['qr_id']           ?? '',
            'phone'           => $guest['phone']           ?? '',
            'member_status'   => $guest['member_status']   ?? '',
            'bargaining_unit' => $guest['bargaining_unit'] ?? '',
            'unit_number'     => $guest['unit_number']     ?? '',
            'employer'        => $guest['employer']        ?? '',
            'jurisdiction'    => $guest['jurisdiction']    ?? '',
            'baseyard'        => $guest['baseyard']        ?? '',
            'island'          => $guest['island']          ?? '',
            'time'            => date_i18n( 'g:ia, l, F j, Y' ),
            'method'          => 'qr',
        ];
        return $checkins;
    } );

    wp_send_json_success( "✅ Welcome {$name}, you are now checked in." );
}

// ────────────────────────────────────────────────────────────────────────────
// PHONE SEARCH — reads guests file only via indexed cache
// ────────────────────────────────────────────────────────────────────────────

function mmm_normalize_phone( $phone ) {
    $digits = preg_replace( '/\D/', '', $phone );
    return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
}

function mmm_get_phone_index( $slug ) {
    $cache_key = 'mmm_pi_' . $slug;
    $index     = get_transient( $cache_key );
    if ( $index !== false ) {
        return $index;
    }

    $guests = mmm_load_guests( $slug );
    $index  = [];
    foreach ( $guests as $idx => $guest ) {
        $phone = mmm_normalize_phone( $guest['phone'] ?? '' );
        if ( ! $phone ) continue;
        $index[ $phone ][] = [
            'idx'       => $idx,
            'name'      => trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) ),
            'has_email' => ! empty( $guest['email'] ),
        ];
    }

    set_transient( $cache_key, $index, 12 * HOUR_IN_SECONDS );
    return $index;
}

function mmm_get_qr_index( $slug ) {
    $cache_key = 'mmm_qi_' . $slug;
    $index     = get_transient( $cache_key );
    if ( $index !== false ) {
        return $index;
    }

    $guests = mmm_load_guests( $slug );
    $index  = [];
    foreach ( $guests as $idx => $guest ) {
        $qr = strtolower( trim( $guest['qr_id'] ?? '' ) );
        if ( $qr ) $index[ $qr ] = [ 'idx' => $idx, 'guest' => $guest ];
    }

    set_transient( $cache_key, $index, 12 * HOUR_IN_SECONDS );
    return $index;
}

add_action( 'wp_ajax_mmm_search_by_phone', 'mmm_search_by_phone' );
add_action( 'wp_ajax_nopriv_mmm_search_by_phone', 'mmm_search_by_phone' );

function mmm_search_by_phone() {
    $raw_digits = preg_replace( '/\D/', '', $_POST['phone'] ?? '' );
    $event_slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );

    if ( ! $raw_digits || ! $event_slug ) {
        wp_send_json_error( '❌ Missing phone or event.' );
    }

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

    if ( ! mmm_event_exists( $event_slug ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }

    $index   = mmm_get_phone_index( $event_slug );
    $entries = $index[ $phone ] ?? [];

    $matches = [];
    foreach ( $entries as $entry ) {
        $idx       = $entry['idx'];
        $token     = hash_hmac( 'sha256', $event_slug . '|' . $idx . '|' . $phone, AUTH_KEY );
        $matches[] = [
            'idx'        => $idx,
            'name'       => $entry['name'],
            'token'      => $token,
            'full_phone' => $phone,
            'missing'    => ( $entry['has_email'] ?? true ) ? [] : [ 'email' ],
        ];
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

// ────────────────────────────────────────────────────────────────────────────
// PHONE CONFIRM CHECK-IN — reads guest from guests file, writes checkins only
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_checkin_by_phone', 'mmm_checkin_by_phone' );
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

    if ( ! mmm_validate_slug( $event_slug ) || ! mmm_event_exists( $event_slug ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }

    $guests = mmm_load_guests( $event_slug );
    $guest  = $guests[ $idx ] ?? null;
    if ( ! $guest ) {
        wp_send_json_error( '❌ Guest record not found.' );
    }

    $new_entry = [
        'guest_idx'       => $idx,
        'first_name'      => $guest['first_name']      ?? '',
        'last_name'       => $guest['last_name']        ?? '',
        'phone'           => $phone,
        'email'           => $guest['email']            ?? '',
        'afscme_id'       => $guest['qr_id']            ?? '',
        'bargaining_unit' => $guest['bargaining_unit']  ?? '',
        'unit_number'     => $guest['unit_number']      ?? '',
        'employer'        => $guest['employer']         ?? '',
        'jurisdiction'    => $guest['jurisdiction']     ?? '',
        'job_title'       => $guest['job_title']        ?? '',
        'baseyard'        => $guest['baseyard']         ?? '',
        'island'          => $guest['island']           ?? '',
        'member_status'   => $guest['member_status']    ?? '',
        'time'            => date_i18n( 'g:ia, l, F j, Y' ),
        'method'          => 'phone',
    ];

    mmm_locked_checkins_update( $event_slug, function ( $checkins ) use ( $guest, $phone, $new_entry ) {
        foreach ( $checkins as $entry ) {
            if ( ! empty( $entry['phone'] ) && mmm_normalize_phone( $entry['phone'] ) === $phone ) {
                wp_send_json_error( "❌ {$guest['first_name']} is already checked in." );
            }
        }
        $checkins[] = $new_entry;
        return $checkins;
    } );

    wp_send_json_success( "✅ Welcome {$guest['first_name']}, you are now checked in." );
}

// ────────────────────────────────────────────────────────────────────────────
// DRIVER'S LICENSE (AAMVA PDF417) CHECK-IN
// ────────────────────────────────────────────────────────────────────────────

/**
 * Normalize a name string for index matching.
 * Lowercases, strips non-alpha except hyphen/apostrophe/space, collapses whitespace.
 * Returns '' if the result is fewer than 2 characters.
 */
function mmm_normalize_name( $raw ) {
    $s = strtolower( trim( sanitize_text_field( $raw ) ) );
    $s = preg_replace( '/[^a-z\-\' ]/', '', $s );
    $s = preg_replace( '/\s+/', ' ', trim( $s ) );
    return strlen( $s ) >= 2 ? $s : '';
}

/**
 * Normalize a date-of-birth string to YYYY-MM-DD.
 * Accepts: MMDDYYYY (AAMVA raw 8-digit), M/D/YY, MM/DD/YYYY, YYYY-MM-DD.
 * 2-digit years: 70–99 → 1970–1999, 00–69 → 2000–2069 (PHP default).
 * Returns '' for unparseable or implausible dates.
 */
function mmm_normalize_dob( $raw ) {
    $raw = trim( $raw );
    if ( $raw === '' ) return '';

    $ts = false;

    if ( preg_match( '/^\d{8}$/', $raw ) ) {
        // AAMVA raw: MMDDYYYY
        $ts = mktime( 0, 0, 0, (int) substr( $raw, 0, 2 ), (int) substr( $raw, 2, 2 ), (int) substr( $raw, 4, 4 ) );
    } elseif ( preg_match( '/^\d{1,2}\/\d{1,2}\/\d{2}$/', $raw ) ) {
        // M/D/YY — common export format (e.g. 9/5/75)
        $dt = DateTime::createFromFormat( 'n/j/y', $raw );
        if ( $dt ) $ts = $dt->getTimestamp();
    } elseif ( preg_match( '/^\d{1,2}\/\d{1,2}\/\d{4}$/', $raw ) ) {
        // M/D/YYYY or MM/DD/YYYY
        $dt = DateTime::createFromFormat( 'n/j/Y', $raw );
        if ( $dt ) $ts = $dt->getTimestamp();
    } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
        // ISO 8601: YYYY-MM-DD
        $ts = strtotime( $raw );
    }

    if ( $ts === false || $ts <= 0 ) return '';

    $year = (int) date( 'Y', $ts );
    $now  = (int) date( 'Y' );
    if ( $year < 1924 || $year > $now - 15 ) return '';

    return date( 'Y-m-d', $ts );
}

/**
 * Build and cache the DL check-in index for one event.
 *
 * Index structure (two sub-arrays):
 *   dob[]  keyed by SHA256(dob_iso) — each key => array of [ 'idx', 'guest', 'ln' ]
 *   name[] keyed by "norm_first|norm_last" — each key => array of [ 'idx', 'guest' ]
 */
function mmm_get_dl_index( $slug ) {
    $cache_key = 'mmm_dli_' . $slug;
    $index     = get_transient( $cache_key );
    if ( $index !== false ) {
        return $index;
    }

    $guests = mmm_load_guests( $slug );
    if ( ! is_array( $guests ) ) {
        return [ 'dob' => [], 'name' => [] ];
    }

    $dob_idx  = [];
    $name_idx = [];

    foreach ( $guests as $idx => $guest ) {
        $dob_hash = $guest['dob_hash'] ?? '';
        $fn       = mmm_normalize_name( $guest['first_name'] ?? '' );
        $ln       = mmm_normalize_name( $guest['last_name']  ?? '' );

        if ( $dob_hash && strlen( $dob_hash ) === 64 ) {
            $dob_idx[ $dob_hash ][] = [ 'idx' => $idx, 'guest' => $guest, 'ln' => $ln ];
        }
        if ( $fn && $ln ) {
            $name_idx[ $fn . '|' . $ln ][] = [ 'idx' => $idx, 'guest' => $guest ];
        }
    }

    $index = [ 'dob' => $dob_idx, 'name' => $name_idx ];
    set_transient( $cache_key, $index, 12 * HOUR_IN_SECONDS );
    return $index;
}

/**
 * Transient-based rate limiter for the DL endpoint.
 * Returns true if the request is allowed; false if limit exceeded.
 * Max 10 attempts per IP per 10 minutes.
 */
function mmm_dl_check_rate_limit() {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key   = 'mmm_dlrl_' . md5( $ip );
    $count = (int) get_transient( $key );
    if ( $count >= 10 ) {
        return false;
    }
    set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
    return true;
}

// ── Step 1: Search by DL data — returns candidate + HMAC token ───────────────

add_action( 'wp_ajax_mmm_checkin_by_dl',        'mmm_ajax_checkin_by_dl' );
add_action( 'wp_ajax_nopriv_mmm_checkin_by_dl', 'mmm_ajax_checkin_by_dl' );

function mmm_ajax_checkin_by_dl() {
    if ( ! mmm_dl_check_rate_limit() ) {
        wp_send_json_error( '❌ Too many attempts. Please wait a few minutes.' );
    }

    $event_slug = sanitize_title_with_dashes( $_POST['event']      ?? '' );
    $dob_hash   = preg_replace( '/[^a-f0-9]/', '', strtolower( $_POST['dob_hash']   ?? '' ) );
    $last_name  = mmm_normalize_name( $_POST['last_name']  ?? '' );
    $first_name = mmm_normalize_name( $_POST['first_name'] ?? '' );

    if ( ! $event_slug ) {
        wp_send_json_error( '❌ Missing event.' );
    }
    if ( ! mmm_validate_slug( $event_slug ) || ! mmm_event_exists( $event_slug ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }
    if ( ! $last_name ) {
        wp_send_json_error( '❌ License unreadable — try phone entry.' );
    }

    $has_dob = ( $dob_hash && strlen( $dob_hash ) === 64 );
    $index   = mmm_get_dl_index( $event_slug );

    if ( ! isset( $index['dob'] ) ) {
        wp_send_json_error( '❌ Guest data unavailable.' );
    }

    // ── Tier 1: DOB hash + last name ─────────────────────────────────────────
    if ( $has_dob && ! empty( $index['dob'][ $dob_hash ] ) ) {
        $candidates = array_values( array_filter( $index['dob'][ $dob_hash ], function ( $e ) use ( $last_name ) {
            return $e['ln'] === $last_name;
        } ) );

        if ( ! empty( $candidates ) ) {
            $window  = floor( time() / 300 );
            $matches = [];
            foreach ( $candidates as $entry ) {
                $idx  = $entry['idx'];
                $name = trim( ( $entry['guest']['first_name'] ?? '' ) . ' ' . ( $entry['guest']['last_name'] ?? '' ) );
                $matches[] = [
                    'idx'      => $idx,
                    'name'     => $name,
                    'tier'     => 1,
                    'dob_hash' => $dob_hash,
                    'token'    => hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . $dob_hash . '|' . $window, AUTH_KEY ),
                    'missing'  => array_values( array_filter( [
                        empty( $entry['guest']['phone'] ) ? 'phone' : null,
                        empty( $entry['guest']['email'] ) ? 'email' : null,
                    ] ) ),
                ];
            }
            wp_send_json_success( $matches );
        }
    }

    // ── Tier 2: first + last name ─────────────────────────────────────────────
    if ( ! $first_name ) {
        wp_send_json_error( '❌ Not found on guest list — try phone entry.' );
    }

    $name_key   = $first_name . '|' . $last_name;
    $candidates = $index['name'][ $name_key ] ?? [];

    if ( empty( $candidates ) ) {
        wp_send_json_error( '❌ Not found on guest list — try phone entry.' );
    }

    $window  = floor( time() / 300 );
    $matches = [];
    foreach ( $candidates as $entry ) {
        $idx  = $entry['idx'];
        $name = trim( ( $entry['guest']['first_name'] ?? '' ) . ' ' . ( $entry['guest']['last_name'] ?? '' ) );
        $matches[] = [
            'idx'      => $idx,
            'name'     => $name,
            'tier'     => 2,
            'dob_hash' => '',
            'token'    => hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . '' . '|' . $window, AUTH_KEY ),
            'missing'  => array_values( array_filter( [
                empty( $entry['guest']['phone'] ) ? 'phone' : null,
                empty( $entry['guest']['email'] ) ? 'email' : null,
            ] ) ),
        ];
    }
    wp_send_json_success( $matches );
}

// ── Step 2: Confirm DL check-in — verifies HMAC + writes checkins file ───────

add_action( 'wp_ajax_mmm_confirm_dl_checkin',        'mmm_ajax_confirm_dl_checkin' );
add_action( 'wp_ajax_nopriv_mmm_confirm_dl_checkin', 'mmm_ajax_confirm_dl_checkin' );

function mmm_ajax_confirm_dl_checkin() {
    $event_slug = sanitize_title_with_dashes( $_POST['event']    ?? '' );
    $idx        = (int) ( $_POST['idx']                          ?? -1 );
    $dob_hash   = preg_replace( '/[^a-f0-9]/', '', strtolower( $_POST['dob_hash'] ?? '' ) );
    $token      = sanitize_text_field( $_POST['token']           ?? '' );

    if ( ! $event_slug || $idx < 0 || ! $token ) {
        wp_send_json_error( '❌ Missing required fields.' );
    }

    // Accept current and previous 5-min window to avoid boundary failures
    $window    = floor( time() / 300 );
    $expected1 = hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . $dob_hash . '|' . $window,           AUTH_KEY );
    $expected2 = hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . $dob_hash . '|' . ( $window - 1 ),   AUTH_KEY );

    if ( ! hash_equals( $expected1, $token ) && ! hash_equals( $expected2, $token ) ) {
        wp_send_json_error( '❌ Session expired — scan again.' );
    }

    if ( ! mmm_validate_slug( $event_slug ) || ! mmm_event_exists( $event_slug ) ) {
        wp_send_json_error( '❌ Event not found.' );
    }

    $guests = mmm_load_guests( $event_slug );
    $guest  = $guests[ $idx ] ?? null;
    if ( ! $guest ) {
        wp_send_json_error( '❌ Guest record not found.' );
    }

    $new_entry = [
        'guest_idx'       => $idx,
        'first_name'      => $guest['first_name']      ?? '',
        'last_name'       => $guest['last_name']        ?? '',
        'phone'           => $guest['phone']            ?? '',
        'email'           => $guest['email']            ?? '',
        'afscme_id'       => $guest['qr_id']            ?? '',
        'bargaining_unit' => $guest['bargaining_unit']  ?? '',
        'unit_number'     => $guest['unit_number']      ?? '',
        'employer'        => $guest['employer']         ?? '',
        'jurisdiction'    => $guest['jurisdiction']     ?? '',
        'job_title'       => $guest['job_title']        ?? '',
        'baseyard'        => $guest['baseyard']         ?? '',
        'island'          => $guest['island']           ?? '',
        'member_status'   => $guest['member_status']    ?? '',
        'time'            => date_i18n( 'g:ia, l, F j, Y' ),
        'method'          => 'dl',
    ];

    mmm_locked_checkins_update( $event_slug, function ( $checkins ) use ( $idx, $guest, $new_entry ) {
        foreach ( $checkins as $ci ) {
            if ( isset( $ci['guest_idx'] ) && (int) $ci['guest_idx'] === $idx ) {
                wp_send_json_error( "❌ {$guest['first_name']} is already checked in." );
            }
        }
        $checkins[] = $new_entry;
        return $checkins;
    } );

    wp_send_json_success( "✅ Welcome {$guest['first_name']}, you are now checked in." );
}

// ────────────────────────────────────────────────────────────────────────────
// CSV UPLOAD — Step 1: save temp file, return headers + guesses
// ────────────────────────────────────────────────────────────────────────────

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

    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $ext !== 'csv' ) {
        wp_send_json_error( 'Only .csv files are accepted.' );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/tmp';
    wp_mkdir_p( $tmp_dir );

    $htaccess = $tmp_dir . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Deny from all\n" );
    }

    foreach ( glob( $tmp_dir . '/*.csv' ) ?: [] as $old ) {
        if ( filemtime( $old ) < time() - 7200 ) {
            unlink( $old );
        }
    }

    $temp_key  = bin2hex( random_bytes( 16 ) );
    $temp_path = $tmp_dir . '/' . $temp_key . '.csv';
    move_uploaded_file( $file['tmp_name'], $temp_path );

    if ( filesize( $temp_path ) > 2 * 1024 * 1024 ) {
        unlink( $temp_path );
        wp_send_json_error( 'File too large. Maximum 2MB.' );
    }

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

    // Proper 3-byte BOM detection (avoids PHP ltrim footgun)
    if ( substr( $raw_headers[0], 0, 3 ) === "\xEF\xBB\xBF" ) {
        $raw_headers[0] = substr( $raw_headers[0], 3 );
    }

    $headers    = array_map( 'trim', $raw_headers );
    $normalized = array_map( 'strtolower', $headers );

    $qr_guess = null;
    foreach ( [ 'afscme_id', 'afscme', 'member_id', 'qr_id', 'id' ] as $c ) {
        $i = array_search( $c, $normalized );
        if ( $i !== false ) { $qr_guess = $headers[ $i ]; break; }
    }

    $phone_guess = null;
    foreach ( [ 'can2_phone', 'phone', 'phone_number', 'mobile', 'cell' ] as $c ) {
        $i = array_search( $c, $normalized );
        if ( $i !== false ) { $phone_guess = $headers[ $i ]; break; }
    }

    $field_guess_map = [
        'first_name'      => [ 'first_name', 'first name', 'first', 'fname' ],
        'last_name'       => [ 'last_name', 'last name', 'last', 'lname', 'surname' ],
        'email'           => [ 'email', 'email_address', 'e-mail' ],
        'dob'             => [ 'dob', 'date_of_birth', 'birth_date', 'birthday', 'birthdate' ],
        'member_status'   => [ 'member_status', 'status', 'membership_status', 'upw member' ],
        'bargaining_unit' => [ 'bargaining_unit', 'bargaining unit', 'unit', 'bu' ],
        'unit_number'     => [ 'unit_name', 'unit name', 'unit_number', 'unit_no', 'unit no', 'unit_num' ],
        'employer'        => [ 'employer', 'agency', 'department' ],
        'jurisdiction'    => [ 'jurisdiction', 'juris' ],
        'job_title'       => [ 'job_title', 'title', 'position', 'job title' ],
        'baseyard'        => [ 'baseyard', 'base_yard', 'base yard', 'yard' ],
        'island'          => [ 'island', 'islands', 'location' ],
    ];
    $field_guesses = [];
    foreach ( $field_guess_map as $field => $aliases ) {
        $field_guesses[ $field ] = null;
        foreach ( $aliases as $alias ) {
            $pos = array_search( $alias, $normalized );
            if ( $pos !== false ) { $field_guesses[ $field ] = $headers[ $pos ]; break; }
        }
    }

    wp_send_json_success( [
        'temp_key'      => $temp_key,
        'headers'       => $headers,
        'row_count'     => $row_count,
        'qr_guess'      => $qr_guess,
        'phone_guess'   => $phone_guess,
        'field_guesses' => $field_guesses,
        'filename'      => sanitize_file_name( $file['name'] ),
    ] );
}

// ────────────────────────────────────────────────────────────────────────────
// CSV UPLOAD — Step 2: stream-process rows, write guests file, update meta
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_import_guest_csv', 'mmm_ajax_import_guest_csv' );

function mmm_ajax_import_guest_csv() {
    check_ajax_referer( 'mmm_upload_guests', 'mmm_guests_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $temp_key   = preg_replace( '/[^a-f0-9]/', '', $_POST['temp_key']     ?? '' );
    $event_name = sanitize_text_field(          $_POST['guest_event_name'] ?? '' );
    $qr_col     = sanitize_text_field(          $_POST['qr_col']           ?? '' );
    $phone_col  = sanitize_text_field(          $_POST['phone_col']        ?? '' );

    if ( ! $temp_key || ! $event_name || ! $qr_col || ! $phone_col ) {
        wp_send_json_error( 'Missing required fields.' );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'mmm-event-checkin/tmp';
    $temp_path  = $tmp_dir . '/' . $temp_key . '.csv';

    if ( ! file_exists( $temp_path ) ) {
        wp_send_json_error( 'Upload session expired — please re-upload the file.' );
    }

    $slug = sanitize_title_with_dashes( $event_name );
    if ( ! mmm_event_exists( $slug ) ) {
        unlink( $temp_path );
        wp_send_json_error( 'Event not found.' );
    }

    // Stream-process: read and handle one row at a time — no $rows accumulation
    $fh = fopen( $temp_path, 'r' );
    if ( ! $fh ) {
        wp_send_json_error( 'Could not read upload session file.' );
    }

    $first_row = fgetcsv( $fh );
    // Proper 3-byte BOM detection
    if ( substr( $first_row[0], 0, 3 ) === "\xEF\xBB\xBF" ) {
        $first_row[0] = substr( $first_row[0], 3 );
    }
    $headers = array_map( 'trim', $first_row );
    $norm    = array_map( 'strtolower', $headers );

    $qr_idx    = array_search( strtolower( trim( $qr_col ) ),    $norm );
    $phone_idx = array_search( strtolower( trim( $phone_col ) ), $norm );

    $optional_fields = [
        'first_name', 'last_name', 'email', 'dob', 'member_status', 'bargaining_unit',
        'unit_number', 'employer', 'jurisdiction', 'job_title', 'baseyard', 'island',
    ];
    $col_idx = [];
    foreach ( $optional_fields as $field ) {
        $col_val = sanitize_text_field( $_POST[ 'col_' . $field ] ?? '' );
        if ( $col_val !== '' ) {
            $pos = array_search( strtolower( trim( $col_val ) ), $norm );
            if ( $pos !== false ) {
                $col_idx[ $field ] = $pos;
            }
        }
    }

    $guests     = [];
    $skipped    = 0;
    $dob_errors = 0;

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {
        if ( empty( array_filter( $row ) ) ) continue;

        $qr_val    = ( $qr_idx    !== false && isset( $row[ $qr_idx ] ) )    ? trim( $row[ $qr_idx ] )    : '';
        $phone_val = ( $phone_idx !== false && isset( $row[ $phone_idx ] ) ) ? trim( $row[ $phone_idx ] ) : '';

        // Also peek at name columns — a name alone is enough for DL check-in
        $fn_val = isset( $col_idx['first_name'] ) ? trim( $row[ $col_idx['first_name'] ] ?? '' ) : '';
        $ln_val = isset( $col_idx['last_name'] )  ? trim( $row[ $col_idx['last_name'] ]  ?? '' ) : '';

        if ( $qr_val === '' && $phone_val === '' && $fn_val === '' && $ln_val === '' ) {
            $skipped++;
            continue;
        }

        $guest = [ 'qr_id' => $qr_val, 'phone' => $phone_val ];
        foreach ( $optional_fields as $field ) {
            $guest[ $field ] = isset( $col_idx[ $field ] )
                ? sanitize_text_field( $row[ $col_idx[ $field ] ] ?? '' )
                : '';
        }

        // Convert raw dob string to a SHA256 hash — never store the raw value
        $raw_dob = $guest['dob'] ?? '';
        unset( $guest['dob'] );
        if ( $raw_dob !== '' ) {
            $normalized_dob = mmm_normalize_dob( $raw_dob );
            if ( $normalized_dob ) {
                $guest['dob_hash'] = hash( 'sha256', $normalized_dob );
            } else {
                $dob_errors++;
            }
        }

        $guests[] = $guest;
    }

    fclose( $fh );
    unlink( $temp_path );

    // Write guests to split file
    if ( mmm_save_guests( $slug, $guests ) === false ) {
        wp_send_json_error( 'Could not save guest list.' );
    }

    // Update meta with new guest count
    $meta = mmm_load_meta( $slug );
    if ( $meta ) {
        $meta['guest_count'] = count( $guests );
        mmm_save_meta( $slug, $meta );
    }

    // Bust phone, QR, and DL index caches
    delete_transient( 'mmm_pi_'  . $slug );
    delete_transient( 'mmm_qi_'  . $slug );
    delete_transient( 'mmm_dli_' . $slug );

    $msg = count( $guests ) . ' guests imported';
    if ( $skipped ) {
        $msg .= ' (' . $skipped . ' skipped — no QR ID, phone, or name)';
    }
    if ( $dob_errors ) {
        $msg .= '. ⚠ ' . $dob_errors . ' guests have an invalid or missing date of birth — they will check in by name only';
    }
    wp_send_json_success( $msg . '.' );
}

// ────────────────────────────────────────────────────────────────────────────
// POLL CHECK-INS — reads checkins file only; no guests file needed
// guest_idx stored in each checkin entry enables O(1) idx lookup
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_poll_checkins', 'mmm_ajax_poll_checkins' );

function mmm_ajax_poll_checkins() {
    check_ajax_referer( 'mmm_poll_checkins', 'mmm_poll_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );
    if ( ! $slug ) {
        wp_send_json_error( 'Missing event.' );
    }
    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $checkins = mmm_load_checkins( $slug );

    // Fast path: all checkins have guest_idx (post-migration / post-3.7.0)
    $state       = [];
    $has_missing = false;
    foreach ( $checkins as $ci ) {
        if ( isset( $ci['guest_idx'] ) ) {
            $state[ (string) $ci['guest_idx'] ] = $ci['time'] ?? '';
        } else {
            $has_missing = true;
        }
    }

    // Slow fallback for pre-migration entries without guest_idx (loads guests file once)
    if ( $has_missing ) {
        $guests          = mmm_load_guests( $slug );
        $checked_by_id   = [];
        $checked_by_name = [];
        foreach ( $checkins as $ci ) {
            $aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
            if ( $aid ) $checked_by_id[ $aid ] = $ci['time'] ?? '';
            $nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
            if ( $nm !== ' ' ) $checked_by_name[ $nm ] = $ci['time'] ?? '';
        }
        foreach ( $guests as $idx => $g ) {
            if ( ! isset( $state[ (string) $idx ] ) && mmm_guest_is_checked_in( $g, $checked_by_id, $checked_by_name ) ) {
                $aid_k  = strtolower( trim( $g['qr_id'] ?? '' ) );
                $name_k = strtolower( trim( ( $g['first_name'] ?? '' ) . ' ' . ( $g['last_name'] ?? '' ) ) );
                $state[ (string) $idx ] = $checked_by_id[ $aid_k ] ?? $checked_by_name[ $name_k ] ?? '';
            }
        }
    }

    wp_send_json_success( $state );
}

// ────────────────────────────────────────────────────────────────────────────
// EDIT GUEST — reads/writes guests and checkins files separately
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_edit_guest', 'mmm_ajax_edit_guest' );

function mmm_ajax_edit_guest() {
    check_ajax_referer( 'mmm_edit_guest', 'mmm_edit_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $slug      = sanitize_title_with_dashes( $_POST['event']     ?? '' );
    $guest_idx = (int) ( $_POST['guest_idx']                     ?? -1 );

    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $guests = mmm_load_guests( $slug );
    if ( ! isset( $guests[ $guest_idx ] ) ) {
        wp_send_json_error( 'Guest not found.' );
    }

    $checkins        = mmm_load_checkins( $slug );
    $checked_by_id   = [];
    $checked_by_name = [];
    foreach ( $checkins as $ci ) {
        $aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
        if ( $aid ) $checked_by_id[ $aid ] = $ci['time'] ?? '';
        $nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
        if ( $nm !== ' ' ) $checked_by_name[ $nm ] = $ci['time'] ?? '';
    }
    $was_checked = mmm_guest_is_checked_in( $guests[ $guest_idx ], $checked_by_id, $checked_by_name );

    $g = &$guests[ $guest_idx ];
    $g['first_name']      = sanitize_text_field( $_POST['first_name']      ?? $g['first_name'] );
    $g['last_name']       = sanitize_text_field( $_POST['last_name']       ?? $g['last_name'] );
    $g['qr_id']           = sanitize_text_field( $_POST['qr_id']           ?? $g['qr_id'] );
    $g['phone']           = sanitize_text_field( $_POST['phone']           ?? $g['phone'] );
    $g['email']           = sanitize_email(      $_POST['email']           ?? $g['email'] ?? '' );
    $g['member_status']   = sanitize_text_field( $_POST['member_status']   ?? $g['member_status'] );
    $g['bargaining_unit'] = sanitize_text_field( $_POST['bargaining_unit'] ?? $g['bargaining_unit'] );
    unset( $g );

    $now_checked = ! empty( $_POST['is_checked_in'] ) && $_POST['is_checked_in'] !== '0';
    $g           = $guests[ $guest_idx ];

    if ( $now_checked && ! $was_checked ) {
        $checkins[] = [
            'guest_idx'       => $guest_idx,
            'first_name'      => $g['first_name']      ?? '',
            'last_name'       => $g['last_name']        ?? '',
            'email'           => $g['email']            ?? '',
            'afscme_id'       => $g['qr_id']            ?? '',
            'phone'           => $g['phone']            ?? '',
            'member_status'   => $g['member_status']    ?? '',
            'bargaining_unit' => $g['bargaining_unit']  ?? '',
            'unit_number'     => $g['unit_number']      ?? '',
            'employer'        => $g['employer']         ?? '',
            'jurisdiction'    => $g['jurisdiction']     ?? '',
            'baseyard'        => $g['baseyard']         ?? '',
            'island'          => $g['island']           ?? '',
            'time'            => date_i18n( 'g:ia, l, F j, Y' ),
            'method'          => 'manual',
        ];
    } elseif ( ! $now_checked && $was_checked ) {
        $aid = strtolower( trim( $g['qr_id'] ?? '' ) );
        $nm  = strtolower( trim( ( $g['first_name'] ?? '' ) . ' ' . ( $g['last_name'] ?? '' ) ) );
        $checkins = array_values( array_filter( $checkins, function ( $ci ) use ( $aid, $nm ) {
            $ci_aid = strtolower( trim( $ci['afscme_id'] ?? '' ) );
            $ci_nm  = strtolower( trim( ( $ci['first_name'] ?? '' ) . ' ' . ( $ci['last_name'] ?? '' ) ) );
            if ( $aid ) return $ci_aid !== $aid;
            return $ci_nm !== $nm;
        } ) );
    }

    if ( mmm_save_guests( $slug, $guests ) === false || mmm_save_checkins( $slug, $checkins ) === false ) {
        wp_send_json_error( 'Could not save changes.' );
    }

    // Bust all scan indexes — qr_id or phone may have changed
    delete_transient( 'mmm_pi_'  . $slug );
    delete_transient( 'mmm_qi_'  . $slug );
    delete_transient( 'mmm_dli_' . $slug );

    wp_send_json_success( 'Guest updated.' );
}

// ────────────────────────────────────────────────────────────────────────────
// ADD GUEST — appends to guests file; optionally appends to checkins file
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_add_guest', 'mmm_ajax_add_guest' );

function mmm_ajax_add_guest() {
    check_ajax_referer( 'mmm_add_guest', 'mmm_add_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $slug = sanitize_title_with_dashes( $_POST['event'] ?? '' );
    if ( ! mmm_event_exists( $slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    $guests = mmm_load_guests( $slug );

    $guest = [
        'first_name'      => sanitize_text_field( $_POST['first_name']      ?? '' ),
        'last_name'       => sanitize_text_field( $_POST['last_name']       ?? '' ),
        'qr_id'           => sanitize_text_field( $_POST['qr_id']           ?? '' ),
        'phone'           => sanitize_text_field( $_POST['phone']           ?? '' ),
        'member_status'   => sanitize_text_field( $_POST['member_status']   ?? '' ),
        'bargaining_unit' => sanitize_text_field( $_POST['bargaining_unit'] ?? '' ),
        'unit_number'     => '',
        'employer'        => '',
        'jurisdiction'    => '',
        'job_title'       => '',
        'baseyard'        => '',
        'island'          => '',
        'email'           => '',
    ];

    $guests[]  = $guest;
    $new_idx   = array_key_last( $guests );

    if ( mmm_save_guests( $slug, $guests ) === false ) {
        wp_send_json_error( 'Could not save guest.' );
    }

    $is_checked_in = ! empty( $_POST['is_checked_in'] ) && $_POST['is_checked_in'] !== '0';
    if ( $is_checked_in ) {
        $checkins   = mmm_load_checkins( $slug );
        $checkins[] = [
            'guest_idx'       => $new_idx,
            'first_name'      => $guest['first_name'],
            'last_name'       => $guest['last_name'],
            'email'           => $guest['email'],
            'afscme_id'       => $guest['qr_id'],
            'phone'           => $guest['phone'],
            'member_status'   => $guest['member_status'],
            'bargaining_unit' => $guest['bargaining_unit'],
            'unit_number'     => $guest['unit_number'],
            'employer'        => $guest['employer'],
            'jurisdiction'    => $guest['jurisdiction'],
            'baseyard'        => $guest['baseyard'],
            'island'          => $guest['island'],
            'time'            => date_i18n( 'g:ia, l, F j, Y' ),
            'method'          => 'manual',
        ];
        mmm_save_checkins( $slug, $checkins );
    }

    // Update meta guest count
    $meta = mmm_load_meta( $slug );
    if ( $meta ) {
        $meta['guest_count'] = count( $guests );
        mmm_save_meta( $slug, $meta );
    }

    delete_transient( 'mmm_pi_'  . $slug );
    delete_transient( 'mmm_qi_'  . $slug );
    delete_transient( 'mmm_dli_' . $slug );
    wp_send_json_success( [ 'guest_idx' => $new_idx ] );
}

// ────────────────────────────────────────────────────────────────────────────
// UPDATE CONTACT — public endpoint; authorized by the phone/DL check-in token
// Only allows writing phone + email; no other fields.
// ────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_mmm_update_guest_contact',        'mmm_ajax_update_guest_contact' );
add_action( 'wp_ajax_nopriv_mmm_update_guest_contact', 'mmm_ajax_update_guest_contact' );

function mmm_ajax_update_guest_contact() {
    $event_slug = sanitize_title_with_dashes( $_POST['event']      ?? '' );
    $idx        = (int) ( $_POST['idx']                            ?? -1 );
    $token      = sanitize_text_field( $_POST['token']             ?? '' );
    $token_type = sanitize_text_field( $_POST['token_type']        ?? 'phone' );
    $auth_phone = mmm_normalize_phone( $_POST['phone']             ?? '' );
    $dob_hash   = preg_replace( '/[^a-f0-9]/', '', strtolower( $_POST['dob_hash'] ?? '' ) );

    if ( ! $event_slug || $idx < 0 || ! $token ) {
        wp_send_json_error( 'Missing required fields.' );
    }
    if ( ! mmm_validate_slug( $event_slug ) || ! mmm_event_exists( $event_slug ) ) {
        wp_send_json_error( 'Event not found.' );
    }

    // Verify token matches the phone or DL lookup that produced it
    $valid = false;
    if ( $token_type === 'phone' && $auth_phone ) {
        $expected = hash_hmac( 'sha256', $event_slug . '|' . $idx . '|' . $auth_phone, AUTH_KEY );
        $valid    = hash_equals( $expected, $token );
    } else {
        $window = floor( time() / 300 );
        $e1     = hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . $dob_hash . '|' . $window,           AUTH_KEY );
        $e2     = hash_hmac( 'sha256', 'dl|' . $event_slug . '|' . $idx . '|' . $dob_hash . '|' . ( $window - 1 ),   AUTH_KEY );
        $valid  = hash_equals( $e1, $token ) || hash_equals( $e2, $token );
    }
    if ( ! $valid ) {
        wp_send_json_error( 'Invalid token.' );
    }

    $guests = mmm_load_guests( $event_slug );
    if ( ! isset( $guests[ $idx ] ) ) {
        wp_send_json_error( 'Guest not found.' );
    }

    $new_phone = sanitize_text_field( $_POST['new_phone'] ?? '' );
    $new_email = sanitize_email(      $_POST['new_email'] ?? '' );

    if ( ! $new_phone && ! $new_email ) {
        wp_send_json_error( 'Nothing to update.' );
    }

    if ( $new_phone ) $guests[ $idx ]['phone'] = $new_phone;
    if ( $new_email ) $guests[ $idx ]['email'] = $new_email;

    if ( mmm_save_guests( $event_slug, $guests ) === false ) {
        wp_send_json_error( 'Could not save.' );
    }

    if ( $new_phone ) delete_transient( 'mmm_pi_'  . $event_slug );
    delete_transient( 'mmm_dli_' . $event_slug );

    wp_send_json_success( 'Contact updated.' );
}

// ────────────────────────────────────────────────────────────────────────────
// SCRIPT ENQUEUING
// ────────────────────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_mmm_checkin' ) return;
    wp_enqueue_script( 'html5-qrcode', MMM_ECI_URL . 'assets/js/html5-qrcode.min.js', [], MMM_ECI_VERSION, true );
    wp_enqueue_script( 'mmm-qr-js', MMM_ECI_URL . 'assets/js/qr-scanner.js', [ 'jquery' ], MMM_ECI_VERSION, true );
    wp_localize_script( 'mmm-qr-js', 'mmm_qr_ajax', [
        'ajaxurl'       => admin_url( 'admin-ajax.php' ),
        'current_event' => get_option( 'mmm_current_event', '' ),
        'success_audio' => plugin_dir_url( __FILE__ ) . 'assets/audio/success.mp3',
        'error_audio'   => plugin_dir_url( __FILE__ ) . 'assets/audio/error.mp3',
    ] );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, 'mmm_view_checkins' ) === false ) return;
    wp_enqueue_script( 'chartjs', MMM_ECI_URL . 'assets/js/chart.min.js', [], '4.4.4', true );
} );

// ────────────────────────────────────────────────────────────────────────────
// PAGE TEMPLATES
// ────────────────────────────────────────────────────────────────────────────

add_filter( 'theme_page_templates', function ( $templates ) {
    $templates['public-event-scanner.php'] = 'Public Event Scanner';
    return $templates;
} );

add_filter( 'template_include', function ( $template ) {
    if ( is_page() && get_page_template_slug() === 'public-event-scanner.php' ) {
        return plugin_dir_path( __FILE__ ) . 'public-event-scanner.php';
    }
    return $template;
} );

add_filter( 'theme_page_templates', function ( $templates ) {
    $templates['checkin-result.php'] = 'Check-In Result';
    return $templates;
}, 11 );

add_filter( 'template_include', function ( $template ) {
    if ( is_page() && get_page_template_slug() === 'checkin-result.php' ) {
        return plugin_dir_path( __FILE__ ) . 'checkin-result.php';
    }
    return $template;
}, 11 );
