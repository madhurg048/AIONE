<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_settings', 'Admin & Settings');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Admin & Settings',
    'Obligations and due dates, the form template builder, the organisation calendar, and who has access.'); ?>

  <div class="flex items-center gap-1 flex-wrap">
    <span class="ctab on" data-t="obligations" onclick="tab('obligations')">Obligations &amp; due dates</span>
    <span class="ctab" data-t="templates" onclick="tab('templates')">Form template builder</span>
    <span class="ctab" data-t="org" onclick="tab('org')">Organisation &amp; calendar</span>
    <span class="ctab" data-t="users" onclick="tab('users')">Users &amp; access</span>
    <span class="ctab" data-t="data" onclick="tab('data')">Data &amp; backup</span>
  </div>

  <!-- obligations -->
  <section data-p="obligations">
    <div id="strip" class="flex gap-2 flex-wrap mb-2"></div>
    <div class="card p-0">
      <div class="px-3 py-2 border-b flex items-center gap-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Obligations</div>
        <div class="text-[10px] text-gray-400">the scheduler expands these into dated tasks</div>
        <div class="flex-1"></div>
        <button onclick="openObl(null)" class="btn-p"><i class="fa-solid fa-plus"></i> New obligation</button>
      </div>
      <div class="overflow-x-auto" style="max-height:58vh"><table class="dense w-full">
        <thead><tr><th>Ref</th><th>Code</th><th>Obligation</th><th>Type</th><th>Authority</th>
          <th>Cadence</th><th>Due day</th><th>Owner</th><th>Form</th><th class="text-right">Tasks</th><th></th></tr></thead>
        <tbody id="oblRows"></tbody></table></div>
    </div>
  </section>

  <!-- templates -->
  <section data-p="templates" class="hidden">
    <div class="card p-0">
      <div class="px-3 py-2 border-b flex items-center gap-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Form templates</div>
        <div class="text-[10px] text-gray-400">changing the field set creates a new version</div>
        <div class="flex-1"></div>
        <button onclick="openTpl(null)" class="btn-p"><i class="fa-solid fa-plus"></i> New template</button>
      </div>
      <div class="overflow-x-auto" style="max-height:58vh"><table class="dense w-full">
        <thead><tr><th>Code</th><th>Title</th><th>Cadence</th><th>Ver</th><th>Checker</th><th>Approver</th>
          <th>Fields</th><th class="text-right">Submissions</th><th>Status</th><th></th></tr></thead>
        <tbody id="tplRows"></tbody></table></div>
    </div>
  </section>

  <!-- organisation & calendar -->
  <section data-p="org" class="hidden">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
      <div class="card p-3">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Organisation &amp; calendar</div>
        <form id="orgForm" onsubmit="saveSettings(event)" class="grid grid-cols-2 gap-2 text-xs"></form>
      </div>
      <div class="card p-3">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">How the calendar is built</div>
        <ul class="text-[11px] text-gray-600 space-y-1 list-disc pl-4">
          <li>The scheduler walks every active obligation and creates one task per period inside the window below.</li>
          <li>A period's due date always falls <b>after</b> the period it covers — you file September's return in October.</li>
          <li>Daily obligations skip Sundays; the due date is the period plus the obligation's due-day offset.</li>
          <li>Re-running the scheduler never duplicates: one task per obligation and period.</li>
          <li>Anything still open past its due date becomes <b>overdue</b> automatically — nobody sets that by hand.</li>
        </ul>
        <div class="text-[11px] text-gray-500 mt-2">Reminders and the digest use the CRM's existing alert settings
          (<a href="sales-settings.php" class="text-indigo-600 underline">Settings → Email</a>), so finance mail goes out
          through the same server as everything else.</div>
      </div>
    </div>
  </section>

  <!-- users -->
  <section data-p="users" class="hidden">
    <div class="card p-0">
      <div class="px-3 py-2 border-b flex items-center gap-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Who can open the cockpit</div>
        <div class="flex-1"></div>
        <a href="sales-users.php" class="text-[11px] border rounded px-2 py-1 hover:bg-gray-50">
          <i class="fa-solid fa-users-gear"></i> Manage users</a>
        <a href="sales-admin.php" class="text-[11px] border rounded px-2 py-1 hover:bg-gray-50">
          <i class="fa-solid fa-shield-halved"></i> Role access matrix</a>
      </div>
      <div class="overflow-x-auto"><table class="dense w-full">
        <thead><tr><th>Name</th><th>Login</th><th>Email</th><th>Role</th><th>Designation</th>
          <th>Department</th><th>Status</th><th>Last login</th></tr></thead>
        <tbody id="userRows"></tbody></table></div>
      <div class="px-3 py-2 text-[10px] text-gray-500 border-t">
        Accounts, passwords and one-time credentials are created in the CRM's User Management module — the cockpit does
        not keep a second user list. Access here follows the <b>cfo_*</b> modules in the role matrix, plus any employee
        group that carries them.</div>
    </div>
  </section>

  <!-- data & backup -->
  <section data-p="data" class="hidden">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Data &amp; backup</div>
      <div class="text-[11px] text-gray-600 mb-2">Every register exports as CSV; the JSON snapshot below carries the
        whole cockpit — obligations, templates, risks, indicators and settings — for an offline copy.</div>
      <div class="flex gap-2 flex-wrap">
        <button onclick="snapshot()" class="btn-p"><i class="fa-solid fa-download"></i> Download JSON snapshot</button>
        <a href="sales-cfo-reports.php" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">Registers &amp; audit pack</a>
      </div>
    </div>
  </section>
