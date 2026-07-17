/* PayG Sales CRM — shared front-end helpers */
const API = 'api/sales/';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function apiGet(path, params = {}) {
  const q = new URLSearchParams(params).toString();
  const r = await fetch(API + path + (q ? '?' + q : ''), { credentials: 'same-origin' });
  return r.json();
}
async function apiSend(path, body, method = 'POST') {
  const r = await fetch(API + path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    credentials: 'same-origin',
    body: JSON.stringify(body || {})
  });
  return r.json();
}

function toast(msg, ok = true) {
  const t = document.getElementById('toast');
  if (!t) return alert(msg);
  t.textContent = msg;
  t.className = 'fixed top-4 right-4 px-4 py-2 rounded-md shadow-lg text-sm z-50 text-white ' +
    (ok ? 'bg-green-600' : 'bg-red-600');
  t.classList.remove('hidden');
  setTimeout(() => t.classList.add('hidden'), 2600);
}

const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
// normalise a phone to international digits for wa.me (prepends 91 to local 10-digit numbers)
const waNum = p => { let n = String(p || '').replace(/\D/g, ''); if (n.length === 10) n = '91' + n; return n; };
const esc = s => (s == null ? '' : String(s).replace(/[&<>"']/g,
  c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));

function badge(text, color) {
  const map = {
    green: 'bg-green-100 text-green-700', yellow: 'bg-yellow-100 text-yellow-700',
    red: 'bg-red-100 text-red-700', blue: 'bg-blue-100 text-blue-700',
    gray: 'bg-gray-100 text-gray-600', indigo: 'bg-indigo-100 text-indigo-700'
  };
  return `<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold ${map[color] || map.gray}">${esc(text)}</span>`;
}

/**
 * Renders an AI summary response as readable HTML. The AI is asked for plain prose, but
 * LLMs sometimes free-style into JSON anyway (a few underlying models do this for
 * summarize-shaped prompts) — this detects that case and renders a structured card
 * instead of dumping raw JSON text at the user.
 */
function renderAiSummary(raw) {
  const text = String(raw ?? '').trim();
  const fenced = text.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i);
  let obj = null;
  try { obj = JSON.parse(fenced ? fenced[1] : text); } catch (e) { obj = null; }
  if (!obj || typeof obj !== 'object') return `<div class="text-xs text-gray-700 whitespace-pre-wrap leading-relaxed">${esc(text)}</div>`;

  const pick = (...keys) => keys.map(k => obj[k]).find(v => v != null && v !== '');
  const summary = pick('conversation_summary', 'summary', 'description');
  const keyPoints = pick('key_points', 'highlights', 'points');
  const nextSteps = pick('next_steps', 'next_step', 'action_items');
  const status = pick('status');
  const shown = new Set(['conversation_summary', 'summary', 'description', 'key_points', 'highlights', 'points', 'next_steps', 'next_step', 'action_items', 'status']);
  const rest = Object.entries(obj).filter(([k, v]) => !shown.has(k) && v != null && v !== '');

  let html = '';
  if (summary) html += `<p class="text-xs text-gray-700 leading-relaxed mb-2">${esc(summary)}</p>`;
  if (Array.isArray(keyPoints) && keyPoints.length) {
    html += `<div class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Key points</div>
      <ul class="list-disc list-inside text-xs text-gray-700 space-y-0.5 mb-2">${keyPoints.map(p => `<li>${esc(p)}</li>`).join('')}</ul>`;
  }
  if (nextSteps) html += `<div class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Next steps</div><p class="text-xs text-gray-700 mb-2">${esc(Array.isArray(nextSteps) ? nextSteps.join(', ') : nextSteps)}</p>`;
  if (status) html += `<div class="mb-2">${badge(String(status).replace(/_/g, ' '), 'indigo')}</div>`;
  if (rest.length) html += rest.map(([k, v]) => `<div class="text-[11px] text-gray-500"><b>${esc(k.replace(/_/g, ' '))}:</b> ${esc(Array.isArray(v) ? v.join(', ') : v)}</div>`).join('');
  return html || `<div class="text-xs text-gray-700 whitespace-pre-wrap leading-relaxed">${esc(text)}</div>`;
}

