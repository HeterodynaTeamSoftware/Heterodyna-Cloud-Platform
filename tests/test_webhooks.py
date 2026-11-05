from pathlib import Path
root=Path(__file__).resolve().parents[1]
s=(root/'includes/class-hds3-webhooks.php').read_text()
main=(root/'heterodyna-saas-v3-core.php').read_text()
alerts=(root/'includes/class-hds3-alerts.php').read_text()
for needle in ['wp_safe_remote_post','hash_hmac','wp_http_validate_url','redirection','hds3_automation_handlers','check_admin_referer','manage_options','HDS3_Automation::enqueue','X-Heterodyna-Timestamp']:
 assert needle in s, needle
assert 'HDS3_Webhooks::init()' in main
assert "do_action('hds3_webhook_alert_recorded'" in alerts
assert s==(root/'engine_payload/hds3_core/includes/class-hds3-webhooks.php').read_text()
print('PASS: webhook static security and wiring')
