from pathlib import Path
r=Path(__file__).resolve().parents[1]
u=(r/'includes/class-hds3-usage.php').read_text()
i=(r/'includes/class-hds3-iam.php').read_text()
assert "const SCHEMA = '03.00.167'" in u
assert 'KEY tenant_time_metric (tenant_id,occurred_at,metric)' in u
assert "get_option('hds3_iam_schema') === '2'" in i
assert 'KEY tenant_role (tenant_id,role)' in i
for n in ['class-hds3-usage.php','class-hds3-iam.php']:
 assert (r/'includes'/n).read_bytes()==(r/'engine_payload/hds3_core/includes'/n).read_bytes()
print('PASS: non-destructive tenant query indexes and schema versioning')
