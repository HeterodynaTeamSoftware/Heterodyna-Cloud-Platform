<?php
if (!defined('ABSPATH')) exit;
/** Manual subscription registry; no payment collection or simulated provider events. */
final class HDS3_Subscriptions {
    private static function table() { return HDS3_DB::table('subscriptions'); }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tenant_id varchar(64) NOT NULL,
            plan_key varchar(64) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'pending',
            period_start datetime DEFAULT NULL,
            period_end datetime DEFAULT NULL,
            cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_id (tenant_id),
            KEY status (status),
            KEY period_end (period_end)
        ) $charset;");
        update_option('hds3_subscription_schema', '03.00.135', false);
    }
    public static function maybe_install() {
        if (get_option('hds3_subscription_schema') !== '03.00.135') self::install();
    }
    public static function get($tenant_id) {
        global $wpdb;
        if (!is_string($tenant_id) || $tenant_id === '') return null;
        $table=self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE tenant_id=%s LIMIT 1",$tenant_id), ARRAY_A);
    }
    /** Read-only calculated status; never mutates a record during a GET. */
    public static function effective_status($row, $now=null) {
        if (!is_array($row)) return 'unregistered';
        $status=(string)$row['status'];
        $now=$now ?: current_time('mysql', true);
        if ($status==='active' && !empty($row['period_end']) && $row['period_end'] <= $now) return 'expired';
        return $status;
    }
    public static function transition_allowed($from,$to) {
        $matrix=[
            'unregistered'=>['pending'],
            'pending'=>['active','canceled'],
            'active'=>['past_due','paused','canceled','expired'],
            'past_due'=>['active','paused','canceled','expired'],
            'paused'=>['active','canceled','expired'],
            'expired'=>['active','canceled'],
            'canceled'=>['pending'],
        ];
        return in_array($to,$matrix[$from]??[],true);
    }
    /** Manual changes are restricted to site administrators, never token clients. */
    public static function change($tenant_id,$plan_key,$status,$period_end='') {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden','Brak uprawnień', ['status'=>403]);
        global $wpdb;
        $tenant_id=sanitize_text_field($tenant_id);
        $plan_key=sanitize_key($plan_key);
        $status=sanitize_key($status);
        $period_end=trim((string)$period_end);
        if ($tenant_id==='' || strlen($tenant_id)>64) return new WP_Error('invalid_tenant','Nieprawidłowa organizacja');
        $tenant_table=HDS3_DB::table('tenants');
        $tenant_exists=$wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM $tenant_table WHERE tenant_id=%s LIMIT 1",$tenant_id));
        if (!$tenant_exists) return new WP_Error('missing_tenant','Organizacja nie istnieje');
        $plan=HDS3_Plans::get_plan($plan_key);
        if (!$plan || ($plan['status']??'')!=='active') return new WP_Error('invalid_plan','Plan nie istnieje lub jest nieaktywny');
        if ($period_end!=='') {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d', $period_end, new DateTimeZone('UTC'));
            if (!$d || $d->format('Y-m-d')!==$period_end) return new WP_Error('invalid_date','Nieprawidłowa data końca');
            $period_end=$d->format('Y-m-d 23:59:59');
        }
        if ($status==='active' && ($period_end==='' || $period_end<=current_time('mysql',true))) return new WP_Error('invalid_period','Aktywna subskrypcja wymaga przyszłej daty końca');
        $old=self::get($tenant_id);
        $from=$old ? self::effective_status($old) : 'unregistered';
        if (!self::transition_allowed($from,$status)) return new WP_Error('invalid_transition','Niedozwolona zmiana statusu');
        if ($old && $old['plan_key']!==$plan_key && $status==='active' && $from==='active') return new WP_Error('plan_change','Zmiana planu wymaga osobnego procesu; nie zmieniaj planu aktywnej subskrypcji');
        $now=current_time('mysql',true); $table=self::table();
        $data=['plan_key'=>$plan_key,'status'=>$status,'period_end'=>$period_end ?: null,'updated_by'=>get_current_user_id(),'updated_at'=>$now];
        if ($old) {
            $ok=$wpdb->update($table,$data,['tenant_id'=>$tenant_id]);
        } else {
            $data['tenant_id']=$tenant_id; $data['created_at']=$now;
            $data['period_start']=$now;
            $ok=$wpdb->insert($table,$data);
        }
        if ($ok===false) return new WP_Error('database_error','Nie udało się zapisać subskrypcji');
        if (class_exists('HDS3_Logger')) HDS3_Logger::event('subscriptions','info','Ręczna zmiana subskrypcji',['tenant_id'=>$tenant_id,'from'=>$from,'to'=>$status,'by'=>get_current_user_id()]);
        return true;
    }
    public static function admin_menu() {
        add_submenu_page('tools.php','Subskrypcje Heterodyna','Subskrypcje Heterodyna','manage_options','hds3-subscriptions',[__CLASS__,'render']);
    }
    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień',403);
        check_admin_referer('hds3_subscriptions_save');
        $result=self::change(
            isset($_POST['tenant_id']) ? wp_unslash($_POST['tenant_id']) : '',
            isset($_POST['plan_key']) ? wp_unslash($_POST['plan_key']) : '',
            isset($_POST['status']) ? wp_unslash($_POST['status']) : '',
            isset($_POST['period_end']) ? wp_unslash($_POST['period_end']) : ''
        );
        $args=['page'=>'hds3-subscriptions'];
        if (is_wp_error($result)) $args['hds3_error']=$result->get_error_message();
        else $args['hds3_saved']='1';
        wp_safe_redirect(add_query_arg($args,admin_url('tools.php'))); exit;
    }
    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień',403);
        global $wpdb;
        $table=self::table();
        $rows=$wpdb->get_results("SELECT * FROM $table ORDER BY updated_at DESC LIMIT 100",ARRAY_A);
        $plans=HDS3_Plans::list_plans(true);
        echo '<div class="wrap"><h1>Heterodyna · Subskrypcje</h1><p>Rejestr ręczny. Statusy nie stanowią potwierdzenia płatności. Nowe organizacje nie dostają automatycznie aktywnej subskrypcji.</p>';
        if (isset($_GET['hds3_saved'])) echo '<div class="notice notice-success"><p>Zapisano zmianę.</p></div>';
        if (isset($_GET['hds3_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['hds3_error']))).'</p></div>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="hds3_subscription_save">';
        wp_nonce_field('hds3_subscriptions_save');
        echo '<table class="form-table"><tr><th><label for="hds3tenant">ID organizacji</label></th><td><input id="hds3tenant" type="text" maxlength="64" required name="tenant_id" class="regular-text"></td></tr><tr><th>Plan</th><td><select name="plan_key">';
        foreach ($plans as $p) echo '<option value="'.esc_attr($p['plan_key']).'">'.esc_html($p['name']).'</option>';
        echo '</select></td></tr><tr><th>Status docelowy</th><td><select name="status">';
        foreach (['pending','active','past_due','paused','canceled','expired'] as $s) echo '<option>'.esc_html($s).'</option>';
        echo '</select><p class="description">Nowe wpisy zaczynają od pending. Następnie można przejść na active.</p></td></tr><tr><th>Koniec okresu (UTC)</th><td><input type="date" name="period_end"></td></tr></table>';
        submit_button('Zapisz zmianę ręczną'); echo '</form>';
        echo '<h2>Ostatnie 100 wpisów</h2><table class="widefat striped"><thead><tr><th>Organizacja</th><th>Plan</th><th>Status zapisany</th><th>Status efektywny</th><th>Koniec okresu UTC</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>'.esc_html($r['tenant_id']).'</td><td>'.esc_html($r['plan_key']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html(self::effective_status($r)).'</td><td>'.esc_html($r['period_end'] ?: '—').'</td></tr>';
        echo '</tbody></table></div>';
    }
}
