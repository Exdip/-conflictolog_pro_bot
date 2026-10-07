<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI!=='cli') exit;
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
if (!defined('CKM_NEGOTIATION_TEST_API_ENABLED')) define('CKM_NEGOTIATION_TEST_API_ENABLED', true);

$GLOBALS['ckm192_admin']=true;
$GLOBALS['ckm192_routes']=[];
$GLOBALS['ckm192_actions']=[];

if (!function_exists('sanitize_key')) {
    function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value){ return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',(string)$value) ?? (string)$value); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag,$value){ return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value,$flags=0){ return json_encode($value,$flags); }
}
if (!function_exists('current_time')) {
    function current_time($type,$gmt=false){ return '2026-09-26 12:00:00'; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap){ return $cap==='manage_options' && !empty($GLOBALS['ckm192_admin']); }
}
if (!function_exists('add_action')) {
    function add_action($hook,$callback,$priority=10,$accepted_args=1){ $GLOBALS['ckm192_actions'][]=[$hook,$callback,$priority,$accepted_args]; return true; }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace,$route,$args,$override=false){ $GLOBALS['ckm192_routes'][]=[$namespace,$route,$args]; return true; }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(){ return '12345678-1234-4234-8234-123456789abc'; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code; public string $message; public array $data;
        public function __construct($code='',$message='',$data=[]){ $this->code=$code; $this->message=$message; $this->data=is_array($data)?$data:[]; }
        public function get_error_code(){ return $this->code; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public array $data; public int $status; public array $headers=[];
        public function __construct($data=[],$status=200){ $this->data=$data; $this->status=$status; }
        public function header($name,$value){ $this->headers[$name]=$value; }
    }
}

final class CKM192FakeWpdb {
    public string $prefix='wp_';
    public string $last_error='';
    public array $tables=[];
    private array $tx=[];
    private int $nextId=1;

