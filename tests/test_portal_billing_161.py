from pathlib import Path
p=Path(__file__).resolve().parents[1]
s=(p/'includes/class-hds3-customer-portal.php').read_text()
assert "HDS3_IAM::allowed($tid,$uid,'read')" in s
assert "!array_key_exists($requested,$accessible)" in s
assert 'WHERE tenant_id=%s ORDER BY month_key DESC LIMIT 12' in s
assert 'HDS3_Usage::report($selected, $usage_start, $usage_end)' in s
assert (p/'engine_payload/hds3_core/includes/class-hds3-customer-portal.php').read_bytes() == (p/'includes/class-hds3-customer-portal.php').read_bytes()
print('Portal 2.0 source checks: PASS')
