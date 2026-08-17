<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_risk', 'Risk Register');
$openId = (int)($_GET['open'] ?? 0);
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Risk Register',
    'The Finance Operations risks, live. Ratings auto-compute from likelihood × impact; every edit is versioned.',
    '<a href="api/sales/cfo.php?export=risk" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-csv"></i> Export</a>
     <button onclick="openRisk(null)" class="btn-p" id="btnNew"><i class="fa-solid fa-plus"></i> New risk</button>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-2">
    <!-- 5x5 residual heatmap -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Residual heatmap</div>
      <div id="heat"></div>
      <div class="text-[10px] text-gray-400 mt-1">Rows = likelihood (5 top), columns = impact. Click a cell to filter.</div>
    </div>
    <div class="card p-0 xl:col-span-2">
      <div class="px-3 py-2 border-b flex items-center gap-2 flex-wrap">
        <div id="chips" class="flex gap-1 flex-wrap"></div>
        <div class="flex-1"></div>
        <select id="fSub" class="ctl" onchange="load()"><option value="">All sub-processes</option></select>
        <select id="fStatus" class="ctl" onchange="load()">
          <option value="">All status</option><option value="open">Open</option>
          <option value="mitigated">Mitigated</option><option value="accepted">Accepted</option>
          <option value="closed">Closed</option></select>
        <input id="q" class="ctl" placeholder="Search ref, risk or control…" oninput="deb()" style="min-width:200px">
      </div>
      <div class="overflow-x-auto" style="max-height:60vh"><table class="dense w-full">
        <thead><tr><th>Ref</th><th>Sub-process</th><th>Description</th><th>Inherent</th>
          <th>Controls</th><th>Residual</th><th>Owner</th><th>Next review</th><th>Ver</th><th></th></tr></thead>
        <tbody id="rows"></tbody></table></div>
    </div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('risk',
  'Which risks are under-controlled and which need re-rating?');

let ROWS = [], BAND = '', CELL = null, tmr = null;
const deb = () => { clearTimeout(tmr); tmr = setTimeout(load, 350); };
const OPEN_ID = <?= $openId ?>;

async function load(){
  const m = await cfoMeta();
  document.getElementById('btnNew').classList.toggle('hidden', !m.can_write);
  const p = { area:'risk', status: document.getElementById('fStatus').value,
              sub_process: document.getElementById('fSub').value, q: document.getElementById('q').value };
  const { data } = await apiGet(CFO_API, p);
  ROWS = data.rows || [];

  if (document.getElementById('fSub').options.length <= 1) {
    document.getElementById('fSub').innerHTML = '<option value="">All sub-processes</option>' +
      (data.sub_processes||[]).map(s => `<option>${esc(s)}</option>`).join('');
  }

  const b = data.bands;
  cfoStrip('strip', [
    ['🗂','Risks on the register', cfoNum(data.totals.total), 'Finance Operations'],
    ['🔴','Critical', cfoNum(b.Critical), 'residual 15+', +b.Critical ? 'bad' : 'good'],
    ['🟠','High', cfoNum(b.High), 'residual 10–14', +b.High ? 'warn' : 'good'],
    ['🟡','Medium', cfoNum(b.Medium), 'residual 5–9', 'info'],
    ['🟢','Low', cfoNum(b.Low), 'residual under 5', 'good'],
    ['📅','Review overdue', cfoNum(ROWS.filter(r => r.next_review && r.next_review < new Date().toISOString().slice(0,10)).length),
      'past their review date', 'warn'],
  ]);

  // chips
  document.getElementById('chips').innerHTML = ['', 'Critical', 'High', 'Medium', 'Low'].map(x => `
    <span class="cchip ${BAND===x?'on':''}" onclick="setBand('${x}')">${x || 'All'}${x ? ' (' + b[x] + ')' : ''}</span>`).join('');

  // heatmap
  const heat = data.heat, colors = { Critical:'#991b1b', High:'#c2410c', Medium:'#a16207', Low:'#15803d' };
  const bandOf = s => s >= 15 ? 'Critical' : s >= 10 ? 'High' : s >= 5 ? 'Medium' : 'Low';
  let html = '<table class="heat w-full"><tbody>';
  for (let l = 5; l >= 1; l--) {
    html += `<tr><td class="text-gray-400" style="width:14px">${l}</td>`;
    for (let i = 1; i <= 5; i++) {
      const refs = (heat[l] && heat[l][i]) ? heat[l][i] : [];
      const band = bandOf(l * i);
      html += `<td><div class="cellbox" style="background:${colors[band]};opacity:${refs.length?1:.28};cursor:pointer"
        title="likelihood ${l} × impact ${i} = ${l*i} (${band})" onclick="setCell(${l},${i})">
        ${refs.map(r => `<span style="font-size:9px">${esc(r)}</span>`).join('')}</div></td>`;
    }
    html += '</tr>';
  }
  html += '<tr><td></td>' + [1,2,3,4,5].map(i => `<td class="text-gray-400">${i}</td>`).join('') + '</tr></tbody></table>';
  document.getElementById('heat').innerHTML = html;

  render();
  if (OPEN_ID) openDetail(OPEN_ID);
}

