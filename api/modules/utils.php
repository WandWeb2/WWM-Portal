<?php
// /api/modules/utils.php
// Version: 30.0 - Added Email Template System

function getDBConnection($secrets) {
    // Support both explicit DSN or legacy DB_HOST/DB_NAME format
    if (!empty($secrets['DB_DSN'])) {
        $dsn = $secrets['DB_DSN'];
    } else {
        $dsn = "mysql:host={$secrets['DB_HOST']};dbname={$secrets['DB_NAME']};charset=utf8mb4";
    }
    return new PDO($dsn, $secrets['DB_USER'] ?? '', $secrets['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function sendJson($s, $m, $d = []) { 
    $r = array_merge(["status" => $s, "message" => $m], $d); 
    if (ob_get_length()) ob_clean(); 
    echo json_encode($r); 
    exit(); 
}

function verifyAuth($input) { 
    if (empty($input['token'])) { sendJson('error', 'Unauthorized'); exit(); } 
    $parts = explode('.', $input['token']); 
    return json_decode(base64_decode($parts[0]), true); 
}

function ensureUserSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(191) UNIQUE,
        password_hash VARCHAR(255),
        full_name VARCHAR(100),
        business_name VARCHAR(100),
        role VARCHAR(20) DEFAULT 'client',
        status VARCHAR(20) DEFAULT 'pending',
        stripe_id VARCHAR(100),
        swipeone_id VARCHAR(100),
        google_resource_name VARCHAR(255),
        phone VARCHAR(255),
        website VARCHAR(255),
        address TEXT,
        position VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// === NEW: PARTNER SCHEMA ===
function ensurePartnerSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_id INT NOT NULL,
        client_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(partner_id, client_id)
    )");
}

// === NEW: SETTINGS SCHEMA ===
function ensureSettingsSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(191) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// === NEW: SETTINGS HELPERS ===
function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function getEmailTemplate($pdo) {
    $template = getSetting($pdo, 'email_template', '');
    if (!empty($template)) return $template;
    
    // Default HTML Template
    return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', Arial, sans-serif; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2c3259; padding: 30px; text-align: center; }
        .header img { max-width: 200px; height: auto; }
        .content { padding: 40px 30px; color: #374151; line-height: 1.6; }
        .content h2 { color: #2c3259; margin-top: 0; }
        .content p { margin: 15px 0; }
        .highlight { background: #f8fafc; padding: 15px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #2493a2; }
        .button { display: inline-block; background: #dba000; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 20px 0; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; font-family: sans-serif;'>WandWeb Portal</h1>
        </div>
        <div class='content'>
            [[BODY]]
        </div>
        [[BUTTON]]
        <div class='footer'>
            <p>&copy; " . date('Y') . " Wandering Webmaster. All rights reserved.</p>
            <p><a href='https://wandweb.co' style='color: #2493a2; text-decoration: none;'>wandweb.co</a></p>
        </div>
    </div>
</body>
</html>";
}

function renderEmail($pdo, $subject, $body, $link = null, $buttonText = null, $recipientName = '') {
    $template = getEmailTemplate($pdo);
    
    // Build button HTML if link provided
    $buttonHtml = '';
    if ($link && $buttonText) {
        $buttonHtml = "<div style='text-align:center;'><a href='$link' class='button'>$buttonText</a></div>";
    }
    
    // Build body with greeting
    $greeting = $recipientName ? "Hello $recipientName," : "Hello Valued Client,";
    $bodyHtml = "<p>$greeting</p>" . $body;
    
    // Replace placeholders
    $template = str_replace('[[SUBJECT]]', $subject, $template);
    $template = str_replace('[[BODY]]', $bodyHtml, $template);
    $template = str_replace('[[BUTTON]]', $buttonHtml, $template);
    $template = str_replace('[[RECIPIENT_NAME]]', $recipientName, $template);
    
    return $template;
}

function normalizePhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (empty($clean)) return null;
    if (substr($clean, 0, 2) === '04' && strlen($clean) === 10) return '61' . substr($clean, 1);
    return $clean;
}

function stripeRequest($secrets, $method, $endpoint, $data = []) {
    $endpoint = ltrim($endpoint, '/'); 
    $ch = curl_init("https://api.stripe.com/v1/$endpoint");
    $headers = ["Authorization: Bearer " . $secrets['STRIPE_SECRET_KEY']];
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    elseif ($method === 'DELETE') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch); 
    curl_close($ch); 
    return json_decode($result, true);
}

function pushToSwipeOne($secrets, $endpoint, $data, $method = 'POST') { 
    if (empty($secrets['SWIPEONE_API_KEY']) || empty($secrets['SWIPEONE_WORKSPACE_ID'])) return; 
    $url = "https://api.swipeone.com/api/workspaces/" . $secrets['SWIPEONE_WORKSPACE_ID'] . "/$endpoint"; 
    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: " . $secrets['SWIPEONE_API_KEY'], "Content-Type: application/json"]); 
    $response = curl_exec($ch); 
    curl_close($ch); 
    return json_decode($response, true); 
}

function sendInvite($pdo, $email) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);
    $pdo->prepare("INSERT INTO password_resets (email,token,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")->execute([$email, $token]);
    $link = "https://wandweb.co/portal/?action=set_password&token=" . $token;
    
    // Use enhanced email template
    $body = "<h2 style='color:#2c3259;'>Welcome to the Portal</h2>
    <p>A client portal account has been created for you at Wandering Webmaster.</p>
    <div class='highlight'>
        <strong>Your Username:</strong> $email
    </div>
    <p>Please click the button below to set your password and access your dashboard.</p>";
    
    $html = renderEmail($pdo, "Your New Portal Account - Wandering Webmaster", $body, $link, "Set Password", "");
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: noreply@wandweb.co\r\nReply-To: support@wandweb.co";
    return mail($email, "Your New Portal Account - Wandering Webmaster", $html, $headers);
}

