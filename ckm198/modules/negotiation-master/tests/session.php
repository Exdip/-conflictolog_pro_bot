<?php
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/scenario.php';

use CKM\NegotiationMaster\{Schema,Migrations,ContentMigration,SessionService,MessageService,Access,MessageRepository,SessionRepository,PlayerSessionSnapshotBuilder,OpponentService,ArbiterService};

$initialPasses = $passed;
$wpdb = new ScenarioTestDB();
$GLOBALS['test_admin'] = false; $GLOBALS['test_user'] = 7; $GLOBALS['test_tenant'] = 0; $GLOBALS['test_member'] = true;
check('NEG-SESSION fresh schema', Migrations::run());
check('NEG-SESSION content seeded', ContentMigration::run());
$scenario = $wpdb->get_row('SELECT * FROM '.Schema::table('scenarios')." WHERE slug='contract-supply'", ARRAY_A);
check('NEG-SESSION scenario available', is_array($scenario) && (int)$scenario['current_version_id'] > 0);
$scenarioId = (int)$scenario['id'];
$service = new SessionService();
$started = $service->start($scenarioId, 'training', true, false);
check('runtime session created', empty($started['active_exists']) && (int)$started['session_id'] > 0);
$sid = (int)$started['session_id'];
$raw = Access::session($sid);
check('session starts in progress', $raw['status']==='in_progress' && $raw['processing_status']==='idle' && (int)$raw['state_revision']===1 && $raw['evaluation_status']==='not_started');
check('scenario version pinned', (int)$raw['scenario_version_id']===(int)$scenario['current_version_id']);
$states = $wpdb->get_results('SELECT * FROM '.Schema::table('item_state').' WHERE session_id='.$sid.' ORDER BY id', ARRAY_A);
check('four item states initialized', count($states)===4 && count(array_filter($states,fn($r)=>$r['status']==='not_discussed'))===4);
check('no hidden facts pre-created', (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('discovered_facts').' WHERE session_id='.$sid)===0);
check('voice setting server-owned', !empty($started['snapshot']['session']['voice_enabled']));
check('all four remain unresolved', count($started['snapshot']['remaining_items'])===4);
$wire = json_encode($started['snapshot'], JSON_UNESCAPED_UNICODE);
foreach (['19500000','opponent_hidden_interests','opponent_constraints','opponent_boundary','payment_pressure','supplier_switch_risk','repeat_threshold','ТехноИмпульс'] as $secret) {
    check('session snapshot excludes '.$secret, !str_contains($wire, $secret));
}
$again = $service->start($scenarioId, 'exam', false, false);
check('active attempt returned instead of duplicate', !empty($again['active_exists']) && (int)$again['session_id']===$sid);
check('active check created no second session', (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('sessions'))===1);

$msgRepo = new MessageRepository(); $sessionRepo = new SessionRepository();
// Sending now includes the arbiter/opponent pipeline. Exercise its production
// services with deterministic offline transports instead of missing live AI.
$opponent = new OpponentService($msgRepo,$sessionRepo,transport:fn($prompts,$timeout)=>'Готов обсудить условия поставки и порядок согласования.');
$arbiter = new ArbiterService($msgRepo,transport:function($prompts,$timeout){
    $text=(string)($prompts[1]['content']??'');
    preg_match('/message_id=(\d+)/',$text,$id); preg_match('/actor=(player|opponent)/',$text,$actor);
    return json_encode(['message'=>['message_id'=>(int)($id[1]??0),'actor'=>$actor[1]??'player'],'semantic_units'=>[],'events'=>[],'fact_updates'=>[],'deal_updates'=>[],'rule_signals'=>[],'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low']],JSON_UNESCAPED_UNICODE);
});
$messages = new MessageService($msgRepo,$sessionRepo,new PlayerSessionSnapshotBuilder(),$opponent,$arbiter);
$first = $messages->send($sid, 'msg-runtime-1', 'Добрый день. Начнём с критериев выбора.', 'text');
check('first player message saved', empty($first['idempotent']) && $first['message']['channel']==='negotiation' && $first['message']['actor']==='player' && (int)$first['message']['sequence_no']===1 && (int)$first['message']['turn_no']===1);
check('neutral message preserves formal state revision', (int)$first['snapshot']['session']['state_revision']===1);
check('offline pipeline completes both message analyses', ($first['arbiter']['player']['status']??'')==='complete' && ($first['arbiter']['opponent']['status']??'')==='complete' && ($first['opponent_message']['actor']??'')==='opponent');
$duplicate = $messages->send($sid, 'msg-runtime-1', 'Добрый день. Начнём с критериев выбора.', 'text');
check('same client ID is idempotent', !empty($duplicate['idempotent']) && (int)$duplicate['message']['id']===(int)$first['message']['id']);
check('duplicate creates no player or opponent row', (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('messages').' WHERE session_id='.$sid)===2);
check('duplicate does not advance revision', (int)$duplicate['snapshot']['session']['state_revision']===(int)$first['snapshot']['session']['state_revision']);
$second = $messages->send($sid, 'msg-runtime-2', 'Какие условия кроме цены для вас важны?', 'text');
check('second player message gets next turn after opponent reply', (int)$second['message']['sequence_no']===3 && (int)$second['message']['turn_no']===2);
check('two player messages and their replies survive snapshot', count($second['snapshot']['messages'])===4 && count(array_filter($second['snapshot']['messages'],fn($row)=>$row['actor']==='player'))===2);
check('messages do not mutate item state', count(array_filter($second['snapshot']['items'],fn($r)=>$r['status']==='not_discussed'))===4);
check('messages do not reveal facts', count($second['snapshot']['discovered_facts'])===0);

$paused = $service->pause($sid);
check('pause persists', $paused['session']['status']==='paused' && !$paused['can_send']);
$resumed = $service->resume($sid);
check('resume restores same session', (int)$resumed['session']['id']===$sid && $resumed['session']['status']==='in_progress' && count($resumed['messages'])===4);

$GLOBALS['test_user']=8;
denied('other user cannot resume session', fn()=> $service->resume($sid));
$GLOBALS['test_user']=7;
$restart = $service->start($scenarioId, 'exam', false, true);
$newSid=(int)$restart['session_id'];
check('restart creates new session', $newSid>0 && $newSid!==$sid && $restart['snapshot']['session']['mode']==='exam');
$old = $wpdb->get_row('SELECT * FROM '.Schema::table('sessions').' WHERE id='.$sid, ARRAY_A);
check('restart abandons but preserves old session', $old['status']==='abandoned' && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('messages').' WHERE session_id='.$sid)===4);
check('new session has clean state', count($restart['snapshot']['messages'])===0 && count($restart['snapshot']['remaining_items'])===4);

$sessionWire=json_encode($restart['snapshot'],JSON_UNESCAPED_UNICODE);
check('no raw state_json on wire', !str_contains($sessionWire,'state_json'));
check('no opponent internals on wire', !preg_match('/opponent_(hidden|constraints|target|boundary|alternative|walkaway|concession)/', $sessionWire));

echo ($passed-$initialPasses)." NEG-SESSION checks passed; $passed total through NEG-SESSION. SQLite adapter, not MySQL.\n";
