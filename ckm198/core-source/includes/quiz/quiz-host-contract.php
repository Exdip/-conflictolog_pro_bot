<?php
/**
 * Stable output contract for quiz hosts.
 *
 * Voice is intentionally an optional delivery channel. A later adapter can
 * turn sourceText into audio without changing game-state transitions or API
 * response consumers.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface CKM_Quiz_Host_Adapter_Interface {
    /**
     * @return array<string,mixed>
     */
    public function compose(string $event, array $context): array;
}

function ckm_quiz_host_output(string $mode, string $event, string $text, array $meta = array()): array {
    $text = trim($text);
    $voice = array(
        'enabled'=>false,
        'adapter'=>null,
        'sourceText'=>$text,
        'audioUrl'=>null,
        'mimeType'=>null,
    );
    /**
     * A future voice plugin may return an enabled voice payload here. The
     * engine and endpoint envelope remain unchanged.
     */
    $filteredVoice = apply_filters('ckm_quiz_host_voice_delivery', $voice, $mode, $event, $meta);
    if (is_array($filteredVoice)) $voice = array_merge($voice, $filteredVoice);
    return array(
        'schemaVersion'=>1,
        'mode'=>$mode,
        'event'=>$event,
        'text'=>array('content'=>$text, 'format'=>'plain'),
        'voice'=>$voice,
        'meta'=>$meta,
    );
}

