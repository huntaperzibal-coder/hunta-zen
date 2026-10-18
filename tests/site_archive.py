"""Full archive and the exact copied terminal command, entirely offline."""
import gzip,hashlib,html,io,json,os,pathlib,re,sqlite3,subprocess,tarfile,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='zen-site-test-') as tmp:
    folder=pathlib.Path(tmp);site=folder/'site';storage=site/'storage';copies=folder/'server-copies'
    for path in [site/'public',site/'.git',storage/'photos',storage/'backups/old',storage/'references']:
        path.mkdir(parents=True,exist_ok=True)
    (site/'public/index.php').write_text('<?php echo "Site";');(site/'.git/history').write_text('history excluded')
    (storage/'photos/photo.png').write_bytes(b'photo-data');(storage/'photos/video.mp4').write_bytes(b'video-data')
    (storage/'references/hero.png').write_bytes(b'reference-data');(storage/'backups/old/blog.sqlite').write_bytes(b'old-backup')
    (storage/'app.log').write_text('private log excluded');(storage/'illustrator-key').write_text('test-key')
    config={'storage':str(storage),'site_url':'https://example.test','database_backup_dir':str(copies)}
    (storage/'config.php').write_text('<?php return json_decode('+repr(json.dumps(config))+',true);')
    env={**os.environ,'HUNTA_CONFIG':str(storage/'config.php')}
    subprocess.run([PHP,str(ROOT/'tools/migrate.php')],env=env,check=True,capture_output=True)
    options={'root':str(site),'storage':str(storage),'config':str(storage/'config.php'),'directory':str(copies),'download':True}
    def archive_bytes():return subprocess.check_output(['python3',str(ROOT/'tools/site_archive.py')],input=json.dumps(options).encode())
    binary=archive_bytes()
    with tarfile.open(fileobj=io.BytesIO(binary),mode='r:gz') as archive:
        names=archive.getnames();manifest=json.load(archive.extractfile('backup-manifest.json'))
        required=['site/public/index.php','data/photos/photo.png','data/photos/video.mp4','data/references/hero.png','data/illustrator-key','configuration/config.php','data/blog.sqlite','RESTORE.txt']
        assert all(name in names for name in required),names
        assert not any('.git/' in name or '/backups/' in name or name.endswith('.log') for name in names)
        for name,item in manifest['files'].items():
            data=archive.extractfile(name).read();assert len(data)==item['size'] and hashlib.sha256(data).hexdigest()==item['sha256']
    options['download']=False
    result=subprocess.check_output(['python3',str(ROOT/'tools/site_archive.py')],input=json.dumps(options).encode())
    saved=list(copies.glob('*.tar.gz'));assert len(saved)==1 and b'zen_site_' in result
    assert (saved[0].stat().st_mode&0o777)==0o600 and not list(copies.glob('*.part'))
    # PHP launcher: creates an archive containing the actual working source and fixture data.
    launched=subprocess.check_output([PHP,str(ROOT/'tools/backup_site.php'),'--download'],env=env)
    with tarfile.open(fileobj=io.BytesIO(launched),mode='r:gz') as archive:assert 'data/photos/video.mp4' in archive.getnames()
    page=subprocess.check_output([PHP,'-r',f"require '{ROOT}/app/bootstrap.php'; github_page();"],env=env,text=True)
    commands=[html.unescape(s) for s in re.findall(r'<pre[^>]*><code>(.*?)</code></pre>',page,re.S)]
    command=next(s for s in commands if 'zen_archive_part=' in s).replace('$HOME/hunta-zen-backups',str(folder/'computer'))
    fixture=folder/'fixture.tar.gz';fixture.write_bytes(binary)
    ssh=folder/'ssh';ssh.write_text('#!/bin/sh\ncat "$ZEN_TEST_ARCHIVE"\nexit "${ZEN_TEST_SSH_EXIT:-0}"\n');ssh.chmod(0o700)
    env.update(PATH=str(folder)+os.pathsep+os.environ['PATH'],ZEN_TEST_ARCHIVE=str(fixture))
    def run_command(extra=None):return subprocess.run(['bash','-c',command],env={**env,**(extra or {})},capture_output=True,text=True)
    result=run_command();assert result.returncode==0,result.stderr
    dest=folder/'computer';downloaded=list(dest.glob('*.tar.gz'));assert len(downloaded)==1 and downloaded[0].read_bytes()==binary
    before=set(dest.iterdir());fixture.write_bytes(binary[:-15]);assert run_command().returncode!=0 and set(dest.iterdir())==before,'truncated gzip discarded'
    fixture.write_bytes(binary);assert run_command({'ZEN_TEST_SSH_EXIT':'1'}).returncode!=0 and set(dest.iterdir())==before,'interrupted transfer discarded'
    # Preserve tar/gzip validity but change media bytes: manifest must catch corruption.
    tampered=io.BytesIO()
    with tarfile.open(fileobj=io.BytesIO(binary),mode='r:gz') as source,tarfile.open(fileobj=tampered,mode='w:gz') as target:
        for info in source:
            data=source.extractfile(info).read() if info.isfile() else None
            if info.name=='data/photos/video.mp4':data=b'wrong-data';info.size=len(data)
            target.addfile(info,io.BytesIO(data) if data is not None else None)
    fixture.write_bytes(tampered.getvalue());assert run_command().returncode!=0 and set(dest.iterdir())==before,'bad media checksum discarded'
print('OK: full-site source, SQLite, photos, video, references and settings archived; copied download checks gzip, hashes and database; failed transfers keep old archives')
