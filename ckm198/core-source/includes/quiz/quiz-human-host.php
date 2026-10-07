<?php
/** Human-host adapter: publishes the host's own text using the same contract. */

if (!defined('ABSPATH')) {
    exit;
}

final class CKM_Quiz_Human_Host implements CKM_Quiz_Host_Adapter_Interface {
    public function compose(string $event, array $context): array {
        $text = sanitize_textarea_field((string)($context['text'] ?? ''));
        return ckm_quiz_host_output('human', $event, $text, array(
            'provider'=>'human',
            'gameId'=>(int)($context['game']['id'] ?? 0),
            'userId'=>(int)($context['actor_user_id'] ?? 0),
        ));
    }
}

function ckm_quiz_compose_host_output(string $mode, string $event, array $context): array {
    $adapter = $mode === 'ai' ? new CKM_Quiz_AI_Host() : new CKM_Quiz_Human_Host();
    $filtered = apply_filters('ckm_quiz_host_adapter', $adapter, $mode, $event, $context);
    if ($filtered instanceof CKM_Quiz_Host_Adapter_Interface) $adapter = $filtered;
    return $adapter->compose($event, $context);
}
