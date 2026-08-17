<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Leads');
sales_header($me, 'Sales · Leads');
sales_nav('leads');
?>
<main class="p-2 space-y-2">

  <!-- ===== my role-level sales goal ===== -->
  <?php sales_goal_widget(); ?>

  <!-- ===== compact KPI strip ===== -->
  <div id="kstrip" class="kstrip"></div>

  <!-- ===== AI insight (collapsible, evidence-backed) ===== -->
  <div data-ai-scope="leads"></div>

  <!-- ===== LEADS LIST ===== -->
  <div class="card">
    <!-- toolbar -->
    <div class="flex items-center gap-2 p-2 border-b flex-wrap">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mr-1">Leads</span>
      <input id="q" placeholder="Search name / company / phone…" class="ctl w-56" oninput="liveSearch()" onkeyup="if(event.key==='Enter')goPage(1)">
      <select id="fStatus" class="ctl" onchange="goPage(1)">
        <option value="">All status</option>
        <option>new</option><option>contacted</option><option>qualified</option>
        <option>unqualified</option><option>converted</option>
      </select>
      <select id="fSource" class="ctl" onchange="goPage(1)">
        <option value="">All sources</option>
        <option>web</option><option>whatsapp</option><option>call</option>
        <option>referral</option><option>campaign</option><option>manual</option><option>chatbot</option>
      </select>
      <button id="dueChip" onclick="toggleDue()" class="ctl flex items-center gap-1">
        <i class="fa-solid fa-bell text-amber-500"></i> Due <span id="dueCount" class="bg-gray-100 text-gray-500 rounded-full px-1.5 text-[10px]">0</span>
      </button>
      <div class="flex-1"></div>
      <span id="assignCtl"></span>
      <button onclick="openLeadForm()" class="btn-p"><i class="fa-solid fa-plus"></i> New Lead</button>
    </div>

    <!-- bulk action bar (hidden until rows selected) -->
    <div id="bulkbar" class="bulkbar hidden flex items-center gap-2 px-3 py-1.5 m-2 text-xs">
      <span><b id="bulkCount">0</b> selected</span>
      <span class="opacity-50">|</span>
      <select id="bulkStatusVal" class="text-gray-800 ctl" style="height:24px">
        <option value="">Set status…</option>
        <option>new</option><option>contacted</option><option>qualified</option><option>unqualified</option>
      </select>
      <button onclick="bulkStatus()">Apply</button>
      <span class="opacity-50">|</span>
      <select id="bulkOwnerVal" class="text-gray-800 ctl" style="height:24px"><option value="">Assign owner…</option></select>
      <button onclick="bulkOwner()">Assign</button>
      <span class="opacity-50">|</span>
      <input id="bulkDate" type="date" class="text-gray-800 ctl" style="height:24px">
      <button onclick="bulkFollowupDate()">Set follow-up</button>
      <span class="opacity-50">|</span>
      <button onclick="bulkDelete()" class="!bg-red-500/80 hover:!bg-red-600">Delete</button>
      <div class="flex-1"></div>
      <button onclick="selClear();load()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- table -->
    <div class="overflow-x-auto" style="max-height:calc(100vh - 250px)">
      <table class="dense">
        <thead>
          <tr>
            <th style="width:26px"><input type="checkbox" class="chk" onclick="allToggle(this)"></th>
            <th class="sortable" onclick="sortBy('ticket_ref')">Ticket / Ref</th>
            <th class="sortable" onclick="sortBy('last_name')">Name</th>
            <th class="sortable" onclick="sortBy('company')">Company</th>
            <th>Contact</th>
            <th>Subject</th>
            <th class="sortable" onclick="sortBy('lead_source')">Source</th>
            <th class="sortable" onclick="sortBy('lead_status')">Status</th>
            <th class="sortable" onclick="sortBy('score')">Score</th>
            <th class="sortable" onclick="sortBy('next_followup_at')">Next F/U</th>
            <th class="sortable" onclick="sortBy('created_at')">Created</th>
            <th>Owner</th>
            <th style="width:120px">Actions</th>
          </tr>
        </thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
    <div id="pager" class="p-1"></div>
  </div>

  <!-- ===== secondary: source performance + leaderboard (compact) ===== -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <div class="card p-2">
      <div class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Source Performance</div>
      <div id="bySource" class="space-y-1 text-[11px]"></div>
    </div>
    <div class="card p-2 lg:col-span-2">
      <div class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Agent Leaderboard</div>
      <table class="dense"><thead><tr>
        <th>Agent</th><th>Leads</th><th>Converted</th><th>Merchants</th><th>Conv %</th>
      </tr></thead><tbody id="leaderboard"></tbody></table>
    </div>
  </div>
