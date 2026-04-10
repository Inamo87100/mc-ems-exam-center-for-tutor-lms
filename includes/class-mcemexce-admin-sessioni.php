<?php
if (!defined('ABSPATH')) exit;

class MCEMEXCE_Admin_Sessioni {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_mcemexce_user_search', [__CLASS__, 'ajax_user_search']);
    }

    /**
     * AJAX handler: search users by display name or email.
     *
     * Expects POST params: nonce, q (search query, min 2 chars).
     * Returns JSON: [{id, name, email}, ...] (max 20 results, deduplicated).
     */
    public static function ajax_user_search(): void {
        check_ajax_referer('mcemexce_user_search', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (strlen($q) < 2) {
            wp_send_json_success([]);
            return;
        }

        global $wpdb;
        $safe_q = $wpdb->esc_like($q);

        $by_name = new \WP_User_Query([
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['display_name'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $by_email = new \WP_User_Query([
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['user_email'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $seen = [];
        $out  = [];
        foreach (array_merge($by_name->get_results(), $by_email->get_results()) as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'    => $id,
                'name'  => (string) $u->display_name,
                'email' => (string) $u->user_email,
            ];
        }

        wp_send_json_success($out);
    }

    public static function enqueue_assets(string $hook): void {
        // Only load on the plugin's admin pages
        if (strpos($hook, 'mcems') === false && strpos($hook, MCEMEXCE_CPT_Sessioni_Esame::CPT) === false) {
            return;
        }

        $ver = defined('MCEMEXCE_VERSION') ? MCEMEXCE_VERSION : '1.0.0';
        $url = defined('MCEMEXCE_PLUGIN_URL') ? MCEMEXCE_PLUGIN_URL : '';

        wp_register_style('mcems-admin-style', $url . 'assets/css/admin.css', [], $ver);
        wp_enqueue_style('mcems-admin-style');

        // Calendar date-picker styles used in the session generate form.
        wp_add_inline_style('mcems-admin-style', '
            #mcems-calendar{max-width:308px;font-family:inherit;}
            .mcems-cal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
            .mcems-cal-header button{background:none;border:1px solid #c3c4c7;border-radius:4px;cursor:pointer;padding:3px 10px;font-size:15px;line-height:1.4;}
            .mcems-cal-header button:hover{background:#f0f0f1;}
            .mcems-cal-header span{font-weight:600;font-size:14px;}
            .mcems-cal-table{border-collapse:collapse;width:100%;}
            .mcems-cal-table th{text-align:center;font-size:11px;padding:4px 2px;color:#646970;font-weight:600;}
            .mcems-cal-table td{padding:2px;text-align:center;}
            .mcems-cal-day{display:inline-block;width:34px;height:34px;line-height:34px;border-radius:50%;font-size:13px;box-sizing:border-box;}
            .mcems-cal-day[data-date]{cursor:pointer;}
            .mcems-cal-day[data-date]:hover{background:#e8f0fe;}
            .mcems-cal-day.mcems-cal-selected{background:#2271b1;color:#fff !important;}
            .mcems-cal-day.mcems-cal-today{font-weight:700;border:2px solid #2271b1;}
            .mcems-cal-day.mcems-cal-past{color:#c3c4c7;cursor:default;}
            .mcems-cal-day.mcems-cal-past:hover{background:none;}
        ');

        wp_register_script('mcems-admin', $url . 'assets/js/admin.js', [], $ver, true);
        wp_enqueue_script('mcems-admin');

        wp_localize_script('mcems-admin', 'MCEMEXCE_ADMIN', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('mcemexce_admin'),
            'exportNonce'      => wp_create_nonce('mcemexce_export_csv'),
            'userSearchNonce'  => wp_create_nonce('mcemexce_user_search'),
            'monthNames'       => [
                __('January','mc-ems-exam-center-for-tutor-lms'),
                __('February','mc-ems-exam-center-for-tutor-lms'),
                __('March','mc-ems-exam-center-for-tutor-lms'),
                __('April','mc-ems-exam-center-for-tutor-lms'),
                __('May','mc-ems-exam-center-for-tutor-lms'),
                __('June','mc-ems-exam-center-for-tutor-lms'),
                __('July','mc-ems-exam-center-for-tutor-lms'),
                __('August','mc-ems-exam-center-for-tutor-lms'),
                __('September','mc-ems-exam-center-for-tutor-lms'),
                __('October','mc-ems-exam-center-for-tutor-lms'),
                __('November','mc-ems-exam-center-for-tutor-lms'),
                __('December','mc-ems-exam-center-for-tutor-lms'),
            ],
            'dayNames'         => [
                __('Mo','mc-ems-exam-center-for-tutor-lms'),
                __('Tu','mc-ems-exam-center-for-tutor-lms'),
                __('We','mc-ems-exam-center-for-tutor-lms'),
                __('Th','mc-ems-exam-center-for-tutor-lms'),
                __('Fr','mc-ems-exam-center-for-tutor-lms'),
                __('Sa','mc-ems-exam-center-for-tutor-lms'),
                __('Su','mc-ems-exam-center-for-tutor-lms'),
            ],
            'i18n'             => [
                'selectAction'                => __('Please select an action.', 'mc-ems-exam-center-for-tutor-lms'),
                'selectItems'                 => __('Please select at least one item.', 'mc-ems-exam-center-for-tutor-lms'),
                'confirmBulk'                 => __('Apply action to {count} item(s)?', 'mc-ems-exam-center-for-tutor-lms'),
                'error'                       => __('An error occurred.', 'mc-ems-exam-center-for-tutor-lms'),
                'networkError'                => __('Network error. Please try again.', 'mc-ems-exam-center-for-tutor-lms'),
                'exporting'                   => __('Exporting…', 'mc-ems-exam-center-for-tutor-lms'),
                'exportCsv'                   => __('Export CSV', 'mc-ems-exam-center-for-tutor-lms'),
                'selectExamBeforeGenerating'  => __('Select a Tutor LMS exam before generating sessions.', 'mc-ems-exam-center-for-tutor-lms'),
                'selectAtLeastOneDate'        => __('Select at least one date from the calendar.', 'mc-ems-exam-center-for-tutor-lms'),
                'enterAtLeastOneTime'         => __('Enter a valid time (HH:MM).', 'mc-ems-exam-center-for-tutor-lms'),
                'selectSpecialDate'           => __('Select a date for the special session.', 'mc-ems-exam-center-for-tutor-lms'),
                'selectSpecialTime'           => __('Select a time for the special session.', 'mc-ems-exam-center-for-tutor-lms'),
                'selectSpecialCandidate'      => __('Select the candidate for the special session.', 'mc-ems-exam-center-for-tutor-lms'),
            ],
        ]);

        ob_start();
        ?>
        (function(){
            const cb = document.getElementById('mcemexce_generate_special');
            const std = document.getElementById('mcemexce_gen_standard');
            const sp  = document.getElementById('mcemexce_gen_special');

            const specialExam = document.getElementById('mcemexce_special_exam_id');
            const specialDate   = document.getElementById('mcemexce_special_date');
            const specialTime   = document.getElementById('mcemexce_special_time');
            const specialEmail  = document.getElementById('mcemexce_special_user_email');

            const standardExam = document.getElementById('mcemexce_exam_id');
            const standardStart  = document.getElementById('mcemexce_date_start');
            const standardEnd    = document.getElementById('mcemexce_date_end');
            const standardTime   = document.getElementById('mcemexce_time');
            const standardCap    = document.getElementById('mcemexce_capacity');

            if (!cb) return;

            function setDisabled(el, state) {
                if (el) {
                    el.disabled = state;
                }
            }

            function toggle() {
                if (cb.checked) {
                    if (std) std.style.display = 'none';
                    if (sp) sp.style.display = 'block';

                    setDisabled(specialExam, false);
                    setDisabled(specialDate, false);
                    setDisabled(specialTime, false);
                    setDisabled(specialEmail, false);

                    setDisabled(standardExam, true);
                    setDisabled(standardStart, true);
                    setDisabled(standardEnd, true);
                    setDisabled(standardTime, true);
                    setDisabled(standardCap, true);
                } else {
                    if (std) std.style.display = 'block';
                    if (sp) sp.style.display = 'none';

                    setDisabled(specialExam, true);
                    setDisabled(specialDate, true);
                    setDisabled(specialTime, true);
                    setDisabled(specialEmail, true);

                    if (specialTime) {
                        specialTime.value = '';
                        specialTime.removeAttribute('min');
                    }

                    setDisabled(standardExam, false);
                    setDisabled(standardStart, false);
                    setDisabled(standardEnd, false);
                    setDisabled(standardTime, false);
                    setDisabled(standardCap, false);
                }
            }

            cb.addEventListener('change', toggle);
            toggle();
        })();

        (function(){
            const genForm = document.getElementById('mcems-generate-form');
            if (!genForm) return;

            genForm.addEventListener('submit', function(e){
                const special = document.getElementById('mcemexce_generate_special');
                const isSpecial = special && special.checked;

                const sel = document.getElementById(isSpecial ? 'mcemexce_special_exam_id' : 'mcemexce_exam_id');
                if (sel && !sel.value) {
                    e.preventDefault();
                    alert(MCEMEXCE_ADMIN.i18n.selectExamBeforeGenerating);
                    sel.focus();
                    return;
                }

                if (!isSpecial) {
                    const calContainer = document.getElementById('mcems-selected-dates');
                    if (calContainer && calContainer.querySelectorAll('input[name="selected_dates[]"]').length === 0) {
                        e.preventDefault();
                        alert(MCEMEXCE_ADMIN.i18n.selectAtLeastOneDate);
                        return;
                    }

                    const timeInput = document.getElementById('mcemexce_time');
                    if (timeInput && !/^\d{2}:\d{2}$/.test((timeInput.value || '').trim())) {
                        e.preventDefault();
                        alert(MCEMEXCE_ADMIN.i18n.enterAtLeastOneTime);
                        timeInput.focus();
                        return;
                    }
                } else {
                    const sDate = document.getElementById('mcemexce_special_date');
                    const sTime = document.getElementById('mcemexce_special_time');
                    const sUser = document.getElementById('mcemexce_special_user_email');
                    const sUserId = document.getElementById('mcemexce_special_user_id');

                    if (sDate && !sDate.value) {
                        e.preventDefault();
                        alert(MCEMEXCE_ADMIN.i18n.selectSpecialDate);
                        sDate.focus();
                        return;
                    }

                    if (sTime && !sTime.value) {
                        e.preventDefault();
                        alert(MCEMEXCE_ADMIN.i18n.selectSpecialTime);
                        sTime.focus();
                        return;
                    }

                    if (!sUserId || !sUserId.value) {
                        e.preventDefault();
                        alert(MCEMEXCE_ADMIN.i18n.selectSpecialCandidate);
                        if (sUser) sUser.focus();
                        return;
                    }
                }
            });
        })();

        (function(){
            const input = document.getElementById('mcemexce_special_user_email');
            const hidden = document.getElementById('mcemexce_special_user_id');
            const box = document.getElementById('mcemexce_user_suggest');

            if (!input || !hidden || !box || typeof ajaxurl === 'undefined') return;

            const nonce = (typeof MCEMEXCE_ADMIN !== 'undefined' && MCEMEXCE_ADMIN.userSearchNonce) ? MCEMEXCE_ADMIN.userSearchNonce : '';
            let timer = null;
            let last = '';

            function clearSuggest(){
                box.innerHTML = '';
                box.style.display = 'none';
            }

            function escHtml(str){
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function render(items){
                if (!items || !items.length) {
                    clearSuggest();
                    return;
                }

                box.innerHTML = '';

                items.forEach(function(u){
                    const row = document.createElement('div');
                    row.style.padding = '8px 10px';
                    row.style.cursor = 'pointer';
                    row.style.borderTop = '1px solid #f0f0f1';
                    row.innerHTML = '<strong>' + escHtml(u.name || u.email || '') + '</strong>' + (u.email ? '<div style="font-size:12px; opacity:.85;">' + escHtml(u.email) + '</div>' : '');

                    row.addEventListener('mouseenter', function(){ row.style.background = '#f6f7f7'; });
                    row.addEventListener('mouseleave', function(){ row.style.background = '#fff'; });

                    row.addEventListener('click', function(){
                        input.value = (u.name || '') + (u.email ? ' (' + u.email + ')' : '');
                        hidden.value = u.id || '';
                        clearSuggest();
                    });

                    box.appendChild(row);
                });

                box.style.display = 'block';
            }

            async function doSearch(q){
                const fd = new FormData();
                fd.append('action', 'mcemexce_user_search');
                fd.append('nonce', nonce);
                fd.append('q', q);

                const res = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                });

                const json = await res.json().catch(() => null);
                if (json && json.success) {
                    render(json.data);
                } else {
                    clearSuggest();
                }
            }

            input.addEventListener('input', function(){
                const q = (input.value || '').trim();
                hidden.value = '';

                if (q.length < 3) {
                    clearSuggest();
                    return;
                }

                last = q;

                if (timer) {
                    clearTimeout(timer);
                }

                timer = setTimeout(function(){
                    if (last === q) {
                        doSearch(q);
                    }
                }, 250);
            });

            document.addEventListener('click', function(e){
                if (!box.contains(e.target) && e.target !== input) {
                    clearSuggest();
                }
            });
        })();

        (function(){
            function pad(n){ return (n < 10 ? '0' : '') + n; }

            function todayYMD(){
                var d = new Date();
                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            }

            function nowHM(){
                var d = new Date();
                return pad(d.getHours()) + ':' + pad(d.getMinutes());
            }

            function setMinDate(id){
                var el = document.getElementById(id);
                if (!el) return;
                el.setAttribute('min', todayYMD());
            }

            function bindDateTime(dateId, timeId){
                var dEl = document.getElementById(dateId);
                var tEl = document.getElementById(timeId);
                if (!dEl || !tEl) return;

                function update(){
                    var t = todayYMD();
                    if (dEl.disabled) {
                        tEl.removeAttribute('min');
                        return;
                    }

                    if (dEl.value === t) {
                        tEl.setAttribute('min', nowHM());

                        if (tEl.value && tEl.value < tEl.getAttribute('min')) {
                            tEl.value = '';
                        }
                    } else {
                        tEl.removeAttribute('min');
                    }
                }

                dEl.addEventListener('change', update);
                tEl.addEventListener('focus', update);
                update();
            }

            setMinDate('mcemexce_special_date');

            bindDateTime('mcemexce_special_date', 'mcemexce_special_time');
        })();

        (function(){
            var selected = {};
            var currentYear, currentMonth;

            function pad(n){ return (n < 10 ? '0' : '') + n; }

            function todayYMD(){
                var d = new Date();
                return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
            }

            var today = todayYMD();
            var td = new Date();
            currentYear  = td.getFullYear();
            currentMonth = td.getMonth(); // 0-indexed

            var monthNames = MCEMEXCE_ADMIN.monthNames;
            var dayNames = MCEMEXCE_ADMIN.dayNames;

            function updateHidden(){
                var container = document.getElementById('mcems-selected-dates');
                if (!container) return;
                container.innerHTML = '';
                var keys = Object.keys(selected).sort();
                keys.forEach(function(d){
                    var inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = 'selected_dates[]';
                    inp.value = d;
                    container.appendChild(inp);
                });
            }

            function render(){
                var cal = document.getElementById('mcems-calendar');
                if (!cal) return;

                var firstDay = new Date(currentYear, currentMonth, 1);
                var totalDays = new Date(currentYear, currentMonth + 1, 0).getDate();
                var startDow = (firstDay.getDay() + 6) % 7; // Monday=0

                var html = '<div class="mcems-cal-header">'
                    + '<button type="button" id="mcems-cal-prev">&#8249;</button>'
                    + '<span>' + monthNames[currentMonth] + ' ' + currentYear + '</span>'
                    + '<button type="button" id="mcems-cal-next">&#8250;</button>'
                    + '</div>'
                    + '<table class="mcems-cal-table"><thead><tr>';

                dayNames.forEach(function(d){ html += '<th>' + d + '</th>'; });
                html += '</tr></thead><tbody><tr>';

                var col = 0;
                for (var i = 0; i < startDow; i++){ html += '<td></td>'; col++; }

                for (var day = 1; day <= totalDays; day++){
                    if (col === 7){ html += '</tr><tr>'; col = 0; }
                    var dateStr = currentYear + '-' + pad(currentMonth+1) + '-' + pad(day);
                    var cls = 'mcems-cal-day';
                    var isPast = dateStr < today;
                    if (isPast)             cls += ' mcems-cal-past';
                    if (selected[dateStr])  cls += ' mcems-cal-selected';
                    if (dateStr === today)  cls += ' mcems-cal-today';
                    var attr = isPast ? ' aria-disabled="true"' : ' data-date="' + dateStr + '" role="button" tabindex="0"';
                    html += '<td><span class="' + cls + '"' + attr + '>' + day + '</span></td>';
                    col++;
                }
                while (col > 0 && col < 7){ html += '<td></td>'; col++; }
                html += '</tr></tbody></table>';

                cal.innerHTML = html;

                document.getElementById('mcems-cal-prev').addEventListener('click', function(){
                    currentMonth--;
                    if (currentMonth < 0){ currentMonth = 11; currentYear--; }
                    render();
                });
                document.getElementById('mcems-cal-next').addEventListener('click', function(){
                    currentMonth++;
                    if (currentMonth > 11){ currentMonth = 0; currentYear++; }
                    render();
                });

                cal.querySelectorAll('[data-date]').forEach(function(el){
                    el.addEventListener('click', function(){
                        var d = el.getAttribute('data-date');
                        if (selected[d]){
                            delete selected[d];
                            el.classList.remove('mcems-cal-selected');
                        } else {
                            selected[d] = true;
                            el.classList.add('mcems-cal-selected');
                        }
                        updateHidden();
                    });
                    el.addEventListener('keydown', function(e){
                        if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); el.click(); }
                    });
                });
            }

            render();
        })();
        <?php
        $admin_ui_js = ob_get_clean();
        wp_add_inline_script('mcems-admin', $admin_ui_js);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMEXCE_CPT_Sessioni_Esame::CPT,
            __('Create sessions', 'mc-ems-exam-center-for-tutor-lms'),
            __('Create sessions', 'mc-ems-exam-center-for-tutor-lms'),
            'manage_options',
            'mcemexce-manage-sessions',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions', 403);
        }

        $today = gmdate('Y-m-d');
        $week  = gmdate('Y-m-d', strtotime('+7 days'));

        $exams   = MCEMEXCE_Tutor::get_exams();
        $exam_pt = MCEMEXCE_Tutor::exam_post_type();

        $is_premium   = class_exists( 'MCEMEXCE_Limits' ) && MCEMEXCE_Limits::is_premium();
        $max_capacity = $is_premium ? 500 : ( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::get_max_seats() : 5 );

        $notice = '';
        $error  = '';

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $posted = ($request_method === 'POST');
        if ($posted && empty($_POST['mcemexce_action'])) {
            $error = 'Form submission detected but missing action (mcemexce_action). Check whether security/cache plugins are altering POST requests.';
        }

        if ($request_method === 'POST' && isset($_POST['mcemexce_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['mcemexce_action']));

            if ($action === 'generate' && check_admin_referer('mcemexce_generate', 'mcemexce_generate_nonce')) {
                $is_special = !empty($_POST['mcemexce_generate_special']);

                if ($is_special) {
                    $result = self::handle_generate_special();
                } else {
                    $result = self::handle_generate_standard();
                }
                $notice = $result[0] ?? '';
                $error  = $result[1] ?? '';
            }

        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Exam Sessions Management', 'mc-ems-exam-center-for-tutor-lms'); ?></h1>

            <?php if ( ! $is_premium && class_exists( 'MCEMEXCE_Limits' ) ): ?>
            <div class="notice notice-warning inline" style="margin:12px 0 16px;padding:10px 14px;">
                <p>
                    <strong>&#x1F512; <?php echo esc_html__( 'Free version limits (MC-EMS)', 'mc-ems-exam-center-for-tutor-lms' ); ?>:</strong>
                    <?php
                    printf(
                        /* translators: 1: max sessions per day per exam, 2: max seats per session, 3: max active sessions, 4: upgrade URL */
                        wp_kses(
                            __( 'Max %1$d session/day per exam &mdash; Max %2$d seats/session &mdash; Max %3$d active sessions. <a href="%4$s" target="_blank" rel="noopener noreferrer">Upgrade to MC-EMS Premium</a> to remove these limits.', 'mc-ems-exam-center-for-tutor-lms' ),
                            [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'strong' => [] ]
                        ),
                        (int) MCEMEXCE_Limits::FREE_MAX_SESSIONS_PER_DAY,
                        (int) MCEMEXCE_Limits::FREE_MAX_SEATS_PER_SESSION,
                        (int) MCEMEXCE_Limits::FREE_MAX_ACTIVE_SESSIONS,
                        esc_url( MCEMEXCE_Limits::upgrade_url() )
                    );
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo wp_kses_post($error); ?></p></div>
            <?php endif; ?>


            <div class="card" style="max-width: 1100px;">
                <h2><?php echo esc_html__('Generate new sessions', 'mc-ems-exam-center-for-tutor-lms'); ?></h2>

                <form method="post" id="mcems-generate-form">
                    <?php wp_nonce_field('mcemexce_generate', 'mcemexce_generate_nonce'); ?>
                    <input type="hidden" name="mcemexce_action" value="generate">

                    <table class="form-table">
                        <tr>
                            <th><?php echo esc_html__('Generation type', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;font-weight:700;">
                                    <input type="checkbox" name="mcemexce_generate_special" id="mcemexce_generate_special" value="1">
                                    ♿ <?php echo esc_html__('Create an exam session for a candidate with special requirements', 'mc-ems-exam-center-for-tutor-lms'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div id="mcemexce_gen_standard">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcemexce_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected (exam post type not found).', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php else: ?>
                                        <select id="mcemexce_exam_id" name="exam_id">
                                            <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-exam-center-for-tutor-lms'); ?></option>
                                            <?php foreach ($exams as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Select dates', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                <td>
                                    <div id="mcems-date-picker-wrap">
                                        <div id="mcems-calendar"></div>
                                        <div id="mcems-selected-dates"></div>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th>
                                    <label for="mcemexce_time"><?php echo esc_html__('Exam session time', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="time"
                                        id="mcemexce_time"
                                        name="time"
                                        value=""
                                    >
                                </td>
                            </tr>

                            <tr>
                                <th>
                                    <label for="mcemexce_capacity"><?php echo esc_html__('Seats per exam session', 'mc-ems-exam-center-for-tutor-lms'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="mcemexce_capacity" name="capacity" min="1" max="<?php echo (int) $max_capacity; ?>">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div id="mcemexce_gen_special" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcemexce_special_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php else: ?>
                                        <select id="mcemexce_special_exam_id" name="special_exam_id" disabled>
                                            <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-exam-center-for-tutor-lms'); ?></option>
                                            <?php foreach ($exams as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcemexce_special_date"><?php echo esc_html__('Date', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <input
                                        type="date"
                                        id="mcemexce_special_date"
                                        name="special_date"
                                        value="<?php echo esc_attr($today); ?>"
                                        min="<?php echo esc_attr($today); ?>"
                                        disabled
                                    >
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcemexce_special_time"><?php echo esc_html__('Time (single)', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <input
                                        type="time"
                                        id="mcemexce_special_time"
                                        name="special_time"
                                        value=""
                                        disabled
                                    >
                                </td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Seats', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                <td><input type="number" value="1" readonly></td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Candidate', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                <td>
                                    <div class="mcems-user-search-wrap">
                                        <input
                                            type="text"
                                            id="mcemexce_special_user_email"
                                            name="special_user_email"
                                            value=""
                                            placeholder="<?php echo esc_attr__('Search by name or email…', 'mc-ems-exam-center-for-tutor-lms'); ?>"
                                            autocomplete="off"
                                            disabled
                                        >
                                        <input type="hidden" id="mcemexce_special_user_id" name="special_user_id" value="">
                                        <div id="mcemexce_user_suggest" class="mcems-user-search-results"></div>
                                    </div>
                                    <p class="description" style="margin-top:8px;"><?php echo esc_html__('Start typing a name or email address, then click the user to select.', 'mc-ems-exam-center-for-tutor-lms'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary" name="mcemexce_submit_generate" value="1">
                            <?php echo esc_html__('Generate Sessions', 'mc-ems-exam-center-for-tutor-lms'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>

        <?php
    }

    private static function handle_generate_standard(): array {
        check_admin_referer('mcemexce_generate', 'mcemexce_generate_nonce');
        $selected_dates_raw = isset($_POST['selected_dates']) && is_array($_POST['selected_dates'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_dates']))
            : [];
        $capacity  = max(1, absint(wp_unslash($_POST['capacity'] ?? 1)));
        $exam_id = isset($_POST['exam_id']) ? absint(wp_unslash($_POST['exam_id'])) : 0;

        if ($exam_id <= 0) {
            return ['', __('Select a Tutor LMS exam.', 'mc-ems-exam-center-for-tutor-lms')];
        }


        // Validate and deduplicate selected dates.
        $selected_dates = [];
        foreach ($selected_dates_raw as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $selected_dates[] = $d;
            }
        }
        $selected_dates = array_values(array_unique($selected_dates));
        sort($selected_dates);

        if (!$selected_dates) {
            return ['', __('Select at least one date from the calendar.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        // Get the single session time from the time input.
        $time = sanitize_text_field(wp_unslash($_POST['time'] ?? ''));
        if (!$time || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['', __('Enter a valid time (HH:MM).', 'mc-ems-exam-center-for-tutor-lms')];
        }

        // -----------------------------------------------------------------
        // Free-plan limits (enforced server-side; not bypassable by UI).
        // -----------------------------------------------------------------
        $is_premium      = class_exists( 'MCEMEXCE_Limits' ) && MCEMEXCE_Limits::is_premium();
        $max_active      = $is_premium ? PHP_INT_MAX : ( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::get_max_active_sessions()   : 5 );
        $max_per_day     = $is_premium ? PHP_INT_MAX : ( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::get_max_sessions_per_day()  : 1 );
        $max_seats       = $is_premium ? 500         : ( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::get_max_seats()             : 5 );
        $active_count    = $is_premium ? 0           : ( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::count_active_sessions()     : 0 );

        // Cap capacity to the per-session seat limit.
        if ( $capacity > $max_seats ) {
            $capacity = $max_seats;
        }

        // If the site has already reached the active-session ceiling, bail early.
        if ( ! $is_premium && $active_count >= $max_active ) {
            return [ '', wp_kses_post( sprintf(
                /* translators: 1: max active sessions limit, 2: upgrade URL */
                __( 'Active sessions limit reached (%1$d) in the free version. <a href="%2$s" target="_blank" rel="noopener noreferrer">Upgrade to MC-EMS Premium</a> to remove this limit.', 'mc-ems-exam-center-for-tutor-lms' ),
                (int) $max_active,
                esc_url( class_exists( 'MCEMEXCE_Limits' ) ? MCEMEXCE_Limits::upgrade_url() : '#' )
            ) ) ];
        }

        $created = 0;
        $skipped = 0;
        $insert_errors = [];

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        foreach ($selected_dates as $date) {

            try {
                $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
                if ($session_dt < $now) {
                    $skipped++;
                    continue;
                }
            } catch (\Throwable $e) {
                $skipped++;
                continue;
            }

            // Free-plan: enforce 1 session per day per exam.
            if ( ! $is_premium && class_exists( 'MCEMEXCE_Limits' ) ) {
                $sessions_this_day = MCEMEXCE_Limits::count_sessions_for_exam_on_date( $exam_id, $date );
                if ( $sessions_this_day >= $max_per_day ) {
                    $skipped++;
                    continue;
                }
            }

            // Free-plan: enforce the overall active-sessions ceiling (accounting
            // for sessions already created in this batch).
            if ( ! $is_premium && ( $active_count + $created ) >= $max_active ) {
                $skipped++;
                continue;
            }

            if (self::session_exists($date, $time, $exam_id)) {
                $skipped++;
                continue;
            }

            $sid = self::create_session($date, $time, $capacity, 0, 0, $exam_id);

            if ($sid) {
                $created++;
            } else {
                $skipped++;
                $insert_errors[] = $date . ' ' . $time;
            }
        }

        if (!$created && $insert_errors) {
            return ['', sprintf(
                /* translators: %s: comma-separated list of date/time combinations that could not be created */
                __('Unable to create sessions for: %s', 'mc-ems-exam-center-for-tutor-lms'),
                implode(', ', array_slice($insert_errors, 0, 5))
            )];
        }

        $notice = $created ? sprintf(
            /* translators: 1: number of sessions successfully created, 2: number of sessions skipped */
            __('Creation completed: %1$d sessions created, %2$d skipped.', 'mc-ems-exam-center-for-tutor-lms'),
            $created,
            $skipped
        ) : '';

        return [$notice, ''];
    }

    private static function handle_generate_special(): array {
        check_admin_referer('mcemexce_generate', 'mcemexce_generate_nonce');
        $date      = sanitize_text_field(wp_unslash($_POST['special_date'] ?? ''));
        $time      = sanitize_text_field(wp_unslash($_POST['special_time'] ?? ''));
        $uid     = absint($_POST['special_user_id'] ?? 0);
        $email     = sanitize_email(wp_unslash($_POST['special_user_email'] ?? ''));
        $exam_id = absint($_POST['special_exam_id'] ?? 0);

        if ($exam_id <= 0) {
            return ['', __('Select a Tutor LMS exam.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['', __('Invalid date/time.', 'mc-ems-exam-center-for-tutor-lms')];
        }


        $tz = wp_timezone();

        try {
            $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
            $now = new \DateTimeImmutable('now', $tz);

            if ($session_dt < $now) {
                return ['', __('Past sessions cannot be created. Please choose a future date and time.', 'mc-ems-exam-center-for-tutor-lms')];
            }
        } catch (\Throwable $e) {
            return ['', __('Invalid date/time.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        // -----------------------------------------------------------------
        // Free-plan limits (enforced server-side for special sessions too).
        // -----------------------------------------------------------------
        if ( class_exists( 'MCEMEXCE_Limits' ) && ! MCEMEXCE_Limits::is_premium() ) {
            // Check total active-sessions ceiling.
            $active_count = MCEMEXCE_Limits::count_active_sessions();
            $max_active   = MCEMEXCE_Limits::get_max_active_sessions();
            if ( $active_count >= $max_active ) {
                return [ '', wp_kses_post( sprintf(
                    /* translators: 1: max active sessions limit, 2: upgrade URL */
                    __( 'Active sessions limit reached (%1$d) in the free version. <a href="%2$s" target="_blank" rel="noopener noreferrer">Upgrade to MC-EMS Premium</a> to remove this limit.', 'mc-ems-exam-center-for-tutor-lms' ),
                    (int) $max_active,
                    esc_url( MCEMEXCE_Limits::upgrade_url() )
                ) ) ];
            }

            // Check per-day-per-exam limit (counts all sessions, not only special).
            $sessions_this_day = MCEMEXCE_Limits::count_sessions_for_exam_on_date( $exam_id, $date );
            $max_per_day       = MCEMEXCE_Limits::get_max_sessions_per_day();
            if ( $sessions_this_day >= $max_per_day ) {
                return [ '', wp_kses_post( sprintf(
                    /* translators: 1: max sessions per day limit, 2: upgrade URL */
                    __( 'Session limit per day/exam reached (%1$d) in the free version. <a href="%2$s" target="_blank" rel="noopener noreferrer">Upgrade to MC-EMS Premium</a> to remove this limit.', 'mc-ems-exam-center-for-tutor-lms' ),
                    (int) $max_per_day,
                    esc_url( MCEMEXCE_Limits::upgrade_url() )
                ) ) ];
            }
        }

        if ($uid <= 0 && $email) {
            $u = get_user_by('email', $email);
            if ($u && !is_wp_error($u)) {
                $uid = (int) $u->ID;
            }
        }

        if ($uid <= 0 || !get_user_by('id', $uid)) {
            return ['', __('Invalid candidate selection.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        if (self::session_exists($date, $time, $exam_id, true)) {
            return ['', __('A special session already exists with this date/time for this exam.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        $sid = self::create_session($date, $time, 1, 1, $uid, $exam_id);
        if (!$sid) {
            return ['', __('Unable to create exam session.', 'mc-ems-exam-center-for-tutor-lms')];
        }

        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_OCCUPATI, [$uid]);

        update_user_meta($uid, MCEMEXCE_Booking::UM_ACTIVE_BOOKING, [
            'slot_id'    => $sid,
            'data'       => $date,
            'orario'     => $time,
            'created_at' => current_time('mysql'),
        ]);

        $storico = get_user_meta($uid, MCEMEXCE_Booking::UM_HISTORY, true);
        if (!is_array($storico)) {
            $storico = [];
        }

        $storico[] = [
            'slot_id'   => $sid,
            'data'      => $date,
            'orario'    => $time,
            'azione'    => 'prenotata',
            'timestamp' => (int) current_time('timestamp'),
        ];

        update_user_meta($uid, MCEMEXCE_Booking::UM_HISTORY, $storico);

        return [sprintf(
            /* translators: %d: ID of the newly created special exam session */
            __('Special exam session created and exam booked for candidate (session ID: #%d).', 'mc-ems-exam-center-for-tutor-lms'),
            (int) $sid
        ), ''];
    }

    /**
     * Return a flat array of all published session dates (Y-m-d strings) that fall
     * within the given inclusive date range. Used to pre-fetch existing dates before
     * iterating over a range so we avoid one query per day inside the loop.
     */
    private static function get_session_dates_in_range(string $start, string $end): array {
        $ids = get_posts([
            'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            // TODO: Plugin Check slow-query warning – meta_query on postmeta is necessary here;
            // consider a custom table for large-scale deployments.
            'meta_query'     => [
                [
                    'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ]);

        $dates = [];
        foreach ($ids as $sid) {
            $d = (string) get_post_meta((int) $sid, MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, true);
            if ($d) {
                $dates[] = $d;
            }
        }
        return array_values(array_unique($dates));
    }

    private static function session_exists(string $date, string $time, int $exam_id, bool $special_only = false): bool {
        $meta = [
            ['key' => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, 'value' => $date],
            ['key' => MCEMEXCE_CPT_Sessioni_Esame::MK_TIME, 'value' => $time],
            ['key' => MCEMEXCE_CPT_Sessioni_Esame::MK_EXAM_ID, 'value' => $exam_id],
        ];

        if ($special_only) {
            $meta[] = ['key' => MCEMEXCE_CPT_Sessioni_Esame::MK_IS_SPECIAL, 'value' => 1];
        }

        $q = new WP_Query([
            'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            // TODO: Plugin Check slow-query warning – meta_query on postmeta is necessary here;
            // consider a custom table for large-scale deployments.
            'meta_query'     => $meta,
        ]);

        return $q->have_posts();
    }

    private static function create_session(string $date, string $time, int $capacity, int $is_special, int $special_user_id, int $exam_id): int {
        $sid = wp_insert_post([
            'post_type'   => MCEMEXCE_CPT_Sessioni_Esame::CPT,
            'post_status' => 'publish',
            'post_title'  => "Session {$date} {$time}",
        ], true);

        if (is_wp_error($sid) || !$sid) {
            return 0;
        }

        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, $date);
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_TIME, $time);
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_EXAM_ID, $exam_id > 0 ? (int) $exam_id : 0);
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_CAPACITY, max(1, $capacity));
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_OCCUPATI, []);
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_IS_SPECIAL, $is_special ? 1 : 0);
        update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $special_user_id > 0 ? (int) $special_user_id : 0);

        return (int) $sid;
    }
}