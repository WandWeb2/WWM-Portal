<?php
// =============================================================================
// Wandering Webmaster Services Module
// Version: 1.0 - Restored Service Logic
// =============================================================================

function ensureServiceSchema($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $idType = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    
    // Local metadata for Stripe Products (Categorization, Sorting, Visibility)
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_metadata (
        stripe_product_id VARCHAR(50) PRIMARY KEY,
        category VARCHAR(100) DEFAULT 'General',
        is_hidden TINYINT DEFAULT 0,
        sort_order INT DEFAULT 999,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function handleGetServices($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    ensureServiceSchema($pdo);
    
    // 1. Fetch Local Metadata
    $meta = [];
    $stmt = $pdo->query("SELECT * FROM product_metadata");
    while ($row = $stmt->fetch()) {
        $meta[$row['stripe_product_id']] = $row;
    }
    
    // 2. Fetch Stripe Products & Prices
    // We fetch active products and expand default_price if possible, but for robust lists we fetch prices separately
    $products = stripeRequest($secrets, 'GET', 'products?limit=100&active=true');
    $prices = stripeRequest($secrets, 'GET', 'prices?limit=100&active=true');
    
    if (!isset($products['data']) || !isset($prices['data'])) {
        sendJson('error', 'Failed to fetch services from Stripe');
    }
    
    // 3. Map Prices to Products
    $priceMap = [];
    foreach ($prices['data'] as $p) {
        $prodId = is_string($p['product']) ? $p['product'] : $p['product']['id'];
        $priceMap[$prodId][] = [
            'id' => $p['id'],
            'amount' => $p['unit_amount'] / 100,
            'currency' => strtoupper($p['currency']),
            'interval' => $p['recurring']['interval'] ?? 'one-time'
        ];
    }
    
    // 4. Build Output
    $services = [];
    foreach ($products['data'] as $prod) {
        $pid = $prod['id'];
        // Skip if locally hidden and user is not admin
        $isHidden = (bool)($meta[$pid]['is_hidden'] ?? false);
        if ($isHidden && $user['role'] !== 'admin') continue;
        
        $services[] = [
            'id' => $pid,
            'name' => $prod['name'],
            'description' => $prod['description'],
            'image' => $prod['images'][0] ?? null,
            'category' => $meta[$pid]['category'] ?? 'General',
            'is_hidden' => $isHidden,
            'prod_sort' => (int)($meta[$pid]['sort_order'] ?? 999),
            'prices' => $priceMap[$pid] ?? []
        ];
    }
    
    // Simple sort by prod_sort
    usort($services, function($a, $b) { return $a['prod_sort'] - $b['prod_sort']; });
    
    sendJson('success', 'Services Loaded', ['services' => $services]);
}

function handleCreateProduct($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    // Create in Stripe
    $stripeData = [
        'name' => $input['name'],
        'description' => $input['description'] ?? ''
    ];
    $prod = stripeRequest($secrets, 'POST', 'products', $stripeData);
    
    if (empty($prod['id'])) sendJson('error', 'Stripe Create Failed');
    
    // Create Price
    $priceData = [
        'product' => $prod['id'],
        'unit_amount' => (int)($input['amount'] * 100),
        'currency' => 'usd'
    ];
    if ($input['interval'] !== 'one-time') {
        $priceData['recurring'] = ['interval' => $input['interval']];
    }
    stripeRequest($secrets, 'POST', 'prices', $priceData);
    
    // Save Local Meta
    ensureServiceSchema($pdo);
    $pdo->prepare("INSERT INTO product_metadata (stripe_product_id, category) VALUES (?, ?)")
        ->execute([$prod['id'], $input['category'] ?? 'General']);
        
    sendJson('success', 'Product Created');
}

function handleUpdateProduct($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    // Update Stripe
    stripeRequest($secrets, 'POST', "products/{$input['product_id']}", [
        'name' => $input['name'],
        'description' => $input['description']
    ]);
    
    // Update Local Meta
    ensureServiceSchema($pdo);
    $stmt = $pdo->prepare("INSERT INTO product_metadata (stripe_product_id, category) VALUES (?, ?) ON DUPLICATE KEY UPDATE category = ?");
    
    // SQLite Fallback for ON DUPLICATE KEY
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->prepare("REPLACE INTO product_metadata (stripe_product_id, category, is_hidden, sort_order) VALUES (?, ?, (SELECT is_hidden FROM product_metadata WHERE stripe_product_id=?), (SELECT sort_order FROM product_metadata WHERE stripe_product_id=?))")
            ->execute([$input['product_id'], $input['category'], $input['product_id'], $input['product_id']]);
    } else {
        // MySQL
        $stmt->execute([$input['product_id'], $input['category'], $input['category']]);
    }
    
    sendJson('success', 'Updated');
}

function handleDeleteProduct($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    
    // Archive in Stripe (Deleting is often restricted if it has transactions)
    stripeRequest($secrets, 'POST', "products/{$input['product_id']}", ['active' => 'false']);
    
    // Remove local meta
    ensureServiceSchema($pdo);
    $pdo->prepare("DELETE FROM product_metadata WHERE stripe_product_id = ?")->execute([$input['product_id']]);
    
    sendJson('success', 'Product Archived');
}

function handleToggleProductVisibility($pdo, $input) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureServiceSchema($pdo);
    
    $pid = $input['product_id'];
    $hidden = $input['hidden'] ? 1 : 0;
    
    // Upsert visibility
    $exists = $pdo->prepare("SELECT stripe_product_id FROM product_metadata WHERE stripe_product_id = ?");
    $exists->execute([$pid]);
    if ($exists->fetch()) {
        $pdo->prepare("UPDATE product_metadata SET is_hidden = ? WHERE stripe_product_id = ?")->execute([$hidden, $pid]);
    } else {
        $pdo->prepare("INSERT INTO product_metadata (stripe_product_id, is_hidden) VALUES (?, ?)")->execute([$pid, $hidden]);
    }
    
    sendJson('success', 'Visibility Updated');
}

