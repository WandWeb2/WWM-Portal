<?php
// /api/modules/clients.php (Updated AI Logic)

// ... (Keep handleGetClients, handleGetClientDetails, handleCreateClient, handleUpdateClient, handleSendOnboardingLink, handleSubmitOnboarding exactly as they were) ...
// =============================================================================
// Wandering Webmaster Custom Component
// Agency: Wandering Webmaster (wandweb.co)
// Client: Portal Architecture
// Version: 30.2
// =============================================================================
// --- VERSION HISTORY ---
// 30.1 - Existing Logic
// 30.2 - Added syncClientToExternal and handleClientSelfUpdate

// [HELPER] Sync local user data to Stripe & CRM
function syncClientToExternal($pdo, $uid, $secrets) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?"); 
    $s->execute([$uid]); 
    $u = $s->fetch();
    if (!$u) return;

    // 1. Sync to Stripe (if connected)
    if(!empty($u['stripe_id'])) {
        stripeRequest($secrets, 'POST', "customers/{$u['stripe_id']}", [
            'name' => $u['full_name'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'metadata' => ['business_name' => $u['business_name']]
        ]);
    }

    // 2. Sync to SwipeOne CRM
    pushToSwipeOne($secrets, 'contacts', [
        'email' => $u['email'],
        'firstName' => $u['full_name'],
        'phone' => $u['phone'],
        'properties' => ['business_name' => $u['business_name']]
    ]);
}

// [NEW] Client Self-Service Update
function handleClientSelfUpdate($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    // Strict ID check: Users can only update themselves
    $uid = $user['uid'];
    
    $full_name = strip_tags($input['full_name']);
    $business = strip_tags($input['business_name']);
    $phone = strip_tags($input['phone']);
    $address = strip_tags($input['address']);
    $website = strip_tags($input['website']);
    $position = strip_tags($input['position']);

    // Update DB
    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, address=?, website=?, position=? WHERE id=?")
        ->execute([$full_name, $business, $phone, $address, $website, $position, $uid]);

    // Trigger External Sync
    syncClientToExternal($pdo, $uid, $secrets);

    sendJson('success', 'Profile Updated & Synced');
}
function handleGetClients($pdo, $i) {
    $u = verifyAuth($i); 
    // SECURITY FIX: Strictly restrict to Admin
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    ensureUserSchema($pdo);
    $s = $pdo->query("SELECT id, full_name, email, business_name, phone, website, status, role, created_at FROM users WHERE role IN ('client', 'partner') ORDER BY created_at DESC");
    sendJson('success', 'Fetched', ['clients' => $s->fetchAll()]);
}

