<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('HR');
sales_header($me, 'HR Dashboard');
sales_nav('hr');
?>
<main class="p-2 space-y-2">
  <div id="kpis" class="kstrip"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- compliance -->
    <div class="card lg:col-span-2">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-user-clock text-indigo-500"></i> Timesheet Compliance (this week)</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr>
        <th>Employee</th><th>Role</th><th>Today</th><th>This week</th><th>Open tasks</th><th>Status</th>
      </tr></thead><tbody id="comp"></tbody></table></div>
    </div>
    <!-- headcount by role -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Headcount by Role</div>
      <div id="byRole" class="space-y-1.5"></div>
    </div>
  </div>

  <!-- HR change requests: what the HR team has proposed, waiting on the Head -->
  <div id="crCard" class="card hidden">
    <div class="p-2 border-b flex items-center justify-between gap-2 flex-wrap">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-gavel text-indigo-500"></i> HR Change Requests
        <span id="crCount" class="ml-1 normal-case font-normal text-gray-400"></span>
      </span>
      <span id="crHint" class="text-[10px] text-gray-400"></span>
    </div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Change</th><th>Requested by</th><th>When</th><th style="width:190px"></th>
    </tr></thead><tbody id="crRows"></tbody></table></div>
    <div id="crRecent" class="border-t p-2 space-y-1"></div>
  </div>

  <!-- pending approvals -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-clipboard-check text-amber-500"></i> Timesheets Pending Approval</div>
    <table class="dense"><thead><tr><th>Employee</th><th>Date</th><th>Type</th><th>Hours</th><th>Description</th><th style="width:130px"></th></tr></thead>
      <tbody id="pending"></tbody></table>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
const roleColor = {
  admin:'red', hr_head:'green', hr_manager:'green', hr:'green',
  sales_head:'indigo', manager:'indigo', sales_exec:'blue',
  service_manager:'pink', service_exec:'purple',
  support_manager:'orange', support:'orange', it_manager:'yellow', it:'yellow'
};
async function load(){
  const { data:d } = await apiGet('hr.php'); const k=d.kpis;
  document.getElementById('kpis').innerHTML = [
    ['Headcount', k.headcount],['Logged today', k.logged_today+' / '+k.headcount, k.logged_today<k.headcount],
    ['Pending approval', k.pending_approval, k.pending_approval>0],['Hours this week', k.hours_week],
    ['Open tasks', k.open_tasks],['Overdue', k.overdue_tasks, k.overdue_tasks>0],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  document.getElementById('comp').innerHTML = d.compliance.map(c=>{
    const loggedToday = +c.hours_today > 0;
    return `<tr class="${loggedToday?'':'bg-red-50/40'}">
      <td class="font-medium">${esc(c.full_name)}</td>
      <td class="text-gray-500 text-[11px]">${esc(c.designation||c.role||'')}</td>
      <td>${(+c.hours_today).toFixed(2)}h</td>
      <td class="font-semibold">${(+c.hours_week).toFixed(2)}h</td>
      <td>${c.open_tasks>0?badge(c.open_tasks,'indigo'):'—'}</td>
      <td>${loggedToday?'<span class="text-green-600 text-[11px]">● logged</span>':'<span class="text-red-600 text-[11px] font-semibold">○ not logged</span>'}</td>
    </tr>`;}).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-3">No staff</td></tr>';

  document.getElementById('byRole').innerHTML = d.by_role.map(r=>`
    <div class="flex items-center justify-between"><span class="text-xs">${badge(r.role_label||r.role, roleColor[r.role]||'gray')}</span>
      <span class="text-sm font-bold">${r.c}</span></div>`).join('') || '<div class="text-gray-400 text-xs">—</div>';

  renderChangeRequests(d.change_requests);

  document.getElementById('pending').innerHTML = d.pending.map(p=>`
    <tr><td class="font-medium">${esc(p.employee)}</td><td>${esc(p.entry_date)}</td><td>${esc(p.activity_type)}</td>
      <td>${p.hours}h</td><td class="text-gray-600">${esc(p.description||'')}</td>
      <td class="whitespace-nowrap">
        <button onclick="act(${p.id},'approve')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Approve</button>
        <button onclick="act(${p.id},'reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Reject</button>
      </td></tr>`).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-3">Nothing pending 🎉</td></tr>';
}
async function act(id, a){ const r = await apiSend('hr.php?'+a+'&id='+id, {}); if(r.success){ toast('Marked '+r.data.status); load(); } }

/**
 * The governance queue. An HR Manager / HR Executive's structural changes (people, roles,
 * reporting lines, global KPAs, module access) land here instead of taking effect, and the
 * HR Head approves or rejects them. The Head sees Approve/Reject buttons; everyone else sees
 * the same queue read-only, so a requester can watch their own submission's status.
 */
function renderChangeRequests(cr){
  if(!cr) return;
  const card = document.getElementById('crCard');
  // Nothing pending and nothing decided, and I can't decide anyway -> keep the screen clean.
  if(!cr.pending.length && !cr.decided.length){ card.classList.add('hidden'); return; }
  card.classList.remove('hidden');

  document.getElementById('crCount').textContent = cr.count ? `· ${cr.count} awaiting decision` : '· nothing pending';
  document.getElementById('crHint').textContent = cr.can_decide
    ? 'You are the approver — changes below are not live until you approve them.'
    : (cr.needs_approval ? 'Your changes appear here until the HR Head approves them.' : '');

  document.getElementById('crRows').innerHTML = cr.pending.map(r=>`
    <tr>
      <td><div class="font-medium">${esc(r.summary)}</div>
          <div class="text-[10px] text-gray-400">${esc(r.type_label)}${r.target_label?' · '+esc(r.target_label):''}</div></td>
      <td class="text-gray-600">${esc(r.requested_by_name||'—')}
          <div class="text-[10px] text-gray-400">${esc(r.requested_by_role||'')}</div></td>
      <td class="text-gray-400 text-[11px]">${esc(r.requested_at||'')}</td>
      <td class="whitespace-nowrap">
        ${cr.can_decide ? `
          <button onclick="decide(${r.id},'approve')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded hover:bg-green-200">Approve</button>
          <button onclick="decide(${r.id},'reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded hover:bg-red-200 ml-1">Reject</button>`
        : '<span class="text-[10px] text-amber-600">awaiting HR Head</span>'}
      </td>
    </tr>`).join('') || '<tr><td colspan="4" class="text-center text-gray-400 py-3">Nothing awaiting approval 🎉</td></tr>';

  document.getElementById('crRecent').innerHTML = cr.decided.length
    ? '<div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Recently decided</div>' + cr.decided.slice(0,6).map(r=>`
        <div class="flex items-center gap-2 text-[11px]">
          ${badge(r.status, r.status==='approved'?'green':'red')}
          <span class="text-gray-700">${esc(r.summary)}</span>
          <span class="text-gray-400">— ${esc(r.requested_by_name||'')} · decided by ${esc(r.decided_by_name||'')}</span>
        </div>`).join('')
    : '';
}

async function decide(id, decision){
  const note = decision === 'reject' ? (prompt('Reason for rejecting (optional):','') ?? null) : null;
  if(decision === 'reject' && note === null) return;                 // cancelled the prompt
  const r = await apiSend('hr.php?action=decide', { id, decision, note });
  if(r.success){ toast(decision==='approve' ? 'Approved and applied' : 'Rejected'); load(); }
  else toast(r.error || 'Failed', false);
}

load();
</script>
</html>
