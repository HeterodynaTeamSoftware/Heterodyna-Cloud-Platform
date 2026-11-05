from pathlib import Path
r=Path(__file__).resolve().parents[1]
s=(r/"includes/class-hds3-notifications.php").read_text()
m=(r/"heterodyna-saas-v3-core.php").read_text()
assert "enabled'=>0" in s
assert "check_admin_referer" in s and "current_user_can" in s
assert "attempted_at >= %s" in s and "wp_mail(" in s
assert "HDS3_Notifications::init();" in m
assert (r/"engine_payload/hds3_core/includes/class-hds3-notifications.php").read_text()==s
print("Notifications checks PASS")
