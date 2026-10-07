# Lumina
## Plataforma de gestão adaptável para diferentes atividades profissionais

**Aluno:** Allysson Mota  
**Escola:** Escola Secundária Domingos Sequeira  
**Curso:** Técnico de Gestão e Programação de Sistemas de Informação  
**Ano:** 10.º ano  
**Tipo de trabalho:** Prova de Aptidão Profissional (PAP)  
**Ano letivo:** 2026/2027

> Este documento é uma base completa para o relatório da PAP. Antes da entrega final, devem ser acrescentados o nome do orientador, datas, imagens próprias da aplicação e os resultados dos testes realizados no computador do aluno.

---

## Resumo

O projeto **Lumina** consiste numa aplicação web para ajudar pequenos negócios, trabalhadores independentes e utilizadores particulares a organizar a sua atividade financeira e operacional.

A aplicação permite gerir movimentos de caixa, contas, produtos, estoque e clientes. Também inclui um sistema de escolha de ramo de atividade. Desta forma, a aplicação pode adaptar os seus textos e atalhos a diferentes utilizadores, como lojas online, restaurantes, vendedores de carros, imobiliárias, motoristas TVDE/Uber, freelancers, lojas de roupas e utilizadores que pretendem gerir as suas finanças pessoais.

A solução foi desenvolvida com **PHP puro**, **MySQL**, **HTML**, **CSS** e **JavaScript**, utilizando o **XAMPP** para disponibilizar o servidor Apache e a base de dados MySQL. O objetivo foi utilizar tecnologias simples, adequadas ao nível do curso e fáceis de explicar durante a apresentação da PAP.

**Palavras-chave:** gestão financeira, PHP, MySQL, XAMPP, clientes, estoque, dashboard, aplicação web.

---

# 1. Introdução

Muitos pequenos negócios controlam as suas receitas, despesas, clientes e produtos através de folhas de cálculo, cadernos ou várias aplicações separadas. Esta situação pode causar perda de informação, erros de cálculo e dificuldade em perceber o estado real do negócio.

O Lumina foi criado para centralizar essa informação numa só aplicação. O utilizador pode registar os principais dados da sua atividade e consultar um resumo de forma rápida.

Outra característica importante é a adaptação ao ramo profissional. Uma loja online não precisa exatamente dos mesmos textos e atalhos que um motorista TVDE ou um freelancer. Por isso, no primeiro acesso, o utilizador escolhe o tipo de atividade e a aplicação apresenta um perfil adequado.

---

# 2. Identificação do problema

O problema identificado é a falta de uma solução simples, centralizada e adaptável para pequenos negócios e trabalhadores independentes.

As principais dificuldades são:

- Falta de controlo sobre entradas e saídas de dinheiro;
- Contas a pagar esquecidas ou sem organização;
- Dificuldade em acompanhar os clientes;
- Falta de controlo sobre produtos e quantidades em estoque;
- Utilização de vários ficheiros diferentes;
- Sistemas demasiado complexos para utilizadores sem conhecimentos avançados;
- Aplicações que não se adaptam ao tipo de atividade do utilizador.

---

# 3. Solução proposta

A solução proposta é uma aplicação web chamada **Lumina**.

A aplicação possui uma área de autenticação e um dashboard principal. Depois de criar uma conta, o utilizador escolhe o seu ramo de atividade e indica o nome do negócio.

A aplicação disponibiliza módulos comuns:

- Login e registo;
- Fluxo de caixa;
- Contas internas;
- Contas a pagar e a receber;
- Gestão de clientes;
- Gestão de produtos e estoque;
- Perfil adaptável por ramo;
- Preview visual da ideia.

O sistema foi construído de modo a que os dados de cada utilizador fiquem separados através do campo `user_id` nas tabelas da base de dados.

---

# 4. Objetivos

## 4.1 Objetivo geral

Desenvolver uma aplicação web simples e adaptável que ajude diferentes utilizadores a controlar a sua atividade financeira e operacional.

## 4.2 Objetivos específicos

- Criar um sistema de registo e login;
- Guardar os utilizadores numa base de dados MySQL;
- Proteger as palavras-passe;
- Criar um fluxo de caixa com entradas e saídas;
- Criar um módulo de contas;
- Criar um módulo de clientes;
- Associar clientes a movimentos e contas;
- Criar um módulo de produtos e estoque;
- Criar um perfil de negócio adaptável;
- Criar uma interface simples e responsiva;
- Permitir a utilização através do Apache e MySQL do XAMPP;
- Criar documentação de instalação e utilização.