</main>

<?php cfo_scripts(); ?>
<script>
let DATA = null, CAN = false;

function tab(t){
  document.querySelectorAll('[data-t]').forEach(e => e.classList.toggle('on', e.dataset.t === t));
  document.querySelectorAll('[data-p]').forEach(e => e.classList.toggle('hidden', e.dataset.p !== t));
}

async function load(){
  const m = await cfoMeta();
  CAN = m.can_sign;
  const { data } = await apiGet(CFO_API, { area:'settings' });
  DATA = data;

  const obl = data.obligations, act = obl.filter(o => +o.is_active === 1);
  cfoStrip('strip', [
    ['📜','Obligations', cfoNum(obl.length), act.length + ' active'],
    ['🏛','Statutory / RBI / tax', cfoNum(obl.filter(o => ['statutory','rbi','tax'].includes(o.obligation_type)).length), 'externally imposed'],
    ['🔁','Internal reviews', cfoNum(obl.filter(o => o.obligation_type === 'internal_review').length), 'own controls'],
    ['📄','With a form', cfoNum(obl.filter(o => o.form_template_id).length), 'maker-checker filings'],
  ]);

  document.getElementById('oblRows').innerHTML = obl.map(o => `
    <tr class="hover:bg-gray-50 ${+o.is_active ? '' : 'opacity-50'}">
      <td>${cfoCode(o.obl_ref)}</td>
      <td class="text-[10px] font-mono">${esc(o.code)}</td>
      <td class="text-[11px] font-medium">${esc(o.title)}
        ${o.reference ? `<div class="text-[9px] text-gray-400">${esc(o.reference)}</div>` : ''}</td>
      <td>${cfoType(o.obligation_type)}</td>
      <td class="text-[10px]">${esc(o.authority||'—')}</td>
      <td class="text-[10px]">${esc(String(o.cadence).replace(/_/g,' '))}</td>
      <td class="text-[11px]">${o.due_day}</td>
      <td class="text-[10px]">${esc(o.owner_name || o.owner_role || '—')}</td>
      <td class="text-[10px]">${o.form_code ? cfoCode(o.form_code,'form') : '<span class="text-gray-300">—</span>'}</td>
      <td class="text-right text-[11px]">${o.task_count}</td>
      <td class="rowact">${CAN ? `<button onclick="openObl(${o.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Edit</button>` : ''}</td>
    </tr>`).join('');

  document.getElementById('tplRows').innerHTML = data.templates.map(t => {
    const spec = JSON.parse(t.fields_json || '{}');
    const n = (spec.fields || []).length + (spec.table ? 1 : 0);
    return `<tr class="hover:bg-gray-50 ${+t.is_active ? '' : 'opacity-50'}">
      <td>${cfoCode(t.code,'form')}</td>
      <td class="text-[11px] font-medium">${esc(t.title)}</td>
      <td class="text-[10px]">${esc(String(t.cadence).replace(/_/g,' '))}</td>
      <td class="text-[11px]">v${t.version}</td>
      <td class="text-[10px]">${esc(t.checker_role||'—')}</td>
      <td class="text-[10px]">${esc(t.approver_role||'—')}</td>
      <td class="text-[10px] text-gray-500">${n} field${n===1?'':'s'}${spec.table ? ' + register' : ''}</td>
      <td class="text-right text-[11px]">${t.submissions}</td>
      <td>${+t.is_active ? badge('active','green') : badge('inactive','gray')}</td>
      <td class="rowact">${CAN ? `<button onclick="openTpl(${t.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Edit</button>` : ''}</td>
    </tr>`;
  }).join('');

  const s = data.settings;
  const f = (k, label, hint, type) => `<label class="block ${type==='wide'?'col-span-2':''}">
      <span class="text-[11px] text-gray-600">${esc(label)}</span>
      <input name="${k}" value="${esc(s[k]||'')}" class="ctl w-full mt-0.5" ${CAN?'':'disabled'}>
      ${hint ? `<span class="text-[10px] text-gray-400">${esc(hint)}</span>` : ''}</label>`;
  document.getElementById('orgForm').innerHTML =
    f('org_name','Organisation','as it appears on the audit pack') +
    f('timezone','Timezone','timestamps are recorded in this zone') +
    f('fy_start_month','Financial year starts (month no.)','4 = April, the Indian FY') +
    f('work_days','Working days','comma separated, e.g. Mon,Tue,Wed,Thu,Fri,Sat') +
    f('holidays','Holidays','comma separated YYYY-MM-DD','wide') +
    f('scheduler_back_days','Scheduler window — days back','how far back the calendar is expanded') +
    f('scheduler_fwd_days','Scheduler window — days forward','how far ahead tasks are created') +
    f('reminder_days_before','Remind this many days before due','used by the email digest') +
    f('digest_recipients','Digest recipients','comma separated addresses','wide') +
    (CAN ? `<div class="col-span-2 flex justify-end"><button class="btn-p">Save settings</button></div>`
         : `<div class="col-span-2 text-[11px] text-gray-500">Only the CFO can change these.</div>`);

  document.getElementById('userRows').innerHTML = data.users.map(u => `
    <tr><td class="text-[11px] font-medium">${esc(u.full_name||'—')}</td>
      <td class="text-[11px] font-mono">${esc(u.username)}</td>
      <td class="text-[10px]">${esc(u.email||'—')}</td>
      <td>${badge(u.role, u.role === 'admin' ? 'purple' : u.role === 'cfo' ? 'blue' : 'gray')}</td>
      <td class="text-[10px]">${esc(u.designation||'—')}</td>
      <td class="text-[10px]">${esc(u.department||'—')}</td>
      <td>${+u.is_active ? badge('active','green') : badge('disabled','gray')}</td>
      <td class="text-[10px] text-gray-500">${u.last_login_at ? esc(cfoDT(u.last_login_at)) : 'never'}</td></tr>`).join('');
}

/* ---------- obligations ---------- */
function openObl(id){
  const o = id ? DATA.obligations.find(x => +x.id === +id) : null;
  const owners = CFO_META.owners.map(x => `<option value="${x.id}" ${o && +o.owner_employee_id===+x.id?'selected':''}>${esc(x.full_name)}</option>`).join('');
  const tpls = DATA.templates.map(t => `<option value="${t.id}" ${o && +o.form_template_id===+t.id?'selected':''}>${esc(t.code)} — ${esc(t.title)}</option>`).join('');
  openModal(`<form onsubmit="saveObl(event, ${id || 'null'})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block">Code<input name="code" required value="${esc(o?o.code:'')}" class="ctl w-full mt-0.5" ${o?'readonly':''}></label>
    <label class="block">Type<select name="obligation_type" class="ctl w-full mt-0.5">
      ${['statutory','internal_review','rbi','tax','board'].map(t => `<option value="${t}" ${o&&o.obligation_type===t?'selected':''}>${t.replace('_',' ')}</option>`).join('')}</select></label>
    <label class="block col-span-2">Obligation<input name="title" required value="${esc(o?o.title:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Authority<input name="authority" value="${esc(o?o.authority:'')}" class="ctl w-full mt-0.5" placeholder="RBI, GSTN, EPFO, Internal…"></label>
    <label class="block">Legal / policy reference<input name="reference" value="${esc(o?o.reference:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Cadence<select name="cadence" class="ctl w-full mt-0.5">
      ${CFO_META.cadences.map(c => `<option value="${c}" ${o&&o.cadence===c?'selected':''}>${c.replace(/_/g,' ')}</option>`).join('')}</select></label>
    <label class="block">Due day<input name="due_day" type="number" min="1" max="31" value="${o?o.due_day:1}" class="ctl w-full mt-0.5">
      <span class="text-[10px] text-gray-400">day of the following month (or day-offset for daily/weekly)</span></label>
    <label class="block">Owner role<input name="owner_role" value="${esc(o?o.owner_role:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Owner<select name="owner_employee_id" class="ctl w-full mt-0.5"><option value="">—</option>${owners}</select></label>
    <label class="block col-span-2">Form to file<select name="form_template_id" class="ctl w-full mt-0.5"><option value="">— none —</option>${tpls}</select></label>
    <label class="block">Active<select name="is_active" class="ctl w-full mt-0.5">
      <option value="1" ${!o||+o.is_active?'selected':''}>Yes</option><option value="0" ${o&&!+o.is_active?'selected':''}>No</option></select></label>
    <div class="col-span-2 text-[10px] text-gray-500">Changing the cadence or due day only affects tasks created from
      now on — history keeps the dates it was actually judged against.</div>
    <div class="col-span-2 flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${o ? 'Save' : 'Create'}</button></div>
  </form>`, o ? 'Edit ' + o.code : 'New obligation');
}
async function saveObl(e, id){
  e.preventDefault(); const f = e.target, body = { id: id || undefined };
  [...f.elements].forEach(el => { if (el.name) body[el.name] = el.value; });
  const res = await apiSend(`${CFO_API}?action=obligation_save`, body);
  if (res.success){ toast('Saved — run the scheduler to apply it'); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}

/* ---------- the template builder ---------- */
let TPL_FIELDS = [];
function openTpl(id){
  const t = id ? DATA.templates.find(x => +x.id === +id) : null;
  const spec = t ? (JSON.parse(t.fields_json || '{}')) : {};
  TPL_FIELDS = (spec.fields || []).map(f => ({ ...f }));
  const roles = ['CFO','Finance Manager','Tax Lead','Settlement Ops Lead','Compliance Lead'];
  openModal(`<form onsubmit="saveTpl(event, ${id || 'null'})" class="text-xs space-y-2">
    <div class="grid grid-cols-2 gap-2">
      <label class="block">Code<input name="code" required value="${esc(t?t.code:'')}" class="ctl w-full mt-0.5"
        ${t?'readonly':''} placeholder="MC-08-SOMETHING"></label>
      <label class="block">Cadence<select name="cadence" class="ctl w-full mt-0.5">
        ${CFO_META.cadences.map(c => `<option value="${c}" ${t&&t.cadence===c?'selected':''}>${c.replace(/_/g,' ')}</option>`).join('')}</select></label>
      <label class="block col-span-2">Title<input name="title" required value="${esc(t?t.title:'')}" class="ctl w-full mt-0.5"></label>
      <label class="block">Checker role<select name="checker_role" class="ctl w-full mt-0.5"><option value="">—</option>
        ${roles.map(r => `<option ${t&&t.checker_role===r?'selected':''}>${r}</option>`).join('')}</select></label>
      <label class="block">Approver role<select name="approver_role" class="ctl w-full mt-0.5"><option value="">—</option>
        ${roles.map(r => `<option ${t&&t.approver_role===r?'selected':''}>${r}</option>`).join('')}</select></label>
    </div>
    <div class="border-t pt-2">
      <div class="flex items-center gap-2"><div class="text-[11px] font-semibold text-gray-700">Fields</div>
        <div class="flex-1"></div>
        <button type="button" onclick="addField()" class="text-[10px] border rounded px-2 py-0.5">+ Add field</button></div>
      <div id="fieldList" class="mt-1 space-y-1"></div>
      <label class="block mt-2"><input type="checkbox" name="has_table" ${spec.table?'checked':''}>
        <span class="text-[11px]">Include a free-form register (line per row)</span></label>
      <input name="table_cols" class="ctl w-full mt-0.5" placeholder="Register columns, comma separated"
        value="${esc(spec.table ? (spec.table.cols||[]).join(', ') : '')}">
    </div>
    <div class="text-[10px] text-gray-500">Saving a changed field set publishes a <b>new version</b>; filings already
      approved keep the version they were signed against.</div>
    <div class="flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${t ? 'Save (new version)' : 'Create template'}</button></div>
  </form>`, t ? 'Edit ' + t.code : 'New form template');
  renderFields();
}
function addField(){ TPL_FIELDS.push({ k:'', l:'', t:'text' }); renderFields(); }
function delField(i){ TPL_FIELDS.splice(i,1); renderFields(); }
function renderFields(){
  const types = ['text','number','date','bool','select','textarea','calc'];
  document.getElementById('fieldList').innerHTML = TPL_FIELDS.map((f,i) => `
    <div class="flex gap-1 items-center">
      <input class="ctl" style="width:110px" placeholder="key" value="${esc(f.k)}" oninput="TPL_FIELDS[${i}].k=this.value">
      <input class="ctl flex-1" placeholder="Label" value="${esc(f.l)}" oninput="TPL_FIELDS[${i}].l=this.value">
      <select class="ctl" style="width:96px" onchange="TPL_FIELDS[${i}].t=this.value">
        ${types.map(t => `<option ${f.t===t?'selected':''}>${t}</option>`).join('')}</select>
      <input class="ctl" style="width:130px" placeholder="formula / options" value="${esc(f.f||(f.o||[]).join(','))}"
        oninput="TPL_FIELDS[${i}][TPL_FIELDS[${i}].t==='select'?'o':'f']=TPL_FIELDS[${i}].t==='select'?this.value.split(','):this.value">
      <button type="button" onclick="delField(${i})" class="text-red-500 px-1"><i class="fa-solid fa-xmark"></i></button>
    </div>`).join('') || '<div class="text-[11px] text-gray-400">No fields — the form will just carry evidence.</div>';
}
async function saveTpl(e, id){
  e.preventDefault(); const f = e.target;
  const fields = { fields: TPL_FIELDS.filter(x => x.k && x.l) };
  if (f.has_table.checked && f.table_cols.value.trim())
    fields.table = { cols: f.table_cols.value.split(',').map(s => s.trim()).filter(Boolean) };
  const res = await apiSend(`${CFO_API}?action=template_save`, {
    id: id || undefined, code: f.code.value, title: f.title.value, cadence: f.cadence.value,
    checker_role: f.checker_role.value, approver_role: f.approver_role.value, fields,
  });
  if (res.success){ toast(id ? 'Published v' + res.data.version : 'Template created'); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}

/* ---------- settings + snapshot ---------- */
async function saveSettings(e){
  e.preventDefault(); const f = e.target, body = {};
  [...f.elements].forEach(el => { if (el.name) body[el.name] = el.value; });
  const res = await apiSend(`${CFO_API}?action=settings_save`, body);
  if (res.success){ toast(res.data.saved + ' setting(s) saved'); load(); } else toast(res.error || 'Failed', false);
}

async function snapshot(){
  const parts = {};
  for (const a of ['settings','risk','kri','capa','reviews','forms','calendar','evidence']) {
    parts[a] = (await apiGet(CFO_API, { area: a })).data;
  }
  const blob = new Blob([JSON.stringify({ taken_at: new Date().toISOString(), cockpit: parts }, null, 2)],
                        { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'cfo-cockpit-snapshot-' + new Date().toISOString().slice(0,10) + '.json';
  a.click();
  toast('Snapshot downloaded');
}

load();
</script>
</html>
