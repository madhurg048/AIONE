<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_dashboard', 'CFO Cockpit');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('CFO Cockpit',
    'Are we compliant right now? What is due, overdue, and where is the evidence?',
    '<button onclick="cfoRunScheduler()" class="btn-p"><i class="fa-solid fa-rotate"></i> Run scheduler</button>
     <a href="sales-cfo-reports.php" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-export"></i> Reports</a>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- residual risk profile -->
    <div class="card p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Residual risk profile</div>
        <a href="sales-cfo-risk.php" class="text-[10px] text-indigo-600">Open risk register →</a>
      </div>
      <div id="riskProfile" class="space-y-1.5"></div>
    </div>

    <!-- KPI / KRI status -->
    <div class="card p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">KPI / KRI status</div>
        <a href="sales-cfo-kri.php" class="text-[10px] text-indigo-600">Open KPI monitoring →</a>
      </div>
      <div id="kriStatus" class="space-y-1.5"></div>
    </div>

    <!-- upcoming reviews -->
    <div class="card p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Upcoming reviews (30 days)</div>
        <a href="sales-cfo-reviews.php" class="text-[10px] text-indigo-600">All reviews →</a>
      </div>
      <div id="reviews" class="space-y-1"></div>
    </div>
  </div>

  <!-- where the misses are -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <div class="card p-0">
      <div class="px-3 py-2 border-b flex items-center justify-between">
        <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Most overdue right now</div>
        <a href="sales-cfo-calendar.php?status=overdue" class="text-[10px] text-indigo-600">Open the calendar →</a>
      </div>
      <div class="overflow-x-auto"><table class="dense w-full">
        <thead><tr><th>Ref</th><th>Task</th><th>Type</th><th>Owner</th><th>Due</th></tr></thead>
        <tbody id="overdueRows"></tbody></table></div>
    </div>
    <div class="card p-0">
      <div class="px-3 py-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        Completion by obligation type</div>
      <div class="overflow-x-auto"><table class="dense w-full">
        <thead><tr><th>Type</th><th class="text-right">Tasks</th><th class="text-right">Done</th>
          <th class="text-right">Overdue</th><th>Completion</th></tr></thead>
        <tbody id="typeRows"></tbody></table></div>
    </div>
  </div>

  <div id="aiWrap"></div>
  <div class="text-[10px] text-gray-400" id="asOf"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('dashboard',
  'Where is the biggest exposure, and what should be cleared first?');

