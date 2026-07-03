<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Drive-integration.
 *
 * Ansvar:
 * 1) OAuth-forbindelse til en Google-konto (bruger selv opretter et Google
 *    Cloud-projekt med Client ID/Secret - se render_settings_page for guide).
 * 2) Automatisk upload af hver genereret kontrakt-PDF til en valgt Drive-mappe,
 *    organiseret i undermapper pr. årstal (fx "Lejekontrakter/2026/Lejeaftale-...pdf").
 * 3) Backup/restore: eksporterer hele bookingdatabasen som JSON til en valgt
 *    backup-mappe, med mulighed for manuel kørsel, planlagt automatisk kørsel
 *    (dagligt/ugentligt via WP-Cron) og gendannelse fra en tidligere backup.
 *
 * Bruger IKKE Google's officielle API-client-bibliotek - alt sker via
 * WordPress's indbyggede wp_remote_* HTTP-funktioner mod Google's REST-API,
 * så pluginnet forbliver let og uden ekstra Composer-afhængigheder.
 */
class HKOF_GDrive {

    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const API_BASE    = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    // Fuld Drive-adgang er nødvendig for at kunne bruge en EKSISTERENDE mappe
    // (indsat som link/ID) - den mere restriktive 'drive.file'-scope ville kun
    // give adgang til mapper/filer som appen selv har oprettet.
    const SCOPE = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email';

    const CRON_HOOK = 'hkof_gdrive_scheduled_backup';

    public static function init() {
        add_action('admin_post_hkof_gdrive_save_credentials', [__CLASS__, 'handle_save_credentials']);
        add_action('admin_post_hkof_gdrive_connect', [__CLASS__, 'handle_connect']);
        add_action('admin_post_hkof_gdrive_callback', [__CLASS__, 'handle_callback']);
        add_action('admin_post_hkof_gdrive_disconnect', [__CLASS__, 'handle_disconnect']);
        add_action('admin_post_hkof_gdrive_set_folder', [__CLASS__, 'handle_set_folder']);
        add_action('admin_post_hkof_gdrive_backup_now', [__CLASS__, 'handle_backup_now']);
        add_action('admin_post_hkof_gdrive_restore', [__CLASS__, 'handle_restore']);
        add_action('admin_post_hkof_gdrive_save_schedule', [__CLASS__, 'handle_save_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_backup']);
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    // ─── STATUS-HJÆLPERE ─────────────────────────────────────
    public static function redirect_uri() {
        return admin_url('admin-post.php?action=hkof_gdrive_callback');
    }

    public static function is_configured() {
        return (bool) get_option('hkof_gdrive_client_id') && (bool) get_option('hkof_gdrive_client_secret');
    }

    public static function is_connected() {
        return (bool) get_option('hkof_gdrive_refresh_token');
    }

    public static function connected_email() {
        return get_option('hkof_gdrive_connected_email', '');
    }

    // ─── GEM CLIENT ID / SECRET ──────────────────────────────
    public static function handle_save_credentials() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_save_credentials');

        update_option('hkof_gdrive_client_id', sanitize_text_field($_POST['client_id'] ?? ''));
        $secret = sanitize_text_field($_POST['client_secret'] ?? '');
        // Gem kun secret hvis feltet rent faktisk er udfyldt, så vi ikke overskriver
        // et allerede gemt secret med tomt hvis admin blot gemmer siden igen
        if ($secret !== '') {
            update_option('hkof_gdrive_client_secret', $secret);
        }

        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&credentials_saved=1'));
        exit;
    }

    // ─── OAUTH-FLOW ──────────────────────────────────────────
    public static function handle_connect() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_connect');

        $client_id = get_option('hkof_gdrive_client_id');
        if (!$client_id) wp_die('Udfyld Google Client ID og Client Secret først.');

