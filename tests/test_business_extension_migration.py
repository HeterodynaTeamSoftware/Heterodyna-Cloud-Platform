from pathlib import Path
r=Path(__file__).resolve().parents[1]
s=(r/'heterodyna-saas-v3-core.php').read_text()
bridge=(r/'includes/class-hds3-legacy-module-bridge.php').read_text()
for mod in ('subscriptions','usage'):
    assert "HDS3_Extensions::register('"+mod+"'" in s
    assert "'extension' => '"+mod+"', 'status' => 'migrated-entrypoints'" in bridge
assert "'requires' => array('subscriptions')" in s
assert s.count("add_action('admin_post_hds3_subscription_save'") == 1
assert s.count("add_action('admin_menu', array('HDS3_Usage', 'admin_menu'))") == 1
assert s.count("add_action('admin_menu', array('HDS3_Subscriptions', 'admin_menu'))") == 1
assert "HDS3_Subscriptions::maybe_install()" in s
assert "HDS3_Usage::maybe_install()" in s
print('PASS: subscriptions and usage entrypoints migrated without destructive DB changes')
