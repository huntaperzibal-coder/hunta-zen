"""Isolated real HTTP tests: credentials, CSRF, uploads, previews, revisions and roles."""
import os, re, socket, subprocess, tempfile, time, urllib.request, urllib.error, http.cookiejar, urllib.parse, sqlite3, pathlib, json, base64
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='photo-desk-') as tmp:
    store=pathlib.Path(tmp)
    for folder in ('files','thumbs','sessions','backups'): (store/folder).mkdir()
    config=store/'config.php'
    config.write_text('<?php return '+"['storage'=>"+repr(str(store))+",'url'=>'https://files.example.test'];")
    seed='require '+repr(str(root/'photo-desk/app.php'))+'; $db=PhotoDesk\\connect('+repr(str(store))+'); PhotoDesk\\schema($db); foreach(["owner"=>"admin","lawyer"=>"lawyer","photo"=>"photographer"] as $login=>$role){$db->prepare("INSERT INTO users VALUES(?,?,?)")->execute([$login,password_hash("test",PASSWORD_DEFAULT),$role]);}'
    subprocess.run(['php','-r',seed],check=True)
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    env=os.environ|{'PHOTO_DESK_CONFIG':str(config)}
    log=open(store/'server.log','w+')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'photo-desk/public')],env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}/'
    def client(): return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    owner=client();lawyer=client();photo=client();guest=client()
    def req(c,path='',data=None,headers=None):
        request=urllib.request.Request(base+path,data=data,headers=headers or {})
        try:
            with c.open(request,timeout=10) as r:return r.status,r.read(),r.headers
        except urllib.error.HTTPError as e:return e.code,e.read(),e.headers
    def csrf(c):
        code,body,_=req(c);assert code==200,(code,body);return re.search(rb'name="csrf" value="([^"]+)"',body)[1].decode()
    def post(c,fields):return req(c,data=urllib.parse.urlencode(fields).encode())
    def login(c,name):
        code,body,_=post(c,{'csrf':csrf(c),'action':'login','login':name,'password':'test'});assert code==200 and 'Выйти'.encode() in body
    try:
        for _ in range(100):
            try:
                if req(guest)[0]==200:break
            except OSError:time.sleep(.05)
        assert req(guest,'?file=1')[0]==403
        assert post(guest,{'action':'create'})[0]==403
        login(owner,'owner');login(lawyer,'lawyer');login(photo,'photo')
        assert post(owner,{'csrf':csrf(owner),'action':'create','title':'Озеро','company':'Тест'})[0]==200
        db=sqlite3.connect(store/'desk.sqlite')
        assert db.execute('select count(*) from cases').fetchone()[0]==1
        def revision():return db.execute('select revision from cases where id=1').fetchone()[0]
        # Two real browser uploads in one multipart request; original bytes are unchanged.
        boundary='desk-test-boundary';parts=[]
        for key,value in {'csrf':csrf(owner),'action':'save','id':'1','revision':str(revision()),'response_note':'Исходники приложены'}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=')
        for name,content,mime in [('original.png',png,'image/png'),('note.txt',b'original evidence','text/plain')]:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="response[]"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+content+b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode());code,body,_=req(owner,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
        assert code==200,(code,body)
        assert db.execute('select count(*) from attachments').fetchone()[0]==2
        assert req(lawyer,'?file=1&download=1')[1]==png
        assert req(guest,'?file=1&thumb=1')[0]==403
        assert req(owner,'?file=1&thumb=1')[0]==200
        assert post(photo,{'csrf':csrf(photo),'action':'flag','id':'1','revision':revision(),'flag':'ready','value':1})[0]==200
        assert db.execute('select ready,closed from cases').fetchone()==(1,0)
        assert post(photo,{'csrf':csrf(photo),'action':'flag','id':'1','revision':revision(),'flag':'closed','value':1})[0]==422
        assert post(lawyer,{'csrf':csrf(lawyer),'action':'flag','id':'1','revision':revision(),'flag':'closed','value':1})[0]==200
        assert db.execute('select closed from cases').fetchone()[0]==1
        assert post(owner,{'csrf':csrf(owner),'action':'save','id':'1','revision':1,'response_note':'stale'})[0]==422
        assert db.execute('select response_note from cases').fetchone()[0]=='Исходники приложены'
        assert req(owner,'?q='+urllib.parse.quote('Тест'))[0]==200
        assert 'Озеро'.encode() in req(owner,'?filter=closed')[1]
        assert post(lawyer,{'csrf':csrf(lawyer),'action':'delete','id':'1','revision':revision(),'attachment':1})[0]==422
        assert post(owner,{'csrf':'bad','action':'flag','id':'1','revision':revision(),'flag':'closed'})[0]==403
        assert req(owner)[2]['X-Robots-Tag'].startswith('noindex')
        assert req(owner,'?asset=../../config.php')[0]==404
        print('Photo desk HTTP checks passed')
    finally:
        server.terminate();server.wait(timeout=5);log.close()
