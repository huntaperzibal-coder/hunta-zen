"""Installer reuses an existing hash, preserves blog and cases on repeat, serves from isolated root."""
import pathlib, tempfile, subprocess, sqlite3, os, json, shutil
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='desk-install-') as tmp:
    tmp=pathlib.Path(tmp); repo=tmp/'repo';repo.mkdir();(repo/'storage').mkdir();(repo/'tools').mkdir()
    shutil.copytree(root/'photo-desk',repo/'photo-desk')
    for name in ('photo_desk_install.php','photo_desk_snapshot.php','author_source.php'):shutil.copy(root/'tools'/name,repo/'tools'/name)
    blog=tmp/'blog';blog.mkdir();public=tmp/'public_html';public.mkdir();(public/'index.php').write_text('old placeholder');(public/'index.html').write_text('old html')
    original_hash=subprocess.check_output(['php','-r','echo password_hash("same-password",PASSWORD_DEFAULT);']).decode()
    db=sqlite3.connect(blog/'blog.sqlite');db.execute('create table orda_users(login text,password_hash text)');db.execute('insert into orda_users values(?,?)',('Orda',original_hash));db.commit();db.close()
    (repo/'storage/config.php').write_text('<?php return ["storage"=>'+repr(str(blog))+'];')
    before=(blog/'blog.sqlite').read_bytes()
    cmd=['php',str(repo/'tools/photo_desk_install.php'),'--public='+str(public)]
    subprocess.run(cmd,check=True,stdout=subprocess.PIPE)
    desk=repo/'storage/photo-desk';db=sqlite3.connect(desk/'desk.sqlite')
    assert db.execute('select login,password_hash,role from users').fetchone()==('Orda',original_hash,'admin')
    db.execute("insert into cases(title,created_at,updated_at) values('keep','now','now')");db.commit();db.close()
    subprocess.run(cmd,check=True,stdout=subprocess.PIPE)
    db=sqlite3.connect(desk/'desk.sqlite');assert db.execute('select title from cases').fetchone()[0]=='keep';db.close()
    assert (blog/'blog.sqlite').read_bytes()==before
    assert not (public/'index.html').exists()
    assert (public/'index.html.before-photo-desk').read_text()=='old html'
    assert 'photo-desk/public/index.php' in (public/'index.php').read_text()
    snapshots=list((desk/'backups').glob('*/desk.sqlite'));assert len(snapshots)==1
    backup=sqlite3.connect(snapshots[0]);assert backup.execute('pragma integrity_check').fetchone()[0]=='ok'
    assert any(p.read_text()=='old placeholder' for p in (desk/'backups').glob('index.php.*'))
    print('Photo desk install checks passed')
