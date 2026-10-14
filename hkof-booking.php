<?php
/**
 * Plugin Name: Lokale Booking
 * Plugin URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 * Description: Booking-system til udlejning af hele huset. Godkendelsesflow, automatisk kontrakt-PDF, manuel depositum-registrering, automatisk faktura 3 uger før arrangementet, samt Google Drive-integration til automatisk kontrakt-backup og planlagt databackup, redigerbare kontrakttekster og logo i alle mails.
 * Version: 1.12.3
 * Author: Fair IT
 * Author URI: https://fair-it.dk
 * Text Domain: hkof-booking
 * Update URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 */

if (!defined('ABSPATH')) exit; // Ingen direkte adgang

define('HKOF_BOOKING_VERSION', '1.12.3');
define('HKOF_BOOKING_FILE', __FILE__);
define('HKOF_BOOKING_DIR', plugin_dir_path(__FILE__));
define('HKOF_BOOKING_URL', plugin_dir_url(__FILE__));
define('HKOF_BOOKING_TABLE', 'hkof_bookings');

require_once HKOF_BOOKING_DIR . 'includes/class-hkof-db.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-settings.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-pdf.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-mailer.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-admin.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-public.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-frontend-admin.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-cron.php';
require_once HKOF_BOOKING_DIR . 'includes/class-hkof-gdrive.php';

// ─── AUTOMATISKE OPDATERINGER VIA GITHUB ───────────────────
// Gør at WP-admin viser "opdatering tilgængelig" ligesom et almindeligt
// plugin, når der pushes en ny version til GitHub-repoet.
require_once HKOF_BOOKING_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$hkofUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Jens-ole-nielsen/hoerby-booking/',
    __FILE__,
    'hkof-booking'
);
$hkofUpdateChecker->setBranch('main');

// GitHub rate-limiter uautentificerede API-kald til 60/time PR. IP – på delt
// hosting kan denne kvote let være brugt op. Et PAT hæver kvoten til 5000/time.
// Vi sender tokenet som Bearer (ikke PUC's Basic-auth med repo-brugernavn),
// fordi Basic 401'er hvis tokenet tilhører en anden GitHub-bruger eller er
// et fine-grained PAT. Ved 401 droppes tokenet, så det offentlige repo
// stadig kan tjekkes uden login.
$hkofGithubToken = trim((string) HKOF_Settings::get('github_update_token'));
add_filter('puc_request_info_options-hkof-booking', function ($options) use (&$hkofGithubToken) {
    if ($hkofGithubToken === '') {
        return $options;
    }
    if (!isset($options['headers']) || !is_array($options['headers'])) {
        $options['headers'] = [];
    }
    $options['headers']['Authorization'] = 'Bearer ' . $hkofGithubToken;
    if (empty($options['timeout']) || (int) $options['timeout'] < 10) {
        $options['timeout'] = 10;
    }
    return $options;
});
add_action('puc_api_error', function ($error, $response = null, $url = '', $slug = '') use (&$hkofGithubToken) {
    if ($slug !== '' && $slug !== 'hkof-booking') {
        return;
    }
    $code = $response ? (int) wp_remote_retrieve_response_code($response) : 0;
    if ($code !== 401 || $hkofGithubToken === '') {
        return;
    }
    $hkofGithubToken = '';
    HKOF_Settings::update(['github_update_token' => '']);
    set_transient('hkof_github_token_invalid', 1, WEEK_IN_SECONDS);
}, 10, 4);

// ─── AKTIVERING / DEAKTIVERING ─────────────────────────────
register_activation_hook(__FILE__, function () {
    HKOF_DB::create_table();
    HKOF_Settings::set_defaults();
    HKOF_Cron::schedule();
    update_option('hkof_booking_db_version', HKOF_BOOKING_VERSION);
});

register_deactivation_hook(__FILE__, function () {
    HKOF_Cron::unschedule();
    HKOF_GDrive::unschedule();
});

// ─── AUTOMATISK DATABASE-OPGRADERING ────────────────────────
// Køres ved hver sideindlæsning og opdaterer databasetabellen (tilføjer
// evt. nye kolonner) hvis pluginnet er blevet opdateret via GitHub uden
// at der er sket en deaktiver/aktiver. dbDelta er sikker at køre igen
// og igen — den sletter aldrig data, kun tilføjer/retter struktur.
add_action('plugins_loaded', function () {
    $installed = get_option('hkof_booking_db_version');
    if ($installed !== HKOF_BOOKING_VERSION) {
        HKOF_DB::create_table();
        // Kun ved opgradering fra før 1.12.0 — må ikke køre igen ved senere versioner
        if (!$installed || version_compare((string) $installed, '1.12.0', '<')) {
            HKOF_Settings::migrate_1120();
        }
        update_option('hkof_booking_db_version', HKOF_BOOKING_VERSION);
    }
    HKOF_PDF::ensure_contracts_dir();
}, 5);

// ─── OPSTART ────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    HKOF_Admin::init();
    HKOF_Public::init();
    HKOF_Frontend_Admin::init();
    HKOF_Cron::init();
    HKOF_GDrive::init();
});

// ─── ASSETS ─────────────────────────────────────────────────
// Indlæses KUN på sider der faktisk indeholder [hkof_booking]-shortcoden,
// så pluginnet ikke tilføjer CSS/JS på resten af sitet (fx sider med det
// gamle bookingsystem) mens begge kører side om side under test.
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) return;
    global $post;
    if (!$post) return;
    $has_public = has_shortcode($post->post_content, 'hkof_booking');
    $has_fe_admin = has_shortcode($post->post_content, 'hkof_booking_admin');
    if (!$has_public && !$has_fe_admin) return;

    // Kalender-CSS'en (hkof-cal-*) bruges af BÅDE den offentlige bookingwidget og
    // front-end admin-dashboardets booking-forhåndsvisning, så style.css loades for begge.
    wp_enqueue_style('hkof-booking-style', HKOF_BOOKING_URL . 'assets/css/style.css', [], HKOF_BOOKING_VERSION);

    if ($has_public) {
        wp_enqueue_script('hkof-booking-js', HKOF_BOOKING_URL . 'assets/js/booking.js', [], HKOF_BOOKING_VERSION, true);
        wp_localize_script('hkof-booking-js', 'HKOF_BOOKING', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hkof_booking_nonce'),
        ]);
    }
    if ($has_fe_admin) {
        wp_enqueue_script('hkof-admin-calendar-js', HKOF_BOOKING_URL . 'assets/js/admin-calendar.js', [], HKOF_BOOKING_VERSION, false);
        wp_localize_script('hkof-admin-calendar-js', 'HKOF_BOOKING', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hkof_booking_nonce'),
        ]);
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'hkof-') === false) return;
    wp_enqueue_style('hkof-booking-admin-style', HKOF_BOOKING_URL . 'assets/css/admin.css', [], HKOF_BOOKING_VERSION);
    // Kalender-forhåndsvisning på "Rediger booking"-siden genbruger den offentlige kalenders CSS-klasser.
    wp_enqueue_style('hkof-booking-style', HKOF_BOOKING_URL . 'assets/css/style.css', [], HKOF_BOOKING_VERSION);
    wp_enqueue_script('hkof-admin-calendar-js', HKOF_BOOKING_URL . 'assets/js/admin-calendar.js', [], HKOF_BOOKING_VERSION, false);
    wp_localize_script('hkof-admin-calendar-js', 'HKOF_BOOKING', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('hkof_booking_nonce'),
    ]);
});
