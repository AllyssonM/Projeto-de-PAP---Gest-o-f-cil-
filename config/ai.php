<?php
/* =========================================================
   LUMINA - ASSISTENTE DE IA (chat no canto inferior direito do painel)

   A IA NUNCA tem acesso direto à base de dados. Só pode pedir ao programa
   consultas pré-definidas e seguras, sempre limitadas ao utilizador com
   sessão iniciada (o identificador vem da sessão, nunca do chat).

   Sem 'api_key' o chat funciona em "modo básico" (responde às perguntas
   mais comuns, sem IA). Com 'api_key' usa a IA (Claude) para compreender
   qualquer pergunta em linguagem natural.

   Este ficheiro está protegido pelo .htaccess da pasta config.
   Passos: ver README > "Lumina (assistente de IA)".
   ========================================================= */
return [
    // Chave da API da Anthropic (https://console.anthropic.com > API Keys). Vazio = modo básico.
    'api_key'   => '',
    'model'     => 'claude-sonnet-5-5',      // mais barato: 'claude-haiku-4-5-20251001'
    'api_url'   => 'https://api.anthropic.com/v1/messages',

    'max_tokens'            => 1200,
    'max_tool_rounds'       => 6,            // máximo de consultas encadeadas por pergunta
    'history_messages'      => 12,           // mensagens anteriores enviadas à IA (contexto)
    'rate_limit_per_minute' => 12,           // perguntas por minuto e por utilizador
    'max_message_length'    => 1000,
    'timeout'               => 45,

    // Utilizador MySQL SÓ DE LEITURA (recomendado; ver database/ia_utilizador_leitura.sql).
    // Vazio = usa uma ligação separada com as credenciais de config/database.php.
    'db_user'   => '',
    'db_pass'   => '',
];
