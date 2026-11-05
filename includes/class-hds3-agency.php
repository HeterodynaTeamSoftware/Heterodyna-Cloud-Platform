<?php

if (!defined('ABSPATH')) exit;



class HDS3_Agency {



    private static function norm_domain(string $domain): string {

        $domain = strtolower(preg_replace('#^https?://#','', trim($domain)));

        return trim($domain,'/');

    }



    /**

     * Upsert rejestru konektora (tabela agency_connectors) + vault meta.

     */

    private static function upsert_connector(string $parent_license_key, string $domain, array $fields = []): void {

        global $wpdb;

        $t = HDS3_DB::table('agency_connectors');



        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        $domain = self::norm_domain($domain);

        if (!$parent_license_key || !$domain) return;



        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE parent_license_key=%s AND domain=%s LIMIT 1", $parent_license_key, $domain), ARRAY_A);



        $vault_path = HDS3_Vault::agency_connector_dir($parent_license_key, $domain);

        // Vault scaffold (meta.json + events/errors logs) – best effort

        HDS3_Vault::ensure_agency_connector_scaffold($parent_license_key, $domain, [

            'subkey' => !empty($fields['subkey']) ? (string)$fields['subkey'] : null,

            'plan_code' => !empty($fields['plan_code']) ? (string)$fields['plan_code'] : null,

        ]);

$data = [

            'parent_license_key' => $parent_license_key,

            'domain' => $domain,

            'vault_path' => $vault_path,

        ];



        $allow = ['product','plan_code','subkey','subtoken_hash','status','connected_at','disconnected_at','last_seen_at','last_error','error_count','meta'];

        foreach ($allow as $k) {

            if (!array_key_exists($k, $fields)) continue;

            $data[$k] = $fields[$k];

        }



        if ($row) {

            $wpdb->update($t, $data, ['id' => (int)$row['id']]);

        } else {

            $data['created_at'] = hds3_now_gmt();

            $wpdb->insert($t, $data);

        }

    }



    /**

     * Normalizacja produktu dla warstwy agencyjnej.

     * Historycznie UI mogło wysyłać "SEO" – mapujemy na "SEO_CONNECTOR".

     */

    private static function normalize_product(string $product): string {

        $p = strtoupper(preg_replace('/[^A-Z0-9_\-]/','', (string)$product));

        if ($p === 'SEO') return 'SEO_CONNECTOR';

        if ($p === '') return 'SEO_CONNECTOR';

        return $p;

    }



    

    /**

     * Limity domen dla licencji agencji (AGENCY_SEO-*).

     * Źródło: meta licencji (max_domains) albo parsowanie plan_code.

     */

    public static function get_agency_domain_limits(string $parent_license_key): array {

        global $wpdb;

        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        if (!$parent_license_key) return ['max_domains'=>0,'used_domains'=>0,'remaining'=>0];



        // max_domains z meta licencji (hds3_licenses.meta) lub wyciągnięte z plan_code.

        $max = 0;

        try {

            $tLic = HDS3_DB::table('licenses');

            $row = $wpdb->get_row($wpdb->prepare("SELECT meta, product, status FROM $tLic WHERE license_key=%s LIMIT 1", $parent_license_key), ARRAY_A);

            if ($row) {

                $meta = hds3_json_decode((string)($row['meta'] ?? ''), []);

                if (is_array($meta)) {

                    if (isset($meta['max_domains'])) $max = (int)$meta['max_domains'];

                    if ($max <= 0) {

                        $pc = (string)($meta['plan_code'] ?? $meta['plan'] ?? '');

                        $max = self::parse_max_domains_from_plan_code($pc);

                    }

                }

            }

        } catch (Throwable $e) {}



        if ($max <= 0) {

            // Ostatnia deska ratunku: wyciągnij liczbę z samego klucza (czasem klienci tak opisują plan)

            $max = self::parse_max_domains_from_plan_code($parent_license_key);

        }

        if ($max < 0) $max = 0;



        // used = aktywne konektory (status=active) w rejestrze agency_connectors

        $used = 0;

        try {

            $tCon = HDS3_DB::table('agency_connectors');

            $used = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $tCon WHERE parent_license_key=%s AND status='active'", $parent_license_key));

        } catch (Throwable $e) { $used = 0; }



        $remaining = $max > 0 ? max(0, $max - $used) : 0;

        return ['max_domains'=>$max,'used_domains'=>$used,'remaining'=>$remaining];

    }



    private static function parse_max_domains_from_plan_code(string $plan_code): int {

        $pc = strtoupper((string)$plan_code);

        // Szukamy jednej z dozwolonych wartości: 10 / 50 / 100 / 1000

        if (preg_match('/(^|\D)(1000)(\D|$)/', $pc)) return 1000;

        if (preg_match('/(^|\D)(100)(\D|$)/', $pc)) return 100;

        if (preg_match('/(^|\D)(50)(\D|$)/', $pc)) return 50;

        if (preg_match('/(^|\D)(10)(\D|$)/', $pc)) return 10;

        return 0;

    }