function handleGetClientDetails($pdo, $input, $secrets) {
    $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $clientId = (int)$input['client_id'];
    
    // Fetch basic details
    $stmt = $pdo->prepare("SELECT id, full_name, email, business_name, phone, status, role, stripe_id FROM users WHERE id = ?"); 
    $stmt->execute([$clientId]); 
    $client = $stmt->fetch();
    
    $projects = []; 
    $stmt = $pdo->prepare("SELECT id, title, status, health_score FROM projects WHERE user_id = ?"); 
    $stmt->execute([$clientId]); 
    $projects = $stmt->fetchAll();

    // IF PARTNER: Fetch assigned clients
    $managedClients = [];
    if ($client['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $s = $pdo->prepare("SELECT u.id, u.full_name, u.business_name FROM partner_assignments pa JOIN users u ON pa.client_id = u.id WHERE pa.partner_id = ?");
        $s->execute([$clientId]);
        $managedClients = $s->fetchAll();
    }

    $invoices = []; $subscriptions = [];
    if (!empty($client['stripe_id'])) {
        $sid = $client['stripe_id'];
        $rawInv = stripeRequest($secrets, 'GET', "invoices?customer=$sid&limit=10");
        foreach ($rawInv['data'] ?? [] as $i) $invoices[] = ['id'=>$i['id'], 'number'=>$i['number'], 'amount'=>number_format($i['total']/100,2), 'status'=>$i['status'], 'date'=>date('Y-m-d', $i['created']), 'pdf'=>$i['invoice_pdf']];
        $rawSub = stripeRequest($secrets, 'GET', "subscriptions?customer=$sid&limit=5&expand%5B%5D=data.plan.product");
        foreach ($rawSub['data'] ?? [] as $s) $subscriptions[] = ['id'=>$s['id'], 'plan'=>$s['plan']['product']['name'], 'amount'=>number_format($s['plan']['amount']/100,2), 'interval'=>$s['plan']['interval'], 'next_bill'=>date('Y-m-d', $s['current_period_end'])];
    }
    
    sendJson('success', 'Details', ['client' => $client, 'projects' => $projects, 'invoices' => $invoices, 'subscriptions' => $subscriptions, 'managed_clients' => $managedClients]);
}

function handleCreateClient($pdo,$i,$s){
    $u=verifyAuth($i); if($u['role']!=='admin')sendJson('error','Unauthorized'); ensureUserSchema($pdo);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$i['email']]); if ($stmt->fetch()) sendJson('error', 'Email exists.');
    $status = $i['send_invite'] ? 'pending_invite' : 'active';
    $pdo->prepare("INSERT INTO users (email, full_name, business_name, phone, role, status) VALUES (?, ?, ?, ?, 'client', ?)")->execute([$i['email'], $i['full_name'], $i['business_name'], $i['phone'], $status]);
    if ($i['send_invite']) sendInvite($pdo, $i['email']);
    pushToSwipeOne($s, 'contacts', ['email'=>$i['email'], 'firstName'=>$i['full_name'], 'properties'=>['business_name'=>$i['business_name']]]);
    sendJson('success','Created');
}

function handleUpdateClient($pdo, $input, $secrets) {
    $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $pdo->prepare("UPDATE users SET full_name=?, email=?, business_name=?, phone=?, status=? WHERE id=?")->execute([$input['full_name'], $input['email'], $input['business_name'], $input['phone'], $input['status'], (int)$input['client_id']]);
    // ADD THIS LINE:
    syncClientToExternal($pdo, (int)$input['client_id'], $secrets);
    sendJson('success', 'Updated');
}

function handleUpdateUserRole($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $newRole = $i['role']; // 'client' or 'partner'
    if (!in_array($newRole, ['client', 'partner'])) sendJson('error', 'Invalid Role');
    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, (int)$i['client_id']]);
    sendJson('success', 'Role Updated');
}

function handleAssignClientToPartner($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    $partnerId = (int)$i['partner_id'];
    $clientId = (int)$i['client_id'];
    
    if ($i['action_type'] === 'remove') {
        $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id=? AND client_id=?")->execute([$partnerId, $clientId]);
        sendJson('success', 'Assignment Removed');
    } else {
        // Add (Ignore duplicates via REPLACE or try/catch unique)
        try {
            $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]);
            sendJson('success', 'Client Assigned');
        } catch (Exception $e) {
            sendJson('success', 'Already Assigned');
        }
    }
}

function handleSendOnboardingLink($pdo, $input) { verifyAuth($input); sendInvite($pdo, $input['email']); sendJson('success', 'Sent'); }
function handleSubmitOnboarding($pdo, $input, $secrets) {
    $token = $input['onboarding_token'];
    $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()"); $stmt->execute([$token]); $invite = $stmt->fetch();
    if (!$invite) sendJson('error', 'Invalid Link');
    $email = $invite['email'];
    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?")->execute([trim(($input['first_name']??'').' '.($input['last_name']??'')), $input['business_name'], $input['phone'], $input['website'], $email]);
    $uid = $pdo->query("SELECT id FROM users WHERE email='$email'")->fetchColumn();
    $pdo->prepare("INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')")->execute([$uid, "New Project: ".$input['business_name'], $input['scope']]);
    $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
    sendJson('success', 'Complete');
}

