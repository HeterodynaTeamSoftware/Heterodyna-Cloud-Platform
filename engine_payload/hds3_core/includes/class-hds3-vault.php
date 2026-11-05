<?php

if (!defined('ABSPATH')) exit;



/**

 * Prosty "vault" plikowy dla SaaS v3.

 * Cel: trzymać historię konektorów (mapowanie domen ↔ subkey), zdarzenia techniczne,

 * oraz paczki do pobrania (ZIPy) poza katalogiem wtyczek.

 */

class HDS3_Vault {

    /**

     * Bazowy katalog vault.

     * Domyślnie: wp-content/heterodyna_vault

     */

    public static function base_dir(): string {

        $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (ABSPATH . 'wp-content');

        $dir = rtrim($base, '/\\') . '/heterodyna_vault';

        return $dir;

    }



    public static function ensure_base(): bool {

        $dir = self::base_dir();

        if (is_dir($dir)) return true;

        return wp_mkdir_p($dir);

    }



    /**

     * Bezpieczna nazwa folderu.

     */

    public static function safe_name(string $s): string {

        $s = trim($s);

        $s = preg_replace('/\s+/', '_', $s);

        $s = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $s);

        $s = trim($s, '._-');

        if ($s === '') $s = 'unknown';

        return $s;

    }



    /**

     * Ścieżka konektora (po stronie SaaS) dla danej licencji agencji i domeny.

     * /heterodyna_vault/agencje/<AGENCY_KEY>/konektory/<domain>/

     */

    public static function agency_connector_dir(string $agency_license_key, string $domain): string {

        $agency_license_key = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', $agency_license_key));

        $domain = strtolower(preg_replace('#^https?://#', '', trim($domain)));

        $domain = trim($domain, '/');

        $dir = self::base_dir()

            . '/agencje/' . self::safe_name($agency_license_key)

            . '/konektory/' . self::safe_name($domain);

        return $dir;

    }



    public static function ensure_agency_connector_dir(string $agency_license_key, string $domain): bool {

        if (!self::ensure_base()) return false;

        $dir = self::agency_connector_dir($agency_license_key, $domain);

        if (is_dir($dir)) return true;

        return wp_mkdir_p($dir);

    }



    public static function write_json(string $path, array $data): bool {

        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (!is_string($json)) $json = '{}';

        return (bool)@file_put_contents($path, $json);

    }



    public static function append_log(string $path, string $line): void {

        $ts = gmdate('Y-m-d H:i:s');

        @file_put_contents($path, '['.$ts.' UTC] '.$line."\n", FILE_APPEND);

    }

    /**

     * Ścieżka tenanta:

     * /heterodyna_vault/tenants/<TENANT_ID>/

     */

    public static function tenant_dir(string $tenant_id): string {

        $tenant_id = strtoupper(preg_replace('/[^A-Z0-9\-_]/', '', $tenant_id));

        return self::base_dir() . '/tenants/' . self::safe_name($tenant_id);

    }



    public static function ensure_tenant_dir(string $tenant_id): bool {

        if (!self::ensure_base()) return false;

        $dir = self::tenant_dir($tenant_id);

        if (is_dir($dir)) return true;

        return wp_mkdir_p($dir);

    }



    /**

     * Tworzy standardowe pliki: meta.json, events.log, errors.log (best-effort).

     * meta.json jest łączone z istniejącym (nie nadpisuje bez sensu).

     */

    public static function ensure_scaffold(string $dir, array $meta): void {

        if (!self::ensure_base()) return;

        if (!is_dir($dir)) {

            @wp_mkdir_p($dir);

        }

        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir)) return;



        // meta.json merge

        $meta_path = $dir . '/meta.json';

        $existing = [];

        if (is_file($meta_path)) {

            $raw = @file_get_contents($meta_path);

            $j = json_decode((string)$raw, true);

            if (is_array($j)) $existing = $j;

        }

        $merged = array_merge($existing, $meta);

        if (empty($merged['created_at'])) $merged['created_at'] = hds3_now_gmt();

        $merged['updated_at'] = hds3_now_gmt();

        self::write_json($meta_path, $merged);



        // log files

        $ev = $dir . '/events.log';

        $er = $dir . '/errors.log';

        if (!is_file($ev)) @file_put_contents($ev, '');

        if (!is_file($er)) @file_put_contents($er, '');

    }



    public static function ensure_tenant_scaffold(string $tenant_id): void {

        $dir = self::tenant_dir($tenant_id);

        if (!self::ensure_tenant_dir($tenant_id)) return;

        self::ensure_scaffold($dir, [

            'type' => 'tenant',

            'tenant_id' => strtoupper(preg_replace('/[^A-Z0-9\-_]/', '', $tenant_id)),

        ]);

    }



    public static function ensure_agency_connector_scaffold(string $agency_license_key, string $domain, array $extra_meta = []): void {

        $dir = self::agency_connector_dir($agency_license_key, $domain);

        if (!self::ensure_agency_connector_dir($agency_license_key, $domain)) return;

        $meta = array_merge([

            'type' => 'agency_connector',

            'parent_license_key' => strtoupper(preg_replace('/[^A-Z0-9\-_]/', '', $agency_license_key)),

            'domain' => strtolower(preg_replace('#^https?://#', '', trim($domain))),

        ], $extra_meta);

        self::ensure_scaffold($dir, $meta);

    }



    public static function append_event(string $dir, string $line): void {

        $p = rtrim($dir,'/\\') . '/events.log';

        self::append_log($p, $line);

    }



    public static function append_error(string $dir, string $line): void {

        $p = rtrim($dir,'/\\') . '/errors.log';

        self::append_log($p, $line);

    }



    /**

     * Usuwa katalog rekurencyjnie (best-effort).

     */

    public static function delete_dir(string $dir): bool {

        $dir = rtrim($dir, '/\\');

        if (!$dir || !is_dir($dir)) return false;

        $items = @scandir($dir);

        if (!$items) return false;

        foreach ($items as $it) {

            if ($it === '.' || $it === '..') continue;

            $p = $dir . '/' . $it;

            if (is_dir($p)) {

                self::delete_dir($p);

            } else {

                @unlink($p);

            }

        }

        return @rmdir($dir);

    }





}

