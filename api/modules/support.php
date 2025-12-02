<?php
/* =============================================================================
   WandWeb API Module: Support & Ticketing
   File: /api/modules/support.php
   ============================================================================= */

function ensureSupportSchema($pdo) {
    // 1. Create Tables (Complete Schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        project_id INT DEFAULT NULL,
        subject VARCHAR(255),
        status VARCHAR(50) DEFAULT 'open',
        priority VARCHAR(20) DEFAULT 'normal',
        is_billable TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
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

// ... (handleGetTickets, handleGetTicketThread, handleCreateTicket, handleReplyTicket, handleUpdateTicketStatus remain the same) ...
function handleGetTickets($pdo, $i) { $u = verifyAuth($i); ensureSupportSchema($pdo); if ($u['role'] === 'admin') { $sql = "SELECT t.*, u.full_name as client_name, p.title as project_title, (SELECT message FROM ticket_messages WHERE ticket_id = t.id ORDER BY id DESC LIMIT 1) as last_message FROM tickets t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN projects p ON t.project_id = p.id ORDER BY field(t.status, 'open', 'waiting_client', 'closed'), t.created_at DESC"; $stmt = $pdo->prepare($sql); $stmt->execute(); } else { $sql = "SELECT t.*, p.title as project_title, (SELECT message FROM ticket_messages WHERE ticket_id = t.id ORDER BY id DESC LIMIT 1) as last_message FROM tickets t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ? ORDER BY t.created_at DESC"; $stmt = $pdo->prepare($sql); $stmt->execute([$u['uid']]); } sendJson('success', 'Tickets Loaded', ['tickets' => $stmt->fetchAll()]); }
function handleGetTicketThread($pdo, $i) { $u = verifyAuth($i); $tid = (int)$i['ticket_id']; if ($u['role'] !== 'admin') { $check = $pdo->prepare("SELECT id FROM tickets WHERE id=? AND user_id=?"); $check->execute([$tid, $u['uid']]); if (!$check->fetch()) sendJson('error', 'Access Denied'); } $sql = "SELECT tm.*, u.full_name, u.role FROM ticket_messages tm LEFT JOIN users u ON tm.sender_id = u.id WHERE tm.ticket_id = ? ORDER BY tm.created_at ASC"; $stmt = $pdo->prepare($sql); $stmt->execute([$tid]); $msgs = $stmt->fetchAll(); if ($u['role'] !== 'admin') { $msgs = array_filter($msgs, function($m) { return $m['is_internal'] == 0; }); } sendJson('success', 'Thread Loaded', ['messages' => array_values($msgs)]); }
function handleCreateTicket($pdo, $i, $s) { $u = verifyAuth($i); ensureSupportSchema($pdo); $stmt = $pdo->prepare("INSERT INTO tickets (user_id, project_id, subject, priority, status) VALUES (?, ?, ?, ?, 'open')"); $pid = !empty($i['project_id']) ? (int)$i['project_id'] : NULL; $stmt->execute([$u['uid'], $pid, strip_tags($i['subject']), $i['priority']]); $ticketId = $pdo->lastInsertId(); $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)"); $stmt->execute([$ticketId, $u['uid'], strip_tags($i['message'])]); if ($u['role'] === 'client') { $ack = "Message received. We have logged ticket #$ticketId and notified the team. A support officer will review it shortly."; $stmt->execute([$ticketId, 0, $ack]); } sendJson('success', 'Ticket Created'); }
function handleReplyTicket($pdo, $i) { $u = verifyAuth($i); $tid = (int)$i['ticket_id']; $isInternal = ($u['role'] === 'admin' && !empty($i['is_internal'])) ? 1 : 0; $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_internal) VALUES (?, ?, ?, ?)"); $stmt->execute([$tid, $u['uid'], strip_tags($i['message']), $isInternal]); $newStatus = ($u['role'] === 'admin') ? 'waiting_client' : 'open'; if ($isInternal) $newStatus = 'open'; $pdo->prepare("UPDATE tickets SET updated_at = NOW(), status = ? WHERE id = ?")->execute([$newStatus, $tid]); sendJson('success', 'Reply Sent'); }
function handleUpdateTicketStatus($pdo, $i) { $u = verifyAuth($i); if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized'); $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$i['status'], (int)$i['ticket_id']]); sendJson('success', 'Status Updated'); }

// === INTELLIGENT SUPPORT AGENT (UPDATED PERSONA) ===
function handleAI($i, $s) {
    // 1. Safety Checks
    $user = verifyAuth($i);
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'AI', ['text' => 'Config Error: API Key missing.']);

    // 2. Prepare Context
    $model = "gemini-2.0-flash";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $s['GEMINI_API_KEY'];
    
    $websiteContext = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "Website data unavailable.";
    $dashboardData = $i['data_context'] ?? [];
    $dashboardContext = !empty($dashboardData) ? json_encode($dashboardData) : "No active dashboard data.";

    // 3. Build System Prompt
    $basePrompt = "You are the WandWeb AI (First Mate).
    
    CONTEXT (User Data): $dashboardContext
    KNOWLEDGE BASE (Website): $websiteContext
    
    PROTOCOL:
    1.  **Persona:** You are a Merchant Maritime Officer on a modern commercial vessel.
    2.  **Tone:** Professional, efficient, competent, and slightly casual. 
        -   Avoid 'Sir' or 'Ma'am'. Use 'Captain' (if Admin) or address the user directly/neutrally.
        -   Avoid pirate slang (No 'Ahoy', 'Matey', 'Shiver me timbers').
        -   Use terms like 'Status Report', 'Log', 'On the horizon', 'All systems nominal'.
    3.  **Ticket Trigger:** If the user reports a bug or asks for human help, summarize the issue and append: [ACTION:OPEN_TICKET].
    4.  **Formatting:** -   Keep responses visually clean. Use bullet points for lists.
        -   Format links strictly as: [Link Title](URL). Do not put URLs in brackets like [https://...].";

    if ($user['role'] === 'admin') {
        $systemPrompt = $basePrompt . "\n USER IS ADMIN (Captain). You have full access.";
    } else {
        $systemPrompt = $basePrompt . "\n USER IS CLIENT. Be helpful and service-oriented.";
    }

    $userMessage = $i['prompt'];
    $fullPrompt = $systemPrompt . "\n\nUSER: " . $userMessage;

    // 4. Execute Request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["contents" => [["parts" => [["text" => $fullPrompt]]]]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) { 
        $err = curl_error($ch); 
        curl_close($ch); 
        sendJson('success', 'AI', ['text' => "Connection Error: $err"]); 
    }
    curl_close($ch);
    
    $d = json_decode($response, true);
    
    // 5. Handle Google Errors
    if (isset($d['error'])) {
        $msg = $d['error']['message'] ?? 'Unknown Google API Error';
        sendJson('success', 'AI', ['text' => "System Error: $msg"]);
    }

    $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? 'System offline (No response).';
    
    sendJson('success', 'AI', ['text' => $text]);
}

// === SMART SUGGESTION ENGINE ===
function handleSuggestSolution($i, $s) {
    if (empty($s['GEMINI_API_KEY'])) sendJson('success', 'Suggestion', ['text' => null]);
    
    $context = function_exists('fetchWandWebContext') ? fetchWandWebContext() : "";
    $query = $i['subject'];
    
    $prompt = "Context: $context. User Subject: '$query'. 
    Does the documentation answer this? If YES, summarize in 1 sentence + link. If NO, return 'NO_MATCH'.";
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $s['GEMINI_API_KEY'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["contents" => [["parts" => [["text" => $prompt]]]]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? 'NO_MATCH';
    
    if (stripos($text, 'NO_MATCH') !== false) {
        sendJson('success', 'Suggestion', ['match' => false]);
    } else {
        sendJson('success', 'Suggestion', ['match' => true, 'text' => $text]);
    }
}
?>