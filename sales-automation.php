<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard(); // automation is a manager/admin capability
sales_head('Automation');
sales_header($me, 'Sales · Automation');
sales_nav('automation');
?>
<main class="p-2 space-y-2">
  <div class="flex items-center justify-between">
    <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide">Workflow Rules</h3>
    <div class="flex gap-2">
      <button onclick="runTimebased()" class="bg-gray-200 px-3 py-1.5 text-xs rounded hover:bg-gray-300"><i class="fa-solid fa-clock-rotate-left"></i> Run time-based now</button>
      <button onclick="newRule()" class="bg-indigo-600 text-white px-3 py-1.5 text-xs rounded hover:bg-indigo-700"><i class="fa-solid fa-plus"></i> New Rule</button>
    </div>
  </div>

  <div class="card p-3">
    <div class="overflow-x-auto border rounded-lg">
      <table class="w-full text-xs">
        <thead class="bg-gray-100 text-gray-700"><tr>
          <th class="px-2 py-2 text-left">Rule</th><th class="px-2 py-2 text-left">Entity</th>
          <th class="px-2 py-2 text-left">Trigger</th><th class="px-2 py-2 text-left">Actions</th>
          <th class="px-2 py-2 text-left">Runs</th><th class="px-2 py-2 text-left">Active</th><th class="px-2 py-2 text-left"></th>
        </tr></thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
  </div>

  <div class="card p-3">
    <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide mb-2">Recent Automation Activity</h3>
    <div id="logs" class="text-xs space-y-1 max-h-72 overflow-y-auto"></div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<!-- Quill rich text editor (for email body) -->
