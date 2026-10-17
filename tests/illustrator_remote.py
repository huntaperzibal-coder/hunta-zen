"""Real CLI bridge + Actions runner, with an offline provider. No SSH/network/real credentials."""
import importlib.util, json, os, pathlib, sqlite3, subprocess, tempfile
from unittest import mock
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
spec=importlib.util.spec_from_file_location('actions',ROOT/'tools/illustrator_actions.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix='hunta-remote-') as temp:
    site=pathlib.Path(temp)/'site';site.mkdir(mode=0o700)
    config=site/'config.php'
    config.write_text('<?php return json_decode('+repr(json.dumps({'storage':str(site),'site_url':'https://example.test','telegram_token':'','telegram_channel':''}))+',true);')
    env={**os.environ,'HUNTA_CONFIG':str(config)}
    code='''require $argv[1].'/app/bootstrap.php';
$db->exec(file_get_contents(ROOT.'/database.sql'));
$db->exec("INSERT INTO settings(key,value) VALUES('illustrator_backend','actions')");
file_put_contents($storage.'/openrouter-key.php',"<?php return 'local-test-secret';");
$db->prepare('INSERT INTO posts(title,body,slug,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['Тихий вечер','Гена и Винни у костра.','fire',now(),now()]);
$jobId=illustration_enqueue(post((int)$db->lastInsertId()),'Тёплый свет');
$snapshot=json_decode($db->query('SELECT snapshot FROM illustration_jobs WHERE id='.$jobId)->fetchColumn(),true);
$snapshot['cycle_memory']=[['post_id'=>42,'title'=>'Ранее в цикле','thought'=>'Не путать уверенность и знание','image'=>'Открытая дверь в стене','style'=>'Импрессионизм']];
$db->prepare('UPDATE illustration_jobs SET snapshot=? WHERE id=?')->execute([json_encode($snapshot),$jobId]);'''
    subprocess.run([PHP,'-r',code,str(ROOT)],env=env,check=True,capture_output=True)
    bridge=object.__new__(module.Bridge);bridge.command=[PHP,str(ROOT/'tools/illustrator_bridge.php')]
    with mock.patch.dict(os.environ,env):
        packet=bridge.call({'op':'claim'})['job']
        assert packet['snapshot']['text']=='Гена и Винни у костра.' and packet['api_key']=='local-test-secret'
        assert packet['snapshot']['cycle_memory'][0]['post_id']==42,'SSH claim preserves frozen cycle cards'
        assert bridge.call({'op':'claim'})['job'] is None,'one lease only'
        base={'id':packet['id'],'lease':packet['lease']}
        try:bridge.call({'op':'finish','id':packet['id'],'lease':'wrong','state':'done'});raise AssertionError('wrong lease accepted')
        except RuntimeError:pass
        work=pathlib.Path(temp)/'runner';work.mkdir(mode=0o700)
        result=module.run_packet(packet,work,lambda progress:bridge.call({**base,'op':'progress',**progress}),[PHP,str(ROOT/'tests/actions_fixture.php')])
        assert result['state']=='done' and abs(result['cost']-.041)<.000001
        assert result['plan']['art_style']['id']=='impressionism','Actions preserves the automatic style choice'
        finish={**base,'op':'finish',**result}
        assert bridge.call(finish)['ok']
        assert bridge.call(finish)['already_saved'],'delivery idempotent'
        files=list((site/'photos').iterdir());assert len(files)==1,'duplicate delivery did not store twice'
        db=sqlite3.connect(site/'blog.sqlite')
        assert db.execute('SELECT body,cover,revision FROM posts').fetchone()==('Гена и Винни у костра.','',1),'remote result did not change author text'
        state,image=db.execute('SELECT state,image FROM illustration_jobs').fetchone()
        assert state=='done' and image==files[0].name
        assert db.execute('SELECT token_hash FROM illustration_remote_leases').fetchone()[0]!=packet['lease'],'lease secret hashed'
        db.execute("INSERT INTO posts(title,body,slug,created_at,updated_at) VALUES('draft','text','draft',datetime('now'),datetime('now'))")
        db.execute("INSERT INTO illustration_jobs(post_id,revision,snapshot,created_at,updated_at) SELECT 2,1,snapshot,created_at,updated_at FROM illustration_jobs LIMIT 1")
        db.commit()
        stale=bridge.call({'op':'claim'})['job']
        db.execute("UPDATE illustration_jobs SET updated_at='2020-01-01T00:00:00+00:00' WHERE id=?",[stale['id']]);db.commit()
        assert bridge.call({'op':'claim'})['job'] is None
        assert db.execute('SELECT state FROM illustration_jobs WHERE id=?',[stale['id']]).fetchone()[0]=='unknown','stale lease not replayed'
        db.execute("INSERT OR REPLACE INTO settings(key,value) VALUES('illustrator_probe_state','queued')");db.commit()
        probe=bridge.call({'op':'probe_claim'})['probe']
        assert probe['neutral_test'] and probe['api_key']=='local-test-secret'
        assert bridge.call({'op':'probe_claim'})['probe'] is None,'diagnostic cannot be claimed twice'
        try:bridge.call({'op':'probe_finish','lease':'wrong','result':{'state':'done'}});raise AssertionError('wrong probe lease accepted')
        except RuntimeError:pass
        assert bridge.call({'op':'probe_finish','lease':probe['lease'],'result':{'state':'failed','error':'test local-test-secret','cost':0}})['ok']
        saved=json.loads(db.execute("SELECT value FROM settings WHERE key='illustrator_probe_result'").fetchone()[0])
        assert saved['state']=='failed' and 'local-test-secret' not in saved['error']
        db.execute("INSERT OR REPLACE INTO settings(key,value) VALUES('illustrator_probe_state','queued')");db.commit()
        probe=bridge.call({'op':'probe_claim'})['probe']
        delivery={'op':'probe_finish','lease':probe['lease'],'result':result}
        assert bridge.call(delivery)['ok']
        saved=json.loads(db.execute("SELECT value FROM settings WHERE key='illustrator_probe_result'").fetchone()[0])
        assert saved['state']=='done' and (site/'photos'/saved['image']).is_file(),'diagnostic image delivered'
        assert bridge.call(delivery)['already_saved'],'diagnostic delivery idempotent'
        # Retired proofreading tasks are closed without a provider request.
        db.execute("INSERT INTO illustration_jobs(post_id,revision,snapshot,created_at,updated_at) VALUES(1,1,?,datetime('now'),datetime('now'))",['{"mode":"proofread","segments":["Old task"]}'])
        old_proof=db.execute('SELECT max(id) FROM illustration_jobs').fetchone()[0];db.commit()
        assert bridge.call({'op':'claim'})['job'] is None
        assert db.execute('SELECT state FROM illustration_jobs WHERE id=?',[old_proof]).fetchone()[0]=='failed'
        db.close()
print('OK: private snapshot, real offline worker, delivery idempotency, lease authentication, unchanged author text, stale task protection')