// === AI HANDLER WITH SUPPORT TRIGGER ===
// === AI HANDLER WITH SUPPORT TRIGGER ===
function handleAI($pdo, $i, $s) {
    $user = verifyAuth($i);
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'AI', ['text' => 'Config Error: API Key missing.']);

    $websiteContext = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Website data unavailable.";
    $dashboardContext = isset($i['data_context']) ? json_encode($i['data_context']) : "No active dashboard data.";

    // SYSTEM PROMPT: PROFESSIONAL EXECUTIVE ASSISTANT
    $basePrompt = "You are the WandWeb Executive Assistant.
    CONTEXT: $dashboardContext
    KB: $websiteContext
    
    PROTOCOL:
    1. Tone: Professional, concise, corporate, and proactive.
    2. If the user asks a question answered in the KB, answer it directly.
    3. **CRITICAL:** If the user reports a problem, asks for a human, or seems frustrated, respond with a brief summary of the issue and then APPEND this exact tag to the end of your message:
       [ACTION:OPEN_TICKET]
    4. If you append the tag, say 'I can open a support ticket for you immediately.'";

    if ($user['role'] === 'admin') {
        $systemPrompt = $basePrompt . "\n USER IS EXECUTIVE (Captain). You have full visibility.";
    } else {
        $systemPrompt = $basePrompt . "\n USER IS CLIENT (Stakeholder). Be extremely service-oriented.";
    }

    $userMessage = $i['prompt'];

    // USE SELF-HEALING GATEWAY
    $d = callGeminiAI($pdo, $s, $systemPrompt, $userMessage);
    
    // Improved Error Handling to catch "System Offline" causes
    if (!empty($d['error'])) {
        $text = "AI Error: " . ($d['error']['message'] ?? 'Unknown API Error');
    } else {
        $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? 'System offline (No candidates returned).';
    }

    // Detect actionable ticket tag appended by the Executive Assistant persona.
    if (stripos($text, '[ACTION:OPEN_TICKET]') !== false) {
        $clean = trim(str_ireplace('[ACTION:OPEN_TICKET]', '', $text));
        // Return flag for frontend to create a ticket via the dedicated endpoint
        sendJson('success', 'AI', ['text' => $clean, 'open_ticket' => true, 'insight' => $clean]);
    } else {
        sendJson('success', 'AI', ['text' => $text]);
    }
}

