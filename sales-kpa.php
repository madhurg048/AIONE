<?php
/**
 * KPA / KRA — org-group goal definitions with manager → team inheritance.
 * Only managers and above may open this page (team members are redirected home).
 *   • Admin / HR : define KPA/KRA per org group (Sales/Service/HR/IT/Support), see every
 *                  KPA in the organisation (including each manager's team goals), edit any.
 *   • Manager    : inherit org + supervisor goals (read-only) and set additional KPAs for
 *                  their own team (their line of control only).
 */
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();

/* Governors own the goal framework company-wide. Both this page and api/sales/kpa.php read the
 * same capability from _roles.php — they used to keep separate hardcoded role lists, and the
 * lists drifted the moment hr_head existed: the API let the HR Head govern KPAs while this
 * page bounced them straight back to their dashboard. */
$__role = sales_role();
$__isGovernor = payg_role_can($__role, 'kpi_govern');

/* Manager-and-above gate (defense in depth beyond role_access nav filtering). */
$__managerPlus = $__isGovernor || payg_role_tier($__role) >= PAYG_TIER_MANAGER || $__role === 'department';
if (!$__managerPlus) {
    header('Location: ' . sales_home());
    exit;
}
$__isAdmin = $__role === 'admin';
/* Everyone except the admin also leads a team of their own, so HR gets both views. */
$__showTeam = !$__isAdmin;

sales_head('KPA / KRA');
sales_header($me, 'Sales · KPA / KRA');
sales_nav('kpa');
?>
<main class="p-2 space-y-2">

  <div class="card p-3">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-sm font-semibold text-gray-800"><i class="fa-solid fa-crosshairs text-indigo-600"></i> KPA / KRA Goals</h2>
        <p class="text-xs text-gray-500 mt-0.5">
          <?php if ($__isGovernor): ?>
            Define Key Performance Areas &amp; Key Result Areas for each org group. Everyone in a group inherits its goals; you can see and edit every KPA/KRA in the organisation below, including the ones each department manager sets for their team.
          <?php else: ?>
            Your org &amp; supervisor goals are inherited (read-only). You can add extra KPAs/KRAs for members of your own team.
          <?php endif; ?>
        </p>
      </div>
      <span id="roleChip" class="text-[11px] px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 font-semibold"></span>
    </div>
  </div>

  <?php if ($__isGovernor): ?>
  <!-- ============ GOVERNOR (admin / HR): define per org group ============ -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-layer-group text-indigo-500"></i> Define KPA/KRA by Org Group</h3>
      <button onclick="openGoalModal('org')" class="btn-p"><i class="fa-solid fa-plus"></i> New org KPA</button>
    </div>
    <div id="groupTabs" class="flex flex-wrap gap-1 border-b mb-2"></div>
    <div id="orgGoals" class="overflow-x-auto"></div>
  </div>

  <!-- ============ GOVERNOR: whole-org oversight ============ -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-building-shield text-indigo-500"></i> All KPAs / KRAs in the Organisation</h3>
      <select id="allFilter" class="ctl" onchange="loadAll()"><option value="">All groups</option></select>
    </div>
    <div id="allGoals" class="overflow-x-auto"></div>
  </div>
  <?php endif; ?>

  <?php if ($__showTeam): ?>
  <!-- ============ MANAGER: my inherited goals ============ -->
  <div class="card p-3">
    <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide mb-2"><i class="fa-solid fa-arrow-down-long text-indigo-500"></i> My Goals (inherited &mdash; read-only)</h3>
    <p class="text-xs text-gray-500 mb-2">Org goals for your group plus any goals set for you by a manager above you. You cannot edit these.</p>
    <div id="inheritedGoals" class="overflow-x-auto"></div>
  </div>

  <!-- ============ MANAGER: my team ============ -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-users-line text-indigo-500"></i> My Team's KPAs / KRAs</h3>
      <button onclick="openGoalModal('team')" class="btn-p"><i class="fa-solid fa-plus"></i> Add team KPA</button>
    </div>
    <p class="text-xs text-gray-500 mb-2">Each member inherits the org goals + your team goals. You can edit only the KPAs you created.</p>
    <div id="teamGoals" class="space-y-3"></div>
  </div>
  <?php endif; ?>
