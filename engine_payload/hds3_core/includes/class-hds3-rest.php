<?php

if (!defined('ABSPATH')) exit;



class HDS3_REST {

    public static function init() {

        add_action('rest_api_init', [__CLASS__, 'routes']);

    }



    public static function routes() {

        $ns = 'heterodyna/v3';
        HDS3_IAM::routes(); HDS3_Audit::routes();



        // Health (podstawowy publicznie, pełny po Bearer)

        register_rest_route($ns, '/health', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'health'],

            'permission_callback' => '__return_true',

        ]);



        // Telemetry

        register_rest_route($ns, '/telemetry/event', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'telemetry_event'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('telemetry')) return false; $r = HDS3_Auth::require_scope_or_error('telemetry:write'); return is_wp_error($r) ? $r : true; },

        ]);



        // Licenses

        register_rest_route($ns, '/license/activate', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'license_activate'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('licenses')) return false; $r = HDS3_Auth::require_scope_or_error('license:activate'); return is_wp_error($r) ? $r : true; },

        ]);

        register_rest_route($ns, '/license/validate', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'license_validate'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('licenses')) return false; $r = HDS3_Auth::require_scope_or_error('license:validate'); return is_wp_error($r) ? $r : true; },

        ]);



        // Optional compatibility routes, isolated from generic SaaS API.
        if (HDS3_Legacy_Compat::enabled()) {
        // SEO vNext (public activation by key+domain, then Bearer token for everything else)

        register_rest_route($ns, '/seo/license/activate', [

            'methods' => 'POST',

            'callback' => ['HDS3_SEO', 'rest_activate'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/seo/license/me', [

            'methods' => 'GET',

            'callback' => ['HDS3_SEO', 'rest_me'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/seo/telemetry/heartbeat', [

            'methods' => 'POST',

            'callback' => ['HDS3_SEO', 'rest_heartbeat'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/seo/telemetry/error', [

            'methods' => 'POST',

            'callback' => ['HDS3_SEO', 'rest_error'],

            'permission_callback' => '__return_true',

        ]);



        } // End SEO legacy API

        // Verification

        register_rest_route($ns, '/verification/status', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'verification_status'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/verification/steps', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'verification_steps'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/verification/complete-step', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'verification_complete_step'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('verification')) return false; $auth = HDS3_Auth::require_scope_or_error('verification:write'); return is_wp_error($auth) ? $auth : true; },

        ]);



        // PPV compat aliases

        register_rest_route($ns, '/ppv/status', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'verification_status'],

            'permission_callback' => '__return_true',

        ]);

        register_rest_route($ns, '/ppv/complete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'ppv_complete'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('verification')) return false; $auth = HDS3_Auth::require_scope_or_error('verification:write'); return is_wp_error($auth) ? $auth : true; },

        ]);



        // Admin: events listing

        register_rest_route($ns, '/admin/telemetry/events', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'admin_events'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);



        // Admin jobs run

        register_rest_route($ns, '/admin/jobs/run', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_jobs_run'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);



        // Admin modules save (stabilne: bez admin-post redirectów)

        register_rest_route($ns, '/admin/modules/save', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_modules_save'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);



        // Admin OPS: revoke/delete (UI + API)

        register_rest_route($ns, '/admin/ops/tenant/revoke', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_tenant_revoke'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/tenant/delete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_tenant_delete'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/token/delete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_token_delete'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/license/revoke', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_license_revoke'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/license/delete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_license_delete'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/connector/revoke', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_connector_revoke'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);

        register_rest_route($ns, '/admin/ops/connector/delete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'admin_ops_connector_delete'],

            'permission_callback' => function() { return current_user_can('manage_options'); },

        ]);





                if (HDS3_Legacy_Compat::enabled()) {
        // AGENCY: bezobsługowa aktywacja (bootstrap -> token instancji)

        // 1) /agency/bootstrap-token: publiczny endpoint, zwraca krótkotrwały token provisioning (HDS3P.*)

        register_rest_route($ns, '/agency/bootstrap-token', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_bootstrap_token'],

            'permission_callback' => '__return_true',

        ]);

        // 2) /agency/provision: wymiana tokenu provisioning na token instancji (w bazie tokens + wpis do instances)

        register_rest_route($ns, '/agency/provision', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_provision'],

            'permission_callback' => '__return_true',

        ]);