    public function prepare($query,...$args){
        foreach($args as $arg){
            $value=is_int($arg)||is_float($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'";
            $query=preg_replace('/%[sdf]/',$value,$query,1);
        }
        return $query;
    }
    public function query($sql){
        $op=strtoupper(trim($sql));
        if($op==='START TRANSACTION'){ $this->tx=[$this->tables,$this->nextId]; return true; }
        if($op==='ROLLBACK'){ if($this->tx){[$this->tables,$this->nextId]=$this->tx;} $this->tx=[]; return true; }
        if($op==='COMMIT'){ $this->tx=[]; return true; }
        return true;
    }
    public function insert($table,$row){
        $this->tables[$table]??=[];
        if($this->duplicate($table,$row)){ $this->last_error='duplicate'; return false; }
        $row['id']=$row['id']??$this->nextId++;
        $this->tables[$table][]=$row;
        return 1;
    }
    public function update($table,$data,$where){
        $this->tables[$table]??=[];
        foreach($this->tables[$table] as &$row){
            if($this->matches($row,$where)){
                foreach($data as $k=>$v) $row[$k]=$v;
                return 1;
            }
        }
        return 0;
    }
    public function delete($table,$where){
        $this->tables[$table]??=[];
        $before=count($this->tables[$table]);
        $this->tables[$table]=array_values(array_filter($this->tables[$table],fn($r)=>!$this->matches($r,$where)));
        return $before-count($this->tables[$table]);
    }
    public function get_row($sql,$output=ARRAY_A){
        if(!preg_match('/FROM\s+([a-zA-Z0-9_]+).*session_id=\'([^\']*)\'/i',$sql,$m)) return null;
        $table=$m[1]; $sid=str_replace("''","'",$m[2]);
        foreach($this->tables[$table]??[] as $row) if(($row['session_id']??'')===$sid) return $row;
        return null;
    }
    public function get_var($sql){
        if(preg_match('/SELECT\s+response_json\s+FROM\s+([a-zA-Z0-9_]+).*session_id=\'([^\']*)\'.*client_request_id=\'([^\']*)\'/i',$sql,$m)){
            $table=$m[1]; $sid=str_replace("''","'",$m[2]); $rid=str_replace("''","'",$m[3]);
            foreach($this->tables[$table]??[] as $row){
                if(($row['session_id']??'')===$sid && ($row['client_request_id']??'')===$rid) return $row['response_json']??null;
            }
        }
        return null;
    }
    private function matches(array $row,array $where): bool{
        foreach($where as $k=>$v) if((string)($row[$k]??'')!==(string)$v) return false;
        return true;
    }
    private function duplicate(string $table,array $row): bool{
        foreach($this->tables[$table]??[] as $old){
            if(str_ends_with($table,'negotiation_sessions') && ($old['session_id']??'')===($row['session_id']??'')) return true;
            if(str_ends_with($table,'negotiation_turns') && ($old['session_id']??'')===($row['session_id']??'') && (int)($old['message_seq']??0)===(int)($row['message_seq']??0)) return true;
            if(str_ends_with($table,'negotiation_events') && ($old['session_id']??'')===($row['session_id']??'') && (int)($old['event_seq']??0)===(int)($row['event_seq']??0)) return true;
            if(str_ends_with($table,'negotiation_requests') && ($old['session_id']??'')===($row['session_id']??'') && ($old['client_request_id']??'')===($row['client_request_id']??'')) return true;
        }
        return false;
    }
}

$GLOBALS['wpdb']=new CKM192FakeWpdb();
$root=dirname(__DIR__);
require_once $root.'/includes/negotiation-core.php';
require_once $root.'/includes/negotiation-repository.php';
require_once $root.'/includes/negotiation-express-runtime.php';
require_once $root.'/includes/negotiation-test-api.php';

$checks=[];
function ck192($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }
function err192($value,$code){ return is_array($value) && empty($value['ok']) && ($value['code']??'')===$code; }
function state192($value){ return is_array($value) ? ($value['state']??[]) : []; }
function count192($logical){ global $wpdb; return count($wpdb->tables[ckm_negotiation_core_table($logical)]??[]); }

ck192(defined('CKM_NEGOTIATION_TEST_API_VERSION') && CKM_NEGOTIATION_TEST_API_VERSION==='negotiation_test_api_v1','test API version constant');
ck192(ckm_negotiation_test_api_enabled()===true,'explicit test API flag enables adapter in test');
ck192(ckm_negotiation_test_api_permission()===true,'administrator passes test API permission');
$GLOBALS['ckm192_admin']=false;
$denied=ckm_negotiation_test_api_permission();
ck192($denied instanceof WP_Error && $denied->get_error_code()==='forbidden','non-admin is denied');
$GLOBALS['ckm192_admin']=true;

ckm_negotiation_test_api_register_routes();
ck192(count($GLOBALS['ckm192_routes'])===7,'exactly seven test-only REST routes registered');
$routeNames=array_map(fn($r)=>$r[1],$GLOBALS['ckm192_routes']);
foreach(['/negotiation-test/start','/negotiation-test/state','/negotiation-test/voice-finished','/negotiation-test/submit','/negotiation-test/evaluate','/negotiation-test/next','/negotiation-test/finish'] as $route){
    ck192(in_array($route,$routeNames,true),'route registered '.$route);
}
foreach($GLOBALS['ckm192_routes'] as $route){
    ck192(($route[0]??'')===CKM_NEGOTIATION_TEST_API_NAMESPACE && (($route[2]['permission_callback']??'')==='ckm_negotiation_test_api_permission'),'route is namespaced and uses closed permission callback');
}

$repo=new CKM_Negotiation_Repository($GLOBALS['wpdb']);
$service=new CKM_Negotiation_Persisted_Runtime_Service($repo);
$api=new CKM_Negotiation_Test_API($service);
$cases=[
    ['id'=>'one','title'=>'Скидка','situation'=>'Клиент требует скидку.','opponent_message'=>'Дайте 20%.','duration_seconds'=>15,'max_score'=>10],
    ['id'=>'two','title'=>'Следующий шаг','situation'=>'Клиент готов продолжить.','opponent_message'=>'Что дальше?','duration_seconds'=>15,'max_score'=>10],
];

$badRuntime=$api->start(['runtime'=>'negotiation_business_v1','cases'=>$cases]);
ck192(err192($badRuntime,'runtime_not_allowed'),'start rejects every runtime except express');
$badSession=$api->start(['session_id'=>'LIVE-1','cases'=>$cases]);
ck192(err192($badSession,'test_session_required'),'start refuses non-test session id');

$createdEnvelope=$api->start([
    'session_id'=>'TEST-API-EXP-1','runtime'=>'negotiation_express_v1','cases'=>$cases,
    'now'=>'2026-09-26T12:00:00+00:00'
]);
$created=state192($createdEnvelope);
ck192(!empty($createdEnvelope['ok']) && ($created['phase']??'')==='host_speaking','test API creates persisted express session');
ck192(($created['session_id']??'')==='TEST-API-EXP-1' && ($created['state_version']??0)===1,'created state has test id and version 1');
ck192(count192('sessions')===1 && count192('events')===2,'start persists session and initial events');
$row=$GLOBALS['wpdb']->tables[ckm_negotiation_core_table('sessions')][0]??[];
ck192((int)($row['tenant_id']??-1)===0 && (int)($row['game_id']??-1)===0,'test API forcibly isolates tenant_id/game_id at zero');

$stateEnvelope=$api->state(['session_id'=>'TEST-API-EXP-1']);
ck192(!empty($stateEnvelope['ok']) && (state192($stateEnvelope)['state_version']??0)===1,'state endpoint restores persisted state');
ck192(err192($api->state(['session_id'=>'LIVE-SESSION']),'test_session_required'),'state endpoint cannot inspect live session id');

$missingVersion=$api->voice_finished([
    'session_id'=>'TEST-API-EXP-1','client_request_id'=>'voice-missing-version',
    'message_id'=>$created['voice']['message_id'],'voice_generation'=>$created['voice']['generation']
]);
ck192(err192($missingVersion,'expected_version_required'),'mutation requires expected_version');
$missingRequest=$api->voice_finished([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>1,
    'message_id'=>$created['voice']['message_id'],'voice_generation'=>$created['voice']['generation']
]);
ck192(err192($missingRequest,'client_request_id_required'),'mutation requires client_request_id');

$voiceEnvelope=$api->voice_finished([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>1,'client_request_id'=>'voice-1',
    'message_id'=>$created['voice']['message_id'],'voice_generation'=>$created['voice']['generation'],
    'now'=>'2026-09-26T12:00:03+00:00'
]);
$voice=state192($voiceEnvelope);
ck192(!empty($voiceEnvelope['ok']) && ($voice['phase']??'')==='answer_window' && ($voice['state_version']??0)===2,'voice-finished opens persisted answer window');
$countsBeforeReplay=[count192('events'),count192('requests')];
$voiceReplay=$api->voice_finished([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>1,'client_request_id'=>'voice-1',
    'message_id'=>$created['voice']['message_id'],'voice_generation'=>$created['voice']['generation'],
    'now'=>'2026-09-26T12:00:04+00:00'
]);
ck192(!empty($voiceReplay['ok']) && (state192($voiceReplay)['state_version']??0)===2,'same request id replays persisted voice response');
ck192($countsBeforeReplay===[count192('events'),count192('requests')],'request replay creates no duplicate persistence rows');

$submitEnvelope=$api->submit([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>2,'client_request_id'=>'answer-1',
    'text'=>'Давайте сравним полный пакет условий, а не только скидку.','input_mode'=>'voice','now'=>'2026-09-26T12:00:08+00:00'
]);
$submitted=state192($submitEnvelope);
ck192(!empty($submitEnvelope['ok']) && ($submitted['phase']??'')==='evaluating' && ($submitted['state_version']??0)===3,'submit persists a locked answer');
ck192(count192('turns')===1,'submit creates exactly one normalized turn');

$evalEnvelope=$api->evaluate([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>3,'client_request_id'=>'eval-1',
    'mock_evaluation'=>['total_score'=>8,'feedback'=>'Тестовая оценка','provider'=>'mock'],
    'now'=>'2026-09-26T12:00:09+00:00'
]);
$evaluated=state192($evalEnvelope);
ck192(!empty($evalEnvelope['ok']) && ($evaluated['phase']??'')==='feedback' && ($evaluated['score']['total']??0)===8,'evaluate persists mock test evaluation');

$stale=$api->next([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>3,'client_request_id'=>'next-stale','now'=>'2026-09-26T12:00:10+00:00'
]);
ck192(err192($stale,'state_conflict') && ($stale['current_version']??0)===4,'stale next is rejected with state_conflict');
$nextEnvelope=$api->next([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>4,'client_request_id'=>'next-1','now'=>'2026-09-26T12:00:10+00:00'
]);
$second=state192($nextEnvelope);
ck192(!empty($nextEnvelope['ok']) && ($second['case']['id']??'')==='two' && ($second['state_version']??0)===5,'next advances exactly one case');

$voice2=state192($api->voice_finished([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>5,'client_request_id'=>'voice-2',
    'message_id'=>$second['voice']['message_id'],'voice_generation'=>$second['voice']['generation'],'now'=>'2026-09-26T12:00:12+00:00'
]));
$submitted2=state192($api->submit([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>6,'client_request_id'=>'answer-2',
    'text'=>'Предлагаю сегодня согласовать пилот и завтра зафиксировать результаты.','now'=>'2026-09-26T12:00:20+00:00'
]));
$evaluated2=state192($api->evaluate([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>7,'client_request_id'=>'eval-2',
    'mock_evaluation'=>['total_score'=>9,'feedback'=>'Хорошо','provider'=>'mock'],'now'=>'2026-09-26T12:00:21+00:00'
]));
ck192(($evaluated2['phase']??'')==='feedback' && in_array('finish_session',$evaluated2['available_actions']??[],true),'last evaluated case exposes finish action');
$finishedEnvelope=$api->finish([
    'session_id'=>'TEST-API-EXP-1','expected_version'=>8,'client_request_id'=>'finish-1','now'=>'2026-09-26T12:00:22+00:00'
]);
$finished=state192($finishedEnvelope);
ck192(!empty($finishedEnvelope['ok']) && ($finished['status']??'')==='finished' && ($finished['state_version']??0)===9,'finish persists completed test session');
ck192(($finished['result']['total_score']??0)===17,'finish preserves accumulated result');

ck192(ckm_negotiation_test_api_status_for(['ok'=>false,'code'=>'state_conflict'])===409,'state conflict maps to HTTP 409');
ck192(ckm_negotiation_test_api_status_for(['ok'=>false,'code'=>'wrong_phase'])===422,'wrong phase maps to HTTP 422');
ck192(ckm_negotiation_test_api_status_for(['ok'=>false,'code'=>'session_not_found'])===404,'missing session maps to HTTP 404');

$source=file_get_contents($root.'/includes/negotiation-test-api.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
ck192(str_contains($source,"defined('CKM_NEGOTIATION_TEST_API_ENABLED')") && str_contains($source,': false;'),'test API is disabled by default in plugin code');
ck192(!str_contains($source,'wp_ajax_'),'test API does not create parallel AJAX surface');
ck192(str_contains($main,"includes/negotiation-test-api.php"),'plugin loads test API adapter after runtime');
ck192(str_contains($catalog,"'product'=>'negotiation_duel_v1'") && str_contains($bridge,"'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"),'real-game express routing remains legacy');
ck192(ckm_test_current_plugin_release($main),'plugin version is newer than the test-API build');

ck192($repo->delete_test_session('TEST-API-EXP-1')===true,'test session can be cleaned by repository');
ck192(count192('sessions')===0 && count192('turns')===0 && count192('events')===0 && count192('requests')===0,'cleanup removes all test API persistence rows');

echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;
