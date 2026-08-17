<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Settings');
sales_header($me, 'Sales · Settings');
sales_nav('settings');

/* KPA/KRA management is for managers and above only (team-member roles also have
   'settings' access, so gate the tile explicitly by role). */
$__kpaRole = sales_role();
$__kpaMgrPlus = $__kpaRole === 'admin' || $__kpaRole === 'manager' || $__kpaRole === 'department'
    || (bool)preg_match('/_manager$/', $__kpaRole);
?>
<main class="p-2 space-y-2">

  <?php if ($__kpaMgrPlus): ?>
  <!-- KPA / KRA GOALS (managers & above) -->
  <a href="sales-kpa.php" class="card p-3 flex items-center justify-between hover:shadow-md transition no-underline">
    <div class="flex items-center gap-3">
      <span class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center"><i class="fa-solid fa-crosshairs"></i></span>
      <div>
        <h3 class="text-[12px] font-semibold text-gray-800">KPA / KRA Goals</h3>
        <p class="text-xs text-gray-500"><?= $__kpaRole === 'admin'
          ? 'Define Key Performance / Result Areas for each org group and review the whole organisation.'
          : 'Inherit your org &amp; supervisor goals and set additional KPAs for your team.' ?></p>
      </div>
    </div>
    <span class="text-indigo-600 text-xs font-medium">Open <i class="fa-solid fa-arrow-right"></i></span>
  </a>
  <?php endif; ?>

  <!-- AI ENGINE -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-microchip text-indigo-500"></i> AI Engine <button type="button" onclick="showHelp('ai')" class="ml-2 text-[10px] normal-case font-normal text-indigo-500 hover:text-indigo-700" title="Setup help"><i class="fa-regular fa-circle-question"></i> Need keys?</button></h3>
      <span id="aiHealth" class="text-[11px]"></span>
    </div>
    <p class="text-xs text-gray-500 mb-2">Route AI (lead scoring, insights, summaries, coaching) to your on-prem <b>AI box</b>. If the box is down it falls over to the cloud.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
      <label class="flex items-center gap-1.5 mt-4"><input type="checkbox" id="aiBoxEnabled" class="chk"> Use AI box</label>
      <label class="md:col-span-2">AI box URL / IP<input id="aiBoxUrl" class="ctl w-full mt-1" placeholder="http://192.168.0.100:8000"></label>
      <label>AI API key<input id="aiKey" type="password" class="ctl w-full mt-1" placeholder="optional"></label>
      <label>Restart token<input id="aiRestartTok" type="password" class="ctl w-full mt-1" placeholder="to bring box up"></label>
      <label class="flex items-center gap-1.5 mt-4"><input type="checkbox" id="aiCloudEnabled" class="chk"> Cloud fallback</label>
      <label class="md:col-span-2">Cloud AI endpoint<input id="aiCloudUrl" class="ctl w-full mt-1" placeholder="https://…/chat_v3"></label>
      <label>Cloud API key<input id="aiCloudKey" type="password" class="ctl w-full mt-1" placeholder="optional"></label>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <button onclick="saveAi()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      <button onclick="checkAi()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-heart-pulse"></i> Check box</button>
      <button onclick="restartAi()" class="ctl hover:bg-gray-50 text-amber-700"><i class="fa-solid fa-power-off"></i> Restart box</button>
    </div>

    <!-- hit the box tester -->
    <div class="mt-3 border-t pt-2">
      <div class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1">Hit the AI box — quick test</div>
      <div class="flex flex-wrap gap-2 items-start">
        <textarea id="aiTestText" rows="2" class="border rounded px-2 py-1.5 text-xs flex-1 min-w-[240px]" placeholder="Paste text to summarize, or ask a question…"></textarea>
        <select id="aiTestAction" class="ctl"><option value="summarize">Summarize</option><option value="chat">Chat</option></select>
        <button onclick="runAiTest()" class="btn-p"><i class="fa-solid fa-play"></i> Run</button>
      </div>
      <div id="aiTestOut" class="mt-2 text-xs text-gray-700 bg-gray-50 border rounded p-2 hidden"></div>
    </div>
  </div>

  <!-- EMAIL / NOTIFICATIONS -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-envelope text-indigo-500"></i> Email / Notifications <button type="button" onclick="showHelp('email')" class="ml-2 text-[10px] normal-case font-normal text-indigo-500 hover:text-indigo-700" title="How to get these keys"><i class="fa-regular fa-circle-question"></i> Need keys?</button></h3>
      <label class="flex items-center gap-1.5 text-xs text-gray-600"><input type="checkbox" id="emActive" class="chk"> Enabled</label>
    </div>
    <p class="text-xs text-gray-500 mb-2">The account the app uses to send all notifications — welcome emails, follow-up reminders and automation emails.</p>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">
      <label>Account type
        <select id="emMethod" onchange="emPreset()" class="ctl w-full mt-1">
          <option value="smtp">SMTP (basic)</option>
          <option value="ad_exchange">Microsoft 365 / Exchange (AD)</option>
          <option value="imap_pop">IMAP / POP account</option>
        </select></label>
      <label>From name<input id="emFromName" class="ctl w-full mt-1"></label>
      <label>From email<input id="emFromEmail" type="email" class="ctl w-full mt-1"></label>
      <label>SMTP host<input id="emHost" class="ctl w-full mt-1" placeholder="smtp.office365.com"></label>
      <label>Port<input id="emPort" type="number" class="ctl w-full mt-1" placeholder="587"></label>
      <label>Encryption
        <select id="emEnc" class="ctl w-full mt-1"><option value="tls">STARTTLS</option><option value="ssl">SSL</option><option value="none">None</option></select></label>
      <label>Username<input id="emUser" class="ctl w-full mt-1" placeholder="user@domain.com"></label>
      <label>Password<input id="emPass" type="password" class="ctl w-full mt-1" placeholder="••••••"></label>
      <label id="emDomainWrap" class="hidden">AD domain<input id="emDomain" class="ctl w-full mt-1" placeholder="corp.local"></label>
      <label id="emImapHostWrap" class="hidden">IMAP/POP host<input id="emImapHost" class="ctl w-full mt-1"></label>
      <label id="emImapPortWrap" class="hidden">IMAP/POP port<input id="emImapPort" type="number" class="ctl w-full mt-1" placeholder="993"></label>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <button onclick="saveEmail()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
      <span class="text-gray-300">|</span>
      <input id="emTestTo" type="email" placeholder="test@recipient.com" class="ctl">
      <button onclick="testEmail()" class="bg-gray-200 px-3 py-1.5 text-xs rounded hover:bg-gray-300"><i class="fa-solid fa-paper-plane"></i> Send test</button>
      <span id="emMsg" class="text-xs"></span>
    </div>
    <p class="text-[10px] text-gray-400 mt-1">POP/IMAP are receiving protocols — outgoing notifications send via the SMTP host above. For Microsoft 365/Exchange, sign in with your AD login (user@domain) — host <code>smtp.office365.com:587</code>, STARTTLS.</p>
  </div>

  <!-- AUTO-EMAIL ALERTS (timesheet / SLA / targets, manager looped in) -->
  <div class="card p-3" id="alertCard">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-bell text-indigo-500"></i> Auto-email Alerts</h3>
      <span class="flex items-center gap-3">
        <span id="alSmtp" class="text-[10px]"></span>
        <label class="flex items-center gap-1.5 text-xs text-gray-600"><input type="checkbox" id="alEnabled" class="chk"> Enabled</label>
      </span>
    </div>
    <p class="text-xs text-gray-500 mb-2">Automatic emails when a <b>timesheet isn't filled</b>, an <b>SLA/TAT is breached</b> (every escalation hop, incl. Board), or a <b>sales target</b> runs below threshold — each one also <b>loops the person's manager in</b>. Uses the SMTP account configured above.</p>
    <div class="flex flex-wrap items-center gap-4 text-xs">
      <label class="flex items-center gap-1.5"><input type="checkbox" id="alCcMgr" class="chk"> Loop manager on every alert</label>
      <label class="flex items-center gap-1.5"><input type="checkbox" id="alTs" class="chk"> Timesheet not filled (daily)</label>
      <label class="flex items-center gap-1.5"><input type="checkbox" id="alSla" class="chk"> SLA / TAT breaches (per escalation)</label>
      <label class="flex items-center gap-1.5"><input type="checkbox" id="alTgt" class="chk"> Sales target below</label>
      <label class="flex items-center gap-1">threshold <input id="alTh" type="number" min="1" max="100" class="ctl w-16"> %</label>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <button onclick="saveAlerts()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      <button onclick="runAlertsNow()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-play"></i> Run daily sweep now</button>
      <span class="text-[10px] text-gray-400">cron: <code>0 17 * * * php cron/run-daily-alerts.php</code> · SLA emails fire from the escalation sweep automatically</span>
    </div>
    <div class="mt-2">
      <div class="text-[10px] uppercase text-gray-400 mb-1">Recent alert emails</div>
      <div class="overflow-x-auto max-h-40 overflow-y-auto"><table class="dense"><thead><tr>
        <th>When</th><th>Type</th><th>Person</th><th>To</th><th>Manager copy</th><th>Status</th>
      </tr></thead><tbody id="alLog"></tbody></table></div>
    </div>
  </div>

  <!-- MAILBOXES TO READ (AI INBOX) -->
  <div class="card p-3">
    <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide mb-1"><i class="fa-solid fa-inbox text-indigo-500"></i> Mailboxes to Read (AI Inbox) <button type="button" onclick="showHelp('mailbox')" class="ml-2 text-[10px] normal-case font-normal text-indigo-500 hover:text-indigo-700" title="How to get these details"><i class="fa-regular fa-circle-question"></i> Need keys?</button></h3>
    <p class="text-xs text-gray-500 mb-2">Add mailboxes (IMAP/POP3, incl. Microsoft 365/AD logins) for the AI to read. It highlights what to update &amp; drafts replies in the <b>Inbox</b> tab.</p>
    <div class="overflow-x-auto"><table class="dense"><thead><tr><th>Label</th><th>Host</th><th>User</th><th>Active</th><th></th></tr></thead><tbody id="mboxRows"></tbody></table></div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs mt-2 border-t pt-2">
      <input type="hidden" id="mbId">
      <label>Label<input id="mbLabel" class="ctl w-full mt-1" placeholder="Sales inbox"></label>
      <label>Protocol
        <select id="mbProto" class="ctl w-full mt-1"><option value="imap">IMAP</option><option value="pop3">POP3</option></select></label>
      <label>Host<input id="mbHost" class="ctl w-full mt-1" placeholder="outlook.office365.com"></label>
      <label>Port<input id="mbPort" type="number" class="ctl w-full mt-1" placeholder="993"></label>
      <label>Encryption<select id="mbEnc" class="ctl w-full mt-1"><option value="ssl">SSL</option><option value="tls">TLS</option><option value="none">None</option></select></label>
      <label>Username<input id="mbUser" class="ctl w-full mt-1" placeholder="sales@domain.com"></label>
      <label>Password<input id="mbPass" type="password" class="ctl w-full mt-1" placeholder="••••••"></label>
      <label>Folder<input id="mbFolder" class="ctl w-full mt-1" placeholder="INBOX"></label>
    </div>
    <div class="mt-2 flex gap-2"><button onclick="saveMbox()" class="btn-p"><i class="fa-solid fa-plus"></i> Save mailbox</button>
      <button onclick="clearMbox()" class="ctl hover:bg-gray-50">Clear</button></div>
    <p class="text-[10px] text-gray-400 mt-1">Microsoft 365 IMAP: <code>outlook.office365.com:993</code> SSL, sign in with the AD login. Passwords stored server-side.</p>
  </div>

  <!-- WHATSAPP -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-brands fa-whatsapp text-green-600"></i> WhatsApp Integration <button type="button" onclick="showHelp('whatsapp')" class="ml-2 text-[10px] normal-case font-normal text-indigo-500 hover:text-indigo-700" title="How to get these keys"><i class="fa-regular fa-circle-question"></i> Need keys?</button></h3>
      <label class="flex items-center gap-1.5 text-xs text-gray-600"><input type="checkbox" id="waActive" class="chk"> Enabled (Business API)</label>
    </div>
    <p class="text-xs text-gray-500 mb-2">Powers the WhatsApp <b>click-to-chat</b> buttons on leads &amp; deals. With the Business API enabled, messages send &amp; log automatically; otherwise agents get a pre-filled wa.me chat link.</p>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">
      <label>Provider
        <select id="waProvider" class="ctl w-full mt-1"><option value="meta_cloud">Meta Cloud API</option><option value="link_only">Link only (wa.me)</option></select></label>
      <label>Business number (display)<input id="waFrom" class="ctl w-full mt-1" placeholder="+91 98xxxxxx"></label>
      <label>Default country code<input id="waCC" class="ctl w-full mt-1" placeholder="91"></label>
      <label>Phone number ID<input id="waPhoneId" class="ctl w-full mt-1" placeholder="Meta phone_number_id"></label>
      <label>WABA ID<input id="waWaba" class="ctl w-full mt-1" placeholder="business_account_id"></label>
      <label>API token (permanent)<input id="waToken" type="password" class="ctl w-full mt-1" placeholder="••••••"></label>
      <label>Template name (for reopening a 24h-closed chat)<input id="waTplName" class="ctl w-full mt-1" placeholder="hello_world"></label>
      <label>Template language<input id="waTplLang" class="ctl w-full mt-1" placeholder="en_US"></label>
      <label>App ID (from App Dashboard → Settings → Basic)<input id="waAppId" class="ctl w-full mt-1" placeholder="e.g. 28273854155549296"></label>
      <label>App secret (from App Dashboard → Settings → Basic)<input id="waAppSecret" type="password" class="ctl w-full mt-1" placeholder="••••••"></label>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <button onclick="saveWa()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
      <span class="text-gray-300">|</span>
      <input id="waTestTo" placeholder="test phone (10-digit or intl)" class="ctl">
      <button onclick="testWa()" class="bg-green-600 text-white px-3 py-1.5 text-xs rounded hover:bg-green-700"><i class="fa-brands fa-whatsapp"></i> Send test</button>
      <span id="waMsg" class="text-xs"></span>
    </div>
    <p class="text-[10px] text-gray-400 mt-1">Get the token &amp; phone_number_id from Meta Business → WhatsApp → API setup. The token is stored server-side and never shown again.</p>
    <div class="mt-3 pt-2 border-t grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
      <label>Webhook URL (paste into Meta → WhatsApp → Configuration)
        <div class="flex gap-1 mt-1"><input id="waWebhookUrl" class="ctl w-full" readonly><button type="button" onclick="copyField('waWebhookUrl')" class="ctl px-2"><i class="fa-regular fa-copy"></i></button></div></label>
      <label>Verify token (paste into the same Meta webhook form)
        <div class="flex gap-1 mt-1"><input id="waVerifyToken" class="ctl w-full" readonly><button type="button" onclick="copyField('waVerifyToken')" class="ctl px-2"><i class="fa-regular fa-copy"></i></button></div></label>
    </div>
    <p class="text-[10px] text-gray-400 mt-1">Subscribe the webhook to the <b>messages</b> field. Local dev needs a public tunnel (e.g. ngrok) in front of this server for Meta to reach the URL above. The token shown in your screenshot is a <b>24h temporary token</b> — for production, generate a permanent System User token (see "Need keys?").</p>
  </div>

  <!-- CLICK-TO-CALL / TELEPHONY -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide"><i class="fa-solid fa-phone-volume text-indigo-500"></i> Click-to-Call (Telephony) <button type="button" onclick="showHelp('telephony')" class="ml-2 text-[10px] normal-case font-normal text-indigo-500 hover:text-indigo-700" title="How to get these keys"><i class="fa-regular fa-circle-question"></i> Need keys?</button></h3>
      <label class="flex items-center gap-1.5 text-xs text-gray-600"><input type="checkbox" id="tpActive" class="chk"> Enabled</label>
    </div>
    <p class="text-xs text-gray-500 mb-2">Powers the <b>cloud call</b> button on leads — the provider rings your phone and the customer's, then bridges them (your number stays private). Recommended lowest-cost: <b>TeleCMI</b> (subscription) or <b>Exotel</b> (pay-per-call).</p>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">
      <label>Provider
        <select id="tpProvider" onchange="tpHint()" class="ctl w-full mt-1"><option value="exotel">Exotel (pay-per-call)</option><option value="telecmi">TeleCMI (subscription)</option></select></label>
      <label id="tpSidL">Account SID / App ID<input id="tpSid" class="ctl w-full mt-1"></label>
      <label>Caller ID<input id="tpCaller" class="ctl w-full mt-1" placeholder="0806xxxxxxx"></label>
      <label>Sales number (for widget callbacks)<input id="tpSales" class="ctl w-full mt-1" placeholder="operator/hotline number"></label>
      <label>API key<input id="tpKey" class="ctl w-full mt-1"></label>
      <label>API token<input id="tpToken" type="password" class="ctl w-full mt-1" placeholder="••••••"></label>
      <label>API subdomain<input id="tpSub" class="ctl w-full mt-1" placeholder="api.exotel.com"></label>
      <label>Default country code<input id="tpCC" class="ctl w-full mt-1" placeholder="91"></label>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button onclick="saveTp()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
      <button onclick="testTp()" class="ctl hover:bg-gray-50"><i class="fa-solid fa-plug-circle-check"></i> Test connection</button>
      <span id="tpMsg" class="text-xs"></span>
    </div>
    <p class="text-[10px] text-gray-400 mt-1">When off, the call button dials from the current device (tel:). Set the provider's status callback to <code>/api/integrations/call-status.php</code> so call time auto-logs to timesheets.</p>

    <div class="mt-4 pt-3 border-t">
      <h4 class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide mb-1"><i class="fa-solid fa-headset text-indigo-500"></i> Calls Workspace (Service) — in-browser calling &amp; inbound routing</h4>
      <p class="text-xs text-gray-500 mb-2">Powers the Service team's <b>Calls</b> screen: agents call and receive calls from the browser tab, no phone bridging. Needs Exotel's WebRTC/App Bridge app in addition to the click-to-call credentials above.</p>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">
        <label>Toll-free / inbound number<input id="tpTollFree" class="ctl w-full mt-1" placeholder="1800xxxxxxx"></label>
        <label>WebRTC App ID<input id="tpWebrtcApp" class="ctl w-full mt-1"></label>
        <label>WebRTC domain<input id="tpWebrtcDomain" class="ctl w-full mt-1" placeholder="api.exotel.com"></label>
        <label class="md:col-span-3">Inbound call-flow ID<input id="tpFlowId" class="ctl w-full mt-1" placeholder="Exotel Flow/App id for the toll-free number"></label>
      </div>
      <p class="text-[10px] text-gray-400 mt-1">
        On Exotel's console: point the toll-free number's call flow at a Passthru applet calling
        <code><?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? ''), ENT_QUOTES) ?>/payg-merged/api/integrations/call-inbound.php</code>,
        then create a Voice/App Bridge app and paste its App ID + domain here. Until these three fields
        are filled in, the Calls screen still works fully for claiming/logging/KPIs — outbound dialing
        just falls back to phone-bridge mode automatically.
      </p>
    </div>
  </div>

  <!-- CUSTOM FIELDS -->
  <div class="card p-3">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide">Custom Fields</h3>
      <select id="entity" onchange="loadFields()" class="h-7 text-xs border rounded px-2">
        <option value="lead">Lead</option><option value="account">Account</option>
        <option value="contact">Contact</option><option value="deal">Deal</option><option value="merchant">Merchant</option>
        <option value="employee">User / Employee</option>
      </select>
    </div>
    <div class="overflow-x-auto border rounded-lg mb-3">
      <table class="w-full text-xs">
        <thead class="bg-gray-100 text-gray-700"><tr>
          <th class="px-2 py-2 text-left">Label</th><th class="px-2 py-2 text-left">Key</th>
          <th class="px-2 py-2 text-left">Type</th><th class="px-2 py-2 text-left">Options</th>
          <th class="px-2 py-2 text-left">Required</th><th class="px-2 py-2 text-left"></th>
        </tr></thead>
        <tbody id="fieldRows"></tbody>
      </table>
    </div>
    <form onsubmit="addField(event)" class="grid grid-cols-2 md:grid-cols-6 gap-2 text-xs items-end">
      <input name="label" placeholder="Label" required class="border rounded px-2 py-1.5">
      <input name="field_key" placeholder="field_key" required class="border rounded px-2 py-1.5 font-mono">
      <select name="field_type" class="border rounded px-2 py-1.5">
        <option>text</option><option>number</option><option>date</option>
        <option>select</option><option>checkbox</option><option>textarea</option>
      </select>
      <input name="options" placeholder='select opts: a,b,c' class="border rounded px-2 py-1.5">
      <label class="flex items-center gap-1"><input type="checkbox" name="is_required"> Required</label>
      <button class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">Add Field</button>
    </form>
  </div>

  <!-- WEB-TO-LEAD -->
  <div class="card p-3">
    <h3 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide mb-2">Web-to-Lead</h3>
    <p class="text-xs text-gray-500 mb-2">Embed this form on any website. Submissions create a lead and fire your <b>on_create</b> automations (round-robin assignment + welcome email). A sample lives at <code>web-to-lead-form.html</code>.</p>
    <pre id="snippet" class="bg-gray-900 text-green-300 text-[11px] rounded p-3 overflow-x-auto"></pre>
    <button onclick="copySnippet()" class="mt-2 bg-gray-200 px-3 py-1.5 text-xs rounded hover:bg-gray-300"><i class="fa-regular fa-copy"></i> Copy</button>

    <!-- QR CODE -->
    <div class="border-t mt-3 pt-3">
      <h4 class="text-[12px] font-semibold text-gray-700 uppercase tracking-wide mb-1"><i class="fa-solid fa-qrcode text-indigo-500"></i> Lead-capture QR code</h4>
      <p class="text-xs text-gray-500 mb-2">Print this on flyers, standees or invoices. Anyone who scans it opens the lead form on their phone — submissions land as leads tagged with the source below.</p>
      <div class="flex flex-wrap items-start gap-4">
        <div>
          <div id="qrbox" class="bg-white p-2 border rounded inline-block"></div>
          <div class="mt-2 flex gap-2">
            <button onclick="downloadQR()" class="bg-indigo-600 text-white px-3 py-1.5 text-xs rounded hover:bg-indigo-700"><i class="fa-solid fa-download"></i> Download PNG</button>
            <button onclick="printQR()" class="bg-gray-200 px-3 py-1.5 text-xs rounded hover:bg-gray-300"><i class="fa-solid fa-print"></i> Print</button>
          </div>
        </div>
        <div class="text-xs space-y-2 flex-1 min-w-[220px]">
          <label class="block">Campaign source (tracked on the lead)
            <input id="qrSrc" value="qr" oninput="makeQR()" class="border rounded px-2 py-1.5 w-full mt-1">
            <span class="text-[10px] text-gray-400">e.g. qr, qr_flyer, qr_event_delhi — each shows separately in Source ROI.</span>
          </label>
          <div>Scan target URL:
            <a id="qrUrl" href="#" target="_blank" class="block font-mono text-[10px] text-indigo-600 break-all mt-1"></a></div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- help / how-to modal -->
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b sticky top-0 bg-white">
      <h3 id="modalTitle" class="font-semibold text-sm text-gray-800"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
