<?php
/**
 * Plugin Name: Lokale Booking
 * Plugin URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 * Description: Booking-system til udlejning af hele huset. Godkendelsesflow, automatisk kontrakt-PDF, manuel depositum-registrering, automatisk faktura 14 dage før arrangementet, samt Google Drive-integration til automatisk kontrakt-backup og planlagt databackup, redigerbare kontrakttekster og logo i alle mails.
 * Version: 1.7.0
 * Author: Fair IT
 * Author URI: https://fair-it.dk
 * Text Domain: hkof-booking
 * Update URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 */

if (!defined('ABSPATH')) exit; // Ingen direkte adgang

define('HKOF_BOOKING_VERSION', '1.7.0');
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
// hosting kan denne kvote let være brugt op af andre sites på samme IP, hvilket
// giver "HTTP status code: 403" når WP tjekker for opdateringer. Sættes derfor
// et Personal Access Token (under Indstillinger i pluginnet) op, hæves kvoten
// til 5000/time og fejlen forsvinder.
$hkofGithubToken = HKOF_Settings::get('github_update_token');
if (!empty($hkofGithubToken)) {
    $hkofUpdateChecker->setAuthentication($hkofGithubToken);
}

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
        update_option('hkof_booking_db_version', HKOF_BOOKING_VERSION);
    }
}, 5);

// ─── OPSTART ────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    HKOF_Admin::init();
    HKOF_Public::init();
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
    if (!$post || !has_shortcode($post->post_content, 'hkof_booking')) return;

    wp_enqueue_style('hkof-booking-style', HKOF_BOOKING_URL . 'assets/css/style.css', [], HKOF_BOOKING_VERSION);
    wp_enqueue_script('hkof-booking-js', HKOF_BOOKING_URL . 'assets/js/booking.js', [], HKOF_BOOKING_VERSION, true);
    wp_localize_script('hkof-booking-js', 'HKOF_BOOKING', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('hkof_booking_nonce'),
    ]);
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'hkof-') === false) return;
    wp_enqueue_style('hkof-booking-admin-style', HKOF_BOOKING_URL . 'assets/css/admin.css', [], HKOF_BOOKING_VERSION);
});
