from pathlib import Path
r=Path(__file__).resolve().parents[1]
a=(r/'includes/class-hds3-alerts.php').read_text()
m=(r/'heterodyna-saas-v3-core.php').read_text()
for s in ['hds3_monitor_hourly','wp_schedule_event','wp_clear_scheduled_hook','check_admin_referer(\'hds3_monitor_settings\')','scan_rules','failed_threshold','overdue_threshold','stale_minutes','current_user_can(\'manage_options\')']:
 assert s in a,s
assert "['HDS3_Alerts','deactivate']" in m
assert a==(r/'engine_payload/hds3_core/includes/class-hds3-alerts.php').read_text()
print('PASS: monitoring rules and scheduled lifecycle')
