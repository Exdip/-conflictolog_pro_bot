<?php
/**
 * CKM Games Hub -> Quiz Pro runtime integration.
 *
 * Keeps the catalog plugin as a storefront/configurator while the existing
 * Quiz Pro engine remains the only authoritative gameplay runtime.
 */
if (!defined('ABSPATH')) exit;

function ckmqp_hub_runtime_to_core_format(string $runtime): string {
    $runtime = sanitize_key($runtime);
    $map = array(
        'classic_quiz_v1'        => 'classic_quiz',
        'chgk_v1'                => 'chgk',
        'jeopardy_v1'            => 'jeopardy',
        'decision_price_v1'      => 'solution_price',
        'persuade_me_v1'         => 'negotiation_duel',
        'persuade_school_v1'     => 'negotiation_duel',
        'persuade_school_grade_v1'=> 'negotiation_duel',
        'persuade_student_v1'    => 'negotiation_duel',
        'persuade_leader_v1'     => 'negotiation_duel',
        'persuade_family_v1'     => 'negotiation_duel',
        'sales_v1'               => 'negotiation_duel',
        'business_negotiation_v1'=> 'negotiation_duel',
        'express_round_v1'       => 'negotiation_duel',
        // Core keys are accepted too, which makes the adapter idempotent.
        'classic_quiz'           => 'classic_quiz',
        'chgk'                   => 'chgk',
        'jeopardy'               => 'jeopardy',
        'solution_price'         => 'solution_price',
        'negotiation_duel'       => 'negotiation_duel',
    );
    return $map[$runtime] ?? '';
}

function ckmqp_hub_runtime_negotiation_mode(string $runtime): string {
    $runtime = sanitize_key($runtime);
    if ($runtime === 'business_negotiation_v1') return 'business';
    if ($runtime === 'express_round_v1') return 'express';
    if (in_array($runtime,['persuade_me_v1','persuade_school_v1','persuade_school_grade_v1','persuade_student_v1','persuade_leader_v1','persuade_family_v1'],true)) return 'communicate';
    if ($runtime === 'sales_v1') return 'sales';
    return '';
}

/**
 * Server-side defaults mirror Games Hub 1.7 profiles. They are deliberately
 * duplicated here as a fallback so a catalog click still reaches the correct
 * runtime even if the browser bridge is blocked or loads late.
 */
function ckmqp_hub_default_runtime_settings(string $runtime): array {
    $runtime = sanitize_key($runtime);
    $map = array(
        'classic_quiz_v1' => array(
            'answer_time_seconds'=>45,
            'speed_bonus_enabled'=>true,
            'voice_input_enabled'=>true,
            'media_questions_enabled'=>true,
        ),
        'chgk_v1' => array(
            'discussion_time_seconds'=>60,
            'final_answer_seconds'=>20,
            'single_final_answer'=>true,
            'arbitration_mode'=>'ai_or_host',
        ),
        'jeopardy_v1' => array(
            'answer_time_seconds'=>30,
            'category_choice_enabled'=>true,
            'question_values_enabled'=>true,
            'negative_score_enabled'=>false,
        ),
        'decision_price_v1' => array(
            'decision_time_seconds'=>600,
            'consequence_round_enabled'=>true,
            'team_defense_enabled'=>true,
            'arbitration_mode'=>'ai_or_host',
        ),
        'persuade_me_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'persuade_school_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'persuade_school_grade_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'persuade_student_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'persuade_leader_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'persuade_family_v1' => array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        'sales_v1' => array(
            'round_time_seconds'=>420,
            'client_role_mode'=>'host_or_ai',
            'objections_enabled'=>true,
            'next_step_required'=>true,
            'arbitration_mode'=>'ai_or_host',
        ),
        'business_negotiation_v1' => array(
            'round_time_seconds'=>720,
            'private_briefs_enabled'=>true,
            'agreement_capture_enabled'=>true,
            'arbitration_mode'=>'ai_or_host',
        ),
        'express_round_v1' => array(
            'round_time_seconds'=>60,
            'prep_time_seconds'=>30,
            'role_swap_enabled'=>true,
            'rapid_feedback_enabled'=>true,
            'arbitration_mode'=>'ai_or_host',
        ),
    );
    return $map[$runtime] ?? array();
}

function ckmqp_hub_runtime_from_request(): string {
    foreach (array('ckm_format','format') as $key) {
        if (!isset($_REQUEST[$key]) || !is_string($_REQUEST[$key])) continue;
        $candidate = sanitize_key(wp_unslash($_REQUEST[$key]));
        if (ckmqp_hub_runtime_to_core_format($candidate) !== '') return $candidate;
    }
    return '';
}

