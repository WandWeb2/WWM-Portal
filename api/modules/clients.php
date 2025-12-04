<?php
// /api/modules/clients.php
// Version: 33.1 - Robust Recovery & Broad Partner Search

// [HELPER] Sync local user data to Stripe & CRM
function syncClientToExternal($pdo, $uid, $secrets) {
    try {
        $s = $pdo->prepare("SELECT * FROM users WHERE id=?"); 
        $s->execute([$uid]); 
        $u = $s->fetch();
        if (!$u) return;

        if(!empty($u['stripe_id']) && function_exists('stripeRequest')) {
            stripeRequest($secrets, 'POST', "customers/{$u['stripe_id']}", [
                'name' => $u['full_name'],
                'email' => $u['email'],
                'metadata' => ['business_name' => $u['business_name']]
            ]);
        }
        
        if(function_exists('pushToSwipeOne')) {
            pushToSwipeOne($secrets, 'contacts', [
                'email' => $u['email'],
                'firstName' => $u['full_name'],
                'properties' => ['business_name' => $u['business_name']]
            ]);
        }
        if(function_exists('logSystemEvent')) logSystemEvent($pdo, "Synced User #$uid", 'info');
    } catch (Exception $e) {
        if(function_exists('logSystemEvent')) logSystemEvent($pdo, "Sync Fail #$uid: " . $e->getMessage(), 'error');
    }
}

// [RECOVERY] Get Raw User List
function handleGetAllUsers($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    sendJson('success', 'Audit List Loaded', ['users' => $stmt->fetchAll()]);
}

// [RECOVERY] Force Fix Account (Smart Logic)
function handleFixUserAccount($pdo, $i, $s) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $uid = (int)$i['target_user_id'];
    $role = strtolower(trim($i['role'])); 
    $status = $i['status'];
    
    if(function_exists('logSystemEvent')) logSystemEvent($pdo, "Force Update User #$uid -> $role / $status", 'warning');

    try {
        // 1. Check Existence
        $check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $check->execute([$uid]);
        if (!$check->fetch()) sendJson('error', 'User ID not found in DB');

        // 2. Perform Update
        $stmt = $pdo->prepare("UPDATE users SET role = ?, status = ? WHERE id = ?");
        $stmt->execute([$role, $status, $uid]);
        
        // 3. Always Success (Even if rowCount is 0, it means they are already correct)
        syncClientToExternal($pdo, $uid, $s);
        sendJson('success', 'Account Verified & Synced');

    } catch (Exception $e) {
        if(function_exists('logSystemEvent')) logSystemEvent($pdo, "DB Error: " . $e->getMessage(), 'error');
        sendJson('error', 'Database Error: ' . $e->getMessage());
    }
}

// [AI] Handler
function handleAI($pdo, $i, $s) {
    $user = verifyAuth($i);
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'AI', ['text' => 'Config Error: API Key missing.']);

    $websiteContext = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Website data unavailable.";
    $dashboardContext = isset($i['data_context']) ? json_encode($i['data_context']) : "No active dashboard data.";

    $basePrompt = "You are the WandWeb Executive Assistant.
    CONTEXT: $dashboardContext
    KB: $websiteContext
    PROTOCOL:
    1. Tone: Professional, concise, corporate.
    2. If user reports a problem, append [ACTION:OPEN_TICKET].";

    $systemPrompt = ($user['role'] === 'admin') ? $basePrompt . "\n USER IS EXECUTIVE." : $basePrompt . "\n USER IS CLIENT.";
    
    $d = callGeminiAI($pdo, $s, $systemPrompt, $i['prompt']);
    
    $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? 'System offline.';

    if (stripos($text, '[ACTION:OPEN_TICKET]') !== false) {
        $clean = trim(str_ireplace('[ACTION:OPEN_TICKET]', '', $text));
        sendJson('success', 'AI', ['text' => $clean, 'open_ticket' => true, 'insight' => $clean]);
    } else {
        sendJson('success', 'AI', ['text' => $text]);
    }
}

// [PARTNER] Dashboard Stats
function handleGetPartnerDashboard($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM partner_assignments WHERE partner_id = ?");
    $cStmt->execute([$u['uid']]);
    $pStmt = $pdo->prepare("SELECT p.id, p.title, p.status, p.health_score, u.full_name as client_name FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) AND p.status != 'archived' ORDER BY p.updated_at DESC");
    $pStmt->execute([$u['uid']]);
    sendJson('success', 'Loaded', ['client_count' => $cStmt->fetchColumn(), 'projects' => $pStmt->fetchAll()]);
}

// [STANDARD] CRUD
function handleGetClients($pdo, $i) {
    $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureUserSchema($pdo);
    $s = $pdo->query("SELECT id, full_name, email, business_name, phone, website, status, role, created_at FROM users WHERE role IN ('client', 'partner') ORDER BY created_at DESC");
    sendJson('success', 'Fetched', ['clients' => $s->fetchAll()]);
}

