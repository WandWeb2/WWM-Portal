<?php
// /api/modules/billing.php

function getOrSyncStripeId($pdo, $uid, $secrets) { 
    $s = $pdo->prepare("SELECT stripe_id, email, full_name FROM users WHERE id=?"); 
    $s->execute([$uid]); 
    $u = $s->fetch(); 
    if (!empty($u['stripe_id'])) { 
        $cust = stripeRequest($secrets, 'GET', "customers/{$u['stripe_id']}"); 
        if (isset($cust['id']) && !isset($cust['deleted'])) return $u['stripe_id']; 
    } 
    $newC = stripeRequest($secrets, 'POST', 'customers', ['email' => $u['email'], 'name' => $u['full_name'], 'metadata' => ['portal_source' => 'wandweb_v2']]); 
    if (isset($newC['id'])) { 
        $pdo->prepare("UPDATE users SET stripe_id=? WHERE id=?")->execute([$newC['id'], $uid]); 
        return $newC['id']; 
    } 
    return null; 
}

function handleGetBilling($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    $bal = stripeRequest($secrets, 'GET', 'balance');
    $avail = ($bal['available'][0]['amount'] ?? 0) / 100;
    $pending = ($bal['pending'][0]['amount'] ?? 0) / 100;
    $feed = [];
    
    // Admin view
    if ($user['role'] === 'admin') {
        $rawCharges = stripeRequest($secrets, 'GET', 'charges?limit=20')['data'] ?? [];
        foreach ($rawCharges as $c) {
            $feed[] = ['id' => $c['id'], 'type' => 'payment', 'title' => 'Payment from ' . ($c['billing_details']['email'] ?? 'Client'), 'amount' => number_format($c['amount'] / 100, 2), 'status' => $c['status'], 'date_ts' => $c['created'], 'date_display' => date('M d, H:i', $c['created'])];
        }
        $rawInvoices = stripeRequest($secrets, 'GET', 'invoices?limit=20')['data'] ?? [];
        $invList = [];
        foreach($rawInvoices as $i) {
            $clientName = $i['customer_name'] ?? $i['customer_email'] ?? 'Unknown';
            $invList[] = ['id' => $i['id'], 'number' => $i['number'] ?? 'DRAFT', 'client_name' => $clientName, 'amount' => number_format($i['total']/100, 2), 'status' => $i['status'], 'pdf' => $i['invoice_pdf'], 'date' => date('M d', $i['created']), 'date_ts' => $i['created']];
            $feed[] = ['id' => $i['id'], 'type' => 'invoice', 'title' => 'Invoice ' . ($i['number'] ?? 'Draft'), 'amount' => number_format($i['total'] / 100, 2), 'status' => $i['status'], 'date_ts' => $i['created'], 'date_display' => date('M d', $i['created'])];
        }
        $rawQuotes = stripeRequest($secrets, 'GET', 'quotes?limit=20')['data'] ?? [];
        $quotesList = [];
        foreach($rawQuotes as $q) {
             $quotesList[] = ['id'=>$q['id'], 'number'=>$q['number']??'DRAFT', 'client_name'=>$q['customer_email']??'Client', 'amount'=>number_format($q['amount_total']/100, 2), 'status'=>$q['status'], 'date_ts'=>$q['created']];
             $feed[] = ['id'=>$q['id'], 'type'=>'quote', 'title'=>'Quote', 'amount'=>number_format($q['amount_total']/100, 2), 'status'=>$q['status'], 'date_ts'=>$q['created'], 'date_display'=>date('M d', $q['created'])];
        }
        $rawSubs = stripeRequest($secrets, 'GET', "subscriptions?status=active&limit=20&expand%5B%5D=data.plan.product")['data'] ?? [];
        $subsList = [];
        foreach($rawSubs as $s) {
            $subsList[] = ['id'=>$s['id'], 'client_name'=>'Client', 'plan'=>$s['plan']['product']['name']??'Sub', 'amount'=>number_format($s['plan']['amount']/100, 2), 'interval'=>$s['plan']['interval'], 'status'=>$s['status'], 'next_bill'=>date('M d', $s['current_period_end']), 'date_ts'=>$s['created']];
            $feed[] = ['id'=>$s['id'], 'type'=>'subscription', 'title'=>'Sub: '.($s['plan']['product']['name']??''), 'amount'=>number_format($s['plan']['amount']/100, 2), 'status'=>$s['status'], 'date_ts'=>$s['created'], 'date_display'=>date('M d', $s['created'])];
        }
        usort($feed, function($a, $b) { return $b['date_ts'] - $a['date_ts']; });
        sendJson('success', 'Stats', ['stats' => ['available' => number_format($avail, 2), 'pending' => number_format($pending, 2), 'feed' => $feed, 'invoices' => $invList, 'quotes' => $quotesList, 'subscriptions' => $subsList]]);
    } else {
        // Client view
        $sid = getOrSyncStripeId($pdo, $user['uid'], $secrets);
        if (!$sid) sendJson('success', 'No Account', ['invoices' => [], 'subscriptions' => []]);
        $clientInvoices = stripeRequest($secrets, 'GET', "invoices?customer=$sid&limit=10")['data'] ?? [];
        $invoices = array_map(function($v){ return ['id'=>$v['id'], 'number'=>$v['number'], 'amount'=>number_format($v['total']/100, 2), 'status'=>$v['status'], 'date'=>date('Y-m-d', $v['created']), 'pdf'=>$v['invoice_pdf']]; }, $clientInvoices);
        sendJson('success', 'Billing Data', ['invoices' => $invoices]);
    }
}

