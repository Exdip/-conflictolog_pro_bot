(function () {
  'use strict';

  const UI = window.CKMQuizUI;
  if (!UI) return;
  UI.initTheme();

  const params = UI.query();
  const game = String(params.get('game') || '').toUpperCase();
  const storagePrefix = 'ckm.quiz.scoreboard.' + game + '.';
  const screenToken = UI.sessionSecret(storagePrefix + 'token', ['token', 'screen_token', 'screenToken']);
  const screenNonce = UI.sessionSecret(storagePrefix + 'nonce', ['nonce']);
  UI.scrubSecrets(['token', 'screen_token', 'screenToken', 'nonce']);

  let state = null;
  let poller = null;
  let latestMessage = '';
  let latestAiHostMessage = '';
  const events = [];
  const PROJECTOR_KEY = 'ckm.quiz.projector';

  function applyProjector(enabled, persist) {
    document.documentElement.setAttribute('data-projector', enabled ? 'true' : 'false');
    const button = document.getElementById('projectorToggle');
    if (button) {
      button.textContent = enabled ? 'Обычный режим' : 'Режим проектора';
      button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    }
    if (persist !== false) localStorage.setItem(PROJECTOR_KEY, enabled ? '1' : '0');
  }

  const requestedProjector = params.get('projector');
  applyProjector(requestedProjector === '1' || (requestedProjector == null && localStorage.getItem(PROJECTOR_KEY) === '1'), false);
  document.getElementById('projectorToggle').addEventListener('click', function () {
    applyProjector(document.documentElement.getAttribute('data-projector') !== 'true', true);
  });

  const clock = new UI.ServerClock(function (seconds) {
    UI.text('scoreTimer', UI.formatSeconds(seconds));
    const wrap = document.getElementById('scoreTimerWrap');
    if (wrap) wrap.classList.toggle('is-low', seconds > 0 && seconds <= 10);
  });

  function isChgk() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'chgk');
  }

  function verdictLabel(verdict) {
    const value = String(verdict || '').toLowerCase();
    if (value === 'accepted' || value === 'correct') return 'Принято';
    if (value === 'rejected' || value === 'incorrect') return 'Не принято';
    if (value === 'partial') return 'Частично';
    if (value === 'no_answer') return 'Нет ответа';
    return 'Ожидает решения';
  }

  function judgeLabel(mode) {
    const value = String(mode || '').toLowerCase();
    if (value === 'ai') return 'ИИ-арбитр';
    if (value === 'human') return 'Ведущий';
    if (value === 'hybrid') return 'Гибрид';
    return 'Арбитр';
  }

  function arbitrationByTeam() {
    const map = {};
    ((state && state.publicArbitration) || []).forEach(function (item) {
      map[Number(item.teamId)] = item;
    });
    return map;
  }

  function isJeopardy() {
    return !!(state && state.formatRuntime && state.formatRuntime.key === 'jeopardy');
  }

  function renderJeopardyBoard() {
    const card = document.getElementById('scoreJeopardyCard');
    if (!card) return;
    const active = isJeopardy();
    card.hidden = !active;
    if (!active) return;
    const board = state && state.jeopardyBoard ? state.jeopardyBoard : { enabled: false };
    const selectorId = Number(board.selectorTeamId || 0);
    const selector = (state.teams || []).find(function (x) { return Number(x.id || 0) === selectorId; }) || null;
    UI.text('scoreJeopardyStatus', selector ? ('Выбирает: ' + String(selector.name || 'команда')) : 'Ожидание выбора');
    const buzzer=state && state.formatRuntime ? state.formatRuntime.buzzer : null;
    const buzzWrap=document.getElementById('scoreJeopardyBuzzer');
    if (buzzWrap) {
      const showBuzz=!!(buzzer && buzzer.enabled); buzzWrap.hidden=!showBuzz;
      if (showBuzz) {
        const claim=buzzer.active && typeof buzzer.active==='object' ? buzzer.active : null;
        const buzzTeam=claim ? (state.teams||[]).find(function(x){return Number(x.id||0)===Number(claim.teamId||0);}) : null;
        UI.text('scoreBuzzStatus',claim ? ('Отвечает: '+String(buzzTeam && buzzTeam.name || 'команда')) : 'Кнопка свободна');
        UI.text('scoreBuzzTimer',claim ? ('На ответ '+UI.formatSeconds(Number(claim.secondsRemaining||0))) : 'Ждём первое нажатие');
      }
    }
    const wager=state && state.formatRuntime ? state.formatRuntime.wager : null;
    const wagerWrap=document.getElementById('scoreJeopardyWager');
    if (wagerWrap) {
      const rows=wager && Array.isArray(wager.rows) ? wager.rows : [];
      wagerWrap.hidden=!(isJeopardy() && wager && wager.enabled && rows.length);
      if (!wagerWrap.hidden) {
        const labels=rows.map(function(row){
          const team=(state.teams||[]).find(function(x){ return Number(x.id||0)===Number(row.teamId||0); });
          const outcome=String(row.outcome||'');
          const suffix=outcome==='won' ? ' +'+Number(row.amount||0) : (outcome==='lost' ? ' −'+Number(row.amount||0) : ' '+Number(row.amount||0));
          return String(team && team.name || 'Команда')+':'+suffix;
        });
        UI.text('scoreWagerStatus',labels.join(' · '));
      }
    }
    const auction=state && state.formatRuntime ? state.formatRuntime.auction : null;
    const auctionWrap=document.getElementById('scoreJeopardyAuction');
    if (auctionWrap) {
      auctionWrap.hidden=!(isJeopardy() && auction && auction.enabled);
      if (!auctionWrap.hidden) {
        const winner=(state.teams||[]).find(function(x){ return Number(x.id||0)===Number(auction.winnerTeamId||0); });
        const text=String(auction.status||'')==='pending_bid'
          ? 'Открыта специальная ячейка · идут торги'
          : (String(auction.status||'')==='assigned' ? ('Победитель: '+String(winner && winner.name || 'команда')+' · цена '+Number(auction.bid||0)) : ('Торги завершены · '+Number(auction.bid||0)));
        UI.text('scoreAuctionStatus',text);
      }
    }
    const cat=state && state.formatRuntime ? state.formatRuntime.catInBag : null;
    const catWrap=document.getElementById('scoreJeopardyCat');
    if (catWrap) {
      catWrap.hidden=!(isJeopardy() && cat && cat.enabled);
      if (!catWrap.hidden) {
        const target=(state.teams||[]).find(function(x){ return Number(x.id||0)===Number(cat.targetTeamId||0); });
        const text=String(cat.status||'')==='pending_target'
          ? 'Выбрана секретная ячейка · ведущий назначает команду'
          : (String(cat.status||'')==='assigned' ? ('Отвечает: '+String(target && target.name || 'назначенная команда')) : 'Спецвопрос завершён');
        UI.text('scoreCatStatus',text);
      }
    }
    const ai=state && state.formatRuntime ? state.formatRuntime.aiArbitration : null;
    const aiWrap=document.getElementById('scoreJeopardyAi');
    if (aiWrap) {
      aiWrap.hidden=!(isJeopardy() && ai && ai.enabled);
      if (!aiWrap.hidden) {
        const pendingCellTransition=!!(board && Number(board.selectedQuestionId||0)>0 && (!state || !state.game || String(state.game.phase||'')!=='question_open'));
        const lastAi=pendingCellTransition ? null : ai.last;
        let text=ai.mode === 'ai' ? 'Автоарбитраж включён' : 'Гибридный арбитраж';
        if (!ai.configured) text='Ручной fallback';
        else if (lastAi && lastAi.status === 'pending_review') text='Решение проверяет ведущий';
        else if (lastAi && lastAi.status === 'failed') text='ИИ недоступен · решение ведущего';
        else if (lastAi && lastAi.status === 'applied') text=String(lastAi.decision||'') === 'accepted' ? 'ИИ: ответ принят' : 'ИИ: ответ отклонён';
        UI.text('scoreJeopardyAiStatus',text);
      }
    }
    const finalState=state && state.formatRuntime ? state.formatRuntime.finalRound : null;
    const finalWrap=document.getElementById('scoreJeopardyFinal');
    if (finalWrap) {
      finalWrap.hidden=!(isJeopardy() && finalState && finalState.enabled && finalState.started);
      if (!finalWrap.hidden) {
        const text=!finalState.revealed
          ? ('Закрытый финал · ответов '+Number(finalState.submittedCount||0)+'/'+Number(finalState.teamCount||0)+' · верный ответ = '+Number(finalState.fixedPoints||500)+' баллов')
          : ('Ответы раскрыты · решений '+Number(finalState.resolvedCount||0)+'/'+Number(finalState.teamCount||0));
        UI.text('scoreFinalStatus',text);
        const rows=document.getElementById('scoreFinalRows');
        if (rows) {
          rows.innerHTML='';
          if (finalState.revealed) (Array.isArray(finalState.rows)?finalState.rows:[]).forEach(function(row){
            const div=document.createElement('div'); div.className='quiz-jeopardy-final__row';
            div.textContent=String(row.teamName||'Команда')+' · '+(row.answerSubmitted?('«'+String(row.answerText||'')+'»'):'нет ответа')+(row.resolved?' · '+String(row.verdict||''):'');
            rows.appendChild(div);
          });
        }
      }
    }
    if (UI.renderJeopardyBoard) UI.renderJeopardyBoard('scoreJeopardyBoard', board, {
      teams: state.teams || [],
      interactive: false,
      hint: selector ? ('Право выбора: ' + String(selector.name || 'команда')) : 'Право выбора ещё не назначено.'
    });
  }

  function renderChgkContext() {
    const chgk = isChgk();
    UI.show('scoreChgkContext', chgk);
    UI.text('scoreTimerLabel', chgk ? 'Обсуждение' : 'Осталось');
    if (!chgk) return;
    const round = state.round || {};
    const roundTitle = String(round.title || state.question && state.question.roundTitle || '').trim();
    const roundPosition = Number(state.game.currentRoundPosition || 0);
    UI.text('scoreRoundBadge', roundTitle || (roundPosition > 0 ? ('Раунд ' + roundPosition) : 'Раунд'));
    UI.text('scoreDiscussionBadge', state.game.phase === 'question_open' ? 'Обсуждение' : (state.game.status === 'finished' ? 'Игра завершена' : 'Обсуждение завершено'));
  }

  function renderChgkArbitration() {
    const box = document.getElementById('scoreChgkArbitration');
    const root = document.getElementById('scoreArbitrationGrid');
    if (!box || !root || !state) return;
    const visible = isChgk() && state.game.phase !== 'question_open' && !!state.question;
    box.hidden = !visible;
    root.innerHTML = '';
    if (!visible) return;
    const rows = Array.isArray(state.publicArbitration) ? state.publicArbitration : [];
    let pending = 0;
    rows.forEach(function (item) {
      if (item.status === 'pending') pending += 1;
      const card = document.createElement('article');
      card.className = 'quiz-chgk-scoreboard__arbitration-card';
      if (item.status === 'resolved') card.classList.add('is-resolved');
      if (item.verdict === 'accepted' || item.verdict === 'correct') card.classList.add('is-accepted');
      if (item.verdict === 'rejected' || item.verdict === 'incorrect') card.classList.add('is-rejected');
      const title = document.createElement('h3');
      title.textContent = item.teamName || 'Команда';
      const verdict = document.createElement('strong');
      verdict.className = 'quiz-chgk-scoreboard__verdict';
      verdict.textContent = verdictLabel(item.verdict);
      const meta = document.createElement('div');
      meta.className = 'quiz-chgk-scoreboard__arbitration-meta';
      if (item.status === 'resolved') meta.textContent = judgeLabel(item.judgeMode) + ' · ' + Number(item.points || 0) + ' балл(а)';
      else if (item.status === 'no_answer') meta.textContent = 'Финальный ответ не был зафиксирован';
      else meta.textContent = 'Решение арбитра ещё не получено';
      card.append(title, verdict, meta);
      const commentText = String(item.judgeComment || '').trim();
      if (commentText && item.status === 'resolved') {
        const comment = document.createElement('p');
        comment.textContent = commentText;
        card.append(comment);
      }
      root.append(card);
    });
    UI.text('scoreArbitrationStatus', rows.length === 0 ? 'Нет ответов' : (pending > 0 ? ('Ожидается: ' + pending) : 'Арбитраж завершён'));
  }

  function renderChgkHostMessage() {
    const box = document.getElementById('scoreChgkHost');
    if (!box) return;
    const board=state && state.jeopardyBoard ? state.jeopardyBoard : null;
    const pendingCellTransition=!!(isJeopardy() && board && Number(board.selectedQuestionId||0)>0 && (!state || !state.game || String(state.game.phase||'')!=='question_open'));
    const visible = !pendingCellTransition && (isChgk() || isJeopardy()) && latestAiHostMessage !== '';
    box.hidden = !visible;
    if (visible) UI.text('scoreChgkHostMessage', latestAiHostMessage);
  }

  function renderTeams() {
    const root = document.getElementById('scoreTeams');
    if (!root || !state) return;
    root.innerHTML = '';
    const teams = (state.teams || []).slice().sort(function (a, b) {
      return Number(b.score) - Number(a.score) || Number(a.slot) - Number(b.slot);
    });
    const topScore = teams.length ? Number(teams[0].score) : null;
    teams.forEach(function (team) {
      const tile = document.createElement('article');
      tile.className = 'quiz-score-tile';
      if (topScore != null && Number(team.score) === topScore) tile.classList.add('is-leading');
      if (team.answeredCurrentQuestion) tile.classList.add('is-answered');
      const title = document.createElement('h2');
      title.textContent = team.name;
      const score = document.createElement('div');
      score.className = 'quiz-score-tile__score';
      score.textContent = String(team.score);
      const meta = document.createElement('div');
      meta.className = 'quiz-score-tile__meta';
      if (isChgk()) {
        const arbitration = arbitrationByTeam()[Number(team.id)] || null;
        if (state.game.phase === 'waiting') {
          meta.textContent = team.ready ? 'Команда готова' : 'Ожидаем готовность';
        } else if (state.game.phase === 'question_open') {
          meta.textContent = team.answeredCurrentQuestion ? 'Финальный ответ зафиксирован' : 'Команда обсуждает';
        } else if (arbitration) {
          meta.textContent = arbitration.status === 'resolved'
            ? (verdictLabel(arbitration.verdict) + ' · ' + Number(arbitration.points || 0) + ' балл(а)')
            : (arbitration.status === 'no_answer' ? 'Нет финального ответа' : 'Ожидается решение арбитра');
        } else {
          meta.textContent = 'Счёт команды';
        }
      } else {
        meta.textContent = state.game.status === 'finished'
          ? ('Основные: ' + Number(team.baseScore || 0) + ' · Скорость: +' + Number(team.speedBonus || 0))
          : (state.game.phase === 'question_open'
            ? (team.answeredCurrentQuestion ? 'Ответ принят' : 'Ожидаем ответ')
            : 'Счёт команды');
      }
      tile.append(title, score, meta);
      root.append(tile);
    });
  }

  function renderComparativeInsight() {
    const box = document.getElementById('scoreChgkAnalysis');
    const text = document.getElementById('scoreChgkAnalysisText');
    if (!box || !text || !state) return;
    const methodology = state.methodologyAnalysis || {};
    const comparative = state.comparativeAnalysis || {};
    const useMethodology = String(methodology.status || '') === 'completed' && String(methodology.publicInsight || '').trim();
    const insight = String(useMethodology ? methodology.publicInsight : (comparative.publicInsight || '')).trim();
    const ready = useMethodology || String(comparative.status || '') === 'completed';
    box.hidden = !isChgk() || !ready || !insight;
    text.textContent = insight || 'Сравнительный вывод появится после завершённого разбора.';
  }

  function renderFinalResult() {
    const box = document.getElementById('scoreFinalResult');
    if (!box || !state || !state.game) return;
    const teams = (state.teams || []).slice().sort(function (a, b) {
      return Number(b.score) - Number(a.score) || Number(a.slot) - Number(b.slot);
    });
    const finished = state.game.status === 'finished';
    if (!finished || !teams.length) {
      box.hidden = true;
      return;
    }

    const topScore = Number(teams[0].score);
    const winners = teams.filter(function (team) { return Number(team.score) === topScore; });
    if (winners.length === 1) {
      UI.text('scoreWinnerText', 'Победитель — ' + winners[0].name);
    } else {
      UI.text('scoreWinnerText', 'Ничья');
    }
    UI.text('scoreWinnerMeta', teams.map(function (team) {
      return isChgk()
        ? (team.name + ': ' + Number(team.score || 0) + ' балл(а)')
        : (team.name + ': основные ' + Number(team.baseScore || 0) + ' · скорость +' + Number(team.speedBonus || 0) + ' · итого ' + Number(team.score || 0));
    }).join('; ') + '.');
    box.hidden = false;
  }

  function updateLatestMessage() {
    events.forEach(function (event) {
      const message = UI.eventMessage(event);
      if (message) latestMessage = message;
      if (event && event.action === 'ai_host_message' && message) latestAiHostMessage = message;
    });
    UI.text('scoreMessage', latestMessage || '—');
    renderChgkHostMessage();
  }

  function renderState(nextState) {
    state = nextState;
    UI.appendEvents(events, state.events, 120);
    UI.renderTestBadge(!!state.game.testMode);
    clock.sync(state);
    UI.text('scoreGameCode', state.game.code || game);
    UI.text('scoreTitle', state.game.title || 'Квиз');
    UI.text('scorePhaseBadge', UI.phaseLabel(state.game.phase, state.game.status));
    const chgk = isChgk();
    document.getElementById('scoreboardRoot').classList.toggle('quiz-scoreboard--chgk', chgk);
    document.getElementById('scoreboardRoot').classList.toggle('quiz-scoreboard--jeopardy', isJeopardy());
    const counter = (chgk ? '«Битва знатоков» · вопрос ' : 'Вопрос ') + state.game.questionPosition + ' из ' + state.game.questionCount;
    UI.text('scoreQuestionCounter', state.question ? counter : UI.phaseLabel(state.game.phase, state.game.status));
    UI.text('scoreQuestionText', state.question ? state.question.text : (state.game.status === 'finished' ? 'Игра завершена.' : 'Ожидаем первый вопрос.'));
    UI.renderQuestionMedia('scoreQuestionMedia', state.question);
    renderChgkContext();
    renderJeopardyBoard();
    renderChgkArbitration();
    renderComparativeInsight();
    renderFinalResult();
    renderTeams();
    updateLatestMessage();
  }

  function boot() {
    UI.text('scoreGameCode', game || 'Квиз');
    if (!game || !screenToken || !screenNonce) {
      UI.setConnection('error', 'Неполная ссылка табло');
      UI.text('scoreTitle', 'Табло не подключено');
      UI.text('scoreQuestionText', 'В ссылке должны быть game, token и nonce.');
      return;
    }
    poller = new UI.StatePoller({
      game: game,
      token: screenToken,
      nonce: screenNonce,
      onState: renderState,
      onError: function (error) {
        if (error && error.status === 403) UI.text('scoreQuestionText', 'Токен или nonce табло недействителен. Откройте исходную ссылку заново.');
      }
    });
    poller.start();
  }

  boot();
})();
