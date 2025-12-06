<?php
// =============================================================================
// Wandering Webmaster System Module
// Version: 1.0
// =============================================================================

function ensureSystemSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $idType = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_updates (
        id $idType,
        version VARCHAR(50),
        description TEXT,
        commit_date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Auto-Populate History if empty (The "Automatic" Backfill)
    $check = $pdo->query("SELECT COUNT(*) FROM portal_updates")->fetchColumn();
    if ($check == 0) {
        $stmt = $pdo->prepare("INSERT INTO portal_updates (version, description, commit_date) VALUES (?, ?, ?)");
        $history = [
            ['v35.0', 'Implemented Dynamic AI Model Discovery (Auto-Switching)', date('Y-m-d H:i:s')],
            ['v34.2', 'Fixed AI 404 Error: Switched to gemini-pro', date('Y-m-d H:i:s', strtotime('-10 minutes'))],
            ['v34.1', 'Added Verbose AI Error Debugging', date('Y-m-d H:i:s', strtotime('-20 minutes'))],
            ['v34.0', 'System Stability Checkpoint', date('Y-m-d H:i:s', strtotime('-30 minutes'))]
        ];
        foreach ($history as $h) $stmt->execute($h);
    }
}

function handleGetUpdates($pdo, $input) {
    // Accessible by ALL logged-in users
    verifyAuth($input); 
    ensureSystemSchema($pdo);
    
    $stmt = $pdo->query("SELECT * FROM portal_updates ORDER BY commit_date DESC");
    sendJson('success', 'Updates Loaded', ['updates' => $stmt->fetchAll()]);
}
?>
