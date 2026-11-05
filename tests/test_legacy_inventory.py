from pathlib import Path
r=Path(__file__).resolve().parents[1]
b=(r/'includes/class-hds3-legacy-module-bridge.php').read_text()
assert 'class HDS3_Legacy_Module_Bridge' in b
assert "'status' => 'legacy'" in b and "'status' => 'migrated'" in b
assert 'class_exists' in b
assert 'eval(' not in b and 'include ' not in b
css=(r/'assets/admin.css').read_text()
assert 'Cloud console canvas' in css
assert 'background-color:#f4f7fb!important' in css
main=(r/'heterodyna-saas-v3-core.php').read_text()
assert "hds3_require('includes/class-hds3-legacy-module-bridge.php');" in main
print('PASS legacy inventory and dashboard contrast')
