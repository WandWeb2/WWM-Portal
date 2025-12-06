<?php
// /api/modules/support.php
// Version: 29.0 - Partner Access Added

function ensureSupportSchema($pdo) {
    // 1. Create Tables (Complete Schema) - SQLite/MySQL compatible
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $autoIncrement = ($driver === 'sqlite') ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';
    $onUpdate = ($driver === 'sqlite') ? '' : 'ON UPDATE CURRENT_TIMESTAMP';
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY $autoIncrement,
        user_id INT,
        project_id INT DEFAULT NULL,
        subject VARCHAR(255),
        status VARCHAR(50) DEFAULT 'open',
        priority VARCHAR(20) DEFAULT 'normal',
        is_billable TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP $onUpdate
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
        id INTEGER PRIMARY KEY $autoIncrement,
        ticket_id INT,
        sender_id INT DEFAULT 0,
        message TEXT,
        is_internal TINYINT DEFAULT 0,
        attachment_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 2. SELF-REPAIR (Fixes missing columns on existing tables)
    try { $pdo->exec("ALTER TABLE tickets ADD COLUMN project_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE tickets ADD COLUMN is_billable TINYINT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN sender_id INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN is_internal TINYINT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
}

function triggerSupportAI($pdo, $secrets, $ticketId) {
    if (empty($secrets['GEMINI_API_KEY'])) return;

    // 1. Loop Guard
    $lastMsg = $pdo->prepare("SELECT sender_id FROM ticket_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 1");
    $lastMsg->execute([$ticketId]);
    $last = $lastMsg->fetch();
    if ($last && $last['sender_id'] == 0) return;

    // 2. Context & Client Dossier
    $tStmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $tStmt->execute([$ticketId]);
    $ticket = $tStmt->fetch();

    // --- AI SILENCE PROTOCOL ---
    // If ticket is already escalated, AI must NOT reply. Humans are in control.
    if ($ticket['status'] === 'escalated') return;
    
    // Fetch Client Profile
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$ticket['user_id']]);
    $client = $uStmt->fetch();

    // Fetch Billing Context
    $billingContext = "No billing data linked.";
    if (!empty($client['stripe_id']) && function_exists('stripeRequest')) {
        try {
            $invRes = stripeRequest($secrets, 'GET', "invoices?customer={$client['stripe_id']}&limit=1");
            if (!empty($invRes['data'][0])) {
                $inv = $invRes['data'][0];
                $amt = number_format($inv['total'] / 100, 2);
                $billingContext = "Last Invoice: #{$inv['number']} (\${$amt} - {$inv['status']})";
            }
        } catch (Exception $e) { $billingContext = "Billing unavailable."; }
    }

    $mStmt = $pdo->prepare("SELECT sender_id, message FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $mStmt->execute([$ticketId]);
    $history = $mStmt->fetchAll();

    $transcript = "";
    foreach ($history as $h) {
        $role = ($h['sender_id'] == 0) ? "AI" : "CLIENT";
        $transcript .= "$role: " . strip_tags($h['message']) . "\n";
    }

    $kb = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Service: Web Design.";

    // Define Service Context (Hardcoded for reliability until dynamic sync is built)
    $serviceList = "Monthly Plans (Care Plans), Managed Hosting, SEO & Local Listings, Social Media Management, Website Maintenance";

    // 3. THE "AGENCY ACCOUNT MANAGER" PROMPT
    $systemPrompt = "
    CONTEXT: $kb
    OFFERED SERVICES: $serviceList
    
    CLIENT DOSSIER:
    - Name: {$client['full_name']}
    - Business: {$client['business_name']}
    - Email: {$client['email']}
    - Billing: $billingContext
    
    YOUR ROLE:
    You are the Senior Account Manager for Wandering Webmaster. 
    
    CRITICAL BEHAVIOR RULES:
    1. NO DIY TUTORIALS: If a user asks \"How do I add a page?\" or \"How do I fix this error?\", DO NOT teach them. They pay us to do it.
    2. SELL THE SOLUTION: Offer to perform the task for them.
       - Bad: \"Go to Pages > Add New.\"
       - Good: \"I can certainly handle that update for you. I've created a work order for the design team to add that page. Shall we proceed?\"
    3. SERVICE AWARENESS: If they mention a need (e.g., \"slow site\"), map it to our services (e.g., \"SEO/Maintenance Package\").
    4. TONE: Professional, Concierge, Proactive. Use the client's name.

    OUTPUT FORMATS:

    SCENARIO A: Simple Answer/Chat (Second Mate)
    Output string: \"[Second Mate] Your answer here...\"

    SCENARIO B: Work Order Needed / Technical Task (First Mate)
    Output a RAW JSON ARRAY of exactly 3 strings (Removed redundant messages):
    [
      \"[Second Mate] I'll get our technical team on this right away. Summoning the First Mate.\",
      \"[First Mate] Hello {$client['full_name']}. I have received your request. I have opened a formal work order and alerted the humans. Is there anything specific you need added?\",
      \"[System] Ticket escalated to Human Support. AI standing down.\"
    ]
    ";

    try {
        $response = callGeminiAI($pdo, $secrets, $systemPrompt, "TRANSCRIPT:\n" . $transcript);
        
        $rawText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $cleanText = trim(str_replace(['```json', '```'], '', $rawText));

        $messagesToAdd = [];
        $newStatus = $ticket['status'];

        // Robust JSON Parsing
        preg_match('/\[.*\]/s', $cleanText, $matches);
        if (!empty($matches[0])) {
            $script = json_decode($matches[0], true);
            if (is_array($script)) {
                $messagesToAdd = $script;
                $newStatus = 'escalated';
                
                // Notify Humans
                $fullResponse = implode(" ", $script);
                if (stripos($fullResponse, 'humans') !== false) {
                    if (function_exists('notifyAllAdmins')) notifyAllAdmins($pdo, "First Mate Requesting Action on Ticket #$ticketId");
                }
            }
        } 
        
        if (empty($messagesToAdd) && trim($cleanText) !== '') $messagesToAdd = [$cleanText];

        // Insert Messages with thinking delays between AI messages
        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)");
        $isFirstMessage = true;
        foreach ($messagesToAdd as $msg) {
            if (trim($msg)) {
                // Add thinking delay between consecutive AI messages (but not before first AI message)
                if (!$isFirstMessage) {
                    usleep(1500000); // 1.5 second thinking pause between AI messages
                }
                $stmt->execute([$ticketId, $msg]);
                $isFirstMessage = false;
                usleep(250000); // 0.25s delay for DB spacing
            }
        }

        if ($newStatus !== $ticket['status']) {
            $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$newStatus, $ticketId]);
        }

    } catch (Exception $e) {
        error_log("AI Support Error: " . $e->getMessage());
    }
}

