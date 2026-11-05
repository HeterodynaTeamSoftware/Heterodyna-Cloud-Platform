<?php
/**
 * Heterodyna Cloud Platform — read-only integration smoke test.
 * Run: wp eval-file wp-content/plugins/heterodyna-cloud-platform/tests/integration-smoke.php
 * This file requires an already bootstrapped WordPress installation.
 */
if (!defined('ABSPATH') || !isset($GLOBALS['wpdb'])) {
    fwrite(STDERR, "FAIL: WordPress must be bootstrapped via WP-CLI.\n");
    exit(2);
}
if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "FAIL: Run through WP-CLI, not over HTTP.\n");
    exit(2);
}
$checks = 0; $failed = 0;
$check = static function (string $label, bool $passed, string $detail = '') use (&$checks, &$failed): void {
    ++$checks;
    if (!$passed) ++$failed;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n";
};
$check('HDS3 bootstrap', defined('HDS3_VERSION') && class_exists('HDS3_DB'));
$check('Version format', defined('HDS3_VERSION') && preg_match('/^\d{2}\.\d{2}\.\d{3}$/', HDS3_VERSION) === 1);
$classes = ['HDS3_IAM', 'HDS3_Subscriptions', 'HDS3_Usage', 'HDS3_Automation', 'HDS3_Alerts'];
foreach ($classes as $name) {
    $check('Class ' . $name, class_exists($name));
}
$wpdb = $GLOBALS['wpdb'];
$tables = ['tenants', 'plans', 'licenses', 'tenant_members', 'subscriptions', 'usage_events', 'automation_jobs', 'alerts'];
foreach ($tables as $suffix) {
    $table = $wpdb->prefix . 'hds3_' . $suffix;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    $check('Database table ' . $suffix, $exists);
    if ($exists) {
        // Table identifier is derived only from a hard-coded allowlist above.
        $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
        $check('Readable table ' . $suffix, $count !== null && $wpdb->last_error === '');
    }
}
$check('Portal shortcode', shortcode_exists('heterodyna_cloud_portal'));
$check('REST API bootstrap', did_action('rest_api_init') === 0 || rest_get_server() instanceof WP_REST_Server);
$check('WP-Cron API', function_exists('wp_next_scheduled'));
echo "\nResult: " . ($checks - $failed) . '/' . $checks . ' passed' . "\n";
if ($failed) exit(1);
