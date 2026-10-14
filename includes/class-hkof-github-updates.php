<?php
if (!defined('ABSPATH')) exit;

/**
 * GitHub-opdateringer: WordPress videresender ofte sit eget HTTP Basic-login
 * (adgangskodebeskyttet wp-admin/staging) til api.github.com. GitHub svarer
 * så 401 på alle kald, også til et offentligt repo. Vi fjerner derfor Basic
 * fra GitHub-kald og sender kun et rigtigt PAT som Bearer.
 */
class HKOF_GitHub_Updates {

    const REPO_API = 'https://api.github.com/repos/Jens-ole-nielsen/hoerby-booking';

    public static function init() {
        add_filter('http_request_args', [__CLASS__, 'filter_github_http_args'], 999, 2);
        add_action('admin_post_hkof_test_github', [__CLASS__, 'handle_test']);
    }

    public static function is_github_url($url) {
        if (!is_string($url) || $url === '') {
            return false;
        }
        return (bool) preg_match('#^https://(api\.github\.com|github\.com|codeload\.github\.com|raw\.githubusercontent\.com)/#i', $url);
    }

    public static function filter_github_http_args($args, $url) {
        if (!self::is_github_url($url)) {
            return $args;
        }
        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        foreach (array_keys($args['headers']) as $key) {
            if (strtolower((string) $key) === 'authorization') {
                unset($args['headers'][$key]);
            }
        }

        $token = self::usable_token();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        if (empty($args['timeout']) || (int) $args['timeout'] < 15) {
            $args['timeout'] = 15;
        }
        return $args;
    }

    public static function usable_token() {
        $token = trim((string) HKOF_Settings::get('github_update_token'));
        if ($token === '') {
            return '';
        }
        if (!preg_match('/^(ghp_|github_pat_|gho_|ghu_)/', $token)) {
            return '';
        }
        return $token;
    }

    public static function handle_test() {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang');
        }
        check_admin_referer('hkof_test_github');

        $resp = wp_remote_get(self::REPO_API . '/branches/main', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        if (is_wp_error($resp)) {
            $result = [
                'ok'      => false,
                'code'    => 0,
                'message' => $resp->get_error_message(),
            ];
        } else {
            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $json = json_decode($body, true);
            $hint = '';
            if ($code === 401) {
                $hint = 'GitHub afviser kaldet. Typisk årsag: webhotellet eller WordPress sender et HTTP-login (adgangskodebeskyttet mappe) med til GitHub. Pluginnet fjerner nu den header — hvis I stadig er på en ældre version, skal pluginnet uploades manuelt én gang.';
            } elseif ($code === 403) {
                $hint = 'GitHub rate-limit (for mange kald fra hosting-IP). Et gyldigt classic PAT under Indstillinger kan løse det.';
            } elseif ($code === 404) {
                $hint = 'Repo eller branch blev ikke fundet. Tjek at GitHub-repoet er offentligt.';
            } elseif ($code >= 200 && $code < 300) {
                $hint = 'Forbindelsen til GitHub virker.';
            }
            $result = [
                'ok'      => ($code >= 200 && $code < 300),
                'code'    => $code,
                'message' => $hint,
                'sha'     => is_array($json) ? ($json['commit']['sha'] ?? '') : '',
            ];
        }

        set_transient('hkof_github_test_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=hkof-settings&github_test=1'));
        exit;
    }
}