function handleGetTickets($pdo, $i) { 
    $u = verifyAuth($i); ensureSupportSchema($pdo);

    // Ordering: 1) status priority (open, waiting_client, closed),
    //           2) ticket urgency (urgent, high, normal, low),
    //           3) oldest first by created_at
    $orderClause = "ORDER BY CASE t.status WHEN 'open' THEN 1 WHEN 'waiting_client' THEN 2 WHEN 'closed' THEN 3 ELSE 4 END ASC, CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC, t.created_at ASC";

    $baseSelect = "SELECT t.*, u.full_name as client_name, p.title as project_title, (SELECT message FROM ticket_messages WHERE ticket_id = t.id ORDER BY id DESC LIMIT 1) as last_message FROM tickets t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN projects p ON t.project_id = p.id";

    if ($u['role'] === 'admin') { 
        $sql = "$baseSelect $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    } elseif ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $sql = "$baseSelect WHERE t.user_id = ? OR t.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid'], $u['uid']]);
    } else {
        $sql = "$baseSelect WHERE t.user_id = ? $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid']]);
    }

    sendJson('success', 'Tickets Loaded', ['tickets' => $stmt->fetchAll()]); 
}

function handleGetTicketThread($pdo, $i) { 
    $u = verifyAuth($i); 
    $tid = (int)$i['ticket_id']; 
    
    if ($u['role'] !== 'admin') { 
        // Partner check or Owner check
        if ($u['role'] === 'partner') {
            ensurePartnerSchema($pdo);
            $check = $pdo->prepare("SELECT id FROM tickets WHERE id=? AND (user_id=? OR user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id=?))");
            $check->execute([$tid, $u['uid'], $u['uid']]);
        } else {
            $check = $pdo->prepare("SELECT id FROM tickets WHERE id=? AND user_id=?");
            $check->execute([$tid, $u['uid']]);
        }
        if (!$check->fetch()) sendJson('error', 'Access Denied'); 
    } 
    
    $sql = "SELECT tm.*, u.full_name, u.role FROM ticket_messages tm LEFT JOIN users u ON tm.sender_id = u.id WHERE tm.ticket_id = ? ORDER BY tm.created_at ASC"; 
    $stmt = $pdo->prepare($sql); 
    $stmt->execute([$tid]); 
    $msgs = $stmt->fetchAll(); 
    if ($u['role'] !== 'admin') { 
        $msgs = array_filter($msgs, function($m) { return $m['is_internal'] == 0; }); 
    } 
    // Tag personas via prefixes for frontend styling
    foreach ($msgs as &$m) {
        if (isset($m['sender_id']) && $m['sender_id'] == 0) {
            $text = $m['message'] ?? '';
            if (stripos($text, '[Second Mate]') === 0 || stripos($text, 'Ahoy!') === 0) $m['persona'] = 'second';
            elseif (stripos($text, '[First Mate]') === 0 || stripos($text, 'First Mate') === 0) $m['persona'] = 'first';
            else $m['persona'] = 'system';
        }
    }
    sendJson('success', 'Thread Loaded', ['messages' => array_values($msgs)]); 
}

