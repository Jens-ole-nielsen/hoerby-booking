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
        $strip = array_merge(['hkof_view', 'hkof_done', 'hkof_note_saved'], $extra_remove);
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
            .hkof-fe-admin textarea,.hkof-fe-admin input[type=text],.hkof-fe-admin input[type=email],.hkof-fe-admin input[type=password]{width:100%;padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.92em}
            .hkof-fe-admin .hkof-login-row{margin-bottom:12px}
            .hkof-fe-admin .hkof-hint{font-size:.85em;color:#6b7280;margin-top:-4px}
        </style>
        <?php

        if (!is_user_logged_in()) {
            self::render_login_prompt();
        } elseif (!current_user_can('edit_posts')) {
            echo '<div class="hkof-box"><p>🔒 Din bruger har desværre ikke adgang til at administrere bookinger. Kontakt en administrator hvis du mener dette er en fejl.</p></div>';
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
                    <td><a class="hkof-btn secondary" href="<?php echo esc_url($view_url); ?>">Åbn →</a></td>
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
            <?php if (!empty($b->gdrive_contract_error)): ?>
                <p style="color:#b91c1c;font-size:.88em">⚠️ Kontrakten kunne ikke gemmes i Google Drive (<?php echo esc_html($b->gdrive_contract_error); ?>). Mailen til lejeren er sendt som normalt.</p>
            <?php endif; ?>
        </div>

        <div class="hkof-box">
            <h3>Handlinger</h3>
            <?php if ($b->status === 'pending'): ?>
                <a class="hkof-btn" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'approve', $self_url)); ?>" onclick="return confirm('Godkend booking og send opkrævning af depositum til lejer?')">✅ Godkend &amp; send opkrævning</a>
                <a class="hkof-btn danger" href="<?php echo esc_url(HKOF_Admin::action_url($id, 'reject', $self_url)); ?>" onclick="return confirm('Afvis denne booking?')">❌ Afvis</a>
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
            <p class="hkof-hint"><a href="<?php echo esc_url(admin_url('admin.php?page=hkof-bookings&action=edit&id=' . $id)); ?>">Skal du rette datoer, priser, lejerens oplysninger eller status manuelt? Det gøres i WordPress-admin →</a></p>
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
}
