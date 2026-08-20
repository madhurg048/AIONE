<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// Who sees the approvals queue must follow the actual reporting chain, not a fixed
// role list: api/sales/timesheet.php?pending=1 returns rows WHERE e.manager_id = me
// (plus anyone who delegated to me), so ANY employee with direct reports is an
// approver — 'sales_head' (and cfo/hr_head/finance_manager) were missing from the old
// hardcoded list, so a Sales Head never saw submissions from the managers under them.
$isManager = in_array(sales_role(), ['manager','service_manager','admin'], true)
    || payg_role_can(sales_role(), 'timesheet_approve_all');
if (!$isManager) {
    try {
        $__mid = (int)($me['_employee_id'] ?? 0);
        if ($__mid) {
            $__db = getDB();
            $__q = $__db->prepare(
                "SELECT 1 FROM employees WHERE manager_id=? LIMIT 1"
            );
            $__q->execute([$__mid]);
            if ($__q->fetchColumn()) {
                $isManager = true;                       // has direct reports
            } else {
                // covering someone's approvals while they're away
                $__d = $__db->prepare(
                    "SELECT 1 FROM employees
                      WHERE delegate_approver_id=?
                        AND (delegate_until IS NULL OR delegate_until>=CURDATE()) LIMIT 1"
                );
                $__d->execute([$__mid]);
                $isManager = (bool)$__d->fetchColumn();
            }
        }
    } catch (Throwable $e) { /* non-fatal: fall back to role-based visibility */ }
}
// team compliance report: admin + dept heads + HR + anyone with a reporting line
$canCompliance = sales_role() === 'admin'
    || payg_role_can(sales_role(), 'records_all')
    || payg_role_can(sales_role(), 'timesheet_approve_all');
