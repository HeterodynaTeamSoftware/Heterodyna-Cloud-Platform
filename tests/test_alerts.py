from pathlib import Path
r=Path(__file__).resolve().parents[1]
m=(r/'heterodyna-saas-v3-core.php').read_text()
a=(r/'includes/class-hds3-alerts.php').read_text()
assert "HDS3_Alerts::init();" in m
assert "HDS3_Alerts::maybe_install();" in m
assert "check_admin_referer('hds3_alert_scan')" in a
assert "check_admin_referer('hds3_alert_status')" in a
assert "current_user_can('manage_options')" in a
assert 'UNIQUE KEY fingerprint' in a
assert "WHERE status = 'failed'" in a
assert "LIMIT 100" in a
assert 'wp_mail(' not in a
print('PASS: alert schema, manual scan, nonce, role, history, no email')
