<?php
if (!defined('ABSPATH')) exit;

class MCEMEXCE_Bookings_List_Base {

    public static function init(): void {
        add_shortcode('mcemexce_bookings_list', [__CLASS__, 'shortcode']);
        add_shortcode('mcemexce_exam_bookings_list', [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_export_csv'], 1);
    }

    private static function can_view(): bool {
        $cap = MCEMEXCE_Settings::get_str('cap_view_bookings');
        if (!$cap) $cap = 'manage_options';
        return current_user_can($cap) || current_user_can('manage_options');
    }

    private static function badge_special(bool $is_special): string {
        if ($is_special) {
            return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#e8f0fe;color:#1a73e8;font-weight:800;font-size:12px;">♿ Yes</span>';
        }
        return '<span style="color:#98a2b3;">—</span>';
    }

    /**
     * Normalize date filters.
     * In advanced mode: requires from/to date range.
     * In basic mode: requires single date.
     */
    private static function normalize_date_filter(string $single, string $from, string $to, bool $advanced): ?array {
        $is_date = function(string $d): bool {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        };

        if ($advanced) {
            if ($is_date($from) && $is_date($to)) {
                if (strtotime($to) < strtotime($from)) return null;
                return [
                    'type' => 'range',
                    'from' => $from,
                    'to'   => $to,
                ];
            }
            return null;
        }

        if ($is_date($single)) {
            return [
                'type' => 'single',
                'date' => $single,
            ];
        }

        return null;
    }

    private static function build_rows(array $filter, int $selected_exam): array {
        $meta = [];
        if (($filter['type'] ?? '') === 'single' && !empty($filter['date'])) {
            $meta[] = [
                'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE,
                'value'   => (string) $filter['date'],
                'compare' => '=',
            ];
        } elseif (($filter['type'] ?? '') === 'range' && !empty($filter['from']) && !empty($filter['to'])) {
            $meta[] = [
                'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE,
                'value'   => [(string) $filter['from'], (string) $filter['to']],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ];
        }
        if ($selected_exam > 0) {
            $meta[] = [
                'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_EXAM_ID,
                'value'   => $selected_exam,
                'compare' => '=',
            ];
        }

        $session_ids = get_posts([
            'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            // TODO: Plugin Check slow-query warning – meta_query on postmeta is necessary here;
            // consider a custom table for large-scale deployments.
            'meta_query'     => $meta,
            'orderby'        => 'meta_value',
            // TODO: Plugin Check – meta_key used for ordering; acceptable with proper index.
            'meta_key'       => MCEMEXCE_CPT_Sessioni_Esame::MK_TIME,
            'order'          => 'ASC',
        ]);

        $rows = [];
        foreach ($session_ids as $sid) {
            $sid = (int) $sid;
            $date      = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, true);
            $time      = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_TIME, true);
            $exam_id = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_EXAM_ID, true);

            $occ = get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            if (!is_array($occ) || empty($occ)) continue;

            $is_special = ((int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);

            $proctor_id    = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            $proctor       = $proctor_id ? get_user_by('id', $proctor_id) : null;
            $proctor_label = $proctor ? $proctor->display_name : '—';

            foreach ($occ as $uid) {
                $uid = (int) $uid;
                $u = get_user_by('id', $uid);
                if (!$u) continue;

                $fn = trim((string) get_user_meta($uid, 'first_name', true));
                $ln = trim((string) get_user_meta($uid, 'last_name', true));
                if ($fn === '' && $ln === '') $fn = $u->display_name;

                $rows[] = [
                    'session_id' => $sid,
                    'cognome' => $ln,
                    'nome'    => $fn,
                    'email'   => $u->user_email,
                    'data'    => $date,
                    'ora'     => $time,
                    'corso'   => $exam_id,
                    'special' => $is_special,
                    'proctor' => $proctor_label,
                ];
            }
        }

        usort($rows, function($a, $b) {
            $ka = ($a['data'] ?? '') . ' ' . ($a['ora'] ?? '');
            $kb = ($b['data'] ?? '') . ' ' . ($b['ora'] ?? '');
            if ($ka === $kb) return strcmp(($a['cognome'] ?? ''), ($b['cognome'] ?? ''));
            return strcmp($ka, $kb);
        });

        return $rows;
    }

