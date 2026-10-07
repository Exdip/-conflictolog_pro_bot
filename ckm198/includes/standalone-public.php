<?php
if (!defined('ABSPATH')) exit;

/**
 * Public gameplay URLs deliberately mirror the proven main-platform layout:
 *   /ckm/quiz-room.html
 *   /ckm/quiz-host.html
 *   /ckm/quiz-scoreboard.html
 *
 * The request is intercepted before WordPress canonical/front-page redirects,
 * so a game room never depends on a WP Page, theme template or homepage route.
 */
function ckm_quiz_pro_page_url(string $key,array $args=[]): string {
    $paths=[
        'play'=>'/ckm/quiz-room.html',
        'host'=>'/ckm/quiz-host.html',
        'scoreboard'=>'/ckm/quiz-scoreboard.html',
    ];
    $path=$paths[$key] ?? '/ckm/quiz-room.html';
    // Version marker is deliberately part of the room URL.  Besides making the
    // generated link auditable it busts browser/proxy caches left by an older
    // physical /ckm/quiz-*.html payload.
    $args['ckmqpv']=CKM_QUIZ_PRO_VERSION;
    return add_query_arg($args,home_url($path));
}
function ckm_quiz_pro_team_url(array $game,array $team): string { return ckmqp_tenant_link(ckm_quiz_pro_page_url('play',['game'=>$game['game_code'],'team'=>$team['teamKey'],'token'=>$team['teamToken'],'nonce'=>$team['nonce']]),(int)($game['tenant_id']??0)); }
function ckm_quiz_pro_scoreboard_url(array $game): string { return ckmqp_tenant_link(ckm_quiz_pro_page_url('scoreboard',['game'=>$game['game_code'],'token'=>$game['scoreboard_token'],'nonce'=>$game['scoreboard_nonce']]),(int)($game['tenant_id']??0)); }
function ckm_quiz_pro_host_url(array $game): string { return ckmqp_tenant_link(ckm_quiz_pro_page_url('host',['game'=>$game['game_code'],'token'=>$game['host_token'],'nonce'=>$game['host_nonce']]),(int)($game['tenant_id']??0)); }


/**
 * Standalone Игровая платформа renders the three /ckm/quiz-*.html routes through
 * WordPress.  Older CKM/main-site experiments may have left real HTML files in
 * ABSPATH/ckm.  Apache/Nginx serves those files before WordPress can run, so
 * the browser loads the main-platform quiz-room.js and its participant-session
 * contract (including a second participant nonce).  The standalone AJAX join
 * contract is intentionally different; the symptom is exactly:
 * "Сервер не вернул nonce участника."
 *
 * Remove only the three recognised stale shell HTML files.  Assets/API files
 * are untouched.  If the full CKM main plugin is active, never touch its
 * deployed /ckm payload.
 */
function ckm_quiz_pro_cleanup_stale_static_room_shells(): void {
    if(defined('CKM_EA_FRONTEND_PAYLOAD_VERSION') || function_exists('ckm_ea_deploy_frontend_payload')) return;
    if(get_option('ckm_quiz_pro_static_room_cleanup_version','')===CKM_QUIZ_PRO_VERSION) return;

    $dir=trailingslashit(ABSPATH).'ckm';
    $targets=[
        'quiz-room.html'=>['assets/quiz-room.js','комната участника'],
        'quiz-host.html'=>['assets/quiz-host.js','панель ведущего'],
        'quiz-scoreboard.html'=>['assets/quiz-scoreboard.js','Публичное табло'],
    ];
    $removed=[]; $blocked=[];
    foreach($targets as $name=>$markers){
        $file=$dir.'/'.$name;
        if(!is_file($file)) continue;
        $html=@file_get_contents($file);
        if(!is_string($html)) { $blocked[]=$name; continue; }
        $known=false;
        foreach($markers as $marker){ if($marker!=='' && stripos($html,$marker)!==false){ $known=true; break; } }
        // The main CKM shell always identifies itself as a quiz page and loads
        // one of the known role scripts.  Do not delete an unrelated user file.
        if(!$known || (stripos($html,'CKM')===false && stripos($html,'ЦКМ')===false)) { $blocked[]=$name; continue; }
        if(@unlink($file)) $removed[]=$name; else $blocked[]=$name;
    }

    update_option('ckm_quiz_pro_static_room_cleanup_last',[
        'version'=>CKM_QUIZ_PRO_VERSION,
        'removed'=>$removed,
        'blocked'=>$blocked,
        'time'=>time(),
    ],false);
    if(!$blocked) update_option('ckm_quiz_pro_static_room_cleanup_version',CKM_QUIZ_PRO_VERSION,false);
}
add_action('plugins_loaded','ckm_quiz_pro_cleanup_stale_static_room_shells',20);

