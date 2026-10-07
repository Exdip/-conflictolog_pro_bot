<?php
// Local fixtures only: exercise application guards without WordPress or network.
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$root = dirname(__DIR__);
$GLOBALS['s570_guard_tenant'] = 10;
$GLOBALS['s570_guard_admin'] = false;
function get_current_user_id() { return 501; }
function current_user_can($cap) { return $GLOBALS['s570_guard_admin']; }
function is_user_logged_in() { return true; }
function ckmqp_scope_id() { return $GLOBALS['s570_guard_tenant']; }
function ckmqp_tenant_is_member($tenant, $user) { return true; }
function get_option($key, $default = false) { return $default; }
class WP_REST_Response {
    public function __construct(public array $data, public int $status, public array $headers) {}
}

final class Sales570GuardDB {
    public string $prefix = 'fixture_';
    public int $insert_calls = 0;
    public array $rows = [];
    public array $reads = [];
    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%[ds]/', static function(array $m) use (&$i, $args): string {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string)(int)$v : "'" . str_replace("'", "''", (string)$v) . "'";
        }, $sql);
    }
    public function get_row(string $sql, $mode = null): ?array {
        $this->reads[] = $sql;
        if (!preg_match('/FROM `fixture_ckm_neg_(\w+)`/', $sql, $table)) { return null; }
        if (!preg_match('/\bid=(\d+)/', $sql, $id)) { return null; }
        $row = $this->rows[$table[1]][(int)$id[1]] ?? null;
        if ($row && preg_match('/\btenant_id=(\d+)/', $sql, $tenant) && (int)$row['tenant_id'] !== (int)$tenant[1]) { return null; }
        return $row;
    }
    public function get_var(string $sql) { return 0; }
    public function insert(string $table, array $data) { $this->insert_calls++; throw new RuntimeException('Unexpected fixture write.'); }
}
$wpdb = new Sales570GuardDB();
require $root . '/modules/negotiation-master/schema.php';
require $root . '/modules/negotiation-master/security.php';
require $root . '/modules/negotiation-master/repositories.php';
require $root . '/modules/negotiation-master/application/library-access-service.php';
require $root . '/modules/negotiation-master/application/assignment-service.php';
require $root . '/modules/effective-sales/sales-domain.php';
require $root . '/modules/effective-sales/application/sales-competition-service.php';
require $root . '/modules/effective-sales/api/sales-controller.php';

