# MMM Event Check-In

A WordPress plugin for managing events, generating user QR codes, and checking attendees in via QR scan.

## Features

- Create and manage events from the WordPress admin
- Generate unique per-user QR codes (keyed on AFSCME ID or user ID + login)
- Admin scanner page for live QR check-ins with audio feedback
- Public-facing scanner page (assignable as a page template)
- Duplicate check-in prevention per event
- Real-time check-in monitor in admin (auto-refreshes every 10 seconds)
- Export check-ins to CSV per event
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

### Displaying a User's QR Code
Add `[mmm_user_qr]` to any page or post. Logged-in users will see their personal QR code image.

### Exporting Check-Ins
From the Event List, click **Export Check-ins** next to any event to download a CSV with attendee details.

## File Structure

```
mmm-event-checkin/
├── mmm-event-checkin.php       # Main plugin file, AJAX handlers, template routing
├── public-event-scanner.php    # Public-facing scanner page template
├── admin/
│   ├── class-admin-menu.php    # Registers admin menu pages
│   ├── page-events.php         # Event list, create, delete, export
│   └── admin-view-checkins.php # Live check-in monitor + AJAX handler
├── includes/
│   ├── class-qr-generator.php  # QR code generation and token matching
│   └── shortcodes.php          # [mmm_user_qr] shortcode
└── assets/
    ├── js/qr-scanner.js        # Admin QR scanner UI
    └── audio/
        ├── success.mp3
        └── error.mp3
```

## Changelog

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