---

# 5. Público-alvo

O Lumina destina-se principalmente a:

- Microempresários;
- Trabalhadores independentes;
- Lojas online;
- Restaurantes e cafés;
- Vendedores de carros;
- Agências ou profissionais imobiliários;
- Motoristas TVDE/Uber;
- Freelancers;
- Lojas de roupas;
- Pessoas que querem controlar as suas finanças pessoais.

---

# 6. Perfis profissionais

No primeiro acesso, o utilizador escolhe um perfil.

| Perfil | Adaptação principal |
|---|---|
| Loja online | Produtos, encomendas, clientes e estoque |
| Restaurante/café | Pedidos, ingredientes, fornecedores e caixa |
| Venda de carros | Viaturas, vendas, compradores e documentos |
| Imobiliária | Imóveis, clientes, visitas e contratos |
| Gestão pessoal | Orçamento, despesas, objetivos e poupança |
| TVDE/Uber | Viagens, quilómetros, combustível e ganhos |
| Freelancer | Projetos, horas, serviços e recebimentos |
| Loja de roupas | Tamanhos, cores, coleção e estoque |
| Negócio geral | Gestão flexível para várias atividades |

Na versão atual, o perfil adapta o nome, a descrição, o ícone e os atalhos do dashboard. Os módulos específicos mais avançados, como contratos imobiliários ou pedidos de restaurante, podem ser adicionados numa fase futura sem alterar a base comum.

---

# 7. Tecnologias utilizadas

## 7.1 HTML

O HTML é utilizado para criar a estrutura das páginas, formulários, tabelas, botões, cartões e menus.

## 7.2 CSS

O CSS é utilizado para definir cores, espaçamentos, tamanhos, cartões, tabelas e adaptação para telemóveis.

## 7.3 JavaScript

O JavaScript é utilizado para tornar a aplicação interativa. Permite trocar de módulo, enviar formulários, atualizar dados e comunicar com o PHP através de `fetch()`.

## 7.4 PHP

O PHP é utilizado no backend. Recebe os pedidos do frontend, valida os dados, controla as sessões e comunica com a base de dados.

## 7.5 MySQL

O MySQL guarda os utilizadores, perfis, clientes, movimentos, contas, produtos e outras informações do sistema.

## 7.6 XAMPP

O XAMPP fornece:

- Apache para executar os ficheiros PHP;
- MySQL para guardar os dados;
- phpMyAdmin para administrar a base de dados.

## 7.7 PDO

A ligação do PHP ao MySQL é feita através de PDO. Foram utilizados comandos preparados para diminuir o risco de SQL Injection.

---

# 8. Arquitetura do sistema

A aplicação segue uma arquitetura simples:

```text
Utilizador
    ↓
Navegador Web
    ↓
Apache do XAMPP
    ↓
Ficheiros PHP e JavaScript
    ↓
PDO
    ↓
MySQL do XAMPP
```

## 8.1 Frontend

O frontend é a parte visual que o utilizador vê no navegador.

Principais ficheiros:

```text
index.php                  página principal
assets/css/style.css       estilos gerais
assets/css/clientes.css    estilos do CRM
assets/css/profile.css     estilos dos perfis
assets/js/app.js           funcionalidades principais
assets/js/profile.js       adaptação por ramo
```

## 8.2 Backend

O backend trata da lógica do sistema.

```text
config/database.php        ligação ao MySQL
includes/auth.php          sessão e funções de segurança
api/auth.php               login, registo e logout
api/data.php               dados financeiros, contas e produtos
api/clients.php            clientes e histórico
api/profile.php            perfil profissional
```

---

# 9. Base de dados

A base de dados chama-se `gestao_facil`.

## 9.1 Tabelas principais

| Tabela | Função |
|---|---|
| `users` | Guarda os utilizadores |
| `business_profiles` | Guarda o ramo e nome do negócio |
| `accounts` | Guarda contas de caixa, banco, reserva ou investimento |
| `clients` | Guarda os clientes |
| `products` | Guarda os produtos e estoque |
| `transactions` | Guarda entradas e saídas |
| `financial_documents` | Guarda contas a pagar e receber |
| `stock_movements` | Guarda movimentos de estoque |
| `notes` | Guarda notas relacionadas com o negócio |
| `investment_accounts` | Guarda contas de investimento |
| `investment_updates` | Guarda atualizações de investimentos |
| `reserve_funds` | Guarda o fundo de reserva |
| `reserve_fund_movements` | Guarda movimentos do fundo de reserva |
| `transfers` | Guarda transferências entre contas |
| `user_sessions` | Guarda sessões de utilizador |

