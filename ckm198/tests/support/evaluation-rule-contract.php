<?php
/** Exercise the real production rule calculator while substituting only persistence. */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__, 2) . '/'); }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
$evaluationContractRoot = dirname(__DIR__, 2) . '/modules/negotiation-master';
require_once $evaluationContractRoot . '/schema.php';
require_once $evaluationContractRoot . '/evaluation/criterion-calculator.php';
require_once $evaluationContractRoot . '/evaluation/php-evaluator.php';
require_once $evaluationContractRoot . '/evaluation/evaluation-service.php';

final class CkmEvaluationPersistenceFixture {
    public string $prefix = 'wp_';
    public array $inserts = [];
    public function prepare(string $sql, ...$args): string { return $sql; }
    public function get_var(string $sql): mixed { return null; }
    public function insert(string $table, array $row): int { $this->inserts[] = ['table'=>$table,'row'=>$row]; return 1; }
}

function ckm_test_calculate_evaluation_rule(array $rule, array $context, ?array $ai = null, array $noDeal = []): array {
    $prior = $GLOBALS['wpdb'] ?? null;
    $db = new CkmEvaluationPersistenceFixture();
    $GLOBALS['wpdb'] = $db;
    try {
        $class = new ReflectionClass(\CKM\NegotiationMaster\EvaluationService::class);
        $service = $class->newInstanceWithoutConstructor();
        $calculator = new \CKM\NegotiationMaster\CriterionCalculator();
        $class->getProperty('calculator')->setValue($service, $calculator);
        $class->getProperty('php')->setValue($service, new \CKM\NegotiationMaster\PhpEvaluator($calculator));
        $result = $class->getMethod('calculateAndSaveRule')->invoke($service, 10, $rule, $context, $noDeal, $ai);
        return ['result'=>$result,'writes'=>$db->inserts];
    } finally {
        $GLOBALS['wpdb'] = $prior;
    }
}
