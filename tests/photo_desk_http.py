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
        # Miniature remains outside the folded body, with attachment fallback.
        folded=req(owner,'?id=999')[1].decode()
        assert 'class="case-thumb" href="?file=1"' in folded
        assert folded.index('class="case-thumb"') < folded.index('class="case-body"')
        preview_key=db.execute('select storage_key from attachments where id=1').fetchone()[0]
        db.execute('update cases set preview_key=? where id=1',(preview_key,));db.commit()
        assert 'class="case-thumb" href="?preview=1"' in req(owner,'?id=999')[1].decode()

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
        def upload_raw(name,content):
            boundary='raw-desk-boundary';parts=[]
            for key,value in {'csrf':csrf(owner),'action':'save','id':'1','revision':str(revision())}.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="response[]"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            return req(owner,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
        import struct
        jpeg=subprocess.check_output(['php','-r','$im=imagecreatetruecolor(40,30);imagejpeg($im);'])
        # A real EXIF APP1/TIFF directory, embedded in both JPEG and RAF fixtures.
        tags=[(271,b'FUJIFILM\0'),(272,b'X-T2\0'),(315,b'Hunta <script>alert(1)</script>\0'),(306,b'2017:05:10 12:34:56\0')]
        offset=8+2+12*len(tags)+4;entries=[];values=b''
        for tag,value in tags:
            entries.append(struct.pack('<HHII',tag,2,len(value),offset));values+=value;offset+=len(value)
        tiff=b'II'+struct.pack('<HIH',42,8,len(tags))+b''.join(entries)+bytes(4)+values
        app1=b'Exif\0\0'+tiff;jpeg=jpeg[:2]+b'\xff\xe1'+struct.pack('>H',len(app1)+2)+app1+jpeg[2:]
        native_tags=[(0x100,struct.pack('>HH',3296,4936)),(0x121,struct.pack('>HH',3264,4896)),(0x110,struct.pack('>HH',16,20)),(0x2ff0,struct.pack('>4H',1024,512,800,1024))]
        native=struct.pack('>I',len(native_tags))+b''.join(struct.pack('>HH',tag,len(value))+value for tag,value in native_tags)
        header=bytearray(128);header[:16]=b'FUJIFILMCCD-RAW ';header[16:20]=b'0201';header[28:32]=b'X-T1';header[60:64]=b'0100'
        header[84:100]=struct.pack('>4I',128,len(jpeg),128+len(jpeg),len(native))
        raf=bytes(header)+jpeg+native+bytes(512)
        assert upload_raw('20170510_0127.RAF',raf)[0]==200
        raw_id,raw_mime=db.execute("select id,mime from attachments where name='20170510_0127.RAF'").fetchone()
        assert raw_mime=='application/octet-stream'
        assert req(lawyer,f'?file={raw_id}&download=1')[1]==raf,'RAF original bytes preserved'
        preview_url=f'?file={raw_id}&raw_preview=1'
        code,content,headers=req(lawyer,preview_url)
        assert code==200 and content==jpeg and headers['Content-Type']=='image/jpeg'
        assert req(guest,preview_url)[0]==403
        assert req(owner,preview_url+'&thumb=1')[0]==200
        assert req(owner,preview_url+'&download=1')[1]==raf
        assert f'href="?file={raw_id}&amp;raw_preview=1"' in req(owner)[1].decode()
        db.execute('update cases set preview_key=NULL where id=1');db.execute('delete from attachments where id=1');db.commit()
        assert f'class="case-thumb" href="?file={raw_id}&amp;raw_preview=1"' in req(owner)[1].decode()
        broken=b'FUJIFILMCCD-RAW '+bytes(68)+struct.pack('>II',4294967295,4294967295)
        assert upload_raw('broken.RAF',broken)[0]==200
        broken_id=db.execute("select id from attachments where name='broken.RAF'").fetchone()[0]
        assert req(owner,f'?file={broken_id}&raw_preview=1')[0]==404
        assert req(owner,f'?file={broken_id}&download=1')[1]==broken
        meta_url=f'?file={raw_id}&metadata=1'
        assert req(guest,meta_url)[0]==403
        assert req(guest,meta_url+'&download=1')[0]==403
        code,body,headers=req(lawyer,meta_url);assert code==200
        meta=json.loads(body);values=[row['value'] for row in meta['rows']]
        assert 'FUJIFILM' in values and 'X-T2' in values and '2017:05:10 12:34:56' in values,meta
        assert 'Hunta <script>alert(1)</script>' in values
        assert '4936 × 3296' in values and '4896 × 3264' in values and 'X-T1' in values
        assert any(row['group']=='RAF · исходный RAW' for row in meta['rows'])
        assert any(row['group']=='EXIF внутри RAF · IFD0' for row in meta['rows'])
        assert not any(row['label']=='Размер встроенного JPEG' for row in meta['rows'])
        assert str(store).encode() not in body and b'storage_key' not in body
        code,report,headers=req(photo,meta_url+'&download=1')
        assert code==200 and headers['Content-Type'].startswith('text/plain')
        assert 'attachment' in headers['Content-Disposition'] and report.startswith(b'\xef\xbb\xbf')
        assert 'Камера [Model]: X-T2'.encode() in report
        assert req(owner,'?file=999999&metadata=1')[0]==404
        assert req(owner,f'?file={broken_id}&metadata=1')[0]==200
        assert json.loads(req(owner,'?file=2&metadata=1')[1])['notice']
        page=req(owner)[1].decode();assert 'Посмотреть метаданные' in page and 'Скачать метаданные' in page
        assert req(owner,f'?file={raw_id}&download=1')[1]==raf
        # Native metadata must remain available even if no JPEG can be extracted.
        nojpeg=bytearray(raf);nojpeg[84:92]=bytes(8)
        assert upload_raw('native-only.RAF',bytes(nojpeg))[0]==200
        native_id=db.execute("select id from attachments where name='native-only.RAF'").fetchone()[0]
        native_meta=json.loads(req(owner,f'?file={native_id}&metadata=1')[1])
        assert '4936 × 3296' in [row['value'] for row in native_meta['rows']]
        assert req(owner,f'?file={native_id}&download=1')[1]==bytes(nojpeg)
        bad_dir=bytearray(raf);bad_dir[92:100]=struct.pack('>II',4294967295,4294967295)
        assert upload_raw('bad-directory.RAF',bytes(bad_dir))[0]==200
        bad_id=db.execute("select id from attachments where name='bad-directory.RAF'").fetchone()[0]
        bad_meta=json.loads(req(owner,f'?file={bad_id}&metadata=1')[1])
        assert 'неверные границы' in bad_meta['notice']
        assert upload_raw('camera.jpg',jpeg)[0]==200
        jpeg_id=db.execute("select id from attachments where name='camera.jpg'").fetchone()[0]
        jpeg_meta=json.loads(req(lawyer,f'?file={jpeg_id}&metadata=1')[1])
        assert 'X-T2' in [row['value'] for row in jpeg_meta['rows']]
        assert 'Загруженный оригинал' in [row['value'] for row in jpeg_meta['rows']]
        assert req(lawyer,f'?file={jpeg_id}&download=1')[1]==jpeg
        # Source preview metadata reads the saved image, through the same login gate.
        assert req(guest,'?preview=1&metadata=1')[0]==403
        assert req(guest,'?preview=1&metadata=1&download=1')[0]==403
        assert req(owner,'?preview=999999&metadata=1')[0]==404
        assert req(owner,'?preview=1&metadata=1')[0]==404 # no preview assigned
        key=db.execute('select storage_key from attachments where id=?',(jpeg_id,)).fetchone()[0]
        db.execute("update cases set preview_key=?,source_url='https://stock.example.test/photo',source_title='Source photo' where id=1",(key,));db.commit()
        for user in (owner,lawyer,photo):
            page=req(user)[1].decode()
            assert 'href="?preview=1&amp;metadata=1" data-metadata' in page
            assert 'href="?preview=1&amp;metadata=1&amp;download=1"' in page
            code,body,headers=req(user,'?preview=1&metadata=1');assert code==200
            meta=json.loads(body);vals=[r['value'] for r in meta['rows']]
            assert 'X-T2' in vals and '40 × 30' in vals
            assert 'Сохранённая картинка источника' in vals and 'https://stock.example.test/photo' in vals
            assert 'Сток может удалить EXIF/IPTC' in meta['notice']
            assert str(store).encode() not in body and b'storage_key' not in body
            assert headers['Content-Type'].startswith('application/json')
            code,report,headers=req(user,'?preview=1&metadata=1&download=1')
            assert code==200 and report.startswith(b'\xef\xbb\xbf') and b'X-T2' in report
            assert headers['Content-Type'].startswith('text/plain') and 'attachment' in headers['Content-Disposition']
        assert req(owner,'?preview=1&download=1')[1]==jpeg
        # A saved PNG without EXIF still returns image dimensions and a clear notice.
        png_key='preview-without-exif';(store/'files'/png_key).write_bytes(png)
        db.execute('update cases set preview_key=? where id=1',(png_key,));db.commit()
        meta=json.loads(req(lawyer,'?preview=1&metadata=1')[1])
        assert '1 × 1' in [r['value'] for r in meta['rows']] and 'EXIF/IPTC не найдены' in meta['notice']
        assert req(owner,'?preview=1&download=1')[1]==png
        db.execute('update cases set preview_key=NULL where id=1');db.commit()

        assert upload_raw('not-a-camera.RAF',b'not a Fujifilm raw file')[0]==422
        assert db.execute("select count(*) from attachments where name='not-a-camera.RAF'").fetchone()[0]==0
        # CR2 JPEG EXIF deliberately disagrees with native RAW metadata.
        def canon_raw(order='<', preview=True, broken=False):
            out=bytearray(1024)
            out[:16]=(b'II' if order=='<' else b'MM')+struct.pack(order+'HI',42,16)+b'CR\x02\x00'+bytes(4)
            cursor=512
            def directory(offset,tags,next_ifd=0):
                nonlocal cursor
                struct.pack_into(order+'H',out,offset,len(tags))
                for i,(tag,kind,count,value) in enumerate(tags):
                    data=value if isinstance(value,bytes) else struct.pack(order+{3:'H',4:'I',5:'II'}[kind],*value)
                    if len(data)<=4: field=data.ljust(4,b'\x00')
                    else:
                        field=struct.pack(order+'I',cursor);out[cursor:cursor+len(data)]=data;cursor+=len(data)
                    out[offset+2+i*12:offset+14+i*12]=struct.pack(order+'HHI',tag,kind,count)+field
                struct.pack_into(order+'I',out,offset+2+12*len(tags),next_ifd)
            directory(16,[(0x10f,2,6,b'Canon\x00'),(0x110,2,13,b'RAW EOS TEST\x00'),
                (0x111,4,1,[4294967295 if broken else 1024]),(0x117,4,1,[len(jpeg) if preview else 0]),
                (0x8769,4,1,[256]),(0x8825,4,1,[384])],16) # cyclic next IFD must terminate
            directory(256,[(0x829a,5,1,[1,125]),(0x8827,3,1,[400]),(0x9003,2,20,b'2020:01:02 03:04:05\x00')])
            directory(384,[(1,2,2,b'N\x00')])
            return bytes(out)+jpeg
        for suffix,order,preview,broken in [('little','<',True,False),('big','>',True,False),('no-jpeg','<',False,False),('bad-offset','<',True,True)]:
            cr2=canon_raw(order,preview,broken);name='canon-'+suffix+'.CR2'
            code,body,_=upload_raw(name,cr2);assert code==200,(code,body)
            cid=db.execute('select id from attachments where name=?',(name,)).fetchone()[0]
            url=f'?file={cid}&raw_preview=1'
            assert req(guest,url)[0]==403
            assert req(lawyer,url)[0]==(200 if preview and not broken else 404)
            if preview and not broken:
                assert req(lawyer,url)[1]==jpeg
                assert req(owner,url+'&thumb=1')[2]['Content-Type']=='image/jpeg'
                assert f'href="?file={cid}&amp;raw_preview=1"' in req(owner)[1].decode()
            assert req(lawyer,url+'&download=1')[1]==cr2
            assert req(guest,f'?file={cid}&metadata=1')[0]==403
            meta=json.loads(req(lawyer,f'?file={cid}&metadata=1')[1]);vals=[r['value'] for r in meta['rows']]
            assert 'RAW EOS TEST' in vals and '1/125' in vals and '400' in vals and 'N' in vals,meta
            assert 'X-T2' not in vals and 'FUJIFILM' not in vals, 'JPEG must never supply CR2 metadata'
            assert all('исходный RAW' in r['group'] for r in meta['rows'] if r['group']!='Файл')
            assert str(store) not in json.dumps(meta)
            report=req(photo,f'?file={cid}&metadata=1&download=1')[1]
            assert b'RAW EOS TEST' in report and b'X-T2' not in report
            assert req(owner,f'?file={cid}&download=1')[1]==cr2
        print('Photo desk HTTP checks passed')
    finally:
        server.terminate();server.wait(timeout=5);log.close()
    # The same public directory serves the desk on its exact hostname, before blog bootstrap.
    log=open(store/'shared-server.log','w+')
    shared=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=log,stderr=log)
    try:
        for _ in range(100):
            try:
                code,body,_=req(client(),headers={'Host':'files.hunta-zen.ru'})
                if code==200:break
            except OSError:time.sleep(.05)
        assert code==200 and 'Вход в кабинет'.encode() in body,(code,body)
        for path in ('admin.php','media.php','robots.php','sitemap.php'):
            code,body,headers=req(client(),path,headers={'Host':'files.hunta-zen.ru'})
            assert code==200 and 'Вход в кабинет'.encode() in body,(path,code,body)
            assert headers['X-Robots-Tag'].startswith('noindex')
        code,body,_=req(client(),'?asset=desk.js',headers={'Host':'files.hunta-zen.ru'})
        assert code==200 and b'data-flag' in body
        print('Shared document root routing checks passed')
    finally:
        shared.terminate();shared.wait(timeout=5);log.close()