$passed = 0;
function s570_guard_check(string $name, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $passed++;
    echo "PASS: $name\n";
}
function s570_guard_rejected(callable $call, string $class): bool {
    try { $call(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}
$mechanics = ['training_domain' => 'sales', 'practice_source_session_id' => 'private-source', 'practice_excerpt' => 'private-dialogue'];
$wpdb->rows['scenarios'] = [
    601 => ['id' => 601, 'tenant_id' => 10, 'status' => 'published', 'current_version_id' => 701, 'library_id' => 0],
    602 => ['id' => 602, 'tenant_id' => 20, 'status' => 'published', 'current_version_id' => 702, 'library_id' => 0],
    603 => ['id' => 603, 'tenant_id' => null, 'status' => 'published', 'current_version_id' => 703, 'library_id' => 0],
    604 => ['id' => 604, 'tenant_id' => 10, 'status' => 'published', 'current_version_id' => 704, 'library_id' => 0],
];
$wpdb->rows['scenario_versions'] = [];
foreach ([701 => 601, 702 => 602, 703 => 603, 704 => 601] as $id => $scenarioId) {
    $wpdb->rows['scenario_versions'][$id] = ['id' => $id, 'scenario_id' => $scenarioId, 'status' => 'published', 'mechanics_json' => json_encode($mechanics)];
}
$wpdb->rows['assignments'] = [
    801 => ['id' => 801, 'tenant_id' => 10, 'scenario_id' => 601, 'assignment_mode' => 'individual', 'mode' => 'exam'],
    802 => ['id' => 802, 'tenant_id' => 10, 'scenario_id' => 601, 'assignment_mode' => 'team_shared', 'mode' => 'exam'],
    803 => ['id' => 803, 'tenant_id' => 20, 'scenario_id' => 602, 'assignment_mode' => 'team_shared', 'mode' => 'exam'],
    804 => ['id' => 804, 'tenant_id' => 10, 'scenario_id' => 601, 'assignment_mode' => 'team_shared', 'mode' => 'training'],
];
$wpdb->rows['sessions'] = [
    901 => ['id' => 901, 'tenant_id' => 10, 'scenario_id' => 601, 'scenario_version_id' => 701, 'session_kind' => 'player', 'participant_key' => 'user:501'],
    902 => ['id' => 902, 'tenant_id' => 20, 'scenario_id' => 602, 'scenario_version_id' => 702, 'session_kind' => 'player', 'participant_key' => 'user:501'],
];
use CKM\EffectiveSales\SalesCompetitionService;
use CKM\EffectiveSales\SalesController;
use CKM\EffectiveSales\SalesDomain;
use CKM\NegotiationMaster\AssignmentService;
use CKM\NegotiationMaster\AssignmentAccessDeniedException;
use CKM\NegotiationMaster\AssignmentValidationException;
use CKM\NegotiationMaster\ScenarioRepository;
s570_guard_check('individual exam is not a team competition', !SalesCompetitionService::isCompetitionAssignment(801));
s570_guard_check('ordinary assigned player can classify a team exam', SalesCompetitionService::isCompetitionAssignment(802));
s570_guard_check('foreign tenant competition is unavailable', !SalesCompetitionService::isCompetitionAssignment(803));
s570_guard_check('team training is not a competition', !SalesCompetitionService::isCompetitionAssignment(804));
s570_guard_check('missing assignment is not a competition', !SalesCompetitionService::isCompetitionAssignment(999));

$decorate = new ReflectionMethod(SalesController::class, 'decorate');
$snapshot = ['session' => ['id' => 901, 'scenario_version_id' => 701, 'session_kind' => 'assignment', 'assignment_id' => 801, 'mode' => 'exam'], 'can_use_coach' => true];
$individual = $decorate->invoke(null, $snapshot);
s570_guard_check('individual exam snapshot remains check and disables coach', $individual['sales_format'] === 'check' && !$individual['can_use_coach']);
$snapshot['session']['assignment_id'] = 802;
$team = $decorate->invoke(null, $snapshot);
s570_guard_check('team exam snapshot is competition', $team['sales_format'] === 'competition');
$snapshot['session']['assignment_id'] = 803;
s570_guard_check('foreign assignment cannot classify snapshot as competition', $decorate->invoke(null, $snapshot)['sales_format'] === 'check');
$snapshot['session']['session_kind'] = 'player';
$snapshot['session']['assignment_id'] = 0;
s570_guard_check('unassigned exam snapshot remains check', $decorate->invoke(null, $snapshot)['sales_format'] === 'check');
$snapshot['session']['mode'] = 'training';
s570_guard_check('training snapshot remains training', $decorate->invoke(null, $snapshot)['sales_format'] === 'training');
s570_guard_check('public mechanics omit source session and dialogue', !isset($team['sales']['practice_source_session_id']) && !isset($team['sales']['practice_excerpt']));
s570_guard_check('player version omits internal mechanics', !isset((new ScenarioRepository())->playerVersion(701)['mechanics_json']));

$GLOBALS['s570_guard_admin'] = true;
s570_guard_check('admin sales access still rejects foreign tenant scenario', s570_guard_rejected(static fn() => SalesDomain::versionForScenario(602), InvalidArgumentException::class));
s570_guard_check('current tenant sales scenario stays available', SalesDomain::versionForScenario(601)['scenario_id'] === 601);
s570_guard_check('global sales scenario stays available', SalesDomain::versionForScenario(603)['scenario_id'] === 603);
s570_guard_check('sales scenario rejects mismatched current version', s570_guard_rejected(static fn() => SalesDomain::versionForScenario(604), InvalidArgumentException::class));
$assignments = new AssignmentService();
s570_guard_check('admin cannot write current-tenant assignment from foreign scenario', s570_guard_rejected(static fn() => $assignments->create(['scenario_id' => 602]), AssignmentAccessDeniedException::class));
s570_guard_check('assignment rejects mismatched current version', s570_guard_rejected(static fn() => $assignments->create(['scenario_id' => 604]), AssignmentValidationException::class));
s570_guard_check('rejected assignment checks made no writes', $wpdb->insert_calls === 0);
s570_guard_check('admin cannot classify foreign tenant competition', !SalesCompetitionService::isCompetitionAssignment(803));
s570_guard_check('current tenant sales session stays available', SalesDomain::versionForSession(901)['scenario_id'] === 601);
$wpdb->reads = [];
s570_guard_check('admin sales access still rejects foreign tenant session', s570_guard_rejected(static fn() => SalesDomain::versionForSession(902), InvalidArgumentException::class));
s570_guard_check('foreign session rejected before reading scenario version', count(array_filter($wpdb->reads, static fn(string $sql): bool => str_contains($sql, 'scenario_versions'))) === 0);
$request = new class {
    public function get_param($name) { return $name === 'id' ? 902 : null; }
};
$blockedResult = SalesController::result($request);
s570_guard_check('foreign session result blocked before evaluation or player projection', !$blockedResult->data['ok'] && $blockedResult->status === 422 && !isset($blockedResult->data['result']) && $blockedResult->data['message'] === 'Попытка другого арендатора недоступна.');
echo "$passed SALES-570 assignment/privacy guard checks passed. No WordPress, network or live games.\n";
