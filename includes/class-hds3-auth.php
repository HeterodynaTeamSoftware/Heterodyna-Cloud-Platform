<?php

if (!defined('ABSPATH')) exit;



class HDS3_Auth {

    public static function bearer_token() {

        $h = '';

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];

        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

        if (!$h) return '';

        if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));

        return '';

    }



    public static function client_token() {

        // Prefer wp-config constant

        if (defined('HDS3_CLIENT_TOKEN') && HDS3_CLIENT_TOKEN) return (string)HDS3_CLIENT_TOKEN;

        $t = (string)HDS3_Settings::get('client_token', '');

        return $t;

    }



    public static function token_source() {

        if (defined('HDS3_CLIENT_TOKEN') && HDS3_CLIENT_TOKEN) return 'wp-config';

        $t = (string)HDS3_Settings::get('client_token', '');

        if (!empty($t)) return 'baza';

        return 'brak';

    }



    /**

     * Autoryzacja:

     * - token systemowy (client_token) -> pełny dostęp (admin/system)

     * - token tenantowy (w tabeli hds3_tokens) -> dostęp wg scope

     *

     * Zwraca array('mode'=>'system'|'tenant', 'tenant_id'=>..., 'token_id'=>..., 'scopes'=>[])

     */

    /** Validate a bearer token and return its authenticated context or WP_Error.
     * Kept for compatibility with existing REST handlers.
     */
    public static function verify_bearer_or_die() {
        return self::require_scope_or_error('');
    }

    public static function require_scope_or_error($required_scope = '') {

        $got = self::bearer_token();

        if (!$got) return new WP_Error('hds3_forbidden', 'Brak tokenu Bearer.', ['status' => 403]);



        $need = self::client_token();

        if ($need && hash_equals($need, $got)) {

            return ['mode'=>'system', 'tenant_id'=>'', 'token_id'=>0, 'scopes'=>['*']];

        }



        // tenant token

        if (class_exists('HDS3_Tokens')) {

            $row = HDS3_Tokens::validate($got, (string)$required_scope);

            if (is_wp_error($row)) return $row;
            if (!class_exists('HDS3_Tenants')) {
                return new WP_Error('hds3_tenant_guard_unavailable', 'Weryfikacja organizacji jest niedostępna.', ['status'=>503]);
            }
            {
                $tenant = HDS3_Tenants::get((string)$row['tenant_id']);
                // Fail closed: orphan tokens must never authenticate.
                if (!is_array($tenant) || ($tenant['status'] ?? '') !== 'active') {
                    return new WP_Error('hds3_tenant_suspended', 'Organizacja nie jest aktywna.', ['status'=>403]);
                }
            }

            return [

                'mode' => 'tenant',

                'tenant_id' => (string)$row['tenant_id'],

                'token_id' => (int)$row['id'],

                'scopes' => isset($row['scopes_arr']) ? (array)$row['scopes_arr'] : [],

            ];

        }



        return new WP_Error('hds3_forbidden', 'Niepoprawny token.', ['status' => 403]);

    }

    /** For tenant credentials, deny access to records owned by other tenants. */
    public static function require_tenant_match($tenant_id, $scope = '') {
        $auth = self::require_scope_or_error($scope);
        if (is_wp_error($auth)) return $auth;
        if (($auth['mode'] ?? '') === 'tenant' && (!is_string($tenant_id) || $tenant_id === '' || !hash_equals((string)$auth['tenant_id'], $tenant_id))) {
            return new WP_Error('hds3_tenant_forbidden', 'Brak dostępu do zasobu organizacji.', ['status'=>403]);
        }
        return $auth;
    }

}

