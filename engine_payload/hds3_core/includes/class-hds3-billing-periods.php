<?php
if (!defined('ABSPATH')) exit;
/** Billing-period usage snapshots. No charging, tax invoices, or payment provider integration. */
final class HDS3_Billing_Periods {
    const SCHEMA = '03.00.160';
    private static function table() { return HDS3_DB::table('billing_periods'); }
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tenant_id varchar(64) NOT NULL,
            month_key char(7) NOT NULL,
            subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
            plan_key varchar(64) NOT NULL DEFAULT '',
            subscription_status varchar(24) NOT NULL DEFAULT '',
            usage_json longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_month (tenant_id,month_key),
            KEY created_at (created_at)
        ) $charset;");
        update_option('hds3_billing_periods_schema',self::SCHEMA,false);
    }
    public static function maybe_install() {
        if (get_option('hds3_billing_periods_schema')!==self::SCHEMA) self::install();
    }
    public static function bounds($month) {
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) return null;
        $first=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$month.'-01 00:00:00',new DateTimeZone('UTC'));
        if (!$first || $first->format('Y-m')!==$month) return null;
        return [$first->format('Y-m-d H:i:s'),$first->modify('+1 month')->format('Y-m-d H:i:s')];
    }
    public static function create_draft($tenant_id,$month) {
        if (!current_user_can('manage_options')) return new WP_Error('billing_forbidden','Brak uprawnień');
        if (!is_string($tenant_id) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',$tenant_id)) return new WP_Error('billing_tenant','Nieprawidłowa organizacja');
        $bounds=self::bounds($month);
        if (!$bounds) return new WP_Error('billing_month','Nieprawidłowy miesiąc');
        // Close only finished UTC months: incomplete data should not be snapshotted silently.
        if ($bounds[1] > current_time('mysql',true)) return new WP_Error('billing_future','Można zamknąć tylko zakończony miesiąc UTC');
        global $wpdb;
        $tenants=HDS3_DB::table('tenants');
        if (!$wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM $tenants WHERE tenant_id=%s LIMIT 1",$tenant_id))) return new WP_Error('billing_missing_tenant','Nie istnieje taka organizacja');
        $subscription=HDS3_Subscriptions::get($tenant_id);
        if (!$subscription) return new WP_Error('billing_no_subscription','Organizacja nie ma wpisu subskrypcji');
        $t=self::table();
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE tenant_id=%s AND month_key=%s LIMIT 1",$tenant_id,$month));
        if ($existing) return new WP_Error('billing_duplicate','Istnieje już zestawienie dla tego okresu');
        $snapshot=HDS3_Usage::totals($tenant_id,$bounds[0],$bounds[1]);
        ksort($snapshot);
        $json=wp_json_encode(['period_start_utc'=>$bounds[0],'period_end_exclusive_utc'=>$bounds[1],'metrics'=>$snapshot,'coverage'=>'only_events_recorded_in_usage_ledger','pricing'=>'not_configured','payment_status'=>'not_tracked']);
        if (!is_string($json)) return new WP_Error('billing_json','Błąd serializacji');
        $ok=$wpdb->insert($t,[
            'tenant_id'=>$tenant_id,'month_key'=>$month,
            'subscription_id'=>(int)$subscription['id'],'plan_key'=>(string)$subscription['plan_key'],
            'subscription_status'=>(string)$subscription['status'], 'usage_json'=>$json,
            'created_by'=>get_current_user_id(), 'created_at'=>current_time('mysql',true)
        ],['%s','%s','%d','%s','%s','%s','%d','%s']);
        if (!$ok) return new WP_Error('billing_insert','Nie zapisano zestawienia; być może okres został już zamknięty');
        if (class_exists('HDS3_Logger')) HDS3_Logger::event('billing','info','Utworzono robocze zestawienie zużycia',['tenant_id'=>$tenant_id,'month'=>$month]);
        return (int)$wpdb->insert_id;
    }
    public static function admin_menu() {
        add_submenu_page('tools.php','Heterodyna · Okresy rozliczeniowe','Heterodyna · Rozliczenia','manage_options','hds3-billing-periods',[__CLASS__,'render']);
    }
    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        check_admin_referer('hds3_billing_create_draft');
        $tenant=isset($_POST['tenant_id'])?sanitize_text_field(wp_unslash($_POST['tenant_id'])):'';
        $month=isset($_POST['month'])?sanitize_text_field(wp_unslash($_POST['month'])):'';
        $result=self::create_draft($tenant,$month);
        $params=['page'=>'hds3-billing-periods'];
        if (is_wp_error($result)) $params['hds3_error']=$result->get_error_message();
        else $params['hds3_created']='1';
        wp_safe_redirect(add_query_arg($params,admin_url('tools.php'))); exit;
    }
    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        global $wpdb; $t=self::table();
        $rows=$wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 100",ARRAY_A);
        echo '<div class="wrap"><h1>Heterodyna Cloud · Okresy rozliczeniowe</h1>';
        echo '<p>Robocze, niezmienne zestawienia zarejestrowanego zużycia. Nie są fakturą, żądaniem zapłaty ani dowodem dokonania płatności. Brak zdarzeń w rejestrze nie potwierdza braku zużycia.</p>';
        if(isset($_GET['hds3_created'])) echo '<div class="notice notice-success"><p>Zapisano zestawienie.</p></div>';
        if(isset($_GET['hds3_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['hds3_error']))).'</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hds3_billing_create_draft">';
        wp_nonce_field('hds3_billing_create_draft');
        echo '<p><label>Identyfikator organizacji <input required maxlength="64" name="tenant_id"></label> <label>Miesiąc UTC <input required type="month" name="month" value="'.esc_attr(gmdate('Y-m',strtotime('first day of last month UTC'))).'"></label> ';
        submit_button('Utwórz robocze zestawienie','primary','submit',false); echo '</p></form>';
        echo '<h2>Ostatnie 100 okresów</h2><table class="widefat striped"><thead><tr><th>Organizacja</th><th>Miesiąc (UTC)</th><th>Plan</th><th>Zarejestrowane zużycie (snapshot)</th><th>Utworzył</th><th>Utworzono</th></tr></thead><tbody>';
        foreach((array)$rows as $r) {
            $snapshot=json_decode($r['usage_json'],true);$metrics=is_array($snapshot)?($snapshot['metrics']??[]):[];
            $parts=[];foreach($metrics as $key=>$value)$parts[]=sanitize_key($key).': '.(int)$value;
            echo '<tr><td>'.esc_html($r['tenant_id']).'</td><td>'.esc_html($r['month_key']).'</td><td>'.esc_html($r['plan_key']).'</td><td>'.esc_html($parts?implode(', ',$parts):'Brak zarejestrowanych zdarzeń').'</td><td>'.esc_html((string)$r['created_by']).'</td><td>'.esc_html($r['created_at']).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
