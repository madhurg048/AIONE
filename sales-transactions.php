<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Transactions');
sales_header($me, 'Sales · Transactions');
sales_nav('transactions');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <!-- toolbar -->
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div class="relative">
      <span class="absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
      <input id="q" class="ctl pl-7 w-56" placeholder="Search ref / merchant / product…" oninput="liveSearch()">
    </div>
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option><option>success</option><option>failed</option><option>refunded</option><option>pending</option>
    </select>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">From <input id="fFrom" type="date" class="ctl" onchange="load()"></label>
    <label class="text-[11px] text-gray-500 flex items-center gap-1">To <input id="fTo" type="date" class="ctl" onchange="load()"></label>
    <button onclick="clearF()" class="ctl hover:bg-gray-50" title="Clear filters"><i class="fa-solid fa-xmark"></i></button>
    <div class="flex-1"></div>
    <span id="count" class="text-[11px] text-gray-400"></span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th class="sortable" onclick="sortBy('txn_date')">Date</th>
        <th>Txn ref</th><th class="sortable" onclick="sortBy('merchant')">Merchant</th>
        <th>Product</th><th class="sortable text-right" onclick="sortBy('amount')">Amount</th>
        <th class="sortable" onclick="sortBy('status')">Status</th><th>Source</th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
    <div id="pager" class="p-2"></div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
let page = 1, perPage = 25, pages = 1, sortK = 'txn_date', sortDir = 'desc';
const stColor = { success:'green', failed:'red', refunded:'yellow', pending:'gray' };

function params(){
  const p = { page, per: perPage, sort: sortK, dir: sortDir };
  const q = document.getElementById('q').value; if (q) p.q = q;
  const s = document.getElementById('fStatus').value; if (s) p.status = s;
  const f = document.getElementById('fFrom').value; if (f) p.from = f;
  const t = document.getElementById('fTo').value; if (t) p.to = t;
  return p;
}
let _t; function liveSearch(){ clearTimeout(_t); _t = setTimeout(()=>{ page=1; load(); }, 300); }
function goPage(p, per){ if (per) perPage = +per; page = Math.max(1, p||1); load(); }
function sortBy(k){ if (sortK===k) sortDir = sortDir==='asc'?'desc':'asc'; else { sortK=k; sortDir='desc'; } load(); }
function clearF(){ document.getElementById('q').value=''; document.getElementById('fStatus').value=''; document.getElementById('fFrom').value=''; document.getElementById('fTo').value=''; page=1; load(); }

async function load(){
  const res = await apiGet('transactions.php', params());
  pages = res.pages || 1;
  const s = res.summary || {};
  document.getElementById('kpis').innerHTML = [
    ['Transactions', (s.txns||0).toLocaleString('en-IN')],
    ['GMV (success)', inr(s.gmv||0)],
    ['Successful', s.ok||0], ['Failed', (s.fail||0), (s.fail||0)>0],
    ['Today', s.today||0],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('count').textContent = (res.total||0) + ' transactions';
  document.getElementById('rows').innerHTML = (res.data||[]).map(t=>`
    <tr>
      <td class="text-gray-500 whitespace-nowrap">${esc(String(t.txn_date||'').slice(0,16).replace('T',' '))}</td>
      <td class="font-mono text-[11px]">${esc(t.txn_ref||'—')}</td>
      <td class="font-medium">${esc(t.merchant_name||t.merchant_code||('#'+t.merchant_id))}</td>
      <td class="text-gray-500">${esc(t.product||'—')}</td>
      <td class="text-right font-semibold">${inr(t.amount)}</td>
      <td>${badge(t.status, stColor[t.status]||'gray')}</td>
      <td class="text-gray-400">${esc(t.source||'—')}</td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-gray-400 py-4">No transactions match</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
}
load();
</script>
</html>
