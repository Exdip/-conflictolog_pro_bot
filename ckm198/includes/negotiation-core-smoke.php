<?php
if (!defined('ABSPATH')) exit;

/**
 * Hidden administrator smoke page for Negotiation Core v1.
 *
 * URL: /wp-admin/admin.php?page=ckm-negotiation-core-smoke
 *
 * The page deliberately has no visible admin-menu entry. Visiting it as an
 * administrator opens a short-lived, per-user test-API window. The browser
 * then drives the real WordPress REST endpoints, performs one real page reload
 * to verify recovery, and finally removes its TEST-API-* persistence rows.
 */

const CKM_NEGOTIATION_CORE_SMOKE_VERSION = 'negotiation_core_smoke_v1';
const CKM_NEGOTIATION_CORE_SMOKE_SLUG = 'ckm-negotiation-core-smoke';

function ckm_negotiation_core_smoke_register_page(): void {
    add_submenu_page(
        null,
        'Negotiation Core Smoke',
        'Negotiation Core Smoke',
        'manage_options',
        CKM_NEGOTIATION_CORE_SMOKE_SLUG,
        'ckm_negotiation_core_smoke_render_page'
    );
}
add_action('admin_menu', 'ckm_negotiation_core_smoke_register_page', 99);

function ckm_negotiation_core_smoke_open_api_window(): void {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) return;
    if (!function_exists('set_transient') || !function_exists('get_current_user_id')) return;
    $uid = (int) get_current_user_id();
    if ($uid <= 0) return;
    $ttl = defined('MINUTE_IN_SECONDS') ? 10 * MINUTE_IN_SECONDS : 600;
    set_transient('ckm_negotiation_smoke_api_' . $uid, 1, $ttl);
}

function ckm_negotiation_core_smoke_cleanup_ajax(): void {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        wp_send_json_error(['code' => 'forbidden'], 403);
    }
    check_ajax_referer('ckm_negotiation_core_smoke_cleanup', 'nonce');
    $sessionId = strtoupper(trim((string)($_POST['session_id'] ?? '')));
    $sessionId = preg_replace('/[^A-Z0-9_-]/', '', $sessionId) ?? '';
    if (strpos($sessionId, 'TEST-API-') !== 0) {
        wp_send_json_error(['code' => 'test_session_required'], 400);
    }
    $repo = new CKM_Negotiation_Repository();
    $ok = $repo->delete_test_session($sessionId);
    if (!$ok) wp_send_json_error(['code' => 'cleanup_refused'], 400);
    wp_send_json_success(['session_id' => $sessionId]);
}
add_action('wp_ajax_ckm_negotiation_core_smoke_cleanup', 'ckm_negotiation_core_smoke_cleanup_ajax');