</main>

<!-- modal -->
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
// Default: newest chat/ticket reference first — same ordering the Service Requests/Tickets
// screens use, so a rep can find the request they just took by its ref at the top of the list.
let page = 1, perPage = 20, pages = 1, sortCol = 'ticket_ref', sortDir = 'desc';
let EMP = [], LEADS = [];

/* ---- KPI strip ---- */
async function loadMetrics() {
  const { data: m } = await apiGet('leads.php', { metrics: 1, vertical: 'sales' });
  const f = m.funnel;
  let waUnread = 0;
  try {
    const { data: wa } = await apiGet('whatsapp-chat.php', { action: 'inbox' });
    waUnread = (wa.threads || []).filter(t => t.unread_count > 0).length;
  } catch (e) {}
  const kpis = [
    ['Total', f.leads], ['New (mo)', m.new_this_month], ['Qualified', f.qualified],
    ['Converted', f.converted], ['Conv. rate', m.conversion_rate + '%'],
    ['Merchants', f.merchants], ['Follow-ups due', m.due_followups || 0],
    ['WhatsApp unread', waUnread],
  ];
  document.getElementById('kstrip').innerHTML = kpis.map(([l, v], i) =>
    `<div><div class="kv ${(l==='Follow-ups due'||l==='WhatsApp unread')&&v>0?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  const dc = document.getElementById('dueCount');
  dc.textContent = m.due_followups || 0;
  dc.className = 'rounded-full px-1.5 text-[10px] ' + (m.due_followups > 0 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500');

  const maxSrc = Math.max(1, ...m.by_source.map(s => +s.total));
  document.getElementById('bySource').innerHTML = m.by_source.map(s => {
    const conv = s.total > 0 ? Math.round(s.converted * 100 / s.total) : 0;
    return `<div><div class="flex justify-between"><span class="capitalize">${esc(s.source)}</span>
      <span class="text-gray-400">${s.converted}/${s.total} · ${conv}%</span></div>
      <div class="h-1.5 bg-gray-100 rounded"><div class="h-1.5 bg-indigo-500 rounded" style="width:${Math.round(s.total*100/maxSrc)}%"></div></div></div>`;
  }).join('') || '<div class="text-gray-400">No data</div>';

  document.getElementById('leaderboard').innerHTML = m.leaderboard.map(a => {
    const conv = a.leads > 0 ? Math.round(a.converted * 100 / a.leads) : 0;
    return `<tr><td class="font-medium">${esc(a.full_name)}</td><td>${a.leads}</td><td>${a.converted}</td>
      <td class="font-semibold text-indigo-600">${a.merchants}</td><td>${conv}%</td></tr>`;
  }).join('') || '<tr><td colspan="5" class="text-gray-400 text-center py-2">No agents yet</td></tr>';
}

/* ---- list ---- */
// A non-technical rep/manager has no way to know a lead auto-reassigns to someone else after
// N idle days (a backend cron rule) unless we surface it here. Only warns once it's genuinely
// close (<=3 days left) so the badge isn't noise on every ordinary new/contacted lead.
function staleBadge(l) {
  if (l.stale_days_left === null || l.stale_days_left === undefined) return '';
  const left = l.stale_days_left;
  if (left > 3) return '';
  const urgent = left <= 1;
  const label = left <= 0 ? 'reassigns today' : `reassigns in ${left}d`;
  return ` <span class="inline-flex items-center gap-1 ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium ${urgent ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'}"
    title="No activity in ${l.stale_idle_days} days — this lead auto-reassigns to another rep if it stays untouched. Log a follow-up to reset it.">
    <i class="fa-solid fa-triangle-exclamation"></i> ${label}</span>`;
}
function fmtNext(dt) {
  if (!dt) return '<span class="text-gray-300">—</span>';
  const d = new Date(dt.replace(' ', 'T')), s = dt.slice(0, 16).replace('T', ' ');
  return d <= new Date() ? `<span class="text-red-600 font-semibold"><i class="fa-solid fa-bell"></i> ${esc(s)}</span>`
                         : `<span class="text-gray-600">${esc(s)}</span>`;
}
let dueOnly = false;
function toggleDue() {
  dueOnly = !dueOnly;
  document.getElementById('dueChip').className = 'ctl flex items-center gap-1 ' + (dueOnly ? 'border-red-400 bg-red-50 text-red-700' : '');
  goPage(1);
}
function sortBy(c) { if (sortCol === c) sortDir = sortDir === 'asc' ? 'desc' : 'asc'; else { sortCol = c; sortDir = 'asc'; } load(); }
function goPage(p, per) { if (per) perPage = +per; page = Math.max(1, p || 1); load(); }
let _searchT; function liveSearch() { clearTimeout(_searchT); _searchT = setTimeout(() => goPage(1), 300); }

async function load() {
  const params = { page, per: perPage, sort: sortCol, dir: sortDir, vertical: 'sales' };
  if (q.value) params.q = q.value;
  if (fStatus.value) params.status = fStatus.value;
  if (fSource.value) params.source = fSource.value;
  if (dueOnly) params.due = 1;
  const res = await apiGet('leads.php', params);
  LEADS = res.data || [];
  pages = res.pages || 1;
  const sc = { new: 'blue', contacted: 'yellow', qualified: 'indigo', unqualified: 'gray', converted: 'green' };
  const SUBJECT_LABEL = { service: 'Service Request', transaction: 'Transaction Issue', callback: 'Callback Request', sales: 'Sales Enquiry' };
  document.getElementById('rows').innerHTML = res.data.map(l => `
    <tr>
      <td><input type="checkbox" class="chk rowchk" data-id="${l.id}" onclick="rowToggle(this)" ${_sel.has(l.id)?'checked':''}></td>
      <td class="font-mono text-[11px] ${l.ticket_ref?'text-gray-600':'text-gray-300'}">${esc(l.ticket_ref || '—')}</td>
      <td class="font-medium">${esc(l.display_name || ((l.first_name||'')+' '+(l.last_name||'')))}</td>
      <td>${esc(l.display_company || l.company)}</td>
      <td class="text-gray-500">${esc(l.display_email || l.email || '')}${(l.phone||l.mobile)?`<br>
        <a href="tel:${(l.mobile||l.phone||'').replace(/[^0-9+]/g,'')}" class="text-indigo-600 hover:underline" title="Call from this device"><i class="fa-solid fa-phone text-[10px]"></i> ${esc(l.display_mobile || l.phone || l.mobile)}</a>
        <button onclick="callLead(${l.id})" title="Cloud call (provider bridges you & the customer)" class="text-blue-600 hover:text-blue-700 ml-1"><i class="fa-solid fa-headset text-[11px]"></i></button>
        <button onclick="waLead(${l.id})" title="WhatsApp chat" class="text-green-600 hover:text-green-700 ml-1"><i class="fa-brands fa-whatsapp"></i></button>`:''}</td>
      <td class="text-gray-600">${esc(l.ticket_subject || SUBJECT_LABEL[l.lead_source] || '—')}</td>
      <td class="capitalize">${esc(l.lead_source)}${isServiceHandoff(l) ? `
        <button onclick="viewHandoff(${l.id})" title="View the notes the Service team sent with this lead"
          class="ml-1 text-indigo-600 hover:text-indigo-800 align-middle"><i class="fa-solid fa-headset text-[10px]"></i><i class="fa-solid fa-note-sticky text-[10px] ml-0.5"></i></button>` : ''}</td>
      <td>${badge(l.lead_status, sc[l.lead_status]||'gray')}</td>
      <td>${l.score>0 ? badge(l.score, l.score>=70?'green':l.score>=40?'yellow':'gray') : '<span class="text-gray-300">—</span>'}</td>
      <td>${fmtNext(l.next_followup_at)}</td>
      <td class="text-gray-400 whitespace-nowrap">${esc(String(l.display_created_at||l.created_at||'').slice(0,16).replace('T',' '))}</td>
      <td class="text-gray-600">${esc(l.owner_name||'—')}${staleBadge(l)}</td>
      <td class="rowact">
        <button onclick="followUp(${l.id})" title="Follow-up" class="text-indigo-600 hover:text-indigo-800"><i class="fa-solid fa-clipboard-check"></i></button>
        <button onclick="waLead(${l.id})" title="WhatsApp" class="text-green-600 hover:text-green-700"><i class="fa-brands fa-whatsapp"></i></button>
        <button onclick="scoreLead(${l.id})" title="AI score" class="text-purple-600 hover:text-purple-800"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
        <button onclick="editLead(${l.id})" title="Edit" class="text-gray-500 hover:text-gray-800"><i class="fa-solid fa-pen"></i></button>
        ${l.is_converted==1 ? '<i class="fa-solid fa-circle-check text-green-500" title="converted"></i>'
          : `<button onclick="convertLead(${l.id})" title="Convert" class="text-green-600 hover:text-green-800"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>`}
      </td>
    </tr>`).join('') || '<tr><td colspan="13" class="text-center text-gray-400 py-4">No leads</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
}

/* ---- bulk actions ---- */
async function bulkStatus() {
  const v = document.getElementById('bulkStatusVal').value; if (!v) return toast('Pick a status', false);
  const r = await apiSend('leads.php?bulk=1&vertical=sales', { ids: selIds(), action: 'status', value: v });
  if (r.success) { toast(`${r.data.updated} updated`); selClear(); loadMetrics(); load(); }
}
async function bulkOwner() {
  const v = document.getElementById('bulkOwnerVal').value; if (!v) return toast('Pick an owner', false);
  const r = await apiSend('leads.php?bulk=1&vertical=sales', { ids: selIds(), action: 'owner', value: v });
  if (r.success) { toast(`${r.data.updated} reassigned`); selClear(); load(); }
}
async function bulkFollowupDate() {
  const v = document.getElementById('bulkDate').value; if (!v) return toast('Pick a date', false);
  const r = await apiSend('leads.php?bulk=1&vertical=sales', { ids: selIds(), action: 'followup_date', value: v + ' 10:00:00' });
  if (r.success) { toast(`${r.data.updated} scheduled`); selClear(); loadMetrics(); load(); }
}
async function bulkDelete() {
  if (!confirm(`Delete ${selIds().length} lead(s)?`)) return;
  const r = await apiSend('leads.php?bulk=1&vertical=sales', { ids: selIds(), action: 'delete' });
  if (r.success) { toast(`${r.data.deleted} deleted`); selClear(); loadMetrics(); load(); }
}

/* ---- create lead + custom fields ---- */
function openLeadForm() {
  openModal(`
    <form onsubmit="saveLead(event)" class="space-y-3 text-xs">
      <div class="grid grid-cols-2 gap-3">
        <input name="first_name" placeholder="First name" class="border rounded px-2 py-1.5">
        <input name="last_name" placeholder="Last name" class="border rounded px-2 py-1.5">
      </div>
      <input name="company" placeholder="Company" class="border rounded px-2 py-1.5 w-full" oninput="dupCheckSoon(this.form)">
      <div class="grid grid-cols-2 gap-3">
        <input name="email" placeholder="Email" class="border rounded px-2 py-1.5" oninput="dupCheckSoon(this.form)">
        <input name="phone" placeholder="Phone" class="border rounded px-2 py-1.5" oninput="dupCheckSoon(this.form)">
      </div>
      <div id="dupWarn"></div>
      <input name="subject" placeholder="Subject" class="border rounded px-2 py-1.5 w-full">
      <div class="grid grid-cols-2 gap-3">
        <select name="lead_source" class="border rounded px-2 py-1.5">
          <option value="web">Web</option><option value="whatsapp">WhatsApp</option><option value="call">Call</option>
          <option value="referral">Referral</option><option value="campaign">Campaign</option><option value="manual">Manual</option>
        </select>
        <input name="industry" placeholder="Industry" class="border rounded px-2 py-1.5">
      </div>
      <textarea name="description" placeholder="Notes" class="border rounded px-2 py-1.5 w-full" rows="2"></textarea>
      <div id="customFields" class="grid grid-cols-2 gap-3"></div>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save Lead</button>
      </div>
    </form>`, 'New Lead');
  loadCustomFields('lead', 'customFields');
}

/* ---- AI duplicate warning while typing a new lead (non-blocking) ---- */
let dupTimer = null, dupSeq = 0;
function dupCheckSoon(form) {
  clearTimeout(dupTimer);
  dupTimer = setTimeout(() => dupCheck(form), 500);
}
async function dupCheck(form) {
  const box = document.getElementById('dupWarn');
  if (!box) return;
  const company = form.company.value.trim(), email = form.email.value.trim(), phone = form.phone.value.trim();
  const name = (form.first_name.value + ' ' + form.last_name.value).trim();
  if (company.length < 3 && !email && phone.replace(/\D/g,'').length < 6) { box.innerHTML = ''; return; }
  const seq = ++dupSeq;
  const res = await apiGet('leads.php', { dupcheck: 1, company, email, phone, name });
  if (seq !== dupSeq || !res.success) return;      // a newer keystroke superseded this check
  const d = res.data;
  if (!d.matches || !d.matches.length) { box.innerHTML = ''; return; }
  box.innerHTML = `<div class="border border-amber-300 bg-amber-50 rounded-lg p-2.5 space-y-1.5">
    <div class="flex items-start gap-2 text-amber-800">
      <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
      <div class="font-medium">${esc(d.warning || 'Possible duplicate lead')}</div>
    </div>
    ${d.matches.map(m => `<div class="flex items-center gap-2 pl-6 text-[11px] text-amber-700">
      <span class="font-semibold">#${m.id}</span>
      <span>${esc(((m.first_name||'') + ' ' + (m.last_name||'')).trim() || '—')}</span>
      <span class="text-amber-500">@ ${esc(m.company || '—')}</span>
      ${badge(m.lead_status, m.lead_status === 'converted' ? 'green' : 'yellow')}
      <span class="text-amber-500">owner: ${esc(m.owner_name || 'unassigned')}</span>
    </div>`).join('')}
    <div class="pl-6 text-[10px] text-amber-600">You can still save — this is a warning, not a block.</div>
  </div>`;
}

/* ---- auto-assign switch (team leads / managers only) ---- */
async function loadAssignCtl() {
  const el = document.getElementById('assignCtl');
  if (!el) return;
  const res = await apiGet('leads.php', { assign_settings: 1 });
  if (!res.success || !res.data.can_manage) return;
  const d = res.data;
  window.__assignOn = d.auto_assign ? 1 : 0;
  el.innerHTML = `<span class="flex items-center gap-1.5 text-[11px] text-gray-500 border border-gray-200 rounded-lg px-2 py-1 bg-white">
    <i class="fa-solid fa-shuffle ${d.auto_assign ? 'text-indigo-500' : 'text-gray-300'}"></i>
    Auto-assign
    <select id="assignGroup" onchange="if(window.__assignOn)toggleAssign(1)" class="border rounded px-1 py-0.5 text-[11px] ${d.auto_assign ? '' : 'opacity-50'}">
      ${(d.groups||[]).map(g => `<option value="${g.id}" ${g.id == d.group_id ? 'selected' : ''}>${esc(g.name)} (${g.members})</option>`).join('')}
    </select>
    <button onclick="toggleAssign(${d.auto_assign ? 0 : 1})" title="${d.auto_assign ? 'ON — leads go to the least-loaded member of the group. Click to turn off.' : 'OFF — whoever brings a lead keeps it. Click to turn on.'}"
      class="rounded-full w-8 h-4 relative transition-all ${d.auto_assign ? 'bg-indigo-500' : 'bg-gray-300'}">
      <span class="absolute top-0.5 ${d.auto_assign ? 'right-0.5' : 'left-0.5'} w-3 h-3 bg-white rounded-full"></span>
    </button>
  </span>`;
}
async function toggleAssign(on) {
  const gid = document.getElementById('assignGroup')?.value || 0;
  const res = await apiSend('leads.php?action=assign_settings', { auto_assign: on, group_id: gid });
  if (res.success) { toast(on ? 'Auto-assign ON — new leads go to the least-loaded group member' : 'Auto-assign OFF — lead creators keep their own leads'); loadAssignCtl(); }
  else toast(res.error || 'Failed', false);
}
loadAssignCtl();
async function loadCustomFields(entity, containerId) {
  const { data: defs } = await apiGet('custom-fields.php', { entity });
  const el = document.getElementById(containerId);
  if (!el || !defs.length) return;
  el.innerHTML = defs.map(f => {
    const name = 'cf_' + f.field_key, req = f.is_required == 1 ? 'required' : '';
    if (f.field_type === 'select') return `<select name="${name}" ${req} class="border rounded px-2 py-1.5"><option value="">${esc(f.label)}</option>${(f.options||[]).map(o=>`<option>${esc(o)}</option>`).join('')}</select>`;
    if (f.field_type === 'checkbox') return `<label class="flex items-center gap-2"><input type="checkbox" name="${name}"> ${esc(f.label)}</label>`;
    if (f.field_type === 'textarea') return `<textarea name="${name}" placeholder="${esc(f.label)}" ${req} class="border rounded px-2 py-1.5 col-span-2"></textarea>`;
    const t = f.field_type === 'number' ? 'number' : f.field_type === 'date' ? 'date' : 'text';
    return `<input type="${t}" name="${name}" placeholder="${esc(f.label)}" ${req} class="border rounded px-2 py-1.5">`;
  }).join('');
}
async function saveLead(e) {
  e.preventDefault();
  const all = Object.fromEntries(new FormData(e.target).entries());
  const std = {}, custom = {};
  for (const [k, v] of Object.entries(all)) { if (k.startsWith('cf_')) custom[k.slice(3)] = v; else std[k] = v; }
  const res = await apiSend('leads.php', std);
  if (!res.success) return toast(res.error || 'Failed', false);
  if (Object.keys(custom).length) await apiSend('custom-fields.php?values=1', { entity: 'lead', entity_id: res.data.id, values: custom });
  toast('Lead created' + (res.automations && res.automations.length ? ' · ' + res.automations.length + ' automation(s)' : ''));
  closeModal(); loadMetrics(); load();
}

/* ---- AI score ---- */
async function scoreLead(id) {
  toast('AI scoring…');
  const res = await apiSend('ai.php', { action: 'score_lead', lead_id: id });
  if (!res.success) return toast(res.error || 'AI unavailable', false);
  const d = res.data;
  openModal(`<div class="space-y-3 text-xs">
    <div class="flex items-center gap-3"><div class="text-3xl font-bold ${d.score>=70?'text-green-600':d.score>=40?'text-yellow-600':'text-gray-500'}">${d.score ?? '—'}</div>
      <div>${badge((d.band||'').toUpperCase(), d.band==='hot'?'green':d.band==='warm'?'yellow':'gray')}</div></div>
    <div><div class="font-semibold text-gray-600 mb-1">Why</div><ul class="list-disc pl-5 space-y-0.5">${(d.reasons||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul></div>
    <div class="bg-indigo-50 rounded p-2"><b class="text-indigo-700">Next best action:</b> ${esc(d.next_best_action||'')}</div>
  </div>`, 'AI Lead Score');
  load();
}

/* ---- service → sales handoff notes ----
   A lead reaches Sales from the Service team either as a "generated lead" (source=service_referral,
   captured off a call/live chat/WhatsApp) or as a transferred ticket. In both cases the notes the
   Service exec wrote are stamped onto the linked ticket's last_update_note. Surface them here so
   the Sales rep gets the same context Service had, one click away. */
function isServiceHandoff(l) {
  return (l.lead_source === 'service_referral' || l.ticket_source === 'service_lead' || !!l.handoff_by)
      && (!!(l.handoff_note && String(l.handoff_note).trim()) || !!l.handoff_by);
}
function viewHandoff(id) {
  const l = LEADS.find(x => +x.id === +id);
  if (!l) return;
  const note = (l.handoff_note && String(l.handoff_note).trim()) ? esc(l.handoff_note).replace(/\n/g, '<br>')
             : '<span class="text-gray-400">No written note was added — see the request details.</span>';
  const meta = [
    l.handoff_by ? `Handed over by <b>${esc(l.handoff_by)}</b>` : '',
    l.ticket_ref ? `Ref ${esc(l.ticket_ref)}` : '',
    l.ticket_subject ? esc(l.ticket_subject) : '',
  ].filter(Boolean).join(' &nbsp;·&nbsp; ');
  openModal(`
    <div class="space-y-2 text-xs">
      <div class="flex items-center gap-2 text-indigo-700 font-semibold">
        <i class="fa-solid fa-headset"></i> Notes from the Service team
      </div>
      ${meta ? `<div class="text-[11px] text-gray-500">${meta}</div>` : ''}
      <div class="bg-amber-50 border border-amber-200 rounded p-3 text-gray-800 leading-relaxed whitespace-pre-wrap">${note}</div>
      <div class="flex justify-end pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Close</button></div>
    </div>`, 'Service Handoff Notes');
}

/* ---- edit ---- */
// Plain-language banner for the lead detail/edit modal — same signal as staleBadge() above,
// spelled out fully since this is where someone would actually act on it (log a follow-up
// via the Follow-up button, or just save an edit — either touches updated_at and resets it).
function staleBanner(l) {
  if (l.stale_days_left === null || l.stale_days_left === undefined || l.stale_days_left > 3) return '';
  const left = l.stale_days_left;
  const urgent = left <= 1;
  const when = left <= 0 ? 'today' : `in ${left} day${left === 1 ? '' : 's'}`;
  return `<div class="text-xs mb-3 px-3 py-2 rounded border ${urgent ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-700'}">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <strong>No activity in ${l.stale_idle_days} days.</strong>
    This lead will auto-reassign to another rep ${when} if it stays untouched. Log a follow-up to keep it.
  </div>`;
}
function editLead(id) {
  const l = LEADS.find(x => +x.id === +id);
  if (!l) return;
  openModal(`
    ${staleBanner(l)}
    <form onsubmit="saveLeadEdit(event, ${id})" class="space-y-3 text-xs">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-gray-500 mb-1">First name</label><input name="first_name" class="border rounded px-2 py-1.5 w-full" value="${esc(l.first_name||'')}"></div>
        <div><label class="block text-gray-500 mb-1">Last name</label><input name="last_name" class="border rounded px-2 py-1.5 w-full" value="${esc(l.last_name||'')}"></div>
      </div>
      <div><label class="block text-gray-500 mb-1">Company</label><input name="company" class="border rounded px-2 py-1.5 w-full" value="${esc(l.company||'')}"></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-gray-500 mb-1">Email</label><input name="email" type="email" class="border rounded px-2 py-1.5 w-full" value="${esc(l.email||'')}"></div>
        <div><label class="block text-gray-500 mb-1">Mobile</label><input name="mobile" class="border rounded px-2 py-1.5 w-full" value="${esc(l.mobile||'')}"></div>
      </div>
      <div><label class="block text-gray-500 mb-1">Description</label><textarea name="description" rows="3" class="border rounded px-2 py-1.5 w-full">${esc(l.description||'')}</textarea></div>
      <div class="text-[10px] text-gray-400 border-t pt-2">
        Created: ${esc(String(l.created_at||'').slice(0,16).replace('T',' ')) || '—'}
        &nbsp;·&nbsp; Last updated: ${esc(String(l.updated_at||'').slice(0,16).replace('T',' ')) || '—'}
      </div>
      <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save</button></div>
    </form>`, 'Edit Lead');
}
async function saveLeadEdit(e, id) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  const res = await apiSend('leads.php?id=' + id, fd, 'PATCH');
  if (res.success) { toast('Lead updated'); closeModal(); load(); } else toast(res.error || 'Update failed', false);
}

/* ---- convert ---- */
function convertLead(id) {
  openModal(`<form onsubmit="doConvert(event, ${id})" class="space-y-3 text-xs">
    <p class="text-gray-600">Converts to Account + Contact, and optionally opens a Deal.</p>
    <label class="flex items-center gap-2"><input type="checkbox" name="create_deal" checked> Create a deal</label>
    <input name="deal_name" placeholder="Deal name (optional)" class="border rounded px-2 py-1.5 w-full">
    <div class="grid grid-cols-2 gap-3">
      <input name="amount" type="number" step="0.01" placeholder="Amount (₹)" class="border rounded px-2 py-1.5">
      <input name="expected_close_date" type="date" class="border rounded px-2 py-1.5">
    </div>
    <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="bg-green-600 text-white px-3 py-1.5 rounded hover:bg-green-700">Convert</button></div>
  </form>`, 'Convert Lead');
}
async function doConvert(e, id) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  fd.lead_id = id; fd.create_deal = e.target.create_deal.checked;
  const res = await apiSend('lead-convert.php', fd);
  if (res.success) { toast('Lead converted → deal opened'); closeModal(); loadMetrics(); load(); } else toast(res.error || 'Failed', false);
}

/* ---- follow-up ---- */
const OUTCOMES = ['connected','no_answer','busy','callback_requested','interested','not_interested','not_reachable','converted','other'];
async function followUp(id) {
  const { data } = await apiGet('followups.php', { lead_id: id });
  const l = data.lead, hist = data.followups || [];
  const histHtml = hist.length ? hist.map(f => `<div class="border-b py-1">
    <div class="flex justify-between"><span class="font-medium capitalize">${esc(f.channel)} · ${esc(f.outcome.replace('_',' '))}</span>
      <span class="text-gray-400">${esc(String(f.created_at).slice(0,16))}</span></div>
    ${f.feedback?`<div class="text-gray-600">${esc(f.feedback)}</div>`:''}
    <div class="text-[10px] text-gray-400">by ${esc(f.by_name||'—')}${f.next_followup_at?` · next: ${esc(String(f.next_followup_at).slice(0,16))}`:''}</div></div>`).join('') : '<div class="text-gray-400">No follow-ups yet.</div>';
  openModal(`<div class="text-xs space-y-3">
    ${staleBanner(l)}
    <div class="bg-gray-50 rounded p-2"><div class="font-semibold">${esc((l.first_name||'')+' '+(l.last_name||''))} · ${esc(l.company||'')}</div>
      <div class="text-gray-500">${esc(l.phone||'')} ${l.email?('· '+esc(l.email)):''} · status: ${esc(l.lead_status)}${l.followup_count>0?(' · '+l.followup_count+' follow-ups'):''}</div></div>
    <form onsubmit="saveFollowup(event, ${id})" class="space-y-2">
      <div class="grid grid-cols-2 gap-2">
        <select name="channel" class="border rounded px-2 py-1.5"><option value="call">Call</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="visit">Visit</option></select>
        <select name="outcome" onchange="onOutcome(this)" class="border rounded px-2 py-1.5">${OUTCOMES.map(o=>`<option value="${o}">${o.replace('_',' ')}</option>`).join('')}</select>
      </div>
      <textarea name="feedback" placeholder="Lead feedback / what they said…" rows="2" class="border rounded px-2 py-1.5 w-full"></textarea>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="text-gray-500">Next follow-up</label><input name="next_followup_at" type="datetime-local" class="border rounded px-2 py-1.5 w-full"></div>
        <div><label class="text-gray-500">Set status</label><select name="update_status" class="border rounded px-2 py-1.5 w-full">
          <option value="">(auto from outcome)</option><option value="contacted">contacted</option><option value="qualified">qualified</option><option value="unqualified">unqualified (lost)</option><option value="converted">converted</option></select></div>
      </div>
      <input name="lost_reason" id="lostReason" placeholder="Lost reason (if not interested)" class="border rounded px-2 py-1.5 w-full hidden">
      <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Save follow-up</button></div>
    </form>
    <div><div class="font-semibold text-gray-600 mb-1">History</div><div class="max-h-40 overflow-y-auto">${histHtml}</div></div>
  </div>`, 'Follow up');
}
function onOutcome(sel){ document.getElementById('lostReason').classList.toggle('hidden', sel.value!=='not_interested'); }
async function saveFollowup(e, id) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries()); fd.lead_id = id;
  const res = await apiSend('followups.php', fd);
  if (res.success) { toast('Follow-up saved' + (res.data.new_status?(' · '+res.data.new_status):'')); closeModal(); loadMetrics(); load(); } else toast(res.error || 'Failed', false);
}

/* ---- Cloud click-to-call (provider bridges agent + customer) ---- */
async function callLead(id) {
  let agentNum = sessionStorage.getItem('agentCallback') || '';
  if (!agentNum) {
    agentNum = prompt('Your callback number — the provider rings this first, then the customer:', '');
    if (!agentNum) return;
    sessionStorage.setItem('agentCallback', agentNum);
  }
  const r = await apiSend('call.php', { lead_id: id, agent_number: agentNum });
  if (r.success) {
    if (r.data.mode === 'device') { toast('Dialing from this device…'); window.location.href = 'tel:' + r.data.dial; }
    else toast(r.message || 'Connecting your phone to the customer…');
  } else toast(r.error || 'Call failed', false);
}

/* ---- WhatsApp: jump to the WhatsApp console (Conversations) and open/start this lead's chat ---- */
async function waLead(id) {
  const { data } = await apiGet('followups.php', { lead_id: id });
  const l = data.lead;
  const phone = (l.mobile || l.phone || '').replace(/[^0-9+]/g, '');
  if (!phone) return toast('This lead has no phone number', false);
  const name = ((l.first_name || '') + ' ' + (l.last_name || '')).trim();
  window.location.href = 'sales-whatsapp.php?phone=' + encodeURIComponent(phone)
    + '&name=' + encodeURIComponent(name) + '&lead_id=' + id;
}

/* ---- init ---- */
async function loadEmployees() {
  const { data } = await apiGet('leads.php', { employees: 1 });
  EMP = data;
  document.getElementById('bulkOwnerVal').innerHTML = '<option value="">Assign owner…</option>' +
    data.map(e => `<option value="${e.id}">${esc(e.full_name)}</option>`).join('');
}
loadEmployees(); loadMetrics(); load();
// Auto-refresh so a lead created elsewhere (chatbot, web-to-lead, another agent) shows up
// without needing a manual page reload — skips while a modal is open so it doesn't yank
// focus out from under an in-progress form.
setInterval(() => { if (document.getElementById('modal').classList.contains('hidden')) { loadMetrics(); load(); } }, 20000);
</script>
</html>
