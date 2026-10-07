<?php
if (PHP_SAPI !== 'cli') { exit; }
if (!extension_loaded('pdo_sqlite')) { echo "SKIP: pdo_sqlite is unavailable; NEG-ARBITER runtime SQLite test not run.\n"; exit(0); }
require dirname(__DIR__).'/modules/negotiation-master/tests/scenario.php';
use CKM\NegotiationMaster\{Schema,Migrations,ContentMigration,SessionService,SessionRepository,MessageRepository,PlayerSessionSnapshotBuilder,OpponentService,ArbiterService,MessageService};

$startPass=$passed;
$wpdb=new ScenarioTestDB();
$GLOBALS['test_admin']=false;$GLOBALS['test_user']=7;$GLOBALS['test_tenant']=0;$GLOBALS['test_member']=true;
check('ARB runtime schema',Migrations::run());
check('ARB runtime seed',ContentMigration::run());
$scenario=$wpdb->get_row('SELECT * FROM '.Schema::table('scenarios')." WHERE slug='contract-supply'",ARRAY_A);
$started=(new SessionService())->start((int)$scenario['id'],'training',false,false);$sid=(int)$started['session_id'];
$msgRepo=new MessageRepository();$sessionRepo=new SessionRepository();$snapshot=new PlayerSessionSnapshotBuilder();
$opponentTransport=function(array $messages,int $timeout):string{
    $last=end($messages);$text=(string)($last['content']??'');
    if(str_contains($text,'Какие условия')||str_contains($text,'кроме цены')||str_contains($text,'Начнём переговоры о поставке'))return 'Для нас важны риски простоев оборудования и скорость сервисной реакции.';
    if(str_contains($text,'19,5'))return '19,5 млн ближе к нашему ориентиру, но давайте ещё обсудим пакет условий.';
    return '20,5 млн можно обсуждать, но 40% предоплаты пока не принимаем.';
};
$arbiterTransport=function(array $messages,int $timeout):string{
    $last=end($messages);$u=(string)($last['content']??'');
    preg_match('/message_id=(\d+)/',$u,$mi);preg_match('/actor=(player|opponent)/',$u,$ma);
    $id=(int)($mi[1]??0);$actor=(string)($ma[1]??'player');$text='';$pos=strpos($u,"text=");if($pos!==false)$text=substr($u,$pos+5);
    $out=['message'=>['message_id'=>$id,'actor'=>$actor],'semantic_units'=>[],'events'=>[],'fact_updates'=>[],'deal_updates'=>[],'rule_signals'=>[],'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low']];
    if($actor==='player' && (str_contains($text,'Какие условия')||str_contains($text,'кроме цены'))){
        $out['events']=[['event_type'=>'question_asked','target_type'=>'session','target_code'=>'','confidence'=>.98,'payload'=>[]],['event_type'=>'interest_probe','target_type'=>'hidden_fact','target_code'=>'service_risk','confidence'=>.95,'payload'=>[]]];
        $out['fact_updates']=[['fact_code'=>'service_risk','suggested_level'=>'partial','confidence'=>.92,'reason'=>'Игрок выясняет критерии кроме цены','public_summary'=>'Для клиента важны эксплуатационные риски и условия поддержки.','discovery_event'=>'interest_discovered']];
    }elseif($actor==='opponent' && str_contains($text,'простоев')){
        $out['events']=[['event_type'=>'position_stated','target_type'=>'session','target_code'=>'','confidence'=>.9,'payload'=>[]]];
        $out['fact_updates']=[['fact_code'=>'service_risk','suggested_level'=>'revealed','confidence'=>.97,'reason'=>'Оппонент прямо назвал риск простоев','public_summary'=>'Для клиента существенен риск простоев оборудования и скорость сервисной реакции.','discovery_event'=>'interest_discovered']];
    }elseif($actor==='player' && str_contains($text,'20,5')){
        $out['events']=[['event_type'=>'package_offer_made','target_type'=>'session','target_code'=>'','confidence'=>.98,'payload'=>[]]];
        $out['deal_updates']=[['item_code'=>'price','action'=>'proposed','value'=>20500000,'proposed_by'=>'player','bundle_key'=>'a','confidence'=>.98],['item_code'=>'prepayment','action'=>'proposed','value'=>40,'proposed_by'=>'player','bundle_key'=>'b','confidence'=>.98]];
    }elseif($actor==='player' && str_contains($text,'19,5')){
        $out['events']=[['event_type'=>'package_offer_made','target_type'=>'session','target_code'=>'','confidence'=>.98,'payload'=>[]],['event_type'=>'conditional_concession','target_type'=>'item','target_code'=>'price','confidence'=>.96,'payload'=>[]]];
        $out['deal_updates']=[['item_code'=>'price','action'=>'proposed','value'=>19500000,'proposed_by'=>'player','bundle_key'=>'a','confidence'=>.98],['item_code'=>'prepayment','action'=>'proposed','value'=>40,'proposed_by'=>'player','bundle_key'=>'b','confidence'=>.98]];
    }elseif($actor==='opponent' && str_contains($text,'40%')){
        $out['events']=[['event_type'=>'willing_to_consider','target_type'=>'item','target_code'=>'price','confidence'=>.9,'payload'=>[]]];
        $out['deal_updates']=[['item_code'=>'prepayment','action'=>'rejected','value'=>40,'proposed_by'=>'opponent','bundle_key'=>'','confidence'=>.95]];
    }
    return json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
};
$opponent=new OpponentService($msgRepo,$sessionRepo,null,null,null,$opponentTransport);
$arbiter=new ArbiterService($msgRepo,transport:$arbiterTransport);
$service=new MessageService($msgRepo,$sessionRepo,$snapshot,$opponent,$arbiter);

