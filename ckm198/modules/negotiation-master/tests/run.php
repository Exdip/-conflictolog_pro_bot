<?php
// Run from CLI only. This harness uses SQLite and WordPress stubs, never a live site.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/fixtures/');
define('CKM_QUIZ_PRO_FILE', dirname(__DIR__, 3) . '/ckm-quiz-pro.php');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['test_user'] = 1; $GLOBALS['test_tenant'] = 0; $GLOBALS['test_admin'] = true;
$GLOBALS['test_member'] = true; $GLOBALS['hooks'] = []; $GLOBALS['ddl_count'] = 0;
$GLOBALS['fail_ddl'] = false;
function get_current_user_id() { return $GLOBALS['test_user']; }
function current_user_can($cap) { return $GLOBALS['test_admin']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function ckmqp_scope_id() { return $GLOBALS['test_tenant']; }
function ckmqp_tenant_is_member($tenant, $user) { return $GLOBALS['test_member']; }
function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
function wp_cache_delete(...$args) {}
function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['hooks'][$hook][] = $callback; return true; }
function register_activation_hook($file, $callback) { add_action('activate', $callback); }
function register_rest_route($ns, $route, $config) { $GLOBALS['route'] = [$ns, $route, $config]; }
class WP_Error { public function __construct(public $code, public $message, public $data) {} }
class WP_REST_Response { public function __construct(public $data, public $status, public $headers = []) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }

