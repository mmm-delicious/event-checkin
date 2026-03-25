=== Event Check-In ===
Contributors: mmmdelicious
Tags: event, check-in, qr code, barcode, guest list
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.15.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate QR codes for event check-in and manage events with a live dashboard, guest list, and barcode scanning.

== Description ==

Event Check-In lets you create events, import guest lists from CSV, and check guests in via QR code, barcode, or phone number lookup. Includes a live admin dashboard with charts and sortable tables.

== Changelog ==

= 3.15.4 =
* Improvement: Camera resolution bumped to 1080p for better PDF417 decode on dense DL barcodes
* Improvement: Continuous autofocus applied after stream starts (camera keeps retrying focus)
* Improvement: Torch/flashlight button appears on supported Android devices (Chrome)
* Fix: Camera selector switch now carries resolution constraints instead of bare deviceId

= 3.15.3 =
* Fix: DOB importer now accepts M/D/YY format (e.g. 9/5/75 → 1975-09-05)
* 2-digit years 70–99 map to 1970–1999; 00–69 map to 2000–2069

= 3.15.2 =
* Fix: Guests with name + DOB but no QR ID or phone are now imported (previously skipped)
* Rows are now only skipped if they have no QR ID, no phone, AND no name at all

= 3.15.1 =
* Fix: CSV column mapper now shows Date of Birth field for mapping
* Fix: Bargaining Unit field relabeled from "Unit Name" to "Bargaining Unit" to avoid confusion
* Fix: Unit Number field relabeled to "Unit Name" to match expected CSV column name
* Fix: "unit name" / "unit_name" added as auto-guess aliases for Unit Name field

= 3.15.0 =
* New: AAMVA PDF417 driver's licence check-in — scan a US/Canada DL to check in a guest
* Tiered matching: Tier 1 uses DOB + last name (for members with DOB in CSV); Tier 2 falls
  back to first + last name with staff confirmation overlay
* DOB is never transmitted or stored in plaintext — SHA-256 hash only
* pdf417 added to BarcodeDetector WANT_FORMATS (ZXing-WASM polyfill already supports it)
* AAMVA JS parser handles DCS/DCT/DAC/DBB fields; client-side validation before any AJAX
* Confirmation overlay shows match tier ("Matched by DOB + Last Name" or "Matched by name only")
* Rate limiting: 10 DL search attempts per IP per 10 minutes via transients
* HMAC tokens include method prefix (dl|) and 5-minute time window
* CSV import: optional dob column accepted; raw DOB hashed at import, never stored raw
* Admin import feedback: reports count of guests with invalid DOB (name-only fallback)
* HSTS header added to public scanner page response

= 3.14.0 =
* Scanner: replaced jsQR with ZXing-C++ WASM polyfill (BarcodeDetector API)
* Full 1D barcode + QR support on Safari/iOS via self-hosted .wasm bundle (no CDN)
* WASM file served locally — eliminates CDN dependency for polyfill

= 3.7.0 =
* Performance & Scalability: three-file split architecture ({slug}-meta.json,
  {slug}-guests.json, {slug}-checkins.json) — runtime ops touch only the small
  checkins file; guests file is write-once at import
* Transparent migration of legacy single-file events on first access
* guest_idx stored in checkin entries — poll endpoint never loads guests file
* Dashboard AJAX returns stats + checkins only (no guests array) — payload reduced
  from 100-150MB to ~2MB at large event scale
* Server-side pagination on guest list (100 rows/page with sort/search URL params)
  eliminates DOM catastrophe at 10k+ guests
* Nonces generated once per page render (not per-row) — eliminates 20,000
  redundant wp_create_nonce calls at 10k guests
* Streaming CSV import — rows processed inline, no $rows[] accumulation
* visibilitychange guard on all polling intervals — pauses on tab hide, resumes on show
* Atomic check-in locking: QR and phone check-in use flock LOCK_EX read-modify-write
  cycle — prevents duplicate entries under simultaneous check-ins
* Slug format validation as defense-in-depth against path traversal
* CSV export formula injection fix: mmm_csv_escape() neutralises dangerous leading chars
* Undo check-in fallback matches by ID OR name — correctly removes phone check-ins
* XSS hardening: Add Guest and poll handlers use createElement/textContent (not innerHTML)
* BOM stripping fixed: proper 3-byte sequence check (was stripping individual bytes)

= 3.6.0 =
* Live Check-In Dashboard: stats bar, Checked In/Not Checked In doughnut chart,
  dynamic field-breakdown bar chart (Unit Name, Member Status, etc.),
  sortable checked-in guest table with 10-second auto-refresh
* Guest List: real-time search, sortable columns, Edit Guest modal (inline AJAX),
  Add Guest modal (inline AJAX), Unit Name and Member Status columns added
* CSV Import: all fields now fully mappable via UI dropdowns (First Name, Last Name,
  Email, Member Status, Unit Name, Unit Number, Employer, Jurisdiction, Job Title,
  Baseyard, Island) — no more auto-detection guessing
* Scanner: barcode scanning support (CODE_128, CODE_39, EAN-13, EAN-8, UPC-A, UPC-E)
  added alongside existing QR code scanning

= 3.5.1 =
* Phone check-in: 7-digit local number auto-prefixes default area code
* Poll endpoint returns idx→time map for guest list auto-refresh

= 3.5.0 =
* Guest list auto-refresh polling (8s interval)
* Manual check-in and undo from admin guest list
* Phone index cache (transient) for fast phone lookups

= 3.0.0 =
* Rebuilt on JSON file storage — no database tables
* CSV guest import with two-step column mapping
* Phone number check-in flow

= 1.0.5 =
* Initial release with QR code generation and basic event check-in
