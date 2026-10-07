<?php
/* =========================================================================
   CONFIGURAÇÃO DO GOOGLE CALENDAR  (config/google.php)
   -------------------------------------------------------------------------
   SEM ESTAS CREDENCIAIS O BOTÃO "LIGAR AO GOOGLE CALENDAR" NÃO FUNCIONA, e o sistema
   diz isso mesmo ("não configurado"), em vez de fingir uma ligação.

   COMO CONFIGURAR (uma vez, por quem instala o sistema):
     1. Google Cloud Console > crie um projeto > "APIs e serviços" > ative a "Google Calendar API".
     2. "Ecrã de consentimento OAuth": preencha os dados da app (nome, email) e adicione os
        âmbitos abaixo. Enquanto a app estiver em "Teste", só os emails que adicionar em
        "Utilizadores de teste" conseguem ligar.
     3. "Credenciais" > "Criar credenciais" > "ID de cliente OAuth" > tipo "Aplicação Web".
        Em "URIs de redirecionamento autorizados" ponha EXATAMENTE o endereço do ponto 4.
     4. Redirect URI de exemplo no XAMPP:
          http://localhost/lumina/api/google.php?action=callback
     5. Copie o ID de cliente e o segredo para aqui em baixo (ou use as variáveis de ambiente
        GF_GOOGLE_CLIENT_ID e GF_GOOGLE_CLIENT_SECRET, melhor numa hospedagem a sério).

   MENOR PRIVILÉGIO: pedimos só dois âmbitos:
     - calendar.calendarlist.readonly : ver a LISTA dos teus calendários (para escolheres quais sincronizar)
     - calendar.events                : ler, criar e apagar EVENTOS
   Não pedimos acesso a email, contactos, Drive nem à palavra-passe do Google (nunca a vemos).

   Este ficheiro contém um SEGREDO: está protegido pelo .htaccess da pasta config; não o
   partilhe nem o envie para o GitHub.
   ========================================================================= */
return [
    'client_id'     => getenv('GF_GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GF_GOOGLE_CLIENT_SECRET') ?: '',
    'redirect_uri'  => '',            // vazio = calculado automaticamente a partir do endereço do site
    // endereços oficiais da Google (só se mudam em testes)
    'auth_uri'      => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_uri'     => 'https://oauth2.googleapis.com/token',
    'revoke_uri'    => 'https://oauth2.googleapis.com/revoke',
    'api_base'      => 'https://www.googleapis.com/calendar/v3',
    'scopes'        => [
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/calendar.events',
    ],
];
