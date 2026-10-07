(function () {
  'use strict';

  const THEME_KEY = 'ckm.platform.theme';
  const LEGACY_THEME_KEY = 'ckm.quiz.theme';
  const PLATFORM_THEME_KEYS = [LEGACY_THEME_KEY, 'ckm_theme', 'ckm-theme', 'theme', 'color-theme'];
  const POLL_INTERVAL_MS = 2500;
  const EVENT_PAGE_SIZE = 250;

  function normalizeTheme(value) {
    const v = String(value || '').toLowerCase();
    return v === 'dark' ? 'dark' : (v === 'light' ? 'light' : '');
  }

  function currentPlatformTheme() {
    const own = normalizeTheme(localStorage.getItem(THEME_KEY));
    if (own) return own;
    for (const key of PLATFORM_THEME_KEYS) {
      const value = normalizeTheme(localStorage.getItem(key));
      if (value) return value;
    }
    const htmlTheme = normalizeTheme(document.documentElement.getAttribute('data-theme'));
    if (htmlTheme) return htmlTheme;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(theme, persist) {
    const resolved = normalizeTheme(theme) || currentPlatformTheme();
    document.documentElement.setAttribute('data-theme', resolved);
    if (persist !== false) {
      localStorage.setItem(THEME_KEY, resolved);
      localStorage.setItem(LEGACY_THEME_KEY, resolved);
    }
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      button.textContent = resolved === 'dark' ? 'Светлая тема' : 'Тёмная тема';
      button.setAttribute('aria-pressed', resolved === 'dark' ? 'true' : 'false');
    });
    return resolved;
  }

  function initTheme() {
    applyTheme(currentPlatformTheme(), false);
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        const current = normalizeTheme(document.documentElement.getAttribute('data-theme')) || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark', true);
      });
    });
  }

  function query() {
    return new URLSearchParams(window.location.search);
  }

  function scrubSecrets(names) {
    const url = new URL(window.location.href);
    let changed = false;
    names.forEach(function (name) {
      if (url.searchParams.has(name)) {
        url.searchParams.delete(name);
        changed = true;
      }
    });
    if (changed && window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
    }
  }

  function sessionSecret(storageKey, names) {
    const params = query();
    let value = '';
    for (const name of names) {
      const candidate = params.get(name);
      if (candidate) {
        value = candidate;
        break;
      }
    }
    if (value) sessionStorage.setItem(storageKey, value);
    return value || sessionStorage.getItem(storageKey) || '';
  }

  function text(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value == null ? '' : String(value);
  }

  function show(id, visible) {
    const node = document.getElementById(id);
    if (node) node.hidden = !visible;
  }

  function setConnection(status, message) {
    const node = document.getElementById('quizConnection');
    if (!node) return;
    node.classList.remove('is-online', 'is-error', 'is-warning');
    if (status === 'online') node.classList.add('is-online');
    if (status === 'error') node.classList.add('is-error');
    if (status === 'warning') node.classList.add('is-warning');
    node.textContent = message || (status === 'online' ? 'Синхронизировано' : 'Нет связи');
  }

  function renderTestBadge(testMode) {
    document.querySelectorAll('[data-test-badge]').forEach(function (node) {
      node.hidden = !testMode;
    });
  }

  async function parseJsonResponse(response) {
    let payload = {};
    try { payload = await response.json(); } catch (e) { payload = {}; }
    if (!response.ok || payload.ok === false) {
      const err = new Error(payload.error || ('HTTP ' + response.status));
      err.status = response.status;
      err.code = payload.code || '';
      err.payload = payload;
      throw err;
    }
    return payload;
  }

  function apiHeaders(token, nonce, sessionToken) {
    const headers = { 'Accept': 'application/json' };
    if (token) headers['X-CKM-Token'] = token;
    if (nonce) headers['X-CKM-Nonce'] = nonce;
    if (sessionToken) headers['X-CKM-Session'] = sessionToken;
    return headers;
  }

  async function getState(game, token, nonce, lastEventId, sessionToken) {
    const url = new URL('api/quiz-state.php', window.location.href);
    url.searchParams.set('game', game);
    url.searchParams.set('last_event_id', String(Math.max(0, Number(lastEventId) || 0)));
    const response = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: apiHeaders(token, nonce, sessionToken)
    });
    const payload = await parseJsonResponse(response);
    return payload.state;
  }

  async function postJson(endpoint, body, token, nonce, sessionToken) {
    const headers = apiHeaders(token, nonce, sessionToken);
    headers['Content-Type'] = 'application/json';
    const response = await fetch(new URL(endpoint, window.location.href).toString(), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers,
      body: JSON.stringify(body || {})
    });
    return parseJsonResponse(response);
  }

  class StatePoller {
    constructor(opts) {
      this.game = opts.game || '';
      this.token = opts.token || '';
      this.nonce = opts.nonce || '';
      this.sessionToken = opts.sessionToken || '';
      this.lastEventId = Math.max(0, Number(opts.lastEventId) || 0);
      this.onState = typeof opts.onState === 'function' ? opts.onState : function () {};
      this.onError = typeof opts.onError === 'function' ? opts.onError : function () {};
      this.running = false;
      this.timer = null;
    }

    setNonce(nonce) { this.nonce = nonce || ''; }
    setToken(token) { this.token = token || ''; }
    setSessionToken(token) { this.sessionToken = token || ''; }

    async cycle() {
      if (!this.running) return;
      try {
        let immediate = true;
        while (this.running && immediate) {
          const state = await getState(this.game, this.token, this.nonce, this.lastEventId, this.sessionToken);
          const events = Array.isArray(state.events) ? state.events : [];
          this.lastEventId = Math.max(this.lastEventId, Number(state.lastEventId) || 0);
          this.onState(state);
          setConnection('online', 'Синхронизировано');
          immediate = events.length === EVENT_PAGE_SIZE;
        }
        if (this.running) this.timer = window.setTimeout(this.cycle.bind(this), POLL_INTERVAL_MS);
      } catch (error) {
        this.onError(error);
        setConnection('error', error && error.message ? error.message : 'Ошибка синхронизации');
        if (this.running) this.timer = window.setTimeout(this.cycle.bind(this), POLL_INTERVAL_MS);
      }
    }

    start() {
      if (this.running) return;
      this.running = true;
      this.cycle();
    }

    stop() {
      this.running = false;
      if (this.timer) window.clearTimeout(this.timer);
      this.timer = null;
    }

    refreshNow() {
      if (!this.running) return;
      if (this.timer) window.clearTimeout(this.timer);
      this.timer = null;
      this.cycle();
    }
  }

  class ServerClock {
    constructor(onTick) {
      this.offsetMs = 0;
      this.deadlineUnix = 0;
      this.onTick = typeof onTick === 'function' ? onTick : function () {};
      this.timer = window.setInterval(this.tick.bind(this), 250);
    }

    sync(state) {
      if (!state) return;
      const serverTime = Number(state.serverTime) || 0;
      if (serverTime > 0) this.offsetMs = serverTime * 1000 - Date.now();
      this.deadlineUnix = state.game ? (Number(state.game.questionDeadlineUnix) || 0) : 0;
      this.tick();
    }

    nowMs() { return Date.now() + this.offsetMs; }

    remainingSeconds() {
      if (!this.deadlineUnix) return 0;
      return Math.max(0, Math.ceil((this.deadlineUnix * 1000 - this.nowMs()) / 1000));
    }

    tick() { this.onTick(this.remainingSeconds()); }

    destroy() { if (this.timer) window.clearInterval(this.timer); }
  }

  function formatSeconds(seconds) {
    const total = Math.max(0, Number(seconds) || 0);
    const m = Math.floor(total / 60);
    const s = total % 60;
    return m + ':' + String(s).padStart(2, '0');
  }

  function phaseLabel(phase, status) {
    if (status === 'finished' || phase === 'finished') return 'Игра завершена';
    if (phase === 'question_open') return 'Вопрос открыт';
    if (phase === 'question_closed') return 'Вопрос закрыт';
    return 'Ожидание старта';
  }

  function optionItems(options) {
    if (!Array.isArray(options)) return [];
    return options.map(function (item, index) {
      if (item && typeof item === 'object') {
        const value = item.value != null ? item.value : (item.id != null ? item.id : index + 1);
        const label = item.label != null ? item.label : (item.text != null ? item.text : value);
        return { value: String(value), label: String(label) };
      }
      return { value: String(item), label: String(item) };
    });
  }

  function messageText(value) {
    if (typeof value === 'string' || typeof value === 'number') return String(value).trim();
    if (!value || typeof value !== 'object') return '';
    const candidates = [value.content, value.text, value.message, value.value, value.sourceText];
    for (const candidate of candidates) {
      const text = messageText(candidate);
      if (text) return text;
    }
    return '';
  }

  function eventMessage(event) {
    if (!event || typeof event !== 'object') return '';
    const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
    if (event.action === 'human_host_message' || event.action === 'ai_host_message') {
      return messageText(payload.text)
        || messageText(payload.channels && payload.channels.text)
        || messageText(payload.message);
    }
    if (event.action === 'chgk_discussion_started') return 'Битва знатоков: началось командное обсуждение.';
    if (event.action === 'chgk_final_answer_submitted') return 'Финальный ответ команды зафиксирован.';
    if (event.action === 'chgk_discussion_closed') return 'Битва знатоков: время обсуждения завершено.';
    if (event.action === 'chgk_answers_closed') return 'Битва знатоков: приём окончательных ответов закрыт.';
    if (event.action === 'chgk_arbitration_requested') return 'Финальный ответ передан арбитру.';
    if (event.action === 'chgk_answer_revealed') return 'Битва знатоков: правильный ответ раскрыт.';
    if (event.action === 'chgk_ai_arbitration_completed') return 'ИИ вынес решение по ответу команды.';
    if (event.action === 'chgk_ai_arbitration_needs_human') return 'ИИ запросил ручную проверку арбитра.';
    if (event.action === 'chgk_ai_arbitration_failed') return 'ИИ-арбитраж не выполнен; доступна ручная оценка.';
    if (event.action === 'captain_assigned') return 'Старая командная роль была назначена.';
    if (event.action === 'captain_changed') return 'Старая командная роль была изменена.';
    if (event.action === 'question_roulette_spun') return 'Следующий вопрос выбран.';
    if (event.action === 'question_random_selected') return 'Следующий вопрос выбран случайно.';
    if (event.action === 'jeopardy_cell_selected') {
      const category = String(payload.categoryTitle || '').trim();
      const value = Number(payload.value || 0);
      return 'Интеллектуальный батл: выбрана ячейка' + (category ? ' «' + category + '»' : '') + (value ? ' за ' + value : '') + '.';
    }
    if (event.action === 'jeopardy_selector_changed') return 'Интеллектуальный батл: право выбора передано другой команде.';
    if (event.action === 'jeopardy_cell_played') return 'Интеллектуальный батл: ячейка сыграна.';
    return '';
  }

  function safeMediaUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.href);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') return '';
      return url.toString();
    } catch (e) {
      return '';
    }
  }

  function youtubeEmbedUrl(raw, startSeconds, endSeconds) {
    try {
      const url = new URL(raw, window.location.href);
      const host = url.hostname.toLowerCase().replace(/^www\./, '');
      let id = '';
      if (host === 'youtu.be') id = url.pathname.split('/').filter(Boolean)[0] || '';
      else if (host === 'youtube.com' || host.endsWith('.youtube.com')) {
        if (url.pathname === '/watch') id = url.searchParams.get('v') || '';
        else {
          const parts = url.pathname.split('/').filter(Boolean);
          if (['embed','shorts','live'].includes(parts[0])) id = parts[1] || '';
        }
      }
      if (!/^[A-Za-z0-9_-]{6,}$/.test(id)) return '';
      const q = new URLSearchParams({controls:'1',playsinline:'1',rel:'0'});
      if (startSeconds > 0) q.set('start', String(startSeconds));
      if (endSeconds > startSeconds) q.set('end', String(endSeconds));
      return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + q.toString();
    } catch (e) { return ''; }
  }

  function renderQuestionMedia(id, question) {
    const mount = document.getElementById(id);
    if (!mount) return;
    const src = safeMediaUrl(question && question.mediaUrl);
    const explicitType = String(question && question.mediaType || '').toLowerCase();
    const inferredVideo = /\.(mp4|webm|ogg|ogv|m4v)(?:$|[?#])/i.test(src) || /(?:youtube\.com|youtu\.be)/i.test(src);
    const type = explicitType === 'video' ? 'video' : explicitType === 'image' ? 'image' : (inferredVideo ? 'video' : 'image');
    const startSeconds = Math.max(0, Number(question && question.mediaStartSeconds) || 0);
    const endSeconds = Math.max(0, Number(question && question.mediaEndSeconds) || 0);

    mount.replaceChildren();
    if (!src) { mount.hidden = true; return; }

    if (type === 'video') {
      const embed = youtubeEmbedUrl(src, startSeconds, endSeconds);
      if (embed) {
        const frame = document.createElement('iframe');
        frame.className = 'quiz-question__media-frame';
        frame.src = embed;
        frame.title = question && question.text ? ('Видеофрагмент к вопросу: ' + String(question.text)) : 'Видеофрагмент к вопросу';
        frame.loading = 'lazy';
        frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        frame.allowFullscreen = true;
        mount.appendChild(frame);
      } else {
        const video = document.createElement('video');
        video.className = 'quiz-question__media-video';
        video.controls = true;
        video.preload = 'metadata';
        video.playsInline = true;
        video.src = src;
        const seekToStart = function () {
          if (startSeconds > 0 && Number.isFinite(video.duration) && video.currentTime < startSeconds - 0.25) {
            try { video.currentTime = Math.min(startSeconds, Math.max(0, video.duration - 0.05)); } catch (e) {}
          }
        };
        video.addEventListener('loadedmetadata', seekToStart, {once:true});
        video.addEventListener('play', function () {
          if (startSeconds > 0 && (video.currentTime < startSeconds - 0.25 || (endSeconds > startSeconds && video.currentTime >= endSeconds - 0.05))) {
            try { video.currentTime = startSeconds; } catch (e) {}
          }
        });
        if (endSeconds > startSeconds) {
          video.addEventListener('timeupdate', function () {
            if (video.currentTime >= endSeconds) { video.pause(); try { video.currentTime = endSeconds; } catch (e) {} }
          });
        }
        mount.appendChild(video);
      }
      if (startSeconds > 0 || endSeconds > 0) {
        const note = document.createElement('div');
        note.className = 'quiz-question__media-note';
        const fmt = function (v) { const m=Math.floor(v/60), s=Math.floor(v%60); return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); };
        note.textContent = 'Фрагмент: ' + fmt(startSeconds) + '–' + (endSeconds > startSeconds ? fmt(endSeconds) : 'до конца');
        mount.appendChild(note);
      }
    } else {
      const image = document.createElement('img');
      image.className = 'quiz-question__media-image';
      image.src = src;
      image.alt = question && question.text ? ('Изображение к вопросу: ' + String(question.text)) : 'Изображение к вопросу';
      image.loading = 'lazy';
      mount.appendChild(image);
    }
    mount.hidden = false;
  }

  function renderJeopardyBoard(targetId, board, options) {
    const root = document.getElementById(targetId);
    if (!root) return;
    const opts = options && typeof options === 'object' ? options : {};
    const enabled = !!(board && board.enabled);
    root.innerHTML = '';
    root.hidden = !enabled;
    if (!enabled) return;

    const teams = Array.isArray(opts.teams) ? opts.teams : [];
    const selectorTeamId = Number(board.selectorTeamId || 0);
    const selector = teams.find(function (team) { return Number(team.id || 0) === selectorTeamId; }) || null;
    const intro = document.createElement('div');
    intro.className = 'quiz-jeopardy-board__meta';
    const selectorText = document.createElement('strong');
    selectorText.textContent = selector ? ('Выбирает: ' + String(selector.name || 'команда')) : 'Право выбора не назначено';
    const hint = document.createElement('span');
    hint.textContent = opts.hint || (opts.interactive ? 'Нажмите доступный номинал, чтобы открыть вопрос.' : 'Доска обновляется автоматически.');
    intro.append(selectorText, hint);
    root.appendChild(intro);

    const rounds = Array.isArray(board.rounds) ? board.rounds : [];
    rounds.forEach(function (round) {
      const roundBox = document.createElement('section');
      roundBox.className = 'quiz-jeopardy-round';
      if (Number(round.id || 0) === Number(board.selectableRoundId || 0)) roundBox.classList.add('is-current');

      const roundHead = document.createElement('div');
      roundHead.className = 'quiz-jeopardy-round__head';
      const title = document.createElement('h3');
      title.textContent = String(round.title || ('Раунд ' + Number(round.position || 1)));
      const badge = document.createElement('span');
      badge.className = 'quiz-badge';
      badge.textContent = Number(round.id || 0) === Number(board.selectableRoundId || 0) ? 'Текущий раунд' : 'Раунд';
      roundHead.append(title, badge);
      roundBox.appendChild(roundHead);

      const categories = document.createElement('div');
      categories.className = 'quiz-jeopardy-categories';
      (Array.isArray(round.categories) ? round.categories : []).forEach(function (category) {
        const row = document.createElement('div');
        row.className = 'quiz-jeopardy-category';
        const categoryTitle = document.createElement('div');
        categoryTitle.className = 'quiz-jeopardy-category__title';
        categoryTitle.textContent = String(category.title || 'Категория');
        const cells = document.createElement('div');
        cells.className = 'quiz-jeopardy-category__cells';
        (Array.isArray(category.cells) ? category.cells : []).forEach(function (cell) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'quiz-jeopardy-cell is-' + String(cell.state || 'available');
          button.textContent = String(Number(cell.value || 0));
          button.dataset.questionId = String(Number(cell.questionId || 0));
          const isAvailable = String(cell.state || 'available') === 'available';
          const inRound = !board.selectableRoundId || Number(round.id || 0) === Number(board.selectableRoundId || 0);
          // alpha.83.1: for the host board, do not swallow clicks with a local
          // disabled state. The server is authoritative for whether a cell may
          // be selected. This also lets the host see the exact server error
          // instead of a cell that appears to do nothing. Participant and
          // scoreboard boards keep the existing read-only behaviour.
          const serverGuardedSelection = !!opts.serverGuardedSelection;
          // alpha.83.2: on the host board, an available cell is always clickable.
          // The server owns all guards (current round, pending selection, phase,
          // selector team, final state). This prevents stale client state or an
          // older cached guard from swallowing the click before the API can
          // return a useful error. Read-only participant/scoreboard boards keep
          // the strict local disabled state.
          const canSelect = !!opts.interactive && isAvailable && (serverGuardedSelection || (inRound && !Number(board.selectedQuestionId || 0) && !opts.locked));
          button.disabled = serverGuardedSelection ? !isAvailable : !canSelect;
          if (String(cell.state || '') === 'selected') button.setAttribute('aria-label', 'Выбран вопрос за ' + String(cell.value || 0));
          else if (String(cell.state || '') === 'played') button.setAttribute('aria-label', 'Сыгран вопрос за ' + String(cell.value || 0));
          else button.setAttribute('aria-label', 'Вопрос за ' + String(cell.value || 0));
          if (canSelect && typeof opts.onSelect === 'function') {
            button.addEventListener('click', function () { opts.onSelect(Number(cell.questionId || 0), cell, round, category); });
          }
          cells.appendChild(button);
        });
        row.append(categoryTitle, cells);
        categories.appendChild(row);
      });
      roundBox.appendChild(categories);
      root.appendChild(roundBox);
    });
  }

  function appendEvents(buffer, events, limit) {
    const max = Math.max(10, Number(limit) || 100);
    (Array.isArray(events) ? events : []).forEach(function (event) {
      if (!buffer.some(function (x) { return Number(x.id) === Number(event.id); })) buffer.push(event);
    });
    buffer.sort(function (a, b) { return Number(a.id) - Number(b.id); });
    if (buffer.length > max) buffer.splice(0, buffer.length - max);
    return buffer;
  }

  document.documentElement.dataset.ckmQuizUiBuild = '2.19.0-alpha.86.4';

  window.CKMQuizUI = {
    initTheme: initTheme,
    applyTheme: applyTheme,
    query: query,
    scrubSecrets: scrubSecrets,
    sessionSecret: sessionSecret,
    text: text,
    show: show,
    setConnection: setConnection,
    renderTestBadge: renderTestBadge,
    getState: getState,
    postJson: postJson,
    StatePoller: StatePoller,
    ServerClock: ServerClock,
    formatSeconds: formatSeconds,
    phaseLabel: phaseLabel,
    optionItems: optionItems,
    eventMessage: eventMessage,
    renderQuestionMedia: renderQuestionMedia,
    renderJeopardyBoard: renderJeopardyBoard,
    appendEvents: appendEvents,
    POLL_INTERVAL_MS: POLL_INTERVAL_MS,
    EVENT_PAGE_SIZE: EVENT_PAGE_SIZE
  };
})();
