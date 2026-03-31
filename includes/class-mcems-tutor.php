<?php
if (!defined('ABSPATH')) exit;

/**
 * MCEMS_Tutor
 *
 * Helper class for Tutor LMS integration.
 * Provides static helpers for exam/student data and activation checks.
 */
class MCEMS_Tutor {

    /**
     * Register hooks (currently a helper-only class; reserved for future hooks).
     */
    public static function init(): void {
        // Intentionally left empty – all methods are static helpers.
    }

    /**
     * Check whether Tutor LMS is active and functional.
     *
     * @return bool
     */
    public static function is_tutor_active(): bool {
        return defined('TUTOR_VERSION') || function_exists('tutor') || function_exists('tutor_utils');
    }

    public static function exam_post_type(): string {
        if (post_type_exists('courses')) return 'courses';
        if (post_type_exists('tutor_course')) return 'tutor_course';
        return '';
    }

    public static function get_exams(): array {
        $pt = self::exam_post_type();
        if (!$pt) return [];

        $ids = get_posts([
            'post_type'      => $pt,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $out = [];
        foreach ($ids as $id) {
            $out[(int)$id] = get_the_title($id);
        }
        return $out;
    }

    /**
     * Get all students enrolled in a given Tutor LMS exam.
     *
     * Returns an array of WP_User-like objects (or plain stdClass rows) each
     * having at least: ID, user_email, display_name.
     *
     * @param int $exam_id
     * @return array
     */
    public static function get_exam_students(int $exam_id): array {
        if ($exam_id <= 0) {
            return [];
        }

        // Primary path: Tutor LMS utility helper.
        if (self::is_tutor_active() && function_exists('tutor_utils') && method_exists(tutor_utils(), 'get_students_data_by_course_id')) {
            $students = tutor_utils()->get_students_data_by_course_id($exam_id);
            return is_array($students) ? $students : [];
        }

        // Fallback: query tutor_enrolled CPT for completed enrolments.
        $enrolled_posts = get_posts([
            'post_type'      => 'tutor_enrolled',
            'post_status'    => 'completed',
            'post_parent'    => $exam_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($enrolled_posts)) {
            return [];
        }

        $author_ids = [];
        foreach ($enrolled_posts as $post_id) {
            $author_id = (int) get_post_field('post_author', (int) $post_id);
            if ($author_id > 0) {
                $author_ids[] = $author_id;
            }
        }

        if (empty($author_ids)) {
            return [];
        }

        $user_query = new WP_User_Query([
            'include' => array_unique($author_ids),
            'fields'  => ['ID', 'user_email', 'display_name'],
        ]);

        return (array) $user_query->get_results();
    }

    public static function exam_title(int $exam_id): string {
        if ($exam_id <= 0) return '';
        return (string) get_the_title($exam_id);
    }

    /**
     * Check whether a user has a valid (active) Tutor LMS enrollment for an exam.
     * Admins and instructors are always considered enrolled.
     */
    public static function is_user_enrolled(int $user_id, int $exam_id): bool {
        if ($user_id <= 0 || $exam_id <= 0) return false;

        // Bypass: admins and instructors do not need an enrollment
        if (user_can($user_id, 'manage_options')) return true;
        if (user_can($user_id, 'tutor_instructor') || user_can($user_id, 'tutor_instructor_manager')) return true;

        // Primary check: Tutor LMS API
        if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'is_enrolled')) {
            return (bool) tutor_utils()->is_enrolled($exam_id, $user_id);
        }

        // Fallback: query the tutor_enrolled post type directly
        $enrolled = get_posts([
            'post_type'      => 'tutor_enrolled',
            'post_status'    => 'completed',
            'post_parent'    => $exam_id,
            'author'         => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return !empty($enrolled);
    }
}
