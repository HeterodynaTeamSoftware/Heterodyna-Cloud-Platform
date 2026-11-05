<?php
if (!defined('ABSPATH')) exit;
/** Optional, re-entrant setup navigation. No automatic data seeding. */
final class HDS3_Setup_Wizard {
    const OPTION = 'hds3_platform_name_v1';
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 25);
        add_action('admin_post_hds3_setup_save', [__CLASS__, 'save']);
    }
    public static function menu(): void {
        add_submenu_page('hds3_center', 'Pierwsza konfiguracja', 'Pierwsza konfiguracja', 'manage_options', 'hds3_setup', [__CLASS__, 'page']);
    }
    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        check_admin_referer('hds3_setup_save');
        $name = sanitize_text_field(wp_unslash($_POST['platform_name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            wp_safe_redirect(add_query_arg(['page'=>'hds3_setup','status'=>'invalid'], admin_url('admin.php'))); exit;
        }
        update_option(self::OPTION, $name, false);
        wp_safe_redirect(add_query_arg(['page'=>'hds3_setup','status'=>'saved'], admin_url('admin.php'))); exit;
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        $products = get_option('hds3_product_catalog_v1', []);
        $products = is_array($products) ? $products : [];
        $active_products = count(array_filter($products, static function($p) { return is_array($p) && ($p['status']??'active')==='active'; }));
        $plans = class_exists('HDS3_Plans') ? HDS3_Plans::list_plans(true) : [];
        $active_plans = is_array($plans) ? count($plans) : 0;
        $offers = get_option('hds3_pricing_offers_v1', []);
        $offers = is_array($offers) ? $offers : [];
        $active_offers = count(array_filter($offers, static function($p) { return is_array($p) && ($p['status']??'active')==='active'; }));
        $steps = [
            ['Nazwa Twojej platformy', true, 'hds3_setup', 'Dostosuj nazwę wyświetlaną w panelu.'],
            ['Produkty', $active_products>0, 'hds3_products', 'Utwórz przynajmniej jeden aktywny produkt.'],
            ['Plany i limity', $active_plans>0, 'hds3_plans', 'Zdefiniuj plan własnej usługi.'],
            ['Cenniki i oferty', $active_offers>0, 'hds3_pricing', 'Skonfiguruj ofertę; bez pobierania płatności.'],
        ];
        $done = (int)($active_products>0)+(int)($active_plans>0)+(int)($active_offers>0)+ (int)(get_option(self::OPTION, '')!=='');
        $notice = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        echo '<div class="wrap hds3-setup"><h1>Konfiguracja Twojego SaaS</h1>';
        echo '<p>Każda instalacja tworzy własny katalog. Kreator nie dodaje automatycznie produktów, klientów, planów ani cenników.</p>';
        if ($notice==='saved') echo '<div class="notice notice-success"><p>Nazwa platformy została zapisana.</p></div>';
        if ($notice==='invalid') echo '<div class="notice notice-error"><p>Podaj nazwę od 1 do 100 znaków.</p></div>';
        echo '<div class="hds3-setup-panel"><h2>Tożsamość platformy</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hds3_setup_save">'; wp_nonce_field('hds3_setup_save');
        echo '<label for="hds3-platform-name">Nazwa wyświetlana w konsoli</label><p><input id="hds3-platform-name" class="regular-text" name="platform_name" maxlength="100" required value="'.esc_attr(get_option(self::OPTION, 'Heterodyna Cloud')).'"></p>';
        echo '<p><button type="submit" class="button button-primary">Zapisz nazwę</button></p></form></div>';
        echo '<h2>Etapy konfiguracji — '.esc_html((string)$done).' / 4</h2><div class="hds3-setup-steps">';
        foreach ($steps as $i=>$step) {
            $status = $i===0 ? (get_option(self::OPTION, '')!=='' ) : $step[1];
            echo '<div class="hds3-setup-step"><span class="hds3-setup-state">'.($status?'✓ Gotowe':'○ Do konfiguracji').'</span><div><strong>'.esc_html($step[0]).'</strong><p>'.esc_html($step[3]).'</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page='.$step[2])).'">'.($status?'Edytuj':'Konfiguruj').'</a></div>';
        }
        echo '</div><p><small>Stan kroków jest obliczany z zapisanych danych. Zmiany nie usuwają wcześniejszych licencji ani subskrypcji.</small></p></div>';
    }
}
