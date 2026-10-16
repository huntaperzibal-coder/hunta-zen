#!/usr/bin/env bash
# Cron setup through SSH, including hosts where PHP exec() is unavailable.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
php_bin=${PHP_BIN:-/usr/local/bin/php8.2}
config_path=${HUNTA_CONFIG:-$root/storage/config.php}
if [[ ! -x "$php_bin" || ! -f "$config_path" ]]; then
    echo 'Не найден PHP или конфиг сайта. Запусти из SSH с PHP_BIN=/usr/local/bin/php8.2.' >&2
    exit 1
fi
config_path=$(cd -- "$(dirname -- "$config_path")" && pwd -P)/$(basename -- "$config_path")
if ! command -v crontab >/dev/null 2>&1; then
    echo 'На хостинге нет команды crontab. Добавь в панели Beget задачу раз в минуту:' >&2
    printf '%q %q\n' "$php_bin" "$root/tools/illustrator_worker.php" >&2
    exit 1
fi
# Resolve storage without shell evaluation or printing config secrets.
storage=$(HUNTA_CONFIG="$config_path" "$php_bin" -r '$c=require getenv("HUNTA_CONFIG");echo $c["storage"];')
[[ -d "$storage" && -w "$storage" ]] || { echo 'Папка storage недоступна для записи.' >&2; exit 1; }
for path in "$root" "$php_bin" "$config_path" "$storage"; do
    [[ "$path" != *$'\n'* && "$path" != *$'\r'* ]] || { echo 'Перенос строки в пути cron не поддерживается.' >&2; exit 1; }
done
work=$(mktemp -d "$storage/cron-install-XXXXXXXX")
chmod 700 "$work"
trap 'rm -f -- "$work/current" "$work/error" "$work/next"; rmdir -- "$work"' EXIT
if ! LC_ALL=C crontab -l >"$work/current" 2>"$work/error"; then
    if ! LC_ALL=C grep -qi 'no crontab' "$work/error"; then
        echo 'Не удалось прочитать существующий cron. Задания не изменены:' >&2
        cat "$work/error" >&2
        echo 'Добавь обработчик через планировщик в панели Beget:' >&2
        printf '%q %q\n' "$php_bin" "$root/tools/illustrator_worker.php" >&2
        exit 1
    fi
    : >"$work/current"
fi
tag_hash=$("$php_bin" -r 'echo substr(hash("sha256",$argv[1]),0,12);' "$root")
tag="# hunta-zen-illustrator-$tag_hash"
# Match only this installation's shell installer entry. Preserve every other line.
while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" == *" $tag" ]] || printf '%s\n' "$line"
done <"$work/current" >"$work/next"
printf -v command 'env HUNTA_CONFIG=%q %q %q >> %q 2>&1' "$config_path" "$php_bin" "$root/tools/illustrator_worker.php" "$storage/illustrator-cron.log"
command=${command//%/\\%}
printf '* * * * * %s %s\n' "$command" "$tag" >>"$work/next"
if ! crontab "$work/next"; then
    echo 'Хостинг не принял cron. Добавь задачу через панель Beget.' >&2
    exit 1
fi
echo 'Готово: очередь иллюстраций проверяется раз в минуту. Теперь открой Дао → Иллюстратор и сохрани ключ OpenRouter.'
