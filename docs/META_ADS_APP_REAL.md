# Meta Ads: do teste à app real

**Estado honesto:** a ligação à Meta foi **testada apenas contra um simulador** (`tests/meta_mock.py`), nunca contra a Meta verdadeira. O código está **preparado** (OAuth com `state` de uso único, token cifrado, só leitura), mas **não foi validado com uma app real, uma conta de anúncios real nem a Revisão da app**. Nada neste guia foi executado por mim: são passos para fazeres tu, com a tua conta Meta. Os nomes dos menus e as regras da Meta mudam; confirma sempre em <https://developers.facebook.com/docs/> antes de seguir.

## 1. O que o Lumina pede e faz

| | |
|---|---|
| Permissão pedida | só `ads_read` (ler campanhas, conjuntos, anúncios e métricas) |
| O que **não** faz | criar, editar, pausar ou apagar anúncios; ler páginas, mensagens ou perfis |
| Dados que a Meta nos dá | id e nome do utilizador Facebook, contas de anúncios (id, nome, moeda), campanhas/anúncios e métricas (gasto, impressões, cliques, etc.) |
| Onde ficam | `meta_connections` (token **cifrado** com libsodium) e uma cópia das métricas para mostrar no painel; apagados quando o utilizador se desliga ou elimina a conta |
| Código | `includes/meta_ads.php`, `api/meta.php`, `config/meta.php` |

## 2. Credenciais (nunca no código)

1. `GF_META_APP_ID` e `GF_META_APP_SECRET` em **variáveis de ambiente** (Apache `SetEnv`, systemd, painel do alojamento) **ou** em `config/meta.local.php` (ignorado pelo Git). Nunca em `config/meta.php`, nunca no GitHub, nunca no navegador, nunca em capturas de ecrã ou logs.
2. Se a chave secreta alguma vez aparecer num sítio público, **gera uma nova** em Definições da app › Básico › «Repor chave secreta».
3. A chave secreta só existe no servidor; o navegador só vê o `client_id` (público por natureza).

## 3. Lista de passos na Meta (por ordem)

1. **Conta de programador** em developers.facebook.com, com a tua conta Facebook pessoal verificada.
2. **Criar a app** (tipo «Empresa»/Business). Adicionar o produto de início de sessão (Facebook Login).
3. **URI de redirecionamento OAuth** exatamente igual à usada pelo Lumina: `https://O-TEU-DOMINIO/api/meta.php?action=callback`. Em modo ativo a Meta exige **HTTPS**; em desenvolvimento aceita `http://localhost`. O domínio e o HTTPS **ainda não estão definidos** — decisão tua (não alterei nada de domínio/alojamento/DNS).
4. **Definições da app › Básico** — preencher o que a Meta exige para a app poder ficar ativa:
   - **URL da Política de Privacidade:** a do Lumina (`/politica-privacidade.php`). Tem de estar **pública e a funcionar** no domínio real.
   - **Eliminação de dados:** a Meta pede um *URL de instruções* ou um *callback de eliminação*. O Lumina tem eliminação de conta no ecrã (`api/account.php`, `delete_account`); falta uma **página pública** que explique como o fazer (por escrever; precisa do teu OK por ser uma página nova) e o texto da política de privacidade atualizado (ver `docs/RGPD_INTEGRACOES.md`, ainda por escrever).
   - Categoria, ícone da app e email de contacto.
5. **Modo de desenvolvimento primeiro:** enquanto a app está em desenvolvimento, só administradores, programadores e testadores da app a podem usar — chega para a **tua** conta de anúncios e para demonstrar na PAP. Adiciona-te e adiciona quem for testar em Funções da app.
6. **Teste real mínimo** (modo de desenvolvimento, conta de anúncios tua, de preferência com pouca ou nenhuma despesa): ligar, escolher a conta de anúncios, ver os números, comparar com o Gestor de Anúncios, desligar e confirmar que o token deixou de existir (`SELECT COUNT(*) FROM meta_connections`).
7. **Revisão da app (App Review) e Verificação da empresa**, só quando houver clientes reais que não são testadores:
   - pedir **acesso avançado** à permissão `ads_read`;
   - a Meta pede **uma descrição de uso**, um **vídeo (screencast)** a mostrar o fluxo completo (ligar → ver dados → desligar) e instruções de teste;
   - pode exigir **verificação da empresa** (documentos da empresa) e **Contrato de Termos de Dados**; os prazos e requisitos são da Meta e não os controlo.
8. **Passar a app a «Ativa/Live»** só depois dos passos anteriores.

## 4. Antes de ligar clientes reais (do lado do Lumina)

- [ ] Domínio com **HTTPS** válido e `redirect_uri` igual nos dois lados.
- [ ] `GF_META_APP_ID` / `GF_META_APP_SECRET` só no servidor.
- [ ] Política de privacidade com a secção da Meta (que dados, para quê, durante quanto tempo, transferência internacional para os EUA). Proposta em `docs/RGPD_INTEGRACOES.md` — **ainda por escrever**; não alterei `legal/documentos/*`.
- [ ] Confirmar a **versão da Graph API** em `config/meta.php` (`v21.0` à data em que foi escrito; a Meta retira versões antigas ao fim de cerca de dois anos — **não verifiquei a versão atual**).
- [ ] Tratar a expiração do token (o painel já mostra «dias que faltam» e pede para voltar a ligar).
- [ ] Limites de pedidos da Meta (rate limits da Graph API): o Lumina guarda um *snapshot*; não foi testado sob carga real.
- [ ] Aviso ao utilizador no ecrã a dizer que só lemos dados e como desligar (já existe o botão de desligar; o texto de consentimento não foi revisto juridicamente).

## 5. Riscos conhecidos

- A Meta pode recusar ou pedir alterações na revisão: prazo e resultado fora do meu controlo.
- Utilizadores sem acesso à conta de anúncios recebem erro de permissão (tratado no código com mensagem clara; testado só no simulador).
- Os dados de anúncios podem conter dados de terceiros (públicos-alvo agregados); o Lumina guarda só métricas agregadas, não listas de pessoas. Confirmar quando houver dados reais.
