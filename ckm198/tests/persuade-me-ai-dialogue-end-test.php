<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
$n=0;function ck165($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}

function base165($at=100){$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=0;$s['phase']='dialogue';$s['dialogueStartedAt']=$at;$s['lastDialogueActivityAt']=$at;return $s;}
function msg165(&$s,$id,$team,$slot,$text,$at){$s['messages'][]=['id'=>$id,'teamId'=>$team,'slot'=>$slot,'round'=>1,'attempt'=>0,'text'=>$text,'at'=>$at,'requestId'=>'t_'.$id];$s['lastDialogueActivityAt']=$at;}

$s=base165(100);
msg165($s,1,101,1,'Когда вам удобно встретиться?',101);
msg165($s,2,102,2,'Предлагаю вторник в 16:00.',102);
msg165($s,3,101,1,'Спасибо. Альтернатива — четверг в 11:00.',103);
msg165($s,4,103,3,'Четверг в 11:00 подходит, договорились.',104);
$s=ckmqp_show_ai_dialogue_advance($s,104,'message');
ck165($s['phase']==='dialogue' && (int)$s['aiDialogueNaturalAt']===104,'natural completion starts grace window instead of immediate close');
$s=ckmqp_show_ai_dialogue_advance($s,113,'poll');
ck165($s['phase']==='dialogue','natural completion stays open for first nine seconds');
$s=ckmqp_show_ai_dialogue_advance($s,114,'poll');
ck165($s['phase']==='review' && $s['dialogueClosedBy']==='ai_natural','natural completion closes after ten silent seconds');

$s=base165(200);
$s=ckmqp_show_ai_dialogue_advance($s,224,'poll');
ck165((int)$s['aiDialogueIdlePromptedAt']===0 && $s['phase']==='dialogue','no silence prompt before 25 seconds');
$s=ckmqp_show_ai_dialogue_advance($s,225,'poll');
ck165((int)$s['aiDialogueIdlePromptedAt']===225 && (int)$s['aiDialogueIdlePromptSeq']===1,'25 seconds silence creates one prompt');
$s=ckmqp_show_ai_dialogue_advance($s,239,'poll');
ck165($s['phase']==='dialogue','prompt leaves 15-second reply window');
$s=ckmqp_show_ai_dialogue_advance($s,240,'poll');
ck165($s['phase']==='review' && $s['dialogueClosedBy']==='ai_idle','15 more silent seconds close dialogue');

$s=base165(300);$s=ckmqp_show_ai_dialogue_advance($s,325,'poll');
msg165($s,1,101,1,'Я добавлю один момент по срокам.',330);
$s=ckmqp_show_ai_dialogue_advance($s,330,'message');
ck165((int)$s['aiDialogueIdlePromptedAt']===0 && $s['phase']==='dialogue','new message cancels silence prompt');
$s=ckmqp_show_ai_dialogue_advance($s,354,'poll');
ck165((int)$s['aiDialogueIdlePromptedAt']===0,'silence clock restarts from new message');
$s=ckmqp_show_ai_dialogue_advance($s,355,'poll');
ck165((int)$s['aiDialogueIdlePromptedAt']===355 && (int)$s['aiDialogueIdlePromptSeq']===2,'later silence can create a fresh prompt');

$dialogue=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-dialogue.php');
$manual=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-manual-review.php');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
$voice=file_get_contents(dirname(__DIR__).'/includes/standalone-voice.php');
ck165(str_contains($dialogue,'negotiation_show_dialogue_idle_prompt')&&str_contains($dialogue,'через 15 секунд'),'AI host publishes spoken idle prompt');
ck165(str_contains($voice,'negotiation_show_dialogue_idle_prompt'),'voice bridge speaks idle prompt');
ck165(!str_contains($manual,'Обсудите оценку ведущего перед переходом.'),'manual review no longer asks teams to discuss host score');
ck165(str_contains($js,"String(r.recommendation||'').trim()!==''"),'empty manual recommendation is not rendered');
ck165(str_contains($dialogue,"dialogueClosedBy']='message_limit") || str_contains($dialogue,"dialogueClosedBy\']=\'message_limit"),'hard message limit remains immediate close path');


$auto=ckmqp_show_initial();$auto['round']=1;$auto['attempt']=0;$auto['phase']='review';$auto['hiddenReviews'][0]=['status'=>'done','review'=>['total'=>300],'completedAt'=>100];
$stay=ckmqp_show_ai_dialogue_advance($auto,104,'poll');ck165(($stay['phase']??'')==='review','AI host keeps result visible during short review grace');
$go=ckmqp_show_ai_dialogue_advance($auto,105,'poll');ck165(($go['phase']??'')==='dialogue'&&(int)$go['attempt']===1&&(int)$go['deadline']===0,'AI host advances after review directly into untimed dialogue');
echo "ALL $n PASS\n";
