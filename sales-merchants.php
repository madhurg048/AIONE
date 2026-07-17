<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Merchants');
sales_header($me, 'Sales · Merchants (PIMS)');
sales_nav('merchants');
?>
<main class="p-2 space-y-2">
  <div id="kstrip" class="kstrip"></div>

  <div data-ai-scope="merchants"></div>

  <div class="card">
    <div class="flex items-center gap-2 p-2 border-b flex-wrap">
      <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mr-1">Merchant Onboarding</span>
      <input id="q" placeholder="Search MID / name…" class="ctl w-52" oninput="liveSearch()" onkeyup="if(event.key==='Enter')load()">
      <select id="fStage" onchange="load()" class="ctl">
        <option value="">All stages</option>
        <option>created</option><option>kyc_pending</option><option>kyc_submitted</option>
        <option>under_review</option><option>activated</option><option>suspended</option><option>rejected</option>
      </select>
    </div>
    <div class="overflow-x-auto" style="max-height:calc(100vh - 230px)">
      <table class="dense">
        <thead><tr>
          <th>MID</th><th>Merchant</th><th>Stage</th><th>KYC</th><th>Category</th>
          <th>Exp. Volume</th><th>Owner</th><th>Created</th><th style="width:80px">Actions</th>
        </tr></thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
    <div id="pager" class="p-1"></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const stageColor = { created:'gray', kyc_pending:'yellow', kyc_submitted:'blue', under_review:'blue',
  activated:'green', suspended:'red', rejected:'red' };
const kycColor = { pending:'gray', partial:'yellow', verified:'green', rejected:'red' };

let page = 1, perPage = 20, pages = 1;
async function loadMetrics() {
  const { data: m } = await apiGet('merchants.php', { metrics: 1 });
  const kpis = [['Total', m.total], ['Activated', m.activated], ['KYC Pending', m.kyc_pending], ['Live Volume', inr(m.volume)]];
  document.getElementById('kstrip').innerHTML = kpis.map(([l, v]) =>
    `<div><div class="kv">${v}</div><div class="kl">${l}</div></div>`).join('');
}

function goPage(p, per) { if (per) perPage = +per; page = Math.max(1, p || 1); load(); }
let _searchT; function liveSearch() { clearTimeout(_searchT); _searchT = setTimeout(() => load(), 300); }
async function load() {
  const params = { page, per: perPage };
  if (document.getElementById('q').value) params.q = document.getElementById('q').value;
  if (document.getElementById('fStage').value) params.stage = document.getElementById('fStage').value;
  const res = await apiGet('merchants.php', params);
  pages = res.pages || 1;
  document.getElementById('rows').innerHTML = res.data.map(m => `
    <tr>
      <td class="font-mono text-[11px]">${esc(m.merchant_code)}</td>
      <td class="font-medium">${esc(m.legal_name)}</td>
      <td>${badge(m.onboarding_stage, stageColor[m.onboarding_stage]||'gray')}</td>
      <td>${badge(m.kyc_status, kycColor[m.kyc_status]||'gray')}</td>
      <td class="text-gray-500">${esc(m.category||'—')}</td>
      <td>${inr(m.expected_monthly_volume)}</td>
      <td class="text-gray-600">${esc(m.owner_name||'—')}</td>
      <td class="text-gray-400">${esc(String(m.created_at||'').slice(0,10))}</td>
      <td class="rowact">
        <button onclick="view(${m.id})" class="text-indigo-600 hover:text-indigo-800" title="View"><i class="fa-solid fa-eye"></i></button>
        <button onclick="aiDocs(${m.id},'${esc(m.legal_name)}')" class="text-purple-600 hover:text-purple-800 ml-1" title="AI document Q&A"><i class="fa-solid fa-file-circle-question"></i></button>
        ${m.onboarding_stage!=='activated'
          ? `<button onclick="activate(${m.id})" class="text-green-600 hover:text-green-800 ml-1" title="Activate"><i class="fa-solid fa-rocket"></i></button>`
          : '<i class="fa-solid fa-circle-check text-green-500 ml-1" title="live"></i>'}
      </td>
    </tr>`).join('') || '<tr><td colspan="9" class="text-center text-gray-400 py-4">No merchants</td></tr>';
  document.getElementById('pager').innerHTML = pagerHtml({ page: res.page, pages, total: res.total, per: perPage }, 'goPage');
}

