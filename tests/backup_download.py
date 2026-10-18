"""Execute the exact command shown in the GitHub hub, with offline SSH fixtures."""
import html,json,os,pathlib,re,sqlite3,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='zen-download-') as tmp:
    folder=pathlib.Path(tmp);storage=folder/'storage';storage.mkdir();dest=folder/'computer-copies'
    config={'storage':str(storage),'site_url':'https://example.test','database_backup_dir':str(folder/'server-copies')}
    (storage/'config.php').write_text('<?php return json_decode('+repr(json.dumps(config))+',true);')
    env={**os.environ,'HUNTA_CONFIG':str(storage/'config.php')}
    subprocess.run([PHP,str(ROOT/'tools/migrate.php')],env=env,check=True,capture_output=True)
    page=subprocess.check_output([PHP,'-r',f"require '{ROOT}/app/bootstrap.php'; github_page();"],env=env,text=True)
    commands=[html.unescape(s) for s in re.findall(r'<pre[^>]*><code>(.*?)</code></pre>',page,re.S)]
    command=next(s for s in commands if 'zen_backup_part=' in s).replace('$HOME/hunta-zen-backups',str(dest))
    assert '--download' in command and 'set -eu' in command
    binary=subprocess.check_output([PHP,str(ROOT/'tools/backup_database.php'),'--download'],env=env)
    fixture=folder/'fixture.sqlite';fixture.write_bytes(binary)
    ssh=folder/'ssh';ssh.write_text('#!/bin/sh\ncat "$ZEN_TEST_SQLITE"\nexit "${ZEN_TEST_SSH_EXIT:-0}"\n');ssh.chmod(0o700)
    env.update(PATH=str(folder)+os.pathsep+os.environ['PATH'],ZEN_TEST_SQLITE=str(fixture))
    result=subprocess.run(['bash','-c',command],env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stderr
    copies=list(dest.glob('*.sqlite'));assert len(copies)==1 and copies[0].read_bytes()==binary
    with sqlite3.connect(copies[0]) as db:assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    before=set(dest.iterdir())
    result=subprocess.run(['bash','-c',command],env={**env,'ZEN_TEST_SSH_EXIT':'1'},capture_output=True,text=True)
    assert result.returncode!=0 and set(dest.iterdir())==before,'interrupted transfer leaves no final or partial backup'
    fixture.write_bytes(b'broken download')
    result=subprocess.run(['bash','-c',command],env=env,capture_output=True,text=True)
    assert result.returncode!=0 and set(dest.iterdir())==before,'invalid SQLite is never kept as a successful download'
print('OK: copied terminal command downloads verified SQLite; interrupted or corrupt transfers keep existing backups intact')
