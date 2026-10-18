"""Real HTTP checks; no external requests or real Telegram credentials."""
import base64, http.cookiejar, json, os, pathlib, re, socket, sqlite3, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='hunta-http-') as tmp:
    storage=pathlib.Path(tmp);(storage/'photos').mkdir()
    config={'storage':tmp,'database_backup_dir':tmp+'/private-db-copies','site_url':'https://example.test','login':'author','password_hash':subprocess.check_output([PHP,'-r',"echo password_hash('test-password-only',PASSWORD_DEFAULT);"],text=True),'telegram_token':'','telegram_channel':'-100100'}
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
            assert 'theme-label' not in public_head and 'theme-icon' not in public_head,'theme toggle has no visible text or emoji'
            for icon in ['sun','moon']:
                code,svg,_=request('/assets/icons/'+icon+'.svg');assert code==200 and '<svg' in svg,'copied theme icon served'
            assert '/assets/js/reader-theme.js?v=2' in public_head and '/assets/css/reader.css?v=17' in public_head,'theme assets cache refreshed'
            assert '/assets/brand/dialogue.svg?v=2' in public_head and '/assets/brand/dao-admin.svg' not in public_head,'separate site identity'
            for path,heading in [('/admin.php?view=settings','Настройки'),('/admin.php?view=telegram','Telegram'),('/admin.php?view=service','Обслуживание'),('/admin.php?view=github','GitHub и резервные копии')]:
                code,panel,_=request(path);assert code==200 and heading in panel,'navigation '+path
            _,github,_=request('/admin.php?view=github')
            assert 'data-copy-command=' in github and 'tools/backup_database.php --list' in github,'GitHub has copyable backup commands'
            assert 'НА КОМПЬЮТЕРЕ' in github and 'НА СЕРВЕРЕ' in github and 'sqlite3.connect' in github,'computer export command verifies SQLite'
            assert 'GitHub и резервные копии' not in request('/admin.php?view=github',opener=urllib.request.build_opener())[1],'GitHub commands need login'
            assert request('/assets/js/github-hub.js')[0]==200,'command clipboard script served'
            assert request('/tools/backup_database.php')[0]==404,'backup CLI not exposed to web'
            assert 'Скачать ВЕСЬ САЙТ на компьютер' in github and 'tools/backup_site.php' in github,'full website download command available'
            _,html,url=request('/admin.php',{'action':'new','csrf':csrf});pid=urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['id'][0]
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            data={'action':'save','csrf':csrf,'id':pid,'revision':revision,'title':'История <script>x</script>','body':'Очень личный черновик','excerpt':'','telegram_text':''}
            _,html,_=request('/admin.php',data)
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            anonymous=urllib.request.build_opener()
            assert 'Очень личный черновик' not in request('/',opener=anonymous)[1],'draft hidden'
            assert request('/?p=istoriya-script-x-script',opener=anonymous)[0]==404
            assert '/assets/js/editor-clipboard.js?v=1' in html and '/assets/js/editor.js?v=18' in html,'clipboard helper loads before editor'
            assert 'Анонс в ленте' not in html and 'Описание для ссылок' in html,'metadata description is named accurately'
            assert html.index('id="telegram"') < html.index('<summary>Фотографии</summary>'),'publishing actions precede optional media settings'
            assert html.index('/assets/js/video-preview.js') < html.index('/assets/js/editor.js'),'video preparation loads before editor'
            assert html.index('/assets/js/editor-clipboard.js') < html.index('/assets/js/editor.js'),'clipboard script order'
            assert request('/proofread.php')[0]==404,'retired proofreading endpoint is unavailable'
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
            # Stored delivery status in the list, scoped to the selected channel; no Telegram requests.
            with sqlite3.connect(storage/'blog.sqlite') as records:
                def badge():
                    code,panel,_=request('/admin.php?category=vinnie')
                    assert code==200 and '<th>Telegram</th>' in panel
                    assert f'href="/admin.php?view=edit&amp;id={pid}#telegram"' in panel
                    return re.search(r'class="status-chip tg-list-status tg-list-([^" ]+)">([^<]+)',panel).groups()
                assert badge()==('none','Не размещено')
                records.execute("INSERT INTO telegram_deliveries(post_id,channel,state,updated_at) VALUES(?,'-100OTHER','sent',datetime('now'))",[pid]);records.commit()
                assert badge()==('none','Не размещено'),'another channel must not mark the post as delivered'
                records.execute("INSERT INTO telegram_deliveries(post_id,channel,state,updated_at) VALUES(?,'-100100','sent',datetime('now'))",[pid]);records.commit()
                assert badge()==('sent','✓ Размещено')
                for state,expected in [('unknown',('check','Проверь канал')),('sending',('check','Проверь канал')),('failed',('failed','Ошибка отправки'))]:
                    records.execute("UPDATE telegram_deliveries SET state=? WHERE post_id=? AND channel='-100100'",[state,pid]);records.commit();assert badge()==expected
                records.execute("INSERT INTO telegram_schedule(post_id,channel,bot_fingerprint,snapshot,due_at,created_at,updated_at) VALUES(?,'-100100','test','{}',?,datetime('now'),datetime('now'))",[pid,int(time.time())+3600]);records.commit()
                assert badge()==('pending','Запланировано')
                records.execute("UPDATE telegram_deliveries SET state='sent' WHERE post_id=? AND channel='-100100'",[pid]);records.commit()
                assert badge()==('sent','✓ Размещено') and 'Обновление запланировано' in request('/admin.php')[1]
                records.execute("UPDATE telegram_schedule SET state='cancelled' WHERE post_id=?",[pid]);records.commit()
                assert 'Обновление запланировано' not in request('/admin.php')[1]
            assert 'tg-list-status' not in request('/',opener=anonymous)[1],'delivery markers remain private'
            # Schedule and cancel in place; no Telegram calls or real bot token.
            (storage/'telegram-connection.php').write_text("<?php return ['telegram_token'=>'123456:"+'x'*35+"','telegram_channel'=>'-100100'];")
            _,edit,_=request('/admin.php?view=edit&id='+pid)
            current_revision=re.search(r'name="revision" value="(\d+)"',edit)[1]
            schedule={**data,'action':'telegram_schedule','revision':current_revision,'telegram_inline':'1','telegram_due':time.strftime('%Y-%m-%dT%H:%M',time.gmtime(time.time()+8*3600))}
            assert request('/admin.php',{**schedule,'csrf':'wrong'})[0]==403,'inline scheduling needs CSRF'
            code,result,url=request('/admin.php',schedule);assert code==200,result
            inline=json.loads(result);assert '#telegram' not in url and 'html' in inline and 'Отправка запланирована' in inline['message'],'JSON scheduling returns in place, no redirect'
            assert 'Отправка назначена' in inline['html'] and 'Отменить эту отправку' in inline['html'],'cancel belongs to a clearly labelled current task'
            assert 'Отменяет только расписание' in inline['html'],'cancel scope explained'
            _,panel,_=request('/admin.php?view=edit&id='+pid)
            assert 'name="telegram_due" value="'+schedule['telegram_due']+'"' in panel,'scheduled time shown in input'
            stale=request('/admin.php',{**schedule,'revision':current_revision})
            assert stale[0]==409 and json.loads(stale[1])['revision'] is None,'stale AJAX scheduling cannot overwrite author changes'
            cancel={**schedule,'action':'telegram_cancel','revision':inline['revision']}
            code,result,_=request('/admin.php',cancel);assert code==200,result
            cancelled=json.loads(result);assert 'Ожидающая отправка отменена' in cancelled['message'] and 'value="telegram_cancel"' not in cancelled['html']
            code,again,_=request('/admin.php',{**cancel,'revision':cancelled['revision']});assert code==200
            assert 'Ожидающей отправки уже нет' in json.loads(again)['message'],'late cancellation does not claim a completed task was cancelled'
            history=cancelled['html'].split('class="tg-schedule-history"',1)[1]
            assert history.startswith('><summary>') and '<details class="tg-schedule-history" open' not in cancelled['html'],'past attempts collapsed separately'
            assert 'не дополнительные публикации в очереди' in history and 'Отменить эту отправку' not in history,'old history has no cancel controls'
            # Public service menu and reader feedback; no external messages or payments.
            settings={'action':'settings','csrf':csrf,'title':'Гена и Винни','description':'','telegram_url':'https://t.me/example_channel','support_url':'https://example.test/donate','support_text':'Для новых историй'}
            assert request('/admin.php',settings)[0]==200
            home=request('/',opener=anonymous)[1]
            assert 'data-service-toggle' in home and '<dialog id="reader-services"' in home and 'reader-services.js?v=1' in home,'hamburger and native service dialog'
            assert home.count('https://t.me/example_channel')==1,'channel link lives in service menu without header duplicate'
            assert '<span>Читать в Telegram</span><span aria-hidden="true">↗</span>' not in home,'Telegram menu has no external-link arrow'
            assert '<a href="/feedback.php">Автор</a>' in request('/support.php',opener=anonymous)[1] and 'познакомиться с автором</a>' not in request('/support.php',opener=anonymous)[1],'support links to author in compact top navigation'
            assert '<span>Поддержать</span></a></nav><h1>' in request('/feedback.php',opener=anonymous)[1] and 'поддержать их продолжение</a>' not in request('/feedback.php',opener=anonymous)[1],'author has heart support link in top navigation without prose duplication'

            assert 'В этой ленте' in home and 'category-tabs' in home,'reading navigation remains in place'
            assert 'href="https://example.test/donate"' in request('/support.php',opener=anonymous)[1]
            assert request('/services.php',opener=anonymous)[0]==200,'no-JS menu fallback'
            request('/admin.php',{**settings,'support_url':'javascript:alert(1)'})
            assert 'href="https://example.test/donate"' in request('/support.php',opener=anonymous)[1],'invalid support URL cannot replace saved destination'
            author_settings={'action':'author_settings','csrf':csrf,'author_name':'Hunta','author_bio':'Автор <script>bad()</script>','operator_name':'Тестовый оператор','operator_address':'Тестовый адрес','operator_email':'author@example.test','feedback_enabled':'1'}
            invalid_author={**author_settings,'operator_name':'Введённое имя не теряется','operator_address':'','operator_email':'author@example.test'}
            code,failed,_=request('/admin.php',invalid_author)
            assert code==200 and 'Не удалось сохранить' in failed and 'Введённое имя не теряется' in failed,'validation error preserves submitted author fields'
            assert 'Введённые значения оставлены в полях' in failed and 'id="author-settings-status"' in failed,'inline author validation is visible'
            assert request('/admin.php',author_settings)[0]==200,'profile and legal settings saved'
            _,saved_author,_=request('/admin.php?view=author&saved=1')
            assert 'Форма включена' in saved_author and 'Изменения сохранены' in saved_author,'saved author screen confirms actual availability'
            assert 'type="text" name="operator_name"' in saved_author and 'author-settings-form' in saved_author,'author text inputs use the scoped form styling'

            assert 'Познакомиться' in request('/feedback.php',opener=anonymous)[1] and '<script>bad()' not in request('/feedback.php',opener=anonymous)[1],'author profile escapes text'
            assert 'Тестовый оператор' in request('/privacy.php',opener=anonymous)[1] and 'Согласие' in request('/consent.php',opener=anonymous)[1],'operator information in separate legal pages'
            guest=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            _,form,_=request('/feedback.php',opener=guest)
            feedback_csrf=re.search(r'name="csrf" value="([^"]+)"',form)[1]
            submission=re.search(r'name="submission" value="([^"]+)"',form)[1]
            answer=sum(map(int,re.search(r'[Сс]колько будет ([0-9]+) \+ ([0-9]+)',form).groups()))
            consent_digest=re.search(r'name="consent_digest" value="([^"]+)"',form)[1]
            message={'consent':'1','consent_digest':consent_digest,'answer':str(answer),'csrf':feedback_csrf,'submission':submission,'message':'Личное сообщение <script>private()</script>','contact':'@reader','website':''}
            assert request('/feedback.php',{**message,'csrf':'bad'},opener=guest)[0]==403,'public feedback requires CSRF'
            assert request('/feedback.php',message,opener=guest)[0]==422,'instant form submission rejected'
            time.sleep(3.05)
            assert request('/feedback.php',{**message,'consent':''},opener=guest)[0]==422,'consent required server side'
            assert request('/feedback.php',{**message,'answer':'99'},opener=guest)[0]==422,'challenge required server side'
            code,thanks,_=request('/feedback.php',message,opener=guest);assert code==200 and 'Сообщение сохранено' in thanks
            assert request('/feedback.php',message,opener=guest)[0]==200,'feedback submission is idempotent'
            assert request('/feedback.php',{**message,'submission':'a'*32},opener=guest)[0]==422,'feedback rate limited by address'
            _,inbox,_=request('/admin.php?view=feedback')
            assert inbox.count('Личное сообщение')==1 and '&lt;script&gt;private()&lt;/script&gt;' in inbox and '<script>private()' not in inbox,'private inbox escapes content and avoids duplicate submissions'
            assert 'Личное сообщение' not in request('/admin.php?view=feedback',opener=guest)[1],'reader inbox needs author login'
            message_id=re.search(r'name="message_id" value="(\d+)"',inbox)[1]
            assert request('/admin.php',{'action':'feedback_read','message_id':message_id})[0]==403,'read marker requires CSRF'
            code,inbox,_=request('/admin.php',{'action':'feedback_read','message_id':message_id,'csrf':csrf});assert code==200 and 'Прочитано · ' in inbox
            assert 'Личное сообщение' not in request('/',opener=guest)[1],'messages never appear in public feed'
            with sqlite3.connect(storage/'blog.sqlite') as check:
                assert check.execute('SELECT count(*) FROM reader_feedback_consents').fetchone()[0]==1,'accepted consent recorded separately'
            assert request('/admin.php',{'action':'feedback_delete','message_id':message_id})[0]==403,'message deletion needs CSRF'
            request('/admin.php',{'action':'feedback_delete','message_id':message_id,'csrf':csrf})
            with sqlite3.connect(storage/'blog.sqlite') as check:
                assert check.execute('SELECT count(*) FROM reader_feedback').fetchone()[0]==0 and check.execute('SELECT count(*) FROM reader_feedback_consents').fetchone()[0]==0,'private message deletion also removes consent'

            # A real tiny H.264 MP4: authenticated multipart upload, private draft, byte seeking.
            mp4=base64.b64decode('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')
            def upload_video(content, token=csrf, opener=client, kind='video', video_name=''):
                fields={'csrf':token,'id':pid,'kind':kind,'video_name':video_name}
                parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key,value in fields.items()]
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="clip.mp4"\r\nContent-Type: video/mp4\r\n\r\n'.encode()+content+b'\r\n')
                parts.append(f'--{boundary}--\r\n'.encode())
                return request('/editor-upload.php',b''.join(parts),opener=opener,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
            assert upload_video(mp4,opener=anonymous)[0]==401,'video upload needs login'
            assert upload_video(mp4,token='bad')[0]==403,'video upload needs CSRF'
            assert upload_video(b'<?php echo "bad";')[0]==422,'fake MP4 rejected by content type'
            code,result,_=upload_video(mp4);assert code==200,result
            video_url=json.loads(result)['location'];video=urllib.parse.parse_qs(urllib.parse.urlparse(video_url).query)['file'][0]
            assert (storage/'photos'/video).read_bytes()==mp4,'video saved intact'
            preview=base64.b64decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAJABADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDjaKKK9Q8c/9k=')
            assert upload_video(preview,kind='video_preview',video_name=video,opener=anonymous)[0]==401,'cover upload needs login'
            assert upload_video(preview,kind='video_preview',video_name=video,token='bad')[0]==403,'cover upload needs CSRF'
            assert upload_video(preview,kind='video_preview',video_name=video)[0]==200,'JPEG cover accepted for existing video'
            assert (storage/'photos'/(video+'.thumb.jpg')).read_bytes()==preview,'video cover saved intact'
            assert upload_video(mp4,kind='video_preview',video_name=video)[0]==422,'MP4 cannot masquerade as JPEG cover'
            assert upload_video(mp4,kind='video_preview',video_name='../config.php')[0]==422,'cover target cannot escape photos directory'

            assert request(video_url,opener=anonymous)[0]==404,'unattached video private'
            with sqlite3.connect(storage/'blog.sqlite') as check:
                check.execute("INSERT INTO posts(slug,title,body,status,created_at,updated_at) VALUES(?,?,?,'draft',1,1)",('video-test','Видео','<!--dao:rich-v1--><p>До</p><video src="'+video_url+'" controls></video><p>После</p>'))
            assert request(video_url,opener=anonymous)[0]==404,'draft video private'
            assert request(video_url)[0]==200,'author can view draft video'
            with sqlite3.connect(storage/'blog.sqlite') as check:check.execute("UPDATE posts SET status='published',published_at='2026-10-18T10:00:00+07:00' WHERE slug='video-test'")
            assert '<video src="'+video_url+'" controls playsinline preload="metadata"></video>' in request('/?p=video-test',opener=anonymous)[1],'public post has native player'
            for value,start,end in [('bytes=4-15',4,15),('bytes=16-',16,len(mp4)-1),('bytes=-12',len(mp4)-12,len(mp4)-1)]:
                with anonymous.open(urllib.request.Request(base+video_url,headers={'Range':value})) as res:
                    assert res.status==206 and res.headers['Content-Range']==f'bytes {start}-{end}/{len(mp4)}'
                    assert res.read()==mp4[start:end+1],'video seek bytes match'
            assert request(video_url,opener=anonymous,headers={'Range':'bytes=999999-'})[0]==416,'invalid range rejected'
            with anonymous.open(urllib.request.Request(base+video_url,method='HEAD')) as res:
                assert res.headers['Content-Type']=='video/mp4' and int(res.headers['Content-Length'])==len(mp4) and res.read()==b'','video HEAD'
            subprocess.run([PHP,str(ROOT/'tools/backup.php')],env=env,check=True,capture_output=True)
            copies=list((storage/'backups').iterdir());assert len(copies)==1 and (copies[0]/'photos'/photo).exists(),'backup photos'
            assert (copies[0]/'photos'/video).read_bytes()==mp4,'verified backup includes video'
            assert (copies[0]/'photos'/(video+'.thumb.jpg')).read_bytes()==preview,'verified backup includes video cover'
            # List placement filters and independent management; no Telegram API calls.
            with sqlite3.connect(storage/'blog.sqlite') as records:
                ids=[]
                for slug,state,tg in [('local-hub','draft',False),('site-hub','published',False),('tg-hub','draft',True),('both-hub','published',True)]:
                    row=records.execute("INSERT INTO posts(slug,title,body,status,created_at,updated_at) VALUES(?,?,?,?,?,?)",(slug,slug,'Текст',state,'2026-10-18','2026-10-18'));ids.append(row.lastrowid)
                    if tg:records.execute("INSERT INTO telegram_deliveries(post_id,channel,message_id,state,updated_at) VALUES(?,'-100100',?,'sent',datetime('now'))",(row.lastrowid,1000+row.lastrowid))
            def list_ids(scope):
                panel=request('/admin.php?scope='+scope)[1]
                return {int(n) for n in re.findall(r'<tr id="post-(\d+)"',panel)},panel
            for scope,indices in [('',[0,1,2,3]),('site',[1,3]),('telegram',[2,3]),('both',[3]),('local',[0])]:
                found,panel=list_ids(scope);assert found.intersection(ids)=={ids[n] for n in indices},('placement filter',scope)
                assert 'Удалить пост полностью' in panel and 'data-confirm=' in panel,'management uses confirmation controls'
            manage={'action':'post_manage','id':ids[0],'revision':1,'command':'site_publish','csrf':csrf,'list_scope':'site'}
            assert request('/admin.php',{**manage,'csrf':'bad'})[0]==403,'list management requires CSRF'
            assert 'Действие выполнено' in request('/admin.php',manage)[1],'list publication succeeds'
            assert 'Пост изменён' in request('/admin.php',{**manage,'command':'post_delete'})[1],'stale list cannot delete changed post'
            request('/admin.php',{**manage,'revision':2,'command':'site_hide'})
            with sqlite3.connect(storage/'blog.sqlite') as records:assert records.execute('SELECT status FROM posts WHERE id=?',[ids[0]]).fetchone()[0]=='draft','hiding returns to system without deleting'
            request('/admin.php',{**manage,'revision':3,'command':'post_delete','list_scope':'local'})
            with sqlite3.connect(storage/'blog.sqlite') as records:assert records.execute('SELECT 1 FROM posts WHERE id=?',[ids[0]]).fetchone() is None,'full deletion removes unposted system record'
            assert list((storage/'private-db-copies').glob('*.sqlite')),'full deletion has verified recovery snapshot'
            footer_save={'action':'autosave','id':ids[1],'revision':1,'title':'site-hub','body':'Текст','telegram_footer':'off','csrf':csrf}
            code,result,_=request('/admin.php',footer_save);assert code==200,result
            saved=json.loads(result)
            editor=request('/admin.php?view=edit&id='+str(ids[1]))[1]
            assert '<option value="off" selected>' in editor,'footer choice survives reload'
            footer_save.pop('telegram_footer');footer_save['revision']=saved['revision']
            request('/admin.php',footer_save)
            with sqlite3.connect(storage/'blog.sqlite') as records:assert records.execute('SELECT footer_mode FROM post_telegram_options WHERE post_id=?',[ids[1]]).fetchone()[0]=='off','older clients preserve stored footer choice'

            # SEO public endpoints, private exclusions and canonical behavior.
            with sqlite3.connect(storage/'blog.sqlite') as records:records.execute("UPDATE settings SET value='1' WHERE key='indexed'")
            assert 'noindex' not in request('/')[1],'public HTML has no indexing prohibition after launch'
            code,robots,_=request('/robots.php',opener=anonymous);assert code==200 and 'Sitemap: https://example.test/sitemap.xml' in robots and 'Disallow: /admin.php' in robots,'crawler rules expose sitemap and exclude admin'
            code,sitemap,_=request('/sitemap.php',opener=anonymous);assert code==200 and 'site-hub' in sitemap and 'tg-hub' not in sitemap,'sitemap contains website posts but no Telegram-only drafts'
            code,article,_=request('/?p=site-hub',opener=anonymous);assert code==200
            assert 'href="https://example.test/?p=site-hub"' in article and 'application/ld+json' in article and 'BlogPosting' in article,'article canonical and structured data'
            assert '<meta name="description" content="Текст">' in article,'article has content-derived description'
            with sqlite3.connect(storage/'blog.sqlite') as records:
                for n in range(11):records.execute("INSERT INTO posts(slug,title,body,status,published_at,created_at,updated_at) VALUES(?,?,?,'published',?,?,?)",('seo-page-'+str(n),'История '+str(n),'Текст истории','2026-10-18','2026-10-18','2026-10-18'))
            code,page_two,_=request('/?page=2&utm_source=test',opener=anonymous);assert code==200 and '<link rel="canonical" href="https://example.test/?page=2">' in page_two and 'страница 2' in page_two,'real second page has its own HTTPS canonical and title'
            with sqlite3.connect(storage/'blog.sqlite') as records:settings_values=dict(records.execute('SELECT key,value FROM settings'))
            verification={key:settings_values.get(key,'') for key in ['title','description','telegram_url','support_url','support_text']}
            verification.update({'action':'settings','csrf':csrf,'indexed':'1','google_verification':'<meta name="google-site-verification" content="google_test-code" />','yandex_verification':'abc123'})
            assert request('/admin.php',verification)[0]==200,'verification codes save through authenticated CSRF form'
            verified=request('/',opener=anonymous)[1]
            head=re.search(r'<head>(.*?)</head>',verified,re.S)
            assert head and '<meta name="google-site-verification" content="google_test-code">' in head[1] and '<meta name="yandex-verification" content="abc123">' in head[1],'verification must be inside explicit document head'
            assert verified.index('</head>') < verified.index('<body'),'head is closed before reader content'
            assert '<meta name="google-site-verification" content="google_test-code">' in verified and '<meta name="yandex-verification" content="abc123">' in verified,'public head contains validated webmaster tokens'
            code,missing,_=request('/?page=999',opener=anonymous);assert code==404,'empty pagination page is a true 404'
            with sqlite3.connect(storage/'blog.sqlite') as records:records.execute("UPDATE settings SET value='0' WHERE key='indexed'")
            assert request('/sitemap.php',opener=anonymous)[0]==404 and 'Disallow: /\n' in request('/robots.php',opener=anonymous)[1],'manual closure hides sitemap and disallows crawling'
            with anonymous.open(base+'/') as response:assert 'noindex' in response.headers.get('X-Robots-Tag',''),'manual closure sends noindex HTTP header'
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self,*args,**kwargs):return None
            no_redirect=urllib.request.build_opener(NoRedirect)
            code,_,_=request('/?p=site-hub',opener=no_redirect,headers={'Host':'example.test'})
            assert code==301,'HTTP on configured host redirects to HTTPS before rendering'
            print('OK: HTTP login, CSRF, drafts, upload, private media, publishing, XSS, conflicts, backup')
        finally:
            server.terminate();server.wait(timeout=5)
