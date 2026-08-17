<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_kri', 'KPI / KRI Monitoring');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('KPI / KRI Monitoring',
    'Performance indicators and risk indicators against their thresholds. A red reading raises an action by itself.',
    '<a href="api/sales/cfo.php?export=kri" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-csv"></i> Export</a>
     <a href="sales-kra.php?view=kpi" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-link"></i> KPI library</a>
     <button onclick="openKri(null)" id="btnNew" class="btn-p hidden"><i class="fa-solid fa-plus"></i> New indicator</button>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div id="chips" class="flex gap-1 flex-wrap"></div>
    <div class="flex-1"></div>
    <select id="fKind" class="ctl" onchange="render()">
      <option value="">KPIs and KRIs</option><option value="kpi">KPIs only</option><option value="kri">KRIs only</option></select>
    <input id="q" class="ctl" placeholder="Search indicator…" oninput="render()" style="min-width:200px">
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto"><table class="dense w-full">
      <thead><tr><th>Code</th><th>Indicator</th><th>Kind</th><th>Target</th><th>Thresholds</th>
        <th>Latest</th><th>RAG</th><th>Trend</th><th>Frequency</th><th>Owner</th><th></th></tr></thead>
      <tbody id="rows"></tbody></table></div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('kri',
  'Which indicators are breaching or trending the wrong way?');

let ROWS = [], RAGF = '';

async function load(){
  const m = await cfoMeta();
  document.getElementById('btnNew').classList.toggle('hidden', !m.can_sign);
  const { data } = await apiGet(CFO_API, { area:'kri' });
  ROWS = data.rows || [];
  const r = data.rag, tot = data.totals.total;
  cfoStrip('strip', [
    ['📈','Indicators', cfoNum(tot), 'KPIs and KRIs in the pack'],
    ['🟢','Green', cfoNum(r.green), cfoPct(r.green, tot) + ' on target', 'good'],
    ['🟡','Amber', cfoNum(r.amber), cfoPct(r.amber, tot) + ' at tolerance', 'warn'],
    ['🔴','Red', cfoNum(r.red), cfoPct(r.red, tot) + ' breaching', +r.red ? 'bad' : 'good'],
    ['⚪','No data', cfoNum(r.no_data), 'never captured — a gap in itself', +r.no_data ? 'warn' : 'good'],
  ]);
  document.getElementById('chips').innerHTML = [['','All'],['green','Green'],['amber','Amber'],['red','Red'],['no_data','No data']]
    .map(([k,l]) => `<span class="cchip ${RAGF===k?'on':''}" onclick="setRag('${k}')">${l}${k?' ('+r[k]+')':''}</span>`).join('');
  render();
}

function setRag(k){ RAGF = k; render(); }

function spark(trend, dir){
  if (!trend || trend.length < 2) return '<span class="text-gray-300 text-[10px]">—</span>';
  const vals = trend.map(t => +t.value);
  const min = Math.min(...vals), max = Math.max(...vals), span = (max - min) || 1;
  const pts = vals.map((v,i) => `${(i/(vals.length-1))*54},${16 - ((v-min)/span)*14}`).join(' ');
  const better = dir === 'higher_better' ? vals[vals.length-1] >= vals[0] : vals[vals.length-1] <= vals[0];
  return `<svg width="56" height="18" viewBox="0 0 56 18"><polyline points="${pts}" fill="none"
    stroke="${better ? '#047857' : '#b91c1c'}" stroke-width="1.5"/></svg>
    <div class="text-[9px] text-gray-400">${trend.length} periods</div>`;
}

