<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('IT');
sales_header($me, 'IT Dashboard');
sales_nav('it');
?>
<main class="p-2 space-y-2">
  <div id="kpis" class="kstrip"></div>

  <!-- MY TEAM — only rendered for a team lead: their reporting line's workload -->
  <div id="teamCard" class="card p-3 hidden">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-people-group text-indigo-500"></i> My Team</div>
      <a href="sales-timesheet.php" class="text-[11px] text-indigo-600 hover:underline">Team timesheets →</a>
    </div>
    <div id="teamKpis" class="flex gap-4 mb-2 text-xs"></div>
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Member</th><th>Sub-department</th><th class="text-right">Open tasks</th><th class="text-right">Overdue</th>
      <th class="text-right">Open tickets</th><th>Timesheet today</th><th></th>
    </tr></thead><tbody id="teamRows"></tbody></table></div>
  </div>

  <!-- integration health cards -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-plug-circle-bolt text-indigo-500"></i> Integration Health</div>
    <div id="integrations" class="grid grid-cols-2 md:grid-cols-5 gap-2"></div>
    <div class="text-[10px] text-gray-400 mt-2">Configure any of these in <a href="sales-settings.php" class="text-indigo-600">Settings</a>.</div>
  </div>

  <!-- ===================== Centralized APIs ===================== -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-tower-broadcast text-indigo-500"></i> Centralized APIs
        <span class="normal-case font-normal text-[10px] text-gray-400">— one OTP service every PayG system can call (email · SMS · WhatsApp)</span>
      </div>
      <span id="otpToken" class="text-[10px] text-gray-400 font-mono"></span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
      <!-- API catalog -->
      <div class="lg:col-span-1 space-y-2" id="apiCards"></div>

      <!-- SMS provider + OTP settings -->
      <div class="border rounded-lg p-2">
        <div class="text-[10px] uppercase text-gray-400 mb-1.5">SMS provider (Indian aggregators) &amp; OTP settings</div>
        <form onsubmit="saveOtpSettings(event)" class="space-y-1.5 text-xs">
          <label class="block">Provider
            <select name="sms_provider" class="ctl w-full mt-0.5" onchange="providerHint(this.value)">
              <option value="hspsms">HSP SMS (sms.hspsms.com) — PayG's gateway</option>
              <option value="msg91">MSG91 (msg91.com)</option>
              <option value="fast2sms">Fast2SMS (fast2sms.com)</option>
              <option value="textlocal">Textlocal India (textlocal.in)</option>
              <option value="custom">Custom HTTP gateway (Kaleyra / Gupshup / other)</option>
            </select></label>
          <label class="block" id="smsUserRow">Gateway username
            <input name="sms_username" class="ctl w-full mt-0.5" placeholder="e.g. myrajasthanclub"></label>
          <label class="block">Provider API key <span id="keySet" class="text-green-600"></span>
            <input name="sms_api_key" type="password" class="ctl w-full mt-0.5" placeholder="leave blank to keep saved key"></label>
          <div class="grid grid-cols-2 gap-1.5">
            <label class="block">Sender ID (DLT)<input name="sms_sender_id" class="ctl w-full mt-0.5" placeholder="e.g. PAYGIN"></label>
            <label class="block">Template / Flow ID<input name="sms_template_id" class="ctl w-full mt-0.5" placeholder="MSG91 DLT template id"></label>
          </div>
          <label class="block" id="customUrlRow" style="display:none">Custom URL template
            <input name="sms_custom_url" class="ctl w-full mt-0.5" placeholder="https://gw.example.com/sms?key={key}&to={phone}&msg=OTP {otp}"></label>
          <div class="grid grid-cols-4 gap-1.5">
            <label class="block">Length<input name="otp_length" type="number" min="4" max="8" class="ctl w-full mt-0.5"></label>
            <label class="block">Expiry (min)<input name="otp_expiry_minutes" type="number" min="1" max="60" class="ctl w-full mt-0.5"></label>
            <label class="block">Max tries<input name="max_attempts" type="number" min="1" max="10" class="ctl w-full mt-0.5"></label>
            <label class="block">Rate/10min<input name="rate_limit_per_10min" type="number" min="1" max="20" class="ctl w-full mt-0.5"></label>
          </div>
          <div id="provHint" class="text-[10px] text-gray-400"></div>
          <button class="btn-p w-full">Save API settings</button>
        </form>
      </div>

      <!-- test console + activity -->
      <div class="border rounded-lg p-2">
        <div class="text-[10px] uppercase text-gray-400 mb-1.5">Test console</div>
        <form onsubmit="otpTestSend(event)" class="flex gap-1.5 text-xs mb-1.5">
          <select name="channel" class="ctl w-24">
            <option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option>
          </select>
          <input name="to" required class="ctl flex-1" placeholder="email or phone">
          <button class="btn-p">Send OTP</button>
        </form>
        <form onsubmit="otpTestVerify(event)" class="flex gap-1.5 text-xs mb-2">
          <select name="channel" class="ctl w-24">
            <option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option>
          </select>
          <input name="to" required class="ctl flex-1" placeholder="same recipient">
          <input name="code" required class="ctl w-20" placeholder="code">
          <button class="btn-p">Verify</button>
        </form>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Recent OTP activity</div>
        <div class="overflow-y-auto max-h-40 overflow-x-auto">
          <table class="dense"><thead><tr><th>When</th><th>Ch</th><th>To</th><th>Status</th><th>Via</th></tr></thead>
          <tbody id="otpLog"></tbody></table>
        </div>
      </div>
    </div>
  </div>

  <!-- ===================== App API Keys & Message Templates ===================== -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
        <i class="fa-solid fa-key text-indigo-500"></i> App API Keys &amp; Message Templates
        <span class="normal-case font-normal text-[10px] text-gray-400">— one key per product (MMS, POS, VPOS, Payment API…); each app gets its own DLT templates &amp; sender account</span>
      </div>
      <div class="flex gap-2">
        <button onclick="importEmailTpls()" class="ctl hover:bg-gray-50 text-[11px]"><i class="fa-solid fa-file-import"></i> Import OTP email templates</button>
        <button onclick="newApp()" class="btn-p"><i class="fa-solid fa-plus"></i> New app key</button>
      </div>
    </div>

    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>App</th><th>Code</th><th>API key</th><th>Scopes</th><th>SMS sender</th><th>Email server</th>
      <th class="text-right">OTPs sent</th><th>Last used</th><th>Status</th><th></th>
    </tr></thead><tbody id="appRows"></tbody></table></div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-3">
      <div class="border rounded-lg p-2">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[10px] uppercase text-gray-400">Email servers (sender accounts)</span>
          <button onclick="editServer(0)" class="text-[11px] text-indigo-600 hover:underline"><i class="fa-solid fa-plus"></i> Add server</button>
        </div>
        <table class="dense"><tbody id="srvRows"></tbody></table>
        <p class="text-[10px] text-gray-400 mt-1">Passwords are write-only. Admin &amp; IT have full control; the default account stays in Settings → Email.</p>
      </div>
      <div class="border rounded-lg p-2">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[10px] uppercase text-gray-400">Template library <span id="tplCount"></span></span>
          <span class="flex gap-1">
            <select id="tplChan" class="ctl" onchange="loadTpls()"><option value="">All channels</option><option value="sms">SMS</option><option value="email">Email</option></select>
            <input id="tplQ" class="ctl w-36" placeholder="Search…" oninput="clearTimeout(window._tq);window._tq=setTimeout(loadTpls,300)">
            <button onclick="editTpl(0)" class="text-[11px] text-indigo-600 hover:underline whitespace-nowrap"><i class="fa-solid fa-plus"></i> New</button>
          </span>
        </div>
        <div class="overflow-y-auto max-h-56 overflow-x-auto"><table class="dense"><thead><tr>
          <th>Purpose</th><th>Name</th><th>Ch</th><th>App</th><th>OTP</th><th></th>
        </tr></thead><tbody id="tplRows"></tbody></table></div>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2">
    <!-- users by role -->
    <div class="card">
      <div class="p-2 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-users-gear text-indigo-500"></i> User Accounts</span>
        <a href="sales-users.php" class="text-[11px] text-indigo-600 hover:underline">Manage →</a>
      </div>
      <table class="dense"><thead><tr><th>Role</th><th>Active</th><th>Inactive</th></tr></thead><tbody id="users"></tbody></table>
    </div>
    <!-- api tokens -->
    <div class="card">
      <div class="p-2 border-b flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-key text-indigo-500"></i> API Tokens</span>
        <a href="sales-integrations.php" class="text-[11px] text-indigo-600 hover:underline">Integrations →</a>
      </div>
      <table class="dense"><tbody id="tokens"></tbody></table>
    </div>
    <!-- automation activity -->
    <div class="card">
      <div class="p-2 border-b text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-robot text-indigo-500"></i> Automation Activity</div>
      <table class="dense"><tbody id="autoLogs"></tbody></table>
    </div>
  </div>
