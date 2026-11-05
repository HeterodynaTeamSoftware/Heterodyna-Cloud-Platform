<?php
if (!defined('ABSPATH')) exit;

class HDS3_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_filter('admin_body_class', [__CLASS__, 'app_body_class']);
        add_action('in_admin_header', [__CLASS__, 'app_chrome']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_hds3_export_events', [__CLASS__, 'export_events']);
        add_action('admin_post_hds3_test_la', [__CLASS__, 'handle_test_la']);
        add_action('admin_post_hds3_generate_token', [__CLASS__, 'generate_token']);
        add_action('admin_post_hds3_save_modules', [__CLASS__, 'save_modules']);
        add_action('admin_post_hds3_cleanup_options', [__CLASS__, 'cleanup_options']);
        add_action('admin_post_hds3_create_tenant_token', [__CLASS__, 'create_tenant_token']);
        add_action('admin_post_hds3_revoke_tenant_token', [__CLASS__, 'revoke_tenant_token']);
        add_action('admin_post_hds3_token_delete', [__CLASS__, 'token_delete']);
        add_action('admin_post_hds3_tenant_revoke', [__CLASS__, 'tenant_revoke']);
        add_action('admin_post_hds3_tenant_delete', [__CLASS__, 'tenant_delete']);
        add_action('admin_post_hds3_tenant_vault_delete', [__CLASS__, 'tenant_vault_delete']);
        add_action('admin_post_hds3_connector_revoke', [__CLASS__, 'connector_revoke']);
        add_action('admin_post_hds3_deactivate_plugins', [__CLASS__, 'deactivate_plugins']);
        add_action('admin_post_hds3_jobs_run', [__CLASS__, 'jobs_run']);
        add_action('admin_post_hds3_ppv_import', [__CLASS__, 'ppv_import']);
        add_action('admin_post_hds3_save_tenant_plan', [__CLASS__, 'save_tenant_plan']);
        add_action('admin_post_hds3_save_plans', [__CLASS__, 'save_plans']);
        add_action('admin_post_hds3_create_plan', [__CLASS__, 'create_plan']);
        add_action('admin_post_hds3_set_billing_status', [__CLASS__, 'set_billing_status']);
        add_action('admin_post_hds3_save_performance', [__CLASS__, 'save_performance']);
        add_action('admin_post_hds3_provision_create', [__CLASS__, 'provision_create']);
        add_action('admin_post_hds3_save_addons', [__CLASS__, 'save_addons']);
        add_action('admin_post_hds3_save_catalog', [__CLASS__, 'save_catalog']);
        add_action('admin_post_hds3_product_save', [__CLASS__, 'product_save']);
        add_action('admin_post_hds3_product_archive', [__CLASS__, 'product_archive']);
        add_action('admin_post_hds3_load_seo_catalog_preset', [__CLASS__, 'load_seo_catalog_preset']);

        // Operacje porządkowe na licencjach/konektorach (testy)
        add_action('admin_post_hds3_license_revoke', [__CLASS__, 'license_revoke']);
        add_action('admin_post_hds3_license_delete', [__CLASS__, 'license_delete']);
        add_action('admin_post_hds3_connector_delete', [__CLASS__, 'connector_delete']);
        add_action('admin_post_hds3_connector_vault_delete', [__CLASS__, 'connector_vault_delete']);
        add_action('admin_post_hds3_connector_revoke', [__CLASS__, 'connector_revoke']);
        add_action('admin_post_hds3_bulk_delete_by_prefix', [__CLASS__, 'bulk_delete_by_prefix']);
    }

    /**
     * Ładuje globalny CSS Admin dla wszystkich ekranów hds3_*.
     * Cel: spójny „SaaS vibe” + czytelność tabel (scroll X + sticky header).
     */
    public static function enqueue_assets($hook) {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? (string)$_GET['page'] : '';
        if ($page === 'hds3_center' || strpos($page, 'hds3_') === 0) {
            wp_enqueue_style('hds3-admin-ui', HDS3_URL . 'assets/admin.css', [], HDS3_VERSION);
            wp_enqueue_style('hds3-premium-ui', HDS3_URL . 'assets/premium-ui.css', ['hds3-admin-ui'], HDS3_VERSION);
            wp_enqueue_style('hds3-design-system', HDS3_URL . 'assets/design-system.css', ['hds3-premium-ui'], HDS3_VERSION);
        }
    }

    private static function is_app_page(): bool {
        if (!is_admin() || !current_user_can('manage_options')) return false;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'hds3_center' || strpos($page, 'hds3_') === 0;
    }

    public static function app_body_class($classes) {
        return self::is_app_page() ? $classes . ' hts-cloud-app ' : $classes;
    }

    public static function app_chrome() {
        if (!self::is_app_page()) return;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'hds3_center';
        $nav = [
            ['hds3_center', 'Pulpit', 'dashicons-dashboard'],
            ['hds3_setup', 'Pierwsza konfiguracja', 'dashicons-admin-tools'],
            ['hds3_tenants', 'Organizacje', 'dashicons-groups'],
            ['hds3_licenses', 'Licencje', 'dashicons-admin-network'],
            ['hds3_plans', 'Plany', 'dashicons-chart-bar'],
            ['hds3_products', 'Produkty', 'dashicons-products'],
            ['hds3_pricing', 'Cenniki', 'dashicons-money-alt'],
            ['hds3_verification', 'Weryfikacja', 'dashicons-shield'],
            ['hds3_queue', 'Kolejki', 'dashicons-schedule'],
            ['hds3_health', 'Stan usług', 'dashicons-heart'],
            ['hds3_modules', 'Moduły', 'dashicons-screenoptions'],
            ['hds3_settings', 'Ustawienia', 'dashicons-admin-generic'],
            ['hds3_data_reset', 'Centrum danych', 'dashicons-database'],
        ];
        echo '<aside class="hts-app-sidebar" aria-label="Nawigacja Heterodyna Cloud">';
        echo '<a class="hts-app-brand" href="'.esc_url(admin_url('admin.php?page=hds3_center')).'">';
        echo '<img src="'.esc_url(HDS3_URL.'assets/logo/logo.svg').'" alt="Heterodyna Team Software" />';
        echo '<span><strong>'.esc_html(get_option('hds3_platform_name_v1', 'Heterodyna Cloud')).'</strong><small>MANAGEMENT CONSOLE</small></span></a>';
        echo '<div class="hts-app-nav-label">PRZESTRZEŃ ROBOCZA</div><nav class="hts-app-nav">';
        foreach ($nav as $entry) {
            $slug = $entry[0];
            if ($slug === 'hds3_tenants' && !HDS3_Settings::module_enabled('tenants')) continue;
            if ($slug === 'hds3_plans' && !HDS3_Settings::module_enabled('plans')) continue;
            if ($slug === 'hds3_licenses' && !HDS3_Settings::module_enabled('licenses')) continue;
            if ($slug === 'hds3_verification' && !HDS3_Settings::module_enabled('verification')) continue;
            if ($slug === 'hds3_queue' && !HDS3_Settings::module_enabled('queue')) continue;
            $active = $slug === $page ? ' aria-current="page" class="hts-nav-current"' : '';
            echo '<a'.$active.' href="'.esc_url(admin_url('admin.php?page='.$slug)).'"><span class="dashicons '.esc_attr($entry[2]).'" aria-hidden="true"></span>'.esc_html($entry[1]).'</a>';
        }
        echo '</nav><div class="hts-app-sidebar-foot"><span class="hts-app-status-dot" aria-hidden="true"></span> Konsola Heterodyna <small>v'.esc_html(HDS3_VERSION).'</small></div></aside>';
        echo '<div class="hts-app-topbar"><span class="hts-topbar-location">Heterodyna Cloud <span class="hts-topbar-separator">/</span> '.esc_html($page === 'hds3_center' ? 'Pulpit' : 'Zarządzanie').'</span><div class="hts-app-top-actions"><a href="'.esc_url(admin_url('index.php')).'">Powrót do WordPress</a><span class="hts-user-mark">'.esc_html(strtoupper(substr(wp_get_current_user()->display_name ?: 'A',0,1))).'</span></div></div>';
    }

    public static function menu() {
        $cap = 'manage_options';
        $parent = 'hds3_center';

        add_menu_page('Heterodyna Team Software', 'Heterodyna', $cap, $parent, [__CLASS__, 'page_center'], 'dashicons-cloud', 58);
        add_submenu_page($parent, 'Centrum', 'Centrum', $cap, $parent, [__CLASS__, 'page_center']);

        add_submenu_page($parent, 'Produkty', 'Produkty', $cap, 'hds3_products', [__CLASS__, 'page_products']);
        add_submenu_page($parent, 'Moduły', 'Moduły', $cap, 'hds3_modules', [__CLASS__, 'page_modules']);
        add_submenu_page($parent, 'Ustawienia', 'Ustawienia', $cap, 'hds3_settings', [__CLASS__, 'page_settings']);
        add_submenu_page($parent, 'Stan usług', 'Stan usług', $cap, 'hds3_health', [__CLASS__, 'page_health']);
        add_submenu_page($parent, 'Wydajność', 'Wydajność', $cap, 'hds3_performance', [__CLASS__, 'page_performance']);
        add_submenu_page($parent, 'Aktualizacje', 'Aktualizacje', $cap, 'hds3_updates', [__CLASS__, 'page_updates']);

        if (HDS3_Settings::module_enabled('tenants')) {
    add_submenu_page($parent, 'Klienci', 'Klienci', $cap, 'hds3_tenants', [__CLASS__, 'page_tenants']);
    add_submenu_page($parent, 'Tokeny', 'Tokeny', $cap, 'hds3_tokens', [__CLASS__, 'page_tokens']);
    add_submenu_page($parent, 'Provisioning', 'Provisioning', $cap, 'hds3_provisioning', [__CLASS__, 'page_provisioning']);
    if (HDS3_Settings::module_enabled('plans')) {
        add_submenu_page($parent, 'Plany i Limity', 'Plany i Limity', $cap, 'hds3_plans', [__CLASS__, 'page_plans']);
    }
}

        // Reszta pozycji jest widoczna tylko, gdy moduł jest włączony.
        // Telemetry retained in REST/storage for compatibility, not a primary navigation item.
        if (HDS3_Settings::module_enabled('licenses')) {
            add_submenu_page($parent, 'Licencje', 'Licencje', $cap, 'hds3_licenses', [__CLASS__, 'page_licenses']);
            // Widoki operacyjne: szybkie tabele „aktywne” + podział planów.
            // License list is the canonical UI; legacy active keys view kept in code.
            // Connector operations retained through existing API and legacy direct routes.
            // Instance records remain for license validation; no redundant sites menu.
        }
        if (HDS3_Settings::module_enabled('verification')) {
            add_submenu_page($parent, 'Weryfikacja', 'Weryfikacja', $cap, 'hds3_verification', [__CLASS__, 'page_verification']);
        }
        if (HDS3_Settings::module_enabled('queue')) {
            add_submenu_page($parent, 'Kolejki', 'Kolejki', $cap, 'hds3_queue', [__CLASS__, 'page_queue']);
        }
        // Register the destructive maintenance page after its parent menu exists.
        HDS3_Data_Reset::menu();

    }

    private static function header($title, $subtitle='') {
        $page = isset($_GET['page']) ? (string)$_GET['page'] : 'hds3_center';

        $items = [
            'hds3_center'      => 'Centrum',
            'hds3_connectors'  => 'Konektory',
            'hds3_licenses'    => 'Licencje',
            'hds3_instances'   => 'Witryny',
            'hds3_plans'       => 'Pakiety',
            'hds3_telemetry'   => 'Telemetria',
            'hds3_portal'      => 'Portal klienta',
            'hds3_settings'    => 'Ustawienia',
        ];

        echo '<div class="wrap">';
        // Usunięto lokalne menu-nagłówek HDS3, żeby nie dublował globalnego headera admina.

        echo '<header class="hts-page-heading"><div class="hts-page-heading-main"><span class="hts-page-eyebrow">HETERODYNA CLOUD / ZARZĄDZANIE</span>';
        echo '<h1>' . hds3_safe_html($title) . '</h1>';
        if ($subtitle) echo '<p class="description">' . wp_kses_post($subtitle) . '</p>';
        echo '</div><a class="hts-page-home" href="'.esc_url(admin_url('admin.php?page=hds3_center')).'">Przegląd platformy</a></header>';
    }


    public static function footer() {
        echo '</div>';
    }

    public static function page_center() {
        global $wpdb;

        $count = function(string $table_key, string $where = '') use ($wpdb): int {
            try {
                $table = HDS3_DB::table($table_key);
                $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
                if (!$exists) return 0;
                $sql = 'SELECT COUNT(*) FROM `' . esc_sql($table) . '`';
                if ($where !== '') $sql .= ' WHERE ' . $where;
                $value = $wpdb->get_var($sql);
                return is_numeric($value) ? (int)$value : 0;
            } catch (Throwable $e) {
                return 0;
            }
        };

        $mods = HDS3_Settings::modules();
        $mods_on = 0;
        foreach ($mods as $enabled) { if ($enabled) $mods_on++; }

        $tenant_total = 0;
        $tenant_active = 0;
        if (class_exists('HDS3_Tenants')) {
            try {
                $tc = HDS3_Tenants::counts();
                $tenant_total = (int)($tc['total'] ?? 0);
                $tenant_active = (int)($tc['active'] ?? 0);
            } catch (Throwable $e) {
                $tenant_total = $count('tenants');
                $tenant_active = $count('tenants', "status='active'");
            }
        } else {
            $tenant_total = $count('tenants');
            $tenant_active = $count('tenants', "status='active'");
        }

        $licenses_total  = $count('licenses');
        $licenses_active = $count('licenses', "status='active'");
        $jobs_queued     = $count('jobs', "status='queued'");
        $jobs_running    = $count('jobs', "status='running'");
        $jobs_failed     = $count('jobs', "status='failed'");
        $billing_unpaid  = $count('billing', "status IN ('unpaid','suspended')");
        $events_total    = $count('events');
        $instances_active = $count('instances', "status='active'");
        $billing_rows    = $count('billing');

        $site_host = function_exists('hds3_site_host') ? hds3_site_host() : (string)parse_url(home_url(), PHP_URL_HOST);
        $token_src = HDS3_Auth::token_source();
        $mode = (defined('WP_DEBUG') && WP_DEBUG) ? 'dev' : 'produkcja';
        $token_ok = HDS3_Auth::client_token() ? true : false;
        $stripe_like = get_option('hds3_stripe_secret', '') || get_option('stripe_secret_key', '') || HDS3_Settings::get('stripe_secret_key', '');
        $payments_label = $stripe_like ? 'klucz ustawiony (nie testowano)' : 'brak konfiguracji';
        $payments_state = $stripe_like ? 'ok' : 'warn';
        $cron_state = function_exists('wp_next_scheduled') && wp_next_scheduled('hds3_cron_tick') ? 'zaplanowany' : 'WP-Cron aktywny';

        $tenant_name = 'Brak organizacji';
        $tenant_id = '—';
        $tenant_status = 'nieprzypisana';
        try {
            if (class_exists('HDS3_Tenants')) {
                $rows = HDS3_Tenants::list_all(1);
                if (!empty($rows[0])) {
                    $tenant_name = (string)($rows[0]['name'] ?? $tenant_name);
                    $tenant_id = (string)($rows[0]['tenant_id'] ?? $tenant_id);
                    $tenant_status = ((string)($rows[0]['status'] ?? 'active') === 'active') ? 'aktywna' : 'wymaga uwagi';
                }
            }
        } catch (Throwable $e) {}

        $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenant_name), 0, 2));
        if ($initials === '') $initials = 'AD';

        $quick = [
            ['Dodaj klienta', hds3_admin_url('hds3_tenants'), '👥', 'blue'],
            ['Wygeneruj licencję', hds3_admin_url('hds3_licenses'), '🔑', 'green'],
            ['Plany i limity', hds3_admin_url('hds3_plans'), '▤', 'violet'],
            ['Uruchom test systemu', hds3_admin_url('hds3_health'), '⚡', 'orange'],
        ];

        echo '<div class="wrap hds3-command-wrap"><main id="hds3-main-content" class="hts-cloud-dashboard">';
        echo '<header class="hts-dashboard-heading"><div><p class="hts-overline">CENTRUM OPERACYJNE</p><h1>Przegląd platformy</h1><p>Kluczowe informacje o organizacjach i usługach w jednym miejscu.</p></div><a class="hts-primary-action" href="'.esc_url(hds3_admin_url('hds3_tenants')).'">+ Organizacje</a></header>';
        echo '<div class="hts-metrics">';
        $metrics = [
            ['Organizacje', $tenant_total, $tenant_active.' aktywnych', 'dashicons-groups'],
            ['Licencje', $licenses_total, $licenses_active.' aktywnych', 'dashicons-admin-network'],
            ['Zadania w kolejce', $jobs_queued, $jobs_failed.' nieudanych', 'dashicons-schedule'],
            ['Zaległe rozliczenia', $billing_unpaid, 'Liczba rekordów', 'dashicons-chart-bar'],
        ];
        foreach ($metrics as $m) {
            echo '<section class="hts-metric"><span class="hts-metric-top"><span>'.esc_html($m[0]).'</span><span class="dashicons '.esc_attr($m[3]).'" aria-hidden="true"></span></span><strong>'.esc_html(number_format_i18n($m[1])).'</strong><small>'.esc_html($m[2]).'</small></section>';
        }
        echo '</div>';
        echo '<div class="hts-dashboard-grid">';
        echo '<section class="hts-dashboard-panel hts-module-panel"><div class="hts-section-heading"><div><p class="hts-overline">WORKSPACE</p><h2>Zarządzanie</h2></div><span class="hts-section-tag">Podstawowe moduły</span></div>';
        $links = [
            ['Organizacje', 'Klienci i uprawnienia', 'hds3_tenants', 'dashicons-groups', 'tenants'],
            ['Licencje', 'Wydawanie i kontrola licencji', 'hds3_licenses', 'dashicons-admin-network', 'licenses'],
            ['Plany i limity', 'Konfiguracja oferty', 'hds3_plans', 'dashicons-chart-bar', 'plans'],
            ['Weryfikacja', 'Historia operacji weryfikacji', 'hds3_verification', 'dashicons-shield', 'verification'],
            ['Zadania', 'Kolejki oraz procesy', 'hds3_queue', 'dashicons-schedule', 'queue'],
            ['Diagnostyka', 'Konfiguracja i stan usług', 'hds3_health', 'dashicons-heart', ''],
        ];
        foreach ($links as $link) {
            if ($link[4] !== '' && !HDS3_Settings::module_enabled($link[4])) continue;
            echo '<a class="hts-module-link" href="'.esc_url(hds3_admin_url($link[2])).'"><span class="hts-module-icon dashicons '.esc_attr($link[3]).'" aria-hidden="true"></span><span><strong>'.esc_html($link[0]).'</strong><small>'.esc_html($link[1]).'</small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>';
        }
        echo '</section>';
        echo '<div class="hts-side-stack"><section class="hts-dashboard-panel"><div class="hts-section-heading"><div><p class="hts-overline">OPERACJE</p><h2>Stan środowiska</h2></div></div>';
        echo '<div class="hts-status-line"><span>Środowisko</span><strong>'.esc_html($mode).'</strong></div>';
        echo '<div class="hts-status-line"><span>Moduły włączone</span><strong>'.esc_html($mods_on.' / '.count($mods)).'</strong></div>';
        echo '<div class="hts-status-line"><span>Harmonogram</span><strong>'.esc_html($cron_state).'</strong></div>';
        echo '<div class="hts-status-line"><span>Płatności</span><strong>'.esc_html($payments_label).'</strong></div>';
        echo '<p class="hts-status-note">Stan z konfiguracji lokalnej; dostępność usług zewnętrznych nie została automatycznie zweryfikowana.</p><a class="hts-text-link" href="'.esc_url(hds3_admin_url('hds3_health')).'">Otwórz diagnostykę →</a></section>';
        echo '<section class="hts-dashboard-panel hts-info-panel"><p class="hts-overline">HETERODYNA TEAM SOFTWARE</p><h2>Architektura, która rośnie razem z projektem.</h2><p>Panel oparty na rzeczywistych danych WordPress. Zaawansowane narzędzia pozostają dostępne w modułach, bez przeciążania pulpitu.</p><a class="hts-text-link" href="'.esc_url(hds3_admin_url('hds3_modules')).'">Przegląd modułów →</a></section></div></div></main></div>';

    }

    
    /**
     * Tablica widżetów dla Centrum.
     * Inne wtyczki mogą dopinać własne widżety przez filtr: hds3_dashboard_widgets
     */
    private static function dashboard_widgets(): array {
        $widgets = self::default_dashboard_widgets();
        $widgets = apply_filters('hds3_dashboard_widgets', $widgets);
        $out = [];
        if (is_array($widgets)) {
            foreach ($widgets as $w) {
                if (!is_array($w)) continue;
                if (empty($w['id'])) continue;
                $out[] = $w;
            }
        }
        return $out;
    }

    private static function default_dashboard_widgets(): array {
        $widgets = [
            [
                'id' => 'hds3_status',
                'title' => 'Heterodyna — Stan usług',
                'icon' => '☁️',
                'priority' => 10,
                'actions' => [
                    ['label' => 'Stan usług', 'url' => hds3_admin_url('hds3_health')],
                    ['label' => 'Moduły', 'url' => hds3_admin_url('hds3_modules')],
                ],
                'render' => [__CLASS__, 'widget_status'],
            ],
            // TENANTS CORE
            [
                'id' => 'hds3_tenants',
                'title' => 'Klienci',
                'icon' => '👥',
                'priority' => 15,
                'actions' => [
                    ['label' => 'Klienci', 'url' => hds3_admin_url('hds3_tenants')],
                ],
                'render' => [__CLASS__, 'widget_tenants'],
            ],
            [
                'id' => 'hds3_telemetry',
                'title' => 'Telemetria',
                'icon' => '📡',
                'priority' => 20,
                'actions' => [
                    ['label' => 'Zobacz', 'url' => hds3_admin_url('hds3_telemetry')],
                ],
                'render' => [__CLASS__, 'widget_telemetry'],
            ],
            [
                'id' => 'hds3_licenses',
                'title' => 'Licencje i witryny',
                'icon' => '🔑',
                'priority' => 30,
                'actions' => [
                    ['label' => 'Licencje', 'url' => hds3_admin_url('hds3_licenses')],
                    ['label' => 'Witryny', 'url' => hds3_admin_url('hds3_instances')],
                ],
                'render' => [__CLASS__, 'widget_licenses'],
            ],
            [
                'id' => 'hds3_queue',
                'title' => 'Kolejki',
                'icon' => '🧵',
                'priority' => 40,
                'actions' => [
                    ['label' => 'Kolejki', 'url' => hds3_admin_url('hds3_queue')],
                ],
                'render' => [__CLASS__, 'widget_queue'],
            ],
            [
                'id' => 'hds3_security',
                'title' => 'Ochrona i Cybersecurity',
                'icon' => '🛡️',
                'priority' => 50,
                'actions' => [
                    ['label' => 'Cybersecurity', 'url' => admin_url('admin.php?page=hcyb')],
                    ['label' => 'Inspektor', 'url' => admin_url('admin.php?page=heterodyna_inspektor')],
                    ['label' => 'AbuseIPDB', 'url' => admin_url('admin.php?page=heterodyna_abuseipdb')],
                ],
                'render' => [__CLASS__, 'widget_security'],
            ],
        ];

        // Jeśli moduł tenantów jest wyłączony, usuń kafel.
        if (!HDS3_Settings::module_enabled('tenants')) {
            $widgets = array_values(array_filter($widgets, function($w){ return ($w['id'] ?? '') !== 'hds3_tenants'; }));
        }
        return $widgets;
    }

    private static function widget_status(): void {
        $mods = HDS3_Settings::modules();
        $on = 0;
        foreach ($mods as $v) { if ($v) $on++; }
        $src = HDS3_Auth::token_source();
        $token = HDS3_Auth::client_token();
        $mask = $token ? (substr($token, 0, 6) . '…' . substr($token, -4)) : '';
        echo '<p class="hds3-muted">Moduły: <b>'.hds3_safe_html((string)$on).'</b>/'.hds3_safe_html((string)count($mods)).' • Token: <code>'.hds3_safe_html($src ?: 'brak').'</code> '.($mask?'<code>'.hds3_safe_html($mask).'</code>':'').'</p>';

        global $wpdb;
        $counts = ['events'=>0,'licenses'=>0,'instances'=>0,'jobs'=>0];
        foreach ($counts as $k => $_) {
            $t = HDS3_DB::table($k);
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);
            if ($exists) {
                $v = $wpdb->get_var("SELECT COUNT(*) FROM $t");
                $counts[$k] = is_numeric($v) ? (int)$v : 0;
            }
        }
        echo '<div class="hds3-metrics">'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$counts['events']).'</b><span class="hds3-muted">zdarzeń</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$counts['licenses']).'</b><span class="hds3-muted">licencji</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$counts['jobs']).'</b><span class="hds3-muted">jobów</span></div>'
            . '</div>';
        echo '<p class="hds3-muted" style="margin-top:10px;">Health: <code>/wp-json/heterodyna/v3/health</code></p>';
    }

    private static function widget_tenants(): void {
        if (!HDS3_Settings::module_enabled('tenants')) {
            echo '<p class="hds3-muted">Moduł tenantów jest wyłączony.</p>';
            return;
        }
        $c = HDS3_Tenants::counts();
        echo '<p class="hds3-muted">Multi-tenant core (klienci). Tenanty są w tabelach HDS3 — bez postów i bez wp_options.</p>';
        echo '<div class="hds3-metrics">'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)($c['total'] ?? 0)).'</b><span class="hds3-muted">łącznie</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)($c['active'] ?? 0)).'</b><span class="hds3-muted">aktywni</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)($c['suspended'] ?? 0)).'</b><span class="hds3-muted">zawieszeni</span></div>'
            . '</div>';
        echo '<p class="hds3-muted" style="margin-top:10px;">Tip: tenant tworzy się automatycznie z istniejących licencji/instancji (migracja).</p>';
    }

    private static function widget_telemetry(): void {
        if (!HDS3_Settings::module_enabled('telemetry')) {
            echo '<p class="hds3-muted">Moduł telemetrii jest wyłączony.</p>';
            return;
        }
        global $wpdb;
        $t = HDS3_DB::table('events');
        $type_col = HDS3_DB::events_type_col();
        $since = gmdate('Y-m-d H:i:s', time() - 24*3600);
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE created_at >= %s", $since));
        $warn = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE created_at >= %s AND level IN ('warn','warning')", $since));
        $err = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE created_at >= %s AND level IN ('error','critical')", $since));
        echo '<p class="hds3-muted">Ostatnie 24h</p>';
        echo '<div class="hds3-metrics">'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$total).'</b><span class="hds3-muted">razem</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$warn).'</b><span class="hds3-muted">ostrzeżeń</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$err).'</b><span class="hds3-muted">błędów</span></div>'
            . '</div>';
        $last = $wpdb->get_row("SELECT site, `$type_col` AS type, level, created_at FROM $t ORDER BY id DESC LIMIT 1", ARRAY_A);
        if ($last) {
            echo '<p class="hds3-muted" style="margin-top:10px;">Ostatni: <code>'.hds3_safe_html($last['type']).'</code> • <code>'.hds3_safe_html($last['level']).'</code> • <span class="hds3-muted">'.hds3_safe_html($last['created_at']).'</span></p>';
        }
    }

    private static function widget_licenses(): void {
        if (!HDS3_Settings::module_enabled('licenses')) {
            echo '<p class="hds3-muted">Moduł licencji jest wyłączony.</p>';
            return;
        }
        global $wpdb;
        $tL = HDS3_DB::table('licenses');
        $tI = HDS3_DB::table('instances');
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tL WHERE status='active'");
        $all = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tL");
        $sites = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tI WHERE status='active'");
        echo '<div class="hds3-metrics">'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$all).'</b><span class="hds3-muted">licencji</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$active).'</b><span class="hds3-muted">aktywnych</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$sites).'</b><span class="hds3-muted">witryn</span></div>'
            . '</div>';
        echo '<p class="hds3-muted" style="margin-top:10px;">Endpointy: <code>/license/activate</code> • <code>/license/validate</code></p>';
    }

    private static function widget_queue(): void {
        if (!HDS3_Settings::module_enabled('queue')) {
            echo '<p class="hds3-muted">Moduł kolejek jest wyłączony.</p>';
            return;
        }
        global $wpdb;
        $t = HDS3_DB::table('jobs');
        $queued = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='queued'");
        $run = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='running'");
        $fail = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='failed'");
        echo '<div class="hds3-metrics">'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$queued).'</b><span class="hds3-muted">w kolejce</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$run).'</b><span class="hds3-muted">w trakcie</span></div>'
            . '<div class="hds3-metric"><b>'.hds3_safe_html((string)$fail).'</b><span class="hds3-muted">błędy</span></div>'
            . '</div>';
        echo '<p class="hds3-muted" style="margin-top:10px;">Worker: uruchom w panelu Kolejki (batch).</p>';
    }
    private static function widget_security(): void {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $items = [
            ['name' => 'Heterodyna Cybersecurity', 'file' => 'heterodyna-cybersecurity/heterodyna-cybersecurity.php', 'page' => 'hcyb', 'slug' => 'hcyb'],
            ['name' => 'Heterodyna Inspektor', 'file' => 'heterodyna-inspektor/heterodyna-inspektor.php', 'page' => 'heterodyna_inspektor', 'slug' => 'inspektor'],
            ['name' => 'Heterodyna AbuseIPDB', 'file' => 'heterodyna-abuseipdb/heterodyna-abuseipdb.php', 'page' => 'heterodyna_abuseipdb', 'slug' => 'abuseipdb'],
        ];

        echo '<p class="hds3-muted">Wtyczki ochronne (status aktywacji)</p>';
        echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Nazwa</th><th>Status</th><th>Skrót</th></tr></thead><tbody>';
        foreach ($items as $it) {
            $active = is_plugin_active($it['file']);
            $url = admin_url('admin.php?page=' . $it['page']);
            echo '<tr><td>'.hds3_safe_html($it['name']).'</td><td>'.($active?'✅ aktywna':'❌ nieaktywna').'</td><td><a href="'.hds3_safe_url($url).'">Otwórz</a></td></tr>';
        }
        echo '</tbody></table>';

        echo '<p class="hds3-muted" style="margin-top:12px;">Metryki (best effort — bez ryzyka, jeśli brak danych)</p>';
        echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Moduł</th><th>Ostatnia aktywność</th><th>Metryki</th></tr></thead><tbody>';
        foreach ($items as $it) {
            $m = self::security_metrics_best_effort($it['slug']);
            $last = !empty($m['last']) ? $m['last'] : '—';
            $pairs = [];
            if (!empty($m['pairs']) && is_array($m['pairs'])) {
                foreach ($m['pairs'] as $k => $v) {
                    $pairs[] = '<span class="hds3-muted">'.hds3_safe_html((string)$k).':</span> <b>'.hds3_safe_html((string)$v).'</b>';
                }
            }
            $pairs_html = $pairs ? implode(' &nbsp;•&nbsp; ', $pairs) : '<span class="hds3-muted">brak danych metryk</span>';
            echo '<tr><td>'.hds3_safe_html($it['name']).'</td><td><code>'.hds3_safe_html($last).'</code></td><td>'.$pairs_html.'</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p class="hds3-muted" style="margin-top:10px;">Jeśli dodasz kolejną wtyczkę SaaS/ochronną, może dopiąć własny widżet przez filtr <code>hds3_dashboard_widgets</code>.</p>';
    }

    /**
     * Best-effort integracje z wtyczkami ochronnymi.
     * Nie zakładamy nazw tabel/kluczy na 100% — próbujemy kilka bezpiecznych heurystyk.
     */
    private static function security_metrics_best_effort(string $slug): array {
        global $wpdb;
        $pairs = [];
        $last = '';

        // 1) Opcje — typowe klucze "ostatnia aktywność".
        $candidates = [];
        if ($slug === 'hcyb') {
            $candidates = ['hcyb_last_scan','hcyb_last_run','hcyb_last_check','heterodyna_cybersecurity_last_scan','heterodyna_cybersecurity_last_run'];
        } elseif ($slug === 'inspektor') {
            $candidates = ['het_inspektor_last_run','heterodyna_inspektor_last_run','inspektor_last_run','inspektor_last_scan'];
        } elseif ($slug === 'abuseipdb') {
            $candidates = ['het_abuseipdb_last_sync','heterodyna_abuseipdb_last_sync','abuseipdb_last_sync','abuseipdb_last_run'];
        }
        foreach ($candidates as $key) {
            $v = get_option($key, null);
            if ($v === null || $v === '') continue;
            $last = self::format_any_time($v);
            if ($last) break;
        }

        // 2) Tabele — jeśli istnieją oczywiste prefiksy, pokaż liczbę rekordów (max 2).
        $prefix = $wpdb->prefix;
        $like = $prefix;
        if ($slug === 'hcyb') $like .= 'hcyb_%';
        if ($slug === 'inspektor') $like .= 'inspektor_%';
        if ($slug === 'abuseipdb') $like .= 'abuse%';

        $tables = [];
        try {
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        } catch (Throwable $e) {
            $tables = [];
        }

        $picked = [];
        if (is_array($tables)) {
            foreach ($tables as $t) {
                if (count($picked) >= 2) break;
                $picked[] = $t;
            }
        }
        foreach ($picked as $t) {
            $cnt = '—';
            try {
                $cntv = $wpdb->get_var("SELECT COUNT(*) FROM `$t`");
                $cnt = is_numeric($cntv) ? (string)$cntv : '—';
            } catch (Throwable $e) {
                $cnt = '—';
            }
            $short = str_replace($prefix, '', $t);
            $pairs[$short] = $cnt;
        }

        // 3) Fallback: ostatni event HDS3 powiązany z typem (jeśli wysyłacie eventy tym slugiem).
        if (!$last) {
            try {
                $t = HDS3_DB::table('events');
                $type_col = HDS3_DB::events_type_col();
                $row = $wpdb->get_row($wpdb->prepare("SELECT created_at FROM $t WHERE `$type_col` LIKE %s ORDER BY id DESC LIMIT 1", $slug.'%'));
                if ($row && !empty($row->created_at)) {
                    $last = (string)$row->created_at;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        return ['last' => $last ?: '', 'pairs' => $pairs];
    }

    private static function format_any_time($v): string {
        if (is_numeric($v)) {
            $ts = (int)$v;
            if ($ts > 1000000000) return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
        }
        if (is_string($v)) {
            $s = trim($v);
            $t = strtotime($s);
            if ($t && $t > 1000000000) return gmdate('Y-m-d H:i:s', $t) . ' UTC';
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return $s;
        }
        return '';
    }

public static function page_health() {
        self::header('Stan usług', 'Szybka diagnostyka: baza, tabele, token, REST, kolejki. Bez zgadywania.');

        $src = HDS3_Auth::token_source();
        $token = HDS3_Auth::client_token();
        $mask = $token ? (substr($token, 0, 6) . '…' . substr($token, -4)) : '';

        echo '<h2>Autoryzacja</h2>';
        echo '<p><b>Źródło tokenu:</b> <code>' . hds3_safe_html($src ?: 'brak') . '</code><br>';
        echo '<b>Token:</b> ' . ($token ? '<code>' . hds3_safe_html($mask) . '</code> <span class="description">(zamaskowane)</span>' : '<span class="description">brak — ustaw w Ustawieniach lub wp-config</span>') . '</p>';

        echo '<h2>Baza danych</h2>';
        $tables = [
            'settings' => 'Ustawienia',
            'events' => 'Telemetria',
            'licenses' => 'Licencje',
            'instances' => 'Witryny',
            'verification' => 'Weryfikacja',
            'verification_steps' => 'Weryfikacja — kroki',
            'jobs' => 'Kolejki',
            'job_runs' => 'Kolejki — wykonania',
        ];
        global $wpdb;
        echo '<table class="widefat striped"><thead><tr><th>Tabela</th><th>Opis</th><th>Istnieje</th><th>Rekordy</th></tr></thead><tbody>';
        foreach ($tables as $k => $label) {
            $t = HDS3_DB::table($k);
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);
            $cnt = '—';
            if ($exists) {
                $cntv = $wpdb->get_var("SELECT COUNT(*) FROM $t");
                $cnt = is_numeric($cntv) ? (string)$cntv : '—';
            }
            echo '<tr><td><code>' . hds3_safe_html($t) . '</code></td><td>' . hds3_safe_html($label) . '</td><td>' . ($exists ? '✅' : '❌') . '</td><td>' . hds3_safe_html($cnt) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>REST</h2>';
        echo '<p><b>Health (public):</b> <code>/wp-json/heterodyna/v3/health</code><br>';
        echo '<b>Health (pełny, Bearer):</b> dodaj nagłówek Authorization. Wtedy dostaniesz więcej szczegółów.</p>';

        echo '<h2>Moduły (wg bazy)</h2>';
        $mods = HDS3_Settings::modules();
        echo '<ul>';
        foreach ($mods as $k=>$v) {
            echo '<li><code>' . hds3_safe_html($k) . '</code>: ' . ($v ? '✅' : '❌') . '</li>';
        }
        echo '</ul>';

        self::footer();
    }

    
    public static function save_modules() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }
        if (empty($_POST['hds3_modules_nonce']) || !wp_verify_nonce($_POST['hds3_modules_nonce'], 'hds3_modules_save')) {
            wp_safe_redirect(hds3_admin_url('hds3_modules') . '&hds3_msg=modules_failed');
            exit;
        }

        $known = self::known_modules();
        foreach ($known as $k => $label) {
            $en = !empty($_POST['mod_'.$k]) ? 1 : 0;
            HDS3_Settings::set_module($k, $en);
        }

        // Wymuś odświeżenie stanów (cache/transienty nie powinny tu istnieć, ale lepiej być odpornym).
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }
        wp_safe_redirect(hds3_admin_url('hds3_modules') . '&hds3_msg=modules_saved');
        exit;
    }


    public static function widget_billing() {
        if (!HDS3_Settings::module_enabled('billing')) {
            echo '<div class="hds3-muted">Moduł billing jest wyłączony.</div>';
            return;
        }
        if (!class_exists('HDS3_Billing') || !class_exists('HDS3_Tenants')) {
            echo '<div class="hds3-muted">(brak danych)</div>';
            return;
        }
        $tenants = HDS3_Tenants::list_all();
        $total = is_array($tenants) ? count($tenants) : 0;
        $active = 0; $blocked = 0; $trial = 0;
        if (is_array($tenants)) {
            foreach ($tenants as $t) {
                $tid = (string)($t['tenant_id'] ?? '');
                if (!$tid) continue;
                $st = HDS3_Billing::status($tid);
                if ($st === 'active') $active++;
                elseif ($st === 'trial') $trial++;
                else $blocked++;
            }
        }
        echo '<div class="hds3-muted">Statusy billing per tenant.</div>';
        echo '<div class="hds3-metrics">';
        echo '<div class="hds3-metric"><b>'.hds3_safe_html($total).'</b><span class="hds3-muted">tenantów</span></div>';
        echo '<div class="hds3-metric"><b>'.hds3_safe_html($active).'</b><span class="hds3-muted">active</span></div>';
        echo '<div class="hds3-metric"><b>'.hds3_safe_html($trial).'</b><span class="hds3-muted">trial</span></div>';
        echo '</div>';
        if ($blocked > 0) {
            echo '<p style="margin:8px 0 0" class="hds3-muted">Zablokowane (unpaid/suspended): <b>'.hds3_safe_html($blocked).'</b></p>';
        }
    }
public static function page_modules() {
        self::header('Moduły', 'Włącz/wyłącz elementy bez rozpinania kodu. Źródłem prawdy są klucze <code>module_*</code> w tabeli <code>wp_hds3_settings</code>.');

        if (!empty($_GET['hds3_msg']) && $_GET['hds3_msg'] === 'modules_saved') {
            echo '<div class="updated notice"><p>Zapisano.</p></div>';
        }
        if (!empty($_GET['hds3_msg']) && $_GET['hds3_msg'] === 'modules_failed') {
            echo '<div class="error notice"><p>Nie udało się zapisać modułów (nonce / uprawnienia). Odśwież stronę i spróbuj ponownie.</p></div>';
        }

        $mods = HDS3_Settings::modules();

        // Formularz ma fallback do admin-post, ale zapis domyślnie idzie REST (stabilniej na hostingach).
        $rest_url = esc_url_raw(rest_url('heterodyna/v3/admin/modules/save'));
        $rest_nonce = wp_create_nonce('wp_rest');

        echo '<form id="hds3-modules-form" method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '" data-rest-url="' . hds3_safe_attr($rest_url) . '">';
        echo '<input type="hidden" name="action" value="hds3_save_modules">';
        wp_nonce_field('hds3_modules_save','hds3_modules_nonce');
        echo '<input type="hidden" id="hds3-rest-nonce" value="' . hds3_safe_attr($rest_nonce) . '">';

        echo '<table class="widefat striped"><thead><tr><th>Moduł</th><th>Włączony</th></tr></thead><tbody>';
        foreach (self::known_modules() as $k => $label) {
            $checked = !empty($mods[$k]) ? 'checked' : '';
            echo '<tr><td><b>' . hds3_safe_html($label) . '</b><div class="description">'.hds3_safe_html($k).'</div></td><td><label><input type="checkbox" name="mod_'.$k.'" value="1" '.$checked.'> włącz</label></td></tr>';
        }
        echo '</tbody></table><p><button id="hds3-modules-save" class="button button-primary">Zapisz</button> <span id="hds3-modules-status" class="description" style="margin-left:8px;"></span></p></form>';

        // REST-first zapis (bez redirectów). Jeśli fetch się nie uda, działa fallback admin-post.
        echo '<script>(function(){\n'
            . 'var f=document.getElementById("hds3-modules-form"); if(!f) return;\n'
            . 'var st=document.getElementById("hds3-modules-status");\n'
            . 'f.addEventListener("submit", function(ev){\n'
            . '  // jeśli REST już próbowaliśmy i nie wyszło, pozwól na normalny submit\n'
            . '  if(f.dataset && f.dataset.restTried==="1"){ return; }\n'
            . '  try{\n'
            . '    ev.preventDefault();\n'
            . '    if(st) st.textContent="Zapisywanie…";\n'
            . '    var url=f.getAttribute("data-rest-url");\n'
            . '    var nonce=document.getElementById("hds3-rest-nonce").value;\n'
            . '    var modules={};\n'
            . '    var inputs=f.querySelectorAll("input[type=checkbox][name^=mod_]");\n'
            . '    inputs.forEach(function(i){ var k=i.name.replace("mod_", ""); modules[k]=i.checked?1:0; });\n'
            . '    fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify({modules:modules})})\n'
            . '      .then(function(r){return r.json().then(function(j){return {ok:r.ok, status:r.status, json:j};});})\n'
            . '      .then(function(res){\n'
            . '        if(!res.ok || !res.json || !res.json.ok){ throw res.json && res.json.error ? new Error(res.json.error) : new Error("Błąd zapisu ("+res.status+")"); }\n'
            . '        if(st) st.textContent="Zapisano.";\n'
            . '        window.location.href=window.location.pathname+window.location.search.replace(/([&?])hds3_msg=[^&]*/g,"$1").replace(/[?&]$/g,"")+ (window.location.search.indexOf("?")===-1?"?":"&") + "hds3_msg=modules_saved";\n'
            . '      })\n'
            . '      .catch(function(e){\n'
            . '        if(st) st.textContent="REST nie zadziałał — próbuję fallback…";\n'
            . '        if(f.dataset) f.dataset.restTried="1";\n'
            . '        f.submit();\n'
            . '      });\n'
            . '  }catch(e){\n'
            . '    if(st) st.textContent="Błąd: "+e.message;\n'
            . '  }\n'
            . '});\n'
            . '})();</script>';

        // Diagnostyka: pokaż surowe wartości z bazy (bez zgadywania).
        echo '<hr><h2>Diagnostyka zapisu</h2>';
        echo '<p><b>Tabela settings:</b> <code>' . hds3_safe_html(HDS3_DB::table('settings')) . '</code></p>';

        $known = array_keys(self::known_modules());
        echo '<table class="widefat striped"><thead><tr><th>Klucz</th><th>Wartość</th></tr></thead><tbody>';
        foreach ($known as $k) {
            $skey = 'module_' . $k;
            $val = HDS3_Settings::get($skey, '(brak)');
            echo '<tr><td><code>' . hds3_safe_html($skey) . '</code></td><td><code>' . hds3_safe_html(is_string($val)?$val: hds3_json_encode($val)) . '</code></td></tr>';
        }
        $legacy = HDS3_Settings::get('modules_enabled', '(brak)');
        echo '<tr><td><code>modules_enabled</code> (legacy)</td><td><code>' . hds3_safe_html(is_string($legacy)?$legacy:'') . '</code></td></tr>';
        echo '</tbody></table>';

        self::footer();
    }

    private static function known_modules() {
        return [
            'tenants' => 'Klienci (Tenants)',
            'telemetry' => 'Telemetria',
            'licenses' => 'Licencje',
            'verification' => 'Weryfikacja',
            'queue' => 'Kolejki',
            'cleanup' => 'Sprzątanie',
            'consolidation' => 'Konsolidacja',
        ];
    }

    public static function page_settings() {
        self::header('Ustawienia platformy', 'Konfiguracja dostępu API, ochrony oraz integracji systemowych.');
        echo '<div class="hts-settings-intro"><div><span class="hts-eyebrow">KONFIGURACJA</span><h2>Ustawienia Heterodyna Cloud</h2><p>Zmiany konfiguracji mają wpływ na działanie usług. Dane uwierzytelniające przechowuj najlepiej poza bazą danych, w <code>wp-config.php</code>.</p></div><div class="hts-settings-links"><a href="'.esc_url(admin_url('admin.php?page=hds3_health')).'">Stan usług</a><a href="'.esc_url(admin_url('admin.php?page=hds3_modules')).'">Moduły</a><a href="'.esc_url(admin_url('admin.php?page=hds3_center')).'">Pulpit</a></div></div>';

        if (!empty($_GET['hds3_msg']) && $_GET['hds3_msg'] === 'token_generated') {
            echo '<div class="updated notice"><p>Wygenerowano nowy token w bazie danych.</p></div>';
        }

        if (!empty($_GET['hds3_msg']) && $_GET['hds3_msg'] === 'token_failed') {
            echo '<div class="error notice"><p>Nie udało się wygenerować tokenu (brak uprawnień lub błąd nonce).</p></div>';
        }

        if (!empty($_POST['hds3_settings_nonce']) && current_user_can('manage_options') && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hds3_settings_nonce'])), 'hds3_settings_save')) {
            HDS3_Settings::set('client_token', sanitize_text_field($_POST['client_token'] ?? ''));
            HDS3_Settings::set('gatekeeper_enabled', !empty($_POST['gatekeeper_enabled']) ? '1' : '0');
            $scope = in_array(($_POST['gatekeeper_scope'] ?? 'admin'), ['admin','front','both'], true) ? $_POST['gatekeeper_scope'] : 'admin';
            HDS3_Settings::set('gatekeeper_scope', $scope);
            $redir = esc_url_raw($_POST['gatekeeper_redirect'] ?? wp_login_url());
            HDS3_Settings::set('gatekeeper_redirect', $redir);
            HDS3_Settings::set('la_url', esc_url_raw($_POST['la_url'] ?? ''));
            HDS3_Settings::set('la_ping_path', sanitize_text_field($_POST['la_ping_path'] ?? '/ping'));

            echo '<div class="updated notice"><p>Zapisano.</p></div>';
        }

        $token = HDS3_Settings::get('client_token','');
        $src = HDS3_Auth::token_source();
        $effective = HDS3_Auth::client_token();
        $effective_mask = $effective ? (substr($effective, 0, 6) . '…' . substr($effective, -4)) : '';
        $gk = HDS3_Settings::get('gatekeeper_enabled','0');
        $scope = HDS3_Settings::get('gatekeeper_scope','admin');
        $redir = HDS3_Settings::get('gatekeeper_redirect', wp_login_url());
        $la_url = HDS3_Settings::get('la_url','');
        $la_ping = HDS3_Settings::get('la_ping_path','/ping');
        $la_test = get_transient('hds3_la_test');

        echo '<form method="post" class="hts-settings-form">';
        wp_nonce_field('hds3_settings_save','hds3_settings_nonce');
        echo '<table class="form-table"><tbody>';

        echo '<tr><th>Token aktywny</th><td>';
        if ($src === 'wp-config') {
            echo '<b>Źródło:</b> <code>wp-config.php</code> (HDS3_CLIENT_TOKEN)<br>';
            echo '<b>Wartość:</b> <code>'.hds3_safe_html($effective_mask).'</code> <span class="description">(zamaskowane)</span><br>';
            echo '<p class="description">Token z wp-config ma priorytet. Pole poniżej nie nadpisze go, dopóki stała jest ustawiona.</p>';
        } elseif ($src === 'baza') {
            echo '<b>Źródło:</b> <code>baza danych</code><br>';
            echo '<b>Wartość:</b> <code>'.hds3_safe_html($effective_mask).'</code> <span class="description">(zamaskowane)</span>';
        } else {
            echo '<b>Źródło:</b> brak<br><span class="description">Ustaw token w bazie lub w wp-config.</span>';
        }
        echo '</td></tr>';

        echo '<tr><th>Client Token (Bearer)</th><td>';
        echo '<input type="text" class="regular-text" name="client_token" value="'.hds3_safe_attr($token).'">';
        echo '<p class="description">Używany przez endpointy REST (telemetria/licencje/weryfikacja). Zalecane: trzymaj token w <code>wp-config.php</code>.</p>';
        echo '<p>';
        echo '<a class="button" href="'.hds3_safe_url(self::generate_token_url()).'">Wygeneruj bezpieczny token</a> ';
        echo '<span class="description">Wygeneruje nowy token i zapisze w bazie danych.</span>';
        echo '</p>';
        echo '</td></tr>';

        echo '<tr><th>Gatekeeper</th><td><label><input type="checkbox" name="gatekeeper_enabled" value="1" '.checked('1',$gk,false).'> włącz</label></td></tr>';
        echo '<tr><th>Zakres</th><td><select name="gatekeeper_scope">';
        foreach (['admin'=>'wp-admin','front'=>'front','both'=>'oba'] as $k=>$lbl) {
            echo '<option value="'.hds3_safe_attr($k).'" '.selected($scope,$k,false).'>'.hds3_safe_html($lbl).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Redirect</th><td><input type="text" class="regular-text" name="gatekeeper_redirect" value="'.hds3_safe_attr($redir).'"><p class="description">Gdzie przekierować nieweryfikowanych.</p></td></tr>';

        echo '</tbody></table><p><button class="button button-primary">Zapisz</button></p></form>';

        echo '<hr/><h2>Import / kompatybilność PPV</h2>';
        echo '<p>Jeśli masz legacy <code>portal-verification</code> (PPV), możesz spróbować zaciągnąć statusy.</p>';
        echo '<form class="hts-settings-legacy" method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_ppv_import">';
        wp_nonce_field('hds3_ppv_import','hds3_ppv_import_nonce');
        echo '<button class="button">Importuj statusy z legacy PPV</button></form>';

        self::footer();
    }

    private static function generate_token_url() {
        return wp_nonce_url(admin_url('admin-post.php?action=hds3_generate_token'), 'hds3_generate_token', 'hds3_generate_token_nonce');
    }

    public static function generate_token() {
        if (!current_user_can('manage_options')) {
            wp_safe_redirect(add_query_arg('hds3_msg', 'token_failed', hds3_admin_url('hds3_settings')));
            exit;
        }
        $ok = !empty($_GET['hds3_generate_token_nonce']) && wp_verify_nonce($_GET['hds3_generate_token_nonce'], 'hds3_generate_token');
        if (!$ok) {
            wp_safe_redirect(add_query_arg('hds3_msg', 'token_failed', hds3_admin_url('hds3_settings')));
            exit;
        }

        // 256-bit token (hex)
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = wp_generate_password(64, false, false);
        }
        HDS3_Settings::set('client_token', $token);
        wp_safe_redirect(add_query_arg('hds3_msg', 'token_generated', hds3_admin_url('hds3_settings')));
        exit;
    }

    public static function page_telemetry() {
        if (!HDS3_Settings::module_enabled('telemetry')) {
            self::header('Telemetria', 'Moduł wyłączony.');
            self::footer(); return;
        }
        self::header('Telemetria', 'Zdarzenia HDS3 (ostatnie 200).');
        global $wpdb;
        $t = HDS3_DB::table('events');
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 200", ARRAY_A);

        echo '<div class="hds3-ui">';
        echo '<p><a class="button" href="'.hds3_safe_url(admin_url('admin-post.php?action=hds3_export_events')).'">Eksport CSV</a></p>';
        echo '<div class="hds3-scroll-x">';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Data</th><th>Site</th><th>Typ</th><th>Poziom</th><th>Wiadomość</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>'.hds3_safe_html($r['id']).'</td><td>'.hds3_safe_html($r['created_at']).'</td><td>'.hds3_safe_html($r['site']).'</td><td>'.hds3_safe_html($r['type']).'</td><td>'.hds3_safe_html($r['level']).'</td><td>'.hds3_safe_html($r['message']).'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6">Brak zdarzeń.</td></tr>';
        echo '</tbody></table>';
        echo '</div></div>';
        self::footer();
    }

    public static function export_events() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!HDS3_Settings::module_enabled('telemetry')) wp_die('Moduł telemetrii jest wyłączony.');
        global $wpdb;
        $t = HDS3_DB::table('events');
        $rows = $wpdb->get_results("SELECT id,created_at,site,`type`,level,message FROM $t ORDER BY id DESC LIMIT 5000", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=hds3-events.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','created_at','site','type','level','message']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['created_at'],$r['site'],$r['type'],$r['level'],$r['message']]);
        }
        fclose($out);
        exit;
    }

    public static function page_licenses() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        if (!HDS3_Settings::module_enabled('licenses')) {
            self::header('Licencje', 'Moduł wyłączony.'); self::footer(); return;
        }
        self::header('Licencje', 'Wydawanie licencji dla produktów utworzonych przez administratora.');
        global $wpdb;
        $catalog = self::product_catalog();
        $active_products = [];
        foreach ($catalog as $code => $item) {
            if (($item['status'] ?? 'active') === 'active') $active_products[$code] = $item;
        }
        $tenants = HDS3_Tenants::list_all(1000);
        $tenant_ids = [];
        foreach ($tenants as $tenant_row) {
            if (isset($tenant_row['tenant_id'])) $tenant_ids[(string)$tenant_row['tenant_id']] = $tenant_row;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hds3_lic_create_submit'])) {
            if (!isset($_POST['hds3_lic_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hds3_lic_nonce'])), 'hds3_lic_create')) {
                echo '<div class="notice notice-error"><p>Sesja formularza wygasła. Odśwież stronę.</p></div>';
            } else {
                $tenant = sanitize_text_field(wp_unslash($_POST['tenant_id'] ?? ''));
                $product = strtoupper(sanitize_key(wp_unslash($_POST['product'] ?? '')));
                if (!isset($tenant_ids[$tenant]) || !isset($active_products[$product])) {
                    echo '<div class="notice notice-error"><p>Wybierz istniejącą organizację i aktywny produkt z katalogu.</p></div>';
                } else {
                    $t = HDS3_DB::table('licenses'); $now = hds3_now_gmt(); $cols = HDS3_DB::cols($t);
                    $insert_ok = false;
                    for ($i=0; $i<5; $i++) {
                        $key = hds3_generate_license_key($product, 24);
                        $data = ['tenant_id'=>$tenant,'license_key'=>$key,'product'=>$product,'status'=>'active','meta'=>null,'created_at'=>$now,'updated_at'=>$now];
                        if (in_array('klucz',$cols,true)) $data['klucz']=$key;
                        if (in_array('produkt',$cols,true)) $data['produkt']=$product;
                        $data = array_filter($data, static function($k) use($cols) { return !$cols || in_array($k,$cols,true); }, ARRAY_FILTER_USE_KEY);
                        if ($wpdb->insert($t,$data)) { $insert_ok=true; break; }
                        if (stripos((string)$wpdb->last_error,'duplicate')===false) break;
                    }
                    if ($insert_ok) {
                        echo '<div class="notice notice-success"><p>Licencja została utworzona. Klucz: <code>'.esc_html($key).'</code></p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Nie udało się zapisać licencji. Sprawdź dziennik bazy danych.</p></div>';
                    }
                }
            }
        }
        echo '<div class="hds3-card"><h2>Nowa licencja</h2>';
        if (!$active_products) echo '<p>Brak aktywnych produktów. <a href="'.esc_url(hds3_admin_url('hds3_products')).'">Dodaj produkt w katalogu</a>, aby tworzyć nowe licencje.</p>';
        if (!$tenant_ids) echo '<p>Brak organizacji. Utwórz organizację przed wydaniem licencji.</p>';
        if ($active_products && $tenant_ids) {
            echo '<form method="post">'; wp_nonce_field('hds3_lic_create','hds3_lic_nonce');
            echo '<p><label for="hds3_license_tenant">Organizacja</label><br><select id="hds3_license_tenant" name="tenant_id" required><option value="">Wybierz organizację</option>';
            foreach ($tenant_ids as $id=>$row) echo '<option value="'.esc_attr($id).'">'.esc_html(($row['name'] ?? $id).' ('.$id.')').'</option>';
            echo '</select></p><p><label for="hds3_license_product">Produkt</label><br><select id="hds3_license_product" name="product" required><option value="">Wybierz produkt</option>';
            foreach ($active_products as $code=>$item) echo '<option value="'.esc_attr($code).'">'.esc_html(($item['name'] ?? $code).' ('.$code.')').'</option>';
            echo '</select></p><p><button type="submit" name="hds3_lic_create_submit" value="1" class="button button-primary">Utwórz licencję</button></p></form>';
        }
        echo '</div>';
        $t = HDS3_DB::table('licenses');
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 200", ARRAY_A);
        echo '<h2>Ostatnie licencje (do 200)</h2><div class="hds3-scroll-x"><table class="widefat striped"><thead><tr><th>ID</th><th>Organizacja</th><th>Klucz</th><th>Produkt</th><th>Status</th><th>Aktualizacja</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>'.esc_html($r['id'] ?? '').'</td><td>'.esc_html($r['tenant_id'] ?? '').'</td><td><code>'.esc_html($r['license_key'] ?? '').'</code></td><td>'.esc_html($r['product'] ?? '').'</td><td>'.esc_html($r['status'] ?? '').'</td><td>'.esc_html($r['updated_at'] ?? '').'</td></tr>';
        if (!$rows) echo '<tr><td colspan="6">Brak licencji.</td></tr>';
        echo '</tbody></table></div>';
        echo '<p class="description">Istniejące klucze zachowują poprzednie formaty. Nowy formularz wymaga produktu z katalogu. Pozostałe, starsze integracje licencyjne będą migrowane w kolejnych wydaniach.</p>';
        self::footer();
    }

    /**
     * Aktywne klucze (SEO / AGENCY / CONNECTOR) z podziałem na produkt i plan (meta).
     * Wymaganie: „kilka tabel” + podział na plany + telemetria jak w tabeli istniejącej.
     * Telemetrię bierzemy z: instances.last_seen_at + ostatnie zdarzenia z events (best-effort).
     */
    public static function page_active_keys() {
        if (!HDS3_Settings::module_enabled('licenses')) {
            self::header('Aktywne klucze', 'Moduł licencji jest wyłączony.');
            self::footer();
            return;
        }
        self::header('Aktywne klucze', 'Wszystkie aktywne klucze z podziałem na produkt i plan.');

        global $wpdb;
        $tLic = HDS3_DB::table('licenses');
        $tIns = HDS3_DB::table('instances');
        $tEvt = HDS3_DB::table('events');

        // Ostatni event per site (best effort, ostatnie 5000 rekordów)
        $lastEvt = [];
        if (HDS3_Settings::module_enabled('telemetry')) {
            try {
                $rowsEvt = $wpdb->get_results("SELECT site, MAX(id) AS max_id FROM $tEvt GROUP BY site ORDER BY max_id DESC LIMIT 2000", ARRAY_A);
                $ids = [];
                foreach ((array)$rowsEvt as $r) {
                    if (!empty($r['max_id'])) $ids[] = (int)$r['max_id'];
                }
                if ($ids) {
                    $in = implode(',', array_map('intval', array_slice($ids, 0, 2000)));
                    $rowsEvt2 = $wpdb->get_results("SELECT id,site,created_at,`type`,level,message FROM $tEvt WHERE id IN ($in)", ARRAY_A);
                    foreach ((array)$rowsEvt2 as $r) {
                        $site = (string)($r['site'] ?? '');
                        if ($site === '') continue;
                        $lastEvt[$site] = $r;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        $products = ['SEO','AGENCY_SEO','SEO_CONNECTOR'];
        foreach ($products as $p) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT l.*, i.last_seen_at AS instance_last_seen, i.site AS instance_site\n                 FROM $tLic l\n                 LEFT JOIN $tIns i ON i.tenant_id = l.tenant_id AND i.product = l.product AND i.status = 'active'\n                 WHERE l.status = 'active' AND l.product = %s\n                 ORDER BY l.id DESC LIMIT 500",
                $p
            ), ARRAY_A);

            $title = $p;
            if ($p === 'SEO') $title = 'SEO (klienci)';
            if ($p === 'AGENCY_SEO') $title = 'AGENCJA (konto)';
            if ($p === 'SEO_CONNECTOR') $title = 'KONEKTORY (1 domena)';

            echo '<h2 style="margin-top:18px">'.hds3_safe_html($title).'</h2>';
            echo '<table class="widefat striped"><thead><tr>'
                .'<th>ID</th><th>tenant</th><th>license_key</th><th>Plan (meta)</th><th>Meta (skrót)</th>'
                .'<th>Site</th><th>Last seen</th>'
                .'<th>Ostatni event</th><th>Typ</th><th>Poziom</th><th>Wiadomosc</th><th>Akcje</th>'
                .'</tr></thead><tbody>';

            foreach ((array)$rows as $r) {
                $meta = self::meta_decode($r['meta'] ?? null);
                $plan = (string)($meta['plan_code'] ?? $meta['plan'] ?? '');
                $metaShort = self::meta_short($meta);
                $site = (string)($r['instance_site'] ?? '');
                if ($site === '' && !empty($meta['domain'])) $site = (string)$meta['domain'];
                $lastSeen = (string)($r['instance_last_seen'] ?? '');

                $evt = ($site && isset($lastEvt[$site])) ? $lastEvt[$site] : null;
                $evtAt = $evt ? (string)($evt['created_at'] ?? '') : '';
                $evtType = $evt ? (string)($evt['type'] ?? '') : '';
                $evtLvl = $evt ? (string)($evt['level'] ?? '') : '';
                $evtMsg = $evt ? (string)($evt['message'] ?? '') : '';

                echo '<tr>';
                echo '<td>'.hds3_safe_html($r['id']).'</td>';
                echo '<td>'.hds3_safe_html($r['tenant_id']).'</td>';
                echo '<td><code>'.hds3_safe_html($r['license_key']).'</code></td>';
                echo '<td>'.hds3_safe_html($plan ?: '—').'</td>';
                echo '<td class="hds3-muted">'.hds3_safe_html($metaShort).'</td>';
                echo '<td>'.hds3_safe_html($site ?: '—').'</td>';
                echo '<td>'.hds3_safe_html($lastSeen ?: '—').'</td>';
                echo '<td>'.hds3_safe_html($evtAt ?: '—').'</td>';
                echo '<td>'.hds3_safe_html($evtType ?: '—').'</td>';
                echo '<td>'.hds3_safe_html($evtLvl ?: '—').'</td>';
                echo '<td>'.hds3_safe_html($evtMsg ? mb_substr($evtMsg, 0, 120) : '—').'</td>';

                echo '<td>';
                if (current_user_can('manage_options')) {
                    echo '<form method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0 6px 0 0">';
                    echo '<input type="hidden" name="action" value="hds3_license_revoke" />';
                    echo '<input type="hidden" name="license_key" value="'.hds3_safe_attr($r['license_key']).'" />';
                    wp_nonce_field('hds3_license_revoke','hds3_license_revoke_nonce');
                    echo '<button class="button" onclick="return confirm(\"Revoke (soft) tej licencji?\")">Revoke</button>';
                    echo '</form>';

                    echo '<form method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0">';
                    echo '<input type="hidden" name="action" value="hds3_license_delete" />';
                    echo '<input type="hidden" name="license_key" value="'.hds3_safe_attr($r['license_key']).'" />';
                    wp_nonce_field('hds3_license_delete','hds3_license_delete_nonce');
                    echo '<button class="button button-link-delete" onclick="return confirm(\"HARD DELETE: usunac licencje?\")">Usun</button>';
                    echo '</form>';

            echo '<form method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0 0 0 6px">';
            echo '<input type="hidden" name="action" value="hds3_tenant_vault_delete" />';
            echo '<input type="hidden" name="tenant_id" value="'.hds3_safe_attr($tid).'" />';
            wp_nonce_field('hds3_tenant_vault_delete');
            echo '<button class="button" onclick="return confirm(\"Usunac folder vault dla tego klienta?\")">Vault</button>';
            echo '</form>';
                } else {
                    echo '—';
                }
                echo '</td>';

                echo '</tr>';
            }

            if (empty($rows)) {
                echo '<tr><td colspan="12">Brak aktywnych rekordow.</td></tr>';
            }
            echo '</tbody></table>';

            // Podział na plany (liczymy po meta.plan_code)
            $counts = [];
            foreach ((array)$rows as $r) {
                $meta = self::meta_decode($r['meta'] ?? null);
                $plan = (string)($meta['plan_code'] ?? $meta['plan'] ?? '');
                if ($plan === '') $plan = '—';
                $counts[$plan] = ($counts[$plan] ?? 0) + 1;
            }
            if ($counts) {
                arsort($counts);
                echo '<p class="description"><b>Podział (plan → liczba):</b> ';
                $parts = [];
                foreach ($counts as $k=>$v) $parts[] = hds3_safe_html($k).': <b>'.hds3_safe_html((string)$v).'</b>';
                echo implode(' · ', $parts);
                echo '</p>';
            }
        }

        self::footer();
    }

    /**
     * Dedykowana tabela konektorów (SEO_CONNECTOR) z domeną, statusem i telemetrią.
     */
    public static function page_connectors() {
        if (!HDS3_Settings::module_enabled('licenses')) {
            self::header('Konektory', 'Moduł licencji jest wyłączony.');
            self::footer();
            return;
        }
        self::header('Konektory', 'Lista konektorów agencji (SEO_CONNECTOR) + domeny + telemetria + folder vault.');

        global $wpdb;
        $tCon = HDS3_DB::table('agency_connectors');
        $tEvt = HDS3_DB::table('events');

        $rows = [];
        try {
            $rows = $wpdb->get_results("SELECT * FROM $tCon ORDER BY id DESC LIMIT 2000", ARRAY_A);
        } catch (Throwable $e) {
            $rows = [];
        }

        // last event per site (domain) – best effort
        $lastEvt = [];
        if (HDS3_Settings::module_enabled('telemetry')) {
            try {
                $rowsEvt = $wpdb->get_results("SELECT site, MAX(id) AS max_id FROM $tEvt GROUP BY site ORDER BY max_id DESC LIMIT 3000", ARRAY_A);
                $ids = [];
                foreach ((array)$rowsEvt as $r) if (!empty($r['max_id'])) $ids[] = (int)$r['max_id'];
                if ($ids) {
                    $in = implode(',', array_map('intval', array_slice($ids, 0, 3000)));
                    $rowsEvt2 = $wpdb->get_results("SELECT id,site,created_at,`type`,level,message FROM $tEvt WHERE id IN ($in)", ARRAY_A);
                    foreach ((array)$rowsEvt2 as $r) {
                        $site = (string)($r['site'] ?? '');
                        if ($site) $lastEvt[$site] = $r;
                    }
                }
            } catch (Throwable $e) {}
        }

                $limits_cache = [];

echo '<p class="description">Operacje: <b>Usuń</b> kasuje rekord konektora + subklucz/subtoken (dla porządków po testach). To jest destrukcyjne.</p>';
        echo '<table class="widefat striped"><thead><tr>'
            .'<th>ID</th><th>Parent</th><th>Limity</th><th>Subkey</th><th>Domena</th><th>Plan</th><th>Status</th><th>Last seen</th><th>Vault</th'
            .'<th>Ostatni event</th><th>Typ</th><th>Poziom</th><th>Wiadomość</th><th>Akcje</th>'
            .'</tr></thead><tbody>';

        foreach ((array)$rows as $r) {
            $meta = self::meta_decode($r['meta'] ?? null);
            $domain = (string)($r['domain'] ?? '');
            $plan = (string)($r['plan_code'] ?? ($meta['plan_code'] ?? $meta['plan'] ?? ''));
            $status = (string)($r['status'] ?? '');
            $lastSeen = (string)($r['last_seen_at'] ?? '');
            $parent = (string)($r['parent_license_key'] ?? '');
            $subkey = (string)($r['subkey'] ?? '');
            $vault = (string)($r['vault_path'] ?? '');

            $evt = ($domain && isset($lastEvt[$domain])) ? $lastEvt[$domain] : null;
            $evtAt = $evt ? (string)($evt['created_at'] ?? '') : '';
            $evtType = $evt ? (string)($evt['type'] ?? '') : '';
            $evtLvl = $evt ? (string)($evt['level'] ?? '') : '';
            $evtMsg = $evt ? (string)($evt['message'] ?? '') : '';

            echo '<tr>'
                .'<td>'.hds3_safe_html($r['id']).'</td>'
                .'<td><code>'.hds3_safe_html($parent ?: '—').'</code></td>'
                .'<td>'.self::connector_limits_badge($parent, $limits_cache).'</td>'
                .'<td><code>'.hds3_safe_html($subkey ?: '—').'</code></td>'
                .'<td>'.hds3_safe_html($domain ?: '—').'</td>'
                .'<td>'.hds3_safe_html($plan ?: '—').'</td>'
                .'<td>'.hds3_safe_html($status ?: '—').'</td>'
                .'<td>'.hds3_safe_html($lastSeen ?: '—').'</td>'
                .'<td class="hds3-muted">'.hds3_safe_html($vault ? mb_substr($vault, 0, 48) . (strlen($vault)>48?'…':'') : '—').'</td>'
                .'<td>'.hds3_safe_html($evtAt ?: '—').'</td>'
                .'<td>'.hds3_safe_html($evtType ?: '—').'</td>'
                .'<td>'.hds3_safe_html($evtLvl ?: '—').'</td>'
                .'<td>'.hds3_safe_html($evtMsg ? mb_substr($evtMsg, 0, 140) : '—').'</td>'
                .'<td>'.self::connector_actions_cell((int)$r['id'], $parent, $domain).'</td>'
                .'</tr>';
        }
        if (empty($rows)) echo '<tr><td colspan="14">Brak rekordów konektorów.</td></tr>';
        echo '</tbody></table>';

        self::footer();
    }

    private static function connector_actions_cell(int $id, string $parent, string $domain): string {
        if (!current_user_can('manage_options')) return '—';
        $url = admin_url('admin-post.php');
        $h = '';

        // Soft revoke
        $h .= '<form method="post" action="'.hds3_safe_url($url).'" style="display:inline-block; margin:0 6px 0 0">';
        $h .= '<input type="hidden" name="action" value="hds3_connector_revoke"/>';
        $h .= '<input type="hidden" name="id" value="'.hds3_safe_attr((string)$id).'"/>';
        $h .= wp_nonce_field('hds3_connector_revoke','hds3_connector_revoke_nonce', true, false);
	    $h .= "<button class=\"button\" onclick=\"return confirm('Cofnąć (soft revoke) ten konektor?')\">Cofnij</button>";
        $h .= '</form>';

        // Hard delete
        $h .= '<form method="post" action="'.hds3_safe_url($url).'" style="display:inline-block; margin:0 6px 0 0">';
        $h .= '<input type="hidden" name="action" value="hds3_connector_delete"/>';
        $h .= '<input type="hidden" name="id" value="'.hds3_safe_attr((string)$id).'"/>';
        $h .= '<input type="hidden" name="parent" value="'.hds3_safe_attr($parent).'"/>';
        $h .= '<input type="hidden" name="domain" value="'.hds3_safe_attr($domain).'"/>';
        $h .= wp_nonce_field('hds3_connector_delete','hds3_connector_delete_nonce', true, false);
	    $h .= "<button class=\"button button-link-delete\" onclick=\"return confirm('Usunąć konektor i powiązane subklucze/subtokeny?')\">Usuń</button>";
        $h .= '</form>';
        return $h;
    }

    private static function meta_decode($meta): array {
        if (is_array($meta)) return $meta;
        $raw = is_string($meta) ? $meta : '';
        if ($raw === '') return [];
        $data = hds3_json_decode($raw, []);
        return is_array($data) ? $data : [];
    }

    private static function meta_short(array $meta): string {
        if (!$meta) return '';
        $keep = ['plan_code','plan','package','duration','expires_at','activated_at','max_domains','used_domains','domain','site','customer','label'];
        $out = [];
        foreach ($keep as $k) {
            if (!isset($meta[$k])) continue;
            $v = $meta[$k];
            if (is_array($v)) $v = wp_json_encode($v);
            $v = (string)$v;
            if ($v === '') continue;
            $out[] = $k.'='.$v;
        }
        $s = implode('; ', $out);
        return mb_substr($s, 0, 160);
    }

    public static function page_instances() {
        if (!HDS3_Settings::module_enabled('licenses')) {
            self::header('Witryny', 'Moduł licencji jest wyłączony.');
            self::footer(); return;
        }
        self::header('Witryny', 'Instancje przypięte do tenantów (aktywacje).');
        global $wpdb;
        $t = HDS3_DB::table('instances');
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 200", ARRAY_A);
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>tenant</th><th>site</th><th>produkt</th><th>status</th><th>activated</th><th>last_seen</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>'.hds3_safe_html($r['id']).'</td><td>'.hds3_safe_html($r['tenant_id']).'</td><td>'.hds3_safe_html($r['site']).'</td><td>'.hds3_safe_html($r['product']).'</td><td>'.hds3_safe_html($r['status']).'</td><td>'.hds3_safe_html($r['activated_at']).'</td><td>'.hds3_safe_html($r['last_seen_at']).'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">Brak instancji.</td></tr>';
        echo '</tbody></table>';
        self::footer();
    }

    /**
     * Provisioning (onboarding) — jeden formularz: tenant + plan + token + licencja.
     * Cel: szybkie dopinanie klienta bez konsoli/CLI.
     */
    public static function page_provisioning() {
        if (!HDS3_Settings::module_enabled('tenants')) {
            self::header('Provisioning', 'Moduł tenantów jest wyłączony.');
            self::footer();
            return;
        }

        self::header('Provisioning', 'Szybkie dopięcie klienta: tworzy <b>tenanta</b>, przypisuje <b>plan</b>, generuje <b>token</b> i (opcjonalnie) <b>licencję</b>. Wszystko w warstwie HDS3 (bez wp_options jako bazy).');

        $flash = HDS3_Settings::get('flash_provision', '');
        if ($flash) {
            $data = hds3_json_decode($flash, []);
            if (is_array($data) && !empty($data['message'])) {
                echo '<div class="updated notice"><p>' . wp_kses_post($data['message']) . '</p></div>';
                if (!empty($data['token'])) {
                    echo '<div class="notice notice-warning"><p><b>Token (pokażę go tylko raz):</b> <code style="font-size:13px">' . hds3_safe_html($data['token']) . '</code></p></div>';
                }
                if (!empty($data['license_key'])) {
                    echo '<div class="notice notice-info"><p><b>Licencja:</b> <code>' . hds3_safe_html($data['license_key']) . '</code></p></div>';
                }
            }
            // jednorazowo
            HDS3_Settings::set('flash_provision', '');
        }

        $plans = [];
        if (HDS3_Settings::module_enabled('plans')) {
            $plans = HDS3_Plans::list_plans(true);
        }

        $default_scopes = ['telemetry:write','license:activate','license:validate'];

        echo '<div style="max-width:980px">';
        echo '<div class="postbox" style="padding:14px">';
        echo '<h2 style="margin-top:0">Nowy klient / onboarding</h2>';
        echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hds3_provision_create">';
        wp_nonce_field('hds3_provision_create', 'hds3_provision_nonce');

        echo '<p><label><b>Nazwa klienta</b><br><input class="regular-text" name="tenant_name" placeholder="np. Klient Premium" required></label></p>';

        if (!empty($plans)) {
            echo '<p><label><b>Plan</b><br><select name="plan_key">';
            foreach ($plans as $p) {
                $pk = (string)($p['plan_key'] ?? 'basic');
                $nm = (string)($p['name'] ?? $pk);
                echo '<option value="' . hds3_safe_attr($pk) . '">' . hds3_safe_html($nm . ' (' . $pk . ')') . '</option>';
            }
            echo '</select></label></p>';
        } else {
            echo '<input type="hidden" name="plan_key" value="basic">';
            echo '<p class="description">Plany są wyłączone — przypisuję <code>basic</code>.</p>';
        }

        echo '<hr style="margin:14px 0">';
        echo '<h3 style="margin:0 0 10px">Token API (tenant)</h3>';
        echo '<p class="description" style="margin-top:0">Token jest widoczny tylko raz po utworzeniu. Scope ogranicza dostęp.</p>';
        echo '<p><label>Etykieta tokenu<br><input class="regular-text" name="token_label" placeholder="np. integracja klienta / serwer 1"></label></p>';

        $all_scopes = ['telemetry:write'=>'telemetry:write','license:activate'=>'license:activate','license:validate'=>'license:validate','jobs:push'=>'jobs:push','*'=>'* (pełny dostęp)'];
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($all_scopes as $k=>$label) {
            $checked = in_array($k, $default_scopes, true) ? 'checked' : '';
            echo '<label style="display:inline-flex;gap:8px;align-items:center;padding:8px 10px;border:1px solid rgba(0,0,0,.08);border-radius:10px;background:#fff">'
                . '<input type="checkbox" name="scopes[]" value="' . hds3_safe_attr($k) . '" ' . $checked . '> '
                . '<span>' . hds3_safe_html($label) . '</span>'
                . '</label>';
        }
        echo '</div>';

        echo '<hr style="margin:14px 0">';
        echo '<h3 style="margin:0 0 10px">Licencja (opcjonalnie)</h3>';
        $lic_checked = HDS3_Settings::module_enabled('licenses') ? 'checked' : '';
        $lic_disabled = HDS3_Settings::module_enabled('licenses') ? '' : 'disabled';
        echo '<p><label style="display:inline-flex;gap:8px;align-items:center"><input type="checkbox" name="make_license" value="1" ' . $lic_checked . ' ' . $lic_disabled . '> Utwórz licencję od razu</label></p>';
        echo '<p><label>Produkt<br><input class="regular-text" name="product" value="CORE" ' . ($lic_disabled ? 'disabled' : '') . '></label></p>';

        echo '<p><button class="button button-primary">Utwórz klienta</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="postbox" style="padding:14px;margin-top:14px">';
        echo '<h2 style="margin-top:0">Gdzie to potem widać?</h2>';
        echo '<ul style="margin-left:18px">
            <li><b>Klienci</b> — lista tenantów i liczników.</li>
            <li><b>Tokeny</b> — zarządzanie tokenami (revoke).</li>
            <li><b>Licencje</b> — lista kluczy.</li>
            <li><b>Witryny</b> — aktywacje / instancje dopinają się po <code>/license/activate</code>.</li>
        </ul>';
        echo '</div>';
        echo '</div>';

        self::footer();
    }

    public static function provision_create() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('hds3_provision_create', 'hds3_provision_nonce');

        $name = sanitize_text_field($_POST['tenant_name'] ?? '');
        $plan_key = sanitize_text_field($_POST['plan_key'] ?? 'basic');
        $scopes = (array)($_POST['scopes'] ?? []);
        $token_label = sanitize_text_field($_POST['token_label'] ?? '');

        if (strlen($name) < 2) {
            HDS3_Settings::set('flash_provision', hds3_json_encode(['message'=>'Błąd: nazwa jest za krótka.']));
            wp_redirect(hds3_admin_url('hds3_provisioning'));
            exit;
        }

        $tenant_id = HDS3_Tenants::create($name, 'active');
        if (!$tenant_id || is_wp_error($tenant_id)) {
            $msg = is_wp_error($tenant_id) ? $tenant_id->get_error_message() : 'Nie udało się utworzyć tenanta.';
            HDS3_Settings::set('flash_provision', hds3_json_encode(['message'=>'Błąd: '.hds3_safe_html($msg)]));
            wp_redirect(hds3_admin_url('hds3_provisioning'));
            exit;
        }

        // Plan
        if (HDS3_Settings::module_enabled('plans')) {
            HDS3_Plans::set_tenant_plan_key($tenant_id, $plan_key ?: 'basic');
        }

        // Token
        $token_plain = '';
        $tk = HDS3_Tokens::create($tenant_id, $scopes, $token_label);
        if (!is_wp_error($tk) && is_array($tk)) {
            $token_plain = (string)($tk['token'] ?? '');
        }

        // Licencja opcjonalnie
        $license_key = '';
        if (HDS3_Settings::module_enabled('licenses') && !empty($_POST['make_license'])) {
            $product = sanitize_text_field($_POST['product'] ?? 'CORE');
            $license_key = function_exists('hds3_generate_license_key')
                ? hds3_generate_license_key($product, 24)
                : ('LIC-' . strtoupper(wp_generate_password(24, false, false)));
            global $wpdb;
            $t = HDS3_DB::table('licenses');
            $now = hds3_now_gmt();
            $cols = HDS3_DB::cols($t);
            $data = [
                'tenant_id' => $tenant_id,
                'license_key' => $license_key,
                'product' => $product ?: 'CORE',
                'status' => 'active',
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (in_array('klucz', $cols, true)) $data['klucz'] = $license_key;
            if (in_array('produkt', $cols, true)) $data['produkt'] = $data['product'];

            for ($i = 0; $i < 5; $i++) {
                $ok = $wpdb->insert($t, array_filter($data, function($k) use ($cols) {
                    if (!$cols) return true;
                    return in_array($k, $cols, true);
                }, ARRAY_FILTER_USE_KEY));
                if ($ok) break;
                $err = (string)$wpdb->last_error;
                if ($err && stripos($err, 'duplicate') !== false) {
                    $license_key = function_exists('hds3_generate_license_key')
                        ? hds3_generate_license_key($product, 24)
                        : ('LIC-' . strtoupper(wp_generate_password(24, false, false)));
                    $data['license_key'] = $license_key;
                    if (isset($data['klucz'])) $data['klucz'] = $license_key;
                    continue;
                }
                break;
            }
        }

        $msg = 'Utworzono klienta: <code>' . hds3_safe_html($tenant_id) . '</code>.';
        if (HDS3_Settings::module_enabled('plans')) $msg .= ' Plan: <code>' . hds3_safe_html($plan_key ?: 'basic') . '</code>.';

        HDS3_Settings::set('flash_provision', hds3_json_encode([
            'message' => $msg,
            'token' => $token_plain,
            'license_key' => $license_key,
        ]));

        wp_redirect(hds3_admin_url('hds3_provisioning'));
        exit;
    }

    public static function page_tenants() {
        if (!HDS3_Settings::module_enabled('tenants')) {
            self::header('Klienci', 'Moduł tenantów jest wyłączony.');
            self::footer();
            return;
        }

        self::header('Klienci (Tenants)', 'TENANT CORE — klienci jako odseparowana warstwa SaaS. Tenanty nie są postami WordPress i nie siedzą w wp_options.');

        $msg = '';

        // Zapis/edycja
        if (!empty($_POST['hds3_tenants_nonce']) && wp_verify_nonce($_POST['hds3_tenants_nonce'], 'hds3_tenants_save')) {
            $mode = sanitize_text_field($_POST['mode'] ?? '');
            $name = sanitize_text_field($_POST['name'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? 'active');
            if ($mode === 'create') {
                if (strlen($name) < 2) {
                    $msg = '<div class="error notice"><p>Nazwa jest za krótka.</p></div>';
                } else {
                    $tid = HDS3_Tenants::create($name, $status);
                    $msg = '<div class="updated notice"><p>Dodano klienta: <code>'.hds3_safe_html($tid).'</code></p></div>';
                }
            } elseif ($mode === 'update') {
                $tid = sanitize_text_field($_POST['tenant_id'] ?? '');
                if (!$tid) {
                    $msg = '<div class="error notice"><p>Brak tenant_id.</p></div>';
                } else {
                    HDS3_Tenants::update($tid, ['name'=>$name,'status'=>$status]);
                    $msg = '<div class="updated notice"><p>Zapisano zmiany.</p></div>';
                }
            }
        }

        echo $msg;

        // Edycja
        $edit_id = sanitize_text_field($_GET['edit'] ?? '');
        $edit = $edit_id ? HDS3_Tenants::get($edit_id) : null;

        echo '<div style="display:grid;grid-template-columns: 360px 1fr; gap:16px; max-width: 1200px;">';

        // Lewy panel: dodaj/edytuj
        echo '<div class="postbox" style="padding:12px;">';
        if ($edit) {
            echo '<h2 style="margin-top:0;">Edytuj klienta</h2>';
        } else {
            echo '<h2 style="margin-top:0;">Dodaj klienta</h2>';
        }
        echo '<form method="post">';
        wp_nonce_field('hds3_tenants_save', 'hds3_tenants_nonce');
        echo '<input type="hidden" name="mode" value="'.hds3_safe_attr($edit ? 'update' : 'create').'">';
        if ($edit) {
            echo '<input type="hidden" name="tenant_id" value="'.hds3_safe_attr($edit['tenant_id']).'">';
            echo '<p><b>tenant_id:</b><br><code>'.hds3_safe_html($edit['tenant_id']).'</code></p>';
        } else {
            echo '<p class="description">tenant_id zostanie wygenerowany automatycznie.</p>';
        }
        echo '<p><label><b>Nazwa</b><br><input type="text" name="name" class="regular-text" value="'.hds3_safe_attr($edit['name'] ?? '').'" placeholder="np. Klient Premium"></label></p>';
        $st = $edit['status'] ?? 'active';
        echo '<p><label><b>Status</b><br>';
        echo '<select name="status">'
            . '<option value="active" '.selected($st,'active',false).'>aktywny</option>'
            . '<option value="suspended" '.selected($st,'suspended',false).'>zawieszony</option>'
            . '</select></label></p>';
        echo '<p><button class="button button-primary">'.($edit?'Zapisz':'Dodaj').'</button> ';
        if ($edit) echo '<a class="button" href="'.hds3_safe_url(hds3_admin_url('hds3_tenants')).'">Anuluj</a>';
        echo '</p>';
        echo '</form>';
        echo '<hr><p class="description">Migracja: jeśli masz istniejące licencje/instancje, tenanty są tworzone automatycznie na podstawie <code>tenant_id</code>.</p>';
        echo '</div>';

        // Prawy panel: lista
        echo '<div class="postbox" style="padding:12px;">';
        echo '<h2 style="margin-top:0;">Lista klientów</h2>';
        $rows = HDS3_Tenants::list_with_counts(300);
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>tenant_id</th><th>Nazwa</th><th>Status</th><th>Licencje</th><th>Witryny</th><th>Aktualizacja</th><th>Akcja</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $tid = $r['tenant_id'];
            $status = (string)($r['status'] ?? '');
            echo '<tr>';
            echo '<td><code>'.hds3_safe_html($tid).'</code></td>';
            echo '<td>'.hds3_safe_html($r['name']).'</td>';
            echo '<td>'.hds3_safe_html($status).'</td>';
            echo '<td>'.hds3_safe_html((string)$r['licenses']).'</td>';
            echo '<td>'.hds3_safe_html((string)$r['instances']).'</td>';
            echo '<td><span class="hds3-muted">'.hds3_safe_html($r['updated_at']).'</span></td>';

            echo '<td>';
            echo '<a class="button" href="'.hds3_safe_url(hds3_admin_url('hds3_tenants').'&edit='.rawurlencode($tid)).'">Edytuj</a> ';

            if ($status === 'active') {
                echo '<form method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0 6px 0 0">';
                echo '<input type="hidden" name="action" value="hds3_tenant_revoke" />';
                echo '<input type="hidden" name="tenant_id" value="'.hds3_safe_attr($tid).'" />';
                wp_nonce_field('hds3_tenant_revoke');
                echo '<button class="button" onclick="return confirm(\"Zawiesic tego klienta (soft revoke)?\")">Zawies</button>';
                echo '</form>';
            }

            echo '<form method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0">';
            echo '<input type="hidden" name="action" value="hds3_tenant_delete" />';
            echo '<input type="hidden" name="tenant_id" value="'.hds3_safe_attr($tid).'" />';
            wp_nonce_field('hds3_tenant_delete');
            echo '<button class="button button-link-delete" onclick="return confirm(\"HARD DELETE: usunac klienta i wszystkie powiazane dane?\")">Usun</button>';
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7">Brak klientów. Dodaj pierwszego klienta lub wykonaj aktywację licencji, aby migracja sama stworzyła tenant.</td></tr>';
        }
        echo '</tbody></table>';

        echo '</div>';

        echo '</div>';

        self::footer();
    }

    public static function page_verification() {
        if (!HDS3_Settings::module_enabled('verification')) {
            self::header('Weryfikacja', 'Moduł wyłączony.');
            self::footer(); return;
        }
        self::header('Weryfikacja', 'Pipeline etapów + gatekeeper. Status dotyczy domyślnie witryny.');

        $site = hds3_site_host();
        HDS3_Kernel::ensure_verification_subject('site', $site);
        $v = HDS3_Kernel::verification_get('site', $site);
        $steps = HDS3_Kernel::verification_steps('site', $site);

        if (!empty($_POST['hds3_ver_nonce']) && wp_verify_nonce($_POST['hds3_ver_nonce'], 'hds3_ver_actions')) {
            $act = sanitize_text_field($_POST['ver_action'] ?? '');
            if ($act === 'complete_current') {
                $cur = $v['current_step'] ?? 'start';
                $res = HDS3_Kernel::verification_complete_step('site', $site, $cur);
                HDS3_Logger::event('verification','info','Domknięto etap: '.$cur, $res);
                $v = HDS3_Kernel::verification_get('site', $site);
                $steps = HDS3_Kernel::verification_steps('site', $site);
                echo '<div class="updated notice"><p>Domknięto etap.</p></div>';
            } elseif ($act === 'set_verified') {
                HDS3_Kernel::verification_set_verified('site', $site);
                HDS3_Logger::event('verification','info','Ustawiono verified (force).');
                $v = HDS3_Kernel::verification_get('site', $site);
                $steps = HDS3_Kernel::verification_steps('site', $site);
                echo '<div class="updated notice"><p>Ustawiono verified.</p></div>';
            } elseif ($act === 'reset') {
                global $wpdb;
                $vtable = HDS3_DB::table('verification');
                $stable = HDS3_DB::table('verification_steps');
                $wpdb->query($wpdb->prepare("UPDATE $vtable SET status=%s,current_step=%s,updated_at=%s WHERE subject_type=%s AND subject_id=%s", 'unverified','start',hds3_now_gmt(),'site',$site));
                $wpdb->query($wpdb->prepare("UPDATE $stable SET is_done=0,done_at=NULL,updated_at=%s WHERE subject_type=%s AND subject_id=%s", hds3_now_gmt(),'site',$site));
                HDS3_Logger::event('verification','warning','Reset weryfikacji (site).');
                $v = HDS3_Kernel::verification_get('site', $site);
                $steps = HDS3_Kernel::verification_steps('site', $site);
                echo '<div class="updated notice"><p>Zresetowano.</p></div>';
            }
        }

        echo '<h2>Status</h2>';
        echo '<p><b>Site:</b> '.hds3_safe_html($site).'<br>';
        echo '<b>Status:</b> '.hds3_safe_html($v['status'] ?? '—').'<br>';
        echo '<b>Bieżący etap:</b> '.hds3_safe_html($v['current_step'] ?? '—').'</p>';

        echo '<form method="post">';
        wp_nonce_field('hds3_ver_actions','hds3_ver_nonce');
        echo '<p class="hds3-verification-actions">';
        echo '<button class="button button-primary" name="ver_action" value="complete_current">Domknij bieżący etap</button> ';
        echo '<button class="button" name="ver_action" value="set_verified">Ustaw verified (force)</button> ';
        echo '<button class="button" name="ver_action" value="reset">Reset</button>';
        echo '</p></form>';

        echo '<h2>Pipeline</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Etap</th><th>Done</th><th>Done at</th></tr></thead><tbody>';
        foreach ($steps as $s) {
            echo '<tr><td>'.hds3_safe_html($s['step']).'</td><td>'.(!empty($s['is_done'])?'✅':'—').'</td><td>'.hds3_safe_html($s['done_at']).'</td></tr>';
        }
        echo '</tbody></table>';

        self::footer();
    }

    public static function page_queue() {
        if (!HDS3_Settings::module_enabled('queue')) {
            self::header('Kolejki', 'Moduł wyłączony.');
            self::footer(); return;
        }
        self::header('Kolejki', 'Jedna kolejka jobów + worker w panelu.');

        if (!empty($_POST['hds3_job_nonce']) && wp_verify_nonce($_POST['hds3_job_nonce'], 'hds3_job_add')) {
            $type = sanitize_text_field($_POST['jtype'] ?? '');
            $payload = trim((string)($_POST['payload'] ?? ''));
            if ($type) {
                global $wpdb;
                $t = HDS3_DB::table('jobs');
                $now = hds3_now_gmt();
                $wpdb->insert($t, [
                    'jtype' => $type,
                    'payload' => $payload ? $payload : null,
                    'status' => 'queued',
                    'priority' => 10,
                    'attempts' => 0,
                    'max_attempts' => 3,
                    'run_after' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                echo '<div class="updated notice"><p>Dodano job.</p></div>';
            } else {
                echo '<div class="error notice"><p>Podaj typ joba.</p></div>';
            }
        }

        echo '<h2>Dodaj job</h2>';
        echo '<form method="post">';
        wp_nonce_field('hds3_job_add','hds3_job_nonce');
        echo '<p><label>typ <input class="regular-text" name="jtype" placeholder="np. telemetry_flush"></label></p>';
        echo '<p><label>payload (JSON lub tekst)<br><textarea name="payload" rows="4" class="large-text code"></textarea></label></p>';
        echo '<p><button class="button button-primary">Dodaj</button></p></form>';

        echo '<hr/><h2>Worker</h2>';
        echo '<form class="hts-settings-legacy" method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_jobs_run">';
        wp_nonce_field('hds3_jobs_run','hds3_jobs_run_nonce');
        echo '<button class="button">Uruchom worker (1 batch)</button></form>';

        global $wpdb;
        $t = HDS3_DB::table('jobs');
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 200", ARRAY_A);
        echo '<h2>Lista jobów (200)</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>typ</th><th>status</th><th>attempts</th><th>updated</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>'.hds3_safe_html($r['id']).'</td><td>'.hds3_safe_html($r['jtype']).'</td><td>'.hds3_safe_html($r['status']).'</td><td>'.hds3_safe_html($r['attempts']).'/'.hds3_safe_html($r['max_attempts']).'</td><td>'.hds3_safe_html($r['updated_at']).'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">Brak jobów.</td></tr>';
        echo '</tbody></table>';

        self::footer();
    }

    public static function jobs_run() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_jobs_run_nonce'] ?? '', 'hds3_jobs_run')) wp_die('Błędny nonce.');
        $processed = HDS3_REST::run_jobs_batch(20);
        wp_safe_redirect(add_query_arg(['page'=>'hds3_queue','ran'=>$processed], admin_url('admin.php')));
        exit;
    }

    public static function page_cleanup() {
        self::header('Sprzątanie', 'Wyłącz duplikaty i usuń legacy opcje. Ostrożnie.');

        $candidates = self::candidate_plugins();
        $force = HDS3_Settings::get('force_disable_plugins', []);
        if (is_string($force)) {
            $decoded = json_decode($force, true);
            if (is_array($decoded)) $force = $decoded;
        }
        if (!is_array($force)) $force = [];
        $opts = self::candidate_options();

        echo '<h2>Wtyczki do wyłączenia (kandydaci)</h2>';
        echo '<p class="description">Opcja <b>Wymuś OFF</b> spowoduje automatyczne dezaktywowanie duplikatu przy starcie (jeśli ktoś go przypadkiem włączy).</p>';
        echo '<form class="hts-settings-legacy" method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_deactivate_plugins">';
        wp_nonce_field('hds3_deactivate_plugins','hds3_deactivate_plugins_nonce');
        echo '<table class="widefat striped"><thead><tr><th>Wyłącz</th><th>Wymuś OFF</th><th>Folder</th><th>Powód</th><th>Aktywna?</th></tr></thead><tbody>';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = get_option('active_plugins', []);
        foreach ($candidates as $slug => $reason) {
            // plugin file unknown; show slug and try match by startswith
            $is_active = false;
            $found_file = '';
            foreach ($active as $af) {
                if (strpos($af, $slug.'/') === 0) { $is_active = true; $found_file = $af; break; }
            }
            $force_checked = in_array($slug, $force, true) ? 'checked' : '';
            echo '<tr>';
            echo '<td><input type="checkbox" name="plugins[]" value="'.hds3_safe_attr($slug).'" '.($is_active?'':'disabled').'></td>';
            echo '<td><input type="checkbox" name="force[]" value="'.hds3_safe_attr($slug).'" '.$force_checked.'></td>';
            echo '<td><code>'.hds3_safe_html($slug).'</code></td>';
            echo '<td>'.hds3_safe_html($reason).'</td>';
            echo '<td>'.($is_active?'✅ '.hds3_safe_html($found_file):'—').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Zapisz / Dezaktywuj zaznaczone</button></p></form>';

        echo '<hr/><h2>Foldery wtyczek na dysku (podgląd)</h2>';
        $plugin_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (ABSPATH . 'wp-content/plugins');
        $dirs = [];
        if (is_dir($plugin_dir) && is_readable($plugin_dir)) {
            $scan = @scandir($plugin_dir);
            if (is_array($scan)) {
                foreach ($scan as $d) {
                    if ($d === '.' || $d === '..') continue;
                    if ($d === HDS3_SLUG) continue;
                    if (is_dir($plugin_dir . '/' . $d)) $dirs[] = $d;
                }
            }
        }
        sort($dirs);
        echo '<p class="description">Checklista folderów w <code>wp-content/plugins</code>. Nic nie jest usuwane automatycznie — to tylko podgląd co istnieje.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Folder</th><th>Aktywny?</th></tr></thead><tbody>';
        foreach ($dirs as $d) {
            $is_active = false;
            foreach ($active as $af) {
                if (strpos($af, $d . '/') === 0) { $is_active = true; break; }
            }
            echo '<tr><td><code>'.hds3_safe_html($d).'</code></td><td>'.($is_active?'✅':'—').'</td></tr>';
        }
        echo '</tbody></table>';

        echo '<hr/><h2>Legacy opcje w wp_options (kandydaci)</h2>';
        echo '<form class="hts-settings-legacy" method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_cleanup_options">';
        wp_nonce_field('hds3_cleanup_options','hds3_cleanup_options_nonce');
        echo '<table class="widefat striped"><thead><tr><th>Usuń</th><th>option_name</th><th>Podgląd</th></tr></thead><tbody>';
        foreach ($opts as $name) {
            $val = get_option($name, null);
            $pv = is_string($val) ? substr($val, 0, 120) : (is_array($val) ? 'array(' . count($val) . ')' : (is_object($val) ? 'object' : (string)$val));
            echo '<tr><td><input type="checkbox" name="options[]" value="'.hds3_safe_attr($name).'"></td><td><code>'.hds3_safe_html($name).'</code></td><td>'.hds3_safe_html($pv).'</td></tr>';
        }
        echo '</tbody></table><p><button class="button">Usuń zaznaczone opcje</button></p></form>';

        echo '<hr/><h2>Testowy śmietnik: usuń licencje po prefiksie</h2>';
        echo '<p class="description">Przykłady prefiksów: <code>SEO-</code>, <code>AGENCY_SEO-</code>, <code>SEO_CONNECTOR-</code>. Usuwa rekordy z tabeli licencji. Używaj tylko do sprzątania po testach.</p>';
        echo '<form class="hts-settings-legacy" method="post" action="'.hds3_safe_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_bulk_delete_by_prefix">';
        wp_nonce_field('hds3_bulk_delete','hds3_bulk_delete_nonce');
        echo '<input type="text" name="prefix" class="regular-text" placeholder="SEO-" required> ';
        echo '<button class="button" onclick="return confirm(\'Na pewno usunąć WSZYSTKIE licencje z tym prefiksem?\')">Usuń</button>';
        echo '</form>';

        self::footer();
    }

    private static function candidate_plugins() {
        return [
            'heterodyna-telemetry-dashboard' => 'Telemetria przeniesiona do HDS3.',
            'heterodyna-telemetry-bridge' => 'Bridge telemetrii dubluje się z HDS3.',
            'heterodyna-dashboard-licenses' => 'Licencje są w HDS3.',
            'heterodyna-saas-dashboard' => 'Panel zastąpiony Centrum HDS3.',
            'heterodyna-admin-center' => 'Panel zastąpiony Centrum HDS3.',
            'portal-verification' => 'Weryfikacja migruje do HDS3 (wyłącz dopiero po testach).',
        ];
    }

    private static function candidate_options() {
        return [
            'hds3_client_token',
            'heterodyna_la_endpoint',
            'heterodyna_la_public_key',
            'ppv_settings',
            'portal_verification_settings',
        ];
    }

    public static function deactivate_plugins() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_deactivate_plugins_nonce'] ?? '', 'hds3_deactivate_plugins')) wp_die('Błędny nonce.');
        $slugs = (array)($_POST['plugins'] ?? []);
        $force = (array)($_POST['force'] ?? []);

        // Zapisz wymuszenia OFF (lista slugów).
        $force_clean = [];
        foreach ($force as $f) {
            $f = sanitize_text_field((string)$f);
            if (!$f) continue;
            if ($f === HDS3_SLUG) continue;
            $force_clean[] = $f;
        }
        $force_clean = array_values(array_unique($force_clean));
        HDS3_Settings::set('force_disable_plugins', $force_clean);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = get_option('active_plugins', []);
        $to_deactivate = [];
        foreach ($active as $af) {
            foreach ($slugs as $slug) {
                $slug = sanitize_text_field($slug);
                if ($slug === HDS3_SLUG) continue;
                if (strpos($af, $slug.'/') === 0) $to_deactivate[] = $af;
            }
        }
        if ($to_deactivate) {
            deactivate_plugins($to_deactivate);
            HDS3_Logger::event('cleanup','warning','Dezaktywacja wtyczek', ['plugins'=>$to_deactivate]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_cleanup','done'=>'1'], admin_url('admin.php')));
        exit;
    }

    public static function cleanup_options() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_cleanup_options_nonce'] ?? '', 'hds3_cleanup_options')) wp_die('Błędny nonce.');
        $opts = (array)($_POST['options'] ?? []);
        $deleted = [];
        foreach ($opts as $o) {
            $o = sanitize_text_field($o);
            if ($o) {
                delete_option($o);
                $deleted[] = $o;
            }
        }
        if ($deleted) HDS3_Logger::event('cleanup','warning','Usunięto legacy opcje', ['options'=>$deleted]);
        wp_safe_redirect(add_query_arg(['page'=>'hds3_cleanup','opt'=>'1'], admin_url('admin.php')));
        exit;
    }

    public static function license_revoke() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_license_revoke_nonce'] ?? '', 'hds3_license_revoke')) wp_die('Błędny nonce.');
        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        if (!$license_key) wp_die('Brak klucza.');
        global $wpdb;
        $t = HDS3_DB::table('licenses');
        $wpdb->update($t, ['status'=>'revoked'], ['license_key'=>$license_key]);
        HDS3_Logger::event('licenses','warning','Unieważniono licencję', ['license_key'=>$license_key]);
        wp_redirect(admin_url('admin.php?page=hds3_active_keys&revoked=1'));
        exit;
    }

    public static function license_delete() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_license_delete_nonce'] ?? '', 'hds3_license_delete')) wp_die('Błędny nonce.');
        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        if (!$license_key) wp_die('Brak klucza.');
        global $wpdb;
        $tLic = HDS3_DB::table('licenses');
        $wpdb->delete($tLic, ['license_key'=>$license_key]);
        HDS3_Logger::event('licenses','warning','Usunięto licencję', ['license_key'=>$license_key]);
        wp_redirect(admin_url('admin.php?page=hds3_active_keys&deleted=1'));
        exit;
    }

    public static function connector_delete() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_connector_delete_nonce'] ?? '', 'hds3_connector_delete')) wp_die('Błędny nonce.');
        $id = (int)($_POST['id'] ?? 0);
        $parent = sanitize_text_field($_POST['parent'] ?? '');
        $domain = sanitize_text_field($_POST['domain'] ?? '');
        global $wpdb;
        $tCon = HDS3_DB::table('agency_connectors');
        $tSk = HDS3_DB::table('agency_subkeys');
        $tSt = HDS3_DB::table('agency_subtokens');

        if ($id > 0) $wpdb->delete($tCon, ['id'=>$id]);
        if ($parent && $domain) {
            $wpdb->delete($tSk, ['parent_license_key'=>strtoupper($parent), 'domain'=>strtolower($domain)]);
            $wpdb->delete($tSt, ['parent_license_key'=>strtoupper($parent), 'domain'=>strtolower($domain)]);
        }
        HDS3_Logger::event('agency','warning','Usunięto konektor', ['id'=>$id,'parent'=>$parent,'domain'=>$domain]);
        wp_redirect(admin_url('admin.php?page=hds3_connectors&deleted=1'));
        exit;
    }

    public static function bulk_delete_by_prefix() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_bulk_delete_nonce'] ?? '', 'hds3_bulk_delete')) wp_die('Błędny nonce.');
        $prefix = strtoupper(sanitize_text_field($_POST['prefix'] ?? ''));
        if (!$prefix) wp_die('Brak prefiksu.');
        global $wpdb;
        $tLic = HDS3_DB::table('licenses');
        $like = $wpdb->esc_like($prefix) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM $tLic WHERE license_key LIKE %s", $like));
        HDS3_Logger::event('cleanup','warning','Bulk delete licencji po prefiksie', ['prefix'=>$prefix]);
        wp_redirect(admin_url('admin.php?page=hds3_cleanup&bulk=1'));
        exit;
    }

    public static function ppv_import() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        if (!wp_verify_nonce($_POST['hds3_ppv_import_nonce'] ?? '', 'hds3_ppv_import')) wp_die('Błędny nonce.');
        $count = HDS3_REST::import_ppv_best_effort();
        wp_safe_redirect(add_query_arg(['page'=>'hds3_settings','ppv'=>$count], admin_url('admin.php')));
        exit;
    }

    // ====== PLANY I LIMITY (SaaS) ======
    public static function save_tenant_plan() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_save_tenant_plan');
        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        $plan_key = sanitize_key(wp_unslash($_POST['plan_key'] ?? ''));
        $chosen = HDS3_Plans::get_plan($plan_key);
        if ($tenant_id && $chosen && $chosen['status'] === 'active') {
            HDS3_Plans::set_tenant_plan_key($tenant_id, $plan_key);
            HDS3_Logger::event('plans','info','Zmieniono plan',['tenant_id'=>$tenant_id,'plan_key'=>$plan_key]);
        }
        wp_redirect(add_query_arg(['page'=>'hds3_plans','updated'=>'1'], admin_url('admin.php')));
        exit;
    }

    /** Add an administrator-defined plan without overriding existing records. */
    public static function create_plan() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('hds3_create_plan');
        $key = strtolower(sanitize_key(wp_unslash($_POST['plan_key'] ?? '')));
        $name = sanitize_text_field(wp_unslash($_POST['plan_name'] ?? ''));
        if (!$key || strlen($key) > 64 || !$name || strlen($name) > 190) {
            wp_safe_redirect(add_query_arg(['page'=>'hds3_plans','plan_error'=>'invalid'], admin_url('admin.php'))); exit;
        }
        global $wpdb;
        $table = HDS3_DB::table('plans');
        if (HDS3_Plans::get_plan($key)) {
            wp_safe_redirect(add_query_arg(['page'=>'hds3_plans','plan_error'=>'duplicate'], admin_url('admin.php'))); exit;
        }
        $limits = ['max_instances'=>max(0,(int)($_POST['max_instances'] ?? 0)), 'jobs_per_day'=>max(0,(int)($_POST['jobs_per_day'] ?? 0)), 'telemetry_per_day'=>max(0,(int)($_POST['telemetry_per_day'] ?? 0))];
        $now = hds3_now_gmt();
        $ok = $wpdb->insert($table, ['plan_key'=>$key,'name'=>$name,'status'=>'active','limits'=>hds3_json_encode($limits),'created_at'=>$now,'updated_at'=>$now], ['%s','%s','%s','%s','%s','%s']);
        if ($ok) HDS3_Logger::event('plans','info','Utworzono plan', ['plan_key'=>$key]);
        wp_safe_redirect(add_query_arg(['page'=>'hds3_plans', $ok ? 'plan_created' : 'plan_error'=>$ok ? '1' : 'database'], admin_url('admin.php'))); exit;
    }

    public static function save_plans() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_save_plans');
        $plans = HDS3_Plans::list_plans(false);
        global $wpdb;
        $pt = HDS3_DB::table('plans');
        $now = hds3_now_gmt();
        foreach ($plans as $p) {
            $k = $p['plan_key'];
            $name = sanitize_text_field($_POST['name_'.$k] ?? $p['name']);
            $status = sanitize_text_field($_POST['status_'.$k] ?? $p['status']);
            $status = in_array($status, ['active','disabled'], true) ? $status : $p['status'];
            $max_instances = (int)($_POST['max_instances_'.$k] ?? ($p['limits']['max_instances'] ?? 0));
            $jobs_per_day = (int)($_POST['jobs_per_day_'.$k] ?? ($p['limits']['jobs_per_day'] ?? 0));
            $telemetry_per_day = (int)($_POST['telemetry_per_day_'.$k] ?? ($p['limits']['telemetry_per_day'] ?? 0));
            $limits = is_array($p['limits']) ? $p['limits'] : [];
            $limits = array_merge($limits, [
                'max_instances' => max(0,$max_instances),
                'jobs_per_day' => max(0,$jobs_per_day),
                'telemetry_per_day' => max(0,$telemetry_per_day),
            ]);
            $wpdb->query($wpdb->prepare("UPDATE $pt SET name=%s,status=%s,limits=%s,updated_at=%s WHERE plan_key=%s", $name, $status, hds3_json_encode($limits), $now, $k));
        }
        wp_redirect(add_query_arg(['page'=>'hds3_plans','saved'=>'1'], admin_url('admin.php')));
        exit;
    }

    public static function page_plans() {
        self::header('Plany i Limity', 'Pakiety SaaS i egzekucja limitów per tenant. WordPress jest tylko panelem — dane i limity są w tabelach HDS3.');

        if (!HDS3_Settings::module_enabled('plans')) {
            echo '<p class="notice notice-warning"><b>Moduł plans jest wyłączony.</b> Włącz go w zakładce Moduły.</p>';
            self::footer();
            return;
        }

        if (!empty($_GET['plan_created'])) echo '<div class="notice notice-success"><p>Utworzono nowy plan.</p></div>';
        if (!empty($_GET['plan_error'])) echo '<div class="notice notice-error"><p>Nie udało się utworzyć planu. Sprawdź identyfikator, nazwę i czy plan już istnieje.</p></div>';
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success"><p>Zapisano plany i limity.</p></div>';
        if (!empty($_GET['updated'])) echo '<div class="notice notice-success"><p>Zmieniono plan dla klienta.</p></div>';

        $plans = HDS3_Plans::list_plans(false);
        echo '<section class="hds3-create-plan"><h2>Utwórz własny plan</h2><p>Wybierz unikalny identyfikator (bez spacji), nazwę i limity. Zero oznacza brak limitu dla starszych mechanizmów — nie wszystkie operacje są jeszcze objęte egzekwowaniem limitów.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="hds3-plan-create-form">';
        echo '<input type="hidden" name="action" value="hds3_create_plan"/>';
        wp_nonce_field('hds3_create_plan');
        echo '<label>Identyfikator <input required maxlength="64" pattern="[a-z0-9_-]+" name="plan_key" placeholder="np. starter"/></label>';
        echo '<label>Nazwa <input required maxlength="190" name="plan_name" placeholder="np. Starter"/></label>';
        echo '<label>Instancje <input type="number" min="0" name="max_instances" value="0"/></label>';
        echo '<label>Zadania/dzień <input type="number" min="0" name="jobs_per_day" value="0"/></label>';
        echo '<label>Telemetria/dzień <input type="number" min="0" name="telemetry_per_day" value="0"/></label>';
        echo '<button type="submit" class="button button-primary">Dodaj plan</button></form></section>';
        echo '<h2>Plany (pakiety)</h2>';
        if (!$plans) echo '<p class="description">Brak planów. Utwórz pierwszy plan powyżej.</p>';
        echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hds3_save_plans"/>';
        wp_nonce_field('hds3_save_plans');
        echo '<table class="widefat striped"><thead><tr><th>Klucz</th><th>Nazwa</th><th>Status</th><th>Maks. instancji</th><th>Joby / dzień</th><th>Telemetria / dzień</th></tr></thead><tbody>';
        foreach ($plans as $p) {
            $k = hds3_safe_html($p['plan_key']);
            $name = hds3_safe_attr($p['name']);
            $status = hds3_safe_attr($p['status']);
            $lim = is_array($p['limits']) ? $p['limits'] : [];
            $mi = (int)($lim['max_instances'] ?? 0);
            $jd = (int)($lim['jobs_per_day'] ?? 0);
            $td = (int)($lim['telemetry_per_day'] ?? 0);
            echo '<tr>';
            echo '<td><code>'.$k.'</code></td>';
            echo '<td><input class="regular-text" name="name_'.$k.'" value="'.$name.'"/></td>';
            echo '<td><select name="status_'.$k.'"><option value="active"'.($status==='active'?' selected':'').'>active</option><option value="disabled"'.($status==='disabled'?' selected':'').'>disabled</option></select></td>';
            echo '<td><input type="number" min="0" name="max_instances_'.$k.'" value="'.$mi.'" style="width:120px"/></td>';
            echo '<td><input type="number" min="0" name="jobs_per_day_'.$k.'" value="'.$jd.'" style="width:120px"/></td>';
            echo '<td><input type="number" min="0" name="telemetry_per_day_'.$k.'" value="'.$td.'" style="width:140px"/></td>';
            echo '</tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Zapisz plany</button></p></form>';

        // Tenants assignment
        echo '<h2>Przypisanie planu do klienta</h2>';
        $tenants = HDS3_Tenants::list_with_counts(500);
        echo '<table class="widefat striped"><thead><tr><th>Tenant</th><th>Nazwa</th><th>Aktualny plan</th><th>Zmień</th></tr></thead><tbody>';
        foreach ($tenants as $t) {
            $tid = hds3_safe_html($t['tenant_id']);
            $nm = hds3_safe_html($t['name']);
            $cur = hds3_safe_html(HDS3_Plans::get_tenant_plan_key($t['tenant_id']));
            echo '<tr><td><code>'.$tid.'</code></td><td>'.$nm.'</td><td><b>'.$cur.'</b></td><td>';
            echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;">';
            echo '<input type="hidden" name="action" value="hds3_save_tenant_plan"/>';
            wp_nonce_field('hds3_save_tenant_plan');
            echo '<input type="hidden" name="tenant_id" value="'.hds3_safe_attr($t['tenant_id']).'"/>';
            echo '<select name="plan_key">';
            foreach ($plans as $p) {
                $pk = hds3_safe_attr($p['plan_key']);
                $sel = ($pk === $cur) ? ' selected' : '';
                echo '<option value="'.$pk.'"'.$sel.'>'.hds3_safe_html($p['name']).' ('.hds3_safe_html($p['plan_key']).')</option>';
            }
            echo '</select><button class="button"'.(!$plans ? ' disabled' : '').'>Zapisz</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p class="description">Limity są egzekwowane przy: <b>aktywacji instancji</b> oraz <b>zapisywaniu telemetrii</b>. Kolejki/joby są best-effort (pełna egzekucja po dodaniu tenant_id do jobów w kolejnym pakiecie).</p>';

        // ====== PAKIETY DODATKOWE (per produkt/program na licencji) ======
        self::render_addons_section();

        self::footer();
    }

    /**
     * Pakiety dodatkowe: osobne zakładki per produkt (program) przypięty do licencji.
     * Konfiguracja jest trzymana w tabeli settings: skey = hds3_addons
     */
    private static function render_addons_section() {
        if (!HDS3_Settings::module_enabled('plans')) return;

        $products = self::discover_products();
        if (!$products) {
            echo '<div class="notice notice-info inline"><p>Nie dodano jeszcze produktów. <a href="'.esc_url(admin_url('admin.php?page=hds3_products')).'">Utwórz pierwszy produkt</a>, aby skonfigurować jego ofertę.</p></div>';
            return;
        }

        $active = isset($_GET['addon_product']) ? sanitize_text_field($_GET['addon_product']) : $products[0];
        if (!in_array($active, $products, true)) $active = $products[0];

        $raw = HDS3_Settings::get('hds3_addons', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];

        $plans = HDS3_Plans::list_plans(false);

        echo '<hr style="margin:18px 0">';
        echo '<h2>Pakiety dodatkowe (programy na licencji)</h2>';
        echo '<p class="description">To są <b>dodatkowe</b> ustawienia per produkt (np. SEO / PWA / Portal). Każdy produkt ma własną zakładkę. Zapis jest w HDS3 (tabela settings), więc działa także po upgrade przez nadpisanie plików.</p>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($products as $p) {
            $url = add_query_arg(['page'=>'hds3_plans','addon_product'=>$p], admin_url('admin.php'));
            $cls = ($p === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="'.hds3_safe_attr($cls).'" href="'.hds3_safe_url($url).'">'.hds3_safe_html($p).'</a>';
        }
        echo '</h2>';

        $pcfg = isset($cfg[$active]) && is_array($cfg[$active]) ? $cfg[$active] : [];

        if (!empty($_GET['addons_saved'])) {
            echo '<div class="notice notice-success"><p>Zapisano pakiety dodatkowe dla produktu: <b>' . hds3_safe_html($active) . '</b>.</p></div>';
        }

        echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hds3_save_addons" />';
        echo '<input type="hidden" name="product" value="' . hds3_safe_attr($active) . '" />';
        wp_nonce_field('hds3_save_addons');

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Plan</th><th>W pakiecie?</th><th>Limit instancji dla produktu</th><th>Notatka</th></tr></thead><tbody>';
        foreach ($plans as $pl) {
            $k = (string)($pl['plan_key'] ?? '');
            if (!$k) continue;
            $row = isset($pcfg[$k]) && is_array($pcfg[$k]) ? $pcfg[$k] : [];
            $enabled = !empty($row['enabled']) ? 1 : 0;
            $limit = isset($row['max_instances']) ? (int)$row['max_instances'] : 0;
            $note = isset($row['note']) ? (string)$row['note'] : '';

            echo '<tr>';
            echo '<td><b>' . hds3_safe_html($pl['name'] ?? $k) . '</b><br><code>' . hds3_safe_html($k) . '</code></td>';
            echo '<td><label style="display:inline-flex;gap:8px;align-items:center">'
                . '<input type="checkbox" name="enabled[' . hds3_safe_attr($k) . ']" value="1" ' . ($enabled ? 'checked' : '') . '> '
                . 'Aktywny</label></td>';
            echo '<td><input type="number" min="0" name="max_instances[' . hds3_safe_attr($k) . ']" value="' . hds3_safe_attr((string)$limit) . '" style="width:160px" />'
                . '<div class="description">0 = bez limitu (dziedzicz z planu)</div></td>';
            echo '<td><input class="regular-text" name="note[' . hds3_safe_attr($k) . ']" value="' . hds3_safe_attr($note) . '" placeholder="np. tylko dla wybranych" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary">Zapisz pakiety dla ' . hds3_safe_html($active) . '</button></p>';
        echo '</form>';

        echo '<p class="description">Uwaga: na tym etapie to jest <b>UI + konfiguracja</b>. Egzekucję limitów per produkt (np. przy aktywacji instancji) dopinamy w kolejnym kroku, gdy będziesz gotowy/a na twarde reguły.</p>';

        // ====== CENNIK / KATALOG PRODUKTU (np. SEO) ======
        self::render_product_catalog_editor($active);
    }

    /**
     * Edytor katalogu (cennik) dla produktu. Na start: preset dla SEO + shortcode.
     */
    private static function render_product_catalog_editor(string $product) {
        $product = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', $product));
        if (!$product) return;

        $raw = HDS3_Settings::get('hds3_catalog', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];

        $pcfg = isset($cfg[$product]) && is_array($cfg[$product]) ? $cfg[$product] : [];
        if (!$pcfg && $product === 'SEO') {
            $pcfg = hds3_default_catalog_seo();
        }

        $json = hds3_json_encode($pcfg);

        echo '<hr style="margin:18px 0">';
        echo '<h2>Cennik / katalog produktu: <code>' . hds3_safe_html($product) . '</code></h2>';
        echo '<p class="description">Tu trzymamy <b>oferty sprzedażowe</b> (sekcje + pakiety czasowe, ceny, opisy). Potem podepniemy to pod płatności/checkout. Na teraz: zapis + shortcode do wyświetlenia na stronie.</p>';

        if (!empty($_GET['catalog_saved'])) {
            echo '<div class="notice notice-success"><p>Zapisano katalog dla produktu: <b>' . hds3_safe_html($product) . '</b>.</p></div>';
        }
        if (!empty($_GET['catalog_preset'])) {
            echo '<div class="notice notice-success"><p>Wczytano preset katalogu dla: <b>' . hds3_safe_html($product) . '</b>.</p></div>';
        }

        echo '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
        if ($product === 'SEO') {
            echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="hds3_load_seo_catalog_preset" />';
            wp_nonce_field('hds3_load_seo_catalog_preset');
            echo '<button class="button">Wczytaj preset Heterodyna SEO 🧾</button>';
            echo '</form>';
        }
        echo '<div class="description">Shortcode do strony sprzedażowej: <code>[hds3_cennik product="' . hds3_safe_html($product) . '"]</code></div>';
        echo '</div>';

        echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hds3_save_catalog" />';
        echo '<input type="hidden" name="product" value="' . hds3_safe_attr($product) . '" />';
        wp_nonce_field('hds3_save_catalog');
        echo '<p><textarea name="catalog_json" style="width:100%;min-height:220px;font-family:ui-monospace,Menlo,Consolas,monospace">' . esc_textarea($json) . '</textarea></p>';
        echo '<p class="description">Wskazówka: ustaw <code>cta_url</code> na docelowy link (np. kontakt/checkout). JSON jest walidowany przed zapisem.</p>';
        echo '<p><button class="button button-primary">Zapisz katalog</button></p>';
        echo '</form>';
    }

    public static function save_catalog() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_save_catalog');

        $product = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', (string)($_POST['product'] ?? '')));
        if (!$product) $product = 'SEO';

        $json = (string)($_POST['catalog_json'] ?? '');
        $data = hds3_json_decode($json, null);
        if (!is_array($data)) {
            wp_die('Błędny JSON. Nie zapisano.');
        }

        $raw = HDS3_Settings::get('hds3_catalog', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];

        $cfg[$product] = $data;
        HDS3_Settings::set('hds3_catalog', hds3_json_encode($cfg));
        HDS3_Logger::event('plans','info','Zapisano katalog produktu',['product'=>$product]);

        wp_redirect(add_query_arg(['page'=>'hds3_plans','addon_product'=>$product,'catalog_saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function load_seo_catalog_preset() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_load_seo_catalog_preset');

        $product = 'SEO';
        $raw = HDS3_Settings::get('hds3_catalog', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];

        $cfg[$product] = hds3_default_catalog_seo();
        HDS3_Settings::set('hds3_catalog', hds3_json_encode($cfg));
        HDS3_Logger::event('plans','info','Wczytano preset katalogu SEO',['product'=>$product]);

        wp_redirect(add_query_arg(['page'=>'hds3_plans','addon_product'=>$product,'catalog_preset'=>1], admin_url('admin.php')));
        exit;
    }

    public static function save_addons() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_save_addons');

        $product = sanitize_text_field($_POST['product'] ?? 'CORE');
        if (!$product) $product = 'CORE';

        $raw = HDS3_Settings::get('hds3_addons', '');
        $cfg = hds3_json_decode(is_string($raw) ? $raw : '', []);
        if (!is_array($cfg)) $cfg = [];
        if (!isset($cfg[$product]) || !is_array($cfg[$product])) $cfg[$product] = [];

        $enabled = isset($_POST['enabled']) ? (array)$_POST['enabled'] : [];
        $maxi = isset($_POST['max_instances']) ? (array)$_POST['max_instances'] : [];
        $note = isset($_POST['note']) ? (array)$_POST['note'] : [];

        $plans = HDS3_Plans::list_plans(false);
        foreach ($plans as $pl) {
            $k = (string)($pl['plan_key'] ?? '');
            if (!$k) continue;
            $cfg[$product][$k] = [
                'enabled' => !empty($enabled[$k]) ? 1 : 0,
                'max_instances' => isset($maxi[$k]) ? max(0, (int)$maxi[$k]) : 0,
                'note' => isset($note[$k]) ? sanitize_text_field($note[$k]) : '',
                'updated_at' => hds3_now_gmt(),
            ];
        }

        HDS3_Settings::set('hds3_addons', hds3_json_encode($cfg));
        HDS3_Logger::event('plans','info','Zapisano pakiety dodatkowe',['product'=>$product]);

        wp_redirect(add_query_arg(['page'=>'hds3_plans','addon_product'=>$product,'addons_saved'=>1], admin_url('admin.php')));
        exit;
    }

    /**
     * Wykrywa produkty na podstawie licencji/instancji oraz znanych domyślnych etykiet.
     */
    private static function discover_products(): array {
        $set = [];
        foreach (self::product_catalog() as $key => $product) {
            if (($product['status'] ?? 'active') === 'active') $set[$key] = true;
        }

        if (HDS3_Settings::module_enabled('licenses')) {
            global $wpdb;
            $lt = HDS3_DB::table('licenses');
            $it = HDS3_DB::table('instances');
            $lrows = $wpdb->get_col("SELECT DISTINCT product FROM $lt WHERE product IS NOT NULL AND product<>'' LIMIT 200");
            $irows = $wpdb->get_col("SELECT DISTINCT product FROM $it WHERE product IS NOT NULL AND product<>'' LIMIT 200");
            foreach (array_merge((array)$lrows,(array)$irows) as $p) {
                $p = strtoupper(trim((string)$p));
                if (!$p) continue;
                $p = preg_replace('/[^A-Z0-9_\-]/', '', $p);
                if (!$p) continue;
                $set[$p] = true;
            }
        }

        $out = array_keys($set);
        sort($out);
        return $out;
    }

    // Dynamiczny katalog produktów: niezależny od licencji, bez preinstalowanych produktów.
    private static function product_catalog(): array {
        $products = get_option('hds3_product_catalog_v1', []);
        return is_array($products) ? $products : [];
    }

    public static function product_save() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        check_admin_referer('hds3_product_save');
        $key = strtoupper(sanitize_key(wp_unslash($_POST['product_key'] ?? '')));
        $key = preg_replace('/[^A-Z0-9_]/', '', $key);
        $name = sanitize_text_field(wp_unslash($_POST['product_name'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['product_description'] ?? ''));
        $status = sanitize_key(wp_unslash($_POST['product_status'] ?? 'active'));
        if (!in_array($status, ['active','archived'], true)) $status = 'active';
        if (!$key || strlen($key)>40 || !$name || strlen($name)>150) {
            wp_safe_redirect(admin_url('admin.php?page=hds3_products&error=invalid')); exit;
        }
        $catalog = self::product_catalog();
        $catalog[$key] = [
            'name'=>$name, 'description'=>$description, 'status'=>$status,
            'updated_at'=>current_time('mysql', true),
        ];
        update_option('hds3_product_catalog_v1', $catalog, false);
        wp_safe_redirect(admin_url('admin.php?page=hds3_products&updated=1')); exit;
    }

    public static function product_archive() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        check_admin_referer('hds3_product_archive');
        $key = strtoupper(sanitize_key(wp_unslash($_POST['product_key'] ?? '')));
        $catalog = self::product_catalog();
        if (isset($catalog[$key])) {
            $catalog[$key]['status'] = 'archived';
            $catalog[$key]['updated_at'] = current_time('mysql', true);
            update_option('hds3_product_catalog_v1', $catalog, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=hds3_products&updated=1')); exit;
    }

    public static function page_products() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        self::header('Produkty', 'Własny katalog produktów i usług. Nowa instalacja rozpoczyna bez gotowych pozycji.');
        $catalog = self::product_catalog();
        if (isset($_GET['updated'])) echo '<div class="notice notice-success inline"><p>Zapisano katalog produktów.</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error inline"><p>Podaj poprawny identyfikator i nazwę produktu.</p></div>';
        echo '<div class="hds3-card"><h2>Katalog produktów</h2><p>Identyfikator produktu jest stały i służy do powiązania licencji. Archiwizacja nie usuwa istniejących licencji ani konfiguracji.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Identyfikator</th><th>Nazwa</th><th>Opis</th><th>Status</th><th>Akcja</th></tr></thead><tbody>';
        foreach ($catalog as $key=>$product) {
            echo '<tr><td><code>'.esc_html($key).'</code></td><td>'.esc_html($product['name'] ?? '').'</td><td>'.esc_html($product['description'] ?? '').'</td><td>'.esc_html($product['status'] ?? 'active').'</td><td>';
            if (($product['status'] ?? '') === 'active') {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('hds3_product_archive');
                echo '<input type="hidden" name="action" value="hds3_product_archive"><input type="hidden" name="product_key" value="'.esc_attr($key).'"><button type="submit" class="button">Archiwizuj</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$catalog) echo '<tr><td colspan="5">Brak produktów. Dodaj pierwszy produkt poniżej.</td></tr>';
        echo '</tbody></table></div>';
        echo '<div class="hds3-card" style="margin-top:20px"><h2>Dodaj lub edytuj produkt</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('hds3_product_save');
        echo '<input type="hidden" name="action" value="hds3_product_save">';
        echo '<table class="form-table"><tr><th><label for="hds3_product_key">Identyfikator</label></th><td><input id="hds3_product_key" name="product_key" maxlength="40" required pattern="[A-Za-z0-9_]+" class="regular-text" placeholder="MOJ_PRODUKT"><p class="description">Istniejący identyfikator aktualizuje produkt. Nie zmieniaj go po wydaniu licencji.</p></td></tr>';
        echo '<tr><th><label for="hds3_product_name">Nazwa</label></th><td><input id="hds3_product_name" name="product_name" maxlength="150" required class="regular-text" placeholder="Moja usługa"></td></tr>';
        echo '<tr><th><label for="hds3_product_description">Opis</label></th><td><textarea id="hds3_product_description" name="product_description" rows="3" class="large-text"></textarea></td></tr>';
        echo '<tr><th>Status</th><td><select name="product_status"><option value="active">Aktywny</option><option value="archived">Zarchiwizowany</option></select></td></tr></table>';
        echo '<p><button type="submit" class="button button-primary">Zapisz produkt</button></p></form></div>';
        echo '<p>Po utworzeniu produktu skonfiguruj jego pakiety i katalog w sekcji <a href="'.esc_url(admin_url('admin.php?page=hds3_plans')).'">Plany</a>.</p>';
        self::footer();
    }

    public static function page_updates() {
        self::header('Aktualizacje', 'Stan mechanizmów aktualizacji HDS3 i diagnostyka błędów instalatora.');
        // UI kart aktualizacji ładowany globalnie z assets/admin.css.

        $la_url = trim((string) HDS3_Settings::get('la_url',''));
        $la_ping = trim((string) HDS3_Settings::get('la_ping_path','/ping'));
        $engine_last_error = (string) get_option('hds3_engine_install_last_error', '');
        $engine_installed_version = (string) get_option('hds3_engine_installed_version', '');
        $engine_last_run = (string) get_option('hds3_engine_install_last_run', '');
        $engine_state = $engine_last_error !== '' ? 'Błąd' : ($engine_installed_version !== '' ? 'OK' : 'Nie uruchomiono');

        echo '<div style="max-width:1200px">';

        echo '<div class="hds3-card" style="margin-bottom:14px">';
        echo '<div class="hds3-card__head"><h3 class="hds3-card__title">Status instalatora</h3></div>';
        echo '<div class="hds3-card__body">';
        echo '<div class="hds3-metrics">';
        echo '<div class="hds3-metric"><b>' . hds3_safe_html($engine_state) . '</b><span class="hds3-muted">stan instalatora</span></div>';
        echo '<div class="hds3-metric"><b>' . hds3_safe_html($engine_installed_version ?: '—') . '</b><span class="hds3-muted">wersja engine</span></div>';
        echo '<div class="hds3-metric"><b>' . hds3_safe_html($engine_last_run ?: '—') . '</b><span class="hds3-muted">ostatni przebieg</span></div>';
        echo '</div>';
        if ($engine_last_error !== '') {
            echo '<div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-left:4px solid #d63638;padding:12px;border-radius:12px;margin-top:12px">';
            echo '<p style="margin:0 0 6px"><b>Ostatni błąd instalatora</b></p>';
            echo '<pre style="margin:0;white-space:pre-wrap">' . hds3_safe_html($engine_last_error) . '</pre>';
            echo '</div>';
        } else {
            echo '<p class="hds3-muted" style="margin-top:12px">Brak zapisanego błędu instalatora.</p>';
        }
        echo '</div></div>';

        echo '<div class="hds3-card" style="margin-bottom:14px">';
        echo '<div class="hds3-card__head"><h3 class="hds3-card__title">License API</h3></div>';
        echo '<div class="hds3-card__body">';
        echo '<p><b>URL:</b> <code>' . hds3_safe_html($la_url ?: 'brak') . '</code></p>';
        echo '<p><b>Ping path:</b> <code>' . hds3_safe_html($la_ping ?: '/ping') . '</code></p>';
        echo '<p class="hds3-muted">Test i konfigurację endpointu wykonasz w zakładce Ustawienia.</p>';
        echo '<p><a class="button" href="' . hds3_safe_url(hds3_admin_url('hds3_settings')) . '">Otwórz Ustawienia</a></p>';
        echo '</div></div>';

        echo '</div>';
        self::footer();
    }

    public static function page_tokens() {
        if (!HDS3_Settings::module_enabled('tenants')) {
            self::header('Tokeny', 'Moduł tenantów jest wyłączony.');
            self::footer();
            return;
        }
        self::header('Tokeny', 'Lista tokenów tenantów. Możesz je przeglądać, cofać lub usuwać. Nowe tokeny twórz przez Provisioning.');
        if (!empty($_GET['hds3_deleted'])) {
            echo '<div class="updated notice"><p>Token został usunięty.</p></div>';
        }
        if (!empty($_GET['hds3_revoked'])) {
            echo '<div class="updated notice"><p>Token został cofnięty.</p></div>';
        }
        $rows = HDS3_Tokens::list_tokens();
        echo '<div class="postbox" style="padding:14px;max-width:1200px">';
        echo '<p class="description">Token jest widoczny tylko jako hash. Jeśli potrzebujesz nowego plain tokenu, utwórz go ponownie w Provisioning.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Tenant</th><th>Etykieta</th><th>Status</th><th>Scope</th><th>Utworzono</th><th>Ostatnie użycie</th><th>Hash</th><th>Akcje</th></tr></thead><tbody>';
        foreach ((array)$rows as $r) {
            $scopes = [];
            if (!empty($r['scopes'])) {
                $tmp = hds3_json_decode((string)$r['scopes'], []);
                if (is_array($tmp)) $scopes = $tmp;
            }
            $hash = (string)($r['token_hash'] ?? '');
            $hash_short = $hash ? substr($hash, 0, 12) . '…' . substr($hash, -8) : '—';
            echo '<tr>';
            echo '<td>' . hds3_safe_html((string)($r['id'] ?? '')) . '</td>';
            echo '<td><code>' . hds3_safe_html((string)($r['tenant_id'] ?? '')) . '</code></td>';
            echo '<td>' . hds3_safe_html((string)($r['label'] ?? '')) . '</td>';
            echo '<td>' . hds3_safe_html((string)($r['status'] ?? '')) . '</td>';
            echo '<td>' . hds3_safe_html($scopes ? implode(', ', $scopes) : '—') . '</td>';
            echo '<td>' . hds3_safe_html((string)($r['created_at'] ?? '')) . '</td>';
            echo '<td>' . hds3_safe_html((string)($r['last_used_at'] ?? '')) . '</td>';
            echo '<td><code>' . hds3_safe_html($hash_short) . '</code></td>';
            echo '<td>';
            if ((string)($r['status'] ?? '') === 'active') {
                echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 0 0">';
                echo '<input type="hidden" name="action" value="hds3_revoke_tenant_token" />';
                echo '<input type="hidden" name="token_id" value="' . hds3_safe_attr((string)($r['id'] ?? '0')) . '" />';
                echo wp_nonce_field('hds3_revoke_tenant_token','hds3_revoke_tenant_token_nonce', true, false);
                echo '<button class="button" onclick="return confirm(&quot;Cofnąć ten token?&quot;)">Cofnij</button>';
                echo '</form>';
            }
            echo '<form method="post" action="' . hds3_safe_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0">';
            echo '<input type="hidden" name="action" value="hds3_token_delete" />';
            echo '<input type="hidden" name="token_id" value="' . hds3_safe_attr((string)($r['id'] ?? '0')) . '" />';
            echo wp_nonce_field('hds3_token_delete','_wpnonce', true, false);
            echo '<button class="button button-link-delete" onclick="return confirm(&quot;Usunąć token?&quot;)">Usuń</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        if (empty($rows)) {
            echo '<tr><td colspan="9">Brak tokenów.</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        self::footer();
    }

    public static function create_tenant_token() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('hds3_create_tenant_token', 'hds3_create_tenant_token_nonce');
        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        $label = sanitize_text_field($_POST['token_label'] ?? '');
        $scopes = (array)($_POST['scopes'] ?? []);
        if ($tenant_id !== '') {
            $created = HDS3_Tokens::create($tenant_id, $scopes, $label);
            if (!is_wp_error($created)) {
                HDS3_Settings::set('flash_provision', hds3_json_encode([
                    'message' => 'Utworzono dodatkowy token dla tenant: <code>' . hds3_safe_html($tenant_id) . '</code>.',
                    'token' => (string)($created['token'] ?? ''),
                ]));
                wp_safe_redirect(hds3_admin_url('hds3_provisioning'));
                exit;
            }
        }
        wp_safe_redirect(hds3_admin_url('hds3_tokens'));
        exit;
    }

    public static function revoke_tenant_token() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('hds3_revoke_tenant_token', 'hds3_revoke_tenant_token_nonce');
        $id = (int)($_POST['token_id'] ?? 0);
        if ($id > 0) {
            HDS3_Tokens::revoke($id);
            HDS3_Logger::event('tenants','warning','Cofnięto token', ['id'=>$id]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_tokens','hds3_revoked'=>1], admin_url('admin.php')));
        exit;
    }

    public static function token_delete() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnien.');
        check_admin_referer('hds3_token_delete');
        $id = (int)($_POST['token_id'] ?? 0);
        if ($id > 0) {
            HDS3_Tokens::hard_delete($id);
            HDS3_Logger::event('tenants','warning','Usunieto token', ['id'=>$id]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_tokens','hds3_deleted'=>1], admin_url('admin.php')));
        exit;
    }

    public static function tenant_revoke() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnien.');
        check_admin_referer('hds3_tenant_revoke');
        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        if ($tenant_id) {
            HDS3_Tenants::soft_revoke($tenant_id, $reason);
            HDS3_Logger::event('tenants','warning','Zawieszono tenant (soft revoke)', ['tenant_id'=>$tenant_id,'reason'=>$reason]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_tenants','revoked'=>1], admin_url('admin.php')));
        exit;
    }

    public static function tenant_delete() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnien.');
        check_admin_referer('hds3_tenant_delete');
        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        if ($tenant_id) {
            $out = HDS3_Tenants::hard_delete($tenant_id);
            HDS3_Logger::event('tenants','warning','Usunieto tenant (hard delete)', $out);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_tenants','deleted'=>1], admin_url('admin.php')));
        exit;
    }

    public static function connector_revoke() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnien.');
        if (!wp_verify_nonce($_POST['hds3_connector_revoke_nonce'] ?? '', 'hds3_connector_revoke')) wp_die('Bledny nonce.');
        $id = (int)($_POST['id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        if ($id > 0) {
            $out = HDS3_Agency::revoke_connector($id, $reason);
            HDS3_Logger::event('agency','warning','Revoke konektora (soft)', $out);
        }
        wp_safe_redirect(add_query_arg(['page'=>'hds3_connectors','revoked'=>1], admin_url('admin.php')));
        exit;
    }
    /**
     * Badge limitów domen dla licencji agencji (used/max).
     * Cache per parent_license_key dla wydajności listy.
     */
    public static function connector_limits_badge(string $parent_license_key, array &$limits_cache): string {
        $parent_license_key = strtoupper(preg_replace('/[^A-Z0-9\-_]/','', (string)$parent_license_key));
        if (!$parent_license_key) return '<span class="hds3-badge hds3-badge--muted">—</span>';

        if (!isset($limits_cache[$parent_license_key])) {
            $limits_cache[$parent_license_key] = HDS3_Agency::get_agency_domain_limits($parent_license_key);
        }
        $lim = $limits_cache[$parent_license_key];
        $max = (int)($lim['max_domains'] ?? 0);
        $used = (int)($lim['used_domains'] ?? 0);

        if ($max <= 0) return '<span class="hds3-badge hds3-badge--muted">—</span>';

        $cls = 'hds3-badge';
        if ($used >= $max) $cls .= ' hds3-badge--danger';
        else if ($used > 0) $cls .= ' hds3-badge--ok';
        else $cls .= ' hds3-badge--muted';

        return '<span class="'.hds3_safe_attr($cls).'">'.hds3_safe_html($used.' / '.$max).'</span>';
    }

}
