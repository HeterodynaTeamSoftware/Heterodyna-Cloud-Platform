#!/usr/bin/env python3
"""Regenerate source-based API inventory; not a complete contract."""
from pathlib import Path
import re,json
root=Path(__file__).resolve().parents[1]
paths={}
for php in sorted((root/'includes').glob('*.php')):
 text=php.read_text(encoding='utf-8')
 for hit in re.finditer(r"register_rest_route\(\s*(?:\$ns|'heterodyna/v3')\s*,\s*'([^']+)'\s*,\s*\[",text):
  fragment=text[hit.end():hit.end()+1200]
  meth=re.search(r"'methods'\s*=>\s*(?:'([^']+)'|\[([^\]]+)\])",fragment)
  if not meth: continue
  methods=[m.strip().upper() for m in meth.group(1).split(',')] if meth.group(1) else [m.upper() for m in re.findall(r"'([A-Z]+)'",meth.group(2))]
  pub=re.search(r"'permission_callback'\s*=>\s*([^,\n]+)",fragment)
  publicly=bool(pub and '__return_true' in pub.group(1))
  path='/'+hit.group(1).lstrip('/')
  for method in methods:
   op={'tags':[path.strip('/').split('/')[0]],'summary':f'{method} {path}', 'description':'Wygenerowano z rejestracji trasy PHP. Parametry, odpowiedzi i zabezpieczenia wymagają dalszej weryfikacji ręcznej.', 'responses':{'200':{'description':'Odpowiedź endpointu; rzeczywisty schemat nieudokumentowany'},'default':{'description':'Błąd zgodny z obsługą WordPress REST'}},'x-heterodyna-source':php.name,'x-security-review':'required','x-permission-callback-public':publicly}
   if not publicly:op['security']=[{'WordPressSession':[]},{'BearerAuth':[]}]
   else:op['description']+=' Permission callback dopuszcza żądanie do handlera; NIE oznacza to, że operacja jest anonimowa ani że uwierzytelnianie w handlerze zostało zweryfikowane.'
   paths.setdefault(path,{})[method.lower()]=op
spec={'openapi':'3.0.3','info':{'title':'Heterodyna Cloud Platform REST API — inventory','version':'03.00.167','description':'Automatyczna inwentaryzacja tras, NIE certyfikowany kontrakt integracyjny. Nie należy zakładać, że endpointy są publiczne lub bezpieczne. Konieczna kontrola handlerów, parametrów i testy auth.'},'servers':[{'url':'/wp-json/heterodyna/v3'}],'components':{'securitySchemes':{'BearerAuth':{'type':'http','scheme':'bearer'},'WordPressSession':{'type':'apiKey','in':'header','name':'X-WP-Nonce','description':'Nonce WordPress dla zalogowanego użytkownika'}}},'paths':dict(sorted(paths.items()))}
(root/'docs/openapi-inventory.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('Routes:',len(paths),'operations:',sum(len(v) for v in paths.values()))
