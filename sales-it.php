<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('IT');
sales_header($me, 'IT Dashboard');
sales_nav('it');
?>
<main class="p-2 space-y-2">
  <div id="kpis" class="kstrip"></div>

  <!-- integration health cards -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-plug-circle-bolt text-indigo-500"></i> Integration Health</div>
    <div id="integrations" class="grid grid-cols-2 md:grid-cols-5 gap-2"></div>
    <div class="text-[10px] text-gray-400 mt-2">Configure any of these in <a href="sales-settings.php" class="text-indigo-600">Settings</a>.</div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- users by role -->
    <div class="card">
      <div class="p-2 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-users-gear text-indigo-500"></i> User Accounts</span>
        <a href="sales-users.php" class="text-[11px] text-indigo-600 hover:underline">Manage →</a>
      </div>
      <table class="dense"><thead><tr><th>Role</th><th>Active</th><th>Inactive</th></tr></thead><tbody id="users"></tbody></table>
    </div>
    <!-- api tokens -->
    <div class="card">
      <div class="p-2 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-key text-indigo-500"></i> API Tokens</span>
        <a href="sales-integrations.php" class="text-[11px] text-indigo-600 hover:underline">Integrations →</a>
      </div>
      <table class="dense"><tbody id="tokens"></tbody></table>
    </div>
    <!-- automation activity -->
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-robot text-indigo-500"></i> Automation Activity</div>
      <table class="dense"><tbody id="autoLogs"></tbody></table>
    </div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
const roleColor = { admin:'red', manager:'indigo', sales_exec:'blue', hr:'green', it:'yellow' };
function statusDot(i){
  if(!i.enabled) return '<span class="text-gray-400 text-[10px]">○ off</span>';
  if(i.up===false) return '<span class="text-red-600 text-[10px] font-semibold">● DOWN</span>';
  if(i.up===true)  return '<span class="text-green-600 text-[10px] font-semibold">● up</span>';
  return '<span class="text-green-600 text-[10px]">● on</span>';
}
async function load(){
  const { data:d } = await apiGet('it.php'); const k=d.kpis;
  document.getElementById('kpis').innerHTML = [
    ['Integrations on', k.integrations_on+' / '+k.integrations_all],
    ['Active users', k.users_active],['Inactive', k.users_inactive, k.users_inactive>0],
    ['Automation rules', k.rules_active],['Automation runs', k.automation_runs],
    ['Txns today', d.system.transactions_today],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  document.getElementById('integrations').innerHTML = d.integrations.map(i=>`
    <div class="border rounded-lg p-2 ${i.up===false?'border-red-200 bg-red-50':''}">
      <div class="flex items-center justify-between"><i class="fa-solid ${i.icon} text-gray-500"></i>${statusDot(i)}</div>
      <div class="text-[11px] font-semibold mt-1">${esc(i.label)}</div>
      <div class="text-[10px] text-gray-400 truncate">${esc(i.detail||'')}</div>
      ${i.note?`<div class="text-[9px] text-red-500">${esc(i.note)}</div>`:''}
    </div>`).join('');

  document.getElementById('users').innerHTML = d.users_by_role.map(u=>`
    <tr><td>${badge(u.role, roleColor[u.role]||'gray')}</td><td class="font-semibold">${u.active}</td>
      <td class="text-gray-400">${u.inactive}</td></tr>`).join('') || '<tr><td colspan="3" class="text-gray-400 text-center py-2">—</td></tr>';

  document.getElementById('tokens').innerHTML = d.api_tokens.map(t=>`
    <tr><td class="text-[11px] font-medium">${esc(t.label)}</td>
      <td>${t.is_active==1?'<span class="text-green-600 text-[10px]">active</span>':'<span class="text-gray-400 text-[10px]">off</span>'}</td>
      <td class="text-[10px] text-gray-400">${esc(t.last_used_at||'never')}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-gray-400 text-center py-2 text-xs">No tokens</td></tr>';

  document.getElementById('autoLogs').innerHTML = (d.automation_logs||[]).map(l=>`
    <tr><td>${badge(l.status, l.status==='success'?'green':'red')}</td>
      <td class="text-[11px]">${esc(l.rule_name||'rule')}</td>
      <td class="text-[10px] text-gray-400">${esc(String(l.created_at||'').slice(5,16))}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-gray-400 text-center py-2 text-xs">No activity</td></tr>';
}
load();
</script>
</html>
