<?php
/* =========================================================================
   DADOS LEGAIS DO LUMINA  (config/legal.php)
   -------------------------------------------------------------------------
   Estes dados aparecem na Política de Privacidade, na Política de Cookies, nos
   Termos e Condições e no rodapé. PREENCHE-OS com os dados reais de quem
   RESPONDE pelo tratamento dos dados (a equipa do projeto, a empresa...).
   O que ficar vazio simplesmente não aparece.

   IMPORTANTE: os textos legais do Lumina são TEXTOS-MODELO escritos de acordo com o que
   o sistema faz de facto. Antes de usar o Lumina com clientes reais, pede a um
   jurista (ou à tua escola, no caso da PAP) para os rever.
   ========================================================================= */
return [
    'name'        => 'Lumina',
    // Quem decide para que e como os dados são tratados (o "responsável pelo tratamento")
    'controller'  => 'Alisson Miguel Mota Madalena, Arthur Siqueira e Arthur Silva (projeto de PAP)',
    'tax_number'  => '',                   // NIF, se houver
    'address'     => '',                   // morada, se houver
    'email'       => '',                   // email para pedidos sobre privacidade (RECOMENDADO preencher)
    'phone'       => '',                   // telefone, se quiseres mostrar
    'updated'     => '2 de outubro de 2026',   // data da última revisão dos textos
    'needs_review' => true,                // mostra o aviso "texto-modelo, rever antes de uso comercial" (põe false depois de revisto)
    // O Livro de Reclamações Eletrónico é obrigatório só para certos fornecedores de bens/serviços a consumidores em Portugal.
    // Vê se se aplica a quem explora o Lumina; se sim, põe true (aparece uma ligação para livroreclamacoes.pt).
    'complaints_book' => false,
];
