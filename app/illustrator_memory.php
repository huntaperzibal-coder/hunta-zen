<?php
declare(strict_types=1);

function illustration_memory_text(mixed $value,int $limit=500): string {
    return is_string($value)?mb_substr(trim(preg_replace('/\s+/u',' ',strip_tags($value))??''),0,$limit):'';
}
function illustration_memory_fingerprint(string $title,string $text): string {
    $normalize=static fn(string $value):string=>trim(preg_replace('/\s+/u',' ',$value)??$value);
    return hash('sha256',$normalize($title)."\n".$normalize($text));
}
/** Build a bounded card from an existing analysis; no additional model call. */
function illustration_memory_card(array $p,array $job): ?array {
    $series=(string)($p['category_slug']??'');
    if(!in_array($series,['vinnie','gena'],true))return null;
    $snapshot=json_decode($job['snapshot'],true);$plan=json_decode($job['plan'],true);
    if(!is_array($snapshot)||!is_array($plan)||($job['state']??'')!=='done'||empty($job['image']))return null;
    $sourceSeries=$snapshot['series']??null;
    if($sourceSeries===null){
        $profile=$snapshot['style_profile']['id']??'';
        $sourceSeries=is_string($profile)&&str_starts_with($profile,'vinnie-')?'vinnie':(is_string($profile)&&str_starts_with($profile,'gena-')?'gena':'');
    }
    if($sourceSeries!==$series || !is_string($snapshot['title']??null)||!is_string($snapshot['text']??null))return null;
    $hash=illustration_memory_fingerprint($snapshot['title'],$snapshot['text']);
    if(!hash_equals($hash,illustration_memory_fingerprint($p['title'],body_plain($p['body']))))return null;
    $memory=is_array($plan['memory']??null)?$plan['memory']:[];
    $thought=illustration_memory_text($memory['thought']??$plan['scene']??'');
    $image=illustration_memory_text($memory['image']??$plan['prompt']??'');
    if($thought===''||$image==='')return null;
    return ['version'=>1,'post_id'=>(int)$p['id'],'job_id'=>(int)$job['id'],'series'=>$series,'fingerprint'=>$hash,
        'selected_image'=>(string)$job['image'],'title'=>illustration_memory_text($p['title'],200),'thought'=>$thought,'image'=>$image,
        'style'=>illustration_memory_text($plan['art_style']['title']??'',100)];
}
/** Called inside the same transaction as the author's cover/inline selection. */
function illustration_memory_accept(array $p,array $job): void {
    global $db;
    $key='illustration_memory:'.(int)$p['id'];$card=illustration_memory_card($p,$job);
    if($card===null){$db->prepare('DELETE FROM settings WHERE key=?')->execute([$key]);return;}
    $db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,json_encode($card,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
/** Latest five accepted, still-current published stories from this cycle only. */
function illustration_memory_cards(array $p): array {
    global $db;
    $series=(string)($p['category_slug']??'');
    if(!in_array($series,['vinnie','gena'],true))return [];
    $q=$db->prepare("SELECT p.*,c.slug AS category_slug,m.value AS memory_json FROM posts p JOIN post_categories pc ON pc.post_id=p.id JOIN categories c ON c.id=pc.category_id LEFT JOIN settings m ON m.key='illustration_memory:' || p.id WHERE c.slug=? AND p.id<>? AND p.status='published' ORDER BY p.published_at DESC,p.id DESC");
    $q->execute([$series,$p['id']]);$cards=[];
    foreach($q as $row){
        $card=json_decode((string)($row['memory_json']??''),true);
        if($row['memory_json']===null && $row['cover']!==''){
            // Existing cover selection is evidence of acceptance; do not promote unused variants.
            $old=$db->prepare("SELECT * FROM illustration_jobs WHERE post_id=? AND image=? AND state='done' ORDER BY id DESC LIMIT 1");$old->execute([$row['id'],$row['cover']]);
            if($job=$old->fetch())$card=illustration_memory_card($row,$job);
        }
        if(!is_array($card)||($card['version']??null)!==1||($card['series']??'')!==$series||!is_string($card['fingerprint']??null)||!hash_equals($card['fingerprint'],illustration_memory_fingerprint($row['title'],body_plain($row['body']))))continue;
        $selected=$card['selected_image']??'';
        if(!is_string($selected)||$selected===''||($row['cover']!==$selected&&!str_contains(body_for_editor($row['body']),photo_url($selected))))continue;
        $cards[]=['post_id'=>(int)$row['id'],'title'=>illustration_memory_text($row['title'],200),'thought'=>illustration_memory_text($card['thought']??''),'image'=>illustration_memory_text($card['image']??''),'style'=>illustration_memory_text($card['style']??'',100)];
        if(count($cards)===5)break;
    }
    return $cards;
}
