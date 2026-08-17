<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$canManage = sales_role() === 'admin' || payg_role_can(sales_role(), 'people');
if (!$canManage) { header('Location: sales-dashboard.php'); exit; }
sales_head('Employee Groups');
sales_header($me, 'Employee Groups');
sales_nav('groups');
// module keys a group may grant (from the live nav catalog)
$grantable = array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0]], array_keys(sales_catalog()), sales_catalog());
?>
<main class="p-2 space-y-2">

  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1"><i class="fa-solid fa-people-group text-indigo-500"></i> Groups grant access</div>
    <p class="text-xs text-gray-500">An employee can belong to <b>any number of groups</b>, and inherits every module a group carries — on top of what their role allows. Example: put someone in <b>Compliance Team</b> and they get the Compliance module without changing their role.</p>
  </div>

  <div class="card p-2 flex items-center gap-2">
    <button onclick="newGroup()" class="btn-p"><i class="fa-solid fa-plus"></i> New group</button>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2" id="groupCards"></div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const MODULES = <?= json_encode($grantable) ?>;
const IS_ADMIN = <?= sales_role() === 'admin' ? 'true' : 'false' ?>;
let employees = [];

async function setGrantAll(id, on){
  if (on && !confirm('Give this group FULL ACCESS? Members will see every module and every record (Board level).')){ load(); return; }
  const res = await apiSend('groups.php?id='+id, { grant_all: on?1:0 }, 'PATCH');
  if (res.success){ toast(on?'Group now has FULL access':'Full access revoked'); load(); }
  else { toast(res.error||'Failed', false); load(); }
}

async function pickers(){
  if (!employees.length) employees = (await apiGet('groups.php', { employees:1 })).data || [];
}

async function load(){
  const { data } = await apiGet('groups.php', { list:1 });
  document.getElementById('groupCards').innerHTML = (data||[]).map(g=>`
    <div class="card p-3 ${+g.is_active?'':'opacity-50'}">
      <div class="flex items-center justify-between">
        <div>
          <span class="font-semibold text-sm text-gray-800">${esc(g.name)}</span>
          ${+g.is_active?'':badge('inactive','gray')}
          <div class="text-[11px] text-gray-400">${esc(g.description||'')}</div>
        </div>
        <div class="flex gap-1">
          <button onclick="openGroup(${g.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Manage</button>
          ${+g.is_active?`<button onclick="deactivate(${g.id})" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Deactivate</button>`
            :`<button onclick="reactivate(${g.id})" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Reactivate</button>`}
        </div>
      </div>
      <div class="mt-2 text-[11px] text-gray-500"><i class="fa-solid fa-user text-gray-300"></i> ${g.member_count} member(s)
        ${IS_ADMIN?`<label class="ml-3 inline-flex items-center gap-1 cursor-pointer" title="Members get EVERY module and org-wide record visibility (Board)">
          <input type="checkbox" class="chk" ${+g.grant_all?'checked':''} onchange="setGrantAll(${g.id}, this.checked)"> <span class="text-[10px] uppercase">Full access</span></label>`:''}
      </div>
      <div class="mt-1 flex flex-wrap gap-1">
        ${+g.grant_all?'<span class="text-[10px] bg-red-100 text-red-700 rounded px-2 py-0.5 font-semibold"><i class="fa-solid fa-crown text-[8px]"></i> FULL ACCESS — all modules & records (Board)</span>':''}
        ${(g.modules||'').split(',').filter(Boolean).map(m=>`<span class="text-[10px] bg-emerald-50 text-emerald-700 rounded px-2 py-0.5"><i class="fa-solid fa-unlock text-[8px]"></i> ${esc(m)}</span>`).join('')
          || (+g.grant_all?'':'<span class="text-[10px] text-gray-400">grants no modules (organisational only)</span>')}
      </div>
    </div>`).join('') || '<div class="text-gray-400 text-xs p-4">No groups yet.</div>';
}

