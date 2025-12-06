<?php
// =============================================================================
// Wandering Webmaster Custom Component
// Agency: Wandering Webmaster (wandweb.co)
// Client: Portal Architecture
// Version: 15.3 - Fixed Function Redeclaration & Stripe Data
// =============================================================================

function getOrSyncStripeId($pdo, $user_id, $stripe_id = null) {
    if (!empty($stripe_id)) {
        $stmt = $pdo->prepare("UPDATE users SET stripe_id = ? WHERE id = ?");
        $stmt->execute([$stripe_id, $user_id]);
        return $stripe_id;
    }
    
    $stmt = $pdo->prepare("SELECT stripe_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return $row['stripe_id'] ?? null;
}

function handleGetBilling($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    $stripe_id = getOrSyncStripeId($pdo, $user['id']);
    if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
    
    $invoices = []; $subscriptions = [];
    
    // Fetch Invoices
    $rawInv = stripeRequest($secrets, 'GET', "invoices?customer=$stripe_id&limit=10&expand[]=data.customer");
    if (isset($rawInv['data'])) {
        foreach ($rawInv['data'] as $inv) {
            $invoices[] = [
                'id' => $inv['id'],
                'number' => $inv['number'],
                'amount' => number_format($inv['total'] / 100, 2),
                'status' => $inv['status'],
                'date' => date('Y-m-d', $inv['created']),
                'pdf' => $inv['hosted_invoice_url'] ?? $inv['invoice_pdf'] ?? ''
            ];
        }
    }
    
    // Fetch Subscriptions
    $rawSub = stripeRequest($secrets, 'GET', "subscriptions?customer=$stripe_id&limit=10&expand[]=data.plan.product");
    if (isset($rawSub['data'])) {
        foreach ($rawSub['data'] as $sub) {
            $subscriptions[] = [
                'id' => $sub['id'],
                'plan' => $sub['items']['data'][0]['price']['product']['name'] ?? 'Unknown',
                'amount' => number_format($sub['items']['data'][0]['price']['unit_amount'] / 100, 2),
                'interval' => $sub['items']['data'][0]['price']['recurring']['interval'] ?? 'one-time',
                'status' => $sub['status'],
                'next_bill' => date('Y-m-d', $sub['current_period_end'] ?? time())
            ];
        }
    }
    
    sendJson('success', 'Billing Loaded', ['invoices' => $invoices, 'subscriptions' => $subscriptions]);
}

// ... (Keep other handler functions if used, but ensure handleGetBilling is NOT duplicated) ...
?>
