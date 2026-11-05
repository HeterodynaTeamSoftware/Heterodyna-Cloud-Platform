import re
from pathlib import Path
r=Path(__file__).resolve().parents[1]
p=(r/'includes/class-hds3-subscriptions.php').read_text()
m=(r/'heterodyna-saas-v3-core.php').read_text()
assert "check_admin_referer('hds3_subscriptions_save')" in p
assert "current_user_can('manage_options')" in p
assert 'UNIQUE KEY tenant_id' in p
assert "self::transition_allowed($from,$status)" in p
assert 'HDS3_Subscriptions::maybe_install()' in m
assert re.search(r"define\('HDS3_VERSION',\s*'\d{2}\.\d{2}\.\d{3}'\)", m)
assert (r/'engine_payload/hds3_core/includes/class-hds3-subscriptions.php').read_text()==p
print('Subscription static contract PASS')