</main>

<!-- ============ create / edit modal ============ -->
<div id="goalModal" class="hidden fixed inset-0 bg-black/40 z-[60] flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 id="goalModalTitle" class="font-semibold text-gray-800">New KPA / KRA</h3>
      <button onclick="closeGoalModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form onsubmit="submitGoal(event)" class="p-4 space-y-3 text-xs">
      <input type="hidden" id="gId">
      <input type="hidden" id="gScope">
      <div id="gOrgWrap">
        <label class="block text-gray-600 mb-1">Org group</label>
        <select id="gGroup" class="ctl w-full"></select>
      </div>
      <div id="gOwnerWrap" class="hidden">
        <label class="block text-gray-600 mb-1">Applies to</label>
        <select id="gOwner" class="ctl w-full"></select>
      </div>
      <div>
        <label class="block text-gray-600 mb-1">KPA — Key Performance Area <span class="text-red-500">*</span></label>
        <input id="gTitle" required class="ctl w-full" placeholder="e.g. New merchant acquisition">
      </div>
      <div>
        <label class="block text-gray-600 mb-1">KRA — how it's measured</label>
        <textarea id="gKra" rows="2" class="border rounded px-2 py-1.5 w-full" placeholder="e.g. Onboard & activate 20 new merchants / quarter"></textarea>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div>
          <label class="block text-gray-600 mb-1">Weight %</label>
          <input id="gWeight" type="number" step="0.01" min="0" max="100" class="ctl w-full" placeholder="20">
        </div>
        <div>
          <label class="block text-gray-600 mb-1">Target</label>
          <input id="gTarget" class="ctl w-full" placeholder="e.g. 20 / ₹2 Cr / 95%">
        </div>
        <div>
          <label class="block text-gray-600 mb-1">Unit</label>
          <select id="gUnit" class="ctl w-full">
            <option value="text">Text</option><option value="count">Count</option>
            <option value="pct">Percent</option><option value="inr">INR</option><option value="flag">Yes/No</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-gray-600 mb-1">Period</label>
        <input id="gPeriod" class="ctl w-full" placeholder="annual / FY26 / 2026-Q3" value="annual">
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" onclick="closeGoalModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">Save KPA</button>
      </div>
    </form>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_GOVERNOR = <?= $__isGovernor ? 'true' : 'false' ?>;   // admin or HR — owns the whole framework
const SHOW_TEAM   = <?= $__showTeam ? 'true' : 'false' ?>;
let META = null, CUR_GROUP = null;

