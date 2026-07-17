<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();            // admin only
sales_head('Admin');
sales_header($me, 'Admin · Incentive Structure');
sales_nav('admin');
?>
<main class="p-2 space-y-2">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">

    <!-- ===== PLANS + RULES ===== -->
    <div class="lg:col-span-2 space-y-2">
      <div class="flex items-center justify-between px-1">
        <h3 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Incentive Plans &amp; Rules</h3>
        <button onclick="newPlan()" class="btn-p"><i class="fa-solid fa-plus"></i> New Plan</button>
      </div>
      <div id="plans" class="space-y-2"></div>
    </div>

    <!-- ===== LIVE PREVIEW CALCULATOR ===== -->
    <div class="card p-3 h-fit lg:sticky lg:top-14">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2">
        <i class="fa-solid fa-calculator text-indigo-500"></i> Payout Preview</div>
      <p class="text-[10px] text-gray-400 mb-2">Enter a hypothetical merchant to see what the current rules would pay.</p>
      <div class="space-y-2 text-xs">
        <label class="block">Expected monthly volume (₹)
          <input id="pv_volume" type="number" value="250000" class="ctl w-full" oninput="preview()"></label>
        <label class="block">Deal amount (₹)
          <input id="pv_deal" type="number" value="250000" class="ctl w-full" oninput="preview()"></label>
        <label class="block">Products (CSV: upi,cards,…)
          <input id="pv_products" value="upi,cards" class="ctl w-full" oninput="preview()"></label>
        <div class="grid grid-cols-2 gap-2">
          <label class="block">Category<input id="pv_cat" class="ctl w-full" oninput="preview()"></label>
          <label class="block">Business type<input id="pv_biz" class="ctl w-full" oninput="preview()"></label>
        </div>
      </div>
      <div id="pvResult" class="mt-3 border-t pt-2 text-xs"></div>
    </div>
  </div>

  <!-- ===== ROLE ACCESS (RBAC) ===== -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1">
      <i class="fa-solid fa-user-lock text-indigo-500"></i> Role Access — which modules each role sees</div>
    <p class="text-[10px] text-gray-400 mb-2">Tick to grant a role access to a module (controls the nav for that role). Sales execs see field tools; managers see more; admins see all.</p>
    <div class="overflow-x-auto"><table class="dense" id="rbacTable"></table></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const RULE_TYPES = {
  flat:          { label: 'Flat per merchant',        fields: ['flat_amount'] },
  percent:       { label: '% of deal amount',         fields: ['percent_rate'] },
  percent_volume:{ label: '% of monthly volume',      fields: ['percent_rate'] },
  slab_volume:   { label: 'Volume slab',              fields: ['slab_min','slab_max','slab_amount'] },
  per_product:   { label: '₹ per enabled product',    fields: ['flat_amount'] },
};

async function loadPlans() {
  const { data } = await apiGet('incentive-admin.php');
  document.getElementById('plans').innerHTML = data.map(p => `
    <div class="card">
      <div class="flex items-center justify-between p-2 border-b">
        <div>
          <span class="font-semibold text-sm">${esc(p.name)}</span>
          ${p.is_active==1?badge('active','green'):badge('inactive','gray')}
          ${p.stack_rules==1?badge('rules stack','indigo'):badge('highest rule only','yellow')}
          <div class="text-[10px] text-gray-400">${esc(p.description||'')}${p.effective_from?` · ${esc(p.effective_from)}→${esc(p.effective_to||'∞')}`:''}</div>
        </div>
        <div class="flex items-center gap-2 text-xs">
          <button onclick="addRule(${p.id})" class="text-indigo-600 hover:underline"><i class="fa-solid fa-plus"></i> Rule</button>
          <button onclick="editPlan(${p.id})" class="text-gray-500 hover:text-indigo-600"><i class="fa-solid fa-pen"></i></button>
          <button onclick="delPlan(${p.id})" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
      <table class="dense"><thead><tr>
        <th>Rule</th><th>Type</th><th>Payout</th><th>Applies to</th>
        <th title="Order the rules are evaluated in (lower runs first). For a 'highest rule only' plan it also breaks ties.">Order <i class="fa-solid fa-circle-info text-gray-300"></i></th><th>On</th><th></th>
      </tr></thead><tbody>
        ${(p.rules||[]).map(r => `<tr>
          <td class="font-medium">${esc(r.label||'—')}</td>
          <td>${esc((RULE_TYPES[r.rule_type]||{}).label||r.rule_type)}</td>
          <td>${payoutDesc(r)}</td>
          <td class="text-gray-500">${filterDesc(r)||'all merchants'}</td>
          <td>${r.priority||0}</td>
          <td>${r.is_active==1?'<i class="fa-solid fa-toggle-on text-green-500"></i>':'<i class="fa-solid fa-toggle-off text-gray-400"></i>'}</td>
          <td class="rowact">
            <button onclick="editRule(${p.id},${r.id})" class="text-indigo-600 hover:text-indigo-800"><i class="fa-solid fa-pen"></i></button>
            <button onclick="delRule(${r.id})" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-trash"></i></button>
          </td></tr>`).join('') || '<tr><td colspan="7" class="text-gray-400 text-center py-2">No rules — add one</td></tr>'}
      </tbody></table>
    </div>`).join('') || '<div class="card p-4 text-gray-400 text-center">No plans yet</div>';
  window._plans = data;
  preview();
}
function payoutDesc(r){
  if (r.rule_type==='flat')           return inr(r.flat_amount);
  if (r.rule_type==='percent')        return r.percent_rate+'% of deal';
  if (r.rule_type==='percent_volume') return r.percent_rate+'% of volume';
  if (r.rule_type==='slab_volume')    return inr(r.slab_amount)+' ['+inr(r.slab_min)+'–'+inr(r.slab_max)+']';
  if (r.rule_type==='per_product')    return inr(r.flat_amount)+' × products';
  return '';
}
function filterDesc(r){
  const f=[]; if(r.product)f.push('product='+r.product); if(r.category)f.push('cat='+r.category); if(r.business_type)f.push('type='+r.business_type);
  return f.join(', ');
}

