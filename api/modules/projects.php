<?php
// /api/modules/projects.php
// Version: 29.0 - Partner Access Added

function handleGetProjects($pdo,$i){
    $u=verifyAuth($i);
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(255), description TEXT, status VARCHAR(50), health_score INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
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
    // 1. Security: Admin or Partner only
    if ($u['role'] !== 'admin' && $u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    
    $pid = (int)$i['project_id'];
    $status = strip_tags($i['status']);
    $health = (int)$i['health_score'];
    
    // 2. Update Project
    $pdo->prepare("UPDATE projects SET status=?, health_score=? WHERE id=?")->execute([$status, $health, $pid]);
    
    // 3. Log to Chat (System Message style)
    $msg = "Project status updated to: " . strtoupper($status);
    // target_id 0 implies 'project' level comment
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$pid, $u['uid'], $msg]);

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

function handleSaveTask($pdo,$i){
    $u=verifyAuth($i);
    $pdo->prepare("INSERT INTO tasks (project_id, title) VALUES (?, ?)")->execute([(int)$i['project_id'], strip_tags($i['title'])]);
    // Recalc health
    $r=$pdo->query("SELECT COUNT(*) as total, SUM(is_complete) as done FROM tasks WHERE project_id=".(int)$i['project_id'])->fetch();
    $s=($r['total']>0)?round(($r['done']/$r['total'])*100):0;
    $pdo->prepare("UPDATE projects SET health_score = ? WHERE id = ?")->execute([$s,(int)$i['project_id']]);
    sendJson('success','Saved');
}

function handleToggleTask($pdo,$i){
    $u=verifyAuth($i); $id=(int)$i['id'];
    $pdo->prepare("UPDATE tasks SET is_complete = ? WHERE id = ?")->execute([(int)$i['is_complete'], $id]);
    sendJson('success','Updated');
}

function handlePostComment($pdo,$i){
    $u=verifyAuth($i);
    $pid = (int)$i['project_id'];
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, ?, ?)")->execute([$pid,$u['uid'],strip_tags($i['message']),$i['target_type'],(int)$i['target_id']]);
    
    // NOTIFY PARTNER LOGIC
    if ($u['role'] === 'client') {
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client {$u['name']} commented on a Project.");
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