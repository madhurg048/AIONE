<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('WhatsApp');
sales_header($me, 'WhatsApp — Agent Console');
sales_nav('whatsapp');
?>
<main class="p-2">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2" style="height:calc(100vh - 120px)">
    <!-- inbox -->
    <div class="card flex flex-col overflow-hidden">
      <div class="p-2 border-b flex items-center justify-between gap-2">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap"><i class="fa-brands fa-whatsapp text-green-600"></i> Inbox <span id="cntUnread" class="text-[9px] text-gray-400 normal-case"></span></span>
        <span class="flex items-center gap-1.5">
          <div id="searchTextWrap" class="relative w-28">
            <i class="fa-solid fa-magnifying-glass absolute left-2 top-1/2 -translate-y-1/2 text-gray-300 text-[9px]"></i>
            <input id="waSearch" oninput="onSearch()" placeholder="Search…" class="w-full border rounded-full pl-6 pr-2 py-1 text-[10px]">
          </div>
          <input id="waSearchDate" type="date" onchange="onSearch()" class="hidden w-28 border rounded-full px-2 py-1 text-[10px]">
          <select id="searchField" onchange="onSearchFieldChange()" class="ctl text-[10px] py-0.5">
            <option value="name">Name</option>
            <option value="company">Company</option>
            <option value="phone">Number</option>
            <option value="date">Date</option>
          </select>
          <button id="bellBtn" onclick="toggleBell()" class="text-gray-300 hover:text-indigo-500" title="Alert me on new messages"><i class="fa-regular fa-bell"></i></button>
        </span>
      </div>
      <div id="inbox" class="flex-1 overflow-y-auto"></div>
    </div>
    <!-- conversation -->
    <div class="card flex flex-col overflow-hidden lg:col-span-2">
      <div id="chatHead" class="p-2 border-b text-sm text-gray-400 flex items-center justify-between relative">
        <span id="chatHeadLabel">Select a conversation to start</span>
        <span class="flex items-center gap-2">
          <button id="waEditContactBtn" onclick="editContact()" title="Edit contact name/number" class="hidden text-gray-500 hover:text-indigo-600 text-xs whitespace-nowrap"><i class="fa-solid fa-pen"></i></button>
          <button id="waTransferBtn" onclick="toggleTransferMenu()" title="Transfer this conversation" class="hidden text-gray-500 hover:text-indigo-600 text-xs whitespace-nowrap"><i class="fa-solid fa-right-left"></i> Transfer</button>
          <button id="waSummarizeBtn" onclick="summarizeThread()" title="AI summary of this conversation" class="hidden text-indigo-500 hover:text-indigo-700 text-xs whitespace-nowrap"><i class="fa-solid fa-wand-magic-sparkles"></i> Summarize</button>
        </span>
        <div id="waTransferMenu" class="hidden absolute right-2 top-9 z-20 bg-white border rounded-lg shadow-lg w-56 max-h-72 overflow-y-auto text-xs"></div>
      </div>
      <div id="readOnlyBanner" class="hidden bg-amber-50 border-b border-amber-200 text-amber-800 text-[11px] px-3 py-1.5 flex items-center gap-2">
        <i class="fa-solid fa-eye"></i>
        <span id="readOnlyBannerText">View-only — this conversation is assigned to another agent.</span>
      </div>
      <div id="thread" class="flex-1 overflow-y-auto p-3 space-y-2 bg-gray-50 text-sm"></div>
      <div id="windowClosed" class="hidden border-t p-2 bg-amber-50 text-amber-700 text-xs flex items-center justify-between gap-2">
        <span><i class="fa-solid fa-clock"></i> 24h window closed — the customer hasn't messaged recently. Send a template to reopen.</span>
        <button onclick="sendTemplate()" class="bg-amber-600 text-white px-2 py-1 rounded text-[11px] whitespace-nowrap">Send template</button>
      </div>
      <div id="composer" class="hidden border-t p-2 flex gap-2">
        <input type="file" id="fileIn" class="hidden" onchange="uploadFile()">
        <button onclick="document.getElementById('fileIn').click()" title="Attach a file" class="w-9 h-9 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200"><i class="fa-solid fa-paperclip text-xs"></i></button>
        <input id="msgIn" placeholder="Type a reply…" class="flex-1 border rounded-full px-3 py-1.5 text-sm" onkeydown="if(event.key==='Enter')send()">
        <button onclick="send()" class="w-9 h-9 rounded-full bg-indigo-600 text-white"><i class="fa-solid fa-paper-plane text-xs"></i></button>
        <button onclick="closeThread()" title="Mark resolved" class="w-9 h-9 rounded-full bg-gray-200 text-gray-600 hover:bg-red-100 hover:text-red-600"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
  </div>
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
let cur = null, lastId = 0, pollT = null, curWindowOpen = true, THREADS = [];
const stColor = { customer:'bg-white border text-gray-700', agent:'bg-indigo-600 text-white', bot:'bg-gray-100 text-gray-500', system:'bg-amber-50 text-amber-700 text-[11px] italic' };

