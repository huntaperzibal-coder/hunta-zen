<?php
declare(strict_types=1);

// Основа: принципы PDO/SQLite и единой проверки входа из altai-topo.
const ROOT = __DIR__ . '/..';
$configPath = getenv('HUNTA_CONFIG') ?: ROOT . '/storage/config.php';
if (!is_file($configPath)) {
    if (PHP_SAPI === 'cli') { throw new RuntimeException('Сначала запусти tools/install.php.'); }
    http_response_code(503);
    exit('Мастерская ещё не настроена. Запусти tools/install.php через SSH.');
}
$config = require $configPath;
$storage = $config['storage'];
if(is_file($storage.'/telegram-connection.php'))$config=array_replace($config,require $storage.'/telegram-connection.php');
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors','0');
    ini_set('log_errors','1');
    ini_set('error_log',$storage.'/app.log');
    set_exception_handler(function (Throwable $e): void {
        error_log((string)$e); http_response_code(500);
        echo 'Мастерская временно недоступна. Записи сохранены. Проверь журнал ошибок.';
    });
}
date_default_timezone_set('Asia/Novosibirsk');
$db = new PDO('sqlite:' . $storage . '/blog.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rich_text.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/orda_auth.php';
require_once __DIR__ . '/dao_brand.php';
require_once __DIR__ . '/orda_layout.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/telegram_schedule.php';
require_once __DIR__ . '/telegram_ui.php';
require_once __DIR__ . '/illustrator.php';
require_once __DIR__ . '/illustrator_ui.php';
require_once __DIR__ . '/illustrator_remote.php';
require_once __DIR__ . '/illustrator_runner.php';