        $state = wp_create_nonce('hkof_gdrive_oauth_state');
        update_option('hkof_gdrive_oauth_state', $state);

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent select_account',
            'state'         => $state,
        ];
        wp_redirect(self::AUTH_URL . '?' . http_build_query($params));
        exit;
    }

    public static function handle_callback() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');

        if (!empty($_GET['error'])) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Google afviste forbindelsen: ' . sanitize_text_field($_GET['error']))));
            exit;
        }

        $state = sanitize_text_field($_GET['state'] ?? '');
        if (!$state || $state !== get_option('hkof_gdrive_oauth_state')) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Ugyldig sikkerhedstoken - prøv at forbinde igen.')));
            exit;
        }
        delete_option('hkof_gdrive_oauth_state');

        $code = sanitize_text_field($_GET['code'] ?? '');
        if (!$code) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Ingen kode modtaget fra Google.')));
            exit;
        }

        $resp = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'code'          => $code,
                'client_id'     => get_option('hkof_gdrive_client_id'),
                'client_secret' => get_option('hkof_gdrive_client_secret'),
                'redirect_uri'  => self::redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($resp)) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Fejl ved kontakt til Google: ' . $resp->get_error_message())));
            exit;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($data['access_token'])) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Kunne ikke hente adgangstoken fra Google. Tjek at Client ID/Secret og redirect URI er korrekte.')));
            exit;
        }

        update_option('hkof_gdrive_access_token', $data['access_token']);
        update_option('hkof_gdrive_token_expires', time() + (int) ($data['expires_in'] ?? 3600) - 60);
        if (!empty($data['refresh_token'])) {
            update_option('hkof_gdrive_refresh_token', $data['refresh_token']);
        }

        $email = self::fetch_connected_email($data['access_token']);
        if ($email) update_option('hkof_gdrive_connected_email', $email);

        // Opret standardmapper automatisk første gang, så der er noget at bruge med det samme
        if (!get_option('hkof_gdrive_contracts_folder_id')) {
            self::ensure_default_folders();
        }

        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&connected=1'));
        exit;
    }

    public static function handle_disconnect() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_disconnect');
        foreach (['hkof_gdrive_access_token', 'hkof_gdrive_refresh_token', 'hkof_gdrive_token_expires', 'hkof_gdrive_connected_email'] as $opt) {
            delete_option($opt);
        }
        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&disconnected=1'));
        exit;
    }

    private static function fetch_connected_email($access_token) {
        $resp = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
        ]);
        if (is_wp_error($resp)) return '';
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return $data['email'] ?? '';
    }

    /** Returnerer et gyldigt access token, opdaterer automatisk via refresh_token hvis udløbet */
    private static function access_token() {
        $expires = (int) get_option('hkof_gdrive_token_expires', 0);
        if ($expires > time()) {
            return get_option('hkof_gdrive_access_token');
        }
        $refresh_token = get_option('hkof_gdrive_refresh_token');
        if (!$refresh_token) return false;

        $resp = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => get_option('hkof_gdrive_client_id'),
                'client_secret' => get_option('hkof_gdrive_client_secret'),
                'grant_type'    => 'refresh_token',
            ],
        ]);
        if (is_wp_error($resp)) return false;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($data['access_token'])) return false;

        update_option('hkof_gdrive_access_token', $data['access_token']);
        update_option('hkof_gdrive_token_expires', time() + (int) ($data['expires_in'] ?? 3600) - 60);
        return $data['access_token'];
    }

    // ─── DRIVE API-HJÆLPEFUNKTIONER ──────────────────────────
    private static function api_get($path, $query = []) {
        $token = self::access_token();
        if (!$token) return new WP_Error('hkof_gdrive_no_token', 'Ikke forbundet til Google Drive.');
        $url = self::API_BASE . $path;
        if ($query) $url .= '?' . http_build_query($query);
        $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $token]]);
        if (is_wp_error($resp)) return $resp;
        return json_decode(wp_remote_retrieve_body($resp), true);
    }

    private static function api_post_json($path, $body) {
        $token = self::access_token();
        if (!$token) return new WP_Error('hkof_gdrive_no_token', 'Ikke forbundet til Google Drive.');
        $resp = wp_remote_post(self::API_BASE . $path, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) return $resp;
        return json_decode(wp_remote_retrieve_body($resp), true);
    }

    /** Finder en mappe med et givent navn under en given parent, eller opretter den hvis den ikke findes */
    public static function find_or_create_folder($name, $parent_id = null) {
        $safe_name = str_replace("'", "\\'", $name);
        $q = "mimeType='application/vnd.google-apps.folder' and name='" . $safe_name . "' and trashed=false";
        if ($parent_id) $q .= " and '" . $parent_id . "' in parents";
        $result = self::api_get('/files', ['q' => $q, 'fields' => 'files(id,name)']);
        if (is_wp_error($result)) return $result;
        if (!empty($result['files'][0]['id'])) return $result['files'][0]['id'];

        $body = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parent_id) $body['parents'] = [$parent_id];
        $created = self::api_post_json('/files', $body);
        if (is_wp_error($created)) return $created;
        return $created['id'] ?? false;
    }

    private static function ensure_default_folders() {
        $contracts = self::find_or_create_folder('Lokale Booking - Lejekontrakter');
        if (!is_wp_error($contracts) && $contracts) {
            update_option('hkof_gdrive_contracts_folder_id', $contracts);
            update_option('hkof_gdrive_contracts_folder_name', 'Lokale Booking - Lejekontrakter');
        }
        $backup = self::find_or_create_folder('Lokale Booking - Backup');
        if (!is_wp_error($backup) && $backup) {
            update_option('hkof_gdrive_backup_folder_id', $backup);
            update_option('hkof_gdrive_backup_folder_name', 'Lokale Booking - Backup');
        }
    }

    /** Validerer at et Drive-mappe-ID findes, er en mappe, og er tilgængeligt for den forbundne konto */
    public static function get_folder_info($folder_id) {
        $result = self::api_get('/files/' . rawurlencode($folder_id), ['fields' => 'id,name,mimeType']);
        if (is_wp_error($result) || empty($result['id'])) return false;
        if (($result['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') return false;
        return $result;
    }

    /** Udtrækker et Drive-mappe-ID fra enten et rent ID eller et delt Drive-link */
    public static function extract_id_from_input($input) {
        $input = trim($input);
        if (preg_match('~/folders/([a-zA-Z0-9_-]+)~', $input, $m)) return $m[1];
        if (preg_match('~[?&]id=([a-zA-Z0-9_-]+)~', $input, $m)) return $m[1];
        return $input; // antag det allerede er et rent ID
    }

    /** Finder (eller opretter) årsmappen for kontrakter og returnerer dens ID, med simpel cache */
    private static function year_folder_id($year) {
        $root = get_option('hkof_gdrive_contracts_folder_id');
        if (!$root) return new WP_Error('hkof_gdrive_no_folder', 'Der er ikke valgt en kontraktmappe i Google Drive-indstillingerne.');
        $cache = get_option('hkof_gdrive_year_folder_cache', []);
        if (!empty($cache[$year])) return $cache[$year];

        $folder_id = self::find_or_create_folder((string) $year, $root);
        if (is_wp_error($folder_id)) return $folder_id;
        if (!$folder_id) return new WP_Error('hkof_gdrive_folder_failed', 'Kunne ikke oprette årsmappen "' . $year . '" i Google Drive.');

        $cache[$year] = $folder_id;
        update_option('hkof_gdrive_year_folder_cache', $cache);
        return $folder_id;
    }

    /** Uploader en fil (simpel multipart-upload) til en given mappe. Returnerer Drive fil-ID eller en WP_Error med årsagen. */
    private static function upload_file($local_path, $filename, $parent_id, $mime_type = 'application/octet-stream') {
        $token = self::access_token();
        if (!$token) return new WP_Error('hkof_gdrive_no_token', 'Kunne ikke hente/forny adgangstoken - forbindelsen til Google er muligvis afbrudt eller udløbet.');
        if (!file_exists($local_path)) return new WP_Error('hkof_gdrive_no_file', 'PDF-filen blev ikke fundet lokalt på serveren.');

        $boundary = wp_generate_password(24, false);
        $metadata = wp_json_encode(['name' => $filename, 'parents' => [$parent_id]]);
        $file_content = file_get_contents($local_path);

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $mime_type\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--$boundary--";

        $resp = wp_remote_post(self::UPLOAD_BASE . '/files?uploadType=multipart', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);
        if (is_wp_error($resp)) return $resp;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($data['id'])) {
            $reason = $data['error']['message'] ?? 'Google Drive returnerede ingen fejlbesked, men uploaden mislykkedes.';
            return new WP_Error('hkof_gdrive_upload_failed', $reason);
        }
        return $data['id'];
    }

    /**
     * Uploader en genereret kontrakt-PDF til årsmappen under den valgte
     * kontraktmappe (fx "Lejekontrakter/2026/Lejeaftale-HKOF-2026-0007-...pdf").
     *
     * VIGTIGT: Denne funktion må ALDRIG stoppe eller forsinke selve
     * mail-afsendelsen til lejeren, hvis Google Drive skulle være utilgængeligt
     * - hele flowet (godkendelse, kontrakt, faktura osv.) kører altid færdigt
     * uanset om Drive-uploaden lykkes. I stedet gemmes resultatet direkte på
     * bookingen:
     *   - Lykkes uploaden: gdrive_contract_synced_at sættes, gdrive_contract_error ryddes.
     *   - Fejler uploaden: gdrive_contract_error sættes med en læsbar årsag, så
     *     admin kan se det i booking-oversigten/detaljesiden og bruge
     *     "Prøv igen"-knappen. En daglig baggrundsjob (se HKOF_Cron) forsøger
     *     desuden automatisk igen for alle bookinger med en fejlet upload.
     *
     * Returnerer true ved succes, false ved fejl, null hvis integrationen slet
     * ikke er sat op/forbundet (bevidst fravalgt - regnes ikke som en fejl).
     */
    public static function upload_contract($booking, $pdf_path) {
        if (!self::is_connected() || !get_option('hkof_gdrive_contracts_folder_id')) return null;

        try {
            $year = date('Y', strtotime($booking->check_in_date));
            $folder_id = self::year_folder_id($year);
            if (is_wp_error($folder_id)) throw new Exception($folder_id->get_error_message());

            $filename = 'Lejeaftale-' . $booking->booking_ref . '-' . sanitize_file_name($booking->last_name) . '.pdf';
            $file_id = self::upload_file($pdf_path, $filename, $folder_id, 'application/pdf');
            if (is_wp_error($file_id)) throw new Exception($file_id->get_error_message());

            HKOF_DB::update($booking->id, [
                'gdrive_contract_synced_at' => current_time('mysql'),
                'gdrive_contract_error'     => null,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[Lokale Booking] Google Drive-upload af kontrakt fejlede (booking #' . $booking->id . '): ' . $e->getMessage());
            HKOF_DB::update($booking->id, ['gdrive_contract_error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Forsøger igen at uploade kontrakten for alle bookinger hvor sidste
     * forsøg fejlede. Kaldes automatisk dagligt (se HKOF_Cron::run_invoice_check)
     * og kan også trigges manuelt via "Prøv igen"-knappen på en enkelt booking.
     */
    public static function retry_failed_contract_uploads() {
        if (!self::is_connected() || !get_option('hkof_gdrive_contracts_folder_id')) return;
        $bookings = HKOF_DB::get_bookings_with_gdrive_error();
        foreach ($bookings as $booking) {
            $pdf_path = self::locate_or_regenerate_contract($booking);
            if ($pdf_path) self::upload_contract($booking, $pdf_path);
        }
    }

    /** Finder den allerede genererede kontrakt-PDF på serveren, eller genskaber den hvis filen mangler */
    public static function locate_or_regenerate_contract($booking) {
        $upload_dir = wp_upload_dir();
        $path = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/lejeaftale-' . $booking->booking_ref . '.pdf';
        if (file_exists($path)) return $path;
        if (!class_exists('HKOF_PDF')) return false;
        return HKOF_PDF::generate_contract($booking);
    }

    // ─── MAPPEVALG ───────────────────────────────────────────
    public static function handle_set_folder() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_set_folder');

        $type = ($_POST['folder_type'] ?? '') === 'backup' ? 'backup' : 'contracts';
        $input = sanitize_text_field($_POST['folder_input'] ?? '');
        if (!$input) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Indsæt venligst et mappe-link eller ID.')));
            exit;
        }
        $folder_id = self::extract_id_from_input($input);
        $info = self::get_folder_info($folder_id);
        if (!$info) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Mappen blev ikke fundet, eller den er ikke tilgængelig for den forbundne Google-konto.')));
            exit;
        }

        update_option('hkof_gdrive_' . $type . '_folder_id', $folder_id);
        update_option('hkof_gdrive_' . $type . '_folder_name', $info['name']);
        if ($type === 'contracts') {
            delete_option('hkof_gdrive_year_folder_cache'); // ny rodmappe -> nulstil årsmappe-cache
        }

        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&folder_saved=1'));
        exit;
    }

    // ─── BACKUP / RESTORE ────────────────────────────────────
    public static function handle_backup_now() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_backup_now');
        $ok = self::run_backup();
        $msg = $ok ? 'backed_up=1' : 'error=' . rawurlencode('Backup fejlede - tjek at Google Drive er forbundet og en backup-mappe er valgt.');
        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&' . $msg));
        exit;
    }

    /** Eksporterer alle bookinger som JSON og uploader til backup-mappen. Bruges af både knap og cron. */
    public static function run_backup() {
        if (!self::is_connected()) return false;
        $backup_folder = get_option('hkof_gdrive_backup_folder_id');
        if (!$backup_folder) return false;

        $bookings = HKOF_DB::all_for_export();
        $export = [
            'exported_at'    => current_time('mysql'),
            'plugin_version' => HKOF_BOOKING_VERSION,
            'bookings'       => $bookings,
        ];

        $tmp_path = wp_tempnam('hkof-backup');
        file_put_contents($tmp_path, wp_json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $filename = 'backup-' . date('Y-m-d-Hi') . '.json';
        $file_id = self::upload_file($tmp_path, $filename, $backup_folder, 'application/json');
        @unlink($tmp_path);

        if (is_wp_error($file_id) || !$file_id) return false;

        update_option('hkof_gdrive_last_backup_at', current_time('mysql'));
        return true;
    }

    public static function run_scheduled_backup() {
        self::run_backup();
    }

    /** Lister backup-filer i backup-mappen, nyeste først */
    public static function list_backups() {
        $backup_folder = get_option('hkof_gdrive_backup_folder_id');
        if (!$backup_folder || !self::is_connected()) return [];
        $result = self::api_get('/files', [
            'q'        => "'" . $backup_folder . "' in parents and trashed=false and name contains 'backup-'",
            'fields'   => 'files(id,name,createdTime,size)',
            'orderBy'  => 'createdTime desc',
            'pageSize' => 50,
        ]);
        if (is_wp_error($result)) return [];
        return $result['files'] ?? [];
    }

    private static function download_file($file_id) {
        $token = self::access_token();
        if (!$token) return false;
        $resp = wp_remote_get(self::API_BASE . '/files/' . rawurlencode($file_id) . '?alt=media', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($resp)) return false;
        return wp_remote_retrieve_body($resp);
    }

    public static function handle_restore() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_restore');

        if (empty($_POST['confirm_restore'])) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Du skal bekræfte at du forstår at dette overskriver alt nuværende bookingdata.')));
            exit;
        }
        $file_id = sanitize_text_field($_POST['backup_file_id'] ?? '');
        if (!$file_id) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Vælg venligst en backup-fil.')));
            exit;
        }

        $content = self::download_file($file_id);
        if (!$content) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Kunne ikke hente backup-filen fra Google Drive.')));
            exit;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['bookings'])) {
            wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&error=' . rawurlencode('Backup-filen ser ikke gyldig ud.')));
            exit;
        }

        $count = HKOF_DB::restore_from_export($data['bookings']);
        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&restored=' . (int) $count));
        exit;
    }

    // ─── SCHEDULERING ────────────────────────────────────────
    public static function handle_save_schedule() {
        if (!current_user_can('manage_options')) wp_die('Ingen adgang');
        check_admin_referer('hkof_gdrive_save_schedule');

        $freq = in_array($_POST['frequency'] ?? 'off', ['off', 'daily', 'weekly'], true) ? $_POST['frequency'] : 'off';
        update_option('hkof_gdrive_backup_frequency', $freq);

        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);

        if ($freq === 'daily') {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::CRON_HOOK);
        } elseif ($freq === 'weekly') {
            wp_schedule_event(strtotime('next monday 03:00'), 'weekly', self::CRON_HOOK);
        }

        wp_safe_redirect(admin_url('admin.php?page=hkof-gdrive&schedule_saved=1'));
        exit;
    }

    // ─── ADMIN-SIDE ──────────────────────────────────────────
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $client_id     = get_option('hkof_gdrive_client_id', '');
        $has_secret    = (bool) get_option('hkof_gdrive_client_secret');
        $connected     = self::is_connected();
        $email         = self::connected_email();
        $contracts_id  = get_option('hkof_gdrive_contracts_folder_id');
        $contracts_nm  = get_option('hkof_gdrive_contracts_folder_name');
        $backup_id     = get_option('hkof_gdrive_backup_folder_id');
        $backup_nm     = get_option('hkof_gdrive_backup_folder_name');
        $frequency     = get_option('hkof_gdrive_backup_frequency', 'off');
        $last_backup   = get_option('hkof_gdrive_last_backup_at');
        ?>
        <div class="wrap hkof-wrap">
            <h1>Lokale Booking – Google Drive</h1>

            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(urldecode($_GET['error'])); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['connected'])): ?>
                <div class="notice notice-success"><p>Forbundet til Google Drive.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['disconnected'])): ?>
                <div class="notice notice-success"><p>Forbindelsen til Google Drive er fjernet.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['credentials_saved'])): ?>
                <div class="notice notice-success"><p>Google-oplysninger gemt.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['folder_saved'])): ?>
                <div class="notice notice-success"><p>Mappe gemt.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['backed_up'])): ?>
                <div class="notice notice-success"><p>Backup gennemført og uploadet til Google Drive.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['schedule_saved'])): ?>
                <div class="notice notice-success"><p>Planlagt backup gemt.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['restored'])): ?>
                <div class="notice notice-success"><p><?php echo (int) $_GET['restored']; ?> bookinger gendannet fra backup.</p></div>
            <?php endif; ?>

            <p class="description">
                Forbind systemet til en Google-konto for automatisk at gemme en kopi af alle lejekontrakter i Google Drive
                (organiseret pr. årstal) i det øjeblik de sendes til lejeren, samt for at kunne tage og gendanne backup af
                hele bookingdatabasen.
            </p>

            <!-- ─── TRIN 1: GOOGLE-OPLYSNINGER ─────────────────────── -->
            <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:18px 0;background:#fff">
                <h2 style="margin-top:0">1. Google Cloud-oplysninger</h2>
                <?php if (!$client_id || !$has_secret): ?>
                <div style="background:#f0f6fc;border:1px solid #c3d9ec;border-radius:6px;padding:12px 14px;margin-bottom:14px">
                    <p style="margin-top:0"><strong>Sådan opretter I jeres eget Google-forbindelses-nøglepar (5 min, gratis):</strong></p>
                    <ol style="margin-bottom:0">
                        <li>Gå til <a href="https://console.cloud.google.com/projectcreate" target="_blank">Google Cloud Console</a> og opret et nyt projekt (fx "Lokale Booking").</li>
                        <li>Gå til <em>APIs &amp; Services → Library</em>, søg efter <strong>Google Drive API</strong> og klik "Enable".</li>
                        <li>Gå til <em>APIs &amp; Services → OAuth consent screen</em>. Vælg "External", udfyld app-navn + jeres email, og under "Test users" tilføj den Google-konto I vil forbinde med. (I ser en "Google har ikke verificeret denne app"-advarsel ved login - det er normalt og helt trygt, da det er jeres egen private forbindelse.)</li>
                        <li>Gå til <em>APIs &amp; Services → Credentials → Create Credentials → OAuth client ID</em>. Vælg type <strong>Web application</strong>.</li>
                        <li>Under <strong>Authorized redirect URIs</strong>, indsæt præcis denne adresse:<br>
                            <code style="user-select:all;background:#fff;padding:4px 8px;border:1px solid #ddd;display:inline-block;margin-top:4px"><?php echo esc_html(self::redirect_uri()); ?></code>
                        </li>
                        <li>Kopiér det viste <strong>Client ID</strong> og <strong>Client Secret</strong> ind i felterne herunder.</li>
                    </ol>
                </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hkof_gdrive_save_credentials">
                    <?php wp_nonce_field('hkof_gdrive_save_credentials'); ?>
                    <table class="form-table">
                        <tr><th>Client ID</th><td><input type="text" name="client_id" class="large-text" value="<?php echo esc_attr($client_id); ?>"></td></tr>
                        <tr><th>Client Secret</th><td><input type="password" name="client_secret" class="regular-text" placeholder="<?php echo $has_secret ? '•••••••• (allerede gemt - lad stå tom for at beholde)' : ''; ?>" autocomplete="off"></td></tr>
                    </table>
                    <?php submit_button('Gem Google-oplysninger'); ?>
                </form>
            </div>

            <!-- ─── TRIN 2: FORBIND KONTO ───────────────────────────── -->
            <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:18px 0;background:#fff">
                <h2 style="margin-top:0">2. Forbind Google-konto</h2>
                <?php if ($connected): ?>
                    <p>✅ Forbundet som <strong><?php echo esc_html($email); ?></strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Fjern forbindelsen til Google Drive? Kontrakter og backups stopper med at blive gemt indtil I forbinder igen.')">
                        <input type="hidden" name="action" value="hkof_gdrive_disconnect">
                        <?php wp_nonce_field('hkof_gdrive_disconnect'); ?>
                        <button type="submit" class="button">🔌 Afbryd forbindelsen</button>
                    </form>
                <?php elseif (self::is_configured()): ?>
                    <p>Ikke forbundet endnu.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="hkof_gdrive_connect">
                        <?php wp_nonce_field('hkof_gdrive_connect'); ?>
                        <button type="submit" class="button button-primary">🔗 Forbind til Google Drive</button>
                    </form>
                <?php else: ?>
                    <p class="description">Udfyld og gem Client ID + Client Secret ovenfor først.</p>
                <?php endif; ?>
            </div>

            <?php if ($connected): ?>
            <!-- ─── TRIN 3: MAPPER ──────────────────────────────────── -->
            <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:18px 0;background:#fff">
                <h2 style="margin-top:0">3. Mapper på Google Drive</h2>

                <h3>📄 Lejekontrakter</h3>
                <p class="description">Hver kontrakt gemmes automatisk i en undermappe pr. årstal (fx "2026") under denne mappe, samtidig med at den sendes til lejeren.</p>
                <?php if ($contracts_id): ?>
                    <p>Nuværende mappe: <strong><?php echo esc_html($contracts_nm ?: $contracts_id); ?></strong> — <a href="https://drive.google.com/drive/folders/<?php echo esc_attr($contracts_id); ?>" target="_blank">Åbn i Google Drive ↗</a></p>
                <?php else: ?>
                    <p><em>Ingen mappe valgt endnu.</em></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="action" value="hkof_gdrive_set_folder">
                    <input type="hidden" name="folder_type" value="contracts">
                    <?php wp_nonce_field('hkof_gdrive_set_folder'); ?>
                    <input type="text" name="folder_input" class="regular-text" placeholder="Indsæt Google Drive mappe-link eller ID">
                    <button type="submit" class="button"><?php echo $contracts_id ? 'Skift mappe' : 'Brug denne mappe'; ?></button>
                </form>

                <h3 style="margin-top:24px">💾 Backup</h3>
                <p class="description">Backup-eksporter af hele bookingdatabasen gemmes her.</p>
                <?php if ($backup_id): ?>
                    <p>Nuværende mappe: <strong><?php echo esc_html($backup_nm ?: $backup_id); ?></strong> — <a href="https://drive.google.com/drive/folders/<?php echo esc_attr($backup_id); ?>" target="_blank">Åbn i Google Drive ↗</a></p>
                <?php else: ?>
                    <p><em>Ingen mappe valgt endnu.</em></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="action" value="hkof_gdrive_set_folder">
                    <input type="hidden" name="folder_type" value="backup">
                    <?php wp_nonce_field('hkof_gdrive_set_folder'); ?>
                    <input type="text" name="folder_input" class="regular-text" placeholder="Indsæt Google Drive mappe-link eller ID">
                    <button type="submit" class="button"><?php echo $backup_id ? 'Skift mappe' : 'Brug denne mappe'; ?></button>
                </form>
                <p class="description">Tip: Åbn <a href="https://drive.google.com" target="_blank">Google Drive</a>, opret eller find den ønskede mappe, og kopiér linket fra adresselinjen (eller "Del"-menuen) ind ovenfor. Mapper der oprettes automatisk ved forbindelse kan efterfølgende omdøbes eller flyttes frit i Drive uden at systemet mister forbindelsen.</p>
            </div>

            <!-- ─── TRIN 4: BACKUP & GENDANNELSE ────────────────────── -->
            <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:18px 0;background:#fff">
                <h2 style="margin-top:0">4. Backup &amp; gendannelse</h2>

                <?php if ($last_backup): ?>
                    <p>Seneste backup: <strong><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($last_backup))); ?></strong></p>
                <?php else: ?>
                    <p><em>Der er endnu ikke kørt en backup.</em></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:20px">
                    <input type="hidden" name="action" value="hkof_gdrive_backup_now">
                    <?php wp_nonce_field('hkof_gdrive_backup_now'); ?>
                    <button type="submit" class="button button-primary" <?php disabled(!$backup_id); ?>>💾 Kør backup nu</button>
                    <?php if (!$backup_id): ?><span class="description"> Vælg en backup-mappe ovenfor først.</span><?php endif; ?>
                </form>

                <h3>⏰ Automatisk planlagt backup</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;align-items:center;margin-bottom:24px">
                    <input type="hidden" name="action" value="hkof_gdrive_save_schedule">
                    <?php wp_nonce_field('hkof_gdrive_save_schedule'); ?>
                    <select name="frequency">
                        <option value="off" <?php selected($frequency, 'off'); ?>>Fra</option>
                        <option value="daily" <?php selected($frequency, 'daily'); ?>>Dagligt (kl. 03:00)</option>
                        <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Ugentligt (mandag kl. 03:00)</option>
                    </select>
                    <button type="submit" class="button">Gem</button>
                </form>

                <h3>♻️ Gendan fra backup</h3>
                <p class="description" style="color:#b91c1c"><strong>OBS:</strong> Gendannelse overskriver ALT nuværende bookingdata med indholdet fra den valgte backup. Brug kun dette til katastrofe-genskabelse.</p>
                <?php
                $backups = self::list_backups();
                if ($backups):
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Er du HELT sikker? Dette overskriver alt nuværende bookingdata med indholdet fra den valgte backup, og kan ikke fortrydes.');">
                    <input type="hidden" name="action" value="hkof_gdrive_restore">
                    <?php wp_nonce_field('hkof_gdrive_restore'); ?>
                    <table class="wp-list-table widefat fixed striped" style="max-width:700px">
                        <thead><tr><th></th><th>Backup</th><th>Oprettet</th><th>Størrelse</th></tr></thead>
                        <tbody>
                        <?php foreach ($backups as $i => $f): ?>
                            <tr>
                                <td><input type="radio" name="backup_file_id" value="<?php echo esc_attr($f['id']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?> required></td>
                                <td><?php echo esc_html($f['name']); ?></td>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($f['createdTime']))); ?></td>
                                <td><?php echo esc_html(size_format((int) ($f['size'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><label><input type="checkbox" name="confirm_restore" value="1" required> Jeg forstår at dette overskriver alt nuværende bookingdata.</label></p>
                    <button type="submit" class="button" style="color:#b91c1c;border-color:#b91c1c">♻️ Gendan valgt backup</button>
                </form>
                <?php else: ?>
                    <p><em>Ingen backups fundet endnu.</em></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
