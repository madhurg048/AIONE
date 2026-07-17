<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$canManage = in_array(sales_role(), ['admin','manager'], true);
sales_head('Scorecards');
sales_header($me, $canManage ? 'Sales · Team Scorecards' : 'Sales · My Scorecard');
sales_nav('scorecards');
?>
<main class="p-2 space-y-2">

  <!-- toolbar -->
  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <span class="text-[11px] font-semibold text-gray-600 uppercase mr-1"><i class="fa-solid fa-bullseye text-indigo-500"></i> Sales Scorecards</span>
    <span class="text-[10px] text-gray-400">Quarterly GMV/incentive-eligibility grading — for goal definitions see <a href="sales-kpa.php" class="text-indigo-500 hover:underline">KPA / KRA</a></span>
    <label class="text-xs text-gray-500">Quarter
      <select id="period" class="ctl ml-1" onchange="load()"></select></label>
    <div class="flex-1"></div>
    <?php if ($canManage): ?>
    <button onclick="openBuilder()" class="btn-p"><i class="fa-solid fa-plus"></i> New Scorecard</button>
    <?php endif; ?>
  </div>

  <!-- roll-up strip -->
  <div id="kpis" class="kstrip"></div>

  <?php if ($canManage): ?>
  <!-- MANAGER: team scorecards table -->
  <div class="card">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide flex items-center justify-between">
      <span>Team Scorecards</span>
      <span class="normal-case font-normal text-[10px] text-gray-400">Assessed quarterly on average monthly performance · GMV is the primary eligibility gate</span>
    </div>
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Employee</th><th>Level</th><th>GMV attain</th><th>Weighted</th><th>Rating</th>
        <th>Gate</th><th>Fee slab</th><th>Payout</th><th>Status</th><th></th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
  </div>
  <?php else: ?>
  <!-- SALES EXEC: my scorecard -->
  <div id="myCard"></div>
  <?php endif; ?>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
let CATALOG = null;

/* ---- ₹ Cr/L aware formatting ---- */
function fmtBig(v){ v=+v||0; if(Math.abs(v)>=1e7) return '₹'+(v/1e7).toFixed(2)+' Cr'; if(Math.abs(v)>=1e5) return '₹'+(v/1e5).toFixed(2)+' L'; return inr(v); }
function fmtUnit(v,u){ if(u==='inr') return fmtBig(v); if(u==='pct') return (+v||0)+'%'; return (+v||0).toLocaleString('en-IN'); }
const RATING_COLOR = { Outstanding:'green', Exceeds:'teal', Meets:'blue', Partial:'yellow', Below:'red' };

/* ---- quarter dropdown (this quarter + previous 3) ---- */
function quarterList(){
  const d = new Date(), y = d.getFullYear(), q = Math.floor(d.getMonth()/3)+1;
  const out = []; let yy=y, qq=q;
  for (let i=0;i<4;i++){ out.push(`${yy}-Q${qq}`); qq--; if(qq<1){qq=4;yy--;} }
  return out;
}
function initPeriods(){
  document.getElementById('period').innerHTML = quarterList().map(p=>`<option>${p}</option>`).join('');
}

async function ensureCatalog(){ if(!CATALOG){ const r = await apiGet('scorecards.php',{catalog:1}); CATALOG=r; } return CATALOG; }