/* generic modal helpers (expects <div id="modal"> with #modalBody) */
function openModal(html, title = '') {
  const m = document.getElementById('modal');
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = html;
  m.classList.remove('hidden');
  m.classList.add('flex');
}
function closeModal() {
  const m = document.getElementById('modal');
  m.classList.add('hidden');
  m.classList.remove('flex');
}

/* ---------- Reusable, evidence-backed AI insight strip ----------
   Any page: <div data-ai-scope="leads"></div> then include js/sales.js.
   Auto-inits on DOMContentLoaded. Shows narrative + expandable evidence. */
function evidenceHtml(ev) {
  const rows = [];
  const walk = (obj, prefix = '') => {
    for (const [k, v] of Object.entries(obj || {})) {
      if (Array.isArray(v)) {
        rows.push(`<tr><td class="pr-3 text-gray-400 align-top">${esc(prefix + k)}</td><td>${
          v.map(o => typeof o === 'object'
            ? '<span class="inline-block mr-2">' + Object.values(o).map(esc).join(' · ') + '</span>'
            : esc(o)).join('')}</td></tr>`);
      } else if (v && typeof v === 'object') {
        walk(v, prefix + k + '.');
      } else {
        rows.push(`<tr><td class="pr-3 text-gray-400">${esc(prefix + k)}</td><td class="font-medium">${esc(v)}</td></tr>`);
      }
    }
  };
  walk(ev);
  return `<table class="w-full text-[11px]"><tbody>${rows.join('')}</tbody></table>`;
}

async function renderInsightStrip(el, force = false) {
  const scope = el.getAttribute('data-ai-scope');
  const uid = 'ai_' + scope + '_' + Math.random().toString(36).slice(2, 7);
  el.innerHTML = `
    <div class="card p-2">
      <div class="flex items-center justify-between mb-1">
        <h3 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
          <i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i> AI Insight &amp; Recommended Decisions
          <span id="${uid}_stamp" class="ml-1 normal-case font-normal text-[10px] text-gray-400"></span></h3>
        <div class="flex items-center gap-3 text-xs">
          <button onclick="document.getElementById('${uid}_ev').classList.toggle('hidden')" class="text-gray-500 hover:text-indigo-600">
            <i class="fa-solid fa-table-list"></i> Evidence</button>
          <button onclick="renderInsightStrip(document.querySelector('[data-ai-scope=\\'${scope}\\']'), true)" class="text-indigo-600 hover:underline"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
      </div>
      <div id="${uid}_body" class="text-xs text-gray-400"><i class="fa-solid fa-circle-notch fa-spin"></i> ${force ? 'Regenerating' : 'Loading'} AI read…</div>
      <div id="${uid}_ev" class="hidden mt-2 border-t pt-2 max-h-56 overflow-y-auto"></div>
    </div>`;
  const res = await apiSend('insight.php', { scope, force: force ? 1 : 0 });
  const d = res.data || {};
  const body = document.getElementById(uid + '_body');
  document.getElementById(uid + '_ev').innerHTML =
    '<div class="text-[10px] text-gray-400 mb-1 uppercase">Computed from live data — the model only interprets these figures</div>' +
    evidenceHtml(d.evidence || {});

  // cache/freshness stamp
  const stamp = document.getElementById(uid + '_stamp');
  if (stamp) {
    if (d.cached) stamp.innerHTML = `<i class="fa-solid fa-clock"></i> cached · as of ${esc(String(d.cached_at||'').slice(11,16))} (Refresh for live)`;
    else if (d.ai_available) stamp.innerHTML = `<i class="fa-solid fa-bolt"></i> live · just now`;
  }

  if (!res.success) {
    // insight.php is deliberately manager/admin-only (it surfaces company-wide aggregates,
    // including per-agent incentive figures) — a rep hitting that on a page they legitimately
    // work (e.g. their own Leads list) isn't a bug to show them, so hide the whole card instead
    // of a scary red "Not authorized". Any other failure (AI truly down, bad scope, etc.) still
    // shows the error since that IS actionable.
    if (res.error === 'Not authorized') { el.innerHTML = ''; return; }
    body.innerHTML = `<span class="text-red-500">${esc(res.error || 'Failed')}</span>`;
    return;
  }
  const list = (title, items, color, icon) => (items && items.length) ? `
    <div class="mb-2"><div class="font-semibold text-${color}-600 mb-0.5"><i class="fa-solid ${icon}"></i> ${title}</div>
      <ul class="list-disc pl-5 space-y-0.5 text-gray-700">${items.map(i => `<li>${esc(i)}</li>`).join('')}</ul></div>` : '';
  const flag = d.ai_available === false
    ? '<div class="mb-2 text-[10px] text-yellow-600"><i class="fa-solid fa-triangle-exclamation"></i> AI service offline — evidence-only mode (numbers are still live).</div>'
    : '';
  body.innerHTML = flag +
    `<div class="bg-indigo-50 text-indigo-800 rounded p-2 mb-2 font-medium">${esc(d.summary || '')}</div>` +
    list('Key findings', d.key_findings, 'gray', 'fa-magnifying-glass-chart') +
    list('Recommended decisions', d.decisions, 'green', 'fa-lightbulb') +
    list('Watch', d.watch, 'red', 'fa-eye');
}

