<?php
/** Heterodyna Cloud: conservative allowlisted WP-Cron task engine. */
if (!defined('ABSPATH')) exit;
final class HDS3_Automation {
    const EVENT = 'hds3_automation_tick';
    const SCHEMA = '1';
    const MAX_ATTEMPTS = 5;
    public static function table() { return HDS3_DB::table('automation_jobs'); }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tenant_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(80) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            max_attempts tinyint unsigned NOT NULL DEFAULT 3,
            run_after datetime NOT NULL,
            locked_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_due (status,run_after),
            KEY tenant_status (tenant_id,status)
        ) $collate;");
        update_option('hds3_automation_schema', self::SCHEMA, false);
        self::schedule();
    }
    public static function maybe_install() {
        if (get_option('hds3_automation_schema') !== self::SCHEMA) self::install();
        self::schedule();
    }
    public static function schedule() {
        if (!wp_next_scheduled(self::EVENT)) wp_schedule_event(time()+90, 'hourly', self::EVENT);
    }
    public static function deactivate() { wp_clear_scheduled_hook(self::EVENT); }
    public static function init() {
        add_action(self::EVENT, [__CLASS__, 'run']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_hds3_automation_run', [__CLASS__, 'manual_run']);
        add_action('admin_post_hds3_automation_retry', [__CLASS__, 'manual_retry']);
        add_action('admin_post_hds3_automation_demo', [__CLASS__, 'demo']);
    }
    /** Register a trusted internal handler; no remote/untrusted callbacks or arbitrary PHP. */
    public static function handlers() {
        $handlers = ['health_probe' => [__CLASS__, 'health_probe']];
        return apply_filters('hds3_automation_handlers', $handlers);
    }
    public static function health_probe($payload, $tenant_id) { return true; }
    public static function enqueue($kind, $payload = [], $tenant_id = 0, $delay = 0, $max_attempts = 3) {
        if (!is_string($kind) || !preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $kind)) return new WP_Error('hds3_kind', 'Invalid task type');
        $handlers=self::handlers();
        if (!isset($handlers[$kind]) || !is_callable($handlers[$kind])) return new WP_Error('hds3_handler', 'Unregistered handler');
        if (!is_array($payload) || strlen(wp_json_encode($payload)) > 16384) return new WP_Error('hds3_payload', 'Payload exceeds limit');
        $tenant_id=absint($tenant_id); $delay=max(0,min(2592000,(int)$delay));
        $max_attempts=max(1,min(self::MAX_ATTEMPTS,(int)$max_attempts));
        global $wpdb;
        $ok=$wpdb->insert(self::table(), [
            'tenant_id'=>$tenant_id,'kind'=>$kind,'payload'=>wp_json_encode($payload),
            'status'=>'pending','attempts'=>0,'max_attempts'=>$max_attempts,
            'run_after'=>gmdate('Y-m-d H:i:s',time()+$delay),'created_at'=>gmdate('Y-m-d H:i:s')
        ], ['%d','%s','%s','%s','%d','%d','%s','%s']);
        return $ok ? (int)$wpdb->insert_id : new WP_Error('hds3_insert','Unable to enqueue job');
    }
    public static function run() {
        global $wpdb;
        $t=self::table(); $now=gmdate('Y-m-d H:i:s');
        // No overlap: allow stale processing claims to be recovered after 15 minutes.
        $stale=gmdate('Y-m-d H:i:s',time()-900);
        $wpdb->query($wpdb->prepare("UPDATE $t SET status='pending', locked_at=NULL WHERE status='running' AND locked_at < %s", $stale));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE status='pending' AND run_after <= %s ORDER BY id ASC LIMIT %d",$now,10));
        foreach ((array)$ids as $id) {
            $id=absint($id); if (!$id) continue;
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $t SET status='running', locked_at=%s, attempts=attempts+1 WHERE id=%d AND status='pending' AND run_after <= %s",$now,$id,$now));
            if ($claimed !== 1) continue;
            $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id));
            if (!$job) continue;
            $handlers=self::handlers(); $handler=$handlers[$job->kind] ?? null;
            $error='';
            try {
                if (!is_callable($handler)) throw new RuntimeException('Handler unavailable');
                $payload=json_decode((string)$job->payload,true);
                if (!is_array($payload)) throw new RuntimeException('Invalid payload');
                $result=call_user_func($handler,$payload,(int)$job->tenant_id);
                if (is_wp_error($result)) throw new RuntimeException($result->get_error_code());
                if ($result === false) throw new RuntimeException('Task returned false');
            } catch (Throwable $e) { $error=substr($e->getMessage(),0,500); }
            if ($error === '') {
                $wpdb->update($t,['status'=>'completed','locked_at'=>null,'completed_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$id],['%s','%s','%s','%s'],['%d']);
            } else {
                $terminal=(int)$job->attempts >= (int)$job->max_attempts;
                $delay=min(86400,60 * (2 ** min(10,(int)$job->attempts-1)));
                $wpdb->update($t,['status'=>$terminal?'failed':'pending','locked_at'=>null,'run_after'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error'=>$error],['id'=>$id],['%s','%s','%s','%s'],['%d']);
            }
        }
    }
    public static function menu() {
        add_management_page('Heterodyna · Automatyzacje','Heterodyna · Automatyzacje','manage_options','hds3-automation',[__CLASS__,'page']);
    }
    private static function redirect() { wp_safe_redirect(admin_url('tools.php?page=hds3-automation')); exit; }
    private static function authorized($action) {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień','',['response'=>403]);
        check_admin_referer($action);
    }
    public static function manual_run() { self::authorized('hds3_automation_run'); self::run(); self::redirect(); }
    public static function demo() { self::authorized('hds3_automation_demo'); self::enqueue('health_probe'); self::redirect(); }
    public static function manual_retry() {
        self::authorized('hds3_automation_retry');
        $id=isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if ($id) { global $wpdb; $t=self::table();
            $wpdb->query($wpdb->prepare("UPDATE $t SET status='pending', attempts=0, locked_at=NULL, last_error=NULL, run_after=%s WHERE id=%d AND status='failed'",gmdate('Y-m-d H:i:s'),$id));
        }
        self::redirect();
    }
    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień');
        global $wpdb; $t=self::table();
        $rows=$wpdb->get_results("SELECT id,tenant_id,kind,status,attempts,max_attempts,run_after,last_error FROM $t ORDER BY id DESC LIMIT 50");
        echo '<div class="wrap"><h1>Heterodyna Cloud · Automatyzacje</h1>';
        echo '<p>Bezpieczna kolejka zadań wewnętrznych. Wykonanie przez WP-Cron zależy od ruchu lub zewnętrznego wywoływania harmonogramu. Nie uruchamia dowolnego kodu użytkownika.</p>';
        foreach (['hds3_automation_demo'=>'Dodaj zadanie diagnostyczne','hds3_automation_run'=>'Uruchom kolejkę teraz'] as $action=>$label) {
            echo '<form style="display:inline-block;margin:4px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field($action);echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
            submit_button($label,'secondary','submit',false);echo '</form>';
        }
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Organizacja</th><th>Zadanie</th><th>Status</th><th>Próby</th><th>Termin UTC</th><th>Błąd</th><th>Akcja</th></tr></thead><tbody>';
        foreach ((array)$rows as $r) {
            echo '<tr><td>'.esc_html((string)$r->id).'</td><td>'.esc_html((string)$r->tenant_id).'</td><td>'.esc_html($r->kind).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->attempts.'/'.$r->max_attempts).'</td><td>'.esc_html($r->run_after).'</td><td>'.esc_html((string)$r->last_error).'</td><td>';
            if ($r->status === 'failed') { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('hds3_automation_retry');echo '<input type="hidden" name="action" value="hds3_automation_retry"><input type="hidden" name="job_id" value="'.esc_attr($r->id).'">';submit_button('Ponów','secondary','submit',false);echo '</form>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
