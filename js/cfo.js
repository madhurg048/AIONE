/* ==========================================================================
 * CFO cockpit — shared front-end.
 * Every screen in the product is the same shape: a question in the header, a
 * four-to-eight tile strip of numbers that are computed (never typed), filters,
 * a dense table you can drill into, and an AI reading of the same evidence the
 * tiles came from. That shape lives here so the eleven pages stay consistent.
 * ========================================================================== */

const CFO_API = 'cfo.php';
let CFO_META = null;

async function cfoMeta() {
  if (!CFO_META) CFO_META = (await apiGet(CFO_API, { meta: 1 })).data;
  return CFO_META;
}

/* ---------------------------------------------------------------- tiles */
/**
 * tiles: [icon, label, value, sub, tone]
 * tone: '' | good | warn | bad | info — colours the number, not the whole card,
 * so a screen full of tiles doesn't turn into a traffic light.
 */
function cfoStrip(elId, tiles) {
  const el = document.getElementById(elId);
  if (!el) return;
  const tone = { good: '#047857', warn: '#b45309', bad: '#b91c1c', info: '#1d4ed8', '': '#1f2937' };
  el.innerHTML = tiles.filter(Boolean).map(([ic, label, val, sub, t]) => `
    <div class="ctile">
      <div class="ct-l">${ic} ${esc(label)}</div>
      <div class="ct-n" style="color:${tone[t || ''] || tone['']}">${val}</div>
      <div class="ct-s">${esc(sub || '')}</div>
    </div>`).join('');
}

const cfoPct = (n, d) => (d ? Math.round((n / d) * 100) : 0) + '%';
const cfoNum = n => Number(n || 0).toLocaleString('en-IN');
const cfoDate = d => (!d ? '—' : new Date(d + (String(d).length <= 10 ? 'T00:00:00' : '')).toLocaleDateString('en-IN',
  { day: '2-digit', month: 'short', year: 'numeric' }));
const cfoDT = d => (!d ? '—' : String(d).slice(0, 16).replace('T', ' '));

/* ---------------------------------------------------------------- badges */
const CFO_ST = {
  open: ['Open', 'blue'], in_progress: ['In progress', 'yellow'], done: ['Done', 'green'],
  overdue: ['Overdue', 'red'], waived: ['Waived', 'gray'],
  draft: ['Draft', 'gray'], submitted: ['Submitted', 'blue'], reviewed: ['Checked', 'purple'],
  approved: ['Approved', 'green'], rejected: ['Returned', 'red'], not_started: ['Not started', 'gray'],
  scheduled: ['Scheduled', 'blue'], signed: ['Signed', 'green'], skipped: ['Skipped', 'gray'],
  verified: ['Verified', 'green'], closed: ['Closed', 'gray'],
  mitigated: ['Mitigated', 'green'], accepted: ['Accepted', 'yellow'],
};
const cfoStatus = s => { const m = CFO_ST[s] || [s, 'gray']; return badge(m[0], m[1]); };

const CFO_PRI = { critical: 'red', high: 'red', medium: 'yellow', low: 'gray' };
const cfoPri = p => badge(p, CFO_PRI[p] || 'gray');

const CFO_BAND = { Critical: '#991b1b', High: '#c2410c', Medium: '#a16207', Low: '#15803d' };
const cfoBand = (band, score) =>
  `<span class="cband" style="background:${CFO_BAND[band] || '#6b7280'}">${esc(band)}${score != null ? ' ' + score : ''}</span>`;

const CFO_RAG = { green: ['#047857', 'Green'], amber: ['#b45309', 'Amber'], red: ['#b91c1c', 'Red'], no_data: ['#9ca3af', 'No data'] };
const cfoRag = r => { const m = CFO_RAG[r] || CFO_RAG.no_data; return `<span class="crag" style="background:${m[0]}">${m[1]}</span>`; };

const CFO_TYPE = { statutory: 'Statutory', internal_review: 'Internal review', rbi: 'RBI', tax: 'Tax', board: 'Board' };
const cfoType = t => badge(CFO_TYPE[t] || t, t === 'rbi' ? 'purple' : t === 'statutory' ? 'red' : t === 'tax' ? 'yellow' : 'blue');

