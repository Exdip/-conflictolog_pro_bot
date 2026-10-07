<?php
// Isolated PHP/SQLite integration tests; never include from WordPress/web requests.
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/run.php';
use CKM\NegotiationMaster\{Schema,Migrations,ContentMigration,ScenarioDiagnostics,ScenarioRepository,Health};

class ScenarioTestDB extends TestDB {
    public $failTable = ''; public $failMarker = false;
    public function query($sql) {
        if ($sql === 'START TRANSACTION') { $sql = 'BEGIN TRANSACTION'; }
        if ($this->failMarker && str_contains($sql, "'ckm_neg_content_version','" . CKM_NEG_CONTENT_VERSION . "'")) { $this->last_error = 'injected marker failure'; return false; }
        return parent::query($sql);
    }
    public function get_var($sql) { $this->last_error = ''; return parent::get_var(str_replace(' FOR UPDATE', '', $sql)); }
    public function get_row($sql, $mode = null) { $this->last_error = ''; return parent::get_row($sql, $mode); }
    public function get_results($sql, $mode = null) { $this->last_error = ''; return parent::get_results($sql, $mode); }
    public function insert($table, $data) {
        if ($table === $this->failTable) { $this->last_error = 'injected write failure'; return false; }
        return parent::insert($table, $data);
    }
}
function counts() {
    global $wpdb; $result=[];
    foreach (['libraries','scenarios','scenario_versions','items','hidden_facts','rules','evaluation_rules'] as $key) { $result[$key] = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table($key)); }
    return $result;
}
function expectedContentCounts(array $pack): array {
    // Importer correctness follows the authored manifest, rather than freezing
    // the original three-scenario 1.2 catalog after new libraries are released.
    $result = ['libraries'=>count($pack['libraries']), 'scenarios'=>count($pack['scenarios'])+count($pack['scenario_stubs'] ?? []), 'scenario_versions'=>count($pack['scenarios'])];
    foreach (['items','hidden_facts','rules','evaluation_rules'] as $key) {
        $result[$key] = array_sum(array_map(fn($entry)=>(int)$entry['expected'][$key],$pack['scenarios']));
    }
    return $result;
}
function contentSnapshot() {
    global $wpdb; $result=[];
    foreach (array_keys(counts()) as $key) { $result[$key] = $wpdb->get_results('SELECT * FROM '.Schema::table($key).' ORDER BY id', ARRAY_A); }
    return $result;
}
$initialPasses = $passed;
$wpdb = new ScenarioTestDB();
$GLOBALS['test_admin'] = true; $GLOBALS['test_user']=1; $GLOBALS['test_tenant']=0;
check('content waits for schema', !ContentMigration::run());
check('fresh schema installed', Migrations::run());
check('schema does not prematurely stamp content', get_option('ckm_neg_content_version') === '1.0.0');
$lease=Migrations::acquire();
check('content respects competing migration lease', !ContentMigration::run());
check('busy lease inserts nothing', array_sum(counts()) === 0);
Migrations::release($lease);
$wpdb->failTable=Schema::table('rules');
check('partial content failure contained', !ContentMigration::run());
check('partial scenario and components rolled back', array_sum(counts()) === 0);
check('failed seed retains previous content version', get_option('ckm_neg_content_version') === '1.0.0');
check('failed content lease released', get_option('ckm_neg_migration_lock','') === '');
$wpdb->failTable='';
check('content retry succeeds', ContentMigration::run());
$pack = require dirname(__DIR__) . '/content/system-v1.php';
$expectedCounts = expectedContentCounts($pack);
$contract = array_values(array_filter($pack['scenarios'],fn($entry)=>$entry['scenario']['slug']==='contract-supply'))[0];
check('exact current manifest content counts', counts() === $expectedCounts);
check('content version advances independently', get_option('ckm_neg_content_version') === CKM_NEG_CONTENT_VERSION && get_option('ckm_neg_db_version') === CKM_NEG_DB_VERSION);
$scenario=$wpdb->get_row('SELECT * FROM '.Schema::table('scenarios')." WHERE slug='contract-supply'", ARRAY_A);
$version=$wpdb->get_row('SELECT * FROM '.Schema::table('scenario_versions').' WHERE id='.(int)$scenario['current_version_id'], ARRAY_A);
$vid=(int)$version['id'];
check('system scenario uses NULL tenant', $scenario['tenant_id'] === null && $scenario['slug'] === 'contract-supply');
check('published current version points to own scenario', (int)$scenario['current_version_id'] === $vid && (int)$version['scenario_id'] === (int)$scenario['id'] && (int)$version['version_number']===(int)$contract['version']['version_number'] && $version['status']==='published');
$versionIds=array_map('intval',$wpdb->get_col('SELECT id FROM '.Schema::table('scenario_versions').' ORDER BY id'));
$weightsOk=true;foreach($versionIds as $testVid){if((float)$wpdb->get_var('SELECT SUM(weight) FROM '.Schema::table('evaluation_rules').' WHERE scenario_version_id='.$testVid)!==100.0){$weightsOk=false;break;}}
check('each scenario criteria sum is 100', $weightsOk);
check('required four item codes', $wpdb->get_col('SELECT code FROM '.Schema::table('items').' WHERE scenario_version_id='.$vid.' ORDER BY sort_order') === ['price','prepayment','service_months','delivery_days']);
$snapshot=contentSnapshot();
for($i=0;$i<3;$i++){ check('repeat seed '.$i, ContentMigration::run()); }
check('repeat seed preserves IDs timestamps and contents', contentSnapshot() === $snapshot);
// Simulate interruption after commit but before stamping the content option.
update_option('ckm_neg_content_version','1.0.0');
check('recovery verifies committed rows', ContentMigration::run());
check('recovery inserts no duplicates or updates', contentSnapshot() === $snapshot);
ScenarioDiagnostics::register();
check('diagnostic GET route is admin-only', $GLOBALS['route'][1] === '/negotiation/scenarios/health' && $GLOBALS['route'][2]['permission_callback'] === [Health::class,'permission']);
$response=ScenarioDiagnostics::response();
check('scenario diagnostic OK', $response->status === 200 && count($response->data['scenarios'])===count($pack['scenarios']));
check('diagnostic exact requested summary', $response->data['scenarios'][0] === ['scenario'=>'Контракт на поставку','version'=>(int)$contract['version']['version_number'],'status'=>'published','items'=>4,'hidden_facts'=>4,'rules'=>6,'evaluation_criteria'=>6,'evaluation_weight'=>100.0,'current_version'=>'valid']);
check('diagnostic includes universal MVP scenarios', !array_diff(['Контракт на поставку','Проект под давлением','Трудный разговор с коллегой'],array_column($response->data['scenarios'],'scenario')));
$diagnostic=json_encode($response->data);
check('diagnostic contains no opponent secrets', !str_contains($diagnostic,'opponent_') && !str_contains($diagnostic,'competitor_offer') && !str_contains($diagnostic,'payment_pressure'));
$GLOBALS['test_admin']=false;
check('ordinary user diagnostic denied', ScenarioDiagnostics::response()->data['status']===403);
$GLOBALS['test_user']=0;
check('guest diagnostic denied', ScenarioDiagnostics::response()->data['status']===401);
$GLOBALS['test_user']=7;
$repo=new ScenarioRepository();
$player=$repo->playerVersion($vid);$items=$repo->playerItems($vid);
check('player can read published system scenario', $repo->get((int)$scenario['id'])['title']==='Контракт на поставку');
check('player reads four dimensions', count($items)===4);
$allowed=['id','scenario_id','version_number','player_role','player_situation','player_task','player_known_facts_json','player_ideal_result_json','player_target_result_json','player_alternative_json','player_red_lines_json','opponent_name','opponent_role'];
check('player version strictly whitelisted', !array_diff(array_keys($player),$allowed));
$allowedItems=['code','title','value_type','unit','required_for_agreement','sort_order'];
foreach($items as $row){ check('item '.$row['code'].' has no secret fields', !array_diff(array_keys($row),$allowedItems)); }
$wire=json_encode([$player,$items],JSON_UNESCAPED_UNICODE);
foreach(['ТехноИмпульс','opponent_constraints','opponent_hidden','opponent_boundary','opponent_target','payment_pressure','supplier_switch_risk','repeat_threshold','19500000'] as $secret){check('player payload excludes '.$secret,!str_contains($wire,$secret));}
denied('player denied full opponent version', fn()=>$repo->adminVersion($vid));
foreach(['hidden_facts','items','rules','evaluation_rules'] as $kind){denied('player denied raw '.$kind,fn()=>$repo->adminComponents($vid,$kind));}
$GLOBALS['test_user']=0;
denied('guest player API denied',fn()=>$repo->playerVersion($vid));
$GLOBALS['test_admin']=true;$GLOBALS['test_user']=1;
// Runtime reads DB, not the installation file: change one public field in the test DB.
$wpdb->query('UPDATE '.Schema::table('scenario_versions')." SET player_task='Database source sentinel' WHERE id=$vid");
check('player API reads DB content', $repo->playerVersion($vid)['player_task']==='Database source sentinel');
update_option('ckm_neg_content_version','1.0.0');
$changed=contentSnapshot();
check('published content conflict rejected',!ContentMigration::run());
check('conflict does not overwrite published version', contentSnapshot()===$changed);
check('failed verification does not advance version',get_option('ckm_neg_content_version')==='1.0.0');
$wpdb->query($wpdb->prepare('UPDATE '.Schema::table('scenario_versions').' SET player_task=%s WHERE id=%d',$snapshot['scenario_versions'][0]['player_task'],$vid));
check('restored content passes verification',ContentMigration::run());
$wpdb->query('UPDATE '.Schema::table('scenarios').' SET current_version_id=999 WHERE id='.(int)$scenario['id']);
check('bad current version detected',ScenarioDiagnostics::response()->status===503);
$wpdb->query('UPDATE '.Schema::table('scenarios')." SET current_version_id=$vid WHERE id=".(int)$scenario['id']);
$wpdb->query('UPDATE '.Schema::table('evaluation_rules')." SET weight=26 WHERE code='economic_result' AND scenario_version_id=$vid");
check('invalid total weight detected',ScenarioDiagnostics::response()->status===503);
$wpdb->query('UPDATE '.Schema::table('evaluation_rules')." SET weight=25 WHERE code='economic_result' AND scenario_version_id=$vid");
check('read-only diagnostics leave data unchanged',contentSnapshot()===$snapshot);
// Duplicate NULL-tenant slug must fail closed, not choose a random global row.
$dupe=$snapshot['scenarios'][0];unset($dupe['id']);$wpdb->insert(Schema::table('scenarios'),$dupe);
update_option('ckm_neg_content_version','1.0.0');
check('ambiguous global slug rejected',!ContentMigration::run());
$wpdb->query('DELETE FROM '.Schema::table('scenarios').' WHERE id='.(int)$wpdb->insert_id);
// Fresh install, marker-write failure after commit, then retry.
$wpdb = new ScenarioTestDB();Migrations::run();$wpdb->failMarker=true;
check('marker failure reported',!ContentMigration::run());
check('marker failure does not lose committed data',counts()===$expectedCounts);
$committed=contentSnapshot();$wpdb->failMarker=false;
check('marker retry succeeds',ContentMigration::run());
check('marker retry creates no duplicates',contentSnapshot()===$committed);
update_option('ckm_neg_content_version','9.0.0');
check('newer content never downgraded',!ContentMigration::run() && get_option('ckm_neg_content_version')==='9.0.0');
echo ($passed-$initialPasses)." NEG-SCENARIO checks passed; $passed total with NEG-CORE. SQLite adapter, not MySQL.\n";
