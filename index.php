<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

payg_start_session();

function paygLandingForSession(array $sessionUser): string
{
    $role = $sessionUser['data']['role'] ?? ($sessionUser['user_type'] ?? '');

    return match ($role) {
        'admin' => 'sales-admin.php',
        'manager' => 'sales-dashboard.php',
        'sales_exec', 'employee' => 'sales-leads.php',
        'hr' => 'sales-timesheet.php',
        'it' => 'sales-integrations.php',
        default => 'sales-dashboard.php',
    };
}

if (!empty($_SESSION['user'])) {
    header('Location: ' . paygLandingForSession($_SESSION['user']));
    exit;
}

if (!(getDB() instanceof PDO)) {
    header('Location: sales-setup.php');
    exit;
}
// Guest (not logged in), DB ready. From here this one file serves TWO views:
//
//   https://aione.payg.in/            -> the public landing page (below)
//   https://aione.payg.in/index.php   -> the sign-in screen
//
// The switch is the URL the browser actually asked for. Hitting the domain root
// sends "/" and the web server picks index.php internally WITHOUT touching
// REQUEST_URI; a typed or linked "/index.php" arrives as exactly that. So the
// login screen gets its own clean URL with no query string, and no rewrite rules
// are needed in .htaccess / nginx-default.conf / web.config.
//
// basename() keeps this correct under a sub-folder too — /frontend/ is the
// landing page, /frontend/index.php is the sign-in screen.
// If REQUEST_URI is somehow unavailable, basename('') is '' and we fall through
// to the landing page, i.e. the safe, pre-existing behaviour.
$paygRequestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$paygWantsLogin  = basename($paygRequestPath) === 'index.php'
    || isset($_GET['login']);   // ?login=1 kept as an alias for older links

if ($paygWantsLogin) {
    require __DIR__ . '/includes/login-page.php';
    exit;
}

// Asked for the directory ("/") -> the marketing homepage below, exactly as before.
?>
<!--
  =============================================
  PayG SuperChat CRM Platform
  Created by Superchat | https://superchat.in
  =============================================
