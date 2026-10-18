<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/zen-feedback-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','password_hash'=>password_hash('test-only',PASSWORD_DEFAULT)];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));session_open();
function fb_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'OK: '.$label."\n";}
function fb_fails(callable $f,string $label):void{try{$f();}catch(RuntimeException $e){echo 'OK: '.$label."\n";return;}throw new RuntimeException('FAIL: '.$label);}
function fb_form(string $message):array{
    $f=reader_feedback_issue();$_SESSION['reader_forms'][$f['nonce']]['issued']=time()-5;
    return ['submission'=>$f['nonce'],'message'=>$message,'contact'=>'@reader','answer'=>(string)$f['answer'],'consent'=>'1','consent_digest'=>$f['digest']];
}
try{
    $input=fb_form('Привет');fb_fails(fn()=>reader_feedback_save($input,'192.0.2.1'),'disabled form cannot collect data');
    reader_author_settings_save(['author_name'=>'Автор','operator_name'=>'Тестовый оператор','operator_address'=>'Адрес','operator_email'=>'privacy@example.test','feedback_enabled'=>'1'],[]);
    $input=fb_form('Личное сообщение');
    fb_fails(fn()=>reader_feedback_save(array_replace($input,['consent'=>'']),'192.0.2.1'),'server requires explicit consent');
    fb_fails(fn()=>reader_feedback_save(array_replace($input,['answer'=>'99']),'192.0.2.1'),'server checks session-bound challenge');
    $old=$_SESSION['reader_forms'];$_SESSION['reader_forms']=[];fb_fails(fn()=>reader_feedback_save($input,'192.0.2.1'),'another session cannot reuse submission');$_SESSION['reader_forms']=$old;
    reader_feedback_save($input,'192.0.2.1');reader_feedback_save($input,'192.0.2.1');
    fb_check((int)$db->query('SELECT count(*) FROM reader_feedback')->fetchColumn()===1,'duplicate request saves exactly one message');
    $record=$db->query('SELECT * FROM reader_feedback_consents')->fetch();fb_check($record['document_hash']===hash('sha256',$record['document'])&&str_contains($record['document'],'privacy@example.test'),'exact accepted consent document retained');
    fb_check($db->query('SELECT client_key FROM reader_feedback')->fetchColumn()!== '192.0.2.1','IP is keyed, never stored literally in feedback table');
    $second=fb_form('Другое сообщение');fb_fails(fn()=>reader_feedback_save($second,'192.0.2.1'),'new session token cannot bypass minute limit');
    $trap=fb_form('Spam');reader_feedback_save($trap+['website'=>'bot'],'192.0.2.2');fb_check((int)$db->query('SELECT count(*) FROM reader_feedback')->fetchColumn()===1,'honeypot discards without storing text');
    $expired=fb_form('Истёкшая');$_SESSION['reader_forms'][$expired['submission']]['issued']=time()-3601;fb_fails(fn()=>reader_feedback_save($expired,'192.0.2.3'),'expired form rejected');
    $changed=fb_form('Старое согласие');$db->exec("UPDATE settings SET value='Новое имя оператора' WHERE key='operator_name'");fb_fails(fn()=>reader_feedback_save($changed,'192.0.2.4'),'operator change invalidates old consent');
    $bad=fb_form('Spam');for($i=0;$i<20;$i++){try{reader_feedback_save(array_replace($bad,['answer'=>'99']),'192.0.2.9');}catch(RuntimeException $e){}}
    fb_fails(fn()=>reader_feedback_save($bad,'192.0.2.9'),'failed attempts count against hourly quota');
    $db->exec('UPDATE reader_feedback SET created_at='.(time()-91*86400));reader_feedback_cleanup();
    fb_check(!$db->query('SELECT count(*) FROM reader_feedback')->fetchColumn()&&!$db->query('SELECT count(*) FROM reader_feedback_consents')->fetchColumn(),'retention removes old message and consent together');
    $db->exec(file_get_contents(ROOT.'/database.sql'));fb_check(reader_legal_ready(),'migration is idempotent and preserves author settings');
}finally{session_write_close();$db=null;foreach(glob($dir.'/sessions/*')?:[]as$p)unlink($p);if(is_dir($dir.'/sessions'))rmdir($dir.'/sessions');foreach(glob($dir.'/*')?:[]as$p)if(is_file($p))unlink($p);rmdir($dir);}
