from pathlib import Path
R=Path(__file__).resolve().parents[1]
for base in (R/'includes',R/'engine_payload/hds3_core/includes'):
    auth=(base/'class-hds3-auth.php').read_text()
    rest=(base/'class-hds3-rest.php').read_text()
    hooks=(base/'class-hds3-webhooks.php').read_text()
    assert "hds3_tenant_guard_unavailable" in auth
    block=rest.split('public static function health(WP_REST_Request $req)',1)[1].split('global $wpdb;',1)[0]
    assert "'modules' =>" not in block
    assert 'is_wp_error($result)' in hooks and 'queue_error' in hooks
print('Security 168 regression PASS')
