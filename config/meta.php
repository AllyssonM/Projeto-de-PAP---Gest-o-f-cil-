<?php
/* =========================================================================
   CONFIGURAÇÃO DA META ADS  (config/meta.php)
   -------------------------------------------------------------------------
   SEM ESTAS CREDENCIAIS O BOTÃO "LIGAR META ADS" NÃO FUNCIONA, e o sistema diz isso
   mesmo ("não configurado"), em vez de fingir uma ligação.

   COMO CONFIGURAR (uma vez, por quem instala o sistema):
     1. developers.facebook.com > "Criar app" > tipo "Empresa" (Business).
     2. Adicione o produto "Início de sessão do Facebook para Empresas" (ou "Facebook Login").
     3. Em "Definições do início de sessão" > "URIs de redirecionamento do OAuth válidos",
        ponha EXATAMENTE o endereço do ponto 4. (Em modo ativo a Meta exige HTTPS; em modo de
        desenvolvimento aceita http://localhost.)
     4. Redirect URI de exemplo:   https://o-teu-site.pt/api/meta.php?action=callback
     5. Definições da app > Básico: copie o "ID da app" e a "Chave secreta da app" para aqui
        (ou use as variáveis de ambiente GF_META_APP_ID e GF_META_APP_SECRET — melhor numa hospedagem).
     6. Permissão pedida: apenas "ads_read" (ler anúncios e métricas). Para utilizadores que não são
        administradores/testadores da app, a Meta exige "Revisão da app" (App Review) para ads_read.

   O Lumina só LÊ dados (campanhas, conjuntos, anúncios e métricas). Não cria, edita nem pausa anúncios.
   Este ficheiro contém um SEGREDO: está protegido pelo .htaccess da pasta config; não o partilhe.
   ========================================================================= */
return [
    'app_id'        => getenv('GF_META_APP_ID') ?: '',
    'app_secret'    => getenv('GF_META_APP_SECRET') ?: '',
    'redirect_uri'  => '',                 // vazio = calculado automaticamente a partir do endereço do site
    'api_version'   => 'v21.0',            // versão da Graph API (confirme a versão em vigor em developers.facebook.com/docs/graph-api/changelog)
    'auth_base'     => 'https://www.facebook.com',
    'graph_base'    => 'https://graph.facebook.com',
    'scopes'        => ['ads_read'],
];
