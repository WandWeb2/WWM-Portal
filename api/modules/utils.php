<?php
// /api/modules/utils.php
// Version: 31.1 - SQLite/MySQL Polyfill Fix

function getDBConnection($secrets) {
    if (!empty($secrets['DB_DSN'])) {
        $dsn = $secrets['DB_DSN'];
    } else {
        $dsn = "mysql:host={$secrets['DB_HOST']};dbname={$secrets['DB_NAME']};charset=utf8mb4";
    }

    try {
        return new PDO($dsn, $secrets['DB_USER'] ?? '', $secrets['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {
        // Fallback to local SQLite
        $fallbackPath = __DIR__ . '/../../data/portal.sqlite';
        $fallbackDsn = 'sqlite:' . $fallbackPath;
        if (!is_dir(dirname($fallbackPath))) @mkdir(dirname($fallbackPath), 0775, true);
        
        return new PDO($fallbackDsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
}

function sendJson($s, $m, $d = []) {
    $r = array_merge(["status" => $s, "message" => $m], $d);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($r);
    exit();
}

function verifyAuth($input) {
    if (empty($input['token'])) {
        sendJson('error', 'Unauthorized');
        exit();
    }
    $parts = explode('.', $input['token']);
    return json_decode(base64_decode($parts[0]), true);
}

// --- SCHEMA HELPERS (Polyfill) ---

function getSqlType($pdo, $type) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    if ($type === 'serial') {
        return ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    }
    
    if ($type === 'timestamp_update') {
        return ($driver === 'sqlite') ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
    }
    
    return $type;
}

function ensureUserSchema($pdo) {
    $idType = getSqlType($pdo, 'serial');
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $idType,
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

function ensurePartnerSchema($pdo) {
    $idType = getSqlType($pdo, 'serial');
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_assignments (
        id $idType,
        partner_id INT NOT NULL,
        client_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(partner_id, client_id)
    )");
}

function ensureSettingsSchema($pdo) {
    // SQLite does not support ON UPDATE CURRENT_TIMESTAMP
    $tsType = getSqlType($pdo, 'timestamp_update');
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(191) PRIMARY KEY,
        setting_value TEXT,
        updated_at $tsType
    )");
}

function ensureLogSchema($pdo) {
    $idType = getSqlType($pdo, 'serial');
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id $idType,
        level VARCHAR(20),
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

function getSetting($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// --- THIRD PARTY HELPERS ---

function stripeRequest($secrets, $method, $endpoint, $data = []) {
    $endpoint = ltrim($endpoint, '/');
    $ch = curl_init("https://api.stripe.com/v1/$endpoint");
    $headers = ["Authorization: Bearer " . $secrets['STRIPE_SECRET_KEY']];
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-api-key: " . $secrets['SWIPEONE_API_KEY'],
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// --- NOTIFICATIONS & EMAIL ---

function renderEmail($pdo, $subject, $body, $link = null, $button = null, $name = '') {
    return "$subject$body" . ($link ? "$button" : "") . "";
}

function sendInvite($pdo, $email) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);
    $pdo->prepare("INSERT INTO password_resets (email,token,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")->execute([$email, $token]);
    $link = "https://wandweb.co/portal/?action=set_password&token=" . $token;
    $body = "Welcome. Set your password: <a href='$link'>Click Here</a>";
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: noreply@wandweb.co";
    return mail($email, "Your Portal Account", $body, $headers);
}

function createNotification($pdo, $userId, $message, $type = null, $id = 0) {
    if (!$userId) return;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, target_type, target_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $message, $type, $id]);
}

function notifyPartnerIfAssigned($pdo, $clientId, $message) {
    ensurePartnerSchema($pdo);
    $stmt = $pdo->prepare("SELECT partner_id FROM partner_assignments WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $partner = $stmt->fetch();
    if ($partner) createNotification($pdo, $partner['partner_id'], "[Partner Alert] " . $message);
}

function notifyAllAdminsForProject($pdo, $projectId, $message) {
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
    foreach ($stmt->fetchAll() as $admin) {
        createNotification($pdo, $admin['id'], $message, 'project', $projectId);
    }
}

function logSystemEvent($pdo, $message, $level = 'info') {
    try {
        ensureLogSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO system_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) {
        error_log("WANDWEB LOG: $message");
    }
}

// --- AI HELPERS ---

function getGoogleAccessToken($secrets) {
    if (empty($secrets['GOOGLE_REFRESH_TOKEN'])) throw new Exception("Missing Google Token");
    
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $secrets['GOOGLE_CLIENT_ID'],
        'client_secret' => $secrets['GOOGLE_CLIENT_SECRET'],
        'refresh_token' => $secrets['GOOGLE_REFRESH_TOKEN'],
        'grant_type' => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? null;
}

function callGeminiAI($pdo, $secrets, $systemPrompt, $userPrompt = "") {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $secrets['GEMINI_API_KEY'];
    $payload = ["contents" => [["role" => "user", "parts" => [["text" => $systemPrompt . "\n\n" . $userPrompt]]]]];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

function fetchWandWebContext() {
    return "WandWeb: Web Design & AI Automation Agency.";
}

function notifyAllAdmins($pdo, $msg) {
    notifyAllAdminsForProject($pdo, 0, $msg);
}

?>
