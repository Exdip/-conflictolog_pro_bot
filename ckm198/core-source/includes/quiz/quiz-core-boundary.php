<?php
/**
 * Quiz Core service boundary.
 *
 * This file is deliberately platform-neutral. It knows only logical service
 * names used by Quiz Core. CKM Platform and the future standalone CKM Quiz Pro
 * register their own implementations in adapter files.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ckm_quiz_core_register_service(string $name, $callback): bool {
    $name = sanitize_key($name);
    if ($name === '' || !is_callable($callback)) return false;
    if (!isset($GLOBALS['ckm_quiz_core_services']) || !is_array($GLOBALS['ckm_quiz_core_services'])) {
        $GLOBALS['ckm_quiz_core_services'] = array();
    }
    $GLOBALS['ckm_quiz_core_services'][$name] = $callback;
    return true;
}

function ckm_quiz_core_has_service(string $name): bool {
    $name = sanitize_key($name);
    return $name !== ''
        && isset($GLOBALS['ckm_quiz_core_services'][$name])
        && is_callable($GLOBALS['ckm_quiz_core_services'][$name]);
}

function ckm_quiz_core_call(string $name, array $args = array(), $default = null) {
    $name = sanitize_key($name);
    if (!ckm_quiz_core_has_service($name)) return $default;
    return call_user_func_array($GLOBALS['ckm_quiz_core_services'][$name], $args);
}

function ckm_quiz_core_registered_services(): array {
    $services = isset($GLOBALS['ckm_quiz_core_services']) && is_array($GLOBALS['ckm_quiz_core_services'])
        ? $GLOBALS['ckm_quiz_core_services'] : array();
    return array_values(array_keys(array_filter($services, 'is_callable')));
}

function ckm_quiz_core_storage_table(string $logicalName): string {
    $logicalName = sanitize_key($logicalName);
    $table = (string)ckm_quiz_core_call('storage_table', array($logicalName), '');
    if ($table === '') {
        throw new RuntimeException('Quiz Core storage adapter is not configured for: ' . $logicalName);
    }
    return $table;
}

function ckm_quiz_core_random_token(int $bytes = 24): string {
    $bytes = max(8, min(64, $bytes));
    $token = (string)ckm_quiz_core_call('random_token', array($bytes), '');
    if ($token !== '') return $token;
    try {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    } catch (Throwable $e) {
        return wp_generate_password(max(24, $bytes * 2), false, false);
    }
}

function ckm_quiz_core_team_policy(array $quiz = array()): array {
    $policy = ckm_quiz_core_call('team_policy', array($quiz), null);
    if (!is_array($policy)) $policy = array();
    $min = max(1, (int)($policy['min'] ?? 2));
    $max = max($min, (int)($policy['max'] ?? 10));
    return array('min'=>$min, 'max'=>$max);
}

function ckm_quiz_core_normalize_team_count($value, int $min, int $max): int {
    $normalized = ckm_quiz_core_call('normalize_team_count', array($value, $min, $max), null);
    if (is_numeric($normalized)) return max($min, min($max, (int)$normalized));
    return max($min, min($max, (int)$value));
}

function ckm_quiz_core_team_key_from_slot(int $slot): string {
    $key = (string)ckm_quiz_core_call('team_key_from_slot', array($slot), '');
    if ($key !== '') return $key;
    $slot = max(1, $slot);
    $key = '';
    while ($slot > 0) {
        $slot--;
        $key = chr(65 + ($slot % 26)) . $key;
        $slot = intdiv($slot, 26);
    }
    return $key;
}

function ckm_quiz_core_team_default_name(int $slot): string {
    $name = (string)ckm_quiz_core_call('team_default_name', array($slot), '');
    return $name !== '' ? $name : ('Команда ' . ckm_quiz_core_team_key_from_slot($slot));
}

function ckm_quiz_core_live_room_url(string $room): string {
    return (string)ckm_quiz_core_call('live_room_url', array($room), '');
}

function ckm_quiz_core_validate_room_access(int $saleId, int $accessId, bool $testMode, array $quiz): array {
    $result = ckm_quiz_core_call('validate_room_access', array($saleId, $accessId, $testMode, $quiz), null);
    if (is_array($result)) return $result;
    if ($testMode) return array('ok'=>true, 'status'=>200, 'sale_id'=>$saleId, 'access_id'=>$accessId, 'testMode'=>true);
    return array('ok'=>false, 'status'=>503, 'code'=>'access_adapter_missing', 'error'=>'Адаптер доступа Quiz Core не настроен.');
}

function ckm_quiz_core_consume_access_locked(array $game): array {
    $result = ckm_quiz_core_call('consume_access_locked', array($game), null);
    if (is_array($result)) return $result;
    return array('ok'=>false, 'status'=>503, 'code'=>'access_adapter_missing', 'error'=>'Адаптер списания доступа Quiz Core не настроен.');
}

/** alpha.87.1 — optional storage + history/AI/access adapter contracts. */
function ckm_quiz_core_storage_table_optional(string $logicalName): string {
    $logicalName = sanitize_key($logicalName);
    if ($logicalName === '') return '';
    return (string)ckm_quiz_core_call('storage_table', array($logicalName), '');
}

function ckm_quiz_core_preflight_access(array $game): array {
    $result = ckm_quiz_core_call('preflight_access', array($game), null);
    if (is_array($result)) return $result;
    if (!empty($game['test_mode']) || (!empty($game['settings']) && !empty($game['settings']['testMode']))) {
        return array('ok'=>true,'detail'=>'test_mode');
    }
    return array('ok'=>false,'code'=>'access_adapter_missing','detail'=>'Адаптер проверки доступа Quiz Core не настроен.');
}

function ckm_quiz_core_history_game_row(int $gameId): ?array {
    $row = ckm_quiz_core_call('history_game_row', array($gameId), null);
    return is_array($row) ? $row : null;
}

function ckm_quiz_core_history_team_rows(int $gameId): array {
    $rows = ckm_quiz_core_call('history_team_rows', array($gameId), array());
    return is_array($rows) ? $rows : array();
}

function ckm_quiz_core_history_finished_game_ids(): array {
    $ids = ckm_quiz_core_call('history_finished_game_ids', array(), array());
    if (!is_array($ids)) return array();
    return array_values(array_filter(array_map('intval', $ids), static function($id){ return $id > 0; }));
}

function ckm_quiz_core_ai_provider_choices(): array {
    $choices = ckm_quiz_core_call('ai_provider_choices', array(), array());
    return is_array($choices) ? $choices : array();
}

function ckm_quiz_core_ai_default_provider(array $choices): string {
    $provider = (string)ckm_quiz_core_call('ai_default_provider', array($choices), '');
    if ($provider !== '' && isset($choices[$provider])) return $provider;
    foreach ($choices as $key=>$value) return (string)$key;
    return '';
}

/**
 * Generic text-AI request. Core supplies prompts/context; the active adapter
 * decides where the request is executed (AI Puffer in CKM, CKM Cloud in Pro).
 */
function ckm_quiz_core_ai_text_call(array $request): array {
    $result = ckm_quiz_core_call('ai_text_call', array($request), null);
    if (is_array($result)) return $result;
    return array('ok'=>false,'code'=>'ai_adapter_missing','error'=>'AI Adapter Quiz Core не настроен.');
}

function ckm_quiz_core_methodology_catalog_rows(): array {
    $rows = ckm_quiz_core_call('methodology_catalog_rows', array(), array());
    return is_array($rows) ? $rows : array();
}

