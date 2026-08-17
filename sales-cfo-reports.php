<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_reports', 'Reports & Export');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Reports & Export',
    'The registers an auditor or the Board asks for, exported straight from the live data.'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card p-3 lg:col-span-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">
        Registers (CSV — opens in Excel)</div>
      <div id="registers" class="grid grid-cols-1 sm:grid-cols-2 gap-2"></div>
      <div class="text-[10px] text-gray-400 mt-2">Exports are generated from the same tables the screens read, so a
        register can never disagree with the cockpit. Every export is recorded in the audit trail.</div>
    </div>

    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">
        Internal audit submission pack</div>
      <div id="pack" class="space-y-1"></div>
      <button onclick="printPack()" class="btn-p w-full mt-2"><i class="fa-solid fa-file-pdf"></i> Build the pack (print / PDF)</button>
      <div class="text-[10px] text-gray-400 mt-1">Opens a print view with the current position, the risk register,
        open actions and the signed review records — save it as PDF from the print dialog.</div>
    </div>
  </div>

  <div class="card p-0">
    <div class="px-3 py-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
      Signed review records</div>
    <div class="overflow-x-auto"><table class="dense w-full">
      <thead><tr><th>Ref</th><th>Period</th><th>Cycle</th><th>Signed by</th><th>Signed at</th><th>Findings</th></tr></thead>
      <tbody id="signed"></tbody></table></div>
  </div>
</main>

<?php cfo_scripts(); ?>
<script>
let DATA = null;

async function load(){
  await cfoMeta();
  const { data } = await apiGet(CFO_API, { area:'reports' });
  DATA = data;
  const p = data.pack;
  cfoStrip('strip', [
    ['📋','Calendar tasks', cfoNum(p.tasks), p.overdue + ' overdue', +p.overdue ? 'bad' : 'good'],
    ['📄','Form submissions', cfoNum(p.submissions), p.approved + ' approved', 'info'],
    ['🗂','Risks', cfoNum(p.risks), 'on the register'],
    ['🛠','Actions', cfoNum(p.actions), 'raised to date'],
    ['📎','Evidence', cfoNum(p.evidence), 'records attached'],
  ]);

  const icons = { risk:'fa-triangle-exclamation', capa:'fa-screwdriver-wrench', calendar:'fa-calendar-check',
                  documents:'fa-file-lines', kri:'fa-chart-line', audit:'fa-fingerprint' };
  document.getElementById('registers').innerHTML = data.registers.map(r => `
    <a href="api/sales/cfo.php?export=${r.key}" class="flex items-center gap-3 border rounded-lg px-3 py-2 hover:bg-gray-50">
      <i class="fa-solid ${icons[r.key]||'fa-file'} text-indigo-500"></i>
      <div class="flex-1"><div class="text-[12px] font-medium">${esc(r.title)}</div>
        <div class="text-[10px] text-gray-400">${cfoNum(r.rows)} rows</div></div>
      <i class="fa-solid fa-download text-gray-400"></i></a>`).join('');

  document.getElementById('pack').innerHTML = [
    ['Period', p.period], ['Tasks in scope', cfoNum(p.tasks)], ['Overdue', cfoNum(p.overdue)],
    ['Approved filings', cfoNum(p.approved) + ' of ' + cfoNum(p.submissions)],
    ['Risks', cfoNum(p.risks)], ['Open actions', cfoNum(p.actions)], ['Evidence records', cfoNum(p.evidence)],
  ].map(([k,v]) => `<div class="flex justify-between text-[11px] border-b border-gray-100 py-0.5">
      <span class="text-gray-500">${esc(k)}</span><span class="font-semibold">${v}</span></div>`).join('');

  document.getElementById('signed').innerHTML = (data.signed_reviews||[]).map(r => `
    <tr><td>${cfoCode(r.rev_ref)}</td><td class="text-[11px] font-medium">${esc(r.period)}</td>
      <td class="text-[10px]">${esc(r.review_type)}</td><td class="text-[11px]">${esc(r.signed_by_name||'—')}</td>
      <td class="text-[10px] text-gray-500">${esc(cfoDT(r.signed_at))}</td>
      <td class="text-[10px] text-gray-600">${esc(String(r.findings||'').slice(0,120))}</td></tr>`).join('')
    || '<tr><td colspan="6" class="text-center text-gray-400 py-4">No reviews signed yet — sign one on Review &amp; Sign-off.</td></tr>';
}

/**
 * The pack is built in the browser from the data already on screen plus a fresh
 * dashboard read, so there is no second server-side renderer to drift out of step
 * with the cockpit.
 */
