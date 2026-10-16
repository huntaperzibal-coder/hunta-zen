<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try {
require dirname(__DIR__).'/app/bootstrap.php';
if(!is_callable('exec'))throw new RuntimeException('На хостинге отключён exec. Добавь cron через панель хостинга: '.PHP_BINARY.' '.realpath(ROOT.'/tools/illustrator_worker.php'));
$tag='# hunta-zen-illustrator-'.substr(hash('sha256',realpath(ROOT)),0,12);
$command='HUNTA_CONFIG='.escapeshellarg(realpath($configPath)).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(realpath(ROOT.'/tools/illustrator_worker.php')).' >> '.escapeshellarg($storage.'/illustrator-cron.log').' 2>&1';
if(str_contains($command,"\n") || str_contains($command,"\r"))throw new RuntimeException('Перенос строки в пути cron не поддерживается.');
// cron interprets percent even inside shell quotes.
$line='* * * * * '.str_replace('%','\\%',$command).' '.$tag;
exec('crontab -l 2>&1',$output,$status);
if($status!==0 && !preg_match('/no crontab|нет.*crontab|отсутствует/i',implode("\n",$output)))throw new RuntimeException('Не удалось прочитать cron. Используй панель хостинга: '.$command);
if($status!==0)$output=[];
$output=array_values(array_filter($output,fn($v)=>!str_ends_with(trim($v),$tag)));$output[]=$line;
$tmp=tempnam($storage,'cron-');if(!$tmp)throw new RuntimeException('Не удалось создать временный файл.');chmod($tmp,0600);
try{
    file_put_contents($tmp,implode("\n",$output)."\n");exec('crontab '.escapeshellarg($tmp).' 2>&1',$result,$code);
    if($code!==0)throw new RuntimeException('Хостинг не принял cron. Добавь через панель: '.$command);
}finally{unlink($tmp);}
echo "Иллюстратор подключён: проверка очереди раз в минуту. Ключ добавь в Дао → Иллюстратор.\n";

} catch (Throwable $e) {
    fwrite(STDERR,"Не удалось подключить cron: ".$e->getMessage()."\n"
        ."Код сайта обновлён. Попробуй установку через оболочку:\n"
        ."PHP_BIN=".escapeshellarg(PHP_BINARY)." bash ".escapeshellarg(__DIR__.'/install_illustrator.sh')."\n");
    exit(1);
}
