<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-rich-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'OK: '.$message."\n";}
try {
    $name=str_repeat('a',32).'.jpg';
    $video='/media.php?file='.str_repeat('b',32).'.mp4';
    $cleanVideo=sanitize_article_html('<video src="'.$video.'" autoplay loop onplay="bad()" poster="https://bad.test/x"><source src="https://bad.test/a.mp4"></video>');
    expect($cleanVideo==='<video src="'.$video.'" controls playsinline preload="metadata"></video>','video retained with safe native controls only');
    expect(sanitize_article_html($cleanVideo)===$cleanVideo,'video sanitizer round trip');
    expect(sanitize_article_html('<video src="https://bad.test/x.mp4"></video><video src="javascript:bad()"></video>')==='','remote and executable video URLs removed');
    expect(media_byte_range('bytes=2-6',10)===[2,6]&&media_byte_range('bytes=-3',10)===[7,9]&&media_byte_range('bytes=10-',10)===false,'bounded byte range parser');

    $html='<h2>Заголовок</h2><p style="text-align: center; color:red">Текст <strong>жирный</strong> <em>курсив</em> <a href="https://example.org/a" target="_blank">ссылка</a></p><ul><li>Первый</li><li>Второй</li></ul><blockquote>Цитата</blockquote><figure><img src="/media.php?file='.$name.'" alt="Горы"><figcaption>Подпись</figcaption></figure><table><tbody><tr><td colspan="2">Ячейка</td></tr></tbody></table>';
    $clean=sanitize_article_html($html);
    expect(str_contains($clean,'<h2>Заголовок</h2>') && str_contains($clean,'<strong>жирный</strong>') && str_contains($clean,'<blockquote>'),'formatting preserved');
    expect(str_contains($clean,'align-center') && !str_contains($clean,'color:red'),'alignment without arbitrary styles');
    expect(sanitize_article_html($clean)===$clean,'sanitizer round trip');
    $bad='<script>alert(1)</script><iframe src="https://example.org"></iframe><svg onload="alert(1)"></svg><p onclick="alert(1)">Текст</p><a href="java&#x73;cript:alert(1)">опасная ссылка</a><img src="https://remote.example/a.jpg"><img src="data:image/svg+xml,bad"><img src="/media.php?file='.$name.'" onerror="alert(1)">';
    $safe=sanitize_article_html($bad);
    expect(!preg_match('/script|iframe|svg|onclick|onerror|data:|remote\.example/',$safe),'active HTML and remote images removed');
    expect(str_contains($safe,'file='.$name),'local photo retained');
    expect(body_for_editor('Литерал <b>не тег</b>')==='<p>Литерал &lt;b&gt;не тег&lt;/b&gt;</p>','old plain text stays literal');
    $db->prepare('INSERT INTO posts(slug,created_at,updated_at) VALUES(?,?,?)')->execute(['rich',now(),now()]);$id=(int)$db->lastInsertId();
    $p=save_post($id,['revision'=>1,'title'=>'История','body'=>$html,'body_format'=>'html']);
    expect(rich_body($p['body']) && render_body($p['body'])===$clean,'rich save and render');
    $plain=body_plain($p['body']);expect(str_contains($plain,'Заголовок') && str_contains($plain,'Подпись') && !str_contains($plain,'<h2>'),'plain excerpts and Telegram');
    expect(!str_contains(telegram_text($p),'<strong>'),'Telegram has no HTML markup');
    $again=save_post($id,['revision'=>$p['revision'],'title'=>'История','body'=>body_for_editor($p['body']),'body_format'=>'html']);
    expect($again['body']===$p['body'],'subsequent edit preserves formatting');
} finally {$db=null;foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}
