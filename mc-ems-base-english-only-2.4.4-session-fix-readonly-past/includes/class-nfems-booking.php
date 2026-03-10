<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Booking {

    // New: per-course active exam bookings map course_id => booking data
    const UM_ACTIVE_BOOKINGS = 'nfems_active_bookings'; // array
    // Legacy single booking
    const UM_ACTIVE_BOOKING  = 'nfems_active_booking';  // array
    const UM_HISTORY         = 'storico_prenotazioni_slot';

    public static function init(): void {
        add_shortcode('mcems_book_exam', [__CLASS__, 'shortcode_prenota']);
        add_shortcode('mcems_manage_booking', [__CLASS__, 'shortcode_gestisci']);

        add_action('wp_ajax_get_slot_per_data', [__CLASS__, 'ajax_get_slots_by_date']);
        add_action('wp_ajax_nopriv_get_slot_per_data', [__CLASS__, 'ajax_get_slots_by_date']);

        add_action('wp_ajax_conferma_prenotazione_slot', [__CLASS__, 'ajax_confirm_booking']);
        add_action('wp_ajax_nopriv_conferma_prenotazione_slot', [__CLASS__, 'ajax_confirm_booking']);

        add_action('wp_ajax_nfems_cancel_booking', [__CLASS__, 'ajax_cancel_booking']);

        // Cleanup when a session is deleted from admin
        add_action('before_delete_post', [__CLASS__, 'on_before_delete_post'], 10, 1);
        add_action('trashed_post', [__CLASS__, 'on_before_delete_post'], 10, 1);
    }

    /* =========================
       Settings
       ========================= */
    private static function get_anticipo_ore(): int {
        return max(0, NFEMS_Settings::get_int('anticipo_ore_prenotazione'));
    }

    private static function get_annullamento_ore(): int {
        return max(0, NFEMS_Settings::get_int('annullamento_ore'));
    }

    private static function is_annullamento_consentito(): bool {
        return NFEMS_Settings::get_int('consenti_annullamento') === 1;
    }

    /* =========================
       Active bookings helpers
       ========================= */
    private static function get_active_bookings(int $user_id): array {
        $map = get_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, true);
        if (!is_array($map)) $map = [];

        // One-time migration from legacy single booking (if present)
        $legacy = get_user_meta($user_id, self::UM_ACTIVE_BOOKING, true);
        if (is_array($legacy) && !empty($legacy['slot_id'])) {
            $slot_id = (int) $legacy['slot_id'];
            $course_id = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);
            if ($course_id > 0) {
                $map[$course_id] = [
                    'slot_id'    => $slot_id,
                    'data'       => (string) ($legacy['data'] ?? ''),
                    'orario'     => (string) ($legacy['orario'] ?? ''),
                    'created_at' => (string) ($legacy['created_at'] ?? ''),
                ];
                update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
            }
            delete_user_meta($user_id, self::UM_ACTIVE_BOOKING);
        }

        // Clean invalid bookings (deleted sessions)
        $changed = false;
        foreach ($map as $cid => $b) {
            $sid = isset($b['slot_id']) ? (int)$b['slot_id'] : 0;
            if ($sid <= 0 || get_post_type($sid) !== NFEMS_CPT_Sessioni_Esame::CPT) {
                unset($map[$cid]);
                $changed = true;
                continue;
            }
            // Also ensure user is still in occupati; if not, remove it
            $occ = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            $occ = is_array($occ) ? $occ : [];
            if (!in_array($user_id, $occ, true)) {
                unset($map[$cid]);
                $changed = true;
            }
        }
        if ($changed) update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);

        return $map;
    }

    private static function get_active_booking_for_course(int $user_id, int $course_id): array {
        $map = self::get_active_bookings($user_id);
        return isset($map[$course_id]) && is_array($map[$course_id]) ? $map[$course_id] : [];
    }

    private static function set_active_booking_for_course(int $user_id, int $course_id, array $booking): void {
        $map = self::get_active_bookings($user_id);
        $map[$course_id] = $booking;
        update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
    }

    private static function remove_active_booking_for_course(int $user_id, int $course_id): void {
        $map = self::get_active_bookings($user_id);
        if (isset($map[$course_id])) {
            unset($map[$course_id]);
            update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
        }
    }

    /* =========================
       History
       ========================= */
    private static function add_history(int $user_id, int $slot_id, string $azione): void {
        $storico = get_user_meta($user_id, self::UM_HISTORY, true);
        if (!is_array($storico)) $storico = [];

        $data   = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $corso  = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);

        $storico[] = [
            'slot_id'   => $slot_id,
            'data'      => $data,
            'orario'    => $orario,
            'corso_id'  => $corso,
            'azione'    => $azione,
            'timestamp' => (int) current_time('timestamp'),
        ];

        update_user_meta($user_id, self::UM_HISTORY, $storico);
    }

    /* =========================
       Shortcodes
       ========================= */

    public static function shortcode_prenota(): string {
        $user_id = (int) get_current_user_id();
        $courses = NFEMS_Tutor::get_courses();
        $course_pt = NFEMS_Tutor::course_post_type();

        ob_start();
        ?>
        <div id="prenotazione-esame" style="max-width: 640px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;">
            <h2 style="text-align: center; font-size: 1.5rem; margin-bottom: 8px;">Book your exam</h2>

            <?php if (!$user_id): ?>
                <p style="text-align:center; color:#f44336; font-weight:bold;">You must be logged in to book an exam.</p>
            <?php else: ?>
                <p style="text-align:center; margin:0 0 16px; font-size: 0.9rem; color:#666;">
                    You can book up to <strong><?php echo (int) self::get_anticipo_ore(); ?> hours</strong> before the exam session time.
                </p>

                <label for="nfems_course_select" style="font-weight:bold; display:block; margin-bottom:8px;">Choose the course:</label>
                <?php if (!$course_pt): ?>
                    <p style="color:#f44336; font-weight:bold;">Tutor LMS not detected (course post type not found).</p>
                <?php elseif (!$courses): ?>
                    <p style="color:#f44336; font-weight:bold;">No published Tutor LMS course found.</p>
                <?php else: ?>
                    <select id="nfems_course_select" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ccc; margin-bottom:20px;">
                        <option value="">— Select course —</option>
                        <?php foreach ($courses as $cid => $title): ?>
                            <option value="<?php echo (int)$cid; ?>"><?php echo esc_html($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <label for="data_esame" style="font-weight:bold; display:block; margin-bottom:8px;">Choose a date:</label>
                <input type="date" id="data_esame" name="data_esame" min="<?php echo esc_attr(date('Y-m-d')); ?>" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ccc; margin-bottom:20px;" disabled />
                <div id="slot-container" style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px;"></div>
                <div id="confirm-container" style="display:none; text-align:center; margin-top:20px;">
                    <button id="confirm-button" style="background-color:#4CAF50; color:#fff; padding:12px 24px; border:none; border-radius:5px; cursor:pointer;">Confirm booking</button>
                </div>

                <script>
                document.addEventListener("DOMContentLoaded", function () {
                    const courseSelect = document.getElementById('nfems_course_select');
                    const dateInput = document.getElementById('data_esame');
                    const slotContainer = document.getElementById('slot-container');
                    const confirmContainer = document.getElementById('confirm-container');
                    const confirmButton = document.getElementById('confirm-button');
                    let selectedSlot = null;

                    function resetSlots(msgHtml) {
                        selectedSlot = null;
                        confirmContainer.style.display = 'none';
                        slotContainer.innerHTML = msgHtml || '';
                    }

                    function ensureCourseSelected() {
                        if (!courseSelect || !courseSelect.value) {
                            if (dateInput) { dateInput.value = ''; dateInput.disabled = true; }
                            resetSlots('<p style="color:#888;">Select a course first.</p>');
                            return false;
                        }
                        if (dateInput) dateInput.disabled = false;
                        return true;
                    }

                    if (courseSelect) {
                        courseSelect.addEventListener('change', function(){
                            ensureCourseSelected();
                            // reset date on course change
                            if (dateInput) dateInput.value = '';
                        });
                    }

                    // Optional: preselect course from URL (?course_id=123)
                    try {
                        const params = new URLSearchParams(window.location.search || '');
                        const pre = params.get('course_id');
                        if (pre && courseSelect && courseSelect.querySelector('option[value="' + pre + '"]')) {
                            courseSelect.value = pre;
                        }
                    } catch(e) {}
                    ensureCourseSelected();

                    if (dateInput) {
                        dateInput.addEventListener('change', function () {
                            if (!ensureCourseSelected()) return;

                            const selectedDate = this.value;
                            resetSlots('');

                            if (!selectedDate) {
                                resetSlots('<p style="color:#888;">Select a valid date.</p>');
                                return;
                            }

                            const today = new Date().toISOString().split('T')[0];
                            if (selectedDate < today) {
                                resetSlots('<p style="color:#f44336; font-weight:bold;">Select a date that is today or later.</p>');
                                return;
                            }

                            const url = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>'
                                + '?action=get_slot_per_data'
                                + '&data=' + encodeURIComponent(selectedDate)
                                + '&course_id=' + encodeURIComponent(courseSelect ? courseSelect.value : '');

                            fetch(url)
                                .then(res => res.json())
                                .then(slots => {
                                    if (slots && slots.error) {
                                        resetSlots('<p style="color:#f44336; font-weight:bold;">' + slots.error + '</p>');
                                        return;
                                    }
                                    if (!Array.isArray(slots) || !slots.length) {
                                        resetSlots('<p style="color:#888;">No sessions available for this course and date.</p>');
                                        return;
                                    }

                                    slots.forEach(slot => {
                                        const btn = document.createElement('button');
                                        btn.type = 'button';
                                        btn.textContent = slot.orario;
                                        btn.style.margin = '5px';
                                        btn.style.padding = '10px 20px';
                                        btn.style.borderRadius = '5px';
                                        btn.style.border = '2px solid #4CAF50';
                                        btn.style.backgroundColor = '#fff';
                                        btn.style.color = '#4CAF50';
                                        btn.style.cursor = 'pointer';

                                        if (slot.occupati >= slot.max) {
                                            btn.disabled = true;
                                            btn.style.backgroundColor = '#eee';
                                            btn.style.color = '#999';
                                            btn.style.borderColor = '#ccc';
                                            btn.style.cursor = 'not-allowed';
                                        } else {
                                            btn.addEventListener('click', function () {
                                                document.querySelectorAll('#slot-container button').forEach(b => {
                                                    b.style.backgroundColor = '#fff';
                                                    b.style.color = '#4CAF50';
                                                    b.style.borderColor = '#4CAF50';
                                                });
                                                this.style.backgroundColor = '#4CAF50';
                                                this.style.color = '#fff';
                                                selectedSlot = slot.id;
                                                confirmContainer.style.display = 'block';
                                            });
                                        }
                                        slotContainer.appendChild(btn);
                                    });
                                })
                                .catch(() => resetSlots('<p style="color:#f44336;">Errore nel caricamento delle sessioni. Riprova.</p>'));
                        });
                    }

                    if (confirmButton) {
                        confirmButton.addEventListener('click', function () {
                            if (!selectedSlot) return alert('Select an exam session before confirming.');

                            const formData = new FormData();
                            formData.append('action', 'conferma_prenotazione_slot');
                            formData.append('slot_id', selectedSlot);

                            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(html => {
                                document.getElementById('prenotazione-esame').innerHTML = html;
                            })
                            .catch(() => alert(__('An error occurred. Please try again.', 'mc-ems')));
                        });
                    }
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shortcode_gestisci(): string {
        $user_id = (int) get_current_user_id();
        if (!$user_id) return '<p>You must be logged in.</p>';

        $map = self::get_active_bookings($user_id);
        if (!$map) {
            $url = NFEMS_Settings::get_booking_page_url();
            if ($url) {
                $btn = '<p><a class="button button-primary" href="' . esc_url($url) . '">Open exam booking calendar</a></p>';
                return '<p>No active exam booking.</p>' . $btn;
            }
            return '<p>No active exam booking.</p>';
        }

        // Sort by date/time
        uasort($map, function($a,$b){
            $ka = (string)($a['data'] ?? '') . ' ' . (string)($a['orario'] ?? '');
            $kb = (string)($b['data'] ?? '') . ' ' . (string)($b['orario'] ?? '');
            return strcmp($ka, $kb);
        });

        ob_start();
        ?>
        <style>
            .nfems-wrap{max-width:980px;margin:0 auto;}
            .nfems-h3{margin:0 0 10px;font-size:1.25rem;}
            .nfems-sub{margin:0 0 18px;color:#667085;font-size:.95rem;}
            .nfems-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;}
            .nfems-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 14px 12px;box-shadow:0 1px 2px rgba(16,24,40,.06);}
            .nfems-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
            .nfems-course{font-weight:800;font-size:1rem;line-height:1.3;}
            .nfems-meta{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;}
            .nfems-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px;font-weight:700;}
            .nfems-pill strong{font-weight:900;}
            .nfems-actions{margin-top:12px;display:flex;gap:10px;align-items:center;}
            .nfems-btn{appearance:none;border:1px solid #d0d5dd;background:#fff;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer;}
            .nfems-btn:hover{background:#f9fafb;}
            .nfems-muted{color:#667085;font-size:12px;font-weight:700;}
            .nfems-note{margin-top:14px;color:#667085;font-size:12px;}
        </style>

        <div class="nfems-wrap">
            <div style="padding:16px;border:1px solid #e5e7eb;border-radius:16px;background:linear-gradient(180deg,#ffffff 0%, #fbfcff 100%);">
                <div class="nfems-row">
                    <div>
                        <h3 class="nfems-h3">My exam bookings</h3>
                        <p class="nfems-sub">Here you can find your active exam bookings (one per course). You can cancel them according to the notice rules.</p>
                    </div>
                </div>

                <div class="nfems-grid">
                    <?php foreach ($map as $course_id => $b):
                        $course_id = (int)$course_id;
                        $slot_id = (int)($b['slot_id'] ?? 0);
                        $data   = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
                        $orario = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);

						$slot_ts = strtotime($data . ' ' . $orario);
						$now_ts  = (int) current_time('timestamp');
						$cancel_window = (int) self::get_annullamento_ore() * HOUR_IN_SECONDS;
						// Rule:
						// - If the session is in the past, cancellation is always allowed.
						// - If the session is in the future, cancellation is allowed only BEFORE the cancellation window.
						$can_cancel = self::is_annullamento_consentito() && (
							$slot_ts <= $now_ts || ($slot_ts - $now_ts) > $cancel_window
						);

                        $data_h = $data ? date_i18n('d/m/Y', strtotime($data)) : '';
                        ?>
                        <div class="nfems-card">
                            <div class="nfems-row">
                                <div class="nfems-course"><?php echo esc_html(NFEMS_Tutor::course_title($course_id)); ?></div>
                                <div class="nfems-muted">ID: <?php echo (int)$slot_id; ?></div>
                            </div>

                            <div class="nfems-meta">
                                <span class="nfems-pill">📅 <strong><?php echo esc_html($data_h); ?></strong></span>
                                <span class="nfems-pill">⏰ <strong><?php echo esc_html($orario); ?></strong></span>
                            </div>

							<div class="nfems-actions">
								<?php if ($can_cancel): ?>
									<button class="nfems-btn nfems-cancel" data-slot="<?php echo (int)$slot_id; ?>" data-course="<?php echo (int)$course_id; ?>">Cancel exam booking</button>
									<?php if ($slot_ts > $now_ts): ?>
										<span class="nfems-muted">Exam booking cancellation deadline: <?php echo (int) self::get_annullamento_ore(); ?>h before the exam session</span>
									<?php else: ?>
										<span class="nfems-muted">Past exam session — cancellation allowed</span>
									<?php endif; ?>
								<?php else: ?>
									<span class="nfems-muted">Cancellation is allowed only up to <?php echo (int) self::get_annullamento_ore(); ?>h before the exam session.</span>
								<?php endif; ?>
							</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="nfems-cancel-msg" class="nfems-note"></div>
            </div>
        </div>

        <script>
        (function(){
            const msg = document.getElementById('nfems-cancel-msg');
            document.querySelectorAll('.nfems-cancel').forEach(btn => {
                btn.addEventListener('click', function(){
                    if (!confirm('Confirm exam booking cancellation?')) return;

                    const fd = new FormData();
                    fd.append('action','nfems_cancel_booking');
                    fd.append('slot_id', this.dataset.slot || '');
                    fd.append('course_id', this.dataset.course || '');
                    fd.append('nonce','<?php echo esc_js(wp_create_nonce('nfems_cancel')); ?>');

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method:'POST', body: fd })
                        .then(r => r.json())
                        .then(j => {
                            if (j && j.success) {
                                msg.textContent = '✅ Exam booking cancelled.';
                                location.reload();
                            } else {
                                msg.textContent = '⚠️ ' + ((j && j.data) ? j.data : 'Error.');
                            }
                        })
                        .catch(()=> msg.textContent='⚠️ Network error');
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }


    /* =========================
       AJAX
       ========================= */

    public static function ajax_get_slots_by_date(): void {
        $data = isset($_GET['data']) ? sanitize_text_field($_GET['data']) : '';
        $course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
        if (!$data) wp_send_json([]);
        if ($course_id <= 0) wp_send_json(['error' => 'Select a course.']);

        $user_id = (int) get_current_user_id();
        if ($user_id) {
            $active = self::get_active_booking_for_course($user_id, $course_id);
            if (!empty($active['slot_id'])) {
	                $manage_url = NFEMS_Settings::get_manage_booking_page_url();
                    if ($manage_url) {
                        $manage_link = '<a href="' . esc_url($manage_url) . '">' . esc_html__('Manage exam booking', 'mc-ems') . '</a>';
                        $msg = sprintf(
                            /* translators: %s is a link to the Manage exam booking page. */
                            __('You already have an active exam booking for this course. Go to %s to cancel it.', 'mc-ems'),
                            $manage_link
                        );
                    } else {
                        $msg = __('You already have an active exam booking for this course. Please open the Manage exam booking page to cancel it.', 'mc-ems');
                    }
                    wp_send_json(['error' => $msg]);
            }
        }

        $meta_query = [
            [
                'key'     => NFEMS_CPT_Sessioni_Esame::MK_DATE,
                'value'   => $data,
                'compare' => '=',
            ],
            [
                'key'     => NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID,
                'value'   => $course_id,
                'compare' => '=',
            ],
        ];

        $slots = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
            'orderby'        => 'meta_value',
            'meta_key'       => NFEMS_CPT_Sessioni_Esame::MK_TIME,
            'order'          => 'ASC',
        ]);

        $risultati    = [];
        $now_ts       = (int) current_time('timestamp');
        $anticipo_sec = (int) self::get_anticipo_ore() * HOUR_IN_SECONDS;

        foreach ($slots as $slot) {
            $orario     = (string) get_post_meta($slot->ID, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $max_posti  = (int) get_post_meta($slot->ID, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);
            $occupati   = get_post_meta($slot->ID, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            $occupati   = is_array($occupati) ? $occupati : [];

            if (count($occupati) >= $max_posti) continue;

            $slot_ts = strtotime($data . ' ' . $orario);
            if (($slot_ts - $now_ts) <= $anticipo_sec) continue;

            $is_special = ((int) get_post_meta($slot->ID, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
            $spec_uid   = (int) get_post_meta($slot->ID, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
            if ($is_special && $spec_uid > 0 && $user_id > 0 && $user_id !== $spec_uid) continue;

            $risultati[] = [
                'id'       => (int) $slot->ID,
                'orario'   => $orario,
                'max'      => $max_posti,
                'occupati' => count($occupati),
            ];
        }

        wp_send_json($risultati);
    }

    public static function ajax_confirm_booking(): void {
        $user_id = (int) get_current_user_id();
        if (!$user_id) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">You must be logged in to book.</p>';
            wp_die();
        }

        $slot_id = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
        if ($slot_id <= 0 || get_post_type($slot_id) !== NFEMS_CPT_Sessioni_Esame::CPT) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">Invalid exam session.</p>';
            wp_die();
        }

        $course_id = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);
        if ($course_id <= 0) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">Exam session is not associated with a course.</p>';
            wp_die();
        }

        $active_for_course = self::get_active_booking_for_course($user_id, $course_id);
        if (!empty($active_for_course['slot_id']) && (int)$active_for_course['slot_id'] !== $slot_id) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">You already have an active exam booking for this course.</p>';
            wp_die();
        }

        $lock_key = '_nfems_lock';
        if (get_post_meta($slot_id, $lock_key, true)) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">Exam session is being updated, please try again.</p>';
            wp_die();
        }
        update_post_meta($slot_id, $lock_key, time());

        $data   = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $max    = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);

        $occupati = get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        if (!is_array($occupati)) $occupati = [];

        $is_special = ((int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
        $spec_uid   = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
        if ($is_special && $spec_uid > 0 && $user_id !== $spec_uid) {
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">This exam session is reserved.</p>';
            wp_die();
        }

        if (in_array($user_id, $occupati, true)) {
            // Ensure active map is aligned
            self::set_active_booking_for_course($user_id, $course_id, [
                'slot_id'    => $slot_id,
                'data'       => $data,
                'orario'     => $orario,
                'created_at' => current_time('mysql'),
            ]);
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="text-align:center; color:#4CAF50; font-weight:bold;">Exam booking already exists.</p>';
            wp_die();
        }

        if (count($occupati) >= $max) {
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">This exam session is full.</p>';
            wp_die();
        }

        $occupati[] = $user_id;
        $occupati = array_values(array_unique(array_map('intval', $occupati)));
        update_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, $occupati);

        self::set_active_booking_for_course($user_id, $course_id, [
            'slot_id'    => $slot_id,
            'data'       => $data,
            'orario'     => $orario,
            'created_at' => current_time('mysql'),
        ]);

        self::add_history($user_id, $slot_id, 'prenotata');
        delete_post_meta($slot_id, $lock_key);
        self::maybe_send_booking_notifications($user_id, $slot_id, $course_id, 'booked');

        $course_title = NFEMS_Tutor::course_title($course_id);

        echo '<p style="text-align:center; color:#4CAF50; font-weight:bold;">Exam booking confirmed!</p>';
        if ($course_title) echo '<p style="text-align:center;">Course: <strong>' . esc_html($course_title) . '</strong></p>';
        echo '<p style="text-align:center;">Exam session: <strong>' . esc_html( date_i18n('d/m/Y', strtotime($data)) ) . '</strong> at <strong>' . esc_html($orario) . '</strong></p>';

        // Link to "Manage exam booking" page (selected in settings)
        $manage_url = NFEMS_Settings::get_manage_booking_page_url();
        if ($manage_url) {
            $manage_link = add_query_arg(['course_id' => $course_id], $manage_url);
            echo '<p style="text-align:center; margin-top:14px;"><a class="button button-primary" href="' . esc_url($manage_link) . '">Go to manage exam booking</a></p>';
        }

        wp_die();
    }

    public static function ajax_cancel_booking(): void {
        $user_id = (int) get_current_user_id();
        if (!$user_id) wp_send_json_error('You must be logged in.');

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'nfems_cancel')) {
            wp_send_json_error('Invalid nonce.');
        }

        if (!self::is_annullamento_consentito()) {
            wp_send_json_error('Cancellation is disabled.');
        }

        $slot_id = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
        $course_id = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        if ($slot_id <= 0) wp_send_json_error('Invalid exam session.');

        // If session deleted, just clear the active exam booking for that course
        if (get_post_type($slot_id) !== NFEMS_CPT_Sessioni_Esame::CPT) {
            if ($course_id > 0) self::remove_active_booking_for_course($user_id, $course_id);
            wp_send_json_success(true);
        }

        $data   = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $slot_course = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);
        if ($course_id <= 0) $course_id = $slot_course;

        $slot_ts = strtotime($data . ' ' . $orario);
        $now_ts  = (int) current_time('timestamp');

		// Rule:
		// - Past session: cancellation is always allowed.
		// - Future session: cancellation is allowed only BEFORE the cancellation window.
		if ($slot_ts > $now_ts) {
			if (($slot_ts - $now_ts) <= (self::get_annullamento_ore() * HOUR_IN_SECONDS)) {
				wp_send_json_error('Too late to cancel.');
			}
		}

        $occupati = get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        if (!is_array($occupati)) $occupati = [];

        if (!in_array($user_id, $occupati, true)) {
            // Still clear active exam booking for course to recover from inconsistencies
            if ($course_id > 0) self::remove_active_booking_for_course($user_id, $course_id);
            wp_send_json_error('You are not booked on this session (meta realigned).');
        }

        $is_special = ((int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
        $spec_uid   = (int) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
        if ($is_special && $spec_uid > 0) {
            wp_send_json_error('This session is reserved and cannot be cancelled from the front-end.');
        }

        $occupati = array_values(array_filter(array_map('intval', $occupati), function($id) use ($user_id){
            return (int)$id !== (int)$user_id;
        }));
        update_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, $occupati);

        if ($course_id > 0) self::remove_active_booking_for_course($user_id, $course_id);
        self::add_history($user_id, $slot_id, 'cancelled');
        if ($course_id > 0) self::maybe_send_booking_notifications($user_id, $slot_id, $course_id, 'cancelled');

        wp_send_json_success(true);
    }


    private static function email_placeholders($user, $slot_id, $course_id): array {
        $date = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $time = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $course_title = NFEMS_Tutor::course_title($course_id);
        $date_label = $date ? date_i18n('d/m/Y', strtotime($date)) : '';
        $manage_url = NFEMS_Settings::get_manage_booking_page_url();
        if ($manage_url) $manage_url = add_query_arg(['course_id' => $course_id], $manage_url);
        $booking_url = NFEMS_Settings::get_booking_page_url();
        if ($booking_url) $booking_url = add_query_arg(['course_id' => $course_id], $booking_url);
        return [
            '{candidate_name}' => $user ? (string) $user->display_name : '',
            '{candidate_email}' => $user ? (string) $user->user_email : '',
            '{course_title}' => (string) $course_title,
            '{session_date}' => (string) $date_label,
            '{session_time}' => (string) $time,
            '{manage_booking_url}' => (string) $manage_url,
            '{booking_page_url}' => (string) $booking_url,
            '{session_id}' => (string) $slot_id,
        ];
    }

    private static function maybe_send_booking_notifications($user_id, $slot_id, $course_id, $action): void {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $headers = NFEMS_Settings::get_mail_headers();
        $ph = self::email_placeholders($user, $slot_id, $course_id);

        if ($action === 'booked') {
            if (NFEMS_Settings::email_enabled('email_send_booking_confirmation', 1) && $user->user_email) {
                $subject = NFEMS_Settings::get_email_template('email_subject_booking_confirmation', 'Exam booking confirmed — {course_title}');
                $body = NFEMS_Settings::get_email_template('email_body_booking_confirmation', "Hello {candidate_name}

Your exam booking has been confirmed.
Course: {course_title}
Date: {session_date}
Time: {session_time}
Manage exam booking: {manage_booking_url}");
                wp_mail($user->user_email, NFEMS_Settings::render_email_template($subject, $ph), NFEMS_Settings::render_email_template($body, $ph), $headers);
            }
            if (NFEMS_Settings::email_enabled('email_send_admin_booking', 0)) {
                $to = NFEMS_Settings::get_admin_recipients();
                if ($to) {
                    $subject = NFEMS_Settings::get_email_template('email_subject_admin_booking', 'New exam booking — {course_title}');
                    $body = NFEMS_Settings::get_email_template('email_body_admin_booking', "A new booking has been created.

Candidate: {candidate_name} <{candidate_email}>
Course: {course_title}
Date: {session_date}
Time: {session_time}
Manage exam booking: {manage_booking_url}");
                    wp_mail($to, NFEMS_Settings::render_email_template($subject, $ph), NFEMS_Settings::render_email_template($body, $ph), $headers);
                }
            }
        }

        if ($action === 'cancelled') {
            if (NFEMS_Settings::email_enabled('email_send_booking_cancellation', 1) && $user->user_email) {
                $subject = NFEMS_Settings::get_email_template('email_subject_booking_cancellation', 'Exam booking cancelled — {course_title}');
                $body = NFEMS_Settings::get_email_template('email_body_booking_cancellation', "Hello {candidate_name}

Your exam booking has been cancelled.
Course: {course_title}
Date: {session_date}
Time: {session_time}");
                wp_mail($user->user_email, NFEMS_Settings::render_email_template($subject, $ph), NFEMS_Settings::render_email_template($body, $ph), $headers);
            }
            if (NFEMS_Settings::email_enabled('email_send_admin_cancellation', 0)) {
                $to = NFEMS_Settings::get_admin_recipients();
                if ($to) {
                    $subject = NFEMS_Settings::get_email_template('email_subject_admin_cancellation', 'Exam booking cancelled — {course_title}');
                    $body = NFEMS_Settings::get_email_template('email_body_admin_cancellation', "A booking has been cancelled.

Candidate: {candidate_name} <{candidate_email}>
Course: {course_title}
Date: {session_date}
Time: {session_time}");
                    wp_mail($to, NFEMS_Settings::render_email_template($subject, $ph), NFEMS_Settings::render_email_template($body, $ph), $headers);
                }
            }
        }
    }

    /* =========================
       Deletion cleanup
       ========================= */
    public static function on_before_delete_post(int $post_id): void {
        if (get_post_type($post_id) !== NFEMS_CPT_Sessioni_Esame::CPT) return;

        $course_id = (int) get_post_meta($post_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);
        $occ = get_post_meta($post_id, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        $occ = is_array($occ) ? $occ : [];

        if ($course_id > 0 && $occ) {
            foreach ($occ as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) continue;
                // Remove active exam booking for this course if it points to this session
                $b = self::get_active_booking_for_course($uid, $course_id);
                if (!empty($b['slot_id']) && (int)$b['slot_id'] === (int)$post_id) {
                    self::remove_active_booking_for_course($uid, $course_id);
                }
            }
        }
    }
}
