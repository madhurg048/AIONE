<?php
/**
 * Service dept's Pipeline — the Service counterpart to sales-pipeline.php.
 *
 * The Service team doesn't work leads and doesn't convert anything into deals, so the Sales
 * board's Lead pipeline (New → Contacted → Qualified → Unqualified) + Deal pipeline are both
 * meaningless here. What a Service agent actually works is the ISSUE queue — the crm_tickets
 * rows raised by the chatbot and other channels — so this board tracks that instead:
 *
 *   New Issues → Pending → Resolved,  with Transferred as the "someone else owns it now" siding.
 *
 * Movement is part automatic, part deliberate:
 *   • a new issue lands in New Issues                    (chatbot-ticket.php inserts status='open')
 *   • accepting the chat moves it to Pending             (chat.php ?action=pickup)
 *   • transferring the chat/ticket moves it to Transferred (chat.php + tickets.php ?action=transfer)
 *   • closing the chat does NOT resolve it — the agent drags the card to Resolved themselves,
 *     which is the whole point: it forces a human to confirm the issue is actually dealt with
 *     rather than letting "customer stopped typing" silently count as "fixed".
 *
 * Reads/writes are vertical-scoped by tickets.php, same as every other Service screen.
 */
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Pipeline');
sales_header($me, 'Service · Pipeline');
sales_nav('pipeline');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <div class="flex items-center justify-between px-1 pt-1">
    <h3 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
      <i class="fa-solid fa-headset text-indigo-500"></i> Issue Pipeline
      <span class="normal-case font-normal text-[10px] text-gray-400">drag a card to change its stage · closing a chat doesn't resolve it — move it here</span>
    </h3>
    <div id="boardSummary" class="text-[11px] text-gray-500"></div>
  </div>
  <div id="board" class="flex gap-2 overflow-x-auto pb-3"></div>
</main>

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
const CURRENT_ROLE = <?= json_encode(sales_role()) ?>;

/* The four stages. `status` is what gets written back on drop; `match` lists every
   crm_tickets.status that lands in this column — waiting_customer/on_hold are work that's
   already been started, so they read as Pending rather than needing columns of their own
   (the Requests page is where those finer states are set and seen). */
const COLS = [
  { key:'new',         label:'New Issues',  status:'open',        match:['open','new',''],                    color:'blue',
    hint:'Nobody has picked these up yet' },
  { key:'pending',     label:'Pending',     status:'in_progress', match:['in_progress','waiting_customer','on_hold'], color:'yellow',
    hint:'Accepted — being worked on' },
  { key:'resolved',    label:'Resolved',    status:'resolved',    match:['resolved','closed'],                color:'green',
    hint:'Confirmed done by an agent' },
  { key:'transferred', label:'Transferred', status:'transferred', match:['transferred'],                      color:'gray',
    hint:'Handed to another agent or team' },
];
const COL_OF = {};
COLS.forEach(c => c.match.forEach(s => COL_OF[s] = c.key));
/* Anything unrecognised falls into Pending rather than vanishing off the board — an issue you
   can't see is worse than one in a slightly wrong column. */
const stageOf = t => COL_OF[(t.status || '').toLowerCase()] || 'pending';

const PRI = { urgent:'red', high:'orange', medium:'yellow', low:'gray' };
/* Mirrors the Requests page's chat icon so the two screens read the same way. */
const CHAT_STYLE = {
  waiting: { cls:'text-red-600 animate-pulse', title:'Live chat waiting — click to join' },
  active:  { cls:'text-green-600',             title:'Chat in progress — click to view' },
  missed:  { cls:'text-amber-500',             title:'Chat was missed — click to view' },
  closed:  { cls:'text-gray-400',              title:'Chat closed — click to view history' },
};

let TICKETS = [], dragId = null;

async function loadBoard() {
  const { data } = await apiGet('tickets.php', { board: 1 });
  TICKETS = data || [];
  renderBoard();
}

function renderBoard() {
  const counts = {};
  document.getElementById('board').innerHTML = COLS.map(col => {
    const items = TICKETS.filter(t => stageOf(t) === col.key);
    counts[col.key] = items.length;
    return `
    <div class="stage-col bg-gray-50 rounded-lg border flex flex-col"
         ondragover="event.preventDefault();this.classList.add('drop-active')"
         ondragleave="this.classList.remove('drop-active')" ondrop="drop(event,'${col.key}')">
      <div class="px-2 py-1.5 border-b">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold text-gray-700">${col.label} ${badge(items.length,'gray')}</span>
        </div>
        <div class="text-[9px] text-gray-400 leading-tight">${esc(col.hint)}</div>
      </div>
      <div class="p-1.5 space-y-1.5 flex-1 min-h-[120px]">
        ${items.map(card).join('') || '<div class="text-[10px] text-gray-300 text-center py-3">Nothing here</div>'}
      </div>
    </div>`;
  }).join('');
  document.getElementById('boardSummary').textContent =
    `${counts.new || 0} unclaimed · ${counts.pending || 0} in progress · ${counts.transferred || 0} transferred`;
  document.getElementById('kpis').innerHTML = [
    ['New issues', counts.new || 0, (counts.new || 0) > 0],
    ['Pending', counts.pending || 0, false],
    ['Resolved', counts.resolved || 0, false],
    ['Transferred', counts.transferred || 0, false],
  ].map(([l, v, warn]) => `<div><div class="kv ${warn ? 'text-red-600' : ''}">${v}</div><div class="kl">${l}</div></div>`).join('');
}

