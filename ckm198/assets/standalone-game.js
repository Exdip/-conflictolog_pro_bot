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
let uid=Number(localStorage.getItem(userKey)||0), selected=false, deadline=0, offset=0, formatKey='classic_quiz', lastState=null, polling=false, lastQuestionId=0, jeopardyBusy=false, actionRenderSeq=0, voiceReady=false, aiAutoStartKey='', aiAutoStartTimer=null, manualTakeoverPending=false, lastHostMode='', lastAnswerInputMode='text';
let sttSocket=null,sttStream=null,sttRecorder=null,sttSendChain=Promise.resolve(),sttConnecting=false,sttRecording=false,sttStopping=false,sttTimedOut=false,sttAborted=false,sttExpectedClose=false,sttWrap=null,sttButton=null,sttInterim=null,sttInstruction=null,sttTargetInput=null,sttIdleLabel='🎙 Продиктовать ответ',sttSessionGeneration=0,sttSessionQuestionId=0,sttSessionShowContextKey='',sttHandshakeTimer=null,sttHandshakeStage='idle';
const $=id=>document.getElementById(id);
function esc(s){return String(s??'').replace(/[&<>\"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[m]));}
function post(action,data){const f=new FormData();f.append('action','ckm_qp_'+action);Object.entries(data||{}).forEach(([k,v])=>{if(v!==undefined&&v!==null)f.append(k,String(v));});return fetch(AJAX,{method:'POST',body:f,credentials:'same-origin'}).then(async r=>{let j={ok:false,error:'Ошибка сервера.'};try{j=await r.json();}catch(e){}return j;});}
function postStoryReady(data){const url=new URL('/wp-json/ckm-quiz-pro/v1/show-story-ready',location.origin).toString();return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data||{}),credentials:'same-origin'}).then(async r=>{let j={ok:false,error:'Ошибка сервера.'};try{j=await r.json();}catch(e){}return j;});}
function postStoryVote(data){const url=new URL('/wp-json/ckm-quiz-pro/v1/show-story-vote',location.origin).toString();return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data||{}),credentials:'same-origin'}).then(async r=>{let j={ok:false,error:'Ошибка сервера.'};try{j=await r.json();}catch(e){}return j;});}
function quickActionRequest(promise,ms=12000){return new Promise((resolve,reject)=>{let settled=false;const timer=setTimeout(()=>{if(settled)return;settled=true;reject(new Error('Сервер не подтвердил действие за '+Math.round(ms/1000)+' секунд. Проверяем состояние; если действие не применилось, повторите его.'));},ms);promise.then(v=>{if(settled)return;settled=true;clearTimeout(timer);resolve(v);},e=>{if(settled)return;settled=true;clearTimeout(timer);reject(e);});});}
function teamById(st,id){return (st.teams||[]).find(t=>Number(t.id)===Number(id))||null;}
function myTeam(st){if(MODE!=='play') return null;const id=Number(st.you&&st.you.teamId||0);return teamById(st,id)||(st.teams||[]).find(t=>String(t.key)===team)||null;}
function setStatus(text,kind=''){const el=$('status');if(!el)return;el.textContent=text||'';el.className='status'+(kind?' '+kind:'');}
function renderTeams(st){const el=$('teams');if(!el)return;const ts=st&&st.teams||[],duel=st&&st.singleTeamDuel||{};if(formatKey==='chgk'&&duel.enabled){const target=Number(duel.targetScore||6);el.innerHTML=`<div class="team"><div>${esc(duel.expertsLabel||'Знатоки')}</div><div class="score">${Number(duel.expertsScore||0)}</div><div class="muted">до ${target}</div></div><div class="team"><div>${esc(duel.gameLabel||'Игра')}</div><div class="score">${Number(duel.gameScore||0)}</div><div class="muted">до ${target}</div></div>`;return;}el.innerHTML=ts.map(t=>`<div class="team"><div>${esc(t.name)}</div><div class="score">${Number(t.score||0)}</div></div>`).join('');}
function verdictText(v){v=String(v||'pending');if(v==='accepted'||v==='correct')return 'Принято';if(v==='rejected'||v==='incorrect')return 'Не принято';if(v==='partial')return 'Частично принято';if(v==='no_answer')return 'Ответ не получен';return 'На проверке';}
function answerValue(v){if(v&&typeof v==='object')return String(v.label??v.text??v.value??'');return String(v??'');}
function renderChgkFinal(st){
 const card=$('chgkFinal'),body=$('chgkFinalBody');if(!card||!body)return;
 const g=st&&st.game||{},duel=st&&st.singleTeamDuel||{};
 const visible=formatKey==='chgk'&&g.status==='finished'&&!!duel.enabled;
 card.classList.toggle('show',visible);if(!visible){body.innerHTML='';return;}
 const e=Number(duel.expertsScore||0),gs=Number(duel.gameScore||0),target=Number(duel.targetScore||6),played=Number(duel.playedQuestions||0);
 const winner=String(duel.winner||'');
 const title=winner==='experts'?'Знатоки победили!':(winner==='game'?'Игра победила':'Матч завершён досрочно');
 const cls=winner==='experts'?'chgk-final-win':(winner==='game'?'chgk-final-loss':'');
 const meta=winner?('Победа — первым до '+target+'. Сыграно вопросов: '+played+'.'):('Ни одна сторона не набрала '+target+' очков.');
 body.innerHTML=`<div class="chgk-final-kicker">Битва знатоков · итог матча</div><div class="chgk-final-title ${cls}">${esc(title)}</div><div class="chgk-final-score"><div class="chgk-final-side">${e}<span>Знатоки</span></div><div class="chgk-final-colon">:</div><div class="chgk-final-side">${gs}<span>Игра</span></div></div><div class="chgk-final-meta">${esc(meta)}</div>`;
}
function renderChgkReview(st){
 const card=$('chgkReview'),body=$('chgkReviewBody');if(!card||!body)return;
 const g=st&&st.game||{},flow=st&&st.questionFlow||{},revealed=formatKey==='chgk'&&MODE==='play'&&g.phase==='question_closed'&&!!flow.answerRevealed;
 card.classList.toggle('show',revealed);if(!revealed){body.innerHTML='';return;}
 const a=st.yourAnswer||{},q=st.question||{},mine=myTeam(st),duel=st.singleTeamDuel||{};
 const duelCurrent=!!(duel.enabled&&Number(duel.currentQuestionId||0)===Number(q.id||0));
 const verdict=String(a.verdict||(duelCurrent?duel.currentVerdict:'pending')||'pending'),points=Number(a.awardedPoints||0);
 const correct=Array.isArray(q.correctAnswers)?q.correctAnswers.map(answerValue).filter(Boolean).join(' / '):'';
 const explanation=String(q.explanation||'').trim(),judge=String(a.judgeComment||'').trim();
 const duelResult=duelCurrent?String(duel.currentOutcome||''):'';
 const pointsText=duel.enabled?(duelResult==='experts'?'+1 Знатокам':(duelResult==='game'?'+1 Игре':'—')):(points>0?('+'+points):String(points));
 const pointsClass=duel.enabled?(duelResult==='experts'?'positive':(duelResult==='game'?'negative':'')):(points>0?'positive':(points<0?'negative':''));
 const scoreHtml=duel.enabled?`${Number(duel.expertsScore||0)} : ${Number(duel.gameScore||0)}<div class="format-note">Знатоки : Игра</div>`:`${Number(mine&&mine.score||0)}`;
 body.innerHTML=`
  <div class="review-item"><div class="review-label">Ваш ответ</div><div class="review-value">${esc(a.answerText||'Ответ не отправлен')}</div></div>
  <div class="review-item"><div class="review-label">Решение арбитра</div><div class="review-verdict ${esc(verdict)}">${esc(verdictText(verdict))}</div>${judge?`<div class="format-note">${esc(judge)}</div>`:''}</div>
  <div class="review-item wide"><div class="review-label">Правильный ответ</div><div class="review-value">${esc(correct||'—')}</div></div>
  <div class="review-item wide"><div class="review-label">Объяснение</div><div class="review-value">${esc(explanation||'Пояснение к вопросу не задано.')}</div></div>
  <div class="review-item"><div class="review-label">${duel.enabled?'Результат раунда':'Начислено'}</div><div class="review-points ${pointsClass}">${esc(pointsText)}</div></div>
  <div class="review-item"><div class="review-label">${duel.enabled?'Текущий счёт':'Текущий счёт команды'}</div><div class="review-score">${scoreHtml}</div></div>`;
}
function phaseLabel(g,st){if(g.status==='finished')return 'Игра завершена';if(formatKey==='jeopardy')return 'Интеллектуальный батл';if(formatKey==='negotiation_duel'&&g.phase==='question_open')return 'Переговорный поединок';if(formatKey==='chgk'){if(g.phase==='question_open'){const rp=String(st&&st.formatRuntime&&st.formatRuntime.phase||'discussion');return rp==='question_narration'?'Озвучивание вопроса':(rp==='final_answer_open'?'Окончательный ответ':(rp==='early_answer_offer'?'Досрочный ответ':'Обсуждение команды'));}if(g.phase==='question_closed'){const fp=String(st&&st.questionFlow&&st.questionFlow.phase||'answers_closed');if(fp==='arbitration')return 'Арбитраж';if(fp==='answer_reveal'||fp==='review'||fp==='completed')return 'Правильный ответ';return 'Ответы закрыты';}}if(g.phase==='question_open')return 'Вопрос открыт';if(g.phase==='question_closed')return 'Ответы обработаны';return 'Ожидание';}
function speechLabelText(v){return String(v||'').replace(/(^|\n)(Оппонент|Клиент|Покупатель|Заказчик|Партн[её]р|Руководитель|Сотрудник|Команда\s+[A-Za-zА-Яа-яЁё0-9_-]+):\s*(?=[«“"])/gu,'$1$2 говорит: ');}
function renderQuestion(st){const g=st.game||{},q=st.question||null,qel=$('question');if(!qel)return;if(q)qel.textContent=speechLabelText(q.text||q.questionText||'');else if(g.status==='finished')qel.textContent='Игра завершена';else if(formatKey==='jeopardy')qel.textContent=g.startAuthorized?'Выберите ячейку игрового поля':'Ожидайте запуска голосовым ведущим';else qel.textContent=ui('participant_waiting_question','Ожидайте запуска голосовым ведущим');}
function hide(id,yes=true){const el=$(id);if(el)el.hidden=!!yes;}
function renderStandaloneChgkHostGuide(){
 const guide=$('hostChgkGuideStandalone');
 if(!guide)return;
 guide.hidden=!(MODE==='host'&&formatKey==='chgk');
}
function showAnswerBox(show,placeholder,buttonLabel,disabled,opts){const box=$('answerBox');if(!box)return;opts=opts||{};box.classList.toggle('show',!!show);box.classList.toggle('final-answer-open',!!opts.finalAnswer);let title=box.querySelector('.answer-box-title');if(!title){title=document.createElement('div');title.className='answer-box-title';box.prepend(title);}title.hidden=!opts.finalAnswer;title.textContent=opts.title||'ОКОНЧАТЕЛЬНЫЙ ОТВЕТ КОМАНДЫ';const note=box.querySelector('.format-note');if(note&&opts.note)note.textContent=opts.note;const text=$('answerText'),btn=$('answerBtn');if(text){text.placeholder=placeholder||'Ответ команды';text.disabled=!!disabled;}if(btn){btn.textContent=buttonLabel||'Отправить ответ';btn.disabled=!!disabled;}if(!opts.preserveStt)syncSttControls(!!show,!!disabled);if(show&&opts.finalAnswer){const qid=lastState&&lastState.question?Number(lastState.question.id||0):0;const key='chgk-final-'+qid;if(window.__ckmLastFinalAnswerScroll!==key){window.__ckmLastFinalAnswerScroll=key;setTimeout(()=>{try{box.scrollIntoView({block:'center',behavior:'smooth'});}catch(e){}},80);}}}
function mountSttControls(target,container,beforeNode,idleLabel){
 if(MODE!=='play'||!target||!container)return;
 if(!sttWrap){
  sttWrap=document.createElement('div');sttWrap.className='voice-controls';sttWrap.hidden=true;sttWrap.setAttribute('data-ckmqp-team-dictation','1');
  sttButton=document.createElement('button');sttButton.type='button';sttButton.className='voice-btn';
  sttInterim=document.createElement('span');sttInterim.className='voice-badge';sttInterim.textContent='Голосовой ввод готов · аудио не сохраняется';
  sttInstruction=document.createElement('div');sttInstruction.className='voice-instruction';const actionLabel=(idleLabel||'🎙 Продиктовать ответ').replace(/^🎙\s*/,'');sttInstruction.textContent='После нажатия «'+actionLabel+'» дождитесь красной надписи «Говорите». Начинайте говорить только после её появления.';
  sttWrap.append(sttButton,sttInterim,sttInstruction);
  sttButton.addEventListener('click',()=>{if(sttRecording||sttStopping)stopSttDictation();else if(!sttConnecting)startSttDictation();});
 }
 sttTargetInput=target;sttIdleLabel=idleLabel||'🎙 Продиктовать ответ';
 if(sttWrap.parentNode!==container||sttWrap.nextSibling!==beforeNode)container.insertBefore(sttWrap,beforeNode||null);
 if(sttButton&&!sttConnecting&&!sttRecording&&!sttStopping)sttButton.textContent=sttIdleLabel;
}
function syncSttControls(show,disabled){
 if(MODE!=='play')return;
 const box=$('answerBox'),text=$('answerText'),btn=$('answerBtn');if(!box||!text||!btn)return;
 mountSttControls(text,box,btn,'🎙 Продиктовать ответ');
 setupHostVoiceCommands();if(!sttWrap)return;
 const allowed=!!show&&!disabled;sttWrap.hidden=!allowed;
 if(!allowed&&(sttRecording||sttStopping||sttSocket||sttStream))abortSttDictation('');
 if(sttButton&&!sttConnecting&&!sttRecording&&!sttStopping)sttButton.disabled=!allowed;
}
function syncShowSttControls(show,disabled,idleLabel){
 if(MODE!=='play')return;
 const box=$('showCompose'),text=$('showMessageText'),btn=$('showSend');if(!box||!text||!btn)return;
 mountSttControls(text,box,btn,idleLabel||'🎙 Продиктовать');
 if(!sttWrap)return;
 const allowed=!!show&&!disabled,activeStt=!!(sttConnecting||sttRecording||sttStopping||sttSocket||sttStream);
 const currentContext=sttShowContextKey(lastState),sameShowContext=activeStt&&!!sttSessionShowContextKey&&currentContext===sttSessionShowContextKey;
 sttWrap.hidden=!(allowed||sameShowContext);
 if(!allowed&&activeStt&&!sameShowContext)abortSttDictation('');
 if(sttButton&&!sttConnecting&&!sttRecording&&!sttStopping){sttButton.disabled=!allowed;sttButton.textContent=sttIdleLabel;}
}
function sttStatus(text,live=false){if(!sttInterim)return;sttInterim.textContent=text||'';sttInterim.classList.toggle('live',!!live);sttInterim.classList.toggle('on',!live&&!!text);}
function chooseSttMime(){const types=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'];for(const t of types){if(window.MediaRecorder&&MediaRecorder.isTypeSupported(t))return t;}return '';}
function sttRecorderErrorText(e){const name=String(e&&e.name||'').trim(),message=String(e&&e.message||'').trim();return [name,message].filter(Boolean).join(': ')||'неизвестная ошибка MediaRecorder';}
function startParticipantMediaRecorder(stream,configure){
 const candidates=['','audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'];let lastError=null;
 for(const mime of candidates){
  if(mime&&window.MediaRecorder&&typeof MediaRecorder.isTypeSupported==='function'&&!MediaRecorder.isTypeSupported(mime))continue;
  try{
   const rec=mime?new MediaRecorder(stream,{mimeType:mime}):new MediaRecorder(stream);
   configure(rec);rec.start(250);return {rec,mime:String(rec.mimeType||mime||'browser-default')};
  }catch(e){lastError=e;}
 }
 throw lastError||new Error('Браузер не смог запустить MediaRecorder.');
}
function stopSttTracks(streamOverride){
 const stream=streamOverride||sttStream;if(!stream)return;
 try{stream.getTracks().forEach(t=>t.stop());}catch(e){}
 if(stream===sttStream)sttStream=null;
}
function sttSessionIsCurrent(sessionId){return Number(sessionId)===Number(sttSessionGeneration);}
function sttShowContextKey(st){
 const s=st&&st.negotiationShow||null;if(!s)return '';
 return [Number(s.round||0),Number(s.attempt||0),String(s.stage||''),Number(s.hardQuestionIndex||0),Number(s.storyQuestionIndex||0),String(s.roleTitle||'')].join(':');
}
function sttMicTimeoutError(message){const e=new Error(message||'Браузер не завершил запрос доступа к микрофону.');e.code='stt_mic_timeout';return e;}
function sttGetUserMediaAttempt(constraints,sessionId,timeoutMs){
 const ms=Math.max(1000,Number(timeoutMs||7000));
 return new Promise((resolve,reject)=>{
  let done=false,timedOut=false,timer=null,promise=null;
  const finishReject=e=>{if(done)return;done=true;if(timer)clearTimeout(timer);reject(e);};
  timer=setTimeout(()=>{if(done)return;timedOut=true;done=true;reject(sttMicTimeoutError('Браузер не ответил на запрос микрофона за '+Math.round(ms/1000)+' секунд.'));},ms);
  try{promise=navigator.mediaDevices.getUserMedia(constraints);}catch(e){finishReject(e);return;}
  Promise.resolve(promise).then(stream=>{
   if(timedOut||done||!sttSessionIsCurrent(sessionId)){stopSttTracks(stream);return;}
   done=true;if(timer)clearTimeout(timer);resolve(stream);
  },e=>{if(done)return;done=true;if(timer)clearTimeout(timer);reject(e);});
 });
}
async function sttEnumerateAudioInputs(){
 if(!navigator.mediaDevices||typeof navigator.mediaDevices.enumerateDevices!=='function')return [];
 try{
  const devices=await Promise.race([
   navigator.mediaDevices.enumerateDevices(),
   new Promise((_,reject)=>setTimeout(()=>reject(new Error('enumerate_timeout')),1500))
  ]);
  return Array.isArray(devices)?devices.filter(d=>d&&d.kind==='audioinput'):[];
 }catch(e){return [];}
}
async function sttAcquireMicrophone(sessionId){
 try{return await sttGetUserMediaAttempt({audio:true,video:false},sessionId,7000);}
 catch(firstError){
  if(!sttSessionIsCurrent(sessionId))throw firstError;
  if(String(firstError&&firstError.code||'')!=='stt_mic_timeout')throw firstError;
  sttStatus('Первый запрос микрофона не ответил. Повторяем подключение…');
  const inputs=await sttEnumerateAudioInputs();
  if(!sttSessionIsCurrent(sessionId))throw firstError;
  const preferred=inputs.find(d=>d.deviceId&&d.deviceId!=='default')||inputs.find(d=>d.deviceId)||null;
  const retryConstraints=preferred?{audio:{deviceId:{exact:preferred.deviceId}},video:false}:{audio:{echoCancellation:false,noiseSuppression:false,autoGainControl:false},video:false};
  try{return await sttGetUserMediaAttempt(retryConstraints,sessionId,7000);}
  catch(secondError){
   if(String(secondError&&secondError.code||'')==='stt_mic_timeout')throw sttMicTimeoutError('Браузер дважды не завершил подключение микрофона (по 7 секунд). Разрешение может быть выдано, но getUserMedia зависает внутри браузера.');
   throw secondError;
  }
 }
}
function sttInvalidateSession(){sttSessionGeneration+=1;sttSessionQuestionId=0;sttSessionShowContextKey='';return sttSessionGeneration;}
function clearSttHandshakeTimer(){if(sttHandshakeTimer){clearTimeout(sttHandshakeTimer);sttHandshakeTimer=null;}sttHandshakeStage='idle';}
function sttHandshakeTimeoutMessage(stage,ms){const sec=Math.max(1,Math.round(Number(ms||0)/1000));if(stage==='joined')return 'Gateway авторизовал команду, но за '+sec+' секунд не прислал сигнал готовности ready.';return 'WebSocket подключён и команда join отправлена, но Gateway за '+sec+' секунд не подтвердил joined/ready.';}
function armSttHandshakeTimer(sessionId,ws,stage='join',ms=12000){clearSttHandshakeTimer();sttHandshakeStage=stage;sttHandshakeTimer=setTimeout(()=>{sttHandshakeTimer=null;if(!sttSessionIsCurrent(sessionId)||sttSocket!==ws||!sttConnecting||sttRecording)return;const message=sttHandshakeTimeoutMessage(sttHandshakeStage,ms);sttHandshakeStage='idle';abortSttDictation(message);},ms);}
function sttCloseReasonText(reason){return String(reason||'').replace(/[\r\n\t]+/g,' ').trim().slice(0,180);}
function sttResetUi(){clearSttHandshakeTimer();sttConnecting=false;sttRecording=false;sttStopping=false;sttTimedOut=false;sttAborted=false;sttSessionQuestionId=0;sttSessionShowContextKey='';if(sttButton){sttButton.textContent=sttIdleLabel;sttButton.disabled=false;}}
function sttCleanup(closeSocket=true){
 clearSttHandshakeTimer();sttExpectedClose=!!closeSocket;
 if(closeSocket&&sttSocket){try{if(sttSocket.readyState===WebSocket.OPEN||sttSocket.readyState===WebSocket.CONNECTING)sttSocket.close(1000,'stt_done');}catch(e){}}
 sttSocket=null;stopSttTracks();sttRecorder=null;sttSendChain=Promise.resolve();sttResetUi();
}
function abortSttDictation(message){
 clearSttHandshakeTimer();sttInvalidateSession();sttAborted=true;sttExpectedClose=true;
 if(sttRecorder&&sttRecorder.state!=='inactive'){try{sttRecorder.stop();}catch(e){}}
 if(sttSocket){try{if(sttSocket.readyState===WebSocket.OPEN||sttSocket.readyState===WebSocket.CONNECTING)sttSocket.close(1000,'stt_aborted');}catch(e){}}
 stopSttTracks();sttSocket=null;sttRecorder=null;sttSendChain=Promise.resolve();sttConnecting=false;sttRecording=false;sttStopping=false;sttTimedOut=false;
 if(sttButton){sttButton.textContent=sttIdleLabel;sttButton.disabled=false;}if(message)sttStatus(message,false);
}
async function requestSttTicket(roleOverride){
 const roleName=roleOverride||'participant';
 const r=await post('voice_token',{game,role:roleName,team:roleName==='participant'?team:'',token,nonce});
 if(!r||!r.ok)throw new Error((r&&r.error)||'Не удалось получить голосовой токен.');
 return r;
}
async function requestHostVoiceCommandTicket(){
 const r=await post('voice_command_token',{game,token,nonce});
 if(!r||!r.ok)throw new Error((r&&r.error)||'Не удалось получить токен голосовой команды.');
 return r;
}
function sttJoinPayloadFromTicket(t,fallbackRole){
 const roleName=String(t&&t.joinRole||t&&t.role||fallbackRole||'participant');
 const msg={type:'join',game_id:String(t&&t.gameId||game),role:roleName,token:String(t&&t.token||'')};
 if(t&&t.teamId!==undefined&&t.teamId!==null&&String(t.teamId)!=='')msg.team_id=String(t.teamId);
 return msg;
}
function friendlySttCloseMessage(code,reason,stage){
 const c=Number(code||0),r=sttCloseReasonText(reason),low=r.toLowerCase(),where=stage==='handshake'?' при подключении':'';
 if(c===1008||low.includes('stt_auth_failed'))return 'STT Gateway отклонил авторизацию'+where+' (код '+(c||1008)+(r?', причина: '+r:'')+'). Проверьте совместимость STT-токена WordPress и Gateway.';
 if(c===1006)return 'Соединение распознавания оборвалось'+where+' (код 1006, без корректного кадра закрытия). Проверьте Gateway и сеть.';
 return 'Соединение распознавания закрыто'+where+': код '+String(c||0)+(r?', причина: '+r:'')+'.';
}
function sttUrlFromTicket(t){
 const raw=String(t&&t.wsUrl||'');if(!/^wss?:\/\//i.test(raw))throw new Error('Gateway не вернул WebSocket URL.');
 const u=new URL(raw,location.href);u.pathname='/stt';u.search='';u.hash='';return u.toString();
}
async function startSttDictation(){
 if(MODE!=='play'||sttConnecting||sttRecording||sttStopping)return;
 setupHostVoiceCommands();
 const text=sttTargetInput||$('answerText');if(!text||text.disabled)return;
 if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){sttStatus('Браузер не поддерживает запись микрофона.');return;}
 const sessionId=++sttSessionGeneration;
 sttSessionQuestionId=Number(lastState&&lastState.question&&lastState.question.id||lastQuestionId||0);
 sttSessionShowContextKey=sttShowContextKey(lastState);
 sttAborted=false;sttExpectedClose=false;sttTimedOut=false;sttConnecting=true;if(sttButton)sttButton.disabled=true;sttStatus('Разрешите доступ к микрофону…');
 let localStream=null;
 try{
  localStream=await sttAcquireMicrophone(sessionId);
  if(!sttSessionIsCurrent(sessionId)){stopSttTracks(localStream);return;}
  sttStream=localStream;
  const ticket=await requestSttTicket();
  if(!sttSessionIsCurrent(sessionId)){stopSttTracks(localStream);return;}
  const ws=new WebSocket(sttUrlFromTicket(ticket));
  if(!sttSessionIsCurrent(sessionId)){try{ws.close(1000,'stt_stale');}catch(e){}stopSttTracks(localStream);return;}
  sttSocket=ws;ws.binaryType='arraybuffer';
  ws.onopen=()=>{
   if(!sttSessionIsCurrent(sessionId)||sttSocket!==ws){try{ws.close(1000,'stt_stale');}catch(e){}return;}
   try{ws.send(JSON.stringify(sttJoinPayloadFromTicket(ticket,'participant')));sttStatus('Подключаем распознавание…');armSttHandshakeTimer(sessionId,ws,'join',12000);}catch(e){if(sttSessionIsCurrent(sessionId))abortSttDictation('Не удалось открыть голосовой ввод.');}
  };
  ws.onmessage=ev=>{
   if(!sttSessionIsCurrent(sessionId)||sttSocket!==ws)return;
   let m;try{m=JSON.parse(String(ev.data));}catch(e){return;}
   if(m.type==='joined'){sttStatus('Команда авторизована…');armSttHandshakeTimer(sessionId,ws,'joined',12000);return;}
   if(m.type==='ready'){
    clearSttHandshakeTimer();
    const tracks=sttStream&&sttStream.getAudioTracks?sttStream.getAudioTracks():[];
    if(!tracks.length||!tracks.some(t=>String(t.readyState||'')==='live')){abortSttDictation('Микрофон разрешён, но браузер не получил активную аудиодорожку.');return;}
    try{
     const started=startParticipantMediaRecorder(sttStream,rec=>{
      if(!sttSessionIsCurrent(sessionId))throw new Error('Устаревшая сессия диктовки.');
      sttRecorder=rec;sttSendChain=Promise.resolve();
      rec.ondataavailable=e=>{if(!sttSessionIsCurrent(sessionId)||!e.data||!e.data.size)return;sttSendChain=sttSendChain.then(async()=>{const buf=await e.data.arrayBuffer();if(sttSessionIsCurrent(sessionId)&&sttSocket===ws&&ws.readyState===WebSocket.OPEN&&!sttAborted)ws.send(buf);});};
      rec.onstop=async()=>{await sttSendChain;if(sttSessionIsCurrent(sessionId)&&!sttAborted&&!sttTimedOut&&sttSocket===ws&&ws.readyState===WebSocket.OPEN){try{ws.send(JSON.stringify({type:'stop'}));sttStatus('Завершаем распознавание…');}catch(e){}}if(sttSessionIsCurrent(sessionId))stopSttTracks();};
      rec.onerror=e=>{if(sttSessionIsCurrent(sessionId))abortSttDictation('Ошибка записи микрофона: '+sttRecorderErrorText(e&&e.error||e));};
     });
     if(!sttSessionIsCurrent(sessionId)){try{if(started.rec&&started.rec.state!=='inactive')started.rec.stop();}catch(e){}return;}
     sttRecorder=started.rec;sttConnecting=false;sttRecording=true;sttStopping=false;if(sttButton){sttButton.disabled=false;sttButton.textContent='■ Остановить диктовку';}sttStatus('Говорите',true);
    }catch(e){if(sttSessionIsCurrent(sessionId))abortSttDictation('Не удалось начать запись микрофона: '+sttRecorderErrorText(e));}
    return;
   }
   if(m.type==='interim'){const v=String(m.text||'').trim();sttStatus(v?('Распознаётся: '+v):'Говорите',true);return;}
   if(m.type==='timeout'){sttTimedOut=true;sttStopping=true;if(sttButton)sttButton.disabled=true;sttStatus('Одна диктовка — до 30 секунд. Завершаем фрагмент…');if(sttRecorder&&sttRecorder.state!=='inactive'){try{sttRecorder.stop();}catch(e){}}return;}
   if(m.type==='final'){
    const v=String(m.text||'').trim();
    if(v){const prev=String(text.value||'').trim();text.value=prev?(prev+' '+v):v;lastAnswerInputMode='voice';text.dispatchEvent(new Event('input',{bubbles:true}));text.dispatchEvent(new Event('change',{bubbles:true}));sttStatus(prev?'Текст добавлен. Можно продиктовать продолжение или отправить.':'Текст вставлен. Проверьте его перед отправкой.');}
    else sttStatus('Речь не распознана. Старый текст сохранён.');
    sttCleanup(true);return;
   }
   if(m.type==='error'){const detail=String(m.detail||m.code||m.reason||'неизвестная ошибка');abortSttDictation('Ошибка распознавания от Gateway: '+detail);}
  };
  ws.onerror=()=>{if(sttSessionIsCurrent(sessionId)&&!sttExpectedClose)sttStatus(sttConnecting?'Ошибка WebSocket при подключении; ожидаем код закрытия Gateway…':'Ошибка соединения с распознаванием.');};
  ws.onclose=e=>{
   if(!sttSessionIsCurrent(sessionId))return;
   const wasConnecting=!!sttConnecting,wasActive=!!(sttRecording||sttStopping),unexpected=!sttExpectedClose&&!sttAborted;
   clearSttHandshakeTimer();if(sttSocket===ws)sttSocket=null;
   if(unexpected&&(wasConnecting||wasActive)){stopSttTracks();sttRecorder=null;sttSendChain=Promise.resolve();sttResetUi();sttStatus(friendlySttCloseMessage(e.code,e.reason,wasConnecting?'handshake':'stream'));}
  };
 }catch(e){
  if(localStream&&localStream!==sttStream)stopSttTracks(localStream);
  if(sttSessionIsCurrent(sessionId))abortSttDictation((e&&e.message)||'Не удалось включить голосовой ввод.');
 }
}
function stopSttDictation(){
 if(sttStopping)return;if(!sttRecorder||sttRecorder.state==='inactive')return;sttStopping=true;sttRecording=false;if(sttButton)sttButton.disabled=true;sttStatus('Останавливаем запись…');try{sttRecorder.stop();}catch(e){abortSttDictation('Не удалось остановить запись.');}
}
function sequentialVoiceTimerWaiting(st){const g=st&&st.game||{};return String(g.phase||'')==='question_open'&&String(g.hostMode||'')==='ai'&&['classic_quiz','solution_price','negotiation_duel','jeopardy'].includes(String(g.formatKey||formatKey||''))&&!Number(g.questionDeadlineUnix||0);}
function renderClassicOrChgk(st){const g=st.game||{},q=st.question||null;
const voiceTimerWaiting=sequentialVoiceTimerWaiting(st);
hide('jeopardyArea',true);hide('jeopardyHostArea',true);hide('genericHostActions',MODE!=='host');
if(MODE==='play'){
 const mine=myTeam(st);if($('teamName'))$('teamName').textContent=mine?mine.name:ui('participant_team_label','Команда')+' '+team;selected=!!(mine&&mine.answeredCurrentQuestion);
 const box=$('options');if(box)box.innerHTML='';
 if(formatKey==='chgk'){
   const chgkPhase=String(st.formatRuntime&&st.formatRuntime.phase||'early_answer_offer');
   const finalOpen=!!q&&g.phase==='question_open'&&chgkPhase==='final_answer_open'&&!g.isPaused;
   showAnswerBox(finalOpen,'Введите окончательный ответ команды','Зафиксировать ответ',selected||!finalOpen,{finalAnswer:finalOpen,title:'ОКОНЧАТЕЛЬНЫЙ ОТВЕТ КОМАНДЫ',note:'На ввод ответа даётся 20 секунд. После отправки ответ изменить нельзя.'});
   if(box){
     if(q&&g.phase==='question_open'&&chgkPhase==='early_answer_offer'&&!g.isPaused&&!selected){const earlySec=Math.max(5,Math.min(60,Number(st.formatRuntime&&st.formatRuntime.earlyAnswerSeconds||5)));box.innerHTML='<button type="button" class="buzz-btn" id="earlyAnswerBtn">Досрочный ответ</button><div class="format-note">У команды есть '+earlySec+' сек., чтобы решить, отвечать досрочно или перейти к обсуждению.</div>';const eb=$('earlyAnswerBtn');if(eb)eb.addEventListener('click',()=>chgkAction('early_answer'));}
     else if(q&&g.phase==='question_open'&&chgkPhase==='discussion'&&!g.isPaused){const b=st.formatRuntime&&st.formatRuntime.bonusMinutes||{},n=Number(b.available||0);let html='<div class="format-note">Идёт обсуждение 60 секунд. После него откроется окно окончательного ответа на 20 секунд.</div>';if(n>0)html+='<button type="button" class="host-btn primary" id="bonusMinuteBtn">'+(n===1?'Дополнительная минута':(n+' дополнительные минуты'))+'</button><div class="format-note">Можно добавить 60 секунд к текущему обсуждению.</div>';box.innerHTML=html;const bm=$('bonusMinuteBtn');if(bm)bm.addEventListener('click',()=>chgkAction('bonus_minute'));}
     else if(!finalOpen) box.innerHTML='';
   }
 }else if(formatKey==='solution_price'){
   showAnswerBox(!!q&&g.phase==='question_open','Введите решение команды','Отправить решение',selected||g.phase!=='question_open'||voiceTimerWaiting,{finalAnswer:false});
 }else if(formatKey==='negotiation_duel'){
   showAnswerBox(!!q&&g.phase==='question_open','Введите вашу реплику','Отправить реплику',selected||g.phase!=='question_open'||voiceTimerWaiting,{finalAnswer:false});
 }else{
   showAnswerBox(false,'','',false,{finalAnswer:false});
   if(box&&q&&g.phase==='question_open') (q.options||[]).forEach(o=>{const v=(o&&typeof o==='object')?String(o.value??o.id??''):String(o),label=(o&&typeof o==='object')?String(o.label??o.text??v):String(o);const b=document.createElement('button');b.className='opt';b.disabled=selected||voiceTimerWaiting;b.innerHTML='<strong>'+esc(v)+'</strong> · '+esc(label);b.addEventListener('click',()=>answer(v));box.appendChild(b);});
 }
}
if(MODE==='host') renderGenericHost(st);
if(g.isPaused){const pp=String(g.pausedPhase||'discussion')==='final_answer_open'?'окончательного ответа':'обсуждения';setStatus('Игра на паузе. Заморожено время '+pp+': '+Math.max(0,Number(g.pausedRemainingSeconds||0))+' сек.');}
else if(g.status==='finished'){const duel=st.singleTeamDuel||{};setStatus(duel.enabled?((duel.winner==='experts'?'Матч завершён. Победили Знатоки. ':duel.winner==='game'?'Матч завершён. Победила Игра. ':'Матч завершён досрочно. ')+'Итоговый счёт: Знатоки '+Number(duel.expertsScore||0)+' : '+Number(duel.gameScore||0)+' Игра.'):'Готово. Итоговый счёт на табло.','ok');}
else if(g.phase==='question_open'){const chgkPhase=String(st.formatRuntime&&st.formatRuntime.phase||'early_answer_offer');if(voiceTimerWaiting)setStatus(MODE==='host'?'ИИ-ведущий озвучивает вопрос. Таймер начнётся после окончания реплики.':'Слушайте ведущего. Таймер начнётся после окончания реплики.');else setStatus(MODE==='host'?(formatKey==='chgk'?(chgkPhase==='question_narration'?'ИИ-ведущий озвучивает вопрос. Таймер начнётся после окончания озвучивания.':(chgkPhase==='final_answer_open'?'Открыт приём окончательных ответов.':(chgkPhase==='early_answer_offer'?'Идёт таймер досрочного ответа.':'Идёт 60-секундное обсуждение.'))):'Вопрос открыт.'):(formatKey==='chgk'?(chgkPhase==='question_narration'?'Слушайте вопрос. После окончания озвучивания начнутся 5 секунд на досрочный ответ.':(chgkPhase==='final_answer_open'?(selected?'Окончательный ответ команды зафиксирован. Изменить его уже нельзя.':'Сформулируйте и зафиксируйте один окончательный ответ команды.'):(chgkPhase==='early_answer_offer'?'Если готовы отвечать досрочно, нажмите «Досрочный ответ».':'Обсуждение — 60 секунд. После него откроется окно окончательного ответа на 20 секунд.'))):(formatKey==='solution_price'?(selected?'Решение команды отправлено.':'Введите решение команды до окончания времени.'):(formatKey==='negotiation_duel'?(selected?'Реплика команды отправлена. Ожидайте следующего хода.':'Введите переговорную реплику до окончания времени.'):'Выберите ответ до окончания времени.'))));}
else if(g.phase==='question_closed'){
 const flow=st.questionFlow||{},fp=String(flow.phase||'answers_closed');
 if(formatKey==='chgk'&&fp==='arbitration')setStatus(Number(flow.pendingArbitration||0)>0?'Ответы закрыты. Идёт арбитраж.':'Арбитраж завершён. Ожидайте раскрытия правильного ответа.');
 else if(formatKey==='chgk'&&(fp==='answer_reveal'||fp==='review'||fp==='completed')){const ans=st.question&&Array.isArray(st.question.correctAnswers)?Array.from(new Map(st.question.correctAnswers.map(v=>[String(v||'').trim().toLocaleLowerCase('ru-RU'),String(v||'').trim()])).values()).filter(Boolean).join(' / '):'';const exp=String(st.question&&st.question.explanation||''),duel=st.singleTeamDuel||{};const score=duel.enabled?(' · Счёт: Знатоки '+Number(duel.expertsScore||0)+' : '+Number(duel.gameScore||0)+' Игра'):'';setStatus('Правильный ответ: '+(ans||'—')+(exp?' · '+exp:'')+score,'ok');}
 else if(formatKey==='chgk')setStatus('Приём окончательных ответов закрыт. Переходим к арбитражу.');
 else setStatus(g.hostMode==='human'?'Ответы зафиксированы. Ведущий может открыть следующий вопрос.':'Ответы зафиксированы. Переходим к следующему вопросу.');
}
else setStatus(g.hostMode==='ai'?'Ожидайте: ведущий включает голос и запускает игру.':'Ожидайте запуска голосовым ведущим.');
if(MODE==='play')renderChgkReview(st);
}

function chgkFlowPhase(st){return String(st&&st.questionFlow&&st.questionFlow.phase||'');}
function chgkArbiterAllowed(st){
 const g=st&&st.game||{},fp=chgkFlowPhase(st),flow=st&&st.questionFlow||{};
 if(formatKey!=='chgk'||g.status==='finished')return false;
 if(g.phase==='question_closed')return true;
 return fp==='arbitration'||fp==='arbitrating'||Number(flow.pendingArbitration||0)>0;
}
function renderChgkArbiterActions(answer){
 const id=Number(answer&&answer.id||0);if(!id)return '';
 return '<div class="host-actions chgk-arbiter-actions"><strong style="width:100%;display:block;margin-bottom:4px">Решение арбитра</strong><button type="button" class="host-btn primary" data-chgk-arbiter="accept" data-answer-id="'+id+'">Засчитать</button><button type="button" class="host-btn" data-chgk-arbiter="reject" data-answer-id="'+id+'">Не засчитать</button></div>';
}
function attachChgkArbiterButtons(root){
 if(!root)return;
 root.querySelectorAll('[data-chgk-arbiter]').forEach(btn=>{
  btn.addEventListener('click',()=>{
   const answerId=Number(btn.getAttribute('data-answer-id')||0);
   const accept=String(btn.getAttribute('data-chgk-arbiter'))==='accept';
   if(!answerId)return;
   btn.disabled=true;
   hostAction('score_answer',{answer_id:answerId,verdict:accept?'accepted':'rejected',points:accept?1:0,comment:accept?'Решение арбитра: ответ засчитан.':'Решение арбитра: ответ не засчитан.',request_id:'human-arbiter-'+answerId+'-'+Date.now()}).then(()=>setStatus(accept?'Решение арбитра сохранено: ответ засчитан.':'Решение арбитра сохранено: ответ не засчитан.','ok')).catch(e=>setStatus((e&&e.message)||'Не удалось сохранить решение арбитра.','err'));
  });
 });
}
function stopAiClientAutomation(reason){
 if(aiAutoStartTimer){clearTimeout(aiAutoStartTimer);aiAutoStartTimer=null;}
 aiAutoStartKey='';
 window.dispatchEvent(new CustomEvent('ckmqp:ai-host-stop',{detail:{reason:String(reason||'manual_takeover')}}));
}
function maybeAutoStartAiHost(st){
 if(MODE!=='host'||!st||!st.game||manualTakeoverPending)return;
 const g=st.game,ai=String(g.hostMode||'')==='ai',waiting=String(g.phase||'')==='waiting',done=String(g.status||'')==='finished';
 if(!ai||!waiting||done)return;
 const sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway';
 if(sergey&&!voiceReady){setStatus('ИИ-ведущий ждёт: Диагностика голоса — соединение готово.');return;}
 const key=String(g.stateVersion||0)+'-'+String(g.currentQuestionId||0)+'-'+String(g.phase||'');
 if(aiAutoStartKey===key)return;
 aiAutoStartKey=key;
 setStatus('Диагностика голоса: соединение готово. ИИ-ведущий автоматически задаёт вопрос…');
 aiAutoStartTimer=setTimeout(()=>{
  aiAutoStartTimer=null;
  const current=lastState&&lastState.game||{};
  if(manualTakeoverPending||String(current.hostMode||'')!=='ai'||aiAutoStartKey!==key)return;
  if(!sergey||voiceReady)hostAction('start');else setStatus('ИИ-ведущий ждёт: Диагностика голоса — соединение готово.');
 },250);
}
function renderHostModeSwitch(st){
 const btn=$('hostModeSwitch'),g=st&&st.game||{};if(!btn)return;
 const done=String(g.status||'')==='finished',ai=String(g.hostMode||'')==='ai';
 btn.hidden=done;btn.disabled=done||manualTakeoverPending;
 btn.textContent=ai?'Переключить на ведущего-человека':'Передать управление ИИ';
 btn.title=ai?'Остановить автоматическое ведение ИИ и продолжить игру вручную с текущего состояния.':'Передать текущее состояние игры ИИ-ведущему без перезапуска.';
}
function renderGenericHost(st){
 renderStandaloneChgkHostGuide();
 renderHostModeSwitch(st);
 const g=st.game||{},next=$('hostNext'),close=$('hostClose'),pause=null,modeSwitch=$('hostModeSwitch'),finish=$('hostFinish'),answers=$('hostAnswers');if(!next)return;
 const done=g.status==='finished',waiting=g.phase==='waiting',ai=g.hostMode==='ai',human=g.hostMode==='human',sergey=ai&&String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway',needsVoice=waiting&&sergey&&!voiceReady;
 const flow=st.questionFlow||{},chgkClosed=formatKey==='chgk'&&g.phase==='question_closed',canReveal=chgkClosed&&!!flow.canRevealAnswer,revealed=chgkClosed&&!!flow.answerRevealed,arbAllowed=chgkArbiterAllowed(st);
 next.hidden=!!ai;
 next.disabled=done||ai||g.phase==='question_open'||(!human&&!waiting)||needsVoice||(chgkClosed&&!revealed);
 const chgkPhase=String(st.formatRuntime&&st.formatRuntime.phase||'discussion');
 const chgkShowClose=formatKey==='chgk'?(canReveal||chgkPhase==='final_answer_open'):true;
 if(close)close.hidden=!chgkShowClose;
 if(close)close.disabled=done||!human||!chgkShowClose||(!(g.phase==='question_open')&&!canReveal);
 finish.disabled=done||!human;
 if(modeSwitch){renderHostModeSwitch(st);}
 if(g.isPaused){next.disabled=true;if(close)close.disabled=true;}
 next.textContent=waiting?ui('host_start_human_button','Задать вопрос'):ui('host_next_button','Следующий вопрос');
 if(close){if(formatKey==='chgk'&&canReveal)close.textContent='Показать правильный ответ';else close.textContent=formatKey==='chgk'?'Закрыть приём ответов':ui('host_close_button','Закрыть вопрос');}
 if(finish)finish.textContent=formatKey==='chgk'?'Завершить досрочно':ui('host_finish_button','Завершить игру');
 next.title=ai?'Кнопка «Задать вопрос» доступна только голосовому ведущему-человеку. ИИ-ведущий задаёт вопрос автоматически.':(needsVoice?'Сначала дождитесь подключения голоса Сергея.':(chgkClosed&&!revealed?'Сначала завершите арбитраж и покажите правильный ответ.':''));
 const rows=st.answers||[];
 if(answers){
  answers.innerHTML=rows.length?rows.map(a=>`<div class="answer-row"><strong>${esc(a.teamName||a.teamKey||'Команда')}</strong><div>${esc(a.answerText||'—')}</div><div class="muted">${esc(verdictText(a.verdict))} · ${Number(a.awardedPoints||0)} очко</div>${arbAllowed?renderChgkArbiterActions(a):''}</div>`).join(''):'<div class="muted">'+esc(ui('host_answers_empty','Ответов пока нет.'))+'</div>';
  if(arbAllowed)attachChgkArbiterButtons(answers);
 }
 maybeAutoStartAiHost(st);
}
function boardState(st){return st.formatRuntime&&st.formatRuntime.board&&st.formatRuntime.board.enabled?st.formatRuntime.board:(st.jeopardyBoard||{enabled:false});}
function runtime(st){return st.formatRuntime||{};}
function renderBoard(st){const area=$('jeopardyArea'),board=$('jeopardyBoard');if(!area||!board)return;area.hidden=false;const rt=runtime(st),b=boardState(st),g=st.game||{},mine=myTeam(st),selectorId=Number(b.selectorTeamId||rt.selectorTeamId||0),selector=teamById(st,selectorId);if($('jeopardySelector'))$('jeopardySelector').textContent=selector?'Вопрос выбирает: '+selector.name:'Право выбора ещё не определено';
 const rounds=b.rounds||[];if(!rounds.length){board.innerHTML='<div class="muted">Игровое поле пока недоступно.</div>';return;}
 let html='';rounds.forEach(round=>{html+='<div class="board-round"><div class="board-round-title">'+esc(round.title||'Игровое поле')+'</div><div class="board-grid">';(round.categories||[]).forEach(cat=>{html+='<div class="board-category"><div class="board-category-title">'+esc(cat.title||cat.key)+'</div><div class="board-cells">';(cat.cells||[]).forEach(cell=>{const available=cell.state==='available',pending=cell.state==='selected'&&Number(b.selectedQuestionId||0)===Number(cell.questionId)&&g.phase!=='question_open';let can=false;if((available||pending)&&g.status!=='finished'&&g.phase!=='question_open'){if(MODE==='host'&&g.hostMode==='human')can=true;else if(MODE==='play'&&g.hostMode==='ai'&&g.startAuthorized&&mine&&selectorId>0&&Number(mine.id)===selectorId)can=true;}const cls='board-cell '+(available?'available':(pending?'selected-pending':'used'))+(can?' selectable':'');const label=available?Number(cell.value):(pending?'↻ '+Number(cell.value):'×');html+=`<button type="button" class="${cls}" data-qid="${Number(cell.questionId)}" ${can?'':'disabled'}>${label}</button>`;});html+='</div></div>';});html+='</div></div>';});board.innerHTML=html;
 board.querySelectorAll('.board-cell.selectable').forEach(btn=>btn.addEventListener('click',()=>{const qid=Number(btn.dataset.qid||0);if(MODE==='host') hostJeopardy('jeopardy_select',{question_id:qid,selector_team_id:selectorId});else teamJeopardy('select_cell',{question_id:qid});}));
}
function renderJeopardyParticipant(st){const g=st.game||{},rt=runtime(st),mine=myTeam(st),myId=Number(mine&&mine.id||0),q=st.question||null,cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{},voiceWaiting=sequentialVoiceTimerWaiting(st);hide('genericHostActions',true);hide('jeopardyHostArea',true);if($('teamName'))$('teamName').textContent=mine?mine.name:ui('participant_team_label','Команда')+' '+team;renderBoard(st);const action=$('jeopardyAction');if(action){action.hidden=false;action.innerHTML='';}
 selected=!!st.yourAnswer;
 if(final.enabled&&final.started){
   if(final.awaitingVoice||voiceWaiting){showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Слушайте финальный вопрос. Таймер ответа начнётся после окончания реплики ведущего.');}
   else if(final.open){showAnswerBox(true,'Финальный ответ команды','Зафиксировать финальный ответ',!!st.yourAnswer);setStatus(st.yourAnswer?'Финальный ответ зафиксирован. Ожидайте завершения приёма.':'Финал: отправьте один скрытый ответ. Верный ответ — +'+Number(final.fixedPoints||500)+' баллов.');}
   else{showAnswerBox(false,'','',false,{finalAnswer:false});setStatus(final.revealed?'Финальные ответы раскрыты.':'Приём финальных ответов завершён.');}
   return;
 }
 if(cat.enabled&&cat.status==='pending_target'){
   showAnswerBox(false,'','',false,{finalAnswer:false});const source=teamById(st,cat.sourceTeamId);if(g.hostMode==='ai'&&myId===Number(cat.sourceTeamId)&&action){action.innerHTML='<div class="special-banner"><strong>Секретная передача</strong><div>Выберите другую команду, которой перейдёт вопрос.</div><div class="host-actions">'+(st.teams||[]).filter(t=>Number(t.id)!==myId).map(t=>`<button type="button" class="host-btn" data-cat-team="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div></div>';action.querySelectorAll('[data-cat-team]').forEach(b=>b.addEventListener('click',()=>teamJeopardy('assign_cat',{target_team_id:Number(b.dataset.catTeam)})));}else setStatus(source?'«Секретная передача»: '+source.name+' выбирает команду-получателя.':'Ожидается выбор команды для «Секретной передачи».');return;
 }
 if(cat.enabled&&cat.status==='assigned'&&Number(cat.questionId)===Number(g.currentQuestionId||rt.selectedQuestionId||0)){
   if(voiceWaiting){showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Слушайте ведущего. Таймер и приём ответа начнутся после окончания вопроса.');}
   else if(cat.open&&myId===Number(cat.targetTeamId)){showAnswerBox(true,'Ваш ответ','Отправить ответ',!!st.yourAnswer);setStatus(st.yourAnswer?'Ответ отправлен. Ожидайте решения.':'Секретная передача: вопрос передан вашей команде. Ответьте до окончания времени.');}
   else{showAnswerBox(false,'','',false,{finalAnswer:false});const target=teamById(st,cat.targetTeamId);setStatus(target?'Секретная передача: отвечает только '+target.name+'.':'Секретная передача.');}return;
 }
 if(q&&g.phase==='question_open'){
   const blocked=(buzz.blockedTeamIds||[]).map(Number).includes(myId),active=buzz.active||null;
   if(voiceWaiting){showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Слушайте ведущего. Кнопка ответа и таймер откроются после окончания вопроса.');}
   else if(active&&Number(active.teamId)===myId){
     if(active.hasAnswer){showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Ответ отправлен. Ожидайте решения.');}
     else{showAnswerBox(true,'Ваш ответ','Отправить ответ',!!st.yourAnswer||Number(active.secondsRemaining||0)<=0);setStatus('Право ответа у вашей команды. Осталось '+Number(active.secondsRemaining||0)+' сек.');}
   }else if(active){showAnswerBox(false,'','',false,{finalAnswer:false});const owner=teamById(st,active.teamId);setStatus('Право ответа сейчас у '+(owner?owner.name:'другой команды')+'.');}
   else if(blocked){showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Ваш предыдущий ответ отклонён. На этот вопрос повторно отвечать нельзя.');}
   else if(buzz.open){showAnswerBox(false,'','',false,{finalAnswer:false});if(action)action.innerHTML='<button type="button" class="buzz-btn" id="buzzBtn">ОТВЕЧАЕМ!</button>';const b=$('buzzBtn');if(b)b.addEventListener('click',()=>teamJeopardy('buzz',{}));setStatus('Знаете ответ? Нажмите «ОТВЕЧАЕМ!» раньше соперников.');}
   else{showAnswerBox(false,'','',false,{finalAnswer:false});setStatus('Ожидайте открытия кнопки ответа.');}
 }else{
   showAnswerBox(false,'','',false,{finalAnswer:false});const b=boardState(st),selector=teamById(st,b.selectorTeamId);if(g.status==='finished')setStatus('Игра завершена.','ok');else if(g.hostMode==='ai'&&mine&&Number(b.selectorTeamId||0)>0&&Number(mine.id)===Number(b.selectorTeamId))setStatus('Ваш ход: выберите свободную ячейку игрового поля.');else if(g.hostMode==='human')setStatus('Ячейку выбирает ведущий.');else setStatus(selector?'Ячейку выбирает '+selector.name+'.':'Ожидайте выбора ячейки.');
 }
}
function pendingAnswerForTeam(st,teamId){return (st.answers||[]).find(a=>Number(a.teamId||0)===Number(teamId))||null;}
function renderJeopardyHost(st){const g=st.game||{},rt=runtime(st),cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{},host=$('jeopardyHostArea'),voiceWaiting=sequentialVoiceTimerWaiting(st);renderHostModeSwitch(st);hide('genericHostActions',true);showAnswerBox(false,'','',false,{finalAnswer:false});renderBoard(st);if(!host)return;host.hidden=false;let html='';const b=boardState(st),selector=teamById(st,b.selectorTeamId);if(g.hostMode==='ai'&&!g.startAuthorized){const sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway',disabled=sergey&&!voiceReady;html+='<div class="host-block"><strong>Старт игры</strong><div class="muted">'+(disabled?'Сначала включите Сергея и дождитесь соединения.':'Голос готов. Можно запускать игру.')+'</div><div class="host-actions"><button type="button" class="host-btn primary" data-ai-start="1" '+(disabled?'disabled':'')+'>Запустить игру</button></div></div>';}
 html+='<div class="host-block"><strong>Право выбора</strong><div class="host-actions">'+(st.teams||[]).map(t=>`<button type="button" class="host-btn ${Number(t.id)===Number(b.selectorTeamId)?'primary':''}" data-set-selector="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div><div class="muted">Текущий выбор: '+esc(selector?selector.name:'не определён')+'</div></div>';
 if(cat.enabled&&cat.status==='pending_target') html+='<div class="host-block special-banner"><strong>Секретная передача</strong><p>Назначьте команду-получателя.</p><div class="host-actions">'+(st.teams||[]).filter(t=>Number(t.id)!==Number(cat.sourceTeamId)).map(t=>`<button type="button" class="host-btn" data-host-cat="${Number(t.id)}">${esc(t.name)}</button>`).join('')+'</div></div>';
 if(buzz.active&&buzz.resolvable){const a=pendingAnswerForTeam(st,buzz.active.teamId),owner=teamById(st,buzz.active.teamId);html+='<div class="host-block"><strong>Ответ после кнопки: '+esc(owner?owner.name:'Команда')+'</strong><div class="answer-preview">'+esc(a&&a.answerText?a.answerText:(buzz.active.hasAnswer?'Ответ получен':'Ответ ещё не отправлен'))+'</div><div class="host-actions"><button type="button" class="host-btn primary" data-buzz-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-buzz-decision="rejected">Отклонить</button></div></div>';}
 if(cat.enabled&&cat.resolvable&&cat.status==='assigned'){const a=pendingAnswerForTeam(st,cat.targetTeamId),target=teamById(st,cat.targetTeamId);html+='<div class="host-block"><strong>Секретная передача: '+esc(target?target.name:'Команда')+'</strong><div class="answer-preview">'+esc(a&&a.answerText?a.answerText:'Ответ ещё не отправлен')+'</div>'+(a?'<div class="host-actions"><button type="button" class="host-btn primary" data-cat-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-cat-decision="rejected">Отклонить</button></div>':'')+'</div>';}
 if(g.phase==='question_open'&&!buzz.resolvable&&!(cat.enabled&&cat.resolvable)) html+='<div class="host-block"><button type="button" class="host-btn" data-close-jeopardy="1">Закрыть вопрос без правильного ответа</button></div>';
 if(final.enabled){html+='<div class="host-block"><strong>Финал · '+Number(final.fixedPoints||500)+' баллов</strong><div class="muted">Ответили: '+Number(final.submittedCount||0)+' из '+Number(final.teamCount||0)+'</div><div class="host-actions">';if(final.canStart)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_start_final">Начать финал</button>';if(final.open)html+='<button type="button" class="host-btn" data-final-action="jeopardy_close_final">Закрыть приём ответов</button>';if(final.canReveal)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_reveal_final">Раскрыть ответы</button>';html+='</div>';
 if(final.revealed){html+='<div class="final-rows">'+(final.rows||[]).map(r=>`<div class="answer-row"><strong>${esc(r.teamName)}</strong><div>${esc(r.answerText||'—')}</div>${r.resolved?`<div class="muted">${esc(verdictText(r.verdict||'no_answer'))}</div>`:`<div class="host-actions"><button type="button" class="host-btn primary" data-final-team="${Number(r.teamId)}" data-final-decision="accepted">Принять</button><button type="button" class="host-btn danger" data-final-team="${Number(r.teamId)}" data-final-decision="rejected">Отклонить</button></div>`}</div>`).join('')+'</div>';if(final.allResolved)html+='<button type="button" class="host-btn primary" data-final-action="jeopardy_finish_final">Завершить финал</button>';}
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
 if(g.status==='finished')setStatus('Игра завершена.','ok');else if(g.hostMode==='ai'&&!g.startAuthorized)setStatus('Сначала включите голос Сергея и запустите игру.');else if(voiceWaiting)setStatus(final.started?'ИИ-ведущий озвучивает финальный вопрос. Таймер начнётся после окончания реплики.':'ИИ-ведущий озвучивает вопрос. Таймер и кнопка ответа откроются после окончания реплики.');else if(final.started)setStatus('Управляйте финальным раундом.');else if(g.phase==='question_open')setStatus('Вопрос открыт. Управляйте правом ответа и арбитражем.');else setStatus('Выберите свободную ячейку игрового поля.');
}
function boardComplete(b){const cells=[];(b.rounds||[]).forEach(r=>(r.categories||[]).forEach(c=>(c.cells||[]).forEach(x=>cells.push(x))));return cells.length>0&&cells.every(x=>x.state==='played');}
function renderJeopardyScoreboard(st){renderBoard(st);hide('genericHostActions',true);hide('jeopardyHostArea',true);showAnswerBox(false,'','',false,{finalAnswer:false});const g=st.game||{},rt=runtime(st),cat=rt.catInBag||{},buzz=rt.buzzer||{},final=rt.finalRound||{},voiceWaiting=sequentialVoiceTimerWaiting(st);if(g.status==='finished')setStatus('Игра завершена.','ok');else if(voiceWaiting)setStatus(final.started?'Финальный вопрос озвучивается. Таймер начнётся после окончания реплики.':'Вопрос озвучивается. Таймер и кнопка ответа откроются после окончания реплики.');else if(final.started)setStatus(final.revealed?'Финальные ответы раскрыты.':'Финал: команды отправляют скрытые ответы.');else if(cat.enabled&&cat.status==='pending_target')setStatus('Секретная передача: выбирается команда-получатель.');else if(cat.enabled&&cat.status==='assigned'){const t=teamById(st,cat.targetTeamId);setStatus('Секретная передача: отвечает '+(t?t.name:'назначенная команда')+'.');}else if(buzz.active){const t=teamById(st,buzz.active.teamId);setStatus('Право ответа: '+(t?t.name:'команда')+'.');}else if(g.phase==='question_open')setStatus('Кнопка ответа открыта.');else{const b=boardState(st),t=teamById(st,b.selectorTeamId);setStatus(t?'Следующую ячейку выбирает '+t.name+'.':'Ожидание выбора ячейки.');}}
function renderJeopardy(st){hide('options',true);hide('genericAnswersCard',true);if(MODE==='play')renderJeopardyParticipant(st);else if(MODE==='host')renderJeopardyHost(st);else renderJeopardyScoreboard(st);}
let showReviewAutoKey='';
function showFinalRanking(s){
 const rows=(s.scores||[]).slice().sort((a,b)=>Number(b.score||0)-Number(a.score||0)||String(a.name||'').localeCompare(String(b.name||''),'ru'));
 const winners=new Set((s.winners||[]).map(String)),shared=winners.size>1;
 let previousScore=null,rank=0;
 return rows.map((row,index)=>{const score=Number(row.score||0),name=String(row.name||'');if(shared&&winners.has(name)){rank=1;}else if(previousScore===null||score!==previousScore){rank=index+1;}previousScore=score;return Object.assign({},row,{rank});});
}
function showFinalTableHtml(s){
 const finalScores=showFinalRanking(s),winnerNames=new Set((s.winners||[]).map(String));
 let html='<div style="overflow-x:auto;margin-top:16px"><table style="width:100%;border-collapse:collapse;min-width:760px"><thead><tr><th style="text-align:left;padding:10px;border-bottom:1px solid #355273">Место</th><th style="text-align:left;padding:10px;border-bottom:1px solid #355273">Команда</th><th style="padding:10px;border-bottom:1px solid #355273">Удержи цель</th><th style="padding:10px;border-bottom:1px solid #355273">Скрытая задача</th><th style="padding:10px;border-bottom:1px solid #355273">Неудобный вопрос</th><th style="padding:10px;border-bottom:1px solid #355273">Проверь историю</th><th style="padding:10px;border-bottom:1px solid #355273">Итого</th></tr></thead><tbody>';
 finalScores.forEach(row=>{const win=winnerNames.has(String(row.name||''));html+='<tr'+(win?' style="font-weight:800;background:rgba(220,234,255,.06)"':'')+'><td style="padding:10px;border-bottom:1px solid #223a55">'+Number(row.rank||0)+'</td><td style="padding:10px;border-bottom:1px solid #223a55">'+esc(row.name||'Команда')+'</td><td style="text-align:center;padding:10px;border-bottom:1px solid #223a55">'+Number(row.round1||0)+'</td><td style="text-align:center;padding:10px;border-bottom:1px solid #223a55">'+Number(row.round2||0)+'</td><td style="text-align:center;padding:10px;border-bottom:1px solid #223a55">'+Number(row.round3||0)+'</td><td style="text-align:center;padding:10px;border-bottom:1px solid #223a55">'+Number(row.round4||0)+'</td><td style="text-align:center;padding:10px;border-bottom:1px solid #223a55;font-size:20px">'+Number(row.score||0)+'</td></tr>';});
 return html+'</tbody></table></div>';
}
function showFinalHeadline(s){
 const winners=(s.winners||[]).map(String).filter(Boolean),winning=Number(s.winningScore||0),ranking=showFinalRanking(s);
 let line=winners.length>1?'Первое место разделили: '+winners.join(', '):'Победитель: '+(winners[0]||'—');
 if(MODE==='play'&&s.teamName){const mine=ranking.find(x=>String(x.name||'')===String(s.teamName));if(mine)line='Ваша команда: '+Number(mine.rank||0)+' место · '+Number(mine.score||0)+' очков. '+line+'.';}
 return '<h2 style="margin-top:0">Итоги «Переговори другого»</h2><p><strong>'+esc(line)+'</strong></p><p class="muted">Лучший результат: '+winning+' из 280 баллов.</p>';
}
function renderShowFinalSurface(st){
 const s=st.negotiationShow||{},teamsEl=$('teams');
 if($('title'))$('title').textContent='Переговори другого — итоги';
 if($('phase'))$('phase').textContent='Игра завершена';
 hide('timer',true);hide('question',true);
 if($('teamName'))$('teamName').textContent=MODE==='play'?(s.teamName||'Команда'):'Переговори другого';
 if(teamsEl){const ranking=showFinalRanking(s);teamsEl.innerHTML=ranking.map(row=>'<div class="team"><strong>'+Number(row.rank||0)+' место · '+esc(row.name||'Команда')+'</strong><div>'+Number(row.score||0)+' очков</div></div>').join('');const title=teamsEl.parentNode.querySelector('h2');if(title)title.textContent='Итоговый рейтинг';}
 $('showRoleTitle').textContent=MODE==='scoreboard'?'Финальный результат':(MODE==='host'?'Игра завершена':'Ваш результат');
 $('showBrief').hidden=true;if($('showActionHint'))$('showActionHint').hidden=true;$('showProgress').hidden=true;$('showLog').hidden=true;$('showCompose').hidden=true;$('showVote').hidden=true;$('showReady').hidden=true;if($('showStoryReady'))$('showStoryReady').hidden=true;$('showContinue').hidden=true;$('showNextRound').hidden=true;$('showPause').hidden=true;$('showRuleNote').hidden=true;$('showError').textContent='';
 renderShowReviews(st);
 const aiHost=String((st.game||{}).hostMode||'')==='ai';setStatus(MODE==='scoreboard'?'Финальный результат игры.':(MODE==='host'?(aiHost?'Игра завершена. Сергей объявляет итог один раз.':'Игра завершена. Итоговый результат зафиксирован.'):'Игра завершена. Итоговый результат зафиксирован.'),'ok');
}
function renderShowManualReview(st,card){
 const s=st.negotiationShow||{};let panel=$('showManualReview');
 if(!panel){panel=document.createElement('section');panel.id='showManualReview';card.appendChild(panel);}
 panel.hidden=MODE!=='host'||!s.canManualReview;if(panel.hidden)return;
 const key=String(s.round)+':'+String(s.attempt)+':'+String(s.reviewAttempt??'');
 if(panel.dataset.key!==key){
  panel.dataset.key=key;
  const round=Number(s.round),criteria=round===3?{
   story_clarity:'Ясность и структура истории',task_fidelity:'Выполнение условия истории',factual_consistency:'Непротиворечивость фактов',answer_directness:'Прямота и полнота ответов',answer_grounding:'Обоснованность ответов',version_stability:'Устойчивость версии под проверкой',communication_quality:'Корректность и ясность общения'
  }:{
   request_specificity:'Конкретность запроса',respect:'Уважение к позиции оппонента',interests:'Аргументация интересами, а не позициями',flexibility:'Гибкость и варианты',objections:'Работа с возражениями',agreement_fixation:'Фиксация договорённостей',emotional_control:'Эмоциональный контроль и тон'
  };
  const roles=(round===0||round===1)?['speaker','opponent']:['speaker'];
  const roleLabels={speaker:'Переговорщик / отвечающая команда',opponent:'Оппонент'};
  const speakerSlot=Number(s.attempt)+1,teamCount=Array.isArray(st.teams)&&st.teams.length?st.teams.length:2;
  const slotForRole=role=>role==='speaker'?speakerSlot:((speakerSlot%teamCount)+1);
  const teamName=slot=>{const t=(st.teams||[]).find(x=>Number(x.slot??x.slot_no)===Number(slot));return t&&((t.name||t.team_name))?String(t.name||t.team_name):'Команда '+slot;};
  const criterionHtml=(role,key,label)=>'<div class="show-manual-criterion" style="margin:14px 0;padding:12px;border:1px solid #29445f;border-radius:12px">'+
   '<div style="font-weight:700;margin-bottom:8px">'+label+'</div>'+ 
   '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">'+Array.from({length:11},(_,i)=>'<label style="display:inline-flex;align-items:center;gap:3px;padding:5px 7px;border:1px solid #355273;border-radius:8px;cursor:pointer"><input type="radio" name="showManual_'+role+'_'+key+'" value="'+i+'">'+i+'</label>').join('')+'</div>'+ 
   '<textarea id="showManualReason_'+role+'_'+key+'" maxlength="4000" rows="2" placeholder="Коротко обоснуйте оценку…" style="width:100%;box-sizing:border-box"></textarea>'+ 
   '</div>';
  let html='<h3>Ручная оценка ведущего</h3><p>ИИ-оценка не получена. Оцените текущий этап по той же семикритериальной системе, которая используется ИИ. Каждый критерий — от 0 до 10 баллов.</p>';
  roles.forEach(role=>{
   const title=round===3?'Рассказчик':roleLabels[role],name=teamName(slotForRole(role));
   html+='<div class="show-manual-participant" data-manual-role="'+role+'" style="margin:16px 0;padding:14px;border:1px solid #355273;border-radius:14px"><h4 style="margin:0 0 10px">'+esc(name)+' · '+esc(title)+'</h4>';
   Object.entries(criteria).forEach(([key,label])=>{html+=criterionHtml(role,key,label);});
   html+='<label style="display:block;margin:12px 0 6px;font-weight:700" for="showManualSummary_'+role+'">Итог</label><textarea id="showManualSummary_'+role+'" maxlength="4000" rows="3" placeholder="Краткий итог по выступлению…" style="width:100%;box-sizing:border-box"></textarea>'+
    '<label style="display:block;margin:12px 0 6px;font-weight:700" for="showManualRecommendation_'+role+'">Что улучшить</label><textarea id="showManualRecommendation_'+role+'" maxlength="4000" rows="3" placeholder="Главное, что стоит улучшить…" style="width:100%;box-sizing:border-box"></textarea>'+
    '<p style="margin:10px 0 0"><strong>Итого: <span data-manual-total="'+role+'">0</span> / 70</strong></p></div>';
  });
  html+='<div style="margin-top:14px"><label style="display:block;margin:0 0 6px;font-weight:700" for="showManualOverall">Общий итог</label><textarea id="showManualOverall" maxlength="8000" rows="3" placeholder="Краткий общий вывод по этапу…" style="width:100%;box-sizing:border-box"></textarea></div>'+
   '<button type="button" class="host-btn primary" id="showManualSave" style="margin-top:12px">Сохранить ручную оценку</button>';
  panel.innerHTML=html;
  const recalc=()=>{
   roles.forEach(role=>{
    let total=0;Object.keys(criteria).forEach(k=>{const x=panel.querySelector('input[name="showManual_'+role+'_'+k+'"]:checked');if(x)total+=Number(x.value||0);});
    const el=panel.querySelector('[data-manual-total="'+role+'"]');if(el)el.textContent=String(total);
   });
  };
  panel.querySelectorAll('input[type="radio"]').forEach(x=>x.addEventListener('change',recalc));
  $('showManualSave').addEventListener('click',()=>{
   const participants={};let invalid=false;
   roles.forEach(role=>{
    const criteriaData={};Object.entries(criteria).forEach(([key])=>{
     const selected=panel.querySelector('input[name="showManual_'+role+'_'+key+'"]:checked');
     const reasonEl=$('showManualReason_'+role+'_'+key),reason=(reasonEl&&reasonEl.value||'').trim();
     if(!selected||!reason)invalid=true;
     criteriaData[key]={points:selected?Number(selected.value):0,reason,evidence:[]};
    });
    const summary=($('showManualSummary_'+role)?.value||'').trim(),recommendation=($('showManualRecommendation_'+role)?.value||'').trim();
    if(!summary||!recommendation)invalid=true;
    participants[role]={criteria,summary,recommendation};
    participants[role].criteria=criteriaData;
   });
   if(invalid){$('showError').textContent='Заполните все 7 оценок, обоснования, итог и рекомендации для каждого участника.';return;}
   const review={participants,summary:($('showManualOverall')?.value||'').trim()};
   showAction('manual_review',{review});
  });
 }
 $('showManualSave').disabled=showBusy;
}
function renderShowReviews(st){
 const s=st.negotiationShow||{},card=$('showRoleCard');if(!card)return;
 let panel=$('showReviewPanel');if(!panel){panel=document.createElement('section');panel.id='showReviewPanel';card.appendChild(panel);}
 if(s.stage==='game_complete'){panel.innerHTML='<div class="answer-row">'+showFinalHeadline(s)+showFinalTableHtml(s)+'</div>';return;}
 const criteria={request_specificity:'Конкретность запроса',respect:'Уважение к позиции оппонента',interests:'Аргументация интересами',flexibility:'Гибкость и варианты',objections:'Работа с возражениями',agreement_fixation:'Фиксация договорённостей',emotional_control:'Эмоциональный контроль и тон'};
 const storyCriteria={story_clarity:'Ясность и структура истории',task_fidelity:'Выполнение условия истории',factual_consistency:'Непротиворечивость фактов',answer_directness:'Прямота и полнота ответов',answer_grounding:'Обоснованность ответов',version_stability:'Устойчивость версии под проверкой',communication_quality:'Корректность и ясность общения'};
 let html='';
 if(Number(s.round)===1&&Array.isArray(s.revealedTasks)&&s.revealedTasks.length){html+='<div class="answer-row"><h3>Скрытые задачи раскрыты</h3><ol>'+s.revealedTasks.map(x=>'<li>'+esc(x)+'</li>').join('')+'</ol></div>';}
 for(const row of s.reviews||[]){
  if(row.status==='not_started')continue;
  html+='<div class="answer-row"><h3>'+(String(row.kind||'').startsWith('story')?'Проверка истории ':'Оценка испытания ')+(Number(row.attempt)+1)+'</h3>';
  if(row.status==='done'){
   const r=row.result||{};
   if(r.participants){
    for(const role of (Array.isArray(r.displayRoles)&&r.displayRoles.length?r.displayRoles:['speaker','opponent'])){
     const p=r.participants[role]||{},team=(st.teams||[]).find(t=>Number(t.slot)===Number(p.slot)),roleLabel=role==='speaker'?'Переговорщик':'Оппонент';
     html+='<div style="margin:12px 0;padding:12px;border:1px solid #29445f;border-radius:12px"><h4 style="margin:0 0 8px">'+esc(team&&team.name?team.name:roleLabel)+' · '+roleLabel+'</h4>';
     html+='<p><strong>Итог: '+Number(p.total||0)+' из '+(Number(r.rubricVersion||0)>=3?70:76)+' баллов</strong></p>';
     const criteriaMap=(r.roundRubric==='story_seven_v2'||Number(r.rubricVersion||0)>=4)?storyCriteria:criteria;
     for(const [key,label] of Object.entries(criteriaMap)){const c=p.criteria&&p.criteria[key]||{};html+='<p><strong>'+label+': '+Number(c.points||0)+'/10</strong><br>'+esc(c.reason||'')+'</p>';for(const q of c.evidence||[])html+='<blockquote style="margin:8px 0;padding:8px 12px;border-left:3px solid #58769c;white-space:pre-wrap">'+esc(q.quote)+'<small style="display:block">Реплика №'+Number(q.messageId)+'</small></blockquote>';}
     if(String(p.summary||'').trim()!=='')html+='<p>'+esc(p.summary)+'</p>';
     if(String(p.recommendation||'').trim()!=='')html+='<p><strong>Что улучшить:</strong> '+esc(p.recommendation)+'</p>';
     html+='</div>';
    }
    if(!r.suppressWinner){const w=r.winner||{},sp=r.participants.speaker||{},op=r.participants.opponent||{},spTeam=(st.teams||[]).find(t=>Number(t.slot)===Number(sp.slot)),opTeam=(st.teams||[]).find(t=>Number(t.slot)===Number(op.slot));
    let winnerText=w.type==='mutual'?'Обоюдная победа — ничья в пользу диалога':w.type==='speaker'?'Победитель диалога: '+(spTeam&&spTeam.name?spTeam.name:'Переговорщик'):w.type==='opponent'?'Победитель диалога: '+(opTeam&&opTeam.name?opTeam.name:'Оппонент'):'';
    if(winnerText)html+='<p><strong>'+esc(winnerText)+'</strong> · разрыв '+Number(w.margin||0)+' балл(а/ов)</p>';}
   }else{
    if(r.source==='manual')html+='<p><strong>Ручная оценка ведущего</strong></p>';
    html+='<strong>'+Number(r.total||0)+' / 70 баллов</strong><p>'+esc(r.summary||'')+'</p>';
    if(row.kind==='hidden'){
     (r.tasks||[]).forEach((c,i)=>{html+='<p><strong>Задача '+(i+1)+': '+Number(c.points||0)+'</strong><br>'+esc(c.label||'')+'<br>'+esc(c.reason||'')+'</p>';for(const q of c.evidence||[])html+='<blockquote style="margin:8px 0;padding:8px 12px;border-left:3px solid #58769c;white-space:pre-wrap">'+esc(q.quote)+'<small style="display:block">Реплика №'+Number(q.messageId)+'</small></blockquote>';});
    }else if(row.kind==='hard'){
     (r.answers||[]).forEach((c,i)=>{html+='<p><strong>Вопрос '+(i+1)+': '+Number(c.points||0)+'</strong><br>'+esc(c.question||'')+'</p><p><strong>Ответ:</strong> '+esc(c.answer||'Нет ответа')+'</p><p>'+esc(c.reason||'')+'</p>';if(c.evidence)html+='<blockquote style="margin:8px 0;padding:8px 12px;border-left:3px solid #58769c;white-space:pre-wrap">'+esc(c.evidence)+'</blockquote>';});
    }
    if(r.source!=='manual'&&String(r.recommendation||'').trim()!=='')html+='<p><strong>Следующий шаг:</strong> '+esc(r.recommendation)+'</p>';
   }
  }else if(row.status==='running')html+='<p>ИИ разбирает весь разговор…</p>';
  else if(row.status==='failed')html+='<p class="err">'+esc(row.error||'Оценка не получена.')+'</p><p>Очки не начислены. Диалог сохранён.</p>';
  else html+='<p>Ожидаем ИИ-оценку разговора.</p>';
  html+='</div>';
 }
 if(Number(s.round)===3&&s.storyReveal){const rr=s.storyReveal;html+='<div class="answer-row"><h3>Досье раскрыто</h3><p><strong>'+esc(rr.title||'')+'</strong></p><ul>'+((rr.facts||[]).map(x=>'<li>'+esc(x)+'</li>').join(''))+'</ul><p><strong>Правильный вариант: '+esc(rr.modeLabel||'')+'</strong></p>'+(rr.mode==='distortion'?'<p>'+esc(rr.distortion||'')+'</p>':'')+'</div>';}
 if(s.canRequestReview&&MODE!=='scoreboard')html+='<button type="button" class="host-btn primary" id="showReviewRetry" '+(showBusy?'disabled':'')+'>'+(s.reviewStatus==='failed'?'Повторить ИИ-оценку':'Запустить ИИ-оценку')+'</button>';
 if(s.canRestartAttempt&&MODE==='host')html+='<button type="button" class="host-btn" id="showRestartAttempt" '+(showBusy?'disabled':'')+'>Повторить испытание</button>';
 if(s.stage==='round_complete'&&s.allReviewsDone)html+='<p><strong>'+(s.winners&&s.winners.length>1?'Первое место разделили: ':'Победитель раунда: ')+esc((s.winners||[]).join(', '))+'</strong></p>';
 if(s.stage==='round2_complete'&&s.allReviewsDone)html+='<p><strong>Раунд «Скрытая задача» завершён. Накопительный счёт после двух раундов показан выше.</strong></p>';
 if(s.stage==='round3_complete'&&s.allReviewsDone)html+='<p><strong>Раунд «Неудобный вопрос» завершён. Накопительный счёт после трёх раундов показан выше.</strong></p>';

 panel.innerHTML=html;
 renderShowManualReview(st,card);
 if($('showReviewRetry'))$('showReviewRetry').addEventListener('click',()=>showAction('review'));
 if($('showRestartAttempt'))$('showRestartAttempt').addEventListener('click',()=>showAction('restart_attempt'));
 const key=String(s.round)+':'+String(s.reviewAttempt)+':'+String(s.revision);
 if(s.autoRequestReview&&MODE!=='scoreboard'&&!showBusy&&showReviewAutoKey!==key){showReviewAutoKey=key;setTimeout(()=>showAction('review'),0);}
}

let showBusy=false,showPending=null,showAttempt=-1;
async function showAction(command,extra={}){
 if(showBusy||!lastState||!lastState.negotiationShow)return;
 const s=lastState.negotiationShow,area=$('showMessageText'),isTextCommand=['message','hard_answer','story_submit','story_question','story_answer'].includes(command),body=isTextCommand?(area.value||'').trim():'';
 if(isTextCommand&&!body)return;
 const qIndex=Number(Number(s.round)===3?s.storyQuestionIndex:s.hardQuestionIndex||0);
 if(isTextCommand&&(!showPending||showPending.text!==body||showPending.attempt!==s.attempt||showPending.round!==s.round||showPending.questionIndex!==qIndex))showPending={text:body,attempt:s.attempt,round:s.round,questionIndex:qIndex,id:crypto.randomUUID()};
 const requestId=isTextCommand?showPending.id:'';
 showBusy=true;if($('showError'))$('showError').textContent='';renderShowPreparation(lastState);
 try{
  const payload=Object.assign({game,team,token,nonce,user_id:uid,role:MODE==='play'?'participant':MODE,command,round:Number(s.round||0),attempt:command==='review'?s.reviewAttempt:s.attempt,question_index:qIndex,text:body,request_id:requestId},extra||{});
  if(command==='story_ready'||command==='story_vote'){const waitUntil=Date.now()+3000;while(polling&&Date.now()<waitUntil)await new Promise(resolve=>setTimeout(resolve,50));}
  const request=command==='story_ready'?postStoryReady(payload):(command==='story_vote'?postStoryVote(payload):post('show_action',payload));
  const r=command==='review'?await request:await quickActionRequest(request,12000);
  if(!r.ok)throw new Error(r.error||'Не удалось выполнить действие.');
  if(isTextCommand){if(area.value.trim()===body)area.value='';showPending=null;}
  if(r.state)render(r.state);
 }catch(e){if($('showError'))$('showError').textContent=e.message||'Нет связи. Повторите отправку: действие не продублируется.';setTimeout(()=>poll(),120);}
 finally{showBusy=false;if(lastState)renderShowPreparation(lastState);}
}

function renderShowPreparation(st){
 const g=st.game||{},s=st.negotiationShow||{};formatKey='negotiation_duel';
 offset=(Number(st.serverTime||0)*1000)-Date.now();deadline=Number(s.deadline||0)*1000;
 if($('title'))$('title').textContent='Переговори другого — '+(s.roundTitle||'Удержи цель');
 if($('gameCode'))$('gameCode').textContent=g.code||game;
 const dialogueLabel=Number(s.round)===1?('Диалог · '+Number(s.messageCount||0)+' из '+Number(s.hiddenMessageLimit||7)+' реплик'+(Number(s.dialogueLimit||0)>0?' · '+Number(s.dialogueLimit)+' сек.':'')):(Number(s.dialogueLimit||0)>0?'Диалог · лимит '+Number(s.dialogueLimit)+' секунд':'Диалог');
 const preparationLabel='Подготовка';const hardLimit=0,storyAnswerLimit=Math.max(0,Number(s.storyAnswerLimit||0));const storyAnswerLabel=storyAnswerLimit>0?('Ответ · до '+storyAnswerLimit+' секунд'):'Ответ';const storyLabel=Number(s.storyLimit||0)>0?('Рассказ · до '+Number(s.storyLimit)+' секунд'):'Рассказ';const labels={waiting:'Готовность команд',preparation:preparationLabel,dialogue:dialogueLabel,hard_answer:'Ответ',story_tell:storyLabel,story_questions:'Уточняющие вопросы',story_answer:storyAnswerLabel,story_vote:'Тайное голосование',review:Number(s.round)===3?'Результат и начисление':'ИИ-арбитраж и разбор',round_complete:'Раунд «Удержи цель» завершён',round2_complete:'Раунд «Скрытая задача» завершён',round3_complete:'Раунд «Неудобный вопрос» завершён',game_complete:'Игра завершена'};
 if($('phase'))$('phase').textContent=s.paused?'Пауза':(labels[s.stage]||'Подключение');
 const showTimer=$('timer');if(showTimer){showTimer.hidden=Number(s.deadline||0)<=0;if(showTimer.hidden)showTimer.textContent='';}hide('question',false);
 showAnswerBox(false,'','',true,{preserveStt:true});
 ['genericHostActions','jeopardyHostArea','jeopardyArea','options','hostAnswers','genericAnswersCard'].forEach(id=>hide(id,true));
 document.querySelectorAll('.format-note').forEach(el=>el.hidden=true);
 if($('teamName'))$('teamName').textContent=s.teamName||'Переговори другого';
 const teamsEl=$('teams');if(teamsEl){teamsEl.innerHTML=(s.roles||[]).map((t,i)=>{const score=(s.scores||[])[i];let scoreText='Оценка ожидается';if(score&&score.score!==null){scoreText=Number(score.score)+' очков';if(Number(s.round)>=1)scoreText+=' · раунд 1: '+(score.round1===null?'—':Number(score.round1))+' · раунд 2: '+(score.round2===null?'—':Number(score.round2));if(Number(s.round)>=2)scoreText+=' · раунд 3: '+(score.round3===null?'—':Number(score.round3));if(Number(s.round)>=3)scoreText+=' · раунд 4: '+Number(score.round4||0);}return '<div class="team"><strong>'+esc(t.name)+'</strong><div class="muted">'+esc(t.role)+'</div><div>'+scoreText+'</div></div>';}).join('');const title=teamsEl.parentNode.querySelector('h2');if(title)title.textContent=Number(s.round)>=1?'Накопительный счёт и роли':'Счёт и роли команд';}
 const q=$('question');if(!q)return;q.textContent=speechLabelText(s.situation||'');
 let card=$('showRoleCard');if(!card){
  card=document.createElement('section');card.id='showRoleCard';card.className='card';q.parentNode.insertBefore(card,q.nextSibling);
  card.innerHTML='<h2 id="showRoleTitle"></h2><p id="showBrief" style="white-space:pre-wrap"></p><div id="showActionHint" role="status" style="margin:12px 0;padding:12px 14px;border:1px solid #355273;border-radius:10px;background:rgba(53,82,115,.16);font-weight:700"></div><p class="muted" id="showProgress"></p><div id="showHostModePanel" class="host-block" hidden><strong id="showHostModeLabel">Ведущий</strong><div class="muted" id="showHostModeNote" style="margin-top:6px"></div><div class="host-actions"><button type="button" class="host-btn" id="showModeSwitch">Передать управление ИИ</button></div></div><div class="host-actions"><button type="button" class="host-btn primary" id="showReady">Команда готова</button><button type="button" class="host-btn primary" id="showStoryReady">Готов рассказать</button><button type="button" class="host-btn primary" id="showContinue">Перейти к следующему испытанию</button><button type="button" class="host-btn primary" id="showNextRound">Перейти к следующему раунду</button><button type="button" class="host-btn primary" id="showFinishDialogue">Завершить диалог</button><button type="button" class="host-btn" id="showPause">Пауза</button></div><div id="showLog" aria-label="Диалог команд" style="max-height:440px;overflow:auto;margin:16px 0"></div><div id="showCompose"><label for="showMessageText">Реплика вашей команды</label><textarea id="showMessageText" maxlength="2000" rows="4" style="box-sizing:border-box;width:100%;margin:8px 0;padding:12px;background:var(--panel,#0c1829);color:inherit;border:1px solid #355273;border-radius:10px"></textarea><button type="button" class="host-btn primary" id="showSend">Отправить реплику</button></div><div id="showVote" class="host-actions" hidden><button type="button" class="host-btn primary" id="showVoteTruth">Соответствует досье</button><button type="button" class="host-btn" id="showVoteDistortion">Есть существенное искажение</button></div><p id="showError" role="alert" class="err"></p><p class="muted" id="showRuleNote"></p>';
  $('showReady').addEventListener('click',()=>showAction('ready'));
  $('showStoryReady').addEventListener('click',()=>showAction('story_ready'));
  $('showContinue').addEventListener('click',()=>showAction('advance'));
  $('showNextRound').addEventListener('click',()=>showAction('next_round'));
  $('showFinishDialogue').addEventListener('click',()=>{const x=lastState&&lastState.negotiationShow||{};if(confirm(String(x.finishDialogueConfirm||'Завершить диалог и перейти к следующему этапу?')))showAction('finish_dialogue');});
  $('showPause').addEventListener('click',()=>showAction(lastState.negotiationShow.paused?'resume':'pause'));
  $('showModeSwitch').addEventListener('click',()=>{const ai=String((lastState&&lastState.game&&lastState.game.hostMode)||'')==='ai';const msg=ai?'Переключить на ведущего-человека без сброса текущего состояния?':'Передать управление ИИ с текущего состояния?';if(confirm(msg))showAction(ai?'switch_human':'switch_ai');});
  $('showSend').addEventListener('click',()=>{const x=lastState&&lastState.negotiationShow||{};let cmd='message';if(Number(x.round)===2)cmd='hard_answer';else if(Number(x.round)===3){cmd=x.stage==='story_tell'?'story_submit':x.stage==='story_questions'?'story_question':x.stage==='story_answer'?'story_answer':'message';}showAction(cmd);});
  $('showVoteTruth').addEventListener('click',()=>showAction('story_vote',{vote:'truth'}));
  $('showVoteDistortion').addEventListener('click',()=>showAction('story_vote',{vote:'distortion'}));
 }
 if(s.stage==='game_complete'){renderShowFinalSurface(st);return;}
 $('showBrief').hidden=false;$('showProgress').hidden=false;$('showLog').hidden=false;$('showRuleNote').hidden=false;
 const showStep=String(s.round)+':'+String(s.attempt)+':'+String(s.stage)+':'+String(s.hardQuestionIndex||0)+':'+String(s.storyQuestionIndex||0)+':'+String(s.storyQuestionsAskedByMe||0);if(showAttempt!==showStep){$('showMessageText').value='';showPending=null;showAttempt=showStep;}
 const roundNo=Math.min(4,Math.max(1,Number(s.round||0)+1));
 const roundNames=['Удержи цель','Скрытая задача','Неудобный вопрос','Проверь историю'];
 const currentRoundName=roundNames[roundNo-1]||String(s.roundTitle||'Раунд');
 $('showRoleTitle').textContent=(s.stage==='waiting'?'Переговори другого · Раунд 1 из 4 — Удержи цель':('Раунд '+roundNo+' из 4 — '+currentRoundName))+(s.roleTitle?' · '+s.roleTitle:'');
 $('showBrief').textContent=s.brief||'Закрытые инструкции доступны только соответствующим командам.';
 let progress='';
 if(s.stage==='waiting')progress='Игра состоит из 4 раундов. Во всех четырёх действует одна шкала: 7 критериев по 0–10. Максимум каждого раунда — 70 баллов, всей игры — 280. Готовы '+Number(s.readyCount||0)+' из '+Number(s.teamCount||2)+' команд.';
 else if(s.stage==='review')progress='Раунд '+roundNo+' из 4. '+(s.currentReviewDone?'Оценка завершена.':'Идёт оценка.');
 else if(['round_complete','round2_complete','round3_complete'].includes(s.stage))progress='Раунд '+roundNo+' из 4 завершён.';
 else if(Number(s.round)===3){if(s.stage==='story_questions')progress='Раунд 4 из 4. Собрано вопросов: '+Number((s.storyQuestions||[]).length)+' из '+Number(s.storyQuestionsTotal||2)+'. Ваша команда задала: '+Number(s.storyQuestionsAskedByMe||0)+' из 2.';else if(s.stage==='story_answer')progress='Раунд 4 из 4. Рассказчик отвечает на вопрос '+(Number(s.storyQuestionIndex||0)+1)+' из '+Number(s.storyQuestionsTotal||2)+'.';else if(s.stage==='story_vote')progress='Раунд 4 из 4. Голосов получено: '+Number(s.storyVoteCount||0)+' из '+Math.max(1,Number(s.teamCount||2)-1)+'.';else progress='Раунд 4 из 4. История команды '+(Number(s.attempt||0)+1)+' из '+Number(s.teamCount||2)+'.';}
 else if(Number(s.round)===2)progress='Раунд 3 из 4. Активная команда отвечает на вопрос '+(Number(s.hardQuestionIndex||0)+1)+' из 3.';
 else if(Number(s.round)===1)progress='Раунд 2 из 4. Активная команда выполняет три скрытые задачи; остальные поддерживают разговор. Реплик: '+Number(s.messageCount||0)+' из '+Number(s.hiddenMessageLimit||7)+'.';
 else progress='Раунд 1 из 4. Команды по очереди меняются ролями переговорщика и оппонента; ИИ-арбитр оценивает обе стороны.';
 $('showProgress').textContent=s.error||progress;
 const activeRole=(s.roles||[]).find(x=>['Отвечает','Рассказчик','Исполнитель'].includes(String(x.role||'')));
 const activeName=activeRole?String(activeRole.name||'активная команда'):'активная команда';
 let actionHint='Следите за состоянием игры.';
 if(MODE==='host'){const aiHost=String(g.hostMode||'')==='ai';if(s.canRestartAttempt&&Number(s.round)===3){actionHint='Рассказ истёк без текста в старой или ограниченной по времени попытке. Нажмите «Повторить испытание»: подготовка начнётся заново.';}else if(s.stage==='dialogue'){const hiddenLimitNote=Number(s.round)===1?' Лимит «Скрытой задачи»: '+Number(s.messageCount||0)+' из '+Number(s.hiddenMessageLimit||7)+' реплик; после достижения лимита оценка начнётся автоматически.':'';const turnNote=Number(s.turnSlot||0)>0?((Number(s.messageCount||0)===0?' Первую реплику делает ':' Сейчас ход: ')+String(s.turnTeamName||('Команда '+s.turnSlot))+(s.turnRoleTitle?' — '+String(s.turnRoleTitle):'')+'.'):'';let aiNote='ИИ-ведущий отслеживает естественное завершение диалога и тишину.';if(aiHost&&Number(s.aiDialogueIdlePromptedAt||0)>0)aiNote='ИИ-ведущий спросил, есть ли что добавить. Если новых реплик не будет, диалог автоматически завершится.';else if(aiHost&&Number(s.aiDialogueNaturalAt||0)>0)aiNote='ИИ-ведущий видит естественное завершение разговора и даёт короткое окно для последней реплики.';actionHint=(aiHost?aiNote:'Когда переговоры завершены, нажмите «Завершить диалог».')+turnNote+hiddenLimitNote;}else if(s.stage==='review'){actionHint=s.canRestartAttempt?(Number(s.round)===2?'Все три ответа истекли без ответа, а оценка завершилась ошибкой. Нажмите «Повторить испытание».':'Диалог этого испытания пуст, а оценка завершилась ошибкой. Нажмите «Повторить испытание».'):(!s.currentReviewDone?(s.reviewStatus==='failed'?'Оценка ИИ не получена. Повторите оценку или выставьте ручную.':'Ожидаем оценку.'):(aiHost?'Оценка готова. ИИ-ведущий автоматически продолжит игру через несколько секунд.':'Оценка готова. Нажмите одну кнопку для следующего испытания или раунда; команды повторно подтверждать готовность не будут.'));}else if(['round_complete','round2_complete','round3_complete'].includes(s.stage))actionHint=aiHost?'ИИ-ведущий автоматически начинает следующий раунд.':'Нажмите «Следующий раунд». Повторная готовность команд не требуется.';else actionHint=s.paused?'Игра на паузе. Возобновите таймер, когда будете готовы.':'Наблюдайте за ходом раунда; управление этапами выполняют команды и движок.';}
 else if(s.stage==='waiting')actionHint=s.canReady?'Сейчас: нажмите «Команда готова».':'Готовность вашей команды подтверждена. Ждём остальные команды.';
 else if(s.paused)actionHint='Сейчас пауза. Отправка реплик и ответов временно закрыта.';
 else if(s.stage==='preparation')actionHint=(Number(s.round)===3&&String(s.roleTitle||'')==='Рассказчик')?'Когда будете готовы начать, нажмите «Готов рассказать».':(Number(s.round)===3?'Рассказчик готовится. Дождитесь начала истории.':'Сейчас идёт подготовка. Дождитесь следующего этапа.');
 else if(s.stage==='dialogue'){const first=Number(s.messageCount||0)===0;const turnName=String(s.turnTeamName||'другая команда');if(Number(s.round)===1){const quota=' Ваша команда: '+Number(s.teamMessageCount||0)+' из '+Number(s.teamMessageMax||0)+' реплик. Всего: '+Number(s.messageCount||0)+' из '+Number(s.hiddenMessageLimit||7)+'.';if(s.canMessage)actionHint=(first?'Ваш ход: сделайте первую реплику. ':'Ваш ход: ответьте другой стороне. ')+(String(s.roleTitle||'')==='Исполнитель скрытых задач'?'Ведите разговор и незаметно выполните свои три скрытые задачи.':'Отправьте реплику вашей команды.')+quota;else if(s.strictTurnOrder)actionHint=(first?'Первую реплику делает ':'Ожидайте реплику ')+turnName+(s.turnRoleTitle?' — '+String(s.turnRoleTitle):'')+'.'+quota;else actionHint='Лимит реплик вашей команды исчерпан или общий лимит испытания достигнут.'+quota;}else actionHint=s.canMessage?(first?'Ваш ход: сделайте первую реплику.':'Ваш ход: ответьте другой стороне.'):(s.strictTurnOrder?((first?'Первую реплику делает ':'Ожидайте реплику ')+turnName+(s.turnRoleTitle?' — '+String(s.turnRoleTitle):'')+'.'):'Сейчас говорит другая сторона; дождитесь своего хода.');if(String(g.hostMode||'')==='ai'&&Number(s.aiDialogueIdlePromptedAt||0)>0)actionHint+=' ИИ-ведущий уточнил, есть ли что добавить; при дальнейшей тишине диалог завершится автоматически.';}
 else if(s.stage==='hard_answer')actionHint=s.canMessage?'Сейчас отвечает ваша команда. Отправьте ответ, когда сформулируете его.':'Сейчас отвечает '+activeName+'. Дождитесь своего блока вопросов.';
 else if(s.stage==='story_tell')actionHint=s.canMessage?(Number(s.storyLimit||0)>0?('Сейчас ваш ход: зафиксируйте рассказ до окончания '+Number(s.storyLimit)+' секунд.'):'Сейчас ваш ход: расскажите историю и нажмите «Зафиксировать рассказ», когда закончите.'):'Сейчас историю рассказывает '+activeName+'. Слушайте — затем вы зададите два вопроса.';
 else if(s.stage==='story_questions')actionHint=s.canMessage?'Сейчас ваш ход: задайте уточняющий вопрос '+(Number(s.storyQuestionsAskedByMe||0)+1)+' из 2.':(Number(s.storyQuestionsAskedByMe||0)>=2?'Ваши вопросы приняты. Ждём перехода к ответам рассказчика.':'Сейчас вопросы задаёт проверяющая команда.');
 else if(s.stage==='story_answer')actionHint=s.canMessage?(storyAnswerLimit>0?'Сейчас ваш ход: ответьте на вопрос до окончания '+storyAnswerLimit+' секунд.':'Сейчас ваш ход: ответьте на уточняющий вопрос.'):'Сейчас рассказчик отвечает на вопрос '+(Number(s.storyQuestionIndex||0)+1)+' из '+Number(s.storyQuestionsTotal||2)+'.';
 else if(s.stage==='story_vote')actionHint=s.canVote?'Сейчас ваш ход: выберите один вариант и зафиксируйте тайный голос.':(s.hasVoted?'Ваш голос зафиксирован. Ждём завершения этапа.':'Дождитесь этапа голосования вашей команды.');
 else if(s.stage==='review')actionHint=!s.currentReviewDone?(s.reviewStatus==='failed'?'Оценка ИИ не получена. Ведущий может повторить оценку или выставить ручную.':'Ожидаем оценку.'):'Оценка готова. Ознакомьтесь с результатом; следующий этап запустит ведущий без дополнительных подтверждений команд.';
 else if(['round_complete','round2_complete','round3_complete'].includes(s.stage))actionHint='Раунд завершён. Следующий раунд запустит ведущий без повторной готовности команд.';
 const hintEl=$('showActionHint');if(hintEl){hintEl.textContent=actionHint;hintEl.hidden=!!s.error;}
 $('showReady').hidden=MODE!=='play'||s.stage!=='waiting'||!s.initialReady||!!s.error;$('showReady').disabled=!s.canReady||showBusy;$('showReady').textContent=s.canReady?'Команда готова':'Готовность подтверждена';$('showStoryReady').hidden=MODE!=='play'||!s.canStoryReady||!!s.error;$('showStoryReady').disabled=!s.canStoryReady||showBusy;
 const aiHostAdvance=MODE==='host'&&String(g.hostMode||'')==='ai';const legacyComplete=['round_complete','round2_complete','round3_complete'].includes(s.stage);
 $('showContinue').hidden=MODE!=='host'||aiHostAdvance||!s.canAdvance||!(s.stage==='review'||legacyComplete);$('showContinue').disabled=!s.canAdvance||showBusy;$('showContinue').textContent=(Number(s.round)===3&&Number(s.attempt)===Number(s.teamCount||2)-1&&s.stage==='review')?'Завершить игру':((Number(s.attempt)===Number(s.teamCount||2)-1||legacyComplete)?'Следующий раунд':'Следующее испытание');
 $('showNextRound').hidden=true;$('showNextRound').disabled=true;
 $('showFinishDialogue').hidden=!s.canFinishDialogue;$('showFinishDialogue').disabled=showBusy||!s.canFinishDialogue;$('showFinishDialogue').textContent='Завершить диалог';
 const showTimedPhase=(s.stage==='story_answer'&&Number(s.deadline||0)>0)||(s.stage==='story_tell'&&Number(s.deadline||0)>0);$('showPause').hidden=MODE!=='host'||!showTimedPhase;$('showPause').disabled=showBusy;$('showPause').textContent=s.paused?'Продолжить':'Пауза';
 const showHostModePanel=$('showHostModePanel'),showModeSwitch=$('showModeSwitch'),showHostModeLabel=$('showHostModeLabel'),showHostModeNote=$('showHostModeNote');
 if(showHostModePanel){const ai=String(g.hostMode||'')==='ai',sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway';showHostModePanel.hidden=MODE!=='host'||s.stage==='game_complete';if(showHostModeLabel)showHostModeLabel.textContent=ai?'ИИ-ведущий':'Ведущий (с микрофоном/без микрофона)';if(showHostModeNote)showHostModeNote.textContent=ai?(sergey?'Голос: Cartesia · Сергей. ИИ озвучивает этапы и продолжает игру с текущего состояния.':'ИИ ведёт игру; используется выбранный голосовой провайдер.'):'Человек ведёт голосом через микрофон и вручную завершает свободный диалог.';if(showModeSwitch){showModeSwitch.disabled=showBusy;showModeSwitch.textContent=ai?'Переключить на ведущего-человека':'Передать управление ИИ';}}
 $('showRuleNote').textContent=s.stage==='waiting'?'После готовности обеих команд игра начнётся автоматически. Победит команда с наибольшей суммой очков за четыре раунда.':Number(s.round)===3?('Раунд 4 из 4 — «Проверь историю»: подготовка, рассказ, перекрёстные вопросы и тайное голосование остаются механикой испытания. После него ИИ оценивает рассказчика по отдельной шкале Раунда 4: ясность истории, выполнение условия, непротиворечивость, прямота и обоснованность ответов, устойчивость версии и качество общения. Голосование отдельных очков не даёт. Максимум раунда — 70 баллов.') :Number(s.round)===2?'Раунд 3 из 4 — «Неудобный вопрос»: три вопроса без отсчёта времени. После блока ИИ применяет единую шкалу 7×0–10. Максимум раунда — 70 баллов.':Number(s.round)===1?('Раунд 2 из 4 — «Скрытая задача»: диалог открыт сразу, максимум '+Number(s.hiddenMessageLimit||7)+' реплик на испытание. Скрытые задачи задают поведение, но не дают отдельных очков: после диалога действует единая шкала 7×0–10. Максимум раунда — 70 баллов.'):'Раунд 1 из 4 — «Удержи цель»: один ИИ-арбитр отдельно оценивает переговорщика и оппонента по 7 критериям 0–10. Раундовый результат команды — среднее её оценок в двух ролях, поэтому максимум — 70 баллов. Разрыв до 3 баллов включительно означает обоюдную победу.';
 const voteBox=$('showVote');if(voteBox){voteBox.hidden=MODE!=='play'||!s.canVote;$('showVoteTruth').disabled=showBusy;$('showVoteDistortion').disabled=showBusy;}
 $('showCompose').hidden=MODE!=='play'||!s.canMessage;$('showSend').disabled=showBusy||!s.canMessage;const composeLabel=$('showCompose').querySelector('label');
 if(composeLabel){if(Number(s.round)===3)composeLabel.textContent=s.stage==='story_tell'?'Рассказ вашей команды':s.stage==='story_questions'?('Уточняющий вопрос '+(Number(s.storyQuestionsAskedByMe||0)+1)+' из 2'):s.stage==='story_answer'?'Ответ рассказчика':'Текст';else composeLabel.textContent=Number(s.round)===2?'Ответ вашей команды':'Реплика вашей команды';}
 if(Number(s.round)===3)$('showSend').textContent=s.stage==='story_tell'?'Зафиксировать рассказ':s.stage==='story_questions'?'Задать вопрос':s.stage==='story_answer'?'Ответить':'Отправить';else $('showSend').textContent=Number(s.round)===2?'Отправить ответ':'Отправить реплику';
 const showVoiceLabel=Number(s.round)===3?(s.stage==='story_tell'?'🎙 Продиктовать рассказ':s.stage==='story_questions'?'🎙 Продиктовать вопрос':s.stage==='story_answer'?'🎙 Продиктовать ответ':'🎙 Продиктовать'):(Number(s.round)===2?'🎙 Продиктовать ответ':'🎙 Продиктовать реплику');
 const showText=$('showMessageText');if(showText&&s.canMessage)showText.placeholder='Нажмите «'+showVoiceLabel.replace('🎙 ','')+'» или введите текст вручную';
 syncShowSttControls(MODE==='play'&&!!s.canMessage,showBusy||!s.canMessage,showVoiceLabel);
 const log=$('showLog'),nearBottom=log.scrollHeight-log.scrollTop-log.clientHeight<60;
 const msgs=s.messages||[],key=String(s.round)+':'+String(s.attempt)+':'+String(s.hardQuestionIndex||0)+':'+String(s.storyQuestionIndex||0)+':'+s.stage+':'+msgs.length+':'+(s.hardAnswers||[]).length+':'+(s.storyText||'').length+':'+(s.storyQuestions||[]).length;
 if(log.dataset.key!==key){
  log.dataset.key=key;
  if(Number(s.round)===3){let html='';if(s.storyText||s.storyTimedOut)html+='<div class="answer-row"><strong>История рассказчика</strong><div style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(s.storyTimedOut&&!s.storyText?'Время истекло без рассказа.':s.storyText)+'</div></div>';for(const x of s.storyQuestions||[])html+='<div class="answer-row"><strong>'+esc(x.fromName||'Соперник')+' · вопрос '+(Number(x.index)+1)+'</strong><div>'+esc(x.text||'')+'</div>'+(x.answer||x.timedOut||x.endedEarly?'<div class="muted" style="margin-top:8px">Ответ: '+esc(x.timedOut&&!x.answer?'Время истекло без ответа.':(x.endedEarly&&!x.answer?'Рассказчик завершил диалог; ответа больше нет.':x.answer))+'</div>':'')+'</div>';log.innerHTML=html||'<p class="muted">Финальный раунд готовится.</p>';}
  else if(Number(s.round)===2){const aa=s.hardAnswers||[];log.innerHTML=aa.length?aa.map(a=>'<div class="answer-row"><strong>Вопрос '+(Number(a.questionIndex)+1)+'</strong><div class="muted">'+esc(a.question||'')+'</div><div style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(a.timedOut?'Время истекло без ответа.':(a.endedEarly&&!a.text?'Участник завершил диалог; ответа больше нет.':(a.text||'')))+'</div></div>').join(''):'<p class="muted">Ответов пока нет.</p>';}
  else{const visible=['round_complete','round2_complete'].includes(s.stage)?msgs.filter(m=>Number(m.round||0)===Number(s.round)):msgs.filter(m=>Number(m.round||0)===Number(s.round)&&m.attempt===s.attempt);log.innerHTML=visible.length?visible.map(m=>'<div class="answer-row"><strong>'+esc(m.teamName)+(s.stage==='round_complete'?' · испытание '+(m.attempt+1):'')+' говорит:</strong><div style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(m.text)+'</div></div>').join(''):'<p class="muted">Реплик пока нет.</p>';}
  if(nearBottom)log.scrollTop=log.scrollHeight;
 }
 renderShowReviews(st);
 let status='Прочитайте карточку своей роли.';
 if(s.stage==='round_complete')status='Первый раунд завершён. Следующий раунд запускает ведущий одной кнопкой.';
 else if(s.stage==='round2_complete')status='Второй раунд завершён. Следующий раунд запускает ведущий одной кнопкой.';
 else if(s.stage==='round3_complete')status='Третий раунд завершён. Финал запускает ведущий одной кнопкой.';
 else if(s.stage==='game_complete')status='Игра завершена. Итоговый победитель определён.';
 else if(s.paused)status='Таймер остановлен. Приём ответов закрыт.';
 else if(Number(s.round)===3){status=s.stage==='review'?'Результат истории раскрыт. Следующий этап запустит ведущий.':s.stage==='story_tell'?(Number(s.storyLimit||0)>0?('Рассказчик фиксирует историю; лимит '+Number(s.storyLimit)+' секунд.'):'Рассказчик ведёт историю.'):s.stage==='story_questions'?('Проверяющая команда задаёт ровно два вопроса, по одному с ответом рассказчика после каждого.'+(s.canFinishDialogue?' Если вопросов больше нет, нажмите «Завершить диалог».':'')):s.stage==='story_answer'?((storyAnswerLimit>0?'Рассказчик отвечает. На каждый ответ — до '+storyAnswerLimit+' секунд.':'Рассказчик отвечает.')+(s.canFinishDialogue?' Если ответов больше нет, нажмите «Завершить диалог».':'')):s.stage==='story_vote'?(s.hasVoted?'Ваш голос зафиксирован. Ждём завершения этапа.':'Выберите один из двух вариантов. Голос соперника скрыт.'):'Ознакомьтесь с закрытым досье. Когда будете готовы, нажмите «Готов рассказать».';}
 else if(s.stage==='review')status=s.currentReviewDone?'Оценка готова. Следующий этап запустит ведущий без повторной готовности команд.':'Дождитесь ИИ-оценки и прочитайте разбор.';
 else if(s.stage==='hard_answer')status='У активной команды '+hardLimit+' секунд на ответ.'+(s.canFinishDialogue?' Если продолжать нечего, нажмите «Завершить диалог».':'');
 else if(s.stage==='dialogue')status=(Number(s.round)===1?('Свободный диалог. Реплик '+Number(s.messageCount||0)+' из '+Number(s.hiddenMessageLimit||7)+'. После лимита оценка начнётся автоматически.'):'Свободный диалог. Переговорщик и оппонент ведут разговор; ИИ-арбитр анализирует обе стороны.')+(s.canFinishDialogue?' Если реплик больше нет, нажмите «Завершить диалог».':'');
 setStatus(s.error||status,s.error?'err':(s.stage==='game_complete'?'ok':''));
}

function render(st){if(st.negotiationShow&&st.negotiationShow.error&&lastState&&lastState.negotiationShow){setStatus(st.negotiationShow.error,'err');return;}if(st.negotiationShow&&lastState&&lastState.negotiationShow&&Number(st.negotiationShow.revision||0)<Number(lastState.negotiationShow.revision||0))return;const incomingMode=String(st&&st.game&&st.game.hostMode||'');if(lastHostMode==='ai'&&incomingMode==='human')stopAiClientAutomation('manual_mode_confirmed');if(incomingMode==='human')manualTakeoverPending=false;if(incomingMode)lastHostMode=incomingMode;lastState=st;if(st.negotiationShow){renderShowPreparation(st);window.dispatchEvent(new CustomEvent('ckmqp:state',{detail:st}));return;}const g=st.game||{},qid=Number(st.question&&st.question.id||0);formatKey=String(g.formatKey||'classic_quiz');renderStandaloneChgkHostGuide();if(qid!==lastQuestionId){const activeStt=sttConnecting||sttRecording||sttStopping||!!sttSocket||!!sttStream;const sessionMatchesIncoming=activeStt&&sttSessionQuestionId>0&&qid>0&&Number(sttSessionQuestionId)===qid;if(activeStt&&!sessionMatchesIncoming)abortSttDictation('');const t=$('answerText');if(t&&!sessionMatchesIncoming)t.value='';lastAnswerInputMode='text';lastQuestionId=qid;selected=false;}if($('gameCode'))$('gameCode').textContent=g.code||game;if($('title'))$('title').textContent=g.title||'Игра';if($('phase'))$('phase').textContent=phaseLabel(g,st)+((st.negotiationExpressCanary&&st.negotiationExpressCanary.enabled)?' · CANARY':'');renderTeams(st);renderChgkFinal(st);offset=(Number(st.serverTime||0)*1000)-Date.now();deadline=Number(g.questionDeadlineUnix||0)*1000;renderQuestion(st);if(formatKey==='jeopardy')renderJeopardy(st);else renderClassicOrChgk(st);window.dispatchEvent(new CustomEvent('ckmqp:state',{detail:st}));}
async function ensureJoin(){if(MODE!=='play')return;const r=await post('join',{game,team,token,nonce,user_id:uid,name:'Участник'});if(r.ok){uid=Number(r.userId||0);localStorage.setItem(userKey,String(uid));}else setStatus(r.error||'Не удалось подключиться.','err');}
async function poll(){if(polling)return;if(showBusy){setTimeout(poll,350);return;}polling=true;try{const d={game,role:MODE==='play'?'participant':MODE,team,token,nonce,user_id:uid};const r=await post('state',d);if(r.ok)render(r.state);else setStatus(r.error||'Ошибка синхронизации.','err');}catch(e){setStatus('Нет связи с сервером. Повторяем попытку…','err');}finally{polling=false;setTimeout(poll,1800);}}
async function answer(v){if(selected&&formatKey!=='jeopardy')return;selected=true;setStatus('Отправляем ответ…');const r=await post('answer',{game,team,token,nonce,user_id:uid,answer:v,input_mode:lastAnswerInputMode||'text'});if(!r.ok)selected=false;setStatus(r.ok?'Ответ принят.':(r.error||'Ошибка ответа'),r.ok?'ok':'err');setTimeout(()=>poll(),120);}
async function chgkAction(command){setStatus('Выполняется действие команды…');const r=await post('chgk_action',{game,team,token,nonce,user_id:uid,command});setStatus(r.ok?'Готово.':(r.error||'Ошибка действия'),r.ok?'ok':'err');if(r.ok&&r.state)render(r.state);else setTimeout(()=>poll(),120);}
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

function commandForHostNext(){return (lastState&&lastState.game&&lastState.game.phase==='waiting')?'start':'next';}
let hostVoiceCommandRec=null,hostVoiceCommandOn=false,hostVoiceCommandBtn=null,hostVoiceCommandBadge=null,hostVoiceCommandStream=null,hostVoiceCommandSocket=null,hostVoiceCommandRecorder=null,hostVoiceCommandSendChain=Promise.resolve(),hostVoiceCommandStopTimer=null,hostVoiceCommandLastText='',hostVoiceCommandFinalized=false;
function normalizeVoiceCommandText(text){return String(text||'').toLowerCase().replace(/ё/g,'е').replace(/[^а-яa-z0-9 ]+/g,' ').replace(/\s+/g,' ').trim();}
function readSpeechText(m){
 if(!m||typeof m!=='object')return '';
 const direct=[m.text,m.transcript,m.phrase,m.message,m.resultText,m.finalText,m.partialText];
 for(const v of direct){const t=String(v||'').trim();if(t)return t;}
 const result=m.result||m.data||{};
 if(result&&typeof result==='object'){
  const nested=[result.text,result.transcript,result.phrase,result.message,result.resultText];
  for(const v of nested){const t=String(v||'').trim();if(t)return t;}
 }
 try{
  const alts=m.channel&&m.channel.alternatives || result.channel&&result.channel.alternatives || result.alternatives || m.alternatives;
  if(Array.isArray(alts)&&alts.length){const t=String((alts[0]&&alts[0].transcript)||(alts[0]&&alts[0].text)||'').trim();if(t)return t;}
 }catch(e){}
 return '';
}
function hostVoiceCommandLabel(cmd){
 if(cmd==='start'||cmd==='next')return commandForHostNext()==='start'?'Запустить игру':'Следующий вопрос';
 if(cmd==='close')return 'Завершить текущий этап';
 if(cmd==='reveal')return 'Раскрыть правильный ответ';
 if(cmd==='switch_ai')return 'Передать управление ИИ';
 if(cmd==='switch_human')return 'Переключить на ведущего-человека';
 if(cmd==='finish')return 'Завершить игру';
 return 'Команда';
}
function commandFromVoicePhrase(raw){
 const t=normalizeVoiceCommandText(raw);if(!t)return '';
 const has=(...words)=>words.some(w=>t.includes(w));
 const hasAny=(re)=>re.test(t);
 if(has('помощь','что можно сказать','какие команды')) return 'help';
 if(has('передать управление ии','передай управление ии','включи ии','включить ии','пусть ведет ии','пусть ведет искусственный интеллект')) return 'switch_ai';
 if(has('перейти к ручному управлению','ручное управление','вернуть ведущего','ведущий человек','голосовой ведущий')) return 'switch_human';
 if(has('завершить игру окончательно','закончить игру окончательно','остановить игру окончательно','прервать игру','завершить матч','закончить матч')) return 'finish';
 if(has('следующий вопрос','дальше вопрос','следующий раунд','дальше раунд','перейти дальше','идем дальше','идём дальше')) return 'next';
 if(has('задать вопрос','задай вопрос','открыть вопрос','открой вопрос','начать вопрос','начни вопрос','запусти вопрос','запустить вопрос','запустить игру','запусти игру','начать игру','начни игру','старт игры','старт')) return commandForHostNext();
 if(has('завершить обсуждение','закончи обсуждение','закончить обсуждение','остановить обсуждение','останови обсуждение','закрыть обсуждение','закрой обсуждение')) return 'close';
 if(has('закрыть ответы','закрой ответы','закрыть прием ответов','закрыть приём ответов','закрой прием ответов','закрой приём ответов','закрыть окончательный ответ','закрой окончательный ответ')) return 'close';
 if(has('раскрыть правильный ответ','раскрой правильный ответ','показать правильный ответ','покажи правильный ответ','открыть правильный ответ','открой правильный ответ','раскрыть ответ','показать ответ','покажи ответ','разбор ответа')) return 'reveal';
 if(hasAny(/\b(задай|задать|открой|открыть|начни|начать|запусти|запустить)\b/) && hasAny(/\b(вопрос|игру|раунд|матч)\b/)) return commandForHostNext();
 if(hasAny(/\b(следующий|следующии|дальше|продолжить|продолжай)\b/) && hasAny(/\b(вопрос|раунд|этап)?\b/)) return 'next';
 if(hasAny(/\b(заверши|завершить|закончить|остановить|закрыть|закрой)\b/) && hasAny(/\b(обсуждение|ответы|прием|приём|окончательный)\b/)) return 'close';
 if(hasAny(/\b(открой|открыть|покажи|показать|раскрой|раскрыть)\b/) && hasAny(/\b(правильный|ответ|разбор)\b/)) return 'reveal';
 return '';
}
function executeHostVoicePhrase(raw){
 const phrase=String(raw||'').trim();const cmd=commandFromVoicePhrase(phrase);if(!cmd)return false;
 if(cmd==='help'){
  if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Команды: «Задать вопрос», «Следующий вопрос», «Закрыть ответы», «Раскрыть правильный ответ», «Передать управление ИИ».';
  return true;
 }
 if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Распознано: «'+phrase+'» → '+hostVoiceCommandLabel(cmd);
 if(cmd==='finish'){if(!confirm('Голосовая команда просит завершить игру. Завершить?'))return true;}
 hostAction(cmd);
 return true;
}
function finishHostVoiceCommand(phrase){
 if(hostVoiceCommandFinalized)return;
 hostVoiceCommandFinalized=true;
 const text=String(phrase||hostVoiceCommandLastText||'').trim();
 if(!text){cleanupHostVoiceCommand('Речь не распознана. Скажите коротко: «Задать вопрос» или «Закрыть ответы».');return;}
 cleanupHostVoiceCommand('Распознано: '+text);
 if(!executeHostVoicePhrase(text)&&hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Распознано: «'+text+'», но это не команда. Скажите: «Задать вопрос», «Закрыть ответы» или «Следующий вопрос».';
}
function cleanupHostVoiceCommand(message){
 if(hostVoiceCommandStopTimer){clearTimeout(hostVoiceCommandStopTimer);hostVoiceCommandStopTimer=null;}
 try{if(hostVoiceCommandRecorder&&hostVoiceCommandRecorder.state!=='inactive')hostVoiceCommandRecorder.stop();}catch(e){}
 try{if(hostVoiceCommandSocket&&(hostVoiceCommandSocket.readyState===WebSocket.OPEN||hostVoiceCommandSocket.readyState===WebSocket.CONNECTING))hostVoiceCommandSocket.close(1000,'voice_command_done');}catch(e){}
 try{if(hostVoiceCommandStream)hostVoiceCommandStream.getTracks().forEach(t=>t.stop());}catch(e){}
 hostVoiceCommandSocket=null;hostVoiceCommandStream=null;hostVoiceCommandRecorder=null;hostVoiceCommandSendChain=Promise.resolve();hostVoiceCommandOn=false;
 if(hostVoiceCommandBtn){hostVoiceCommandBtn.disabled=false;hostVoiceCommandBtn.classList.remove('primary');hostVoiceCommandBtn.textContent='';}
 if(hostVoiceCommandBadge&&message)hostVoiceCommandBadge.textContent=message;
}
async function startHostVoiceCommand(){
 if(MODE!=='host'||hostVoiceCommandOn)return;
 if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Браузер не поддерживает запись микрофона.';return;}
 hostVoiceCommandOn=true;hostVoiceCommandLastText='';hostVoiceCommandFinalized=false;if(hostVoiceCommandBtn){hostVoiceCommandBtn.disabled=true;hostVoiceCommandBtn.classList.add('primary');hostVoiceCommandBtn.textContent='Подключаем…';}if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Разрешите доступ к микрофону…';
 try{
  hostVoiceCommandStream=await navigator.mediaDevices.getUserMedia({audio:true});
  const ticket=await requestHostVoiceCommandTicket();
  const ws=new WebSocket(sttUrlFromTicket(ticket));hostVoiceCommandSocket=ws;ws.binaryType='arraybuffer';
  ws.onopen=()=>{try{ws.send(JSON.stringify(sttJoinPayloadFromTicket(ticket,'participant')));if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Подключаем распознавание…';}catch(e){cleanupHostVoiceCommand('Не удалось открыть распознавание.');}};
  ws.onmessage=ev=>{
   let m;try{m=JSON.parse(String(ev.data));}catch(e){return;}
   const spoken=readSpeechText(m);
   const type=String(m.type||m.event||'').toLowerCase();
   if(type==='joined'){if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Ведущий авторизован…';return;}
   if(type==='ready'){
    const mime=chooseSttMime();if(!mime){cleanupHostVoiceCommand('Нет поддерживаемого аудиоформата.');return;}
    try{
     const rec=new MediaRecorder(hostVoiceCommandStream,{mimeType:mime});hostVoiceCommandRecorder=rec;hostVoiceCommandSendChain=Promise.resolve();
     rec.ondataavailable=e=>{if(!e.data||!e.data.size)return;hostVoiceCommandSendChain=hostVoiceCommandSendChain.then(async()=>{const buf=await e.data.arrayBuffer();if(hostVoiceCommandSocket&&hostVoiceCommandSocket.readyState===WebSocket.OPEN&&hostVoiceCommandOn)hostVoiceCommandSocket.send(buf);});};
     rec.onstop=async()=>{await hostVoiceCommandSendChain;if(hostVoiceCommandSocket&&hostVoiceCommandSocket.readyState===WebSocket.OPEN){try{hostVoiceCommandSocket.send(JSON.stringify({type:'stop'}));if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Распознаём команду…';}catch(e){}}};
     rec.onerror=()=>cleanupHostVoiceCommand('Ошибка записи микрофона.');
     rec.start(250);if(hostVoiceCommandBtn){hostVoiceCommandBtn.disabled=false;hostVoiceCommandBtn.textContent='■ Остановить команду';}if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Говорите команду коротко: «Задать вопрос».';
     hostVoiceCommandStopTimer=setTimeout(()=>{try{if(hostVoiceCommandRecorder&&hostVoiceCommandRecorder.state!=='inactive')hostVoiceCommandRecorder.stop();}catch(e){}},10000);
    }catch(e){cleanupHostVoiceCommand('Не удалось начать запись команды.');}
    return;
   }
   if(spoken){hostVoiceCommandLastText=spoken;if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Распознаётся: '+spoken;}
   if(type==='interim'||type==='partial'||type==='transcript')return;
   if(type==='timeout'){try{if(hostVoiceCommandRecorder&&hostVoiceCommandRecorder.state!=='inactive')hostVoiceCommandRecorder.stop();}catch(e){}return;}
   if(type==='final'||type==='result'||type==='speech_final'||m.is_final===true||m.final===true){finishHostVoiceCommand(spoken||hostVoiceCommandLastText);return;}
   if(type==='error'){cleanupHostVoiceCommand('Голосовые команды недоступны: '+String(m.detail||m.code||'ошибка распознавания'));}
  };
  ws.onerror=()=>{if(hostVoiceCommandBadge)hostVoiceCommandBadge.textContent='Ошибка соединения с распознаванием.';};
  ws.onclose=e=>{if(hostVoiceCommandSocket===ws&&hostVoiceCommandOn){if(hostVoiceCommandLastText)finishHostVoiceCommand(hostVoiceCommandLastText);else cleanupHostVoiceCommand(friendlySttCloseMessage(e.code));}};
 }catch(e){cleanupHostVoiceCommand((e&&e.message)||'Не удалось включить голосовые команды.');}
}
function setupHostVoiceCommands(){
 // Голосовые команды убраны из панели ведущего: ведущий говорит через микрофон,
 // а управление фазами остаётся кнопками. Оставляем no-op для совместимости
 // со старыми вызовами этого файла.
 if(hostVoiceCommandBtn&&hostVoiceCommandBtn.parentNode)hostVoiceCommandBtn.parentNode.removeChild(hostVoiceCommandBtn);
 if(hostVoiceCommandBadge&&hostVoiceCommandBadge.parentNode)hostVoiceCommandBadge.parentNode.removeChild(hostVoiceCommandBadge);
 hostVoiceCommandBtn=null;hostVoiceCommandBadge=null;
}


async function hostAction(command,extra){const g=lastState&&lastState.game||{},sergey=String((window.CKMQPVoiceConfig||{}).aiProvider||'')==='gateway';if(command==='start'&&g.hostMode==='ai'&&sergey&&!voiceReady){setStatus('ИИ-ведущий ждёт: Диагностика голоса — соединение готово.','');return Promise.resolve({ok:false,code:'voice_not_ready'});}const switchingToHuman=command==='switch_human';if(switchingToHuman){manualTakeoverPending=true;stopAiClientAutomation('manual_switch_requested');}setStatus('Выполняется команда ведущего…');let r;try{r=await post('host_action',Object.assign({game,token,nonce,command,voice_ready:voiceReady?1:0},extra||{}));if(r.ok&&r.state)render(r.state);if(!r.ok)throw new Error(r.error||'Ошибка команды ведущего');if(switchingToHuman){const confirmed=String(r.state&&r.state.game&&r.state.game.hostMode||'')==='human';if(!confirmed)throw new Error('Сервер не подтвердил переход к ручному управлению.');manualTakeoverPending=false;stopAiClientAutomation('manual_switch_confirmed');setStatus('Ведущий-человек включён. Автоматическое ведение ИИ остановлено.','ok');}else setStatus('Готово.','ok');return r;}catch(e){if(switchingToHuman)manualTakeoverPending=false;setStatus((e&&e.message)||'Ошибка команды ведущего','err');throw e;}}
async function hostJeopardy(command,extra){setStatus('Выполняется команда ведущего…');const r=await post('host_action',Object.assign({game,token,nonce,command},extra||{}));setStatus(r.ok?'Готово.':(r.error||'Ошибка команды ведущего'),r.ok?'ok':'err');if(r.ok&&r.state)render(r.state);else setTimeout(()=>poll(),100);}
mountSttControls();
setupHostVoiceCommands();
const answerBtn=$('answerBtn');if(answerBtn)answerBtn.addEventListener('click',()=>{const t=($('answerText').value||'').trim();if(!t){setStatus(formatKey==='negotiation_duel'?'Введите переговорную реплику.':'Введите ответ команды.','err');return;}if(sttRecording||sttStopping)abortSttDictation('');answer(t);});
const hostNext=$('hostNext');if(hostNext)hostNext.addEventListener('click',()=>hostAction(commandForHostNext()));const hostModeSwitch=$('hostModeSwitch');if(hostModeSwitch)hostModeSwitch.addEventListener('click',()=>{const ai=lastState&&lastState.game&&lastState.game.hostMode==='ai';const msg=ai?'Переключить на ведущего-человека без сброса текущего состояния?':'Передать управление ИИ с текущего состояния?';if(confirm(msg))hostAction(ai?'switch_human':'switch_ai');});const hostClose=$('hostClose');if(hostClose)hostClose.addEventListener('click',()=>{const flow=lastState&&lastState.questionFlow||{};hostAction(formatKey==='chgk'&&lastState&&lastState.game&&lastState.game.phase==='question_closed'&&flow.canRevealAnswer?'reveal':'close');});const hostFinish=$('hostFinish');if(hostFinish)hostFinish.addEventListener('click',()=>{const msg=formatKey==='chgk'?'Прервать матч до достижения 6 очков? Победитель в этом случае не объявляется.':'Завершить игру?';if(confirm(msg))hostAction('finish');});
window.addEventListener('ckmqp:voice-connection',e=>{const d=e.detail||{};if(MODE!=='host'||d.role!=='host')return;voiceReady=!!d.ready;if(lastState){if(lastState.negotiationShow){renderShowPreparation(lastState);return;}if(formatKey==='jeopardy')renderJeopardyHost(lastState);else renderGenericHost(lastState);maybeAutoStartAiHost(lastState);}});
const voicePlaybackAcked=new Set();
window.addEventListener('ckmqp:voice-playback-ended',e=>{
 const d=e.detail||{},messageId=Number(d.messageId||0);if(MODE!=='host'||d.ok===false||messageId<=0||voicePlaybackAcked.has(messageId))return;
 voicePlaybackAcked.add(messageId);
 post('voice_playback_complete',{game,token,nonce,message_id:messageId}).then(r=>{if(!r.ok){voicePlaybackAcked.delete(messageId);return;}setTimeout(()=>poll(),80);}).catch(()=>voicePlaybackAcked.delete(messageId));
});
function timerPhaseLabel(){if(formatKey!=='chgk'||!lastState||!lastState.game||lastState.game.phase!=='question_open')return '';const rp=String(lastState.formatRuntime&&lastState.formatRuntime.phase||'discussion');return rp==='question_narration'?'Озвучивание вопроса':(rp==='final_answer_open'?'Окончательный ответ':(rp==='early_answer_offer'?'Досрочный ответ':'Обсуждение'));}
setInterval(()=>{const el=$('timer');if(!el)return;const g=lastState&&lastState.game||{};const show=lastState&&lastState.negotiationShow;if(show&&show.paused){const sec=Number(show.remaining||0);el.textContent='Пауза · '+Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0');return;}if(g.isPaused){const sec=Math.max(0,Number(g.pausedRemainingSeconds||0));const clockText=Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0');const label=String(g.pausedPhase||'')==='final_answer_open'?'Окончательный ответ':'Обсуждение';el.textContent='Пауза · '+label+' — '+clockText;return;}const currentPhase=formatKey==='chgk'&&lastState&&lastState.formatRuntime?String(lastState.formatRuntime.phase||''):'';if(show&&!deadline){el.hidden=true;el.textContent='';return;}el.hidden=false;if(!deadline&&lastState&&sequentialVoiceTimerWaiting(lastState)){el.textContent='Слушайте ведущего · таймер после реплики';return;}if(!deadline){el.textContent='—';return;}const sec=Math.max(0,Math.ceil((deadline-(Date.now()+offset))/1000));const clockText=Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0');const label=timerPhaseLabel();el.textContent=label?(label+' — '+clockText):clockText;},250);
(async()=>{if(MODE==='play')await ensureJoin();poll();})();
})();
