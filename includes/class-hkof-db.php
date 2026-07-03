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
            extra_days INT UNSIGNED NOT NULL DEFAULT 0,
            extra_days_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            contract_sent_at DATETIME DEFAULT NULL,
            deposit_paid_at DATETIME DEFAULT NULL,
            invoice_sent_at DATETIME DEFAULT NULL,
            invoice_paid_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            deposit_invoice_sent_at DATETIME DEFAULT NULL,
            rejected_reason TEXT DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            gdrive_contract_synced_at DATETIME DEFAULT NULL,
            gdrive_contract_error TEXT DEFAULT NULL,
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
        // Inklusiv overlap-tjek (<=/>=) så udtjekningsdagen for en booking regnes som
        // fuldt optaget (matcher kalenderens visning og understøtter korrekt
        // enkelt-dags bookinger, hvor check_in og check_out er samme dato).
        $sql = "SELECT COUNT(*) FROM $table
                WHERE status NOT IN ('rejected','cancelled')
                AND check_in_date <= %s AND check_out_date >= %s";
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
     * Bookinger hvor kontrakten er sendt (har booking_ref), men hvor sidste
     * forsøg på at gemme en kopi i Google Drive fejlede. Bruges af den
     * daglige retry-cron til automatisk at forsøge igen.
     */
    public static function get_bookings_with_gdrive_error() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            "SELECT * FROM $table
             WHERE gdrive_contract_error IS NOT NULL
             AND gdrive_contract_error != ''
             AND booking_ref IS NOT NULL AND booking_ref != ''"
        );
    }

    // ─── EKSPORT / GENDANNELSE (bruges af Google Drive-backup) ──────────
    /** Henter alle rå bookingdata som associative arrays, klar til JSON-eksport */
    public static function all_for_export() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id ASC", ARRAY_A);
    }

    /** Navnene på tabellens faktiske kolonner - bruges til at filtrere gendannelsesdata sikkert */
    private static function table_columns() {
        global $wpdb;
        $cols = $wpdb->get_col("DESCRIBE " . self::table());
        return $cols ?: [];
    }

    /**
     * Gendanner bookinger fra en backup-eksport. ERSTATTER alt nuværende
     * indhold i tabellen (tømmer den først), så tabellen efter kørslen
     * matcher backup-tidspunktet præcist, med de oprindelige ID'er bevaret.
     * Filtrerer ukendte kolonner væk, så en ældre/nyere backup ikke fejler
     * hvis skemaet er ændret siden backuppen blev taget.
     */
    public static function restore_from_export($rows) {
        global $wpdb;
        if (!is_array($rows) || empty($rows)) return 0;
        $table = self::table();
        $valid_columns = self::table_columns();
        if (!$valid_columns) return 0;

        $wpdb->query("TRUNCATE TABLE $table");

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) continue;
            $filtered = array_intersect_key($row, array_flip($valid_columns));
            if ($wpdb->insert($table, $filtered) !== false) $count++;
        }
        return $count;
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
