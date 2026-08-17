<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Org & Roles');
sales_header($me, 'Admin · Org & Roles');
sales_nav('org');
?>
<main class="p-2 space-y-2">

  <!-- ===== My Organisation (profile, tax details, locations — from the All-In-One design) ===== -->
  <div class="card p-3" id="orgProfileCard">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-sm font-bold text-gray-800">My Organisation</h3>
      <button id="orgSaveBtn" onclick="saveOrgProfile()" class="btn-p hidden"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </div>
    <p class="text-xs text-gray-400 mb-2">Set up your organisation profile, tax details, and locations.</p>
    <div class="text-[10px] uppercase tracking-wide text-gray-400 border-b pb-1 mb-2">Basic information</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
      <label>Organisation name<input id="op_org_name" class="ctl w-full mt-0.5" placeholder="e.g. PayG Digital"></label>
      <label>Legal name<input id="op_legal_name" class="ctl w-full mt-0.5" placeholder="As per registration"></label>
      <label>Industry<input id="op_industry" class="ctl w-full mt-0.5" placeholder="e.g. Financial Services"></label>
      <label>Founded year<input id="op_founded_year" type="number" class="ctl w-full mt-0.5" placeholder="e.g. 2018"></label>
    </div>
    <div class="text-[10px] uppercase tracking-wide text-gray-400 border-b pb-1 my-2">Tax &amp; registration</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
      <label>GSTIN<input id="op_gstin" class="ctl w-full mt-0.5"></label>
      <label>PAN<input id="op_pan" class="ctl w-full mt-0.5"></label>
      <label>CIN<input id="op_cin" class="ctl w-full mt-0.5"></label>
      <label>Website<input id="op_website" class="ctl w-full mt-0.5"></label>
    </div>
    <div class="text-[10px] uppercase tracking-wide text-gray-400 border-b pb-1 my-2">Contact &amp; locations</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
      <label>Email<input id="op_email" class="ctl w-full mt-0.5"></label>
      <label>Phone<input id="op_phone" class="ctl w-full mt-0.5"></label>
      <label class="col-span-2">HQ address<input id="op_hq_address" class="ctl w-full mt-0.5"></label>
      <label class="col-span-2 md:col-span-4">Locations (one per line, e.g. "Hyderabad — HQ")
        <textarea id="op_locations" rows="2" class="ctl w-full mt-0.5 py-1"></textarea></label>
    </div>
  </div>

  <!-- ===== Departments & Designations masters (design: Organization section) ===== -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <div class="card p-3" id="departments">
      <h3 class="text-sm font-bold text-gray-800 mb-1">Departments &amp; Sub-departments</h3>
      <p class="text-xs text-gray-400 mb-2">Define departments and their sub-divisions — they feed the People grid's dropdowns.</p>
      <form onsubmit="addDept(event)" class="flex items-end gap-2 mb-2 text-xs" id="deptForm">
        <label class="block flex-1">Department<input name="department" required list="deptList" class="ctl w-full mt-0.5" placeholder="e.g. Sales"></label>
        <datalist id="deptList"></datalist>
        <label class="block flex-1">Sub-department<input name="sub_department" class="ctl w-full mt-0.5" placeholder="e.g. Regional Sales (Acquisition)"></label>
        <button class="btn-p">+ Add</button>
      </form>
      <div class="overflow-y-auto max-h-52"><table class="dense"><thead><tr><th>Department</th><th>Sub-department</th><th></th></tr></thead>
      <tbody id="deptRows"></tbody></table></div>
    </div>

    <div class="card p-3" id="designations">
      <h3 class="text-sm font-bold text-gray-800 mb-1">Designations</h3>
      <p class="text-xs text-gray-400 mb-2">Manage job titles and seniority levels used across the organisation.</p>
      <form onsubmit="addDesig(event)" class="flex items-end gap-2 mb-2 text-xs flex-wrap" id="desigForm">
        <label class="block flex-1 min-w-[140px]">Title<input name="title" required class="ctl w-full mt-0.5" placeholder="e.g. Product Manager"></label>
        <label class="block w-16">Level<input name="level" type="number" min="1" max="20" class="ctl w-full mt-0.5"></label>
        <label class="block w-28">Department<select name="department" class="ctl w-full mt-0.5"><option value="">—</option></select></label>
        <label class="flex items-center gap-1 pb-1.5"><input name="exec" type="checkbox" class="chk"> Exec</label>
        <button class="btn-p">+ Add</button>
      </form>
      <div class="overflow-y-auto max-h-52"><table class="dense"><thead><tr><th>Title</th><th>Level</th><th>Department</th><th>Exec leadership</th><th></th></tr></thead>
      <tbody id="desigRows"></tbody></table></div>
    </div>
  </div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase mr-1"><i class="fa-solid fa-sitemap text-indigo-500"></i> Org &amp; Roles</span>
    <span class="text-[11px] text-gray-400">Reassign anyone's login role and who they report to. Changes are live.</span>
    <div class="flex-1"></div>
    <input id="q" class="ctl" placeholder="Filter people…" oninput="render()">
  </div>

  <!-- editable people table -->
  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Employee</th><th>Designation</th><th>Department</th><th>Contact</th><th style="width:170px">Login role</th><th style="width:210px">Reports to</th><th>Login</th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
  </div>

  <!-- org chart -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-diagram-project text-indigo-500"></i> Reporting Structure</div>
    <div id="tree" class="text-xs"></div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
