<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ArbiterContextBuilder {
    private MessageRepository $messages;

    public function __construct(?MessageRepository $messages = null) {
        $this->messages = $messages ?: new MessageRepository();
    }

    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }

    public function build(int $sessionId, int $messageId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new \RuntimeException('Session is not analyzable.'); }
        $message = $this->messages->findById($sessionId, $messageId);
        if (!$message || !in_array($message['actor'], ['player','opponent'], true) || !in_array($message['channel'], ['dialogue','negotiation'], true)) {
            throw new \InvalidArgumentException('Negotiation message is unavailable.');
        }
        $version = Access::version((int) $session['scenario_version_id']);

        $itemsTable = Schema::table('items');
        $stateTable = Schema::table('item_state');
        $items = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT i.*,s.status AS state_status,s.current_value_json,s.proposed_by,s.bundle_key
             FROM `$itemsTable` i LEFT JOIN `$stateTable` s ON s.item_id=i.id AND s.session_id=%d
             WHERE i.scenario_version_id=%d ORDER BY i.sort_order ASC,i.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($items as &$item) {
            foreach (['player_target_json'=>'player_target','player_boundary_json'=>'player_boundary','opponent_target_json'=>'opponent_target','opponent_boundary_json'=>'opponent_boundary','config_json'=>'config','current_value_json'=>'current_value'] as $source => $target) {
                $item[$target] = self::decode($item[$source] ?? null);
                unset($item[$source]);
            }
            $item['id'] = (int) $item['id'];
            $item['required_for_agreement'] = (int) $item['required_for_agreement'];
            $item['importance_weight'] = (float) $item['importance_weight'];
            $item['sort_order'] = (int) $item['sort_order'];
        }
        unset($item);

        $factsTable = Schema::table('hidden_facts');
        $discoveredTable = Schema::table('discovered_facts');
        $facts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT f.*,COALESCE(d.reveal_level,0) AS current_reveal_level,d.confidence AS reveal_confidence
             FROM `$factsTable` f LEFT JOIN `$discoveredTable` d ON d.hidden_fact_id=f.id AND d.session_id=%d
             WHERE f.scenario_version_id=%d ORDER BY f.sort_order ASC,f.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($facts as &$fact) {
            $fact['id'] = (int) $fact['id'];
            $fact['importance'] = (float) $fact['importance'];
            $fact['initial_level'] = (int) $fact['initial_level'];
            $fact['current_reveal_level'] = (int) $fact['current_reveal_level'];
            $fact['reveal_rules'] = self::decode($fact['reveal_rules_json'] ?? null);
            unset($fact['reveal_rules_json']);
        }
        unset($fact);

        $rulesTable = Schema::table('rules');
        $rules = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,code,rule_type,priority,condition_json,action_json FROM `$rulesTable` WHERE scenario_version_id=%d AND is_active=1 ORDER BY priority DESC,id ASC",
            (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($rules as &$rule) {
            $rule['id'] = (int) $rule['id'];
            $rule['priority'] = (int) $rule['priority'];
            $rule['condition'] = self::decode($rule['condition_json'] ?? null);
            $rule['action'] = self::decode($rule['action_json'] ?? null);
            unset($rule['condition_json'], $rule['action_json']);
        }
        unset($rule);

        $state = self::decode((string) ($session['state_json'] ?? ''));
        if (!is_array($state)) { $state = []; }

        return [
            'session_id' => (int) $session['id'],
            'scenario_version_id' => (int) $session['scenario_version_id'],
            'mechanics' => self::decode($version['mechanics_json'] ?? null) ?: [],
            'target_message' => [
                'id' => (int) $message['id'],
                'sequence_no' => (int) $message['sequence_no'],
                'turn_no' => (int) $message['turn_no'],
                'actor' => (string) $message['actor'],
                'content' => (string) $message['content'],
            ],
            'scenario' => [
                'player_role' => (string) ($version['player_role'] ?? ''),
                'player_situation' => (string) ($version['player_situation'] ?? ''),
                'player_task' => (string) ($version['player_task'] ?? ''),
                'opponent_name' => (string) ($version['opponent_name'] ?? ''),
                'opponent_role' => (string) ($version['opponent_role'] ?? ''),
            ],
            'items' => $items,
            'hidden_facts' => $facts,
            'rules' => $rules,
            'validated_state' => [
                'state_revision' => (int) $session['state_revision'],
                'arbiter' => is_array($state['arbiter'] ?? null) ? $state['arbiter'] : [],
                'commitments' => CommitmentService::publicProjection($state),
                'relationship' => RelationshipService::internalProjection($state),
            ],
            'recent_dialogue' => $this->messages->listNegotiationForContext($sessionId, 8),
        ];
    }
}
