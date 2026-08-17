<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// same gate as the API: admin + the HR department (capability 'people')
if (!(sales_role() === 'admin' || payg_role_can(sales_role(), 'people'))) {
    header('Location: sales-dashboard.php'); exit;
}
sales_head('User Management');
sales_header($me, 'People');
sales_nav('users');
?>
<main class="p-3 space-y-2">
  <div id="pplStrip" class="flex gap-2 flex-wrap"></div>

  <?php
  // Figma-IA filtered views of this same grid: Board of Directors / Advisory Board /
  // Executive Leadership (governance) and Employees / Contractors (type).
  $govFilter  = in_array($_GET['gov'] ?? '', ['board', 'advisory', 'executive', 'execution'], true) ? $_GET['gov'] : '';
  $typeFilter = in_array($_GET['type'] ?? '', ['employee', 'contractor'], true) ? $_GET['type'] : '';
  $headings = ['board' => 'BOARD OF DIRECTORS', 'advisory' => 'ADVISORY BOARD', 'executive' => 'EXECUTIVE LEADERSHIP TEAM',
               'employee' => 'EMPLOYEES', 'contractor' => 'CONTRACTORS'];
  $heading = $headings[$govFilter] ?? $headings[$typeFilter] ?? 'PEOPLE';
  ?>
  <div class="flex items-center gap-3 flex-wrap">
    <span class="text-sm font-bold text-gray-700 tracking-wide"><?= $heading ?></span>
    <span id="countLbl" class="text-xs text-gray-400"></span>
    <?php if ($govFilter || $typeFilter): ?>
      <a href="sales-users.php" class="text-[10px] text-indigo-600 hover:underline"><i class="fa-solid fa-xmark"></i> clear filter — all people</a>
    <?php endif; ?>
    <div class="flex-1"></div>
    <input id="q" class="ctl w-64" placeholder="Filter by name, email…" oninput="render()">
    <button onclick="openBulk()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-file-arrow-up text-indigo-500"></i> Bulk upload</button>
    <button onclick="startAdd()" class="btn-p"><i class="fa-solid fa-plus"></i> Add person</button>
  </div>

  <div id="queueNote" class="hidden text-[11px] bg-amber-50 border border-amber-200 text-amber-700 rounded px-3 py-1.5">
    <i class="fa-solid fa-hourglass-half"></i> Your changes are queued for HR Head approval before they take effect.
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense" id="grid">
      <thead><tr>
        <th>Governance</th><th>Type</th><th>Person ID</th><th>Name</th><th>Title</th><th>Role (login)</th>
        <th>Department</th><th>Sub department</th><th>Reports to</th><th>Groups</th><th>Contact</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody id="rows"></tbody>
      <tbody><tr id="addHint"><td colspan="13" class="text-center py-2">
        <button onclick="startAdd()" class="text-indigo-600 text-xs hover:underline"><i class="fa-solid fa-plus"></i> Click to add a new person (or press Insert)</button>
      </td></tr></tbody>
    </table></div>
  </div>
  <div class="text-[10px] text-gray-400">Click any cell to edit it in place — Enter saves, Esc cancels. Governance <b>Board</b> = full access to everything; <b>Executive</b> joins the C-Suite group automatically.</div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<style>
  .kstat { border:1px solid #e5e7eb; border-radius:10px; padding:8px 12px; background:#fff; min-width:138px; }
  .kstat .kt { font-size:10px; color:#9ca3af; }
  .kstat .kn { font-size:18px; font-weight:700; color:#1f2937; line-height:1.15; }
  .kstat .ks { font-size:10px; color:#9ca3af; }
</style>
<?php sales_scripts(); ?>
<script>
const IS_ADMIN = <?= json_encode(sales_role() === 'admin') ?>;
let PEOPLE = [], ROLES = {}, MANAGERS = [], GROUPS = [], MEMBERS = {}, CAN_PRIV = false, PRIV_ROLES = [];
const GOV = { board:['Board','red'], advisory:['Advisory','purple'], executive:['Executive','indigo'], execution:['Execution','blue'] };
const TYPE = { employee:['Employee','indigo'], contractor:['Contractor','yellow'] };
const DEPT_COLOR = { Sales:'green', Service:'blue', HR:'purple', IT:'yellow', Support:'gray' };
const pid = id => 'PID-' + String(id).padStart(3,'0');


/* Figma list pattern: four tiles, each with an icon, a count and a sub-line. */
function renderStrip(elId, tiles, total){
  const el = document.getElementById(elId); if (!el) return;
  const pct = n => total ? ((n/total)*100).toFixed(2) + '%' : '0%';
  el.innerHTML = tiles.map(([ic,label,val,sub]) => `<div class="kstat">
      <div class="kt">${ic} ${esc(label)}</div><div class="kn">${val}</div>
      <div class="ks">${esc(sub === '%' ? pct(val) : sub)}</div></div>`).join('');
}

let ORG_DEPTS = [], ORG_SUBS = {}, ORG_DESIGS = [], EMP_CFS = [];
async function load(){
  const [u, g, m, om, cf] = await Promise.all([
    apiGet('users.php', {}),
    apiGet('groups.php', { list:1 }).catch(()=>({data:[]})),
    apiGet('groups.php', { memberships:1 }).catch(()=>({data:{}})),
    apiGet('org-masters.php', {}).catch(()=>({data:{departments:[],designations:[]}})),
    apiGet('custom-fields.php', { entity:'employee' }).catch(()=>({data:[]})),
  ]);
  // organisation masters feed the grid's Department / Sub-department / Title dropdowns
  ORG_DEPTS = [...new Set((om.data.departments||[]).map(d=>d.department))];
  ORG_SUBS = {};
  (om.data.departments||[]).forEach(d=>{ if(d.sub_department){ (ORG_SUBS[d.department] = ORG_SUBS[d.department]||[]).push(d.sub_department); } });
  ORG_DESIGS = (om.data.designations||[]).map(d=>d.title);
  EMP_CFS = cf.data || [];                 // admin-defined additional fields for people
  PEOPLE = u.data.people || []; ROLES = u.data.roles || {}; MANAGERS = u.data.managers || [];
  CAN_PRIV = !!u.data.can_grant_privileged; PRIV_ROLES = u.data.privileged_roles || [];
  GROUPS = (g.data||[]).filter(x=>+x.is_active);
  MEMBERS = m.data || {};
  document.getElementById('queueNote').classList.toggle('hidden', !u.data.needs_approval);
  render();
}

const GOV_FILTER = <?= json_encode($govFilter) ?>, TYPE_FILTER = <?= json_encode($typeFilter) ?>;
function visiblePeople(){
  const q = (document.getElementById('q').value||'').toLowerCase().trim();
  let list = PEOPLE;
  if (GOV_FILTER)  list = list.filter(p => (p.governance||'execution') === GOV_FILTER);
  if (TYPE_FILTER) list = list.filter(p => (p.emp_type||'employee') === TYPE_FILTER);
  if (!q) return list;
  return list.filter(p => [p.full_name, p.email, p.username, p.department, p.designation, p.role_label]
    .some(v => (v||'').toLowerCase().includes(q)));
}

function roleBadgeColor(p){ return DEPT_COLOR[p.dept_group] || 'gray'; }

function render(){
  const list = visiblePeople();
  const withLogin = PEOPLE.filter(p => p.username);
  renderStrip('pplStrip', [
    ['👥','Total People', PEOPLE.length, 'On record'],
    ['✅','Active Logins', withLogin.filter(p => +p.is_active !== 0).length, '%'],
    ['🧾','Contractors', PEOPLE.filter(p => (p.emp_type||'employee') === 'contractor').length, '%'],
    ['🏛','Governance tier', PEOPLE.filter(p => (p.governance||'execution') !== 'execution').length, 'board / advisory / exec'],
  ], PEOPLE.length);
  document.getElementById('countLbl').textContent = '· ' + list.length + ' of ' + PEOPLE.length;
  document.getElementById('rows').innerHTML = list.map(p => {
    const [gl, gc] = GOV[p.governance || 'execution'];
    const [tl, tc] = TYPE[p.emp_type || 'employee'];
    const myGroups = (MEMBERS[p.employee_id] || []).map(gid => GROUPS.find(g => +g.id === gid)).filter(Boolean);
    return `<tr data-eid="${p.employee_id}">
      <td class="cell" data-f="governance">${badge(gl, gc)}</td>
      <td class="cell" data-f="emp_type">${badge(tl, tc)}</td>
      <td class="font-mono text-[11px]"><a onclick="openPerson(${p.employee_id})" title="View profile & additional fields"
          class="text-indigo-600 hover:underline cursor-pointer">${pid(p.employee_id)}</a></td>
      <td class="cell font-semibold" data-f="full_name">${esc(p.full_name)}
        <i onclick="event.stopPropagation(); openPerson(${p.employee_id})" title="View profile & additional fields"
           class="fa-solid fa-address-card text-gray-300 hover:text-indigo-500 cursor-pointer ml-1"></i></td>
      <td class="cell" data-f="designation">${p.designation ? badge(esc(p.designation), roleBadgeColor(p)) : '<span class="text-gray-300">—</span>'}</td>
      <td class="cell" data-f="role">${p.role ? `${esc(p.role_label)}<div class="text-[10px] text-gray-400">${esc(p.username||'')}</div>` : '<span class="text-gray-400 text-[10px]">no login · click to add</span>'}</td>
      <td class="cell" data-f="department">${esc(p.department||'') || '<span class="text-gray-300">—</span>'}</td>
      <td class="cell" data-f="sub_department">${esc(p.sub_department||'') || '<span class="text-gray-300">—</span>'}</td>
      <td class="cell" data-f="manager_id">${p.manager_name ? `${esc(p.manager_name)}` : '<span class="text-gray-300">—</span>'}</td>
      <td class="cell" data-f="groups">${myGroups.map(g=>`<span class="text-[9px] ${+g.grant_all?'bg-red-100 text-red-700':'bg-indigo-50 text-indigo-700'} rounded px-1.5 py-0.5 mr-0.5">${esc(g.name)}</span>`).join('') || '<span class="text-gray-300 text-[10px]">+ groups</span>'}</td>
      <td class="text-[11px]">
        <div class="cell text-indigo-600" data-f="email">${esc(p.email||'') || '<span class="text-gray-300">—</span>'}</div>
        <div class="cell text-gray-500" data-f="phone">${esc(p.phone||'') || '<span class="text-gray-300">—</span>'}</div>
      </td>
      <td class="cell" data-f="is_active">${p.has_login
        ? (p.is_active ? '<span class="text-green-600 text-[11px]">● active</span>' : '<span class="text-gray-400 text-[11px]">○ inactive</span>')
        : '<span class="text-gray-300 text-[10px]">no login</span>'}</td>
      <td class="rowact text-center">
        <button onclick="event.stopPropagation(); openPerson(${p.employee_id})" title="Edit / Profile" class="text-indigo-600 hover:text-indigo-800"><i class="fa-solid fa-pen"></i></button>
        ${IS_ADMIN && p.has_login && p.role !== 'admin' ? `<button onclick="event.stopPropagation(); deletePerson(${p.employee_id})" title="Deactivate login" class="text-red-500 hover:text-red-700 ml-1.5"><i class="fa-solid fa-trash"></i></button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="13" class="text-center text-gray-400 py-6">No people match</td></tr>';
}

async function deletePerson(eid) {
  const p = PEOPLE.find(x => x.employee_id === eid);
  if (!p) return;
  if (!confirm(`Deactivate login and access for ${p.full_name}?`)) return;
  const res = await apiSend('users.php?id=' + eid, {}, 'DELETE');
  if (res.success) {
    toast(res.message || 'Login deactivated');
    load();
  } else {
    toast(res.error || 'Deactivation failed', false);
  }
}

/* ============ click-to-edit (Excel style) ============ */
document.getElementById('rows').addEventListener('click', e => {
  const cell = e.target.closest('.cell'); if (!cell || cell.querySelector('input,select')) return;
  const tr = cell.closest('tr'); const eid = +tr.dataset.eid;
  const p = PEOPLE.find(x => x.employee_id === eid); if (!p) return;
  const f = cell.dataset.f;
  if (f === 'groups') return editGroups(p, cell);
  if (f === 'role' && !p.has_login) return addLoginModal(p);
  if (f === 'is_active') { if (p.has_login) toggleActive(p); return; }
  openEditor(p, cell, f);
});

function selHtml(opts, cur){ return opts.map(([v,l]) => `<option value="${esc(String(v))}" ${String(v)===String(cur??'')?'selected':''}>${esc(l)}</option>`).join(''); }

function openEditor(p, cell, f){
  const old = cell.innerHTML;
  let editor;
  if (f === 'governance')      editor = `<select class="ctl w-full">${selHtml(Object.entries(GOV).map(([k,[l]])=>[k,l]), p.governance||'execution')}</select>`;
  else if (f === 'emp_type')   editor = `<select class="ctl w-full">${selHtml(Object.entries(TYPE).map(([k,[l]])=>[k,l]), p.emp_type||'employee')}</select>`;
  else if (f === 'role')       editor = `<select class="ctl w-full">${selHtml(Object.entries(ROLES).filter(([k])=>CAN_PRIV||!PRIV_ROLES.includes(k)||k===p.role), p.role)}</select>`;
  else if (f === 'manager_id') editor = `<select class="ctl w-full"><option value="">— nobody —</option>${selHtml(MANAGERS.filter(m=>m.id!==p.employee_id).map(m=>[m.id, m.name]), p.manager_id)}</select>`;
  // masters-fed dropdowns (free entry still allowed when no master matches — nothing is lost)
  else if (f === 'department' && ORG_DEPTS.length)
    editor = `<select class="ctl w-full"><option value="">—</option>${selHtml(ORG_DEPTS.map(d=>[d,d]).concat(p.department&&!ORG_DEPTS.includes(p.department)?[[p.department,p.department+' (legacy)']]:[]), p.department)}</select>`;
  else if (f === 'sub_department' && (ORG_SUBS[p.department]||[]).length)
    editor = `<select class="ctl w-full"><option value="">—</option>${selHtml((ORG_SUBS[p.department]||[]).map(s=>[s,s]).concat(p.sub_department&&!(ORG_SUBS[p.department]||[]).includes(p.sub_department)?[[p.sub_department,p.sub_department+' (legacy)']]:[]), p.sub_department)}</select>`;
  else if (f === 'designation' && ORG_DESIGS.length){
    editor = `<input class="ctl w-full" list="desigDl" value="${esc(p[f]||'')}">`;
    if (!document.getElementById('desigDl')){
      const dl = document.createElement('datalist'); dl.id='desigDl';
      dl.innerHTML = ORG_DESIGS.map(d=>`<option value="${esc(d)}">`).join('');
      document.body.appendChild(dl);
    }
  }
  else                         editor = `<input class="ctl w-full" value="${esc(p[f]||'')}">`;
  cell.innerHTML = editor;
  const el = cell.querySelector('input,select');
  el.focus(); if (el.select) el.select();
  let done = false;
  const commit = async () => {
    if (done) return; done = true;
    const val = el.value;
    if (String(val) === String(p[f] ?? '')) { render(); return; }
    const res = await apiSend('users.php?id=' + p.employee_id, { [f]: val }, 'PATCH');
    if (res.success){ toast(res.message || 'Saved'); load(); }
    else { toast(res.error || 'Failed', false); render(); }
  };
  el.addEventListener('keydown', ev => {
    if (ev.key === 'Enter'){ ev.preventDefault(); commit(); }
    else if (ev.key === 'Escape'){ done = true; cell.innerHTML = old; }
  });
  el.addEventListener('blur', commit);
  if (el.tagName === 'SELECT') el.addEventListener('change', commit);
}

async function toggleActive(p){
  const to = p.is_active ? 0 : 1;
  if (!to && !confirm(`Deactivate ${p.full_name}'s login?`)) return;
  const res = await apiSend('users.php?id=' + p.employee_id, { is_active: to }, 'PATCH');
  if (res.success){ toast(res.message || 'Saved'); load(); } else toast(res.error||'Failed', false);
}

/* ---- groups cell: checkbox picker ---- */
function editGroups(p, cell){
  const mine = new Set(MEMBERS[p.employee_id] || []);
  openModal(`<div class="text-xs space-y-1">
    ${GROUPS.map(g => `<label class="flex items-center gap-2 py-0.5">
      <input type="checkbox" class="chk grp-tog" value="${g.id}" ${mine.has(+g.id)?'checked':''}>
      ${esc(g.name)} ${+g.grant_all?'<span class="text-[9px] bg-red-100 text-red-700 rounded px-1">FULL ACCESS</span>':''}
      <span class="text-gray-400 text-[10px]">${esc((g.modules||'').split(',').filter(Boolean).join(', '))}</span>
    </label>`).join('') || '<span class="text-gray-400">No active groups defined</span>'}
    <div class="flex justify-end gap-2 pt-2">
      <button onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button onclick="saveGroups(${p.employee_id})" class="btn-p">Save groups</button>
    </div></div>`, 'Groups — ' + p.full_name);
}
async function saveGroups(eid){
  const now = new Set([...document.querySelectorAll('.grp-tog:checked')].map(c=>+c.value));
  const before = new Set(MEMBERS[eid] || []);
  for (const g of GROUPS){
    const gid = +g.id;
    if (now.has(gid) && !before.has(gid)) await apiSend(`groups.php?id=${gid}&members=1`, { add:[eid] });
    if (!now.has(gid) && before.has(gid)) await apiSend(`groups.php?id=${gid}&members=1`, { remove:[eid] });
  }
  closeModal(); toast('Groups updated'); load();
}

/* ---- add login to an existing person ---- */
function addLoginModal(p){
  openModal(`<form onsubmit="saveLogin(event, ${p.employee_id})" class="space-y-2 text-xs">
    <p class="text-gray-500">Create a login for <b>${esc(p.full_name)}</b>. Email + mobile are required (password-reset OTPs).</p>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Username<input name="username" required class="ctl w-full mt-0.5"></label>
      <label class="block">Role<select name="role" class="ctl w-full mt-0.5">${selHtml(Object.entries(ROLES).filter(([k])=>CAN_PRIV||!PRIV_ROLES.includes(k)), 'sales_exec')}</select></label>
      <label class="block">Password<input name="password" type="password" required minlength="6" class="ctl w-full mt-0.5"></label>
      <label class="block">Email<input name="email" type="email" value="${esc(p.email||'')}" required class="ctl w-full mt-0.5"></label>
      <label class="block">Mobile<input name="phone" value="${esc(p.phone||'')}" required class="ctl w-full mt-0.5"></label>
    </div>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Create login</button></div></form>`, 'Add login — ' + p.full_name);
}
async function saveLogin(e, eid){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('users.php?id=' + eid, { username:f.username.value, role:f.role.value,
    password:f.password.value, email:f.email.value, phone:f.phone.value }, 'PATCH');
  if (res.success){ closeModal(); toast(res.message||'Login created'); load(); } else toast(res.error||'Failed', false);
}

/* ============ add new person (inline row, Excel style) ============ */
function startAdd(){
  if (document.getElementById('newRow')) { document.querySelector('#newRow input')?.focus(); return; }
  const tr = document.createElement('tr');
  tr.id = 'newRow'; tr.className = 'bg-indigo-50/50';
  tr.innerHTML = `
    <td><select id="n_gov" class="ctl w-full">${selHtml(Object.entries(GOV).map(([k,[l]])=>[k,l]), 'execution')}</select></td>
    <td><select id="n_type" class="ctl w-full">${selHtml(Object.entries(TYPE).map(([k,[l]])=>[k,l]), 'employee')}</select></td>
    <td class="text-gray-300 font-mono text-[11px]">PID-new</td>
    <td><input id="n_name" class="ctl w-full" placeholder="Full name *"></td>
    <td><input id="n_desig" class="ctl w-full" placeholder="Title"></td>
    <td><div class="flex gap-1"><input id="n_user" class="ctl w-20" placeholder="username">
        <select id="n_role" class="ctl">${selHtml([['','—'],...Object.entries(ROLES).filter(([k])=>CAN_PRIV||!PRIV_ROLES.includes(k))], '')}</select></div>
        <input id="n_pass" type="password" class="ctl w-full mt-0.5" placeholder="password (if login)"></td>
    <td><input id="n_dept" class="ctl w-full" placeholder="Dept"></td>
    <td><input id="n_sub" class="ctl w-full" placeholder="Sub dept"></td>
    <td><select id="n_mgr" class="ctl w-full"><option value="">— nobody —</option>${selHtml(MANAGERS.map(m=>[m.id,m.name]), '')}</select></td>
    <td class="text-gray-300 text-[10px]">assign after save</td>
    <td><input id="n_email" class="ctl w-full" placeholder="email"><input id="n_phone" class="ctl w-full mt-0.5" placeholder="mobile"></td>
    <td class="whitespace-nowrap">
      <button onclick="saveNew()" class="text-[10px] bg-indigo-600 text-white px-2 py-1 rounded">Save</button>
      <button onclick="document.getElementById('newRow').remove()" class="text-[10px] bg-gray-100 text-gray-600 px-2 py-1 rounded">✕</button>
    </td>`;
  document.getElementById('addHint').before(tr);
  tr.addEventListener('keydown', ev => {
    if (ev.key === 'Enter' && ev.target.tagName !== 'SELECT'){ ev.preventDefault(); saveNew(); }
    if (ev.key === 'Escape') tr.remove();
  });
  document.getElementById('n_name').focus();
}
document.addEventListener('keydown', e => {
  const typing = ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName);
  if (e.key === 'Insert' && !typing) startAdd();
});
async function saveNew(){
  const v = id => document.getElementById(id).value.trim();
  if (!v('n_name')) { toast('Full name is required', false); return; }
  const res = await apiSend('users.php', { full_name:v('n_name'), designation:v('n_desig'),
    department:v('n_dept'), sub_department:v('n_sub'), manager_id:v('n_mgr'),
    email:v('n_email'), phone:v('n_phone'), username:v('n_user'), role:v('n_role'), password:v('n_pass'),
    emp_type:document.getElementById('n_type').value, governance:document.getElementById('n_gov').value });
  if (res.success){ document.getElementById('newRow')?.remove(); toast(res.message||'Person added'); load(); }
  else toast(res.error||'Failed', false);
}

/* ============ person profile (click a user → standard + additional fields) ============ */
async function openPerson(eid){
  const p = PEOPLE.find(x => +x.employee_id === +eid);
  if (!p) return;
  const vals = (await apiGet('custom-fields.php', { values:1, entity:'employee', entity_id: eid }).catch(()=>({data:{}}))).data || {};
  const [gl, gc] = GOV[p.governance || 'execution'];
  const [tl, tc] = TYPE[p.emp_type || 'employee'];
  const myGroups = (MEMBERS[eid] || []).map(gid => GROUPS.find(g => +g.id === gid)).filter(Boolean);
  const cfInput = f => {
    const v = vals[f.field_key] ?? '';
    const name = `pcf_${f.field_key}`;
    if (f.field_type === 'select') return `<select name="${name}" class="ctl w-full mt-0.5"><option value="">—</option>${(f.options||[]).map(o=>`<option ${o===v?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
    if (f.field_type === 'checkbox') return `<label class="flex items-center gap-2 mt-1"><input type="checkbox" name="${name}" ${v==='1'||v==='on'?'checked':''}> Yes</label>`;
    if (f.field_type === 'textarea') return `<textarea name="${name}" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(v)}</textarea>`;
    const t = f.field_type === 'number' ? 'number' : f.field_type === 'date' ? 'date' : 'text';
    return `<input type="${t}" name="${name}" value="${esc(v)}" class="ctl w-full mt-0.5">`;
  };
  openModal(`<div class="text-xs space-y-3">
    <div class="flex items-start gap-3">
      <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-sm">${esc((p.full_name||'?').split(' ').map(w=>w[0]).slice(0,2).join(''))}</div>
      <div class="flex-1">
        <div class="font-semibold text-sm">${esc(p.full_name)} <span class="font-mono text-[10px] text-gray-400 ml-1">${pid(eid)}</span></div>
        <div class="text-gray-500">${esc(p.designation||'—')} · ${esc(p.department||'—')}${p.sub_department?' › '+esc(p.sub_department):''}</div>
        <div class="mt-1 flex flex-wrap gap-1">${badge(gl,gc)} ${badge(tl,tc)} ${p.role?badge(p.role_label,'blue'):''}
          ${myGroups.map(g=>`<span class="text-[9px] ${+g.grant_all?'bg-red-100 text-red-700':'bg-indigo-50 text-indigo-700'} rounded px-1.5 py-0.5">${esc(g.name)}</span>`).join('')}</div>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px] border-t pt-2">
      <div><span class="text-gray-400">Email</span><br>${esc(p.email||'—')}</div>
      <div><span class="text-gray-400">Phone</span><br>${esc(p.phone||'—')}</div>
      <div><span class="text-gray-400">Reports to</span><br>${esc(p.manager_name||'—')}</div>
      <div><span class="text-gray-400">Login</span><br>${esc(p.username||'no login')}</div>
    </div>
    <div class="border-t pt-2">
      <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Performance
        <span class="normal-case">(automatic — from TAT/SLA outcomes and escalations)</span></div>
      <div id="perfBox" class="text-[11px] text-gray-400">Loading…</div>
    </div>
    <form onsubmit="savePersonCustom(event, ${eid})" class="border-t pt-2">
      <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1.5">Additional fields
        <span class="normal-case">(defined by admin in Settings → Custom Fields → User / Employee)</span></div>
      ${EMP_CFS.length ? `<div class="grid grid-cols-2 gap-2">
        ${EMP_CFS.map(f=>`<label class="block">${esc(f.label)}${+f.is_required?' *':''}${cfInput(f)}</label>`).join('')}
      </div>
      <div class="flex justify-end mt-2"><button class="btn-p">Save additional fields</button></div>`
      : '<div class="text-gray-400">No additional fields defined yet — admin can add them under Settings → Custom Fields.</div>'}
    </form>
  </div>`, 'Person profile');
  loadPersonPerf(eid);
}

/* automatic performance summary: SLA/TAT adherence + escalations feed this, no manual entry */
async function loadPersonPerf(eid){
  const box = document.getElementById('perfBox');
  if (!box) return;
  const res = await apiGet('performance.php', { employee_id: eid, days: 90 }).catch(()=>null);
  if (!res || !res.success) { box.innerHTML = '<span class="text-gray-400">Not available for this person.</span>'; return; }
  const d = res.data, t = d.tat, e = d.escalations;
  const col = d.performance_score >= 85 ? '#16a34a' : d.performance_score >= 65 ? '#f59e0b' : '#dc2626';
  box.innerHTML = `
    <div class="flex items-center gap-3 flex-wrap">
      <div><span class="text-xl font-bold" style="color:${col}">${d.performance_score}</span>
        <span class="text-[10px] text-gray-400">/100</span></div>
      ${badge(d.band, d.band==='at risk'?'red':d.band==='watch'?'yellow':'green')}
      <div class="text-[11px]">TAT adherence <b>${t.adherence_pct===null?'—':t.adherence_pct+'%'}</b>
        <span class="text-gray-400">(${t.met} met · ${t.breached} breached of ${t.with_sla})</span></div>
      <div class="text-[11px]">Escalations <b class="${(e.items||0)>0?'text-red-600':''}">${e.items||0}</b>
        ${(e.to_board||0)>0?`<span class="text-red-600">· ${e.to_board} reached the Board</span>`:''}</div>
      <div class="text-[11px] text-gray-400">last 90 days</div>
      <a href="sales-performance.php" class="text-[11px] text-indigo-600 hover:underline ml-auto">Full view →</a>
    </div>
    ${(d.escalation_trail||[]).length ? `<div class="mt-1 text-[10px] text-gray-500">Latest: ${
      d.escalation_trail.slice(0,3).map(r=>`${esc(r.esl_ref)}${r.sla_ref?' · '+esc(r.sla_ref):''}${r.esc_ref?' · '+esc(r.esc_ref):''}`).join(' | ')}</div>` : ''}`;
}
async function savePersonCustom(e, eid){
  e.preventDefault();
  const custom = {};
  EMP_CFS.forEach(f => {
    const el = e.target[`pcf_${f.field_key}`];
    if (!el) return;
    custom[f.field_key] = f.field_type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
  });
  const res = await apiSend('custom-fields.php?values=1', { entity:'employee', entity_id: eid, values: custom });
  if (res.success){ toast('Additional fields saved'); closeModal(); } else toast(res.error||'Failed', false);
}

/* ============ bulk upload ============ */
const CSV_COLS = ['full_name','designation','department','sub_department','email','phone','manager_email','username','role','password','emp_type','governance'];
function csvTemplate(){
  // header = standard columns + every admin-defined additional field (by its title),
  // so the downloaded sheet always matches what the system currently maps
  const cfHdr = EMP_CFS.map(f => f.label);
  const hdr = CSV_COLS.concat(cfHdr).join(',');
  const sample = ['Anita Rao','Sales Executive','Sales','','anita.rao@payg.in','9876501234','','anita','sales_exec','Welcome@123','employee','execution']
    .concat(EMP_CFS.map(f => f.field_type==='date' ? '2026-01-31' : f.field_type==='number' ? '10'
                           : f.field_type==='select' ? ((f.options||[])[0]||'') : f.field_type==='checkbox' ? '1' : 'sample'));
  return hdr + '\n' + sample.map(v => /[",\n]/.test(v) ? '"'+String(v).replace(/"/g,'""')+'"' : v).join(',') + '\n';
}
function openBulk(){
  openModal(`<div class="text-xs space-y-3">
    <p class="text-gray-500">Upload a CSV/Excel-saved-as-CSV with a header row. Columns (any order, extras ignored):<br>
      <code class="text-[10px] bg-gray-100 rounded px-1">${CSV_COLS.join(', ')}</code>
      ${EMP_CFS.length?`<br>Additional fields (current custom columns): <code class="text-[10px] bg-amber-50 rounded px-1">${EMP_CFS.map(f=>esc(f.label)).join(', ')}</code>`:''}<br>
      <b>role</b> one of: ${Object.keys(ROLES).join(', ')} · <b>manager_email</b> links "reports to" by that person's email.</p>
    <a href="data:text/csv;charset=utf-8,${encodeURIComponent(csvTemplate())}"
       download="payg-people-template.csv" class="text-indigo-600 hover:underline"><i class="fa-solid fa-download"></i> Download template (${CSV_COLS.length + EMP_CFS.length} columns, incl. ${EMP_CFS.length} custom)</a>
    <input id="bulkFile" type="file" accept=".csv,text/csv" class="block w-full text-xs border rounded p-2">
    <div id="bulkPreview" class="text-gray-500"></div>
    <div class="flex justify-end gap-2">
      <button onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button id="bulkGo" onclick="runBulk()" class="btn-p" disabled>Upload</button>
    </div>
    <div id="bulkResults" class="max-h-48 overflow-y-auto"></div>
  </div>`, 'Bulk upload — people');
  document.getElementById('bulkFile').addEventListener('change', prepBulk);
}
let BULK_ROWS = [];
function parseCsv(text){
  const rows = []; let row = [], cur = '', inQ = false;
  for (let i = 0; i < text.length; i++){
    const c = text[i];
    if (inQ){ if (c === '"'){ if (text[i+1] === '"'){ cur += '"'; i++; } else inQ = false; } else cur += c; }
    else if (c === '"') inQ = true;
    else if (c === ','){ row.push(cur); cur = ''; }
    else if (c === '\n' || c === '\r'){ if (cur !== '' || row.length){ row.push(cur); rows.push(row); row = []; cur = ''; } if (c === '\r' && text[i+1] === '\n') i++; }
    else cur += c;
  }
  if (cur !== '' || row.length){ row.push(cur); rows.push(row); }
  return rows;
}
async function prepBulk(e){
  const file = e.target.files[0]; if (!file) return;
  const text = await file.text();
  const rows = parseCsv(text).filter(r => r.some(c => c.trim() !== ''));
  if (rows.length < 2){ document.getElementById('bulkPreview').textContent = 'No data rows found.'; return; }
  const hdr = rows[0].map(h => h.trim().toLowerCase());
  const emailToId = Object.fromEntries(PEOPLE.filter(p=>p.email).map(p=>[p.email.toLowerCase(), p.employee_id]));
  // custom columns match an additional field's title or key, case-insensitively
  const cfByHeader = {};
  EMP_CFS.forEach(f => { cfByHeader[f.label.toLowerCase()] = f.field_key; cfByHeader[f.field_key.toLowerCase()] = f.field_key; });
  BULK_ROWS = rows.slice(1).map(r => {
    const o = {};
    hdr.forEach((h, i) => {
      const v = (r[i]||'').trim();
      if (CSV_COLS.includes(h)) o[h] = v;
      else if (cfByHeader[h] && v !== '') (o.custom = o.custom || {})[cfByHeader[h]] = v;
    });
    if (o.manager_email){ o.manager_id = emailToId[o.manager_email.toLowerCase()] || ''; delete o.manager_email; }
    return o;
  }).filter(o => o.full_name);
  document.getElementById('bulkPreview').innerHTML = `<b>${BULK_ROWS.length}</b> row(s) ready — e.g. ${esc(BULK_ROWS[0]?.full_name||'')}`;
  document.getElementById('bulkGo').disabled = BULK_ROWS.length === 0;
}
async function runBulk(){
  document.getElementById('bulkGo').disabled = true;
  const res = await apiSend('users.php?bulk=1', { rows: BULK_ROWS });
  if (!res.success){ toast(res.error||'Failed', false); document.getElementById('bulkGo').disabled = false; return; }
  const d = res.data;
  toast(`${d.created} added, ${d.failed} failed`, d.failed === 0);
  document.getElementById('bulkResults').innerHTML = `<table class="dense"><tbody>` +
    d.results.map(r => `<tr><td>${r.row}</td><td>${esc(r.name)}</td>
      <td>${r.ok ? '<span class="text-green-600">✓ ' + (r.queued?'queued for approval':'added') + '</span>'
                 : '<span class="text-red-600">✗ ' + esc(r.error||'') + '</span>'}</td></tr>`).join('') + '</tbody></table>';
  load();
}

load();
</script>
</html>