function handleSaveServiceOrder($pdo, $input) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    ensureServiceSchema($pdo);
    
    $items = $input['items'] ?? [];
    $stmt = $pdo->prepare("UPDATE product_metadata SET sort_order = ? WHERE stripe_product_id = ?");
    
    // If local record doesn't exist, we must create it. 
    // For simplicity, we assume admins have viewed the list which likely syncs basics, 
    // or we just INSERT OR IGNORE if possible. 
    // Here we'll just try update, if user organizes raw stripe items they might miss metadata entries.
    // Ideally we iterate and upsert.
    
    foreach ($items as $item) {
        // Simple UPSERT for sort order
        $pid = $item['key'];
        $sort = (int)$item['index'];
        
        $check = $pdo->prepare("SELECT stripe_product_id FROM product_metadata WHERE stripe_product_id = ?");
        $check->execute([$pid]);
        if ($check->fetch()) {
            $stmt->execute([$sort, $pid]);
        } else {
            $pdo->prepare("INSERT INTO product_metadata (stripe_product_id, sort_order) VALUES (?, ?)")->execute([$pid, $sort]);
        }
    }
    sendJson('success', 'Order Saved');
}

function handleCreateCheckout($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    
    $priceId = $input['price_id'];
    $mode = ($input['interval'] === 'one-time') ? 'payment' : 'subscription';
    
    $payload = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'mode' => $mode,
        'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/portal/?payment=success',
        'cancel_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/portal/?payment=cancelled',
    ];
    
    // Attach Customer if known
    if (!empty($user['uid'])) {
        $stmt = $pdo->prepare("SELECT stripe_id FROM users WHERE id = ?");
        $stmt->execute([$user['uid']]);
        $row = $stmt->fetch();
        if (!empty($row['stripe_id'])) {
            $payload['customer'] = $row['stripe_id'];
        } else {
            // Optional: Auto-create customer here if missing?
            // For now, let Stripe handle guest checkout if no ID, or rely on email prefill
            $payload['customer_email'] = getSetting($pdo, 'admin_email', ''); // Placeholder, should be user email
            // Better: fetch user email
            $uStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $uStmt->execute([$user['uid']]);
            $uRow = $uStmt->fetch();
            if($uRow) $payload['customer_email'] = $uRow['email'];
        }
    }
    
    $session = stripeRequest($secrets, 'POST', 'checkout/sessions', $payload);
    
    if (isset($session['url'])) {
        sendJson('success', 'Checkout Created', ['url' => $session['url']]);
    } else {
        sendJson('error', 'Could not init checkout');
    }
}
?>
