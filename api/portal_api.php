<?php
// =============================================================================
// Wandering Webmaster Portal API
// Version: 30.2 (Emergency Fix)
// =============================================================================

// 1. Security Headers & CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle Preflight Requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. Error Handling (Prevents 500 Silent Deaths)
ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Critical Server Error', 'debug' => $error['message']]);
    }
});

// 3. Load Dependencies
try {
    $secrets = [];
    if (file_exists(__DIR__ . '/../secrets.php')) {
        include __DIR__ . '/../secrets.php';
    } else {
        // Fallback for dev environments
        $secrets = [
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'wandweb_portal',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'STRIPE_SECRET_KEY' => '',
            'GEMINI_API_KEY' => ''
        ];
    }

    require_once __DIR__ . '/modules/utils.php';
    require_once __DIR__ . '/modules/auth.php';
    require_once __DIR__ . '/modules/projects.php';
    require_once __DIR__ . '/modules/files.php';
    require_once __DIR__ . '/modules/clients.php';
    require_once __DIR__ . '/modules/billing.php';
    require_once __DIR__ . '/modules/support.php';

    // 4. Initialize Database
    $pdo = getDBConnection($secrets);

    // 5. Parse Input
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // 6. Router
    switch ($action) {
        // --- AUTH ---
        case 'login': handleLogin($pdo, $input); break;
        case 'set_password': handleSetPassword($pdo, $input); break;
        case 'check_auth': handleCheckAuth($pdo, $input); break;
        
        // --- DASHBOARD ---
        case 'get_dashboard_stats': handleGetDashboardStats($pdo, $input); break;
        case 'get_recent_activity': handleGetRecentActivity($pdo, $input); break;

        // --- PROJECTS ---
        case 'get_projects': handleGetProjects($pdo, $input); break;
        case 'create_project': handleCreateProject($pdo, $input); break;
        case 'update_project_status': handleUpdateProjectStatus($pdo, $input); break;
        case 'get_project_details': handleGetProjectDetails($pdo, $input); break;

        // --- FILES ---
        case 'get_files': handleGetFiles($pdo, $input); break;
        case 'upload_file': handleUploadFile($pdo, $_FILES, $_POST); break; 
        case 'delete_file': handleDeleteFile($pdo, $input); break;

        // --- CLIENTS ---
        case 'get_clients': handleGetClients($pdo, $input); break;
        case 'create_client': handleCreateClient($pdo, $input); break;
        case 'update_client': handleUpdateClient($pdo, $input); break;
        case 'invite_client': handleInviteClient($pdo, $input); break;
        
        // --- BILLING & COMMERCE ---
        case 'get_billing_overview': handleGetBilling($pdo, $input, $secrets); break;
        case 'create_invoice': handleCreateInvoice($pdo, $input, $secrets); break;
        case 'update_invoice_draft': handleUpdateInvoiceDraft($pdo, $input, $secrets); break;
        case 'get_invoice_details': handleGetInvoiceDetails($pdo, $input, $secrets); break;
        case 'create_quote': handleCreateQuote($pdo, $input, $secrets); break;
        case 'quote_action': handleQuoteAction($pdo, $input, $secrets); break;
        case 'invoice_action': handleInvoiceAction($pdo, $input, $secrets); break;
        case 'create_subscription_manually': handleCreateSubscriptionManually($pdo, $input, $secrets); break;
        case 'subscription_action': handleSubscriptionAction($pdo, $input, $secrets); break;
        case 'get_stripe_portal': handleStripePortal($pdo, $input, $secrets); break;
        case 'refund_payment': handleRefund($pdo, $input, $secrets); break;

        // --- PRODUCTS & SERVICES ---
        case 'get_services_list': handleGetServices($pdo, $input, $secrets, verifyAuth($input)); break;
        case 'create_product': handleCreateProduct($pdo, $input, $secrets); break;
        case 'update_product': handleUpdateProduct($pdo, $input, $secrets); break;
        case 'delete_product': handleDeleteProduct($pdo, $input, $secrets); break;
        case 'toggle_product_visibility': handleToggleProductVisibility($pdo, $input, $secrets); break;
        case 'create_coupon': handleCreateCoupon($pdo, $input, $secrets); break;
        case 'create_checkout_session': handleCreateCheckout($pdo, $input, $secrets); break;
        case 'save_service_order': handleSaveServiceOrder($pdo, $input); break;

        // --- SUPPORT ---
        case 'get_tickets': handleGetTickets($pdo, $input); break;
        case 'create_ticket': handleCreateTicket($pdo, $input); break;
        case 'update_ticket': handleUpdateTicket($pdo, $input); break;

        // --- SYSTEM ---
        case 'get_logs': 
            $u = verifyAuth($input); 
            if ($u['role'] === 'admin') {
                $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY id DESC LIMIT 100");
                sendJson('success', 'Logs', ['logs' => $stmt->fetchAll()]);
            }
            break;

        default:
            sendJson('error', 'Invalid Action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

?>