function handleGetClientDetails($pdo, $input, $secrets) {
    $user = verifyAuth($input); 
    $clientId = (int)$input['client_id'];
    if ($user['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $check = $pdo->prepare("SELECT id FROM partner_assignments WHERE partner_id = ? AND client_id = ?");
        $check->execute([$user['uid'], $clientId]);
        if (!$check->fetch()) sendJson('error', 'Access Denied');
    } elseif ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $stmt = $pdo->prepare("SELECT id, full_name, email, business_name, phone, status, role, stripe_id FROM users WHERE id = ?"); 
    $stmt->execute([$clientId]); 
    $client = $stmt->fetch();
    
    $projects = []; 
    $stmt = $pdo->prepare("SELECT id, title, status, health_score FROM projects WHERE user_id = ?"); 
    $stmt->execute([$clientId]); 
    $projects = $stmt->fetchAll();

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
    syncClientToExternal($pdo, (int)$input['client_id'], $secrets);
    sendJson('success', 'Updated');
}

function handleClientSelfUpdate($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    $uid = $user['uid'];
    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, address=?, website=?, position=? WHERE id=?")
        ->execute([strip_tags($input['full_name']), strip_tags($input['business_name']), strip_tags($input['phone']), strip_tags($input['address']), strip_tags($input['website']), strip_tags($input['position']), $uid]);
    syncClientToExternal($pdo, $uid, $secrets);
    sendJson('success', 'Profile Updated');
}

function handleUpdateUserRole($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $newRole = $i['role']; if (!in_array($newRole, ['client', 'partner', 'admin'])) sendJson('error', 'Invalid Role');
    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, (int)$i['client_id']]);
    sendJson('success', 'Role Updated');
}

function handleAssignClientToPartner($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    if ($i['action_type'] === 'remove') {
        $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id=? AND client_id=?")->execute([(int)$i['partner_id'], (int)$i['client_id']]);
        sendJson('success', 'Removed');
    } else {
        try { $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([(int)$i['partner_id'], (int)$i['client_id']]); sendJson('success', 'Assigned'); }
        catch (Exception $e) { sendJson('success', 'Already Assigned'); }
    }
}

