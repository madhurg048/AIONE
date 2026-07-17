<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Tickets');
sales_header($me, 'Support · Tickets');
sales_nav('tickets');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div class="relative">
      <span class="absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
      <input id="q" class="ctl pl-7 w-56" placeholder="Search ref / subject / assignee…" oninput="liveSearch()">
    </div>
    <select id="fStatus" class="ctl" onchange="load()"><option value="">All status</option></select>
    <select id="fPriority" class="ctl" onchange="load()">
      <option value="">Any priority</option><option>urgent</option><option>high</option><option>medium</option><option>low</option>
    </select>
    <select id="fWaiting" class="ctl" onchange="load()">
      <option value="">Waiting on…</option><option value="us">On us</option><option value="customer">Customer</option><option value="vendor">Vendor</option>
    </select>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">From <input id="fFrom" type="date" class="ctl" onchange="load()"></label>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">To <input id="fTo" type="date" class="ctl" onchange="load()"></label>
    <button onclick="clearF()" class="ctl hover:bg-gray-50" title="Clear filters"><i class="fa-solid fa-xmark"></i></button>
    <div class="flex-1"></div>
    <span id="count" class="text-[11px] text-gray-400"></span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th class="sortable" onclick="sortBy('ticket_ref')">Ticket</th><th>Subject</th><th>Merchant</th><th>Customer</th>
        <th class="sortable" onclick="sortBy('status')">Status</th>
        <th class="sortable" onclick="sortBy('priority')">Priority</th>
        <th>Waiting on</th><th>Assignee</th><th>Chat</th>
        <th class="sortable" onclick="sortBy('updated_at')">Updated</th>
        <th></th>
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
// Default: newest chat reference (CHAT-2026-00036 …) first — see tickets.php's $sortMap.
let page = 1, perPage = 25, pages = 1, sortK = 'ticket_ref', sortDir = 'desc';
const stColor = { open:'blue', new:'blue', in_progress:'indigo', waiting:'yellow', on_hold:'yellow', resolved:'green', transferred:'gray', closed:'gray' };
const prColor = { urgent:'red', high:'orange', medium:'yellow', low:'gray' };
/* Unlike the Leads page's live-chat icon (active/missed only — a "needs attention now"
   signal), Tickets is the audit trail: show waiting/active/missed/closed alike. */
const chatColor = { waiting:'red', active:'green', missed:'orange', closed:'gray' };
function chatBadge(status) {
  if (!status) return '<span class="text-gray-300">—</span>';
  return badge(status, chatColor[status] || 'gray');
}

function params(){
  const p = { page, per: perPage, sort: sortK, dir: sortDir };
  const g = id => document.getElementById(id).value;
  if (g('q')) p.q = g('q'); if (g('fStatus')) p.status = g('fStatus');
  if (g('fPriority')) p.priority = g('fPriority'); if (g('fWaiting')) p.waiting = g('fWaiting');
  if (g('fFrom')) p.from = g('fFrom'); if (g('fTo')) p.to = g('fTo');
  return p;
}
let _t; function liveSearch(){ clearTimeout(_t); _t = setTimeout(()=>{ page=1; load(); }, 300); }
function goPage(p, per){ if (per) perPage = +per; page = Math.max(1, p||1); load(); }
function sortBy(k){ if (sortK===k) sortDir = sortDir==='asc'?'desc':'asc'; else { sortK=k; sortDir='desc'; } load(); }
function clearF(){ ['q','fStatus','fPriority','fWaiting','fFrom','fTo'].forEach(id=>document.getElementById(id).value=''); page=1; load(); }

let _statusesLoaded = false, TICKETS = [];
async function load(){
  const res = await apiGet('tickets.php', params());
  TICKETS = res.data || [];
  pages = res.pages || 1;
  const s = res.summary || {};
  document.getElementById('kpis').innerHTML = [
    ['Tickets', s.total||0], ['Open', s.open||0, (s.open||0)>0],
    ['Waiting on us', s.waiting_us||0, (s.waiting_us||0)>0], ['High / urgent', s.high||0],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('count').textContent = (res.total||0) + ' tickets';
  // populate status filter from data once
  if (!_statusesLoaded) {
    const sts = [...new Set((res.data||[]).map(t=>t.status).filter(Boolean))];
    if (sts.length) { document.getElementById('fStatus').innerHTML = '<option value="">All status</option>' + sts.map(x=>`<option>${esc(x)}</option>`).join(''); _statusesLoaded = true; }
  }
  document.getElementById('rows').innerHTML = (res.data||[]).map(t=>`
    <tr>
      <td class="font-mono text-[11px]">${esc(t.ticket_ref||'—')}</td>
      <td class="font-medium max-w-xs truncate" title="${esc(t.subject||'')}">${esc(t.subject||'—')}</td>
      <td class="text-gray-500">${esc(t.merchant_name||(t.merchant_id?('#'+t.merchant_id):'—'))}</td>
      <td class="text-gray-500">${t.customer_name || t.customer_mobile ? `${esc(t.customer_name||'—')}${t.customer_mobile?'<br><span class="text-[10px] text-gray-400">'+esc(t.customer_mobile)+'</span>':''}` : '—'}</td>
      <td>${badge(t.status||'—', stColor[t.status]||'gray')}</td>
      <td>${t.priority?badge(t.priority, prColor[t.priority]||'gray'):'—'}</td>
      <td class="${t.waiting_on==='us'?'text-red-600 font-medium':'text-gray-500'}">${esc(t.waiting_on||'—')}</td>
      <td class="text-gray-600">${t.assigned_to_name ? esc(t.assigned_to_name)
        // Claim is a Service-only workflow: Service tickets land unassigned (owner_id NULL) and
        // whoever clicks Claim first gets them. Sales tickets are auto-assigned by round-robin at
        // creation (chatbot-ticket.php), so a Sales rep never needs to claim — showing the button
        // to them was misleading. Only service_exec sees it now; everyone else sees the owner or
        // a plain "Unassigned".
        : (CURRENT_ROLE === 'service_exec'
          ? `<button onclick="claimTicket(${t.id})" class="text-indigo-600 hover:text-indigo-800 font-medium"><i class="fa-solid fa-hand"></i> Claim</button>`
          : '<span class="text-gray-300">Unassigned</span>')}</td>
      <td>${chatBadge(t.chat_status)}</td>
      <td class="text-gray-400 whitespace-nowrap">${esc(String(t.updated_at||'').slice(0,16).replace('T',' '))}</td>
      <td><button onclick="editTicket(${t.id})" title="Edit ticket" class="text-gray-400 hover:text-indigo-600"><i class="fa-solid fa-pen"></i></button></td>
    </tr>`).join('') || '<tr><td colspan="11" class="text-center text-gray-400 py-4">No tickets match</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
}
async function claimTicket(id) {
  const r = await apiSend('tickets.php?action=claim', { id });
  if (r.success) { toast('Ticket claimed'); load(); }
  else toast(r.error || 'Already claimed by someone else', false);
}

// 'transferred' included so editing a transferred ticket doesn't silently reset it to 'open'
// — see the note on the same list in sales-requests-service.php.
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
    </div>`, `Edit ${esc(t.ticket_ref||'ticket')}`);
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
  if (r.success) { toast('Ticket updated'); closeModal(); load(); }
  else toast(r.error || 'Update failed', false);
}

load();
setInterval(load, 20000);
</script>
</html>
