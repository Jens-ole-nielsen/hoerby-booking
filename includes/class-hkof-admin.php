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
        add_action('admin_enqueue_scripts', function ($hook) {
            if (strpos($hook, 'hkof-') !== false) wp_enqueue_media();
        });
    }

    public static function menu() {
        add_menu_page('Hørby Booking', 'Hørby Booking', 'edit_posts', 'hkof-bookings', [__CLASS__, 'render_list'], 'dashicons-calendar-alt', 26);
        add_submenu_page('hkof-bookings', 'Bookinger', 'Bookinger', 'edit_posts', 'hkof-bookings', [__CLASS__, 'render_list']);
        add_submenu_page('hkof-bookings', 'Indstillinger', 'Indstillinger', 'manage_options', 'hkof-settings', ['HKOF_Settings', 'render_settings_page']);
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

        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $bookings = HKOF_DB::all($filter, 200);
        $counts = HKOF_DB::counts();
        $total = array_sum($counts);
        ?>
        <div class="wrap hkof-wrap">
            <h1>Hørby Booking – Bookinger</h1>

            <ul class="subsubsub">
                <li><a href="?page=hkof-bookings&status=all" class="<?php echo $filter==='all'?'current':''; ?>">Alle <span class="count">(<?php echo $total; ?>)</span></a> |</li>
                <?php foreach (self::$status_labels as $key => $l): $c = $counts[$key] ?? 0; ?>
                <li><a href="?page=hkof-bookings&status=<?php echo $key; ?>" class="<?php echo $filter===$key?'current':''; ?>"><?php echo esc_html($l[0]); ?> <span class="count">(<?php echo $c; ?>)</span></a> |</li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Lejeaftale nr.</th><th>Navn</th><th>Periode</th><th>Formål</th><th>Status</th><th>Oprettet</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$bookings): ?>
                    <tr><td colspan="7">Ingen bookinger fundet.</td></tr>
                <?php else: foreach ($bookings as $b): ?>
                    <tr>
                        <td><?php echo esc_html($b->booking_ref ?: '—'); ?></td>
                        <td><?php echo esc_html($b->first_name . ' ' . $b->last_name); ?></td>
                        <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($b->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($b->check_out_date))); ?></td>
                        <td><?php echo esc_html($b->purpose); ?></td>
                        <td><?php echo self::label($b->status); ?></td>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->created_at))); ?></td>
                        <td><a class="button button-small" href="?page=hkof-bookings&action=view&id=<?php echo $b->id; ?>">Åbn</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
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
                        <tr><th>Miljøafgift</th><td><?php echo number_format((float) $b->environment_fee, 2, ',', '.'); ?> kr.</td></tr>
                        <tr><th>Depositum</th><td><?php echo number_format((float) $b->deposit_amount, 2, ',', '.'); ?> kr.</td></tr>
                    </table>

                    <?php if ($b->deposit_invoice_sent_at): ?><p>📨 Opkrævning af depositum sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->deposit_invoice_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->contract_sent_at): ?><p>📄 Kontrakt sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->contract_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->deposit_paid_at): ?><p>💰 Depositum modtaget: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->deposit_paid_at))); ?></p><?php endif; ?>
                    <?php if ($b->invoice_sent_at): ?><p>🧾 Faktura sendt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->invoice_sent_at))); ?></p><?php endif; ?>
                    <?php if ($b->invoice_paid_at): ?><p>✅ Faktura betalt: <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($b->invoice_paid_at))); ?></p><?php endif; ?>
                </div>

                <div style="flex:0 0 260px">
                    <h2>Handlinger</h2>
                    <div style="display:flex;flex-direction:column;gap:8px">
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