</main>

<?php sales_scripts(); ?>
<script>
const roleColor = { admin:'red', manager:'indigo', sales_exec:'blue', hr:'green', it:'yellow' };
function statusDot(i){
  if(!i.enabled) return '<span class="text-gray-400 text-[10px]">○ off</span>';
  if(i.up===false) return '<span class="text-red-600 text-[10px] font-semibold">● DOWN</span>';
  if(i.up===true)  return '<span class="text-green-600 text-[10px] font-semibold">● up</span>';
  return '<span class="text-green-600 text-[10px]">● on</span>';
}
async function load(){
  const { data:d } = await apiGet('it.php'); const k=d.kpis;
  document.getElementById('kpis').innerHTML = [
    ['Integrations on', k.integrations_on+' / '+k.integrations_all],
    ['Active users', k.users_active],['Inactive', k.users_inactive, k.users_inactive>0],
    ['Automation rules', k.rules_active],['Automation runs', k.automation_runs],
    ['Txns today', d.system.transactions_today],
  ].map(([l,v,warn])=>`<div><div class="kv ${warn?'text-red-600':''}">${v}</div><div class="kl">${l}</div></div>`).join('');

  // team lead drill-down: only present for manager-tier callers with reports
  if (d.team) {
    document.getElementById('teamCard').classList.remove('hidden');
    document.getElementById('teamKpis').innerHTML = [
      ['Team open tickets', d.team.tickets_open], ['Team open tasks', d.team.tasks_open],
      ['Open escalations', d.team.escalations_open, d.team.escalations_open > 0],
    ].map(([l,v,warn])=>`<div><span class="font-bold ${warn?'text-red-600':'text-gray-800'}">${v}</span> <span class="text-gray-400">${l}</span></div>`).join('');
    document.getElementById('teamRows').innerHTML = d.team.members.map(m=>`
      <tr><td class="font-medium">${esc(m.full_name)}</td>
        <td class="text-[11px] text-gray-500">${esc(m.sub_department||'—')}</td>
        <td class="text-right">${m.open_tasks}</td>
        <td class="text-right ${+m.overdue_tasks>0?'text-red-600 font-semibold':''}">${m.overdue_tasks}</td>
        <td class="text-right">${m.open_tickets}</td>
        <td>${+m.timesheet_today ? badge('filled','green') : badge('missing','red')}</td>
        <td class="rowact"><a href="sales-tickets.php?owner=${m.id}" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Tickets</a></td>
      </tr>`).join('') || '<tr><td colspan="7" class="text-gray-400 text-center py-2">No direct reports</td></tr>';
  }

  document.getElementById('integrations').innerHTML = d.integrations.map(i=>`
    <div class="border rounded-lg p-2 ${i.up===false?'border-red-200 bg-red-50':''}">
      <div class="flex items-center justify-between"><i class="fa-solid ${i.icon} text-gray-500"></i>${statusDot(i)}</div>
      <div class="text-[11px] font-semibold mt-1">${esc(i.label)}</div>
      <div class="text-[10px] text-gray-400 truncate">${esc(i.detail||'')}</div>
      ${i.note?`<div class="text-[9px] text-red-500">${esc(i.note)}</div>`:''}
    </div>`).join('');

  document.getElementById('users').innerHTML = d.users_by_role.map(u=>`
    <tr><td>${badge(u.role, roleColor[u.role]||'gray')}</td><td class="font-semibold">${u.active}</td>
      <td class="text-gray-400">${u.inactive}</td></tr>`).join('') || '<tr><td colspan="3" class="text-gray-400 text-center py-2">—</td></tr>';

  document.getElementById('tokens').innerHTML = d.api_tokens.map(t=>`
    <tr><td class="text-[11px] font-medium">${esc(t.label)}</td>
      <td>${t.is_active==1?'<span class="text-green-600 text-[10px]">active</span>':'<span class="text-gray-400 text-[10px]">off</span>'}</td>
      <td class="text-[10px] text-gray-400">${esc(t.last_used_at||'never')}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-gray-400 text-center py-2 text-xs">No tokens</td></tr>';

  document.getElementById('autoLogs').innerHTML = (d.automation_logs||[]).map(l=>`
    <tr><td>${badge(l.status, l.status==='success'?'green':'red')}</td>
      <td class="text-[11px]">${esc(l.rule_name||'rule')}</td>
      <td class="text-[10px] text-gray-400">${esc(String(l.created_at||'').slice(5,16))}</td></tr>`).join('')
    || '<tr><td colspan="3" class="text-gray-400 text-center py-2 text-xs">No activity</td></tr>';
}
load();

/* ===================== Centralized APIs ===================== */
const API_BASE = location.origin + '/api/integrations/otp.php';
const API_LIST = [
  { name:'Email OTP API', icon:'fa-envelope', ch:'email',
    desc:'Sends a one-time code by email via the CRM SMTP settings.' },
  { name:'SMS OTP API', icon:'fa-comment-sms', ch:'sms',
    desc:'Sends a one-time code by SMS via the configured Indian provider (MSG91 / Fast2SMS / Textlocal / custom).' },
  { name:'WhatsApp OTP API', icon:'fa-brands fa-whatsapp', ch:'whatsapp',
    desc:'Sends a one-time code on WhatsApp via the integrated Business API (template fallback outside the 24h window).' },
];
let OTP_READY = {};

function curlFor(ch){
  const to = ch==='email' ? 'user@example.com' : '98XXXXXXXX';
  return `curl -X POST "${API_BASE}?action=send" \\
  -H "X-API-Key: <token>" -H "Content-Type: application/json" \\
  -d '{"channel":"${ch}","to":"${to}","purpose":"login"}'
# then verify:
#   ?action=verify  {"channel":"${ch}","to":"${to}","code":"123456","purpose":"login"}`;
}

function renderApiCards(){
  document.getElementById('apiCards').innerHTML = API_LIST.map(a=>{
    const st = OTP_READY[a.ch];
    const dot = st ? (st.ready ? '<span class="text-green-600 text-[10px] font-semibold">● ready</span>'
                               : '<span class="text-red-600 text-[10px] font-semibold">● not configured</span>')
                   : '';
    return `<div class="border rounded-lg p-2">
      <div class="flex items-center justify-between">
        <span class="text-[11px] font-semibold"><i class="fa-solid ${a.icon} text-indigo-500"></i> ${a.name}</span>${dot}
      </div>
      <div class="text-[10px] text-gray-400 mb-1">${a.desc}${st&&st.detail?` · <span class="text-gray-500">${esc(st.detail)}</span>`:''}</div>
      <div class="flex gap-2">
        <button onclick="showCurl('${a.ch}','${a.name.replace(/'/g,'')}')" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded"><i class="fa-solid fa-code"></i> How to call</button>
        <span class="text-[10px] text-gray-400 font-mono truncate">POST /api/integrations/otp.php</span>
      </div>
    </div>`;
  }).join('');
}
function showCurl(ch, name){
  openModal(`<pre class="text-[10px] bg-gray-900 text-green-300 rounded p-3 overflow-x-auto whitespace-pre">${esc(curlFor(ch))}</pre>
    <p class="text-[10px] text-gray-400 mt-2">Auth: <b>X-API-Key</b> header with an integration token carrying scope <b>otp</b> (see token above / Integrations page). The code is never returned by the API — it goes only to the recipient.</p>`, name);
}
function providerHint(p){
  document.getElementById('customUrlRow').style.display = p==='custom' ? '' : 'none';
  document.getElementById('smsUserRow').style.display = (p==='hspsms') ? '' : 'none';
  const hints = {
    hspsms:   'HSP SMS: username + apikey + DLT sender header; smstype=TRANS — sms.hspsms.com/sendSMS (the gateway in your SendOtp code)',
    msg91:    'MSG91: authkey + DLT template with ##OTP## variable — control.msg91.com/api/v5/otp',
    fast2sms: 'Fast2SMS: authorization key, dedicated OTP route — fast2sms.com/dev/bulkV2',
    textlocal:'Textlocal: apikey + 6-char DLT sender id — api.textlocal.in/send',
    custom:   'Custom: HTTP GET template with {phone}, {otp} and {key} placeholders — works with Kaleyra, Gupshup, Airtel IQ, etc.',
  };
  document.getElementById('provHint').textContent = hints[p]||'';
}

async function loadOtp(){
  const { data } = await apiGet('otp-admin.php', { settings:1 });
  const s = data.settings; OTP_READY = data.channels || {};
  const f = document.querySelector('form[onsubmit^="saveOtpSettings"]');
  f.sms_provider.value = s.sms_provider; providerHint(s.sms_provider);
  f.sms_sender_id.value = s.sms_sender_id||''; f.sms_template_id.value = s.sms_template_id||'';
  f.sms_custom_url.value = s.sms_custom_url||''; f.sms_username.value = s.sms_username||'';
  f.otp_length.value = s.otp_length; f.otp_expiry_minutes.value = s.otp_expiry_minutes;
  f.max_attempts.value = s.max_attempts; f.rate_limit_per_10min.value = s.rate_limit_per_10min;
  document.getElementById('keySet').textContent = s.sms_api_key_set ? '(saved ✓)' : '';
  const t = (data.tokens||[])[0];
  document.getElementById('otpToken').innerHTML = t ? `API key: <b>${esc(t.token)}</b> <button onclick="navigator.clipboard.writeText('${esc(t.token)}');toast('Token copied')" class="text-indigo-600"><i class="fa-solid fa-copy"></i></button>` : '';
  renderApiCards();
  loadOtpLog();
}
async function saveOtpSettings(e){
  e.preventDefault(); const f = e.target;
  const body = { sms_provider:f.sms_provider.value, sms_sender_id:f.sms_sender_id.value,
    sms_template_id:f.sms_template_id.value, sms_custom_url:f.sms_custom_url.value, sms_username:f.sms_username.value,
    otp_length:+f.otp_length.value, otp_expiry_minutes:+f.otp_expiry_minutes.value,
    max_attempts:+f.max_attempts.value, rate_limit_per_10min:+f.rate_limit_per_10min.value };
  if (f.sms_api_key.value) body.sms_api_key = f.sms_api_key.value;
  const res = await apiSend('otp-admin.php?settings=1', body);
  if (res.success){ toast('API settings saved'); f.sms_api_key.value=''; loadOtp(); } else toast(res.error||'Failed', false);
}
async function otpTestSend(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('otp-admin.php?test_send=1', { channel:f.channel.value, to:f.to.value });
  toast(res.success ? res.data.message : (res.error||'Failed'), !!res.success);
  loadOtpLog();
}
async function otpTestVerify(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('otp-admin.php?test_verify=1', { channel:f.channel.value, to:f.to.value, code:f.code.value });
  if (res.success) toast(res.data.message, !!res.data.verified); else toast(res.error||'Failed', false);
  loadOtpLog();
}
async function loadOtpLog(){
  const { data } = await apiGet('otp-admin.php', { log:1 });
  const SC = { sent:'blue', verified:'green', expired:'gray', failed:'red' };
  document.getElementById('otpLog').innerHTML = (data||[]).slice(0,25).map(r=>`
    <tr><td class="text-[10px] text-gray-400 whitespace-nowrap">${esc(String(r.created_at||'').slice(5,16))}</td>
    <td>${badge(r.channel, r.channel==='whatsapp'?'green':r.channel==='sms'?'yellow':'blue')}</td>
    <td class="text-[10px]">${esc(r.recipient)}</td>
    <td>${badge(r.status, SC[r.status]||'gray')}${r.status==='failed'&&r.provider_result?`<div class="text-[9px] text-red-400 max-w-[140px] truncate" title="${esc(r.provider_result)}">${esc(r.provider_result)}</div>`:''}</td>
    <td class="text-[10px] text-gray-400">${esc(r.requested_via||'')}</td></tr>`).join('')
    || '<tr><td colspan="5" class="text-gray-400 text-center py-2 text-xs">No OTPs sent yet</td></tr>';
}
loadOtp();

/* ===================== App API Keys & Templates ===================== */
let APPS = [], SERVERS = [], PURPOSES = [];
const SCOPES = ['otp','sms','email'];

async function loadApps(){
  const { data } = await apiGet('apps-admin.php', { apps:1 });
  APPS = data.apps||[]; SERVERS = data.servers||[]; PURPOSES = data.purposes||[];
  document.getElementById('appRows').innerHTML = APPS.map(a=>`
    <tr class="${+a.is_active?'':'opacity-50'}">
      <td class="font-semibold">${esc(a.app_name)}<div class="text-[10px] text-gray-400">${esc(a.description||'')}</div></td>
      <td class="font-mono text-[10px]">${esc(a.app_code)}</td>
      <td class="font-mono text-[10px]">${esc(a.api_key.slice(0,10))}…
        <button onclick="navigator.clipboard.writeText('${esc(a.api_key)}');toast('Key copied')" class="text-indigo-600" title="Copy full key"><i class="fa-solid fa-copy"></i></button></td>
      <td>${a.scopes.split(',').map(s=>`<span class="text-[9px] bg-indigo-50 text-indigo-700 rounded px-1.5 py-0.5 mr-0.5">${esc(s)}</span>`).join('')}</td>
      <td class="text-[11px]">${esc(a.sms_sender_id||'—')}</td>
      <td class="text-[11px]">${esc(a.email_server_name||'default')}</td>
      <td class="text-right">${a.otp_sent}</td>
      <td class="text-[10px] text-gray-400">${esc(a.last_used_at||'never')}</td>
      <td>${+a.is_active?'<span class="text-green-600 text-[10px]">● active</span>':'<span class="text-gray-400 text-[10px]">○ off</span>'}</td>
      <td class="rowact whitespace-nowrap">
        <button onclick="editApp(${a.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Edit</button>
        <button onclick="rotateApp(${a.id})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Rotate key</button>
        <button onclick="appDocs(${a.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">How to call</button>
        <button onclick="toggleApp(${a.id}, ${+a.is_active?0:1})" class="text-[10px] ${+a.is_active?'bg-red-100 text-red-700':'bg-green-100 text-green-700'} px-2 py-0.5 rounded">${+a.is_active?'Disable':'Enable'}</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="10" class="text-center text-gray-400 py-3">No app keys yet — click "New app key".</td></tr>';
  document.getElementById('srvRows').innerHTML = SERVERS.map(s=>`
    <tr class="${+s.is_active?'':'opacity-50'}">
      <td class="font-medium text-[11px]">${esc(s.name)}</td>
      <td class="text-[10px] text-gray-500">${esc(s.from_email)}</td>
      <td class="text-[10px]">${esc(s.smtp_host||'—')}:${s.smtp_port}</td>
      <td>${+s.password_set?'<span class="text-green-600 text-[9px]">key ✓</span>':'<span class="text-amber-600 text-[9px]">no password</span>'}</td>
      <td class="rowact whitespace-nowrap">
        <button onclick="editServer(${s.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Edit</button>
        <button onclick="testServer(${s.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Test</button>
      </td>
    </tr>`).join('');
  loadTpls();
}

async function loadTpls(){
  const p = { templates:1 };
  const c = document.getElementById('tplChan').value; if (c) p.channel = c;
  const q = document.getElementById('tplQ').value; if (q) p.q = q;
  const { data } = await apiGet('apps-admin.php', p);
  document.getElementById('tplCount').textContent = '(' + (data||[]).length + ')';
  document.getElementById('tplRows').innerHTML = (data||[]).map(t=>`
    <tr class="${+t.is_active?'':'opacity-40'}">
      <td class="font-mono text-[9px]">${esc(t.purpose)}</td>
      <td class="text-[11px] max-w-[160px] truncate" title="${esc(t.content).slice(0,300)}">${esc(t.name)}</td>
      <td>${badge(t.channel, t.channel==='email'?'blue':'purple')}</td>
      <td class="text-[10px]">${esc(t.app_name||'global')}</td>
      <td>${+t.is_otp?'<i class="fa-solid fa-shield text-green-500 text-[10px]"></i>':''}</td>
      <td class="rowact"><button onclick="editTpl(${t.id})" class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Edit</button></td>
    </tr>`).join('') || '<tr><td colspan="6" class="text-gray-400 text-center py-2 text-xs">No templates</td></tr>';
}

function appForm(a){
  a = a || {};
  const scopes = (a.scopes||'otp').split(',');
  return `
    <div class="grid grid-cols-2 gap-2 text-xs">
      <label class="block">App name<input name="app_name" required value="${esc(a.app_name||'')}" class="ctl w-full mt-0.5" placeholder="e.g. PayG POS"></label>
      <label class="block">App code<input name="app_code" value="${esc(a.app_code||'')}" ${a.id?'disabled':''} class="ctl w-full mt-0.5" placeholder="pos"></label>
      <label class="block col-span-2">Description<input name="description" value="${esc(a.description||'')}" class="ctl w-full mt-0.5"></label>
      <div class="col-span-2 flex gap-4">${SCOPES.map(s=>`<label class="flex items-center gap-1.5">
        <input type="checkbox" class="chk scope-chk" value="${s}" ${scopes.includes(s)?'checked':''}> ${s.toUpperCase()}</label>`).join('')}</div>
      <label class="block">SMS sender header (DLT)<input name="sms_sender_id" value="${esc(a.sms_sender_id||'')}" class="ctl w-full mt-0.5" placeholder="PAYGin"></label>
      <label class="block">Email sender account<select name="email_server_id" class="ctl w-full mt-0.5">
        <option value="">Default (Settings → Email)</option>
        ${SERVERS.filter(s=>+s.is_active).map(s=>`<option value="${s.id}" ${a.email_server_id==s.id?'selected':''}>${esc(s.name)}</option>`).join('')}
      </select></label>
    </div>`;
}
function newApp(){
  openModal(`<form onsubmit="saveApp(event, null)">${appForm()}
    <div class="flex justify-end gap-2 pt-3 text-xs"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Create app + key</button></div></form>`, 'New app API key');
}
function editApp(id){
  const a = APPS.find(x=>+x.id===+id);
  openModal(`<form onsubmit="saveApp(event, ${id})">${appForm(a)}
    <div class="flex justify-end gap-2 pt-3 text-xs"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Save</button></div></form>`, 'Edit — ' + a.app_name);
}
async function saveApp(e, id){
  e.preventDefault(); const f = e.target;
  const body = { app_name:f.app_name.value, description:f.description.value, sms_sender_id:f.sms_sender_id.value,
    email_server_id:f.email_server_id.value, scopes:[...f.querySelectorAll('.scope-chk:checked')].map(c=>c.value) };
  let res;
  if (id) res = await apiSend('apps-admin.php?app_id='+id, body, 'PATCH');
  else { body.app_code = f.app_code.value; res = await apiSend('apps-admin.php?app=1', body); }
  if (res.success){ closeModal();
    if (!id) openModal(`<p class="text-xs text-gray-600 mb-2">App created. This is its API key — share it with the product team:</p>
      <div class="font-mono text-xs bg-gray-900 text-green-300 rounded p-3 break-all">${esc(res.data.api_key)}</div>
      <button onclick="navigator.clipboard.writeText('${esc(res.data.api_key)}');toast('Copied')" class="btn-p mt-2"><i class="fa-solid fa-copy"></i> Copy key</button>`, 'API key');
    else toast('Saved');
    loadApps(); }
  else toast(res.error||'Failed', false);
}
async function rotateApp(id){
  if (!confirm('Rotate this key? The old key stops working immediately.')) return;
  const res = await apiSend('apps-admin.php?rotate='+id, {});
  if (res.success){ openModal(`<p class="text-xs text-gray-600 mb-2">New key (update the product's config):</p>
    <div class="font-mono text-xs bg-gray-900 text-green-300 rounded p-3 break-all">${esc(res.data.api_key)}</div>`, 'Key rotated'); loadApps(); }
  else toast(res.error||'Failed', false);
}
async function toggleApp(id, on){
  const res = await apiSend('apps-admin.php?app_id='+id, { is_active:on }, 'PATCH');
  if (res.success){ loadApps(); } else toast(res.error||'Failed', false);
}
function appDocs(id){
  const a = APPS.find(x=>+x.id===+id);
  const base = location.origin + '/api/integrations';
  openModal(`<pre class="text-[10px] bg-gray-900 text-green-300 rounded p-3 overflow-x-auto whitespace-pre"># send an OTP (uses ${esc(a.app_name)}'s own DLT template for the purpose)
curl -X POST "${base}/otp.php?action=send" \\
  -H "X-API-Key: &lt;key&gt;" -H "Content-Type: application/json" \\
  -d '{"channel":"sms","to":"98XXXXXXXX","purpose":"merchant_registration"}'

# validate the OTP the user typed
curl -X POST "${base}/otp.php?action=verify" \\
  -H "X-API-Key: &lt;key&gt;" -H "Content-Type: application/json" \\
  -d '{"channel":"sms","to":"98XXXXXXXX","code":"123456","purpose":"merchant_registration"}'

# templated transactional SMS (scope: sms) — {#var#} filled in order
curl -X POST "${base}/messages.php?action=send_sms" \\
  -H "X-API-Key: &lt;key&gt;" -d '{"to":"98XXXXXXXX","purpose":"notification","vars":["TXN123","₹5,000"]}'

# templated email (scope: email) — sent from the app's sender account
curl -X POST "${base}/messages.php?action=send_email" \\
  -H "X-API-Key: &lt;key&gt;" -d '{"to":"a@b.com","purpose":"send_otp_customer","vars":{"name":"Anita"}}'</pre>
  <p class="text-[10px] text-gray-400 mt-2">Purposes available: ${PURPOSES.map(esc).join(', ')||'—'}</p>`, a.app_name + ' — how to call');
}

/* ---- email servers ---- */
function editServer(id){
  const s = SERVERS.find(x=>+x.id===+id) || {};
  openModal(`<form onsubmit="saveServer(event, ${id||0})" class="grid grid-cols-2 gap-2 text-xs">
    <label class="block">Name<input name="name" required value="${esc(s.name||'')}" class="ctl w-full mt-0.5" placeholder="Transaction Receipts"></label>
    <label class="block">From email<input name="from_email" type="email" required value="${esc(s.from_email||'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">From name<input name="from_name" value="${esc(s.from_name||'PayG')}" class="ctl w-full mt-0.5"></label>
    <label class="block">SMTP host<input name="smtp_host" value="${esc(s.smtp_host||'smtp.office365.com')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Port<input name="smtp_port" type="number" value="${s.smtp_port||587}" class="ctl w-full mt-0.5"></label>
    <label class="block">Encryption<select name="encryption" class="ctl w-full mt-0.5">
      ${['tls','ssl','none'].map(e=>`<option ${((s.encryption||'tls')===e)?'selected':''}>${e}</option>`).join('')}</select></label>
    <label class="block">Username<input name="username" value="${esc(s.username||'')}" class="ctl w-full mt-0.5"></label>
    <label class="block">Password<input name="password" type="password" class="ctl w-full mt-0.5" placeholder="${+s.password_set?'saved — blank keeps it':'required to send'}"></label>
    <label class="flex items-center gap-1.5 col-span-2"><input name="act" type="checkbox" class="chk" ${(+s.is_active||!id)?'checked':''}> Active</label>
    <div class="col-span-2 flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Save server</button></div></form>`, id ? 'Edit — ' + s.name : 'Add email server');
}
async function saveServer(e, id){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('apps-admin.php?servers=1', { id, name:f.name.value, from_email:f.from_email.value,
    from_name:f.from_name.value, smtp_host:f.smtp_host.value, smtp_port:+f.smtp_port.value,
    encryption:f.encryption.value, username:f.username.value, password:f.password.value, is_active:f.act.checked?1:0 });
  if (res.success){ closeModal(); toast('Server saved'); loadApps(); } else toast(res.error||'Failed', false);
}
async function testServer(id){
  const to = prompt('Send a test email to:',''); if (!to) return;
  const res = await apiSend('apps-admin.php?test_server='+id, { to });
  if (res.success) toast(res.data.sent ? 'Test sent ✓' : ('Failed: ' + res.data.detail), !!res.data.sent);
  else toast(res.error||'Failed', false);
}

/* ---- templates ---- */
function editTpl(id){
  apiGet('apps-admin.php', { templates:1 }).then(({data})=>{
    const t = (data||[]).find(x=>+x.id===+id) || {};
    openModal(`<form onsubmit="saveTpl(event, ${id||0})" class="space-y-2 text-xs">
      <div class="grid grid-cols-2 gap-2">
        <label class="block">Name<input name="name" required value="${esc(t.name||'')}" class="ctl w-full mt-0.5"></label>
        <label class="block">Purpose (API key)<input name="purpose" value="${esc(t.purpose||'generic')}" class="ctl w-full mt-0.5" placeholder="merchant_registration"></label>
        <label class="block">Channel<select name="channel" ${id?'disabled':''} class="ctl w-full mt-0.5">
          <option value="sms" ${t.channel!=='email'?'selected':''}>SMS</option><option value="email" ${t.channel==='email'?'selected':''}>Email</option></select></label>
        <label class="block">App<select name="app_id" class="ctl w-full mt-0.5">
          <option value="">Global (all apps)</option>
          ${APPS.map(a=>`<option value="${a.id}" ${t.app_id==a.id?'selected':''}>${esc(a.app_name)}</option>`).join('')}</select></label>
        <label class="block">DLT template id<input name="dlt_template_id" value="${esc(t.dlt_template_id||'')}" class="ctl w-full mt-0.5"></label>
        <label class="block">Sender header<input name="sender_header" value="${esc(t.sender_header||'')}" class="ctl w-full mt-0.5" placeholder="PAYGin"></label>
        <label class="block col-span-2">Email subject<input name="subject" value="${esc(t.subject||'')}" class="ctl w-full mt-0.5" placeholder="(email only)"></label>
      </div>
      <label class="block">Content <span class="text-gray-400">({#otp#} {#minutes#} named, {#var#} positional)</span>
        <textarea name="content" rows="5" required class="ctl w-full mt-0.5 py-1 font-mono text-[10px]">${esc(t.content||'')}</textarea></label>
      <label class="flex items-center gap-1.5"><input name="isotp" type="checkbox" class="chk" ${+t.is_otp?'checked':''}> This is an OTP template</label>
      <div class="flex justify-between gap-2 pt-1">
        ${id?`<button type="button" onclick="delTpl(${id})" class="px-3 py-1.5 border border-red-200 text-red-600 rounded">Deactivate</button>`:'<span></span>'}
        <span class="flex gap-2"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="btn-p">Save template</button></span>
      </div></form>`, id ? 'Edit template' : 'New template');
  });
}
async function saveTpl(e, id){
  e.preventDefault(); const f = e.target;
  const body = { name:f.name.value, purpose:f.purpose.value, dlt_template_id:f.dlt_template_id.value,
    sender_header:f.sender_header.value, subject:f.subject.value, content:f.content.value,
    is_otp:f.isotp.checked?1:0, app_id:f.app_id.value };
  let res;
  if (id) res = await apiSend('apps-admin.php?template_id='+id, body, 'PATCH');
  else { body.channel = f.channel.value; res = await apiSend('apps-admin.php?template=1', body); }
  if (res.success){ closeModal(); toast('Template saved'); loadTpls(); } else toast(res.error||'Failed', false);
}
async function delTpl(id){
  const res = await apiSend('apps-admin.php?template_id='+id, {}, 'DELETE');
  if (res.success){ closeModal(); toast('Template deactivated'); loadTpls(); } else toast(res.error||'Failed', false);
}
async function importEmailTpls(){
  const res = await apiSend('apps-admin.php?import_email=1', {});
  if (res.success){ toast(`Imported ${res.data.imported.length} email OTP template(s), ${res.data.skipped_existing} already present`); loadTpls(); }
  else toast(res.error||'Failed', false);
}
loadApps();
</script>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>
</html>
