<?php
if (!defined('ABSPATH')) exit;

class HKOF_Mailer {

    private static function headers() {
        $s = HKOF_Settings::all();
        return ['Content-Type: text/plain; charset=UTF-8', 'From: ' . $s['sender_name'] . ' <' . get_option('admin_email') . '>'];
    }

    /** Sendes til foreningen når en ny booking-forespørgsel kommer ind */
    public static function send_new_booking_notification($booking) {
        $s = HKOF_Settings::all();
        $to = $s['notify_email'];
        if (!$to) return;
        $admin_url = admin_url('admin.php?page=hkof-bookings&action=view&id=' . $booking->id);
        $subject = 'Ny bookingforespørgsel – ' . $booking->first_name . ' ' . $booking->last_name;
        $body = "Der er kommet en ny bookingforespørgsel på hjemmesiden:\n\n"
            . "Navn: {$booking->first_name} {$booking->last_name}\n"
            . "Periode: " . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)) . "\n"
            . "Formål: {$booking->purpose}\n"
            . "Email: {$booking->email}\n"
            . "Telefon: {$booking->phone}\n\n"
            . "Godkend eller afvis her: $admin_url";
        wp_mail($to, $subject, $body, self::headers());
    }

    /** Sendes til gæsten straks efter de har afsendt en bookingforespørgsel */
    public static function send_booking_received($booking) {
        $s = HKOF_Settings::all();
        $subject = 'Vi har modtaget jeres bookingforespørgsel – ' . $s['association_name'];
        $body = "Hej {$booking->first_name},\n\n"
            . "Tak for jeres bookingforespørgsel til " . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)) . ".\n"
            . "Den er nu registreret og afventer godkendelse. I hører fra os snarest.\n\n"
            . "MVH {$s['sender_name']}";
        wp_mail($booking->email, $subject, $body, self::headers());
    }

    /** Sendes til lejer når bookingen godkendes – bekræftelse + opkrævning af depositum */
    public static function send_deposit_invoice($booking) {
        $s = HKOF_Settings::all();
        $subject = 'Booking godkendt – opkrævning af depositum, lejeaftale nr. ' . $booking->booking_ref;
        $deposit = number_format((float) $booking->deposit_amount, 2, ',', '.');
        $body = "Hej {$booking->first_name},\n\n"
            . "Vi kan bekræfte jeres booking af huset " . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)) . ".\n\n"
            . "For at bekræfte lejeaftalen bedes depositum på kr. {$deposit} indbetalt indenfor {$s['deposit_days_limit']} dage til:\n"
            . "{$s['bank_navn']} Reg. nr. {$s['bank_reg']} Konto nr. {$s['bank_konto']}\n"
            . "Husk at anføre lejeaftale nr. {$booking->booking_ref} på indbetalingen.\n\n"
            . "Så snart vi har registreret depositummet, sender vi jer den underskrevne lejeaftale.\n\n"
            . "MVH {$s['sender_name']}\n"
            . $s['udlejning_navn'];
        wp_mail($booking->email, $subject, $body, self::headers());
    }

    /** Sendes til foreningen (post@hoerbyforsamlingshus.dk) når en opkrævning (depositum eller leje) er sendt til lejer */
    public static function notify_association_invoice_sent($booking, $type) {
        $s = HKOF_Settings::all();
        $to = $s['notify_email'];
        if (!$to) return;
        $label = $type === 'depositum' ? 'depositum' : 'leje';
        $amount = $type === 'depositum'
            ? number_format((float) $booking->deposit_amount, 2, ',', '.')
            : number_format((float) $booking->rental_amount + (float) $booking->extra_days_fee + (float) $booking->environment_fee, 2, ',', '.');
        $admin_url = admin_url('admin.php?page=hkof-bookings&action=view&id=' . $booking->id);
        $subject = 'Opkrævning af ' . $label . ' sendt – lejeaftale nr. ' . $booking->booking_ref;
        $body = "Der er netop sendt en opkrævning af {$label} til {$booking->first_name} {$booking->last_name} (lejeaftale nr. {$booking->booking_ref}).\n\n"
            . "Beløb: kr. {$amount}\n"
            . "Periode: " . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)) . "\n\n"
            . "Husk at holde øje med at indbetalingen kommer ind på kontoen.\n\n"
            . "Se booking: $admin_url";
        wp_mail($to, $subject, $body, self::headers());
    }

    /** Sendes til lejer når bookingen godkendes – med kontrakt-PDF vedhæftet */
    public static function send_contract($booking, $pdf_path) {
        $s = HKOF_Settings::all();
        $subject = 'Lejeaftale nr. ' . $booking->booking_ref . ' – ' . $s['association_name'];
        $body = "Hej {$booking->first_name},\n\n"
            . "Vi har registreret jeres depositum – tak for det. Hermed fremsendes den underskrevne lejeaftale vedr. leje af huset.\n\n"
            . "I er velkommen til at kontakte undertegnede hvis i skulle have nogle spørgsmål.\n\n"
            . "MVH {$s['sender_name']}\n"
            . $s['udlejning_navn'];
        $attachments = [$pdf_path];
        wp_mail($booking->email, $subject, $body, self::headers(), $attachments);
    }

    /** Sendes automatisk (via cron) eller manuelt X dage før arrangementet – faktura vedhæftet */
    public static function send_invoice($booking, $pdf_path) {
        $s = HKOF_Settings::all();
        $subject = 'Faktura for leje af huset – ' . $booking->booking_ref;
        $total = number_format((float) $booking->rental_amount + (float) $booking->extra_days_fee + (float) $booking->environment_fee, 2, ',', '.');
        $body = "Hej {$booking->first_name},\n\n"
            . "Vedhæftet finder du fakturaen for jeres leje af huset d. " . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ".\n"
            . "Beløbet på kr. {$total} bedes indbetalt senest {$s['invoice_days_before']} dage før arrangementet – husk lejeaftale nr. {$booking->booking_ref} på indbetalingen.\n\n"
            . "MVH {$s['sender_name']}";
        $attachments = [$pdf_path];
        wp_mail($booking->email, $subject, $body, self::headers(), $attachments);
    }

    /** Sendes hvis en booking afvises */
    public static function send_rejection($booking) {
        $s = HKOF_Settings::all();
        $subject = 'Vedr. jeres bookingforespørgsel – ' . $s['association_name'];
        $reason = $booking->rejected_reason ? "\n\nBegrundelse: {$booking->rejected_reason}" : '';
        $body = "Hej {$booking->first_name},\n\n"
            . "Desværre kan vi ikke imødekomme jeres bookingforespørgsel for perioden "
            . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ' - ' . date_i18n('d.m.Y', strtotime($booking->check_out_date)) . "."
            . $reason
            . "\n\nI er velkomne til at kontakte os for en alternativ dato.\n\nMVH {$s['sender_name']}";
        wp_mail($booking->email, $subject, $body, self::headers());
    }

    /** Kvittering til lejer når selve lejen er registreret som betalt */
    public static function send_final_payment_confirmation($booking) {
        $s = HKOF_Settings::all();
        $subject = 'Betaling modtaget – lejeaftale nr. ' . $booking->booking_ref;
        $body = "Hej {$booking->first_name},\n\n"
            . "Vi bekræfter at have modtaget betaling for jeres leje af huset. Lejeaftale nr. {$booking->booking_ref} er dermed fuldt betalt, og alt er klar til jeres arrangement d. "
            . date_i18n('d.m.Y', strtotime($booking->check_in_date)) . ".\n\n"
            . "Vi glæder os til at se jer!\n\n"
            . "MVH {$s['sender_name']}";
        wp_mail($booking->email, $subject, $body, self::headers());
    }
}
