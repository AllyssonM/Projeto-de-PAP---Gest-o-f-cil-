<?php
/* =========================================================================
   CONFIGURAÇÃO DO EMAIL  (config/mail.php)
   -------------------------------------------------------------------------
   O Lumina envia emails para: recuperar a palavra-passe, confirmar o email e convidar
   funcionários. Escolhe o 'driver':

     'log'   (por omissão) NÃO envia nada: grava cada email num ficheiro em storage/mail/.
             Serve para testar no XAMPP sem servidor de email. Abre o ficheiro .eml para ver o
             link de recuperação.
     'smtp'  envia por um servidor de email real (Gmail, Outlook, o email do teu alojamento...).
             Preenche a secção 'smtp' abaixo. Para o Gmail precisas de uma "palavra-passe de
             aplicação" (não a palavra-passe normal).
     'mail'  usa a função mail() do PHP (só funciona se o servidor estiver configurado para isso).

   Se ficar vazio, é deduzido do pedido (serve para localhost).
   ========================================================================= */
return [
    'driver'     => 'log',
    'from_email' => 'nao-responder@lumina.local',
    'from_name'  => 'Lumina',
    'smtp' => [
        'host'     => '',
        'port'     => 587,
        'security' => 'tls',        // 'tls' (STARTTLS, porta 587), 'ssl' (porta 465) ou 'none' (só para testes locais)
        'username' => '',
        'password' => '',
    ],
];
