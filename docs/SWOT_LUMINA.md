# SWOT do Lumina (11 de outubro de 2026)

Análise honesta do estado do projeto. Cada ponto tem uma prova ou indica que **não foi provado**. O que diz respeito a vendas ou a clientes é uma hipótese a validar, não um facto.

## Pontos fortes (internos)

- **Segurança de base sólida e testada:** consultas só com `?`, isolamento por negócio (`tenant_id` da sessão), CSRF em todos os POST, permissões no servidor, limite de pedidos (v17), cabeçalhos HTTP, segredos cifrados com libsodium. Prova: `tests/` (905 testes, 0 falhas no commit `13f2bf2`) e `tests/secret_scan.php`.
- **Sem dependências pesadas:** PHP 8.3 + MariaDB + JavaScript simples. Corre em XAMPP e, em teoria, num Raspberry Pi. Fácil de ler e de defender na PAP.
- **Cobertura funcional larga:** fluxo de caixa, estoque com variações, clientes, equipa, calendário, tempo ativo, importar/exportar CSV, alertas de estoque, relatório de vendas, RGPD (exportar e eliminar dados), PWA.
- **Honestidade nos números:** margem e rotação são marcadas como estimativas; o painel só mostra dados reais; as importações nunca sobrescrevem em silêncio e têm «anular».
- **Documentação em português:** cada ficheiro tem cabeçalho explicativo; `docs/GUIA_DO_PROJETO.md`, `docs/GUIA_DO_CODIGO.md`, guias do Raspberry Pi, Meta Ads e RGPD.

## Pontos fracos (internos)

- **Muito por provar fora de Linux:** Windows/XAMPP e os `.bat`, Raspberry Pi real, MySQL 8, Firefox, Safari, SMTP real. Só se testou MariaDB 10.11 e Chromium.
- **Meta Ads só contra simulador** (`tests/meta_mock.py`): nunca falou com a API real.
- **CSP sem `script-src`:** há scripts embutidos (`window.GF_USER`, `GF_CSRF`, `LUMINA_ACCOUNT_LANG`) que impedem aplicar a regra mais forte contra XSS. Falta o OK para os mover.
- **O CI não corre os testes de navegador** (Playwright); só os de PHP/HTTP.
- **Código escrito à mão sem framework:** mais superfície para erros e mais custo de manutenção; alguns ficheiros de API são longos.
- **Dependência de uma só pessoa a perceber o código:** o autor está a aprender o projeto agora (ver `docs/GUIA_DO_CODIGO.md`).
- **Textos legais são modelos** e a política de privacidade ainda não menciona que as cópias de segurança guardam dados apagados cerca de 6 meses.
- **Traduções EN/ES automáticas**, sem revisão humana.
- **Documentos antigos desatualizados:** `Lumina_Documento.docx`, `Lumina_Apresentacao.pptx`, `lumina-skill.skill` (não estão neste repositório).

## Oportunidades (externas)

- **Microempresas portuguesas que vendem a empresas** (decisão tomada: o Lumina só vende a empresas) e não querem ferramentas pesadas nem caras.
- **Escolher 2 a 3 setores** em que o Lumina seja claramente melhor (decisão ainda em aberto) e adaptar o vocabulário por ramo (já existe em `profiles.js`).
- **Integrações com serviços já usados:** Google Calendar e Drive (cópia cifrada), Meta (anúncios), faturação certificada (hoje só análise legal).
- **Autoalojamento barato** (Raspberry Pi) como argumento de privacidade e custo, depois de provado num Pi real.
- **Assistente com LLM gratuito** (por exemplo Groq) só depois de rever termos de uso de dados e RGPD, e com a chave sempre em variável de ambiente.
- **Apresentação da PAP:** a transparência sobre limites é um ponto a favor perante o júri.

## Ameaças (externas)

- **RGPD e legislação:** tratamento de dados de clientes e de funcionários, transferências internacionais (Open-Meteo recebe o IP, Meta, Google, LLMs). Precisa de revisão jurídica profissional.
- **Faturação certificada em Portugal** (software certificado pela AT): sem isto, o Lumina não pode substituir um programa de faturação.
- **Concorrência estabelecida** em gestão para PME (por exemplo programas de faturação e ERP com anos de mercado) e ferramentas gratuitas como folhas de cálculo.
- **Mudanças em APIs de terceiros** (Meta, Google, Open-Meteo) e limites de serviços gratuitos.
- **Risco de segurança de qualquer site público:** força bruta, XSS, fugas por configuração errada do servidor. Mitigado, mas nunca nulo.
- **Tempo e recursos de um projeto escolar:** manter, corrigir e dar suporte exige mais do que o prazo da PAP.

## O que fazer a seguir (por prioridade)

1. Abrir os Draft PRs e rever o código no GitHub (sem merge no `main` sem decisão tua).
2. Experimentar no Windows/XAMPP e escrever o que correu mal (a maior lacuna para a defesa).
3. Decidir o CSP `script-src` e o LLM gratuito (sim ou não).
4. Rever a política de privacidade com um profissional e aplicar o texto proposto em `docs/RGPD_INTEGRACOES.md`.
5. Escolher os 2 a 3 setores e falar com utilizadores reais antes de construir mais funcionalidades.
