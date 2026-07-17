<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Calls');
sales_header($me, 'Service · Calls');
sales_nav('calls');
?>
<main class="p-2 space-y-2">
  <div id="callKpis" class="kstrip"></div>

  <!-- incoming call banner -->
  <div id="ringBanner" class="hidden card p-3 border-2 border-red-400 bg-red-50/60 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <span class="w-9 h-9 rounded-full bg-red-500 text-white flex items-center justify-center animate-pulse"><i class="fa-solid fa-phone-volume"></i></span>
      <div>
        <div class="text-sm font-semibold text-gray-800">Incoming call — <span id="ringNumber"></span></div>
        <div class="text-[11px] text-gray-500">Ringing on everyone's screen · first to accept gets it</div>
      </div>
    </div>
    <div class="flex gap-2">
      <button onclick="acceptRing()" class="bg-green-600 text-white px-3 py-1.5 rounded text-xs font-semibold hover:bg-green-700"><i class="fa-solid fa-phone"></i> Accept</button>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-4 gap-2">
    <!-- presence roster -->
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-users text-indigo-500"></i> Agents</div>
      <div id="roster" class="divide-y max-h-[60vh] overflow-y-auto"></div>
    </div>

    <!-- softphone -->
    <div class="card lg:col-span-2 flex flex-col">
      <div class="p-2 border-b flex items-center justify-between text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <span><i class="fa-solid fa-headset text-indigo-500"></i> Softphone</span>
        <span id="webrtcBadge" class="normal-case font-normal"></span>
      </div>
      <div class="p-3 flex-1">
        <!-- idle -->
        <div id="idlePane">
          <div class="flex gap-2">
            <input id="dialNumber" class="ctl flex-1" placeholder="Enter phone number to call">
            <button onclick="dial()" class="btn-p"><i class="fa-solid fa-phone"></i> Call</button>
          </div>
          <div class="mt-2 flex gap-2">
            <button onclick="openManualLog()" class="ctl hover:bg-gray-50 text-xs"><i class="fa-solid fa-pen-to-square"></i> Log a call taken elsewhere</button>
          </div>
        </div>
        <!-- active call -->
        <div id="activePane" class="hidden">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm font-semibold text-gray-800" id="activeNumber"></div>
              <div class="text-[11px] text-gray-500">Direction: <span id="activeDirection"></span> · <span id="activeTimer">00:00</span></div>
            </div>
            <button onclick="hangup()" class="bg-red-600 text-white px-3 py-1.5 rounded text-xs font-semibold hover:bg-red-700"><i class="fa-solid fa-phone-slash"></i> Hang up</button>
          </div>
          <div class="mt-3 border-t pt-2 space-y-2 text-xs">
            <div class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Customer details</div>
            <div class="grid grid-cols-2 gap-2">
              <input id="cCustomerName" class="ctl" placeholder="Customer name" oninput="saveNotesDebounced()">
              <select id="cDisposition" class="ctl" onchange="saveNotesDebounced()">
                <option value="">Disposition…</option>
                <option value="resolved">Resolved</option>
                <option value="follow_up">Needs follow-up</option>
                <option value="escalated">Escalated</option>
                <option value="wrong_number">Wrong number</option>
                <option value="no_response">No response</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="relative">
              <input id="cLeadSearch" class="ctl w-full" placeholder="Link to existing lead — search by name/phone" oninput="leadSearch()">
              <div id="cLeadResults" class="hidden absolute z-10 bg-white border rounded shadow-lg w-full max-h-40 overflow-y-auto"></div>
              <div id="cLeadLinked" class="hidden text-[11px] text-green-700 mt-1"></div>
            </div>
            <textarea id="cNotes" rows="3" class="ctl w-full" placeholder="Call notes…" oninput="saveNotesDebounced()"></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- call log -->
    <div class="card">
      <div class="p-2 border-b flex items-center justify-between text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <span><i class="fa-solid fa-list"></i> Call Log</span>
        <select id="logFilter" onchange="renderLog()" class="text-[10px] border rounded px-1 py-0.5 normal-case font-normal">
          <option value="">All</option><option value="inbound">Incoming</option><option value="outbound">Outgoing</option><option value="missed">Missed</option>
        </select>
      </div>
      <div id="callLogRows" class="divide-y max-h-[60vh] overflow-y-auto text-[11px]"></div>
    </div>
  </div>
  <p class="text-[10px] text-gray-400">Live — refreshes every 3s.</p>