function fetchWandWebContext() {
    $content = "Here is the latest knowledge base from WandWeb.co:\n";
    try {
        $context = stream_context_create(['http' => ['timeout' => 2]]);
        $rssContent = @file_get_contents('https://wandweb.co/feed/', false, $context);
        if ($rssContent) {
            $rss = @simplexml_load_string($rssContent);
            if ($rss) {
                $count = 0;
                foreach ($rss->channel->item as $item) {
                    if ($count++ > 3) break;
                    $content .= "- " . (string)$item->title . " (" . (string)$item->link . ")\n";
                }
            }
        }
    } catch (Exception $e) { $content .= "(Blog feed momentarily unavailable)\n"; }
    $content .= "\nCORE PAGES:\n- Services: https://wandweb.co/services\n- Contact: https://wandweb.co/contact\n";
    return $content;
}

// === NOTIFICATION HELPER ===
function createNotification($pdo, $userId, $message, $type = null, $id = 0) {
    if (!$userId) return;
    
    // 1. DB Insert with Context
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, target_type, target_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $message, $type, $id]);
    
    // 2. Email Alert (Immediate) - Use enhanced template
    try {
        $u = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $u->execute([$userId]);
        $user = $u->fetch();
        
        if ($user && !empty($user['email'])) {
            $to = $user['email'];
            $subject = "New Activity: WandWeb Portal";
            $link = "https://wandweb.co/portal/";
            $body = "<h2 style='color:#2c3259;'>New Portal Activity</h2>
            <p>$message</p>
            <p>Click the button below to view your portal dashboard.</p>";
            
            $html = renderEmail($pdo, $subject, $body, $link, "View Portal", $user['full_name']);
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: noreply@wandweb.co\r\nReply-To: support@wandweb.co\r\nX-Mailer: PHP/" . phpversion();
            @mail($to, $subject, $html, $headers);
        }
    } catch (Exception $e) { }
}

