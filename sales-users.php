<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('People');
sales_header($me, 'People');
sales_nav('users');
?>
<main class="p-2 space-y-2">
  <div id="approvalBanner" class="hidden card p-2 bg-amber-50 border-amber-200 text-[11px] text-amber-800">
    <i class="fa-solid fa-circle-info"></i>
    Changes you make here are <b>sent to the HR Head for approval</b> and take effect once approved.
  </div>

  <div class="card">
    <div class="flex items-center justify-between p-2 border-b gap-2 flex-wrap">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-users text-indigo-500"></i> People <span id="count" class="text-gray-400 normal-case font-normal"></span>
      </span>
      <div class="flex items-center gap-2">
        <input id="q" class="ctl" placeholder="Filter by name, username, dept…" oninput="render()">
        <button onclick="openPerson()" class="btn-p"><i class="fa-solid fa-user-plus"></i> Add person</button>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="dense"><thead><tr>
        <th>Name</th><th>Role</th><th>Username</th><th>Department</th>
        <th>Reports to</th><th>Contact</th><th>Status</th><th>Last login</th><th></th>
      </tr></thead><tbody id="rows"></tbody></table>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b sticky top-0 bg-white">
      <h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
/**
 * People — ONE table, ONE form.
 *
 * This screen used to show two tables (Login Accounts + Employee Directory) and a form that
 * asked you to either "link to an existing employee" or fill a separate "or create a new
 * employee record" block. That exposed the CRM's internal employees/sales_users split as a
 * decision for whoever was adding a colleague, and it is how logins ended up with no contact
 * details and no way to reset their own password. A person and their login are now one row and
 * one form; the API (api/sales/users.php + _people.php) writes both in a single transaction.
 */
const IS_ADMIN = <?= sales_role() === 'admin' ? 'true' : 'false' ?>;   // only a real admin may impersonate
let PEOPLE = [], ROLES = {}, PRIVILEGED = [], CAN_GRANT_PRIVILEGED = false, MY_EMP_ID = 0;

const roleColor = {
  admin:'red', hr_head:'green', hr_manager:'green', hr:'green',
  sales_head:'indigo', manager:'indigo', sales_exec:'blue',
  service_manager:'pink', service_exec:'purple',
  support_manager:'orange', support:'orange', it_manager:'yellow', it:'yellow'
};

async function load() {
  const { data } = await apiGet('users.php');
  PEOPLE = data.people; ROLES = data.roles; PRIVILEGED = data.privileged_roles || [];
  CAN_GRANT_PRIVILEGED = !!data.can_grant_privileged; MY_EMP_ID = data.my_employee_id || 0;
  document.getElementById('approvalBanner').classList.toggle('hidden', !data.needs_approval);
  render();
}