document.addEventListener('DOMContentLoaded', () => {
  const strips = document.querySelectorAll('[data-ai-scope]');
  // show a light placeholder immediately (no layout jump)…
  strips.forEach(el => el.innerHTML =
    '<div class="card p-2 text-xs text-gray-300"><i class="fa-solid fa-wand-magic-sparkles"></i> AI insight will load…</div>');
  // …then load the AI last, when the browser is idle, so it never blocks the main content
  const kick = () => strips.forEach(el => renderInsightStrip(el));
  if ('requestIdleCallback' in window) requestIdleCallback(kick, { timeout: 2000 });
  else setTimeout(kick, 400);
});

/* Ask the agentic AI service and render into a target element */
async function aiInto(targetId, action, params) {
  const el = document.getElementById(targetId);
  if (el) el.innerHTML = '<div class="text-gray-400 text-xs flex items-center gap-2"><i class="fa-solid fa-circle-notch fa-spin"></i> AI analysing…</div>';
  const res = await apiSend('ai.php', { action, ...params });
  if (!res.success) {
    if (el) el.innerHTML = `<div class="text-red-500 text-xs">${esc(res.error || 'AI unavailable')}</div>`;
    return res;
  }
  return res;
}

/* ================= My Tasks panel ================= */
let _taskTab = 'mine';
const TASK_TYPE_LABEL = { kyc_validation:'KYC', document_collection:'Docs', follow_up:'Follow-up',
  callback:'Callback', review:'Review', onboarding:'Onboarding', general:'Task' };
const TASK_PRI_COLOR = { urgent:'red', high:'yellow', medium:'blue', low:'gray' };
const TASK_ST_COLOR  = { open:'blue', in_progress:'yellow', done:'green', cancelled:'gray' };
// pipeline = queued/assigned (not started) · active = started/in-progress
const TASK_ST_LABEL  = { open:'Pipeline', in_progress:'Active', done:'Done', cancelled:'Cancelled' };

