<?php
/**
 * CHGK sequential question selection compatibility layer.
 *
 * Legacy filename is retained because older manifests require it. The active
 * «Битва знатоков» contract has exactly one order: sequential. Random and
 * roulette settings are ignored and no selection event is emitted.
 */

if (!defined('ABSPATH')) exit;

function ckm_quiz_chgk_question_selection_settings(array $game): array {
    return array(
        'mode'=>'sequential',
        'rouletteEnabled'=>false,
        'randomized'=>false,
        'spinMs'=>0,
    );
}

function ckm_quiz_chgk_random_selection_enabled(array $game): bool {
    return false;
}

function ckm_quiz_chgk_played_main_question_ids(int $gameId): array {
    if ($gameId <= 0) return array();
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT e.question_id
         FROM " . ckm_quiz_events_table() . " e
         INNER JOIN " . ckm_quiz_questions_table() . " q ON q.id=e.question_id
         WHERE e.game_id=%d AND e.action='question_started' AND e.question_id>0
           AND COALESCE(q.question_stage,'main')='main'",
        $gameId
    )) ?: array();
    return array_values(array_unique(array_map('intval', $ids)));
}

function ckm_quiz_chgk_main_question_rows(array $game): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT q.*,COALESCE(r.position,q.round_no) AS _round_position
         FROM " . ckm_quiz_questions_table() . " q
         LEFT JOIN " . ckm_quiz_rounds_table() . " r ON r.id=q.round_id
         WHERE q.quiz_id=%d AND q.quiz_revision=%d AND q.status='active'
           AND COALESCE(q.question_stage,'main')='main'
         ORDER BY COALESCE(r.position,q.round_no) ASC,q.position ASC,q.id ASC",
        (int)($game['quiz_id'] ?? 0),
        (int)($game['quiz_revision'] ?? 0)
    ), ARRAY_A) ?: array();
}

function ckm_quiz_chgk_unplayed_main_rows(array $game): array {
    $played = array_fill_keys(ckm_quiz_chgk_played_main_question_ids((int)($game['id'] ?? 0)), true);
    return array_values(array_filter(ckm_quiz_chgk_main_question_rows($game), static function(array $row) use ($played): bool {
        return empty($played[(int)$row['id']]);
    }));
}

function ckm_quiz_chgk_unplayed_in_round(array $game, int $roundId): array {
    if ($roundId <= 0) return array();
    return array_values(array_filter(ckm_quiz_chgk_unplayed_main_rows($game), static function(array $row) use ($roundId): bool {
        return (int)($row['round_id'] ?? 0) === $roundId;
    }));
}

function ckm_quiz_chgk_round_has_unplayed_main(array $game, int $roundId): bool {
    return count(ckm_quiz_chgk_unplayed_in_round($game, $roundId)) > 0;
}

/**
 * Sequential mode delegates selection to the shared quiz engine.
 */
function ckm_quiz_chgk_select_next_question(array $game, string $actorType = 'host', int $actorUserId = 0): array {
    return array('handled'=>false, 'question'=>null, 'mode'=>'sequential');
}

function ckm_quiz_chgk_question_selection_state(array $game): array {
    $settings = ckm_quiz_chgk_question_selection_settings($game);
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') {
        return array('enabled'=>false,'mode'=>'sequential');
    }
    $remaining = count(ckm_quiz_chgk_unplayed_main_rows($game));
    $roundRemaining = 0;
    if ((int)($game['current_round_id'] ?? 0) > 0) $roundRemaining = count(ckm_quiz_chgk_unplayed_in_round($game, (int)$game['current_round_id']));
    return array(
        'enabled'=>true,
        'mode'=>(string)$settings['mode'],
        'rouletteEnabled'=>!empty($settings['rouletteEnabled']),
        'randomized'=>!empty($settings['randomized']),
        'spinMs'=>(int)$settings['spinMs'],
        'remainingMainQuestions'=>$remaining,
        'remainingInCurrentRound'=>$roundRemaining,
    );
}
