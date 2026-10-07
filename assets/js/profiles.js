/* =========================================================================
   MOTOR DE RAMOS  (assets/js/profiles.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  personaliza a plataforma para cada ramo de atividade. Quem é
               corretor imobiliário vê "Imóveis", quem é barbeiro vê "Serviços",
               quem é TVDE vê "Viagens", quem vende carros vê "Viaturas"...
   O QUE MUDA PARA CADA RAMO:
     1. O VOCABULÁRIO  - nomes das abas, títulos, botões e etiquetas (o "produto").
     2. OS CAMPOS      - cada ramo tem campos próprios (ex.: tipologia e área nos
                         imóveis; marca, ano e km nos carros; duração nos cortes).
     3. AS CATEGORIAS  - sugestões para o fluxo de caixa (ex.: "Comissão de venda").
     4. AS DICAS       - uma dica útil do ramo, que roda no banner da Visão geral.
     5. A FERRAMENTA   - uma mini-calculadora do ramo (comissão, margem, ganho/hora...).
     6. OS MÓDULOS     - há ramos que não precisam de Clientes ou de Estoque.

   COMO SE LIGA AO RESTO:
     - dashboard.php tem elementos com  data-t="chave"  (texto) e  data-tp="chave"
       (placeholder). Este ficheiro troca esses textos pelos do ramo escolhido.
     - profile.js guarda o ramo na base de dados (api/profile.php) e chama
       GFP.apply(perfil) para aplicar tudo.
     - app.js usa GFP para desenhar os produtos com os campos do ramo.
     - api/data.php guarda os campos do ramo em products.attributes (JSON).

   COMO ACRESCENTAR UM RAMO NOVO (3 passos):
     1. Copia um bloco da lista RAMOS mais abaixo e muda os textos.
     2. Acrescenta a chave do ramo à lista $allowed em api/profile.php.
     3. (Opcional) acrescenta o ramo em database/ ... nada: a base de dados não muda.
   ========================================================================= */