async function load(){
  await cfoMeta();
  const { data } = await apiGet(CFO_API, { area:'dashboard' });
  const t = data.tiles;

  cfoStrip('strip', [
    ['🩺','Compliance health', t.health_pct + '%',
      t.done_to_date + ' of ' + t.due_to_date + ' tasks due to date completed',
      t.health_pct >= 90 ? 'good' : t.health_pct >= 60 ? 'warn' : 'bad'],
    ['⏰','Overdue tasks', cfoNum(t.overdue), t.total_tasks + ' in the calendar', t.overdue ? 'bad' : 'good'],
    ['📅','Due this week', cfoNum(t.due_week), 'next 7 days', t.due_week ? 'warn' : 'good'],
    ['🛠','Open actions', cfoNum(t.open_actions), 'CAPA not yet closed', t.open_actions ? 'warn' : 'good'],
    ['📄','Overdue forms', cfoNum(t.overdue_forms), 'filings past due', t.overdue_forms ? 'bad' : 'good'],
    ['✍️','Overdue reviews', cfoNum(t.overdue_reviews), 'sign-offs not done', t.overdue_reviews ? 'bad' : 'good'],
    ['📚','Policies to review', cfoNum(t.policies_to_review), 'expiring within 30 days', t.policies_to_review ? 'warn' : 'good'],
    ['🏛','Statutory overdue', cfoNum(t.statutory_overdue),
      'RBI ' + t.rbi_overdue + ' · tax ' + t.tax_overdue, t.statutory_overdue ? 'bad' : 'good'],
  ]);

  // risk profile
  const rp = data.risk_profile, rtot = Object.values(rp).reduce((a,b)=>a+b,0);
  const bandColor = { Critical:'#991b1b', High:'#c2410c', Medium:'#a16207', Low:'#15803d' };
  document.getElementById('riskProfile').innerHTML = Object.keys(rp).map(k => `
    <div>
      <div class="flex justify-between text-[11px]"><span>${k}</span>
        <span class="font-semibold">${rp[k]}</span></div>
      <div class="bar"><span style="width:${rtot ? (rp[k]/rtot*100) : 0}%;background:${bandColor[k]}"></span></div>
    </div>`).join('') + `<div class="text-[10px] text-gray-400 pt-1">${rtot} live risks · rating = likelihood × impact</div>`;

  // KRI RAG
  const rag = data.kri_rag, ktot = Object.values(rag).reduce((a,b)=>a+b,0);
  const ragColor = { green:'#047857', amber:'#b45309', red:'#b91c1c', no_data:'#9ca3af' };
  const ragLabel = { green:'Green', amber:'Amber', red:'Red', no_data:'No data' };
  document.getElementById('kriStatus').innerHTML = Object.keys(rag).map(k => `
    <div>
      <div class="flex justify-between text-[11px]"><span>${ragLabel[k]}</span>
        <span class="font-semibold">${rag[k]}</span></div>
      <div class="bar"><span style="width:${ktot ? (rag[k]/ktot*100) : 0}%;background:${ragColor[k]}"></span></div>
    </div>`).join('') + `<div class="text-[10px] text-gray-400 pt-1">${ktot} indicators · RAG from each indicator's thresholds</div>`;

  // upcoming reviews
  document.getElementById('reviews').innerHTML = (data.upcoming_reviews||[]).map(r => `
    <a href="sales-cfo-reviews.php?open=${r.id}" class="flex items-center gap-2 text-[11px] py-1 border-b border-gray-100 hover:bg-gray-50">
      <span class="ccode">${esc(r.rev_ref||'')}</span>
      <span class="font-medium">${esc(r.period)}</span>
      <span class="text-gray-500">${esc(r.review_type)}</span>
      <span class="flex-1"></span>
      <span class="text-gray-500">${cfoDate(r.scheduled_on)}</span>
      ${cfoStatus(r.status)}</a>`).join('')
    || '<div class="text-[11px] text-gray-400">Nothing scheduled in the next 30 days.</div>';

  // overdue table
  document.getElementById('overdueRows').innerHTML = (data.overdue_top||[]).map(r => `
    <tr class="cursor-pointer hover:bg-gray-50" onclick="location.href='sales-cfo-calendar.php?open=${r.id}'">
      <td>${cfoCode(r.task_ref,'task')}</td>
      <td class="text-[11px] font-medium">${esc(r.title)}<div class="text-[9px] text-gray-400">${esc(r.period)}</div></td>
      <td>${cfoType(r.task_type)}</td>
      <td class="text-[11px]">${cfoOwner(r)}</td>
      <td class="text-[11px]">${cfoDueCell(r.due_on, 'overdue')}</td>
    </tr>`).join('') || '<tr><td colspan="5" class="text-center text-gray-400 py-4">Nothing overdue.</td></tr>';

  // by type
  document.getElementById('typeRows').innerHTML = (data.by_type||[]).map(r => {
    const pct = r.total ? Math.round((r.done/r.total)*100) : 0;
    return `<tr><td>${cfoType(r.task_type)}</td><td class="text-right">${r.total}</td>
      <td class="text-right text-green-700">${r.done}</td>
      <td class="text-right ${+r.overdue ? 'text-red-700 font-semibold' : ''}">${r.overdue}</td>
      <td style="min-width:120px"><div class="bar"><span style="width:${pct}%;background:${pct>=90?'#047857':pct>=50?'#b45309':'#b91c1c'}"></span></div>
        <div class="text-[9px] text-gray-400">${pct}%</div></td></tr>`;
  }).join('') || '<tr><td colspan="5" class="text-center text-gray-400 py-4">No tasks yet — run the scheduler.</td></tr>';

  document.getElementById('asOf').textContent = 'Figures computed from the compliance calendar, risk register and indicator readings · as of ' + data.as_of;
}
load();
</script>
</html>