    /**

     * Czy można dodać nową domenę (connector) do licencji agencji?

     * Jeśli rekord dla domeny już istnieje, pozwalamy (reactivation).

     */

    private static function can_add_domain(string $parent_license_key, string $domain): array {

        global $wpdb;

        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        $domain = self::norm_domain($domain);

        if (!$parent_license_key || !$domain) return ['ok'=>0,'error'=>'Brak parent_license_key lub domain'];



        $tCon = HDS3_DB::table('agency_connectors');

        try {

            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tCon WHERE parent_license_key=%s AND domain=%s LIMIT 1", $parent_license_key, $domain));

            if ($exists) return ['ok'=>1,'reason'=>'exists'];

        } catch (Throwable $e) {}



        $lim = self::get_agency_domain_limits($parent_license_key);

        $max = (int)($lim['max_domains'] ?? 0);

        $used = (int)($lim['used_domains'] ?? 0);

        if ($max > 0 && $used >= $max) {

            return ['ok'=>0,'error'=>'Limit domen dla planu agencji został wyczerpany','limits'=>$lim];

        }

        return ['ok'=>1,'limits'=>$lim];

    }

public static function ensure_tables(): void {

        // Tables are created in HDS3_DB::create_tables(); this is a helper placeholder.

    }



    public static function create_subkey(string $parent_license_key, string $domain, string $product='SEO', string $plan_code=''): array {

        global $wpdb;

        $t = HDS3_DB::table('agency_subkeys');



        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        $product = self::normalize_product($product);

        $domain = self::norm_domain($domain);



        if (!$parent_license_key || !$domain) return ['ok'=>0,'error'=>'Brak parent_license_key lub domain'];

        if (stripos($parent_license_key, 'AGENCY_SEO-') !== 0) return ['ok'=>0,'error'=>'Nieprawidłowy klucz agencji (wymagany prefix AGENCY_SEO-)'];



        $can = self::can_add_domain($parent_license_key, $domain);

        if (empty($can['ok'])) return $can;





        $can = self::can_add_domain($parent_license_key, $domain);

        if (empty($can['ok'])) return $can;





        // prefix per product

        $prefix = $product;

        if (strlen($prefix) > 12) $prefix = substr($prefix,0,12);



        $subkey = $prefix . '-' . strtoupper(hds3_rand_alnum(20));



        // ensure unique

        for ($i=0;$i<5;$i++){

            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE subkey=%s LIMIT 1", $subkey));

            if (!$exists) break;

            $subkey = $prefix . '-' . strtoupper(hds3_rand_alnum(20));

        }



        $wpdb->insert($t, [

            'parent_license_key'=>$parent_license_key,

            'subkey'=>$subkey,

            'product'=>$product,

            'domain'=>$domain,

            'status'=>'active',

            'created_at'=>hds3_now_gmt(),

            'expires_at'=>null,

            'meta'=> wp_json_encode(['plan_code'=>sanitize_text_field((string)$plan_code)]),

        ]);



        // Rejestr + vault

        self::upsert_connector($parent_license_key, $domain, [

            'product' => $product,

            'plan_code' => sanitize_text_field((string)$plan_code),

            'subkey' => $subkey,

            'status' => 'active',

        ]);



        return ['ok'=>1,'subkey'=>$subkey];

    }



    public static function list_subkeys(string $parent_license_key): array {

        global $wpdb;

        $t = HDS3_DB::table('agency_subkeys');

        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE parent_license_key=%s ORDER BY id DESC", $parent_license_key), ARRAY_A);

        return ['ok'=>1,'items'=>$rows?:[]];

    }



    public static function create_subtoken(string $parent_license_key, string $domain, string $product, array $scopes): array {

        global $wpdb;

        $t = HDS3_DB::table('agency_subtokens');

        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        $domain = self::norm_domain($domain);

        $product = self::normalize_product($product);



        if (stripos($parent_license_key, 'AGENCY_SEO-') !== 0) return ['ok'=>0,'error'=>'Nieprawidłowy klucz agencji (wymagany prefix AGENCY_SEO-)'];



        $token_plain = 'AGY-' . strtoupper(hds3_rand_alnum(42));

        $token_hash = hash('sha256', $token_plain);



        $wpdb->insert($t, [

            'parent_license_key'=>$parent_license_key,

            'token_hash'=>$token_hash,

            'product'=>$product,

            'domain'=>$domain,

            'scopes'=>hds3_json_encode($scopes),

            'status'=>'active',

            'created_at'=>hds3_now_gmt(),

            'last_used_at'=>null,

            'expires_at'=>null,

        ]);



        self::upsert_connector($parent_license_key, $domain, [

            'product' => $product,

            'subtoken_hash' => $token_hash,

            'status' => 'active',

            'connected_at' => hds3_now_gmt(),

        ]);



        return ['ok'=>1,'subtoken'=>$token_plain];

    }



