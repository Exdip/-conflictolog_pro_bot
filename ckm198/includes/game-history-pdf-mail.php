<?php
if (!defined('ABSPATH')) exit;

/**
 * Automatic private PDF archive + email delivery for every completed game.
 * The game engine only schedules work; PDF rendering and wp_mail happen later
 * through WP-Cron, outside the gameplay transaction/request.
 */
const CKMQP_HISTORY_PDF_RECIPIENT = 'exdip@ya.ru';
const CKMQP_HISTORY_PDF_CRON_HOOK = 'ckm_qp_game_history_pdf_mail';

function ckmqp_history_pdf_reports_table(): string {
    return ckm_quiz_pro_table('history_reports');
}

function ckmqp_history_pdf_recipient(): string {
    $email = apply_filters('ckm_qp_history_pdf_recipient', CKMQP_HISTORY_PDF_RECIPIENT);
    return sanitize_email((string)$email) ?: CKMQP_HISTORY_PDF_RECIPIENT;
}

function ckmqp_history_pdf_schedule(int $gameId): void {
    if ($gameId < 1) return;
    global $wpdb;
    $game = $wpdb->get_row($wpdb->prepare('SELECT id,tenant_id,status FROM '.ckm_quiz_pro_table('games').' WHERE id=%d LIMIT 1',$gameId),ARRAY_A);
    if (!$game) return;
    $table = ckmqp_history_pdf_reports_table();
    $now = current_time('mysql');
    $exists = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE game_id=%d LIMIT 1",$gameId),ARRAY_A);
    if (!$exists) {
        $wpdb->insert($table,[
            'game_id'=>$gameId,'tenant_id'=>(int)$game['tenant_id'],'recipient'=>ckmqp_history_pdf_recipient(),
            'status'=>'pending','pdf_path'=>'','pdf_sha256'=>'','attempts'=>0,'last_error'=>'','created_at'=>$now,'updated_at'=>$now,'sent_at'=>null,
        ]);
    } elseif ((string)$exists['status']==='sent') {
        return;
    }
    $args=[$gameId];
    if (!wp_next_scheduled(CKMQP_HISTORY_PDF_CRON_HOOK,$args)) {
        wp_schedule_single_event(time()+5,CKMQP_HISTORY_PDF_CRON_HOOK,$args);
    }
}

add_action('ckm_quiz_event_appended',static function(array $event): void {
    $action=sanitize_key((string)($event['action'] ?? ''));
    if (!in_array($action,['game_finished','negotiation_show_finished'],true)) return;
    ckmqp_history_pdf_schedule((int)($event['game_id'] ?? 0));
},30,1);

add_action(CKMQP_HISTORY_PDF_CRON_HOOK,'ckmqp_history_pdf_send_game',10,1);

