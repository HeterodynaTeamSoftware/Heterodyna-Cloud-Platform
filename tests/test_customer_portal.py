from pathlib import Path
r=Path(__file__).resolve().parents[1]
code=(r/'includes/class-hds3-customer-portal.php').read_text()
main=(r/'heterodyna-saas-v3-core.php').read_text()
assert "add_shortcode('heterodyna_cloud_portal'" in main
assert 'HDS3_IAM::allowed($tid,$uid' in code
assert 'array_key_exists($requested,$accessible)' in code
assert 'm.user_id=%d AND t.status=%s' in code
assert 'wp_unslash' in code
assert 'esc_html' in code and 'esc_url' in code
assert 'INSERT INTO' not in code and 'UPDATE ' not in code
print('Customer portal static contract PASS')
