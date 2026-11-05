from pathlib import Path
r=Path(__file__).resolve().parents[1]
a=(r/'includes/class-hds3-extension-manager.php').read_text()
assert 'function diagnostics(' in a
assert 'function depends_on(' in a
assert 'Brak zależności' in a and 'Wyłączona zależność' in a and 'Cykliczna zależność' in a
assert "esc_html($problem)" in a
assert a==(r/'engine_payload/hds3_core/includes/class-hds3-extension-manager.php').read_text()
print('Extension Manager 2.0 checks PASS')
