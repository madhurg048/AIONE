<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

function run_tests() {
    $base_url = "http://localhost/paygfinal/";
    
    $users = [
        ['username' => 'admin', 'password' => 'admin123'],
        ['username' => 'manager', 'password' => 'manager123'],
        ['username' => 'asha', 'password' => 'asha123']
    ];

    $pages = [
        "sales-dashboard.php",
        "sales-leads.php",
        "sales-pipeline.php",
        "sales-merchants.php",
        "sales-incentives.php",
        "sales-transactions.php",
        "sales-reimbursements.php",
        "sales-scorecards.php",
        "sales-reports.php",
        "sales-admin.php"
    ];

    $apis = [
        "api/sales/leads.php?action=list",
        "api/sales/deals.php?action=list",
        "api/sales/merchants.php?action=list",
        "api/sales/incentives.php?action=list",
        "api/sales/dashboard-kpis.php?action=overview",
        "api/sales/reports.php?action=funnel",
        "api/sales/followups.php?action=list",
        "api/sales/transactions.php?action=list",
        "api/sales/reimbursements.php?action=list"
    ];

    $report = [];

    foreach ($users as $u) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $cookie_file = sys_get_temp_dir() . "/cookie_" . $u['username'] . ".txt";
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        
        // 1. Get CSRF Token
        curl_setopt($ch, CURLOPT_URL, $base_url . "api/sales/auth.php?action=csrf");
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $resp = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($resp, $header_size);
        $data = json_decode($body, true);
        $csrf = $data['data']['csrf'] ?? '';
        
        // 2. Login
        curl_setopt($ch, CURLOPT_URL, $base_url . "api/sales/auth.php?action=login");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $u['username'], 'password' => $u['password']]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $csrf
        ]);
        $resp = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($resp, $header_size);
        
        $login_result = json_decode($body, true);
        if (!$login_result || empty($login_result['success'])) {
            $report[] = "Failed to login as " . $u['username'] . ": " . $body;
            continue;
        }

        // Test Pages
        foreach ($pages as $p) {
            curl_setopt($ch, CURLOPT_URL, $base_url . $p);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, []); // remove post headers
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($resp, $header_size);
            
            $errors = [];
            if ($code != 200) $errors[] = "HTTP $code";
            if (empty(trim($body))) $errors[] = "Blank page";
            if (preg_match('/(Fatal error|Parse error|Warning|Notice):/i', $body, $matches)) {
                $errors[] = "PHP Error found: " . $matches[1];
            }
            if (!empty($errors)) {
                $report[] = "[{$u['username']}] Page $p issues: " . implode(', ', $errors);
            } else {
                $report[] = "[{$u['username']}] Page $p OK";
            }
        }
        
        // Test APIs
        foreach ($apis as $a) {
            curl_setopt($ch, CURLOPT_URL, $base_url . $a);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, []);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($resp, $header_size);
            
            $errors = [];
            if ($code != 200) $errors[] = "HTTP $code";
            if (empty(trim($body))) {
                $errors[] = "Blank response";
            } else {
                $json = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = "Invalid JSON";
                    if (preg_match('/(Fatal error|Parse error|Warning|Notice):/i', $body, $matches)) {
                        $errors[] = "PHP Error found: " . $matches[1];
                    }
                }
            }
            
            if (!empty($errors)) {
                $report[] = "[{$u['username']}] API $a issues: " . implode(', ', $errors) . " (Snippet: " . substr($body, 0, 100) . "...)";
            } else {
                $report[] = "[{$u['username']}] API $a OK";
            }
        }
        
        curl_close($ch);
    }
    
    echo implode("\n", $report);
}

run_tests();
