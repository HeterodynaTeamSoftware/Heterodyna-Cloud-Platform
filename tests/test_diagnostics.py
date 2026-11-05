from pathlib import Path
r=Path(__file__).resolve().parents[1]
main=(r/'heterodyna-saas-v3-core.php').read_text()
code=(r/'includes/class-hds3-diagnostics.php').read_text()
assert "HDS3_Diagnostics::init();" in main
assert "current_user_can('manage_options')" in code
assert 'esc_html((string)$value)' in code
assert 'HDS3_Automation::EVENT' in code
assert 'SHOW TABLES LIKE %s' in code
assert 'UTC_TIMESTAMP() - INTERVAL 24 HOUR' in code
print('PASS: read-only diagnostics wiring, permissions, escaping, cron and error summaries')
