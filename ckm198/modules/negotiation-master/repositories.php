<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** Server-side repositories. No public write routes in NEG-CORE. */
abstract class Repository {
    protected static function row(string $key, int $id): ?array {
        global $wpdb;
        $table = Schema::table($key);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
    }
    protected static function insert(string $key, array $data): int {
        global $wpdb;
        $spec = Schema::spec()[$key]['columns'];
        if (array_diff(array_keys($data), array_keys($spec)) || isset($data['id'])) { throw new \InvalidArgumentException('Unsupported fields.'); }
        foreach ($data as $column => $value) {
            if (substr($column, -5) === '_json' && $value !== null) {
                if (!is_string($value)) { $data[$column] = wp_json_encode($value, JSON_THROW_ON_ERROR); }
                else { json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
            } elseif ($value !== null && !is_scalar($value)) { throw new \InvalidArgumentException('Invalid field value.'); }
        }
        $table = Schema::table($key);
        $previous = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->insert($table, $data) === false) { throw new \RuntimeException('NEG-CORE write rejected (duplicate or invalid data).'); }
            return (int) $wpdb->insert_id;
        } finally { $wpdb->suppress_errors($previous); }
    }
    protected static function linkedMessage(?int $messageId, int $sessionId): void {
        if ($messageId === null) { return; }
        $row = self::row('messages', $messageId);
        if (!$row || (int) $row['session_id'] !== $sessionId) { throw new \InvalidArgumentException('Message belongs to another session.'); }
    }
    protected static function sessionRows(string $key, int $sessionId, int $afterId, int $limit): array {
        global $wpdb;
        Access::session($sessionId);
        $table = Schema::table($key);
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE session_id=%d AND id>%d ORDER BY id ASC LIMIT %d", $sessionId, max(0, $afterId), max(1, min(200, $limit))), ARRAY_A);
    }
}

final class ScenarioRepository extends Repository {
    public function get(int $id): array { return Access::scenario($id); }

    private static function validStoredSlug(string $slug): bool {
        // Compatibility with legacy tenant slugs produced by sanitize_title().
        // They are stored as literal ASCII percent octets (for example %d0%b0).
        // Validate the exact stored representation; never decode or sanitize_key().
        if ($slug === '' || strlen($slug) > 191) { return false; }
        return preg_match('/\A[a-z0-9](?:[a-z0-9_-]|%[0-9a-f]{2})*\z/D', $slug) === 1;
    }

    public function findPublishedSystemBySlug(string $slug): ?array {
        global $wpdb;
        Access::context();
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/D', $slug)) { throw new \InvalidArgumentException('Invalid scenario slug.'); }
        $table = Schema::table('scenarios');
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id IS NULL AND slug=%s AND status='published' ORDER BY id ASC LIMIT 2", $slug), ARRAY_A);
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous system scenario.'); }
        return $rows[0] ?? null;
    }
    /** Published scenario visible in the current tenant. Tenant-owned content wins over a system slug. */
    public function findPublishedBySlug(string $slug): ?array {
        global $wpdb;
        $context = Access::context();
        if (!self::validStoredSlug($slug)) { throw new \InvalidArgumentException('Invalid scenario slug.'); }
        $table = Schema::table('scenarios');
        $tenant = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id=%d AND slug=%s AND status='published' ORDER BY id DESC LIMIT 1", (int)$context['tenant_id'], $slug), ARRAY_A);
        if ($tenant) { return $tenant; }
        // System slugs have always been canonical ASCII. A legacy percent slug is
        // tenant-only and must not be silently transformed into another locator.
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/D', $slug)) { return null; }
        return $this->findPublishedSystemBySlug($slug);
    }
    public function firstPublishedSystem(): ?array {
        global $wpdb;
        Access::context();
        $table = Schema::table('scenarios');
        return $wpdb->get_row("SELECT * FROM `$table` WHERE tenant_id IS NULL AND status='published' ORDER BY id ASC LIMIT 1", ARRAY_A) ?: null;
    }
    /** Player projection never contains opponent secrets or hidden facts. */
    public function playerVersion(int $id): array {
        $version = Access::version($id);
        return array_intersect_key($version, array_flip(['id','scenario_id','version_number','player_role','player_situation','player_task','player_known_facts_json','player_ideal_result_json','player_target_result_json','player_alternative_json','player_red_lines_json','opponent_name','opponent_role']));
    }
    /** Negotiation dimensions only; targets, boundaries and config stay server-side. */
    public function playerItems(int $versionId): array {
        global $wpdb;
        Access::version($versionId);
        $table = Schema::table('items');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT code,title,value_type,unit,required_for_agreement,sort_order FROM `$table` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC", $versionId), ARRAY_A);
    }
    public function adminVersion(int $id): array { Access::admin(); return Access::version($id); }
    public function adminComponents(int $versionId, string $kind): array {
        global $wpdb;
        Access::admin(); Access::version($versionId);
        if (!in_array($kind, ['hidden_facts','items','rules','evaluation_rules'], true)) { throw new \InvalidArgumentException('Invalid component type.'); }
        $table = Schema::table($kind);
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE scenario_version_id=%d ORDER BY id ASC", $versionId), ARRAY_A);
    }
}