async function newGroup(){
  await pickers();
  openModal(`<form onsubmit="saveGroup(event)" class="space-y-2 text-xs">
    <label class="block">Name<input name="name" required class="ctl w-full mt-1" placeholder="e.g. Compliance Team"></label>
    <label class="block">Description<input name="description" class="ctl w-full mt-1"></label>
    <div class="text-[10px] uppercase text-gray-400 pt-1">Modules this group grants</div>
    <div class="grid grid-cols-3 gap-1 max-h-40 overflow-y-auto border rounded p-2">
      ${MODULES.map(m=>`<label class="flex items-center gap-1.5"><input type="checkbox" class="chk mod-chk" value="${m.key}">${esc(m.label)}</label>`).join('')}
    </div>
    <div class="text-[10px] uppercase text-gray-400 pt-1">Initial members</div>
    <div class="grid grid-cols-2 gap-1 max-h-40 overflow-y-auto border rounded p-2">
      ${employees.map(e=>`<label class="flex items-center gap-1.5"><input type="checkbox" class="chk emp-chk" value="${e.id}">${esc(e.full_name)}</label>`).join('')}
    </div>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Create group</button></div></form>`, 'New group');
}
async function saveGroup(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('groups.php', { name:f.name.value, description:f.description.value,
    modules:[...document.querySelectorAll('.mod-chk:checked')].map(c=>c.value),
    members:[...document.querySelectorAll('.emp-chk:checked')].map(c=>+c.value) });
  if (res.success){ closeModal(); toast('Group created'); load(); } else toast(res.error||'Failed', false);
}

async function openGroup(id){
  await pickers();
  const { data:g } = await apiGet('groups.php', { id });
  const memberIds = new Set((g.members||[]).map(m=>+m.employee_id));
  openModal(`
    <div class="text-xs space-y-3">
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Modules granted to members</div>
        <div class="grid grid-cols-3 gap-1 max-h-40 overflow-y-auto border rounded p-2">
          ${MODULES.map(m=>`<label class="flex items-center gap-1.5"><input type="checkbox" class="chk gmod" value="${m.key}" ${g.modules.includes(m.key)?'checked':''}>${esc(m.label)}</label>`).join('')}
        </div>
        <button onclick="saveAccess(${id})" class="btn-p mt-1">Save modules</button>
      </div>
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Members (${(g.members||[]).length})</div>
        <div class="grid grid-cols-2 gap-1 max-h-48 overflow-y-auto border rounded p-2">
          ${employees.map(e=>`<label class="flex items-center gap-1.5">
            <input type="checkbox" class="chk gmem" value="${e.id}" ${memberIds.has(+e.id)?'checked':''}>
            ${esc(e.full_name)} <span class="text-gray-400">${esc(e.role||e.designation||'')}</span></label>`).join('')}
        </div>
        <button onclick="saveMembers(${id}, [${[...memberIds].join(',')}])" class="btn-p mt-1">Save members</button>
      </div>
    </div>`, g.name);
}
async function saveAccess(id){
  const mods = [...document.querySelectorAll('.gmod:checked')].map(c=>c.value);
  const res = await apiSend('groups.php?id='+id+'&access=1', { modules: mods });
  if (res.success){ toast('Modules updated'); load(); } else toast(res.error||'Failed', false);
}
async function saveMembers(id, before){
  const now = new Set([...document.querySelectorAll('.gmem:checked')].map(c=>+c.value));
  const prev = new Set(before);
  const add = [...now].filter(x=>!prev.has(x));
  const remove = [...prev].filter(x=>!now.has(x));
  const res = await apiSend('groups.php?id='+id+'&members=1', { add, remove });
  if (res.success){ closeModal(); toast('Members updated'); load(); } else toast(res.error||'Failed', false);
}
async function deactivate(id){
  if (!confirm('Deactivate this group? Members lose the modules it grants.')) return;
  const res = await apiSend('groups.php?id='+id, {}, 'DELETE');
  if (res.success){ toast('Deactivated'); load(); } else toast(res.error||'Failed', false);
}
async function reactivate(id){
  const res = await apiSend('groups.php?id='+id, { is_active:1 }, 'PATCH');
  if (res.success){ toast('Reactivated'); load(); } else toast(res.error||'Failed', false);
}

load();
</script>
</html>
