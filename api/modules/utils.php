<?php
// /api/modules/utils.php
// Version: 29.0 - Added Partner Schema

function getDBConnection($secrets) {
    $dsn = "mysql:host={$secrets['DB_HOST']};dbname={$secrets['DB_NAME']};charset=utf8mb4";
    return new PDO($dsn, $secrets['DB_USER'], $secrets['DB_PASS'], [
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
    $html = "<div style='font-family:sans-serif;padding:20px;background:#f3f4f6;color:#333;'><div style='background:white;padding:30px;border-radius:10px;max-width:500px;margin:0 auto;border:1px solid #e5e7eb;'><h2 style='color:#2c3259;margin-top:0;'>Welcome to the Portal</h2><p>A client portal account has been created for you at Wandering Webmaster.</p><div style='background:#f8fafc;padding:15px;border-radius:6px;margin:20px 0;border-left:4px solid #2c3259;'><strong>Your Username:</strong> $email</div><p>Please click the button below to set your password and access your dashboard.</p><a href='$link' style='display:block;background:#ea580c;color:white;padding:12px 20px;text-align:center;text-decoration:none;border-radius:6px;margin-top:20px;font-weight:bold;'>Set Password</a></div></div>";
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
function createNotification($pdo, $userId, $message) {
    if (!$userId) return;
    
    // 1. DB Insert
    $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$userId, $message]);
    
    // 2. Email Alert (Immediate)
    try {
        $u = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $u->execute([$userId]);
        $user = $u->fetch();
        
        if ($user && !empty($user['email'])) {
            $to = $user['email'];
            $subject = "Update from WandWeb Portal";
            $body = "Hello {$user['full_name']},\n\nNew activity on your portal:\n$message\n\nLogin to view: https://wandweb.co/portal/";
            $headers = "From: noreply@wandweb.co\r\n";
            $headers .= "Reply-To: support@wandweb.co\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            // Silence mail errors to prevent API crashes
            @mail($to, $subject, $body, $headers);
        }
    } catch (Exception $e) {
        // Ignore email failures, notification is saved in DB
    }
}

function notifyPartnerIfAssigned($pdo, $clientId, $message) {
    $stmt = $pdo->prepare("SELECT partner_id FROM partner_assignments WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $partner = $stmt->fetch();
    if ($partner) {
        createNotification($pdo, $partner['partner_id'], "[Partner Alert] " . $message);
    }
}
?>