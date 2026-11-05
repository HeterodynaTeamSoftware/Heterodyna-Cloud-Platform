<?php
/** Locally generated, administrator-reviewed operational alerts. No automatic email delivery. */
if (!defined('ABSPATH')) exit;
final class HDS3_Alerts {
    const SCHEMA = '1';
    private static function table() { return HDS3_DB::table('alerts'); }
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('hds3_monitor_hourly', [__CLASS__, 'scheduled_scan']);
        add_action('admin_post_hds3_monitor_settings', [__CLASS__, 'save_monitor_settings']);
        // One scheduled event for the whole site. Never send email from the monitor.
        if (!wp_next_scheduled('hds3_monitor_hourly')) wp_schedule_event(time() + 300, 'hourly', 'hds3_monitor_hourly');
        add_action('admin_post_hds3_alert_scan', [__CLASS__, 'scan_action']);
        add_action('admin_post_hds3_alert_ack', [__CLASS__, 'ack_action']);
        add_action('admin_post_hds3_alert_reopen', [__CLASS__, 'reopen_action']);
    }
    public static function deactivate() { wp_clear_scheduled_hook('hds3_monitor_hourly'); }
    private static function defaults() { return ['enabled'=>1,'failed_threshold'=>3,'overdue_threshold'=>10,'overdue_minutes'=>60,'stale_minutes'=>30]; }
    public static function config() {
        $raw=get_option('hds3_monitor_rules',[]);
        if (!is_array($raw)) $raw=[];
        return array_merge(self::defaults(),array_intersect_key($raw,self::defaults()));
    }
    public static function save_monitor_settings() {
        self::check(); check_admin_referer('hds3_monitor_settings');
        $rules=[
          'enabled'=>isset($_POST['monitor_enabled'])?1:0,
          'failed_threshold'=>max(1,min(1000,absint($_POST['failed_threshold']??3))),
          'overdue_threshold'=>max(1,min(1000,absint($_POST['overdue_threshold']??10))),
          'overdue_minutes'=>max(5,min(10080,absint($_POST['overdue_minutes']??60))),
          'stale_minutes'=>max(5,min(1440,absint($_POST['stale_minutes']??30))),
        ];
        update_option('hds3_monitor_rules',$rules,false);
        self::redirect('Zapisano reguły monitorowania');
    }
    public static function scheduled_scan() {
        if (!self::config()['enabled']) return;
        self::maybe_install();
        self::scan_rules();
    }
    /** Aggregate indicators, measured in UTC. No external connectivity claims. */
    public static function scan_rules() {
        global $wpdb;
        $cfg=self::config();
        $jobs=HDS3_DB::table('automation_jobs');
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($jobs)));
        if ($exists!==$jobs) { self::record('queue_missing','warning','jobs','Brak tabeli kolejki','Sprawdź instalację modułu Automation.'); return; }
        $failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$jobs` WHERE status='failed'");
        if ($failed >= $cfg['failed_threshold']) self::record('queue_failed_threshold','warning','global','Przekroczony próg nieudanych zadań','Nieudane zadania: '.$failed.'; próg: '.$cfg['failed_threshold']);
        $due=gmdate('Y-m-d H:i:s',time()-$cfg['overdue_minutes']*60);
        $overdue=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$jobs` WHERE status='pending' AND run_after < %s",$due));
        if ($overdue >= $cfg['overdue_threshold']) self::record('queue_overdue_threshold','warning','global','Zaległe zadania w kolejce','Zaległe zadania: '.$overdue.'; próg: '.$cfg['overdue_threshold']);
        $stale=gmdate('Y-m-d H:i:s',time()-$cfg['stale_minutes']*60);
        $stale_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$jobs` WHERE status='running' AND locked_at IS NOT NULL AND locked_at < %s",$stale));
        if ($stale_count > 0) self::record('queue_stale_running','critical','global','Zadania z nieaktualną blokadą','Liczba zadań: '.$stale_count.'; limit minut: '.$cfg['stale_minutes']);
    }
    public static function maybe_install() {
        if (get_option('hds3_alerts_schema') !== self::SCHEMA) self::install();
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          fingerprint varchar(64) NOT NULL,
          severity varchar(16) NOT NULL,
          kind varchar(64) NOT NULL,
          title varchar(191) NOT NULL,
          details text NULL,
          status varchar(16) NOT NULL DEFAULT 'open',
          occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
          first_seen datetime NOT NULL,
          last_seen datetime NOT NULL,
          acknowledged_at datetime NULL,
          acknowledged_by bigint(20) unsigned NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY fingerprint (fingerprint),
          KEY status_last_seen (status,last_seen)
        ) $charset;");
        if (empty($wpdb->last_error)) update_option('hds3_alerts_schema', self::SCHEMA, false);
    }
    private static function check() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień', '', ['response'=>403]);
    }
    public static function menu() {
        add_management_page('Heterodyna Cloud · Alerty','Heterodyna · Alerty','manage_options','hds3-alerts',[__CLASS__,'page']);
    }
    /** Duplicate detections update a single alert, without resetting acknowledged status. */
    public static function record($kind,$severity,$subject,$title,$details='') {
        global $wpdb;
        $kind=sanitize_key($kind); $severity=in_array($severity,['info','warning','critical'],true)?$severity:'warning';
        $fingerprint=hash('sha256',$kind.'|'.(string)$subject);
        $table=self::table(); $now=current_time('mysql',true);
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (fingerprint,severity,kind,title,details,status,occurrence_count,first_seen,last_seen) VALUES (%s,%s,%s,%s,%s,'open',1,%s,%s) ON DUPLICATE KEY UPDATE severity=VALUES(severity),title=VALUES(title),details=VALUES(details),last_seen=VALUES(last_seen),occurrence_count=occurrence_count+1",$fingerprint,$severity,$kind,wp_strip_all_tags((string)$title),wp_strip_all_tags((string)$details),$now,$now));
        if(empty($wpdb->last_error)) {
            $alert_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE fingerprint=%s",$fingerprint));
            if($alert_id)do_action('hds3_webhook_alert_recorded',$alert_id,$severity);
        }
        return empty($wpdb->last_error);
    }
    public static function scan() {
        global $wpdb;
        $jobs=HDS3_DB::table('automation_jobs');
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($jobs)));
        if ($exists!==$jobs) { self::record('queue_missing','warning','jobs','Brak tabeli kolejki','Sprawdź instalację modułu Automation.'); return 1; }
        $rows=$wpdb->get_results("SELECT id FROM `$jobs` WHERE status = 'failed' ORDER BY id DESC LIMIT 100",ARRAY_A);
        $count=0;
        if (is_array($rows)) foreach($rows as $row) {
            if (self::record('failed_job','warning',(string)$row['id'],'Nieudane zadanie #'.(int)$row['id'],'Sprawdź szczegóły zadania w module Automatyzacje.')) $count++;
        }
        return $count;
    }
    private static function redirect($message) {
        wp_safe_redirect(add_query_arg('hds3_notice',rawurlencode($message),admin_url('tools.php?page=hds3-alerts')));
        exit;
    }
    public static function scan_action() {
        self::check(); check_admin_referer('hds3_alert_scan');
        self::maybe_install(); $count=self::scan(); self::redirect('Sprawdzono kolejkę. Zarejestrowane wykrycia: '.$count);
    }
    private static function change_status($new) {
        self::check(); check_admin_referer('hds3_alert_status');
        global $wpdb; $id=absint($_POST['alert_id']??0);
        if (!$id) self::redirect('Nieprawidłowy identyfikator');
        $values=['status'=>$new,'acknowledged_at'=>$new==='acknowledged'?current_time('mysql',true):null,'acknowledged_by'=>$new==='acknowledged'?get_current_user_id():null];
        $wpdb->update(self::table(),$values,['id'=>$id],['%s','%s','%d'],['%d']);
        self::redirect('Zapisano zmianę statusu');
    }
    public static function ack_action() { self::change_status('acknowledged'); }
    public static function reopen_action() { self::change_status('open'); }
    public static function page() {
        self::check(); self::maybe_install(); global $wpdb;
        $table=self::table();
        $rows=$wpdb->get_results("SELECT id,severity,kind,title,details,status,occurrence_count,first_seen,last_seen FROM `$table` ORDER BY last_seen DESC LIMIT 100",ARRAY_A);
        echo '<div class="wrap hds3-alerts"><h1>Heterodyna Cloud · Centrum alertów</h1>';
        echo '<p>Alerty powstają po ręcznym skanowaniu kolejki lub wywołaniu API modułu. E-mail nie jest wysyłany automatycznie. Potwierdzone alerty pozostają w historii.</p>';
        if (isset($_GET['hds3_notice'])) echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['hds3_notice']))).'</p></div>';
        $cfg=self::config();
        $next=wp_next_scheduled('hds3_monitor_hourly');
        echo '<div class="hds3-alert-panel"><h2>Reguły monitorowania automatycznego</h2>';
        echo '<p>Kontrole są wykonywane co godzinę przez WP-Cron (zależnie od ruchu lub systemowego crona). E-mail pozostaje wyłączony.</p>';
        echo '<p>Następny przebieg: '.($next?esc_html(gmdate('Y-m-d H:i:s',$next)).' UTC':'nie zaplanowano').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hds3_monitor_settings">';
        wp_nonce_field('hds3_monitor_settings');
        echo '<p><label><input type="checkbox" name="monitor_enabled" value="1" '.checked($cfg['enabled'],1,false).'> Aktywne monitorowanie</label></p>';
        foreach (['failed_threshold'=>'Alarm od liczby nieudanych zadań','overdue_threshold'=>'Alarm od liczby zaległych zadań','overdue_minutes'=>'Opóźnienie w minutach','stale_minutes'=>'Nieaktualna blokada po minutach'] as $key=>$label) {
          echo '<p><label>'.esc_html($label).' <input type="number" min="1" max="10080" name="'.esc_attr($key).'" value="'.esc_attr($cfg[$key]).'"></label></p>';
        }
        submit_button('Zapisz reguły','primary','submit',false); echo '</form></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hds3_alert_scan">';
        wp_nonce_field('hds3_alert_scan'); submit_button('Sprawdź nieudane zadania','primary','submit',false); echo '</form>';
        echo '<style>.hds3-alerts{max-width:1150px}.hds3-alerts .hds3-alert-panel{background:#fff;border:1px solid #dbe4ef;border-radius:12px;padding:20px;margin-top:18px}.hds3-alerts th{text-align:left}.hds3-alerts td{vertical-align:top}.hds3-alerts .critical{color:#a7192b;font-weight:700}.hds3-alerts .warning{color:#926300;font-weight:700}</style>';
        echo '<div class="hds3-alert-panel"><h2>Historia ostatnich 100 alertów</h2><table class="widefat striped"><thead><tr><th>Poziom</th><th>Zdarzenie</th><th>Status</th><th>Wystąpienia</th><th>Ostatnio UTC</th><th>Działania</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Nie zarejestrowano alertów.</td></tr>';
        foreach ((array)$rows as $row) {
            $severity=(string)$row['severity'];
            echo '<tr><td class="'.esc_attr($severity).'">'.esc_html($severity).'</td><td><strong>'.esc_html($row['title']).'</strong><p>'.esc_html($row['details']).'</p><small>'.esc_html($row['kind']).'</small></td><td>'.esc_html($row['status']).'</td><td>'.(int)$row['occurrence_count'].'</td><td>'.esc_html($row['last_seen']).'</td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="'.($row['status']==='open'?'hds3_alert_ack':'hds3_alert_reopen').'"><input type="hidden" name="alert_id" value="'.(int)$row['id'].'">';
            wp_nonce_field('hds3_alert_status');
            echo '<button class="button button-secondary" type="submit">'.($row['status']==='open'?'Potwierdź':'Otwórz ponownie').'</button></form></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