function handleSendOnboardingLink($pdo, $input) { verifyAuth($input); sendInvite($pdo, $input['email']); sendJson('success', 'Sent'); }
function handleSubmitOnboarding($pdo, $input, $secrets) { $token = $input['onboarding_token']; $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()"); $stmt->execute([$token]); $invite = $stmt->fetch(); if (!$invite) sendJson('error', 'Invalid Link'); $email = $invite['email']; $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?")->execute([trim(($input['first_name']??'').' '.($input['last_name']??'')), $input['business_name'], $input['phone'], $input['website'], $email]); $uid = $pdo->query("SELECT id FROM users WHERE email='$email'")->fetchColumn(); $pdo->prepare("INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')")->execute([$uid, "New Project: ".$input['business_name'], $input['scope']]); $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]); sendJson('success', 'Complete'); }
function handleGetAdminDashboard($pdo, $i, $s) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn(); $p = $pdo->query("SELECT SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status='onboarding' THEN 1 ELSE 0 END) as onboarding FROM projects")->fetch(); $b = stripeRequest($s, 'GET', 'balance'); $av = ($b['available'][0]['amount'] ?? 0) / 100; $pe = ($b['pending'][0]['amount'] ?? 0) / 100; $re = 0; if (isset($b['connect_reserved'][0])) $re += $b['connect_reserved'][0]['amount'] / 100; $sym = strtoupper($b['available'][0]['currency'] ?? 'USD') === 'AUD' ? 'A$' : '$'; sendJson('success', 'Loaded', ['stats' => ['total_clients' => $c, 'active_projects' => $p['active'] ?? 0, 'onboarding_projects' => $p['onboarding'] ?? 0, 'stripe_available' => $sym . number_format($av, 2), 'stripe_incoming' => $sym . number_format($pe, 2), 'stripe_reserved' => $sym . number_format($re, 2)]]); }
function handleImportCRMClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $logs = []; $added = 0; if (!empty($secrets['SWIPEONE_API_KEY'])) { $url = "https://api.swipeone.com/api/workspaces/" . $secrets['SWIPEONE_WORKSPACE_ID'] . "/contacts"; $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: " . $secrets['SWIPEONE_API_KEY'], "Content-Type: application/json"]); $crmRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($crmRes['data'])) { foreach ($crmRes['data'] as $c) { $email = $c['email'] ?? ''; if (!$email) continue; $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$email]); if (!$stmt->fetch()) { $pdo->prepare("INSERT INTO users (email, full_name, role, status) VALUES (?, ?, 'client', 'pending_invite')")->execute([$email, trim(($c['firstName']??'').' '.($c['lastName']??''))]); $logs[] = "[CRM] Imported $email"; $added++; } } } else { $logs[] = "[CRM] No contacts found."; } } else { $logs[] = "[CRM] Skipped (Missing Config)"; } if (!empty($secrets['GOOGLE_REFRESH_TOKEN'])) { $accessToken = getGoogleAccessToken($secrets); if ($accessToken) { $gUrl = "https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers,organizations,metadata&pageSize=100"; $ch = curl_init($gUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Accept: application/json"]); $gRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($gRes['connections'])) { foreach ($gRes['connections'] as $person) { $email = $person['emailAddresses'][0]['value'] ?? ''; if (!$email) continue; $name = $person['names'][0]['displayName'] ?? 'Google Client'; $googleId = $person['resourceName'] ?? ''; $stmt = $pdo->prepare("SELECT id, google_resource_name FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if(empty($existing['google_resource_name'])) { $pdo->prepare("UPDATE users SET google_resource_name = ? WHERE id = ?")->execute([$googleId, $existing['id']]); $logs[] = "[GOOGLE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, google_resource_name, role, status) VALUES (?, ?, ?, 'client', 'pending_invite')")->execute([$email, $name, $googleId]); $logs[] = "[GOOGLE] Imported $email"; $added++; } } } else { $logs[] = "[GOOGLE] No connections found."; } } else { $logs[] = "[GOOGLE] Auth failed."; } } else { $logs[] = "[GOOGLE] Skipped."; } sendJson('success', "Sync Cycle Complete", ['logs' => $logs]); }
function handleImportStripeClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $response = stripeRequest($secrets, 'GET', 'customers?limit=100'); $customers = $response['data'] ?? []; $logs = []; foreach ($customers as $c) { $email = $c['email']; if (!$email) continue; $stmt = $pdo->prepare("SELECT id, stripe_id FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if (empty($existing['stripe_id'])) { $pdo->prepare("UPDATE users SET stripe_id = ? WHERE email = ?")->execute([$c['id'], $email]); $logs[] = "[STRIPE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, stripe_id, role, status) VALUES (?, ?, ?, 'client', 'active')")->execute([$email, $c['name'], $c['id']]); $logs[] = "[STRIPE] Imported $email"; } } sendJson('success', "Stripe Sync Complete", ['logs' => $logs]); }
function handleGetSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings"); $settings = []; while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; } sendJson('success', 'Settings loaded', ['settings' => $settings]); }
function handleUpdateSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); foreach ($i['settings'] ?? [] as $key => $value) { $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)"); $stmt->execute([$key, $value]); } sendJson('success', 'Settings saved'); }
function handleGetMyProfile($pdo, $input) { $u = verifyAuth($input); $stmt = $pdo->prepare("SELECT full_name, business_name, email, phone, website, address, position FROM users WHERE id = ?"); $stmt->execute([$u['uid']]); sendJson('success', 'Profile Loaded', ['profile' => $stmt->fetch()]); }
// UPDATED: Broad matching for partners
function handleGetPartners($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureUserSchema($pdo);
    // Broad query to catch all variations
    $s = $pdo->query("SELECT id, full_name, email, phone, created_at FROM users WHERE role LIKE '%partner%' OR role = 'partner' ORDER BY full_name ASC");
    sendJson('success', 'Partners fetched', ['partners' => $s->fetchAll()]);
}
function handleAssignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); if (!$partnerId || !$clientId) sendJson('error', 'Invalid IDs'); try { $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]); } catch (Exception $e) { } $c = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $c->execute([$clientId]); $client = $c->fetch(); if ($client) { createNotification($pdo, $partnerId, "You have been assigned to client: " . ($client['full_name'] ?? 'New Client')); } sendJson('success', 'Partner assigned'); }
function handleUnassignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id = ? AND client_id = ?")->execute([$partnerId, $clientId]); sendJson('success', 'Partner unassigned'); }
?>

// [HELPER] Sync local user data to Stripe & CRM
function syncClientToExternal($pdo, $uid, $secrets) {
    try {
        $s = $pdo->prepare("SELECT * FROM users WHERE id=?"); 
        $s->execute([$uid]); 
        $u = $s->fetch();
        if (!$u) return;

        if(!empty($u['stripe_id']) && function_exists('stripeRequest')) {
            stripeRequest($secrets, 'POST', "customers/{$u['stripe_id']}", [
                'name' => $u['full_name'],
                'email' => $u['email'],
                'metadata' => ['business_name' => $u['business_name']]
            ]);
        }
        
        if(function_exists('pushToSwipeOne')) {
            pushToSwipeOne($secrets, 'contacts', [
                'email' => $u['email'],
                'firstName' => $u['full_name'],
                'properties' => ['business_name' => $u['business_name']]
            ]);
        }
        logSystemEvent($pdo, "Synced User #$uid to external systems.", 'info');
    } catch (Exception $e) {
        logSystemEvent($pdo, "Sync Failed for User #$uid: " . $e->getMessage(), 'error');
    }
}

