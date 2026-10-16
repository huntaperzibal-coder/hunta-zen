"""Offline integration checks for the real Git update script."""
import os,pathlib,shutil,subprocess,tempfile
SOURCE=pathlib.Path(__file__).resolve().parents[1]
def run(args,cwd,check=True,env=None):
    return subprocess.run(args,cwd=cwd,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,check=check)
def git(cwd,*args):return run(['git',*args],cwd)
def commit(repo,name,text):
    (repo/name).write_text(text);git(repo,'add',name);git(repo,'commit','-qm',text)
with tempfile.TemporaryDirectory(prefix='hunta-sync-') as tmp:
    root=pathlib.Path(tmp);remote=root/'remote.git';git(root,'init','--bare','--initial-branch=main',str(remote))
    local=root/'local';other=root/'other'
    git(root,'clone',str(remote),str(local))
    for repo in [local]:
        git(repo,'config','user.email','test@example.test');git(repo,'config','user.name','Test')
    (local/'tools').mkdir();shutil.copy(SOURCE/'tools/sync.sh',local/'tools/sync.sh')
    (local/'.gitignore').write_text('/storage/\n')
    (local/'shared').write_text('initial\n');git(local,'add','.');git(local,'commit','-qm','initial');git(local,'push','origin','main')
    git(root,'clone',str(remote),str(other));git(other,'config','user.email','test@example.test');git(other,'config','user.name','Test')
    fake=root/'php';fake.write_text('#!/bin/sh\ncase "$1" in\n tools/backup.php) echo backup >> "$SYNC_LOG"; exit "${BACKUP_FAIL:-0}";;\n tools/migrate.php) echo migrate >> "$SYNC_LOG";;\nesac\n');fake.chmod(0o700)
    (local/'storage').mkdir();(local/'storage/config.php').write_text('private');(local/'storage/note').write_text('private-content')
    env={**os.environ,'PHP_BIN':str(fake),'SYNC_LOG':str(root/'calls')}
    commit(other,'upstream','upstream');git(other,'push','origin','main')
    commit(local,'local-note','local note')
    result=run(['bash','tools/sync.sh'],local,env=env)
    assert git(local,'rev-parse','HEAD').stdout==git(remote,'rev-parse','main').stdout
    assert (local/'upstream').read_text()=='upstream' and (local/'local-note').exists()
    assert (local/'storage/note').read_text()=='private-content'
    assert (root/'calls').read_text().splitlines()==['backup','migrate']
    print('OK: diverged main rebased and pushed; private storage untouched')
    before=git(local,'rev-parse','HEAD').stdout
    (local/'shared').write_text('dirty')
    result=run(['bash','tools/sync.sh'],local,False,env);assert result.returncode!=0
    assert git(local,'rev-parse','HEAD').stdout==before and (local/'shared').read_text()=='dirty'
    git(local,'restore','shared');print('OK: uncommitted code protected')
    git(local,'switch','-c','work');assert run(['bash','tools/sync.sh'],local,False,env).returncode!=0;git(local,'switch','main');print('OK: other branch protected')
    git(other,'pull','--ff-only');commit(other,'after','after');git(other,'push','origin','main')
    assert run(['bash','tools/sync.sh'],local,False,{**env,'BACKUP_FAIL':'1'}).returncode!=0
    assert git(local,'rev-parse','HEAD').stdout==before;print('OK: failed backup blocks update')
    run(['bash','tools/sync.sh'],local,env=env)
    commit(local,'shared','local conflict');commit(other,'shared','remote conflict');git(other,'push','origin','main')
    remote_before=git(remote,'rev-parse','main').stdout
    result=run(['bash','tools/sync.sh'],local,False,env)
    assert result.returncode!=0 and git(remote,'rev-parse','main').stdout==remote_before
    assert (local/'.git/rebase-merge').is_dir();print('OK: conflict retained; no push or discarded changes')
