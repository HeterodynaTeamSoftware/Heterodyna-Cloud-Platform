<?php

if (!defined('ABSPATH')) exit;



/**

 * Performance Pack (odseparowany od WP):

 * - tryb produkcyjny (mniej kosztownych akcji)

 * - nagłówki cache klienta (opcjonalnie, bezpiecznie)

 * - status cache obiektów (Redis/Memcached)

 */

class HDS3_Performance {



    public static function init() {

        // Nagłówki cache klienta (tylko front, tylko GET, tylko dla niezalogowanych).

        add_action('send_headers', [__CLASS__, 'maybe_send_client_cache_headers']);

    }



    public static function is_production_mode() {

        return HDS3_Settings::get('production_mode', '0') === '1';

    }



    public static function maybe_send_client_cache_headers() {

        if (!self::is_production_mode()) return;

        if (is_admin()) return;

        if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'GET') return;

        if (is_user_logged_in()) return;



        $enabled = HDS3_Settings::get('client_cache_headers', '1');

        if ($enabled !== '1') return;



        // Krótki cache po stronie klienta, żeby nie psuć dynamicznych treści.

        $max_age = (int) HDS3_Settings::get('client_cache_max_age', '300');

        if ($max_age < 60) $max_age = 60;

        if ($max_age > 3600) $max_age = 3600;



        // Tylko jeśli serwer nie ustawił już czegoś sensownego.

        if (!headers_sent()) {

            if (!self::has_header('Cache-Control')) {

                header('Cache-Control: public, max-age=' . $max_age . ', stale-while-revalidate=60');

            }

            if (!self::has_header('Expires')) {

                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $max_age) . ' GMT');

            }

            if (!self::has_header('Referrer-Policy')) {

                header('Referrer-Policy: strict-origin-when-cross-origin');

            }

        }

    }



    private static function has_header($name) {

        $name = strtolower($name);

        foreach (headers_list() as $h) {

            $p = strpos($h, ':');

            if ($p === false) continue;

            $hn = strtolower(trim(substr($h, 0, $p)));

            if ($hn === $name) return true;

        }

        return false;

    }



    public static function object_cache_status() {

        // WP: wp_using_ext_object_cache() true gdy działa drop-in object-cache.php (Redis/Memcached itd.)

        $using = function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false;

        $dropin = defined('WP_CONTENT_DIR') ? (WP_CONTENT_DIR . '/object-cache.php') : '';

        $dropin_exists = $dropin ? file_exists($dropin) : false;



        // Prosty test set/get (best effort)

        $ok = null;

        if (function_exists('wp_cache_set') && function_exists('wp_cache_get')) {

            $k = 'hds3_oc_test_' . wp_generate_uuid4();

            wp_cache_set($k, '1', 'hds3', 30);

            $v = wp_cache_get($k, 'hds3');

            $ok = ($v === '1');

        }



        return [

            'using_ext' => $using,

            'dropin' => $dropin_exists,

            'test_ok' => $ok,

        ];

    }

}

