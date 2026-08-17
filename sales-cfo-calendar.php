<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_calendar', 'Calendar & Tasks');
$openId = (int)($_GET['open'] ?? 0);
$preStatus = preg_replace('/[^a-z_]/', '', (string)($_GET['status'] ?? ''));
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Calendar & Tasks',
    'Every statutory, RBI, tax and internal obligation, expanded into dated tasks with an owner.',
    '<button onclick="openAssign()" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-users-between-lines"></i> Assign &amp; split tasks</button>
     <button onclick="cfoRunScheduler()" class="btn-p"><i class="fa-solid fa-rotate"></i> Run scheduler</button>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="flex items-center gap-1 flex-wrap">
    <span class="ctab on" id="tabAll" onclick="setScope('all')">All tasks</span>
    <span class="ctab" id="tabMine" onclick="setScope('mine')">My tasks</span>
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400" id="rowCount"></span>
  </div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option value="overdue">Overdue</option>
      <option value="open">Open</option><option value="in_progress">In progress</option>
      <option value="done">Done</option><option value="waived">Waived</option>
    </select>
    <select id="fType" class="ctl" onchange="load()"><option value="">All types</option></select>
    <select id="fOwner" class="ctl" onchange="load()"><option value="">All owners</option></select>
    <input id="fPeriod" class="ctl" placeholder="Period, e.g. 2026-08" oninput="deb()" style="width:150px">
    <input id="q" class="ctl" placeholder="Search task, obligation or authority…" oninput="deb()" style="min-width:230px">
    <div class="flex-1"></div>
    <button onclick="saveView()" class="text-[11px] border rounded px-2 py-1 hover:bg-gray-50">
      <i class="fa-solid fa-bookmark"></i> Save view</button>
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto" style="max-height:66vh">
      <table class="dense w-full">
        <thead><tr>
          <th style="width:26px"><input type="checkbox" onclick="allRows(this)"></th>
          <th>Ref</th><th>Task</th><th>Type</th><th>Obligation</th><th>Owner</th>
          <th>Period</th><th>Due</th><th>Status</th><th>Form</th><th></th>
        </tr></thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('calendar',
  'Which obligations are slipping, and who is carrying too much?');

let SCOPE = 'all', ROWS = [], SEL = new Set(), tmr = null;
const deb = () => { clearTimeout(tmr); tmr = setTimeout(load, 350); };
const OPEN_ID = <?= $openId ?>;
if ('<?= $preStatus ?>') document.getElementById('fStatus').value = '<?= $preStatus ?>';

function setScope(s){
  SCOPE = s;
  document.getElementById('tabAll').classList.toggle('on', s === 'all');
  document.getElementById('tabMine').classList.toggle('on', s === 'mine');
  load();
}

async function boot(){
  const m = await cfoMeta();
  document.getElementById('fOwner').innerHTML = '<option value="">All owners</option>' +
    m.owners.map(o => `<option value="${o.id}">${esc(o.full_name)}${o.designation ? ' · ' + esc(o.designation) : ''}</option>`).join('');
  const saved = localStorage.getItem('cfoCalView');
  if (saved && !'<?= $preStatus ?>') {
    try { const v = JSON.parse(saved);
      ['fStatus','fType','fOwner','fPeriod','q'].forEach(k => { if (v[k] != null) document.getElementById(k).value = v[k]; });
      if (v.scope) SCOPE = v.scope;
    } catch(e){}
  }
  await load();
  if (OPEN_ID) openTask(OPEN_ID);
}

function saveView(){
  const v = { scope: SCOPE };
  ['fStatus','fType','fOwner','fPeriod','q'].forEach(k => v[k] = document.getElementById(k).value);
  localStorage.setItem('cfoCalView', JSON.stringify(v));
  toast('View saved for this browser');
}

