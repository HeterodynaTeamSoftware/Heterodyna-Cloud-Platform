from pathlib import Path
p=Path(__file__).resolve().parents[1]
s=(p/'includes/class-hds3-data-reset.php').read_text()
assert "current_user_can('manage_options')" in s
assert "check_admin_referer('hds3_data_reset_execute')" in s
assert "self::PHRASE" in s and 'backup_confirmed' in s
assert 'DELETE FROM' in s and "$wpdb->query('DELETE FROM" in s
assert 'self::discovered()' in s
print('reset guard tests PASS')