function setBand(x){ BAND = x; CELL = null; render(); }
function setCell(l, i){ CELL = (CELL && CELL[0]===l && CELL[1]===i) ? null : [l,i]; BAND = ''; render(); }

function render(){
  let rows = ROWS;
  if (BAND) rows = rows.filter(r => r.residual_band === BAND);
  if (CELL) rows = rows.filter(r => +r.residual_likelihood === CELL[0] && +r.residual_impact === CELL[1]);
  document.getElementById('rows').innerHTML = rows.map(r => `
    <tr class="hover:bg-gray-50 cursor-pointer" onclick="openDetail(${r.id})">
      <td>${cfoCode(r.ref,'risk')}</td>
      <td class="text-[11px]">${esc(r.sub_process||'—')}</td>
      <td class="text-[11px]" style="min-width:260px">${esc(r.description)}</td>
      <td>${cfoBand(r.inherent_band, r.inherent_score)}
        <div class="text-[9px] text-gray-400">${r.inherent_likelihood}×${r.inherent_impact}</div></td>
      <td class="text-[10px] text-gray-600" style="min-width:180px">${esc(r.controls||'—')}</td>
      <td>${cfoBand(r.residual_band, r.residual_score)}
        <div class="text-[9px] text-gray-400">${r.residual_likelihood}×${r.residual_impact}</div></td>
      <td class="text-[11px]">${cfoOwner(r)}</td>
      <td class="text-[11px]">${cfoDueCell(r.next_review, r.status)}</td>
      <td class="text-[10px] text-gray-500">v${r.version}</td>
      <td>${cfoStatus(r.status)}${+r.action_count ? `<div class="text-[9px] text-orange-600">${r.action_count} action(s)</div>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="10" class="text-center text-gray-400 py-6">No risks match.</td></tr>';
}

/* ---------- detail + edit ---------- */
async function openDetail(id){
  const { data } = await apiGet(CFO_API, { risk: id });
  const r = data.risk;
  openModal(`
    <div class="flex items-center gap-2 mb-2">${cfoCode(r.ref,'risk')} ${cfoStatus(r.status)}
      <span class="text-[10px] text-gray-400">version ${r.version}</span>
      <div class="flex-1"></div>
      ${CFO_META.can_write ? `<button onclick="openRisk(${r.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Edit</button>` : ''}
      <button onclick="raiseCapa('${esc(r.ref)}')" class="text-[10px] bg-orange-100 text-orange-800 px-2 py-0.5 rounded">Raise action</button>
    </div>
    <div class="text-sm font-semibold mb-2">${esc(r.description)}</div>
    ${cfoRow('Sub-process', esc(r.sub_process||''))}
    ${cfoRow('Inherent rating', cfoBand(r.inherent_band, r.inherent_score) + ` <span class="text-gray-400">likelihood ${r.inherent_likelihood} × impact ${r.inherent_impact}</span>`)}
    ${cfoRow('Controls in place', esc(r.controls||''))}
    ${cfoRow('Residual rating', cfoBand(r.residual_band, r.residual_score) + ` <span class="text-gray-400">likelihood ${r.residual_likelihood} × impact ${r.residual_impact}</span>`)}
    ${cfoRow('Control effect', `${r.inherent_score} → ${r.residual_score} (${Math.round((1 - r.residual_score / Math.max(1, r.inherent_score)) * 100)}% reduction)`)}
    ${cfoRow('Owner', cfoOwner(r))}
    ${cfoRow('Next review', cfoDueCell(r.next_review, r.status))}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">Actions raised against this risk</div>
    ${(data.actions||[]).length ? `<table class="dense w-full"><tbody>${data.actions.map(a => `
      <tr><td>${cfoCode(a.ref,'capa')}</td><td class="text-[11px]">${esc(a.title)}</td>
        <td>${cfoPri(a.priority)}</td><td class="text-[11px]">${cfoDate(a.due_on)}</td><td>${cfoStatus(a.status)}</td></tr>`).join('')}</tbody></table>`
      : '<div class="text-[11px] text-gray-400">None. A high residual rating with no action is itself a finding.</div>'}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">Evidence</div>
    ${cfoEvidenceList(data.evidence, 'risk', r.id)}
    <div class="mt-3 text-[11px] font-semibold text-gray-600">Version history</div>
    ${cfoHistory(data.history)}`, 'Risk ' + r.ref);
}

function scale(name, val){
  return `<select name="${name}" class="ctl w-full mt-0.5">${[1,2,3,4,5].map(n =>
    `<option value="${n}" ${+val===n?'selected':''}>${n} — ${['Rare','Unlikely','Possible','Likely','Almost certain'][n-1]}</option>`).join('')}</select>`;
}
function scaleI(name, val){
  return `<select name="${name}" class="ctl w-full mt-0.5">${[1,2,3,4,5].map(n =>
    `<option value="${n}" ${+val===n?'selected':''}>${n} — ${['Insignificant','Minor','Moderate','Major','Severe'][n-1]}</option>`).join('')}</select>`;
}

function openRisk(id){
  const r = id ? ROWS.find(x => +x.id === +id) : null;
  const owners = CFO_META.owners.map(o => `<option value="${o.id}" ${r && +r.owner_employee_id===+o.id?'selected':''}>${esc(o.full_name)}</option>`).join('');
  openModal(`<form onsubmit="saveRisk(event, ${id || 'null'})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block col-span-2">Risk description
      <textarea name="description" rows="2" required class="ctl w-full mt-0.5" style="height:auto">${esc(r?r.description:'')}</textarea></label>
    <label class="block">Sub-process<input name="sub_process" value="${esc(r?r.sub_process:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Status<select name="status" class="ctl w-full mt-0.5">
      ${['open','mitigated','accepted','closed'].map(s => `<option ${r&&r.status===s?'selected':''}>${s}</option>`).join('')}</select></label>
    <label class="block">Inherent likelihood${scale('inherent_likelihood', r?r.inherent_likelihood:3)}</label>
    <label class="block">Inherent impact${scaleI('inherent_impact', r?r.inherent_impact:3)}</label>
    <label class="block col-span-2">Controls in place
      <textarea name="controls" rows="2" class="ctl w-full mt-0.5" style="height:auto">${esc(r?r.controls:'')}</textarea></label>
    <label class="block">Residual likelihood${scale('residual_likelihood', r?r.residual_likelihood:2)}</label>
    <label class="block">Residual impact${scaleI('residual_impact', r?r.residual_impact:3)}</label>
    <label class="block">Owner role<input name="owner_role" value="${esc(r?r.owner_role:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Owner<select name="owner_employee_id" class="ctl w-full mt-0.5"><option value="">—</option>${owners}</select></label>
    <label class="block">Next review<input name="next_review" type="date" value="${esc(r?r.next_review:'')}" class="ctl w-full mt-0.5"></label>
    <div class="col-span-2 text-[10px] text-gray-500">Ratings are not typed in: the score is likelihood × impact and the
      band follows from it. Saving increments the version and records the before → after in the trail.</div>
    <div class="col-span-2 flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${r ? 'Save new version' : 'Add to the register'}</button></div>
  </form>`, r ? 'Edit ' + r.ref : 'New risk');
}

async function saveRisk(e, id){
  e.preventDefault(); const f = e.target, body = { id: id || undefined };
  [...f.elements].forEach(el => { if (el.name) body[el.name] = el.value; });
  const res = await apiSend(`${CFO_API}?action=risk_save`, body);
  if (res.success){ toast(id ? 'Saved as v' + res.data.version : 'Added as ' + res.data.ref); closeModal(); load(); }
  else toast(res.error || 'Failed', false);
}

function raiseCapa(ref){ location.href = 'sales-cfo-capa.php?source=risk&ref=' + encodeURIComponent(ref); }

load();
</script>
</html>