final class SessionRepository extends Repository {
    public function findActiveForScenario(int $scenarioId): ?array {
        global $wpdb;
        $context = Access::context();
        $table = Schema::table('sessions');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id=%d AND participant_key=%s AND scenario_id=%d AND session_kind='player' AND status IN ('in_progress','paused') ORDER BY id DESC LIMIT 1", $context['tenant_id'], $context['participant_key'], $scenarioId), ARRAY_A) ?: null;
    }
    public function createRuntime(int $versionId, string $mode, bool $voiceEnabled, string $difficulty = 'medium'): int {
        $context = Access::context();
        $version = Access::version($versionId);
        if ($version['status'] !== 'published') { throw new \InvalidArgumentException('Only published versions may start sessions.'); }
        if (!in_array($mode, ['training','exam'], true)) { throw new \InvalidArgumentException('Unsupported mode.'); }
        $difficulty = DifficultyPolicy::assertAllowed($difficulty, $version);
        $now = current_time('mysql', true);
        return self::insert('sessions', [
            'tenant_id' => $context['tenant_id'], 'scenario_id' => (int) $version['scenario_id'], 'scenario_version_id' => $versionId,
            'participant_key' => $context['participant_key'], 'assignment_id' => null, 'session_kind' => 'player', 'mode' => $mode, 'difficulty' => $difficulty, 'status' => 'in_progress',
            'processing_status' => 'idle', 'state_revision' => 1, 'state_json' => ['voice_enabled' => $voiceEnabled, 'relationship' => ['tension'=>'normal','credibility'=>['player'=>'normal','opponent'=>'normal'],'last_change_message_id'=>0,'last_change_actor'=>'']],
            'started_at' => $now, 'last_activity_at' => $now, 'completed_at' => null, 'final_agreement_id' => null,
            'evaluation_status' => 'not_started', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    public function findActiveForAssignment(int $assignmentId, string $participantKey): ?array {
        global $wpdb;
        Access::context();
        if ($assignmentId <= 0 || $participantKey === '') { return null; }
        $table = Schema::table('sessions');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE assignment_id=%d AND participant_key=%s AND session_kind='assignment' AND status IN ('in_progress','paused') ORDER BY id DESC LIMIT 1", $assignmentId, $participantKey), ARRAY_A) ?: null;
    }

    public function countCompletedForAssignment(int $assignmentId, string $participantKey): int {
        global $wpdb;
        Access::context();
        $table = Schema::table('sessions');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE assignment_id=%d AND participant_key=%s AND session_kind='assignment' AND status LIKE 'completed_%%'", $assignmentId, $participantKey));
    }

    public function createAssignedRuntime(int $assignmentId, int $versionId, string $participantKey, string $mode, bool $voiceEnabled, string $difficulty = 'medium'): int {
        $context = Access::context();
        $version = Access::version($versionId);
        if ($assignmentId <= 0 || trim($participantKey) === '') { throw new \InvalidArgumentException('Invalid assignment runtime.'); }
        if (($version['status'] ?? '') !== 'published') { throw new \InvalidArgumentException('Only published versions may start assignment sessions.'); }
        if (!in_array($mode, ['training','exam'], true)) { throw new \InvalidArgumentException('Unsupported mode.'); }
        $difficulty = DifficultyPolicy::assertAllowed($difficulty, $version);
        $now = current_time('mysql', true);
        return self::insert('sessions', [
            'tenant_id'=>(int)$context['tenant_id'],'scenario_id'=>(int)$version['scenario_id'],'scenario_version_id'=>$versionId,
            'participant_key'=>$participantKey,'assignment_id'=>$assignmentId,'session_kind'=>'assignment','mode'=>$mode,'difficulty'=>$difficulty,'status'=>'in_progress',
            'processing_status'=>'idle','state_revision'=>1,'state_json'=>['voice_enabled'=>$voiceEnabled,'assignment_id'=>$assignmentId,'relationship'=>['tension'=>'normal','credibility'=>['player'=>'normal','opponent'=>'normal'],'last_change_message_id'=>0,'last_change_actor'=>'']],
            'started_at'=>$now,'last_activity_at'=>$now,'completed_at'=>null,'final_agreement_id'=>null,'evaluation_status'=>'not_started','created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    public function createBuilderTestRuntime(int $versionId, string $mode = 'training', bool $voiceEnabled = false, string $difficulty = 'medium'): int {
        $context = Access::context();
        $version = Access::version($versionId);
        if (!in_array((string)($version['status'] ?? ''), ['draft','published'], true)) {
            throw new \InvalidArgumentException('Only a draft or published version may start a builder test session.');
        }
        $scenario = Access::scenario((int)$version['scenario_id']);
        $ownedByTenant = $scenario['tenant_id'] !== null && (int)$scenario['tenant_id'] === (int)$context['tenant_id'];
        $ownedByUser = (int)($scenario['created_by'] ?? 0) === (int)$context['user_id'];
        if (!$ownedByTenant || (!$context['admin'] && !$ownedByUser)) {
            throw new \RuntimeException('Builder test scenario is unavailable.');
        }
        if (!in_array($mode, ['training','exam'], true)) { throw new \InvalidArgumentException('Unsupported mode.'); }
        $difficulty = DifficultyPolicy::assertAllowed($difficulty, $version);
        $now = current_time('mysql', true);
        return self::insert('sessions', [
            'tenant_id' => $context['tenant_id'], 'scenario_id' => (int)$version['scenario_id'], 'scenario_version_id' => $versionId,
            'participant_key' => $context['participant_key'], 'assignment_id' => null, 'session_kind' => 'builder_test', 'mode' => $mode, 'difficulty' => $difficulty, 'status' => 'in_progress',
            'processing_status' => 'idle', 'state_revision' => 1, 'state_json' => ['voice_enabled' => $voiceEnabled, 'builder_test' => true, 'relationship' => ['tension'=>'normal','credibility'=>['player'=>'normal','opponent'=>'normal'],'last_change_message_id'=>0,'last_change_actor'=>'']],
            'started_at' => $now, 'last_activity_at' => $now, 'completed_at' => null, 'final_agreement_id' => null,
            'evaluation_status' => 'not_started', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function abandonBuilderTestsForScenario(int $scenarioId): void {
        global $wpdb;
        $context = Access::context();
        $table = Schema::table('sessions');
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET status='abandoned',processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,last_activity_at=%s,completed_at=%s,updated_at=%s WHERE tenant_id=%d AND participant_key=%s AND scenario_id=%d AND session_kind='builder_test' AND status IN ('in_progress','paused')",
            $now,$now,$now,(int)$context['tenant_id'],(string)$context['participant_key'],$scenarioId
        ));
    }

    public function initializeItems(int $sessionId, int $versionId): void {
        global $wpdb;
        Access::session($sessionId); Access::version($versionId);
        $items = Schema::table('items'); $state = Schema::table('item_state'); $now = current_time('mysql', true);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id FROM `$items` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC", $versionId), ARRAY_A);
        foreach ($rows as $row) {
            if ($wpdb->insert($state, ['session_id'=>$sessionId,'item_id'=>(int)$row['id'],'status'=>'not_discussed','current_value_json'=>null,'proposed_by'=>'','bundle_key'=>'','updated_at'=>$now]) === false) {
                throw new \RuntimeException('Unable to initialize negotiation item state.');
            }
        }
    }
    public function setStatus(int $id, string $status, array $allowedFrom): bool {
        global $wpdb;
        $row = Access::session($id);
        if (!in_array($row['status'], $allowedFrom, true)) { return false; }
        $table = Schema::table('sessions'); $now = current_time('mysql', true);
        if ($status === 'abandoned') {
            $sql = $wpdb->prepare("UPDATE `$table` SET status=%s,processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,last_activity_at=%s,completed_at=%s,updated_at=%s WHERE id=%d AND status=%s", $status, $now, $now, $now, $id, $row['status']);
        } elseif ($status === 'paused') {
            $sql = $wpdb->prepare("UPDATE `$table` SET status=%s,processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,last_activity_at=%s,updated_at=%s WHERE id=%d AND status=%s", $status, $now, $now, $id, $row['status']);
        } else {
            $sql = $wpdb->prepare("UPDATE `$table` SET status=%s,last_activity_at=%s,updated_at=%s WHERE id=%d AND status=%s", $status, $now, $now, $id, $row['status']);
        }
        return $wpdb->query($sql) === 1;
    }
    public function touchAfterMessage(int $id): bool {
        global $wpdb; Access::session($id); $table = Schema::table('sessions'); $now = current_time('mysql', true);
        return $wpdb->query($wpdb->prepare("UPDATE `$table` SET state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'", $now, $now, $id)) === 1;
    }
    public function touchActivity(int $id): bool {
        global $wpdb; Access::session($id); $table = Schema::table('sessions'); $now = current_time('mysql', true);
        return $wpdb->query($wpdb->prepare("UPDATE `$table` SET last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'", $now, $now, $id)) !== false;
    }
    public function setProcessingStatus(int $id, string $status, array $allowedFrom): bool {
        global $wpdb;
        $row = Access::session($id);
        if ($row['status'] !== 'in_progress' || !in_array($row['processing_status'], $allowedFrom, true)) { return false; }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $status)) { throw new \InvalidArgumentException('Invalid processing status.'); }
        $table = Schema::table('sessions'); $now = current_time('mysql', true);
        $placeholders = implode(',', array_fill(0, count($allowedFrom), '%s'));
        $args = [$status, $now, $now, $id];
        foreach ($allowedFrom as $from) { $args[] = $from; }
        $sql = $wpdb->prepare("UPDATE `$table` SET processing_status=%s,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress' AND processing_status IN ($placeholders)", ...$args);
        return $wpdb->query($sql) === 1;
    }

    public function forceProcessingStatus(int $id, string $status): bool {
        global $wpdb;
        $row = Access::session($id);
        if ($row['status'] !== 'in_progress') { return false; }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $status)) { throw new \InvalidArgumentException('Invalid processing status.'); }
        $table = Schema::table('sessions'); $now = current_time('mysql', true);
        return $wpdb->query($wpdb->prepare("UPDATE `$table` SET processing_status=%s,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'", $status, $now, $now, $id)) === 1;
    }

    private static function runtimeClientId(string $clientId): string {
        $clientId = trim($clientId);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/D', $clientId)) { throw new \InvalidArgumentException('Invalid runtime client ID.'); }
        return $clientId;
    }

    public function acquireProcessingLock(int $id, string $token, int $ttlSeconds = 45): bool {
        global $wpdb;
        Access::session($id);
        $token = self::runtimeClientId($token);
        $ttlSeconds = max(10, min(300, $ttlSeconds));
        $table = Schema::table('sessions');
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $sql = $wpdb->prepare(
            "UPDATE `$table` SET processing_lock_token=%s,processing_lock_expires_at=%s,updated_at=%s WHERE id=%d AND (processing_lock_token IS NULL OR processing_lock_token='' OR processing_lock_expires_at IS NULL OR processing_lock_expires_at<%s OR processing_lock_token=%s)",
            $token, $expires, $now, $id, $now, $token
        );
        return $wpdb->query($sql) === 1;
    }

    public function renewProcessingLock(int $id, string $token, int $ttlSeconds = 45): bool {
        global $wpdb;
        Access::session($id);
        $token = self::runtimeClientId($token);
        $ttlSeconds = max(10, min(300, $ttlSeconds));
        $table = Schema::table('sessions');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        return $wpdb->query($wpdb->prepare("UPDATE `$table` SET processing_lock_expires_at=%s,updated_at=%s WHERE id=%d AND processing_lock_token=%s", $expires, current_time('mysql', true), $id, $token)) === 1;
    }

    public function releaseProcessingLock(int $id, string $token): void {
        global $wpdb;
        $token = self::runtimeClientId($token);
        $table = Schema::table('sessions');
        $wpdb->query($wpdb->prepare("UPDATE `$table` SET processing_lock_token=NULL,processing_lock_expires_at=NULL,updated_at=%s WHERE id=%d AND processing_lock_token=%s", current_time('mysql', true), $id, $token));
    }

    public function claimWriter(int $id, string $clientId, int $ttlSeconds = 180, bool $force = false): bool {
        global $wpdb;
        $row = Access::session($id);
        if ($row['status'] !== 'in_progress') { return false; }
        $clientId = self::runtimeClientId($clientId);
        $ttlSeconds = max(30, min(900, $ttlSeconds));
        $table = Schema::table('sessions');
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        if ($force) {
            $sql = $wpdb->prepare("UPDATE `$table` SET active_client_id=%s,writer_lock_expires_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'", $clientId, $expires, $now, $id);
        } else {
            $sql = $wpdb->prepare(
                "UPDATE `$table` SET active_client_id=%s,writer_lock_expires_at=%s,updated_at=%s WHERE id=%d AND status='in_progress' AND (active_client_id IS NULL OR active_client_id='' OR writer_lock_expires_at IS NULL OR writer_lock_expires_at<%s OR active_client_id=%s)",
                $clientId, $expires, $now, $id, $now, $clientId
            );
        }
        return $wpdb->query($sql) === 1;
    }

    public function releaseWriter(int $id, string $clientId): void {
        global $wpdb;
        $clientId = self::runtimeClientId($clientId);
        $table = Schema::table('sessions');
        $wpdb->query($wpdb->prepare("UPDATE `$table` SET active_client_id=NULL,writer_lock_expires_at=NULL,updated_at=%s WHERE id=%d AND active_client_id=%s", current_time('mysql', true), $id, $clientId));
    }

    public function writerInfo(int $id): array {
        $row = Access::session($id);
        $now = time();
        $expires = !empty($row['writer_lock_expires_at']) ? strtotime((string)$row['writer_lock_expires_at'].' UTC') : false;
        return [
            'has_writer'=>!empty($row['active_client_id']) && $expires !== false && $expires >= $now,
            'expires_at'=>$row['writer_lock_expires_at'] ?: null,
        ];
    }

    public function processingLockInfo(int $id): array {
        $row = Access::session($id);
        $now = time();
        $expires = !empty($row['processing_lock_expires_at']) ? strtotime((string)$row['processing_lock_expires_at'].' UTC') : false;
        return [
            'locked'=>!empty($row['processing_lock_token']) && $expires !== false && $expires >= $now,
            'expires_at'=>$row['processing_lock_expires_at'] ?: null,
        ];
    }
    public function create(int $versionId, string $mode = 'training', string $difficulty = 'medium'): int {
        $context = Access::context();
        $version = Access::version($versionId);
        if ($version['status'] !== 'published') { throw new \InvalidArgumentException('Only published versions may start sessions.'); }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $mode)) { throw new \InvalidArgumentException('Invalid mode.'); }
        $difficulty = DifficultyPolicy::assertAllowed($difficulty, $version);
        $now = current_time('mysql', true);
        return self::insert('sessions', ['tenant_id' => $context['tenant_id'], 'scenario_id' => (int) $version['scenario_id'], 'scenario_version_id' => $versionId, 'participant_key' => $context['participant_key'], 'assignment_id' => null, 'session_kind' => 'player', 'mode' => $mode, 'difficulty' => $difficulty, 'created_at' => $now, 'updated_at' => $now]);
    }
    public function get(int $id): array {
        $row = Access::session($id);
        // state_json is reserved for the future engine and can contain secrets.
        unset($row['state_json'], $row['processing_lock_token'], $row['processing_lock_expires_at'], $row['active_client_id'], $row['writer_lock_expires_at']);
        return $row;
    }
    public function adminState(int $id): array { Access::admin(); return Access::session($id); }
    /** Optimistic concurrency for the future server engine; no client state writes. */
    public function updateState(int $id, int $expectedRevision, array $state): bool {
        global $wpdb;
        Access::admin(); Access::session($id);
        if ($expectedRevision < 1) { throw new \InvalidArgumentException('Invalid revision.'); }
        $table = Schema::table('sessions');
        $json = wp_json_encode($state, JSON_THROW_ON_ERROR);
        return $wpdb->query($wpdb->prepare("UPDATE `$table` SET state_json=%s, state_revision=state_revision+1, updated_at=%s WHERE id=%d AND state_revision=%d", $json, current_time('mysql', true), $id, $expectedRevision)) === 1;
    }
}

