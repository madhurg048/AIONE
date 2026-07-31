<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Merchant Settlements');
sales_header($me, 'Merchant Settlements');
sales_nav('settlements');
?>
<main class="p-2 space-y-2">

  <div id="kpis" class="kstrip"></div>

  <div class="card p-3">
    <div class="flex items-center justify-between">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-user-check text-indigo-500"></i> Maker · Checker · Approver
        <span class="normal-case font-normal text-[10px] text-gray-400">— one prepares, a second verifies, a third approves. The same person can never play two roles on one settlement.</span>
      </div>
      <div id="cfgView" class="flex items-center gap-1 text-[11px]"></div>
    </div>
  </div>

  <div class="card p-2 flex items-center gap-2 flex-wrap">
    <button onclick="newSettlement()" class="btn-p"><i class="fa-solid fa-plus"></i> New settlement</button>
    <span class="text-gray-300">|</span>
    <select id="fStatus" class="ctl" onchange="load()">
      <option value="">All status</option>
      <option value="draft">Draft</option><option value="pending_check">Pending check</option>
      <option value="checked">Checked (await approval)</option><option value="approved">Approved (to pay)</option>
      <option value="rejected">Rejected</option><option value="paid">Paid</option>
    </select>
    <div class="flex-1"></div>
    <span class="text-[10px] text-gray-400">Amounts: Net = Gross − MDR − Tax + Adjustments</span>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense">
      <thead><tr>
        <th>Ref</th><th>Merchant</th><th>Period</th>
        <th class="text-right">Gross</th><th class="text-right">Net</th>
        <th>Status</th><th>Maker</th><th>Checker</th><th>Approver</th><th></th>
      </tr></thead>
      <tbody id="rows"></tbody>
    </table></div>
    <div id="pager" class="p-2"></div>
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
let page = 1, perPage = 25, ME = null;
const ST = {
  draft:         ['Draft','gray'],
  pending_check: ['Pending check','yellow'],
  checked:       ['Checked','blue'],
  approved:      ['Approved','teal'],
  rejected:      ['Rejected','red'],
  paid:          ['Paid','green'],
};
const TIER_NAME = {1:'Exec+',2:'Manager+',3:'Head+',4:'Admin only'};

function goPage(p, per){ if(per) perPage=+per; page=Math.max(1,p||1); load(); }

async function loadCfg(){
  const { data } = await apiGet('settlements.php', { config:1 });
  ME = data.me;
  document.getElementById('cfgView').innerHTML = (data.levels||[]).map(l=>
    `<span class="bg-indigo-50 text-indigo-700 rounded px-2 py-0.5">${esc(l.level_name)}: <b>${TIER_NAME[l.min_tier]||l.min_tier}</b></span>`
  ).join('<i class="fa-solid fa-arrow-right text-gray-300 mx-1"></i>')
  + (ME.can_configure ? ` <button onclick="editCfg()" class="text-indigo-600 hover:underline ml-2"><i class="fa-solid fa-gear"></i></button>` : '');
}