function ckm_quiz_pro_public_mode_from_request(): string {
    $path=trim((string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)??''),'/');
    $homePath=trim((string)(parse_url(home_url('/'),PHP_URL_PATH)??''),'/');
    if($homePath!=='' && str_starts_with($path,$homePath.'/')) $path=substr($path,strlen($homePath)+1);
    $map=[
        'ckm/quiz-room.html'=>'play',
        'ckm/quiz-host.html'=>'host',
        'ckm/quiz-scoreboard.html'=>'scoreboard',
        'ckm-quiz-pro-play'=>'play',
        'ckm-quiz-pro-host'=>'host',
        'ckm-quiz-pro-scoreboard'=>'scoreboard',
    ];
    if(isset($map[$path])) return $map[$path];
    if(str_ends_with($path,'/ckm/quiz-room.html')) return 'play';
    if(str_ends_with($path,'/ckm/quiz-host.html')) return 'host';
    if(str_ends_with($path,'/ckm/quiz-scoreboard.html')) return 'scoreboard';
    $last=$path!=='' ? basename($path) : '';
    if(isset($map[$last])) return $map[$last];
    $requested=sanitize_key((string)($_GET['ckmqp_screen']??''));
    if(in_array($requested,['play','host','scoreboard'],true)) return $requested;
    return '';
}

function ckm_quiz_pro_refresh_stale_public_version(): void {
    $requested=isset($_GET['ckmqpv']) ? sanitize_text_field(wp_unslash((string)$_GET['ckmqpv'])) : '';
    if($requested==='' || hash_equals((string)CKM_QUIZ_PRO_VERSION,$requested)) return;

    // Room links are intentionally long-lived and may have been created by an
    // older plugin build. Some browser/proxy caches key the rendered shell by
    // the ckmqpv query marker, so a hard refresh can still replay an old HTML
    // shell and therefore its old standalone-game.js. Redirect only the same
    // relative request URI with the current marker; game/team/token/nonce and
    // tenant hostname are preserved unchanged.
    $requestUri=(string)wp_unslash($_SERVER['REQUEST_URI'] ?? '');
    if($requestUri==='') return;
    $target=add_query_arg('ckmqpv',CKM_QUIZ_PRO_VERSION,$requestUri);
    if(!is_string($target) || $target==='' || $target===$requestUri) return;

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    wp_safe_redirect($target,302,'CKM Quiz Pro');
    exit;
}

function ckm_quiz_pro_fullscreen_pages(): void {
    if(is_admin()) return;
    $mode=ckm_quiz_pro_public_mode_from_request();
    if($mode===''){
        // Keep the old helper WP Pages usable as a compatibility fallback.
        foreach(['play','host','scoreboard'] as $key){
            $id=(int)get_option('ckm_quiz_pro_page_'.$key,0);
            if($id>0 && is_page($id)){ $mode=$key; break; }
        }
    }
    if($mode==='') return;
    ckm_quiz_pro_refresh_stale_public_version();
    status_header(200);
    nocache_headers();
    ckm_quiz_pro_render_public_shell($mode);
    exit;
}
// Run before redirect_canonical and before the managed homepage/frontdoor.
add_action('template_redirect','ckm_quiz_pro_fullscreen_pages',-1000);