async function printPack(){
  const dash = (await apiGet(CFO_API, { area:'dashboard' })).data;
  const risk = (await apiGet(CFO_API, { area:'risk' })).data;
  const capa = (await apiGet(CFO_API, { area:'capa', status:'' })).data;
  const t = dash.tiles;
  const w = window.open('', '_blank');
  const rows = (arr, cols) => arr.map(r => '<tr>' + cols.map(c => `<td>${esc(String(c(r) ?? ''))}</td>`).join('') + '</tr>').join('');
  w.document.write(`<html><head><title>Internal audit submission pack — ${esc(dash.as_of)}</title>
    <style>body{font:11px/1.5 system-ui,Arial;margin:24px;color:#111}
      h1{font-size:18px;margin:0 0 2px} h2{font-size:13px;margin:18px 0 6px;border-bottom:1px solid #ccc;padding-bottom:2px}
      table{border-collapse:collapse;width:100%} td,th{border:1px solid #ddd;padding:3px 5px;text-align:left;font-size:10px}
      th{background:#f3f4f6} .k{display:inline-block;margin:0 18px 6px 0}
      .k b{display:block;font-size:16px}</style></head><body>
    <h1>${esc(CFO_META.org.name)} — internal audit submission pack</h1>
    <div style="color:#666">Finance compliance cockpit · as of ${esc(dash.as_of)} · prepared by ${esc(CFO_META.my_role_label)}</div>
    <h2>1. Compliance position</h2>
    <div>
      <span class="k">Compliance health<b>${t.health_pct}%</b>${t.done_to_date} of ${t.due_to_date} due to date</span>
      <span class="k">Overdue tasks<b>${t.overdue}</b>of ${t.total_tasks}</span>
      <span class="k">Overdue filings<b>${t.overdue_forms}</b></span>
      <span class="k">Overdue reviews<b>${t.overdue_reviews}</b></span>
      <span class="k">Open actions<b>${t.open_actions}</b></span>
      <span class="k">Statutory overdue<b>${t.statutory_overdue}</b>RBI ${t.rbi_overdue} · tax ${t.tax_overdue}</span>
    </div>
    <h2>2. Overdue obligations</h2>
    <table><tr><th>Ref</th><th>Task</th><th>Type</th><th>Owner</th><th>Due</th><th>Days late</th></tr>
      ${rows(dash.overdue_top||[], [r=>r.task_ref, r=>r.title, r=>r.task_type, r=>r.owner_name||r.owner_role, r=>r.due_on, r=>r.days_late])}</table>
    <h2>3. Risk register (residual rating)</h2>
    <table><tr><th>Ref</th><th>Sub-process</th><th>Risk</th><th>Inherent</th><th>Controls</th><th>Residual</th><th>Owner</th><th>Ver</th></tr>
      ${rows(risk.rows||[], [r=>r.ref, r=>r.sub_process, r=>r.description,
        r=>r.inherent_band+' '+r.inherent_score, r=>r.controls, r=>r.residual_band+' '+r.residual_score,
        r=>r.owner_name||r.owner_role, r=>'v'+r.version])}</table>
    <h2>4. Open corrective and preventive actions</h2>
    <table><tr><th>Ref</th><th>Action</th><th>Source</th><th>Owner</th><th>Due</th><th>Priority</th><th>Status</th></tr>
      ${rows((capa.rows||[]).filter(r=>!['closed','verified'].includes(r.status)),
        [r=>r.ref, r=>r.title, r=>(r.source_type+' '+(r.source_ref||'')), r=>r.owner_name||r.owner_role, r=>r.due_on, r=>r.priority, r=>r.status])}</table>
    <h2>5. Signed review records</h2>
    <table><tr><th>Ref</th><th>Period</th><th>Cycle</th><th>Signed by</th><th>Signed at</th><th>Findings</th></tr>
      ${rows(DATA.signed_reviews||[], [r=>r.rev_ref, r=>r.period, r=>r.review_type, r=>r.signed_by_name, r=>r.signed_at, r=>r.findings])}</table>
    <h2>6. Indicator status</h2>
    <div><span class="k">Green<b>${dash.kri_rag.green}</b></span><span class="k">Amber<b>${dash.kri_rag.amber}</b></span>
      <span class="k">Red<b>${dash.kri_rag.red}</b></span><span class="k">No data<b>${dash.kri_rag.no_data}</b></span></div>
    <p style="color:#666;margin-top:20px">Every figure in this pack is computed from the cockpit's tables at the time
      shown above. Changes to any record are versioned and recorded in the append-only audit trail.</p>
    </body></html>`);
  w.document.close();
  setTimeout(() => w.print(), 400);
}

load();
</script>
</html>
