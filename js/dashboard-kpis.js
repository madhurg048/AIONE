/* ==========================================================================
 * Configurable dashboard KPIs (shared by sales-dashboard.php and
 * sales-dashboard-service.php).
 *
 *   initCustomKpis('service', document.getElementById('customKpis'))
 *
 * Renders the admin/department-manager-defined KPIs (api/sales/dashboard-kpis.php)
 * as tiles / trend charts / vs-target donuts, plus a "Manage KPIs" builder modal
 * for roles allowed to define them (admin any dept; a manager their own dept).
 * Requires js/sales.js (apiGet/apiSend/toast/esc/inr) and Chart.js.
 * ========================================================================== */

let _dkCharts = [];
let _dkVertical = null, _dkContainer = null, _dkCatalog = [], _dkEditing = null;

function dkFmt(v, unit) {
  v = +v || 0;
  if (unit === 'inr') return inr(v);
  if (unit === 'pct') return v + '%';
  if (unit === 'sec') return v >= 90 ? Math.round(v / 60) + 'm' : Math.round(v) + 's';
  return Math.abs(v) >= 1000 ? v.toLocaleString('en-IN') : String(Math.round(v * 100) / 100);
}

/* attainment 0..1+ against target, honouring direction (lower-is-better inverts) */
function dkAttain(k) {
  if (k.target_value === null || k.target_value === undefined) return null;
  const t = +k.target_value, v = +k.value;
  if (k.direction === 'lower') return v <= t ? 1 : (v > 0 ? t / v : 1);
  return t > 0 ? v / t : 1;
}
function dkStatusColor(a) {
  if (a === null) return 'gray';
  return a >= 1 ? 'green' : a >= 0.7 ? 'yellow' : 'red';
}
const DK_PERIOD_LABEL = { today: 'today', '7d': 'last 7 days', month: 'this month', quarter: 'this quarter', all: 'all time' };

async function initCustomKpis(vertical, container) {
  _dkVertical = vertical; _dkContainer = container;
  await renderCustomKpis();
}

async function renderCustomKpis() {
  const res = await apiGet('dashboard-kpis.php', { action: 'list', vertical: _dkVertical });
  if (!res.success) { _dkContainer.innerHTML = ''; return; }
  const { kpis, can_manage } = res.data;
  if (!kpis.length && !can_manage) { _dkContainer.innerHTML = ''; return; }

  _dkCharts.forEach(c => { try { c.destroy(); } catch (e) {} });
  _dkCharts = [];

  const manageBtn = can_manage
    ? `<button onclick="dkOpenManage()" class="text-[11px] text-indigo-600 hover:underline"><i class="fa-solid fa-sliders"></i> Manage KPIs</button>`
    : '';
  const body = kpis.length
    ? `<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">${kpis.map(dkItemHtml).join('')}</div>`
    : `<div class="text-[11px] text-gray-400 py-3 text-center">No custom KPIs defined yet — click "Manage KPIs" to add the metrics your team tracks.</div>`;

  _dkContainer.innerHTML = `
    <div class="card p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
          <i class="fa-solid fa-crosshairs text-indigo-500"></i> Team KPIs — set by admin / manager</div>
        ${manageBtn}
      </div>
      ${body}
    </div>`;

  // instantiate charts after the canvases exist
  kpis.forEach(k => {
    if (k.display === 'bar' || k.display === 'line') dkTrendChart(k);
    else if (k.display === 'donut') dkDonutChart(k);
  });
}

function dkItemHtml(k) {
  const a = dkAttain(k), col = dkStatusColor(a);
  const per = DK_PERIOD_LABEL[k.period] || k.period;
  const tgt = k.target_value !== null
    ? `<div class="text-[10px] text-gray-400">target ${dkFmt(k.target_value, k.unit)} · <span class="text-${col === 'yellow' ? 'amber' : col}-600 font-semibold">${a === null ? '' : Math.round(a * 100) + '%'}</span></div>`
    : `<div class="text-[10px] text-gray-400">${esc(k.metric_label)}</div>`;

  if (k.display === 'kpi') {
    const bar = k.target_value !== null
      ? `<div class="w-full h-1.5 bg-gray-100 rounded mt-1"><div class="h-1.5 rounded bg-${col === 'yellow' ? 'amber' : col}-500" style="width:${Math.min(100, Math.max(3, Math.round((a || 0) * 100)))}%"></div></div>`
      : '';
    return `<div class="border rounded-lg p-2">
      <div class="text-[10px] uppercase tracking-wide text-gray-500 truncate" title="${esc(k.title)}">${esc(k.title)} <span class="normal-case">· ${per}</span></div>
      <div class="text-xl font-bold text-gray-800 leading-6">${dkFmt(k.value, k.unit)}</div>
      ${tgt}${bar}</div>`;
  }
  // chart items (bar / line / donut)
  return `<div class="border rounded-lg p-2">
    <div class="text-[10px] uppercase tracking-wide text-gray-500 truncate" title="${esc(k.title)}">${esc(k.title)} <span class="normal-case">· ${per}</span></div>
    <div class="flex items-baseline gap-2"><span class="text-sm font-bold text-gray-800">${dkFmt(k.value, k.unit)}</span>${tgt}</div>
    <div style="height:110px"><canvas id="dkC${k.id}"></canvas></div></div>`;
}

