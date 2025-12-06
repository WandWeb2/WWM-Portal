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
    try { $pdo->exec("ALTER TABLE tickets ADD COLUMN sentiment_score INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE tickets ADD COLUMN snooze_until DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN sender_id INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN is_internal TINYINT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE ticket_messages ADD COLUMN file_id INT DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_ticket ON ticket_messages(ticket_id)"); } catch (Exception $e) {}
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

        // 3. THE "INTAKE & DISPATCH" PROMPT
        $systemPrompt = "
        CONTEXT: $kb
    
        CLIENT DOSSIER:
        - Name: {$client['full_name']}
        - Business: {$client['business_name']}
        - Email: {$client['email']}
        - Billing: $billingContext
    
        PERSONAS:
        1. [Second Mate] (Tier 1): Friendly, helpful. Answers generic questions.
        2. [First Mate] (Tier 2): Authoritative, capable.
    
        CRITICAL RULES:
        - You CANNOT see images, watch videos, or listen to audio files yet.
        - If the user says 'See attached' or sends a message that implies visual/audio context you miss:
            Output [ESCALATE_MEDIA] immediately. Do not try to guess.
        - If the client requests work (e.g., 'Update my logo'):
            Output [ESCALATE_WORK] to trigger a work order.

        OUTPUT FORMATS:

        SCENARIO A: Simple Reply
        Output string: \"[Second Mate] Your answer here...\"

        SCENARIO B: Work Request (First Mate)
        Output a RAW JSON ARRAY:
        [
            \"[Second Mate] I'll get the technical team on this.\",
            \"[First Mate] Work order created. Humans notified.\",
            \"[System] Escalated to Human Support.\"
        ]

        SCENARIO C: Unreadable Media / Confusion
        Output string: \"[ESCALATE_MEDIA]\"
        ";

    try {
        $response = callGeminiAI($pdo, $secrets, $systemPrompt, "TRANSCRIPT:\n" . $transcript);
        
        $rawText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $cleanText = trim(str_replace(['```json', '```'], '', $rawText));

        // Media/Confusion Safety Net
        if (stripos($cleanText, '[ESCALATE_MEDIA]') !== false) {
            $pdo->prepare("UPDATE tickets SET status = 'escalated' WHERE id = ?")->execute([$ticketId]);
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, 0, ?)")
                ->execute([$ticketId, "[System] The AI could not process the media/context of this message and has passed it to a human agent."]);
            if (function_exists('notifyAllAdmins')) notifyAllAdmins($pdo, "Ticket #$ticketId Escalated: Unreadable Media/Context");
            return;
        }

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
    autoCloseStaleTickets($pdo);

    // Ordering: 1) status priority (open, waiting_client, closed),
    //           2) ticket urgency (urgent, high, normal, low),
    //           3) oldest first by created_at
    $orderClause = "ORDER BY CASE t.status WHEN 'open' THEN 1 WHEN 'waiting_client' THEN 2 WHEN 'closed' THEN 3 ELSE 4 END ASC, CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC, t.created_at ASC";

    $baseSelect = "SELECT t.*, u.full_name as client_name, p.title as project_title, (SELECT message FROM ticket_messages WHERE ticket_id = t.id ORDER BY id DESC LIMIT 1) as last_message FROM tickets t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN projects p ON t.project_id = p.id";

    if ($u['role'] === 'admin') { 
        $sql = "$baseSelect WHERE (t.snooze_until IS NULL OR t.snooze_until <= NOW()) $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    } elseif ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $sql = "$baseSelect WHERE (t.user_id = ? OR t.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?)) AND (t.snooze_until IS NULL OR t.snooze_until <= NOW()) $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid'], $u['uid']]);
    } else {
        $sql = "$baseSelect WHERE t.user_id = ? AND (t.snooze_until IS NULL OR t.snooze_until <= NOW()) $orderClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid']]);
    }

    sendJson('success', 'Tickets Loaded', ['tickets' => $stmt->fetchAll()]); 
}

