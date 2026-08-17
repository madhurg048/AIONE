<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Governance Map');
sales_header($me, 'Governance · Map');
sales_nav('govmap');
?>
<style>
  .gtab { padding:4px 10px; border-radius:8px; font-size:12px; color:#4b5563; cursor:pointer; }
  .gtab.on { background:#2563eb; color:#fff; font-weight:600; }
  .code { font-family:ui-monospace,Consolas,monospace; font-size:10.5px; font-weight:700;
          border-radius:5px; padding:1px 5px; white-space:nowrap; }
  .c-kpa{background:#eef2ff;color:#4338ca}.c-kra{background:#f0f9ff;color:#0369a1}
  .c-kpi{background:#ecfeff;color:#0e7490}
  .c-sla{background:#fef3c7;color:#92400e}.c-esc{background:#fee2e2;color:#b91c1c}
  .c-policy{background:#dcfce7;color:#166534}.c-link{background:#f3e8ff;color:#7e22ce}
  .c-esl{background:#ffe4e6;color:#9f1239}
  .vtag { font-size:9px; color:#94a3b8; margin-left:4px; }
  #graph text { font-family:Inter,system-ui,sans-serif; }
</style>
<main class="p-3 space-y-2">

  <div class="flex items-center gap-2 flex-wrap">
    <span class="text-sm font-bold text-gray-700 tracking-wide">GOVERNANCE MAP</span>
    <span id="counts" class="text-xs text-gray-400"></span>
    <div class="flex-1"></div>
    <span class="gtab on" data-tab="graph" onclick="tab('graph')">Graph</span>
    <span class="gtab" data-tab="codes" onclick="tab('codes')">Codes</span>
    <span class="gtab" data-tab="links" onclick="tab('links')">Links</span>
    <span class="gtab" data-tab="sla" onclick="tab('sla')">SLA → escalations</span>
    <span class="gtab" data-tab="history" onclick="tab('history')">Version history</span>
  </div>

  <!-- ============ GRAPH ============ -->
  <div id="t-graph" class="card p-2">
    <div class="text-[11px] text-gray-500 px-1 pb-1">
      <b>How the chain reads:</b>
      <span class="code c-kpa">KPA</span> the focus <i>area</i> &rarr;
      <span class="code c-kra">KRA</span> the <i>outcome</i> you must deliver &rarr;
      <span class="code c-kpi">KPI</span> the <i>measure</i> of success &rarr;
      <span class="code c-sla">SLA</span> the TAT that proves it &rarr;
      <span class="code c-esc">ESC</span> who it escalates to when missed;
      <span class="code c-policy">POL</span> the policy that demands it.
      <span class="text-gray-400">Click any node to open its links, history and editor.</span>
    </div>
    <div class="overflow-auto" style="max-height:74vh"><svg id="graph" width="100%" height="620"></svg></div>
  </div>

  <!-- ============ CODES ============ -->
  <div id="t-codes" class="card p-3 hidden">
    <div class="flex items-center gap-2 mb-2">
      <input id="qCode" oninput="renderCodes()" placeholder="Search code, name or person…" class="ctl" style="min-width:280px">
      <select id="fType" onchange="renderCodes()" class="ctl">
        <option value="">All types</option><option value="kpa">KPA</option><option value="kra">KRA</option><option value="kpi">KPI</option>
        <option value="sla">SLA / TAT</option><option value="esc">Escalation rule</option><option value="policy">Policy / cert</option>
      </select>
    </div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Code</th><th>Ver</th><th>Type</th><th>Name</th><th>Context</th><th>People</th><th>Approval</th><th>Links</th><th></th>
    </tr></thead><tbody id="codeRows"></tbody></table></div>
    <div class="text-[10px] text-gray-400 mt-2">Draft → submitted → reviewed → approved. The reviewer can't be the author, and the approver can't be the author or the reviewer. Editing an approved record sends it back to draft.</div>
  </div>

  <!-- ============ LINKS ============ -->
  <div id="t-links" class="card p-3 hidden">
    <form id="linkForm" onsubmit="addLink(event)" class="flex items-end gap-2 flex-wrap mb-3 text-xs hidden">
      <label class="block">From<select name="from" id="selFrom" class="ctl block mt-1" style="min-width:230px"></select></label>
      <label class="block">Relationship<select name="relation" id="selRel" class="ctl block mt-1"></select></label>
      <label class="block">To<select name="to" id="selTo" class="ctl block mt-1" style="min-width:230px"></select></label>
      <label class="block">Note<input name="note" class="ctl block mt-1" placeholder="optional"></label>
      <button class="btn-p"><i class="fa-solid fa-link"></i> Link</button>
    </form>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Link</th><th>From</th><th>Relationship</th><th>To</th><th>People</th><th>Note</th><th>Created</th><th></th>
    </tr></thead><tbody id="linkRows"></tbody></table></div>
  </div>

  <!-- ============ SLA -> ESCALATIONS ============ -->
  <div id="t-sla" class="card p-3 hidden">
    <div class="text-[10px] text-gray-400 mb-2">Every escalation event carries the SLA/TAT policy that was breached and the escalation-matrix rule that routed it — both codes, so a breach can be traced end to end.</div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Escalation</th><th>SLA / TAT policy</th><th>Matrix rule</th><th>Level</th><th>Escalated to</th><th>Subject</th><th>Status</th><th>When</th>
    </tr></thead><tbody id="slaRows"></tbody></table></div>
  </div>

  <!-- ============ HISTORY ============ -->
  <div id="t-history" class="card p-3 hidden">
    <div class="text-[10px] text-gray-400 mb-2">Version control across all governance records — every create, edit, link and unlink, newest first.</div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Revision</th><th>Record</th><th>Ver</th><th>Action</th><th>What changed</th><th>By</th><th>When</th>
    </tr></thead><tbody id="histRows"></tbody></table></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
let CAT = [], LINKS = [], RELS = [], CAN = false, SLAESC = [];
const TYPE_LABEL = { kpa:'KPA', kra:'KRA', kpi:'KPI', sla:'SLA / TAT', esc:'Escalation rule', policy:'Policy / cert' };

function codeTag(type, code){ return `<span class="code c-${type}">${esc(code||'')}</span>`; }

const ST_COLOR = { draft:'gray', pending_review:'yellow', reviewed:'blue', approved:'green', rejected:'red' };
function statusBadge(c){
  const s = c.status || 'draft';
  const who = s === 'approved' ? (c.approved_by_name ? ' by ' + c.approved_by_name : '')
            : s === 'reviewed' ? (c.reviewed_by_name ? ' by ' + c.reviewed_by_name : '') : '';
  return badge(s.replace('_',' '), ST_COLOR[s] || 'gray') +
    (who ? `<div class="text-[9px] text-gray-400">${esc(who.trim())}</div>` : '') +
    (s === 'rejected' && c.reject_reason ? `<div class="text-[9px] text-red-500">${esc(c.reject_reason)}</div>` : '');
}
/* which workflow step is offered next (the server enforces who may actually do it) */
function wfButtons(c){
  if (!CAN) return '';
  const s = c.status || 'draft';
  const b = (act, label, cls) =>
    `<button onclick="wf('${c.type}',${c.id},'${act}')" class="text-[10px] ${cls} px-2 py-0.5 rounded mr-1">${label}</button>`;
  if (s === 'draft')          return b('submit','Submit','bg-indigo-100 text-indigo-700');
  if (s === 'pending_review') return b('review','Review','bg-blue-100 text-blue-700') + b('reject','Reject','bg-red-50 text-red-600');
  if (s === 'reviewed')       return b('approve','Approve','bg-green-100 text-green-700') + b('reject','Reject','bg-red-50 text-red-600');
  if (s === 'rejected')       return b('submit','Resubmit','bg-indigo-100 text-indigo-700');
  return b('reopen','Reopen','bg-gray-100 text-gray-600');    // approved
}
async function wf(type, id, action){
  let reason = null;
  if (action === 'reject') { reason = prompt('Why is it being rejected?'); if (reason === null) return; }
  const res = await apiSend('governance-map.php?workflow=1', { type, id, action, reason });
  if (res.success){ toast(res.data.message); load(); } else toast(res.error || 'Failed', false);
}

async function load(){
  const { data } = await apiGet('governance-map.php', {});
  CAT = data.catalog || []; LINKS = data.links || []; RELS = data.relations || []; CAN = !!data.can_edit;
  SLAESC = data.sla_escalations || [];
  document.getElementById('counts').textContent = '· ' +
    Object.entries(data.counts || {}).map(([k,v]) => `${v} ${TYPE_LABEL[k]||k}`).join(' · ') + ` · ${LINKS.length} links`;
  if (CAN) {
    document.getElementById('linkForm').classList.remove('hidden');
    const opts = CAT.map(c => `<option value="${c.type}:${c.id}">${esc(c.code)} — ${esc((c.name||'').slice(0,60))}</option>`).join('');
    document.getElementById('selFrom').innerHTML = opts;
    document.getElementById('selTo').innerHTML = opts;
    document.getElementById('selRel').innerHTML = RELS.map(r => `<option value="${r}">${r.replace(/_/g,' ')}</option>`).join('');
  }
  renderCodes(); renderLinks(); renderSla(); drawGraph();
}

function linksOf(type, id){
  return LINKS.filter(l => (l.from_type===type && +l.from_id===+id) || (l.to_type===type && +l.to_id===+id));
}

function renderCodes(){
  const q = (document.getElementById('qCode').value||'').toLowerCase().trim();
  const ft = document.getElementById('fType').value;
  const rows = CAT.filter(c => (!ft || c.type===ft) &&
    (!q || [c.code,c.name,c.person,c.context].some(v => (v||'').toLowerCase().includes(q))));
  document.getElementById('codeRows').innerHTML = rows.map(c => `
    <tr class="${+c.is_active ? '' : 'opacity-50'}">
      <td>${codeTag(c.type, c.code)}</td>
      <td class="text-[10px] text-gray-500">v${c.version||1}</td>
      <td class="text-[11px]">${TYPE_LABEL[c.type]||c.type}</td>
      <td class="font-medium">${esc((c.name||'').slice(0,80))}</td>
      <td class="text-[11px] text-gray-500">${esc(c.context||'')}</td>
      <td class="text-[11px]">${esc(c.person||'—')}</td>
      <td>${statusBadge(c)}</td>
      <td class="text-center">${linksOf(c.type,c.id).length || '<span class="text-gray-300">0</span>'}</td>
      <td class="rowact whitespace-nowrap">${CAN?`<button onclick="editRec('${c.type}',${c.id})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded mr-1">Edit</button>`:''}${wfButtons(c)}
        <button onclick="showHistory('${c.type}',${c.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">History</button></td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-3">Nothing matches</td></tr>';
}

function renderLinks(){
  document.getElementById('linkRows').innerHTML = LINKS.map(l => `
    <tr>
      <td>${codeTag('link', l.link_ref)}</td>
      <td>${codeTag(l.from_type, l.from_code)} <span class="text-[11px]">${esc((l.from_name||'').slice(0,42))}</span></td>
      <td class="text-[11px] text-indigo-600">${esc((l.relation||'').replace(/_/g,' '))}</td>
      <td>${codeTag(l.to_type, l.to_code)} <span class="text-[11px]">${esc((l.to_name||'').slice(0,42))}</span></td>
      <td class="text-[10px] text-gray-500">${esc([l.from_person,l.to_person].filter(Boolean).join(' → ') || '—')}</td>
      <td class="text-[11px] text-gray-500">${esc(l.note||'')}</td>
      <td class="text-[10px] text-gray-400">${esc((l.created_at||'').slice(0,16))}<div>${esc(l.created_by_name||'')}</div></td>
      <td class="rowact">${CAN?`<button onclick="delLink(${l.id})" class="text-[10px] bg-red-50 text-red-600 px-2 py-0.5 rounded">Remove</button>`:''}</td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-3">No links yet — connect a KRA to the SLA that measures it, or a policy to the escalation rule that enforces it.</td></tr>';
}

function renderSla(){
  document.getElementById('slaRows').innerHTML = SLAESC.map(r => `
    <tr>
      <td>${codeTag('esl', r.esl_ref)}</td>
      <td>${r.sla_ref ? codeTag('sla', r.sla_ref) + ' <span class="text-[11px]">' + esc((r.sla_name||'').slice(0,34)) + '</span>' : '<span class="text-gray-300 text-[11px]">none</span>'}</td>
      <td>${r.esc_ref ? codeTag('esc', r.esc_ref) : '<span class="text-gray-400 text-[10px]">manager chain</span>'}</td>
      <td class="text-center">L${r.level}</td>
      <td class="text-[11px]">${esc(r.escalated_to||'—')}</td>
      <td class="text-[11px] text-gray-600">${esc((r.subject||'').slice(0,46))}</td>
      <td>${badge(r.status, r.status==='open'?'red':'green')}</td>
      <td class="text-[10px] text-gray-400">${esc((r.created_at||'').slice(0,16))}</td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-3">No escalation events yet</td></tr>';
}

async function loadHistory(){
  const { data } = await apiGet('governance-map.php', { changelog:1, limit:150 });
  document.getElementById('histRows').innerHTML = (data||[]).map(h => `
    <tr>
      <td class="code c-link">${esc(h.rev_ref)}</td>
      <td>${h.obj_code ? codeTag(h.obj_type, h.obj_code) : '<span class="text-gray-400 text-[10px]">'+esc(h.obj_type)+'</span>'}</td>
      <td class="text-[10px] text-gray-500">v${h.version}</td>
      <td>${badge(h.action, h.action==='deleted'||h.action==='unlinked'?'red':h.action==='created'||h.action==='linked'?'green':'yellow')}</td>
      <td class="text-[11px]">${esc(h.summary||'')}</td>
      <td class="text-[11px]">${esc(h.changed_by_name||'system')}</td>
      <td class="text-[10px] text-gray-400">${esc((h.created_at||'').slice(0,16))}</td>
    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-gray-400 py-3">No changes recorded yet</td></tr>';
}

async function showHistory(type, id){
  const { data } = await apiGet('governance-map.php', { history:1, type, id });
  const mine = linksOf(type, id);
  openModal(`
    <div class="text-xs space-y-3">
      <div>${codeTag(type, data.code)} <span class="text-gray-500">version history</span></div>
      <div class="flex items-center gap-2">${(data.can_edit && type !== 'link') ? `<button onclick="closeModal();editRec('${type}',${id})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Edit this record</button>` : ''}
        ${statusBadge({status:(data.approval||{}).status || 'draft',
                          approved_by_name:(data.approval||{}).approved_by_name,
                          reviewed_by_name:(data.approval||{}).reviewed_by_name})}</div>
      <div class="overflow-x-auto max-h-56 overflow-y-auto"><table class="dense"><thead><tr>
        <th>Rev</th><th>Ver</th><th>Action</th><th>What</th><th>By</th><th>When</th><th></th></tr></thead><tbody>
        ${(data.history||[]).map(h=>`<tr><td class="text-[10px]">${esc(h.rev_ref)}</td><td>v${h.version}</td>
          <td>${badge(h.action, h.action==='deleted'||h.action==='unlinked'?'red':'green')}</td>
          <td class="text-[11px]">${esc(h.summary||'')}</td><td class="text-[11px]">${esc(h.changed_by_name||'system')}</td>
          <td class="text-[10px] text-gray-400">${esc((h.created_at||'').slice(0,16))}</td>
          <td class="rowact">${(data.can_edit && +h.restorable) ? `<button onclick="revertTo('${type}',${id},${h.id},${h.version})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Restore</button>` : ''}</td></tr>`).join('')
          || '<tr><td colspan="7" class="text-gray-400 text-center py-2">No changes recorded yet</td></tr>'}
      </tbody></table></div>
      <div>
        <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Linked records (${mine.length})</div>
        ${mine.map(l=>`<div class="text-[11px] py-0.5">${codeTag(l.from_type,l.from_code)} <span class="text-indigo-600">${esc((l.relation||'').replace(/_/g,' '))}</span> ${codeTag(l.to_type,l.to_code)}</div>`).join('') || '<span class="text-gray-400 text-[11px]">Not linked to anything yet</span>'}
      </div>
    </div>`, 'Record history');
}

/* ---- edit a record without leaving the map ---- */
async function editRec(type, id){
  const { data } = await apiGet('governance-map.php', { record:1, type, id });
  if (!data) return toast('Could not open that record', false);
  const f = (fl) => {
    const v = data.row[fl.key] ?? '';
    if (fl.type === 'textarea') return `<textarea name="${fl.key}" rows="3" class="ctl w-full mt-0.5" style="height:auto">${esc(v)}</textarea>`;
    if (fl.type === 'select') return `<select name="${fl.key}" class="ctl w-full mt-0.5">${
      (fl.options||[]).map(o=>`<option ${String(o)===String(v)?'selected':''} value="${esc(o)}">${esc(o||'—')}</option>`).join('')}</select>`;
    const t = fl.type === 'number' ? 'number' : fl.type === 'date' ? 'date' : 'text';
    const step = fl.type === 'number' ? ' step="0.5"' : '';
    return `<input type="${t}"${step} name="${fl.key}" value="${esc(v)}" class="ctl w-full mt-0.5">`;
  };
  openModal(`<form onsubmit="saveRec(event,'${type}',${id})" class="space-y-2 text-xs">
      <div>${codeTag(type, data.code)} <span class="text-gray-500">editing in place</span></div>
      ${data.fields.map(fl => `<label class="block">${esc(fl.label)}${f(fl)}</label>`).join('')}
      <div class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
        Saving creates a new version and sends the record back to <b>draft</b> — it needs review and approval again.</div>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save changes</button></div>
    </form>`, 'Edit ' + (TYPE_LABEL[type]||type));
}
async function saveRec(e, type, id){
  e.preventDefault();
  const values = {};
  [...e.target.elements].forEach(el => { if (el.name) values[el.name] = el.value; });
  const res = await apiSend('governance-map.php?record=1', { type, id, values }, 'PATCH');
  if (res.success){ toast(res.data.message); closeModal(); load(); } else toast(res.error||'Failed', false);
}

async function revertTo(type, id, revId, ver){
  if (!confirm(`Restore this record to version ${ver}? The current content is kept in history, and the restored record goes back to draft for review and approval.`)) return;
  const res = await apiSend('governance-map.php?revert=1', { type, id, revision_id: revId });
  if (res.success){ toast(res.data.message); closeModal(); load(); } else toast(res.error || 'Failed', false);
}

async function addLink(e){
  e.preventDefault(); const f = e.target;
  const [ft, fi] = f.from.value.split(':'), [tt, ti] = f.to.value.split(':');
  const res = await apiSend('governance-map.php', { from_type:ft, from_id:+fi, to_type:tt, to_id:+ti,
    relation:f.relation.value, note:f.note.value });
  if (res.success){ toast('Linked · ' + res.data.summary); f.note.value=''; load(); } else toast(res.error||'Failed', false);
}
async function delLink(id){
  if (!confirm('Remove this link? The removal is kept in the version history.')) return;
  const res = await apiSend('governance-map.php?id='+id, {}, 'DELETE');
  if (res.success){ toast('Link removed'); load(); } else toast(res.error||'Failed', false);
}

/* ---------------- the graph ---------------- */
function drawGraph(){
  const svg = document.getElementById('graph');
  const cols = ['kpa','kra','kpi','sla','esc','policy'];
  const colX = { kpa:40, kra:220, kpi:400, sla:580, esc:760, policy:930 };
  const pos = {};
  let maxRows = 0;
  cols.forEach(t => {
    const items = CAT.filter(c => c.type === t).slice(0, 24);
    maxRows = Math.max(maxRows, items.length);
    items.forEach((c, i) => { pos[t+':'+c.id] = { x: colX[t], y: 60 + i*46, c }; });
  });
  const h = Math.max(360, 90 + maxRows*46);
  svg.setAttribute('height', h);
  svg.setAttribute('viewBox', `0 0 1090 ${h}`);
  const esc2 = s => (s||'').replace(/[<>&]/g, m => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[m]));
  const fill = { kpa:'#eef2ff', kra:'#f0f9ff', kpi:'#ecfeff', sla:'#fef3c7', esc:'#fee2e2', policy:'#dcfce7' };
  const stroke = { kpa:'#6366f1', kra:'#0ea5e9', kpi:'#06b6d4', sla:'#f59e0b', esc:'#ef4444', policy:'#22c55e' };

  let edges = '';
  LINKS.forEach(l => {
    const a = pos[l.from_type+':'+l.from_id], b = pos[l.to_type+':'+l.to_id];
    if (!a || !b) return;
    const x1 = a.x + 140, y1 = a.y + 15, x2 = b.x, y2 = b.y + 15, mx = (x1 + x2) / 2;
    edges += `<path d="M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}" fill="none" stroke="#94a3b8"
                 stroke-width="1.3" marker-end="url(#arw)"><title>${esc2(l.from_code + ' ' + l.relation + ' ' + l.to_code)}</title></path>`;
  });

  let nodes = '';
  cols.forEach(t => {
    nodes += `<text x="${colX[t]}" y="34" font-size="11" font-weight="700" fill="#6b7280">${esc2((TYPE_LABEL[t]||t).toUpperCase())}</text>`;
    CAT.filter(c => c.type === t).slice(0, 24).forEach(c => {
      const p = pos[t+':'+c.id];
      nodes += `<g style="cursor:pointer" onclick="showHistory('${t}',${c.id})">
        <rect x="${p.x}" y="${p.y}" rx="7" width="140" height="30" fill="${fill[t]}" stroke="${stroke[t]}" stroke-width="1"/>
        <text x="${p.x+8}" y="${p.y+13}" font-size="9.5" font-weight="700" fill="${stroke[t]}">${esc2(c.code)} <tspan fill="#94a3b8" font-weight="400">v${c.version||1}</tspan></text>
        <text x="${p.x+8}" y="${p.y+24}" font-size="9" fill="#475569">${esc2((c.name||'').slice(0,26))}</text>
        <title>${esc2(c.code + ' — ' + (c.name||'') + (c.person ? ' · ' + c.person : ''))}</title></g>`;
    });
    if (!CAT.some(c => c.type === t))
      nodes += `<text x="${colX[t]}" y="66" font-size="10" fill="#cbd5e1">none yet</text>`;
  });

  svg.innerHTML = `<defs><marker id="arw" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="#94a3b8"/></marker></defs>${edges}${nodes}`;
}

function tab(t){
  ['graph','codes','links','sla','history'].forEach(x => {
    document.getElementById('t-'+x).classList.toggle('hidden', x !== t);
    document.querySelector(`.gtab[data-tab="${x}"]`).classList.toggle('on', x === t);
  });
  if (t === 'history') loadHistory();
  if (t === 'graph') drawGraph();
}
load();
</script>
</html>
