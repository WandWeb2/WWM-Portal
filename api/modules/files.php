<?php
// /api/modules/files.php
// =============================================================================
// Wandering Webmaster Custom Component
// Agency: Wandering Webmaster (wandweb.co)
// Client: Portal Architecture
// Version: 31.0
// =============================================================================
// --- VERSION HISTORY ---
// 31.0 - Initial Drive Integration, Folder Logic, and Secure Proxy

// --- GOOGLE DRIVE HELPERS ---

function driveRequest($accessToken, $method, $endpoint, $body = null, $contentType = 'application/json') {
    $ch = curl_init("https://www.googleapis.com/drive/v3/" . ltrim($endpoint, '/'));
    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: $contentType"
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POST, true);
    if ($method === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $code, 'data' => json_decode($res, true)];
}

function findOrCreateFolder($token, $name, $parentId = 'root') {
    // 1. Search
    $query = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace("'", "\'", $name) . "' and '$parentId' in parents and trashed=false";
    $search = driveRequest($token, 'GET', "files?q=" . urlencode($query));
    
    if (!empty($search['data']['files'])) {
        return $search['data']['files'][0]['id'];
    }
    
    // 2. Create if missing
    $body = json_encode([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId]
    ]);
    $create = driveRequest($token, 'POST', 'files', $body);
    return $create['data']['id'] ?? null;
}

function uploadToDrive($token, $fileData, $filename, $mimeType, $parentId) {
    // Multipart upload for metadata + content
    $metadata = json_encode(['name' => $filename, 'parents' => [$parentId]]);
    $boundary = '-------' . md5(time());
    
    $body = "--$boundary\r\n" .
            "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
            "$metadata\r\n" .
            "--$boundary\r\n" .
            "Content-Type: $mimeType\r\n\r\n" .
            $fileData . "\r\n" .
            "--$boundary--";

    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: multipart/related; boundary=$boundary",
        "Content-Length: " . strlen($body)
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    return $res['id'] ?? null;
}

// --- HANDLERS ---

function handleGetFiles($pdo, $i) {
    $u = verifyAuth($i);
    // Reuse existing schema from projects.php logic
    if ($u['role'] === 'admin') {
        $s = $pdo->query("SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id ORDER BY f.created_at DESC");
    } elseif ($u['role'] === 'partner') {
        // Ensure partner schema exists if not already
        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_assignments (partner_id INT, client_id INT)"); 
        $sql = "SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id WHERE f.client_id = ? OR f.client_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) ORDER BY f.created_at DESC";
        $s = $pdo->prepare($sql);
        $s->execute([$u['uid'], $u['uid']]);
    } else {
        $s = $pdo->prepare("SELECT * FROM shared_files WHERE client_id = ? ORDER BY created_at DESC");
        $s->execute([$u['uid']]);
    }
    sendJson('success', 'Files', ['files' => $s->fetchAll()]);
}