async function load(){
  const p = { list:1, page, per:perPage };
  const st = document.getElementById('fStatus').value; if (st) p.status = st;
  const res = await apiGet('settlements.php', p);
  const s = res.summary || {};
  document.getElementById('kpis').innerHTML = [
    ['Settlements', s.cnt||0],
    ['Awaiting check', s.to_check||0, (s.to_check||0)>0],
    ['Awaiting approval', s.to_approve||0, (s.to_approve||0)>0],
    ['Approved to pay', s.to_pay||0],
    ['Rejected', s.rejected||0],
    ['In-flight amount', inr(s.in_flight||0)],
    ['Paid out', inr(s.paid_amt||0)],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-amber-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  document.getElementById('rows').innerHTML = (res.data||[]).map(r=>{
    const [lbl,col] = ST[r.status]||[r.status,'gray'];
    const mine = ME && +r.maker_id === +ME.employee_id;
    const acts = [];
    if (mine && ['draft','rejected'].includes(r.status)) {
      acts.push(`<button onclick="editSettlement(${r.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Edit</button>`);
      acts.push(`<button onclick="act(${r.id},'submit')" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Submit</button>`);
    }
    if (ME && ME.can_check && r.status==='pending_check' && !mine) {
      acts.push(`<button onclick="act(${r.id},'check')" class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded">Verify ✓</button>`);
      acts.push(`<button onclick="act(${r.id},'reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Reject</button>`);
    }
    if (ME && ME.can_approve && r.status==='checked' && !mine && +r.checked_by !== +ME.employee_id) {
      acts.push(`<button onclick="act(${r.id},'approve')" class="text-[10px] bg-teal-100 text-teal-700 px-2 py-0.5 rounded">Approve</button>`);
      acts.push(`<button onclick="act(${r.id},'reject')" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded">Reject</button>`);
    }
    if (ME && ME.can_pay && r.status==='approved') {
      acts.push(`<button onclick="act(${r.id},'pay')" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded">Mark paid</button>`);
    }
    return `<tr>
      <td class="font-mono text-[11px]"><a href="javascript:viewSettlement(${r.id})" class="text-indigo-600 hover:underline">${esc(r.settlement_ref)}</a></td>
      <td class="font-medium">${esc(r.merchant_name)}<div class="text-[10px] text-gray-400">${esc(r.merchant_code||'')}</div></td>
      <td class="text-gray-500 text-[11px]">${esc(r.period_from||'—')} → ${esc(r.period_to||'—')}</td>
      <td class="text-right">${inr(r.gross_amount)}</td>
      <td class="text-right font-semibold">${inr(r.net_amount)}</td>
      <td>${badge(lbl, col)}${r.status==='rejected'&&r.reject_reason?`<div class="text-[10px] text-red-500 max-w-[140px] truncate" title="${esc(r.reject_reason)}">${esc(r.reject_reason)}</div>`:''}</td>
      <td class="text-[11px]">${esc(r.maker_name||'')}</td>
      <td class="text-[11px]">${esc(r.checker_name||'—')}</td>
      <td class="text-[11px]">${esc(r.approver_name||'—')}</td>
      <td class="rowact whitespace-nowrap">${acts.join(' ')}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="10" class="text-center text-gray-400 py-4">No settlements — click "New settlement".</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages: res.pages, total: res.total, per: perPage }, 'goPage');
}

function settleForm(r){
  r = r || {};
  return `
    <div class="grid grid-cols-2 gap-2 text-xs">
      <label class="block col-span-2">Merchant
        <select name="merchant_id" required class="ctl w-full mt-1" ${r.id?'disabled':''}><option value="">Loading…</option></select></label>
      <label class="block">Period from<input name="period_from" type="date" value="${r.period_from||''}" class="ctl w-full mt-1"></label>
      <label class="block">Period to<input name="period_to" type="date" value="${r.period_to||''}" class="ctl w-full mt-1"></label>
      <label class="block">Gross amount (₹)<input name="gross_amount" type="number" step="0.01" min="0.01" required value="${r.gross_amount||''}" class="ctl w-full mt-1" oninput="calcNet(this.form)"></label>
      <label class="block">MDR (₹)<input name="mdr_amount" type="number" step="0.01" min="0" value="${r.mdr_amount||0}" class="ctl w-full mt-1" oninput="calcNet(this.form)"></label>
      <label class="block">Tax (₹)<input name="tax_amount" type="number" step="0.01" min="0" value="${r.tax_amount||0}" class="ctl w-full mt-1" oninput="calcNet(this.form)"></label>
      <label class="block">Adjustments (±₹)<input name="adjustments" type="number" step="0.01" value="${r.adjustments||0}" class="ctl w-full mt-1" oninput="calcNet(this.form)"></label>
      <label class="block col-span-2">Bank account<input name="bank_account" value="${esc(r.bank_account||'')}" class="ctl w-full mt-1" placeholder="a/c to credit"></label>
      <label class="block col-span-2">Remarks<input name="remarks" value="${esc(r.remarks||'')}" class="ctl w-full mt-1"></label>
      <div class="col-span-2 bg-gray-50 rounded p-2 flex justify-between items-center">
        <span class="text-gray-500">Net payable</span><b id="netPreview" class="text-sm">—</b>
      </div>
    </div>`;
}
function calcNet(f){
  const v = n => parseFloat(f[n].value)||0;
  document.getElementById('netPreview').textContent = inr(v('gross_amount')-v('mdr_amount')-v('tax_amount')+v('adjustments'));
}
async function fillMerchants(sel, cur){
  const { data } = await apiGet('settlements.php', { merchants:1 });
  sel.innerHTML = '<option value="">Select merchant…</option>' + (data||[]).map(m=>
    `<option value="${m.id}" ${cur==m.id?'selected':''}>${esc(m.legal_name)} · ${esc(m.merchant_code)}</option>`).join('');
}

async function newSettlement(){
  openModal(`<form onsubmit="saveSettlement(event, null)">${settleForm()}
    <div class="flex justify-end gap-2 pt-3 text-xs">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button name="asDraft" value="1" class="px-3 py-1.5 border rounded">Save draft</button>
      <button class="btn-p">Submit for check</button>
    </div></form>`, 'New settlement (you are the maker)');
  fillMerchants(document.querySelector('#modalBody select[name=merchant_id]'));
  calcNet(document.querySelector('#modalBody form'));
}
async function editSettlement(id){
  const { data } = await apiGet('settlements.php', { id });
  openModal(`<form onsubmit="saveSettlement(event, ${id})">${settleForm(data)}
    <div class="flex justify-end gap-2 pt-3 text-xs">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="btn-p">Save changes</button>
    </div></form>`, 'Edit ' + data.settlement_ref);
  fillMerchants(document.querySelector('#modalBody select[name=merchant_id]'), data.merchant_id);
  calcNet(document.querySelector('#modalBody form'));
}
async function saveSettlement(e, id){
  e.preventDefault(); const f = e.target;
  const body = { merchant_id:+f.merchant_id.value, period_from:f.period_from.value, period_to:f.period_to.value,
    gross_amount:+f.gross_amount.value, mdr_amount:+f.mdr_amount.value, tax_amount:+f.tax_amount.value,
    adjustments:+f.adjustments.value, bank_account:f.bank_account.value, remarks:f.remarks.value };
  let res;
  if (id) res = await apiSend('settlements.php?id='+id, body, 'PATCH');
  else { body.submit = e.submitter && e.submitter.name==='asDraft' ? 0 : 1; res = await apiSend('settlements.php', body); }
  if (res.success){ closeModal(); toast(id?'Saved':'Settlement '+(body.submit?'submitted for check':'saved as draft')); load(); }
  else toast(res.error||'Failed', false);
}

async function viewSettlement(id){
  const { data:r } = await apiGet('settlements.php', { id });
  const [lbl,col] = ST[r.status]||[r.status,'gray'];
  const row = (k,v)=>`<div class="flex justify-between border-b border-gray-100 py-1"><span class="text-gray-500">${k}</span><span class="font-medium text-right">${v}</span></div>`;
  openModal(`
    <div class="text-xs space-y-0.5">
      ${row('Merchant', esc(r.merchant_name)+' · '+esc(r.merchant_code||''))}
      ${row('Status', badge(lbl,col))}
      ${row('Period', esc(r.period_from||'—')+' → '+esc(r.period_to||'—'))}
      ${row('Gross', inr(r.gross_amount))} ${row('MDR', '− '+inr(r.mdr_amount))} ${row('Tax', '− '+inr(r.tax_amount))}
      ${row('Adjustments', inr(r.adjustments))} ${row('<b>Net payable</b>', '<b>'+inr(r.net_amount)+'</b>')}
      ${r.bank_account?row('Bank a/c', esc(r.bank_account)):''}
      ${r.utr?row('UTR', esc(r.utr)):''}
      ${r.reject_reason?row('Reject reason', '<span class="text-red-600">'+esc(r.reject_reason)+'</span>'):''}
    </div>
    <div class="text-[10px] uppercase text-gray-400 mt-3 mb-1">Audit trail (who did what)</div>
    <div class="space-y-1 text-[11px] max-h-48 overflow-y-auto">
      ${(r.audit||[]).map(a=>`<div class="flex gap-2">
        <span class="text-gray-400 whitespace-nowrap">${esc((a.created_at||'').slice(0,16))}</span>
        <span>${badge(a.action, a.action==='rejected'?'red':a.action==='approved'?'teal':a.action==='paid'?'green':a.action==='checked'?'blue':'gray')}
        <b>${esc(a.actor_name||'system')}</b> <span class="text-gray-400">(${esc(a.actor_role||'')})</span>
        ${a.remarks?'— '+esc(a.remarks):''}</span></div>`).join('') || '<span class="text-gray-400">—</span>'}
    </div>`, r.settlement_ref);
}

async function act(id, action){
  let extra = {};
  if (action==='reject'){ const r = prompt('Reason for rejection (required):',''); if(r===null) return; extra.remarks = r; }
  if (action==='pay'){ const u = prompt('UTR / payment reference (required):',''); if(u===null) return; extra.utr = u; }
  if (action==='check' && !confirm('Verify this settlement? You confirm the figures match the merchant\'s transactions.')) return;
  if (action==='approve' && !confirm('Approve this settlement for payout?')) return;
  const res = await apiSend('settlements.php?action='+action+'&id='+id, extra);
  if (res.success){ toast('Settlement '+res.data.status.replace('_',' ')); load(); }
  else toast(res.error||'Failed', false);
}

async function editCfg(){
  const { data } = await apiGet('settlements.php', { config:1 });
  openModal(`<form onsubmit="saveCfg(event)" class="space-y-2 text-xs">
    ${(data.levels||[]).map(l=>`
      <label class="flex items-center justify-between gap-2">L${l.level} — ${esc(l.level_name)}
        <select name="tier_${l.level}" class="ctl">
          ${[1,2,3,4].map(t=>`<option value="${t}" ${+l.min_tier===t?'selected':''}>${TIER_NAME[t]}</option>`).join('')}
        </select></label>`).join('')}
    <p class="text-[10px] text-gray-400">Minimum role tier allowed to act at each level. Separation of duties always applies on top.</p>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Save</button></div></form>`, 'Approval levels');
}
async function saveCfg(e){
  e.preventDefault(); const f = e.target;
  const levels = [1,2,3].map(l=>({ level:l, min_tier:+f['tier_'+l].value }));
  const res = await apiSend('settlements.php?config=1', { levels });
  if (res.success){ closeModal(); toast('Approval levels updated'); loadCfg(); } else toast(res.error||'Failed', false);
}

loadCfg().then(load);
</script>
</html>
