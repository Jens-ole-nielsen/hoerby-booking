<?php
if (!defined('ABSPATH')) exit;

class HKOF_Public {

    public static function init() {
        add_shortcode('hkof_booking', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_hkof_calendar', [__CLASS__, 'ajax_calendar']);
        add_action('wp_ajax_nopriv_hkof_calendar', [__CLASS__, 'ajax_calendar']);
        add_action('wp_ajax_hkof_submit_booking', [__CLASS__, 'ajax_submit']);
        add_action('wp_ajax_nopriv_hkof_submit_booking', [__CLASS__, 'ajax_submit']);
    }

    public static function render_shortcode($atts) {
        $s = HKOF_Settings::all();
        ob_start();
        ?>
        <div class="hkof-booking-widget">
            <div class="hkof-legend">
                <span><i class="hkof-dot hkof-free"></i> Ledig</span>
                <span><i class="hkof-dot hkof-pending"></i> Afventer</span>
                <span><i class="hkof-dot hkof-booked"></i> Reserveret</span>
            </div>
            <div id="hkof-calendar" class="hkof-calendar"
                 data-days-selskab="<?php echo esc_attr($s['default_days']); ?>"
                 data-days-moede="<?php echo esc_attr($s['default_days_moede']); ?>"
                 data-extra-price="<?php echo esc_attr($s['price_extra_day']); ?>"
                 data-price-selskab="<?php echo esc_attr($s['price_selskab']); ?>"
                 data-price-moede="<?php echo esc_attr($s['price_moede']); ?>"
                 data-price-miljoe="<?php echo esc_attr($s['price_miljoeafgift']); ?>">
                <div class="hkof-cal-nav">
                    <button type="button" id="hkof-prev-month">&larr;</button>
                    <span id="hkof-cal-title"></span>
                    <button type="button" id="hkof-next-month">&rarr;</button>
                </div>
                <div id="hkof-cal-grid" class="hkof-cal-grid"><p>Indlæser kalender…</p></div>
            </div>

            <form id="hkof-booking-form" class="hkof-form">
                <p class="hkof-selected-range">Valgt periode: <strong id="hkof-range-display">Vælg ankomstdato i kalenderen</strong></p>
                <input type="hidden" id="hkof-check-in" name="check_in_date">
                <input type="hidden" id="hkof-check-out" name="check_out_date">

                <div class="hkof-row">
                    <div class="hkof-field"><label>Fornavn *</label><input type="text" name="first_name" required></div>
                    <div class="hkof-field"><label>Efternavn *</label><input type="text" name="last_name" required></div>
                </div>
                <div class="hkof-row">
                    <div class="hkof-field"><label>Adresse – Vej / Nr. *</label><input type="text" name="address" required></div>
                    <div class="hkof-field"><label>Postnr / By *</label><input type="text" name="postal_city" required></div>
                </div>
                <div class="hkof-row">
                    <div class="hkof-field"><label>Email *</label><input type="email" name="email" required></div>
                    <div class="hkof-field"><label>Telefon *</label><input type="text" name="phone" required></div>
                </div>
                <div class="hkof-row">
                    <div class="hkof-field"><label>Formål *</label><input type="text" name="purpose" required placeholder="F.eks. konfirmation, fødselsdag, møde"></div>
                    <div class="hkof-field">
                        <label>Type *</label>
                        <select id="hkof-price-type" name="price_type" required>
                            <option value="selskab">Selskab (standard <?php echo esc_html($s['default_days']); ?> dage)</option>
                            <option value="moede">Møde (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                            <option value="begravelse">Begravelse (standard <?php echo esc_html($s['default_days_moede']); ?> dag)</option>
                        </select>
                    </div>
                </div>
                <div class="hkof-row">
                    <div class="hkof-field">
                        <label>Ekstra dage (<?php echo esc_html(number_format((float) $s['price_extra_day'], 0, ',', '.')); ?> kr. pr. ekstra dag)</label>
                        <select id="hkof-extra-days" name="extra_days">
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="hkof-field">
                        <label>Estimeret pris</label>
                        <div id="hkof-price-estimate" class="hkof-price-estimate">Vælg venligst en periode</div>
                    </div>
                </div>

                <p class="hkof-note">Bemærk: huset er ikke endeligt booket før I modtager en lejeaftale på mail og har indbetalt depositum.</p>
                <button type="submit" class="hkof-submit-btn">Send bookingforespørgsel</button>
                <div id="hkof-form-message"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Returnerer optagelsesstatus for en given måned til kalenderen */
    public static function ajax_calendar() {
        check_ajax_referer('hkof_booking_nonce', 'nonce');
        $month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $bookings = HKOF_DB::get_occupied_between($start, $end);
        $days = [];
        foreach ($bookings as $b) {
            $status = ($b->status === 'pending') ? 'pending' : 'booked';
            $cursor = strtotime($b->check_in_date);
            $stop = strtotime($b->check_out_date);
            while ($cursor <= $stop) {
                $d = date('Y-m-d', $cursor);
                // Behold "booked" over "pending" hvis begge findes samme dag
                if (!isset($days[$d]) || $days[$d] !== 'booked') $days[$d] = $status;
                $cursor = strtotime('+1 day', $cursor);
            }
        }
        wp_send_json_success(['days' => $days]);
    }

    public static function ajax_submit() {
        check_ajax_referer('hkof_booking_nonce', 'nonce');

        $check_in  = isset($_POST['check_in_date']) ? sanitize_text_field($_POST['check_in_date']) : '';
        $check_out = isset($_POST['check_out_date']) ? sanitize_text_field($_POST['check_out_date']) : '';
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $address    = sanitize_text_field($_POST['address'] ?? '');
        $postal     = sanitize_text_field($_POST['postal_city'] ?? '');
        $email      = sanitize_email($_POST['email'] ?? '');
        $phone      = sanitize_text_field($_POST['phone'] ?? '');
        $purpose    = sanitize_text_field($_POST['purpose'] ?? '');
        $price_type = in_array($_POST['price_type'] ?? '', ['selskab', 'moede', 'begravelse']) ? $_POST['price_type'] : 'selskab';
        $extra_days = isset($_POST['extra_days']) ? max(0, min(30, (int) $_POST['extra_days'])) : 0;

        if (!$check_in || !$check_out || !$first_name || !$last_name || !$address || !$postal || !$email || !$phone || !$purpose) {
            wp_send_json_error(['message' => 'Udfyld venligst alle felter.']);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Ugyldig email-adresse.']);
        }
        if (strtotime($check_out) < strtotime($check_in)) {
            wp_send_json_error(['message' => 'Afgangsdato kan ikke være før ankomstdato.']);
        }

        // Sikrer at den valgte periode faktisk matcher standardperioden + ekstra dage for den valgte type
        $base_days = HKOF_Settings::base_days_for_type($price_type);
        $expected_days = $base_days + $extra_days;
        $actual_days = (int) round((strtotime($check_out) - strtotime($check_in)) / DAY_IN_SECONDS) + 1;
        if ($actual_days !== $expected_days) {
            wp_send_json_error(['message' => 'Den valgte periode matcher ikke antal dage for den valgte type. Prøv venligst igen.']);
        }

        if (HKOF_DB::has_overlap($check_in, $check_out)) {
            wp_send_json_error(['message' => 'Den valgte periode er desværre ikke længere ledig. Vælg venligst en anden dato.']);
        }

        $s = HKOF_Settings::all();
        $rental = HKOF_Settings::price_for_type($price_type);
        $environment_fee = (float) $s['price_miljoeafgift'];
        $deposit = (float) $s['price_depositum'];
        $extra_days_fee = $extra_days * (float) $s['price_extra_day'];

        $id = HKOF_DB::insert([
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
            'rental_amount'   => $rental,
            'environment_fee' => $environment_fee,
            'deposit_amount'  => $deposit,
            'extra_days'      => $extra_days,
            'extra_days_fee'  => $extra_days_fee,
            'status'          => 'pending',
        ]);

        $booking = HKOF_DB::get($id);
        HKOF_Mailer::send_new_booking_notification($booking);
        HKOF_Mailer::send_booking_received($booking);

        wp_send_json_success(['message' => 'Tak for din forespørgsel! Den afventer nu godkendelse, og du modtager en bekræftelse på mail.']);
    }
}
