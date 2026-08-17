<?php
// Single sign-on page for the Sales CRM — styled to PayG CRM UI standards.
require_once __DIR__ . '/config/app.php';
payg_start_session();
$csrfToken = payg_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI One Sales Cloud — Sign in</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" type="image/svg+xml" href="public/images/PayG-Dark.svg">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .user-type-card { border: 2px solid transparent; transition: all .2s ease; cursor: pointer; }
    .user-type-card:hover { border-color: #667eea; transform: translateY(-2px); box-shadow: 0 10px 25px rgba(102,126,234,.15); }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 min-h-screen">
  <div class="min-h-screen flex items-center justify-center px-4 sm:px-6 lg:px-8 py-8">
    <div class="w-full max-w-md">

      <!-- Logo + title -->
      <div class="text-center mb-5">
        <div class="flex items-center justify-center gap-3 mb-3 whitespace-nowrap">
          <img src="public/images/PayG-Dark.svg" alt="Logo" class="h-9 w-auto object-contain shrink-0">
          <span class="text-2xl font-bold text-gray-900 tracking-tight whitespace-nowrap">AI One</span>
        </div>
        <h1 class="text-xl font-bold text-gray-800">Welcome to AI One Sales Cloud</h1>
        <p class="text-xs text-gray-500 mt-1">Sign in to continue</p>
      </div>

      <!-- Login card -->
      <div class="bg-white rounded-xl shadow-2xl p-6">
        <form id="loginForm" onsubmit="return doLogin(event)" class="space-y-4">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label id="idLabel" class="block text-xs font-semibold text-gray-600">Email</label>
              <button type="button" id="idSwitch" onclick="switchIdMode()"
                      class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">Use phone instead</button>
            </div>
            <div class="relative">
              <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><i id="idIcon" class="fas fa-envelope text-sm"></i></span>
              <input id="username" autocomplete="username" required placeholder="you@company.com" inputmode="email"
                     class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all duration-200">
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-semibold text-gray-600">Password</label>
              <button type="button" onclick="openForgot()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">Forgot password?</button>
            </div>
            <div class="relative">
              <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><i class="fas fa-lock text-sm"></i></span>
              <input id="password" type="password" autocomplete="current-password" required placeholder="Enter password"
                     class="w-full pl-9 pr-9 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all duration-200">
              <button type="button" onclick="togglePw()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-indigo-600 transition-colors">
                <i id="pwEye" class="fas fa-eye text-sm"></i></button>
            </div>
          </div>
          <div id="err" class="hidden text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2"></div>
          <button class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold py-2.5 px-4 text-sm rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
            <i class="fas fa-right-to-bracket mr-1"></i> Sign in</button>
        </form>
      </div>

    </div>
  </div>

  <!-- Forgot password: OTP to the email / mobile already on the user's employee record -->
  <div id="forgotModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center px-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h2 class="text-base font-bold text-gray-800">Reset your password</h2>
          <p id="fpStepHint" class="text-xs text-gray-500 mt-0.5">Verify with an OTP sent to your registered contact.</p>
        </div>
        <button type="button" onclick="closeForgot()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-xmark"></i></button>
      </div>

      <!-- step 1 -->
      <form id="fpStep1" onsubmit="return fpRequest(event)" class="space-y-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Username</label>
          <input id="fpUsername" required placeholder="Your username"
                 class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Send OTP to</label>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" id="fpChanEmail" onclick="fpSetChannel('email')"
                    class="border-2 border-indigo-600 bg-indigo-50 text-indigo-700 rounded-lg py-2 text-xs font-semibold">
              <i class="fas fa-envelope mr-1"></i> Email</button>
            <button type="button" id="fpChanMobile" onclick="fpSetChannel('mobile')"
                    class="border-2 border-gray-200 text-gray-600 rounded-lg py-2 text-xs font-semibold">
              <i class="fas fa-mobile-screen mr-1"></i> Mobile</button>
          </div>
        </div>
        <div>
          <label id="fpDestLabel" class="block text-xs font-semibold text-gray-600 mb-1">Registered email address</label>
          <input id="fpDest" required placeholder="you@company.com"
                 class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <p class="text-[11px] text-gray-400 mt-1">Must match what's on your employee record. Ask your admin if you're unsure.</p>
        </div>
        <button id="fpSendBtn" class="w-full bg-indigo-600 text-white font-bold py-2.5 text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-60">
          Send OTP</button>
      </form>

      <!-- step 2 -->
      <form id="fpStep2" onsubmit="return fpVerify(event)" class="space-y-3 hidden">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Enter the OTP</label>
          <input id="fpOtp" required inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code"
                 class="w-full px-3 py-2 text-sm tracking-[0.4em] text-center border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button id="fpVerifyBtn" class="w-full bg-indigo-600 text-white font-bold py-2.5 text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-60">
          Verify OTP</button>
        <button type="button" onclick="fpBackToStep1()" class="w-full text-xs text-gray-500 hover:text-gray-700">Use a different account or channel</button>
      </form>

      <!-- step 3 -->
      <form id="fpStep3" onsubmit="return fpReset(event)" class="space-y-3 hidden">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">New password</label>
          <input id="fpPw1" type="password" required autocomplete="new-password" placeholder="At least 8 characters"
                 class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <p class="text-[11px] text-gray-400 mt-1">Minimum 8 characters, with at least one letter and one number.</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Confirm new password</label>
          <input id="fpPw2" type="password" required autocomplete="new-password" placeholder="Re-enter password"
                 class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button id="fpResetBtn" class="w-full bg-indigo-600 text-white font-bold py-2.5 text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-60">
          Set new password</button>
      </form>

      <div id="fpMsg" class="hidden text-xs rounded-lg px-3 py-2 mt-3"></div>
    </div>
  </div>

<script>
function csrfToken() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

async function apiPost(url, body) {
  const r = await fetch(url, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    credentials: 'same-origin', body: JSON.stringify(body || {}) });
  return r.json();
}
function post(action, body) { return apiPost('api/sales/auth.php?action=' + action, body); }
/* Email (default) ⇄ phone login switch. Purely presentational — the server accepts
   email, 10-digit phone, or a plain username in the same field either way. */
