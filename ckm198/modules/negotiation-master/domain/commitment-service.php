<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Formalizes explicit promises made in the negotiation dialogue.
 * Commitments live in the authoritative session state_json; event history remains in ckm_neg_events.
 * The service never infers a binding promise from acknowledgement/willingness alone.
 */
final class CommitmentService {
    private const KINDS = ['result','effort','responsibility'];
    private const ACTIONS = ['create','clarify','break'];

    private static function clean(mixed $value, int $max = 500): string {
        $text = trim(strip_tags((string)$value));
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $max, 'UTF-8'); }
        return substr($text, 0, $max);
    }

    private static function containsCue(string $text, string $cue): bool {
        return preg_match('/' . preg_quote($cue, '/') . '/iu', $text) === 1;
    }

    private static function explicitCommitmentCue(string $text, string $kind): bool {
        if ($kind === 'effort') {
            foreach (['постараюсь','постараемся','попробую','попробуем','сделаю всё возможное','сделаем всё возможное'] as $cue) {
                if (self::containsCue($text, $cue)) { return true; }
            }
            return false;
        }
        foreach ([
            'обязуюсь','обязуемся','беру на себя','берём на себя','возьму на себя','возьмём на себя',
            'гарантирую','гарантируем','обеспечу','обеспечим','предоставлю','предоставим','сделаю','сделаем',
            'подготовлю','подготовим','отправлю','отправим','передам','передадим','выполню','выполним',
            'исправлю','исправим','закрою','закроем','назначу','назначим'
        ] as $cue) {
            if (self::containsCue($text, $cue)) { return true; }
        }
        return false;
    }

    private static function breakCue(string $text): bool {
        foreach ([
            'не смогу','не сможем','не буду','не будем','отказываюсь','отказываемся','не получится',
            'не выполним','не выполню','не предоставим','не предоставлю','не сделаем','не сделаю',
            'снимаю обязательство','снимаем обязательство','отзываю обещание','отзываем обещание'
        ] as $cue) {
            if (self::containsCue($text, $cue)) { return true; }
        }
        return false;
    }

    private static function nextId(array $commitments): int {
        $max = 0;
        foreach ($commitments as $row) { $max = max($max, (int)($row['id'] ?? 0)); }
        return $max + 1;
    }

    /**
     * @return array{state:array,events:array,changed:bool,applied:array}
     */
    public function apply(array $state, array $message, array $updates): array {
        $commitments = is_array($state['commitments'] ?? null) ? array_values($state['commitments']) : [];
        $actor = (string)($message['actor'] ?? '');
        if (!in_array($actor, ['player','opponent'], true)) {
            return ['state'=>$state,'events'=>[],'changed'=>false,'applied'=>[]];
        }
        $text = (string)($message['content'] ?? '');
        $messageId = (int)($message['id'] ?? 0);
        $events = []; $applied = []; $changed = false;

        foreach (array_slice($updates, 0, 8) as $update) {
            if (!is_array($update)) { continue; }
            $action = (string)($update['action'] ?? '');
            if (!in_array($action, self::ACTIONS, true)) { continue; }
            $confidence = is_numeric($update['confidence'] ?? null) ? (float)$update['confidence'] : 0.0;
            if ($confidence < 0.80 || $confidence > 1.0) { continue; }

            if ($action === 'create') {
                $kind = (string)($update['kind'] ?? 'result');
                if (!in_array($kind, self::KINDS, true)) { $kind = 'result'; }
                if (!self::explicitCommitmentCue($text, $kind)) { continue; }
                $semanticSummary = self::clean($update['summary'] ?? '', 420);
                if ($semanticSummary === '') { continue; }
                // Player-visible wording is always grounded in the exact utterance, never in hidden arbiter context.
                $summary = self::clean($text, 420);
                if ($summary === '') { $summary = $semanticSummary; }
                $condition = self::clean($update['condition'] ?? '', 320);
                $deadline = self::clean($update['deadline'] ?? '', 120);

                $duplicate = false;
                foreach ($commitments as $existing) {
                    if ((int)($existing['created_message_id'] ?? 0) === $messageId && (string)($existing['summary'] ?? '') === $summary) { $duplicate = true; break; }
                }
                if ($duplicate) { continue; }

                $id = self::nextId($commitments);
                $row = [
                    'id'=>$id,'actor'=>$actor,'kind'=>$kind,'summary'=>$summary,'condition'=>$condition,'deadline'=>$deadline,
                    'status'=>'active','created_message_id'=>$messageId,'updated_message_id'=>$messageId,
                    'conditional'=>$condition !== '',
                ];
                $commitments[] = $row; $changed = true; $applied[] = $row;
                $events[] = [
                    'event_type'=>$condition !== '' ? 'conditional_commitment' : ($kind === 'responsibility' ? 'responsibility_accepted' : 'commitment_made'),
                    'actor'=>$actor,'target_type'=>'message','target_id'=>$messageId,'confidence'=>$confidence,
                    'payload'=>['commitment_id'=>$id,'kind'=>$kind,'summary'=>$summary,'condition'=>$condition,'deadline'=>$deadline],
                ];
                continue;
            }

            $id = (int)($update['commitment_id'] ?? 0);
            if ($id <= 0) { continue; }
            $index = null;
            foreach ($commitments as $i => $existing) {
                if ((int)($existing['id'] ?? 0) === $id && (string)($existing['actor'] ?? '') === $actor) { $index = $i; break; }
            }
            if ($index === null) { continue; }
            $existing = $commitments[$index];
            if (!in_array((string)($existing['status'] ?? ''), ['active','at_risk'], true)) { continue; }

            if ($action === 'clarify') {
                $summary = array_key_exists('summary', $update) ? self::clean($text, 420) : self::clean($existing['summary'] ?? '', 420);
                $condition = self::clean($update['condition'] ?? ($existing['condition'] ?? ''), 320);
                $deadline = self::clean($update['deadline'] ?? ($existing['deadline'] ?? ''), 120);
                if ($summary === '') { continue; }
                if ($summary === (string)($existing['summary'] ?? '') && $condition === (string)($existing['condition'] ?? '') && $deadline === (string)($existing['deadline'] ?? '')) { continue; }
                $commitments[$index]['summary'] = $summary;
                $commitments[$index]['condition'] = $condition;
                $commitments[$index]['deadline'] = $deadline;
                $commitments[$index]['conditional'] = $condition !== '';
                $commitments[$index]['updated_message_id'] = $messageId;
                $changed = true; $applied[] = $commitments[$index];
                $events[] = [
                    'event_type'=>'commitment_clarified','actor'=>$actor,'target_type'=>'message','target_id'=>$messageId,'confidence'=>$confidence,
                    'payload'=>['commitment_id'=>$id,'summary'=>$summary,'condition'=>$condition,'deadline'=>$deadline],
                ];
                continue;
            }

            if ($action === 'break' && self::breakCue($text)) {
                $triggerSatisfied = !empty($update['condition_satisfied']);
                $conditional = !empty($existing['conditional']);
                $status = ($conditional && !$triggerSatisfied) ? 'at_risk' : 'broken';
                if ((string)($existing['status'] ?? '') === $status) { continue; }
                $commitments[$index]['status'] = $status;
                $commitments[$index]['updated_message_id'] = $messageId;
                $commitments[$index]['break_message_id'] = $messageId;
                $changed = true; $applied[] = $commitments[$index];
                $events[] = [
                    'event_type'=>$status === 'broken' ? 'commitment_broken' : 'commitment_at_risk',
                    'actor'=>$actor,'target_type'=>'message','target_id'=>$messageId,'confidence'=>$confidence,
                    'payload'=>['commitment_id'=>$id,'summary'=>(string)($existing['summary'] ?? ''),'conditional'=>$conditional],
                ];
            }
        }

        if ($changed) { $state['commitments'] = array_values($commitments); }
        return ['state'=>$state,'events'=>$events,'changed'=>$changed,'applied'=>$applied];
    }

    public static function publicProjection(array $state): array {
        $rows = is_array($state['commitments'] ?? null) ? $state['commitments'] : [];
        $safe = [];
        foreach (array_slice($rows, -30) as $row) {
            if (!is_array($row)) { continue; }
            $safe[] = [
                'id'=>(int)($row['id'] ?? 0),
                'actor'=>(string)($row['actor'] ?? ''),
                'kind'=>(string)($row['kind'] ?? 'result'),
                'summary'=>self::clean($row['summary'] ?? '', 420),
                'condition'=>'',
                'deadline'=>'',
                'status'=>(string)($row['status'] ?? 'active'),
                'created_message_id'=>(int)($row['created_message_id'] ?? 0),
                'updated_message_id'=>(int)($row['updated_message_id'] ?? 0),
            ];
        }
        return $safe;
    }
}