const cfoCode = (c, cls) => c ? `<span class="ccode ${cls || ''}">${esc(c)}</span>` : '<span class="text-gray-300">—</span>';

/* ---------------------------------------------------------------- AI panel */
/**
 * The analysis reads the SAME aggregates the tiles are built from — the endpoint
 * returns the evidence alongside the narrative, and "Show the numbers" reveals
 * it, so nothing on screen is an unverifiable claim.
 */
function cfoAiPanel(area, question) {
  return `<div class="card p-0 overflow-hidden">
    <div class="flex items-center gap-2 px-3 py-2 border-b bg-gradient-to-r from-indigo-50 to-white">
      <i class="fa-solid fa-wand-magic-sparkles text-indigo-500"></i>
      <div class="text-[11px] font-semibold text-gray-700">AI analysis</div>
      <div class="text-[10px] text-gray-400 hidden sm:block">${esc(question || '')}</div>
      <div class="flex-1"></div>
      <button onclick="cfoAiRun('${area}', true)" class="text-[10px] border rounded px-2 py-0.5 hover:bg-gray-50">
        <i class="fa-solid fa-rotate"></i> Run</button>
      <button onclick="cfoAiEvidence()" class="text-[10px] border rounded px-2 py-0.5 hover:bg-gray-50">Show the numbers</button>
    </div>
    <div id="cfoAiBody" class="p-3 text-[12px] text-gray-700 leading-relaxed">
      <span class="text-gray-400">Click Run for a reading of the figures above.</span></div>
    <pre id="cfoAiEv" class="hidden bg-gray-50 border-t text-[10px] p-3 overflow-x-auto"></pre>
  </div>`;
}

let _cfoAiEv = null;
async function cfoAiRun(area) {
  const body = document.getElementById('cfoAiBody');
  body.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-indigo-400"></i> <span class="text-gray-400">Reading the register…</span>';
  const res = await apiSend(`${CFO_API}?ai=${encodeURIComponent(area)}`, {});
  if (!res.success) { body.innerHTML = `<span class="text-red-600">${esc(res.error || 'AI unavailable')}</span>`; return; }
  _cfoAiEv = res.data.evidence;
  body.innerHTML = res.data.analysis
    ? renderAiSummary(res.data.analysis) +
      `<div class="text-[10px] text-gray-400 mt-2">source: ${esc(res.data.source || 'n/a')} · as of ${esc(res.data.as_of || '')}</div>`
    : '<span class="text-gray-500">The AI service is not reachable — the figures above are still live. '
      + 'Use “Show the numbers” for the raw evidence.</span>';
}
function cfoAiEvidence() {
  const p = document.getElementById('cfoAiEv');
  if (!_cfoAiEv) { toast('Run the analysis first', false); return; }
  p.textContent = JSON.stringify(_cfoAiEv, null, 2);
  p.classList.toggle('hidden');
}

/* ---------------------------------------------------------------- drill-down */
function cfoRow(label, value) {
  return `<div class="flex gap-2 py-1 border-b border-gray-100 text-[11px]">
    <div class="w-40 shrink-0 text-gray-500">${esc(label)}</div>
    <div class="flex-1 text-gray-800">${value == null || value === '' ? '<span class="text-gray-300">—</span>' : value}</div></div>`;
}

function cfoHistory(rows) {
  if (!rows || !rows.length) return '<div class="text-[11px] text-gray-400">Nothing recorded yet.</div>';
  return `<table class="dense w-full"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th></tr></thead><tbody>
    ${rows.map(h => `<tr><td class="text-[10px] text-gray-500">${esc(cfoDT(h.created_at))}</td>
      <td class="text-[11px]">${esc(h.user_name || '—')}</td><td>${badge(h.action, 'gray')}</td>
      <td class="text-[11px] text-gray-600">${esc(h.summary || '')}</td></tr>`).join('')}</tbody></table>`;
}

