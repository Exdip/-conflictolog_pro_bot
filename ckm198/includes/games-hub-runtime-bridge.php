<?php
/**
 * Compatibility bridge between CKM Games Hub public catalog and Quiz Pro.
 *
 * Hub exposes eight marketing runtimes while Quiz Pro intentionally keeps five
 * server runtimes. Sales / business negotiations / express round share the
 * negotiation_duel engine and differ only by negotiationMode.
 */
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_hub_runtime_map(): array {
    return array(
        'classic_quiz_v1'          => array('format_key'=>'classic_quiz','mode'=>''),
        'chgk_v1'                  => array('format_key'=>'chgk','mode'=>''),
        'jeopardy_v1'              => array('format_key'=>'jeopardy','mode'=>''),
        'decision_price_v1'        => array('format_key'=>'solution_price','mode'=>''),
        'persuade_me_v1'           => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'persuade_school_v1'       => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'persuade_school_grade_v1' => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'persuade_student_v1'      => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'persuade_leader_v1'       => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'persuade_family_v1'       => array('format_key'=>'negotiation_duel','mode'=>'communicate'),
        'sales_v1'                 => array('format_key'=>'negotiation_duel','mode'=>'sales'),
        'business_negotiation_v1'  => array('format_key'=>'negotiation_duel','mode'=>'business'),
        'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express'),
        // Backward-compatible technical aliases already used by Quiz Pro.
        'negotiation_duel_v1'      => array('format_key'=>'negotiation_duel','mode'=>'sales'),
    );
}

function ckm_quiz_pro_hub_selection_from_runtime(string $runtime): array {
    $runtime = sanitize_key($runtime);
    $map = ckm_quiz_pro_hub_runtime_map();
    if (!isset($map[$runtime])) return array();
    return array(
        'runtime'=>$runtime,
        'format_key'=>(string)$map[$runtime]['format_key'],
        'mode'=>(string)$map[$runtime]['mode'],
    );
}

function ckm_quiz_pro_hub_request_runtime_payload(): array {
    $raw = '';
    if (isset($_REQUEST['ckm_runtime_config_json'])) {
        $raw = wp_unslash($_REQUEST['ckm_runtime_config_json']);
    }
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['runtime'])) {
            $selection = ckm_quiz_pro_hub_selection_from_runtime((string)$decoded['runtime']);
            if ($selection) {
                $selection['settings'] = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : array();
                return $selection;
            }
        }
    }

    $runtime = '';
    if (isset($_REQUEST['ckm_format']) && is_string($_REQUEST['ckm_format'])) {
        $runtime = sanitize_key(wp_unslash($_REQUEST['ckm_format']));
    }
    if ($runtime === '' && isset($_GET['format']) && is_string($_GET['format'])) {
        $candidate = sanitize_key(wp_unslash($_GET['format']));
        if (isset(ckm_quiz_pro_hub_runtime_map()[$candidate])) $runtime = $candidate;
    }
    $selection = $runtime !== '' ? ckm_quiz_pro_hub_selection_from_runtime($runtime) : array();
    if ($selection) $selection['settings'] = array();
    return $selection;
}

function ckm_quiz_pro_hub_requested_selection(): array {
    return ckm_quiz_pro_hub_request_runtime_payload();
}

function ckm_quiz_pro_hub_bool($value, bool $fallback = false): bool {
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return (int)$value !== 0;
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, array('1','true','yes','on'), true)) return true;
        if (in_array($value, array('0','false','no','off',''), true)) return false;
    }
    return $fallback;
}

function ckm_quiz_pro_hub_judge_mode($value, string $fallback = 'hybrid'): string {
    $value = sanitize_key((string)$value);
    $map = array('ai'=>'ai','host'=>'human','human'=>'human','ai_or_host'=>'hybrid','hybrid'=>'hybrid');
    return $map[$value] ?? (in_array($fallback,array('ai','human','hybrid'),true) ? $fallback : 'hybrid');
}

function ckm_quiz_pro_hub_runtime_seconds(string $formatKey, int $fallback, array $runtimeSettings): int {
    $formatKey = sanitize_key($formatKey);
    $seconds = $fallback;
    if ($formatKey === 'chgk' && isset($runtimeSettings['discussion_time_seconds'])) {
        $seconds = (int)$runtimeSettings['discussion_time_seconds'];
        return max(60, min(300, $seconds));
    }
    if ($formatKey === 'solution_price' && isset($runtimeSettings['decision_time_seconds'])) {
        $seconds = (int)$runtimeSettings['decision_time_seconds'];
        return max(30, min(10800, $seconds));
    }
    if ($formatKey === 'negotiation_duel' && isset($runtimeSettings['round_time_seconds'])) {
        $seconds = (int)$runtimeSettings['round_time_seconds'];
        return max(30, min(600, $seconds));
    }
    if (isset($runtimeSettings['answer_time_seconds'])) {
        $seconds = (int)$runtimeSettings['answer_time_seconds'];
    }
    return max(5, min(3600, $seconds));
}