function dkTrendChart(k) {
  const el = document.getElementById('dkC' + k.id);
  if (!el || !k.series) return;
  _dkCharts.push(new Chart(el, {
    type: k.display,
    data: { labels: k.series.map(s => s.ym), datasets: [{
      label: k.title, data: k.series.map(s => +s.v),
      backgroundColor: '#6366f1', borderColor: '#6366f1', tension: .3 }] },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0, font: { size: 9 } } },
                x: { ticks: { font: { size: 9 } } } } }
  }));
}

function dkDonutChart(k) {
  const el = document.getElementById('dkC' + k.id);
  if (!el) return;
  const a = dkAttain(k);
  const done = a === null ? 1 : Math.min(1, a);
  const col = { green: '#10b981', yellow: '#f59e0b', red: '#ef4444', gray: '#6366f1' }[dkStatusColor(a)];
  _dkCharts.push(new Chart(el, {
    type: 'doughnut',
    data: { labels: ['Achieved', 'Remaining'], datasets: [{
      data: [Math.round(done * 100), Math.max(0, 100 - Math.round(done * 100))],
      backgroundColor: [col, '#f1f5f9'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '70%',
      plugins: { legend: { display: false } } }
  }));
}

/* ===================== Manage (builder) modal ===================== */

async function dkOpenManage() {
  if (!document.getElementById('dkModal')) dkBuildModal();
  const cat = await apiGet('dashboard-kpis.php', { action: 'catalog', vertical: _dkVertical });
  _dkCatalog = (cat.data && cat.data.metrics) || [];
  const sel = document.getElementById('dkfMetric');
  sel.innerHTML = '<option value="">Metric…</option>' + _dkCatalog.map(m =>
    `<option value="${m.key}" data-snapshot="${m.snapshot ? 1 : 0}">${esc(m.label)}</option>`).join('');
  dkResetForm();
  await dkRenderManageList();
  document.getElementById('dkModal').classList.remove('hidden');
  document.getElementById('dkModal').classList.add('flex');
}
function dkCloseManage() {
  const m = document.getElementById('dkModal');
  m.classList.add('hidden'); m.classList.remove('flex');
  renderCustomKpis();                      // reflect any edits on the dashboard
}

let _dkRows = {};
async function dkRenderManageList() {
  const res = await apiGet('dashboard-kpis.php', { action: 'manage', vertical: _dkVertical });
  const rows = (res.data && res.data.kpis) || [];
  _dkRows = {};
  rows.forEach(k => { _dkRows[k.id] = k; });
  document.getElementById('dkList').innerHTML = rows.length ? rows.map(k => `
    <tr class="${k.is_active ? '' : 'opacity-50'}">
      <td class="text-[11px] font-medium">${esc(k.title)}</td>
      <td class="text-[10px] text-gray-500">${esc(k.metric_label)}</td>
      <td class="text-[10px]">${k.display} · ${DK_PERIOD_LABEL[k.period] || k.period}</td>
      <td class="text-[10px] text-right">${k.target_value !== null ? dkFmt(k.target_value, k.unit) : '—'}</td>
      <td class="text-right whitespace-nowrap">
        <button onclick="dkEdit(${k.id})" class="text-indigo-600 hover:underline text-[10px] mr-2"><i class="fa-solid fa-pen"></i></button>
        <button onclick="dkToggle(${k.id}, ${k.is_active ? 0 : 1})" class="text-gray-500 hover:underline text-[10px] mr-2" title="${k.is_active ? 'Deactivate' : 'Activate'}"><i class="fa-solid ${k.is_active ? 'fa-eye-slash' : 'fa-eye'}"></i></button>
        <button onclick="dkDelete(${k.id})" class="text-red-500 hover:underline text-[10px]"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>`).join('')
    : '<tr><td colspan="5" class="text-center text-gray-400 text-[11px] py-3">No KPIs yet — add one below.</td></tr>';
}

function dkEdit(id) {
  const k = _dkRows[id];
  if (!k) return;
  _dkEditing = k.id;
  const f = document.getElementById('dkForm');
  f.title.value = k.title;
  f.metric_key.value = k.metric_key;
  f.display.value = k.display;
  f.period.value = k.period;
  f.target_value.value = k.target_value === null ? '' : k.target_value;
  f.direction.value = k.direction;
  f.sort_order.value = k.sort_order;
  document.getElementById('dkFormTitle').textContent = 'Edit KPI';
  document.getElementById('dkSubmitBtn').textContent = 'Save changes';
  dkSyncDisplayOptions();
}
function dkResetForm() {
  _dkEditing = null;
  document.getElementById('dkForm').reset();
  document.getElementById('dkFormTitle').textContent = 'Add KPI';
  document.getElementById('dkSubmitBtn').textContent = 'Add KPI';
  dkSyncDisplayOptions();
}
/* snapshot metrics can't render 6-month trends — disable bar/line for them */
function dkSyncDisplayOptions() {
  const sel = document.getElementById('dkfMetric');
  const opt = sel.options[sel.selectedIndex];
  const snap = opt && opt.getAttribute('data-snapshot') === '1';
  const disp = document.getElementById('dkfDisplay');
  [...disp.options].forEach(o => {
    if (o.value === 'bar' || o.value === 'line') {
      o.disabled = snap;
      if (snap && disp.value === o.value) disp.value = 'kpi';
    }
  });
}

async function dkSubmit(ev) {
  ev.preventDefault();
  const f = ev.target;
  const payload = {
    vertical: _dkVertical,
    title: f.title.value.trim(),
    metric_key: f.metric_key.value,
    display: f.display.value,
    period: f.period.value,
    target_value: f.target_value.value === '' ? null : +f.target_value.value,
    direction: f.direction.value,
    sort_order: +f.sort_order.value || 0,
  };
  if (!payload.metric_key) return toast('Pick a metric', false);
  const res = _dkEditing
    ? await apiSend('dashboard-kpis.php?id=' + _dkEditing, payload, 'PATCH')
    : await apiSend('dashboard-kpis.php', payload);
  if (!res.success) return toast(res.error || 'Failed', false);
  toast(_dkEditing ? 'KPI updated' : 'KPI added');
  dkResetForm();
  await dkRenderManageList();
}
async function dkToggle(id, active) {
  const res = await apiSend('dashboard-kpis.php?id=' + id, { is_active: active }, 'PATCH');
  if (!res.success) return toast(res.error || 'Failed', false);
  await dkRenderManageList();
}
async function dkDelete(id) {
  if (!confirm('Delete this KPI from the dashboard?')) return;
  const res = await apiSend('dashboard-kpis.php?id=' + id, {}, 'DELETE');
  if (!res.success) return toast(res.error || 'Failed', false);
  toast('KPI deleted');
  await dkRenderManageList();
}

function dkBuildModal() {
  const div = document.createElement('div');
  div.id = 'dkModal';
  div.className = 'hidden fixed inset-0 bg-black/40 z-[60] items-center justify-center p-4';
  div.innerHTML = `
  <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 class="font-semibold text-gray-800"><i class="fa-solid fa-sliders text-indigo-600"></i> Manage Dashboard KPIs
        <span class="text-gray-400 font-normal text-xs">— visible to the whole ${_dkVertical === 'service' ? 'Service' : 'Sales'} team</span></h3>
      <button onclick="dkCloseManage()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="overflow-y-auto p-4 space-y-4">
      <table class="dense">
        <thead><tr><th>Title</th><th>Metric</th><th>Display</th><th class="text-right">Target</th><th></th></tr></thead>
        <tbody id="dkList"></tbody>
      </table>
      <form id="dkForm" onsubmit="dkSubmit(event)" class="border rounded-lg p-3 space-y-3 text-xs bg-gray-50">
        <div class="flex items-center justify-between">
          <div id="dkFormTitle" class="font-semibold text-gray-700">Add KPI</div>
          <button type="button" onclick="dkResetForm()" class="text-[10px] text-gray-400 hover:text-gray-600">clear</button>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <input name="title" placeholder="KPI title shown on the dashboard" class="border rounded px-2 py-1.5">
          <select name="metric_key" id="dkfMetric" required class="border rounded px-2 py-1.5" onchange="dkSyncDisplayOptions()">
            <option value="">Metric…</option>
          </select>
        </div>
        <div class="grid grid-cols-4 gap-3">
          <select name="display" id="dkfDisplay" class="border rounded px-2 py-1.5">
            <option value="kpi">KPI tile</option><option value="bar">Bar (6-mo trend)</option>
            <option value="line">Line (6-mo trend)</option><option value="donut">Donut (vs target)</option>
          </select>
          <select name="period" class="border rounded px-2 py-1.5">
            <option value="month">This month</option><option value="today">Today</option>
            <option value="7d">Last 7 days</option><option value="quarter">This quarter</option>
            <option value="all">All time</option>
          </select>
          <input name="target_value" type="number" step="any" min="0" placeholder="Target (optional)" class="border rounded px-2 py-1.5">
          <select name="direction" class="border rounded px-2 py-1.5" title="Is a higher or a lower value better?">
            <option value="higher">Higher is better</option><option value="lower">Lower is better</option>
          </select>
        </div>
        <div class="flex items-center justify-between">
          <input name="sort_order" type="number" step="1" value="0" class="border rounded px-2 py-1.5 w-24" title="Sort order">
          <button id="dkSubmitBtn" class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">Add KPI</button>
        </div>
      </form>
    </div>
  </div>`;
  document.body.appendChild(div);
}
