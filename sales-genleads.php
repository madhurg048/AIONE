<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Generated Leads');
sales_header($me, 'Service · Generated Leads');
sales_nav('genleads');
?>
<main class="p-2 space-y-2">

  <p class="text-[11px] text-gray-500">Every sales enquiry your team has captured from a service
    interaction — including the ones already handed to Sales, which drop off the Requests tab the
    moment they're sent. This is where to find them and see exactly who they landed on.</p>

  <div id="kpis" class="kstrip"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <div class="relative">
      <span class="absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
      <input id="q" class="ctl pl-7 w-56" placeholder="Search name / company / ref…" oninput="liveSearch()">
    </div>
    <select id="fStatus" class="ctl" onchange="goPage(1)">
      <option value="">All</option>
      <option value="draft">Draft (not sent)</option>
      <option value="transferred">Transferred to Sales</option>
    </select>
    <button onclick="clearF()" class="ctl hover:bg-gray-50" title="Clear filters"><i class="fa-solid fa-xmark"></i></button>
    <div class="flex-1"></div>
    <span id="count" class="text-[11px] text-gray-400"></span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Ref</th><th>Name</th><th>Company</th><th>Contact</th><th>Subject</th>
        <th>Captured by</th><th>Captured</th>
        <th>Status</th><th>Sent by</th><th>Received by (Sales)</th><th style="width:70px">Actions</th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
    <div id="pager" class="p-2"></div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
let page = 1, perPage = 20, pages = 1;
let ROWS = [];

function params(){
  const p = { list: 1, page, per: perPage };
  const q = document.getElementById('q').value; if (q) p.q = q;
  const st = document.getElementById('fStatus').value; if (st) p.status = st;
  return p;
}
let _t; function liveSearch(){ clearTimeout(_t); _t = setTimeout(()=>{ page=1; load(); }, 300); }
function goPage(p, per){ if (per) perPage = +per; page = Math.max(1, p||1); load(); }
function clearF(){ document.getElementById('q').value=''; document.getElementById('fStatus').value=''; page=1; load(); }

async function load(){
  const res = await apiGet('service-lead.php', params());
  ROWS = res.data || [];
  pages = res.pages || 1;
  const total = res.total || 0;
  const sent = ROWS.filter(r => r.vertical === 'sales').length;
  document.getElementById('kpis').innerHTML = [
    ['Total generated', total], ['Transferred to Sales', sent], ['Still draft', total - sent],
  ].map(([l,v])=>`<div><div class="kv">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('count').textContent = total + ' lead(s)';

  document.getElementById('rows').innerHTML = ROWS.map(r => {
    const name = ((r.first_name||'') + ' ' + (r.last_name||'')).trim() || '—';
    const contact = [r.email, r.mobile || r.phone].filter(Boolean).join('<br>') || '—';
    const sent = r.vertical === 'sales';
    const status = sent
      ? '<span class="text-green-700 bg-green-50 border border-green-200 rounded px-1.5 py-0.5 text-[10px] font-semibold"><i class="fa-solid fa-paper-plane text-[9px]"></i> Transferred to Sales</span>'
      : '<span class="text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 text-[10px] font-semibold">Draft — not sent</span>';
    // Both frozen at the moment of transfer (see phase48 migration) — won't drift if Sales
    // later reassigns the lead to someone else.
    const sentBy = sent ? (r.transferred_by_name ? esc(r.transferred_by_name) : '<span class="text-gray-400">—</span>') : '<span class="text-gray-300">—</span>';
    const assignee = sent
      ? (r.assigned_to_name ? esc(r.assigned_to_name) : '<span class="text-gray-400">Unassigned</span>')
      : '<span class="text-gray-300">—</span>';
    return `<tr>
      <td class="font-mono text-[11px] text-gray-600">${esc(r.ticket_ref || '—')}</td>
      <td class="font-medium">${esc(name)}</td>
      <td>${esc(r.company || '—')}</td>
      <td class="text-gray-500">${contact}</td>
      <td class="text-gray-600 max-w-xs truncate" title="${esc(r.subject || '')}">${esc(r.subject || '—')}</td>
      <td class="text-gray-600">${esc(r.created_by_name || '—')}</td>
      <td class="text-gray-400 whitespace-nowrap">${esc(String(r.lead_created_at||'').slice(0,16).replace('T',' '))}</td>
      <td>${status}</td>
      <td class="text-gray-600">${sentBy}</td>
      <td class="font-medium ${sent ? 'text-indigo-600' : ''}">${assignee}</td>
      <td class="rowact">
        ${!sent ? `<button onclick="sendToSales(${r.lead_id})" title="Send this draft to Sales" class="text-gray-400 hover:text-green-600"><i class="fa-solid fa-paper-plane"></i></button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="11" class="text-center text-gray-400 py-4">No generated leads yet</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total, per: perPage }, 'goPage');
}

async function sendToSales(leadId){
  if (!confirm('Send this lead to Sales? It will be assigned to the next sales exec in the rotation.')) return;
  const r = await apiSend('service-lead.php?action=send&id=' + leadId, {}, 'PATCH');
  if (!r.success) return toast(r.error || 'Could not send to Sales', false);
  const who = r.data && r.data.owner ? r.data.owner.name : null;
  toast(who ? `Sent to Sales — assigned to ${who}` : 'Sent to Sales, but no sales exec was available to assign', !!who);
  load();
}

load();
setInterval(load, 20000);
</script>
</html>