sales_head('Timesheet');
sales_header($me, 'My Timesheet & Activities');
sales_nav('timesheet');
?>
<main class="p-2 space-y-2">

  <!-- day toolbar -->
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase mr-1">Day</span>
    <input id="date" type="date" class="ctl" onchange="loadDay()">
    <span id="dayTotal" class="text-xs text-gray-500"></span>
    <span id="dayEntryCount" class="text-[10px] text-gray-400"></span>
    <div class="flex-1"></div>
    <span id="allTimeStats" class="text-xs text-gray-500"></span>
    <button onclick="aiFill()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i> AI fill</button>
    <button onclick="addRow()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-plus"></i> Entry</button>
    <button onclick="addOvertimeRow()" class="ctl hover:bg-gray-50" title="Log extra hours worked beyond the standard 8h for this day"><i class="fa-solid fa-bolt text-amber-500"></i> Overtime</button>
    <button onclick="submitDay()" class="btn-p"><i class="fa-solid fa-paper-plane"></i> Submit for approval</button>
  </div>

  <style>
    .desc-area{
      display:block; box-sizing:border-box; width:100%; min-height:30px; max-height:160px;
      overflow-y:auto; line-height:1.35; white-space:pre-wrap; word-wrap:break-word;
      font-family:inherit; border:1px solid #e5e7eb; border-radius:4px; padding:4px 6px;
    }
    .desc-area:focus{ outline:2px solid #6366f1; outline-offset:-1px; border-color:#6366f1; }
    .desc-area table{ border-collapse:collapse; font-size:11px; }
    table.dense .desc-area table td, table.dense .desc-area table th{
      border:1.5px solid #6b7280 !important; padding:3px 6px; white-space:normal;
    }
    .desc-view{
      max-height:160px; overflow-y:auto; white-space:pre-wrap; word-wrap:break-word; font-family:inherit;
    }
    .desc-view table{ border-collapse:collapse; font-size:11px; }
    table.dense .desc-view table td, table.dense .desc-view table th{
      border:1.5px solid #6b7280 !important; padding:3px 6px; white-space:normal;
    }
  </style>
  <!-- entries -->
  <div class="card">
    <table class="dense"><thead><tr>
      <th style="width:130px">Type</th><th style="width:70px">Hours</th><th style="width:120px" title="The CRM record this entry is linked to (lead / deal / merchant), if any — set automatically when AI-fill drafts an entry from your activity. Manual entries show &#8212; since they aren't tied to a record.">Related <i class="fa-solid fa-circle-info text-gray-300"></i></th>
      <th>Description</th><th style="width:90px">Status</th><th style="width:60px">Src</th><th style="width:50px"></th>
    </tr></thead><tbody id="rows"></tbody></table>
  </div>

  <!-- weekly summary stats (current Mon–Fri) -->
  <div class="flex justify-end">
    <div id="weekStats" class="card px-3 py-2 flex items-center gap-2 flex-wrap text-xs"></div>
  </div>

  <?php if ($isManager): ?>
  <!-- manager approvals -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Pending Approvals (my team)</div>
    <table class="dense"><thead><tr>
      <th>Employee</th><th>Date</th><th>Type</th><th>Hours</th><th>Description</th><th style="width:130px">Action</th>
    </tr></thead><tbody id="approvals"></tbody></table>
  </div>

  <!-- manager: already-reviewed entries, so they can see who they approved/rejected -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Reviewed by You (my team)</div>
    <table class="dense"><thead><tr>
      <th>Employee</th><th>Date</th><th>Type</th><th>Hours</th><th>Description</th><th style="width:90px">Status</th><th style="width:150px">Reviewed</th>
    </tr></thead><tbody id="reviewed"></tbody></table>
  </div>
  <?php endif; ?>

  <?php if ($canCompliance): ?>
  <!-- ===== team timesheet compliance (hierarchy-scoped) ===== -->
  <div class="card p-3">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-user-clock text-red-500"></i> Team Timesheet Compliance
        <span id="tcScope" class="normal-case font-normal text-[10px] text-gray-400"></span>
      </div>
      <div class="flex items-center gap-1.5 text-xs">
        <label class="text-[11px] text-gray-500">From <input id="tcFrom" type="date" class="ctl"></label>
        <label class="text-[11px] text-gray-500">To <input id="tcTo" type="date" class="ctl"></label>
        <button onclick="tcPreset(0)" class="ctl hover:bg-gray-50">Today</button>
        <button onclick="tcPreset(7)" class="ctl hover:bg-gray-50">7d</button>
        <button onclick="tcPreset(30)" class="ctl hover:bg-gray-50">30d</button>
        <button onclick="tcLoad()" class="btn-p">Apply</button>
      </div>
    </div>

    <div id="tcKpis" class="kstrip mb-2"></div>

    <div class="mb-2">
      <span class="text-[10px] uppercase text-gray-400">Not filled today:</span>
      <span id="tcToday" class="inline-flex flex-wrap gap-1 align-middle ml-1"></span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Missing per day <span class="normal-case">(weekends excluded)</span></div>
        <div class="overflow-x-auto max-h-64 overflow-y-auto"><table class="dense"><thead><tr>
          <th>Date</th><th class="text-center">Filled</th><th class="text-center">Missing</th><th>Who missed</th>
        </tr></thead><tbody id="tcDays"></tbody></table></div>
      </div>
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Lowest fill rate <span class="normal-case">(informational — filling is optional; weekends, holidays &amp; approved leave excluded)</span></div>
        <div class="overflow-x-auto max-h-64 overflow-y-auto"><table class="dense"><thead><tr>
          <th>Employee</th><th class="text-center">Missed</th><th class="text-center">Miss %</th><th>Last filled</th>
        </tr></thead><tbody id="tcOffenders"></tbody></table></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;
const CAN_COMPLIANCE = <?= $canCompliance ? 'true' : 'false' ?>;

/* ===== team timesheet compliance ===== */
function tcPreset(days){
  const today = new Date().toISOString().slice(0,10);
  const from = new Date(Date.now() - days*86400000).toISOString().slice(0,10);
  document.getElementById('tcFrom').value = days ? from : today;
  document.getElementById('tcTo').value = today;
  tcLoad();
}
async function tcLoad(){
  if (!CAN_COMPLIANCE) return;
  const p = {};
  const f = document.getElementById('tcFrom').value, t = document.getElementById('tcTo').value;
  if (f) p.from = f; if (t) p.to = t;
  const res = await apiGet('timesheet-compliance.php', p);
  if (!res.success) return;
  const d = res.data;
  document.getElementById('tcScope').textContent = `— ${d.scope} · ${d.team_size} people · ${d.from} → ${d.to} (${d.workdays} workday${d.workdays===1?'':'s'})`;
  const totMiss = d.days.reduce((s,x)=>s+x.missing, 0);
  const totSlots = d.team_size * d.workdays;
  const rate = totSlots ? Math.round((totSlots-totMiss)*100/totSlots) : 100;
  // Filling is optional — these are informational counts, not penalties, so they are
  // no longer rendered as red alarms.
  document.getElementById('tcKpis').innerHTML = [
    ['Team size', d.team_size],
    ['Not filled today', d.today_missing.length],
    ['Not filled in range', totMiss],
    ['Fill rate', rate + '%'],
    ['Below 50% fill', d.offenders.filter(o=>o.miss_pct>50 && o.days_expected>0).length],
  ].map(([l,v])=>`<div><div class="kv">${v}</div><div class="kl">${l}</div></div>`).join('');
  document.getElementById('tcToday').innerHTML = d.today_missing.length
    ? d.today_missing.map(n=>`<span class="text-[10px] bg-gray-100 text-gray-600 rounded-full px-2 py-0.5">${esc(n)}</span>`).join('')
    : '<span class="text-[10px] bg-green-50 text-green-700 rounded-full px-2 py-0.5">everyone has filled ✓</span>';
  document.getElementById('tcDays').innerHTML = d.days.map(x=>`
    <tr>
      <td class="whitespace-nowrap">${esc(x.date)} <span class="text-[10px] text-gray-400">${esc(x.dow)}</span></td>
      <td class="text-center text-green-700">${x.filled}</td>
      <td class="text-center font-semibold text-gray-600">${x.missing}</td>
      <td class="text-[10px] text-gray-500 max-w-[260px] truncate" title="${esc(x.missing_names.join(', '))}">${esc(x.missing_names.join(', '))}</td>
    </tr>`).join('') || '<tr><td colspan="4" class="text-center text-gray-400 py-2">No workdays in range</td></tr>';
  document.getElementById('tcOffenders').innerHTML = d.offenders.map(o=>`
    <tr>
      <td class="font-medium">${esc(o.full_name)}<div class="text-[10px] text-gray-400">${esc(o.designation||'')}</div></td>
      <td class="text-center font-semibold text-gray-600">${o.days_missed}/${o.days_expected}</td>
      <td class="text-center"><span class="inline-block rounded px-1.5 ${o.miss_pct>50?'bg-amber-100 text-amber-700':'bg-gray-100 text-gray-600'}">${o.miss_pct}%</span></td>
      <td class="text-[10px] text-gray-500">${esc(o.last_filled||'never')}</td>
    </tr>`).join('');
}
if (CAN_COMPLIANCE) window.addEventListener('DOMContentLoaded', ()=>tcPreset(0));
const TYPES = ['call','meeting','follow_up','onboarding','admin','travel','training','reports','other'];
const stColor = { draft:'gray', submitted:'blue', approved:'green', rejected:'red' };
document.getElementById('date').value = toLocalYMD(new Date());

async function loadDay() {
  await flushDescEdits(); // commit any unsaved description edit before we discard the old rows
  const date = document.getElementById('date').value;
  const res = await apiGet('timesheet.php', { date });
  const ot = +(res.ot_hours || 0), under = res.under_hours_flag;
  const std = +(res.standard_hours || 0), target = +(res.standard_target || 8);
  const pct = Math.min(100, target ? (std / target) * 100 : 0);
  const barColor = std >= target ? 'bg-green-500' : std > 0 ? 'bg-amber-500' : 'bg-gray-300';
  document.getElementById('dayTotal').innerHTML =
    `<span class="inline-flex items-center gap-1.5 align-middle">
       <span class="font-medium ${std >= target ? 'text-green-700' : 'text-gray-700'}">${std.toFixed(2)} / ${target.toFixed(2)} hrs</span>
       <span class="inline-block w-24 h-1.5 rounded-full bg-gray-200 overflow-hidden align-middle">
         <span class="block h-full ${barColor}" style="width:${pct}%"></span>
       </span>
     </span>`
    + (ot > 0 ? ` <span class="inline-block rounded px-1.5 bg-purple-100 text-purple-700 text-[10px]">+${ot.toFixed(2)}h OT</span>` : '')
    + (under ? ` <span class="inline-block rounded px-1.5 bg-red-100 text-red-700 text-[10px]">under 8h</span>` : '');
  document.getElementById('rows').innerHTML = res.data.map(t => rowHtml(t)).join('')
    || '<tr><td colspan="7" class="text-center text-gray-400 py-3">No entries — add manually or use AI-fill</td></tr>';
  document.getElementById('dayEntryCount').textContent = `(${res.data.length} entr${res.data.length===1?'y':'ies'} on this day)`;
  descEditedIds.clear();   // rows just re-rendered from the server — nothing edited since
  if (IS_MANAGER) { loadApprovals(); loadReviewed(); }
  loadWeekStats();
  loadAllTimeStats();
}
async function loadAllTimeStats(){
  const r = await apiGet('timesheet.php', { all_time_stats: 1 });
  if (!r.success) return;
  const d = r.data;
  document.getElementById('allTimeStats').innerHTML =
    `<span title="All-time, every status">All-time entries: <b>${d.total_entries}</b> · ${d.total_hours} hrs</span>`;
}

// ---- weekly summary widget: read-only, aggregates the existing per-day GET (no new/changed API) ----
function toLocalYMD(d){
  const y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
  return `${y}-${m}-${dd}`;
}
function weekMonToSun(dateStr){
  // parse as local date parts (not via Date+UTC) so this can't drift a day in +offset timezones like IST
  const [y, m, dd] = dateStr.split('-').map(Number);
  const d = new Date(y, m - 1, dd);
  const dow = d.getDay(); // 0=Sun..6=Sat
  const mondayOffset = dow === 0 ? -6 : 1 - dow;
  const monday = new Date(y, m - 1, dd + mondayOffset);
  const days = [];
  for (let i = 0; i < 7; i++) {
    const x = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + i);
    days.push(toLocalYMD(x));
  }
  return days;
}
// Every count here is derived fresh from the 7 day-fetches each time — never incremented
// across calls or merged with a stale render — so a single row can't get double-counted
// and a resubmit/edit on one row only moves that row between these buckets.
async function loadWeekStats(){
  const el = document.getElementById('weekStats');
  const days = weekMonToSun(document.getElementById('date').value);
  const results = await Promise.all(days.map(d => apiGet('timesheet.php', { date: d })));
  const workdays = 5; // Mon–Fri standard week; Sat/Sun entries (e.g. OT) still count toward hours below
  const weeklyTarget = workdays * 8;
  let totalEntries = 0, totalHours = 0, draft = 0, submitted = 0, approved = 0, pending = 0, rejected = 0;
  results.forEach(r => (r.data || []).forEach(t => {
    totalEntries++; // every row logged this week, including still-draft ones
    totalHours += +t.hours || 0;
    if (t.status === 'draft') { draft++; return; } // not submitted yet — excluded from submission stats
    submitted++;
    if (t.status === 'approved') approved++;
    else if (t.status === 'submitted') pending++;
    else if (t.status === 'rejected') rejected++;
  }));
  el.innerHTML = `
    <span class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide" title="Covers the whole week, not just the selected day">
      Week ${days[0]} → ${days[6]}
    </span>
    <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5" title="All rows logged across these 7 days (${draft} still in draft)">Entries: <b>${totalEntries}</b></span>
    <span class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 text-indigo-700 px-2 py-0.5">Logged: <b>${totalHours.toFixed(2)} / ${weeklyTarget.toFixed(2)} hrs</b></span>
    <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5" title="${totalEntries} row(s) total, ${draft} still in draft">Submitted: <b>${submitted}</b></span>
    <span class="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 text-green-700 px-2 py-0.5">Approved: <b>${approved}</b></span>
    <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 text-amber-700 px-2 py-0.5">Pending: <b>${pending}</b></span>
    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 bg-red-50 text-red-700 px-2 py-0.5">Rejected: <b>${rejected}</b></span>
  `;
}