const unitLabel = u => ({inr:'₹', pct:'%', count:'#', flag:'Y/N', text:''}[u] || '');
function relBadge(rel){
  if (rel === 'org') return badge('Org', 'indigo');
  if (rel === 'manager') return badge('Manager', 'blue');
  return '';
}
let _rid = 0;
function goalRow(g, showApplies, empFilter){
  const rid = 'r' + (++_rid);
  const w = Number(g.weight||0);
  const tgt = g.target_value ? esc(g.target_value) + ' ' + unitLabel(g.unit) : '—';
  const hrs = Number(g.hours||0);
  const empArg = (empFilter === undefined || empFilter === null) ? 'null' : empFilter;
  const act = g.can_edit
    ? `<button onclick='editGoal(${JSON.stringify(g)})' class="text-indigo-600 hover:text-indigo-800" title="Edit"><i class="fa-solid fa-pen"></i></button>
       <button onclick="delGoal(${g.id})" class="text-red-500 hover:text-red-700 ml-2" title="Delete"><i class="fa-solid fa-trash"></i></button>`
    : '<span class="text-gray-300"><i class="fa-solid fa-lock" title="Read-only"></i></span>';
  const cols = (showApplies ? 8 : 7);
  return `<tr>
    <td><button onclick="toggleContrib('${rid}', ${g.id}, ${empArg})" class="text-gray-400 hover:text-indigo-600 mr-1" title="Show hours spent"><i id="ic-${rid}" class="fa-solid fa-chevron-right text-[9px]"></i></button>
        <span class="font-medium text-gray-800">${esc(g.title)}</span>
        <div class="text-gray-400 text-[11px] pl-4">${esc(g.kra||'')}</div></td>
    ${showApplies ? `<td>${g.scope==='org' ? esc(g.org_group||'') : (g.owner_name?esc(g.owner_name):'Whole team')}</td>` : ''}
    <td>${w?w+'%':'—'}</td>
    <td>${tgt}</td>
    <td>${hrs>0?`<span class="text-indigo-600 font-semibold">${hrs.toFixed(2)}h</span>`:'<span class="text-gray-300">0h</span>'}</td>
    <td>${esc(g.period||'')}</td>
    <td>${g.scope==='org'?relBadge('org'):(g.relation?relBadge(g.relation):badge('Team','gray'))} <span class="text-gray-400 text-[10px]">by ${esc(g.created_by_name||'')}</span></td>
    <td class="text-right whitespace-nowrap">${act}</td>
  </tr>
  <tr id="cd-${rid}" class="hidden"><td colspan="${cols}" class="bg-gray-50 p-0"><div id="cdc-${rid}" class="p-2 text-[11px] text-gray-500"></div></td></tr>`;
}
function goalTable(rows, showApplies, empFilter){
  if (!rows.length) return '<div class="text-xs text-gray-400 py-3 text-center">No KPAs yet.</div>';
  const cols = (showApplies ? 8 : 7);
  const sum = rows.reduce((a,g)=>a+Number(g.weight||0),0);
  const ok = Math.abs(sum-100) < 0.01;
  // A person's KPA weights should add up to 100% (each is a share of overall performance).
  const foot = sum>0 ? `<tfoot><tr class="border-t"><td colspan="${cols}" class="text-[11px] py-1">
      <span class="${ok?'text-green-600':'text-amber-600'} font-semibold">Weights total: ${(Math.round(sum*100)/100)}%</span>
      ${ok?'<i class="fa-solid fa-check text-green-500 ml-1"></i>'
          :`<span class="text-amber-600 ml-1"><i class="fa-solid fa-triangle-exclamation"></i> should sum to 100% (currently ${sum<100?'under':'over'} by ${(Math.round(Math.abs(sum-100)*100)/100)}%)</span>`}
    </td></tr></tfoot>` : '';
  return `<table class="dense w-full"><thead><tr>
    <th>KPA / KRA</th>${showApplies?'<th>Applies to</th>':''}<th>Weight</th><th>Target</th><th>Hours</th><th>Period</th><th>Source</th><th></th>
    </tr></thead><tbody>${rows.map(g=>goalRow(g, showApplies, empFilter)).join('')}</tbody>${foot}</table>`;
}
async function toggleContrib(rid, goalId, emp){
  const row = document.getElementById('cd-'+rid), ic = document.getElementById('ic-'+rid);
  if (!row.classList.contains('hidden')){ row.classList.add('hidden'); ic.className='fa-solid fa-chevron-right text-[9px]'; return; }
  row.classList.remove('hidden'); ic.className='fa-solid fa-chevron-down text-[9px]';
  const box = document.getElementById('cdc-'+rid); box.innerHTML = 'Loading…';
  const params = { action:'contrib', goal_id: goalId };
  if (emp !== null && emp !== undefined) params.employee_id = emp;
  const r = await apiGet('kpa.php', params);
  if (!r.success){ box.innerHTML = '<span class="text-red-500">'+esc(r.error||'error')+'</span>'; return; }
  const d = r.data;
  if (!d.people.length){ box.innerHTML = '<div class="text-gray-400">No hours logged against this KPA yet.</div>'; return; }
  box.innerHTML = `
    <div class="font-semibold text-gray-600 mb-1"><i class="fa-solid fa-clock text-indigo-500"></i> ${Number(d.total_hours).toFixed(2)}h total &middot; who spent how much</div>
    <table class="dense w-full mb-1"><thead><tr><th>Person</th><th>Hours</th><th>Tasks</th></tr></thead>
      <tbody>${d.people.map(p=>`<tr><td>${esc(p.name||('#'+p.employee_id))}</td><td class="text-indigo-600 font-semibold">${Number(p.hours).toFixed(2)}h</td><td>${p.task_count}</td></tr>`).join('')}</tbody></table>
    ${d.tasks.length?`<div class="text-gray-400 mt-1 mb-0.5">Contributing tasks</div>
      <div class="space-y-0.5">${d.tasks.map(t=>`<div class="flex justify-between gap-2 border-b border-gray-100 py-0.5">
        <span>${esc(t.title)} <span class="text-gray-400">· ${esc(t.name||'')}</span></span>
        <span class="whitespace-nowrap">${badge(t.status, t.status==='done'?'green':(t.status==='in_progress'?'yellow':'gray'))} <span class="text-indigo-600 font-semibold">${Number(t.hours_spent).toFixed(2)}h</span></span>
      </div>`).join('')}</div>`:''}`;
}