let waSearchT = null;
function onSearchFieldChange() {
  const field = document.getElementById('searchField').value;
  const isDate = field === 'date';
  document.getElementById('searchTextWrap').classList.toggle('hidden', isDate);
  document.getElementById('waSearchDate').classList.toggle('hidden', !isDate);
  document.getElementById('waSearch').placeholder =
    field === 'company' ? 'Search by company…' : field === 'phone' ? 'Search by number…' : 'Search by name…';
  document.getElementById('waSearch').value = '';
  document.getElementById('waSearchDate').value = '';
  loadInbox();
}
function onSearch() {
  clearTimeout(waSearchT);
  waSearchT = setTimeout(loadInbox, 300);
}
async function loadInbox() {
  const field = document.getElementById('searchField').value;
  const params = { action: 'inbox', field };
  if (field === 'date') params.date = document.getElementById('waSearchDate').value;
  else params.q = document.getElementById('waSearch').value.trim();
  const { data } = await apiGet('whatsapp-chat.php', params);
  THREADS = data.threads;
  notifyUnread(data.threads);
  const item = (t) => `
    <div data-id="${t.id}" data-name="${esc(t.contact_name||t.phone||'')}" onclick="openThread(this.dataset.id, this.dataset.name)"
         class="px-2 py-2 border-b cursor-pointer hover:bg-gray-50 ${cur===t.id?'bg-indigo-50':''}">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold">${esc(t.contact_name||t.phone||'Contact')}</span>
        ${t.unread_count>0?`<span class="text-[9px] font-semibold bg-red-500 text-white rounded-full px-1.5 py-0.5">${t.unread_count}</span>`
          : (t.agent?`<span class="text-[9px] font-semibold bg-green-100 text-green-700 rounded px-1.5 py-0.5"><i class="fa-solid fa-headset text-[8px]"></i> ${esc(t.agent)}</span>`:'')}
      </div>
      <div class="text-[11px] text-gray-500 truncate">${esc(t.last_msg||'')}</div>
      <div class="text-[10px] text-gray-400 flex items-center justify-between">
        <span>${esc(t.phone||'')}</span>
        ${t.window_open? '<span class="text-green-600">● open</span>' : '<span class="text-amber-500">● 24h closed</span>'}
      </div>
    </div>`;
  document.getElementById('inbox').innerHTML = data.threads.map(item).join('') || '<div class="p-3 text-center text-gray-400 text-xs">No WhatsApp conversations yet</div>';
  const unreadTotal = data.threads.filter(t => t.unread_count > 0).length;
  document.getElementById('cntUnread').textContent = unreadTotal ? `(${unreadTotal} unread)` : '';
}
function fmtTime(ts) {
  if (!ts) return '';
  const d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
/* WhatsApp-style "Today / Yesterday / 5 July 2026" date pill shown once per day, between
   message groups — matches the original Meta WhatsApp UI convention. */
function dateKey(ts) {
  if (!ts) return '';
  const d = new Date(ts.replace(' ', 'T'));
  return isNaN(d.getTime()) ? '' : d.toDateString();
}
function dateLabel(ts) {
  const d = new Date(ts.replace(' ', 'T'));
  const today = new Date(), yest = new Date(); yest.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return 'Today';
  if (d.toDateString() === yest.toDateString()) return 'Yesterday';
  return d.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' });
}
function dateSeparatorHtml(ts) {
  return `<div class="flex justify-center my-2"><span class="bg-gray-200 text-gray-600 text-[10px] font-medium rounded px-2 py-0.5 shadow-sm">${esc(dateLabel(ts))}</span></div>`;
}
let _lastMsgDateKey = null;
function bubble(m) {
  if (m.sender === 'system') return `<div class="text-center text-[11px] text-amber-600 italic">${esc(m.body)}</div>`;
  const mine = m.sender === 'agent';
  let body;
  if (m.local_path) {
    // our own outbound upload — public URL, no auth proxy needed
    const isImg = (m.mime_type||'').startsWith('image/');
    body = isImg
      ? `<a href="${m.local_path}" target="_blank"><img src="${m.local_path}" class="rounded max-w-[180px] max-h-[180px]"></a>${m.body?'<div class="mt-1">'+esc(m.body)+'</div>':''}`
      : `<i class="fa-solid fa-paperclip"></i> <a href="${m.local_path}" target="_blank" class="underline">${esc(m.body||m.media_type||'attachment')}</a>`;
  } else if (m.media_id) {
    // inbound from the customer — proxied through Meta on demand, auth-gated
    body = `<i class="fa-solid fa-paperclip"></i> <a href="${API}whatsapp-media.php?message_id=${m.id}" target="_blank" class="underline">${esc(m.media_type||'attachment')}</a>${m.body?' — '+esc(m.body):''}`;
  } else {
    body = esc(m.body);
  }
  const time = fmtTime(m.created_at);
  return `<div class="flex ${mine?'justify-end':'justify-start'}"><div class="${stColor[m.sender]||stColor.customer} rounded-2xl px-3 py-1.5 max-w-[75%] shadow-sm">
    ${body}
    <div class="text-[10px] ${mine?'text-indigo-100':'text-gray-400'} text-right mt-0.5 select-none">${time}</div>
  </div></div>`;
}
async function uploadFile() {
  const input = document.getElementById('fileIn');
  const file = input.files[0]; if (!file || !cur) { input.value=''; return; }
  const fd = new FormData();
  fd.append('thread_id', cur);
  fd.append('file', file);
  try {
    const r = await fetch(API + 'whatsapp-upload.php?action=send', { method:'POST', headers:{'X-CSRF-Token': CSRF_TOKEN}, body: fd });
    const j = await r.json();
    if (j.success) pull(); else toast(j.error || 'Send failed', false);
  } catch (e) { toast('Send failed', false); }
  input.value = '';
}
async function openThread(id, name) {
  cur = +id; lastId = 0; _lastMsgDateKey = null;   // dataset.id arrives as a string — keep `cur` numeric so `cur===t.id` still matches on re-render
  document.getElementById('thread').innerHTML = '';
  document.getElementById('chatHeadLabel').innerHTML = `<span class="font-semibold text-gray-700">${esc(name||'Conversation #'+id)}</span> <span class="text-[11px] text-gray-400">· WhatsApp</span>`;
  document.getElementById('waSummarizeBtn').classList.remove('hidden');
  document.getElementById('waTransferBtn').classList.remove('hidden');
  document.getElementById('waEditContactBtn').classList.remove('hidden');
  hideTransferMenu();
  await pull();
  if (pollT) clearInterval(pollT);
  pollT = setInterval(pull, 3000);
  loadInbox();
}
async function summarizeThread() {
  if (!cur) return;
  const btn = document.getElementById('waSummarizeBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Summarizing…';
  const r = await apiSend('whatsapp-chat.php?action=summarize', { thread_id: cur });
  btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Summarize';
  if (!r.success) return toast(r.error || 'Summarize failed', false);
  openModal(`${renderAiSummary(r.data.summary)}<div class="text-[10px] text-gray-400 mt-2">via ${esc(r.data.source)}</div>`, 'Conversation summary');
}
let pulling = false;
async function pull() {
  if (!cur || pulling) return;
  pulling = true;
  const pulledFor = cur;
  try {
    const { data } = await apiGet('whatsapp-chat.php', { action: 'poll', thread_id: pulledFor, since: lastId });
    if (cur !== pulledFor) return; // switched conversations while this request was in flight
    if (data.messages.length) {
      const t = document.getElementById('thread');
      data.messages.forEach(m => {
        const k = dateKey(m.created_at);
        if (k && k !== _lastMsgDateKey) { t.insertAdjacentHTML('beforeend', dateSeparatorHtml(m.created_at)); _lastMsgDateKey = k; }
        t.insertAdjacentHTML('beforeend', bubble(m)); lastId = m.id;
      });
      t.scrollTop = t.scrollHeight;
    }
    curWindowOpen = !!data.window_open;
    const banner = document.getElementById('readOnlyBanner');
    document.getElementById('waTransferBtn').classList.toggle('hidden', data.status === 'closed' || !data.can_write);
    if (!data.can_write) {
      // Team-wide read, owner-only write: any teammate can open/watch this conversation,
      // but only its current owner (shown here) may actually reply to it.
      document.getElementById('composer').classList.add('hidden');
      document.getElementById('windowClosed').classList.add('hidden');
      document.getElementById('readOnlyBannerText').textContent =
        `View-only — this conversation is currently assigned to ${data.owner_name || 'another agent'}.`;
      banner.classList.remove('hidden');
    } else {
      banner.classList.add('hidden');
      document.getElementById('composer').classList.toggle('hidden', !curWindowOpen);
      document.getElementById('windowClosed').classList.toggle('hidden', curWindowOpen);
    }
  } finally {
    pulling = false;
  }
}
async function send() {
  const el = document.getElementById('msgIn'); const b = el.value.trim(); if (!b || !cur) return;
  el.value = '';
  const r = await apiSend('whatsapp-chat.php?action=post', { thread_id: cur, body: b });
  if (!r.success) { toast(r.error || 'Send failed', false); el.value = b; }
  pull();
}
async function sendTemplate() {
  if (!cur || !confirm('Send the configured template to reopen this conversation?')) return;
  const r = await apiSend('whatsapp-chat.php?action=template', { thread_id: cur });
  if (r.success) { toast(r.message || 'Template sent'); pull(); } else toast(r.error || 'Failed', false);
}
async function closeThread() {
  if (!cur || !confirm('Mark this conversation as resolved?')) return;
  const r = await apiSend('whatsapp-chat.php?action=close', { thread_id: cur });
  if (r.success) { toast('Marked resolved'); loadInbox(); }
}
/* ---- Transfer: dropdown of every other agent (both Sales and Service) with an online/offline dot ---- */
async function toggleTransferMenu() {
  const menu = document.getElementById('waTransferMenu');
  if (!menu.classList.contains('hidden')) { hideTransferMenu(); return; }
  if (!cur) return;
  menu.innerHTML = '<div class="p-3 text-gray-400">Loading agents…</div>';
  menu.classList.remove('hidden');
  const { data } = await apiGet('whatsapp-chat.php', { action: 'agents' });
  if (!data.length) { menu.innerHTML = '<div class="p-3 text-gray-400">No other agents found</div>'; return; }
  menu.innerHTML = data.map(a => `
    <div data-id="${a.id}" data-name="${esc(a.full_name || '')}" onclick="transferTo(this.dataset.id, this.dataset.name)" class="px-3 py-2 flex items-center gap-2 hover:bg-gray-50 cursor-pointer border-b last:border-b-0">
      <span class="w-2 h-2 rounded-full flex-shrink-0 ${a.is_online == 1 ? 'bg-green-500' : 'bg-red-400'}"></span>
      <span class="flex-1 truncate">${esc(a.full_name)}</span>
      <span class="text-[10px] text-gray-400 uppercase">${esc((a.role || '').replace('_', ' '))}</span>
    </div>`).join('');
}
function hideTransferMenu() { const m = document.getElementById('waTransferMenu'); if (m) m.classList.add('hidden'); }
async function transferTo(agentId, name) {
  if (!cur) return;
  hideTransferMenu();
  if (!confirm(`Transfer this conversation to ${name}? You'll lose the ability to reply once it's transferred.`)) return;
  const r = await apiSend('whatsapp-chat.php?action=transfer', { thread_id: cur, agent_id: agentId });
  if (!r.success) return toast(r.error || 'Transfer failed', false);
  toast(`Transferred to ${name}`);
  pull();
  loadInbox();
}

/* Edit the contact's display name / phone number, shown on the chat header — saved straight
   to whatsapp_threads (see whatsapp-chat.php?action=update_contact). */
function editContact() {
  if (!cur) return;
  const t = THREADS.find(x => +x.id === +cur) || {};
  openModal(`
    <form onsubmit="saveContactEdit(event)" class="space-y-3 text-xs">
      <div><label class="block text-gray-500 mb-1">Name</label>
        <input name="contact_name" class="border rounded px-2 py-1.5 w-full" value="${esc(t.contact_name||'')}"></div>
      <div><label class="block text-gray-500 mb-1">Phone number</label>
        <input name="phone" class="border rounded px-2 py-1.5 w-full" value="${esc(t.phone||'')}"></div>
      <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save</button></div>
    </form>`, 'Edit Contact');
}
async function saveContactEdit(e) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  fd.thread_id = cur;
  const res = await apiSend('whatsapp-chat.php?action=update_contact', fd);
  if (res.success) {
    toast('Contact updated'); closeModal();
    document.getElementById('chatHeadLabel').innerHTML = `<span class="font-semibold text-gray-700">${esc(res.data.contact_name || res.data.phone)}</span> <span class="text-[11px] text-gray-400">· WhatsApp</span>`;
    loadInbox();
  } else toast(res.error || 'Update failed', false);
}
document.addEventListener('click', (e) => {
  const menu = document.getElementById('waTransferMenu');
  const btn = document.getElementById('waTransferBtn');
  if (!menu || menu.classList.contains('hidden')) return;
  if (!menu.contains(e.target) && e.target !== btn && !btn.contains(e.target)) hideTransferMenu();
});
/* ---- new-message sound + desktop alert ---- */
let _seenUnread = null;
function bellOn(){ return localStorage.getItem('waBell') === '1'; }
function toggleBell(){
  const on = !bellOn(); localStorage.setItem('waBell', on ? '1' : '0');
  if(on && 'Notification' in window && Notification.permission!=='granted') Notification.requestPermission();
  updateBell(); toast(on ? 'Alerts on 🔔' : 'Alerts off');
}
function updateBell(){ const b=document.getElementById('bellBtn'); if(b) b.innerHTML = bellOn() ? '<i class="fa-solid fa-bell text-indigo-500"></i>' : '<i class="fa-regular fa-bell"></i>'; }
function beep(){ try{ const a=new (window.AudioContext||window.webkitAudioContext)(); const o=a.createOscillator(), g=a.createGain(); o.connect(g); g.connect(a.destination); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(.001,a.currentTime); g.gain.exponentialRampToValueAtTime(.3,a.currentTime+.02); g.gain.exponentialRampToValueAtTime(.001,a.currentTime+.4); o.start(); o.stop(a.currentTime+.42);}catch(e){} }
function notifyUnread(threads){
  const withUnread = (threads||[]).filter(t=>t.unread_count>0);
  const ids = new Set(withUnread.map(t=>t.id));
  if(_seenUnread === null){ _seenUnread = ids; return; }
  const fresh = withUnread.filter(t=>!_seenUnread.has(t.id));
  _seenUnread = ids;
  if(fresh.length && bellOn()){
    beep();
    if('Notification' in window && Notification.permission==='granted')
      fresh.forEach(t=>{
        const notif = new Notification('New WhatsApp message', { body: (t.contact_name||t.phone||'A contact')+' sent a message' });
        notif.onclick = () => { window.focus(); openThread(t.id, t.contact_name||t.phone); notif.close(); };
      });
  }
}
/* deep link from a Leads row's WhatsApp icon: sales-whatsapp.php?phone=...&name=...&lead_id=... */
async function openByPhoneParam() {
  const p = new URLSearchParams(location.search);
  const phone = p.get('phone');
  if (!phone) return;
  const r = await apiGet('whatsapp-chat.php', { action: 'open_by_phone', phone, name: p.get('name') || '', lead_id: p.get('lead_id') || '' });
  if (!r.success) return toast(r.error || 'Could not open this conversation', false);
  await loadInbox();
  openThread(r.data.thread_id, r.data.contact_name);
}

updateBell();
loadInbox();
openByPhoneParam();
setInterval(loadInbox, 5000);
</script>
</html>
