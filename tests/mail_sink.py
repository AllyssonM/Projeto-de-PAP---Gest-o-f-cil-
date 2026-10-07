"""Servidor SMTP local de teste (só para verificar o fluxo de email de ponta a ponta; não sai nada para a internet).
Uso: python3 tests/mail_sink.py PORTA PASTA   -> guarda cada mensagem aceite em PASTA/NNN.eml
Regras: utilizador "mau" -> 535 (autenticação); destinatário com "recusa" -> 550; destinatário com "rejeitadata" -> 554 no fim do DATA."""
import socketserver, sys, os, base64
PORT, OUT = int(sys.argv[1]), sys.argv[2]; os.makedirs(OUT, exist_ok=True); n = [0]
class H(socketserver.StreamRequestHandler):
    def send(self, t): self.wfile.write((t + '\r\n').encode()); self.wfile.flush()
    def handle(self):
        self.send('220 sink ESMTP'); user = ''; rcpt = []
        while True:
            line = self.rfile.readline().decode(errors='replace').strip()
            if not line: return
            c = line.upper()
            if c.startswith('EHLO'): self.wfile.write(b'250-sink\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n'); self.wfile.flush()
            elif c.startswith('AUTH LOGIN'):
                self.send('334 VXNlcm5hbWU6'); user = base64.b64decode(self.rfile.readline().strip()).decode(errors='replace')
                self.send('334 UGFzc3dvcmQ6'); self.rfile.readline()
                self.send('535 5.7.8 Authentication failed' if user == 'mau' else '235 ok')
            elif c.startswith('MAIL FROM'): self.send('250 ok')
            elif c.startswith('RCPT TO'):
                if 'recusa' in line.lower(): self.send('550 5.1.1 Mailbox unavailable')
                else: rcpt.append(line); self.send('250 ok')
            elif c == 'DATA':
                self.send('354 go'); data = []
                while True:
                    l = self.rfile.readline().decode(errors='replace')
                    if l.rstrip('\r\n') == '.': break
                    data.append(l[1:] if l.startswith('..') else l)
                body = ''.join(data)
                if any('rejeitadata' in r.lower() for r in rcpt): self.send('554 5.7.1 Message rejected as spam'); continue
                n[0] += 1; open(os.path.join(OUT, f'{n[0]:03d}.eml'), 'w').write('RCPT: ' + ';'.join(rcpt) + '\r\n' + body); self.send('250 2.0.0 queued')
            elif c == 'QUIT': self.send('221 bye'); return
            else: self.send('250 ok')
class S(socketserver.ThreadingTCPServer): allow_reuse_address = True; daemon_threads = True
S(('127.0.0.1', PORT), H).serve_forever()