async function boot(){
  const r = await apiGet('kpa.php', { action:'meta' });
  if (!r.success) { toast(r.error||'Load failed', false); return; }
  META = r.data;
  document.getElementById('roleChip').textContent =
    META.is_admin ? 'Admin · whole organisation'
    : (META.is_governor ? 'HR · whole organisation'
    : (META.my_group ? META.my_group + ' Manager' : 'Manager'));
  // modal group + owner selects
  const gg = document.getElementById('gGroup');
  if (gg) gg.innerHTML = META.groups.map(g=>`<option value="${g}">${g}</option>`).join('');
  const go = document.getElementById('gOwner');
  if (go) go.innerHTML = `<option value="">Whole team</option>` +
    (META.team||[]).map(m=>`<option value="${m.id}">${esc(m.name)} — ${esc(m.designation||m.role||'')}</option>`).join('');

  if (IS_GOVERNOR){
    const tabs = document.getElementById('groupTabs');
    tabs.innerHTML = META.groups.map((g,i)=>`<button data-g="${g}" onclick="pickGroup('${g}')" class="grp px-3 py-1.5 text-xs border-b-2 ${i===0?'border-indigo-600 text-indigo-600 font-semibold':'border-transparent text-gray-500'}">${g}</button>`).join('');
    const af = document.getElementById('allFilter');
    af.innerHTML = '<option value="">All groups</option>' + META.groups.map(g=>`<option value="${g}">${g}</option>`).join('');
    CUR_GROUP = META.groups[0];
    loadOrg(); loadAll();
  }
  if (SHOW_TEAM){ loadInherited(); loadTeam(); }
}
function pickGroup(g){
  CUR_GROUP = g;
  document.querySelectorAll('#groupTabs .grp').forEach(b=>{
    const on = b.dataset.g===g;
    b.className = 'grp px-3 py-1.5 text-xs border-b-2 ' + (on?'border-indigo-600 text-indigo-600 font-semibold':'border-transparent text-gray-500');
  });
  loadOrg();
}
async function loadOrg(){
  const r = await apiGet('kpa.php', { action:'org', group: CUR_GROUP });
  document.getElementById('orgGoals').innerHTML = r.success ? goalTable(r.data.goals, false, null) : '<div class="text-red-500 text-xs p-2">'+esc(r.error||'error')+'</div>';
}
async function loadAll(){
  const g = document.getElementById('allFilter').value;
  const r = await apiGet('kpa.php', { action:'all', group: g });
  document.getElementById('allGoals').innerHTML = r.success ? goalTable(r.data.goals, true, null) : '<div class="text-red-500 text-xs p-2">'+esc(r.error||'error')+'</div>';
}
async function loadInherited(){
  const r = await apiGet('kpa.php', { action:'inherited' });
  const emp = (META && META.my_employee_id) || null;
  document.getElementById('inheritedGoals').innerHTML = r.success ? goalTable(r.data.goals, true, emp) : '<div class="text-red-500 text-xs p-2">'+esc(r.error||'error')+'</div>';
}
async function loadTeam(){
  const r = await apiGet('kpa.php', { action:'team' });
  const el = document.getElementById('teamGoals');
  if (!r.success){ el.innerHTML = '<div class="text-red-500 text-xs p-2">'+esc(r.error||'error')+'</div>'; return; }
  const ms = r.data.members || [];
  if (!ms.length){ el.innerHTML = '<div class="text-xs text-gray-400 py-3 text-center">You have no team members.</div>'; return; }
  el.innerHTML = ms.map(m=>`
    <div class="border rounded-lg">
      <div class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b">
        <div class="font-medium text-gray-800 text-xs">${esc(m.name)} <span class="text-gray-400 font-normal">· ${esc(m.designation||m.role||'')}${m.group?(' · '+esc(m.group)):''}</span></div>
        <button onclick="openGoalModal('team', ${m.id})" class="text-[11px] text-indigo-600 hover:underline"><i class="fa-solid fa-plus"></i> Add KPA for ${esc(m.name.split(' ')[0])}</button>
      </div>
      <div class="p-2 overflow-x-auto">${goalTable(m.goals, false, m.id)}</div>
    </div>`).join('');
}

