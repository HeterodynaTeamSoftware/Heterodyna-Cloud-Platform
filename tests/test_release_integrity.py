#!/usr/bin/env python3
"""Offline release checks; does NOT claim WordPress runtime security."""
import unittest, re
from pathlib import Path
root=Path(__file__).resolve().parents[1]
class ReleaseIntegrity(unittest.TestCase):
 def test_plugin_header_and_constants(self):
  data=(root/'heterodyna-saas-v3-core.php').read_text()
  header=re.search(r'(?m)^Version:\s*(\S+)',data)
  constant=re.search(r"define\('HDS3_VERSION','([^']+)'\)",data)
  mirror=re.search(r"return '([^']+)'",(root/'engine_payload/hds3_core/engine-version.php').read_text())
  self.assertIsNotNone(header)
  self.assertIsNotNone(constant)
  self.assertIsNotNone(mirror)
  self.assertEqual(header.group(1),constant.group(1))
  self.assertEqual(mirror.group(1),constant.group(1))
 def test_local_only_loader(self):
  data=(root/'heterodyna-saas-v3-core.php').read_text()
  section=data.split('function hds3_require(string $rel): void {',1)[1].split('\n}',1)[0]
  self.assertIn('require_once $local;',section)
  self.assertNotIn('require_once $engine;',section)
  self.assertIn('Invalid HDS3 class path',section)
 def test_payload_is_complete_exact_mirror(self):
  local={p.name:p.read_bytes() for p in (root/'includes').glob('*.php')}
  mirror={p.name:p.read_bytes() for p in (root/'engine_payload/hds3_core/includes').glob('*.php')}
  self.assertGreater(len(local),15)
  self.assertTrue(set(mirror).issubset(set(local)))
  self.assertEqual({k:local[k] for k in mirror},mirror)
 def test_security_guard_contract(self):
  auth=(root/'includes/class-hds3-auth.php').read_text()
  iam=(root/'includes/class-hds3-iam.php').read_text()
  rest=(root/'includes/class-hds3-rest.php').read_text()
  self.assertIn('function require_tenant_match(',auth)
  self.assertIn('function verify_bearer_or_die(',auth)
  self.assertIn('function permission(WP_REST_Request $r, $action)',iam)
  for scope in ('telemetry:write','license:activate','license:validate','verification:write'):
   self.assertIn("require_scope_or_error('"+scope+"')",rest)
 def test_all_route_permissions_declared(self):
  rest=(root/'includes/class-hds3-rest.php').read_text()
  self.assertGreater(rest.count('register_rest_route('),20)
  self.assertEqual(rest.count('register_rest_route('),rest.count("'permission_callback'"))
 def test_no_packaged_runtime_secrets(self):
  names={str(p.relative_to(root)) for p in root.rglob('*') if p.is_file()}
  for name in names:
   self.assertFalse(name.endswith(('.env','.pem','.key','.log','.sql','.sqlite','.db')),(name,'sensitive artifact'))
if __name__=='__main__':unittest.main()