final class MessageRepository extends Repository {
    private const SELECT_FIELDS = 'id,session_id,sequence_no,turn_no,channel,actor,input_type,client_message_id,reply_to_message_id,coach_level,related_message_id,content,analysis_status,created_at';

    public function listForSession(int $sessionId, int $afterId = 0, int $limit = 100): array {
        global $wpdb;
        Access::session($sessionId);
        $table = Schema::table('messages');
        // Main transcript deliberately excludes coach/system/internal channels.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND id>%d AND channel IN ('dialogue','negotiation') ORDER BY id ASC LIMIT %d", $sessionId, max(0, $afterId), max(1, min(200, $limit))), ARRAY_A);
    }

    public function listCoachForSession(int $sessionId, int $limit = 50): array {
        global $wpdb;
        $session = Access::session($sessionId);
        if (($session['mode'] ?? '') !== 'training') { return []; }
        $table = Schema::table('messages');
        $limit = max(1, min(100, $limit));
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND channel='coach' AND actor='coach' ORDER BY sequence_no DESC,id DESC LIMIT %d", $sessionId, $limit), ARRAY_A);
        return array_reverse($rows);
    }

    public function coachCounts(int $sessionId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        $counts = ['attention'=>0,'direction'=>0,'example'=>0,'review_last_move'=>0,'total'=>0];
        if (($session['mode'] ?? '') !== 'training') { return $counts; }
        $table = Schema::table('messages');
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT coach_level,COUNT(*) AS qty FROM `$table` WHERE session_id=%d AND channel='coach' AND actor='coach' GROUP BY coach_level", $sessionId), ARRAY_A);
        foreach ($rows as $row) {
            $level = (string)($row['coach_level'] ?? '');
            if (array_key_exists($level, $counts)) { $counts[$level] = (int)$row['qty']; $counts['total'] += (int)$row['qty']; }
        }
        return $counts;
    }

