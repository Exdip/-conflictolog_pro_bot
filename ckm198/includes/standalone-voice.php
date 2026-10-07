<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_VOICE_OPTION = 'ckm_quiz_pro_voice_settings_v1';
const CKM_QUIZ_PRO_VOICE_PROTOCOL = 'ckm_voice_broadcast_v1';

function ckm_quiz_pro_voice_default_settings(): array {
    return [
        'enabled' => false,
        'gateway_url' => 'https://gateway.video-training.ru',
        'secret' => '',
        // browser = free local SpeechSynthesis; gateway = Cartesia/Sergey via CKM Direct Gateway.
        'ai_voice_provider' => 'browser',
    ];
}

function ckm_quiz_pro_voice_settings(): array {
    $saved = get_option(CKM_QUIZ_PRO_VOICE_OPTION, []);
    if (!is_array($saved)) $saved = [];
    $settings = array_merge(ckm_quiz_pro_voice_default_settings(), $saved);
    if (!in_array((string)$settings['ai_voice_provider'], ['browser','gateway'], true)) $settings['ai_voice_provider'] = 'browser';
    return $settings;
}

function ckm_quiz_pro_ai_voice_provider(): string {
    $settings = ckm_quiz_pro_voice_settings();
    return (string)$settings['ai_voice_provider'] === 'gateway' ? 'gateway' : 'browser';
}

function ckm_quiz_pro_voice_secret(): string {
    if (defined('CKM_QUIZ_PRO_VOICE_SECRET') && is_string(CKM_QUIZ_PRO_VOICE_SECRET) && strlen(CKM_QUIZ_PRO_VOICE_SECRET) >= 16) {
        return CKM_QUIZ_PRO_VOICE_SECRET;
    }
    $env = getenv('CKM_VOICE_SECRET');
    if (is_string($env) && strlen($env) >= 16) return $env;
    $settings = ckm_quiz_pro_voice_settings();
    return (string)($settings['secret'] ?? '');
}

function ckm_quiz_pro_voice_url_allowed(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return false;
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    if ($scheme === 'https') return true;
    return $scheme === 'http' && in_array($host, ['127.0.0.1','localhost','::1'], true);
}

function ckm_quiz_pro_voice_gateway_base_url(): string {
    $settings = ckm_quiz_pro_voice_settings();
    $url = trim((string)($settings['gateway_url'] ?? ''));
    if (!ckm_quiz_pro_voice_url_allowed($url)) return '';
    return rtrim($url, '/');
}

function ckm_quiz_pro_voice_http_url(): string {
    $base = ckm_quiz_pro_voice_gateway_base_url();
    if ($base === '') return '';
    if (preg_match('~/v1/voice/messages$~', $base)) return $base;
    return $base . '/v1/voice/messages';
}

function ckm_quiz_pro_voice_ws_url(): string {
    $url = ckm_quiz_pro_voice_gateway_base_url();
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower((string)$parts['scheme']) === 'https' ? 'wss' : 'ws';
    $host = (string)$parts['host'];
    if (strpos($host, ':') !== false && $host[0] !== '[') $host = '['.$host.']';
    $port = isset($parts['port']) ? ':'.(int)$parts['port'] : '';
    return $scheme.'://'.$host.$port.'/voice';
}

function ckm_quiz_pro_voice_ready(): bool {
    $settings = ckm_quiz_pro_voice_settings();
    return !empty($settings['enabled']) && strlen(ckm_quiz_pro_voice_secret()) >= 16 && ckm_quiz_pro_voice_ws_url() !== '' && ckm_quiz_pro_voice_http_url() !== '';
}

