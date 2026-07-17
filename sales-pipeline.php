<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Pipeline');
sales_header($me, 'Sales · Pipeline');
sales_nav('pipeline');
?>
<main class="p-2 space-y-2">

  <!-- ===== LEAD PIPELINE ===== -->
  <div class="flex items-center justify-between px-1">
    <h3 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
      <i class="fa-solid fa-user-plus text-indigo-500"></i> Lead Pipeline
      <span class="normal-case font-normal text-[10px] text-gray-400">drag to change status · convert to push into deals</span></h3>
    <div id="leadSummary" class="text-[11px] text-gray-500"></div>
  </div>
  <div id="leadBoard" class="flex gap-2 overflow-x-auto pb-1"></div>

  <!-- ===== DEAL PIPELINE ===== -->
  <div class="flex items-center justify-between px-1 pt-1">
    <h3 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
      <i class="fa-solid fa-diagram-project text-indigo-500"></i> Deal Pipeline</h3>
    <div id="pipeSummary" class="text-[11px] text-gray-500"></div>
  </div>
  <div data-ai-scope="pipeline"></div>
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
/* owner chip shown on every card */
const owner = n => `<span class="text-[10px] text-gray-500"><i class="fa-solid fa-user text-gray-400"></i> ${esc(n||'Unassigned')}</span>`;

/* ================= LEAD PIPELINE ================= */
const LEAD_COLS = [
  { key: 'new', label: 'New', color: 'blue' },
  { key: 'contacted', label: 'Contacted', color: 'yellow' },
  { key: 'qualified', label: 'Qualified', color: 'indigo' },
  { key: 'unqualified', label: 'Unqualified', color: 'gray' },
];
let LEADS = [], leadDragId = null;

async function loadLeadBoard() {
  const { data } = await apiGet('leads.php', { board: 1 });
  LEADS = data;
  renderLeadBoard();
}

function renderLeadBoard() {
  document.getElementById('leadBoard').innerHTML = LEAD_COLS.map(col => {
    const items = LEADS.filter(l => l.lead_status === col.key);
    return `
    <div class="stage-col bg-gray-50 rounded-lg border flex flex-col" ondragover="event.preventDefault();this.classList.add('drop-active')"
         ondragleave="this.classList.remove('drop-active')" ondrop="dropLead(event,'${col.key}')">
      <div class="px-2 py-1.5 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-700">${col.label} ${badge(items.length,'gray')}</span>
      </div>
      <div class="p-1.5 space-y-1.5 flex-1 min-h-[90px]">
        ${items.map(l => `
          <div class="deal-card bg-white rounded border shadow-sm p-1.5" draggable="true"
               ondragstart="leadDragId=${l.id};event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(${l.id}));this.classList.add('dragging')" ondragend="this.classList.remove('dragging')">
            <div class="flex items-start justify-between gap-1">
              <span class="text-[11px] font-medium text-gray-800">${esc((l.first_name||'')+' '+(l.last_name||''))}</span>
              ${l.score>0 ? badge(l.score, l.score>=70?'green':l.score>=40?'yellow':'gray') : ''}
            </div>
            <div class="text-[10px] text-gray-500">${esc(l.company||'')}</div>
            <div class="flex items-center justify-between mt-1">
              ${owner(l.owner_name)}
              <button onclick="quickConvert(${l.id})" title="Convert to deal" class="text-[10px] text-green-600 hover:text-green-800"><i class="fa-solid fa-arrow-right-arrow-left"></i> Convert</button>
            </div>
          </div>`).join('')}
      </div>
    </div>`;
  }).join('');
  document.getElementById('leadSummary').textContent = LEADS.length + ' active leads';
}

async function dropLead(e, status) {
  e.preventDefault();
  document.querySelectorAll('.drop-active').forEach(el => el.classList.remove('drop-active'));
  if (!leadDragId) return;
  const id = leadDragId; leadDragId = null;
  const lead = LEADS.find(l => +l.id === +id);
  if (!lead || lead.lead_status === status) return;
  const prevStatus = lead.lead_status;
  lead.lead_status = status;
  renderLeadBoard();   // move it instantly, don't wait on the network
  const res = await apiSend('leads.php?id=' + id, { lead_status: status }, 'PATCH');
  if (res.success) { toast('Lead → ' + status); }
  else { lead.lead_status = prevStatus; renderLeadBoard(); toast(res.error || 'Failed', false); }
}

async function quickConvert(id) {
  const amt = prompt('Convert to a deal — expected amount (₹):', '');
  if (amt === null) return;
  const res = await apiSend('lead-convert.php', { lead_id: id, create_deal: true, amount: parseFloat(amt) || 0 });
  if (res.success) { toast('Converted → deal in pipeline'); loadLeadBoard(); loadBoard(); } else toast(res.error || 'Failed', false);
}

/* ================= DEAL PIPELINE ================= */
let STAGES = [], DEALS = [], dragId = null;

async function loadBoard() {
  const { data } = await apiGet('deals.php');
  STAGES = data.stages; DEALS = data.deals;
  renderBoard();
}