let idMode = 'email';
function switchIdMode() {
  idMode = idMode === 'email' ? 'phone' : 'email';
  const em = idMode === 'email';
  document.getElementById('idLabel').textContent = em ? 'Email' : 'Phone number';
  document.getElementById('idSwitch').textContent = em ? 'Use phone instead' : 'Use email instead';
  document.getElementById('idIcon').className = 'fas ' + (em ? 'fa-envelope' : 'fa-mobile-screen') + ' text-sm';
  const u = document.getElementById('username');
  u.placeholder = em ? 'you@company.com' : '98765 43210';
  u.inputMode = em ? 'email' : 'tel';
  u.value = ''; u.focus();
}
function togglePw() {
  const p = document.getElementById('password'), e = document.getElementById('pwEye');
  const show = p.type === 'password'; p.type = show ? 'text' : 'password';
  e.className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye') + ' text-sm';
}
async function doLogin(e) {
  if (e && e.preventDefault) e.preventDefault();
  const err = document.getElementById('err'); err.classList.add('hidden');
  // Re-sync the CSRF token first: a login page left open past the session's
  // lifetime holds a stale token and would otherwise fail with "CSRF validation
  // failed" — this makes the age of the page irrelevant.
  try {
    const t = await (await fetch('api/sales/auth.php?action=csrf', { credentials: 'same-origin' })).json();
    if (t.success && t.data && t.data.csrf) document.querySelector('meta[name="csrf-token"]').content = t.data.csrf;
  } catch (_) {}
  const res = await post('login', { username: username.value, password: password.value });
  if (res.success) { window.location.href = res.data.redirect; }
  else {
    // reset the styling — a successful password reset repaints this same box green
    err.className = 'text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2';
    err.textContent = res.error || 'Login failed';
    err.classList.remove('hidden');
  }
  return false;
}

/* ---------- forgot password ---------- */
let fpChannel = 'email';

