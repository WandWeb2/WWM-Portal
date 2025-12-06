<?php
// =============================================================================
// Wandering Webmaster Custom Component
// Agency: Wandering Webmaster (wandweb.co)
// Client: Portal Architecture
// Version: 31.2 - Fixed Upload Return Data
// =============================================================================

function driveRequest($token, $method, $endpoint, $body = null, $contentType = 'application/json') {
    $ch = curl_init("https://www.googleapis.com/drive/v3/" . ltrim($endpoint, '/'));
    $headers = ["Authorization: Bearer $token", "Content-Type: $contentType"];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POST, true);
    if ($method === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

function findOrCreateFolder($token, $name, $parentId = 'root') {
    $safeName = str_replace("'", "\'", $name);
    $q = "mimeType='application/vnd.google-apps.folder' and name='$safeName' and '$parentId' in parents and trashed=false";
    $search = driveRequest($token, 'GET', "files?q=" . urlencode($q));
    if (!empty($search['files'])) return $search['files'][0]['id'];
    
    $create = driveRequest($token, 'POST', 'files', json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]]));
    return $create['id'] ?? null;
}

function handleGetFiles($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] === 'admin') {
        $sql = "SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id ORDER BY f.created_at DESC";
        $stmt = $pdo->query($sql);
    } elseif ($u['role'] === 'partner') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_assignments (partner_id INT, client_id INT)");
        $sql = "SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id WHERE f.client_id = ? OR f.client_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) ORDER BY f.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid'], $u['uid']]);
    } else {
        $sql = "SELECT * FROM shared_files WHERE client_id = ? ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$u['uid']]);
    }
    sendJson('success', 'Files Loaded', ['files' => $stmt->fetchAll()]);
}

function handleUploadFile($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    $pid = (int)($i['project_id'] ?? 0);
    
    $clientId = $u['uid'];
    if ($pid > 0) {
        $p = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
        $p->execute([$pid]);
        $proj = $p->fetch();
        if ($proj) $clientId = $proj['user_id'];
    } elseif ($u['role'] === 'admin' && !empty($i['client_id'])) {
        $clientId = (int)$i['client_id'];
    }

    $token = getGoogleAccessToken($secrets);
    $useLocal = empty($token);
    $uploadDir = __DIR__ . '/../../data/uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

    $cStmt = $pdo->prepare("SELECT full_name, business_name FROM users WHERE id = ?");
    $cStmt->execute([$clientId]);
    $client = $cStmt->fetch();
    
    $folderName = preg_replace('/[^A-Za-z0-9 _-]/', '', $client['business_name'] ?: ($client['full_name'] ?: "Client_$clientId"));
    $rootId = $useLocal ? null : findOrCreateFolder($token, 'WandWeb Clients');
    $clientIdFolder = $useLocal ? null : findOrCreateFolder($token, $folderName, $rootId);
    $sharedId = $useLocal ? null : findOrCreateFolder($token, 'Shared Files', $clientIdFolder);

    $fileRef = null; $mime = 'link'; $size = 0; $filename = strip_tags($i['filename'] ?? 'Untitled');
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $filename = $_FILES['file']['name'];
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        $size = $_FILES['file']['size'];

        if (!$useLocal) {
            // Attempt Drive upload first
            $meta = json_encode(['name' => $filename, 'parents' => [$sharedId]]);
            $boundary = '-------' . md5(time());
            $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($_FILES['file']['tmp_name']) . "\r\n--$boundary--";
            
            $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: multipart/related; boundary=$boundary", "Content-Length: " . strlen($body)]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (!empty($res['id'])) {
                $fileRef = "drive:" . $res['id'];
            } else {
                // Fallback to local storage if Drive fails
                $useLocal = true;
            }
        }

        if ($useLocal) {
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $storedName = uniqid('file_', true) . '_' . $safeName;
            $dest = rtrim($uploadDir, '/') . '/' . $storedName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                sendJson('error', 'File upload failed');
            }
            $fileRef = 'local:' . $storedName;
        }
    } elseif (!empty($i['external_url'])) {
        $fileRef = strip_tags($i['external_url']);
    } else {
        $err = $_FILES['file']['error'] ?? 'No file';
        sendJson('error', "No valid file or link provided (Code $err)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS shared_files (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, uploader_id INTEGER, filename TEXT, external_url TEXT, file_type TEXT, filesize INTEGER, project_id INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->prepare("INSERT INTO shared_files (client_id, uploader_id, filename, external_url, file_type, filesize, project_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$clientId, $u['uid'], $filename, $fileRef, $mime, $size, $pid]);

    $fileId = $pdo->lastInsertId();
    // CRITICAL FIX: Return file details so frontend knows it succeeded
    sendJson('success', 'File Saved', ['file_id' => $fileId, 'filename' => $filename, 'file_type' => $mime]);
}

function handleDownloadFile($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
    $stmt->execute([(int)$i['file_id']]);
    $file = $stmt->fetch();
    
    if (!$file) die("File not found");
    if ($u['role'] !== 'admin' && $u['uid'] != $file['client_id'] && $u['role'] !== 'partner') die("Access Denied");

    $ref = $file['external_url'];

    // Local storage download
    if (strpos($ref, 'local:') === 0) {
        $basename = basename(str_replace('local:', '', $ref));
        $path = __DIR__ . '/../../data/uploads/' . $basename;
        if (!file_exists($path)) die("File missing");
        header("Content-Type: " . ($file['file_type'] ?: 'application/octet-stream'));
        header("Content-Disposition: attachment; filename=\"" . $file['filename'] . "\"");
        readfile($path);
        exit;
    }

    // External direct link
    if (strpos($ref, 'drive:') === false) { header("Location: $ref"); exit; }
    
    $token = getGoogleAccessToken($secrets);
    $googleId = str_replace('drive:', '', $ref);
    
    header("Content-Type: " . $file['file_type']);
    header("Content-Disposition: attachment; filename=\"" . $file['filename'] . "\"");
    
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$googleId?alt=media");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_exec($ch);
    curl_close($ch);
    exit;
}

function handleDeleteFile($pdo, $i, $secrets) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
    $stmt->execute([(int)$i['file_id']]);
    $file = $stmt->fetch();
    
    if ($file && strpos($file['external_url'], 'drive:') === 0) {
        $token = getGoogleAccessToken($secrets);
        driveRequest($token, 'DELETE', "files/" . str_replace('drive:', '', $file['external_url']));
    }
    
    $pdo->prepare("DELETE FROM shared_files WHERE id = ?")->execute([(int)$i['file_id']]);
    sendJson('success', 'Deleted');
}
?>
