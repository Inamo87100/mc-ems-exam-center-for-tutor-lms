# Changelog

## [1.2.0] - 2026-04-02

### Changed (structural)
- **Quiz Statistics page** (`MC-EMS → Quiz Statistics`) completely redesigned to provide **per-question** statistics (error rate / success rate per question, grouped by course):
  - Added course-filter toolbar (dropdown to select a Tutor LMS course before loading stats).
  - Per-question table with columns: ID, Question, Options (with correct answer highlighted), Quiz, Total Responses, Correct Answers, Wrong Answers, Error Rate (%), Success Rate (%).
  - Sortable column headers.
  - Pagination (25 rows per page).
  - **Recalculate Stats** button (per selected course).
  - Three **CSV export** options: all questions, error rate ≥ 50%, error rate ≤ 3%.
  - Auto-refresh on course selection.
  - Rich inline CSS matching the reference layout (toolbar, pill badges, table, pagination).
- New custom DB table `{prefix}mcems_quiz_stats_cache` created via `dbDelta` on activation/upgrade (replaces the previous `{prefix}mcems_quiz_stats` table for per-question granularity).
- **Admin menu order** updated to: Create sessions → Sessions list → **Quiz statistics** → Settings (previously Quiz Statistics was appended after Settings).

## [2.5.0] - 2026-03-17

### Added
- **Proctor Roles** feature implemented, allowing users to assign specific roles to proctors, enhancing the control and management of examinations.

### Improvements
- Improved user interface for the Proctor Management Dashboard, making it more intuitive and user-friendly.
- Enhanced performance for loading proctor-related data, resulting in faster access and reduced wait times.

### Documentation Updates
- Updated documentation to include detailed usage instructions for the Proctor Roles feature.
- Added examples and best practices for managing proctor assignments effectively.