// Only allow table-structure tags through (no attributes, no scripts/styles) —
// strips everything else while keeping text content, so pasted Excel/Teams
// blocks render as a real table without opening a stored-XSS hole.
const DESC_ALLOWED_TAGS = new Set(['TABLE','THEAD','TBODY','TR','TD','TH','BR']);
// contenteditable wraps each typed line in a block element. Those aren't in the
// allowed set, so unwrapping them bare would run every line together — emit a
// line break in their place to keep the text as the user typed it.
const DESC_BLOCK_TAGS = new Set(['DIV','P','LI','UL','OL','H1','H2','H3','H4','H5','H6']);
// Anything the contenteditable box itself produced (typed lines, pasted tables) comes
// back as real markup using these tags. Only content that looks like NONE of them —
// i.e. legacy plain text saved before this editor existed, or literal "<" a user typed —
// is treated as plain text and escaped as-is. Previously only a pasted <table> took the
// HTML path, so a lone typed line (which the browser still wraps as <div>text</div> or
// <div><br></div> for an empty line) fell through to the plain-text branch and got its
// own <div>/<br> tags escaped into visible "&lt;div&gt;" text instead of being parsed away.
const DESC_MARKUP_RE = /<(table|div|p|br|li|ul|ol|h[1-6])[\s\/>]/i;
function sanitizeDescHtml(html){
  if (!html) return '';
  if (!DESC_MARKUP_RE.test(html)) return esc(html).replace(/\n/g,'<br>');
  const doc = new DOMParser().parseFromString(html, 'text/html');
  function clean(node){
    const frag = document.createDocumentFragment();
    node.childNodes.forEach(child=>{
      if (child.nodeType === 3) {
        frag.appendChild(document.createTextNode(child.textContent));
      } else if (child.nodeType === 1) {
        if (DESC_ALLOWED_TAGS.has(child.tagName)) {
          const el = document.createElement(child.tagName);
          el.appendChild(clean(child));
          frag.appendChild(el);
        } else {
          // a block wrapper outside a table means a new line; inside a table the
          // cell boundaries already provide the structure, so don't inject breaks
          const startsNewLine = DESC_BLOCK_TAGS.has(child.tagName) && !node.closest?.('table');
          if (startsNewLine && frag.lastChild) frag.appendChild(document.createElement('br'));
          frag.appendChild(clean(child)); // unwrap (e.g. colgroup/span wrappers) but keep contents
        }
      }
    });
    return frag;
  }
  const wrap = document.createElement('div');
  wrap.appendChild(clean(doc.body));
  return wrap.innerHTML || esc(html);
}
function buildTableFromTabText(text){
  if (!text || text.indexOf('\t') === -1) return null;
  const rows = text.replace(/\r/g,'').split('\n').filter(r => r.length);
  if (rows.length < 1) return null;
  return '<table><tbody>' + rows.map(r =>
    '<tr>' + r.split('\t').map(c => `<td>${esc(c)}</td>`).join('') + '</tr>'
  ).join('') + '</tbody></table>';
}
// preserve Excel/Teams-style tabular paste as a real table inside the description
function onDescPaste(ev, el){
  const cd = ev.clipboardData || window.clipboardData;
  if (!cd) return;
  ev.preventDefault();
  const html = cd.getData('text/html');
  const text = cd.getData('text/plain');
  let insertHtml;
  if (html && /<table[\s>]/i.test(html)) insertHtml = sanitizeDescHtml(html);
  else insertHtml = buildTableFromTabText(text) || esc(text||'').replace(/\n/g,'<br>');
  document.execCommand('insertHTML', false, insertHtml);
}
// Blur only fires when focus moves elsewhere in the page — a plain browser
// refresh/tab-close while still typing never triggers it, so a pending edit
// could be lost. Auto-save shortly after typing pauses (in addition to blur
// and the pre-action flush below) so it hits the DB without needing a click.
const descSaveTimers = new Map();
// Which rows were edited since the table was last (re)loaded. Separate from the
// per-element "dirty" flag, because the debounced auto-save clears dirty as soon as
// it persists — so by the time Submit is clicked there is usually nothing dirty left,
// and Submit would wrongly report "Nothing new to submit" for an edit that DID save.
const descEditedIds = new Set();
function markDescDirty(el){
  el.dataset.dirty = '1';
  const id = el.dataset.id;
  descEditedIds.add(id);
  if (descSaveTimers.has(id)) clearTimeout(descSaveTimers.get(id));
  descSaveTimers.set(id, setTimeout(() => saveDescEl(el), 800));
}
async function saveDescEl(el){
  const id = el.dataset.id;
  if (descSaveTimers.has(id)) { clearTimeout(descSaveTimers.get(id)); descSaveTimers.delete(id); }
  if (!id || el.dataset.dirty !== '1') return;
  await apiSend('timesheet.php?id='+id, { description: el.innerHTML }, 'PATCH');
  el.dataset.dirty = '0';
}
// blur-based save misses the case where the user clicks Submit/AI-fill/Entry
// without first clicking away — those reload the table before blur can fire,
// silently dropping the edit. Flush any dirty description box first instead.
async function flushDescEdits(){
  const dirty = document.querySelectorAll('#rows .desc-area[data-dirty="1"]');
  for (const el of dirty) await saveDescEl(el);
  return dirty.length;
}
function rowHtml(t){
  const isDraft = t.status === 'draft';
  const isRejected = t.status === 'rejected';
  const isOt = (t.is_overtime == 1 || t.is_overtime === true);
  // rejected entries can still have hours/type fixed based on the manager's feedback, same as a draft
  const fieldsEditable = isDraft || isRejected;
  return `<tr>
    <td>${fieldsEditable ? `<select onchange="upd(${t.id},'activity_type',this.value)" class="border rounded px-1 py-1 w-full">${TYPES.map(x=>`<option ${x===t.activity_type?'selected':''}>${x}</option>`).join('')}</select>` : esc(t.activity_type)}</td>
    <td>${fieldsEditable ? `<input type="number" step="0.25" value="${t.hours}" onchange="upd(${t.id},'hours',this.value)" class="border rounded px-1 py-1 w-full">` : t.hours}</td>
    <td class="text-gray-500" title="${t.related_type!=='none'?esc(t.related_type+' #'+(t.related_id||'')):'Not linked to a CRM record'}">${t.related_type!=='none'?esc(t.related_type+' #'+(t.related_id||'')):'—'}</td>
    <td><div class="desc-area" contenteditable="true" data-id="${t.id}" oninput="markDescDirty(this)" onpaste="onDescPaste(event,this)" onblur="saveDescEl(this)">${sanitizeDescHtml(t.description||'')}</div></td>
    <td>${badge(t.status, stColor[t.status])}${isOt?' <span class="text-[9px] bg-purple-100 text-purple-700 rounded px-1" title="Overtime / extra hours">OT</span>':''}${(t.late_entry==1||t.late_entry===true)?' <span class="text-[9px] bg-amber-100 text-amber-700 rounded px-1">backdated</span>':''}
      ${isRejected && t.remarks ? `<div class="text-[9px] text-red-500 mt-0.5" title="Manager's rejection remark">${esc(t.remarks)}</div>` : ''}</td>
    <td>${t.source==='ai'?'🤖':t.source==='voice'?'🎙️':'✍️'}</td>
    <td class="rowact whitespace-nowrap">
      ${isDraft ? `<button onclick="del(${t.id})" class="text-red-400 hover:text-red-600" title="Discard draft"><i class="fa-solid fa-trash"></i></button>` : ''}
      ${isRejected ? `<button onclick="resubmitOne(${t.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded font-medium"><i class="fa-solid fa-arrow-rotate-right"></i> Resubmit</button>` : ''}
    </td>
  </tr>`;
}
async function upd(id, field, value){ await apiSend('timesheet.php?id='+id, {[field]:value}, 'PATCH'); loadDay(); }
// Real delete (own draft/rejected rows only). The old soft-delete wrote status='rejected',
// which the API routes to the manager-only approve/reject branch — so it 403'd for reps,
// and any surviving row kept inflating the weekly status counts.
async function del(id){
  const r = await apiSend('timesheet.php?id='+id, {}, 'DELETE');
  if (r.success) { toast('Entry deleted'); loadDay(); }
  else toast(r.error || 'Could not delete', false);
}
// Row-level resubmit for a single rejected entry — updates only that entry back to
// 'submitted' (pending review), leaving the rest of the day/week's entries untouched
// so weekly stats stay accurate. Reuses the existing PATCH resubmission path (which
// already notifies the manager when a rejected entry comes back as submitted).
async function resubmitOne(id){
  const el = document.querySelector(`.desc-area[data-id="${id}"]`);
  if (el) await saveDescEl(el); // make sure the fixed description is saved before flipping status
  const r = await apiSend('timesheet.php?id='+id, { status: 'submitted' }, 'PATCH');
  if (r.success) { toast('Entry resubmitted for approval'); loadDay(); }
  else toast(r.error || 'Could not resubmit', false);
}

