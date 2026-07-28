<?php
/**
 * PayG chatbot widget (ticket-only port of payg1/chat.php).
 *
 * This is a pure front-end widget: no DB access, no PHP session needed here.
 * All server interaction happens via fetch() calls to:
 *   - api/sales/chatbot-otp.php    (mobile/email OTP send+verify)
 *   - api/sales/chatbot-ticket.php (creates Lead + crm_tickets row)
 *
 * Scope decision (see CLAUDE.md / task brief): this port is "ticket-only".
 * There is no live-chat backend (`chat_sessions`/`chat_messages` tables here
 * are unrelated to this feature), no `clients` table, and no agent hand-off.
 * After a ticket is created we show a confirmation with the ticket_ref and
 * offer "Start another request" / "End chat" only.
 */
?>
<!--
  =============================================
  Created by Superchat | https://superchat.in
  =============================================
-->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayG - Chat with Us</title>
    <script>
        // Keeps #chatCardEmbed sized to the real visible viewport on mobile,
        // so the on-screen keyboard doesn't push the input bar off-screen.
        // On desktop (no virtual keyboard resizing the viewport) this is a no-op:
        // --vh stays equal to 1% of window.innerHeight, same as plain 100vh.
        (function () {
            var lastH = 0;
            var rafId = null;
            function setAppHeight() {
                var h = (window.visualViewport ? window.visualViewport.height : window.innerHeight);
                // Skip no-op writes: without this, the tiny height jitter from an
                // auto-growing textarea (resizes on every keystroke) keeps re-firing
                // visualViewport 'resize'/'scroll' here AND in the parent widget's own
                // listener on the same viewport, and each write re-triggers the other
                // — a feedback loop that reads as flicker and speeds up while typing.
                if (Math.abs(h - lastH) < 2) return;
                lastH = h;
                document.documentElement.style.setProperty('--vh', (h * 0.01) + 'px');
            }
            function scheduleSetAppHeight() {
                if (rafId) return;
                rafId = requestAnimationFrame(function () {
                    rafId = null;
                    setAppHeight();
                });
            }
            setAppHeight();
            window.addEventListener('resize', scheduleSetAppHeight);
            window.addEventListener('orientationchange', scheduleSetAppHeight);
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', scheduleSetAppHeight);
                window.visualViewport.addEventListener('scroll', scheduleSetAppHeight);
            }

            // When a chat input/textarea gets focus (keyboard opens), scroll it
            // into view and keep the latest message visible - normal chat UX.
            document.addEventListener('focusin', function (e) {
                var tag = e.target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA') {
                    setTimeout(function () {
                        e.target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        var messages = document.getElementById('chatMessages');
                        if (messages) messages.scrollTop = messages.scrollHeight;
                    }, 300);
                }
            });
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Google Analytics 4 -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-0LQNKT94DM"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', 'G-0LQNKT94DM');
    </script>
    <script>(function (w, d, s, l, i) {
            w[l] = w[l] || []; w[l].push({
                'gtm.start':
                    new Date().getTime(), event: 'gtm.js'
            }); var f = d.getElementsByTagName(s)[0],
                j = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : ''; j.async = true; j.src =
                    'https://www.googletagmanager.com/gtm.js?id=' + i + dl; f.parentNode.insertBefore(j, f);
        })(window, document, 'script', 'dataLayer', 'GTM-KNPRBVB');</script>

    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }

        .demo-page {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .demo-page h1 {
            color: #1f2937;
            margin-bottom: 8px;
        }

        .demo-page p {
            color: #6b7280;
            margin-bottom: 24px;
        }

        .demo-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .chat-bubble {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        html,
        body {
            height: 100%;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        #chatCardEmbed {
            height: 100vh;
            height: calc(var(--vh, 1vh) * 100);
            width: 100vw;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        #chatMessages {
            flex: 1 1 auto;
            overflow-y: auto;
            min-height: 0;
            padding: 0.75rem;
            background: #f8fafc;
        }

        #chatInput {
            flex-shrink: 0;
            border-top: 1px solid #e5e7eb;
            background: #fff;
            padding: 0.75rem;
        }

        .file-upload-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f3f4f6;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-upload-label:hover {
            background: #e5e7eb;
            border-color: #9ca3af;
        }

        .file-name {
            font-size: 14px;
            color: #4b5563;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gmail-upload-preview {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #ffffff;
            padding: 8px 10px 10px;
        }

        .gmail-upload-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .gmail-upload-main {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .gmail-upload-icon {
            color: #374151;
            font-size: 15px;
            flex-shrink: 0;
        }

        .gmail-upload-name {
            color: #202124;
            line-height: 1.35;
            text-decoration: none;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 260px;
            font-weight: 700;
        }

        .gmail-upload-name.uploaded {
            color: #1a73e8;
        }

        .gmail-upload-size {
            color: #4b5563;
            font-size: 15px;
            flex-shrink: 0;
        }

        .gmail-upload-remove {
            border: none;
            background: transparent;
            color: #6b7280;
            padding: 0 4px;
            line-height: 1;
            cursor: pointer;
            font-size: 16px;
            flex-shrink: 0;
        }

        .gmail-upload-remove:hover {
            color: #111827;
        }

        .gmail-upload-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .gmail-upload-progress-track {
            width: 88px;
            height: 2px;
            background: #dadce0;
            border-radius: 0;
            overflow: hidden;
        }

        .gmail-upload-progress-bar {
            height: 100%;
            width: 0;
            background: #1a73e8;
            border-radius: 0;
            transition: width 0.15s linear;
        }

        .gmail-upload-status {
            margin-top: 6px;
            font-size: 12px;
            color: #1f2937;
        }

        .gmail-upload-status.error {
            color: #dc2626;
        }

        .gmail-upload-status.success {
            color: #16a34a;
        }

        .reference-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1rem;
            margin: 0.5rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Auto-expanding textarea, capped at 3 lines then scrolls. */
        .chat-autogrow {
            resize: none;
            overflow-y: auto;
            min-height: 40px;
            max-height: 92px;
            /* ~3 lines at 1.45 line-height */
            line-height: 1.45;
            field-sizing: content;
            /* Chrome 123+ native auto-size */
        }
    </style>
</head>

<body>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KNPRBVB" height="0" width="0"
            style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <!-- Embedded Chat Card Only -->
    <div id="chatCardEmbed">
        <!-- Chat Header -->
        <div
            class="bg-gradient-to-r from-blue-600 to-purple-600 text-white px-3 py-2.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-robot text-xs"></i>
                </div>
                <h3 class="font-semibold text-sm leading-none">PayG Assistant</h3>
            </div>

            <div class="flex items-center gap-3">
                <div id="clientName" class="text-xs text-white/90"></div>
                <p class="text-xs text-white/80 flex items-center gap-1 leading-none">
                    <span class="w-1.5 h-1.5 bg-green-400 rounded-full"></span>
                    Online
                </p>
            </div>
        </div>

        <!-- Chat Messages Container -->
        <div id="chatMessages" class="flex-1 overflow-y-auto p-4 bg-gray-50"></div>

        <!-- Chat Input Area (bot Q&A / quick replies) -->
        <div id="chatInput" class="p-4 bg-white border-t border-gray-200"></div>

        <!-- Live Chat Input Area (shown once a Service/Transaction chat connects to an agent) -->
        <div id="liveChatInputContainer" class="hidden p-3 bg-white border-t border-gray-200 gap-2 items-end">
            <textarea id="liveChatInput" rows="1"
                class="chat-autogrow flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="Type your message…"></textarea>
            <button onclick="sendLiveChatMessage()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex-shrink-0">
                <i class="fas fa-paper-plane"></i>
            </button>
            <button onclick="closeLiveChatAsClient()"
                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex-shrink-0">
                <i class="fas fa-times-circle"></i>
            </button>
        </div>
        <!-- Mirrors the server's chat_sweep_inactive_active() auto-close (5 min of silence
             after the agent's last message with no reply) — see api/sales/_automation_engine.php.
             Hidden whenever it doesn't apply: chat not active yet, or the visitor already
             replied (their own turn no longer counts against the timeout). -->
        <div id="liveChatCountdown" class="hidden px-3 pb-2 bg-white text-center text-xs text-gray-500"></div>
    </div>

    <script>
        let chatState = {
            stage: 'welcome',
            formData: {
                name: '',
                mobile: '',
                email: '',
                businessName: '',
                requestType: '',
                issueDescription: '',
                attachment: null,
                attachmentUrl: ''
            },
            otpData: {
                mobileOTP: '',
                emailOTP: '',
                mobileVerified: false,
                emailVerified: false,
                mobileOtpId: null,
                mobileSentAt: null,
                mobileTimerHandle: null,
                emailOtpId: null,
                emailSentAt: null,
                emailTimerHandle: null
            },
            lastTicketRef: null,
            requestSelectionLocked: false,
            awaitingNextRequestDecision: false,
            attachmentUploadInProgress: false,
            attachmentUploadProgress: 0,
            attachmentUploadDisplayProgress: 0,
            attachmentUploadTargetProgress: 0,
            attachmentUploaded: false,
            attachmentUploadError: '',
            attachmentUploadRequest: null,
            attachmentUploadTimer: null
        };

        // Live-chat session (Service/Transaction requests, only opened when a Service exec
        // is online — see api/sales/chatbot-ticket.php). Polls api/sales/chat.php's public
        // v_poll/v_post/v_close actions using the token issued at ticket creation time.
        let liveChat = {
            token: null,
            sessionId: null,
            lastId: 0,
            pollHandle: null,
            active: false,
            connectedMsgShown: false,
            // Auto-close countdown: mirrors chat_sweep_inactive_active() server-side (5 min of
            // silence after the agent's — or the seed 'system' message's — last word, with no
            // reply from the visitor). Set to a deadline timestamp when the last message came
            // from 'agent'/'system'; cleared the moment the visitor replies (see
            // trackAutoCloseTimer()) or the chat leaves 'active' status.
            autoCloseDeadline: null,
            countdownTick: null
        };

        // ── Session persistence helpers ──
        // Keeps an in-progress form (name/mobile/email/business, verified flags) — and an
        // in-progress live chat, if one was opened — alive across a reload of this iframe.
        function saveChatSession() {
            try {
                const data = {
                    formData: {
                        name: chatState.formData.name,
                        mobile: chatState.formData.mobile,
                        email: chatState.formData.email,
                        businessName: chatState.formData.businessName,
                        requestType: chatState.formData.requestType,
                        issueDescription: chatState.formData.issueDescription
                    },
                    otpData: {
                        mobileVerified: chatState.otpData.mobileVerified,
                        emailVerified: chatState.otpData.emailVerified
                    },
                    liveChat: liveChat.active ? { token: liveChat.token, sessionId: liveChat.sessionId } : null,
                    lastTicketRef: chatState.lastTicketRef,
                    stage: chatState.stage,
                    savedAt: Date.now()
                };
                sessionStorage.setItem('payg_chat_session', JSON.stringify(data));
            } catch (e) { /* ignore */ }
        }

        function loadChatSession() {
            try {
                const raw = sessionStorage.getItem('payg_chat_session');
                if (!raw) return null;
                const data = JSON.parse(raw);
                // Expire after 30 minutes of inactivity
                if (Date.now() - (data.savedAt || 0) > 30 * 60 * 1000) {
                    sessionStorage.removeItem('payg_chat_session');
                    return null;
                }
                return data;
            } catch (e) { return null; }
        }

        function clearChatSession() {
            try { sessionStorage.removeItem('payg_chat_session'); } catch (e) { /* ignore */ }
        }

        function isFullyCompleted(saved) {
            return (
                saved?.formData?.name &&
                saved?.formData?.mobile &&
                saved?.otpData?.mobileVerified &&
                saved?.formData?.email &&
                saved?.otpData?.emailVerified &&
                saved?.formData?.businessName
            );
        }

        function restoreFromSession(saved) {
            // If NOT fully completed -> don't restore
            if (!isFullyCompleted(saved)) {
                clearChatSession();
                return false;
            }
            chatState.formData.name = saved.formData.name || '';
            chatState.formData.mobile = saved.formData.mobile || '';
            chatState.formData.email = saved.formData.email || '';
            chatState.formData.businessName = saved.formData.businessName || '';
            chatState.formData.requestType = saved.formData.requestType || '';
            chatState.formData.issueDescription = saved.formData.issueDescription || '';

            chatState.otpData.mobileVerified = !!saved.otpData.mobileVerified;
            chatState.otpData.emailVerified = !!saved.otpData.emailVerified;
            chatState.stage = saved.stage || 'welcome';
            chatState.lastTicketRef = saved.lastTicketRef || null;

            updateClientNameInHeader(chatState.formData.name.split(' ')[0]);

            // Resume a live chat that was still open when the page reloaded, instead of
            // restarting the Q&A flow from scratch.
            if (saved.liveChat && saved.liveChat.token) {
                addBotMessage(`👋 Welcome back ${chatState.formData.name}! Reconnecting you to your chat…`);
                resumeLiveChat(saved.liveChat.token, saved.liveChat.sessionId);
                return true;
            }

            addBotMessage(`👋 Welcome back ${chatState.formData.name}! How can we assist you today?`);
            setTimeout(() => askAssistType(), 800);

            return true;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const liveInput = document.getElementById('liveChatInput');
            if (liveInput) enableChatAutoGrow(liveInput, sendLiveChatMessage);

            const savedSession = loadChatSession();
            if (savedSession && restoreFromSession(savedSession)) {
                return;
            }
            startChat();
        });

        function updateClientNameInHeader(name) {
            const el = document.getElementById('clientName');
            if (!el) return;
            el.textContent = name || '';
        }

        function hasCompleteClient() {
            return (
                chatState.formData.name &&
                chatState.formData.mobile &&
                chatState.formData.email &&
                chatState.formData.businessName &&
                chatState.otpData.mobileVerified
                // chatState.otpData.emailVerified
            );
        }

        function startChat() {
            if (window.gtag) gtag('event', 'chatbot_opened', { event_category: 'chatbot' });
            addBotMessage("👋 Welcome to PayG Digitals! We are here to support you with all your business payment solutions and services. May I know if you are interested in learning more about PayG's business services?");
            setTimeout(() => {
                askInterest();
            }, 800);
        }

        function askAssistType() {
            chatState.stage = 'assist_type';
            showRequestTypeOptions();
        }

        function clearChat() {
            document.getElementById('chatInput').innerHTML = '';
            chatState.stage = 'welcome';
        }

        function askInterest() {
            showQuickReplies([{
                text: 'Yes, I am interested',
                action: 'interested'
            },
            {
                text: 'Just Exploring',
                action: 'browsing'
            }
            ]);
        }

        function handleInterest(interested) {
            // ── Analytics: postMessage to parent (cross-origin safe) ────
            try {
                window.parent.postMessage({
                    type: 'payg_analytics',
                    event: interested ? 'chat_interest_yes' : 'chat_interest_just_exploring'
                }, 'https://payg.in');
            } catch (e) { }
            // ── end analytics ────────────────────────────────────────────
            if (interested) {
                addUserMessage("Yes, I am interested");
                chatState.stage = 'form';
                setTimeout(() => {
                    addBotMessage("Excellent! I'd be happy to assist you.Let me collect some information  to better serve you.");
                    setTimeout(() => {
                        showNameInput();
                        forceScrollToBottom();
                    }, 50);
                }, 50);
            } else {
                addUserMessage("Just Exploring");
                setTimeout(() => {
                    addBotMessage("No problem! Feel free to explore. If you need help, I'm here! 😊");
                    setTimeout(() => {
                        showQuickReplies([{
                            text: 'Actually, I need help',
                            action: 'interested'
                        },
                        {
                            text: 'Close Chat',
                            action: 'close-chat'
                        }
                        ]);
                    }, 50);
                }, 50);
            }
        }

        function showRequestTypeOptions() {
            showQuickReplies([{
                text: '💼 Sales Enquiry',
                action: 'sales'
            },
            {
                text: '🛠️ Service Related',
                action: 'service'
            },
            {
                text: '💳 Transaction Related',
                action: 'transaction'
            },
            {
                text: '📞 Request a Call Back',
                action: 'callback'
            }
            ]);
        }

        // Main function to handle request type selection
        async function handleRequestType(type) {
            const typeLabels = {
                'sales': 'Sales Enquiry',
                'service': 'Service Related',
                'transaction': 'Transaction Related',
                'callback': 'Request a Call Back'
            };

            chatState.formData.requestType = type;
            addUserMessage(typeLabels[type] || type);

            // Check if client details are complete
            if (!hasCompleteClient()) {
                addBotMessage("Please complete your details first.");
                showNameInput();
                chatState.requestSelectionLocked = false;
                return;
            }

            // Proceed based on request type
            if (type === 'sales') {
                createTicket();
            } else if (type === 'transaction') {
                setTimeout(() => askTransactionDetails(), 30);
            } else if (type === 'callback') {
                setTimeout(() => askCallbackIssueDescription(), 30);
            } else if (type === 'service') {
                setTimeout(() => askIssueDescription(), 30);
            }
        }

        // Show name input
        function showNameInput() {
            if (chatState.formData.name && chatState.formData.mobile && chatState.formData.email && chatState.formData.businessName) {
                askInterest();
                return;
            }
            const inputHTML = `
                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-700">Can you please tell me your name?</label>
                    <div class="flex gap-2">
                        <input type="text" id="nameInput" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Enter your name" />
                        <button onclick="submitName()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('chatInput').innerHTML = inputHTML;
            document.getElementById('nameInput').focus();
            document.getElementById('nameInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') submitName();
            });
        }

        function submitName() {
            const name = document.getElementById('nameInput').value.trim();
            if (!name) {
                alert('Please enter your name');
                return;
            }
            chatState.formData.name = name;
            saveChatSession();
            addUserMessage(name);
            setTimeout(() => {
                addBotMessage(`Nice to meet you, ${name}! 👋`);
                setTimeout(() => {
                    showMobileInput();
                }, 50);
            }, 50);
        }

        function showMobileInput() {
            if (chatState.otpData.mobileVerified) {
                // Show verified mobile, not editable
                const inputHTML = `
            <div class="space-y-1">
                <label class="block text-xs font-medium text-gray-700">Mobile Number</label>
                <div class="flex gap-2 items-center">
                    <span class="px-2 py-1 border border-gray-300 rounded-lg bg-gray-100 text-gray-700 text-xs select-none">
                        ${chatState.formData.mobile}
                    </span>
                    <span class="text-green-600 font-semibold text-xs">Verified</span>
                </div>
            </div>
        `;
                document.getElementById('chatInput').innerHTML = inputHTML;
                setTimeout(() => showEmailInput(), 800);
                return;
            }
            const inputHTML = `
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:600; color:#374151;">
                    Can you please tell me your mobile number?
                </label>
                <div style="display:flex; gap:6px; align-items:stretch;">
                    <span style="display:inline-flex; align-items:center; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; background:#f3f4f6; font-size:13px; color:#374151; white-space:nowrap; height:36px; box-sizing:border-box;">
                        +91
                    </span>
                    <input
                        type="text"
                        id="mobileInput"
                        style="flex:1; min-width:0; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family:monospace; height:36px; box-sizing:border-box;"
                        placeholder="10-digit number"
                        maxlength="10"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        oninput="this.value = this.value.replace(/\\D/g, '')"
                        value="${chatState.formData.mobile ? chatState.formData.mobile.replace('+91', '') : ''}"
                    />
                    <button
                        id="sendMobileOtpBtn"
                        onclick="submitMobile()"
                        style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; background:#2563eb; color:white; border:none; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; height:36px; min-width:95px; box-sizing:border-box;">
                        Send OTP
                    </button>
                </div>
                <p style="font-size:10px; color:#6b7280; margin:0;">Enter exactly 10 digits (without country code)</p>
                <div id="mobileOtpSection"></div>
            </div>`;

            document.getElementById('chatInput').innerHTML = inputHTML;
            document.getElementById('mobileInput').focus();
            document.getElementById('mobileInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') submitMobile();
            });
        }

        async function submitMobile() {
            const mobileInput = document.getElementById('mobileInput');
            const sendBtn = document.getElementById('sendMobileOtpBtn');

            const mobile = mobileInput.value.trim();

            if (!mobile || mobile.length !== 10) {
                alert('Please enter exactly 10 digits for your mobile number');
                mobileInput.focus();
                return;
            }

            chatState.formData.mobile = '+91' + mobile;
            saveChatSession();

            addUserMessage(`+91 ${mobile}`);

            // SEND -> SENDING
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            sendBtn.style.opacity = '0.7';
            sendBtn.style.cursor = 'not-allowed';

            try {
                const res = await fetch(
                    'api/sales/chatbot-otp.php?action=mobile-send',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ mobile })
                    }
                );

                const result = await res.json();

                if (!result.success) {
                    if (result.limited) {
                        alert(result.message);
                    } else {
                        addBotMessage(
                            '❌ Failed to send OTP: ' +
                            (result.message || 'Unknown error')
                        );
                    }

                    // Restore button
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send OTP';
                    sendBtn.style.opacity = '1';
                    sendBtn.style.cursor = 'pointer';

                    return;
                }

                chatState.otpData.mobileOtpId = result.id;
                chatState.otpData.mobileSentAt = Date.now();

                // SENDING -> SENT
                sendBtn.textContent = 'Sent ✓';
                sendBtn.style.background = '#16a34a';
                sendBtn.style.opacity = '1';

                setTimeout(() => {
                    addBotMessage(
                        `📱 OTP sent to ${mobile}. Please enter the 6-digit code.`
                    );

                    showMobileOTPInput();
                }, 400);

            } catch (err) {
                addBotMessage(
                    '❌ Error sending OTP. Please try again.'
                );

                sendBtn.disabled = false;
                sendBtn.textContent = 'Send OTP';
                sendBtn.style.opacity = '1';
                sendBtn.style.cursor = 'pointer';
            }
        }

        function startMobileOtpTimer() {
            clearMobileOtpTimer();

            const display = () => {
                const el = document.getElementById('mobileOtpTimer');
                if (!el) return;

                const remaining = Math.max(
                    0,
                    300000 - (Date.now() - (chatState.otpData.mobileSentAt || 0))
                );

                const minutes = String(Math.floor(remaining / 60000)).padStart(2, '0');
                const seconds = String(Math.floor((remaining % 60000) / 1000)).padStart(2, '0');

                el.textContent = `${minutes}:${seconds}`;

                if (remaining <= 0) {
                    clearMobileOtpTimer();
                    addBotMessage('⌛ Your mobile OTP expired. Please request a new one.');
                }
            };

            display();
            chatState.otpData.mobileTimerHandle = setInterval(display, 1000);
        }

        function clearMobileOtpTimer() {
            if (chatState.otpData.mobileTimerHandle) {
                clearInterval(chatState.otpData.mobileTimerHandle);
                chatState.otpData.mobileTimerHandle = null;
            }
        }

        function isMobileOtpExpired() {
            if (!chatState.otpData.mobileSentAt) return false;
            return (Date.now() - chatState.otpData.mobileSentAt) > 300000;
        }

        function showMobileOTPInput() {
            const otpHTML = `
        <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px; padding-top:4px; border-top:1px solid #e5e7eb;">
            <label style="font-size:11px; font-weight:600; color:#374151;">Enter OTP</label>
            <div style="display:flex; gap:6px; align-items:stretch;">
                <input type="text" id="mobileOTPInput"
                    style="flex:1; min-width:0; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family:monospace; letter-spacing:4px; text-align:center; height:36px; box-sizing:border-box;"
                    placeholder="000000" maxlength="6" />
                <button
                    id="verifyMobileOtpBtn"
                    onclick="verifyMobileOTP()"
                    style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; background:#16a34a; color:white; border:none; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; height:36px; min-width:90px; box-sizing:border-box;">
                    Verify
                </button>
                <span style="display:inline-flex; align-items:center; font-size:10px; color:#6b7280; white-space:nowrap;">
                    Expires in <span id="mobileOtpTimer" style="font-weight:600;">05:00</span>
                </span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <button onclick="resendMobileOTP()"
                    style="font-size:10px; color:#2563eb; background:none; border:none; cursor:pointer; padding:0;">
                    Resend OTP
                </button>
                <span style="font-size:10px; color:#6b7280; display:flex; align-items:center; gap:8px;">
                    ${chatState.formData.mobile}
                    <button onclick="editMobileNumber()"
                        style="font-size:10px; color:#dc2626; background:none; border:none; cursor:pointer; padding:0; text-decoration:underline;">
                        Change number
                    </button>
                </span>
            </div>
        </div>`;

            document.getElementById('mobileOtpSection').innerHTML = otpHTML;
            document.getElementById('mobileOTPInput').focus();
            document.getElementById('mobileOTPInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') verifyMobileOTP();
            });
            startMobileOtpTimer();
        }

        function editMobileNumber() {
            clearMobileOtpTimer();
            chatState.otpData.mobileVerified = false;
            chatState.otpData.mobileOtpId = null;
            chatState.otpData.mobileSentAt = null;
            chatState.formData.mobile = '';
            saveChatSession();
            showMobileInput();
        }

        async function resendMobileOTP() {
            const mobile = (chatState.formData.mobile || '').replace(/\D/g, '').slice(-10);
            if (!mobile || mobile.length !== 10) {
                addBotMessage('Please enter your mobile number first.');
                showMobileInput();
                return;
            }

            addBotMessage('🔁 Sending you a fresh OTP…');
            try {
                const res = await fetch('api/sales/chatbot-otp.php?action=mobile-send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mobile })
                });
                const data = await res.json();
                if (!data.success) {
                    if (data.limited) {
                        alert(data.message);
                        return;
                    }
                    addBotMessage('❌ Could not resend OTP. Please try again.');
                    return;
                }
                chatState.otpData.mobileOtpId = data.id;
                addBotMessage(`📱 New OTP sent to ${mobile}. Please enter the 6-digit code.`);
                chatState.otpData.mobileSentAt = Date.now();
                clearMobileOtpTimer();
                showMobileOTPInput();
            } catch (e) {
                addBotMessage('❌ Could not resend OTP right now. Please try again.');
            }
        }

        async function verifyMobileOTP() {
            const otpInput = document.getElementById('mobileOTPInput');
            const verifyBtn = document.getElementById('verifyMobileOtpBtn');

            const otp = otpInput.value.trim();

            if (otp.length !== 6) {
                alert('Please enter a valid 6-digit OTP');
                otpInput.focus();
                return;
            }

            if (isMobileOtpExpired()) {
                addBotMessage(
                    '⌛ This OTP has expired. Please request a new one.'
                );
                return;
            }

            const mobile = chatState.formData.mobile
                .replace(/\D/g, '')
                .slice(-10);

            // Show entered OTP as masked user message
            addUserMessage('••••••');

            // Prevent duplicate clicks
            verifyBtn.disabled = true;
            verifyBtn.textContent = 'Verifying...';
            verifyBtn.style.opacity = '0.7';
            verifyBtn.style.cursor = 'not-allowed';

            otpInput.disabled = true;

            try {
                const res = await fetch(
                    'api/sales/chatbot-otp.php?action=mobile-verify',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            mobile,
                            otp,
                            id: chatState.otpData.mobileOtpId
                        })
                    }
                );

                const result = await res.json();

                if (!result.success) {
                    addBotMessage(
                        '❌ OTP verification failed. Please enter the valid OTP sent to your mobile.'
                    );

                    verifyBtn.disabled = false;
                    verifyBtn.textContent = 'Verify';
                    verifyBtn.style.opacity = '1';
                    verifyBtn.style.cursor = 'pointer';

                    otpInput.disabled = false;
                    otpInput.value = '';
                    otpInput.focus();

                    return;
                }

                chatState.otpData.mobileOTP = otp;
                chatState.otpData.mobileVerified = true;

                clearMobileOtpTimer();
                saveChatSession();

                verifyBtn.textContent = 'Verified ✓';
                verifyBtn.style.opacity = '1';

                setTimeout(() => {
                    addBotMessage('✅ Mobile number verified!');

                    setTimeout(() => {
                        showEmailInput();
                    }, 500);
                }, 400);

            } catch (err) {
                addBotMessage(
                    '❌ Error verifying OTP. Please try again.'
                );

                verifyBtn.disabled = false;
                verifyBtn.textContent = 'Verify';
                verifyBtn.style.opacity = '1';
                verifyBtn.style.cursor = 'pointer';

                otpInput.disabled = false;
                otpInput.focus();
            }
        }

        function showEmailInput() {
            if (chatState.otpData.emailVerified) {
                const inputHTML = `
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:600; color:#374151;">Email Address</label>
                <div style="display:flex; gap:4px; align-items:center;">
                    <span style="padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; background:#f3f4f6; font-size:12px; color:#374151;">
                        ${chatState.formData.email}
                    </span>
                    <span style="font-size:12px; font-weight:600; color:#16a34a;">✓ Verified</span>
                </div>
            </div>`;
                document.getElementById('chatInput').innerHTML = inputHTML;
                setTimeout(() => showBusinessNameInput(), 800);
                return;
            }

            const inputHTML = `
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:600; color:#374151;">
                    Can you please enter your email address?
                </label>
                <div style="display:flex; gap:6px; align-items:stretch;">
                    <input type="email" id="emailInput"
                        style="flex:1; min-width:0; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; height:36px; box-sizing:border-box;"
                        placeholder="your@email.com"
                        value="${chatState.formData.email || ''}" />
                    <button onclick="submitEmail()"
                        style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; background:#2563eb; color:white; border:none; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; height:36px; box-sizing:border-box;">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <div id="emailOtpSection"></div>
            </div>`;

            document.getElementById('chatInput').innerHTML = inputHTML;
            document.getElementById('emailInput').focus();
            document.getElementById('emailInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); submitEmail(); }
            });
        }

        async function submitEmail() {
            const email = document.getElementById('emailInput').value.trim();
            if (!email || !email.includes('@')) {
                alert('Please enter a valid email address');
                return;
            }
            const sendBtn = document.querySelector('#chatInput button');
            if (sendBtn) sendBtn.disabled = true;

            chatState.formData.email = email;
            // Email OTP is disabled in this flow (see the commented-out block below); mark the
            // email accepted so isFullyCompleted()/restoreFromSession() can persist the session
            // across a reload instead of always requiring a step that no longer runs.
            chatState.otpData.emailVerified = true;
            saveChatSession();
            addUserMessage(email);
            // addBotMessage(`⏳ Sending OTP to ${email}...`);

            // try {
            //     const res = await fetch('api/sales/chatbot-otp.php?action=email-send', {
            //         method: 'POST',
            //         headers: { 'Content-Type': 'application/json' },
            //         body: JSON.stringify({ email })
            //     });
            //     const result = await res.json();
            //     if (!result.success) {
            //         addBotMessage('❌ Failed to send OTP: ' + (result.message || 'Unknown error'));
            //         return;
            //     }
            //     chatState.otpData.emailOtpId = result.id;
            //     chatState.otpData.emailSentAt = Date.now();
            // addBotMessage(`📧 OTP sent to ${email}. Please enter the 6-digit code.`);
            //showEmailOTPInput();
            showBusinessNameInput();
            // } catch (err) {
            //     addBotMessage('❌ Something went wrong. Please try again.');
            // } finally {
            //     if (sendBtn) sendBtn.disabled = false;
            // }
        }

        // function showEmailOTPInput() {
        //     const otpHTML = `
        // <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px; padding-top:4px; border-top:1px solid #e5e7eb;">
        //     <label style="font-size:11px; font-weight:600; color:#374151;">Enter OTP</label>
        //     <div style="display:flex; gap:6px; align-items:stretch;">
        //         <input type="text" id="emailOTPInput"
        //             style="flex:1; min-width:0; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family:monospace; letter-spacing:4px; text-align:center; height:36px; box-sizing:border-box;"
        //             placeholder="000000" maxlength="6" />
        //         <button onclick="verifyEmailOTP()"
        //             style="display:inline-flex; align-items:center; padding:0 14px; background:#16a34a; color:white; border:none; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; height:36px; box-sizing:border-box;">
        //             Verify
        //         </button>
        //         <span style="display:inline-flex; align-items:center; font-size:10px; color:#6b7280; white-space:nowrap;">
        //             Expires in <span id="emailOtpTimer" style="font-weight:600;">05:00</span>
        //         </span>
        //     </div>
        //     <div style="display:flex; justify-content:space-between; align-items:center;">
        //         <button onclick="resendEmailOTP()"
        //             style="font-size:10px; color:#2563eb; background:none; border:none; cursor:pointer; padding:0;">
        //             Resend OTP
        //         </button>
        //         <span style="font-size:10px; color:#6b7280;">${chatState.formData.email}</span>
        //     </div>
        // </div>`;

        //     document.getElementById('emailOtpSection').innerHTML = otpHTML;
        //     document.getElementById('emailOTPInput').focus();
        //     document.getElementById('emailOTPInput').addEventListener('keypress', (e) => {
        //         if (e.key === 'Enter') verifyEmailOTP();
        //     });
        //     startEmailOtpTimer();
        // }

        // function editEmailAddress() {
        // chatState.otpData.emailVerified = false;
        // chatState.formData.email = '';
        // showEmailInput();
        // }

        // async function verifyEmailOTP() {
        // const otp = document.getElementById('emailOTPInput').value.trim();
        // const email = chatState.formData.email;

        // if (otp.length !== 6) {
        //     alert('Please enter a valid 6-digit OTP');
        //     return;
        // }
        // const btn = document.querySelector('#emailOtpSection button');
        // if (btn) {
        //     btn.disabled = true;
        //     btn.textContent = 'Verifying...';
        // }

        // if (isEmailOtpExpired()) {
        //     addBotMessage('⌛ This OTP has expired. Please request a new one.');
        //     showQuickReplies([{
        //         text: '🔄 Resend OTP',
        //         action: 'resend-email-otp'
        //     }]);
        //     return;
        // }

        // try {
        //     const res = await fetch('api/sales/chatbot-otp.php?action=email-verify', {
        //         method: 'POST',
        //         headers: { 'Content-Type': 'application/json' },
        //         body: JSON.stringify({
        //             email,
        //             otp,
        //             id: chatState.otpData.emailOtpId
        //         })
        //     });
        //     const result = await res.json();

        //     if (!result.success) {
        //         throw new Error(result.message);
        //     }

        //     chatState.otpData.emailVerified = true;
        //     clearEmailOtpTimer();
        //     saveChatSession();
        //     addUserMessage('✓ OTP Verified');
        //     addBotMessage('✅ Email verified!');
        //     setTimeout(() => {
        //         showBusinessNameInput();
        //     }, 50);
        // } catch (err) {
        //     addBotMessage('❌ Entered OTP is incorrect. Please enter the correct OTP.');
        // } finally {
        //     if (btn) {
        //         btn.disabled = false;
        //         btn.textContent = 'Verify';
        //     }
        // }
        // }

        // function resendEmailOTP() {
        // if (!chatState.formData.email) {
        //     addBotMessage('Please enter your email first.');
        //     return;
        // }

        // if (chatState.otpData.emailSentAt && Date.now() - chatState.otpData.emailSentAt < 5000) {
        //     addBotMessage('⏳ Please wait a few seconds before requesting another OTP.');
        //     return;
        // }

        // addBotMessage('🔁 Sending you a fresh OTP…');

        // fetch('api/sales/chatbot-otp.php?action=email-send', {
        //     method: 'POST',
        //     headers: { 'Content-Type': 'application/json' },
        //     body: JSON.stringify({ email: chatState.formData.email })
        // })
        //     .then(res => res.json())
        //     .then(data => {
        //         if (!data.success) {
        //             throw new Error(data.message || 'Unable to resend OTP');
        //         }
        //         chatState.otpData.emailOtpId = data.id;
        //         chatState.otpData.emailSentAt = Date.now();
        //         addBotMessage(`📧 New OTP sent to ${chatState.formData.email}`);
        //         showEmailOTPInput();
        //         startEmailOtpTimer();
        //     })
        //     .catch(err => {
        //         addBotMessage('❌ Could not resend the OTP right now. Please try again shortly.');
        //     });
        // }

        // function startEmailOtpTimer() {
        // clearEmailOtpTimer();
        // const display = () => {
        //     const el = document.getElementById('emailOtpTimer');
        //     if (!el) return;
        //     const remaining = Math.max(0, 300000 - (Date.now() - (chatState.otpData.emailSentAt || 0)));
        //     const minutes = String(Math.floor(remaining / 60000)).padStart(2, '0');
        //     const seconds = String(Math.floor((remaining % 60000) / 1000)).padStart(2, '0');
        //     el.textContent = `${minutes}:${seconds}`;
        //     if (remaining <= 0) {
        //         clearEmailOtpTimer();
        //         addBotMessage('⌛ Your OTP expired. Please request a new one.');
        //         showQuickReplies([{
        //             text: 'Resend OTP',
        //             action: 'resend-email-otp'
        //         }]);
        //     }
        // };
        // display();
        // chatState.otpData.emailTimerHandle = setInterval(display, 1000);
        // }

        // function clearEmailOtpTimer() {
        // if (chatState.otpData.emailTimerHandle) {
        //     clearInterval(chatState.otpData.emailTimerHandle);
        //     chatState.otpData.emailTimerHandle = null;
        // }
        // }

        // function isEmailOtpExpired() {
        // if (!chatState.otpData.emailSentAt) return false;
        // return (Date.now() - chatState.otpData.emailSentAt) > 300000;
        // }

        // Show business name input
        function showBusinessNameInput() {
            const inputHTML = `
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:600; color:#374151;">
                    Can you please tell me your Business/Company name? *
                </label>
                <div style="display:flex; gap:6px; align-items:stretch;">
                    <input type="text" id="businessNameInput"
                        style="flex:1; min-width:0; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; height:36px; box-sizing:border-box;"
                        placeholder="Enter your business name" required />
                    <button onclick="submitBusinessName()"
                        style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; background:#2563eb; color:white; border:none; border-radius:6px; font-size:13px; cursor:pointer; height:36px; box-sizing:border-box;">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <p style="font-size:10px; color:#6b7280; margin:0;">Required for registration</p>
            </div>`;

            document.getElementById('chatInput').innerHTML = inputHTML;
            document.getElementById('businessNameInput').focus();
            document.getElementById('businessNameInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') submitBusinessName();
            });
        }

        function submitBusinessName() {
            const businessName = document.getElementById('businessNameInput').value.trim();
            if (!businessName) {
                alert('Please enter your business/company name');
                return;
            }
            chatState.formData.businessName = businessName;
            saveChatSession();
            addUserMessage(businessName);

            setTimeout(() => {
                addBotMessage('How can we assist you today?');
                setTimeout(() => {
                    askAssistType();
                }, 50);
            }, 50);
        }

        // Ask for callback issue description
        function askCallbackIssueDescription() {
            addBotMessage("Can you please describe your issue in a few words?");
            showCallbackDescriptionInput();
        }

        function showCallbackDescriptionInput() {
            const inputHTML = `
            <div class="space-y-3">
                <label class="block text-sm font-medium text-gray-700">Please tell us briefly about your issue</label>
                <div class="flex gap-2 items-end">
                    <textarea id="callbackDescInput" rows="1" class="chat-autogrow flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe your callback request here" maxlength="2000" required></textarea>
                    <button onclick="submitCallbackDescription()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        `;
            document.getElementById('chatInput').innerHTML = inputHTML;
            const cbDesc = document.getElementById('callbackDescInput');
            cbDesc.focus();
            enableChatAutoGrow(cbDesc, submitCallbackDescription);
        }

        function submitCallbackDescription() {
            const description = document.getElementById('callbackDescInput').value.trim();
            if (!description) {
                alert('Please describe your callback request');
                return;
            }
            chatState.formData.issueDescription = description;
            addUserMessage(description);
            setTimeout(() => {
                createTicket();
            }, 50);
        }

        // Transaction specific functions
        // Note: the original (payg1) flow required a file attachment here and
        // uploaded it as part of ticket creation. api/sales/chatbot-ticket.php
        // does not accept file attachments, so the attachment step is dropped
        // entirely -- we go straight from the description to ticket submission.
        function askTransactionDetails() {
            addBotMessage("May I request you to provide details about your transaction issue?");
            showTransactionDescriptionInput();
        }

        function showTransactionDescriptionInput() {
            const inputHTML = `
            <div class="space-y-3">
                <label class="block text-sm font-medium text-gray-700">Please describe your transaction issue *</label>
                <div class="flex gap-2">
                    <textarea id="transactionDescInput" rows="3" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe the transaction issue..."></textarea>
                    <button onclick="submitTransactionDescription()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
            `;
            document.getElementById('chatInput').innerHTML = inputHTML;
            const transactionDescInput = document.getElementById('transactionDescInput');
            transactionDescInput.focus();
            transactionDescInput.addEventListener('keydown', (e) => {
                // Enter submits; Shift+Enter inserts a new line.
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    submitTransactionDescription();
                }
            });
        }

        function submitTransactionDescription() {
            const description = document.getElementById('transactionDescInput').value.trim();
            if (!description) {
                alert('Please describe your transaction issue');
                return;
            }
            chatState.formData.issueDescription = description;
            addUserMessage(description);
            setTimeout(() => {
                addBotMessage(
                    "Kindly upload the transaction screenshot or a relevant transaction statement."
                );
                showTransactionAttachmentInput();
            }, 50);
        }

        function showTransactionAttachmentInput() {
            const file = chatState.formData.attachment;
            const fileName = file ? 'Change file' : 'Choose file (max 5MB)';
            const filePreviewClass = file ? '' : 'hidden';
            const uploadInProgress = chatState.attachmentUploadInProgress;
            const progress = Math.max(0, Math.min(100, chatState.attachmentUploadProgress || 0));
            const uploadError = chatState.attachmentUploadError;
            const uploadDone = chatState.attachmentUploaded && !!chatState.formData.attachmentUrl;
            let statusText = '';
            let statusClass = '';

            if (uploadDone) {
                statusText = '';
                statusClass = '';
            } else if (uploadError) {
                statusText = uploadError;
                statusClass = 'error';
            }

            const inputHTML = `
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Attachment <span class="text-red-500">*</span></label>
                    <input type="file" id="attachmentInput" class="hidden" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="handleFileSelect(event)">
                    <label for="attachmentInput" class="file-upload-label">
                        <i class="fas fa-paperclip"></i>
                        <span id="fileName">${fileName}</span>
                    </label>
                    <div id="filePreview" class="mt-2 gmail-upload-preview ${filePreviewClass}">
                        <div class="gmail-upload-row">
                            <div class="gmail-upload-main">
                                <span id="filePreviewName" class="gmail-upload-name"></span>
                                <span id="filePreviewSize" class="gmail-upload-size"></span>
                            </div>
                            <div class="gmail-upload-actions">
                                <div id="uploadProgressWrap" class="${uploadInProgress ? '' : 'hidden'}">
                                    <div class="gmail-upload-progress-track">
                                        <div id="uploadProgressBar" class="gmail-upload-progress-bar" style="width:${progress}%"></div>
                                    </div>
                                </div>
                                <button type="button" onclick="removeAttachment()" class="gmail-upload-remove" aria-label="Remove attachment">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div id="uploadStatusText" class="gmail-upload-status ${statusClass} ${statusText ? '' : 'hidden'}">${statusText}</div>
                    </div>
                </div>
                <button id="transactionSubmitBtn" onclick="submitTransactionDetails()" class="w-full px-6 py-3 bg-blue-600 text-white rounded-lg font-medium ${uploadInProgress ? 'opacity-60 cursor-not-allowed' : 'hover:bg-blue-700'}" ${uploadInProgress ? 'disabled' : ''}>
                    ${uploadInProgress ? 'Uploading...' : 'Submit'}
                </button>
            </div>
            `;
            document.getElementById('chatInput').innerHTML = inputHTML;
            updateAttachmentUploadUI();
        }

        function formatFileSize(bytes) {
            if (!bytes || bytes <= 0) return '';
            if (bytes < 1024) return `${bytes}B`;
            if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)}K`;
            return `${(bytes / (1024 * 1024)).toFixed(1)}M`;
        }

        function updateAttachmentUploadUI() {
            const file = chatState.formData.attachment;
            const preview = document.getElementById('filePreview');
            const fileNameLabel = document.getElementById('fileName');
            const filePreviewName = document.getElementById('filePreviewName');
            const filePreviewSize = document.getElementById('filePreviewSize');
            const statusTextEl = document.getElementById('uploadStatusText');
            const progressWrap = document.getElementById('uploadProgressWrap');
            const progressBar = document.getElementById('uploadProgressBar');
            const submitBtn = document.getElementById('transactionSubmitBtn');

            if (!preview || !fileNameLabel) return;

            if (!file) {
                preview.classList.add('hidden');
                fileNameLabel.textContent = 'Choose file (max 5MB)';
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit';
                    submitBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                    submitBtn.classList.add('hover:bg-blue-700');
                }
                return;
            }

            preview.classList.remove('hidden');
            fileNameLabel.textContent = 'Change file';
            if (filePreviewName) filePreviewName.textContent = file.name;
            if (filePreviewSize) filePreviewSize.textContent = `(${formatFileSize(file.size)})`;
            if (filePreviewName) {
                const isUploaded = !!(chatState.attachmentUploaded && chatState.formData.attachmentUrl);
                filePreviewName.classList.toggle('uploaded', isUploaded);
            }

            if (progressBar) {
                const visibleProgress = Math.max(0, Math.min(100, chatState.attachmentUploadDisplayProgress || 0));
                progressBar.style.width = `${visibleProgress}%`;
            }
            if (progressWrap) progressWrap.classList.toggle('hidden', !chatState.attachmentUploadInProgress);

            if (statusTextEl) {
                statusTextEl.classList.remove('hidden', 'error', 'success');
                if (chatState.attachmentUploadInProgress) {
                    statusTextEl.textContent = '';
                    statusTextEl.classList.add('hidden');
                } else if (chatState.attachmentUploaded && chatState.formData.attachmentUrl) {
                    statusTextEl.textContent = '';
                    statusTextEl.classList.add('hidden');
                } else if (chatState.attachmentUploadError) {
                    statusTextEl.textContent = chatState.attachmentUploadError;
                    statusTextEl.classList.add('error');
                } else {
                    statusTextEl.textContent = '';
                    statusTextEl.classList.add('hidden');
                }
            }

            if (submitBtn) {
                const disabled = chatState.attachmentUploadInProgress;
                submitBtn.disabled = disabled;
                submitBtn.textContent = disabled ? 'Uploading...' : 'Submit';
                submitBtn.classList.toggle('opacity-60', disabled);
                submitBtn.classList.toggle('cursor-not-allowed', disabled);
                submitBtn.classList.toggle('hover:bg-blue-700', !disabled);
            }
        }

        async function startAttachmentUpload(file) {
            chatState.attachmentUploadInProgress = true;
            chatState.attachmentUploadProgress = 0;
            chatState.attachmentUploadDisplayProgress = 0;
            chatState.attachmentUploadTargetProgress = 0;
            chatState.attachmentUploaded = false;
            chatState.attachmentUploadError = '';

            startAttachmentProgressAnimation();
            updateAttachmentUploadUI();

            // ✅ Simulate upload progress
            let progress = 0;

            const interval = setInterval(() => {
                progress += Math.random() * 20;

                if (progress >= 100) {
                    progress = 100;
                    clearInterval(interval);

                    stopAttachmentProgressAnimation();

                    chatState.attachmentUploadInProgress = false;
                    chatState.attachmentUploadProgress = 100;
                    chatState.attachmentUploadDisplayProgress = 100;
                    chatState.attachmentUploadTargetProgress = 100;
                    chatState.attachmentUploaded = true;

                    updateAttachmentUploadUI();
                } else {
                    chatState.attachmentUploadProgress = progress;
                    setAttachmentProgressTarget(progress);
                }
            }, 200);
        }

        function stopAttachmentProgressAnimation() {
            if (chatState.attachmentUploadTimer) {
                clearInterval(chatState.attachmentUploadTimer);
                chatState.attachmentUploadTimer = null;
            }
        }

        function setAttachmentProgressTarget(value) {
            const next = Math.max(0, Math.min(100, value || 0));
            chatState.attachmentUploadTargetProgress = Math.max(chatState.attachmentUploadTargetProgress || 0, next);
        }

        function startAttachmentProgressAnimation() {
            stopAttachmentProgressAnimation();
            chatState.attachmentUploadTimer = setInterval(() => {
                const current = chatState.attachmentUploadDisplayProgress || 0;
                const target = chatState.attachmentUploadTargetProgress || 0;
                if (current >= target) return;
                const step = target >= 100 ? 3 : 1.5;
                chatState.attachmentUploadDisplayProgress = Math.min(target, current + step);
                updateAttachmentUploadUI();
            }, 45);
        }

        async function animateAttachmentProgressToEnd() {
            setAttachmentProgressTarget(100);
            return new Promise((resolve) => {
                const timer = setInterval(() => {
                    const done = (chatState.attachmentUploadDisplayProgress || 0) >= 100;
                    if (!done) return;
                    clearInterval(timer);
                    resolve();
                }, 30);
            });
        }

        function abortAttachmentUpload() {
            stopAttachmentProgressAnimation();
            if (chatState.attachmentUploadRequest) {
                try {
                    chatState.attachmentUploadRequest.abort();
                } catch (e) {
                    // ignore
                }
                chatState.attachmentUploadRequest = null;
            }
        }

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                event.target.value = '';
                return;
            }

            abortAttachmentUpload();
            chatState.formData.attachment = file;
            chatState.formData.attachmentUrl = '';
            chatState.attachmentUploadInProgress = true;
            chatState.attachmentUploadProgress = 0;
            chatState.attachmentUploadDisplayProgress = 12;
            chatState.attachmentUploadTargetProgress = 12;
            chatState.attachmentUploaded = false;
            chatState.attachmentUploadError = '';
            startAttachmentProgressAnimation();
            updateAttachmentUploadUI();
            startAttachmentUpload(file);
        }

        function removeAttachment() {
            abortAttachmentUpload();
            chatState.formData.attachment = null;
            chatState.formData.attachmentUrl = '';
            chatState.attachmentUploadInProgress = false;
            chatState.attachmentUploadProgress = 0;
            chatState.attachmentUploadDisplayProgress = 0;
            chatState.attachmentUploadTargetProgress = 0;
            chatState.attachmentUploaded = false;
            chatState.attachmentUploadError = '';
            document.getElementById('attachmentInput').value = '';
            updateAttachmentUploadUI();
        }

        async function submitTransactionDetails() {
            if (submitTransactionDetails._inProgress) return;

            if (!chatState.formData.attachment) {
                alert('Please upload a file for transaction related issues.');
                return;
            }

            if (!chatState.attachmentUploaded) {
                alert('Please wait for upload animation to complete.');
                return;
            }

            submitTransactionDetails._inProgress = true;

            const submitBtn = document.getElementById('transactionSubmitBtn');

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
                submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
            }

            try {
                await createTicket();
            } finally {
                submitTransactionDetails._inProgress = false;
            }
        }

        function askIssueDescription() {
            addBotMessage("Can you please describe your issue in a few words?");
            showDescriptionInput();
        }

        function showDescriptionInput() {
            const inputHTML = `
            <div class="space-y-3">
                <label class="block text-sm font-medium text-gray-700">Please tell us briefly about your issue *</label>
                <div class="flex gap-2 items-end">
                    <textarea id="issueDescriptionInput" rows="1" class="chat-autogrow flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe your issue here" maxlength="2000" required></textarea>
                    <button onclick="submitIssueDescription()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        `;
            document.getElementById('chatInput').innerHTML = inputHTML;
            const issueDesc = document.getElementById('issueDescriptionInput');
            issueDesc.focus();
            enableChatAutoGrow(issueDesc, submitIssueDescription);
        }

        function submitIssueDescription() {
            const description = document.getElementById('issueDescriptionInput').value.trim();
            if (!description) {
                alert('Please describe your issue');
                return;
            }
            chatState.formData.issueDescription = description;
            addUserMessage(description);
            setTimeout(() => {
                createTicket();
            }, 500);
        }

        // Enable auto-growing textarea behaviour (1 -> 3 lines, then scroll)
        // and wire Enter = submit, Shift+Enter = newline.
        function enableChatAutoGrow(el, onSubmit) {
            if (!el) return;
            const MAX_PX = 92; // matches CSS max-height (~3 lines)
            el.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, MAX_PX) + 'px';
            });
            if (typeof onSubmit === 'function') {
                el.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        onSubmit();
                    }
                });
            }
        }

        // Ticket creation -> api/sales/chatbot-ticket.php (creates Lead + crm_tickets row).
        // No live-agent hand-off in this build: straight to a confirmation screen.
        async function createTicket() {
            try {
                const requestType = chatState.formData.requestType;
                const mobile = chatState.formData.mobile.replace(/\D/g, '').slice(-10);

                const formData = new FormData();

                formData.append('name', chatState.formData.name || '');
                formData.append('mobile', mobile);
                formData.append('email', chatState.formData.email || '');
                formData.append('company', chatState.formData.businessName || '');
                formData.append('assist_type', requestType);
                formData.append(
                    'description',
                    chatState.formData.issueDescription || ''
                );
                formData.append('website', '');

                if (
                    requestType === 'transaction' &&
                    chatState.formData.attachment instanceof File
                ) {
                    formData.append(
                        'attachment',
                        chatState.formData.attachment
                    );
                }

                const response = await fetch(
                    'api/sales/chatbot-ticket.php',
                    {
                        method: 'POST',
                        body: formData
                    }
                );

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || 'Ticket creation failed');
                }

                chatState.lastTicketRef = result.ticket_ref;
                saveChatSession();

                // A Service/Transaction request opens a live chat immediately when a Service
                // exec is online (api/sales/chatbot-ticket.php decides this server-side) — in
                // that case connect the visitor to the queue instead of the static confirmation.
                if (result.chat_session_id && result.chat_token) {
                    enterLiveChatMode(result.chat_token, result.chat_session_id, result.ticket_ref, result.chat_last_message_id || 0);
                    return;
                }

                showTicketConfirmation(requestType, result.ticket_ref);
            } catch (error) {
                addBotMessage("❌ Sorry, we couldn't submit your request right now. Please try again later.");
                chatState.requestSelectionLocked = false;
            }
        }

        // ── Live chat (Service/Transaction requests picked up by an online Service exec) ──
        // Polls api/sales/chat.php's public v_poll/v_post/v_close actions using the token
        // issued by chatbot-ticket.php. Mirrors the agent side (sales-chat.php), which already
        // shows this session in its waiting queue the moment it's created.
        function enterLiveChatMode(token, sessionId, ticketRef, lastMessageId) {
            liveChat.token = token;
            liveChat.sessionId = sessionId;
            // Seed the "since" cursor to the last message already inserted at ticket-creation
            // time (the queue greeting + the visitor's own issue description) — both were
            // already shown locally as the visitor typed them, so the poll should only surface
            // what happens AFTER this point (agent joining, replies), not re-echo them.
            liveChat.lastId = lastMessageId || 0;
            liveChat.active = true;
            liveChat.connectedMsgShown = false;
            saveChatSession();

            document.getElementById('chatInput').style.display = 'none';
            document.getElementById('chatInput').innerHTML = '';
            // Input box stays hidden until an agent actually picks up (status flips to
            // 'active') — see showLiveChatInput(), called from loadLiveChatMessages()/
            // pollLiveChat() below. Typing into it while still "Connecting…" would just be
            // messages sitting unread since nobody has joined yet.

            addBotMessage(`⏳ Connecting you to a Service agent…<br><span class="text-xs text-gray-500">Reference: ${ticketRef}</span>`);
            pollLiveChat();
            startLiveChatPolling();
        }

        // Reveal the message box only once the session is actually 'active' (an agent has
        // joined) — not while still 'waiting' in the queue.
        function showLiveChatInput() {
            document.getElementById('liveChatInputContainer').classList.remove('hidden');
            document.getElementById('liveChatInputContainer').style.display = 'flex';
        }

        // Re-attach to a live chat that was already open before a page reload.
        function resumeLiveChat(token, sessionId) {
            liveChat.token = token;
            liveChat.sessionId = sessionId;
            liveChat.lastId = 0;
            liveChat.active = true;
            liveChat.connectedMsgShown = true; // don't re-announce "connected" on a resume

            document.getElementById('chatInput').style.display = 'none';
            document.getElementById('chatInput').innerHTML = '';
            // Same as enterLiveChatMode: don't assume active on resume — the visitor could have
            // reloaded while still waiting. loadLiveChatMessages() below reveals it if/when the
            // polled status is actually 'active'.

            loadLiveChatMessages();
            startLiveChatPolling();
        }

        function startLiveChatPolling() {
            if (liveChat.pollHandle) clearInterval(liveChat.pollHandle);
            liveChat.pollHandle = setInterval(pollLiveChat, 3000);
        }

        function stopLiveChatPolling() {
            if (liveChat.pollHandle) clearInterval(liveChat.pollHandle);
            liveChat.pollHandle = null;
        }

        function renderLiveChatMessage(m) {
            if (m.sender === 'system') {
                // "<Agent Name> joined the chat" is meant for the agent-side console
                // (sales-chat.php) where names matter — the visitor just gets the generic
                // "Agent is connected" message (added separately once status flips to active),
                // so skip re-showing it here with the agent's name attached.
                if (/joined the chat$/i.test((m.body || '').trim())) return;
                // Same rule for the handover notice. The server stores it as
                // "Transferred to <Agent Name> (<Team> team)" because the agent console wants
                // to know exactly who took it — but an agent's name must never reach the
                // customer, so rewrite it to a generic notice here rather than stripping the
                // name server-side (which would blind the console too).
                let body = (m.body || '').trim();
                if (/^Transferred to /i.test(body)) body = 'Your chat has been transferred to another agent.';
                const div = document.createElement('div');
                div.className = 'flex justify-center my-2';
                div.innerHTML = `<div class="bg-amber-100 text-amber-800 border border-amber-200 px-3 py-1 rounded-full text-xs">${escapeLiveChatHtml(body)}</div>`;
                document.getElementById('chatMessages').appendChild(div);
                return;
            }
            if (m.sender === 'agent') {
                const div = document.createElement('div');
                div.className = 'chat-bubble flex items-start gap-2 mb-2';
                div.innerHTML = `
                    <div class="w-6 h-6 rounded-full bg-green-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-headset text-white" style="font-size:9px;"></i>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-gray-800 shadow-sm" style="min-width:0; max-width:calc(100% - 2rem);">
                        ${liveChatAttachmentHtml(m)}
                        ${m.body ? `<p class="text-gray-800 text-sm">${escapeLiveChatHtml(m.body)}</p>` : ''}
                    </div>`;
                document.getElementById('chatMessages').appendChild(div);
                return;
            }
            if (m.sender === 'visitor') { addUserMessage(escapeLiveChatHtml(m.body)); return; }
            if (m.sender === 'bot') { addBotMessage(escapeLiveChatHtml(m.body)); return; }
        }

        // Feeds the "closes in MM:SS" countdown (see startAutoCloseCountdown()). Server-side
        // timeout only fires when the SESSION'S last message is agent/system, so a visitor
        // reply cancels it — same rule applied here.
        function trackAutoCloseTimer(m) {
            if (m.sender === 'agent' || m.sender === 'system') {
                const ts = new Date((m.created_at || '').replace(' ', 'T')).getTime();
                if (!isNaN(ts)) liveChat.autoCloseDeadline = ts + 5 * 60 * 1000;
            } else if (m.sender === 'visitor') {
                liveChat.autoCloseDeadline = null;
            }
        }

        function startAutoCloseCountdown() {
            if (liveChat.countdownTick) return;
            liveChat.countdownTick = setInterval(() => {
                const el = document.getElementById('liveChatCountdown');
                if (!liveChat.active || !liveChat.autoCloseDeadline) { el.classList.add('hidden'); return; }
                const remainMs = liveChat.autoCloseDeadline - Date.now();
                if (remainMs <= 0) { el.classList.add('hidden'); return; }
                const mm = String(Math.floor(remainMs / 60000)).padStart(2, '0');
                const ss = String(Math.floor((remainMs % 60000) / 1000)).padStart(2, '0');
                el.textContent = `If no response, chat will be closed in ${mm}:${ss}`;
                el.classList.remove('hidden');
            }, 1000);
        }

        function stopAutoCloseCountdown() {
            if (liveChat.countdownTick) { clearInterval(liveChat.countdownTick); liveChat.countdownTick = null; }
            document.getElementById('liveChatCountdown').classList.add('hidden');
        }

        // A file an agent sent (see chat-upload.php / sales-chat.php's paperclip button) —
        // images preview inline, anything else (PDF, txt, etc.) is a plain download link. Same
        // attachment_url/attachment_type fields v_poll already returns; the widget just wasn't
        // rendering them, so a customer had no way to see or download a file an agent sent.
        function liveChatAttachmentHtml(m) {
            if (!m.attachment_url) return '';
            const isImg = (m.attachment_type || '').startsWith('image/');
            if (isImg) {
                return `<a href="${m.attachment_url}" target="_blank" rel="noopener"><img src="${m.attachment_url}" class="rounded-lg max-w-[200px] max-h-[200px] mb-1"></a>`;
            }
            return `<a href="${m.attachment_url}" target="_blank" rel="noopener" download class="flex items-center gap-1 text-blue-600 underline text-sm mb-1"><i class="fas fa-paperclip"></i> Download attachment</a>`;
        }

        function escapeLiveChatHtml(text) {
            const div = document.createElement('div');
            div.textContent = text ?? '';
            return div.innerHTML;
        }

        async function loadLiveChatMessages() {
            if (!liveChat.token) return;
            try {
                const res = await fetch(`api/sales/chat.php?action=v_poll&token=${encodeURIComponent(liveChat.token)}`);
                const data = await res.json();
                if (!data.success) return;
                (data.data.messages || []).forEach(m => { renderLiveChatMessage(m); trackAutoCloseTimer(m); liveChat.lastId = m.id; });
                if (data.data.status === 'active') {
                    showLiveChatInput();
                    startAutoCloseCountdown();
                    if (!liveChat.connectedMsgShown) {
                        liveChat.connectedMsgShown = true;
                        addBotMessage(`✅ Agent is connected — you're now live.`);
                    }
                }
                if (data.data.status === 'closed') exitLiveChatMode('closed');
                if (data.data.status === 'missed') exitLiveChatMode('missed');
            } catch (err) { /* keep polling */ }
        }

        async function pollLiveChat() {
            if (!liveChat.token || !liveChat.active) return;
            try {
                const res = await fetch(`api/sales/chat.php?action=v_poll&token=${encodeURIComponent(liveChat.token)}&since=${liveChat.lastId}`);
                const data = await res.json();
                if (!data.success) return;
                const wasWaiting = !liveChat.connectedMsgShown;
                (data.data.messages || []).forEach(m => { renderLiveChatMessage(m); trackAutoCloseTimer(m); liveChat.lastId = m.id; });
                if (data.data.status === 'active') {
                    showLiveChatInput();
                    startAutoCloseCountdown();
                    if (wasWaiting) {
                        liveChat.connectedMsgShown = true;
                        addBotMessage(`✅ Agent is connected — you're now live.`);
                    }
                }
                if (data.data.status === 'closed') { exitLiveChatMode('closed'); return; }
                if (data.data.status === 'missed') { exitLiveChatMode('missed'); return; }
                forceScrollToBottom();
            } catch (err) { /* keep polling */ }
        }

        async function sendLiveChatMessage() {
            const input = document.getElementById('liveChatInput');
            const text = input.value.trim();
            if (!text || !liveChat.token) return;
            input.value = '';
            input.style.height = 'auto';
            try {
                await fetch('api/sales/chat.php?action=v_post', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: liveChat.token, body: text })
                });
                await pollLiveChat();
            } catch (err) { /* ignore, next poll will catch up */ }
        }

        async function closeLiveChatAsClient() {
            if (!liveChat.token) return;
            if (!confirm('Are you sure you want to close this chat?')) return;
            try {
                await fetch('api/sales/chat.php?action=v_close', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: liveChat.token })
                });
            } catch (err) { /* ignore */ }
            exitLiveChatMode();
        }

        // Idempotent: a concurrent poll tick can also observe status='closed' around the same
        // moment closeLiveChatAsClient() (or the agent ending the chat) does — the active-flag
        // guard below stops that from double-printing the closing message.
        //
        // reason === 'missed': the queue-timeout sweep (api/sales/_automation_engine.php) already
        // pushed the "sorry, our agents are busy" apology as its own bot chat bubble before flipping
        // the session to 'missed', so here we just offer the follow-up choice instead of also
        // printing the generic "chat has been closed" line. Any other reason (visitor closed it,
        // agent closed it, or the 5-minute inactivity auto-close) uses that generic message.
        function exitLiveChatMode(reason) {
            if (!liveChat.active) return;
            stopLiveChatPolling();
            stopAutoCloseCountdown();
            liveChat.active = false;
            liveChat.token = null;
            liveChat.sessionId = null;
            liveChat.autoCloseDeadline = null;
            saveChatSession();

            document.getElementById('liveChatInputContainer').classList.add('hidden');
            document.getElementById('liveChatInputContainer').style.display = 'none';
            document.getElementById('chatInput').style.display = 'block';

            if (reason === 'missed') {
                chatState.awaitingNextRequestDecision = true;
                chatState.requestSelectionLocked = false;
                showQuickReplies([
                    { text: '🔄 Start Another Request', action: 'start-another' },
                    { text: "🚫 No, that's all", action: 'end' }
                ]);
            } else {
                showLiveChatClosedMessage(chatState.lastTicketRef);
            }
        }

        // Shown once, when a live chat ends — deliberately NOT the "ticket created" wording
        // (showTicketConfirmation) since no new ticket was just created here, just the
        // existing chat session closing.
        function showLiveChatClosedMessage(ticketRef) {
            addBotMessage(`💬 This chat has been closed. Reference: ${ticketRef || '—'}.`);
            chatState.awaitingNextRequestDecision = true;
            chatState.requestSelectionLocked = false;
            showQuickReplies([
                { text: '🔄 Start Another Request', action: 'start-another' },
                { text: "🚫 No, that's all", action: 'end' }
            ]);
        }

        // Ticket confirmation screen
        function showTicketConfirmation(type, ticketRef) {
            let message;
            if (type === 'sales') {
                message = `✅ Thank you for your sales enquiry! Your reference is ${ticketRef}. Our team will reach out to you shortly.`;
            } else if (type === 'callback') {
                message = `✅ Your callback request has been created. Reference: ${ticketRef}. Our team will reach out to you shortly.`;
            } else if (type === 'transaction') {
                message = `✅ Your issue has been registered! Reference: ${ticketRef}. Our team will reach out to you shortly.`;
            } else {
                message = `✅ Your request has been created. Reference: ${ticketRef}. Our team will reach out to you shortly.`;
            }

            addBotMessage(message);
            chatState.awaitingNextRequestDecision = true;
            chatState.requestSelectionLocked = false;
            showQuickReplies([
                { text: '🔄 Start Another Request', action: 'start-another' },
                { text: "🚫 No, that's all", action: 'end' }
            ]);
        }

        // UI FUNCTIONS
        function showQuickReplies(options) {
            const quickRepliesHTML = options.map(opt =>
                `<button onclick="handleQuickReply('${opt.action}')" class="px-4 py-2 bg-white border border-gray-300 rounded-full hover:bg-blue-50 hover:border-blue-500 transition-colors text-sm">
            ${opt.text}
        </button>`
            ).join('');
            document.getElementById('chatInput').innerHTML = `<div class="flex flex-wrap gap-2">${quickRepliesHTML}</div>`;
        }

        function handleQuickReply(action) {
            if (action === 'interested' || action === 'browsing') {
                handleInterest(action === 'interested');
            } else if (['sales', 'service', 'transaction', 'callback'].includes(action)) {
                if (chatState.awaitingNextRequestDecision) {
                    addBotMessage("Do you want another ticket? Please choose: 🔄 Start Another Request or 🚫 No, that's all.");
                    return;
                }
                if (chatState.requestSelectionLocked) {
                    return;
                }
                chatState.requestSelectionLocked = true;
                handleRequestType(action);
            } else if (action === 'start-another' || action === 'start-new-request') {
                handleStartAnotherRequest();
            } else if (action === 'end') {
                handleEndChat();
            } else if (action === 'resend-email-otp') {
                resendEmailOTP();
            } else if (action === 'close-chat') {
                handleEndChat();
            } else if (action === 'change-email') {
                chatState.formData.email = '';
                chatState.otpData.emailVerified = false;
                showEmailInput();
            } else if (action === 'change-mobile') {
                chatState.formData.mobile = '';
                chatState.otpData.mobileVerified = false;
                chatState.formData.email = '';
                chatState.otpData.emailVerified = false;
                showMobileInput();
            }
        }

        function handleEndChat() {
            addUserMessage("🚫 No, that's all");
            setTimeout(() => {
                addBotMessage("Thank you for contacting PayG! Have a great day! 😊");
                setTimeout(() => {
                    document.getElementById('chatInput').innerHTML = '';
                    clearChat();
                    clearChatSession();
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({ type: 'payg-close-chat' }, '*');
                    }
                }, 1000);
            }, 50);
        }

        function handleStartAnotherRequest() {
    addUserMessage("🔄 Start Another Request");

    setTimeout(() => {
        addBotMessage("Great! Let's create another request.");

        chatState.awaitingNextRequestDecision = false;
        chatState.requestSelectionLocked = false;

        // Reset request data
        chatState.formData.requestType = '';
        chatState.formData.issueDescription = '';
        chatState.lastTicketRef = null;

        // Reset uploaded attachment
        abortAttachmentUpload();
        chatState.formData.attachment = null;
        chatState.formData.attachmentUrl = '';

        chatState.attachmentUploadInProgress = false;
        chatState.attachmentUploadProgress = 0;
        chatState.attachmentUploadDisplayProgress = 0;
        chatState.attachmentUploadTargetProgress = 0;
        chatState.attachmentUploaded = false;
        chatState.attachmentUploadError = '';

        const attachmentInput = document.getElementById('attachmentInput');
        if (attachmentInput) {
            attachmentInput.value = '';
        }

        updateAttachmentUploadUI();

        showRequestTypeOptions();
    }, 20);
}

        // MESSAGE DISPLAY FUNCTIONS
        function addBotMessage(text) {
            const messagesDiv = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-bubble flex items-start gap-2 mb-2';
            messageDiv.innerHTML = `
        <div class="w-6 h-6 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="fas fa-robot text-white" style="font-size:9px;"></i>
        </div>
        <div class="bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-gray-800 shadow-sm" style="min-width:0; max-width:calc(100% - 2rem);">
            <p class="text-gray-800 text-sm">${text}</p>
        </div>`;
            messagesDiv.appendChild(messageDiv);
            scrollToBottom();
            forceScrollToBottom();
        }

        function addUserMessage(text) {
            const messagesDiv = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-bubble flex items-start justify-end gap-2 mb-2';
            messageDiv.innerHTML = `
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-2xl rounded-tr-sm px-3 py-2 text-sm" style="min-width:0; max-width:calc(100% - 2rem);">
            <p class="text-sm">${text}</p>
        </div>
        <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="fas fa-user text-purple-600" style="font-size:9px;"></i>
        </div>`;
            messagesDiv.appendChild(messageDiv);
            scrollToBottom();
            forceScrollToBottom();
        }

        function scrollToBottom() {
            const messagesDiv = document.getElementById('chatMessages');
            if (messagesDiv) messagesDiv.scrollTop = messagesDiv.scrollHeight;
            forceScrollToBottom();
        }

        function forceScrollToBottom() {
            const el = document.getElementById('chatMessages');
            if (!el) return;

            // run after DOM paint (twice = safer for dynamic height)
            requestAnimationFrame(() => {
                el.scrollTop = el.scrollHeight;
                requestAnimationFrame(() => {
                    el.scrollTop = el.scrollHeight;
                });
            });
        }
    </script>
</body>

</html>