function ckmqp_history_pdf_private_dir(): string {
    $preferred = trailingslashit(dirname(untrailingslashit(ABSPATH))).'ckm-private-game-history';
    $dir = $preferred;
    if (!is_dir($dir) && !wp_mkdir_p($dir)) $dir = trailingslashit(WP_CONTENT_DIR).'ckm-private-game-history';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    if (is_dir($dir)) {
        $ht=$dir.'/.htaccess'; if(!file_exists($ht)) @file_put_contents($ht,"Deny from all\n<IfModule mod_authz_core.c>Require all denied</IfModule>\n");
        $idx=$dir.'/index.php'; if(!file_exists($idx)) @file_put_contents($idx,"<?php http_response_code(404); exit;\n");
        $web=$dir.'/web.config'; if(!file_exists($web)) @file_put_contents($web,'<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
    }
    return $dir;
}

function ckmqp_history_pdf_font_path(): string {
    $custom=(string)apply_filters('ckm_qp_history_pdf_font_path','');
    $candidates=array_filter([
        $custom,
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ]);
    foreach($candidates as $path) if(is_readable($path)) return $path;
    return '';
}

function ckmqp_history_pdf_text(array $game,array $teams,array $questions,array $answers,array $scoreEvents,array $events,?array $show): array {
    $lines=[];
    $add=static function(string $text='',string $kind='body') use (&$lines): void {$lines[]=['text'=>$text,'kind'=>$kind];};
    $title=trim((string)($game['title'] ?? '')) ?: ('Игра '.(string)($game['game_code'] ?? ''));
    $add('ЦКМ — история игры','title');
    $add($title,'h1');
    $add('Код: '.(string)($game['game_code'] ?? ''));
    $add('Формат: '.(function_exists('ckm_quiz_pro_history_format_label')?ckm_quiz_pro_history_format_label($game):(string)($game['format_key_snapshot']??'')));
    $add('Ведущий: '.(function_exists('ckm_quiz_pro_history_host_label')?ckm_quiz_pro_history_host_label($game):(string)($game['host_mode_snapshot']??'')));
    $add('Начало: '.(function_exists('ckm_quiz_pro_history_datetime')?ckm_quiz_pro_history_datetime($game['started_at']??''):(string)($game['started_at']??'')));
    $add('Завершение: '.(function_exists('ckm_quiz_pro_history_datetime')?ckm_quiz_pro_history_datetime($game['finished_at']??''):(string)($game['finished_at']??'')));
    $add(); $add('ИТОГОВЫЙ РЕЗУЛЬТАТ','h2');
    foreach($teams as $t){
        $score=function_exists('ckm_quiz_pro_history_team_score')?ckm_quiz_pro_history_team_score($t):(int)($t['score']??0);
        $add((string)$t['team_name'].' — '.$score.' очк.');
        $sum=trim((string)($t['final_summary']??'')); if($sum!=='')$add('  '.$sum);
    }
    if($show){
        $names=[];foreach($teams as $t)$names[(int)$t['slot_no']]=(string)$t['team_name'];
        $roundTitles=[0=>'Удержи цель',1=>'Скрытая задача',2=>'Неудобный вопрос',3=>'Проверь историю'];
        $add();$add('ХОД «ПЕРЕГОВОРИ МЕНЯ»','h2');
        foreach($roundTitles as $round=>$rtitle){
            $add('Раунд '.($round+1).'. '.$rtitle,'h3');
            if(in_array($round,[0,1],true)){
                foreach((array)($show['messages']??[]) as $m){if((int)($m['round']??0)!==$round)continue;$slot=(int)($m['slot']??0);$add(($names[$slot]??('Команда '.$slot)).': '.trim((string)($m['text']??'')));}
            } elseif($round===2){
                $hard=function_exists('ckmqp_show_hard_questions')?ckmqp_show_hard_questions($show):[];
                foreach((array)($show['hardAnswers']??[]) as $attempt=>$rows)foreach((array)$rows as $q=>$ans){$slot=(int)$attempt+1;$qt=(string)($hard[$attempt][$q]??('Вопрос '.((int)$q+1)));$add(($names[$slot]??('Команда '.$slot)).' — '.$qt);$add('Ответ: '.trim((string)($ans['text']??'')));}
            } else {
                foreach((array)($show['storyStories']??[]) as $attempt=>$story){$slot=(int)$attempt+1;$add(($names[$slot]??('Команда '.$slot)).' — рассказ: '.trim((string)($story['text']??'')));foreach((array)($show['storyAnswers'][$attempt]??[]) as $answer)$add('Ответ: '.trim((string)($answer['text']??'')));}
            }
            $sets=$round===0?($show['reviews']??[]):($round===1?($show['hiddenReviews']??[]):($round===2?($show['hardReviews']??[]):($show['storyResults']??[])));
            foreach((array)$sets as $attempt=>$wrap){$review=$round===3?$wrap:($wrap['review']??null);if(!is_array($review))continue;$slot=(int)$attempt+1;$total=(int)($review['total']??$review['storytellerPoints']??0);$add('Арбитр — '.($names[$slot]??('Команда '.$slot)).': '.$total.' очк.');$sum=trim((string)($review['summary']??''));if($sum!=='')$add($sum);$rec=trim((string)($review['recommendation']??''));if($rec!=='')$add('Рекомендация: '.$rec);}
        }
    }
    $qBy=[];foreach($questions as $q)$qBy[(int)$q['id']]=$q;
    $aBy=[];foreach($answers as $a)$aBy[(int)$a['question_id']][]=$a;
    if($answers){$add();$add('ВОПРОСЫ И ОТВЕТЫ','h2');foreach($aBy as $qid=>$rows){$q=$qBy[$qid]??null;$add($q?trim((string)$q['question_text']):('Вопрос #'.$qid),'h3');foreach($rows as $a){$team=trim((string)($a['team_name']??''))?:'Команда';$add($team.': '.trim((string)($a['answer_text']??'')));$add('Решение: '.(function_exists('ckm_quiz_pro_history_verdict_label')?ckm_quiz_pro_history_verdict_label((string)($a['verdict']??'')):(string)($a['verdict']??'')).'; баллы: '.(int)($a['awarded_points']??0));$jc=trim((string)($a['judge_comment']??''));if($jc!=='')$add('Комментарий: '.$jc);}}}
    if($scoreEvents){$add();$add('ЖУРНАЛ НАЧИСЛЕНИЙ','h2');foreach($scoreEvents as $e){$team=trim((string)($e['team_name']??''))?:'Команда';$d=(int)($e['points_delta']??0);$add($team.': '.($d>=0?'+':'').$d.'; '.(int)($e['score_before']??0).' → '.(int)($e['score_after']??0).'. '.trim((string)($e['reason']??'')));}}
    if($events){$add();$add('СЕРВЕРНАЯ ХРОНОЛОГИЯ','h2');foreach($events as $e){$label=function_exists('ckm_quiz_pro_history_event_label')?ckm_quiz_pro_history_event_label((string)$e['action']):(string)$e['action'];$note=function_exists('ckm_quiz_pro_history_event_note')?ckm_quiz_pro_history_event_note($e):'';$when=function_exists('ckm_quiz_pro_history_datetime')?ckm_quiz_pro_history_datetime($e['created_at']??''):(string)($e['created_at']??'');$add($when.' — '.$label.($note!==''?'. '.$note:''));}}
    $add();$add('PDF сформирован автоматически сервером ЦКМ '.wp_date('d.m.Y H:i:s'),'foot');
    return $lines;
}

function ckmqp_history_pdf_wrap(string $text,int $limit): array {
    $text=preg_replace('/\s+/u',' ',trim($text));
    if($text==='') return [''];
    $words=preg_split('/\s+/u',$text)?:[$text];$out=[];$line='';
    foreach($words as $word){$wordChars=preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:[$word];if(count($wordChars)>$limit){if($line!==''){$out[]=$line;$line='';}foreach(array_chunk($wordChars,$limit) as $chunk)$out[]=implode('',$chunk);continue;}$try=$line===''?$word:$line.' '.$word;$len=function_exists('mb_strlen')?mb_strlen($try,'UTF-8'):count(preg_split('//u',$try,-1,PREG_SPLIT_NO_EMPTY));if($len<=$limit){$line=$try;continue;}if($line!=='')$out[]=$line;$line=$word;}
    if($line!=='')$out[]=$line;return $out?:[''];
}

function ckmqp_history_pdf_render_pages(array $lines): array {
    if(class_exists('Imagick') && class_exists('ImagickDraw')) return ckmqp_history_pdf_render_pages_imagick($lines);
    if(function_exists('imagecreatetruecolor') && function_exists('imagettftext')) return ckmqp_history_pdf_render_pages_gd($lines);
    throw new RuntimeException('На сервере нет Imagick или GD/FreeType для формирования PDF с русским текстом.');
}

function ckmqp_history_pdf_layout(array $lines,callable $pageFactory,callable $writer,callable $pageFinish): array {
    $pages=[];$page=null;$y=0;$bottom=1650;
    $newPage=function()use(&$page,&$y,&$pages,$pageFactory){if($page!==null)$pages[]=$page;$page=$pageFactory();$y=100;};
    $newPage();
    foreach($lines as $item){$kind=(string)($item['kind']??'body');$text=(string)($item['text']??'');$size=$kind==='title'?30:($kind==='h1'?34:($kind==='h2'?25:($kind==='h3'?21:($kind==='foot'?14:18))));$limit=$kind==='h1'?52:($kind==='h2'?65:86);$spacing=$kind==='title'||$kind==='h1'?50:($kind==='h2'?40:($kind==='h3'?34:30));$wrapped=ckmqp_history_pdf_wrap($text,$limit);if($text==='')$wrapped=[''];foreach($wrapped as $line){if($y+$spacing>$bottom)$newPage();$writer($page,$line,80,$y,$size,$kind);$y+=$spacing;}if(in_array($kind,['h1','h2'],true))$y+=10;}
    if($page!==null)$pages[]=$page;
    $jpg=[];foreach($pages as $p)$jpg[]=$pageFinish($p);return $jpg;
}

function ckmqp_history_pdf_render_pages_imagick(array $lines): array {
    $factory=static function(){ $im=new Imagick();$im->newImage(1240,1754,'white','jpeg');$im->setImageColorspace(Imagick::COLORSPACE_RGB);return $im;};
    $writer=static function($im,string $text,int $x,int $y,int $size,string $kind): void {$d=new ImagickDraw();$d->setFillColor('black');$d->setFontSize($size);$font=ckmqp_history_pdf_font_path();if($font!=='')$d->setFont($font);else{$fonts=Imagick::queryFonts('DejaVu*');if($fonts)$d->setFont((string)$fonts[0]);}$im->annotateImage($d,$x,$y,0,$text);$d->clear();};
    $finish=static function($im): string {$im->setImageFormat('jpeg');$im->setImageCompressionQuality(90);$blob=$im->getImagesBlob();$im->clear();$im->destroy();return $blob;};
    return ckmqp_history_pdf_layout($lines,$factory,$writer,$finish);
}

function ckmqp_history_pdf_render_pages_gd(array $lines): array {
    $font=ckmqp_history_pdf_font_path();if($font==='')throw new RuntimeException('Не найден серверный шрифт с поддержкой кириллицы для PDF.');
    $factory=static function(){ $im=imagecreatetruecolor(1240,1754);$white=imagecolorallocate($im,255,255,255);imagefill($im,0,0,$white);return $im;};
    $writer=static function($im,string $text,int $x,int $y,int $size,string $kind)use($font): void {$black=imagecolorallocate($im,20,24,30);imagettftext($im,$size,0,$x,$y,$black,$font,$text);};
    $finish=static function($im): string {ob_start();imagejpeg($im,null,90);$blob=(string)ob_get_clean();imagedestroy($im);return $blob;};
    return ckmqp_history_pdf_layout($lines,$factory,$writer,$finish);
}

function ckmqp_history_pdf_from_jpegs(array $pages): string {
    if(!$pages)throw new RuntimeException('PDF: нет страниц.');
    $objects=[];$kids=[];$count=count($pages);
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
    for($i=0;$i<$count;$i++)$kids[]=(3+$i*3).' 0 R';
    $objects[2]='<< /Type /Pages /Count '.$count.' /Kids [ '.implode(' ',$kids).' ] >>';
    foreach($pages as $i=>$jpg){$pageId=3+$i*3;$imageId=$pageId+1;$contentId=$pageId+2;$objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 '.$imageId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';$objects[$imageId]='<< /Type /XObject /Subtype /Image /Width 1240 /Height 1754 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpg).' >>' . "\nstream\n".$jpg."\nendstream";$content="q\n595 0 0 842 0 0 cm\n/Im0 Do\nQ\n";$objects[$contentId]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content.'endstream';}
    ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$body."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i]??0)."\n";$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";return $pdf;
}

function ckmqp_history_pdf_build(int $gameId): array {
    global $wpdb;
    $game=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('games').' WHERE id=%d LIMIT 1',$gameId),ARRAY_A);if(!$game)throw new RuntimeException('Игра не найдена.');if((string)$game['status']!=='finished')throw new RuntimeException('Игра ещё не завершена.');
    $teams=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('teams').' WHERE game_id=%d ORDER BY slot_no,id',$gameId),ARRAY_A)?:[];
    $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',(int)$game['quiz_id'],(int)$game['quiz_revision']),ARRAY_A)?:[];
    $answers=$wpdb->get_results($wpdb->prepare('SELECT a.*,t.team_name,t.slot_no FROM '.ckm_quiz_pro_table('answers').' a LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=a.team_id WHERE a.game_id=%d ORDER BY a.question_id,a.submitted_at,a.id',$gameId),ARRAY_A)?:[];
    $score=$wpdb->get_results($wpdb->prepare('SELECT s.*,t.team_name,t.slot_no FROM '.ckm_quiz_pro_table('score_events').' s LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=s.team_id WHERE s.game_id=%d ORDER BY s.created_at,s.id',$gameId),ARRAY_A)?:[];
    $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('events').' WHERE game_id=%d ORDER BY id ASC',$gameId),ARRAY_A)?:[];
    $show=(function_exists('ckm_quiz_pro_history_is_persuade_me')&&ckm_quiz_pro_history_is_persuade_me($game)&&function_exists('ckm_quiz_pro_history_show_session'))?ckm_quiz_pro_history_show_session($gameId,(int)$game['tenant_id'],$teams):null;
    $lines=ckmqp_history_pdf_text($game,$teams,$questions,$answers,$score,$events,$show);
    $jpg=ckmqp_history_pdf_render_pages($lines);$pdf=ckmqp_history_pdf_from_jpegs($jpg);
    $dir=ckmqp_history_pdf_private_dir();if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Приватная папка PDF недоступна для записи.');
    $code=sanitize_file_name((string)($game['game_code']??('game-'.$gameId)));$name='game-history-'.$code.'-'.$gameId.'.pdf';$path=trailingslashit($dir).$name;if(file_put_contents($path,$pdf)===false)throw new RuntimeException('Не удалось сохранить PDF.');
    return ['path'=>$path,'sha256'=>hash('sha256',$pdf),'game'=>$game,'pages'=>count($jpg)];
}