    public static function verify_subtoken(string $subtoken, string $domain, string $product='SEO'): array {

        global $wpdb;

        $t = HDS3_DB::table('agency_subtokens');

        $hash = hash('sha256', $subtoken);

        $domain = self::norm_domain($domain);

        $product = self::normalize_product($product);



        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token_hash=%s AND status='active' LIMIT 1", $hash), ARRAY_A);

        if (!$row) return ['ok'=>0,'error'=>'Token nieaktywny'];



        // domain lock (exact match)

        if (!empty($row['domain']) && $row['domain'] !== $domain) {

            self::upsert_connector((string)($row['parent_license_key']??''), $row['domain'], [

                'last_error' => 'Token nie pasuje do domeny (verify)',

                'error_count' => (int)($row['error_count'] ?? 0) + 1,

            ]);

            return ['ok'=>0,'error'=>'Token nie pasuje do domeny'];

        }

        if (!empty($row['product']) && strtoupper($row['product']) !== $product) {

            self::upsert_connector((string)($row['parent_license_key']??''), $domain, [

                'last_error' => 'Token nie pasuje do produktu (verify)',

                'error_count' => (int)($row['error_count'] ?? 0) + 1,

            ]);

            return ['ok'=>0,'error'=>'Token nie pasuje do produktu'];

        }



        // update last_used

        $wpdb->query($wpdb->prepare("UPDATE $t SET last_used_at=%s WHERE id=%d", hds3_now_gmt(), (int)$row['id']));



        self::upsert_connector((string)($row['parent_license_key']??''), $domain, [

            'last_seen_at' => hds3_now_gmt(),

            'status' => 'active',

            'last_error' => null,

        ]);



        return ['ok'=>1,'scopes'=>hds3_json_decode($row['scopes']??'[]',[]),'parent_license_key'=>$row['parent_license_key']??''];

    }



    public static function list_connectors(string $parent_license_key): array {

        global $wpdb;

        $t = HDS3_DB::table('agency_connectors');

        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', $parent_license_key));

        if (!$parent_license_key) return ['ok'=>1,'items'=>[]];

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE parent_license_key=%s ORDER BY id DESC", $parent_license_key), ARRAY_A);

        return ['ok'=>1,'items'=>$rows?:[]];

    }



    /**

     * Soft revoke connector (status=revoked, disconnected_at).

     */

    public static function revoke_connector(int $id, string $reason = ''): array {

        global $wpdb;

        $id = (int)$id;

        if ($id <= 0) return ['ok'=>0,'error'=>'Brak id'];

        $tCon = HDS3_DB::table('agency_connectors');

        $now = hds3_now_gmt();

        $wpdb->update($tCon, [

            'status' => 'revoked',

            'disconnected_at' => $now,

            'last_error' => $reason ? sanitize_text_field($reason) : null,

        ], ['id'=>$id]);

        return ['ok'=>1,'id'=>$id];

    }



    /**

     * Hard delete connector + powiazane subklucze/subtokeny (destrukcyjne).

     */

    public static function hard_delete_connector(int $id): array {

        global $wpdb;

        $id = (int)$id;

        if ($id <= 0) return ['ok'=>0,'error'=>'Brak id'];

        $tCon = HDS3_DB::table('agency_connectors');

        $tSk = HDS3_DB::table('agency_subkeys');

        $tSt = HDS3_DB::table('agency_subtokens');



        $row = $wpdb->get_row($wpdb->prepare("SELECT parent_license_key, domain FROM $tCon WHERE id=%d", $id), ARRAY_A);

        $parent = $row ? (string)($row['parent_license_key'] ?? '') : '';

        $domain = $row ? (string)($row['domain'] ?? '') : '';



        $wpdb->delete($tCon, ['id'=>$id], ['%d']);

        $deleted_con = (int)$wpdb->rows_affected;



        $deleted_sk = 0;

        $deleted_st = 0;

        if ($parent && $domain) {

            $wpdb->delete($tSk, ['parent_license_key'=>strtoupper($parent), 'domain'=>strtolower($domain)]);

            $deleted_sk = (int)$wpdb->rows_affected;

            $wpdb->delete($tSt, ['parent_license_key'=>strtoupper($parent), 'domain'=>strtolower($domain)]);

            $deleted_st = (int)$wpdb->rows_affected;

        }



        return ['ok'=>1,'id'=>$id,'parent'=>$parent,'domain'=>$domain,'deleted'=>['connector'=>$deleted_con,'subkeys'=>$deleted_sk,'subtokens'=>$deleted_st]];

    }

}

