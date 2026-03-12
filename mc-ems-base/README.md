# MC-EMS (Base) – Exam Management System

MC-EMS is a WordPress plugin that adds exam session management (Custom Post Type), candidate exam bookings, and a proctor assignment calendar.

## Requirements
- WordPress 6.0+
- PHP 7.0+

## Main features (Base)
- Exam sessions CPT (`mcems_exam_session`)
- Candidate exam booking calendar (shortcode: `[mcems_book_exam]`)
- Manage exam booking page (shortcode: `[mcems_manage_booking]`)
- Bookings list with advanced search and CSV export (shortcode: `[mcems_bookings_list]`)
- Tutor LMS course access gate (exam-booking based)
- Admin session management & proctor assignment calendar

## Recommended setup
1. Create a page for booking and place: `[mcems_book_exam]`
2. Create a page for manage booking and place: `[mcems_manage_booking]`
3. Go to **Settings → MC-EMS → Pages** and select those pages.

## Shortcodes
- `[mcems_book_exam]` – exam booking calendar / slot booking
- `[mcems_manage_booking]` – view & cancel own booking
- `[mcems_sessions_calendar]` – proctor assignment calendar (admin use)

## Uninstall
On uninstall, the plugin removes its options. It does **not** delete `mcems_exam_session` posts automatically.