function card(t) {
  const chat = CHAT_STYLE[t.chat_status];
  return `
  <div class="deal-card bg-white rounded border shadow-sm p-1.5" draggable="true"
       ondragstart="dragId=${t.id};event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(${t.id}));this.classList.add('dragging')"
       ondragend="this.classList.remove('dragging')">
    <div class="flex items-start justify-between gap-1">
      <span class="font-mono text-[10px] text-gray-400">${esc(t.ticket_ref || '—')}</span>
      ${t.priority ? badge(t.priority, PRI[t.priority] || 'gray') : ''}
    </div>
    <div class="text-[11px] font-medium text-gray-800 truncate" title="${esc(t.customer_name || '')}">${esc(t.customer_name || 'Unknown customer')}</div>
    <div class="text-[10px] text-gray-500 truncate" title="${esc(t.subject || '')}">${esc(t.subject || '—')}</div>
    <div class="flex items-center justify-between mt-1">
      <span class="text-[10px] text-gray-500 truncate">
        <i class="fa-solid fa-user text-gray-400"></i> ${esc(t.assigned_to_name || 'Unassigned')}
      </span>
      <span class="flex items-center gap-1.5 flex-shrink-0">
        ${chat ? `<button onclick="openChat(${t.lead_id})" draggable="false" title="${esc(chat.title)}" class="${chat.cls} hover:opacity-70"><i class="fa-solid fa-comments text-[11px]"></i></button>` : ''}
        <button onclick="openTicket(${t.id})" draggable="false" title="Details" class="text-gray-400 hover:text-indigo-600"><i class="fa-solid fa-circle-info text-[11px]"></i></button>
      </span>
    </div>
  </div>`;
}

/* Optimistic move: repaint immediately, roll the card back to where it came from if the write
   fails. Same pattern as the Sales board (sales-pipeline.php) — waiting on the round-trip
   before moving the card makes drag-and-drop feel broken. */
async function drop(e, colKey) {
  e.preventDefault();
  document.querySelectorAll('.drop-active').forEach(el => el.classList.remove('drop-active'));
  if (!dragId) return;
  const id = dragId; dragId = null;
  const t = TICKETS.find(x => +x.id === +id);
  const col = COLS.find(c => c.key === colKey);
  if (!t || !col || stageOf(t) === colKey) return;
  const prev = t.status;
  t.status = col.status;
  renderBoard();
  const r = await apiSend('tickets.php?id=' + id, { status: col.status }, 'PATCH');
  if (r.success) toast(`${t.ticket_ref} → ${col.label}`);
  else { t.status = prev; renderBoard(); toast(r.error || 'Could not move this issue', false); }
}

function openTicket(id) {
  const t = TICKETS.find(x => +x.id === +id);
  if (!t) return;
  const row = (k, v) => `<div class="flex justify-between gap-3 border-b py-1"><span class="text-gray-500">${k}</span><span class="text-gray-800 text-right">${v}</span></div>`;
  openModal(`<div class="text-xs">
    ${row('Reference', `<span class="font-mono">${esc(t.ticket_ref || '—')}</span>`)}
    ${row('Customer', esc(t.customer_name || '—'))}
    ${row('Company', esc(t.customer_company || '—'))}
    ${row('Subject', esc(t.subject || '—'))}
    ${row('Source', esc(t.source || '—'))}
    ${row('Stage', esc((COLS.find(c => c.key === stageOf(t)) || {}).label || t.status || '—'))}
    ${row('Priority', t.priority ? badge(t.priority, PRI[t.priority] || 'gray') : '—')}
    ${row('Owner', esc(t.assigned_to_name || 'Unassigned'))}
    ${row('Raised', esc(String(t.created_at || '').slice(0, 16).replace('T', ' ')))}
    <div class="flex justify-end gap-2 pt-3">
      <a href="sales-requests-service.php" class="ctl hover:bg-gray-50 no-underline text-gray-700">Open in Requests</a>
      ${t.lead_id && t.chat_status ? `<button onclick="openChat(${t.lead_id})" class="btn-p">View chat</button>` : ''}
    </div>
  </div>`, 'Issue details');
}

/* Same entry point the Requests page uses — reuses/starts this customer's chat session and
   lands on the right tab in the agent console. */
async function openChat(leadId) {
  if (!leadId) return toast('This issue has no chat attached', false);
  const r = await apiSend('chat.php?action=start_from_lead', { lead_id: leadId });
  if (!r.success) return toast(r.error || 'Could not open the chat', false);
  window.location.href = 'sales-chat.php?open=' + r.data.session_id + '&tab=' + (r.data.status || 'active');
}

loadBoard();
// Refresh so a chatbot-raised issue appears without a manual reload — paused while a modal is
// open (don't yank the card out from under someone reading it) or mid-drag.
setInterval(() => {
  if (dragId) return;
  if (!document.getElementById('modal').classList.contains('hidden')) return;
  loadBoard();
}, 20000);
</script>
</html>
