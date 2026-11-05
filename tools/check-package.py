#!/usr/bin/env python3
"""Read-only offline consistency check; run from the plugin root."""
from pathlib import Path
import re, subprocess, sys
root = Path(__file__).resolve().parents[1]
local = root / 'includes'
mirror = root / 'engine_payload' / 'hds3_core' / 'includes'
files = sorted(p.name for p in local.glob('*.php'))
mirrors = sorted(p.name for p in mirror.glob('*.php'))
errors = []
if not set(mirrors).issubset(set(files)): errors.append('Engine payload contains files missing from canonical modules')
for name in set(files) & set(mirrors):
    if (local / name).read_bytes() != (mirror / name).read_bytes():
        errors.append('Unsynchronized engine mirror: ' + name)
main = (root / 'heterodyna-saas-v3-core.php').read_text()
version = re.search(r"define\('HDS3_VERSION','([^']+)'\)", main)
engine_version = re.search(r"return '([^']+)'", (root / 'engine_payload/hds3_core/engine-version.php').read_text())
if not version or not engine_version or version.group(1) != engine_version.group(1):
    errors.append('Plugin and engine versions differ')
if "require_once $local;" not in main or "require_once $engine;" in main:
    errors.append('Runtime loader is not local-only')
php_files=list(root.rglob('*.php'))
for path in php_files:
    result=subprocess.run(['php','-l',str(path)],capture_output=True,text=True)
    if result.returncode: errors.append('PHP syntax: '+str(path.relative_to(root))+' '+result.stderr.strip())
print('Modules:',len(files),'PHP files:',len(php_files),'version:',version.group(1) if version else '?')
if errors:
    print('FAILED\n'+'\n'.join(errors));sys.exit(1)
print('PASS: local runtime modules, mirrored payload, matching versions and PHP syntax')