-->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayG SuperChat CRM - Home</title>
    <link rel="icon" type="image/png" href="./public/images/PayG-Dark.svg">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            overflow: hidden; /* 🚫 Global scrollbar removal */
        }
        #main-wrapper {
            height: 100vh;
            overflow-y: auto; /* ✅ Internal scrolling */
            scroll-behavior: smooth;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div id="main-wrapper">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <div class="w-20 h-20 flex items-center justify-center">
                        <img src="public/images/PayG-Dark.svg" alt="PayG Logo"
                            class="w-full h-full object-contain" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">CRM</h1>
                        <p class="text-sm text-gray-600">Multi-Channel Customer Support Platform</p>
                    </div>
                </div>
                <a href="index.php"
                    class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-20">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <h2 class="text-5xl font-bold mb-6">Complete CRM Solution</h2>
            <p class="text-xl mb-8 opacity-90">AI-Powered Multi-Channel Customer Support System</p>
            <div class="flex justify-center space-x-4">
                <a href="index.php"
                    class="bg-white text-purple-600 px-8 py-3 rounded-lg font-semibold hover:bg-gray-100 transition">
                    Get Started
                </a>
                <a href="#features"
                    class="border-2 border-white text-white px-8 py-3 rounded-lg font-semibold hover:bg-white hover:text-purple-600 transition">
                    Learn More
                </a>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div id="features" class="max-w-7xl mx-auto px-4 py-16">
        <h3 class="text-3xl font-bold text-center text-gray-800 mb-12">Key Features</h3>

        <div class="grid md:grid-cols-3 gap-8">
            <!-- Feature 1 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-blue-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-comments text-blue-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Multi-Channel Support</h4>
                <p class="text-gray-600">Handle customer inquiries across Chat, Calls, Email, and WhatsApp - all in one
                    unified platform.</p>
            </div>

            <!-- Feature 2 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-robot text-purple-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">AI-Powered Routing</h4>
                <p class="text-gray-600">Intelligent keyword-based routing automatically assigns tickets to the right
                    team with 85-90% accuracy.</p>
            </div>

            <!-- Feature 3 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-green-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-clock text-green-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">SLA Management</h4>
                <p class="text-gray-600">Automatic SLA calculation and tracking ensures timely responses and resolution
                    of customer issues.</p>
            </div>

            <!-- Feature 4 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-yellow-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-users text-yellow-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Team Dashboards</h4>
                <p class="text-gray-600">Separate dashboards for Sales, Service, Technical, and Accounts teams with
                    focused workflows.</p>
            </div>

            <!-- Feature 5 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-indigo-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-user-circle text-indigo-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Customer 360°</h4>
                <p class="text-gray-600">Complete customer view with unified interaction history across all channels and
                    touchpoints.</p>
            </div>

            <!-- Feature 6 -->
            <div class="bg-white p-6 rounded-xl shadow-lg hover:shadow-xl transition">
                <div class="bg-red-100 w-16 h-16 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-chart-line text-red-600 text-2xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Analytics & Reports</h4>
                <p class="text-gray-600">Comprehensive reporting with agent performance, SLA compliance, and system
                    uptime metrics.</p>
            </div>
        </div>
    </div>

    <!-- Channels Section -->
    <div class="bg-gray-100 py-16">
        <div class="max-w-7xl mx-auto px-4">
            <h3 class="text-3xl font-bold text-center text-gray-800 mb-12">Supported Channels</h3>

            <div class="grid md:grid-cols-4 gap-6">
                <div class="bg-white p-6 rounded-xl text-center shadow-lg">
                    <i class="fas fa-comments text-blue-600 text-4xl mb-3"></i>
                    <h4 class="font-bold text-gray-800 mb-2">Live Chat</h4>
                    <p class="text-sm text-gray-600">AI-powered chat widget</p>
                </div>
                <div class="bg-white p-6 rounded-xl text-center shadow-lg">
                    <i class="fas fa-phone text-green-600 text-4xl mb-3"></i>
                    <h4 class="font-bold text-gray-800 mb-2">Voice Calls</h4>
                    <p class="text-sm text-gray-600">RBI-compliant 1600XXX</p>
                </div>
                <div class="bg-white p-6 rounded-xl text-center shadow-lg">
                    <i class="fas fa-envelope text-purple-600 text-4xl mb-3"></i>
                    <h4 class="font-bold text-gray-800 mb-2">Email</h4>
                    <p class="text-sm text-gray-600">Unified inbox & threading</p>
                </div>
                <div class="bg-white p-6 rounded-xl text-center shadow-lg">
                    <i class="fab fa-whatsapp text-green-500 text-4xl mb-3"></i>
                    <h4 class="font-bold text-gray-800 mb-2">WhatsApp</h4>
                    <p class="text-sm text-gray-600">Chat & Voice calls</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="max-w-7xl mx-auto px-4 py-16">
        <div class="grid md:grid-cols-4 gap-8 text-center">
            <div>
                <div class="text-4xl font-bold text-purple-600 mb-2">27</div>
                <div class="text-gray-600">Complete Screens</div>
            </div>
            <div>
                <div class="text-4xl font-bold text-indigo-600 mb-2">30+</div>
                <div class="text-gray-600">API Endpoints</div>
            </div>
            <div>
                <div class="text-4xl font-bold text-blue-600 mb-2">20+</div>
                <div class="text-gray-600">Database Tables</div>
            </div>
            <div>
                <div class="text-4xl font-bold text-green-600 mb-2">4</div>
                <div class="text-gray-600">Specialized Teams</div>
            </div>
        </div>
    </div>

    <!-- CTA Section -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-16">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <h3 class="text-3xl font-bold mb-4">Ready to Transform Your Customer Support?</h3>
            <p class="text-xl mb-8 opacity-90">Get started with PayG SuperChat CRM today</p>
            <a href="index.php"
                class="inline-block bg-white text-purple-600 px-8 py-3 rounded-lg font-semibold hover:bg-gray-100 transition">
                <i class="fas fa-rocket mr-2"></i>Launch CRM
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-8">
        <div class="max-w-7xl mx-auto px-4">
            <div class="grid md:grid-cols-4 gap-8">
                <div>
                    <h4 class="font-bold mb-4">PayG SuperChat CRM</h4>
                    <p class="text-sm text-gray-400">Complete multi-channel customer support platform with AI-powered
                        routing.</p>
                </div>
                <div>
                    <h4 class="font-bold mb-4">Features</h4>
                    <ul class="text-sm text-gray-400 space-y-2">
                        <li>Multi-Channel Support</li>
                        <li>AI Routing</li>
                        <li>SLA Management</li>
                        <li>Team Dashboards</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4">Support</h4>
                    <ul class="text-sm text-gray-400 space-y-2">
                        <li>Email: Care@payg.in</li>
                        <li>Service: CSD@payg.in</li>
                        <li>Helpline: 1600XXX</li>
                        <li>Knowledge Base</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4">Quick Links</h4>
                    <ul class="text-sm text-gray-400 space-y-2">
                        <li><a href="index.php" class="hover:text-white">Login</a></li>
                        <li><a href="#" class="hover:text-white">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-700 mt-8 pt-8 text-center text-sm text-gray-400">
                © 2026 PayG SuperChat CRM. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- Chat Icon & Widget -->
    <div id="payg-chat-widget-btn" class="fixed bottom-6 right-6 z-50">
        <button onclick="toggleChatWidget()"
            class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-full shadow-lg w-20 h-20 flex items-center justify-center text-3xl hover:scale-110 transition">
            <i class="fas fa-comment-dots"></i>
        </button>
    </div>
    <div id="payg-chat-widget"
        class="fixed bottom-6 right-6 z-50 bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden transition-all duration-300"
        style="width:0;height:0;opacity:0;pointer-events:none;">
        <div class="flex flex-col h-full w-full">

