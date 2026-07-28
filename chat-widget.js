/**
 * Created by Superchat | https://superchat.in
 */

(function () {
    'use strict';

    // Widget configuration
    const config = {
        apiUrl: '/api',
        primaryColor: '#667eea',
        botName: 'PayG Assistant',
        welcomeMessage: 'Hi! ?? I\'m your PayG support assistant. How can I help you today?',
        position: 'right' // 'right' or 'left'
    };

    // AI Knowledge Base
    const aiKnowledge = {
        pricing: {
            keywords: ['price', 'pricing', 'cost', 'plan', 'subscription', 'fee', 'charge', 'rate'],
            responses: [
                "Great question about our pricing! We offer flexible plans to suit businesses of all sizes.",
                "Our pricing is transparent and competitive. We have:",
                " Starter Plan: Perfect for small businesses",
                " Business Plan: Most popular for growing companies",
                " Enterprise Plan: Custom solutions for large organizations",
                "",
                "Would you like me to connect you with our sales team for detailed pricing?"
            ]
        },
        api: {
            keywords: ['api', 'integration', 'developer', 'code', 'sdk', 'webhook', 'technical', 'documentation'],
            responses: [
                "I can help with API integration questions!",
                "",
                "Our API provides:",
                " RESTful endpoints for all operations",
                " Comprehensive documentation",
                " SDKs for popular languages",
                " Webhook support for real-time updates",
                "",
                "Would you like me to connect you with our technical team?"
            ]
        },
        payment: {
            keywords: ['payment', 'transaction', 'refund', 'invoice', 'billing', 'settlement', 'reconciliation'],
            responses: [
                "I understand payment-related questions are important.",
                "",
                "I can help with:",
                " Payment methods and processing",
                " Transaction status inquiries",
                " Refund requests",
                " Invoice and billing questions",
                "",
                "Let me connect you with our accounts team for immediate assistance."
            ]
        },
        support: {
            keywords: ['help', 'support', 'issue', 'problem', 'not working', 'error', 'question'],
            responses: [
                "I'm here to help! Let me understand your issue better.",
                "",
                "Our support team can assist with:",
                " Account access issues",
                " Technical troubleshooting",
                " Feature guidance",
                " General inquiries",
                "",
                "Would you like me to create a support ticket or connect you with an agent?"
            ]
        },
        callback: {
            keywords: ['callback', 'call back', 'call me', 'phone', 'contact', 'speak', 'talk'],
            responses: [
                "I'd be happy to arrange a callback for you!",
                "",
                "Please provide:",
                " Your preferred phone number",
                " Best time to call",
                " Reason for callback",
                "",
                "Our team typically responds within 30 minutes during business hours."
            ]
        },
        greeting: {
            keywords: ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening'],
            responses: [
                "Hello! ?? Welcome to PayG Support!",
                "",
                "I can help you with:",
                " Product information and pricing",
                " Technical support and API questions",
                " Payment and billing inquiries",
                " Account assistance",
                "",
                "What brings you here today?"
            ]
        },
        thanks: {
            keywords: ['thank', 'thanks', 'appreciate'],
            responses: [
                "You're very welcome! ??",
                "",
                "Is there anything else I can help you with today?"
            ]
        }
    };

    // Chat state
    let chatState = {
        isOpen: false,
        messages: [],
        customerInfo: null,
        sessionId: null,
        otpSent: false,
        otpVerified: false,
        ticketNumber: null
    };

    // Create widget HTML
    function createWidget() {
        const widgetHTML = `
        <!-- Chat Icon -->
        <div id="payg-chat-widget-btn" style="
            position:fixed;
            bottom:24px;
            right:24px;
            z-index:999999;
        ">
            <button id="paygWidgetToggle"
                style="
                background:linear-gradient(135deg,#7c3aed,#4f46e5);
                color:white;
                border:none;
                width:70px;
                height:70px;
                border-radius:50%;
                font-size:28px;
                cursor:pointer;
                box-shadow:0 8px 20px rgba(0,0,0,0.2);
                ">
                <i class="fas fa-comment-dots"></i>
            </button>
        </div>


        <!-- Chat Widget -->
        <div id="payg-chat-widget"
            style="
            position:fixed;
            bottom:24px;
            right:24px;
            z-index:999998;
            background:white;
            border-radius:12px;
            box-shadow:0 20px 40px rgba(0,0,0,0.15);
            border:1px solid #e5e7eb;
            overflow:hidden;
            width:0;
            height:0;
            opacity:0;
            pointer-events:none;
            transition:all .3s;
            ">

            <div style="display:flex;flex-direction:column;height:100%;width:100%">

                <!-- Header -->
                <div style="
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    background:linear-gradient(135deg,#7c3aed,#4f46e5);
                    color:white;
                    padding:2px 10px;
                ">
                    <span style="font-weight:600; font-size:13px;">PayG Chat</span>
                    <div style="display:flex;gap:10px">
                        <button id="paygHomeBtn" style="
                            background:none;
                            border:none;
                            color:white;
                            cursor:pointer;
                            font-size:12px;
                        ">
                            <i class="fas fa-home"></i>
                        </button>
                        <button id="paygCloseBtn" style="
                            background:none;
                            border:none;
                            color:white;
                            font-size:15px;
                            cursor:pointer;
                        ">
                            &times;
                        </button>
                    </div>
                </div>

                <!-- Chat iframe -->
                <iframe
                    id="paygChatIframe"
                    src="https://crm.payg-india.com/payg/chat.php"
                    style="
                    width:100%;
                    height:100%;
                    border:none;
                    flex:1;
                    ">
                </iframe>

            </div>

        </div>
        `;

        // Add CSS for quick replies
        const style = document.createElement('style');
        style.textContent = `
            .payg-quick-reply {
                padding: 8px 14px;
                background: #f3f4f6;
                border: 1px solid #e5e7eb;
                border-radius: 16px;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .payg-quick-reply:hover {
                background: ${config.primaryColor};
                color: white;
                border-color: ${config.primaryColor};
            }
            #paygChatButton:hover {
                transform: scale(1.05);
                box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            }
            #paygMessages::-webkit-scrollbar {
                width: 6px;
            }
            #paygMessages::-webkit-scrollbar-track {
                background: #f1f1f1;
            }
            #paygMessages::-webkit-scrollbar-thumb {
                background: #888;
                border-radius: 3px;
            }
            #paygMessages::-webkit-scrollbar-thumb:hover {
                background: #555;
            }
        `;
        document.head.appendChild(style);

        // Insert widget into page
        const container = document.createElement('div');
        container.innerHTML = widgetHTML;
        document.body.appendChild(container);

        attachEventListeners();

        // Auto-open chat widget when page loads
        setTimeout(() => {
            const toggleBtn = document.getElementById('paygWidgetToggle');
            if (toggleBtn) toggleBtn.click();
        }, 500);
    }

    function attachEventListeners() {
        const toggleBtn = document.getElementById('paygWidgetToggle');
        const widget = document.getElementById('payg-chat-widget');
        const closeBtn = document.getElementById('paygCloseBtn');
        const homeBtn = document.getElementById('paygHomeBtn');
        const iframe = document.getElementById('paygChatIframe');

        let widgetOpen = false;
        function calcWidgetSize() {
            const vw = window.visualViewport ? window.visualViewport.width : window.innerWidth;
            const vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            let w, h, b, r;
            if (vw <= 500) {
                const margin = 8;
                w = vw - margin * 2;
                h = Math.min(vh * 0.72, 600); // 72% of viewport, max 600px
                b = margin;
                r = margin;
            } else {
                const margin = 24;
                w = Math.min(390, vw - margin * 2);
                h = vh - margin * 7.5;
                b = margin;
                r = margin;
                if (vh < 800) { h = vh * 0.75; }
            }
            return { w, h, b, r };
        }

        let lastSize = { w: 0, h: 0, b: 0, r: 0 };
        let resizeRafId = null;
        function applyWidgetSize() {
            if (!widgetOpen) return;
            const { w, h, b, r } = calcWidgetSize();
            // Skip no-op writes: the chat iframe has its own visualViewport listener
            // reacting to the same events (e.g. an auto-growing textarea resizing on
            // every keystroke). Writing here on every tick fed a resize feedback loop
            // between parent and iframe that looked like flicker inside the widget.
            if (Math.abs(w - lastSize.w) < 4 && Math.abs(h - lastSize.h) < 4 &&
                Math.abs(b - lastSize.b) < 4 && Math.abs(r - lastSize.r) < 4) return;
            lastSize = { w, h, b, r };
            widget.style.width = w + 'px';
            widget.style.height = h + 'px';
            widget.style.bottom = b + 'px';
            widget.style.right = r + 'px';
        }

        function scheduleApplyWidgetSize() {
            if (resizeRafId) return;
            resizeRafId = requestAnimationFrame(() => {
                resizeRafId = null;
                applyWidgetSize();
            });
        }

        window.addEventListener('resize', scheduleApplyWidgetSize);
        window.addEventListener('orientationchange', scheduleApplyWidgetSize);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', scheduleApplyWidgetSize);
            window.visualViewport.addEventListener('scroll', scheduleApplyWidgetSize);
        }

        // Collapse the widget to 0x0 and reset the size cache so the next open
        // always re-applies a real size (the no-op guard in applyWidgetSize()
        // otherwise sees the size as "unchanged" and skips the write).
        function collapseWidget() {
            lastSize = { w: 0, h: 0, b: 0, r: 0 };
            widget.style.width = '0';
            widget.style.height = '0';
            widget.style.opacity = '0';
            widget.style.pointerEvents = 'none';
        }

        toggleBtn.addEventListener('click', () => {
            widgetOpen = !widgetOpen;
            if (widgetOpen) {
                if (window.gtag) gtag('event', 'chatbot_launcher_clicked', { event_category: 'chatbot' });
                applyWidgetSize();
                widget.style.opacity = '1';
                widget.style.pointerEvents = 'auto';
                // ? Hide chat button
                document.getElementById('payg-chat-widget-btn').style.display = 'none';
            } else {
                resetIframe();
                collapseWidget();
                // ? Show button again
                document.getElementById('payg-chat-widget-btn').style.display = 'block';
            }
        });
        closeBtn.addEventListener('click', () => {
            resetIframe();
            collapseWidget();
            document.getElementById('payg-chat-widget-btn').style.display = 'block';
            widgetOpen = false;

        });
        homeBtn.addEventListener('click', () => {
            iframe.src = "https://crm.payg-india.com/payg/chat.php?home=1&refresh=" + Date.now();
        });
        function resetIframe() {
            const iframe = document.getElementById('paygChatIframe');
            if (iframe) {
                iframe.src = "https://crm.payg-india.com/payg/chat.php?refresh=" + Date.now();
            }
        }
    }

    // Listen for close message from chat iframe
    window.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'payg-close-chat') {
            // Route through the close button so the shared close logic runs
            // (resets the size cache + widgetOpen flag; otherwise the widget
            // cannot be reopened afterwards).
            const closeBtn = document.getElementById('paygCloseBtn');
            if (closeBtn) {
                closeBtn.click();
            } else {
                const widget = document.getElementById('payg-chat-widget');
                if (widget) {
                    widget.style.width = '0';
                    widget.style.height = '0';
                    widget.style.opacity = '0';
                    widget.style.pointerEvents = 'none';
                }
                const btn = document.getElementById('payg-chat-widget-btn');
                if (btn) btn.style.display = 'block';
            }
        }

        // Forward analytics events from chat iframe to parent page's GTM dataLayer
        if (event.data && event.data.type === 'payg_analytics' && event.data.event) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: event.data.event });
        }
    });

    function toggleChat() {
        const chatWindow = document.getElementById('paygChatWindow');
        const iframe = document.getElementById('paygChatIframe')
        // If chat is open and user clicks close
        if (chatState.isOpen) {
            // ?? refresh iframe before closing
            if (iframe) {
                iframe.src = "https://crm.payg-india.com/payg/chat.php?refresh=" + Date.now();
            }
            chatWindow.style.display = 'none';
            chatState.isOpen = false;
        } else {
            chatWindow.style.display = 'flex';
            chatState.isOpen = true;
        }
    }

    async function handleStartChat() {
        const name = document.getElementById('paygName').value.trim();
        const email = document.getElementById('paygEmail').value.trim();
        const mobile = document.getElementById('paygMobile').value.trim();
        const business = document.getElementById('paygBusiness').value.trim();

        // Validate required fields
        if (!name || !email || !mobile) {
            alert('Please fill in all required fields');
            return;
        }

        // Validate email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            alert('Please enter a valid email address');
            return;
        }

        // Validate mobile (Indian format)
        const mobileRegex = /^(\+91)?[6-9]\d{9}$/;
        if (!mobileRegex.test(mobile.replace(/\s/g, ''))) {
            alert('Please enter a valid Indian mobile number');
            return;
        }

        // Store customer info
        chatState.customerInfo = { name, email, mobile, business };
        chatState.sessionId = 'sess_' + Date.now();

        // Send OTP verification
        await sendOTPForChat(email, mobile);

        // Hide form, show chat
        document.getElementById('paygCustomerForm').style.display = 'none';
        document.getElementById('paygInputContainer').style.display = 'block';
        document.getElementById('paygQuickReplies').style.display = 'block';

        // Show welcome message
        addBotMessage(`Thank you, ${name}, for providing your details. ??\n\n${config.welcomeMessage}\n\nFor your security, we have sent a One-Time Password (OTP) to your email and mobile number. Kindly type 'verify' to enter your OTP and continue.`);
        showQuickReplies();
    }

    async function sendOTPForChat(email, mobile) {
        try {
            // Send OTP to both email and mobile
            const response = await fetch('/api/otp/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    identifier: email,
                    type: 'email',
                    mobile: mobile
                })
            });
            const data = await response.json();
            if (data.success) {
                console.log('OTP sent successfully');
                chatState.otpSent = true;
            }
        } catch (error) {
            console.error('Error sending OTP:', error);
        }
    }

    async function sendMessage() {
        const input = document.getElementById('paygInput');
        const message = input.value.trim();

        if (!message) return;

        // Add user message
        addUserMessage(message);
        input.value = '';

        // Check for OTP verification request
        if (message.toLowerCase().includes('verify') && chatState.otpSent && !chatState.otpVerified) {
            showOTPVerification();
            return;
        }

        // Check if message indicates needing a ticket
        const needsTicket = checkIfNeedsTicket(message);

        // Get AI response
        setTimeout(async () => {
            const response = getAIResponse(message);
            addBotMessage(response);

            // Auto-create ticket if needed
            if (needsTicket && chatState.otpVerified) {
                await autoCreateTicket(message);
            } else if (needsTicket && !chatState.otpVerified) {
                addBotMessage('?? To create a support ticket, please verify your contact information first. Type "verify" to enter your OTP.');
            }
        }, 500);
    }

    function checkIfNeedsTicket(message) {
        const ticketKeywords = [
            'issue', 'problem', 'not working', 'error', 'bug', 'help',
            'support', 'urgent', 'broken', 'failed', 'cannot', 'unable',
            'ticket', 'assistance', 'technical', 'payment', 'refund'
        ];
        const messageLower = message.toLowerCase();
        return ticketKeywords.some(keyword => messageLower.includes(keyword));
    }

    function showOTPVerification() {
        const messagesDiv = document.getElementById('paygMessages');
        const otpFormHTML = `
            <div style="display: flex; justify-content: flex-start; margin-bottom: 8px;">
                <div style="
                    background: white;
                    border: 2px solid ${config.primaryColor};
                    padding: 16px;
                    border-radius: 12px;
                    max-width: 80%;
                ">
                    <div style="font-weight: 600; margin-bottom: 8px; color: #1f2937;">?? OTP Verification</div>
                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 8px;">
                        Kindly enter the 6-digit OTP sent to your registered email and mobile number to proceed.
                    </div>
                    <input type="text" id="paygOTPInput" placeholder="Enter 6-digit OTP" maxlength="6" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #d1d5db;
                        border-radius: 6px;
                        margin-bottom: 10px;
                        font-size: 16px;
                        letter-spacing: 4px;
                        text-align: center;
                    ">
                    <button onclick="verifyOTPFromChat()" style="
                        width: 100%;
                        padding: 10px;
                        background: ${config.primaryColor};
                        color: white;
                        border: none;
                        border-radius: 6px;
                        font-weight: 600;
                        cursor: pointer;
                    ">Verify OTP</button>
                    <div style="font-size: 11px; color: #9ca3af; margin-top: 8px; text-align: center;">
                        Demo OTP: 123456
                    </div>
                </div>
            </div>
        `;
        messagesDiv.insertAdjacentHTML('beforeend', otpFormHTML);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    async function verifyOTPFromChat() {
        const otpCode = document.getElementById('paygOTPInput').value.trim();

        if (!otpCode || otpCode.length !== 6) {
            alert('Please enter a valid 6-digit OTP');
            return;
        }

        try {
            const response = await fetch('/api/otp/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    identifier: chatState.customerInfo.email,
                    otp_code: otpCode
                })
            });

            const data = await response.json();

            if (data.success || otpCode === '123456') { // Demo OTP
                chatState.otpVerified = true;
                addBotMessage('? Thank you for verifying your contact information. You may now create support tickets and access all available features. How may I assist you further?');
            } else {
                addBotMessage('? The OTP entered is invalid. Kindly try again or type "verify" to request a new OTP.');
            }
        } catch (error) {
            console.error('Error verifying OTP:', error);
            addBotMessage('?? Error verifying OTP. Please try again.');
        }
    }

    async function autoCreateTicket(message) {
        try {
            // Analyze message with AI routing
            const analysisResponse = await fetch('/api/routing/analyze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: message })
            });
            const analysis = await analysisResponse.json();

            // Create ticket
            const ticketData = {
                channel: 'chat',
                customer: chatState.customerInfo,
                subject: message.substring(0, 100), // First 100 chars as subject
                description: message,
                priority: determinePriority(message),
                assigned_team_id: getTeamIdFromAnalysis(analysis),
                otp_verified: chatState.otpVerified,
                session_id: chatState.sessionId,
                chat_history: chatState.messages
            };

            const response = await fetch('/api/tickets', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(ticketData)
            });

            const data = await response.json();

            if (data.success) {
                chatState.ticketNumber = data.ticket.ticket_number;
                addBotMessage(
                    `?? Thank you for sharing the details. Your support ticket has been created successfully!\n\nTicket Number: <strong>${data.ticket.ticket_number}</strong>\n\nOur ${analysis.analysis?.suggested_team || 'support'} team will respond within ${getSLATime(ticketData.priority)}.\n\nIf you have any further queries or require additional assistance, please let us know.`
                );
            } else {
                addBotMessage('?? We apologize, but there was an issue creating your ticket. A support representative will reach out to you shortly.');
            }
        } catch (error) {
            console.error('Error creating ticket:', error);
            addBotMessage('?? I encountered an issue creating your ticket. A support agent will assist you shortly.');
        }
    }

    function determinePriority(message) {
        const messageLower = message.toLowerCase();
        if (messageLower.includes('urgent') || messageLower.includes('critical') || messageLower.includes('down')) {
            return 'critical';
        } else if (messageLower.includes('important') || messageLower.includes('high')) {
            return 'high';
        } else if (messageLower.includes('when you can') || messageLower.includes('whenever')) {
            return 'low';
        }
        return 'medium';
    }

    function getTeamIdFromAnalysis(analysis) {
        const teamMap = {
            'Sales': '1',
            'Service': '2',
            'Technical': '3',
            'Accounts': '4'
        };
        return teamMap[analysis.analysis?.suggested_team] || null;
    }

    function getSLATime(priority) {
        const slaMap = {
            'critical': '1 hour',
            'high': '4 hours',
            'medium': '24 hours',
            'low': '48 hours'
        };
        return slaMap[priority] || '24 hours';
    }

    // Make verifyOTPFromChat globally accessible
    window.verifyOTPFromChat = verifyOTPFromChat;

    function getAIResponse(message) {
        const messageLower = message.toLowerCase();

        // Check each knowledge category
        for (const [category, data] of Object.entries(aiKnowledge)) {
            for (const keyword of data.keywords) {
                if (messageLower.includes(keyword)) {
                    return data.responses.join('\n');
                }
            }
        }

        // Default response
        return "Thank you for reaching out to PayG Support. I will connect you with the appropriate team member to assist you further.\n\nWould you like me to:\n Create a support ticket for you\n Request a callback from our team\n Connect you with a live agent\n\nPlease let me know how I may assist you.";
    }

    function addUserMessage(message) {
        const messagesDiv = document.getElementById('paygMessages');
        const messageHTML = `
            <div style="display: flex; justify-content: flex-end; margin-bottom: 8px;">
                <div style="
                    background: ${config.primaryColor};
                    color: white;
                    padding: 10px 12px;
                    border-radius: 16px 16px 4px 16px;
                    max-width: 70%;
                    font-size: 13px;
                    line-height: 1.4;
                ">${escapeHtml(message)}</div>
            </div>
        `;
        messagesDiv.insertAdjacentHTML('beforeend', messageHTML);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;

        chatState.messages.push({ type: 'user', message, timestamp: new Date() });
    }

    function addBotMessage(message) {
        const messagesDiv = document.getElementById('paygMessages');
        const formattedMessage = message.split('\n').map(line =>
            line.trim() ? `<div style="margin-bottom: ${line.startsWith('') ? '4px' : '8px'}">${escapeHtml(line)}</div>` : ''
        ).join('');

        const messageHTML = `
            <div style="display: flex; justify-content: flex-start; margin-bottom: 8px;">
                <div style="
                    background: white;
                    border: 1px solid #e5e7eb;
                    color: #1f2937;
                    padding: 10px 12px;
                    border-radius: 16px 16px 16px 4px;
                    max-width: 70%;
                    font-size: 13px;
                    line-height: 1.4;
                ">${formattedMessage}</div>
            </div>
        `;
        messagesDiv.insertAdjacentHTML('beforeend', messageHTML);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;

        chatState.messages.push({ type: 'bot', message, timestamp: new Date() });
    }

    function showQuickReplies() {
        document.getElementById('paygQuickReplies').style.display = 'block';
    }

    function showNotificationBadge() {
        const badge = document.querySelector('.payg-notification-badge');
        if (badge) badge.style.display = 'flex';
    }

    function hideNotificationBadge() {
        const badge = document.querySelector('.payg-notification-badge');
        if (badge) badge.style.display = 'none';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize widget when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createWidget);
    } else {
        createWidget();
    }
})();
