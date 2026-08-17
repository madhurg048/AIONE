<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// The Service dept has its own dashboard (chats/calls/WhatsApp/tickets, not the sales funnel).
if (in_array(sales_role(), ['service_exec', 'service_manager'], true)) {
    header('Location: sales-dashboard-service.php');
    exit;
}
sales_head('Dashboard');
sales_header($me, 'Executive Dashboard');
sales_nav('dashboard');
?>
<main class="p-2 space-y-2">

  <!-- my role-level sales goal (scorecard — management view; self-hides for reps) -->
  <?php sales_goal_widget(); ?>
  <!-- my KPA/KRA goals (what HR / my manager set — this is what a rep sees) -->
  <?php sales_my_goals_widget(); ?>

  <!-- system-wide KPI strip -->
  <div id="kpis" class="kstrip"></div>

  <!-- ===== Governance: SLA misses, high TAT, escalations, approvals, compliance (admin/managers) ===== -->
  <?php if (payg_role_can(sales_role(), 'records_all') || sales_role() === 'admin'): ?>
  <div id="govPanel" class="hidden space-y-2">
    <div id="govKpis" class="kstrip"></div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
      <div class="card">
        <div class="p-2 border-b flex items-center justify-between">
          <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-user-clock text-red-500"></i> SLA not met — by employee</span>
          <a href="sales-escalations.php" class="text-[11px] text-indigo-600 hover:underline">Escalations →</a>
        </div>
        <table class="dense"><thead><tr><th>Employee</th><th class="text-right">Breached</th><th class="text-right">Worst overdue</th></tr></thead>
        <tbody id="govSlaMissed"></tbody></table>
      </div>
      <div class="card">
        <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-gauge-simple-high text-amber-500"></i> High TAT — by task type</div>
        <table class="dense"><thead><tr><th>Type</th><th class="text-right">Open</th><th class="text-right">Avg age</th><th class="text-right">Breached</th></tr></thead>
        <tbody id="govHighTat"></tbody></table>
      </div>
      <div class="card">
        <div class="p-2 border-b flex items-center justify-between">
          <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-shield-halved text-indigo-500"></i> Compliance — renewals next 6 months</span>
          <a href="sales-compliance.php" class="text-[11px] text-indigo-600 hover:underline">Registry →</a>
        </div>
        <table class="dense"><thead><tr><th>Item</th><th>Owner</th><th class="text-right">Due</th></tr></thead>
        <tbody id="govUpcoming"></tbody></table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ===== end-to-end conversion flow: Lead → Conversion → Merchant ===== -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-arrow-trend-up text-indigo-500"></i> Conversion Flow — Lead → Conversion → Merchant</div>
      <div class="text-[11px] text-gray-500">Lead→Live merchant: <span id="flowOverall" class="font-bold text-indigo-700"></span></div>
    </div>
    <div id="flowStages" class="flex items-stretch gap-0.5 overflow-x-auto pb-1"></div>
    <div id="flowPhases" class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-3"></div>
    <div class="mt-2 text-[10px] text-gray-400">Source ROI (leads → live merchants): <span id="flowSources"></span></div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- funnel -->
    <div class="card p-3 lg:col-span-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Acquisition Funnel</div>
      <div id="funnel" class="space-y-1.5"></div>
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mt-3 mb-1">6-Month Trend</div>
      <div style="height:150px"><canvas id="cTrend"></canvas></div>
    </div>
    <!-- AI insight -->
    <div data-ai-scope="dashboard"></div>
  </div>

  <!-- module summary cards -->
  <div id="modules" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2"></div>

  <!-- admin/manager-configurable KPIs -->
  <div id="customKpis"></div>

  <!-- live feeds -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Recent Transactions</div>
      <table class="dense"><tbody id="txns"></tbody></table>
    </div>
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">ITSM Tickets</div>
      <table class="dense"><tbody id="tickets"></tbody></table>
    </div>
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Top Agents</div>
      <table class="dense"><tbody id="agents"></tbody></table>
    </div>
  </div>
</main>

