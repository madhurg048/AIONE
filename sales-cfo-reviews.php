<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_reviews', 'Review & Sign-off');
$openId = (int)($_GET['open'] ?? 0);
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Review & Sign-off',
    'The management review cycle: what the meeting looked at, what it found, and who signed it.',
    '<a href="sales-cfo-reports.php" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-export"></i> Signed records</a>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option value="scheduled">Scheduled</option>
      <option value="in_progress">In progress</option><option value="signed">Signed</option>
      <option value="skipped">Skipped</option></select>
    <select id="fType" class="ctl" onchange="load()">
      <option value="">All cycles</option><option value="monthly">Monthly</option>
      <option value="quarterly">Quarterly</option><option value="annual">Annual</option>
      <option value="adhoc">Ad-hoc</option></select>
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400" id="rowCount"></span>
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto" style="max-height:62vh"><table class="dense w-full">
      <thead><tr><th>Ref</th><th>Period</th><th>Cycle</th><th>Scheduled</th><th>Agenda</th>
        <th>Status</th><th>Signed by</th><th class="text-right">Evidence</th><th></th></tr></thead>
      <tbody id="rows"></tbody></table></div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('reviews',
  'Are sign-offs happening on time, and what is the risk in the gaps?');

let ROWS = [];
const OPEN_ID = <?= $openId ?>;