// [RECOVERY] Get Raw User List (Admin Only)
function handleGetAllUsers($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    sendJson('success', 'Audit List Loaded', ['users' => $stmt->fetchAll()]);
}

// [RECOVERY] Force Fix Account with Logging
function handleFixUserAccount($pdo, $i, $s) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $uid = (int)$i['target_user_id'];
    $role = strtolower(trim($i['role'])); 
    $status = $i['status'];
    
    logSystemEvent($pdo, "Admin attempting to force update User #$uid to Role: $role, Status: $status", 'warning');

    try {
        $stmt = $pdo->prepare("UPDATE users SET role = ?, status = ? WHERE id = ?");
        $stmt->execute([$role, $status, $uid]);
        $count = $stmt->rowCount();
        
        if ($count > 0) {
            logSystemEvent($pdo, "Success: Updated User #$uid. Rows affected: $count", 'success');
            syncClientToExternal($pdo, $uid, $s);
            sendJson('success', 'Account Repaired');
        } else {
            logSystemEvent($pdo, "Failure: Update executed but no rows changed for User #$uid. ID may not exist.", 'error');
            sendJson('error', 'No user found with that ID.');
        }
    } catch (Exception $e) {
        logSystemEvent($pdo, "DB Crash during update: " . $e->getMessage(), 'error');
        sendJson('error', 'Database Error: ' . $e->getMessage());
    }
}

// [AI] Executive Assistant Handler
function handleAI($pdo, $i, $s) {
    $user = verifyAuth($i);
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'AI', ['text' => 'Config Error: API Key missing.']);

    $websiteContext = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Website data unavailable.";
    $dashboardContext = isset($i['data_context']) ? json_encode($i['data_context']) : "No active dashboard data.";

    $basePrompt = "You are the WandWeb Executive Assistant.
    CONTEXT: $dashboardContext
    KB: $websiteContext
    PROTOCOL:
    1. Tone: Professional, concise, corporate, and proactive.
    2. If user reports a problem, append [ACTION:OPEN_TICKET].";

    $systemPrompt = ($user['role'] === 'admin') ? $basePrompt . "\n USER IS EXECUTIVE." : $basePrompt . "\n USER IS CLIENT.";
    
    $d = callGeminiAI($pdo, $s, $systemPrompt, $i['prompt']);
    
    if (!empty($d['error'])) {
        $text = "AI Error: " . ($d['error']['message'] ?? 'Unknown API Error');
    } else {
        $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? 'System offline (No candidates returned).';
    }

    if (stripos($text, '[ACTION:OPEN_TICKET]') !== false) {
        $clean = trim(str_ireplace('[ACTION:OPEN_TICKET]', '', $text));
        sendJson('success', 'AI', ['text' => $clean, 'open_ticket' => true, 'insight' => $clean]);
    } else {
        sendJson('success', 'AI', ['text' => $text]);
    }
}

// [PARTNER] Dashboard Stats
function handleGetPartnerDashboard($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM partner_assignments WHERE partner_id = ?");
    $cStmt->execute([$u['uid']]);
    $clientCount = $cStmt->fetchColumn();
    
    $pStmt = $pdo->prepare("SELECT p.id, p.title, p.status, p.health_score, u.full_name as client_name FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) AND p.status != 'archived' ORDER BY p.updated_at DESC");
    $pStmt->execute([$u['uid']]);
    
    sendJson('success', 'Loaded', ['client_count' => $clientCount, 'projects' => $pStmt->fetchAll()]);
}

// [STANDARD] CRUD Operations
function handleGetClients($pdo, $i) {
    $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureUserSchema($pdo);
    $s = $pdo->query("SELECT id, full_name, email, business_name, phone, website, status, role, created_at FROM users WHERE role IN ('client', 'partner') ORDER BY created_at DESC");
    sendJson('success', 'Fetched', ['clients' => $s->fetchAll()]);
}