/** Pick the exact published template, including the three negotiation modes. */
function ckmqp_hub_find_quiz_for_runtime(array $quizzes, string $runtime): int {
    $runtime = sanitize_key($runtime);
    $core = ckmqp_hub_runtime_to_core_format($runtime);
    if ($core === '') return 0;
    $wantedMode = ckmqp_hub_runtime_negotiation_mode($runtime);
    $variantMap=[
        'persuade_school_v1'=>'school',
        'persuade_school_grade_v1'=>'school_grade',
        'persuade_student_v1'=>'student',
        'persuade_leader_v1'=>'leader',
        'persuade_family_v1'=>'family',
        'persuade_me_v1'=>'',
    ];
    $wantedVariant=$variantMap[$runtime] ?? null;
    $fallback = 0;
    foreach ($quizzes as $candidate) {
        if (sanitize_key((string)($candidate['format_key'] ?? '')) !== $core) continue;
        $id = (int)($candidate['id'] ?? 0);
        if ($id <= 0) continue;
        if ($fallback <= 0) $fallback = $id;
        if ($core !== 'negotiation_duel' || $wantedMode === '') return $id;
        $settings = ckm_quiz_json_decode((string)($candidate['format_settings_json'] ?? ''));
        $mode = function_exists('ckm_quiz_pro_negotiation_mode')
            ? ckm_quiz_pro_negotiation_mode((string)($settings['negotiationMode'] ?? 'sales'))
            : sanitize_key((string)($settings['negotiationMode'] ?? 'sales'));
        if ($mode !== $wantedMode) continue;
        if ($wantedVariant !== null) {
            $candidateVariant=sanitize_key((string)($settings['persuadeMeVariant'] ?? ''));
            if ($candidateVariant !== $wantedVariant) continue;
        }
        return $id;
    }
    return $wantedMode === 'communicate' ? 0 : $fallback;
}


function ckmqp_hub_normalize_judge_mode($value, string $fallback = 'hybrid'): string {
    $value = sanitize_key((string)$value);
    $map = array(
        'ai'=>'ai',
        'host'=>'human',
        'human'=>'human',
        'ai_or_host'=>'hybrid',
        'hybrid'=>'hybrid',
    );
    if (isset($map[$value])) return $map[$value];
    return in_array($fallback, array('ai','human','hybrid'), true) ? $fallback : 'hybrid';
}

function ckmqp_hub_decode_runtime_config($raw): array {
    if (is_array($raw)) $decoded = $raw;
    else {
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : array();
    }
    if (!is_array($decoded)) return array();
    $runtime = sanitize_key((string)($decoded['runtime'] ?? ''));
    $settings = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : array();
    if ($runtime === '' || !$settings) return array();
    return array('runtime'=>$runtime, 'settings'=>$settings);
}

function ckmqp_hub_request_runtime_config(array $input = array()): array {
    if (array_key_exists('ckm_runtime_config_json', $input)) {
        $config = ckmqp_hub_decode_runtime_config($input['ckm_runtime_config_json']);
        if ($config) return $config;
    }
    if (isset($_REQUEST['ckm_runtime_config_json'])) {
        $config = ckmqp_hub_decode_runtime_config($_REQUEST['ckm_runtime_config_json']);
        if ($config) return $config;
    }
    if (function_exists('ckm_games_hub_runtime_config')) {
        $config = ckm_games_hub_runtime_config();
        if (is_array($config) && !empty($config['runtime']) && isset($config['settings']) && is_array($config['settings'])) {
            return array('runtime'=>sanitize_key((string)$config['runtime']), 'settings'=>$config['settings']);
        }
    }
    // Hard server fallback: a plain ?ckm_format=... link is enough to preserve
    // the intended runtime and its safe defaults through the create-room POST.
    $runtime = '';
    if (isset($input['ckm_format']) && is_string($input['ckm_format'])) {
        $runtime = sanitize_key((string)$input['ckm_format']);
    }
    if ($runtime === '') $runtime = ckmqp_hub_runtime_from_request();
    if ($runtime !== '' && ckmqp_hub_runtime_to_core_format($runtime) !== '') {
        return array('runtime'=>$runtime, 'settings'=>ckmqp_hub_default_runtime_settings($runtime));
    }
    return array();
}

