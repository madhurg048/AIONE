<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// Read-only extra visibility: admin, manager (own reports), sales_head, HR. Admin never acts.
$canViewExtra = in_array(sales_role(), ['admin','manager','sales_head','hr_head','hr_manager'], true);
sales_head('Reimbursements');
sales_header($me, 'Sales · Reimbursements');
sales_nav('reimbursements');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <?php if ($canViewExtra): ?>
  <!-- who claimed how much (admin / manager / sales_head / HR analysis) -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">
      <i class="fa-solid fa-chart-simple text-indigo-500"></i> Who claimed how much
      <span class="normal-case font-normal text-[10px] text-gray-400">— reimbursement by rep (paid · approved · pending)</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
      <div class="lg:col-span-2" style="height:230px"><canvas id="cByRep"></canvas></div>
      <div>
        <div class="overflow-x-auto"><table class="dense"><thead><tr><th>Rep</th><th class="text-right">Claims</th><th class="text-right">Total</th></tr></thead><tbody id="repTbl"></tbody></table></div>
        <div class="text-[10px] uppercase text-gray-400 mt-2 mb-1">By category</div>
        <div id="catChips" class="flex flex-wrap gap-1"></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <button onclick="newClaim()" class="btn-p"><i class="fa-solid fa-plus"></i> New claim</button>
    <span class="text-gray-300">|</span>
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option>submitted</option><option>approved</option><option>paid</option><option>rejected</option>
    </select>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">From <input id="fFrom" type="date" class="ctl" onchange="load()"></label>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">To <input id="fTo" type="date" class="ctl" onchange="load()"></label>
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400">Claims are linked to a lead not yet converted to a merchant.</span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Date</th><?php if($canViewExtra):?><th>Rep</th><?php endif;?><th>Lead (in pursuit)</th>
        <th>Category</th><th class="text-right">Amount</th><th>Sales approval</th><th>HR approval</th><th>Status</th><th>Note</th><th></th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
    <div id="pager" class="p-2"></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<?php if ($canViewExtra): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script><?php endif; ?>
<script>
const CAN_VIEW_EXTRA = <?= $canViewExtra ? 'true':'false' ?>;
let page = 1, perPage = 25, pages = 1;
const CAT_LABEL = { travel:'Travel', stay:'Stay', food:'Food', fuel:'Fuel', other:'Other' };
const ST_COLOR  = { submitted:'yellow', approved:'blue', paid:'green', rejected:'red' };
const STAGE_COLOR = { pending:'yellow', approved:'green', rejected:'red' };

function params(){ const p={page,per:perPage}; const g=id=>document.getElementById(id).value;
  if(g('fStatus'))p.status=g('fStatus'); if(g('fFrom'))p.from=g('fFrom'); if(g('fTo'))p.to=g('fTo'); return p; }
function goPage(p, per){ if(per) perPage=+per; page=Math.max(1,p||1); load(); }