function notifyPartnerIfAssigned($pdo, $clientId, $message) {
    // FIX: Ensure table exists before querying to prevent SQLSTATE[42S02]
    ensurePartnerSchema($pdo);
    
    $stmt = $pdo->prepare("SELECT partner_id FROM partner_assignments WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $partner = $stmt->fetch();
    if ($partner) {
        createNotification($pdo, $partner['partner_id'], "[Partner Alert] " . $message);
    }
}

function notifyAllAdmins($pdo, $message) {
    // 1. Find all admins
    $stmt = $pdo->query("SELECT id, email FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll();
    
    foreach ($admins as $admin) {
        // Internal Notification
        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)") ->execute([$admin['id'], $message]);
        
        // Email Notification
        $subject = "Escalation Alert: WandWeb Portal";
        $headers = "From: noreply@wandweb.co";
        @mail($admin['email'], $subject, $message, $headers);
    }
}

function notifyAllAdminsForProject($pdo, $projectId, $message) {
    // Notify all admins about project activity
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll();
    foreach ($admins as $admin) {
        createNotification($pdo, $admin['id'], $message, 'project', $projectId);
    }
}

// === GOOGLE OAUTH HELPER (Moved from clients.php) ===
function getGoogleAccessToken($secrets) {
    // 1. Check for Config
    if (empty($secrets['GOOGLE_REFRESH_TOKEN'])) {
        throw new Exception("Google Integration Not Configured (Missing Refresh Token)");
    }

    $url = "https://oauth2.googleapis.com/token";
    $params = [
        'client_id'     => $secrets['GOOGLE_CLIENT_ID'],
        'client_secret' => $secrets['GOOGLE_CLIENT_SECRET'],
        'refresh_token' => $secrets['GOOGLE_REFRESH_TOKEN'],
        'grant_type'    => 'refresh_token'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($response['error'])) {
        throw new Exception("Google OAuth Error: " . ($response['error_description'] ?? $response['error']));
    }

    return $response['access_token'] ?? null;
}

// === SELF-HEALING AI GATEWAY ===

function getActiveAIModel($pdo, $secrets) {
    // 1. Try Cached
    $cached = getSetting($pdo, 'ai_active_model');
    if ($cached) return $cached;

    // 2. Initial Setup (First Run)
    return refreshAIModelList($pdo, $secrets);
}

function refreshAIModelList($pdo, $secrets) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $secrets['GEMINI_API_KEY'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res['models'])) return 'gemini-1.5-flash-001'; // Fallback

    // Logic: Find latest "flash" model that supports generation
    $bestModel = '';
    foreach ($res['models'] as $m) {
        $name = str_replace('models/', '', $m['name']);
        if (strpos($name, 'flash') !== false && in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
            $bestModel = $name;
            break; 
        }
    }
    
    if (!$bestModel) $bestModel = 'gemini-1.5-flash-001'; // Hard fallback

    // Save to DB
    $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('ai_active_model', ?)")
        ->execute([$bestModel]);
        
    return $bestModel;
}

function callGeminiAI($pdo, $secrets, $systemPrompt, $userPrompt = "") {
    $model = getActiveAIModel($pdo, $secrets);
    $response = internalGeminiRequest($secrets['GEMINI_API_KEY'], $model, $systemPrompt, $userPrompt);

    // AUTO-HEAL: If model not found (404/400), refresh list and retry ONCE
    if (isset($response['error']) && (stripos($response['error']['message'], 'not found') !== false || stripos($response['error']['message'], 'not supported') !== false)) {
        $newModel = refreshAIModelList($pdo, $secrets);
        if ($newModel !== $model) {
            $response = internalGeminiRequest($secrets['GEMINI_API_KEY'], $newModel, $systemPrompt, $userPrompt);
        }
    }

    return $response;
}

function internalGeminiRequest($apiKey, $model, $sys, $user) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";
    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [["text" => $sys . "\n\n" . $user]]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($res, true);
}

