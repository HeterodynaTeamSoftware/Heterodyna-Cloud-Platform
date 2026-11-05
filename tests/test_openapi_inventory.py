from pathlib import Path
import json,subprocess,sys
root=Path(__file__).resolve().parents[1]
spec=root/'docs/openapi-inventory.json'
before=spec.read_bytes()
subprocess.run([sys.executable,str(root/'tools/generate-openapi-inventory.py')],check=True,cwd=root)
assert spec.read_bytes()==before,'OpenAPI inventory stale'
data=json.loads(before)
assert data['info']['version']=='03.00.167'
assert len(data['paths'])>=15
assert all(op.get('x-security-review')=='required' for p in data['paths'].values() for op in p.values())
print('PASS OpenAPI inventory')
