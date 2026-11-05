<?php
if (!defined('ABSPATH')) exit;
/**
 * Complete data reset for a fresh open-source deployment.
 * Never runs on activation or upgrade. All destructive operations require
 * a capability check, POST nonce, checkbox and exact confirmation phrase.
 */
final class HDS3_Data_Reset {
    private const PHRASE = 'RESET HETERODYNA CLOUD';

    public static function menu() {
        add_submenu_page('hds3_center', 'Centrum danych', 'Centrum danych', 'manage_options', 'hds3_data_reset', [__CLASS__, 'page']);
    }

    /** Only tables starting with the exact current-site HDS3 prefix. */
    private static function discovered(): array {
        global $wpdb;
        $prefix = HDS3_DB::table('');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) return [];
        $pattern = $wpdb->esc_like($prefix) . '%';
        $names = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
        $result = [];
        foreach ($names as $name) {
            $name = (string)$name;
            if (strpos($name, $prefix) !== 0 || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) continue;
            $result[] = $name;
        }
        sort($result, SORT_STRING);
        return $result;
    }

    /** Read only plugin-owned option names (no wildcards at deletion time). */
    private static function options(): array {
        global $wpdb;
        $pattern = $wpdb->esc_like('hds3_') . '%';
        $names = (array)$wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s ORDER BY option_name", $pattern
        ));
        return array_values(array_filter(array_map('strval', $names), static function($name) {
            return strpos($name, 'hds3_') === 0;
        }));
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        global $wpdb;
        $tables = self::discovered();
        $options = self::options();
        echo '<div class="wrap"><h1>Heterodyna Cloud — Centrum danych</h1>';
        echo '<div class="notice notice-warning inline"><p><strong>Pełny reset danych platformy.</strong> Usuwa rekordy z tabel bieżącej witryny o prefiksie <code>'.esc_html(HDS3_DB::table('')).'</code> i ustawienia <code>hds3_*</code>. Nie usuwa użytkowników, wpisów i stron WordPressa ani tabel innych wtyczek. Operacja nie jest transakcyjna.</p></div>';
        if (isset($_GET['hds3_reset_result'])) {
            $state = sanitize_key(wp_unslash($_GET['hds3_reset_result']));
            if ($state === 'ok') echo '<div class="notice notice-success"><p>Reset zakończony. Baza platformy została wyczyszczona i jest gotowa do nowej konfiguracji.</p></div>';
            if ($state === 'error') echo '<div class="notice notice-error"><p>Reset nie został zakończony w całości. Sprawdź logi serwera i przywróć kopię w razie potrzeby.</p></div>';
        }
        echo '<h2>Podgląd zakresu</h2><p>Tabele platformy: <strong>'.count($tables).'</strong> · opcje platformy: <strong>'.count($options).'</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Tabela</th><th>Rekordy</th></tr></thead><tbody>';
        foreach ($tables as $table) {
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `'.$table.'`');
            echo '<tr><td><code>'.esc_html($table).'</code></td><td>'.esc_html((string)$count).'</td></tr>';
        }
        if (!$tables) echo '<tr><td colspan="2">Brak tabel platformy na tej witrynie.</td></tr>';
        echo '</tbody></table>';
        echo '<p><strong>Opcje do wyczyszczenia:</strong> '.esc_html(implode(', ', $options) ?: 'brak').'</p>';
        echo '<p><strong>Przed resetem:</strong> zatrzymaj integracje i wykonaj zweryfikowaną kopię MySQL. Nie uruchamiaj w środowisku obsługującym klientów.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:720px;margin-top:24px">';
        echo '<input type="hidden" name="action" value="hds3_data_reset">';
        wp_nonce_field('hds3_data_reset_execute');
        echo '<p><label><input type="checkbox" name="backup_confirmed" value="1" required> Potwierdzam posiadanie sprawdzonej kopii zapasowej bazy.</label></p>';
        echo '<p><label><input type="checkbox" name="service_confirmed" value="1" required> Potwierdzam, że na tym systemie nie pracują użytkownicy ani integracje.</label></p>';
        echo '<p><label for="hds3_phrase">Wpisz dokładnie <code>'.esc_html(self::PHRASE).'</code></label><br><input class="regular-text" id="hds3_phrase" name="confirm_phrase" autocomplete="off" required></p>';
        submit_button('Wyczyść dane platformy — nieodwracalnie', 'delete');
        echo '</form></div>';
    }

    public static function execute() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        check_admin_referer('hds3_data_reset_execute');
        $phrase = isset($_POST['confirm_phrase']) && is_string($_POST['confirm_phrase']) ? wp_unslash($_POST['confirm_phrase']) : '';
        if ($phrase !== self::PHRASE || ($_POST['backup_confirmed'] ?? '') !== '1' || ($_POST['service_confirmed'] ?? '') !== '1') {
            wp_die('Nie potwierdzono pełnego resetu.', '', ['response'=>400]);
        }
        global $wpdb;
        $tables = self::discovered();
        $options = self::options();
        $failed = [];
        // DELETE preserves structures, so existing table-based initializers can reuse them.
        foreach ($tables as $table) {
            if ($wpdb->query('DELETE FROM `'.$table.'`') === false) $failed[] = $table;
        }
        if (!$failed) {
            foreach ($options as $name) {
                if (!delete_option($name)) {
                    // Option may already have been removed by a hook; verify actual state.
                    if (get_option($name, null) !== null) $failed[] = 'option:'.$name;
                }
            }
        }
        // Fresh installations must not reactivate legacy SEO/Agency after option cleanup.
        if (!$failed) update_option('hds3_legacy_integrations_enabled', '0', false);
        error_log('[Heterodyna Cloud] explicit fresh-start reset, admin ID '.get_current_user_id().'; failed: '.implode(',', $failed));
        wp_safe_redirect(add_query_arg('hds3_reset_result', $failed ? 'error' : 'ok', admin_url('admin.php?page=hds3_data_reset')));
        exit;
    }
}