/* ---- plan modal ---- */
function planForm(p){
  p=p||{name:'',description:'',effective_from:'',effective_to:'',stack_rules:1,is_active:1};
  return `<form onsubmit="savePlan(event,${p.id||0})" class="space-y-3 text-xs">
    <input name="name" value="${esc(p.name)}" placeholder="Plan name" required class="border rounded px-2 py-1.5 w-full">
    <input name="description" value="${esc(p.description||'')}" placeholder="Description" class="border rounded px-2 py-1.5 w-full">
    <div class="grid grid-cols-2 gap-2">
      <label>Effective from<input name="effective_from" type="date" value="${esc(p.effective_from||'')}" class="border rounded px-2 py-1.5 w-full"></label>
      <label>Effective to<input name="effective_to" type="date" value="${esc(p.effective_to||'')}" class="border rounded px-2 py-1.5 w-full"></label>
    </div>
    <label class="flex items-center gap-2"><input type="checkbox" name="stack_rules" ${p.stack_rules==1?'checked':''}> Stack all matching rules (else pay only the highest)</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="is_active" ${p.is_active==1?'checked':''}> Active</label>
    <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Save Plan</button></div>
  </form>`;
}
function newPlan(){ openModal(planForm(null),'New Plan'); }
function editPlan(id){ openModal(planForm(window._plans.find(p=>p.id==id)),'Edit Plan'); }
async function savePlan(e,id){
  e.preventDefault();
  const fd=Object.fromEntries(new FormData(e.target).entries());
  fd.stack_rules=e.target.stack_rules.checked?1:0; fd.is_active=e.target.is_active.checked?1:0;
  const res=id?await apiSend('incentive-admin.php?plan='+id,fd,'PATCH'):await apiSend('incentive-admin.php?plan',fd);
  if(res.success){toast('Plan saved');closeModal();loadPlans();}else toast(res.error||'Failed',false);
}
async function delPlan(id){ if(!confirm('Delete plan + its rules?'))return; const r=await apiSend('incentive-admin.php?plan='+id,{},'DELETE'); if(r.success){toast('Deleted');loadPlans();} }

