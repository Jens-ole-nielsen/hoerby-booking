<?php
if (!defined('ABSPATH')) exit;

/**
 * Daglig automatisk kørsel: sender faktura X dage før arrangementet
 * for alle bookinger hvor depositum er registreret modtaget.
 */
class HKOF_Cron {

    const HOOK = 'hkof_daily_invoice_check';

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run_invoice_check']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 06:00'), 'daily', self::HOOK);
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::HOOK);
    }

    public static function run_invoice_check() {
        $s = HKOF_Settings::all();
        $days_before = (int) $s['invoice_days_before'];
        $due = HKOF_DB::get_bookings_due_for_invoice($days_before);

        foreach ($due as $booking) {
            $pdf_path = HKOF_PDF::generate_invoice($booking);
            HKOF_Mailer::send_invoice($booking, $pdf_path);
            HKOF_Mailer::notify_association_invoice_sent($booking, 'leje');
            HKOF_DB::update($booking->id, [
                'status'          => 'invoice_sent',
                'invoice_sent_at' => current_time('mysql'),
            ]);
        }

        // Selvhelbredende: forsøg automatisk igen for enhver booking hvor
        // kontrakten tidligere fejlede med at blive gemt i Google Drive
        // (fx pga. midlertidigt netværksudfald eller udløbet token).
        HKOF_GDrive::retry_failed_contract_uploads();
    }
}
