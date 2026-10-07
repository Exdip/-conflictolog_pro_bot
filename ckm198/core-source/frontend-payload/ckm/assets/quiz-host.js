(function () {
  'use strict';

  const UI = window.CKMQuizUI;
  if (!UI) return;
  UI.initTheme();

  const params = UI.query();
  const game = String(params.get('game') || '').toUpperCase();
  const storagePrefix = 'ckm.quiz.host.' + game + '.';
  const hostToken = UI.sessionSecret(storagePrefix + 'token', ['token', 'host_token', 'hostToken']);
  const hostNonce = UI.sessionSecret(storagePrefix + 'nonce', ['nonce']);
  UI.scrubSecrets(['token', 'host_token', 'hostToken', 'nonce']);

  let state = null;
  const events = [];
  let poller = null;

  const clock = new UI.ServerClock(function (seconds) {
    UI.text('hostTimer', UI.formatSeconds(seconds));
    const wrap = document.getElementById('hostTimerWrap');
    if (wrap) wrap.classList.toggle('is-low', seconds > 0 && seconds <= 10);
  });

  function setStatus(id, message, kind) {
    const node = document.getElementById(id);
    if (!node) return;
    node.textContent = message || '';
    node.classList.remove('is-success', 'is-error');
    if (kind === 'success') node.classList.add('is-success');
    if (kind === 'error') node.classList.add('is-error');
  }

  function isChgk() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'chgk');
  }

  function chgkFlowPhase() {
    return String(state && state.questionFlow && state.questionFlow.phase || '');
  }

  function isChgkArbitrationPhase() {
    const phase = chgkFlowPhase();
    return phase === 'arbitrating' || phase === 'arbitration' || phase === 'closed' || phase === 'review';
  }

  function chgkArbiterControlsAllowed() {
    if (!isChgk()) return !(state && state.game && state.game.phase === 'question_open');
    // In the CHGK runtime the public flow may already be in arbitration while
    // the legacy game phase is still question_open. The human arbiter buttons
    // must follow the CHGK flow, not the old generic phase flag.
    return isChgkArbitrationPhase() || String(state && state.game && state.game.phase || '') !== 'question_open';
  }

  function currentAnswers() {
    return state && Array.isArray(state.answers) ? state.answers : [];
  }

  function isJeopardy() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'jeopardy');
  }


  function hostUiRole() {
    if (!state || !state.game) return 'host';
    if (String(state.game.hostMode || 'ai') === 'human') return 'host';
    return String(state.game.judgeMode || 'hybrid') === 'ai' ? 'none' : 'arbiter';
  }

  function humanArbitrationWorkPending() {
    if (!state || !state.game || hostUiRole() !== 'arbiter') return false;
    const pendingAnswer = currentAnswers().some(function (answer) { return String(answer.verdict || 'pending') === 'pending'; });
    if (isJeopardy()) {
      const finalState = jeopardyFinalState();
      const finalWork = !!(finalState && finalState.started && finalState.revealed && !finalState.allResolved);
      const buzzer = state.formatRuntime && state.formatRuntime.buzzer ? state.formatRuntime.buzzer : null;
      const claim = buzzer && buzzer.active && typeof buzzer.active === 'object' ? buzzer.active : null;
      const buzzerWork = !!(claim && claim.hasAnswer);
      return pendingAnswer || finalWork || buzzerWork;
    }
    return pendingAnswer && (isChgk() ? chgkArbiterControlsAllowed() : String(state.game.phase || '') !== 'question_open');
  }

  function hostRoleSetHidden(id, hidden) {
    const node = document.getElementById(id);
    if (node) node.hidden = !!hidden;
  }

  function applyHostRoleLayout() {
    if (!state || !state.game) return;
    const role = hostUiRole();
    const diagnostic = String(params.get('diagnostic') || '') === '1';
    document.body.dataset.hostUiRole = diagnostic ? 'diagnostic' : role;
    document.documentElement.dataset.hostMode = String(state.game.hostMode || 'ai');
    document.documentElement.dataset.judgeMode = String(state.game.judgeMode || 'hybrid');
    try {
      window.dispatchEvent(new CustomEvent('ckm:host-mode-state', {detail:{hostMode:String(state.game.hostMode || 'ai'), judgeMode:String(state.game.judgeMode || 'hybrid'), uiRole:role}}));
    } catch (e) {}
    if (diagnostic || role === 'host') {
      UI.text('hostRoleLabel','Панель ведущего');
      UI.text('hostRoleKicker','Управление игрой');
      document.title = 'Квизы — панель ведущего';
      hostRoleSetHidden('hostAiRoleNotice', true);
      return;
    }

    const arbiter = role === 'arbiter';
    const work = arbiter && humanArbitrationWorkPending();
    UI.text('hostRoleLabel', arbiter ? 'Панель арбитра' : 'ИИ');
    UI.text('hostRoleKicker', arbiter ? 'Арбитраж' : 'Автоматическая игра');
    document.title = arbiter ? 'Квизы — панель арбитра' : 'Квизы — ИИ';
    hostRoleSetHidden('hostAiRoleNotice', false);
    UI.text('hostAiRoleKicker', arbiter ? 'ИИ ведёт игру' : 'ИИ');
    UI.text('hostAiRoleTitle', arbiter ? (work ? 'Требуется решение арбитра' : 'Решение человека сейчас не требуется') : 'Ведущий и арбитр работают автоматически');
    UI.text('hostAiRoleBadge', arbiter ? 'АРБИТР' : 'ИИ');
    UI.text('hostAiRoleText', arbiter
      ? 'ИИ ведёт игру самостоятельно. Эта компактная панель появляется для человека только когда ответ нужно подтвердить или переопределить.'
      : 'ИИ ведёт игру и применяет ИИ-арбитраж. Рабочая панель ведущего для этой комнаты не используется.');
    setStatus('hostAiRoleStatus', arbiter ? (work ? 'Есть ответ, ожидающий решения человека.' : 'Ожидаем ситуацию, требующую ручного арбитража.') : 'Управление ходом игры выполняется серверным runtime и ИИ.', work ? 'success' : '');

    // AI-host mode never exposes host/game-flow controls in the ordinary UI.
    ['hostChgkLobby','hostPhaseCard','hostChgkPanel','hostChgkAppeals','hostChgkComparative','hostChgkMethodology','hostTeamsCard','hostResultCard','hostLiveStudio','hostMessageCard'].forEach(function(id){ hostRoleSetHidden(id,true); });

    if (!arbiter) {
      ['hostQuestionCard','hostAnswersCard','hostJeopardyCard'].forEach(function(id){ hostRoleSetHidden(id,true); });
      return;
    }

    hostRoleSetHidden('hostQuestionCard', !work);
    if (!isJeopardy()) hostRoleSetHidden('hostAnswersCard', !work);
    else hostRoleSetHidden('hostAnswersCard', true);
    hostRoleSetHidden('hostJeopardyCard', !(work && isJeopardy()));

    if (work && isJeopardy()) {
      const head = document.querySelector('#hostJeopardyCard .quiz-card__head h2');
      const kicker = document.querySelector('#hostJeopardyCard .quiz-card__head .quiz-kicker');
      if (head) head.textContent = 'Решение по ответу';
      if (kicker) kicker.textContent = 'Арбитраж';
      const buzzer = state.formatRuntime && state.formatRuntime.buzzer ? state.formatRuntime.buzzer : null;
      const claim = buzzer && buzzer.active && typeof buzzer.active === 'object' ? buzzer.active : null;
      if (document.getElementById('hostJeopardyBuzzer')) document.getElementById('hostJeopardyBuzzer').hidden = !(claim && claim.hasAnswer);
      const cat = state.formatRuntime && state.formatRuntime.catInBag ? state.formatRuntime.catInBag : null;
      if (document.getElementById('hostJeopardyCat')) document.getElementById('hostJeopardyCat').hidden = !(cat && cat.resolvable);
    }
  }

  function jeopardyTeamName(teamId) {
    const team = state && Array.isArray(state.teams) ? state.teams.find(function (x) { return Number(x.id || 0) === Number(teamId || 0); }) : null;
    return team ? String(team.name || 'Команда') : '';
  }

  function jeopardyAuctionState() {
    return state && state.formatRuntime && state.formatRuntime.auction ? state.formatRuntime.auction : { enabled:false };
  }

  function jeopardyFinalState() {
    return state && state.formatRuntime && state.formatRuntime.finalRound ? state.formatRuntime.finalRound : { enabled:false, configured:false, started:false, rows:[] };
  }

  function jeopardyAiState() {
    return state && state.formatRuntime && state.formatRuntime.aiArbitration
      ? state.formatRuntime.aiArbitration
      : { enabled:false, mode:'human', configured:false, humanFallback:true, autoApply:false, last:null };
  }

  function jeopardyAnswerForTeam(teamId) {
    const questionId = state && state.question ? Number(state.question.id || 0) : 0;
    const matches = currentAnswers().filter(function (answer) {
      return (!questionId || Number(answer.questionId || answer.question_id || 0) === questionId)
        && (!teamId || Number(answer.teamId || answer.team_id || 0) === Number(teamId || 0));
    });
    return matches.length ? matches[matches.length - 1] : null;
  }

  function jeopardyCurrentAnswer() {
    const questionId = state && state.question ? Number(state.question.id || 0) : 0;
    const matches = currentAnswers().filter(function (answer) {
      return !questionId || Number(answer.questionId || answer.question_id || 0) === questionId;
    });
    return matches.length ? matches[matches.length - 1] : null;
  }

  function latestJeopardyAiForAnswer(answerId) {
    for (let i = events.length - 1; i >= 0; i -= 1) {
      const event = events[i];
      if (!event || event.action !== 'jeopardy_ai_arbitration_completed') continue;
      const payload = event.payload || {};
      if (Number(payload.answerId || 0) === Number(answerId || 0)) return payload;
    }
    return null;
  }

  function jeopardyAiDecisionLabel(value) {
    if (String(value || '') === 'accepted') return 'верно';
    if (String(value || '') === 'rejected') return 'неверно';
    return 'требуется решение ведущего';
  }

  async function requestJeopardyAi(answerId) {
    if (!answerId) {
      setStatus('hostJeopardyBoardStatus','Сначала дождитесь ответа команды.','error');
      return;
    }
    try {
      setStatus('hostJeopardyBoardStatus','ИИ оценивает ответ…','');
      const payload = await hostAction('jeopardy_ai_arbitrate', {
        answer_id:Number(answerId),
        request_id:'jeopardy-ui-' + Number(answerId) + '-' + Date.now()
      });
      const ai = payload && payload.evaluation ? payload.evaluation : (payload && payload.aiArbitration ? payload.aiArbitration : null);
      const status = payload && payload.aiStatus ? String(payload.aiStatus) : '';
      setStatus('hostJeopardyBoardStatus', status === 'needs_human'
        ? 'ИИ запросил ручную проверку. Используйте решение ведущего.'
        : 'Рекомендация ИИ сохранена. В гибридном режиме ведущий подтверждает решение.', status === 'needs_human' ? '' : 'success');
      if (ai && ai.comment) UI.text('hostJeopardyAiComment', String(ai.comment));
    } catch (error) {
      setStatus('hostJeopardyBoardStatus',(error && error.message ? error.message : 'ИИ недоступен.') + ' Используйте ручное решение ведущего.','error');
    }
  }

  function renderJeopardyAi() {
    const wrap=document.getElementById('hostJeopardyAi');
    if (!wrap) return;
    const ai=jeopardyAiState();
    const answer=jeopardyCurrentAnswer();
    const finalState=jeopardyFinalState();
    const isFinal=!!(state && state.question && String(state.question.stage || '') === 'final');
    // alpha.85.4: the AI card is contextual, not permanent. Show it only
    // when there is a current non-final answer that can actually be judged.
    const visible=!!(isJeopardy() && ai.enabled && answer && !isFinal && String(answer.verdict || 'pending') === 'pending');
    wrap.hidden=!visible;
    if (!visible) return;
    const button=document.getElementById('hostJeopardyAiEvaluate');
    const board=state && state.jeopardyBoard ? state.jeopardyBoard : null;
    const pendingCellTransition=!!(board && Number(board.selectedQuestionId||0)>0 && (!state || !state.game || String(state.game.phase||'')!=='question_open'));
    let last=ai.last || null;
    // alpha.85: never carry a previous answer's AI recommendation into a newly
    // selected cell that has not opened yet (e.g. «Секретная передача»).
    if (pendingCellTransition || !answer) last=null;
    if (last && last.answerId && answer && Number(last.answerId||0)!==Number(answer.id||0)) last=null;
    let status=ai.mode === 'ai' ? 'Автоарбитраж ИИ' : 'Гибридный арбитраж ИИ + ведущий';
    let comment='Ручное решение ведущего всегда остаётся доступным как резерв.';
    if (!ai.configured) {
      status='ИИ не настроен';
      comment='Игра не блокируется: используйте ручную оценку ведущего.';
    } else if (last) {
      if (last.status === 'failed') {
        status='ИИ временно недоступен';
        comment=String(last.error || 'Используйте ручное решение ведущего.');
      } else if (last.status === 'needs_human') {
        status='ИИ просит ручную проверку';
        comment=String(last.comment || 'Вердикт недостаточно надёжен для автоматического применения.');
      } else if (last.status === 'recommended') {
        status='Рекомендация ИИ: ' + jeopardyAiDecisionLabel(last.decision) + (last.confidence ? ' · ' + Number(last.confidence) + '%' : '');
        comment=String(last.comment || 'Подтвердите или переопределите решение вручную.');
      } else if (last.status === 'applied') {
        status='AI-решение применено: ' + jeopardyAiDecisionLabel(last.decision) + (last.confidence ? ' · ' + Number(last.confidence) + '%' : '');
        comment=String(last.comment || 'Результат применён сервером.');
      }
    }
    UI.text('hostJeopardyAiStatus',status);
    UI.text('hostJeopardyAiComment',comment);
    const why=document.getElementById('hostJeopardyAiWhy');
    if (why) why.open=!!(last && (last.status==='failed' || last.status==='needs_human'));
    if (button) {
      const canEvaluate=!!(ai.configured && answer && !isFinal && state && state.game && state.game.status !== 'finished');
      button.hidden=!canEvaluate;
      button.disabled=!canEvaluate;
      button.textContent=last && Number(last.answerId || 0) === Number(answer && answer.id || 0) ? 'Повторить AI-оценку' : 'Оценить текущий ответ ИИ';
    }
    const messageNode=document.getElementById('hostJeopardyAiHostMessage');
    if (messageNode) {
      // AI-host narration is intentionally not duplicated in the compact
      // arbitration card. The host sees only the current recommendation.
      messageNode.hidden=true;
      messageNode.textContent='';
    }
  }

  function renderJeopardyFinal() {
    const wrap=document.getElementById('hostJeopardyFinal'); if (!wrap) return;
    const finalState=jeopardyFinalState();
    const waiting=document.getElementById('hostFinalWaitingHint');
    const enabled=!!(isJeopardy() && finalState.enabled);
    const visible=!!(enabled && (finalState.started || finalState.canStart));
    if (waiting) waiting.hidden=!(enabled && !finalState.started && !finalState.canStart);
    wrap.hidden=!visible; if (!visible) return;
    const start=document.getElementById('hostFinalStartControls');
    const reveal=document.getElementById('hostFinalRevealControls');
    const finish=document.getElementById('hostFinalFinishControls');
    if (start) start.hidden=!(!finalState.started && finalState.canStart);
    if (reveal) reveal.hidden=!(finalState.started && !finalState.revealed && finalState.canReveal);
    if (finish) finish.hidden=!(finalState.started && finalState.revealed && finalState.allResolved && state.game.status !== 'finished');
    if (!finalState.started) UI.text('hostFinalStatus',finalState.canStart?'Все ячейки сыграны. Финал готов к запуску: верный ответ = 500 баллов.':'Финал станет доступен после завершения игрового поля.');
    else if (!finalState.revealed && finalState.open) UI.text('hostFinalStatus','Приём финальных ответов · '+Number(finalState.submittedCount||0)+'/'+Number(finalState.teamCount||0)+' · осталось '+UI.formatSeconds(Number(finalState.secondsRemaining||0))+'. Верный ответ = '+Number(finalState.fixedPoints||500)+' баллов.');
    else if (!finalState.revealed) UI.text('hostFinalStatus','Приём финальных ответов завершён · '+Number(finalState.submittedCount||0)+'/'+Number(finalState.teamCount||0)+'. Теперь можно раскрыть ответы.');
    else UI.text('hostFinalStatus','Ответы раскрыты · решений '+Number(finalState.resolvedCount||0)+'/'+Number(finalState.teamCount||0)+'.');
    const rowsRoot=document.getElementById('hostFinalRows'); if (!rowsRoot) return;
    rowsRoot.innerHTML='';
    (Array.isArray(finalState.rows)?finalState.rows:[]).forEach(function(row){
      const node=document.createElement('div'); node.className='quiz-jeopardy-final__row';
      const text=document.createElement('div');
      if (!finalState.revealed) text.textContent=String(row.teamName||'Команда')+' · ответ '+(row.answerSubmitted?'✓':'—');
      else text.textContent=String(row.teamName||'Команда')+' · '+(row.answerSubmitted?('«'+String(row.answerText||'')+'»'):'нет ответа')+(row.resolved?' · '+String(row.verdict||''):'');
      node.appendChild(text);
      if (finalState.revealed && !row.resolved && row.answerSubmitted) {
        const actions=document.createElement('div'); actions.className='quiz-inline-actions';
        const ok=document.createElement('button'); ok.type='button'; ok.className='quiz-btn quiz-btn--primary'; ok.textContent='Верно · +'+Number(finalState.fixedPoints||500);
        const no=document.createElement('button'); no.type='button'; no.className='quiz-btn'; no.textContent='Неверно · +0';
        ok.addEventListener('click',function(){ resolveJeopardyFinalTeam(row.teamId,'accepted'); });
        no.addEventListener('click',function(){ resolveJeopardyFinalTeam(row.teamId,'rejected'); });
        actions.append(ok,no);
        const aiState=jeopardyAiState();
        const finalAnswer=jeopardyAnswerForTeam(row.teamId);
        if (aiState.enabled && aiState.configured && finalAnswer) {
          const aiButton=document.createElement('button');
          aiButton.type='button'; aiButton.className='quiz-btn';
          const recommendation=latestJeopardyAiForAnswer(finalAnswer.id);
          aiButton.textContent=recommendation ? 'Повторить AI-оценку' : 'Оценить ИИ';
          aiButton.addEventListener('click',function(){ requestJeopardyAi(finalAnswer.id); });
          actions.appendChild(aiButton);
          if (recommendation) {
            const aiNote=document.createElement('span'); aiNote.className='quiz-help';
            aiNote.textContent='ИИ: '+jeopardyAiDecisionLabel(recommendation.decision)+(recommendation.confidence ? ' · '+Number(recommendation.confidence)+'%' : '')+(recommendation.comment ? ' · '+String(recommendation.comment) : '');
            node.appendChild(aiNote);
          }
        }
        node.appendChild(actions);
      }
      rowsRoot.appendChild(node);
    });
  }

  async function startJeopardyFinal() {
    try { setStatus('hostJeopardyBoardStatus','Открываем финальный раунд…',''); await hostAction('jeopardy_start_final',{}); setStatus('hostJeopardyBoardStatus','Финальный раунд открыт. Ответы скрыты до общего раскрытия; верный ответ = 500 баллов.','success'); }
    catch(error){ setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось открыть финал.','error'); }
  }

  async function revealJeopardyFinal() {
    try { setStatus('hostJeopardyBoardStatus','Раскрываем финальные ответы…',''); await hostAction('jeopardy_reveal_final',{}); setStatus('hostJeopardyBoardStatus','Финальные ответы раскрыты. Оцените каждый полученный ответ.','success'); }
    catch(error){ setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось раскрыть финал.','error'); }
  }

  async function resolveJeopardyFinalTeam(teamId,decision) {
    try { await hostAction('jeopardy_resolve_final_team',{team_id:Number(teamId||0),decision:decision}); }
    catch(error){ setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось оценить финальный ответ.','error'); }
  }

  async function finishJeopardyFinal() {
    try { await hostAction('jeopardy_finish_final',{}); setStatus('hostJeopardyBoardStatus','Игра завершена по итогам финального раунда.','success'); }
    catch(error){ setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось завершить игру.','error'); }
  }

  function renderJeopardyAuction() {
    const wrap=document.getElementById('hostJeopardyAuction');
    if (!wrap) return;
    const auction=jeopardyAuctionState();
    const visible=!!(isJeopardy() && auction && auction.enabled);
    wrap.hidden=!visible;
    if (!visible) return;
    const pending=String(auction.status||'')==='pending_bid';
    const assigned=String(auction.status||'')==='assigned';
    const assignControls=document.getElementById('hostAuctionAssignControls');
    const resolveControls=document.getElementById('hostAuctionResolveControls');
    if (assignControls) assignControls.hidden=!pending;
    if (resolveControls) resolveControls.hidden=!(assigned && auction.resolvable);
    const select=document.getElementById('hostAuctionTeam');
    if (pending && select) {
      const current=Number(select.value||0); select.innerHTML='';
      (state.teams||[]).forEach(function(team){
        const o=document.createElement('option'); o.value=String(Number(team.id||0)); o.textContent=String(team.name||('Команда '+Number(team.slot||0))); if(Number(team.id||0)===current)o.selected=true; select.appendChild(o);
      });
    }
    const bid=document.getElementById('hostAuctionBid');
    if (pending && bid) { bid.min=String(Math.max(1,Number(auction.minBid||1))); if(!Number(bid.value||0) || Number(bid.value||0)<Number(auction.minBid||1)) bid.value=String(Math.max(1,Number(auction.minBid||1))); }
    if (pending) UI.text('hostAuctionStatus','Спецячейка открыта. Проведите торги и зафиксируйте победителя. Минимальная цена: '+Number(auction.minBid||0)+'.');
    else if (assigned) {
      const winner=jeopardyTeamName(auction.winnerTeamId)||'команда';
      const winnerTeam=(state.teams||[]).find(function(team){return Number(team.id||0)===Number(auction.winnerTeamId||0);});
      const answered=!!(winnerTeam && winnerTeam.answeredCurrentQuestion);
      UI.text('hostAuctionStatus','Победитель: '+winner+' · цена вопроса '+Number(auction.bid||0)+'. Отвечает только эта команда; buzzer и отдельная рискованная ставка отключены.');
      const accept=document.getElementById('hostAuctionAccept');
      const reject=document.getElementById('hostAuctionReject');
      if (accept) accept.disabled=!auction.resolvable || !answered;
      if (reject) reject.disabled=!auction.resolvable;
    } else {
      const winner=jeopardyTeamName(auction.winnerTeamId)||'команда';
      UI.text('hostAuctionStatus','Торги завершены · '+winner+' · '+Number(auction.bid||0)+'.');
    }
  }

  function jeopardyCatState() {
    return state && state.formatRuntime && state.formatRuntime.catInBag ? state.formatRuntime.catInBag : { enabled:false };
  }

  function renderJeopardyCat() {
    const wrap=document.getElementById('hostJeopardyCat');
    if (!wrap) return;
    const cat=jeopardyCatState();
    const visible=!!(isJeopardy() && cat && cat.enabled);
    wrap.hidden=!visible;
    if (!visible) return;
    const sourceId=Number(cat.sourceTeamId||0), targetId=Number(cat.targetTeamId||0);
    const sourceName=jeopardyTeamName(sourceId) || 'команда, выбравшая вопрос';
    const targetName=jeopardyTeamName(targetId) || '';
    const pending=String(cat.status||'')==='pending_target';
    const assigned=String(cat.status||'')==='assigned';
    const assignControls=document.getElementById('hostCatAssignControls');
    const resolveControls=document.getElementById('hostCatResolveControls');
    if (assignControls) assignControls.hidden=!pending;
    if (resolveControls) resolveControls.hidden=!(assigned && cat.resolvable);
    if (pending) {
      UI.text('hostCatStatus','Ячейку выбрала '+sourceName+'. Назначьте другую команду — спецтип был скрыт до выбора.');
      const select=document.getElementById('hostCatTarget');
      if (select) {
        select.innerHTML='';
        (state.teams||[]).forEach(function(team){
          if (Number(team.id||0)===sourceId) return;
          const option=document.createElement('option');
          option.value=String(Number(team.id||0));
          option.textContent=String(team.name||('Команда '+Number(team.slot||0)));
          select.appendChild(option);
        });
      }
      const assign=document.getElementById('hostCatAssign');
      if (assign) assign.disabled=!(state.teams||[]).some(function(team){return Number(team.id||0)!==sourceId;});
    } else if (assigned) {
      const target=(state.teams||[]).find(function(team){return Number(team.id||0)===targetId;});
      const answered=!!(target && target.answeredCurrentQuestion);
      UI.text('hostCatStatus','Отвечает '+(targetName||'назначенная команда')+'. Право ответа эксклюзивное; buzzer отключён.');
      const accept=document.getElementById('hostCatAccept');
      const reject=document.getElementById('hostCatReject');
      if (accept) accept.disabled=!cat.resolvable || !answered;
      if (reject) reject.disabled=!cat.resolvable;
    } else {
      UI.text('hostCatStatus','Спецвопрос завершён.');
    }
  }

  function renderJeopardyBoard() {
    const card = document.getElementById('hostJeopardyCard');
    if (!card) return;
    const active = isJeopardy();
    card.hidden = !active;
    if (!active) return;
    const board = state && state.jeopardyBoard ? state.jeopardyBoard : { enabled: false };
    const selectorId = Number(board.selectorTeamId || 0);
    const selectorName = jeopardyTeamName(selectorId);
    UI.text('hostJeopardyStatus', selectorName ? ('Выбирает: ' + selectorName) : 'Право выбора не назначено');

    const select = document.getElementById('hostJeopardySelector');
    if (select) {
      select.innerHTML = '';
      (state.teams || []).forEach(function (team) {
        const option = document.createElement('option');
        option.value = String(Number(team.id || 0));
        option.textContent = String(team.name || ('Команда ' + Number(team.slot || 0)));
        option.selected = Number(team.id || 0) === selectorId;
        select.appendChild(option);
      });
    }
    const hasPendingSelection=Number(board.selectedQuestionId||0)>0;
    const finalState=state && state.formatRuntime ? state.formatRuntime.finalRound : null;
    const finalStarted=!!(finalState && finalState.started);
    const transfer = document.getElementById('hostJeopardySetSelector');
    if (transfer) transfer.disabled = state.game.status === 'finished' || state.game.phase === 'question_open' || hasPendingSelection || finalStarted || !(state.teams || []).length;

    const locked = state.game.status === 'finished' || state.game.phase === 'question_open' || hasPendingSelection || finalStarted || !selectorId;
    if (UI.renderJeopardyBoard) UI.renderJeopardyBoard('hostJeopardyBoard', board, {
      teams: state.teams || [],
      interactive: true,
      locked: locked,
      // alpha.83.1: host clicks are always sent to the authoritative server
      // for available cells. If the game is really locked, the API returns the
      // precise reason and hostJeopardyBoardStatus displays it.
      serverGuardedSelection: true,
      hint: selectorName ? ('Доступную ячейку выбирает ' + selectorName + '.') : 'Сначала назначьте команду с правом выбора.',
      onSelect: function (questionId) { selectJeopardyQuestion(questionId); }
    });
  }

  function renderJeopardyBuzzer() {
    const wrap=document.getElementById('hostJeopardyBuzzer');
    if (!wrap) return;
    const buzzer=state && state.formatRuntime ? state.formatRuntime.buzzer : null;
    const visible=!!(isJeopardy() && buzzer && buzzer.enabled);
    wrap.hidden=!visible;
    if (!visible) return;
    const claim=buzzer.active && typeof buzzer.active==='object' ? buzzer.active : null;
    const teamName=claim ? jeopardyTeamName(claim.teamId) : '';
    const postClose=!!(claim && claim.postClose);
    const hasAnswer=!!(claim && claim.hasAnswer);
    UI.text('hostBuzzStatus',claim ? ((postClose?'Ожидает решения: ':'Право ответа: ')+(teamName||'команда')) : 'Кнопка свободна');
    UI.text('hostBuzzTimer',claim
      ? (postClose ? 'Время ответа завершено · сохранённый ответ ждёт решения ведущего.' : ('Осталось '+UI.formatSeconds(Number(claim.secondsRemaining||0))))
      : 'Ждём первого серверного нажатия.');
    const accept=document.getElementById('hostBuzzAccept');
    const reject=document.getElementById('hostBuzzReject');
    if (accept) {
      accept.disabled=!claim || !hasAnswer;
      accept.textContent='Верно · +номинал';
    }
    if (reject) {
      reject.disabled=!claim;
      reject.textContent=postClose ? 'Неверно' : 'Неверно · открыть другим';
    }
  }

  function renderJeopardyWager() {
    const wrap=document.getElementById('hostJeopardyWager'); if (!wrap) return;
    const wager=state && state.formatRuntime ? state.formatRuntime.wager : null;
    const rows=wager && Array.isArray(wager.rows) ? wager.rows : [];
    wrap.hidden=!(isJeopardy() && wager && wager.enabled);
    if (wrap.hidden) return;
    if (!rows.length) { UI.text('hostWagerStatus','Ставок нет.'); return; }
    const labels=rows.map(function(row){
      const name=jeopardyTeamName(row.teamId) || 'Команда', outcome=String(row.outcome||'');
      const status=outcome==='won' ? 'выиграна' : (outcome==='lost' ? 'проиграна' : 'ожидает результата');
      return name+': '+Number(row.amount||0)+' ('+status+')';
    });
    UI.text('hostWagerStatus',labels.join(' · '));
  }

  async function resolveJeopardyBuzz(decision) {
    try {
      setStatus('hostJeopardyBoardStatus','Обрабатываем ответ…','');
      await hostAction('jeopardy_resolve_buzz',{decision:decision});
      setStatus('hostJeopardyBoardStatus',decision==='accepted'?'Ответ принят, номинал начислен.':'Ответ отклонён, кнопка открыта другим командам.','success');
    } catch(error) { setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось обработать ответ.','error'); }
  }

  async function assignJeopardyAuction() {
    const select=document.getElementById('hostAuctionTeam');
    const bid=document.getElementById('hostAuctionBid');
    const teamId=select ? Number(select.value||0) : 0;
    const amount=bid ? Number(bid.value||0) : 0;
    if (!teamId || !amount) { setStatus('hostJeopardyBoardStatus','Выберите победителя и укажите цену вопроса.','error'); return; }
    try {
      setStatus('hostJeopardyBoardStatus','Фиксируем торги…','');
      await hostAction('jeopardy_assign_auction',{team_id:teamId,bid:amount});
      setStatus('hostJeopardyBoardStatus','Торги завершены, вопрос открыт победившей команде.','success');
    } catch(error) { setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось завершить торги.','error'); }
  }

  async function resolveJeopardyAuction(decision) {
    try {
      setStatus('hostJeopardyBoardStatus','Обрабатываем ответ победителя торгов…','');
      await hostAction('jeopardy_resolve_auction',{decision:decision});
      setStatus('hostJeopardyBoardStatus',decision==='accepted'?'Ответ принят, ставка начислена.':'Ответ отклонён, ставка списана.','success');
    } catch(error) { setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось завершить торги.','error'); }
  }

  async function assignJeopardyCat() {
    const select=document.getElementById('hostCatTarget');
    const teamId=select ? Number(select.value||0) : 0;
    if (!teamId) { setStatus('hostJeopardyBoardStatus','Выберите команду-получателя.','error'); return; }
    try {
      setStatus('hostJeopardyBoardStatus','Передаём спецвопрос…','');
      await hostAction('jeopardy_assign_cat',{team_id:teamId});
      setStatus('hostJeopardyBoardStatus','Секретная передача выполнена, вопрос открыт назначенной команде.','success');
    } catch(error) { setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось передать спецвопрос.','error'); }
  }

  async function resolveJeopardyCat(decision) {
    try {
      setStatus('hostJeopardyBoardStatus','Обрабатываем спецвопрос…','');
      await hostAction('jeopardy_resolve_cat',{decision:decision});
      setStatus('hostJeopardyBoardStatus',decision==='accepted'?'Ответ принят, номинал начислен.':'Ответ отклонён, спецвопрос завершён.','success');
    } catch(error) { setStatus('hostJeopardyBoardStatus',error && error.message ? error.message : 'Не удалось завершить спецвопрос.','error'); }
  }

  async function selectJeopardyQuestion(questionId) {
    const board = state && state.jeopardyBoard ? state.jeopardyBoard : {};
    const teamId = Number(board.selectorTeamId || 0);
    if (!teamId || !questionId) {
      setStatus('hostJeopardyBoardStatus', 'Сначала назначьте команду с правом выбора.', 'error');
      return;
    }
    try {
      setStatus('hostJeopardyBoardStatus', 'Ячейка нажата. Проверяем право выбора на сервере…', '');
      await hostAction('jeopardy_select_question', { team_id: teamId, question_id: Number(questionId) });
      const auction=jeopardyAuctionState();
      const cat=jeopardyCatState();
      setStatus('hostJeopardyBoardStatus', auction && auction.enabled && String(auction.status||'')==='pending_bid'
        ? 'Открылась спецмеханика «Торги за вопрос». Проведите торги и зафиксируйте победителя.'
        : (cat && cat.enabled && String(cat.status||'')==='pending_target'
          ? 'Открылась спецмеханика «Секретная передача». Назначьте другую команду.'
          : 'Ячейка выбрана, вопрос открыт.'), 'success');
    } catch (error) {
      setStatus('hostJeopardyBoardStatus', error && error.message ? error.message : 'Не удалось выбрать ячейку.', 'error');
    }
  }

  async function transferJeopardySelector() {
    const select = document.getElementById('hostJeopardySelector');
    const teamId = select ? Number(select.value || 0) : 0;
    if (!teamId) return;
    try {
      setStatus('hostJeopardyBoardStatus', 'Передаём право выбора…', '');
      await hostAction('jeopardy_set_selector', { team_id: teamId });
      setStatus('hostJeopardyBoardStatus', 'Право выбора передано команде.', 'success');
    } catch (error) {
      setStatus('hostJeopardyBoardStatus', error && error.message ? error.message : 'Не удалось передать право выбора.', 'error');
    }
  }

  function chgkPendingCount() {
    if (!isChgk()) return 0;
    return currentAnswers().filter(function (answer) { return String(answer.verdict || 'pending') === 'pending'; }).length;
  }

  function verdictLabel(value) {
    const labels = { accepted: 'Принят', correct: 'Верно', partial: 'Частично', incorrect: 'Неверно', rejected: 'Отклонён', pending: 'Ожидает решения' };
    return labels[String(value || 'pending')] || String(value || '—');
  }

  function latestAiHostMessage() {
    const questionId = state && state.question ? Number(state.question.id || 0) : 0;
    const candidates = events.filter(function (event) {
      return event && event.action === 'ai_host_message' && (!questionId || !event.question_id || Number(event.question_id) === questionId);
    });
    const event = candidates.length ? candidates[candidates.length - 1] : null;
    if (!event || !event.payload) return '';
    const payload = event.payload || {};
    if (payload.text && typeof payload.text.content === 'string') return payload.text.content;
    return typeof payload.text === 'string' ? payload.text : '';
  }

  function makeChgkStat(label, value, tone) {
    const node = document.createElement('div');
    node.className = 'quiz-chgk-host-stat' + (tone ? ' is-' + tone : '');
    const strong = document.createElement('strong');
    strong.textContent = String(value);
    const span = document.createElement('span');
    span.textContent = label;
    node.append(strong, span);
    return node;
  }

  function renderChgkLobby() {
    const panel = document.getElementById('hostChgkLobby');
    if (!panel || !state) return;
    const lobby = state.lobby || null;
    const visible = isChgk() && lobby && lobby.active && state.game.phase === 'waiting';
    panel.hidden = !visible;
    if (!visible) return;
    const preflight = lobby.preflight || {};
    UI.text('hostLobbyReadyCount', Number(lobby.readyCount || 0) + ' / ' + Number(lobby.teamCount || 0));
    const badge = document.getElementById('hostLobbyBadge');
    if (badge) {
      badge.textContent = preflight.canStart ? 'Готово к старту' : 'Требуется подготовка';
      badge.classList.toggle('quiz-badge--success', !!preflight.canStart);
      badge.classList.toggle('quiz-badge--warning', !preflight.canStart);
    }
    const list = document.getElementById('hostPreflightList');
    if (list) {
      list.innerHTML = '';
      const checks = preflight.checks || {};
      Object.keys(checks).forEach(function (key) {
        const check = checks[key] || {};
        const row = document.createElement('div');
        row.className = 'quiz-preflight-row ' + (check.ok ? 'is-ok' : 'is-fail');
        const mark = document.createElement('strong');
        mark.textContent = check.ok ? '✓' : '×';
        const text = document.createElement('span');
        text.textContent = String(check.label || key) + (check.detail ? ' · ' + check.detail : '');
        row.append(mark, text);
        list.append(row);
      });
    }
    const warnings = Array.isArray(preflight.warnings) ? preflight.warnings : [];
    UI.text('hostPreflightWarnings', warnings.map(function (x) { return x.message || ''; }).filter(Boolean).join(' '));
    UI.text('hostLobbyHint', preflight.canStart ? 'Все обязательные проверки пройдены. Можно начинать игру.' : ((preflight.blockers || []).map(function (x) { return x.message || ''; }).filter(Boolean)[0] || 'Устраните проблемы перед стартом.'));
  }

  function chgkResolveAnswer(answer, accepted) {
    const answerId = Number(answer && answer.id || 0);
    if (!answerId) return;
    const verdict = accepted ? 'accepted' : 'rejected';
    const text = accepted ? 'Засчитываем ответ…' : 'Не засчитываем ответ…';
    setStatus('hostActionStatus', text, '');
    hostAction('score_answer', {
      answer_id: answerId,
      verdict: verdict,
      points: accepted ? 1 : 0,
      comment: accepted ? 'Решение арбитра: ответ засчитан.' : 'Решение арбитра: ответ не засчитан.',
      request_id: 'chgk-human-' + answerId + '-' + Date.now()
    }).then(function () {
      setStatus('hostActionStatus', accepted ? 'Решение арбитра сохранено: ответ засчитан.' : 'Решение арбитра сохранено: ответ не засчитан.', 'success');
    }).catch(function (error) {
      setStatus('hostActionStatus', error && error.message ? error.message : 'Не удалось сохранить решение арбитра.', 'error');
    });
  }

  function chgkAiEvaluateAnswer(answer, repeat) {
    const answerId = Number(answer && answer.id || 0);
    if (!answerId) return;
    setStatus('hostActionStatus', repeat ? 'ИИ повторно оценивает ответ…' : 'ИИ оценивает ответ…', '');
    hostAction(repeat ? 'ai_rearbitrate' : 'ai_arbitrate', {
      answer_id: answerId,
      request_id: 'chgk-ai-' + answerId + '-' + Date.now()
    }).then(function () {
      setStatus('hostActionStatus', 'ИИ-оценка сохранена. При необходимости её можно изменить вручную.', 'success');
    }).catch(function (error) {
      setStatus('hostActionStatus', (error && error.message ? error.message : 'ИИ-оценка не выполнена.') + ' Используйте решение арбитра.', 'error');
    });
  }

  function renderChgkHostPanel() {
    const panel = document.getElementById('hostChgkPanel');
    if (!panel || !state) return;
    const chgk = isChgk();
    panel.hidden = !chgk;
    if (!chgk) return;

    const teams = Array.isArray(state.teams) ? state.teams : [];
    const answers = currentAnswers();
    const answersByTeam = {};
    answers.forEach(function (answer) { answersByTeam[Number(answer.teamId || 0)] = answer; });
    const answered = teams.filter(function (team) { return !!answersByTeam[Number(team.id || 0)]; }).length;
    const pending = answers.filter(function (answer) { return String(answer.verdict || 'pending') === 'pending'; }).length;
    const aiResolved = answers.filter(function (answer) { return String(answer.verdict || 'pending') !== 'pending' && String(answer.judgeMode || '') === 'ai'; }).length;
    const humanResolved = answers.filter(function (answer) { return String(answer.verdict || 'pending') !== 'pending' && String(answer.judgeMode || '') === 'human'; }).length;
    UI.text('hostChgkSummary', answered + ' из ' + teams.length + ' ответили');

    const earlySeconds = Math.max(5, Math.min(60, Number(state.formatRuntime && state.formatRuntime.earlyAnswerSeconds || 5)));
    const earlySelect = document.getElementById('hostChgkEarlySeconds');
    if (earlySelect && document.activeElement !== earlySelect) earlySelect.value = String(earlySeconds);
    UI.text('hostEarlyAnswerMeta', 'Сейчас команда видит кнопку «Досрочный ответ» ' + earlySeconds + ' сек. Обсуждение после этого — 60 сек.');

    const stats = document.getElementById('hostChgkStats');
    if (stats) {
      stats.innerHTML = '';
      stats.append(
        makeChgkStat('Ответы', answered + '/' + teams.length, answered === teams.length && teams.length ? 'success' : ''),
        makeChgkStat('Ждут арбитра', pending, pending ? 'warning' : 'success'),
        makeChgkStat('Оценено ИИ', aiResolved, ''),
        makeChgkStat('Оценено вручную', humanResolved, '')
      );
    }

    const grid = document.getElementById('hostChgkTeams');
    if (grid) {
      grid.innerHTML = '';
      teams.forEach(function (team) {
        const answer = answersByTeam[Number(team.id || 0)] || null;
        const card = document.createElement('div');
        card.className = 'quiz-chgk-team-card';
        const head = document.createElement('div');
        head.className = 'quiz-chgk-team-card__head';
        const title = document.createElement('strong');
        title.textContent = team.name || team.key || 'Команда';
        const badge = document.createElement('span');
        badge.className = 'quiz-badge';
        if (!answer) {
          const closed = state.game.phase !== 'question_open';
          badge.textContent = closed ? 'Нет ответа' : 'Обсуждает';
          badge.classList.add(closed ? 'quiz-badge--danger' : 'quiz-badge--warning');
        } else if (String(answer.verdict || 'pending') === 'pending') {
          badge.textContent = 'Ответ зафиксирован';
          badge.classList.add('quiz-badge--warning');
        } else {
          badge.textContent = (String(answer.judgeMode || '') === 'ai' ? 'ИИ: ' : 'Ведущий: ') + verdictLabel(answer.verdict);
          badge.classList.add('quiz-badge--success');
        }
        head.append(title, badge);
        card.append(head);

        const authority = document.createElement('div');
        authority.className = 'quiz-chgk-captain-control';
        const mode = document.createElement('small');
        mode.textContent = 'Одна команда · общий командный экран. Окончательный ответ фиксируется командой совместно.';
        authority.append(mode);
        card.append(authority);

        if (answer) {
          const text = document.createElement('p');
          text.className = 'quiz-chgk-team-card__answer';
          text.textContent = answer.answerText || '—';
          card.append(text);
          const argumentation = String((answer.answerPayload || {}).argumentation || '').trim();
          if (argumentation) {
            const rationale = document.createElement('p');
            rationale.className = 'quiz-chgk-team-card__argument';
            rationale.textContent = 'Аргументация: ' + argumentation;
            card.append(rationale);
          }
          if (String(answer.verdict || 'pending') !== 'pending') {
            const result = document.createElement('small');
            result.textContent = verdictLabel(answer.verdict) + ' · ' + Number(answer.awardedPoints || 0) + ' балл(а)' + (answer.judgeComment ? ' · ' + answer.judgeComment : '');
            card.append(result);
          }
          if (chgkArbiterControlsAllowed()) {
            const controls = document.createElement('div');
            controls.className = 'quiz-host-actions quiz-chgk-arbiter-actions';
            const label = document.createElement('strong');
            label.textContent = 'Решение арбитра';
            const accept = document.createElement('button');
            accept.type = 'button';
            accept.className = 'quiz-btn quiz-btn--primary';
            accept.textContent = 'Засчитать ответ';
            accept.addEventListener('click', function () { chgkResolveAnswer(answer, true); });
            const reject = document.createElement('button');
            reject.type = 'button';
            reject.className = 'quiz-btn';
            reject.textContent = 'Не засчитывать';
            reject.addEventListener('click', function () { chgkResolveAnswer(answer, false); });
            const aiButton = document.createElement('button');
            aiButton.type = 'button';
            aiButton.className = 'quiz-btn';
            aiButton.textContent = String(answer.verdict || 'pending') === 'pending' ? 'Оценить через ИИ' : 'Повторить ИИ-оценку';
            aiButton.addEventListener('click', function () { chgkAiEvaluateAnswer(answer, String(answer.verdict || 'pending') !== 'pending'); });
            controls.append(label, accept, reject, aiButton);
            card.append(controls);
          }
        }
        grid.append(card);
      });
    }

    const hostComment = document.getElementById('hostChgkHostComment');
    const hostMessage = document.getElementById('hostChgkHostMessage');
    const message = latestAiHostMessage();
    if (hostComment && hostMessage) {
      hostComment.hidden = !message;
      hostMessage.textContent = message || '—';
    }

    let hint = '';
    if (state.game.phase === 'question_open') {
      hint = answered === teams.length && teams.length ? 'Все команды зафиксировали ответы. Вопрос можно закрыть досрочно.' : 'Идёт обсуждение. Следите, какие команды уже зафиксировали финальный ответ.';
    } else if (state.game.phase === 'question_closed' && pending > 0) {
      hint = 'До перехода дальше завершите арбитраж: ожидают решения ' + pending + ' ответ(а).';
    } else if (state.game.phase === 'question_closed') {
      const atLast = Number(state.game.questionPosition) >= Number(state.game.questionCount);
      hint = atLast ? 'Все оценки завершены. Можно завершить игру.' : 'Все ответы оценены. Можно перейти к следующему вопросу.';
    }
    UI.text('hostChgkNextHint', hint);
    const answersMeta = document.getElementById('hostAnswersMeta');
    if (answersMeta) answersMeta.textContent = state.game.phase === 'question_open' ? 'Финальные ответы фиксируются командами' : (pending ? 'Есть ответы, ожидающие арбитража' : 'Арбитраж текущего вопроса завершён');
  }

  function renderAppealsAndTiebreak() {
    const panel = document.getElementById('hostChgkAppeals');
    if (!panel || !state) return;
    panel.hidden = !isChgk();
    if (!isChgk()) return;
    const appeals = Array.isArray(state.appeals) ? state.appeals : [];
    const pending = appeals.filter(function (a) { return ['submitted','reviewing'].includes(String(a.status || '')); });
    UI.text('hostAppealMeta', pending.length ? (pending.length + ' требуют решения') : 'Новых апелляций нет');
    const list = document.getElementById('hostAppealList');
    if (list) {
      list.innerHTML = '';
      if (!appeals.length) list.innerHTML = '<p class="quiz-empty">Апелляций нет.</p>';
      appeals.forEach(function (appeal) {
        const card = document.createElement('div'); card.className = 'quiz-appeal-card';
        const title = document.createElement('strong'); title.textContent = 'Команда #' + Number(appeal.teamId || 0) + ' · вопрос #' + Number(appeal.questionId || 0);
        const text = document.createElement('p'); text.textContent = appeal.appealText || '—';
        card.append(title, text);
        if (appeal.aiReview && appeal.aiReview.comment) { const ai=document.createElement('small'); ai.textContent='AI: '+appeal.aiReview.comment; card.append(ai); }
        if (['submitted','reviewing'].includes(String(appeal.status || ''))) {
          const actions=document.createElement('div'); actions.className='quiz-host-actions';
          [['review_appeal_ai','Проверить ИИ'],['accept','Засчитать'],['reject','Отклонить']].forEach(function (item) {
            const b=document.createElement('button'); b.type='button'; b.className='quiz-btn quiz-btn--small'; b.textContent=item[1];
            b.addEventListener('click', async function () {
              try { b.disabled=true; if (item[0]==='review_appeal_ai') await hostAction(item[0],{appeal_id:Number(appeal.id)}); else await hostAction('resolve_appeal',{appeal_id:Number(appeal.id),decision:item[0]==='accept'?'accepted':'rejected'}); }
              catch(e){ setStatus('hostActionStatus',e.message||'Не удалось обработать апелляцию.','error'); }
              finally { b.disabled=false; }
            }); actions.append(b);
          }); card.append(actions);
        } else { const status=document.createElement('small'); status.textContent='Решение: '+String(appeal.status||''); card.append(status); }
        list.append(card);
      });
    }
    const tb = state.tiebreak || {};
    const actions = document.getElementById('hostTiebreakActions');
    const start = document.getElementById('hostStartTiebreak');
    const shared = document.getElementById('hostFinishShared');
    if (actions) actions.hidden = !tb.required;
    if (start) start.hidden = !tb.required || !!tb.active || !!tb.exhausted || !Number(tb.nextQuestionId || 0);
    if (shared) shared.hidden = !tb.required || !tb.exhausted;
    UI.text('hostTiebreakStatus', tb.active ? 'Идёт тай-брейк. Участвуют только команды, делящие первое место.' : (tb.required ? (tb.exhausted ? 'Ничья сохраняется, резервные вопросы закончились.' : 'Обнаружена ничья за первое место. Доступен дополнительный вопрос.') : ''));
  }

  function renderComparativeAnalysis() {
    const panel = document.getElementById('hostChgkComparative');
    if (!panel || !state) return;
    panel.hidden = !isChgk();
    if (!isChgk()) return;
    const analysis = state.comparativeAnalysis || {};
    const status = String(analysis.status || 'not_started');
    const labels = {not_started:'Не запускался',pending:'В очереди',running:'Анализируется',completed:'Готов',failed:'Ошибка'};
    UI.text('hostComparativeStatus', labels[status] || status);
    UI.text('hostComparativeSummary', analysis.comparisonSummary || analysis.publicInsight || (status === 'failed' ? 'Анализ не выполнен. Игра при этом продолжает работать.' : 'Анализ появляется после завершённого разбора и не влияет на игровой счёт.'));
    const root = document.getElementById('hostComparativeTeams');
    if (root) {
      root.innerHTML = '';
      const teams = Array.isArray(analysis.teams) ? analysis.teams : [];
      const names = {}; (state.teams || []).forEach(function (t) { names[Number(t.id)] = t.name; });
      teams.forEach(function (item) {
        const card = document.createElement('div'); card.className = 'quiz-appeal-card';
        const title = document.createElement('strong'); title.textContent = (names[Number(item.teamId)] || ('Команда #' + Number(item.teamId))) + ' · ' + String(item.reasoningLevel || 'insufficient_evidence');
        const body = document.createElement('p');
        const strong = Array.isArray(item.strengths) && item.strengths.length ? ('Сильные стороны: ' + item.strengths.join('; ') + '. ') : '';
        const weak = Array.isArray(item.weaknesses) && item.weaknesses.length ? ('Наблюдения: ' + item.weaknesses.join('; ') + '.') : '';
        body.textContent = strong + weak || 'Недостаточно данных для надёжного вывода о рассуждении.';
        const evidence = document.createElement('small'); evidence.textContent = Array.isArray(item.evidence) && item.evidence.length ? ('Основание: ' + item.evidence.join('; ')) : 'Основание: недостаточно данных';
        card.append(title, body, evidence); root.append(card);
      });
      if (!teams.length) root.innerHTML = '<p class="quiz-empty">Командный анализ ещё не готов.</p>';
    }
    const meta = [];
    if (analysis.bestReasoningTeamId) meta.push('лучшее наблюдаемое рассуждение: ' + (state.teams || []).filter(function(t){return Number(t.id)===Number(analysis.bestReasoningTeamId);}).map(function(t){return t.name;})[0]);
    if (analysis.framework) meta.push('framework ' + analysis.framework + ' ' + String(analysis.frameworkVersion || ''));
    UI.text('hostComparativeMeta', meta.filter(Boolean).join(' · '));
    const run = document.getElementById('hostRunComparative');
    if (run) {
      const reviewDone = !!(state.questionFlow && state.questionFlow.reviewFinished);
      run.disabled = !reviewDone || status === 'running';
    }
  }


  function renderMethodologyAnalysis() {
    const panel = document.getElementById('hostChgkMethodology');
    if (!panel || !state) return;
    panel.hidden = !isChgk();
    if (!isChgk()) return;
    const analysis = state.methodologyAnalysis || {};
    const status = String(analysis.status || 'not_started');
    const labels = {not_started:'Не запускался',pending:'В очереди',running:'Анализируется',completed:'Готов',failed:'Ошибка',not_configured:'Нет каталога МИНД/СМКМ',disabled:'Отключён'};
    UI.text('hostMethodologyStatus', labels[status] || status);
    UI.text('hostMethodologySummary', analysis.summary || analysis.publicInsight || (status === 'not_configured' ? 'Добавьте в Базу знаний формализованный каталог МИНД/СМКМ. Неизвестные категории система не придумывает.' : 'Методологический анализ появляется после Comparative AI и не влияет на игровой счёт.'));
    const root = document.getElementById('hostMethodologyTeams');
    if (root) {
      root.innerHTML = '';
      const teams = Array.isArray(analysis.teams) ? analysis.teams : [];
      const names = {}; (state.teams || []).forEach(function (t) { names[Number(t.id)] = t.name; });
      teams.forEach(function (item) {
        const card = document.createElement('div'); card.className = 'quiz-appeal-card';
        const title = document.createElement('strong'); title.textContent = names[Number(item.teamId)] || ('Команда #' + Number(item.teamId)); card.append(title);
        const findings = Array.isArray(item.findings) ? item.findings : [];
        if (!findings.length) { const empty=document.createElement('p'); empty.textContent='Недостаточно данных для методологической классификации.'; card.append(empty); }
        findings.forEach(function (f) {
          const line=document.createElement('p');
          if (String(f.itemId || '') === '__insufficient__') line.textContent='Недостаточно данных для классификации МИНД.';
          else if (f.type === 'smkm_operation') line.textContent='СМКМ · ' + String(f.itemId || '') + ' · ' + String(f.title || '') + (f.recommendation && f.recommendation.recommendedAction ? ' — ' + f.recommendation.recommendedAction : '');
          else line.textContent='МИНД · ' + String(f.itemId || '') + ' · ' + String(f.title || '') + ' · ' + String(f.status || '');
          card.append(line);
          if (Array.isArray(f.evidence) && f.evidence.length) { const ev=document.createElement('small'); ev.textContent='Основание: ' + f.evidence.join('; '); card.append(ev); }
        });
        root.append(card);
      });
      if (!teams.length) root.innerHTML='<p class="quiz-empty">Методологический анализ ещё не готов.</p>';
    }
    const meta=[]; if (Array.isArray(analysis.sourceRefs) && analysis.sourceRefs.length) meta.push('источников БЗ: ' + analysis.sourceRefs.length); if (analysis.promptVersion) meta.push('prompt ' + analysis.promptVersion); UI.text('hostMethodologyMeta',meta.join(' · '));
    const run=document.getElementById('hostRunMethodology'); if (run) { const comp=state.comparativeAnalysis||{}; run.disabled = String(comp.status||'')!=='completed' || status==='running'; }
  }

  function renderTeams() {
    const root = document.getElementById('hostTeams');
    if (!root || !state) return;
    root.innerHTML = '';
    (state.teams || []).forEach(function (team) {
      const row = document.createElement('div');
      row.className = 'quiz-team';
      const name = document.createElement('div');
      name.className = 'quiz-team__name';
      const strong = document.createElement('strong');
      strong.textContent = team.name;
      const small = document.createElement('small');
      small.textContent = state.game.phase === 'waiting' && isChgk()
        ? (team.ready ? 'Готова' : 'Ожидает готовности')
        : (team.answeredCurrentQuestion ? 'Ответ получен' : 'Ответа нет');
      name.append(strong, small);
      const score = document.createElement('div');
      score.className = 'quiz-team__score';
      score.textContent = String(team.score);
      row.append(name, score);
      root.append(row);
    });
  }

  function renderResultSummary() {
    const card = document.getElementById('hostResultCard');
    const root = document.getElementById('hostResultStandings');
    if (!card || !root || !state) return;
    const result = state.resultSummary || {};
    const visible = isJeopardy() && !!result.finished;
    card.hidden = !visible;
    if (!visible) return;
    const winners = Array.isArray(result.winnerNames) ? result.winnerNames : [];
    UI.text('hostResultWinner', winners.length === 1 ? ('Победитель — ' + winners[0] + '.') : ('Итог: ' + (winners.length ? ('ничья между ' + winners.join(', ')) : 'игра завершена') + '.'));
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

  function scoreForm(answer) {
    const form = document.createElement('div');
    form.className = 'quiz-score-form';

    const pointsLabel = document.createElement('label');
    pointsLabel.textContent = 'Баллы';
    const points = document.createElement('input');
    points.type = 'number';
    points.step = '1';
    points.value = String((answer.verdict && answer.verdict !== 'pending') ? Number(answer.awardedPoints || 0) : (state.question ? Number(state.question.points || 0) : 0));
    pointsLabel.append(points);

    const verdictLabel = document.createElement('label');
    verdictLabel.textContent = 'Вердикт';
    const verdict = document.createElement('select');
    (isChgk() ? [
      { value:'accepted', label:'Засчитать ответ' },
      { value:'rejected', label:'Не засчитывать' }
    ] : [
      { value:'accepted', label:'Принято' },
      { value:'correct', label:'Верно' },
      { value:'partial', label:'Частично' },
      { value:'incorrect', label:'Неверно' },
      { value:'rejected', label:'Отклонено' }
    ]).forEach(function (item) {
      const option = document.createElement('option');
      option.value = item.value;
      option.textContent = item.label;
      if (item.value === (answer.verdict || 'accepted')) option.selected = true;
      verdict.append(option);
    });
    verdictLabel.append(verdict);

    const commentLabel = document.createElement('label');
    commentLabel.textContent = 'Комментарий';
    const comment = document.createElement('input');
    comment.type = 'text';
    comment.value = answer.judgeComment || '';
    commentLabel.append(comment);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'quiz-btn quiz-btn--primary';
    button.textContent = isChgk() ? 'Сохранить ручную оценку' : 'Оценить';
    button.disabled = !chgkArbiterControlsAllowed();
    button.addEventListener('click', async function () {
      try {
        button.disabled = true;
        const payload = await hostAction('score_answer', {
          answer_id: answer.id,
          points: Number(points.value) || 0,
          verdict: verdict.value,
          comment: comment.value,
          request_id: 'ui-' + answer.id + '-' + Date.now()
        });
        if (payload.state) renderState(payload.state);
        setStatus('hostActionStatus', 'Оценка сохранена.', 'success');
      } catch (error) {
        setStatus('hostActionStatus', error.message || 'Не удалось сохранить оценку.', 'error');
      } finally {
        if (chgkArbiterControlsAllowed()) button.disabled = false;
      }
    });

    if (isChgk()) {
      pointsLabel.hidden = true;
      const note = document.createElement('p');
      note.className = 'quiz-help';
      note.textContent = 'В «Битве знатоков» решение бинарное: засчитать ответ или не засчитывать. Баллы сервер выставляет сам.';
      form.append(verdictLabel, commentLabel, button, note);
    } else {
      form.append(pointsLabel, verdictLabel, commentLabel, button);
    }

    const arbitration = answer && answer.arbitration ? answer.arbitration : null;
    const aiEnabled = !!(arbitration && arbitration.required && (arbitration.mode === 'ai' || arbitration.mode === 'hybrid'));
    if (aiEnabled) {
      const aiButton = document.createElement('button');
      aiButton.type = 'button';
      aiButton.className = 'quiz-btn';
      aiButton.textContent = answer.verdict === 'pending' ? 'Запустить ИИ-оценку (ИИ)' : 'Повторить ИИ-оценку (ИИ)';
      const connector = state && state.formatRuntime && state.formatRuntime.arbitration ? state.formatRuntime.arbitration.aiConnector : '';
      aiButton.disabled = !chgkArbiterControlsAllowed() || connector !== 'ai_puffer';
      aiButton.addEventListener('click', async function () {
        const resolved = String(answer.verdict || 'pending') !== 'pending';
        if (resolved && !window.confirm('Повторная AI-оценка заменит текущий вердикт и баллы этого ответа. Продолжить?')) return;
        try {
          aiButton.disabled = true;
          setStatus('hostActionStatus', resolved ? 'ИИ повторно оценивает ответ…' : 'ИИ оценивает ответ…', '');
          const payload = await hostAction(resolved ? 'ai_rearbitrate' : 'ai_arbitrate', {
            answer_id: answer.id,
            request_id: 'ui-ai-' + answer.id + '-' + Date.now()
          });
          if (payload.state) renderState(payload.state);
          const status = payload.aiArbitration && payload.aiArbitration.status ? payload.aiArbitration.status : 'scored';
          setStatus('hostActionStatus', status === 'needs_human_review' ? 'ИИ запросил ручную проверку ответа.' : (resolved ? 'Повторная ИИ-оценка сохранена. Её можно скорректировать вручную.' : 'ИИ-арбитраж сохранён. Ручная корректировка ведущим остаётся доступной.'), status === 'needs_human_review' ? '' : 'success');
        } catch (error) {
          setStatus('hostActionStatus', (error.message || 'ИИ-арбитраж не выполнен.') + ' Можно использовать ручную оценку.', 'error');
        } finally {
          if (chgkArbiterControlsAllowed() && connector === 'ai_puffer') aiButton.disabled = false;
        }
      });
      form.append(aiButton);
    }
    return form;
  }

  function renderAnswers() {
    const root = document.getElementById('hostAnswers');
    const card = document.getElementById('hostAnswersCard');
    if (!root || !state) return;
    root.innerHTML = '';
    const finalState = jeopardyFinalState();
    if (isJeopardy() && finalState && finalState.started && state.question && String(state.question.stage || '') === 'final') {
      if (card) card.hidden = true;
      const note = document.createElement('p');
      note.className = 'quiz-empty';
      note.textContent = finalState.revealed
        ? 'Финальные ответы оцениваются кнопками «Верно / Неверно» в блоке «Финальный раунд».'
        : 'Финальные ответы скрыты до общего раскрытия и не показываются в обычной панели оценки.';
      root.append(note);
      return;
    }
    const answers = Array.isArray(state.answers) ? state.answers : [];
    if (card) card.hidden = !!(isJeopardy() && !answers.length);
    if (!answers.length) {
      if (!isJeopardy()) {
        const empty = document.createElement('p');
        empty.className = 'quiz-empty';
        empty.textContent = 'Ответов пока нет.';
        root.append(empty);
      }
      return;
    }
    answers.forEach(function (answer) {
      const card = document.createElement('div');
      card.className = 'quiz-card quiz-card--flat quiz-answer-review';
      const head = document.createElement('div');
      head.className = 'quiz-card__head';
      const title = document.createElement('h3');
      title.textContent = answer.teamName || ('Команда ' + answer.teamKey);
      const meta = document.createElement('span');
      meta.className = 'quiz-card__meta';
      meta.textContent = 'Попытка ' + answer.attempt + ' · ' + verdictLabel(answer.verdict);
      head.append(title, meta);
      const text = document.createElement('div');
      text.className = 'quiz-answer-review__text';
      text.textContent = answer.answerText || JSON.stringify(answer.answerPayload || {});
      card.append(head, text);
      const argumentation = String((answer.answerPayload || {}).argumentation || '');
      if (argumentation) {
        const rationale = document.createElement('p');
        rationale.className = 'quiz-help';
        rationale.textContent = 'Аргументация: ' + argumentation;
        card.append(rationale);
      }
      if (answer.arbitration && answer.arbitration.required) {
        const arbitration = document.createElement('p');
        arbitration.className = 'quiz-help';
        const arbMode = String(answer.arbitration.mode || 'hybrid');
        const arbStatus = String(answer.arbitration.status || 'pending');
        const resolvedBy = String(answer.arbitration.resolvedBy || '');
        arbitration.textContent = arbStatus === 'resolved'
          ? ('Арбитраж «Битвы знатоков» завершён: ' + (resolvedBy === 'ai' ? 'ИИ' : 'человек/ручная оценка') + '. Ручная корректировка ведущим остаётся доступной.')
          : ('Арбитраж «Битвы знатоков»: точность ответа + качество аргументации. Режим: ' + arbMode + '.');
        card.append(arbitration);
      }
      if (isJeopardy()) {
        const note=document.createElement('p');
        note.className='quiz-help';
        const aiState=jeopardyAiState();
        const recommendation=latestJeopardyAiForAnswer(answer.id);
        if (recommendation && !recommendation.applied) {
          note.textContent='ИИ рекомендует: '+jeopardyAiDecisionLabel(recommendation.decision)+(recommendation.confidence ? ' · '+Number(recommendation.confidence)+'%' : '')+(recommendation.comment ? ' · '+String(recommendation.comment) : '')+'. Подтвердите или переопределите результат кнопками игровой механики выше.';
        } else if (recommendation && recommendation.applied) {
          note.textContent='ИИ применил решение: '+jeopardyAiDecisionLabel(recommendation.decision)+(recommendation.comment ? ' · '+String(recommendation.comment) : '')+'.';
        } else {
          note.textContent=aiState.enabled ? 'Оценка ответа выполняется через ИИ или ручные кнопки игровой механики выше.' : 'Оцените ответ ручными кнопками игровой механики выше.';
        }
        card.append(note);
      } else {
        card.append(scoreForm(answer));
      }
      root.append(card);
    });
  }

  function renderLiveStudio() {
    const panel = document.getElementById('hostLiveStudio');
    const link = document.getElementById('hostLiveStudioLink');
    if (!panel || !link) return;
    const url = String(state && state.liveHost && state.liveHost.url || '');
    const human = !!(state && state.game && state.game.hostMode === 'human' && url);
    panel.hidden = !human;
    if (human) link.href = url; else link.removeAttribute('href');
  }

  function renderChgkHostGuide() {
    const guide = document.getElementById('hostChgkGuide');
    if (!guide) return;
    guide.hidden = !isChgk();
  }

  function updateControls() {
    if (!state) return;
    const phase = state.game.phase;
    const finished = state.game.status === 'finished' || phase === 'finished';
    const atLast = Number(state.game.questionPosition) >= Number(state.game.questionCount);
    const lobbyPreflight = state && state.lobby && state.lobby.preflight ? state.lobby.preflight : null;
    const chgkLobbyBlocked = isChgk() && phase === 'waiting' && lobbyPreflight && !lobbyPreflight.canStart;
    const jeopardy = isJeopardy();
    document.getElementById('hostStart').hidden = jeopardy;
    document.getElementById('hostNext').hidden = jeopardy;
    document.getElementById('hostStart').disabled = finished || phase !== 'waiting' || chgkLobbyBlocked || jeopardy;
    const catState=jeopardy ? jeopardyCatState() : {enabled:false,open:false};
    const auctionState=jeopardy ? jeopardyAuctionState() : {enabled:false,open:false};
    const finalState=jeopardy ? jeopardyFinalState() : {enabled:false,started:false,revealed:false,allResolved:false};
    const finalCurrent=!!(jeopardy && finalState.started && state.question && String(state.question.stage||'')==='final');
    const closeButton=document.getElementById('hostClose');
    const phaseCard=document.getElementById('hostPhaseCard');
    const phaseTitle=document.getElementById('hostPhaseTitle');
    const jeopardyCloseAction=!!(jeopardy && phase === 'question_open' && !(catState.enabled && catState.open) && !(auctionState.enabled && auctionState.open) && !finalCurrent);
    if (phaseCard) phaseCard.hidden = !!(jeopardy && !jeopardyCloseAction);
    if (phaseTitle) phaseTitle.textContent = jeopardy ? 'Текущее действие' : 'Управление фазой';
    if (closeButton) {
      closeButton.hidden = !!(jeopardy && !jeopardyCloseAction);
      closeButton.disabled = finished || phase !== 'question_open' || !!(catState.enabled && catState.open) || !!(auctionState.enabled && auctionState.open) || finalCurrent;
    }
    const chgkArbitrationPending = isChgk() && phase === 'question_closed' && chgkPendingCount() > 0;
    const qFlow = state.questionFlow || {};
    const reviewStart = document.getElementById('hostStartReview');
    const reviewFinish = document.getElementById('hostFinishReview');
    if (reviewStart) { reviewStart.hidden = !isChgk() || !qFlow.reviewEnabled || qFlow.reviewStarted || qFlow.reviewFinished; reviewStart.disabled = finished || !qFlow.canStartReview; }
    if (reviewFinish) { reviewFinish.hidden = !isChgk() || !qFlow.reviewStarted || qFlow.reviewFinished; reviewFinish.disabled = finished || !qFlow.canFinishReview; }
    const reviewBlocksNext = isChgk() && ((qFlow.reviewStarted && !qFlow.reviewFinished) || (qFlow.reviewRequired && !qFlow.reviewFinished));
    document.getElementById('hostNext').disabled = finished || phase !== 'question_closed' || atLast || chgkArbitrationPending || reviewBlocksNext || jeopardy;
    const finishButton=document.getElementById('hostFinish');
    if (finishButton) {
      finishButton.hidden = jeopardy;
      finishButton.disabled = finished || (jeopardy && finalState.enabled && (!finalState.started || !finalState.allResolved));
    }
    const emergencyFinish=document.getElementById('hostJeopardyEmergencyFinish');
    if (emergencyFinish) emergencyFinish.disabled=finished;
    const messageCard=document.getElementById('hostMessageCard');
    if (messageCard) messageCard.hidden = !!(jeopardy && state.game.hostMode !== 'human');
    const publishButton=document.getElementById('hostPublishMessage');
    if (publishButton) publishButton.disabled = finished || state.game.hostMode !== 'human';
  }

  function renderQuestion() {
    const q = state && state.question;
    const g = state && state.game ? state.game : {};
    const counter = q && String(q.stage || 'main') === 'final' ? 'Интеллектуальный батл · Финальный раунд' : ((((state.formatRuntime && state.formatRuntime.key === 'chgk') ? 'Битва знатоков · ' : '') + 'Вопрос ' + g.questionPosition + ' из ' + g.questionCount));
    UI.text('hostQuestionCounter', q ? counter : 'Вопрос ещё не открыт');
    UI.text('hostQuestionText', q ? q.text : (g.status === 'finished' ? 'Игра завершена.' : 'Ожидайте старта.'));
    UI.renderQuestionMedia('hostQuestionMedia', q);
    const correct = q && Array.isArray(q.correctAnswers) ? q.correctAnswers.join(', ') : '';
    UI.show('hostCorrectBlock', !!q);
    UI.text('hostCorrectAnswer', correct || '—');
    UI.text('hostExplanation', q && q.explanation ? q.explanation : '');
  }

  function renderState(nextState) {
    state = nextState;
    UI.appendEvents(events, state.events, 160);
    UI.renderTestBadge(!!state.game.testMode);
    clock.sync(state);
    UI.text('hostGameCode', state.game.code || game);
    UI.text('hostTitle', state.game.title || 'Квиз');
    UI.text('hostPhaseBadge', (state.formatRuntime && state.formatRuntime.key === 'chgk' && state.questionFlow && state.questionFlow.phase) ? ({open:'Обсуждение',arbitrating:'Арбитраж',closed:'Готов к разбору',review:'Разбор',completed:'Разбор завершён'}[state.questionFlow.phase] || UI.phaseLabel(state.game.phase, state.game.status)) : UI.phaseLabel(state.game.phase, state.game.status));
    UI.text('hostSyncMeta', 'Состояние ' + state.game.stateVersion);
    const chgk = !!(state.formatRuntime && state.formatRuntime.key === 'chgk');
    const arbNotice = document.getElementById('hostArbitrationNotice');
    if (arbNotice) {
      arbNotice.hidden = !chgk;
      if (chgk) {
        const aiState = state.formatRuntime && state.formatRuntime.arbitration ? state.formatRuntime.arbitration : {};
        arbNotice.textContent = aiState.autoEnabled
          ? (aiState.aiConnector === 'ai_puffer' ? 'Битва знатоков: после закрытия вопроса ИИ автоматически оценивает точность ответа и аргументацию. Ведущий может повторить ИИ-оценку для оставшегося pending-ответа или изменить результат вручную.' : 'Битва знатоков: выбран ИИ/гибридный арбитраж, но ИИ сейчас недоступен. Ответы останутся pending для ручной оценки ведущим.')
          : 'Битва знатоков: выбран арбитр-человек. После закрытия вопроса оцените финальные ответы вручную.';
      }
    }
    if (state.game.hostMode !== 'human') {
      if (chgk) {
        const hostState = state.formatRuntime && state.formatRuntime.host ? state.formatRuntime.host : {};
        UI.text('hostSubtitle', hostState.liveAI ? 'Битва знатоков: текстовый ИИ-ведущий работает через ИИ. Вопрос публикуется дословно, а вступления, переходы и комментарии генерируются по текущему состоянию игры.' : 'Битва знатоков: выбран ИИ-ведущий, но ИИ сейчас недоступен. Игра продолжится с безопасными резервными текстами; состояние и таймеры не зависят от внешнего ИИ.');
      } else if (isJeopardy()) {
        const hostState = state.formatRuntime && state.formatRuntime.host ? state.formatRuntime.host : {};
        UI.text('hostSubtitle', hostState.liveAI ? 'Интеллектуальный батл: ИИ ведёт игру текстом, объявляет вопросы и комментирует решения. Арбитраж ответа настраивается отдельно: ИИ, человек или гибрид.' : 'Интеллектуальный батл: выбран ИИ-ведущий, но ИИ сейчас недоступен. Игра продолжится с безопасным fallback и ручным арбитражем.');
      } else {
        UI.text('hostSubtitle', 'Комната настроена не в режиме голосового ведущего; публикация сообщений этой панели отключена.');
      }
    } else if (chgk) {
      UI.text('hostSubtitle', 'Битва знатоков: голосовой ведущий говорит командам через микрофон и управляет обсуждением. Арбитр настраивается отдельно.');
    } else if (isJeopardy()) {
      UI.text('hostSubtitle', 'Интеллектуальный батл: выберите команду с правом выбора и откройте вопрос нажатием на доступный номинал игровой доски.');
    }
    renderQuestion();
    renderTeams();
    renderResultSummary();
    renderChgkLobby();
    renderJeopardyBoard();
    renderJeopardyAuction();
    renderJeopardyCat();
    renderJeopardyBuzzer();
    renderJeopardyWager();
    renderJeopardyFinal();
    renderJeopardyAi();
    renderChgkHostPanel();
    renderAppealsAndTiebreak();
    renderComparativeAnalysis();
    renderMethodologyAnalysis();
    renderAnswers();
    renderLiveStudio();
    renderChgkHostGuide();
    updateControls();
    applyHostRoleLayout();
  }

  async function hostAction(action, extra) {
    const body = Object.assign({
      game: game,
      command: action,
      action: action,
      host_token: hostToken,
      nonce: hostNonce,
      last_event_id: poller ? poller.lastEventId : 0
    }, extra || {});
    const payload = await UI.postJson('api/quiz-host-action.php', body, hostToken, hostNonce);
    if (payload.state) renderState(payload.state);
    if (poller) poller.refreshNow();
    return payload;
  }

  async function runAction(action, successText) {
    try {
      setStatus('hostActionStatus', 'Выполняется…', '');
      await hostAction(action);
      setStatus('hostActionStatus', successText, 'success');
    } catch (error) {
      setStatus('hostActionStatus', error.message || 'Действие не выполнено.', 'error');
    }
  }

  const preflightButton = document.getElementById('hostPreflight');
  if (preflightButton) preflightButton.addEventListener('click', function () { runAction('preflight', 'Проверка готовности обновлена.'); });
  document.getElementById('hostStart').addEventListener('click', function () { runAction('start', 'Первый вопрос открыт.'); });
  document.getElementById('hostClose').addEventListener('click', function () { runAction('finish_discussion', 'Обсуждение завершено.'); });
  const startReviewButton = document.getElementById('hostStartReview');
  if (startReviewButton) startReviewButton.addEventListener('click', function () { runAction('start_review', 'Разбор вопроса открыт.'); });
  const finishReviewButton = document.getElementById('hostFinishReview');
  if (finishReviewButton) finishReviewButton.addEventListener('click', function () { runAction('finish_review', 'Разбор вопроса завершён.'); });
  document.getElementById('hostNext').addEventListener('click', function () { runAction('next_question', 'Следующий вопрос открыт.'); });
  document.getElementById('hostFinish').addEventListener('click', function () { runAction('finish', 'Игра завершена.'); });
  const emergencyFinish=document.getElementById('hostJeopardyEmergencyFinish');
  if (emergencyFinish) emergencyFinish.addEventListener('click', function () {
    if (!window.confirm('Завершить игру досрочно? Если обязательный финал ещё не проведён, сервер может отклонить действие.')) return;
    runAction('finish', 'Игра завершена.');
  });
  const runComparative = document.getElementById('hostRunComparative');
  if (runComparative) runComparative.addEventListener('click', function () { runAction('run_comparative_analysis', 'Сравнительный анализ обновлён.'); });
  const runMethodology = document.getElementById('hostRunMethodology');
  if (runMethodology) runMethodology.addEventListener('click', function () { runAction('run_methodology_analysis', 'Методологический анализ обновлён.'); });


  async function saveChgkEarlySeconds() {
    const select = document.getElementById('hostChgkEarlySeconds');
    const seconds = Math.max(10, Math.min(60, Number(select && select.value || 20)));
    try {
      setStatus('hostActionStatus', 'Сохраняем время «Досрочного ответа»…', '');
      await hostAction('set_early_answer_seconds', { early_answer_seconds: seconds });
      setStatus('hostActionStatus', 'Время «Досрочного ответа» сохранено: ' + seconds + ' сек.', 'success');
    } catch (error) {
      setStatus('hostActionStatus', error && error.message ? error.message : 'Не удалось сохранить время «Досрочного ответа».', 'error');
    }
  }

  const saveEarlyButton = document.getElementById('hostSaveEarlySeconds');
  if (saveEarlyButton) saveEarlyButton.addEventListener('click', saveChgkEarlySeconds);
  const earlySecondsSelect = document.getElementById('hostChgkEarlySeconds');
  if (earlySecondsSelect) earlySecondsSelect.addEventListener('change', saveChgkEarlySeconds);

  const auctionAssignButton=document.getElementById('hostAuctionAssign');
  if (auctionAssignButton) auctionAssignButton.addEventListener('click',assignJeopardyAuction);
  const auctionAcceptButton=document.getElementById('hostAuctionAccept');
  if (auctionAcceptButton) auctionAcceptButton.addEventListener('click',function(){resolveJeopardyAuction('accepted');});
  const auctionRejectButton=document.getElementById('hostAuctionReject');
  if (auctionRejectButton) auctionRejectButton.addEventListener('click',function(){resolveJeopardyAuction('rejected');});

  const jeopardySelectorButton = document.getElementById('hostJeopardySetSelector');
  if (jeopardySelectorButton) jeopardySelectorButton.addEventListener('click', transferJeopardySelector);
  const jeopardyAiButton=document.getElementById('hostJeopardyAiEvaluate');
  if (jeopardyAiButton) jeopardyAiButton.addEventListener('click',function(){ const answer=jeopardyCurrentAnswer(); requestJeopardyAi(answer ? answer.id : 0); });
  document.getElementById('hostFinalStart')?.addEventListener('click',startJeopardyFinal);
  document.getElementById('hostFinalReveal')?.addEventListener('click',revealJeopardyFinal);
  document.getElementById('hostFinalFinish')?.addEventListener('click',finishJeopardyFinal);
  const buzzAccept=document.getElementById('hostBuzzAccept');
  const buzzReject=document.getElementById('hostBuzzReject');
  if (buzzAccept) buzzAccept.addEventListener('click',function(){resolveJeopardyBuzz('accepted');});
  if (buzzReject) buzzReject.addEventListener('click',function(){resolveJeopardyBuzz('rejected');});

  const catAssign=document.getElementById('hostCatAssign');
  const catAccept=document.getElementById('hostCatAccept');
  const catReject=document.getElementById('hostCatReject');
  if (catAssign) catAssign.addEventListener('click',assignJeopardyCat);
  if (catAccept) catAccept.addEventListener('click',function(){resolveJeopardyCat('accepted');});
  if (catReject) catReject.addEventListener('click',function(){resolveJeopardyCat('rejected');});

  document.getElementById('hostPublishMessage').addEventListener('click', async function () {
    const textarea = document.getElementById('hostMessageText');
    const value = String(textarea.value || '').trim();
    if (!value) {
      setStatus('hostMessageStatus', 'Введите сообщение.', 'error');
      return;
    }
    try {
      this.disabled = true;
      await hostAction('publish_message', { text: value });
      textarea.value = '';
      setStatus('hostMessageStatus', 'Сообщение опубликовано.', 'success');
    } catch (error) {
      setStatus('hostMessageStatus', error.message || 'Не удалось опубликовать сообщение.', 'error');
    } finally {
      updateControls();
    }
  });

  function boot() {
    UI.text('hostGameCode', game || 'Квиз');
    if (!game || !hostToken || !hostNonce) {
      UI.setConnection('error', 'Неполная ссылка ведущего');
      UI.text('hostTitle', 'Не удалось открыть панель');
      UI.text('hostSubtitle', 'В ссылке должны быть game, token и nonce.');
      updateControls();
      return;
    }
    poller = new UI.StatePoller({
      game: game,
      token: hostToken,
      nonce: hostNonce,
      onState: renderState,
      onError: function (error) {
        if (error && error.status === 403) UI.text('hostSubtitle', 'Токен или nonce ведущего недействителен. Откройте исходную ссылку заново.');
      }
    });
    poller.start();
  }

  boot();
})();
