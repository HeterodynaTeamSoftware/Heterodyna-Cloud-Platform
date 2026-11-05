<?php

if (!defined('ABSPATH')) exit;



/**

 * Portal klienta (SaaS v3) — widok podglądowy licencji/tenantów w stylu SEO.heterodyna.pl

 * Cel: działać na każdej bazie (legacy) bez fatali — tylko read-only.

 */

class HDS3_Admin_Portal {



    private function get_brand_logo_url() {

        // Prefer ustawienie w SaaS v3 (wp_options) – proste i pewne.

        $u = get_option('hds3_brand_logo_url', '');

        $u = is_string($u) ? trim($u) : '';

        if ($u) return esc_url_raw($u);



        // Fallback: plik logo z katalogu wtyczki (FTP): assets/logo/logo.png

        if (defined('HDS3_PATH') && defined('HDS3_URL')) {

            $file = rtrim(HDS3_PATH, '/\\') . '/assets/logo/logo.png';

            if (file_exists($file)) {

                return esc_url_raw(rtrim(HDS3_URL, '/\\') . '/assets/logo/logo.png');

            }

        }



        // Fallback: logo witryny (jeśli ustawione w WP)

        $custom = function_exists('get_custom_logo') ? get_custom_logo() : '';

        if ($custom && preg_match('/src=["\']([^"\']+)/', $custom, $m)) {

            return esc_url_raw($m[1]);

        }

        return '';

    }



    private function render_topnav($active_tab, $tenant_id) {

        $base_url = menu_page_url('hds3_portal', false);

        $mk_url = function($t) use ($base_url, $tenant_id) {

            $u = add_query_arg('tab', $t, $base_url);

            if ($tenant_id) $u = add_query_arg('tenant_id', $tenant_id, $u);

            return $u;

        };

        $logo = $this->get_brand_logo_url();

        ?>

        <div class="hds3-topnav">

            <div class="hds3-topnav__brand">

                <?php if ($logo): ?>

                    <img class="hds3-brand-logo" src="<?php echo hds3_safe_url($logo); ?>" alt="Heterodyna"/>

                <?php else: ?>

                    <span class="hds3-brand-text">Heterodyna Team Software</span>

                <?php endif; ?>

            </div>



            <div class="hds3-topnav__tabs" role="tablist" aria-label="Sekcje portalu">

                <a class="hds3-tab <?php echo $active_tab==='status'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('status')); ?>">Status</a>

                <a class="hds3-tab <?php echo $active_tab==='limity'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('limity')); ?>">Limity</a>

                <a class="hds3-tab <?php echo $active_tab==='konektory'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('konektory')); ?>">Konektory</a>

                <a class="hds3-tab <?php echo $active_tab==='telemetria'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('telemetria')); ?>">Telemetria</a>

            </div>



            <div class="hds3-topnav__meta">Portal klienta</div>

        </div>

        <?php

    }





    public function __construct() {

        add_action('admin_menu', [$this,'register_menu'], 55);

        add_action('admin_enqueue_scripts', [$this,'enqueue_assets']);

    }



    public function register_menu() {

        add_submenu_page(

            'hds3_center',

            'Portal klienta',

            'Portal klienta',

            'manage_options',

            'hds3_portal',

            [$this,'render']

        );

    }



    public function enqueue_assets($hook) {

        if (strpos((string)$hook, 'hds3_portal') === false) return;        wp_enqueue_style('hds3-portal-theme', plugin_dir_url(__FILE__) . '../assets/hds3-portal.css', [], HDS3_VERSION);

    }



    private function mask_key(string $k): string {

        $k = trim($k);

        if ($k === '') return '';

        $k2 = preg_replace('/[^A-Za-z0-9\-]/', '', $k);

        if (strlen($k2) <= 10) return $k2;

        return substr($k2, 0, 6) . '-****-****-' . substr($k2, -4);

    }



    private function mask_token(string $t): string {

        $t = trim($t);

        if ($t === '') return '';

        if (strlen($t) <= 12) return substr($t, 0, 4) . '****';

        return substr($t, 0, 4) . '****' . substr($t, -4);

    }



    private function read_meta($raw): array {

        if (is_array($raw)) return $raw;

        if (!is_string($raw) || $raw === '') return [];

        $d = json_decode($raw, true);

        return is_array($d) ? $d : [];

    }



    private function pick_tenant_id(): string {

        $tid = sanitize_text_field($_GET['tenant_id'] ?? '');

        if ($tid) return $tid;



        // fallback: jeśli jest tylko 1 tenant, wybierz go automatycznie

        global $wpdb;

        $t = HDS3_DB::table('tenants');

        $row = $wpdb->get_row("SELECT tenant_id FROM $t ORDER BY id ASC LIMIT 1", ARRAY_A);

        return (string)($row['tenant_id'] ?? '');

    }