async function view(id) {
  const { data: m } = await apiGet('merchants.php', { id });
  const docs = (m.documents||[]);
  const inc = (m.incentives||[]);
  openModal(`
    <div class="space-y-3 text-xs">
      <div class="grid grid-cols-2 gap-x-4 gap-y-1">
        <div><span class="text-gray-400">MID</span><br><span class="font-mono">${esc(m.merchant_code)}</span></div>
        <div><span class="text-gray-400">Legal name</span><br>${esc(m.legal_name)}</div>
        <div><span class="text-gray-400">Stage</span><br>${badge(m.onboarding_stage, stageColor[m.onboarding_stage]||'gray')}</div>
        <div><span class="text-gray-400">KYC</span><br>${badge(m.kyc_status, kycColor[m.kyc_status]||'gray')}</div>
        <div><span class="text-gray-400">Exp. volume</span><br>${inr(m.expected_monthly_volume)}</div>
        <div><span class="text-gray-400">Owner</span><br>${esc(m.owner_name||'—')}</div>
        <div><span class="text-gray-400">PayG PIMS</span><br>${paygBadge(m)}</div>
      </div>
      <div>
        <div class="font-semibold text-gray-600 mb-1">KYC set</div>
        <div class="flex gap-2 items-center flex-wrap">
          ${['pan','gst','aadhaar','bank_proof'].map(t=>{
            const d = docs.find(x=>x.doc_type===t);
            return badge(t.toUpperCase()+(d?' ✓':' ·'), d && d.status==='verified'?'green':d?'yellow':'gray');
          }).join('')}
          <button onclick="setKyc(${m.id},'verified')" class="ml-2 text-[11px] text-indigo-600 hover:underline">Mark KYC verified</button>
        </div>
      </div>
      <div>
        <div class="font-semibold text-gray-600 mb-1">Business profile
          <span class="text-[10px] text-gray-400 font-normal">— drives fee-income &amp; high-yield segment-mix KPIs</span></div>
        <div class="grid grid-cols-2 gap-2">
          <label>Category
            <select id="mCat" class="ctl w-full mt-0.5">
              ${['','FI','NBFC','Insurance','Education','Enterprise','Retail','Food','Pharma','Apparel','Automotive','Other'].map(c=>`<option ${c===(m.category||'')?'selected':''}>${c}</option>`).join('')}
            </select></label>
          <label>Business type
            <select id="mBiz" class="ctl w-full mt-0.5">
              ${['proprietorship','partnership','pvt_ltd','llp','public_ltd','trust','other'].map(c=>`<option ${c===(m.business_type||'')?'selected':''}>${c}</option>`).join('')}
            </select></label>
          <label>MDR %<input id="mMdr" type="number" step="0.001" value="${m.mdr_percent||''}" class="ctl w-full mt-0.5" placeholder="e.g. 1.8"></label>
          <label>Expected volume/mo (₹)<input id="mVol" type="number" value="${m.expected_monthly_volume||''}" class="ctl w-full mt-0.5"></label>
        </div>
        <div class="text-[10px] text-gray-400 mt-1">High-yield segments: FI, NBFC, Insurance, Education, Enterprise.</div>
        <button onclick="saveProfile(${m.id})" class="btn-p mt-2"><i class="fa-solid fa-floppy-disk"></i> Save profile</button>
      </div>
      <div>
        <div class="font-semibold text-gray-600 mb-1">Incentives booked</div>
        ${inc.length ? inc.map(i=>`<div class="flex justify-between border-b py-0.5"><span>${esc(i.basis)}</span><span class="font-semibold">${inr(i.amount)} ${badge(i.status,i.status==='paid'?'green':'yellow')}</span></div>`).join('')
          : '<div class="text-gray-400">None yet — activate the merchant to release incentive.</div>'}
      </div>
      <div class="flex justify-end items-center gap-2 pt-2">
        ${m.payg_push_status!=='pushed' ? `
          <button id="paygBtn${m.id}" onclick="pushPayg(${m.id})" class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"><i class="fa-solid fa-cloud-arrow-up"></i> Push to PayG</button>
          <i id="paygSpin${m.id}" class="fa-solid fa-spinner fa-spin text-indigo-500 hidden"></i>
          <i id="paygTick${m.id}" class="fa-solid fa-circle-check text-green-600 hidden"></i>
        ` : ''}
        ${m.onboarding_stage!=='activated' ? `<button onclick="activate(${m.id})" class="bg-green-600 text-white px-3 py-1.5 rounded hover:bg-green-700"><i class="fa-solid fa-rocket"></i> Activate</button>`:''}
      </div>
    </div>`, m.merchant_code + ' — ' + m.legal_name);
}

