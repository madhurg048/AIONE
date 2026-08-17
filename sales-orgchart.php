<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Org Chart');
sales_header($me, 'Organisation');
sales_nav('orgchart');
?>
<style>
  #chartWrap { background:
      radial-gradient(circle, #e2e8f0 1px, transparent 1px) 0 0/22px 22px, #fbfcfe;
      border-radius:10px; cursor:grab; overflow:hidden; position:relative; }
  #chartWrap.drag { cursor:grabbing; }
  #chart text { font-family:Inter,system-ui,-apple-system,sans-serif; }
  .nodeBox { cursor:pointer; }
  .nodeBox:hover .card-bg { filter:brightness(.985); stroke-width:2; }
  .zbtn { width:28px; height:28px; border:1px solid #e5e7eb; background:#fff; border-radius:7px;
          font-size:12px; color:#475569; }
  .zbtn:hover { background:#f8fafc; color:#4f46e5; }
  .legend i { width:9px; height:9px; border-radius:2px; display:inline-block; }
</style>
<main class="p-3 space-y-2">

  <div class="flex items-center gap-2 flex-wrap">
    <span class="text-sm font-bold text-gray-700 tracking-wide">ORG CHART</span>
    <span id="stats" class="text-xs text-gray-400"></span>
    <div class="flex-1"></div>
    <input id="q" oninput="applyFilter()" placeholder="Find a person, team or designation…" class="ctl" style="min-width:240px">
    <select id="deptFilter" onchange="applyFilter()" class="ctl"><option value="">All departments</option></select>
    <select id="rootPick" onchange="setRoot(this.value)" class="ctl" title="Show the chart from one person down"></select>
    <button onclick="expandAll(true)" class="ctl hover:bg-gray-50" title="Expand all"><i class="fa-solid fa-angles-down"></i></button>
    <button onclick="expandAll(false)" class="ctl hover:bg-gray-50" title="Collapse to two levels"><i class="fa-solid fa-angles-up"></i></button>
    <button onclick="jumpToMe()" class="btn-p"><i class="fa-solid fa-location-crosshairs"></i> Where am I</button>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-4 gap-2">
    <div class="lg:col-span-3">
      <div id="chartWrap" style="height:74vh">
        <svg id="chart" width="100%" height="100%"></svg>
        <div class="absolute top-2 right-2 flex flex-col gap-1">
          <button class="zbtn" onclick="zoom(1.2)" title="Zoom in"><i class="fa-solid fa-plus"></i></button>
          <button class="zbtn" onclick="zoom(1/1.2)" title="Zoom out"><i class="fa-solid fa-minus"></i></button>
          <button class="zbtn" onclick="fit()" title="Fit to screen"><i class="fa-solid fa-expand"></i></button>
        </div>
        <div class="absolute bottom-2 left-2 legend flex items-center gap-3 text-[10px] text-gray-500 bg-white/85 rounded-md px-2 py-1">
          <span><i style="background:#4f46e5"></i> Sales</span><span><i style="background:#0ea5e9"></i> Service</span>
          <span><i style="background:#16a34a"></i> HR</span><span><i style="background:#f59e0b"></i> IT</span>
          <span><i style="background:#a855f7"></i> Support</span><span><i style="background:#94a3b8"></i> Other</span>
          <span class="text-gray-400">· drag to pan · scroll to zoom · click a box to open it</span>
        </div>
      </div>
    </div>
    <div class="card p-3" id="detail" style="max-height:74vh;overflow:auto">
      <div class="text-[10px] uppercase tracking-wide text-gray-400">Selected person</div>
      <div class="text-xs text-gray-400 mt-2">Click anyone on the chart to see their details, reporting line and direct reports.</div>
    </div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
let PEOPLE = [], BY = {}, KIDS = {}, ROOTS = [], ME = null, ORG_NAME = 'Organisation';
let DEPTS = [], DEPT_OF = {};          // synthetic department tier for people with no manager
let COLLAPSED = new Set(), ROOT_ID = null, SEL = null, HITS = new Set(), DIMMED = false;
let view = { x: 0, y: 0, k: 1 };
const ORG = 'ORG';                     // synthetic top node, so the chart is one pyramid
const isSynthetic = id => typeof id === 'string';

const NW = 186, NH = 56, HGAP = 26, VGAP = 78;      // node box + spacing
const DEPT_COLOR = { Sales:'#4f46e5', Service:'#0ea5e9', HR:'#16a34a', IT:'#f59e0b', Support:'#a855f7' };
const dcol = d => DEPT_COLOR[d] || '#94a3b8';
const initials = n => (n||'?').split(' ').filter(Boolean).map(w=>w[0]).slice(0,2).join('').toUpperCase();
const xesc = s => (s||'').replace(/[<>&]/g, m => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[m]));
const clip = (s, n) => { s = s || ''; return s.length > n ? s.slice(0, n-1) + '…' : s; };

async function load(){
  const { data } = await apiGet('orgchart.php', {});
  PEOPLE = data.people || []; ROOTS = data.roots || []; ME = data.me;
  ORG_NAME = data.org_name || 'Organisation';
  BY = {}; KIDS = {};
  PEOPLE.forEach(p => { BY[p.id] = p; if (p.manager_id) (KIDS[p.manager_id] = KIDS[p.manager_id] || []).push(p.id); });

  // A real org chart is one pyramid. Anyone with no manager hangs off their
  // DEPARTMENT, and the departments hang off the organisation — so the top of
  // the chart reads company → departments → reporting lines, instead of twenty
  // unconnected boxes in a row.
  DEPT_OF = {}; const seen = {};
  ROOTS.forEach(id => {
    const d = BY[id].department || 'Unassigned';
    (seen[d] = seen[d] || []).push(id);
  });
  DEPTS = Object.keys(seen).sort((a, b) => (a === 'Unassigned') - (b === 'Unassigned') || a.localeCompare(b));
  DEPTS.forEach(d => { DEPT_OF['D:' + d] = seen[d]; });
  // stable order: managers first, then alphabetical — keeps the chart from reshuffling
  Object.values(KIDS).forEach(a => a.sort((x, y) =>
    ((KIDS[y]||[]).length - (KIDS[x]||[]).length) || BY[x].full_name.localeCompare(BY[y].full_name)));

  document.getElementById('stats').textContent =
    `· ${data.stats.headcount} people · ${data.stats.managers} with reports · ${data.stats.departments} departments`;
  const depts = [...new Set(PEOPLE.map(p => p.department).filter(Boolean))].sort();
  document.getElementById('deptFilter').innerHTML = '<option value="">All departments</option>' +
    depts.map(d => `<option>${esc(d)}</option>`).join('');
  document.getElementById('rootPick').innerHTML = '<option value="">Whole organisation</option>' +
    PEOPLE.filter(p => (KIDS[p.id]||[]).length).sort((a,b)=>a.full_name.localeCompare(b.full_name))
          .map(p => `<option value="${p.id}">From ${esc(p.full_name)} down</option>`).join('');

  collapseToDepth(2);
  draw(); fit();
  if (ME && BY[ME]) setTimeout(jumpToMe, 120);
}

/* ---------- which nodes are shown ---------- */
function topNodes(){ return ROOT_ID ? [ROOT_ID] : [ORG]; }
function allChildren(id){
  if (id === ORG) return DEPTS.map(d => 'D:' + d);
  if (typeof id === 'string' && id.startsWith('D:')) return DEPT_OF[id] || [];
  return KIDS[id] || [];
}
function childrenOf(id){ return COLLAPSED.has(id) ? [] : allChildren(id); }
function subtreeCount(id){
  let n = 0;
  (allChildren(id) || []).forEach(k => { if (!isSynthetic(k)) n++; n += subtreeCount(k); });
  return n;
}
function collapseToDepth(d){
  COLLAPSED = new Set();
  const walk = (id, depth) => {
    if (depth >= d && allChildren(id).length) COLLAPSED.add(id);
    allChildren(id).forEach(k => walk(k, depth + 1));
  };
  topNodes().forEach(r => walk(r, 1));
}
function expandAll(open){
  if (open) COLLAPSED = new Set(); else collapseToDepth(2);
  draw();
}
function toggle(id, ev){
  if (ev) ev.stopPropagation();
  COLLAPSED.has(id) ? COLLAPSED.delete(id) : COLLAPSED.add(id);
  draw();
}

/* ---------- tidy layout: children centred under their parent ---------- */
function layout(){
  const pos = {};
  let cursor = 0;                                   // next free leaf slot
  const place = (id, depth) => {
    const kids = childrenOf(id);
    let x;
    if (!kids.length) { x = cursor; cursor += NW + HGAP; }
    else {
      const xs = kids.map(k => place(k, depth + 1));
      x = (xs[0] + xs[xs.length - 1]) / 2;          // centre over the children span
    }
    pos[id] = { x, y: depth * (NH + VGAP), depth };
    return x;
  };
  topNodes().forEach(r => { place(r, 0); cursor += NW; });   // gap between separate roots
  return pos;
}

/* ---------- render ---------- */
function draw(){
  const svg = document.getElementById('chart');
  const pos = layout();
  const ids = Object.keys(pos);                      // raw keys: numbers AND 'ORG' / 'D:<dept>'
  if (!ids.length) { svg.innerHTML = ''; return; }

  let edges = '', nodes = '';
  ids.forEach(rawId => {
    const id = isNaN(+rawId) ? rawId : +rawId;
    const p = pos[rawId];
    childrenOf(id).forEach(k => {
      const c = pos[k];
      const x1 = p.x + NW/2, y1 = p.y + NH, x2 = c.x + NW/2, y2 = c.y, mid = y1 + VGAP/2;
      edges += `<path d="M${x1},${y1} V${mid} H${x2} V${y2}" fill="none" stroke="#cbd5e1" stroke-width="1.6" stroke-linejoin="round"/>`;
    });
  });

  ids.forEach(rawId => {
    const id = isNaN(+rawId) ? rawId : +rawId;
    const p = pos[rawId];
    const kidsAll = allChildren(id).length, isCol = COLLAPSED.has(id);
    const q = s => `toggle(${typeof id === 'string' ? `'${id}'` : id}${s})`;
    const sel = typeof id === 'string' ? `select('${id}')` : `select(${id})`;

    if (id === ORG || (typeof id === 'string' && id.startsWith('D:'))) {
      // synthetic tier: the organisation and its departments
      const isOrg = id === ORG;
      const label = isOrg ? ORG_NAME : id.slice(2);
      const col = isOrg ? '#334155' : dcol(label);
      const head = subtreeCount(id);
      nodes += `<g class="nodeBox" transform="translate(${p.x},${p.y})" onclick="${sel}">
        <rect class="card-bg" width="${NW}" height="${NH}" rx="10" fill="${col}" stroke="${col}" stroke-width="1"/>
        <text x="${NW/2}" y="${isOrg ? 26 : 24}" text-anchor="middle" font-size="${isOrg ? 13 : 12}" font-weight="700" fill="#fff">${xesc(clip(label, 22))}</text>
        <text x="${NW/2}" y="${isOrg ? 41 : 39}" text-anchor="middle" font-size="9" fill="#ffffffcc">${isOrg ? 'organisation' : 'department'} · ${head} people</text>
        ${kidsAll ? `<g onclick="${q(', event')}" style="cursor:pointer">
            <circle cx="${NW/2}" cy="${NH}" r="9" fill="#fff" stroke="${col}" stroke-width="1.4"/>
            <text x="${NW/2}" y="${NH + 3.5}" text-anchor="middle" font-size="10" font-weight="700" fill="${col}">${isCol ? kidsAll : '−'}</text></g>` : ''}
        <title>${xesc(label + ' — ' + head + ' people')}</title></g>`;
      return;
    }

    const n = BY[id];
    if (!n) return;
    const col = dcol(n.department);
    const dim = DIMMED && !HITS.has(id);
    const isMe = id === ME, isSel = id === SEL;
    const under = subtreeCount(id);
    nodes += `<g class="nodeBox" transform="translate(${p.x},${p.y})" opacity="${dim ? .3 : 1}" onclick="${sel}">
      <rect class="card-bg" width="${NW}" height="${NH}" rx="10" fill="#fff"
            stroke="${isSel ? '#4f46e5' : isMe ? '#2563eb' : '#e2e8f0'}" stroke-width="${isSel || isMe ? 2 : 1}"/>
      <rect width="4" height="${NH}" rx="2" fill="${col}"/>
      <circle cx="26" cy="${NH/2}" r="13" fill="${col}"/>
      <text x="26" y="${NH/2 + 3.5}" text-anchor="middle" font-size="10" font-weight="700" fill="#fff">${xesc(initials(n.full_name))}</text>
      <text x="46" y="23" font-size="11.5" font-weight="700" fill="#1f2937">${xesc(clip(n.full_name, 20))}</text>
      <text x="46" y="37" font-size="9.5" fill="#6b7280">${xesc(clip(n.designation || n.role_label || '—', 24))}</text>
      <text x="46" y="48" font-size="8.5" fill="#94a3b8">${xesc(clip((n.department || 'Unassigned') + (n.sub_department ? ' › ' + n.sub_department : ''), 28))}</text>
      ${isMe ? `<rect x="${NW-34}" y="6" width="28" height="12" rx="6" fill="#2563eb"/>
                <text x="${NW-20}" y="15" text-anchor="middle" font-size="7.5" font-weight="700" fill="#fff">YOU</text>` : ''}
      ${kidsAll ? `<g onclick="${q(', event')}" style="cursor:pointer">
          <circle cx="${NW/2}" cy="${NH}" r="9" fill="#fff" stroke="${isCol ? col : '#cbd5e1'}" stroke-width="1.4"/>
          <text x="${NW/2}" y="${NH + 3.5}" text-anchor="middle" font-size="10" font-weight="700"
                fill="${isCol ? col : '#64748b'}">${isCol ? kidsAll : '−'}</text></g>` : ''}
      <title>${xesc(n.full_name + ' — ' + (n.designation || '') + (under ? ` · ${under} in their line` : ''))}</title>
    </g>`;
  });

  const xs = ids.map(i => pos[i].x), ys = ids.map(i => pos[i].y);
  const bb = { x0: Math.min(...xs) - 40, y0: Math.min(...ys) - 30,
               x1: Math.max(...xs) + NW + 40, y1: Math.max(...ys) + NH + 40 };
  svg.dataset.bb = JSON.stringify(bb);
  svg.innerHTML = `<g id="viewport">${edges}${nodes}</g>`;
  applyView();
  window.__pos = pos;
}

function applyView(){
  const vp = document.getElementById('viewport');
  if (vp) vp.setAttribute('transform', `translate(${view.x},${view.y}) scale(${view.k})`);
}
function fit(){
  const svg = document.getElementById('chart'), bb = JSON.parse(svg.dataset.bb || 'null');
  if (!bb) return;
  const r = svg.getBoundingClientRect();
  const k = Math.max(0.5, Math.min(r.width / (bb.x1 - bb.x0), r.height / (bb.y1 - bb.y0), 1.1));
  view.k = k;
  view.x = (r.width - (bb.x1 - bb.x0) * k) / 2 - bb.x0 * k;
  view.y = 20 - bb.y0 * k;
  applyView();
}
function zoom(f){
  const svg = document.getElementById('chart'), r = svg.getBoundingClientRect();
  const cx = r.width / 2, cy = r.height / 2;
  view.x = cx - (cx - view.x) * f; view.y = cy - (cy - view.y) * f;
  view.k = Math.max(0.2, Math.min(2.5, view.k * f));
  applyView();
}
function centreOn(id){
  const p = (window.__pos || {})[id];
  if (!p) return;
  const r = document.getElementById('chart').getBoundingClientRect();
  view.x = r.width / 2 - (p.x + NW/2) * view.k;
  view.y = r.height / 2 - (p.y + NH/2) * view.k;
  applyView();
}

/* pan + wheel-zoom */
(function(){
  const wrap = document.getElementById('chartWrap');
  let dragging = false, sx = 0, sy = 0;
  wrap.addEventListener('mousedown', e => { dragging = true; sx = e.clientX - view.x; sy = e.clientY - view.y; wrap.classList.add('drag'); });
  window.addEventListener('mouseup', () => { dragging = false; wrap.classList.remove('drag'); });
  window.addEventListener('mousemove', e => { if (!dragging) return; view.x = e.clientX - sx; view.y = e.clientY - sy; applyView(); });
  wrap.addEventListener('wheel', e => { e.preventDefault(); zoom(e.deltaY < 0 ? 1.12 : 1/1.12); }, { passive: false });
})();

/* ---------- search / filter / focus ---------- */
function setRoot(v){ ROOT_ID = v ? +v : null; collapseToDepth(3); draw(); fit(); }
function applyFilter(){
  const q = (document.getElementById('q').value || '').toLowerCase().trim();
  const dept = document.getElementById('deptFilter').value;
  if (!q && !dept) { HITS = new Set(); DIMMED = false; draw(); return; }
  const match = p => (!dept || p.department === dept) &&
    (!q || [p.full_name, p.designation, p.department, p.sub_department, p.role_label]
      .some(v => (v || '').toLowerCase().includes(q)));
  const hits = PEOPLE.filter(match);
  HITS = new Set(hits.map(p => p.id));
  hits.forEach(p => {                       // reveal every match by opening its ancestors
    let c = p;
    while (c && c.manager_id) { COLLAPSED.delete(c.manager_id); HITS.add(c.manager_id); c = BY[c.manager_id]; }
    openPathTo(p.id);
  });
  DIMMED = true;
  draw();
  if (hits.length) { fit(); if (hits.length === 1) centreOn(hits[0].id); }
}
function openPathTo(id){
  COLLAPSED.delete(ORG);
  let c = BY[id];
  while (c && c.manager_id) { COLLAPSED.delete(c.manager_id); c = BY[c.manager_id]; }
  if (c) COLLAPSED.delete('D:' + (c.department || 'Unassigned'));   // the department tier above the root
}
function jumpToMe(){
  if (!ME) return;
  ROOT_ID = null; openPathTo(ME);
  draw();
  view.k = Math.max(view.k, 0.85);      // land on your own box at a readable size
  select(ME); centreOn(ME);
}

/* ---------- detail panel ---------- */
function chainOf(id){
  const out = []; let c = BY[id];
  while (c && c.manager_id && BY[c.manager_id]) { out.push(BY[c.manager_id]); c = BY[c.manager_id]; }
  return out.reverse();
}
function select(id){
  // synthetic tiers get a summary panel instead of a person card
  if (typeof id === 'string') {
    const isOrg = id === ORG, label = isOrg ? ORG_NAME : id.slice(2);
    const kids = allChildren(id);
    SEL = id; draw();
    document.getElementById('detail').innerHTML = `
      <div class="text-[10px] uppercase tracking-wide text-gray-400">${isOrg ? 'Organisation' : 'Department'}</div>
      <div class="font-semibold text-sm mt-1">${esc(label)}</div>
      <div class="text-[11px] text-gray-500">${subtreeCount(id)} people${isOrg ? ' · ' + DEPTS.length + ' departments' : ''}</div>
      <div class="mt-2">
        <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">${isOrg ? 'Departments' : 'Heads of line'} (${kids.length})</div>
        <div class="text-[11px] space-y-0.5 max-h-72 overflow-y-auto">
          ${kids.map(k => typeof k === 'string'
            ? `<div class="cursor-pointer hover:text-indigo-600" onclick="select('${k}');centreOn('${k}')">${esc(k.slice(2))}
                 <span class="text-gray-400">· ${subtreeCount(k)} people</span></div>`
            : `<div class="cursor-pointer hover:text-indigo-600" onclick="select(${k});centreOn(${k})">${esc(BY[k].full_name)}
                 <span class="text-gray-400">· ${esc(BY[k].designation || '—')}</span></div>`).join('')}
        </div>
      </div>`;
    return;
  }
  const p = BY[id]; if (!p) return;
  SEL = id; draw();
  const kids = (KIDS[id] || []).map(k => BY[k]).filter(Boolean);
  const chain = chainOf(id);
  const col = dcol(p.department);
  document.getElementById('detail').innerHTML = `
    <div class="text-[10px] uppercase tracking-wide text-gray-400">Selected person</div>
    <div class="flex items-center gap-2 mt-2">
      <span style="width:34px;height:34px;border-radius:50%;background:${col};color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">${esc(initials(p.full_name))}</span>
      <div><div class="font-semibold text-sm">${esc(p.full_name)}</div>
        <div class="text-[11px] text-gray-500">${esc(p.designation || p.role_label || '—')}</div></div>
    </div>
    <div class="mt-2 space-y-1 text-[11px]">
      <div><span class="text-gray-400">Department</span><br>${esc(p.department || '—')}${p.sub_department ? ' › ' + esc(p.sub_department) : ''}</div>
      <div><span class="text-gray-400">Email</span><br>${esc(p.email || '—')}</div>
      <div>${badge(p.emp_type || 'employee','gray')} ${p.governance && p.governance !== 'execution' ? badge(p.governance,'purple') : ''}</div>
    </div>
    <div class="mt-2">
      <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Reporting line</div>
      <div class="text-[11px]">${chain.length
        ? chain.map(c => `<span class="text-indigo-600 cursor-pointer" onclick="select(${c.id});centreOn(${c.id})">${esc(c.full_name)}</span>`).join(' <span class="text-gray-300">›</span> ')
          + ' <span class="text-gray-300">›</span> <b>' + esc(p.full_name) + '</b>'
        : '<span class="text-gray-400">Top of the chart</span>'}</div>
    </div>
    <div class="mt-2">
      <div class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Direct reports (${kids.length})</div>
      <div class="text-[11px] space-y-0.5 max-h-44 overflow-y-auto">
        ${kids.map(k => `<div class="cursor-pointer hover:text-indigo-600" onclick="select(${k.id});centreOn(${k.id})">${esc(k.full_name)}
          <span class="text-gray-400">· ${esc(k.designation || '—')}</span></div>`).join('') || '<span class="text-gray-400">None</span>'}
      </div>
    </div>
    ${kids.length ? `<button onclick="setRoot(${id})" class="ctl w-full mt-2 hover:bg-gray-50"><i class="fa-solid fa-crosshairs"></i> Show this team only</button>` : ''}`;
}
window.addEventListener('resize', () => fit());
load();
</script>
</html>