    public function render() {



        if (!class_exists('HDS3_DB')) {

            echo '<div class="wrap"><h1>Portal Klienta</h1><div class="notice notice-error"><p>Brak rdzenia DB (HDS3_DB).</p></div></div>';

            return;

        }



        global $wpdb;



        $site = function_exists('hds3_site_host') ? hds3_site_host() : (string)parse_url(home_url(), PHP_URL_HOST);

        $tenant_id = $this->pick_tenant_id();



        $tab = sanitize_key($_GET['tab'] ?? 'status');

        if (!in_array($tab, ['status','limity','konektory','telemetria','problemy'], true)) $tab = 'status';



        $t_tenants  = HDS3_DB::table('tenants');

        $t_licenses = HDS3_DB::table('licenses');

        $t_con      = HDS3_DB::table('agency_connectors');

        $t_evt      = HDS3_DB::table('events');



        $tenants = $wpdb->get_results("SELECT tenant_id, name, status FROM $t_tenants ORDER BY id ASC LIMIT 200", ARRAY_A);

        if (!is_array($tenants)) $tenants = [];



        $licenses = [];

        if ($tenant_id) {

            $licenses = $wpdb->get_results($wpdb->prepare(

                "SELECT * FROM $t_licenses WHERE tenant_id=%s ORDER BY id DESC LIMIT 200",

                $tenant_id

            ), ARRAY_A);

            if (!is_array($licenses)) $licenses = [];

        }



        // Token rdzenia (jeśli jest)

        $effective_token = class_exists('HDS3_Auth') ? (string)HDS3_Auth::client_token() : '';

        $token_src = class_exists('HDS3_Auth') ? (string)HDS3_Auth::token_source() : '';



        // Zbierz klucze licencji tego tenant'a (do filtrów konektorów)

        $license_keys = [];

        $license_meta_by_key = [];

        foreach ((array)$licenses as $l) {

            $k = (string)($l['license_key'] ?? $l['key'] ?? '');

            if ($k) {

                $license_keys[] = $k;

                $license_meta_by_key[$k] = $this->read_meta($l['meta'] ?? null);

            }

        }



        // Konektory: best effort — filtr po parent_license_key IN (klucze licencji tenant'a)

        $connectors = [];

        $connectors_by_parent = [];

        $domains = [];

        if ($license_keys) {

            // Bezpieczne IN: placeholders

            $place = implode(',', array_fill(0, count($license_keys), '%s'));

            $sql = "SELECT * FROM $t_con WHERE parent_license_key IN ($place) ORDER BY id DESC LIMIT 2000";

            $connectors = $wpdb->get_results($wpdb->prepare($sql, ...$license_keys), ARRAY_A);

            if (!is_array($connectors)) $connectors = [];

            foreach ((array)$connectors as $c) {

                $p = (string)($c['parent_license_key'] ?? '');

                if ($p) $connectors_by_parent[$p] = ($connectors_by_parent[$p] ?? 0) + 1;

                $d = (string)($c['domain'] ?? '');

                if ($d) $domains[] = $d;

            }

        }



        // Telemetria: ostatnie eventy dla domen tenant'a (z konektorów)

        $events = [];

        if ($tab === 'telemetria' && $domains) {

            $domains = array_values(array_unique(array_slice($domains, 0, 200))); // limit IN

            $place = implode(',', array_fill(0, count($domains), '%s'));

            $sql = "SELECT id,site,created_at,`type`,level,message FROM $t_evt WHERE site IN ($place) ORDER BY id DESC LIMIT 80";

            try {

                $events = $wpdb->get_results($wpdb->prepare($sql, ...$domains), ARRAY_A);

            } catch (Throwable $e) { $events = []; }

            if (!is_array($events)) $events = [];

        }



        $base_url = menu_page_url('hds3_portal', false);

        $mk_url = function($t) use ($base_url, $tenant_id) {

            $u = add_query_arg('tab', $t, $base_url);

            if ($tenant_id) $u = add_query_arg('tenant_id', $tenant_id, $u);

            return $u;

        };



        ?>

        <?php if (class_exists('HDS3_Admin')) { } ?>

        <div class="hds3-portal">

            <div class="hds3-toprow">

                <div>

                    <h1 class="hds3-title">Portal Klienta</h1>

                    <p class="hds3-muted">Witryna: <code><?php echo hds3_safe_html($site); ?></code></p>

                </div>



                <form method="get" class="hds3-tenantpick">

                    <input type="hidden" name="page" value="hds3_portal"/>

                    <input type="hidden" name="tab" value="<?php echo hds3_safe_attr($tab); ?>"/>

                    <label>

                        <span>Tenant</span>

                        <select name="tenant_id" onchange="this.form.submit()">

                            <option value="">— wybierz —</option>

                            <?php foreach ((array)$tenants as $t): 

                                $id = (string)($t['tenant_id'] ?? '');

                                $nm = (string)($t['name'] ?? '');

                                $st = (string)($t['status'] ?? '');

                                ?>

                                <option value="<?php echo hds3_safe_attr($id); ?>" <?php selected($tenant_id, $id); ?>>

                                    <?php echo hds3_safe_html(($nm?$nm:$id) . ($st? " · ".$st:"")); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                </form>

            </div>



            <div class="hds3-topbar" style="margin-top:0">

                <div class="hds3-topbar__brand">Portal klienta</div>

                <div class="hds3-tabs" role="tablist" aria-label="Sekcje portalu">

                    <a class="hds3-tab <?php echo $tab==='status'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('status')); ?>">Status</a>

                    <a class="hds3-tab <?php echo $tab==='limity'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('limity')); ?>">Limity</a>

                    <a class="hds3-tab <?php echo $tab==='konektory'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('konektory')); ?>">Konektory</a>

                    <a class="hds3-tab <?php echo $tab==='telemetria'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('telemetria')); ?>">Telemetria</a>

                    <a class="hds3-tab <?php echo $tab==='problemy'?'is-active':''; ?>" href="<?php echo hds3_safe_url($mk_url('problemy')); ?>">Problemy</a>

                </div>

                <div class="hds3-topbar__meta">Tenant: <code><?php echo hds3_safe_html($tenant_id ?: '—'); ?></code></div>

            </div>



            <?php if (!$tenant_id): ?>

                <div class="hds3-card"><h2>Wybierz tenant</h2><p class="hds3-muted">Aby zobaczyć dane portalu, wybierz tenant z listy w prawym górnym rogu.</p></div>

            <?php else: ?>



                <?php if ($tab === 'status'): ?>

                    <div class="hds3-card">

                        <h2>Status licencji</h2>

                        <p>Token rdzenia: <code><?php echo hds3_safe_html($this->mask_token($effective_token)); ?></code> <span class="hds3-muted">(<?php echo hds3_safe_html($token_src ?: 'unknown'); ?>)</span></p>



                        <div class="hds3-tablewrap">

                            <table class="widefat striped hds3-table">

                                <thead><tr>

                                    <th>ID</th><th>Produkt</th><th>Klucz</th><th>Status</th><th>Ważna do</th><th>Pakiet</th>

                                </tr></thead>

                                <tbody>

                                <?php foreach ((array)$licenses as $l):

                                    $meta = $this->read_meta($l['meta'] ?? null);

                                    $prod = (string)($l['product'] ?? $meta['product'] ?? $meta['app'] ?? '—');

                                    $key  = (string)($l['license_key'] ?? $l['key'] ?? '');

                                    $st   = (string)($l['status'] ?? $meta['status'] ?? '—');

                                    $exp  = (string)($l['expires_at'] ?? $meta['expires_at'] ?? $meta['expires'] ?? '—');

                                    $pkg  = (string)($l['plan_code'] ?? $meta['plan_code'] ?? $meta['plan'] ?? '—');

                                    ?>

                                    <tr>

                                        <td><?php echo hds3_safe_html($l['id'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($prod); ?></td>

                                        <td><code><?php echo hds3_safe_html($this->mask_key($key)); ?></code></td>

                                        <td><?php echo hds3_safe_html($st); ?></td>

                                        <td><?php echo hds3_safe_html($exp); ?></td>

                                        <td><?php echo hds3_safe_html($pkg); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if (!$licenses): ?>

                                    <tr><td colspan="6" class="hds3-muted">Brak licencji dla tego tenant'a.</td></tr>

                                <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>



                <?php if ($tab === 'limity'): ?>

                    <div class="hds3-card">

                        <h2>Limity</h2>

                        <p class="hds3-muted">Wyliczenia best-effort. Jeśli w meta planu brak limitów — pokażemy tylko <code>used</code>.</p>



                        <div class="hds3-tablewrap">

                            <table class="widefat striped hds3-table">

                                <thead><tr><th>Klucz</th><th>Konektory</th><th>Limit konektorów</th></tr></thead>

                                <tbody>

                                <?php foreach ((array)$license_keys as $k):

                                    $used = (int)($connectors_by_parent[$k] ?? 0);

                                    $m = (array)($license_meta_by_key[$k] ?? []);

                                    $max = $m['connectors_max'] ?? $m['max_connectors'] ?? $m['limit_connectors'] ?? $m['konektory_max'] ?? null;

                                    ?>

                                    <tr>

                                        <td><code><?php echo hds3_safe_html($this->mask_key($k)); ?></code></td>

                                        <td><?php echo hds3_safe_html($used); ?></td>

                                        <td><?php echo hds3_safe_html($max===null ? '—' : (string)$max); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>



                <?php if ($tab === 'konektory'): ?>

                    <div class="hds3-card">

                        <h2>Konektory</h2>

                        <p class="hds3-muted">Lista konektorów przypiętych do licencji tego tenant'a.</p>



                        <div class="hds3-tablewrap">

                            <table class="widefat striped hds3-table">

                                <thead><tr>

                                    <th>ID</th><th>Parent</th><th>Domena</th><th>Plan</th><th>Status</th><th>Last seen</th><th>Vault</th>

                                </tr></thead>

                                <tbody>

                                <?php foreach ((array)$connectors as $c):

                                    $meta = $this->read_meta($c['meta'] ?? null);

                                    $parent = (string)($c['parent_license_key'] ?? '');

                                    $domain = (string)($c['domain'] ?? '—');

                                    $plan = (string)($c['plan_code'] ?? ($meta['plan_code'] ?? $meta['plan'] ?? '—'));

                                    $st = (string)($c['status'] ?? '—');

                                    $ls = (string)($c['last_seen_at'] ?? '—');

                                    $vault = (string)($c['vault_path'] ?? '');

                                    ?>

                                    <tr>

                                        <td><?php echo hds3_safe_html($c['id'] ?? ''); ?></td>

                                        <td><code><?php echo hds3_safe_html($this->mask_key($parent)); ?></code></td>

                                        <td><?php echo hds3_safe_html($domain); ?></td>

                                        <td><?php echo hds3_safe_html($plan); ?></td>

                                        <td><?php echo hds3_safe_html($st); ?></td>

                                        <td><?php echo hds3_safe_html($ls); ?></td>

                                        <td class="hds3-muted"><?php echo hds3_safe_html($vault ? mb_substr($vault, 0, 48) . (strlen($vault)>48?'…':'') : '—'); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if (!$connectors): ?>

                                    <tr><td colspan="7" class="hds3-muted">Brak konektorów.</td></tr>

                                <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>



                <?php if ($tab === 'problemy'): ?>

                    <div class="hds3-card">

                        <h2>Problemy</h2>

                        <p class="hds3-muted">Ostatnie błędy z telemetrii (best-effort). Jeśli nie ma konektorów/domen — lista może być pusta.</p>

                        <?php

                        $errors = [];

                        if ($domains) {

                            $domains2 = array_values(array_unique(array_slice($domains, 0, 200)));

                            $place2 = implode(',', array_fill(0, count($domains2), '%s'));

                            $sql2 = "SELECT id,site,created_at,`type`,level,message FROM $t_evt WHERE site IN ($place2) AND (level IN ('error','fatal','critical') OR `type` IN ('error','fatal')) ORDER BY id DESC LIMIT 120";

                            try { $errors = $wpdb->get_results($wpdb->prepare($sql2, ...$domains2), ARRAY_A); } catch (Throwable $e) { $errors = []; }

                            if (!is_array($errors)) $errors = [];

                        }

                        ?>

                        <div class="hds3-tablewrap">

                            <table class="widefat striped hds3-table">

                                <thead><tr><th>ID</th><th>Site</th><th>At</th><th>Level</th><th>Type</th><th>Message</th></tr></thead>

                                <tbody>

                                <?php foreach ((array)$errors as $e): ?>

                                    <tr>

                                        <td><?php echo hds3_safe_html($e['id'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['site'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['created_at'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['level'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['type'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html(mb_substr((string)($e['message'] ?? ''), 0, 240)); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if (!$errors): ?><tr><td colspan="6" class="hds3-muted">Brak błędów (albo brak danych).</td></tr><?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>



                <?php if ($tab === 'telemetria'): ?>

                    <div class="hds3-card">

                        <h2>Telemetria</h2>

                        <p class="hds3-muted">Ostatnie zdarzenia dla domen tenant'a (z konektorów). Limit: 80.</p>



                        <div class="hds3-tablewrap">

                            <table class="widefat striped hds3-table">

                                <thead><tr><th>ID</th><th>Site</th><th>At</th><th>Type</th><th>Level</th><th>Message</th></tr></thead>

                                <tbody>

                                <?php foreach ((array)$events as $e):

                                    ?>

                                    <tr>

                                        <td><?php echo hds3_safe_html($e['id'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['site'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['created_at'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['type'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html($e['level'] ?? ''); ?></td>

                                        <td><?php echo hds3_safe_html(mb_substr((string)($e['message'] ?? ''), 0, 180)); ?></td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if (!$events): ?>

                                    <tr><td colspan="6" class="hds3-muted">Brak eventów (albo brak konektorów/domen).</td></tr>

                                <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>



            <?php endif; ?>

        </div>

        <?php if (class_exists('HDS3_Admin')) { } ?>

        <?php



    }

}



new HDS3_Admin_Portal();

