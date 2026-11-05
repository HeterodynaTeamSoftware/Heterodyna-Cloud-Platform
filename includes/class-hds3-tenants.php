<?php

if (!defined('ABSPATH')) exit;



/**

 * TENANT CORE — fundament SaaS v3.

 * WordPress jest launcherem, a tenant/plan/limity żyją w tabelach HDS3.

 */

class HDS3_Tenants {



    private static $cols_cache = [];



    private static function cols(string $table): array {

        if (isset(self::$cols_cache[$table])) return self::$cols_cache[$table];

        global $wpdb;

        $cols = [];

        try {

            $rows = $wpdb->get_results("SHOW COLUMNS FROM `$table`");

            if ($rows) {

                foreach ($rows as $r) {

                    if (!empty($r->Field)) $cols[] = (string)$r->Field;

                }

            }

        } catch (Throwable $e) {

            $cols = [];

        }

        self::$cols_cache[$table] = $cols;

        return $cols;

    }



    public static function maybe_migrate(): void {

        // Nie rób nic, jeśli tabela nie istnieje (np. przy problemie z DB).

        global $wpdb;

        $table = HDS3_DB::table('tenants');

        // cheap check

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if ($exists !== $table) return;



        // Migracja tylko raz na wersję.

        $flag = HDS3_Settings::get('tenants_migrated_version', '');

        if ($flag === HDS3_VERSION) return;



        self::migrate_from_licenses();

        self::migrate_from_instances();



        HDS3_Settings::set('tenants_migrated_version', HDS3_VERSION);

    }



    private static function migrate_from_licenses(): void {

        global $wpdb;

        $ltable = HDS3_DB::table('licenses');

        $rows = $wpdb->get_results("SELECT DISTINCT tenant_id FROM $ltable WHERE tenant_id<>''", ARRAY_A);

        if (!$rows) return;

        foreach ($rows as $r) {

            $tid = (string)($r['tenant_id'] ?? '');

            if (!$tid) continue;

            self::ensure($tid, 'Tenant ' . $tid);

        }

    }



    private static function migrate_from_instances(): void {

        global $wpdb;

        $itable = HDS3_DB::table('instances');

        $rows = $wpdb->get_results("SELECT DISTINCT tenant_id FROM $itable WHERE tenant_id<>''", ARRAY_A);

        if (!$rows) return;

        foreach ($rows as $r) {

            $tid = (string)($r['tenant_id'] ?? '');

            if (!$tid) continue;

            self::ensure($tid, 'Tenant ' . $tid);

        }

    }



    public static function ensure(string $tenant_id, string $name = ''): void {

        global $wpdb;

        $table = HDS3_DB::table('tenants');

        $tenant_id = self::sanitize_tenant_id($tenant_id);

        if (!$tenant_id) return;

        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE tenant_id=%s LIMIT 1", $tenant_id));

        if ($row) return;



        $now = hds3_now_gmt();

        $cols = self::cols($table);

        $data = [

            'tenant_id' => $tenant_id,

            'name' => $name ? sanitize_text_field($name) : ('Tenant ' . $tenant_id),

            'status' => 'active',

            'meta' => null,

            'created_at' => $now,

            'updated_at' => $now,

        ];



        // Legacy kompatybilność.

        if (in_array('tenant_key', $cols, true)) $data['tenant_key'] = $tenant_id;

        if (in_array('nazwa', $cols, true)) $data['nazwa'] = $data['name'];

        if (in_array('created', $cols, true)) $data['created'] = $now;



        // Zostaw tylko pola istniejące w tabeli.

        foreach (array_keys($data) as $k) {

            if (!in_array($k, $cols, true)) unset($data[$k]);

        }

        $wpdb->insert($table, $data);

    }