function ckm_negotiation_core_smoke_render_page(): void {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        wp_die(esc_html__('Недостаточно прав.', 'ckm-quiz-pro'));
    }

    ckm_negotiation_core_smoke_open_api_window();

    $config = [
        'restBase' => trailingslashit(rest_url(CKM_NEGOTIATION_TEST_API_NAMESPACE)),
        'restNonce' => wp_create_nonce('wp_rest'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cleanupNonce' => wp_create_nonce('ckm_negotiation_core_smoke_cleanup'),
        'storageKey' => 'ckmNegotiationCoreSmokeV2-' . CKM_QUIZ_PRO_VERSION,
    ];
    ?>
    <div class="wrap" id="ckm-negotiation-core-smoke">
        <h1>Negotiation Core Smoke</h1>
        <p>Закрытый тест администратора. Он работает только с <code>TEST-API-*</code>, не подключает реальные игры и использует настоящий REST API WordPress.</p>
        <p><strong>Во время проверки страница один раз автоматически перезагрузится.</strong> Это часть теста восстановления после F5.</p>
        <p>
            <button type="button" class="button button-primary" id="ckm-smoke-restart">Запустить заново</button>
            <span id="ckm-smoke-status" style="margin-left:10px">Подготовка…</span>
        </p>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th style="width:45%">Проверка</th><th style="width:90px">Результат</th><th>Детали</th></tr></thead>
            <tbody id="ckm-smoke-results"></tbody>
        </table>
        <h2 id="ckm-smoke-summary" style="margin-top:18px"></h2>
        <details style="max-width:1100px;margin-top:16px">
            <summary>Технический журнал</summary>
            <pre id="ckm-smoke-log" style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ccd0d4;max-height:360px;overflow:auto"></pre>
        </details>
    </div>
    <script>
    (() => {
        'use strict';
        const CFG = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const tbody = document.getElementById('ckm-smoke-results');
        const statusEl = document.getElementById('ckm-smoke-status');
        const summaryEl = document.getElementById('ckm-smoke-summary');
        const logEl = document.getElementById('ckm-smoke-log');
        const restart = document.getElementById('ckm-smoke-restart');

        const freshRun = () => ({
            stage: 'start',
            sessionId: '',
            checks: [],
            log: [],
            checkpoint: null,
            startedAt: new Date().toISOString()
        });

        let run = loadRun() || freshRun();
        let running = false;

        function loadRun() {
            try {
                const raw = sessionStorage.getItem(CFG.storageKey);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : null;
            } catch (e) { return null; }
        }
        function saveRun() {
            try { sessionStorage.setItem(CFG.storageKey, JSON.stringify(run)); } catch (e) {}
        }
        function clearRun() {
            try { sessionStorage.removeItem(CFG.storageKey); } catch (e) {}
        }
        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }
        function render() {
            tbody.innerHTML = (run.checks || []).map(c =>
                `<tr><td>${esc(c.name)}</td><td><strong style="color:${c.ok ? '#008a20' : '#b32d2e'}">${c.ok ? 'PASS' : 'FAIL'}</strong></td><td>${esc(c.detail || '')}</td></tr>`
            ).join('');
            logEl.textContent = (run.log || []).join('\n');
            const passed = (run.checks || []).filter(c => c.ok).length;
            const total = (run.checks || []).length;
            summaryEl.textContent = total ? `Итог: ${passed}/${total} PASS` : '';
            summaryEl.style.color = total && passed !== total ? '#b32d2e' : '#008a20';
        }
        function check(name, ok, detail='') {
            run.checks.push({name, ok: !!ok, detail});
            saveRun(); render();
            if (!ok) throw new Error(`${name}: ${detail || 'FAIL'}`);
        }
        function log(message, data) {
            const tail = data === undefined ? '' : ' ' + JSON.stringify(data);
            run.log.push(`[${new Date().toISOString()}] ${message}${tail}`);
            saveRun(); render();
        }
        function stateOf(result) { return result && result.data && result.data.state ? result.data.state : {}; }
        function codeOf(result) { return result && result.data ? String(result.data.code || '') : ''; }
        function requestId(prefix) {
            const rnd = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
            return `${prefix}-${rnd}`;
        }
        async function api(path, method='POST', payload=null) {
            const opts = {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'X-WP-Nonce': CFG.restNonce, 'Accept': 'application/json'}
            };
            let url = CFG.restBase + path.replace(/^\//,'');
            if (method === 'GET') {
                const qs = new URLSearchParams(payload || {});
                if ([...qs].length) url += '?' + qs.toString();
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(payload || {});
            }
            const response = await fetch(url, opts);
            let data = {};
            try { data = await response.json(); } catch (e) { data = {ok:false, code:'invalid_json'}; }
            log(`${method} ${path} → HTTP ${response.status}`, {code:data.code || '', message:data.message || '', version:data.state_version || (data.state||{}).state_version || 0});
            return {status: response.status, data};
        }
        async function cleanup() {
            if (!run.sessionId) return false;
            const body = new URLSearchParams({
                action: 'ckm_negotiation_core_smoke_cleanup',
                nonce: CFG.cleanupNonce,
                session_id: run.sessionId
            });
            const response = await fetch(CFG.ajaxUrl, {
                method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            });
            const data = await response.json().catch(() => ({}));
            return response.ok && !!data.success;
        }
        function makeSessionId() {
            const suffix = `${Date.now()}-${Math.random().toString(36).slice(2,8)}`.toUpperCase().replace(/[^A-Z0-9-]/g,'');
            return `TEST-API-SMOKE-${suffix}`.slice(0,64);
        }

        async function firstHalf() {
            run.sessionId = makeSessionId();
            saveRun();
            const cases = [
                {id:'smoke-one', title:'Скидка', situation:'Клиент требует значительную скидку.', opponent_message:'Почему я должен покупать у вас дороже?', task:'Ответьте коротко и по делу.', duration_seconds:120, max_score:10},
                {id:'smoke-two', title:'Следующий шаг', situation:'Клиент готов продолжить обсуждение.', opponent_message:'Что вы предлагаете делать дальше?', task:'Зафиксируйте конкретный следующий шаг.', duration_seconds:120, max_score:10}
            ];

            const started = await api('negotiation-test/start','POST',{
                session_id:run.sessionId, runtime:'negotiation_express_v1', cases,
                player_role:'Команда', opponent_role:'Клиент'
            });
            const s1 = stateOf(started);
            check('REST start создаёт тестовую сессию', started.status === 200 && started.data.ok === true, `HTTP ${started.status}, code=${codeOf(started) || '-'}, message=${started.data?.message || '-'}`);
            check('Начальная фаза = host_speaking', s1.phase === 'host_speaking' && Number(s1.state_version) === 1, `phase=${s1.phase}, version=${s1.state_version}`);
            check('Таймер до окончания голоса не запущен', !s1.timer?.started_at && !s1.timer?.deadline_at, `deadline=${s1.timer?.deadline_at || 'нет'}`);

            const premature = await api('negotiation-test/submit','POST',{
                session_id:run.sessionId, expected_version:1, client_request_id:requestId('premature'),
                text:'Слишком ранний ответ', input_mode:'text'
            });
            check('Ответ до Voice → Timer Gate блокируется', premature.status === 422 && codeOf(premature) === 'wrong_phase', `HTTP ${premature.status}, code=${codeOf(premature)}`);

            const voiceReq = requestId('voice-1');
            const voice = await api('negotiation-test/voice-finished','POST',{
                session_id:run.sessionId, expected_version:1, client_request_id:voiceReq,
                message_id:s1.voice?.message_id || '', voice_generation:Number(s1.voice?.generation || 0)
            });
            const s2 = stateOf(voice);
            check('voice-finished открывает окно ответа', voice.status === 200 && s2.phase === 'answer_window' && Number(s2.state_version) === 2, `phase=${s2.phase}, version=${s2.state_version}`);
            check('Таймер стартует только после voice-finished', !!s2.timer?.started_at && !!s2.timer?.deadline_at, `deadline=${s2.timer?.deadline_at || 'нет'}`);

            const voiceReplay = await api('negotiation-test/voice-finished','POST',{
                session_id:run.sessionId, expected_version:1, client_request_id:voiceReq,
                message_id:s1.voice?.message_id || '', voice_generation:Number(s1.voice?.generation || 0)
            });
            const s2r = stateOf(voiceReplay);
            check('Повтор voice-finished идемпотентен', voiceReplay.status === 200 && Number(s2r.state_version) === 2 && s2r.timer?.deadline_at === s2.timer?.deadline_at, `version=${s2r.state_version}`);

            run.stage = 'after-f5';
            run.checkpoint = {version:Number(s2.state_version), phase:s2.phase, deadline:s2.timer?.deadline_at || ''};
            saveRun();
            statusEl.textContent = 'Проверка F5: автоматическая перезагрузка…';
            log('CHECKPOINT: выполняется реальная перезагрузка страницы для проверки restore_session');
            setTimeout(() => window.location.reload(), 250);
        }

        async function secondHalf() {
            const restored = await api('negotiation-test/state','GET',{session_id:run.sessionId});
            const s2 = stateOf(restored);
            const cp = run.checkpoint || {};
            check('F5: state восстанавливается через REST', restored.status === 200 && restored.data.ok === true, `HTTP ${restored.status}`);
            check('F5 сохраняет phase/state_version/deadline', Number(s2.state_version) === Number(cp.version) && s2.phase === cp.phase && (s2.timer?.deadline_at || '') === (cp.deadline || ''), `phase=${s2.phase}, version=${s2.state_version}`);

            const submitReq = requestId('submit-1');
            const submit = await api('negotiation-test/submit','POST',{
                session_id:run.sessionId, expected_version:Number(s2.state_version), client_request_id:submitReq,
                text:'Предлагаю сравнить полный пакет условий, а скидку связать с объёмом закупки.', input_mode:'voice'
            });
            const s3 = stateOf(submit);
            check('Первый ответ принимается и блокируется', submit.status === 200 && s3.phase === 'evaluating' && s3.answer?.locked === true && Number(s3.state_version) === 3, `phase=${s3.phase}, version=${s3.state_version}`);

            const submitReplay = await api('negotiation-test/submit','POST',{
                session_id:run.sessionId, expected_version:Number(s2.state_version), client_request_id:submitReq,
                text:'Этот текст не должен записаться второй раз.', input_mode:'text'
            });
            const s3r = stateOf(submitReplay);
            check('Повтор submit с тем же request_id не дублирует ход', submitReplay.status === 200 && Number(s3r.state_version) === 3 && s3r.answer?.text === s3.answer?.text, `version=${s3r.state_version}`);

            const eval1 = await api('negotiation-test/evaluate','POST',{
                session_id:run.sessionId, expected_version:3, client_request_id:requestId('eval-1'),
                mock_evaluation:{total_score:8, feedback:'Smoke evaluation 1', provider:'mock'}
            });
            const s4 = stateOf(eval1);
            check('Первый ход оценивается', eval1.status === 200 && s4.phase === 'feedback' && Number(s4.score?.total) === 8 && Number(s4.state_version) === 4, `score=${s4.score?.total}, version=${s4.state_version}`);

            const staleNext = await api('negotiation-test/next','POST',{
                session_id:run.sessionId, expected_version:3, client_request_id:requestId('next-stale')
            });
            check('Устаревший expected_version даёт state_conflict', staleNext.status === 409 && codeOf(staleNext) === 'state_conflict', `HTTP ${staleNext.status}, code=${codeOf(staleNext)}`);

            const nextReq = requestId('next-1');
            const next = await api('negotiation-test/next','POST',{
                session_id:run.sessionId, expected_version:4, client_request_id:nextReq
            });
            const s5 = stateOf(next);
            check('next переводит ровно ко второму кейсу', next.status === 200 && s5.case?.id === 'smoke-two' && Number(s5.state_version) === 5, `case=${s5.case?.id}, version=${s5.state_version}`);

            const nextReplay = await api('negotiation-test/next','POST',{
                session_id:run.sessionId, expected_version:4, client_request_id:nextReq
            });
            const s5r = stateOf(nextReplay);
            check('Повтор next не перескакивает кейс', nextReplay.status === 200 && s5r.case?.id === 'smoke-two' && Number(s5r.state_version) === 5, `case=${s5r.case?.id}, version=${s5r.state_version}`);

            const voice2 = await api('negotiation-test/voice-finished','POST',{
                session_id:run.sessionId, expected_version:5, client_request_id:requestId('voice-2'),
                message_id:s5.voice?.message_id || '', voice_generation:Number(s5.voice?.generation || 0)
            });
            const s6 = stateOf(voice2);
            check('Второй Voice → Timer Gate открывается', voice2.status === 200 && s6.phase === 'answer_window' && Number(s6.state_version) === 6, `version=${s6.state_version}`);

            const submit2 = await api('negotiation-test/submit','POST',{
                session_id:run.sessionId, expected_version:6, client_request_id:requestId('submit-2'),
                text:'Предлагаю сегодня согласовать пилот, а завтра зафиксировать критерии результата.', input_mode:'text'
            });
            const s7 = stateOf(submit2);
            check('Второй ответ принимается', submit2.status === 200 && s7.phase === 'evaluating' && Number(s7.state_version) === 7, `version=${s7.state_version}`);

            const eval2 = await api('negotiation-test/evaluate','POST',{
                session_id:run.sessionId, expected_version:7, client_request_id:requestId('eval-2'),
                mock_evaluation:{total_score:9, feedback:'Smoke evaluation 2', provider:'mock'}
            });
            const s8 = stateOf(eval2);
            check('После последней оценки доступен finish', eval2.status === 200 && s8.phase === 'feedback' && Array.isArray(s8.available_actions) && s8.available_actions.includes('finish_session') && Number(s8.state_version) === 8, `actions=${(s8.available_actions||[]).join(',')}`);

            const finish = await api('negotiation-test/finish','POST',{
                session_id:run.sessionId, expected_version:8, client_request_id:requestId('finish')
            });
            const s9 = stateOf(finish);
            check('finish завершает сессию', finish.status === 200 && s9.status === 'finished' && s9.is_finished === true && Number(s9.state_version) === 9, `status=${s9.status}, version=${s9.state_version}`);
            check('Итоговый счёт сохраняется', Number(s9.result?.total_score) === 17 && Number(s9.result?.completed_cases) === 2, `score=${s9.result?.total_score}, cases=${s9.result?.completed_cases}`);

            const finalState = await api('negotiation-test/state','GET',{session_id:run.sessionId});
            const sf = stateOf(finalState);
            check('Финальное состояние читается после завершения', finalState.status === 200 && sf.status === 'finished' && Number(sf.result?.total_score) === 17, `status=${sf.status}, score=${sf.result?.total_score}`);

            const afterFinish = await api('negotiation-test/submit','POST',{
                session_id:run.sessionId, expected_version:9, client_request_id:requestId('after-finish'),
                text:'Недопустимый ответ после завершения.'
            });
            check('После finish новые ответы блокируются', afterFinish.status === 409 && codeOf(afterFinish) === 'session_finished', `HTTP ${afterFinish.status}, code=${codeOf(afterFinish)}`);

            const cleaned = await cleanup();
            check('Тестовые записи удаляются после smoke', cleaned, run.sessionId);
            run.stage = 'done';
            saveRun();
            statusEl.textContent = 'Smoke-test завершён.';
            const pass = run.checks.filter(c => c.ok).length;
            const total = run.checks.length;
            summaryEl.textContent = `Итог: ${pass}/${total} PASS`;
            clearRun();
        }

        async function runSmoke() {
            if (running) return;
            running = true;
            statusEl.textContent = 'Smoke-test выполняется…';
            render();
            try {
                if (run.stage === 'after-f5') await secondHalf();
                else await firstHalf();
            } catch (error) {
                log('ERROR', {message:error && error.message ? error.message : String(error)});
                run.stage = 'failed';
                statusEl.textContent = 'Smoke-test остановлен на ошибке.';
                summaryEl.style.color = '#b32d2e';
                try {
                    const cleaned = await cleanup();
                    if (run.sessionId) run.log.push(`cleanup after failure: ${cleaned ? 'OK' : 'FAILED'}`);
                } catch (e) {}
                saveRun(); render();
            } finally {
                running = false;
            }
        }

        restart.addEventListener('click', async () => {
            try { if (run.sessionId) await cleanup(); } catch (e) {}
            clearRun();
            window.location.reload();
        });

        render();
        if (run.stage === 'failed') {
            statusEl.textContent = 'Smoke-test остановлен на ошибке. Нажмите «Запустить заново». ';
            summaryEl.style.color = '#b32d2e';
        } else {
            runSmoke();
        }
    })();
    </script>
    <?php
}
