<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Tutor {

    public static function course_post_type(): string {
        if (post_type_exists('courses')) return 'courses';
        if (post_type_exists('tutor_course')) return 'tutor_course';
        return '';
    }

    public static function get_courses(): array {
        $pt = self::course_post_type();
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

    public static function course_title(int $course_id): string {
        if ($course_id <= 0) return '';
        return (string) get_the_title($course_id);
    }
}
