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
$n=0;function ok244($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function p244($points,$bonus=false,$quoteId=null,$quote=''){
    $criteria=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$criteria[$k]=['points'=>$points,'reason'=>'Проверка '.$k,'evidence'=>[]];
    return ['criteria'=>$criteria,'summary'=>'Итог','recommendation'=>'Совет'];
}
function pStory244($storyId,$storyQuote,$answerId,$answerQuote){
    $criteria=[];
    foreach(array_keys(ckmqp_show_story_review_criteria()) as $k){
        $answerOnly=in_array($k,['answer_directness','answer_grounding','version_stability'],true);
        $criteria[$k]=['points'=>10,'reason'=>'Проверка '.$k,'evidence'=>[['messageId'=>$answerOnly?$answerId:$storyId,'quote'=>$answerOnly?$answerQuote:$storyQuote]]];
    }
    return ['criteria'=>$criteria,'summary'=>'Итог','recommendation'=>'Совет'];
}
function reviewed244($sp,$op,$spSlot,$opSlot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$sp,'slot'=>$spSlot],'opponent'=>['total'=>$op,'slot'=>$opSlot]],'total'=>$sp,'rubricVersion'=>3]];}

// Round 1: average two role evaluations, never exceed 70.
$s=ckmqp_show_initial();
$s['reviews']=[reviewed244(70,64,1,2),reviewed244(66,70,2,3),reviewed244(68,70,3,1)];
ok244(ckmqp_show_round1_team_score($s,1)===70,'round 1 uses mean of speaker/opponent roles');
ok244(ckmqp_show_round1_team_score($s,2)===65,'round 1 mean rounds half-up');
ok244(ckmqp_show_round1_team_score($s,3)===69,'round 1 stays within 70');

// Round 2: same seven-criterion validator and same role averaging.
$hiddenMessages=[
 ['id'=>10,'round'=>1,'attempt'=>0,'slot'=>1,'text'=>'Предлагаю два варианта и готов учесть ваши ограничения.'],
 ['id'=>11,'round'=>1,'attempt'=>0,'slot'=>2,'text'=>'Подходит второй вариант, зафиксируем срок до пятницы.'],
];
$raw=['participants'=>['speaker'=>p244(10,true,10,'Предлагаю два варианта'),'opponent'=>p244(10,true,11,'Подходит второй вариант')],'summary'=>'Диалог'];
$hv=ckmqp_show_validate_hidden_review($raw,$hiddenMessages,0,$s,$he);
ok244(is_array($hv)&&$hv['speakerTotal']===70&&$hv['opponentTotal']===70,'round 2 uses identical seven-criterion rubric');
$s['hiddenReviews']=[reviewed244(70,70,1,2),reviewed244(70,70,2,3),reviewed244(70,70,3,1)];
ok244(ckmqp_show_hidden_round_score($s,1)===70,'round 2 ceiling is 70');

// Round 3: questions are context; only active team's speaker score enters game score.
$h=ckmqp_show_initial();$h['round']=2;$h['attempt']=0;$h['hardAnswers'][0]=[
 ['text'=>'Понимаю сомнение. Предлагаю два варианта и фиксирую первый шаг на завтра.','timedOut'=>false],
 ['text'=>'Мне важно сохранить срок, поэтому беру проверку на себя.','timedOut'=>false],
 ['text'=>'Если этот вариант не подходит, скорректируем объём и согласуем новый срок.','timedOut'=>false],
];
$hm=ckmqp_show_hard_rubric_messages($h,0);$speakerQuote=$hm[1]['text'];$opponentQuote=$hm[0]['text'];
$hr=['participants'=>['speaker'=>p244(10,true,$hm[1]['id'],$speakerQuote),'opponent'=>p244(0,false)],'summary'=>'Ответы'];
$hv3=ckmqp_show_validate_hard_review($hr,$h,0,$herr);
ok244(is_array($hv3)&&$hv3['participants']['speaker']['total']===70&&!empty($hv3['suppressWinner']),'round 3 uses seven-criterion rubric and scores active team only');
$h['hardReviews'][0]=['status'=>'done','review'=>$hv3];
ok244(ckmqp_show_hard_round_score($h,1)===70,'round 3 ceiling is 70');

