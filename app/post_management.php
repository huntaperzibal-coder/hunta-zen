<?php
declare(strict_types=1);
/** Caller holds telegram.lock; cancellations also stop a worker's stale queue claim. */
function post_remove_telegram(int $id,?callable $transport=null): void {
    global $db,$config;
    $q=$db->prepare("SELECT 1 FROM telegram_deliveries WHERE post_id=? AND state IN ('unknown','sending')");$q->execute([$id]);
    if($q->fetchColumn())throw new RuntimeException('Сначала проверь неизвестный результат отправки в редакторе. Удаление остановлено.');
    $q=$db->prepare("SELECT 1 FROM telegram_deliveries WHERE post_id=? AND state='sent' AND message_id IS NULL");$q->execute([$id]);if($q->fetchColumn())throw new RuntimeException('Не сохранён номер опубликованного сообщения. Сначала проверь публикацию в канале.');
    $q=$db->prepare('SELECT channel,message_id FROM telegram_parts WHERE post_id=? UNION SELECT channel,message_id FROM telegram_deliveries WHERE post_id=?');$q->execute([$id,$id]);
    foreach($q->fetchAll() as $row){
        if(!$row['message_id'])continue;
        if(empty($config['telegram_token']))throw new RuntimeException('Подключи бота для удаления публикации из Telegram.');
        $payload=['chat_id'=>$row['channel'],'message_id'=>(int)$row['message_id']];
        try{$response=$transport?$transport('deleteMessage',$payload):telegram_request('deleteMessage',$payload);}
        catch(Throwable $e){throw new RuntimeException('Telegram не подтвердил удаление. Пост сохранён в системе; можно повторить удаление после проверки канала.');}
        $error=(string)($response['description']??'');
        if(empty($response['ok'])&&!str_contains(strtolower($error),'message to delete not found')&&!str_contains($error,'MESSAGE_ID_INVALID'))throw new RuntimeException('Не удалось удалить сообщение из Telegram: '.telegram_safe_error($error).'. Пост сохранён в системе. Возможно, нужно удалить сообщение вручную в канале.');
        $db->prepare('DELETE FROM telegram_parts WHERE post_id=? AND channel=? AND message_id=?')->execute([$id,$row['channel'],$row['message_id']]);
        $db->prepare('DELETE FROM telegram_deliveries WHERE post_id=? AND channel=? AND message_id=?')->execute([$id,$row['channel'],$row['message_id']]);
    }
    $db->prepare('DELETE FROM telegram_parts WHERE post_id=?')->execute([$id]);
    $db->prepare('DELETE FROM telegram_deliveries WHERE post_id=?')->execute([$id]);
    audit('telegram:removed',$id);
}
function post_manage(int $id,int $revision,string $command,?callable $transport=null): void {
    global $db,$storage;
    if(!in_array($command,['site_hide','site_publish','telegram_delete','post_delete'],true))throw new RuntimeException('Неизвестное действие.');
    $lock=fopen($storage.'/telegram.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Сейчас идёт отправка. Повтори после её завершения.');
    try{
        $p=post($id);if((int)$p['revision']!==$revision)throw new RuntimeException('Пост изменён в другом окне. Обнови список перед действием.');
        if(in_array($command,['site_hide','site_publish'],true)){
            if($command==='site_publish'&&(trim($p['title'])===''||trim(body_plain($p['body']))===''))throw new RuntimeException('Для публикации нужны заголовок и текст.');
            $state=$command==='site_publish'?'published':'draft';
            $q=$db->prepare('UPDATE posts SET status=?,published_at=CASE WHEN ?=\'published\' THEN COALESCE(published_at,?) ELSE published_at END,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
            $q->execute([$state,$state,now(),now(),$id,$revision]);if(!$q->rowCount())throw new RuntimeException('Пост изменён. Обнови список.');
            audit('manage:'.$command,$id);return;
        }
        if($command==='post_delete')database_backup_create();
        telegram_cancel_schedule($id);
        post_remove_telegram($id,$transport);
        if($command==='post_delete'){
            $db->beginTransaction();
            try{
                foreach(['telegram_schedule','telegram_parts','telegram_deliveries'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE post_id=?')->execute([$id]);
                $q=$db->prepare('DELETE FROM posts WHERE id=? AND revision=?');$q->execute([$id,$revision]);if(!$q->rowCount())throw new RuntimeException('Пост изменён. Обнови список.');
                $db->commit();audit('post:deleted',$id);
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function post_management_controls(array $p,string $scope,string $category): string {
    $id=(int)$p['id'];$onSite=$p['status']==='published';
    $label=$onSite?'Снять с сайта':'Опубликовать на сайте';
    $question=$onSite?'Снять пост с сайта? В системе и Telegram он останется.':'Опубликовать пост на сайте?';
    return '<details class="post-manage"><summary>Управление</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="post_manage"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="revision" value="'.(int)$p['revision'].'"><input type="hidden" name="list_scope" value="'.h($scope).'"><input type="hidden" name="list_category" value="'.h($category).'"><button name="command" value="'.($onSite?'site_hide':'site_publish').'" data-confirm="'.h($question).'">'.$label.'</button><button name="command" value="telegram_delete" data-confirm="Удалить публикации этого поста из Telegram и отменить его отложенные отправки? Пост в системе и на сайте останется.">Удалить из Telegram</button><button class="post-delete" name="command" value="post_delete" data-confirm="Удалить пост полностью: из системы, с сайта и из Telegram? Перед удалением будет создана резервная копия базы.">Удалить пост полностью</button></form></details>';
}