</main>

<!-- manual log modal -->
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const mmss = s => { s=+s||0; const m=Math.floor(s/60), x=s%60; return m+':'+String(x).padStart(2,'0'); };
let STATE = null, ACTIVE_TIMER = null, ACTIVE_START = null, seenRinging = new Set(), notesTimer = null;

async function loadState(){
  const { data } = await apiGet('calls.php', { action: 'state' });
  STATE = data;
  renderRoster();
  renderRing();
  renderActive();
  renderLog();
  loadKpis();
}

function renderRoster(){
  document.getElementById('roster').innerHTML = (STATE.roster||[]).map(a => {
    const busy = a.status === 'on_call';
    const chip = busy
      ? `<span class="text-[10px] font-semibold text-red-600 bg-red-50 border border-red-200 rounded px-1.5 py-0.5"><i class="fa-solid fa-phone"></i> On call${a.with_number?' · '+esc(a.with_number):''}</span>`
      : a.status === 'offline'
      ? `<span class="text-[10px] text-gray-400">○ Offline</span>`
      : `<span class="text-[10px] font-semibold text-green-700 bg-green-50 border border-green-200 rounded px-1.5 py-0.5">● Available</span>`;
    return `<div class="p-2 flex items-center justify-between"><span class="text-xs font-medium">${esc(a.full_name)}</span>${chip}</div>`;
  }).join('') || '<div class="p-3 text-center text-gray-400 text-xs">No agents</div>';
}

function renderRing(){
  const ringing = (STATE.ringing||[]).filter(r => !STATE.my_active || r.id !== STATE.my_active.id);
  const banner = document.getElementById('ringBanner');
  if (STATE.my_active || !ringing.length){ banner.classList.add('hidden'); return; }
  const r = ringing[0];
  document.getElementById('ringNumber').textContent = r.from_number || 'Unknown number';
  banner.dataset.callId = r.id;
  banner.classList.remove('hidden');
  if (!seenRinging.has(r.id)){ seenRinging.add(r.id); beep(); }
}
async function acceptRing(){
  const id = document.getElementById('ringBanner').dataset.callId;
  if (!id) return;
  const r = await apiSend('calls.php?action=claim', { call_id: id });
  if (!r.success){ toast(r.error||'Already claimed by another agent', false); return loadState(); }
  toast('Call accepted'); loadState();
}

function renderActive(){
  const a = STATE.my_active;
  document.getElementById('idlePane').classList.toggle('hidden', !!a);
  document.getElementById('activePane').classList.toggle('hidden', !a);
  if (!a){ clearInterval(ACTIVE_TIMER); ACTIVE_TIMER = null; return; }
  document.getElementById('activeNumber').textContent = a.direction === 'inbound' ? (a.from_number||'') : (a.to_number||'');
  document.getElementById('activeDirection').textContent = a.direction === 'inbound' ? 'Incoming' : 'Outgoing';
  if (document.activeElement && document.activeElement.id !== 'cCustomerName') document.getElementById('cCustomerName').value = a.customer_name || '';
  if (document.activeElement && document.activeElement.id !== 'cNotes') document.getElementById('cNotes').value = a.notes || '';
  document.getElementById('cDisposition').value = a.disposition || '';
  window._activeCallId = a.id;
  if (!ACTIVE_TIMER){
    ACTIVE_START = a.claimed_at ? new Date(a.claimed_at.replace(' ','T')) : new Date();
    ACTIVE_TIMER = setInterval(()=>{ document.getElementById('activeTimer').textContent = mmss(Math.floor((Date.now()-ACTIVE_START)/1000)); }, 1000);
  }
}

function saveNotesDebounced(){
  clearTimeout(notesTimer);
  notesTimer = setTimeout(async ()=>{
    if (!window._activeCallId) return;
    await apiSend('calls.php?action=notes', {
      call_id: window._activeCallId,
      customer_name: document.getElementById('cCustomerName').value,
      notes: document.getElementById('cNotes').value,
      disposition: document.getElementById('cDisposition').value,
    }, 'PATCH');
  }, 600);
}