function handleGetServices($pdo, $input, $secrets, $user) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sort_orders (obj_key VARCHAR(255) PRIMARY KEY, sort_index INT)");
    $sortMap = $pdo->query("SELECT obj_key, sort_index FROM sort_orders")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawProds = stripeRequest($secrets, 'GET', 'products?active=true&limit=100');
    $rawPrices = stripeRequest($secrets, 'GET', 'prices?active=true&limit=100');
    $priceMap = [];
    foreach($rawPrices['data'] ?? [] as $price) {
        if(!$price['active']) continue;
        $priceMap[$price['product']][] = ['id' => $price['id'], 'amount' => number_format($price['unit_amount'] / 100, 2), 'interval' => $price['recurring']['interval'] ?? 'one-time'];
    }
    $services = [];
    foreach($rawProds['data'] ?? [] as $p) {
        $isHidden = ($p['metadata']['portal_hidden'] ?? 'false') === 'true';
        if ($user['role'] !== 'admin' && $isHidden) continue;
        if (empty($priceMap[$p['id']]) && $user['role'] !== 'admin') continue;
        $cat = $p['metadata']['Category'] ?? 'General';
        $services[] = ['id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'image' => $p['images'][0] ?? null, 'category' => $cat, 'is_hidden' => $isHidden, 'prices' => $priceMap[$p['id']] ?? [], 'prod_sort' => $sortMap[$p['id']] ?? 999, 'cat_sort' => $sortMap['cat_' . $cat] ?? 999];
    }
    sendJson('success', 'Services Loaded', ['data' => ['services' => $services]]);
}

function handleCreateInvoice($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $sid = getOrSyncStripeId($pdo, (int)$input['client_id'], $secrets);
    $payload = ['customer' => $sid, 'collection_method' => $input['collection_method'] ?? 'send_invoice', 'description' => $input['memo'] ?? '', 'footer' => $input['footer'] ?? ''];
    if (($input['collection_method'] ?? 'send_invoice') === 'send_invoice') $payload['days_until_due'] = (int)($input['days_until_due'] ?? 7);
    $inv = stripeRequest($secrets, 'POST', 'invoices', $payload);
    if (!isset($inv['id'])) sendJson('error', $inv['error']['message'] ?? 'Create Failed');
    if (!empty($input['items'])) { foreach ($input['items'] as $item) { stripeRequest($secrets, 'POST', 'invoiceitems', ['customer' => $sid, 'price' => $item['price_id'], 'invoice' => $inv['id']]); } }
    if (!empty($input['coupon'])) { stripeRequest($secrets, 'POST', "invoices/{$inv['id']}", ['discounts' => [['coupon' => $input['coupon']]]]); }
    if (!empty($input['send_now']) && $input['send_now'] === true) { stripeRequest($secrets, 'POST', "invoices/{$inv['id']}/finalize"); stripeRequest($secrets, 'POST', "invoices/{$inv['id']}/send"); }
    sendJson('success', 'Invoice Created');
}

function handleCreateQuote($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $sid = getOrSyncStripeId($pdo, (int)$input['client_id'], $secrets);
    $lineItems = [];
    foreach ($input['items'] as $item) { $lineItems[] = ['price' => $item['price_id']]; }
    $quote = stripeRequest($secrets, 'POST', 'quotes', [ 'customer' => $sid, 'line_items' => $lineItems, 'description' => $input['memo'] ?? '', 'footer' => $input['footer'] ?? '' ]);
    if (isset($quote['id'])) {
        if (!empty($input['send_now']) && $input['send_now'] === true) { stripeRequest($secrets, 'POST', "quotes/{$quote['id']}/finalize"); }
        sendJson('success', 'Quote Created');
    } else { sendJson('error', $quote['error']['message'] ?? 'Failed'); }
}

