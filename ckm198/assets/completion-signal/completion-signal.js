(()=>{
'use strict';
const cfg=window.CKMCompletionSignal||{};
const box=document.getElementById('ckm-completion-sound');
const toggle=document.getElementById('ckm-completion-sound-toggle');
const test=document.getElementById('ckm-completion-sound-test');
const note=document.getElementById('ckm-completion-sound-note');
if(!box||!toggle||!test||!cfg.url)return;
const KEY='ckm_completion_sound_enabled';
const LAST_KEY='ckm_completion_sound_last_id';
let enabled=localStorage.getItem(KEY)==='1';
let lastId=localStorage.getItem(LAST_KEY)||'';
let initialized=false;
let timer=null;
let audioCtx=null;
let unlocked=false;

function setNote(text,bad=false){if(!note)return;note.textContent=text||'';note.classList.toggle('is-error',!!bad);}
function notificationGranted(){return 'Notification'in window&&Notification.permission==='granted';}
function render(){
  toggle.setAttribute('aria-pressed',enabled?'true':'false');
  const needsClick=enabled&&!unlocked&&!notificationGranted();
  toggle.textContent=!enabled?'🔇 Звук завершения: ВЫКЛ':(needsClick?'🔊 Звук завершения: ВКЛ · НУЖЕН КЛИК':'🔊 Звук завершения: ВКЛ');
  box.classList.toggle('is-enabled',enabled);
}
function rememberId(id){if(!id)return;lastId=id;try{localStorage.setItem(LAST_KEY,id);}catch(_){}}
async function ensureNotificationPermission(){
  if(!('Notification'in window))return false;
  if(Notification.permission==='granted')return true;
  if(Notification.permission==='denied')return false;
  try{return (await Notification.requestPermission())==='granted';}catch(_){return false;}
}
function notify(message){
  if(!notificationGranted())return false;
  try{
    const n=new Notification('CKM — готово',{body:message||cfg.defaultMessage||'Готово. Задание выполнено.',tag:'ckm-completion-signal'});
    setTimeout(()=>{try{n.close();}catch(_){}},12000);
    return true;
  }catch(_){return false;}
}
async function ensureAudio(){
  const AC=window.AudioContext||window.webkitAudioContext;
  if(!AC)return false;
  try{
    if(audioCtx&&audioCtx.state==='closed')audioCtx=null;
    if(!audioCtx){
      audioCtx=new AC();
      audioCtx.onstatechange=()=>{unlocked=!!audioCtx&&audioCtx.state==='running';};
    }
    if(audioCtx.state!=='running')await audioCtx.resume();
    unlocked=audioCtx.state==='running';
    return unlocked;
  }catch(_){unlocked=false;return false;}
}
async function beep(){
  if(!(await ensureAudio()))return false;
  const now=audioCtx.currentTime;
  const gain=audioCtx.createGain();
  gain.gain.setValueAtTime(0.0001,now);
  gain.gain.exponentialRampToValueAtTime(0.18,now+0.015);
  gain.gain.exponentialRampToValueAtTime(0.0001,now+0.48);
  gain.connect(audioCtx.destination);
  [880,660].forEach((freq,i)=>{
    const o=audioCtx.createOscillator();
    o.type='sine';o.frequency.value=freq;o.connect(gain);
    o.start(now+i*0.16);o.stop(now+0.18+i*0.16);
  });
  return true;
}
function speak(message){
  if(!('speechSynthesis'in window)||!message)return;
  try{
    window.speechSynthesis.cancel();
    const u=new SpeechSynthesisUtterance(message);
    u.lang='ru-RU';u.rate=0.95;u.pitch=1;u.volume=1;
    window.speechSynthesis.speak(u);
  }catch(_){ }
}
async function play(message,withVoice=true){
  const msg=message||cfg.defaultMessage||'Готово. Задание выполнено.';
  const beepOk=await beep();
  const notificationOk=notify(msg);
  if(withVoice)setTimeout(()=>speak(msg),420);
  render();
  return beepOk||notificationOk;
}
async function poll(baseline=false){
  if(!enabled)return;
  try{
    const res=await fetch(cfg.url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
    if(!res.ok)throw new Error('HTTP '+res.status);
    const data=await res.json();
    const signal=data&&data.signal?data.signal:{};
    const id=String(signal.id||'');
    if(!initialized){
      initialized=true;
      if(baseline||!lastId){rememberId(id);render();setNote(notificationGranted()?'Звук включён. Системное уведомление готово.':'Звук включён. Для надёжной работы после перезагрузки нажмите «Проверить звук».');return;}
    }
    if(id&&id!==lastId){
      rememberId(id);
      const ok=await play(String(signal.message||cfg.defaultMessage||'Готово. Задание выполнено.'),signal.voice!==false);
      setNote(ok?'Получен сигнал завершения.':'Сигнал получен, но браузер не разрешил звук. Нажмите «Проверить звук».',!ok);
    }
  }catch(_){setNote('Не удалось проверить сигнал завершения. Следующая попытка будет автоматически.',true);}
}
function stop(){if(timer){clearInterval(timer);timer=null;}}
function start(baseline=false){stop();poll(baseline);timer=setInterval(()=>poll(false),Math.max(5000,Number(cfg.pollMs)||10000));}

toggle.addEventListener('click',async()=>{
  enabled=!enabled;localStorage.setItem(KEY,enabled?'1':'0');initialized=false;
  if(enabled){
    await ensureNotificationPermission();
    const ok=await play('Звук завершения включён.',true);
    render();
    setNote(ok?'Звук включён. Жду сигнал завершения.':'Браузер пока не разрешил звук. Нажмите «Проверить звук».',!ok);
    start(true);
  }else{stop();render();setNote('Звук завершения выключен.');}
});

test.addEventListener('click',async()=>{
  await ensureNotificationPermission();
  const ok=await play('Проверка звука. Всё работает.',true);
  render();
  setNote(ok?'Проверка звука выполнена. После перезагрузки останется системное уведомление.':'Браузер не разрешил воспроизведение. Проверьте разрешения уведомлений и нажмите кнопку ещё раз.',!ok);
});

document.addEventListener('pointerdown',()=>{if(enabled&&!unlocked)ensureAudio().then(render);},{passive:true});
document.addEventListener('keydown',()=>{if(enabled&&!unlocked)ensureAudio().then(render);},{passive:true});
render();
if(enabled)start(false);
})();
