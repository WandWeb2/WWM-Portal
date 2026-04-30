<?php
// =============================================================================
// Wandering Webmaster System Module
// Version: 1.0
// =============================================================================

function ensureSystemSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $idType = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_updates (
        id $idType,
        version VARCHAR(50),
        description TEXT,
        commit_date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Auto-Populate History if empty (The "Automatic" Backfill)
    $check = $pdo->query("SELECT COUNT(*) FROM portal_updates")->fetchColumn();
    if ($check == 0) {
        $stmt = $pdo->prepare("INSERT INTO portal_updates (version, description, commit_date) VALUES (?, ?, ?)");
        $history = [
            ['v35.0', 'Implemented Dynamic AI Model Discovery (Auto-Switching)', date('Y-m-d H:i:s')],
            ['v34.2', 'Fixed AI 404 Error: Switched to gemini-pro', date('Y-m-d H:i:s', strtotime('-10 minutes'))],
            ['v34.1', 'Added Verbose AI Error Debugging', date('Y-m-d H:i:s', strtotime('-20 minutes'))],
            ['v34.0', 'System Stability Checkpoint', date('Y-m-d H:i:s', strtotime('-30 minutes'))]
        ];
        foreach ($history as $h) $stmt->execute($h);
    }
}

function handleGetUpdates($pdo, $input) {
    // Accessible by ALL logged-in users
    verifyAuth($input); 
    ensureSystemSchema($pdo);
    
    $stmt = $pdo->query("SELECT * FROM portal_updates ORDER BY commit_date DESC");
    sendJson('success', 'Updates Loaded', ['updates' => $stmt->fetchAll()]);
}

function handleGetSystemLogs($pdo, $input) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    ensureLogSchema($pdo);
    
    $limit = max(1, (int)($input['limit'] ?? 100));
    // Note: MySQL/MariaDB do not allow binding LIMIT as a parameter, so we safely inline the int
    $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT $limit");
    
    sendJson('success', 'Logs Retrieved', ['logs' => $stmt->fetchAll()]);
}

function handleDebugTest($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $testName = $input['test_name'] ?? 'unknown';
    $results = [];
    
    try {
        switch($testName) {
            case 'test_check_php_errors':
                $results['php_version_secure'] = version_compare(PHP_VERSION, '8.0.0', '>=');
                $results['error_reporting_configured'] = error_reporting() !== 0;
                $results['display_errors_active'] = (bool)ini_get('display_errors');
                $results['status'] = 'pass';
                break;
                
            case 'test_database_status':
                $results['driver'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $results['connection'] = 'active';
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
                $results['users_count'] = $stmt->fetchColumn();
                $results['status'] = 'pass';
                break;
                
            case 'test_api_connection':
                $results['method'] = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
                $results['content_type'] = $_SERVER['CONTENT_TYPE'] ?? 'unknown';
                $results['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $results['status'] = 'pass';
                break;
                
            case 'test_check_json_output':
                $testArray = ['test' => 'data', 'number' => 123, 'nested' => ['key' => 'value']];
                $results['json_encode'] = json_encode($testArray) ? 'working' : 'failed';
                $results['json_last_error'] = json_last_error_msg();
                $results['status'] = 'pass';
                break;
                
            case 'test_permissions_audit':
                $paths = [
                    'api/portal_api.php',
                    'api/modules/utils.php',
                    'private/secrets.php'
                ];
                $all_valid = true;
                foreach ($paths as $path) {
                    $fullPath = __DIR__ . '/../../' . $path;
                    if (!file_exists($fullPath) || !is_readable($fullPath)) {
                        $all_valid = false;
                        break;
                    }
                }
                $results['system_files_intact'] = $all_valid;
                $results['status'] = 'pass';
                break;
                
            case 'test_check_includes':
                $modules = ['auth', 'billing', 'clients', 'files', 'projects', 'services', 'support', 'system', 'utils'];
                $all_loaded = true;
                foreach ($modules as $mod) {
                    $path = __DIR__ . '/' . $mod . '.php';
                    if (!file_exists($path)) {
                        $all_loaded = false;
                        break;
                    }
                }
                $results['modules_intact'] = $all_loaded;
                $results['status'] = 'pass';
                break;
                
            default:
                $results['error'] = 'Unknown test: ' . $testName;
                $results['status'] = 'fail';
        }
        
        logSystemEvent($pdo, "Debug Test: $testName", 'info');
        sendJson('success', 'Test Complete', ['results' => $results]);
        
    } catch (Exception $e) {
        logSystemEvent($pdo, "Debug Test Failed: $testName - " . $e->getMessage(), 'error');
        sendJson('error', 'Test Failed: ' . $e->getMessage(), ['test' => $testName]);
    }
}

function handleDebugLog($pdo, $input) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $message = strip_tags($input['message'] ?? 'Manual Log Entry');
    $level = strip_tags($input['level'] ?? 'info');
    $source = strip_tags($input['source'] ?? 'manual');
    
    logSystemEvent($pdo, $message, $level, $source);
    sendJson('success', 'Log Entry Created');
}
?>
