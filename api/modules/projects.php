<?php
// /api/modules/projects.php
// Version: 29.3 - SAFE: Ensure table creation happens inside functions to avoid DB access at include time.

function ensureProjectSchema($pdo) {
    // Create projects and dependent tables if missing. Call from handlers.
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        title VARCHAR(255),
        description TEXT,
        status VARCHAR(50) DEFAULT 'onboarding',
        health_score INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT,
        title VARCHAR(255),
        is_complete TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT,
        user_id INT,
        message TEXT,
        target_type VARCHAR(50),
        target_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shared_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT,
        uploader_id INT,
        filename VARCHAR(255),
        filepath VARCHAR(255),
        external_url TEXT,
        file_type VARCHAR(50),
        filesize VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

function recalcProjectHealth($pdo, $pid) {
    $pid = (int)$pid;
    $r = $pdo->prepare("SELECT COUNT(*) as total, SUM(is_complete) as done FROM tasks WHERE project_id = ?");
    $r->execute([$pid]);
    $row = $r->fetch();
    $total = (int)($row['total'] ?? 0);
    $done = (int)($row['done'] ?? 0);
    $score = ($total > 0) ? (int)round(($done / $total) * 100) : 0;
    $pdo->prepare("UPDATE projects SET health_score = ? WHERE id = ?")->execute([$score, $pid]);
    return $score;
}

function handleGetProjects($pdo, $i) {
    $u = verifyAuth($i);
    ensureProjectSchema($pdo);

    if ($u['role'] === 'admin') {
        $s = $pdo->query(
            "SELECT p.*, COALESCE(NULLIF(u.full_name, ''), NULLIF(u.email, ''), 'Unassigned') AS client_name
             FROM projects p LEFT JOIN users u ON p.user_id = u.id
             ORDER BY FIELD(p.status, 'active','onboarding','stalled','complete','archived') ASC, p.created_at ASC"
        );
    } elseif ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $sql = "SELECT p.*, COALESCE(NULLIF(u.full_name, ''), NULLIF(u.email, ''), 'Unassigned') AS client_name
                FROM projects p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.user_id = ? OR p.user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?)
                ORDER BY FIELD(p.status, 'active','onboarding','stalled','complete','archived') ASC, p.created_at ASC";
        $s = $pdo->prepare($sql);
        $s->execute([$u['uid'], $u['uid']]);
    } else {
        $s = $pdo->prepare("SELECT p.*, COALESCE(NULLIF(u.full_name, ''), NULLIF(u.email, ''), 'Unassigned') AS client_name FROM projects p LEFT JOIN users u ON p.user_id = u.id WHERE p.user_id = ? ORDER BY p.created_at DESC");
        $s->execute([$u['uid']]);
    }

    sendJson('success', 'Fetched', ['projects' => $s->fetchAll()]);
}

function handleCreateProject($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureProjectSchema($pdo);

    $clientId = (int)($i['client_id'] ?? 0);
    $title = strip_tags($i['title'] ?? 'Untitled Project');
    $stmt = $pdo->prepare("INSERT INTO projects (user_id, title, status, health_score) VALUES (?, ?, 'onboarding', 0)");
    $stmt->execute([$clientId, $title]);
    sendJson('success', 'Created');
}

function handleUpdateProjectStatus($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin' && $u['role'] !== 'partner') sendJson('error', 'Unauthorized');
    ensureProjectSchema($pdo);

    $pid = (int)($i['project_id'] ?? 0);
    $status = strip_tags($i['status'] ?? '');
    $health = (int)($i['health_score'] ?? 0);

    $stmt = $pdo->prepare("SELECT user_id, title FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
    if (!$project) sendJson('error', 'Project not found');

    $pdo->prepare("UPDATE projects SET status = ?, health_score = ? WHERE id = ?")->execute([$status, $health, $pid]);

    $actorName = $u['name'] ?? $u['full_name'] ?? 'Staff';
    $msg = "Status updated to " . strtoupper($status) . " by $actorName";

    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$pid, $u['uid'], $msg]);

    // Deep-linking notification to project owner
    createNotification($pdo, $project['user_id'], "Project '" . $project['title'] . "' updated to " . strtoupper($status) . " by $actorName", 'project', $pid);

    sendJson('success', 'Updated');
}

function handleDeleteProject($pdo, $i) {
    $u = verifyAuth($i);
    if ($u['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureProjectSchema($pdo);

    $pid = (int)($i['project_id'] ?? 0);
    $pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$pid]);
    $pdo->prepare("DELETE FROM comments WHERE project_id = ?")->execute([$pid]);
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]);
    sendJson('success', 'Deleted');
}

