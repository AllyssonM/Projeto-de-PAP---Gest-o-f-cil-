# SEO do Lumina

Este documento responde ao checklist de SEO ponto por ponto e diz o que **tens de fazer tu** (o código não consegue).
Resultados medidos com o Lighthouse (Chromium, telemóvel simulado) nas páginas públicas: **Performance 97 a 100, Acessibilidade 100, Boas práticas 100, SEO 100**
(antes: Performance 66, SEO 91, e a página inicial pesava 4,6 MB e demorava 24 s a mostrar o conteúdo principal; agora pesa 58 KB e demora 1,4 s).

## O que é público e o que é privado

| Público (indexável, no sitemap) | Privado (`noindex`, fora do Google) |
|---|---|
| Página inicial `/` | Painel (`dashboard.php`), Área pessoal |
| `/politica-privacidade`, `/politica-cookies`, `/termos-e-condicoes`, `/informacao-legal` | Recuperar palavra-passe, redefinir, confirmar email, página offline, toda a API |

As páginas privadas levam `noindex` na etiqueta **e** no cabeçalho HTTP (`X-Robots-Tag`), porque têm dados de uma conta.

## O teu checklist, ponto por ponto

| Item | Estado | Onde |
|---|---|---|
| ❌ Tags noindex (nas páginas que querem aparecer) | **Nenhuma** nas páginas públicas; só nas privadas, de propósito | `includes/seo.php`; teste «SEM noindex» |
| Meta títulos | Único por página, 15 a 65 caracteres | `config/seo.php`, `legal/documentos/*.md` |
| Meta descrições | Única por página, 70 a 160 caracteres. A das páginas legais escreve-se no `.md` (linha `Descrição:`) | `config/seo.php`, `legal/documentos/*.md` |
| Alt text nas imagens | Todas têm `alt` (as decorativas, `alt=""`); teste automático | testes `tests/run.php` |
| Core Web Vitals | LCP 24,4 s → **1,4 s**; CLS 0 a 0,025; TBT 20 a 190 ms | ver abaixo |
| Sitemap.xml | Gerado, só com páginas públicas, `lastmod` verdadeiro | `sitemap.php` (servido como `/sitemap.xml`) |
| og:image | 1200×630, 53 KB, mais Open Graph e Twitter card completos | `assets/img/og-lumina.jpg` |
| ❌ Links quebrados | **Zero**: um rastreador testa todos os links e recursos internos | teste «NENHUM link interno quebrado» |
| Hierarquia de headers | Um `h1` por página, sem saltos (h1 > h2 > h3); testado | testes |
| Estratégia de backlinks | Plano escrito abaixo (não se pode automatizar) | esta página |
| Limpar os slugs de URL | `/politica-privacidade` em vez de `politica-privacidade.php`; o endereço antigo faz 301 para o novo | `.htaccess`, `router.php` |
| Links internos | Início ⇄ páginas legais (rodapé, caminho de navegação «Início › Página», ligações entre documentos) | `includes/legal.php` |
| Tags canónicas | Em todas as páginas públicas, absolutas e iguais ao endereço limpo | `seo_head()` |
| Forçar HTTPS | Redireciona para https (301) em qualquer endereço real, **menos localhost**; respeita proxies (`X-Forwarded-Proto`); HSTS de 1 ano em https | `.htaccess` |
| Comprimir todas as imagens | Fundo 4,5 MB → **45 KB** (WebP, 14 KB no telemóvel); guarda automática: nenhuma imagem acima de 120 KB | `assets/css/fundo*.webp`; teste de peso |
| Schema markup | JSON-LD: Organization, WebSite, SoftwareApplication (início); WebPage + BreadcrumbList (legais). **Sem preços nem avaliações inventadas** | `seo_schema_*()` |
| Verificar o Search Console | Precisa de ti (ver abaixo). O código de verificação põe-se em `config/seo.php` | `config/seo.php` |
| Só 1 h1 por página | Testado | testes |
| Criar um robots.txt | Gerado, bloqueia pastas internas e indica o sitemap | `robots.php` |
| Corrigir a responsividade mobile | Sem scroll horizontal de 320 a 1920 px; viewport; alvos de toque ≥ 24 px | testes de responsividade |