async function refreshTaskBadge() {
  try {
    const [t, f] = await Promise.all([
      apiGet('tasks.php', { count: 1 }),
      apiGet('followups.php', { due: 1, mine: 1 }).catch(() => ({ data: [] })),
    ]);
    const total = (t.data.open || 0) + ((f.data && f.data.length) || 0);
    const b = document.getElementById('taskBadge');
    if (!b) return;
    if (total > 0) { b.textContent = total; b.classList.remove('hidden'); }
    else b.classList.add('hidden');
  } catch (e) {}
}
function openTaskPanel() {
  document.getElementById('taskPanelOverlay').classList.remove('hidden');
  document.getElementById('taskPanel').classList.remove('translate-x-full');
  loadTasks();
}
function closeTaskPanel() {
  document.getElementById('taskPanelOverlay').classList.add('hidden');
  document.getElementById('taskPanel').classList.add('translate-x-full');
}
function switchTaskTab(tab) {
  _taskTab = tab;
  document.getElementById('tabMine').className = 'flex-1 py-2 border-b-2 ' + (tab==='mine'?'border-indigo-600 text-indigo-600 font-semibold':'border-transparent text-gray-500');
  document.getElementById('tabAssigned').className = 'flex-1 py-2 border-b-2 ' + (tab==='assigned'?'border-indigo-600 text-indigo-600 font-semibold':'border-transparent text-gray-500');
  loadTasks();
}
/* KPA/KRA goals the current user may tag their tasks with (cached). */
let _myGoals = null;
async function ensureMyGoals() {
  if (_myGoals === null) {
    try { const r = await apiGet('kpa.php', { action: 'my_goals' }); _myGoals = (r.success && r.data.goals) || []; }
    catch (e) { _myGoals = []; }
  }
  return _myGoals;
}
function kpaOptHtml(goals, sel) {
  return '<option value="">— KPA / KRA —</option>' + goals.map(g =>
    `<option value="${g.id}" ${String(sel) === String(g.id) ? 'selected' : ''}>${esc(g.title)}${g.scope === 'org' ? ' (' + esc(g.org_group) + ')' : ''}</option>`).join('');
}

