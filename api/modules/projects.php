<?php
// /api/modules/projects.php
// Version: 29.1 - Moved table creation outside function to guarantee initialization

$pdo->exec("CREATE TABLE IF NOT EXISTS projects (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(255), description TEXT, status VARCHAR(50), health_score INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

function recalcProjectHealth($pdo, $pid) {
    $r = $pdo->query("SELECT COUNT(*) as total, SUM(is_complete) as done FROM tasks WHERE project_id=" . (int)$pid)->fetch();
    $score = ($r['total'] > 0) ? round(($r['done'] / $r['total']) * 100) : 0;
    $pdo->prepare("UPDATE projects SET health_score = ? WHERE id = ?")->execute([$score, $pid]);
    return $score;
}

function handleGetProjects($pdo,$i){
    $u=verifyAuth($i);
    // REMOVED: $pdo->exec("CREATE TABLE IF NOT EXISTS projects ...") from here
    
    if($u['role']==='admin'){
        $s=$pdo->query("SELECT p.*, COALESCE(NULLIF(u.full_name, ''), NULLIF(u.email, ''), 'Unassigned') as client_name FROM projects p LEFT JOIN users u ON p.user_id=u.id ORDER BY client_name ASC, p.created_at DESC");
    } elseif ($u['role'] === 'partner') {
        // Fetch Own Projects + Assigned Clients' Projects
        ensurePartnerSchema($pdo);
        $sql = "SELECT p.*, u.full_name as client_name 
                FROM projects p 
                LEFT JOIN users u ON p.user_id=u.id 
                WHERE p.user_id = ? 
                OR p.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) 
                ORDER BY p.created_at DESC";
        $s=$pdo->prepare($sql);
        $s->execute([$u['uid'], $u['uid']]);
    } else {
        $s=$pdo->prepare("SELECT * FROM projects WHERE user_id=? ORDER BY created_at DESC");
        $s->execute([$u['uid']]);
    }
    sendJson('success','Fetched',['projects'=>$s->fetchAll()]);
}

function handleCreateProject($pdo,$i){
    $u=verifyAuth($i); if($u['role']!=='admin')sendJson('error','Unauthorized');
    $pdo->prepare("INSERT INTO projects (user_id, title, status, health_score) VALUES (?, ?, 'onboarding', 0)")->execute([(int)$i['client_id'], strip_tags($i['title'])]);
    sendJson('success','Created');
}

function handleUpdateProjectStatus($pdo, $i, $s) {
    $u = verifyAuth($i); 
    if ($u['role'] !== 'admin' && $u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    
    $pid = (int)$i['project_id'];
    $status = strip_tags($i['status']);
    $health = (int)$i['health_score'];
    
    $stmt = $pdo->prepare("SELECT user_id, title FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
    if (!$project) sendJson('error', 'Project not found');

    $pdo->prepare("UPDATE projects SET status=?, health_score=? WHERE id=?")->execute([$status, $health, $pid]);
    
    $actorName = $u['name'] ?? $u['full_name'] ?? 'Staff';
    $msg = "Status updated to " . strtoupper($status) . " by $actorName";
    
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$pid, $u['uid'], $msg]);

    // Notify Client with Deep Link
    createNotification($pdo, $project['user_id'], "Project '{$project['title']}' updated to " . strtoupper($status) . " by $actorName", 'project', $pid);

    sendJson('success', 'Updated');
}

function handleDeleteProject($pdo,$i){
    $u=verifyAuth($i); if($u['role']!=='admin')sendJson('error','Unauthorized');
    $pid=(int)$i['project_id'];
    $pdo->prepare("DELETE FROM tasks WHERE project_id=?")->execute([$pid]);
    $pdo->prepare("DELETE FROM comments WHERE project_id=?")->execute([$pid]);
    $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$pid]);
    sendJson('success','Deleted');
}

function handleGetProjectDetails($pdo,$i){
    $u=verifyAuth($i); $pid=(int)$i['project_id'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, title VARCHAR(255), is_complete TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, user_id INT, message TEXT, target_type VARCHAR(20), target_id INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    // Partner Security Check: Can this user view this project?
    if ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $check = $pdo->prepare("SELECT id FROM projects WHERE id=? AND (user_id=? OR user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id=?))");
        $check->execute([$pid, $u['uid'], $u['uid']]);
        if (!$check->fetch()) sendJson('error', 'Access Denied');
    }

    $t=$pdo->prepare("SELECT * FROM tasks WHERE project_id=? ORDER BY id ASC"); $t->execute([$pid]);
    $c=$pdo->prepare("SELECT c.*, u.full_name FROM comments c JOIN users u ON c.user_id=u.id WHERE c.project_id=? ORDER BY c.created_at ASC"); $c->execute([$pid]);
    sendJson('success','Loaded',['tasks'=>$t->fetchAll(),'comments'=>$c->fetchAll()]);
}