### Core Web Vitals: o que mudou
- Fundo em WebP (4,5 MB → 45 KB; versão de 14 KB em ecrãs até 900 px) e **pré-carregado** (era ele que fazia o LCP de 24 s).
- Scripts com `defer` (não bloqueiam o desenho), imagens com largura e altura (sem saltos de layout).
- Compressão gzip e cache do navegador (imagens 30 dias, CSS/JS 1 dia).
- O mesmo CSS e o mesmo desenho: o gradiente por cima do fundo é o original.

## O que tens de fazer para a PRODUÇÃO (5 minutos)

1. **Preenche `APP_URL`** em `config/app.php` (ex.: `https://lumina.exemplo.pt`). É o endereço que o Google vai guardar, usado no canonical, no sitemap e nas partilhas. Em localhost deixa vazio.
2. Põe o Lumina atrás de **HTTPS** (certificado). Sem ele, o redirecionamento 301 leva a um endereço que não responde.
3. Confirma que o Apache tem os módulos `rewrite`, `headers`, `expires` e `deflate` e `AllowOverride All` na pasta do Lumina. No XAMPP já vêm, mas o `AllowOverride` pode estar a `None`.
4. Se não usares Apache, replica as regras do `.htaccess` (nginx, etc.).

## Google Search Console (só tu podes fazer)

O Search Console precisa de **provar que o site é teu**, e isso depende do teu domínio.
1. Vai a <https://search.google.com/search-console> e adiciona a propriedade (o teu endereço).
2. Escolhe a verificação por **etiqueta HTML**; o Google dá-te um código. Cola-o em `config/seo.php` (`google_site_verification`) e carrega em Verificar.
3. Em **Sitemaps**, submete `sitemap.xml`.
4. Em **Inspeção de URL**, testa a página inicial e pede indexação.
5. Volta passadas 1 a 2 semanas: **Páginas** (o que está indexado e porquê), **Desempenho** (palavras que trazem visitas), **Experiência** (Core Web Vitals reais) e **Ligações** (quem aponta para ti).

Para o Bing, o mesmo com `bing_site_verification` no Bing Webmaster Tools.

## Estratégia de backlinks (plano, não automatizável)

Backlinks são ligações de **outros sites** para o Lumina. Não se criam por código, e nunca se compram (o Google penaliza). O que funciona, por ordem de esforço:

1. **O que já controlas (hoje):** o repositório do projeto (README com ligação), os perfis de LinkedIn dos três autores, e a página do projeto na escola/instituição da PAP.
2. **Conteúdo útil que merece ser citado:** guias curtos para microempresários portugueses (por exemplo «como organizar a caixa ao fim do dia» ou «que datas fiscais acompanhar»). Escreve-os no próprio site (uma secção «Guias») para ganhar também pesquisa orgânica.
3. **Parcerias com quem serve o teu público:** contabilistas, associações empresariais e comerciais locais, gabinetes de apoio ao empreendedor. Uma menção ou ligação deles vale mais do que dezenas de diretórios.
4. **Demonstração com casos reais:** quando houver 3 a 5 negócios a usar (barbeiro, loja, motorista...), um pequeno testemunho com a ligação deles.
5. **Diretórios e listas de ferramentas:** só os relevantes e sérios (software para PME, empreendedorismo em Portugal). Poucos e bons.

Regras: texto de ligação natural (nada de «melhor software barato»), nunca trocas em massa, nunca redes de links. Mede o resultado em Search Console > Ligações.

## Manter

- **Escreveste uma página pública nova?** Acrescenta o `.md` em `legal/documentos/` (com `Descrição:`), o ficheiro `.php` de 3 linhas, e o nome em `SEO_PUBLIC_PAGES` (`includes/seo.php`) **e** no `.htaccess` (um teste confirma que as duas listas são iguais).
- **Nunca** ponhas imagens grandes em `assets/` (o teste falha acima de 120 KB). Converte para WebP.
- Para testar sem Apache: `php -S 127.0.0.1:8080 router.php`.
- Para medir: `npx lighthouse <endereço> --only-categories=seo,performance`.
