<?php
if (!defined('ABSPATH')) exit;

/**
 * Håndterer alle udgående mails i booking-flowet.
 *
 * To ting kan styres fra backend (Indstillinger → E-mails):
 * 1) En global "pause"-kontakt (HKOF_Settings::mail_paused()) der midlertidigt
 *    stopper AL mailafsendelse - så man kan godkende/afvise/redigere bookinger
 *    uden at gæster eller foreningen får besked.
 * 2) Emne + indhold for hver enkelt mailtype, med [kode]-pladsholdere der
 *    udskiftes automatisk. Hver mailtype kan desuden slås helt fra permanent.
 */
class HKOF_Mailer {

    private static function headers() {
        $s = HKOF_Settings::all();
        return ['Content-Type: text/html; charset=UTF-8', 'From: ' . $s['sender_name'] . ' <' . get_option('admin_email') . '>'];
    }

    /** Pakker den rene tekst-krop ind i et simpelt HTML-layout med foreningens logo øverst (hvis valgt under Indstillinger). */
    private static function wrap_html($body) {
        $s = HKOF_Settings::all();
        $logo_url = $s['logo_id'] ? wp_get_attachment_url($s['logo_id']) : '';
        $logo_html = $logo_url
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($s['association_name']) . '" style="max-height:80px;margin-bottom:20px;display:block">'
            : '<div style="font-size:18px;font-weight:bold;margin-bottom:16px;color:#222">' . esc_html($s['association_name']) . '</div>';
        $body_html = nl2br(esc_html($body));
        return '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222;max-width:600px;margin:0 auto;padding:24px 20px">'
            . $logo_html
            . '<div>' . $body_html . '</div>'
            . '<hr style="margin:28px 0 12px;border:none;border-top:1px solid #ddd">'
            . '<p style="color:#888;font-size:12px;margin:0">' . esc_html($s['association_name']) . '</p>'
            . '</body></html>';
    }

