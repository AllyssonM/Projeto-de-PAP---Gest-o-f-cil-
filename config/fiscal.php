<?php
/* =========================================================================
   DATAS FISCAIS INDICATIVAS  (config/fiscal.php)
   -------------------------------------------------------------------------
   As regras que alimentam o cartão "Próximas datas fiscais" da Visão geral. ESTÁ AQUI, e não no código,
   para poderes atualizar quando a lei mudar, sem mexer no programa.

   ATENÇÃO: são datas INDICATIVAS, baseadas nas regras gerais. Os prazos podem mudar (prorrogações, feriados,
   regimes especiais). Confirma sempre no Portal das Finanças, na Segurança Social Direta ou com o teu contabilista.
   REVISÃO: confirma estas regras uma vez por ano (campo 'reviewed').

   Cada regra:  'when'  => se aplica a que respostas do questionário (chave => valores aceites)
                'months' => meses do ano em que acontece (1 a 12); 'day' => dia do mês ('last' = último dia)
                'title', 'detail', 'kind' ('iva' | 'ss' | 'irs')
   ========================================================================= */
return [
    'reviewed'   => '2026-10-03',
    'disclaimer' => 'Datas indicativas. Confirma no Portal das Finanças, na Segurança Social Direta ou com o teu contabilista.',
    'rules' => [
        // IVA trimestral: declaração até dia 15 e pagamento até dia 25 do 2.º mês a seguir ao trimestre (fev, mai, ago, nov)
        ['when' => ['vat' => ['quarterly']], 'months' => [2, 5, 8, 11], 'day' => 15, 'kind' => 'iva', 'title' => 'IVA: entregar a declaração periódica', 'detail' => 'Declaração do trimestre anterior.'],
        ['when' => ['vat' => ['quarterly']], 'months' => [2, 5, 8, 11], 'day' => 25, 'kind' => 'iva', 'title' => 'IVA: pagar', 'detail' => 'Pagamento do IVA do trimestre anterior.'],
        // IVA mensal: declaração até dia 10 e pagamento até dia 25 do 2.º mês a seguir
        ['when' => ['vat' => ['monthly']], 'months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'day' => 10, 'kind' => 'iva', 'title' => 'IVA: entregar a declaração periódica', 'detail' => 'Declaração de há dois meses.'],
        ['when' => ['vat' => ['monthly']], 'months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'day' => 25, 'kind' => 'iva', 'title' => 'IVA: pagar', 'detail' => 'Pagamento do IVA de há dois meses.'],
        // Segurança Social (trabalhador independente): declaração trimestral e pagamento mensal
        ['when' => ['ss' => ['independent']], 'months' => [1, 4, 7, 10], 'day' => 'last', 'kind' => 'ss', 'title' => 'Segurança Social: declaração trimestral', 'detail' => 'Rendimentos do trimestre anterior.'],
        ['when' => ['ss' => ['independent']], 'months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'day' => 20, 'kind' => 'ss', 'title' => 'Segurança Social: último dia para pagar', 'detail' => 'Pagamento entre os dias 10 e 20.'],
        // IRS: entrega de 1 de abril a 30 de junho (regra geral)
        ['when' => [], 'months' => [4], 'day' => 1, 'kind' => 'irs', 'title' => 'IRS: abre a entrega da declaração', 'detail' => 'A entrega costuma ir até 30 de junho.'],
        ['when' => [], 'months' => [6], 'day' => 30, 'kind' => 'irs', 'title' => 'IRS: último dia para entregar', 'detail' => 'Regra geral. Confirma o prazo do ano.'],
    ],
];
