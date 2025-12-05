<?php
// =============================================================================
// WandWeb Portal API (Restored)
// =============================================================================

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => "Critical Server Error: " . $error['message']]);
        exit();
    }
});

try {
    // Load Config
    $possible_paths = [
        __DIR__ . '/../private/secrets.php',
        __DIR__ . '/../../private/secrets.php',
        $_SERVER['DOCUMENT_ROOT'] . '/../private/secrets.php',
        '/workspaces/WWM-Portal/private/secrets.php'
    ];
    
    $secrets_path = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $secrets_path = $path;
            break;
        }
    }
    
    if (!$secrets_path) {
        // Fallback for dev/test
        $secrets = ['DB_DSN' => 'sqlite:' . __DIR__ . '/../../data/portal.sqlite'];
    } else {
        $secrets = require($secrets_path);
    }

    // Load Modules
    require_once __DIR__ . '/modules/utils.php';
    require_once __DIR__ . '/modules/auth.php';
    require_once __DIR__ . '/modules/projects.php';
    require_once __DIR__ . '/modules/billing.php';
    require_once __DIR__ . '/modules/clients.php';
    require_once __DIR__ . '/modules/files.php';
    require_once __DIR__ . '/modules/support.php';

    $pdo = getDBConnection($secrets);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';

    // Ensure core tables exist (using the new Polyfill)
    ensureUserSchema($pdo);
    ensureSettingsSchema($pdo);
    ensureLogSchema($pdo);

    ob_clean(); // Clear any previous output

    switch ($action) {
        case 'login': handleLogin($pdo, $input, $secrets); break;
        case 'get_admin_dashboard': handleGetAdminDashboard($pdo, $input, $secrets); break;
        case 'get_projects': handleGetProjects($pdo, $input); break;
        case 'get_billing_overview': handleGetBilling($pdo, $input, $secrets); break;
        case 'refund_payment': handleRefund($pdo, $input, $secrets); break;
        
        // Standard Handlers
        case 'get_files': handleGetFiles($pdo, $input); break;
        case 'upload_file': handleUploadFile($pdo, $input, $secrets); break;
        case 'delete_file': handleDeleteFile($pdo, $input, $secrets); break;
        case 'get_system_logs': handleGetSystemLogs($pdo, $input); break;
        case 'debug_test': handleDebugTest($pdo, $input, $secrets); break;
        case 'debug_log': handleDebugLog($pdo, $input); break;
        case 'get_tickets': handleGetTickets($pdo, $input); break;
        case 'get_ticket_thread': handleGetTicketThread($pdo, $input); break;
        case 'create_ticket': handleCreateTicket($pdo, $input, $secrets); break;
        case 'reply_ticket': handleReplyTicket($pdo, $input, $secrets); break;
        case 'update_ticket_status': handleUpdateTicketStatus($pdo, $input); break;
        case 'get_clients': handleGetClients($pdo, $input); break;
        case 'get_partners': handleGetPartners($pdo, $input); break;
        case 'create_client': handleCreateClient($pdo, $input, $secrets); break;
        
        default: 
            if (function_exists('handle' . str_replace('_', '', ucwords($action, '_')))) {
                call_user_func('handle' . str_replace('_', '', ucwords($action, '_')), $pdo, $input, $secrets);
            } else {
                sendJson('error', 'Invalid Action: ' . $action);
            }
            break;
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}

?>
