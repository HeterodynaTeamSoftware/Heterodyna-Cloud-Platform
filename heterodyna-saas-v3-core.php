<?php
/*
Plugin Name: Heterodyna Cloud Platform
Description: Heterodyna Cloud Platform — zarządzanie organizacjami, licencjami, subskrypcjami i rozszerzeniami.
Version: 03.00.182
Author: Heterodyna Team Software
Author URI: https://heterodyna.pl
Text Domain: heterodyna-saas-v3-core
*/

if (!defined('ABSPATH')) exit;

define('HDS3_VERSION','03.00.182');
define('HDS3_SLUG', 'heterodyna-saas-v3-core');


// --- HDS3: Globalny Topbar (menu na KAŻDEJ stronie wp-admin) ---
// v03.00.122: powrót do natywnego admina WordPress (górny pasek + lewe menu).
// Globalny topbar HDS3 wyłączony celowo — widżety i strony wtyczki zostają.


// --- HDS3 SAFE MODE: nie dotykamy wrażliwych ekranów WP-Admin (żeby nie wywalać krytycznych błędów) ---
if (!function_exists('hds3_is_sensitive_admin_page')) {
    function hds3_is_sensitive_admin_page(): bool {
        if (!is_admin()) return false;
        $p = isset($GLOBALS['pagenow']) ? (string)$GLOBALS['pagenow'] : '';
        if ($p === '') return false;
        $sensitive = array('plugin-install.php','plugin-editor.php','theme-editor.php','update-core.php','update.php','site-health.php');
        return in_array($p, $sensitive, true);
    }
}

// Jeśli to wrażliwy ekran, wtyczka pozostaje aktywna, ale NIE wykonuje żadnego kodu UI/telemetrii w tym request.
if (hds3_is_sensitive_admin_page()) {
    return;
}


// --- HDS3: Porzadek z Deprecated (PHP 8.x) ---
// W praktyce: wyciszamy Deprecated w UI (żeby nie zalewało kokpitu), ale zapisujemy je do loga
// i do opcji WP (ostatnie 50), żeby dało się wskazać winowajcę (wtyczka/motyw/core).
if (!function_exists('hds3_register_deprecated_handler')) {
    function hds3_register_deprecated_handler() {
        // W adminie to najbardziej przeszkadza. Nie dotykamy frontu, żeby nie maskować problemów publicznie.
        if (!is_admin()) return;
        // Jeśli ktoś chce widzieć Deprecated w przeglądarce, może to wyłączyć flagą:
        // define('HDS3_SHOW_DEPRECATED', true);
        if (defined('HDS3_SHOW_DEPRECATED') && HDS3_SHOW_DEPRECATED) return;
        if (function_exists('hds3_is_sensitive_admin_page') && hds3_is_sensitive_admin_page()) return;

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if ($errno !== E_DEPRECATED && $errno !== E_USER_DEPRECATED) {
                return false; // inne błędy niech idą standardowo
            }

            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $culprit = '';
            foreach ($trace as $t) {
                if (empty($t['file'])) continue;
                $f = (string)$t['file'];
                // pierwsze miejsce poza core WP (wp-includes/wp-admin) zwykle wskazuje wtyczkę/motyw
                if (strpos($f, '/wp-includes/') === false && strpos($f, '/wp-admin/') === false) {
                    $culprit = $f;
                    break;
                }
            }

            $entry = array(
                'ts' => time(),
                'errno' => (int)$errno,
                'msg' => (string)$errstr,
                'file' => (string)$errfile,
                'line' => (int)$errline,
                'culprit' => (string)$culprit,
            );

            // log do error_log
            error_log('[HDS3][DEPRECATED] ' . $entry['msg'] . ' @ ' . $entry['file'] . ':' . $entry['line'] . ($culprit ? (' | culprit: ' . $culprit) : ''));

            // log do opcji (ostatnie 50)
            $opt_key = 'hds3_deprecated_log';
            $log = get_option($opt_key, array());
            if (!is_array($log)) $log = array();
            array_unshift($log, $entry);
            if (count($log) > 50) $log = array_slice($log, 0, 50);
            update_option($opt_key, $log, false);

            return true; // WYŁĄCZ wyświetlanie Deprecated w przeglądarce
        });
    }
}
add_action('plugins_loaded', 'hds3_register_deprecated_handler', 1);

define('HDS3_PATH', plugin_dir_path(__FILE__));
define('HDS3_URL', plugin_dir_url(__FILE__));

// Lokalny katalog paczek do pobrania (generowanych automatycznie jako ZIP).
// Uwaga: celowo NIE pozwalamy generować paczek systemu SaaS (ten plugin / core).
define('HDS3_ADMIN_PRODUCTS_DIR', HDS3_PATH . 'admin_products');
define('HDS3_ADMIN_ZIPS_DIR', HDS3_PATH . 'admin_zips');

// --- HDS3 SAFE CONTEXT GUARDS ---
// Na niektórych ekranach WP (np. instalacja wtyczek) nie chcemy podmieniać admin bara / handlerów,
// żeby uniknąć konfliktów i krytycznych błędów.
if (!function_exists('hds3_is_sensitive_admin_page')) {
    function hds3_is_sensitive_admin_page(): bool {
        if (!is_admin()) return false;
        global $pagenow;
        $p = strtolower((string)($pagenow ?? ''));
        if ($p === '') return false;
        // Ekrany, na których NIE ingerujemy w pasek/obsługę błędów
        $blocked = array(
            'plugin-install.php',
            'plugin-editor.php',
            'theme-editor.php',
            'update-core.php',
            'update.php',
            'site-health.php',
        );
        return in_array($p, $blocked, true);
    }
}


// --- HDS3 TELEMETRIA MAX (bez danych osobowych) ---
// Zbieramy tylko dane techniczne + agregaty. Domeny/hosty są haszowane (salt per instalacja).
define('HDS3_TEL_RING_LIMIT', 288); // ~24h przy 5 min
define('HDS3_TEL_INTERVAL_SEC', 300); // 5 min

if (!function_exists('hds3_tel_salt')) {
    function hds3_tel_salt(): string {
        $k = 'hds3_tel_salt';
        $v = get_option($k, '');
        if (!is_string($v) || $v === '') {
            $v = wp_generate_password(32, false, false);
            update_option($k, $v, true);
        }
        return (string)$v;
    }
}
if (!function_exists('hds3_tel_hash_host')) {
    function hds3_tel_hash_host(string $host): string {
        $salt = hds3_tel_salt();
        return substr(hash('sha256', $salt.'|'.strtolower($host)), 0, 16);
    }
}
if (!function_exists('hds3_tel_opt_basic')) {
    function hds3_tel_opt_basic(): bool {
        // podstawowa telemetria ON domyślnie
        $v = get_option('hds3_tel_basic', '1');
        return (string)$v === '1';
    }
}
if (!function_exists('hds3_tel_opt_extended')) {
    function hds3_tel_opt_extended(): bool {
        // rozszerzona telemetria OFF domyślnie (opt-in)
        $v = get_option('hds3_tel_extended', '0');
        return (string)$v === '1';
    }
}

if (!function_exists('hds3_tel_add_cron_interval')) {
    function hds3_tel_add_cron_interval($schedules) {
        if (!isset($schedules['hds3_5min'])) {
            $schedules['hds3_5min'] = array('interval' => HDS3_TEL_INTERVAL_SEC, 'display' => 'HDS3 co 5 minut');
        }
        return $schedules;
    }
}
add_filter('cron_schedules', 'hds3_tel_add_cron_interval');

if (!function_exists('hds3_tel_schedule')) {
    function hds3_tel_schedule() {
        if (!wp_next_scheduled('hds3_collect_telemetry')) {
            wp_schedule_event(time() + 30, 'hds3_5min', 'hds3_collect_telemetry');
        }
    }
}
add_action('plugins_loaded', 'hds3_tel_schedule', 5);

if (!function_exists('hds3_tel_collect_snapshot')) {
    function hds3_tel_collect_snapshot(): array {
        $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $host_hash = $host ? hds3_tel_hash_host($host) : 'unknown';

        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : array();
        $load1 = is_array($load) && isset($load[0]) ? (float)$load[0] : null;
        $load5 = is_array($load) && isset($load[1]) ? (float)$load[1] : null;
        $load15 = is_array($load) && isset($load[2]) ? (float)$load[2] : null;

        $mem_usage = function_exists('memory_get_usage') ? (int)@memory_get_usage(true) : null;
        $mem_peak  = function_exists('memory_get_peak_usage') ? (int)@memory_get_peak_usage(true) : null;
        $mem_limit_raw = (string)ini_get('memory_limit');

        // dysk
        $disk_total = null; $disk_free = null;
        $root = defined('ABSPATH') ? ABSPATH : '/';
        if (function_exists('disk_total_space')) $disk_total = @disk_total_space($root);
        if (function_exists('disk_free_space'))  $disk_free  = @disk_free_space($root);
        $disk_pct = null;
        if (is_numeric($disk_total) && is_numeric($disk_free) && (float)$disk_total > 0) {
            $disk_pct = (float)(100.0 * (1.0 - ((float)$disk_free / (float)$disk_total)));
        }

        // błędy (agregat): liczymy deprecations z ostatnich 24h (max 50 wpisów)
        $dep = get_option('hds3_deprecated_log', array());
        $dep24 = 0;
        if (is_array($dep)) {
            $cut = time() - 86400;
            foreach ($dep as $e) {
                if (!is_array($e)) continue;
                $ts = isset($e['ts']) ? (int)$e['ts'] : 0;
                if ($ts >= $cut) $dep24++;
            }
        }

        // metryki z innych wtyczek (rejestr)
        $ext = apply_filters('hds3_metrics', array());
        if (!is_array($ext)) $ext = array();

        // filtrujemy do bezpiecznych scalarów
        $ext_safe = array();
        foreach ($ext as $k=>$v) {
            $k = sanitize_key((string)$k);
            if ($k === '') continue;
            if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
                $ext_safe[$k] = $v;
            }
        }

        // lista aktywnych wtyczek Heterodyna (bez nazw userów, bez treści)
        $active = (array)get_option('active_plugins', array());
        $het_active = array();
        foreach ($active as $p) {
            if (is_string($p) && (strpos($p, 'heterodyna') !== false || strpos($p, 'Heterodyna') !== false)) {
                $het_active[] = $p;
            }
        }

        return array(
            'ts' => time(),
            'host_hash' => $host_hash,
            'wp' => (string)get_bloginfo('version'),
            'php' => (string)PHP_VERSION,
            'load1' => $load1, 'load5'=>$load5, 'load15'=>$load15,
            'mem_usage' => $mem_usage, 'mem_peak'=>$mem_peak,
            'mem_limit' => $mem_limit_raw,
            'disk_pct' => $disk_pct,
            'deprecated_24h' => $dep24,
            'het_active' => $het_active,
            'metrics' => $ext_safe,
        );
    }
}

