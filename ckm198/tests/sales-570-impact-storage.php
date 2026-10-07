<?php
// Execute the production impact queries against isolated in-memory storage.
namespace CKM\EffectiveSales {
    final class SalesStandardRecertificationService {
        public static array $fixture = [];
        public static function canManage(): bool { return true; }
        public static function dashboard(string $scriptId): array { return self::$fixture; }
    }
    final class SalesCompetitionService {
        public static function canManage(): bool { return true; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('SALES-570 impact storage requires PDO SQLite.');
    }
    define('ABSPATH', __DIR__ . '/');
    define('ARRAY_A', 'ARRAY_A');
    $root = dirname(__DIR__);
    $GLOBALS['s570_impact_tenant'] = 10;
    $GLOBALS['s570_impact_meta'] = [];
    function get_current_user_id() { return 501; }
    function current_user_can($cap) { return false; }
    function ckmqp_scope_id() { return $GLOBALS['s570_impact_tenant']; }
    function ckmqp_tenant_is_member($tenant, $user) { return true; }
    function get_option($key, $default = false) { return $default; }
    function get_user_meta($user, $key, $single = false) { return $GLOBALS['s570_impact_meta'][$user][$key] ?? ''; }
    function update_user_meta($user, $key, $value) { $GLOBALS['s570_impact_meta'][$user][$key] = $value; return true; }
    function current_time($kind, $gmt = false) { return $gmt ? '2026-01-10 10:00:00' : '2026-01-10 13:00:00'; }
    function get_gmt_from_date($date) {
        return (new DateTimeImmutable($date, new DateTimeZone('Europe/Moscow')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    final class Sales570ImpactDB {
        public string $prefix = 'fixture_';
        public PDO $pdo;
        public array $reads = [];
        public function __construct() {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE fixture_ckm_neg_sessions (
                id INTEGER PRIMARY KEY, tenant_id INTEGER, participant_key TEXT, session_kind TEXT,
                status TEXT, mode TEXT, scenario_id INTEGER, assignment_id INTEGER,
                completed_at TEXT, last_activity_at TEXT, created_at TEXT)');
            $this->pdo->exec('CREATE TABLE fixture_ckm_neg_evaluations (
                id INTEGER PRIMARY KEY, session_id INTEGER, status TEXT, final_score REAL, completed_at TEXT)');
            $this->pdo->exec('CREATE TABLE fixture_ckm_neg_evaluation_scores (
                id INTEGER PRIMARY KEY, evaluation_id INTEGER, evaluation_rule_id INTEGER, raw_score REAL)');
            $this->pdo->exec('CREATE TABLE fixture_ckm_neg_evaluation_rules (id INTEGER PRIMARY KEY, code TEXT, title TEXT)');
            $this->pdo->exec("INSERT INTO fixture_ckm_neg_evaluation_rules VALUES
                (1, 'customer_understanding', 'Понимание клиента'), (2, 'question_quality', 'Качество вопросов')");
        }
        public function prepare(string $sql, ...$args): string {
            if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
            $index = 0;
            return preg_replace_callback('/%%|%[dsf]/', function(array $m) use (&$index, $args): string {
                if ($m[0] === '%%') { return '%'; }
                if (!array_key_exists($index, $args)) { throw new RuntimeException('Missing SQL fixture argument.'); }
                $value = $args[$index++];
                return match ($m[0]) { '%d' => (string)(int)$value, '%f' => (string)(float)$value, default => $this->pdo->quote((string)$value) };
            }, $sql);
        }
        public function get_row(string $sql, $mode = null): ?array {
            $this->reads[] = $sql;
            return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        public function get_var(string $sql) { return $this->pdo->query($sql)->fetchColumn(); }
        public function seed(int $id, string $participant, float $score, string $at, array $override = []): void {
            $session = array_replace([
                'id' => $id, 'tenant_id' => 10, 'participant_key' => $participant,
                'session_kind' => 'player', 'status' => 'completed_success', 'mode' => 'training',
                'scenario_id' => 101, 'assignment_id' => 0, 'completed_at' => $at,
                'last_activity_at' => $at, 'created_at' => $at,
            ], $override['session'] ?? []);
            $evaluation = array_replace([
                'id' => $id, 'session_id' => $id, 'status' => 'completed', 'final_score' => $score, 'completed_at' => $at,
            ], $override['evaluation'] ?? []);
            $criterion = array_replace([
                'id' => $id, 'evaluation_id' => $id, 'evaluation_rule_id' => 1, 'raw_score' => $score,
            ], $override['criterion'] ?? []);
            foreach (['sessions' => $session, 'evaluations' => $evaluation, 'evaluation_scores' => $criterion] as $table => $row) {
                $sql = 'INSERT INTO fixture_ckm_neg_' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
                $this->pdo->prepare($sql)->execute(array_values($row));
            }
        }
    }
    $wpdb = new Sales570ImpactDB();
    require $root . '/modules/negotiation-master/schema.php';
    require $root . '/modules/negotiation-master/security.php';
    require $root . '/modules/effective-sales/application/sales-script-service.php';
    require $root . '/modules/effective-sales/application/sales-adaptive-polygon-service.php';
    require $root . '/modules/effective-sales/application/sales-team-development-service.php';
    require $root . '/modules/effective-sales/application/sales-practice-feedback-service.php';
    require $root . '/modules/effective-sales/application/sales-standard-impact-service.php';
    use CKM\EffectiveSales\SalesScriptService;
    use CKM\EffectiveSales\SalesStandardRecertificationService as Recertification;
    use CKM\EffectiveSales\SalesStandardImpactService as Impact;
    use CKM\EffectiveSales\SalesPracticeFeedbackService as Practice;
    $passed = 0;
    function s570_impact_check(string $name, bool $ok): void {
        global $passed;
        if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
        $passed++;
        echo "PASS: $name\n";
    }
    $activation = '2026-01-10 10:00:00';
    $script = [
        'id' => 'script-impact', 'title' => 'Методика', 'status' => 'approved', 'ai_client_scenario_id' => 101,
        'adaptive_cases' => [
            ['scenario_id' => 102, 'focus_code' => 'customer_understanding'],
            ['scenario_id' => 201, 'focus_code' => 'customer_understanding', 'recertification_revision' => 2],
        ],
        'practice_standard_events' => [['revision' => 2, 'action' => 'activated', 'at' => '2026-01-10 13:00:00', 'at_utc' => $activation]],
    ];
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1'] = ['10' => [$script]];
    Recertification::$fixture = [
        'available' => true, 'focus_code' => 'customer_understanding', 'focus_title' => 'Понимание клиента',
        'scenario_id' => 201, 'revision' => 2, 'rule' => 'Правило диагностики',
        'employees' => [
            ['participant_key' => 'user:11', 'label' => 'Первый', 'assignment' => ['assignment_id' => 501, 'status' => 'Выполнено', 'created_at' => '2026-01-10 11:00:00']],
            ['participant_key' => 'user:12', 'label' => 'Второй', 'assignment' => ['assignment_id' => 502, 'status' => 'Выполнено', 'created_at' => '2026-01-10 11:00:00']],
        ],
    ];
    $wpdb->seed(1, 'user:11', 60, '2026-01-10 09:00:00');
    $wpdb->seed(2, 'user:12', 65, '2026-01-10 09:00:00');
    $post = ['session_kind' => 'assignment', 'mode' => 'exam', 'scenario_id' => 201, 'assignment_id' => 501];
    $wpdb->seed(11, 'user:11', 80, '2026-01-10 12:00:00', ['session' => $post]);
    $wpdb->seed(12, 'user:12', 90, '2026-01-10 12:00:00', ['session' => array_replace($post, ['assignment_id' => 502])]);
    $view = Impact::dashboard('script-impact');
    s570_impact_check('two employees use the same trained criterion before and after', $view['comparable_count'] === 2 && $view['employees'][0]['before']['criterion_code'] === $view['employees'][0]['after']['criterion_code']);
    s570_impact_check('two employees improve and pass through production summarize', $view['conclusion']['code'] === 'confirmed' && $view['improved_count'] === 2 && $view['passed_count'] === 2 && $view['average_delta'] === 22.5);
    s570_impact_check('dashboard aggregation is the same public production decision', Impact::summarize($view['employees'])['conclusion'] === $view['conclusion']);

    $wpdb->seed(3, 'user:11', 74, '2026-01-10 10:30:00');
    s570_impact_check('late assignment does not move baseline beyond activation', Impact::dashboard('script-impact')['employees'][0]['before']['session_id'] === 1);
    $wpdb->seed(4, 'user:11', 99, $activation);
    s570_impact_check('result exactly at activation is not a baseline', Impact::dashboard('script-impact')['employees'][0]['before']['session_id'] === 1);
    $wpdb->seed(5, 'user:11', 99, '2026-01-10 09:59:00', ['session' => ['session_kind' => 'builder_test']]);
    $wpdb->seed(15, 'user:11', 0, '2026-01-10 12:59:00', ['session' => array_replace($post, ['session_kind' => 'builder_test'])]);
    $view = Impact::dashboard('script-impact');
    s570_impact_check('builder tests are excluded from baseline and exam', $view['employees'][0]['before']['session_id'] === 1 && $view['employees'][0]['after']['session_id'] === 11);
    $wpdb->seed(6, 'user:11', 99, '2026-01-10 09:59:00', ['session' => ['tenant_id' => 20]]);
    $wpdb->seed(16, 'user:11', 0, '2026-01-10 12:59:00', ['session' => array_replace($post, ['tenant_id' => 20])]);
    $view = Impact::dashboard('script-impact');
    s570_impact_check('same participant and assignment keys cannot mix foreign tenant scores', $view['employees'][0]['before']['session_id'] === 1 && $view['employees'][0]['after']['session_id'] === 11 && $view['conclusion']['code'] === 'confirmed');
    $wpdb->seed(7, 'user:11', 99, '2026-01-10 09:59:00', ['session' => ['scenario_id' => 201]]);
    s570_impact_check('recertification scenario is excluded from baseline', Impact::dashboard('script-impact')['employees'][0]['before']['session_id'] === 1);
    $wpdb->seed(8, 'user:11', 99, '2026-01-10 09:59:00', ['criterion' => ['evaluation_rule_id' => 2]]);
    $wpdb->seed(17, 'user:11', 0, '2026-01-10 12:59:00', ['session' => $post, 'criterion' => ['evaluation_rule_id' => 2]]);
    $view = Impact::dashboard('script-impact');
    s570_impact_check('SQL matches exact criterion for both results', $view['employees'][0]['before']['session_id'] === 1 && $view['employees'][0]['after']['session_id'] === 11);
    foreach ([
        18 => ['assignment_id' => 999], 19 => ['scenario_id' => 202],
        20 => ['mode' => 'training'], 21 => ['session_kind' => 'player'],
    ] as $id => $incorrect) {
        $wpdb->seed($id, 'user:11', 0, '2026-01-10 12:59:00', ['session' => array_replace($post, $incorrect)]);
        s570_impact_check('post result rejects wrong ' . array_key_first($incorrect), Impact::dashboard('script-impact')['employees'][0]['after']['session_id'] === 11);
    }
    $wpdb->seed(22, 'user:11', 0, '2026-01-10 12:59:00', ['session' => $post, 'evaluation' => ['status' => 'running']]);
    $wpdb->seed(23, 'user:11', 0, '2026-01-10 12:59:00', ['session' => array_replace($post, ['status' => 'active'])]);
    s570_impact_check('incomplete session or evaluation cannot replace completed exam', Impact::dashboard('script-impact')['employees'][0]['after']['session_id'] === 11);

    $saved = $script;
    $script['ai_client_scenario_id'] = 0;
    $script['adaptive_cases'] = [$script['adaptive_cases'][1]];
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$script];
    $view = Impact::dashboard('script-impact');
    s570_impact_check('empty baseline catalog does not broaden search to unrelated scenarios', $view['comparable_count'] === 0 && $view['employees'][0]['before'] === null && $view['conclusion']['code'] === 'insufficient');
    $script = $saved;
    $script['practice_standard_events'] = [];
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$script];
    $view = Impact::dashboard('script-impact');
    s570_impact_check('missing activation metadata cannot use assignment creation as baseline cutoff', $view['comparable_count'] === 0 && $view['conclusion']['code'] === 'insufficient');
    $script = $saved;
    unset($script['practice_standard_events'][0]['at_utc']);
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$script];
    $view = Impact::dashboard('script-impact');
    s570_impact_check('legacy Moscow activation is converted to UTC before baseline SQL', $view['employees'][0]['before']['session_id'] === 1 && $view['conclusion']['code'] === 'confirmed');
    $script['practice_standard_events'][0]['at_utc'] = $activation;
    $script['practice_standard_events'][0]['at'] = '2026-01-10 20:00:00';
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$script];
    s570_impact_check('explicit UTC activation takes precedence over legacy local timestamp', Impact::dashboard('script-impact')['employees'][0]['before']['session_id'] === 1);
    $script = $saved;
    $script['practice_methodology_notes'] = [['focus_code' => 'customer_understanding', 'status' => 'adopted']];
    $script['practice_standard_revision'] = 2;
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$script];
    $activated = Practice::setRuleActive('script-impact', 'customer_understanding', true);
    $event = end($activated['script']['practice_standard_events']);
    s570_impact_check('new activation stores both local display time and UTC evidence cutoff', $event['at'] === '2026-01-10 13:00:00' && $event['at_utc'] === $activation && $event['revision'] === 3);
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['10'] = [$saved];
    $GLOBALS['s570_impact_tenant'] = 20;
    s570_impact_check('real script storage does not expose the other tenant script', SalesScriptService::find('script-impact') === null && !Impact::dashboard('script-impact')['available']);
    $GLOBALS['s570_impact_meta'][501]['ckm_sales_scripts_v1']['20'] = [$saved];
    $view = Impact::dashboard('script-impact');
    s570_impact_check('tenant switch reads only that tenant baseline and exam', $view['employees'][0]['before']['session_id'] === 6 && $view['employees'][0]['after']['session_id'] === 16 && $view['employees'][1]['after'] === null);
    echo "$passed SALES-570 impact storage checks passed. SQLite fixtures; no WordPress, network or live games.\n";
}
