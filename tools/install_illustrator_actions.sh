#!/usr/bin/env bash
# Create an incoming key that can ONLY speak the illustrator JSON protocol.
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
php_bin=${PHP_BIN:-/usr/local/bin/php8.2}
config_path=${HUNTA_CONFIG:-$root/storage/config.php}
config_path=$(cd -- "$(dirname -- "$config_path")" && pwd -P)/$(basename -- "$config_path")
storage=$(HUNTA_CONFIG="$config_path" "$php_bin" -r '$c=require getenv("HUNTA_CONFIG");echo $c["storage"];')
host=${HUNTA_SSH_HOST:-vortex.beget.com}
user=${HUNTA_SSH_USER:-$(id -un)}
[[ "$host" =~ ^[A-Za-z0-9][A-Za-z0-9.-]+$ && "$user" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || { echo 'Некорректный SSH-адрес.' >&2; exit 1; }
for path in "$root" "$php_bin" "$config_path" "$storage"; do
    [[ "$path" != *$'\n'* && "$path" != *$'\r'* && "$path" != *'"'* && "$path" != *'\\'* ]] || { echo 'Неподдерживаемые символы в пути.' >&2; exit 1; }
done
[[ ! -L "$storage/illustrator-actions-key" ]] || { echo 'Ключ является ссылкой; установка остановлена.' >&2; exit 1; }
key="$storage/illustrator-actions-key"
if [[ ! -f "$key" ]]; then
    ssh-keygen -q -t ed25519 -N '' -C hunta-zen-illustrator-only -f "$key"
fi
[[ -f "$key.pub" ]] || { echo 'Нет публичной части ключа. Существующий ключ не изменён.' >&2; exit 1; }
chmod 600 "$key"
ssh_directory=${HUNTA_SSH_DIRECTORY:-$HOME/.ssh}
mkdir -p "$ssh_directory"
chmod 700 "$ssh_directory"
authorized="$ssh_directory/authorized_keys"
[[ ! -L "$authorized" ]] || { echo 'authorized_keys — ссылка; автоматическая установка остановлена.' >&2; exit 1; }
touch "$authorized";chmod 600 "$authorized"
printf -v forced 'env HUNTA_CONFIG=%q %q %q' "$config_path" "$php_bin" "$root/tools/illustrator_bridge.php"
# Paths with quotes/backslashes were rejected; quote the forced command for authorized_keys.
forced=${forced//\\/\\\\}
forced=${forced//\"/\\\"}
entry="restrict,command=\"$forced\" $(cat "$key.pub")"
if ! grep -Fqx -- "$entry" "$authorized"; then
    if grep -Fq -- "$(awk '{print $2}' "$key.pub")" "$authorized"; then
        echo 'Этот ключ уже добавлен с другими правами. Проверь authorized_keys; файл не изменён.' >&2; exit 1
    fi
    # A fresh private file needs no source ACL/ownership metadata (Beget denies cp -p).
    backup=$(mktemp "$authorized.before-illustrator-XXXXXXXX")
    cat -- "$authorized" >"$backup"
    cmp -s -- "$authorized" "$backup" || { echo 'Не удалось проверить копию authorized_keys; ключ не добавлен.' >&2; exit 1; }
    # Preserve a final key/comment even if the original file has no trailing newline.
    if [[ -s "$authorized" && -n "$(tail -c 1 -- "$authorized")" ]]; then printf '\n' >>"$authorized"; fi
    printf '%s\n' "$entry" >>"$authorized"
fi
# Read the actual server's public host keys. Do not trust unauthenticated ssh-keyscan output.
known="$storage/illustrator-actions-known-hosts"
temp=$(mktemp "$storage/hosts-XXXXXXXX")
trap 'rm -f -- "$temp"' EXIT
for public in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_ecdsa_key.pub /etc/ssh/ssh_host_rsa_key.pub; do
    if [[ -r "$public" ]]; then
        awk -v host="$host" '{print host" "$1" "$2}' "$public" >>"$temp"
    fi
done
if [[ ! -s "$temp" && ! -s "$known" ]] && command -v ssh-keyscan >/dev/null 2>&1; then
    # Probe only this machine's loopback, never accept an unverified remote host key.
    ssh-keyscan -T 5 127.0.0.1 2>/dev/null | awk -v host="$host" '!/^#/ && NF>=3 {print host" "$2" "$3}' >>"$temp" || true
fi
if [[ -s "$temp" ]]; then mv "$temp" "$known";chmod 600 "$known"
elif [[ ! -s "$known" ]]; then
    echo 'Ключ доступа создан. Публичные ключи SSH-сервера недоступны в этом окружении.' >&2
    echo 'Нужно сохранить проверенную запись known_hosts в storage/illustrator-actions-known-hosts, затем повторить установку.' >&2
    exit 1
fi
HUNTA_CONFIG="$config_path" "$php_bin" "$root/tools/backup.php"
HUNTA_CONFIG="$config_path" HUNTA_ACTIONS_TARGET="$user@$host" "$php_bin" -r '
require $argv[1]."/app/bootstrap.php";
$db->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(["illustrator_backend","actions"]);
$db->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(["illustrator_ssh_target",getenv("HUNTA_ACTIONS_TARGET")]);
' "$root"
echo 'Готово. Открой Дао → Иллюстратор → Подключение GitHub Actions.'
echo 'Там можно скачать отдельный ключ и known_hosts для настроек GitHub. Не присылай приватный ключ в чат.'
