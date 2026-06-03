=== MC-EMS – Exam Center for Tutor LMS ===
Contributors: internetamodo
Tags: exam, booking, tutor lms, exam management, sessions
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.2.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0-or-later.html

Exam Management System – complete plugin for managing exam sessions and bookings with Tutor LMS.

== Description ==

MC-EMS – Exam Center for Tutor LMS

MC-EMS is an advanced WordPress plugin that transforms Tutor LMS into a complete exam management system. The plugin allows you to organize exam sessions, manage student bookings, and automatically control access to exams based on the presence of a valid reservation.

With MC-EMS, you can structure scheduled exams through calendar-based sessions, offer an intuitive booking system, and manage all reservations from a single administrative panel.

= Ideal for =

* Certification bodies
* Universities and academies
* Tutor LMS-based e-learning platforms
* Schools and vocational training centers
* Organizations that manage scheduled exams

= Main Features =

= 3-LEVEL ACCESS =
1. Admin: session creation
2. Tutor / Proctor: session management
3. Students: session booking

= Exam Session Management =

MC-EMS introduces a Custom Post Type dedicated to exam sessions (mcems_exam_session). Each session includes date, time, associated exam, capacity, and operational settings. Sessions can be managed directly from the WordPress dashboard through a simple and intuitive interface.

= Exam Booking via Calendar =

**Shortcode:** `[mcemexce_book_exam]`

Students can book an exam session through an interactive calendar that shows all available sessions filtered by date.

= Student Booking Management =

**Shortcode:** `[mcemexce_manage_booking]`

Students can view and manage their booking, check the exam date and time, see the details of the associated exam, and cancel the booking when allowed.

= Booking Management with Search and CSV Export =

**Shortcode:** `[mcemexce_bookings_list]`

* Complete booking list
* Search by date or date range
* Filters by exam, candidate, and status
* Export bookings to CSV
* Display of candidates' special needs
* Assigned proctor information

= Administrative Session Calendar =

**Shortcode:** `[mcemexce_sessions_calendar]`

The administrative calendar allows you to view all exam sessions, check available seats, assign proctors, and monitor booking status.

= Exam Access Control with Tutor LMS =

MC-EMS integrates an access gate system that automatically blocks access to the exam until the student has a valid booking for an available session.

When access is blocked:

* the exam remains inaccessible
* the message "Exam locked" is displayed
* the exam content is hidden
* the student receives instructions to book a session

= Configurable Settings System =

* Booking page configuration
* Minimum advance booking time management
* Booking cancellation management
* Tutor LMS integration
* System message customization
* Administrative permission management

= Free Version – Features & Limits =

The free version includes all core features. The following limits apply and can be removed by upgrading to MC-EMS Premium:

**Free version limits:**

* Max 5 active sessions at a time
* Max 5 seats per session
* Max 1 session per day per exam

**Features:**

* Exam sessions (up to 5 active)
* Exam booking
* Booking calendar
* User booking management
* Bookings list
* CSV export of bookings
* Administrative session calendar
* Proctor assignment
* Tutor LMS integration

**MC-EMS Premium removes all limits and adds:**

* Unlimited active sessions
* Session capacity up to 500 seats
* Multiple time slots per day per exam
* Priority support

All limits are enforced transparently in the admin interface. When a limit is reached, a clear notice explains what happened and includes a link to MC-EMS Premium.

= Requirements =

* WordPress 6.0 or later
* PHP 7.4 or later
* Tutor LMS installed for exam integration

== Installation ==

1. Download the plugin from the WordPress Repository
2. Activate the plugin from the WordPress Plugins section
3. Configure the settings in the MC-EMS section
4. Create exam sessions
5. Insert the shortcodes into your site pages

== Frequently Asked Questions ==

= What is EC and what is it used for? =

EC is an advanced WordPress plugin that transforms Tutor LMS into a complete exam session management system.

