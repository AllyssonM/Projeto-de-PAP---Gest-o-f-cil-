# Roteiro de apresentação — Lumina

## 1. Apresentação inicial

Bom dia/boa tarde. O meu nome é Allysson Mota e vou apresentar o meu projeto de PAP, chamado Lumina.

O Lumina é uma aplicação web para ajudar pequenos negócios, trabalhadores independentes e utilizadores particulares a organizar a sua gestão financeira e operacional.

## 2. Problema

Muitos pequenos negócios controlam receitas, despesas, clientes e produtos em folhas de cálculo, cadernos ou aplicações diferentes. Isto pode causar erros, perda de informação e dificuldade em perceber o estado real do negócio.

## 3. Solução

A minha solução foi criar uma plataforma centralizada onde o utilizador consegue gerir o fluxo de caixa, contas, clientes, produtos e estoque.

A aplicação também permite escolher um ramo de atividade. Assim, pode ser adaptada a uma loja online, restaurante, vendedor de carros, imobiliária, motorista TVDE, freelancer ou loja de roupas.

## 4. Tecnologias

Utilizei PHP, MySQL, HTML, CSS e JavaScript.

Utilizei o XAMPP porque fornece o Apache para executar PHP, o MySQL para guardar os dados e o phpMyAdmin para gerir a base de dados.

Escolhi PHP puro e JavaScript puro porque são tecnologias mais simples de compreender e explicar no contexto do meu curso.

## 5. Demonstração

Durante a demonstração devo seguir esta ordem:

1. Abrir o projeto no endereço `http://localhost/lumina/`.
2. Mostrar o ecrã de login.
3. Criar ou utilizar uma conta.
4. Escolher o ramo de atividade.
5. Mostrar o dashboard adaptado.
6. Criar uma entrada no fluxo de caixa.
7. Criar uma despesa.
8. Criar um cliente.
9. Associar um movimento ao cliente.
10. Mostrar o histórico do cliente.
11. Criar um produto.
12. Mostrar o estoque.
13. Abrir o preview e trocar entre profissões.

## 6. Base de dados

A base de dados chama-se `gestao_facil`.

As tabelas principais são users, business_profiles, accounts, clients, products, transactions e financial_documents.

A tabela users guarda os utilizadores. A tabela business_profiles guarda o ramo escolhido. As restantes tabelas guardam os dados de gestão.

Os registos são ligados através de chaves estrangeiras e cada consulta utiliza o user_id para separar os dados dos utilizadores.

## 7. Segurança

As palavras-passe são protegidas com `password_hash()`.

O login utiliza `password_verify()`.

As consultas ao MySQL usam prepared statements através de PDO.

As páginas privadas exigem uma sessão autenticada.

## 8. Dificuldades

As principais dificuldades foram ligar o PHP ao MySQL, organizar as tabelas, criar o login e fazer com que a aplicação se adaptasse a vários ramos.

Resolvi estas dificuldades separando o projeto por pastas e criando APIs específicas para cada módulo.

## 9. Melhorias futuras

No futuro, posso acrescentar módulos próprios para pedidos de restaurantes, imóveis, venda de carros, viagens TVDE, projetos de freelancers, relatórios em PDF e gráficos financeiros.

## 10. Conclusão

Concluo que o Lumina cumpre o objetivo de centralizar a gestão e apresentar uma solução simples, flexível e adaptável.

A principal vantagem é poder utilizar a mesma base para diferentes atividades profissionais, sem criar uma aplicação completamente diferente para cada profissão.
