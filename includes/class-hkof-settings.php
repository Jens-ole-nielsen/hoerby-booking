<?php
if (!defined('ABSPATH')) exit;

/**
 * Alle indstillinger for foreningen, priser og bankoplysninger.
 * Gemmes som ét samlet option-array for nem håndtering.
 */
class HKOF_Settings {

    const OPTION_KEY = 'hkof_booking_settings';

    public static function defaults() {
        return [
            'association_name'    => 'Hørby Kultur & Forsamlingshus',
            'association_address' => 'Uglerupvej 24',
            'association_postal'  => '4300 Holbæk',
            'association_website' => 'https://hoerbyforsamlingshus.dk',
            'cvr'                 => '71268454',
            'bank_navn'           => 'Sparekassen Sjælland',
            'bank_reg'            => '0520',
            'bank_konto'          => '1034666',
            'logo_id'             => 0,
            'price_selskab'       => 3500,
            'price_moede'         => 1500,
            'price_miljoeafgift'  => 450,
            'price_depositum'     => 2000,
            'default_days'        => 3,
            'deposit_days_limit'  => 8,
            'invoice_days_before' => 14,
            'udlejning_navn'      => 'Inge Petersen',
            'udlejning_tlf'       => '59 46 11 29',
            'tilsyn_navn'         => 'Lillian Olsen',
            'tilsyn_adresse'      => 'Uglerupvej 24, 4300 Holbæk',
            'tilsyn_tlf'          => '20 77 81 07',
            'sender_name'         => 'Hørby Kultur & Forsamlingshus',
            'notify_email'        => get_option('admin_email'),
        ];
    }

    public static function set_defaults() {
        if (!get_option(self::OPTION_KEY)) {
            update_option(self::OPTION_KEY, self::defaults());
        }
    }

    public static function all() {
        $saved = get_option(self::OPTION_KEY, []);
        return array_merge(self::defaults(), $saved);
    }

