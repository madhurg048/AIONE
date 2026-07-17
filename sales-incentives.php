<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$isManager = in_array($me['_role'], ['manager','admin'], true);
sales_head('Incentives');
sales_header($me, 'Sales · Incentives');
sales_nav('incentives');
?>
<main class="p-2 space-y-2">
  <div id="summary" class="kstrip"></div>

  <div data-ai-scope="incentives"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- Leaderboard -->
    <div class="card lg:col-span-2">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Earnings Leaderboard</div>
      <div class="overflow-x-auto">
        <table class="dense"><thead><tr>
          <th>Agent</th><th>Payouts</th><th>Earned</th><th>Pending</th><th>Paid</th><th>AI</th>
        </tr></thead><tbody id="leaderboard"></tbody></table>
      </div>
    </div>
    <!-- Payout batch -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Payout Batch</div>
      <input id="period" type="month" class="ctl w-full mb-2">
      <?php if ($isManager): ?>
      <button onclick="bulk('approve')" class="btn-p w-full mb-1">Approve all accrued</button>
      <button onclick="bulk('pay')" class="w-full text-xs bg-green-600 text-white rounded h-7 hover:bg-green-700">Mark approved as paid</button>
      <?php endif; ?>
      <p class="text-[10px] text-gray-400 mt-2">Batch actions apply to the selected month; rows can also be actioned below.</p>
    </div>
  </div>

  <!-- Monthly payouts -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Payouts — month by month</div>
    <div class="overflow-x-auto">
      <table class="dense"><thead><tr>
        <th>Month</th><th>Lines</th><th>Total</th><th>Paid</th><th>Approved</th><th>Accrued</th><th>Agent breakdown</th>
      </tr></thead><tbody id="monthly"></tbody></table>
    </div>
  </div>

  <!-- Ledger -->
  <div class="card">
    <div class="flex items-center gap-2 p-2 border-b">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mr-1">Incentive Ledger</span>
      <select id="fStatus" onchange="loadLedger()" class="ctl">
        <option value="">All</option><option>accrued</option><option>approved</option><option>paid</option><option>on_hold</option>
      </select>
    </div>
    <div class="overflow-x-auto" style="max-height:calc(100vh - 330px)">
      <table class="dense"><thead><tr>
        <th>Agent</th><th>Merchant</th><th>Basis</th><th>Amount</th><th>Period</th><th>Status</th><th style="width:90px">Action</th>
      </tr></thead><tbody id="ledger"></tbody></table>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;
const statusColor = { accrued:'yellow', approved:'blue', paid:'green', on_hold:'gray', clawed_back:'red' };

async function loadSummary() {
  const { data: s } = await apiGet('incentives.php', { summary: 1 });
  const kpis = [['Total Booked', inr(s.total)], ['This Month', inr(s.this_month)],
    ['Pending Approval', inr(s.pending_approval)], ['Paid Out', inr((s.by_status.paid||{}).amount||0)]];
  document.getElementById('summary').innerHTML = kpis.map(([l, v]) =>
    `<div><div class="kv">${v}</div><div class="kl">${l}</div></div>`).join('');
}