async function loadFields() {
  const entity = document.getElementById('entity').value;
  const { data } = await apiGet('custom-fields.php', { entity });
  document.getElementById('fieldRows').innerHTML = data.map(f => `
    <tr class="border-t">
      <td class="px-2 py-1.5 font-medium">${esc(f.label)}</td>
      <td class="px-2 py-1.5 font-mono text-gray-500">${esc(f.field_key)}</td>
      <td class="px-2 py-1.5">${badge(f.field_type,'indigo')}</td>
      <td class="px-2 py-1.5 text-gray-500">${f.options?esc(f.options.join(', ')):'—'}</td>
      <td class="px-2 py-1.5">${f.is_required==1?'✔':''}</td>
      <td class="px-2 py-1.5"><button onclick="delField(${f.id})" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`).join('') || '<tr><td colspan="6" class="px-2 py-3 text-center text-gray-400">No custom fields</td></tr>';
}

async function addField(e) {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target).entries());
  fd.entity = document.getElementById('entity').value;
  fd.is_required = e.target.is_required.checked ? 1 : 0;
  if (fd.field_type === 'select' && fd.options) fd.options = fd.options.split(',').map(s => s.trim()).filter(Boolean);
  else delete fd.options;
  const res = await apiSend('custom-fields.php', fd);
  if (res.success) { toast('Field added'); e.target.reset(); loadFields(); }
  else toast(res.error || 'Failed', false);
}
async function delField(id) {
  if (!confirm('Remove this field?')) return;
  const res = await apiSend('custom-fields.php?id=' + id, {}, 'DELETE');
  if (res.success) { toast('Removed'); loadFields(); }
}

