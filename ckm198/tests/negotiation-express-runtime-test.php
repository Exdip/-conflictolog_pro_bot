<?php
if (PHP_SAPI!=='cli') exit;
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag,$value){ return $value; }
}
$GLOBALS['wpdb']=(object)['prefix'=>'wp_'];

$root=dirname(__DIR__);
require_once $root.'/includes/negotiation-core.php';
require_once $root.'/includes/negotiation-express-runtime.php';

$checks=[];
function ck190($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }
function err190($value,$code){ return is_array($value) && empty($value['ok']) && ($value['code']??'')===$code; }

ck190(defined('CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION') && CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION==='negotiation_express_v1','express runtime version constant');
ck190(CKM_Negotiation_Runtime_Registry::has('negotiation_express_v1'),'express runtime registered');
$runtime=CKM_Negotiation_Runtime_Registry::get('negotiation_express_v1');
ck190($runtime instanceof CKM_Negotiation_Runtime_Interface,'express runtime implements core contract');

$cases=[
    ['id'=>'pressure','title'=>'Скидка','situation'=>'Клиент требует немедленную скидку.','opponent_message'=>'Либо скидка 20%, либо мы уходим.','task'=>'Сохраните инициативу.','duration_seconds'=>15,'max_score'=>10],
    ['id'=>'next-step','title'=>'Следующий шаг','situation'=>'Оппонент готов продолжить.','opponent_message'=>'Что вы предлагаете сделать сейчас?','duration_seconds'=>15,'max_score'=>10],
];
$state=$runtime->create_session(['session_id'=>'TEST-EXP-1','cases'=>$cases,'now'=>'2026-09-26T12:00:00+00:00']);
ck190(($state['status']??'')==='active' && ($state['phase']??'')==='host_speaking','test session starts in host_speaking');
ck190(($state['case']['id']??'')==='pressure' && ($state['case']['index']??0)===1,'first case loaded');
ck190(empty($state['timer']['enabled']) && !empty($state['timer']['starts_after_voice']) && empty($state['answer']['allowed']),'voice gate blocks timer and answer initially');
ck190(err190($runtime->submit_player_message($state,['text'=>'Ответ раньше голоса','now'=>'2026-09-26T12:00:01+00:00']),'wrong_phase'),'answer before voice is rejected');

$old=$runtime->advance_phase($state,['action'=>'voice_finished','message_id'=>$state['voice']['message_id'],'voice_generation'=>0,'now'=>'2026-09-26T12:00:02+00:00']);
ck190(err190($old,'voice_generation_mismatch'),'stale voice generation rejected');

$voice=$runtime->advance_phase($state,[
    'action'=>'voice_finished','message_id'=>$state['voice']['message_id'],'voice_generation'=>$state['voice']['generation'],'now'=>'2026-09-26T12:00:03+00:00'
]);
ck190(($voice['phase']??'')==='answer_window' && !empty($voice['timer']['enabled']) && !empty($voice['answer']['allowed']),'voice finish opens timed answer window');
ck190(strtotime((string)$voice['timer']['deadline_at'])-strtotime((string)$voice['timer']['started_at'])===15,'deadline starts exactly after voice finish');
$voiceReplay=$runtime->advance_phase($voice,[
    'action'=>'voice_finished','message_id'=>$voice['voice']['message_id'],'voice_generation'=>$voice['voice']['generation'],'now'=>'2026-09-26T12:00:04+00:00'
]);
ck190(($voiceReplay['state_version']??0)===($voice['state_version']??-1),'duplicate voice_finished is idempotent');

$submitted=$runtime->submit_player_message($voice,[
    'text'=>'Правильно понимаю, что цена — единственный критерий? Если да, предлагаю сравнить пакет условий.','input_mode'=>'voice','client_request_id'=>'req-1','now'=>'2026-09-26T12:00:08+00:00'
]);
ck190(($submitted['phase']??'')==='evaluating' && !empty($submitted['answer']['locked']),'first answer accepted and locked');
$versionAfterSubmit=(int)$submitted['state_version'];
$replayed=$runtime->submit_player_message($submitted,[
    'text'=>'Другая реплика','input_mode'=>'voice','client_request_id'=>'req-1','now'=>'2026-09-26T12:00:09+00:00'
]);
ck190(($replayed['state_version']??0)===$versionAfterSubmit && ($replayed['answer']['text']??'')===($submitted['answer']['text']??''),'same client_request_id does not apply twice');
ck190(err190($runtime->submit_player_message($submitted,[
    'text'=>'Вторая новая попытка','client_request_id'=>'req-2','now'=>'2026-09-26T12:00:09+00:00'
]),'wrong_phase'),'second answer cannot overwrite accepted answer');

