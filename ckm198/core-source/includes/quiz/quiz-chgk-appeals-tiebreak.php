<?php
/**
 * CHGK retired competition extensions compatibility layer.
 *
 * «Битва знатоков» is now one team versus the Game, first to 6. Appeals and
 * multi-team tie-breaks are deliberately unavailable. Legacy function names
 * remain as safe stubs so old API/self-heal code cannot reactivate them.
 */
if (!defined('ABSPATH')) exit;

function ckm_quiz_chgk_competition_settings(array $game): array {
    return array('appealsEnabled'=>false,'appealWindowSeconds'=>0,'tieBreakMode'=>'disabled');
}

function ckm_quiz_chgk_short_key(string $prefix, array $parts): string {
    $prefix = sanitize_key($prefix);
    $raw = $prefix . '-' . implode('-', array_map(static function($v): string {
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$v);
    }, $parts));
    if (strlen($raw) <= 64) return $raw;
    return substr($prefix, 0, 28) . '-' . substr(hash('sha256', $raw), 0, 32);
}

function ckm_quiz_chgk_appeal_window(array $game): array {
    return array('enabled'=>false,'open'=>false,'windowSeconds'=>0,'secondsRemaining'=>0,'questionId'=>(int)($game['current_question_id'] ?? 0));
}
function ckm_quiz_chgk_submit_appeal(array $auth, array $input): array {
    return ckm_quiz_error(409,'В текущей «Битве знатоков» апелляции не используются: итоговый ответ рассматривается в основном арбитраже.','chgk_appeals_disabled');
}
function ckm_quiz_chgk_ai_review_appeal(int $appealId, array $auth): array {
    return ckm_quiz_error(409,'Апелляции отключены для текущего формата «Битва знатоков».','chgk_appeals_disabled');
}
function ckm_quiz_chgk_resolve_appeal(int $appealId, string $decision, array $auth, string $comment = '', ?int $points = null): array {
    return ckm_quiz_error(409,'Апелляции отключены для текущего формата «Битва знатоков».','chgk_appeals_disabled');
}

function ckm_quiz_chgk_tiebreak_active_team_ids(int $gameId): array { return array(); }
function ckm_quiz_chgk_tiebreak_team_eligible(array $game, int $teamId): bool { return true; }
function ckm_quiz_chgk_tiebreak_state(array $game): array {
    return array('enabled'=>false,'active'=>false,'required'=>false,'mode'=>'disabled','leaderTeamIds'=>array(),'reserveQuestions'=>0);
}
function ckm_quiz_chgk_start_tiebreak(int $gameId, array $auth): array {
    return ckm_quiz_error(409,'В одномандатной «Битве знатоков» тай-брейк не используется: матч заканчивается при счёте 6:x или x:6.','chgk_tiebreak_disabled');
}
function ckm_quiz_chgk_finish_guard(array $game, bool $forceSharedPlace = false): array {
    return ckm_quiz_result(true,200);
}
function ckm_quiz_chgk_state_extension(array $game, array $auth): array {
    $out=array(
        'appealWindow'=>ckm_quiz_chgk_appeal_window($game),
        'tiebreak'=>ckm_quiz_chgk_tiebreak_state($game),
    );
    $role=(string)($auth['role'] ?? 'participant');
    if ($role==='host' || $role==='admin') $out['appeals']=array();
    elseif ($role==='participant') $out['yourAppeals']=array();
    elseif ($role==='scoreboard') $out['appealsPublic']=array('pending'=>0);
    return $out;
}
