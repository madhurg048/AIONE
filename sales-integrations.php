<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Integrations');
sales_header($me, 'Admin · Integrations & API');
sales_nav('integrations');
?>
<main class="p-2 space-y-2">

  <!-- API keys -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">API Keys</div>
      <button onclick="newKey()" class="btn-p"><i class="fa-solid fa-plus"></i> New key</button>
    </div>
    <table class="dense"><thead><tr><th>Label</th><th>Key</th><th>Scopes</th><th>Last used</th><th></th></tr></thead>
      <tbody id="keys"></tbody></table>
    <p class="text-[10px] text-gray-400 mt-1">Send the key on every call as header <code>X-API-Key: &lt;key&gt;</code> (or <code>Authorization: Bearer &lt;key&gt;</code>). Rotate immediately if leaked.</p>
  </div>

  <!-- API reference -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">API Reference — endpoints ITSM / PIMS / the gateway can call</div>
    <div id="catalog" class="space-y-2"></div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
    <!-- recent transactions -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">Recent Transactions (last 25)</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr><th>When</th><th>Merchant</th><th>Ref</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody id="txns"></tbody></table></div>
    </div>
    <!-- ITSM tickets -->
    <div class="card p-3">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">ITSM Tickets (last 25)</div>
      <div class="overflow-x-auto"><table class="dense"><thead><tr><th>Ref</th><th>Subject</th><th>Status</th><th>Assigned</th><th>Waiting on</th></tr></thead>
        <tbody id="tickets"></tbody></table></div>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const ORIGIN = location.origin + location.pathname.replace(/[^\/]*$/, '');
const stColor = { success:'green', failed:'red', refunded:'yellow', pending:'gray',
  closed:'green', resolved:'green', open:'blue', in_progress:'yellow', waiting_customer:'yellow' };

async function load() {
  const { data } = await apiGet('integrations.php');
  const firstKey = (data.tokens[0]||{}).token || 'YOUR_API_KEY';

  document.getElementById('keys').innerHTML = data.tokens.map(t => `
    <tr><td class="font-medium">${esc(t.label)}</td>
      <td class="font-mono text-[11px]">${esc(t.token)}
        <button onclick="navigator.clipboard.writeText('${esc(t.token)}');toast('Copied')" class="text-gray-400 hover:text-indigo-600 ml-1"><i class="fa-regular fa-copy"></i></button></td>
      <td class="text-gray-500 text-[10px]">${esc(t.scopes)}</td>
      <td class="text-gray-400">${esc(t.last_used_at||'never')}</td>
      <td class="rowact"><button onclick="regen(${t.id})" class="text-amber-600 hover:underline text-[11px]">Rotate</button></td></tr>`).join('');

  document.getElementById('catalog').innerHTML = data.catalog.map(c => {
    const url = ORIGIN + c.path;
    const curl = `curl -X POST '${url}' \\\n  -H 'X-API-Key: ${firstKey}' \\\n  -H 'Content-Type: application/json' \\\n  -d '${JSON.stringify(c.body)}'`;
    return `<div class="border rounded-lg">
      <div class="flex items-center gap-2 px-2 py-1.5 border-b bg-gray-50">
        ${badge(c.method,'indigo')}<span class="font-semibold text-xs">${esc(c.name)}</span>
        <span class="font-mono text-[10px] text-gray-500">${esc(c.path)}</span></div>
      <div class="p-2 text-[11px] text-gray-600">${esc(c.desc)}</div>
      <pre class="bg-gray-900 text-green-300 text-[10px] rounded m-2 p-2 overflow-x-auto">${esc(curl)}</pre>
    </div>`;
  }).join('');

  document.getElementById('txns').innerHTML = data.recent_transactions.map(t => `
    <tr><td class="text-gray-400">${esc(String(t.txn_date||'').slice(0,16))}</td>
      <td>${esc(t.legal_name||t.merchant_code||'#'+t.merchant_id)}</td>
      <td class="font-mono text-[11px]">${esc(t.txn_ref)}</td>
      <td class="font-semibold">${inr(t.amount)}</td>
      <td>${badge(t.status, stColor[t.status]||'gray')}</td></tr>`).join('')
    || '<tr><td colspan="5" class="text-gray-400 text-center py-2">No transactions received yet</td></tr>';

  document.getElementById('tickets').innerHTML = data.recent_tickets.map(t => `
    <tr><td class="font-mono text-[11px]">${esc(t.ticket_ref)}</td>
      <td>${esc(t.subject||'')}</td>
      <td>${badge(t.status||'—', stColor[t.status]||'gray')}</td>
      <td class="text-gray-600">${esc(t.assigned_to_name||'—')}</td>
      <td>${t.waiting_on?badge('waiting: '+t.waiting_on, t.waiting_on==='us'?'red':'yellow'):'—'}</td></tr>`).join('')
    || '<tr><td colspan="5" class="text-gray-400 text-center py-2">No tickets received yet</td></tr>';
}

async function regen(id){ if(!confirm('Rotate this key? Old key stops working immediately.'))return;
  const r = await apiSend('integrations.php?regenerate='+id, {}); if(r.success){toast('Key rotated');load();} }
function newKey(){ openModal(`<form onsubmit="saveKey(event)" class="space-y-3 text-xs">
    <input name="label" placeholder="Key label (e.g. Payment Gateway prod)" required class="border rounded px-2 py-1.5 w-full">
    <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Create</button></div>
  </form>`, 'New API Key'); }
async function saveKey(e){ e.preventDefault(); const fd=Object.fromEntries(new FormData(e.target).entries());
  const r=await apiSend('integrations.php?token',fd); if(r.success){toast('Key created');closeModal();load();} }

load();
</script>
</html>