async function loadTasks() {
  const el = document.getElementById('taskList');
  el.innerHTML = '<div class="text-gray-400 text-xs">Loading…</div>';

  // Follow-ups due (only on the "assigned to me" tab) — closes the follow-up loop
  let dueHtml = '';
  if (_taskTab === 'mine') {
    const due = await apiGet('followups.php', { due: 1, mine: 1 }).catch(() => ({ data: [] }));
    const list = (due.data || []);
    if (list.length) {
      dueHtml = `<div class="mb-1"><div class="text-[10px] uppercase text-red-600 font-semibold mb-1"><i class="fa-solid fa-bell"></i> Follow-ups due (${list.length})</div>` +
        list.map(l => `<a href="sales-leads.php" class="block bg-red-50 border border-red-100 rounded-lg p-2 mb-1 hover:bg-red-100">
            <div class="font-medium text-xs">${esc((l.first_name||'')+' '+(l.last_name||''))} · ${esc(l.company||'')}</div>
            <div class="text-[10px] text-red-600">due ${esc(String(l.next_followup_at).slice(0,16))} · ${esc(l.phone||l.email||'')}</div>
          </a>`).join('') + `</div>`;
    }
  }

  const { data } = await apiGet('tasks.php', _taskTab==='assigned' ? { assigned:1 } : { mine:1 });
  if (!data.length && !dueHtml) { el.innerHTML = '<div class="text-gray-400 text-xs text-center py-6">No tasks</div>'; return; }
  if (!data.length) { el.innerHTML = dueHtml + '<div class="text-gray-400 text-xs text-center py-3">No assigned tasks</div>'; return; }
  // On my own tasks, offer a KPA/KRA tag + hours log so effort rolls up to the goal.
  const goals = _taskTab==='mine' ? await ensureMyGoals() : [];
  el.innerHTML = dueHtml + data.map(t => {
    const who = _taskTab==='assigned' ? ('→ ' + esc(t.assignee_name||'')) : ('from ' + esc(t.assigner_name||'system'));
    const overdue = t.due_at && t.status!=='done' && new Date(t.due_at) < new Date();
    const logRow = _taskTab==='mine' && t.status!=='cancelled'
      ? `<div class="flex items-center gap-1 mt-2 flex-wrap">
           <select class="kpaS ctl !h-6 !text-[10px] max-w-[150px]" data-id="${t.id}">${kpaOptHtml(goals, t.kpa_goal_id)}</select>
           <input class="hrsS ctl !h-6 !text-[10px] w-14" data-id="${t.id}" type="number" step="0.25" min="0" value="${t.hours_spent>0?t.hours_spent:''}" placeholder="hrs">
           <button onclick="saveTaskLog(${t.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded" title="Log hours to this KPA">Save</button>
           ${t.status==='open'?`<button onclick="setTaskStatus(${t.id},'in_progress')" class="text-[10px] bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded">Start</button>`:''}
           ${t.status!=='done'?`<button onclick="completeTask(${t.id})" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Done</button>`:''}
         </div>` : '';
    return `<div class="bg-white rounded-lg border p-2.5">
      <div class="flex items-start justify-between gap-2">
        <span class="font-medium text-xs text-gray-800">${esc(t.title)}</span>
        ${badge(TASK_TYPE_LABEL[t.task_type]||'Task','indigo')}
      </div>
      ${t.description?`<div class="text-[11px] text-gray-500 mt-0.5">${esc(t.description)}</div>`:''}
      <div class="flex items-center gap-2 mt-1 text-[10px] text-gray-400">
        ${badge(TASK_ST_LABEL[t.status]||t.status, TASK_ST_COLOR[t.status])}
        ${badge(t.priority, TASK_PRI_COLOR[t.priority])}
        <span>${who}</span>
        ${t.hours_spent>0?`<span class="text-indigo-500 font-semibold">${(+t.hours_spent).toFixed(2)}h</span>`:''}
        ${t.due_at?`<span class="${overdue?'text-red-500 font-semibold':''}">due ${esc(String(t.due_at).slice(0,10))}</span>`:''}
      </div>${logRow}</div>`;
  }).join('');
}
function _readTaskLog(id) {
  const k = document.querySelector(`.kpaS[data-id="${id}"]`);
  const h = document.querySelector(`.hrsS[data-id="${id}"]`);
  return { kpa_goal_id: k ? k.value : '', hours_spent: h ? (parseFloat(h.value) || 0) : 0 };
}
async function saveTaskLog(id) {
  const res = await apiSend('tasks.php?id=' + id, _readTaskLog(id), 'PATCH');
  if (res.success) { toast('Logged to KPA'); loadTasks(); } else toast(res.error || 'Failed', false);
}
async function completeTask(id) {
  const res = await apiSend('tasks.php?id=' + id, Object.assign({ status: 'done' }, _readTaskLog(id)), 'PATCH');
  if (res.success) { toast('Task completed'); loadTasks(); refreshTaskBadge(); } else toast(res.error || 'Failed', false);
}
async function setTaskStatus(id, status) {
  const res = await apiSend('tasks.php?id='+id, { status }, 'PATCH');
  if (res.success) {
    const msg = status==='done' ? 'Task completed'
      : status==='in_progress' ? ((res.data && res.data.timesheet_logged) ? 'Task started → active · logged to your timesheet' : 'Task started → active')
      : 'Task updated';
    toast(msg); loadTasks(); refreshTaskBadge();
  } else toast(res.error||'Failed', false);
}
async function openAssignModal() {
  const m = document.getElementById('taskAssignModal');
  const sel = m.querySelector('select[name=assigned_to]');
  const kSel = m.querySelector('select[name=kpa_goal_id]');
  const { data } = await apiGet('tasks.php', { assignees: 1 });
  sel.innerHTML = '<option value="">Assign to…</option>' +
    data.map(e => `<option value="${e.id}">${esc(e.full_name)}${e.designation?' — '+esc(e.designation):''}</option>`).join('');
  if (kSel) kSel.innerHTML = '<option value="">KPA / KRA (optional)…</option>';
  // Load the *assignee's* applicable KPAs so the task's effort attributes to their goals.
  sel.onchange = async () => {
    if (!kSel) return;
    kSel.innerHTML = '<option value="">KPA / KRA (optional)…</option>';
    if (!sel.value) return;
    const r = await apiGet('kpa.php', { action: 'goals_for', employee_id: sel.value });
    if (r.success) kSel.innerHTML += (r.data.goals || []).map(g =>
      `<option value="${g.id}">${esc(g.title)}${g.scope === 'org' ? ' (' + esc(g.org_group) + ')' : ''}</option>`).join('');
  };
  m.classList.remove('hidden'); m.classList.add('flex');
}
function closeAssignModal(){ const m=document.getElementById('taskAssignModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
async function submitTask(e) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  const res = await apiSend('tasks.php', fd);
  if (res.success) { toast('Task assigned'); closeAssignModal(); e.target.reset(); switchTaskTab('assigned'); refreshTaskBadge(); }
  else toast(res.error||'Failed', false);
}
document.addEventListener('DOMContentLoaded', () => { refreshTaskBadge(); setInterval(refreshTaskBadge, 60000); });

/* Logout — destroy the session server-side, then show the single login page */
function salesLogout() {
  fetch('api/sales/auth.php?action=logout', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': CSRF_TOKEN }
  })
    .catch(() => {})
    .finally(() => { window.location.href = 'sales-login.php'; });
}