async function loadMonthly() {
  const { data } = await apiGet('incentives.php', { monthly: 1 });
  document.getElementById('monthly').innerHTML = (data.by_month||[]).map(m => `
    <tr>
      <td class="font-medium">${esc(m.period_month)}</td>
      <td>${m.line_count}</td>
      <td class="font-semibold">${inr(m.total)}</td>
      <td class="text-green-600">${inr(m.paid)}</td>
      <td class="text-blue-600">${inr(m.approved)}</td>
      <td class="text-yellow-600">${inr(m.accrued)}</td>
      <td class="rowact"><button onclick="monthAgents('${esc(m.period_month)}')" class="text-indigo-600 hover:underline text-[11px]">by agent</button></td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-gray-400 py-2">No payouts yet</td></tr>';
}
async function monthAgents(month) {
  const { data } = await apiGet('incentives.php', { monthly: 1, month });
  const rows = (data.by_agent||[]);
  openModal(`<table class="dense w-full"><thead><tr><th>Agent</th><th>Total</th><th>Paid</th><th>Pending</th></tr></thead><tbody>
    ${rows.map(a=>`<tr><td class="font-medium">${esc(a.full_name)}</td><td class="font-semibold">${inr(a.total)}</td>
      <td class="text-green-600">${inr(a.paid)}</td><td class="text-yellow-600">${inr(a.pending)}</td></tr>`).join('')
    || '<tr><td colspan="4" class="text-gray-400 text-center py-2">No payouts</td></tr>'}
  </tbody></table>`, 'Payouts by agent — ' + month);
}

async function loadLeaderboard() {
  const { data } = await apiGet('incentives.php', { leaderboard: 1 });
  document.getElementById('leaderboard').innerHTML = data.map(a => `
    <tr>
      <td class="font-medium">${esc(a.full_name)}</td>
      <td>${a.payouts}</td>
      <td class="font-semibold">${inr(a.earned)}</td>
      <td class="text-yellow-600">${inr(a.pending)}</td>
      <td class="text-green-600">${inr(a.paid)}</td>
      <td><button onclick="coach(${a.id},'${esc(a.full_name)}')" class="text-purple-600 hover:text-purple-800"><i class="fa-solid fa-wand-magic-sparkles"></i></button></td>
    </tr>`).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-2">No incentives yet</td></tr>';
}

async function loadLedger() {
  const params = {};
  if (document.getElementById('fStatus').value) params.status = document.getElementById('fStatus').value;
  const res = await apiGet('incentives.php', params);
  document.getElementById('ledger').innerHTML = res.data.map(r => `
    <tr>
      <td class="font-medium">${esc(r.employee_name)}</td>
      <td>${esc(r.merchant_name||r.merchant_code||'—')}</td>
      <td class="text-gray-500">${esc(r.basis)}</td>
      <td class="font-semibold">${inr(r.amount)}</td>
      <td>${esc(r.period_month)}</td>
      <td>${badge(r.status, statusColor[r.status]||'gray')}</td>
      <td class="rowact">
        ${IS_MANAGER && r.status==='accrued' ? `<button onclick="act('approve',${r.id})" class="text-blue-600 hover:underline mr-2">Approve</button>`:''}
        ${IS_MANAGER && r.status==='approved' ? `<button onclick="act('pay',${r.id})" class="text-green-600 hover:underline">Pay</button>`:''}
        ${!IS_MANAGER ? '<span class="text-gray-300">—</span>':''}
      </td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-gray-400 py-4">No rows</td></tr>';
}

async function act(kind, id) {
  const res = await apiSend(`incentives.php?${kind}=${id}`, {});
  if (res.success) { toast(kind==='approve'?'Approved':'Paid'); loadLedger(); loadSummary(); loadLeaderboard(); }
  else toast(res.error||'Failed', false);
}

async function bulk(kind) {
  const period = document.getElementById('period').value;
  if (!period) return toast('Pick a month first', false);
  const res = await apiSend(`incentives.php?bulk=${kind}&period=${period}`, {});
  if (res.success) { toast(`${kind==='approve'?res.data.approved:res.data.paid} rows ${kind==='approve'?'approved':'paid'}`); loadLedger(); loadSummary(); loadLeaderboard(); }
  else toast(res.error||'Failed', false);
}

async function coach(id, name) {
  toast('AI coaching…');
  const res = await apiSend('ai.php', { action: 'coach_agent', employee_id: id });
  if (!res.success) return toast(res.error||'AI unavailable', false);
  const d = res.data;
  openModal(`
    <div class="space-y-3 text-xs">
      <div class="bg-indigo-50 rounded p-2 font-medium text-indigo-700">${esc(d.headline||'')}</div>
      <div><div class="font-semibold text-green-600 mb-1">Strengths</div><ul class="list-disc pl-5">${(d.strengths||[]).map(s=>`<li>${esc(s)}</li>`).join('')}</ul></div>
      <div><div class="font-semibold text-red-500 mb-1">Gaps</div><ul class="list-disc pl-5">${(d.gaps||[]).map(s=>`<li>${esc(s)}</li>`).join('')}</ul></div>
      <div><div class="font-semibold text-gray-600 mb-1">Coaching</div><ul class="list-disc pl-5">${(d.coaching||[]).map(s=>`<li>${esc(s)}</li>`).join('')}</ul></div>
    </div>`, 'AI Coaching — ' + name);
}

loadSummary(); loadLeaderboard(); loadMonthly(); loadLedger();
</script>
</html>
