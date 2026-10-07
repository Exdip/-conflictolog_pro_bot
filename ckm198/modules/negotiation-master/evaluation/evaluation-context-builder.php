<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class EvaluationContextBuilder {
    private static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($v) ? $v : []; }
        catch (\Throwable) { return []; }
    }

    public function build(int $sessionId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        if (!str_starts_with((string)$session['status'], 'completed_')) { throw new \RuntimeException('Evaluation is available only for completed sessions.'); }

        $version = Access::version((int)$session['scenario_version_id']);
        $messagesT = Schema::table('messages');
        $eventsT = Schema::table('events');
        $itemsT = Schema::table('items');
        $stateT = Schema::table('item_state');
        $factsT = Schema::table('hidden_facts');
        $discoveredT = Schema::table('discovered_facts');
        $agreementsT = Schema::table('agreements');
        $rulesT = Schema::table('evaluation_rules');
        $scenarioRulesT = Schema::table('rules');

        $messages = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT id,sequence_no,turn_no,actor,content,input_type FROM `$messagesT` WHERE session_id=%d AND channel IN ('dialogue','negotiation') AND actor IN ('player','opponent') ORDER BY sequence_no ASC,id ASC",
            $sessionId
        ), ARRAY_A);

        $events = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT id,message_id,sequence_no,event_type,actor,target_type,target_id,confidence,payload_json FROM `$eventsT` WHERE session_id=%d ORDER BY sequence_no ASC,id ASC",
            $sessionId
        ), ARRAY_A);
        foreach ($events as &$e) { $e['payload'] = self::decode($e['payload_json'] ?? null); unset($e['payload_json']); }
        unset($e);

        $items = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT i.*,s.status AS state_status,s.current_value_json,s.proposed_by,s.bundle_key FROM `$itemsT` i LEFT JOIN `$stateT` s ON s.item_id=i.id AND s.session_id=%d WHERE i.scenario_version_id=%d ORDER BY i.sort_order ASC,i.id ASC",
            $sessionId, (int)$session['scenario_version_id']
        ), ARRAY_A);
        foreach ($items as &$item) {
            foreach (['player_target_json','player_boundary_json','opponent_target_json','opponent_boundary_json','config_json','current_value_json'] as $field) {
                $item[str_replace('_json','',$field)] = self::decode($item[$field] ?? null);
                unset($item[$field]);
            }
        }
        unset($item);

        $facts = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT f.id,f.code,f.title,f.content,f.importance,COALESCE(d.reveal_level,0) AS reveal_level,d.first_discovered_message_id FROM `$factsT` f LEFT JOIN `$discoveredT` d ON d.hidden_fact_id=f.id AND d.session_id=%d WHERE f.scenario_version_id=%d ORDER BY f.sort_order ASC,f.id ASC",
            $sessionId, (int)$session['scenario_version_id']
        ), ARRAY_A);

        $rules = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$rulesT` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC",
            (int)$session['scenario_version_id']
        ), ARRAY_A);
        foreach ($rules as &$rule) {
            $rule['rubric'] = self::decode($rule['rubric_json'] ?? null);
            $rule['config'] = self::decode($rule['config_json'] ?? null);
            unset($rule['rubric_json'], $rule['config_json']);
        }
        unset($rule);

        $scenarioRules = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT code,rule_type,priority,condition_json,action_json,is_active FROM `$scenarioRulesT` WHERE scenario_version_id=%d AND is_active=1 ORDER BY priority DESC,id ASC",
            (int)$session['scenario_version_id']
        ), ARRAY_A);
        foreach ($scenarioRules as &$scenarioRule) {
            $scenarioRule['condition'] = self::decode($scenarioRule['condition_json'] ?? null);
            $scenarioRule['action'] = self::decode($scenarioRule['action_json'] ?? null);
            unset($scenarioRule['condition_json'], $scenarioRule['action_json']);
        }
        unset($scenarioRule);

        $agreement = null;
        if (!empty($session['final_agreement_id'])) {
            $agreement = $wpdb->get_row($wpdb->prepare("SELECT id,proposal_no,status,package_json,php_validation_json,created_at,responded_at FROM `$agreementsT` WHERE id=%d AND session_id=%d", (int)$session['final_agreement_id'], $sessionId), ARRAY_A) ?: null;
            if ($agreement) {
                $agreement['package'] = self::decode($agreement['package_json'] ?? null);
                $agreement['php_validation'] = self::decode($agreement['php_validation_json'] ?? null);
                unset($agreement['package_json'], $agreement['php_validation_json']);
            }
        }

        $state = self::decode($session['state_json'] ?? null);
        $completion = is_array($state['completion'] ?? null) ? $state['completion'] : [];

        return [
            'mechanics' => self::decode($version['mechanics_json'] ?? null),
            'session' => [
                'id'=>(int)$session['id'],'status'=>(string)$session['status'],'mode'=>(string)$session['mode'],
                'scenario_id'=>(int)$session['scenario_id'],'scenario_version_id'=>(int)$session['scenario_version_id'],
                'completed_at'=>$session['completed_at'],'state_revision'=>(int)$session['state_revision'],
            ],
            'player_card' => [
                'role'=>(string)($version['player_role'] ?? ''),'situation'=>(string)($version['player_situation'] ?? ''),'task'=>(string)($version['player_task'] ?? ''),
                'known_facts'=>self::decode($version['player_known_facts_json'] ?? null),'ideal_result'=>self::decode($version['player_ideal_result_json'] ?? null),
                'target_result'=>self::decode($version['player_target_result_json'] ?? null),'alternative'=>self::decode($version['player_alternative_json'] ?? null),
                'red_lines'=>self::decode($version['player_red_lines_json'] ?? null),
            ],
            'opponent_card' => [
                'name'=>(string)($version['opponent_name'] ?? ''),'role'=>(string)($version['opponent_role'] ?? ''),
                'persona'=>self::decode($version['opponent_persona_json'] ?? null),'external_position'=>self::decode($version['opponent_external_position_json'] ?? null),
                'hidden_interests'=>self::decode($version['opponent_hidden_interests_json'] ?? null),'constraints'=>self::decode($version['opponent_constraints_json'] ?? null),
                'alternative'=>self::decode($version['opponent_alternative_json'] ?? null),'concession_space'=>self::decode($version['opponent_concession_space_json'] ?? null),
                'walkaway'=>self::decode($version['opponent_walkaway_json'] ?? null),
            ],
            'messages'=>$messages,'events'=>$events,'items'=>$items,'facts'=>$facts,'scenario_rules'=>$scenarioRules,'evaluation_rules'=>$rules,
            'final_agreement'=>$agreement,'completion'=>$completion,'commitments'=>CommitmentService::publicProjection($state),'relationship'=>RelationshipService::internalProjection($state),
        ];
    }
}
