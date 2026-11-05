from pathlib import Path
r=Path(__file__).resolve().parents[1]
m=(r/'includes/class-hds3-extension-manager.php').read_text()
e=(r/'includes/class-hds3-extensions.php').read_text()
for x in ('current_user_can', 'check_admin_referer', 'update_option', 'wp_safe_redirect', 'admin_post_hds3_extension_toggle', "in_array($id, $other['requires']"):
    assert x in m,x
assert "['status'] = 'disabled'" in e
assert "'failed', 'disabled'" in e
print('Extension manager static checks PASS')