function handleCreateTicket($pdo, $i, $s) { 
    $u = verifyAuth($i); ensureSupportSchema($pdo); 
    
    // Determine the actual owner of the ticket
    $ticketOwnerId = $u['uid'];
    if (($u['role'] === 'admin' || $u['role'] === 'partner') && !empty($i['target_client_id'])) {
        $ticketOwnerId = (int)$i['target_client_id'];
    }

    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, project_id, subject, priority, status) VALUES (?, ?, ?, ?, 'open')"); 
    $pid = !empty($i['project_id']) ? (int)$i['project_id'] : NULL; 
    $stmt->execute([$ticketOwnerId, $pid, strip_tags($i['subject']), $i['priority']]); 
    $ticketId = $pdo->lastInsertId(); 
    
    // The sender is still the actual logged-in user (Admin/Partner), so the message history is accurate
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)"); 
    $stmt->execute([$ticketId, $u['uid'], strip_tags($i['message'])]); 
    
    // If created by Client, trigger AI and notify partner
    if ($u['role'] === 'client') { 
        triggerSupportAI($pdo, $s, $ticketId);
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client {$u['name']} created Ticket #$ticketId");
    } elseif ($ticketOwnerId !== $u['uid']) {
        // Created by Admin/Partner for Client -> Notify Client
        createNotification($pdo, $ticketOwnerId, "New Support Ticket #$ticketId opened for you by {$u['name']}", 'ticket', $ticketId);
    }

    sendJson('success', 'Ticket Created', ['ticket_id' => $ticketId]); 
}

function handleReplyTicket($pdo, $i, $s) { 
    $u = verifyAuth($i); 
    $tid = (int)$i['ticket_id']; 

    // 1. Fetch Current Status to prevent "Zombie AI"
    $statusCheck = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
    $statusCheck->execute([$tid]);
    $currentStatus = $statusCheck->fetchColumn();

    if ($currentStatus === 'closed') {
        sendJson('error', 'This ticket is closed. Replies are disabled.');
        return;
    }

    $isInternal = ($u['role'] === 'admin' && !empty($i['is_internal'])) ? 1 : 0; 
    
    // 2. Insert Message
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_internal) VALUES (?, ?, ?, ?)"); 
    $stmt->execute([$tid, $u['uid'], strip_tags($i['message']), $isInternal]); 
    
    // 3. Determine New Status (The Fix)
    if ($u['role'] === 'admin') {
        $newStatus = 'waiting_client';
    } else {
        // If client replies to an ESCALATED ticket, keep it escalated (don't wake the AI).
        // Otherwise, set to open to trigger AI triage.
        $newStatus = ($currentStatus === 'escalated') ? 'escalated' : 'open';
    }
    
    if ($isInternal) $newStatus = $currentStatus; // Internal notes never change status
    
    $pdo->prepare("UPDATE tickets SET updated_at = NOW(), status = ? WHERE id = ?")->execute([$newStatus, $tid]); 
    
    // 4. Trigger AI / Notifications
    // ONLY trigger AI if the ticket is NOT escalated
    if ($u['role'] === 'client' && $newStatus !== 'escalated') {
        triggerSupportAI($pdo, $s, $tid);
    }
    
    // Always notify partner/admin on client reply
    if ($u['role'] === 'client') {
        notifyPartnerIfAssigned($pdo, $u['uid'], "{$u['name']} replied to Ticket #$tid");
    }
    
    if ($u['role'] === 'admin' || $u['role'] === 'partner') {
        $stmt = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $stmt->execute([$tid]);
        $ticket = $stmt->fetch();
        if ($ticket) {
            createNotification($pdo, $ticket['user_id'], "{$u['name']} replied to Ticket #$tid", 'ticket', $tid);
        }
    }
    
    // 5. Fetch Final Status (In case AI auto-closed or escalated it immediately)
    $finalStatusStmt = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
    $finalStatusStmt->execute([$tid]);
    $finalStatus = $finalStatusStmt->fetchColumn();
    
    sendJson('success', 'Reply Sent', ['new_status' => $finalStatus]); 
}

