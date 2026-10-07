import subprocess, datetime as dt, json
def sql(q):
    r=subprocess.run(['mysql','-uroot','--default-character-set=utf8mb4','gestao_facil','-N','-e',q],capture_output=True,text=True)
    if r.returncode: print('ERRO SQL:',r.stderr[:200],'|',q[:90])
    return r.stdout.strip()
def php_hash(pw): return subprocess.run(['php','-r',f'echo password_hash("{pw}", PASSWORD_DEFAULT);'],capture_output=True,text=True).stdout
def d(n): return (dt.date.today()+dt.timedelta(days=n)).isoformat()
for t in ['ai_audit_log','ai_messages','ai_conversations','payment_cards','calendar_events','stock_movements','financial_documents','transactions','products','clients','accounts','business_profiles']: sql(f"DELETE FROM {t}")
sql("DELETE FROM users"); sql("ALTER TABLE users AUTO_INCREMENT=1")
h123=php_hash('123456'); habc=php_hash('abcdef')
def owner(name,email,h): sql(f"INSERT INTO users (name,email,password_hash,role) VALUES ('{name}','{email}','{h}','owner')")
def emp(name,email,h,owner_email,perms,status='active',must=0,job='Funcionário'):
    sql(f"INSERT INTO users (name,email,password_hash,role,owner_id,permissions,job_title,department,status,must_change_password) SELECT '{name}','{email}','{h}','employee',id,'{json.dumps(perms)}','{job}','Vendas','{status}',{must} FROM users WHERE email='{owner_email}'")
owner('Maria Teste','maria@teste.pt',h123); owner('Outra Pessoa','outra@teste.pt',habc)
emp('Func Calendário','cal@teste.pt',h123,'maria@teste.pt',['calendar'])
emp('Func Contas e Stock','fin@teste.pt',h123,'maria@teste.pt',['accounts','stock','calendar'])
emp('Func Caixa','cf@teste.pt',h123,'maria@teste.pt',['cashflow'])
emp('Func Clientes','cli@teste.pt',h123,'maria@teste.pt',['clients'])
emp('Func Desativado','off@teste.pt',h123,'maria@teste.pt',['cashflow','accounts'],status='inactive')
emp('Func Palavra-passe','pw@teste.pt',h123,'maria@teste.pt',['cashflow','accounts'],must=1)
emp('Func do B','funcb@teste.pt',habc,'outra@teste.pt',['cashflow','accounts','stock','clients','calendar'])
A="(SELECT id FROM users WHERE email='maria@teste.pt')"; B="(SELECT id FROM users WHERE email='outra@teste.pt')"
for n,e in [("Cliente Alfa","alfa@ex.pt"),("Cliente Beta","beta@ex.pt"),("<img src=x onerror=alert(1)> Cliente XSS","xss@ex.pt")]: sql(f"INSERT INTO clients (user_id,name,email) VALUES ({A},'{n}','{e}')")
def bill(uid,direction,title,cp,amount,due,status='pending',client=None):
    cl='NULL' if client is None else f"(SELECT id FROM clients c WHERE c.user_id={uid} AND c.name='{client}' LIMIT 1)"; c='NULL' if cp is None else f"'{cp}'"
    sql(f"INSERT INTO financial_documents (user_id,client_id,direction,title,counterparty,amount,due_date,status) VALUES ({uid},{cl},'{direction}','{title}',{c},{amount},'{due}','{status}')")