<link href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<style>.ql-editor{min-height:120px;font-size:12px} .ql-toolbar.ql-snow,.ql-container.ql-snow{border-color:#e5e7eb}</style>
<script>
let EMP = [];

/* friendly vocab for the visual builder (no JSON for the user) */
const FIELDS = {
  lead: [['lead_status','Status'],['lead_source','Source'],['score','AI Score'],['rating','Rating'],['company','Company'],['industry','Industry'],['email','Email'],['phone','Phone'],['next_followup_at','Next follow-up'],['updated_at','Last updated'],['created_at','Created']],
  deal: [['stage_name','Stage'],['stage_type','Stage type'],['amount','Amount'],['probability','Probability %'],['expected_close_date','Close date'],['updated_at','Last updated']],
  merchant: [['onboarding_stage','Onboarding stage'],['kyc_status','KYC status'],['risk_rating','Risk'],['expected_monthly_volume','Monthly volume'],['category','Category'],['business_type','Business type'],['created_at','Created']],
};
const OPS = [['eq','is'],['ne','is not'],['gt','greater than'],['lt','less than'],['contains','contains'],['in','is any of'],['is_empty','is empty'],['not_empty','is not empty'],['older_than_days','older than (days)']];
const VALUE_OPTIONS = {
  lead_status:['new','contacted','qualified','unqualified','converted'], lead_source:['web','whatsapp','call','referral','campaign','manual'],
  rating:['hot','warm','cold'], stage_type:['open','won','lost'], kyc_status:['pending','partial','verified','rejected'],
  onboarding_stage:['created','kyc_pending','kyc_submitted','under_review','activated','suspended','rejected'],
  risk_rating:['low','medium','high'], business_type:['proprietorship','partnership','pvt_ltd','llp','public_ltd','trust','other'],
};
const ACTS = [['assign_owner','Assign owner'],['update_field','Update a field'],['add_tag','Add a tag'],['create_task','Create a task'],['notify','Send notification'],['send_email','Send email']];
const TRIGGERS = [['on_create','When created'],['on_update','When updated'],['on_stage_change','When stage changes'],['time_based','On a schedule (time-based)']];

let condState = [], actState = [], curEntity = 'lead', quills = {};

async function load() {
  const { data } = await apiGet('automations.php');
  document.getElementById('rows').innerHTML = data.map(r => {
    let acts = []; try { acts = JSON.parse(r.actions).map(a => a.type); } catch(e){}
    return `<tr class="border-t hover:bg-gray-50">
      <td class="px-2 py-1.5 font-medium">${esc(r.name)}</td>
      <td class="px-2 py-1.5">${badge(r.entity,'indigo')}</td>
      <td class="px-2 py-1.5">${esc(r.trigger_event)}</td>
      <td class="px-2 py-1.5">${acts.map(a=>badge(a,'gray')).join(' ')}</td>
      <td class="px-2 py-1.5 text-center">${r.run_count}</td>
      <td class="px-2 py-1.5">
        <button onclick="toggle(${r.id},${r.is_active==1?0:1})" class="${r.is_active==1?'text-green-600':'text-gray-400'}">
          <i class="fa-solid fa-toggle-${r.is_active==1?'on':'off'} text-lg"></i></button></td>
      <td class="px-2 py-1.5 whitespace-nowrap">
        <button onclick="editRule(${r.id})" class="text-indigo-600 hover:text-indigo-800 mr-2"><i class="fa-solid fa-pen"></i></button>
        <button onclick="delRule(${r.id})" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('') || '<tr><td colspan="7" class="px-2 py-4 text-center text-gray-400">No rules yet</td></tr>';
}

async function loadLogs() {
  const { data } = await apiGet('automations.php', { logs: 1 });
  document.getElementById('logs').innerHTML = data.map(l => `
    <div class="flex items-center gap-2 border-b py-1">
      ${badge(l.status, l.status==='success'?'green':'red')}
      <span class="font-medium">${esc(l.rule_name||'rule#'+l.rule_id)}</span>
      <span class="text-gray-400">${esc(l.entity)}#${l.entity_id}</span>
      <span class="text-gray-500 flex-1 truncate">${esc(l.actions_run)}</span>
      <span class="text-gray-300">${esc(l.created_at)}</span>
    </div>`).join('') || '<div class="text-gray-400">No activity yet</div>';
}

function opt(list, sel){ return list.map(([v,l])=>`<option value="${v}" ${v===sel?'selected':''}>${l}</option>`).join(''); }

/* ---------- visual rule builder (no JSON) ---------- */
function ruleForm(r) {
  r = r || { entity:'lead', trigger_event:'on_create', match_type:'all', conditions:[], actions:[], is_active:1, name:'' };
  curEntity = r.entity;
  condState = (typeof r.conditions==='string' ? JSON.parse(r.conditions||'[]') : (r.conditions||[]));
  actState  = (typeof r.actions==='string'    ? JSON.parse(r.actions||'[]')    : (r.actions||[]));
  quills = {};
  return `
  <form onsubmit="saveRule(event, ${r.id||0})" class="space-y-3 text-xs">
    <div>
      <label class="block text-gray-500 mb-1">Rule name</label>
      <input id="rName" value="${esc(r.name)}" placeholder="e.g. Welcome new web leads" required class="border rounded px-2 py-1.5 w-full">
    </div>
    <div class="grid grid-cols-3 gap-2">
      <div><label class="block text-gray-500 mb-1">Applies to</label>
        <select id="rEntity" onchange="curEntity=this.value; renderConds()" class="border rounded px-2 py-1.5 w-full">
          ${opt([['lead','Leads'],['deal','Deals'],['merchant','Merchants']], r.entity)}</select></div>
      <div><label class="block text-gray-500 mb-1">Trigger</label>
        <select id="rTrigger" class="border rounded px-2 py-1.5 w-full">${opt(TRIGGERS, r.trigger_event)}</select></div>
      <div><label class="block text-gray-500 mb-1">Match</label>
        <select id="rMatch" class="border rounded px-2 py-1.5 w-full">
          <option value="all" ${r.match_type==='all'?'selected':''}>ALL conditions</option>
          <option value="any" ${r.match_type==='any'?'selected':''}>ANY condition</option></select></div>
    </div>

    <div class="border rounded-lg p-2 bg-gray-50">
      <div class="flex items-center justify-between mb-1">
        <span class="font-semibold text-gray-600"><i class="fa-solid fa-filter text-indigo-500"></i> When these conditions match</span>
        <button type="button" onclick="addCond()" class="text-indigo-600 hover:underline"><i class="fa-solid fa-plus"></i> Add condition</button>
      </div>
      <div id="condRows" class="space-y-1"></div>
      <div class="text-[10px] text-gray-400 mt-1">No conditions = the rule always runs.</div>
    </div>

    <div class="border rounded-lg p-2 bg-gray-50">
      <div class="flex items-center justify-between mb-1">
        <span class="font-semibold text-gray-600"><i class="fa-solid fa-bolt text-amber-500"></i> Then do these actions</span>
        <button type="button" onclick="addAct()" class="text-indigo-600 hover:underline"><i class="fa-solid fa-plus"></i> Add action</button>
      </div>
      <div id="actRows" class="space-y-2"></div>
    </div>

    <div class="flex justify-end gap-2">
      <button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
      <button class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">Save Rule</button>
    </div>
  </form>`;
}

/* ----- conditions ----- */
function addCond(){ condState.push({ field:(FIELDS[curEntity][0]||[''])[0], op:'eq', value:'' }); renderConds(); }
function renderConds(){
  const el = document.getElementById('condRows'); if(!el) return;
  el.innerHTML = condState.map((c,i)=>{
    const fields = FIELDS[curEntity] || [];
    if (!fields.find(f=>f[0]===c.field)) c.field = (fields[0]||[''])[0];
    const noVal = c.op==='is_empty'||c.op==='not_empty';
    let valCell;
    if (noVal) valCell = '<span class="text-[10px] text-gray-400 px-1 py-1.5">(no value)</span>';
    else if (c.op==='older_than_days') valCell = `<input type="number" value="${esc(c.value||'')}" oninput="condState[${i}].value=this.value" placeholder="days" class="border rounded px-2 py-1.5 w-full">`;
    else if (VALUE_OPTIONS[c.field] && c.op!=='in') valCell = `<select onchange="condState[${i}].value=this.value" class="border rounded px-2 py-1.5 w-full"><option value="">—</option>${VALUE_OPTIONS[c.field].map(o=>`<option ${o===c.value?'selected':''}>${o}</option>`).join('')}</select>`;
    else valCell = `<input value="${esc(c.value||'')}" oninput="condState[${i}].value=this.value" placeholder="${c.op==='in'?'comma,list':'value'}" class="border rounded px-2 py-1.5 w-full">`;
    return `<div class="flex items-center gap-1">
      <select onchange="condState[${i}].field=this.value; renderConds()" class="border rounded px-2 py-1.5 w-1/3">${opt(fields, c.field)}</select>
      <select onchange="condState[${i}].op=this.value; renderConds()" class="border rounded px-2 py-1.5 w-1/3">${opt(OPS, c.op)}</select>
      <div class="w-1/3">${valCell}</div>
      <button type="button" onclick="condState.splice(${i},1); renderConds()" class="text-red-400 hover:text-red-600 px-1"><i class="fa-solid fa-xmark"></i></button>
    </div>`;
  }).join('') || '<div class="text-[10px] text-gray-400">No conditions.</div>';
}

/* ----- actions ----- */
function addAct(){ actState.push({ type:'notify', to:'owner', title:'', message:'' }); renderActs(); }
function empSelect(i, key, val){
  return `<select onchange="actState[${i}].${key}=this.value" class="border rounded px-2 py-1.5 w-full">
    <option value="">Select person…</option>${EMP.map(e=>`<option value="${e.id}" ${String(e.id)===String(val)?'selected':''}>${esc(e.full_name)}</option>`).join('')}</select>`;
}
function actFields(a, i){
  const T = (name,key,ph)=>`<input value="${esc(a[key]||'')}" oninput="actState[${i}].${key}=this.value" placeholder="${ph}" class="border rounded px-2 py-1.5 w-full">`;
  switch(a.type){
    case 'assign_owner':
      return `<div class="grid grid-cols-2 gap-2">
        <select onchange="actState[${i}].mode=this.value; renderActs()" class="border rounded px-2 py-1.5">
          <option value="round_robin" ${a.mode!=='specific'?'selected':''}>Round-robin (fair split)</option>
          <option value="specific" ${a.mode==='specific'?'selected':''}>A specific person</option></select>
        ${a.mode==='specific' ? empSelect(i,'employee_id',a.employee_id) : `<input value="${esc(a.pool||'all')}" oninput="actState[${i}].pool=this.value" placeholder="pool: all or 1,2,3" class="border rounded px-2 py-1.5">`}
      </div>`;
    case 'update_field': return `<div class="grid grid-cols-2 gap-2">${T('','field','field name (e.g. lead_status)')}${T('','value','new value')}</div>`;
    case 'add_tag':      return T('','tag','tag to add');
    case 'create_task':  return `<div class="grid grid-cols-2 gap-2">${T('','subject','task title')}<input type="number" value="${esc(a.due_in_days||'')}" oninput="actState[${i}].due_in_days=this.value" placeholder="due in N days" class="border rounded px-2 py-1.5"></div>`;
    case 'notify':       return `<div class="space-y-1">
        <select onchange="actState[${i}].to=this.value; renderActs()" class="border rounded px-2 py-1.5 w-full">
          <option value="owner" ${a.to!=='specific'?'selected':''}>Notify the record owner</option>
          <option value="specific" ${a.to==='specific'?'selected':''}>Notify a specific person</option></select>
        ${a.to==='specific'?empSelect(i,'employee_id',a.employee_id):''}
        ${T('','title','notification title')}${T('','message','message')}</div>`;
    case 'send_email':   return `<div class="space-y-1">
        <select onchange="actState[${i}].to=this.value" class="border rounded px-2 py-1.5 w-full">
          <option value="lead" ${a.to!=='owner'?'selected':''}>Email the lead/customer</option>
          <option value="owner" ${a.to==='owner'?'selected':''}>Email the record owner</option></select>
        <input value="${esc(a.subject||'')}" oninput="actState[${i}].subject=this.value" placeholder="Subject" class="border rounded px-2 py-1.5 w-full">
        <div class="text-[10px] text-gray-400">Body (rich text · use {{first_name}}, {{company}} for personalisation)</div>
        <div id="qbody_${i}"></div></div>`;
  }
  return '';
}
function renderActs(){
  const el = document.getElementById('actRows'); if(!el) return;
  el.innerHTML = actState.map((a,i)=>`
    <div class="bg-white border rounded p-2">
      <div class="flex items-center gap-1 mb-1">
        <span class="text-[10px] text-gray-400">Action ${i+1}</span>
        <select onchange="actState[${i}]={type:this.value}; renderActs()" class="border rounded px-2 py-1 ml-1">${opt(ACTS, a.type)}</select>
        <div class="flex-1"></div>
        <button type="button" onclick="actState.splice(${i},1); renderActs()" class="text-red-400 hover:text-red-600 px-1"><i class="fa-solid fa-xmark"></i></button>
      </div>
      ${actFields(a,i)}
    </div>`).join('') || '<div class="text-[10px] text-gray-400">Add at least one action.</div>';
  // (re)init Quill editors for any send_email actions
  quills = {};
  actState.forEach((a,i)=>{
    if (a.type==='send_email' && document.getElementById('qbody_'+i)) {
      const q = new Quill('#qbody_'+i, { theme:'snow', modules:{ toolbar:[['bold','italic','underline'],[{list:'ordered'},{list:'bullet'}],['link'],['clean']] } });
      if (a.body) q.root.innerHTML = a.body;
      q.on('text-change', ()=>{ actState[i].body = q.root.innerHTML; });
      quills[i] = q;
    }
  });
}

async function newRule(){ await ensureEmployees(); openModal(ruleForm(null), 'New Automation Rule'); renderConds(); renderActs(); }
async function editRule(id){ await ensureEmployees(); const { data } = await apiGet('automations.php', { id }); openModal(ruleForm(data), 'Edit Rule'); renderConds(); renderActs(); }
async function ensureEmployees(){ if(!EMP.length){ const r = await apiGet('leads.php',{employees:1}); EMP = r.data||[]; } }

async function saveRule(e, id) {
  e.preventDefault();
  // pull latest email bodies from Quill
  Object.entries(quills).forEach(([i,q])=>{ actState[i].body = q.root.innerHTML; });
  const conditions = condState.filter(c=>c.field).map(c=>({ field:c.field, op:c.op, value:c.value ?? '' }));
  const actions = actState.filter(a=>a.type);
  if (!actions.length) return toast('Add at least one action', false);
  const payload = {
    name: document.getElementById('rName').value,
    entity: document.getElementById('rEntity').value,
    trigger_event: document.getElementById('rTrigger').value,
    match_type: document.getElementById('rMatch').value,
    conditions, actions,
  };
  if (!payload.name) return toast('Give the rule a name', false);
  const res = id ? await apiSend('automations.php?id='+id, payload, 'PATCH') : await apiSend('automations.php', payload);
  if (res.success) { toast('Rule saved'); closeModal(); load(); } else toast(res.error||'Failed', false);
}
async function toggle(id, to){ const res = await apiSend('automations.php?id='+id, { is_active: to }, 'PATCH'); if(res.success){toast(to?'Enabled':'Disabled');load();} }
async function delRule(id){ if(!confirm('Delete this rule?'))return; const res = await apiSend('automations.php?id='+id, {}, 'DELETE'); if(res.success){toast('Deleted');load();} }
async function runTimebased(){ toast('Running…'); const res = await apiSend('automations.php?run_timebased=1', {}); if(res.success){ const n=Object.values(res.fired||{}).reduce((a,b)=>a+b,0); toast(`Time-based sweep done — ${n} actions`); load(); loadLogs(); } else toast(res.error||'Failed',false); }

load(); loadLogs();
</script>
</html>