if (!function_exists('hds3_tel_store_snapshot')) {
    function hds3_tel_store_snapshot(array $snap): void {
        update_option('hds3_tel_last_snapshot', $snap, false);

        $ring = get_option('hds3_tel_ring', array());
        if (!is_array($ring)) $ring = array();
        $ring[] = $snap;
        if (count($ring) > HDS3_TEL_RING_LIMIT) {
            $ring = array_slice($ring, -HDS3_TEL_RING_LIMIT);
        }
        update_option('hds3_tel_ring', $ring, false);
    }
}

if (!function_exists('hds3_tel_db_install')) {
    function hds3_tel_db_install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t1 = $wpdb->prefix . 'hds3_telemetry_events';
        $t2 = $wpdb->prefix . 'hds3_telemetry_daily';

        $sql1 = "CREATE TABLE $t1 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts INT UNSIGNED NOT NULL,
            host_hash VARCHAR(32) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            payload LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY event_type (event_type)
        ) $charset;";

        $sql2 = "CREATE TABLE $t2 (
            day DATE NOT NULL,
            host_hash VARCHAR(32) NOT NULL,
            load1 FLOAT NULL,
            disk_pct FLOAT NULL,
            deprecated_24h INT NULL,
            PRIMARY KEY (day, host_hash)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
    }
}

if (!function_exists('hds3_tel_upsert_daily')) {
    function hds3_tel_upsert_daily(array $snap): void {
        global $wpdb;
        $t2 = $wpdb->prefix . 'hds3_telemetry_daily';
        $day = gmdate('Y-m-d', (int)$snap['ts']);
        $host_hash = isset($snap['host_hash']) ? (string)$snap['host_hash'] : 'unknown';
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $t2 (day, host_hash, load1, disk_pct, deprecated_24h)
                 VALUES (%s, %s, %f, %f, %d)
                 ON DUPLICATE KEY UPDATE load1=VALUES(load1), disk_pct=VALUES(disk_pct), deprecated_24h=VALUES(deprecated_24h)",
                $day,
                $host_hash,
                is_null($snap['load1']) ? 0.0 : (float)$snap['load1'],
                is_null($snap['disk_pct']) ? 0.0 : (float)$snap['disk_pct'],
                (int)$snap['deprecated_24h']
            )
        );
    }
}

add_action('hds3_collect_telemetry', function () {
    if (!hds3_tel_opt_basic()) return;
    $snap = hds3_tel_collect_snapshot();
    hds3_tel_store_snapshot($snap);
    // daily agregat
    hds3_tel_upsert_daily($snap);

    // event: snapshot (opcjonalnie)
    if (hds3_tel_opt_extended()) {
        global $wpdb;
        $t1 = $wpdb->prefix . 'hds3_telemetry_events';
        $wpdb->insert($t1, array(
            'ts' => (int)$snap['ts'],
            'host_hash' => (string)$snap['host_hash'],
            'event_type' => 'snapshot',
            'payload' => wp_json_encode($snap),
        ));
    }
});

// Instalacja tabel + cron przy aktywacji
register_activation_hook(__FILE__, function () {
    hds3_tel_db_install();
    hds3_tel_schedule();
});



// --- HDS3 BRAND LOGO HELPERS ---
if (!function_exists('hds3_brand_logo_src')) {
    /**
     * Returns logo URL for portal/admin. Prefers /assets/logo/logo.png if present.
     * Falls back to built-in SVG data URI.
     */
    function hds3_brand_logo_src(): string {
        // 1) Opcjonalnie: administrator może wskazać własny URL w ustawieniach.
        $opt = function_exists('get_option') ? get_option('hds3_brand_logo_url', '') : '';
        if (is_string($opt) && $opt !== '') {
            return $opt;
        }

        // 2) Plik w assets (łatwo podmienić przez FTP): logo.png
        $file_png = plugin_dir_path(__FILE__) . 'assets/logo/logo.png';
        if (is_readable($file_png)) {
            return plugin_dir_url(__FILE__) . 'assets/logo/logo.png';
        }

        // 3) Fallback: logo.svg (zawsze obecne w paczce)
        $file_svg = plugin_dir_path(__FILE__) . 'assets/logo/logo.svg';
        if (is_readable($file_svg)) {
            return plugin_dir_url(__FILE__) . 'assets/logo/logo.svg';
        }

        // 4) Ostateczny fallback: data-uri SVG (nie wymaga plików)
        return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1MjAiIGhlaWdodD0iMTQwIiB2aWV3Qm94PSIwIDAgNTIwIDE0MCI+PGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+PHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjN2EwMGRmIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMDBkNGZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+PHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjUyMCIgaGVpZ2h0PSIxNDAiIHJ4PSIyOCIgc3R5bGU9ImZpbGw6IzBiMTAyMDtzdHJva2U6IzE2MmEzZjtzdHJva2Utd2lkdGg6MyIvPjx0ZXh0IHg9IjQ0IiB5PSI4NCIgZm9udC1mYW1pbHk9IkludGVyLFN5c3RlbS1VSSxBcmlhbCIgZm9udC1zaXplPSI0NiIgZm9udC13ZWlnaHQ9IjgwMCIgZmlsbD0idXJsKCNnKSI+SGV0ZXJvZHluYTwvdGV4dD48dGV4dCB4PSIzMzAiIHk9Ijg0IiBmb250LWZhbWlseT0iSW50ZXIsU3lzdGVtLVVJLEFyaWFsIiBmb250LXNpemU9IjI4IiBmb250LXdlaWdodD0iNjAwIiBmaWxsPSIjYjhkNmZmIj5UZWFtIFNvZnR3YXJlPC90ZXh0Pjwvc3ZnPg==';
    }
}
// --- HDS3 SAFE HELPERS (PHP 8.x) ---
// Zabezpiecza przed przekazywaniem NULL do funkcji WordPress (esc_url/esc_attr/esc_html), co powoduje Deprecated w PHP 8.x.
if (!function_exists('hds3_safe_str')) {
    function hds3_safe_str($v): string {
        if (is_string($v)) return $v;
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return (string)$v;
        return '';
    }
}
if (!function_exists('hds3_safe_url')) {
    function hds3_safe_url($v): string {
        $s = hds3_safe_str($v);
        if ($s === '') return '';
        // WordPress bywa restrykcyjny dla data-uri. My używamy wyłącznie data:image/* dla fallback logo.
        if (stripos($s, 'data:image/') === 0) {
            return $s;
        }
        return esc_url($s);
    }
}
if (!function_exists('hds3_safe_attr')) {
    function hds3_safe_attr($v): string {
        return esc_attr(hds3_safe_str($v));
    }
}
if (!function_exists('hds3_safe_html')) {
    function hds3_safe_html($v): string {
        return esc_html(hds3_safe_str($v));
    }
}

define('HDS3_ENGINE_DIR', WP_CONTENT_DIR . '/heterodyna_engine');
define('HDS3_ENGINE_CORE_DIR', HDS3_ENGINE_DIR . '/hds3_core');
define('HDS3_ENGINE_PAYLOAD_DIR', HDS3_PATH . 'engine_payload/hds3_core');

/**
 * Engine poza WP: logika jest kopiowana do /wp-content/heterodyna_engine/hds3_core
 * WP działa jako shell/admin/router. Jeśli engine nie jest dostępny, używamy plików z pluginu (compat).
 */
function hds3_engine_is_ready(): bool {
    return is_dir(HDS3_ENGINE_CORE_DIR) && file_exists(HDS3_ENGINE_CORE_DIR . '/includes/class-hds3-kernel.php');
}

function hds3_engine_version(): ?string {
    $vf = HDS3_ENGINE_CORE_DIR . '/engine-version.php';
    if (!file_exists($vf)) return null;
    $v = @include $vf;
    return is_string($v) ? $v : null;
}

function hds3_copy_dir(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) return false;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Throwable $e) {
        return false;
    }

    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = rtrim($dst, '/\\') . DIRECTORY_SEPARATOR . $rel;

        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0755, true)) return false;
        } else {
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) return false;
            if (!@copy($item->getPathname(), $target)) return false;
        }
    }
    return true;
}

