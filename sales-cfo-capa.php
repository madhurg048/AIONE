<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_capa', 'Actions / CAPA');
$srcType = preg_replace('/[^a-z_]/', '', (string)($_GET['source'] ?? ''));
$srcRef  = substr((string)($_GET['ref'] ?? ''), 0, 48);
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Actions / CAPA',
    'Corrective and preventive actions, with their source and their ageing. A finding with no action is not closed.',
    '<a href="api/sales/cfo.php?export=capa" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-csv"></i> Export</a>
     <button onclick="openCapa(null)" id="btnNew" class="btn-p"><i class="fa-solid fa-plus"></i> New action</button>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div id="buckets" class="flex gap-1 flex-wrap"></div>
    <div class="flex-1"></div>
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option value="open">Open</option>
      <option value="in_progress">In progress</option><option value="done">Done</option>
      <option value="verified">Verified</option><option value="closed">Closed</option></select>
    <select id="fPri" class="ctl" onchange="load()">
      <option value="">All priorities</option><option value="critical">Critical</option>
      <option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
    <select id="fSrc" class="ctl" onchange="load()">
      <option value="">All sources</option><option value="review">Review</option><option value="audit">Audit</option>
      <option value="incident">Incident</option><option value="risk">Risk</option>
      <option value="kpi_breach">KPI breach</option><option value="regulator">Regulator</option>
      <option value="other">Other</option></select>
    <input id="q" class="ctl" placeholder="Search ref, title or source…" oninput="deb()" style="min-width:200px">
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto" style="max-height:62vh"><table class="dense w-full">
      <thead><tr><th>Ref</th><th>Title</th><th>Kind</th><th>Source</th><th>Owner</th>
        <th>Due</th><th>Ageing</th><th>Priority</th><th>Status</th><th></th></tr></thead>
      <tbody id="rows"></tbody></table></div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('capa',
  'Which actions are stalled, and is the priority mix credible?');

let ROWS = [], BUCKET = '', RISKS = [], tmr = null;
const deb = () => { clearTimeout(tmr); tmr = setTimeout(load, 350); };

