# MC-EMS – Exam Session Management for Tutor LMS

MC-EMS is a complete **exam management** plugin for WordPress and **Tutor LMS**. It lets you create exam sessions, manage **exam bookings** with an interactive booking calendar, assign proctors, export data to CSV, and automatically control exam access — all from your WordPress dashboard.

Whether you run a **certification** programme, a university, or a large **elearning** platform, MC-EMS integrates directly with Tutor LMS to bring structured **exam scheduling**, **student booking**, and access-gate enforcement into your **learning management system**.

MC-EMS is ideal for:
- Certification bodies managing scheduled exams
- Universities and academies with online courses
- Elearning platforms powered by Tutor LMS
- Schools and professional training centres
- Digital certification systems requiring strict exam-access control

## Features

### Exam Session Management
MC-EMS introduces a dedicated Custom Post Type (`mcems_exam_session`) for **exam sessions**. Each session stores date, time, associated exam, capacity, special configurations, and more. Sessions are fully manageable from the WordPress admin panel with an intuitive interface and an administrative calendar.

### Exam Booking Calendar
Students can book an exam session using an interactive **exam booking** calendar on the front end of the site.

**Shortcode**: `[mcems_book_exam]`

This shortcode displays available sessions filtered by date and lets students book an exam slot quickly and easily.

### Student Booking Management
Users can view, manage, and cancel their own booking through a dedicated page.

**Shortcode**: `[mcems_manage_booking]`

This page allows the student to:
- View booking details
- Check the exam date and time
- See the associated course
- Cancel the booking (when permitted)

### Bookings List with Advanced Search and CSV Export
MC-EMS includes a complete administrative screen for managing bookings with advanced features.

**Shortcode**: `[mcems_bookings_list]`

Available functions:
- Complete list of all bookings
- Advanced date search (single day or date range)
- Filters by course, candidate, and status
- Data export to CSV with customisable separator
- Display of special candidate needs
- Assigned proctor information

### Administrative Sessions Calendar
MC-EMS includes a calendar for the operational management of **exam sessions** and proctor assignment.

**Shortcode**: `[mcems_sessions_calendar]`

This calendar allows administrators to:
- View all sessions in a single view
- Check capacity and available seats
- Assign and manage proctors
- Monitor bookings per session

### Exam Access Control with Tutor LMS
The plugin integrates a **Tutor LMS** access gate that automatically blocks access to courses and content (lessons, quizzes, materials) for students who do not have a valid booking for that session.

When access is blocked:
- The course remains completely inaccessible
- The "Exam locked" message is displayed
- All course content is hidden
- The student can only see the block message with instructions on how to book

### Configurable Settings System
MC-EMS includes an advanced configuration section in the WordPress panel where you can:
- Configure the pages used for booking and booking management
- Set booking system behaviour (advance hours, cancellations, etc.)
- Configure the Tutor LMS integration
- Customise system messages and texts
- Manage access and display permissions

## Base vs Premium

| Feature | Base | Premium |
|---|---|---|
| Exam sessions (CPT) | ✓ | ✓ |
| Exam booking | ✓ | ✓ |
| Booking calendar | ✓ | ✓ |
| Student booking management | ✓ | ✓ |
| Bookings list | ✓ | ✓ |
| Booking search | ✓ | ✓ |
| CSV export | ✓ | ✓ |
| Administrative sessions calendar | ✓ | ✓ |
| Proctor assignment | ✓ | ✓ |
| Tutor LMS integration | ✓ | ✓ |
| **Unlimited exam sessions** | — | ✓ |
| **Session capacity up to 500** | 5 max | ✓ |
| **Multiple slots per day** | 1 max | ✓ |
| **Advanced search with toggle** | Basic | ✓ |
| **Priority support** | — | ✓ |

## Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 7.0 or higher
- **Tutor LMS**: Active installation required for exam integration

To use the Premium version, the Base plugin must be active.

## Ideal for

MC-EMS is perfect for:
- Certification bodies managing scheduled exams
- Elearning platforms with Tutor LMS
- Universities and academies with online courses
- Schools and training centres with session-based exams
- Digital certification systems
- Organisations requiring strict exam-access control

## Installation

1. Download the plugin from the WordPress Repository
2. Activate the plugin in the WordPress Plugins section
3. (Optional) Activate the Premium version if you have the add-on
4. Configure the settings in the MC-EMS section
5. Create the exam sessions
6. Add the shortcodes to the desired pages