function ckm_quiz_pro_voice_gateway_health(): array {
    $base = ckm_quiz_pro_voice_gateway_base_url();
    if ($base === '') return ['ok'=>false,'code'=>'gateway_url_missing','error'=>'Gateway URL не настроен.'];
    $response = wp_remote_get($base.'/health', [
        'timeout'=>4,
        'redirection'=>0,
        'headers'=>['Accept'=>'application/json'],
        'user-agent'=>'CKM-Quiz-Pro-Voice-Diagnostics/1.0',
    ]);
    if (is_wp_error($response)) return ['ok'=>false,'code'=>'gateway_health_request_failed','error'=>sanitize_text_field($response->get_error_message())];
    $http=(int)wp_remote_retrieve_response_code($response);
    $raw=trim((string)wp_remote_retrieve_body($response));
    if ($http<200 || $http>=300) return ['ok'=>false,'code'=>'gateway_health_http_error','httpCode'=>$http,'error'=>'Gateway health HTTP '.$http];
    $data=json_decode($raw,true);
    if (!is_array($data) || empty($data['ok'])) return ['ok'=>false,'code'=>'gateway_health_invalid','error'=>'Gateway вернул некорректный health.'];
    $tts=is_array($data['tts']??null)?$data['tts']:[];
    $ws=is_array($data['websocket']??null)?$data['websocket']:[];
    return [
        'ok'=>true,
        'service'=>sanitize_text_field((string)($data['service']??'')),
        'version'=>sanitize_text_field((string)($data['version']??'')),
        'tts'=>[
            'provider'=>sanitize_key((string)($tts['provider']??'')),
            'enabled'=>!empty($tts['enabled']),
            'configured'=>!empty($tts['configured']),
            'model'=>sanitize_text_field((string)($tts['model']??'')),
            'language'=>sanitize_text_field((string)($tts['language']??'')),
            'voice_id'=>sanitize_text_field((string)($tts['voice_id']??'')),
        ],
        'websocket'=>[
            'enabled'=>!empty($ws['enabled']),
            'configured'=>!empty($ws['configured']),
            'path'=>sanitize_text_field((string)($ws['path']??'')),
            'clients'=>(int)($ws['clients']??0),
            'rooms'=>(int)($ws['rooms']??0),
        ],
    ];
}

function ckm_quiz_pro_voice_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function ckm_quiz_pro_voice_sign_token(array $claims): string {
    $secret = ckm_quiz_pro_voice_secret();
    if (strlen($secret) < 16) return '';
    $json = wp_json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return '';
    $body = ckm_quiz_pro_voice_b64url($json);
    $signed = 'ckm1.'.$body;
    $sig = ckm_quiz_pro_voice_b64url(hash_hmac('sha256', $signed, $secret, true));
    return $signed.'.'.$sig;
}

function ckm_quiz_pro_voice_same_origin(): bool {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return true;

    // Do not compare against home_url(): WordPress keeps the canonical platform
    // host there (ckkm.ru), while a tenant game legitimately runs on e.g.
    // 222.ckkm.ru. Same-origin must be checked against the host that actually
    // received this AJAX request.
    $originHost = (string)wp_parse_url($origin, PHP_URL_HOST);
    $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (function_exists('ckmqp_tenant_hostname')) {
        $originHost = ckmqp_tenant_hostname($originHost);
        $requestHost = ckmqp_tenant_hostname($requestHost);
    } else {
        $originHost = strtolower(rtrim($originHost, '.'));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', rtrim($requestHost, '.')));
    }
    if ($originHost === '' || $requestHost === '') return false;

    // If tenant routing is available, reject a forged/unknown Host before the
    // constant-time equality check. Registered tenant hosts and the platform
    // host are both valid request targets.
    if (function_exists('ckmqp_tenant_resolve')) {
        $ctx = ckmqp_tenant_resolve($requestHost);
        if (!is_array($ctx) || !in_array((string)($ctx['kind'] ?? ''), ['platform','tenant'], true)) return false;
    }

    return hash_equals($requestHost, $originHost);
}

