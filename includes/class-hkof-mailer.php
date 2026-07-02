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

    /** Sendes til lejer når bookingen godkendes – med kontrakt-PDF vedhæftet */
    public static function send_contract($booking, $pdf_path) {
        $s = HKOF_Settings::all();
        $subject = 'Lejeaftale nr. ' . $booking->booking_ref . ' – ' . $s['association_name'];
        $body = "Hermed fremsendes lejeaftale vedr. leje af huset. Bemærk lejeaftalen først er gyldig når depositum er indbetalt.\n\n"
            . "I er velkommen til at kontakte undertegnede hvis i skulle have nogle spørgsmål.\n\n"
            . "MVH {$s['sender_name']}\n"
            . $s['udlejning_navn'];
        $attachments = [$pdf_path];
        wp_mail($booking->email, $subject, $body, self::headers(), $attachments);
    }

    /** Sendes automatisk (via cron) 14 dage før arrangementet – faktura vedhæftet */
    public static function send_invoice($booking, $pdf_path) {
        $s = HKOF_Settings::all();
        $subject = 'Faktura for leje af huset – ' . $booking->booking_ref;
        $total = number_format((float) $booking->rental_amount + (float) $booking->environment_fee, 2, ',', '.');
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

    /** Kvittering til lejer når depositum er registreret som modtaget */
    public static function send_deposit_confirmation($booking) {
        $s = HKOF_Settings::all();
        $subject = 'Depositum modtaget – lejeaftale nr. ' . $booking->booking_ref;
        $body = "Hej {$booking->first_name},\n\n"
            . "Vi bekræfter at have modtaget jeres depositum. Lejeaftalen nr. {$booking->booking_ref} er dermed gyldig.\n"
            . "I modtager en faktura for den resterende lejeafgift ca. {$s['invoice_days_before']} dage før arrangementet.\n\n"
            . "MVH {$s['sender_name']}";
        wp_mail($booking->email, $subject, $body, self::headers());
    }
}
