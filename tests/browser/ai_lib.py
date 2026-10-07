import os
import json, subprocess, re, urllib.request, urllib.error, http.cookiejar, time
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/')
def sql(q):
    """Corre uma consulta na base de dados do Lumina (cliente mysql). Variáveis: LUMINA_DB (gestao_facil), LUMINA_DB_USER (root), MYSQL_PWD (palavra-passe)."""
    return subprocess.run(['mysql','-u'+os.environ.get('LUMINA_DB_USER','root'),os.environ.get('LUMINA_DB','gestao_facil'),'-N','-e',q],capture_output=True,text=True).stdout.strip()
class Client:
    def __init__(self, email=None, pw=None):
        self.jar=http.cookiejar.CookieJar(); self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar)); self.csrf=''
        if email: self.login(email,pw)
    def req(self, method, path, data=None):
        r=urllib.request.Request(BASE+path, data=json.dumps(data).encode() if data is not None else None, method=method, headers={'Content-Type':'application/json'})
        try: x=self.op.open(r); return x.status, json.loads(x.read() or b'{}')
        except urllib.error.HTTPError as e:
            b=e.read(); 
            try: return e.code, json.loads(b)
            except Exception: return e.code, {'raw':b.decode(errors='replace')[:200]}
    def login(self,email,pw):
        s,d=self.req('POST','/api/auth.php?action=login',{'email':email,'password':pw}); self.csrf=d['csrf']; return s
    def ask(self, message, conv=None, **extra):
        body={'message':message,'csrf':self.csrf, **extra}
        if conv is not None: body['conversation_id']=conv
        return self.req('POST','/api/ai_chat.php',body)
