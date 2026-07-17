<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Conversations');
sales_header($me, 'Live Conversations Monitor');
sales_nav('conversations');
?>
<main class="p-2 space-y-2">
  <div id="convKpis" class="kstrip"></div>
  <div class="card p-2 flex flex-wrap items-center gap-3">
    <label class="flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" id="autoAssign" class="chk" onchange="saveAuto()"> <b>Auto-assign</b> new chats (round-robin to the next free operator)</label>
    <button onclick="distribute()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-share-nodes"></i> Distribute waiting now</button>
    <span class="flex-1"></span>
    <label class="flex items-center gap-1.5 text-[11px] text-gray-500"><input type="checkbox" id="notifyOn" class="chk" onchange="toggleNotify()"> 🔔 Alert me on new free chats</label>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-comments text-indigo-500"></i> Live Chats</div>
      <div id="liveChats" class="divide-y max-h-[70vh] overflow-y-auto"></div>
    </div>
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-phone text-green-500"></i> Calls (24h)</div>
      <table class="dense"><tbody id="calls"></tbody></table>
    </div>
    <div class="card flex flex-col" style="max-height:70vh">
      <div class="p-2 border-b flex items-center justify-between text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <span><i class="fa-brands fa-whatsapp text-green-600"></i> WhatsApp</span>
        <button id="waBackBtn" onclick="waBackToList()" class="hidden text-indigo-500 normal-case font-normal text-[11px]"><i class="fa-solid fa-arrow-left"></i> Back</button>
      </div>
      <div id="waList" class="overflow-y-auto flex-1"></div>
      <div id="waThreadWrap" class="hidden flex-col flex-1 overflow-hidden">
        <div id="waThread" class="flex-1 overflow-y-auto p-2 space-y-1.5 bg-gray-50 text-[11px]"></div>
        <div id="waWindowClosed" class="hidden border-t p-1.5 bg-amber-50 text-amber-700 text-[10px] flex items-center justify-between gap-2">
          <span><i class="fa-solid fa-clock"></i> 24h window closed</span>
          <button onclick="waSendTemplate()" class="bg-amber-600 text-white px-2 py-0.5 rounded text-[10px] whitespace-nowrap">Send template</button>
        </div>
        <div id="waComposer" class="hidden border-t p-1.5 flex gap-1 items-center">
          <input id="waMsgIn" class="flex-1 border rounded-full px-2 py-1 text-[11px]" placeholder="Reply…" onkeydown="if(event.key==='Enter')waSend()">
          <select id="waAssignSel" onchange="waAssign(this.value)" class="text-[10px] border rounded px-1 py-1"><option value="">Assign…</option></select>
          <button onclick="waSend()" class="w-7 h-7 rounded-full bg-indigo-600 text-white text-[10px] flex items-center justify-center shrink-0"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
      </div>
    </div>
  </div>
  <p class="text-[10px] text-gray-400">Live — refreshes every 4s.</p>
</main>

