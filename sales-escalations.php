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
    <div id="mxStrip" class="flex gap-2 flex-wrap"></div>
    <div class="card p-2 flex items-center gap-2 flex-wrap">
      <select id="fMxSubject" class="ctl" onchange="filterMatrix()">
        <option value="">All subjects</option><option value="employee">People</option>
        <option value="role">Roles</option><option value="group">Groups</option></select>
      <select id="fMxStatus" class="ctl" onchange="filterMatrix()">
        <option value="">All status</option><option value="approved">Active</option>
        <option value="draft">Draft</option><option value="pending_review">In review</option></select>
      <input id="qMx" class="ctl" placeholder="Search person, role or group…" oninput="filterMatrix()" style="min-width:220px">
      <div class="flex-1"></div>
      <span class="text-[10px] text-gray-400">Person rules beat group rules, group beats role, role beats the manager chain.</span>
    </div>
    <?php if ($canConfigure): ?>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Custom overrides</div>
      <form onsubmit="saveMatrixRow(event)" class="flex items-end gap-2 flex-wrap mb-3 text-xs">
        <label class="block">Applies to<select name="subject_type" id="mSubType" onchange="fillWho('msub')" class="ctl block mt-1">
          <option value="employee">A person</option><option value="role">A role</option><option value="group">A group</option></select></label>
        <label class="block">Which<select name="subject_pick" id="mSubPick" class="ctl block mt-1 min-w-[170px]"></select></label>
        <label class="block">Level<input name="level" type="number" min="1" max="10" value="1" required class="ctl block mt-1 w-16"></label>
        <label class="block">Escalate to<select name="target_type" id="mTgtType" onchange="fillWho('mtgt')" class="ctl block mt-1">
          <option value="employee">A person</option><option value="role">A role</option><option value="group">A group</option></select></label>
        <label class="block">Which<select name="target_pick" id="mTgtPick" class="ctl block mt-1 min-w-[170px]"></select></label>
        <label class="block">TAT at this level (h)<input name="tat_hours" type="number" step="0.5" min="0.5" placeholder="policy default" class="ctl block mt-1 w-28"></label>
        <button class="btn-p">Save override</button>
      </form>
      <div class="overflow-x-auto"><table class="dense" id="matrixGrid"><thead><tr id="mgHead"></tr></thead><tbody id="tblMatrix"></tbody></table></div>
      <div class="text-[10px] text-gray-400 mt-2">One row per person, role or group — every level side by side.
        Click a level cell to set or change who it escalates to; an empty cell falls back to the default manager chain.</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ============ SLA TAB ============ -->
  <div id="tab-sla" class="hidden space-y-2">
    <div class="flex items-start gap-3 flex-wrap">
      <div>
        <div class="text-sm font-bold text-gray-700 tracking-wide">SLA / TAT POLICIES</div>
        <div class="text-[11px] text-gray-500">Define Service Level Agreement (SLA) policies used for KPIs and processes.</div>
      </div>
      <div class="flex-1"></div>
      <span id="slaCycle" class="text-[11px]" style="background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;border-radius:8px;padding:3px 10px"></span>
      <?php if ($canConfigure): ?>
      <button onclick="openPolicy()" class="btn-p"><i class="fa-solid fa-plus"></i> New SLA Policy</button>
      <?php endif; ?>
    </div>

    <div id="slaStrip" class="flex gap-2 flex-wrap"></div>

    <div class="card p-2 flex items-center gap-2 flex-wrap">
      <select id="fSlaType" class="ctl" onchange="loadSla()"><option value="">All SLA types</option></select>
      <select id="fSlaDept" class="ctl" onchange="loadSla()"><option value="">All departments</option></select>
      <select id="fSlaStatus" class="ctl" onchange="loadSla()">
        <option value="">All status</option><option value="active">Active</option>
        <option value="draft">Draft</option><option value="archived">Archived</option>
      </select>
      <input id="qSla" class="ctl" placeholder="Search policy or description…" oninput="slaDebounced()" style="min-width:220px">
      <div class="flex-1"></div>
      <span class="text-[10px] text-gray-400">Most specific policy wins: person &gt; group &gt; role &gt; sub-dept &gt; dept &gt; type/priority.</span>
    </div>

    <div class="card">
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>SLA Code</th><th>Policy Name</th><th>SLA Type</th><th>Department</th><th>Sub Dept</th>
        <th>Applies To</th><th>Description</th><th>TAT (Deadline)</th><th>Working Hours</th>
        <th>Escalation Matrix</th><th class="text-right">Open</th><th>Status</th><th>Last Updated</th><th>Action</th>
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