function cfoEvidenceList(rows, entityType, entityId) {
  const add = CFO_META && CFO_META.can_write
    ? `<button onclick="cfoAttach('${entityType}',${entityId})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">
         <i class="fa-solid fa-paperclip"></i> Attach evidence</button>` : '';
  const list = (rows && rows.length)
    ? rows.map(e => `<div class="flex items-center gap-2 text-[11px] py-0.5">
        <i class="fa-solid fa-file-lines text-gray-400"></i>
        <span class="font-medium">${esc(e.title)}</span>
        ${e.file_name ? `<span class="text-gray-500">${esc(e.file_name)}</span>` : ''}
        ${e.url ? `<a href="${esc(e.url)}" target="_blank" class="text-indigo-600 underline">link</a>` : ''}
        <span class="text-gray-400 text-[10px]">${esc(cfoDT(e.created_at))}</span></div>`).join('')
    : '<div class="text-[11px] text-amber-700">No evidence attached — an auditor would find nothing here.</div>';
  return `<div class="mt-2">${list}<div class="mt-1">${add}</div></div>`;
}

/** Attach evidence: a document already in the DMS, or an external link. */
async function cfoAttach(entityType, entityId) {
  const { data } = await apiGet(CFO_API, { area: 'evidence' });
  const files = data.doc_files || [];
  openModal(`<form onsubmit="cfoAttachSave(event,'${entityType}',${entityId})" class="space-y-2 text-xs">
    <label class="block">Title<input name="title" required class="ctl w-full mt-0.5" placeholder="e.g. GSTR-1 acknowledgement, Sep 2026"></label>
    <label class="block">Document from the library
      <select name="doc_id" class="ctl w-full mt-0.5"><option value="">— none —</option>
        ${files.map(f => `<option value="${f.id}">${esc(f.orig_name)}${f.folder ? ' · ' + esc(f.folder) : ''}</option>`).join('')}
      </select></label>
    <label class="block">…or an external link<input name="url" class="ctl w-full mt-0.5" placeholder="https://…"></label>
    <label class="block">Note<textarea name="note" rows="2" class="ctl w-full mt-0.5" style="height:auto"></textarea></label>
    <div class="text-[10px] text-gray-500">Documents live in the CRM's Document Management module — this only records
      which filing or risk they prove, so the same file is never uploaded twice.</div>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Attach</button></div></form>`, 'Attach evidence');
}
async function cfoAttachSave(e, entityType, entityId) {
  e.preventDefault();
  const f = e.target;
  const res = await apiSend(`${CFO_API}?action=evidence_link`, {
    entity_type: entityType, entity_id: entityId, title: f.title.value,
    doc_id: f.doc_id.value || null, url: f.url.value || null, note: f.note.value || null,
  });
  if (res.success) { toast('Evidence attached'); closeModal(); if (window.load) load(); }
  else toast(res.error || 'Failed', false);
}

/* ---------------------------------------------------------------- misc */
/** Small "who owns it" cell: person if resolved, else the role that owns it. */
function cfoOwner(row) {
  if (row.owner_name) return `${esc(row.owner_name)}${row.owner_role ? `<div class="text-[9px] text-gray-400">${esc(row.owner_role)}</div>` : ''}`;
  return row.owner_role ? `<span class="text-gray-600">${esc(row.owner_role)}</span>
    <div class="text-[9px] text-amber-600">no person mapped</div>` : '<span class="text-gray-300">Unassigned</span>';
}

function cfoDueCell(due, status) {
  if (!due) return '<span class="text-gray-300">—</span>';
  const d = new Date(due + 'T00:00:00'), today = new Date(new Date().toDateString());
  const days = Math.round((d - today) / 86400000);
  const late = days < 0 && !['done', 'waived', 'approved', 'signed', 'closed', 'verified'].includes(status);
  return `${cfoDate(due)}<div class="text-[9px] ${late ? 'text-red-600 font-semibold' : 'text-gray-400'}">
    ${late ? Math.abs(days) + ' day' + (Math.abs(days) === 1 ? '' : 's') + ' late'
           : (days === 0 ? 'due today' : (days > 0 ? 'in ' + days + ' day' + (days === 1 ? '' : 's') : 'closed'))}</div>`;
}

/** Run the scheduler, then reload the page's data. */
async function cfoRunScheduler() {
  const res = await apiSend(`${CFO_API}?action=scheduler`, {});
  if (res.success) { toast(res.data.message || 'Scheduler run'); if (window.load) load(); }
  else toast(res.error || 'Failed', false);
}
