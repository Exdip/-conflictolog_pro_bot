<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class WriterConflictException extends \RuntimeException {}
final class RecoveryBusyException extends \RuntimeException {}

/**
 * NEG-RECOVERY coordinator.
 *
 * The database remains authoritative: recovery only resumes stages that can be
 * proven incomplete from persisted messages / analysis state. A short lease
 * prevents parallel workers from processing the same turn.
 */
final class RecoveryService {
    private const PROCESSING_TTL = 180;
    private const WRITER_TTL = 180;

    private SessionRepository $sessions;
    private MessageRepository $messages;
    private ArbiterService $arbiter;
    private OpponentService $opponent;
    private NoDealService $noDeal;
    private PlayerSessionSnapshotBuilder $snapshots;

    public function __construct(
        ?SessionRepository $sessions = null,
        ?MessageRepository $messages = null,
        ?ArbiterService $arbiter = null,
        ?OpponentService $opponent = null,
        ?NoDealService $noDeal = null,
        ?PlayerSessionSnapshotBuilder $snapshots = null
    ) {
        $this->sessions = $sessions ?: new SessionRepository();
        $this->messages = $messages ?: new MessageRepository();
        $this->arbiter = $arbiter ?: new ArbiterService($this->messages);
        $this->opponent = $opponent ?: new OpponentService($this->messages, $this->sessions);
        $this->noDeal = $noDeal ?: new NoDealService();
        $this->snapshots = $snapshots ?: new PlayerSessionSnapshotBuilder();
    }