function handleGetClientDetails($pdo, $input, $secrets) {
    $user = verifyAuth($input); 
    $clientId = (int)$input['client_id'];
    
    // Auth Check: Admin or Assigned Partner
    if ($user['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $check = $pdo->prepare("SELECT id FROM partner_assignments WHERE partner_id = ? AND client_id = ?");
        $check->execute([$user['uid'], $clientId]);
        if (!$check->fetch()) sendJson('error', 'Access Denied');
    } elseif ($user['role'] !== 'admin') {
        sendJson('error', 'Unauthorized');
    }
    
    $stmt = $pdo->prepare("SELECT id, full_name, email, business_name, phone, status, role, stripe_id FROM users WHERE id = ?"); 
    $stmt->execute([$clientId]); 
    $client = $stmt->fetch();
    
    $projects = []; 
    $stmt = $pdo->prepare("SELECT id, title, status, health_score FROM projects WHERE user_id = ?"); 
    $stmt->execute([$clientId]); 
    $projects = $stmt->fetchAll();

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
    syncClientToExternal($pdo, (int)$input['client_id'], $secrets);
    sendJson('success', 'Updated');
}

function handleClientSelfUpdate($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    $uid = $user['uid'];
    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, address=?, website=?, position=? WHERE id=?")
        ->execute([strip_tags($input['full_name']), strip_tags($input['business_name']), strip_tags($input['phone']), strip_tags($input['address']), strip_tags($input['website']), strip_tags($input['position']), $uid]);
    syncClientToExternal($pdo, $uid, $secrets);
    sendJson('success', 'Profile Updated');
}

function handleUpdateUserRole($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $newRole = $i['role']; if (!in_array($newRole, ['client', 'partner', 'admin'])) sendJson('error', 'Invalid Role');
    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, (int)$i['client_id']]);
    sendJson('success', 'Role Updated');
}

function handleAssignClientToPartner($pdo, $i) {
    $user = verifyAuth($i); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    if ($i['action_type'] === 'remove') {
        $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id=? AND client_id=?")->execute([(int)$i['partner_id'], (int)$i['client_id']]);
        sendJson('success', 'Removed');
    } else {
        try { $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([(int)$i['partner_id'], (int)$i['client_id']]); sendJson('success', 'Assigned'); }
        catch (Exception $e) { sendJson('success', 'Already Assigned'); }
    }
}