function hds3_delete_dir_safe(string $dir): bool {
    if ($dir === '' || !is_dir($dir)) return true;

    $items = @scandir($dir);
    if (!is_array($items)) {
        return @rmdir($dir);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path) && !is_link($path)) {
            hds3_delete_dir_safe($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}

/**
 * Best-effort instalacja/upgrade engine.
 * Bezpieczniki:
 * - nie odpalamy tego na zwykłych requestach front/ajax/rest/cron
 * - nie robimy nic bez payloadu źródłowego
 * - każda operacja na katalogach jest best-effort i nigdy nie może wywalić fatala
 */
function hds3_engine_install_if_needed(bool $force = false): void {
    if (!defined('WP_CONTENT_DIR')) return;
    if (!is_dir(HDS3_ENGINE_PAYLOAD_DIR)) return;

    if (!$force) {
        if (!is_admin()) return;
        if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_INSTALLING') && WP_INSTALLING)) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        if (function_exists('current_user_can') && !current_user_can('manage_options')) return;
    }

    $need = !hds3_engine_is_ready() || (hds3_engine_version() !== HDS3_VERSION);
    if (!$need) return;

    if (!is_dir(HDS3_ENGINE_DIR) && !@mkdir(HDS3_ENGINE_DIR, 0755, true) && !is_dir(HDS3_ENGINE_DIR)) {
        return;
    }

    $tmp = HDS3_ENGINE_DIR . '/.hds3_core_tmp';
    $bak = HDS3_ENGINE_DIR . '/.hds3_core_bak';
    $core = HDS3_ENGINE_CORE_DIR;

    try {
        if (is_dir($tmp)) {
            hds3_delete_dir_safe($tmp);
        }
        if (is_dir($bak)) {
            hds3_delete_dir_safe($bak);
        }

        if (!hds3_copy_dir(HDS3_ENGINE_PAYLOAD_DIR, $tmp)) {
            update_option('hds3_engine_install_last_error', 'copy_failed', false);
            return;
        }

        if (is_dir($core) && !@rename($core, $bak)) {
            if (!hds3_copy_dir($core, $bak)) {
                update_option('hds3_engine_install_last_error', 'backup_failed', false);
                hds3_delete_dir_safe($tmp);
                return;
            }
            hds3_delete_dir_safe($core);
        }

        if (!@rename($tmp, $core)) {
            if (!hds3_copy_dir($tmp, $core)) {
                update_option('hds3_engine_install_last_error', 'swap_failed', false);
                hds3_delete_dir_safe($tmp);
                return;
            }
            hds3_delete_dir_safe($tmp);
        }

        if (is_dir($bak)) {
            hds3_delete_dir_safe($bak);
        }
        delete_option('hds3_engine_install_last_error');
        update_option('hds3_engine_installed_version', HDS3_VERSION, false);
    } catch (Throwable $e) {
        update_option('hds3_engine_install_last_error', $e->getMessage(), false);
    }
}

/**
 * Single source of truth: all runtime PHP classes come from this installed
 * plugin release. The separately deployed engine is retained for backwards
 * compatibility, but must never shadow a newer or older local class.
 * This avoids mixed-version loads when engine copies are stale or unwritable.
 */
function hds3_require(string $rel): void {
    $rel = ltrim($rel, '/\\');
    // Only local, explicitly listed includes below are permitted; never let
    // an untrusted caller request arbitrary paths from another directory.
    if (!preg_match('~\Aincludes/[a-z0-9-]+\.php\z~D', $rel)) {
        throw new InvalidArgumentException('Invalid HDS3 class path');
    }
    $local = HDS3_PATH . $rel;
    if (!is_file($local)) {
        throw new RuntimeException('Missing bundled HDS3 class: ' . $rel);
    }
    require_once $local;
}



require_once HDS3_PATH . 'includes/helpers.php';
hds3_require('includes/class-hds3-db.php');
hds3_require('includes/class-hds3-data-reset.php');
hds3_require('includes/class-hds3-settings.php');
hds3_require('includes/class-hds3-auth.php');
hds3_require('includes/class-hds3-tokens.php');
hds3_require('includes/class-hds3-logger.php');
hds3_require('includes/class-hds3-vault.php');
hds3_require('includes/class-hds3-kernel.php');
hds3_require('includes/class-hds3-extensions.php');
hds3_require('includes/class-hds3-legacy-module-bridge.php');
hds3_require('includes/class-hds3-extension-manager.php');
hds3_require('includes/class-hds3-tenants.php');
hds3_require('includes/class-hds3-iam.php');
hds3_require('includes/class-hds3-audit.php');
hds3_require('includes/class-hds3-plans.php');
hds3_require('includes/class-hds3-billing.php');
hds3_require('includes/class-hds3-subscriptions.php');
hds3_require('includes/class-hds3-usage.php');
hds3_require('includes/class-hds3-billing-periods.php');
hds3_require('includes/class-hds3-automation.php');
hds3_require('includes/class-hds3-diagnostics.php');
hds3_require('includes/class-hds3-performance.php');
hds3_require('includes/class-hds3-admin.php');
hds3_require('includes/class-hds3-alerts.php');
hds3_require('includes/class-hds3-notifications.php');
hds3_require('includes/class-hds3-webhooks.php');
hds3_require('includes/class-hds3-rest.php');
hds3_require('includes/class-hds3-gatekeeper.php');
hds3_require('includes/class-hds3-legacy.php');
hds3_require('includes/class-hds3-seo.php');
hds3_require('includes/class-hds3-agency.php');
hds3_require('includes/class-hds3-legacy-compat.php');

// Engine install/upgrade (bezpiecznie: aktywacja + admin, nigdy zwykły AJAX/front).
add_action('admin_init', function () { hds3_engine_install_if_needed(false); }, 1);
register_activation_hook(__FILE__, function () { hds3_engine_install_if_needed(true); });


register_activation_hook(__FILE__, ['HDS3_DB','activate']);
register_activation_hook(__FILE__, ['HDS3_IAM','install']);
register_activation_hook(__FILE__, ['HDS3_Audit','install']);
register_activation_hook(__FILE__, ['HDS3_Subscriptions','install']);
register_activation_hook(__FILE__, ['HDS3_Usage','install']);
register_activation_hook(__FILE__, ['HDS3_Billing_Periods','install']);
register_activation_hook(__FILE__, ['HDS3_Automation','install']);
register_activation_hook(__FILE__, ['HDS3_Alerts','install']);
register_activation_hook(__FILE__, ['HDS3_Notifications','install']);
register_activation_hook(__FILE__, ['HDS3_Webhooks','install']);
register_deactivation_hook(__FILE__, ['HDS3_Alerts','deactivate']);
register_deactivation_hook(__FILE__, ['HDS3_Automation','deactivate']);

add_action('plugins_loaded', function() {
    HDS3_DB::maybe_upgrade();
    HDS3_Tenants::maybe_migrate();
    if (is_admin()) { HDS3_IAM::install(); HDS3_Audit::install(); HDS3_Subscriptions::maybe_install(); HDS3_Usage::maybe_install(); HDS3_Billing_Periods::maybe_install(); HDS3_Automation::maybe_install(); HDS3_Alerts::maybe_install(); HDS3_Notifications::maybe_install(); HDS3_Webhooks::maybe_install(); }
    HDS3_Plans::maybe_seed();
    HDS3_Plans::maybe_assign_defaults();

    // Najpierw wymuś wyłączenie legacy duplikatów (jeśli użytkownik zaznaczył), zanim reszta się zainicjalizuje.
    HDS3_Legacy::maybe_force_disable();
    HDS3_Kernel::boot();
    // v03.00.166: system modules are now booted exactly once by Extensions.
    // These are protected infrastructure modules, never user-disableable.
    HDS3_Extensions::register('performance', array(
        'name' => 'Performance Core', 'version' => HDS3_VERSION,
        'requires' => array(), 'system' => true,
        'boot' => array('HDS3_Performance', 'init'),
    ));
    HDS3_Extensions::register('automation', array(
        'name' => 'Automation Core', 'version' => HDS3_VERSION,
        'requires' => array(), 'system' => true,
        'boot' => array('HDS3_Automation', 'init'),
    ));
    // Business module entry-points are managed by the extension lifecycle.
    // Schema migrations stay on the legacy upgrade path for compatibility.
    HDS3_Extensions::register('subscriptions', array(
        'name' => 'Subscriptions', 'version' => HDS3_VERSION,
        'requires' => array(), 'system' => true,
        'boot' => static function () {
            add_action('admin_menu', array('HDS3_Subscriptions', 'admin_menu'));
            add_action('admin_post_hds3_subscription_save', array('HDS3_Subscriptions', 'save'));
        },
    ));
    HDS3_Extensions::register('usage', array(
        'name' => 'Usage & Quotas', 'version' => HDS3_VERSION,
        'requires' => array('subscriptions'), 'system' => true,
        'boot' => static function () {
            add_action('admin_menu', array('HDS3_Usage', 'admin_menu'));
        },
    ));
    HDS3_Extensions::boot();
    HDS3_Extension_Manager::init();
    HDS3_Diagnostics::init();
    HDS3_Alerts::init();
    HDS3_Notifications::init();
    HDS3_Webhooks::init();

    HDS3_Admin::init();
    HDS3_REST::init();
    HDS3_Gatekeeper::init();
    if (HDS3_Legacy_Compat::enabled()) HDS3_SEO::init();
});


// Shortcode: cennik / katalog produktu (np. SEO)
add_action('init', function() {
    add_shortcode('hds3_cennik', function($atts) {
        $atts = shortcode_atts([
            'product' => 'SEO',
        ], is_array($atts) ? $atts : []);
        $product = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', (string)($atts['product'] ?? 'SEO')));
        if (!$product) $product = 'SEO';

        $raw = HDS3_Settings::get('hds3_catalog', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];

        $pcfg = isset($cfg[$product]) && is_array($cfg[$product]) ? $cfg[$product] : [];
        $cats = isset($pcfg['categories']) && is_array($pcfg['categories']) ? $pcfg['categories'] : [];
        if (!$cats) {
            // jeśli brak katalogu, ale to SEO — pokaż domyślny preset
            if ($product === 'SEO') {
                $pcfg = hds3_default_catalog_seo();
                $cats = $pcfg['categories'] ?? [];
            }
        }

        if (!$cats) return '<div class="hds3-cennik"><p>Brak skonfigurowanego cennika dla produktu: <b>' . hds3_safe_html($product) . '</b>.</p></div>';

        ob_start();
        echo '<div class="hds3-cennik" style="display:block;max-width:1200px;margin:0 auto">';
        echo '<style>.hds3-cennik .hds3-cennik__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.hds3-cennik .hds3-cennik__cat{border:1px solid rgba(0,0,0,.08);border-radius:14px;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.06);padding:14px}.hds3-cennik .hds3-cennik__cat h3{margin:0 0 6px 0;font-size:18px}.hds3-cennik .hds3-cennik__desc{margin:0 0 10px 0;opacity:.8}.hds3-cennik .hds3-cennik__offer{border-top:1px dashed rgba(0,0,0,.12);padding-top:10px;margin-top:10px}.hds3-cennik .hds3-cennik__offer:first-of-type{border-top:0;padding-top:0;margin-top:0}.hds3-cennik .hds3-price{font-size:20px;font-weight:800}.hds3-cennik .hds3-meta{margin:6px 0 0 0;opacity:.85;font-size:13px}.hds3-cennik .hds3-cta{display:inline-block;margin-top:10px;padding:10px 12px;border-radius:12px;background:#111;color:#fff;text-decoration:none;font-weight:700}.hds3-cennik .hds3-cta:hover{opacity:.9}.hds3-cennik .hds3-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(0,0,0,.06);font-size:12px;margin-bottom:8px} @media(max-width:980px){.hds3-cennik .hds3-cennik__grid{grid-template-columns:repeat(2,minmax(0,1fr))}} @media(max-width:640px){.hds3-cennik .hds3-cennik__grid{grid-template-columns:1fr}}</style>';
        echo '<div class="hds3-cennik__grid">';
        foreach ($cats as $cat) {
            if (!is_array($cat)) continue;
            $title = (string)($cat['title'] ?? '');
            $desc = (string)($cat['desc'] ?? '');
            $offers = isset($cat['offers']) && is_array($cat['offers']) ? $cat['offers'] : [];
            echo '<div class="hds3-cennik__cat">';
            echo '<div class="hds3-badge">🧾 ' . hds3_safe_html($title ?: $product) . '</div>';
            echo '<h3>' . hds3_safe_html($title ?: $product) . '</h3>';
            if ($desc) echo '<p class="hds3-cennik__desc">' . hds3_safe_html($desc) . '</p>';
            foreach ($offers as $of) {
                if (!is_array($of)) continue;
                $label = (string)($of['label'] ?? '');
                $months = (int)($of['months'] ?? 0);
                $price = (int)($of['price_pln'] ?? 0);
                $domains = (string)($of['domains'] ?? '');
                $support = (string)($of['support'] ?? '');
                $cta_label = (string)($of['cta_label'] ?? 'Skontaktuj się');
                $cta_url = (string)($of['cta_url'] ?? '#');

                echo '<div class="hds3-cennik__offer">';
                echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">';
                echo '<div><b>' . hds3_safe_html($label ?: ($months ? ($months . ' mc') : 'Pakiet')) . '</b></div>';
                echo '<div class="hds3-price">' . hds3_safe_html($price ? ($price . ' zł') : '') . '</div>';
                echo '</div>';
                echo '<p class="hds3-meta">' . ($domains ? '🌐 ' . hds3_safe_html($domains) . '<br>' : '') . ($months ? '⏱️ ' . hds3_safe_html($months . ' mc') . '<br>' : '') . ($support ? '🛠️ ' . hds3_safe_html($support) : '') . '</p>';
                if ($cta_url) echo '<a class="hds3-cta" href="' . hds3_safe_url($cta_url) . '">' . hds3_safe_html($cta_label) . ' →</a>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
        return ob_get_clean();
    });
});
require_once __DIR__ . '/includes/admin-portal.php';

require_once __DIR__ . '/includes/admin-branding.php';




/**
 * FRONTEND PORTAL ENTERPRISE (/portal/*)
 */
add_action('init', function () {

    // rola klienta jeśli nie istnieje
    if (!get_role('hds3_client')) {
        add_role('hds3_client', 'HDS3 Client', ['read' => true]);
    }

    add_rewrite_rule('^portal/?$', 'index.php?hds3_portal_page=dashboard', 'top');
    add_rewrite_rule('^portal/(dashboard|licencje|telemetria|ustawienia|pobrane)/?$', 'index.php?hds3_portal_page=$matches[1]', 'top');

    add_rewrite_tag('%hds3_portal_page%', '([^&]+)');
});

register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});


/**
 * FRONTEND PORTAL ENTERPRISE (/portal/*) — HARD ROUTER
 * Działa nawet bez flush permalinków i przebija theme/archiwa.
 */

/**
 * Portal klienta — proste źródła danych (na razie placeholdery, ale już spięte z tenant/user).
 */
function hds3_portal_current_tenant_label($user_id) {
    $tenant = get_user_meta($user_id, 'hds3_tenant', true);
    if (!$tenant) {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
        $tenant = $host ? $host : 'tenant';
    }
    return $tenant;
}

function hds3_portal_get_client_licenses($tenant, $user_id) {
    // TODO: podpiąć do tabel licencji HDS3 (na razie: dane demo bezpieczne)
    return [
        [
            'product' => 'Heterodyna Cloud Platform',
            'plan' => 'Klient — miesięczny',
            'status' => 'aktywny',
            'expires' => date('Y-m-d', time() + 30*86400),
            'key_mask' => 'HDS3-****-'.substr(md5($tenant.$user_id), 0, 4),
        ],
    ];
}

function hds3_portal_get_client_telemetry($tenant, $user_id) {
    // Telemetria oparta o snapshoty HDS3 (bez danych osobowych).
    $snap = get_option('hds3_tel_last_snapshot', array());
    if (!is_array($snap)) $snap = array();

    $last_seen = isset($snap['ts']) ? date('Y-m-d H:i', (int)$snap['ts']) : 'brak';
    $dep24 = isset($snap['deprecated_24h']) ? (int)$snap['deprecated_24h'] : 0;

    // Dodatkowo: próbujemy zliczyć ile snapshotów mamy w ostatnich 24h jako proxy "aktywności"
    $ring = get_option('hds3_tel_ring', array());
    $req24 = 0;
    if (is_array($ring)) {
        $cut = time() - 86400;
        foreach ($ring as $pt) {
            if (!is_array($pt)) continue;
            $ts = isset($pt['ts']) ? (int)$pt['ts'] : 0;
            if ($ts >= $cut) $req24++;
        }
    }

    return [
        'requests_24h' => $req24,
        'warnings_24h' => max(0, (int)floor($dep24 / 2)),
        'errors_24h'   => $dep24,
        'last_seen'    => $last_seen,
        'load1'        => isset($snap['load1']) ? $snap['load1'] : null,
        'disk_pct'     => isset($snap['disk_pct']) ? $snap['disk_pct'] : null,
        'php'          => isset($snap['php']) ? (string)$snap['php'] : PHP_VERSION,
        'wp'           => isset($snap['wp']) ? (string)$snap['wp'] : get_bloginfo('version'),
    ];
}


function hds3_portal_get_client_billing($tenant, $user_id) {
    // TODO: podpiąć do faktur/rozliczeń
    return [
        ['date'=>date('Y-m-d', strtotime('-14 days')), 'title'=>'Subskrypcja (miesiąc)', 'amount'=>'49.00 PLN', 'status'=>'opłacone'],
        ['date'=>date('Y-m-d', strtotime('-44 days')), 'title'=>'Subskrypcja (miesiąc)', 'amount'=>'49.00 PLN', 'status'=>'opłacone'],
    ];
}

/**
 * Portal klienta — lista zakupionych aplikacji/wtyczek (UI).
 * Źródła uprawnień (na dziś):
 * - aktywna licencja w hds3_portal_get_client_licenses() (placeholder)
 * - user_meta: hds3_purchased_plugins (CSV: slug1,slug2,...)
 *
 * W kolejnym kroku podpinamy to do prawdziwych tabel (licencje/rozliczenia) i serwera licencyjnego.
 */
function hds3_portal_catalog(): array {
    return [
        [
            'slug' => 'heterodyna-seo',
            'name' => 'Heterodyna SEO',
            'desc' => 'SEO + AI kolejka, audyt, automatyzacje.',
            'kind' => 'wtyczka',
            'latest' => 'v3.x',
        ],
        [
            'slug' => 'heterodyna-cybersecurity',
            'name' => 'Heterodyna Cybersecurity',
            'desc' => 'WAF, skany, blokady, incydenty, raporty.',
            'kind' => 'wtyczka',
            'latest' => 'v3.x',
        ],
        [
            'slug' => 'heterodyna-cache',
            'name' => 'Heterodyna Cache',
            'desc' => 'Cache, przyspieszenie i diagnostyka.',
            'kind' => 'wtyczka',
            'latest' => 'v3.x',
        ],
        [
            'slug' => 'heterodyna-inspektor',
            'name' => 'Heterodyna Inspektor',
            'desc' => 'Inspekcja instalacji, logi, stan usług.',
            'kind' => 'wtyczka',
            'latest' => 'v3.x',
        ],
        [
            'slug' => 'heterodyna-abuseipdb',
            'name' => 'AbuseIPDB Integracja',
            'desc' => 'Weryfikacja IP, reputacja, blokady.',
            'kind' => 'wtyczka',
            'latest' => 'v3.x',
        ],
    ];
}

function hds3_portal_get_client_entitled_slugs($tenant, $user_id): array {
    $slugs = [];

    // 1) user_meta (manual / z płatności)
    $raw = get_user_meta($user_id, 'hds3_purchased_plugins', true);
    if (is_string($raw) && trim($raw) !== '') {
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($parts as $p) {
            $p = sanitize_key($p);
            if ($p) $slugs[] = $p;
        }
    }

    // 2) licencje (placeholder: aktywna licencja SaaS v3 daje dostęp do paczki bazowej)
    $licenses = (array) hds3_portal_get_client_licenses($tenant, $user_id);
    foreach ($licenses as $lic) {
        $status = strtolower((string)($lic['status'] ?? ''));
        $product = strtolower((string)($lic['product'] ?? ''));
        if (strpos($status, 'akty') !== false && (strpos($product, 'saas') !== false || strpos($product, 'heterodyna') !== false)) {
            // paczka startowa
            $slugs = array_merge($slugs, ['heterodyna-seo','heterodyna-cybersecurity','heterodyna-cache','heterodyna-inspektor']);
        }
    }

    $slugs = array_values(array_unique(array_filter($slugs)));
    return $slugs;
}

function hds3_portal_download_base_url(): string {
    // Docelowo: URL serwera licencyjnego / endpoint pobierania po tokenie.
    $u = get_option('hds3_download_base_url', '');
    $u = is_string($u) ? trim($u) : '';
    if ($u !== '') return $u;
    // Fallback: brak — pokażemy komunikat.
    return '';
}

// --- Portal Klienta: lokalne pobieranie ZIP (generowane automatycznie) ---
// Pliki źródłowe do spakowania trzymamy w: /admin_products/<slug>/
// Wygenerowane ZIP-y trafiają do: /admin_zips/
// Blokada: NIE pozwalamy generować paczek systemu SaaS (ten plugin / core).
if (!function_exists('hds3_is_forbidden_zip_slug')) {
    function hds3_is_forbidden_zip_slug(string $slug): bool {
        $slug = sanitize_key($slug);
        if ($slug === '' ) return true;
        // core / system SaaS
        if ($slug === sanitize_key(HDS3_SLUG)) return true;
        if (strpos($slug, 'saas') !== false && strpos($slug, 'v3') !== false) return true;
        return false;
    }
}

if (!function_exists('hds3_local_product_dir')) {
    function hds3_local_product_dir(string $slug): string {
        $slug = sanitize_key($slug);
        return rtrim(HDS3_ADMIN_PRODUCTS_DIR, '/\\') . DIRECTORY_SEPARATOR . $slug;
    }
}

if (!function_exists('hds3_local_zip_path')) {
    function hds3_local_zip_path(string $slug, string $ver = ''): string {
        $slug = sanitize_key($slug);
        $ver = trim((string)$ver);
        $fname = $slug;
        if ($ver !== '') {
            // zostawiamy pełną kontrolę nad formatem wersji (bez "hotfix")
            $fname .= '-'.preg_replace('/[^0-9A-Za-z\._-]/', '', $ver);
        }
        $fname .= '.zip';
        return rtrim(HDS3_ADMIN_ZIPS_DIR, '/\\') . DIRECTORY_SEPARATOR . $fname;
    }
}

if (!function_exists('hds3_build_local_zip')) {
    function hds3_build_local_zip(string $slug, string $ver = ''): ?string {
        if (!class_exists('ZipArchive')) return null;
        $slug = sanitize_key($slug);
        if (hds3_is_forbidden_zip_slug($slug)) return null;

        $src = hds3_local_product_dir($slug);
        if (!is_dir($src)) return null;

        if (!is_dir(HDS3_ADMIN_ZIPS_DIR)) {
            @mkdir(HDS3_ADMIN_ZIPS_DIR, 0755, true);
        }

        $zip_path = hds3_local_zip_path($slug, $ver);

        // cache: jeśli zip istnieje i jest nowszy niż źródła, nie przebudowujemy.
        $need = true;
        if (file_exists($zip_path)) {
            $zip_mtime = @filemtime($zip_path);
            if ($zip_mtime) {
                $need = false;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    /** @var SplFileInfo $f */
                    $mt = $f->getMTime();
                    if ($mt > $zip_mtime) { $need = true; break; }
                }
            }
        }

        if (!$need) return $zip_path;

        // buduj zip
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $base_len = strlen(rtrim($src, '/\\')) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $p = $f->getPathname();
            $rel = substr($p, $base_len);
            $rel = str_replace('\\', '/', $rel);
            if ($f->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($p, $rel);
            }
        }
        $zip->close();

        return file_exists($zip_path) ? $zip_path : null;
    }
}