/* ---- modal ---- */
function openGoalModal(scope, ownerId){
  document.getElementById('gId').value = '';
  document.getElementById('gScope').value = scope;
  document.getElementById('goalModalTitle').textContent = scope==='org' ? 'New Org KPA / KRA' : 'New Team KPA / KRA';
  document.getElementById('gOrgWrap').classList.toggle('hidden', scope!=='org');
  document.getElementById('gOwnerWrap').classList.toggle('hidden', scope!=='team');
  if (scope==='org' && CUR_GROUP) document.getElementById('gGroup').value = CUR_GROUP;
  if (scope==='team') document.getElementById('gOwner').value = ownerId ? String(ownerId) : '';
  ['gTitle','gKra','gWeight','gTarget'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('gUnit').value='text';
  document.getElementById('gPeriod').value='annual';
  document.getElementById('goalModal').classList.remove('hidden');
}
function closeGoalModal(){ document.getElementById('goalModal').classList.add('hidden'); }
function editGoal(g){
  document.getElementById('gId').value = g.id;
  document.getElementById('gScope').value = g.scope;
  document.getElementById('goalModalTitle').textContent = 'Edit KPA / KRA';
  document.getElementById('gOrgWrap').classList.add('hidden');
  document.getElementById('gOwnerWrap').classList.add('hidden');
  document.getElementById('gTitle').value = g.title||'';
  document.getElementById('gKra').value = g.kra||'';
  document.getElementById('gWeight').value = g.weight||'';
  document.getElementById('gTarget').value = g.target_value||'';
  document.getElementById('gUnit').value = g.unit||'text';
  document.getElementById('gPeriod').value = g.period||'annual';
  document.getElementById('goalModal').classList.remove('hidden');
}
async function submitGoal(e){
  e.preventDefault();
  const id = document.getElementById('gId').value;
  const scope = document.getElementById('gScope').value;
  const payload = {
    title: document.getElementById('gTitle').value.trim(),
    kra: document.getElementById('gKra').value.trim(),
    weight: parseFloat(document.getElementById('gWeight').value)||0,
    target_value: document.getElementById('gTarget').value.trim(),
    unit: document.getElementById('gUnit').value,
    period: document.getElementById('gPeriod').value.trim()||'annual',
  };
  let r;
  if (id){
    r = await apiSend('kpa.php?id='+id, payload, 'PATCH');
  } else {
    payload.scope = scope;
    if (scope==='org') payload.org_group = document.getElementById('gGroup').value;
    else { const o = document.getElementById('gOwner').value; payload.owner_employee_id = o||null; }
    r = await apiSend('kpa.php', payload, 'POST');
  }
  if (!r.success){ toast(r.error||'Save failed', false); return; }
  toast('Saved'); closeGoalModal(); refresh();
}
async function delGoal(id){
  if (!confirm('Delete this KPA / KRA?')) return;
  const r = await apiSend('kpa.php?id='+id, {}, 'DELETE');
  if (!r.success){ toast(r.error||'Delete failed', false); return; }
  toast('Deleted'); refresh();
}
function refresh(){
  if (IS_GOVERNOR){ loadOrg(); loadAll(); }
  if (SHOW_TEAM){ loadInherited(); loadTeam(); }
}

boot();
</script>
</body>
</html>