$evaluated=$runtime->evaluate_turn($submitted,['mock_evaluation'=>[
    'total_score'=>7,'criteria'=>['reaction_accuracy'=>2,'argument_strength'=>2,'initiative'=>1,'pressure_resistance'=>2],
    'strengths'=>['Сохраняет инициативу'],'improvements'=>['Можно быстрее назвать следующий шаг'],'feedback'=>'Тестовая оценка','provider'=>'mock'
]]);
ck190(($evaluated['phase']??'')==='feedback' && ($evaluated['evaluation']['total_score']??0)===7,'mock AI evaluation produces feedback');
ck190(($evaluated['score']['total']??0)===7 && ($evaluated['runtime_state']['completed_cases']??0)===1,'score and completed case counter updated');
ck190(in_array('next_case',$evaluated['available_actions']??[],true),'next_case available after first feedback');

$second=$runtime->advance_phase($evaluated,['action'=>'next_case','now'=>'2026-09-26T12:00:10+00:00']);
ck190(($second['case']['id']??'')==='next-step' && ($second['turn_number']??0)===2 && ($second['phase']??'')==='host_speaking','next case advances exactly once');
ck190(empty($second['answer']['locked']) && empty($second['timer']['enabled']) && ($second['voice']['generation']??0)>($voice['voice']['generation']??0),'new case resets answer/timer and increments voice generation');

$voice2=$runtime->advance_phase($second,['action'=>'voice_finished','message_id'=>$second['voice']['message_id'],'voice_generation'=>$second['voice']['generation'],'now'=>'2026-09-26T12:00:12+00:00']);
$late=$runtime->submit_player_message($voice2,['text'=>'Слишком поздно','client_request_id'=>'req-late','now'=>'2026-09-26T12:00:30+00:00']);
ck190(err190($late,'timer_expired'),'server deadline rejects late answer');

$submitted2=$runtime->submit_player_message($voice2,['text'=>'Предлагаю сегодня согласовать пилот и завтра зафиксировать результаты.','client_request_id'=>'req-2a','now'=>'2026-09-26T12:00:20+00:00']);
$evaluated2=$runtime->evaluate_turn($submitted2,['mock_evaluation'=>['total_score'=>8,'feedback'=>'Хороший следующий шаг','provider'=>'mock']]);
ck190(!empty($runtime->check_completion($evaluated2)['complete']) && in_array('finish_session',$evaluated2['available_actions']??[],true),'last feedback marks session complete without hiding feedback');
$finished=$runtime->advance_phase($evaluated2,['action'=>'finish_session','now'=>'2026-09-26T12:00:21+00:00']);
ck190(($finished['status']??'')==='finished' && !empty($finished['is_finished']) && ($finished['result']['total_score']??0)===15,'session finishes with accumulated score');
ck190(err190($runtime->submit_player_message($finished,['text'=>'После финиша']),'session_finished'),'submit rejected after finish');

$restored=$runtime->restore_session($finished);
ck190(($restored['state_version']??0)===($finished['state_version']??-1) && ($restored['result']['total_score']??0)===15,'restore keeps authoritative state intact');
$events=(array)($finished['runtime_state']['events']??[]);
$seq=array_column($events,'event_seq');
$types=array_column($events,'event_type');
ck190($seq===range(1,count($seq)),'event sequence is contiguous');
foreach(['session_created','express_case_started','host_voice_finished','timer_started','player_message_submitted','ai_turn_started','turn_evaluated','express_case_completed','session_completed'] as $type){
    ck190(in_array($type,$types,true),'event log contains '.$type);
}

$main=file_get_contents($root.'/ckm-quiz-pro.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
$formatRuntime=file_get_contents($root.'/core-source/includes/quiz/quiz-format-runtime.php');
ck190(str_contains($main,"includes/negotiation-express-runtime.php"),'plugin loads express runtime');
ck190(str_contains($catalog,"'product'=>'negotiation_duel_v1'"),'existing paid catalog still points to legacy negotiation runtime');
ck190(str_contains($bridge,"'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"),'games hub express route remains legacy');
ck190(str_contains($formatRuntime,"'runtime'=>'negotiation_duel_v1'"),'format runtime route remains legacy');
ck190(!str_contains(file_get_contents($root.'/includes/negotiation-express-runtime.php'),'register_rest_route('),'express runtime has no public REST route yet');

echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;