/* ================= LOAD ================= */
async function load(){
  const period = document.getElementById('period').value;
  const { data } = await apiGet('scorecards.php');
  const cards = (data.cards||[]).filter(c=>c.period===period);

  // roll-up strip
  const n = cards.length;
  const avg = n ? (cards.reduce((a,c)=>a+ +c.score.weighted_pct,0)/n).toFixed(0) : 0;
  const gate = cards.filter(c=>c.score.gate_pass).length;
  const ready = cards.filter(c=>c.score.payout_ready).length;
  const acc = cards.filter(c=>c.score.accelerator).length;
  document.getElementById('kpis').innerHTML = [
    ['Scorecards', n], ['Avg weighted', avg+'%'],
    ['GMV gate passed', gate+' / '+n, gate<n], ['Accelerator', acc],
    ['Payout ready', ready+' / '+n, ready<n],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  if (CAN_MANAGE) renderRows(cards, period);
  else renderMine(cards, period);
}

/* ================= MANAGER: rows ================= */
function renderRows(cards, period){
  const tb = document.getElementById('rows');
  if (!cards.length){ tb.innerHTML = `<tr><td colspan="10" class="text-center text-gray-400 py-3">No scorecards for ${period} — click “New Scorecard”.</td></tr>`; return; }
  tb.innerHTML = cards.map(c=>{
    const s=c.score;
    return `<tr>
      <td class="font-medium">${esc(c.employee_name)}</td>
      <td class="text-gray-500">${esc(c.role_level)}</td>
      <td>${attainCell(s.gmv_attainment)}</td>
      <td><span class="font-semibold">${s.weighted_pct}%</span></td>
      <td>${badge(s.rating, RATING_COLOR[s.rating]||'gray')}</td>
      <td>${s.gate_pass?badge('pass','green'):badge('fail','red')}${s.accelerator?' '+badge('+accel','teal'):''}</td>
      <td class="text-gray-600">${s.fee_slab}</td>
      <td>${s.payout_ready?badge('ready','green'):badge('hold','yellow')}</td>
      <td>${badge(c.status, c.status==='published'?'blue':c.status==='acknowledged'?'green':c.status==='locked'?'gray':'yellow')}</td>
      <td class="rowact whitespace-nowrap">
        <button onclick="openCard(${c.id})" title="Open" class="text-indigo-600 hover:text-indigo-800"><i class="fa-solid fa-up-right-from-square"></i></button>
        <button onclick="syncCard(${c.id})" title="Sync live actuals" class="text-teal-600 hover:text-teal-800"><i class="fa-solid fa-rotate"></i></button>
        <button onclick="delCard(${c.id})" title="Delete" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-trash"></i></button>
      </td></tr>`;
  }).join('');
}
function attainCell(a){
  const col = a>=110?'bg-teal-500':a>=100?'bg-green-500':a>=70?'bg-yellow-500':'bg-red-500';
  return `<div class="flex items-center gap-1"><div class="w-16 h-2 bg-gray-100 rounded"><div class="${col} h-2 rounded" style="width:${Math.min(100,Math.max(3,a))}%"></div></div><span class="text-[11px]">${a}%</span></div>`;
}

/* ================= MANAGER: builder ================= */
async function openBuilder(){
  await ensureCatalog();
  const team = (await apiGet('scorecards.php',{team:1})).data.team || [];
  const levels = CATALOG.data.levels;
  const period = document.getElementById('period').value;
  openModal(`<form onsubmit="createCard(event)" class="space-y-3 text-xs">
    <div class="grid grid-cols-2 gap-3">
      <label>Team member
        <select name="employee_id" required class="ctl w-full mt-1">
          <option value="">Select…</option>
          ${team.map(t=>`<option value="${t.id}">${esc(t.full_name)} — ${esc(t.designation||t.role)}</option>`).join('')}
        </select></label>
      <label>Quarter
        <select name="period" class="ctl w-full mt-1">${quarterList().map(p=>`<option ${p===period?'selected':''}>${p}</option>`).join('')}</select></label>
      <label>Role level
        <select name="role_level" class="ctl w-full mt-1" onchange="showPreset(this.value)">
          ${Object.keys(levels).map(l=>`<option ${l==='Senior Sales Executive'?'selected':''}>${l}</option>`).join('')}
        </select></label>
      <div class="self-end text-[11px] text-gray-500">Assigned GMV target: <b id="presetGmv" class="text-indigo-700"></b><div class="text-[10px] text-gray-400">avg monthly · auto-fills the GMV KPI</div></div>
    </div>
    <label>Notes (optional)<input name="notes" class="ctl w-full mt-1" placeholder="Focus areas for the quarter…"></label>
    <div class="bg-indigo-50 rounded p-2 text-[11px] text-gray-600">
      Seeds the 5 framework KPIs at default weights (GMV 50% · Fee 20% · Segment Mix 15% · Active Merchants 10% · Governance 5%).
      You can tune every target &amp; weight after creating, then <b>Sync</b> to pull live actuals.
    </div>
    <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Create scorecard</button></div>
  </form>`, 'New Scorecard');
  showPreset('Senior Sales Executive');
}
function showPreset(level){ const v = CATALOG.data.levels[level]||0; document.getElementById('presetGmv').textContent = fmtBig(v); }

async function createCard(e){
  e.preventDefault();
  const f = e.target;
  const body = { employee_id:+f.employee_id.value, period:f.period.value, role_level:f.role_level.value, notes:f.notes.value };
  if(!body.employee_id) return toast('Pick a team member', false);
  const r = await apiSend('scorecards.php', body);
  if(!r.success) return toast(r.error||'Failed', false);
  closeModal(); toast('Scorecard created'); document.getElementById('period').value = body.period; load();
  openCard(r.data.card.id);
}

/* ================= card detail (shared) ================= */
async function openCard(id){
  const { data } = await apiGet('scorecards.php',{id});
  const c = data.card, s = c.score;
  openModal(cardHtml(c,s), `${esc(c.employee_name)} · ${c.period} · ${esc(c.role_level)}`);
}
function cardHtml(c,s){
  const editable = CAN_MANAGE && c.status!=='locked';
  const lines = c.lines.map(l=>{
    const a = +l.target_value>0 ? (+l.actual_value/+l.target_value*100) : 0;
    const bar = attainCell(Math.round(a*10)/10);
    const val = (field,step)=> editable
      ? `<input type="number" step="${step}" value="${+l[field]}" onchange="updLine(${c.id},'${l.kpi_code}','${field}',this.value)" class="border rounded px-1 py-0.5 w-28 text-right">`
      : `<span>${l.unit==='inr'?fmtBig(l[field]):(+l[field]).toLocaleString('en-IN')}${l.unit==='pct'?'%':''}</span>`;
    return `<tr class="align-top">
      <td class="font-medium">${esc(l.name)}${+l.is_gate?' '+badge('GATE','red'):''}<div class="text-[10px] text-gray-400 normal-case">${esc(l.measurement_basis||'')}</div></td>
      <td>${editable?`<input type="number" step="1" value="${+l.weight}" onchange="updLine(${c.id},'${l.kpi_code}','weight',this.value)" class="border rounded px-1 py-0.5 w-14 text-right">`:(+l.weight)+'%'}</td>
      <td class="text-right">${val('target_value','0.01')}</td>
      <td class="text-right">${val('actual_value','0.01')} ${l.actual_source==='auto'?'<span title="auto from transactions">🔄</span>':''}</td>
      <td>${bar}</td>
    </tr>`;
  }).join('');
  const statusBtns = CAN_MANAGE ? `
    ${c.status==='draft'?`<button onclick="setStatus(${c.id},'published')" class="btn-p"><i class="fa-solid fa-paper-plane"></i> Publish to employee</button>`:''}
    ${c.status==='published'||c.status==='acknowledged'?`<button onclick="setStatus(${c.id},'locked')" class="ctl hover:bg-gray-50"><i class="fa-solid fa-lock"></i> Lock (finalize)</button>`:''}
    <button onclick="syncCard(${c.id})" class="ctl hover:bg-gray-50"><i class="fa-solid fa-rotate text-teal-500"></i> Sync live actuals</button>` : '';
  return `<div class="space-y-3 text-xs">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
      ${scoreTile('Weighted score', s.weighted_pct+'%', RATING_COLOR[s.rating])}
      ${scoreTile('Rating', s.rating, RATING_COLOR[s.rating])}
      ${scoreTile('GMV gate', s.gate_pass?'PASS':'FAIL', s.gate_pass?'green':'red', s.gmv_attainment+'% of target')}
      ${scoreTile('Payout', s.payout_ready?'READY':'ON HOLD', s.payout_ready?'green':'yellow', s.governance_ok?'validated':'needs validation')}
    </div>
    <div class="flex flex-wrap gap-2 text-[11px]">
      ${s.accelerator?badge('Accelerator (GMV &gt;110%)','teal'):''}
      ${badge('Fee incentive: '+(s.fee_incentive_eligible?('eligible '+s.fee_slab):'below ₹1L'), s.fee_incentive_eligible?'green':'gray')}
      ${badge('GMV incentive: '+(s.gmv_incentive_eligible?'eligible':'not payable'), s.gmv_incentive_eligible?'green':'red')}
    </div>
    <table class="dense"><thead><tr><th>KPI</th><th>Weight</th><th class="text-right">Target</th><th class="text-right">Actual</th><th>Attainment</th></tr></thead>
      <tbody>${lines}</tbody></table>
    <p class="text-[10px] text-gray-400">Eligibility is assessed quarterly on average monthly performance. GMV is the primary gate — below 100% no GMV incentive is payable. Fee-income incentives apply separately once the ₹1L threshold is met and merchant activity is validated. 100% governance validation &amp; management approval are required before payout release.</p>
    ${c.notes?`<div class="bg-gray-50 rounded p-2"><b>Notes:</b> ${esc(c.notes)}</div>`:''}
    <div class="flex flex-wrap items-center gap-2 border-t pt-2">${statusBtns}</div>
  </div>`;
}
function scoreTile(label,val,color,sub){
  const c = color||'gray';
  return `<div class="rounded border p-2 bg-${c}-50 border-${c}-100">
    <div class="text-[10px] uppercase tracking-wide text-gray-500">${label}</div>
    <div class="text-lg font-bold text-${c}-700 leading-tight">${val}</div>
    ${sub?`<div class="text-[10px] text-gray-400">${sub}</div>`:''}</div>`;
}

async function updLine(id, kpi, field, value){
  const r = await apiSend('scorecards.php?id='+id, { kpi_code:kpi, [field]:parseFloat(value)||0 }, 'PATCH');
  if(!r.success) return toast(r.error||'Failed', false);
  openCard(id); // refresh detail (recomputes score)
}
async function setStatus(id, status){
  const r = await apiSend('scorecards.php?id='+id, { status }, 'PATCH');
  if(!r.success) return toast(r.error||'Failed', false);
  toast('Scorecard '+status); openCard(id); load();
}
async function syncCard(id){
  toast('Pulling live GMV, fee income & active merchants…');
  const r = await apiSend('scorecards.php?sync=1&id='+id, {});
  if(!r.success) return toast(r.error||'Sync failed', false);
  toast(`Synced ${r.data.synced} KPIs from transactions`);
  const open = document.getElementById('modal').classList.contains('hidden');
  if(!open) openCard(id);
  load();
}
async function delCard(id){
  if(!confirm('Delete this scorecard?')) return;
  const r = await apiSend('scorecards.php?id='+id, {}, 'DELETE');
  if(r.success){ toast('Deleted'); load(); }
}

/* ================= SALES EXEC: my scorecard ================= */
function renderMine(cards, period){
  const el = document.getElementById('myCard');
  const c = cards[0];
  if(!c){ el.innerHTML = `<div class="card p-6 text-center text-gray-400">No scorecard published for ${period} yet. Your manager sets your KPAs/KRAs each quarter.</div>`; return; }
  const s = c.score;
  el.innerHTML = `
  <div class="card p-3 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div><span class="font-semibold text-sm">${esc(c.role_level)}</span>
        <span class="text-gray-400 text-xs"> · ${c.period}</span>
        ${badge(c.status, c.status==='published'?'blue':c.status==='acknowledged'?'green':c.status==='locked'?'gray':'yellow')}</div>
      ${c.status==='published'?`<button onclick="ackCard(${c.id})" class="btn-p"><i class="fa-solid fa-check"></i> Acknowledge</button>`:''}
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
      ${scoreTile('Weighted score', s.weighted_pct+'%', RATING_COLOR[s.rating])}
      ${scoreTile('Rating', s.rating, RATING_COLOR[s.rating])}
      ${scoreTile('GMV gate', s.gate_pass?'PASS':'FAIL', s.gate_pass?'green':'red', s.gmv_attainment+'% of target')}
      ${scoreTile('Payout', s.payout_ready?'READY':'ON HOLD', s.payout_ready?'green':'yellow', s.governance_ok?'validated':'pending validation')}
    </div>
    <div class="flex flex-wrap gap-2 text-[11px]">
      ${s.accelerator?badge('Accelerator (GMV &gt;110%)','teal'):''}
      ${badge('Fee incentive: '+(s.fee_incentive_eligible?('eligible '+s.fee_slab):'below ₹1L'), s.fee_incentive_eligible?'green':'gray')}
      ${badge('GMV incentive: '+(s.gmv_incentive_eligible?'eligible':'not payable'), s.gmv_incentive_eligible?'green':'red')}
    </div>
  </div>
  <div class="card mt-2">
    <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide">My KPIs — ${c.period}</div>
    <table class="dense"><thead><tr><th>KPI</th><th>Weight</th><th class="text-right">Target</th><th class="text-right">Actual</th><th>Attainment</th></tr></thead>
    <tbody>${c.lines.map(l=>{
      const a = +l.target_value>0 ? Math.round(+l.actual_value/+l.target_value*1000)/10 : 0;
      return `<tr class="align-top">
        <td class="font-medium">${esc(l.name)}${+l.is_gate?' '+badge('GATE','red'):''}<div class="text-[10px] text-gray-400 normal-case">${esc(l.measurement_basis||'')}</div></td>
        <td>${+l.weight}%</td>
        <td class="text-right">${fmtUnit(l.target_value,l.unit)}</td>
        <td class="text-right">${fmtUnit(l.actual_value,l.unit)}</td>
        <td>${attainCell(a)}</td></tr>`;
    }).join('')}</tbody></table>
  </div>
  <p class="text-[10px] text-gray-400 mt-1 px-1">Assessed quarterly on average monthly performance. GMV is the primary gate. Fee-income incentives apply separately once the ₹1L threshold is met. Governance validation is required before any payout is released.</p>`;
}
async function ackCard(id){
  const r = await apiSend('scorecards.php?id='+id, { status:'acknowledged' }, 'PATCH');
  if(r.success){ toast('Acknowledged'); load(); } else toast(r.error||'Failed', false);
}

initPeriods(); load();
</script>
</html>
