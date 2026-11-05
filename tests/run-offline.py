#!/usr/bin/env python3
"""Run static checks without a live WordPress installation."""
from pathlib import Path
import subprocess,sys
root=Path(__file__).resolve().parents[1]
checks=[['python3','-m','unittest','discover','-s',str(root/'tests'),'-p','test_rc_170.py','-v'],['python3',str(root/'tests/test_db_indexes_167.py')],['python3',str(root/'tests/test_premium_ui.py')],['python3',str(root/'tests/test_iam_audit.py')],['python3',str(root/'tests/test_business_extension_migration.py')],['python3',str(root/'tests/test_monitoring.py')],['python3',str(root/'tests/test_alerts.py')],['python3',str(root/'tools/check-package.py')],['python3',str(root/'tests/test_diagnostics.py')],['python3',str(root/'tests/test_api_guards.py')],['python3',str(root/'tests/test_subscriptions.py')],['python3',str(root/'tests/test_team_management.py')],['python3',str(root/'tests/test_extensions_registry.py')],['python3',str(root/'tests/test_extension_manager.py')],['python3','-m','unittest','discover','-s',str(root/'tests'),'-p','test_release_integrity.py','-v']]
for cmd in checks:
 print('RUN', ' '.join(cmd),flush=True)
 run=subprocess.run(cmd,cwd=root)
 if run.returncode:sys.exit(run.returncode)
print('PASS: all offline regression checks')
