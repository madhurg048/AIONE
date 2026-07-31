<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
// The Centralized APIs console is IT + admin only, matching api/sales/otp-admin.php.
// The 'it' MODULE is granted more widely in role_access (manager, sales_exec), so the
// page itself opens for them — render the OTP card only for who can actually use it,
// otherwise its loader just eats a 403.
$otpAdmin = in_array(sales_role(), ['it', 'it_manager', 'admin'], true);
sales_head('IT');
sales_header($me, 'IT Dashboard');
sales_nav('it');
?>
<main class="p-2 space-y-2">
  <div id="kpis" class="kstrip"></div>

  <!-- integration health cards -->
  <div class="card p-3">
    <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-2"><i class="fa-solid fa-plug-circle-bolt text-indigo-500"></i> Integration Health</div>
    <div id="integrations" class="grid grid-cols-2 md:grid-cols-5 gap-2"></div>
    <div class="text-[10px] text-gray-400 mt-2">Configure any of these in <a href="sales-settings.php" class="text-indigo-600">Settings</a>.</div>
  </div>

  <!-- ===================== Centralized APIs ===================== -->
  <?php if ($otpAdmin): ?>
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
              <option value="msg91">MSG91 (msg91.com)</option>
              <option value="fast2sms">Fast2SMS (fast2sms.com)</option>
              <option value="textlocal">Textlocal India (textlocal.in)</option>
              <option value="custom">Custom HTTP gateway (Kaleyra / Gupshup / other)</option>
            </select></label>
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
  <?php endif; ?>

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
  const hints = {
    msg91:    'MSG91: authkey + DLT template with ##OTP## variable — control.msg91.com/api/v5/otp',
    fast2sms: 'Fast2SMS: authorization key, dedicated OTP route — fast2sms.com/dev/bulkV2',
    textlocal:'Textlocal: apikey + 6-char DLT sender id — api.textlocal.in/send',
    custom:   'Custom: HTTP GET template with {phone}, {otp} and {key} placeholders — works with Kaleyra, Gupshup, Airtel IQ, etc.',
  };
  document.getElementById('provHint').textContent = hints[p]||'';
}

async function loadOtp(){
  // card is PHP-gated to IT+admin, and the phase51 tables may not be migrated yet —
  // never let either case throw and kill the rest of the IT dashboard's scripts.
  if (!document.getElementById('apiCards')) return;
  try {
    const { data } = await apiGet('otp-admin.php', { settings:1 });
    const s = data.settings; OTP_READY = data.channels || {};
    const f = document.querySelector('form[onsubmit^="saveOtpSettings"]');
    f.sms_provider.value = s.sms_provider; providerHint(s.sms_provider);
    f.sms_sender_id.value = s.sms_sender_id||''; f.sms_template_id.value = s.sms_template_id||'';
    f.sms_custom_url.value = s.sms_custom_url||'';
    f.otp_length.value = s.otp_length; f.otp_expiry_minutes.value = s.otp_expiry_minutes;
    f.max_attempts.value = s.max_attempts; f.rate_limit_per_10min.value = s.rate_limit_per_10min;
    document.getElementById('keySet').textContent = s.sms_api_key_set ? '(saved ✓)' : '';
    const t = (data.tokens||[])[0];
    document.getElementById('otpToken').innerHTML = t ? `API key: <b>${esc(t.token)}</b> <button onclick="navigator.clipboard.writeText('${esc(t.token)}');toast('Token copied')" class="text-indigo-600"><i class="fa-solid fa-copy"></i></button>` : '';
    renderApiCards();
    loadOtpLog();
  } catch(err){ /* leave the card empty rather than breaking the page */ }
}
async function saveOtpSettings(e){
  e.preventDefault(); const f = e.target;
  const body = { sms_provider:f.sms_provider.value, sms_sender_id:f.sms_sender_id.value,
    sms_template_id:f.sms_template_id.value, sms_custom_url:f.sms_custom_url.value,
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
</script>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>
</html>
