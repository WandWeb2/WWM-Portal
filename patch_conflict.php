<?php
$content = file_get_contents('api/modules/clients.php');

$search = <<<EOD
<<<<<<< HEAD
function handleSubmitOnboarding(\$pdo, \$input, \$secrets) { \$token = \$input['onboarding_token']; \$stmt = \$pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()"); \$stmt->execute([\$token]); \$invite = \$stmt->fetch(); if (!\$invite) sendJson('error', 'Invalid Link'); \$email = \$invite['email']; if (!filter_var(\$email, FILTER_VALIDATE_EMAIL)) sendJson('error', 'Invalid Email'); \$pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?")->execute([trim((\$input['first_name']??'').' '.(\$input['last_name']??'')), \$input['business_name'], \$input['phone'], \$input['website'], \$email]); \$uidStmt = \$pdo->prepare("SELECT id FROM users WHERE email=?"); \$uidStmt->execute([\$email]); \$uid = \$uidStmt->fetchColumn(); \$pdo->prepare("INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')")->execute([\$uid, "New Project: ".\$input['business_name'], \$input['scope']]); \$pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([\$token]); sendJson('success', 'Complete'); }
=======
function handleSubmitOnboarding(\$pdo, \$input, \$secrets) { \$token = \$input['onboarding_token']; \$stmt = \$pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()"); \$stmt->execute([\$token]); \$invite = \$stmt->fetch(); if (!\$invite) sendJson('error', 'Invalid Link'); \$email = \$invite['email']; \$pdo->prepare("UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?")->execute([trim((\$input['first_name']??'').' '.(\$input['last_name']??'')), \$input['business_name'], \$input['phone'], \$input['website'], \$email]); \$stmtEmail = \$pdo->prepare("SELECT id FROM users WHERE email=?"); \$stmtEmail->execute([\$email]); \$uid = \$stmtEmail->fetchColumn(); \$pdo->prepare("INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')")->execute([\$uid, "New Project: ".\$input['business_name'], \$input['scope']]); \$pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([\$token]); sendJson('success', 'Complete'); }
>>>>>>> 72fa424 (Fix SQL injection vulnerability in handleSubmitOnboarding)
EOD;

$replace = "function handleSubmitOnboarding(\$pdo, \$input, \$secrets) { \$token = \$input['onboarding_token']; \$stmt = \$pdo->prepare(\"SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()\"); \$stmt->execute([\$token]); \$invite = \$stmt->fetch(); if (!\$invite) sendJson('error', 'Invalid Link'); \$email = \$invite['email']; if (!filter_var(\$email, FILTER_VALIDATE_EMAIL)) sendJson('error', 'Invalid Email'); \$pdo->prepare(\"UPDATE users SET full_name=?, business_name=?, phone=?, website=?, status='active' WHERE email=?\")->execute([trim((\$input['first_name']??'').' '.(\$input['last_name']??'')), \$input['business_name'], \$input['phone'], \$input['website'], \$email]); \$uidStmt = \$pdo->prepare(\"SELECT id FROM users WHERE email=?\"); \$uidStmt->execute([\$email]); \$uid = \$uidStmt->fetchColumn(); \$pdo->prepare(\"INSERT INTO projects (user_id, title, description, status) VALUES (?, ?, ?, 'onboarding')\")->execute([\$uid, \"New Project: \".\$input['business_name'], \$input['scope']]); \$pdo->prepare(\"DELETE FROM password_resets WHERE token = ?\")->execute([\$token]); sendJson('success', 'Complete'); }";

if (strpos($content, $search) !== false) {
    $newContent = str_replace($search, $replace, $content);
    file_put_contents('api/modules/clients.php', $newContent);
    echo "Successfully replaced the conflicted code.\n";
} else {
    echo "Could not find the target code in api/modules/clients.php\n";
}
