(function(){
'use strict';
const cfg=window.CKMQPVoiceConfig||{};
const params=new URLSearchParams(location.search);
const game=String(params.get('game')||'').toUpperCase();
const team=String(params.get('team')||'').toUpperCase();
const token=String(params.get('token')||'');
const nonce=String(params.get('nonce')||'');
const role=cfg.mode==='play'?'participant':(cfg.mode==='scoreboard'?'scoreboard':'host');
const aiProvider=String(cfg.aiProvider||'browser')==='gateway'?'gateway':'browser';
const isControlRoom=role==='host';
if(!game||!token||!nonce||!['participant','scoreboard','host'].includes(role))return;

let hostMode='',mounted=false,wanted=false,connecting=false,joined=false,socket=null,reconnectTimer=null;
let toggleBtn=null,statusBadge=null,diagBox=null,diagSummary=null,diagList=null,diagError=null,micBtn=null,micBadge=null,micStream=null,micRecorder=null,micHeld=false,micActive=false,micStarting=false,micStopping=false;
let live=null,tts=null,audioContext=null,ttsQueue=[],playbackBusy=false,audioUnlocked=false;

function isHuman(){return hostMode==='human';}
function isAiGateway(){return hostMode==='ai'&&aiProvider==='gateway';}
function diagReady(){return !isAiGateway()?!!joined:(!!joined&&['wordpress','token','websocket','gateway','cartesia'].every(k=>diagState[k]&&diagState[k][0]==='ok'));}
function emitConnection(){const ready=diagReady();window.dispatchEvent(new CustomEvent('ckmqp:voice-connection',{detail:{role,provider:aiProvider,hostMode,joined:!!joined,wanted:!!wanted,ready:ready,diagnosticsReady:ready,diagnosticsText:diagSummary?String(diagSummary.textContent||''):''}}));}
function postAction(action,data,timeoutMs=8000){const f=new FormData();f.append('action',action);Object.entries(data).forEach(([k,v])=>f.append(k,String(v??'')));const controller=window.AbortController?new AbortController():null;const timer=controller?setTimeout(()=>controller.abort(),timeoutMs):null;return fetch(cfg.ajaxUrl,{method:'POST',body:f,credentials:'same-origin',signal:controller?controller.signal:undefined}).then(async r=>{let raw='',j={};try{raw=await r.text();j=raw?JSON.parse(raw):{};}catch(e){}if(timer)clearTimeout(timer);if(!r.ok||!j.ok){const err=new Error(j.error||('WordPress HTTP '+r.status));err.httpStatus=r.status;err.code=j.code||'';err.response=raw.slice(0,500);throw err;}return j;}).catch(err=>{if(timer)clearTimeout(timer);if(err&&err.name==='AbortError'){const e=new Error('Таймаут ответа WordPress ('+Math.round(timeoutMs/1000)+' с).');e.code='wordpress_timeout';throw e;}throw err;});}
function setStatus(text,good){if(!statusBadge)return;statusBadge.textContent=text;statusBadge.className='voice-badge'+(good?' on':'');}
function setToggle(text,on){if(!toggleBtn)return;toggleBtn.textContent=text;toggleBtn.setAttribute('aria-pressed',on?'true':'false');toggleBtn.classList.toggle('primary',!!on);}
const diagState={wordpress:['idle','ожидание'],token:['idle','ожидание'],websocket:['idle','ожидание'],gateway:['idle','ожидание'],cartesia:['idle','ожидание']};
const diagLabels={wordpress:'WordPress',token:'Голосовой токен',websocket:'WebSocket',gateway:'Gateway',cartesia:'Cartesia · Сергей'};
function diagMark(key,state,detail){if(!diagState[key])return;diagState[key]=[state,String(detail||'')];renderDiag();}
function diagSymbol(state){return state==='ok'?'✓':(state==='err'?'✕':(state==='pending'?'…':'○'));}
function renderDiag(){if(!diagList)return;diagList.innerHTML='';Object.keys(diagLabels).forEach(key=>{const [state,detail]=diagState[key];const row=document.createElement('div');row.className='voice-diag-row '+state;const a=document.createElement('span');a.className='voice-diag-symbol';a.textContent=diagSymbol(state);const b=document.createElement('span');b.className='voice-diag-name';b.textContent=diagLabels[key];const c=document.createElement('span');c.className='voice-diag-detail';c.textContent=detail;row.append(a,b,c);diagList.appendChild(row);});if(diagSummary){const bad=Object.values(diagState).some(v=>v[0]==='err'),done=['wordpress','token','websocket','gateway','cartesia'].every(k=>diagState[k][0]==='ok');diagSummary.textContent=bad?'Диагностика голоса: есть ошибка':(done?'Диагностика голоса: соединение готово':'Диагностика голоса');if(bad&&diagBox)diagBox.open=true;}if(diagError){const bad=Object.entries(diagState).find(([,v])=>v[0]==='err');diagError.textContent=bad?bad[1][1]:'';diagError.style.display=bad?'block':'none';}if(isControlRoom)emitConnection();}
function mountDiagnostics(wrap){if(!isControlRoom||!isAiGateway())return;diagBox=document.createElement('details');diagBox.className='voice-diag';diagSummary=document.createElement('summary');diagSummary.textContent='Диагностика голоса';diagList=document.createElement('div');diagList.className='voice-diag-list';diagError=document.createElement('div');diagError.className='voice-diag-error';diagError.style.display='none';diagBox.append(diagSummary,diagList,diagError);wrap.appendChild(diagBox);diagMark('wordpress','ok','страница CKM загружена');diagMark('token','idle','ещё не запрошен');diagMark('websocket','idle','ещё не открыт');diagMark('gateway','idle','ожидает WebSocket');diagMark('cartesia','idle','проверка после токена');}
function resetConnectionDiag(){diagMark('token','pending','запрашиваем у WordPress…');diagMark('websocket','idle','ожидает токен');diagMark('gateway','idle','ожидает WebSocket');diagMark('cartesia','pending','проверяем Gateway /health…');}
function requestGatewayDiag(){return postAction('ckm_qp_voice_diag',{game,role,team,token,nonce},7000).then(d=>{const h=d.health||{},t=h.tts||{},w=h.websocket||{};if(t.enabled&&t.configured){const voice=String(t.voice_id||'');diagMark('cartesia','ok','готов · '+String(t.model||'Cartesia')+(voice?' · '+voice.slice(0,8)+'…':''));}else{diagMark('cartesia','err','TTS не настроен на Gateway');}if(w.enabled===false||w.configured===false)diagMark('gateway','err','WebSocket отключён на Gateway');return d;}).catch(err=>{diagMark('cartesia','err','health: '+(err.message||'ошибка'));return null;});}
function currentOffText(){return isAiGateway()?'Включить Сергея':(role==='host'?'Подключить микрофон ведущего':'Включить голос ведущего');}
function currentOnText(){return isAiGateway()?'🔊 Сергей: вкл':(role==='host'?'Микрофон ведущего подключён':'Голос ведущего: вкл');}
function setMic(state,detail){if(!micBtn||!micBadge)return;const isLive=state==='live',ready=state==='ready',busy=state==='starting'||state==='stopping';micBtn.disabled=!joined||busy;micBtn.textContent=isLive?'Отпустите, чтобы закончить':(busy?'Микрофон…':'Удерживать и говорить');micBtn.classList.toggle('danger',isLive);micBtn.classList.toggle('primary',ready&&!isLive);micBtn.setAttribute('aria-pressed',isLive?'true':'false');micBadge.textContent=isLive?'Вы в эфире':(ready?'Микрофон ведущего готов':'Микрофон ведущего выключен');micBadge.className='voice-badge'+(isLive?' live':(ready?' on':''));micBadge.title=detail||'';}


function unmountVoiceControls(){
  document.querySelectorAll('[data-ckmqp-voice-controls="1"]').forEach(n=>{try{n.remove();}catch(e){}});
  toggleBtn=null;statusBadge=null;diagBox=null;diagSummary=null;diagList=null;diagError=null;micBtn=null;micBadge=null;mounted=false;
}
function mount(){
  if(mounted||(!isHuman()&&!isAiGateway()))return;mounted=true;
  const unlock=()=>{unlockAudio().then(playNextTts).catch(()=>{});};
  document.addEventListener('pointerdown',unlock,{passive:true});
  document.addEventListener('keydown',unlock);

  // Participants and the public scoreboard are listeners only. They connect
  // silently and never show «Включить Сергея», Cartesia status or diagnostics.
  if(!isControlRoom){
    if(!cfg.ready)return;
    wanted=true;emitConnection();connect();
    return;
  }

  const top=document.querySelector('.top');if(!top)return;
  const wrap=document.createElement('div');wrap.className='voice-controls';wrap.setAttribute('data-ckmqp-voice-controls','1');
  if(!cfg.ready){const b=document.createElement('span');b.className='voice-badge';b.textContent=isAiGateway()?'Сергей: Gateway не настроен':'Микрофон не настроен';wrap.appendChild(b);top.appendChild(wrap);emitConnection();return;}
  toggleBtn=document.createElement('button');toggleBtn.type='button';toggleBtn.className='voice-btn';toggleBtn.textContent=currentOffText();toggleBtn.addEventListener('click',async()=>{if(wanted){disconnect();return;}try{await unlockAudio();}catch(e){}wanted=true;emitConnection();connect();});wrap.appendChild(toggleBtn);
  statusBadge=document.createElement('span');statusBadge.className='voice-badge';statusBadge.textContent=isAiGateway()?'Cartesia · Сергей':'Голосовой ведущий выкл';wrap.appendChild(statusBadge);
  mountDiagnostics(wrap);
  if(isHuman()){
    micBtn=document.createElement('button');micBtn.type='button';micBtn.className='voice-btn';micBtn.textContent='Удерживать и говорить';micBtn.disabled=true;wrap.appendChild(micBtn);
    micBadge=document.createElement('span');micBadge.className='voice-badge';micBadge.textContent='Микрофон ведущего выключен';wrap.appendChild(micBadge);
    micBtn.addEventListener('pointerdown',e=>{e.preventDefault();if(!joined)return;micHeld=true;try{micBtn.setPointerCapture(e.pointerId);}catch(x){}startMic();});
    micBtn.addEventListener('pointerup',e=>{e.preventDefault();micHeld=false;stopMic(true);});
    micBtn.addEventListener('pointercancel',()=>{micHeld=false;stopMic(true);});
    micBtn.addEventListener('keydown',e=>{if((e.code==='Space'||e.code==='Enter')&&!e.repeat){e.preventDefault();micHeld=true;startMic();}});
    micBtn.addEventListener('keyup',e=>{if(e.code==='Space'||e.code==='Enter'){e.preventDefault();micHeld=false;stopMic(true);}});
  }
  top.appendChild(wrap);
  if(isAiGateway()){
    // В режиме ИИ-ведущего Сергей подключается автоматически: ведущему не нужно
    // нажимать отдельную кнопку перед первым вопросом.
    wanted=true;joined=false;emitConnection();setToggle('Подключаем Сергея…',false);setStatus('Сергей подключается…',false);setTimeout(connect,120);
  } else {
    wanted=false;joined=false;emitConnection();
  }
}

window.addEventListener('ckmqp:state',e=>{const st=e.detail||{},g=st.game||{};const mode=String(g.hostMode||'');if(mode&&hostMode&&mode!==hostMode){disconnect();unmountVoiceControls();}if(mode)hostMode=mode;if((isHuman()||isAiGateway()))mount();});

async function unlockAudio(){
  const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx)throw new Error('Браузер не поддерживает Web Audio.');
  if(!audioContext)audioContext=new Ctx();
  if(audioContext.state==='suspended')await audioContext.resume();
  if(!audioUnlocked){const b=audioContext.createBuffer(1,1,22050),s=audioContext.createBufferSource();s.buffer=b;s.connect(audioContext.destination);s.start(0);audioUnlocked=true;}
}
function requestTicket(){return postAction('ckm_qp_voice_token',{game,role,team,token,nonce},8000);}
function joinPayload(t){const p={type:'join',game_id:String(t.gameId||game),role:String(t.role||role),token:String(t.token||'')};if(role==='participant')p.team_id=String(t.teamId||'');return p;}
async function connect(){
  if(!wanted||connecting)return;if(socket&&(socket.readyState===WebSocket.OPEN||socket.readyState===WebSocket.CONNECTING))return;
  connecting=true;setToggle(isAiGateway()?'Сергей: подключение…':'Подключение…',false);setStatus('Соединение…',false);if(isAiGateway())resetConnectionDiag();
  try{
    const t=await requestTicket();diagMark('wordpress','ok','AJAX отвечает');diagMark('token','ok','создан · до '+new Date(Number(t.expiresAt||0)*1000).toLocaleTimeString());if(isControlRoom&&isAiGateway())requestGatewayDiag();
    const wsUrl=String(t.wsUrl||'');if(!/^wss?:\/\//i.test(wsUrl)){diagMark('websocket','err','неверный URL: '+wsUrl);throw new Error('Gateway не вернул WebSocket URL.');}
    diagMark('websocket','pending',wsUrl.replace(/\?.*$/,''));diagMark('gateway','pending','ожидаем подтверждение join…');
    const ws=new WebSocket(wsUrl);socket=ws;ws.binaryType='arraybuffer';let opened=false;let joinedHere=false;
    const connectTimer=setTimeout(()=>{if(!opened&&socket===ws){diagMark('websocket','err','таймаут открытия 8 с');try{ws.close(4000,'client_connect_timeout');}catch(e){}}},8000);
    const joinTimer=setTimeout(()=>{if(opened&&!joinedHere&&socket===ws){diagMark('gateway','err','Gateway не подтвердил join за 8 с');try{ws.close(4001,'client_join_timeout');}catch(e){}}},16000);
    ws.onopen=()=>{opened=true;clearTimeout(connectTimer);diagMark('websocket','ok','открыт');try{ws.send(JSON.stringify(joinPayload(t)));}catch(e){diagMark('gateway','err','не удалось отправить join');try{ws.close();}catch(x){}}};
    ws.onmessage=e=>{if(typeof e.data==='string'){try{const msg=JSON.parse(e.data);if(msg&&msg.type==='joined'){joinedHere=true;clearTimeout(joinTimer);}control(msg);}catch(x){}return;}const consume=buf=>{const part=new Uint8Array(buf);if(tts){tts.chunks.push(part);tts.total+=part.byteLength;return;}if(live)appendChunk(part);};if(e.data instanceof ArrayBuffer)consume(e.data);else if(e.data instanceof Blob)e.data.arrayBuffer().then(consume);};
    ws.onclose=e=>{clearTimeout(connectTimer);clearTimeout(joinTimer);connecting=false;joined=false;emitConnection();if(socket===ws)socket=null;stopMic(false);destroyLive(true);tts=null;const reason=String(e.reason||'');const detail='код '+String(e.code)+(reason?' · '+reason:'');if(wanted){if(!opened)diagMark('websocket','err','закрыт до открытия · '+detail);else if(!joinedHere)diagMark('gateway','err','join не принят · '+detail);else diagMark('gateway','err','соединение закрыто · '+detail);}setStatus(wanted?'Переподключение…':'Голос выкл',false);if(role==='host'&&isHuman())setMic('off');if(wanted)scheduleReconnect();};
    ws.onerror=()=>{diagMark('websocket','err','браузер сообщил ошибку WebSocket; смотрите код закрытия ниже');};
  }catch(err){connecting=false;joined=false;emitConnection();diagMark('wordpress',err&&err.httpStatus?'err':'ok',err&&err.httpStatus?('HTTP '+err.httpStatus):'AJAX доступен');if(diagState.token[0]==='pending')diagMark('token','err',(err&&err.code?err.code+': ':'')+(err&&err.message?err.message:'ошибка токена'));setToggle(currentOffText(),false);setStatus('Ошибка',false);wanted=false;console.warn('CKM voice connect failed',err);}
}
function disconnect(){wanted=false;connecting=false;joined=false;emitConnection();if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null;}stopMic(true);destroyLive(true);tts=null;if(socket){try{socket.close(1000,'voice_disabled');}catch(e){}}socket=null;setToggle(currentOffText(),false);setStatus(isAiGateway()?'Сергей выключен':(role==='host'?'Голосовой ведущий выключен':'Голос выкл'),false);if(role==='host'&&isHuman())setMic('off');}
function scheduleReconnect(){if(!wanted||reconnectTimer)return;reconnectTimer=setTimeout(()=>{reconnectTimer=null;connect();},1800);}

function control(m){
  if(!m||typeof m!=='object')return;
  if(m.type==='joined'){connecting=false;joined=true;diagMark('gateway','ok','join подтверждён · '+String(m.auth_mode||'signed token'));setToggle(currentOnText(),true);setStatus(isAiGateway()?'Cartesia · Сергей':'Канал подключён',true);if(role==='host'&&isHuman())setMic('ready');emitConnection();return;}
  if(m.type==='voice_start'&&isAiGateway()){diagMark('cartesia','ok','аудио началось · message '+String(m.message_id||''));tts={id:String(m.message_id||''),mime:String(m.mime_type||'audio/wav'),chunks:[],total:0};setStatus('Сергей говорит…',true);return;}
  if(m.type==='voice_end'&&isAiGateway()){const done=tts;tts=null;diagMark('cartesia','ok','аудио получено · '+String(done?done.total:0)+' байт');setStatus('Cartesia · Сергей',true);if(done&&done.total>0){ttsQueue.push(done);playNextTts();}return;}
  if(m.type==='voice_failed'&&isAiGateway()){tts=null;diagMark('cartesia','err',String(m.detail||m.error||'voice_failed'));setStatus('Ошибка Cartesia',false);return;}
  if(m.type==='host_live_start'){if(role!=='host')startLive(m);return;}
  if(m.type==='host_live_end'){if(role!=='host')endLive();return;}
  if(m.type==='host_live_ready'){if(role==='host'&&micActive)setMic('live','Слушателей: '+String(m.listeners||0));return;}
  if(m.type==='host_live_stopped'){if(role==='host')setMic(joined?'ready':'off');return;}
  if(m.type==='error'){const detail=String(m.detail||m.code||'Ошибка Gateway');if(role==='host'&&isHuman()&&/^host_live_/.test(String(m.code||''))){setMic(joined?'ready':'off',detail);alert('Голос ведущего: '+detail);}else{diagMark('gateway','err',detail);setStatus('Ошибка Gateway',false);}return;}
}

function concatChunks(parts,total){const out=new Uint8Array(total);let o=0;parts.forEach(p=>{out.set(p,o);o+=p.byteLength;});return out.buffer;}
async function playNextTts(){
  if(playbackBusy||!wanted||!ttsQueue.length)return;
  try{await unlockAudio();}catch(e){setStatus('Нажмите «Включить Сергея»',false);return;}
  if(!audioContext||audioContext.state!=='running')return;
  playbackBusy=true;const item=ttsQueue.shift();
  let playbackOk=false;
  try{const bytes=concatChunks(item.chunks,item.total),decoded=await audioContext.decodeAudioData(bytes.slice(0));const source=audioContext.createBufferSource();source.buffer=decoded;source.connect(audioContext.destination);await new Promise((resolve,reject)=>{source.onended=resolve;try{source.start(0);}catch(e){reject(e);}});playbackOk=true;}catch(e){console.warn('CKM Sergey playback failed',e);setStatus('Ошибка воспроизведения',false);}finally{if(item&&item.id)window.dispatchEvent(new CustomEvent('ckmqp:voice-playback-ended',{detail:{messageId:Number(item.id||0),provider:'gateway',ok:playbackOk}}));playbackBusy=false;if(ttsQueue.length)setTimeout(playNextTts,40);}
}

function startLive(m){destroyLive(true);const mime=String(m.mime_type||'audio/webm;codecs=opus');live={mime,chunks:[],total:0,queue:[],ending:false,mode:'buffer',audio:null,mediaSource:null,sourceBuffer:null,url:''};setStatus('Ведущий говорит…',true);if(window.MediaSource&&MediaSource.isTypeSupported&&MediaSource.isTypeSupported(mime)){try{const a=document.createElement('audio');a.autoplay=true;a.playsInline=true;a.hidden=true;document.body.appendChild(a);const ms=new MediaSource(),url=URL.createObjectURL(ms);live.mode='mse';live.audio=a;live.mediaSource=ms;live.url=url;a.src=url;a.addEventListener('ended',()=>destroyLive(false),{once:true});ms.addEventListener('sourceopen',()=>{if(!live||live.mediaSource!==ms)return;try{live.sourceBuffer=ms.addSourceBuffer(mime);live.sourceBuffer.mode='sequence';live.sourceBuffer.addEventListener('updateend',pumpLive);pumpLive();const p=a.play();if(p&&p.catch)p.catch(()=>{});}catch(e){if(live)live.mode='buffer';}},{once:true});}catch(e){if(live)live.mode='buffer';}}}
function appendChunk(part){if(!live)return;live.chunks.push(part);live.total+=part.byteLength;if(live.mode==='mse'){live.queue.push(part);pumpLive();}}
function pumpLive(){const l=live;if(!l||l.mode!=='mse'||!l.sourceBuffer||l.sourceBuffer.updating||!l.queue.length){finishMedia(l);return;}const p=l.queue.shift();try{l.sourceBuffer.appendBuffer(p.buffer.slice(p.byteOffset,p.byteOffset+p.byteLength));}catch(e){l.mode='buffer';}}
function finishMedia(l){if(!l||l!==live||l.mode!=='mse'||!l.ending||l.queue.length||(l.sourceBuffer&&l.sourceBuffer.updating))return;try{if(l.mediaSource&&l.mediaSource.readyState==='open')l.mediaSource.endOfStream();}catch(e){}}
function endLive(){const l=live;if(!l)return;setStatus('Канал подключён',true);l.ending=true;if(l.mode==='mse'){finishMedia(l);return;}if(!l.total){destroyLive(true);return;}try{const blob=new Blob(l.chunks,{type:l.mime}),a=new Audio(URL.createObjectURL(blob));l.audio=a;l.url=a.src;a.onended=()=>destroyLive(false);const p=a.play();if(p&&p.catch)p.catch(()=>{});}catch(e){destroyLive(true);}}
function destroyLive(force){const l=live;live=null;if(!l)return;if(l.audio){if(force){try{l.audio.pause();}catch(e){}}if(l.audio.parentNode)l.audio.parentNode.removeChild(l.audio);}if(l.url){try{URL.revokeObjectURL(l.url);}catch(e){}}}

function chooseMime(){if(!window.MediaRecorder)return'';for(const m of ['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/webm']){if(!MediaRecorder.isTypeSupported||MediaRecorder.isTypeSupported(m))return m;}return'';}
async function ensureMic(){if(micStream&&micStream.getAudioTracks().some(t=>t.readyState==='live'))return micStream;if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)throw new Error('Браузер не поддерживает доступ к микрофону.');micStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true,channelCount:1},video:false});return micStream;}
async function startMic(){if(!isHuman()||role!=='host'||micActive||micStarting||micStopping)return;if(!joined){alert('Сначала нажмите «Подключить микрофон ведущего».');return;}micStarting=true;setMic('starting');try{const stream=await ensureMic();if(!micHeld){setMic('ready');return;}const mime=chooseMime();if(!mime)throw new Error('Браузер не поддерживает Opus-запись микрофона.');const rec=new MediaRecorder(stream,{mimeType:mime,audioBitsPerSecond:24000});micRecorder=rec;micActive=true;socket.send(JSON.stringify({type:'host_live_start',mime_type:mime}));rec.ondataavailable=e=>{if((!micActive&&!micStopping)||!e.data||!e.data.size||!socket||socket.readyState!==WebSocket.OPEN)return;e.data.arrayBuffer().then(buf=>{if((!micActive&&!micStopping)||!socket||socket.readyState!==WebSocket.OPEN)return;const bytes=new Uint8Array(buf),max=12000;for(let o=0;o<bytes.byteLength;o+=max)socket.send(bytes.slice(o,Math.min(bytes.byteLength,o+max)).buffer);});};rec.onerror=()=>stopMic(true);rec.onstop=()=>{if(socket&&socket.readyState===WebSocket.OPEN&&joined){try{socket.send(JSON.stringify({type:'host_live_end'}));}catch(e){}}micActive=false;micStopping=false;micRecorder=null;setMic(joined?'ready':'off');};rec.start(100);setMic('live');}catch(err){micActive=false;micStopping=false;setMic(joined?'ready':'off',err.message||'Ошибка микрофона');alert(err.message||'Не удалось включить микрофон.');}finally{micStarting=false;}}
function stopMic(sendEnd){micHeld=false;if(micRecorder&&micRecorder.state!=='inactive'){micStopping=true;setMic('stopping');try{micRecorder.stop();}catch(e){}return;}if(sendEnd&&micActive&&socket&&socket.readyState===WebSocket.OPEN&&joined){try{socket.send(JSON.stringify({type:'host_live_end'}));}catch(e){}}micActive=false;micStopping=false;micRecorder=null;if(role==='host'&&isHuman())setMic(joined?'ready':'off');}
window.addEventListener('pagehide',()=>{disconnect();if(micStream)micStream.getTracks().forEach(t=>{try{t.stop();}catch(e){}});});
})();
