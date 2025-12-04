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
            <img src='https://wandweb.co/wp-content/uploads/2024/11/wandweb-logo-white.png' alt='WandWeb Logo' />
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
        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$admin['id'], $message]);
        
        // Email Notification
        $subject = "Escalation Alert: WandWeb Portal";
        $headers = "From: noreply@wandweb.co";
        @mail($admin['email'], $subject, $message, $headers);
    }
}
?>