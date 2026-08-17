<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_forms', 'Forms & Submissions');
$openId = (int)($_GET['open'] ?? 0);
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Forms & Submissions',
    'Maker prepares, checker re-performs, approver signs — and the filing is locked once approved.',
    '<button onclick="openTemplates()" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-table-columns"></i> Manage templates</button>
     <button onclick="openOnDemand()" class="btn-p"><i class="fa-solid fa-plus"></i> Raise a form</button>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <div class="card p-0">
      <div class="px-3 py-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        To prepare (assigned to me)</div>
      <div class="overflow-x-auto" style="max-height:230px"><table class="dense w-full">
        <thead><tr><th>Form</th><th>Period</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody id="mineRows"></tbody></table></div>
    </div>
    <div class="card p-0">
      <div class="px-3 py-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        Awaiting my check or approval</div>
      <div class="overflow-x-auto" style="max-height:230px"><table class="dense w-full">
        <thead><tr><th>Form</th><th>Period</th><th>Prepared by</th><th>Status</th><th></th></tr></thead>
        <tbody id="waitRows"></tbody></table></div>
    </div>
  </div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mr-1">All submissions</span>
    <select id="fStatus" class="ctl" onchange="render()">
      <option value="">All status</option><option value="not_started">Not started</option>
      <option value="draft">Draft</option><option value="submitted">Submitted</option>
      <option value="reviewed">Checked</option><option value="approved">Approved</option>
      <option value="rejected">Returned</option><option value="overdue">Overdue</option>
    </select>
    <select id="fForm" class="ctl" onchange="render()"><option value="">All forms</option></select>
    <input id="q" class="ctl" placeholder="Search form, period or preparer…" oninput="render()" style="min-width:220px">
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400" id="rowCount"></span>
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto" style="max-height:56vh"><table class="dense w-full">
      <thead><tr><th>Form</th><th>Title</th><th>Cadence</th><th>Period</th><th>Due</th>
        <th>Prepared</th><th>Checked</th><th>Approved</th><th>Status</th><th></th></tr></thead>
      <tbody id="rows"></tbody></table></div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('forms',
  'Which forms are chronically late or never started?');

let DATA = null;
const OPEN_ID = <?= $openId ?>;

async function load(){
  await cfoMeta();
  const res = await apiGet(CFO_API, { area:'forms' });
  DATA = res.data;
  const t = DATA.totals;
  cfoStrip('strip', [
    ['📄','Expected filings', cfoNum(t.total), 'from the compliance calendar'],
    ['✅','Approved', cfoNum(t.approved), cfoPct(t.approved, t.total) + ' complete', 'good'],
    ['⏳','In the workflow', cfoNum(t.pending), 'draft, submitted or checked', +t.pending ? 'warn' : 'good'],
    ['⏰','Overdue', cfoNum(t.overdue), 'past their due date', +t.overdue ? 'bad' : 'good'],
  ]);

  if (document.getElementById('fForm').options.length <= 1) {
    document.getElementById('fForm').innerHTML = '<option value="">All forms</option>' +
      DATA.templates.map(x => `<option value="${esc(x.code)}">${esc(x.code)}</option>`).join('');
  }

  document.getElementById('mineRows').innerHTML = DATA.to_prepare.slice(0,40).map(r => `
    <tr class="hover:bg-gray-50"><td>${cfoCode(r.form_code,'form')}</td>
      <td class="text-[11px]">${esc(r.period)}</td>
      <td class="text-[11px]">${cfoDueCell(r.due_on, r.status)}</td>
      <td>${cfoStatus(r.status)}</td>
      <td class="rowact">${r.submission_id
        ? `<button onclick="openSub(${r.submission_id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Open</button>`
        : `<button onclick="start(${r.template_id},'${esc(r.period)}','${r.due_on||''}')" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Prepare</button>`}
      </td></tr>`).join('') || '<tr><td colspan="5" class="text-center text-gray-400 py-4">Nothing waiting on you.</td></tr>';

  document.getElementById('waitRows').innerHTML = DATA.awaiting.slice(0,40).map(r => `
    <tr class="hover:bg-gray-50"><td>${cfoCode(r.form_code,'form')}</td>
      <td class="text-[11px]">${esc(r.period)}</td>
      <td class="text-[11px]">${esc(r.prepared_by_name || '—')}</td>
      <td>${cfoStatus(r.status)}</td>
      <td class="rowact"><button onclick="openSub(${r.submission_id})"
        class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded">Review</button></td></tr>`).join('')
    || '<tr><td colspan="5" class="text-center text-gray-400 py-4">Nothing awaiting your signature.</td></tr>';

  render();
  if (OPEN_ID) openSub(OPEN_ID);
}

