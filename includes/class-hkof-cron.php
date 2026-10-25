<?php
if (!defined('ABSPATH')) exit;

/**
 * Daglig automatisk kørsel: sender faktura X dage før arrangementet
 * for alle bookinger hvor depositum er registreret modtaget, og skifter
 * status efter arrangementsdatoen til tilbagebetaling/afsluttet.
 */
class HKOF_Cron {

    const HOOK = 'hkof_daily_invoice_check';

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run_daily']);
        // Kør også status-skift når admin åbner bookinglisten, så man ikke
        // behøver vente til næste morgen-cron efter arrangementsdatoen.
        add_action('load-toplevel_page_hkof-bookings', [__CLASS__, 'apply_post_event_statuses']);
        add_action('load-lokale-booking_page_hkof-bookings', [__CLASS__, 'apply_post_event_statuses']);
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

    public static function run_daily() {
        self::run_invoice_check();
        self::apply_post_event_statuses();
        // Selvhelbredende: forsøg automatisk igen for enhver booking hvor
        // kontrakten tidligere fejlede med at blive gemt i Google Drive
        // (fx pga. midlertidigt netværksudfald eller udløbet token).
        HKOF_GDrive::retry_failed_contract_uploads();
    }

    /** Bagudkompatibilitet hvis noget stadig kalder det gamle hook-navn */
    public static function run_invoice_check() {
        $s = HKOF_Settings::all();
        $days_before = (int) $s['invoice_days_before'];
        $due = HKOF_DB::get_bookings_due_for_invoice($days_before);

        foreach ($due as $booking) {
            $pdf_path = HKOF_PDF::generate_invoice($booking);
            $sent = HKOF_Mailer::send_invoice($booking, $pdf_path);
            if (!$sent) {
                continue;
            }
            HKOF_Mailer::notify_association_invoice_sent($booking, 'leje');
            HKOF_DB::update($booking->id, [
                'status'          => 'invoice_sent',
                'invoice_sent_at' => current_time('mysql'),
            ]);
        }
    }

    /**
     * Når arrangementsdatoen (check_out) er passeret:
     * - depositum > 0  → "Tilbagebetaling af depositum mangler"
     * - depositum = 0  → "Afsluttet" (fx begravelser uden depositum)
     */
    public static function apply_post_event_statuses() {
        $due = HKOF_DB::get_bookings_past_event_awaiting_close();
        foreach ($due as $booking) {
            $deposit = (float) $booking->deposit_amount;
            if ($deposit > 0) {
                HKOF_DB::update($booking->id, ['status' => 'refund_pending']);
            } else {
                HKOF_DB::update($booking->id, [
                    'status'       => 'completed',
                    'completed_at' => current_time('mysql'),
                ]);
            }
        }
    }
}
