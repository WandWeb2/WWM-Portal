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
    // 1. Validation
    if (empty($secrets['GEMINI_API_KEY'])) {
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")
            ->execute([$ticketId, "[System] Error: AI API Key is missing. Please contact Admin."]);
        return;
    }

    // 2. Context Building
    try {
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        $mStmt = $pdo->prepare("SELECT sender_id, message FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $mStmt->execute([$ticketId]);
        $history = $mStmt->fetchAll();

        $transcript = "";
        foreach ($history as $h) {
            $role = ($h['sender_id'] == 0) ? "AI" : "CLIENT";
            $transcript .= "$role: " . strip_tags($h['message']) . "\n";
        }

        $kb = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Service: Web Design.";
        $status = $ticket['status'];

        $systemPrompt = "CONTEXT: $kb\nCURRENT PHASE: $status\n\nINSTRUCTIONS:\n1. You are Second Mate AI (if Open) or First Mate AI (if Escalating).\n2. Be helpful and brief.\n3. If you cannot solve it, output: [TRIGGER_HANDOFF] or [TRIGGER_ADMIN].\n4. DO NOT start response with your name.";

        // 3. API Call with Timeout
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $secrets['GEMINI_API_KEY'];
        $payload = json_encode(["contents" => [["parts" => [["text" => $systemPrompt . "\n\nTRANSCRIPT:\n" . $transcript . "\n\nRESPONSE:"]]]]]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 15 Second Timeout
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception("Connection Error: " . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($response, true);
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $reply = trim(str_replace(['[Second Mate]', '[First Mate]'], '', $reply));

        // 4. Fail-Safe Check
        if (empty($reply)) {
            // Check if API returned a specific error
            $apiError = $data['error']['message'] ?? 'Unknown API Error';
            throw new Exception("AI returned empty response. Reason: " . $apiError);
        }

        // 5. Logic Processing
        if (strpos($reply, '[TRIGGER_HANDOFF]') !== false) {
            $clean = str_replace('[TRIGGER_HANDOFF]', '', $reply);
            $pdo->prepare("UPDATE tickets SET status = 'escalating' WHERE id = ?")->execute([$ticketId]);
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, "[Second Mate] " . $clean]);
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, "[First Mate] I have been summoned. I am reviewing the logs now. Client, please provide any extra details."]);
        } 
        elseif (strpos($reply, '[TRIGGER_ADMIN]') !== false) {
            $clean = str_replace('[TRIGGER_ADMIN]', '', $reply);
            $pdo->prepare("UPDATE tickets SET status = 'escalated' WHERE id = ?")->execute([$ticketId]);
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $clean]);
            if (function_exists('notifyAllAdmins')) notifyAllAdmins($pdo, "Ticket #$ticketId Escalated to Admin.");
        } 
        else {
            // Standard Reply
            $prefix = ($status === 'escalating') ? "[First Mate] " : "[Second Mate] ";
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $prefix . $reply]);
        }

    } catch (Exception $e) {
        // 6. Ultimate Fallback (This ensures chat is NEVER empty)
        $errorMsg = "[System] Second Mate is temporarily offline (" . $e->getMessage() . "). A human agent has been notified.";
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")->execute([$ticketId, $errorMsg]);
        
        // Force escalate so human sees it
        $pdo->prepare("UPDATE tickets SET status = 'escalated' WHERE id = ?")->execute([$ticketId]);
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
    
    // System bootstrap message from Second Mate AI
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)");
    $secondMateMsg = "[Second Mate] Ahoy! I am Second Mate AI. I've received your request and am analyzing the ship's logs. I will attempt to resolve this immediately. If I cannot, I will signal the First Mate.";
    $stmt->execute([$ticketId, $secondMateMsg]);
    
    // If created by Client, trigger AI and notify partner
    if ($u['role'] === 'client') { 
        triggerSupportAI($pdo, $s, $ticketId);
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client {$u['name']} created Ticket #$ticketId");
    } elseif ($ticketOwnerId !== $u['uid']) {
        // Created by Admin/Partner for Client -> Notify Client
        createNotification($pdo, $ticketOwnerId, "New Support Ticket #$ticketId opened for you by {$u['name']}", 'ticket', $ticketId);
    }

    sendJson('success', 'Ticket Created'); 
}

function handleReplyTicket($pdo, $i, $s) { 
    $u = verifyAuth($i); 
    $tid = (int)$i['ticket_id']; 
    $isInternal = ($u['role'] === 'admin' && !empty($i['is_internal'])) ? 1 : 0; 
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_internal) VALUES (?, ?, ?, ?)"); 
    $stmt->execute([$tid, $u['uid'], strip_tags($i['message']), $isInternal]); 
    $newStatus = ($u['role'] === 'admin') ? 'waiting_client' : 'open'; 
    if ($isInternal) $newStatus = 'open'; 
    $pdo->prepare("UPDATE tickets SET updated_at = NOW(), status = ? WHERE id = ?")->execute([$newStatus, $tid]); 
    
    // Trigger AI and notify partner if client replied
    if ($u['role'] === 'client') {
        triggerSupportAI($pdo, $s, $tid);
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client replied to #$tid");
    }
    
    // If Admin/Partner replied, notify the Client
    if ($u['role'] === 'admin' || $u['role'] === 'partner') {
        $stmt = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $stmt->execute([$tid]);
        $ticket = $stmt->fetch();
        if ($ticket) {
            createNotification($pdo, $ticket['user_id'], "New reply on Ticket #$tid", 'ticket', $tid);
        }
    }
    
    sendJson('success', 'Reply Sent'); 
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

function handleSuggestSolution($i, $s) {
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'Suggestion', ['text' => null]);
    $context = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "";
    $query = $i['subject'];
    $prompt = "Context: $context. User Subject: '$query'. Answer in 1 sentence + link or 'NO_MATCH'.";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $s['GEMINI_API_KEY'];
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["contents" => [["parts" => [["text" => $prompt]]]]])); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
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