<div
  class="flex items-center justify-between bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-4 py-2">
  <span class="font-semibold">PayG Chat</span>

  <!-- ✅ Right side buttons -->
  <div class="flex items-center gap-3">
    <!-- Home button (smaller icon) -->
    <button
      onclick="onHomeClick()"
      class="text-white text-sm hover:text-gray-200"
      title="Home">
      <i class="fas fa-home"></i>
    </button>

    <!-- Close button -->
    <button
      onclick="toggleChatWidget()"
      class="text-white text-xl hover:text-gray-200"
      title="Close">
      &times;
    </button>
  </div>
</div>


            <iframe src="chat.php" class="flex-1 w-full border-0" style="height:calc(100% - 40px);"></iframe>
        </div>
    </div>
    <script>
    let widgetOpen = false;

    function toggleChatWidget() {
        const widget = document.getElementById('payg-chat-widget');
        widgetOpen = !widgetOpen;
        if (widgetOpen) {
            widget.style.width = '500px';
            widget.style.height = '89vh';
            widget.style.opacity = '1';
            widget.style.pointerEvents = 'auto';
        } else {
            resetChatWidgetIframe();
            widget.style.width = '0';
            widget.style.height = '0';
            widget.style.opacity = '0';
            widget.style.pointerEvents = 'none';
        }
    }

    // Expose a function for iframe to close the widget
    function closePayGChatWidget() {
        const widget = document.getElementById('payg-chat-widget');
        resetChatWidgetIframe();
        widget.style.width = '0';
        widget.style.height = '0';
        widget.style.opacity = '0';
        widget.style.pointerEvents = 'none';
        widgetOpen = false;
    }

    function resetChatWidgetIframe() {
        const iframe = document.querySelector('#payg-chat-widget iframe');
        if (iframe) {
            iframe.src = `chat.php?refresh=${Date.now()}`;
        }
    }

    function onHomeClick() {
    const iframe = document.querySelector('#payg-chat-widget iframe');
    if (!iframe) return;

    // ✅ reload chat page with a flag to reset + show welcome only
    iframe.src = `chat.php?home=1&refresh=${Date.now()}`;
  }

    // Let the iframed chat.php ask us to close the widget (e.g. "No, that's all").
    window.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'payg-close-chat') {
            closePayGChatWidget();
        }
    });

    // Ensure only one widget button and widget exist
    document.querySelectorAll('#payg-chat-widget-btn').forEach((btn, i) => {
        if (i > 0) btn.remove();
    });
    document.querySelectorAll('#payg-chat-widget').forEach((w, i) => {
        if (i > 0) w.remove();
    });

    </script>

    </div> <!-- End main-wrapper -->
</body>

</html>
