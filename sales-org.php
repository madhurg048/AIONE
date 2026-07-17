<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Org & Roles');
sales_header($me, 'Admin · Org & Roles');
sales_nav('org');
?>
<main class="p-2 space-y-2">

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
</script>
</html>
