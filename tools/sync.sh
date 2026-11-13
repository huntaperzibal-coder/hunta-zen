#!/usr/bin/env bash
# main: резервная копия → fetch → rebase → migrate → push. Без force.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [[ -z "${PHP_BIN:-}" ]]; then
  if [[ -x /usr/local/bin/php8.2 ]]; then PHP_BIN=/usr/local/bin/php8.2; else PHP_BIN=php; fi
fi
if command -v flock >/dev/null 2>&1; then
  exec 9>"$(git rev-parse --git-path zen-sync.lock)"
  flock -n 9 || { echo 'Обновление уже идёт. Подожди его завершения.'; exit 1; }
fi
if [[ "$(git branch --show-current)" != main ]]; then
  echo 'Обновление остановлено: текущая ветка не main.'; exit 1
fi
if [[ -d "$(git rev-parse --git-path rebase-merge)" || -d "$(git rev-parse --git-path rebase-apply)" || -f "$(git rev-parse --git-path MERGE_HEAD)" ]]; then
  echo 'Сначала заверши текущее слияние или rebase. Файлы не изменены.'; exit 1
fi
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo 'Есть несохранённые изменения кода. Сначала сохрани их коммитом. Файлы не изменены.'; exit 1
fi
config_path="${HUNTA_CONFIG:-$PWD/storage/config.php}"
if [[ -f "$config_path" ]]; then "$PHP_BIN" tools/backup.php; fi
fetch_main() {
  local attempt
  for attempt in 1 2 3; do
    if git fetch origin main; then return 0; fi
    echo "Не получилось получить main, попытка $attempt из 3."
    if [[ "$attempt" -lt 3 ]]; then sleep 2; fi
  done
  return 1
}
rebase_main() {
  if ! git rebase origin/main; then
    echo 'Обновление остановлено из-за конфликта. Git сохранил состояние rebase; push не выполнен.'
    return 1
  fi
  if [[ -f "$config_path" ]]; then "$PHP_BIN" tools/migrate.php; fi
}
fetch_main
rebase_main
for push_attempt in 1 2 3; do
  if git push origin main; then
    echo 'Готово. main обновлена и отправлена в Git. База, фотографии и настройки сохранены.'
    git log -1 --oneline
    exit 0
  fi
  if [[ "$push_attempt" -lt 3 ]]; then
    echo 'Push не прошёл. Проверяю новые коммиты в main и повторяю.'
    fetch_main
    rebase_main
  fi
done
echo 'Push не выполнен после трёх попыток. Локальные коммиты сохранены.'
exit 1
