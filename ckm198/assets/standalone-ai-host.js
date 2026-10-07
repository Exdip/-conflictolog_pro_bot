(function(){
'use strict';
const cfg=window.CKMQPAIHostConfig||{};
const provider=String(cfg.provider||'browser')==='gateway'?'gateway':'browser';
let mounted=false,voiceEnabled=localStorage.getItem('ckmqp_ai_voice')!=='0',firstState=true;
let seen=new Set(),queue=[],speaking=false,lastText='',lastEventId=0;
let card=null,textEl=null,toggle=null,badge=null,voiceSelect=null;

function escText(v){return String(v==null?'':v).trim();}
function eventText(ev){
  const p=ev&&ev.payload||{};
  if(p&&p.text&&typeof p.text==='object') return escText(p.text.content);
  if(p&&typeof p.text==='string') return escText(p.text);
  if(p&&typeof p.content==='string') return escText(p.content);
  return '';
}
function russianVoices(){
  if(!window.speechSynthesis)return[];
  const all=window.speechSynthesis.getVoices()||[];
  const ru=all.filter(v=>/^ru(?:-|_)/i.test(v.lang||''));
  return ru.length?ru:all;
}
function populateVoices(){
  if(!voiceSelect||!window.speechSynthesis)return;
  const voices=russianVoices();
  const saved=localStorage.getItem('ckmqp_ai_browser_voice')||'';
  const previous=voiceSelect.value||saved;
  voiceSelect.innerHTML='';
  voices.forEach(v=>{const o=document.createElement('option');o.value=v.name;o.textContent=(v.name||'Голос')+(v.lang?' · '+v.lang:'');voiceSelect.appendChild(o);});
  if(previous&&voices.some(v=>v.name===previous))voiceSelect.value=previous;
  else if(voices.length){const preferred=voices.find(v=>/pavel|yuri|maxim|male|муж/i.test(v.name||''))||voices[0];voiceSelect.value=preferred.name;localStorage.setItem('ckmqp_ai_browser_voice',preferred.name);}
}
function mount(){
  if(mounted)return; mounted=true;
  const top=document.querySelector('.top');
  if(top&&provider==='browser'){
    const wrap=document.createElement('div'); wrap.className='voice-controls ai-voice-controls';
    toggle=document.createElement('button'); toggle.type='button'; toggle.className='voice-btn';
    toggle.addEventListener('click',()=>{voiceEnabled=!voiceEnabled;localStorage.setItem('ckmqp_ai_voice',voiceEnabled?'1':'0');refreshToggle();if(voiceEnabled&&lastText)enqueue(lastText,true,0);else if(!voiceEnabled&&window.speechSynthesis){window.speechSynthesis.cancel();queue=[];speaking=false;}});
    voiceSelect=document.createElement('select');voiceSelect.className='voice-select';voiceSelect.setAttribute('aria-label','Браузерный голос ИИ-ведущего');voiceSelect.title='Голос браузера';
    voiceSelect.addEventListener('change',()=>{localStorage.setItem('ckmqp_ai_browser_voice',voiceSelect.value||'');if(voiceEnabled&&lastText)enqueue(lastText,true,0);});
    badge=document.createElement('span');badge.className='voice-badge';
    wrap.append(toggle,voiceSelect,badge); top.appendChild(wrap); populateVoices(); refreshToggle();
  }
  const main=document.querySelector('main');
  if(main){
    card=document.createElement('section');card.className='card ai-host-card';card.hidden=true;
    card.innerHTML='<div class="muted">ИИ-ведущий</div><div class="ai-host-message" aria-live="polite"></div>';
    textEl=card.querySelector('.ai-host-message');
    const hero=main.querySelector('.hero');
    if(hero&&hero.nextSibling)main.insertBefore(card,hero.nextSibling);else main.prepend(card);
  }
  if(provider==='browser'){
    const unlock=()=>{if(voiceEnabled&&lastText&&!speaking)enqueue(lastText,true,lastEventId);document.removeEventListener('pointerdown',unlock);document.removeEventListener('keydown',unlock);};
    document.addEventListener('pointerdown',unlock,{passive:true});
    document.addEventListener('keydown',unlock);
  }
}
function refreshToggle(){
  if(provider!=='browser')return;
  if(toggle){toggle.textContent=voiceEnabled?'🔊 Браузерный голос: вкл':'🔇 Браузерный голос: выкл';toggle.classList.toggle('primary',voiceEnabled);toggle.setAttribute('aria-pressed',voiceEnabled?'true':'false');}
  if(badge){const ok=!!window.speechSynthesis;badge.textContent=ok?'Бесплатно':'TTS недоступен';badge.className='voice-badge'+(ok&&voiceEnabled?' on':'');}
}
function chooseVoice(){
  if(!window.speechSynthesis)return null;
  const voices=window.speechSynthesis.getVoices()||[];
  const saved=localStorage.getItem('ckmqp_ai_browser_voice')||(voiceSelect?voiceSelect.value:'');
  if(saved){const exact=voices.find(v=>v.name===saved);if(exact)return exact;}
  const ru=voices.filter(v=>/^ru(?:-|_)/i.test(v.lang||''));
  return ru.find(v=>/pavel|yuri|maxim|male|муж/i.test(v.name||''))||ru[0]||voices.find(v=>/^ru/i.test(v.lang||''))||null;
}
function enqueue(text,priority,eventId){
  text=escText(text);eventId=Number(eventId||0);
  if(provider!=='browser'||!text)return;
  if(!voiceEnabled||!window.speechSynthesis){if(eventId)window.dispatchEvent(new CustomEvent('ckmqp:voice-playback-ended',{detail:{messageId:eventId,provider:'browser',ok:false,skipped:true}}));return;}
  if(priority){window.speechSynthesis.cancel();queue=[];speaking=false;}
  if(queue.length&&queue[queue.length-1]&&queue[queue.length-1].text===text)return;
  queue.push({text,eventId}); pump();
}
function pump(){
  if(provider!=='browser'||speaking||!queue.length||!voiceEnabled||!window.speechSynthesis)return;
  const item=queue.shift(),text=String(item&&item.text||'');
  const u=new SpeechSynthesisUtterance(text);
  u.lang='ru-RU';u.rate=.94;u.pitch=1;u.volume=1;
  const v=chooseVoice();if(v)u.voice=v;
  speaking=true;
  const finish=ok=>{if(item&&item.eventId)window.dispatchEvent(new CustomEvent('ckmqp:voice-playback-ended',{detail:{messageId:Number(item.eventId||0),provider:'browser',ok:!!ok}}));speaking=false;setTimeout(pump,60);};
  u.onend=()=>finish(true);u.onerror=()=>finish(false);
  try{window.speechSynthesis.speak(u);}catch(e){finish(false);}
}
function compactText(v){return escText(v).replace(/\s+/g,' ').trim();}
function duplicatesCurrentPrompt(st,text){
  const q=st&&st.question||{};
  const prompt=compactText(q.text||q.questionText||'');
  const host=compactText(text);
  if(!prompt||!host)return false;
  // The automatic host deliberately voices the full current prompt. The same
  // text is already visible in the main situation/question card, so showing a
  // second visual copy adds no information. Keep TTS, hide only the duplicate card.
  return host===prompt || host===('Следующий этап. '+prompt) || host.endsWith(' '+prompt);
}
function show(text,visible){
  lastText=text;
  if(card&&textEl){
    textEl.textContent=text;
    card.hidden=!text||visible===false;
  }
}
function stopAiOutput(){
  queue=[];speaking=false;lastText='';
  if(provider==='browser'&&window.speechSynthesis){try{window.speechSynthesis.cancel();}catch(e){}}
  if(card)card.hidden=true;
}
function processState(st){
  const g=st&&st.game||{};if(String(g.hostMode||'')!=='ai'){stopAiOutput();return;}
  mount();
  const events=(st&&st.events||[]).filter(e=>String(e.action||'')==='ai_host_message');
  if(!events.length)return;
  if(firstState){
    events.forEach(e=>seen.add(Number(e.id||0)));
    const latest=events[events.length-1],t=eventText(latest);
    if(t){lastEventId=Number(latest.id||0);show(t,!duplicatesCurrentPrompt(st,t));enqueue(t,false,lastEventId);}
    firstState=false;return;
  }
  const fresh=events.filter(e=>{const id=Number(e.id||0);return id&&!seen.has(id);});
  fresh.forEach(e=>{
    const id=Number(e.id||0);seen.add(id);const t=eventText(e);
    if(t){lastEventId=Number(e.id||0);show(t,!duplicatesCurrentPrompt(st,t));enqueue(t,false,lastEventId);}
  });
}
window.addEventListener('ckmqp:state',e=>processState(e.detail||{}));
window.addEventListener('ckmqp:ai-host-stop',()=>stopAiOutput());
if(window.speechSynthesis&&window.speechSynthesis.addEventListener)window.speechSynthesis.addEventListener('voiceschanged',()=>{populateVoices();refreshToggle();});
})();
