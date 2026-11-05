<?php

if (!defined('ABSPATH')) exit;



class HDS3_Settings {



    // Lista modułów jest częścią rdzenia (nie zależy od UI).

    public static function known_modules() {

        return [

            'tenants' => 1,

            'plans' => 1,

            'tokens' => 1,

            'billing' => 1,

            'telemetry' => 1,

            'licenses' => 1,

            'verification' => 1,

            'queue' => 1,

            'cleanup' => 1,

            'consolidation' => 1,

            'agency' => 1,

        ];

    }



    public static function get($key, $default = null) {

        global $wpdb;

        $table = HDS3_DB::table('settings');

        $row = $wpdb->get_row($wpdb->prepare("SELECT svalue FROM $table WHERE skey=%s LIMIT 1", $key));

        if (!$row) return $default;

        return $row->svalue;

    }



    public static function set($key, $value) {

        global $wpdb;

        $table = HDS3_DB::table('settings');

        $wpdb->query(

            $wpdb->prepare(

                "INSERT INTO $table (skey, svalue, updated_at) VALUES (%s,%s,%s)

                 ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)",

                $key,

                is_string($value) ? $value : hds3_json_encode($value),

                hds3_now_gmt()

            )

        );

    }



    private static function module_skey($key) {

        return 'module_' . preg_replace('/[^a-z0-9_\-]/i', '', strtolower($key));

    }



    /**

     * Inicjalizuje domyślne ustawienia oraz wykonuje migrację modułów z legacy JSON.

     * Ważne: musi działać także po upgrade przez nadpisanie plików (bez de/aktywacji).

     */

    public static function init_defaults() {



        // 1) Podstawowe defaulty (bez modułów).

        $defaults = [

            'client_token' => '',

            'gatekeeper_enabled' => '0',

            'gatekeeper_scope' => 'admin', // admin|front|both

            'gatekeeper_redirect' => wp_login_url(),

            'verification_import_ppv' => '0',

            'verification_steps' => hds3_json_encode(['start','email','phone','docs','face','done']),

            'production_mode' => '0',

            'client_cache_headers' => '1',

            'client_cache_max_age' => '300',

        ];

        foreach ($defaults as $k => $v) {

            if (self::get($k, null) === null) self::set($k, $v);

        }



        // SEO: rejestr planów (parametry licencyjne) — jeden punkt prawdy.

        if (self::get('hds3_seo_plan_registry', null) === null) {

            self::set('hds3_seo_plan_registry', hds3_json_encode(hds3_default_seo_plan_registry()));

        }



        // 2) Migracja legacy: modules_enabled (JSON) -> module_* (osobne klucze).

        $legacy_raw = self::get('modules_enabled', null);

        $legacy = hds3_json_decode(is_string($legacy_raw) ? $legacy_raw : '', []);

        if (!is_array($legacy)) $legacy = [];



        // 3) Upewnij się, że każdy moduł ma osobny klucz (backfill).

        $known = self::known_modules();

        foreach ($known as $mod => $default_on) {

            $skey = self::module_skey($mod);

            $cur = self::get($skey, null);



            if ($cur === null) {

                // Preferuj legacy jeśli jest, inaczej default.

                $val = array_key_exists($mod, $legacy) ? ( (!empty($legacy[$mod])) ? '1' : '0' ) : ( $default_on ? '1' : '0' );

                self::set($skey, $val);

            }

        }



        // 4) Legacy rekord zostawiamy (do wglądu), ale wyrównujemy typy (1/0) żeby nie mieszać true/false.

        if ($legacy_raw !== null) {

            $normalized = [];

            foreach ($known as $mod => $default_on) {

                $normalized[$mod] = self::module_enabled($mod) ? 1 : 0;

            }

            self::set('modules_enabled', hds3_json_encode($normalized));

        } else {

            // jeśli nie było legacy, utwórz go tylko informacyjnie

            $info = [];

            foreach ($known as $mod => $default_on) $info[$mod] = $default_on ? 1 : 0;

            self::set('modules_enabled', hds3_json_encode($info));

        }



        // Ensure site verification row exists

        HDS3_Kernel::ensure_verification_subject('site', hds3_site_host());

    }



    /**

     * Zwraca mapę modułów na podstawie kluczy module_* (źródło prawdy).

     */

    public static function modules() {

        $known = self::known_modules();

        $out = [];

        $changed = false;



        foreach ($known as $mod => $default_on) {

            $skey = self::module_skey($mod);

            $cur = self::get($skey, null);

            if ($cur === null) {

                $cur = $default_on ? '1' : '0';

                self::set($skey, $cur);

                $changed = true;

            }

            $out[$mod] = (!empty($cur) && $cur !== '0') ? 1 : 0;

        }



        // Jeśli coś dopisaliśmy, również wyrównaj legacy JSON.

        if ($changed) {

            self::set('modules_enabled', hds3_json_encode($out));

        }



        return $out;

    }



    public static function module_enabled($key) {

        $skey = self::module_skey($key);

        $cur = self::get($skey, null);

        if ($cur === null) {

            // fallback (powinno być już zmigrowane, ale niech nie wybucha)

            $m = self::modules();

            return !empty($m[$key]);

        }

        return (!empty($cur) && $cur !== '0');

    }



    public static function set_module($key, $enabled) {

        $skey = self::module_skey($key);

        self::set($skey, $enabled ? '1' : '0');

        // aktualizuj legacy JSON informacyjnie

        $m = self::modules();

        $m[$key] = $enabled ? 1 : 0;

        self::set('modules_enabled', hds3_json_encode($m));

    }

}

