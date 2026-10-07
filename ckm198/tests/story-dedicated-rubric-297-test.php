<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
require dirname(__DIR__).'/includes/negotiation-show-review.php';
$n=0;function t297($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$expected=['story_clarity','task_fidelity','factual_consistency','answer_directness','answer_grounding','version_stability','communication_quality'];
t297(array_keys(ckmqp_show_story_review_criteria())===$expected,'final story round has its own seven criteria');
t297(count(array_intersect($expected,array_keys(ckmqp_show_review_criteria())))===0,'story rubric does not reuse ordinary negotiation criterion keys');
$messages=[
 ['id'=>40000,'slot'=>1,'text'=>'Лера просит сначала перепроверить работу по ключу варианта Б и только затем решать вопрос об оценке.','requestId'=>'story-main-0'],
 ['id'=>40001,'slot'=>2,'text'=>'Вы требуете сразу изменить оценку?','requestId'=>'story-question-0-0'],
 ['id'=>40002,'slot'=>1,'text'=>'Нет. Я прошу сначала сверить работу с правильным ключом, а окончательный вывод сделать после проверки.','requestId'=>'story-answer-0-0'],
];
$criteria=[];
foreach($expected as $k){
 $answerOnly=in_array($k,['answer_directness','answer_grounding','version_stability'],true);
 $criteria[$k]=['points'=>10,'reason'=>'Критерий проявлен','evidence'=>[[$answerOnly?'messageId':'messageId'=>$answerOnly?40002:40000,'quote'=>$answerOnly?'Я прошу сначала сверить работу с правильным ключом':'Лера просит сначала перепроверить работу по ключу варианта Б']]];
}
$err=null;$v=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>['criteria'=>$criteria]]],$messages,0,$err,2);
t297(is_array($v),'dedicated story review validates: '.($err??''));
t297(($v['speakerTotal']??0)===70&&($v['rubricVersion']??0)===4,'dedicated rubric keeps 70-point ceiling and version 4');
$ordinary=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$ordinary[$k]=['points'=>10,'reason'=>'Old rubric','evidence'=>[['messageId'=>40000,'quote'=>'Лера просит сначала перепроверить работу']]];
$err=null;$old=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>['criteria'=>$ordinary]]],$messages,0,$err,2);
t297($old===null&&str_contains((string)$err,'story_clarity'),'old negotiation rubric is rejected for new story assessments');
$src=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-review.php');
t297(str_contains($src,'Не используй критерии обычных переговорных раундов')&&str_contains($src,'1 story_clarity — Ясность и структура истории'),'AI prompt explicitly uses dedicated story methodology');
$storySrc=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-story.php');
t297(str_contains($storySrc,'Режим distortion: рассказчик обязан выполнить ровно предписанное искажение')&&str_contains($storySrc,"roundRubric']='story_seven_v2'"),'AI receives hidden truth/distortion task and story rubric marker');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
t297(str_contains($js,"story_clarity:'Ясность и структура истории'")&&str_contains($js,"version_stability:'Устойчивость версии под проверкой'"),'UI shows dedicated criterion names');
echo "ALL $n PASS\n";