// Misc Handlers
function handleSendOnboardingLink($pdo, $input) { verifyAuth($input); sendInvite($pdo, $input['email']); sendJson('success', 'Sent'); }
function handleSubmitOnboarding($pdo, $input, $secrets) { $token = $input['onboarding_token']; $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()"); $stmt->execute([$token]); $invite = $stmt->fetch(); if (!$invite) sendJson('error', 'Invalid Link'); $email = $invite['email']; $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?")->execute([trim(($input['first_name']??'').' '.($input['last_name']??'')), $input['business_name'], $input['phone'], $input['website'], $email]); $uid = $pdo->query("SELECT id FROM users WHERE email='$email'")->fetchColumn(); $pdo->prepare("INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')")->execute([$uid, "New Project: ".$input['business_name'], $input['scope']]); $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]); sendJson('success', 'Complete'); }
function handleGetAdminDashboard($pdo, $i, $s) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn(); $p = $pdo->query("SELECT SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status='onboarding' THEN 1 ELSE 0 END) as onboarding FROM projects")->fetch(); $b = stripeRequest($s, 'GET', 'balance'); $av = ($b['available'][0]['amount'] ?? 0) / 100; $pe = ($b['pending'][0]['amount'] ?? 0) / 100; $re = 0; if (isset($b['connect_reserved'][0])) $re += $b['connect_reserved'][0]['amount'] / 100; $sym = strtoupper($b['available'][0]['currency'] ?? 'USD') === 'AUD' ? 'A$' : '$'; sendJson('success', 'Loaded', ['stats' => ['total_clients' => $c, 'active_projects' => $p['active'] ?? 0, 'onboarding_projects' => $p['onboarding'] ?? 0, 'stripe_available' => $sym . number_format($av, 2), 'stripe_incoming' => $sym . number_format($pe, 2), 'stripe_reserved' => $sym . number_format($re, 2)]]); }
function handleImportCRMClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $logs = []; $added = 0; if (!empty($secrets['SWIPEONE_API_KEY'])) { $url = "https://api.swipeone.com/api/workspaces/" . $secrets['SWIPEONE_WORKSPACE_ID'] . "/contacts"; $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: " . $secrets['SWIPEONE_API_KEY'], "Content-Type: application/json"]); $crmRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($crmRes['data'])) { foreach ($crmRes['data'] as $c) { $email = $c['email'] ?? ''; if (!$email) continue; $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$email]); if (!$stmt->fetch()) { $pdo->prepare("INSERT INTO users (email, full_name, role, status) VALUES (?, ?, 'client', 'pending_invite')")->execute([$email, trim(($c['firstName']??'').' '.($c['lastName']??''))]); $logs[] = "[CRM] Imported $email"; $added++; } } } else { $logs[] = "[CRM] No contacts found."; } } else { $logs[] = "[CRM] Skipped (Missing Config)"; } if (!empty($secrets['GOOGLE_REFRESH_TOKEN'])) { $accessToken = getGoogleAccessToken($secrets); if ($accessToken) { $gUrl = "https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers,organizations,metadata&pageSize=100"; $ch = curl_init($gUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Accept: application/json"]); $gRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($gRes['connections'])) { foreach ($gRes['connections'] as $person) { $email = $person['emailAddresses'][0]['value'] ?? ''; if (!$email) continue; $name = $person['names'][0]['displayName'] ?? 'Google Client'; $googleId = $person['resourceName'] ?? ''; $stmt = $pdo->prepare("SELECT id, google_resource_name FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if(empty($existing['google_resource_name'])) { $pdo->prepare("UPDATE users SET google_resource_name = ? WHERE id = ?")->execute([$googleId, $existing['id']]); $logs[] = "[GOOGLE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, google_resource_name, role, status) VALUES (?, ?, ?, 'client', 'pending_invite')")->execute([$email, $name, $googleId]); $logs[] = "[GOOGLE] Imported $email"; $added++; } } } else { $logs[] = "[GOOGLE] No connections found."; } } else { $logs[] = "[GOOGLE] Auth failed."; } } else { $logs[] = "[GOOGLE] Skipped."; } sendJson('success', "Sync Cycle Complete", ['logs' => $logs]); }
function handleImportStripeClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $response = stripeRequest($secrets, 'GET', 'customers?limit=100'); $customers = $response['data'] ?? []; $logs = []; foreach ($customers as $c) { $email = $c['email']; if (!$email) continue; $stmt = $pdo->prepare("SELECT id, stripe_id FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if (empty($existing['stripe_id'])) { $pdo->prepare("UPDATE users SET stripe_id = ? WHERE email = ?")->execute([$c['id'], $email]); $logs[] = "[STRIPE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, stripe_id, role, status) VALUES (?, ?, ?, 'client', 'active')")->execute([$email, $c['name'], $c['id']]); $logs[] = "[STRIPE] Imported $email"; } } sendJson('success', "Stripe Sync Complete", ['logs' => $logs]); }
function handleGetSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings"); $settings = []; while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; } sendJson('success', 'Settings loaded', ['settings' => $settings]); }
function handleUpdateSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); foreach ($i['settings'] ?? [] as $key => $value) { $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)"); $stmt->execute([$key, $value]); } sendJson('success', 'Settings saved'); }
function handleGetMyProfile($pdo, $input) { $u = verifyAuth($input); $stmt = $pdo->prepare("SELECT full_name, business_name, email, phone, website, address, position FROM users WHERE id = ?"); $stmt->execute([$u['uid']]); sendJson('success', 'Profile Loaded', ['profile' => $stmt->fetch()]); }
function handleGetPartners($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $s = $pdo->query("SELECT id, full_name, email, phone, created_at FROM users WHERE TRIM(LOWER(role)) = 'partner' ORDER BY full_name ASC"); sendJson('success', 'Partners fetched', ['partners' => $s->fetchAll()]); }
function handleAssignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); if (!$partnerId || !$clientId) sendJson('error', 'Invalid IDs'); try { $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]); } catch (Exception $e) { } $c = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $c->execute([$clientId]); $client = $c->fetch(); if ($client) { createNotification($pdo, $partnerId, "You have been assigned to client: " . ($client['full_name'] ?? 'New Client')); } sendJson('success', 'Partner assigned'); }
function handleUnassignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id = ? AND client_id = ?")->execute([$partnerId, $clientId]); sendJson('success', 'Partner unassigned'); }
?>

    if(!empty($u['stripe_id'])) {
        stripeRequest($secrets, 'POST', "customers/{$u['stripe_id']}", [
            'name' => $u['full_name'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'metadata' => ['business_name' => $u['business_name']]
        ]);
    }
    pushToSwipeOne($secrets, 'contacts', [
        'email' => $u['email'],
        'firstName' => $u['full_name'],
        'phone' => $u['phone'],
        'properties' => ['business_name' => $u['business_name']]
    ]);
}

// [RECOVERY] Get Raw User List (Admin Only)
function handleGetAllUsers($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    // Select everything to find "lost" accounts
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    sendJson('success', 'Audit List Loaded', ['users' => $stmt->fetchAll()]);
}

// [RECOVERY] Force Fix Account
function handleFixUserAccount($pdo, $i, $s) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $uid = (int)$i['target_user_id'];
    $role = strtolower(trim($i['role'])); // Force lowercase
    $status = $i['status'];
    
    $pdo->prepare("UPDATE users SET role = ?, status = ? WHERE id = ?")->execute([$role, $status, $uid]);
    syncClientToExternal($pdo, $uid, $s);
    sendJson('success', 'Account Repaired');
}

