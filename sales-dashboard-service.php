<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// Sales-only roles have their own dashboard — mirror of the service-roles redirect there.
if (in_array(sales_role(), ['sales_exec', 'manager'], true)) {
    header('Location: sales-dashboard.php');
    exit;
}
sales_head('Dashboard');
sales_header($me, 'Service Dashboard');
sales_nav('dashboard');
$adminVertical = sales_role() === 'admin' ? '?vertical=service' : '';
?>
<main class="p-2 space-y-2">

  <!-- my role-level goal -->
  <?php sales_goal_widget(); ?>

  <!-- system-wide KPI strip -->
  <div id="kpis" class="kstrip"></div>

  <!-- ===== end-to-end service flow: Incoming → Handled → Ticket → Resolved ===== -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-headset text-indigo-500"></i> Service Flow — Incoming → Handled → Ticket → Resolved (this month)</div>
      <div class="text-[11px] text-gray-500">Incoming→Resolved: <span id="flowOverall" class="font-bold text-indigo-700"></span></div>
    </div>
    <div id="flowStages" class="flex items-stretch gap-0.5 overflow-x-auto pb-1"></div>
    <div id="flowPhases" class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-3"></div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- channel volume + trend -->
    <div class="card p-3 lg:col-span-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Volume by Channel — Last 7 Days</div>
      <div style="height:150px"><canvas id="cWeekly"></canvas></div>
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mt-3 mb-1">6-Month Trend</div>
      <div style="height:150px"><canvas id="cTrend"></canvas></div>
    </div>
    <!-- AI insight -->
    <div data-ai-scope="service_dashboard"></div>
  </div>

  <!-- module summary cards -->
  <div id="modules" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2"></div>

  <!-- channel / outcome / ticket distribution -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Lead Channel Mix — Last 30 Days</div>
      <div style="height:170px"><canvas id="cChannel"></canvas></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Chat Outcomes — This Month</div>
      <div style="height:170px"><canvas id="cOutcomes"></canvas></div>
    </div>
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Tickets by Assist Type — This Month</div>
      <div style="height:170px"><canvas id="cTickets"></canvas></div>
    </div>
  </div>

  <!-- admin/manager-configurable KPIs -->
  <div id="customKpis"></div>

  <!-- live feeds -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Recent Live Chats</div>
      <table class="dense"><tbody id="chats"></tbody></table>
    </div>
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Recent Transfers</div>
      <table class="dense"><tbody id="transfers"></tbody></table>
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
const IND='#6366f1', GRN='#10b981', AMB='#f59e0b', RED='#ef4444', TEAL='#14b8a6', BLU='#3b82f6';
const PHASE_COLOR = { channel:'indigo', handling:'amber', resolution:'green' };
const CHART_OPTS = { responsive:true, maintainAspectRatio:false,
  plugins:{ legend:{ position:'bottom', labels:{ boxWidth:10, font:{ size:9 } } } } };
const fmtSec = s => { s = +s||0; return s >= 90 ? Math.round(s/60) + 'm' : s + 's'; };

