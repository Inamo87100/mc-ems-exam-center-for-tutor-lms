# Changelog

## 2.4.4
- Improved exam session creation handling.
- Locked past exam sessions in backend (read-only).
- Added stricter UI restrictions for past dates/times.

## 2.4.1
- Added {course_title} placeholder to proctor assignment email subject/body settings and rendering.

## 2.3.9.1-base
- Fixed settings save bug: saving Email settings no longer resets Exam booking settings flags, and saving Exam booking settings no longer resets Email flags.
- Added hidden current-tab tracking so checkbox fields are sanitized only for the active settings tab.

## 2.3.9-base
- Added customizable email subjects and bodies in Email settings.
- Added placeholder support for candidate/admin/proctor emails.

## 2.3.8-base
- Added Email settings tab.
- Added toggles for booking confirmation/cancellation emails and admin notifications.
- Added configurable sender name/email and admin recipients.

# Changelog

## 2.3.7-base
- Maintenance: added README.md / CHANGELOG.md / uninstall.php
- Plugin header now declares minimum WP/PHP requirements

## 2.3.6-base
- Settings: page selector switched to native wp_dropdown_pages() (stable)
