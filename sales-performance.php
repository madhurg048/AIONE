<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Performance');
sales_header($me, 'Performance');
sales_nav('performance');
// Managers/heads/admin get the aggregated view; everyone else sees their own record.
$isLead = payg_role_tier(sales_role()) >= PAYG_TIER_MANAGER || sales_role() === 'admin';
?>
<style>
  .pbar { height:6px; border-radius:3px; background:#e5e7eb; overflow:hidden; min-width:70px }
  .pbar > span { display:block; height:100%; }
  .gcode{font-family:ui-monospace,Consolas,monospace;font-size:10px;font-weight:700;border-radius:5px;padding:1px 5px;white-space:nowrap}
  .c-sla{background:#fef3c7;color:#92400e}.c-esc{background:#fee2e2;color:#b91c1c}.c-esl{background:#ffe4e6;color:#9f1239}
  .c-kpa{background:#eef2ff;color:#4338ca}.c-kra{background:#f0f9ff;color:#0369a1}
</style>
<main class="p-3 space-y-2">

  <div class="flex items-center gap-3 flex-wrap">
    <span class="text-sm font-bold text-gray-700 tracking-wide">PERFORMANCE</span>
    <span id="scopeLbl" class="text-xs text-gray-400"></span>
    <div class="flex-1"></div>
    <select id="days" onchange="load()" class="ctl">
      <option value="30">Last 30 days</option><option value="90" selected>Last 90 days</option>
      <option value="180">Last 6 months</option><option value="365">Last year</option>
    </select>
  </div>

  <div class="text-[10px] text-gray-400">
    Built automatically from TAT/SLA outcomes and escalations — no one keys these numbers in.
    A missed TAT or an escalation on someone's work lands on their record the moment it happens.
  </div>

<?php if ($isLead): ?>
  <!-- ================= AGGREGATE (team lead / head / admin) ================= -->
  <div id="kpis" class="kstrip"></div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">By department</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Department</th><th class="text-right">People</th><th>TAT adherence</th>
        <th class="text-right">Breached</th><th class="text-right">Escalated</th><th class="text-right">Avg score</th><th>Bands</th>
      </tr></thead><tbody id="deptRows"></tbody></table></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">By team (reporting line)</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Team lead</th><th class="text-right">People</th><th>TAT adherence</th>
        <th class="text-right">Breached</th><th class="text-right">Escalated</th><th class="text-right">Avg score</th><th>Bands</th>
      </tr></thead><tbody id="teamRows"></tbody></table></div>
    </div>
  </div>

  <div class="card p-3">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">People — weakest first</span>
      <input id="q" oninput="renderPeople()" placeholder="Search…" class="ctl ml-auto" style="min-width:200px">
      <select id="bandF" onchange="renderPeople()" class="ctl">
        <option value="">All bands</option><option value="at risk">At risk</option>
        <option value="watch">Watch</option><option value="on track">On track</option>
      </select>
    </div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Person</th><th>Department</th><th>Reports to</th><th>TAT adherence</th>
      <th class="text-right">Met</th><th class="text-right">Breached</th><th class="text-right">Escalations</th>
      <th class="text-right">To board</th><th class="text-right">KPAs</th><th>Score</th><th></th>
    </tr></thead><tbody id="peopleRows"></tbody></table></div>
  </div>
<?php endif; ?>

  <!-- ================= INDIVIDUAL ================= -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2" id="oneTitle">My performance</div>
    <div id="one"></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_LEAD = <?= $isLead ? 'true' : 'false' ?>;
let TEAM = null;

const bandColor = b => b === 'at risk' ? 'red' : b === 'watch' ? 'yellow' : 'green';
const scoreColor = s => s >= 85 ? '#16a34a' : s >= 65 ? '#f59e0b' : '#dc2626';
function bar(pct, color){
  const v = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct));
  return `<div class="flex items-center gap-1.5"><div class="pbar"><span style="width:${v}%;background:${color||scoreColor(v)}"></span></div>
          <span class="text-[10px] ${pct===null?'text-gray-400':''}">${pct===null||pct===undefined?'no SLA work':v+'%'}</span></div>`;
}
function bands(r){
  return `<span class="text-[10px]">${r.at_risk?`<span class="text-red-600 font-semibold">${r.at_risk} at risk</span> · `:''}`
       + `${r.watch?`<span class="text-amber-600">${r.watch} watch</span> · `:''}`
       + `<span class="text-green-600">${r.on_track} ok</span></span>`;
}

async function load(){
  const days = document.getElementById('days').value;
  if (IS_LEAD) {
    const { data } = await apiGet('performance.php', { team:1, days });
    TEAM = data;
    document.getElementById('scopeLbl').textContent =
      `· ${data.level} view · ${data.summary.people} people · last ${data.window_days} days`;
    const s = data.summary;
    document.getElementById('kpis').innerHTML = [
      ['TAT adherence', s.adherence_pct === null ? '—' : s.adherence_pct + '%', s.adherence_pct !== null && s.adherence_pct < 90],
      ['Items under SLA', s.with_sla],
      ['Breached', s.breached, s.breached > 0],
      ['Escalated items', s.escalated_items, s.escalated_items > 0],
      ['Still open', s.still_open, s.still_open > 0],
      ['Reached the Board', s.to_board, s.to_board > 0],
      ['Avg score', s.avg_score ?? '—'],
      ['At risk', s.at_risk, s.at_risk > 0],
    ].map(([l,v,warn]) => `<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

    document.getElementById('deptRows').innerHTML = (data.by_department||[]).map(d => `
      <tr><td class="font-medium">${esc(d.department)}</td><td class="text-right">${d.people}</td>
        <td>${bar(d.adherence_pct)}</td>
        <td class="text-right ${d.breached>0?'text-red-600 font-semibold':''}">${d.breached}</td>
        <td class="text-right">${d.escalated_items}</td>
        <td class="text-right font-bold" style="color:${scoreColor(d.avg_score||0)}">${d.avg_score ?? '—'}</td>
        <td>${bands(d)}</td></tr>`).join('') || '<tr><td colspan="7" class="text-gray-400 text-center py-2">No data</td></tr>';

    document.getElementById('teamRows').innerHTML = (data.by_team||[]).map(t => `
      <tr><td class="font-medium">${esc(t.manager)}</td><td class="text-right">${t.people}</td>
        <td>${bar(t.adherence_pct)}</td>
        <td class="text-right ${t.breached>0?'text-red-600 font-semibold':''}">${t.breached}</td>
        <td class="text-right">${t.escalated_items}</td>
        <td class="text-right font-bold" style="color:${scoreColor(t.avg_score||0)}">${t.avg_score ?? '—'}</td>
        <td>${bands(t)}</td></tr>`).join('') || '<tr><td colspan="7" class="text-gray-400 text-center py-2">No reporting lines in scope</td></tr>';
    renderPeople();
  }
  loadOne();
}

function renderPeople(){
  if (!TEAM) return;
  const q = (document.getElementById('q').value||'').toLowerCase().trim();
  const bf = document.getElementById('bandF').value;
  const rows = (TEAM.people||[]).filter(p => (!bf || p.band === bf) &&
    (!q || [p.full_name,p.designation,p.department,p.manager_name].some(v => (v||'').toLowerCase().includes(q))));
  document.getElementById('peopleRows').innerHTML = rows.map(p => `
    <tr>
      <td class="font-medium">${esc(p.full_name)}<div class="text-[10px] text-gray-400">${esc(p.designation||'—')}</div></td>
      <td class="text-[11px]">${esc(p.department||'—')}${p.sub_department?'<div class="text-[10px] text-gray-400">'+esc(p.sub_department)+'</div>':''}</td>
      <td class="text-[11px]">${esc(p.manager_name||'—')}</td>
      <td>${bar(p.adherence_pct)}</td>
      <td class="text-right">${p.met}</td>
      <td class="text-right ${p.breached>0?'text-red-600 font-semibold':''}">${p.breached}</td>
      <td class="text-right">${p.escalated_items}${p.still_open?`<span class="text-[9px] text-red-500"> (${p.still_open} open)</span>`:''}</td>
      <td class="text-right ${p.to_board>0?'text-red-600 font-bold':''}">${p.to_board}</td>
      <td class="text-right">${p.goals}</td>
      <td><span class="font-bold" style="color:${scoreColor(p.performance_score)}">${p.performance_score}</span>
          ${badge(p.band, bandColor(p.band))}</td>
      <td class="rowact"><button onclick="drill(${p.id},'${esc(p.full_name).replace(/'/g,"")}')" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Details</button></td>
    </tr>`).join('') || '<tr><td colspan="11" class="text-gray-400 text-center py-3">Nobody matches</td></tr>';
}

function detailHtml(d){
  const t = d.tat, e = d.escalations;
  return `
    <div class="flex items-center gap-4 flex-wrap mb-3">
      <div><div class="text-2xl font-bold" style="color:${scoreColor(d.performance_score)}">${d.performance_score}</div>
        <div class="text-[10px] text-gray-400">performance score</div></div>
      <div>${badge(d.band, bandColor(d.band))}</div>
      <div class="min-w-[160px]">${bar(t.adherence_pct)}<div class="text-[10px] text-gray-400">TAT adherence · last ${d.window_days} days</div></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-center mb-3">
      ${[['Under SLA',t.with_sla],['Met on time',t.met],['Breached',t.breached,t.breached>0],
         ['Escalated items',e.items||0,(e.items||0)>0],['Still open',e.still_open||0,(e.still_open||0)>0],
         ['Deepest level','L'+(e.deepest_level||0)],['Reached Board',e.to_board||0,(e.to_board||0)>0],
         ['Avg breach (h)',e.avg_breach_h??'—']].map(([l,v,warn])=>
        `<div class="border rounded-lg p-2"><div class="font-bold ${warn?'text-red-600':'text-gray-800'}">${v}</div><div class="text-[10px] text-gray-400">${l}</div></div>`).join('')}
    </div>
    <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">KPA / KRA owned</div>
    <div class="overflow-x-auto mb-3"><table class="dense"><thead><tr>
      <th>KPA</th><th>KRA</th><th>Target</th><th>Measured by</th><th>Approval</th></tr></thead><tbody>
      ${(d.goals||[]).map(g=>`<tr>
        <td><span class="gcode c-kpa">${esc(g.kpa_ref)}</span> ${esc((g.title||'').slice(0,40))}</td>
        <td><span class="gcode c-kra">${esc(g.kra_ref)}</span> <span class="text-[11px]">${esc((g.kra||'').slice(0,44))}</span></td>
        <td class="text-[11px]">${esc(g.target_value||'—')} ${esc(g.unit||'')}</td>
        <td>${g.sla_ref?`<span class="gcode c-sla">${esc(g.sla_ref)}</span> <span class="text-[10px]">TAT ${g.tat_hours}h</span>`:'<span class="text-gray-300 text-[11px]">not linked</span>'}</td>
        <td>${badge(g.approval_status||'draft', g.approval_status==='approved'?'green':'gray')}</td></tr>`).join('')
        || '<tr><td colspan="5" class="text-gray-400 text-center py-2">No KPAs assigned</td></tr>'}
    </tbody></table></div>
    <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Escalations on their work — traced by code</div>
    <div class="overflow-x-auto max-h-52 overflow-y-auto"><table class="dense"><thead><tr>
      <th>Event</th><th>SLA breached</th><th>Matrix rule</th><th>Level</th><th class="text-right">Late by (h)</th><th>Subject</th><th>Status</th></tr></thead><tbody>
      ${(d.escalation_trail||[]).map(r=>`<tr>
        <td><span class="gcode c-esl">${esc(r.esl_ref)}</span></td>
        <td>${r.sla_ref?`<span class="gcode c-sla">${esc(r.sla_ref)}</span>`:'<span class="text-gray-300">—</span>'}</td>
        <td>${r.esc_ref?`<span class="gcode c-esc">${esc(r.esc_ref)}</span>`:'<span class="text-[10px] text-gray-400">manager chain</span>'}</td>
        <td class="text-center">L${r.level}</td>
        <td class="text-right">${r.breached_by_hours??'—'}</td>
        <td class="text-[11px]">${esc((r.subject||'').slice(0,38))}</td>
        <td>${badge(r.status, r.status==='open'?'red':'green')}</td></tr>`).join('')
        || '<tr><td colspan="7" class="text-green-600 text-center py-2">No escalations in this window</td></tr>'}
    </tbody></table></div>`;
}

async function loadOne(){
  const days = document.getElementById('days').value;
  const { data } = await apiGet('performance.php', { days });
  document.getElementById('one').innerHTML = detailHtml(data);
}
async function drill(id, name){
  const days = document.getElementById('days').value;
  const { data } = await apiGet('performance.php', { employee_id:id, days });
  openModal(detailHtml(data), name + ' — performance');
}
load();
</script>
</html>