function paygBadge(m) {
  if (m.payg_push_status === 'pushed') return badge('Pushed' + (m.payg_merchant_id ? ' · ' + esc(m.payg_merchant_id) : ''), 'green');
  if (m.payg_push_status === 'failed') return badge('Push failed', 'red');
  return badge('Not pushed', 'gray');
}

async function pushPayg(id) {
  const btn = document.getElementById('paygBtn'+id);
  const spin = document.getElementById('paygSpin'+id);
  const tick = document.getElementById('paygTick'+id);
  if (btn) btn.disabled = true;
  if (spin) spin.classList.remove('hidden');
  if (tick) tick.classList.add('hidden');
  try {
    const res = await apiSend('merchant-push-payg.php', { merchant_id: id });
    if (res.success) {
      toast(res.message || 'Pushed to PayG');
      if (spin) spin.classList.add('hidden');
      if (tick) tick.classList.remove('hidden');
      setTimeout(() => { view(id); load(); }, 700);
    } else {
      toast(res.error || 'PayG push failed', false);
      if (btn) btn.disabled = false;
      if (spin) spin.classList.add('hidden');
    }
  } catch (e) {
    toast('PayG push failed', false);
    if (btn) btn.disabled = false;
    if (spin) spin.classList.add('hidden');
  }
}

async function setKyc(id, status) {
  const res = await apiSend('merchants.php?id='+id, { kyc_status: status }, 'PATCH');
  if (res.success) { toast('KYC → '+status); view(id); load(); loadMetrics(); }
  else toast(res.error||'Failed', false);
}

async function saveProfile(id) {
  const body = {
    category: document.getElementById('mCat').value || null,
    business_type: document.getElementById('mBiz').value,
    mdr_percent: parseFloat(document.getElementById('mMdr').value) || 0,
    expected_monthly_volume: parseFloat(document.getElementById('mVol').value) || 0
  };
  const res = await apiSend('merchants.php?id='+id, body, 'PATCH');
  if (res.success) { toast('Business profile saved'); view(id); load(); }
  else toast(res.error || 'Failed', false);
}

async function activate(id) {
  const res = await apiSend('merchants.php?activate='+id, {});
  if (res.success) { toast(res.message || 'Activated'); closeModal(); load(); loadMetrics(); }
  else toast(res.error || 'Cannot activate (verify KYC first)', false);
}

