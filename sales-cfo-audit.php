<?php
require_once __DIR__ . '/includes/cfo-layout.php';
$me = cfo_page('cfo_audit', 'Audit Trail');
?>
<main class="p-3 space-y-2">
  <?php cfo_head('Audit Trail',
    'Immutable and append-only. It cannot be edited or deleted through the UI or the API.',
    '<a href="api/sales/cfo.php?export=audit" class="text-[11px] border rounded px-2 py-1.5 hover:bg-gray-50">
       <i class="fa-solid fa-file-csv"></i> Export</a>'); ?>

  <div id="strip" class="flex gap-2 flex-wrap"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <select id="fEntity" class="ctl" onchange="load()"><option value="">All records</option></select>
    <select id="fAction" class="ctl" onchange="load()"><option value="">All actions</option></select>
    <input id="q" class="ctl" placeholder="Search user, detail or record id…" oninput="deb()" style="min-width:230px">
    <select id="fLimit" class="ctl" onchange="load()">
      <option value="300">Last 300</option><option value="1000">Last 1000</option><option value="2000">Last 2000</option></select>
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400" id="rowCount"></span>
  </div>

  <div class="card p-0">
    <div class="overflow-x-auto" style="max-height:64vh"><table class="dense w-full">
      <thead><tr><th>When (IST)</th><th>User</th><th>Action</th><th>Entity</th><th>Entity id</th><th>Detail</th></tr></thead>
      <tbody id="rows"></tbody></table></div>
  </div>

  <div id="aiWrap"></div>
</main>

<?php cfo_scripts(); ?>
<script>
document.getElementById('aiWrap').innerHTML = cfoAiPanel('audit',
  'Any concentration on one user, or unusual action patterns?');

let tmr = null;
const deb = () => { clearTimeout(tmr); tmr = setTimeout(load, 350); };
const AC = { create:'green', update:'blue', delete:'red', sign:'purple', approved:'green',
             rejected:'red', submitted:'blue', reviewed:'purple', assign:'yellow', split:'yellow',
             run:'gray', export:'gray' };

async function load(){
  await cfoMeta();
  const { data } = await apiGet(CFO_API, { area:'audit',
    entity: document.getElementById('fEntity').value, action: document.getElementById('fAction').value,
    q: document.getElementById('q').value, limit: document.getElementById('fLimit').value });
  const t = data.totals;
  cfoStrip('strip', [
    ['🧾','Entries', cfoNum(t.total), 'since the cockpit went live'],
    ['📅','Today', cfoNum(t.today_cnt), 'recorded today', 'info'],
    ['🗓','Last 7 days', cfoNum(t.week_cnt), 'recorded this week', 'info'],
    ['👤','Users', cfoNum(t.users), 'have touched a record'],
  ]);

  const fill = (id, vals, label) => {
    const el = document.getElementById(id);
    if (el.options.length <= 1) el.innerHTML = `<option value="">${label}</option>` +
      vals.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  };
  fill('fEntity', data.entities || [], 'All records');
  fill('fAction', data.actions || [], 'All actions');

  document.getElementById('rowCount').textContent = (data.rows||[]).length + ' shown';
  document.getElementById('rows').innerHTML = (data.rows||[]).map(r => `
    <tr class="hover:bg-gray-50">
      <td class="text-[10px] text-gray-500 whitespace-nowrap">${esc(cfoDT(r.created_at))}</td>
      <td class="text-[11px]">${esc(r.user_name||'system')}</td>
      <td>${badge(r.action, AC[r.action] || 'gray')}</td>
      <td class="text-[10px]">${esc(r.entity)}</td>
      <td class="text-[10px] text-gray-500">${esc(r.entity_id)}</td>
      <td class="text-[11px] text-gray-700">${esc(r.summary||'')}</td>
    </tr>`).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-6">Nothing matches.</td></tr>';
}
load();
</script>
</html>
