<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Compliance & Certifications');
sales_header($me, 'Compliance & Certifications');
sales_nav('compliance');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <button onclick="newItem()" class="btn-p"><i class="fa-solid fa-plus"></i> New policy / compliance / certification</button>
    <span class="text-gray-300">|</span>
    <select id="fType" class="ctl" onchange="load()">
      <option value="">All types</option>
      <option value="policy">Policies</option><option value="compliance">Compliances</option>
      <option value="certification">Certifications</option><option value="license">Licenses</option>
    </select>
    <select id="fState" class="ctl" onchange="load()">
      <option value="">All states</option>
      <option value="expiring">Expiring soon</option><option value="expired">Expired</option>
      <option value="archived">Archived</option>
    </select>
    <input id="fQ" class="ctl" placeholder="Search title / RBI / authority…" oninput="debounceLoad()">
    <div class="flex-1"></div>
    <button onclick="runReminders()" class="text-[11px] text-indigo-600 hover:underline" title="Notify stakeholders of everything in its reminder window">
      <i class="fa-solid fa-bell"></i> Run reminders now</button>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Policy no.</th><th>Title</th><th>Type</th><th>Category / Authority</th><th>Owner</th>
        <th>Valid till</th><th>Days left</th><th>Renewal</th><th class="text-right">Stakeholders</th><th></th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<style>.gcode{font-family:ui-monospace,Consolas,monospace;font-size:10px;font-weight:700;background:#dcfce7;color:#166534;border-radius:5px;padding:1px 5px;white-space:nowrap}</style>
<style>
  .kstat { border:1px solid #e5e7eb; border-radius:10px; padding:8px 12px; background:#fff; min-width:138px; }
  .kstat .kt { font-size:10px; color:#9ca3af; }
  .kstat .kn { font-size:18px; font-weight:700; color:#1f2937; line-height:1.15; }
  .kstat .ks { font-size:10px; color:#9ca3af; }
</style>
<?php sales_scripts(); ?>
<script>
let employees = [], groups = [], _t = null;
const TYPE_BADGE = { policy:['Policy','blue'], compliance:['Compliance','purple'], certification:['Certification','teal'], license:['License','yellow'] };
const FREQ = { once:'One-time', monthly:'Monthly', quarterly:'Quarterly', half_yearly:'Half-yearly', yearly:'Yearly', custom:'Custom' };
// RBI / payments presets the user can start from with one click
const PRESETS = [
  { title:'RBI PA/PG Authorisation', item_type:'license', category:'RBI', authority:'Reserve Bank of India', reminder_days:90, renewal_frequency:'yearly' },
  { title:'PCI-DSS Certification', item_type:'certification', category:'PCI', authority:'PCI SSC / QSA', reminder_days:60, renewal_frequency:'yearly' },
  { title:'KYC / AML Policy Review', item_type:'policy', category:'RBI', authority:'Internal — Board approved', reminder_days:30, renewal_frequency:'yearly' },
  { title:'Cybersecurity Audit (SAR)', item_type:'compliance', category:'RBI', authority:'CERT-In empanelled auditor', reminder_days:45, renewal_frequency:'yearly' },
  { title:'IS Audit Report', item_type:'compliance', category:'RBI', authority:'CISA auditor', reminder_days:45, renewal_frequency:'yearly' },
];

function debounceLoad(){ clearTimeout(_t); _t = setTimeout(load, 300); }

async function loadStats(){
  const { data:s } = await apiGet('compliance.php', { stats:1 });
  document.getElementById('kpis').innerHTML = [
    ['Registry items', s.total||0],
    ['Expiring soon', s.expiring||0, (s.expiring||0)>0],
    ['EXPIRED', s.expired||0, (s.expired||0)>0],
    ['Policies', s.policies||0], ['Compliances', s.compliances||0],
    ['Certifications', s.certifications||0], ['Licenses', s.licenses||0],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');
}

async function load(){
  const p = { list:1 };
  const g = id=>document.getElementById(id).value;
  if (g('fType')) p.type = g('fType');
  if (g('fState')) p.state = g('fState');
  if (g('fQ')) p.q = g('fQ');
  const { data } = await apiGet('compliance.php', p);
  document.getElementById('rows').innerHTML = (data||[]).map(r=>{
    const [tl,tc] = TYPE_BADGE[r.item_type]||[r.item_type,'gray'];
    const dl = r.days_left;
    const dlHtml = r.valid_till==null ? '<span class="text-gray-400">—</span>'
      : r.state==='expired' ? `<b class="text-red-600">${Math.abs(dl)}d overdue</b>`
      : r.state==='expiring' ? `<b class="text-amber-600">${dl}d</b>`
      : `${dl}d`;
    return `<tr class="${r.state==='expired'?'bg-red-50/50':r.state==='expiring'?'bg-amber-50/40':''}">
      <td><span class="gcode">${esc(r.policy_no||('POL-'+r.id))}</span><span class="text-[9px] text-gray-400 ml-1">v${r.version||1}</span></td>
      <td class="font-medium"><a href="javascript:viewItem(${r.id})" class="text-indigo-600 hover:underline">${esc(r.title)}</a>
        ${r.reference_no?`<div class="text-[10px] text-gray-400">${esc(r.reference_no)}</div>`:''}</td>
      <td>${badge(tl,tc)}</td>
      <td class="text-[11px]">${esc(r.category||'—')}<div class="text-[10px] text-gray-400">${esc(r.authority||'')}</div></td>
      <td class="text-[11px]">${esc(r.owner_group_name||'')}${r.owner_group_name&&r.owner_name?' · ':''}${esc(r.owner_name||'')||(r.owner_group_name?'':'—')}</td>
      <td class="text-[11px] ${r.state==='expired'?'text-red-600 font-semibold':''}">${esc(r.valid_till||'no expiry')}</td>
      <td>${dlHtml}</td>
      <td class="text-[11px] text-gray-500">${FREQ[r.renewal_frequency]||''}<div class="text-[10px] text-gray-400">remind ${r.reminder_days}d before</div></td>
      <td class="text-right">${r.stakeholder_count}</td>
      <td class="rowact whitespace-nowrap">
        ${r.status==='active'?`
          <button onclick="renewItem(${r.id})" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Renew</button>
          <button onclick="remindItem(${r.id})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded" title="Notify stakeholders now">Remind</button>
          <button onclick="editItem(${r.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Edit</button>`:''}
      </td></tr>`;
  }).join('') || '<tr><td colspan="9" class="text-center text-gray-400 py-4">Nothing in the registry yet — add your RBI policies, certifications and licenses.</td></tr>';
}

async function pickers(){
  if (!employees.length) employees = (await apiGet('compliance.php', { employees:1 })).data || [];
  if (!groups.length)    groups    = (await apiGet('compliance.php', { groups:1 })).data || [];
}

function itemForm(r){
  r = r || {};
  const empOpts = sel => '<option value="">—</option>' + employees.map(e=>`<option value="${e.id}" ${sel==e.id?'selected':''}>${esc(e.full_name)}</option>`).join('');
  const grpOpts = sel => '<option value="">—</option>' + groups.map(g=>`<option value="${g.id}" ${sel==g.id?'selected':''}>${esc(g.name)}</option>`).join('');
  return `
    ${!r.id?`<div class="mb-2 flex flex-wrap gap-1">${PRESETS.map((p,i)=>
      `<button type="button" onclick='usePreset(${i})' class="text-[10px] bg-indigo-50 text-indigo-700 rounded px-2 py-0.5 hover:bg-indigo-100">${esc(p.title)}</button>`).join('')}</div>`:''}
    <div class="grid grid-cols-2 gap-2 text-xs">
      <label class="block col-span-2">Title<input name="title" required value="${esc(r.title||'')}" class="ctl w-full mt-1" placeholder="e.g. RBI PA/PG Authorisation"></label>
      <label class="block">Type<select name="item_type" class="ctl w-full mt-1">
        ${Object.entries(TYPE_BADGE).map(([k,[v]])=>`<option value="${k}" ${r.item_type===k?'selected':''}>${v}</option>`).join('')}</select></label>
      <label class="block">Category<input name="category" value="${esc(r.category||'')}" class="ctl w-full mt-1" placeholder="RBI / PCI / GST / custom"></label>
      <label class="block">Reference no.<input name="reference_no" value="${esc(r.reference_no||'')}" class="ctl w-full mt-1"></label>
      <label class="block">Authority<input name="authority" value="${esc(r.authority||'')}" class="ctl w-full mt-1" placeholder="Reserve Bank of India"></label>
      <label class="block">Issued on<input name="issued_on" type="date" value="${r.issued_on||''}" class="ctl w-full mt-1"></label>
      <label class="block">Valid till<input name="valid_till" type="date" value="${r.valid_till||''}" class="ctl w-full mt-1"></label>
      <label class="block">Remind (days before expiry)<input name="reminder_days" type="number" min="1" value="${r.reminder_days||30}" class="ctl w-full mt-1"></label>
      <label class="block">Renewal cycle<select name="renewal_frequency" class="ctl w-full mt-1">
        ${Object.entries(FREQ).map(([k,v])=>`<option value="${k}" ${ (r.renewal_frequency||'yearly')===k?'selected':''}>${v}</option>`).join('')}</select></label>
      <label class="block">Owner group<select name="owner_group_id" class="ctl w-full mt-1">${grpOpts(r.owner_group_id)}</select></label>
      <label class="block">Owner (person)<select name="owner_employee_id" class="ctl w-full mt-1">${empOpts(r.owner_employee_id)}</select></label>
      <label class="block col-span-2">Document link<input name="doc_url" value="${esc(r.doc_url||'')}" class="ctl w-full mt-1" placeholder="https://… (policy doc / certificate)"></label>
      <label class="block col-span-2">Description<textarea name="description" rows="2" class="ctl w-full mt-1 py-1">${esc(r.description||'')}</textarea></label>
    </div>`;
}
function usePreset(i){
  const p = PRESETS[i], f = document.querySelector('#modalBody form');
  f.title.value = p.title; f.item_type.value = p.item_type; f.category.value = p.category;
  f.authority.value = p.authority; f.reminder_days.value = p.reminder_days; f.renewal_frequency.value = p.renewal_frequency;
}

async function newItem(){
  await pickers();
  openModal(`<form onsubmit="saveItem(event, null)">${itemForm()}
    <div class="mt-2 text-xs">
      <div class="text-[10px] uppercase text-gray-400 mb-1">Stakeholders (notified on expiry reminders)</div>
      <div id="stakeRows" class="space-y-1"></div>
      <button type="button" onclick="addStakeRow()" class="text-indigo-600 text-[11px] mt-1"><i class="fa-solid fa-plus"></i> Add stakeholder</button>
    </div>
    <div class="flex justify-end gap-2 pt-3 text-xs">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Create</button>
    </div></form>`, 'New compliance item');
  addStakeRow();
}
function addStakeRow(){
  const d = document.createElement('div');
  d.className = 'flex gap-1 items-center stake-row';
  d.innerHTML = `<select class="ctl flex-1 st-emp"><option value="">Employee…</option>
      ${employees.map(e=>`<option value="${e.id}">${esc(e.full_name)}</option>`).join('')}</select>
    <input class="ctl w-32 st-role" placeholder="role e.g. SPOC">
    <button type="button" onclick="this.parentNode.remove()" class="text-gray-400 hover:text-red-600"><i class="fa-solid fa-xmark"></i></button>`;
  document.getElementById('stakeRows').appendChild(d);
}
function collectForm(f){
  return { title:f.title.value, item_type:f.item_type.value, category:f.category.value,
    reference_no:f.reference_no.value, authority:f.authority.value, issued_on:f.issued_on.value,
    valid_till:f.valid_till.value, reminder_days:+f.reminder_days.value, renewal_frequency:f.renewal_frequency.value,
    owner_group_id:f.owner_group_id.value, owner_employee_id:f.owner_employee_id.value,
    doc_url:f.doc_url.value, description:f.description.value };
}
async function saveItem(e, id){
  e.preventDefault(); const f = e.target;
  const body = collectForm(f);
  if (!id) body.stakeholders = [...document.querySelectorAll('.stake-row')].map(r=>({
      employee_id:+r.querySelector('.st-emp').value, role:r.querySelector('.st-role').value||'stakeholder'
    })).filter(s=>s.employee_id);
  const res = id ? await apiSend('compliance.php?id='+id, body, 'PATCH') : await apiSend('compliance.php', body);
  if (res.success){ closeModal(); toast(id?'Saved':'Added to the registry'); load(); loadStats(); }
  else toast(res.error||'Failed', false);
}
async function editItem(id){
  await pickers();
  const { data } = await apiGet('compliance.php', { id });
  openModal(`<form onsubmit="saveItem(event, ${id})">${itemForm(data)}
    <div class="flex justify-between gap-2 pt-3 text-xs">
      <button type="button" onclick="archiveItem(${id})" class="px-3 py-1.5 border border-red-200 text-red-600 rounded">Archive</button>
      <span class="flex gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Save</button></span>
    </div></form>`, 'Edit — ' + data.title);
}

async function viewItem(id){
  const { data:r } = await apiGet('compliance.php', { id });
  const [tl,tc] = TYPE_BADGE[r.item_type]||[r.item_type,'gray'];
  const row = (k,v)=>`<div class="flex justify-between border-b border-gray-100 py-1"><span class="text-gray-500">${k}</span><span class="font-medium text-right">${v}</span></div>`;
  openModal(`
    <div class="text-xs space-y-0.5">
      ${row('Type', badge(tl,tc)+' '+esc(r.category||''))}
      ${row('Authority', esc(r.authority||'—'))}
      ${r.reference_no?row('Reference', esc(r.reference_no)):''}
      ${row('Validity', esc(r.issued_on||'?')+' → '+esc(r.valid_till||'no expiry'))}
      ${row('State', r.state==='expired'?'<b class="text-red-600">EXPIRED</b>':r.state==='expiring'?'<b class="text-amber-600">Expiring — '+r.days_left+'d left</b>':'Active')}
      ${row('Renewal', (FREQ[r.renewal_frequency]||'')+' · remind '+r.reminder_days+'d before')}
      ${row('Owner', esc([r.owner_group_name, r.owner_name].filter(Boolean).join(' · ')||'—'))}
      ${r.doc_url?row('Document', '<a class="text-indigo-600 hover:underline" target="_blank" href="'+esc(r.doc_url)+'">open ↗</a>'):''}
      ${r.description?`<p class="text-gray-500 pt-1">${esc(r.description)}</p>`:''}
    </div>
    <div class="text-[10px] uppercase text-gray-400 mt-3 mb-1">Stakeholders</div>
    <div class="flex flex-wrap gap-1">${(r.stakeholders||[]).map(s=>
      `<span class="text-[10px] bg-indigo-50 text-indigo-700 rounded px-2 py-0.5">${esc(s.full_name)} · ${esc(s.role)}</span>`).join('') || '<span class="text-[10px] text-gray-400">none</span>'}</div>
    <div class="text-[10px] uppercase text-gray-400 mt-3 mb-1">History</div>
    <div class="space-y-1 text-[11px] max-h-40 overflow-y-auto">
      ${(r.log||[]).map(l=>`<div class="flex gap-2"><span class="text-gray-400 whitespace-nowrap">${esc((l.created_at||'').slice(0,16))}</span>
        <span>${badge(l.action,'gray')} ${esc(l.actor_name||'system')} ${l.notes?'— '+esc(l.notes):''}</span></div>`).join('') || '<span class="text-gray-400">—</span>'}
    </div>`, r.title);
}

async function renewItem(id){
  const { data:r } = await apiGet('compliance.php', { id });
  const today = new Date().toISOString().slice(0,10);
  openModal(`<form onsubmit="saveRenew(event, ${id})" class="space-y-2 text-xs">
    <p class="text-gray-500">Record the completed renewal of <b>${esc(r.title)}</b>. Reminders reset and any open renewal task is closed.</p>
    <label class="block">Renewed / issued on<input name="issued_on" type="date" value="${today}" class="ctl w-full mt-1"></label>
    <label class="block">New valid till<input name="valid_till" type="date" required class="ctl w-full mt-1"></label>
    <label class="block">Notes<input name="notes" class="ctl w-full mt-1" placeholder="e.g. renewed via RBI portal, ack no…"></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Record renewal</button></div></form>`, 'Renew — ' + r.title);
}
async function saveRenew(e, id){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('compliance.php?action=renew&id='+id, { issued_on:f.issued_on.value, valid_till:f.valid_till.value, notes:f.notes.value });
  if (res.success){ closeModal(); toast('Renewal recorded'); load(); loadStats(); } else toast(res.error||'Failed', false);
}
async function archiveItem(id){
  if (!confirm('Archive this item? It will stop generating reminders.')) return;
  const res = await apiSend('compliance.php?action=archive&id='+id, {});
  if (res.success){ closeModal(); toast('Archived'); load(); loadStats(); } else toast(res.error||'Failed', false);
}
async function remindItem(id){
  const res = await apiSend('compliance.php?action=remind&id='+id, {});
  if (res.success){ toast('Reminder sent to '+res.data.notified+' people'); } else toast(res.error||'Failed', false);
}
async function runReminders(){
  const res = await apiSend('compliance.php?action=run_reminders', {});
  if (res.success){ const s=res.data; toast(`Sweep done — ${s.reminded} item(s), ${s.notified} notification(s)`); load(); }
  else toast(res.error||'Failed', false);
}

loadStats(); load();
</script>
</html>
