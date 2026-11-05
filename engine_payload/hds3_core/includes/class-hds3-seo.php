<?php

if (!defined('ABSPATH')) exit;



/**

 * SEO Ops + Licencje vNext (prefix SEO-)

 * - aktywacja: license_key + domena => token

 * - dalsza komunikacja po Bearer token

 * - tabela "Aktywne klucze" w panelu

 * - heartbeat/error telemetrii dla stanu wtyczki

 * - probe domen (cron) po stronie serwera

 */

class HDS3_SEO {

    const TOKEN_HEADER = 'authorization';



    public static function init() {

        // menu

        add_action('admin_menu', [__CLASS__, 'menu'], 90);



        // cron: probe domen

        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);

        add_action('hds3_seo_domain_probe', [__CLASS__, 'cron_domain_probe']);

        if (!wp_next_scheduled('hds3_seo_domain_probe')) {

            wp_schedule_event(time() + 120, 'hds3_10min', 'hds3_seo_domain_probe');

        }

    }



    public static function cron_schedules($schedules) {

        if (!isset($schedules['hds3_10min'])) {

            $schedules['hds3_10min'] = ['interval' => 600, 'display' => 'Co 10 minut (HDS3)'];

        }

        return $schedules;

    }



    public static function menu() {

        if (!current_user_can('manage_options')) return;

        if (!HDS3_Settings::module_enabled('licenses')) return;

        add_submenu_page('hds3_center', 'Aktywne klucze (SEO)', 'Aktywne klucze (SEO)', 'manage_options', 'hds3_seo_active_keys', [__CLASS__, 'page_active_keys']);

    }



    // ----------------------------

    // REST — aktywacja i odczyt

    // ----------------------------



    private static function normalize_domain($domain) {

        $domain = strtolower(trim((string)$domain));

        $domain = preg_replace('#^https?://#', '', $domain);

        $domain = preg_replace('#/.*$#', '', $domain);

        $domain = preg_replace('/[^a-z0-9\-\.]/', '', $domain);

        return $domain;

    }



    private static function random_token() {

        $raw = random_bytes(32);

        $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64;

    }



    private static function plan_registry() {

        $raw = HDS3_Settings::get('hds3_seo_plan_registry', '');

        $reg = hds3_json_decode(is_string($raw) ? $raw : '', []);

        return is_array($reg) ? $reg : [];

    }



    private static function plan_from_code($plan_code) {

        $reg = self::plan_registry();

        $plan_code = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string)$plan_code));

        if (!$plan_code) $plan_code = 'SEO_BASIC_1M';

        if (!isset($reg[$plan_code]) || !is_array($reg[$plan_code])) {

            // fallback

            $plan_code = 'SEO_BASIC_1M';

        }

        return $reg[$plan_code] ?? [

            'plan_code' => $plan_code,

            'tier' => 'basic',

            'duration_months' => 1,

            'domain_limit' => 1,

            'activations_limit' => 1,

        ];

    }



    private static function parse_license_meta_plan($meta_raw) {

        $m = hds3_json_decode(is_string($meta_raw) ? $meta_raw : '', []);

        if (!is_array($m)) $m = [];

        $plan_code = $m['plan_code'] ?? ($m['plan'] ?? '');

        return is_string($plan_code) ? $plan_code : '';

    }



    private static function token_from_request(WP_REST_Request $req) {

        $h = $req->get_header('authorization');

        if (!$h) $h = $req->get_header('Authorization');

        $h = trim((string)$h);

        if (!$h) return '';

        if (stripos($h, 'bearer ') === 0) {

            return trim(substr($h, 7));

        }

        return '';

    }



    private static function require_activation_by_token(WP_REST_Request $req) {

        $token = self::token_from_request($req);

        if (!$token) return new WP_Error('hds3_seo_no_token', 'Brak tokenu Bearer.', ['status'=>401]);

        $hash = hash('sha256', $token);



        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token_hash=%s LIMIT 1", $hash), ARRAY_A);

        if (!$row) return new WP_Error('hds3_seo_bad_token', 'Nieprawidłowy token.', ['status'=>401]);

        if (($row['status'] ?? 'active') !== 'active') return new WP_Error('hds3_seo_inactive', 'Token nieaktywny.', ['status'=>403]);



        // expiry check

        $exp = (string)($row['expires_at'] ?? '');

        if ($exp && strtotime($exp.' UTC') < time()) {

            // best effort: oznacz jako expired

            $wpdb->update($t, ['status'=>'expired'], ['id'=>(int)$row['id']]);

            return new WP_Error('hds3_seo_expired', 'Licencja wygasła.', ['status'=>403]);

        }



        return $row;

    }



    public static function rest_activate(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('licenses')) {

            return new WP_Error('hds3_disabled', 'Moduł licencji jest wyłączony.', ['status'=>403]);

        }



        $b = $req->get_json_params();

        $license_key = sanitize_text_field($b['license_key'] ?? ($b['klucz'] ?? ''));

        $domain = sanitize_text_field($b['domain'] ?? ($b['domena'] ?? ($b['site'] ?? hds3_site_host())));

        $domain = self::normalize_domain($domain);

        $instance_id = sanitize_text_field($b['instance_id'] ?? ($b['instancja'] ?? ''));



        if (!$license_key || !$domain) {

            return new WP_Error('hds3_bad', 'Brak license_key lub domeny.', ['status'=>400]);

        }

        if (stripos($license_key, 'SEO-') !== 0) {

            return new WP_Error('hds3_bad_prefix', 'Klucz nie jest kluczem SEO (wymagany prefix SEO-).', ['status'=>400]);

        }



        global $wpdb;

        $lt = HDS3_DB::table('licenses');

        $lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE license_key=%s AND status='active' LIMIT 1", $license_key), ARRAY_A);

        if (!$lic) {

            return new WP_Error('hds3_no_license', 'Nie znaleziono aktywnej licencji.', ['status'=>404]);

        }



        // Plan: z meta licencji lub fallback

        $plan_code = self::parse_license_meta_plan($lic['meta'] ?? '');

        if (!$plan_code) $plan_code = 'SEO_BASIC_1M';

        $plan = self::plan_from_code($plan_code);



        // Agency limit domen: egzekwujemy limit aktywnych domen (distinct).

        $domain_limit = (int)($plan['domain_limit'] ?? 1);

        $tier = (string)($plan['tier'] ?? 'basic');

        $duration_months = (int)($plan['duration_months'] ?? 1);

        $activations_limit = (int)($plan['activations_limit'] ?? max(1, $duration_months));



        $at = HDS3_DB::table('seo_activations');

        $now = hds3_now_gmt();



        // ile domen aktywnych pod tym kluczem (agency)

        $active_domains = (int)$wpdb->get_var($wpdb->prepare(

            "SELECT COUNT(DISTINCT domain) FROM $at WHERE license_key=%s AND status='active' AND expires_at >= %s",

            $license_key,

            $now

        ));



        if ($tier === 'agency' && $domain_limit > 0) {

            // jeśli domena już istnieje, nie licz jej drugi raz

            $already = (int)$wpdb->get_var($wpdb->prepare(

                "SELECT COUNT(*) FROM $at WHERE license_key=%s AND domain=%s AND status='active' AND expires_at >= %s",

                $license_key,

                $domain,

                $now

            ));

            if (!$already && $active_domains >= $domain_limit) {

                return new WP_Error('hds3_seo_domain_limit', 'Przekroczono limit domen dla pakietu agency.', ['status'=>403]);

            }

        }



        // limit aktywacji: liczymy aktywacje w trakcie ważności pakietu (best-effort)

        // Tu trzymamy liczbę aktywacji na poziomie rekordu domeny (activations_used), ale do UI zwracamy globalnie.

        $activations_used = (int)$wpdb->get_var($wpdb->prepare(

            "SELECT COUNT(*) FROM $at WHERE license_key=%s AND activated_at >= DATE_SUB(%s, INTERVAL %d MONTH)",

            $license_key,

            $now,

            $duration_months

        ));

        $activations_used = max(0, $activations_used);

        if ($activations_limit > 0 && ($activations_used + 1) > $activations_limit) {

            return new WP_Error('hds3_seo_activations_limit', 'Przekroczono limit aktywacji dla tego pakietu.', ['status'=>403]);

        }



        $token_full = self::random_token();

        $token_hash = hash('sha256', $token_full);



        // expires

        $expires_ts = strtotime($now . ' UTC +' . $duration_months . ' months');

        $expires_at = gmdate('Y-m-d H:i:s', $expires_ts);



        $agency_enabled = ($tier === 'agency') ? 1 : 0;



        // Insert activation for this domain. Jeśli domena była już aktywna, nadpisz token i odśwież daty.

        $wpdb->query($wpdb->prepare(

            "INSERT INTO $at (license_key, token_hash, token_full, domain, owner, plan_code, tier, duration_months, domain_limit, activations_used, activations_limit, status, activated_at, expires_at, last_seen_at, agency_enabled)

             VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%d,'active',%s,%s,%s,%d)

             ON DUPLICATE KEY UPDATE

                token_hash=VALUES(token_hash),

                token_full=VALUES(token_full),

                plan_code=VALUES(plan_code),

                tier=VALUES(tier),

                duration_months=VALUES(duration_months),

                domain_limit=VALUES(domain_limit),

                activations_used=VALUES(activations_used),

                activations_limit=VALUES(activations_limit),

                status='active',

                activated_at=VALUES(activated_at),

                expires_at=VALUES(expires_at),

                last_seen_at=VALUES(last_seen_at),

                agency_enabled=VALUES(agency_enabled)

            ",

            $license_key,

            $token_hash,

            $token_full,

            $domain,

            (string)($lic['tenant_id'] ?? ''),

            (string)($plan['plan_code'] ?? $plan_code),

            $tier,

            $duration_months,

            $domain_limit,

            ($activations_used + 1),

            $activations_limit,

            $now,

            $expires_at,

            $now,

            $agency_enabled

        ));



        // ensure instance row (dla spójności)

        $it = HDS3_DB::table('instances');

        $wpdb->query($wpdb->prepare(

            "INSERT INTO $it (tenant_id, site, product, status, activated_at, last_seen_at, meta)

             VALUES (%s,%s,'SEO','active',%s,%s,NULL)

             ON DUPLICATE KEY UPDATE status='active', last_seen_at=%s",

            (string)($lic['tenant_id'] ?? ''),

            $domain,

            $now,

            $now,

            $now

        ));



        HDS3_Logger::event('seo','info','SEO aktywacja', [

            'license_key' => $license_key,

            'domain' => $domain,

            'plan_code' => (string)($plan['plan_code'] ?? $plan_code),

        ], $domain);



        // refresh counts

        $active_domains = (int)$wpdb->get_var($wpdb->prepare(

            "SELECT COUNT(DISTINCT domain) FROM $at WHERE license_key=%s AND status='active' AND expires_at >= %s",

            $license_key,

            $now

        ));



        return [

            'ok' => 1,

            'license_key' => $license_key,

            'domain' => $domain,

            'token' => $token_full,

            'plan_code' => (string)($plan['plan_code'] ?? $plan_code),

            'tier' => $tier,

            'activated_at' => $now,

            'duration_months' => $duration_months,

            'expires_at' => $expires_at,

            'activations_used' => ($activations_used + 1),

            'activations_limit' => $activations_limit,

            'domain_limit' => $domain_limit,

            'active_domains' => $active_domains,

            'instance_id' => $instance_id,

        ];

    }



    public static function rest_me(WP_REST_Request $req) {

        $row = self::require_activation_by_token($req);

        if (is_wp_error($row)) return $row;



        // przelicz aktywne domeny i zwróć spójny payload do wtyczki

        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $now = hds3_now_gmt();

        $license_key = (string)($row['license_key'] ?? '');

        $active_domains = (int)$wpdb->get_var($wpdb->prepare(

            "SELECT COUNT(DISTINCT domain) FROM $t WHERE license_key=%s AND status='active' AND expires_at >= %s",

            $license_key,

            $now

        ));



        return [

            'ok' => 1,

            'product' => 'SEO',

            'license_key' => $license_key,

            'domain' => (string)($row['domain'] ?? ''),

            'owner' => (string)($row['owner'] ?? ''),

            'plan_code' => (string)($row['plan_code'] ?? ''),

            'tier' => (string)($row['tier'] ?? 'basic'),

            'activated_at' => (string)($row['activated_at'] ?? ''),

            'duration_months' => (int)($row['duration_months'] ?? 1),

            'expires_at' => (string)($row['expires_at'] ?? ''),

            'activations_used' => (int)($row['activations_used'] ?? 1),

            'activations_limit' => (int)($row['activations_limit'] ?? 1),

            'domain_limit' => (int)($row['domain_limit'] ?? 1),

            'active_domains' => $active_domains,

            'plugin_version' => (string)($row['plugin_version'] ?? ''),

            'wp_version' => (string)($row['wp_version'] ?? ''),

            'php_version' => (string)($row['php_version'] ?? ''),

            'agency_enabled' => !empty($row['agency_enabled']) ? 1 : 0,

            'connectors_total' => (int)($row['connectors_total'] ?? 0),

            'connectors_ok' => (int)($row['connectors_ok'] ?? 0),

            'connectors_bad' => (int)($row['connectors_bad'] ?? 0),

            'last_error' => (string)($row['last_error'] ?? ''),

            'error_count' => (int)($row['error_count'] ?? 0),

            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),

        ];

    }



    public static function rest_heartbeat(WP_REST_Request $req) {

        $row = self::require_activation_by_token($req);

        if (is_wp_error($row)) return $row;



        $b = $req->get_json_params();

        $plugin_version = sanitize_text_field($b['plugin_version'] ?? ($b['wtyczka'] ?? ''));

        $wp_version = sanitize_text_field($b['wp_version'] ?? ($b['wordpress'] ?? ''));

        $php_version = sanitize_text_field($b['php_version'] ?? ($b['php'] ?? ''));

        $agency_enabled = !empty($b['agency_enabled']) ? 1 : 0;

        $connectors_total = (int)($b['connectors_total'] ?? 0);

        $connectors_ok = (int)($b['connectors_ok'] ?? 0);

        $connectors_bad = (int)($b['connectors_bad'] ?? 0);

        $last_error = sanitize_text_field($b['last_error'] ?? '');



        $now = hds3_now_gmt();

        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $wpdb->update($t, [

            'last_seen_at' => $now,

            'plugin_version' => $plugin_version ?: ($row['plugin_version'] ?? null),

            'wp_version' => $wp_version ?: ($row['wp_version'] ?? null),

            'php_version' => $php_version ?: ($row['php_version'] ?? null),

            'agency_enabled' => $agency_enabled,

            'connectors_total' => max(0, $connectors_total),

            'connectors_ok' => max(0, $connectors_ok),

            'connectors_bad' => max(0, $connectors_bad),

            'last_error' => $last_error ?: ($row['last_error'] ?? null),

        ], ['id' => (int)$row['id']]);



        return ['ok'=>1,'ts'=>time()];

    }



    public static function rest_error(WP_REST_Request $req) {

        $row = self::require_activation_by_token($req);

        if (is_wp_error($row)) return $row;



        $b = $req->get_json_params();

        $msg = sanitize_text_field($b['message'] ?? ($b['blad'] ?? ''));

        $code = sanitize_text_field($b['code'] ?? '');

        $payload = $b['payload'] ?? null;



        $now = hds3_now_gmt();

        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $err_count = (int)($row['error_count'] ?? 0) + 1;

        $last_error = trim($code ? ($code . ': ' . $msg) : $msg);

        $wpdb->update($t, [

            'last_seen_at' => $now,

            'last_error' => $last_error,

            'error_count' => $err_count,

        ], ['id' => (int)$row['id']]);



        HDS3_Logger::event('seo_error', 'error', $last_error ?: 'Błąd SEO', [

            'domain' => (string)($row['domain'] ?? ''),

            'license_key' => (string)($row['license_key'] ?? ''),

            'payload' => $payload,

        ], (string)($row['domain'] ?? ''));



        return ['ok'=>1,'error_count'=>$err_count];

    }



    // ----------------------------

    // CRON — probe domen

    // ----------------------------



    public static function cron_domain_probe() {

        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $now = hds3_now_gmt();



        // bierz do 30 rekordów, które nie były sprawdzane ostatnie 30 minut i nie są wygasłe

        $rows = $wpdb->get_results($wpdb->prepare(

            "SELECT id,domain,domain_checked_at FROM $t WHERE status='active' AND expires_at >= %s AND (domain_checked_at IS NULL OR domain_checked_at <= DATE_SUB(%s, INTERVAL 30 MINUTE)) ORDER BY domain_checked_at ASC, id ASC LIMIT 30",

            $now,

            $now

        ), ARRAY_A);



        foreach ((array)$rows as $r) {

            $domain = (string)($r['domain'] ?? '');

            if (!$domain) continue;



            $url = 'https://' . $domain . '/';

            $code = null;



            $resp = wp_remote_head($url, ['timeout'=>6,'redirection'=>3,'user-agent'=>'HeterodynaSEOProbe/'.HDS3_VERSION]);

            if (is_wp_error($resp)) {

                // fallback: spróbuj http

                $resp2 = wp_remote_head('http://' . $domain . '/', ['timeout'=>6,'redirection'=>3,'user-agent'=>'HeterodynaSEOProbe/'.HDS3_VERSION]);

                if (!is_wp_error($resp2)) {

                    $code = (int)wp_remote_retrieve_response_code($resp2);

                }

            } else {

                $code = (int)wp_remote_retrieve_response_code($resp);

            }



            $wpdb->update($t, [

                'domain_http_code' => $code,

                'domain_checked_at' => $now,

            ], ['id'=>(int)$r['id']]);

        }

    }



    // ----------------------------

    // Admin UI — Aktywne klucze

    // ----------------------------



    public static function page_active_keys() {

        if (!current_user_can('manage_options')) return;



        echo '<div class="wrap"><h1>Aktywne klucze — Heterodyna SEO</h1>';

        echo '<p class="description">Tabela operacyjna: klucz, token, domena, pakiet, daty, zdrowie domeny i wtyczki, wersje, connectory, błędy.</p>';



        global $wpdb;

        $t = HDS3_DB::table('seo_activations');

        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 500", ARRAY_A);



        $now_ts = time();



        echo '<style>

            .hds3-table{border-collapse:collapse;width:100%;background:#fff}

            .hds3-table th,.hds3-table td{border:1px solid rgba(0,0,0,.08);padding:8px;vertical-align:top;font-size:12px}

            .hds3-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(0,0,0,.06);font-weight:700}

            .hds3-ok{background:rgba(34,197,94,.15)}

            .hds3-bad{background:rgba(239,68,68,.12)}

            .hds3-warn{background:rgba(245,158,11,.14)}

            .hds3-mono{font-family:ui-monospace,Menlo,Consolas,monospace}

            details summary{cursor:pointer}

        </style>';



        echo '<table class="hds3-table">';

        echo '<thead><tr>';

        $cols = [

            'LP','Klucz','Token (pełny)','Domena','Właściciel','Aktywowano','Pakiet','Ważne do','Wtyczka działa','Domena działa','Problemy','Wersja wtyczki','PHP','WordPress','Agency: domeny','Agency działa','Connectory','Błędy','Ostatni kontakt'

        ];

        foreach ($cols as $c) echo '<th>'.hds3_safe_html($c).'</th>';

        echo '</tr></thead><tbody>';



        $lp = 0;

        foreach ((array)$rows as $r) {

            $lp++;

            $license_key = (string)($r['license_key'] ?? '');

            $token_full = (string)($r['token_full'] ?? '');

            $domain = (string)($r['domain'] ?? '');

            $owner = (string)($r['owner'] ?? '');

            $activated_at = (string)($r['activated_at'] ?? '');

            $expires_at = (string)($r['expires_at'] ?? '');

            $plan_code = (string)($r['plan_code'] ?? '');

            $tier = (string)($r['tier'] ?? '');

            $duration_months = (int)($r['duration_months'] ?? 0);

            $domain_limit = (int)($r['domain_limit'] ?? 0);



            $last_seen = (string)($r['last_seen_at'] ?? '');

            $last_seen_ts = $last_seen ? strtotime($last_seen.' UTC') : 0;

            $plugin_ok = ($last_seen_ts && ($now_ts - $last_seen_ts) <= 1800); // 30 min



            $http_code = isset($r['domain_http_code']) ? (int)$r['domain_http_code'] : 0;

            $domain_ok = ($http_code >= 200 && $http_code < 500); // 5xx/0 = problem



            $error_count = (int)($r['error_count'] ?? 0);

            $last_error = (string)($r['last_error'] ?? '');



            // active domains for this key

            $active_domains = 0;

            if ($tier === 'agency') {

                $active_domains = (int)$wpdb->get_var($wpdb->prepare(

                    "SELECT COUNT(DISTINCT domain) FROM $t WHERE license_key=%s AND status='active' AND expires_at >= %s",

                    $license_key,

                    hds3_now_gmt()

                ));

            }



            $connectors_total = (int)($r['connectors_total'] ?? 0);

            $connectors_bad = (int)($r['connectors_bad'] ?? 0);

            $connectors_ok = (int)($r['connectors_ok'] ?? 0);



            $problems = [];

            if (!$plugin_ok) $problems[] = 'Brak heartbeat';

            if (!$domain_ok) $problems[] = 'Domena down (HTTP '.$http_code.')';

            if ($error_count > 0) $problems[] = 'Błędy: '.$error_count;

            if ($connectors_bad > 0) $problems[] = 'Connectory: '.$connectors_bad.' bad';



            $pill = function($text, $state) {

                $cls = 'hds3-pill ' . ($state==='ok'?'hds3-ok':($state==='bad'?'hds3-bad':'hds3-warn'));

                return '<span class="'.hds3_safe_attr($cls).'">'.hds3_safe_html($text).'</span>';

            };



            echo '<tr>';

            echo '<td>'.(int)$lp.'</td>';

            echo '<td class="hds3-mono">'.hds3_safe_html($license_key).'</td>';



            // token full in details

            echo '<td class="hds3-mono">';

            if ($token_full) {

                $masked = substr($token_full, 0, 10).'…'.substr($token_full, -6);

                echo '<details><summary>'.hds3_safe_html($masked).'</summary><div style="margin-top:6px"><code>'.hds3_safe_html($token_full).'</code></div></details>';

            } else {

                echo '—';

            }

            echo '</td>';



            echo '<td><a href="'.hds3_safe_url('https://'.$domain.'/').'" target="_blank" rel="noopener">'.hds3_safe_html($domain).'</a><br><span class="hds3-mono">HTTP: '.hds3_safe_html((string)$http_code).'</span></td>';

            echo '<td>'.hds3_safe_html($owner ?: '—').'</td>';

            echo '<td>'.hds3_safe_html($activated_at).'</td>';

            echo '<td>'.hds3_safe_html(strtoupper($tier)).'<br><span class="hds3-mono">'.hds3_safe_html($plan_code).'</span><br>⏱️ '.hds3_safe_html((string)$duration_months).' mc</td>';

            echo '<td>'.hds3_safe_html($expires_at).'</td>';



            echo '<td>'.($plugin_ok ? $pill('OK','ok') : $pill('OFF','bad')).'</td>';

            echo '<td>'.($domain_ok ? $pill('OK','ok') : $pill('PROBLEM','bad')).'</td>';



            echo '<td>';

            if (!$problems) echo $pill('Brak','ok');

            else {

                echo '<ul style="margin:0;padding-left:16px">';

                foreach ($problems as $p) echo '<li>'.hds3_safe_html($p).'</li>';

                echo '</ul>';

                if ($last_error) echo '<div style="margin-top:6px"><span class="hds3-pill hds3-bad">'.hds3_safe_html($last_error).'</span></div>';

            }

            echo '</td>';



            echo '<td class="hds3-mono">'.hds3_safe_html((string)($r['plugin_version'] ?? '—')).'</td>';

            echo '<td class="hds3-mono">'.hds3_safe_html((string)($r['php_version'] ?? '—')).'</td>';

            echo '<td class="hds3-mono">'.hds3_safe_html((string)($r['wp_version'] ?? '—')).'</td>';



            if ($tier === 'agency') {

                echo '<td>'.hds3_safe_html((string)$active_domains).' / '.hds3_safe_html((string)$domain_limit).'</td>';

                echo '<td>'.(!empty($r['agency_enabled']) ? $pill('TAK','ok') : $pill('NIE','warn')).'</td>';

            } else {

                echo '<td>—</td><td>—</td>';

            }



            $conn_state = ($connectors_total <= 0) ? '—' : ($connectors_bad > 0 ? $pill($connectors_ok.'/'.$connectors_total.' OK','warn') : $pill($connectors_ok.'/'.$connectors_total.' OK','ok'));

            echo '<td>'.$conn_state.'</td>';

            echo '<td>'.($error_count > 0 ? $pill((string)$error_count,'bad') : $pill('0','ok')).'</td>';

            echo '<td>'.hds3_safe_html($last_seen ?: '—').'</td>';



            echo '</tr>';

        }



        if ($lp === 0) {

            echo '<tr><td colspan="19">Brak aktywacji SEO. Gdy wtyczka wykona <code>/seo/license/activate</code>, pojawią się rekordy.</td></tr>';

        }



        echo '</tbody></table>';

        echo '</div>';

    }

}