let leadSearchTimer = null;
function leadSearch(){
  clearTimeout(leadSearchTimer);
  const q = document.getElementById('cLeadSearch').value.trim();
  const box = document.getElementById('cLeadResults');
  if (q.length < 2){ box.classList.add('hidden'); return; }
  leadSearchTimer = setTimeout(async ()=>{
    const { data } = await apiGet('leads.php', { q, per: 8 });
    const rows = data || [];
    box.innerHTML = rows.slice(0,8).map(l=>`<div onclick="linkLead(${l.id},'${esc((l.first_name||'')+' '+(l.last_name||''))}')" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer text-xs">${esc((l.first_name||'')+' '+(l.last_name||''))} <span class="text-gray-400">${esc(l.mobile||l.phone||'')}</span></div>`).join('') || '<div class="px-2 py-1.5 text-gray-400 text-xs">No matches</div>';
    box.classList.remove('hidden');
  }, 300);
}
async function linkLead(id, name){
  if (!window._activeCallId) return;
  await apiSend('calls.php?action=notes', { call_id: window._activeCallId, lead_id: id }, 'PATCH');
  document.getElementById('cLeadResults').classList.add('hidden');
  document.getElementById('cLeadSearch').value = '';
  document.getElementById('cLeadLinked').textContent = 'Linked to lead: ' + name;
  document.getElementById('cLeadLinked').classList.remove('hidden');
  toast('Lead linked');
}

async function dial(){
  const to = document.getElementById('dialNumber').value.trim();
  if (!to) return toast('Enter a number', false);
  const r = await apiSend('calls.php?action=dial', { to_number: to });
  if (!r.success) return toast(r.error||'Call failed', false);
  if (r.data.mode === 'device') window.location.href = 'tel:' + r.data.dial;
  toast(r.message || 'Calling…');
  document.getElementById('dialNumber').value = '';
  loadState();
}
async function hangup(){
  if (!window._activeCallId) return;
  await apiSend('calls.php?action=hangup', { call_id: window._activeCallId });
  window._activeCallId = null;
  toast('Call ended'); loadState();
}

function openManualLog(){
  openModal(`<form onsubmit="saveManualLog(event)" class="space-y-2 text-xs">
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Channel<select name="channel" class="ctl w-full mt-1">
        <option value="mobile">Personal mobile</option><option value="whatsapp">WhatsApp call</option><option value="telephony">Telephony (other line)</option><option value="other">Other</option>
      </select></label>
      <label class="block">Direction<select name="direction" class="ctl w-full mt-1">
        <option value="inbound">Incoming</option><option value="outbound">Outgoing</option><option value="missed">Missed</option>
      </select></label>
    </div>
    <label class="block">Customer name<input name="customer_name" class="ctl w-full mt-1"></label>
    <label class="block">Phone<input name="phone" class="ctl w-full mt-1"></label>
    <label class="block">Duration (seconds)<input name="duration_sec" type="number" min="0" class="ctl w-full mt-1"></label>
    <label class="block">Notes<textarea name="notes" rows="2" class="ctl w-full mt-1"></textarea></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Log call</button></div>
  </form>`, 'Log a call taken elsewhere');
}
async function saveManualLog(e){
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  const r = await apiSend('calls.php?action=manual-log', fd);
  if (r.success){ toast('Call logged'); closeModal(); loadState(); } else toast(r.error||'Failed', false);
}