// Client Self-Service Update
function handleClientSelfUpdate($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    $uid = $user['uid'];
    $full_name = strip_tags($input['full_name']);
    $business = strip_tags($input['business_name']);
    $phone = strip_tags($input['phone']);
    $address = strip_tags($input['address']);
    $website = strip_tags($input['website']);
    $position = strip_tags($input['position']);

    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, address=?, website=?, position=? WHERE id=?")
        ->execute([$full_name, $business, $phone, $address, $website, $position, $uid]);

    syncClientToExternal($pdo, $uid, $secrets);
    sendJson('success', 'Profile Updated & Synced');
}

function handleGetClients($pdo, $i) {
    $u = verifyAuth($i); 
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    ensureUserSchema($pdo);
    // Standard view filters for 'client' or 'partner'
    $s = $pdo->query("SELECT id, full_name, email, business_name, phone, website, status, role, created_at FROM users WHERE role IN ('client', 'partner') ORDER BY created_at DESC");
    sendJson('success', 'Fetched', ['clients' => $s->fetchAll()]);
}

function handleGetClientDetails($pdo, $input, $secrets) {
    $user = verifyAuth($input); 
    // Allow Partner access if assigned
    $clientId = (int)$input['client_id'];
    
    if ($user['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $check = $pdo->prepare("SELECT id FROM partner_assignments WHERE partner_id = ? AND client_id = ?");
        $check->execute([$user['uid'], $clientId]);
        if (!$check->fetch()) sendJson('error', 'Access Denied');
    } elseif ($user['role'] !== 'admin') {
        sendJson('error', 'Unauthorized');
    }
    
    $stmt = $pdo->prepare("SELECT id, full_name, email, business_name, phone, status, role, stripe_id FROM users WHERE id = ?"); 
    $stmt->execute([$clientId]); 
    $client = $stmt->fetch();
    
    $projects = []; 
    $stmt = $pdo->prepare("SELECT id, title, status, health_score FROM projects WHERE user_id = ?"); 
    $stmt->execute([$clientId]); 
    $projects = $stmt->fetchAll();

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
    $newRole = $i['role']; 
    if (!in_array($newRole, ['client', 'partner', 'admin'])) sendJson('error', 'Invalid Role');
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
        try {
            $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]);
            sendJson('success', 'Client Assigned');
        } catch (Exception $e) {
            sendJson('success', 'Already Assigned');
        }
    }
}

// Partner Dashboard specific stats
function handleGetPartnerDashboard($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    ensurePartnerSchema($pdo);
    
    // Get assigned clients count
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM partner_assignments WHERE partner_id = ?");
    $cStmt->execute([$u['uid']]);
    $clientCount = $cStmt->fetchColumn();
    
    // Get active projects for assigned clients
    $pStmt = $pdo->prepare("SELECT p.id, p.title, p.status, p.health_score, u.full_name as client_name FROM projects p JOIN users u ON p.user_id = u.id WHERE p.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) AND p.status != 'archived' ORDER BY p.updated_at DESC");
    $pStmt->execute([$u['uid']]);
    $projects = $pStmt->fetchAll();
    
    sendJson('success', 'Loaded', ['client_count' => $clientCount, 'projects' => $projects]);
}

