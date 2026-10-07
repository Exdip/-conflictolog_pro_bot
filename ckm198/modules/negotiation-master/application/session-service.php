<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class SessionService {
    private ScenarioRepository $scenarios;
    private SessionRepository $sessions;
    private PlayerSessionSnapshotBuilder $snapshots;

    public function __construct(?ScenarioRepository $scenarios = null, ?SessionRepository $sessions = null, ?PlayerSessionSnapshotBuilder $snapshots = null) {
        $this->scenarios = $scenarios ?: new ScenarioRepository();
        $this->sessions = $sessions ?: new SessionRepository();
        $this->snapshots = $snapshots ?: new PlayerSessionSnapshotBuilder($this->scenarios);
    }

    public function activeForScenario(int $scenarioId): ?array {
        if ($scenarioId <= 0) { return null; }
        return $this->sessions->findActiveForScenario($scenarioId);
    }

    private function allowedModes(array $version): array {
        $modes = ['training','exam'];
        if (!empty($version['mechanics_json'])) {
            try {
                $mechanics = json_decode((string) $version['mechanics_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!empty($mechanics['allowed_modes']) && is_array($mechanics['allowed_modes'])) {
                    $candidate = array_values(array_intersect(['training','exam'], array_map('strval', $mechanics['allowed_modes'])));
                    if ($candidate) { $modes = $candidate; }
                }
            } catch (\Throwable) {}
        }
        return $modes;
    }


    private function allowedDifficulties(array $version): array {
        return DifficultyPolicy::allowedForVersion($version);
    }

    public function start(int $scenarioId, string $mode = 'training', bool $voiceEnabled = false, bool $restart = false, string $difficulty = 'medium'): array {
        global $wpdb;
        if ($scenarioId <= 0) { throw new \InvalidArgumentException('Scenario is required.'); }
        $scenario = $this->scenarios->get($scenarioId);
        (new LibraryAccessService())->assertScenarioAccess($scenario);
        if ($scenario['status'] !== 'published' || empty($scenario['current_version_id'])) { throw new \InvalidArgumentException('Scenario is not available.'); }
        $version = Access::version((int) $scenario['current_version_id']);
        if ((int) $version['scenario_id'] !== $scenarioId || $version['status'] !== 'published') { throw new \RuntimeException('Current scenario version is invalid.'); }
        if (!in_array($mode, $this->allowedModes($version), true)) { throw new \InvalidArgumentException('Mode is not available for this scenario.'); }
        $difficulty = DifficultyPolicy::assertAllowed($difficulty, $version);

        $active = $this->sessions->findActiveForScenario($scenarioId);
        if ($active && !$restart) {
            return ['active_exists'=>true,'session_id'=>(int)$active['id'],'status'=>(string)$active['status']];
        }

        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Unable to start session transaction.'); }
        try {
            if ($active && $restart && !$this->sessions->setStatus((int) $active['id'], 'abandoned', ['in_progress','paused'])) {
                throw new \RuntimeException('Unable to abandon the previous session.');
            }
            $sessionId = $this->sessions->createRuntime((int) $version['id'], $mode, $voiceEnabled, $difficulty);
            $this->sessions->initializeItems($sessionId, (int) $version['id']);
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Unable to commit session transaction.'); }
            return ['active_exists'=>false,'session_id'=>$sessionId,'snapshot'=>$this->snapshots->build($sessionId)];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function resume(int $sessionId): array {
        $session = Access::session($sessionId);
        if (($session['session_kind'] ?? 'player') === 'assignment' && in_array((string)$session['status'], ['in_progress','paused'], true)) {
            (new AssignmentService())->assertSessionWritable($sessionId);
        }
        if ($session['status'] === 'paused') {
            if (!$this->sessions->setStatus($sessionId, 'in_progress', ['paused'])) { throw new \RuntimeException('Unable to resume session.'); }
        } elseif ($session['status'] === 'in_progress') {
            // already active
        } elseif (str_starts_with((string)$session['status'], 'completed_')) {
            // Completed sessions are immutable but remain readable.
            return $this->snapshots->build($sessionId);
        } else {
            throw new \RuntimeException('Session cannot be resumed.');
        }
        return $this->snapshots->build($sessionId);
    }

    public function pause(int $sessionId): array {
        $session = Access::session($sessionId);
        if ($session['status'] === 'in_progress') {
            if (($session['processing_status'] ?? 'idle') !== 'idle') { throw new StateConflictException('Дождитесь завершения текущего хода перед сохранением и выходом.'); }
            if (!$this->sessions->setStatus($sessionId, 'paused', ['in_progress'])) { throw new \RuntimeException('Unable to pause session.'); }
        } elseif ($session['status'] !== 'paused') {
            throw new \RuntimeException('Session cannot be paused.');
        }
        return $this->snapshots->build($sessionId);
    }
}
