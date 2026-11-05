<?php
/**
 * Built-in extension lifecycle registry. No dynamic arbitrary file inclusion.
 * A first step toward a stable extension API; existing legacy modules remain
 * loaded by the original bootstrap until their dependencies are migrated.
 *
 * @package HeterodynaCloud
 */
if (!defined('ABSPATH')) { exit; }

final class HDS3_Extensions {
    private static $booted = false;
    private static $registry = array();

    /** Register a built-in or third-party extension before the extensions boot hook. */
    public static function register($id, $descriptor) {
        if (self::$booted) return new WP_Error('hds3_extensions_locked', 'Extension registration is closed.');
        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $id)) {
            return new WP_Error('hds3_extension_id', 'Invalid extension ID.');
        }
        if (isset(self::$registry[$id])) return new WP_Error('hds3_extension_duplicate', 'Extension already registered.');
        if (!is_array($descriptor)) return new WP_Error('hds3_extension_descriptor', 'Invalid descriptor.');
        $name = isset($descriptor['name']) ? trim((string)$descriptor['name']) : '';
        $version = isset($descriptor['version']) ? trim((string)$descriptor['version']) : '';
        $callback = isset($descriptor['boot']) ? $descriptor['boot'] : null;
        if ($name === '' || $version === '' || !is_callable($callback)) {
            return new WP_Error('hds3_extension_invalid', 'Extension requires a name, version and callable boot.');
        }
        $requires = isset($descriptor['requires']) ? $descriptor['requires'] : array();
        if (!is_array($requires)) return new WP_Error('hds3_extension_requires', 'Dependencies must be an array.');
        foreach ($requires as $dep) {
            if (!is_string($dep) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $dep) || $dep === $id) {
                return new WP_Error('hds3_extension_requires', 'Invalid dependency.');
            }
        }
        self::$registry[$id] = array(
            'id' => $id, 'name' => $name, 'version' => $version,
            'requires' => array_values(array_unique($requires)),
            'system' => !empty($descriptor['system']),
            'boot' => $callback, 'status' => 'registered', 'error' => '',
        );
        return true;
    }

    /** Persisted opt-out for registered extensions only; legacy modules are not affected. */
    public static function disabled_ids() {
        $value = get_option('hds3_disabled_extensions', array());
        if (!is_array($value)) return array();
        return array_values(array_unique(array_filter($value, function($id) {
            // Protected infrastructure must boot even if an older option lists it.
            if (isset(self::$registry[$id]) && !empty(self::$registry[$id]['system'])) return false;
            return is_string($id) && preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $id);
        })));
    }

    /** Metadata only; never leak executable callbacks to callers. */
    public static function all() {
        $out = array();
        foreach (self::$registry as $id => $entry) {
            $item = $entry;
            unset($item['boot']);
            $out[$id] = $item;
        }
        return $out;
    }

    /** Dependency-ordered boot, rejecting unresolved dependencies and cycles. */
    public static function boot() {
        if (self::$booted) return;
        do_action('hds3_register_extensions');
        self::$booted = true;
        $disabled = self::disabled_ids();
        foreach ($disabled as $id) {
            if (isset(self::$registry[$id])) self::$registry[$id]['status'] = 'disabled';
        }
        $pending = array_values(array_diff(array_keys(self::$registry), $disabled));
        while ($pending) {
            $progress = false;
            foreach ($pending as $key => $id) {
                $deps = self::$registry[$id]['requires'];
                $waiting = false;
                $failed = false;
                foreach ($deps as $dep) {
                    if (!isset(self::$registry[$dep])) { $failed = true; break; }
                    if (in_array(self::$registry[$dep]['status'], array('failed', 'disabled'), true)) { $failed = true; break; }
                    if (self::$registry[$dep]['status'] !== 'active') $waiting = true;
                }
                if ($waiting && !$failed) continue;
                if ($failed) {
                    self::$registry[$id]['status'] = 'failed';
                    self::$registry[$id]['error'] = 'Missing or failed dependency';
                } else {
                    try {
                        call_user_func(self::$registry[$id]['boot']);
                        self::$registry[$id]['status'] = 'active';
                    } catch (Throwable $e) {
                        self::$registry[$id]['status'] = 'failed';
                        self::$registry[$id]['error'] = 'Boot failed';
                        error_log('[HDS3] Extension ' . $id . ' boot failed: ' . $e->getMessage());
                    }
                }
                unset($pending[$key]);
                $progress = true;
            }
            if (!$progress) {
                foreach ($pending as $id) {
                    self::$registry[$id]['status'] = 'failed';
                    self::$registry[$id]['error'] = 'Circular dependency';
                }
                break;
            }
        }
        do_action('hds3_extensions_booted', self::all());
    }
}
