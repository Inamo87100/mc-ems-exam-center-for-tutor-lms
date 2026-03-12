<?php
if (!defined('ABSPATH')) exit;

class MCEMS_Admin_Sessioni {

    /** Maximum number of future (active) sessions allowed on the Base license. */
    const BASE_MAX_ACTIVE_SESSIONS = 5;

    /** Maximum seats per session allowed on the Base license. */
    const BASE_MAX_CAPACITY = 5;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT,
            __('Create sessions', 'mc-ems'),
            __('Create sessions', 'mc-ems'),
            'manage_options',
            'mcems-manage-sessions',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions', 403);
        }

        $today = date('Y-m-d');
        $week  = date('Y-m-d', strtotime('+7 days'));

        $courses   = MCEMS_Tutor::get_courses();
        $course_pt = MCEMS_Tutor::course_post_type();

        $notice = '';
        $error  = '';

        $posted = ($_SERVER['REQUEST_METHOD'] === 'POST');
        if ($posted && empty($_POST['mcems_action'])) {
            $error = 'Form submission detected but missing action (mcems_action). Check whether security/cache plugins are altering POST requests.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mcems_action'])) {
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
            <h1><?php echo esc_html__('Exam Sessions Management', 'mc-ems'); ?></h1>

            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width: 1100px;">
                <h2><?php echo esc_html__('Generate new sessions', 'mc-ems'); ?></h2>

                <?php
                $is_premium = defined('EMS_PREMIUM_VERSION');
                if (!$is_premium) :
                    $future_count = self::count_future_sessions();
                    $remaining    = max(0, self::BASE_MAX_ACTIVE_SESSIONS - $future_count);
                ?>
                <div style="margin-bottom:16px;padding:12px 16px;border-radius:10px;border:1px solid #fed7aa;background:#fff7ed;">
                    <strong>📋 <?php echo esc_html__('Base license – session limits', 'mc-ems'); ?></strong><br>
                    <?php echo esc_html(sprintf(
                        __('Active future sessions: %d / %d — you can still create %d more session(s).', 'mc-ems'),
                        (int) $future_count,
                        (int) self::BASE_MAX_ACTIVE_SESSIONS,
                        (int) $remaining
                    )); ?>
                    <br><small style="color:#92400e;"><?php echo esc_html(sprintf(
                        __('Base license: max 1 session per day, max %d active sessions, and max %d seats per session. Upgrade to Premium to remove these limits.', 'mc-ems'),
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
                            <th><?php echo esc_html__('Generation type', 'mc-ems'); ?></th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;font-weight:700;">
                                    <input type="checkbox" name="mcems_generate_special" id="mcems_generate_special" value="1">
                                    ♿ <?php echo esc_html__('Create an exam session for a candidate with special requirements', 'mc-ems'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div id="mcems_gen_standard">
                        <table class="form-table">
                            <tr>
                                <th><label for="mcems_course_id"><?php echo esc_html__('Tutor LMS course', 'mc-ems'); ?></label></th>
                                <td>
                                    <?php if (!$course_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected (course post type not found).', 'mc-ems'); ?></em>
                                    <?php elseif (!$courses): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS course found.', 'mc-ems'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_course_id" name="course_id">
                                            <option value=""><?php echo esc_html__('— Select course —', 'mc-ems'); ?></option>
                                            <?php foreach ($courses as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Select dates', 'mc-ems'); ?></th>
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
                                        #mcems-selected-count{margin-top:6px;font-size:12px;color:#646970;}
                                        </style>
                                        <div id="mcems-calendar"></div>
                                        <div id="mcems-selected-count"></div>
                                        <div id="mcems-selected-dates"></div>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <?php if (!$is_premium): ?>
                                    <th><label for="mcems_times"><?php echo esc_html__('Exam session time', 'mc-ems'); ?></label></th>
                                    <td>
                                        <input
                                            type="time"
                                            id="mcems_times"
                                            name="times"
                                            required
                                        >
                                        <p class="description"><?php echo esc_html__('Base license: only one time per day allowed.', 'mc-ems'); ?></p>
                                    </td>
                                <?php else: ?>
                                    <th><label for="mcems_times"><?php echo esc_html__('Exam session times (one per line)', 'mc-ems'); ?></label></th>
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
                                <th><label for="mcems_capacity"><?php echo esc_html__('Seats per exam session', 'mc-ems'); ?></label></th>
                                <td>
                                    <?php if (!$is_premium): ?>
                                        <input type="number" id="mcems_capacity" name="capacity" min="1">
                                        <p class="description"><?php echo esc_html(sprintf(__('Base license: max %d seats per session.', 'mc-ems'), self::BASE_MAX_CAPACITY)); ?></p>
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
                                <th><label for="mcems_special_course_id"><?php echo esc_html__('Tutor LMS course', 'mc-ems'); ?></label></th>
                                <td>
                                    <?php if (!$course_pt): ?>
                                        <em><?php echo esc_html__('Tutor LMS not detected.', 'mc-ems'); ?></em>
                                    <?php elseif (!$courses): ?>
                                        <em><?php echo esc_html__('No published Tutor LMS course found.', 'mc-ems'); ?></em>
                                    <?php else: ?>
                                        <select id="mcems_special_course_id" name="special_course_id" disabled>
                                            <option value=""><?php echo esc_html__('— Select course —', 'mc-ems'); ?></option>
                                            <?php foreach ($courses as $cid => $title): ?>
                                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="mcems_special_date"><?php echo esc_html__('Date', 'mc-ems'); ?></label></th>
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
                                <th><label for="mcems_special_time"><?php echo esc_html__('Time (single)', 'mc-ems'); ?></label></th>
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
                                <th><?php echo esc_html__('Seats', 'mc-ems'); ?></th>
                                <td><input type="number" value="1" readonly></td>
                            </tr>

                            <tr>
                                <th><?php echo esc_html__('Candidate (email)', 'mc-ems'); ?></th>
                                <td>
                                    <div style="max-width:520px; position:relative;">
                                        <input
                                            type="email"
                                            id="mcems_special_user_email"
                                            name="special_user_email"
                                            value=""
                                            placeholder="Type an email to search…"
                                            autocomplete="off"
                                            style="width:100%;"
                                            disabled
                                        >
                                        <input type="hidden" id="mcems_special_user_id" name="special_user_id" value="">
                                        <div id="mcems_user_suggest" style="display:none; position:absolute; left:0; right:0; top:100%; z-index:9999; background:#fff; border:1px solid #c3c4c7; border-top:none; max-height:240px; overflow:auto;"></div>
                                    </div>
                                    <p class="description" style="margin-top:8px;"><?php echo esc_html__('Start typing an email address, then click the user to select.', 'mc-ems'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary" name="mcems_submit_generate" value="1">
                            <?php echo esc_html__('Generate Sessions', 'mc-ems'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <hr>

            <div class="card" style="max-width: 1100px;">
            </div>
        </div>

        <script>
        (function(){
            const cb = document.getElementById('mcems_generate_special');
            const std = document.getElementById('mcems_gen_standard');
            const sp  = document.getElementById('mcems_gen_special');

            const specialCourse = document.getElementById('mcems_special_course_id');
            const specialDate   = document.getElementById('mcems_special_date');
            const specialTime   = document.getElementById('mcems_special_time');
            const specialEmail  = document.getElementById('mcems_special_user_email');

            const standardCourse = document.getElementById('mcems_course_id');
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

                    setDisabled(specialCourse, false);
                    setDisabled(specialDate, false);
                    setDisabled(specialTime, false);
                    setDisabled(specialEmail, false);

                    setDisabled(standardCourse, true);
                    setDisabled(standardStart, true);
                    setDisabled(standardEnd, true);
                    setDisabled(standardTimes, true);
                    setDisabled(standardCap, true);
                } else {
                    if (std) std.style.display = 'block';
                    if (sp) sp.style.display = 'none';

                    setDisabled(specialCourse, true);
                    setDisabled(specialDate, true);
                    setDisabled(specialTime, true);
                    setDisabled(specialEmail, true);

                    if (specialTime) {
                        specialTime.value = '';
                        specialTime.removeAttribute('min');
                    }

                    setDisabled(standardCourse, false);
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

                const sel = document.getElementById(isSpecial ? 'mcems_special_course_id' : 'mcems_course_id');
                if (sel && !sel.value) {
                    e.preventDefault();
                    alert('Select a Tutor LMS course before generating sessions.');
                    sel.focus();
                    return;
                }

                if (!isSpecial) {
                    const calContainer = document.getElementById('mcems-selected-dates');
                    if (calContainer && calContainer.querySelectorAll('input[name="selected_dates[]"]').length === 0) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Select at least one date from the calendar.', 'mc-ems')); ?>');
                        return;
                    }

                    const ta = document.getElementById('mcems_times');
                    if (ta) {
                        // Support both <input type="time"> (base) and <textarea> (premium)
                        const isTimeInput = (ta.tagName === 'INPUT');
                        if (isTimeInput) {
                            if (!ta.value || !/^\d{2}:\d{2}$/.test(ta.value.trim())) {
                                e.preventDefault();
                                alert('Enter a valid time (HH:MM).');
                                ta.focus();
                                return;
                            }
                        } else {
                            const hasTime = (ta.value || '').split(/\r\n|\r|\n/).some(function(l){
                                return /^\s*\d{2}:\d{2}\s*$/.test(l);
                            });

                            if (!hasTime) {
                                e.preventDefault();
                                alert('Enter at least one valid time (HH:MM), one per line.');
                                ta.focus();
                                return;
                            }
                        }
                    }

                    const capInput = document.getElementById('mcems_capacity');
                    const maxCap = <?php echo $is_premium ? 'null' : (int) self::BASE_MAX_CAPACITY; ?>;
                    if (capInput && maxCap !== null && parseInt(capInput.value, 10) > maxCap) {
                        e.preventDefault();
                        alert('Base license: max ' + maxCap + ' seats per session.');
                        capInput.focus();
                        return;
                    }
                } else {
                    const sDate = document.getElementById('mcems_special_date');
                    const sTime = document.getElementById('mcems_special_time');
                    const sUser = document.getElementById('mcems_special_user_email');

                    if (sDate && !sDate.value) {
                        e.preventDefault();
                        alert('Select a date for the special session.');
                        sDate.focus();
                        return;
                    }

                    if (sTime && !sTime.value) {
                        e.preventDefault();
                        alert('Select a time for the special session.');
                        sTime.focus();
                        return;
                    }

                    if (sUser && !sUser.value.trim()) {
                        e.preventDefault();
                        alert('Select the candidate email for the special session.');
                        sUser.focus();
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
                    row.innerHTML = '<strong>' + (u.email || '') + '</strong>' + (u.name ? '<div style="font-size:12px; opacity:.85;">' + u.name + '</div>' : '');

                    row.addEventListener('mouseenter', function(){ row.style.background = '#f6f7f7'; });
                    row.addEventListener('mouseleave', function(){ row.style.background = '#fff'; });

                    row.addEventListener('click', function(){
                        input.value = u.email || '';
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
                __('January','mc-ems'),__('February','mc-ems'),__('March','mc-ems'),
                __('April','mc-ems'),__('May','mc-ems'),__('June','mc-ems'),
                __('July','mc-ems'),__('August','mc-ems'),__('September','mc-ems'),
                __('October','mc-ems'),__('November','mc-ems'),__('December','mc-ems'),
            ]); ?>;
            var dayNames = <?php echo wp_json_encode([
                __('Mo','mc-ems'),__('Tu','mc-ems'),__('We','mc-ems'),
                __('Th','mc-ems'),__('Fr','mc-ems'),__('Sa','mc-ems'),__('Su','mc-ems'),
            ]); ?>;

            function updateHidden(){
                var container = document.getElementById('mcems-selected-dates');
                var countEl   = document.getElementById('mcems-selected-count');
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
                if (countEl) {
                    if (keys.length) {
                        var ul = document.createElement('ul');
                        ul.style.margin  = '4px 0 0 0';
                        ul.style.padding = '0 0 0 16px';
                        ul.setAttribute('aria-label', <?php echo wp_json_encode(__('Selected dates', 'mc-ems')); ?>);
                        keys.forEach(function(d){
                            var li = document.createElement('li');
                            li.textContent = d;
                            li.style.fontSize = '12px';
                            ul.appendChild(li);
                        });
                        countEl.innerHTML = '';
                        countEl.appendChild(ul);
                    } else {
                        countEl.innerHTML = '';
                    }
                }
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
        $selected_dates_raw = isset($_POST['selected_dates']) && is_array($_POST['selected_dates'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_dates']))
            : [];
        $times_raw = (string) wp_unslash($_POST['times'] ?? '');
        $capacity  = max(1, (int) ($_POST['capacity'] ?? 1));
        $course_id = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;

        if ($course_id <= 0) {
            return ['', 'Select a Tutor LMS course.'];
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
            return ['', __('Select at least one date from the calendar.', 'mc-ems')];
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
            return ['', __('Enter at least one valid time (HH:MM), one per line.', 'mc-ems')];
        }

        // Base license limits: max 1 time per day, max BASE_MAX_ACTIVE_SESSIONS future sessions, max BASE_MAX_CAPACITY seats.
        $is_premium = defined('EMS_PREMIUM_VERSION');
        if (!$is_premium) {
            $times = [$times[0]];
            $capacity = min($capacity, self::BASE_MAX_CAPACITY);

            $future_count = self::count_future_sessions();
            if ($future_count >= self::BASE_MAX_ACTIVE_SESSIONS) {
                return ['', sprintf(
                    __('Base license limit reached: you already have %d active (future) sessions (maximum %d). Delete or wait for existing sessions to pass before creating new ones.', 'mc-ems'),
                    (int) $future_count,
                    (int) self::BASE_MAX_ACTIVE_SESSIONS
                )];
            }
        }

        $created = 0;
        $skipped = 0;
        $insert_errors = [];

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        // For base license, track how many sessions we've created in this batch.
        $future_count_start = $is_premium ? 0 : $future_count;
        $batch_created      = 0;

        // For base license, pre-fetch all existing session dates in the selected range to avoid
        // one DB query per date inside the loop.
        $existing_dates_in_range = [];
        if (!$is_premium && $selected_dates) {
            $existing_dates_in_range = self::get_session_dates_in_range($selected_dates[0], end($selected_dates));
        }

        foreach ($selected_dates as $date) {
            // Base license: block if max future sessions would be exceeded.
            if (!$is_premium && ($future_count_start + $batch_created) >= self::BASE_MAX_ACTIVE_SESSIONS) {
                $skipped++;
                continue;
            }

            // Base license: skip days that already have a session.
            if (!$is_premium && in_array($date, $existing_dates_in_range, true)) {
                $skipped++;
                continue;
            }

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

                if (self::session_exists($date, $time, $course_id)) {
                    $skipped++;
                    continue;
                }

                $sid = self::create_session($date, $time, $capacity, 0, 0, $course_id);

                if ($sid) {
                    $created++;
                    $batch_created++;
                } else {
                    $skipped++;
                    $insert_errors[] = $date . ' ' . $time;
                }
            }
        }

        if (!$created && $insert_errors) {
            return ['', sprintf(__('Unable to create sessions for: %s', 'mc-ems'), implode(', ', array_slice($insert_errors, 0, 5)))];
        }

        return [sprintf(__('Creation completed: %d sessions created, %d skipped.', 'mc-ems'), $created, $skipped), ''];
    }

    private static function handle_generate_special(): array {
        $date      = sanitize_text_field(wp_unslash($_POST['special_date'] ?? ''));
        $time      = sanitize_text_field(wp_unslash($_POST['special_time'] ?? ''));
        $uid       = (int) ($_POST['special_user_id'] ?? 0);
        $email     = sanitize_email(wp_unslash($_POST['special_user_email'] ?? ''));
        $course_id = isset($_POST['special_course_id']) ? (int) $_POST['special_course_id'] : 0;

        if ($course_id <= 0) {
            return ['', 'Select a Tutor LMS course.'];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['', __('Invalid date/time.', 'mc-ems')];
        }

        $tz = wp_timezone();

        try {
            $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
            $now = new \DateTimeImmutable('now', $tz);

            if ($session_dt < $now) {
                return ['', __('Past sessions cannot be created. Please choose a future date and time.', 'mc-ems')];
            }
        } catch (\Throwable $e) {
            return ['', __('Invalid date/time.', 'mc-ems')];
        }

        if ($uid <= 0 && $email) {
            $u = get_user_by('email', $email);
            if ($u && !is_wp_error($u)) {
                $uid = (int) $u->ID;
            }
        }

        if ($uid <= 0 || !get_user_by('id', $uid)) {
            return ['', __('Invalid candidate email.', 'mc-ems')];
        }

        if (self::session_exists($date, $time, $course_id, true)) {
            return ['', __('A special session already exists with this date/time for this course.', 'mc-ems')];
        }

        $sid = self::create_session($date, $time, 1, 1, $uid, $course_id);
        if (!$sid) {
            return ['', __('Unable to create exam session.', 'mc-ems')];
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

        return [__('Special exam session created and exam booked for candidate (#', 'mc-ems') . $sid . ').', ''];
    }

    private static function handle_update_capacity(): array {
        $new_cap     = max(1, (int) ($_POST['new_capacity'] ?? 1));
        $only_future = !empty($_POST['only_future']);

        $ids = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $updated = 0;
        $today = date('Y-m-d');

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

        return [sprintf(__('Update completed: %d sessions updated.', 'mc-ems'), $updated), ''];
    }

    /**
     * Count published sessions whose date is today or in the future.
     * Used to enforce the Base license limit of BASE_MAX_ACTIVE_SESSIONS.
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
     * Used to enforce the Base license "one session per day" rule.
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

    private static function session_exists(string $date, string $time, int $course_id, bool $special_only = false): bool {
        $meta = [
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_DATE, 'value' => $date],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_TIME, 'value' => $time],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_COURSE_ID, 'value' => $course_id],
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

    private static function create_session(string $date, string $time, int $capacity, int $is_special, int $special_user_id, int $course_id): int {
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
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_COURSE_ID, $course_id > 0 ? (int) $course_id : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, max(1, $capacity));
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, []);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, $is_special ? 1 : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $special_user_id > 0 ? (int) $special_user_id : 0);

        return (int) $sid;
    }
}