function ckm_quiz_pro_hub_merge_format_settings(string $formatKey, array $formatSettings, array $runtimeSettings, string $negotiationMode = ''): array {
    $formatKey = sanitize_key($formatKey);

    if ($formatKey === 'classic_quiz') {
        if (array_key_exists('speed_bonus_enabled', $runtimeSettings)) {
            $formatSettings['speedBonusEnabled'] = ckm_quiz_pro_hub_bool($runtimeSettings['speed_bonus_enabled'], true);
        }
    } elseif ($formatKey === 'chgk') {
        if (isset($runtimeSettings['discussion_time_seconds'])) {
            $formatSettings['discussionSeconds'] = max(60, min(300, (int)$runtimeSettings['discussion_time_seconds']));
        }
        if (isset($runtimeSettings['final_answer_seconds'])) {
            $formatSettings['finalAnswerSeconds'] = 20;
            $formatSettings['earlyAnswerSeconds'] = 5;
        }
        if (array_key_exists('single_final_answer', $runtimeSettings)) {
            $formatSettings['singleFinalAnswer'] = ckm_quiz_pro_hub_bool($runtimeSettings['single_final_answer'], true);
        }
        // Legacy selection/competition switches are ignored by the strict single-team format.
        $formatSettings['questionSelectionMode'] = 'sequential';
        $formatSettings['appealsEnabled'] = false;
        $formatSettings['appealWindowSeconds'] = 0;
        $formatSettings['tieBreakMode'] = 'disabled';
        if (isset($runtimeSettings['arbitration_mode'])) {
            $formatSettings['judgeMode'] = ckm_quiz_pro_hub_judge_mode($runtimeSettings['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'hybrid'));
        }
    } elseif ($formatKey === 'jeopardy') {
        if (isset($runtimeSettings['answer_time_seconds'])) {
            $formatSettings['buzzerSeconds'] = max(5, min(60, (int)$runtimeSettings['answer_time_seconds']));
        }
        foreach (array(
            'category_choice_enabled'=>'categoryChoiceEnabled',
            'question_values_enabled'=>'questionValuesEnabled',
            'negative_score_enabled'=>'negativeScoreEnabled',
        ) as $source=>$target) {
            if (array_key_exists($source, $runtimeSettings)) $formatSettings[$target] = ckm_quiz_pro_hub_bool($runtimeSettings[$source], true);
        }
    } elseif ($formatKey === 'solution_price') {
        if (isset($runtimeSettings['decision_time_seconds'])) {
            $formatSettings['secondsPerStage'] = max(30, min(10800, (int)$runtimeSettings['decision_time_seconds']));
        }
        if (array_key_exists('consequence_round_enabled', $runtimeSettings)) {
            $formatSettings['consequenceRoundEnabled'] = ckm_quiz_pro_hub_bool($runtimeSettings['consequence_round_enabled'], true);
        }
        if (array_key_exists('team_defense_enabled', $runtimeSettings)) {
            $formatSettings['teamDefenseEnabled'] = ckm_quiz_pro_hub_bool($runtimeSettings['team_defense_enabled'], true);
        }
        if (isset($runtimeSettings['arbitration_mode'])) {
            $formatSettings['judgeMode'] = ckm_quiz_pro_hub_judge_mode($runtimeSettings['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'ai'));
        }
    } elseif ($formatKey === 'negotiation_duel') {
        if ($negotiationMode !== '') {
            $formatSettings['negotiationMode'] = function_exists('ckm_quiz_pro_negotiation_mode') ? ckm_quiz_pro_negotiation_mode($negotiationMode) : sanitize_key($negotiationMode);
        }
        if (isset($runtimeSettings['round_time_seconds'])) {
            $formatSettings['secondsPerTurn'] = max(30, min(600, (int)$runtimeSettings['round_time_seconds']));
        }
        foreach (array(
            'objections_enabled'=>'objectionsEnabled',
            'next_step_required'=>'nextStepRequired',
            'private_briefs_enabled'=>'privateBriefsEnabled',
            'agreement_capture_enabled'=>'agreementCaptureEnabled',
            'role_swap_enabled'=>'roleSwapEnabled',
            'rapid_feedback_enabled'=>'rapidFeedbackEnabled',
        ) as $source=>$target) {
            if (array_key_exists($source, $runtimeSettings)) $formatSettings[$target] = ckm_quiz_pro_hub_bool($runtimeSettings[$source], true);
        }
        if (isset($runtimeSettings['prep_time_seconds'])) {
            $formatSettings['prepTimeSeconds'] = max(0, min(600, (int)$runtimeSettings['prep_time_seconds']));
        }
        if (isset($runtimeSettings['client_role_mode'])) {
            $formatSettings['clientRoleMode'] = sanitize_key((string)$runtimeSettings['client_role_mode']);
        }
        if (isset($runtimeSettings['arbitration_mode'])) {
            $formatSettings['judgeMode'] = ckm_quiz_pro_hub_judge_mode($runtimeSettings['arbitration_mode'], (string)($formatSettings['judgeMode'] ?? 'ai'));
        }
    }

    return $formatSettings;
}

function ckm_quiz_pro_hub_apply_quiz_configuration(string $formatKey, array $formatSettings, int $seconds, string $negotiationMode = ''): array {
    $request = ckm_quiz_pro_hub_request_runtime_payload();
    $runtimeSettings = isset($request['settings']) && is_array($request['settings']) ? $request['settings'] : array();
    if ($request && !empty($request['format_key']) && sanitize_key((string)$request['format_key']) === sanitize_key($formatKey)) {
        if ($negotiationMode === '' && !empty($request['mode'])) $negotiationMode = (string)$request['mode'];
        $seconds = ckm_quiz_pro_hub_runtime_seconds($formatKey, $seconds, $runtimeSettings);
        $formatSettings = ckm_quiz_pro_hub_merge_format_settings($formatKey, $formatSettings, $runtimeSettings, $negotiationMode);
    }
    return array('seconds'=>$seconds,'format_settings'=>$formatSettings,'selection'=>$request);
}