/** Short-lived real-game listener/publisher token for /voice. */
function ckm_quiz_pro_voice_mint(array $game, string $role, int $teamId = 0): array {
    if (!ckm_quiz_pro_voice_ready()) return ['ok'=>false,'code'=>'voice_not_configured','error'=>'Голосовой Gateway не настроен.'];
    if (!in_array($role, ['participant','host','scoreboard'], true)) return ['ok'=>false,'code'=>'voice_role_not_allowed','error'=>'Роль не поддерживает голос.'];
    if ($role === 'participant' && $teamId <= 0) return ['ok'=>false,'code'=>'voice_team_missing','error'=>'Команда не определена.'];
    // A host token is also allowed in an AI-host room as a LISTENER. The UI never
    // exposes the microphone publisher controls unless host_mode=human.
    $gameCode = strtoupper(trim((string)($game['game_code'] ?? '')));
    if ($gameCode === '' || strpos($gameCode, 'QUIZ-') !== 0) return ['ok'=>false,'code'=>'voice_game_invalid','error'=>'Некорректный код игры.'];
    $now = time();
    try { $nonce = ckm_quiz_pro_voice_b64url(random_bytes(12)); }
    catch (Throwable $e) { $nonce = substr(hash('sha256', uniqid('ckm-voice-', true)), 0, 24); }
    $claims = [
        'v'=>1,
        'aud'=>'ckm_voice_ws',
        'game_id'=>$gameCode,
        'role'=>$role,
        'team_id'=>$role === 'participant' ? $teamId : null,
        'iat'=>$now,
        'exp'=>$now + 120,
        'nonce'=>$nonce,
    ];
    $token = ckm_quiz_pro_voice_sign_token($claims);
    if ($token === '') return ['ok'=>false,'code'=>'voice_token_failed','error'=>'Не удалось создать голосовой токен.'];
    return [
        'ok'=>true,
        'protocol'=>CKM_QUIZ_PRO_VOICE_PROTOCOL,
        'wsUrl'=>ckm_quiz_pro_voice_ws_url(),
        'token'=>$token,
        'expiresAt'=>$claims['exp'],
        'gameId'=>$gameCode,
        'role'=>$role,
        'teamId'=>$role === 'participant' ? (string)$teamId : null,
    ];
}

