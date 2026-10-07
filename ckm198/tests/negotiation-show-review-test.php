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
$n=0;function check($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}

$messages=[
 ['id'=>1,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Мне важно закончить отчёт сегодня. Предлагаю: вы присылаете таблицу до 16:00, а я беру на себя проверку.','at'=>100,'requestId'=>'m1'],
 ['id'=>2,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>102,'text'=>'До 16:00 не успею, у меня ещё два запроса.','at'=>101,'requestId'=>'m2'],
 ['id'=>3,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Понимаю загрузку. Тогда два варианта: черновик к 16:00 или готовая таблица к 18:00.','at'=>102,'requestId'=>'m3'],
 ['id'=>4,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>102,'text'=>'Черновик к 16:00 подходит, финальную версию пришлю к 18:00.','at'=>103,'requestId'=>'m4'],
];
$keys=array_keys(ckmqp_show_review_criteria());
function participant($role,$points,$quoteId,$quote,$bonus=false,$penalty=false){
 global $keys;
 $criteria=[];foreach($keys as $k)$criteria[$k]=['points'=>$points,'reason'=>'Обоснование '.$k,'evidence'=>[['messageId'=>$quoteId,'quote'=>$quote]]];
 return [
  'criteria'=>$criteria,
  'bonuses'=>[
   'bridge'=>['awarded'=>$bonus,'reason'=>$bonus?'Есть взаимовыгодный вариант':'Не проявлено','evidence'=>$bonus?[['messageId'=>$quoteId,'quote'=>$quote]]:[]],
   'admit_error'=>['awarded'=>false,'reason'=>'Не проявлено','evidence'=>[]],
   'humor'=>['awarded'=>false,'reason'=>'Не проявлено','evidence'=>[]],
  ],
  'penalties'=>[
   'personal_attack'=>['applied'=>$penalty,'reason'=>$penalty?'Есть переход на личности':'Не выявлено','evidence'=>$penalty?[['messageId'=>$quoteId,'quote'=>$quote]]:[]],
   'ultimatum'=>['applied'=>false,'reason'=>'Не выявлено','evidence'=>[]],
   'fact_manipulation'=>['applied'=>false,'reason'=>'Не выявлено','evidence'=>[]],
  ],
  'summary'=>'Краткий итог '.$role,
  'recommendation'=>'Конкретная рекомендация '.$role,
 ];
}
$valid=['participants'=>[
 'speaker'=>participant('speaker',8,3,'Понимаю загрузку. Тогда два варианта: черновик к 16:00 или готовая таблица к 18:00.',true,false),
 'opponent'=>participant('opponent',7,4,'Черновик к 16:00 подходит, финальную версию пришлю к 18:00.',false,false),
],'summary'=>'Оба участника пришли к конкретному варианту.','winner'=>['type'=>'fake'],'total'=>9999];
$r=ckmqp_show_validate_review($valid,$messages,0,$err);
check(is_array($r),'seven-criterion review validates');
check($r['participants']['speaker']['baseTotal']===56,'speaker base is seven criteria x 8');
check(!isset($r['participants']['speaker']['bonusTotal'])&&!isset($r['participants']['speaker']['bonuses'])&&$r['participants']['speaker']['total']===56,'bonus metadata is ignored and total equals seven criteria');
check($r['participants']['opponent']['baseTotal']===49&&$r['participants']['opponent']['total']===49,'opponent scored independently');
check($r['winner']['type']==='speaker'&&$r['winner']['margin']===7,'server ignores model winner and chooses higher seven-criterion score');
check($r['total']===56,'legacy total projects speaker without trusting model total');

$tie=$valid;foreach($tie['participants']['opponent']['criteria'] as &$c)$c['points']=8;unset($c);
$t=ckmqp_show_validate_review($tie,$messages,0,$err);check($t['participants']['opponent']['total']===56&&$t['winner']['type']==='mutual'&&$t['winner']['margin']===0,'difference <=3 is mutual victory');

$max=$valid;foreach(['speaker','opponent'] as $role){foreach($max['participants'][$role]['criteria'] as &$c)$c['points']=10;unset($c);foreach($max['participants'][$role]['bonuses'] as &$b){$b['awarded']=true;$b['evidence']=[['messageId'=>$role==='speaker'?3:4,'quote'=>$role==='speaker'?'Понимаю загрузку. Тогда два варианта: черновик к 16:00 или готовая таблица к 18:00.':'Черновик к 16:00 подходит, финальную версию пришлю к 18:00.']];}unset($b);}
$m=ckmqp_show_validate_review($max,$messages,0,$err);check($m['participants']['speaker']['baseTotal']===70&&!isset($m['participants']['speaker']['bonusTotal'])&&$m['participants']['speaker']['total']===70,'maximum participant score is 70 with seven criteria only');

$bad=$valid;$bad['participants']['speaker']['criteria']['respect']['points']=11;check(ckmqp_show_validate_review($bad,$messages,0,$err)===null,'criterion above 10 rejected');
$bad=$valid;$bad['participants']['speaker']['criteria']['respect']['points']='8';check(ckmqp_show_validate_review($bad,$messages,0,$err)===null,'string score rejected by strict validator');
$normalized=ckmqp_show_normalize_ai_review($bad,$messages);check(ckmqp_show_validate_review($normalized,$messages,0,$err)!==null,'harmless numeric-string representation normalized');
$bad=$valid;$bad['participants']['speaker']['bonuses']['bridge']['evidence']=[['messageId'=>4,'quote'=>'Черновик к 16:00 подходит, финальную версию пришлю к 18:00.']];$ignored=ckmqp_show_validate_review($bad,$messages,0,$err);check(is_array($ignored)&&$ignored['participants']['speaker']['total']===56,'legacy bonus evidence is ignored');
$bad=$valid;$bad['participants']['speaker']['bonuses']['bridge']['evidence']=[];$ignored=ckmqp_show_validate_review($bad,$messages,0,$err);check(is_array($ignored)&&$ignored['participants']['speaker']['total']===56,'legacy awarded bonus cannot change score');

// Round 1 cumulative score uses both roles for every team.
$s=ckmqp_show_initial();$s['reviews']=[];
function reviewed($sp,$op,$spSlot,$opSlot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$sp,'slot'=>$spSlot],'opponent'=>['total'=>$op,'slot'=>$opSlot]],'total'=>$sp,'rubricVersion'=>2]];}
$s['reviews'][0]=reviewed(58,49,1,2);
$s['reviews'][1]=reviewed(60,55,2,3);
$s['reviews'][2]=reviewed(50,57,3,1);
check(ckmqp_show_round1_team_score($s,1)===58,'team 1 averages speaker and opponent roles');
check(ckmqp_show_round1_team_score($s,2)===55,'team 2 averages speaker and opponent roles');
check(ckmqp_show_round1_team_score($s,3)===53,'team 3 averages speaker and opponent roles');