function handleReplyTicket($pdo, $i, $s) { 
    $u = verifyAuth($i); 
    $tid = (int)$i['ticket_id']; 

    $rawMsg = $i['message'] ?? '';
    $cleanMsg = redactSensitiveData(strip_tags($rawMsg)); // #23 Sanitization + Redaction
    if (empty(trim($cleanMsg))) sendJson('error', 'Message cannot be empty');

    $statusCheck = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
    $statusCheck->execute([$tid]);
    $currentStatus = $statusCheck->fetchColumn();

    if ($currentStatus === 'closed') { sendJson('error', 'Ticket is closed.'); return; }

    $isInternal = ($u['role'] === 'admin' && !empty($i['is_internal'])) ? 1 : 0; 
    $fileId = !empty($i['file_id']) ? (int)$i['file_id'] : 0; 

    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_internal, file_id) VALUES (?, ?, ?, ?, ?)"); 
    $stmt->execute([$tid, $u['uid'], $cleanMsg, $isInternal, $fileId]); 

    $newStatus = ($u['role'] === 'admin') ? 'waiting_client' : 'open'; 
    if ($isInternal || $currentStatus === 'escalated') $newStatus = $currentStatus;

    $pdo->prepare("UPDATE tickets SET updated_at = NOW(), status = ? WHERE id = ?")->execute([$newStatus, $tid]); 

    if ($u['role'] === 'client') {
        $score = analyzeSentiment($cleanMsg);
        $pdo->prepare("UPDATE tickets SET sentiment_score = LEAST(100, sentiment_score + ?) WHERE id = ?")->execute([$score, $tid]);
        if ($newStatus !== 'escalated') triggerSupportAI($pdo, $s, $tid);
        notifyPartnerIfAssigned($pdo, $u['uid'], "{$u['name']} replied to Ticket #$tid");
    }

    sendJson('success', 'Reply Sent', ['new_status' => $newStatus]); 
}

function handleCreateTicket($pdo, $i, $s) {
    $u = verifyAuth($i);
    ensureSupportSchema($pdo);
    checkTicketRateLimit($pdo, $u['uid']);

    // Determine the actual owner of the ticket
    $ticketOwnerId = $u['uid'];
    if (($u['role'] === 'admin' || $u['role'] === 'partner') && !empty($i['target_client_id'])) {
        $ticketOwnerId = (int)$i['target_client_id'];
    }

    $priority = !empty($i['priority']) ? $i['priority'] : 'normal';
    $pid = !empty($i['project_id']) ? (int)$i['project_id'] : null;

    $subject = redactSensitiveData(strip_tags($i['subject']));
    $message = redactSensitiveData(strip_tags($i['message']));

    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, project_id, subject, priority, status) VALUES (?, ?, ?, ?, 'open')");
    $stmt->execute([$ticketOwnerId, $pid, $subject, $priority]);
    $ticketId = $pdo->lastInsertId();

    // The sender is still the actual logged-in user (Admin/Partner), so the message history is accurate
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$ticketId, $u['uid'], $message]);

    // If created by Client, trigger AI and notify partner
    if ($u['role'] === 'client') {
        $score = analyzeSentiment($subject . ' ' . $message);
        $pdo->prepare("UPDATE tickets SET sentiment_score = ? WHERE id = ?")->execute([$score, $ticketId]);
        triggerSupportAI($pdo, $s, $ticketId);
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client {$u['name']} created Ticket #$ticketId");
    } elseif ($ticketOwnerId !== $u['uid']) {
        // Created by Admin/Partner for Client -> Notify Client
        createNotification($pdo, $ticketOwnerId, "New Support Ticket #$ticketId opened for you by {$u['name']}", 'ticket', $ticketId);
    }

    sendJson('success', 'Ticket Created', ['ticket_id' => $ticketId]);
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

function handleSaveQuickReply($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'support_canned_responses'");
    $stmt->execute();
    $current = json_decode($stmt->fetchColumn() ?: '[]', true);
    $current[] = ['title' => strip_tags($i['title']), 'text' => strip_tags($i['text'])];
    $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('support_canned_responses', ?)")->execute([json_encode($current)]);
    sendJson('success', 'Saved');
}

function analyzeSentiment($text) {
    $triggers = ['urgent'=>20,'emergency'=>30,'broken'=>20,'down'=>30,'error'=>10,'fail'=>10,'refund'=>50,'cancel'=>50,'frustrated'=>40,'asap'=>10];
    $score = 0; $lower = strtolower($text);
    foreach ($triggers as $word => $points) { if (strpos($lower, $word) !== false) $score += $points; }
    return min(100, $score);
}

function checkTicketRateLimit($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT created_at FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $last = $stmt->fetchColumn();
    if ($last && (time() - strtotime($last) < 60)) sendJson('error', 'Please wait 60 seconds.');
}

function redactSensitiveData($text) {
    $text = preg_replace('/\b(?:\d[ -]*?){13,16}\b/', '[REDACTED_CARD]', $text);
    return preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[REDACTED_SSN]', $text);
}

function autoCloseStaleTickets($pdo) {
    $pdo->prepare("UPDATE tickets SET status = 'closed' WHERE status = 'waiting_client' AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->execute();
}

function handleSnoozeTicket($pdo, $i) {
    $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $date = date('Y-m-d H:i:s', strtotime("+".(int)$i['hours']." hours"));
    $pdo->prepare("UPDATE tickets SET snooze_until = ? WHERE id = ?")->execute([$date, (int)$i['ticket_id']]);
    sendJson('success', "Snoozed until $date");
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
