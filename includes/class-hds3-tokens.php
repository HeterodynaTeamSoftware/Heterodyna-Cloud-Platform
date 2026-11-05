<?php

if (!defined('ABSPATH')) exit;



class HDS3_Tokens {



    public static function table() {

        return HDS3_DB::table('tokens');

    }



    public static function hash_token($token) {

        return hash('sha256', (string)$token);

    }



    public static function create($tenant_id, $scopes = [], $label = '') {

        global $wpdb;

        $tenant_id = sanitize_text_field((string)$tenant_id);

        if (!$tenant_id) return new WP_Error('hds3_bad_tenant', 'Brak tenant_id.');



        $plain = bin2hex(random_bytes(32)); // 256-bit HEX

        $hash = self::hash_token($plain);

        $scopes = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$scopes))));

        $scopes_json = wp_json_encode($scopes);



        $now = current_time('mysql');

        $ok = $wpdb->insert(self::table(), [

            'tenant_id' => $tenant_id,

            'token_hash' => $hash,

            'scopes' => $scopes_json,

            'status' => 'active',

            'label' => sanitize_text_field((string)$label),

            'created_at' => $now,

            'last_used_at' => null,

        ], ['%s','%s','%s','%s','%s','%s','%s']);



        if (!$ok) return new WP_Error('hds3_db', 'Nie udało się zapisać tokenu.');



        return [

            'token' => $plain,

            'token_hash' => $hash,

            'tenant_id' => $tenant_id,

            'scopes' => $scopes,

            'id' => (int)$wpdb->insert_id,

        ];

    }



    public static function revoke($id) {

        global $wpdb;

        $id = (int)$id;

        if ($id <= 0) return false;

        $now = current_time('mysql');

        $wpdb->update(self::table(), ['status'=>'revoked','revoked_at'=>$now], ['id'=>$id], ['%s','%s'], ['%d']);

        return true;

    }



    public static function find_by_plain($plain) {

        global $wpdb;

        $hash = self::hash_token($plain);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table()." WHERE token_hash=%s LIMIT 1", $hash), ARRAY_A);

        return $row ?: null;

    }



    public static function validate($plain, $required_scope = '') {

        global $wpdb;

        $row = self::find_by_plain($plain);

        if (!$row) return new WP_Error('hds3_forbidden', 'Niepoprawny token.', ['status'=>403]);

        if ($row['status'] !== 'active') return new WP_Error('hds3_forbidden', 'Token nieaktywny.', ['status'=>403]);



        $scopes = [];

        if (!empty($row['scopes'])) {

            $tmp = json_decode((string)$row['scopes'], true);

            if (is_array($tmp)) $scopes = $tmp;

        }



        if ($required_scope) {

            if (!in_array($required_scope, $scopes, true) && !in_array('*', $scopes, true)) {

                return new WP_Error('hds3_forbidden', 'Token nie ma wymaganego uprawnienia: '.$required_scope, ['status'=>403]);

            }

        }



        // update last_used_at best-effort

        $wpdb->update(self::table(), ['last_used_at'=>current_time('mysql')], ['id'=>(int)$row['id']], ['%s'], ['%d']);



        $row['scopes_arr'] = $scopes;

        return $row;

    }



    public static function list_tokens($tenant_id = '') {

        global $wpdb;

        if ($tenant_id) {

            $tenant_id = sanitize_text_field($tenant_id);

            return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." WHERE tenant_id=%s ORDER BY id DESC LIMIT 200", $tenant_id), ARRAY_A);

        }

        return $wpdb->get_results("SELECT * FROM ".self::table()." ORDER BY id DESC LIMIT 200", ARRAY_A);

    }



    /**

     * Hard delete token (destrukcyjne) - do sprzatania po testach.

     */

    public static function hard_delete(int $id): bool {

        global $wpdb;

        $id = (int)$id;

        if ($id <= 0) return false;

        $wpdb->delete(self::table(), ['id' => $id], ['%d']);

        return true;

    }



    /**

     * Hard delete wszystkich tokenow dla tenanta.

     */

    public static function hard_delete_by_tenant(string $tenant_id): int {

        global $wpdb;

        $tenant_id = sanitize_text_field($tenant_id);

        if (!$tenant_id) return 0;

        $t = self::table();

        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE tenant_id=%s", $tenant_id));

        return (int)$wpdb->rows_affected;

    }

}