function handleUpdateTicketStatus($pdo, $i) {
    $u = verifyAuth($i);
    $ticketId = (int)$i['ticket_id'];
    $newStatus = $i['status'];
    
    // Allow admins to change any status
    if ($u['role'] === 'admin') {
        if ($newStatus === 'closed') {
            $closer = "An Administrator";
            $msg = "[System] Ticket closed by $closer. Replies are now disabled.";
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $msg]);
        }
        $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$newStatus, $ticketId]);
        sendJson('success', 'Status Updated');
    }
    
    // Allow clients to close their own tickets
    if ($u['role'] === 'client' && $newStatus === 'closed') {
        $stmt = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        
        if ($ticket && $ticket['user_id'] == $u['uid']) {
            $closer = "The Client";
            $msg = "[System] Ticket closed by $closer. Replies are now disabled.";
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $msg]);
            $pdo->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?")->execute([$ticketId]);
            sendJson('success', 'Ticket Closed');
        }
    }
    
    sendJson('error', 'Unauthorized');
}

function handleSuggestSolution($pdo, $i, $s) {
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'Suggestion', ['text' => null]);
    $context = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "";
    $query = $i['subject'];
    $prompt = "Context: $context. User Subject: '$query'. Answer in 1 sentence + link or 'NO_MATCH'.";
    $res = callGeminiAI($pdo, $s, $prompt);
    $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? 'NO_MATCH';
    sendJson('success', 'Suggestion', ['match' => stripos($text, 'NO_MATCH') === false, 'text' => $text]);
}

// Create ticket from AI insight
function handleCreateTicketFromInsight($pdo, $i) {
    $u = verifyAuth($i);
    ensureSupportSchema($pdo);
    
    $insight = strip_tags($i['insight']);
    $subject = "Dashboard Discussion: " . substr($insight, 0, 30) . "...";
    
    // 1. Create Ticket
    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, priority, status) VALUES (?, ?, 'normal', 'ai_triage')");
    $stmt->execute([$u['uid'], $subject]);
    $tid = $pdo->lastInsertId();
    
    // 2. Add AI's Insight as the first message (System)
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)");
    $msg1 = "AI INSIGHT: " . $insight;
    $stmt->execute([$tid, $msg1]);
    $msg1Id = $pdo->lastInsertId();
    
    // 3. Add a welcome prompt
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)");
    $msg2 = "I've started this support thread based on the AI's dashboard summary. Please let us know how we can assist you further or if you would like to escalate this issue.";
    $stmt->execute([$tid, $msg2]);
    $msg2Id = $pdo->lastInsertId();
    
    // 4. Build initial messages array for typing simulation
    $initialMessages = [
        [
            'id' => $msg1Id,
            'sender_id' => 0,
            'message' => $msg1,
            'is_internal' => false,
            'full_name' => 'System',
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => $msg2Id,
            'sender_id' => 0,
            'message' => $msg2,
            'is_internal' => false,
            'full_name' => 'System',
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];

    sendJson('success', 'Thread Started', ['ticket_id' => $tid, 'initial_messages' => $initialMessages]);
}


function handleEscalateTicket($pdo, $input) {
    // Allow system override for internal AI calls
    if (($input['role'] ?? '') !== 'system') {
        $u = verifyAuth($input);
    }

    $ticketId = (int)$input['ticket_id'];
    $pdo->prepare("UPDATE tickets SET status='escalated' WHERE id=?")->execute([$ticketId]);

    $msg = "[First Mate] First Mate AI here. Second Mate has flagged this. I've summoned the Captain (Admin). Stand by.";
    $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $msg]);

    createNotification($pdo, 'admin', "Ticket #$ticketId Escalated to First Mate.");
    sendJson('success','Escalated');
}

?>
