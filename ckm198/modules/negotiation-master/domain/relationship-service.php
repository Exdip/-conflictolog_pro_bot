<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Formal relationship dynamics for the negotiation runtime.
 *
 * The model is deliberately small: overall tension plus credibility of each side.
 * It never changes hard limits, item boundaries, ZOPA, scoring weights or scenario facts.
 * State changes are driven by validated negotiation events, not by free-form LLM mood scores.
 */
final class RelationshipService {
    private const TENSION_ORDER = ['low'=>0, 'normal'=>1, 'high'=>2];
    private const CREDIBILITY = ['normal', 'weakened'];

    public static function initialState(): array {
        return [
            'tension' => 'normal',
            'credibility' => ['player'=>'normal', 'opponent'=>'normal'],
            'last_change_message_id' => 0,
            'last_change_actor' => '',
        ];
    }

    private static function normalized(array $state): array {
        $relationship = is_array($state['relationship'] ?? null) ? $state['relationship'] : [];
        $tension = (string)($relationship['tension'] ?? 'normal');
        if (!array_key_exists($tension, self::TENSION_ORDER)) { $tension = 'normal'; }
        $credibility = is_array($relationship['credibility'] ?? null) ? $relationship['credibility'] : [];
        foreach (['player','opponent'] as $side) {
            $value = (string)($credibility[$side] ?? 'normal');
            $credibility[$side] = in_array($value, self::CREDIBILITY, true) ? $value : 'normal';
        }
        return [
            'tension'=>$tension,
            'credibility'=>$credibility,
            'last_change_message_id'=>(int)($relationship['last_change_message_id'] ?? 0),
            'last_change_actor'=>(string)($relationship['last_change_actor'] ?? ''),
        ];
    }

    private static function shiftTension(string $current, int $delta): string {
        $index = self::TENSION_ORDER[$current] ?? self::TENSION_ORDER['normal'];
        $index = max(0, min(2, $index + $delta));
        return array_search($index, self::TENSION_ORDER, true) ?: 'normal';
    }

    private static function eventConfidence(array $event): float {
        $confidence = $event['confidence'] ?? 1.0;
        return is_numeric($confidence) ? max(0.0, min(1.0, (float)$confidence)) : 1.0;
    }

    private static function relationEvent(string $type, string $actor, int $messageId, array $payload): array {
        return [
            'event_type'=>$type,
            'actor'=>$actor,
            'target_type'=>'session',
            'target_id'=>null,
            'confidence'=>1.0,
            'payload'=>$payload + ['message_id'=>$messageId],
        ];
    }

    /**
     * Apply only server-recognized event consequences.
     * @return array{state:array,events:array,changed:bool,relationship:array}
     */
    public function apply(array $state, array $message, array $events): array {
        $relationship = self::normalized($state);
        $before = $relationship;
        $actor = (string)($message['actor'] ?? '');
        $messageId = (int)($message['id'] ?? 0);
        $audit = [];

        foreach ($events as $event) {
            if (!is_array($event) || self::eventConfidence($event) < 0.80) { continue; }
            $type = (string)($event['event_type'] ?? '');
            $eventActor = (string)($event['actor'] ?? $actor);
            if (!in_array($eventActor, ['player','opponent'], true)) { $eventActor = $actor; }

            $oldTension = $relationship['tension'];
            $newTension = $oldTension;
            if ($type === 'personal_attack') {
                $newTension = 'high';
            } elseif (in_array($type, ['ultimatum','walkaway_warning','walkaway_candidate'], true)) {
                $newTension = self::shiftTension($oldTension, 1);
            } elseif (in_array($type, ['commitment_broken','agreement_backtracking','unjustified_reopen_attempt'], true)) {
                $newTension = self::shiftTension($oldTension, 1);
            } elseif ($type === 'deescalation') {
                $newTension = self::shiftTension($oldTension, -1);
            }
            if ($newTension !== $oldTension) {
                $relationship['tension'] = $newTension;
                $audit[] = self::relationEvent(
                    self::TENSION_ORDER[$newTension] > self::TENSION_ORDER[$oldTension] ? 'tension_increased' : 'tension_reduced',
                    $eventActor,
                    $messageId,
                    ['from'=>$oldTension,'to'=>$newTension,'reason_event'=>$type]
                );
            }

            if (in_array($type, ['commitment_broken','agreement_backtracking','unjustified_reopen_attempt','personal_attack'], true)
                && in_array($eventActor, ['player','opponent'], true)
                && ($relationship['credibility'][$eventActor] ?? 'normal') !== 'weakened') {
                $relationship['credibility'][$eventActor] = 'weakened';
                $audit[] = self::relationEvent('credibility_weakened', $eventActor, $messageId, ['reason_event'=>$type]);
            }
        }

        $changed = $relationship['tension'] !== $before['tension']
            || $relationship['credibility']['player'] !== $before['credibility']['player']
            || $relationship['credibility']['opponent'] !== $before['credibility']['opponent'];
        if ($changed) {
            $relationship['last_change_message_id'] = $messageId;
            $relationship['last_change_actor'] = $actor;
            $state['relationship'] = $relationship;
        } elseif (!isset($state['relationship'])) {
            // Persist the neutral baseline only when this service first participates in a state-changing turn.
            $state['relationship'] = $relationship;
        }
        return ['state'=>$state,'events'=>$audit,'changed'=>$changed,'relationship'=>$relationship];
    }

    /** Internal projection for AI services. Never contains scenario secrets. */
    public static function internalProjection(array $state): array {
        $relationship = self::normalized($state);
        return [
            'tension'=>$relationship['tension'],
            'player_credibility'=>$relationship['credibility']['player'],
            'opponent_credibility'=>$relationship['credibility']['opponent'],
        ];
    }

    /**
     * Post-game, concise evidence-backed dynamics. No numeric gauges and no hidden facts.
     */
    public static function postGameDynamics(array $events): array {
        $rows = [];
        $seen = [];
        foreach ($events as $event) {
            if (!is_array($event)) { continue; }
            $type = (string)($event['event_type'] ?? '');
            if (!in_array($type, ['tension_increased','tension_reduced','credibility_weakened'], true)) { continue; }
            $actor = (string)($event['actor'] ?? '');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $reason = (string)($payload['reason_event'] ?? '');
            $messageId = (int)($event['message_id'] ?? $payload['message_id'] ?? 0);
            $key = $type . '|' . $actor . '|' . $reason;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;

            if ($type === 'tension_increased') {
                $text = $actor === 'player'
                    ? 'Напряжение в переговорах выросло после вашей жёсткой эскалации.'
                    : 'Напряжение в переговорах выросло после жёсткой реакции оппонента.';
            } elseif ($type === 'tension_reduced') {
                $text = $actor === 'player'
                    ? 'Вам удалось снизить напряжение и вернуть разговор к более рабочему тону.'
                    : 'Оппонент снизил напряжение и вернул разговор к более рабочему тону.';
            } else {
                $text = $actor === 'player'
                    ? 'Надёжность вашей позиции снизилась из-за непоследовательности или нарушения ранее зафиксированного обязательства.'
                    : 'Надёжность позиции оппонента снизилась из-за его непоследовательности или нарушения обязательства.';
            }
            $rows[] = ['text'=>$text,'evidence_message_ids'=>$messageId > 0 ? [$messageId] : []];
            if (count($rows) >= 4) { break; }
        }
        return $rows;
    }
}
