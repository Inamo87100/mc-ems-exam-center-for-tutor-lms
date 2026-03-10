<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Admin_Sessioni {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . NFEMS_CPT_Sessioni_Esame::CPT,
            __('Exam Sessions Management', 'mc-ems'),
            __('Exam Sessions Management', 'mc-ems'),
            'manage_options',
            'nfems-gestione-sessioni',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions', 403);

        $today = date('Y-m-d');
        $week  = date('Y-m-d', strtotime('+7 days'));

        $courses  = NFEMS_Tutor::get_courses();
        $course_pt= NFEMS_Tutor::course_post_type();

        $notice = '';
        $error  = '';

        $posted = ($_SERVER['REQUEST_METHOD'] === 'POST');
        if ($posted && empty($_POST['nfems_action'])) {
            $error = 'Form submission detected but missing action (nfems_action). Check whether security/cache plugins are altering POST requests.';
        }
        /* nfems_post_debug */

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nfems_action'])) {
            $action = sanitize_text_field($_POST['nfems_action']);

            if ($action === 'generate' && check_admin_referer('nfems_generate','nfems_generate_nonce')) {
                $is_special = !empty($_POST['nfems_generate_special']);
                if ($is_special) {
                    [$notice, $error] = self::handle_generate_special();
                } else {
                    [$notice, $error] = self::handle_generate_standard();
                }
            }

            if ($action === 'update_capacity' && check_admin_referer('nfems_update_capacity','nfems_update_capacity_nonce')) {
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
                <h2>Generate new sessions</h2>

                <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . NFEMS_CPT_Sessioni_Esame::CPT . '&page=nfems-gestione-sessioni')); ?>">
                    <?php wp_nonce_field('nfems_generate','nfems_generate_nonce'); ?>
                    <input type="hidden" name="nfems_action" value="generate">

                    <table class="form-table">
                        <tr>
                            <th>Generation type</th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;font-weight:700;">
                                    <input type="checkbox" name="nfems_generate_special" id="nfems_generate_special" value="1">
                                    ♿ Create an exam session for a candidate with special requirements
                                </label>
                            </td>
                        </tr>
                    </table>

                    <!-- STANDARD -->
                    <div id="nfems_gen_standard">
                        <table class="form-table">
                            <tr>
                                <th><label for="nfems_course_id">Tutor LMS course</label></th>
                                <td>
                                    <?php if (!$course_pt): ?>
                                        <em>Tutor LMS not detected (course post type not found).</em>
                                    <?php elseif (!$courses): ?>
                                        <em>No published Tutor LMS course found.</em>
                                    <?php else: ?>
                                        <select id="nfems_course_id" name="course_id">
                                            <option value="">— Select course —</option>
                                            <?php foreach ($courses as $cid => $title): ?>
                                                <option value="<?php echo (int)$cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nfems_date_start">Start date</label></th>
                                <td><input type="date" id="nfems_date_start" name="date_start" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="nfems_date_end">End date</label></th>
                                <td><input type="date" id="nfems_date_end" name="date_end" value="<?php echo esc_attr($week); ?>" min="<?php echo esc_attr($today); ?>"></td>
                            </tr>
                            <tr>
                                <th>Weekdays</th>
                                <td>
                                    <?php
                                    $days = [
                                        'monday'    => __('Monday', 'mc-ems'),
                                        'tuesday'   => __('Tuesday', 'mc-ems'),
                                        'wednesday' => __('Wednesday', 'mc-ems'),
                                        'thursday'  => __('Thursday', 'mc-ems'),
                                        'friday'    => __('Friday', 'mc-ems'),
                                        'saturday'  => __('Saturday', 'mc-ems'),
                                        'sunday'    => __('Sunday', 'mc-ems'),
                                    ];
                                    foreach ($days as $k=>$lbl) {
                                        echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="days[]" value="'.esc_attr($k).'" checked> '.esc_html($lbl).'</label>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nfems_times">Exam session times (one per line)</label></th>
                                <td>
                                    <textarea id="nfems_times" name="times" rows="5" cols="40" placeholder="08:30&#10;10:30&#10;12:30"></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nfems_capacity">Seats per exam session</label></th>
                                <td><input type="number" id="nfems_capacity" name="capacity" value="25" min="1" max="500"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- SPECIAL -->
                    <div id="nfems_gen_special" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th><label for="nfems_special_course_id">Tutor LMS course</label></th>
                                <td>
                                    <?php if (!$course_pt): ?>
                                        <em>Tutor LMS not detected.</em>
                                    <?php elseif (!$courses): ?>
                                        <em>No published Tutor LMS course found.</em>
                                    <?php else: ?>
                                        <select id="nfems_special_course_id" name="special_course_id">
                                            <option value="">— Select course —</option>
                                            <?php foreach ($courses as $cid => $title): ?>
                                                <option value="<?php echo (int)$cid; ?>"><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nfems_special_date">Date</label></th>
                                <td><input type="date" id="nfems_special_date" name="special_date" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="nfems_special_time">Time (single)</label></th>
                                <td><input type="time" id="nfems_special_time" name="special_time" value="09:00"></td>
                            </tr>
                            <tr>
                                <th>Seats</th>
                                <td><input type="number" value="1" readonly></td>
                            </tr>
                            <tr>
                                <th>Candidate (email)</th>
                                <td>
                                    <div style="max-width:520px; position:relative;">
                                        <input
                                            type="email"
                                            id="nfems_special_user_email"
                                            name="special_user_email"
                                            value=""
                                            placeholder="Type an email to search…"
                                            autocomplete="off"
                                            style="width:100%;"
                                        >
                                        <input type="hidden" id="nfems_special_user_id" name="special_user_id" value="">
                                        <div id="nfems_user_suggest" style="display:none; position:absolute; left:0; right:0; top:100%; z-index:9999; background:#fff; border:1px solid #c3c4c7; border-top:none; max-height:240px; overflow:auto;"></div>
                                    </div>
                                    <p class="description" style="margin-top:8px;">Start typing an email address, then click the user to select.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php submit_button(__('Generate Sessions', 'mc-ems')); ?>
                </form>
            </div>

            <hr>

            <div class="card" style="max-width: 1100px;">
                </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
            (function(){
                const cb = document.getElementById('nfems_generate_special');
                const std = document.getElementById('nfems_gen_standard');
                const sp  = document.getElementById('nfems_gen_special');
                if (!cb) return;
                function toggle(){
                    if (cb.checked) { std.style.display='none'; sp.style.display='block'; }
                    else { std.style.display='block'; sp.style.display='none'; }
                }
                cb.addEventListener('change', toggle);
                toggle();
            })();

            // Client-side hint: makes issues visible (does not replace server-side checks)
            (function(){
                const genForm = document.querySelector('input[name="nfems_action"][value="generate"]')?.closest('form');
                if (!genForm) {
                    console.warn('[MC-EMS] Generate form not found.');
                    return;
                }

                genForm.addEventListener('submit', function(e){
                    const special = document.getElementById('nfems_generate_special');
                    const isSpecial = special && special.checked;

                    const sel = document.getElementById(isSpecial ? 'nfems_special_course_id' : 'nfems_course_id');
                    if (sel && !sel.value) {
                        e.preventDefault();
                        alert('Select a Tutor LMS course before generating sessions.');
                        sel.focus();
                        return;
                    }

                    // Standard: ensure times has at least one HH:MM
                    if (!isSpecial) {
                        const ta = document.getElementById('nfems_times');
                        if (ta) {
                            const hasTime = (ta.value || '').split(/\r\n|\r|\n/).some(l => /^\s*\d{2}:\d{2}\s*$/.test(l));
                            if (!hasTime) {
                                e.preventDefault();
                                alert('Enter at least one valid time (HH:MM), one per line.');
                                ta.focus();
                            }
                        }
                    }
                });
            })();
        });
        /* nfems-validate-generate */

        // Candidate email search (special sessions)
        document.addEventListener('DOMContentLoaded', function(){
        (function(){
            const input = document.getElementById('nfems_special_user_email');
            const hidden = document.getElementById('nfems_special_user_id');
            const box = document.getElementById('nfems_user_suggest');
            if (!input || !hidden || !box || typeof ajaxurl === 'undefined') return;

            const nonce = <?php echo json_encode(wp_create_nonce('mcems_user_search')); ?>;
            let timer = null;
            let last = '';

            function clearSuggest(){
                box.innerHTML = '';
                box.style.display = 'none';
            }

            function render(items){
                if (!items || !items.length) { clearSuggest(); return; }
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
                const res = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd });
                const json = await res.json().catch(() => null);
                if (json && json.success) render(json.data); else clearSuggest();
            }

            input.addEventListener('input', function(){
                const q = (input.value || '').trim();
                hidden.value = '';
                if (q.length < 3) { clearSuggest(); return; }
                last = q;
                if (timer) clearTimeout(timer);
                timer = setTimeout(function(){
                    if (last === q) doSearch(q);
                }, 250);
            });

            document.addEventListener('click', function(e){
                if (!box.contains(e.target) && e.target !== input) clearSuggest();
            });
        })();
        });
        </script>
