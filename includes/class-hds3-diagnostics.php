<?php
/** Read-only operational diagnostics for Heterodyna Cloud. */
if (!defined('ABSPATH')) exit;
final class HDS3_Diagnostics {
    public static function init() { add_action('admin_menu', [__CLASS__, 'menu']); }
    public static function menu() { add_management_page('Heterodyna Cloud · Diagnostyka','Heterodyna · Diagnostyka','manage_options','hds3-diagnostics',[__CLASS__,'page']); }
    private static function table_status($table) {
        global $wpdb;
        // All names originate from HDS3_DB::table(), never user input.
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $exists === $table;
    }
    private static function count_jobs($table) {
        global $wpdb;
        $counts=['pending'=>0,'running'=>0,'completed'=>0,'failed'=>0];
        if (!self::table_status($table)) return null;
        $rows=$wpdb->get_results("SELECT status,COUNT(*) AS total FROM `$table` GROUP BY status", ARRAY_A);
        if (!is_array($rows)) return null;
        foreach ($rows as $row) {
            if (array_key_exists($row['status'],$counts)) $counts[$row['status']]=(int)$row['total'];
        }
        return $counts;
    }
    public static function snapshot() {
        global $wpdb;
        $jobs=self::count_jobs(HDS3_DB::table('automation_jobs'));
        $event_table=HDS3_DB::table('events');
        $events=null;
        if (self::table_status($event_table)) {
            $events=$wpdb->get_results("SELECT level,COUNT(*) AS total FROM `$event_table` WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) GROUP BY level",ARRAY_A);
        }
        $next=wp_next_scheduled(HDS3_Automation::EVENT);
        $extensions=class_exists('HDS3_Extensions') && method_exists('HDS3_Extensions','all') ? HDS3_Extensions::all() : null;
        return [
            'version'=>HDS3_VERSION,
            'site_environment'=>function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
            'php'=>PHP_VERSION,
            'wp'=>get_bloginfo('version'),
            'db_connected'=>empty($wpdb->last_error),
            'jobs'=>$jobs,
            'events'=>$events,
            'cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_cron'=>$next ? (int)$next : null,
            'extensions'=>$extensions,
        ];
    }
    public static function page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Brak uprawnień','heterodyna-saas-v3-core'),'', ['response'=>403]);
        $s=self::snapshot();
        echo '<div class="wrap hds3-diagnostics"><h1>Heterodyna Cloud · Centrum diagnostyczne</h1>';
        echo '<p>Odczyt danych lokalnych. Brak wyniku oznacza brak weryfikacji, nie poprawne działanie usługi zewnętrznej.</p>';
        echo '<style>.hds3-diagnostics{max-width:1100px}.hds3-diag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:20px}.hds3-diag-card{background:#fff;border:1px solid #d7e0eb;border-radius:12px;padding:20px;box-shadow:0 2px 10px #1020390a}.hds3-diag-card h2{margin:0 0 12px;font-size:17px;color:#152744}.hds3-diag-card dl{margin:0}.hds3-diag-card dt{color:#53657b;float:left;clear:left}.hds3-diag-card dd{text-align:right;font-weight:600;margin:0 0 10px}.hds3-diag-muted{color:#64748b}.hds3-diag-warning{color:#a34f05}</style>';
        echo '<div class="hds3-diag-grid"><section class="hds3-diag-card"><h2>Środowisko</h2><dl>';
        foreach (['Wersja platformy'=>$s['version'],'WordPress'=>$s['wp'],'PHP'=>$s['php'],'Środowisko'=>$s['site_environment']] as $k=>$v) self::row($k,$v);
        echo '</dl></section><section class="hds3-diag-card"><h2>Automatyzacja</h2><dl>';
        self::row('WP-Cron', $s['cron_disabled'] ? 'Wyłączony w WordPressie (sprawdź cron serwera)' : 'Włączony (wykonanie niepotwierdzone)');
        self::row('Najbliższe wywołanie', $s['next_cron'] ? wp_date('Y-m-d H:i:s T',$s['next_cron']) : 'Brak harmonogramu');
        if ($s['jobs']===null) self::row('Kolejka','Tabela niedostępna');
        else foreach (['pending'=>'Oczekujące','running'=>'Wykonywane','completed'=>'Ukończone','failed'=>'Nieudane'] as $key=>$label) self::row($label,(string)$s['jobs'][$key]);
        echo '</dl></section><section class="hds3-diag-card"><h2>Zdarzenia — ostatnie 24 h (UTC)</h2><dl>';
        if ($s['events']===null) self::row('Dziennik','Tabela niedostępna');
        elseif (!$s['events']) self::row('Zdarzenia','Brak wpisów');
        else foreach ($s['events'] as $entry) self::row((string)$entry['level'],(string)(int)$entry['total']);
        echo '</dl></section><section class="hds3-diag-card"><h2>Informacje o stanie</h2>';
        echo '<p>To lokalny odczyt stanu tabel, harmonogramu i dziennika. Nie sprawdza zewnętrznych API, dostarczania e-maili ani płatności.</p>';
        echo '<p><a class="button button-secondary" href="'.esc_url(admin_url('tools.php?page=hds3-automation')).'">Otwórz kolejkę</a></p>';
        echo '</section></div></div>';
    }
    private static function row($key,$value) { echo '<dt>'.esc_html((string)$key).'</dt><dd>'.esc_html((string)$value).'</dd>'; }
}