function handleCreateSubscriptionManually($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $sid = getOrSyncStripeId($pdo, (int)$input['client_id'], $secrets);
    $itemsPayload = [];
    foreach ($input['items'] as $item) { $itemsPayload[] = ['price' => $item['price_id']]; }
    $payload = [ 'customer' => $sid, 'items' => $itemsPayload, 'collection_method' => $input['collection_method'] ?? 'send_invoice', 'payment_behavior' => 'default_incomplete', 'description' => $input['memo'] ?? '' ];
    if (($input['collection_method'] ?? 'send_invoice') === 'send_invoice') { $payload['days_until_due'] = (int)($input['days_until_due'] ?? 7); }
    $sub = stripeRequest($secrets, 'POST', 'subscriptions', $payload);
    if (isset($sub['id'])) { sendJson('success', 'Subscription Created'); } else { sendJson('error', $sub['error']['message'] ?? 'Failed'); }
}

function handleUpdateInvoiceDraft($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $invId = $input['invoice_id'];
    $items = $input['items'];
    $payload = [ 'collection_method' => $input['collection_method'] ?? 'send_invoice', 'description' => $input['memo'] ?? '', 'footer' => $input['footer'] ?? '' ];
    if (($input['collection_method'] ?? 'send_invoice') === 'send_invoice') { $payload['days_until_due'] = (int)($input['days_until_due'] ?? 7); }
    stripeRequest($secrets, 'POST', "invoices/$invId", $payload);
    $lines = stripeRequest($secrets, 'GET', "invoices/$invId/lines?limit=100");
    if (!empty($lines['data'])) { foreach ($lines['data'] as $line) { if (isset($line['id'])) { stripeRequest($secrets, 'DELETE', "invoiceitems/" . $line['invoice_item']); } } }
    if (!empty($items) && is_array($items)) { $inv = stripeRequest($secrets, 'GET', "invoices/$invId"); $sid = $inv['customer']; foreach ($items as $item) { stripeRequest($secrets, 'POST', 'invoiceitems', [ 'customer' => $sid, 'price' => $item['price_id'], 'invoice' => $invId ]); } }
    sendJson('success', 'Draft Updated');
}

function handleGetInvoiceDetails($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $id = $input['invoice_id'];
    $i = stripeRequest($secrets, 'GET', "invoices/$id?expand%5B%5D=lines.data.price.product");
    if(!isset($i['id'])) sendJson('error', 'Invoice not found');
    $lines = [];
    if (!empty($i['lines']['data'])) { foreach($i['lines']['data'] as $l) { $lines[] = [ 'price_id' => $l['price']['id'] ?? '', 'description' => $l['description'], 'amount' => $l['amount'] / 100, 'product_name' => $l['price']['product']['name'] ?? 'Custom Item' ]; } }
    $invObj = [ 'id' => $i['id'], 'number' => $i['number'] ?? 'DRAFT', 'status' => $i['status'], 'customer' => $i['customer'], 'lines' => $lines, 'collection_method' => $i['collection_method'], 'days_until_due' => $i['days_until_due'], 'description' => $i['description'], 'footer' => $i['footer'] ];
    sendJson('success', 'Loaded', ['invoice' => $invObj]);
}

function handleQuoteAction($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $id = $input['quote_id'];
    $act = $input['sub_action'];
    if ($act === 'finalize') { $res = stripeRequest($secrets, 'POST', "quotes/$id/finalize"); } 
    elseif ($act === 'cancel') { $res = stripeRequest($secrets, 'POST', "quotes/$id/cancel"); } 
    elseif ($act === 'accept') { $res = stripeRequest($secrets, 'POST', "quotes/$id/accept"); } 
    else { sendJson('error', 'Unknown Action'); }
    if (isset($res['id'])) sendJson('success', 'Action Successful');
    else sendJson('error', $res['error']['message'] ?? 'Action Failed');
}

function handleInvoiceAction($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $id = $input['invoice_id'];
    $act = $input['sub_action'];
    if ($act === 'void') { $res = stripeRequest($secrets, 'POST', "invoices/$id/void"); } 
    elseif ($act === 'delete') { $res = stripeRequest($secrets, 'DELETE', "invoices/$id"); } 
    elseif ($act === 'finalize') { $res = stripeRequest($secrets, 'POST', "invoices/$id/finalize"); } 
    else { sendJson('error', 'Unknown Action'); }
    if (isset($res['id']) || isset($res['deleted'])) sendJson('success', ucfirst($act) . ' Successful');
    else sendJson('error', $res['error']['message'] ?? 'Action Failed');
}

function handleSubscriptionAction($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $id = $input['subscription_id'];
    $act = $input['sub_action']; 
    if ($act === 'cancel') {
        $res = stripeRequest($secrets, 'DELETE', "subscriptions/$id");
        if (isset($res['status']) && $res['status'] === 'canceled') sendJson('success', 'Subscription Canceled');
        else sendJson('error', $res['error']['message'] ?? 'Cancel Failed');
    }
}