    public static function get($key) {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function update($data) {
        $current = self::all();
        $merged = array_merge($current, $data);
        update_option(self::OPTION_KEY, $merged);
    }

    /** Beregner priser for en given periodetype */
    public static function price_for_type($price_type) {
        $s = self::all();
        if ($price_type === 'moede' || $price_type === 'begravelse') {
            return (float) $s['price_moede'];
        }
        return (float) $s['price_selskab'];
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::all();

        if (isset($_POST['hkof_settings_nonce']) && wp_verify_nonce($_POST['hkof_settings_nonce'], 'hkof_save_settings')) {
            $logo_id = isset($_POST['logo_id']) ? absint($_POST['logo_id']) : 0;
            self::update([
                'association_name'    => sanitize_text_field($_POST['association_name']),
                'association_address' => sanitize_text_field($_POST['association_address']),
                'association_postal'  => sanitize_text_field($_POST['association_postal']),
                'association_website' => esc_url_raw($_POST['association_website']),
                'cvr'                 => sanitize_text_field($_POST['cvr']),
                'bank_navn'           => sanitize_text_field($_POST['bank_navn']),
                'bank_reg'            => sanitize_text_field($_POST['bank_reg']),
                'bank_konto'          => sanitize_text_field($_POST['bank_konto']),
                'logo_id'             => $logo_id,
                'price_selskab'       => (float) $_POST['price_selskab'],
                'price_moede'         => (float) $_POST['price_moede'],
                'price_miljoeafgift'  => (float) $_POST['price_miljoeafgift'],
                'price_depositum'     => (float) $_POST['price_depositum'],
                'default_days'        => absint($_POST['default_days']),
                'deposit_days_limit'  => absint($_POST['deposit_days_limit']),
                'invoice_days_before' => absint($_POST['invoice_days_before']),
                'udlejning_navn'      => sanitize_text_field($_POST['udlejning_navn']),
                'udlejning_tlf'       => sanitize_text_field($_POST['udlejning_tlf']),
                'tilsyn_navn'         => sanitize_text_field($_POST['tilsyn_navn']),
                'tilsyn_adresse'      => sanitize_text_field($_POST['tilsyn_adresse']),
                'tilsyn_tlf'          => sanitize_text_field($_POST['tilsyn_tlf']),
                'sender_name'         => sanitize_text_field($_POST['sender_name']),
                'notify_email'        => sanitize_email($_POST['notify_email']),
            ]);
            echo '<div class="notice notice-success"><p>Indstillinger gemt.</p></div>';
            $s = self::all();
        }

        $logo_url = $s['logo_id'] ? wp_get_attachment_url($s['logo_id']) : '';
        ?>
        <div class="wrap hkof-wrap">
            <h1>Hørby Booking – Indstillinger</h1>
            <form method="post">
                <?php wp_nonce_field('hkof_save_settings', 'hkof_settings_nonce'); ?>

                <h2>Foreningsoplysninger</h2>
                <table class="form-table">
                    <tr><th>Navn</th><td><input type="text" name="association_name" class="regular-text" value="<?php echo esc_attr($s['association_name']); ?>"></td></tr>
                    <tr><th>Adresse</th><td><input type="text" name="association_address" class="regular-text" value="<?php echo esc_attr($s['association_address']); ?>"></td></tr>
                    <tr><th>Postnr / By</th><td><input type="text" name="association_postal" class="regular-text" value="<?php echo esc_attr($s['association_postal']); ?>"></td></tr>
                    <tr><th>Hjemmeside</th><td><input type="text" name="association_website" class="regular-text" value="<?php echo esc_attr($s['association_website']); ?>"></td></tr>
                    <tr><th>CVR-nr.</th><td><input type="text" name="cvr" class="regular-text" value="<?php echo esc_attr($s['cvr']); ?>"></td></tr>
                    <tr>
                        <th>Logo</th>
                        <td>
                            <div id="hkof-logo-preview" style="margin-bottom:8px">
                                <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" style="max-height:80px;display:block;margin-bottom:8px"><?php endif; ?>
                            </div>
                            <input type="hidden" id="logo_id" name="logo_id" value="<?php echo esc_attr($s['logo_id']); ?>">
                            <button type="button" class="button" id="hkof-upload-logo-btn">Vælg logo fra mediebibliotek</button>
                        </td>
                    </tr>
                </table>

                <h2>Bankoplysninger (vises på kontrakt og faktura)</h2>
                <table class="form-table">
                    <tr><th>Bank</th><td><input type="text" name="bank_navn" class="regular-text" value="<?php echo esc_attr($s['bank_navn']); ?>"></td></tr>
                    <tr><th>Reg. nr.</th><td><input type="text" name="bank_reg" value="<?php echo esc_attr($s['bank_reg']); ?>"></td></tr>
                    <tr><th>Konto nr.</th><td><input type="text" name="bank_konto" value="<?php echo esc_attr($s['bank_konto']); ?>"></td></tr>
                </table>

                <h2>Priser (kr.)</h2>
                <table class="form-table">
                    <tr><th>Selskaber (hverdag + weekend)</th><td><input type="number" step="0.01" name="price_selskab" value="<?php echo esc_attr($s['price_selskab']); ?>"></td></tr>
                    <tr><th>Møder / begravelser</th><td><input type="number" step="0.01" name="price_moede" value="<?php echo esc_attr($s['price_moede']); ?>"></td></tr>
                    <tr><th>Miljøafgift (alle bookinger)</th><td><input type="number" step="0.01" name="price_miljoeafgift" value="<?php echo esc_attr($s['price_miljoeafgift']); ?>"></td></tr>
                    <tr><th>Depositum (alle bookinger)</th><td><input type="number" step="0.01" name="price_depositum" value="<?php echo esc_attr($s['price_depositum']); ?>"></td></tr>
                </table>

                <h2>Frister og regler</h2>
                <table class="form-table">
                    <tr><th>Standard antal dage pr. booking</th><td><input type="number" name="default_days" value="<?php echo esc_attr($s['default_days']); ?>"> dage</td></tr>
                    <tr><th>Depositum skal betales inden</th><td><input type="number" name="deposit_days_limit" value="<?php echo esc_attr($s['deposit_days_limit']); ?>"> dage efter godkendelse</td></tr>
                    <tr><th>Faktura sendes automatisk</th><td><input type="number" name="invoice_days_before" value="<?php echo esc_attr($s['invoice_days_before']); ?>"> dage før arrangementet</td></tr>
                </table>

                <h2>Kontaktpersoner (vises på kontrakten)</h2>
                <table class="form-table">
                    <tr><th>Udlejning – navn</th><td><input type="text" name="udlejning_navn" class="regular-text" value="<?php echo esc_attr($s['udlejning_navn']); ?>"></td></tr>
                    <tr><th>Udlejning – telefon</th><td><input type="text" name="udlejning_tlf" value="<?php echo esc_attr($s['udlejning_tlf']); ?>"></td></tr>
                    <tr><th>Tilsynsførende – navn</th><td><input type="text" name="tilsyn_navn" class="regular-text" value="<?php echo esc_attr($s['tilsyn_navn']); ?>"></td></tr>
                    <tr><th>Tilsynsførende – adresse</th><td><input type="text" name="tilsyn_adresse" class="regular-text" value="<?php echo esc_attr($s['tilsyn_adresse']); ?>"></td></tr>
                    <tr><th>Tilsynsførende – telefon</th><td><input type="text" name="tilsyn_tlf" value="<?php echo esc_attr($s['tilsyn_tlf']); ?>"></td></tr>
                    <tr><th>Afsendernavn i mails</th><td><input type="text" name="sender_name" class="regular-text" value="<?php echo esc_attr($s['sender_name']); ?>"></td></tr>
                    <tr><th>Notifikations-email (nye bookinger)</th><td><input type="email" name="notify_email" class="regular-text" value="<?php echo esc_attr($s['notify_email']); ?>"></td></tr>
                </table>

                <?php submit_button('Gem indstillinger'); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            $('#hkof-upload-logo-btn').on('click', function(e){
                e.preventDefault();
                var frame = wp.media({ title: 'Vælg logo', multiple: false, library: { type: 'image' } });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#logo_id').val(att.id);
                    $('#hkof-logo-preview').html('<img src="'+att.url+'" style="max-height:80px;display:block;margin-bottom:8px">');
                });
                frame.open();
            });
        });
        </script>
        <?php
    }
}
