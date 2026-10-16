"""Install the restricted key in an isolated SSH directory, never the real account."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=subprocess.check_output(['which',os.environ.get('PHP_BIN','php')],text=True).strip()
with tempfile.TemporaryDirectory(prefix='hunta-actions-install-') as temp:
    folder=pathlib.Path(temp);storage=folder/'storage';storage.mkdir()
    config=storage/'config.php';config.write_text('<?php return json_decode('+repr(json.dumps({'storage':str(storage),'site_url':'https://example.test','telegram_token':'','telegram_channel':''}))+',true);')
    ssh=folder/'ssh';ssh.mkdir();authorized=ssh/'authorized_keys';original='# existing key retained';authorized.write_text(original)
    (storage/'illustrator-actions-known-hosts').write_text('example.test ssh-ed25519 AAAATEST\n')
    # Emulate Beget: copying content works, preserving source metadata is denied.
    fake_bin=folder/'bin';fake_bin.mkdir()
    real_cp=shutil.which('cp')
    wrapper=fake_bin/'cp'
    wrapper.write_text("#!/usr/bin/python3\nimport os,sys\nif '-p' in sys.argv[1:]:\n print('cp: preserving permissions: Operation not permitted',file=sys.stderr);sys.exit(1)\nos.execv("+repr(real_cp)+",["+repr(real_cp)+"]+sys.argv[1:])\n")
    wrapper.chmod(0o700)
    env={**os.environ,'PATH':str(fake_bin)+':'+os.environ['PATH'],'PHP_BIN':PHP,'HUNTA_CONFIG':str(config),'HUNTA_SSH_DIRECTORY':str(ssh),'HUNTA_SSH_HOST':'example.test','HUNTA_SSH_USER':'author'}
    subprocess.run([PHP,str(ROOT/'tools/migrate.php')],env=env,check=True,capture_output=True)
    for _ in range(2):
        result=subprocess.run(['bash',str(ROOT/'tools/install_illustrator_actions.sh')],env=env,capture_output=True,text=True)
        assert result.returncode==0,result.stderr
    text=authorized.read_text()
    assert text.startswith(original+'\nrestrict,') and text.count('restrict,command=')==1
    subprocess.run(['ssh-keygen','-lf',str(authorized)],check=True,capture_output=True)
    assert 'illustrator_bridge.php' in text and 'HUNTA_CONFIG=' in text
    assert (storage/'illustrator-actions-key').stat().st_mode & 0o777==0o600
    backups=list(ssh.glob('authorized_keys.before-*'));assert len(backups)==1
    assert backups[0].read_text()==original and backups[0].stat().st_mode & 0o777==0o600
    assert 'BEGIN OPENSSH PRIVATE KEY' not in result.stdout
    assert len(list((storage/'backups').iterdir()))==2
print('OK: isolated restricted SSH key, preserved authorized_keys, idempotent install, private key permissions, no metadata preservation, missing trailing newline, database backups')
