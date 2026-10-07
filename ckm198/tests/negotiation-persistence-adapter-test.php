<?php
if (PHP_SAPI!=='cli') exit;
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
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

final class CKM191FakeWpdb {
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

$GLOBALS['wpdb']=new CKM191FakeWpdb();
$root=dirname(__DIR__);
require_once $root.'/includes/negotiation-core.php';
require_once $root.'/includes/negotiation-repository.php';
require_once $root.'/includes/negotiation-express-runtime.php';

$checks=[];
function ck191($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }
function err191($value,$code){ return is_array($value) && empty($value['ok']) && ($value['code']??'')===$code; }
function count191($logical){ global $wpdb; return count($wpdb->tables[ckm_negotiation_core_table($logical)]??[]); }

ck191(defined('CKM_NEGOTIATION_REPOSITORY_VERSION') && CKM_NEGOTIATION_REPOSITORY_VERSION==='negotiation_repository_v1','repository version constant');
$repo=new CKM_Negotiation_Repository($GLOBALS['wpdb']);
$service=new CKM_Negotiation_Persisted_Runtime_Service($repo);
$cases=[
    ['id'=>'one','title'=>'Скидка','situation'=>'Клиент требует скидку.','opponent_message'=>'Дайте 20%.','duration_seconds'=>15,'max_score'=>10],
    ['id'=>'two','title'=>'Следующий шаг','situation'=>'Клиент готов продолжить.','opponent_message'=>'Что дальше?','duration_seconds'=>15,'max_score'=>10],
];
$created=$service->create_session('negotiation_express_v1',[
    'session_id'=>'TEST-PERSIST-1','cases'=>$cases,'now'=>'2026-09-26T12:00:00+00:00'
],[
    'tenant_id'=>7,'game_id'=>42,'host_mode'=>'ai','now'=>'2026-09-26T12:00:00+00:00'
]);
ck191(($created['phase']??'')==='host_speaking','persisted test session created');
ck191(count191('sessions')===1,'session row stored');
ck191(count191('events')===2,'initial events stored');
ck191(count191('turns')===0 && count191('requests')===0,'no turn/request rows before command');
$sessionRow=$GLOBALS['wpdb']->tables[ckm_negotiation_core_table('sessions')][0]??[];
ck191(($sessionRow['tenant_id']??0)===7 && ($sessionRow['game_id']??0)===42,'tenant/game metadata stored');
ck191(is_array(json_decode((string)($sessionRow['state_json']??''),true)),'authoritative state_json stored');
ck191(is_array(json_decode((string)($sessionRow['runtime_state_json']??''),true)),'runtime_state_json stored separately');

$voice=$service->apply('TEST-PERSIST-1','voice_finished',[
    'message_id'=>$created['voice']['message_id'],
    'voice_generation'=>$created['voice']['generation'],
    'client_request_id'=>'req-voice-1',
    'expected_version'=>$created['state_version'],
    'now'=>'2026-09-26T12:00:03+00:00'
]);
ck191(($voice['phase']??'')==='answer_window' && ($voice['state_version']??0)===2,'voice transition persisted with state version increment');
ck191(count191('events')===4 && count191('requests')===1,'voice events and idempotency request stored');
$countsBeforeReplay=[count191('sessions'),count191('events'),count191('turns'),count191('requests')];
$voiceReplay=$service->apply('TEST-PERSIST-1','voice_finished',[
    'message_id'=>$created['voice']['message_id'],
    'voice_generation'=>$created['voice']['generation'],
    'client_request_id'=>'req-voice-1',
    'expected_version'=>1,
    'now'=>'2026-09-26T12:00:04+00:00'
]);
ck191(($voiceReplay['state_version']??0)===2,'persisted duplicate request replays original response');
ck191($countsBeforeReplay===[count191('sessions'),count191('events'),count191('turns'),count191('requests')],'duplicate request creates no duplicate rows');
$voiceNoop=$service->apply('TEST-PERSIST-1','voice_finished',[
    'message_id'=>$created['voice']['message_id'],
    'voice_generation'=>$created['voice']['generation'],
    'client_request_id'=>'req-voice-2',
    'expected_version'=>2,
    'now'=>'2026-09-26T12:00:05+00:00'
]);
ck191(($voiceNoop['state_version']??0)===2 && count191('events')===4,'idempotent runtime no-op keeps state/events unchanged');
ck191(count191('requests')===2,'idempotent no-op with a new request id stores replayable response');

$submitted=$service->apply('TEST-PERSIST-1','submit_answer',[
    'text'=>'Давайте сначала сравним полный пакет условий.','input_mode'=>'voice',
    'client_request_id'=>'req-answer-1','expected_version'=>2,'now'=>'2026-09-26T12:00:08+00:00'
]);
ck191(($submitted['phase']??'')==='evaluating' && ($submitted['state_version']??0)===3,'answer transition persisted');
ck191(count191('turns')===1,'player message normalized into turns table');
$turn=$GLOBALS['wpdb']->tables[ckm_negotiation_core_table('turns')][0]??[];
ck191(($turn['message_seq']??0)===1 && ($turn['actor']??'')==='team','turn message_seq and actor stored');
ck191(count191('events')===5 && count191('requests')===3,'answer event and request stored');

$evaluated=$service->apply('TEST-PERSIST-1','evaluate_turn',[],[
    'mock_evaluation'=>['total_score'=>8,'feedback'=>'Тест','provider'=>'mock']
]);
ck191(($evaluated['phase']??'')==='feedback' && ($evaluated['state_version']??0)===4,'evaluation persisted');
ck191(count191('events')===8,'evaluation appends exactly three events');
$restored=$service->state('TEST-PERSIST-1');
ck191(($restored['phase']??'')==='feedback' && ($restored['score']['total']??0)===8,'full state restores from state_json');
ck191(($restored['answer']['text']??'')===($submitted['answer']['text']??''),'accepted answer survives restore');

$conflict=$service->apply('TEST-PERSIST-1','next_case',[
    'client_request_id'=>'req-next-conflict','expected_version'=>3,'now'=>'2026-09-26T12:00:10+00:00'
]);
ck191(err191($conflict,'state_conflict') && ($conflict['current_version']??0)===4,'stale expected_version rejected before mutation');
ck191(count191('requests')===3 && count191('events')===8,'conflict does not write request/events');

$next=$service->apply('TEST-PERSIST-1','next_case',[
    'client_request_id'=>'req-next-1','expected_version'=>4,'now'=>'2026-09-26T12:00:10+00:00'
]);
ck191(($next['case']['id']??'')==='two' && ($next['state_version']??0)===5,'next case persisted once');
ck191(count191('events')===9 && count191('requests')===4,'next-case event and request persisted');

$schema=file_get_contents($root.'/includes/standalone-schema.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$repositorySource=file_get_contents($root.'/includes/negotiation-repository.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
ck191(str_contains($schema,"const CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16';"),'schema version bumped for persistence snapshot');
ck191(str_contains($schema,'state_json longtext NULL'),'sessions schema has authoritative state_json');
ck191(str_contains($schema,'message_seq bigint unsigned NOT NULL DEFAULT 0') && str_contains($schema,'UNIQUE KEY session_message_seq (session_id,message_seq)'),'turns schema has idempotent message sequence');
ck191(str_contains($main,"includes/negotiation-repository.php"),'plugin loads repository before runtime');
ck191(!str_contains($repositorySource,'register_rest_route(') && !str_contains($repositorySource,'wp_ajax_'),'persistence adapter exposes no public route');
ck191(str_contains($catalog,"'product'=>'negotiation_duel_v1'") && str_contains($bridge,"'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"),'legacy real-game routing remains unchanged');

ck191($repo->delete_test_session('TEST-PERSIST-1')===true,'test session cleanup allowed');
ck191(count191('sessions')===0 && count191('turns')===0 && count191('events')===0 && count191('requests')===0,'cleanup removes all persisted test rows');
ck191($repo->delete_test_session('LIVE-SESSION-1')===false,'cleanup refuses non-test session id');

echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;