/* ---- rule modal (fields adapt to type) ---- */
function ruleFields(type){
  const F={
    flat_amount:`<label>Amount (₹)<input name="flat_amount" type="number" step="0.01" class="border rounded px-2 py-1.5 w-full"></label>`,
    percent_rate:`<label>Rate (%)<input name="percent_rate" type="number" step="0.01" class="border rounded px-2 py-1.5 w-full"></label>`,
    slab_min:`<label>Volume min (₹)<input name="slab_min" type="number" class="border rounded px-2 py-1.5 w-full"></label>`,
    slab_max:`<label>Volume max (₹)<input name="slab_max" type="number" class="border rounded px-2 py-1.5 w-full"></label>`,
    slab_amount:`<label>Slab amount (₹)<input name="slab_amount" type="number" class="border rounded px-2 py-1.5 w-full"></label>`,
  };
  return (RULE_TYPES[type].fields||[]).map(f=>F[f]).join('');
}
function ruleForm(planId, r){
  r=r||{rule_type:'flat',label:'',product:'',category:'',business_type:'',priority:10,is_active:1};
  return `<form onsubmit="saveRule(event,${planId},${r.id||0})" class="space-y-3 text-xs">
    <input name="label" value="${esc(r.label||'')}" placeholder="Rule label (e.g. Base bonus)" class="border rounded px-2 py-1.5 w-full">
    <label>Type<select name="rule_type" onchange="document.getElementById('rf').innerHTML=ruleFields(this.value)" class="border rounded px-2 py-1.5 w-full">
      ${Object.entries(RULE_TYPES).map(([k,v])=>`<option value="${k}" ${r.rule_type===k?'selected':''}>${v.label}</option>`).join('')}
    </select></label>
    <div id="rf" class="grid grid-cols-2 gap-2">${ruleFields(r.rule_type)}</div>
    <div class="border-t pt-2 text-[10px] text-gray-400 uppercase">Optional targeting filters</div>
    <div class="grid grid-cols-3 gap-2">
      <input name="product" value="${esc(r.product||'')}" placeholder="product" class="border rounded px-2 py-1.5">
      <input name="category" value="${esc(r.category||'')}" placeholder="category" class="border rounded px-2 py-1.5">
      <input name="business_type" value="${esc(r.business_type||'')}" placeholder="biz type" class="border rounded px-2 py-1.5">
    </div>
    <div class="grid grid-cols-2 gap-2">
      <label>Order (evaluation sequence)<input name="priority" type="number" value="${r.priority||10}" class="border rounded px-2 py-1.5 w-full"></label>
      <label class="flex items-center gap-2 mt-5"><input type="checkbox" name="is_active" ${r.is_active==1?'checked':''}> Active</label>
    </div>
    <div class="flex justify-end gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button><button class="btn-p">Save Rule</button></div>
  </form>`;
}
function addRule(planId){ openModal(ruleForm(planId,null),'Add Rule'); setTimeout(()=>document.getElementById('rf').innerHTML=ruleFields('flat'),0); }
function editRule(planId,ruleId){ const p=window._plans.find(x=>x.id==planId); openModal(ruleForm(planId,(p.rules||[]).find(r=>r.id==ruleId)),'Edit Rule'); }
async function saveRule(e,planId,ruleId){
  e.preventDefault();
  const fd=Object.fromEntries(new FormData(e.target).entries());
  fd.plan_id=planId; fd.is_active=e.target.is_active.checked?1:0;
  const res=ruleId?await apiSend('incentive-admin.php?rule='+ruleId,fd,'PATCH'):await apiSend('incentive-admin.php?rule',fd);
  if(res.success){toast('Rule saved');closeModal();loadPlans();}else toast(res.error||'Failed',false);
}
async function delRule(id){ if(!confirm('Delete rule?'))return; const r=await apiSend('incentive-admin.php?rule='+id,{},'DELETE'); if(r.success){toast('Deleted');loadPlans();} }

/* ---- live preview ---- */
async function preview(){
  const body={volume:+pv_volume.value||0, deal_amount:+pv_deal.value||0, products:pv_products.value, category:pv_cat.value, business_type:pv_biz.value};
  const res=await apiSend('incentive-admin.php?preview',body);
  const el=document.getElementById('pvResult');
  if(!res.success){ el.innerHTML=`<span class="text-red-500">${esc(res.error||'error')}</span>`; return; }
  const d=res.data;
  el.innerHTML = (d.lines.length? d.lines.map(l=>`<div class="flex justify-between border-b py-0.5"><span class="text-gray-600">${esc(l.basis)}</span><span class="font-semibold">${inr(l.amount)}</span></div>`).join('') : '<div class="text-gray-400">No rule matches this merchant.</div>')
    + `<div class="flex justify-between mt-2 pt-1 border-t font-bold text-indigo-700"><span>Total incentive</span><span>${inr(d.total)}</span></div>`;
}

/* ---- role access (RBAC) matrix ---- */
async function loadRbac(){
  const { data } = await apiGet('rbac.php');
  const mods = Object.entries(data.modules), roles = Object.entries(data.roles);
  let html = '<thead><tr><th>Module</th>' + roles.map(([r,l])=>`<th class="text-center">${esc(l)}</th>`).join('') + '</tr></thead><tbody>';
  html += mods.map(([m,ml])=>`<tr><td class="font-medium">${esc(ml)}</td>` +
    roles.map(([r])=>`<td class="text-center"><input type="checkbox" class="chk" ${data.matrix[r][m]?'checked':''} onchange="setAccess('${r}','${m}',this.checked)"></td>`).join('') + '</tr>').join('');
  document.getElementById('rbacTable').innerHTML = html + '</tbody>';
}
async function setAccess(role, mod, on){
  const r = await apiSend('rbac.php', { role, module_key: mod, can_access: on ? 1 : 0 });
  if (r.success) toast(`${role} · ${mod}: ${on?'granted':'revoked'}`); else toast('Failed', false);
}

loadPlans(); loadRbac();
</script>
</html>
