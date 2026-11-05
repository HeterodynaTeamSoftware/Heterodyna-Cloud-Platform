from pathlib import Path
import re
r=Path(__file__).resolve().parents[1]
s=(r/'includes/class-hds3-extensions.php').read_text()
main=(r/'heterodyna-saas-v3-core.php').read_text()
assert "hds3_require('includes/class-hds3-extensions.php')" in main
assert 'HDS3_Extensions::boot();' in main
assert main.index('HDS3_Kernel::boot();') < main.index('HDS3_Extensions::boot();') < main.index('HDS3_Admin::init();')
assert re.search(r"define\('HDS3_VERSION','\d{2}\.\d{2}\.\d{3}'\)", main)
assert (r/'engine_payload/hds3_core/includes/class-hds3-extensions.php').read_bytes() == s.encode()
for token in ('hds3_register_extensions','Circular dependency','Missing or failed dependency','is_callable','unset($item[\'boot\'])'):
    assert token in s, token
print('PASS: extension registry bootstrap and mirror')