    public static function normalizeClientId(string $clientId): string {
        $clientId = trim($clientId);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/D', $clientId)) {
            throw new \InvalidArgumentException('Runtime client ID is required.');
        }
        return $clientId;
    }

    private static function token(string $prefix): string {
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        return substr($prefix . ':' . $uuid, 0, 96);
    }

    public function assertWriter(int $sessionId, string $clientId, bool $force = false): void {
        $clientId = self::normalizeClientId($clientId);
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') {
            if (str_starts_with((string)$session['status'], 'completed_')) { throw new SessionCompletedException('Session is already completed.'); }
            throw new StateConflictException('Session is not writable.');
        }
        if (!$this->sessions->claimWriter($sessionId, $clientId, self::WRITER_TTL, $force)) {
            throw new WriterConflictException('Эта сессия сейчас редактируется в другой вкладке или на другом устройстве.');
        }
    }

    public function releaseWriter(int $sessionId, string $clientId): void {
        $this->sessions->releaseWriter($sessionId, self::normalizeClientId($clientId));
    }

    public function takeover(int $sessionId, string $clientId): array {
        $this->assertWriter($sessionId, $clientId, true);
        return ['taken_over'=>true, 'snapshot'=>$this->snapshots->build($sessionId)];
    }

    public function acquireProcessing(int $sessionId, string $purpose = 'turn', int $ttl = self::PROCESSING_TTL): string {
        Access::session($sessionId);
        $token = self::token('proc-' . preg_replace('/[^a-z0-9_-]/i', '-', $purpose));
        if (!$this->sessions->acquireProcessingLock($sessionId, $token, $ttl)) {
            throw new RecoveryBusyException('Session processing is already running.');
        }
        return $token;
    }

    public function renewProcessing(int $sessionId, string $token, int $ttl = self::PROCESSING_TTL): void {
        if (!$this->sessions->renewProcessingLock($sessionId, $token, $ttl)) {
            throw new RecoveryBusyException('Session processing lease was lost.');
        }
    }

    public function releaseProcessing(int $sessionId, string $token): void {
        $this->sessions->releaseProcessingLock($sessionId, $token);
    }

    private function phase(int $sessionId, string $status): void {
        $session = Access::session($sessionId);
        if ($session['status'] === 'in_progress' && ($session['processing_status'] ?? '') !== $status) {
            $this->sessions->forceProcessingStatus($sessionId, $status);
        }
    }

    /** Continue exactly one already-persisted player turn. Caller owns the processing lease. */
    public function continueTurnLocked(int $sessionId, int $playerMessageId): array {
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') {
            return ['completed'=>str_starts_with((string)$session['status'], 'completed_'), 'snapshot'=>$this->snapshots->build($sessionId)];
        }
        $player = $this->messages->findById($sessionId, $playerMessageId);
        if (!$player || ($player['actor'] ?? '') !== 'player' || !in_array(($player['channel'] ?? ''), ['dialogue','negotiation'], true)) {
            throw new \InvalidArgumentException('Player message is unavailable for recovery.');
        }

        $playerAnalysis = ['status'=>'complete','idempotent'=>true,'state_changed'=>false];
        if (!in_array(($player['analysis_status'] ?? ''), ['complete','failed'], true)) {
            $this->phase($sessionId, 'player_analysis_pending');
            $playerAnalysis = $this->arbiter->analyze($sessionId, $playerMessageId);
            $player = $this->messages->findById($sessionId, $playerMessageId) ?: $player;
        }
        $this->phase($sessionId, 'player_analyzed');

        if ($this->noDeal->isPlayerWalkawayCandidate($sessionId, $playerMessageId)) {
            $this->phase($sessionId, 'idle');
            return [
                'player_walkaway_candidate'=>true,
                'opponent_failed'=>false,
                'arbiter'=>['player'=>$playerAnalysis],
                'arbiter_failed'=>($playerAnalysis['status']??'')==='failed',
                'snapshot'=>$this->snapshots->build($sessionId),
            ];
        }

        $reply = $this->messages->findReplyTo($sessionId, $playerMessageId);
        $generated = false;
        if (!$reply) {
            $this->phase($sessionId, 'opponent_generation_pending');
            try {
                $opponent = $this->opponent->ensureReply($sessionId, $playerMessageId);
                $reply = $opponent['message'] ?? null;
                $generated = !empty($opponent['generated']);
            } catch (OpponentBusyException $error) {
                return [
                    'opponent_pending'=>true,'opponent_failed'=>false,
                    'arbiter'=>['player'=>$playerAnalysis],
                    'arbiter_failed'=>($playerAnalysis['status']??'')==='failed',
                    'snapshot'=>$this->snapshots->build($sessionId),
                ];
            } catch (OpponentUnavailableException $error) {
                $this->phase($sessionId, 'opponent_failed');
                return [
                    'opponent_failed'=>true,'message_to_user'=>'Не удалось получить ответ оппонента.',
                    'arbiter'=>['player'=>$playerAnalysis],
                    'arbiter_failed'=>($playerAnalysis['status']??'')==='failed',
                    'snapshot'=>$this->snapshots->build($sessionId),
                ];
            }
        } else {
            $this->phase($sessionId, 'opponent_saved');
        }
        if (!$reply) { throw new OpponentUnavailableException('Opponent response is unavailable after generation.'); }

        $opponentAnalysis = ['status'=>'complete','idempotent'=>true,'state_changed'=>false];
        if (!in_array(($reply['analysis_status'] ?? ''), ['complete','failed'], true)) {
            $this->phase($sessionId, 'opponent_analysis_pending');
            $opponentAnalysis = $this->arbiter->analyze($sessionId, (int)$reply['id']);
        }
        $this->phase($sessionId, 'opponent_analyzed');
        $walkaway = $this->noDeal->maybeCompleteOpponentWalkaway($sessionId, (int)$reply['id']);
        $fresh = Access::session($sessionId);
        if ($fresh['status'] === 'in_progress') { $this->phase($sessionId, 'idle'); }

        return [
            'opponent_message'=>$reply,
            'opponent_generated'=>$generated,
            'opponent_failed'=>false,
            'arbiter'=>['player'=>$playerAnalysis,'opponent'=>$opponentAnalysis],
            'arbiter_failed'=>($playerAnalysis['status']??'')==='failed'||($opponentAnalysis['status']??'')==='failed',
            'opponent_walkaway'=>$walkaway,
            'snapshot'=>$this->snapshots->build($sessionId),
        ];
    }

    /** Resume a stale/incomplete pipeline without creating new player input. */
    public function recover(int $sessionId): array {
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') {
            return ['recovered'=>false,'busy'=>false,'snapshot'=>$this->snapshots->build($sessionId)];
        }
        $lastPlayer = $this->messages->lastPlayerMessage($sessionId);
        $needsRecovery = ($session['processing_status'] ?? 'idle') !== 'idle';
        if ($lastPlayer) {
            $reply = $this->messages->findReplyTo($sessionId, (int)$lastPlayer['id']);
            $playerPending=!in_array(($lastPlayer['analysis_status']??''),['complete','failed'],true);$replyPending=$reply&&!in_array(($reply['analysis_status']??''),['complete','failed'],true);$needsRecovery=$needsRecovery||$playerPending||!$reply||$replyPending;
        }
        if (!$needsRecovery) {
            return ['recovered'=>false,'busy'=>false,'snapshot'=>$this->snapshots->build($sessionId)];
        }
        try { $token = $this->acquireProcessing($sessionId, 'resume'); }
        catch (RecoveryBusyException) { return ['recovered'=>false,'busy'=>true,'snapshot'=>$this->snapshots->build($sessionId)]; }
        try {
            $fresh = Access::session($sessionId);
            if (($fresh['processing_status'] ?? '') === 'agreement_processing') {
                // AgreementService persists its proposal/candidate/reply idempotently. After a crashed
                // lease expires, resume only releases the stale phase; the same proposal can be retried.
                $this->phase($sessionId, 'idle');
                return ['recovered'=>true,'busy'=>false,'agreement_retry_required'=>true,'snapshot'=>$this->snapshots->build($sessionId)];
            }
            if (($fresh['processing_status'] ?? '') === 'opponent_failed') {
                // A completed failure is not an incomplete stage: keep the explicit Retry button.
                return ['recovered'=>false,'busy'=>false,'snapshot'=>$this->snapshots->build($sessionId)];
            }
            if (!$lastPlayer) {
                $this->phase($sessionId, 'idle');
                return ['recovered'=>true,'busy'=>false,'snapshot'=>$this->snapshots->build($sessionId)];
            }
            $result = $this->continueTurnLocked($sessionId, (int)$lastPlayer['id']);
            return ['recovered'=>true,'busy'=>false] + $result;
        } finally { $this->releaseProcessing($sessionId, $token); }
    }

    public function diagnostics(int $sessionId): array {
        Access::admin();
        $session = Access::session($sessionId);
        $player = $this->messages->lastPlayerMessage($sessionId);
        $reply = $player ? $this->messages->findReplyTo($sessionId, (int)$player['id']) : null;
        $pLock = $this->sessions->processingLockInfo($sessionId);
        $wLock = $this->sessions->writerInfo($sessionId);
        $playerPending=$player&&!in_array(($player['analysis_status']??''),['complete','failed'],true);$replyPending=$reply&&!in_array(($reply['analysis_status']??''),['complete','failed'],true);$needs=$session['status']==='in_progress' && (($session['processing_status']??'idle')!=='idle'||$playerPending||($player&&!$reply)||$replyPending);
        return [
            'session_id'=>$sessionId,
            'status'=>(string)$session['status'],
            'processing_status'=>(string)$session['processing_status'],
            'state_revision'=>(int)$session['state_revision'],
            'last_player_message_id'=>$player ? (int)$player['id'] : null,
            'last_opponent_message_id'=>$reply ? (int)$reply['id'] : null,
            'last_player_analysis'=>$player['analysis_status'] ?? null,
            'last_opponent_analysis'=>$reply['analysis_status'] ?? null,
            'analysis_failures'=>$this->messages->analysisFailureCount($sessionId),
            'processing_lock'=>$pLock,
            'writer_lock'=>$wLock,
            'recovery_needed'=>$needs,
        ];
    }
}
