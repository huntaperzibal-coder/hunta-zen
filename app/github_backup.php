<?php
declare(strict_types=1);

function zen_backup_command(array $command, ?array $environment = null): string {
    $log = tmpfile();
    if (!$log) throw new RuntimeException('Не удалось открыть журнал операции.');
    try {
        $process = proc_open($command, [['file', '/dev/null', 'r'], $log, $log], $pipes, null, $environment);
        if (!is_resource($process) || proc_close($process) !== 0) {
            rewind($log);
            error_log('Zen backup: '.substr((string)stream_get_contents($log), 0, 2000));
            throw new RuntimeException('restic завершился с ошибкой. Проверь реквизиты и репозиторий Selectel.');
        }
        rewind($log);
        return (string)stream_get_contents($log);
    } finally {
        fclose($log);
    }
}

function zen_backup_run(string $action): void {
    global $db, $storage, $configPath;
    $stage = sys_get_temp_dir().'/zen-'.bin2hex(random_bytes(12));
    if (!mkdir($stage, 0700)) throw new RuntimeException('Не удалось создать временную папку.');
    $archive = $stage.'/hunta-zen.zip';
    try {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('В веб-PHP недоступно расширение ZIP.');
        $snapshot = $stage.'/blog.sqlite';
        $db->exec('VACUUM INTO '.$db->quote($snapshot));
        $check = new PDO('sqlite:'.$snapshot);
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Копия базы блога не прошла проверку.');
        }
        unset($check);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Не удалось открыть ZIP для записи.');
        }
        $add = static function (string $source, string $name) use ($zip): void {
            if (!$zip->addFile($source, $name) || !$zip->setCompressionName($name, ZipArchive::CM_STORE)) {
                throw new RuntimeException('Не удалось добавить файл в ZIP.');
            }
        };
        $add($snapshot, 'data/blog.sqlite');
        $add($configPath, 'configuration/config.php');
        foreach ([realpath(ROOT) => 'site', realpath($storage) => 'data'] as $base => $prefix) {
            $entries = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $item): bool {
                    $name = $item->getFilename();
                    return !$item->isLink() && !in_array($name, [
                        '.git', '.ssh', '.gitconfig', '.bash_history', '.lesshst', 'storage',
                        'backups', 'zen_backups', 'sessions', 'image-cache', 'access-backups',
                        'restic', 'zen-selectel.json', 'blog.sqlite', 'blog.sqlite-wal',
                        'blog.sqlite-shm', 'blog.sqlite-journal',
                    ], true) && !str_ends_with($name, '.log') && !str_ends_with($name, '.lock');
                }
            );
            foreach (new RecursiveIteratorIterator($entries) as $item) {
                if ($item->isFile() && realpath($item->getPathname()) !== realpath($configPath)) $add($item->getPathname(), $prefix.'/'.substr($item->getPathname(), strlen($base)+1));
            }
        }
        if (!$zip->close()) throw new RuntimeException('Не удалось записать ZIP. Проверь свободное место.');
        if ($action === 'download_blog_backup') {
            session_write_close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="hunta-zen-'.date('Y-m-d_H-i-s').'.zip"');
            header('Content-Length: '.filesize($archive));
            readfile($archive);
            return;
        }
        $restic = $storage.'/restic';
        if (!is_executable($restic)) throw new RuntimeException('Установи исполняемый restic в закрытую папку данных Дзен.');
        $credentialsPath = $storage.'/zen-selectel.json';
        $new = isset($_POST['access_key']);
        $keys = $new ? [
            'AWS_ACCESS_KEY_ID' => trim((string)$_POST['access_key']),
            'AWS_SECRET_ACCESS_KEY' => (string)($_POST['secret_key'] ?? ''),
            'RESTIC_PASSWORD' => (string)($_POST['backup_password'] ?? ''),
        ] : (is_readable($credentialsPath) ? json_decode((string)file_get_contents($credentialsPath), true) : []);
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESTIC_PASSWORD'] as $key) {
            if (empty($keys[$key])) throw new RuntimeException('Заполни реквизиты Selectel.');
        }
        $env = $keys + [
            'AWS_DEFAULT_REGION' => 'ru-3',
            'RESTIC_REPOSITORY' => 's3:https://s3.ru-3.storage.selcloud.ru/hunta-backups/hunta-zen/',
        ];
        try {
            zen_backup_command([$restic, '--no-cache', 'cat', 'config'], $env);
        } catch (RuntimeException $e) {
            if (!$new || empty($_POST['initialize_repository'])) throw $e;
            zen_backup_command([$restic, '--no-cache', 'init'], $env);
        }
        $output = zen_backup_command([$restic, '--no-cache', '--json', 'backup', '--stdin-from-command',
            '--stdin-filename', 'hunta-zen.zip', '--tag', 'hunta-zen', '--', '/bin/cat', $archive], $env);
        foreach (explode("\n", $output) as $line) {
            $event = json_decode($line, true);
            if (($event['message_type'] ?? '') !== 'summary' || empty($event['snapshot_id'])) continue;
            if ($new) {
                $tmp = tempnam($storage, '.zen-selectel-');
                if ($tmp === false) throw new RuntimeException('Не удалось подготовить сохранение реквизитов.');
                try {
                    if (!chmod($tmp, 0600) || !file_put_contents($tmp, json_encode($keys, JSON_THROW_ON_ERROR))
                        || !rename($tmp, $credentialsPath)) {
                        throw new RuntimeException('Снимок сохранён, но записать реквизиты не удалось.');
                    }
                } finally {
                    if (is_file($tmp)) unlink($tmp);
                }
            }
            $_SESSION['zen_backup_notice'] = 'Снимок '.substr($event['snapshot_id'], 0, 8).' сохранён в Selectel.';
            return;
        }
        throw new RuntimeException('Selectel не вернул идентификатор снимка.');
    } finally {
        if (is_file($archive)) unlink($archive);
        if (isset($snapshot) && is_file($snapshot)) unlink($snapshot);
        rmdir($stage);
    }
}