<!-- modal -->
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const mmss = s => { s=+s||0; const m=Math.floor(s/60), x=s%60; return m+':'+String(x).padStart(2,'0'); };
let CALLS = [];
async function load(){
  const { data:d } = await apiGet('conversations.php');
  const c = d.counts;
  document.getElementById('convKpis').innerHTML = [
    ['Chats active', c.chats_active, c.chats_active>0],['Chats waiting', c.chats_waiting, c.chats_waiting>0],
    ['Chats missed', c.chats_missed, c.chats_missed>0],
    ['Calls (24h)', c.calls_today],['WhatsApp (24h)', c.wa_today],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  document.getElementById('liveChats').innerHTML = d.live_chats.map(s=>{
    const free = s.status==='waiting' || s.status==='missed' || !s.agent_id;
    const attend = s.status==='missed'
      ? '<span class="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5"><i class="fa-solid fa-clock text-[8px]"></i> Missed</span>'
      : free
      ? '<span class="text-[10px] font-semibold text-red-600 bg-red-50 border border-red-200 rounded px-1.5 py-0.5 animate-pulse"><i class="fa-solid fa-circle text-[7px]"></i> Currently free</span>'
      : `<span class="text-[10px] font-semibold text-green-700 bg-green-50 border border-green-200 rounded px-1.5 py-0.5"><i class="fa-solid fa-headset text-[8px]"></i> ${esc(s.agent)}</span>`;
    const opts = (AGENTS||[]).map(a=>`<option value="${a.id}" ${a.id==s.agent_id?'selected':''}>${esc(a.full_name)}</option>`).join('');
    return `<div class="p-2 ${s.status==='missed'?'bg-amber-50/40':free?'bg-red-50/40':''}">
      <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold truncate">${esc(s.visitor_name||'Visitor')}</span>
        ${attend}
      </div>
      <div class="text-[11px] text-gray-500 truncate">${esc(s.last_msg||'')}</div>
      <div class="flex items-center justify-between mt-1">
        <span class="text-[10px] text-gray-400">${esc(s.visitor_phone||'')} · ${mmss(s.age_sec)} elapsed</span>
        <select onchange="assignChat(${s.id}, this.value)" class="text-[10px] border rounded px-1 py-0.5 text-gray-600">
          <option value="">${free?'Assign to…':'Reassign to…'}</option>${opts}
        </select>
      </div>
    </div>`;
  }).join('') || '<div class="p-3 text-center text-gray-400 text-xs">No live chats</div>';

  CALLS = d.calls;
  document.getElementById('calls').innerHTML = d.calls.map(cl=>{
    // Transcribing NEW recordings is disabled (voice transcription org-wide) — only the
    // "view" button for calls already transcribed before that decision remains.
    let tx = '';
    if (cl.transcript_status === 'done') tx = `<button onclick="viewTranscript(${cl.id})" title="View transcript" class="text-indigo-500 hover:text-indigo-700 ml-1.5"><i class="fa-solid fa-file-lines"></i></button>`;
    return `
    <tr><td class="text-[11px]">${esc(cl.lead_name||cl.to_number)}</td>
      <td class="text-gray-500 text-[10px]">${esc(cl.agent||'—')}</td>
      <td>${badge(cl.status, cl.status==='completed'?'green':cl.status==='failed'?'red':'yellow')}</td>
      <td class="text-right text-[11px]">${mmss(cl.duration_sec)}</td>
      <td class="text-right whitespace-nowrap">${cl.recording_url?`<a href="${esc(cl.recording_url)}" target="_blank" title="Play recording" class="text-indigo-500 hover:text-indigo-700"><i class="fa-solid fa-circle-play"></i></a>`:''}${tx}</td></tr>`;
  }).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-xs">No calls</td></tr>';

  notifyNewFree(d.live_chats);
  waLoad();
}

/* ---- call recording transcription (on request) ---- */
function viewTranscript(id) {
  const cl = CALLS.find(c => c.id === id);
  if (!cl) return;
  openModal(`<div class="text-xs text-gray-700 whitespace-pre-wrap leading-relaxed">${esc(cl.transcript || '')}</div>`,
    'Call transcript — ' + (cl.lead_name || cl.to_number || ''));
}
/* ---- WhatsApp live pane ---- */
let waCur = null, waLastId = 0;
async function waLoad() {
  const { data } = await apiGet('whatsapp-chat.php', { action: 'inbox' });
  waNotifyUnread(data.threads);
  const opts = (AGENTS||[]).map(a=>`<option value="${a.id}">${esc(a.full_name)}</option>`).join('');
  document.getElementById('waAssignSel').innerHTML = '<option value="">Assign…</option>' + opts;
  document.getElementById('waList').innerHTML = data.threads.map(t=>`
    <div onclick="waOpen(${t.id},'${esc(t.contact_name||t.phone)}')" class="p-2 border-b cursor-pointer hover:bg-gray-50 ${waCur===t.id?'bg-indigo-50':''}">
      <div class="flex items-center justify-between">
        <span class="text-[11px] font-semibold truncate">${esc(t.contact_name||t.phone||'Contact')}</span>
        ${t.unread_count>0?`<span class="text-[9px] font-semibold bg-red-500 text-white rounded-full px-1.5 py-0.5">${t.unread_count}</span>`
          : (t.agent?`<span class="text-[9px] text-green-700">${esc(t.agent)}</span>`:'')}
      </div>
      <div class="text-[10px] text-gray-500 truncate">${esc(t.last_msg||'')}</div>
      <div class="text-[10px] text-gray-400">${esc(t.phone||'')} · ${t.window_open?'<span class="text-green-600">open</span>':'<span class="text-amber-500">24h closed</span>'}</div>
    </div>`).join('') || '<div class="p-3 text-center text-gray-400 text-xs">No WhatsApp conversations yet</div>';
  if (waCur) waPull();
}
function waBubble(m) {
  if (m.sender === 'system') return `<div class="text-center text-amber-600 italic">${esc(m.body)}</div>`;
  const mine = m.sender === 'agent';
  const cls = mine ? 'bg-indigo-600 text-white' : (m.sender==='bot' ? 'bg-gray-100 text-gray-500' : 'bg-white border text-gray-700');
  return `<div class="flex ${mine?'justify-end':'justify-start'}"><div class="${cls} rounded-2xl px-2.5 py-1 max-w-[85%] shadow-sm">${esc(m.body)}</div></div>`;
}
async function waOpen(id, name) {
  waCur = id; waLastId = 0;
  document.getElementById('waThread').innerHTML = '';
  document.getElementById('waList').classList.add('hidden');
  document.getElementById('waThreadWrap').classList.remove('hidden');
  document.getElementById('waThreadWrap').classList.add('flex');
  document.getElementById('waBackBtn').classList.remove('hidden');
  await waPull();
}
function waBackToList() {
  waCur = null;
  document.getElementById('waList').classList.remove('hidden');
  document.getElementById('waThreadWrap').classList.add('hidden');
  document.getElementById('waThreadWrap').classList.remove('flex');
  document.getElementById('waBackBtn').classList.add('hidden');
  waLoad();
}
async function waPull() {
  if (!waCur) return;
  const { data } = await apiGet('whatsapp-chat.php', { action: 'poll', thread_id: waCur, since: waLastId });
  if (data.messages.length) {
    const t = document.getElementById('waThread');
    data.messages.forEach(m => { t.insertAdjacentHTML('beforeend', waBubble(m)); waLastId = m.id; });
    t.scrollTop = t.scrollHeight;
  }
  document.getElementById('waComposer').classList.toggle('hidden', !data.window_open);
  document.getElementById('waWindowClosed').classList.toggle('hidden', !!data.window_open);
}
async function waSend() {
  const el = document.getElementById('waMsgIn'); const b = el.value.trim(); if (!b || !waCur) return;
  el.value = '';
  const r = await apiSend('whatsapp-chat.php?action=post', { thread_id: waCur, body: b });
  if (!r.success) toast(r.error || 'Send failed', false);
  waPull();
}
async function waSendTemplate() {
  if (!waCur || !confirm('Send the configured template to reopen this conversation?')) return;
  const r = await apiSend('whatsapp-chat.php?action=template', { thread_id: waCur });
  if (r.success) { toast(r.message || 'Template sent'); waPull(); } else toast(r.error || 'Failed', false);
}
async function waAssign(agentId) {
  if (!agentId || !waCur) return;
  const r = await apiSend('whatsapp-chat.php?action=assign', { thread_id: waCur, agent_id: agentId });
  if (r.success) { toast('Conversation assigned'); waLoad(); } else toast(r.error || 'Assign failed', false);
}
let _seenWaUnread = null;
function waNotifyUnread(threads) {
  const withUnread = (threads||[]).filter(t=>t.unread_count>0);
  const ids = new Set(withUnread.map(t=>t.id));
  if (_seenWaUnread === null) { _seenWaUnread = ids; return; }
  const fresh = withUnread.filter(t=>!_seenWaUnread.has(t.id));
  _seenWaUnread = ids;
  if (fresh.length && document.getElementById('notifyOn').checked) {
    beep();
    if ('Notification' in window && Notification.permission==='granted')
      fresh.forEach(t=>{
        const notif = new Notification('New WhatsApp message', { body: (t.contact_name||t.phone||'A contact')+' sent a message' });
        notif.onclick = () => { window.focus(); window.location.href = 'sales-whatsapp.php'; notif.close(); };
      });
  }
}

let AGENTS = [];
async function loadAgents(){ const { data } = await apiGet('chat.php', { action: 'agents' }); AGENTS = data || []; }
async function assignChat(sessionId, agentId){
  if(!agentId) return;
  const r = await apiSend('chat.php?action=assign', { session_id: sessionId, agent_id: agentId });
  if(r.success){ toast(r.data.reassigned ? 'Chat reassigned (visitor not notified)' : 'Chat assigned'); load(); }
  else toast(r.error || 'Assign failed', false);
}

/* ---- auto-assign policy ---- */
async function loadSettings(){ const { data } = await apiGet('chat.php', { action: 'settings' }); document.getElementById('autoAssign').checked = data.auto_assign == 1; }
async function saveAuto(){ const r = await apiSend('chat.php?action=settings', { auto_assign: document.getElementById('autoAssign').checked ? 1 : 0 }); if(r.success) toast('Auto-assign ' + (r.data.auto_assign ? 'ON' : 'OFF')); load(); }
async function distribute(){ const r = await apiSend('chat.php?action=distribute', {}); if(r.success) toast(r.data.assigned + ' chat(s) distributed'); load(); }

/* ---- sound + desktop notification on a new free chat ---- */
let _seenFree = null;   // null until first load (so we don't alert for the backlog)
function beep(){ try{ const a=new (window.AudioContext||window.webkitAudioContext)(); const o=a.createOscillator(), g=a.createGain(); o.connect(g); g.connect(a.destination); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(.001,a.currentTime); g.gain.exponentialRampToValueAtTime(.3,a.currentTime+.02); g.gain.exponentialRampToValueAtTime(.001,a.currentTime+.4); o.start(); o.stop(a.currentTime+.42); }catch(e){} }
function toggleNotify(){ const on=document.getElementById('notifyOn').checked; localStorage.setItem('convNotify', on?'1':'0'); if(on && 'Notification' in window && Notification.permission!=='granted') Notification.requestPermission(); if(on) toast('Alerts on'); }
function notifyNewFree(chats){
  const free = (chats||[]).filter(s => s.status==='waiting' || !s.agent_id).map(s=>({id:s.id,name:s.visitor_name}));
  const ids = new Set(free.map(f=>f.id));
  if(_seenFree === null){ _seenFree = ids; return; }        // first load: seed only
  const fresh = free.filter(f => !_seenFree.has(f.id));
  _seenFree = ids;
  if(fresh.length && document.getElementById('notifyOn').checked){
    beep();
    if('Notification' in window && Notification.permission==='granted'){
      fresh.forEach(f => {
        const notif = new Notification('New chat waiting', { body: (f.name||'A visitor')+' is currently free — needs an agent', icon:'/payg/public/images/PayG-Dark.svg' });
        notif.onclick = () => { window.focus(); notif.close(); };   // supervisory view — assign from here, not a personal claim
      });
    }
  }
}

(async()=>{ await loadAgents(); await loadSettings(); document.getElementById('notifyOn').checked = localStorage.getItem('convNotify')==='1'; load(); })();
setInterval(load, 4000);
</script>
</html>
