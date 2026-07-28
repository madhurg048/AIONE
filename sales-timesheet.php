<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$isManager = in_array(sales_role(), ['manager','service_manager','admin'], true);
sales_head('Timesheet');
sales_header($me, 'My Timesheet & Activities');
sales_nav('timesheet');
?>
<main class="p-2 space-y-2">

  <!-- day toolbar -->
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase mr-1">Day</span>
    <input id="date" type="date" class="ctl" onchange="loadDay()">
    <span id="dayTotal" class="text-xs text-gray-500"></span>
    <div class="flex-1"></div>
    <button onclick="aiFill()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i> AI fill</button>
    <!-- Voice dictation has been removed org-wide: the mic button, the transcribe endpoint
         and the timesheet ?parse= handler are all gone. Entries are manual or AI-filled. -->
    <button onclick="addRow()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-plus"></i> Entry</button>
    <button onclick="submitDay()" class="btn-p"><i class="fa-solid fa-paper-plane"></i> Submit for approval</button>
  </div>

  <!-- entries -->
  <div class="card">
    <table class="dense"><thead><tr>
      <th style="width:130px">Type</th><th style="width:70px">Hours</th><th style="width:120px">Related</th>
      <th>Description</th><th style="width:90px">Status</th><th style="width:60px">Src</th><th style="width:50px"></th>
    </tr></thead><tbody id="rows"></tbody></table>
  </div>

  <?php if ($isManager): ?>
  <!-- manager approvals -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Pending Approvals (my team)</div>
    <table class="dense"><thead><tr>
      <th>Employee</th><th>Date</th><th>Type</th><th>Hours</th><th>Description</th><th style="width:130px">Action</th>
    </tr></thead><tbody id="approvals"></tbody></table>
  </div>
  <?php endif; ?>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;
const TYPES = ['call','meeting','follow_up','onboarding','admin','travel','training','other'];
const stColor = { draft:'gray', submitted:'blue', approved:'green', rejected:'red' };
document.getElementById('date').value = new Date().toISOString().slice(0,10);

async function loadDay() {
  const date = document.getElementById('date').value;
  const res = await apiGet('timesheet.php', { date });
  document.getElementById('dayTotal').textContent = `${res.total_hours || 0} h logged`;
  document.getElementById('rows').innerHTML = res.data.map(t => rowHtml(t)).join('')
    || '<tr><td colspan="7" class="text-center text-gray-400 py-3">No entries — add manually, by voice, or AI-fill</td></tr>';
  if (IS_MANAGER) loadApprovals();
}
function rowHtml(t){
  const editable = t.status === 'draft';
  return `<tr>
    <td>${editable ? `<select onchange="upd(${t.id},'activity_type',this.value)" class="border rounded px-1 py-1 w-full">${TYPES.map(x=>`<option ${x===t.activity_type?'selected':''}>${x}</option>`).join('')}</select>` : esc(t.activity_type)}</td>
    <td>${editable ? `<input type="number" step="0.25" value="${t.hours}" onchange="upd(${t.id},'hours',this.value)" class="border rounded px-1 py-1 w-full">` : t.hours}</td>
    <td class="text-gray-500">${t.related_type!=='none'?esc(t.related_type+' #'+(t.related_id||'')):'—'}</td>
    <td>${editable ? `<input value="${esc(t.description||'')}" onchange="upd(${t.id},'description',this.value)" class="border rounded px-1 py-1 w-full">` : esc(t.description||'')}</td>
    <td>${badge(t.status, stColor[t.status])}</td>
    <td>${t.source==='ai'?'🤖':t.source==='voice'?'🎙️':'✍️'}</td>
    <td class="rowact">${editable?`<button onclick="del(${t.id})" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-trash"></i></button>`:''}</td>
  </tr>`;
}
async function upd(id, field, value){ await apiSend('timesheet.php?id='+id, {[field]:value}, 'PATCH'); loadDay(); }
async function del(id){ await apiSend('timesheet.php?id='+id, {status:'rejected'}, 'PATCH'); loadDay(); } // soft-remove own draft

async function addRow(desc){
  const date = document.getElementById('date').value;
  await apiSend('timesheet.php', { entry_date: date, hours: 1, activity_type: 'other', description: desc || '', source: 'manual' });
  loadDay();
}

async function aiFill(){
  const date = document.getElementById('date').value;
  toast('AI drafting from your activity…');
  const res = await apiSend('timesheet.php?ai_fill=1', { date });
  if (!res.success) return toast(res.error||'Failed', false);
  const entries = res.data.entries || [];
  if (!entries.length) return toast(res.data.note || 'Nothing to draft', false);
  // save each as a draft (source=ai)
  for (const e of entries) await apiSend('timesheet.php', { entry_date: date, hours: e.hours||1, activity_type: e.activity_type||'other', description: e.description||'', source: 'ai' });
  toast(`AI added ${entries.length} draft entr${entries.length>1?'ies':'y'}`);
  loadDay();
}

async function submitDay(){
  const date = document.getElementById('date').value;
  const res = await apiSend('timesheet.php?submit=1', { date });
  if (res.success) { toast(`${res.data.submitted} entr${res.data.submitted===1?'y':'ies'} submitted for approval`); loadDay(); }
}

/* ---- manager approvals ---- */
async function loadApprovals(){
  const { data } = await apiGet('timesheet.php', { pending: 1 });
  document.getElementById('approvals').innerHTML = data.map(t => `
    <tr>
      <td class="font-medium">${esc(t.employee_name)}</td>
      <td>${esc(t.entry_date)}</td><td>${esc(t.activity_type)}</td><td>${t.hours}</td>
      <td class="text-gray-600">${esc(t.description||'')}</td>
      <td class="whitespace-nowrap">
        <button onclick="approve(${t.id},'approved')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Approve</button>
        <button onclick="approve(${t.id},'rejected')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Reject</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-2">Nothing pending</td></tr>';
}
async function approve(id, status){ const r = await apiSend('timesheet.php?id='+id, { status }, 'PATCH'); if(r.success){toast('Marked '+status); loadApprovals();} }

loadDay();
</script>
</html>