function ckmqp_hub_merge_format_settings(string $formatKey, array $formatSettings, array $input = array()): array {
    $config = ckmqp_hub_request_runtime_config($input);
    if (($formatSettings['negotiationMode'] ?? '') === 'communicate') return $formatSettings;
    if (in_array(($config['runtime'] ?? ''),['persuade_me_v1','persuade_school_v1','persuade_school_grade_v1','persuade_student_v1','persuade_leader_v1','persuade_family_v1'],true)) return $formatSettings;
    if (!$config) return $formatSettings;
    $runtimeFormat = ckmqp_hub_runtime_to_core_format((string)$config['runtime']);
    if ($runtimeFormat === '' || $runtimeFormat !== sanitize_key($formatKey)) return $formatSettings;
    $s = (array)$config['settings'];

    if ($formatKey === 'chgk') {
        if (array_key_exists('discussion_time_seconds', $s)) {
            $formatSettings['discussionSeconds'] = max(60, min(300, (int)$s['discussion_time_seconds']));
        }
        if (array_key_exists('final_answer_seconds', $s)) {
            $formatSettings['finalAnswerSeconds'] = 20;
            $formatSettings['earlyAnswerSeconds'] = 5;
        }
        // Legacy selection/competition switches are ignored by the strict single-team format.
        $formatSettings['questionSelectionMode'] = 'sequential';
        $formatSettings['appealsEnabled'] = false;
        $formatSettings['appealWindowSeconds'] = 0;
        $formatSettings['tieBreakMode'] = 'disabled';
        if (array_key_exists('single_final_answer', $s)) {
            // CHGK core contract is intentionally strict: one final answer stays authoritative.
            $formatSettings['singleFinalAnswer'] = true;
        }
        if (array_key_exists('arbitration_mode', $s)) {
            $formatSettings['judgeMode'] = ckmqp_hub_normalize_judge_mode($s['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'hybrid'));
        }
    } elseif ($formatKey === 'jeopardy') {
        if (array_key_exists('answer_time_seconds', $s)) {
            $formatSettings['buzzerSeconds'] = max(5, min(60, (int)$s['answer_time_seconds']));
        }
        if (array_key_exists('category_choice_enabled', $s)) $formatSettings['categoryChoiceEnabled'] = !empty($s['category_choice_enabled']);
        if (array_key_exists('question_values_enabled', $s)) $formatSettings['questionValuesEnabled'] = !empty($s['question_values_enabled']);
        if (array_key_exists('negative_score_enabled', $s)) $formatSettings['negativeScoreEnabled'] = !empty($s['negative_score_enabled']);
    } elseif ($formatKey === 'solution_price') {
        if (array_key_exists('decision_time_seconds', $s)) {
            $formatSettings['secondsPerStage'] = max(30, min(10800, (int)$s['decision_time_seconds']));
        }
        if (array_key_exists('consequence_round_enabled', $s)) $formatSettings['consequenceRoundEnabled'] = !empty($s['consequence_round_enabled']);
        if (array_key_exists('team_defense_enabled', $s)) $formatSettings['teamDefenseEnabled'] = !empty($s['team_defense_enabled']);
        if (array_key_exists('arbitration_mode', $s)) {
            $formatSettings['judgeMode'] = ckmqp_hub_normalize_judge_mode($s['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'ai'));
        }
    } elseif ($formatKey === 'negotiation_duel') {
        $mode = ckmqp_hub_runtime_negotiation_mode((string)$config['runtime']);
        if ($mode !== '') $formatSettings['negotiationMode'] = $mode;
        if (array_key_exists('round_time_seconds', $s)) $formatSettings['secondsPerTurn'] = max(30, min(600, (int)$s['round_time_seconds']));
        if (array_key_exists('prep_time_seconds', $s)) $formatSettings['prepTimeSeconds'] = max(0, min(600, (int)$s['prep_time_seconds']));
        if (array_key_exists('client_role_mode', $s)) $formatSettings['clientRoleMode'] = sanitize_key((string)$s['client_role_mode']);
        foreach (array(
            'objections_enabled'=>'objectionsEnabled',
            'next_step_required'=>'nextStepRequired',
            'private_briefs_enabled'=>'privateBriefsEnabled',
            'agreement_capture_enabled'=>'agreementCaptureEnabled',
            'role_swap_enabled'=>'roleSwapEnabled',
            'rapid_feedback_enabled'=>'rapidFeedbackEnabled',
        ) as $source=>$target) {
            if (array_key_exists($source, $s)) $formatSettings[$target] = !empty($s[$source]);
        }
        if (array_key_exists('arbitration_mode', $s)) {
            $formatSettings['judgeMode'] = ckmqp_hub_normalize_judge_mode($s['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'ai'));
        }
    }

    return $formatSettings;
}

function ckmqp_hub_merge_room_settings(string $formatKey, array $roomSettings, array $input = array()): array {
    $config = ckmqp_hub_request_runtime_config($input);
    if (!$config) return $roomSettings;
    $runtimeFormat = ckmqp_hub_runtime_to_core_format((string)$config['runtime']);
    if ($runtimeFormat === '' || $runtimeFormat !== sanitize_key($formatKey)) return $roomSettings;
    $s = (array)$config['settings'];

    $seconds = null;
    if (array_key_exists('answer_time_seconds', $s)) $seconds = (int)$s['answer_time_seconds'];
    elseif ($formatKey === 'solution_price' && array_key_exists('decision_time_seconds', $s)) $seconds = (int)$s['decision_time_seconds'];
    elseif ($formatKey === 'negotiation_duel' && array_key_exists('round_time_seconds', $s)) $seconds = (int)$s['round_time_seconds'];
    if ($seconds !== null) {
        $roomSettings['secondsPerQuestion'] = max(5, min(10800, $seconds));
        $roomSettings['hubAnswerTimeOverride'] = true;
    }
    if (array_key_exists('speed_bonus_enabled', $s)) {
        $roomSettings['speedBonusEnabled'] = !empty($s['speed_bonus_enabled']);
    }
    $roomSettings['gamesHubRuntime'] = sanitize_key((string)$config['runtime']);
    return $roomSettings;
}

function ckmqp_hub_requested_core_format(): string {
    return ckmqp_hub_runtime_to_core_format(ckmqp_hub_runtime_from_request());
}