// ... (Keep handleGetAdminDashboard, handleImportCRMClients, handleImportStripeClients, getGoogleAccessToken) ...
function handleGetAdminDashboard($pdo, $i, $s) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn(); $p = $pdo->query("SELECT SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status='onboarding' THEN 1 ELSE 0 END) as onboarding FROM projects")->fetch(); $b = stripeRequest($s, 'GET', 'balance'); $av = ($b['available'][0]['amount'] ?? 0) / 100; $pe = ($b['pending'][0]['amount'] ?? 0) / 100; $re = 0; if (isset($b['connect_reserved'][0])) $re += $b['connect_reserved'][0]['amount'] / 100; $sym = strtoupper($b['available'][0]['currency'] ?? 'USD') === 'AUD' ? 'A$' : '$'; sendJson('success', 'Loaded', ['stats' => ['total_clients' => $c, 'active_projects' => $p['active'] ?? 0, 'onboarding_projects' => $p['onboarding'] ?? 0, 'stripe_available' => $sym . number_format($av, 2), 'stripe_incoming' => $sym . number_format($pe, 2), 'stripe_reserved' => $sym . number_format($re, 2)]]); }
function handleImportCRMClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $logs = []; $added = 0; if (!empty($secrets['SWIPEONE_API_KEY'])) { $url = "https://api.swipeone.com/api/workspaces/" . $secrets['SWIPEONE_WORKSPACE_ID'] . "/contacts"; $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: " . $secrets['SWIPEONE_API_KEY'], "Content-Type: application/json"]); $crmRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($crmRes['data'])) { foreach ($crmRes['data'] as $c) { $email = $c['email'] ?? ''; if (!$email) continue; $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$email]); if (!$stmt->fetch()) { $pdo->prepare("INSERT INTO users (email, full_name, role, status) VALUES (?, ?, 'client', 'pending_invite')")->execute([$email, trim(($c['firstName']??'').' '.($c['lastName']??''))]); $logs[] = "[CRM] Imported $email"; $added++; } } } else { $logs[] = "[CRM] No contacts found."; } } else { $logs[] = "[CRM] Skipped (Missing Config)"; } if (!empty($secrets['GOOGLE_REFRESH_TOKEN'])) { $accessToken = getGoogleAccessToken($secrets); if ($accessToken) { $gUrl = "https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers,organizations,metadata&pageSize=100"; $ch = curl_init($gUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Accept: application/json"]); $gRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($gRes['connections'])) { foreach ($gRes['connections'] as $person) { $email = $person['emailAddresses'][0]['value'] ?? ''; if (!$email) continue; $name = $person['names'][0]['displayName'] ?? 'Google Client'; $googleId = $person['resourceName'] ?? ''; $stmt = $pdo->prepare("SELECT id, google_resource_name FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if(empty($existing['google_resource_name'])) { $pdo->prepare("UPDATE users SET google_resource_name = ? WHERE id = ?")->execute([$googleId, $existing['id']]); $logs[] = "[GOOGLE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, google_resource_name, role, status) VALUES (?, ?, ?, 'client', 'pending_invite')")->execute([$email, $name, $googleId]); $logs[] = "[GOOGLE] Imported $email"; $added++; } } } else { $logs[] = "[GOOGLE] No connections found."; } } else { $logs[] = "[GOOGLE] Auth failed."; } } else { $logs[] = "[GOOGLE] Skipped."; } sendJson('success', "Sync Cycle Complete", ['logs' => $logs]); }
function handleImportStripeClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $response = stripeRequest($secrets, 'GET', 'customers?limit=100'); $customers = $response['data'] ?? []; $logs = []; foreach ($customers as $c) { $email = $c['email']; if (!$email) continue; $stmt = $pdo->prepare("SELECT id, stripe_id FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if (empty($existing['stripe_id'])) { $pdo->prepare("UPDATE users SET stripe_id = ? WHERE email = ?")->execute([$c['id'], $email]); $logs[] = "[STRIPE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, stripe_id, role, status) VALUES (?, ?, ?, 'client', 'active')")->execute([$email, $c['name'], $c['id']]); $logs[] = "[STRIPE] Imported $email"; } } sendJson('success', "Stripe Sync Complete", ['logs' => $logs]); }

// === NEW: SETTINGS HANDLERS ===
function handleGetSettings($pdo, $i) {
    $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureSettingsSchema($pdo);
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    sendJson('success', 'Settings loaded', ['settings' => $settings]);
}

function handleUpdateSettings($pdo, $i) {
    $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureSettingsSchema($pdo);
    foreach ($i['settings'] ?? [] as $key => $value) {
        $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }
    sendJson('success', 'Settings saved');
}

// [OPTIONAL] Self profile fetch for modal population
function handleGetMyProfile($pdo, $input) {
    $u = verifyAuth($input);
    $stmt = $pdo->prepare("SELECT full_name, business_name, email, phone, website, address, position FROM users WHERE id = ?");
    $stmt->execute([$u['uid']]);
    sendJson('success', 'Profile Loaded', ['profile' => $stmt->fetch()]);
}

function handleGetPartners($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureUserSchema($pdo);
    $s = $pdo->query("SELECT id, full_name, email, phone, created_at FROM users WHERE role = 'partner' ORDER BY full_name ASC");
    sendJson('success', 'Partners fetched', ['partners' => $s->fetchAll()]);
}

function handleAssignPartner($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    
    $partnerId = (int)($i['partner_id'] ?? 0);
    $clientId = (int)($i['client_id'] ?? 0);
    
    if (!$partnerId || !$clientId) sendJson('error', 'Invalid IDs');
    
    // Insert or ignore
    try {
        $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]);
    } catch (Exception $e) {
        // Already assigned
    }
    
    // Notify partner
    $c = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $c->execute([$clientId]);
    $client = $c->fetch();
    if ($client) {
        createNotification($pdo, $partnerId, "You have been assigned to client: " . ($client['full_name'] ?? 'New Client'));
    }
    
    sendJson('success', 'Partner assigned');
}

function handleUnassignPartner($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    
    $partnerId = (int)($i['partner_id'] ?? 0);
    $clientId = (int)($i['client_id'] ?? 0);
    
    $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id = ? AND client_id = ?")->execute([$partnerId, $clientId]);
    sendJson('success', 'Partner unassigned');
}
?>