function render(){
  const st = document.getElementById('fStatus').value, fm = document.getElementById('fForm').value;
  const q = document.getElementById('q').value.toLowerCase();
  const rows = DATA.rows.filter(r =>
    (!st || r.status === st) && (!fm || r.form_code === fm) &&
    (!q || (r.form_code + ' ' + r.form_title + ' ' + r.period + ' ' + (r.prepared_by_name||'')).toLowerCase().includes(q)));
  document.getElementById('rowCount').textContent = rows.length + ' of ' + DATA.rows.length;
  document.getElementById('rows').innerHTML = rows.slice(0, 400).map(r => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(r.form_code,'form')}<div class="text-[9px] text-gray-400">v${r.version}</div></td>
      <td class="text-[11px] font-medium">${esc(r.form_title)}
        ${r.obligation_code ? `<div class="text-[9px] text-gray-400">${esc(r.obligation_code)}</div>` : ''}</td>
      <td class="text-[10px] text-gray-500">${esc(String(r.cadence).replace(/_/g,' '))}</td>
      <td class="text-[11px]">${esc(r.period)}</td>
      <td class="text-[11px]">${cfoDueCell(r.due_on, r.status)}</td>
      <td class="text-[10px]">${esc(r.prepared_by_name || '—')}</td>
      <td class="text-[10px]">${esc(r.reviewed_by_name || '—')}</td>
      <td class="text-[10px]">${esc(r.approved_by_name || '—')}</td>
      <td>${cfoStatus(r.status)}</td>
      <td class="rowact">${r.submission_id
        ? `<button onclick="openSub(${r.submission_id})" class="text-[10px] bg-gray-100 px-2 py-0.5 rounded">Open</button>`
        : (CFO_META.can_write
            ? `<button onclick="start(${r.template_id},'${esc(r.period)}','${r.due_on||''}')" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Prepare</button>` : '')}
      </td></tr>`).join('') || '<tr><td colspan="10" class="text-center text-gray-400 py-6">No filings match.</td></tr>';
}

async function start(tplId, period, due){
  const res = await apiSend(`${CFO_API}?action=submission_create`, { template_id: tplId, period, due_on: due || null });
  if (res.success) openSub(res.data.id); else toast(res.error || 'Failed', false);
}

/* ---------- the form itself ---------- */
function fieldHtml(f, val){
  const v = val == null ? '' : val;
  if (f.t === 'textarea') return `<textarea name="${esc(f.k)}" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(v)}</textarea>`;
  if (f.t === 'bool')     return `<select name="${esc(f.k)}" class="ctl w-full mt-0.5">
      <option value="">—</option><option value="yes" ${v==='yes'?'selected':''}>Yes</option>
      <option value="no" ${v==='no'?'selected':''}>No</option></select>`;
  if (f.t === 'select')   return `<select name="${esc(f.k)}" class="ctl w-full mt-0.5"><option value="">—</option>
      ${(f.o||[]).map(o => `<option ${String(o)===String(v)?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
  if (f.t === 'calc')     return `<input name="${esc(f.k)}" value="${esc(v)}" readonly class="ctl w-full mt-0.5 bg-gray-50"
      data-formula="${esc(f.f||'')}" placeholder="computed">`;
  return `<input name="${esc(f.k)}" type="${f.t === 'number' ? 'number' : f.t === 'date' ? 'date' : 'text'}"
      step="any" value="${esc(v)}" class="ctl w-full mt-0.5">`;
}

async function openSub(id){
  const { data } = await apiGet(CFO_API, { submission: id });
  const s = data.submission, spec = s.fields || {}, val = s.data || {};
  // Editable only while it is the maker's: draft, returned, or overdue-but-unstarted.
  const editable = ['draft', 'rejected', 'overdue'].includes(s.status);
  const locked = !editable || !CFO_META.can_write;
  const lockNote = s.status === 'approved'
    ? 'Approved and locked. Changes need a Change Request (GEN-12).'
    : (['submitted','reviewed'].includes(s.status)
        ? 'With the ' + (s.status === 'submitted' ? 'checker' : 'approver') + '. The working paper is frozen until it is returned for correction.'
        : 'You do not have rights to prepare this filing.');
  const fields = (spec.fields || []).map(f => `<label class="block ${f.t==='textarea'?'col-span-2':''}">
      <span class="text-[11px] text-gray-600">${esc(f.l)}</span>${fieldHtml(f, val[f.k])}</label>`).join('');
  const table = spec.table ? `<div class="col-span-2 mt-1">
      <div class="text-[11px] text-gray-600 mb-1">${esc(spec.table.cols.join(' · '))}</div>
      <textarea name="__table" rows="4" class="ctl w-full" style="height:auto"
        placeholder="One line per row, values separated by |">${esc(val.__table || '')}</textarea>
      <div class="text-[10px] text-gray-400">Line-per-row working; the register columns above are the expected order.</div>
    </div>` : '';

  const flow = [['draft','Prepared'],['submitted','Submitted'],['reviewed','Checked'],['approved','Approved']];
  const at = flow.findIndex(f => f[0] === s.status);
  const steps = flow.map((f,i) => `<span class="text-[10px] px-2 py-0.5 rounded ${i <= at ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'}">
      ${f[1]}</span>`).join('<span class="text-gray-300">→</span>');

  const actions = [];
  if (!locked && ['draft','rejected','overdue'].includes(s.status))
    actions.push(`<button onclick="trans(${id},'submitted')" class="btn-p">Submit for check</button>`);
  if (s.status === 'submitted')
    actions.push(`<button onclick="trans(${id},'reviewed')" class="text-[11px] bg-purple-100 text-purple-800 px-3 py-1.5 rounded">Re-performed &amp; correct</button>`,
                 `<button onclick="trans(${id},'rejected')" class="text-[11px] bg-red-100 text-red-700 px-3 py-1.5 rounded">Return for correction</button>`);
  if (['submitted','reviewed'].includes(s.status) && CFO_META.can_sign)
    actions.push(`<button onclick="trans(${id},'approved')" class="text-[11px] bg-green-600 text-white px-3 py-1.5 rounded">Approve &amp; lock</button>`);

  openModal(`
    <div class="flex items-center gap-2 mb-1">${cfoCode(s.form_code,'form')} ${cfoStatus(s.status)}
      <span class="text-[10px] text-gray-400">v${s.version} · ${esc(String(s.cadence).replace(/_/g,' '))}</span></div>
    <div class="text-sm font-semibold">${esc(s.form_title)} — ${esc(s.period)}</div>
    <div class="flex items-center gap-1 my-2">${steps}</div>
    ${cfoRow('Due', cfoDueCell(s.due_on, s.status))}
    ${cfoRow('Prepared by', s.prepared_by_name ? esc(s.prepared_by_name) + ' <span class="text-gray-400">' + esc(cfoDT(s.prepared_at)) + '</span>' : null)}
    ${cfoRow('Checked by', s.reviewed_by_name ? esc(s.reviewed_by_name) + ' <span class="text-gray-400">' + esc(cfoDT(s.reviewed_at)) + '</span>' : '<span class="text-gray-400">pending — must not be the preparer</span>')}
    ${cfoRow('Approved by', s.approved_by_name ? esc(s.approved_by_name) + ' <span class="text-gray-400">' + esc(cfoDT(s.approved_at)) + '</span>' : '<span class="text-gray-400">pending — ' + esc(s.approver_role || 'CFO') + '</span>')}
    ${s.remarks ? cfoRow('Remarks', esc(s.remarks)) : ''}
    <form id="subForm" onsubmit="saveSub(event,${id})" class="grid grid-cols-2 gap-2 mt-3">
      ${fields || '<div class="col-span-2 text-[11px] text-gray-400">This form has no structured fields — attach the working paper as evidence.</div>'}
      ${table}
      ${locked ? `<div class="col-span-2 text-[11px] ${s.status === 'approved' ? 'text-green-700' : 'text-amber-700'}">${esc(lockNote)}</div>` : `
        <div class="col-span-2 flex items-center gap-2 pt-1">
          <label class="flex-1"><span class="text-[11px] text-gray-600">Remarks on this submission</span>
            <input name="__remarks" value="${esc(s.remarks||'')}" class="ctl w-full mt-0.5"></label>
        </div>`}
      <!-- the workflow buttons belong to whoever holds it now, so they render even when
           the working paper itself is frozen (submitted / checked) -->
      <div class="col-span-2 flex justify-end gap-2">
        ${locked ? '' : '<button type="submit" class="px-3 py-1.5 border rounded">Save working</button>'}
        ${actions.join('')}
      </div>
    </form>
    <div class="mt-3 text-[11px] font-semibold text-gray-600">Evidence</div>
    ${cfoEvidenceList(data.evidence, 'submission', id)}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">History</div>
    ${cfoHistory(data.history)}`, s.form_code + ' · ' + s.period);

  // keep calc fields honest: recompute on every keystroke from the formula
  const form = document.getElementById('subForm');
  form.addEventListener('input', () => {
    form.querySelectorAll('[data-formula]').forEach(el => {
      const f = el.dataset.formula; if (!f) return;
      try {
        const expr = f.replace(/[a-z_][a-z0-9_]*/gi, k => {
          const src = form.elements[k]; return src ? (parseFloat(src.value) || 0) : 0;
        });
        const out = Function('"use strict";return (' + expr + ')')();
        el.value = Number.isFinite(out) ? Math.round(out * 100) / 100 : '';
      } catch (e) { /* a half-typed formula is not an error */ }
    });
  });
}

async function saveSub(e, id){
  e.preventDefault();
  const f = e.target, data = {};
  // A template is free to define a field called "remarks" (MC-01 does), so the shell's
  // own submission-level remarks box is namespaced __remarks and excluded from the data.
  [...f.elements].forEach(el => { if (el.name && el.name !== '__remarks') data[el.name] = el.value; });
  const box = f.elements['__remarks'];
  const res = await apiSend(`${CFO_API}?action=submission_save`,
    { id, data, remarks: box ? box.value : null });
  if (res.success){ toast('Working saved'); load(); } else toast(res.error || 'Failed', false);
}

async function trans(id, to){
  if (to === 'rejected') {
    const why = prompt('Why is it being returned?'); if (why === null) return;
    const r = await apiSend(`${CFO_API}?action=submission_transition`, { id, to, remarks: why });
    if (r.success){ toast('Returned to the preparer'); closeModal(); load(); } else toast(r.error || 'Failed', false);
    return;
  }
  const res = await apiSend(`${CFO_API}?action=submission_transition`, { id, to });
  if (res.success){ toast('Moved to ' + to); closeModal(); load(); } else toast(res.error || 'Failed', false);
}

/* ---------- on-demand forms & the template builder ---------- */
function openOnDemand(){
  openModal(`<form onsubmit="raise(event)" class="space-y-2 text-xs">
    <div class="text-[11px] text-gray-600">On-demand forms have no calendar entry — raise one when the event happens.</div>
    <label class="block">Form<select name="template_id" class="ctl w-full mt-0.5">
      ${DATA.on_demand.map(t => `<option value="${t.id}">${esc(t.code)} — ${esc(t.title)}</option>`).join('')}</select></label>
    <label class="block">Reference / period<input name="period" class="ctl w-full mt-0.5"
      value="${new Date().toISOString().slice(0,10)}" required></label>
    <label class="block">Due<input name="due_on" type="date" class="ctl w-full mt-0.5"
      value="${new Date(Date.now()+7*864e5).toISOString().slice(0,10)}"></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Raise</button></div></form>`, 'Raise a form');
}
async function raise(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend(`${CFO_API}?action=submission_create`,
    { template_id: +f.template_id.value, period: f.period.value, due_on: f.due_on.value });
  if (res.success){ closeModal(); openSub(res.data.id); } else toast(res.error || 'Failed', false);
}

function openTemplates(){
  openModal(`<div class="overflow-x-auto"><table class="dense w-full">
    <thead><tr><th>Code</th><th>Title</th><th>Cadence</th><th>Ver</th><th>Checker</th><th>Approver</th>
      <th class="text-right">Submissions</th><th></th></tr></thead><tbody>
    ${DATA.templates.map(t => `<tr>
      <td>${cfoCode(t.code,'form')}</td><td class="text-[11px] font-medium">${esc(t.title)}</td>
      <td class="text-[10px]">${esc(String(t.cadence).replace(/_/g,' '))}</td>
      <td class="text-[11px]">v${t.version}</td>
      <td class="text-[10px]">${esc(t.checker_role||'—')}</td><td class="text-[10px]">${esc(t.approver_role||'—')}</td>
      <td class="text-right">${t.submissions}</td>
      <td>${t.is_active == 1 ? badge('active','green') : badge('inactive','gray')}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="text-[10px] text-gray-500 mt-2">The full builder — fields, cadence and approver roles — lives on
      <a href="sales-cfo-settings.php" class="text-indigo-600 underline">Admin &amp; Settings</a>.
      Changing a form's fields there creates a new version rather than editing history.</div>`,
    'Form templates');
}

load();
</script>
</html>