function handleSaveTask($pdo, $i) {
    $u = verifyAuth($i);
    $pid = (int)$i['project_id'];
    $title = strip_tags($i['title']);
    
    // 1. Save
    $pdo->prepare("INSERT INTO tasks (project_id, title) VALUES (?, ?)")->execute([$pid, $title]);
    
    // 2. Recalc
    recalcProjectHealth($pdo, $pid);
    
    // 3. Notify & Log
    $p = $pdo->query("SELECT title, user_id FROM projects WHERE id=$pid")->fetch();
    $msg = "New task added: $title";
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")->execute([$pid, $u['uid'], $msg]);
    createNotification($pdo, $p['user_id'], "New Task in '{$p['title']}': $title");
    
    sendJson('success', 'Saved');
}

function handleToggleTask($pdo, $i) {
    $u = verifyAuth($i);
    $tid = (int)$i['id'];
    $done = (int)$i['is_complete'];
    
    // 1. Update
    $pdo->prepare("UPDATE tasks SET is_complete = ? WHERE id = ?")->execute([$done, $tid]);
    
    // 2. Recalc (Need Project ID)
    $t = $pdo->query("SELECT project_id, title FROM tasks WHERE id=$tid")->fetch();
    if ($t) {
        $pid = $t['project_id'];
        recalcProjectHealth($pdo, $pid);
        
        // 3. Log
        $status = $done ? "Completed" : "Re-opened";
        $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
            ->execute([$pid, $u['uid'], "Task '{$t['title']}' marked as $status"]);
    }
    
    sendJson('success', 'Updated');
}

function handleDeleteTask($pdo, $i) {
    $u = verifyAuth($i);
    $tid = (int)$i['task_id'];
    
    // 1. Get Info
    $t = $pdo->query("SELECT project_id, title FROM tasks WHERE id=$tid")->fetch();
    if (!$t) sendJson('error', 'Not found');
    
    // 2. Delete
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$tid]);
    
    // 3. Recalc & Log
    recalcProjectHealth($pdo, $t['project_id']);
    
    $pid = $t['project_id'];
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$pid, $u['uid'], "Task removed: {$t['title']}"]);
        
    sendJson('success', 'Deleted');
}

function handlePostComment($pdo,$i){
    $u = verifyAuth($i);
    $pid = (int)$i['project_id'];
    $message = strip_tags($i['message']);
    
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$pid, $u['uid'], $message, $i['target_type'], (int)$i['target_id']]);
    
    $stmt = $pdo->prepare("SELECT user_id, title FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();

    $actorName = $u['name'] ?? $u['full_name'] ?? 'Someone';

    if ($u['role'] === 'client') {
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client $actorName commented on '{$project['title']}'");
    } else {
        if ($project['user_id'] != $u['uid']) {
            createNotification($pdo, $project['user_id'], "$actorName commented on '{$project['title']}': " . substr($message, 0, 50) . "...", 'project', $pid);
        }
    }

    sendJson('success','Posted');
}

function handleGetFiles($pdo,$i){
    $u=verifyAuth($i);
    $pdo->exec("CREATE TABLE IF NOT EXISTS shared_files (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, uploader_id INT, filename VARCHAR(255), filepath VARCHAR(255), external_url TEXT, file_type VARCHAR(20), filesize VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    if($u['role']==='admin'){
        $s=$pdo->query("SELECT f.*,u.full_name as client_name FROM shared_files f JOIN users u ON f.client_id=u.id ORDER BY f.created_at DESC");
    } elseif ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $s=$pdo->prepare("SELECT f.*, u.full_name as client_name FROM shared_files f JOIN users u ON f.client_id=u.id WHERE f.client_id=? OR f.client_id IN (SELECT client_id FROM partner_assignments WHERE partner_id=?) ORDER BY f.created_at DESC");
        $s->execute([$u['uid'], $u['uid']]);
    } else {
        $s=$pdo->prepare("SELECT * FROM shared_files WHERE client_id=? ORDER BY created_at DESC");$s->execute([$u['uid']]);
    }
    
    $f=$s->fetchAll();
    $b="https://".$_SERVER['HTTP_HOST']."/api/uploads/";
    foreach($f as &$x)$x['url']=$x['file_type']==='link'?$x['external_url']:$b.$x['filepath'];
    sendJson('success','Fetched',['files'=>$f]);
}

function handleUploadFile($pdo,$i){
    $u=verifyAuth($i);
    // If admin or partner, they upload FOR the client_id passed. If client, they upload for themselves.
    $cid = $u['uid'];
    if (($u['role'] === 'admin' || $u['role'] === 'partner') && !empty($i['client_id'])) {
        $cid = (int)$i['client_id'];
    }

    $dir=__DIR__.'/../uploads/'; if(!is_dir($dir))mkdir($dir,0755,true);
    $name=uniqid().'_'.$_FILES['file']['name'];
    if(move_uploaded_file($_FILES['file']['tmp_name'],$dir.$name)){
        $pdo->prepare("INSERT INTO shared_files(client_id,uploader_id,filename,filepath,file_type,filesize) VALUES(?,?,?,?, 'file', ?)")->execute([$cid,$u['uid'],$_FILES['file']['name'],$name,round($_FILES['file']['size']/1024).' KB']);
        sendJson('success','Uploaded');
    } else sendJson('error','Failed');
}
?>