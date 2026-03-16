# MC-EMS – Exam Session Management for Tutor LMS

MC-EMS is a complete **exam management** plugin for WordPress and **Tutor LMS**. It lets you create exam sessions, manage **exam bookings** with an interactive booking calendar, assign proctors, export data to CSV, and automatically control exam access — all from your WordPress dashboard.

Whether you run a **certification** programme, a university, or a large **elearning** platform, MC-EMS integrates directly with Tutor LMS to bring structured **exam scheduling**, **student booking**, and access-gate enforcement into your **learning management system**.

## Ideal for

- Certification bodies
- Universities and academies
- Elearning platforms based on Tutor LMS
- Schools and professional training centres
- Organisations managing scheduled exams

## Features

### Exam Session Management

MC-EMS introduces a dedicated Custom Post Type for **exam sessions** (`mcems_exam_session`). Each session stores date, time, associated exam, capacity, and operational settings. Sessions are fully manageable from the WordPress admin panel through an intuitive interface.

### Exam Booking Calendar

**Shortcode**: `[mcems_book_exam]`

Students can book an **exam session** using an interactive **booking calendar** that displays all available sessions filtered by date.

### Student Booking Management

**Shortcode**: `[mcems_manage_booking]`

Students can view and manage their own booking, check the exam date and time, see the associated course details, and cancel the booking when permitted.

### Bookings List with Search and CSV Export

**Shortcode**: `[mcems_bookings_list]`

- Complete list of all bookings
- Search by date or date range
- Filters by exam, candidate, and status
- Export bookings to CSV
- Display of candidate special needs
- Assigned proctor information

### Administrative Sessions Calendar

**Shortcode**: `[mcems_sessions_calendar]`

The administrative calendar lets you view all **exam sessions**, check available seats, assign proctors, and monitor booking status.

### Exam Access Control with Tutor LMS

MC-EMS integrates an **access gate** that automatically blocks exam access until the student holds a valid booking for an available session.

When access is blocked:
- The exam remains inaccessible
- An "Exam locked" message is displayed
- The exam content is hidden
- The student receives instructions to book a session

### Configurable Settings

- Booking page configuration
- Minimum booking lead-time management
- Booking cancellation management
- Tutor LMS integration
- System message customisation
- Administrative permissions management

## Base vs Premium

| Feature | Base | Premium |
|---|---|---|
| Exam sessions | ✓ | ✓ |
| Exam booking | ✓ | ✓ |
| Booking calendar | ✓ | ✓ |
| Student booking management | ✓ | ✓ |
| Bookings list | ✓ | ✓ |
| CSV export | ✓ | ✓ |
| Administrative sessions calendar | ✓ | ✓ |
| Proctor assignment | ✓ | ✓ |
| Tutor LMS integration | ✓ | ✓ |
| **Unlimited exam sessions** | — | ✓ |
| **Session capacity** | up to 5 seats | up to 500 seats |
| **Multiple slots per day** | — | ✓ |
| **Priority support** | — | ✓ |

## Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 7.0 or higher
- **Tutor LMS**: Required for exam integration

## Installation

1. Download the plugin from the WordPress Repository
2. Activate the plugin in the WordPress Plugins section
3. Configure the settings in the MC‑EMS section
4. Create the exam sessions
5. Insert the shortcodes in the desired pages