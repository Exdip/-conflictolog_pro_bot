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
$n=0;function ok175($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n).' '.$label."\n";}
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
ok175(str_contains($js,'function quickActionRequest(promise,ms=12000)'), 'quick action timeout helper exists');
ok175(str_contains($js,"command==='review'?await request:await quickActionRequest(request,12000)"), 'ordinary show actions use timeout but AI review does not');
ok175(str_contains($js,'setTimeout(()=>poll(),120)'), 'failed or timed-out action triggers state reconciliation poll');
$s=ckmqp_show_initial();
$s['round']=3;$s['attempt']=0;$s['phase']='story_vote';$s['deadline']=0;$s['storyModes']=['distortion','truth','truth'];
$s['storyStories'][0]=['text'=>'История','at'=>100,'requestId'=>'story_submit_0000001','timedOut'=>false];
$s['storyQuestions'][0][2]=[
 ['text'=>'Q1','at'=>101,'requestId'=>'story_q_b_00000001'],
 ['text'=>'Q3','at'=>103,'requestId'=>'story_q_b_00000002']
];
$s['storyQuestions'][0][3]=[
 ['text'=>'Q2','at'=>102,'requestId'=>'story_q_c_00000001'],
 ['text'=>'Q4','at'=>104,'requestId'=>'story_q_c_00000002']
];
$s['storyAnswers'][0]=[
 ['questionIndex'=>0,'text'=>'','at'=>131,'requestId'=>'','timedOut'=>true],
 ['questionIndex'=>1,'text'=>'','at'=>161,'requestId'=>'','timedOut'=>true],
 ['questionIndex'=>2,'text'=>'','at'=>191,'requestId'=>'','timedOut'=>true],
 ['questionIndex'=>3,'text'=>'','at'=>221,'requestId'=>'','timedOut'=>true],
];
$b=['role'=>'participant','slot'=>2,'team_id'=>102];
$c=['role'=>'participant','slot'=>3,'team_id'=>103];
$r1=ckmqp_show_reduce($s,$b,['command'=>'story_vote','round'=>3,'attempt'=>0,'vote'=>'distortion'],230);
ok175($r1['ok'] && count($r1['session']['storyVotes'][0])===1, 'first secret vote persists');
$rdup=ckmqp_show_reduce($r1['session'],$b,['command'=>'story_vote','round'=>3,'attempt'=>0,'vote'=>'distortion'],231);
ok175($rdup['ok'] && !empty($rdup['duplicate']) && count($rdup['session']['storyVotes'][0])===1, 'same vote retry is idempotent');
$rconf=ckmqp_show_reduce($r1['session'],$b,['command'=>'story_vote','round'=>3,'attempt'=>0,'vote'=>'truth'],232);
ok175(!$rconf['ok'] && ($rconf['code']??'')==='vote_locked', 'opposite retry cannot overwrite recorded vote');
$r2=ckmqp_show_reduce($r1['session'],$c,['command'=>'story_vote','round'=>3,'attempt'=>0,'vote'=>'distortion'],233);
ok175($r2['ok'] && count($r2['session']['storyVotes'][0])===2, 'second team vote persists independently');
ok175(($r2['session']['phase']??'')==='review' && is_array($r2['session']['storyResults'][0]??null), 'two votes complete deterministic story result');
echo "ALL $n PASS\n";