// Keeping original handlers...
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
function handleGetAdminDashboard($pdo, $i, $s) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn(); $p = $pdo->query("SELECT SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status='onboarding' THEN 1 ELSE 0 END) as onboarding FROM projects")->fetch(); $b = stripeRequest($s, 'GET', 'balance'); $av = ($b['available'][0]['amount'] ?? 0) / 100; $pe = ($b['pending'][0]['amount'] ?? 0) / 100; $re = 0; if (isset($b['connect_reserved'][0])) $re += $b['connect_reserved'][0]['amount'] / 100; $sym = strtoupper($b['available'][0]['currency'] ?? 'USD') === 'AUD' ? 'A$' : '$'; sendJson('success', 'Loaded', ['stats' => ['total_clients' => $c, 'active_projects' => $p['active'] ?? 0, 'onboarding_projects' => $p['onboarding'] ?? 0, 'stripe_available' => $sym . number_format($av, 2), 'stripe_incoming' => $sym . number_format($pe, 2), 'stripe_reserved' => $sym . number_format($re, 2)]]); }
function handleImportCRMClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $logs = []; $added = 0; if (!empty($secrets['SWIPEONE_API_KEY'])) { $url = "https://api.swipeone.com/api/workspaces/" . $secrets['SWIPEONE_WORKSPACE_ID'] . "/contacts"; $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-api-key: " . $secrets['SWIPEONE_API_KEY'], "Content-Type: application/json"]); $crmRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($crmRes['data'])) { foreach ($crmRes['data'] as $c) { $email = $c['email'] ?? ''; if (!$email) continue; $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$email]); if (!$stmt->fetch()) { $pdo->prepare("INSERT INTO users (email, full_name, role, status) VALUES (?, ?, 'client', 'pending_invite')")->execute([$email, trim(($c['firstName']??'').' '.($c['lastName']??''))]); $logs[] = "[CRM] Imported $email"; $added++; } } } else { $logs[] = "[CRM] No contacts found."; } } else { $logs[] = "[CRM] Skipped (Missing Config)"; } if (!empty($secrets['GOOGLE_REFRESH_TOKEN'])) { $accessToken = getGoogleAccessToken($secrets); if ($accessToken) { $gUrl = "https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers,organizations,metadata&pageSize=100"; $ch = curl_init($gUrl); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Accept: application/json"]); $gRes = json_decode(curl_exec($ch), true); curl_close($ch); if (!empty($gRes['connections'])) { foreach ($gRes['connections'] as $person) { $email = $person['emailAddresses'][0]['value'] ?? ''; if (!$email) continue; $name = $person['names'][0]['displayName'] ?? 'Google Client'; $googleId = $person['resourceName'] ?? ''; $stmt = $pdo->prepare("SELECT id, google_resource_name FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if(empty($existing['google_resource_name'])) { $pdo->prepare("UPDATE users SET google_resource_name = ? WHERE id = ?")->execute([$googleId, $existing['id']]); $logs[] = "[GOOGLE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, google_resource_name, role, status) VALUES (?, ?, ?, 'client', 'pending_invite')")->execute([$email, $name, $googleId]); $logs[] = "[GOOGLE] Imported $email"; $added++; } } } else { $logs[] = "[GOOGLE] No connections found."; } } else { $logs[] = "[GOOGLE] Auth failed."; } } else { $logs[] = "[GOOGLE] Skipped."; } sendJson('success', "Sync Cycle Complete", ['logs' => $logs]); }
function handleImportStripeClients($pdo, $input, $secrets) { set_time_limit(300); $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureUserSchema($pdo); $response = stripeRequest($secrets, 'GET', 'customers?limit=100'); $customers = $response['data'] ?? []; $logs = []; foreach ($customers as $c) { $email = $c['email']; if (!$email) continue; $stmt = $pdo->prepare("SELECT id, stripe_id FROM users WHERE email = ?"); $stmt->execute([$email]); $existing = $stmt->fetch(); if ($existing) { if (empty($existing['stripe_id'])) { $pdo->prepare("UPDATE users SET stripe_id = ? WHERE email = ?")->execute([$c['id'], $email]); $logs[] = "[STRIPE] Linked ID for $email"; } } else { $pdo->prepare("INSERT INTO users (email, full_name, stripe_id, role, status) VALUES (?, ?, ?, 'client', 'active')")->execute([$email, $c['name'], $c['id']]); $logs[] = "[STRIPE] Imported $email"; } } sendJson('success', "Stripe Sync Complete", ['logs' => $logs]); }
function handleGetSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings"); $settings = []; while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; } sendJson('success', 'Settings loaded', ['settings' => $settings]); }
function handleUpdateSettings($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensureSettingsSchema($pdo); foreach ($i['settings'] ?? [] as $key => $value) { $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)"); $stmt->execute([$key, $value]); } sendJson('success', 'Settings saved'); }
function handleGetMyProfile($pdo, $input) { $u = verifyAuth($input); $stmt = $pdo->prepare("SELECT full_name, business_name, email, phone, website, address, position FROM users WHERE id = ?"); $stmt->execute([$u['uid']]); sendJson('success', 'Profile Loaded', ['profile' => $stmt->fetch()]); }
function handleGetPartners($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureUserSchema($pdo);
    // Trim and lower check to be safe
    $s = $pdo->query("SELECT id, full_name, email, phone, created_at FROM users WHERE TRIM(LOWER(role)) = 'partner' ORDER BY full_name ASC");
    sendJson('success', 'Partners fetched', ['partners' => $s->fetchAll()]);
}
function handleAssignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); if (!$partnerId || !$clientId) sendJson('error', 'Invalid IDs'); try { $pdo->prepare("INSERT INTO partner_assignments (partner_id, client_id) VALUES (?, ?)")->execute([$partnerId, $clientId]); } catch (Exception $e) { } $c = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $c->execute([$clientId]); $client = $c->fetch(); if ($client) { createNotification($pdo, $partnerId, "You have been assigned to client: " . ($client['full_name'] ?? 'New Client')); } sendJson('success', 'Partner assigned'); }
function handleUnassignPartner($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); ensurePartnerSchema($pdo); $partnerId = (int)($i['partner_id'] ?? 0); $clientId = (int)($i['client_id'] ?? 0); $pdo->prepare("DELETE FROM partner_assignments WHERE partner_id = ? AND client_id = ?")->execute([$partnerId, $clientId]); sendJson('success', 'Partner unassigned'); }
?>