class TestDB {
    public $prefix = 'qa42_'; public $options = 'qa42_options'; public $last_error = ''; public $insert_id = 0;
    public $hidden_errors = false; public PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE qa42_options (option_name TEXT PRIMARY KEY, option_value TEXT)');
    }
    public function prepare($query, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string)(int)$v : $this->pdo->quote((string)$v); }, $query);
    }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function suppress_errors($v) { $old = $this->hidden_errors; $this->hidden_errors = $v; return $old; }
    public function query($sql) {
        $this->last_error = '';
        try { return $this->pdo->exec($sql); } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_var($sql) {
        if (str_starts_with($sql, 'SHOW TABLES LIKE ')) { $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE " . substr($sql, 17) . " ESCAPE '\\'"; }
        $value = $this->pdo->query($sql)->fetchColumn();
        return $value === false ? null : $value;
    }
    public function get_col($sql) {
        if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $sql, $m)) { return array_column($this->pdo->query('PRAGMA table_info(' . $m[1] . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'); }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function get_results($sql, $mode = null) {
        if (preg_match('/SHOW INDEX FROM `([^`]+)`/', $sql, $m)) {
            $rows = [['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0]];
            foreach ($this->pdo->query('PRAGMA index_list(' . $m[1] . ')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $rows[] = ['Key_name' => substr($index['name'], strlen($m[1]) + 1), 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name'], 'Non_unique' => !$index['unique']];
                }
            }
            return $rows;
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get_row($sql, $mode = null) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function insert($table, $data) {
        $this->last_error = '';
        try {
            $q = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
            $this->pdo->prepare($q)->execute(array_values($data));
            $this->insert_id = (int)$this->pdo->lastInsertId(); return 1;
        } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
}
$wpdb = new TestDB();
function get_option($name, $default = false) { global $wpdb; $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name)); return $v === null ? $default : $v; }
function add_option($name, $value, $deprecated = '', $autoload = false) { global $wpdb; return $wpdb->insert($wpdb->options, ['option_name'=>$name,'option_value'=>$value]) !== false; }
function update_option($name, $value, $autoload = false) { global $wpdb; return $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} VALUES (%s,%s) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value", $name, $value)) !== false; }
function delete_option($name) { global $wpdb; return $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s", $name)); }
function dbDelta($sql) {
    global $wpdb;
    $GLOBALS['ddl_count']++;
    if ($GLOBALS['fail_ddl']) { $wpdb->last_error = 'simulated DDL denial'; return; }
    if (!preg_match('/CREATE TABLE (\w+) \(\n(.*)\n\) ENGINE=/s', $sql, $m)) { throw new RuntimeException('Invalid DDL'); }
    $columns = []; $indexes = [];
    foreach (explode(",\n", $m[2]) as $line) {
        if (preg_match('/^(UNIQUE )?KEY (\w+) \(([^)]+)\)/', $line, $i)) { $indexes[] = 'CREATE ' . $i[1] . 'INDEX IF NOT EXISTS ' . $m[1] . '_' . $i[2] . ' ON ' . $m[1] . ' (' . $i[3] . ')'; continue; }
        if (str_starts_with($line, 'PRIMARY KEY')) { continue; }
        if (str_starts_with($line, 'id ')) { $line = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; }
        else { $line = preg_replace('/(?:bigint|int|tinyint) unsigned/i', 'INTEGER', $line); }
        $columns[] = $line;
    }
    $wpdb->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $m[1] . ' (' . implode(',', $columns) . ')');
    foreach ($indexes as $index) { $wpdb->pdo->exec($index); }
    $wpdb->last_error = '';
}
require dirname(__DIR__) . '/bootstrap.php';
use CKM\NegotiationMaster\{Schema,Migrations,Access,Health,ScenarioRepository,SessionRepository,MessageRepository,EventRepository,AgreementRepository,EvaluationRepository};
$passed = 0;
function check($name, $ok) { global $passed; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } $passed++; echo 'PASS: ' . $name . "\n"; }
function denied($name, $fn) { $thrown = false; try { $fn(); } catch (Throwable $e) { $thrown = true; } check($name, $thrown); }
check('17 isolated table definitions', count(Schema::spec()) === 17);
check('uses custom prefix', Schema::table('sessions') === 'qa42_ckm_neg_sessions');
denied('rejects SQL identifier injection', fn() => Schema::table('sessions; DROP TABLE users'));
check('migration succeeds', Migrations::run());
check('all 17 tables/columns/unique indexes healthy', Schema::healthy(Schema::inspect()));
check('separate version markers', get_option('ckm_neg_db_version') === CKM_NEG_DB_VERSION && get_option('ckm_neg_content_version') === '1.0.0');
$count = $GLOBALS['ddl_count'];
check('repeat upgrade is idempotent', Migrations::run() && $count === $GLOBALS['ddl_count']);
check('lease released after success', get_option('ckm_neg_migration_lock', '') === '');
$token = Migrations::acquire();
check('concurrent lease refused', $token !== null && Migrations::acquire() === null);
Migrations::release('not-owner');
check('wrong owner cannot unlock', get_option('ckm_neg_migration_lock') === $token);
Migrations::release($token);
update_option('ckm_neg_migration_lock', '1:expired');
$new = Migrations::acquire();
check('expired lease replaced', $new !== null && $new !== '1:expired');
Migrations::release('1:expired');
check('stale worker cannot delete replacement lease', get_option('ckm_neg_migration_lock') === $new);
Migrations::release($new);
Health::register();
check('only health GET endpoint', $GLOBALS['route'][0] === 'ckm/v1' && $GLOBALS['route'][1] === '/negotiation/health' && $GLOBALS['route'][2]['methods'] === 'GET');
check('administrator health OK', Health::response()->status === 200);
$GLOBALS['test_admin'] = false;
check('non-admin health denied', Health::permission()->data['status'] === 403);
$GLOBALS['test_user'] = 0;
check('anonymous health denied', Health::permission()->data['status'] === 401);
denied('anonymous context denied', fn() => Access::context());
$GLOBALS['test_user'] = 7; $GLOBALS['test_tenant'] = -1;
denied('unknown tenant denied', fn() => Access::context());
$GLOBALS['test_tenant'] = 3; $GLOBALS['test_member'] = false;
denied('non-member tenant denied', fn() => Access::context());
$GLOBALS['test_member'] = true;
$now = current_time('mysql');
$wpdb->insert(Schema::table('scenarios'), ['tenant_id'=>3,'slug'=>'fixture','title'=>'Fixture','status'=>'published','created_by'=>7,'created_at'=>$now,'updated_at'=>$now]);
$scenario = $wpdb->insert_id;
$wpdb->insert(Schema::table('scenario_versions'), ['scenario_id'=>$scenario,'version_number'=>1,'status'=>'published','player_task'=>'Task','opponent_hidden_interests_json'=>'{"secret":true}','created_at'=>$now]);
$version = $wpdb->insert_id;
$sr = new ScenarioRepository();
check('player version excludes secrets', !array_key_exists('opponent_hidden_interests_json', $sr->playerVersion($version)));
denied('non-admin cannot read full version', fn() => $sr->adminVersion($version));
$sessions = new SessionRepository(); $session = $sessions->create($version);
check('server-owned identity', $sessions->get($session)['participant_key'] === 'user:7');
check('state JSON never exposed', !array_key_exists('state_json', $sessions->get($session)));
$GLOBALS['test_user'] = 8;
denied('other user session denied', fn() => $sessions->get($session));
$GLOBALS['test_user'] = 7; $GLOBALS['test_tenant'] = 4;
denied('other tenant session denied', fn() => $sessions->get($session));
denied('other tenant scenario denied', fn() => $sr->get($scenario));
$GLOBALS['test_tenant'] = 3; $GLOBALS['test_admin'] = true;
check('CAS state update', $sessions->updateState($session, 1, ['secret'=>1]));
check('stale state revision rejected', !$sessions->updateState($session, 1, ['secret'=>2]));
$messages = new MessageRepository();
$data = ['sequence_no'=>1,'channel'=>'dialogue','actor'=>'player','input_type'=>'text','client_message_id'=>'msg-1','content'=>'Hello'];
$message = $messages->append($session, $data);
denied('duplicate sequence rejected', fn() => $messages->append($session, array_replace($data, ['client_message_id'=>'msg-2'])));
denied('duplicate client ID rejected', fn() => $messages->append($session, array_replace($data, ['sequence_no'=>2])));
$messages->append($session, ['sequence_no'=>2,'channel'=>'internal','content'=>'SECRET']);
check('internal messages hidden', count($messages->listForSession($session)) === 1);
$second = $sessions->create($version);
denied('cross-session reply rejected', fn() => $messages->append($second, ['sequence_no'=>1,'content'=>'Bad','reply_to_message_id'=>$message]));
$events = new EventRepository();
$eventData = ['message_id'=>$message,'sequence_no'=>1,'event_key'=>'fact-1'];
$events->append($session, $eventData);
denied('event deduplication', fn() => $events->append($session, array_replace($eventData, ['sequence_no'=>2])));
denied('cross-session event rejected', fn() => $events->append($second, $eventData));
$agreements = new AgreementRepository();
denied('proposal without state revision rejected', fn() => $agreements->create($session, ['proposal_no'=>1]));
$agreements->create($session, ['proposal_no'=>1,'state_revision'=>2]);
denied('duplicate proposal rejected', fn() => $agreements->create($session, ['proposal_no'=>1,'state_revision'=>2]));
$evaluations = new EvaluationRepository(); $evaluation = $evaluations->create($session);
denied('duplicate session evaluation rejected', fn() => $evaluations->create($session));
$wpdb->insert(Schema::table('evaluation_rules'), ['scenario_version_id'=>$version,'code'=>'rule-1']); $rule = $wpdb->insert_id;
$evaluations->addScore($evaluation, $rule, ['raw_score'=>5]);
denied('duplicate criterion score rejected', fn() => $evaluations->addScore($evaluation, $rule, ['raw_score'=>6]));
$wpdb->insert(Schema::table('evaluation_rules'), ['scenario_version_id'=>999,'code'=>'other']); $otherRule = $wpdb->insert_id;
denied('cross-version score rejected', fn() => $evaluations->addScore($evaluation, $otherRule, ['raw_score'=>5]));
foreach ([['item_state','item_id'],['discovered_facts','hidden_fact_id']] as [$table,$fk]) {
    $data = ['session_id'=>$session, $fk=>1, 'updated_at'=>$now];
    check($table . ' first insert', $wpdb->insert(Schema::table($table), $data) === 1);
    check($table . ' duplicate rejected', $wpdb->insert(Schema::table($table), $data) === false);
}
// Existing data must survive a migration retry and a simulated DDL failure.
delete_option('ckm_neg_db_version'); $GLOBALS['fail_ddl'] = true;
check('DDL failure contained', !Migrations::run());
check('failure does not stamp version', get_option('ckm_neg_db_version', '') === '');
check('failure releases lease', get_option('ckm_neg_migration_lock', '') === '');
check('failure health degraded', Health::response()->status === 503);
$GLOBALS['fail_ddl'] = false;
check('retry succeeds', Migrations::run());
check('session and messages survive retry', $sessions->get($session)['id'] == $session && count($messages->listForSession($session)) === 1);
check('no deactivation/delete hooks', !isset($GLOBALS['hooks']['deactivate']) && !isset($GLOBALS['hooks']['uninstall']));
update_option('ckm_neg_db_version', '9.0.0'); $count = $GLOBALS['ddl_count'];
check('newer schema never downgraded', !Migrations::run() && get_option('ckm_neg_db_version') === '9.0.0' && $count === $GLOBALS['ddl_count']);
echo "$passed checks passed (SQLite-backed WordPress stubs; not a live MySQL test).\n";
