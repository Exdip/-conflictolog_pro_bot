<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class PlayerSessionSnapshotBuilder {
    private ScenarioRepository $scenarios;
    private MessageRepository $messages;

    public function __construct(?ScenarioRepository $scenarios = null, ?MessageRepository $messages = null) {
        $this->scenarios = $scenarios ?: new ScenarioRepository();
        $this->messages = $messages ?: new MessageRepository();
    }

    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }

    public function build(int $sessionId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        $scenario = $this->scenarios->get((int) $session['scenario_id']);
        $version = $this->scenarios->playerVersion((int) $session['scenario_version_id']);

        $state = self::decode((string) ($session['state_json'] ?? ''));
        if (!is_array($state)) { $state = []; }

        $itemState = Schema::table('item_state');
        $items = Schema::table('items');
        $itemRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT s.item_id,i.code,i.title,i.value_type,i.unit,i.config_json,i.required_for_agreement,i.sort_order,s.status,s.current_value_json,s.proposed_by,s.bundle_key
             FROM `$itemState` s INNER JOIN `$items` i ON i.id=s.item_id
             WHERE s.session_id=%d AND i.scenario_version_id=%d
             ORDER BY i.sort_order ASC,i.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);
        foreach ($itemRows as &$row) {
            $row['item_id'] = (int) $row['item_id'];
            $row['required_for_agreement'] = (int) $row['required_for_agreement'];
            $row['sort_order'] = (int) $row['sort_order'];
            $config = self::decode($row['config_json'] ?? null);
            $row['value_labels'] = (is_array($config) && is_array($config['labels'] ?? null)) ? $config['labels'] : [];
            unset($row['config_json']);
            $row['current_value'] = self::decode($row['current_value_json']);
            if (is_array($row['current_value'])) {
                $raw = $row['current_value'];
                $safe = ['offers'=>[]];
                if (isset($raw['offers']) && is_array($raw['offers'])) {
                    foreach (['player','opponent'] as $side) {
                        if (isset($raw['offers'][$side]['value'])) { $safe['offers'][$side] = ['value'=>$raw['offers'][$side]['value']]; }
                    }
                }
                if (isset($raw['acceptance_candidate']['actor'], $raw['acceptance_candidate']['value'])) {
                    $safe['acceptance_candidate'] = ['actor'=>$raw['acceptance_candidate']['actor'],'value'=>$raw['acceptance_candidate']['value']];
                }
                if (isset($raw['agreed']['value'])) { $safe['agreed'] = ['value'=>$raw['agreed']['value']]; }
                if (isset($raw['previous_agreed']['value'])) { $safe['previous_agreed'] = ['value'=>$raw['previous_agreed']['value']]; }
                if (isset($raw['reopen_request']) && is_array($raw['reopen_request'])) {
                    $safe['reopen_request'] = [
                        'actor'=>(string)($raw['reopen_request']['actor'] ?? ''),
                        'reason_type'=>(string)($raw['reopen_request']['reason_type'] ?? 'none'),
                        'reason'=>(string)($raw['reopen_request']['reason'] ?? ''),
                        'value'=>$raw['reopen_request']['value'] ?? null,
                    ];
                }
                $row['current_value'] = $safe;
            }
            unset($row['current_value_json']);
        }
        unset($row);

        $discovered = Schema::table('discovered_facts');
        $facts = Schema::table('hidden_facts');
        $factRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT d.hidden_fact_id,d.reveal_level,d.confidence,f.code,f.title,f.content,f.sort_order
             FROM `$discovered` d INNER JOIN `$facts` f ON f.id=d.hidden_fact_id
             WHERE d.session_id=%d AND f.scenario_version_id=%d AND d.reveal_level>0
             ORDER BY f.sort_order ASC,f.id ASC",
            $sessionId, (int) $session['scenario_version_id']
        ), ARRAY_A);
        $eventsTable = Schema::table('events');
        foreach ($factRows as &$row) {
            $row['hidden_fact_id'] = (int) $row['hidden_fact_id'];
            $row['reveal_level'] = (int) $row['reveal_level'];
            $row['sort_order'] = (int) $row['sort_order'];
            if ($row['reveal_level'] < 2) {
                $payload = $wpdb->get_var($wpdb->prepare(
                    "SELECT payload_json FROM `$eventsTable` WHERE session_id=%d AND target_type='hidden_fact' AND target_id=%d ORDER BY id DESC LIMIT 1",
                    $sessionId, $row['hidden_fact_id']
                ));
                $summary = '';
                if (is_string($payload) && $payload !== '') {
                    try { $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); $summary = trim((string)($decoded['public_summary'] ?? '')); }
                    catch (\Throwable) {}
                }
                $row['title'] = 'Частично выяснено';
                $row['content'] = $summary !== '' ? $summary : 'Вы получили частичную информацию об интересах или ограничениях другой стороны.';
            }
            unset($row['hidden_fact_id'], $row['code']);
        }
        unset($row);

        $publicScenario = [
            'id' => (int) $scenario['id'],
            'title' => (string) $scenario['title'],
            'version' => (int) $version['version_number'],
            'player_role' => (string) ($version['player_role'] ?? ''),
            'player_situation' => (string) ($version['player_situation'] ?? ''),
            'player_task' => (string) ($version['player_task'] ?? ''),
            'player_known_facts' => self::decode($version['player_known_facts_json'] ?? null),
            'player_ideal_result' => self::decode($version['player_ideal_result_json'] ?? null),
            'player_target_result' => self::decode($version['player_target_result_json'] ?? null),
            'player_alternative' => self::decode($version['player_alternative_json'] ?? null),
            'player_red_lines' => self::decode($version['player_red_lines_json'] ?? null),
            'opponent' => [
                'name' => (string) ($version['opponent_name'] ?? ''),
                'role' => (string) ($version['opponent_role'] ?? ''),
            ],
        ];

        $remaining = [];
        foreach ($itemRows as $item) {
            if ($item['required_for_agreement'] && !in_array((string)$item['status'], ['agreed','reopen_requested'], true)) {
                $remaining[] = ['item_id'=>$item['item_id'],'code'=>$item['code'],'title'=>$item['title']];
            }
        }

        $unanswered = $this->messages->lastUnansweredPlayer($sessionId);
        $canRetryOpponent = $session['status'] === 'in_progress' && $session['processing_status'] === 'opponent_failed' && is_array($unanswered);

        $coachMessages = $this->messages->listCoachForSession($sessionId, 50);
        $coachCounts = $this->messages->coachCounts($sessionId);
        $canUseCoach = $session['status'] === 'in_progress' && $session['processing_status'] === 'idle' && $session['mode'] === 'training';

        $agreementRows = (new AgreementRepository())->listForSession($sessionId, 0, 50);
        $retryAgreement = null;
        foreach (array_reverse($agreementRows) as $agreementRow) {
            if (($agreementRow['status'] ?? '') === 'proposed') { $retryAgreement = $agreementRow; break; }
        }
        $finalAgreement = null;
        if (!empty($session['final_agreement_id'])) {
            foreach ($agreementRows as $agreementRow) {
                if ((int)$agreementRow['id'] === (int)$session['final_agreement_id']) { $finalAgreement = $agreementRow; break; }
            }
        }
        $completion = is_array($state['completion'] ?? null) ? $state['completion'] : null;
        if (is_array($completion)) {
            $completion = [
                'type'=>(string)($completion['type'] ?? ''),
                'red_line_breached'=>!empty($completion['red_line_breached']),
                'completed_at'=>$completion['completed_at'] ?? $session['completed_at'],
            ];
        }
        $activeWritable = $session['status'] === 'in_progress' && $session['processing_status'] === 'idle';
        $assignmentBlockedMessage = '';
        if ($activeWritable && ($session['session_kind'] ?? '') === 'assignment') {
            try { (new AssignmentService())->assertSessionWritable($sessionId); }
            catch (\Throwable $error) { $activeWritable = false; $assignmentBlockedMessage = $error->getMessage(); }
        }

        return [
            'session' => [
                'id' => (int) $session['id'],
                'scenario_id' => (int) $session['scenario_id'],
                'scenario_version_id' => (int) $session['scenario_version_id'],
                'session_kind' => (string)($session['session_kind'] ?? 'player'),
                'builder_test' => (string)($session['session_kind'] ?? 'player') === 'builder_test',
                'assignment' => (string)($session['session_kind'] ?? 'player') === 'assignment',
                'assignment_id' => (int)($session['assignment_id'] ?? 0),
                'mode' => (string) $session['mode'],
                'difficulty' => DifficultyPolicy::normalize($session['difficulty'] ?? 'medium'),
                'difficulty_label' => DifficultyPolicy::label((string)($session['difficulty'] ?? 'medium')),
                'status' => (string) $session['status'],
                'processing_status' => (string) $session['processing_status'],
                'state_revision' => (int) $session['state_revision'],
                'voice_enabled' => !empty($state['voice_enabled']),
                'started_at' => $session['started_at'],
                'last_activity_at' => $session['last_activity_at'],
            ],
            'scenario' => $publicScenario,
            'messages' => $this->messages->listForSession($sessionId, 0, 200),
            'coach_messages' => $coachMessages,
            'coach_counts' => $coachCounts,
            'commitments' => CommitmentService::publicProjection($state),
            'items' => $itemRows,
            'discovered_facts' => $factRows,
            'remaining_items' => $remaining,
            'agreements' => $agreementRows,
            'final_agreement' => $finalAgreement,
            'completion' => $completion,
            'can_create_agreement' => $activeWritable,
            'can_finish_without_agreement' => $activeWritable,
            'can_send' => $activeWritable,
            'can_use_coach' => $canUseCoach,
            'can_retry_opponent' => $canRetryOpponent,
            'retry_opponent_message_id' => $canRetryOpponent ? (int) $unanswered['id'] : null,
            'can_retry_agreement' => $activeWritable && is_array($retryAgreement),
            'retry_agreement' => ($activeWritable && is_array($retryAgreement)) ? ['id'=>(int)$retryAgreement['id'],'state_revision'=>(int)$retryAgreement['state_revision']] : null,
            'assignment_blocked_message' => $assignmentBlockedMessage,
        ];
    }
}
