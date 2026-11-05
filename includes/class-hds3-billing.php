<?php

if (!defined('ABSPATH')) exit;



class HDS3_Billing {



    public static function table() {

        return HDS3_DB::table('billing');

    }



    public static function normalize_status($s) {

        $s = strtolower(trim((string)$s));

        $allowed = ['active','unpaid','suspended','trial'];

        if (!in_array($s, $allowed, true)) $s = 'active';

        return $s;

    }



    public static function ensure_row($tenant_id) {

        global $wpdb;

        $tenant_id = sanitize_text_field((string)$tenant_id);

        if (!$tenant_id) return false;



        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE tenant_id=%s LIMIT 1", $tenant_id), ARRAY_A);

        if ($row) return true;



        $now = current_time('mysql');

        $wpdb->insert($table, [

            'tenant_id' => $tenant_id,

            'status' => 'active',

            'balance_cents' => 0,

            'currency' => 'PLN',

            'next_due_at' => null,

            'meta' => null,

            'updated_at' => $now,

        ], ['%s','%s','%d','%s','%s','%s','%s']);

        return true;

    }



    public static function maybe_seed_defaults() {

        // Tworzymy wiersze billing dla wszystkich tenantów (best-effort).

        if (!class_exists('HDS3_Tenants')) return;

        $tenants = HDS3_Tenants::list_all();

        if (!is_array($tenants)) return;

        foreach ($tenants as $t) {

            if (!empty($t['tenant_id'])) self::ensure_row((string)$t['tenant_id']);

        }

    }



    public static function get($tenant_id) {

        global $wpdb;

        $tenant_id = sanitize_text_field((string)$tenant_id);

        if (!$tenant_id) return null;

        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE tenant_id=%s LIMIT 1", $tenant_id), ARRAY_A);

        return $row ?: null;

    }



    public static function status($tenant_id) {

        $row = self::get($tenant_id);

        if (!$row) return 'active';

        return self::normalize_status($row['status'] ?? 'active');

    }



    public static function is_active($tenant_id) {

        $s = self::status($tenant_id);

        return ($s === 'active' || $s === 'trial');

    }



    public static function set_status($tenant_id, $status, $meta = null) {

        global $wpdb;

        $tenant_id = sanitize_text_field((string)$tenant_id);

        if (!$tenant_id) return false;

        $status = self::normalize_status($status);

        self::ensure_row($tenant_id);



        $table = self::table();

        $now = current_time('mysql');

        $wpdb->update($table, [

            'status' => $status,

            'meta' => is_null($meta) ? null : (is_string($meta) ? $meta : wp_json_encode($meta)),

            'updated_at' => $now,

        ], ['tenant_id'=>$tenant_id], ['%s','%s','%s'], ['%s']);

        return true;

    }



    public static function list_all() {

        global $wpdb;

        return $wpdb->get_results("SELECT * FROM ".self::table()." ORDER BY updated_at DESC LIMIT 500", ARRAY_A);

    }

}

