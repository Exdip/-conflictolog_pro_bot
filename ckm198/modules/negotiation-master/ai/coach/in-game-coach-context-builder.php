<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Builds the in-game coach context strictly from the same projection that is safe
 * to show to the player. Hidden opponent data is intentionally impossible to reach
 * through this builder.
 */
final class InGameCoachContextBuilder {
    private PlayerSessionSnapshotBuilder $snapshots;

    public function __construct(?PlayerSessionSnapshotBuilder $snapshots = null) {
        $this->snapshots = $snapshots ?: new PlayerSessionSnapshotBuilder();
    }

    public function build(int $sessionId, string $helpLevel): array {
        if (!in_array($helpLevel, ['attention','direction','example','review_last_move'], true)) {
            throw new \InvalidArgumentException('Unsupported coach help level.');
        }
        $session = Access::session($sessionId);
        if (($session['status'] ?? '') !== 'in_progress') { throw new \RuntimeException('Session is not active.'); }
        if (($session['mode'] ?? '') !== 'training') { throw new CoachNotAvailableException('Coach is unavailable in exam mode.'); }
        if (($session['processing_status'] ?? '') !== 'idle') { throw new CoachBusyException('Подождите завершения текущего хода.'); }

        $snapshot = $this->snapshots->build($sessionId);
        $version = Access::version((int)$session['scenario_version_id']);
        $mechanics = [];
        try { $decoded = json_decode((string)($version['mechanics_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR); if (is_array($decoded)) { $mechanics = $decoded; } } catch (\Throwable) {}
        $messages = array_values(array_filter((array)($snapshot['messages'] ?? []), static function(array $row): bool {
            return in_array((string)($row['actor'] ?? ''), ['player','opponent'], true)
                && in_array((string)($row['channel'] ?? ''), ['dialogue','negotiation'], true);
        }));
        $recentDialogue = array_slice($messages, -10);
        $previousHints = array_slice((array)($snapshot['coach_messages'] ?? []), -6);
        $lastPlayer = null;
        for ($i = count($messages)-1; $i >= 0; $i--) {
            if (($messages[$i]['actor'] ?? '') === 'player') { $lastPlayer = $messages[$i]; break; }
        }
        if ($helpLevel === 'review_last_move' && !$lastPlayer) {
            throw new \InvalidArgumentException('Сначала сделайте хотя бы одну реплику.');
        }

        $scenario = (array)($snapshot['scenario'] ?? []);
        return [
            'session_id' => $sessionId,
            'mode' => 'training',
            'training_domain' => (string)($mechanics['training_domain'] ?? 'negotiation'),
            'help_level' => $helpLevel,
            'player_card' => [
                'role' => (string)($scenario['player_role'] ?? ''),
                'situation' => (string)($scenario['player_situation'] ?? ''),
                'task' => (string)($scenario['player_task'] ?? ''),
                'known_facts' => $scenario['player_known_facts'] ?? null,
                'ideal_result' => $scenario['player_ideal_result'] ?? null,
                'target_result' => $scenario['player_target_result'] ?? null,
                'alternative' => $scenario['player_alternative'] ?? null,
                'red_lines' => $scenario['player_red_lines'] ?? null,
                'opponent' => [
                    'name' => (string)($scenario['opponent']['name'] ?? ''),
                    'role' => (string)($scenario['opponent']['role'] ?? ''),
                ],
            ],
            'visible_items' => array_map(static function(array $row): array {
                return array_intersect_key($row, array_flip(['item_id','code','title','value_type','unit','required_for_agreement','status','current_value','proposed_by','bundle_key']));
            }, (array)($snapshot['items'] ?? [])),
            'visible_commitments' => (array)($snapshot['commitments'] ?? []),
            'visible_facts' => array_map(static function(array $row): array {
                return array_intersect_key($row, array_flip(['reveal_level','confidence','title','content','sort_order']));
            }, (array)($snapshot['discovered_facts'] ?? [])),
            'recent_dialogue' => array_map(static function(array $row): array {
                return array_intersect_key($row, array_flip(['id','turn_no','actor','content']));
            }, $recentDialogue),
            'previous_hints' => array_map(static function(array $row): array {
                return array_intersect_key($row, array_flip(['id','turn_no','coach_level','related_message_id','content']));
            }, $previousHints),
            'last_player_message' => $lastPlayer ? array_intersect_key($lastPlayer, array_flip(['id','turn_no','content'])) : null,
            'coach_counts' => (array)($snapshot['coach_counts'] ?? []),
        ];
    }
}
