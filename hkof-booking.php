<?php
/**
 * Plugin Name: Hørby Booking System
 * Plugin URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 * Description: Booking-system til udlejning af hele huset. Godkendelsesflow, automatisk kontrakt-PDF, manuel depositum-registrering og automatisk faktura 14 dage før arrangementet.
 * Version: 1.0.0
 * Author: Fair IT
 * Author URI: https://fair-it.dk
 * Text Domain: hkof-booking
 * Update URI: https://github.com/Jens-ole-nielsen/hoerby-booking
 */

if (!defined('ABSPATH')) exit; // Ingen direkte adgang

define('HKOF_BOOKING_VERSION', '1.0.0');
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

// ─── AKTIVERING / DEAKTIVERING ─────────────────────────────
register_activation_hook(__FILE__, function () {
    HKOF_DB::create_table();
    HKOF_Settings::set_defaults();
    HKOF_Cron::schedule();
});

register_deactivation_hook(__FILE__, function () {
    HKOF_Cron::unschedule();
});

// ─── OPSTART ────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    HKOF_Admin::init();
    HKOF_Public::init();
    HKOF_Cron::init();
});

// ─── ASSETS ─────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function () {
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