$r1=$service->send($sid,'arb-1','Какие условия кроме цены для вас важны?','text');
check('question turn gets opponent reply',is_array($r1['opponent_message'])&&$r1['opponent_message']['actor']==='opponent');
check('both messages analyzed complete',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('messages')." WHERE session_id=$sid AND analysis_status='complete'")===2);
$fact=$wpdb->get_row('SELECT * FROM '.Schema::table('discovered_facts')." WHERE session_id=$sid",ARRAY_A);
check('opponent direct disclosure reveals fact',(int)$fact['reveal_level']===2);
// A requested partial->full disclosure is expected. The unexpected-reveal
// diagnostic applies only when an opponent jumps from undiscovered to revealed.
check('prompted partial-to-full reveal is not unexpected',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid AND event_type='unexpected_fact_reveal'")===0);
check('prompted reveal records both discovery transitions',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid AND event_type='interest_discovered'")===2);
check('formal state revision advances',(int)$r1['snapshot']['session']['state_revision']>1);
$wire=json_encode($r1['snapshot'],JSON_UNESCAPED_UNICODE);
check('player snapshot hides hidden fact code',!str_contains($wire,'service_risk'));

$GLOBALS['test_user']=9;
$unprompted=(new SessionService())->start((int)$scenario['id'],'training',false,false);
$unpromptedSid=(int)$unprompted['session_id'];
$unpromptedReply=$service->send($unpromptedSid,'arb-unsolicited-1','Добрый день. Начнём переговоры о поставке.','text');
check('unprompted disclosure analyzes both messages',($unpromptedReply['arbiter']['player']['status']??'')==='complete'&&($unpromptedReply['arbiter']['opponent']['status']??'')==='complete');
check('unexpected reveal diagnostic saved',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$unpromptedSid AND event_type='unexpected_fact_reveal'")===1);
$GLOBALS['test_user']=7;

$unanchored=$service->send($sid,'arb-2','Предлагаю 20,5 млн при 40% предоплаты.','text');
$unanchoredPrice=$wpdb->get_row('SELECT s.status,s.current_value_json FROM '.Schema::table('item_state').' s JOIN '.Schema::table('items')." i ON i.id=s.item_id WHERE s.session_id=$sid AND i.code='price'",ARRAY_A);
check('unanchored model price does not mutate formal state',$unanchoredPrice['status']==='not_discussed'&&$unanchoredPrice['current_value_json']===null);
// Grounding guards require the player's text to identify the numeric item.
$r2=$service->send($sid,'arb-2-grounded','Предлагаю цену 20,5 млн при 40% предоплаты.','text');
$states=$wpdb->get_results('SELECT i.code,s.status,s.current_value_json,s.bundle_key FROM '.Schema::table('item_state').' s JOIN '.Schema::table('items').' i ON i.id=s.item_id WHERE s.session_id='.$sid.' ORDER BY i.sort_order',ARRAY_A);
$by=[];foreach($states as $x)$by[$x['code']]=$x;
check('package price formalized',$by['price']['status']==='proposed'&&str_contains($by['price']['current_value_json'],'20500000'));
check('package prepayment formalized',in_array($by['prepayment']['status'],['proposed','rejected'],true)&&str_contains($by['prepayment']['current_value_json'],'40'));
check('shared package event stored',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid AND event_type='package_offer_made'")>=1);

$r3=$service->send($sid,'arb-3','Тогда цена 19,5 млн при тех же 40% предоплаты.','text');
check('PHP derives conditional concession',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid AND event_type='conditional_concession'")>=1);
check('PHP derives red-line candidate',(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid AND event_type='red_line_candidate'")>=1);
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid");
$dup=$service->send($sid,'arb-3','Тогда цена 19,5 млн при тех же 40% предоплаты.','text');
$after=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('events')." WHERE session_id=$sid");
check('repeat client message does not duplicate events',$before===$after&&!empty($dup['idempotent']));

echo ($passed-$startPass)." NEG-ARBITER runtime checks passed. SQLite adapter; no live AI/MySQL.\n";