// AGENCY: subklucze/subtokenu

        register_rest_route($ns, '/agency/subkey/create', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_subkey_create'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);

        register_rest_route($ns, '/agency/subtoken/create', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_subtoken_create'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);



        // Bundle: subkey + subtoken w jednej operacji (bezobsługowo)

        register_rest_route($ns, '/agency/connector/create', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_connector_create'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);

        register_rest_route($ns, '/agency/subkeys', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'agency_subkeys_list'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);



        register_rest_route($ns, '/agency/connectors', [

            'methods' => 'GET',

            'callback' => [__CLASS__, 'agency_connectors_list'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);



        // AGENCY: operacje na konektorach (revoke/delete)

        register_rest_route($ns, '/agency/connector/revoke', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_connector_revoke'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);

        register_rest_route($ns, '/agency/connector/delete', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_connector_delete'],

            'permission_callback' => function() { if (!HDS3_Settings::module_enabled('agency')) return false; $r = HDS3_Auth::require_scope_or_error('agency:manage'); return is_wp_error($r) ? $r : true; },

        ]);



        // Verify subtoken (client connector). Bearer = subtoken.

        register_rest_route($ns, '/agency/verify', [

            'methods' => 'POST',

            'callback' => [__CLASS__, 'agency_verify'],

            'permission_callback' => '__return_true',

        ]);

        } // End Agency compatibility API

    }



    public static function agency_subkey_create(WP_REST_Request $req) {

        $body = $req->get_json_params();

        $parent = sanitize_text_field($body['parent_license_key'] ?? '');

        $domain = sanitize_text_field($body['domain'] ?? '');

        $product = sanitize_text_field($body['product'] ?? 'SEO');

        $plan_code = sanitize_text_field($body['plan_code'] ?? '');

        $out = HDS3_Agency::create_subkey($parent, $domain, $product, $plan_code);

        if (empty($out['ok'])) return new WP_REST_Response($out, 400);

        return $out;

    }



    public static function agency_subtoken_create(WP_REST_Request $req) {

        $body = $req->get_json_params();

        $parent = sanitize_text_field($body['parent_license_key'] ?? '');

        $domain = sanitize_text_field($body['domain'] ?? '');

        $product = sanitize_text_field($body['product'] ?? 'SEO');

        $scopes = isset($body['scopes']) && is_array($body['scopes']) ? array_values($body['scopes']) : [];

        $out = HDS3_Agency::create_subtoken($parent, $domain, $product, $scopes);

        if (empty($out['ok'])) return new WP_REST_Response($out, 400);

        return $out;

    }



    public static function agency_connector_create(WP_REST_Request $req) {

        $body = $req->get_json_params();

        $parent = sanitize_text_field($body['parent_license_key'] ?? '');

        $domain = sanitize_text_field($body['domain'] ?? '');

        $product = sanitize_text_field($body['product'] ?? 'SEO_CONNECTOR');

        $plan_code = sanitize_text_field($body['plan_code'] ?? '');

        $scopes = isset($body['scopes']) && is_array($body['scopes']) ? array_values($body['scopes']) : ['agency:seo:read','agency:seo:write'];



        $out1 = HDS3_Agency::create_subkey($parent, $domain, $product, $plan_code);

        if (empty($out1['ok'])) return new WP_REST_Response($out1, 400);

        $out2 = HDS3_Agency::create_subtoken($parent, $domain, $product, $scopes);

        if (empty($out2['ok'])) return new WP_REST_Response($out2, 400);



        return [

            'ok' => 1,

            'subkey' => (string)($out1['subkey'] ?? ''),

            'subtoken' => (string)($out2['subtoken'] ?? ''),

            'limits' => HDS3_Agency::get_agency_domain_limits($parent),

        ];

    }



    public static function agency_subkeys_list(WP_REST_Request $req) {

        $parent = sanitize_text_field($req->get_param('parent_license_key') ?? '');

        return HDS3_Agency::list_subkeys($parent);

    }



    public static function agency_connectors_list(WP_REST_Request $req) {

        $parent = sanitize_text_field($req->get_param('parent_license_key') ?? '');

        $out = HDS3_Agency::list_connectors($parent);

        // Dołącz limity domen (max/used) dla UI/klienta.

        $out['limits'] = HDS3_Agency::get_agency_domain_limits($parent);

        return $out;

    }



    



    public static function agency_connector_revoke(WP_REST_Request $req) {

        $body = $req->get_json_params();

        $id = (int)($body['id'] ?? 0);

        $reason = sanitize_text_field($body['reason'] ?? '');

        $out = HDS3_Agency::revoke_connector($id, $reason);

        if (empty($out['ok'])) return new WP_REST_Response($out, 400);

        return $out;

    }



    public static function agency_connector_delete(WP_REST_Request $req) {

        $body = $req->get_json_params();

        $id = (int)($body['id'] ?? 0);

        $out = HDS3_Agency::hard_delete_connector($id);

        if (empty($out['ok'])) return new WP_REST_Response($out, 400);

        return $out;

    }



public static function agency_verify(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('agency')) return new WP_REST_Response(['ok'=>0,'error'=>'Moduł agency wyłączony'], 403);

        $subtoken = HDS3_Auth::bearer_token();

        if (!$subtoken) return new WP_REST_Response(['ok'=>0,'error'=>'Brak Bearer'], 401);

        $body = $req->get_json_params();

        $domain = sanitize_text_field($body['domain'] ?? '');

        $product = sanitize_text_field($body['product'] ?? 'SEO');

        $out = HDS3_Agency::verify_subtoken($subtoken, $domain, $product);

        if (empty($out['ok'])) return new WP_REST_Response($out, 403);

        return $out;

    }



    public static function admin_modules_save(WP_REST_Request $req) {

        // Przyjmujemy JSON: {"modules": {"telemetry": true, ...}} lub {"modules": ["telemetry","licenses"]}

        $body = $req->get_json_params();

        $mods = isset($body['modules']) ? $body['modules'] : null;



        $known = array_keys(HDS3_Settings::known_modules());

        $map = [];



        if (is_array($mods)) {

            // associative map

            $is_assoc = array_keys($mods) !== range(0, count($mods) - 1);

            if ($is_assoc) {

                foreach ($known as $k) {

                    $map[$k] = !empty($mods[$k]) ? 1 : 0;

                }

            } else {

                // list

                $set = array_fill_keys($mods, 1);

                foreach ($known as $k) {

                    $map[$k] = !empty($set[$k]) ? 1 : 0;

                }

            }

        } else {

            return new WP_REST_Response(['ok'=>0,'error'=>'Brak pola modules'], 400);

        }



        foreach ($map as $k => $en) {

            HDS3_Settings::set_module($k, $en);

        }



        if (function_exists('wp_cache_flush')) {

            @wp_cache_flush();

        }



        return ['ok'=>1,'modules'=>HDS3_Settings::modules()];

    }



    public static function health(WP_REST_Request $req) {

        // Minimalna odpowiedź publiczna, bez wrażliwych danych.

        $basic = [

            'ok' => 1,

            'version' => HDS3_VERSION,


            'ts' => time(),

        ];



        // Jeśli jest poprawny Bearer — dokładamy szczegóły (dla panelu / integracji).

        $auth = HDS3_Auth::verify_bearer_or_die();

        if (is_wp_error($auth) || !is_array($auth) || ($auth['mode'] ?? '') !== 'system') {

            return $basic;

        }



        global $wpdb;

        $tables = ['settings','events','tenants','plans','tenant_plans','licenses','instances','verification','verification_steps','jobs','job_runs','seo_activations','seo_commands'];

        $tbl = [];

        foreach ($tables as $t) {

            $name = HDS3_DB::table($t);

            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name);

            $tbl[$t] = [

                'table' => $name,

                'exists' => $exists ? 1 : 0,

                'count' => $exists ? (int)$wpdb->get_var("SELECT COUNT(*) FROM $name") : null,

            ];

        }



        return $basic + [

            'token_source' => HDS3_Auth::token_source(),

            'db' => [

                'prefix' => $wpdb->prefix,

                'tables' => $tbl,

            ],

        ];

    }



    private static function guard_result($maybe) {

        if (is_wp_error($maybe)) return $maybe;

        return true;

    }



    public static function telemetry_event(WP_REST_Request $req) {

        $auth = HDS3_Auth::require_scope_or_error('telemetry:write');

        if (is_wp_error($auth)) return $auth;



        $body = $req->get_json_params();

        $etype = sanitize_text_field($body['type'] ?? 'event');

        $level = sanitize_text_field($body['level'] ?? 'info');

        $msg = sanitize_text_field($body['message'] ?? '');

        $payload = $body['payload'] ?? null;

        $site = sanitize_text_field($body['site'] ?? hds3_site_host());



        HDS3_Logger::event($etype, $level, $msg, $payload, $site);

        return ['ok'=>1];

    }



    public static function license_activate(WP_REST_Request $req) {

        $auth = HDS3_Auth::require_scope_or_error('license:activate');

        if (is_wp_error($auth)) return $auth;



        $b = $req->get_json_params();

        $license_key = sanitize_text_field($b['license_key'] ?? '');

        $site = sanitize_text_field($b['domena'] ?? ($b['site'] ?? hds3_site_host()));

        $product = sanitize_text_field($b['produkt'] ?? ($b['product'] ?? 'CORE'));



        if (!$license_key || !$site) return new WP_Error('hds3_bad', 'Brak license_key lub domeny.', ['status'=>400]);



        global $wpdb;

        $lt = HDS3_DB::table('licenses');

        $lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE license_key=%s AND status='active' LIMIT 1", $license_key), ARRAY_A);

        if (!$lic) return new WP_Error('hds3_no_license', 'Nie znaleziono aktywnej licencji.', ['status'=>404]);
        $ownership = HDS3_Auth::require_tenant_match((string)$lic['tenant_id'], 'license:activate');
        if (is_wp_error($ownership)) return $ownership;



        $it = HDS3_DB::table('instances');

        $now = hds3_now_gmt();

        $wpdb->query($wpdb->prepare(

            "INSERT INTO $it (tenant_id, site, product, status, activated_at, last_seen_at, meta)

             VALUES (%s,%s,%s,'active',%s,%s,NULL)

             ON DUPLICATE KEY UPDATE status='active', last_seen_at=%s",

            $lic['tenant_id'], strtolower($site), $product ?: 'CORE', $now, $now, $now

        ));



        HDS3_Logger::event('license','info','Aktywacja', ['tenant'=>$lic['tenant_id'],'site'=>$site,'product'=>$product]);

        return ['ok'=>1,'tenant_id'=>$lic['tenant_id'],'site'=>$site,'product'=>$product];

    }



    public static function license_validate(WP_REST_Request $req) {

        $auth = HDS3_Auth::require_scope_or_error('license:validate');

        if (is_wp_error($auth)) return $auth;



        $b = $req->get_json_params();

        $license_key = sanitize_text_field($b['license_key'] ?? '');

        $site = sanitize_text_field($b['domena'] ?? ($b['site'] ?? hds3_site_host()));

        $product = sanitize_text_field($b['produkt'] ?? ($b['product'] ?? 'CORE'));

        if (!$license_key) return new WP_Error('hds3_bad', 'Brak license_key.', ['status'=>400]);



        global $wpdb;

        $lt = HDS3_DB::table('licenses');

        $lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE license_key=%s LIMIT 1", $license_key), ARRAY_A);

        if (!$lic) return ['valid'=>0,'reason'=>'not_found'];
        $ownership = HDS3_Auth::require_tenant_match((string)$lic['tenant_id'], 'license:validate');
        if (is_wp_error($ownership)) return $ownership;

        if ($lic['status'] !== 'active') return ['valid'=>0,'reason'=>'inactive'];



        // instance optional check

        $it = HDS3_DB::table('instances');

        $inst = $wpdb->get_row($wpdb->prepare("SELECT * FROM $it WHERE tenant_id=%s AND site=%s AND product=%s LIMIT 1", $lic['tenant_id'], strtolower($site), $product ?: 'CORE'), ARRAY_A);

        return ['valid'=>1,'tenant_id'=>$lic['tenant_id'],'product'=>$product,'site'=>$site,'instance'=> $inst ? 1 : 0];

    }



    public static function verification_status(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('verification')) return ['enabled'=>0];

        $site = sanitize_text_field($req->get_param('site') ?? hds3_site_host());

        HDS3_Kernel::ensure_verification_subject('site', strtolower($site));

        $v = HDS3_Kernel::verification_get('site', strtolower($site));

        return ['enabled'=>1,'site'=>$site,'status'=>$v['status'] ?? 'unverified','current_step'=>$v['current_step'] ?? 'start'];

    }



    public static function verification_steps(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('verification')) return ['enabled'=>0];

        $site = sanitize_text_field($req->get_param('site') ?? hds3_site_host());

        HDS3_Kernel::ensure_verification_subject('site', strtolower($site));

        $steps = HDS3_Kernel::verification_steps('site', strtolower($site));

        return ['enabled'=>1,'site'=>$site,'steps'=>$steps];

    }



    public static function verification_complete_step(WP_REST_Request $req) {

        $auth = HDS3_Auth::require_scope_or_error('verification:write');

        if (is_wp_error($auth)) return $auth;



        $b = $req->get_json_params();

        $site = sanitize_text_field($b['site'] ?? ($b['domena'] ?? hds3_site_host()));

        $step = sanitize_text_field($b['step'] ?? '');

        if (!$step) return new WP_Error('hds3_bad', 'Brak step.', ['status'=>400]);

        $res = HDS3_Kernel::verification_complete_step('site', strtolower($site), $step);

        HDS3_Logger::event('verification','info','REST complete-step', ['site'=>$site,'step'=>$step,'res'=>$res]);

        return ['ok'=>1] + $res;

    }



    public static function ppv_complete(WP_REST_Request $req) {

        $auth = HDS3_Auth::require_scope_or_error('verification:write');

        if (is_wp_error($auth)) return $auth;

        $b = $req->get_json_params();

        $site = sanitize_text_field($b['site'] ?? ($b['domena'] ?? hds3_site_host()));

        $step = sanitize_text_field($b['step'] ?? 'done');

        if ($step === 'done') {

            HDS3_Kernel::verification_set_verified('site', strtolower($site));

            return ['ok'=>1,'status'=>'verified'];

        }

        $res = HDS3_Kernel::verification_complete_step('site', strtolower($site), $step);

        return ['ok'=>1] + $res;

    }



    public static function admin_events(WP_REST_Request $req) {

        global $wpdb;

        $t = HDS3_DB::table('events');

        $rows = $wpdb->get_results("SELECT id,created_at,site,`type`,level,message FROM $t ORDER BY id DESC LIMIT 200", ARRAY_A);

        return ['rows'=>$rows];

    }



    public static function admin_jobs_run(WP_REST_Request $req) {

        $count = self::run_jobs_batch(20);

        return ['processed'=>$count];

    }



    public static function run_jobs_batch($limit = 20) {

        if (!HDS3_Settings::module_enabled('queue')) return 0;

        global $wpdb;

        $jt = HDS3_DB::table('jobs');

        $rt = HDS3_DB::table('job_runs');

        $now = hds3_now_gmt();

        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jt WHERE status='queued' AND (run_after IS NULL OR run_after <= %s) ORDER BY priority ASC, id ASC LIMIT %d", $now, (int)$limit), ARRAY_A);

        $processed = 0;



        foreach ($jobs as $j) {

            $processed++;

            $wpdb->update($jt, ['status'=>'running','updated_at'=>$now], ['id'=>$j['id']]);

            $run_id = null;

            $wpdb->insert($rt, ['job_id'=>$j['id'],'started_at'=>$now,'finished_at'=>null,'result'=>'ok','message'=>null]);

            $run_id = $wpdb->insert_id;



            // "worker" demo - just log and mark done

            $payload = $j['payload'] ? $j['payload'] : null;

            HDS3_Logger::event('job','info','Wykonano job: '.$j['jtype'], ['job_id'=>$j['id'],'payload'=>$payload]);



            $wpdb->update($jt, ['status'=>'done','updated_at'=>hds3_now_gmt()], ['id'=>$j['id']]);

            $wpdb->update($rt, ['finished_at'=>hds3_now_gmt(),'result'=>'ok','message'=>'done'], ['id'=>$run_id]);

        }

        return $processed;

    }



    public static function import_ppv_best_effort() {

        global $wpdb;

        $count = 0;



        // 1) Options: mark site verified if legacy says so

        $legacy_keys = ['ppv_verified','portal_verification_verified','ppv_status','portal_verification_status'];

        foreach ($legacy_keys as $k) {

            $v = get_option($k, null);

            if ($v === '1' || $v === 1 || $v === true || $v === 'verified') {

                HDS3_Kernel::verification_set_verified('site', hds3_site_host());

                $count++;

            }

        }



        // 2) Table sniffing: wp_ppv_verifications if exists

        $ppv_table = $wpdb->prefix . 'ppv_verifications';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ppv_table));

        if ($exists) {

            $rows = $wpdb->get_results("SELECT * FROM $ppv_table ORDER BY id DESC LIMIT 200", ARRAY_A);

            foreach ($rows as $r) {

                // best effort fields

                $status = '';

                foreach (['status','state','verification_status'] as $f) if (isset($r[$f])) { $status = (string)$r[$f]; break; }

                $user_id = null;

                foreach (['user_id','uid'] as $f) if (isset($r[$f])) { $user_id = (string)$r[$f]; break; }

                $step = '';

                foreach (['step','stage','current_step'] as $f) if (isset($r[$f])) { $step = (string)$r[$f]; break; }



                if ($status === 'verified' || $status === 'done' || $status === '1') {

                    if ($user_id) HDS3_Kernel::verification_set_verified('user', 'u'.$user_id);

                    else HDS3_Kernel::verification_set_verified('site', hds3_site_host());

                    $count++;

                } elseif ($step) {

                    if ($user_id) HDS3_Kernel::verification_complete_step('user','u'.$user_id,$step);

                    else HDS3_Kernel::verification_complete_step('site', hds3_site_host(), $step);

                    $count++;

                }

            }

        }



        HDS3_Logger::event('ppv','info','Import PPV (best effort)', ['count'=>$count]);

        return $count;

    }

    // ===== AGENCY: bootstrap/provision =====



    private static function provision_sign_key(): string {

        // Klucz podpisu dla tokenu provisioning (server-side)

        $k = defined('AUTH_SALT') ? (string)AUTH_SALT : '';

        if (!$k) $k = defined('NONCE_SALT') ? (string)NONCE_SALT : 'hds3_provision_fallback_key';

        return $k;

    }



    private static function b64url_encode($data): string {

        return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');

    }



    private static function b64url_decode($data): string {

        $data = strtr((string)$data, '-_', '+/');

        $pad = strlen($data) % 4;

        if ($pad) $data .= str_repeat('=', 4-$pad);

        return base64_decode($data);

    }



    private static function make_provision_token(string $license_key, string $site, string $product, int $exp_ts): string {

        $payload = [

            'lk' => hash('sha256', $license_key),

            'site' => strtolower($site),

            'prod' => strtoupper($product ?: 'AGENCY_SEO'),

            'exp' => (int)$exp_ts,

        ];

        $p = wp_json_encode($payload);

        $p64 = self::b64url_encode($p);

        $sig = hash_hmac('sha256', $p64, self::provision_sign_key());

        return 'HDS3P.' . $p64 . '.' . $sig;

    }



    private static function verify_provision_token(string $token, string $license_key, string $site, string $product) {

        $token = (string)$token;

        if (stripos($token, 'HDS3P.') !== 0) return new WP_Error('hds3_bad', 'Niepoprawny token provisioning.', ['status'=>403]);

        $parts = explode('.', $token);

        if (count($parts) !== 3) return new WP_Error('hds3_bad', 'Niepoprawny format tokenu provisioning.', ['status'=>403]);

        $p64 = $parts[1];

        $sig = $parts[2];



        $expect = hash_hmac('sha256', $p64, self::provision_sign_key());

        if (!hash_equals($expect, $sig)) return new WP_Error('hds3_bad', 'Niepoprawny podpis tokenu provisioning.', ['status'=>403]);



        $json = self::b64url_decode($p64);

        $payload = json_decode(is_string($json)?$json:'', true);

        if (!is_array($payload)) return new WP_Error('hds3_bad', 'Niepoprawny payload tokenu provisioning.', ['status'=>403]);



        if (!empty($payload['exp']) && time() > (int)$payload['exp']) {

            return new WP_Error('hds3_bad', 'Token provisioning wygasł. Spróbuj ponownie.', ['status'=>403]);

        }



        $lk_hash = hash('sha256', (string)$license_key);

        if (empty($payload['lk']) || !hash_equals((string)$payload['lk'], $lk_hash)) {

            return new WP_Error('hds3_bad', 'Token provisioning nie pasuje do klucza.', ['status'=>403]);

        }



        $site = strtolower((string)$site);

        if (empty($payload['site']) || strtolower((string)$payload['site']) !== $site) {

            return new WP_Error('hds3_bad', 'Token provisioning nie pasuje do domeny.', ['status'=>403]);

        }



        $prod = strtoupper((string)($product ?: 'AGENCY_SEO'));

        if (empty($payload['prod']) || strtoupper((string)$payload['prod']) !== $prod) {

            return new WP_Error('hds3_bad', 'Token provisioning nie pasuje do produktu.', ['status'=>403]);

        }



        return true;

    }



    public static function agency_bootstrap_token(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('agency')) return new WP_Error('hds3_disabled','Moduł agencyjny wyłączony.', ['status'=>403]);



        $b = $req->get_json_params();

        $license_key = sanitize_text_field($b['license_key'] ?? '');

        $product = sanitize_text_field($b['product'] ?? 'AGENCY_SEO');

        $site = sanitize_text_field($b['site'] ?? hds3_site_host());

        $site = strtolower(preg_replace('#^https?://#','', $site));

        $site = trim($site,'/');



        if (!$license_key) return new WP_Error('hds3_bad', 'Brak license_key.', ['status'=>400]);

        if (stripos($license_key, 'AGENCY_SEO-') !== 0) return new WP_Error('hds3_bad', 'Nieprawidłowy klucz agencji (wymagany prefix AGENCY_SEO-).', ['status'=>400]);



        global $wpdb;

        $lt = HDS3_DB::table('licenses');

        $lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE license_key=%s LIMIT 1", $license_key), ARRAY_A);

        if (!$lic) return new WP_Error('hds3_bad', 'Nie znaleziono licencji.', ['status'=>404]);

        if (($lic['status'] ?? '') !== 'active') return new WP_Error('hds3_bad', 'Licencja nieaktywna.', ['status'=>403]);



        // Token provisioning ważny 5 minut

        $exp = time() + 300;

        $prov = self::make_provision_token($license_key, $site, $product ?: 'AGENCY_SEO', $exp);

        return [

            'ok' => 1,

            'provision_token' => $prov,

            'expires_at' => $exp,

        ];

    }



    public static function agency_provision(WP_REST_Request $req) {

        if (!HDS3_Settings::module_enabled('agency')) return new WP_Error('hds3_disabled','Moduł agencyjny wyłączony.', ['status'=>403]);



        $b = $req->get_json_params();

        $license_key = sanitize_text_field($b['license_key'] ?? '');

        $product = sanitize_text_field($b['product'] ?? 'AGENCY_SEO');

        $site = sanitize_text_field($b['site'] ?? hds3_site_host());

        $site = strtolower(preg_replace('#^https?://#','', $site));

        $site = trim($site,'/');



        // Bearer = token provisioning (HDS3P.*)

        $bearer = HDS3_Auth::bearer_token();

        if (!$bearer) return new WP_Error('hds3_forbidden', 'Brak tokenu Bearer.', ['status'=>403]);

        $v = self::verify_provision_token($bearer, $license_key, $site, $product ?: 'AGENCY_SEO');

        if (is_wp_error($v)) return $v;



        global $wpdb;

        $lt = HDS3_DB::table('licenses');

        $lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE license_key=%s LIMIT 1", $license_key), ARRAY_A);

        if (!$lic) return new WP_Error('hds3_bad', 'Nie znaleziono licencji.', ['status'=>404]);

        if (($lic['status'] ?? '') !== 'active') return new WP_Error('hds3_bad', 'Licencja nieaktywna.', ['status'=>403]);



        $tenant_id = (string)($lic['tenant_id'] ?? '');

        if (!$tenant_id) return new WP_Error('hds3_bad', 'Licencja bez tenant_id.', ['status'=>500]);



        // upsert instance

        $it = HDS3_DB::table('instances');

        $now = hds3_now_gmt();

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $it WHERE tenant_id=%s AND site=%s AND product=%s LIMIT 1", $tenant_id, strtolower($site), $product ?: 'AGENCY_SEO'));

        if ($exists) {

            $wpdb->update($it, ['status'=>'active','last_seen_at'=>$now], ['id'=>(int)$exists], ['%s','%s'], ['%d']);

            $instance_id = (int)$exists;

        } else {

            $wpdb->insert($it, [

                'tenant_id'=>$tenant_id,

                'site'=>strtolower($site),

                'product'=>$product ?: 'AGENCY_SEO',

                'status'=>'active',

                'activated_at'=>$now,

                'last_seen_at'=>$now,

                'meta'=>null,

            ]);

            $instance_id = (int)$wpdb->insert_id;

        }



        // token instancji (DB)

        // HDS3_Tokens::create() zwraca tablicę z kluczem 'token' (plain) + metadane.

        $tok_row = HDS3_Tokens::create($tenant_id, ['agency:manage'], 'agency_instance:'.$site);

        if (is_wp_error($tok_row)) return $tok_row;

        $tok = (string)($tok_row['token'] ?? '');

        if (!$tok) return new WP_Error('hds3_bad', 'Nie udało się wygenerować tokenu instancji.', ['status'=>500]);

        $masked = substr($tok,0,6).'…'.substr($tok,-4);



        return [

            'ok'=>1,

            'instance_id'=>$instance_id,

            'instance_token'=>$tok,

            'instance_token_masked'=>$masked,

            'activated_at'=>$lic['activated_at'] ?? null,

            'expires_at'=>$lic['expires_at'] ?? null,

            'package'=>$lic['package'] ?? null,

            'duration'=>$lic['duration'] ?? null,

        ];

    }







    // ====== ADMIN OPS (ZIP 3) ======



    public static function admin_ops_tenant_revoke(WP_REST_Request $req) {

        $tenant_id = sanitize_text_field((string)$req->get_param('tenant_id'));

        $reason = sanitize_text_field((string)$req->get_param('reason'));

        if (!$tenant_id) return new WP_Error('hds3_bad', 'Brak tenant_id', ['status'=>400]);

        HDS3_Tenants::soft_revoke($tenant_id, $reason);

        HDS3_Logger::event('tenants','warning','Zawieszono tenant (API soft revoke)', ['tenant_id'=>$tenant_id,'reason'=>$reason]);

        return ['ok'=>1,'tenant_id'=>$tenant_id];

    }



    public static function admin_ops_tenant_delete(WP_REST_Request $req) {

        $tenant_id = sanitize_text_field((string)$req->get_param('tenant_id'));

        if (!$tenant_id) return new WP_Error('hds3_bad', 'Brak tenant_id', ['status'=>400]);

        $out = HDS3_Tenants::hard_delete($tenant_id);

        HDS3_Logger::event('tenants','warning','Usunieto tenant (API hard delete)', $out);

        return $out;

    }



    public static function admin_ops_token_delete(WP_REST_Request $req) {

        $id = (int)$req->get_param('token_id');

        if ($id <= 0) return new WP_Error('hds3_bad', 'Brak token_id', ['status'=>400]);

        HDS3_Tokens::hard_delete($id);

        HDS3_Logger::event('tenants','warning','Usunieto token (API)', ['id'=>$id]);

        return ['ok'=>1,'id'=>$id];

    }



    public static function admin_ops_license_revoke(WP_REST_Request $req) {

        $license_key = sanitize_text_field((string)$req->get_param('license_key'));

        if (!$license_key) return new WP_Error('hds3_bad', 'Brak license_key', ['status'=>400]);

        global $wpdb;

        $t = HDS3_DB::table('licenses');

        $wpdb->update($t, ['status'=>'revoked'], ['license_key'=>$license_key]);

        HDS3_Logger::event('licenses','warning','Uniewazniono licencje (API)', ['license_key'=>$license_key]);

        return ['ok'=>1,'license_key'=>$license_key];

    }



    public static function admin_ops_license_delete(WP_REST_Request $req) {

        $license_key = sanitize_text_field((string)$req->get_param('license_key'));

        if (!$license_key) return new WP_Error('hds3_bad', 'Brak license_key', ['status'=>400]);

        global $wpdb;

        $t = HDS3_DB::table('licenses');

        $wpdb->delete($t, ['license_key'=>$license_key]);

        HDS3_Logger::event('licenses','warning','Usunieto licencje (API)', ['license_key'=>$license_key]);

        return ['ok'=>1,'license_key'=>$license_key];

    }



    public static function admin_ops_connector_revoke(WP_REST_Request $req) {

        $id = (int)$req->get_param('id');

        $reason = sanitize_text_field((string)$req->get_param('reason'));

        if ($id <= 0) return new WP_Error('hds3_bad', 'Brak id', ['status'=>400]);

        $out = HDS3_Agency::revoke_connector($id, $reason);

        HDS3_Logger::event('agency','warning','Revoke konektora (API)', $out);

        return $out;

    }



    public static function admin_ops_connector_delete(WP_REST_Request $req) {

        $id = (int)$req->get_param('id');

        if ($id <= 0) return new WP_Error('hds3_bad', 'Brak id', ['status'=>400]);

        $out = HDS3_Agency::hard_delete_connector($id);

        HDS3_Logger::event('agency','warning','Usunieto konektor (API)', $out);

        return $out;

    }

}