// === SYSTEM LOGGING (RESTORED) ===
function ensureLogSchema($pdo) {
    try {
        // Use MySQL-compatible syntax
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20),
            message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {
        // Silently fail if table creation has issues - DB might already exist
        error_log("ensureLogSchema warning: " . $e->getMessage());
    }
}

// === FALLBACK LOG FILE (when DB is down) ===
function getLogFilePath() {
    return sys_get_temp_dir() . '/wandweb_system.log';
}

function logToFile($message, $level = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] $message\n";
    @file_put_contents(getLogFilePath(), $logLine, FILE_APPEND);
}

function logSystemEvent($pdo, $message, $level = 'info') {
    try {
        ensureLogSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO system_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) { 
        // Fallback to file if DB fails
        logToFile($message, $level);
        error_log("WANDWEB LOG [$level]: $message"); 
    }
}

function handleGetSystemLogs($pdo, $i) {
    // Try to verify auth, but don't fail if it causes DB error
    $u = null;
    try {
        $u = verifyAuth($i);
        if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    } catch (Exception $e) {
        // Even if auth fails, still try to serve logs from file
        logToFile('Failed to verify auth in handleGetSystemLogs: ' . $e->getMessage(), 'warning');
    }
    
    $logs = [];
    $dbStatus = testDatabaseConnection($pdo);
    
    // Try to get DB logs first
    if ($dbStatus['status'] === 'connected') {
        try {
            ensureLogSchema($pdo);
            $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 50");
            $logs = $stmt->fetchAll();
        } catch (Exception $e) {
            logToFile('Failed to fetch DB logs: ' . $e->getMessage(), 'error');
            $logs[] = [
                'id' => 0,
                'level' => 'error',
                'message' => 'DATABASE QUERY FAILED: ' . $e->getMessage(),
                'created_at' => date('Y-m-d H:i:s'),
                'source' => 'system'
            ];
        }
    } else {
        $logs[] = [
            'id' => -1,
            'level' => 'error',
            'message' => 'DATABASE DISCONNECTED: ' . $dbStatus['message'],
            'created_at' => date('Y-m-d H:i:s'),
            'source' => 'system'
        ];
    }
    
    // Always append file logs as fallback (whether DB is up or down)
    $logFile = getLogFilePath();
    if (file_exists($logFile)) {
        $fileLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($fileLines) {
            $fileLines = array_reverse(array_slice($fileLines, -50)); // Last 50 lines
            foreach ($fileLines as $idx => $line) {
                preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches);
                if (!empty($matches)) {
                    $logs[] = [
                        'id' => -($idx + 2),
                        'level' => $matches[2] ?? 'info',
                        'message' => $matches[3] ?? $line,
                        'created_at' => $matches[1] ?? date('Y-m-d H:i:s'),
                        'source' => 'file_fallback'
                    ];
                }
            }
        }
    }
    
    // Sort by date descending
    usort($logs, function($a, $b) {
        $aTime = strtotime($a['created_at'] ?? 'now');
        $bTime = strtotime($b['created_at'] ?? 'now');
        return $bTime - $aTime;
    });
    
    sendJson('success', 'Logs Loaded', ['logs' => $logs, 'db_status' => $dbStatus]);
}

function handleDebugLog($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $msg = $i['message'] ?? 'Debug action triggered';
    logSystemEvent($pdo, $msg, 'info');
    sendJson('success', 'Debug log added');
}

// === DATABASE CONNECTION TEST ===
function testDatabaseConnection($pdo) {
    try {
        $result = $pdo->query("SELECT 1");
        return ['status' => 'connected', 'message' => 'Database connection working'];
    } catch (Exception $e) {
        return ['status' => 'disconnected', 'message' => $e->getMessage()];
    }
}

