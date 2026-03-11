<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Bookings_List {

    public static function init(): void {
        add_shortcode('mcems_bookings_list', [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_export_csv'], 1);
}

    public static function maybe_export_csv(): void {
        if (!isset($_GET['nfems_export']) || sanitize_text_field($_GET['nfems_export']) !== 'csv') {
            return;
        }

        if (!is_user_logged_in() || !self::can_view()) {
            status_header(403);
            exit;
        }

        $selected_date   = isset($_GET['nfems_date']) ? sanitize_text_field($_GET['nfems_date']) : '';
        $date_from       = isset($_GET['nfems_from']) ? sanitize_text_field($_GET['nfems_from']) : '';
        $date_to         = isset($_GET['nfems_to']) ? sanitize_text_field($_GET['nfems_to']) : '';$selected_course = isset($_GET['nfems_course']) ? (int) $_GET['nfems_course'] : 0;
        $advanced       = isset($_GET['nfems_adv']) && (string)$_GET['nfems_adv'] === '1';

        $filter = self::normalize_date_filter($selected_date, $date_from, $date_to, $advanced);
        if (!$filter) {
            status_header(400);
            echo 'Missing or invalid date filter.';
            exit;
        }

        $rows = self::build_rows($filter, $selected_course);

        $label = ($filter['type'] ?? '') === 'single'
            ? (string) ($filter['date'] ?? '')
            : ((string) ($filter['from'] ?? '') . '_' . (string) ($filter['to'] ?? ''));
        $filename = 'exam_bookings_' . $label;

        if ($selected_course > 0) {
            $course_title = '';
            if (class_exists('NFEMS_Tutor')) {
                $course_title = (string) NFEMS_Tutor::course_title($selected_course);
            }
            if (!$course_title) {
                $course_title = (string) get_the_title($selected_course);
            }
            $course_slug = sanitize_file_name($course_title ? $course_title : ('course-' . $selected_course));
            $filename .= '_' . $course_slug;
        }

        $filename .= '.csv';

        while (ob_get_level()) { @ob_end_clean(); }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, ['Last name','First name','Email','Exam session date','Exam session time','Course','Special','Proctor'], ';');

        foreach ($rows as $r) {
            $data_h = '';
            if (!empty($r['data'])) $data_h = date_i18n('d/m/Y', strtotime($r['data']));
            $corso_t = '';
            if (!empty($r['corso']) && class_exists('NFEMS_Tutor')) {
                $corso_t = NFEMS_Tutor::course_title((int) $r['corso']);
            }
            $spec = !empty($r['special']) ? 'Yes' : 'No';

            fputcsv($out, [
                $r['cognome'] ?? '',
                $r['nome'] ?? '',
                $r['email'] ?? '',
                $data_h,
                $r['ora'] ?? '',
                $corso_t,
                $spec,
                $r['proctor'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }


    private static function can_view(): bool {
        $cap = NFEMS_Settings::get_str('cap_view_bookings');
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
     * Priority: range (from/to) > single date.
     */
    private static function normalize_date_filter($single, $from, $to, $advanced): ?array {
    $is_date = function(string $d): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    };

    if ($advanced) {
        // Range mode: require from/to. Ignore single date.
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

    // Basic: single date only. Ignore advanced fields.
    if ($is_date($single)) {
        return [
            'type' => 'single',
            'date' => $single,
        ];
    }

    return null;
}



    private static function build_rows(array $filter, int $selected_course): array {
        // Query sessions for date filter (and optional course)
        $meta = [];
        if (($filter['type'] ?? '') === 'single' && !empty($filter['date'])) {
            $meta[] = [
                'key'     => NFEMS_CPT_Sessioni_Esame::MK_DATE,
                'value'   => (string) $filter['date'],
                'compare' => '=',
            ];
        } elseif (($filter['type'] ?? '') === 'range' && !empty($filter['from']) && !empty($filter['to'])) {
            $meta[] = [
                'key'     => NFEMS_CPT_Sessioni_Esame::MK_DATE,
                'value'   => [(string) $filter['from'], (string) $filter['to']],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ];
        }
        if ($selected_course > 0) {
            $meta[] = [
                'key'     => NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID,
                'value'   => $selected_course,
                'compare' => '=',
            ];
        }

        $session_ids = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta,
            'orderby'        => 'meta_value',
            'meta_key'       => NFEMS_CPT_Sessioni_Esame::MK_TIME,
            'order'          => 'ASC',
        ]);

        $rows = [];
        foreach ($session_ids as $sid) {
            $sid = (int) $sid;
            $date = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $time = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $course_id = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);

            $occ = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            if (!is_array($occ) || empty($occ)) continue;

            $is_special = ((int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);

            $proctor_id = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            $proctor = $proctor_id ? get_user_by('id', $proctor_id) : null;
            $proctor_label = $proctor ? $proctor->display_name : '—';

            foreach ($occ as $uid) {
                $uid = (int) $uid;
                $u = get_user_by('id', $uid);
                if (!$u) continue;

                $fn = trim((string) get_user_meta($uid, 'first_name', true));
                $ln = trim((string) get_user_meta($uid, 'last_name', true));
                if ($fn === '' && $ln === '') $fn = $u->display_name;

                $rows[] = [
                    'cognome' => $ln,
                    'nome'    => $fn,
                    'email'   => $u->user_email,
                    'data'    => $date,
                    'ora'     => $time,
                    'corso'   => $course_id,
                    'special' => $is_special,
                    'proctor' => $proctor_label,
                ];
            }
        }

        usort($rows, function($a,$b){
            $ka = ($a['data'] ?? '').' '.($a['ora'] ?? '');
            $kb = ($b['data'] ?? '').' '.($b['ora'] ?? '');
            if ($ka === $kb) return strcmp(($a['cognome'] ?? ''), ($b['cognome'] ?? ''));
            return strcmp($ka, $kb);
        });

        return $rows;
    }


    public static function shortcode(): string {
        if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
        if (!self::can_view()) return '<p>Insufficient permissions.</p>';

        $courses = NFEMS_Tutor::get_courses();
        $course_pt = NFEMS_Tutor::course_post_type();

        $selected_date = isset($_GET['nfems_date']) ? sanitize_text_field($_GET['nfems_date']) : '';
        $date_from     = isset($_GET['nfems_from']) ? sanitize_text_field($_GET['nfems_from']) : '';
        $date_to       = isset($_GET['nfems_to']) ? sanitize_text_field($_GET['nfems_to']) : '';$selected_course = isset($_GET['nfems_course']) ? (int) $_GET['nfems_course'] : 0;
        $advanced       = isset($_GET['nfems_adv']) && (string)$_GET['nfems_adv'] === '1';

        $filter = self::normalize_date_filter($selected_date, $date_from, $date_to, $advanced);
        $has_filter = (bool) $filter;
ob_start();
        ?>
        <style>
            .nfems-adminwrap{max-width:1200px;margin:0 auto;}
            .nfems-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 1px 2px rgba(16,24,40,.06);}
            .nfems-title{margin:0 0 6px;font-size:1.2rem;font-weight:900;}
            .nfems-desc{margin:0 0 14px;color:#667085;}
            .nfems-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;}
            .nfems-field{display:flex;flex-direction:column;gap:6px;}
            .nfems-field label{font-size:12px;font-weight:800;color:#344054;}
            .nfems-field input,.nfems-field select{min-width:240px;padding:9px 10px;border-radius:12px;border:1px solid #d0d5dd;background:#fff;}
            .nfems-actions{display:flex;gap:10px;align-items:center;}
            .nfems-btn{appearance:none;border:1px solid #d0d5dd;background:#101828;color:#fff;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;}
            .nfems-btn:hover{filter:brightness(1.05);}
            .nfems-link{font-weight:800;color:#344054;text-decoration:none;border:1px solid #d0d5dd;border-radius:12px;padding:10px 14px;background:#fff;}
            .nfems-link:hover{background:#f9fafb;}
            .nfems-hint{margin-top:10px;color:#667085;font-size:12px;}
            .nfems-tablewrap{margin-top:14px;overflow:auto;}
            table.nfems-table{min-width:1100px;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid #e5e7eb;border-radius:14px;}
            table.nfems-table thead th{background:#f9fafb;color:#344054;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.02em;padding:10px;border-bottom:1px solid #e5e7eb;}
            table.nfems-table tbody td{padding:10px;border-bottom:1px solid #f2f4f7;vertical-align:top;}
            table.nfems-table tbody tr:hover td{background:#fcfcfd;}
            .nfems-empty{margin-top:12px;padding:12px;border:1px dashed #d0d5dd;border-radius:14px;color:#667085;background:#fcfcfd;}
            .nfems-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px;font-weight:800;}
        </style>

        <div class="nfems-adminwrap">
            <div class="nfems-panel">
                <h3 class="nfems-title"><?php echo esc_html('Exam exam bookings list'); ?></h3>
                <p class="nfems-desc"><?php echo sprintf(esc_html('Filter by %1$sdate%2$s (single day or date range). You can also filter by course.'), '<strong>', '</strong>'); ?></p>

<div class="nfems-search-toggle" style="margin:8px 0 14px 0;">
    <button type="button" id="nfems_adv_btn" class="nfems-btn" aria-pressed="false">
        Advanced search
    </button>
</div>


                <form method="get" class="nfems-filters">
                    <input type="hidden" name="post_type" value="<?php echo esc_attr(NFEMS_CPT_Sessioni_Esame::CPT); ?>">
                    <?php
                    // Preserve "page" if inside admin, but shortcode may be used anywhere.
                    if (isset($_GET['page'])) {
                        echo '<input type="hidden" name="page" value="' . esc_attr(sanitize_text_field($_GET['page'])) . '">';
                    }
                    ?>

                    
<input type="hidden" id="nfems_adv" name="nfems_adv" value="<?php echo $advanced ? '1' : '0'; ?>">
<div class="nfems-basic-filters" style="display:flex; gap:12px; flex-wrap:wrap;">
<div class="nfems-field">
                        <label for="nfems_date"><?php echo esc_html('Date'); ?></label>
                        <input type="date" id="nfems_date" name="nfems_date" value="<?php echo esc_attr($selected_date); ?>">
                    </div>

                    
<div class="nfems-field">
                        <label for="nfems_course"><?php echo esc_html('Course'); ?></label>
                        <select id="nfems_course" name="nfems_course">
                            <option value="0"><?php echo esc_html('All courses'); ?></option>
                            <?php if ($course_pt && $courses): foreach ($courses as $cid => $title): ?>
                                <option value="<?php echo (int)$cid; ?>" <?php selected($selected_course, (int)$cid); ?>>
                                    <?php echo esc_html($title); ?>
                                </option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>


</div>
<div class="nfems-advanced-filters" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:10px;">
<div class="nfems-field">
                        <label for="nfems_from"><?php echo esc_html('From'); ?></label>
                        <input type="date" id="nfems_from" name="nfems_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>

                    
<div class="nfems-field">
                        <label for="nfems_to"><?php echo esc_html('To'); ?></label>
                        <input type="date" id="nfems_to" name="nfems_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>
<div class="nfems-field">
                        <label for="nfems_course"><?php echo esc_html('Course'); ?></label>
                        <select id="nfems_course" name="nfems_course">
                            <option value="0"><?php echo esc_html('All courses'); ?></option>
                            <?php if ($course_pt && $courses): foreach ($courses as $cid => $title): ?>
                                <option value="<?php echo (int)$cid; ?>" <?php selected($selected_course, (int)$cid); ?>>
                                    <?php echo esc_html($title); ?>
                                </option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>

                    
</div>
<div class="nfems-actions">
                        <button class="nfems-btn" type="submit"><?php echo esc_html('Filter'); ?></button>
                        <a class="nfems-link" href="<?php echo esc_url(remove_query_arg(['nfems_date','nfems_from','nfems_to','nfems_course'])); ?>">Reset</a>
                    <?php if ($has_filter): ?>
                        <button class="nfems-btn" type="submit" name="nfems_export" value="csv"><?php echo esc_html('Export CSV'); ?></button>
                        <?php endif; ?>
                    </div>
                <script>
(function(){
    var btn = document.getElementById('nfems_adv_btn');
    var adv = document.getElementById('nfems_adv');
    var basicWrap = document.querySelector('.nfems-basic-filters');
    var advWrap   = document.querySelector('.nfems-advanced-filters');

    function setMode(isAdv){
        if(adv) adv.value = isAdv ? '1':'0';
        if(basicWrap) basicWrap.style.display = isAdv ? 'none':'flex';
        if(advWrap) advWrap.style.display = isAdv ? 'flex':'none';

        if(btn){
            btn.setAttribute('aria-pressed', isAdv ? 'true' : 'false');
            // Update button label: show the action to switch mode
            btn.textContent = isAdv ? 'Basic search' : 'Advanced search';
            var sw = btn.querySelector('.nfems-adv-switch');
            var kb = btn.querySelector('.nfems-adv-knob');
            if(sw) sw.style.background = isAdv ? '#101828' : '#e4e7ec';
            if(kb) kb.style.left = isAdv ? '18px' : '2px';
        }

        // Clear fields from the hidden mode to avoid confusion in URLs
        if(isAdv){
            var d = document.getElementById('nfems_date'); if(d) d.value='';
        } else {
            ['nfems_from','nfems_to'].forEach(function(id){
                var el=document.getElementById(id);
                if(!el) return;
                if(el.tagName==='SELECT') el.value='0';
                else el.value='';
            });
        }
    }

    function isAdvMode(){
        return adv && adv.value === '1';
    }

    if(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            setMode(!isAdvMode());
        });
    }

    setMode(!!(adv && adv.value === '1'));
})();
</script>
</form>

                <?php if (!$has_filter): ?>
                    <div class="nfems-empty">📌 <?php echo sprintf(esc_html('Select a date filter and press %1$sFilter%2$s to see the exam bookings list.'), '<strong>', '</strong>'); ?></div>
                <?php else: ?>

                    <?php
                    $rows = self::build_rows($filter, $selected_course);

                    $label = '';
                    if (($filter['type'] ?? '') === 'single' && !empty($filter['date'])) {
                        $label = date_i18n('d/m/Y', strtotime((string)$filter['date']));
                    } elseif (($filter['type'] ?? '') === 'range' && !empty($filter['from']) && !empty($filter['to'])) {
                        $label = date_i18n('d/m/Y', strtotime((string)$filter['from'])) . ' → ' . date_i18n('d/m/Y', strtotime((string)$filter['to']));
                    }
                    ?>
                    <div class="nfems-hint">
                        <span class="nfems-pill">📅 <?php echo esc_html('Date:'); ?> <strong><?php echo esc_html($label); ?></strong></span>
                        <?php if ($selected_course > 0): ?>
                            <span class="nfems-pill">📘 <?php echo esc_html('Course:'); ?> <strong><?php echo esc_html(NFEMS_Tutor::course_title($selected_course)); ?></strong></span>
                        <?php else: ?>
                            <span class="nfems-pill">📘 <?php echo esc_html('Course:'); ?> <strong><?php echo esc_html('All'); ?></strong></span>
                        <?php endif; ?>
                        <span class="nfems-pill"><?php echo esc_html('👥 Exam bookings:'); ?> <strong><?php echo (int) count($rows); ?></strong></span>
                    </div>

                    <div class="nfems-tablewrap">
                        <table class="nfems-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html('Last name'); ?></th>
                                    <th><?php echo esc_html('First name'); ?></th>
                                    <th><?php echo esc_html('Email'); ?></th>
                                    <th><?php echo esc_html('Exam session date'); ?></th>
                                    <th><?php echo esc_html('Exam session time'); ?></th>
                                    <th><?php echo esc_html('Course'); ?></th>
                                    <th>♿</th>
                                    <th><?php echo esc_html('Proctor'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="8" style="text-align:center;color:#667085;padding:14px;">No exam bookings found for these filters.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo esc_html($r['cognome']); ?></td>
                                    <td><?php echo esc_html($r['nome']); ?></td>
                                    <td><?php echo esc_html($r['email']); ?></td>
                                    <td><?php echo esc_html( date_i18n('d/m/Y', strtotime($r['data'])) ); ?></td>
                                    <td><?php echo esc_html($r['ora']); ?></td>
                                    <td><?php echo esc_html(NFEMS_Tutor::course_title((int)$r['corso'])); ?></td>
                                    <td><?php echo self::badge_special(!empty($r['special'])); ?></td>
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