<style>.gcode{font-family:ui-monospace,Consolas,monospace;font-size:10px;font-weight:700;background:#eef2ff;color:#4338ca;border-radius:5px;padding:1px 5px;white-space:nowrap}</style>
<style>
  .kstat { border:1px solid #e5e7eb; border-radius:10px; padding:8px 12px; background:#fff; min-width:138px; }
  .kstat .kt { font-size:10px; color:#9ca3af; }
  .kstat .kn { font-size:18px; font-weight:700; color:#1f2937; line-height:1.15; }
  .kstat .ks { font-size:10px; color:#9ca3af; }
</style>
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
    <td class="font-medium max-w-[220px] truncate" title="${esc(r.title)}">${r.entity==='ticket'?badge('ticket','purple')+' ':''}${+r.to_board?badge('BOARD','red')+' ':''}${esc(r.title)}</td>
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
  const ce = document.getElementById('chainEmp');
  if (ce.options.length <= 1) ce.innerHTML = '<option value="">Preview an employee&apos;s chain…</option>' +
      emps.map(e => `<option value="${e.id}">${esc(e.full_name)}</option>`).join('');
  if (!CAN_CONFIGURE) return;
  const { data } = await apiGet('sla.php', { matrix_grid:1 });
  MX_DATA = data;
  const maxLevel = Math.max(3, Math.min(6, data.max_level || 3));   // always show at least L1-L3
  MG_MAX = maxLevel;
  document.getElementById('mgHead').innerHTML =
    '<th>Matrix</th><th>Applies to</th><th>Department</th>'
    + Array.from({length:maxLevel}, (_,i) => `<th>L${i+1} &mdash; escalates to</th>`).join('')
    + '<th class="text-right">Used</th><th>Status</th><th>Last updated</th><th></th>';

  const args = (sj, lvl) => `'${sj.subject_type}',${sj.employee_id||'null'},'${(sj.subject_role||'').replace(/'/g,'')}',${sj.subject_group_id||'null'},${lvl}`;
  const cell = (sj, lvl) => {
    const L = sj.levels[lvl];
    if (!L) return `<td class="text-center"><button onclick="setLevel(${args(sj,lvl)})"
        class="text-[10px] text-gray-400 hover:text-indigo-600 border border-dashed border-gray-300 rounded px-2 py-0.5">+ set</button></td>`;
    return `<td>
      <div class="flex items-center gap-1"><span class="font-medium text-[11px]">${esc(L.target_label)}</span>
        ${L.target_type !== 'employee' ? badge(L.target_type,'purple') : ''}</div>
      <div class="text-[9px] text-gray-400"><span class="gcode">${esc(L.esc_ref)}</span> v${L.version}
        ${L.tat_hours ? ' &middot; TAT ' + L.tat_hours + 'h' : ' &middot; policy TAT'}</div>
      <div class="text-[9px]"><a onclick="setLevel(${args(sj,lvl)})" class="text-indigo-600 cursor-pointer">edit</a>
        &middot; <a onclick="delMatrixRow(${L.id})" class="text-red-500 cursor-pointer">remove</a></div></td>`;
  };

  const subjects = (data.subjects||[]).filter(sj => {
    const st = document.getElementById('fMxSubject'), ss = document.getElementById('fMxStatus'), q = document.getElementById('qMx');
    if (st && st.value && sj.subject_type !== st.value) return false;
    if (ss && ss.value) {
      const lv = Object.values(sj.levels), bad = lv.find(l => l.status !== 'approved');
      if ((bad ? bad.status : 'approved') !== ss.value) return false;
    }
    if (q && q.value && !(sj.label||'').toLowerCase().includes(q.value.toLowerCase())) return false;
    return true;
  });
  const tiles = (data.subjects||[]);
  const lvOf = sj => Object.values(sj.levels);
  renderStrip('mxStrip', [
    ['📋','Total Matrices', tiles.length, 'All subjects'],
    ['✅','Active Matrices', tiles.filter(x=>!lvOf(x).some(l=>l.status!=='approved')).length, '%'],
    ['⏳','Draft / in review', tiles.filter(x=>lvOf(x).some(l=>l.status!=='approved')).length, '%'],
    ['🔢','Total Levels', tiles.reduce((a,x)=>a+lvOf(x).length,0), 'across all matrices'],
  ], tiles.length);
  document.getElementById('tblMatrix').innerHTML = subjects.map(sj => {
    const lv = Object.values(sj.levels);
    const bad = lv.find(l => l.status !== 'approved');
    const st = bad ? bad.status : 'approved';
    return `<tr>
      <td><span class="gcode">${esc(sj.codes[0]||'—')}</span>${sj.codes.length>1?`<div class="text-[9px] text-gray-400">+${sj.codes.length-1} more</div>`:''}</td>
      <td class="font-medium">${esc(sj.label)}${sj.subject_type!=='employee'?' '+badge(sj.subject_type,'purple'):''}</td>
      <td class="text-[11px] text-gray-500">${esc(sj.department||'—')}${sj.sub_department?'<div class="text-[10px] text-gray-400">'+esc(sj.sub_department)+'</div>':''}</td>
      ${Array.from({length:maxLevel}, (_,i) => cell(sj, i+1)).join('')}
      <td class="text-right text-[11px]">${sj.times_used||0}</td>
      <td>${badge(st==='approved'?'active':st.replace('_',' '), st==='approved'?'green':st==='rejected'?'red':'yellow')}</td>
      <td class="text-[10px] text-gray-400">${esc((sj.last_change||'').slice(0,16))}${sj.last_change_by?'<div>'+esc(sj.last_change_by)+'</div>':''}</td>
      <td class="rowact"><button onclick="addLevelFor('${sj.subject_type}',${sj.employee_id||'null'},'${(sj.subject_role||'').replace(/'/g,'')}',${sj.subject_group_id||'null'})"
            class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">+ level</button></td>
    </tr>`;
  }).join('') || `<tr><td colspan="${maxLevel+7}" class="text-center text-gray-400 py-3">No custom rules — everyone follows the default manager chain</td></tr>`;
}
let MG_MAX = 3, MX_DATA = null;
function filterMatrix(){ if (MX_DATA) loadMatrix(); }

/* set or change ONE level for ONE subject, straight from its grid cell */
function setLevel(subjectType, empId, role, groupId, level){
  const who = subjectType === 'role'  ? 'Role: ' + role
            : subjectType === 'group' ? 'Group: ' + (((TARGETS.groups||[]).find(g => +g.id === +groupId)||{}).name || '')
            : (((TARGETS.people||[]).find(p => +p.id === +empId)||{}).full_name || '');
  openModal(`<form onsubmit="saveLevel(event,'${subjectType}',${empId||'null'},'${role||''}',${groupId||'null'},${level})" class="space-y-2 text-xs">
      <div class="text-gray-500">Level <b>L${level}</b> for <b>${esc(who||'—')}</b></div>
      <label class="block">Escalate to
        <select name="target_type" onchange="fillModalPick(this)" class="ctl w-full mt-0.5">
          <option value="employee">A person</option><option value="role">A role</option><option value="group">A group</option>
        </select></label>
      <label class="block">Which<select name="target_pick" id="modalPick" class="ctl w-full mt-0.5">${whoOptions('employee')}</select></label>
      <label class="block">TAT at this level (hours)
        <input name="tat_hours" type="number" step="0.5" min="0.5" placeholder="blank = use the policy TAT" class="ctl w-full mt-0.5"></label>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save level</button></div>
    </form>`, 'Escalation level');
}
function fillModalPick(sel){ document.getElementById('modalPick').innerHTML = whoOptions(sel.value); }
function addLevelFor(subjectType, empId, role, groupId){
  const next = prompt('Which level? (1-10)', String(MG_MAX + 1));
  if (!next) return;
  setLevel(subjectType, empId, role, groupId, Math.max(1, Math.min(10, +next || 1)));
}
async function saveLevel(e, subjectType, empId, role, groupId, level){
  e.preventDefault(); const f = e.target;
  const tt = f.target_type.value;
  const body = { level, subject_type: subjectType, target_type: tt, tat_hours: f.tat_hours.value };
  if (subjectType === 'employee') body.employee_id = empId;
  if (subjectType === 'role')     body.subject_role = role;
  if (subjectType === 'group')    body.subject_group_id = groupId;
  if (tt === 'employee') body.escalate_to = +f.target_pick.value;
  if (tt === 'role')     body.target_role = f.target_pick.value;
  if (tt === 'group')    body.target_group_id = +f.target_pick.value;
  const res = await apiSend('sla.php?matrix=1', body);
  if (res.success){ toast('Level saved · ' + (res.data.code||'')); closeModal(); loadMatrix(); loadChain(); }
  else toast(res.error||'Failed', false);
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
      <span class="${h.is_board?'bg-red-600 text-white font-semibold':h.is_admin?'bg-red-50 text-red-700':'bg-indigo-50 text-indigo-700'} rounded px-2 py-1" title="${h.is_board?'final stop — all Board members are alerted':h.custom?'custom override':'via manager chain'}">
        L${h.level}: ${h.is_board?'THE BOARD 👑':esc(name(h.employee_id))+(h.is_admin?' (Admin panel)':'')}${h.custom?' ★':''}${h.tat_hours?(' · '+h.tat_hours+'h'):''}
      </span>`).join('') || '<span class="text-gray-400">No chain — assign a manager or add overrides.</span>';
}
async function saveMatrixRow(e){
  e.preventDefault(); const f = e.target;
  const st = f.subject_type.value, tt = f.target_type.value;
  const body = { level:+f.level.value, tat_hours:f.tat_hours.value, subject_type:st, target_type:tt };
  if (st === 'employee') body.employee_id = +f.subject_pick.value;
  if (st === 'role')     body.subject_role = f.subject_pick.value;
  if (st === 'group')    body.subject_group_id = +f.subject_pick.value;
  if (tt === 'employee') body.escalate_to = +f.target_pick.value;
  if (tt === 'role')     body.target_role = f.target_pick.value;
  if (tt === 'group')    body.target_group_id = +f.target_pick.value;
  const res = await apiSend('sla.php?matrix=1', body);
  if (res.success){ toast('Rule saved · ' + (res.data.code||'')); loadMatrix(); loadChain(); }
  else toast(res.error||'Failed', false);
}

/* ---- person / role / group pickers, shared by both forms ---- */
let TARGETS = { people:[], roles:[], groups:[] };
async function loadTargets(){
  const res = await apiGet('sla.php', { targets:1 }).catch(()=>null);
  if (res && res.success) TARGETS = res.data;
  ['sla','msub','mtgt'].forEach(fillWho);
}
function whoOptions(kind){
  if (kind === 'employee') return TARGETS.people.map(p=>`<option value="${p.id}">${esc(p.full_name)}${p.designation?' · '+esc(p.designation):''}</option>`).join('');
  if (kind === 'role')     return TARGETS.roles.map(r=>`<option value="${esc(r.key)}">${esc(r.label)}</option>`).join('');
  if (kind === 'group')    return TARGETS.groups.map(g=>`<option value="${g.id}">${esc(g.name)} (${g.members})</option>`).join('');
  return '';
}
function fillWho(which){
  const map = { sla:['slaWho','slaWhoPick','slaWhoWrap'], msub:['mSubType','mSubPick',null], mtgt:['mTgtType','mTgtPick',null] };
  const [typeId, pickId, wrapId] = map[which] || [];
  const t = document.getElementById(typeId), pick = document.getElementById(pickId);
  if (!t || !pick) return;
  const kind = t.value;
  if (wrapId) document.getElementById(wrapId).style.display = (kind === 'all') ? 'none' : '';
  pick.innerHTML = whoOptions(kind);
}
function appliesLabel(p){
  if (!p.applies_to_type || p.applies_to_type === 'all') return '';
  if (p.applies_to_type === 'role') {
    const r = (TARGETS.roles||[]).find(x => x.key === p.applies_to_role);
    return 'Role: ' + (r ? r.label : p.applies_to_role);
  }
  if (p.applies_to_type === 'group') {
    const g = (TARGETS.groups||[]).find(x => +x.id === +p.applies_to_id);
    return 'Group: ' + (g ? g.name : '#' + p.applies_to_id);
  }
  const e = (TARGETS.people||[]).find(x => +x.id === +p.applies_to_id);
  return 'Person: ' + (e ? e.full_name : '#' + p.applies_to_id);
}
async function delMatrixRow(id){
  const res = await apiSend('sla.php?matrix_row='+id, {}, 'DELETE');
  if (res.success){ toast('Removed'); loadMatrix(); loadChain(); } else toast(res.error||'Failed', false);
}

/* ---------- SLA policies ---------- */

/* Figma list pattern: four tiles, each with an icon, a count and a sub-line. */
function renderStrip(elId, tiles, total){
  const el = document.getElementById(elId); if (!el) return;
  const pct = n => total ? ((n/total)*100).toFixed(2) + '%' : '0%';
  el.innerHTML = tiles.map(([ic,label,val,sub]) => `<div class="kstat">
      <div class="kt">${ic} ${esc(label)}</div><div class="kn">${val}</div>
      <div class="ks">${esc(sub === '%' ? pct(val) : sub)}</div></div>`).join('');
}

let SLA_META = { sla_types: [], tat_bases: {} }, SLA_ROWS = [], slaTmr = null;
const slaDebounced = () => { clearTimeout(slaTmr); slaTmr = setTimeout(loadSla, 400); };

async function loadSla(){
  const p = { policies:1, status: (document.getElementById('fSlaStatus')||{}).value || '',
              sla_type: (document.getElementById('fSlaType')||{}).value || '',
              department: (document.getElementById('fSlaDept')||{}).value || '',
              q: (document.getElementById('qSla')||{}).value || '' };
  const { data } = await apiGet('sla.php', p);
  SLA_ROWS = data.rows || []; SLA_META = data;
  const cyc = document.getElementById('slaCycle');
  if (cyc && !cyc.textContent) {
    const y = new Date().getMonth() >= 3 ? new Date().getFullYear() : new Date().getFullYear() - 1;
    cyc.textContent = `Appraisal Cycle: FY ${y}-${String(y+1).slice(2)} (Apr ${y} – Mar ${y+1}) · OPEN`;
  }
  const ft = document.getElementById('fSlaType');
  if (ft && ft.options.length <= 1)
    ft.innerHTML = '<option value="">All SLA types</option>' + (data.sla_types||[]).map(t=>`<option>${esc(t)}</option>`).join('');
  const fd = document.getElementById('fSlaDept');
  if (fd && fd.options.length <= 1)
    fd.innerHTML = '<option value="">All departments</option>' + ORG_DEPTS.map(d=>`<option>${esc(d)}</option>`).join('');

  const t = data.totals || {};
  const pct = n => t.total ? ((n/t.total)*100).toFixed(2) + '%' : '0%';
  document.getElementById('slaStrip').innerHTML = [
    ['📋','Total SLA Policies', t.total||0, 'All Policies'],
    ['✅','Active Policies', t.active_cnt||0, pct(t.active_cnt||0)],
    ['⏳','Draft Policies', t.draft_cnt||0, pct(t.draft_cnt||0)],
    ['📦','Archived Policies', t.archived_cnt||0, pct(t.archived_cnt||0)],
  ].map(([ic,l,v,sub]) => `<div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px;background:#fff;min-width:140px">
      <div class="text-[10px] text-gray-400">${ic} ${esc(l)}</div>
      <div class="text-lg font-bold text-gray-800">${v}</div>
      <div class="text-[10px] text-gray-400">${esc(sub)}</div></div>`).join('');

  document.getElementById('tblSla').innerHTML = SLA_ROWS.map(p=>`
    <tr class="${p.status!=='active'?'opacity-60':''}">
      <td><span class="gcode">${esc(p.sla_ref||('SLA-'+p.id))}</span><div class="text-[9px] text-gray-400">v${p.version||1}</div></td>
      <td class="font-medium">${esc(p.name)} ${badge(p.entity==='ticket'?'ticket':'task', p.entity==='ticket'?'purple':'blue')}</td>
      <td>${p.sla_type?badge(p.sla_type,'blue'):'<span class="text-gray-300">—</span>'}</td>
      <td class="text-[11px]">${esc(p.department||'—')}</td>
      <td class="text-[11px] text-gray-500">${esc(p.sub_department||'—')}</td>
      <td class="text-[11px]">${esc(p.applies_label||'Everyone')}</td>
      <td class="text-[11px] text-gray-600">${esc((p.description||'').slice(0,54))}</td>
      <td class="text-[11px] font-medium">${esc(p.tat_label||(p.tat_hours+'h'))}
        ${p.escalate_every_hours?`<div class="text-[9px] text-gray-400">re-escalate ${p.escalate_every_hours}h</div>`:''}</td>
      <td class="text-[11px]">${esc(p.hours_label||'24x7')}</td>
      <td class="text-[10px]">${p.matrix_codes?esc(p.matrix_codes):'<span class="text-gray-300">not linked</span>'}</td>
      <td class="text-right">${p.open_tasks}</td>
      <td>${badge(p.status, p.status==='active'?'green':p.status==='draft'?'yellow':'gray')}</td>
      <td class="text-[10px] text-gray-400">${esc((p.last_change||p.updated_at||'').slice(0,16))}
        ${p.last_change_by?'<div>'+esc(p.last_change_by)+'</div>':''}</td>
      <td class="rowact">${CAN_CONFIGURE?`
        <button onclick="openPolicy(${p.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded mr-1">Edit</button>
        <button onclick="setPolicyStatus(${p.id},'${p.status==='archived'?'active':'archived'}')"
          class="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded">${p.status==='archived'?'Restore':'Archive'}</button>`:''}</td>
    </tr>`).join('') || '<tr><td colspan="14" class="text-center text-gray-400 py-3">No policies match</td></tr>';
}

/* ---- create / edit a policy, with TAT basis + working hours ---- */
function openPolicy(id){
  const p = id ? SLA_ROWS.find(x=>+x.id===+id) : null;
  const bases = SLA_META.tat_bases || { hours:'Hours' };
  const sel = (n, opts, val, blank) => `<select name="${n}" class="ctl w-full mt-0.5">${blank?'<option value="">—</option>':''}
    ${Object.entries(opts).map(([k,v])=>`<option value="${esc(k)}" ${String(k)===String(val||'')?'selected':''}>${esc(v)}</option>`).join('')}</select>`;
  const arr = a => Object.fromEntries(a.map(x=>[x,x]));
  openModal(`<form onsubmit="savePolicy(event, ${id||'null'})" class="grid grid-cols-2 gap-2 text-xs">
      <label class="block col-span-2">Policy name<input name="name" required value="${esc(p?p.name:'')}" class="ctl w-full mt-0.5"></label>
      <label class="block col-span-2">Description<textarea name="description" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(p?p.description:'')}</textarea></label>
      <label class="block">Applies to entity${sel('entity', {task:'Tasks', ticket:'Tickets'}, p?p.entity:'task', false)}</label>
      <label class="block">SLA type${sel('sla_type', arr(SLA_META.sla_types||[]), p?p.sla_type:'', true)}</label>
      <label class="block">Department${sel('department', arr(ORG_DEPTS), p?p.department:'', true)}</label>
      <label class="block">Sub-department<input name="sub_department" value="${esc(p?p.sub_department:'')}" class="ctl w-full mt-0.5"></label>
      <label class="block">Task type${sel('task_type', arr(['kyc_validation','document_collection','follow_up','callback','review','onboarding','general']), p?p.task_type:'', true)}</label>
      <label class="block">Priority${sel('priority', arr(['urgent','high','medium','low']), p?p.priority:'', true)}</label>
      <div class="col-span-2 border-t pt-2 text-[10px] uppercase tracking-wide text-gray-400">Turnaround</div>
      <label class="block">TAT measured in${sel('tat_basis', bases, p?p.tat_basis:'hours', false)}</label>
      <label class="block">TAT value<input name="tat_value" type="number" step="0.5" min="0.5" value="${p?(p.tat_value||p.tat_hours):24}" class="ctl w-full mt-0.5"></label>
      <label class="block">Clock time (for T+n)<input name="tat_clock" type="time" value="${esc(p&&p.tat_clock?String(p.tat_clock).slice(0,5):'')}" class="ctl w-full mt-0.5"></label>
      <label class="block">T+n days<input name="tat_offset_days" type="number" min="0" max="30" value="${p?(p.tat_offset_days||1):1}" class="ctl w-full mt-0.5"></label>
      <label class="block">Re-escalate every (h)<input name="escalate_every_hours" type="number" step="0.5" value="${esc(p?p.escalate_every_hours:'')}" class="ctl w-full mt-0.5"></label>
      <label class="block">Status${sel('status', {active:'Active', draft:'Draft', archived:'Archived'}, p?p.status:'active', false)}</label>
      <div class="col-span-2 border-t pt-2 text-[10px] uppercase tracking-wide text-gray-400">Working hours
        <span class="normal-case">— the TAT clock only runs inside these</span></div>
      <label class="flex items-center gap-2 col-span-2"><input type="checkbox" name="is_24x7" ${(!p||+p.is_24x7)?'checked':''}
        onchange="document.getElementById('whBox').style.display=this.checked?'none':''"> Runs 24x7</label>
      <div id="whBox" class="col-span-2 grid grid-cols-3 gap-2" style="display:${(!p||+p.is_24x7)?'none':''}">
        <label class="block col-span-1">Days<input name="work_days" value="${esc(p?p.work_days:'Mon,Tue,Wed,Thu,Fri')}" class="ctl w-full mt-0.5"></label>
        <label class="block">Opens<input name="work_start" type="time" value="${esc(p?String(p.work_start).slice(0,5):'09:00')}" class="ctl w-full mt-0.5"></label>
        <label class="block">Closes<input name="work_end" type="time" value="${esc(p?String(p.work_end).slice(0,5):'18:00')}" class="ctl w-full mt-0.5"></label>
      </div>
      <div class="col-span-2 flex justify-end gap-2 pt-1">
        <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">${id?'Save changes':'Create policy'}</button></div>
    </form>`, id ? 'Edit SLA policy' : 'New SLA policy');
}
async function savePolicy(e, id){
  e.preventDefault(); const f = e.target;
  const body = {};
  [...f.elements].forEach(el => { if (!el.name) return;
    body[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
  body.tat_hours = +body.tat_value || 24;
  const res = id ? await apiSend('sla.php?policy_id=' + id, body, 'PATCH')
                 : await apiSend('sla.php?policy=1', body);
  if (res.success){ toast(id ? 'Policy saved' : 'Policy added · ' + (res.data.code||'')); closeModal(); loadSla(); }
  else toast(res.error||'Failed', false);
}
async function setPolicyStatus(id, status){
  const res = await apiSend('sla.php?policy_id=' + id, { status }, 'PATCH');
  if (res.success){ toast(status === 'archived' ? 'Archived' : 'Restored'); loadSla(); } else toast(res.error||'Failed', false);
}

/* department / sub-department pickers, fed by the org masters */
let ORG_DEPTS = [];
async function loadDeptPickers(){
  const sel = document.getElementById('slaDept');
  if (!sel) return;                                  // viewer without configure rights
  const res = await apiGet('org-masters.php');
  ORG_DEPTS = (res.data && res.data.departments) || [];
  const names = [...new Set(ORG_DEPTS.map(d => d.department))];
  sel.innerHTML = '<option value="">All departments</option>' + names.map(n=>`<option>${esc(n)}</option>`).join('');
  fillSubDepts();
}
function fillSubDepts(){
  const dept = document.getElementById('slaDept')?.value || '';
  const sub = document.getElementById('slaSubDept');
  if (!sub) return;
  const subs = [...new Set(ORG_DEPTS.filter(d => d.department === dept && d.sub_department).map(d => d.sub_department))];
  sub.innerHTML = '<option value="">All</option>' + subs.map(s=>`<option>${esc(s)}</option>`).join('');
}
loadDeptPickers(); loadTargets();
async function togglePolicy(id, on){
  const res = await apiSend('sla.php?policy_id='+id, { is_active:on }, 'PATCH');
  if (res.success){ loadSla(); } else toast(res.error||'Failed', false);
}

loadReport(); loadAI(false);
// deep-link support for the sidebar's SLA / Escalation Matrices entries (?tab=sla|matrix|log|report)
(function(){ const t = new URLSearchParams(location.search).get('tab');
  if (t && ['report','log','matrix','sla'].includes(t)) switchTab(t); })();
</script>
</html>
