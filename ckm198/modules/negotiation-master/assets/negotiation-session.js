(()=>{
'use strict';
const cfg=window.CKMNegSessionConfig||{};
const root=document.getElementById('ckm-neg-app');
if(!root)return;
const $=id=>document.getElementById(id);
let sessionId=Number(cfg.activeSessionId||0), snapshot=null, pendingClientId='', pendingText='', currentAgreement=null, noDealToken='', evaluationBusy=false, evaluationAttemptedSession=0;
let runtimeClientId='';

function uuid(){
  if(window.crypto&&typeof window.crypto.randomUUID==='function')return window.crypto.randomUUID();
  return 'msg-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,14);
}
try{runtimeClientId=window.sessionStorage.getItem('ckmNegRuntimeClientId')||'';if(!runtimeClientId){runtimeClientId='tab-'+uuid();window.sessionStorage.setItem('ckmNegRuntimeClientId',runtimeClientId);}}catch(e){runtimeClientId='tab-'+uuid();}
function setStatus(text,error=false,pre=false){const el=$(pre?'ckm-neg-prestatus':'ckm-neg-status');if(!el)return;el.textContent=text||'';el.classList.toggle('is-error',!!error);}
async function api(path,options={}){
  const original=Object.assign({},options), noTakeover=!!original.__noTakeover;delete original.__noTakeover;
  const method=String(original.method||'GET').toUpperCase();
  if(!['GET','HEAD'].includes(method)){
    let payload={};
    if(original.body){try{payload=JSON.parse(String(original.body));}catch(e){payload={};}}
    if(!payload||typeof payload!=='object'||Array.isArray(payload))payload={};
    if(!payload.client_id)payload.client_id=runtimeClientId;
    original.body=JSON.stringify(payload);
  }
  const headers=Object.assign({'Accept':'application/json','X-WP-Nonce':String(cfg.nonce||'')},original.headers||{});
  if(original.body&&!headers['Content-Type'])headers['Content-Type']='application/json';
  const res=await fetch(String(cfg.restBase||'').replace(/\/$/,'')+path,Object.assign({credentials:'same-origin'},original,{headers}));
  let data={};try{data=await res.json();}catch(e){}
  if(!res.ok){
    if(res.status===409&&data&&data.code==='WRITER_CONFLICT'&&!noTakeover&&sessionId&&window.confirm('Эта сессия открыта в другой вкладке или на другом устройстве. Перехватить управление здесь?')){
      await api('/sessions/'+sessionId+'/writer/takeover',{method:'POST',body:'{}',__noTakeover:true});
      return api(path,Object.assign({},options,{__noTakeover:true}));
    }
    const err=new Error(data.message||data.code||('HTTP '+res.status));err.status=res.status;err.data=data;throw err;
  }
  return data;
}
function clear(el){while(el&&el.firstChild)el.removeChild(el.firstChild);}
function lowerFirstUserText(text){
  const chars=Array.from(String(text??'').trim());
  if(!chars.length)return '';
  chars[0]=chars[0].toLocaleLowerCase('ru-RU');
  return chars.join('');
}
function inlineListDescriptions(text){
  const lines=String(text??'').split(/\r?\n/),out=[];
  for(let i=0;i<lines.length;i++){
    const head=lines[i].match(/^(\s*)-\s+(.+?):\s*$/u);
    if(head&&i+1<lines.length){
      const detail=lines[i+1].match(/^(\s+)-\s+(.+?)\s*$/u);
      if(detail&&detail[1].length>head[1].length){
        let description=String(detail[2]||'').trim().replace(/[.!?;:]+$/u,'');
        description=lowerFirstUserText(description);
        out.push(head[1]+'- '+head[2].trim()+' ('+description+').');
        i++;continue;
      }
    }
    out.push(lines[i]);
  }
  return out.join('\n');
}
function cleanUserFacingText(text){
  const cleaned=String(text??'')
    .replace(/\*+/g,'')
    .replace(/(^|\n)\s*\d{1,3}[.)]\s+/g,'$1- ')
    .replace(/(^|\n)\s*[•●▪]\s*/g,'$1- ');
  const inline=inlineListDescriptions(cleaned).replace(/(^|\n)(\s*-\s+[^()\n:]+):\s+\(/g,'$1$2 (');
  return inline.replace(/(^|\n)(\s*-\s+)([^()\n:]{1,120}):\s+([A-Za-zА-Яа-яЁё][^\n]*)(?=\n|$)/g,(full,prefix,bullet,title,detail)=>{
    let description=String(detail||'').trim().replace(/[.!?;:]+$/u,'');
    if(!String(title||'').trim()||!description)return full;
    description=lowerFirstUserText(description);
    return prefix+bullet+String(title).trim()+' ('+description+').';
  });
}
function addText(el,text,cls=''){const div=document.createElement('div');if(cls)div.className=cls;div.textContent=cleanUserFacingText(text);el.appendChild(div);return div;}
function formatNumber(n){if(typeof n!=='number')return String(n??'');if(Math.abs(n)>=1000000)return (n/1000000).toLocaleString('ru-RU',{maximumFractionDigits:2})+' млн';return n.toLocaleString('ru-RU');}
function valueText(v,unit='',labels={}){
  if(v===null||typeof v==='undefined')return '—';
  if(typeof v==='number'){
    if(unit==='RUB')return (v/1000000).toLocaleString('ru-RU',{maximumFractionDigits:2})+' млн руб.';
    if(unit==='percent')return v.toLocaleString('ru-RU')+'%';
    if(unit==='months')return v.toLocaleString('ru-RU')+' мес.';
    if(unit==='days')return v.toLocaleString('ru-RU')+' дн.';
    return formatNumber(v);
  }
  if(typeof v==='boolean')return v?'Да':'Нет';
  if(typeof v==='string')return Object.prototype.hasOwnProperty.call(labels||{},v)?String(labels[v]):v;
  if(Array.isArray(v))return v.map(x=>valueText(x,unit,labels)).join('; ');
  if(typeof v==='object')return Object.entries(v).map(([k,x])=>'Параметр: '+valueText(x,unit,labels)).join('; ');
  return String(v);
}
function treeKeyLabel(key){
  const itemLabels=cfg.itemLabels&&typeof cfg.itemLabels==='object'?cfg.itemLabels:{};
  if(Object.prototype.hasOwnProperty.call(itemLabels,key)&&String(itemLabels[key]||'')!=='')return String(itemLabels[key]);
  const map={price:'Цена',prepayment:'Предоплата',service_months:'Сервис, месяцев',delivery_days:'Поставка, дней',min:'не ниже',max:'не выше',target:'цель',description:'Описание',currency:'Валюта',opening:'Исходные условия',buyer_opening:'Позиция покупателя',opening_position:'Исходная позиция',package_requirements:'Условия пакета',require:'Требуется',require_any:'Одно из условий',if:'Если'};
  if(Object.prototype.hasOwnProperty.call(map,key))return map[key];
  const suffixes=[['_lte',' — не более'],['_gte',' — не менее'],['_lt',' — меньше'],['_gt',' — больше'],['_eq',' — равно']];
  for(const pair of suffixes){if(String(key).endsWith(pair[0]))return treeKeyLabel(String(key).slice(0,-pair[0].length))+pair[1];}
  return 'Параметр';
}
function treeScalar(value,key=''){
  const all=cfg.valueLabels&&typeof cfg.valueLabels==='object'?cfg.valueLabels:{};
  const labels=key&&all[key]&&typeof all[key]==='object'?all[key]:{};
  return valueText(value,'',labels);
}
function treeNode(value,key=''){
  if(value===null||typeof value!=='object'){
    const span=document.createElement('span');span.textContent=treeScalar(value,key);return span;
  }
  const entries=Array.isArray(value)?value.map((v,i)=>[i,v]):Object.entries(value);
  if(!entries.length){const span=document.createElement('span');span.textContent='—';return span;}
  const ul=document.createElement('ul');ul.className='ckm-neg-tree';
  entries.forEach(([childKey,child])=>{
    const li=document.createElement('li');
    const stringKey=typeof childKey==='number'||/^\d+$/.test(String(childKey))?'':String(childKey);
    if(stringKey){const strong=document.createElement('strong');strong.textContent=treeKeyLabel(stringKey)+':';li.appendChild(strong);li.appendChild(document.createTextNode(' '));}
    li.appendChild(treeNode(child,stringKey||key));ul.appendChild(li);
  });
  return ul;
}
function renderTree(el,value){clear(el);if(!el)return;el.appendChild(treeNode(value));}
function renderMessages(messages,opponentName){
  const box=$('ckm-neg-messages');clear(box);
  if(!messages||!messages.length){addText(box,'Вы начинаете переговоры. Сформулируйте первую реплику.','ckm-neg-empty');return;}
  messages.forEach(m=>{
    const wrap=document.createElement('div');wrap.className='ckm-neg-message '+(m.actor==='player'?'is-player':'is-opponent')+(m.input_type==='agreement'?' is-agreement':'');wrap.dataset.messageId=String(m.id||'');
    const who=document.createElement('strong');who.className='ckm-neg-message-speaker';who.textContent=m.actor==='player'?(m.input_type==='agreement'?'Ваш итоговый пакет':'Вы'):String(opponentName||'Оппонент');wrap.appendChild(who);
    const body=document.createElement('div');body.className='ckm-neg-message-body';body.textContent=cleanUserFacingText(m.content||'');wrap.appendChild(body);box.appendChild(wrap);
  });
  box.scrollTop=box.scrollHeight;
}
function itemLabel(item){
  let text=String(item.title||item.code||'Условие'),v=item.current_value,status=String(item.status||'');
  if(v&&typeof v==='object'&&!Array.isArray(v)){
    if(v.agreed&&Object.prototype.hasOwnProperty.call(v.agreed,'value')){
      text+=': '+valueText(v.agreed.value,item.unit||'',item.value_labels||{});
      if(status==='reopen_requested'&&v.reopen_request){
        const who=v.reopen_request.actor==='player'?'вы':'оппонент';
        text+=' · '+who+' запросил пересмотр';
        if(v.reopen_request.value!==null&&typeof v.reopen_request.value!=='undefined')text+=' → '+valueText(v.reopen_request.value,item.unit||'',item.value_labels||{});
      }
      return text;
    }
    if(v.offers&&typeof v.offers==='object'){
      const parts=[];
      if(v.offers.player&&Object.prototype.hasOwnProperty.call(v.offers.player,'value'))parts.push('Вы: '+valueText(v.offers.player.value,item.unit||'',item.value_labels||{}));
      if(v.offers.opponent&&Object.prototype.hasOwnProperty.call(v.offers.opponent,'value'))parts.push('Оппонент: '+valueText(v.offers.opponent.value,item.unit||'',item.value_labels||{}));
      if(parts.length)text+=' — '+parts.join(' / ');
      if(v.acceptance_candidate)text+=' · подтверждение условия';
      if(status==='reopened')text+=' · условие пересматривается';
      return text;
    }
  }
  if(v!==null&&typeof v!=='undefined')text+=': '+valueText(v,item.unit||'',item.value_labels||{});
  return text;
}
function renderState(s){
  const agreed=$('ckm-neg-agreed'),discussing=$('ckm-neg-discussing'),facts=$('ckm-neg-facts'),commitments=$('ckm-neg-commitments'),remaining=$('ckm-neg-remaining');
  [agreed,discussing,facts,commitments,remaining].forEach(clear);
  const items=s.items||[];
  const a=items.filter(x=>['agreed','reopen_requested'].includes(String(x.status||''))), d=items.filter(x=>!['agreed','reopen_requested','not_discussed'].includes(String(x.status||'')));
  if(a.length)a.forEach(x=>addText(agreed,itemLabel(x),'ckm-neg-state-item'));else addText(agreed,'Пока ничего.','ckm-neg-muted');
  if(d.length)d.forEach(x=>addText(discussing,itemLabel(x),'ckm-neg-state-item'));else addText(discussing,'Пока ничего.','ckm-neg-muted');
  const fs=s.discovered_facts||[];
  if(fs.length)fs.forEach(x=>addText(facts,String(x.content||x.title||'Открытый факт'),'ckm-neg-state-item'));else addText(facts,'Пока ничего нового.','ckm-neg-muted');
  const cs=s.commitments||[];
  if(cs.length){
    cs.forEach(x=>{
      const who=x.actor==='player'?'Вы':'Оппонент';
      const status=String(x.status||'active');
      const suffix=status==='broken'?' · нарушено':(status==='at_risk'?' · под вопросом':'');
      const condition=x.condition?(' · при условии: '+String(x.condition)):'';
      const deadline=x.deadline?(' · срок: '+String(x.deadline)):'';
      addText(commitments,who+': '+String(x.summary||'обязательство')+condition+deadline+suffix,'ckm-neg-state-item');
    });
  }else addText(commitments,'Пока нет зафиксированных обязательств.','ckm-neg-muted');
  const rs=s.remaining_items||[];
  if(rs.length)rs.forEach(x=>addText(remaining,String(x.title||x.code||'Условие'),'ckm-neg-state-item'));else addText(remaining,'Все обязательные условия определены.','ckm-neg-muted');
}
function coachLabel(level){return ({attention:'На что обратить внимание',direction:'Подсказать направление',example:'Пример реплики',review_last_move:'Разбор последней реплики'})[String(level||'')]||'Подсказка';}
function renderCoach(s){
  const ss=s.session||{}, panel=$('ckm-neg-coach'), menu=$('ckm-neg-coach-menu'), history=$('ckm-neg-coach-history'), counts=$('ckm-neg-coach-counts');
  if(!panel)return;
  const training=ss.mode==='training'&&ss.status==='in_progress';panel.hidden=!training;
  if(!training){if(menu)menu.hidden=true;return;}
  const can=!!s.can_use_coach;
  const hasPlayer=(s.messages||[]).some(m=>m.actor==='player');
  root.querySelectorAll('[data-coach-level]').forEach(btn=>{btn.disabled=!can||(btn.dataset.coachLevel==='review_last_move'&&!hasPlayer);});
  const c=s.coach_counts||{};if(counts){counts.textContent=Number(c.total||0)>0?('Подсказок: '+Number(c.total||0)+' · готовых реплик: '+Number(c.example||0)):'';}
  if(history){clear(history);const rows=(s.coach_messages||[]).slice(-4);rows.forEach(m=>{const card=document.createElement('div');card.className='ckm-neg-coach-message';const title=document.createElement('strong');title.textContent='ИИ-тренер · '+coachLabel(m.coach_level);card.appendChild(title);const body=document.createElement('div');body.textContent=cleanUserFacingText(m.content||'');card.appendChild(body);history.appendChild(card);});}
}
function renderPackage(el,items,empty='Нет данных.'){
  clear(el);const rows=Array.isArray(items)?items:[];
  if(!rows.length){addText(el,empty,'ckm-neg-muted');return;}
  rows.forEach(item=>{const row=document.createElement('div');row.className='ckm-neg-package-row';const label=document.createElement('strong');label.textContent=String(item.title||item.code||'Условие');const val=document.createElement('span');val.textContent=valueText(item.value,item.unit||'',item.value_labels||{});row.append(label,val);el.appendChild(row);});
}
function resultTypeLabel(type){return ({agreement_strong:'Сильное соглашение',agreement_acceptable:'Приемлемый компромисс',agreement_weak:'Слабое соглашение',rational_walkaway:'Рациональный отказ от сделки',unavoidable_no_deal:'Соглашение объективно не найдено',premature_walkaway:'Переговоры завершены преждевременно',zopa_destroyed:'Рабочее пространство сделки было потеряно',opponent_walkaway_caused:'Переговоры завершил оппонент',unclear_no_deal:'Без соглашения'})[String(type||'')]||'Итог переговоров';}
function jumpToMessage(id){const el=root.querySelector('.ckm-neg-message[data-message-id="'+String(Number(id)||0)+'"]');if(!el)return;el.scrollIntoView({behavior:'smooth',block:'center'});el.classList.add('is-evidence');window.setTimeout(()=>el.classList.remove('is-evidence'),1800);}
function humanizeEvaluationText(text,code,score){
  const original=String(text||'').trim();
  if(!/Резервная формальная оценка|Качественная ИИ-часть временно недоступна/i.test(original))return original;
  const s=Math.max(0,Math.min(100,Number(score||0))),c=String(code||'');
  if(c==='result_quality')return s>=80?'Итоговое соглашение соответствует целевым условиям и не выходит за допустимые границы.':(s>=55?'Итоговое соглашение остаётся допустимым, но часть целевых условий достигнута не полностью.':'Итоговое соглашение заметно отклоняется от целевых условий или приближается к критическим границам.');
  if(c==='interest_discovery')return s>=80?'Ключевые интересы и риски оппонента выявлены и зафиксированы в ходе переговоров.':(s>=55?'Часть важных интересов оппонента удалось выявить, но картина осталась неполной.':'Ключевые интересы и риски оппонента остались недостаточно выясненными.');
  if(c==='concession_exchange')return s>=80?'Уступки использовались управляемо и сопровождались встречным движением.':(s>=55?'Обмен уступками в целом контролировался, но не все уступки были связаны со встречными условиями.':'Уступки недостаточно связывались со встречными условиями и движением оппонента.');
  if(c==='boundary_protection')return s>=80?'Допустимые границы были сохранены и не нарушены в итоговом результате.':(s>=55?'Границы в целом сохранены, хотя в ходе переговоров возникал риск их пересечения.':'Защита допустимых границ была недостаточно устойчивой.');
  if(c==='package_solution')return s>=80?'Условия обсуждались как связанный пакет, а не как набор изолированных уступок.':(s>=55?'Пакетный подход использован частично; часть условий обсуждалась раздельно.':'Связанный пакет условий практически не использовался.');
  return s>=80?'По зафиксированным действиям критерий выполнен уверенно.':(s>=55?'Критерий выполнен частично и требует более последовательного применения.':'По этому критерию требуется заметное улучшение.');
}
function humanizeZopaSummary(text){
  let out=String(text||'').trim();
  const replacements=[
    ['Рабочее пространство для соглашения существовало.','У сторон были условия, при которых можно было договориться.'],
    ['Соглашение было возможно только при увязке нескольких условий.','Договориться было возможно, если связать несколько условий в единый пакет.'],
    ['Взаимоприемлемое пространство сделки по формальным границам и правилам не подтверждено.','По заданным ограничениям взаимоприемлемый вариант не найден.'],
    ['Рабочее пространство сделки не удалось определить однозначно.','Не удалось однозначно определить, можно ли было договориться.'],
    ['Найденные технически допустимые варианты выходили за учебные границы игрока.','Формально возможные варианты выходили за ваши допустимые границы.'],
    ['Среди взаимоприемлемых вариантов найден пакет, ценнее формализованной альтернативы при отсутствии соглашения.','Среди возможных вариантов был пакет выгоднее отказа от сделки.'],
    ['Среди взаимоприемлемых вариантов найден пакет, не хуже формализованной альтернативы при отсутствии соглашения.','Среди возможных вариантов был пакет не хуже отказа от сделки.'],
    ['Даже лучший формально проверенный взаимоприемлемый пакет уступает альтернативе при отсутствии соглашения.','Даже лучший найденный вариант был хуже отказа от сделки.'],
    ['Итоговое соглашение ценнее формализованной альтернативы при отсутствии соглашения.','Итоговое соглашение выгоднее отказа от сделки.'],
    ['Итоговое соглашение по формальной оценке не хуже альтернативы при отсутствии соглашения.','Итоговое соглашение не хуже отказа от сделки.'],
    ['Итоговое соглашение по формальной оценке уступает альтернативе при отсутствии соглашения.','Итоговое соглашение хуже отказа от сделки.'],
    ['Формализованное сравнение с альтернативой при отсутствии соглашения не завершено.','Сравнение с вариантом отказа от сделки не завершено.']
  ];
  replacements.forEach(([from,to])=>{out=out.split(from).join(to);});
  // Older saved evaluations may contain both “best possible” and final comparisons; the final agreement is enough for the participant.
  out=out.replace(/\s*Среди возможных вариантов был пакет (?:выгоднее|не хуже) отказа от сделки\.\s*(?=Итоговое соглашение)/u,' ');
  return out.trim();
}
function renderFindingList(el,rows,emptyText='Нет отдельных пунктов.',scoreByCode=null){clear(el);const list=Array.isArray(rows)?rows:[];if(!list.length){addText(el,emptyText,'ckm-neg-muted');return;}list.forEach(row=>{const card=document.createElement('div');card.className='ckm-neg-finding';const text=document.createElement('div');const c=scoreByCode&&scoreByCode[String(row.criterion_code||'')]||null;text.textContent=humanizeEvaluationText(row.text,row.criterion_code,c&&c.raw_score);card.appendChild(text);const ids=Array.isArray(row.evidence_message_ids)?row.evidence_message_ids:[];if(ids.length){const b=document.createElement('button');b.type='button';b.className='ckm-neg-evidence-btn';b.textContent='Показать в диалоге';b.addEventListener('click',()=>jumpToMessage(ids[0]));card.appendChild(b);}el.appendChild(card);});}
function renderEvaluation(r){
  const loading=$('ckm-neg-result-loading'),ready=$('ckm-neg-result-ready'),failed=$('ckm-neg-result-failed');if(!loading||!ready||!failed)return;
  loading.hidden=true;failed.hidden=true;ready.hidden=false;$('ckm-neg-score').textContent=String(r.display_score??Math.round(Number(r.final_score||0)))+' / 100';$('ckm-neg-result-type').textContent=resultTypeLabel(r.result_type);
  $('ckm-neg-redline-result').textContent='Красная линия: '+(r.red_line_breached?'нарушена':'сохранена');$('ckm-neg-redline-result').classList.toggle('is-breached',!!r.red_line_breached);
  const criteria=$('ckm-neg-criteria'),scoreByCode={};clear(criteria);(r.criteria||[]).forEach(c=>{scoreByCode[String(c.code||'')]=c;const row=document.createElement('div');row.className='ckm-neg-criterion';const head=document.createElement('div');head.className='ckm-neg-criterion-head';const name=document.createElement('strong');name.textContent=String(c.title||c.code||'Критерий');const score=document.createElement('span');score.textContent=(Number(c.weighted_score||0).toFixed(1).replace('.0',''))+' / '+Number(c.weight||0).toFixed(0);head.append(name,score);row.appendChild(head);if(c.explanation){const why=document.createElement('div');why.className='ckm-neg-criterion-note';why.textContent=cleanUserFacingText(humanizeEvaluationText(c.explanation,c.code,c.raw_score));row.appendChild(why);}const ids=c.evidence&&Array.isArray(c.evidence.message_ids)?c.evidence.message_ids:[];if(ids.length){const b=document.createElement('button');b.type='button';b.className='ckm-neg-evidence-btn';b.textContent='Показать момент';b.addEventListener('click',()=>jumpToMessage(ids[0]));row.appendChild(b);}criteria.appendChild(row);});
  const summary=r.summary||{};renderFindingList($('ckm-neg-strengths'),summary.strengths||[],'Сильные стороны по заданным критериям пока не подтверждены.',scoreByCode);renderFindingList($('ckm-neg-improvements'),summary.improvements||[],'Зоны развития по заданным критериям не выявлены.',scoreByCode);
  const zopa=summary.zopa||{},zopaBox=$('ckm-neg-zopa-result'),zopaSummary=$('ckm-neg-zopa-summary'),zopaDetails=$('ckm-neg-zopa-details');
  if(zopaBox){const show=!!String(zopa.summary||'');zopaBox.hidden=!show;if(show){if(zopaSummary)zopaSummary.textContent=cleanUserFacingText(humanizeZopaSummary(zopa.summary));if(zopaDetails){clear(zopaDetails);const labels={open:'Договориться было возможно',conditional:'Нужно было связать несколько условий',closed:'Взаимоприемлемый вариант не найден'};addText(zopaDetails,labels[String(zopa.feasibility||'')]||'Статус не определён','ckm-neg-zopa-chip');const paths=Array.isArray(zopa.compensation_paths)?zopa.compensation_paths:[];paths.slice(0,3).forEach(x=>addText(zopaDetails,String(x.description||''),'ckm-neg-state-item'));}}}
  const dynamics=Array.isArray(summary.relationship_dynamics)?summary.relationship_dynamics:[],relBox=$('ckm-neg-relationship-result');if(relBox){relBox.hidden=!dynamics.length;if(dynamics.length)renderFindingList($('ckm-neg-relationship-dynamics'),dynamics);}const cc=summary.coach_counts||{};$('ckm-neg-independence').textContent=snapshot&&snapshot.session?.mode==='exam'?'Переговоры пройдены без помощи тренера.':'Подсказок тренера: '+Number(cc.total||0)+' · готовых реплик: '+Number(cc.example||0);
}
function renderEvaluationState(status){const loading=$('ckm-neg-result-loading'),ready=$('ckm-neg-result-ready'),failed=$('ckm-neg-result-failed');if(!loading||!ready||!failed)return;ready.hidden=true;failed.hidden=status!=='failed';loading.hidden=status==='failed';loading.textContent=status==='processing'?'Готовим итоговый разбор…':'Итоговый разбор ожидает расчёта…';}
function renderEvaluationHidden(message){const loading=$('ckm-neg-result-loading'),ready=$('ckm-neg-result-ready'),failed=$('ckm-neg-result-failed');if(!loading||!ready||!failed)return;ready.hidden=true;failed.hidden=true;loading.hidden=false;loading.textContent=String(message||'Итоговый разбор пока недоступен участнику.');}
async function loadEvaluation(force=false){
  if(!sessionId||!snapshot||!String(snapshot.session?.status||'').startsWith('completed_')||evaluationBusy)return;
  evaluationBusy=true;
  try{
    const current=await api('/results/sessions/'+sessionId,{method:'GET'});let r=current.result||{};
    if(r.ready){renderEvaluation(r);return;}
    if(r.status==='failed'&&!force){renderEvaluationState('failed');return;}
    if(r.hidden)renderEvaluationHidden(r.message);else renderEvaluationState(r.status||'pending');
    if(!force&&evaluationAttemptedSession===sessionId)return;
    evaluationAttemptedSession=sessionId;
    for(let step=0;step<10;step++){
      const calc=await api('/results/sessions/'+sessionId+'/evaluate',{method:'POST',body:'{}'});
      r=calc.result||{};
      if(r.hidden)renderEvaluationHidden(r.message);
      else if(r.ready){renderEvaluation(r);return;}
      else if(r.status==='failed'){renderEvaluationState('failed');return;}
      else renderEvaluationState(r.status||'processing');
      if(!['processing','pending'].includes(String(r.status||'')))return;
      await new Promise(resolve=>window.setTimeout(resolve,180));
    }
    renderEvaluationState('processing');
  }catch(e){renderEvaluationState('failed');}
  finally{evaluationBusy=false;}
}
function renderCompletion(s){
  const ss=s.session||{}, completed=String(ss.status||'').startsWith('completed_'), panel=$('ckm-neg-completed');if(!panel)return;
  panel.hidden=!completed;if(!completed)return;
  const title=$('ckm-neg-completed-title'),pkg=$('ckm-neg-completed-package'),note=$('ckm-neg-completed-note');
  if(ss.status==='completed_agreement'){
    title.textContent='Соглашение достигнуто';renderPackage(pkg,(s.final_agreement&&s.final_agreement.package)||[],'Итоговый пакет недоступен.');
    note.textContent=s.completion&&s.completion.red_line_breached?'Соглашение зафиксировано. Красная линия игрока была нарушена.':'Соглашение зафиксировано.';
  }else{
    title.textContent='Переговоры завершены без соглашения';clear(pkg);addText(pkg,'Финального соглашения нет. Частично согласованные условия сохранены в истории.','ckm-neg-muted');note.textContent='';
  }
  renderEvaluationState('pending');
}
function closeModals(){$('ckm-neg-agreement-modal').hidden=true;$('ckm-neg-no-deal-modal').hidden=true;}
function renderSnapshot(s){
  snapshot=s;sessionId=Number(s.session&&s.session.id||0);
  $('ckm-neg-prestart').hidden=true;$('ckm-neg-game').hidden=false;
  const sc=s.scenario||{}, ss=s.session||{};
  $('ckm-neg-game-title').textContent=sc.title||'Переговоры';$('ckm-neg-role').textContent=sc.player_role||'';
  renderTree($('ckm-neg-target'),sc.player_target_result);renderTree($('ckm-neg-redlines'),sc.player_red_lines);
  $('ckm-neg-mode-label').textContent=ss.mode==='exam'?'Экзамен':'Тренировка';
  if($('ckm-neg-difficulty-label'))$('ckm-neg-difficulty-label').textContent=String(ss.difficulty_label||({soft:'Мягкий',medium:'Средний',hard:'Жёсткий',expert:'Эксперт'}[ss.difficulty]||'Средний'));
  $('ckm-neg-opponent-name').textContent=(sc.opponent&&sc.opponent.name)||'Оппонент';$('ckm-neg-opponent-role').textContent=(sc.opponent&&sc.opponent.role)||'';
  renderMessages(s.messages||[],(sc.opponent&&sc.opponent.name)||'Оппонент');renderState(s);renderCoach(s);renderCompletion(s);
  const can=!!s.can_send,retry=!!s.can_retry_opponent,completed=String(ss.status||'').startsWith('completed_');
  $('ckm-neg-input').disabled=!can;$('ckm-neg-send').disabled=!can;$('ckm-neg-pause').disabled=completed;
  const retryBtn=$('ckm-neg-retry-opponent');if(retryBtn){retryBtn.hidden=!retry;retryBtn.disabled=!retry;}
  const retryAgreementBtn=$('ckm-neg-retry-agreement');if(retryAgreementBtn){retryAgreementBtn.hidden=!s.can_retry_agreement||completed;retryAgreementBtn.disabled=!s.can_retry_agreement;}
  const agreementBtn=$('ckm-neg-agreement');if(agreementBtn){agreementBtn.disabled=!s.can_create_agreement;agreementBtn.hidden=completed;}
  const noDealBtn=$('ckm-neg-no-deal');if(noDealBtn){noDealBtn.disabled=!s.can_finish_without_agreement;noDealBtn.hidden=completed;}
  if(completed){setStatus('Переговоры завершены. Сессия доступна только для чтения.');window.setTimeout(()=>loadEvaluation(false),0);}
  else if(s.assignment_blocked_message)setStatus(String(s.assignment_blocked_message),true);
  else if(ss.status==='paused')setStatus('Сессия приостановлена.');
  else if(['player_message_saved','player_analysis_pending','player_analyzed'].includes(ss.processing_status))setStatus('Анализируем ваш ход…');
  else if(['opponent_generation_pending','opponent_generating'].includes(ss.processing_status))setStatus(((sc.opponent&&sc.opponent.name)||'Оппонент')+' отвечает…');
  else if(['opponent_saved','opponent_analysis_pending','opponent_analyzed'].includes(ss.processing_status))setStatus('Анализируем ход переговоров…');
  else if(ss.processing_status==='agreement_processing')setStatus('Оппонент рассматривает итоговый пакет…');
  else if(ss.processing_status==='opponent_failed')setStatus('Не удалось получить ответ оппонента. Можно повторить попытку.',true);
  else setStatus('');
}
function showPrestart(active=true){$('ckm-neg-game').hidden=true;$('ckm-neg-completed').hidden=true;$('ckm-neg-prestart').hidden=false;$('ckm-neg-active').hidden=!active;$('ckm-neg-start-actions').hidden=active;closeModals();}
async function start(restart=false){
  setStatus(restart?'Создаём новую попытку…':'Создаём сессию…',false,true);const mode=(root.querySelector('input[name="ckm-neg-mode"]:checked')||{}).value||'training';const difficulty=(root.querySelector('input[name="ckm-neg-difficulty"]:checked')||{}).value||'medium';const voice=!!($('ckm-neg-voice')&&$('ckm-neg-voice').checked);
  try{const d=await api('/sessions/start',{method:'POST',body:JSON.stringify({scenario_id:Number(cfg.scenarioId||0),mode,difficulty,voice_enabled:voice,restart})});sessionId=Number(d.session_id||0);pendingClientId='';pendingText='';setStatus('',false,true);renderSnapshot(d.snapshot);}
  catch(e){if(e.status===409&&e.data&&e.data.code==='ACTIVE_SESSION_EXISTS'){sessionId=Number(e.data.session_id||0);cfg.activeSessionId=sessionId;showPrestart(true);setStatus('У вас уже есть незавершённые переговоры.',false,true);return;}setStatus(e.message||'Не удалось начать переговоры.',true,true);}
}
async function resume(){if(!sessionId)return;setStatus('Восстанавливаем сессию…',false,true);try{const d=await api('/sessions/'+sessionId+'/resume',{method:'GET'});setStatus('',false,true);renderSnapshot(d.snapshot);}catch(e){setStatus(e.message||'Не удалось продолжить сессию.',true,true);}}
async function pause(){if(!sessionId)return;setStatus('Сохраняем…');try{await api('/sessions/'+sessionId+'/pause',{method:'POST',body:'{}'});cfg.activeSessionId=sessionId;if(cfg.catalogUrl){window.location.href=String(cfg.catalogUrl);return;}showPrestart(true);setStatus('Сессия сохранена. Можно продолжить позже.',false,true);}catch(e){setStatus(e.message||'Не удалось сохранить сессию.',true);}}
async function send(){
  if(!sessionId)return;const input=$('ckm-neg-input'),text=String(input.value||'').trim();if(!text){setStatus('Введите реплику.',true);return;}
  if(!pendingClientId||pendingText!==text){pendingClientId=uuid();pendingText=text;}$('ckm-neg-send').disabled=true;input.disabled=true;setStatus('Оппонент готовит ответ…');
  try{const d=await api('/sessions/'+sessionId+'/messages',{method:'POST',body:JSON.stringify({client_message_id:pendingClientId,content:text,input_type:'text'})});input.value='';pendingClientId='';pendingText='';renderSnapshot(d.snapshot);if(d.player_walkaway_candidate){setStatus('Вы обозначили завершение переговоров. Подтвердите решение.');await openNoDeal();}else if(d.opponent_failed)setStatus(d.message_to_user||'Не удалось получить ответ оппонента. Можно повторить попытку.',true);else if(d.opponent_pending)setStatus('Ответ оппонента уже формируется…');else if(d.arbiter_failed)setStatus('Ответ получен. Структурированный анализ хода временно не обновлён.',true);}
  catch(e){setStatus((e.message||'Не удалось отправить реплику.')+' Повторная отправка использует тот же идентификатор.',true);$('ckm-neg-send').disabled=false;input.disabled=false;}
}
async function retryOpponent(){if(!sessionId||!snapshot||!snapshot.can_retry_opponent)return;const messageId=Number(snapshot.retry_opponent_message_id||0);if(!messageId)return;const btn=$('ckm-neg-retry-opponent');if(btn)btn.disabled=true;setStatus('Повторяем ответ оппонента…');try{const d=await api('/sessions/'+sessionId+'/retry-opponent',{method:'POST',body:JSON.stringify({player_message_id:messageId})});renderSnapshot(d.snapshot);}catch(e){setStatus(e.message||'Не удалось повторить ответ оппонента.',true);if(btn)btn.disabled=false;}}
async function retryAgreement(){if(!sessionId||!snapshot||!snapshot.can_retry_agreement||!snapshot.retry_agreement)return;const a=snapshot.retry_agreement,btn=$('ckm-neg-retry-agreement');if(btn)btn.disabled=true;setStatus('Восстанавливаем фиксацию соглашения…');try{const d=await api('/sessions/'+sessionId+'/agreements/propose',{method:'POST',body:JSON.stringify({agreement_id:Number(a.id||0),expected_state_revision:Number(a.state_revision||0)})});renderSnapshot(d.snapshot);if(d.decision==='partial')setStatus('Оппонент принял не весь пакет. Переговоры продолжаются.');else if(d.decision==='reject')setStatus('Итоговый пакет отклонён. Можно продолжить переговоры.');else if(d.decision==='blocked')setStatus('Пакет не может быть зафиксирован из-за формального ограничения.',true);}catch(e){setStatus(e.message||'Не удалось восстановить фиксацию соглашения.',true);if(btn)btn.disabled=false;}}
async function requestCoach(level){if(!sessionId||!snapshot||snapshot.session?.mode!=='training')return;const requestId='coach-'+uuid();root.querySelectorAll('[data-coach-level]').forEach(btn=>{btn.disabled=true;});setStatus('ИИ-тренер анализирует ситуацию…');try{const d=await api('/sessions/'+sessionId+'/coach',{method:'POST',body:JSON.stringify({help_level:String(level||''),client_request_id:requestId})});renderSnapshot(d.snapshot);const menu=$('ckm-neg-coach-menu');if(menu)menu.hidden=true;}catch(e){setStatus(e.message||'Не удалось получить подсказку тренера.',true);if(snapshot)renderCoach(snapshot);}}
async function draftAgreement(){
  if(!sessionId||!snapshot||!snapshot.can_create_agreement)return;setStatus('Формируем итоговый пакет…');
  try{const d=await api('/sessions/'+sessionId+'/agreements/draft',{method:'POST',body:JSON.stringify({expected_state_revision:Number(snapshot.session?.state_revision||0)})});currentAgreement=d.agreement;renderPackage($('ckm-neg-agreement-package'),currentAgreement.package||[],'Нет условий для фиксации.');const v=d.validation||{},warning=$('ckm-neg-agreement-warning');clear(warning);if((v.missing||[]).length)addText(warning,'Осталось согласовать: '+v.missing.map(x=>x.title||x.code).join(', ')+'.','is-error');(v.blocking||[]).forEach(x=>addText(warning,String(x.message||'Пакет не проходит формальную проверку.'),'is-error'));if(v.red_line_breached)addText(warning,'Внимание: пакет пересекает вашу красную линию. Это не запрещает учебную сделку, но будет зафиксировано.','');$('ckm-neg-agreement-propose').disabled=!v.can_propose;$('ckm-neg-agreement-modal').hidden=false;setStatus('');}
  catch(e){if(e.status===409&&e.data?.code==='STATE_CONFLICT')await resume();setStatus(e.message||'Не удалось сформировать соглашение.',true);}
}
async function proposeAgreement(){
  if(!currentAgreement||!sessionId)return;const btn=$('ckm-neg-agreement-propose');btn.disabled=true;setStatus('Оппонент рассматривает итоговый пакет…');
  try{const d=await api('/sessions/'+sessionId+'/agreements/propose',{method:'POST',body:JSON.stringify({agreement_id:Number(currentAgreement.id),expected_state_revision:Number(currentAgreement.state_revision)})});$('ckm-neg-agreement-modal').hidden=true;currentAgreement=null;renderSnapshot(d.snapshot);if(d.decision==='partial')setStatus('Оппонент принял не весь пакет. Переговоры продолжаются.');else if(d.decision==='reject')setStatus('Итоговый пакет отклонён. Можно продолжить переговоры.');else if(d.decision==='blocked')setStatus('Пакет не может быть зафиксирован из-за формального ограничения.',true);}
  catch(e){btn.disabled=false;if(e.status===409&&e.data?.code==='STATE_CONFLICT')$('ckm-neg-agreement-modal').hidden=true;setStatus(e.message||'Не удалось предложить итоговое соглашение.',true);}
}
async function openNoDeal(){
  if(!sessionId||!snapshot||!snapshot.can_finish_without_agreement)return;setStatus('Готовим сводку завершения…');
  try{const d=await api('/sessions/'+sessionId+'/finish-without-agreement/preview',{method:'POST',body:'{}'});noDealToken=String(d.confirmation_token||'');const box=$('ckm-neg-no-deal-summary');clear(box);addText(box,'Согласовано','ckm-neg-summary-title');const agreedWrap=document.createElement('div');box.appendChild(agreedWrap);renderPackage(agreedWrap,d.agreed_items||[],'Пока ничего окончательно не согласовано.');addText(box,'Не согласовано','ckm-neg-summary-title');const unresolvedWrap=document.createElement('div');unresolvedWrap.className='ckm-neg-state-list';box.appendChild(unresolvedWrap);(d.unresolved_items||[]).forEach(x=>addText(unresolvedWrap,String(x.title||x.code),'ckm-neg-state-item'));if(!(d.unresolved_items||[]).length)addText(unresolvedWrap,'Нет обязательных нерешённых условий.','ckm-neg-muted');$('ckm-neg-no-deal-comment').value='';$('ckm-neg-no-deal-modal').hidden=false;setStatus('');}
  catch(e){setStatus(e.message||'Не удалось подготовить завершение без соглашения.',true);}
}
async function confirmNoDeal(){
  if(!sessionId||!noDealToken)return;const btn=$('ckm-neg-no-deal-confirm');btn.disabled=true;setStatus('Завершаем переговоры…');
  try{const d=await api('/sessions/'+sessionId+'/finish-without-agreement/confirm',{method:'POST',body:JSON.stringify({confirmation_token:noDealToken,comment:String($('ckm-neg-no-deal-comment').value||'')})});noDealToken='';$('ckm-neg-no-deal-modal').hidden=true;renderSnapshot(d.snapshot);}
  catch(e){btn.disabled=false;if(e.status===409&&e.data?.code==='STATE_CONFLICT')$('ckm-neg-no-deal-modal').hidden=true;setStatus(e.message||'Не удалось завершить переговоры.',true);}
}
async function retryEvaluation(){if(evaluationBusy)return;evaluationAttemptedSession=0;await loadEvaluation(true);}
async function replay(){closeModals();currentAgreement=null;noDealToken='';evaluationAttemptedSession=0;await start(false);}

function toggleMobilePanel(kind){
  const task=root.querySelector('.ckm-neg-task'),state=root.querySelector('.ckm-neg-state');
  if(kind==='task'&&task){const open=!task.classList.contains('is-mobile-open');task.classList.toggle('is-mobile-open',open);if(state)state.classList.remove('is-mobile-open');}
  if(kind==='state'&&state){const open=!state.classList.contains('is-mobile-open');state.classList.toggle('is-mobile-open',open);if(task)task.classList.remove('is-mobile-open');}
}

$('ckm-neg-start')?.addEventListener('click',()=>start(false));
$('ckm-neg-continue')?.addEventListener('click',resume);
$('ckm-neg-restart')?.addEventListener('click',()=>{if(window.confirm('Текущая попытка будет сохранена как незаконченная. Начать новую?'))start(true);});
$('ckm-neg-pause')?.addEventListener('click',pause);
$('ckm-neg-send')?.addEventListener('click',send);
$('ckm-neg-retry-opponent')?.addEventListener('click',retryOpponent);
$('ckm-neg-retry-agreement')?.addEventListener('click',retryAgreement);
$('ckm-neg-coach-toggle')?.addEventListener('click',()=>{const menu=$('ckm-neg-coach-menu');if(menu)menu.hidden=!menu.hidden;});
root.querySelectorAll('[data-coach-level]').forEach(btn=>btn.addEventListener('click',()=>requestCoach(btn.dataset.coachLevel||'')));
$('ckm-neg-agreement')?.addEventListener('click',draftAgreement);
$('ckm-neg-agreement-cancel')?.addEventListener('click',()=>{$('ckm-neg-agreement-modal').hidden=true;currentAgreement=null;});
$('ckm-neg-agreement-propose')?.addEventListener('click',proposeAgreement);
$('ckm-neg-no-deal')?.addEventListener('click',openNoDeal);
$('ckm-neg-no-deal-cancel')?.addEventListener('click',()=>{$('ckm-neg-no-deal-modal').hidden=true;noDealToken='';});
$('ckm-neg-no-deal-confirm')?.addEventListener('click',confirmNoDeal);
$('ckm-neg-replay')?.addEventListener('click',replay);
$('ckm-neg-result-retry')?.addEventListener('click',retryEvaluation);
$('ckm-neg-mobile-task')?.addEventListener('click',()=>toggleMobilePanel('task'));
$('ckm-neg-mobile-state')?.addEventListener('click',()=>toggleMobilePanel('state'));
$('ckm-neg-input')?.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();send();}});
if(sessionId){if(cfg.autoResume)resume();else showPrestart(true);}
})();
