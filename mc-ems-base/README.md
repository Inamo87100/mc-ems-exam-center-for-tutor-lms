# MC-EMS – Exam Session Management for Tutor LMS

**Contributors:** Mamba Coding  
**Tags:** exam, booking, tutor lms, exam management, sessions  
**Requires at least:** 5.0  
**Tested up to:** 6.9  
**Stable tag:** 2.5.0  
**Requires PHP:** 7.2  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0-or-later.html

Exam Management System – base module for managing exam sessions and bookings with Tutor LMS.

## Description

MC-EMS (Mamba Coding Exam Management System) is the base module for managing exam sessions, bookings, and proctors in WordPress with Tutor LMS integration.

### Features

- Exam session booking with calendar view
- Session management with proctor assignment
- Bookings list with CSV export
- Configurable roles and permissions
- Exam access control with time-based locking

### Shortcodes

- `[mcems_book_exam]` – Exam booking (select exam → calendar → choose exam session)
- `[mcems_manage_booking]` – Shows the logged-in user exam bookings and allows cancellation
- `[mcems_sessions_calendar]` – Calendar to assign proctors to exam sessions
- `[mcems_bookings_list]` – Exam bookings list (with date and exam filters)

## Configurable Settings System

### Role-Based Proctor Assignment

The Proctor Roles feature allows for customizable assignments of proctors based on specific roles. This capability enhances the flexibility of the proctoring process. To facilitate this, a new "Role Settings" tab has been introduced, which consolidates the following settings:

- **Shortcode Visibility**: Control who can see the applied shortcodes.
- **Proctor Role Restrictions**: Set restrictions based on assigned roles to ensure that only the eligible proctors can access certain functionalities.

#### get_proctor_roles() Method

The `get_proctor_roles()` method is designed to retrieve the list of available proctor roles within the system. When no roles are selected, the method defaults to assigning all available roles to the user, ensuring that proctors have the necessary access until specific assignments are made. This behavior maintains a seamless experience across the proctoring functionality.

## Changelog

### 2.5.0
* Fixed text domain to match plugin slug (mc-ems-base)
* Fixed output escaping throughout
* Added nonce verification to AJAX handlers
* Replaced date() with gmdate() for timezone-safe operations
* Replaced fopen/fclose with output buffering for CSV export
* Updated README.md with required WordPress.org headers

## Upgrade Notice

### 2.5.0
Fixes WordPress.org plugin check issues including text domain, output escaping, and security improvements.
