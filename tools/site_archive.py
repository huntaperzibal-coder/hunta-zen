#!/usr/bin/env python3
"""Private full-site archive; SQLite is copied with the online backup API."""
import hashlib,io,json,os,pathlib,sqlite3,sys,tarfile,tempfile,time

def build_archive(options):
    root=pathlib.Path(options['root']).resolve();storage=pathlib.Path(options['storage']).resolve()
    config=pathlib.Path(options['config']).resolve();directory=pathlib.Path(options['directory']).resolve()
    if directory==root/'public' or root/'public' in directory.parents:
        raise RuntimeError('Каталог архивов должен быть вне public')
    with tempfile.TemporaryDirectory(prefix='zen-full-') as staging:
        snapshot=pathlib.Path(staging)/'blog.sqlite'
        with sqlite3.connect((storage/'blog.sqlite').as_uri()+'?mode=ro',uri=True) as source, sqlite3.connect(snapshot) as dest:
            source.backup(dest)
            if dest.execute('PRAGMA integrity_check').fetchone()[0]!='ok':raise RuntimeError('Копия SQLite не прошла проверку')
            dest.execute('SELECT count(*) FROM posts').fetchone()
        os.chmod(snapshot,0o600)
        output=None;part=None;final=None
        if not options.get('download'):
            directory.mkdir(mode=0o700,parents=True,exist_ok=True)
            fd,part=tempfile.mkstemp(prefix='.zen-site-',suffix='.part',dir=directory);os.chmod(part,0o600)
            output=os.fdopen(fd,'wb');final=directory/('zen_site_'+time.strftime('%Y-%m-%d_%H-%M-%S')+'_'+os.urandom(4).hex()+'.tar.gz')
        manifest={}
        class HashReader:
            def __init__(self,stream):self.stream=stream;self.hash=hashlib.sha256()
            def read(self,n=-1):
                data=self.stream.read(n);self.hash.update(data);return data
        try:
            with tarfile.open(fileobj=output or sys.stdout.buffer,mode='w|gz',compresslevel=1) as archive:
                def add_file(path,name):
                    info=archive.gettarinfo(str(path),arcname=name);info.uid=info.gid=0;info.uname=info.gname=''
                    if info.isfile():
                        with path.open('rb') as source:
                            reader=HashReader(source);archive.addfile(info,reader)
                            manifest[name]={'size':info.size,'sha256':reader.hash.hexdigest()}
                    elif info.isdir() or info.issym():archive.addfile(info)
                def walk(base,prefix,is_data=False):
                    for current,dirs,files in os.walk(base,followlinks=False):
                        current=pathlib.Path(current);relative=current.relative_to(base)
                        dirs[:]=[d for d in sorted(dirs) if d not in (['backups','zen_backups','image-cache','__pycache__'] if is_data else ['.git','__pycache__']) and (current/d).resolve() not in [storage,directory]]
                        for name in dirs[:]:
                            path=current/name
                            if path.is_symlink():add_file(path,str(pathlib.PurePosixPath(prefix)/relative/name));dirs.remove(name)
                        for name in sorted(files):
                            if name=='.git':continue
                            if is_data and ((relative==pathlib.Path('.') and name in ['blog.sqlite','blog.sqlite-wal','blog.sqlite-shm','blog.sqlite-journal']) or name.endswith(('.lock','.log'))):continue
                            path=current/name
                            if path.resolve()==directory or directory in path.resolve().parents:continue
                            add_file(path,str(pathlib.PurePosixPath(prefix)/relative/name))
                walk(root,'site');walk(storage,'data',True)
                add_file(snapshot,'data/blog.sqlite');add_file(config,'configuration/config.php')
                readme=('Гена и Винни — полная резервная копия\n\nsite/ — код и публичные материалы сайта без истории Git.\ndata/ — приватные данные, фото, видео, настройки и согласованный снимок blog.sqlite.\nconfiguration/config.php — исходный конфиг входа, включая вариант HUNTA_CONFIG.\nbackup-manifest.json — размеры и SHA-256 каждого обычного файла.\n\nСтарые резервные копии, журналы и файлы блокировок исключены.\nАрхив содержит пароли и токены: не публикуйте его.\n\nВосстановление: сначала распаковать в отдельный приватный каталог.\nРазместить site/ в репозитории, data/ в закрытом storage, конфиг — в storage/config.php\nили в HUNTA_CONFIG. Проверить абсолютные пути storage и остальных каталогов в настройках.\nКорень веб-сервера должен указывать только на public/.\nНастроить cron и SSH отдельно; системные настройки хостинга в архив не входят.\nНе заменять рабочую базу без отдельной копии и остановки записи.\n')
                for name,data in [('RESTORE.txt',readme.encode()),('backup-manifest.json',json.dumps({'version':1,'files':manifest},ensure_ascii=False).encode())]:
                    info=tarfile.TarInfo(name);info.size=len(data);info.mode=0o600;archive.addfile(info,io.BytesIO(data))
            if output:
                output.flush();os.fsync(output.fileno());output.close();output=None;os.rename(part,final);part=None
                print('Полный архив сайта: '+str(final))
        finally:
            if output:output.close()
            if part:pathlib.Path(part).unlink(missing_ok=True)

if __name__=='__main__':
    try:build_archive(json.load(sys.stdin))
    except Exception as error:
        print('Ошибка полного архива: '+str(error),file=sys.stderr);sys.exit(1)