// Handler pobierania ZIP przed wyrenderowaniem portalu
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;

    $slug = isset($_GET['hds3_zip']) ? sanitize_key((string)$_GET['hds3_zip']) : '';
    if ($slug === '') return;

    // tylko w obrębie portalu i zakładki "Pobrane"
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? trim($path, '/') : '';
    if (!(strpos($path, 'portal/pobrane') === 0)) return;

    $user = wp_get_current_user();
    $tenant_label = hds3_portal_current_tenant_label($user->ID);
    $entitled = hds3_portal_get_client_entitled_slugs($tenant_label, $user->ID);
    if (!in_array($slug, $entitled, true)) {
        wp_die('Brak dostępu do pobrania tej paczki.');
    }

    if (hds3_is_forbidden_zip_slug($slug)) {
        wp_die('To jest paczka systemowa — generowanie ZIP jest zablokowane.');
    }

    // wersja: bierzemy z katalogu (jeśli jest), inaczej pusta
    $ver = '';
    foreach (hds3_portal_catalog() as $p) {
        if (sanitize_key((string)($p['slug'] ?? '')) === $slug) {
            $ver = (string)($p['latest'] ?? '');
            break;
        }
    }
    $ver_clean = preg_replace('/[^0-9A-Za-z\._-]/', '', (string)$ver);

    $zip_path = hds3_build_local_zip($slug, $ver_clean);
    if (!$zip_path) {
        wp_die('Brak paczki do pobrania. Administrator musi umieścić pliki w: <code>'.esc_html('admin_products/'.$slug.'/').'</code>.');
    }

    // Serve
    if (!headers_sent()) {
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.basename($zip_path).'"');
        header('Content-Length: '.filesize($zip_path));
    }
    @readfile($zip_path);
    exit;
}, 0);


