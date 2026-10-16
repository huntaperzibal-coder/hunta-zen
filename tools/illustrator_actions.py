"""Drain the author's private SSH queue. Never log packets, keys, text or images."""
import json, os, pathlib, re, subprocess, sys, tempfile, time
ROOT = pathlib.Path(__file__).resolve().parents[1]

class Bridge:
    def __init__(self, folder):
        target = os.environ.get('HUNTA_SSH_TARGET', '')
        if not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]*@[A-Za-z0-9][A-Za-z0-9.-]*', target):
            raise RuntimeError('Set HUNTA_SSH_TARGET in repository Variables (user@host).')
        key = os.environ.get('HUNTA_SSH_KEY', '').strip()
        hosts = os.environ.get('HUNTA_SSH_KNOWN_HOSTS', '').strip()
        if not key or not hosts:
            raise RuntimeError('Add HUNTA_SSH_KEY and HUNTA_SSH_KNOWN_HOSTS in repository Secrets.')
        for name, value in [('key', key), ('hosts', hosts)]:
            path = folder/name; path.write_text(value+'\n'); path.chmod(0o600)
        self.command = ['ssh', '-T', '-i', str(folder/'key'), '-o', 'IdentitiesOnly=yes',
                        '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                        '-o', 'UserKnownHostsFile='+str(folder/'hosts'), '-o', 'ConnectTimeout=15',
                        '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2', target, 'illustrator']

    def call(self, packet):
        try:
            result = subprocess.run(self.command, input=json.dumps(packet).encode(), stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, timeout=60, check=False)
            data = json.loads(result.stdout)
        except (ValueError, OSError, subprocess.TimeoutExpired):
            raise RuntimeError('SSH connection failed. Check the target, dedicated key and pinned host keys.') from None
        if result.returncode or not data.get('ok'):
            raise RuntimeError('SSH bridge rejected the operation. Check installation and database migration.')
        return data

def run_packet(packet, folder, progress=None, command=None):
    storage = folder/'work'; storage.mkdir(mode=0o700)
    config = storage/'config.php'
    literal = json.dumps({'storage':str(storage), 'site_url':'https://example.invalid',
                          'telegram_token':'', 'telegram_channel':''})
    config.write_text("<?php return json_decode('"+literal.replace('\\','\\\\').replace("'","\\'")+"',true);")
    config.chmod(0o600)
    env = {**os.environ, 'HUNTA_CONFIG':str(config)}
    with tempfile.TemporaryFile(dir=folder) as output:
        worker = subprocess.Popen(command or ['php', '-d', 'memory_limit=512M', str(ROOT/'tools/illustrator_actions.php')],
                                  stdin=subprocess.PIPE, stdout=output, stderr=subprocess.DEVNULL, env=env)
        try:
            worker.stdin.write(json.dumps(packet).encode()); worker.stdin.close()
            notified = False; start = time.monotonic()
            while worker.poll() is None:
                marker = storage/'progress.json'
                if not notified and marker.is_file() and progress:
                    try:
                        progress(json.loads(marker.read_text())); notified = True
                    except (ValueError, RuntimeError):
                        pass  # Final result can still be delivered; never repeat an AI call.
                if time.monotonic()-start > 540:
                    worker.kill(); worker.wait()
                    raise RuntimeError('Worker timed out. The provider result is uncertain; no automatic retry.')
                time.sleep(1)
            output.seek(0)
            if worker.returncode:
                raise RuntimeError('Image worker stopped unexpectedly. No automatic retry; inspect the provider history.')
            result = json.load(output)
        finally:
            if worker.poll() is None: worker.kill(); worker.wait()
    if result.get('state') not in ('done', 'failed'):
        raise RuntimeError('Invalid worker result. No automatic retry.')
    return result

def main():
    with tempfile.TemporaryDirectory(prefix='hunta-illustrator-') as temp:
        folder = pathlib.Path(temp); folder.chmod(0o700)
        bridge = Bridge(folder)
        started = time.monotonic(); completed = 0
        while completed < 10 and time.monotonic()-started < 18*60:
            packet = bridge.call({'op':'claim'})['job']
            if packet is None:
                print('Queue is empty.'); return
            base = {'id':packet['id'], 'lease':packet['lease']}
            # Mask before any tool can accidentally include this credential in diagnostics.
            api_key = packet['api_key']
            if re.fullmatch(r'[A-Za-z0-9_-]+', api_key): print('::add-mask::'+api_key, flush=True)
            print('Processing illustration', int(packet['id']), flush=True)
            with tempfile.TemporaryDirectory(dir=folder, prefix='job-') as work:
                try:
                    result = run_packet(packet, pathlib.Path(work),
                                        lambda update: bridge.call({**base, 'op':'progress', **update}))
                except (RuntimeError, OSError, ValueError):
                    # A crash may have reached a paid provider: report uncertainty, never rerun.
                    result = {'state':'failed', 'plan':[], 'cost':0,
                              'error':'Обработчик Actions прервался. Результат запроса неизвестен. Проверь OpenRouter перед новым вариантом.'}
                finish = {**base, 'op':'finish', **result}
                # Delivery retries are safe: the server accepts the same completed lease once.
                for attempt in range(3):
                    try: bridge.call(finish); break
                    except RuntimeError:
                        if attempt == 2: raise
                print('Result delivered to the author dashboard.', flush=True)
            completed += 1
        print('Batch limit reached; remaining queued tasks can be dispatched again.')

if __name__ == '__main__':
    try: main()
    except (RuntimeError, OSError, ValueError) as error:
        # Only our fixed infrastructure messages, never raw provider/SSH packets.
        print('Illustrator runner could not complete; check SSH settings and the author dashboard.', file=sys.stderr)
        sys.exit(1)