async function load() {
  const { data: o } = await apiGet('service-overview.php'<?= $adminVertical ? ", { vertical: 'service' }" : '' ?>);

  // KPI strip — the whole service desk at a glance
  document.getElementById('kpis').innerHTML = [
    ['Chats today', o.chats.today],
    ['Waiting now', o.chats.waiting_now, o.chats.waiting_now>0],
    ['Missed (mo)', o.chats.missed_month, o.chats.missed_month>0],
    ['Avg pickup', fmtSec(o.chats.avg_pickup_sec)],
    ['Calls today', o.calls.today],
    ['WA msgs today', o.whatsapp.msgs_today],
    ['WA unread', o.whatsapp.unread, o.whatsapp.unread>0],
    ['Open tickets', o.tickets.open, o.tickets.waiting_us>0],
    ['Callbacks due', o.tickets.callbacks_pending, o.tickets.callbacks_pending>0],
    ['Transfers (mo)', o.transfers.out_month + ' out / ' + o.transfers.in_month + ' in'],
    ['Leads today', o.leads.today],
  ].map(([l,v,warn]) => `<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  // service flow strip with conversion % between stages
  const f = o.flow;
  document.getElementById('flowOverall').textContent = f.overall + '%';
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
  document.getElementById('flowPhases').innerHTML = ['channel','handling','resolution'].map(k => {
    const p = f.phases[k];
    return `<div class="border rounded-lg overflow-hidden">
      <div class="bg-${p.color}-50 border-b px-2 py-1.5 flex items-center gap-2">
        <i class="fa-solid ${p.icon} text-${p.color}-500"></i>
        <span class="text-[11px] font-semibold text-gray-700">${p.label}</span></div>
      <div class="p-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px]">
        ${p.metrics.map(m => `<div class="flex justify-between gap-1">
          <span class="text-gray-500 truncate">${m[0]}</span>
          <span class="font-semibold text-gray-800 whitespace-nowrap">${m[1]}</span></div>`).join('')}
      </div></div>`;
  }).join('');

  // module cards
  const mods = [
    ['Live Chat','fa-comments','indigo','sales-chat.php',[[o.chats.waiting_now,'waiting'],[o.chats.active_now,'active']]],
    ['Tickets','fa-ticket','red','sales-tickets.php',[[o.tickets.open,'open'],[o.tickets.unclaimed,'unclaimed']]],
    ['WhatsApp','fa-comment-dots','green','sales-whatsapp.php',[[o.whatsapp.threads_open,'open'],[o.whatsapp.unread,'unread']]],
    ['Requests','fa-inbox','blue','sales-requests-service.php',[[o.leads.month,'this mo'],[o.leads.today,'today']]],
    ['Live Monitor','fa-headset','teal','sales-conversations.php',[[o.chats.month,'chats mo'],[o.calls.month,'calls mo']]],
    ['Timesheet','fa-clock','yellow','sales-timesheet.php',[[o.tickets.resolved_month,'resolved mo'],[o.transfers.out_month,'transfers']]],
  ];
  // Only surface cards the current role can actually open (same rule as the sales dashboard).
  const CAN_OPEN = new Set((window.SALES_PAGES||[]).map(p => (p.href||'').split('?')[0]));
  document.getElementById('modules').innerHTML = mods
    .filter(([t,i,c,href]) => CAN_OPEN.size === 0 || CAN_OPEN.has(href))
    .map(([t,i,c,href,stats])=>`
    <a href="${href}<?= $adminVertical ?>" class="card p-2 kpi-card block">
      <div class="flex items-center gap-2 mb-1"><i class="fa-solid ${i} text-${c}-500"></i><span class="text-xs font-semibold">${t}</span></div>
      <div class="flex justify-between text-[11px]">${stats.map(([v,l])=>`<div><div class="font-bold text-gray-800">${v}</div><div class="text-gray-400 text-[10px]">${l}</div></div>`).join('')}</div>
    </a>`).join('');

  // weekly channel volume (bar)
  new Chart(cWeekly, { type:'bar', data:{ labels:(o.weekly||[]).map(d=>String(d.d).slice(5)),
    datasets:[{label:'Chats',data:(o.weekly||[]).map(d=>+d.chats),backgroundColor:IND},
              {label:'Calls',data:(o.weekly||[]).map(d=>+d.calls),backgroundColor:TEAL},
              {label:'WhatsApp',data:(o.weekly||[]).map(d=>+d.wa),backgroundColor:GRN}] },
    options:{...CHART_OPTS, scales:{y:{beginAtZero:true,ticks:{precision:0}}}} });

  // 6-month trend (line)
  new Chart(cTrend, { type:'line', data:{ labels:(o.monthly||[]).map(m=>m.ym),
    datasets:[{label:'Chats',data:(o.monthly||[]).map(m=>+m.chats),borderColor:IND,backgroundColor:IND,tension:.3},
              {label:'Calls',data:(o.monthly||[]).map(m=>+m.calls),borderColor:TEAL,backgroundColor:TEAL,tension:.3},
              {label:'WhatsApp',data:(o.monthly||[]).map(m=>+m.wa),borderColor:GRN,backgroundColor:GRN,tension:.3},
              {label:'Tickets',data:(o.monthly||[]).map(m=>+m.tickets),borderColor:RED,backgroundColor:RED,tension:.3}] },
    options:{...CHART_OPTS, scales:{y:{beginAtZero:true,ticks:{precision:0}}}} });

  // channel mix donut
  const PAL = [IND, GRN, TEAL, AMB, BLU, RED, '#a855f7', '#64748b'];
  new Chart(cChannel, { type:'doughnut', data:{ labels:(o.channel_mix||[]).map(c=>c.channel),
    datasets:[{data:(o.channel_mix||[]).map(c=>+c.c),backgroundColor:PAL}] }, options:CHART_OPTS });

  // chat outcomes donut
  const OUT_COLOR = { closed:GRN, active:BLU, waiting:AMB, missed:RED };
  new Chart(cOutcomes, { type:'doughnut', data:{ labels:(o.chat_outcomes||[]).map(c=>c.status),
    datasets:[{data:(o.chat_outcomes||[]).map(c=>+c.c),backgroundColor:(o.chat_outcomes||[]).map(c=>OUT_COLOR[c.status]||'#64748b')}] },
    options:CHART_OPTS });

  // tickets by assist type pie
  new Chart(cTickets, { type:'pie', data:{ labels:(o.tickets_by_type||[]).map(t=>t.type),
    datasets:[{data:(o.tickets_by_type||[]).map(t=>+t.c),backgroundColor:PAL}] }, options:CHART_OPTS });

  // recent chats
  const CH_COLOR = { closed:'green', active:'blue', waiting:'yellow', missed:'red' };
  document.getElementById('chats').innerHTML = (o.recent_chats||[]).map(c=>`
    <tr><td class="text-[11px] font-medium">${esc(c.visitor_name||'Visitor')}</td>
    <td class="text-[11px] text-gray-400">${esc(c.agent||'—')}</td>
    <td>${badge(c.status||'—', CH_COLOR[c.status]||'gray')}</td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No chats yet</td></tr>';

  // recent transfers
  document.getElementById('transfers').innerHTML = (o.recent_transfers||[]).map(t=>`
    <tr><td class="text-[11px]">${esc(t.visitor_name||'Visitor')}</td>
    <td class="text-[11px] text-gray-500">${esc(t.from_agent||'Queue')} <i class="fa-solid fa-arrow-right text-[9px] text-gray-300"></i> ${esc(t.to_agent||'—')}</td>
    <td>${badge(t.action==='transfer' ? (t.from_vertical!==t.to_vertical ? 'to '+esc(t.to_vertical) : 'transfer') : 'assign',
                t.action==='transfer'?'indigo':'gray')}</td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No transfers yet</td></tr>';

  // top agents
  document.getElementById('agents').innerHTML = (o.agents||[]).map(a=>`
    <tr><td class="text-[11px] font-medium">${esc(a.full_name)}</td>
    <td class="text-center text-[11px]">${a.chats} ch · ${a.calls} ca</td>
    <td class="text-right text-[11px]"><span class="font-semibold">${a.tickets_closed}</span> <span class="text-gray-400">closed</span></td></tr>`).join('')
    || '<tr><td class="text-gray-400 text-center py-2 text-[11px]">No agents yet</td></tr>';
}
load();
initCustomKpis('service', document.getElementById('customKpis'));
</script>
</html>
