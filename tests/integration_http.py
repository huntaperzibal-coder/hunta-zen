"""Real HTTP checks; no external requests or real Telegram credentials."""
import base64, http.cookiejar, json, os, pathlib, re, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='hunta-http-') as tmp:
    storage=pathlib.Path(tmp);(storage/'photos').mkdir()
    config={'storage':tmp,'site_url':'https://example.test','login':'author','password_hash':subprocess.check_output([PHP,'-r',"echo password_hash('test-password-only',PASSWORD_DEFAULT);"],text=True),'telegram_token':'','telegram_channel':''}
    (storage/'config.php').write_text('<?php return json_decode('+repr(json.dumps(config))+',true);')
    env={**os.environ,'HUNTA_CONFIG':str(storage/'config.php')}
    subprocess.run([PHP,str(ROOT/'tools/migrate.php')],env=env,check=True,capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    with tempfile.TemporaryFile() as log:
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=log,stderr=log)
        try:
            jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def request(path, data=None, opener=client, headers=None):
                if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
                try:
                    response=opener.open(urllib.request.Request(base+path,data=data,headers=headers or {}),timeout=5)
                except urllib.error.HTTPError as error:response=error
                return response.status,response.read().decode(errors='replace'),response.geturl()
            for _ in range(100):
                try:request('/');break
                except urllib.error.URLError:time.sleep(.05)
            else:raise AssertionError('server failed to start')
            result=request('/admin.php',{'action':'new'}); assert result[0]==403,repr(result)
            _,html,_=request('/admin.php');csrf=re.search(r'name="csrf" value="([^"]+)"',html)[1]
            _,html,_=request('/admin.php',{'action':'login','csrf':csrf,'login':'author','password':'test-password-only'})
            assert 'Мои истории' in html,'login'
            assert 'Дао' in html and 'Тамга' not in html,'Dao identity'
            assert '/assets/brand/dao-admin.svg?v=2' in html,'admin emblem and favicon'
            assert 'class="side"' in html and 'class="layout"' in html,'Orda shell'
            assert '/styles_core/adminka/stili_orda.css' in html and '/assets/css/app.css' not in html,'original Orda stylesheet'
            assert request('/styles_core/adminka/stili_orda.css')[0]==200,'CSS served'
            assert request('/assets/fonts/Manrope-Regular.woff2')[0]==200,'local font served'
            for path in ['/assets/brand/dialogue.svg','/assets/brand/dao-admin.svg','/assets/brand/dialogue-32.png','/assets/brand/dao-admin.ico','/site.webmanifest','/admin.webmanifest','/favicon.ico']:
                assert request(path)[0]==200,'brand asset '+path
            public_head=request('/')[1]
            assert '/assets/brand/dialogue.svg?v=2' in public_head and '/assets/brand/dao-admin.svg' not in public_head,'separate site identity'
            for path,heading in [('/admin.php?view=settings','Настройки'),('/admin.php?view=telegram','Telegram'),('/admin.php?view=service','Обслуживание')]:
                code,panel,_=request(path);assert code==200 and heading in panel,'navigation '+path
            _,html,url=request('/admin.php',{'action':'new','csrf':csrf});pid=urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['id'][0]
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            data={'action':'save','csrf':csrf,'id':pid,'revision':revision,'title':'История <script>x</script>','body':'Очень личный черновик','excerpt':'','telegram_text':''}
            _,html,_=request('/admin.php',data)
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            anonymous=urllib.request.build_opener()
            assert 'Очень личный черновик' not in request('/',opener=anonymous)[1],'draft hidden'
            assert request('/?p=istoriya-script-x-script',opener=anonymous)[0]==404
            assert request('/proofread.php?id='+pid+'&job=1',opener=anonymous)[0]==401,'proofreading status requires login'
            assert request('/proofread.php',{'id':pid,'revision':revision,'body':'<p>Превет</p>'})[0]==403,'proofreading requires CSRF'
            (storage/'openrouter-key.php').write_text("<?php return 'offline-only-key';")
            code,proof,_=request('/proofread.php',{'id':pid,'revision':revision,'csrf':csrf,'body':'<p>Превет</p>'})
            assert code==200,proof
            proof_job=json.loads(proof)['job']
            assert json.loads(request('/illustration-status.php?id='+pid)[1])==[],'corrections are not image variants'
            assert json.loads(request('/proofread.php?id='+pid+'&job='+str(proof_job))[1])['state']=='queued','private correction queue'
            assert request('/proofread.php',{'id':pid,'revision':0,'csrf':csrf,'body':'<p>Превет</p>'})[0]==409,'stale correction rejected'
            assert 'Очень личный черновик' in request('/admin.php?view=edit&id='+pid)[1],'checking never replaces saved story'
            # Cover upload also saves the article; private photo must stay private.
            boundary='hunta-test-boundary';fields={**data,'action':'upload','revision':revision,'photo_kind':'cover'}
            parts=[]
            for key,val in fields.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{val}\r\n'.encode())
            png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX2kAAAAASUVORK5CYII=')
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            _,html,_=request('/admin.php',b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
            photos=list((storage/'photos').iterdir());assert len(photos)==1,'upload'
            photo=photos[0].name
            assert request('/media.php?file='+photo,opener=anonymous)[0]==404,'draft cover hidden'
            assert request('/media.php?file=../../config.php',opener=anonymous)[0]==404,'traversal blocked'
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            data.update(action='publish',revision=revision)
            _,html,_=request('/admin.php',data)
            public=request('/',opener=anonymous)[1];assert 'Очень личный черновик' in public and '<script>x</script>' not in public,'published and escaped'
            assert request('/media.php?file='+photo,opener=anonymous)[0]==200,'published cover visible'
            stale={**data,'action':'autosave','body':'overwrite attempt'}
            assert request('/admin.php',stale)[0]==409,'HTTP conflict'
            assert 'overwrite attempt' not in request('/',opener=anonymous)[1]
            _,html,_=request('/admin.php?view=edit&id='+pid)
            assert '/assets/tinymce/tinymce.min.js' in html and 'name="body_format" value="html"' in html,'visual editor loaded'
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            rich={**data,'action':'autosave','revision':revision,'body_format':'html','body':'<h2>Подзаголовок</h2><p><strong>Форматирование</strong></p><script>alert(1)</script><p><img src="/media.php?file='+photo+'" onerror="alert(1)"></p>'}
            code,result,_=request('/admin.php',rich);assert code==200,result
            public=request('/?p=istoriya-script-x-script',opener=anonymous)[1]
            assert '<h2>Подзаголовок</h2>' in public and '<strong>Форматирование</strong>' in public,'formatted publication'
            assert 'onerror=' not in public and '<script>alert(1)' not in public,'server sanitizes HTML'
            feed=request('/',opener=anonymous)[1]
            assert '<strong>Форматирование</strong>' in feed and '<h2>Подзаголовок</h2>' in feed,'feed contains entire rich post'
            assert 'reading-sidebar' in feed and 'Читать историю →' not in feed and '<section class="intro">' not in feed,'compact feed has sidebar without duplicate introduction or excerpt links'
            saved_revision=json.loads(result)['revision']
            category_id=re.search(r'<option value="(\d+)"[^>]*>Диалоги Винни</option>',html)[1]
            code,result,_=request('/admin.php',{**rich,'revision':saved_revision,'category_id':category_id});assert code==200,result
            assert 'class="story"' in request('/?category=vinnie',opener=anonymous)[1],'published category filter'
            assert 'class="story"' not in request('/?category=gena',opener=anonymous)[1],'other category excludes story'
            assert 'class="post-title"' in request('/admin.php?category=vinnie')[1],'admin category filter'
            assert 'class="post-title"' not in request('/admin.php?category=gena')[1],'admin other category'
            assert request('/illustration-status.php?id=1',opener=anonymous)[0]==401,'illustration history private'
            assert request('/illustration-status.php?id=1')[0]==200,'author can check illustration status'
            assert request('/admin.php',{'action':'illustrator_download','kind':'key'})[0]==403,'SSH key download requires csrf'
            assert 'HTTPS' in request('/admin.php',{'action':'illustrator_download','kind':'key','csrf':csrf})[1],'SSH private key cannot be downloaded over plain HTTP'
            settings_page=request('/admin.php?view=illustrator')[1]
            assert 'Модель читателя' in settings_page and 'name="api_key"' in settings_page,'illustrator settings UI'
            assert request('/admin.php?view=illustrator',{'action':'illustrator_settings','reader':'x/y','painter':'x/y'})[0]==403,'illustrator settings require csrf'
            assert request('/editor-upload.php',b'')[0]==403,'upload requires csrf'
            assert request('/editor-upload.php',b'',opener=anonymous)[0]==401,'upload requires login'
            subprocess.run([PHP,str(ROOT/'tools/backup.php')],env=env,check=True,capture_output=True)
            copies=list((storage/'backups').iterdir());assert len(copies)==1 and (copies[0]/'photos'/photo).exists(),'backup photos'
            print('OK: HTTP login, CSRF, drafts, upload, private media, publishing, XSS, conflicts, backup')
        finally:
            server.terminate();server.wait(timeout=5)
