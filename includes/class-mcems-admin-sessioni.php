<?php
if (!defined('ABSPATH')) exit;

class MCEMS_Admin_Sessioni {

    const BASE_MAX_ACTIVE_SESSIONS = 5;
    const BASE_MAX_CAPACITY = 5;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_mcems_user_search', [__CLASS__, 'ajax_user_search']);
    }

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
        if (strpos($hook, 'mcems') === false && strpos($hook, MCEMS_CPT_Sessioni_Esame::CPT) === false) return;

        $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
        $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';
        wp_register_style('mcems-admin-style', $url . 'assets/css/admin.css', [], $ver);
        wp_enqueue_style('mcems-admin-style');

        wp_register_script('mcems-admin', $url . 'assets/js/admin.js', [], $ver, true);
        wp_enqueue_script('mcems-admin');

        wp_localize_script('mcems-admin', 'MCEMS_ADMIN', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('mcems_admin'),
            'exportNonce' => wp_create_nonce('mcems_export_csv'),
            'i18n'        => [
                'selectAction' => __('Please select an action.', 'mc-ems-base'),
                'selectItems'  => __('Please select at least one item.', 'mc-ems-base'),
                'confirmBulk'  => __('Apply action to {count} item(s)?', 'mc-ems-base'),
                'error'        => __('An error occurred.', 'mc-ems-base'),
                'networkError' => __('Network error. Please try again.', 'mc-ems-base'),
                'exporting'    => __('Exporting…', 'mc-ems-base'),
                'exportCsv'    => __('Export CSV', 'mc-ems-base'),
            ],
        ]);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT,
            __('Create sessions', 'mc-ems-base'),
            __('Create sessions', 'mc-ems-base'),
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
        $is_premium = mcems_is_license_valid();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Exam Sessions Management', 'mc-ems-base'); ?></h1>
            <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

            <div class="card" style="max-width: 1100px;">
            <h2><?php echo esc_html__('Generate new sessions', 'mc-ems-base'); ?></h2>
            <?php if (!$is_premium) :
                $future_count = self::count_future_sessions();
                $remaining = max(0, self::BASE_MAX_ACTIVE_SESSIONS - $future_count); ?>
                <div style="margin-bottom:16px;padding:12px 16px;border-radius:10px;border:1px solid #fed7aa;background:#fff7ed;">
                    <strong>📋 <?php echo esc_html__('Base license – session limits', 'mc-ems-base'); ?></strong><br>
                    <?php echo esc_html(sprintf(
                        __('Active future sessions: %1$d / %2$d — you can still create %3$d more session(s).', 'mc-ems-base'),
                        (int) $future_count,
                        (int) self::BASE_MAX_ACTIVE_SESSIONS,
                        (int) $remaining
                    )); ?>
                    <br><small style="color:#92400e;"><?php echo esc_html(sprintf(
                        __('Base license: max 1 session per day, max %1$d active sessions, and max %2$d seats per session. Upgrade to Premium to remove these limits.', 'mc-ems-base'),
                        (int) self::BASE_MAX_ACTIVE_SESSIONS,
                        (int) self::BASE_MAX_CAPACITY
                    )); ?></small>
                </div>
            <?php endif; ?>
                        <form method="post" id="mcems-generate-form">
                    <?php wp_nonce_field('mcems_generate', 'mcems_generate_nonce'); ?>
                    <input type="hidden" name="mcems_action" value="generate">

                    <table class="form-table">
                        <tr>
                            <th><?php echo esc_html__('Generation type', 'mc-ems-base'); ?></th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;font-weight:700;">
                                    <input type="checkbox" name="mcems_generate_special" id="mcems_generate_special" value="1">
                                    ♿ <?php echo esc_html__('Create an exam session for a candidate with special requirements', 'mc-ems-base'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div id="mcems_gen_standard">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcems_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-base'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected (exam post type not found).', 'mc-ems-base'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-base'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_exam_id" name="exam_id">
                                            <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-base'); ?></option>
                                            <?php foreach ($exams as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Select dates', 'mc-ems-base'); ?></th>
                                <td>
                                    <div id="mcems-date-picker-wrap">
                                        <!-- (calendar styles e container) -->
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
                                <?php if (!$is_premium): ?>
                                    <th><label for="mcems_times"><?php echo esc_html__('Exam session time', 'mc-ems-base'); ?></label></th>
                                    <td>
                                        <input
                                            type="time"
                                            id="mcems_times"
                                            name="times"
                                            required
                                        >
                                        <p class="description"><?php echo esc_html__('Base license: only one time per day allowed.', 'mc-ems-base'); ?></p>
                                    </td>
                                <?php else: ?>
                                    <th><label for="mcems_times"><?php echo esc_html__('Exam session times (one per line)', 'mc-ems-base'); ?></label></th>
                                    <td>
                                        <textarea
                                            id="mcems_times"
                                            name="times"
                                            rows="5"
                                            cols="40"
                                            placeholder="08:30&#10;10:30&#10;12:30"
                                        ></textarea>
                                    </td>
                                <?php endif; ?>
                            </tr>

                            <tr>
                                <th><label for="mcems_capacity"><?php echo esc_html__('Seats per exam session', 'mc-ems-base'); ?></label></th>
                                <td>
                                    <?php if (!$is_premium): ?>
                                        <input type="number" id="mcems_capacity" name="capacity" min="1" max="<?php echo (int) self::BASE_MAX_CAPACITY; ?>">
                                        <p class="description"><?php echo esc_html(sprintf(
                            /* translators: %d: maximum number of seats per session */
                            __('Base license: max %d seats per session.', 'mc-ems-base'),
                            self::BASE_MAX_CAPACITY
                        )); ?></p>
                                    <?php else: ?>
                                        <input type="number" id="mcems_capacity" name="capacity" min="1" max="500">
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div id="mcems_gen_special" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcems_special_exam_id"><?php echo esc_html__('Tutor LMS exam', 'mc-ems-base'); ?></label></th>
                                <td>
                                    <?php if (!$exam_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected.', 'mc-ems-base'); ?></em>
                                    <?php elseif (!$exams): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-base'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_special_exam_id" name="special_exam_id" disabled>
                                            <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-base'); ?></option>
                                            <?php foreach ($exams as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_special_date"><?php echo esc_html__('Date', 'mc-ems-base'); ?></label></th>
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
                                <th><label for="mcems_special_time"><?php echo esc_html__('Time (single)', 'mc-ems-base'); ?></label></th>
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
                                <th><?php echo esc_html__('Seats', 'mc-ems-base'); ?></th>
                                <td><input type="number" value="1" readonly></td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Candidate', 'mc-ems-base'); ?></th>
                                <td>
                                    <div class="mcems-user-search-wrap">
                                        <input
                                            type="text"
                                            id="mcems_special_user_email"
                                            name="special_user_email"
                                            value=""
                                            placeholder="<?php echo esc_attr__('Search by name or email…', 'mc-ems-base'); ?>"
                                            autocomplete="off"
                                            disabled
                                        >
                                        <input type="hidden" id="mcems_special_user_id" name="special_user_id" value="">
                                        <div id="mcems_user_suggest" class="mcems-user-search-results"></div>
                                    </div>
                                    <p class="description" style="margin-top:8px;"><?php echo esc_html__('Start typing a name or email address, then click the user to select.', 'mc-ems-base'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary" name="mcems_submit_generate" value="1">
                            <?php echo esc_html__('Generate Sessions', 'mc-ems-base'); ?>
                        </button>
                    </p>
                </form>
            </div>
            <hr>
            <div class="card" style="max-width: 1100px;"></div>
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
            alert('<?php echo esc_js(__('Select a Tutor LMS exam before generating sessions.', 'mc-ems-base')); ?>');
            sel.focus();
            return;
        }

        if (!isSpecial) {
            const calContainer = document.getElementById('mcems-selected-dates');
            if (calContainer && calContainer.querySelectorAll('input[name="selected_dates[]"]').length === 0) {
                e.preventDefault();
                alert('<?php echo esc_js(__('Select at least one date from the calendar.', 'mc-ems-base')); ?>');
                return;
            }

            const ta = document.getElementById('mcems_times');
            if (ta) {
                // Support both <input type="time"> (base) and <textarea> (premium)
                const isTimeInput = (ta.tagName === 'INPUT');
                if (isTimeInput) {
                    if (!ta.value || !/^\d{2}:\d{2}$/.test(ta.value.trim())) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Enter a valid time (HH:MM).', 'mc-ems-base')); ?>');
                        ta.focus();
                        return;
                    }
                } else {
                    const hasTime = (ta.value || '').split(/\r\n|\r|\n/).some(function(l){
                        return /^\s*\d{2}:\d{2}\s*$/.test(l);
                    });

                    if (!hasTime) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Enter at least one valid time (HH:MM), one per line.', 'mc-ems-base')); ?>');
                        ta.focus();
                        return;
                    }
                }
            }

            const capInput = document.getElementById('mcems_capacity');
            const maxCap = <?php echo $is_premium ? 'null' : (int) self::BASE_MAX_CAPACITY; ?>;
            if (capInput && maxCap !== null && parseInt(capInput.value, 10) > maxCap) {
                e.preventDefault();
                alert('<?php echo esc_js(sprintf(
                    __('Base license: max %d seats per session.', 'mc-ems-base'),
                    self::BASE_MAX_CAPACITY
                )); ?>');
                capInput.focus();
                return;
            }
        } else {
            const sDate = document.getElementById('mcems_special_date');
            const sTime = document.getElementById('mcems_special_time');
            const sUser = document.getElementById('mcems_special_user_email');
            const sUserId = document.getElementById('mcems_special_user_id');

            if (sDate && !sDate.value) {
                e.preventDefault();
                alert('<?php echo esc_js(__('Select a date for the special session.', 'mc-ems-base')); ?>');
                sDate.focus();
                return;
            }

            if (sTime && !sTime.value) {
                e.preventDefault();
                alert('<?php echo esc_js(__('Select a time for the special session.', 'mc-ems-base')); ?>');
                sTime.focus();
                return;
            }

            if (!sUserId || !sUserId.value) {
                e.preventDefault();
                alert('<?php echo esc_js(__('Select the candidate for the special session.', 'mc-ems-base')); ?>');
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

    const nonce = <?php echo wp_json_encode(wp_create_nonce('mcems_user_search')); ?>;
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
        __('January','mc-ems-base'),__('February','mc-ems-base'),__('March','mc-ems-base'),
        __('April','mc-ems-base'),__('May','mc-ems-base'),__('June','mc-ems-base'),
        __('July','mc-ems-base'),__('August','mc-ems-base'),__('September','mc-ems-base'),
        __('October','mc-ems-base'),__('November','mc-ems-base'),__('December','mc-ems-base'),
    ]); ?>;
    var dayNames = <?php echo wp_json_encode([
        __('Mo','mc-ems-base'),__('Tu','mc-ems-base'),__('We','mc-ems-base'),
        __('Th','mc-ems-base'),__('Fr','mc-ems-base'),__('Sa','mc-ems-base'),__('Su','mc-ems-base'),
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
// chiusura render()
    }
?>
        
