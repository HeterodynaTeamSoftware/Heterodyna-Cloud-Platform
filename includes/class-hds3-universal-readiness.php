<?php
if (!defined('ABSPATH')) exit;
/** Read-only diagnostics of a clean, self-configurable installation. */
final class HDS3_Universal_Readiness {
    public static function init(): void { add_action('admin_menu', [__CLASS__, 'menu']); }
    public static function menu(): void {
        add_submenu_page('hds3_center', 'Gotowość open source', 'Gotowość instalacji', 'manage_options', 'hds3_universal_readiness', [__CLASS__, 'render']);
    }
    private static function count_rows(string $name): ?int {
        global $wpdb;
        $table = HDS3_DB::table($name);
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) return null;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) return null;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.$table.'`');
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response' => 403]);
        $legacy = class_exists('HDS3_Legacy_Compat') ? HDS3_Legacy_Compat::enabled() : null;
        $checks = [
            ['Katalog produktów', self::count_rows('products'), 'Dodaj własne produkty w katalogu.'],
            ['Plany', self::count_rows('plans'), 'Zdefiniuj własne plany.'],
            ['Organizacje', self::count_rows('tenants'), 'Organizacje mogą powstać po konfiguracji oferty.'],
        ];
        echo '<div class="wrap"><h1>Heterodyna Cloud — gotowość instalacji open source</h1>';
        echo '<p>Odczyt stanu instalacji. Ten ekran nie modyfikuje bazy i nie uruchamia resetu.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Obszar</th><th>Stan</th><th>Opis</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            $value = $check[1] === null ? 'Brak tabeli — sprawdź instalację' : (string)$check[1] . ' rekordów';
            echo '<tr><td>'.esc_html($check[0]).'</td><td>'.esc_html($value).'</td><td>'.esc_html($check[2]).'</td></tr>';
        }
        echo '<tr><td>Integracje starszego systemu</td><td>'.esc_html($legacy === null ? 'Niezweryfikowane' : ($legacy ? 'Włączone' : 'Wyłączone')).'</td><td>W czystej instalacji zalecane wyłączenie SEO/Agency.</td></tr>';
        echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=hds3_setup_wizard')).'">Kreator konfiguracji</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=hds3_data_reset')).'">Centrum danych i reset</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=hds3_legacy_compat')).'">Integracje legacy</a></p>';
        echo '<p><strong>Uwaga:</strong> liczniki nie stanowią testów bezpieczeństwa ani sprawdzenia integralności danych. Przed publicznym wdrożeniem wymagane są testy WordPress/MySQL.</p></div>';
    }
}
HDS3_Universal_Readiness::init();
