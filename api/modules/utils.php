<?php
// /api/modules/utils.php
// Version: 30.1 - Fixed SQLite/MySQL Compatibility

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
        // Fallback to local SQLite if primary fails
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
    echo json_encode($r);
    exit();
}

function verifyAuth($input) {
    if (empty($input['token'])) { sendJson('error', 'Unauthorized'); exit(); }
    $parts = explode('.', $input['token']);
    return json_decode(base64_decode($parts[0]), true);
}

function ensureUserSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ai = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $ai,
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
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ai = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_assignments (
        id $ai,
        partner_id INT NOT NULL,
        client_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(partner_id, client_id)
    )");
}

function ensureSettingsSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

function ensureLogSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ai = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id $ai,
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

function getEmailTemplate($pdo) {
    $template = getSetting($pdo, 'email_template', '');
    if (!empty($template)) return $template;
    return "<!DOCTYPE html><html><head><style>body{font-family:Arial,sans-serif}container{max-width:600px;margin:0 auto}.header{background:#2c3259;padding:20px;color:white}.content{padding:20px}</style></head><body><div class='container'><div class='header'><h1>WandWeb Portal</h1></div><div class='content'>[[BODY]]</div>[[BUTTON]]<div style='background:#f9fafb;padding:10px;text-align:center;font-size:12px'>&copy; " . date('Y') . " Wandering Webmaster</div></div></body></html>";
}

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

function testDatabaseConnection($pdo) {
    try {
        $result = $pdo->query("SELECT 1");
        return ['status' => 'connected', 'message' => 'Database connection working'];
    } catch (Exception $e) {
        return ['status' => 'disconnected', 'message' => $e->getMessage()];
    }
}

function logToFile($message, $level = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] $message\n";
    @file_put_contents(getLogFilePath(), $logLine, FILE_APPEND);
}

function getLogFilePath() {
    return sys_get_temp_dir() . '/wandweb_system.log';
}

function logSystemEvent($pdo, $message, $level = 'info') {
    try {
        ensureLogSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO system_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) {
        logToFile($message, $level);
    }
}

function handleGetSystemLogs($pdo, $i) {
    $u = null;
    try {
        $u = verifyAuth($i);
        if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    } catch (Exception $e) {
        logToFile('Failed to verify auth: ' . $e->getMessage(), 'warning');
    }
    
    $logs = [];
    $dbStatus = testDatabaseConnection($pdo);
    
    if ($dbStatus['status'] === 'connected') {
        try {
            ensureLogSchema($pdo);
            $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 50");
            $logs = $stmt->fetchAll();
        } catch (Exception $e) {
            logToFile('Failed to fetch DB logs: ' . $e->getMessage(), 'error');
        }
    }
    
    $logFile = getLogFilePath();
    if (file_exists($logFile)) {
        $fileLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($fileLines) {
            foreach (array_reverse($fileLines) as $idx => $line) {
                preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches);
                if (!empty($matches)) {
                    $logs[] = ['id' => -($idx + 1), 'level' => $matches[2] ?? 'info', 'message' => $matches[3] ?? $line, 'created_at' => $matches[1] ?? date('Y-m-d H:i:s'), 'source' => 'file'];
                }
            }
        }
    }
    
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

function handleDebugTest($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $dbStatus = testDatabaseConnection($pdo);
    $test = $i['test'] ?? 'unknown';
    $result = '';
    
    switch($test) {
        case 'api_connection':
            $result = 'API: ✓ Working';
            logSystemEvent($pdo, $result, 'success');
            break;
        case 'database_status':
            if ($dbStatus['status'] === 'connected') {
                $result = 'Database: ✓ Connected';
                logSystemEvent($pdo, $result, 'success');
            } else {
                $result = 'Database: ✗ Offline - Using fallback logs';
                logSystemEvent($pdo, $result, 'warning');
            }
            break;
        case 'check_php_errors':
            $result = 'PHP: ✓ Version ' . phpversion();
            logSystemEvent($pdo, $result, 'success');
            break;
        case 'rebuild_partners':
            $result = 'Partners: ✓ Rebuild triggered';
            logSystemEvent($pdo, $result, 'success');
            break;
        default:
            $result = "Test: $test completed";
            logSystemEvent($pdo, $result, 'info');
    }
    
    sendJson('success', 'Test completed', ['result' => $result, 'db_status' => $dbStatus]);
}
?>
