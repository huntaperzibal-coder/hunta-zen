<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$db->beginTransaction();
try { $db->exec(file_get_contents(ROOT.'/database.sql')); $db->commit(); echo "Структура базы проверена. Записи сохранены.\n"; }
catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
