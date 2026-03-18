# MC-EMS – Exam Center for Tutor LMS

**Contributors:** Mamba Coding  
**Tags:** exam, booking, tutor lms, exam management, sessions  
**Requires at least:** 6.0  
**Tested up to:** 6.9  
**Stable tag:** 1.0.0  
**Requires PHP:** 7.4  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0-or-later.html

Exam Management System – base module for managing exam sessions and bookings with Tutor LMS.

## Description

MC-EMS – Exam Center for Tutor LMS

MC-EMS is an advanced WordPress plugin that transforms Tutor LMS into a complete exam management system. The plugin allows you to organize exam sessions, manage student bookings, and automatically control access to exams based on the presence of a valid reservation.

With MC-EMS, you can structure scheduled exams through calendar-based sessions, offer an intuitive booking system, and manage all reservations from a single administrative panel.

### Ideal for

* Certification bodies
* Universities and academies
* Tutor LMS-based e-learning platforms
* Schools and vocational training centers
* Organizations that manage scheduled exams

### Main Features

#### 3-LEVEL ACCESS
1. Admin: session creation
2. Tutor / Proctor: session management
3. Students: session booking

#### Exam Session Management

MC-EMS introduces a Custom Post Type dedicated to exam sessions (mcems_exam_session). Each session includes date, time, associated exam, capacity, and operational settings. Sessions can be managed directly from the WordPress dashboard through a simple and intuitive interface.

#### Exam Booking via Calendar

**Shortcode:** `[mcems_book_exam]`

Students can book an exam session through an interactive calendar that shows all available sessions filtered by date.

#### Student Booking Management

**Shortcode:** `[mcems_manage_booking]`

Students can view and manage their booking, check the exam date and time, see the details of the associated exam, and cancel the booking when allowed.

#### Booking Management with Search and CSV Export

**Shortcode:** `[mcems_bookings_list]`

* Complete booking list
* Search by date or date range
* Filters by exam, candidate, and status
* Export bookings to CSV
* Display of candidates' special needs
* Assigned proctor information

#### Administrative Session Calendar

**Shortcode:** `[mcems_sessions_calendar]`

The administrative calendar allows you to view all exam sessions, check available seats, assign proctors, and monitor booking status.

#### Exam Access Control with Tutor LMS

MC-EMS integrates an access gate system that automatically blocks access to the exam until the student has a valid booking for an available session.

When access is blocked:

* the exam remains inaccessible
* the message "Exam locked" is displayed
* the exam content is hidden
* the student receives instructions to book a session

#### Configurable Settings System

* Booking page configuration
* Minimum advance booking time management
* Booking cancellation management
* Tutor LMS integration
* System message customization
* Administrative permission management

### Base Version vs Premium Version

| Feature | Base | Premium |
|---------|------|---------|
| Exam sessions | ✓ | ✓ |
| Exam booking | ✓ | ✓ |
| Booking calendar | ✓ | ✓ |
| User booking management | ✓ | ✓ |
| Bookings list | ✓ | ✓ |
| CSV export of bookings | ✓ | ✓ |
| Administrative session calendar | ✓ | ✓ |
| Proctor assignment | ✓ | ✓ |
| Tutor LMS integration | ✓ | ✓ |
| Unlimited sessions | — | ✓ |
| Session capacity | up to 5 seats | up to 500 seats |
| Multiple time slots per day | — | ✓ |
| Priority support | — | ✓ |

### Requirements

* WordPress 6.0 or later
* PHP 7.4 or later
* Tutor LMS installed for exam integration

### Installation

1. Download the plugin from the WordPress Repository
2. Activate the plugin from the WordPress Plugins section
3. Configure the settings in the MC-EMS section
4. Create exam sessions
5. Insert the shortcodes into your site pages

## Changelog

### 1.0.0

* Initial stable release
* Exam session booking with calendar view
* Session management with proctor assignment
* Bookings list with CSV export
* Configurable roles and permissions
* Exam access control with time-based locking
* Full WordPress.org compatibility
* All strings properly internationalized
* Complete security hardening

## Upgrade Notice

### 1.0.0

Initial stable release of MC-EMS base module with all core features and WordPress.org compatibility.