function renderLog(){
  const filter = document.getElementById('logFilter').value;
  let rows = STATE.recent || [];
  if (filter === 'missed') rows = rows.filter(c => ['missed','no-answer','busy','failed'].includes(c.status));
  else if (filter) rows = rows.filter(c => c.direction === filter);
  window._logRows = rows;
  document.getElementById('callLogRows').innerHTML = rows.map(c => {
    const name = (c.first_name||c.last_name) ? esc((c.first_name||'')+' '+(c.last_name||'')) : esc(c.customer_name || c.to_number || c.from_number || '—');
    const dirIcon = c.direction==='inbound' ? 'fa-arrow-down text-green-500' : c.direction==='missed' ? 'fa-phone-slash text-red-500' : 'fa-arrow-up text-indigo-500';
    const missed = ['missed','no-answer','busy','failed'].includes(c.status);
    return `<div class="p-2 ${missed?'bg-red-50/40':''}">
      <div class="flex items-center justify-between">
        <span class="font-medium"><i class="fa-solid ${dirIcon} mr-1"></i>${name}</span>
        ${badge(c.status, c.status==='completed'?'green':missed?'red':'yellow')}
      </div>
      <div class="flex items-center justify-between text-gray-400 mt-0.5">
        <span>${esc(c.disposition||c.channel||'')}</span>
        <span>${mmss(c.duration_sec)}${c.recording_url?` · <a href="${esc(c.recording_url)}" target="_blank" class="text-indigo-500"><i class="fa-solid fa-circle-play"></i></a>`:''}</span>
      </div>
      ${c.notes?`<div class="mt-1 bg-gray-50 border rounded px-1.5 py-1 text-[10px] text-gray-600 whitespace-pre-wrap">${esc(c.notes)}</div>`:''}
      <div class="mt-1 text-right"><button onclick="openRemark(${c.id})" class="text-[10px] text-indigo-500 hover:text-indigo-700"><i class="fa-solid fa-comment-medical"></i> Add remark</button></div>
    </div>`;
  }).join('') || '<div class="p-3 text-center text-gray-400">No calls yet</div>';
}

function openRemark(callId){
  const c = (window._logRows||[]).find(x=>x.id===callId);
  openModal(`<form onsubmit="saveRemark(event,${callId})" class="space-y-2 text-xs">
    <div class="text-gray-500">${esc((c&&(c.customer_name||c.to_number||c.from_number))||'')} · ${esc((c&&c.status)||'')}</div>
    ${c&&c.notes?`<div class="bg-gray-50 border rounded px-2 py-1.5 text-[11px] text-gray-600 whitespace-pre-wrap max-h-32 overflow-y-auto">${esc(c.notes)}</div>`:''}
    <label class="block">New remark<textarea name="remark" rows="3" required class="ctl w-full mt-1" placeholder="e.g. called back, customer confirmed order / explain why this was missed…"></textarea></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Add remark</button></div>
  </form>`, 'Add remark');
}
async function saveRemark(e, callId){
  e.preventDefault();
  const remark = new FormData(e.target).get('remark');
  const r = await apiSend('calls.php?action=notes', { call_id: callId, remark }, 'PATCH');
  if (r.success){ toast('Remark added'); closeModal(); loadState(); } else toast(r.error||'Failed', false);
}

async function loadKpis(){
  const { data } = await apiGet('dashboard-kpis.php', { action: 'list', vertical: 'service' });
  const callKpis = (data.kpis||[]).filter(k => k.metric_key && k.metric_key.startsWith('svc_call'));
  document.getElementById('callKpis').innerHTML = callKpis.length ? callKpis.map(k=>{
    const v = k.unit==='sec' ? mmss(k.value) : k.unit==='pct' ? Math.round(k.value)+'%' : Math.round(k.value);
    return `<div><div class="kv">${v}</div><div class="kl">${esc(k.title)}</div></div>`;
  }).join('') : '<div class="text-[11px] text-gray-400 px-1">No call KPIs pinned yet — an admin/manager can add them from the Service Dashboard KPI builder (metrics starting with "Call…").</div>';
}

function beep(){ try{ const a=new (window.AudioContext||window.webkitAudioContext)(); const o=a.createOscillator(), g=a.createGain(); o.connect(g); g.connect(a.destination); o.type='sine'; o.frequency.value=660; g.gain.setValueAtTime(.001,a.currentTime); g.gain.exponentialRampToValueAtTime(.3,a.currentTime+.02); g.gain.exponentialRampToValueAtTime(.001,a.currentTime+.5); o.start(); o.stop(a.currentTime+.52); }catch(e){} }

document.addEventListener('click', e => { if (!e.target.closest('#cLeadSearch') && !e.target.closest('#cLeadResults')) document.getElementById('cLeadResults')?.classList.add('hidden'); });

loadState();
setInterval(loadState, 3000);
</script>
</html>
