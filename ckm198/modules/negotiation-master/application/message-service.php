<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class MessageService {
    private MessageRepository $messages;
    private SessionRepository $sessions;
    private PlayerSessionSnapshotBuilder $snapshots;
    private RecoveryService $recovery;
    private TopicalityGuard $topicGuard;

    public function __construct(
        ?MessageRepository $messages = null,
        ?SessionRepository $sessions = null,
        ?PlayerSessionSnapshotBuilder $snapshots = null,
        ?OpponentService $opponent = null,
        ?ArbiterService $arbiter = null,
        ?NoDealService $noDeal = null,
        ?RecoveryService $recovery = null,
        ?TopicalityGuard $topicGuard = null
    ) {
        $this->messages = $messages ?: new MessageRepository();
        $this->sessions = $sessions ?: new SessionRepository();
        $this->snapshots = $snapshots ?: new PlayerSessionSnapshotBuilder();
        $opponent = $opponent ?: new OpponentService($this->messages, $this->sessions);
        $arbiter = $arbiter ?: new ArbiterService($this->messages);
        $noDeal = $noDeal ?: new NoDealService();
        $this->recovery = $recovery ?: new RecoveryService($this->sessions, $this->messages, $arbiter, $opponent, $noDeal, $this->snapshots);
        $this->topicGuard = $topicGuard ?: new TopicalityGuard();
    }

    private static function cleanText(string $content): string {
        $content = trim(str_replace("\0", '', $content));
        $length = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
        if ($length < 1 || $length > 6000) { throw new \InvalidArgumentException('Message must contain 1 to 6000 characters.'); }
        return $content;
    }

    public function send(int $sessionId, string $clientMessageId, string $content, string $inputType = 'text'): array {
        global $wpdb;
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new SessionCompletedException('Session is not writable.'); }
        $content = self::cleanText($content);
        $existingBeforeLock = $this->messages->findByClientId($sessionId, $clientMessageId);
        if (!$existingBeforeLock) { $this->topicGuard->assertRelevant($sessionId, $content); }
        try { $lock = $this->recovery->acquireProcessing($sessionId, 'message'); }
        catch (RecoveryBusyException $error) {
            if ($existingBeforeLock) {
                return ['idempotent'=>true,'message'=>$existingBeforeLock,'opponent_pending'=>true,'opponent_failed'=>false,'snapshot'=>$this->snapshots->build($sessionId)];
            }
            throw $error;
        }
        try {
            $existing = $this->messages->findByClientId($sessionId, $clientMessageId);
            if ($existing) {
                $result = $this->recovery->continueTurnLocked($sessionId, (int)$existing['id']);
                return ['idempotent'=>true,'message'=>$existing] + $result;
            }

            $freshSession = Access::session($sessionId);
            if (($freshSession['processing_status'] ?? 'idle') !== 'idle') {
                throw new OpponentBusyException('Предыдущий ход ещё требует восстановления.');
            }

            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Unable to start message transaction.'); }
            try {
                $messageId = $this->messages->appendPlayer($sessionId, $clientMessageId, $content, $inputType);
                if (!$this->sessions->setProcessingStatus($sessionId, 'player_message_saved', ['idle'])) {
                    throw new \RuntimeException('Unable to mark saved player message.');
                }
                if (!$this->sessions->touchActivity($sessionId)) { throw new \RuntimeException('Unable to touch session after message.'); }
                if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Unable to commit message transaction.'); }
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                $existing = $this->messages->findByClientId($sessionId, $clientMessageId);
                if ($existing) {
                    $result = $this->recovery->continueTurnLocked($sessionId, (int)$existing['id']);
                    return ['idempotent'=>true,'message'=>$existing] + $result;
                }
                throw $error;
            }

            $message = $this->messages->findByClientId($sessionId, $clientMessageId);
            if (!$message) { throw new \RuntimeException('Saved message is unavailable.'); }
            $result = $this->recovery->continueTurnLocked($sessionId, (int)$message['id']);
            return ['idempotent'=>false,'message'=>$message] + $result;
        } finally {
            $this->recovery->releaseProcessing($sessionId, $lock);
        }
    }

    public function retryOpponent(int $sessionId, int $playerMessageId): array {
        $session = Access::session($sessionId);
        if ($session['status'] !== 'in_progress') { throw new SessionCompletedException('Session is not writable.'); }
        $player = $this->messages->findById($sessionId, $playerMessageId);
        if (!$player || ($player['actor'] ?? '') !== 'player') { throw new \InvalidArgumentException('Player message is required.'); }
        $lock = $this->recovery->acquireProcessing($sessionId, 'retry-opponent');
        try {
            $result = $this->recovery->continueTurnLocked($sessionId, $playerMessageId);
            return $result + ['retry'=>true];
        } finally {
            $this->recovery->releaseProcessing($sessionId, $lock);
        }
    }
}