    public static function create(string $name, string $status = 'active'): string {

        global $wpdb;

        $table = HDS3_DB::table('tenants');

        $status = in_array($status, ['active','suspended'], true) ? $status : 'active';



        $now = hds3_now_gmt();

        $cols = self::cols($table);



        // Retry: legacy schematy potrafią mieć UNIQUE na tenant_key z default '', co zabija drugi insert.

        for ($i = 0; $i < 5; $i++) {

            $tenant_id = self::generate_tenant_id();



            $data = [

                'tenant_id' => $tenant_id,

                'name' => sanitize_text_field($name),

                'status' => $status,

                'meta' => null,

                'created_at' => $now,

                'updated_at' => $now,

            ];

            if (in_array('tenant_key', $cols, true)) $data['tenant_key'] = $tenant_id;

            if (in_array('nazwa', $cols, true)) $data['nazwa'] = $data['name'];

            if (in_array('created', $cols, true)) $data['created'] = $now;



            // jeśli nie udało się pobrać listy kolumn, nie filtruj (best effort)

            if (!empty($cols)) {

                foreach (array_keys($data) as $k) {

                    if (!in_array($k, $cols, true)) unset($data[$k]);

                }

            }



            $ok = $wpdb->insert($table, $data);

            if ($ok) {

                // Vault scaffold (meta.json + logs) – best effort

                if (class_exists('HDS3_Vault')) {

                    HDS3_Vault::ensure_tenant_scaffold($tenant_id);

                    $vp = HDS3_Vault::tenant_dir($tenant_id);

                    // dopisz vault_path do meta (bez utraty istniejących danych)

                    $cur = self::get($tenant_id);

                    $meta = [];

                    if ($cur && !empty($cur['meta'])) {

                        $j = json_decode((string)$cur['meta'], true);

                        if (is_array($j)) $meta = $j;

                    }

                    $meta['vault_path'] = $vp;

                    self::update($tenant_id, ['meta' => $meta]);

                }

                return $tenant_id;

            }



            $err = (string)$wpdb->last_error;

            if ($err && (stripos($err, 'duplicate') !== false)) {

                continue;

            }

            // inne błędy: nie zapętlamy.

            return $tenant_id;

        }



        return self::generate_tenant_id();

    }



    public static function update(string $tenant_id, array $fields): void {

        global $wpdb;

        $table = HDS3_DB::table('tenants');

        $tenant_id = self::sanitize_tenant_id($tenant_id);

        if (!$tenant_id) return;



        $data = [];

        if (isset($fields['name'])) $data['name'] = sanitize_text_field((string)$fields['name']);

        if (isset($fields['status'])) {

            $st = (string)$fields['status'];

            $data['status'] = in_array($st, ['active','suspended'], true) ? $st : 'active';

        }

        if (isset($fields['meta'])) $data['meta'] = is_string($fields['meta']) ? $fields['meta'] : hds3_json_encode($fields['meta']);

        if (!$data) return;

        $data['updated_at'] = hds3_now_gmt();



        $wpdb->update($table, $data, ['tenant_id' => $tenant_id]);

    }



    public static function get(string $tenant_id): ?array {

        global $wpdb;

        $table = HDS3_DB::table('tenants');

        $tenant_id = self::sanitize_tenant_id($tenant_id);

        if (!$tenant_id) return null;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE tenant_id=%s LIMIT 1", $tenant_id), ARRAY_A);

        return $row ?: null;

    }



    public static function list_all(int $limit = 1000): array {

        global $wpdb;

        $t = HDS3_DB::table('tenants');

        $limit = max(1, min(5000, (int)$limit));

        $rows = $wpdb->get_results("SELECT tenant_id,name,status,created_at,updated_at FROM $t ORDER BY updated_at DESC LIMIT $limit", ARRAY_A);

        return $rows ?: [];

    }





    public static function list_with_counts(int $limit = 200): array {

        global $wpdb;

        $t = HDS3_DB::table('tenants');

        $l = HDS3_DB::table('licenses');

        $i = HDS3_DB::table('instances');

        $limit = max(1, min(1000, (int)$limit));



        $sql = "SELECT

            tt.tenant_id, tt.name, tt.status, tt.created_at, tt.updated_at,

            (SELECT COUNT(*) FROM $l ll WHERE ll.tenant_id=tt.tenant_id) AS licenses,

            (SELECT COUNT(*) FROM $i ii WHERE ii.tenant_id=tt.tenant_id) AS instances

            FROM $t tt

            ORDER BY tt.updated_at DESC

            LIMIT $limit";



        $rows = $wpdb->get_results($sql, ARRAY_A);

        return $rows ?: [];

    }



    public static function counts(): array {

        global $wpdb;

        $t = HDS3_DB::table('tenants');

        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");

        $active = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status=%s", 'active'));

        $susp = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status=%s", 'suspended'));

        return ['total'=>$total,'active'=>$active,'suspended'=>$susp];

    }



    public static function generate_tenant_id(): string {

        // krótki, czytelny identyfikator (nie UUID) — wystarczy jako tenant key.

        $raw = bin2hex(random_bytes(10));

        return 't_' . substr($raw, 0, 16);

    }



    public static function sanitize_tenant_id(string $tenant_id): string {

        $tenant_id = strtolower(trim($tenant_id));

        $tenant_id = preg_replace('/[^a-z0-9_\-]/', '', $tenant_id);

        return substr($tenant_id, 0, 64);

    }



