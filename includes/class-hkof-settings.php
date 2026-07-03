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
            'default_days_moede'  => 1,
            'price_extra_day'     => 1000,
            'deposit_days_limit'  => 8,
            'invoice_days_before' => 14,
            'udlejning_navn'      => 'Inge Petersen',
            'udlejning_tlf'       => '59 46 11 29',
            'tilsyn_navn'         => 'Lillian Olsen',
            'tilsyn_adresse'      => 'Uglerupvej 24, 4300 Holbæk',
            'tilsyn_tlf'          => '20 77 81 07',
            'sender_name'         => 'Hørby Kultur & Forsamlingshus',
            'notify_email'        => get_option('admin_email'),
            'github_update_token' => '',
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

    // ─── MAIL-PAUSE (midlertidig stop af al mailafsendelse) ─────────────
    const MAIL_PAUSE_OPTION = 'hkof_mail_paused';

    public static function mail_paused() {
        return (bool) get_option(self::MAIL_PAUSE_OPTION, false);
    }

    public static function set_mail_paused($paused) {
        update_option(self::MAIL_PAUSE_OPTION, (bool) $paused);
    }

    // ─── MAIL-SKABELONER (emne/indhold pr. mailtype, kan overskrives) ────
    const MAIL_TEMPLATES_OPTION = 'hkof_mail_templates';

    /** Returnerer den effektive skabelon (gemt overskrivning, ellers standard) for en mailtype */
    public static function mail_template($key) {
        $defs = HKOF_Mailer::template_defs();
        if (!isset($defs[$key])) return null;
        $default = $defs[$key];
        $saved = get_option(self::MAIL_TEMPLATES_OPTION, []);
        $override = isset($saved[$key]) ? $saved[$key] : [];
        return [
            'enabled' => array_key_exists('enabled', $override) ? (bool) $override['enabled'] : true,
            'subject' => !empty($override['subject']) ? $override['subject'] : $default['default_subject'],
            'body'    => !empty($override['body']) ? $override['body'] : $default['default_body'],
        ];
    }

    public static function save_mail_template($key, $enabled, $subject, $body) {
        $saved = get_option(self::MAIL_TEMPLATES_OPTION, []);
        $saved[$key] = ['enabled' => (bool) $enabled, 'subject' => $subject, 'body' => $body];
        update_option(self::MAIL_TEMPLATES_OPTION, $saved);
    }

    /** Fjerner en evt. gemt overskrivning, så mailtypen falder tilbage til standardteksten */
    public static function reset_mail_template($key) {
        $saved = get_option(self::MAIL_TEMPLATES_OPTION, []);
        unset($saved[$key]);
        update_option(self::MAIL_TEMPLATES_OPTION, $saved);
    }

    /** Beregner priser for en given periodetype */
    public static function price_for_type($price_type) {
        $s = self::all();
        if ($price_type === 'moede' || $price_type === 'begravelse') {
            return (float) $s['price_moede'];
        }
        return (float) $s['price_selskab'];
    }

    /** Standard antal dage (uden ekstra dage) for en given periodetype */
    public static function base_days_for_type($price_type) {
        $s = self::all();
        if ($price_type === 'moede' || $price_type === 'begravelse') {
            return max(1, (int) $s['default_days_moede']);
        }
        return max(1, (int) $s['default_days']);
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
                'default_days_moede'  => absint($_POST['default_days_moede']),
                'price_extra_day'     => (float) $_POST['price_extra_day'],
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
            if (!empty($_POST['github_update_token'])) {
                self::update(['github_update_token' => sanitize_text_field($_POST['github_update_token'])]);
            }
            if (!empty($_POST['github_update_token_clear'])) {
                self::update(['github_update_token' => '']);
            }
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

                <h2>Automatiske opdateringer (GitHub)</h2>
                <table class="form-table">
                    <tr>
                        <th>GitHub token</th>
                        <td>
                            <input type="password" name="github_update_token" class="regular-text" placeholder="<?php echo $s['github_update_token'] ? 'sat (udfyld kun for at ændre)' : 'ikke sat'; ?>" autocomplete="off">
                            <?php if ($s['github_update_token']): ?>
                                <label style="display:block;margin-top:6px"><input type="checkbox" name="github_update_token_clear" value="1"> Fjern gemt token</label>
                            <?php endif; ?>
                            <p class="description">Bruges kun til at tjekke GitHub for opdateringer til dette plugin (undgår 403-fejl pga. GitHub's rate-limit for delt hosting-IP'er). Opret et "classic" Personal Access Token på <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a> med scope <code>public_repo</code> (repoet er offentligt, så det er nok). Feltet gemmer aldrig et blankt token oveni et allerede gemt.</p>
                        </td>
                    </tr>
                </table>

                <h2>Priser (kr.)</h2>
                <table class="form-table">
                    <tr><th>Selskaber (hverdag + weekend)</th><td><input type="number" step="0.01" name="price_selskab" value="<?php echo esc_attr($s['price_selskab']); ?>"></td></tr>
                    <tr><th>Møder / begravelser</th><td><input type="number" step="0.01" name="price_moede" value="<?php echo esc_attr($s['price_moede']); ?>"></td></tr>
                    <tr><th>Miljøafgift (alle bookinger)</th><td><input type="number" step="0.01" name="price_miljoeafgift" value="<?php echo esc_attr($s['price_miljoeafgift']); ?>"></td></tr>
                    <tr><th>Depositum (alle bookinger)</th><td><input type="number" step="0.01" name="price_depositum" value="<?php echo esc_attr($s['price_depositum']); ?>"></td></tr>
                    <tr><th>Ekstra dag (pr. dag udover standardperioden)</th><td><input type="number" step="0.01" name="price_extra_day" value="<?php echo esc_attr($s['price_extra_day']); ?>"></td></tr>
                </table>

                <h2>Frister og regler</h2>
                <table class="form-table">
                    <tr><th>Standard antal dage – Selskaber</th><td><input type="number" name="default_days" value="<?php echo esc_attr($s['default_days']); ?>"> dage</td></tr>
                    <tr><th>Standard antal dage – Møder/begravelser</th><td><input type="number" name="default_days_moede" value="<?php echo esc_attr($s['default_days_moede']); ?>"> dage</td></tr>
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

    /**
     * "E-mails"-siden: øverst en hurtig pause-kontakt der midlertidigt stopper
     * ALT mailafsendelse (så man kan godkende/afvise/redigere frit), og
     * derunder en editor for emne + indhold for hver enkelt automatisk mail.
     */
    public static function render_mails_page() {
        if (!current_user_can('manage_options')) return;

        // Pause-kontakt: lille selvstændig formular øverst
        if (isset($_POST['hkof_mail_pause_nonce']) && wp_verify_nonce($_POST['hkof_mail_pause_nonce'], 'hkof_toggle_mail_pause')) {
            self::set_mail_paused(!self::mail_paused());
        }

        // Gem mail-skabeloner
        if (isset($_POST['hkof_mails_nonce']) && wp_verify_nonce($_POST['hkof_mails_nonce'], 'hkof_save_mails')) {
            foreach (HKOF_Mailer::template_defs() as $key => $def) {
                if (!empty($_POST['reset'][$key])) {
                    self::reset_mail_template($key);
                    continue;
                }
                $enabled = !empty($_POST['templates'][$key]['enabled']);
                $subject = sanitize_text_field(wp_unslash($_POST['templates'][$key]['subject'] ?? ''));
                $body    = sanitize_textarea_field(wp_unslash($_POST['templates'][$key]['body'] ?? ''));
                self::save_mail_template($key, $enabled, $subject, $body);
            }
            echo '<div class="notice notice-success"><p>E-mail-indstillinger gemt.</p></div>';
        }

        $paused = self::mail_paused();
        ?>
        <div class="wrap hkof-wrap">
            <h1>Hørby Booking – E-mails</h1>

            <div style="padding:14px 18px;border-radius:8px;margin-bottom:20px;border:1px solid <?php echo $paused ? '#fca5a5' : '#86efac'; ?>;background:<?php echo $paused ? '#fee2e2' : '#dcfce7'; ?>">
                <form method="post" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0">
                    <?php wp_nonce_field('hkof_toggle_mail_pause', 'hkof_mail_pause_nonce'); ?>
                    <?php if ($paused): ?>
                        <strong>🔕 Mail-afsendelse er sat på PAUSE.</strong>
                        <span>Ingen mails sendes til gæster eller foreningen lige nu – I kan godkende, afvise og redigere bookinger i ro og mag.</span>
                        <button type="submit" class="button button-primary">🔔 Aktiver mails igen</button>
                    <?php else: ?>
                        <strong>🔔 Mail-afsendelse er aktiv.</strong>
                        <span>Alle mails sendes normalt til gæster og foreningen.</span>
                        <button type="submit" class="button">🔕 Sæt mails på pause midlertidigt</button>
                    <?php endif; ?>
                </form>
            </div>

            <form method="post">
                <?php wp_nonce_field('hkof_save_mails', 'hkof_mails_nonce'); ?>
                <p class="description">Tilpas emne og indhold for hver automatisk mail nedenfor. Koderne i firkantede parenteser (fx <code>[fornavn]</code>) udskiftes automatisk med de rigtige oplysninger, når mailen sendes. Slå en mailtype fra permanent med "Send denne mail"-boksen, eller sæt hele systemet på pause med knappen ovenfor.<br>
                Vil du ændre <strong>hvornår</strong> depositum-fristen eller den automatiske faktura udløses? Det styres under <a href="<?php echo esc_url(admin_url('admin.php?page=hkof-settings')); ?>">Indstillinger → Frister og regler</a> ("Depositum skal betales inden" og "Faktura sendes automatisk X dage før").</p>

                <?php foreach (HKOF_Mailer::template_defs() as $key => $def):
                    $tpl = self::mail_template($key);
                ?>
                    <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin-bottom:16px;background:#fff">
                        <h2 style="margin-top:0"><?php echo esc_html($def['label']); ?> <span style="font-weight:400;font-size:13px;color:#777">(sendes til: <?php echo esc_html($def['to']); ?>)</span></h2>
                        <p>
                            <label><input type="checkbox" name="templates[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked($tpl['enabled']); ?>> Send denne mail</label>
                        </p>
                        <table class="form-table">
                            <tr><th>Emne</th><td><input type="text" name="templates[<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($tpl['subject']); ?>" class="large-text"></td></tr>
                            <tr><th>Indhold</th><td><textarea name="templates[<?php echo esc_attr($key); ?>][body]" rows="8" class="large-text"><?php echo esc_textarea($tpl['body']); ?></textarea></td></tr>
                        </table>
                        <p class="description">
                            <strong>Tilgængelige koder:</strong><br>
                            <?php foreach ($def['tokens'] as $token => $desc): ?>
                                <code><?php echo esc_html($token); ?></code> – <?php echo esc_html($desc); ?><br>
                            <?php endforeach; ?>
                        </p>
                        <label><input type="checkbox" name="reset[<?php echo esc_attr($key); ?>]" value="1"> Nulstil til standardtekst ved gem</label>
                    </div>
                <?php endforeach; ?>

                <?php submit_button('Gem alle mail-indstillinger'); ?>
            </form>
        </div>
        <?php
    }
}