async function addRow(desc){
  const date = document.getElementById('date').value;
  await apiSend('timesheet.php', { entry_date: date, hours: 1, activity_type: 'other', description: desc || '', source: 'manual' });
  loadDay();
}

// Extra/overtime work on the same date goes in its own flagged row, so standard
// hours and overtime stay countable separately.
async function addOvertimeRow(){
  const date = document.getElementById('date').value;
  await apiSend('timesheet.php', { entry_date: date, hours: 1, activity_type: 'other', description: '', source: 'manual', is_overtime: 1 });
  toast('Overtime entry added for ' + date);
  loadDay();
}

async function aiFill(){
  const date = document.getElementById('date').value;
  toast('AI drafting from your activity…');
  const res = await apiSend('timesheet.php?ai_fill=1', { date });
  if (!res.success) return toast(res.error||'Failed', false);
  const entries = res.data.entries || [];
  if (!entries.length) return toast(res.data.note || 'Nothing to draft', false);
  // save each as a draft (source=ai)
  for (const e of entries) await apiSend('timesheet.php', { entry_date: date, hours: e.hours||1, activity_type: e.activity_type||'other', description: e.description||'', source: 'ai' });
  toast(`AI added ${entries.length} draft entr${entries.length>1?'ies':'y'}`);
  loadDay();
}