let PEOPLE = [], ROLES = {};
const ROLE_COLOR = { admin:'purple', manager:'blue', sales_exec:'green', support_manager:'teal', support:'teal',
                     hr_manager:'pink', hr:'pink', it_manager:'gray', it:'gray' };

async function load(){
  const { data } = await apiGet('org.php');
  PEOPLE = data.people; ROLES = data.roles;
  render();
}

function render(){
  const q = (document.getElementById('q').value||'').toLowerCase();
  const list = PEOPLE.filter(p => !q || (p.full_name+' '+(p.designation||'')+' '+(p.department||'')+' '+(p.sub_department||'')+' '+(p.email||'')+' '+(p.role||'')).toLowerCase().includes(q));
  // ---- table ----
  document.getElementById('rows').innerHTML = list.map(p => {
    const roleSel = p.user_id
      ? `<select onchange="setRole(${p.id}, this.value)" class="border rounded px-1 py-1 w-full">
           ${Object.entries(ROLES).map(([k,v])=>`<option value="${k}" ${k===p.role?'selected':''}>${v}</option>`).join('')}
         </select>`
      : `<span class="text-[10px] text-gray-400">no login</span>`;
    const mgrSel = `<select onchange="setManager(${p.id}, this.value)" class="border rounded px-1 py-1 w-full">
        <option value="">— top (no manager) —</option>
        ${PEOPLE.filter(m=>m.id!==p.id).map(m=>`<option value="${m.id}" ${String(m.id)===String(p.manager_id)?'selected':''}>${esc(m.full_name)}</option>`).join('')}
      </select>`;
    const dept = [p.department, p.sub_department].filter(Boolean).join(' / ');
    const contact = [p.email, p.phone].filter(Boolean).map(esc).join('<br>');
    return `<tr>
      <td class="font-medium">${esc(p.full_name)}${p.role?' '+badge(ROLES[p.role]||p.role, ROLE_COLOR[p.role]||'gray'):''}</td>
      <td class="text-gray-500">${esc(p.designation||'—')}</td>
      <td class="text-gray-500">${dept?esc(dept):'—'}</td>
      <td class="text-gray-400 text-[11px]">${contact||'—'}</td>
      <td>${roleSel}</td>
      <td>${mgrSel}</td>
      <td class="text-gray-400 text-[11px]">${p.username?esc(p.username):'—'}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="5" class="text-center text-gray-400 py-3">No matches</td></tr>';
  // ---- tree ----
  renderTree();
}

function renderTree(){
  const byMgr = {};
  PEOPLE.forEach(p => { (byMgr[p.manager_id||0] = byMgr[p.manager_id||0] || []).push(p); });
  const node = p => {
    const kids = (byMgr[p.id]||[]).sort((a,b)=>a.full_name.localeCompare(b.full_name));
    const dept = [p.department, p.sub_department].filter(Boolean).join(' / ');
    return `<li>
      <span class="inline-flex items-center gap-1.5 py-0.5">
        <i class="fa-solid fa-user text-gray-300 text-[10px]"></i>
        <span class="font-medium">${esc(p.full_name)}</span>
        <span class="text-gray-400">· ${esc(p.designation||'')}${dept?' · '+esc(dept):''}</span>
        ${p.role?badge(ROLES[p.role]||p.role, ROLE_COLOR[p.role]||'gray'):''}
      </span>
      ${kids.length?`<ul class="ml-4 border-l border-gray-200 pl-3 mt-0.5">${kids.map(node).join('')}</ul>`:''}
    </li>`;
  };
  const roots = (byMgr[0]||[]).sort((a,b)=>a.full_name.localeCompare(b.full_name));
  document.getElementById('tree').innerHTML = roots.length
    ? `<ul class="space-y-0.5">${roots.map(node).join('')}</ul>`
    : '<div class="text-gray-400">No employees</div>';
}

async function setRole(empId, role){
  const r = await apiSend('org.php?id='+empId+'&action=role', { role }, 'PATCH');
  if(!r.success){ toast(r.error||'Failed', false); return load(); }
  const p = PEOPLE.find(x=>x.id===empId); if(p) p.role = role;
  toast('Role updated'); render();
}
async function setManager(empId, managerId){
  const r = await apiSend('org.php?id='+empId+'&action=manager', { manager_id: managerId||null }, 'PATCH');
  if(!r.success){ toast(r.error||'Failed', false); return load(); }
  const p = PEOPLE.find(x=>x.id===empId); if(p){ p.manager_id = r.data.manager_id; }
  toast('Reporting updated'); render();
}

load();

/* ===== Organisation masters (My Org / Departments / Designations) ===== */
let ORG_CAN_EDIT = false, ORG_DEPTS = [], ORG_DESIGS = [];
const OP_FIELDS = ['org_name','legal_name','industry','founded_year','gstin','pan','cin','website','email','phone','hq_address','locations'];
async function loadOrgMasters(){
  const { data } = await apiGet('org-masters.php', {});
  ORG_CAN_EDIT = !!data.can_edit; ORG_DEPTS = data.departments||[]; ORG_DESIGS = data.designations||[];
  const p = data.profile||{};
  OP_FIELDS.forEach(f => { const el = document.getElementById('op_'+f); if (el){ el.value = p[f]??''; el.disabled = !ORG_CAN_EDIT; } });
  document.getElementById('orgSaveBtn').classList.toggle('hidden', !ORG_CAN_EDIT);
  document.getElementById('deptForm').style.display = ORG_CAN_EDIT ? '' : 'none';
  document.getElementById('desigForm').style.display = ORG_CAN_EDIT ? '' : 'none';
  // dept datalist + designation dept select
  const depts = [...new Set(ORG_DEPTS.map(d=>d.department))];
  document.getElementById('deptList').innerHTML = depts.map(d=>`<option value="${esc(d)}">`).join('');
  document.querySelector('#desigForm select[name=department]').innerHTML =
    '<option value="">—</option>' + depts.map(d=>`<option>${esc(d)}</option>`).join('');
  document.getElementById('deptRows').innerHTML = ORG_DEPTS.map(d=>`
    <tr><td class="font-medium">${esc(d.department)}</td><td>${esc(d.sub_department||'—')}</td>
    <td class="rowact">${ORG_CAN_EDIT?`<button onclick="delDept(${d.id})" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">×</button>`:''}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-gray-400 text-center py-2">No departments defined</td></tr>';
  document.getElementById('desigRows').innerHTML = ORG_DESIGS.map(d=>`
    <tr><td class="font-medium">${esc(d.title)}</td>
    <td>${d.level?badge('L'+d.level,'blue'):'—'}</td><td class="text-[11px]">${esc(d.department||'—')}</td>
    <td>${+d.exec_leadership?badge('Yes','indigo'):'<span class="text-gray-300">No</span>'}</td>
    <td class="rowact">${ORG_CAN_EDIT?`<button onclick="delDesig(${d.id})" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">×</button>`:''}</td></tr>`).join('')
    || '<tr><td colspan="5" class="text-gray-400 text-center py-2">No designations defined</td></tr>';
}
async function saveOrgProfile(){
  const body = {};
  OP_FIELDS.forEach(f => body[f] = document.getElementById('op_'+f).value);
  const res = await apiSend('org-masters.php?profile=1', body);
  toast(res.success ? 'Organisation profile saved' : (res.error||'Failed'), !!res.success);
}
async function addDept(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('org-masters.php?department=1', { department:f.department.value, sub_department:f.sub_department.value });
  if (res.success){ toast('Added'); f.sub_department.value=''; loadOrgMasters(); } else toast(res.error||'Failed', false);
}
async function delDept(id){
  const res = await apiSend('org-masters.php?department_id='+id, {}, 'DELETE');
  if (res.success){ loadOrgMasters(); } else toast(res.error||'Failed', false);
}
async function addDesig(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('org-masters.php?designation=1', { title:f.title.value, level:f.level.value,
    department:f.department.value, exec_leadership:f.exec.checked?1:0 });
  if (res.success){ toast('Added'); f.title.value=''; loadOrgMasters(); } else toast(res.error||'Failed', false);
}
async function delDesig(id){
  const res = await apiSend('org-masters.php?designation_id='+id, {}, 'DELETE');
  if (res.success){ loadOrgMasters(); } else toast(res.error||'Failed', false);
}
loadOrgMasters();
// deep-link: ?tab=departments|designations scrolls to that master (sidebar virtual links)
(function(){ const t = new URLSearchParams(location.search).get('tab');
  if (t && document.getElementById(t)) document.getElementById(t).scrollIntoView({behavior:'smooth'}); })();
</script>
</html>
