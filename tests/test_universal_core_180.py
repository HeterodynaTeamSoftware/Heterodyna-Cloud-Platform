from pathlib import Path
r=Path(__file__).resolve().parents[1]
main=(r/'heterodyna-saas-v3-core.php').read_text()
reset=(r/'includes/class-hds3-data-reset.php').read_text()
assert "class-hds3-universal-readiness.php" in main
assert "if (!$failed) update_option('hds3_legacy_integrations_enabled', '0', false)" in reset
assert 'hds3_universal_readiness' in (r/'includes/class-hds3-universal-readiness.php').read_text()
assert reset==(r/'engine_payload/hds3_core/includes/class-hds3-data-reset.php').read_text()
print('PASS: clean-start readiness and reset guard')
