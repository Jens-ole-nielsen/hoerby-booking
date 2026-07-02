<?php
if (!defined('ABSPATH')) exit;

/**
 * Database-lag for bookinger.
 * Status-flow: pending -> approved -> deposit_paid -> invoice_sent -> paid -> completed
 *              (eller: pending -> rejected / enhver status -> cancelled)
 */
class HKOF_DB {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . HKOF_BOOKING_TABLE;
    }

    public static function create_table() {
        global $wpdb;
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref VARCHAR(30) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            address VARCHAR(200) NOT NULL,
            postal_city VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            purpose VARCHAR(255) DEFAULT '',
            price_type VARCHAR(20) NOT NULL DEFAULT 'selskab',
            check_in_date DATE NOT NULL,
            check_out_date DATE NOT NULL,
            rental_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            environment_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            contract_sent_at DATETIME DEFAULT NULL,
            deposit_paid_at DATETIME DEFAULT NULL,
            invoice_sent_at DATETIME DEFAULT NULL,
            invoice_paid_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            deposit_invoice_sent_at DATETIME DEFAULT NULL,
            rejected_reason TEXT DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY check_in_date (check_in_date),
            KEY check_out_date (check_out_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function insert($data) {
        global $wpdb;
        $now = current_time('mysql');
        $defaults = [
            'status'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $data = array_merge($defaults, $data);
        $wpdb->insert(self::table(), $data);
        return $wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update(self::table(), $data, ['id' => $id]);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id));
    }

    public static function get_by_ref($ref) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE booking_ref = %s", $ref));
    }

    public static function all($status = null, $limit = 200, $offset = 0) {
        global $wpdb;
        $table = self::table();
        if ($status && $status !== 'all') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY check_in_date ASC LIMIT %d OFFSET %d",
                $status, $limit, $offset
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset
        ));
    }

    public static function counts() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as c FROM $table GROUP BY status", ARRAY_A);
        $out = [];
        foreach ($rows as $r) $out[$r['status']] = (int) $r['c'];
        return $out;
    }

    /**
     * Henter alle bookinger der overlapper en periode og optager kalenderen
     * (bruges til at vise ledig/optaget og til at forhindre dobbeltbooking)
     */
    public static function get_occupied_between($start, $end) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_ref, status, check_in_date, check_out_date, first_name, last_name
             FROM $table
             WHERE status NOT IN ('rejected','cancelled')
             AND check_in_date <= %s AND check_out_date >= %s
             ORDER BY check_in_date ASC",
            $end, $start
        ));
    }

    public static function has_overlap($check_in, $check_out, $exclude_id = null) {
        global $wpdb;
        $table = self::table();
        $sql = "SELECT COUNT(*) FROM $table
                WHERE status NOT IN ('rejected','cancelled')
                AND check_in_date < %s AND check_out_date > %s";
        $params = [$check_out, $check_in];
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params)) > 0;
    }

    /**
     * Genererer næste lejeaftale-nummer, fx HKOF-2026-0007
     */
    public static function next_booking_ref() {
        global $wpdb;
        $table = self::table();
        $year = date('Y');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE booking_ref LIKE %s",
            'HKOF-' . $year . '-%'
        ));
        $next = $count + 1;
        return sprintf('HKOF-%s-%04d', $year, $next);
    }

    /**
     * Bookinger der er bekræftet (depositum betalt) og hvor check_in_date
     * er nøjagtig X dage ude, og hvor der ikke allerede er sendt faktura.
     */
    public static function get_bookings_due_for_invoice($days_before) {
        global $wpdb;
        $table = self::table();
        $target_date = date('Y-m-d', strtotime("+{$days_before} days"));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE status = 'deposit_paid'
             AND invoice_sent_at IS NULL
             AND check_in_date <= %s",
            $target_date
        ));
    }
}