<?php sales_scripts(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="js/dashboard-kpis.js"></script>
<script>
const IND='#6366f1', GRN='#10b981';

/* ===== Governance panel (admin/managers — panel div absent for others) ===== */
async function loadGovernance(){
  const panel = document.getElementById('govPanel');
  if (!panel) return;
  try {
    const { data:g } = await apiGet('governance-stats.php', {});
    const s=g.sla||{}, e=g.escalations||{}, c=g.compliance||{};
    document.getElementById('govKpis').innerHTML = [
      ['SLA breached (open)', s.breached_open||0, (s.breached_open||0)>0],
      ['At risk < 4h', s.at_risk_4h||0, (s.at_risk_4h||0)>0],
      ['Escalations open', e.open_now||0, (e.open_now||0)>0],
      ['At L2+ (near admin)', e.at_level2_plus||0, (e.at_level2_plus||0)>0],
      ['Policies & certs', c.total||0],
      ['Renewals in 6 months', c.renewals_6m||0, (c.renewals_30d||0)>0],
      ['EXPIRED', c.expired||0, (c.expired||0)>0],
    ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
    document.getElementById('govSlaMissed').innerHTML = (g.sla_missed||[]).map(r=>`
      <tr><td class="font-medium">${esc(r.full_name||('#'+r.assigned_to))}<div class="text-[10px] text-gray-400">${esc(r.designation||'')}</div></td>
      <td class="text-right font-semibold text-red-600">${r.breached}</td>
      <td class="text-right">${r.worst_overdue_h} h</td></tr>`).join('')
      || '<tr><td colspan="3" class="text-gray-400 text-center py-2">Everyone within SLA 🎉</td></tr>';
    document.getElementById('govHighTat').innerHTML = (g.high_tat||[]).map(r=>`
      <tr><td>${badge(r.task_type,'gray')}</td><td class="text-right">${r.open_cnt}</td>
      <td class="text-right ${(+r.avg_age_h)>48?'text-red-600 font-semibold':''}">${r.avg_age_h} h</td>
      <td class="text-right ${(+r.breached)>0?'text-red-600':''}">${r.breached}</td></tr>`).join('')
      || '<tr><td colspan="4" class="text-gray-400 text-center py-2">—</td></tr>';
    document.getElementById('govUpcoming').innerHTML = (g.upcoming||[]).map(r=>`
      <tr><td class="font-medium max-w-[150px] truncate" title="${esc(r.title)}">${esc(r.title)}
        <div class="text-[10px] text-gray-400">${esc(r.item_type)} · ${esc(r.category||'')}</div></td>
      <td class="text-[10px]">${esc(r.owner_group_name||r.owner_name||'—')}</td>
      <td class="text-right ${r.days_left<0?'text-red-600 font-bold':r.days_left<30?'text-amber-600 font-semibold':''}">${r.days_left<0?Math.abs(r.days_left)+'d overdue':r.days_left+'d'}</td></tr>`).join('')
      || '<tr><td colspan="3" class="text-gray-400 text-center py-2">Nothing due in 6 months</td></tr>';
    panel.classList.remove('hidden');
  } catch(err){ /* keep hidden */ }
}
loadGovernance();
const PHASE_COLOR = { lead:'indigo', conversion:'amber', merchant:'green' };

async function loadFlow() {
  const { data: f } = await apiGet('flow.php');
  document.getElementById('flowOverall').textContent = f.overall_conversion + '%';

  // stage flow with conversion % between stages
  document.getElementById('flowStages').innerHTML = f.stages.map((s, i) => {
    const c = PHASE_COLOR[s.phase];
    const arrow = i > 0 ? `<div class="flex flex-col items-center justify-center px-1 shrink-0">
        <i class="fa-solid fa-caret-right text-gray-300"></i>
        <span class="text-[10px] font-bold ${s.conv < 40 ? 'text-red-500' : s.conv < 70 ? 'text-amber-600' : 'text-green-600'}">${s.conv}%</span></div>` : '';
    return arrow + `<div class="flex-1 min-w-[80px] text-center rounded-lg border-t-4 border-${c}-500 bg-${c}-50 py-2 px-1">
        <div class="text-2xl font-bold text-gray-800 leading-6">${s.count}</div>
        <div class="text-[10px] uppercase text-gray-500 tracking-wide mt-0.5">${s.label}</div></div>`;
  }).join('');

  // three phase panels
  document.getElementById('flowPhases').innerHTML = ['lead','conversion','merchant'].map(k => {
    const p = f.phases[k];
    return `<div class="border rounded-lg overflow-hidden">
      <div class="bg-${p.color}-50 border-b px-2 py-1.5 flex items-center gap-2">
        <i class="fa-solid ${p.icon} text-${p.color}-500"></i>
        <span class="text-[11px] font-semibold text-gray-700">${p.label}</span></div>
      <div class="p-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px]">
        ${p.metrics.map(m => `<div class="flex justify-between gap-1">
          <span class="text-gray-500 truncate">${m[0]}</span>
          <span class="font-semibold text-gray-800 whitespace-nowrap">${m[2]==='money'?inr(m[1]):m[1]}</span></div>`).join('')}
      </div></div>`;
  }).join('');

  document.getElementById('flowSources').innerHTML = (f.source_roi||[]).map(s =>
    `<span class="inline-block mr-2">${esc(s.source)} <b>${s.leads}→${s.activated}</b></span>`).join('') || '—';
}

async function load() {
  const { data: o } = await apiGet('overview.php');

  // KPI strip — the whole system at a glance
  document.getElementById('kpis').innerHTML = [
    ['Leads', o.funnel.leads], ['Conv. rate', o.conversion_rate + '%'],
    ['Open deals', o.pipeline.open_count], ['Pipeline', inr(o.pipeline.open_value)],
    ['Merchants live', o.merchants.activated], ['Live volume/mo', inr(o.merchants.volume_actual || o.merchants.volume_expected)],
    ['Txns today', o.transactions.today_count], ['Incentive (mo)', inr(o.incentives.this_month)],
    ['Follow-ups due', o.leads.due_followups, o.leads.due_followups>0], ['Open tickets', o.tickets.open, o.tickets.waiting_us>0],
    ['TS pending', o.timesheet.pending],
  ].map(([l,v,warn]) => `<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  // funnel bars
  const f = o.funnel;
  const steps = [['Leads',f.leads,'bg-indigo-500'],['Qualified',f.qualified,'bg-indigo-400'],['Converted',f.converted,'bg-blue-500'],['Open deals',f.open_deals,'bg-yellow-500'],['Won',f.won,'bg-teal-500'],['Merchants',f.merchants,'bg-green-500']];
  const max = Math.max(1, ...steps.map(s=>s[1]));
  document.getElementById('funnel').innerHTML = steps.map(([t,v,c])=>`
    <div class="flex items-center gap-2">
      <span class="text-[10px] w-20 text-gray-500">${t}</span>
      <div class="flex-1 h-4 bg-gray-100 rounded"><div class="${c} h-4 rounded" style="width:${Math.max(4,Math.round(v*100/max))}%"></div></div>
      <span class="text-[11px] font-semibold w-8 text-right">${v}</span></div>`).join('');

  // module cards
  const mods = [
    ['Leads','fa-user-plus','indigo','sales-leads.php',[[o.leads.total,'total'],[o.leads.due_followups,'due']]],
    ['Pipeline','fa-diagram-project','blue','sales-pipeline.php',[[o.pipeline.open_count,'open'],[inr(o.pipeline.open_value),'value']]],
    ['Merchants','fa-store','green','sales-merchants.php',[[o.merchants.activated,'live'],[o.merchants.kyc_pending,'KYC due']]],
    ['Incentives','fa-sack-dollar','yellow','sales-incentives.php',[[inr(o.incentives.this_month),'this mo'],[inr(o.incentives.pending_approval),'to approve']]],
    ['Transactions','fa-receipt','teal','sales-transactions.php',[[inr(o.transactions.month_value),'this mo'],[o.transactions.today_count,'today']]],
    ['Tickets','fa-ticket','red','sales-tickets.php',[[o.tickets.open,'open'],[o.tickets.waiting_us,'on us']]],
  ];
  // Only surface cards the current role can actually open — a click must always land on
  // real data, never bounce back. SALES_PAGES is the RBAC-filtered page list for this user.
  const CAN_OPEN = new Set((window.SALES_PAGES||[]).map(p => (p.href||'').split('?')[0]));
  document.getElementById('modules').innerHTML = mods
    .filter(([t,i,c,href]) => CAN_OPEN.size === 0 || CAN_OPEN.has(href))
    .map(([t,i,c,href,stats])=>`
    <a href="${href}" class="card p-2 kpi-card block">
      <div class="flex items-center gap-2 mb-1"><i class="fa-solid ${i} text-${c}-500"></i><span class="text-xs font-semibold">${t}</span></div>
      <div class="flex justify-between text-[11px]">${stats.map(([v,l])=>`<div><div class="font-bold text-gray-800">${v}</div><div class="text-gray-400 text-[10px]">${l}</div></div>`).join('')}</div>
    </a>`).join('');

  // recent transactions
  document.getElementById('txns').innerHTML = (o.recent_transactions||[]).map(t=>`
    <tr><td class="text-[11px]">${esc(t.legal_name||'—')}</td><td class="font-mono text-[10px] text-gray-400">${esc(t.txn_ref)}</td>
    <td class="text-right font-semibold">${inr(t.amount)}</td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No transactions yet</td></tr>';

  document.getElementById('tickets').innerHTML = (o.recent_tickets||[]).map(t=>`
    <tr><td class="font-mono text-[10px]">${esc(t.ticket_ref)}</td><td class="text-[11px]">${esc((t.subject||'').slice(0,22))}</td>
    <td>${badge(t.status||'—', t.status==='closed'?'green':t.waiting_on==='us'?'red':'yellow')}</td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No tickets yet</td></tr>';

  document.getElementById('agents').innerHTML = (o.top_agents||[]).map(a=>`
    <tr><td class="text-[11px] font-medium">${esc(a.full_name)}</td><td class="text-center text-[11px]">${a.merchants} m</td>
    <td class="text-right font-semibold text-[11px]">${inr(a.incentive)}</td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No agents yet</td></tr>';

  // trend chart
  new Chart(cTrend, { type:'line', data:{ labels:(o.monthly||[]).map(m=>m.ym),
    datasets:[{label:'Leads',data:(o.monthly||[]).map(m=>+m.leads),borderColor:IND,backgroundColor:IND,tension:.3},
              {label:'Activated',data:(o.monthly||[]).map(m=>+m.activated),borderColor:GRN,backgroundColor:GRN,tension:.3}] },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:9}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}} });
}
load();
loadFlow();
initCustomKpis('sales', document.getElementById('customKpis'));
</script>
</html>
