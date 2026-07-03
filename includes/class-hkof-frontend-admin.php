<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end admin-dashboard til booking-styring, via shortcode [hkof_booking_admin].
 *
 * Sættes ind på en almindelig (gerne adgangsbeskyttet/skjult) WordPress-side, så
 * bestyrelsesmedlemmer kan godkende/administrere bookinger uden at skulle ind i
 * selve wp-admin. Kræver at man er logget ind som WordPress-bruger med mindst
 * 'edit_posts'-rettighed (samme niveau som kræves for wp-admin-siden "Bookinger") -
 * ikke logget ind viser et login-felt, logget ind uden rettighed viser en pæn
 * "ingen adgang"-besked. Genbruger de samme handlings-endpoints (admin-post.php)
 * som wp-admin-siden, blot med et redirect_to der sender brugeren tilbage til
 * denne front-end-side i stedet for wp-admin bagefter.
 */
class HKOF_Frontend_Admin {

    public static function init() {
        add_shortcode('hkof_booking_admin', [__CLASS__, 'render']);
    }

    private static function current_full_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return esc_url_raw($scheme . '://' . $host . $uri);
    }

    /** Nuværende side-URL renset for vores egne midlertidige query-parametre (visning/kvitteringer) */
    private static function clean_url($extra_remove = []) {
        $url = self::current_full_url();
        $strip = array_merge(['hkof_view', 'hkof_edit', 'hkof_done', 'hkof_note_saved', 'error'], $extra_remove);
        return remove_query_arg($strip, $url);
    }

    public static function render($atts) {
        ob_start();
        ?>
        <div class="hkof-fe-admin">
        <style>
            .hkof-fe-admin{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937;max-width:960px}
            .hkof-fe-admin *{box-sizing:border-box}
            .hkof-fe-admin h2{font-size:1.4em;margin:0 0 .6em;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .hkof-fe-admin h3{font-size:1.1em;margin:0 0 .6em}
            .hkof-fe-admin table{width:100%;border-collapse:collapse;margin:0 0 1.2em;font-size:.94em}
            .hkof-fe-admin th,.hkof-fe-admin td{text-align:left;padding:9px 10px;border-bottom:1px solid #e5e7eb;vertical-align:top}
            .hkof-fe-admin th{background:#f9fafb;font-weight:600}
            .hkof-fe-admin .hkof-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.78em;font-weight:600;color:#fff;white-space:nowrap}
            .hkof-fe-admin .hkof-tabs{list-style:none;display:flex;flex-wrap:wrap;gap:6px;padding:0;margin:0 0 18px}
            .hkof-fe-admin .hkof-tabs a{display:inline-block;padding:6px 12px;border-radius:6px;background:#f3f4f6;color:#374151;text-decoration:none;font-size:.88em}
            .hkof-fe-admin .hkof-tabs a.current{background:#1d4ed8;color:#fff}
            .hkof-fe-admin .hkof-btn{display:inline-block;padding:9px 15px;border-radius:6px;background:#1d4ed8;color:#fff!important;text-decoration:none;font-size:.9em;font-weight:600;border:none;cursor:pointer;margin:0 6px 8px 0}
            .hkof-fe-admin .hkof-btn.secondary{background:#f3f4f6;color:#374151!important}
            .hkof-fe-admin .hkof-btn.danger{background:#fef2f2;color:#b91c1c!important}
            .hkof-fe-admin .hkof-box{border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;margin-bottom:18px;background:#fff}
            .hkof-fe-admin .hkof-notice{padding:10px 14px;border-radius:8px;margin-bottom:16px;background:#dcfce7;border:1px solid #86efac;color:#14532d}
            .hkof-fe-admin textarea,.hkof-fe-admin input[type=text],.hkof-fe-admin input[type=email],.hkof-fe-admin input[type=password],.hkof-fe-admin input[type=date],.hkof-fe-admin input[type=number],.hkof-fe-admin select{width:100%;padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.92em;font-family:inherit;background:#fff}
            .hkof-fe-admin .hkof-login-row{margin-bottom:12px}
            .hkof-fe-admin .hkof-hint{font-size:.85em;color:#6b7280;margin-top:-4px}
            .hkof-fe-admin .hkof-edit-table th{width:220px}
            .hkof-fe-admin .hkof-edit-table td{max-width:360px}
            .hkof-fe-admin .hkof-inline-btn{display:inline-block;padding:6px 12px;border-radius:6px;background:#f3f4f6;color:#374151;text-decoration:none;font-size:.85em;font-weight:600;border:1px solid #d1d5db;cursor:pointer;margin-left:8px}
            .hkof-fe-admin .hkof-error-box{padding:10px 14px;border-radius:8px;margin-bottom:16px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        </style>
        <?php

        if (!is_user_logged_in()) {
            self::render_login_prompt();
        } elseif (!current_user_can('edit_posts')) {
            echo '<div class="hkof-box"><p>🔒 Din bruger har desværre ikke adgang til at administrere bookinger. Kontakt en administrator hvis du mener dette er en fejl.</p></div>';
        } elseif (isset($_GET['hkof_view']) && isset($_GET['hkof_edit'])) {
            self::render_edit((int) $_GET['hkof_view']);
        } elseif (isset($_GET['hkof_view'])) {
            self::render_detail((int) $_GET['hkof_view']);
        } else {
            self::render_list();
        }

        echo '</div>';
        return ob_get_clean();
    }

    private static function render_login_prompt() {
        ?>
        <div class="hkof-box">
            <h2>🔒 Log ind for at administrere bookinger</h2>
            <p>Denne side er kun for foreningens administratorer. Log ind med din WordPress-bruger herunder.</p>
            <?php
            wp_login_form([
                'redirect'       => self::current_full_url(),
                'label_username' => 'Brugernavn eller e-mail',
                'label_password' => 'Adgangskode',
                'label_log_in'   => 'Log ind',
            ]);
            ?>
        </div>
        <?php
    }

    private static function render_list() {
        $filter = isset($_GET['hkof_status']) ? sanitize_text_field($_GET['hkof_status']) : 'all';
        $bookings = HKOF_DB::all($filter, 200);
        $counts = HKOF_DB::counts();
        $total = array_sum($counts);
        $base = self::clean_url(['hkof_status']);
        ?>
        <h2>📋 Bookinger</h2>

        <?php if (isset($_GET['hkof_done'])): ?>
            <div class="hkof-notice">✅ Handlingen blev udført.</div>
        <?php endif; ?>

        <ul class="hkof-tabs">
            <li><a href="<?php echo esc_url(add_query_arg('hkof_status', 'all', $base)); ?>" class="<?php echo $filter === 'all' ? 'current' : ''; ?>">Alle (<?php echo (int) $total; ?>)</a></li>
            <?php foreach (HKOF_Admin::status_labels() as $key => $l): $c = $counts[$key] ?? 0; ?>
                <li><a href="<?php echo esc_url(add_query_arg('hkof_status', $key, $base)); ?>" class="<?php echo $filter === $key ? 'current' : ''; ?>"><?php echo esc_html($l[0]); ?> (<?php echo (int) $c; ?>)</a></li>
            <?php endforeach; ?>
        </ul>

        <table>
            <thead><tr><th>Lejeaftale nr.</th><th>Navn</th><th>Periode</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$bookings): ?>
                <tr><td colspan="5">Ingen bookinger fundet.</td></tr>
            <?php else: foreach ($bookings as $b):
                $labels = HKOF_Admin::status_labels();
                $l = $labels[$b->status] ?? [$b->status, '#6b7280'];
                $view_url = add_query_arg('hkof_view', $b->id, $base);
            ?>
                <tr>
                    <td><?php echo esc_html($b->booking_ref ?: '—'); ?></td>
                    <td><?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></td>
                    <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($b->check_in_date)) . ' – ' . date_i18n('d.m.Y', strtotime($b->check_out_date))); ?></td>
                    <td><span class="hkof-badge" style="background:<?php echo esc_attr($l[1]); ?>"><?php echo esc_html($l[0]); ?></span></td>
                    <td><a class="hkof-btn secondary" href="<?php echo esc_url($view_url); ?>">Åbn →</a> <a class="hkof-inline-btn" href="<?php echo esc_url(add_query_arg('hkof_edit', '1', $view_url)); ?>" title="Rediger direkte">✏️</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_detail($id) {
        $b = HKOF_DB::get($id);
        $list_url = self::clean_url();
        if (!$b) {
            echo '<p>Booking ikke fundet. <a href="' . esc_url($list_url) . '">← Tilbage</a></p>';
            return;
        }
        $labels = HKOF_Admin::status_labels();
        $l = $labels[$b->status] ?? [$b->status, '#6b7280'];
        $self_url = add_query_arg('hkof_view', $id, $list_url); // redirect-mål efter handlinger: bliv på denne bookings side
        ?>
        <p><a href="<?php echo esc_url($list_url); ?>">← Tilbage til listen</a></p>
        <h2><?php echo esc_html($b->first_name . ' ' . $b->last_name); ?> <span class="hkof-badge" style="background:<?php echo esc_attr($l[1]); ?>"><?php echo esc_html($l[0]); ?></span></h2>

        <?php if (isset($_GET['hkof_done'])): ?><div class="hkof-notice">✅ Handlingen blev udført.</div><?php endif; ?>
        <?php if (isset($_GET['hkof_note_saved'])): ?><div class="hkof-notice">📝 Noten er gemt.</div><?php endif; ?>

        <div class="hkof-box">
            <table>
                <tr><th>Lejeaftale nr.</th><td><?php echo esc_html($b->booking_ref ?: '(tildeles ved godkendelse)'); ?></td></tr>
                <tr><th>Adresse</th><td><?php echo esc_html($b->address . ', ' . $b->postal_city); ?></td></tr>
                <tr><th>Email</th><td><?php echo esc_html($b->email); ?></td></tr>
                <tr><th>Telefon</th><td><?php echo esc_html($b->phone); ?></td></tr>
                <tr><th>Formål</th><td><?php echo esc_html($b->purpose); ?></td></tr>
                <tr><th>Periode</th><td><?php echo esc_html(date_i18n('d.m.Y', strtotime($b->check_in_date)) . ' kl. 12:00 – ' . date_i18n('d.m.Y', strtotime($b->check_out_date)) . ' kl. 12:00'); ?></td></tr>
                <tr><th>Lejeafgift i alt</th><td><strong><?php echo number_format((float) $b->rental_amount + (float) $b->extra_days_fee + (float) $b->environment_fee, 2, ',', '.'); ?> kr.</strong></td></tr>
                <tr><th>Depositum</th><td><?php echo number_format((float) $b->deposit_amount, 2, ',', '.'); ?> kr.</td></tr>
            </table>

            <div class="hkof-calendar" style="max-width:420px;margin-top:16px">
                <div class="hkof-legend">
                    <span><span class="hkof-dot hkof-free"></span>Ledig</span>
                    <span><span class="hkof-dot hkof-pending"></span>Afventer</span>
                    <span><span class="hkof-dot hkof-booked"></span>Reserveret</span>
                    <span>🔵 Denne booking</span>
                </div>
                <div class="hkof-cal-nav">
                    <button type="button" id="hkof-fe-cal-prev">&larr;</button>
                    <span id="hkof-fe-cal-title"></span>
                    <button type="button" id="hkof-fe-cal-next">&rarr;</button>
                </div>
                <div class="hkof-cal-grid" id="hkof-fe-cal-grid"></div>
            </div>
            <script>
            (function () {
                if (window.HKOF_AdminCalendar && window.HKOF_BOOKING) {
                    window.HKOF_AdminCalendar({
                        gridId: 'hkof-fe-cal-grid',
                        titleId: 'hkof-fe-cal-title',
                        prevId: 'hkof-fe-cal-prev',
                        nextId: 'hkof-fe-cal-next',
                        editable: false,
                        excludeId: <?php echo (int) $id; ?>,
                        checkIn: '<?php echo esc_js($b->check_in_date); ?>',
                        checkOut: '<?php echo esc_js($b->check_out_date); ?>',
                        ajaxUrl: HKOF_BOOKING.ajaxUrl,
                        nonce: HKOF_BOOKING.nonce
                    });
                }
            })();
            </script>

            <?php if (!empty($b->gdrive_contract_error)): ?>
                <p style="color:#b91c1c;font-size:.88em">⚠️ Kontrakten kunne ikke gemmes i Google Drive (<?php echo esc_html($b->gdrive_contract_error); ?>). Mailen til lejeren er sendt som normalt.</p>
            <?php endif; ?>
            <?php if ($b->status === 'rejected'): ?>
                <p style="font-size:.9em">❌ Afvist<?php if (!empty($b->rejected_reason)): ?> - begrundelse (sendt til lejer): <em><?php echo esc_html($b->rejected_reason); ?></em><?php else: ?> (ingen begrundelse angivet)<?php endif; ?></p>
            <?php endif; ?>
        </div>

        <?php HKOF_Admin::render_reject_reason_script(); ?>
        <div class="hkof-box">
            <h3>Handlinger</h3>
            <?php if ($b->status === 'pending'): ?>
                <a class="hkof-btn" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'approve', $self_url)); ?>" onclick="return confirm('Godkend booking og send opkrævning af depositum til lejer?')">✅ Godkend &amp; send opkrævning</a>
                <a class="hkof-btn danger" href="#" onclick="return hkofRejectWithReason(this, '<?php echo esc_js(HKOF_Admin::action_url($id, 'reject', $self_url)); ?>')">❌ Afvis</a>
            <?php elseif ($b->status === 'approved'): ?>
                <a class="hkof-btn" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'mark_deposit_paid', $self_url)); ?>" onclick="return confirm('Bekræft at depositum er modtaget – dette sender kontrakten til lejer.')">💰 Registrér depositum modtaget &amp; send kontrakt</a>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'resend_deposit_invoice', $self_url)); ?>">📧 Gensend opkrævning</a>
            <?php elseif ($b->status === 'deposit_paid'): ?>
                <a class="hkof-btn" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'send_invoice_now', $self_url)); ?>" onclick="return confirm('Send faktura nu (før den automatiske dato)?')">🧾 Send faktura nu</a>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'resend_contract', $self_url)); ?>">📧 Gensend kontrakt</a>
            <?php elseif ($b->status === 'invoice_sent'): ?>
                <a class="hkof-btn" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'mark_invoice_paid', $self_url)); ?>" onclick="return confirm('Bekræft at fakturaen er betalt? Lejeren får en bekræftelsesmail.')">✅ Registrér faktura betalt</a>
            <?php elseif ($b->status === 'paid'): ?>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'mark_completed', $self_url)); ?>">🏁 Markér som afsluttet</a>
            <?php endif; ?>
            <?php if (!in_array($b->status, ['rejected', 'cancelled', 'completed'], true)): ?>
                <a class="hkof-btn danger" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'cancel', $self_url)); ?>" onclick="return confirm('Annullér denne booking helt?')">🚫 Annullér booking</a>
            <?php endif; ?>
            <a class="hkof-btn secondary" href="<?php echo esc_url(add_query_arg('hkof_edit', '1', $self_url)); ?>">✏️ Rediger booking (datoer, priser, oplysninger, status)</a>
        </div>

        <div class="hkof-box">
            <h3>📧 Send mails manuelt</h3>
            <p class="hkof-hint">Uafhængigt af status ovenfor - brug til at (gen)sende en bestemt mail, fx efter I selv har rettet status i WordPress-admin.</p>
            <?php if (!$b->booking_ref): ?>
                <p style="color:#b91c1c;font-size:.9em">Bookingen mangler et lejeaftalenummer. Ret status i WordPress-admin (forskellig fra "Afventer godkendelse") for at få tildelt et nummer, før mails kan sendes.</p>
            <?php else: ?>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'resend_deposit_invoice', $self_url)); ?>" onclick="return confirm('Send opkrævning af depositum til lejer nu?')">📧 Send opkrævning af depositum</a>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'resend_contract', $self_url)); ?>" onclick="return confirm('Send kontrakt (lejeaftale-PDF) til lejer nu?')">📄 Send kontrakt</a>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'send_invoice_now', $self_url)); ?>" onclick="return confirm('Send faktura til lejer nu? Dette sætter samtidig status til Faktura sendt.')">🧾 Send faktura</a>
                <a class="hkof-btn secondary" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'resend_final_confirmation', $self_url)); ?>" onclick="return confirm('Send bekræftelse på fuld betaling til lejer nu?')">✅ Send betalingsbekræftelse</a>
            <?php endif; ?>
        </div>

        <div class="hkof-box" id="hkof-note">
            <h3>📝 Interne noter</h3>
            <p class="hkof-hint">Kun synligt internt for jer – vises aldrig for lejer.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hkof_booking_save_note">
                <input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($self_url); ?>">
                <?php wp_nonce_field('hkof_booking_save_note_' . $b->id); ?>
                <textarea name="admin_notes" rows="4" placeholder="Skriv en note – fx aftaler pr. telefon, ting I skal huske..."><?php echo esc_textarea($b->admin_notes); ?></textarea>
                <p><button type="submit" class="hkof-btn">💾 Gem note</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Fuld redigeringsformular på front-end - samme felter og logik som wp-admins
     * "Rediger booking" (kontaktoplysninger, periode/pris m. kalender-forhåndsvisning,
     * status-override, interne noter), blot med front-end-styling i stedet for WP
     * admin-klasser. Genbruger HKOF_Admin::handle_save() (samme admin-post.php action)
     * via de to skjulte felter 'redirect_to' (edit-siden, bruges ved fejl) og
     * 'redirect_to_view' (booking-detaljesiden, bruges ved succes).
     */
    private static function render_edit($id) {
        $b = HKOF_DB::get($id);
        $list_url = self::clean_url();
        if (!$b) {
            echo '<p>Booking ikke fundet. <a href="' . esc_url($list_url) . '">← Tilbage</a></p>';
            return;
        }
        $s = HKOF_Settings::all();
        $view_url = add_query_arg('hkof_view', $id, $list_url);
        $edit_self_url = add_query_arg(['hkof_view' => $id, 'hkof_edit' => 1], $list_url);
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        ?>
        <p><a href="<?php echo esc_url($view_url); ?>">← Tilbage til booking</a></p>
        <h2>✏️ Rediger booking – <?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></h2>

        <?php if ($error): ?>
            <div class="hkof-error-box">⚠️ <?php echo esc_html($error); ?></div>
        <?php endif; ?>

        <p class="hkof-hint">At redigere en booking sender ikke automatisk besked til lejer. Brug knapperne "Send mails manuelt" på bookingens side bagefter, hvis lejer skal have besked om ændringen.</p>

        <div class="hkof-box">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hkof_booking_save">
            <input type="hidden" name="booking_id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($edit_self_url); ?>">
            <input type="hidden" name="redirect_to_view" value="<?php echo esc_attr($view_url); ?>">
            <?php wp_nonce_field('hkof_booking_save_' . $id); ?>

            <h3>Kontaktoplysninger</h3>
            <table class="hkof-edit-table">
                <tr><th>Fornavn</th><td><input type="text" name="first_name" value="<?php echo esc_attr($b->first_name); ?>" required></td></tr>
                <tr><th>Efternavn</th><td><input type="text" name="last_name" value="<?php echo esc_attr($b->last_name); ?>" required></td></tr>
                <tr><th>Adresse</th><td><input type="text" name="address" value="<?php echo esc_attr($b->address); ?>" required></td></tr>
                <tr><th>Postnr / By</th><td><input type="text" name="postal_city" value="<?php echo esc_attr($b->postal_city); ?>" required></td></tr>
                <tr><th>Email</th><td><input type="email" name="email" value="<?php echo esc_attr($b->email); ?>" required></td></tr>
                <tr><th>Telefon</th><td><input type="text" name="phone" value="<?php echo esc_attr($b->phone); ?>" required></td></tr>
                <tr><th>Formål</th><td><input type="text" name="purpose" value="<?php echo esc_attr($b->purpose); ?>" required></td></tr>
            </table>

            <h3>Periode &amp; pris</h3>
            <table class="hkof-edit-table">
                <tr>
                    <th>Type</th>
                    <td>
                        <select name="price_type" id="hkof-fe-edit-type">
                            <option value="selskab" <?php selected($b->price_type, 'selskab'); ?>>Selskab (standard <?php echo esc_html($s['default_days']); ?> dage)</option>
                            <option value="moede" <?php selected($b->price_type, 'moede'); ?>>Møde (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                            <option value="begravelse" <?php selected($b->price_type, 'begravelse'); ?>>Begravelse (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                        </select>
                    </td>
                </tr>
                <tr><th>Ankomstdato</th><td><input type="date" name="check_in_date" id="hkof-fe-edit-checkin" value="<?php echo esc_attr($b->check_in_date); ?>" required></td></tr>
                <tr>
                    <th>Kalender-overblik</th>
                    <td>
                        <div class="hkof-calendar" style="max-width:400px">
                            <div class="hkof-legend">
                                <span><span class="hkof-dot hkof-free"></span>Ledig</span>
                                <span><span class="hkof-dot hkof-pending"></span>Afventer</span>
                                <span><span class="hkof-dot hkof-booked"></span>Reserveret</span>
                                <span>🔵 Denne booking</span>
                            </div>
                            <div class="hkof-cal-nav">
                                <button type="button" id="hkof-fe-edit-cal-prev">&larr;</button>
                                <span id="hkof-fe-edit-cal-title"></span>
                                <button type="button" id="hkof-fe-edit-cal-next">&rarr;</button>
                            </div>
                            <div class="hkof-cal-grid" id="hkof-fe-edit-cal-grid"></div>
                        </div>
                        <p id="hkof-fe-edit-cal-warning" style="display:none;color:#b91c1c;font-weight:600;margin-top:8px">⚠️ Den valgte periode overlapper med en anden booking - dobbelttjek datoerne.</p>
                        <p class="hkof-hint">Klik en ledig dag i kalenderen for at sætte ankomstdato, eller ret datoerne manuelt nedenfor.</p>
                    </td>
                </tr>
                <tr>
                    <th>Ekstra dage</th>
                    <td>
                        <select name="extra_days" id="hkof-fe-edit-extra">
                            <?php for ($i = 0; $i <= 30; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected((int) $b->extra_days, $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="hkof-hint"><?php echo esc_html(number_format((float) $s['price_extra_day'], 0, ',', '.')); ?> kr. pr. ekstra dag</span>
                    </td>
                </tr>
                <tr>
                    <th>Afgangsdato</th>
                    <td>
                        <input type="date" name="check_out_date" id="hkof-fe-edit-checkout" value="<?php echo esc_attr($b->check_out_date); ?>" required>
                        <button type="button" class="hkof-btn secondary" id="hkof-fe-edit-recalc-dates" style="margin-top:6px">↻ Beregn ud fra type + ekstra dage</button>
                    </td>
                </tr>
                <tr><th>Lejeafgift (kr.)</th><td><input type="number" step="0.01" name="rental_amount" id="hkof-fe-edit-rental" value="<?php echo esc_attr($b->rental_amount); ?>"></td></tr>
                <tr><th>Ekstra dage – beløb (kr.)</th><td><input type="number" step="0.01" name="extra_days_fee" id="hkof-fe-edit-extrafee" value="<?php echo esc_attr($b->extra_days_fee); ?>"></td></tr>
                <tr><th>Miljøafgift (kr.)</th><td><input type="number" step="0.01" name="environment_fee" value="<?php echo esc_attr($b->environment_fee); ?>"></td></tr>
                <tr><th>Depositum (kr.)</th><td><input type="number" step="0.01" name="deposit_amount" value="<?php echo esc_attr($b->deposit_amount); ?>"></td></tr>
            </table>
            <p><button type="button" class="hkof-btn secondary" id="hkof-fe-edit-recalc-price">↻ Genberegn priser til standardpriser for type + ekstra dage</button></p>

            <h3>Status</h3>
            <table class="hkof-edit-table">
                <tr>
                    <th>Status (manuel override)</th>
                    <td>
                        <select name="status">
                            <?php foreach (HKOF_Admin::status_labels() as $key => $l): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($b->status, $key); ?>><?php echo esc_html($l[0]); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hkof-hint">Skifter KUN status i systemet - sender ingen mails og rører ikke datoer/beløb. Har bookingen intet lejeaftalenummer endnu, tildeles ét automatisk når du gemmer en status der ikke er "Afventer godkendelse". Brug "Send mails manuelt" på bookingens side bagefter, hvis lejer/foreningen skal have besked.</p>
                    </td>
                </tr>
            </table>

            <h3>Interne noter</h3>
            <textarea name="admin_notes" rows="4" placeholder="Kun synligt for jer internt – vises ikke for lejer"><?php echo esc_textarea($b->admin_notes); ?></textarea>

            <p style="margin-top:18px">
                <button type="submit" class="hkof-btn">💾 Gem ændringer</button>
                <a href="<?php echo esc_url($view_url); ?>" class="hkof-btn secondary">Annullér</a>
            </p>
        </form>
        </div>
        <script>
        (function(){
            var baseDaysSelskab = <?php echo (int) $s['default_days']; ?>;
            var baseDaysMoede = <?php echo (int) $s['default_days_moede']; ?>;
            var priceSelskab = <?php echo (float) $s['price_selskab']; ?>;
            var priceMoede = <?php echo (float) $s['price_moede']; ?>;
            var extraPrice = <?php echo (float) $s['price_extra_day']; ?>;

            function currentBaseDays() {
                var t = document.getElementById('hkof-fe-edit-type').value;
                return (t === 'moede' || t === 'begravelse') ? baseDaysMoede : baseDaysSelskab;
            }
            function currentBasePrice() {
                var t = document.getElementById('hkof-fe-edit-type').value;
                return (t === 'moede' || t === 'begravelse') ? priceMoede : priceSelskab;
            }
            function addDaysISO(iso, n) {
                var d = new Date(iso + 'T12:00:00');
                d.setDate(d.getDate() + n);
                return d.toISOString().slice(0, 10);
            }

            document.getElementById('hkof-fe-edit-recalc-dates').addEventListener('click', function () {
                var checkIn = document.getElementById('hkof-fe-edit-checkin').value;
                var extra = parseInt(document.getElementById('hkof-fe-edit-extra').value, 10) || 0;
                if (!checkIn) return;
                var totalDays = currentBaseDays() + extra;
                document.getElementById('hkof-fe-edit-checkout').value = addDaysISO(checkIn, totalDays);
            });
            document.getElementById('hkof-fe-edit-recalc-price').addEventListener('click', function () {
                var extra = parseInt(document.getElementById('hkof-fe-edit-extra').value, 10) || 0;
                document.getElementById('hkof-fe-edit-rental').value = currentBasePrice();
                document.getElementById('hkof-fe-edit-extrafee').value = extra * extraPrice;
            });

            if (window.HKOF_AdminCalendar && window.HKOF_BOOKING) {
                window.HKOF_AdminCalendar({
                    gridId: 'hkof-fe-edit-cal-grid',
                    titleId: 'hkof-fe-edit-cal-title',
                    prevId: 'hkof-fe-edit-cal-prev',
                    nextId: 'hkof-fe-edit-cal-next',
                    warningId: 'hkof-fe-edit-cal-warning',
                    checkInInputId: 'hkof-fe-edit-checkin',
                    checkOutInputId: 'hkof-fe-edit-checkout',
                    editable: true,
                    excludeId: <?php echo (int) $id; ?>,
                    ajaxUrl: HKOF_BOOKING.ajaxUrl,
                    nonce: HKOF_BOOKING.nonce
                });
            }
        })();
        </script>
        <?php
    }
}