    public function findByClientId(int $sessionId, string $clientId): ?array {
        global $wpdb; Access::session($sessionId);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $clientId)) { throw new \InvalidArgumentException('Invalid client message ID.'); }
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND client_message_id=%s LIMIT 1", $sessionId, $clientId), ARRAY_A) ?: null;
    }

    public function findById(int $sessionId, int $messageId): ?array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND id=%d LIMIT 1", $sessionId, $messageId), ARRAY_A) ?: null;
    }

    public function findReplyTo(int $sessionId, int $playerMessageId): ?array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND channel='negotiation' AND actor='opponent' AND reply_to_message_id=%d ORDER BY id ASC LIMIT 1", $sessionId, $playerMessageId), ARRAY_A) ?: null;
    }

    public function listNegotiationForContext(int $sessionId, int $limit = 10): array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        $limit = max(2, min(20, $limit));
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id,sequence_no,turn_no,actor,content FROM `$table` WHERE session_id=%d AND channel IN ('dialogue','negotiation') AND actor IN ('player','opponent') ORDER BY sequence_no DESC,id DESC LIMIT %d", $sessionId, $limit), ARRAY_A);
        return array_reverse($rows);
    }

    public function lastPlayerMessage(int $sessionId): ?array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND channel IN ('dialogue','negotiation') AND actor='player' ORDER BY sequence_no DESC,id DESC LIMIT 1", $sessionId), ARRAY_A) ?: null;
    }

    public function lastUnansweredPlayer(int $sessionId): ?array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT p.".str_replace(',', ',p.', self::SELECT_FIELDS)." FROM `$table` p LEFT JOIN `$table` o ON o.session_id=p.session_id AND o.channel='negotiation' AND o.actor='opponent' AND o.reply_to_message_id=p.id WHERE p.session_id=%d AND p.channel IN ('dialogue','negotiation') AND p.actor='player' AND o.id IS NULL ORDER BY p.sequence_no DESC,p.id DESC LIMIT 1", $sessionId), ARRAY_A) ?: null;
    }

    public function lastOpponentMessage(int $sessionId): ?array {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT ".self::SELECT_FIELDS." FROM `$table` WHERE session_id=%d AND channel='negotiation' AND actor='opponent' ORDER BY sequence_no DESC,id DESC LIMIT 1", $sessionId), ARRAY_A) ?: null;
    }

    public function analysisFailureCount(int $sessionId): int {
        global $wpdb; Access::session($sessionId);
        $table = Schema::table('messages');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE session_id=%d AND channel IN ('dialogue','negotiation') AND actor IN ('player','opponent') AND analysis_status='failed'", $sessionId));
    }

    public function appendPlayer(int $sessionId, string $clientId, string $content, string $inputType = 'text'): int {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new \RuntimeException('Session is not writable.'); }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $clientId)) { throw new \InvalidArgumentException('Invalid client message ID.'); }
        if (!in_array($inputType, ['text','voice'], true)) { throw new \InvalidArgumentException('Invalid input type.'); }
        $table = Schema::table('messages');
        $sequence = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_no),0)+1 FROM `$table` WHERE session_id=%d", $sessionId));
        $turn = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(turn_no),0)+1 FROM `$table` WHERE session_id=%d AND actor='player' AND channel IN ('dialogue','negotiation')", $sessionId));
        return self::insert('messages', ['session_id'=>$sessionId,'sequence_no'=>max(1,$sequence),'turn_no'=>max(1,$turn),'channel'=>'negotiation','actor'=>'player','input_type'=>$inputType,'client_message_id'=>$clientId,'reply_to_message_id'=>null,'coach_level'=>null,'related_message_id'=>null,'content'=>$content,'analysis_status'=>'not_started','created_at'=>current_time('mysql', true)]);
    }

    public function appendCoach(int $sessionId, string $clientId, string $level, ?int $relatedMessageId, string $content): int {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress' || ($session['mode'] ?? '') !== 'training') { throw new \RuntimeException('Coach is not writable for this session.'); }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $clientId)) { throw new \InvalidArgumentException('Invalid coach request ID.'); }
        if (!in_array($level, ['attention','direction','example','review_last_move'], true)) { throw new \InvalidArgumentException('Invalid coach level.'); }
        $related = null; $turn = 0;
        if ($relatedMessageId !== null && $relatedMessageId > 0) {
            $related = $this->findById($sessionId, $relatedMessageId);
            if (!$related || ($related['actor'] ?? '') !== 'player' || !in_array($related['channel'] ?? '', ['dialogue','negotiation'], true)) { throw new \InvalidArgumentException('Related player message is unavailable.'); }
            $turn = (int)($related['turn_no'] ?? 0);
        }
        $table = Schema::table('messages');
        $sequence = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_no),0)+1 FROM `$table` WHERE session_id=%d", $sessionId));
        return self::insert('messages', [
            'session_id'=>$sessionId,'sequence_no'=>max(1,$sequence),'turn_no'=>max(0,$turn),'channel'=>'coach','actor'=>'coach','input_type'=>'text',
            'client_message_id'=>$clientId,'reply_to_message_id'=>null,'coach_level'=>$level,'related_message_id'=>$relatedMessageId,'content'=>$content,
            'analysis_status'=>'complete','created_at'=>current_time('mysql', true),
        ]);
    }

    public function setAnalysisStatus(int $sessionId, int $messageId, string $status): bool {
        global $wpdb; Access::session($sessionId);
        if (!in_array($status, ['not_started','complete','failed'], true)) { throw new \InvalidArgumentException('Invalid analysis status.'); }
        $table = Schema::table('messages');
        if ($status === 'failed') {
            $changed = $wpdb->query($wpdb->prepare("UPDATE `$table` SET analysis_status=%s WHERE session_id=%d AND id=%d AND analysis_status<>'complete' AND analysis_status<>%s", $status, $sessionId, $messageId, $status));
        } else {
            $changed = $wpdb->query($wpdb->prepare("UPDATE `$table` SET analysis_status=%s WHERE session_id=%d AND id=%d AND analysis_status<>%s", $status, $sessionId, $messageId, $status));
        }
        if ($changed === false) { throw new \RuntimeException('Unable to update message analysis status.'); }
        return $changed === 1;
    }

    public function appendAgreementProposal(int $sessionId, int $agreementId, string $content): int {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new \RuntimeException('Session is not writable.'); }
        $clientId = 'agreement:' . $agreementId;
        $existing = $this->findByClientId($sessionId, $clientId);
        if ($existing) { return (int) $existing['id']; }
        $table = Schema::table('messages');
        $sequence = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_no),0)+1 FROM `$table` WHERE session_id=%d", $sessionId));
        $turn = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(turn_no),0)+1 FROM `$table` WHERE session_id=%d AND actor='player' AND channel IN ('dialogue','negotiation')", $sessionId));
        return self::insert('messages', [
            'session_id'=>$sessionId,'sequence_no'=>max(1,$sequence),'turn_no'=>max(1,$turn),'channel'=>'negotiation','actor'=>'player','input_type'=>'agreement',
            'client_message_id'=>$clientId,'reply_to_message_id'=>null,'coach_level'=>null,'related_message_id'=>null,'content'=>$content,'analysis_status'=>'complete','created_at'=>current_time('mysql', true)
        ]);
    }

    public function appendOpponent(int $sessionId, int $playerMessageId, string $content): int {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new \RuntimeException('Session is not writable.'); }
        $player = $this->findById($sessionId, $playerMessageId);
        if (!$player || $player['actor'] !== 'player' || !in_array($player['channel'], ['dialogue','negotiation'], true)) { throw new \InvalidArgumentException('Player message is unavailable.'); }
        $existing = $this->findReplyTo($sessionId, $playerMessageId);
        if ($existing) { return (int) $existing['id']; }
        $table = Schema::table('messages');
        $sequence = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_no),0)+1 FROM `$table` WHERE session_id=%d", $sessionId));
        return self::insert('messages', [
            'session_id'=>$sessionId,'sequence_no'=>max(1,$sequence),'turn_no'=>(int)$player['turn_no'],'channel'=>'negotiation','actor'=>'opponent',
            'input_type'=>'text','client_message_id'=>null,'reply_to_message_id'=>$playerMessageId,'coach_level'=>null,'related_message_id'=>null,'content'=>$content,'analysis_status'=>'not_started','created_at'=>current_time('mysql', true)
        ]);
    }

    /** Sequence assigned by engine/admin tools; unique indexes reject races. */
    public function append(int $sessionId, array $data): int {
        Access::admin(); Access::session($sessionId);
        $allowed = ['sequence_no','turn_no','channel','actor','input_type','client_message_id','reply_to_message_id','coach_level','related_message_id','content','analysis_status'];
        if (array_diff(array_keys($data), $allowed) || (int) ($data['sequence_no'] ?? 0) < 1 || !isset($data['content'])) { throw new \InvalidArgumentException('Invalid message.'); }
        self::linkedMessage(isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null, $sessionId);
        self::linkedMessage(isset($data['related_message_id']) ? (int) $data['related_message_id'] : null, $sessionId);
        $clientId = $data['client_message_id'] ?? null;
        if ($clientId !== null && (!is_string($clientId) || !preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $clientId))) { throw new \InvalidArgumentException('Invalid client message ID.'); }
        return self::insert('messages', $data + ['session_id' => $sessionId, 'client_message_id' => null, 'coach_level'=>null, 'related_message_id'=>null, 'created_at' => current_time('mysql', true)]);
    }
}

