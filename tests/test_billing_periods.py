from pathlib import Path
p=Path(__file__).resolve().parents[1]
s=(p/'includes/class-hds3-billing-periods.php').read_text()
m=(p/'heterodyna-saas-v3-core.php').read_text()
assert 'UNIQUE KEY tenant_month' in s
assert "current_user_can('manage_options')" in s
assert 'check_admin_referer' in s
assert 'HDS3_Usage::totals' in s
assert 'payment_status' in s and 'not_tracked' in s
assert 'HDS3_Billing_Periods::maybe_install()' in m
assert (p/'engine_payload/hds3_core/includes/class-hds3-billing-periods.php').read_bytes()==(p/'includes/class-hds3-billing-periods.php').read_bytes()
print('PASS Billing period invariants (static)')