/* admin impersonation — return to the real admin session */
async function stopImpersonate() {
  try {
    const r = await fetch('api/sales/auth.php?action=stop_impersonate', { method: 'POST', credentials: 'same-origin' });
    const j = await r.json();
    window.location.href = (j.data && j.data.redirect) || 'sales-admin.php';
  } catch (e) { window.location.href = 'sales-admin.php'; }
}

/* ================= Dense list-view helpers (pagination + bulk select) ================= */
const _sel = new Set();
function selClear(){ _sel.clear(); renderBulkBar(); }
function selIds(){ return [..._sel]; }
function rowToggle(cb){
  const id = +cb.dataset.id;
  cb.checked ? _sel.add(id) : _sel.delete(id);
  cb.closest('tr')?.classList.toggle('sel', cb.checked);
  renderBulkBar();
}
function allToggle(master){
  document.querySelectorAll('input.rowchk').forEach(cb => { cb.checked = master.checked; rowToggle(cb); });
}
function renderBulkBar(){
  const bar = document.getElementById('bulkbar');
  if (!bar) return;
  if (_sel.size === 0) { bar.classList.add('hidden'); return; }
  bar.classList.remove('hidden');
  const c = document.getElementById('bulkCount'); if (c) c.textContent = _sel.size;
}
/* pagination footer: pass state {page,pages,total,per} + a reload fn name */
function pagerHtml(st, fn){
  const from = st.total ? (st.page-1)*st.per+1 : 0;
  const to = Math.min(st.page*st.per, st.total);
  let nums = '';
  const span = 2;
  for (let p = Math.max(1, st.page-span); p <= Math.min(st.pages, st.page+span); p++)
    nums += `<button onclick="${fn}(${p})" class="${p===st.page?'bg-indigo-600 text-white':''}">${p}</button>`;
  return `<div class="flex items-center justify-between text-[11px] text-gray-500 pager mt-1 px-1">
    <div>Showing <b>${from}–${to}</b> of <b>${st.total}</b>
      &nbsp;·&nbsp; Rows:
      <select onchange="${fn}(1, this.value)" class="ctl" style="height:22px;padding:0 4px">
        ${[20,50,100].map(n=>`<option ${n===st.per?'selected':''}>${n}</option>`).join('')}</select>
    </div>
    <div class="flex items-center gap-1">
      <button onclick="${fn}(1)" ${st.page<=1?'disabled':''}>&laquo;</button>
      <button onclick="${fn}(${st.page-1})" ${st.page<=1?'disabled':''}>&lsaquo;</button>
      ${nums}
      <button onclick="${fn}(${st.page+1})" ${st.page>=st.pages?'disabled':''}>&rsaquo;</button>
      <button onclick="${fn}(${st.pages})" ${st.page>=st.pages?'disabled':''}>&raquo;</button>
    </div></div>`;
}
