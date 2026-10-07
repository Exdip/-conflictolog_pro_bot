<?php
/** CLI smoke test for public Games Hub -> CKM Quiz Pro runtime mapping. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
require_once dirname(__DIR__) . '/includes/games-hub-runtime-bridge.php';

$expected = array(
    'classic_quiz_v1'=>array('classic_quiz',''),
    'chgk_v1'=>array('chgk',''),
    'jeopardy_v1'=>array('jeopardy',''),
    'decision_price_v1'=>array('solution_price',''),
    'sales_v1'=>array('negotiation_duel','sales'),
    'business_negotiation_v1'=>array('negotiation_duel','business'),
    'express_round_v1'=>array('negotiation_duel','express'),
);
foreach ($expected as $runtime=>$want) {
    $got = ckm_quiz_pro_hub_selection_from_runtime($runtime);
    if (($got['format_key'] ?? '') !== $want[0] || ($got['mode'] ?? '') !== $want[1]) {
        fwrite(STDERR, "FAIL: {$runtime}\n"); exit(1);
    }
}
if (ckm_quiz_pro_hub_runtime_seconds('solution_price', 600, array('decision_time_seconds'=>7200)) !== 7200) {
    fwrite(STDERR, "FAIL: solution_price seconds\n"); exit(1);
}
if (ckm_quiz_pro_hub_runtime_seconds('chgk', 60, array('discussion_time_seconds'=>90)) !== 90) {
    fwrite(STDERR, "FAIL: chgk seconds\n"); exit(1);
}
$settings = ckm_quiz_pro_hub_merge_format_settings('classic_quiz', array(), array('speed_bonus_enabled'=>false));
if (!array_key_exists('speedBonusEnabled',$settings) || $settings['speedBonusEnabled'] !== false) {
    fwrite(STDERR, "FAIL: speed bonus toggle\n"); exit(1);
}
echo "PASS: Games Hub runtime mapping\n";