<script>
// nfems_prevent_past_date
document.addEventListener('DOMContentLoaded', function(){
(function(){
    function pad(n){ return (n<10?'0':'')+n; }
    function todayYMD(){
        var d=new Date();
        return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
    }
    function nowHM(){
        var d=new Date();
        return pad(d.getHours())+':'+pad(d.getMinutes());
    }
    function setMinDate(id){
        var el=document.getElementById(id);
        if(!el) return;
        el.setAttribute('min', todayYMD());
    }
    function bindDateTime(dateId, timeId){
        var dEl=document.getElementById(dateId);
        var tEl=document.getElementById(timeId);
        if(!dEl || !tEl) return;
        function update(){
            var t=todayYMD();
            if(dEl.value===t){
                var minTime=nowHM();
                tEl.setAttribute('min', minTime);
                if(tEl.value < minTime){ tEl.value=minTime; }
            } else {
                tEl.removeAttribute('min');
            }
        }
        dEl.addEventListener('change', update);
        update();
    }
    setMinDate('nfems_date_start');
    setMinDate('nfems_date_end');
    setMinDate('nfems_special_date');
    bindDateTime('nfems_special_date', 'nfems_special_time');
})();
});
</script>

        <?php
    }

    private static function handle_generate_standard(): array {
        $start = sanitize_text_field($_POST['date_start'] ?? '');
        $end   = sanitize_text_field($_POST['date_end'] ?? '');
        $days  = isset($_POST['days']) && is_array($_POST['days']) ? array_map('sanitize_text_field', $_POST['days']) : [];
        $times_raw = (string) ($_POST['times'] ?? '');
        $capacity  = max(1, (int) ($_POST['capacity'] ?? 1));
        $course_id = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;

        if ($course_id <= 0) return ['', 'Select a Tutor LMS course.'];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return ['', __('Invalid date(s).', 'mc-ems')];
        }
        if (strtotime($end) < strtotime($start)) {
            return ['', __('End date cannot be earlier than start date.', 'mc-ems')];
        }
        if (!$days) return ['', 'Select at least one weekday.'];

        $times = [];
        foreach (preg_split("/\r\n|\r|\n/", $times_raw) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $t)) continue;
            $times[] = $t;
        }
        $times = array_values(array_unique($times));
        sort($times);
        if (!$times) return ['', __('Enter at least one valid time (HH:MM), one per line.', 'mc-ems')];

        $created = 0;
        $skipped = 0;
        $insert_errors = [];

        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        $cur = strtotime($start);
        $endTs = strtotime($end);

        while ($cur <= $endTs) {
            $dow = strtolower(date('l', $cur));
            if (in_array($dow, $days, true)) {
                $date = date('Y-m-d', $cur);

                foreach ($times as $time) {
                    // Prevent creation of past sessions (including time).
                    try {
                        $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
                        if ($session_dt < $now) {
                            $skipped++;
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // If parsing fails, skip.
                        $skipped++;
                        continue;
                    }

                    if (self::session_exists($date, $time, $course_id)) {
                        $skipped++;
                        continue;
                    }
                    $sid = self::create_session($date, $time, $capacity, 0, 0, $course_id);
                    if ($sid) { $created++; } else { $skipped++; $insert_errors[] = $date . ' ' . $time; }
                }
            }
            $cur = strtotime('+1 day', $cur);
        }

        if (!$created && $insert_errors) {
            return ['', sprintf(__('Unable to create sessions for: %s', 'mc-ems'), implode(', ', array_slice($insert_errors, 0, 5)))];
        }
        return [sprintf(__('Creation completed: %d sessions created, %d skipped.', 'mc-ems'), $created, $skipped), ''];
    }

    private static function handle_generate_special(): array {
        $date = sanitize_text_field($_POST['special_date'] ?? '');
        $time = sanitize_text_field($_POST['special_time'] ?? '');
        $uid  = (int) ($_POST['special_user_id'] ?? 0);
        $email = sanitize_email($_POST['special_user_email'] ?? '');
        $course_id = isset($_POST['special_course_id']) ? (int) $_POST['special_course_id'] : 0;

        if ($course_id <= 0) return ['', 'Select a Tutor LMS course.'];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['', __('Invalid date/time.', 'mc-ems')];
        }

        // Prevent creation of past sessions (including time).
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
        if (!$sid) return ['', __('Unable to create exam session.', 'mc-ems')];

        // Prenota automaticamente per il candidato
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, [$uid]);

        update_user_meta($uid, NFEMS_Booking::UM_ACTIVE_BOOKING, [
            'slot_id'    => $sid,
            'data'       => $date,
            'orario'     => $time,
            'created_at' => current_time('mysql'),
        ]);

        $storico = get_user_meta($uid, NFEMS_Booking::UM_HISTORY, true);
        if (!is_array($storico)) $storico = [];
        $storico[] = [
            'slot_id'   => $sid,
            'data'      => $date,
            'orario'    => $time,
            'azione'    => 'prenotata',
            'timestamp' => (int) current_time('timestamp'),
        ];
        update_user_meta($uid, NFEMS_Booking::UM_HISTORY, $storico);

        return [__('Special exam session created and exam booked for candidate (#', 'mc-ems').$sid.').', ''];
    }

    private static function handle_update_capacity(): array {
        $new_cap = max(1, (int) ($_POST['new_capacity'] ?? 1));
        $only_future = !empty($_POST['only_future']);

        $ids = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $updated = 0;
        $today = date('Y-m-d');

        foreach ($ids as $sid) {
            $sid = (int) $sid;
            $is_special = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true);
            $date = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);

            if ($only_future && $date && $date < $today) continue;

            if ($is_special === 1) {
                update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, 1);
                continue;
            }

            update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, $new_cap);
            $updated++;
        }

        return [sprintf(__('Update completed: %d sessions updated.', 'mc-ems'), $updated), ''];
    }

    private static function session_exists(string $date, string $time, int $course_id, bool $special_only=false): bool {
        $meta = [
            ['key'=>NFEMS_CPT_Sessioni_Esame::MK_DATE, 'value'=>$date],
            ['key'=>NFEMS_CPT_Sessioni_Esame::MK_TIME, 'value'=>$time],
            ['key'=>NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, 'value'=>$course_id],
        ];
        if ($special_only) $meta[] = ['key'=>NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, 'value'=>1];

        $q = new WP_Query([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta,
        ]);
        return $q->have_posts();
    }

    private static function create_session(string $date, string $time, int $capacity, int $is_special, int $special_user_id, int $course_id): int {
        $sid = wp_insert_post([
            'post_type'   => NFEMS_CPT_Sessioni_Esame::CPT,
            'post_status' => 'publish',
            'post_title'  => "Session {$date} {$time}",
        ], true);

        if (is_wp_error($sid) || !$sid) return 0;

        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, $date);
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, $time);
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, $course_id > 0 ? (int)$course_id : 0);
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, max(1,$capacity));
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, []);
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, $is_special ? 1 : 0);
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $special_user_id > 0 ? (int)$special_user_id : 0);

        return (int) $sid;
    }
}