(function () {
  'use strict';

  /* -----------------------------------------------------------------------
     1) CONSTRUTOR DE VOCABULÁRIO
     Em vez de escrever 25 textos à mão por ramo, damos só o essencial
     (como se chama o "produto" e o "cliente") e esta função gera os restantes.
       item     -> singular do produto      (ex.: 'Imóvel')
       plural   -> plural do produto        (ex.: 'Imóveis')
       fem      -> true se o produto é feminino ('Viagem', 'Viatura') -> "Nova"
       client / clients / cfem -> o mesmo para o cliente (ex.: 'Passageiro')
       tab, title, eyebrow, fName, fSku, fCategory, fCost, fPrice, fQty, fMin, unit
                -> opcionais: só para mudar um texto concreto
     ----------------------------------------------------------------------- */
  function vocab(o) {
    const item = o.item.toLowerCase();
    const client = (o.client || 'Cliente').toLowerCase();
    const clients = o.clients || 'Clientes';
    return {
      // aba, lista e formulário dos produtos
      stockTab: o.tab || o.plural,
      stockEyebrow: o.eyebrow || 'INVENTÁRIO',
      stockTitle: o.title || o.plural,
      stockNewEyebrow: `${o.fem ? 'NOVA' : 'NOVO'} ${o.item.toUpperCase()}`,
      stockNewTitle: `Adicionar ${item}`,
      stockSave: `Guardar ${item}`,
      stockEmpty: o.empty || `Ainda não há ${o.plural.toLowerCase()}. Adiciona o primeiro!`,
      qaProduct: `Adicionar ${item}`,
      calcCost: `Custo ${o.fem ? 'da' : 'do'} ${item} (€)`,
      // etiquetas dos campos fixos do formulário
      fName: o.fName || 'Nome', fSku: o.fSku || 'SKU', fCategory: o.fCategory || 'Categoria',
      fCost: o.fCost || 'Custo', fPrice: o.fPrice || 'Preço de venda',
      fQty: o.fQty || 'Quantidade', fMin: o.fMin || 'Estoque mínimo',
      unit: o.unit || 'unidades',
      // aba, lista e formulário dos clientes
      clientTab: clients, clientTitle: clients,
      clientEyebrow: `${o.cfem ? 'NOVA' : 'NOVO'} ${client.toUpperCase()}`,
      clientNewTitle: `Adicionar ${client}`, clientEditTitle: `Editar ${client}`, clientSave: `Guardar ${client}`,
      clientTotal: `Total de ${clients.toLowerCase()}`, qaClient: `Adicionar ${client}`,
    };
  }

  /* -----------------------------------------------------------------------
     2) OS RAMOS
     Campos de cada ramo:
       name, icon, subtitle, primary  -> identidade (título e subtítulo do banner)
       chips        -> os "módulos" mostrados no banner (como já era)
       trackStock   -> true: o produto tem unidades em estoque (roupa, loja...)
                       false: cada produto é único ou é um serviço (imóvel, carro, corte...)
       modules      -> que abas existem (clients / stock)
       vocab        -> ver vocab() acima
       fields       -> campos próprios do ramo. Cada campo:
                         key (a-z, 0-9, _), label, type ('text' | 'number' | 'select'),
                         options (para 'select'), suffix (ex.: ' m²'), min, step, placeholder,
                         status:true -> aparece como etiqueta colorida e pode mudar-se no cartão
       categories   -> sugestões de categorias para o fluxo de caixa
       tips         -> dicas do ramo (rodam no banner)
       tool         -> mini-calculadora do ramo (ver renderTool)
     ----------------------------------------------------------------------- */
  const CHIPS_FULL = ['Fluxo de caixa', 'Clientes', 'Contas', 'Estoque', 'Notas', 'Relatórios'];
  const CHIPS_LITE = ['Fluxo de caixa', 'Contas', 'Notas', 'Relatórios'];
  const ESTADO_VENDA = ['Disponível', 'Reservado', 'Vendido'];

  const RAMOS = {

    /* ---------- IMOBILIÁRIA / CORRETOR: o produto é um imóvel ---------- */
    real_estate: {
      name: 'Imobiliária', icon: '🏠', subtitle: 'Imóveis, clientes, visitas e contratos.', primary: 'Imóveis e negócios',
      chips: CHIPS_FULL, trackStock: false, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Imóvel', plural: 'Imóveis', eyebrow: 'CARTEIRA DE IMÓVEIS', title: 'A minha carteira',
        fName: 'Título do anúncio', fSku: 'Referência', fCategory: 'Tipo de negócio (venda, arrendamento…)',
        fCost: 'Valor de angariação', fPrice: 'Preço pedido', client: 'Cliente', clients: 'Clientes' }),
      fields: [
        { key: 'tipologia', label: 'Tipologia', type: 'select', options: ['T0', 'T1', 'T2', 'T3', 'T4', 'T5+', 'Moradia', 'Terreno', 'Loja'] },
        { key: 'area', label: 'Área (m²)', type: 'number', min: 0, step: 1, suffix: ' m²' },
        { key: 'localizacao', label: 'Localização', type: 'text', placeholder: 'Ex.: Leiria, Marrazes' },
        { key: 'estado', label: 'Estado', type: 'select', options: [...ESTADO_VENDA, 'Arrendado'], status: true },
        { key: 'comissao', label: 'Comissão (%)', type: 'number', min: 0, step: 0.5, suffix: '%' },
      ],
      categories: ['Comissão de venda', 'Comissão de arrendamento', 'Publicidade', 'Fotografia', 'Deslocações', 'Impostos', 'Renda do escritório'],
      tips: ['Regista a comissão de cada imóvel vendido: é assim que vês o mês realmente lucrativo.',
             'Marca as visitas no Calendário: o aviso de reunião lembra-te do tempo que falta.',
             'Muda o estado do imóvel (Disponível → Reservado → Vendido) diretamente no cartão.',
             'Guarda a localização exata: ajuda a agrupar visitas e poupar deslocações.'],
      tool: { title: 'Comissão da venda', intro: 'Quanto vais faturar de comissão num negócio.',
        inputs: [{ key: 'preco', label: 'Preço do imóvel (€)', value: 250000, step: 1000 }, { key: 'com', label: 'Comissão (%)', value: 5, step: 0.5 }, { key: 'iva', label: 'IVA (%)', value: 23, step: 1 }],
        outputs: [{ label: 'Comissão sem IVA', kind: 'money', fn: v => v.preco * v.com / 100 }, { label: 'IVA da comissão', kind: 'money', fn: v => v.preco * v.com / 100 * v.iva / 100 },
                  { label: 'Total a faturar', kind: 'money', fn: v => v.preco * v.com / 100 * (1 + v.iva / 100), main: true }] },
    },

    /* ---------- VENDA DE CARROS: o produto é uma viatura ---------- */
    cars: {
      name: 'Venda de carros', icon: '🚗', subtitle: 'Viaturas, compradores, vendas e documentos.', primary: 'Viaturas e vendas',
      chips: CHIPS_FULL, trackStock: false, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Viatura', plural: 'Viaturas', fem: true, eyebrow: 'STAND', title: 'As minhas viaturas',
        fName: 'Descrição da viatura', fSku: 'Matrícula / referência', fCategory: 'Segmento (citadino, SUV…)',
        fCost: 'Preço de compra', fPrice: 'Preço de venda', client: 'Comprador', clients: 'Compradores' }),
      fields: [
        { key: 'marca', label: 'Marca', type: 'text', placeholder: 'Ex.: Renault' },
        { key: 'modelo', label: 'Modelo', type: 'text', placeholder: 'Ex.: Clio' },
        { key: 'ano', label: 'Ano', type: 'number', min: 1980, max: 2100, step: 1 },
        { key: 'km', label: 'Quilómetros', type: 'number', min: 0, step: 1000, suffix: ' km' },
        { key: 'combustivel', label: 'Combustível', type: 'select', options: ['Gasolina', 'Diesel', 'Híbrido', 'Elétrico', 'GPL'] },
        { key: 'estado', label: 'Estado', type: 'select', options: ESTADO_VENDA, status: true },
      ],
      categories: ['Venda de viatura', 'Compra de viatura', 'Preparação e reparações', 'Documentação', 'Publicidade', 'Comissões', 'Seguros'],
      tips: ['Soma a preparação (limpeza, reparações) ao custo: só assim sabes a margem verdadeira.',
             'Viaturas paradas custam dinheiro: olha para as que estão há mais tempo "Disponíveis".',
             'Regista o preço de compra e de venda de cada viatura para ver o lucro de cada negócio.',
             'Usa a ferramenta "Margem da viatura" na aba Calculadora antes de negociar.'],
      tool: { title: 'Margem da viatura', intro: 'Lucro e margem de um negócio, já com as despesas.',
        inputs: [{ key: 'venda', label: 'Preço de venda (€)', value: 18000, step: 100 }, { key: 'compra', label: 'Preço de compra (€)', value: 15000, step: 100 }, { key: 'prep', label: 'Preparação e despesas (€)', value: 700, step: 50 }],
        outputs: [{ label: 'Lucro do negócio', kind: 'money', fn: v => v.venda - v.compra - v.prep, main: true }, { label: 'Margem sobre a venda', kind: 'percent', fn: v => v.venda ? (v.venda - v.compra - v.prep) / v.venda * 100 : 0 },
                  { label: 'Retorno sobre o investimento', kind: 'percent', fn: v => (v.compra + v.prep) ? (v.venda - v.compra - v.prep) / (v.compra + v.prep) * 100 : 0 }] },
    },

    /* ---------- MOTORISTA TVDE / UBER: o produto é uma viagem ---------- */
    uber: {
      name: 'Motorista TVDE / Uber', icon: '🚘', subtitle: 'Viagens, quilómetros, combustível e ganhos líquidos.', primary: 'Viagens e ganhos',
      chips: ['Fluxo de caixa', 'Contas', 'Viagens', 'Notas', 'Relatórios'], trackStock: false, modules: { clients: true, stock: true },
      insights: { kind: 'trips', title: 'Distância e custo-benefício', icon: '🚘' },
      vocab: vocab({ item: 'Viagem', plural: 'Viagens', fem: true, eyebrow: 'REGISTO DE VIAGENS', title: 'As minhas viagens',
        fName: 'Descrição da viagem', fSku: 'Referência', fCategory: 'Tipo (aeroporto, noite…)',
        fCost: 'Custo da viagem (combustível)', fPrice: 'Valor da viagem', client: 'Passageiro', clients: 'Passageiros' }),
      fields: [
        { key: 'origem', label: 'Origem', type: 'text', placeholder: 'Ex.: Leiria' },
        { key: 'destino', label: 'Destino', type: 'text', placeholder: 'Ex.: Aeroporto de Lisboa' },
        { key: 'distancia', label: 'Distância (km)', type: 'number', min: 0, step: 0.1, suffix: ' km' },
        { key: 'plataforma', label: 'Plataforma', type: 'select', options: ['Uber', 'Bolt', 'FreeNow', 'Particular'] },
        { key: 'estado', label: 'Estado', type: 'select', options: ['Concluída', 'Em curso', 'Cancelada'], status: true },
      ],
      categories: ['Viagens (ganhos)', 'Combustível', 'Comissão da plataforma', 'Portagens', 'Manutenção da viatura', 'Seguro', 'Lavagens', 'Impostos'],
      tips: ['Regista o combustível de cada dia: o ganho líquido é o que fica depois dele.',
             'Anota os quilómetros: ajuda a ver que viagens compensam mais por km.',
             'Separa a comissão da plataforma nas despesas para ver o que realmente ganhas.',
             'Guarda uma reserva para manutenção: um imprevisto no carro é um dia sem ganhos.'],
      tool: { title: 'Ganho líquido da viagem', intro: 'O que fica depois da comissão e do combustível.',
        inputs: [{ key: 'valor', label: 'Valor da viagem (€)', value: 12.5, step: 0.5 }, { key: 'com', label: 'Comissão da plataforma (%)', value: 25, step: 1 }, { key: 'km', label: 'Distância (km)', value: 8.2, step: 0.1 },
                 { key: 'cons', label: 'Consumo (L/100 km)', value: 6, step: 0.1 }, { key: 'preco', label: 'Preço do combustível (€/L)', value: 1.75, step: 0.01 }],
        outputs: [{ label: 'Comissão da plataforma', kind: 'money', fn: v => v.valor * v.com / 100 }, { label: 'Combustível gasto', kind: 'money', fn: v => v.km * v.cons / 100 * v.preco },
                  { label: 'Ganho líquido', kind: 'money', fn: v => v.valor - v.valor * v.com / 100 - v.km * v.cons / 100 * v.preco, main: true },
                  { label: 'Ganho líquido por km', kind: 'money', fn: v => v.km ? (v.valor - v.valor * v.com / 100 - v.km * v.cons / 100 * v.preco) / v.km : 0 }] },
    },

    /* ---------- MOTOCICLISTA / ESTAFETA: o produto é uma entrega ---------- */
    rider: {
      name: 'Motociclista / estafeta', icon: '🏍️', subtitle: 'Entregas, quilómetros, combustível e custo por km.', primary: 'Entregas e custos',
      chips: ['Fluxo de caixa', 'Contas', 'Entregas', 'Notas', 'Relatórios'], trackStock: false, modules: { clients: true, stock: true },
      insights: { kind: 'trips', title: 'Distância e custo-benefício', icon: '🏍️' },
      vocab: vocab({ item: 'Entrega', plural: 'Entregas', fem: true, eyebrow: 'REGISTO DE ENTREGAS', title: 'As minhas entregas',
        fName: 'Descrição da entrega', fSku: 'Referência', fCategory: 'Tipo (refeição, encomenda…)', fCost: 'Custo da entrega (combustível)', fPrice: 'Valor da entrega' }),
      fields: [
        { key: 'origem', label: 'Origem', type: 'text', placeholder: 'Ex.: Restaurante X' },
        { key: 'destino', label: 'Destino', type: 'text', placeholder: 'Ex.: Marrazes' },
        { key: 'distancia', label: 'Distância (km)', type: 'number', min: 0, step: 0.1, suffix: ' km' },
        { key: 'tipo', label: 'Tipo', type: 'select', options: ['Refeição', 'Encomenda', 'Documento', 'Outro'] },
        { key: 'estado', label: 'Estado', type: 'select', options: ['Concluída', 'Em curso', 'Cancelada'], status: true },
      ],
      categories: ['Entregas (ganhos)', 'Combustível', 'Manutenção da mota', 'Pneus e óleo', 'Seguro', 'Equipamento', 'Impostos'],
      tips: ['Regista cada reabastecimento: o custo por quilómetro mostra se a mota compensa.',
             'Entregas curtas pagam pior por km: compara no painel "Distância e custo-benefício".',
             'Guarda uma reserva para revisões e pneus: um imprevisto é um dia sem ganhos.',
             'Separa as despesas da mota das pessoais para veres o lucro real.'],
      tool: { title: 'Custo por quilómetro', intro: 'Quanto custa cada km da tua mota.',
        inputs: [{ key: 'cons', label: 'Consumo (L/100 km)', value: 3, step: 0.1 }, { key: 'preco', label: 'Preço do combustível (€/L)', value: 1.75, step: 0.01 }, { key: 'manut', label: 'Manutenção (€ por 1000 km)', value: 40, step: 5 }],
        outputs: [{ label: 'Combustível por km', kind: 'money', fn: v => v.cons / 100 * v.preco }, { label: 'Manutenção por km', kind: 'money', fn: v => v.manut / 1000 },
                  { label: 'Custo por km', kind: 'money', fn: v => v.cons / 100 * v.preco + v.manut / 1000, main: true }] },
    },

    /* ---------- BARBEIRO / CABELEIREIRO: o produto é um serviço ---------- */
    barber: {
      name: 'Barbearia / Cabeleireiro', icon: '💈', subtitle: 'Serviços, marcações, clientes e caixa do dia.', primary: 'Serviços e marcações',
      chips: CHIPS_FULL, trackStock: false, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Serviço', plural: 'Serviços', eyebrow: 'TABELA DE PREÇOS', title: 'Os meus serviços',
        fName: 'Nome do serviço', fSku: 'Código', fCategory: 'Categoria (cabelo, barba…)',
        fCost: 'Custo do serviço (produtos usados)', fPrice: 'Preço do serviço' }),
      fields: [
        { key: 'tipo', label: 'Tipo', type: 'select', options: ['Corte', 'Barba', 'Corte + barba', 'Tratamento', 'Coloração', 'Outro'] },
        { key: 'duracao', label: 'Duração (min)', type: 'number', min: 5, step: 5, suffix: ' min' },
        { key: 'profissional', label: 'Profissional', type: 'text', placeholder: 'Ex.: João' },
      ],
      categories: ['Serviços (cortes)', 'Serviços (barba)', 'Produtos de cabelo', 'Renda do espaço', 'Material e lâminas', 'Comissões dos profissionais', 'Publicidade'],
      tips: ['Marca cada cliente no Calendário: o aviso de reunião lembra-te de quem vem a seguir.',
             'Cria um serviço "Corte + barba" com preço próprio: vende mais e demora menos.',
             'Regista as gorjetas e as vendas de produtos separadas dos serviços.',
             'Vê o ganho por hora de cada serviço: o mais caro nem sempre é o que mais compensa.'],
      tool: { title: 'Ganho por hora', intro: 'Quanto rende cada serviço por hora de trabalho.',
        inputs: [{ key: 'preco', label: 'Preço do serviço (€)', value: 15, step: 0.5 }, { key: 'min', label: 'Duração (min)', value: 30, step: 5 }, { key: 'com', label: 'Comissão do profissional (%)', value: 40, step: 5 }],
        outputs: [{ label: 'Faturação por hora', kind: 'money', fn: v => v.min ? v.preco / v.min * 60 : 0, main: true }, { label: 'Para o profissional', kind: 'money', fn: v => v.preco * v.com / 100 },
                  { label: 'Para a casa', kind: 'money', fn: v => v.preco * (1 - v.com / 100) }] },
    },

    /* ---------- LOJA ONLINE ---------- */
    online_store: {
      name: 'Loja online', icon: '🛒', subtitle: 'Produtos, encomendas, estoque e clientes.', primary: 'Produtos e encomendas',
      chips: CHIPS_FULL, trackStock: true, variants: true, modules: { clients: true, stock: true },
      insights: { kind: 'sales', title: 'Produtos mais vendidos', icon: '🛒' },      // vendas reais ligadas ao estoque (api/insights.php)
      vocab: vocab({ item: 'Produto', plural: 'Produtos', tab: 'Estoque', title: 'Produtos' }),
      fields: [],                                                                  // tamanho, cor e marca vêm das variações (variants.js)
      categories: ['Vendas online', 'Fornecedores', 'Envios e portes', 'Embalagens', 'Publicidade (Instagram)', 'Plataforma e pagamentos', 'Impostos', 'Devoluções'],
      tips: ['Calcula o preço com margem e IVA na aba Calculadora antes de publicar o produto.',
             'Regista os portes de envio como despesa: muitas vezes comem a margem sem se notar.',
             'Define um estoque mínimo: o painel avisa quando é altura de repor.',
             'Separa o dinheiro dos impostos numa conta de reserva.'],
      tool: null,
    },

    /* ---------- LOJA DE ROUPA ---------- */
    clothing: {
      name: 'Loja de roupas', icon: '👕', subtitle: 'Tamanhos, cores, estoque, vendas e clientes.', primary: 'Coleção e estoque',
      chips: CHIPS_FULL, trackStock: true, variants: true, modules: { clients: true, stock: true },
      insights: { kind: 'sales', title: 'Produtos mais vendidos', icon: '👕' },
      vocab: vocab({ item: 'Peça', plural: 'Peças', fem: true, tab: 'Estoque', eyebrow: 'COLEÇÃO', title: 'A minha coleção', fName: 'Nome da peça', fCategory: 'Categoria (camisolas, calças…)' }),
      fields: [
        { key: 'colecao', label: 'Coleção', type: 'select', options: ['Primavera', 'Verão', 'Outono', 'Inverno', 'Atemporal'] },
      ],
      categories: ['Vendas', 'Fornecedores', 'Renda da loja', 'Montra e decoração', 'Embalagens', 'Publicidade', 'Ordenados', 'Impostos'],
      tips: ['Cada tamanho é uma linha de estoque: assim sabes quais saem primeiro.',
             'No fim da coleção, vê que peças ficaram: é aí que se decide o desconto.',
             'Regista as trocas e devoluções como movimentos: contam para o resultado.',
             'Usa a Calculadora para ver a margem antes de pôr uma peça em promoção.'],
      tool: null,
    },

    /* ---------- RESTAURANTE / CAFÉ ---------- */
    restaurant: {
      name: 'Restaurante / café', icon: '🍽️', subtitle: 'Pedidos, ingredientes, fornecedores e caixa.', primary: 'Pedidos e ingredientes',
      chips: CHIPS_FULL, trackStock: true, modules: { clients: true, stock: true },
      insights: { kind: 'orders', title: 'Mais pedidos', icon: '🍽️' },          // painel inteligente (assets/js/insights.js)
      vocab: vocab({ item: 'Prato', plural: 'Pratos', tab: 'Menu', eyebrow: 'EMENTA', title: 'A minha ementa', fName: 'Nome do prato', fCategory: 'Categoria (entrada, prato, sobremesa)',
        fCost: 'Custo dos ingredientes', fPrice: 'Preço na ementa', fQty: 'Porções disponíveis', fMin: 'Aviso com menos de', unit: 'porções' }),
      fields: [
        { key: 'tipo', label: 'Tipo', type: 'select', options: ['Entrada', 'Prato', 'Sobremesa', 'Bebida', 'Menu do dia'] },
        { key: 'tempo', label: 'Preparação (min)', type: 'number', min: 0, step: 5, suffix: ' min' },
        { key: 'alergenios', label: 'Alergénios', type: 'text', placeholder: 'Ex.: glúten, lactose' },
      ],
      categories: ['Vendas (sala)', 'Vendas (take-away)', 'Ingredientes', 'Bebidas', 'Ordenados', 'Renda', 'Eletricidade e gás', 'Plataformas de entrega'],
      tips: ['O custo dos ingredientes não deve passar de cerca de um terço do preço do prato.',
             'Regista o desperdício como despesa: é dinheiro que se vê e se pode reduzir.',
             'Cria um "Menu do dia" com preço fixo: facilita a caixa e reduz o desperdício.',
             'Atenção aos prazos dos fornecedores: negoceia pagar mais tarde.'],
      tool: { title: 'Custo do prato', intro: 'Quanto do preço é custo e quanto é lucro.',
        inputs: [{ key: 'custo', label: 'Custo dos ingredientes (€)', value: 3.2, step: 0.1 }, { key: 'preco', label: 'Preço na ementa (€)', value: 12, step: 0.5 }],
        outputs: [{ label: 'Custo sobre o preço', kind: 'percent', fn: v => v.preco ? v.custo / v.preco * 100 : 0 }, { label: 'Margem por prato', kind: 'money', fn: v => v.preco - v.custo, main: true },
                  { label: 'Margem sobre o preço', kind: 'percent', fn: v => v.preco ? (v.preco - v.custo) / v.preco * 100 : 0 }] },
    },

    /* ---------- OFICINA AUTO ---------- */
    mechanic: {
      name: 'Oficina auto', icon: '🔧', subtitle: 'Reparações, peças, orçamentos e clientes.', primary: 'Reparações e orçamentos',
      chips: CHIPS_FULL, trackStock: true, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Serviço ou peça', plural: 'Serviços e peças', tab: 'Serviços e peças', eyebrow: 'TABELA DA OFICINA', title: 'Serviços e peças',
        fName: 'Nome do serviço ou peça', fCategory: 'Categoria (travões, óleo…)', fCost: 'Custo (peça ou hora)', fPrice: 'Preço ao cliente', fQty: 'Peças em estoque', fMin: 'Aviso com menos de', unit: 'peças' }),
      fields: [
        { key: 'tipo', label: 'Tipo', type: 'select', options: ['Mão de obra', 'Peça', 'Diagnóstico', 'Revisão'] },
        { key: 'viatura', label: 'Viatura', type: 'text', placeholder: 'Ex.: Renault Clio 2015' },
        { key: 'garantia', label: 'Garantia (meses)', type: 'number', min: 0, step: 1, suffix: ' meses' },
      ],
      categories: ['Mão de obra', 'Venda de peças', 'Compra de peças', 'Ferramentas', 'Renda da oficina', 'Eletricidade', 'Ordenados', 'Impostos'],
      tips: ['Dá sempre o orçamento por escrito: usa a ferramenta "Orçamento" na aba Calculadora.',
             'Guarda a viatura de cada trabalho: ajuda se o cliente voltar com um problema.',
             'Define um estoque mínimo nas peças mais usadas (óleo, filtros, pastilhas).',
             'Regista a garantia: evita discussões e mostra profissionalismo.'],
      tool: { title: 'Orçamento', intro: 'Mão de obra, peças e IVA num só valor.',
        inputs: [{ key: 'h', label: 'Horas de trabalho', value: 2, step: 0.5 }, { key: 'hora', label: 'Preço por hora (€)', value: 40, step: 1 }, { key: 'pecas', label: 'Peças (€)', value: 85, step: 1 }, { key: 'iva', label: 'IVA (%)', value: 23, step: 1 }],
        outputs: [{ label: 'Mão de obra', kind: 'money', fn: v => v.h * v.hora }, { label: 'Subtotal (sem IVA)', kind: 'money', fn: v => v.h * v.hora + v.pecas },
                  { label: 'Total a cobrar', kind: 'money', fn: v => (v.h * v.hora + v.pecas) * (1 + v.iva / 100), main: true }] },
    },

    /* ---------- FREELANCER ---------- */
    freelancer: {
      name: 'Freelancer', icon: '💻', subtitle: 'Projetos, clientes, horas, serviços e recebimentos.', primary: 'Projetos e serviços',
      chips: ['Fluxo de caixa', 'Clientes', 'Contas', 'Notas', 'Relatórios'], trackStock: false, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Projeto', plural: 'Projetos', tab: 'Projetos', eyebrow: 'PORTFÓLIO', title: 'Os meus projetos', fName: 'Nome do projeto', fCategory: 'Tipo (site, design, aulas…)',
        fCost: 'Custos do projeto', fPrice: 'Valor do projeto' }),
      fields: [
        { key: 'horas', label: 'Horas estimadas', type: 'number', min: 0, step: 0.5, suffix: ' h' },
        { key: 'tipo', label: 'Cobrança', type: 'select', options: ['Projeto fechado', 'Por hora', 'Mensalidade'] },
        { key: 'estado', label: 'Estado', type: 'select', options: ['Proposta', 'Em curso', 'Entregue', 'Pago'], status: true },
      ],
      categories: ['Projetos (recebimentos)', 'Mensalidades', 'Software e ferramentas', 'Equipamento', 'Formação', 'Impostos', 'Segurança Social'],
      tips: ['Divide o valor do projeto pelas horas: é o teu preço real por hora.',
             'Marca "Pago" só quando o dinheiro entrar: o estado ajuda a ver o que ainda falta receber.',
             'Guarda parte de cada recebimento para impostos e Segurança Social.',
             'Contas a receber com data de vencimento: o painel avisa se alguém se atrasar.'],
      tool: { title: 'Preço por hora', intro: 'Quanto realmente ganhas por hora num projeto.',
        inputs: [{ key: 'valor', label: 'Valor do projeto (€)', value: 800, step: 50 }, { key: 'horas', label: 'Horas gastas', value: 20, step: 0.5 }, { key: 'desp', label: 'Despesas do projeto (€)', value: 60, step: 10 }],
        outputs: [{ label: 'Valor por hora (bruto)', kind: 'money', fn: v => v.horas ? v.valor / v.horas : 0 }, { label: 'Valor por hora (líquido)', kind: 'money', fn: v => v.horas ? (v.valor - v.desp) / v.horas : 0, main: true }] },
    },

    /* ---------- GESTÃO PESSOAL: sem clientes nem estoque ---------- */
    personal: {
      name: 'Gestão pessoal', icon: '👤', subtitle: 'Orçamento, despesas, objetivos e poupança.', primary: 'Orçamento pessoal',
      chips: CHIPS_LITE, trackStock: false, modules: { clients: false, stock: false },
      vocab: vocab({ item: 'Objetivo', plural: 'Objetivos' }),
      fields: [],
      categories: ['Salário', 'Renda / prestação', 'Alimentação', 'Transportes', 'Saúde', 'Lazer', 'Poupança', 'Educação'],
      tips: ['Regra 50/30/20: metade para o essencial, 30% para desejos, 20% para poupar.',
             'Cria uma conta de "Reserva" com meta: a barra de progresso motiva.',
             'Regista tudo durante um mês: só assim se vê para onde vai o dinheiro.',
             'Paga primeiro a ti: transfere a poupança logo que o salário entra.'],
      tool: { title: 'Regra 50 / 30 / 20', intro: 'Como dividir o rendimento mensal.',
        inputs: [{ key: 'renda', label: 'Rendimento mensal (€)', value: 1200, step: 50 }],
        outputs: [{ label: 'Essencial (50%)', kind: 'money', fn: v => v.renda * 0.5 }, { label: 'Desejos (30%)', kind: 'money', fn: v => v.renda * 0.3 }, { label: 'Poupança (20%)', kind: 'money', fn: v => v.renda * 0.2, main: true }] },
    },

    /* ---------- NEGÓCIO GERAL: os textos originais da plataforma ---------- */
    general: {
      name: 'Negócio geral', icon: '📊', subtitle: 'Uma gestão flexível para qualquer atividade.', primary: 'Operações do negócio',
      chips: CHIPS_FULL, trackStock: true, modules: { clients: true, stock: true },
      vocab: vocab({ item: 'Produto', plural: 'Produtos', tab: 'Estoque', title: 'Produtos' }),
      fields: [],
      categories: ['Vendas', 'Fornecedores', 'Renda', 'Pró-labore', 'Impostos', 'Marketing', 'Ordenados'],
      tips: ['Organiza por categorias (vendas, fornecedores, renda, impostos…) para veres onde gastas.',
             'Negoceia prazos: recebe dos clientes mais cedo e paga aos fornecedores mais tarde.',
             'Separa as contas pessoais das do negócio.',
             'Guarda uma reserva de emergência: dá tranquilidade nos meses fracos.'],
      tool: null,
    },
  };

  /* Ordem em que os ramos aparecem na janela de escolha. */
  const ORDER = ['real_estate', 'cars', 'uber', 'rider', 'barber', 'online_store', 'clothing', 'restaurant', 'mechanic', 'freelancer', 'personal', 'general'];

  /* -----------------------------------------------------------------------
     3) ESTADO E FUNÇÕES PÚBLICAS  (window.GFP)
     ----------------------------------------------------------------------- */
  let currentType = 'general';
  const money = new Intl.NumberFormat((window.LUMINA_LOCALE || 'pt-PT'), { style: 'currency', currency: 'EUR' });

  const GFP = {
    RAMOS, ORDER,

    /* O ramo atual (se o tipo guardado já não existir, usa o geral). */
    get type() { return currentType; },
    current() { return RAMOS[currentType] || RAMOS.general; },
    get(type) { return RAMOS[type] || RAMOS.general; },

    /* Texto do vocabulário do ramo atual (ex.: GFP.t('stockTab') -> 'Imóveis'). */
    t(key, fallback = '') { return this.current().vocab[key] ?? fallback; },

    /* Chips (etiquetas) de um produto: os campos do ramo que têm valor. */
    attrChips(attrs) {
      const out = [];
      for (const f of this.current().fields) {
        const value = attrs && attrs[f.key];
        if (value === undefined || value === null || value === '') continue;
        out.push({ key: f.key, label: f.label, text: String(value) + (f.suffix && f.type === 'number' ? f.suffix : ''), status: !!f.status });
      }
      return out;
    },

    /* Lê os campos do ramo preenchidos num formulário e devolve {chave: valor}. */
    collectAttrs(form) {
      const attrs = {};
      for (const f of this.current().fields) {
        const el = form.elements['attr_' + f.key];
        if (el && String(el.value).trim() !== '') attrs[f.key] = String(el.value).trim();
      }
      return attrs;
    },

    /* Repõe os valores por omissão do formulário de produtos depois de o limpar (ex.: quantidade 1 nos ramos sem estoque). */
    refreshForm() { applyModules(this.current()); },

    /* Aplica um ramo à página inteira. "row" é o que vem de api/profile.php. */
    apply(row) {
      currentType = row && RAMOS[row.business_type] ? row.business_type : 'general';
      const p = this.current();
      applyVocabulary(p);
      applyModules(p);
      applyEnabledModules(row?.enabled_modules);
      renderExtraFields(p);
      renderTool(p);
      renderTip(p);
      renderCategories(p);
      document.documentElement.dataset.ramo = currentType;          // permite estilos CSS por ramo
      document.documentElement.dataset.i18nCtx = currentType;       // idioma: o mesmo texto pode ter sentidos diferentes por ramo (ex.: "peças" na roupa e na oficina)
      document.dispatchEvent(new CustomEvent('gf:profile', { detail: { type: currentType, profile: p } }));
      window.moveTabGlass?.();                                     // os textos das abas mudaram de largura
    },
  };

  /* -----------------------------------------------------------------------
     4) APLICAR O VOCABULÁRIO
     Troca o texto de todos os elementos com data-t="chave" e o placeholder
     dos que têm data-tp="chave".
     ----------------------------------------------------------------------- */
  function applyVocabulary(p) {
    $$('[data-t]').forEach(el => { const v = p.vocab[el.dataset.t]; if (v !== undefined) el.textContent = v; });
    $$('[data-tp]').forEach(el => { const v = p.vocab[el.dataset.tp]; if (v !== undefined) el.placeholder = v; });
  }

  /* -----------------------------------------------------------------------
     5) MÓDULOS
     Esconde as abas (e atalhos) de Clientes/Estoque quando o ramo não os usa.
     Mostra ou esconde também os campos "Quantidade" e "Estoque mínimo".
     ----------------------------------------------------------------------- */
  /* As abas que a PESSOA escolheu no questionário (row.enabled_modules). Sem lista = todas. As abas desligadas não se veem
     nem no menu nem nos atalhos; se a aba aberta for desligada, voltamos à Visão geral. */
  const MODULE_IDS = ['overview', 'cashflow', 'calendar', 'notes', 'time', 'insights', 'clients', 'accounts', 'stock', 'team', 'calculator', 'weather', 'reports'];
  function applyEnabledModules(list) {
    const on = Array.isArray(list) ? new Set(list) : null;
    for (const id of MODULE_IDS) {
      $$(`.nav-tab[data-section="${id}"], #home-shortcuts [data-go="${id}"], .quick-action[data-go="${id}"]`)
        .forEach(el => el.classList.toggle('module-off', on ? !on.has(id) : false));
    }
    if ($('.nav-tab.active.module-off')) window.showSection?.('overview');
    window.moveTabGlass?.();
  }

  function applyModules(p) {
    for (const mod of ['clients', 'stock']) {
      $$(`.nav-tab[data-section="${mod}"], #home-shortcuts [data-go="${mod}"], .quick-action[data-go="${mod}"]`)
        .forEach(el => el.classList.toggle('ramo-off', p.modules[mod] === false));
    }
    // Painel inteligente do ramo (restaurante, loja de roupas, motorista...): só existe nos ramos que o têm.
    const insTab = $('.nav-tab[data-section="insights"]');
    if (insTab) {
      insTab.classList.toggle('ramo-off', !p.insights);
      const label = insTab.querySelector('span'); if (label && p.insights) label.textContent = p.insights.title;
    }
    // Produtos únicos (imóvel, carro, serviço...) não têm "quantidade": escondemos os campos e usamos 1 / 0.
    const form = $('#product-form');
    if (form) {
      form.querySelectorAll('[data-stock-only]').forEach(label => {
        const noQty = label.hasAttribute('data-qty-only') && !!p.variants;               // com variações, a quantidade é a de cada variação
        label.classList.toggle('ramo-off', !!(!p.trackStock || noQty));
        const input = label.querySelector('input');
        if (input) { input.required = p.trackStock && !noQty; if (!p.trackStock) input.value = input.name === 'stock_quantity' ? '1' : '0'; else if (noQty) input.value = 0; }
      });
      form.querySelectorAll('[data-variants-only]').forEach(el => el.classList.toggle('ramo-off', !p.variants));   // marca, variações, estado e imagem
    }
  }

  /* -----------------------------------------------------------------------
     6) CAMPOS PRÓPRIOS DO RAMO
     Desenha, dentro do formulário de produtos, os campos do ramo (select,
     número ou texto). Os nomes começam por "attr_" para os distinguir.
     ----------------------------------------------------------------------- */
  function renderExtraFields(p) {
    const box = $('#product-extra');
    if (!box) return;
    box.innerHTML = p.fields.map(f => {
      const common = `name="attr_${f.key}" id="attr-${f.key}"`;
      let control;
      if (f.type === 'select') control = `<select ${common}><option value="">—</option>${f.options.map(o => `<option>${esc(o)}</option>`).join('')}</select>`;
      else if (f.type === 'number') control = `<input ${common} type="number" ${f.min !== undefined ? `min="${f.min}"` : ''} ${f.max !== undefined ? `max="${f.max}"` : ''} step="${f.step || 1}" placeholder="${esc(f.placeholder || '')}">`;
      else control = `<input ${common} type="text" maxlength="100" placeholder="${esc(f.placeholder || '')}">`;
      return `<label class="extra-field">${esc(f.label)}${control}</label>`;
    }).join('');
    box.classList.toggle('ramo-off', !p.fields.length);
  }

  /* -----------------------------------------------------------------------
     7) FERRAMENTA DO RAMO (mini-calculadora na aba Calculadora)
     Cada ramo define "inputs" (o que o utilizador escreve) e "outputs"
     (funções que calculam o resultado). Atualiza em tempo real ao escrever.
     ----------------------------------------------------------------------- */
  const fmt = { money: v => money.format(Number.isFinite(v) ? v : 0), percent: v => `${(Number.isFinite(v) ? v : 0).toFixed(1).replace('.', ',')} %`, number: v => String(Number.isFinite(v) ? v : 0) };

  function renderTool(p) {
    const box = $('#ramo-tool');
    if (!box) return;
    if (!p.tool) { box.classList.add('ramo-off'); box.innerHTML = ''; return; }
    box.classList.remove('ramo-off');
    box.innerHTML = `
      <p class="eyebrow">FERRAMENTA DO TEU RAMO</p>
      <h2>${esc(p.tool.title)}</h2>
      <p class="muted">${esc(p.tool.intro)}</p>
      <div class="tool-grid">
        <div class="tool-inputs">${p.tool.inputs.map(i => `<label>${esc(i.label)}<input type="number" data-tool="${i.key}" value="${i.value}" step="${i.step || 1}" min="0"></label>`).join('')}</div>
        <div class="tool-outputs" aria-live="polite">${p.tool.outputs.map((o, n) => `<div class="tool-out${o.main ? ' main' : ''}"><span>${esc(o.label)}</span><strong data-out="${n}">—</strong></div>`).join('')}</div>
      </div>`;
    const recalc = () => {
      const v = {};
      box.querySelectorAll('[data-tool]').forEach(i => { v[i.dataset.tool] = parseFloat(i.value) || 0; });
      p.tool.outputs.forEach((o, n) => { const el = box.querySelector(`[data-out="${n}"]`); if (el) el.textContent = fmt[o.kind](o.fn(v)); });
    };
    box.querySelectorAll('[data-tool]').forEach(i => i.addEventListener('input', recalc));
    recalc();
  }

  /* -----------------------------------------------------------------------
     8) DICA DO RAMO (no banner da Visão geral)
     Mostra uma dica diferente a cada carregamento; clicar na dica mostra a seguinte.
     ----------------------------------------------------------------------- */
  let tipIndex = Math.floor(Math.random() * 100);
  function renderTip(p) {
    const el = $('#ramo-tip');
    if (!el) return;
    const show = () => { el.textContent = '💡 ' + p.tips[tipIndex % p.tips.length]; el.classList.remove('tip-in'); void el.offsetWidth; el.classList.add('tip-in'); };
    el.onclick = () => { tipIndex++; show(); };
    el.title = 'Clica para ver outra dica';
    show();
  }

  /* -----------------------------------------------------------------------
     9) CATEGORIAS DO FLUXO DE CAIXA
     Atualiza as sugestões do campo "Categoria" (datalist) com as do ramo.
     ----------------------------------------------------------------------- */
  function renderCategories(p) {
    const list = $('#category-list');
    if (list) list.innerHTML = p.categories.map(c => `<option value="${esc(c)}"></option>`).join('');
    const input = $('#transaction-form [name=category]');
    if (input) input.placeholder = `Ex.: ${p.categories.slice(0, 3).join(', ')}`;
  }

  window.GFP = GFP;
})();