async function submitDay(){
  const date = document.getElementById('date').value;
  // flush first (not just rely on blur) so no edit is lost, then count everything
  // edited since load — including edits the debounced auto-save already persisted.
  await flushDescEdits();
  const updated = descEditedIds.size;
  const res = await apiSend('timesheet.php?submit=1', { date });
  if (res.success) {
    if (res.data.submitted > 0) {
      toast(`${res.data.submitted} entr${res.data.submitted===1?'y':'ies'} submitted for approval`);
    } else if (updated > 0) {
      // status was already 'submitted' (still awaiting review) — no status flip needed,
      // but confirm the edited description actually reached the approver's queue.
      toast(`${updated} entr${updated===1?'y':'ies'} updated — already awaiting approval, your approver will see the latest text`);
    } else {
      toast('Nothing new to submit', false);
    }
    loadDay();
  }
}

/* ---- manager approvals ---- */
async function loadApprovals(){
  const { data } = await apiGet('timesheet.php', { pending: 1 });
  document.getElementById('approvals').innerHTML = data.map(t => `
    <tr>
      <td class="font-medium">${esc(t.employee_name)}</td>
      <td>${esc(t.entry_date)}${(t.late_entry==1||t.late_entry===true)?' <span class="text-[9px] bg-amber-100 text-amber-700 rounded px-1">backdated</span>':''}</td><td>${esc(t.activity_type)}</td><td>${t.hours}</td>
      <td class="text-gray-600"><div class="desc-view">${sanitizeDescHtml(t.description||'')}</div></td>
      <td class="whitespace-nowrap">
        <button onclick="approve(${t.id},'approved')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Approve</button>
        <button onclick="approve(${t.id},'rejected')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Reject</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="6" class="text-center text-gray-400 py-2">Nothing pending</td></tr>';
}
async function loadReviewed(){
  const { data } = await apiGet('timesheet.php', { reviewed: 1 });
  document.getElementById('reviewed').innerHTML = data.map(t => `
    <tr>
      <td class="font-medium">${esc(t.employee_name)}</td>
      <td>${esc(t.entry_date)}</td><td>${esc(t.activity_type)}</td><td>${t.hours}</td>
      <td class="text-gray-600"><div class="desc-view">${sanitizeDescHtml(t.description||'')}</div></td>
      <td>${badge(t.status, stColor[t.status])}</td>
      <td class="text-[10px] text-gray-500">${esc(t.approved_by_name||'')}${t.approved_at?'<br>'+esc(t.approved_at):''}</td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-gray-400 py-2">Nothing reviewed yet</td></tr>';
}
async function approve(id, status){ const r = await apiSend('timesheet.php?id='+id, { status }, 'PATCH'); if(r.success){toast('Marked '+status); loadApprovals(); loadReviewed();} }

loadDay();
</script>
</html>
