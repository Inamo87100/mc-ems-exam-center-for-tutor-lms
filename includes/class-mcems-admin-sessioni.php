<?php
if (!defined('ABSPATH')) exit;

class MCEMS_Admin_Sessioni {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_mcems_user_search', [__CLASS__, 'ajax_user_search']);
    }

    /**
     * AJAX handler: search users by display name or email.
     *
     * Expects POST params: nonce, q (search query, min 2 chars).
     * Returns JSON: [{id, name, email}, ...] (max 20 results, deduplicated).
     */
    public static function ajax_user_search(): void {
        check_ajax_referer('mcems_user_search', 'nonce');

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
        if (strpos($hook, 'mcems') === false && strpos($hook, MCEMS_CPT_Sessioni_Esame::CPT) === false) {
            return;
        }

        $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
        $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';

        wp_register_style('mcems-admin-style', $url . 'assets/css/admin.css', [], $ver);
        wp_enqueue_style('mcems-admin-style');

        wp_register_script('mcems-admin', $url . 'assets/js/admin.js', [], $ver, true);
        wp_enqueue_script('mcems-admin');

        wp_localize_script('mcems-admin', 'MCEMS_ADMIN', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('mcems_admin'),
            'exportNonce'      => wp_create_nonce('mcems_export_csv'),
            'userSearchNonce'  => wp_create_nonce('mcems_user_search'),
            'i18n'             => [
                'selectAction' => __('Please select an action.', 'mc-ems-exam-center-for-tutor-lms'),
                'selectItems'  => __('Please select at least one item.', 'mc-ems-exam-center-for-tutor-lms'),
                'confirmBulk'  => __('Apply action to {count} item(s)?', 'mc-ems-exam-center-for-tutor-lms'),
                'error'        => __('An error occurred.', 'mc-ems-exam-center-for-tutor-lms'),
                'networkError' => __('Network error. Please try again.', 'mc-ems-exam-center-for-tutor-lms'),
                'exporting'    => __('Exporting…', 'mc-ems-exam-center-for-tutor-lms'),
                'exportCsv'    => __('Export CSV', 'mc-ems-exam-center-for-tutor-lms'),
            ],
        ]);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT,
            __('Create sessions', 'mc-ems-exam-center-for-tutor-lms'),
            __('Create sessions', 'mc-ems-exam-center-for-tutor-lms'),
            'manage_options',
            'mcems-manage-sessions',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions', 403);
        }

        $today = gmdate('Y-m-d');
        $week  = gmdate('Y-m-d', strtotime('+7 days'));

        $exams   = MCEMS_Tutor::get_exams();
        $exam_pt = MCEMS_Tutor::exam_post_type();

        $notice = '';
        $error  = '';

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $posted = ($request_method === 'POST');
        if ($posted && empty($_POST['mcems_action'])) {
            $error = 'Form submission detected but missing action (mcems_action). Check whether security/cache plugins are altering POST requests.';
        }

        if ($request_method === 'POST' && isset($_POST['mcems_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['mcems_action']));

            if ($action === 'generate' && check_admin_referer('mcems_generate', 'mcems_generate_nonce')) {
                $is_special = !empty($_POST['mcems_generate_special']);

                if ($is_special) {
                    [$notice, $error] = self::handle_generate_special();
                } else {
                    [$notice, $error] = self::handle_generate_standard();
                }
            }

            if ($action === 'update_capacity' && check_admin_referer('mcems_update_capacity', 'mcems_update_capacity_nonce')) {
                [$notice, $error] = self::handle_update_capacity();
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Exam Sessions Management', 'mc-ems-exam-center-for-tutor-lms'); ?></h1>

            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width: 1100px;">
                <h2><?php echo esc_html__('Generate new sessions', 'mc-ems-exam-center-for-tutor-lms'); ?></h2>



                <form method="post" id="mcems-generate-form">
                    <?php wp_nonce_field('mcems_generate', 'mcems_generate_nonce'); ?>
                    <input type="hidden" name="mcems_action" value="generate">

                    <table class="form-table">
                        <tr>
                            <th><?php echo esc_html__('Generation type', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;font-weight:700;">
                                    <input type="checkbox" name="mcems_generate_special" id="mcems_generate_special" value="1">
                                    ♿ <?php echo esc_html__('Create an exam session for a candidate with special requirements', 'mc-ems-exam-center-for-tutor-lms'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div id="mcems_gen_standard">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcems_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected (exam post type not found).', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_exam_id" name="exam_id">
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
                                        <style>
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
                                        </style>
                                        <div id="mcems-calendar"></div>
                                        <div id="mcems-selected-dates"></div>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_times"><?php echo esc_html__('Exam session times (one per line)', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <textarea
                                        id="mcems_times"
                                        name="times"
                                        rows="5"
                                        cols="40"
                                        placeholder="08:30&#10;10:30&#10;12:30"
                                    ></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_capacity"><?php echo esc_html__('Seats per exam session', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <input type="number" id="mcems_capacity" name="capacity" min="1" max="500">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div id="mcems_gen_special" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcems_special_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-exam-center-for-tutor-lms'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_special_exam_id" name="special_exam_id" disabled>
                                            <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-exam-center-for-tutor-lms'); ?></option>
                                            <?php foreach ($exams as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_special_date"><?php echo esc_html__('Date', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <input
                                        type="date"
                                        id="mcems_special_date"
                                        name="special_date"
                                        value="<?php echo esc_attr($today); ?>"
                                        min="<?php echo esc_attr($today); ?>"
                                        disabled
                                    >
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_special_time"><?php echo esc_html__('Time (single)', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                                <td>
                                    <input
                                        type="time"
                                        id="mcems_special_time"
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
                                            id="mcems_special_user_email"
                                            name="special_user_email"
                                            value=""
                                            placeholder="<?php echo esc_attr__('Search by name or email…', 'mc-ems-exam-center-for-tutor-lms'); ?>"
                                            autocomplete="off"
                                            disabled
                                        >
                                        <input type="hidden" id="mcems_special_user_id" name="special_user_id" value="">
                                        <div id="mcems_user_suggest" class="mcems-user-search-results"></div>
                                    </div>
                                    <p class="description" style="margin-top:8px;"><?php echo esc_html__('Start typing a name or email address, then click the user to select.', 'mc-ems-exam-center-for-tutor-lms'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary" name="mcems_submit_generate" value="1">
                            <?php echo esc_html__('Generate Sessions', 'mc-ems-exam-center-for-tutor-lms'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <hr>

            <div class="card" style="max-width: 1100px;">
                <h2><?php echo esc_html__('Bulk update session capacity', 'mc-ems-exam-center-for-tutor-lms'); ?></h2>
                <p class="description"><?php echo esc_html__('Override the capacity of all standard (non-special) sessions in bulk.', 'mc-ems-exam-center-for-tutor-lms'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('mcems_update_capacity', 'mcems_update_capacity_nonce'); ?>
                    <input type="hidden" name="mcems_action" value="update_capacity">
                    <table class="form-table">
                        <tr>
                            <th><label for="mcems_new_capacity"><?php echo esc_html__('New capacity', 'mc-ems-exam-center-for-tutor-lms'); ?></label></th>
                            <td>
                                <input type="number" id="mcems_new_capacity" name="new_capacity" min="1" max="500" value="1">
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Scope', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="only_future" value="1">
                                    <?php echo esc_html__('Apply only to future sessions (today and later)', 'mc-ems-exam-center-for-tutor-lms'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-secondary">
                            <?php echo esc_html__('Update capacity', 'mc-ems-exam-center-for-tutor-lms'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        (function(){
            const cb = document.getElementById('mcems_generate_special');
            const std = document.getElementById('mcems_gen_standard');
            const sp  = document.getElementById('mcems_gen_special');

            const specialExam = document.getElementById('mcems_special_exam_id');
            const specialDate   = document.getElementById('mcems_special_date');
            const specialTime   = document.getElementById('mcems_special_time');
            const specialEmail  = document.getElementById('mcems_special_user_email');

            const standardExam = document.getElementById('mcems_exam_id');
            const standardStart  = document.getElementById('mcems_date_start');
            const standardEnd    = document.getElementById('mcems_date_end');
            const standardTimes  = document.getElementById('mcems_times');
            const standardCap    = document.getElementById('mcems_capacity');

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
                    setDisabled(standardTimes, true);
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
                    setDisabled(standardTimes, false);
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
                const special = document.getElementById('mcems_generate_special');
                const isSpecial = special && special.checked;

                const sel = document.getElementById(isSpecial ? 'mcems_special_exam_id' : 'mcems_exam_id');
                if (sel && !sel.value) {
                    e.preventDefault();
                    alert('<?php echo esc_js(__('Select a Tutor LMS exam before generating sessions.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                    sel.focus();
                    return;
                }

                if (!isSpecial) {
                    const calContainer = document.getElementById('mcems-selected-dates');
                    if (calContainer && calContainer.querySelectorAll('input[name="selected_dates[]"]').length === 0) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Select at least one date from the calendar.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                        return;
                    }

                    const ta = document.getElementById('mcems_times');
                    if (ta) {
                        const hasTime = (ta.value || '').split(/\r\n|\r|\n/).some(function(l){
                            return /^\s*\d{2}:\d{2}\s*$/.test(l);
                        });

                        if (!hasTime) {
                            e.preventDefault();
                            alert('<?php echo esc_js(__('Enter at least one valid time (HH:MM), one per line.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                            ta.focus();
                            return;
                        }
                    }
                } else {
                    const sDate = document.getElementById('mcems_special_date');
                    const sTime = document.getElementById('mcems_special_time');
                    const sUser = document.getElementById('mcems_special_user_email');
                    const sUserId = document.getElementById('mcems_special_user_id');

                    if (sDate && !sDate.value) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Select a date for the special session.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                        sDate.focus();
                        return;
                    }

                    if (sTime && !sTime.value) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Select a time for the special session.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                        sTime.focus();
                        return;
                    }

                    if (!sUserId || !sUserId.value) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Select the candidate for the special session.', 'mc-ems-exam-center-for-tutor-lms')); ?>');
                        if (sUser) sUser.focus();
                        return;
                    }
                }
            });
        })();

        (function(){
            const input = document.getElementById('mcems_special_user_email');
            const hidden = document.getElementById('mcems_special_user_id');
            const box = document.getElementById('mcems_user_suggest');

            if (!input || !hidden || !box || typeof ajaxurl === 'undefined') return;

            const nonce = (typeof MCEMS_ADMIN !== 'undefined' && MCEMS_ADMIN.userSearchNonce) ? MCEMS_ADMIN.userSearchNonce : '';
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
                fd.append('action', 'mcems_user_search');
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
        </script>

        <script>
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

            setMinDate('mcems_special_date');

            bindDateTime('mcems_special_date', 'mcems_special_time');
        })();
        </script>

        <script>
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

            var monthNames = <?php echo wp_json_encode([
                __('January','mc-ems-exam-center-for-tutor-lms'),__('February','mc-ems-exam-center-for-tutor-lms'),__('March','mc-ems-exam-center-for-tutor-lms'),
                __('April','mc-ems-exam-center-for-tutor-lms'),__('May','mc-ems-exam-center-for-tutor-lms'),__('June','mc-ems-exam-center-for-tutor-lms'),
                __('July','mc-ems-exam-center-for-tutor-lms'),__('August','mc-ems-exam-center-for-tutor-lms'),__('September','mc-ems-exam-center-for-tutor-lms'),
                __('October','mc-ems-exam-center-for-tutor-lms'),__('November','mc-ems-exam-center-for-tutor-lms'),__('December','mc-ems-exam-center-for-tutor-lms'),
            ]); ?>;
            var dayNames = <?php echo wp_json_encode([
                __('Mo','mc-ems-exam-center-for-tutor-lms'),__('Tu','mc-ems-exam-center-for-tutor-lms'),__('We','mc-ems-exam-center-for-tutor-lms'),
                __('Th','mc-ems-exam-center-for-tutor-lms'),__('Fr','mc-ems-exam-center-for-tutor-lms'),__('Sa','mc-ems-exam-center-for-tutor-lms'),__('Su','mc-ems-exam-center-for-tutor-lms'),
            ]); ?>;

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
        </script>
        <?php
    }

    private static function handle_generate_standard(): array {
        check_admin_referer('mcems_generate', 'mcems_generate_nonce');
        $selected_dates_raw = isset($_POST['selected_dates']) && is_array($_POST['selected_dates'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_dates']))
            : [];
        $times_raw = sanitize_textarea_field(wp_unslash($_POST['times'] ?? ''));
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

        $times = [];
        foreach (preg_split("/\r\n|\r|\n/", $times_raw) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $t)) continue;
            $times[] = $t;
        }

        $times = array_values(array_unique($times));
        sort($times);

        if (!$times) {
            return ['', __('Enter at least one valid time (HH:MM), one per line.', 'mc-ems-exam-center-for-tutor-lms')];
        }


        $created = 0;
        $skipped = 0;
        $insert_errors = [];

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        foreach ($selected_dates as $date) {
            foreach ($times as $time) {
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
        }

        if (!$created && $insert_errors) {
            return ['', sprintf(
                /* translators: %s: comma-separated list of date/time combinations that could not be created */
                __('Unable to create sessions for: %s', 'mc-ems-exam-center-for-tutor-lms'),
                implode(', ', array_slice($insert_errors, 0, 5))
            )];
        }

        return [sprintf(
            /* translators: 1: number of sessions successfully created, 2: number of sessions skipped */
            __('Creation completed: %1$d sessions created, %2$d skipped.', 'mc-ems-exam-center-for-tutor-lms'),
            $created,
            $skipped
        ), ''];
    }

    private static function handle_generate_special(): array {
        check_admin_referer('mcems_generate', 'mcems_generate_nonce');
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

        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, [$uid]);

        update_user_meta($uid, MCEMS_Booking::UM_ACTIVE_BOOKING, [
            'slot_id'    => $sid,
            'data'       => $date,
            'orario'     => $time,
            'created_at' => current_time('mysql'),
        ]);

        $storico = get_user_meta($uid, MCEMS_Booking::UM_HISTORY, true);
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

        update_user_meta($uid, MCEMS_Booking::UM_HISTORY, $storico);

        return [sprintf(
            /* translators: %d: ID of the newly created special exam session */
            __('Special exam session created and exam booked for candidate (session ID: #%d).', 'mc-ems-exam-center-for-tutor-lms'),
            (int) $sid
        ), ''];
    }

    private static function handle_update_capacity(): array {
        check_admin_referer('mcems_update_capacity', 'mcems_update_capacity_nonce');
        $new_cap     = max(1, absint($_POST['new_capacity'] ?? 0));
        $only_future = !empty($_POST['only_future']);

        $ids = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $updated = 0;
        $today = gmdate('Y-m-d');

        foreach ($ids as $sid) {
            $sid = (int) $sid;
            $is_special = (int) get_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true);
            $date = (string) get_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);

            if ($only_future && $date && $date < $today) {
                continue;
            }

            if ($is_special === 1) {
                update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, 1);
                continue;
            }

            update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, $new_cap);
            $updated++;
        }

        return [sprintf(
            /* translators: %d: number of sessions successfully updated */
            __('Update completed: %d sessions updated.', 'mc-ems-exam-center-for-tutor-lms'),
            $updated
        ), ''];
    }

    /**
     * Count published sessions whose date is today or in the future.
     */
    private static function count_future_sessions(): int {
        $today = current_time('Y-m-d');
        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => $today,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ]);
        return (int) $q->found_posts;
    }

    /**
     * Check whether any published session (of any time) exists for the given date.
     */
    private static function has_session_on_date(string $date): bool {
        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value' => $date,
                ],
            ],
        ]);
        return $q->have_posts();
    }

    /**
     * Return a flat array of all published session dates (Y-m-d strings) that fall
     * within the given inclusive date range. Used to pre-fetch existing dates before
     * iterating over a range so we avoid one query per day inside the loop.
     */
    private static function get_session_dates_in_range(string $start, string $end): array {
        $ids = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ]);

        $dates = [];
        foreach ($ids as $sid) {
            $d = (string) get_post_meta((int) $sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
            if ($d) {
                $dates[] = $d;
            }
        }
        return array_values(array_unique($dates));
    }

    private static function session_exists(string $date, string $time, int $exam_id, bool $special_only = false): bool {
        $meta = [
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_DATE, 'value' => $date],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_TIME, 'value' => $time],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, 'value' => $exam_id],
        ];

        if ($special_only) {
            $meta[] = ['key' => MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, 'value' => 1];
        }

        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta,
        ]);

        return $q->have_posts();
    }

    private static function create_session(string $date, string $time, int $capacity, int $is_special, int $special_user_id, int $exam_id): int {
        $sid = wp_insert_post([
            'post_type'   => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status' => 'publish',
            'post_title'  => "Session {$date} {$time}",
        ], true);

        if (is_wp_error($sid) || !$sid) {
            return 0;
        }

        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, $date);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_TIME, $time);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, $exam_id > 0 ? (int) $exam_id : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, max(1, $capacity));
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, []);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, $is_special ? 1 : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $special_user_id > 0 ? (int) $special_user_id : 0);

        return (int) $sid;
    }
}