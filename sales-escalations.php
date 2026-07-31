<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$isManager = payg_role_can(sales_role(), 'records_all') || sales_role() === 'admin';
$canConfigure = sales_role() === 'admin' || payg_role_can(sales_role(), 'people');
sales_head('Escalations & SLA');
sales_header($me, 'Escalations & SLA');
sales_nav('escalations');
?>
<main class="p-2 space-y-2">

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div class="flex text-xs">
      <button id="tabBtnReport" onclick="switchTab('report')" class="px-3 py-1.5 rounded-l border border-indigo-600 bg-indigo-600 text-white font-semibold">Report</button>
      <button id="tabBtnLog" onclick="switchTab('log')" class="px-3 py-1.5 border-t border-b border-gray-300">Escalation Log</button>
      <button id="tabBtnMatrix" onclick="switchTab('matrix')" class="px-3 py-1.5 border border-gray-300">Escalation Matrix</button>
      <button id="tabBtnSla" onclick="switchTab('sla')" class="px-3 py-1.5 rounded-r border-t border-r border-b border-gray-300">SLA / TAT Policies</button>
    </div>
    <div class="flex-1"></div>
    <select id="fDays" class="ctl" onchange="loadReport()">
      <option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option>
      <option value="90">Last 90 days</option><option value="365">Last year</option>
    </select>
    <?php if ($isManager): ?>
    <button onclick="runSweep()" class="btn-p" title="Escalate every open task past its TAT right now"><i class="fa-solid fa-bolt"></i> Run sweep now</button>
    <?php endif; ?>
  </div>

  <!-- ============ REPORT TAB ============ -->
  <div id="tab-report" class="space-y-2">
    <div id="kpis" class="kstrip"></div>

    <!-- AI summary of WHY things escalate -->
    <div class="card p-3">
      <div class="flex items-center justify-between mb-1">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
          <i class="fa-solid fa-wand-magic-sparkles text-indigo-500"></i> AI summary — why tasks are escalating</span>
        <span class="flex items-center gap-2">
          <span id="aiMeta" class="text-[10px] text-gray-400"></span>
          <button onclick="loadAI(true)" class="text-[11px] text-indigo-600 hover:underline"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </span>
      </div>
      <div id="aiBox" class="text-xs text-gray-500">Loading…</div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
      <div class="card p-3">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Whose work escalates most <span class="normal-case font-normal text-gray-400">(original assignee)</span></div>
        <div class="overflow-x-auto"><table class="dense"><thead><tr>
          <th>Employee</th><th class="text-right">Tasks escalated</th><th class="text-right">Total hops</th><th class="text-right">Avg breach (h)</th>
        </tr></thead><tbody id="tblByEmp"></tbody></table></div>
      </div>
      <div class="card p-3">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Who is absorbing escalations</div>
        <div class="overflow-x-auto"><table class="dense"><thead><tr>
          <th>Receiver</th><th class="text-right">Received</th><th class="text-right">Still open</th>
        </tr></thead><tbody id="tblByRecv"></tbody></table></div>
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mt-3 mb-2">By level / by task type</div>
        <div id="levelChips" class="flex flex-wrap gap-1 mb-1"></div>
        <div id="typeChips" class="flex flex-wrap gap-1"></div>
      </div>
    </div>

    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Worst TAT breaches — with reasons</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Task</th><th>Assignee</th><th>Now with</th><th>Level</th><th class="text-right">Breached by</th><th>Status</th><th>Reason</th>
      </tr></thead><tbody id="tblWorst"></tbody></table></div>
    </div>
  </div>

  <!-- ============ LOG TAB ============ -->
  <div id="tab-log" class="hidden space-y-2">
    <div class="card p-2 flex items-center gap-2">
      <select id="fLogStatus" class="ctl" onchange="loadLog()">
        <option value="">All</option><option value="open">Open</option><option value="resolved">Resolved</option>
      </select>
      <span class="text-[10px] text-gray-400">Every escalation hop, newest first. Open = the task is still not done.</span>
    </div>
    <div class="card"><div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>When</th><th>Task</th><th>Level</th><th>From</th><th>To</th><th class="text-right">Breach (h)</th><th>Source</th><th>Status</th><th>Reason</th>
    </tr></thead><tbody id="tblLog"></tbody></table></div></div>
  </div>

  <!-- ============ MATRIX TAB ============ -->
  <div id="tab-matrix" class="hidden space-y-2">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1">How escalation works</div>
      <p class="text-xs text-gray-500">By default a breached task escalates to the assignee's <b>manager</b>, then the manager's manager, and so on until it reaches the <b>Admin panel</b>. Add a row below to <b>override</b> any hop for a specific employee (and optionally give that level its own TAT before the next hop).</p>
    </div>
    <div class="card p-3">
      <div class="flex items-center gap-2 flex-wrap mb-2">
        <select id="chainEmp" class="ctl min-w-[220px]" onchange="loadChain()"><option value="">Preview an employee's chain…</option></select>
        <div id="chainView" class="flex items-center gap-1 flex-wrap text-xs"></div>
      </div>
    </div>
    <?php if ($canConfigure): ?>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Custom overrides</div>
      <form onsubmit="saveMatrixRow(event)" class="flex items-end gap-2 flex-wrap mb-3 text-xs">
        <label class="block">Employee<select name="employee_id" required class="ctl block mt-1 min-w-[180px]"></select></label>
        <label class="block">Level<input name="level" type="number" min="1" max="10" value="1" required class="ctl block mt-1 w-16"></label>
        <label class="block">Escalate to<select name="escalate_to" required class="ctl block mt-1 min-w-[180px]"></select></label>
        <label class="block">TAT at this level (h)<input name="tat_hours" type="number" step="0.5" min="0.5" placeholder="policy default" class="ctl block mt-1 w-28"></label>
        <button class="btn-p">Save override</button>
      </form>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Employee</th><th>Level</th><th>Escalates to</th><th class="text-right">Level TAT (h)</th><th></th>
      </tr></thead><tbody id="tblMatrix"></tbody></table></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ============ SLA TAB ============ -->
  <div id="tab-sla" class="hidden space-y-2">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1">SLA / TAT policies</div>
      <p class="text-xs text-gray-500 mb-2">Every new task is stamped with the most specific active policy (type + priority beats type beats priority beats catch-all). When the TAT lapses and the task is still open, auto-escalation begins.</p>
      <?php if ($canConfigure): ?>
      <form onsubmit="savePolicy(event)" class="flex items-end gap-2 flex-wrap mb-2 text-xs">
        <label class="block">Name<input name="name" required class="ctl block mt-1 min-w-[190px]" placeholder="e.g. KYC — 8h TAT"></label>
        <label class="block">Task type<select name="task_type" class="ctl block mt-1">
          <option value="">Any</option><option value="kyc_validation">KYC Validation</option><option value="document_collection">Document Collection</option>
          <option value="follow_up">Follow-up</option><option value="callback">Callback</option><option value="review">Review</option>
          <option value="onboarding">Onboarding</option><option value="general">General</option></select></label>
        <label class="block">Priority<select name="priority" class="ctl block mt-1">
          <option value="">Any</option><option value="urgent">Urgent</option><option value="high">High</option>
          <option value="medium">Medium</option><option value="low">Low</option></select></label>
        <label class="block">TAT (h)<input name="tat_hours" type="number" step="0.5" min="0.5" value="24" required class="ctl block mt-1 w-20"></label>
        <label class="block">Re-escalate every (h)<input name="escalate_every_hours" type="number" step="0.5" min="0.5" placeholder="= TAT" class="ctl block mt-1 w-28"></label>
        <button class="btn-p">Add policy</button>
      </form>
      <?php endif; ?>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Policy</th><th>Task type</th><th>Priority</th><th class="text-right">TAT (h)</th><th class="text-right">Re-escalate (h)</th>
        <th class="text-right">Open tasks</th><th>Status</th><th></th>
      </tr></thead><tbody id="tblSla"></tbody></table></div>
    </div>
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
<script>
const IS_MANAGER = <?= $isManager ? 'true':'false' ?>;
const CAN_CONFIGURE = <?= $canConfigure ? 'true':'false' ?>;
let employees = [];

