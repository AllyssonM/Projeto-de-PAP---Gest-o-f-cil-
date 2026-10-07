"""Servidor Graph API SIMULADO da Meta (só para testar o Lumina; nada sai para a internet).
Uso: python3 tests/meta_mock.py PORTA
Simula: /dialog/oauth (devolve a pessoa ao redirect_uri com code+state), troca de código, token longo, /me, /me/adaccounts,
campanhas, conjuntos, anúncios e insights (com paginação numa lista). Códigos especiais: code=deny -> erro; code=expired -> token que dá erro 190;
code=noperm -> erro 200 (permissões); code=rate -> erro 17 (limite)."""
import json, sys, urllib.parse as up
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
PORT = int(sys.argv[1]); V = '/v21.0'
def camp(i, name, st='ACTIVE'): return {'id': f'c{i}', 'name': name, 'status': st, 'effective_status': st, 'objective': 'OUTCOME_SALES', 'daily_budget': '2500' if i % 2 else None, 'lifetime_budget': None if i % 2 else '100000'}
CAMPS = [camp(1, 'Perfumes — Vendas'), camp(2, 'Acessórios — Tráfego', 'PAUSED'), camp(3, 'Calçado — Leads')]
ADSETS = [{'id': f'as{i}', 'name': f'Conjunto {i}', 'campaign_id': f'c{(i-1)%3+1}', 'status': 'ACTIVE', 'effective_status': 'ACTIVE', 'daily_budget': '1000', 'optimization_goal': 'OFFSITE_CONVERSIONS'} for i in range(1, 5)]
ADS = [{'id': f'ad{i}', 'name': f'Anúncio {i}', 'adset_id': f'as{(i-1)%4+1}', 'campaign_id': f'c{(i-1)%3+1}', 'status': 'ACTIVE', 'effective_status': 'ACTIVE' if i != 5 else 'DISAPPROVED'} for i in range(1, 7)]
def ins(idk=None, idv=None, mult=1):
    r = {'spend': f'{12.5*mult:.2f}', 'impressions': str(1000*mult), 'clicks': str(40*mult), 'reach': str(800*mult), 'cpc': '0.31', 'cpm': '12.50', 'ctr': '4.00',
         'actions': [{'action_type': 'omni_purchase', 'value': str(3*mult)}, {'action_type': 'purchase', 'value': str(3*mult)}, {'action_type': 'link_click', 'value': '40'}],
         'action_values': [{'action_type': 'omni_purchase', 'value': str(75*mult)}], 'purchase_roas': [{'action_type': 'omni_purchase', 'value': '6.00'}]}
    if idk: r[idk] = idv
    return r
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def out(self, obj, status=200):
        b = json.dumps(obj).encode(); self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def err(self, code, msg, status=400): self.out({'error': {'message': msg, 'type': 'OAuthException', 'code': code, 'fbtrace_id': 'x'}}, status)
    def do_DELETE(self): self.out({'success': True})
    def do_GET(self):
        u = up.urlparse(self.path); q = {k: v[0] for k, v in up.parse_qs(u.query).items()}; p = u.path
        if p.endswith('/dialog/oauth'):
            code = 'deny' if q.get('deny') else 'GOODCODE'
            loc = q['redirect_uri'] + ('&' if '?' in q['redirect_uri'] else '?') + up.urlencode({'code': code, 'state': q['state']} if code != 'deny' else {'error': 'access_denied', 'state': q['state']})
            self.send_response(302); self.send_header('Location', loc); self.end_headers(); return
        if p == V + '/oauth/access_token':
            if q.get('grant_type') == 'fb_exchange_token': return self.out({'access_token': 'LONG_' + q['fb_exchange_token'], 'token_type': 'bearer', 'expires_in': 5183944})
            c = q.get('code', '')
            if c not in ('GOODCODE', 'expired', 'noperm', 'rate'): return self.err(100, 'Invalid verification code format.')
            return self.out({'access_token': {'GOODCODE': 'SHORT', 'expired': 'EXPIRED', 'noperm': 'NOPERM', 'rate': 'RATE'}[c], 'token_type': 'bearer'})
        tok = q.get('access_token', '')
        if not (p.startswith(V) and tok): return self.err(190, 'Invalid OAuth access token.', 401)
        if 'EXPIRED' in tok: return self.err(190, 'Error validating access token: Session has expired', 401)
        if 'RATE' in tok: return self.err(17, 'User request limit reached', 429)
        if not q.get('appsecret_proof'): return self.err(100, 'Missing appsecret_proof')
        r = p[len(V):]
        if r == '/me': return self.out({'id': '1001', 'name': 'Allysson Teste'})
        if r == '/me/adaccounts': return self.out({'data': [{'id': 'act_555001', 'account_id': '555001', 'name': 'Mota Importz — Conta principal', 'account_status': 1, 'currency': 'EUR', 'timezone_name': 'Europe/Lisbon', 'business_name': 'Mota Importz'},
                                                          {'id': 'act_555002', 'account_id': '555002', 'name': 'Conta secundária', 'account_status': 2, 'currency': 'USD'}]})
        if 'NOPERM' in tok and r.startswith('/act_'): return self.err(200, '(#200) Requires ads_read permission to access this ad account', 403)
        if r.endswith('/campaigns'):
            if q.get('after') == 'p2': return self.out({'data': [camp(3, 'Calçado — Leads')]})
            return self.out({'data': CAMPS[:2], 'paging': {'next': f'http://127.0.0.1:{PORT}{V}{r}?' + up.urlencode({**q, 'after': 'p2'})}})
        if r.endswith('/adsets'): return self.out({'data': ADSETS})
        if r.endswith('/ads'): return self.out({'data': ADS})
        if r.endswith('/insights'):
            lvl = q.get('level'); n = {'account': 1, 'campaign': 3, 'adset': 4, 'ad': 6}.get(lvl, 1)
            if lvl == 'account': return self.out({'data': [ins(mult=6)]})
            key = {'campaign': 'campaign_id', 'adset': 'adset_id', 'ad': 'ad_id'}[lvl]; pre = {'campaign': 'c', 'adset': 'as', 'ad': 'ad'}[lvl]
            return self.out({'data': [ins(key, f'{pre}{i}', i) for i in range(1, n + 1) if not (lvl == 'campaign' and i == 2)]})   # campanha pausada sem entrega: sem linha
        self.err(100, 'Unsupported get request')
ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
