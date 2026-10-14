<?php
/**
 * Plugin Name: HKOF – fjern HTTP-login fra GitHub-kald
 * Description: Læg denne fil i wp-content/mu-plugins/ hvis Lokale Booking ikke kan tjekke GitHub (HTTP 401). WordPress videresender ellers sit eget HTTP-login til GitHub.
 *
 * Kopiér filen til: wp-content/mu-plugins/hkof-strip-github-basic-auth.php
 * (opret mappen mu-plugins hvis den ikke findes). Kræver ikke at selve booking-pluginnet er opdateret.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('http_request_args', function ($args, $url) {
    if (!is_string($url) || !preg_match('#^https://(api\.github\.com|github\.com|codeload\.github\.com|raw\.githubusercontent\.com)/#i', $url)) {
        return $args;
    }
    if (!isset($args['headers']) || !is_array($args['headers'])) {
        return $args;
    }
    foreach (array_keys($args['headers']) as $key) {
        if (strtolower((string) $key) === 'authorization' && stripos((string) $args['headers'][$key], 'Basic ') === 0) {
            unset($args['headers'][$key]);
        }
    }
    return $args;
}, 999, 2);
