<?php
if (!defined('ABSPATH')) exit;
/** Isolated compatibility surface for pre-universal SEO / Agency integrations. */
final class HDS3_Legacy_Compat {
    const OPTION = 'hds3_legacy_integrations_enabled';
    // Default ON preserves upgrades. Disable explicitly after integration inventory.
    public static function enabled(): bool { return (string) get_option(self::OPTION, '1') === '1'; }
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_hds3_legacy_compat_save', [__CLASS__, 'save']);
    }
    public static function menu(): void {
        add_submenu_page('hds3_center', 'Integracje starszego systemu', 'Integracje legacy', 'manage_options', 'hds3_legacy_compat', [__CLASS__, 'render']);
    }
    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        check_admin_referer('hds3_legacy_compat_save');
        $enabled = isset($_POST['legacy_enabled']) && (string)wp_unslash($_POST['legacy_enabled']) === '1';
        update_option(self::OPTION, $enabled ? '1' : '0', false);
        wp_safe_redirect(add_query_arg(['page'=>'hds3_legacy_compat','updated'=>'1'], admin_url('admin.php')));
        exit;
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        echo '<div class="wrap"><h1>Integracje starszego systemu (SEO / Agency)</h1>';
        if (isset($_GET['updated'])) echo '<div class="notice notice-success"><p>Zapisano ustawienie. Sprawdź działanie integracji.</p></div>';
        echo '<p>Starsze integracje nie należą do uniwersalnego rdzenia. Wyłączenie zatrzyma ich rejestrację REST i ekran SEO, ale nie usuwa danych ani kluczy.</p>';
        echo '<p><strong>Uwaga:</strong> wyłączenie może zatrzymać istniejących klientów SEO/Agency. Przed zmianą wykonaj kopię zapasową i zinwentaryzuj używane endpointy.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_legacy_compat_save">';
        wp_nonce_field('hds3_legacy_compat_save');
        echo '<label><input type="checkbox" name="legacy_enabled" value="1" '.checked(self::enabled(), true, false).'> Włącz zgodność SEO / Agency</label>';
        submit_button('Zapisz konfigurację');
        echo '</form><p>Domyślnie włączona dla kompatybilności istniejących instalacji. Nowe produkty i plany pozostają niezależne od tego przełącznika.</p></div>';
    }
}
HDS3_Legacy_Compat::init();
