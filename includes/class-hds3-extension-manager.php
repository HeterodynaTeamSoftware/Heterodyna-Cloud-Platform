<?php
/** Registered extension administration. Does not install or execute uploaded code. */
if (!defined('ABSPATH')) exit;

final class HDS3_Extension_Manager {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_hds3_extension_toggle', array(__CLASS__, 'toggle'));
    }

    public static function menu() {
        add_management_page('Heterodyna · Rozszerzenia', 'Heterodyna · Rozszerzenia', 'manage_options', 'hds3-extension-manager', array(__CLASS__, 'render'));
    }

    public static function toggle() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', array('response' => 403));
        check_admin_referer('hds3_extension_toggle');
        $id = isset($_POST['extension_id']) && is_string($_POST['extension_id']) ? sanitize_key(wp_unslash($_POST['extension_id'])) : '';
        $action = isset($_POST['desired_state']) && is_string($_POST['desired_state']) ? sanitize_key(wp_unslash($_POST['desired_state'])) : '';
        $extensions = HDS3_Extensions::all();
        if (!isset($extensions[$id]) || !in_array($action, array('enabled', 'disabled'), true)) {
            wp_die('Nieprawidłowe rozszerzenie lub stan.', '', array('response' => 400));
        }
        if (!empty($extensions[$id]['system'])) {
            wp_die('Moduł systemowy nie może zostać wyłączony.', '', array('response' => 403));
        }
        $disabled = HDS3_Extensions::disabled_ids();
        if ($action === 'disabled') {
            foreach ($extensions as $other) {
                if ($other['id'] !== $id && !in_array($other['id'], $disabled, true) && in_array($id, $other['requires'], true)) {
                    wp_die('Rozszerzenie jest wymagane przez inny aktywny moduł. Najpierw wyłącz zależny moduł.', '', array('response' => 409));
                }
            }
            $disabled[] = $id;
        } else {
            $disabled = array_values(array_diff($disabled, array($id)));
            foreach ($extensions[$id]['requires'] as $dependency) {
                if (!isset($extensions[$dependency]) || in_array($dependency, $disabled, true)) {
                    wp_die('Brak dostępnej, włączonej zależności.', '', array('response' => 409));
                }
            }
        }
        update_option('hds3_disabled_extensions', array_values(array_unique($disabled)), false);
        wp_safe_redirect(add_query_arg('hds3_updated', '1', admin_url('tools.php?page=hds3-extension-manager')));
        exit;
    }

    /** Explain dependency issues without invoking extension callbacks. */
    public static function diagnostics($extensions, $disabled) {
        $result = array();
        foreach ($extensions as $id => $item) {
            $issues = array();
            $seen = array();
            $stack = array($id);
            while ($stack) {
                $current = array_pop($stack);
                if (isset($seen[$current])) continue;
                $seen[$current] = true;
                $entry = isset($extensions[$current]) ? $extensions[$current] : null;
                if (!$entry) continue;
                foreach ($entry['requires'] as $dep) {
                    if (!isset($extensions[$dep])) {
                        $issues[] = 'Brak zależności: ' . $dep;
                    } elseif (in_array($dep, $disabled, true)) {
                        $issues[] = 'Wyłączona zależność: ' . $dep;
                    } elseif ($dep === $id || self::depends_on($extensions, $dep, $id)) {
                        $issues[] = 'Cykliczna zależność: ' . $dep;
                    } else {
                        $stack[] = $dep;
                    }
                }
            }
            if (!empty($item['error'])) $issues[] = 'Błąd uruchomienia: ' . $item['error'];
            $result[$id] = array_values(array_unique($issues));
        }
        return $result;
    }

    /** Reachability in the dependency graph. */
    private static function depends_on($extensions, $start, $target) {
        $pending = array($start);
        $seen = array();
        while ($pending) {
            $current = array_pop($pending);
            if ($current === $target) return true;
            if (isset($seen[$current]) || !isset($extensions[$current])) continue;
            $seen[$current] = true;
            foreach ($extensions[$current]['requires'] as $dep) $pending[] = $dep;
        }
        return false;
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', array('response' => 403));
        $extensions = HDS3_Extensions::all();
        $disabled = HDS3_Extensions::disabled_ids();
        $diagnostics = self::diagnostics($extensions, $disabled);
        echo '<div class="wrap"><h1>Heterodyna Cloud · Extension Manager 2.0</h1>';
        echo '<p>Menadżer pokazuje zarejestrowane rozszerzenia. Moduły systemowe Performance i Automation są uruchamiane przez rejestr i chronione przed wyłączeniem. Pozostałe starsze moduły nadal korzystają z poprzedniego mechanizmu.</p>';
        if (isset($_GET['hds3_updated']) && $_GET['hds3_updated'] === '1') echo '<div class="notice notice-success"><p>Zapisano ustawienia modułu.</p></div>';
        if (!$extensions) echo '<div class="notice notice-info"><p>Brak zarejestrowanych rozszerzeń. Moduły historyczne nie są jeszcze migrowane do rejestru.</p></div>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>Rozszerzenie</th><th>Wersja</th><th>Zależności</th><th>Stan</th><th>Diagnostyka</th><th>Akcja</th></tr></thead><tbody>'; 
            foreach ($extensions as $id => $item) {
                $is_disabled = in_array($id, $disabled, true);
                echo '<tr><td><strong>'.esc_html($item['name']).'</strong><br><code>'.esc_html($id).'</code></td>';
                echo '<td>'.esc_html($item['version']).'</td><td>'.esc_html(implode(', ', $item['requires']) ?: '—').'</td>';
                echo '<td>'.esc_html($is_disabled ? 'disabled' : $item['status']).'</td>';
                echo '<td>';
                if (empty($diagnostics[$id])) echo '<span style="color:#22783b">Bez wykrytych problemów zależności</span>';
                else foreach ($diagnostics[$id] as $problem) echo '<div style="color:#a52a2a">'.esc_html($problem).'</div>';
                echo '</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('hds3_extension_toggle');
                echo '<input type="hidden" name="action" value="hds3_extension_toggle">';
                echo '<input type="hidden" name="extension_id" value="'.esc_attr($id).'">';
                echo '<input type="hidden" name="desired_state" value="'.($is_disabled ? 'enabled' : 'disabled').'">';
                echo !empty($item['system']) ? '<span class="description">Moduł systemowy</span>' : '<button class="button button-secondary" type="submit">'.($is_disabled ? 'Włącz' : 'Wyłącz').'</button>';
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">Statusy dotyczą bieżącego uruchomienia PHP; wyłączenie modułu wymaga ponownego załadowania strony. Diagnostyka nie zastępuje testu działania w WordPress/MySQL.</p>';
        echo '</div>';
    }
}
