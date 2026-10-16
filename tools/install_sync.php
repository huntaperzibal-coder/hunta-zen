<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$home=getenv('HOME');
if (!$home || !is_dir($home) || !is_writable($home)) throw new RuntimeException('Домашняя папка недоступна для создания команды.');
$root=realpath(dirname(__DIR__));$target=$home.'/zen-sync.sh';
$content="#!/usr/bin/env bash\nset -euo pipefail\ncd ".escapeshellarg($root)."\nif [[ -z \"\${PHP_BIN:-}\" ]]; then export PHP_BIN=".escapeshellarg(PHP_BINARY)."; fi\nexec bash tools/sync.sh\n";
if (is_link($target)) throw new RuntimeException('zen-sync.sh является ссылкой. Установка остановлена, ссылка не изменена.');
if (is_file($target) && file_get_contents($target)!==$content) {
    $backup=$target.'.before-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
    if (!copy($target,$backup)) throw new RuntimeException('Не удалось сохранить предыдущий скрипт.');
    chmod($backup,0700);
}
$tmp=tempnam($home,'.zen-sync-');
if ($tmp===false) throw new RuntimeException('Не удалось создать временный файл.');
try {
    if (file_put_contents($tmp,$content,LOCK_EX)!==strlen($content) || !chmod($tmp,0700) || !rename($tmp,$target)) throw new RuntimeException('Не удалось установить команду.');
} finally { if (is_file($tmp)) unlink($tmp); }
echo "Готово. Теперь обновление одной командой: ~/zen-sync.sh\n";