function switchTab(t){
  for (const k of ['report','log','matrix','sla']){
    document.getElementById('tab-'+k).classList.toggle('hidden', k!==t);
    const b = document.getElementById('tabBtn'+k[0].toUpperCase()+k.slice(1));
    b.className = b.className.replace(/bg-indigo-600 text-white font-semibold|border-indigo-600/g,'').trim();
    if (k===t) b.className += ' bg-indigo-600 text-white font-semibold border-indigo-600';
  }
  if (t==='log') loadLog();
  if (t==='matrix') loadMatrix();
  if (t==='sla') loadSla();
}

/* ---------- report ---------- */
async function loadReport(){
  if (!IS_MANAGER){ document.getElementById('kpis').innerHTML=''; return; }
  const days = document.getElementById('fDays').value;
  const { data } = await apiGet('escalations.php', { report:1, days });
  const t = data.totals || {};
  document.getElementById('kpis').innerHTML = [
    ['Escalation hops', t.total||0], ['Open now', t.open_now||0, (t.open_now||0)>0],
    ['Manual', t.manual_cnt||0], ['Avg breach', (t.avg_breach_h||0)+' h'],
    ['Worst breach', (t.max_breach_h||0)+' h'], ['Deepest level', 'L'+(t.deepest_level||0)],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('tblByEmp').innerHTML = (data.by_employee||[]).map(r=>`
    <tr><td class="font-medium">${esc(r.full_name||('#'+r.assigned_to))}<div class="text-[10px] text-gray-400">${esc(r.designation||'')}</div></td>
    <td class="text-right font-semibold">${r.tasks_escalated}</td><td class="text-right">${r.hops}</td>
    <td class="text-right ${(+r.avg_breach_h)>24?'text-red-600 font-semibold':''}">${r.avg_breach_h??'—'}</td></tr>`).join('')
    || '<tr><td colspan="4" class="text-center text-gray-400 py-3">No escalations in this window 🎉</td></tr>';
  document.getElementById('tblByRecv').innerHTML = (data.by_receiver||[]).map(r=>`
    <tr><td class="font-medium">${esc(r.full_name||('#'+r.to_employee_id))}</td>
    <td class="text-right">${r.received}</td>
    <td class="text-right ${(+r.still_open)>0?'text-amber-600 font-semibold':''}">${r.still_open}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-center text-gray-400 py-3">—</td></tr>';
  document.getElementById('levelChips').innerHTML = (data.by_level||[]).map(l=>
    `<span class="text-[10px] bg-indigo-50 text-indigo-700 rounded px-2 py-0.5">L${l.level}: <b>${l.cnt}</b>${(+l.open_cnt)?` (${l.open_cnt} open)`:''}</span>`).join('') || '<span class="text-[10px] text-gray-400">—</span>';
  document.getElementById('typeChips').innerHTML = (data.by_type||[]).map(x=>
    `<span class="text-[10px] bg-gray-100 text-gray-600 rounded px-2 py-0.5">${esc(x.task_type)}: <b>${x.cnt}</b> · avg ${x.avg_breach_h}h</span>`).join('') || '<span class="text-[10px] text-gray-400">—</span>';
  document.getElementById('tblWorst').innerHTML = (data.worst||[]).map(r=>`
    <tr><td class="font-medium max-w-[220px] truncate" title="${esc(r.title)}">${esc(r.title)}</td>
    <td>${esc(r.assignee_name||'')}</td><td>${esc(r.holder_name||'')}</td>
    <td>${badge('L'+r.level, r.level>=3?'red':r.level==2?'yellow':'blue')}</td>
    <td class="text-right font-semibold ${(+r.breached_by_hours)>24?'text-red-600':''}">${r.breached_by_hours??'—'} h</td>
    <td>${badge(r.status, r.status==='open'?'red':'green')}</td>
    <td class="text-[11px] text-gray-500 max-w-md truncate" title="${esc(r.reason||'')}">${esc(r.reason||'')}</td></tr>`).join('')
    || '<tr><td colspan="7" class="text-center text-gray-400 py-3">No breaches recorded</td></tr>';
}

async function loadAI(refresh){
  if (!IS_MANAGER) return;
  const box = document.getElementById('aiBox');
  box.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analysing escalation reasons…';
  try {
    const { data } = await apiGet('escalations.php', refresh ? { ai:1, refresh:1 } : { ai:1 });
    document.getElementById('aiMeta').textContent = data.cached ? ('cached · as of ' + (data.as_of||'')) : (data.insight ? 'live · just now' : '');
    const i = data.insight;
    if (!i){ box.innerHTML = '<span class="text-gray-400">'+esc(data.note || 'AI unavailable — showing raw numbers only (evidence-only mode).')+'</span>'; return; }
    const list = (arr,icon,cls)=> (arr||[]).map(x=>`<li class="flex gap-1.5"><i class="fa-solid ${icon} ${cls} mt-0.5"></i><span>${esc(x)}</span></li>`).join('');
    box.innerHTML = `
      <p class="text-gray-700 mb-2">${esc(i.summary||'')}</p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div><div class="text-[10px] uppercase text-gray-400 mb-1">Key findings</div><ul class="space-y-1">${list(i.key_findings,'fa-chart-simple','text-indigo-500')}</ul></div>
        <div><div class="text-[10px] uppercase text-gray-400 mb-1">Likely root causes</div><ul class="space-y-1">${list(i.root_causes,'fa-magnifying-glass','text-amber-500')}</ul></div>
        <div><div class="text-[10px] uppercase text-gray-400 mb-1">Recommended actions</div><ul class="space-y-1">${list(i.actions,'fa-circle-check','text-green-600')}</ul></div>
      </div>`;
  } catch(e){ box.innerHTML = '<span class="text-gray-400">AI summary unavailable.</span>'; }
}

async function runSweep(){
  const res = await apiSend('escalations.php?action=run_sweep', {});
  if (res.success){ const s=res.data; toast(`Sweep done — ${s.escalated} escalated, ${s.stamped} SLA-stamped, ${s.resolved} resolved`); loadReport(); }
  else toast(res.error||'Failed', false);
}

/* ---------- log ---------- */
async function loadLog(){
  const p = { list:1 }; const st = document.getElementById('fLogStatus').value; if (st) p.status = st;
  const { data } = await apiGet('escalations.php', p);
  document.getElementById('tblLog').innerHTML = (data||[]).map(r=>`
    <tr><td class="text-gray-500 whitespace-nowrap">${esc((r.created_at||'').slice(0,16))}</td>
    <td class="font-medium max-w-[220px] truncate" title="${esc(r.title)}">${esc(r.title)}</td>
    <td>${badge('L'+r.level, r.level>=3?'red':r.level==2?'yellow':'blue')}</td>
    <td>${esc(r.from_name||'')}</td><td class="font-medium">${esc(r.to_name||'')}</td>
    <td class="text-right">${r.breached_by_hours??'—'}</td>
    <td>${badge(r.source, r.source==='manual'?'purple':'gray')}</td>
    <td>${badge(r.status, r.status==='open'?'red':'green')}</td>
    <td class="text-[11px] text-gray-500 max-w-md truncate" title="${esc(r.reason||'')}">${esc(r.reason||'')}</td></tr>`).join('')
    || '<tr><td colspan="9" class="text-center text-gray-400 py-3">No escalations</td></tr>';
}

/* ---------- matrix ---------- */
async function loadEmployees(){
  if (employees.length) return employees;
  const { data } = await apiGet('sla.php', { employees:1 }).catch(()=>({data:[]}));
  employees = data || [];
  return employees;
}
async function loadMatrix(){
  const emps = await loadEmployees();
  const opts = '<option value="">Select…</option>' + emps.map(e=>`<option value="${e.id}">${esc(e.full_name)} — ${esc(e.designation||'')}</option>`).join('');
  const ce = document.getElementById('chainEmp');
  if (ce.options.length <= 1) ce.innerHTML = '<option value="">Preview an employee\'s chain…</option>' + emps.map(e=>`<option value="${e.id}">${esc(e.full_name)}</option>`).join('');
  document.querySelectorAll('select[name=employee_id], select[name=escalate_to]').forEach(s=>{ if(s.options.length<=1) s.innerHTML = opts; });
  if (!CAN_CONFIGURE) return;
  const { data } = await apiGet('sla.php', { matrix:1 });
  document.getElementById('tblMatrix').innerHTML = (data||[]).map(r=>`
    <tr><td class="font-medium">${esc(r.employee_name||('#'+r.employee_id))}</td>
    <td>${badge('L'+r.level,'blue')}</td><td>${esc(r.escalate_to_name||('#'+r.escalate_to))}</td>
    <td class="text-right">${r.tat_hours??'<span class="text-gray-400">policy</span>'}</td>
    <td class="rowact"><button onclick="delMatrixRow(${r.id})" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Remove</button></td></tr>`).join('')
    || '<tr><td colspan="5" class="text-center text-gray-400 py-3">No overrides — everyone follows the manager chain</td></tr>';
}
async function loadChain(){
  const emp = document.getElementById('chainEmp').value;
  const view = document.getElementById('chainView');
  if (!emp){ view.innerHTML=''; return; }
  const { data } = await apiGet('sla.php', { chain: emp });
  const P = data.people || {};
  const name = id => (P[id] && P[id].full_name) || ('#'+id);
  view.innerHTML = `<span class="bg-gray-100 rounded px-2 py-1">${esc(name(+emp))}</span>` + (data.chain||[]).map(h=>
    ` <i class="fa-solid fa-arrow-right text-gray-300"></i>
      <span class="${h.is_admin?'bg-red-50 text-red-700':'bg-indigo-50 text-indigo-700'} rounded px-2 py-1" title="${h.custom?'custom override':'via manager chain'}">
        L${h.level}: ${esc(name(h.employee_id))}${h.is_admin?' (Admin panel)':''}${h.custom?' ★':''}${h.tat_hours?(' · '+h.tat_hours+'h'):''}
      </span>`).join('') || '<span class="text-gray-400">No chain — assign a manager or add overrides.</span>';
}
async function saveMatrixRow(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('sla.php?matrix=1', { employee_id:+f.employee_id.value, level:+f.level.value,
    escalate_to:+f.escalate_to.value, tat_hours:f.tat_hours.value });
  if (res.success){ toast('Override saved'); loadMatrix(); loadChain(); } else toast(res.error||'Failed', false);
}
async function delMatrixRow(id){
  const res = await apiSend('sla.php?matrix_row='+id, {}, 'DELETE');
  if (res.success){ toast('Removed'); loadMatrix(); loadChain(); } else toast(res.error||'Failed', false);
}

/* ---------- SLA policies ---------- */
async function loadSla(){
  const { data } = await apiGet('sla.php', { policies:1 });
  document.getElementById('tblSla').innerHTML = (data||[]).map(p=>`
    <tr class="${+p.is_active?'':'opacity-50'}">
    <td class="font-medium">${esc(p.name)}</td>
    <td>${p.task_type?badge(p.task_type,'blue'):'<span class="text-gray-400">any</span>'}</td>
    <td>${p.priority?badge(p.priority, p.priority==='urgent'?'red':p.priority==='high'?'yellow':'gray'):'<span class="text-gray-400">any</span>'}</td>
    <td class="text-right font-semibold">${p.tat_hours}</td>
    <td class="text-right">${p.escalate_every_hours??'<span class="text-gray-400">= TAT</span>'}</td>
    <td class="text-right">${p.open_tasks}</td>
    <td>${badge(+p.is_active?'active':'inactive', +p.is_active?'green':'gray')}</td>
    <td class="rowact">${CAN_CONFIGURE?`
      <button onclick="togglePolicy(${p.id}, ${+p.is_active?0:1})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">${+p.is_active?'Disable':'Enable'}</button>`:''}</td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-3">No policies</td></tr>';
}
async function savePolicy(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('sla.php?policy=1', { name:f.name.value, task_type:f.task_type.value,
    priority:f.priority.value, tat_hours:+f.tat_hours.value, escalate_every_hours:f.escalate_every_hours.value });
  if (res.success){ toast('Policy added'); f.reset(); loadSla(); } else toast(res.error||'Failed', false);
}
async function togglePolicy(id, on){
  const res = await apiSend('sla.php?policy_id='+id, { is_active:on }, 'PATCH');
  if (res.success){ loadSla(); } else toast(res.error||'Failed', false);
}

loadReport(); loadAI(false);
</script>
</html>
