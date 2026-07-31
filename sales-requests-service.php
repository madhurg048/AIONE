<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Requests');
sales_header($me, 'Service · Customer Requests');
sales_nav('leads');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div class="relative">
      <span class="absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
      <input id="q" class="ctl pl-7 w-56" placeholder="Search ref / subject / customer…" oninput="liveSearch()">
    </div>
    <select id="fStatus" class="ctl" onchange="load()"><option value="">All status</option></select>
    <select id="fPriority" class="ctl" onchange="load()">
      <option value="">Any priority</option><option>urgent</option><option>high</option><option>medium</option><option>low</option>
    </select>
    <select id="fWaiting" class="ctl" onchange="load()">
      <option value="">Waiting on…</option><option value="us">On us</option><option value="customer">Customer</option><option value="vendor">Vendor</option>
    </select>
    <button onclick="clearF()" class="ctl hover:bg-gray-50" title="Clear filters"><i class="fa-solid fa-xmark"></i></button>
    <div class="flex-1"></div>
    <span id="count" class="text-[11px] text-gray-400"></span>
    <button onclick="openLeadForm()" class="btn-p" title="Capture a sales enquiry that came to Service"><i class="fa-solid fa-plus"></i> New Lead</button>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Name</th><th>Company</th><th>Contact</th><th>Subject</th><th>Source</th>
        <th class="sortable" onclick="sortBy('status')">Status</th>
        <th class="sortable" onclick="sortBy('priority')">Priority</th>
        <th>Waiting on</th><th>Created</th><th>Owner</th><th style="width:130px">Actions</th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
    <div id="pager" class="p-2"></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const CURRENT_ROLE = <?= json_encode(sales_role()) ?>;
// Default: newest chat reference (CHAT-2026-00036 …) first — the newest request is the one an
// agent needs to see first, and ticket_ref is allocated in sequence so it orders reliably.
let page = 1, perPage = 20, pages = 1, sortK = 'ticket_ref', sortDir = 'desc';
let TICKETS = [], TARGETS = [];
const stColor = { open:'blue', new:'blue', in_progress:'indigo', waiting_customer:'yellow', on_hold:'yellow', resolved:'green', transferred:'gray', closed:'gray' };
const prColor = { urgent:'red', high:'orange', medium:'yellow', low:'gray' };
const CHAT_STATUS_STYLE = {
  waiting: { cls: 'text-red-600 hover:text-red-700 animate-pulse', dot: 'bg-red-500', title: 'Live chat waiting — click to join' },
  active:  { cls: 'text-green-600 hover:text-green-700', dot: 'bg-green-500', title: 'Chat in progress — click to view' },
  missed:  { cls: 'text-amber-500 hover:text-amber-600', dot: 'bg-amber-500', title: 'Chat was missed — click to view' },
  closed:  { cls: 'text-gray-400 hover:text-gray-600', dot: 'bg-gray-400', title: 'Chat closed — click to view history' },
};
function liveChatIconHtml(leadId, chatStatus, extraCls) {
  if (!leadId) return '';
  const s = CHAT_STATUS_STYLE[chatStatus] || { cls: 'text-indigo-500 hover:text-indigo-700', dot: '', title: 'Start live chat' };
  const dot = s.dot ? `<span class="absolute -top-1 -right-1 w-2 h-2 ${s.dot} rounded-full"></span>` : '';
  return `<button onclick="liveChatLead(${leadId})" title="${esc(s.title)}" class="relative ${extraCls||''} ${s.cls}"><i class="fa-solid fa-comments"></i>${dot}</button>`;
}
// A callback request is ticket-only by design (chatbot-ticket.php never opens a chat session for
// assist_type='callback'), so a live-chat icon on the row would only ever *start* a brand-new chat
// the customer never asked for — and push the request into the Live Chats queue. Callback rows get
// the customer-info button in its place; every other request keeps the chat icon.
const isCallback = t => t.assist_type === 'callback';
function contactChannelIconHtml(t) {
  return isCallback(t)
    ? `<button onclick="showCustomerInfo(${t.id})" title="Customer info" class="text-blue-600 hover:text-blue-700 ml-1"><i class="fa-solid fa-circle-info"></i></button>`
    : liveChatIconHtml(t.lead_id, t.chat_status, 'ml-1');
}
const SOURCE_ICON = { chatbot: 'fa-robot', itsm: 'fa-headset', web: 'fa-globe', service_lead: 'fa-user-plus' };
// A service-captured sales enquiry is a lead, not a support channel — label it that way rather
// than leaking the internal source key ('service_lead') next to chatbot/whatsapp/call.
const SOURCE_LABEL = { service_lead: 'lead' };
// A CRM- ref is a lead this team captured; while it still sits in the service vertical it's a
// draft nobody in Sales can see yet, so it needs its own send action on the row.
const isDraftLead = t => t.source === 'service_lead' && t.vertical !== 'sales';

