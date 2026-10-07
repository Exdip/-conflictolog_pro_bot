<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function check4($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function actor4($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function act4($s,$slot,$cmd,$now,$extra=[]){return ckmqp_show_reduce($s,actor4($slot),array_merge(['command'=>$cmd,'round'=>$s['round'],'attempt'=>$s['attempt'],'question_index'=>$s['storyQuestionIndex']??0],$extra),$now);}
// Completed first three rounds at the current 70-point-per-round ceiling.
$s=ckmqp_show_initial();$s['round']=2;$s['attempt']=2;$s['phase']='round3_complete';
foreach([0,1,2] as $a){$slot=$a+1;$s['reviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot],'opponent'=>['total'=>70,'slot'=>(($slot)%3)+1]],'total'=>70,'rubricVersion'=>3]];$s['hiddenReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot],'opponent'=>['total'=>70,'slot'=>(($slot)%3)+1]],'total'=>70,'rubricVersion'=>3]];$s['hardReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot]],'total'=>70,'rubricVersion'=>3]];}
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>2,'attempt'=>2],100);$s=$r['session'];check4($r['ok']&&$s['round']===3&&$s['phase']==='preparation'&&count($s['storyModes'])===3,'one host action starts final with three secret modes');
check4(count(array_unique($s['storyModes']))===2,'mode pattern includes truth and distortion');
// Force known mode for scoring test.
$s['storyModes'][0]='distortion';
$r=act4($s,1,'ready',101);check4(!$r['ok']&&($r['code']??'')==='ready_not_needed','final needs no repeated readiness');
check4($s['deadline']===0,'story preparation is untimed');
$still=ckmqp_show_tick($s,1000);check4($still['phase']==='preparation'&&$still['deadline']===0,'preparation never auto-expires');
$r=act4($s,2,'story_ready',150);check4(!$r['ok']&&($r['code']??'')==='active_team_required','only storyteller may declare readiness');
$s=act4($s,1,'story_ready',151)['session'];check4($s['phase']==='story_tell'&&$s['deadline']===0,'story starts only after storyteller readiness and is untimed by default');
$still=ckmqp_show_tick($s,5000);check4($still['phase']==='story_tell'&&$still['deadline']===0,'untimed story never auto-expires');
$r=act4($s,2,'story_submit',152,['text'=>'Чужая история','request_id'=>'story_wrong_team_001']);check4(!$r['ok']&&($r['code']??'')==='active_team_required','only storyteller may submit story');
$s=act4($s,1,'story_submit',153,['text'=>'Мы приехали самолётом в Казань и на следующий день встретились с клиентом.','request_id'=>'story_team_1_000001'])['session'];check4($s['phase']==='story_questions'&&$s['deadline']===0,'submitted story opens cross-examination');
$r=act4($s,1,'story_question',152,['text'=>'Сам себе вопрос?','request_id'=>'story_self_question01']);check4(!$r['ok']&&($r['code']??'')==='opponent_required','storyteller cannot ask own questions');
// Two opponents, two questions each.
$s=act4($s,2,'story_question',153,['text'=>'Во сколько началась встреча?','request_id'=>'story_q_team2_00001'])['session'];
$s=act4($s,2,'story_question',154,['text'=>'Сколько длился пилот?','request_id'=>'story_q_team2_00002'])['session'];
$r=act4($s,2,'story_question',155,['text'=>'Третий вопрос','request_id'=>'story_q_team2_00003']);check4(!$r['ok']&&($r['code']??'')==='question_limit','each opponent limited to two questions');
$s=act4($s,3,'story_question',156,['text'=>'Как вы добирались до Казани?','request_id'=>'story_q_team3_00001'])['session'];
$s=act4($s,3,'story_question',157,['text'=>'Когда вы уехали обратно?','request_id'=>'story_q_team3_00002'])['session'];check4($s['phase']==='story_answer'&&$s['storyQuestionIndex']===0&&$s['deadline']===0,'fourth question opens untimed answer by default');
$order=ckmqp_show_story_question_order($s,0);check4(count($order)===4&&$order[0]['slot']===2&&$order[1]['slot']===3&&$order[2]['slot']===2&&$order[3]['slot']===3,'questions are interleaved between opponents');
// Four answers; the last one times out.
$s=act4($s,1,'story_answer',160,['question_index'=>0,'text'=>'В 10:30.','request_id'=>'story_answer_000001'])['session'];check4($s['storyQuestionIndex']===1&&$s['deadline']===0,'answer 1 advances without timer by default');
$s=act4($s,1,'story_answer',161,['question_index'=>1,'text'=>'Самолётом.','request_id'=>'story_answer_000002'])['session'];
$s=act4($s,1,'story_answer',162,['question_index'=>2,'text'=>'Четырнадцать дней.','request_id'=>'story_answer_000003'])['session'];
$s['storyAnswerLimit']=30;$s['storyAnswerPolicyConfigured']=true;$s['deadline']=192;$s=ckmqp_show_tick($s,193);check4($s['phase']==='story_vote'&&!empty($s['storyAnswers'][0][3]['timedOut']),'optional 30-second answer limit still times out into vote');
// Storyteller cannot vote; first opponent correct, second wrong.
$r=act4($s,1,'story_vote',184,['vote'=>'distortion']);check4(!$r['ok']&&($r['code']??'')==='opponent_required','storyteller cannot vote');
$s=act4($s,2,'story_vote',185,['vote'=>'distortion'])['session'];check4($s['phase']==='story_vote'&&!isset($s['storyResults'][0]),'first vote stays unresolved and hidden');
$s=act4($s,3,'story_vote',186,['vote'=>'truth'])['session'];check4($s['phase']==='review'&&is_array($s['storyResults'][0]),'second vote resolves story');
$r=$s['storyResults'][0];check4(!array_key_exists('storytellerPoints',$r)&&!array_key_exists('voterPoints',$r),'vote result no longer carries legacy 100-point scoring');
check4(ckmqp_show_story_round_score($s,1)===null&&ckmqp_show_story_round_score($s,2)===null,'final-round score waits for dedicated seven-criterion review');
// The vote result and the rubric review are separate: votes resolve the story, the review supplies up to 70 points for the storyteller.
$s['storyReviews'][0]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>1]],'total'=>70,'rubricVersion'=>4],'completedAt'=>186];
check4(ckmqp_show_story_round_score($s,1)===70&&ckmqp_show_story_round_score($s,2)===null,'storyteller receives the dedicated 70-point review score only');
// One host action advances to the next storyteller.
$r=act4($s,1,'continue',187);check4(!$r['ok']&&($r['code']??'')==='confirmation_removed','team confirmation removed in final');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>3,'attempt'=>0],188);$s=$r['session'];check4($r['ok']&&$s['attempt']===1&&$s['phase']==='preparation'&&$s['deadline']===0,'second storyteller starts with untimed preparation');
// Directly seed remaining story reviews at the current 70-point ceiling.
$s['storyResults'][1]=['attempt'=>1,'storytellerSlot'=>2,'mode'=>'truth','modeLabel'=>'Соответствует досье','votes'=>[]];
$s['storyResults'][2]=['attempt'=>2,'storytellerSlot'=>3,'mode'=>'truth','modeLabel'=>'Соответствует досье','votes'=>[]];
$s['storyReviews'][1]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>2]],'total'=>70,'rubricVersion'=>4],'completedAt'=>200];
$s['storyReviews'][2]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>3]],'total'=>70,'rubricVersion'=>4],'completedAt'=>201];
check4(ckmqp_show_story_round_score($s,2)===70&&ckmqp_show_story_round_score($s,3)===70,'each storyteller can receive up to 70 in the final round');
// Project final complete and verify the current 280-point ceiling.
$s['attempt']=2;$s['phase']='game_complete';
$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'finished','host_mode_snapshot'=>'ai'];$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams,$s);check4($o['negotiationShow']['scores'][1]['score']<=280,'overall score stays within the current 280-point ceiling');
// Secret mode is not exposed to opponent before reveal.
$secret=ckmqp_show_initial();$secret['round']=3;$secret['storyModes']=['distortion','truth','truth'];$secret['attempt']=0;$secret['phase']='preparation';
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams,$secret);check4(!isset($o['negotiationShow']['storyReveal'])&&!str_contains((string)$o['negotiationShow']['brief'],'самолётом'),'opponent never receives closed dossier before reveal');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams,$secret);check4(str_contains((string)$o['negotiationShow']['brief'],'СЕКРЕТНЫЙ РЕЖИМ'),'storyteller receives secret dossier');
// Legacy state upgrade.
$legacy=['version'=>1,'revision'=>1,'round'=>2,'attempt'=>0,'phase'=>'waiting','deadline'=>0,'ready'=>[],'next'=>[],'roundNext'=>[],'paused'=>false,'remaining'=>0,'messages'=>[],'reviews'=>[],'hiddenReviews'=>[],'hardAnswers'=>[],'hardReviews'=>[],'questionIndex'=>0];$legacy=ckmqp_show_normalize_state($legacy);check4(isset($legacy['storyModes'],$legacy['storyStories'],$legacy['storyQuestions'],$legacy['storyAnswers'],$legacy['storyVotes'],$legacy['storyResults'],$legacy['storyQuestionIndex'],$legacy['storyLimit']),'legacy rooms upgrade with final-round fields');
// Optional story limit remains available for organizers.
$limited=ckmqp_show_initial();$limited['round']=3;$limited['attempt']=0;$limited['phase']='preparation';$limited['storyModes']=['truth','distortion','truth'];$limited['storyLimit']=90;
$limited=act4($limited,1,'story_ready',500)['session'];check4($limited['phase']==='story_tell'&&$limited['deadline']===590,'optional 90-second story limit starts only after storyteller readiness');
echo "ALL $n PASS\n";
