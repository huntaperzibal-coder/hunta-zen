<?php
declare(strict_types=1);
function github_command_card(string $title,string $where,string $description,string $command): void {
    static $number=0;$id='github-command-'.++$number;
    echo '<section class="github-command"><div class="github-command-heading"><h2>'.h($title).'</h2><span class="github-command-place">'.h($where).'</span></div><p>'.h($description).'</p><pre id="'.$id.'"><code>'.h($command).'</code></pre><button type="button" class="btn-secondary" data-copy-command="'.$id.'">Скопировать команду</button><span class="github-copy-status" role="status"></span></section>';
}
function github_page(): void {
    $root=(string)realpath(ROOT);$php='/usr/local/bin/php8.2';
    $cd='cd '.escapeshellarg($root).' && ';
    $target=setting('illustrator_ssh_target','altaikemp_zen@vortex.beget.com');
    if(!preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9.-]+$/D',$target))$target='altaikemp_zen@vortex.beget.com';
    $ssh='ssh '.escapeshellarg($target);
    echo '<h1>GitHub и резервные копии</h1><p>Код хранится в GitHub. Тексты, настройки, фотографии и видео живут на сервере: обновление Git не заменяет их резервную копию.</p><p><a href="https://github.com/huntaperzibal-coder/hunta-zen" target="_blank" rel="noopener">Репозиторий ↗</a> · <a href="https://github.com/huntaperzibal-coder/hunta-zen/actions" target="_blank" rel="noopener">GitHub Actions ↗</a></p>';
    github_command_card('Войти на сервер','НА КОМПЬЮТЕРЕ','Открой терминал своего компьютера и вставь команду. Пароль вводится в терминале; символы не показываются.',$ssh);
    github_command_card('Обновить main','НА СЕРВЕРЕ','Сначала проверенная копия базы и всех медиа, затем получение main, миграция и отправка своих коммитов.',$cd.'~/zen-sync.sh');
    github_command_card('Состояние Git','НА СЕРВЕРЕ','Показывает локальные изменения и последние пять коммитов. Ничего не меняет.',$cd.'git status --short && git log -5 --oneline');
    github_command_card('Копия базы на сервере','НА СЕРВЕРЕ','Отдельный согласованный снимок SQLite с проверкой целостности. Каталог: '.database_backup_directory().'. Он находится вне репозитория и public_html. Старые копии сохраняются.',$cd.$php.' tools/backup_database.php');
    github_command_card('Посмотреть копии базы','НА СЕРВЕРЕ','Последние 20 снимков, их имена и размеры.',$cd.$php.' tools/backup_database.php --list');
    github_command_card('Полная копия данных на сервере','НА СЕРВЕРЕ','Существующая команда: база, фотографии и видео в storage/backups. Пароли и токены сохраняй отдельно.',$cd.$php.' tools/backup.php');
    $remote=$php.' '.escapeshellarg($root.'/tools/backup_database.php').' --download';
    $download=<<<'SHELL'
(
set -eu
umask 077
zen_backup_dir="$HOME/hunta-zen-backups"
mkdir -p "$zen_backup_dir"
zen_backup_file="$zen_backup_dir/zen_$(date +%Y-%m-%d_%H-%M-%S)_$$.sqlite"
zen_backup_part="$zen_backup_file.part"
trap 'rm -f "$zen_backup_part"' EXIT
__SSH__ __REMOTE__ > "$zen_backup_part"
python3 - "$zen_backup_part" <<'PYTHON'
import sqlite3, sys
from pathlib import Path
path = Path(sys.argv[1])
if not path.is_file() or not path.stat().st_size:
    raise SystemExit('Ошибка: пустая копия')
with sqlite3.connect(path.resolve().as_uri() + '?mode=ro', uri=True) as db:
    if db.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
        raise SystemExit('Ошибка проверки SQLite')
    db.execute('SELECT count(*) FROM posts').fetchone()
print('Целостность базы проверена')
PYTHON
mv "$zen_backup_part" "$zen_backup_file"
printf 'Копия базы сохранена: %s\n' "$zen_backup_file"
)
SHELL;
    $download=str_replace(['__SSH__','__REMOTE__'],[$ssh,escapeshellarg($remote)],$download);
    github_command_card('Скачать базу на компьютер','НА КОМПЬЮТЕРЕ','Запускай в домашнем терминале, а не после входа по SSH. Команда создаёт новую проверенную копию на сервере, скачивает её в ~/hunta-zen-backups и проверяет SQLite через Python 3. Рабочая база не копируется напрямую.',$download);
    github_command_card('Проверить планировщик Telegram','НА СЕРВЕРЕ','Состояние очереди, последнего запуска и связь с ботом. Посты не отправляются.',$cd.$php.' tools/telegram_diagnose.php');
    echo '<p class="hint">Скачанная база содержит черновики, личные сообщения и настройки. Храни её у себя. Облачный Backblaze B2 здесь пока не подключён; настройки другого проекта не используются.</p><script src="/assets/js/github-hub.js?v=1" defer></script>';
}