final class EventRepository extends Repository {
    public function listForSession(int $sessionId, int $afterId = 0, int $limit = 100): array {
        Access::admin(); return self::sessionRows('events', $sessionId, $afterId, $limit);
    }
    public function append(int $sessionId, array $data): int {
        Access::admin(); Access::session($sessionId);
        $allowed = ['message_id','sequence_no','event_key','event_type','actor','target_type','target_id','confidence','payload_json','arbiter_version'];
        if (array_diff(array_keys($data), $allowed) || (int) ($data['message_id'] ?? 0) < 1 || (int) ($data['sequence_no'] ?? 0) < 1 || empty($data['event_key'])) { throw new \InvalidArgumentException('Invalid event.'); }
        self::linkedMessage((int) $data['message_id'], $sessionId);
        return self::insert('events', $data + ['session_id' => $sessionId, 'created_at' => current_time('mysql', true)]);
    }
}

final class AgreementRepository extends Repository {
    private static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (\Throwable) { return []; }
    }
    public function listForSession(int $sessionId, int $afterId = 0, int $limit = 100): array {
        $rows = self::sessionRows('agreements', $sessionId, $afterId, $limit);
        foreach ($rows as &$row) { unset($row['php_validation_json']); $row['package'] = self::decode($row['package_json'] ?? null); unset($row['package_json']); }
        return $rows;
    }
    public function get(int $sessionId, int $agreementId, bool $includeInternal = false): ?array {
        global $wpdb; Access::session($sessionId); $table = Schema::table('agreements');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d AND session_id=%d", $agreementId, $sessionId), ARRAY_A) ?: null;
        if (!$row) { return null; }
        if (!$includeInternal) { unset($row['php_validation_json']); }
        return $row;
    }
    public function findDraftAtRevision(int $sessionId, int $revision): ?array {
        global $wpdb; Access::session($sessionId); $table = Schema::table('agreements');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE session_id=%d AND state_revision=%d AND status='draft' ORDER BY id DESC LIMIT 1", $sessionId, $revision), ARRAY_A) ?: null;
    }
    public function nextProposalNo(int $sessionId): int {
        global $wpdb; Access::session($sessionId); $table = Schema::table('agreements');
        return max(1, (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(proposal_no),0)+1 FROM `$table` WHERE session_id=%d", $sessionId)));
    }
    public function create(int $sessionId, array $data): int {
        Access::session($sessionId);
        $allowed = ['proposal_no','status','package_json','created_by','created_at_message_id','php_validation_json','state_revision'];
        if (array_diff(array_keys($data), $allowed) || (int) ($data['proposal_no'] ?? 0) < 1 || (int)($data['state_revision'] ?? 0) < 1) { throw new \InvalidArgumentException('Invalid proposal.'); }
        self::linkedMessage(isset($data['created_at_message_id']) ? (int) $data['created_at_message_id'] : null, $sessionId);
        return self::insert('agreements', $data + ['session_id' => $sessionId, 'created_at' => current_time('mysql', true)]);
    }
    public function update(int $sessionId, int $agreementId, array $data): bool {
        global $wpdb; Access::session($sessionId); $allowed=['status','created_at_message_id','php_validation_json','responded_at'];
        if (array_diff(array_keys($data),$allowed)) { throw new \InvalidArgumentException('Unsupported agreement update.'); }
        if (isset($data['created_at_message_id'])) { self::linkedMessage((int)$data['created_at_message_id'],$sessionId); }
        if (isset($data['php_validation_json']) && !is_string($data['php_validation_json'])) { $data['php_validation_json']=wp_json_encode($data['php_validation_json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
        $table=Schema::table('agreements'); $changed=$wpdb->update($table,$data,['id'=>$agreementId,'session_id'=>$sessionId]);
        if ($changed===false) { throw new \RuntimeException('Unable to update agreement.'); }
        return $changed>0;
    }
}

final class EvaluationRepository extends Repository {
    public function getForSession(int $sessionId): ?array {
        global $wpdb;
        Access::session($sessionId);
        $table = Schema::table('evaluations');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE session_id=%d", $sessionId), ARRAY_A);
    }
    public function create(int $sessionId, string $version = '1.0.0'): int {
        Access::admin(); Access::session($sessionId);
        return self::insert('evaluations', ['session_id' => $sessionId, 'evaluation_version' => $version, 'created_at' => current_time('mysql', true)]);
    }
    public function addScore(int $evaluationId, int $ruleId, array $score): int {
        Access::admin();
        $evaluation = self::row('evaluations', $evaluationId);
        if (!$evaluation) { throw new \InvalidArgumentException('Evaluation unavailable.'); }
        $session = Access::session((int) $evaluation['session_id']);
        $rule = self::row('evaluation_rules', $ruleId);
        if (!$rule || (int) $rule['scenario_version_id'] !== (int) $session['scenario_version_id']) { throw new \InvalidArgumentException('Rule belongs to another version.'); }
        if (array_diff(array_keys($score), ['raw_score','weighted_score','source','confidence','explanation','evidence_json'])) { throw new \InvalidArgumentException('Invalid score.'); }
        return self::insert('evaluation_scores', $score + ['evaluation_id' => $evaluationId, 'evaluation_rule_id' => $ruleId]);
    }
}
