#!/usr/bin/env python3
"""Static security invariants, not a substitute for WordPress integration tests."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
for prefix in ('includes','engine_payload/hds3_core/includes'):
    auth=(root/prefix/'class-hds3-auth.php').read_text()
    iam=(root/prefix/'class-hds3-iam.php').read_text()
    rest=(root/prefix/'class-hds3-rest.php').read_text()
    assert "!is_array($tenant) ||" in auth
    assert "public static function permission(WP_REST_Request $r, $action)" in iam
    assert "'permission_callback'=>'is_user_logged_in'" not in iam
    for scope in ('telemetry:write','license:activate','license:validate','verification:write'):
        assert "require_scope_or_error('"+scope+"')" in rest
print('PASS: duplicated source tree IAM, fail-closed tenant, endpoint scopes')