function ckmqp_history_pdf_send_game(int $gameId): void {
    if($gameId<1)return;global $wpdb;$table=ckmqp_history_pdf_reports_table();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE game_id=%d LIMIT 1",$gameId),ARRAY_A);if(!$row){ckmqp_history_pdf_schedule($gameId);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE game_id=%d LIMIT 1",$gameId),ARRAY_A);}if(!$row||$row['status']==='sent')return;
    $attempt=(int)$row['attempts']+1;$now=current_time('mysql');$wpdb->update($table,['status'=>'processing','attempts'=>$attempt,'updated_at'=>$now,'last_error'=>''],['game_id'=>$gameId]);
    try{
        $built=ckmqp_history_pdf_build($gameId);$game=$built['game'];$recipient=ckmqp_history_pdf_recipient();$subject='ЦКМ — история игры '.((string)($game['title']??'')?:((string)($game['game_code']??'')));$body="Игра завершена. Во вложении автоматически сформирован PDF с полным ходом игры.\n\nКод игры: ".(string)($game['game_code']??'')."\nФормат: ".(function_exists('ckm_quiz_pro_history_format_label')?ckm_quiz_pro_history_format_label($game):(string)($game['format_key_snapshot']??''))."\nЗавершение: ".(string)($game['finished_at']??'')."\n";
        $ok=wp_mail($recipient,$subject,$body,['Content-Type: text/plain; charset=UTF-8'],[$built['path']]);if(!$ok)throw new RuntimeException('wp_mail вернул false. Проверьте почтовую отправку WordPress/SMTP.');
        $wpdb->update($table,['status'=>'sent','recipient'=>$recipient,'pdf_path'=>$built['path'],'pdf_sha256'=>$built['sha256'],'updated_at'=>current_time('mysql'),'sent_at'=>current_time('mysql'),'last_error'=>''],['game_id'=>$gameId]);
    }catch(Throwable $e){$message=(string)$e->getMessage();$err=function_exists('mb_substr')?mb_substr($message,0,1000,'UTF-8'):substr($message,0,1000);$wpdb->update($table,['status'=>'failed','updated_at'=>current_time('mysql'),'last_error'=>$err],['game_id'=>$gameId]);error_log('CKM game history PDF mail failed for game '.$gameId.': '.$err);if($attempt<3){$delay=$attempt===1?300:1800;if(!wp_next_scheduled(CKMQP_HISTORY_PDF_CRON_HOOK,[$gameId]))wp_schedule_single_event(time()+$delay,CKMQP_HISTORY_PDF_CRON_HOOK,[$gameId]);}}
}
