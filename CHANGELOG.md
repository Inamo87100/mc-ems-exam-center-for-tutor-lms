# Changelog

## [1.1.0] - 2026-04-02

### Added
- **Quiz Statistics** admin page (`MC-EMS → Quiz Statistics`): aggregated statistics per Tutor LMS quiz (total attempts, unique students, average score, pass/fail counts, pass rate, highest and lowest scores). Requires Tutor LMS to be active.
- **Recalculate** action: rebuilds stats from the live `tutor_quiz_attempts` table via a nonce-protected POST form.
- **Export CSV** action: downloads all quiz stats as a UTF-8 CSV file (nonce-protected, admin-only).
- New custom DB table `{prefix}mcems_quiz_stats` created via `dbDelta` on activation/upgrade.
- Table removed automatically on plugin uninstall.

### Changed
- Unified the styling of the **Allowed proctor roles** checkbox group in Settings → Role Settings to match the bordered card layout used by the Shortcode Visibility boxes.
- Bumped plugin version and DB version to `1.1.0`.

## [2.5.0] - 2026-03-17

### Added
- **Proctor Roles** feature implemented, allowing users to assign specific roles to proctors, enhancing the control and management of examinations.

### Improvements
- Improved user interface for the Proctor Management Dashboard, making it more intuitive and user-friendly.
- Enhanced performance for loading proctor-related data, resulting in faster access and reduced wait times.

### Documentation Updates
- Updated documentation to include detailed usage instructions for the Proctor Roles feature.
- Added examples and best practices for managing proctor assignments effectively.
