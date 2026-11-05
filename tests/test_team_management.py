from pathlib import Path
r=Path(__file__).resolve().parents[1]
p=(r/'includes/class-hds3-customer-portal.php').read_text()
i=(r/'includes/class-hds3-iam.php').read_text()
assert 'check_admin_referer' in p and 'wp_safe_redirect' in p
assert "HDS3_IAM::allowed($tid, $actor, 'manage_members')" in p
assert 'is_last_owner' in i and 'hds3_last_owner' in i
assert 'get_user_by' in p and 'wp_nonce_field' in p
print('PASS: portal team management static checks')