function fpMsg(text, kind) {
  const el = document.getElementById('fpMsg');
  el.textContent = text;
  el.className = 'text-xs rounded-lg px-3 py-2 mt-3 ' +
    (kind === 'error' ? 'text-red-600 bg-red-50' : kind === 'ok' ? 'text-green-700 bg-green-50' : 'text-gray-600 bg-gray-50');
  el.classList.remove('hidden');
}
function fpShowStep(n) {
  [1, 2, 3].forEach(i => document.getElementById('fpStep' + i).classList.toggle('hidden', i !== n));
  document.getElementById('fpStepHint').textContent = n === 1
    ? 'Verify with an OTP sent to your registered contact.'
    : n === 2 ? 'Enter the OTP we just sent you.' : 'Choose a new password for your account.';
}
function openForgot() {
  document.getElementById('forgotModal').classList.remove('hidden');
  document.getElementById('fpUsername').value = document.getElementById('username').value.trim();
  document.getElementById('fpMsg').classList.add('hidden');
  fpShowStep(1);
  document.getElementById('fpUsername').focus();
}
function closeForgot() {
  document.getElementById('forgotModal').classList.add('hidden');
  ['fpDest', 'fpOtp', 'fpPw1', 'fpPw2'].forEach(id => document.getElementById(id).value = '');
}
function fpBackToStep1() { document.getElementById('fpMsg').classList.add('hidden'); fpShowStep(1); }
function fpSetChannel(ch) {
  fpChannel = ch;
  const on = 'border-2 border-indigo-600 bg-indigo-50 text-indigo-700 rounded-lg py-2 text-xs font-semibold';
  const off = 'border-2 border-gray-200 text-gray-600 rounded-lg py-2 text-xs font-semibold';
  document.getElementById('fpChanEmail').className = ch === 'email' ? on : off;
  document.getElementById('fpChanMobile').className = ch === 'mobile' ? on : off;
  const dest = document.getElementById('fpDest');
  document.getElementById('fpDestLabel').textContent = ch === 'email' ? 'Registered email address' : 'Registered mobile number';
  dest.placeholder = ch === 'email' ? 'you@company.com' : '10-digit mobile number';
  dest.type = ch === 'email' ? 'email' : 'tel';
  dest.value = '';
}

async function fpRequest(e) {
  e.preventDefault();
  const btn = document.getElementById('fpSendBtn');
  btn.disabled = true; btn.textContent = 'Sending…';
  try {
    const res = await apiPost('api/sales/forgot-password.php?action=request', {
      username: document.getElementById('fpUsername').value.trim(),
      channel: fpChannel,
      destination: document.getElementById('fpDest').value.trim(),
    });
    if (res.success) { fpShowStep(2); fpMsg(res.data.message, 'info'); document.getElementById('fpOtp').focus(); }
    else fpMsg(res.error || 'Could not send the OTP', 'error');
  } finally { btn.disabled = false; btn.textContent = 'Send OTP'; }
  return false;
}

async function fpVerify(e) {
  e.preventDefault();
  const btn = document.getElementById('fpVerifyBtn');
  btn.disabled = true; btn.textContent = 'Verifying…';
  try {
    const res = await apiPost('api/sales/forgot-password.php?action=verify', { otp: document.getElementById('fpOtp').value.trim() });
    if (res.success) { fpShowStep(3); fpMsg('OTP verified. Set your new password.', 'ok'); document.getElementById('fpPw1').focus(); }
    else fpMsg(res.error || 'Verification failed', 'error');
  } finally { btn.disabled = false; btn.textContent = 'Verify OTP'; }
  return false;
}

async function fpReset(e) {
  e.preventDefault();
  const pw1 = document.getElementById('fpPw1').value, pw2 = document.getElementById('fpPw2').value;
  if (pw1 !== pw2) { fpMsg('The two passwords do not match', 'error'); return false; }
  const btn = document.getElementById('fpResetBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const res = await apiPost('api/sales/forgot-password.php?action=reset', { password: pw1 });
    if (res.success) {
      // the reset rotated the CSRF token server-side — keep the page's copy usable for the login below
      if (res.data.csrf) document.querySelector('meta[name="csrf-token"]').content = res.data.csrf;
      closeForgot();
      const err = document.getElementById('err');
      err.textContent = 'Password updated. Please sign in with your new password.';
      err.className = 'text-xs text-green-700 bg-green-50 rounded-lg px-3 py-2';
      err.classList.remove('hidden');
      document.getElementById('password').value = '';
      document.getElementById('password').focus();
    } else fpMsg(res.error || 'Could not update the password', 'error');
  } finally { btn.disabled = false; btn.textContent = 'Set new password'; }
  return false;
}
</script>
</body>
</html>
