<?php
declare(strict_types=1);
/** Connections stay in the private config file, never in Git or HTML. */
function telegram_channels(): array {
    global $config;
    $channels=$config['telegram_channels']??[];
    $id=(string)($config['telegram_channel']??'');
    if($id!==''&&!isset($channels[$id]))$channels[$id]=array_intersect_key($config,array_flip(['telegram_token','telegram_channel','telegram_channel_title','telegram_bot_name']));
    return $channels;
}
function telegram_select_channel(string $id): void {
    global $config;
    $channels=telegram_channels();
    if(!isset($channels[$id]))throw new RuntimeException('Канал не подключён. Выбери его в разделе Telegram.');
    $config=array_replace($config,$channels[$id]);
    $config['telegram_channels']=$channels;
}
function telegram_store_channel(array $connection,bool $makeDefault=false): void {
    global $config,$storage;
    $lock=fopen($storage.'/telegram-connections.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Не удалось сохранить каналы.');
    try{
        $saved=is_file($storage.'/telegram-connection.php')?require $storage.'/telegram-connection.php':[];
        $channels=array_replace(telegram_channels(),$saved['telegram_channels']??[]);
        $channels[$connection['telegram_channel']]=$connection;
        $default=$makeDefault?$connection:array_intersect_key($saved?:$config,array_flip(['telegram_token','telegram_channel','telegram_channel_title','telegram_bot_name']));
        if(empty($default['telegram_channel']))$default=$connection;
        // Updating an existing default connection updates its token too.
        if(($default['telegram_channel']??'')===$connection['telegram_channel'])$default=$connection;
        $data=$default+['telegram_channels'=>$channels];
        $file=tempnam($storage,'tg-');if(!$file)throw new RuntimeException('Не удалось сохранить каналы.');
        chmod($file,0600);
        if(file_put_contents($file,'<?php return '.var_export($data,true).';',LOCK_EX)===false||!rename($file,$storage.'/telegram-connection.php')){@unlink($file);throw new RuntimeException('Не удалось сохранить каналы.');}
        $config=array_replace($config,$data);$config['telegram_default_channel']=(string)$default['telegram_channel'];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function telegram_channel_name(string $id): string {
    $channels=telegram_channels();return (string)($channels[$id]['telegram_channel_title']??$id);
}
function telegram_channel_query(): string {
    global $config;return '&channel='.rawurlencode((string)($config['telegram_channel']??''));
}