    /**

     * Soft revoke tenant - zawiesza (nie kasuje danych).

     */

    public static function soft_revoke(string $tenant_id, string $reason = ''): bool {

        $tenant_id = self::sanitize_tenant_id($tenant_id);

        if (!$tenant_id) return false;

        $meta = self::get($tenant_id);

        $m = [];

        if (is_array($meta) && !empty($meta['meta'])) {

            $m = hds3_json_decode((string)$meta['meta'], []);

            if (!is_array($m)) $m = [];

        }

        $m['revoked_at'] = hds3_now_gmt();

        if ($reason) $m['revoked_reason'] = sanitize_text_field($reason);

        self::update($tenant_id, ['status' => 'suspended', 'meta' => $m]);

        return true;

    }



    /**

     * Hard delete tenant + logiczne cascade (destrukcyjne).

     * Usuwa: billing, tokens, licenses, instances, events (best-effort), agency sub* dla licencji agencji.

     */

    public static function hard_delete(string $tenant_id): array {

        global $wpdb;

        $tenant_id = self::sanitize_tenant_id($tenant_id);

        if (!$tenant_id) return ['ok'=>0,'error'=>'Brak tenant_id'];



        $tTen = HDS3_DB::table('tenants');

        $tTok = HDS3_DB::table('tokens');

        $tLic = HDS3_DB::table('licenses');

        $tIns = HDS3_DB::table('instances');

        $tBil = HDS3_DB::table('billing');

        $tEvt = HDS3_DB::table('events');



        $count = [

            'billing'=>0,

            'tokens'=>0,

            'licenses'=>0,

            'instances'=>0,

            'events'=>0,

            'agency_connectors'=>0,

            'agency_subkeys'=>0,

            'agency_subtokens'=>0,

            'tenants'=>0,

        ];



        // agency keys for this tenant (best effort)

        $agency_keys = [];

        try {

            $rows = $wpdb->get_results($wpdb->prepare("SELECT license_key FROM $tLic WHERE tenant_id=%s AND product=%s", $tenant_id, 'AGENCY_SEO'), ARRAY_A);

            foreach ((array)$rows as $r) {

                if (!empty($r['license_key'])) $agency_keys[] = (string)$r['license_key'];

            }

        } catch (Throwable $e) {}



        // Billing

        $wpdb->delete($tBil, ['tenant_id'=>$tenant_id], ['%s']);

        $count['billing'] = (int)$wpdb->rows_affected;



        // Tokens

        $wpdb->query($wpdb->prepare("DELETE FROM $tTok WHERE tenant_id=%s", $tenant_id));

        $count['tokens'] = (int)$wpdb->rows_affected;



        // Licenses

        $wpdb->query($wpdb->prepare("DELETE FROM $tLic WHERE tenant_id=%s", $tenant_id));

        $count['licenses'] = (int)$wpdb->rows_affected;



        // Instances

        $wpdb->query($wpdb->prepare("DELETE FROM $tIns WHERE tenant_id=%s", $tenant_id));

        $count['instances'] = (int)$wpdb->rows_affected;



        // Events (best-effort)

        try {

            $wpdb->query($wpdb->prepare("DELETE FROM $tEvt WHERE tenant_id=%s", $tenant_id));

            $count['events'] = (int)$wpdb->rows_affected;

        } catch (Throwable $e) {

            $count['events'] = 0;

        }



        // Agency cascade (by parent_license_key)

        if (!empty($agency_keys)) {

            $tCon = HDS3_DB::table('agency_connectors');

            $tSk = HDS3_DB::table('agency_subkeys');

            $tSt = HDS3_DB::table('agency_subtokens');

            foreach ($agency_keys as $k) {

                $wpdb->query($wpdb->prepare("DELETE FROM $tCon WHERE parent_license_key=%s", $k));

                $count['agency_connectors'] += (int)$wpdb->rows_affected;

                $wpdb->query($wpdb->prepare("DELETE FROM $tSk WHERE parent_license_key=%s", $k));

                $count['agency_subkeys'] += (int)$wpdb->rows_affected;

                $wpdb->query($wpdb->prepare("DELETE FROM $tSt WHERE parent_license_key=%s", $k));

                $count['agency_subtokens'] += (int)$wpdb->rows_affected;

            }

        }



        // Tenant row

        $wpdb->delete($tTen, ['tenant_id'=>$tenant_id], ['%s']);

        $count['tenants'] = (int)$wpdb->rows_affected;



        return ['ok'=>1,'deleted'=>$count,'tenant_id'=>$tenant_id,'agency_keys'=>$agency_keys];

    }

}