// Round 4: vote result has no score fields; storyteller uses a dedicated seven-criterion story rubric.
$t=ckmqp_show_initial();$t['round']=3;$t['attempt']=0;$t['storyModes'][0]='truth';
$t['storyStories'][0]=['text'=>'Мы согласовали план и предложили два варианта.','at'=>1];
$t['storyQuestions'][0]=[2=>[['text'=>'Когда будет готово?'],['text'=>'Что если срок сорвётся?']],3=>[['text'=>'Кто отвечает?'],['text'=>'Есть другой вариант?']]];
$t['storyAnswers'][0]=[
 ['text'=>'Готово будет в пятницу, отвечаю я.'],['text'=>'Если риск вырастет, уменьшим объём.'],['text'=>'Ответственность беру на себя.'],['text'=>'Да, второй вариант — перенести часть работ.']
];
$t['storyVotes'][0]=[2=>['vote'=>'truth'],3=>['vote'=>'distortion']];
$sr=ckmqp_show_story_result($t,0);
ok244(is_array($sr)&&!array_key_exists('storytellerPoints',$sr)&&!array_key_exists('voterPoints',$sr),'round 4 voting no longer carries 100-point scoring');
$t['storyResults'][0]=$sr;$sm=ckmqp_show_story_rubric_messages($t,0);
$speakerStory=current(array_filter($sm,fn($m)=>str_starts_with((string)($m['requestId']??''),'story-main-')));
$speakerAnswer=current(array_filter($sm,fn($m)=>str_starts_with((string)($m['requestId']??''),'story-answer-')));
$storyRaw=['participants'=>['speaker'=>pStory244($speakerStory['id'],$speakerStory['text'],$speakerAnswer['id'],$speakerAnswer['text'])],'summary'=>'История'];
$storyValidated=ckmqp_show_validate_speaker_only_review($storyRaw,$sm,0,$serr,3);$storyValidated['displayRoles']=['speaker'];$storyValidated['suppressWinner']=true;$storyValidated['roundRubric']='story_seven_v2';
$t['storyReviews'][0]=['status'=>'done','review'=>$storyValidated,'completedAt'=>10];
ok244(ckmqp_show_story_round_score($t,1)===70&&($storyValidated['rubricVersion']??0)===4,'round 4 dedicated story rubric ceiling is 70');

// Final 280 ceiling across all four rounds.
$final=ckmqp_show_initial();$final['round']=3;$final['attempt']=2;$final['phase']='game_complete';
$final['reviews']=[reviewed244(70,70,1,2),reviewed244(70,70,2,3),reviewed244(70,70,3,1)];
$final['hiddenReviews']=[reviewed244(70,70,1,2),reviewed244(70,70,2,3),reviewed244(70,70,3,1)];
foreach([0,1,2] as $a){$slot=$a+1;$final['hardReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot]],'total'=>70]];$final['storyReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot]],'total'=>70]];$final['storyResults'][$a]=['attempt'=>$a,'storytellerSlot'=>$slot,'mode'=>'truth','modeLabel'=>'Соответствует досье','votes'=>[]];}
ok244(ckmqp_show_story_cumulative_score($final,1)===280,'overall maximum is 280');

// Overall winner rule: only the exact top score wins; only an exact tie shares first place.
$final['hardReviews'][1]['review']['participants']['speaker']['total']=68; // team 2 => 278
$final['storyReviews'][2]['review']['participants']['speaker']['total']=66; // team 3 => 276
$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'finished','host_mode_snapshot'=>'ai'];$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'scoreboard'],$teams,$final);
ok244($o['negotiationShow']['scores'][0]['score']===280&&$o['negotiationShow']['scores'][1]['score']===278&&$o['negotiationShow']['scores'][2]['score']===276,'final scores combine three negotiation rubrics and one dedicated story rubric');
ok244($o['negotiationShow']['winners']===['Команда A'],'overall winner is the unique exact top score');
$final['hardReviews'][1]['review']['participants']['speaker']['total']=70; // team 2 => 280 exact tie
$oTie=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'scoreboard'],$teams,$final);
ok244($oTie['negotiationShow']['winners']===['Команда A','Команда B'],'exact overall tie shares first place');
ok244(($oTie['negotiationShow']['winnerMarginRule']??null)===0,'overall winner margin rule is exact tie only');

// Final round cannot advance on vote result alone; AI rubric result is required.
$gate=ckmqp_show_initial();$gate['round']=3;$gate['attempt']=0;$gate['phase']='review';$gate['storyResults'][0]=['attempt'=>0,'storytellerSlot'=>1,'mode'=>'truth','modeLabel'=>'Соответствует досье','votes'=>[]];
$blocked=ckmqp_show_reduce($gate,['role'=>'host'],['command'=>'advance','round'=>3,'attempt'=>0],100);
ok244(empty($blocked['ok'])&&($blocked['code']??'')==='advance_unavailable','round 4 waits for dedicated seven-criterion story review');
$gate['storyReviews'][0]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>50,'slot'=>1]],'total'=>50],'completedAt'=>99];
$advanced=ckmqp_show_reduce($gate,['role'=>'host'],['command'=>'advance','round'=>3,'attempt'=>0],100);
ok244(!empty($advanced['ok'])&&$advanced['session']['attempt']===1,'round 4 advances after dedicated story review');

echo "ALL $n PASS\n";