async function load(){
  const res = await apiGet('reimbursements.php', params());
  pages = res.pages || 1; const s = res.summary || {};
  document.getElementById('kpis').innerHTML = [
    ['Claims', s.cnt||0], ['Total', inr(s.total||0)],
    ['Pending approval', inr(s.pending||0), (s.pending||0)>0],
    ['Approved (to pay)', inr(s.approved||0)], ['Paid', inr(s.paid||0)],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-amber-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('rows').innerHTML = (res.data||[]).map(r=>{
    // Stage 1 (Sales Manager / Sales Head) and stage 2 (HR) are independent action slots —
    // a row can show one, both, or neither depending on who's looking and where the claim is.
    const mgrAct = r.can_mgr_act ? `
      <button onclick="act(${r.id},'mgr_approve')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Accept</button>
      <button onclick="act(${r.id},'mgr_reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded ml-1">Deny</button>` : '';
    const hrAct = r.can_hr_act ? `
      <button onclick="act(${r.id},'hr_approve')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Accept</button>
      <button onclick="act(${r.id},'hr_reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded ml-1">Deny</button>` : '';
    const payAct = r.can_pay ? `<button onclick="act(${r.id},'pay')" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Mark paid</button>` : '';
    return `<tr>
      <td class="text-gray-500 whitespace-nowrap">${esc(r.expense_date||'')}</td>
      ${CAN_VIEW_EXTRA?`<td class="font-medium">${esc(r.employee_name||('#'+r.employee_id))}</td>`:''}
      <td>${esc(r.lead_company||r.lead_name||('lead #'+r.lead_id))}<div class="text-[10px] text-gray-400">${esc(r.lead_name||'')}</div></td>
      <td>${badge(CAT_LABEL[r.category]||r.category,'gray')}</td>
      <td class="text-right font-semibold">${inr(r.amount)}</td>
      <td class="whitespace-nowrap">${badge(r.mgr_status, STAGE_COLOR[r.mgr_status]||'gray')}${(r.mgr_status==='approved'&&!r.mgr_by)?' <span class="text-[9px] text-gray-400">(self — skipped to HR)</span>':''}${mgrAct?' '+mgrAct:''}</td>
      <td class="whitespace-nowrap">${badge(r.hr_status, STAGE_COLOR[r.hr_status]||'gray')}${hrAct?' '+hrAct:''}</td>
      <td>${badge(r.status, ST_COLOR[r.status]||'gray')}</td>
      <td class="text-gray-500 text-[11px] max-w-xs truncate" title="${esc(r.description||'')}">${esc(r.description||'')}${r.remarks?` · <span class="text-red-500">${esc(r.remarks)}</span>`:''}</td>
      <td class="rowact whitespace-nowrap">${payAct}</td>
    </tr>`;
  }).join('') || `<tr><td colspan="${CAN_VIEW_EXTRA?9:8}" class="text-center text-gray-400 py-4">No claims yet — click “New claim”.</td></tr>`;
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
  if (CAN_VIEW_EXTRA) loadAnalysis();
}

let _repChart;
async function loadAnalysis(){
  const dp = {}; const g=id=>document.getElementById(id).value;
  if(g('fFrom')) dp.from=g('fFrom'); if(g('fTo')) dp.to=g('fTo');
  const { data } = await apiGet('reimbursements.php', Object.assign({analysis:1}, dp));
  const rows = data.by_rep || [];
  document.getElementById('repTbl').innerHTML = rows.map(r=>
    `<tr><td class="font-medium">${esc(r.name)}</td><td class="text-right">${r.cnt}</td><td class="text-right font-semibold">${inr(r.total)}</td></tr>`
  ).join('') || '<tr><td colspan="3" class="text-gray-400 text-center py-2">No claims</td></tr>';
  document.getElementById('catChips').innerHTML = (data.by_category||[]).map(c=>
    `<span class="text-[10px] bg-indigo-50 text-indigo-700 rounded px-2 py-0.5">${esc(CAT_LABEL[c.category]||c.category)}: <b>${inr(c.total)}</b></span>`
  ).join('') || '<span class="text-[10px] text-gray-400">—</span>';
  if (_repChart) _repChart.destroy();
  const ctx = document.getElementById('cByRep'); if(!ctx || !window.Chart) return;
  _repChart = new Chart(ctx, { type:'bar', data:{
      labels: rows.map(r=>r.name),
      datasets:[
        { label:'Paid',     data: rows.map(r=>+r.paid),     backgroundColor:'#10b981' },
        { label:'Approved', data: rows.map(r=>+r.approved), backgroundColor:'#3b82f6' },
        { label:'Pending',  data: rows.map(r=>+r.pending),  backgroundColor:'#f59e0b' },
      ]},
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
      scales:{ x:{ stacked:true, beginAtZero:true, ticks:{ color:'#64748b' } }, y:{ stacked:true, ticks:{ color:'#334155' } } },
      plugins:{ legend:{ position:'bottom' } } } });
}

async function newClaim(){
  const leads = (await apiGet('reimbursements.php', { leads: 1 })).data || [];
  if (!leads.length){ openModal('<div class="text-xs text-gray-500">You have no leads in pursuit (all your leads are already merchants). A reimbursement must be linked to a lead that is not yet a merchant.</div>', 'New claim'); return; }
  const today = new Date().toISOString().slice(0,10);
  openModal(`<form onsubmit="saveClaim(event)" class="space-y-3 text-xs">
    <label class="block">Lead being pursued <span class="text-gray-400 font-normal">(not yet a merchant)</span>
      <select name="lead_id" required class="ctl w-full mt-1">
        <option value="">Select a lead…</option>
        ${leads.map(l=>`<option value="${l.id}">${esc((l.company||'')+' — '+((l.first_name||'')+' '+(l.last_name||'')).trim())} · ${esc(l.lead_status)}</option>`).join('')}
      </select></label>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Category
        <select name="category" class="ctl w-full mt-1">${Object.entries(CAT_LABEL).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select></label>
      <label class="block">Amount (₹)<input name="amount" type="number" step="0.01" min="0" required class="ctl w-full mt-1"></label>
      <label class="block">Expense date<input name="expense_date" type="date" value="${today}" class="ctl w-full mt-1"></label>
      <label class="block">Receipt no. / link<input name="receipt_ref" class="ctl w-full mt-1" placeholder="optional"></label>
    </div>
    <label class="block">Description<input name="description" class="ctl w-full mt-1" placeholder="e.g. Cab + hotel for Hyderabad merchant visit"></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Submit claim</button></div>
  </form>`, 'New reimbursement claim');
}
async function saveClaim(e){
  e.preventDefault(); const f = e.target;
  const body = { lead_id:+f.lead_id.value, category:f.category.value, amount:parseFloat(f.amount.value)||0,
                 expense_date:f.expense_date.value, description:f.description.value, receipt_ref:f.receipt_ref.value };
  if(!body.lead_id) return toast('Pick a lead', false);
  const res = await apiSend('reimbursements.php', body);
  if(res.success){ closeModal(); toast('Claim submitted for approval'); load(); }
  else toast(res.error||'Failed', false);
}
async function act(id, action){
  let extra = {};
  if(action.endsWith('reject')){ const r = prompt('Reason for denying this claim:',''); if(r===null) return; extra.remarks = r; }
  const res = await apiSend('reimbursements.php?'+action+'='+id, extra);
  if(res.success){ toast('Claim ' + (res.data.status === 'submitted' ? 'updated — waiting on the next approver' : res.data.status)); load(); }
  else toast(res.error||'Failed', false);
}
load();
</script>
</html>