function ckm_quiz_pro_ajax_voice_token(): void {
    if (!ckm_quiz_pro_voice_same_origin()) wp_send_json(['ok'=>false,'error'=>'Недопустимый источник запроса.'],403);
    $game = ckm_quiz_pro_find_game($_POST['game'] ?? '');
    if (!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    $role = sanitize_key((string)($_POST['role'] ?? 'participant'));
    $token = (string)wp_unslash($_POST['token'] ?? '');
    $nonce = (string)wp_unslash($_POST['nonce'] ?? '');
    $teamId = 0;

    if ($role === 'participant') {
        $v = ckm_quiz_pro_validate_team_request($game, (string)($_POST['team'] ?? ''), $token, $nonce);
        if (empty($v['ok'])) ckm_quiz_pro_json_error($v);
        $teamId = (int)$v['team']['id'];
    } elseif ($role === 'scoreboard') {
        if (!ckm_quiz_secret_equal($token,(string)$game['scoreboard_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['scoreboard_nonce'])) {
            wp_send_json(['ok'=>false,'error'=>'Неверная ссылка табло.'],403);
        }
    } elseif ($role === 'host') {
        if (!ckm_quiz_secret_equal($token,(string)$game['host_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['host_nonce'])) {
            wp_send_json(['ok'=>false,'error'=>'Неверная ссылка ведущего.'],403);
        }
    } else {
        wp_send_json(['ok'=>false,'error'=>'Недопустимая роль.'],400);
    }

    $result = ckm_quiz_pro_voice_mint($game, $role, $teamId);
    if (empty($result['ok'])) wp_send_json($result, 503);
    wp_send_json($result);
}
add_action('wp_ajax_ckm_qp_voice_token','ckm_quiz_pro_ajax_voice_token');
add_action('wp_ajax_nopriv_ckm_qp_voice_token','ckm_quiz_pro_ajax_voice_token');

/**
 * Short-lived STT ticket for host voice commands.
 *
 * Older Gateway/STT builds reject role=host on /stt with WebSocket close 1008.
 * The host link is validated here, then WordPress mints a participant-compatible
 * recognition token bound to the first active team. It is used only for speech
 * recognition; the recognized text is interpreted locally in the host browser
 * and no team answer is submitted from this token.
 */
function ckm_quiz_pro_ajax_voice_command_token(): void {
    if (!ckm_quiz_pro_voice_same_origin()) wp_send_json(['ok'=>false,'code'=>'bad_origin','error'=>'Недопустимый источник запроса.'],403);
    $game = ckm_quiz_pro_find_game($_POST['game'] ?? '');
    if (!$game) wp_send_json(['ok'=>false,'code'=>'game_not_found','error'=>'Игра не найдена.'],404);
    $token = (string)wp_unslash($_POST['token'] ?? '');
    $nonce = (string)wp_unslash($_POST['nonce'] ?? '');
    if (!ckm_quiz_secret_equal($token,(string)$game['host_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['host_nonce'])) {
        wp_send_json(['ok'=>false,'code'=>'host_auth_failed','error'=>'Неверная ссылка ведущего.'],403);
    }
    if (!ckm_quiz_pro_voice_ready()) wp_send_json(['ok'=>false,'code'=>'voice_not_configured','error'=>'Голосовой Gateway не настроен.'],503);

    global $wpdb;
    $team = null;
    if (function_exists('ckm_quiz_teams_table')) {
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active' ORDER BY slot_no ASC, id ASC LIMIT 1",
            (int)$game['id']
        ), ARRAY_A);
    }
    if (!$team || empty($team['id'])) {
        wp_send_json(['ok'=>false,'code'=>'voice_command_team_missing','error'=>'Для распознавания команды ведущего нужна хотя бы одна активная команда в игре.'],409);
    }

    $result = ckm_quiz_pro_voice_mint($game, 'participant', (int)$team['id']);
    if (empty($result['ok'])) wp_send_json($result, 503);
    $result['joinRole'] = 'participant';
    $result['commandMode'] = 'host_voice_command';
    $result['note'] = 'STT-токен совместимости для голосовых команд ведущего.';
    wp_send_json($result);
}
add_action('wp_ajax_ckm_qp_voice_command_token','ckm_quiz_pro_ajax_voice_command_token');
add_action('wp_ajax_nopriv_ckm_qp_voice_command_token','ckm_quiz_pro_ajax_voice_command_token');

function ckm_quiz_pro_ajax_voice_diag(): void {
    if (!ckm_quiz_pro_voice_same_origin()) wp_send_json(['ok'=>false,'code'=>'bad_origin','error'=>'Недопустимый источник запроса.'],403);
    $game = ckm_quiz_pro_find_game($_POST['game'] ?? '');
    if (!$game) wp_send_json(['ok'=>false,'code'=>'game_not_found','error'=>'Игра не найдена.'],404);
    $role = sanitize_key((string)($_POST['role'] ?? 'participant'));
    $token = (string)wp_unslash($_POST['token'] ?? '');
    $nonce = (string)wp_unslash($_POST['nonce'] ?? '');
    if ($role === 'participant') {
        $v = ckm_quiz_pro_validate_team_request($game, (string)($_POST['team'] ?? ''), $token, $nonce);
        if (empty($v['ok'])) wp_send_json(['ok'=>false,'code'=>(string)($v['code']??'team_auth_failed'),'error'=>(string)($v['error']??'Неверная ссылка команды.')],403);
    } elseif ($role === 'scoreboard') {
        if (!ckm_quiz_secret_equal($token,(string)$game['scoreboard_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['scoreboard_nonce'])) wp_send_json(['ok'=>false,'code'=>'scoreboard_auth_failed','error'=>'Неверная ссылка табло.'],403);
    } elseif ($role === 'host') {
        if (!ckm_quiz_secret_equal($token,(string)$game['host_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['host_nonce'])) wp_send_json(['ok'=>false,'code'=>'host_auth_failed','error'=>'Неверная ссылка ведущего.'],403);
    } else wp_send_json(['ok'=>false,'code'=>'voice_role_not_allowed','error'=>'Недопустимая роль.'],400);
    if (!ckm_quiz_pro_voice_ready()) wp_send_json(['ok'=>false,'code'=>'voice_not_configured','error'=>'Голосовой Gateway не настроен.'],503);
    $health=ckm_quiz_pro_voice_gateway_health();
    if (empty($health['ok'])) wp_send_json(['ok'=>false,'code'=>(string)($health['code']??'gateway_health_failed'),'error'=>(string)($health['error']??'Gateway health недоступен.'),'health'=>$health],503);
    wp_send_json(['ok'=>true,'wordpress'=>['ready'=>true,'pluginVersion'=>CKM_QUIZ_PRO_VERSION],'health'=>$health]);
}
add_action('wp_ajax_ckm_qp_voice_diag','ckm_quiz_pro_ajax_voice_diag');
add_action('wp_ajax_nopriv_ckm_qp_voice_diag','ckm_quiz_pro_ajax_voice_diag');

function ckm_quiz_pro_sequential_start_timer_after_voice(int $gameId, int $messageEventId): array {
    if ($gameId<=0 || $messageEventId<=0) return ckm_quiz_error(422,'Некорректное подтверждение озвучивания.','voice_playback_invalid');
    $game=ckm_quiz_get_game($gameId);
    if (!$game) return ckm_quiz_error(404,'Игра не найдена.','game_not_found');
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : sanitize_key((string)($game['format_key_snapshot']??''));
    if (!in_array($formatKey,array('classic_quiz','solution_price','negotiation_duel','jeopardy'),true)) {
        return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'format_not_sequential_voice_gate'));
    }
    if ((string)($game['host_mode_snapshot']??'')!=='ai') return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'host_mode_human'));
    global $wpdb;
    $event=$wpdb->get_row($wpdb->prepare(
        "SELECT id,question_id,payload_json FROM ".ckm_quiz_events_table()." WHERE id=%d AND game_id=%d AND action='ai_host_message' LIMIT 1",
        $messageEventId,$gameId
    ),ARRAY_A);
    if (!$event) return ckm_quiz_error(409,'Сообщение ИИ-ведущего не найдено.','voice_playback_stale');
    $payload=ckm_quiz_json_decode((string)($event['payload_json']??''));
    $hostEvent=sanitize_key((string)($payload['event']??''));
    if ($hostEvent!=='question_started') return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'voice_event_no_timer_bridge','hostEvent'=>$hostEvent));
    $qid=(int)($event['question_id']??0);
    if ($qid<=0 || $qid!==(int)($game['current_question_id']??0)) return ckm_quiz_error(409,'Подтверждение относится не к текущему вопросу.','voice_playback_stale');
    if ((string)($game['status']??'')!=='live' || (string)($game['quiz_phase']??'')!=='question_open') return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'question_not_open'));
    $question=ckm_quiz_get_question($qid);
    if (!$question) return ckm_quiz_error(404,'Вопрос не найден.','question_missing');
    if ($formatKey==='jeopardy' && (string)($question['question_stage']??'main')==='final') {
        return function_exists('ckm_quiz_jeopardy_start_final_timer_after_voice')
            ? ckm_quiz_jeopardy_start_final_timer_after_voice($gameId,$messageEventId)
            : ckm_quiz_error(500,'Связка голоса и финального таймера недоступна.','voice_timer_bridge_unavailable');
    }
    $startEvent=$wpdb->get_row($wpdb->prepare(
        "SELECT payload_json FROM ".ckm_quiz_events_table()." WHERE game_id=%d AND question_id=%d AND action='question_started' ORDER BY id DESC LIMIT 1",
        $gameId,$qid
    ),ARRAY_A);
    $startPayload=$startEvent ? ckm_quiz_json_decode((string)($startEvent['payload_json']??'')) : array();
    if (empty($startPayload['timerDeferredUntilVoiceEnd'])) return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'timer_not_deferred'));
    $seconds=(int)($startPayload['timeLimitSeconds']??0);
    if ($seconds<=0 && function_exists('ckm_quiz_effective_question_seconds')) $seconds=ckm_quiz_effective_question_seconds($game,$question);
    if ($seconds<=0) $seconds=60;
    return function_exists('ckm_quiz_start_deferred_question_timer')
        ? ckm_quiz_start_deferred_question_timer($gameId,$qid,$seconds,'voice_playback_complete',$messageEventId)
        : ckm_quiz_error(500,'Связка голоса и таймера недоступна.','voice_timer_bridge_unavailable');
}

function ckm_quiz_pro_ajax_voice_playback_complete(): void {
    if (!ckm_quiz_pro_voice_same_origin()) wp_send_json(['ok'=>false,'code'=>'bad_origin','error'=>'Недопустимый источник запроса.'],403);
    $game=ckm_quiz_pro_find_game((string)($_POST['game'] ?? ''));
    if (!$game) wp_send_json(['ok'=>false,'code'=>'game_not_found','error'=>'Игра не найдена.'],404);
    $token=(string)wp_unslash($_POST['token'] ?? '');
    $nonce=(string)wp_unslash($_POST['nonce'] ?? '');
    if (!ckm_quiz_secret_equal($token,(string)$game['host_token']) || !ckm_quiz_secret_equal($nonce,(string)$game['host_nonce'])) {
        wp_send_json(['ok'=>false,'code'=>'host_auth_failed','error'=>'Неверная ссылка ведущего.'],403);
    }
    $messageId=absint($_POST['message_id'] ?? 0);
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : sanitize_key((string)($game['format_key_snapshot']??''));
    if ($formatKey==='chgk') {
        $result=function_exists('ckm_quiz_chgk_start_ai_early_answer_after_voice')
            ? ckm_quiz_chgk_start_ai_early_answer_after_voice((int)$game['id'],$messageId)
            : ['ok'=>false,'status'=>500,'code'=>'voice_timer_bridge_unavailable','error'=>'Связка голоса и игрового этапа недоступна.'];
    } elseif (in_array($formatKey,['classic_quiz','solution_price','negotiation_duel','jeopardy'],true)) {
        $result=ckm_quiz_pro_sequential_start_timer_after_voice((int)$game['id'],$messageId);
    } else {
        $result=ckm_quiz_result(true,200,['skipped'=>true,'reason'=>'format_timer_gate_not_enabled']);
    }
    if (empty($result['ok'])) wp_send_json(['ok'=>false,'code'=>(string)($result['code'] ?? 'voice_timer_failed'),'error'=>(string)($result['error'] ?? 'Не удалось подтвердить завершение реплики ИИ-ведущего.')],(int)($result['status'] ?? 409));
    $canary=null;
    if(empty($result['skipped']) && function_exists('ckm_quiz_pro_express_canary_is_game') && ckm_quiz_pro_express_canary_is_game($game)) {
        $freshCanaryGame=ckm_quiz_get_game((int)$game['id']) ?: $game;
        $canary=ckm_quiz_pro_express_canary_mirror_voice_finished($freshCanaryGame,$messageId);
        if(function_exists('ckm_quiz_pro_express_canary_log_result')) ckm_quiz_pro_express_canary_log_result('voice_finished',$canary,(int)$game['id']);
    }
    wp_send_json(['ok'=>true,'result'=>$result,'canary'=>$canary]);
}
add_action('wp_ajax_ckm_qp_voice_playback_complete','ckm_quiz_pro_ajax_voice_playback_complete');
add_action('wp_ajax_nopriv_ckm_qp_voice_playback_complete','ckm_quiz_pro_ajax_voice_playback_complete');

/* ---------------- AI-host -> Gateway -> Cartesia/Sergey ---------------- */

function ckm_quiz_pro_voice_message_category(string $event): string {
    $event = sanitize_key($event);
    if (in_array($event, ['game_created','game_started'], true)) return 'welcome';
    if (in_array($event, ['question_started','discussion_warning','final_answer_opened','final_answer_warning','negotiation_show_preparation_started','negotiation_show_dialogue_started','negotiation_show_dialogue_idle_prompt','negotiation_show_hard_question','negotiation_show_story_started','negotiation_show_story_questions','negotiation_show_story_answer','negotiation_show_story_vote','negotiation_show_next_attempt','negotiation_show_round_started'], true)) return 'question';
    if (in_array($event, ['question_closed','question_review_started','question_review_finished','answer_judged','negotiation_show_review_started','negotiation_show_review_finished','negotiation_show_story_result','negotiation_show_round_finished'], true)) return 'result';
    if (in_array($event, ['final_revealed','game_finished','negotiation_show_finished'], true)) return 'final';
    return 'system';
}

function ckm_quiz_pro_voice_extract_host_text(array $hostOutput): string {
    $text = '';
    if (isset($hostOutput['text']) && is_array($hostOutput['text'])) $text = (string)($hostOutput['text']['content'] ?? '');
    elseif (isset($hostOutput['text']) && is_string($hostOutput['text'])) $text = (string)$hostOutput['text'];
    elseif (isset($hostOutput['content']) && is_string($hostOutput['content'])) $text = (string)$hostOutput['content'];
    $text = trim(wp_strip_all_tags($text));
    if (function_exists('mb_substr')) return mb_substr($text, 0, 5000, 'UTF-8');
    return substr($text, 0, 5000);
}

function ckm_quiz_pro_voice_payload_from_event(array $event): ?array {
    if ((string)($event['action'] ?? '') !== 'ai_host_message') return null;
    if (ckm_quiz_pro_ai_voice_provider() !== 'gateway' || !ckm_quiz_pro_voice_ready()) return null;
    $eventId = (int)($event['id'] ?? 0);
    $gameId = (int)($event['game_id'] ?? 0);
    $hostOutput = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    $text = ckm_quiz_pro_voice_extract_host_text($hostOutput);
    if ($eventId <= 0 || $gameId <= 0 || $text === '') return null;
    $game = function_exists('ckm_quiz_get_game') ? ckm_quiz_get_game($gameId) : null;
    if (!is_array($game) || (string)($game['host_mode_snapshot'] ?? '') !== 'ai') return null;
    $hostEvent = sanitize_key((string)($hostOutput['event'] ?? 'system'));
    return [
        'protocol'=>CKM_QUIZ_PRO_VOICE_PROTOCOL,
        'type'=>'voice_message',
        'game_id'=>(string)($game['game_code'] ?? ('GAME-'.$gameId)),
        'game_db_id'=>$gameId,
        'message_id'=>$eventId,
        'event_key'=>(string)($event['event_key'] ?? ''),
        'category'=>ckm_quiz_pro_voice_message_category($hostEvent),
        'host_event'=>$hostEvent,
        'text'=>$text,
        'start_timer_after'=>false,
        'question_id'=>(int)($event['question_id'] ?? 0),
        'format_key'=>sanitize_key((string)($game['format_key_snapshot'] ?? '')),
        'test_mode'=>!empty($game['test_mode']),
        'created_at'=>gmdate('c'),
    ];
}

function ckm_quiz_pro_voice_send_payload(array $payload, bool $blocking = false): array {
    if (!ckm_quiz_pro_voice_ready()) return ['ok'=>false,'code'=>'voice_not_configured'];
    $url = ckm_quiz_pro_voice_http_url();
    $secret = ckm_quiz_pro_voice_secret();
    if ($url === '' || strlen($secret) < 16) return ['ok'=>false,'code'=>'voice_not_configured'];
    $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') return ['ok'=>false,'code'=>'payload_encode_failed'];
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp."\n".$body, $secret);
    $response = wp_remote_post($url, [
        'timeout'=>$blocking ? 8 : 0.01,
        'blocking'=>$blocking,
        'redirection'=>0,
        'headers'=>[
            'Content-Type'=>'application/json; charset=utf-8',
            'Accept'=>'application/json',
            'X-CKM-Voice-Protocol'=>CKM_QUIZ_PRO_VOICE_PROTOCOL,
            'X-CKM-Voice-Timestamp'=>(string)$timestamp,
            'X-CKM-Voice-Signature'=>'sha256='.$signature,
            'X-CKM-Voice-Message-Id'=>(string)($payload['message_id'] ?? ''),
        ],
        'body'=>$body,
        'user-agent'=>'CKM-Quiz-Pro-Voice/1.0',
    ]);
    if (is_wp_error($response)) return ['ok'=>false,'code'=>'gateway_request_failed','error'=>sanitize_text_field($response->get_error_message())];
    if (!$blocking) return ['ok'=>true,'code'=>'queued_nonblocking'];
    $http = (int)wp_remote_retrieve_response_code($response);
    $raw = trim((string)wp_remote_retrieve_body($response));
    if ($http < 200 || $http >= 300) return ['ok'=>false,'code'=>'gateway_http_error','httpCode'=>$http,'error'=>sanitize_text_field($raw ?: ('HTTP '.$http))];
    return ['ok'=>true,'code'=>'gateway_ok','httpCode'=>$http,'body'=>$raw];
}