async function load(){
  const p = { area:'calendar', scope: SCOPE,
    status: document.getElementById('fStatus').value, type: document.getElementById('fType').value,
    owner: document.getElementById('fOwner').value, period: document.getElementById('fPeriod').value,
    q: document.getElementById('q').value };
  const { data } = await apiGet(CFO_API, p);
  ROWS = data.rows || [];
  const t = data.totals || {};

  if (document.getElementById('fType').options.length <= 1) {
    document.getElementById('fType').innerHTML = '<option value="">All types</option>' +
      (data.types||[]).map(x => `<option value="${esc(x)}">${esc(x.replace('_',' '))}</option>`).join('');
  }

  cfoStrip('strip', [
    ['📋','Total tasks', cfoNum(t.total), 'in the rolling calendar'],
    ['⏰','Overdue', cfoNum(t.overdue), cfoPct(t.overdue, t.total) + ' of all tasks', +t.overdue ? 'bad' : 'good'],
    ['📅','Due this week', cfoNum(t.due_week), 'next 7 days', +t.due_week ? 'warn' : 'good'],
    ['✅','Completed', cfoNum(t.done), cfoPct(t.done, t.total) + ' closed', 'good'],
  ]);
  document.getElementById('rowCount').textContent = ROWS.length + ' shown' +
    (+t.overdue ? ' · Overdue (' + t.overdue + ')' : '');

  document.getElementById('rows').innerHTML = ROWS.map(r => `
    <tr class="hover:bg-gray-50">
      <td><input type="checkbox" value="${r.id}" onclick="pick(this)" ${SEL.has(String(r.id))?'checked':''}></td>
      <td>${cfoCode(r.task_ref,'task')}</td>
      <td class="text-[11px] font-medium cursor-pointer" onclick="openTask(${r.id})">
        ${esc(r.title)}
        ${r.form_code ? `<span class="text-[9px] text-cyan-700">· has form ${esc(r.form_code)}</span>` : ''}
      </td>
      <td>${cfoType(r.task_type)}</td>
      <td class="text-[11px]">${cfoCode(r.obligation_code)}<div class="text-[9px] text-gray-400">${esc(r.authority||'')}</div></td>
      <td class="text-[11px]">${cfoOwner(r)}</td>
      <td class="text-[11px]">${esc(r.period)}</td>
      <td class="text-[11px]">${cfoDueCell(r.due_on, r.status)}</td>
      <td>${cfoStatus(r.status)}</td>
      <td>${r.form_code
            ? (r.submission_id
                ? `<a href="sales-cfo-forms.php?open=${r.submission_id}" class="text-[10px] text-indigo-600 underline">${esc(r.submission_status)}</a>`
                : `<span class="text-[10px] text-amber-600">not started</span>`)
            : '<span class="text-gray-300 text-[10px]">—</span>'}</td>
      <td class="rowact">${CFO_META.can_write ? `
        <select class="ctl text-[10px]" onchange="setStatus(${r.id}, this.value); this.selectedIndex=0" style="padding:1px 4px">
          <option value="">set…</option><option value="in_progress">In progress</option>
          <option value="done">Done</option><option value="open">Re-open</option>
          ${CFO_META.can_sign ? '<option value="waived">Waive</option>' : ''}
        </select>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="11" class="text-center text-gray-400 py-6">Nothing matches — or the scheduler has not run yet.</td></tr>';
}

function pick(cb){ cb.checked ? SEL.add(cb.value) : SEL.delete(cb.value); }
function allRows(m){ document.querySelectorAll('#rows input[type=checkbox]').forEach(cb => { cb.checked = m.checked; pick(cb); }); }

async function setStatus(id, status){
  if (!status) return;
  const res = await apiSend(`${CFO_API}?action=task_status`, { id, status });
  if (res.success){ toast('Task updated'); load(); } else toast(res.error || 'Failed', false);
}

/* ---------- drill-down ---------- */
async function openTask(id){
  const { data } = await apiGet(CFO_API, { task: id });
  const t = data.task, s = data.submission;
  openModal(`
    <div class="flex items-center gap-2 mb-2">${cfoCode(t.task_ref,'task')} ${cfoType(t.task_type)} ${cfoStatus(t.status)}</div>
    <div class="text-sm font-semibold mb-2">${esc(t.title)}</div>
    ${cfoRow('Obligation', cfoCode(t.obligation_code) + ' ' + esc(t.obligation_title))}
    ${cfoRow('Authority', esc(t.authority || '') + (t.reference ? ` <span class="text-gray-400">(${esc(t.reference)})</span>` : ''))}
    ${cfoRow('Cadence', esc(t.cadence))}
    ${cfoRow('Period', esc(t.period))}
    ${cfoRow('Due', cfoDueCell(t.due_on, t.status))}
    ${cfoRow('Owner', cfoOwner(t))}
    ${cfoRow('Completed', t.completed_at ? esc(cfoDT(t.completed_at)) + ' by ' + esc(t.completed_by_name || '—') : null)}
    ${t.form_code ? cfoRow('Form', cfoCode(t.form_code,'form') + ' ' + esc(t.form_title) +
        (s ? ` — ${cfoStatus(s.status)} <a class="text-indigo-600 underline" href="sales-cfo-forms.php?open=${s.id}">open</a>`
           : ` — <span class="text-amber-600">not started</span>
               <button onclick="startForm(${t.form_template_id}, '${esc(t.period)}', '${t.due_on}')"
                 class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded ml-1">Start it</button>`)) : ''}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">Evidence</div>
    ${cfoEvidenceList(data.evidence, 'task', t.id)}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">History</div>
    ${cfoHistory(data.history)}`, 'Task ' + (t.task_ref || t.id));
}

async function startForm(tplId, period, due){
  const res = await apiSend(`${CFO_API}?action=submission_create`, { template_id: tplId, period, due_on: due });
  if (res.success) location.href = 'sales-cfo-forms.php?open=' + res.data.id;
  else toast(res.error || 'Failed', false);
}

/* ---------- assign & split ---------- */
function openAssign(){
  const ids = [...SEL];
  const opts = CFO_META.owners.map(o => `<option value="${o.id}">${esc(o.full_name)}${o.designation ? ' · ' + esc(o.designation) : ''}</option>`).join('');
  openModal(`
    <div class="text-[11px] text-gray-600 mb-2">
      ${ids.length ? ids.length + ' task(s) selected.' : 'Tick tasks in the table first to assign them in bulk.'}
    </div>
    <form onsubmit="doAssign(event)" class="space-y-2 text-xs border-b pb-3">
      <div class="font-semibold text-[11px] text-gray-700">Assign the selected tasks</div>
      <label class="block">Owner<select name="owner" class="ctl w-full mt-0.5">${opts}</select></label>
      <div class="flex justify-end"><button class="btn-p" ${ids.length ? '' : 'disabled'}>Assign ${ids.length || ''}</button></div>
    </form>
    <form onsubmit="doSplit(event)" class="space-y-2 text-xs pt-3">
      <div class="font-semibold text-[11px] text-gray-700">Split one task across several people</div>
      <label class="block">Task<select name="task" class="ctl w-full mt-0.5">
        ${ROWS.slice(0,80).map(r => `<option value="${r.id}">${esc(r.title)}</option>`).join('')}</select></label>
      <label class="block">Owners (pick two or more)
        <select name="owners" multiple size="6" class="ctl w-full mt-0.5" style="height:auto">${opts}</select></label>
      <div class="text-[10px] text-gray-500">Each part keeps the same obligation, period and due date, so the calendar
        still reconciles back to one obligation.</div>
      <div class="flex justify-end"><button class="btn-p">Split</button></div>
    </form>`, 'Assign & split tasks');
}
async function doAssign(e){
  e.preventDefault();
  const res = await apiSend(`${CFO_API}?action=task_assign`,
    { ids: [...SEL].map(Number), owner_employee_id: +e.target.owner.value });
  if (res.success){ toast(res.data.assigned + ' assigned to ' + res.data.owner); SEL.clear(); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}
async function doSplit(e){
  e.preventDefault();
  const owners = [...e.target.owners.selectedOptions].map(o => +o.value);
  const res = await apiSend(`${CFO_API}?action=task_split`, { id: +e.target.task.value, owners });
  if (res.success){ toast('Split into ' + res.data.parts + ' parts'); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}

boot();
</script>
</html>
