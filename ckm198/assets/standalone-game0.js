(function(){
'use strict';
const root=document.documentElement;
if(root.dataset.ckmGameInit==='1') return;
root.dataset.ckmGameInit='1';
const cfg=window.CKMQPGameConfig||{};
const MODE=String(cfg.mode||'play'), AJAX=String(cfg.ajaxUrl||''), p=new URLSearchParams(location.search);
const game=p.get('game')||'', team=p.get('team')||'', token=p.get('token')||'', nonce=p.get('nonce')||'';
const uiLabels=cfg.uiLabels||{};
function ui(key,fallback){const v=uiLabels&&uiLabels[key];return (v===undefined||v===null||String(v).trim()==='')?fallback:String(v);}
const userKey='ckmqp.'+game+'.'+team+'.uid';
let uid=Number(localStorage.getItem(userKey)||0), selected=false, deadline=0, offset=0, formatKey='classic_quiz', lastState=null, polling=false, lastQuestionId=0, jeopardyBusy=false, actionRenderSeq=0, voiceReady=false;
const $=id=>document.getElementById(id);
function esc(s){return String(s??'').replace(/[&<>\"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[m]));}
function post(action,data){const f=new FormData();f.append('action','ckm_qp_'+action);Object.entries(data||{}).forEach(([k,v])=>{if(v!==undefined&&v!==null)f.append(k,String(v));});return fetch(AJAX,{method:'POST',body:f,credentials:'same-origin'}).then(async r=>{let j={ok:false,error:'Ошибка сервера.'};try{j=await r.json();}catch(e){}return j;});}
function teamById(st,id){return (st.teams||[]).find(t=>Number(t.id)===Number(id))||null;}
function myTeam(st){if(MODE!=='play') return null;const id=Number(st.you&&st.you.teamId||0);return teamById(st,id)||(st.teams||[]).find(t=>String(t.key)===team)||null;}
function setStatus(text,kind=''){const el=$('status');if(!el)return;el.textContent=text||'';el.className='status'+(kind?' '+kind:'');}
function renderTeams(ts){const el=$('teams');if(!el)return;el.innerHTML=(ts||[]).map(t=>`<div class="team"><div>${esc(t.name)}</div><div class="score">${Number(t.score||0)}</div></div>`).join('');}
function phaseLabel(g){if(g.status==='finished')return 'Игра завершена';if(formatKey==='jeopardy')return 'Интеллектуальный батл';if(formatKey==='negotiation_duel'&&g.phase==='question_open')return 'Переговорный поединок';if(g.phase==='question_open')return formatKey==='chgk'?'Обсуждение':'Вопрос открыт';if(g.phase==='question_closed')return 'Ответы обработаны';return 'Ожидание';}
function renderQuestion(st){const g=st.game||{},q=st.question||null,qel=$('question');if(!qel)return;if(q)qel.textContent=q.text||q.questionText||'';else if(g.status==='finished')qel.textContent='Игра завершена';else if(formatKey==='jeopardy')qel.textContent=g.startAuthorized?'Выберите ячейку игрового поля':'Ожидайте запуска ведущим';else qel.textContent=ui('participant_waiting_question','Ожидайте запуска ведущим');}
function hide(id,yes=true){const el=$(id);if(el)el.hidden=!!yes;}
function showAnswerBox(show,placeholder,buttonLabel,disabled){const box=$('answerBox');if(!box)return;box.classList.toggle('show',!!show);const text=$('answerText'),btn=$('answerBtn');if(text){text.placeholder=placeholder||'Ответ команды';text.disabled=!!disabled;}if(btn){btn.textContent=buttonLabel||'Отправить ответ';btn.disabled=!!disabled;}}
function renderClassicOrChgk(st){const g=st.game||{},q=st.question||null;
hide('jeopardyArea',true);hide('jeopardyHostArea',true);hide('genericHostActions',MODE!=='host');
if(MODE==='play'){
 const mine=myTeam(st);if($('teamName'))$('teamName').textContent=mine?mine.name:ui('participant_team_label','Команда')+' '+team;selected=!!(mine&&mine.answeredCurrentQuestion);
 const box=$('options');if(box)box.innerHTML='';
 if(formatKey==='chgk'){
   showAnswerBox(!!q&&g.phase==='question_open','Финальный ответ команды','Зафиксировать финальный ответ',selected||g.phase!=='question_open');
 }else if(formatKey==='solution_price'){
   showAnswerBox(!!q&&g.phase==='question_open','Введите решение команды','Отправить решение',selected||g.phase!=='question_open');
 }else if(formatKey==='negotiation_duel'){
   showAnswerBox(!!q&&g.phase==='question_open','Введите вашу реплику','Отправить реплику',selected||g.phase!=='question_open');
 }else{
   showAnswerBox(false);
   if(box&&q&&g.phase==='question_open') (q.options||[]).forEach(o=>{const v=(o&&typeof o==='object')?String(o.value??o.id??''):String(o),label=(o&&typeof o==='object')?String(o.label??o.text??v):String(o);const b=document.createElement('button');b.className='opt';b.disabled=selected;b.innerHTML='<strong>'+esc(v)+'</strong> · '+esc(label);b.addEventListener('click',()=>answer(v));box.appendChild(b);});
 }
}
if(MODE==='host') renderGenericHost(st);
if(g.status==='finished')setStatus('Готово. Итоговый счёт на табло.','ok');
else if(g.phase==='question_open')setStatus(MODE==='host'?'Вопрос открыт.':(formatKey==='chgk'?(selected?'Финальный ответ команды зафиксирован.':'Обсудите вопрос и зафиксируйте один финальный ответ до окончания времени.'):(formatKey==='solution_price'?(selected?'Решение команды отправлено.':'Введите решение команды до окончания времени.'):(formatKey==='negotiation_duel'?(selected?'Реплика команды отправлена. Ожидайте следующего хода.':'Введите переговорную реплику до окончания времени.'):'Выберите ответ до окончания времени.'))));
else if(g.phase==='question_closed')setStatus(g.hostMode==='human'?'Ответы зафиксированы. Ведущий может открыть следующий вопрос.':'Ответы зафиксированы. Переходим к следующему вопросу.');
else setStatus(g.hostMode==='ai'?'Ожидайте: ведущий включает голос и запускает игру.':'Ожидайте запуска ведущим.');
}
function renderGenericHost(st){const g=st.game||{},next=$('hostNext'),close=$('hostClose'),finish=$('hostFinish'),answers=$('hostAnswers');if(!next)return;const done=g.status==='finished',waiting=g.phase==='waiting',ai=g.hostMode==='ai',human=g.hostMode==='human',sergey=ai&&String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway',needsVoice=waiting&&sergey&&!voiceReady;next.disabled=done||g.phase==='question_open'||(!human&&!waiting)||needsVoice;close.disabled=done||g.phase!=='question_open'||!human;finish.disabled=done||!human;next.textContent=waiting?(ai?ui('host_start_ai_button','Запустить игру'):ui('host_start_human_button','Задать вопрос')):ui('host_next_button','Следующий вопрос');if(close)close.textContent=ui('host_close_button','Закрыть вопрос');if(finish)finish.textContent=ui('host_finish_button','Завершить игру');next.title=needsVoice?'Сначала нажмите «Включить Сергея» и дождитесь статуса соединения.':'';const rows=st.answers||[];if(answers)answers.innerHTML=rows.length?rows.map(a=>`<div class="answer-row"><strong>${esc(a.teamName||a.teamKey||'Команда')}</strong><div>${esc(a.answerText||'—')}</div><div class="muted">${esc(a.verdict||'pending')} · ${Number(a.awardedPoints||0)} балл.</div></div>`).join(''):'<div class="muted">'+esc(ui('host_answers_empty','Ответов пока нет.'))+'</div>';}
function boardState(st){return st.formatRuntime&&st.formatRuntime.board&&st.formatRuntime.board.enabled?st.formatRuntime.board:(st.jeopardyBoard||{enabled:false});}
function runtime(st){return st.formatRuntime||{};}
function renderBoard(st){const area=$('jeopardyArea'),board=$('jeopardyBoard');if(!area||!board)return;area.hidden=false;const rt=runtime(st),b=boardState(st),g=st.game||{},mine=myTeam(st),selectorId=Number(b.selectorTeamId||rt.selectorTeamId||0),selector=teamById(st,selectorId);if($('jeopardySelector'))$('jeopardySelector').textContent=selector?'Вопрос выбирает: '+selector.name:'Право выбора ещё не определено';
 const rounds=b.rounds||[];if(!rounds.length){board.innerHTML='<div class="muted">Игровое поле пока недоступно.</div>';return;}
 let html='';rounds.forEach(round=>{html+='<div class="board-round"><div class="board-round-title">'+esc(round.title||'Игровое поле')+'</div><div class="board-grid">';(round.categories||[]).forEach(cat=>{html+='<div class="board-category"><div class="board-category-title">'+esc(cat.title||cat.key)+'</div><div class="board-cells">';(cat.cells||[]).forEach(cell=>{const available=cell.state==='available',pending=cell.state==='selected'&&Number(b.selectedQuestionId||0)===Number(cell.questionId)&&g.phase!=='question_open';let can=false;if((available||pending)&&g.status!=='finished'&&g.phase!=='question_open'){if(MODE==='host'&&g.hostMode==='human')can=true;else if(MODE==='play'&&g.hostMode==='ai'&&g.startAuthorized&&mine&&selectorId>0&&Number(mine.id)===selectorId)can=true;}const cls='board-cell '+(available?'available':(pending?'selected-pending':'used'))+(can?' selectable':'');const label=available?Number(cell.value):(pending?'↻ '+Number(cell.value):'×');html+=`<button type="button" class="${cls}" data-qid="${Number(cell.questionId)}" ${can?'':'disabled'}>${label}</button>`;});html+='</div></div>';});html+='</div></div>';});board.innerHTML=html;
 board.querySelectorAll('.board-cell.selectable').forEach(btn=>btn.addEventListener('click',()=>{const qid=Number(btn.dataset.qid||0);if(MODE==='host') hostJeopardy('jeopardy_select',{question_id:qid,selector_team_id:selectorId});else teamJeopardy('select_cell',{question_id:qid});}));
}
function renderJeopardyParticipant(st){const g=st.game||{},rt=runtime(st),mine=myTeam(st),myId=Number(mine&&mine.id||0),q=st.question||null,cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{};hide('genericHostActions',true);hide('jeopardyHostArea',true);if($('teamName'))$('teamName').textContent=mine?mine.name:ui('participant_team_label','Команда')+' '+team;renderBoard(st);const action=$('jeopardyAction');if(action){action.hidden=false;action.innerHTML='';}
 selected=!!st.yourAnswer;
 if(final.enabled&&final.started){
   if(final.open){showAnswerBox(true,'Финальный ответ команды','Зафиксировать финальный ответ',!!st.yourAnswer);setStatus(st.yourAnswer?'Финальный ответ зафиксирован. Ожидайте завершения приёма.':'Финал: отправьте один скрытый ответ. Верный ответ — +'+Number(final.fixedPoints||500)+' баллов.');}
   else{showAnswerBox(false);setStatus(final.revealed?'Финальные ответы раскрыты.':'Приём финальных ответов завершён.');}
   return;
 }
 if(cat.enabled&&cat.status==='pending_target'){
   showAnswerBox(false);const source=teamById(st,cat.sourceTeamId);if(g.hostMode==='ai'&&myId===Number(cat.sourceTeamId)&&action){action.innerHTML='<div class="special-banner"><strong>Секретная передача</strong><div>Выберите другую команду, которой перейдёт вопрос.</div><div class="host-actions">'+(st.teams||[]).filter(t=>Number(t.id)!==myId).map(t=>`<button type="button" class="host-btn" data-cat-team="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div></div>';action.querySelectorAll('[data-cat-team]').forEach(b=>b.addEventListener('click',()=>teamJeopardy('assign_cat',{target_team_id:Number(b.dataset.catTeam)})));}else setStatus(source?'«Секретная передача»: '+source.name+' выбирает команду-получателя.':'Ожидается выбор команды для «Секретной передачи».');return;
 }
 if(cat.enabled&&cat.status==='assigned'&&Number(cat.questionId)===Number(g.currentQuestionId||rt.selectedQuestionId||0)){
   if(cat.open&&myId===Number(cat.targetTeamId)){showAnswerBox(true,'Ваш ответ','Отправить ответ',!!st.yourAnswer);setStatus(st.yourAnswer?'Ответ отправлен. Ожидайте решения.':'Секретная передача: вопрос передан вашей команде. Ответьте до окончания времени.');}
   else{showAnswerBox(false);const target=teamById(st,cat.targetTeamId);setStatus(target?'Секретная передача: отвечает только '+target.name+'.':'Секретная передача.');}return;
 }
 if(q&&g.phase==='question_open'){
   const blocked=(buzz.blockedTeamIds||[]).map(Number).includes(myId),active=buzz.active||null;
   if(active&&Number(active.teamId)===myId){
     if(active.hasAnswer){showAnswerBox(false);setStatus('Ответ отправлен. Ожидайте решения.');}
     else{showAnswerBox(true,'Ваш ответ','Отправить ответ',!!st.yourAnswer||Number(active.secondsRemaining||0)<=0);setStatus('Право ответа у вашей команды. Осталось '+Number(active.secondsRemaining||0)+' сек.');}
   }else if(active){showAnswerBox(false);const owner=teamById(st,active.teamId);setStatus('Право ответа сейчас у '+(owner?owner.name:'другой команды')+'.');}
   else if(blocked){showAnswerBox(false);setStatus('Ваш предыдущий ответ отклонён. На этот вопрос повторно отвечать нельзя.');}
   else if(buzz.open){showAnswerBox(false);if(action)action.innerHTML='<button type="button" class="buzz-btn" id="buzzBtn">ОТВЕЧАЕМ!</button>';const b=$('buzzBtn');if(b)b.addEventListener('click',()=>teamJeopardy('buzz',{}));setStatus('Знаете ответ? Нажмите «ОТВЕЧАЕМ!» раньше соперников.');}
   else{showAnswerBox(false);setStatus('Ожидайте открытия кнопки ответа.');}
 }else{
   showAnswerBox(false);const b=boardState(st),selector=teamById(st,b.selectorTeamId);if(g.status==='finished')setStatus('Игра завершена.','ok');else if(g.hostMode==='ai'&&mine&&Number(b.selectorTeamId||0)>0&&Number(mine.id)===Number(b.selectorTeamId))setStatus('Ваш ход: выберите свободную ячейку игрового поля.');else if(g.hostMode==='human')setStatus('Ячейку выбирает ведущий.');else setStatus(selector?'Ячейку выбирает '+selector.name+'.':'Ожидайте выбора ячейки.');
 }
}
function pendingAnswerForTeam(st,teamId){return (st.answers||[]).find(a=>Number(a.teamId||0)===Number(teamId))||null;}
function renderJeopardyHost(st){const g=st.game||{},rt=runtime(st),cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{},host=$('jeopardyHostArea');hide('genericHostActions',true);showAnswerBox(false);renderBoard(st);if(!host)return;host.hidden=false;let html='';const b=boardState(st),selector=teamById(st,b.selectorTeamId);if(g.hostMode==='ai'&&!g.startAuthorized){const sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway',disabled=sergey&&!voiceReady;html+='<div class="host-block"><strong>Старт игры</strong><div class="muted">'+(disabled?'Сначала включите Сергея и дождитесь соединения.':'Голос готов. Можно запускать игру.')+'</div><div class="host-actions"><button type="button" class="host-btn primary" data-ai-start="1" '+(disabled?'disabled':'')+'>Запустить игру</button></div></div>';}
 html+='<div class="host-block"><strong>Право выбора</strong><div class="host-actions">'+(st.teams||[]).map(t=>`<button type="button" class="host-btn ${Number(t.id)===Number(b.selectorTeamId)?'primary':''}" data-set-selector="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div><div class="muted">Текущий выбор: '+esc(selector?selector.name:'не определён')+'</div></div>';
 if(cat.enabled&&cat.status==='pending_target') html+='<div class="host-block special-banner"><strong>Секретная передача</strong><p>Назначьте команду-получателя.</p><div class="host-actions">'+(st.teams||[]).filter(t=>Number(t.id)!==Number(cat.sourceTeamId)).map(t=>`<button type="button" class="host-btn" data-host-cat="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div></div>';
 if(buzz.active&&buzz.resolvable){const a=pendingAnswerForTeam(st,buzz.active.teamId),owner=teamById(st,buzz.active.teamId);html+='<div class="host-block"><strong>Ответ после кнопки: '+esc(owner?owner.name:'Команда')+'</strong><div class="answer-preview">'+esc(a&&a.answerText?a.answerText:(buzz.active.hasAnswer?'Ответ получен':'Ответ ещё не отправлен'))+'</div><div class="host-actions"><button type="button" class="host-btn primary" data-buzz-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-buzz-decision="rejected">Отклонить</button></div></div>';}
 if(cat.enabled&&cat.resolvable&&cat.status==='assigned'){const a=pendingAnswerForTeam(st,cat.targetTeamId),target=teamById(st,cat.targetTeamId);html+='<div class="host-block"><strong>Секретная передача: '+esc(target?target.name:'Команда')+'</strong><div class="answer-preview">'+esc(a&&a.answerText?a.answerText:'Ответ ещё не отправлен')+'</div>'+(a?'<div class="host-actions"><button type="button" class="host-btn primary" data-cat-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-cat-decision="rejected">Отклонить</button></div>':'')+'</div>';}
 if(g.phase==='question_open'&&!buzz.resolvable&&!(cat.enabled&&cat.resolvable)) html+='<div class="host-block"><button type="button" class="host-btn" data-close-jeopardy="1">Закрыть вопрос без правильного ответа</button></div>';
 if(final.enabled){html+='<div class="host-block"><strong>Финал · '+Number(final.fixedPoints||500)+' баллов</strong><div class="muted">Ответили: '+Number(final.submittedCount||0)+' из '+Number(final.teamCount||0)+'</div><div class="host-actions">';if(final.canStart)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_start_final">Начать финал</button>';if(final.open)html+='<button type="button" class="host-btn" data-final-action="jeopardy_close_final">Закрыть приём ответов</button>';if(final.canReveal)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_reveal_final">Раскрыть ответы</button>';html+='</div>';
 if(final.revealed){html+='<div class="final-rows">'+(final.rows||[]).map(r=>`<div class="answer-row"><strong>${esc(r.teamName)}</strong><div>${esc(r.answerText||'—')}</div>${r.resolved?`<div class="muted">${esc(r.verdict||'no_answer')}</div>`:`<div class="host-actions"><button type="button" class="host-btn primary" data-final-team="${Number(r.teamId)}" data-final-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-final-team="${Number(r.teamId)}" data-final-decision="rejected">Отклонить</button></div>`}</div>`).join('')+'</div>';if(final.allResolved)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_finish_final">Завершить финал</button>';}
 html+='</div>';}
 if(!final.enabled&&boardComplete(b)&&g.status!=='finished')html+='<div class="host-block"><button type="button" class="host-btn danger" data-finish-jeopardy="1">Завершить игру</button></div>';
 host.innerHTML=html;
 const aiStart=host.querySelector('[data-ai-start]');if(aiStart)aiStart.addEventListener('click',()=>hostAction('start'));host.querySelectorAll('[data-set-selector]').forEach(x=>x.addEventListener('click',()=>hostJeopardy('jeopardy_set_selector',{team_id:Number(x.dataset.setSelector)})));
 host.querySelectorAll('[data-host-cat]').forEach(x=>x.addEventListener('click',()=>hostJeopardy('jeopardy_assign_cat',{target_team_id:Number(x.dataset.hostCat)})));
 host.querySelectorAll('[data-buzz-decision]').forEach(x=>x.addEventListener('click',()=>hostJeopardy('jeopardy_resolve_buzz',{decision:x.dataset.buzzDecision})));
 host.querySelectorAll('[data-cat-decision]').forEach(x=>x.addEventListener('click',()=>hostJeopardy('jeopardy_resolve_cat',{decision:x.dataset.catDecision})));
 host.querySelectorAll('[data-final-action]').forEach(x=>x.addEventListener('click',()=>hostJeopardy(x.dataset.finalAction,{})));
 host.querySelectorAll('[data-final-team]').forEach(x=>x.addEventListener('click',()=>hostJeopardy('jeopardy_resolve_final',{team_id:Number(x.dataset.finalTeam),decision:x.dataset.finalDecision})));
 const close=host.querySelector('[data-close-jeopardy]');if(close)close.addEventListener('click',()=>hostJeopardy('jeopardy_close_question',{}));const fin=host.querySelector('[data-finish-jeopardy]');if(fin)fin.addEventListener('click',()=>{if(confirm('Завершить игру?'))hostJeopardy('jeopardy_finish_game',{});});
 if(g.status==='finished')setStatus('Игра завершена.','ok');else if(g.hostMode==='ai'&&!g.startAuthorized)setStatus('Сначала включите голос Сергея и запустите игру.');else if(final.started)setStatus('Управляйте финальным раундом.');else if(g.phase==='question_open')setStatus('Вопрос открыт. Управляйте правом ответа и арбитражем.');else setStatus('Выберите свободную ячейку игрового поля.');
}
function boardComplete(b){const cells=[];(b.rounds||[]).forEach(r=>(r.categories||[]).forEach(c=>(c.cells||[]).forEach(x=>cells.push(x))));return cells.length>0&&cells.every(x=>x.state==='played');}
function renderJeopardyScoreboard(st){renderBoard(st);hide('genericHostActions',true);hide('jeopardyHostArea',true);showAnswerBox(false);const g=st.game||{},rt=runtime(st),cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{};if(g.status==='finished')setStatus('Игра завершена.','ok');else if(final.started)setStatus(final.revealed?'Финальные ответы раскрыты.':'Финал: команды отправляют скрытые ответы.');else if(cat.enabled&&cat.status==='pending_target')setStatus('Секретная передача: выбирается команда-получатель.');else if(cat.enabled&&cat.status==='assigned'){const t=teamById(st,cat.targetTeamId);setStatus('Секретная передача: отвечает '+(t?t.name:'назначенная команда')+'.');}else if(buzz.active){const t=teamById(st,buzz.active.teamId);setStatus('Право ответа: '+(t?t.name:'команда')+'.');}else if(g.phase==='question_open')setStatus('Кнопка ответа открыта.');else{const b=boardState(st),t=teamById(st,b.selectorTeamId);setStatus(t?'Следующую ячейку выбирает '+t.name+'.':'Ожидание выбора ячейки.');}}
function renderJeopardy(st){hide('options',true);hide('genericAnswersCard',true);if(MODE==='play')renderJeopardyParticipant(st);else if(MODE==='host')renderJeopardyHost(st);else renderJeopardyScoreboard(st);}
function render(st){lastState=st;const g=st.game||{},qid=Number(st.question&&st.question.id||0);formatKey=String(g.formatKey||'classic_quiz');if(qid!==lastQuestionId){const t=$('answerText');if(t)t.value='';lastQuestionId=qid;selected=false;}if($('gameCode'))$('gameCode').textContent=g.code||game;if($('title'))$('title').textContent=g.title||'Игра';if($('phase'))$('phase').textContent=phaseLabel(g);renderTeams(st.teams);offset=(Number(st.serverTime||0)*1000)-Date.now();deadline=Number(g.questionDeadlineUnix||0)*1000;renderQuestion(st);if(formatKey==='jeopardy')renderJeopardy(st);else renderClassicOrChgk(st);window.dispatchEvent(new CustomEvent('ckmqp:state',{detail:st}));}
async function ensureJoin(){if(MODE!=='play')return;const r=await post('join',{game,team,token,nonce,user_id:uid,name:'Участник'});if(r.ok){uid=Number(r.userId||0);localStorage.setItem(userKey,String(uid));}else setStatus(r.error||'Не удалось подключиться.','err');}
async function poll(){if(polling)return;polling=true;try{const d={game,role:MODE==='play'?'participant':MODE,team,token,nonce,user_id:uid};const r=await post('state',d);if(r.ok)render(r.state);else setStatus(r.error||'Ошибка синхронизации.','err');}catch(e){setStatus('Нет связи с сервером. Повторяем попытку…','err');}finally{polling=false;setTimeout(poll,1800);}}
async function answer(v){if(selected&&formatKey!=='jeopardy')return;selected=true;setStatus('Отправляем ответ…');const r=await post('answer',{game,team,token,nonce,user_id:uid,answer:v});if(!r.ok)selected=false;setStatus(r.ok?'Ответ принят.':(r.error||'Ошибка ответа'),r.ok?'ok':'err');setTimeout(()=>poll(),120);}
async function teamJeopardy(command,extra){
 if(jeopardyBusy)return;jeopardyBusy=true;const seq=++actionRenderSeq;setStatus(command==='select_cell'?'Открываем выбранный вопрос…':'Выполняется действие…');
 try{
  const r=await post('jeopardy_action',Object.assign({game,team,token,nonce,user_id:uid,command},extra||{}));
  if(!r.ok){setStatus(r.error||'Ошибка действия','err');return;}
  // The action endpoint now returns an authoritative state, so the selected
  // question appears immediately instead of waiting for the polling loop.
  if(r.state&&seq===actionRenderSeq)render(r.state);
  const result=r.result||{};
  if(command==='select_cell'){
   if(result.needsTarget)setStatus('Выбрана «Секретная передача». Теперь нужно определить команду-получателя.','ok');
   else if((r.state&&r.state.game&&r.state.game.phase==='question_open')||(r.state&&r.state.question))setStatus('Ячейка выбрана. Вопрос открыт.','ok');
   else setStatus('Ячейка выбрана. Обновляем состояние игры…','ok');
  }else setStatus('Готово.','ok');
  setTimeout(()=>poll(),120);
 }catch(e){setStatus('Нет связи с сервером. Повторите действие.','err');}
 finally{jeopardyBusy=false;}
}
async function hostAction(command){const g=lastState&&lastState.game||{},sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway';if(command==='start'&&g.hostMode==='ai'&&sergey&&!voiceReady){setStatus('ИИ-ведущий ждёт: Диагностика голоса — соединение готово.','err');return;}setStatus('Выполняется команда ведущего…');const r=await post('host_action',{game,token,nonce,command,voice_ready:voiceReady?1:0});setStatus(r.ok?'Готово.':(r.error||'Ошибка команды ведущего'),r.ok?'ok':'err');if(r.ok&&r.state)render(r.state);}
async function hostJeopardy(command,extra){setStatus('Выполняется команда ведущего…');const r=await post('host_action',Object.assign({game,token,nonce,command},extra||{}));setStatus(r.ok?'Готово.':(r.error||'Ошибка команды ведущего'),r.ok?'ok':'err');if(r.ok&&r.state)render(r.state);else setTimeout(()=>poll(),100);}
const answerBtn=$('answerBtn');if(answerBtn)answerBtn.addEventListener('click',()=>{const t=($('answerText').value||'').trim();if(!t){setStatus(formatKey==='negotiation_duel'?'Введите переговорную реплику.':'Введите ответ команды.','err');return;}answer(t);});
const hostNext=$('hostNext');if(hostNext)hostNext.addEventListener('click',()=>hostAction((lastState&&lastState.game&&lastState.game.phase==='waiting')?'start':'next'));const hostClose=$('hostClose');if(hostClose)hostClose.addEventListener('click',()=>hostAction('close'));const hostFinish=$('hostFinish');if(hostFinish)hostFinish.addEventListener('click',()=>{if(confirm('Завершить игру?'))hostAction('finish');});
window.addEventListener('ckmqp:voice-connection',e=>{const d=e.detail||{};if(MODE!=='host'||d.role!=='host')return;voiceReady=!!d.ready;if(lastState){if(formatKey==='jeopardy')renderJeopardyHost(lastState);else renderGenericHost(lastState);}});
setInterval(()=>{const el=$('timer');if(!el)return;if(!deadline){el.textContent='—';return;}const sec=Math.max(0,Math.ceil((deadline-(Date.now()+offset))/1000));el.textContent=Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0');},250);
(async()=>{if(MODE==='play')await ensureJoin();poll();})();
})();
