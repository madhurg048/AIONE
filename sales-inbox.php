<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('AI Inbox');
sales_header($me, 'AI Inbox');
sales_nav('inbox');
?>
<main class="p-2 space-y-2">
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase mr-1">AI Inbox</span>
    <select id="status" class="ctl" onchange="load()"><option value="new">New</option><option value="replied">Replied</option><option value="archived">Archived</option></select>
    <div class="flex-1"></div>
    <button onclick="fetchNew()" class="btn-p"><i class="fa-solid fa-download"></i> Fetch new</button>
    <a href="sales-settings.php" class="ctl hover:bg-gray-50"><i class="fa-solid fa-gear"></i> Mailboxes</a>
  </div>
  <div id="list" class="space-y-2"></div>
</main>

<?php sales_scripts(); ?>
<script>
const catColor = { new_enquiry:'green', lead_reply:'indigo', support:'blue', complaint:'red', spam:'gray', other:'gray' };
async function load(){
  const { data } = await apiGet('mailbox.php', { inbox: 1, status: document.getElementById('status').value });
  document.getElementById('list').innerHTML = (data||[]).map(e => card(e)).join('')
    || '<div class="card p-6 text-center text-gray-400 text-sm">No emails. Click <b>Fetch new</b> to pull from your mailboxes.</div>';
}
function card(e){
  const triaged = e.triaged == 1;
  return `<div class="card p-3" id="em${e.id}">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <div class="text-sm font-semibold text-gray-800 truncate">${esc(e.subject||'(no subject)')}</div>
        <div class="text-[11px] text-gray-500">${esc(e.from_name||'')} &lt;${esc(e.from_email)}&gt; · ${esc(String(e.received_at||'').slice(0,16))}
          ${e.lead_id?`· <a href="sales-leads.php" class="text-indigo-600">🔗 ${esc(e.lead_name||'lead')}</a> ${e.lead_status?`<span class="text-gray-400">(${esc(e.lead_status)})</span>`:''}`:'· <span class="text-amber-600">no matching lead</span>'}</div>
      </div>
      ${triaged?`<span class="text-[9px] bg-${catColor[e.ai_category]||'gray'}-100 text-${catColor[e.ai_category]||'gray'}-700 rounded px-1.5 py-0.5 whitespace-nowrap">${esc(e.ai_category||'')}</span>`:''}
    </div>
    <div class="text-xs text-gray-600 mt-1 line-clamp-2">${esc((e.snippet||'').slice(0,240))}</div>
    <div id="ai${e.id}">${triaged?aiBlock(e):`<button onclick="triage(${e.id})" class="mt-2 btn-p"><i class="fa-solid fa-wand-magic-sparkles"></i> Triage with AI</button>`}</div>
  </div>`;
}
function aiBlock(e){
  return `<div class="mt-2 border-t pt-2 space-y-2">
    <div class="text-xs"><span class="text-gray-400 uppercase text-[10px]">AI summary</span><br>${esc(e.ai_summary||'')}</div>
    ${e.ai_action?`<div class="text-xs bg-amber-50 border border-amber-200 rounded p-2"><i class="fa-solid fa-lightbulb text-amber-500"></i> <b>Suggested:</b> ${esc(e.ai_action)}</div>`:''}
    <div>
      <div class="text-[10px] text-gray-400 uppercase mb-1">Draft reply (edit before sending)</div>
      <textarea id="draft${e.id}" rows="4" class="border rounded px-2 py-1.5 w-full text-xs">${esc(e.ai_draft||'')}</textarea>
    </div>
    <div class="flex gap-2">
      <button onclick="sendReply(${e.id})" class="btn-p"><i class="fa-solid fa-paper-plane"></i> Send reply</button>
      <button onclick="triage(${e.id})" class="ctl hover:bg-gray-50"><i class="fa-solid fa-rotate"></i> Re-triage</button>
      <button onclick="archive(${e.id})" class="ctl hover:bg-gray-50">Archive</button>
    </div>
  </div>`;
}
async function fetchNew(){
  toast('Reading mailboxes…');
  const r = await apiSend('mailbox.php?fetch', {});
  if (r.success){ toast(r.message||'Fetched'); load(); } else toast(r.error||'Fetch failed', false);
}
async function triage(id){
  const el = document.getElementById('ai'+id); el.innerHTML = '<div class="mt-2 text-xs text-gray-400">AI reading the email…</div>';
  const r = await apiSend('mailbox.php?triage&id='+id, {});
  if (r.success){ el.innerHTML = aiBlock({id, ai_summary:r.data.summary, ai_category:r.data.category, ai_action:r.data.suggested_action, ai_draft:r.data.draft_reply}); }
  else { el.innerHTML = `<div class="mt-2 text-xs text-red-500">${esc(r.error||'failed')}</div>`; }
}
async function sendReply(id){
  const reply = document.getElementById('draft'+id).value.trim(); if(!reply) return toast('Draft is empty', false);
  const r = await apiSend('mailbox.php?reply&id='+id, { reply });
  if (r.success){ toast(r.message||'Sent'); load(); } else toast(r.error||'Send failed', false);
}
async function archive(id){ await apiSend('mailbox.php?archive&id='+id, {}); document.getElementById('em'+id)?.remove(); }
load();
</script>
</html>
