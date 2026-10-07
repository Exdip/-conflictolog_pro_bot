(function () {
  'use strict';

  const UI = window.CKMQuizUI;
  if (!UI) return;
  UI.initTheme();

  const params = UI.query();
  const game = String(params.get('game') || '').toUpperCase();
  const team = String(params.get('team') || '').toUpperCase();
  const initialTeamName = team ? ('Команда ' + team) : 'Команда';
  UI.text('roomTeamBadge', initialTeamName);
  UI.text('roomTeamIdentityName', initialTeamName);
  const storagePrefix = 'ckm.quiz.participant.' + game + '.' + team + '.';
  const testPreview = params.get('test_preview') === '1' || sessionStorage.getItem(storagePrefix + 'testPreview') === '1';
  if (testPreview) sessionStorage.setItem(storagePrefix + 'testPreview', '1');
  const teamToken = UI.sessionSecret(storagePrefix + 'teamToken', ['token', 'team_token', 'teamToken']);
  const joinNonce = UI.sessionSecret(storagePrefix + 'joinNonce', ['nonce']);
  UI.scrubSecrets(['token', 'team_token', 'teamToken', 'nonce']);

  let participantNonce = sessionStorage.getItem(storagePrefix + 'participantNonce') || '';
  let participantSessionToken = sessionStorage.getItem(storagePrefix + 'participantSessionToken') || '';
  let poller = null;
  let state = null;
  let selectedValues = [];
  let renderedQuestionId = 0;
  let draftQuestionId = 0;
  let draftVersion = 0;
  let draftDirty = false;
  let draftSaving = false;
  let draftSaveTimer = null;
  let readySaving = false;
  let jeopardySelectionSaving = false;
  let jeopardyCatSaving = false;
  let jeopardyGuideInitialized = false;
  const events = [];

  const clock = new UI.ServerClock(function (seconds) {
    UI.text('roomTimer', UI.formatSeconds(seconds));
    const wrap = document.getElementById('roomTimerWrap');
    if (wrap) wrap.classList.toggle('is-low', seconds > 0 && seconds <= 10);
    updateAnswerAvailability();
  });

  function status(message, kind) {
    const node = document.getElementById('roomAnswerStatus');
    if (!node) return;
    node.textContent = message || '';
    node.classList.remove('is-success', 'is-error');
    if (kind === 'success') node.classList.add('is-success');
    if (kind === 'error') node.classList.add('is-error');
  }

  function teamLabel(teamState) {
    if (!teamState) return 'Команда';
    return teamState.name + ' · ' + teamState.score + ' балл.';
  }

  function storedTeamRoomUrl(teamKey) {
    const key = String(teamKey || '').toUpperCase();
    if (!key) return '';
    const prefix = 'ckm.quiz.participant.' + game + '.' + key + '.';
    const token = sessionStorage.getItem(prefix + 'teamToken') || '';
    const nonce = sessionStorage.getItem(prefix + 'joinNonce') || '';
    const participant = sessionStorage.getItem(prefix + 'participantNonce') || '';
    const participantSession = sessionStorage.getItem(prefix + 'participantSessionToken') || '';
    if ((!token || !nonce) && !participant && !participantSession) return '';
    const url = new URL('quiz-room.html', window.location.href);
    url.searchParams.set('game', game);
    url.searchParams.set('team', key);
    if (testPreview) url.searchParams.set('test_preview', '1');
    return url.pathname + url.search;
  }


  function showMembershipConflict(error) {
    const payload = error && error.payload && typeof error.payload === 'object' ? error.payload : {};
    const own = payload.existingTeam && typeof payload.existingTeam === 'object' ? payload.existingTeam : null;
    const requested = payload.requestedTeam && typeof payload.requestedTeam === 'object' ? payload.requestedTeam : null;
    const ownName = own && own.name ? String(own.name) : 'другой команде';
    const requestedName = requested && requested.name ? String(requested.name) : team;
    UI.setConnection('warning', 'Вы уже в другой команде');
    UI.text('roomPhase', 'Участник уже зарегистрирован');
    UI.text('roomTitle', 'Вы уже состоите в команде «' + ownName + '»');
    UI.text('roomSubtitle', 'Эта ссылка относится к команде «' + requestedName + '». Одна учётная запись может состоять только в одной команде одной игры.');
    UI.text('roomMembershipConflictText', 'Ваше текущее членство сохранено: команда «' + ownName + '». Никакие данные и ответы команды не изменены.');
    UI.show('roomMembershipConflict', true);
    const link = document.getElementById('roomOwnTeamLink');
    const href = storedTeamRoomUrl(own && own.key ? own.key : '');
    if (link) {
      link.hidden = !href;
      if (href) link.href = href;
    }
    const submit = document.getElementById('roomSubmit');
    const textarea = document.getElementById('roomAnswerText');
    if (submit) submit.disabled = true;
    if (textarea) textarea.disabled = true;
    UI.text('roomAnswerHelp', 'Чтобы отвечать за эту команду, откройте ссылку под другой учётной записью.');
    status('Текущее членство сохранено.', 'success');
  }

  function renderLiveHost() {
    const panel = document.getElementById('roomLiveHost');
    if (!panel) return;
    const url = String(state && state.liveHost && state.liveHost.url || '');
    const human = !!(state && state.game && state.game.hostMode === 'human' && url);
    panel.hidden = !human;
    const link = document.getElementById('roomLiveHostLink');
    const button = document.getElementById('roomJoinLiveHost');
    if (link) {
      if (human) link.href = url; else link.removeAttribute('href');
    }
    if (button) button.disabled = !human;
    if (!human) {
      const frame = document.getElementById('roomLiveHostFrame');
      if (frame) frame.innerHTML = '';
    }
  }

  function openLiveHost() {
    const url = String(state && state.liveHost && state.liveHost.url || '');
    if (!url) return;
    const frame = document.getElementById('roomLiveHostFrame');
    if (!frame) return;
    const joinUrl = url + '#config.prejoinPageEnabled=false&config.startWithAudioMuted=true&config.startWithVideoMuted=true&config.disableDeepLinking=true';
    frame.innerHTML = '';
    const iframe = document.createElement('iframe');
    iframe.src = joinUrl;
    iframe.setAttribute('allow', 'camera; microphone; fullscreen; display-capture; autoplay');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    iframe.setAttribute('title', 'Живой ведущий');
    frame.appendChild(iframe);
    const button = document.getElementById('roomJoinLiveHost');
    if (button) { button.textContent = 'Видео и голос подключены'; button.disabled = true; }
  }

  function findOwnTeam() {
    if (!state || !state.you || !Array.isArray(state.teams)) return null;
    return state.teams.find(function (x) { return Number(x.id) === Number(state.you.teamId); }) || null;
  }

  function isChgk() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'chgk');
  }

  function isJeopardy() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'jeopardy');
  }

  function isNegotiation() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'negotiation_duel');
  }

  function negotiationMode() {
    const mode = String(state && state.formatRuntime && state.formatRuntime.negotiationMode || 'sales');
    return ['sales','business','express'].indexOf(mode) !== -1 ? mode : 'sales';
  }

  function negotiationModeTitle() {
    const mode = negotiationMode();
    if (mode === 'business') return 'Мастер переговоров';
    if (mode === 'express') return 'Переговорный раунд';
    return 'Эффективный продажник';
  }

  function jeopardyHelpSeen() {
    try { return localStorage.getItem(storagePrefix + 'jeopardyHelpIntroSeen') === '1'; } catch (e) { return false; }
  }

  function markJeopardyHelpSeen() {
    try { localStorage.setItem(storagePrefix + 'jeopardyHelpIntroSeen', '1'); } catch (e) {}
  }

  function renderParticipantGuide() {
    const guide=document.getElementById('roomParticipantGuide');
    if (!guide) return;
    if (!isJeopardy()) return;
    guide.innerHTML='<summary>Правила за 30 секунд</summary>'+
      '<ol class="participant-guide__steps">'+
      '<li><strong>Выберите вопрос.</strong><span>В режиме ИИ команда с правом выбора сама нажимает категорию и номинал на доске.</span></li>'+
      '<li><strong>Дождитесь вопроса.</strong><span>После открытия ячейки вопрос и серверный таймер появятся автоматически.</span></li>'+
      '<li><strong>Нажмите «ОТВЕЧАЕМ!».</strong><span>Первое серверное нажатие получает право ответа. Для «Секретной передачи» кнопка не нужна.</span></li>'+
      '<li><strong>Отправьте ответ.</strong><span>Поле ответа открывается только команде, получившей право ответа.</span></li>'+
      '<li><strong>Получите баллы.</strong><span>Правильный ответ приносит номинал вопроса, неправильный — 0.</span></li>'+
      '<li><strong>Сыграйте финал.</strong><span>После основного поля — один закрытый финальный ответ от каждой команды; верный ответ даёт 500 баллов.</span></li>'+
      '</ol>'+
      '<p class="participant-guide__note"><strong>«Секретная передача»:</strong> специальный вопрос отвечает другая команда.</p>'+
      '<div class="participant-guide__actions"><button class="quiz-btn" type="button" id="roomOpenFullRules">Полные правила</button></div>';
    if (!jeopardyGuideInitialized) {
      guide.open=false;
      jeopardyGuideInitialized=true;
    }
  }

  function renderJeopardyHelp() {
    const active=isJeopardy();
    const intro=document.getElementById('roomJeopardyHelpIntro');
    const full=document.getElementById('roomJeopardyFullRules');
    const button=document.getElementById('roomRulesButton');
    if (button) button.hidden=!active;
    if (full) full.hidden=!active;
    if (!intro) return;
    if (!active) { intro.hidden=true; return; }
    const waiting=!!(state && state.game && state.game.status !== 'finished' && state.game.phase === 'waiting');
    intro.hidden=!(waiting && !jeopardyHelpSeen());
  }

  function openJeopardyThirtySecondRules() {
    markJeopardyHelpSeen();
    const intro=document.getElementById('roomJeopardyHelpIntro');
    if (intro) intro.hidden=true;
    const guide=document.getElementById('roomParticipantGuide');
    if (guide) { guide.open=true; guide.scrollIntoView({behavior:'smooth',block:'start'}); }
  }

  function openJeopardyFullRules() {
    markJeopardyHelpSeen();
    const intro=document.getElementById('roomJeopardyHelpIntro');
    if (intro) intro.hidden=true;
    const full=document.getElementById('roomJeopardyFullRules');
    if (full) { full.hidden=false; full.open=true; full.scrollIntoView({behavior:'smooth',block:'start'}); }
  }

  function jeopardyBuzzerState() {
    return state && state.formatRuntime && state.formatRuntime.buzzer ? state.formatRuntime.buzzer : { enabled:false, open:false, blockedTeamIds:[] };
  }

  function jeopardyWagerState() {
    return state && state.formatRuntime && state.formatRuntime.wager ? state.formatRuntime.wager : { enabled:false, min:1, max:5, rows:[] };
  }

  function jeopardyAiState() {
    return state && state.formatRuntime && state.formatRuntime.aiArbitration
      ? state.formatRuntime.aiArbitration
      : { enabled:false, mode:'human', configured:false, humanFallback:true, autoApply:false, last:null };
  }

  function renderJeopardyAi() {
    const wrap=document.getElementById('roomJeopardyAi');
    if (!wrap) return;
    const ai=jeopardyAiState();
    const visible=!!(isJeopardy() && ai.enabled);
    wrap.hidden=!visible;
    if (!visible) return;
    const board=state && state.jeopardyBoard ? state.jeopardyBoard : null;
    const pendingCellTransition=!!(board && Number(board.selectedQuestionId||0)>0 && (!state || !state.game || String(state.game.phase||'')!=='question_open'));
    const lastAi=pendingCellTransition ? null : ai.last;
    let status=ai.mode === 'ai' ? 'Автоарбитраж ИИ' : 'Гибридный арбитраж';
    let comment=ai.mode === 'ai' ? 'После ответа ИИ применяет уверенное решение автоматически.' : 'ИИ готовит рекомендацию, окончательное решение подтверждает ведущий.';
    if (!ai.configured) {
      status='Ручной fallback';
      comment='ИИ сейчас недоступен. Игра продолжается с решением ведущего.';
    } else if (lastAi) {
      const last=lastAi;
      if (last.status === 'failed') {
        status='Ручной fallback';
        comment='ИИ временно недоступен. Решение принимает ведущий.';
      } else if (last.status === 'pending_review') {
        status='Решение проверяет ведущий';
        comment='AI-рекомендация передана ведущему. Её содержание не раскрывается до решения.';
      } else if (last.status === 'applied') {
        status=String(last.decision || '') === 'accepted' ? 'Ответ принят ИИ' : 'Ответ отклонён ИИ';
        comment=String(last.comment || 'Решение применено сервером.');
      }
    }
    UI.text('roomJeopardyAiStatus',status);
    UI.text('roomJeopardyAiComment',comment);
  }

  function jeopardyAuctionState() {
    return state && state.formatRuntime && state.formatRuntime.auction ? state.formatRuntime.auction : { enabled:false };
  }

  function jeopardyFinalState() {
    return state && state.formatRuntime && state.formatRuntime.finalRound ? state.formatRuntime.finalRound : { enabled:false, started:false, rows:[] };
  }

  function ownFinalRow() {
    const finalState=jeopardyFinalState(), own=findOwnTeam(), ownId=Number(own && own.id || 0);
    return (Array.isArray(finalState.rows)?finalState.rows:[]).find(function(row){return Number(row.teamId||0)===ownId;}) || null;
  }

  function renderJeopardyFinal() {
    const wrap=document.getElementById('roomJeopardyFinal'); if (!wrap) return;
    const finalState=jeopardyFinalState();
    const visible=!!(isJeopardy() && finalState.enabled && finalState.started);
    wrap.hidden=!visible; if (!visible) return;
    const row=ownFinalRow();
    let text='Финальный вопрос открыт. Отправьте один окончательный ответ; ответы команд скрыты до общего раскрытия.';
    if (row && row.answerSubmitted && !finalState.revealed) text='Финальный ответ вашей команды зафиксирован. Ожидайте общего раскрытия.';
    if (finalState.revealed) text='Финальные ответы раскрыты. Ведущий оценивает полученные ответы.';
    else if (finalState.submissionClosed) text='Время приёма финальных ответов завершено. Ожидайте общего раскрытия.';
    UI.text('roomFinalStatus',text);
    UI.text('roomFinalHint','Верный финальный ответ даёт '+Number(finalState.fixedPoints||500)+' баллов. Ставок нет.');
  }


  function renderJeopardyAuction() {
    const wrap=document.getElementById('roomJeopardyAuction');
    if (wrap) wrap.hidden=true;
  }

  function jeopardyCatState() {
    return state && state.formatRuntime && state.formatRuntime.catInBag ? state.formatRuntime.catInBag : { enabled:false };
  }

  function renderJeopardyCat() {
    const wrap=document.getElementById('roomJeopardyCat');
    if (!wrap) return;
    const cat=jeopardyCatState();
    const visible=!!(isJeopardy() && cat && cat.enabled);
    wrap.hidden=!visible;
    if (!visible) return;
    const source=(state.teams||[]).find(function(team){return Number(team.id||0)===Number(cat.sourceTeamId||0);});
    const target=(state.teams||[]).find(function(team){return Number(team.id||0)===Number(cat.targetTeamId||0);});
    const targets=document.getElementById('roomCatTargets');
    if (targets) targets.innerHTML='';
    if (String(cat.status||'')==='pending_target') {
      const own=findOwnTeam();
      const sourceOwn=!!(own && Number(own.id||0)===Number(cat.sourceTeamId||0));
      if (aiHostMode() && sourceOwn) {
        UI.text('roomCatStatus','Вы выбрали «Секретную передачу». Выберите команду-получателя.');
        if (targets) {
          (state.teams||[]).filter(function(t){return Number(t.id||0)!==Number(cat.sourceTeamId||0);}).forEach(function(t){
            const button=document.createElement('button');
            button.type='button';
            button.className='quiz-btn';
            button.textContent=String(t.name||'Команда');
            button.disabled=jeopardyCatSaving;
            button.addEventListener('click',function(){assignJeopardyCatTarget(Number(t.id||0));});
            targets.appendChild(button);
          });
        }
      } else {
        UI.text('roomCatStatus',aiHostMode()?'Команда, выбравшая секретную ячейку, назначает получателя.':'Выбрана секретная ячейка. Ведущий назначает другую команду.');
      }
    } else if (String(cat.status||'')==='assigned') {
      UI.text('roomCatStatus','Вопрос передан '+String(target && target.name || 'другой команде')+'. Отвечает только она; кнопка ответа не используется.');
    } else {
      UI.text('roomCatStatus','Спецвопрос завершён.');
    }
  }

  function renderJeopardyWager() {
    const wrap=document.getElementById('roomJeopardyWager');
    if (wrap) wrap.hidden=true;
  }

  async function placeJeopardyWager() {
    return;
  }

  function renderJeopardyBuzzer() {
    const wrap=document.getElementById('roomJeopardyBuzzer');
    const answerWrap=document.getElementById('roomAnswerBuzz');
    const buzzer=jeopardyBuzzerState();
    const active=isJeopardy() && !!buzzer.enabled;
    if (wrap) wrap.hidden=!active;
    const own=findOwnTeam();
    const ownId=Number(own && own.id || 0);
    const claim=buzzer.active && typeof buzzer.active==='object' ? buzzer.active : null;
    const blocked=(buzzer.blockedTeamIds || []).map(Number).indexOf(ownId)!==-1;
    const mine=!!(claim && Number(claim.teamId || 0)===ownId);
    const cat=jeopardyCatState();
    const auction={enabled:false};
    const finalRound=jeopardyFinalState();
    const ordinaryQuestion=active && !(cat && cat.enabled) && !(auction && auction.enabled) && !(finalRound && finalRound.started && state && state.question && String(state.question.stage||'')==='final');
    if (answerWrap) answerWrap.hidden=!ordinaryQuestion || mine;
    const buttons=['roomBuzzButton','roomAnswerBuzzButton'].map(function(id){ return document.getElementById(id); }).filter(Boolean);
    buttons.forEach(function(button){
      button.disabled=!buzzer.open || blocked || !!claim;
      button.textContent=mine ? 'ПРАВО ОТВЕТА ПОЛУЧЕНО' : 'ОТВЕЧАЕМ!';
    });
    let label='Кнопка свободна';
    if (blocked) label='Ваша команда уже отвечала';
    if (claim) { const team=(state.teams||[]).find(function(x){return Number(x.id||0)===Number(claim.teamId||0);}); label=(mine?'Право ответа у вашей команды':'Нажала '+(team?team.name:'другая команда')); }
    UI.text('roomBuzzStatus',label);
    UI.text('roomBuzzTimer',claim ? ('На ответ: '+UI.formatSeconds(Number(claim.secondsRemaining||0))) : 'Первое серверное нажатие получает право ответа.');
    UI.text('roomAnswerBuzzHint', blocked
      ? 'Ваша команда уже использовала право ответа на этот вопрос.'
      : (claim && !mine
        ? 'Право ответа уже зафиксировано другой командой.'
        : 'Нажмите «ОТВЕЧАЕМ!». Только победившей команде сервер откроет поле ответа.'));
  }

  async function claimJeopardyBuzzer() {
    const buttons=['roomBuzzButton','roomAnswerBuzzButton'].map(function(id){ return document.getElementById(id); }).filter(Boolean);
    try {
      buttons.forEach(function(button){ button.disabled=true; });
      const payload=await UI.postJson('api/quiz-buzz.php',{game:game,team:team,team_token:teamToken,nonce:participantNonce || joinNonce,last_event_id:poller?poller.lastEventId:0},teamToken,participantNonce || joinNonce,participantSessionToken);
      if (payload.state) renderState(payload.state);
      status('Право ответа зафиксировано сервером. Поле ответа открыто для вашей команды.','success');
      const textarea=document.getElementById('roomAnswerText');
      if (textarea && !textarea.disabled) textarea.focus();
      if (poller) poller.refreshNow();
    } catch(error) { status(error.message || 'Кнопку уже нажала другая команда.','error'); if (poller) poller.refreshNow(); }
  }

  function aiHostMode() {
    return !!(state && state.game && state.game.hostMode === 'ai');
  }

  async function selectJeopardyCell(questionId) {
    if (!questionId || jeopardySelectionSaving) return;
    jeopardySelectionSaving = true;
    try {
      status('Фиксируем выбор ячейки…','');
      const payload = await UI.postJson('api/quiz-jeopardy-team-action.php',{
        game:game,
        action:'select_cell',
        question_id:Number(questionId),
        nonce:participantNonce,
        last_event_id:poller?poller.lastEventId:0
      },teamToken,participantNonce,participantSessionToken);
      if (payload.state) renderState(payload.state);
      status(payload.specialPending ? 'Ячейка выбрана. Назначьте команду для «Секретной передачи».' : 'Ячейка выбрана. ИИ открывает вопрос.','success');
      if (poller) poller.refreshNow();
    } catch (error) {
      status(error.message || 'Не удалось выбрать ячейку.','error');
      if (poller) poller.refreshNow();
    } finally {
      jeopardySelectionSaving = false;
    }
  }

  async function assignJeopardyCatTarget(targetTeamId) {
    if (!targetTeamId || jeopardyCatSaving) return;
    jeopardyCatSaving = true;
    try {
      status('Передаём секретный вопрос…','');
      const payload = await UI.postJson('api/quiz-jeopardy-team-action.php',{
        game:game,
        action:'assign_cat',
        target_team_id:Number(targetTeamId),
        nonce:participantNonce,
        last_event_id:poller?poller.lastEventId:0
      },teamToken,participantNonce,participantSessionToken);
      if (payload.state) renderState(payload.state);
      status('Команда-получатель назначена. ИИ открывает вопрос.','success');
      if (poller) poller.refreshNow();
    } catch (error) {
      status(error.message || 'Не удалось передать секретный вопрос.','error');
      if (poller) poller.refreshNow();
    } finally {
      jeopardyCatSaving = false;
    }
  }

  function renderJeopardyBoard() {
    const card = document.getElementById('roomJeopardyCard');
    if (!card) return;
    const active = isJeopardy();
    card.hidden = !active;
    if (!active) return;
    const board = state && state.jeopardyBoard ? state.jeopardyBoard : { enabled: false };
    const selectorId = Number(board.selectorTeamId || 0);
    const selector = (state.teams || []).find(function (x) { return Number(x.id || 0) === selectorId; }) || null;
    const own = findOwnTeam();
    const ownSelects = !!(own && selectorId && Number(own.id || 0) === selectorId);
    UI.text('roomJeopardyStatus', selector ? ('Выбирает: ' + selector.name) : 'Право выбора не назначено');
    const teamCanSelect = aiHostMode() && ownSelects && !jeopardySelectionSaving;
    UI.text('roomJeopardyHint', ownSelects
      ? (aiHostMode()
        ? 'Сейчас выбирает ваша команда. Нажмите доступную категорию и номинал на игровом поле.'
        : 'Сейчас выбирает ваша команда. Назовите ведущему категорию и номинал; после выбора ячейка откроется автоматически.')
      : 'Следите за доступными категориями и номиналами. Выбранные и сыгранные ячейки блокируются сервером.');
    if (UI.renderJeopardyBoard) UI.renderJeopardyBoard('roomJeopardyBoard', board, {
      teams: state.teams || [],
      interactive: teamCanSelect,
      serverGuardedSelection: teamCanSelect,
      onSelect: teamCanSelect ? selectJeopardyCell : null,
      hint: ownSelects
        ? (aiHostMode() ? 'Нажмите доступный номинал. Сервер проверит право выбора.' : 'Право выбора у вашей команды.')
        : 'Доска обновляется автоматически.'
    });
  }

  function teamFlow() {
    const runtime = state && state.formatRuntime ? state.formatRuntime : {};
    const flow = runtime && runtime.teamFlow ? runtime.teamFlow : {};
    return {
      participationMode: String((state && state.you && state.you.participationMode) || flow.participationMode || ''),
      finalizationMode: String((state && state.you && state.you.finalizationMode) || flow.finalizationMode || ''),
      isCaptain: !!(state && state.you && state.you.isCaptain),
      canEditDraft: false,
      canFinalize: !!(state && state.you && state.you.canFinalize)
    };
  }

  function currentDraft() {
    return state && state.yourDraft ? state.yourDraft : null;
  }

  function renderLobby() {
    const panel = document.getElementById('roomChgkLobby');
    if (!panel) return;
    const lobby = state && state.lobby ? state.lobby : null;
    const visible = isChgk() && lobby && lobby.active && state && state.game && state.game.phase === 'waiting';
    panel.hidden = !visible;
    if (!visible) return;
    const own = findOwnTeam();
    const ready = !!(lobby.yourReady || (own && own.ready));
    UI.text('roomLobbyReadyCount', Number(lobby.readyCount || 0) + ' / ' + Number(lobby.teamCount || 0));
    UI.text('roomLobbySummary', lobby.requireAllTeamsReady
      ? 'Первый вопрос откроется только после подтверждения готовности всеми командами и прохождения server preflight.'
      : 'Комната ожидает старта ведущего. Подтверждение готовности отображается ведущему.');
    const flow = teamFlow();
    let authority = '';
    if (flow.participationMode === 'team_device') authority = 'Готовность подтверждает этот командный экран.';
    else authority = 'Готовность подтверждается с общего командного экрана.';
    UI.text('roomLobbyAuthority', authority);
    const badge = document.getElementById('roomLobbyBadge');
    if (badge) {
      badge.textContent = ready ? 'Команда готова' : 'Ожидание';
      badge.classList.toggle('quiz-badge--success', ready);
      badge.classList.toggle('quiz-badge--warning', !ready);
    }
    const button = document.getElementById('roomReadyToggle');
    if (button) {
      button.textContent = ready ? 'Снять готовность' : 'Мы готовы';
      button.disabled = readySaving || !lobby.canToggleReady;
    }
    UI.text('roomReadyStatus', ready ? (aiHostMode() ? 'Готовность подтверждена. ИИ-ведущий запустит игру автоматически после готовности всех команд.' : 'Готовность подтверждена. Ожидайте старта ведущего.') : (lobby.canToggleReady ? 'Подтвердите готовность, когда команда готова начать.' : (aiHostMode() ? 'Ожидайте готовности команды.' : 'Ожидайте подтверждения готовности команды.')));
  }

  async function toggleReady() {
    const lobby = state && state.lobby ? state.lobby : null;
    if (!lobby || !lobby.active || readySaving || !lobby.canToggleReady) return;
    try {
      readySaving = true;
      renderLobby();
      const payload = await UI.postJson('api/quiz-ready.php', {
        game: game,
        ready: lobby.yourReady ? 0 : 1,
        last_event_id: poller ? poller.lastEventId : 0
      }, teamToken, participantNonce, participantSessionToken);
      if (payload.state) renderState(payload.state);
      if (poller) poller.refreshNow();
    } catch (error) {
      const node = document.getElementById('roomReadyStatus');
      if (node) { node.textContent = error.message || 'Не удалось изменить готовность.'; node.classList.add('is-error'); }
    } finally {
      readySaving = false;
      renderLobby();
    }
  }

  function clearDraftTimer() { draftSaveTimer = null; }


  function setDraftStatus(message, kind) { const node=document.getElementById('roomDraftStatus'); if(node){node.textContent='';node.hidden=true;} }


  function draftValues() {
    return {
      answer: String(document.getElementById('roomAnswerText')?.value || '').trim(),
      argumentation: String(document.getElementById('roomArgumentation')?.value || '').trim()
    };
  }

  function syncDraftFromState(force) { return; }


  async function saveDraftNow() { return false; }


  function scheduleDraftSave() { return; }


  function verdictLabel(verdict) {
    const value = String(verdict || '').toLowerCase();
    if (value === 'correct') return 'Ответ принят';
    if (value === 'incorrect') return 'Ответ не принят';
    if (value === 'partial') return 'Частично принят';
    if (value === 'accepted') return 'Ответ принят';
    if (value === 'rejected') return 'Ответ не принят';
    return value && value !== 'pending' ? 'Решение сохранено' : 'Ожидает решения';
  }

  function judgeLabel(mode) {
    const value = String(mode || '').toLowerCase();
    if (value === 'ai') return 'ИИ';
    if (value === 'human') return 'Человек-арбитр';
    if (value === 'automatic') return 'Автоматическая проверка';
    return value || '—';
  }

  function latestHostMessageEvent() {
    for (let i = events.length - 1; i >= 0; i -= 1) {
      const event = events[i];
      if (!event || (event.action !== 'ai_host_message' && event.action !== 'human_host_message')) continue;
      const message = UI.eventMessage(event);
      if (message) return { event:event, message:message };
    }
    return null;
  }

  function latestAiHostMessage() {
    const item = latestHostMessageEvent();
    return item ? item.message : '';
  }

  function renderChgkHost() {
    const panel = document.getElementById('roomChgkHostCard');
    if (!panel) return;
    const board=state && state.jeopardyBoard ? state.jeopardyBoard : null;
    const pendingCellTransition=!!(isJeopardy() && board && Number(board.selectedQuestionId||0)>0 && (!state || !state.game || String(state.game.phase||'')!=='question_open'));
    const latest = !pendingCellTransition && (isChgk() || isJeopardy()) ? latestHostMessageEvent() : null;
    const message = latest ? latest.message : '';
    panel.hidden = !message;
    if (message) {
      UI.text('roomChgkHostMessage', message);
      UI.text('roomChgkHostBadge', latest.event && latest.event.action === 'ai_host_message' ? 'ИИ' : 'Ведущий');
      const kicker = panel.querySelector('.quiz-kicker');
      if (kicker) kicker.textContent = latest.event && latest.event.action === 'ai_host_message' ? 'ИИ-ведущий' : 'Голосовой ведущий';
    }
  }

  function renderChgkArbitration() {
    const panel = document.getElementById('roomChgkArbitration');
    const result = document.getElementById('roomChgkResult');
    const comment = document.getElementById('roomChgkJudgeComment');
    const badge = document.getElementById('roomChgkArbitrationBadge');
    if (!panel || !result || !comment || !badge) return;
    panel.hidden = !isChgk();
    if (!isChgk()) return;

    badge.classList.remove('quiz-badge--success', 'quiz-badge--warning', 'quiz-badge--danger');
    const answer = state && state.yourAnswer ? state.yourAnswer : null;
    const phase = state && state.game ? String(state.game.phase || '') : '';

    if (!answer) {
      result.hidden = true;
      comment.hidden = true;
      badge.classList.add('quiz-badge--warning');
      badge.textContent = phase === 'question_open' ? 'Обсуждение' : 'Нет ответа';
      UI.text('roomChgkArbitrationTitle', phase === 'question_open' ? 'Сначала зафиксируйте ответ' : 'Ответ не зафиксирован');
      UI.text('roomChgkArbitrationSummary', phase === 'question_open'
        ? 'После фиксации ответ останется неизменным и будет передан арбитру после окончания обсуждения.'
        : 'У команды нет финального ответа на текущий вопрос.');
      return;
    }

    const verdict = String(answer.verdict || 'pending');
    if (verdict === 'pending') {
      result.hidden = true;
      comment.hidden = true;
      badge.classList.add('quiz-badge--warning');
      if (phase === 'question_open') {
        badge.textContent = 'Ответ зафиксирован';
        UI.text('roomChgkArbitrationTitle', 'Финальный ответ принят системой');
        UI.text('roomChgkArbitrationSummary', 'Изменить ответ уже нельзя. Дождитесь окончания обсуждения — затем он будет передан арбитру.');
      } else {
        badge.textContent = 'Арбитраж';
        UI.text('roomChgkArbitrationTitle', 'Ожидаем решение арбитра');
        UI.text('roomChgkArbitrationSummary', 'Время обсуждения завершено. Финальный ответ передан на оценку.');
      }
      return;
    }

    badge.classList.add(verdict === 'incorrect' || verdict === 'rejected' ? 'quiz-badge--danger' : 'quiz-badge--success');
    badge.textContent = 'Решение получено';
    UI.text('roomChgkArbitrationTitle', 'Решение арбитра');
    UI.text('roomChgkArbitrationSummary', 'Оценка сохранена в общем журнале счёта игры.');
    UI.text('roomChgkVerdict', verdictLabel(verdict));
    UI.text('roomChgkPoints', String(Number(answer.awardedPoints) || 0));
    UI.text('roomChgkJudge', judgeLabel(answer.judgeMode));
    result.hidden = false;
    const judgeComment = String(answer.judgeComment || '').trim();
    comment.textContent = judgeComment;
    comment.hidden = !judgeComment;
  }

  function renderChgkAppeal() {
    const panel = document.getElementById('roomChgkAppeal');
    const form = document.getElementById('roomAppealForm');
    const badge = document.getElementById('roomAppealBadge');
    if (!panel || !form || !badge || !state) return;
    const qid = state.question ? Number(state.question.id || 0) : 0;
    const answer = state.yourAnswer || null;
    const windowState = state.appealWindow || {};
    const appeals = Array.isArray(state.yourAppeals) ? state.yourAppeals : [];
    const own = appeals.find(function (a) { return Number(a.questionId || 0) === qid; }) || null;
    const eligible = isChgk() && answer && String(answer.verdict || 'pending') !== 'pending' && state.questionFlow && state.questionFlow.phase === 'review' && (!state.you || !!state.you.canFinalize);
    panel.hidden = !eligible && !own;
    if (panel.hidden) return;
    if (own) {
      form.hidden = true;
      badge.textContent = ({submitted:'Подана',reviewing:'Проверяется',accepted:'Принята',rejected:'Отклонена'})[String(own.status || '')] || 'Подана';
      UI.text('roomAppealHint', own.resolutionText || own.appealText || 'Апелляция зарегистрирована.');
      return;
    }
    const seconds = Number(windowState.secondsRemaining || 0);
    badge.textContent = windowState.open ? ('Открыто · ' + seconds + ' сек.') : 'Закрыто';
    form.hidden = !windowState.open;
    UI.text('roomAppealHint', windowState.open ? 'Команда может один раз попросить пересмотреть решение с общего командного экрана.' : 'Окно апелляции закрыто.');
  }

  function renderFormatUi() {
    const chgk = isChgk();
    const chgkPhase = chgk ? String(state && state.formatRuntime && state.formatRuntime.phase || 'discussion') : '';
    const chgkFinalOpen = !chgk || chgkPhase === 'final_answer_open';
    const negotiation = isNegotiation();
    const shell = document.getElementById('roomShell');
    if (shell) {
      shell.classList.toggle('quiz-room--chgk', chgk);
      shell.classList.toggle('quiz-room--negotiation', negotiation);
    }
    document.querySelectorAll('.quiz-chgk-only').forEach(function (node) { node.hidden = !chgk; });
    UI.text('roomTimerLabel', chgk ? 'Обсуждение' : (negotiation ? 'На реплику' : 'Осталось'));
    const textarea = document.getElementById('roomAnswerText');
    if (textarea) textarea.placeholder = chgk ? 'Окончательный ответ команды' : (negotiation ? 'Напишите следующую реплику так, как сказали бы её в реальных переговорах' : 'Введите ответ команды');
    if (chgk) {
      UI.text('roomChgkMode', 'Одна команда · общий командный экран');
      UI.text('roomChgkRole', 'Обсуждайте вместе и зафиксируйте один окончательный ответ команды. Отдельных ролей внутри команды нет.');
    }
    renderChgkHost();
    renderChgkArbitration();
    renderChgkAppeal();
    renderNegotiationReview();
  }

  function renderNegotiationReview() {
    const panel = document.getElementById('roomNegotiationReview');
    if (!panel) return;
    const negotiation = isNegotiation();
    panel.hidden = !negotiation;
    if (!negotiation) return;
    const answer = state && state.yourAnswer ? state.yourAnswer : null;
    const badge = document.getElementById('roomNegotiationBadge');
    const result = document.getElementById('roomNegotiationResult');
    const comment = document.getElementById('roomNegotiationComment');
    UI.text('roomNegotiationMode', negotiationModeTitle());
    if (!answer) {
      if (badge) { badge.textContent = state && state.game && state.game.phase === 'question_open' ? 'Ваш ход' : 'Ожидание'; badge.className='quiz-badge quiz-badge--warning'; }
      if (result) result.hidden = true;
      if (comment) comment.hidden = true;
      UI.text('roomNegotiationSummary', state && state.game && state.game.phase === 'question_open' ? 'Сформулируйте следующую рабочую реплику. ИИ оценивает переговорный ход, а не литературный стиль.' : 'Разбор появится после вашей реплики и закрытия раунда.');
      return;
    }
    const verdict = String(answer.verdict || 'pending');
    if (verdict === 'pending') {
      if (badge) { badge.textContent='На оценке'; badge.className='quiz-badge quiz-badge--warning'; }
      if (result) result.hidden = true;
      if (comment) comment.hidden = true;
      UI.text('roomNegotiationSummary','Реплика принята. После завершения раунда ИИ-арбитр даст баллы и короткий разбор.');
      return;
    }
    if (badge) { badge.textContent='Разбор готов'; badge.className='quiz-badge ' + ((verdict==='rejected'||verdict==='incorrect') ? 'quiz-badge--danger' : 'quiz-badge--success'); }
    UI.text('roomNegotiationSummary','Оценка сохранена в результате переговорного поединка.');
    UI.text('roomNegotiationVerdict', verdictLabel(verdict));
    UI.text('roomNegotiationPoints', String(Number(answer.awardedPoints)||0));
    UI.text('roomNegotiationJudge', judgeLabel(answer.judgeMode));
    if (result) result.hidden=false;
    const judgeComment=String(answer.judgeComment||'').trim();
    if (comment) { comment.textContent=judgeComment; comment.hidden=!judgeComment; }
  }

  function renderTeams() {
    const root = document.getElementById('roomTeams');
    if (!root || !state) return;
    root.innerHTML = '';
    (state.teams || []).forEach(function (teamState) {
      const row = document.createElement('div');
      row.className = 'quiz-team';
      const name = document.createElement('div');
      name.className = 'quiz-team__name';
      const strong = document.createElement('strong');
      strong.textContent = teamState.name;
      const small = document.createElement('small');
      small.textContent = teamState.answeredCurrentQuestion ? 'Ответ принят' : 'Ответа ещё нет';
      name.append(strong, small);
      const score = document.createElement('div');
      score.className = 'quiz-team__score';
      score.textContent = String(teamState.score);
      row.append(name, score);
      root.append(row);
    });
  }

  function renderResultSummary() {
    const card = document.getElementById('roomResultCard');
    const root = document.getElementById('roomResultStandings');
    if (!card || !root || !state) return;
    const result = state.resultSummary || {};
    const visible = isJeopardy() && !!result.finished;
    card.hidden = !visible;
    if (!visible) return;
    const winners = Array.isArray(result.winnerNames) ? result.winnerNames : [];
    UI.text('roomResultWinner', winners.length === 1 ? ('Победитель — ' + winners[0] + '.') : ('Итог: ' + (winners.length ? ('ничья между ' + winners.join(', ')) : 'игра завершена') + '.'));
    root.innerHTML = '';
    (result.standings || []).forEach(function (item) {
      const row = document.createElement('div'); row.className = 'quiz-team';
      const name = document.createElement('div'); name.className = 'quiz-team__name';
      const strong = document.createElement('strong'); strong.textContent = '#' + Number(item.place || 0) + ' ' + String(item.teamName || 'Команда');
      const small = document.createElement('small'); small.textContent = Number(item.place || 0) === 1 ? 'Победитель' : 'Итоговое место';
      name.append(strong, small);
      const score = document.createElement('div'); score.className = 'quiz-team__score'; score.textContent = String(Number(item.score || 0));
      row.append(name, score); root.append(row);
    });
  }

  function renderMessages() {
    const root = document.getElementById('roomMessages');
    if (!root) return;
    const messages = events.map(function (event) {
      return { event: event, message: UI.eventMessage(event) };
    }).filter(function (x) { return x.message && (x.event.action === 'ai_host_message' || x.event.action === 'human_host_message'); }).slice(-30).reverse();
    root.innerHTML = '';
    if (!messages.length) {
      const empty = document.createElement('p');
      empty.className = 'quiz-empty';
      empty.textContent = 'Сообщений пока нет.';
      root.append(empty);
      return;
    }
    messages.forEach(function (item) {
      const node = document.createElement('div');
      node.className = 'quiz-event';
      const strong = document.createElement('strong');
      strong.textContent = item.message;
      const span = document.createElement('span');
      span.textContent = item.event.action === 'ai_host_message' ? 'ИИ-ведущий' : 'Голосовой ведущий';
      node.append(strong, span);
      root.append(node);
    });
  }

  function renderOptions(question) {
    const root = document.getElementById('roomOptions');
    const textarea = document.getElementById('roomAnswerText');
    if (!root || !textarea) return;
    root.innerHTML = '';
    const questionId = Number(question && question.id) || 0;
    if (questionId !== renderedQuestionId) {
      renderedQuestionId = questionId;
      selectedValues = [];
      const source = state && state.yourAnswer ? state.yourAnswer : (isChgk() && state && state.yourDraft ? state.yourDraft : null);
      textarea.value = source ? String(source.answerText || '') : '';
    }
    const options = UI.optionItems(question && question.options);
    const isChoice = question && (question.type === 'single_choice' || question.type === 'multiple_choice');
    textarea.hidden = isChoice && options.length > 0;
    if (!isChoice || !options.length) {
      selectedValues = [];
      return;
    }
    const source = state && state.yourAnswer ? state.yourAnswer : (isChgk() && state && state.yourDraft ? state.yourDraft : null);
    const existingText = source ? String(source.answerText || '') : '';
    if (!selectedValues.length && existingText) {
      selectedValues = question.type === 'multiple_choice'
        ? existingText.split(',').map(function (x) { return x.trim(); }).filter(Boolean)
        : [existingText];
    }
    options.forEach(function (item) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'quiz-option';
      button.textContent = item.label;
      button.classList.toggle('is-selected', selectedValues.indexOf(item.value) !== -1);
      button.addEventListener('click', function () {
        if (button.disabled) return;
        if (question.type === 'multiple_choice') {
          if (selectedValues.indexOf(item.value) === -1) selectedValues.push(item.value);
          else selectedValues = selectedValues.filter(function (x) { return x !== item.value; });
        } else {
          selectedValues = [item.value];
        }
        renderOptions(question);
        updateAnswerAvailability();
      });
      root.append(button);
    });
  }

  function currentTeamEligibleForTiebreak() {
    if (!state || !state.tiebreak || !state.tiebreak.active) return true;
    const ownTeamId = Number(state.you && state.you.teamId || 0);
    const leaders = Array.isArray(state.tiebreak.leaderTeamIds) ? state.tiebreak.leaderTeamIds.map(Number) : [];
    return ownTeamId > 0 && leaders.indexOf(ownTeamId) !== -1;
  }

  function updateAnswerAvailability() {
    const submit = document.getElementById('roomSubmit');
    const textarea = document.getElementById('roomAnswerText');
    const argumentation = document.getElementById('roomArgumentation');
    const optionButtons = Array.from(document.querySelectorAll('#roomOptions .quiz-option'));
    if (!submit || !textarea) return;
    const open = !!(state && state.game && state.game.phase === 'question_open');
    const timeLeft = clock.remainingSeconds();
    const chgk = isChgk();
    const finalRound=jeopardyFinalState();
    const finalRow=ownFinalRow();
    const jeopardyFinalActive=!!(isJeopardy() && finalRound.enabled && finalRound.started && state && state.question && String(state.question.stage||'')==='final');
    const finalLocked = (chgk || jeopardyFinalActive) && !!(state && state.yourAnswer);
    const flow = teamFlow();
    const canEdit = !chgk || (chgkFinalOpen && flow.canFinalize);
    const canFinalize = !chgk || flow.canFinalize;
    const tiebreakEligible = currentTeamEligibleForTiebreak();
    const buzzer=jeopardyBuzzerState();
    const cat=jeopardyCatState();
    const auction=jeopardyAuctionState();
    const ownTeam=findOwnTeam();
    const ownId=Number(ownTeam && ownTeam.id || 0);
    const ownBuzz=!!(isJeopardy() && buzzer.active && ownId && Number(buzzer.active.teamId||0)===ownId && Number(buzzer.active.secondsRemaining||0)>0);
    const ownCat=!!(isJeopardy() && cat.enabled && String(cat.status||'')==='assigned' && Number(cat.targetTeamId||0)===ownId && cat.open);
    const ownAuction=false;
    const jeopardyAnswerRight = jeopardyFinalActive ? !!finalRound.open : (ownBuzz || ownCat);
    const baseDisabled = !open || timeLeft <= 0 || finalLocked || !chgkFinalOpen || !tiebreakEligible || (isJeopardy() && !jeopardyAnswerRight);
    submit.disabled = baseDisabled || !canFinalize;
    textarea.disabled = baseDisabled || !canEdit;
    if (argumentation) {
      argumentation.hidden = !chgk;
      argumentation.disabled = baseDisabled || !canEdit;
    }
    optionButtons.forEach(function (button) { button.disabled = baseDisabled || !canEdit; });
    submit.textContent = (chgk || jeopardyFinalActive) ? 'Зафиксировать финальный ответ' : (isNegotiation() ? 'Отправить реплику' : 'Сохранить ответ');

    if (!state || !state.question) {
      UI.text('roomAnswerHelp', 'Ответ станет доступен после старта вопроса.');
    } else if (!open || timeLeft <= 0) {
      UI.text('roomAnswerHelp', 'Приём окончательных ответов закрыт сервером.');
    } else if (jeopardyFinalActive && finalLocked) {
      UI.text('roomAnswerHelp', 'Финальный ответ зафиксирован и не может быть изменён. Ожидайте общего раскрытия.');
    } else if (isJeopardy() && cat.enabled && String(cat.status||'')==='assigned' && !ownCat) {
      UI.text('roomAnswerHelp', 'Этот спецвопрос передан другой команде.');
    } else if (isJeopardy() && !jeopardyFinalActive && !ownBuzz && !ownCat) {
      UI.text('roomAnswerHelp', 'Сначала нажмите «ОТВЕЧАЕМ!». Сервер откроет поле ответа только команде, первой получившей право ответа.');
    } else if (!tiebreakEligible) {
      UI.text('roomAnswerHelp', 'Сейчас идёт тай-брейк между командами, делящими первое место. Ваша команда наблюдает за дополнительным вопросом.');
    } else if (finalLocked) {
      UI.text('roomAnswerHelp', 'Финальный ответ «Битвы знатоков» зафиксирован. Изменить его уже нельзя.');
    } else if (chgk && !canEdit) {
      const own = findOwnTeam();
      UI.text('roomAnswerHelp', 'Эта сессия не может редактировать командный ответ.');
    } else if (chgk && !canFinalize) {
      UI.text('roomAnswerHelp', 'Окончательный ответ фиксируется с общего командного экрана.');
    } else if (chgk) {
      UI.text('roomAnswerHelp', chgkFinalOpen ? 'Введите один окончательный ответ команды и проверьте формулировку перед фиксацией.' : 'Идёт обсуждение. Поле окончательного ответа откроется после его завершения.');
    } else if (isNegotiation() && state.yourAnswer) {
      UI.text('roomAnswerHelp', 'Реплика отправлена. До окончания времени её можно изменить; в зачёт идёт последний вариант.');
    } else if (isNegotiation()) {
      UI.text('roomAnswerHelp', negotiationMode()==='express' ? 'Переговорный раунд: ответьте коротко и по делу. ИИ оценит инициативу, ясность и следующий ход.' : 'Ответьте следующей рабочей репликой. ИИ оценит качество переговорного хода и даст разбор.');
    } else if (state.yourAnswer) {
      UI.text('roomAnswerHelp', 'Ответ сохранён. До окончания времени его можно изменить; в зачёт идёт последний вариант.');
    } else {
      UI.text('roomAnswerHelp', 'Ответ можно менять до окончания времени. В зачёт идёт последний сохранённый вариант.');
    }
  }

  function renderQuestion() {
    if (!state) return;
    const q = state.question;
    const gameState = state.game || {};
    const counter = q && String(q.stage || 'main') === 'final' ? 'Интеллектуальный батл · Финальный раунд' : ((isNegotiation() ? ('Переговорный поединок · ' + negotiationModeTitle() + ' · Ход ' + gameState.questionPosition + ' из ' + gameState.questionCount) : ((isChgk() ? 'Битва знатоков · ' : '') + 'Вопрос ' + gameState.questionPosition + ' из ' + gameState.questionCount)));
    UI.text('roomQuestionCounter', q ? counter : 'Вопрос ещё не открыт');
    UI.text('roomQuestionText', q ? q.text : (gameState.status === 'finished' ? 'Игра завершена.' : (gameState.hostMode === 'ai' ? 'ИИ-ведущий запустит игру автоматически после подключения всех команд.' : 'Ожидайте старта ведущего.')));
    UI.renderQuestionMedia('roomQuestionMedia', q);
    const textarea = document.getElementById('roomAnswerText');
    const argumentation = document.getElementById('roomArgumentation');
    const qid = q ? Number(q.id || 0) : 0;
    if (qid && qid !== draftQuestionId) {
      draftQuestionId = qid;
      if (textarea) textarea.value = state.yourAnswer ? String(state.yourAnswer.answerText || '') : '';
      if (argumentation) argumentation.value = state.yourAnswer ? String((state.yourAnswer.answerPayload || {}).argumentation || '') : '';
    } else if (state.yourAnswer) {
      if (textarea) textarea.value = String(state.yourAnswer.answerText || '');
      if (argumentation) argumentation.value = String((state.yourAnswer.answerPayload || {}).argumentation || '');
      draftDirty = false;
      clearDraftTimer();
    } else if (isChgk() && !draftDirty) {
    }
    if (q) renderOptions(q); else if (document.getElementById('roomOptions')) document.getElementById('roomOptions').innerHTML = '';
    if (state.yourAnswer) {
      if (isChgk() && String(state.yourAnswer.verdict || 'pending') !== 'pending') {
        status('Решение арбитра получено: ' + verdictLabel(state.yourAnswer.verdict) + '.', 'success');
      } else {
        status(isChgk() || (isJeopardy() && state.question && String(state.question.stage||'')==='final') ? 'Финальный ответ команды зафиксирован.' : (isNegotiation() ? ('Реплика отправлена. Версия ' + state.yourAnswer.attempt + '.') : ('Ответ команды сохранён. Версия ' + state.yourAnswer.attempt + '.')), 'success');
      }
    } else {
      status('', '');
    }
    updateAnswerAvailability();
  }

  function renderState(nextState) {
    state = nextState;
    UI.appendEvents(events, state.events, 120);
    UI.renderTestBadge(!!state.game.testMode);
    clock.sync(state);
    UI.text('roomGameCode', state.game.code || game);
    UI.text('roomTitle', state.game.title || 'Квиз');
    UI.text('roomPhase', isChgk() && state.game.phase === 'question_open' ? 'Командное обсуждение' : UI.phaseLabel(state.game.phase, state.game.status));
    UI.text('roomSubtitle', state.you ? ('Вы подключены к команде. Серверное состояние версии ' + state.game.stateVersion + '.') : 'Синхронизация с сервером.');
    UI.text('roomSyncMeta', 'Состояние ' + state.game.stateVersion);
    const own = findOwnTeam();
    const ownName = own && own.name ? String(own.name) : initialTeamName;
    UI.text('roomTeamBadge', ownName);
    UI.text('roomTeamIdentityName', ownName);
    UI.text('roomTeamStatus', own ? teamLabel(own) : 'Команда не определена.');
    renderTeams();
    renderResultSummary();
    renderLobby();
    renderQuestion();
    renderParticipantGuide();
    renderJeopardyHelp();
    renderMessages();
    renderFormatUi();
    if (isChgk()) { const box=document.getElementById('roomAnswerBox'); const rp=String(state&&state.formatRuntime&&state.formatRuntime.phase||'discussion'); if(box) box.hidden = !(state&&state.game&&state.game.phase==='question_open'&&rp==='final_answer_open') && !(state&&state.yourAnswer); }
    renderJeopardyBoard();
    renderJeopardyAuction();
    renderJeopardyCat();
    renderJeopardyBuzzer();
    renderJeopardyWager();
    renderJeopardyFinal();
    renderJeopardyAi();
    renderLiveHost();
  }

  async function join() {
    if (!game || !team || !teamToken || !joinNonce) {
      throw new Error('Откройте полную персональную ссылку команды: в ней должны быть game, team, token и nonce.');
    }
    const payload = await UI.postJson('api/quiz-join.php', {
      game: game,
      team: team,
      team_token: teamToken,
      nonce: joinNonce,
      test_preview: testPreview ? 1 : 0
    }, teamToken, joinNonce);
    participantNonce = String(payload.nonce || '');
    participantSessionToken = String(payload.sessionToken || '');
    if (!participantNonce) throw new Error('Сервер не вернул nonce участника.');
    sessionStorage.setItem(storagePrefix + 'participantNonce', participantNonce);
    if (participantSessionToken) sessionStorage.setItem(storagePrefix + 'participantSessionToken', participantSessionToken);
    renderState(payload.state);
  }

  function startPolling() {
    if (!participantNonce) throw new Error('Нет nonce сессии участника.');
    if (poller) poller.stop();
    poller = new UI.StatePoller({
      game: game,
      token: teamToken,
      nonce: participantNonce,
      sessionToken: participantSessionToken,
      onState: renderState,
      onError: function (error) {
        if (error && error.status === 403) UI.text('roomSubtitle', 'Сессия участника недействительна. Откройте командную ссылку заново.');
      }
    });
    poller.start();
  }


  document.getElementById('roomJeopardyHelpDismiss')?.addEventListener('click', function () {
    markJeopardyHelpSeen();
    const intro=document.getElementById('roomJeopardyHelpIntro');
    if (intro) intro.hidden=true;
  });
  document.getElementById('roomJeopardyHelpOpen30')?.addEventListener('click', openJeopardyThirtySecondRules);
  document.getElementById('roomRulesButton')?.addEventListener('click', openJeopardyThirtySecondRules);
  document.getElementById('roomParticipantGuide')?.addEventListener('click', function (event) {
    if (event.target && event.target.id === 'roomOpenFullRules') {
      event.preventDefault();
      openJeopardyFullRules();
    }
  });

  const readyButton = document.getElementById('roomReadyToggle');
  if (readyButton) readyButton.addEventListener('click', toggleReady);

  async function boot() {
    UI.text('roomGameCode', game || 'Квиз');
    try {
      if (!participantNonce) await join();
      startPolling();
    } catch (error) {
      if (error && error.code === 'already_in_other_team') {
        showMembershipConflict(error);
        return;
      }
      UI.setConnection('error', error.message || 'Не удалось подключиться');
      UI.text('roomTitle', 'Подключение не выполнено');
      UI.text('roomSubtitle', error.message || 'Откройте полную персональную ссылку команды, выданную организатором.');
      status(error.message || 'Ошибка подключения', 'error');
    }
  }

  document.getElementById('roomJoinLiveHost')?.addEventListener('click', openLiveHost);

  document.getElementById('roomAnswerText')?.addEventListener('input', function () {
    if (isChgk()) scheduleDraftSave();
  });
  document.getElementById('roomArgumentation')?.addEventListener('input', function () {
    if (isChgk()) scheduleDraftSave();
  });

  const appealButton = document.getElementById('roomAppealSubmit');
  if (appealButton) appealButton.addEventListener('click', async function () {
    const textarea = document.getElementById('roomAppealText');
    const appealText = String(textarea && textarea.value || '').trim();
    if (!appealText) { UI.text('roomAppealStatus', 'Укажите основание апелляции.'); return; }
    try {
      appealButton.disabled = true;
      const payload = await UI.postJson('api/quiz-appeal.php', {
        game: game, team: team, appeal_text: appealText,
        participant_nonce: participantNonce, participant_session_token: participantSessionToken,
        last_event_id: poller ? poller.lastEventId : 0
      }, teamToken, participantNonce, participantSessionToken);
      if (payload.state) renderState(payload.state);
      if (textarea) textarea.value = '';
      UI.text('roomAppealStatus', 'Апелляция отправлена ведущему.');
    } catch (error) {
      UI.text('roomAppealStatus', error.message || 'Не удалось подать апелляцию.');
    } finally { appealButton.disabled = false; }
  });

  const buzzButton=document.getElementById('roomBuzzButton');
  if (buzzButton) buzzButton.addEventListener('click', claimJeopardyBuzzer);
  const answerBuzzButton=document.getElementById('roomAnswerBuzzButton');
  if (answerBuzzButton) answerBuzzButton.addEventListener('click', claimJeopardyBuzzer);

  document.getElementById('roomSubmit').addEventListener('click', async function () {
    if (!state || !state.question) return;
    const textarea = document.getElementById('roomAnswerText');
    const choice = state.question.type === 'single_choice' || state.question.type === 'multiple_choice';
    const answerText = choice ? selectedValues.join(', ') : String(textarea.value || '').trim();
    const answerPayload = state.question.type === 'multiple_choice' ? { answers: selectedValues.slice() } : {};
    const argumentation = isChgk() ? String(document.getElementById('roomArgumentation')?.value || '').trim() : '';
    if (!answerText && !selectedValues.length) {
      status('Введите или выберите ответ.', 'error');
      return;
    }
    if (isChgk()) {
      const flow = teamFlow();
      if (!flow.canFinalize) {
        status('У этой сессии нет права зафиксировать окончательный ответ команды.', 'error');
        return;
      }
      const confirmed = window.confirm('Зафиксировать финальный ответ команды? После этого изменить ответ и аргументацию будет нельзя.');
      if (!confirmed) return;
      clearDraftTimer();
    }
    try {
      this.disabled = true;
      let requestBody;
      if (isChgk()) {
        requestBody = {
          game: game,
          answer_text: answerText,
          argumentation: argumentation,
          last_event_id: poller ? poller.lastEventId : 0,
          nonce: participantNonce
        };
      } else {
        requestBody = {
          game: game,
          answer_text: answerText,
          answer_payload: answerPayload,
          last_event_id: poller ? poller.lastEventId : 0,
          nonce: participantNonce
        };
      }
      const payload = await UI.postJson('api/quiz-answer.php', requestBody, teamToken, participantNonce, participantSessionToken);
      if (payload.state) renderState(payload.state);
      status(payload.action === 'finalized' ? 'Финальный ответ «Битвы знатоков» зафиксирован.' : (payload.action === 'updated' ? 'Ответ команды обновлён.' : 'Ответ команды сохранён.'), 'success');
      if (poller) poller.refreshNow();
    } catch (error) {
      if (error.code === 'deadline_passed') status('Серверный дедлайн истёк. Окончательный ответ не принят.', 'error');
      else if (error.code === 'final_answer_locked') status('Финальный ответ «Битвы знатоков» уже зафиксирован и не может быть изменён.', 'error');
      else if (error.code === 'captain_required' || error.code === 'captain_not_supported') status('В «Битве знатоков» используется общий командный экран без отдельных ролей.', 'error');
      else status(error.message || 'Не удалось отправить ответ.', 'error');
    } finally {
      updateAnswerAvailability();
    }
  });

  boot();
})();