function handleUploadFile($pdo, $i, $secrets) {
    // Note: Accepts both 'file' (upload) and 'external_url' (link)
    $u = verifyAuth($i);
    $pid = (int)($i['project_id'] ?? 0);
    
    // 1. Determine Target Client
    $clientId = $u['uid']; // Default to self
    $projTitle = "";
    
    if ($pid > 0) {
        // If attached to a project, get that project's owner
        $p = $pdo->prepare("SELECT user_id, title FROM projects WHERE id = ?");
        $p->execute([$pid]);
        $proj = $p->fetch();
        if ($proj) {
            $clientId = $proj['user_id'];
            $projTitle = $proj['title'];
        }
    } else if ($u['role'] === 'admin' && !empty($i['client_id'])) {
        // Admin uploading directly to a client's file tab
        $clientId = (int)$i['client_id'];
    }

    // 2. Fetch Client Name for Folder Structure
    $cStmt = $pdo->prepare("SELECT full_name, business_name FROM users WHERE id = ?");
    $cStmt->execute([$clientId]);
    $client = $cStmt->fetch();
    $folderName = $client['business_name'] ? $client['business_name'] : ($client['full_name'] ?? "Client_$clientId");
    // Sanitize folder name
    $folderName = preg_replace('/[^A-Za-z0-9 _-]/', '', $folderName);

    // 3. Process Upload (Drive or Link)
    $driveId = null;
    $filename = "";
    $mime = "";
    $size = 0;
    $isLink = false;

    if (!empty($_FILES['file']['name'])) {
        // GOOGLE DRIVE UPLOAD FLOW
        if (empty($secrets['GOOGLE_REFRESH_TOKEN'])) sendJson('error', 'Google Drive not configured');
        
        $token = getGoogleAccessToken($secrets);
        if (!$token) sendJson('error', 'Failed to authenticate with Drive');

        // A. Ensure Folder Structure
        $rootId = findOrCreateFolder($token, 'WandWeb Clients'); // Agency Root
        $clientFolderId = findOrCreateFolder($token, $folderName, $rootId); // Client Folder
        $sharedId = findOrCreateFolder($token, 'Shared Files', $clientFolderId); // Target Folder

        // B. Upload
        $fileContent = file_get_contents($_FILES['file']['tmp_name']);
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        $filename = $_FILES['file']['name'];
        $size = $_FILES['file']['size'];

        $driveId = uploadToDrive($token, $fileContent, $filename, $mime, $sharedId);
        if (!$driveId) sendJson('error', 'Drive Upload Failed');
        
    } else {
        // LINK FLOW
        $isLink = true;
        $driveId = strip_tags($i['external_url']); // Stores URL
        $filename = strip_tags($i['filename']);
        $mime = 'link';
    }

    // 4. Record in DB
    // Use 'drive:' prefix to identify Drive files vs legacy local files
    $storedUrl = $isLink ? $driveId : "drive:$driveId";
    
    $stmt = $pdo->prepare("INSERT INTO shared_files (client_id, uploader_id, filename, external_url, file_type, filesize, project_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$clientId, $u['uid'], $filename, $storedUrl, $mime, $size, $pid]);
    
    // 5. Notify/Log
    if ($pid > 0) {
        $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
            ->execute([$pid, $u['uid'], "📎 New File: $filename"]);
    }
    
    sendJson('success', 'File Saved');
}

function handleDownloadFile($pdo, $i, $secrets) {
    // SECURE PROXY: Streams file from Drive to Browser without exposing Drive URL
    // No auth verification needed here? WRONG. Verify auth.
    // Since this is a direct GET request (often), we might need token in URL or session. 
    // For this architecture, we assume the token is passed in GET or POST.
    
    $u = verifyAuth($i); 
    $fileId = (int)$i['file_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    
    if (!$file) die("File not found");
    
    // Security Check: Does user own this file or is admin/partner?
    $hasAccess = ($u['role'] === 'admin') || 
                 ($u['uid'] == $file['client_id']) || 
                 ($u['role'] === 'partner'); // Simplified partner check
                 
    if (!$hasAccess) die("Access Denied");
    
    $ref = $file['external_url'];
    
    // If it's a simple link, redirect
    if (strpos($ref, 'drive:') === false) {
        header("Location: $ref");
        exit;
    }
    
    // It's a Drive File
    $googleId = str_replace('drive:', '', $ref);
    $token = getGoogleAccessToken($secrets);
    
    // Stream Headers
    header("Content-Type: " . $file['file_type']);
    header("Content-Disposition: attachment; filename=\"" . $file['filename'] . "\"");
    
    // Stream Content from Google
    $url = "https://www.googleapis.com/drive/v3/files/$googleId?alt=media";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Direct stream to output
    curl_exec($ch);
    curl_close($ch);
    exit;
}

function handleDeleteFile($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $fileId = (int)$i['file_id'];
    $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    
    if (!$file) sendJson('error', 'Not found');
    
    // 1. Delete from Drive if applicable
    if (strpos($file['external_url'], 'drive:') === 0) {
        $googleId = str_replace('drive:', '', $file['external_url']);
        if (!empty($secrets['GOOGLE_REFRESH_TOKEN'])) {
            $token = getGoogleAccessToken($secrets);
            driveRequest($token, 'DELETE', "files/$googleId");
        }
    }
    
    // 2. Delete from DB
    $pdo->prepare("DELETE FROM shared_files WHERE id = ?")->execute([$fileId]);
    
    sendJson('success', 'Deleted');
}
?>
