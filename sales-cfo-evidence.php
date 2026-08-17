<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_evidence', 'Evidence Library');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Evidence Library',
    'What proves each filing, review, risk and action — the answer to "show me".',
    '<a href="sales-documents.php" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-folder-open"></i> Document library</a>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card p-0 lg:col-span-2">
      <div class="px-3 py-2 border-b flex items-center gap-2 flex-wrap">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Attached evidence</div>
        <div class="flex-1"></div>
        <select id="fType" class="ctl" onchange="load()">
          <option value="">Everything</option><option value="task">Calendar tasks</option>
          <option value="submission">Form submissions</option><option value="review">Reviews</option>
          <option value="risk">Risks</option><option value="action">Actions</option>
          <option value="kri">Indicators</option><option value="obligation">Obligations</option></select>
        <input id="q" class="ctl" placeholder="Search title, file or note…" oninput="deb()" style="min-width:190px">
      </div>
      <div class="overflow-x-auto" style="max-height:56vh"><table class="dense w-full">
        <thead><tr><th>Ref</th><th>Title</th><th>Proves</th><th>Document</th><th>Note</th>
          <th>Attached by</th><th>When</th><th></th></tr></thead>
        <tbody id="rows"></tbody></table></div>
    </div>

    <div class="card p-0">
      <div class="px-3 py-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        Gaps — closed or overdue work with no evidence</div>
      <div class="overflow-x-auto" style="max-height:56vh"><table class="dense w-full">
        <thead><tr><th>Ref</th><th>Task</th><th>Due</th><th></th></tr></thead>
        <tbody id="gapRows"></tbody></table></div>
    </div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('evidence',
  'Where would an auditor find nothing, and which gaps matter most?');

let tmr = null;
const deb = () => { clearTimeout(tmr); tmr = setTimeout(load, 350); };

async function load(){
  await cfoMeta();
  const { data } = await apiGet(CFO_API, { area:'evidence',
    entity_type: document.getElementById('fType').value, q: document.getElementById('q').value });
  const t = data.totals;
  cfoStrip('strip', [
    ['📎','Evidence records', cfoNum(t.total), 'linked to a filing, risk or review'],
    ['📁','From the document library', cfoNum(t.linked_docs), t.files + ' files in the DMS', 'info'],
    ['🕳','Evidence gaps', cfoNum(t.gaps), 'closed or overdue work with nothing attached', +t.gaps ? 'bad' : 'good'],
    ['🗂','Coverage by type', String((data.by_type||[]).length), (data.by_type||[]).map(x => x.entity_type + ' ' + x.n).join(' · ') || 'none yet'],
  ]);

  document.getElementById('rows').innerHTML = (data.rows||[]).map(e => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(e.ev_ref)}</td>
      <td class="text-[11px] font-medium">${esc(e.title)}</td>
      <td class="text-[10px]">${badge(e.entity_type, 'blue')} <span class="text-gray-500">#${e.entity_id}</span></td>
      <td class="text-[11px]">${e.doc_name
          ? `<i class="fa-solid fa-file-lines text-gray-400"></i> ${esc(e.doc_name)}
             ${e.folder_name ? `<div class="text-[9px] text-gray-400">${esc(e.folder_name)}</div>` : ''}`
          : (e.url ? `<a href="${esc(e.url)}" target="_blank" class="text-indigo-600 underline">external link</a>`
                   : '<span class="text-amber-600 text-[10px]">reference only</span>')}</td>
      <td class="text-[10px] text-gray-600">${esc(String(e.note||'').slice(0,70))}</td>
      <td class="text-[10px]">${esc(e.uploaded_by_name||'—')}</td>
      <td class="text-[10px] text-gray-400">${esc(cfoDT(e.created_at))}</td>
      <td class="rowact">${CFO_META.can_sign
        ? `<button onclick="detach(${e.id})" class="text-[10px] bg-red-50 text-red-700 px-2 py-0.5 rounded">Detach</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-6">Nothing attached yet. Open any task, form or risk and use “Attach evidence”.</td></tr>';

  document.getElementById('gapRows').innerHTML = (data.gaps||[]).map(g => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(g.task_ref,'task')}</td>
      <td class="text-[11px]">${esc(String(g.title).slice(0,60))}</td>
      <td class="text-[10px]">${cfoDate(g.due_on)}</td>
      <td class="rowact">${CFO_META.can_write
        ? `<button onclick="cfoAttach('task',${g.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Attach</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="4" class="text-center text-gray-400 py-4">No gaps — everything closed has evidence.</td></tr>';
}

async function detach(id){
  if (!confirm('Detach this evidence record? The document itself stays in the library.')) return;
  const res = await apiSend(`${CFO_API}?action=evidence_delete`, { id });
  if (res.success){ toast('Detached'); load(); } else toast(res.error || 'Failed', false);
}

load();
</script>
</html>
