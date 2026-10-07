# Procedimento: violação de dados pessoais (interno)

O que fazer se houver (ou se suspeitar de) acesso indevido, perda, destruição ou divulgação de dados pessoais. **Modelo a rever.** Última revisão: {{atualizado}}.

## Prazos que contam
- **72 horas** desde que se toma conhecimento para notificar a **CNPD** (<https://www.cnpd.pt>), a não ser que seja improvável que a violação resulte em risco para as pessoas.
- **Sem demora** para informar as **pessoas afetadas**, se o risco for elevado.
- Se o Lumina agir como **subcontratante**, avisar **o negócio (cliente) sem demora**, para ele cumprir as suas obrigações.

## Passos
1. **Conter**: terminar sessões (`sessions_revoke_all`), mudar palavras-passe e chaves (`config/app_key.php`, credenciais do Google e do email), desligar o que estiver comprometido.
2. **Avaliar**: que dados, de quantas pessoas, desde quando, qual o risco. O registo de auditoria (`audit_log`) e os logs do servidor ajudam.
3. **Registar** tudo (mesmo as violações que não forem notificadas): o que aconteceu, efeitos, medidas.
4. **Notificar** a CNPD (se aplicável) com: natureza da violação, categorias e número aproximado de pessoas e registos, contacto, consequências prováveis e medidas.
5. **Informar as pessoas** (se risco elevado) em linguagem simples: o que aconteceu, que dados, o que fazer para se protegerem.
6. **Corrigir e aprender**: causa, correção, e o que muda para não se repetir.

## Contactos
- Responsável: {{responsavel}}. Email: {{email}}.
- CNPD: <https://www.cnpd.pt>.