function ckm_quiz_pro_voice_on_event_appended(array $event): void {
    $payload = ckm_quiz_pro_voice_payload_from_event($event);
    if (!$payload) return;
    $key = 'ckm_qp_voice_sent_'.(int)$payload['message_id'];
    if (get_transient($key)) return;
    $result = ckm_quiz_pro_voice_send_payload($payload, false);
    if (!empty($result['ok'])) set_transient($key, 1, DAY_IN_SECONDS);
    else error_log('CKM Quiz Pro voice bridge: '.(string)($result['code'] ?? 'unknown').(!empty($result['error'])?' — '.$result['error']:''));
}
add_action('ckm_quiz_event_appended','ckm_quiz_pro_voice_on_event_appended',10,1);

/* ---------------- Admin settings ---------------- */

function ckm_quiz_pro_voice_admin_menu(): void {
    add_submenu_page('ckm-quiz-pro','Голос ведущего','Голос','manage_options','ckm-quiz-pro-voice','ckm_quiz_pro_voice_admin_page');
}
add_action('admin_menu','ckm_quiz_pro_voice_admin_menu',30);

function ckm_quiz_pro_voice_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_voice_save'])) {
        check_admin_referer('ckm_qp_voice_save');
        $old = ckm_quiz_pro_voice_settings();
        $secret = trim((string)wp_unslash($_POST['secret'] ?? ''));
        if ($secret === '') $secret = (string)($old['secret'] ?? '');
        $url = esc_url_raw(trim((string)wp_unslash($_POST['gateway_url'] ?? '')));
        $provider = sanitize_key((string)wp_unslash($_POST['ai_voice_provider'] ?? 'browser'));
        if (!in_array($provider, ['browser','gateway'], true)) $provider = 'browser';
        update_option(CKM_QUIZ_PRO_VOICE_OPTION, [
            'enabled'=>!empty($_POST['enabled']),
            'gateway_url'=>$url !== '' ? $url : 'https://gateway.video-training.ru',
            'secret'=>$secret,
            'ai_voice_provider'=>$provider,
        ], false);
        $notice = 'Настройки голоса сохранены.';
    }
    $settings = ckm_quiz_pro_voice_settings();
    $constant = defined('CKM_QUIZ_PRO_VOICE_SECRET') || (is_string(getenv('CKM_VOICE_SECRET')) && strlen((string)getenv('CKM_VOICE_SECRET')) >= 16);
    $ready = ckm_quiz_pro_voice_ready();
    $provider = ckm_quiz_pro_ai_voice_provider();
    echo '<div class="wrap"><h1>Голос ведущего</h1>';
    if ($notice !== '') echo '<div class="notice notice-success"><p>'.esc_html($notice).'</p></div>';
    if ($ready) {
        echo '<div class="notice notice-success"><p><strong>Gateway готов.</strong> Доступны Сергей через Cartesia и микрофон голосового ведущего.</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p><strong>Gateway пока не готов.</strong> Браузерный голос ИИ работает бесплатно; для Сергея и микрофона нужен включённый Gateway и серверный секрет.</p></div>';
    }
    echo '<form method="post">'; wp_nonce_field('ckm_qp_voice_save');
    echo '<table class="form-table">';
    echo '<tr><th>Голос ИИ-ведущего</th><td>';
    echo '<label style="display:block;margin-bottom:8px"><input type="radio" name="ai_voice_provider" value="browser" '.checked($provider,'browser',false).'> <strong>Браузерный — бесплатно</strong></label>';
    echo '<label style="display:block"><input type="radio" name="ai_voice_provider" value="gateway" '.checked($provider,'gateway',false).'> <strong>Сергей — Cartesia через Gateway</strong></label>';
    echo '<p class="description">Браузерный режим использует SpeechSynthesis на устройстве участника; конкретный русский голос выбирается прямо в игровой комнате. Режим «Сергей» отправляет одну реплику ИИ в Gateway, где Cartesia синтезирует её один раз и транслирует всем подключённым участникам.</p></td></tr>';
    echo '<tr><th>Включить Gateway</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($settings['enabled']),true,false).'> Разрешить Сергея (Cartesia) и микрофон голосового ведущего</label></td></tr>';
    echo '<tr><th>Gateway</th><td><input class="regular-text code" type="url" name="gateway_url" value="'.esc_attr((string)$settings['gateway_url']).'"><p class="description">Текущая инфраструктура: https://gateway.video-training.ru</p></td></tr>';
    echo '<tr><th>Секрет Gateway</th><td>';
    if ($constant) {
        echo '<strong>Задан на сервере</strong><p class="description">Используется CKM_QUIZ_PRO_VOICE_SECRET / CKM_VOICE_SECRET. В браузер он не передаётся.</p>';
    } else {
        echo '<input class="regular-text" type="password" autocomplete="new-password" name="secret" value="" placeholder="'.(strlen((string)($settings['secret']??''))>=16?'Секрет уже сохранён — оставьте пустым':'Введите тот же секрет, что настроен в Gateway').'"><p class="description">Секрет хранится только на сервере WordPress. Им подписываются TTS-запросы и короткоживущие WebSocket-токены.</p>';
    }
    echo '</td></tr>';
    echo '<tr><th>TTS endpoint</th><td><code>'.esc_html(ckm_quiz_pro_voice_http_url() ?: 'не настроен').'</code></td></tr>';
    echo '<tr><th>WebSocket</th><td><code>'.esc_html(ckm_quiz_pro_voice_ws_url() ?: 'не настроен').'</code></td></tr>';
    echo '</table>';
    echo '<p><button class="button button-primary" name="ckm_qp_voice_save" value="1">Сохранить</button></p></form></div>';
}
