<?php
/* =============================================================================
   WandWeb Portal API - Router (v33.2)
   ============================================================================= */

// 1. START BUFFERING
ob_start();

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// 2. CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// 3. SHUTDOWN FUNCTION
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        // Log to file for debugging
        error_log("[FATAL ERROR] " . json_encode($error), 3, '/tmp/wandweb_api_fatal.log');
        ob_clean(); 
        echo json_encode(['status' => 'error', 'message' => "Critical Server Error: " . $error['message'], 'file' => $error['file'], 'line' => $error['line']]);
        exit();
    }
    // Also check if output buffer is empty (might indicate early exit without response)
    if (ob_get_length() === 0) {
        error_log("[WARNING] Empty response buffer", 3, '/tmp/wandweb_api_fatal.log');
    }
});

try {
    // 4. LOAD CONFIG
    $possible_paths = [
        __DIR__ . '/../private/secrets.php',
        __DIR__ . '/../../private/secrets.php', 
        $_SERVER['DOCUMENT_ROOT'] . '/../private/secrets.php',
        '/workspaces/WWM-Portal/private/secrets.php'
    ];
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
    require_once __DIR__ . '/modules/files.php';

    $pdo = getDBConnection($secrets);
    
    // ** ENSURE LOGGING TABLE EXISTS (NON-FATAL) **
    try {
        if(function_exists('ensureLogSchema')) {
            ensureLogSchema($pdo);
        }
    } catch (Exception $e) {
        // Logging setup failure should not break the API
        error_log("Warning: Could not initialize logging: " . $e->getMessage());
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Log what we received for debugging
    error_log("API Request: " . json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'action' => ($input['action'] ?? 'none'), 'has_token' => !empty($input['token'])]), 3, '/tmp/wandweb_api_debug.log');
    
    // Handle both JSON and FormData (for file uploads)
    if (empty($input)) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_POST['action'] ?? '';

    // Ensure settings schema exists
    ensureSettingsSchema($pdo);

    // Clear buffer before processing to ensure no whitespace from includes leaks out
    ob_clean(); 

    switch ($action) {
        // Auth
        case 'login': handleLogin($pdo, $input, $secrets); break;
        case 'register': handleRegister($pdo, $input, $secrets); break;
        case 'set_password': handleSetPassword($pdo, $input); break;
        case 'get_notifications': handleGetNotifications($pdo, $input); break;
        case 'mark_read': handleMarkRead($pdo, $input); break;
        case 'mark_all_read': handleMarkAllRead($pdo, $input); break;

		// Support & Ticketing
  		case 'get_tickets': require_once __DIR__.'/modules/support.php'; handleGetTickets($pdo, $input); break;
    	case 'get_ticket_thread': require_once __DIR__.'/modules/support.php'; handleGetTicketThread($pdo, $input); break;
        case 'create_ticket': require_once __DIR__.'/modules/support.php'; handleCreateTicket($pdo, $input, $secrets); break;
        case 'create_ticket_from_insight': require_once __DIR__.'/modules/support.php'; handleCreateTicketFromInsight($pdo, $input); break;
     	case 'reply_ticket': require_once __DIR__.'/modules/support.php'; handleReplyTicket($pdo, $input, $secrets); break;
     	case 'update_ticket_status': require_once __DIR__.'/modules/support.php'; handleUpdateTicketStatus($pdo, $input); break;
        case 'escalate_ticket': require_once __DIR__.'/modules/support.php'; handleEscalateTicket($pdo, $input); break;
     	case 'suggest_solution': require_once __DIR__.'/modules/support.php'; handleSuggestSolution($pdo, $input, $secrets); break;
        
        // Files (Google Drive Integration)
        case 'get_files': handleGetFiles($pdo, $input); break;
        case 'upload_file': handleUploadFile($pdo, $input, $secrets); break;
        case 'delete_file': handleDeleteFile($pdo, $input, $secrets); break;
        case 'download_file': handleDownloadFile($pdo, $input, $secrets); break;

        // Projects
        case 'get_admin_dashboard': handleGetAdminDashboard($pdo, $input, $secrets); break;
        case 'get_projects': handleGetProjects($pdo, $input); break;
        case 'create_project': handleCreateProject($pdo, $input); break;
        case 'ai_create_project': handleAICreateProject($pdo, $input, $secrets); break; // NEW AI HANDLER
        case 'update_project_status': handleUpdateProjectStatus($pdo, $input, $secrets); break;
        case 'assign_project_manager': handleAssignProjectManager($pdo, $input); break;
        case 'delete_project': handleDeleteProject($pdo, $input); break;
        case 'get_project_details': handleGetProjectDetails($pdo, $input); break;
        case 'save_task': handleSaveTask($pdo, $input); break;
        case 'delete_task': handleDeleteTask($pdo, $input); break;
        case 'toggle_task': handleToggleTask($pdo, $input); break;
        case 'post_comment': handlePostComment($pdo, $input); break;

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
        case 'refund_payment': handleRefund($pdo, $input, $secrets); break;
        
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
        case 'get_partners': handleGetPartners($pdo, $input); break;
        case 'assign_partner': handleAssignPartner($pdo, $input); break;
        case 'unassign_partner': handleUnassignPartner($pdo, $input); break;
        case 'get_client_details': handleGetClientDetails($pdo, $input, $secrets); break;
        case 'create_client': handleCreateClient($pdo, $input, $secrets); break;
        case 'update_client': handleUpdateClient($pdo, $input, $secrets); break;
        case 'client_self_update': handleClientSelfUpdate($pdo, $input, $secrets); break;

        case 'update_user_role': handleUpdateUserRole($pdo, $input); break; // NEW
        case 'assign_client_partner': handleAssignClientToPartner($pdo, $input); break; // NEW

        case 'send_onboarding_link': handleSendOnboardingLink($pdo, $input); break;
        case 'submit_onboarding': handleSubmitOnboarding($pdo, $input, $secrets); break;
        case 'import_crm_clients': handleImportCRMClients($pdo, $input, $secrets); break;
        case 'import_stripe_clients': handleImportStripeClients($pdo, $input, $secrets); break;
        case 'ai_request': handleAI($pdo, $input, $secrets); break;
        case 'get_my_profile': handleGetMyProfile($pdo, $input); break;
        
        // Settings
        case 'get_settings': handleGetSettings($pdo, $input); break;
        case 'update_settings': handleUpdateSettings($pdo, $input); break;

        // Recovery & Logs (CRITICAL)
        case 'get_all_users': handleGetAllUsers($pdo, $input); break;
        case 'fix_user_account': handleFixUserAccount($pdo, $input, $secrets); break;
        case 'get_partner_dashboard': handleGetPartnerDashboard($pdo, $input); break;
        case 'get_system_logs': handleGetSystemLogs($pdo, $input); break; // Fixed
        case 'debug_log': handleDebugLog($pdo, $input); break; // Debug logging for portal
        case 'debug_test': handleDebugTest($pdo, $input, $secrets); break; // Diagnostic tests

        default: 
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Invalid Action: ' . $action]);
            break;
    }

} catch (Exception $e) {
    // 1. Log to Database for Admin Dashboard visibility
    // We check if the logger exists first to avoid double-crashing
    if (function_exists('logSystemEvent') && isset($pdo)) {
        try {
            logSystemEvent($pdo, 'CRASH: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error');
        } catch (Exception $logErr) {
            // Fallback if DB is gone
            error_log("WandWeb Fatal Logging Error: " . $logErr->getMessage());
        }
    }
    
    // 2. Clear Buffer and Return JSON
    ob_clean(); 
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit();
}

// FINAL SAFETY: If we somehow get here without exiting, return error
ob_clean();
echo json_encode(['status' => 'error', 'message' => 'API completed without response']);
exit();
?>