# RGPD e integrações: que dados vão para onde

**Aviso:** isto é um mapa técnico para preparar a política de privacidade; **não é aconselhamento jurídico**. Os textos legais do Lumina são modelos e devem ser revistos por quem tem competência para isso (ex.: o teu orientador, a escola ou um jurista) antes de haver clientes reais. **Não alterei `legal/documentos/*`**: as propostas de texto estão no fim, para decidires.

Legenda de estado: **Ativa** (já existe no código) · **Preparada, não ligada** · **Não existe** (ideia, sem código).

## 1. Integrações que já existem

| Integração | Estado | Dados enviados | Para quê | Quanto tempo / quem acede | Transferência internacional | Base legal (proposta) |
|---|---|---|---|---|---|---|
| **Assistente Lumina (IA)** — `api.anthropic.com` | Ativa só se houver chave (`LUMINA_AI_*`) | Pergunta do utilizador + os dados do negócio estritamente necessários (resultados de consultas à própria BD do negócio) | Responder no chat | Histórico no Lumina; registo de consultas apagado aos 180 dias. No fornecedor: segundo os termos dele (**confirmar**, não verifiquei a retenção atual) | Sim (EUA) | Consentimento/ativação da função |
| **Email (SMTP)** — o que configurares | Driver `log` por omissão (nada sai) | Email do destinatário, nome, conteúdo do aviso (links de conta; alertas de estoque com nomes de produtos e quantidades) | Recuperar palavra-passe, convites, alertas ao dono | No fornecedor de email escolhido | Depende do fornecedor | Execução do contrato / interesse legítimo; alertas: o dono ativa |
| **Google Calendar** (OAuth) | Ativa só com `GF_GOOGLE_*` | Token OAuth (cifrado) e eventos do calendário do utilizador | Sincronizar eventos | Até o utilizador desligar ou eliminar a conta | Sim (EUA) | Consentimento |
| **Meta Ads** (OAuth, só leitura) | Preparada; **testada só em simulador** | Token OAuth (cifrado); recebemos contas de anúncios e métricas agregadas | Mostrar desempenho dos anúncios | Até desligar/eliminar conta | Sim (EUA) | Consentimento |
| **Open-Meteo** (tempo) | Ativa no painel, **chamada do navegador** | O **IP** do utilizador e a cidade/coordenadas pedidas (o navegador contacta diretamente `api.open-meteo.com`) | Widget do tempo | Sem conta nem cookies do nosso lado; política do serviço | Possível | Consentimento (localização opcional) / interesse legítimo |
| **Cópias de segurança** (local) | Ativa (`bin/backup_bd.php`) | Toda a BD (dados pessoais incluídos), cifrada se houver frase-passe | Recuperação de desastres | Retenção por omissão: 7 dias + 4 semanas + 6 meses; acede quem administra o servidor | Não (fica no teu servidor) | Interesse legítimo (segurança) |

Origens externas carregadas pelo JavaScript do site são vigiadas por um teste (`35_cabecalhos_http.php`); fontes/CDN externos: ver `docs/SEGURANCA_CABECALHOS.md` §3.

## 2. Integrações pedidas e ainda NÃO ligadas

| Integração | Estado | O que enviaria | Cuidados antes de existir |
|---|---|---|---|
| **Google Drive** (exportar/backup) | Não existe | Ficheiros de cópia **já cifrados** | Precisa de OAuth do utilizador (autorização dele); âmbito mínimo (`drive.file`); nunca guardar a frase-passe no Drive; documentar que a Google guarda cópias fora da UE |
| **Zapier** (eventos por webhook) | Não existe | Eventos assinados (ex.: «venda criada») | Só eventos escolhidos pelo utilizador, sem dados de clientes finais por omissão; assinatura HMAC; o Zapier é subcontratante nos EUA |
| **Postiz** (agendar publicações) | Não existe | Texto/imagens de publicações | Só **rascunhos**; nunca publicar sozinho |
| **Canva** | Não existe | — | Respeitar a identidade Lumina; só quando pedido |
| **Landbot** (chatbot FAQ) | Não existe | Perguntas dos visitantes | Só FAQ público; sem dados privados nem sessão; aviso de que a conversa vai para um terceiro |
| **Semrush** | Não existe | Domínio público para análise SEO | Sem gastar quota nem mudar SEO estrutural sem autorização |
| **Bright Data** | Não existe | Pedidos de recolha de páginas públicas | Rever Termos de Serviço dos sites, RGPD (dados pessoais públicos continuam a ser dados pessoais) e legalidade **antes** de qualquer código |
| **Figma** | Não existe | — | Só quando pedido |

## 3. Dados que **não** recolhemos de propósito

O Lumina não envia dados de clientes finais do negócio para nenhuma das integrações acima; o chatbot (Landbot, se existir) não deve receber dados privados; os alertas de estoque levam só nomes de produtos e quantidades, para o próprio dono.

## 4. Pontos a decidir (precisam de ti)

1. **Cópias de segurança vs. direito ao apagamento.** Quando alguém elimina a conta, os dados continuam nas cópias até elas expirarem (até ≈ 6 meses com a retenção por omissão). A política de privacidade atual **não diz isto** e a política interna de retenção deixa a questão em aberto. Opções: (a) dizê-lo na política (recomendado, é o que a prática habitual aceita desde que as cópias fiquem isoladas, protegidas e sejam repostas sem os dados apagados); (b) encurtar a retenção. **Não mudei nem o texto nem a retenção.**
2. **Open-Meteo:** a política deve mencionar que o navegador contacta um serviço externo e lhe revela o IP.
3. **Meta/Google/IA:** mencionar cada fornecedor ativado, e a transferência para os EUA e as garantias usadas (ex.: cláusulas contratuais-tipo ou decisão de adequação aplicável — **confirmar com quem de direito qual se aplica a cada fornecedor**).
4. **Prazo de resposta** a pedidos de titulares (já indicado: até um mês).

## 5. Texto proposto (para colar na política se concordares)

> **Cópias de segurança.** Fazemos cópias de segurança da base de dados para podermos recuperar o serviço em caso de falha. Quando eliminas a tua conta, os teus dados são apagados do sistema de imediato, mas podem permanecer em cópias de segurança cifradas durante um máximo de **seis meses**, até estas serem substituídas pelo ciclo normal de rotação. Essas cópias não são usadas para nenhum outro fim, ficam protegidas e, se for preciso repor uma, os dados já eliminados voltam a ser apagados antes de o sistema voltar a ser usado.

> **Previsão do tempo.** Se ativares o widget do tempo, o teu navegador contacta o serviço Open-Meteo e envia-lhe o teu endereço IP e a localização pedida, para devolver a previsão. Não partilhamos mais nada com este serviço.

> **Avisos por email.** Se ativares os avisos de estoque, enviamos ao teu email confirmado uma mensagem com os nomes e quantidades dos produtos que precisam de reposição. Podes desativá-los quando quiseres.

Quando ligares uma nova integração, acrescenta-lhe uma linha às tabelas acima (dados, finalidade, prazo, quem acede, transferência, base legal) **antes** de a ativar.