function params(){
  const p = { page, per: perPage, sort: sortK, dir: sortDir };
  const g = id => document.getElementById(id).value;
  if (g('q')) p.q = g('q'); if (g('fStatus')) p.status = g('fStatus');
  if (g('fPriority')) p.priority = g('fPriority'); if (g('fWaiting')) p.waiting = g('fWaiting');
  return p;
}
let _t; function liveSearch(){ clearTimeout(_t); _t = setTimeout(()=>{ page=1; load(); }, 300); }
function goPage(p, per){ if (per) perPage = +per; page = Math.max(1, p||1); load(); }
function sortBy(k){ if (sortK===k) sortDir = sortDir==='asc'?'desc':'asc'; else { sortK=k; sortDir='desc'; } load(); }
function clearF(){ ['q','fStatus','fPriority','fWaiting'].forEach(id=>document.getElementById(id).value=''); page=1; load(); }

let _statusesLoaded = false;
async function load(){
  const res = await apiGet('tickets.php', params());
  TICKETS = res.data || [];
  pages = res.pages || 1;
  const s = res.summary || {};
  document.getElementById('kpis').innerHTML = [
    ['Requests', s.total||0], ['Open', s.open||0, (s.open||0)>0],
    ['Waiting on us', s.waiting_us||0, (s.waiting_us||0)>0], ['High / urgent', s.high||0],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('count').textContent = (res.total||0) + ' requests';
  if (!_statusesLoaded) {
    const sts = [...new Set((res.data||[]).map(t=>t.status).filter(Boolean))];
    if (sts.length) { document.getElementById('fStatus').innerHTML = '<option value="">All status</option>' + sts.map(x=>`<option>${esc(x)}</option>`).join(''); _statusesLoaded = true; }
  }
  document.getElementById('rows').innerHTML = TICKETS.map(t=>{
    const phoneLine = t.customer_mobile ? `<br>
      <a href="tel:${(t.customer_mobile||'').replace(/[^0-9+]/g,'')}" class="text-indigo-600 hover:underline" title="Call from this device"><i class="fa-solid fa-phone text-[10px]"></i> ${esc(t.customer_mobile)}</a>
      ${t.lead_id?`<button onclick="callLead(${t.lead_id})" title="Cloud call" class="text-blue-600 hover:text-blue-700 ml-1"><i class="fa-solid fa-headset text-[11px]"></i></button>
      <button onclick="waLead(${t.lead_id})" title="WhatsApp" class="text-green-600 hover:text-green-700 ml-1"><i class="fa-brands fa-whatsapp"></i></button>`:''}` : '';
    // The info button hangs off the row itself, not off lead_id/mobile, so a callback with no
    // phone number on file still exposes its details — this is now its only entry point.
    const contact = (t.customer_email || t.customer_mobile)
      ? `${esc(t.customer_email||'')}${phoneLine}${contactChannelIconHtml(t)}`
      : (isCallback(t) ? contactChannelIconHtml(t) : '—');
    return `<tr>
      <td class="font-medium">${esc(t.customer_name||'—')}</td>
      <td class="text-gray-500">${esc(t.customer_company||t.merchant_name||'—')}</td>
      <td class="text-gray-500">${contact}</td>
      <td class="text-gray-600 max-w-xs truncate" title="${esc(t.subject||'')}">${esc(t.subject||'—')}</td>
      <td class="text-gray-500"><i class="fa-solid ${SOURCE_ICON[t.source]||'fa-inbox'} text-gray-400 mr-1"></i>${esc(SOURCE_LABEL[t.source] || t.source || '—')}${isDraftLead(t)?' <span class="text-[10px] text-amber-600">draft</span>':''}</td>
      <td>${badge(t.status||'—', stColor[t.status]||'gray')}</td>
      <td>${t.priority?badge(t.priority, prColor[t.priority]||'gray'):'—'}</td>
      <td class="${t.waiting_on==='us'?'text-red-600 font-medium':'text-gray-500'}">${esc(t.waiting_on||'—')}</td>
      <td class="text-gray-400 whitespace-nowrap">${esc(String(t.created_at||'').slice(0,16).replace('T',' '))}</td>
      <td class="text-gray-600">${t.assigned_to_name ? esc(t.assigned_to_name)
        : (['manager','service_manager'].includes(CURRENT_ROLE) ? '<span class="text-gray-300">Unassigned</span>'
          : `<button onclick="claimTicket(${t.id})" class="text-indigo-600 hover:text-indigo-800 font-medium"><i class="fa-solid fa-hand"></i> Claim</button>`)}</td>
      <td class="rowact whitespace-nowrap">
        <button onclick="editTicket(${t.id})" title="Edit" class="text-gray-400 hover:text-indigo-600"><i class="fa-solid fa-pen"></i></button>
        <button onclick="openTransfer(${t.id})" title="Transfer to concerned team/person" class="text-gray-400 hover:text-amber-600 ml-1"><i class="fa-solid fa-right-left"></i></button>
        ${isDraftLead(t)?`<button onclick="sendDraftToSales(${t.lead_id})" title="Send this draft to Sales" class="text-gray-400 hover:text-green-600 ml-1"><i class="fa-solid fa-paper-plane"></i></button>`:''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="11" class="text-center text-gray-400 py-4">No requests match</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
}
async function claimTicket(id) {
  const r = await apiSend('tickets.php?action=claim', { id });
  if (!r.success) return toast(r.error || 'Already claimed by someone else', false);
  toast('Request claimed');
  // If this request has a live chat attached (chatbot service/transaction requests always do,
  // when a Service agent was online to open one), claiming it should also pick up and open that
  // chat — not just change ownership on the ticket while the customer keeps waiting unanswered.
  const t = TICKETS.find(x => +x.id === +id);
  if (t && t.lead_id && t.chat_status && t.chat_status !== 'closed') {
    return liveChatLead(t.lead_id);   // redirects into sales-chat.php with the chat open
  }
  load();
}

// 'transferred' must be listed even though it's normally set by the Transfer action rather than
// picked by hand: a <select> whose value isn't among its options falls back to the FIRST one, so
// leaving it out meant opening this modal on a transferred request and saving any unrelated edit
// silently knocked it back to 'open' — i.e. off Transferred and into New Issues on the pipeline.
const STATUS_OPTS = ['open','in_progress','waiting_customer','on_hold','resolved','transferred','closed'];
const PRIORITY_OPTS = ['low','medium','high','urgent'];
const WAITING_OPTS = ['us','customer','vendor'];

function editTicket(id) {
  const t = TICKETS.find(x => +x.id === +id);
  if (!t) return;
  openModal(`
    <div class="space-y-3 text-xs">
      <div><label class="block text-gray-500 mb-1">Subject</label>
        <input id="edSubject" class="ctl w-full" value="${esc(t.subject||'')}"></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="block text-gray-500 mb-1">Status</label>
          <select id="edStatus" class="ctl w-full">${STATUS_OPTS.map(s=>`<option value="${s}" ${t.status===s?'selected':''}>${esc(s)}</option>`).join('')}</select></div>
        <div><label class="block text-gray-500 mb-1">Priority</label>
          <select id="edPriority" class="ctl w-full">${PRIORITY_OPTS.map(p=>`<option value="${p}" ${t.priority===p?'selected':''}>${esc(p)}</option>`).join('')}</select></div>
      </div>
      <div><label class="block text-gray-500 mb-1">Waiting on</label>
        <select id="edWaiting" class="ctl w-full">${WAITING_OPTS.map(w=>`<option value="${w}" ${t.waiting_on===w?'selected':''}>${esc(w)}</option>`).join('')}</select></div>
      <div><label class="block text-gray-500 mb-1">Note</label>
        <textarea id="edNote" class="ctl w-full" rows="3">${esc(t.last_update_note||'')}</textarea></div>
      <div class="flex justify-end gap-2 pt-1">
        <button onclick="closeModal()" class="ctl hover:bg-gray-50">Cancel</button>
        <button onclick="saveTicketEdit(${id})" class="btn-p">Save</button>
      </div>
    </div>`, `Edit ${esc(t.ticket_ref||'request')}`);
}
async function saveTicketEdit(id) {
  const b = {
    subject: document.getElementById('edSubject').value.trim(),
    status: document.getElementById('edStatus').value,
    priority: document.getElementById('edPriority').value,
    waiting_on: document.getElementById('edWaiting').value,
    last_update_note: document.getElementById('edNote').value.trim(),
  };
  const r = await apiSend('tickets.php?id=' + id, b, 'PATCH');
  if (r.success) { toast('Saved'); closeModal(); load(); }
  else toast(r.error || 'Update failed', false);
}

function infoRow(label, value) {
  if (value === null || value === undefined || value === '') return '';
  return `<div class="grid grid-cols-3 gap-2 py-1 border-b border-gray-100">
    <div class="text-gray-400">${esc(label)}</div>
    <div class="col-span-2 font-medium text-gray-800 break-words">${esc(String(value))}</div>
  </div>`;
}
// Splits each source on blank lines, drops empty/repeated paragraphs (case- and whitespace-
// insensitive), and rejoins in first-seen order.
function dedupeBlocks(sources) {
  const seen = new Set(), out = [];
  sources.filter(Boolean).forEach(src => String(src).split(/\n\s*\n/).forEach(block => {
    const b = block.trim();
    if (!b) return;
    const key = b.toLowerCase().replace(/\s+/g, ' ');
    if (seen.has(key)) return;
    seen.add(key); out.push(b);
  }));
  return out.join('\n\n');
}
async function showCustomerInfo(id) {
  const t = TICKETS.find(x => +x.id === +id);
  if (!t) return;
  let lead = null;
  if (t.lead_id) {
    try { const r = await apiGet('followups.php', { lead_id: t.lead_id }); lead = r.data ? r.data.lead : null; } catch (e) {}
  }
  const altMobile = lead && lead.phone && lead.phone !== t.customer_mobile ? lead.phone : '';
  const customerId = t.lead_id ? ('LEAD-' + t.lead_id) : (t.merchant_id ? ('MER-' + t.merchant_id) : '');
  // The customer types their message once, but it is stored twice — chatbot-ticket.php writes it
  // to BOTH crm_tickets.last_update_note and leads.description (and appends another copy to the
  // lead on every repeat request within the 6h reuse window). Concatenating the three sources
  // verbatim echoed the same paragraph two or more times here. De-dupe block by block instead.
  const enquiry = dedupeBlocks([t.subject, t.last_update_note, lead && lead.description]);
  openModal(`
    <div class="text-xs">
      ${infoRow('Customer Name', t.customer_name)}
      ${infoRow('Mobile Number', t.customer_mobile)}
      ${infoRow('Alternate Mobile Number', altMobile)}
      ${infoRow('Email Address', t.customer_email)}
      ${infoRow('Customer ID', customerId)}
      ${infoRow('Address', lead && lead.address)}
      ${infoRow('City', lead && lead.city)}
      ${infoRow('State', lead && lead.state)}
      ${infoRow('Pincode', lead && lead.pincode)}
      ${infoRow('Request ID', t.ticket_ref)}
      ${infoRow('Callback Request Date & Time', String(t.created_at||'').slice(0,16).replace('T',' '))}
      ${infoRow('Request Status', t.status)}
      ${infoRow('Assigned Agent', t.assigned_to_name || 'Unassigned')}
      ${infoRow('Lead Source', lead && lead.lead_source)}
      ${infoRow('Created Date', String(t.created_at||'').slice(0,16).replace('T',' '))}
      ${infoRow('Last Updated Date', String(t.updated_at||'').slice(0,16).replace('T',' '))}
      <div class="pt-2">
        <div class="text-gray-400 mb-1">Complete Customer Enquiry / Message</div>
        <div class="font-medium text-gray-800 whitespace-pre-wrap bg-gray-50 rounded p-2">${esc(enquiry || '—')}</div>
      </div>
    </div>`, `Customer Info — ${esc(t.ticket_ref||'request')}`);
}

async function loadTargets(){ const { data } = await apiGet('tickets.php', { action: 'transfer_targets' }); TARGETS = data || []; }
/* ---- New Lead: a sales enquiry that came in to the Service team ----
 * Two exits, per the buttons in the form:
 *   Save          — parks an unassigned draft (stays with Service, no sales owner yet).
 *   Send to Sales — round-robins it onto a sales exec and jumps to Sales Leads.
 * The CRM-2026-NNNNN ref is allocated by either path (see api/sales/service-lead.php); on this
 * list a CRM ref is what identifies the row as a lead rather than a chatbot/call request.
 */
function openLeadForm(){
  openModal(`<form id="leadForm" class="space-y-2" onsubmit="return false">
      <div class="grid grid-cols-2 gap-2">
        <input name="first_name" class="ctl w-full" placeholder="First name">
        <input name="last_name" class="ctl w-full" placeholder="Last name">
      </div>
      <input name="company" class="ctl w-full" placeholder="Company">
      <div class="grid grid-cols-2 gap-2">
        <input name="email" type="email" class="ctl w-full" placeholder="Email">
        <input name="mobile" class="ctl w-full" placeholder="Mobile">
      </div>
      <input name="subject" class="ctl w-full" placeholder="Subject (what are they asking about?)">
      <select name="priority" class="ctl w-full">
        <option value="medium">Medium priority</option><option value="urgent">Urgent</option>
        <option value="high">High</option><option value="low">Low</option>
      </select>
      <textarea name="description" class="ctl w-full" rows="3" placeholder="Notes from the call…"></textarea>
      <p class="text-[11px] text-gray-400">Save keeps it with Service as a draft. Send to Sales assigns it to the next sales exec in the rotation.</p>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" onclick="submitLead('save')" class="ctl hover:bg-gray-50">Save</button>
        <button type="button" onclick="submitLead('send')" class="btn-p"><i class="fa-solid fa-paper-plane"></i> Send to Sales</button>
      </div>
    </form>`, 'New Lead');
}

async function submitLead(action){
  const f = document.getElementById('leadForm');
  const b = Object.fromEntries(new FormData(f).entries());
  if (!b.last_name && !b.company) return toast('Name or company is required', false);

  // Guard against a double-click firing two submits — each would allocate its own CRM ref and
  // burn a rotation slot, leaving a duplicate lead assigned to a different rep.
  f.querySelectorAll('button').forEach(x => x.disabled = true);
  const r = await apiSend('service-lead.php?action=' + action, b);
  if (!r.success) {
    f.querySelectorAll('button').forEach(x => x.disabled = false);
    return toast(r.error || 'Could not save lead', false);
  }
  closeModal();
  if (action === 'send') {
    const who = r.data && r.data.owner ? r.data.owner.name : null;
    // No exec in the rotation means nobody owns the enquiry — say so rather than reporting
    // success and letting it sit unassigned.
    toast(who ? `${r.data.ticket_ref} sent to Sales — assigned to ${who}`
              : `${r.data.ticket_ref} sent to Sales, but no sales exec was available to assign`, !!who);
    return setTimeout(() => { location.href = 'sales-leads.php'; }, 900);
  }
  toast(`Draft saved as ${r.data.ticket_ref}`);
  load();
}

async function sendDraftToSales(leadId){
  if (!confirm('Send this lead to Sales? It will be assigned to the next sales exec in the rotation.')) return;
  const r = await apiSend('service-lead.php?action=send&id=' + leadId, {}, 'PATCH');
  if (!r.success) return toast(r.error || 'Could not send to Sales', false);
  const who = r.data && r.data.owner ? r.data.owner.name : null;
  toast(who ? `Sent to Sales — assigned to ${who}` : 'Sent to Sales, but no sales exec was available to assign', !!who);
  load();
}

function openTransfer(id){
  const t = TICKETS.find(x => +x.id === +id);
  if (!t) return;
  openModal(`<form onsubmit="saveTransfer(event,${id})" class="space-y-2 text-xs">
    <div class="text-gray-500">Currently: ${t.assigned_to_name?esc(t.assigned_to_name):'Unassigned'}</div>
    <label class="block">Transfer to<select name="to_employee_id" required class="ctl w-full mt-1">
      <option value="">Select person…</option>
      ${TARGETS.map(e=>`<option value="${e.id}">${esc(e.full_name)} — ${esc(e.designation||e.role)}</option>`).join('')}
    </select></label>
    <label class="block">Note (optional)<textarea name="note" rows="2" class="ctl w-full mt-1" placeholder="Why this is being handed off…"></textarea></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Transfer</button></div>
  </form>`, `Transfer ${esc(t.ticket_ref||'request')}`);
}
async function saveTransfer(e, id){
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  const r = await apiSend('tickets.php?action=transfer', { id, to_employee_id: fd.to_employee_id, note: fd.note });
  if (r.success) { toast(r.message || 'Transferred'); closeModal(); load(); } else toast(r.error || 'Transfer failed', false);
}

/* ---- reused from the Leads page: call / WhatsApp / live-chat, driven by the ticket's lead_id ---- */
async function callLead(id) {
  let agentNum = sessionStorage.getItem('agentCallback') || '';
  if (!agentNum) {
    agentNum = prompt('Your callback number — the provider rings this first, then the customer:', '');
    if (!agentNum) return;
    sessionStorage.setItem('agentCallback', agentNum);
  }
  const r = await apiSend('call.php', { lead_id: id, agent_number: agentNum });
  if (r.success) {
    if (r.data.mode === 'device') { toast('Dialing from this device…'); window.location.href = 'tel:' + r.data.dial; }
    else toast(r.message || 'Connecting your phone to the customer…');
  } else toast(r.error || 'Call failed', false);
}
async function waLead(id) {
  const { data } = await apiGet('followups.php', { lead_id: id });
  const l = data.lead;
  const phone = (l.mobile || l.phone || '').replace(/[^0-9+]/g, '');
  if (!phone) return toast('This customer has no phone number', false);
  const name = ((l.first_name || '') + ' ' + (l.last_name || '')).trim();
  window.location.href = 'sales-whatsapp.php?phone=' + encodeURIComponent(phone)
    + '&name=' + encodeURIComponent(name) + '&lead_id=' + id;
}
async function liveChatLead(id) {
  const r = await apiSend('chat.php?action=start_from_lead', { lead_id: id });
  if (!r.success) return toast(r.error || 'Could not start live chat', false);
  window.location.href = 'sales-chat.php?open=' + r.data.session_id + '&tab=' + (r.data.status || 'active');
}

(async()=>{ await loadTargets(); load(); })();
setInterval(load, 20000);
</script>
</html>
