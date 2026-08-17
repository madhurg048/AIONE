<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// One page, two lenses: ?view=kpi renders the KPI Library, default is the KRA Library.
$view = ($_GET['view'] ?? 'kra') === 'kpi' ? 'kpi' : 'kra';
sales_head($view === 'kpi' ? 'KPI Library' : 'KRA Library');
sales_header($me, $view === 'kpi' ? 'KPI Library' : 'KRA Library');
sales_nav($view === 'kpi' ? 'kpi' : 'kra');
?>
<style>
  .lib-tab { padding:4px 12px; font-size:12px; border-radius:8px; color:#4b5563; cursor:pointer; }
  .lib-tab.on { background:#2563eb; color:#fff; font-weight:600; }
  .gcode { font-family:ui-monospace,Consolas,monospace; font-size:10px; font-weight:700;
           border-radius:5px; padding:1px 5px; white-space:nowrap; background:#eef2ff; color:#4338ca; }
  .gcode.kpi { background:#ecfeff; color:#0e7490; }
  .cyc { background:#f5f3ff; border:1px solid #ddd6fe; color:#5b21b6; border-radius:8px; padding:3px 10px; font-size:11px; }
  .kstat { border:1px solid #e5e7eb; border-radius:10px; padding:8px 12px; background:#fff; min-width:132px; }
</style>
<main class="p-3 space-y-2">

  <!-- header: title, cycle, actions -->
  <div class="flex items-start gap-3 flex-wrap">
    <div>
      <div class="text-sm font-bold text-gray-700 tracking-wide" id="pgTitle"></div>
      <div class="text-[11px] text-gray-500" id="pgSub"></div>
    </div>
    <div class="flex-1"></div>
    <span class="cyc" id="cycle"></span>
    <button onclick="openNew()" id="btnNew" class="btn-p hidden"><i class="fa-solid fa-plus"></i> <span id="btnNewLbl">New KRA</span></button>
  </div>

  <!-- library tabs -->
  <div class="flex items-center gap-1">
    <a href="sales-kra.php" class="lib-tab <?= $view === 'kra' ? 'on' : '' ?>">KRA Library</a>
    <a href="sales-kra.php?view=kpi" class="lib-tab <?= $view === 'kpi' ? 'on' : '' ?>">KPI Library</a>
    <span class="lib-tab" id="tabArchived" onclick="showArchived()">Archived</span>
  </div>

  <!-- KPI strip -->
  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <!-- filters -->
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <select id="fType" class="ctl" onchange="load()"><option value="">All types</option></select>
    <select id="fDept" class="ctl" onchange="load()"><option value="">All departments</option></select>
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option value="active">Active</option>
      <option value="draft">Draft</option><option value="archived">Archived</option>
    </select>
    <input id="q" class="ctl" placeholder="Search code, name or description…" oninput="debounced()" style="min-width:240px">
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400" id="rowCount"></span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense"><thead><tr id="head"></tr></thead><tbody id="rows"></tbody></table></div>
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
const VIEW = '<?= $view ?>';
let META = { kra_types:[], kpi_types:[], departments:[], roles:[], slas:[], units:[] }, ROWS = [], CAN = false;
let showArch = false, tmr = null;
const debounced = () => { clearTimeout(tmr); tmr = setTimeout(load, 400); };

document.getElementById('pgTitle').textContent = VIEW === 'kpi' ? 'KPI LIBRARY' : 'KRA LIBRARY';
document.getElementById('pgSub').textContent = VIEW === 'kpi'
  ? 'Create reusable KPI templates and apply them across KRAs, departments and roles'
  : 'Create, manage and reuse KRAs for roles, departments and employees';
document.getElementById('btnNewLbl').textContent = VIEW === 'kpi' ? 'New KPI' : 'New KRA';

async function boot(){
  const m = await apiGet('kra-library.php', { meta:1 });
  META = m.data; CAN = !!m.data.can_write;
  document.getElementById('cycle').textContent = 'Appraisal Cycle: ' + META.cycle.label + ' · ' + META.cycle.status;
  document.getElementById('btnNew').classList.toggle('hidden', !CAN);
  document.getElementById('fType').innerHTML = '<option value="">All types</option>' +
    (VIEW === 'kpi' ? META.kpi_types : META.kra_types).map(t=>`<option>${esc(t)}</option>`).join('');
  document.getElementById('fDept').innerHTML = '<option value="">All departments</option>' +
    (META.departments||[]).map(d=>`<option>${esc(d)}</option>`).join('');
  document.getElementById('head').innerHTML = VIEW === 'kpi'
    ? '<th>KPI Code</th><th>KPI Name</th><th>KPI Type</th><th>Department</th><th>Sub Dept</th><th>Description</th>'
      + '<th>TAT (Deadline)</th><th class="text-right"># KRAs</th><th class="text-right"># SLAs</th>'
      + '<th class="text-right"># Esc. Matrices</th><th>Status</th><th>Last Updated</th><th>Action</th>'
    : '<th>KRA Code</th><th>KRA Name</th><th>KRA Type</th><th>Department</th><th>Sub Dept</th><th>Description</th>'
      + '<th class="text-right">No. of KPIs</th><th>Linked Roles</th><th>Status</th><th>Last Updated</th><th>Action</th>';
  load();
}

function showArchived(){
  showArch = !showArch;
  document.getElementById('tabArchived').classList.toggle('on', showArch);
  document.getElementById('fStatus').value = showArch ? 'archived' : '';
  load();
}

async function load(){
  const p = { view: VIEW, status: document.getElementById('fStatus').value,
              department: document.getElementById('fDept').value,
              type: document.getElementById('fType').value, q: document.getElementById('q').value };
  const { data } = await apiGet('kra-library.php', p);
  ROWS = data.rows || [];
  const t = data.totals || {};
  const pct = n => t.total ? ((n / t.total) * 100).toFixed(2) + '%' : '0%';
  const label = VIEW === 'kpi' ? 'Templates' : 'KRAs';
  document.getElementById('strip').innerHTML = [
    ['📋', 'Total ' + label, t.total || 0, VIEW === 'kpi' ? 'All Templates' : 'Across Organisation'],
    ['✅', 'Active ' + label, t.active_cnt || 0, pct(t.active_cnt || 0)],
    ['⏳', 'Draft ' + label, t.draft_cnt || 0, pct(t.draft_cnt || 0)],
    ['📦', 'Archived ' + label, t.archived_cnt || 0, pct(t.archived_cnt || 0)],
  ].map(([ic,l,v,sub]) => `<div class="kstat"><div class="text-[10px] text-gray-400">${ic} ${esc(l)}</div>
      <div class="text-lg font-bold text-gray-800">${v}</div><div class="text-[10px] text-gray-400">${esc(sub)}</div></div>`).join('');
  document.getElementById('rowCount').textContent = ROWS.length + ' shown';

  const stBadge = r => badge(r.status, r.status === 'active' ? 'green' : r.status === 'draft' ? 'yellow' : 'gray');
  const upd = r => `${esc((r.last_change||r.updated_at||'').slice(0,16))}${r.last_change_by?'<div class="text-[10px] text-gray-400">'+esc(r.last_change_by)+'</div>':''}`;
  const act = r => CAN ? `<button onclick="openEdit(${r.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded mr-1">Edit</button>`
      + (r.status === 'archived'
          ? `<button onclick="setArchive(${r.id},'restore')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Restore</button>`
          : `<button onclick="setArchive(${r.id},'archive')" class="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded">Archive</button>`) : '';

  document.getElementById('rows').innerHTML = ROWS.map(r => VIEW === 'kpi' ? `
    <tr class="${r.status!=='active'?'opacity-60':''}">
      <td><span class="gcode kpi">${esc(r.code)}</span><div class="text-[9px] text-gray-400">v${r.version}</div></td>
      <td class="font-medium">${esc(r.title)}<div class="text-[10px] text-gray-500">target ${esc(r.target_value||'—')} ${esc(r.unit||'')}</div></td>
      <td>${r.type?badge(r.type,'blue'):'<span class="text-gray-300">—</span>'}</td>
      <td class="text-[11px]">${esc(r.org_group||'—')}</td>
      <td class="text-[11px] text-gray-400">—</td>
      <td class="text-[11px] text-gray-600">${esc((r.description||'').slice(0,60))}</td>
      <td class="text-[11px]">${esc(r.tat_deadline||'—')}</td>
      <td class="text-right">${r.kra_count}</td><td class="text-right">${r.sla_count}</td><td class="text-right">${r.esc_count}</td>
      <td>${stBadge(r)}</td><td class="text-[10px] text-gray-400">${upd(r)}</td><td class="rowact">${act(r)}</td>
    </tr>` : `
    <tr class="${r.status!=='active'?'opacity-60':''}">
      <td><span class="gcode">${esc(r.code)}</span><div class="text-[9px] text-gray-400">v${r.version}</div></td>
      <td class="font-medium">${esc(r.name)}<div class="text-[10px] text-gray-500">${esc(r.title)}</div></td>
      <td>${r.type?badge(r.type,'purple'):'<span class="text-gray-300">—</span>'}</td>
      <td class="text-[11px]">${esc(r.org_group||'—')}</td>
      <td class="text-[11px] text-gray-400">—</td>
      <td class="text-[11px] text-gray-600">${esc((r.description||'').slice(0,60))}</td>
      <td class="text-right">${r.kpi_count}</td>
      <td class="text-[10px]">${(r.role_labels||[]).map(x=>`<span class="bg-gray-100 rounded px-1 mr-0.5">${esc(x)}</span>`).join('') || '<span class="text-gray-300">—</span>'}</td>
      <td>${stBadge(r)}</td><td class="text-[10px] text-gray-400">${upd(r)}</td><td class="rowact">${act(r)}</td>
    </tr>`).join('') || `<tr><td colspan="13" class="text-center text-gray-400 py-4">Nothing here yet</td></tr>`;
}

/* ---------- create / edit ---------- */
function formHtml(r){
  const types = VIEW === 'kpi' ? META.kpi_types : META.kra_types;
  const sel = (name, opts, val, blank) => `<select name="${name}" class="ctl w-full mt-0.5">${blank?'<option value="">—</option>':''}
    ${opts.map(o => { const v = typeof o === 'object' ? o.key : o, l = typeof o === 'object' ? o.label : o;
      return `<option value="${esc(v)}" ${String(v)===String(val||'')?'selected':''}>${esc(l)}</option>`; }).join('')}</select>`;
  return `<form onsubmit="save(event, ${r ? r.id : 'null'})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block col-span-2">Name (the ${VIEW === 'kpi' ? 'KPI' : 'KRA'})
      <input name="title" required value="${esc(r?r.title:'')}" class="ctl w-full mt-0.5"></label>
    ${VIEW === 'kra' ? `<label class="block col-span-2">Outcome / KRA statement
      <textarea name="kra" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(r?r.kra:'')}</textarea></label>` : ''}
    <label class="block col-span-2">Description<textarea name="description" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(r?r.description:'')}</textarea></label>
    <label class="block">${VIEW === 'kpi' ? 'KPI' : 'KRA'} type${sel(VIEW==='kpi'?'kpi_type':'kra_type', types, r?(VIEW==='kpi'?r.kpi_type:r.kra_type):'', true)}</label>
    <label class="block">Department${sel('department', META.departments, r?r.org_group:'', true)}</label>
    ${VIEW === 'kpi' ? `
      <label class="block">Target${`<input name="target_value" value="${esc(r?r.target_value:'')}" class="ctl w-full mt-0.5">`}</label>
      <label class="block">Unit${sel('unit', META.units, r?r.unit:'count', false)}</label>
      <label class="block">TAT (deadline)<input name="tat_deadline" value="${esc(r?r.tat_deadline:'')}" placeholder="e.g. T+1 by 11:00 AM" class="ctl w-full mt-0.5"></label>
      <label class="block">Proven by SLA${sel('sla_policy_id', (META.slas||[]).map(s=>({key:s.id,label:s.sla_ref+' — '+s.name})), r?r.sla_policy_id:'', true)}</label>
    ` : `
      <label class="block">Weight (%)<input name="weight" type="number" step="0.5" value="${r?r.weight:0}" class="ctl w-full mt-0.5"></label>
      <label class="block">Period<input name="period" value="${esc(r?r.period:'annual')}" class="ctl w-full mt-0.5"></label>
      <label class="block col-span-2">Linked roles
        <select name="linked_roles" multiple size="5" class="ctl w-full mt-0.5" style="height:auto">
          ${(META.roles||[]).map(x=>`<option value="${esc(x.key)}" ${(r&&(r.roles||[]).includes(x.key))?'selected':''}>${esc(x.label)}</option>`).join('')}
        </select></label>
    `}
    <label class="block">Status${sel('status', ['draft','active','archived'], r?r.status:'draft', false)}</label>
    <div class="col-span-2 flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${r ? 'Save changes' : 'Create'}</button></div>
  </form>`;
}
function openNew(){ openModal(formHtml(null), VIEW === 'kpi' ? 'New KPI' : 'New KRA'); }
function openEdit(id){ const r = ROWS.find(x=>+x.id===+id); if (r) openModal(formHtml(r), 'Edit ' + r.code); }
async function save(e, id){
  e.preventDefault(); const f = e.target;
  const body = {};
  [...f.elements].forEach(el => {
    if (!el.name) return;
    if (el.name === 'linked_roles') body.linked_roles = [...el.selectedOptions].map(o=>o.value);
    else body[el.name] = el.value;
  });
  const res = id ? await apiSend('kra-library.php?id=' + id, body, 'PATCH')
                 : await apiSend('kra-library.php?view=' + VIEW, body);
  if (res.success){ toast(id ? 'Saved' : 'Created'); closeModal(); load(); } else toast(res.error||'Failed', false);
}
async function setArchive(id, action){
  const res = await apiSend(`kra-library.php?action=${action}&id=${id}`, {});
  if (res.success){ toast(action === 'restore' ? 'Restored' : 'Archived'); load(); } else toast(res.error||'Failed', false);
}
boot();
</script>
</html>
