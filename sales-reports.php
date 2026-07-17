<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Reports');
sales_header($me, 'Sales · Reports & Analytics');
sales_nav('reports');
?>
<main class="p-2 space-y-2">

  <!-- headline KPIs (compact strip) -->
  <div id="kpis" class="kstrip"></div>

  <!-- AI read of the analytics -->
  <div data-ai-scope="dashboard"></div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Acquisition Funnel</div>
      <div style="height:200px"><canvas id="cFunnel"></canvas></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Monthly Trend (6 mo)</div>
      <div style="height:200px"><canvas id="cMonthly"></canvas></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Source ROI — Leads vs Activated</div>
      <div style="height:200px"><canvas id="cSource"></canvas></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Pipeline Value by Stage</div>
      <div style="height:200px"><canvas id="cStage"></canvas></div>
    </div>
    <div class="card p-3 lg:col-span-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Agent Performance — Merchants vs Incentive</div>
      <div style="height:240px"><canvas id="cAgents"></canvas></div>
    </div>
  </div>

  <!-- ===== data-driven analytics ===== -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card p-3 lg:col-span-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-trophy text-amber-500"></i> GMV &amp; Fee-Income Leaderboard <span class="normal-case font-normal text-[10px] text-gray-400">— from live transactions</span></div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr><th>Rep</th><th class="text-right">GMV</th><th class="text-right">Fee income</th><th class="text-right">Active merchants</th></tr></thead><tbody id="gmvLb"></tbody></table></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-triangle-exclamation text-red-500"></i> Dormant Merchants <span class="normal-case font-normal text-[10px] text-gray-400">— 30+ days idle</span></div>
      <div class="overflow-x-auto max-h-56 overflow-y-auto"><table class="dense"><thead><tr><th>Merchant</th><th>Owner</th><th class="text-right">Idle</th></tr></thead><tbody id="dormant"></tbody></table></div>
    </div>
  </div>
</main>

<?php sales_scripts(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const IND = '#6366f1', GRN = '#10b981', YEL = '#f59e0b', BLU = '#3b82f6', RED = '#ef4444';
const BASE = { responsive: true, maintainAspectRatio: false };

async function loadReports() {
  const { data: r } = await apiGet('reports.php');

  const liab = r.incentive_liability ? (+r.incentive_liability.accrued + +r.incentive_liability.approved) : 0;
  const tc = r.timesheet_compliance || {};
  const tsTotal = (+tc.submitted||0) + (+tc.approved||0) + (+tc.draft||0);
  document.getElementById('kpis').innerHTML = [
    ['Win Rate', r.win_rate.rate_pct + '%'],
    ['Avg Days to Activate', r.cycle.avg_days_to_activate],
    ['Merchants Live', r.funnel.activated],
    ['Total Leads', r.funnel.leads],
    ['Incentive Liability', inr(liab)],
    ['TS Submitted (wk)', (+tc.submitted||0) + ' / ' + tsTotal],
  ].map(([l, v]) => `<div><div class="kv">${v}</div><div class="kl">${l}</div></div>`).join('');

  // GMV & fee-income leaderboard
  document.getElementById('gmvLb').innerHTML = (r.gmv_leaderboard || []).map(x => `
    <tr><td class="font-medium">${esc(x.full_name)}</td>
      <td class="text-right">${inr(x.gmv)}</td>
      <td class="text-right text-gray-600">${inr(x.fee)}</td>
      <td class="text-right">${x.active_merchants}</td></tr>`).join('')
    || '<tr><td colspan="4" class="text-center text-gray-400 py-2">No transaction data yet</td></tr>';
  // dormant merchants
  document.getElementById('dormant').innerHTML = (r.dormant || []).map(d => `
    <tr><td class="font-medium">${esc(d.legal_name || d.merchant_code)}</td>
      <td class="text-gray-500">${esc(d.owner || '—')}</td>
      <td class="text-right ${d.days_idle > 60 ? 'text-red-600' : 'text-amber-600'}">${d.days_idle == null ? '—' : d.days_idle + 'd'}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-center text-gray-400 py-2">No dormant merchants 🎉</td></tr>';

  const f = r.funnel;
  new Chart(cFunnel, { type: 'bar', data: {
      labels: ['Leads','Qualified','Deals','Won','Activated'],
      datasets: [{ label: 'Count', data: [f.leads,f.qualified,f.deals,f.won,f.activated],
        backgroundColor: [IND,'#818cf8',BLU,YEL,GRN] }] },
    options: { ...BASE, indexAxis: 'y', plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });

  new Chart(cMonthly, { type: 'line', data: {
      labels: r.monthly.map(m => m.ym),
      datasets: [
        { label: 'New Leads', data: r.monthly.map(m => +m.leads), borderColor: IND, backgroundColor: IND, tension: .3 },
        { label: 'Activated', data: r.monthly.map(m => +m.activated), borderColor: GRN, backgroundColor: GRN, tension: .3 },
      ] }, options: { ...BASE, plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });

  new Chart(cSource, { type: 'bar', data: {
      labels: r.source_roi.map(s => s.source),
      datasets: [
        { label: 'Leads', data: r.source_roi.map(s => +s.leads), backgroundColor: BLU },
        { label: 'Activated', data: r.source_roi.map(s => +s.activated), backgroundColor: GRN },
      ] }, options: { ...BASE, plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });

  new Chart(cStage, { type: 'bar', data: {
      labels: r.stage_value.map(s => s.name),
      datasets: [{ label: 'Value (₹)', data: r.stage_value.map(s => +s.value),
        backgroundColor: r.stage_value.map(s => s.stage_type==='won'?GRN:s.stage_type==='lost'?RED:IND) }] },
    options: { ...BASE, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });

  // Agent performance — merchants (bars, left axis) + incentive ₹ (line, right axis)
  new Chart(cAgents, { type: 'bar', data: {
      labels: r.agents.map(a => a.full_name),
      datasets: [
        { type: 'bar', label: 'Merchants', data: r.agents.map(a => +a.merchants),
          backgroundColor: IND, yAxisID: 'yM', order: 2, barPercentage: .5 },
        { type: 'line', label: 'Incentive ₹', data: r.agents.map(a => +a.incentive),
          borderColor: GRN, backgroundColor: GRN, yAxisID: 'yI', order: 1,
          tension: .3, pointRadius: 4, pointBackgroundColor: GRN, fill: false },
      ] },
    options: { ...BASE, plugins: { legend: { position: 'bottom' } },
      scales: {
        yM: { type: 'linear', position: 'left', beginAtZero: true, ticks: { precision: 0 },
              title: { display: true, text: 'Merchants' } },
        yI: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false },
              title: { display: true, text: 'Incentive ₹' } },
      } } });
}
loadReports();
</script>
</html>