    public static function maybe_export_csv(): void {
        if (!isset($_GET['mcemexce_export']) || sanitize_text_field(wp_unslash($_GET['mcemexce_export'])) !== 'csv') {
            return;
        }

        if (!is_user_logged_in() || !self::can_view()) {
            status_header(403);
            exit;
        }

        if (!isset($_GET['mcemexce_export_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['mcemexce_export_nonce'])), 'mcemexce_export_csv')) {
            status_header(403);
            exit;
        }

        $selected_date  = isset($_GET['mcemexce_date']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_date'])) : '';
        $date_from      = isset($_GET['mcemexce_from']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_from'])) : '';
        $date_to        = isset($_GET['mcemexce_to']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_to'])) : '';
        $selected_exam  = isset($_GET['mcemexce_exam']) ? absint(wp_unslash($_GET['mcemexce_exam'])) : 0;
        $advanced       = isset($_GET['mcemexce_adv']) && sanitize_text_field(wp_unslash($_GET['mcemexce_adv'])) === '1';

        $filter = self::normalize_date_filter($selected_date, $date_from, $date_to, $advanced);
        if ($filter === null) {
            status_header(400);
            echo esc_html__('Missing or invalid date filter.', 'mc-ems-exam-center-for-tutor-lms');
            exit;
        }

        $rows = self::build_rows($filter, $selected_exam);

        $label = ($filter['type'] ?? '') === 'single'
            ? (string) ($filter['date'] ?? '')
            : ((string) ($filter['from'] ?? '') . '_' . (string) ($filter['to'] ?? ''));
        $filename = 'exam_bookings_' . $label;

        if ($selected_exam > 0) {
            $exam_title = '';
            if (class_exists('MCEMEXCE_Tutor')) {
                $exam_title = (string) MCEMEXCE_Tutor::exam_title($selected_exam);
            }
            if (!$exam_title) {
                $exam_title = (string) get_the_title($selected_exam);
            }
            $exam_slug = sanitize_file_name($exam_title ? $exam_title : ('exam-' . $selected_exam));
            $filename .= '_' . $exam_slug;
        }

        $filename .= '.csv';

        while (ob_get_level()) { @ob_end_clean(); }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        // UTF-8 BOM for Excel
        echo chr(0xEF) . chr(0xBB) . chr(0xBF); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $header_row = ['Last name', 'First name', 'Email', 'Session ID', 'Exam session date', 'Exam session time', 'Exam', 'Special', 'Proctor'];
        echo self::format_csv_row($header_row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        foreach ($rows as $r) {
            $data_h = !empty($r['data']) ? date_i18n('d/m/Y', strtotime($r['data'])) : '';
            $corso_t = '';
            if (!empty($r['corso']) && class_exists('MCEMEXCE_Tutor')) {
                $corso_t = MCEMEXCE_Tutor::exam_title((int) $r['corso']);
            }
            $spec = !empty($r['special']) ? 'Yes' : 'No';

            echo self::format_csv_row([ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output, not HTML
                esc_html($r['cognome'] ?? ''),
                esc_html($r['nome']    ?? ''),
                esc_html($r['email']   ?? ''),
                esc_html($r['session_id'] ?? ''),
                esc_html($data_h),
                esc_html($r['ora']     ?? ''),
                esc_html($corso_t),
                esc_html($spec),
                esc_html($r['proctor'] ?? ''),
            ]);
        }

        exit;
    }

    private static function format_csv_row(array $fields, string $delimiter = ';'): string {
        $escaped = array_map(function($field) {
            $field = (string) $field;
            if (preg_match('/[\";\n]/', $field)) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $fields);
        return implode($delimiter, $escaped) . "\n";
    }

    public static function shortcode(): string {
        if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
        if (!self::can_view()) return '<p>Insufficient permissions.</p>';

        $exams = MCEMEXCE_Tutor::get_exams();
        $exam_pt = MCEMEXCE_Tutor::exam_post_type();

        $filter_nonce  = isset($_GET['mcemexce_filter_nonce']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_filter_nonce'])) : '';
        $nonce_valid   = wp_verify_nonce($filter_nonce, 'mcemexce_filter');

        $selected_date  = ($nonce_valid && isset($_GET['mcemexce_date'])) ? sanitize_text_field(wp_unslash($_GET['mcemexce_date'])) : '';
        $date_from      = ($nonce_valid && isset($_GET['mcemexce_from'])) ? sanitize_text_field(wp_unslash($_GET['mcemexce_from'])) : '';
        $date_to        = ($nonce_valid && isset($_GET['mcemexce_to'])) ? sanitize_text_field(wp_unslash($_GET['mcemexce_to'])) : '';
        $selected_exam  = ($nonce_valid && isset($_GET['mcemexce_exam'])) ? absint(wp_unslash($_GET['mcemexce_exam'])) : 0;
        $advanced       = $nonce_valid && isset($_GET['mcemexce_adv']) && sanitize_text_field(wp_unslash($_GET['mcemexce_adv'])) === '1';

        $filter     = self::normalize_date_filter($selected_date, $date_from, $date_to, $advanced);
        $has_filter = (bool) $filter;

        ob_start();
        ?>
        <style>
            .mcems-adminwrap{max-width:1200px;margin:0 auto;}
            .mcems-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 1px 2px rgba(16,24,40,.06);}
            .mcems-title{margin:0 0 6px;font-size:1.2rem;font-weight:900;}
            .mcems-desc{margin:0 0 14px;color:#667085;}
            .mcems-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;}
            .mcems-field{display:flex;flex-direction:column;gap:6px;}
            .mcems-field label{font-size:12px;font-weight:800;color:#344054;}
            .mcems-field input,.mcems-field select{min-width:240px;padding:9px 10px;border-radius:12px;border:1px solid #d0d5dd;background:#fff;}
            .mcems-actions{display:flex;gap:10px;align-items:center;}
            .mcems-btn{appearance:none;border:1px solid #d0d5dd;background:#101828;color:#fff;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;}
            .mcems-btn:hover{filter:brightness(1.05);}
            .mcems-link{font-weight:800;color:#344054;text-decoration:none;border:1px solid #d0d5dd;border-radius:12px;padding:10px 14px;background:#fff;}
            .mcems-link:hover{background:#f9fafb;}
            .mcems-hint{margin-top:10px;color:#667085;font-size:12px;}
            .mcems-tablewrap{margin-top:14px;overflow:auto;}
            table.mcems-table{min-width:1100px;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid #e5e7eb;border-radius:14px;}
            table.mcems-table thead th{background:#f9fafb;color:#344054;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.02em;padding:10px;border-bottom:1px solid #e5e7eb;}
            table.mcems-table tbody td{padding:10px;border-bottom:1px solid #f2f4f7;vertical-align:top;}
            table.mcems-table tbody tr:hover td{background:#fcfcfd;}
            .mcems-empty{margin-top:12px;padding:12px;border:1px dashed #d0d5dd;border-radius:14px;color:#667085;background:#fcfcfd;}
            .mcems-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px;font-weight:800;}
        </style>

        <div class="mcems-adminwrap">
            <div class="mcems-panel">
                <h3 class="mcems-title"><?php echo esc_html__('Bookings list', 'mc-ems-exam-center-for-tutor-lms'); ?></h3>
                <p class="mcems-desc"><?php
                // translators: %1$s and %2$s are HTML <strong> tags wrapping the word "date"
                echo sprintf(esc_html__('Filter by %1$sdate%2$s (single day or date range). You can also filter by exam.', 'mc-ems-exam-center-for-tutor-lms'), '<strong>', '</strong>'); ?></p>

                <div class="mcems-search-toggle" style="margin:8px 0 14px 0;">
                    <button type="button" id="mcemexce_adv_btn" class="mcems-btn" aria-pressed="false">
                        <?php echo esc_html__('Advanced search', 'mc-ems-exam-center-for-tutor-lms'); ?>
                    </button>
                </div>

                <form method="get" class="mcems-filters">
                    <input type="hidden" name="post_type" value="<?php echo esc_attr(MCEMEXCE_CPT_Sessioni_Esame::CPT); ?>">
                    <?php
                    if (isset($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress admin page slug, read-only navigation
                        echo '<input type="hidden" name="page" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET['page']))) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    }
                    ?>
                    <input type="hidden" name="mcemexce_filter_nonce" value="<?php echo esc_attr(wp_create_nonce('mcemexce_filter')); ?>">
                    <input type="hidden" name="mcemexce_export_nonce" value="<?php echo esc_attr(wp_create_nonce('mcemexce_export_csv')); ?>">

                    <input type="hidden" id="mcemexce_adv" name="mcemexce_adv" value="<?php echo esc_attr($advanced ? '1' : '0'); ?>">

                    <div class="mcems-basic-filters" style="display:flex; gap:12px; flex-wrap:wrap;">
                        <div class="mcems-field">
                            <label for="mcemexce_date"><?php echo esc_html__('Date', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                            <input type="date" id="mcemexce_date" name="mcemexce_date" value="<?php echo esc_attr($selected_date); ?>">
                        </div>
                        <div class="mcems-field">
                            <label for="mcemexce_exam"><?php echo esc_html__('Exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                            <select id="mcemexce_exam" name="mcemexce_exam">
                                <option value="0"><?php echo esc_html__('All exams', 'mc-ems-exam-center-for-tutor-lms'); ?></option>
                                <?php if ($exam_pt && $exams): foreach ($exams as $cid => $title): ?>
                                    <option value="<?php echo (int) $cid; ?>" <?php selected($selected_exam, (int) $cid); ?>>
                                        <?php echo esc_html($title); ?>
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mcems-advanced-filters" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:10px;">
                        <div class="mcems-field">
                            <label for="mcemexce_from"><?php echo esc_html__('From', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                            <input type="date" id="mcemexce_from" name="mcemexce_from" value="<?php echo esc_attr($date_from); ?>">
                        </div>
                        <div class="mcems-field">
                            <label for="mcemexce_to"><?php echo esc_html__('To', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                            <input type="date" id="mcemexce_to" name="mcemexce_to" value="<?php echo esc_attr($date_to); ?>">
                        </div>
                        <div class="mcems-field">
                            <label for="mcemexce_exam_adv"><?php echo esc_html__('Exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                            <select id="mcemexce_exam_adv" name="mcemexce_exam">
                                <option value="0"><?php echo esc_html__('All exams', 'mc-ems-exam-center-for-tutor-lms'); ?></option>
                                <?php if ($exam_pt && $exams): foreach ($exams as $cid => $title): ?>
                                    <option value="<?php echo (int) $cid; ?>" <?php selected($selected_exam, (int) $cid); ?>>
                                        <?php echo esc_html($title); ?>
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mcems-actions">
                        <button class="mcems-btn" type="submit"><?php echo esc_html__('Filter', 'mc-ems-exam-center-for-tutor-lms'); ?></button>
                        <a class="mcems-link" href="<?php echo esc_url(remove_query_arg(['mcemexce_date', 'mcemexce_from', 'mcemexce_to', 'mcemexce_exam', 'mcemexce_adv'])); ?>"><?php echo esc_html__('Reset', 'mc-ems-exam-center-for-tutor-lms'); ?></a>
                        <?php if ($has_filter): ?>
                            <button class="mcems-btn" type="submit" name="mcemexce_export" value="csv"><?php echo esc_html__('Export CSV', 'mc-ems-exam-center-for-tutor-lms'); ?></button>
                        <?php endif; ?>
                    </div>

                    <script>
(function(){
    var btn = document.getElementById('mcemexce_adv_btn');
    var adv = document.getElementById('mcemexce_adv');
    var basicWrap = document.querySelector('.mcems-basic-filters');
    var advWrap   = document.querySelector('.mcems-advanced-filters');

    function setMode(isAdv){
        if(adv) adv.value = isAdv ? '1':'0';
        if(basicWrap) basicWrap.style.display = isAdv ? 'none':'flex';
        if(advWrap) advWrap.style.display = isAdv ? 'flex':'none';
        if(btn){
            btn.setAttribute('aria-pressed', isAdv ? 'true' : 'false');
            btn.textContent = isAdv ? '<?php echo esc_js(__('Basic search', 'mc-ems-exam-center-for-tutor-lms')); ?>' : '<?php echo esc_js(__('Advanced search', 'mc-ems-exam-center-for-tutor-lms')); ?>';
        }
        if(isAdv){
            var d = document.getElementById('mcemexce_date'); if(d) d.value='';
        } else {
            ['mcemexce_from','mcemexce_to'].forEach(function(id){
                var el=document.getElementById(id); if(el) el.value='';
            });
        }
    }

    if(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            setMode(!(adv && adv.value === '1'));
        });
    }

    setMode(!!(adv && adv.value === '1'));
})();
                    </script>
                </form>

                <?php if (!$has_filter): ?>
                    <div class="mcems-empty">📌 <?php
                    // translators: %1$s and %2$s are HTML <strong> tags wrapping the word "Filter"
                    echo sprintf(esc_html__('Select a date filter and press %1$sFilter%2$s to see the bookings list.', 'mc-ems-exam-center-for-tutor-lms'), '<strong>', '</strong>'); ?></div>
                <?php else: ?>

                    <?php
                    $rows = self::build_rows($filter, $selected_exam);

                    $label = '';
                    if (($filter['type'] ?? '') === 'single' && !empty($filter['date'])) {
                        $label = date_i18n('d/m/Y', strtotime((string) $filter['date']));
                    } elseif (($filter['type'] ?? '') === 'range' && !empty($filter['from']) && !empty($filter['to'])) {
                        $label = date_i18n('d/m/Y', strtotime((string) $filter['from'])) . ' → ' . date_i18n('d/m/Y', strtotime((string) $filter['to']));
                    }
                    ?>
                    <div class="mcems-hint">
                        <span class="mcems-pill">📅 <?php echo esc_html__('Date:', 'mc-ems-exam-center-for-tutor-lms'); ?> <strong><?php echo esc_html($label); ?></strong></span>
                        <?php if ($selected_exam > 0): ?>
                            <span class="mcems-pill">📘 <?php echo esc_html__('Exam:', 'mc-ems-exam-center-for-tutor-lms'); ?> <strong><?php echo esc_html(MCEMEXCE_Tutor::exam_title($selected_exam)); ?></strong></span>
                        <?php else: ?>
                            <span class="mcems-pill">📘 <?php echo esc_html__('Exam:', 'mc-ems-exam-center-for-tutor-lms'); ?> <strong><?php echo esc_html__('All', 'mc-ems-exam-center-for-tutor-lms'); ?></strong></span>
                        <?php endif; ?>
                        <span class="mcems-pill">👥 <?php echo esc_html__('Bookings:', 'mc-ems-exam-center-for-tutor-lms'); ?> <strong><?php echo (int) count($rows); ?></strong></span>
                    </div>

                    <div class="mcems-tablewrap">
                        <table class="mcems-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Last name', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('First name', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('Email', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('Session ID', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('Exam session date', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('Exam session time', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php echo esc_html__('Exam', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th>♿</th>
                                    <th><?php echo esc_html__('Proctor', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="9" style="text-align:center;color:#667085;padding:14px;"><?php echo esc_html__('No exam bookings found for these filters.', 'mc-ems-exam-center-for-tutor-lms'); ?></td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo esc_html($r['cognome']); ?></td>
                                    <td><?php echo esc_html($r['nome']); ?></td>
                                    <td><?php echo esc_html($r['email']); ?></td>
                                    <td><?php echo esc_html($r['session_id']); ?></td>
                                    <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($r['data']))); ?></td>
                                    <td><?php echo esc_html($r['ora']); ?></td>
                                    <td><?php echo esc_html(MCEMEXCE_Tutor::exam_title((int) $r['corso'])); ?></td>
                                    <td><?php echo wp_kses_post(self::badge_special(!empty($r['special']))); ?></td>
                                    <td><?php echo esc_html($r['proctor']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
