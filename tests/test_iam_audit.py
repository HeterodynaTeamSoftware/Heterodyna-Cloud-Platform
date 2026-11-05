from pathlib import Path
r=Path(__file__).resolve().parents[1]
a=(r/'includes/class-hds3-audit.php').read_text()
i=(r/'includes/class-hds3-iam.php').read_text()
m=(r/'heterodyna-saas-v3-core.php').read_text()
assert 'manage_members' in a and 'tenant_id=%s' in a
assert 'HDS3_Audit::record' in i and 'HDS3_Audit::routes()' in (r/'includes/class-hds3-rest.php').read_text()
assert "hds3_require('includes/class-hds3-audit.php')" in m
assert (r/'engine_payload/hds3_core/includes/class-hds3-audit.php').read_bytes() == (r/'includes/class-hds3-audit.php').read_bytes()
print('PASS IAM audit wiring')