bill(A,'receivable','Fatura 001',None,1200.00,d(-20),client='Cliente Alfa'); bill(A,'receivable','Fatura 002',None,3500.00,d(10),client='Cliente Beta'); bill(A,'receivable','Fatura 003','Cliente Alfa',2000.00,d(40))
bill(A,'receivable','Fatura CANCELADA',None,9999.00,d(-5),status='cancelled')
bill(A,'payable','Compra de tecidos','Fornecedor Têxtil Lda',800.00,d(-5)); bill(A,'payable','Embalagens','Embalagens SA',150.00,d(7)); bill(A,'payable','Serviço X','Fornecedor Têxtil Lda',300.00,d(-30),status='paid')
for n,sku,cat,cost,sale,q,mn in [("Camisola básica","CB1","Roupa",8,19.9,24,8),("Embalagem premium","EP1","Acessórios",1.2,3.5,5,10),("Perfume X","PX1","Fragrâncias",30,79,2,5)]:
    sql(f"INSERT INTO products (user_id,name,sku,category,cost_price,sale_price,stock_quantity,minimum_stock) VALUES ({A},'{n}','{sku}','{cat}',{cost},{sale},{q},{mn})")
sql(f"INSERT INTO accounts (user_id,name,type,balance) VALUES ({A},'Caixa','cash',1250.50),({A},'Banco BPI','bank',8400.00)")
today=dt.date.today()
for i in range(60): sql(f"INSERT INTO transactions (user_id,type,description,category,amount,occurred_at,status) VALUES ({A},'income','Venda {i}','Vendas',{10+i},'{today:%Y-%m-}01 10:00:00','paid')")
sql(f"INSERT INTO transactions (user_id,type,description,category,amount,occurred_at,status) VALUES ({A},'expense','Compra de stock','Compra',50,'{today:%Y-%m-}02 10:00:00','paid'),({A},'income','Venda PREVISTA','Vendas',5000,'{today:%Y-%m-}20 10:00:00','planned'),({A},'expense','Renda PREVISTA','Renda',700,'{today:%Y-%m-}25 10:00:00','planned')")
# negócio B: dados que ninguém do negócio A pode ver
sql(f"INSERT INTO clients (user_id,name,email) VALUES ({B},'SEGREDO_B Cliente','segredo@b.pt')"); bill(B,'receivable','Fatura SEGREDO_B',None,999999.99,d(-3),client='SEGREDO_B Cliente'); bill(B,'payable','Dívida SEGREDO_B','Fornecedor SEGREDO_B',777777.77,d(5))
sql(f"INSERT INTO products (user_id,name,sku,category,cost_price,sale_price,stock_quantity,minimum_stock) VALUES ({B},'Produto SEGREDO_B','SB','X',1,2,1,9)")
sql(f"INSERT INTO accounts (user_id,name,type,balance) VALUES ({B},'Conta SEGREDO_B','bank',555555.55)")
sql(f"INSERT INTO transactions (user_id,type,description,category,amount,occurred_at,status) VALUES ({B},'income','Venda SEGREDO_B','Vendas',888888.88,'{today:%Y-%m-}03 10:00:00','paid')")
# agenda: pessoal
for email,title in [('maria@teste.pt','Agenda da Maria (dona)'),('cal@teste.pt','Agenda do funcionário Calendário'),('fin@teste.pt','Agenda do funcionário Contas')]:
    sql(f"INSERT INTO calendar_events (user_id,title,event_date,start_time,end_time) SELECT id,'{title}','{d(1)}','10:00:00','11:00:00' FROM users WHERE email='{email}'")
print('utilizadores:', sql("SELECT GROUP_CONCAT(CONCAT(email,'(',role,')') SEPARATOR ', ') FROM users"))
print('A a receber pendente (esperado 6700):', sql(f"SELECT SUM(amount) FROM financial_documents WHERE user_id={A} AND direction='receivable' AND status='pending'"), '| entradas realizadas de A =', sql(f"SELECT SUM(amount) FROM transactions WHERE user_id={A} AND type='income' AND status='paid'"), '| previstas =', sql(f"SELECT SUM(amount) FROM transactions WHERE user_id={A} AND status='planned' AND type='income'"))
# v16: cada produto semeado precisa da sua variação (a migração só trata dos que já existiam)
sql("INSERT INTO product_variants (user_id,product_id,quantity) SELECT user_id,id,GREATEST(stock_quantity,0) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id=p.id)")
