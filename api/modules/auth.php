<?php
// /api/modules/auth.php
// Version: 36.0 - Enhanced with Better Error Handling & Forgot Password

function handleLogin($pdo, $i, $s) {
    try {
        $q = $pdo->prepare("SELECT id, password_hash, role, full_name, email FROM users WHERE email = ? LIMIT 1");
        $q->execute([$i['email']]);
        $u = $q->fetch();
        
        if (!$u) {
            sendJson('error', 'Incorrect password');
            return;
        }
        
        if (!password_verify($i['password'], $u['password_hash'])) {
            sendJson('error', 'Incorrect password');
            return;
        }
        
        if ($u['role'] !== 'admin') {
            pushToSwipeOne($s, 'events', [
                'contact' => ['email' => $u['email']],
                'type' => 'portal_login',
                'properties' => ['role' => $u['role']]
            ]);
        }
        
        $t = base64_encode(json_encode(['uid' => $u['id'], 'role' => $u['role'], 'time' => time()])) . "." . hash_hmac('sha256', $u['id'], $s['JWT_SECRET']);
        sendJson('success', 'Login', ['token' => $t, 'user' => ['id' => $u['id'], 'name' => $u['full_name'], 'role' => $u['role']]]);
    } catch (Exception $e) {
        sendJson('error', 'Server error during login');
    }
}

function handleRegister($pdo, $input, $secrets) {
    ensureUserSchema($pdo);
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    $name = strip_tags($input['name']);
    $business = strip_tags($input['business_name']);
    $password = $input['password'];
    if (!$email || strlen($password) < 6) sendJson('error', 'Invalid email or password (min 6 chars).');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) sendJson('error', 'Account already exists. Please log in.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, business_name, role, status, created_at) VALUES (?, ?, ?, ?, 'client', 'active', NOW())");
    if ($stmt->execute([$email, $hash, $name, $business])) {
        $uid = $pdo->lastInsertId();
        $tokenPayload = base64_encode(json_encode(['uid' => $uid, 'role' => 'client', 'time' => time()]));
        $signature = hash_hmac('sha256', $uid, $secrets['JWT_SECRET']);
        $token = "$tokenPayload.$signature";
        pushToSwipeOne($secrets, 'contacts', ['email' => $email, 'firstName' => $name, 'properties' => ['business_name' => $business]]);
        sendJson('success', 'Account Created', ['token' => $token, 'user' => ['id' => $uid, 'name' => $name, 'role' => 'client']]);
    } else {
        sendJson('error', 'Registration failed.');
    }
}

function handleSetPassword($pdo,$i){
    $q=$pdo->prepare("SELECT email FROM password_resets WHERE token=? AND expires_at>NOW()");
    $q->execute([$i['invite_token']]);
    if(!$r=$q->fetch())sendJson('error','Invalid or expired token');
    $pdo->prepare("UPDATE users SET password_hash=?,status='active' WHERE email=?")->execute([password_hash($i['password'],PASSWORD_DEFAULT),$r['email']]);
    $pdo->prepare("DELETE FROM password_resets WHERE token=?")->execute([$i['invite_token']]);
    sendJson('success','Password Set');
}

function handleGetNotifications($pdo,$i){
    $u=verifyAuth($i);
    // Ensure table exists (SQLite compatible)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    // Silent Schema Upgrade for Deep Linking
    try { $pdo->exec("ALTER TABLE notifications ADD COLUMN target_type TEXT DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE notifications ADD COLUMN target_id INTEGER DEFAULT 0"); } catch(Exception $e){}
    
    $s=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $s->execute([$u['uid']]);
    sendJson('success','Fetched',['notifications'=>$s->fetchAll()]);
}

function handleMarkRead($pdo,$i){
    $u=verifyAuth($i);
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$i['id'],$u['uid']]);
    sendJson('success','Read');
}

function handleMarkAllRead($pdo,$i){
    $u=verifyAuth($i);
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$u['uid']]);
    sendJson('success','All notifications marked as read');
}

function handleRequestPasswordReset($pdo, $input, $secrets = []) {
    try {
        $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
        
        if (!$email) {
            sendJson('error', 'Invalid email address');
            return;
        }
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Don't reveal if email exists or not (security best practice)
            sendJson('success', 'If an account exists with that email, a reset link has been sent.');
            return;
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        
        // Ensure password_resets table exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(191),
                token VARCHAR(255) UNIQUE,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {
            // Table likely exists
        }
        
        // Delete old reset requests for this email
        $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
        
        // Insert new reset request
        $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $token, $expires]);
        
        // Send reset email
        $resetLink = "https://wandweb.co/portal/?action=reset_password&token=" . $token;
        $subject = "WandWeb Portal - Reset Your Password";
        $message = "Click the link below to reset your password:\n\n$resetLink\n\nThis link expires in 1 hour.";
        $headers = "From: noreply@wandweb.co\r\nContent-Type: text/plain; charset=UTF-8";
        
        @mail($email, $subject, $message, $headers);
        
        sendJson('success', 'If an account exists with that email, a reset link has been sent.');
    } catch (Exception $e) {
        logSystemEvent($pdo, "Password reset error: " . $e->getMessage(), 'error');
        sendJson('error', 'Server error processing request');
    }
}
?>