## 9.2 Relações principais

```text
users 1 ──── N transactions
users 1 ──── N accounts
users 1 ──── N clients
users 1 ──── N products
users 1 ──── N financial_documents
users 1 ──── 1 business_profiles
clients 1 ──── N transactions
clients 1 ──── N financial_documents
products 1 ──── N stock_movements
```

## 9.3 Integridade dos dados

As tabelas utilizam chaves estrangeiras para relacionar os registos. Por exemplo, um movimento de caixa pode estar ligado a um cliente através de `client_id`.

As consultas utilizam sempre o `user_id` do utilizador autenticado. Desta forma, um utilizador não deve conseguir consultar os dados de outro utilizador.

---

# 10. Segurança

Foram aplicadas algumas medidas de segurança:

- As palavras-passe não são guardadas em texto simples;
- O PHP utiliza `password_hash()` para criar o hash;
- O login utiliza `password_verify()`;
- A sessão é regenerada depois do login;
- As rotas privadas exigem autenticação;
- As consultas MySQL utilizam prepared statements;
- Os valores apresentados em HTML são tratados com `htmlspecialchars()`;
- As operações filtram pelo utilizador autenticado;
- As pastas internas têm regras `.htaccess` para impedir acesso direto.

Exemplo do processo de login:

```text
1. O utilizador escreve email e palavra-passe.
2. O JavaScript envia os dados para api/auth.php.
3. O PHP procura o email na tabela users.
4. password_verify() compara a palavra-passe.
5. O PHP cria uma sessão.
6. O utilizador passa a ter acesso aos módulos privados.
```

---

# 11. Funcionamento dos módulos

## 11.1 Login e registo

O utilizador pode criar uma conta ou entrar numa conta existente. Depois de entrar, é criada uma sessão PHP.

## 11.2 Fluxo de caixa

Permite registar:

- Entradas;
- Saídas;
- Descrição;
- Categoria;
- Valor;
- Data;
- Cliente associado.

O sistema calcula:

- Total de entradas;
- Total de saídas;
- Saldo.

## 11.3 Clientes

Permite guardar:

- Nome;
- Email;
- Telefone;
- NIF;
- Categoria;
- Notas.

Também permite consultar transações e contas ligadas a cada cliente.

## 11.4 Contas

Permite criar contas de:

- Caixa;
- Banco;
- Reserva;
- Investimento.

## 11.5 Contas a pagar e receber

Permite guardar o título, valor, data de vencimento, entidade e cliente associado.

## 11.6 Estoque

Permite guardar:

- Nome do produto;
- SKU;
- Categoria;
- Preço de custo;
- Preço de venda;
- Quantidade;
- Stock mínimo.

A aplicação também mostra um alerta quando a quantidade fica abaixo do stock mínimo.

## 11.7 Perfis adaptáveis

O utilizador escolhe um ramo e o sistema mostra atalhos adequados. A seleção pode ser alterada posteriormente.

## 11.8 Preview


---

# 12. Interface gráfica

A interface foi pensada para ser simples e responsiva.

Elementos principais:

- Barra superior com nome do negócio;
- Menu de módulos;
- Cartões de resumo financeiro;
- Tabelas de movimentos;
- Formulários de registo;
- Cartões de clientes e produtos;
- Janela de escolha do ramo;
- Cores verdes associadas a organização e equilíbrio financeiro.

A interface também pode adaptar-se a ecrãs mais pequenos através de regras CSS responsivas.

---

# 13. Instalação e execução

## 13.1 Requisitos

- Windows;
- XAMPP;
- Navegador web;
- Visual Studio Code, opcional para editar o código.

## 13.2 Instalação

1. Instalar o XAMPP.
2. Abrir o XAMPP Control Panel.
3. Iniciar Apache.
4. Iniciar MySQL.
5. Copiar a pasta para:

```text
C:\xampp\htdocs\lumina
```

6. Abrir o phpMyAdmin:

```text
http://localhost/phpmyadmin
```

7. Importar o ficheiro:

```text
database/gestao_facil.sql
```

8. Abrir a aplicação:

```text
http://localhost/lumina/
```

## 13.3 Arranque automático

Também existe o ficheiro:

```text
iniciar_lumina_automatico.bat
```

Esse ficheiro inicia o Apache, inicia o MySQL e abre o navegador.

## 13.4 Preview sem XAMPP

