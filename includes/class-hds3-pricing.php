<?php
if (!defined('ABSPATH')) exit;
/** Administrator-defined pricing catalog. Stores decimal amounts as minor units. No payment processing. */
final class HDS3_Pricing {
    const OPTION = 'hds3_pricing_offers_v1';
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_post_hds3_pricing_save', [__CLASS__, 'save']);
        add_action('admin_post_hds3_pricing_archive', [__CLASS__, 'archive']);
    }
    public static function offers() {
        $list = get_option(self::OPTION, []);
        return is_array($list) ? $list : [];
    }
    public static function menu() {
        add_submenu_page('hds3_center', 'Cenniki i oferty', 'Cenniki', 'manage_options', 'hds3_pricing', [__CLASS__, 'page']);
    }
    private static function back($status) {
        wp_safe_redirect(add_query_arg(['page'=>'hds3_pricing', 'pricing_notice'=>$status], admin_url('admin.php')));
        exit;
    }
    private static function valid_catalog() {
        $products=get_option('hds3_product_catalog_v1', []);
        return is_array($products) ? $products : [];
    }
    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        check_admin_referer('hds3_pricing_save');
        $data=wp_unslash($_POST);
        $product=strtoupper(sanitize_text_field($data['product'] ?? ''));
        $plan=sanitize_key($data['plan'] ?? '');
        $currency=strtoupper(sanitize_text_field($data['currency'] ?? ''));
        $interval=sanitize_key($data['interval'] ?? '');
        $raw=trim((string)($data['price'] ?? ''));
        $title=sanitize_text_field($data['title'] ?? '');
        $products=self::valid_catalog();
        $plans=HDS3_Plans::list_plans(false);
        $active_plan=false;
        foreach ($plans as $p) if ((string)($p['plan_key']??'')===$plan && ($p['status']??'active')==='active') $active_plan=true;
        if (!isset($products[$product]) || ($products[$product]['status']??'active')!=='active' || !$active_plan || !preg_match('/^[A-Z]{3}$/D', $currency) || !in_array($interval, ['month','year','one_time'], true) || !preg_match('/^(0|[0-9]{1,8})(\.[0-9]{1,2})?$/D', $raw) || $title==='' || strlen($title)>150) self::back('invalid');
        $parts=explode('.', $raw, 2);
        $minor=((int)$parts[0])*100 + (int)str_pad($parts[1]??'', 2, '0');
        if ($minor>9999999999) self::back('invalid');
        $id=sanitize_key($data['offer_id']??'');
        $offers=self::offers();
        if ($id!=='' && !isset($offers[$id])) self::back('invalid');
        if ($id==='') $id=wp_generate_uuid4();
        $original=$offers[$id]??[];
        $offers[$id]=['id'=>$id,'product'=>$product,'plan'=>$plan,'currency'=>$currency,'interval'=>$interval,'amount_minor'=>$minor,'title'=>$title,'status'=>'active','created_at'=>$original['created_at']??current_time('mysql', true),'updated_at'=>current_time('mysql', true)];
        update_option(self::OPTION, $offers, false);
        self::back('saved');
    }
    public static function archive() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        check_admin_referer('hds3_pricing_archive');
        $id=sanitize_key(wp_unslash($_POST['offer_id']??''));
        $offers=self::offers();
        if (!isset($offers[$id])) self::back('invalid');
        $offers[$id]['status']='archived';
        $offers[$id]['updated_at']=current_time('mysql', true);
        update_option(self::OPTION, $offers, false);
        self::back('archived');
    }
    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
        $products=array_filter(self::valid_catalog(), static function($p){return ($p['status']??'active')==='active';});
        $plans=array_filter(HDS3_Plans::list_plans(false), static function($p){return ($p['status']??'active')==='active';});
        $offers=self::offers();
        $notice=sanitize_key(wp_unslash($_GET['pricing_notice']??''));
        echo '<div class="wrap hds3-pricing"><h1>Cenniki i oferty</h1><p>Konfiguruj własne ceny produktów i planów. Ten moduł nie pobiera płatności ani nie wystawia faktur.</p>';
        if ($notice) echo '<div class="notice '.($notice==='invalid'?'notice-error':'notice-success').'"><p>'.esc_html(['saved'=>'Oferta zapisana.','archived'=>'Oferta zarchiwizowana.','invalid'=>'Nieprawidłowe dane. Zmiany nie zostały zapisane.'][$notice]??'').'</p></div>';
        echo '<h2>Dodaj ofertę</h2>';
        if (!$products || !$plans) echo '<p>Najpierw dodaj aktywny produkt w sekcji Produkty oraz aktywny plan w sekcji Plany.</p>';
        else {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="hds3-pricing-form"><input type="hidden" name="action" value="hds3_pricing_save">'; wp_nonce_field('hds3_pricing_save');
            echo '<label>Nazwa oferty <input required maxlength="150" name="title" placeholder="Przykładowa oferta"></label>';
            echo '<label>Produkt <select name="product" required>';
            foreach ($products as $key=>$p) echo '<option value="'.esc_attr($key).'">'.esc_html(($p['name']??$key).' ('.$key.')').'</option>';
            echo '</select></label><label>Plan <select name="plan" required>';
            foreach ($plans as $p) echo '<option value="'.esc_attr($p['plan_key']).'">'.esc_html($p['name']??$p['plan_key']).'</option>';
            echo '</select></label><label>Waluta (ISO 4217) <input name="currency" value="PLN" pattern="[A-Za-z]{3}" maxlength="3" required></label>';
            echo '<label>Cena <input name="price" type="text" pattern="[0-9]+([.][0-9]{1,2})?" value="0.00" required></label>';
            echo '<label>Okres <select name="interval"><option value="month">Miesięcznie</option><option value="year">Rocznie</option><option value="one_time">Jednorazowo</option></select></label>';
            echo '<p><button class="button button-primary">Dodaj ofertę</button></p></form>';
        }
        echo '<h2>Zapisane oferty</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Nazwa</th><th>Produkt</th><th>Plan</th><th>Cena</th><th>Okres</th><th>Status</th><th>Akcja</th></tr></thead><tbody>';
        foreach ($offers as $id=>$o) {
            echo '<tr><td>'.esc_html($o['title']??'').'</td><td>'.esc_html($o['product']??'').'</td><td>'.esc_html($o['plan']??'').'</td><td>'.esc_html(number_format(($o['amount_minor']??0)/100, 2, '.', ' ').' '.($o['currency']??'')).'</td><td>'.esc_html($o['interval']??'').'</td><td>'.esc_html($o['status']??'').'</td><td>';
            if (($o['status']??'')==='active') {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hds3_pricing_archive"><input type="hidden" name="offer_id" value="'.esc_attr($id).'">'; wp_nonce_field('hds3_pricing_archive'); echo '<button class="button">Archiwizuj</button></form>';}
            echo '</td></tr>';
        }
        if (!$offers) echo '<tr><td colspan="7">Brak ofert. Dodaj pierwszą własną ofertę powyżej.</td></tr>';
        echo '</tbody></table></div></div>';
    }
}
