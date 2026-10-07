import WebSocket from 'ws';

const CKM_BASE = String(process.env.CKM_BASE_URL || '').replace(/\/$/, '');
const CKM_SECRET = String(process.env.CKM_SIDEBAND_SECRET || '');
const OPENAI_API_KEY = String(process.env.OPENAI_API_KEY || '');
const OPENAI_PROJECT = String(process.env.OPENAI_PROJECT_ID || '');
const POLL_MS = Math.max(1000, Number(process.env.CKM_POLL_MS || 2000));

if (!CKM_BASE || !CKM_SECRET || !OPENAI_API_KEY) {
  throw new Error('Set CKM_BASE_URL, CKM_SIDEBAND_SECRET and OPENAI_API_KEY.');
}

const api = (path) => `${CKM_BASE}/wp-json/ckm/v1/sales/live-sip/sideband/${path}`;
const active = new Map();
const allowed = new Set([
  'session.started', 'session.input_transcript.delta', 'session.output_transcript.delta',
  'session.usage.updated', 'transport.ringing', 'transport.answered', 'transport.failed',
  'transport.dtmf.received', 'session.closed', 'error'
]);

async function post(path, body = {}) {
  const r = await fetch(api(path), {
    method: 'POST',
    headers: {'content-type':'application/json','x-ckm-sideband-secret':CKM_SECRET},
    body: JSON.stringify(body),
  });
  const text = await r.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch { data = {raw:text}; }
  if (!r.ok || data.ok === false) throw new Error(data.message || `CKM ${path} HTTP ${r.status}`);
  return data;
}

async function forward(sessionId, event) {
  if (!event || !allowed.has(String(event.type || ''))) return;
  await post('event', {session_id: sessionId, event});
}

function attach(call) {
  const sessionId = String(call.session_id || '');
  if (!sessionId || active.has(sessionId)) return;
  const url = `wss://api.openai.com/v1/live/sessions/${encodeURIComponent(sessionId)}/attach`;
  const headers = {Authorization:`Bearer ${OPENAI_API_KEY}`};
  if (OPENAI_PROJECT) headers['OpenAI-Project'] = OPENAI_PROJECT;
  const ws = new WebSocket(url, {headers});
  const state = {ws, timer:null};
  active.set(sessionId, state);

  ws.on('open', async () => {
    try { await post('heartbeat', {session_id:sessionId}); } catch (e) { console.error('heartbeat', sessionId, e.message); }
    state.timer = setInterval(() => post('heartbeat', {session_id:sessionId}).catch(e => console.error('heartbeat', sessionId, e.message)), 15000);
  });
  ws.on('message', async (buf) => {
    let event;
    try { event = JSON.parse(buf.toString()); } catch { return; }
    try { await forward(sessionId, event); } catch (e) { console.error('forward', sessionId, e.message); }
    if (event?.type === 'session.closed') ws.close(1000, 'session closed');
  });
  ws.on('error', async (err) => {
    console.error('sideband', sessionId, err.message);
    try { await forward(sessionId, {type:'error', message:`Sideband worker: ${err.message}`}); } catch {}
  });
  ws.on('close', () => {
    if (state.timer) clearInterval(state.timer);
    active.delete(sessionId);
  });
}

async function poll() {
  try {
    await post('heartbeat', {});
    const data = await post('claim', {limit:10});
    for (const call of (data.calls || [])) attach(call);
  } catch (e) {
    console.error('poll', e.message);
  }
}

console.log('CKM Live sideband worker started');
await poll();
setInterval(poll, POLL_MS);
