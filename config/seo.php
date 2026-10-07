<?php
/* =========================================================================
   SEO: PESQUISA E PARTILHAS  (config/seo.php)
   -------------------------------------------------------------------------
   Define como o Lumina aparece no Google e quando alguém partilha o link (WhatsApp, LinkedIn, Facebook...).
   O ENDEREÇO PÚBLICO do site define-se em config/app.php (APP_URL). Em produção, PREENCHE-O.
   Para o Google Search Console: cola abaixo o código de verificação (o Google dá-te um) e submete
   <o-teu-endereço>/sitemap.xml. Ver docs/SEO.md.
   ========================================================================= */
return [
    'site_name'   => 'Lumina',
    // Texto da página inicial nos resultados do Google (entre 70 e 160 caracteres)
    'home_title'  => 'Lumina: gestão simples e clara para microempresários',
    'home_description' => 'O Lumina é a ferramenta de gestão para microempresários: caixa, contas, clientes, estoque, calendário e relatórios em PDF, adaptada ao teu ramo.',
    'og_image'    => 'assets/img/og-lumina.jpg',          // 1200x630, aparece nas partilhas
    'locale'      => 'pt_PT',
    // Verificação dos motores de pesquisa (opcional): só o código, sem o resto da etiqueta
    'google_site_verification' => '',
    'bing_site_verification'   => '',
];
