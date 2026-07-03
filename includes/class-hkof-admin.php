<?php
if (!defined('ABSPATH')) exit;

class HKOF_Admin {

    private static $status_labels = [
        'pending'      => ['Afventer godkendelse', '#b45309'],
        'approved'     => ['Godkendt – afventer depositum', '#1d4ed8'],
        'deposit_paid' => ['Depositum modtaget', '#0f766e'],
        'invoice_sent' => ['Faktura sendt', '#7c3aed'],
        'paid'         => ['Betalt', '#15803d'],
        'completed'    => ['Afsluttet', '#4b5563'],
        'rejected'     => ['Afvist', '#b91c1c'],
        'cancelled'    => ['Annulleret', '#6b7280'],
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_hkof_booking_action', [__CLASS__, 'handle_action']);
        add_action('admin_post_hkof_booking_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_hkof_booking_save_note', [__CLASS__, 'handle_save_note']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if (strpos($hook, 'hkof-') !== false) wp_enqueue_media();
        });
        // Vis en tydelig påmindelse på alle Lokale Booking-sider når mail-afsendelse er sat på pause
        add_action('admin_notices', function () {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || strpos($screen->id, 'hkof-') === false) return;
            if (HKOF_Settings::mail_paused()) {
                echo '<div class="notice notice-warning"><p>🔕 <strong>Mail-afsendelse er sat på pause</strong> for Lokale Booking – gæster og foreningen får IKKE besked om ændringer lige nu. <a href="' . esc_url(admin_url('admin.php?page=hkof-mails')) . '">Slå det til igen her</a>.</p></div>';
            }
        });
    }

    public static function menu() {
        add_menu_page('Lokale Booking', 'Lokale Booking', 'edit_posts', 'hkof-bookings', [__CLASS__, 'render_list'], 'dashicons-calendar-alt', 26);
        add_submenu_page('hkof-bookings', 'Bookinger', 'Bookinger', 'edit_posts', 'hkof-bookings', [__CLASS__, 'render_list']);
        add_submenu_page('hkof-bookings', 'Indstillinger', 'Indstillinger', 'manage_options', 'hkof-settings', ['HKOF_Settings', 'render_settings_page']);
        add_submenu_page('hkof-bookings', 'E-mails', '📧 E-mails', 'manage_options', 'hkof-mails', ['HKOF_Settings', 'render_mails_page']);
        add_submenu_page('hkof-bookings', 'Google Drive', '📁 Google Drive', 'manage_options', 'hkof-gdrive', ['HKOF_GDrive', 'render_settings_page']);
    }

    private static function label($status) {
        $l = self::$status_labels[$status] ?? [$status, '#6b7280'];
        return sprintf('<span style="background:%s;color:#fff;padding:3px 9px;border-radius:12px;font-size:12px;font-weight:600">%s</span>', $l[1], esc_html($l[0]));
    }

    public static function render_list() {
        if (!current_user_can('edit_posts')) return;

        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            self::render_detail((int) $_GET['id']);
            return;
        }
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            self::render_edit((int) $_GET['id']);
            return;
        }

        // Hurtig mail-pause-kontakt (samme funktion som på E-mails-siden), så man
        // kan slå mails fra lige her hvor man godkender/afviser bookinger
        if (isset($_POST['hkof_mail_pause_nonce']) && wp_verify_nonce($_POST['hkof_mail_pause_nonce'], 'hkof_toggle_mail_pause')) {
            HKOF_Settings::set_mail_paused(!HKOF_Settings::mail_paused());
        }
        $mail_paused = HKOF_Settings::mail_paused();

        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $bookings = HKOF_DB::all($filter, 200);
        $counts = HKOF_DB::counts();
        $total = array_sum($counts);
        ?>
        <div class="wrap hkof-wrap">
            <h1>Lokale Booking – Bookinger</h1>

            <div style="padding:10px 16px;border-radius:8px;margin-bottom:16px;border:1px solid <?php echo $mail_paused ? '#fca5a5' : '#e2e2e2'; ?>;background:<?php echo $mail_paused ? '#fee2e2' : '#f6f7f7'; ?>">
                <form method="post" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0">
                    <?php wp_nonce_field('hkof_toggle_mail_pause', 'hkof_mail_pause_nonce'); ?>
                    <?php if ($mail_paused): ?>
                        <strong>🔕 Mails er sat på pause</strong> — ingen mails sendes til gæster/foreningen.
                        <button type="submit" class="button button-primary">🔔 Aktiver mails igen</button>
                    <?php else: ?>
                        <span>🔔 Mail-afsendelse er aktiv.</span>
                        <button type="submit" class="button">🔕 Sæt mails på pause midlertidigt</button>
                    <?php endif; ?>
                    <a href="?page=hkof-mails" style="margin-left:auto">Rediger mail-indhold →</a>
                </form>
            </div>

            <ul class="subsubsub">
                <li><a href="?page=hkof-bookings&status=all" class="<?php echo $filter==='all'?'current':''; ?>">Alle <span class="count">(<?php echo $total; ?>)</span></a> |</li>
                <?php foreach (self::$status_labels as $key => $l): $c = $counts[$key] ?? 0; ?>
                <li><a href="?page=hkof-bookings&status=<?php echo $key; ?>" class="<?php echo $filter===$key?'current':''; ?>"><?php echo esc_html($l[0]); ?> <span class="count">(<?php echo $c; ?>)</span></a> |</li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Lejeaftale nr.</th><th>Navn</th><th>Periode</th><th>Formål</th><th>Status</th><th>Note</th><th>Oprettet</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$bookings): ?>
                    <tr><td colspan="8">Ingen bookinger fundet.</td></tr>
                <?php else: foreach ($bookings as $b): ?>
                    <tr>
                        <td><?php echo esc_html($b->booking_ref ?: '—'); ?></td>
                        <td><?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></td>
                        <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($b->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($b->check_out_date))); ?></td>
                        <td><?php echo esc_html($b->purpose); ?></td>
                        <td><?php echo self::label($b->status); ?></td>
                        <td>
                            <?php if (!empty($b->admin_notes)):
                                $preview = self::truncate($b->admin_notes, 40);
                            ?>
                                <a href="?page=hkof-bookings&action=view&id=<?php echo $b->id; ?>#hkof-note" title="<?php echo esc_attr($b->admin_notes); ?>">📝 <?php echo esc_html($preview); ?></a>
                            <?php else: ?>
                                <a href="?page=hkof-bookings&action=view&id=<?php echo $b->id; ?>#hkof-note" style="color:#a7aaad">+ tilføj note</a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->created_at))); ?></td>
                        <td>
                            <a class="button button-small" href="?page=hkof-bookings&action=view&id=<?php echo $b->id; ?>">Åbn</a>
                            <a class="button button-small" href="?page=hkof-bookings&action=edit&id=<?php echo $b->id; ?>">✏️ Rediger</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Trunkerer tekst sikkert til UTF-8 (æøå) uden at kræve mbstring-extension på serveren */
    private static function truncate($text, $length) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
        }
        // Fallback uden mbstring: brug WP's egen (mbstring-uafhængige) hjælpefunktion
        return strlen($text) > $length ? wp_html_excerpt($text, $length, '…') : $text;
    }

    private static function action_url($id, $action_name) {
        return wp_nonce_url(admin_url('admin-post.php?action=hkof_booking_action&booking_id=' . $id . '&do=' . $action_name), 'hkof_booking_action_' . $id);
    }

    private static function render_detail($id) {
        $b = HKOF_DB::get($id);
        if (!$b) { echo '<div class="wrap"><p>Booking ikke fundet.</p></div>'; return; }
        $s = HKOF_Settings::all();
        ?>
        <div class="wrap hkof-wrap">
            <h1>Booking – <?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></h1>
            <p><a href="?page=hkof-bookings">&larr; Tilbage til listen</a></p>

            <?php if (isset($_GET['done'])): ?>
                <div class="notice notice-success"><p>Handling udført.</p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap">
                <div style="flex:1;min-width:320px">
                    <h2>Detaljer</h2>
                    <table class="form-table">
                        <tr><th>Status</th><td><?php echo self::label($b->status); ?></td></tr>
                        <tr><th>Lejeaftale nr.</th><td><?php echo esc_html($b->booking_ref ?: '(tildeles ved godkendelse)'); ?></td></tr>
                        <tr><th>Navn</th><td><?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></td></tr>
                        <tr><th>Adresse</th><td><?php echo esc_html($b->address . ', ' . $b->postal_city); ?></td></tr>
                        <tr><th>Email</th><td><?php echo esc_html($b->email); ?></td></tr>
                        <tr><th>Telefon</th><td><?php echo esc_html($b->phone); ?></td></tr>
                        <tr><th>Formål</th><td><?php echo esc_html($b->purpose); ?></td></tr>
                        <tr><th>Periode</th><td><?php echo esc_html(date_i18n('d.m.Y', strtotime($b->check_in_date)) . ' 12:00 - ' . date_i18n('d.m.Y', strtotime($b->check_out_date)) . ' 12:00'); ?></td></tr>
                        <tr><th>Type</th><td><?php echo esc_html($b->price_type); ?></td></tr>
                        <tr><th>Lejeafgift</th><td><?php echo number_format((float) $b->rental_amount, 2, ',', '.'); ?> kr.</td></tr>
                        <?php if ((int) $b->extra_days > 0): ?>
                        <tr><th>Ekstra dage</th><td><?php echo (int) $b->extra_days; ?> stk. &times; <?php echo number_format(((float) $b->extra_days_fee / max(1,(int) $b->extra_days)), 2, ',', '.'); ?> kr. = <?php echo number_format((float) $b->extra_days_fee, 2, ',', '.'); ?> kr.</td></tr>
                        <?php endif; ?>
                        <tr><th>Miljøafgift</th><td><?php echo number_format((float) $b->environment_fee, 2, ',', '.'); ?> kr.</td></tr>
                        <tr><th>Lejeafgift i alt</th><td><strong><?php echo number_format((float) $b->rental_amount + (float) $b->extra_days_fee + (float) $b->environment_fee, 2, ',', '.'); ?> kr.</strong></td></tr>
                        <tr><th>Depositum</th><td><?php echo number_format((float) $b->deposit_amount, 2, ',', '.'); ?> kr.</td></tr>
                    </table>

                    <?php if ($b->deposit_invoice_sent_at): ?><p>📨 Opkrævning af depositum sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->deposit_invoice_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->contract_sent_at): ?><p>📄 Kontrakt sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->contract_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->deposit_paid_at): ?><p>💰 Depositum modtaget: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->deposit_paid_at))); ?></p><?php endif; ?>
                    <?php if ($b->invoice_sent_at): ?><p>🧾 Faktura sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->invoice_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->invoice_paid_at): ?><p>✅ Faktura betalt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->invoice_paid_at))); ?></p><?php endif; ?>
                    <div id="hkof-note" style="margin-top:18px">
                        <h3 style="margin-bottom:4px">📝 Interne noter</h3>
                        <p class="description" style="margin-top:0">Kun synligt for jer internt – deles af alle med adgang til systemet, vises aldrig for lejer.</p>
                        <?php if (isset($_GET['note_saved'])): ?>
                            <div class="notice notice-success inline" style="margin:0 0 8px"><p>Note gemt.</p></div>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="hkof_booking_save_note">
                            <input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>">
                            <?php wp_nonce_field('hkof_booking_save_note_' . $b->id); ?>
                            <textarea name="admin_notes" rows="4" class="large-text" placeholder="Skriv en note her – fx aftaler pr. telefon, ting I skal huske, status internt..."><?php echo esc_textarea($b->admin_notes); ?></textarea>
                            <p><button type="submit" class="button">💾 Gem note</button></p>
                        </form>
                    </div>
                </div>

                <div style="flex:0 0 260px">
                    <h2>Handlinger</h2>
                    <div style="display:flex;flex-direction:column;gap:8px">
                    <a href="?page=hkof-bookings&action=edit&id=<?php echo $id; ?>" class="button">✏️ Rediger booking</a>
                    <?php if ($b->status === 'pending'): ?>
                        <a href="<?php echo self::action_url($id, 'approve'); ?>" class="button button-primary" onclick="return confirm('Godkend booking og send opkrævning af depositum til lejer?')">✅ Godkend & send opkrævning</a>
                        <a href="<?php echo self::action_url($id, 'reject'); ?>" class="button" onclick="return confirm('Afvis denne booking?')">❌ Afvis</a>
                    <?php elseif ($b->status === 'approved'): ?>
                        <a href="<?php echo self::action_url($id, 'mark_deposit_paid'); ?>" class="button button-primary" onclick="return confirm('Bekræft at depositum er modtaget – dette sender kontrakten til lejer.')">💰 Registrér depositum modtaget & send kontrakt</a>
                        <a href="<?php echo self::action_url($id, 'resend_deposit_invoice'); ?>" class="button">📧 Gensend opkrævning af depositum</a>
                    <?php elseif ($b->status === 'deposit_paid'): ?>
                        <a href="<?php echo self::action_url($id, 'send_invoice_now'); ?>" class="button button-primary" onclick="return confirm('Send faktura nu (før den automatiske dato)?')">🧾 Send faktura nu</a>
                        <a href="<?php echo self::action_url($id, 'resend_contract'); ?>" class="button">📧 Gensend kontrakt</a>
                    <?php elseif ($b->status === 'invoice_sent'): ?>
                        <a href="<?php echo self::action_url($id, 'mark_invoice_paid'); ?>" class="button button-primary" onclick="return confirm('Bekræft at fakturaen er betalt? Lejeren får en bekræftelsesmail.')">✅ Registrér faktura betalt</a>
                    <?php elseif ($b->status === 'paid'): ?>
                        <a href="<?php echo self::action_url($id, 'mark_completed'); ?>" class="button">🏁 Markér som afsluttet</a>
                    <?php endif; ?>
                    <?php if (!in_array($b->status, ['rejected', 'cancelled', 'completed'])): ?>
                        <a href="<?php echo self::action_url($id, 'cancel'); ?>" class="button" style="color:#b91c1c" onclick="return confirm('Annullér denne booking helt?')">🚫 Annullér booking</a>
                    <?php endif; ?>
                    </div>

                    <?php
                    $upload_dir = wp_upload_dir();
                    $contract_path = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/lejeaftale-' . $b->booking_ref . '.pdf';
                    $contract_url  = trailingslashit($upload_dir['baseurl']) . 'hkof-contracts/lejeaftale-' . $b->booking_ref . '.pdf';
                    $invoice_path  = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/faktura-' . $b->booking_ref . '.pdf';
                    $invoice_url   = trailingslashit($upload_dir['baseurl']) . 'hkof-contracts/faktura-' . $b->booking_ref . '.pdf';
                    ?>
                    <?php if ($b->booking_ref && file_exists($contract_path)): ?>
                        <p style="margin-top:14px"><a href="<?php echo esc_url($contract_url); ?>" target="_blank">📄 Se kontrakt-PDF</a></p>
                    <?php endif; ?>
                    <?php if ($b->booking_ref && file_exists($invoice_path)): ?>
                        <p><a href="<?php echo esc_url($invoice_url); ?>" target="_blank">🧾 Se faktura-PDF</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_edit($id) {
        if (!current_user_can('edit_posts')) return;
        $b = HKOF_DB::get($id);
        if (!$b) { echo '<div class="wrap"><p>Booking ikke fundet.</p></div>'; return; }
        $s = HKOF_Settings::all();
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        ?>
        <div class="wrap hkof-wrap">
            <h1>Rediger booking – <?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></h1>
            <p><a href="?page=hkof-bookings&action=view&id=<?php echo $id; ?>">&larr; Tilbage til booking</a></p>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <p class="description">Bemærk: at redigere en booking sender ikke automatisk besked til lejer. Brug knapperne "Gensend kontrakt" / "Send faktura nu" på booking-siden bagefter, hvis lejer skal have besked om ændringen.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hkof_booking_save">
                <input type="hidden" name="booking_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field('hkof_booking_save_' . $id); ?>

                <h2>Kontaktoplysninger</h2>
                <table class="form-table">
                    <tr><th>Fornavn</th><td><input type="text" name="first_name" value="<?php echo esc_attr($b->first_name); ?>" class="regular-text" required></td></tr>
                    <tr><th>Efternavn</th><td><input type="text" name="last_name" value="<?php echo esc_attr($b->last_name); ?>" class="regular-text" required></td></tr>
                    <tr><th>Adresse</th><td><input type="text" name="address" value="<?php echo esc_attr($b->address); ?>" class="regular-text" required></td></tr>
                    <tr><th>Postnr / By</th><td><input type="text" name="postal_city" value="<?php echo esc_attr($b->postal_city); ?>" class="regular-text" required></td></tr>
                    <tr><th>Email</th><td><input type="email" name="email" value="<?php echo esc_attr($b->email); ?>" class="regular-text" required></td></tr>
                    <tr><th>Telefon</th><td><input type="text" name="phone" value="<?php echo esc_attr($b->phone); ?>" class="regular-text" required></td></tr>
                    <tr><th>Formål</th><td><input type="text" name="purpose" value="<?php echo esc_attr($b->purpose); ?>" class="regular-text" required></td></tr>
                </table>

                <h2>Periode &amp; pris</h2>
                <table class="form-table">
                    <tr>
                        <th>Type</th>
                        <td>
                            <select name="price_type" id="hkof-edit-type">
                                <option value="selskab" <?php selected($b->price_type, 'selskab'); ?>>Selskab (standard <?php echo esc_html($s['default_days']); ?> dage)</option>
                                <option value="moede" <?php selected($b->price_type, 'moede'); ?>>Møde (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                                <option value="begravelse" <?php selected($b->price_type, 'begravelse'); ?>>Begravelse (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Ankomstdato</th><td><input type="date" name="check_in_date" id="hkof-edit-checkin" value="<?php echo esc_attr($b->check_in_date); ?>" required></td></tr>
                    <tr>
                        <th>Ekstra dage</th>
                        <td>
                            <select name="extra_days" id="hkof-edit-extra">
                                <?php for ($i = 0; $i <= 30; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected((int) $b->extra_days, $i); ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                            (<?php echo esc_html(number_format((float) $s['price_extra_day'], 0, ',', '.')); ?> kr. pr. ekstra dag)
                        </td>
                    </tr>
                    <tr>
                        <th>Afgangsdato</th>
                        <td>
                            <input type="date" name="check_out_date" id="hkof-edit-checkout" value="<?php echo esc_attr($b->check_out_date); ?>" required>
                            <button type="button" class="button" id="hkof-edit-recalc-dates">↻ Beregn ud fra type + ekstra dage</button>
                            <p class="description">Beregnes automatisk ud fra type og ekstra dage, men kan tilpasses manuelt her ved behov.</p>
                        </td>
                    </tr>
                    <tr><th>Lejeafgift (kr.)</th><td><input type="number" step="0.01" name="rental_amount" id="hkof-edit-rental" value="<?php echo esc_attr($b->rental_amount); ?>"></td></tr>
                    <tr><th>Ekstra dage – beløb (kr.)</th><td><input type="number" step="0.01" name="extra_days_fee" id="hkof-edit-extrafee" value="<?php echo esc_attr($b->extra_days_fee); ?>"></td></tr>
                    <tr><th>Miljøafgift (kr.)</th><td><input type="number" step="0.01" name="environment_fee" value="<?php echo esc_attr($b->environment_fee); ?>"></td></tr>
                    <tr><th>Depositum (kr.)</th><td><input type="number" step="0.01" name="deposit_amount" value="<?php echo esc_attr($b->deposit_amount); ?>"></td></tr>
                </table>
                <p><button type="button" class="button" id="hkof-edit-recalc-price">↻ Genberegn priser til standardpriser for type + ekstra dage</button></p>

                <h2>Interne noter</h2>
                <textarea name="admin_notes" rows="4" class="large-text" placeholder="Kun synligt for jer internt – vises ikke for lejer"><?php echo esc_textarea($b->admin_notes); ?></textarea>

                <p style="margin-top:18px">
                    <button type="submit" class="button button-primary">💾 Gem ændringer</button>
                    <a href="?page=hkof-bookings&action=view&id=<?php echo $id; ?>" class="button">Annullér</a>
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
                var t = document.getElementById('hkof-edit-type').value;
                return (t === 'moede' || t === 'begravelse') ? baseDaysMoede : baseDaysSelskab;
            }
            function currentBasePrice() {
                var t = document.getElementById('hkof-edit-type').value;
                return (t === 'moede' || t === 'begravelse') ? priceMoede : priceSelskab;
            }
            function addDaysISO(iso, n) {
                var d = new Date(iso + 'T12:00:00');
                d.setDate(d.getDate() + n);
                var pad = function(x){ return x < 10 ? '0'+x : ''+x; };
                return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
            }
            document.getElementById('hkof-edit-recalc-dates').addEventListener('click', function () {
                var checkin = document.getElementById('hkof-edit-checkin').value;
                if (!checkin) { alert('Vælg først en ankomstdato.'); return; }
                var extra = parseInt(document.getElementById('hkof-edit-extra').value, 10) || 0;
                var days = currentBaseDays() + extra;
                document.getElementById('hkof-edit-checkout').value = addDaysISO(checkin, days - 1);
            });
            document.getElementById('hkof-edit-recalc-price').addEventListener('click', function () {
                var extra = parseInt(document.getElementById('hkof-edit-extra').value, 10) || 0;
                document.getElementById('hkof-edit-rental').value = currentBasePrice();
                document.getElementById('hkof-edit-extrafee').value = extra * extraPrice;
            });
        })();
        </script>
        <?php
    }

    public static function handle_save() {
        $id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        check_admin_referer('hkof_booking_save_' . $id);
        if (!current_user_can('edit_posts') || !$id) wp_die('Ingen adgang');
        $booking = HKOF_DB::get($id);
        if (!$booking) wp_die('Booking ikke fundet');

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $address    = sanitize_text_field($_POST['address'] ?? '');
        $postal     = sanitize_text_field($_POST['postal_city'] ?? '');
        $email      = sanitize_email($_POST['email'] ?? '');
        $phone      = sanitize_text_field($_POST['phone'] ?? '');
        $purpose    = sanitize_text_field($_POST['purpose'] ?? '');
        $price_type = in_array($_POST['price_type'] ?? '', ['selskab', 'moede', 'begravelse']) ? $_POST['price_type'] : 'selskab';
        $check_in   = sanitize_text_field($_POST['check_in_date'] ?? '');
        $check_out  = sanitize_text_field($_POST['check_out_date'] ?? '');
        $extra_days = max(0, min(30, (int) ($_POST['extra_days'] ?? 0)));
        $rental_amount   = (float) ($_POST['rental_amount'] ?? 0);
        $extra_days_fee  = (float) ($_POST['extra_days_fee'] ?? 0);
        $environment_fee = (float) ($_POST['environment_fee'] ?? 0);
        $deposit_amount  = (float) ($_POST['deposit_amount'] ?? 0);
        $admin_notes     = sanitize_textarea_field($_POST['admin_notes'] ?? '');

        $redirect_edit = admin_url('admin.php?page=hkof-bookings&action=edit&id=' . $id);

        if (!$first_name || !$last_name || !$address || !$postal || !$email || !$phone || !$purpose || !$check_in || !$check_out) {
            wp_safe_redirect($redirect_edit . '&error=' . rawurlencode('Udfyld venligst alle felter.'));
            exit;
        }
        if (!is_email($email)) {
            wp_safe_redirect($redirect_edit . '&error=' . rawurlencode('Ugyldig email-adresse.'));
            exit;
        }
        if (strtotime($check_out) < strtotime($check_in)) {
            wp_safe_redirect($redirect_edit . '&error=' . rawurlencode('Afgangsdato kan ikke være før ankomstdato.'));
            exit;
        }
        if (HKOF_DB::has_overlap($check_in, $check_out, $id)) {
            wp_safe_redirect($redirect_edit . '&error=' . rawurlencode('Den valgte periode overlapper med en anden booking. Vælg venligst andre datoer.'));
            exit;
        }

        HKOF_DB::update($id, [
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'address'         => $address,
            'postal_city'     => $postal,
            'email'           => $email,
            'phone'           => $phone,
            'purpose'         => $purpose,
            'price_type'      => $price_type,
            'check_in_date'   => $check_in,
            'check_out_date'  => $check_out,
            'extra_days'      => $extra_days,
            'rental_amount'   => $rental_amount,
            'extra_days_fee'  => $extra_days_fee,
            'environment_fee' => $environment_fee,
            'deposit_amount'  => $deposit_amount,
            'admin_notes'     => $admin_notes,
        ]);

        wp_safe_redirect(admin_url('admin.php?page=hkof-bookings&action=view&id=' . $id . '&done=1'));
        exit;
    }

    /** Hurtig gem af kun det interne notefelt - uden at røre resten af bookingens data */
    public static function handle_save_note() {
        $id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        check_admin_referer('hkof_booking_save_note_' . $id);
        if (!current_user_can('edit_posts') || !$id) wp_die('Ingen adgang');
        $booking = HKOF_DB::get($id);
        if (!$booking) wp_die('Booking ikke fundet');

        $admin_notes = sanitize_textarea_field($_POST['admin_notes'] ?? '');
        HKOF_DB::update($id, ['admin_notes' => $admin_notes]);

        wp_safe_redirect(admin_url('admin.php?page=hkof-bookings&action=view&id=' . $id . '&note_saved=1#hkof-note'));
        exit;
    }

    public static function handle_action() {
        $id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        $do = isset($_GET['do']) ? sanitize_text_field($_GET['do']) : '';
        check_admin_referer('hkof_booking_action_' . $id);

        if (!current_user_can('edit_posts') || !$id) wp_die('Ingen adgang');
        $booking = HKOF_DB::get($id);
        if (!$booking) wp_die('Booking ikke fundet');

        switch ($do) {
            case 'approve':
                $ref = HKOF_DB::next_booking_ref();
                HKOF_DB::update($id, ['status' => 'approved', 'booking_ref' => $ref]);
                $booking = HKOF_DB::get($id);
                HKOF_Mailer::send_deposit_invoice($booking);
                HKOF_Mailer::notify_association_invoice_sent($booking, 'depositum');
                HKOF_DB::update($id, ['deposit_invoice_sent_at' => current_time('mysql')]);
                break;

            case 'resend_deposit_invoice':
                if ($booking->booking_ref) {
                    HKOF_Mailer::send_deposit_invoice($booking);
                }
                break;

            case 'resend_contract':
                if ($booking->booking_ref) {
                    $pdf_path = HKOF_PDF::generate_contract($booking);
                    HKOF_Mailer::send_contract($booking, $pdf_path);
                    HKOF_DB::update($id, ['contract_sent_at' => current_time('mysql')]);
                }
                break;

            case 'reject':
                HKOF_DB::update($id, ['status' => 'rejected']);
                HKOF_Mailer::send_rejection($booking);
                break;

            case 'mark_deposit_paid':
                HKOF_DB::update($id, ['status' => 'deposit_paid', 'deposit_paid_at' => current_time('mysql')]);
                $booking = HKOF_DB::get($id);
                $pdf_path = HKOF_PDF::generate_contract($booking);
                HKOF_Mailer::send_contract($booking, $pdf_path);
                HKOF_DB::update($id, ['contract_sent_at' => current_time('mysql')]);
                break;

            case 'send_invoice_now':
                $pdf_path = HKOF_PDF::generate_invoice($booking);
                HKOF_Mailer::send_invoice($booking, $pdf_path);
                HKOF_Mailer::notify_association_invoice_sent($booking, 'leje');
                HKOF_DB::update($id, ['status' => 'invoice_sent', 'invoice_sent_at' => current_time('mysql')]);
                break;

            case 'mark_invoice_paid':
                HKOF_DB::update($id, ['status' => 'paid', 'invoice_paid_at' => current_time('mysql')]);
                $booking = HKOF_DB::get($id);
                HKOF_Mailer::send_final_payment_confirmation($booking);
                break;

            case 'mark_completed':
                HKOF_DB::update($id, ['status' => 'completed', 'completed_at' => current_time('mysql')]);
                break;

            case 'cancel':
                HKOF_DB::update($id, ['status' => 'cancelled']);
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=hkof-bookings&action=view&id=' . $id . '&done=1'));
        exit;
    }
}
