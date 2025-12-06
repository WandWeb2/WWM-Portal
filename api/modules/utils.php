<?php
// /api/modules/utils.php
// Version: 35.0 - Dynamic AI Model Discovery

function getDBConnection($secrets) {
    if (!empty($secrets['DB_DSN'])) {
        $dsn = $secrets['DB_DSN'];
    } else {
        // Default to MySQL if not specified
        $dsn = "mysql:host={$secrets['DB_HOST']};dbname={$secrets['DB_NAME']};charset=utf8mb4";
    }

    try {
        return new PDO($dsn, $secrets['DB_USER'] ?? '', $secrets['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {
        // Fallback to local SQLite if primary connection fails
        $fallbackPath = __DIR__ . '/../../data/portal.sqlite';
        $fallbackDsn = 'sqlite:' . $fallbackPath;
        
        // Ensure directory exists
        if (!is_dir(dirname($fallbackPath))) {
            @mkdir(dirname($fallbackPath), 0775, true);
        }
        
        return new PDO($fallbackDsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
}

// --- SQL POLYFILL (Fixes the Crash) ---

// =============================================================================
// Wandering Webmaster Custom Component
// Version: 32.1 - Critical DB Driver Fix
// =============================================================================

function getSqlType($pdo, $type) {
    // 1. Get driver and force lowercase to ensure accurate detection
    // Some environments return 'SQLITE' or 'sqlite' randomly
    $rawDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = strtolower($rawDriver); 
    
    if ($type === 'serial') {
        return ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    }
    
    if ($type === 'timestamp_update') {
        // SQLite does not support ON UPDATE CURRENT_TIMESTAMP
        return ($driver === 'sqlite') ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
    }
    
    return $type;
}

function sendJson($s, $m, $d = []) {
    // Clear buffer to remove any previous warnings/text
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(["status" => $s, "message" => $m], $d));
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

// --- SCHEMA FUNCTIONS ---

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
    // CRITICAL FIX: Use polyfill for timestamp to avoid SQLite crash
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

function logSystemEvent($pdo, $message, $level = 'info') {
    // 1. Try Database Log
    try {
        ensureLogSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO system_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) {
        // DB Failed - Silent fail here, proceed to file
    }

    // 2. Guaranteed File Fallback (Persistence)
    // Writes to a temp file that survives DB crashes
    try {
        $logFile = sys_get_temp_dir() . '/wandweb_system.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($logFile, $entry, FILE_APPEND);
    } catch (Exception $e) {
        error_log("WWM CRITICAL LOG FAILURE: " . $e->getMessage());
    }
}

// --- THIRD PARTY & HELPERS ---

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
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: noreply@wandweb.co";
    return mail($email, "Your Portal Account", "Set password here: Link", $headers);
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

function notifyAllAdminsForEscalation($pdo, $ticketId, $message) {
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
    foreach ($stmt->fetchAll() as $admin) {
        createNotification($pdo, $admin['id'], $message, 'ticket', $ticketId);
    }
}

// --- GOOGLE & AI ---

function getGoogleAccessToken($secrets) {
    if (empty($secrets['GOOGLE_REFRESH_TOKEN'])) return null;
    
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

// [NEW] Dynamically finds the best available model from Google
function getBestGeminiModel($secrets) {
    // 1. Check Cache (avoid slowing down every request)
    // Note: In a full production env, we'd use the database 'settings' table. 
    // For this standalone version, we will default to a safe fallback if the API check fails.
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $secrets['GEMINI_API_KEY'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    
    if (empty($data['models'])) return "gemini-pro"; // Safe Fallback

    // 2. Define Preference Hierarchy
    // We prefer 1.5 Flash (fastest), then 1.5 Pro (best), then standard Pro.
    $preferred = ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-pro'];
    
    // 3. Map Available Models
    $available = [];
    foreach ($data['models'] as $m) {
        $id = str_replace('models/', '', $m['name']);
        if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
            $available[] = $id;
        }
    }

    // 4. Match Preference
    foreach ($preferred as $p) {
        if (in_array($p, $available)) return $p;
    }

    // 5. If no preferences match, return the first available model
    return $available[0] ?? "gemini-pro";
}

function callGeminiAI($pdo, $secrets, $systemPrompt, $userPrompt = "") {
    // AUTO-DISCOVERY: Get the best model available to this API Key
    $model = getBestGeminiModel($secrets);
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $secrets['GEMINI_API_KEY'];
    
    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $systemPrompt . "\n\n" . $userPrompt]
                ]
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($result, true);
    
    // Inject Model Info into response for debugging
    if (is_array($response)) {
        $response['_meta_model_used'] = $model;
    }

    if ($httpCode >= 400) {
        if (isset($response['error'])) return $response;
        return ['error' => ['code' => $httpCode, 'message' => 'HTTP Error']];
    }

    return $response;
}

function fetchWandWebContext() {
    return "
    OFFICIAL SERVICE CATALOG (WANDWEB):
    
    1. MONTHLY PLANS (Top Priority to Sell)
       - Link: https://wandweb.co/monthly-plans/
       - Focus: The ultimate peace of mind. Includes updates, security, and support.
       - Key Selling Points: Proactive maintenance, priority support, discounted hourly rates for extra work.
    
    2. WEBSITE MAINTENANCE & SUPPORT
       - Link: https://wandweb.co/website-maintenance-support/
       - Features: Core WordPress updates, plugin management, uptime monitoring, off-site backups.
       - Value: 'We break it, we fix it.' Prevention is cheaper than repair.
    
    3. MANAGED HOSTING
       - Link: https://wandweb.co/hosting/
       - Specs: High-performance cloud hosting optimized for WordPress.
       - Benefits: SSL included, CDN integration, daily backups, malware scanning.
    
    4. SEO & LOCAL LISTINGS
       - Link: https://wandweb.co/seo-local-listings/
       - Goal: Get found on Google Maps and Local Search.
       - Deliverables: Google Business Profile optimization, citation building, on-page SEO keywording.
    
    5. SOCIAL MEDIA MANAGEMENT
       - Link: https://wandweb.co/social-media-management/
       - Scope: Content creation, scheduling, and community engagement.
       - Platforms: Facebook, Instagram, LinkedIn, Google Business.
    ";
}

function notifyAllAdmins($pdo, $message, $type = 'system', $targetId = 0) {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $insert = $pdo->prepare("INSERT INTO notifications (user_id, message, target_type, target_id) VALUES (?, ?, ?, ?)");
        
        foreach ($admins as $uid) {
            $insert->execute([$uid, $message, $type, $targetId]);
        }
        return count($admins);
    } catch (Exception $e) {
        return 0;
    }
}

?>
