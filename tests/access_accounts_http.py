"""Real HTTP access management in separate databases, multiple clients and revoked sessions."""
import pathlib,tempfile,subprocess,os,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,sqlite3
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='access-http-') as tmp:
    basepath=pathlib.Path(tmp);blog=basepath/'blog';desk=basepath/'desk'
    for directory in (blog,blog/'photos',desk,desk/'files',desk/'thumbs',desk/'sessions',desk/'backups'):directory.mkdir()
    (blog/'config.php').write_text('<?php return '+"['storage'=>"+repr(str(blog))+",'site_url'=>'http://127.0.0.1','telegram_token'=>'','telegram_channel'=>''];")
    (desk/'config.php').write_text('<?php return '+"['storage'=>"+repr(str(desk))+",'url'=>'https://files.hunta-zen.ru'];")
    env=os.environ|{'HUNTA_CONFIG':str(blog/'config.php'),'PHOTO_DESK_CONFIG':str(desk/'config.php')}
    seed='require '+repr(str(root/'app/bootstrap.php'))+'; $db->exec(file_get_contents(ROOT."/database.sql")); $db->prepare("INSERT INTO orda_users(login,password_hash) VALUES(?,?)")->execute(["owner",password_hash("old",PASSWORD_DEFAULT)]); $db->exec(file_get_contents(ROOT."/database.sql")); require ROOT."/photo-desk/app.php"; $desk=PhotoDesk\\connect('+repr(str(desk))+'); PhotoDesk\\schema($desk); $desk->prepare("INSERT INTO users VALUES(?,?,?)")->execute(["owner",password_hash("old",PASSWORD_DEFAULT),"admin"]); PhotoDesk\\schema($desk);'
    subprocess.run(['php','-r',seed],env=env,check=True)
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    log=open(basepath/'server.log','w+')
    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}'
    def client():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(c,scope,data=None,query=''):
        path='/admin.php' if scope=='blog' else '/'
        if query:path+='?'+query
        headers={'Host':'files.hunta-zen.ru'} if scope=='desk' else {}
        req=urllib.request.Request(base+path,data=urllib.parse.urlencode(data).encode() if data is not None else None,headers=headers)
        try:
            with c.open(req,timeout=10) as r:return r.status,r.read().decode()
        except urllib.error.HTTPError as e:return e.code,e.read().decode()
    def token(c,scope):
        status,html=request(c,scope);assert status==200,(status,html)
        return re.search('name="csrf" value="([^"]+)"',html)[1]
    def login(c,scope,login,password):return request(c,scope,{'csrf':token(c,scope),'action':'login','login':login,'password':password})
    try:
        for _ in range(100):
            try:
                if request(client(),'blog')[0]==200:break
            except OSError:time.sleep(.05)
        for scope in ('blog','desk'):
            owner=client();other_device=client();member=client()
            assert 'Выйти' in login(owner,scope,'owner','old')[1]
            assert 'Выйти' in login(other_device,scope,'owner','old')[1]
            status,page=request(owner,scope,query='view=users');assert status==200 and 'Добавить пользователя' in page
            data={'csrf':token(owner,scope),'action':'access_create','login':'new_'+scope,'role':'admin' if scope=='blog' else 'lawyer','active':1,'new_password':'member','password_confirm':'member'}
            assert request(owner,scope,data)[0]==200
            assert 'Выйти' in login(member,scope,'new_'+scope,'member')[1]
            status,page=request(member,scope,query='view=users');assert status==200 and 'Мой пароль' in page and 'Добавить пользователя' not in page
            evil=data|{'csrf':token(member,scope),'login':'intruder','role':'owner' if scope=='blog' else 'admin'}
            request(member,scope,evil)
            db=sqlite3.connect((blog if scope=='blog' else desk)/('blog.sqlite' if scope=='blog' else 'desk.sqlite'))
            table='orda_users' if scope=='blog' else 'users';meta='orda_user_access' if scope=='blog' else 'user_access'
            assert db.execute(f"select count(*) from {table} where login='intruder'").fetchone()[0]==0
            rev=db.execute(f'select revision from {meta} where login=?',('new_'+scope,)).fetchone()[0]
            data={'csrf':token(owner,scope),'action':'access_update','login':'new_'+scope,'role':'admin' if scope=='blog' else 'lawyer','revision':rev}
            assert request(owner,scope,data)[0]==200
            assert 'Выйти' not in request(member,scope)[1],'blocked session revoked'
            assert 'Выйти' not in login(client(),scope,'new_'+scope,'member')[1],'blocked login rejected'
            assert request(owner,scope,{'csrf':'invalid','action':'access_create'})[0]==403
            rev=db.execute(f'select revision from {meta} where login="owner"').fetchone()[0]
            data={'csrf':token(owner,scope),'action':'access_password','revision':rev,'current_password':'old','new_password':'new','password_confirm':'new'}
            assert 'Пароль изменён' in request(owner,scope,data)[1]
            assert 'Выйти' not in request(other_device,scope)[1],'other device revoked'
            assert 'Выйти' not in login(client(),scope,'owner','old')[1]
            assert 'Выйти' in login(client(),scope,'owner','new')[1],'new computer can log in'
            assert db.execute(f'select count(*) from {table}').fetchone()[0]==2
            db.close()
        assert 'Выйти' not in login(client(),'blog','new_desk','member')[1],'desk does not grant blog access'
        print('Blog and desk account HTTP checks passed: creation, scopes, CSRF, block, password, new-device login and session revocation')
    finally:
        proc.terminate();proc.wait(timeout=5);log.close()
