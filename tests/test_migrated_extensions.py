from pathlib import Path
r=Path(__file__).resolve().parents[1]
main=(r/'heterodyna-saas-v3-core.php').read_text()
reg=(r/'includes/class-hds3-extensions.php').read_text()
manager=(r/'includes/class-hds3-extension-manager.php').read_text()
for id,klass in [('performance','HDS3_Performance'),('automation','HDS3_Automation')]:
    assert "HDS3_Extensions::register('"+id+"'" in main
    assert "'boot' => array('"+klass+"', 'init')" in main
    assert (klass+'::init();') not in main
assert "'system' => !empty($descriptor['system'])" in reg
assert 'Moduł systemowy nie może zostać wyłączony' in manager
assert 'if (isset(self::$registry[$id])' in reg
print('PASS: protected migrated extensions')
