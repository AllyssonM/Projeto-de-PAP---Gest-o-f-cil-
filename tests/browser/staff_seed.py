import os
"""Cenário de teste para a gestão de funcionários: o negócio de maria@teste.pt com 4 funcionários."""
import sys, random, os; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
PROJ = os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
from ai_lib import sql, Client
from PIL import Image, ImageDraw
PW='func-palavra-1'
EMP=[('st-ana@teste.pt','Ana Silva','Vendedora','Vendas'),('st-rui@teste.pt','Rui Costa','Vendedor','Vendas'),('st-eva@teste.pt','Eva Lopes','Caixa','Loja'),('st-tiago@teste.pt','Tiago Reis','Gerente de armazém','Logística')]
def owner(): return sql("SELECT id FROM users WHERE email='maria@teste.pt'")
def clean():
    o=owner()
    try: os.remove(os.path.join(PROJ,'storage','avatars','a1b2c3d4e5f60718293a4b5c6d7e8f90.png'))
    except FileNotFoundError: pass
    sql(f"DELETE FROM users WHERE owner_id={o} AND email LIKE 'st-%@teste.pt'")
    sql(f"DELETE FROM sales WHERE user_id={o} AND product_name LIKE 'ST-%'")
    sql(f"DELETE FROM notifications WHERE user_id={o}"); sql(f"DELETE FROM login_log WHERE tenant_id={o}")
def seed(photo=True):
    clean(); o=owner()
    import hashlib; h=sql("SELECT password_hash FROM users WHERE email='maria@teste.pt'")  # só para garantir que existe
    from subprocess import run
    ph=run(['php','-r',f"echo password_hash('{PW}', PASSWORD_DEFAULT);"],capture_output=True,text=True).stdout
    ids={}
    for e,n,job,dep in EMP:
        sql(f"INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status,job_title,department,phone,hired_at) VALUES ('{n}','{e}','{ph}','employee',{o},'[\"calendar\",\"clients\"]','active','{job}','{dep}','+351 912 345 678','2025-03-10')")
        ids[e]=sql(f"SELECT id FROM users WHERE email='{e}'")
    if photo:
        os.makedirs(os.path.join(PROJ,'storage','avatars'),exist_ok=True)
        im=Image.new('RGB',(240,240),(30,90,120)); d=ImageDraw.Draw(im); d.ellipse((70,40,170,140),fill=(240,200,170)); d.ellipse((30,130,210,330),fill=(240,240,240)); 
        name='a1b2c3d4e5f60718293a4b5c6d7e8f90.png'; im.save(os.path.join(PROJ,'storage','avatars',name))
        sql(f"UPDATE users SET avatar_path='avatars/{name}' WHERE id={ids['st-ana@teste.pt']}")
    A,R,E,T=[ids[e[0]] for e in EMP]
    random.seed(7)
    for who,n in ((A,9),(R,4),(E,2)):
        for i in range(n): sql(f"INSERT INTO sales (user_id,product_name,quantity,amount,sold_at,is_demo,created_by) VALUES ({o},'ST-Item {i}',1,{round(random.uniform(20,160),2)},NOW() - INTERVAL {random.randint(0,12)} DAY - INTERVAL {random.randint(0,600)} MINUTE,0,{who})")
    tasks=[(A,'Arrumar a montra nova',1,'done'),(A,'Contar o stock de acessórios',1,'done'),(A,'Ligar aos clientes em atraso',1,'done'),(A,'Preparar a encomenda da Marta',3,'pending'),(R,'Atualizar preços dos perfumes',2,'done'),(R,'Fotografar calçado novo',2,'pending'),(R,'Responder às mensagens do Instagram',-1,'pending'),(E,'Fechar a caixa às 19h',0,'pending')]
    for emp,t,dd,st in tasks:
        comp="NOW() - INTERVAL 1 DAY" if st=='done' else "NULL"
        sql(f"INSERT INTO employee_tasks (tenant_id,employee_id,title,due_date,status,created_by,completed_at) VALUES ({o},{emp},'{t}',CURDATE() + INTERVAL {dd} DAY,'{st}',{o},{comp})")
    mo=sql("SELECT DATE_FORMAT(CURDATE(),'%Y-%m')")
    for emp,k,tg in ((A,'sales_count',10),(A,'sales_value',900),(R,'sales_count',4),(R,'tasks_done',3),(E,'sales_count',5)):
        sql(f"INSERT INTO employee_goals (tenant_id,employee_id,kind,target,month) VALUES ({o},{emp},'{k}',{tg},'{mo}')")
    for emp,mins in ((A,540),(R,300),(E,180)):
        sql(f"INSERT INTO work_shifts (tenant_id,employee_id,started_at,ended_at,status,source) VALUES ({o},{emp},NOW() - INTERVAL 1 DAY - INTERVAL {mins} MINUTE,NOW() - INTERVAL 1 DAY,'closed','clock')")
    sql(f"INSERT INTO leader_notes (tenant_id,employee_id,author_id,body,shared) VALUES ({o},{A},{o},'Muito atenciosa com os clientes. Boa iniciativa na montra.',0),({o},{A},{o},'Parabéns pelo mês de vendas!',1)")
    sql(f"INSERT INTO team_messages (tenant_id,from_user,to_user,kind,body,read_at,created_at) VALUES ({o},{o},{A},'task','Ana, consegues tratar da encomenda da Marta até quinta?',NOW() - INTERVAL 20 MINUTE,NOW() - INTERVAL 30 MINUTE),({o},{A},{o},'general','Claro, já estou a tratar disso.',NULL,NOW() - INTERVAL 10 MINUTE)")
    sql(f"INSERT INTO login_log (tenant_id,user_id,logged_at) VALUES ({o},{A},NOW() - INTERVAL 2 HOUR),({o},{R},NOW() - INTERVAL 5 HOUR),({o},{E},NOW() - INTERVAL 1 DAY)")
    sql(f"UPDATE users SET last_login_at=NOW() - INTERVAL 2 HOUR WHERE id={A}"); sql(f"UPDATE users SET last_login_at=NOW() - INTERVAL 5 HOUR WHERE id={R}"); sql(f"UPDATE users SET last_login_at=NOW() - INTERVAL 1 DAY WHERE id={E}")
    # presenças reais (sessões): Ana online, Rui ausente, Eva em pausa, Tiago offline
    sql("DELETE FROM login_attempts")
    clients={}
    for e,n,job,dep in EMP[:3]:
        c=Client(); s,d=c.req('POST','/api/auth.php?action=login',{'email':e,'password':PW}); c.csrf=d.get('csrf',''); clients[e]=c
    sql(f"UPDATE user_sessions SET last_seen_at=NOW() - INTERVAL 12 MINUTE WHERE user_id={R}")
    ev=clients['st-eva@teste.pt']; ev.req('POST','/api/work.php',{'action':'presence','state':'pause','csrf':ev.csrf})
    sql(f"DELETE FROM notifications WHERE user_id={o}")
    sql(f"INSERT INTO notifications (tenant_id,user_id,type,title,body,actor_id,section,created_at) VALUES ({o},{o},'login','Ana Silva entrou no sistema','',{A},'staff',NOW() - INTERVAL 2 HOUR),({o},{o},'message','Nova mensagem de Ana Silva','Claro, já estou a tratar disso.',{A},'staff',NOW() - INTERVAL 10 MINUTE)")
    return ids
if __name__=='__main__': print(seed())
