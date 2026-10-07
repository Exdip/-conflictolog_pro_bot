<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class OpponentContextBuilder {
    private MessageRepository $messages;

    public function __construct(?MessageRepository $messages = null) {
        $this->messages = $messages ?: new MessageRepository();
    }

    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }

    public function build(int $sessionId, int $playerMessageId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new \RuntimeException('Session is not writable.'); }
        $version = Access::version((int) $session['scenario_version_id']);
        $playerMessage = $this->messages->findById($sessionId, $playerMessageId);
        if (!$playerMessage || $playerMessage['actor'] !== 'player' || !in_array($playerMessage['channel'], ['dialogue','negotiation'], true)) {
            throw new \InvalidArgumentException('Player message is unavailable.');
        }

        $factsTable = Schema::table('hidden_facts');
        $facts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT code,title,content,importance,reveal_rules_json,sort_order FROM `$factsTable` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC",
            (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($facts as &$fact) {
            $fact['importance'] = (int) ($fact['importance'] ?? 0);
            $fact['reveal_rules'] = self::decode($fact['reveal_rules_json'] ?? null);
            unset($fact['reveal_rules_json']);
        }
        unset($fact);

        $itemsTable = Schema::table('items');
        $items = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT code,title,value_type,unit,opponent_target_json,opponent_boundary_json,opponent_preference_direction,config_json,sort_order FROM `$itemsTable` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC",
            (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($items as &$item) {
            foreach (['opponent_target_json'=>'opponent_target','opponent_boundary_json'=>'opponent_boundary','config_json'=>'config'] as $source => $target) {
                $item[$target] = self::decode($item[$source] ?? null);
                unset($item[$source]);
            }
            $item['sort_order'] = (int) ($item['sort_order'] ?? 0);
        }
        unset($item);

        $stateTable = Schema::table('item_state');
        $validatedItems = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT i.code,i.title,s.status,s.current_value_json,s.proposed_by,s.bundle_key FROM `$stateTable` s INNER JOIN `$itemsTable` i ON i.id=s.item_id WHERE s.session_id=%d AND i.scenario_version_id=%d ORDER BY i.sort_order ASC,i.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($validatedItems as &$row) {
            $row['current_value'] = self::decode($row['current_value_json'] ?? null);
            unset($row['current_value_json']);
        }
        unset($row);
        $sessionState = self::decode((string)($session['state_json'] ?? ''));
        if (!is_array($sessionState)) { $sessionState = []; }

        $discoveredTable = Schema::table('discovered_facts');
        $validatedFacts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT f.code,f.title,d.reveal_level FROM `$discoveredTable` d INNER JOIN `$factsTable` f ON f.id=d.hidden_fact_id WHERE d.session_id=%d AND f.scenario_version_id=%d AND d.reveal_level>0 ORDER BY f.sort_order ASC,f.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);

        $rulesTable = Schema::table('rules');
        $scenarioRules = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT code,rule_type,priority,condition_json,action_json FROM `$rulesTable` WHERE scenario_version_id=%d AND is_active=1 ORDER BY priority DESC,id ASC",
            (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($scenarioRules as &$rule) {
            $rule['condition'] = self::decode($rule['condition_json'] ?? null);
            $rule['action'] = self::decode($rule['action_json'] ?? null);
            unset($rule['condition_json'], $rule['action_json']);
        }
        unset($rule);

        $mechanics = self::decode($version['mechanics_json'] ?? null) ?: [];
        $salesReplyHistory = [];
        if ((string)($mechanics['training_domain'] ?? '') === 'sales') {
            $messagesTable = Schema::table('messages');
            $salesReplyHistory = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT content FROM `$messagesTable` WHERE session_id=%d AND channel IN ('dialogue','negotiation') AND actor='opponent' ORDER BY sequence_no ASC,id ASC",
                $sessionId
            ), ARRAY_A);
        }

        return [
            'session_id' => (int) $session['id'],
            'scenario_version_id' => (int) $session['scenario_version_id'],
            'difficulty' => DifficultyPolicy::normalize($session['difficulty'] ?? 'medium'),
            'difficulty_label' => DifficultyPolicy::label((string)($session['difficulty'] ?? 'medium')),
            'mechanics' => $mechanics,
            'player_message_id' => $playerMessageId,
            'identity' => [
                'name' => (string) ($version['opponent_name'] ?? 'Оппонент'),
                'role' => (string) ($version['opponent_role'] ?? ''),
                'persona' => self::decode($version['opponent_persona_json'] ?? null),
            ],
            'common_situation' => [
                'player_role' => (string) ($version['player_role'] ?? ''),
                'situation' => (string) ($version['player_situation'] ?? ''),
                'task' => (string) ($version['player_task'] ?? ''),
            ],
            'external_position' => self::decode($version['opponent_external_position_json'] ?? null),
            'hidden_interests' => self::decode($version['opponent_hidden_interests_json'] ?? null),
            'constraints' => self::decode($version['opponent_constraints_json'] ?? null),
            'alternative' => self::decode($version['opponent_alternative_json'] ?? null),
            'concession_space' => self::decode($version['opponent_concession_space_json'] ?? null),
            'walkaway' => self::decode($version['opponent_walkaway_json'] ?? null),
            'hidden_facts' => $facts,
            'items' => $items,
            'scenario_rules' => $scenarioRules,
            'validated_state' => [
                'items' => $validatedItems,
                'discovered_facts' => $validatedFacts,
                'commitments' => CommitmentService::publicProjection($sessionState),
                'relationship' => RelationshipService::internalProjection($sessionState),
                'state_revision' => (int) $session['state_revision'],
            ],
            'recent_dialogue' => $this->messages->listNegotiationForContext($sessionId, 10),
            // SALES-only duplicate guard history. Kept out of prompt builders; it is
            // used solely by SalesClientFallback so an old client reply cannot
            // resurface after it falls outside the short prompt context window.
            'sales_reply_history' => $salesReplyHistory,
        ];
    }
}