const base = location.origin + location.pathname.replace(/[^\/]*$/, '');
document.getElementById('snippet').textContent =
`<form onsubmit="return payg(event)">
  <input name="first_name" placeholder="Name">
  <input name="email" placeholder="Email" required>
  <input name="phone" placeholder="Phone">
  <input name="company" placeholder="Business name">
  <input type="text" name="website" style="display:none"> <!-- honeypot -->
  <button>Get a callback</button>
</form>
<script>
function payg(e){e.preventDefault();
  fetch('${base}api/sales/web-to-lead.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(Object.fromEntries(new FormData(e.target)))})
   .then(r=>r.json()).then(()=>e.target.reset());
  return false;}
<\/script>`;
function copySnippet(){ navigator.clipboard.writeText(document.getElementById('snippet').textContent); toast('Copied'); }

/* ---- lead-capture QR code ---- */
const FORM_URL = base + 'web-to-lead-form.html';
function qrTarget(){ const s = encodeURIComponent((document.getElementById('qrSrc').value || 'qr').trim() || 'qr'); return FORM_URL + '?src=' + s; }
function makeQR(){
  if (typeof QRCode === 'undefined') return;
  const url = qrTarget();
  const a = document.getElementById('qrUrl'); a.textContent = url; a.href = url;
  const box = document.getElementById('qrbox'); box.innerHTML = '';
  new QRCode(box, { text: url, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
}
function qrCanvas(){ return document.querySelector('#qrbox canvas'); }
function downloadQR(){
  const c = qrCanvas(); if (!c) return toast('QR not ready', false);
  const a = document.createElement('a');
  a.href = c.toDataURL('image/png');
  a.download = 'payg-lead-qr-' + ((document.getElementById('qrSrc').value || 'qr').trim()) + '.png';
  a.click();
}
function printQR(){
  const c = qrCanvas(); if (!c) return;
  const w = window.open('', '_blank');
  w.document.write('<div style="text-align:center;font-family:sans-serif;margin-top:40px">'
    + '<img src="' + c.toDataURL('image/png') + '" style="width:300px;height:300px">'
    + '<h2 style="color:#4f46e5">Scan to get started with PayG</h2>'
    + '<p>Point your phone camera at the code</p></div>');
  w.document.close(); w.focus(); setTimeout(() => w.print(), 300);
}

/* ---- email / notification settings ---- */
const E = id => document.getElementById(id);
async function loadEmail(){
  const { data:c } = await apiGet('email-settings.php');
  if (!c || !c.id) { emPreset(true); return; }
  E('emMethod').value=c.method||'smtp'; E('emFromName').value=c.from_name||''; E('emFromEmail').value=c.from_email||'';
  E('emHost').value=c.smtp_host||''; E('emPort').value=c.smtp_port||587; E('emEnc').value=c.encryption||'tls';
  E('emUser').value=c.username||''; E('emDomain').value=c.ad_domain||''; E('emImapHost').value=c.imap_host||''; E('emImapPort').value=c.imap_port||993;
  E('emActive').checked = c.is_active==1;
  E('emPass').placeholder = c.has_password==1 ? '•••••• (saved — leave blank to keep)' : '••••••';
  emPreset(true);
}
function emPreset(keep){
  const m = E('emMethod').value;
  E('emDomainWrap').classList.toggle('hidden', m!=='ad_exchange');
  E('emImapHostWrap').classList.toggle('hidden', m!=='imap_pop');
  E('emImapPortWrap').classList.toggle('hidden', m!=='imap_pop');
  if (!keep && m==='ad_exchange' && !E('emHost').value){ E('emHost').value='smtp.office365.com'; E('emPort').value=587; E('emEnc').value='tls'; }
}
async function saveEmail(){
  const body = { method:E('emMethod').value, from_name:E('emFromName').value, from_email:E('emFromEmail').value,
    smtp_host:E('emHost').value, smtp_port:E('emPort').value, encryption:E('emEnc').value, username:E('emUser').value,
    ad_domain:E('emDomain').value, imap_host:E('emImapHost').value, imap_port:E('emImapPort').value, is_active:E('emActive').checked?1:0 };
  if (E('emPass').value) body.password = E('emPass').value;
  const r = await apiSend('email-settings.php', body);
  if (r.success){ toast('Email settings saved'); E('emPass').value=''; loadEmail(); } else toast(r.error||'Failed', false);
}
async function testEmail(){
  const to = E('emTestTo').value.trim(); if(!to) return toast('Enter a test recipient', false);
  const msg = E('emMsg'); msg.textContent='Sending…'; msg.className='text-xs text-gray-500';
  const r = await apiSend('email-settings.php?test=1', { to });
  msg.textContent = r.success ? ('✅ '+(r.message||'sent')) : ('⚠️ '+(r.error||'failed'));
  msg.className = 'text-xs ' + (r.success ? 'text-green-600' : 'text-red-500');
}

/* ---- whatsapp settings ---- */
async function loadWa(){
  const { data:c } = await apiGet('whatsapp-settings.php');
  if (!c || !c.id) return;
  E('waProvider').value=c.provider||'meta_cloud'; E('waFrom').value=c.from_display||''; E('waCC').value=c.default_cc||'91';
  E('waPhoneId').value=c.phone_number_id||''; E('waWaba').value=c.business_account_id||'';
  E('waTplName').value=c.template_name||'hello_world'; E('waTplLang').value=c.template_lang||'en_US';
  E('waActive').checked = c.enabled==1;
  E('waAppId').value = c.app_id||'';
  E('waToken').placeholder = c.has_token==1 ? '•••••• (saved — leave blank to keep)' : '••••••';
  E('waAppSecret').placeholder = c.has_app_secret==1 ? '•••••• (saved — leave blank to keep)' : '••••••';
  E('waWebhookUrl').value = c.webhook_url||''; E('waVerifyToken').value = c.webhook_verify_token||'(generated on first save)';
}
async function saveWa(){
  const body = { enabled:E('waActive').checked?1:0, provider:E('waProvider').value, from_display:E('waFrom').value,
    default_cc:E('waCC').value, phone_number_id:E('waPhoneId').value, business_account_id:E('waWaba').value,
    app_id:E('waAppId').value, template_name:E('waTplName').value, template_lang:E('waTplLang').value };
  if (E('waToken').value) body.api_token = E('waToken').value;
  if (E('waAppSecret').value) body.app_secret = E('waAppSecret').value;
  const r = await apiSend('whatsapp-settings.php', body);
  if (r.success){ toast('WhatsApp settings saved'); E('waToken').value=''; E('waAppSecret').value=''; loadWa(); } else toast(r.error||'Failed', false);
}
function copyField(id){
  const el = E(id); if (!el.value) return;
  navigator.clipboard?.writeText(el.value).then(()=>toast('Copied')).catch(()=>{ el.select(); document.execCommand('copy'); toast('Copied'); });
}
async function testWa(){
  const to = E('waTestTo').value.trim(); if(!to) return toast('Enter a test phone', false);
  const msg = E('waMsg'); msg.textContent='Sending…'; msg.className='text-xs text-gray-500';
  const r = await apiSend('whatsapp-settings.php?test=1', { to });
  if (r.success && r.data && r.data.wa_link){ window.open(r.data.wa_link,'_blank'); msg.textContent='✅ '+(r.message||''); msg.className='text-xs text-green-600'; return; }
  msg.textContent = r.success ? ('✅ '+(r.message||'sent')) : ('⚠️ '+(r.error||'failed'));
  msg.className = 'text-xs ' + (r.success ? 'text-green-600' : 'text-red-500');
}

/* ---- telephony settings ---- */
function tpHint(){
  const tc = E('tpProvider').value==='telecmi';
  E('tpSidL').firstChild.textContent = tc ? 'App ID' : 'Account SID';
  E('tpSub').closest('label').style.display = tc ? 'none' : '';  // subdomain is Exotel-only
}
async function loadTp(){
  const { data:c } = await apiGet('telephony-settings.php');
  if (!c || !c.id) return;
  E('tpProvider').value=c.provider||'exotel'; E('tpSid').value=c.account_sid||''; E('tpCaller').value=c.caller_id||'';
  E('tpSales').value=c.sales_number||''; E('tpKey').value=c.api_key||''; E('tpSub').value=c.subdomain||'api.exotel.com'; E('tpCC').value=c.default_cc||'91';
  E('tpTollFree').value=c.toll_free_number||''; E('tpWebrtcApp').value=c.webrtc_app_id||'';
  E('tpWebrtcDomain').value=c.webrtc_domain||''; E('tpFlowId').value=c.flow_id||'';
  E('tpActive').checked = c.enabled==1;
  E('tpToken').placeholder = c.has_token==1 ? '•••••• (saved — leave blank to keep)' : '••••••';
  tpHint();
}
async function testTp(){
  const msg = E('tpMsg'); msg.textContent = 'Checking credentials…'; msg.className = 'text-xs text-gray-500';
  const r = await apiSend('telephony-settings.php?test=1', {});
  msg.textContent = r.success ? ('✅ ' + (r.message||'ok')) : ('⚠️ ' + (r.error||'failed'));
  msg.className = 'text-xs ' + (r.success ? 'text-green-600' : 'text-red-500');
}
async function saveTp(){
  const body = { enabled:E('tpActive').checked?1:0, provider:E('tpProvider').value, account_sid:E('tpSid').value,
    caller_id:E('tpCaller').value, sales_number:E('tpSales').value, api_key:E('tpKey').value, subdomain:E('tpSub').value, default_cc:E('tpCC').value,
    toll_free_number:E('tpTollFree').value, webrtc_app_id:E('tpWebrtcApp').value, webrtc_domain:E('tpWebrtcDomain').value, flow_id:E('tpFlowId').value };
  if (E('tpToken').value) body.api_token = E('tpToken').value;
  const r = await apiSend('telephony-settings.php', body);
  if (r.success){ toast('Telephony settings saved'); E('tpToken').value=''; loadTp(); } else toast(r.error||'Failed', false);
}

/* ---- AI ---- */
function renderHealth(h){
  const el = E('aiHealth');
  if (!h || h.up === null) { el.innerHTML = '<span class="text-gray-400">box disabled</span>'; return; }
  if (h.up) {
    const m = h.metrics||{}; const req=(m.requests&&m.requests.in_flight!=null)?` · ${m.requests.in_flight} in-flight`:'';
    el.innerHTML = `<span class="text-green-600 font-semibold"><i class="fa-solid fa-circle-check"></i> AI box is UP</span><span class="text-gray-400">${req}${m.cpu!=null?' · CPU '+m.cpu+'%':''}</span>`;
  } else {
    el.innerHTML = `<span class="text-red-600 font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> AI box is DOWN</span> <span class="text-gray-400">${esc(h.error||'')}</span>`;
  }
}
async function loadAi(){
  const { data } = await apiGet('ai-settings.php'); const c = data.config||{};
  E('aiBoxEnabled').checked = c.box_enabled==1; E('aiBoxUrl').value = c.box_url||'';
  E('aiCloudEnabled').checked = c.cloud_enabled==1; E('aiCloudUrl').value = c.cloud_url||'';
  E('aiKey').placeholder = c.has_ai_api_key?'•••••• (saved)':'optional';
  E('aiRestartTok').placeholder = c.has_restart_token?'•••••• (saved)':'to bring box up';
  E('aiCloudKey').placeholder = c.has_cloud_api_key?'•••••• (saved)':'optional';
  renderHealth(data.health);
}
async function saveAi(){
  const b = { box_enabled:E('aiBoxEnabled').checked?1:0, box_url:E('aiBoxUrl').value,
    cloud_enabled:E('aiCloudEnabled').checked?1:0, cloud_url:E('aiCloudUrl').value };
  ['aiKey:ai_api_key','aiRestartTok:restart_token','aiCloudKey:cloud_api_key'].forEach(p=>{
    const [id,k]=p.split(':'); if(E(id).value) b[k]=E(id).value; });
  const r = await apiSend('ai-settings.php', b);
  if (r.success){ toast('AI settings saved'); ['aiKey','aiRestartTok','aiCloudKey'].forEach(i=>E(i).value=''); loadAi(); }
  else toast(r.error||'Failed', false);
}
async function checkAi(){
  E('aiHealth').innerHTML = '<span class="text-gray-400">checking…</span>';
  const r = await apiSend('ai-settings.php?health=1', {});
  renderHealth(r.success ? r.data : {up:false, error:r.error});
}
async function restartAi(){
  if(!confirm('Send a restart to the AI box?')) return;
  const r = await apiSend('ai-settings.php?restart=1', {});
  toast(r.success ? (r.message||'Restart requested') : (r.error||'Failed'), r.success);
  setTimeout(checkAi, 4000);
}
async function runAiTest(){
  const text = E('aiTestText').value.trim(); if(!text) return toast('Enter text', false);
  const out = E('aiTestOut'); out.classList.remove('hidden'); out.innerHTML = '<span class="text-gray-400">Hitting the AI box…</span>';
  const r = await apiSend('ai-settings.php?test=1', { text, action:E('aiTestAction').value });
  if (r.success) out.innerHTML = `<div class="text-[10px] text-gray-400 mb-1">via ${esc(r.data.source)}</div>${esc(r.data.response)}`;
  else out.innerHTML = `<span class="text-red-500">${esc(r.error||'failed')}</span>`;
}

/* ---- mailboxes to read ---- */
async function loadMboxes(){
  const { data } = await apiGet('mailbox.php', { boxes: 1 });
  document.getElementById('mboxRows').innerHTML = (data||[]).map(m=>`
    <tr><td class="font-medium">${esc(m.label||'—')}</td>
      <td class="text-gray-500">${esc(m.protocol)}://${esc(m.host)}:${m.port}</td>
      <td class="text-gray-500">${esc(m.username)}</td>
      <td>${m.is_active==1?'<span class="text-green-600 text-[11px]">● on</span>':'<span class="text-gray-400 text-[11px]">off</span>'}</td>
      <td class="rowact whitespace-nowrap">
        <button onclick="testMbox(${m.id})" class="text-blue-500 hover:text-blue-700 mr-1" title="Test connection (IMAP login)"><i class="fa-solid fa-plug-circle-check"></i></button>
        <button onclick='editMbox(${JSON.stringify(m)})' class="text-indigo-600 mr-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
        <button onclick="delMbox(${m.id})" class="text-red-400" title="Remove"><i class="fa-solid fa-trash"></i></button></td></tr>`).join('')
    || '<tr><td colspan="5" class="text-center text-gray-400 py-2 text-[11px]">No mailboxes yet</td></tr>';
}
function clearMbox(){ ['mbId','mbLabel','mbHost','mbPort','mbUser','mbPass','mbFolder'].forEach(i=>E(i).value=''); E(mbProto).value='imap'; E('mbEnc').value='ssl'; }
function editMbox(m){ E('mbId').value=m.id; E('mbLabel').value=m.label||''; E('mbProto').value=m.protocol; E('mbHost').value=m.host||''; E('mbPort').value=m.port||993; E('mbEnc').value=m.encryption; E('mbUser').value=m.username||''; E('mbFolder').value=m.folder||'INBOX'; E('mbPass').value=''; E('mbPass').placeholder=m.has_password?'•••••• (saved)':'••••••'; }
async function saveMbox(){
  const b = { id:E('mbId').value, label:E('mbLabel').value, protocol:E('mbProto').value, host:E('mbHost').value,
    port:E('mbPort').value||993, encryption:E('mbEnc').value, username:E('mbUser').value, folder:E('mbFolder').value||'INBOX', is_active:1 };
  if (E('mbPass').value) b.password = E('mbPass').value;
  if (!b.host || !b.username) return toast('Host and username required', false);
  const r = await apiSend('mailbox.php?save', b);
  if (r.success){ toast('Mailbox saved'); clearMbox(); loadMboxes(); } else toast(r.error||'Failed', false);
}
async function delMbox(id){ if(!confirm('Remove this mailbox?')) return; await apiSend('mailbox.php?del&id='+id, {}); loadMboxes(); }
async function testMbox(id){ toast('Testing IMAP login…'); const r = await apiSend('mailbox.php?test&id='+id, {}); toast(r.success ? (r.message||'Connected') : (r.error||'Connection failed'), r.success); }

/* ---- how-to help for third-party keys ---- */
const HELP = {
  email: { title: 'How to get your email (SMTP) credentials',
    intro: 'Outgoing notifications send via SMTP. Pick your provider below.',
    sections: [
      { h: 'Microsoft 365 / Outlook', steps: [
        'Host <b>smtp.office365.com</b>, Port <b>587</b>, Encryption <b>STARTTLS</b>.',
        'Username = the full email (<i>user@yourdomain.com</i>); Password = the mailbox password.',
        'If the account uses MFA, create an <b>App Password</b>: profile → <b>Security info</b> → Add sign-in method → App password.',
        'An admin may need to enable <b>Authenticated SMTP</b>: Microsoft 365 admin center → Users → the user → Mail → Manage email apps → tick <b>Authenticated SMTP</b>.'],
        link: { label: 'Microsoft 365 admin center', url: 'https://admin.microsoft.com' } },
      { h: 'Gmail / Google Workspace', steps: [
        'Host <b>smtp.gmail.com</b>, Port <b>587</b>, Encryption <b>STARTTLS</b>.',
        'Turn on <b>2-Step Verification</b> on the Google account.',
        'Create an <b>App Password</b> (16 characters) and use it as the password.'],
        link: { label: 'Google App Passwords', url: 'https://myaccount.google.com/apppasswords' } }],
    fields: 'Fill: From name/email, SMTP host, Port, Encryption, Username, Password — then Send test.' },

  whatsapp: { title: 'How to get WhatsApp Business API keys (Meta Cloud API)',
    steps: [
      'Go to <b>developers.facebook.com</b> and log in with your Meta/Facebook account.',
      'Create a <b>Meta Business</b> account if you don\'t have one (business.facebook.com).',
      'In the developer console: <b>My Apps → Create App → Business</b>, then add the <b>WhatsApp</b> product.',
      'Open <b>WhatsApp → API Setup</b> — copy the <b>Phone number ID</b> and the <b>WhatsApp Business Account (WABA) ID</b>.',
      'Add &amp; verify your business phone number (or start with the provided test number).',
      'For a <b>permanent token</b>: Business Settings → <b>System Users</b> → create a system user → assign your app → <b>Generate token</b> with the <b>whatsapp_business_messaging</b> permission.',
      'Paste the Phone number ID, WABA ID and token here, tick <b>Enabled</b>, and Send test.'],
    link: { label: 'Meta for Developers', url: 'https://developers.facebook.com/apps' },
    fields: 'Fill: Phone number ID, WABA ID, API token.' },

  telephony: { title: 'How to get click-to-call credentials',
    sections: [
      { h: 'Exotel (pay-per-call)', steps: [
        'Sign up at <b>exotel.com</b>, then log in to <b>my.exotel.com</b>.',
        'Go to <b>Settings → API settings</b>.',
        'Copy your <b>API Key</b>, <b>API Token</b> and <b>Account SID</b> (subdomain is usually <i>api.exotel.com</i>).',
        'Buy/assign an <b>ExoPhone</b> (virtual number) — that becomes your <b>Caller ID</b>.'],
        link: { label: 'Exotel dashboard', url: 'https://my.exotel.com' } },
      { h: 'TeleCMI (subscription)', steps: [
        'Sign up at <b>telecmi.com</b>.',
        'In the developer / PIOPIY section, get your <b>App ID</b> and <b>Secret</b>.',
        'Assign a <b>DID / caller number</b> as your Caller ID.'],
        link: { label: 'TeleCMI', url: 'https://telecmi.com' } }],
    fields: 'Fill: Provider, Account SID / App ID, Caller ID, Sales number, API key, API token.' },

  mailbox: { title: 'How to get IMAP details to read a mailbox',
    sections: [
      { h: 'Microsoft 365 / Outlook', steps: [
        'Host <b>outlook.office365.com</b>, Port <b>993</b>, Encryption <b>SSL</b>.',
        'Enable IMAP for the mailbox (admin center → Users → Mail → Manage email apps → IMAP).',
        'Username = full email; Password = mailbox password, or an <b>App Password</b> if MFA is on.'] },
      { h: 'Gmail / Google Workspace', steps: [
        'Host <b>imap.gmail.com</b>, Port <b>993</b>, Encryption <b>SSL</b>.',
        'Gmail → Settings → <b>Forwarding and POP/IMAP</b> → Enable IMAP.',
        'Use an <b>App Password</b> (with 2FA on) as the password.'],
        link: { label: 'Google App Passwords', url: 'https://myaccount.google.com/apppasswords' } }],
    fields: 'Fill: Protocol (IMAP), Host, Port, Encryption, Username, Password, Folder (INBOX).' },

  ai: { title: 'AI box & keys',
    intro: 'The AI box is your <b>Superchat</b> on-prem engine. For the box address, API keys and restart token, contact Superchat.',
    steps: [
      'Enter the <b>AI box URL/IP</b> your Superchat deployment runs on (e.g. <i>http://192.168.0.100:8000</i>).',
      'Get the <b>AI API key</b> and <b>Restart token</b> from Superchat and paste them here.',
      '<b>Cloud fallback</b> (optional): an external chat endpoint used only when the box is down.'],
    link: { label: 'For keys, contact Superchat — superchat.in', url: 'https://www.superchat.in' },
    fields: 'Fill: AI box URL, AI key, Restart token, Cloud endpoint (optional).' }
};

function showHelp(key){
  const h = HELP[key]; if (!h) return;
  const ol = steps => `<ol class="list-decimal ml-4 space-y-1 text-gray-700">${steps.map(s=>`<li>${s}</li>`).join('')}</ol>`;
  const lnk = l => l ? `<a href="${l.url}" target="_blank" rel="noopener" class="inline-block mt-1 text-indigo-600 hover:underline text-[11px]"><i class="fa-solid fa-arrow-up-right-from-square"></i> ${l.label}</a>` : '';
  let body = h.intro ? `<p class="text-gray-500 mb-2">${h.intro}</p>` : '';
  if (h.steps) body += ol(h.steps) + lnk(h.link);
  if (h.sections) body += h.sections.map(s=>`<div class="mb-3"><div class="font-semibold text-gray-800 mb-1"><i class="fa-solid fa-circle-dot text-indigo-400 text-[9px]"></i> ${s.h}</div>${ol(s.steps)}${lnk(s.link)}</div>`).join('');
  if (h.fields) body += `<div class="mt-3 pt-2 border-t text-[11px] text-gray-500"><i class="fa-solid fa-pen-to-square"></i> ${h.fields}</div>`;
  openModal(`<div class="text-xs space-y-1 leading-relaxed">${body}</div>`, h.title);
}

loadFields();
makeQR();
loadEmail();
loadWa();
loadTp();
loadAi();
loadMboxes();

/* ===== Auto-email alerts ===== */
async function loadAlerts(){
  try {
    const { data } = await apiGet('alert-settings.php', {});
    const s = data.settings;
    E('alEnabled').checked = !!+s.alerts_enabled; E('alCcMgr').checked = !!+s.cc_manager;
    E('alTs').checked = !!+s.timesheet_alert; E('alSla').checked = !!+s.sla_alert;
    E('alTgt').checked = !!+s.target_alert; E('alTh').value = s.target_threshold_pct;
    E('alSmtp').innerHTML = data.smtp_ready
      ? '<span class="text-green-600">● SMTP configured</span>'
      : '<span class="text-red-600">● SMTP not configured — set Email above first</span>';
    E('alLog').innerHTML = (data.log||[]).map(l=>`
      <tr><td class="text-[10px] text-gray-400 whitespace-nowrap">${esc(String(l.created_at||'').slice(5,16))}</td>
      <td>${badge(l.alert_type, l.alert_type==='sla_breach'||l.alert_type==='sla_escalated_to'?'red':l.alert_type==='timesheet_missing'?'yellow':'blue')}</td>
      <td class="text-[11px]">${esc(l.full_name||('#'+l.employee_id))}</td>
      <td class="text-[10px]">${esc(l.sent_to||'—')}</td>
      <td class="text-[10px]">${esc(l.cc_manager_to||'—')}</td>
      <td>${badge(l.status, l.status==='sent'?'green':l.status==='failed'?'red':'gray')}</td></tr>`).join('')
      || '<tr><td colspan="6" class="text-gray-400 text-center py-2 text-xs">No alert emails yet</td></tr>';
  } catch(e){ document.getElementById('alertCard').style.display='none'; }
}
async function saveAlerts(){
  const r = await apiSend('alert-settings.php', {
    alerts_enabled: E('alEnabled').checked?1:0, cc_manager: E('alCcMgr').checked?1:0,
    timesheet_alert: E('alTs').checked?1:0, sla_alert: E('alSla').checked?1:0,
    target_alert: E('alTgt').checked?1:0, target_threshold_pct: +E('alTh').value||70 });
  toast(r.success ? 'Alert settings saved' : (r.error||'Failed'), !!r.success);
  loadAlerts();
}
async function runAlertsNow(){
  const r = await apiSend('alert-settings.php?run_now=1', {});
  if (r.success){ const s=r.data; toast(`Sweep done — ${s.timesheet_alerts} timesheet, ${s.target_alerts} target alert(s), ${s.skipped} throttled`); loadAlerts(); }
  else toast(r.error||'Failed', false);
}
loadAlerts();
</script>
</html>