It allows administrators to organize exam calendars, manage student bookings, and automatically control access to exams based on a valid reservation.

= What does EC add compared to the original Tutor LMS? =

Tutor LMS mainly manages courses and quizzes. EC adds essential functionality for managing real examination sessions, including:

* Exam session management (date, time, capacity)
* Exam booking through an interactive calendar
* Dedicated administrative dashboard for bookings
* Access control to exams based on valid reservations
* CSV export of bookings and session data
* Assignment of proctors to sessions
* Advanced role and permission management

In practice, EC makes Tutor LMS suitable for professional and certification-based environments.

= Can students view available sessions and book autonomously? =

Yes. Using the shortcode:

[mcemexce_book_exam]

students can view available sessions filtered by date and book directly from the interactive calendar.

This significantly reduces manual administrative work and provides a modern and user-friendly experience.

= Can students manage their own bookings? =

Yes. Using the shortcode:

[mcemexce_manage_booking]

each student can:

* View existing bookings
* Check session details
* Cancel bookings (if allowed)
* Verify exam date, time, and information

= Can administrators manage many sessions and export bookings? =

Yes. From the WordPress dashboard administrators can:

* Create and modify exam sessions
* View all bookings
* Export booking data in CSV format
* Assign proctors to sessions

This provides full operational control for organizations managing a large number of candidates.

= How customizable is the exam session management system? =

The system is highly configurable. Administrators can define:

* Session date, time, associated exam, and capacity
* Custom roles and permissions (administrators, proctors, students)
* Role-based access to shortcodes
* Email notifications for confirmations, alerts, and reminders

= Is there an access control system to allow only booked users to take the exam? =

Yes. EC can automatically block access to Tutor LMS exams for users who do not have a valid booking for that session.

This ensures security, organization, and compliance with examination rules without manual intervention.

= Are there limitations in the base version? =

Yes. The base version allows management of:

* Up to 5 seats per session
* One time slot per day

The premium version increases these limits (up to 500 seats per session and multiple daily slots) and includes priority support.

= Is EC fully integrated with Tutor LMS and WordPress? =

Yes. EC works natively within WordPress and integrates directly with Tutor LMS without requiring complex configuration or code modifications.

All management operations are performed from the standard WordPress administration interface.

= What are the minimum requirements and compatibility details? =

EC requires:

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Tutor LMS installed and active

All plugin strings are fully internationalized and compatible with translation tools such as Loco Translate.

== Screenshots ==

1. Generate new exam sessions using the session creation interface
2. View and manage all exam sessions in the administrator dashboard
3. Students can book exam sessions using the interactive booking interface
4. Students can manage and cancel their existing bookings
5. Administrators can view, filter, and export bookings in CSV format
6. Calendar view showing available exam sessions and availability status
7. Access control message displayed when a user attempts to access an exam without a valid booking
8. Booking and cancellation settings for exam sessions
9. Shortcode assignment settings for booking and booking management pages
10. Role-based access control settings for administrators, proctors, and students
11. Advanced exam access settings, including booking validity rules and protected exams

== Changelog ==

= 1.2.6 =
* Initial public release on WordPress.org
* Improved stability and compatibility with Tutor LMS
* Code cleanup and performance improvements

= 1.1.0 =
* Added Quiz Statistics admin page with per-quiz aggregated data (attempts, students, scores, pass/fail rate)
* Added Recalculate action to rebuild stats from Tutor LMS quiz attempts
* Added Export CSV for quiz statistics
* Unified "Allowed proctor roles" checkbox styling in Settings → Role Settings
* New DB table mcems_quiz_stats (created via dbDelta; removed on uninstall)

= 1.0.0 =
* Initial stable release
* Exam session booking with calendar view
* Session management with proctor assignment
* Bookings list with CSV export
* Configurable roles and permissions
* Exam access control with time-based locking
* Full WordPress.org compatibility
* All strings properly internationalized
* Complete security hardening
