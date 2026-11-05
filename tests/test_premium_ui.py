from pathlib import Path
r=Path(__file__).resolve().parents[1]
a=(r/'includes/class-hds3-admin.php').read_text()
css=(r/'assets/premium-ui.css').read_text()
assert "wp_enqueue_style('hds3-premium-ui'" in a
assert "['hds3-admin-ui']" in a
assert a==(r/'engine_payload/hds3_core/includes/class-hds3-admin.php').read_text()
assert css.count('body.hts-cloud-app')>=15
assert 'body.hts-cloud-app .hts-app-sidebar' in css
assert 'body.hts-cloud-app #wpbody-content .wrap .widefat' in css
assert 'prefers-reduced-motion' in css
assert '03.00.182' in (r/'heterodyna-saas-v3-core.php').read_text()
print('PASS: premium UI enqueued after base CSS and limited to Cloud app')