// Canonical redirects must never rewrite or strip gameplay credentials.
add_filter('redirect_canonical',static function($redirect,$requested){
    return ckm_quiz_pro_public_mode_from_request()!=='' ? false : $redirect;
},10,2);

function ckm_quiz_pro_render_public_shell(string $mode): void {
    $ajax=admin_url('admin-ajax.php');
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Игровая платформа</title><style>
    @import url("https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&family=Ubuntu:ital,wght@0,400;0,500;0,700;1,400&display=swap");
    :root{font-family:"Ubuntu",Arial,sans-serif;color-scheme:dark}*{box-sizing:border-box}h1,h2,h3,h4,h5,h6,.ckm-logo,.brand{font-family:"Caveat","Bad Script",cursive}body{margin:0;background:#07101d;color:#eef4ff;min-height:100vh}.top{display:flex;justify-content:space-between;align-items:center;padding:16px 24px;border-bottom:1px solid #22314a;background:#0b1626}.brand{font-size:26px;font-weight:700}.pill{padding:7px 12px;border:1px solid #314767;border-radius:999px;color:#b9c9e4}.wrap{max-width:1180px;margin:0 auto;padding:28px 20px}.hero{margin-bottom:24px}.hero h1{font-size:clamp(32px,5vw,52px);margin:8px 0}.muted{color:#9fb0cb}.card{background:#0d1a2d;border:1px solid #243955;border-radius:18px;padding:22px;margin:16px 0;box-shadow:0 16px 40px rgba(0,0,0,.22)}.question{font-size:clamp(18px,1.8vw,24px);line-height:1.45;font-weight:400}.options{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}.opt{width:100%;min-height:72px;border:1px solid #365276;background:#10233d;color:#fff;border-radius:14px;padding:14px 18px;text-align:left;font-size:18px;cursor:pointer}.opt:hover{background:#183252}.opt:disabled{opacity:.55;cursor:not-allowed}.teams{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.team{background:#10233d;border-radius:14px;padding:16px}.score{font-size:34px;font-weight:800}.timer{font-size:22px;font-weight:400;line-height:1.35;margin:6px 0 10px}.status{padding:12px 14px;background:#0a1526;border:1px solid transparent;border-radius:12px}.status.ok{border-color:#2f7b50}.status.err{border-color:#8b3144;color:#ffd2d8}.ai-host-card{border-color:#365276;background:#0b1b31}.ai-host-message{font-size:18px;line-height:1.55;margin-top:8px;white-space:pre-wrap}.ai-voice-controls{margin-left:auto}.answer-box{display:none;margin-top:22px;gap:12px}.answer-box.show{display:grid}.answer-box.final-answer-open{border:2px solid #dceaff;background:#10233d;padding:18px;border-radius:18px;box-shadow:0 0 0 4px rgba(220,234,255,.08),0 18px 42px rgba(0,0,0,.35)}.answer-box-title{font:700 28px "Ubuntu",Arial,sans-serif;letter-spacing:.02em}.answer-box.final-answer-open textarea{min-height:150px;font-size:22px;border-color:#dceaff;background:#071321}.answer-box.final-answer-open .answer-btn{min-height:58px;font-size:20px}.answer-box textarea{width:100%;min-height:96px;border:1px solid #365276;background:#0a1727;color:#fff;border-radius:14px;padding:14px;font:inherit;font-size:18px;resize:vertical}.answer-btn{min-height:48px;border:0;border-radius:12px;background:#dceaff;color:#07101d;font:700 16px "Ubuntu",Arial,sans-serif;padding:10px 18px;cursor:pointer}.answer-btn:disabled{opacity:.55;cursor:not-allowed}.format-note{margin-top:12px;color:#9fb0cb;font-size:14px}.host-actions{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.host-btn{min-height:44px;border:1px solid #365276;border-radius:12px;background:#12243d;color:#fff;padding:9px 14px;font:700 15px "Ubuntu",Arial,sans-serif;cursor:pointer}.host-btn.primary{background:#dceaff;color:#07101d;border-color:#dceaff}.host-btn.danger{border-color:#8b3144;color:#ffd2d8}.host-btn:disabled{opacity:.45;cursor:not-allowed}.voice-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.voice-select{min-height:38px;max-width:260px;border:1px solid #365276;border-radius:10px;background:#12243d;color:#fff;padding:7px 10px;font:500 14px "Ubuntu",Arial,sans-serif}.voice-btn{min-height:38px;border:1px solid #365276;border-radius:10px;background:#12243d;color:#fff;padding:7px 11px;font:700 14px "Ubuntu",Arial,sans-serif;cursor:pointer}.voice-btn.primary{background:#dceaff;color:#07101d;border-color:#dceaff}.voice-btn.danger{background:#5b1725;border-color:#8b3144}.voice-btn:disabled{opacity:.5;cursor:not-allowed}.voice-badge{padding:6px 9px;border:1px solid #314767;border-radius:999px;color:#b9c9e4;font-size:12px}.voice-badge.on{border-color:#2f7b50;color:#8df0b0}.voice-badge.live{border-color:#b53a4c;color:#ff6b6b;font-weight:700}.voice-instruction{flex-basis:100%;color:#9fb0cb;font-size:13px;line-height:1.4;margin-top:2px}.voice-diag{width:100%;max-width:560px;margin-top:4px;border:1px solid #314767;border-radius:10px;background:#091526;padding:0}.voice-diag summary{cursor:pointer;padding:8px 10px;color:#b9c9e4;font-size:12px;font-weight:700}.voice-diag[open] summary{border-bottom:1px solid #22314a}.voice-diag-list{padding:7px 10px}.voice-diag-row{display:grid;grid-template-columns:18px 132px 1fr;gap:6px;align-items:start;padding:4px 0;font-size:12px;color:#9fb0cb}.voice-diag-row.ok .voice-diag-symbol{color:#8df0b0}.voice-diag-row.err .voice-diag-symbol{color:#ff9a9a}.voice-diag-row.pending .voice-diag-symbol{color:#ffd37a}.voice-diag-name{color:#d7e4f7;font-weight:700}.voice-diag-detail{overflow-wrap:anywhere}.voice-diag-error{margin:0 10px 10px;padding:8px 10px;border:1px solid #8b3144;border-radius:8px;color:#ffd2d8;background:#28131a;font-size:12px;overflow-wrap:anywhere}.answer-row{border:1px solid #294563;background:#0a1727;border-radius:12px;padding:12px;margin:9px 0}.answer-row strong{display:block;margin-bottom:5px}.answer-preview{margin:10px 0;padding:12px;background:#071321;border-radius:10px;font-size:18px}.ok{color:#80e5a5}.err{color:#ff9a9a}.chgk-review{display:none}.chgk-review.show{display:block}.chgk-review h2{margin:0 0 14px}.review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.review-item{border:1px solid #294563;background:#0a1727;border-radius:14px;padding:14px}.review-item.wide{grid-column:1/-1}.review-label{color:#9fb0cb;font-size:13px;margin-bottom:6px}.review-value{font-size:18px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}.review-verdict{display:inline-flex;align-items:center;border:1px solid #314767;border-radius:999px;padding:6px 10px;font-weight:700}.review-verdict.accepted{border-color:#2f7b50;color:#80e5a5}.review-verdict.rejected{border-color:#8b3144;color:#ff9a9a}.review-verdict.pending{color:#ffd37a}.review-points{font-size:32px;font-weight:800}.review-points.positive{color:#80e5a5}.review-points.negative{color:#ff9a9a}.review-score{font-size:28px;font-weight:800}.chgk-final{display:none;text-align:center;border-color:#365276;background:linear-gradient(180deg,#10233d,#0b1728)}.chgk-final.show{display:block}.chgk-final-kicker{color:#9fb0cb;text-transform:uppercase;letter-spacing:.08em;font-size:12px;font-weight:700}.chgk-final-title{font:700 clamp(38px,6vw,64px) "Caveat",cursive;margin:6px 0 18px}.chgk-final-score{display:flex;justify-content:center;align-items:center;gap:18px;font-size:clamp(46px,9vw,86px);font-weight:800;line-height:1}.chgk-final-side{min-width:120px}.chgk-final-side span{display:block;font-size:14px;color:#9fb0cb;margin-top:8px;font-weight:500}.chgk-final-colon{color:#9fb0cb}.chgk-final-meta{margin-top:18px;color:#b9c9e4;font-size:15px}.chgk-final-win{color:#80e5a5}.chgk-final-loss{color:#ffb0bd}
    .jeopardy-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.board-round-title{font:700 26px "Caveat",cursive;margin-bottom:10px}.board-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.board-category{min-width:0}.board-category-title{min-height:58px;display:flex;align-items:center;justify-content:center;text-align:center;background:#132846;border:1px solid #345479;border-radius:12px;padding:9px;font-weight:700}.board-cells{display:grid;gap:8px;margin-top:8px}.board-cell{width:100%;min-height:64px;border-radius:12px;border:1px solid #35567d;background:#10233d;color:#e8f1ff;font:700 24px "Ubuntu",sans-serif}.board-cell.available{background:#17355b}.board-cell.selectable{cursor:pointer;outline:2px solid transparent}.board-cell.selectable:hover,.board-cell.selectable:focus{background:#254e80;outline-color:#dceaff}.board-cell.used{opacity:.35}.board-cell:disabled{cursor:default}.buzz-btn{width:100%;min-height:86px;border:2px solid #dceaff;border-radius:18px;background:#dceaff;color:#07101d;font:800 clamp(25px,5vw,46px) "Ubuntu",sans-serif;cursor:pointer}.buzz-btn:active{transform:translateY(1px)}.special-banner{padding:15px;border:1px solid #8a7040;background:#2a2415;border-radius:14px}.host-block{margin:14px 0;padding:14px;border:1px solid #294563;background:#0a1727;border-radius:14px}.final-rows{margin:12px 0}[hidden]{display:none!important}
    @media(max-width:760px){.options{grid-template-columns:1fr}.review-grid{grid-template-columns:1fr}.top{padding:12px 14px}.wrap{padding:20px 12px}.board-grid{grid-template-columns:1fr 1fr}.board-category-title{min-height:48px}.board-cell{min-height:54px;font-size:20px}}
    @media(max-width:420px){.board-grid{grid-template-columns:1fr}.board-category{display:grid;grid-template-columns:minmax(110px,1fr) 1.3fr;gap:8px}.board-category-title{height:auto}.board-cells{margin-top:0}}
    .host-guide-details summary{cursor:pointer;font-size:20px}.host-guide-details h3{margin:18px 0 8px}.host-guide-details ul,.host-guide-details ol{padding-left:22px;line-height:1.55}.host-guide-details li{margin:6px 0}</style></head><body>
    <div class="top"><div><div class="muted" style="margin-top:2px"><?php echo esc_html($mode==='play'?ckm_quiz_pro_ui_text('room_play_label'):($mode==='host'?ckm_quiz_pro_ui_text('room_host_label'):ckm_quiz_pro_ui_text('room_scoreboard_label'))); ?></div></div><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><div class="pill" id="gameCode"><?php echo esc_html(ckm_quiz_pro_ui_text('room_connecting')); ?></div></div></div>
    <main class="wrap"><div class="hero"><div class="muted" id="phase"><?php echo esc_html(ckm_quiz_pro_ui_text('room_waiting_phase')); ?></div><h1 id="title"><?php echo esc_html(ckm_quiz_pro_ui_text('room_default_title')); ?></h1><div class="status" id="status"><?php echo esc_html(ckm_quiz_pro_ui_text('room_sync_status')); ?></div></div>
    <?php if($mode==='play'): ?>
        <section class="card"><div class="muted" id="teamName"><?php echo esc_html(ckm_quiz_pro_ui_text('participant_team_label')); ?></div><div class="timer" id="timer">—</div><div class="question" id="question"><?php echo esc_html(ckm_quiz_pro_ui_text('participant_waiting_question')); ?></div><div class="options" id="options"></div><div id="jeopardyAction" hidden></div><div class="answer-box" id="answerBox"><textarea id="answerText" placeholder="Ответ команды"></textarea><button type="button" class="answer-btn" id="answerBtn">Отправить ответ</button><div class="format-note">После отправки ответ фиксируется.</div></div></section>
        <section class="card chgk-review" id="chgkReview" aria-live="polite"><h2>Разбор ответа</h2><div class="review-grid" id="chgkReviewBody"></div></section>
        <section class="card chgk-final" id="chgkFinal" aria-live="polite"><div id="chgkFinalBody"></div></section>
        <section class="card" id="jeopardyArea" hidden><div class="jeopardy-head"><h2 style="margin:0">Игровое поле</h2><div class="pill" id="jeopardySelector">Право выбора</div></div><div id="jeopardyBoard"></div></section>
        <section class="card"><h2><?php echo esc_html(ckm_quiz_pro_ui_text('score_title')); ?></h2><div class="teams" id="teams"></div></section>
    <?php elseif($mode==='scoreboard'): ?>
        <section class="card"><div class="timer" id="timer">—</div><div class="question" id="question"><?php echo esc_html(ckm_quiz_pro_ui_text('host_waiting_question')); ?></div></section>
        <section class="card chgk-final" id="chgkFinal" aria-live="polite"><div id="chgkFinalBody"></div></section>
        <section class="card" id="jeopardyArea" hidden><div class="jeopardy-head"><h2 style="margin:0">Игровое поле</h2><div class="pill" id="jeopardySelector">Право выбора</div></div><div id="jeopardyBoard"></div></section>
        <section class="card"><h2><?php echo esc_html(ckm_quiz_pro_ui_text('scoreboard_title')); ?></h2><div class="teams" id="teams"></div></section>
    <?php else: ?>
        <section class="card" id="hostChgkGuideStandalone" data-guide-marker="CHGK_HOST_GUIDE_AI_FIRST_NO_DISCUSSION_BUTTON_0_3_23_108" hidden>
            <details class="host-guide-details">
                <summary><strong>Краткая инструкция по «Битве знатоков»</strong></summary>
                <div class="format-note">Игра идёт до шести побед одной из сторон: «Знатоки» или «Игра».</div>
                <h3>ИИ-ведущий</h3>
                <ul>
                    <li>сам подключает голос Сергея;</li>
                    <li>сам ждёт готовности голоса;</li>
                    <li>сам запускает первый вопрос;</li>
                    <li>сам озвучивает этапы;</li>
                    <li>сам ведёт игру;</li>
                    <li>сам оценивает ответы;</li>
                    <li>сам переходит к следующим вопросам.</li>
                </ul>
                <h3>Ведущий-человек</h3>
                <ul>
                    <li>сам говорит в микрофон;</li>
                    <li>сам нажимает «Задать вопрос»;</li>
                    <li>сам принимает решение арбитра;</li>
                    <li>сам нажимает «Засчитать» или «Не засчитать».</li>
                </ul>
                <p class="format-note">Человек вмешивается только при необходимости, например если надо перейти к ручному управлению.</p>
            </details>
        </section>
        <section class="card"><div class="muted"><?php echo esc_html(ckm_quiz_pro_ui_text('host_panel_label')); ?></div><div class="timer" id="timer">—</div><div class="question" id="question"><?php echo esc_html(ckm_quiz_pro_ui_text('host_waiting_question')); ?></div><div class="host-actions" id="hostModeActions"><button type="button" class="host-btn" id="hostModeSwitch" hidden>Переключить на ведущего-человека</button></div><div class="host-actions" id="genericHostActions"><button type="button" class="host-btn primary" id="hostNext"><?php echo esc_html(ckm_quiz_pro_ui_text('host_start_human_button')); ?></button><button type="button" class="host-btn" id="hostClose"><?php echo esc_html(ckm_quiz_pro_ui_text('host_close_button')); ?></button><button type="button" class="host-btn danger" id="hostFinish"><?php echo esc_html(ckm_quiz_pro_ui_text('host_finish_button')); ?></button></div><div class="format-note"><?php echo esc_html(ckm_quiz_pro_ui_text('host_voice_prestart_note')); ?></div></section>
        <section class="card chgk-final" id="chgkFinal" aria-live="polite"><div id="chgkFinalBody"></div></section>
        <section class="card" id="jeopardyArea" hidden><div class="jeopardy-head"><h2 style="margin:0">Игровое поле</h2><div class="pill" id="jeopardySelector">Право выбора</div></div><div id="jeopardyBoard"></div></section>
        <section class="card" id="jeopardyHostArea" hidden><h2>Управление «Интеллектуальным батлом»</h2></section>
        <section class="card" id="genericAnswersCard"><h2><?php echo esc_html(ckm_quiz_pro_ui_text('host_answers_title')); ?></h2><div id="hostAnswers"><div class="muted"><?php echo esc_html(ckm_quiz_pro_ui_text('host_answers_empty')); ?></div></div></section>
        <section class="card"><h2><?php echo esc_html(ckm_quiz_pro_ui_text('score_title')); ?></h2><div class="teams" id="teams"></div></section>
    <?php endif; ?>
    </main>
    <?php $aiVoiceProvider=function_exists('ckm_quiz_pro_ai_voice_provider')?ckm_quiz_pro_ai_voice_provider():'browser'; ?>
    <script>window.CKMQPGameConfig={ajaxUrl:<?php echo wp_json_encode($ajax); ?>,mode:<?php echo wp_json_encode($mode); ?>,uiLabels:<?php echo wp_json_encode(ckm_quiz_pro_ui_js_labels(), JSON_UNESCAPED_UNICODE); ?>};window.CKMQPVoiceConfig={ajaxUrl:<?php echo wp_json_encode($ajax); ?>,mode:<?php echo wp_json_encode($mode); ?>,ready:<?php echo function_exists('ckm_quiz_pro_voice_ready') && ckm_quiz_pro_voice_ready() ? 'true' : 'false'; ?>,aiProvider:<?php echo wp_json_encode($aiVoiceProvider); ?>};window.CKMQPAIHostConfig={mode:<?php echo wp_json_encode($mode); ?>,provider:<?php echo wp_json_encode($aiVoiceProvider); ?>};</script>
    <script src="<?php echo esc_url(CKM_QUIZ_PRO_URL.'assets/standalone-game.js?v='.rawurlencode(CKM_QUIZ_PRO_VERSION)); ?>"></script>
    <script src="<?php echo esc_url(CKM_QUIZ_PRO_URL.'assets/standalone-ai-host.js?v='.rawurlencode(CKM_QUIZ_PRO_VERSION)); ?>"></script>
    <script src="<?php echo esc_url(CKM_QUIZ_PRO_URL.'assets/standalone-voice.js?v='.rawurlencode(CKM_QUIZ_PRO_VERSION)); ?>"></script>
    </body></html><?php
}
