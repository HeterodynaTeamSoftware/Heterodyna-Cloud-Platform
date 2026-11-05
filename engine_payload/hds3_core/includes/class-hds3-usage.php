<?php
if (!defined('ABSPATH')) exit;
/** Usage ledger: only recorded events, never fabricated payment records. */
final class HDS3_Usage {
    const SCHEMA = '03.00.167';
    private static function table() { return HDS3_DB::table('usage_events'); }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tenant_id varchar(64) NOT NULL,
            metric varchar(64) NOT NULL,
            units bigint(20) unsigned NOT NULL DEFAULT 1,
            event_key varchar(128) NOT NULL,
            occurred_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY tenant_metric_time (tenant_id,metric,occurred_at),
            KEY tenant_time_metric (tenant_id,occurred_at,metric)
        ) $charset;");
        update_option('hds3_usage_schema',self::SCHEMA,false);
    }
    public static function maybe_install() {
        if (get_option('hds3_usage_schema')!==self::SCHEMA) self::install();
    }
    /** Accepts only trusted server-side calls. Never expose this method directly to untrusted REST requests. */
    public static function record($tenant_id,$metric,$units,$event_key,$occurred_at=null) {
        global $wpdb;
        if (!is_string($tenant_id) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',$tenant_id)) return new WP_Error('usage_tenant','Invalid tenant');
        if (!is_string($metric) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$metric)) return new WP_Error('usage_metric','Invalid metric');
        if (!is_int($units) || $units<1 || $units>1000000000) return new WP_Error('usage_units','Invalid units');
        if (!is_string($event_key) || !preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D',$event_key)) return new WP_Error('usage_event_key','Invalid event key');
        $tenant_table=HDS3_DB::table('tenants');
        $exists=$wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM $tenant_table WHERE tenant_id=%s LIMIT 1",$tenant_id));
        if (!$exists) return new WP_Error('usage_missing_tenant','Tenant does not exist');
        $when=$occurred_at ?: current_time('mysql',true);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$when,new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d H:i:s')!==$when) return new WP_Error('usage_timestamp','Invalid UTC timestamp');
        // INSERT IGNORE makes repeat delivery idempotent, including concurrent retries.
        $t=self::table(); $now=current_time('mysql',true);
        $result=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (tenant_id,metric,units,event_key,occurred_at,created_at) VALUES (%s,%s,%d,%s,%s,%s)",$tenant_id,$metric,$units,$event_key,$when,$now));
        if ($result===false) return new WP_Error('usage_database','Unable to write usage event');
        if ($result===0) {
            $previous=$wpdb->get_row($wpdb->prepare("SELECT tenant_id,metric,units,occurred_at FROM $t WHERE event_key=%s LIMIT 1",$event_key),ARRAY_A);
            if (!$previous || $previous['tenant_id']!==$tenant_id || $previous['metric']!==$metric || (int)$previous['units']!==$units || $previous['occurred_at']!==$when) return new WP_Error('usage_duplicate_conflict','Event key already used for different data');
            return 'duplicate';
        }
        return 'recorded';
    }
    public static function totals($tenant_id,$start,$end) {
        global $wpdb;
        if (!is_string($tenant_id) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',$tenant_id)) return [];
        $t=self::table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT metric,SUM(units) total FROM $t WHERE tenant_id=%s AND occurred_at >= %s AND occurred_at < %s GROUP BY metric",$tenant_id,$start,$end),ARRAY_A);
        $totals=[];
        foreach ((array)$rows as $row) $totals[$row['metric']]=(int)$row['total'];
        return $totals;
    }
    public static function report($tenant_id,$start,$end) {
        $sub=HDS3_Subscriptions::get($tenant_id);
        $status=HDS3_Subscriptions::effective_status($sub);
        $plan=($sub && $status==='active') ? HDS3_Plans::get_plan($sub['plan_key']) : null;
        $limits=[];
        if ($plan) {
            $raw=$plan['limits']??[];
            $limits=is_array($raw)?$raw:json_decode((string)$raw,true);
            if (!is_array($limits)) $limits=[];
        }
        $totals=self::totals($tenant_id,$start,$end);
        $result=[];
        foreach (array_unique(array_merge(array_keys($totals),array_keys($limits))) as $metric) {
            $limit=isset($limits[$metric]) && is_numeric($limits[$metric]) ? (int)$limits[$metric] : null;
            $used=$totals[$metric]??0;
            $result[$metric]=['used'=>$used,'limit'=>$limit,'over_limit'=>$limit!==null && $used>$limit];
        }
        return ['subscription_status'=>$status,'plan_key'=>$plan['plan_key']??null,'metrics'=>$result,'enforcement'=>'advisory_only'];
    }
    public static function admin_menu() { add_submenu_page('tools.php','Heterodyna · Zużycie','Heterodyna · Zużycie','manage_options','hds3-usage',[__CLASS__,'render']); }
    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        $tenant=isset($_GET['tenant_id'])?sanitize_text_field(wp_unslash($_GET['tenant_id'])):'';
        $month=isset($_GET['month'])?sanitize_text_field(wp_unslash($_GET['month'])):gmdate('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) $month=gmdate('Y-m');
        echo '<div class="wrap"><h1>Heterodyna Cloud · Usage</h1><p>Rejestr rzeczywistych zdarzeń zgłoszonych przez integracje. Brak zdarzenia nie oznacza zerowego rzeczywistego zużycia. Żadnych płatności ani faktur.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="hds3-usage"><label>Organizacja <input name="tenant_id" value="'.esc_attr($tenant).'" maxlength="64"></label> <label>Miesiąc UTC <input type="month" name="month" value="'.esc_attr($month).'"></label> <button class="button button-primary">Pokaż</button></form>';
        if ($tenant!=='') {
            $start=$month.'-01 00:00:00'; $end=gmdate('Y-m-d H:i:s',strtotime($start.' +1 month UTC'));
            $report=self::report($tenant,$start,$end);
            echo '<h2>Status subskrypcji: '.esc_html($report['subscription_status']).'</h2><p>Limity są informacyjne. Plan: '.esc_html($report['plan_key']??'brak aktywnego planu').'</p><table class="widefat striped"><thead><tr><th>Metryka</th><th>Zarejestrowane jednostki</th><th>Limit</th><th>Status</th></tr></thead><tbody>';
            foreach ($report['metrics'] as $key=>$item) echo '<tr><td>'.esc_html($key).'</td><td>'.esc_html((string)$item['used']).'</td><td>'.esc_html($item['limit']===null?'nieokreślony':(string)$item['limit']).'</td><td>'.esc_html($item['over_limit']?'Przekroczony':'—').'</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