function renderBoard() {
  let totalOpen = 0;
  document.getElementById('board').innerHTML = STAGES.map(st => {
    const items = DEALS.filter(d => +d.stage_id === +st.id);
    const sum = items.reduce((a, d) => a + (+d.amount || 0), 0);
    if (st.stage_type === 'open') totalOpen += sum;
    return `
    <div class="stage-col bg-gray-50 rounded-lg border flex flex-col" ondragover="event.preventDefault();this.classList.add('drop-active')"
         ondragleave="this.classList.remove('drop-active')" ondrop="drop(event, ${st.id})">
      <div class="px-2 py-1.5 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-700">${esc(st.name)} ${badge(items.length,'gray')}</span>
        <span class="text-[10px] ${st.stage_type==='won'?'text-green-600':st.stage_type==='lost'?'text-red-500':'text-gray-500'}">${inr(sum)}</span>
      </div>
      <div class="p-1.5 space-y-1.5 flex-1 min-h-[100px]">
        ${items.map(d => `
          <div class="deal-card bg-white rounded border shadow-sm p-1.5" draggable="true"
               ondragstart="dragId=${d.id};event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(${d.id}));this.classList.add('dragging')" ondragend="this.classList.remove('dragging')">
            <div class="flex items-start justify-between gap-1">
              <span class="text-[11px] font-medium text-gray-800">${esc(d.deal_name)}</span>
              <button onclick="nba(${d.id})" title="AI next best action" class="text-purple-600 hover:text-purple-800"><i class="fa-solid fa-wand-magic-sparkles text-[11px]"></i></button>
            </div>
            <div class="text-[10px] text-gray-500">${esc(d.account_name||'')}</div>
            <div class="flex items-center justify-between mt-1">
              <span class="text-[11px] font-semibold text-indigo-600">${inr(d.amount)}</span>
              <span class="text-[10px] text-gray-400">${d.probability}%</span>
            </div>
            <div class="flex items-center justify-between mt-0.5">
              ${owner(d.owner_name)}
              ${d.contact_phone ? `<span class="flex gap-1.5">
                <a href="tel:${(d.contact_phone||'').replace(/[^0-9+]/g,'')}" draggable="false" title="Call ${esc(d.contact_name||'')}" class="text-indigo-600 hover:text-indigo-800"><i class="fa-solid fa-phone text-[10px]"></i></a>
                <a href="https://wa.me/${waNum(d.contact_phone)}?text=${encodeURIComponent('Hi '+(d.contact_name||'')+', regarding '+d.deal_name)}" target="_blank" draggable="false" title="WhatsApp ${esc(d.contact_name||'')}" class="text-green-600 hover:text-green-700"><i class="fa-brands fa-whatsapp text-[11px]"></i></a>
              </span>` : '<span class="text-[9px] text-gray-300">no contact #</span>'}
            </div>
          </div>`).join('')}
      </div>
    </div>`;
  }).join('');
  document.getElementById('pipeSummary').textContent = 'Open pipeline value: ' + inr(totalOpen);
}

async function drop(e, stageId) {
  e.preventDefault();
  document.querySelectorAll('.drop-active').forEach(el => el.classList.remove('drop-active'));
  if (!dragId) return;
  const id = dragId; dragId = null;
  const deal = DEALS.find(d => +d.id === +id);
  if (!deal || +deal.stage_id === +stageId) return;
  const prevStageId = deal.stage_id;
  const toStage = STAGES.find(st => +st.id === +stageId);
  deal.stage_id = stageId;
  if (toStage) deal.probability = toStage.probability;
  renderBoard();   // move it instantly, don't wait on the network
  const res = await apiSend('deal-move-stage.php', { deal_id: id, to_stage_id: stageId });
  if (res.success) {
    toast(res.data.message || ('Moved to ' + res.data.stage));
    if (res.data.merchant_id) loadBoard();   // a merchant shell was created — safe full resync
  } else {
    deal.stage_id = prevStageId;
    renderBoard();
    toast(res.error || 'Move failed', false);
  }
}

async function nba(id) {
  toast('AI thinking…');
  const res = await apiSend('ai.php', { action: 'next_best_action', deal_id: id });
  if (!res.success) return toast(res.error || 'AI unavailable', false);
  const d = res.data;
  openModal(`<div class="space-y-3 text-xs">
    <div><div class="font-semibold text-gray-600 mb-1">Recommended next actions</div>
      <ol class="list-decimal pl-5 space-y-0.5">${(d.actions||[]).map(a=>`<li>${esc(a)}</li>`).join('')}</ol></div>
    <div class="bg-red-50 rounded p-2"><b class="text-red-600">Main risk:</b> ${esc(d.risk||'')}</div>
    <div class="bg-indigo-50 rounded p-2"><b class="text-indigo-700">Talk track:</b> ${esc(d.talk_track||'')}</div>
  </div>`, 'AI — Next Best Action');
}

loadLeadBoard(); loadBoard();
</script>
</html>
