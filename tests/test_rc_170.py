from pathlib import Path
import re, unittest
r=Path(__file__).resolve().parents[1]
class RC170(unittest.TestCase):
 def test_version_and_notes(self):
  main=(r/'heterodyna-saas-v3-core.php').read_text()
  self.assertIn('Version: 03.00.182',main)
  self.assertIn("define('HDS3_VERSION','03.00.182')",main)
  self.assertIn("return '03.00.182'",(r/'engine_payload/hds3_core/engine-version.php').read_text())
  self.assertIn('03.00.182',(r/'readme.txt').read_text())
  self.assertTrue((r/'RELEASE-03.00.182.md').is_file())
 def test_no_build_artifacts(self):
  bad=[str(p) for p in r.rglob('*') if p.is_file() and (p.suffix in ('.pyc','.log','.db','.sql','.pem','.key') or '__pycache__' in p.parts) and '__pycache__' not in p.parts]
  self.assertEqual(bad,[])
if __name__=='__main__':unittest.main()
