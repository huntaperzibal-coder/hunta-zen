"""Exercise both installers with a fake crontab; never alter the system cron."""
import json, os, pathlib, subprocess, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = pathlib.Path(subprocess.check_output(['which', os.environ.get('PHP_BIN', 'php')], text=True).strip())
with tempfile.TemporaryDirectory(prefix='hunta-cron-') as tmp:
    folder = pathlib.Path(tmp)
    config = folder/'config.php'
    config.write_text('<?php return json_decode('+repr(json.dumps({'storage':tmp,'site_url':'https://example.test'}))+',true);')
    state = folder/'crontab.txt'
    original = '7 3 * * * existing-backup\n'
    state.write_text(original)
    fake = folder/'crontab'
    fake.write_text('''#!/usr/bin/python3
import os, pathlib, sys
state = pathlib.Path(os.environ['FAKE_CRON_STATE'])
if os.environ.get('FAKE_CRON_DENY'):
    print('crontab: permission denied', file=sys.stderr)
    sys.exit(1)
if sys.argv[1] == '-l':
    print(state.read_text(), end='')
else:
    state.write_text(pathlib.Path(sys.argv[1]).read_text())
''')
    fake.chmod(0o700)
    env = {**os.environ, 'HUNTA_CONFIG':str(config), 'PHP_BIN':str(PHP),
           'PATH':str(folder)+':'+os.environ['PATH'], 'FAKE_CRON_STATE':str(state)}
    old = subprocess.run([str(PHP), str(ROOT/'tools/install_illustrator.php')], env=env, capture_output=True, text=True)
    assert old.returncode == 0, old.stderr
    for _ in range(2):
        result = subprocess.run(['bash',str(ROOT/'tools/install_illustrator.sh')], env=env, capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
    saved = state.read_text()
    assert saved.count('hunta-zen-illustrator-') == 1 and saved.startswith(original), saved
    denied = subprocess.run(['bash',str(ROOT/'tools/install_illustrator.sh')], env={**env,'FAKE_CRON_DENY':'1'}, capture_output=True, text=True)
    assert denied.returncode == 1 and 'permission denied' in denied.stderr and state.read_text() == saved
    disabled = subprocess.run([str(PHP),'-d','disable_functions=exec',str(ROOT/'tools/install_illustrator.php')], env=env, capture_output=True, text=True)
    assert disabled.returncode == 1 and 'install_illustrator.sh' in disabled.stderr, disabled.stderr
print('OK: PHP/Bash installers share one cron entry, preserve other jobs, report permission errors and disabled PHP exec')
