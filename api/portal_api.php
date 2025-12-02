<?php
/* =============================================================================
   WandWeb Portal API - Router with Safety Buffer
   ============================================================================= */

// 1. START BUFFERING (Captures any stray errors/warnings)
ob_start();

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// 2. CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// 3. SHUTDOWN FUNCTION (Catches Fatal Errors)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        ob_clean(); // Clear broken HTML
        echo json_encode(['status' => 'error', 'message' => "Critical Server Error: " . $error['message'] . " on line " . $error['line']]);
        exit();
    }
});

try {
    // 4. LOAD CONFIG
    $possible_paths = [__DIR__ . '/../../private/secrets.php', $_SERVER['DOCUMENT_ROOT'] . '/../private/secrets.php'];
    $secrets_path = null;
    foreach ($possible_paths as $path) { if (file_exists($path)) { $secrets_path = $path; break; } }
    if (!$secrets_path) throw new Exception('Configuration missing (secrets.php).');
    $secrets = require($secrets_path);

    // 5. LOAD MODULES
    require_once __DIR__ . '/modules/utils.php';
    require_once __DIR__ . '/modules/auth.php';
    require_once __DIR__ . '/modules/projects.php';
    require_once __DIR__ . '/modules/billing.php';
    require_once __DIR__ . '/modules/clients.php';

    $pdo = getDBConnection($secrets);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $_POST['action'] ?? '';

    // Clear buffer before processing to ensure no whitespace from includes leaks out
    ob_clean(); 

    switch ($action) {
        // Auth
        case 'login': handleLogin($pdo, $input, $secrets); break;
        case 'register': handleRegister($pdo, $input, $secrets); break;
        case 'set_password': handleSetPassword($pdo, $input); break;
        case 'get_notifications': handleGetNotifications($pdo, $input); break;
        case 'mark_read': handleMarkRead($pdo, $input); break;

		// Support & Ticketing
  		case 'get_tickets': require_once __DIR__.'/modules/support.php'; handleGetTickets($pdo, $input); break;
    	case 'get_ticket_thread': require_once __DIR__.'/modules/support.php'; handleGetTicketThread($pdo, $input); break;
    	case 'create_ticket': require_once __DIR__.'/modules/support.php'; handleCreateTicket($pdo, $input, $secrets); break;
    	case 'reply_ticket': require_once __DIR__.'/modules/support.php'; handleReplyTicket($pdo, $input); break;
    	case 'update_ticket_status': require_once __DIR__.'/modules/support.php'; handleUpdateTicketStatus($pdo, $input); break;
    	case 'suggest_solution': require_once __DIR__.'/modules/support.php'; handleSuggestSolution($input, $secrets); break;	
			
        // Projects
        case 'get_admin_dashboard': handleGetAdminDashboard($pdo, $input, $secrets); break;
        case 'get_projects': handleGetProjects($pdo, $input); break;
        case 'create_project': handleCreateProject($pdo, $input); break;
        case 'update_project_status': handleUpdateProjectStatus($pdo, $input, $secrets); break;
        case 'delete_project': handleDeleteProject($pdo, $input); break;
        case 'get_project_details': handleGetProjectDetails($pdo, $input); break;
        case 'save_task': handleSaveTask($pdo, $input); break;
        case 'toggle_task': handleToggleTask($pdo, $input); break;
        case 'post_comment': handlePostComment($pdo, $input); break;
        case 'get_files': handleGetFiles($pdo, $input); break;
        case 'upload_file': handleUploadFile($pdo, $input); break;

        // Billing
        case 'get_billing_overview': handleGetBilling($pdo, $input, $secrets); break;
        case 'get_services': handleGetServices($pdo, $input, $secrets, verifyAuth($input)); break; 
        case 'create_invoice': handleCreateInvoice($pdo, $input, $secrets); break;
        case 'create_quote': handleCreateQuote($pdo, $input, $secrets); break;
        case 'get_invoice_details': handleGetInvoiceDetails($pdo, $input, $secrets); break;
        case 'update_invoice_draft': handleUpdateInvoiceDraft($pdo, $input, $secrets); break;
        case 'invoice_action': handleInvoiceAction($pdo, $input, $secrets); break;
        case 'quote_action': handleQuoteAction($pdo, $input, $secrets); break;
        case 'get_stripe_portal': handleStripePortal($pdo, $input, $secrets); break;
        case 'create_subscription_manually': handleCreateSubscriptionManually($pdo, $input, $secrets); break;
        case 'subscription_action': handleSubscriptionAction($pdo, $input, $secrets); break;
        
        // Products
        case 'create_product': handleCreateProduct($pdo, $input, $secrets); break;
        case 'update_product': handleUpdateProduct($pdo, $input, $secrets); break;
        case 'delete_product': handleDeleteProduct($pdo, $input, $secrets); break;
        case 'toggle_product_visibility': handleToggleProductVisibility($pdo, $input, $secrets); break;
        case 'save_service_order': handleSaveServiceOrder($pdo, $input); break;
        case 'create_coupon': handleCreateCoupon($pdo, $input, $secrets); break;
        case 'create_checkout': handleCreateCheckout($pdo, $input, $secrets); break;

        // Clients & AI
        case 'get_clients': handleGetClients($pdo, $input); break;
        case 'get_client_details': handleGetClientDetails($pdo, $input, $secrets); break;
        case 'create_client': handleCreateClient($pdo, $input, $secrets); break;
        case 'update_client': handleUpdateClient($pdo, $input, $secrets); break;
        case 'send_onboarding_link': handleSendOnboardingLink($pdo, $input); break;
        case 'submit_onboarding': handleSubmitOnboarding($pdo, $input, $secrets); break;
        case 'import_crm_clients': handleImportCRMClients($pdo, $input, $secrets); break;
        case 'import_stripe_clients': handleImportStripeClients($pdo, $input, $secrets); break;
        case 'ai_request': handleAI($input, $secrets); break;

        default: 
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Invalid Action: ' . $action]);
            break;
    }

} catch (Exception $e) {
    ob_clean(); // Clear any partial output
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>