function render(){
  const kind = document.getElementById('fKind').value, q = document.getElementById('q').value.toLowerCase();
  const rows = ROWS.filter(r => (!RAGF || r.rag === RAGF) && (!kind || r.indicator_kind === kind)
    && (!q || (r.code + ' ' + r.name).toLowerCase().includes(q)));
  const unit = r => r.unit === 'pct' ? '%' : (r.unit === 'inr' ? ' INR' : (r.unit === 'days' ? ' d' : ''));
  document.getElementById('rows').innerHTML = rows.map(r => `
    <tr class="hover:bg-gray-50">
      <td>${cfoCode(r.code)}</td>
      <td class="text-[11px] font-medium">${esc(r.name)}
        ${r.kpa_title ? `<div class="text-[9px] text-gray-400">from KPI library: ${esc(r.kpa_title)}</div>` : ''}</td>
      <td class="text-[10px]">${badge(r.indicator_kind.toUpperCase(), r.indicator_kind === 'kpi' ? 'blue' : 'purple')}</td>
      <td class="text-[11px]">${esc(r.target_value)}${unit(r)}
        <div class="text-[9px] text-gray-400">${r.direction === 'higher_better' ? 'higher is better' : 'lower is better'}</div></td>
      <td class="text-[10px] text-gray-600">green ${r.direction==='higher_better'?'≥':'≤'} ${esc(r.green_from)}<br>
        amber ${r.direction==='higher_better'?'≥':'≤'} ${esc(r.amber_from)}</td>
      <td class="text-[11px]">${r.last_value == null ? '<span class="text-gray-300">—</span>'
          : `<b>${esc(r.last_value)}</b>${unit(r)}<div class="text-[9px] text-gray-400">${esc(r.last_period||'')}</div>`}</td>
      <td>${cfoRag(r.rag)}</td>
      <td>${spark(r.trend, r.direction)}</td>
      <td class="text-[10px]">${esc(r.frequency)}</td>
      <td class="text-[10px]">${esc(r.owner_role||'—')}</td>
      <td class="rowact">${CFO_META.can_write ? `
        <button onclick="capture(${r.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Capture</button>` : ''}
        ${CFO_META.can_sign ? `<button onclick="openKri(${r.id})" class="text-[10px] bg-gray-100 px-2 py-0.5 rounded ml-1">Edit</button>` : ''}
      </td></tr>`).join('') || '<tr><td colspan="11" class="text-center text-gray-400 py-6">No indicators match.</td></tr>';
}

function capture(id){
  const r = ROWS.find(x => +x.id === +id);
  const per = r.frequency === 'daily' ? new Date().toISOString().slice(0,10)
            : r.frequency === 'quarterly' ? new Date().getFullYear() + '-Q' + (Math.floor(new Date().getMonth()/3)+1)
            : new Date().toISOString().slice(0,7);
  openModal(`<form onsubmit="saveReading(event, ${id})" class="space-y-2 text-xs">
    <div class="text-[11px] text-gray-600">${esc(r.name)} — target ${esc(r.target_value)}
      (${r.direction === 'higher_better' ? 'higher is better' : 'lower is better'}); green
      ${r.direction==='higher_better'?'≥':'≤'} ${esc(r.green_from)}, amber ${r.direction==='higher_better'?'≥':'≤'} ${esc(r.amber_from)}.</div>
    <label class="block">Period<input name="period" value="${per}" required class="ctl w-full mt-0.5"></label>
    <label class="block">Value<input name="value" type="number" step="any" required class="ctl w-full mt-0.5"></label>
    <label class="block">Note<textarea name="note" rows="2" class="ctl w-full mt-0.5" style="height:auto"></textarea></label>
    <div class="text-[10px] text-amber-700">If the reading is red, a corrective action is raised automatically and
      assigned to ${esc(r.owner_role || 'the owner role')}.</div>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Save reading</button></div></form>`, 'Capture ' + r.code);
}

async function saveReading(e, id){
  e.preventDefault(); const f = e.target;
  const res = await apiSend(`${CFO_API}?action=kri_reading`,
    { kri_id: id, period: f.period.value, value: f.value.value, note: f.note.value });
  if (res.success){
    toast('Recorded as ' + String(res.data.rag).toUpperCase(), res.data.rag !== 'red');
    closeModal(); load();
  } else toast(res.error || 'Failed', false);
}

function openKri(id){
  const r = id ? ROWS.find(x => +x.id === +id) : null;
  const goals = (CFO_META.kpa_goals || []).map(g =>
    `<option value="${g.id}" ${r && +r.kpa_goal_id===+g.id?'selected':''}>${esc(g.kpi_ref||'')} ${esc(g.title)}</option>`).join('');
  openModal(`<form onsubmit="saveKri(event, ${id || 'null'})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block">Code<input name="code" required value="${esc(r?r.code:'')}" class="ctl w-full mt-0.5" ${r?'readonly':''}></label>
    <label class="block">Kind<select name="indicator_kind" class="ctl w-full mt-0.5">
      <option value="kri" ${r&&r.indicator_kind==='kri'?'selected':''}>KRI — risk indicator</option>
      <option value="kpi" ${r&&r.indicator_kind==='kpi'?'selected':''}>KPI — performance indicator</option></select></label>
    <label class="block col-span-2">Name<input name="name" required value="${esc(r?r.name:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Unit<select name="unit" class="ctl w-full mt-0.5">
      ${['pct','count','inr','days','ratio'].map(u => `<option ${r&&r.unit===u?'selected':''}>${u}</option>`).join('')}</select></label>
    <label class="block">Direction<select name="direction" class="ctl w-full mt-0.5">
      <option value="higher_better" ${r&&r.direction==='higher_better'?'selected':''}>Higher is better</option>
      <option value="lower_better" ${r&&r.direction==='lower_better'?'selected':''}>Lower is better</option></select></label>
    <label class="block">Target<input name="target_value" type="number" step="any" value="${esc(r?r.target_value:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Frequency<select name="frequency" class="ctl w-full mt-0.5">
      ${['daily','weekly','monthly','quarterly'].map(f => `<option ${r&&r.frequency===f?'selected':''}>${f}</option>`).join('')}</select></label>
    <label class="block">Green from<input name="green_from" type="number" step="any" value="${esc(r?r.green_from:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Amber from<input name="amber_from" type="number" step="any" value="${esc(r?r.amber_from:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Owner role<input name="owner_role" value="${esc(r?r.owner_role:'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Linked KPI from the library<select name="kpa_goal_id" class="ctl w-full mt-0.5">
      <option value="">—</option>${goals}</select></label>
    <div class="col-span-2 text-[10px] text-gray-500">Linking to the KPI library ties this cockpit indicator to the same
      KRA/KPI tree performance appraisals use, so finance is not measured twice against different numbers.</div>
    <div class="col-span-2 flex justify-end gap-2 pt-1">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">${r ? 'Save' : 'Create'}</button></div>
  </form>`, r ? 'Edit ' + r.code : 'New indicator');
}

async function saveKri(e, id){
  e.preventDefault(); const f = e.target, body = { id: id || undefined };
  [...f.elements].forEach(el => { if (el.name) body[el.name] = el.value; });
  const res = await apiSend(`${CFO_API}?action=kri_save`, body);
  if (res.success){ toast('Saved'); closeModal(); load(); } else toast(res.error || 'Failed', false);
}

load();
</script>
</html>