function render() {
  const q = (document.getElementById('q').value || '').toLowerCase().trim();
  const list = PEOPLE.filter(p => !q ||
    [p.full_name, p.username, p.department, p.sub_department, p.designation, p.role_label]
      .filter(Boolean).join(' ').toLowerCase().includes(q));
  document.getElementById('count').textContent = `· ${list.length} of ${PEOPLE.length}`;

  document.getElementById('rows').innerHTML = list.map(p => {
    const dept = [p.department, p.sub_department].filter(Boolean).join(' / ');
    // A person who can sign in but has no email/mobile can never receive a reset OTP —
    // surface that here rather than at the moment they're locked out.
    const noReset = p.has_login && (!p.email || !p.phone);
    const contact = [p.email, p.phone].filter(Boolean).map(esc).join('<br>') || '—';
    return `<tr>
      <td class="font-medium">${esc(p.full_name)}
        <div class="text-[10px] text-gray-400">${esc(p.designation || '')}</div></td>
      <td>${p.role ? badge(p.role_label || p.role, roleColor[p.role] || 'gray') : '<span class="text-gray-300 text-[11px]">no login</span>'}</td>
      <td class="text-gray-600">${p.username ? esc(p.username) : '—'}</td>
      <td class="text-gray-500">${dept ? esc(dept) : '—'}</td>
      <td class="text-gray-500">${esc(p.manager_name || '—')}</td>
      <td class="text-gray-400 text-[11px]">${contact}
        ${noReset ? `<div class="text-[10px] text-amber-600" title="No email/mobile on file — this person cannot reset their own password"><i class="fa-solid fa-triangle-exclamation"></i> no reset contact</div>` : ''}</td>
      <td>${!p.has_login ? '<span class="text-gray-300 text-[11px]">—</span>'
            : (p.is_active == 1 ? '<span class="text-green-600 text-[11px]">● active</span>'
                                : '<span class="text-gray-400 text-[11px]">○ inactive</span>')}</td>
      <td class="text-gray-400 text-[11px]">${p.has_login ? esc(p.last_login_at || 'never') : '—'}</td>
      <td class="rowact whitespace-nowrap">
        ${(IS_ADMIN && p.has_login && p.is_active == 1)
          ? `<button onclick="loginAs(${p.user_id},'${esc(p.full_name)}')" class="text-teal-600 hover:text-teal-800" title="Log in as this person"><i class="fa-solid fa-right-to-bracket"></i></button>` : ''}
        <button onclick="openPerson(${p.employee_id})" class="text-indigo-600 hover:text-indigo-800 ml-2" title="Edit"><i class="fa-solid fa-pen"></i></button>
        ${p.has_login ? `<button onclick="resetPw(${p.employee_id},'${esc(p.full_name)}')" class="text-amber-600 hover:text-amber-800 ml-1" title="Reset password"><i class="fa-solid fa-key"></i></button>` : ''}
        ${(p.has_login && p.is_active == 1 && p.employee_id !== MY_EMP_ID)
          ? `<button onclick="deactivate(${p.employee_id},'${esc(p.full_name)}')" class="text-red-500 hover:text-red-700 ml-1" title="Deactivate login"><i class="fa-solid fa-user-slash"></i></button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="9" class="text-center text-gray-400 py-4">Nobody matches</td></tr>';
}

/** Roles the current user may actually assign — admin/*_head only appear for an Admin. */
function roleOptions(sel) {
  return Object.entries(ROLES)
    .filter(([k]) => CAN_GRANT_PRIVILEGED || !PRIVILEGED.includes(k) || k === sel)
    .map(([k, v]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${v}</option>`).join('');
}
function managerOptions(sel, selfId) {
  return '<option value="">— nobody —</option>' + PEOPLE
    .filter(p => p.employee_id !== selfId)          // nobody reports to themselves
    .map(p => `<option value="${p.employee_id}" ${p.employee_id == sel ? 'selected' : ''}>${esc(p.full_name)}${p.role_label ? ' · ' + esc(p.role_label) : ''}</option>`).join('');
}

/**
 * The single Add/Edit Person form. Everything about a person lives here: who they are, where
 * they sit, who they report to, how to reach them, and how they sign in. Pass an employee_id
 * to edit, nothing to add.
 */
function openPerson(id) {
  const p = id ? PEOPLE.find(x => x.employee_id === id) || {} : {};
  const isEdit = !!id;
  const isSelf = isEdit && p.employee_id === MY_EMP_ID;
  openModal(`<form onsubmit="savePerson(event, ${isEdit ? id : 'null'})" class="space-y-3 text-xs">

    <div class="text-[10px] text-gray-400 uppercase tracking-wide font-semibold">Person</div>
    <label class="block">Full name <span class="text-red-500">*</span>
      <input name="full_name" required value="${esc(p.full_name || '')}" class="border rounded px-2 py-1.5 w-full mt-1"></label>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Department<input name="department" value="${esc(p.department || '')}" class="border rounded px-2 py-1.5 w-full mt-1"></label>
      <label class="block">Sub-department<input name="sub_department" value="${esc(p.sub_department || '')}" class="border rounded px-2 py-1.5 w-full mt-1"></label>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Designation<input name="designation" value="${esc(p.designation || '')}" class="border rounded px-2 py-1.5 w-full mt-1"></label>
      <label class="block">Reports to<select name="manager_id" class="border rounded px-2 py-1.5 w-full mt-1">${managerOptions(p.manager_id, p.employee_id)}</select></label>
    </div>

    <div class="border rounded-lg bg-indigo-50/50 border-indigo-100 p-2.5 space-y-2">
      <div class="text-[10px] text-indigo-700 font-semibold uppercase tracking-wide">
        <i class="fa-solid fa-shield-halved mr-1"></i>Contact &amp; password reset</div>
      <div class="grid grid-cols-2 gap-2">
        <label class="block">Email <span class="text-red-500">*</span>
          <input name="email" type="email" value="${esc(p.email || '')}" placeholder="name@payg.in" class="border rounded px-2 py-1.5 w-full mt-1"></label>
        <label class="block">Mobile <span class="text-red-500">*</span>
          <input name="phone" value="${esc(p.phone || '')}" placeholder="10-digit mobile" inputmode="numeric" class="border rounded px-2 py-1.5 w-full mt-1"></label>
      </div>
      <p class="text-[10px] text-gray-500">Required for anyone with a login — the reset OTP is sent to these.</p>
    </div>

    <div class="text-[10px] text-gray-400 uppercase tracking-wide font-semibold pt-1 border-t">Login <span class="normal-case font-normal">(leave the username blank if this person doesn't sign in)</span></div>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Username<input name="username" value="${esc(p.username || '')}" class="border rounded px-2 py-1.5 w-full mt-1"></label>
      <label class="block">Role<select name="role" class="border rounded px-2 py-1.5 w-full mt-1">
        <option value="">— no login —</option>${roleOptions(p.role || '')}</select></label>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <label class="block">${isEdit ? 'New password <span class="text-gray-400">(blank = unchanged)</span>' : 'Password'}
        <input name="password" type="text" placeholder="min 6 chars" class="border rounded px-2 py-1.5 w-full mt-1"></label>
      <label class="block">Status<select name="is_active" ${isSelf ? 'disabled title="You can\'t deactivate your own account"' : ''} class="border rounded px-2 py-1.5 w-full mt-1">
        <option value="1" ${p.is_active != 0 ? 'selected' : ''}>Active</option>
        <option value="0" ${p.is_active == 0 ? 'selected' : ''}>Inactive</option></select></label>
    </div>
    ${!CAN_GRANT_PRIVILEGED ? '<p class="text-[10px] text-gray-500"><i class="fa-solid fa-lock"></i> Only an Admin can grant the Admin or Head roles.</p>' : ''}

    <div class="flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${isEdit ? 'Save' : 'Add person'}</button>
    </div>
  </form>`, isEdit ? 'Edit Person' : 'Add Person');
}

async function savePerson(e, id) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  if (!fd.password) delete fd.password;              // blank = leave the password alone
  const r = id ? await apiSend('users.php?id=' + id, fd, 'PATCH')
               : await apiSend('users.php', fd);
  if (r.success) { toast(r.message || 'Saved'); closeModal(); load(); }
  else toast(r.error || 'Failed', false);
}

async function resetPw(id, name) {
  const p = prompt('New password for ' + name + ' (min 6 chars):', '');
  if (!p) return;
  const r = await apiSend('users.php?id=' + id, { password: p }, 'PATCH');
  toast(r.success ? (r.message || 'Password reset') : (r.error || 'Failed'), r.success);
}

async function deactivate(id, name) {
  if (!confirm('Deactivate ' + name + "'s login? They will not be able to sign in. Their records and history are kept.")) return;
  const r = await apiSend('users.php?id=' + id, {}, 'DELETE');
  if (r.success) { toast(r.message || 'Deactivated'); load(); } else toast(r.error || 'Failed', false);
}

async function loginAs(id, name) {
  if (!confirm('Log in as ' + name + '?\nYou will see their screens exactly as they do. Use the amber "Return to admin" bar at the top to switch back.')) return;
  const res = await apiSend('auth.php?action=impersonate', { user_id: id });
  if (res.success) { toast('Now viewing as ' + name); setTimeout(() => { window.location.href = res.data.redirect; }, 350); }
  else toast(res.error || 'Failed', false);
}

load();
</script>
</html>