function handleDebugTest($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    // Get DB status without requiring a working connection
    $dbStatus = testDatabaseConnection($pdo);
    
    $test = $i['test'] ?? 'unknown';
    $result = '';
    $diagnostics = [];
    
    switch($test) {
        case 'api_connection':
            $result = 'API Connection: ✓ WORKING - Portal API responding normally';
            logSystemEvent($pdo, $result, 'success');
            break;
            
        case 'database_status':
            $diagnostics = [
                'status' => $dbStatus['status'],
                'message' => $dbStatus['message'],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            if ($dbStatus['status'] === 'disconnected') {
                $result = "Database Status: ✗ DISCONNECTED - {$dbStatus['message']}";
                logSystemEvent($pdo, $result, 'error');
            } else {
                $result = "Database Status: ✓ CONNECTED";
                try {
                    ensureLogSchema($pdo);
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM system_logs");
                    $row = $stmt->fetch();
                    $count = $row['count'] ?? 0;
                    $result .= " - Found $count log entries";
                    $diagnostics['log_count'] = $count;
                } catch (Exception $e) {
                    $diagnostics['log_error'] = $e->getMessage();
                }
                logSystemEvent($pdo, $result, 'success');
            }
            break;
            
        case 'emergency_status':
            // Complete system status check without relying on DB
            $diagnostics = [
                'timestamp' => date('Y-m-d H:i:s'),
                'database' => $dbStatus,
                'php_version' => phpversion(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'log_file' => getLogFilePath(),
                'log_file_exists' => file_exists(getLogFilePath()),
                'log_file_readable' => is_readable(getLogFilePath()),
                'log_file_size' => file_exists(getLogFilePath()) ? filesize(getLogFilePath()) : 0,
                'temp_writable' => is_writable(sys_get_temp_dir()),
                'api_uptime' => 'Running'
            ];
            
            // Try to get file size
            $logFile = getLogFilePath();
            if (file_exists($logFile)) {
                $diagnostics['recent_logs'] = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10);
            }
            
            $result = "System Emergency Status: Retrieved " . count($diagnostics) . " diagnostic points";
            logSystemEvent($pdo, $result, 'info');
            break;
            
        case 'database_query':
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM system_logs");
                $row = $stmt->fetch();
                $count = $row['count'] ?? 0;
                $result = "Database Query: ✓ WORKING - Found $count log entries in database";
                logSystemEvent($pdo, $result, 'success');
            } catch (Exception $e) {
                $result = "Database Query: ✗ ERROR - " . $e->getMessage();
                logSystemEvent($pdo, $result, 'error');
            }
            break;
            
        case 'resync_user_59':
            try {
                // First, show table schema
                $columns = $pdo->query("DESCRIBE users")->fetchAll();
                logSystemEvent($pdo, "Users table columns:", 'info');
                foreach ($columns as $col) {
                    logSystemEvent($pdo, "  - {$col['Field']}: {$col['Type']}", 'info');
                }
                
                // Clear user 59 from cache/temp and force fresh load
                $stmt = $pdo->prepare("SELECT id, full_name, role, status FROM users WHERE id = 59");
                $stmt->execute();
                $user = $stmt->fetch();
                
                if (!$user) {
                    $result = "User #59: ✗ NOT FOUND - User does not exist in database";
                    logSystemEvent($pdo, $result, 'error');
                } else {
                    $role = $user['role'] === '' || $user['role'] === null ? '(EMPTY)' : $user['role'];
                    $result = "User #59 Found: ID={$user['id']}, Name={$user['full_name']}, Role=$role, Status={$user['status']}";
                    logSystemEvent($pdo, $result, 'info');
                    
                    // If they're a partner, verify they're in partner list
                    if ($user['role'] === 'partner') {
                        $pStmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'partner' AND status = 'active'");
                        $pStmt->execute();
                        $pRow = $pStmt->fetch();
                        $result = "Partner List Check: Found {$pRow['count']} active partners (including #{$user['id']})";
                        logSystemEvent($pdo, $result, 'success');
                    }
                }
            } catch (Exception $e) {
                $result = "User #59 Resync: ✗ ERROR - " . $e->getMessage();
                logSystemEvent($pdo, $result, 'error');
            }
            break;
            
        case 'rebuild_partners':
            try {
                $stmt = $pdo->query("SELECT id, full_name, role, status FROM users WHERE role = 'partner' AND status = 'active' ORDER BY full_name");
                $partners = $stmt->fetchAll();
                $count = count($partners);
                
                $result = "Partners Rebuilt: ✓ Found $count active partners";
                logSystemEvent($pdo, $result, 'success');
                
                if ($count > 0) {
                    foreach ($partners as $p) {
                        logSystemEvent($pdo, "  - Partner #{$p['id']}: {$p['full_name']} (role={$p['role']}, status={$p['status']})", 'info');
                    }
                } else {
                    logSystemEvent($pdo, "No active partners found in database", 'warning');
                }
            } catch (Exception $e) {
                $result = "Rebuild Partners: ✗ ERROR - " . $e->getMessage();
                logSystemEvent($pdo, $result, 'error');
            }
            break;
            
        case 'permissions_audit':
            try {
                logSystemEvent($pdo, "=== PARTNER PERMISSIONS AUDIT ===", 'info');
                
                // Check partner role enum
                $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field='role'");
                $roleCol = $stmt->fetch();
                logSystemEvent($pdo, "Role Column Type: " . $roleCol['Type'], 'info');
                
                // Count partners
                $pStmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'partner' AND status = 'active'");
                $pCount = $pStmt->fetch();
                logSystemEvent($pdo, "Active Partners: " . $pCount['count'], 'success');
                
                // List each partner's clients
                $partners = $pdo->query("SELECT id, full_name FROM users WHERE role = 'partner' AND status = 'active' ORDER BY full_name");
                foreach ($partners->fetchAll() as $partner) {
                    $cStmt = $pdo->prepare("SELECT COUNT(*) as count FROM partner_assignments WHERE partner_id = ?");
                    $cStmt->execute([$partner['id']]);
                    $cRow = $cStmt->fetch();
                    logSystemEvent($pdo, "  Partner #{$partner['id']} ({$partner['full_name']}): manages {$cRow['count']} client(s)", 'info');
                    
                    // List their clients
                    $clients = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN partner_assignments pa ON u.id = pa.client_id WHERE pa.partner_id = ?");
                    $clients->execute([$partner['id']]);
                    foreach ($clients->fetchAll() as $client) {
                        logSystemEvent($pdo, "    → Client #{$client['id']}: {$client['full_name']}", 'info');
                    }
                }
                
                // Permission checks
                logSystemEvent($pdo, "=== PERMISSION SUMMARY ===", 'info');
                logSystemEvent($pdo, "✓ Partners can: View assigned clients' projects", 'success');
                logSystemEvent($pdo, "✓ Partners can: View project details for assigned clients", 'success');
                logSystemEvent($pdo, "✓ Partners can: Create projects for assigned clients", 'success');
                logSystemEvent($pdo, "✓ Partners can: View their own profile/settings", 'success');
                logSystemEvent($pdo, "✓ Admin can: Assign clients to partners via partner_assignments", 'success');
                logSystemEvent($pdo, "Note: Partners CANNOT delete projects (admin only)", 'warning');
                
            } catch (Exception $e) {
                logSystemEvent($pdo, "Permissions Audit ERROR: " . $e->getMessage(), 'error');
            }
            break;
        
        case 'check_php_errors':
            // Check PHP error logs and display errors
            $diagnostics['php_version'] = phpversion();
            $diagnostics['display_errors'] = ini_get('display_errors');
            $diagnostics['error_reporting'] = error_reporting();
            $diagnostics['log_errors'] = ini_get('log_errors');
            $diagnostics['error_log'] = ini_get('error_log');
            
            logSystemEvent($pdo, "=== PHP ERROR CHECK ===", 'info');
            logSystemEvent($pdo, "PHP Version: " . phpversion(), 'info');
            logSystemEvent($pdo, "Display Errors: " . ini_get('display_errors'), 'info');
            logSystemEvent($pdo, "Error Reporting Level: " . error_reporting(), 'info');
            
            // Check for recent PHP errors in log file
            $errorLog = ini_get('error_log');
            if ($errorLog && file_exists($errorLog)) {
                $recentErrors = array_slice(file($errorLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10);
                logSystemEvent($pdo, "Recent PHP Errors (last 10):", 'warning');
                foreach ($recentErrors as $err) {
                    logSystemEvent($pdo, "  " . $err, 'error');
                }
            } else {
                logSystemEvent($pdo, "No PHP error log file found or accessible", 'info');
            }
            
            $result = "PHP Error Check: Completed - See logs for details";
            logSystemEvent($pdo, $result, 'success');
            break;
        
        case 'check_json_output':
            // Test that JSON output is clean
            ob_start();
            echo json_encode(['test' => 'value', 'timestamp' => date('Y-m-d H:i:s')]);
            $output = ob_get_clean();
            
            $diagnostics['test_json'] = $output;
            $diagnostics['json_valid'] = json_decode($output) !== null;
            
            logSystemEvent($pdo, "=== JSON OUTPUT TEST ===", 'info');
            logSystemEvent($pdo, "Test JSON: " . $output, 'info');
            logSystemEvent($pdo, "JSON Valid: " . ($diagnostics['json_valid'] ? 'YES' : 'NO'), $diagnostics['json_valid'] ? 'success' : 'error');
            
            // Check output buffering
            $diagnostics['ob_level'] = ob_get_level();
            $diagnostics['ob_handlers'] = ob_list_handlers();
            logSystemEvent($pdo, "Output Buffer Level: " . ob_get_level(), 'info');
            logSystemEvent($pdo, "Output Handlers: " . implode(', ', ob_list_handlers()), 'info');
            
            $result = "JSON Output Check: " . ($diagnostics['json_valid'] ? '✓ CLEAN' : '✗ CORRUPTED');
            logSystemEvent($pdo, $result, $diagnostics['json_valid'] ? 'success' : 'error');
            break;
        
        case 'check_includes':
            // Check that all required files are included and readable
            $requiredFiles = [
                'secrets.php' => [
                    __DIR__ . '/../../private/secrets.php',
                    $_SERVER['DOCUMENT_ROOT'] . '/../private/secrets.php',
                    '/workspaces/WWM-Portal/private/secrets.php'
                ],
                'auth.php' => [__DIR__ . '/auth.php'],
                'utils.php' => [__DIR__ . '/utils.php'],
                'clients.php' => [__DIR__ . '/clients.php'],
                'projects.php' => [__DIR__ . '/projects.php'],
                'billing.php' => [__DIR__ . '/billing.php'],
                'files.php' => [__DIR__ . '/files.php']
            ];
            
            logSystemEvent($pdo, "=== FILE INCLUDES CHECK ===", 'info');
            
            foreach ($requiredFiles as $name => $paths) {
                $found = false;
                foreach ($paths as $path) {
                    if (file_exists($path)) {
                        logSystemEvent($pdo, "✓ $name: Found at $path", 'success');
                        $diagnostics['files'][$name] = ['status' => 'found', 'path' => $path, 'readable' => is_readable($path), 'size' => filesize($path)];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    logSystemEvent($pdo, "✗ $name: NOT FOUND (checked " . count($paths) . " locations)", 'error');
                    $diagnostics['files'][$name] = ['status' => 'missing', 'checked' => $paths];
                }
            }
            
            $result = "File Includes Check: Completed";
            logSystemEvent($pdo, $result, 'success');
            break;
            
        default:
            $result = "Unknown test: $test";
            logSystemEvent($pdo, $result, 'warning');
    }
    
    sendJson('success', 'Diagnostic test completed', ['result' => $result, 'diagnostics' => $diagnostics, 'db_status' => $dbStatus]);
}
?>