function handleGetProjectDetails($pdo, $i) {
    $u = verifyAuth($i);
    $pid = (int)($i['project_id'] ?? 0);
    ensureProjectSchema($pdo);

    if ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $check = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND (user_id = ? OR user_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?))");
        $check->execute([$pid, $u['uid'], $u['uid']]);
        if (!$check->fetch()) sendJson('error', 'Access Denied');
    }

    $t = $pdo->prepare("SELECT * FROM tasks WHERE project_id = ? ORDER BY id ASC");
    $t->execute([$pid]);
    $c = $pdo->prepare("SELECT c.*, COALESCE(u.full_name, u.email) AS author FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.project_id = ? ORDER BY c.created_at ASC");
    $c->execute([$pid]);

    sendJson('success', 'Loaded', ['tasks' => $t->fetchAll(), 'comments' => $c->fetchAll()]);
}

function handleSaveTask($pdo, $i) {
    $u = verifyAuth($i);
    $pid = (int)($i['project_id'] ?? 0);
    $title = strip_tags($i['title'] ?? 'Untitled Task');
    ensureProjectSchema($pdo);

    $pdo->prepare("INSERT INTO tasks (project_id, title) VALUES (?, ?)")->execute([$pid, $title]);
    recalcProjectHealth($pdo, $pid);

    $p = $pdo->prepare("SELECT title, user_id FROM projects WHERE id = ?");
    $p->execute([$pid]);
    $proj = $p->fetch();
    $msg = "New task added: $title";
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$pid, $u['uid'], $msg]);
    if ($proj) createNotification($pdo, $proj['user_id'], "New Task in '" . $proj['title'] . "': $title", 'project', $pid);

    sendJson('success', 'Saved');
}

function handleToggleTask($pdo, $i) {
    $u = verifyAuth($i);
    $tid = (int)($i['id'] ?? 0);
    $done = (int)($i['is_complete'] ?? 0);
    ensureProjectSchema($pdo);

    $pdo->prepare("UPDATE tasks SET is_complete = ? WHERE id = ?")->execute([$done, $tid]);
    $t = $pdo->prepare("SELECT project_id, title FROM tasks WHERE id = ?");
    $t->execute([$tid]);
    $row = $t->fetch();
    if ($row) {
        $pid = $row['project_id'];
        recalcProjectHealth($pdo, $pid);
        $status = $done ? 'Completed' : 'Re-opened';
        $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
            ->execute([$pid, $u['uid'], "Task '" . $row['title'] . "' marked as $status"]);
    }

    sendJson('success', 'Updated');
}

function handleDeleteTask($pdo, $i) {
    $u = verifyAuth($i);
    $tid = (int)($i['task_id'] ?? 0);
    ensureProjectSchema($pdo);

    $t = $pdo->prepare("SELECT project_id, title FROM tasks WHERE id = ?");
    $t->execute([$tid]);
    $row = $t->fetch();
    if (!$row) sendJson('error', 'Not found');

    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$tid]);
    recalcProjectHealth($pdo, $row['project_id']);
    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, 'project', 0)")
        ->execute([$row['project_id'], $u['uid'], "Task removed: " . $row['title']]);

    sendJson('success', 'Deleted');
}

function handlePostComment($pdo, $i) {
    $u = verifyAuth($i);
    $pid = (int)($i['project_id'] ?? 0);
    $message = strip_tags($i['message'] ?? '');
    ensureProjectSchema($pdo);

    $pdo->prepare("INSERT INTO comments (project_id, user_id, message, target_type, target_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$pid, $u['uid'], $message, ($i['target_type'] ?? 'project'), (int)($i['target_id'] ?? 0)]);

    $stmt = $pdo->prepare("SELECT user_id, title FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();

    $actorName = $u['name'] ?? $u['full_name'] ?? 'Someone';
    if ($u['role'] === 'client') {
        notifyPartnerIfAssigned($pdo, $u['uid'], "Client $actorName commented on '" . ($project['title'] ?? '') . "'");
    } else {
        if (!empty($project['user_id']) && $project['user_id'] != $u['uid']) {
            createNotification($pdo, $project['user_id'], $actorName . " commented on '" . ($project['title'] ?? '') . "': " . substr($message, 0, 50) . "...", 'project', $pid);
        }
    }

    sendJson('success', 'Posted');
}

function handleGetFiles($pdo, $i) {
    $u = verifyAuth($i);
    ensureProjectSchema($pdo);

    if ($u['role'] === 'admin') {
        $s = $pdo->query("SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id ORDER BY f.created_at DESC");
    } elseif ($u['role'] === 'partner') {
        ensurePartnerSchema($pdo);
        $sql = "SELECT f.*, COALESCE(u.full_name, u.email) as client_name FROM shared_files f JOIN users u ON f.client_id = u.id WHERE f.client_id = ? OR f.client_id IN (SELECT client_id FROM partner_assignments WHERE partner_id = ?) ORDER BY f.created_at DESC";
        $s = $pdo->prepare($sql);
        $s->execute([$u['uid'], $u['uid']]);
    } else {
        $s = $pdo->prepare("SELECT * FROM shared_files WHERE client_id = ? ORDER BY created_at DESC");
        $s->execute([$u['uid']]);
    }

    sendJson('success', 'Files', ['files' => $s->fetchAll()]);
}

?>

