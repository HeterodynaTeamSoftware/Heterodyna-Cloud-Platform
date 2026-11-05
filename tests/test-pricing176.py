from pathlib import Path
p=Path(__file__).resolve().parents[1]
s=(p/'includes/class-hds3-pricing.php').read_text()
main=(p/'heterodyna-saas-v3-core.php').read_text()
assert 'check_admin_referer' in s and 'current_user_can' in s
assert 'hds3_pricing_offers_v1' in s
assert 'HDS3_Pricing' in main
assert '03.00.182' in main
print('Pricing structure: PASS')