async function load(){
  await cfoMeta();
  const { data } = await apiGet(CFO_API, { area:'reviews',
    status: document.getElementById('fStatus').value, type: document.getElementById('fType').value });
  ROWS = data.rows || [];
  const t = data.totals;
  cfoStrip('strip', [
    ['✍️','Review cycles', cfoNum(t.total), 'monthly + quarterly'],
    ['✅','Signed', cfoNum(t.signed_cnt), cfoPct(t.signed_cnt, t.total) + ' of the calendar', 'good'],
    ['⏰','Overdue sign-offs', cfoNum(t.overdue_cnt), 'past their meeting date', +t.overdue_cnt ? 'bad' : 'good'],
    ['📅','Upcoming', cfoNum(t.upcoming_cnt), 'still ahead', 'info'],
  ]);
  document.getElementById('rowCount').textContent = ROWS.length + ' shown';

  const today = new Date().toISOString().slice(0,10);
  document.getElementById('rows').innerHTML = ROWS.map(r => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(r.rev_ref)}</td>
      <td class="text-[11px] font-medium cursor-pointer" onclick="openReview(${r.id})">${esc(r.period)}</td>
      <td class="text-[10px]">${badge(r.review_type, r.review_type === 'quarterly' ? 'purple' : 'blue')}</td>
      <td class="text-[11px]">${cfoDueCell(r.scheduled_on, r.status)}</td>
      <td class="text-[10px] text-gray-600" style="min-width:240px">${esc(r.agenda||'—')}</td>
      <td>${cfoStatus(r.status !== 'signed' && r.status !== 'skipped' && r.scheduled_on < today ? 'overdue' : r.status)}</td>
      <td class="text-[11px]">${esc(r.signed_by_name || '—')}
        ${r.signed_at ? `<div class="text-[9px] text-gray-400">${esc(cfoDT(r.signed_at))}</div>` : ''}</td>
      <td class="text-right text-[11px]">${r.evidence_count}</td>
      <td class="rowact"><button onclick="openReview(${r.id})" class="text-[10px] bg-gray-100 px-2 py-0.5 rounded">Open</button></td>
    </tr>`).join('') || '<tr><td colspan="9" class="text-center text-gray-400 py-6">No review cycles match.</td></tr>';

  if (OPEN_ID) openReview(OPEN_ID);
}

async function openReview(id){
  const { data } = await apiGet(CFO_API, { review: id });
  const r = data.review, pack = data.pack;
  const canSign = CFO_META.can_sign && r.status !== 'signed';
  openModal(`
    <div class="flex items-center gap-2 mb-2">${cfoCode(r.rev_ref)} ${cfoStatus(r.status)}
      <span class="text-[10px] text-gray-400">${esc(r.review_type)} · ${cfoDate(r.scheduled_on)}</span></div>
    <div class="text-sm font-semibold mb-2">Management review — ${esc(r.period)}</div>
    ${cfoRow('Agenda', esc(r.agenda||''))}
    ${cfoRow('Signed', r.signed_by_name ? esc(r.signed_by_name) + ' · ' + esc(cfoDT(r.signed_at)) +
        (r.signature_note ? ` <span class="text-gray-500">(${esc(r.signature_note)})</span>` : '') : '<span class="text-amber-600">not yet signed</span>')}

    <div class="mt-3 text-[11px] font-semibold text-gray-600">What this meeting has to look at (live)</div>
    <div class="flex gap-2 flex-wrap mt-1">
      <div class="ctile"><div class="ct-l">⏰ Overdue tasks</div><div class="ct-n" style="color:${pack.overdue_tasks?'#b91c1c':'#047857'}">${pack.overdue_tasks}</div></div>
      <div class="ctile"><div class="ct-l">🛠 Open actions</div><div class="ct-n" style="color:${pack.open_actions?'#b45309':'#047857'}">${pack.open_actions}</div></div>
      <div class="ctile"><div class="ct-l">🔴 Red indicators</div><div class="ct-n" style="color:${pack.red_indicators.length?'#b91c1c':'#047857'}">${pack.red_indicators.length}</div></div>
    </div>
    ${pack.red_indicators.length ? `<table class="dense w-full mt-2"><tbody>${pack.red_indicators.map(k => `
      <tr><td>${cfoCode(k.code)}</td><td class="text-[11px]">${esc(k.name)}</td>
        <td class="text-[11px]">${esc(k.last_period||'—')}: <b>${esc(k.last_value)}</b> vs target ${esc(k.target_value)}</td>
        <td>${cfoRag(k.rag)}</td></tr>`).join('')}</tbody></table>` : ''}

    <div class="mt-3 text-[11px] font-semibold text-gray-600">Findings</div>
    ${r.status === 'signed'
      ? `<div class="text-[11px] whitespace-pre-wrap bg-gray-50 border rounded p-2">${esc(r.findings||'—')}</div>
         <div class="text-[10px] text-green-700 mt-1">Signed records cannot be edited — that is what makes them evidence.</div>`
      : (canSign ? `<form onsubmit="sign(event,${id})" class="space-y-2">
          <textarea name="findings" rows="4" class="ctl w-full" style="height:auto"
            placeholder="What was reviewed, what was found, what was decided…">${esc(r.findings||'')}</textarea>
          <input name="note" class="ctl w-full" placeholder="Signature note (e.g. reviewed with the Audit Committee)">
          <div class="flex justify-end gap-2">
            <button type="button" onclick="setStatus(${id},'in_progress')" class="px-3 py-1.5 border rounded text-xs">Mark in progress</button>
            <button type="button" onclick="setStatus(${id},'skipped')" class="px-3 py-1.5 border rounded text-xs">Skip with reason</button>
            <button class="btn-p">Sign off</button></div></form>`
        : '<div class="text-[11px] text-gray-500">Only the CFO signs off a review.</div>')}

    <div class="mt-3 text-[11px] font-semibold text-gray-600">Evidence</div>
    ${cfoEvidenceList(data.evidence, 'review', id)}`, 'Review ' + (r.rev_ref || id));
}

async function sign(e, id){
  e.preventDefault(); const f = e.target;
  const res = await apiSend(`${CFO_API}?action=review_sign`, { id, findings: f.findings.value, note: f.note.value });
  if (res.success){ toast('Review signed'); closeModal(); load(); } else toast(res.error || 'Failed', false);
}
async function setStatus(id, status){
  let findings = null;
  if (status === 'skipped') { findings = prompt('Why is this review being skipped?'); if (findings === null) return; }
  const res = await apiSend(`${CFO_API}?action=review_status`, { id, status, findings });
  if (res.success){ toast('Updated'); closeModal(); load(); } else toast(res.error || 'Failed', false);
}

load();
</script>
</html>
