<?php

if (!defined('ABSPATH')) exit;



class HDS3_Plans {



    public static function maybe_seed() {

        // Open source: nie narzucaj planów demonstracyjnych nowym instalacjom.
        // Starsze instalacje zachowują już zapisane plany.
        if (!apply_filters('hds3_enable_legacy_plan_seed', false)) return;
        // Seed default plans if table exists and empty.

        global $wpdb;

        $pt = HDS3_DB::table('plans');

        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) === $pt);

        if (!$exists) return;



        $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt");

        if ($cnt > 0) return;



        $now = hds3_now_gmt();

        $defaults = [

            [

                'plan_key' => 'basic',

                'name' => 'Basic',

                'status' => 'active',

                'limits' => [

                    'max_instances' => 1,

                    'jobs_per_day' => 200,

                    'telemetry_per_day' => 5000,

                ],

            ],

            [

                'plan_key' => 'pro',

                'name' => 'Pro',

                'status' => 'active',

                'limits' => [

                    'max_instances' => 5,

                    'jobs_per_day' => 2000,

                    'telemetry_per_day' => 50000,

                ],

            ],

            [

                'plan_key' => 'agency',

                'name' => 'Agency',

                'status' => 'active',

                'limits' => [

                    'max_instances' => 50,

                    'jobs_per_day' => 20000,

                    'telemetry_per_day' => 500000,

                ],

            ],

        ];



        foreach ($defaults as $d) {

            $wpdb->query($wpdb->prepare(

                "INSERT INTO $pt (plan_key,name,status,limits,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s)",

                $d['plan_key'],

                $d['name'],

                $d['status'],

                hds3_json_encode($d['limits']),

                $now,

                $now

            ));

        }

    }



    public static function get_plan($plan_key) {

        global $wpdb;

        $pt = HDS3_DB::table('plans');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE plan_key=%s LIMIT 1", $plan_key), ARRAY_A);

        if (!$row) return null;

        $row['limits'] = hds3_json_decode($row['limits'] ?? '', []);

        if (!is_array($row['limits'])) $row['limits'] = [];

        return $row;

    }



    public static function list_plans($active_only=false) {

        global $wpdb;

        $pt = HDS3_DB::table('plans');

        $where = $active_only ? "WHERE status='active'" : "";

        $rows = $wpdb->get_results("SELECT * FROM $pt $where ORDER BY id ASC", ARRAY_A);

        foreach ($rows as &$r) {

            $r['limits'] = hds3_json_decode($r['limits'] ?? '', []);

            if (!is_array($r['limits'])) $r['limits'] = [];

        }

        return $rows;

    }



    public static function get_tenant_plan_key($tenant_id) {

        global $wpdb;

        $tp = HDS3_DB::table('tenant_plans');

        $pk = $wpdb->get_var($wpdb->prepare("SELECT plan_key FROM $tp WHERE tenant_id=%s LIMIT 1", $tenant_id));

        return $pk ? (string)$pk : ''; // brak przypisania nie oznacza automatycznie planu Basic

    }



    public static function set_tenant_plan_key($tenant_id, $plan_key) {

        global $wpdb;

        $tp = HDS3_DB::table('tenant_plans');

        $now = hds3_now_gmt();

        $wpdb->query($wpdb->prepare(

            "INSERT INTO $tp (tenant_id, plan_key, updated_at) VALUES (%s,%s,%s)

             ON DUPLICATE KEY UPDATE plan_key=VALUES(plan_key), updated_at=VALUES(updated_at)",

            $tenant_id, $plan_key, $now

        ));

    }



    public static function get_limits_for_tenant($tenant_id) {

        $plan_key = self::get_tenant_plan_key($tenant_id);

        $plan = self::get_plan($plan_key);

        if (!$plan) {

            $plan = self::get_plan('basic');

        }

        $limits = $plan ? ($plan['limits'] ?? []) : [];

        return is_array($limits) ? $limits : [];

    }



    private static function day_start_gmt() {

        return gmdate('Y-m-d 00:00:00');

    }



    public static function enforce_instance_limit($tenant_id) {

        $limits = self::get_limits_for_tenant($tenant_id);

        $max = (int)($limits['max_instances'] ?? 0);

        if ($max <= 0) return true; // 0 => no limit



        global $wpdb;

        $it = HDS3_DB::table('instances');

        $cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $it WHERE tenant_id=%s AND status='active'", $tenant_id));

        return ($cnt < $max);

    }



    public static function enforce_jobs_per_day($tenant_id) {

        $limits = self::get_limits_for_tenant($tenant_id);

        $max = (int)($limits['jobs_per_day'] ?? 0);

        if ($max <= 0) return true;



        global $wpdb;

        $jt = HDS3_DB::table('jobs');

        $day = self::day_start_gmt();

        // Jobs table doesn't include tenant_id yet. We use payload. Best-effort: if payload contains tenant_id.

        $like = '%' . $wpdb->esc_like('"tenant_id":"' . $tenant_id . '"') . '%';

        $cnt = (int)$wpdb->get_var($wpdb->prepare(

            "SELECT COUNT(*) FROM $jt WHERE created_at >= %s AND payload LIKE %s",

            $day, $like

        ));

        return ($cnt < $max);

    }



    public static function enforce_telemetry_per_day($tenant_id) {

        $limits = self::get_limits_for_tenant($tenant_id);

        $max = (int)($limits['telemetry_per_day'] ?? 0);

        if ($max <= 0) return true;



        global $wpdb;

        $et = HDS3_DB::table('events');

        $day = self::day_start_gmt();



        // Best-effort: count events by tenant via instances->sites mapping.

        $it = HDS3_DB::table('instances');

        $sites = $wpdb->get_col($wpdb->prepare("SELECT site FROM $it WHERE tenant_id=%s", $tenant_id));

        if (empty($sites)) return true;



        // Limit IN size for safety.

        $sites = array_slice(array_map('strval', $sites), 0, 50);

        $in = implode(',', array_fill(0, count($sites), '%s'));

        $sql = "SELECT COUNT(*) FROM $et WHERE created_at >= %s AND site IN ($in)";

        $params = array_merge([$day], $sites);

        $cnt = (int)$wpdb->get_var($wpdb->prepare($sql, $params));

        return ($cnt < $max);

    }

    public static function maybe_assign_defaults() {

        global $wpdb;

        $tp = HDS3_DB::table('tenant_plans');

        $tt = HDS3_DB::table('tenants');

        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tp)) === $tp);

        if (!$exists) return;



        $tenant_ids = $wpdb->get_col("SELECT tenant_id FROM $tt");

        if (empty($tenant_ids)) return;

        foreach ($tenant_ids as $tid) {

            $has = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tp WHERE tenant_id=%s LIMIT 1", $tid));

            if (!$has) {

                self::set_tenant_plan_key($tid, 'basic');

            }

        }

    }





    public static function tenant_id_for_site($site) {

        global $wpdb;

        $it = HDS3_DB::table('instances');

        $tenant = $wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM $it WHERE site=%s ORDER BY activated_at DESC LIMIT 1", strtolower($site)));

        return $tenant ? (string)$tenant : '';

    }

}

