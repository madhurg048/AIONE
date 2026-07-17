<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('My Activity');
sales_header($me, 'My Activity');
sales_nav('activity');
?>
<main class="p-2 space-y-2">
  <?php sales_goal_widget(); ?>
  <div id="kpis" class="kstrip"></div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- task monitor -->
    <div class="card lg:col-span-2">
      <div class="p-2 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-list-check text-indigo-500"></i> My Tasks</span>
        <a href="sales-timesheet.php" class="text-[11px] text-indigo-600 hover:underline">Timesheet →</a>
      </div>
      <table class="dense"><tbody id="tasks"></tbody></table>
    </div>
    <!-- time this week -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Time logged this week</div>
      <div id="timeByType" class="space-y-1.5"></div>
    </div>
  </div>

  <!-- activity timeline -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Recent Activity</div>
    <div id="timeline" class="space-y-1"></div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
const kindIcon = { call:'fa-phone', whatsapp:'fa-brands fa-whatsapp', chat:'fa-comments', email:'fa-envelope',
  meeting:'fa-people-group', note:'fa-note-sticky', task:'fa-list-check', follow_up:'fa-clipboard-check',
  onboarding:'fa-store', admin:'fa-gear', travel:'fa-car', training:'fa-graduation-cap', other:'fa-circle' };
const priColor = { urgent:'red', high:'amber', medium:'blue', low:'gray' };

async function load(){
  const { data:d } = await apiGet('activity.php');
  document.getElementById('kpis').innerHTML = [
    ['Open tasks', d.tasks.open, d.tasks.open>0],
    ['Overdue', d.tasks.overdue, d.tasks.overdue>0],
    ['Done today', d.tasks.done_today],
    ['Hours today', d.timesheet.hours_today],
    ['Hours this week', d.timesheet.hours_week],
    ['Awaiting approval', d.timesheet.pending_approval],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  document.getElementById('tasks').innerHTML = (d.task_list||[]).map(t=>{
    const overdue = t.due_at && new Date(t.due_at) < new Date();
    return `<tr>
      <td>${badge(t.task_type||'task','indigo')}</td>
      <td class="font-medium">${esc(t.title)}</td>
      <td>${t.priority?badge(t.priority, priColor[t.priority]||'gray'):''}</td>
      <td class="text-[11px] ${overdue?'text-red-600 font-semibold':'text-gray-400'}">${t.due_at?esc(String(t.due_at).slice(0,16))+(overdue?' · overdue':''):'—'}</td>
    </tr>`;}).join('') || '<tr><td colspan="4" class="text-center text-gray-400 py-4">No open tasks 🎉</td></tr>';

  const maxH = Math.max(1, ...(d.time_by_type||[]).map(x=>+x.hrs));
  document.getElementById('timeByType').innerHTML = (d.time_by_type||[]).map(x=>`
    <div class="flex items-center gap-2">
      <span class="text-[10px] w-20 text-gray-500 capitalize"><i class="fa-solid ${kindIcon[x.activity_type]||'fa-circle'} text-gray-400"></i> ${esc(x.activity_type)}</span>
      <div class="flex-1 h-3 bg-gray-100 rounded"><div class="bg-indigo-400 h-3 rounded" style="width:${Math.round(x.hrs*100/maxH)}%"></div></div>
      <span class="text-[11px] font-semibold w-10 text-right">${(+x.hrs).toFixed(2)}h</span></div>`).join('')
    || '<div class="text-gray-400 text-xs text-center py-2">No time logged this week</div>';

  document.getElementById('timeline').innerHTML = (d.timeline||[]).map(e=>`
    <div class="flex items-center gap-2 text-xs border-b py-1">
      <i class="fa-solid ${kindIcon[e.kind]||'fa-circle'} text-gray-400 w-4 text-center"></i>
      <span class="text-gray-700 flex-1 truncate">${esc(e.label||e.kind)}</span>
      <span class="text-[10px] text-gray-400">${esc(String(e.ts||'').slice(0,16))}</span>
    </div>`).join('') || '<div class="text-gray-400 text-xs text-center py-2">No recent activity</div>';
}
load();
</script>
</html>
