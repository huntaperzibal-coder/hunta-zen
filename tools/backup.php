<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

function removeRollbackBackup(string $directory): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $entry) {
        $removed = $entry->isDir() && !$entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
        if (!$removed) {
            throw new RuntimeException('Не удалось убрать временную копию.');
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Не удалось убрать временный каталог копии.');
    }
}

umask(0077);
$lock = fopen($storage . '/.backup.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Не удалось заблокировать сборку резервной копии.');
}

$root = $storage . '/backups';
$latest = $root . '/before-update';
$previous = $root . '/.before-update-previous';
$stage = null;

try {
    if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700))) {
        throw new RuntimeException('Каталог резервных копий недоступен.');
    }
    foreach ([$latest, $previous] as $directory) {
        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('Путь резервной копии занят посторонним файлом или ссылкой.');
        }
    }
    if (is_dir($previous)) {
        if (is_dir($latest)) {
            removeRollbackBackup($previous);
        } elseif (!rename($previous, $latest)) {
            throw new RuntimeException('Не удалось вернуть предыдущую проверенную копию.');
        }
    }

    $temporary = $root . '/.before-update-building';
    if (is_link($temporary) || (file_exists($temporary) && !is_dir($temporary))) {
        throw new RuntimeException('Временный путь резервной копии занят посторонним файлом или ссылкой.');
    }
    if (is_dir($temporary)) {
        removeRollbackBackup($temporary);
    }
    if (!mkdir($temporary, 0700)) {
        throw new RuntimeException('Не удалось создать временный каталог резервной копии.');
    }
    $stage = $temporary;
    $snapshot = $stage . '/blog.sqlite';
    $db->exec('VACUUM INTO ' . $db->quote($snapshot));
    $check = new PDO('sqlite:' . $snapshot);
    if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('Копия базы не прошла проверку.');
    }
    $check = null;
    if (!chmod($snapshot, 0600) || !mkdir($stage . '/photos', 0700)) {
        throw new RuntimeException('Не удалось подготовить каталог фотографий.');
    }
    foreach (glob($storage . '/photos/*') as $photo) {
        $file = $stage . '/photos/' . basename($photo);
        if (!copy($photo, $file) || !chmod($file, 0600)) {
            throw new RuntimeException('Ошибка копирования фотографии.');
        }
    }

    if (is_dir($latest) && !rename($latest, $previous)) {
        throw new RuntimeException('Не удалось подготовить замену предыдущей копии.');
    }
    if (!rename($stage, $latest)) {
        if (is_dir($previous)) {
            rename($previous, $latest);
        }
        throw new RuntimeException('Не удалось сохранить новую копию.');
    }
    $stage = null;
    if (is_dir($previous)) {
        removeRollbackBackup($previous);
    }
    echo "Проверенная копия базы и фотографий: $latest\nКонфиг с паролем и токеном сохраняй отдельно в защищённом месте.\n";
} finally {
    if ($stage !== null) {
        removeRollbackBackup($stage);
    }
    fclose($lock);
}