// AI transport remains a single arbiter call when valid.
function ckm_quiz_pro_solution_price_aipuffer_rest_key(){return 'test-key';}
function ckm_quiz_pro_solution_price_ai_settings(){return ['provider'=>'test','model'=>'test-model'];}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function ckm_quiz_pro_aipuffer_endpoint(){return 'https://example.test/aipuffer';}
function wp_remote_post($url,$args){$GLOBALS['calls'][]=$args;return ['code'=>200,'body'=>json_encode(['content'=>json_encode($GLOBALS['aiReview'])])];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
$aiReview=$valid;$calls=[];$state=ckmqp_show_initial();$state['phase']='review';$state['messages']=$messages;
$out=ckmqp_show_ai_review($state,0);check(($out['review']['speakerTotal']??null)===56,'single AI arbiter result accepted');
check(count($calls)===1,'valid dialogue uses one AI request, not two judges');
$payload=json_decode($calls[0]['body'],true);$system=$payload['messages'][0]['content']??'';check(str_contains($system,'ОБОИХ участников')&&str_contains($system,'request_specificity')&&str_contains($system,'разрыв ≤ 3')&&!str_contains($system,'Бонусы')&&!str_contains($system,'Штрафы'),'prompt contains only two-sided seven-criterion rubric and tie rule');

echo "ALL $n PASS\n";