// Products
function handleCreateProduct($pdo, $input, $secrets) { 
    $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); 
    $prod = stripeRequest($secrets, 'POST', 'products', ['name'=>strip_tags($input['name']),'description'=>strip_tags($input['description']),'metadata'=>['Category'=>strip_tags($input['category']??'General')]]); 
    if(isset($prod['id'])){ 
        $d=['product'=>$prod['id'],'unit_amount'=>(float)$input['amount']*100,'currency'=>'aud']; 
        if($input['interval']!=='one-time')$d['recurring']=['interval'=>$input['interval']]; 
        stripeRequest($secrets,'POST','prices',$d); 
        sendJson('success','Created'); 
    } 
    else sendJson('error',$prod['error']['message']??'Error'); 
}
function handleUpdateProduct($pdo, $i, $s) { verifyAuth($i); stripeRequest($s, 'POST', "products/{$i['product_id']}", ['name'=>$i['name'], 'description'=>$i['description'], 'metadata'=>['Category'=>$i['category']]]); sendJson('success', 'Updated'); }
function handleDeleteProduct($pdo, $i, $s) { verifyAuth($i); stripeRequest($s, 'POST', "products/{$i['product_id']}", ['active'=>'false']); sendJson('success', 'Deleted'); }
function handleToggleProductVisibility($pdo, $i, $s) { verifyAuth($i); stripeRequest($s, 'POST', "products/{$i['product_id']}", ['metadata'=>['portal_hidden'=>$i['hidden']?'true':'false']]); sendJson('success', 'Updated'); }
function handleCreateCoupon($pdo, $i, $s) { verifyAuth($i); stripeRequest($s, 'POST', 'coupons', ['percent_off'=>$i['percent_off'], 'duration'=>$i['duration'], 'name'=>$i['code'], 'id'=>$i['code']]); sendJson('success', 'Created'); }
function handleCreateCheckout($pdo, $i, $s) { 
    $u=verifyAuth($i); $sid=getOrSyncStripeId($pdo, $u['uid'], $s);
    $mode = ($i['interval'] === 'one-time') ? 'payment' : 'subscription';
    $r = stripeRequest($s, 'POST', 'checkout/sessions', ['customer'=>$sid, 'line_items'=>[['price'=>$i['price_id'], 'quantity'=>1]], 'mode'=>$mode, 'success_url'=>'https://'.$_SERVER['HTTP_HOST'].'/portal/?status=success', 'cancel_url'=>'https://'.$_SERVER['HTTP_HOST'].'/portal/?status=cancel']);
    if(isset($r['url'])) sendJson('success', 'Link', ['url'=>$r['url']]); else sendJson('error', 'Failed');
}
function handleSaveServiceOrder($pdo, $input) { 
    $user = verifyAuth($input); if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized'); 
    $pdo->exec("CREATE TABLE IF NOT EXISTS sort_orders (obj_key VARCHAR(255) PRIMARY KEY, sort_index INT)");
    $pdo->beginTransaction(); 
    $stmt = $pdo->prepare("REPLACE INTO sort_orders (obj_key, sort_index) VALUES (?, ?)"); 
    foreach ($input['items'] as $item) { $stmt->execute([$item['key'], $item['index']]); } 
    $pdo->commit(); sendJson('success', 'Order Saved'); 
}
function handleStripePortal($pdo,$i,$s){$u=verifyAuth($i);$sid=getOrSyncStripeId($pdo,$u['uid'],$s);$r=stripeRequest($s,'POST','billing_portal/sessions',['customer'=>$sid,'return_url'=>'https://'.$_SERVER['HTTP_HOST'].'/portal/']);if(isset($r['url']))sendJson('success','Link',['url'=>$r['url']]);else sendJson('error',$r['error']['message']);}

function handleRefund($pdo, $input, $secrets) {
    $user = verifyAuth($input);
    if ($user['role'] !== 'admin') sendJson('error', 'Unauthorized');
    $chargeId = $input['charge_id'] ?? null;
    if (!$chargeId) sendJson('error', 'Charge ID required');
    $payload = ['charge' => $chargeId];
    if (!empty($input['amount'])) $payload['amount'] = (int)($input['amount'] * 100);
    $res = stripeRequest($secrets, 'POST', 'refunds', $payload);
    if (isset($res['id'])) {
        if(function_exists('logSystemEvent')) logSystemEvent($pdo, "Refund Processed: \$" . ($input['amount'] ?? 'full') . " for charge {$chargeId}", 'success');
        sendJson('success', 'Refund Processed', ['refund_id' => $res['id']]);
    } else {
        sendJson('error', $res['error']['message'] ?? 'Refund Failed');
    }
}
?>