add_action('template_redirect', function () {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? trim($path, '/') : '';
    if ($path === '') return;

    // dopasuj /portal lub /portal/*
    if ($path === 'portal' || strpos($path, 'portal/') === 0) {

        // auth
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(home_url('/portal/')));
            exit;
        }

        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        $is_client = in_array('hds3_client', $roles, true) || in_array('subscriber', $roles, true);

        if (!$is_client) {
            wp_die('Brak uprawnień do Portalu Klienta.');
        }

        // page slug
        $parts = explode('/', $path);
        $page = isset($parts[1]) && $parts[1] !== '' ? sanitize_key($parts[1]) : 'dashboard';

        $nav = [
            'dashboard'   => 'Dashboard',
            'licencje'    => 'Licencje',
            'pobrane'     => 'Pobrane',
            'telemetria'  => 'Telemetria',
            'ustawienia'  => 'Ustawienia'
        ];

        if (!isset($nav[$page])) {
            $page = 'dashboard';
        }

        // headers
        if (!headers_sent()) {
            nocache_headers();
            // Force 200 OK (portal is rendered via hard router, WP query may be 404)
            if (function_exists('status_header')) { status_header(200); }
            global $wp_query;
            if (isset($wp_query) && is_object($wp_query)) { $wp_query->is_404 = false; }

            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Portal Klienta</title>';
        echo '<style>
            :root{--het-bg-0:#070b12;--het-bg-1:#061018;--het-text:#e5e7eb;--het-line:rgba(255,255,255,.10);--het-card:rgba(255,255,255,.06);--het-radius:16px;}
            body{margin:0;color:var(--het-text);font-family:ui-sans-serif,system-ui;background:radial-gradient(900px 520px at 20% -10%, rgba(77,215,255,.22), transparent 55%),radial-gradient(820px 520px at 85% 0%, rgba(55,255,122,.18), transparent 55%),linear-gradient(180deg,var(--het-bg-0),var(--het-bg-1));background-attachment:fixed}
            .container{max-width:1200px;margin:40px auto;padding:20px}
            .card{background:var(--het-card);border:1px solid var(--het-line);border-radius:var(--het-radius);padding:20px}
            .nav{display:flex;gap:14px;margin-bottom:22px;flex-wrap:wrap}
            .nav a{color:var(--het-text);text-decoration:none;padding:8px 14px;border-radius:20px;background:rgba(255,255,255,.08);border:1px solid var(--het-line)}
            .nav a.active{background:#00219770;border-color:#4dd7ff}
            h1{margin-top:0}
            .muted{opacity:.75}
            .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
            @media(max-width:960px){.grid{grid-template-columns:1fr}}
            .card{border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.22);border-radius:18px;padding:16px;box-shadow:0 12px 28px rgba(0,0,0,.22)}
            .card h3{margin:0 0 10px;font-size:14px;opacity:.92}
            .big{font-size:28px;font-weight:750;margin-top:6px}
            .btn{display:inline-block;padding:8px 14px;border-radius:999px;border:1px solid var(--het-line);background:rgba(255,255,255,.08);color:var(--het-text);text-decoration:none}
            .btn:hover{background:rgba(77,215,255,.10)}
            .table{width:100%;border-collapse:separate;border-spacing:0 8px}
            .table th{font-size:12px;opacity:.75;text-align:left;padding:0 10px}
            .table td{padding:10px;background:rgba(0,0,0,.18);border-top:1px solid rgba(255,255,255,.08);border-bottom:1px solid rgba(255,255,255,.08)}
            .table tr td:first-child{border-left:1px solid rgba(255,255,255,.08);border-top-left-radius:14px;border-bottom-left-radius:14px}
            .table tr td:last-child{border-right:1px solid rgba(255,255,255,.08);border-top-right-radius:14px;border-bottom-right-radius:14px}
            .pill{display:inline-flex;padding:3px 10px;border-radius:999px;border:1px solid rgba(77,215,255,.35);background:rgba(77,215,255,.10);font-size:12px}
            .topbar{position:relative;display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px}
            #heterodyna-portal-topbar .het-topbar__logo{height:150px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 8px 18px rgba(0,0,0,.55))}
            #heterodyna-portal-topbar .het-topbar__brand{display:flex;align-items:center;gap:12px;text-decoration:none}
            #heterodyna-portal-topbar .het-topbar__brand span{font-weight:750;letter-spacing:.2px;opacity:.92}
            .topbar .nav{display:flex;gap:14px;flex-wrap:wrap;margin:0}
            @media(max-width:980px){#heterodyna-portal-topbar .het-topbar__logo{height:90px}}

            .userpanel{position:relative;display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:999px;background:rgba(0,0,0,.22);border:1px solid var(--het-line)}
            .userpanel__avatar{width:34px;height:34px;border-radius:999px;object-fit:cover;border:1px solid rgba(255,255,255,.18)}
            .userpanel__meta{line-height:1.1}
            .userpanel__name{font-weight:650;font-size:13px}
            .userpanel__role{opacity:.72;font-size:12px}
            .userpanel__btn{margin-left:6px;cursor:pointer;border:1px solid var(--het-line);background:rgba(255,255,255,.08);color:var(--het-text);border-radius:999px;padding:6px 10px;font-size:12px}
            .userpanel__menu{display:none;position:absolute;right:0;top:48px;min-width:180px;background:rgba(8,12,18,.92);border:1px solid var(--het-line);border-radius:14px;overflow:hidden;backdrop-filter: blur(8px);box-shadow:0 18px 40px rgba(0,0,0,.35)}
            .userpanel__menu a{display:block;padding:10px 12px;color:var(--het-text);text-decoration:none;border-top:1px solid rgba(255,255,255,.06)}
            .userpanel__menu a:hover{background:rgba(77,215,255,.10)}
            .userpanel.is-open .userpanel__menu{display:block}

            .badge{display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding:4px 10px;border-radius:999px;background:rgba(77,215,255,.10);border:1px solid rgba(77,215,255,.35);font-size:12px;opacity:.92}
            .badge b{font-weight:700}


        </style></head><body>';

        echo '<div class="container">';
        echo '<div class="topbar" id="heterodyna-portal-topbar">';
        
        $brand_logo = hds3_brand_logo_src();
        $brand_url = home_url('/portal/dashboard/');
        echo '<a class="het-topbar__brand" href="'.hds3_safe_url($brand_url).'" aria-label="Heterodyna Team Software">';
        echo '<img class="het-topbar__logo" src="'.hds3_safe_url($brand_logo).'" alt="Heterodyna Team Software">';
        echo '<span>Heterodyna Team Software</span>';
        echo '</a>';

        $avatar = get_avatar_url($user->ID);
        if (!is_string($avatar) || $avatar === '') {
            // Fallback: lokalny, lekki avatar SVG (bez zewnętrznych żądań)
            $avatar = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDY0IDY0Ij48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwIiB5MT0iMCIgeDI9IjEiIHkyPSIxIj48c3RvcCBzdG9wLWNvbG9yPSIjN2EwMGRmIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMDBkNGZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+PGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMzAiIGZpbGw9InVybCgjZykiLz48cGF0aCBkPSJNMzIgMzRjLTcuNyAwLTE0LTYuMy0xNC0xNHM2LjMtMTQgMTQtMTQgMTQgNi4zIDE0IDE0LTYuMyAxNC0xNCAxNHptMC00YzUuNSAwIDEwLTQuNSAxMC0xMHMtNC41LTEwLTEwLTEwLTEwIDQuNS0xMCAxMCA0LjUgMTAgMTAgMTB6bS0yMCAzNmMwLTkuOSA4LjEtMTggMTgtMThoNEM0My45IDQ4IDUyIDU2LjEgNTIgNjZ2MmgtNDB2LTJ6IiBmaWxsPSIjMGIxMDIwIiBvcGFjaXR5PSIwLjg4Ii8+PC9zdmc=';
        }
        $role_label = !empty($roles) ? $roles[0] : 'użytkownik';
        $tenant_label = get_user_meta($user->ID, 'hds3_tenant', true);
        if (!$tenant_label) {
            $host = parse_url(home_url('/'), PHP_URL_HOST);
            $tenant_label = $host ? $host : 'tenant';
        }
        echo '<div class="userpanel" id="hds3-userpanel">';
        echo '<img class="userpanel__avatar" src="'.hds3_safe_url((string)$avatar).'" alt="">';
        echo '<div class="userpanel__meta">';
        echo '<div class="userpanel__name">'.hds3_safe_html($user->display_name ? $user->display_name : $user->user_login).'</div>';
        echo '<div class="userpanel__role">'.hds3_safe_html($role_label).'</div>';
        echo '</div>';
        echo '<button type="button" class="userpanel__btn" aria-haspopup="true" aria-expanded="false">Konto ▾</button>';
        echo '<div class="userpanel__menu" role="menu">';
        echo '<a href="'.hds3_safe_url(home_url('/portal/ustawienia/')).'">Ustawienia</a>';
        echo '<a href="'.hds3_safe_url(wp_logout_url(home_url('/'))).'">Wyloguj się</a>';
        echo '</div>'; // userpanel__menu
        echo '</div>'; // userpanel
        // Tabs
        echo '<div class="nav">';
        $tabs = [
            'dashboard'  => 'Dashboard',
            'licencje'   => 'Licencje',
            'pobrane'    => 'Pobrane',
            'telemetria' => 'Telemetria',
            'ustawienia' => 'Ustawienia',
        ];
        foreach ($tabs as $k=>$label) {
            // $tab bywa niezdefiniowany (warning w UI). Aktywną zakładkę wyznaczamy na podstawie $page.
            $active = ($page === $k) ? ' active' : '';
            $url = home_url('/portal/' . $k . '/');
            echo '<a class="tab'.$active.'" href="'.hds3_safe_url($url).'">'.hds3_safe_html($label).'</a>';
        }
        echo '</div>'; // nav
        echo '</div>'; // topbar

        echo '<script>(function(){var p=document.getElementById("hds3-userpanel"); if(!p) return; var b=p.querySelector(".userpanel__btn"); b.addEventListener("click",function(e){e.preventDefault(); e.stopPropagation(); p.classList.toggle("is-open"); b.setAttribute("aria-expanded", p.classList.contains("is-open") ? "true":"false");}); document.addEventListener("click",function(){p.classList.remove("is-open"); b.setAttribute("aria-expanded","false");});})();</script>';

        echo '<div class="card">';
        echo '<h1>'.$nav[$page].'</h1>';
        echo '<p class="muted">Zalogowany jako: <strong>'.hds3_safe_html($user->user_login).'</strong></p>';
        echo '<p class="muted">Role: '.hds3_safe_html(implode(',', $roles)).'</p>';

        switch ($page) {
            case 'dashboard':
            $tenant_label = hds3_portal_current_tenant_label($user->ID);
            $tel = hds3_portal_get_client_telemetry($tenant_label, $user->ID);
            echo '<h1>Dashboard</h1>';
            echo '<p class="muted">Tenant: <strong>'.hds3_safe_html($tenant_label).'</strong> • Zalogowany jako: <strong>'.hds3_safe_html($user->user_login).'</strong></p>';
            echo '<div class="grid">';
            echo '<div class="card"><h3>Zapytania (24h)</h3><div class="big">'.intval($tel['requests_24h']).'</div><div class="muted">Ostatnio: '.hds3_safe_html($tel['last_seen']).'</div></div>';
            echo '<div class="card"><h3>Ostrzeżenia</h3><div class="big">'.intval($tel['warnings_24h']).'</div><div class="muted">24h</div></div>';
            echo '<div class="card"><h3>Błędy</h3><div class="big">'.intval($tel['errors_24h']).'</div><div class="muted">24h</div></div>';
            echo '</div>';
            echo '<div class="card" style="margin-top:14px"><h3>Szybkie akcje</h3>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">';
            echo '<a class="btn" href="'.hds3_safe_url(home_url('/portal/licencje/')).'">Moje licencje</a>';
            echo '<a class="btn" href="'.hds3_safe_url(home_url('/portal/pobrane/')).'">Pobierz wtyczki</a>';
            echo '<a class="btn" href="'.hds3_safe_url(home_url('/portal/telemetria/')).'">Telemetria</a>';
            echo '</div></div>';
            break;
            case 'licencje':
            $tenant_label = hds3_portal_current_tenant_label($user->ID);
            $rows = hds3_portal_get_client_licenses($tenant_label, $user->ID);
            echo '<h1>Licencje</h1>';
            echo '<p class="muted">Poniżej znajdują się aktywne licencje przypisane do Twojego konta w ramach tenant: <strong>'.hds3_safe_html($tenant_label).'</strong>.</p>';
            echo '<div class="card" style="margin-top:12px">';
            echo '<table class="table"><thead><tr><th>Produkt</th><th>Pakiet</th><th>Status</th><th>Wygasa</th><th>Klucz</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>'.hds3_safe_html($r['product']).'</td>';
                echo '<td>'.hds3_safe_html($r['plan']).'</td>';
                echo '<td><span class="pill">'.hds3_safe_html($r['status']).'</span></td>';
                echo '<td>'.hds3_safe_html($r['expires']).'</td>';
                echo '<td><code>'.hds3_safe_html($r['key_mask']).'</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            break;
            case 'pobrane':
            $tenant_label = hds3_portal_current_tenant_label($user->ID);
            $entitled = hds3_portal_get_client_entitled_slugs($tenant_label, $user->ID);
            $base = hds3_portal_download_base_url();
            $sub = sanitize_key($_GET['sub'] ?? 'wtyczki');
            if (!in_array($sub, ['wtyczki','instrukcje','historia'], true)) $sub = 'wtyczki';

            echo '<h1>Pobrane</h1>';
            echo '<p class="muted">Tutaj pobierzesz wtyczki i paczki, za które masz aktywny dostęp. Tenant: <strong>'.hds3_safe_html($tenant_label).'</strong>.</p>';

            // Sub-tabs
            $subtabs = [
                'wtyczki' => 'Wtyczki',
                'instrukcje' => 'Instrukcje',
                'historia' => 'Historia',
            ];
            echo '<div class="nav" style="margin-top:10px">';
            foreach ($subtabs as $sk=>$sl) {
                $active = ($sub === $sk) ? ' active' : '';
                $u = add_query_arg('sub', $sk, home_url('/portal/pobrane/'));
                echo '<a class="tab'.$active.'" href="'.hds3_safe_url($u).'">'.hds3_safe_html($sl).'</a>';
            }
            echo '</div>';

            if ($sub === 'instrukcje') {
                echo '<div class="card" style="margin-top:12px">';
                echo '<h3>Jak instalować aktualizacje (upgrade)</h3>';
                echo '<ol class="muted" style="margin:0;padding-left:18px">';
                echo '<li>Pobierz ZIP wtyczki.</li>';
                echo '<li>Wgraj i <strong>nadpisz</strong> istniejący katalog wtyczki na serwerze (<code>wp-content/plugins/</code>).</li>';
                echo '<li>Zrób <strong>Ctrl+Shift+R</strong> i sprawdź panel.</li>';
                echo '</ol>';
                echo '<p class="muted" style="margin-top:10px">Docelowo: aktualizacje zdalne po tokenie (LA) + log powodów błędu.</p>';
                echo '</div>';
                break;
            }

            if ($sub === 'historia') {
                echo '<div class="card" style="margin-top:12px">';
                echo '<h3>Historia pobrań</h3>';
                echo '<p class="muted">W kolejnym ZIP podepniemy log pobrań (tenant/user, IP, wersja, wynik) do telemetrii.</p>';
                echo '</div>';
                break;
            }

            // Wtyczki
            if ($base === '') {
                echo '<div class="card" style="margin-top:12px">';
                echo '<h3>Brak skonfigurowanego serwera pobierania</h3>';
                echo '<p class="muted">Administrator musi ustawić <code>hds3_download_base_url</code> (URL do serwera licencyjnego / sklepu) — wtedy przycisk „Pobierz” będzie aktywny.</p>';
                echo '</div>';
            }

            $catalog = hds3_portal_catalog();
            echo '<div class="card" style="margin-top:12px">';
            echo '<table class="table"><thead><tr><th>Produkt</th><th>Typ</th><th>Wersja</th><th>Dostęp</th><th>ZIP</th></tr></thead><tbody>';
            foreach ($catalog as $p) {
                $slug = sanitize_key((string)($p['slug'] ?? ''));
                $name = (string)($p['name'] ?? $slug);
                $kind = (string)($p['kind'] ?? 'wtyczka');
                $latest = (string)($p['latest'] ?? '');
                $desc = (string)($p['desc'] ?? '');
                $has = in_array($slug, $entitled, true);

                // Lokalny ZIP (generowany automatycznie z katalogu admin_products/<slug>/)
                $zip_btn = '<span class="muted">—</span>';
                if ($has && !hds3_is_forbidden_zip_slug($slug) && is_dir(hds3_local_product_dir($slug))) {
                    $u = add_query_arg('hds3_zip', $slug, home_url('/portal/pobrane/'));
                    $zip_btn = '<a class="btn" href="'.hds3_safe_url($u).'">Pobierz ZIP</a>';
                } elseif ($has && !hds3_is_forbidden_zip_slug($slug) && !is_dir(hds3_local_product_dir($slug))) {
                    $zip_btn = '<span class="muted">brak plików</span>';
                }

                echo '<tr>';
                echo '<td>'.hds3_safe_html($name).'<div class="muted" style="font-size:12px;margin-top:4px">'.hds3_safe_html($desc).'</div></td>';
                echo '<td>'.hds3_safe_html($kind).'</td>';
                echo '<td><span class="pill">'.hds3_safe_html($latest).'</span></td>';
                echo '<td>'.($has ? '<span class="pill">aktywne</span>' : '<span class="pill" style="border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.06)">brak</span>').'</td>';
                echo '<td>'.$zip_btn.'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            break;
            case 'telemetria':
            $tenant_label = hds3_portal_current_tenant_label($user->ID);
            $tel = hds3_portal_get_client_telemetry($tenant_label, $user->ID);
            echo '<h1>Telemetria</h1>';
            echo '<p class="muted">Podgląd zdrowia usług dla tenant: <strong>'.hds3_safe_html($tenant_label).'</strong>.</p>';
            echo '<div class="grid" style="margin-top:12px">';
            echo '<div class="card"><h3>Zapytania (24h)</h3><div class="big">'.intval($tel['requests_24h']).'</div></div>';
            echo '<div class="card"><h3>Ostrzeżenia (24h)</h3><div class="big">'.intval($tel['warnings_24h']).'</div></div>';
            echo '<div class="card"><h3>Błędy (24h)</h3><div class="big">'.intval($tel['errors_24h']).'</div></div>';
            echo '</div>';
            echo '<div class="card" style="margin-top:14px"><h3>Ostatnie zdarzenie</h3><p class="muted">Last seen: '.hds3_safe_html($tel['last_seen']).'</p>';
            echo '<p class="muted">Następny krok: podpinamy to do tabel telemetrii HDS3 i pokażemy logi per usługa.</p></div>';
            break;
            case 'ustawienia':
                // Ustawienia telemetrii (zgodnie z prawem: podstawowa bez danych osobowych, rozszerzona opt-in)
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hds3_tel_save'])) {
                    if (function_exists('check_admin_referer')) {
                        check_admin_referer('hds3_tel_settings');
                    }
                    $basic = isset($_POST['hds3_tel_basic']) ? '1' : '0';
                    $ext   = isset($_POST['hds3_tel_extended']) ? '1' : '0';
                    update_option('hds3_tel_basic', $basic, true);
                    update_option('hds3_tel_extended', $ext, true);
                    echo '<div class="card" style="border-color:#22c55e"><b>Zapisano.</b> Zmiany telemetrii zastosują się automatycznie.</div>';
                }

                $basic_on = hds3_tel_opt_basic();
                $ext_on   = hds3_tel_opt_extended();

                echo '<h1>Ustawienia</h1>';
                echo '<div class="card"><h2>Telemetria</h2>';
                echo '<p class="muted">Zbieramy wyłącznie dane techniczne (bez danych osobowych). Host/domena jest haszowana. Rozszerzona telemetria jest opcjonalna (opt-in).</p>';
                echo '<form method="post">';
                if (function_exists('wp_nonce_field')) wp_nonce_field('hds3_tel_settings');
                echo '<label style="display:block;margin:10px 0;"><input type="checkbox" name="hds3_tel_basic" '.($basic_on?'checked':'').' /> <b>Podstawowa telemetria</b> (wersje, obciążenie, limity, agregaty błędów)</label>';
                echo '<label style="display:block;margin:10px 0;"><input type="checkbox" name="hds3_tel_extended" '.($ext_on?'checked':'').' /> <b>Rozszerzona telemetria diagnostyczna</b> (snapshoty do tabeli zdarzeń, bez treści i bez danych osobowych)</label>';
                echo '<button class="btn" type="submit" name="hds3_tel_save" value="1">Zapisz</button>';
                echo '</form></div>';

                // Podgląd: ostatni snapshot
                $snap = get_option('hds3_tel_last_snapshot', array());
                if (is_array($snap) && !empty($snap)) {
                    echo '<div class="card"><h2>Ostatni snapshot</h2>';
                    echo '<div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">';
                    echo '<div class="stat"><div class="k">Ostatni pomiar</div><div class="v">'.hds3_safe_html(date('Y-m-d H:i', (int)$snap['ts'])).'</div></div>';
                    echo '<div class="stat"><div class="k">Load 1/5/15</div><div class="v">'.hds3_safe_html((string)($snap['load1'] ?? 'n/a')).' / '.hds3_safe_html((string)($snap['load5'] ?? 'n/a')).' / '.hds3_safe_html((string)($snap['load15'] ?? 'n/a')).'</div></div>';
                    echo '<div class="stat"><div class="k">Deprecated 24h</div><div class="v">'.hds3_safe_html((string)($snap['deprecated_24h'] ?? 0)).'</div></div>';
                    echo '</div></div>';
                }
                break;
        }

        echo '<div style="margin-top:20px"><a href="'.hds3_safe_url(wp_logout_url(home_url('/'))).'" style="display:inline-block;padding:8px 16px;border-radius:20px;background:#00219770;border:1px solid #4dd7ff;color:#e5e7eb;text-decoration:none">Wyloguj się</a></div>';
echo '</div></div></body></html>';
        exit;
    }
}, 0);




/**
 * Launcher-mode: rejestrujemy ukryte strony, zeby linki z paska admina NIGDY nie dawały 404/Brak uprawnień.
 * Nie dodajemy ich do bocznego menu (bo boczne menu i tak ukrywamy).
 */
add_action('admin_menu', function () {

    // Alias: SaaS v3
    add_submenu_page(
        null,
        'Heterodyna Cloud',
        'Heterodyna Cloud',
        'read',
        'heterodyna_saas_v3',
        function () {
            wp_safe_redirect(admin_url('admin.php?page=hds3_center'));
            exit;
        }
    );

    // Alias: Cybersecurity
    add_submenu_page(
        null,
        'Cybersecurity',
        'Cybersecurity',
        'read',
        'heterodyna_cybersecurity',
        function () {
            wp_safe_redirect(admin_url('admin.php?page=hds3_center#hds3w_hds3_security'));
            exit;
        }
    );

    // Alias: Audit Site
    add_submenu_page(
        null,
        'Audit Site',
        'Audit Site',
        'manage_options',
        'heterodyna_audit_site',
        'hds3_render_audit_site'
    );

}, 1);

/**
 * Widgety kokpitu (praktyczne KPI + obciążenie serwera + statusy wtyczek).
 */
// Legacy WP dashboard widgets are intentionally not registered.
// The dedicated Heterodyna Cloud console is the canonical dashboard.



/**
 * v03.00.122
 * Przywrócono natywny pasek WordPress i lewe menu admina.
 * Niestandardowy topbar / modyfikacje adminbara HDS3 zostały wyłączone.
 */

add_action('admin_head', function() {
    // v03.00.122: bez ukrywania lewego menu i bez nadpisywania natywnego headera WP.
});

/**
 * Render: Centrum SaaS v3 (efekt wow + praktyka).
 */
function hds3_render_control_panel() {
    if (!current_user_can('read')) wp_die('Brak uprawnień.');
    wp_safe_redirect(admin_url('admin.php?page=hds3_center'));
    exit;
}

/**
 * Render: Audit Site (lekki audyt – bezpieczny, praktyczny).
 */
function hds3_render_audit_site() {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
    $site = home_url('/');
    $php = PHP_VERSION;
    $wp = get_bloginfo('version');
    $https = is_ssl() ? 'TAK' : 'NIE';
    $uploads = wp_upload_dir();
    $writable = is_writable($uploads['basedir']) ? 'TAK' : 'NIE';
    ?>
    <div class="wrap">
        <h1>Audit Site</h1>
        <p style="opacity:.85">Szybki audyt podstaw (pod kolejne automaty).</p>
        <table class="widefat striped" style="max-width:980px;">
            <tbody>
                <tr><td><b>Adres</b></td><td><?php echo hds3_safe_html($site); ?></td></tr>
                <tr><td><b>HTTPS</b></td><td><?php echo hds3_safe_html($https); ?></td></tr>
                <tr><td><b>WordPress</b></td><td><?php echo hds3_safe_html($wp); ?></td></tr>
                <tr><td><b>PHP</b></td><td><?php echo hds3_safe_html($php); ?></td></tr>
                <tr><td><b>Uploads writable</b></td><td><?php echo hds3_safe_html($writable); ?></td></tr>
            </tbody>
        </table>
        <p style="margin-top:14px;">
            <a class="button button-primary" href="<?php echo hds3_safe_url(admin_url('admin.php?page=hds3_center')); ?>">Wróć do Centrum</a>
        </p>
    </div>
    <?php
}

/**
 * Dashboard widget: KPI
 */
function hds3_dashboard_widget_kpi() {
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
    $u = wp_get_current_user();
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
    echo '<div style="min-width:220px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-weight:800;">Tenant</div><div>'.hds3_safe_html($host).'</div></div>';
    echo '<div style="min-width:220px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-weight:800;">Użytkownik</div><div>'.hds3_safe_html($u->user_login).'</div></div>';
    echo '<div style="min-width:220px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-weight:800;">Centrum</div><div><a href="'.hds3_safe_url(admin_url('admin.php?page=hds3_center')).'">Otwórz</a></div></div>';
    echo '</div>';
}

/**
 * Dashboard widget: Serwer
 */
function hds3_dashboard_widget_server() {
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    $load1 = is_array($load) ? floatval($load[0]) : 0.0;
    $mem = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
    $mem_mb = $mem ? round($mem/1024/1024,1) : 0;
    echo '<div style="display:flex;gap:14px;flex-wrap:wrap;">';
    echo '<div style="min-width:160px;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-size:12px;opacity:.7">Load (1m)</div><div style="font-size:22px;font-weight:900;">'.hds3_safe_html(number_format($load1,2)).'</div></div>';
    echo '<div style="min-width:160px;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-size:12px;opacity:.7">RAM (PHP)</div><div style="font-size:22px;font-weight:900;">'.hds3_safe_html($mem_mb.' MB').'</div></div>';
    echo '<div style="min-width:160px;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.5);"><div style="font-size:12px;opacity:.7">PHP</div><div style="font-size:22px;font-weight:900;">'.hds3_safe_html(PHP_VERSION).'</div></div>';
    echo '</div>';
}

/**
 * Dashboard widget: Architektura (mini)
 */
function hds3_dashboard_widget_architecture() {
    echo '<div style="opacity:.85;margin-bottom:8px;">Pogląd architektury (mini). Pełna wersja: <a href="'.hds3_safe_url(admin_url('admin.php?page=hds3_center')).'">Centrum</a></div>';
    echo '<div style="max-width:900px;">'.hds3_architecture_svg_mini().'</div>';
}

function hds3_dashboard_widget_telemetry() {
    $snap = get_option('hds3_tel_last_snapshot', array());
    if (!is_array($snap) || empty($snap)) {
        echo '<p class="muted">Brak danych. Telemetria zbierze pierwszy snapshot w ciągu kilku minut (cron).</p>';
        echo '<p><a href="'.hds3_safe_url(admin_url('admin.php?page=hds3_center')).'">Otwórz Heterodyna Cloud</a></p>';
        return;
    }

    $ring = get_option('hds3_tel_ring', array());
    if (!is_array($ring)) $ring = array();

    $load_vals = array();
    $dep_vals  = array();
    foreach ($ring as $pt) {
        if (!is_array($pt)) continue;
        $load_vals[] = isset($pt['load1']) ? (float)$pt['load1'] : 0.0;
        $dep_vals[]  = isset($pt['deprecated_24h']) ? (float)$pt['deprecated_24h'] : 0.0;
    }
    $spark = function($values) {
        $values = array_values(array_filter($values, function($v){ return is_numeric($v); }));
        if (count($values) < 2) return '';
        $w=220; $h=40; $pad=4;
        $min=min($values); $max=max($values);
        if ($max - $min < 0.0001) { $max = $min + 1; }
        $pts=[];
        $n=count($values);
        for ($i=0;$i<$n;$i++){
            $x=$pad + ($i * ($w-2*$pad)/max(1,($n-1)));
            $y=$pad + ($h-2*$pad) * (1 - (($values[$i]-$min)/($max-$min)));
            $pts[] = round($x,2).','.round($y,2);
        }
        $poly = implode(' ', $pts);
        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg"><polyline fill="none" stroke="rgba(29,78,216,.9)" stroke-width="2" points="'.$poly.'" /><rect x="0" y="0" width="'.$w.'" height="'.$h.'" rx="10" ry="10" fill="none" stroke="rgba(0,0,0,.08)"/></svg>';
    };

    echo '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">';
    echo '<div style="min-width:220px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.55)">';
    echo '<div style="font-weight:800;margin-bottom:6px">Ostatni snapshot</div>';
    echo '<div style="opacity:.85">Czas: <b>'.hds3_safe_html(date('Y-m-d H:i', (int)$snap['ts'])).'</b></div>';
    echo '<div style="opacity:.85">Load1: <b>'.hds3_safe_html((string)($snap['load1'] ?? 'n/a')).'</b></div>';
    echo '<div style="opacity:.85">Dysk: <b>'.hds3_safe_html(isset($snap['disk_pct']) && $snap['disk_pct']!==null ? round((float)$snap['disk_pct'],1).'%' : 'n/a').'</b></div>';
    echo '<div style="opacity:.85">Deprecated 24h: <b>'.hds3_safe_html((string)($snap['deprecated_24h'] ?? 0)).'</b></div>';
    echo '<div style="margin-top:8px"><a class="button button-small" href="'.hds3_safe_url(admin_url('admin.php?page=hds3_center')).'">Centrum</a></div>';
    echo '</div>';

    echo '<div style="min-width:240px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.55)">';
    echo '<div style="font-weight:800;margin-bottom:6px">Trend load (24h)</div>';
    echo $spark($load_vals);
    echo '</div>';

    echo '<div style="min-width:240px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.55)">';
    echo '<div style="font-weight:800;margin-bottom:6px">Trend deprecated (24h)</div>';
    echo $spark($dep_vals);
    echo '</div>';

    echo '</div>';
}

function hds3_dashboard_widget_neural_core() {
    if (!current_user_can('read')) return;

    // Wykryj Inspektora (jeśli jest aktywny) — tylko do linkowania.
    $inspektor_active = false;
    if (is_admin()) {
        if (!function_exists('is_plugin_active')) {
            @require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('is_plugin_active')) {
            $inspektor_active = is_plugin_active('heterodyna-inspektor/heterodyna-inspektor.php') || is_plugin_active('heterodyna-inspektor/inspektor.php');
        }
    }

    $explorer_url = admin_url('admin.php?page=het-inspektor-explorer');

    echo '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">';
    echo '<div style="flex:1;min-width:360px;padding:12px;border-radius:14px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.55)">';
    echo '<div style="font-weight:900;font-size:14px;margin-bottom:6px">Neuron Core — graf zależności (pseudo 3D)</div>';
    echo '<div style="opacity:.78;margin-bottom:10px;">Pogląd zależności modułów. Statusy są poglądowe (pełne dane będą pobierane z Inspektora/telemetrii).</div>';
    echo hds3_neural_core_svg_3d();
    echo '</div>';

    echo '<div style="min-width:260px;padding:12px;border-radius:14px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.55)">';
    echo '<div style="font-weight:900;font-size:14px;margin-bottom:6px">Inspektor</div>';
    echo '<div style="opacity:.82;margin-bottom:10px">'.($inspektor_active ? '✅ Wtyczka Inspektor wygląda na aktywną.' : 'ℹ️ Inspektor nie jest wykryty jako aktywny — używam trybu fallback.').'</div>';
    echo '<a class="button button-primary" href="'.hds3_safe_url($explorer_url).'" target="_blank" rel="noopener">Otwórz Explorer</a>';
    echo '<div style="opacity:.7;margin-top:10px;font-size:12px">Cel: tu podpinamy realne zależności jako dane + statusy + obciążenia (live overlay).</div>';
    echo '</div>';

    echo '</div>';
}

function hds3_neural_core_svg_3d(): string {
    // Lekki, statyczny SVG z efektem pseudo-3D (izometria + cienie).
    // Docelowo: WebGL/Canvas + live overlay metryk.
    return '<svg viewBox="0 0 760 260" width="100%" height="260" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Neuron Core 3D">
        <defs>
            <linearGradient id="ncg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#7a00df" stop-opacity="0.95"/>
                <stop offset="1" stop-color="#00d4ff" stop-opacity="0.95"/>
            </linearGradient>
            <filter id="ncs" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="10" stdDeviation="10" flood-color="#000" flood-opacity="0.25"/>
            </filter>
            <filter id="ncp" x="-40%" y="-40%" width="180%" height="180%">
                <feDropShadow dx="0" dy="0" stdDeviation="10" flood-color="#00d4ff" flood-opacity="0.25"/>
                <feDropShadow dx="0" dy="0" stdDeviation="14" flood-color="#7a00df" flood-opacity="0.20"/>
            </filter>
        </defs>

        <rect x="0" y="0" width="760" height="260" rx="16" fill="rgba(2,8,23,0.10)" stroke="rgba(0,0,0,0.06)"/>

        <!-- Połączenia (pseudo 3D) -->
        <g stroke="rgba(0,0,0,0.22)" stroke-width="2" opacity="0.85">
            <path d="M380 120 L180 70" />
            <path d="M380 120 L560 70" />
            <path d="M380 120 L160 200" />
            <path d="M380 120 L600 200" />
            <path d="M380 120 L380 30" />
            <path d="M380 120 L380 230" />
        </g>

        <!-- Node centralny: Neuron Core -->
        <g filter="url(#ncp)">
            <polygon points="380,70 450,105 380,140 310,105" fill="url(#ncg)" opacity="0.92"/>
            <polygon points="310,105 380,140 380,190 310,155" fill="rgba(122,0,223,0.22)"/>
            <polygon points="450,105 380,140 380,190 450,155" fill="rgba(0,212,255,0.22)"/>
            <text x="380" y="118" text-anchor="middle" font-size="14" font-weight="900" fill="rgba(11,16,32,0.92)">NEURON CORE</text>
        </g>

        <!-- Moduły wokół (izometria) -->
        <g filter="url(#ncs)">
            <!-- LICENSE -->
            <g transform="translate(350,10)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">LICENSE</text>
            </g>

            <!-- SEO -->
            <g transform="translate(110,45)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">SEO</text>
            </g>

            <!-- CYBER -->
            <g transform="translate(520,45)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">CYBER</text>
            </g>

            <!-- AUDIT -->
            <g transform="translate(90,175)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">AUDIT</text>
            </g>

            <!-- TELEMETRY -->
            <g transform="translate(560,175)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">TEL</text>
            </g>

            <!-- INSPEKTOR -->
            <g transform="translate(350,210)">
                <polygon points="30,10 85,35 30,60 -25,35" fill="rgba(255,255,255,0.65)" stroke="rgba(0,0,0,0.10)"/>
                <text x="30" y="40" text-anchor="middle" font-size="12" font-weight="800" fill="rgba(0,0,0,0.72)">INSPEKTOR</text>
            </g>
        </g>

        <text x="18" y="242" font-size="12" fill="rgba(0,0,0,0.55)">Tryb: pogląd (3D SVG). Następny krok: live overlay metryk + realne zależności z Inspektora.</text>
    </svg>';
}

function hds3_architecture_svg_mini() {
    return '<svg viewBox="0 0 520 160" width="100%" height="160" aria-hidden="true">
        <defs>
            <linearGradient id="mg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#7a00df" stop-opacity="0.85"/>
                <stop offset="1" stop-color="#00d4ff" stop-opacity="0.85"/>
            </linearGradient>
        </defs>
        <rect x="0" y="0" width="520" height="160" rx="14" fill="rgba(2,8,23,0.12)" stroke="rgba(0,0,0,0.06)"/>
        <rect x="24" y="86" width="160" height="50" rx="12" fill="url(#mg)" opacity="0.18" stroke="rgba(0,0,0,0.08)"/>
        <text x="40" y="116" font-size="14" font-weight="800" fill="rgba(0,0,0,0.75)">Tenants</text>

        <rect x="198" y="54" width="190" height="60" rx="12" fill="url(#mg)" opacity="0.22" stroke="rgba(0,0,0,0.08)"/>
        <text x="212" y="88" font-size="14" font-weight="900" fill="rgba(0,0,0,0.75)">Heterodyna Core</text>

        <rect x="404" y="28" width="94" height="56" rx="12" fill="url(#mg)" opacity="0.16" stroke="rgba(0,0,0,0.08)"/>
        <text x="416" y="58" font-size="12" font-weight="900" fill="rgba(0,0,0,0.75)">Pluginy</text>

        <path d="M184 100 C 220 80, 250 80, 298 74" stroke="rgba(0,0,0,0.18)" stroke-width="2" fill="none"/>
        <path d="M388 70 C 410 56, 420 52, 404 50" stroke="rgba(0,0,0,0.18)" stroke-width="2" fill="none"/>
    </svg>';
}
// Read-only customer portal (shortcode). Access is checked per organization.
require_once HDS3_PATH . 'includes/class-hds3-customer-portal.php';
require_once HDS3_PATH . 'includes/class-hds3-pricing.php';
add_action('plugins_loaded', ['HDS3_Pricing', 'init'], 20);
add_shortcode('heterodyna_cloud_portal', ['HDS3_Customer_Portal', 'render']);

// Tenant membership actions: nonce + IAM checks are enforced in the handler.
add_action('admin_post_hds3_portal_member', ['HDS3_Customer_Portal', 'manage_member']);
add_action('admin_post_hds3_portal_profile', ['HDS3_Customer_Portal', 'update_profile']);

// Explicit, administrator-triggered legacy data cleanup. Installation never wipes data.
// Registered with the parent menu inside HDS3_Admin::menu().
add_action('admin_post_hds3_data_reset', ['HDS3_Data_Reset', 'execute']);

// Billing statements: explicitly created drafts, never payments or fiscal invoices.
add_action('admin_menu', ['HDS3_Billing_Periods', 'admin_menu']);
add_action('admin_post_hds3_billing_create_draft', ['HDS3_Billing_Periods', 'save']);

// Universal first-run setup. No sample tenants, products or plans are created.
require_once HDS3_PATH . 'includes/class-hds3-setup-wizard.php';
HDS3_Setup_Wizard::init();

// Universal SaaS: read-only onboarding readiness dashboard.
require_once HDS3_PATH . 'includes/class-hds3-universal-readiness.php';
