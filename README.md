# MMM Event Check-In

A WordPress plugin for managing events, generating user QR codes, and checking attendees in via QR scan or phone number search.

## Features

- Create and manage events from the WordPress admin
- Generate unique per-user QR codes (keyed on AFSCME ID or user ID + login)
- Admin scanner page for live QR check-ins with audio feedback
- Public-facing scanner page (assignable as a page template) — optimized for iPad kiosk use
- **Guest list CSV upload per event** — import an expected attendee list, no WP user account required
- **Phone number check-in** — custom dialpad UI, searches guest list, shows confirmation screen before committing
- Duplicate check-in prevention per event (by email for QR, by phone for phone check-in)
- Real-time check-in monitor in admin (auto-refreshes every 10 seconds)
- Export check-ins to CSV per event (includes phone and check-in method columns)
- Shortcode `[mmm_user_qr]` to display a user's QR code on any page

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Installation

1. Upload the `mmm-event-checkin` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to **Event Check-In** in the admin menu to create your first event

## Usage

### Creating an Event
Go to **Event Check-In** in the admin sidebar and enter an event name. This creates a JSON file in `wp-content/uploads/mmm-event-checkin/events/`.

### Scanning Check-Ins (Admin)
The top-level **Event Check-In** menu page includes a live QR scanner. Select the active event, point the camera at a member's QR code, and check-ins are recorded instantly with audio confirmation.

### Public Scanner Page
Create a WordPress page and assign the **Public Event Scanner** template. Append `?event=your-event-slug` to the URL to load the correct event. Share this URL with volunteers who don't have admin access.

The scanner page has two check-in modes:

- **QR Scan** — point the camera at a member's QR code
- **Phone Number** — available when the event has a guest list uploaded; uses a custom dialpad styled like a phone keypad (no system keyboard), searches by phone number, and shows a name confirmation screen before checking in

### Uploading a Guest List
In the Event List, click **Upload Guests** next to an event and upload a CSV. The CSV can use any of these column headers (case-insensitive):

| Field | Accepted header names |
|---|---|
| First Name | `first_name`, `first` |
| Last Name | `last_name`, `last` |
| Phone | `phone`, `phone_number`, `mobile`, `cell` |
| Email | `email`, `email_address` |
| Member Status | `member_status`, `status` |
| Bargaining Unit | `bargaining_unit`, `unit` |
| AFSCME ID | `afscme_id`, `afscme`, `id` |
| Job Title | `job_title`, `title` |
| Employer | `employer` |
| Jurisdiction | `jurisdiction` |
| Island | `island` |
| Baseyard | `baseyard`, `base_yard` |
| Unit Number | `unit_number`, `unit_no` |

Uploading a new CSV replaces the existing guest list. The guest count is shown in the Event List.

### Displaying a User's QR Code
Add `[mmm_user_qr]` to any page or post. Logged-in users will see their personal QR code image.

### Exporting Check-Ins
From the Event List, click **Export Check-ins** next to any event to download a CSV with attendee details, including phone number and check-in method (`qr` or `phone`).

## File Structure

```
mmm-event-checkin/
├── mmm-event-checkin.php       # Main plugin file, AJAX handlers, template routing
├── public-event-scanner.php    # Public-facing scanner page template (QR + phone dialpad)
├── admin/
│   ├── class-admin-menu.php    # Registers admin menu pages
│   ├── page-events.php         # Event list, create, delete, export, guest CSV upload
│   └── admin-view-checkins.php # Live check-in monitor + AJAX handler
├── includes/
│   ├── class-qr-generator.php  # QR code generation and token matching
│   └── shortcodes.php          # [mmm_user_qr] shortcode
└── assets/
    ├── js/
    │   ├── qr-scanner.js           # Admin QR scanner UI
    │   └── html5-qrcode.min.js     # Self-hosted QR scanning library
    └── audio/
        ├── success.mp3
        └── error.mp3
```

## Changelog

### 3.0.0
- Add guest list CSV upload per event — import expected attendees without requiring WP user accounts
- Add phone number check-in with custom phone dialpad UI (no system keyboard) and name confirmation screen
- Add HMAC-signed confirmation tokens for phone check-ins (`hash_hmac` + `hash_equals`)
- Stop QR camera when switching to Phone tab; restart on return (prevents battery drain)
- iPad kiosk UX: all tap targets ≥ 52px, `touch-action: manipulation`, dark backdrop on confirm dialog
- Add Method column to admin check-in monitor and CSV export
- Fix: audio `.play()` wrapped in try/catch to prevent uncaught promise rejections on iOS
- Fix: audio source URLs properly escaped with `esc_url`

### 2.7.0
- Replace CDN-loaded html5-qrcode with self-hosted copy (no external dependencies)
- Add `.htaccess` + `index.php` to events directory on first load (blocks direct HTTP access to JSON files)
- Add slug collision detection on event creation
- Register `wp_ajax_nopriv_mmm_checkin` so public scanner works without login

### 2.6.0
- Fix version mismatch: unified plugin header and `MMM_ECI_VERSION` constant to `2.6.0`
- Add ABSPATH guard to all PHP files
- Add CSRF nonces to event create, export, and delete forms
- Add `manage_options` capability check to `mmm_get_checkins` AJAX handler
- Fix XSS in check-in monitor: replaced `innerHTML` template literal with safe DOM `textContent` construction
- Remove stray backup file `includes/class-qr-generator copy.php`
- Add `.gitignore`
- Add `Requires at least`, `Requires PHP`, `Tested up to` to plugin header
- Add README.md

### 2.5.2
- Public event scanner page template
- AFSCME ID-based QR token generation
- Audio feedback on scan result

### Earlier
- Initial event management and QR check-in system