/* ---- AI document Q&A (over the AI box) ---- */
let _docMid = null, _curDoc = null;
function aiDocs(mid, name){
  _docMid = mid; _curDoc = null;
  openModal(`
    <div class="space-y-2 text-xs">
      <div class="flex items-center gap-2">
        <input type="file" id="docFile" accept=".pdf,.docx,.txt,.csv" class="text-xs flex-1">
        <button onclick="uploadDoc()" class="btn-p"><i class="fa-solid fa-upload"></i> Upload</button>
      </div>
      <div class="text-[10px] text-gray-400">Upload a merchant doc (PAN, GST, agreement…) to the AI box, then ask questions grounded in it.</div>
      <div id="docList" class="border rounded divide-y max-h-40 overflow-y-auto"></div>
      <div id="docAsk" class="hidden border-t pt-2">
        <div class="text-[11px] text-gray-500 mb-1">Ask about <b id="docSel"></b>:</div>
        <div class="flex gap-2">
          <input id="docQ" class="border rounded px-2 py-1.5 flex-1" placeholder="e.g. What is the GST number? Is the KYC complete?" onkeydown="if(event.key==='Enter')askDoc()">
          <button onclick="askDoc()" class="btn-p">Ask</button>
        </div>
        <div id="docAnswer" class="mt-2 bg-gray-50 border rounded p-2 text-gray-700 hidden"></div>
      </div>
    </div>`, 'AI Documents — ' + name);
  loadDocs();
}
async function loadDocs(){
  const { data } = await apiGet('doc-ai.php', { merchant_id: _docMid });
  document.getElementById('docList').innerHTML = (data||[]).map(d=>`
    <div class="flex items-center justify-between px-2 py-1.5 hover:bg-gray-50">
      <button onclick="selectDoc(${d.id},'${esc(d.filename)}')" class="text-left flex-1 truncate text-indigo-600 hover:underline"><i class="fa-solid fa-file-lines"></i> ${esc(d.filename)}</button>
      <span class="text-[9px] ${d.mode==='qa'?'text-green-600':'text-amber-600'} mr-2">${d.mode==='qa'?'Q&A':'summary only'}</span>
      <button onclick="summaryDoc(${d.id})" class="text-gray-500 hover:text-indigo-600 mr-1" title="Summarize"><i class="fa-solid fa-align-left"></i></button>
      <button onclick="delDoc(${d.id})" class="text-red-400 hover:text-red-600" title="Remove"><i class="fa-solid fa-trash"></i></button>
    </div>`).join('') || '<div class="p-2 text-center text-gray-400">No documents yet</div>';
}
async function uploadDoc(){
  const f = document.getElementById('docFile').files[0];
  if(!f) return toast('Choose a file', false);
  const fd = new FormData(); fd.append('file', f); fd.append('merchant_id', _docMid);
  toast('Uploading to AI box…');
  const r = await fetch(API + 'doc-ai.php?action=upload', { method:'POST', credentials:'same-origin', body: fd });
  const j = await r.json();
  if(j.success){ toast(j.message||'Uploaded'); loadDocs(); } else toast(j.error||'Upload failed', false);
}
function selectDoc(id, name){
  _curDoc = id;
  document.getElementById('docAsk').classList.remove('hidden');
  document.getElementById('docSel').textContent = name;
  document.getElementById('docAnswer').classList.add('hidden');
  document.getElementById('docQ').focus();
}
async function askDoc(){
  const q = document.getElementById('docQ').value.trim(); if(!q || !_curDoc) return;
  const out = document.getElementById('docAnswer'); out.classList.remove('hidden'); out.textContent = 'Thinking…';
  const r = await apiSend('doc-ai.php?action=ask', { doc_id:_curDoc, question:q });
  out.textContent = r.success ? r.data.answer : ('⚠️ '+(r.error||'failed'));
}
async function summaryDoc(id){
  _curDoc = id; document.getElementById('docAsk').classList.remove('hidden');
  const out = document.getElementById('docAnswer'); out.classList.remove('hidden'); out.textContent = 'Summarizing…';
  const r = await apiSend('doc-ai.php?action=summary', { doc_id:id });
  out.textContent = r.success ? r.data.answer : ('⚠️ '+(r.error||'failed'));
}
async function delDoc(id){ await apiSend('doc-ai.php?action=delete&id='+id, {}); loadDocs(); }

loadMetrics(); load();
</script>
</html>