Para mostrar apenas a ideia visual, abrir:

```text
```

ou executar:

```text
```

---

# 14. Testes realizados

## 14.1 Teste de login

**Procedimento:** criar uma conta e entrar com as credenciais.  
**Resultado esperado:** o dashboard é apresentado.

## 14.2 Teste de proteção

**Procedimento:** tentar consultar dados sem sessão.  
**Resultado esperado:** o sistema impede o acesso.

## 14.3 Teste do fluxo de caixa

**Procedimento:** inserir uma entrada e uma saída.  
**Resultado esperado:** o saldo é atualizado.

## 14.4 Teste de clientes

**Procedimento:** criar um cliente e associá-lo a um movimento.  
**Resultado esperado:** o movimento aparece no histórico do cliente.

## 14.5 Teste de estoque

**Procedimento:** criar um produto com quantidade inferior ao stock mínimo.  
**Resultado esperado:** aparece o aviso de stock baixo.

## 14.6 Teste de perfil

**Procedimento:** escolher diferentes ramos no onboarding.  
**Resultado esperado:** o nome, ícone, descrição e atalhos são alterados.

> Na versão final do relatório, devem ser adicionadas capturas de ecrã destes testes realizadas no computador do aluno.

---

# 15. Dificuldades encontradas

Durante o desenvolvimento foram identificadas algumas dificuldades:

- Configuração inicial do ambiente de desenvolvimento;
- Ligação entre PHP e MySQL;
- Organização das tabelas e relações;
- Criação de sessões de utilizador;
- Separação dos dados por utilizador;
- Adaptação da aplicação a vários ramos;
- Organização de uma interface responsiva;
- Testes no Apache do XAMPP.

Estas dificuldades foram resolvidas através da divisão do projeto em módulos e da criação de uma estrutura simples de pastas.

---

# 16. Melhorias futuras

As próximas versões poderão incluir:

- Módulo completo para imóveis;
- Módulo completo para venda de carros;
- Pedidos e mesas para restaurantes;
- Encomendas para lojas online;
- Viagens e quilómetros para TVDE/Uber;
- Projetos e horas para freelancers;
- Valuation da empresa;
- Relatórios em PDF;
- Gráficos financeiros;
- Upload de imagens de produtos;
- Modo escuro;
- Notificações;
- Exportação para Excel;
- Integração com serviços de pagamento.

Estas funcionalidades devem ser implementadas gradualmente para manter o código simples e fácil de testar.

---

# 17. Conclusão

O Lumina apresenta uma solução funcional para centralizar a gestão financeira e operacional de pequenos negócios e utilizadores particulares.

O projeto cumpre os principais objetivos definidos: possui autenticação, base de dados MySQL, fluxo de caixa, contas, clientes, produtos e perfis adaptáveis.

A utilização de PHP, MySQL, HTML, CSS e JavaScript permite que o sistema seja executado com o XAMPP e que o código seja compreendido e apresentado no âmbito do curso Técnico de Gestão e Programação de Sistemas de Informação.

A possibilidade de escolher diferentes ramos torna a aplicação mais flexível. Em vez de existir um programa separado para cada profissão, existe uma base comum que pode ser adaptada às necessidades do utilizador.

Como trabalho futuro, poderão ser criados módulos específicos para cada ramo, mantendo a estrutura central e evitando a duplicação de dados.

---

# 18. Bibliografia e recursos

- Documentação oficial do PHP: https://www.php.net/docs.php
- Documentação do MySQL: https://dev.mysql.com/doc/
- Documentação do XAMPP: https://www.apachefriends.org/
- Documentação do HTML: https://developer.mozilla.org/docs/Web/HTML
- Documentação do CSS: https://developer.mozilla.org/docs/Web/CSS
- Documentação do JavaScript: https://developer.mozilla.org/docs/Web/JavaScript

---

# 19. Anexos

## Anexo A — Estrutura de pastas

```text
lumina/
├── api/
├── assets/
├── config/
├── database/
├── docs/
├── includes/
├── index.php
├── iniciar_lumina_automatico.bat
└── README.md
```

## Anexo B — Comandos e endereços

```text
http://localhost/phpmyadmin
http://localhost/lumina/
```

## Anexo C — Imagens a acrescentar

Adicionar capturas de ecrã de:

1. Login;
2. Registo;
3. Escolha do ramo;
4. Dashboard;
5. Fluxo de caixa;
6. Clientes;
7. Contas;
8. Estoque;
9. Preview dos perfis;
10. Base de dados no phpMyAdmin.