    /**
     * Definerer alle mailtyper: label, hvem de sendes til, hvilke [koder] der
     * er tilgængelige (med forklaring), samt standard emne/indhold. Dette er
     * eneste sted standardteksterne defineres - HKOF_Settings gemmer kun evt.
     * overskrivninger af dem.
     */
    public static function template_defs() {
        return [
            'new_booking_notification' => [
                'label' => 'Ny bookingforespørgsel (til foreningen)',
                'to'    => 'foreningen',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[efternavn]' => 'Lejers efternavn',
                    '[periode]' => 'Ankomst - afgang, fx 14.08.2026 - 16.08.2026',
                    '[formaal]' => 'Formål med lejen',
                    '[email]' => 'Lejers email',
                    '[telefon]' => 'Lejers telefon',
                    '[admin_link]' => 'Direkte link til at godkende/afvise i admin',
                ],
                'default_subject' => 'Ny bookingforespørgsel – [fornavn] [efternavn]',
                'default_body' =>
                    "Der er kommet en ny bookingforespørgsel på hjemmesiden:\n\n"
                    . "Navn: [fornavn] [efternavn]\n"
                    . "Periode: [periode]\n"
                    . "Formål: [formaal]\n"
                    . "Email: [email]\n"
                    . "Telefon: [telefon]\n\n"
                    . "Godkend eller afvis her: [admin_link]",
            ],
            'booking_received' => [
                'label' => 'Kvittering for modtaget forespørgsel (til gæst)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[periode]' => 'Ankomst - afgang',
                    '[forening_navn]' => 'Foreningens navn',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                ],
                'default_subject' => 'Vi har modtaget jeres bookingforespørgsel – [forening_navn]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Tak for jeres bookingforespørgsel til [periode].\n"
                    . "Den er nu registreret og afventer godkendelse. I hører fra os snarest.\n\n"
                    . "MVH [afsender_navn]",
            ],
            'deposit_invoice' => [
                'label' => 'Booking godkendt – opkrævning af depositum (til gæst)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[periode]' => 'Ankomst - afgang',
                    '[depositum]' => 'Depositumbeløb, fx 2.000,00',
                    '[depositum_frist_dage]' => 'Antal dage depositum skal betales indenfor',
                    '[bank_navn]' => 'Bankens navn',
                    '[bank_reg]' => 'Reg. nr.',
                    '[bank_konto]' => 'Konto nr.',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                    '[udlejning_navn]' => 'Kontaktperson for udlejning',
                ],
                'default_subject' => 'Booking godkendt – opkrævning af depositum, lejeaftale nr. [lejeaftale_nr]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Vi kan bekræfte jeres booking af huset [periode].\n\n"
                    . "For at bekræfte lejeaftalen bedes depositum på kr. [depositum] indbetalt indenfor [depositum_frist_dage] dage til:\n"
                    . "[bank_navn] Reg. nr. [bank_reg] Konto nr. [bank_konto]\n"
                    . "Husk at anføre lejeaftale nr. [lejeaftale_nr] på indbetalingen.\n\n"
                    . "Så snart vi har registreret depositummet, sender vi jer den underskrevne lejeaftale.\n\n"
                    . "MVH [afsender_navn]\n"
                    . "[udlejning_navn]",
            ],
            'notify_deposit_sent' => [
                'label' => 'Depositum-opkrævning sendt (notifikation til foreningen)',
                'to'    => 'foreningen',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[efternavn]' => 'Lejers efternavn',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[beloeb]' => 'Opkrævet beløb',
                    '[periode]' => 'Ankomst - afgang',
                    '[admin_link]' => 'Direkte link til bookingen i admin',
                ],
                'default_subject' => 'Opkrævning af depositum sendt – lejeaftale nr. [lejeaftale_nr]',
                'default_body' =>
                    "Der er netop sendt en opkrævning af depositum til [fornavn] [efternavn] (lejeaftale nr. [lejeaftale_nr]).\n\n"
                    . "Beløb: kr. [beloeb]\n"
                    . "Periode: [periode]\n\n"
                    . "Husk at holde øje med at indbetalingen kommer ind på kontoen.\n\n"
                    . "Se booking: [admin_link]",
            ],
            'contract' => [
                'label' => 'Underskrevet lejeaftale (til gæst, med PDF)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[forening_navn]' => 'Foreningens navn',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                    '[udlejning_navn]' => 'Kontaktperson for udlejning',
                ],
                'default_subject' => 'Lejeaftale nr. [lejeaftale_nr] – [forening_navn]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Vi har registreret jeres depositum – tak for det. Hermed fremsendes den underskrevne lejeaftale vedr. leje af huset.\n\n"
                    . "I er velkommen til at kontakte undertegnede hvis i skulle have nogle spørgsmål.\n\n"
                    . "MVH [afsender_navn]\n"
                    . "[udlejning_navn]",
            ],
            'invoice' => [
                'label' => 'Faktura for leje (til gæst, med PDF)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[ankomst]' => 'Ankomstdato',
                    '[beloeb]' => 'Fakturabeløb i alt',
                    '[faktura_frist_dage]' => 'Antal dage før arrangementet fakturaen forfalder',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                ],
                'default_subject' => 'Faktura for leje af huset – [lejeaftale_nr]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Vedhæftet finder du fakturaen for jeres leje af huset d. [ankomst].\n"
                    . "Beløbet på kr. [beloeb] bedes indbetalt senest [faktura_frist_dage] dage før arrangementet – husk lejeaftale nr. [lejeaftale_nr] på indbetalingen.\n\n"
                    . "MVH [afsender_navn]",
            ],
            'notify_invoice_sent' => [
                'label' => 'Lejefaktura sendt (notifikation til foreningen)',
                'to'    => 'foreningen',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[efternavn]' => 'Lejers efternavn',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[beloeb]' => 'Faktureret beløb',
                    '[periode]' => 'Ankomst - afgang',
                    '[admin_link]' => 'Direkte link til bookingen i admin',
                ],
                'default_subject' => 'Opkrævning af leje sendt – lejeaftale nr. [lejeaftale_nr]',
                'default_body' =>
                    "Der er netop sendt en opkrævning af leje til [fornavn] [efternavn] (lejeaftale nr. [lejeaftale_nr]).\n\n"
                    . "Beløb: kr. [beloeb]\n"
                    . "Periode: [periode]\n\n"
                    . "Husk at holde øje med at indbetalingen kommer ind på kontoen.\n\n"
                    . "Se booking: [admin_link]",
            ],
            'rejection' => [
                'label' => 'Booking afvist (til gæst)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[periode]' => 'Ankomst - afgang',
                    '[begrundelse]' => 'Evt. begrundelse admin har angivet (indsættes med linjeskift, kan være tom)',
                    '[forening_navn]' => 'Foreningens navn',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                ],
                'default_subject' => 'Vedr. jeres bookingforespørgsel – [forening_navn]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Desværre kan vi ikke imødekomme jeres bookingforespørgsel for perioden [periode].[begrundelse]\n\n"
                    . "I er velkomne til at kontakte os for en alternativ dato.\n\n"
                    . "MVH [afsender_navn]",
            ],
            'final_payment_confirmation' => [
                'label' => 'Betaling modtaget – endelig bekræftelse (til gæst)',
                'to'    => 'gæsten',
                'tokens' => [
                    '[fornavn]' => 'Lejers fornavn',
                    '[lejeaftale_nr]' => 'Lejeaftalens nummer',
                    '[ankomst]' => 'Ankomstdato',
                    '[afsender_navn]' => 'Afsendernavn i mails',
                ],
                'default_subject' => 'Betaling modtaget – lejeaftale nr. [lejeaftale_nr]',
                'default_body' =>
                    "Hej [fornavn],\n\n"
                    . "Vi bekræfter at have modtaget betaling for jeres leje af huset. Lejeaftale nr. [lejeaftale_nr] er dermed fuldt betalt, og alt er klar til jeres arrangement d. [ankomst].\n\n"
                    . "Vi glæder os til at se jer!\n\n"
                    . "MVH [afsender_navn]",
            ],
        ];
    }

    /** Fælles koder der findes i alle bookinger, uanset mailtype */
    private static function tokens($booking, $extra = []) {
        $s = HKOF_Settings::all();
        $common = [
            '[fornavn]'        => $booking->first_name,
            '[efternavn]'      => $booking->last_name,
            '[periode]'        => date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)),
            '[ankomst]'        => date_i18n('d.m.Y', strtotime($booking->check_in_date)),
            '[afgang]'         => date_i18n('d.m.Y', strtotime($booking->check_out_date)),
            '[lejeaftale_nr]'  => $booking->booking_ref,
            '[forening_navn]'  => $s['association_name'],
            '[afsender_navn]'  => $s['sender_name'],
            '[udlejning_navn]' => $s['udlejning_navn'],
        ];
        return array_merge($common, $extra);
    }

    /** Slår skabelonen op, tjekker om mailtypen er slået til, og udskifter koder */
    private static function render($tpl_key, $tokens) {
        $tpl = HKOF_Settings::mail_template($tpl_key);
        if (!$tpl || !$tpl['enabled']) return null;
        return [
            'subject' => strtr($tpl['subject'], $tokens),
            'body'    => strtr($tpl['body'], $tokens),
        ];
    }

    /** Eneste sted wp_mail() reelt kaldes - tjekker den globale pause-kontakt først */
    private static function dispatch($to, $subject, $body, $attachments = []) {
        if (!$to) return false;
        if (HKOF_Settings::mail_paused()) return false; // Mail-afsendelse midlertidigt sat på pause
        return wp_mail($to, $subject, self::wrap_html($body), self::headers(), $attachments);
    }

    /** Sendes til foreningen når en ny booking-forespørgsel kommer ind */
    public static function send_new_booking_notification($booking) {
        $s = HKOF_Settings::all();
        $to = $s['notify_email'];
        if (!$to) return;
        $tokens = self::tokens($booking, [
            '[formaal]'    => $booking->purpose,
            '[email]'      => $booking->email,
            '[telefon]'    => $booking->phone,
            '[admin_link]' => admin_url('admin.php?page=hkof-bookings&action=view&id=' . $booking->id),
        ]);
        $r = self::render('new_booking_notification', $tokens);
        if (!$r) return;
        self::dispatch($to, $r['subject'], $r['body']);
    }

    /** Sendes til gæsten straks efter de har afsendt en bookingforespørgsel */
    public static function send_booking_received($booking) {
        $tokens = self::tokens($booking);
        $r = self::render('booking_received', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body']);
    }

    /** Sendes til lejer når bookingen godkendes – bekræftelse + opkrævning af depositum */
    public static function send_deposit_invoice($booking) {
        $s = HKOF_Settings::all();
        $tokens = self::tokens($booking, [
            '[depositum]'             => number_format((float) $booking->deposit_amount, 2, ',', '.'),
            '[depositum_frist_dage]'  => $s['deposit_days_limit'],
            '[bank_navn]'             => $s['bank_navn'],
            '[bank_reg]'              => $s['bank_reg'],
            '[bank_konto]'            => $s['bank_konto'],
        ]);
        $r = self::render('deposit_invoice', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body']);
    }

    /** Sendes til foreningen når en opkrævning (depositum eller leje) er sendt til lejer */
    public static function notify_association_invoice_sent($booking, $type) {
        $s = HKOF_Settings::all();
        $to = $s['notify_email'];
        if (!$to) return;
        $amount = $type === 'depositum'
            ? (float) $booking->deposit_amount
            : (float) $booking->rental_amount + (float) $booking->extra_days_fee + (float) $booking->environment_fee;
        $tokens = self::tokens($booking, [
            '[beloeb]'     => number_format($amount, 2, ',', '.'),
            '[admin_link]' => admin_url('admin.php?page=hkof-bookings&action=view&id=' . $booking->id),
        ]);
        $tpl_key = $type === 'depositum' ? 'notify_deposit_sent' : 'notify_invoice_sent';
        $r = self::render($tpl_key, $tokens);
        if (!$r) return;
        self::dispatch($to, $r['subject'], $r['body']);
    }

    /** Sendes til lejer når bookingen godkendes – med kontrakt-PDF vedhæftet */
    public static function send_contract($booking, $pdf_path) {
        // Gemmer samtidig en kopi af kontrakten i Google Drive (hvis forbundet) -
        // sker uafhængigt af om selve mailen rent faktisk sendes (fx ved pause),
        // så der altid ligger en backup så snart kontrakten genereres.
        HKOF_GDrive::upload_contract($booking, $pdf_path);

        $tokens = self::tokens($booking);
        $r = self::render('contract', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body'], [$pdf_path]);
    }

    /** Sendes automatisk (via cron) eller manuelt X dage før arrangementet – faktura vedhæftet */
    public static function send_invoice($booking, $pdf_path) {
        $s = HKOF_Settings::all();
        $total = (float) $booking->rental_amount + (float) $booking->extra_days_fee + (float) $booking->environment_fee;
        $tokens = self::tokens($booking, [
            '[beloeb]'             => number_format($total, 2, ',', '.'),
            '[faktura_frist_dage]' => $s['invoice_days_before'],
        ]);
        $r = self::render('invoice', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body'], [$pdf_path]);
    }

    /** Sendes hvis en booking afvises */
    public static function send_rejection($booking) {
        $reason = $booking->rejected_reason ? "\n\nBegrundelse: {$booking->rejected_reason}" : '';
        $tokens = self::tokens($booking, [
            '[begrundelse]' => $reason,
        ]);
        $r = self::render('rejection', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body']);
    }

    /** Kvittering til lejer når selve lejen er registreret som betalt */
    public static function send_final_payment_confirmation($booking) {
        $tokens = self::tokens($booking);
        $r = self::render('final_payment_confirmation', $tokens);
        if (!$r) return;
        self::dispatch($booking->email, $r['subject'], $r['body']);
    }
}
