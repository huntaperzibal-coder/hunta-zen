#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo 'Есть локальные изменения кода. Сначала сохрани их коммитом; обновление остановлено.'; exit 1
fi
"$PHP_BIN" tools/backup.php
git fetch origin main
git merge --ff-only origin/main
"$PHP_BIN" tools/migrate.php
echo 'Код обновлён. База, фотографии и конфиг сохранены.'