async function load(){
  const m = await cfoMeta();
  document.getElementById('btnNew').classList.toggle('hidden', !m.can_write);
  const p = { area:'capa', bucket: BUCKET, status: document.getElementById('fStatus').value,
              priority: document.getElementById('fPri').value, source_type: document.getElementById('fSrc').value,
              q: document.getElementById('q').value };
  const { data } = await apiGet(CFO_API, p);
  ROWS = data.rows || []; RISKS = data.risk_refs || [];
  const t = data.totals;

  cfoStrip('strip', [
    ['🛠','Open actions', cfoNum(t.open_cnt), t.total + ' raised in total', +t.open_cnt ? 'warn' : 'good'],
    ['🟢','Open ≤ 30 days', cfoNum(t.b30), 'within tolerance', 'good'],
    ['🟠','Open 31–60 days', cfoNum(t.b60), 'ageing', +t.b60 ? 'warn' : 'good'],
    ['🔴','Open > 60 days', cfoNum(t.bover), 'escalate these', +t.bover ? 'bad' : 'good'],
    ['✅','Closed', cfoNum(t.closed_cnt), cfoPct(t.closed_cnt, t.total) + ' of all actions', 'good'],
  ]);

  document.getElementById('buckets').innerHTML = [['','All open + closed'],['30','≤30 days'],['60','31–60'],['over','>60']]
    .map(([k,l]) => `<span class="cchip ${BUCKET===k?'on':''}" onclick="setBucket('${k}')">${l}</span>`).join('');

  document.getElementById('rows').innerHTML = ROWS.map(r => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(r.ref,'capa')}</td>
      <td class="text-[11px] font-medium cursor-pointer" onclick="openCapa(${r.id})">${esc(r.title)}
        ${r.description ? `<div class="text-[9px] text-gray-400">${esc(String(r.description).slice(0,90))}</div>` : ''}</td>
      <td class="text-[10px]">${badge(r.action_kind, r.action_kind === 'preventive' ? 'blue' : 'purple')}</td>
      <td class="text-[10px]">${esc(String(r.source_type).replace('_',' '))}
        ${r.source_ref ? `<div class="text-[9px] text-gray-500">${esc(r.source_ref)}</div>` : ''}</td>
      <td class="text-[11px]">${cfoOwner(r)}</td>
      <td class="text-[11px]">${cfoDueCell(r.due_on, r.status)}</td>
      <td class="text-[11px] ${+r.age_days > 60 ? 'text-red-700 font-semibold' : +r.age_days > 30 ? 'text-amber-700' : 'text-gray-500'}">
        ${r.age_days == null ? '—' : (r.age_days > 0 ? r.age_days + 'd past due' : Math.abs(r.age_days) + 'd left')}</td>
      <td>${cfoPri(r.priority)}</td>
      <td>${cfoStatus(r.status)}</td>
      <td class="rowact">${CFO_META.can_write ? `
        <select class="ctl text-[10px]" style="padding:1px 4px" onchange="setStatus(${r.id}, this.value); this.selectedIndex=0">
          <option value="">set…</option><option value="in_progress">In progress</option><option value="done">Done</option>
          <option value="verified">Verified</option><option value="closed">Closed</option></select>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="10" class="text-center text-gray-400 py-6">No actions match. Breaches auto-raise one — see KPI / KRI.</td></tr>';
}

function setBucket(k){ BUCKET = k; load(); }

async function setStatus(id, status){
  if (!status) return;
  let closure = null;
  if (status === 'closed' || status === 'verified') {
    closure = prompt('What evidence closes this action?');
    if (closure === null) return;
  }
  const res = await apiSend(`${CFO_API}?action=capa_save`, { id, status, closure_evidence: closure });
  if (res.success){ toast('Action updated'); load(); } else toast(res.error || 'Failed', false);
}

function openCapa(id){
  const r = id ? ROWS.find(x => +x.id === +id) : null;
  const owners = CFO_META.owners.map(o => `<option value="${o.id}" ${r && +r.owner_employee_id===+o.id?'selected':''}>${esc(o.full_name)}</option>`).join('');
  const srcSel = ['review','audit','incident','risk','kpi_breach','regulator','other'];
  const preType = '<?= $srcType ?>', preRef = '<?= addslashes($srcRef) ?>';
  openModal(`<form onsubmit="save(event, ${id || 'null'})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block col-span-2">Action<input name="title" required value="${esc(r?r.title:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block col-span-2">What is being done
      <textarea name="description" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(r?r.description:'')}</textarea></label>
    <label class="block">Source<select name="source_type" class="ctl w-full mt-0.5">
      ${srcSel.map(s => `<option value="${s}" ${(r ? r.source_type===s : preType===s)?'selected':''}>${s.replace('_',' ')}</option>`).join('')}</select></label>
    <label class="block">Source reference
      <input name="source_ref" list="riskRefs" value="${esc(r ? r.source_ref : preRef)}" class="ctl w-full mt-0.5" placeholder="e.g. FR-06, RVW-003">
      <datalist id="riskRefs">${RISKS.map(x => `<option value="${esc(x.ref)}">${esc(x.sub_process)}</option>`).join('')}</datalist></label>
    <label class="block">Kind<select name="action_kind" class="ctl w-full mt-0.5">
      <option value="corrective" ${r&&r.action_kind==='corrective'?'selected':''}>Corrective — fix what happened</option>
      <option value="preventive" ${r&&r.action_kind==='preventive'?'selected':''}>Preventive — stop it recurring</option></select></label>
    <label class="block">Priority<select name="priority" class="ctl w-full mt-0.5">
      ${['critical','high','medium','low'].map(s => `<option ${r&&r.priority===s?'selected':(!r&&s==='medium'?'selected':'')}>${s}</option>`).join('')}</select></label>
    <label class="block">Owner role<input name="owner_role" value="${esc(r?r.owner_role:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Owner<select name="owner_employee_id" class="ctl w-full mt-0.5"><option value="">—</option>${owners}</select></label>
    <label class="block">Due<input name="due_on" type="date" value="${esc(r?r.due_on:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Status<select name="status" class="ctl w-full mt-0.5">
      ${['open','in_progress','done','verified','closed'].map(s => `<option ${r&&r.status===s?'selected':''}>${s}</option>`).join('')}</select></label>
    <label class="block col-span-2">Closure evidence
      <input name="closure_evidence" value="${esc(r?r.closure_evidence:'')}" class="ctl w-full mt-0.5"
        placeholder="What proves this is done — a signed form, a config change, a report"></label>
    <div class="col-span-2 flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${r ? 'Save' : 'Raise action'}</button></div>
  </form>`, r ? 'Edit ' + r.ref : 'New action');
}

async function save(e, id){
  e.preventDefault(); const f = e.target, body = { id: id || undefined };
  [...f.elements].forEach(el => { if (el.name) body[el.name] = el.value; });
  const res = await apiSend(`${CFO_API}?action=capa_save`, body);
  if (res.success){ toast(id ? 'Saved' : 'Raised as ' + res.data.ref); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}

load();
<?php if ($srcType && $srcRef): ?>
setTimeout(() => openCapa(null), 400);   // arrived from the risk register's "Raise action"
<?php endif; ?>
</script>
</html>
