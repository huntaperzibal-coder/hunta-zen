<?php
declare(strict_types=1);
function github_command_card(string $title,string $where,string $description,string $command): void {
    static $number=0;$id='github-command-'.++$number;
    echo '<section class="github-command"><div class="github-command-heading"><h2>'.h($title).'</h2><span class="github-command-place">'.h($where).'</span></div><p>'.h($description).'</p><pre id="'.$id.'"><code>'.h($command).'</code></pre><button type="button" class="btn-secondary" data-copy-command="'.$id.'">'.h($title==='Вход на сервер'?'Копировать вход':($title==='Обновление Git'?'Копировать обновление':'Скопировать команду')).'</button><span class="github-copy-status" role="status"></span></section>';
}
function github_page(): void {
    $root=(string)realpath(ROOT);$php='/usr/local/bin/php8.2';
    $cd='cd '.escapeshellarg($root).' && ';
    $ssh='ssh -p 22 altaikemp_zen@altaikemp.beget.tech';
    echo '<h1>GitHub</h1><p><a class="btn-secondary" href="https://github.com/huntaperzibal-coder/hunta-zen" target="_blank" rel="noopener">Открыть репозиторий</a></p>';
    echo '<div class="github-primary">';
    github_command_card('Вход на сервер','','',$ssh);
    github_command_card('Обновление Git','','',$cd.'git pull --ff-only');
    echo '</div>';
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
    ob_start();
    github_command_card('Состояние Git','НА СЕРВЕРЕ','Показывает локальные изменения и последние пять коммитов. Ничего не меняет.',$cd.'git status --short && git log -5 --oneline');
    github_command_card('Копия базы на сервере','НА СЕРВЕРЕ','Отдельный согласованный снимок SQLite с проверкой целостности. Каталог: '.database_backup_directory().'. Он находится вне репозитория и public_html. Старые копии сохраняются.',$cd.$php.' tools/backup_database.php');
    github_command_card('Посмотреть копии базы','НА СЕРВЕРЕ','Последние 20 снимков, их имена и размеры.',$cd.$php.' tools/backup_database.php --list');
    github_command_card('Полная копия данных на сервере','НА СЕРВЕРЕ','Существующая команда: база, фотографии и видео в storage/backups. Пароли и токены сохраняй отдельно.',$cd.$php.' tools/backup.php');
    github_command_card('Полный архив сайта на сервере','НА СЕРВЕРЕ','Код, согласованная база, фотографии, видео, остальные материалы и настройки. Сохраняется в '.database_backup_directory().'. Старые бэкапы, история Git и журналы внутрь не включаются.',$cd.$php.' tools/backup_site.php');
    github_command_card('Скачать базу на компьютер','НА КОМПЬЮТЕРЕ','Команда проверяет целостность SQLite после скачивания.',$download);
    github_command_card('Проверить планировщик Telegram','НА СЕРВЕРЕ','Состояние очереди и связь с ботом.',$cd.$php.' tools/telegram_diagnose.php');
    $maintenance = ob_get_clean();
    echo '<div class="github-backups"><section class="github-backup-card"><h2>Синхронизация с Selectel</h2><p>Отправка в Selectel из админки Дзен пока не подключена.</p><a class="btn-secondary" href="/admin.php?view=service">Резервные копии и журнал</a></section>';
    echo '<section class="github-backup-card"><h2>Резервная копия на компьютер</h2><p>Записи блога, медиа из публикаций, настройки и код сайта.</p><form method="post">'.csrf().'<button type="submit" class="btn-secondary" name="action" value="download_blog_backup">Скачать резервную копию</button></form>';
    echo '</section></div><details class="github-maintenance"><summary>Служебные команды</summary>'.$maintenance.'</details><script src="/assets/js/github-hub.js?v=2" defer></script>';
}
