<?php
// =============================================================================
// Wandering Webmaster Custom Component
// Version: 15.6 - CRITICAL FIX: Function Wrapper (Force Update)
// =============================================================================

// CRASH PREVENTION: Wrap entire file in existence check
if (!function_exists('getOrSyncStripeId')) {

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

    function handleGetInvoices($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $result = stripeRequest($secrets, 'GET', "/invoices?customer=$stripe_id&limit=100&expand[]=data.customer");
        if (!isset($result['data'])) return sendJson('error', 'Failed to fetch invoices', ['error' => $result]);
        
        $invoices = array_map(function($inv) {
            $customer = $inv['customer'] ?? [];
            $client_name = is_array($customer) ? ($customer['name'] ?? 'Client') : 'Client';
            return [
                'id' => $inv['id'],
                'number' => $inv['number'],
                'amount_due' => $inv['amount_due'] / 100,
                'status' => $inv['status'],
                'client_name' => $client_name,
                'created' => $inv['created'],
                'due_date' => $inv['due_date'],
                'paid' => $inv['paid'],
                'url' => $inv['hosted_invoice_url'] ?? ''
            ];
        }, $result['data']);
        
        sendJson('success', 'Invoices fetched', ['invoices' => $invoices]);
    }

    function handleGetQuotes($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $result = stripeRequest($secrets, 'GET', "/quotes?customer=$stripe_id&limit=100&expand[]=data.customer");
        if (!isset($result['data'])) return sendJson('error', 'Failed to fetch quotes', ['error' => $result]);
        
        $quotes = array_map(function($quote) {
            $customer = $quote['customer'] ?? [];
            $client_name = is_array($customer) ? ($customer['name'] ?? 'Client') : 'Client';
            return [
                'id' => $quote['id'],
                'number' => $quote['number'] ?? 'N/A',
                'amount_total' => $quote['amount_total'] / 100,
                'status' => $quote['status'],
                'client_name' => $client_name,
                'created' => $quote['created'],
                'expires_at' => $quote['expires_at'] ?? null,
                'url' => $quote['url'] ?? ''
            ];
        }, $result['data']);
        
        sendJson('success', 'Quotes fetched', ['quotes' => $quotes]);
    }

    function handleGetSubscriptions($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $result = stripeRequest($secrets, 'GET', "/subscriptions?customer=$stripe_id&limit=100&expand[]=data.customer");
        if (!isset($result['data'])) return sendJson('error', 'Failed to fetch subscriptions', ['error' => $result]);
        
        $subs = array_map(function($sub) {
            $customer = $sub['customer'] ?? [];
            $client_name = is_array($customer) ? ($customer['name'] ?? 'Client') : 'Client';
            return [
                'id' => $sub['id'],
                'status' => $sub['status'],
                'client_name' => $client_name,
                'current_period_start' => $sub['current_period_start'],
                'current_period_end' => $sub['current_period_end'],
                'cancel_at_period_end' => $sub['cancel_at_period_end'],
                'items_count' => count($sub['items']['data'] ?? [])
            ];
        }, $result['data']);
        
        sendJson('success', 'Subscriptions fetched', ['subscriptions' => $subs]);
    }

    function handleGetPayouts($pdo, $input, $secrets) {
        $result = stripeRequest($secrets, 'GET', '/payouts?limit=100');
        if (!isset($result['data'])) return sendJson('error', 'Failed to fetch payouts', ['error' => $result]);
        
        $payouts = array_map(function($payout) {
            return [
                'id' => $payout['id'],
                'amount' => $payout['amount'] / 100,
                'status' => $payout['status'],
                'arrival_date' => $payout['arrival_date'],
                'created' => $payout['created'],
                'type' => $payout['type']
            ];
        }, $result['data']);
        
        sendJson('success', 'Payouts fetched', ['payouts' => $payouts]);
    }

    function handleCreateInvoice($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $invoiceData = [
            'customer' => $stripe_id,
            'collection_method' => 'send_invoice',
            'days_until_due' => $input['days_until_due'] ?? 30
        ];
        
        if (!empty($input['items'])) {
            foreach ($input['items'] as $idx => $item) {
                $invoiceData["line_items[$idx][price_data][currency]"] = 'usd';
                $invoiceData["line_items[$idx][price_data][product_data][name]"] = $item['description'];
                $invoiceData["line_items[$idx][price_data][unit_amount]"] = intval($item['amount'] * 100);
                $invoiceData["line_items[$idx][quantity]"] = $item['quantity'] ?? 1;
            }
        }
        
        $result = stripeRequest($secrets, 'POST', '/invoices', $invoiceData);
        
        if (!isset($result['id'])) return sendJson('error', 'Failed to create invoice', ['error' => $result]);
        
        sendJson('success', 'Invoice created', ['invoice' => $result]);
    }

    function handleUpdateInvoice($pdo, $input, $secrets) {
        if (empty($input['invoice_id'])) return sendJson('error', 'Invoice ID required');
        
        $updateData = [];
        if (!empty($input['description'])) $updateData['description'] = $input['description'];
        if (isset($input['due_date'])) $updateData['due_date'] = $input['due_date'];
        
        $result = stripeRequest($secrets, 'POST', "/invoices/{$input['invoice_id']}", $updateData);
        if (!isset($result['id'])) return sendJson('error', 'Failed to update invoice', ['error' => $result]);
        
        sendJson('success', 'Invoice updated', ['invoice' => $result]);
    }

    function handleDeleteInvoice($pdo, $input, $secrets) {
        if (empty($input['invoice_id'])) return sendJson('error', 'Invoice ID required');
        
        $result = stripeRequest($secrets, 'DELETE', "/invoices/{$input['invoice_id']}", []);
        
        if (!isset($result['id']) && !isset($result['deleted'])) {
            return sendJson('error', 'Failed to delete invoice', ['error' => $result]);
        }
        
        sendJson('success', 'Invoice deleted');
    }

    function handleCreateQuote($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $quoteData = [
            'customer' => $stripe_id,
            'expires_at' => $input['expires_at'] ?? (time() + 2592000)
        ];
        
        if (!empty($input['items'])) {
            foreach ($input['items'] as $idx => $item) {
                $quoteData["line_items[$idx][price_data][currency]"] = 'usd';
                $quoteData["line_items[$idx][price_data][product_data][name]"] = $item['description'];
                $quoteData["line_items[$idx][price_data][unit_amount]"] = intval($item['amount'] * 100);
                $quoteData["line_items[$idx][quantity]"] = $item['quantity'] ?? 1;
            }
        }
        
        $result = stripeRequest($secrets, 'POST', '/quotes', $quoteData);
        if (!isset($result['id'])) return sendJson('error', 'Failed to create quote', ['error' => $result]);
        
        sendJson('success', 'Quote created', ['quote' => $result]);
    }

    function handleUpdateQuote($pdo, $input, $secrets) {
        if (empty($input['quote_id'])) return sendJson('error', 'Quote ID required');
        
        $updateData = [];
        if (isset($input['expires_at'])) $updateData['expires_at'] = $input['expires_at'];
        if (!empty($input['description'])) $updateData['description'] = $input['description'];
        
        $result = stripeRequest($secrets, 'POST', "/quotes/{$input['quote_id']}", $updateData);
        if (!isset($result['id'])) return sendJson('error', 'Failed to update quote', ['error' => $result]);
        
        sendJson('success', 'Quote updated', ['quote' => $result]);
    }

    function handleFinalizeQuote($pdo, $input, $secrets) {
        if (empty($input['quote_id'])) return sendJson('error', 'Quote ID required');
        
        $result = stripeRequest($secrets, 'POST', "/quotes/{$input['quote_id']}/finalize", []);
        if (!isset($result['id'])) return sendJson('error', 'Failed to finalize quote', ['error' => $result]);
        
        sendJson('success', 'Quote finalized', ['quote' => $result]);
    }

    function handleDeleteQuote($pdo, $input, $secrets) {
        if (empty($input['quote_id'])) return sendJson('error', 'Quote ID required');
        
        $result = stripeRequest($secrets, 'DELETE', "/quotes/{$input['quote_id']}", []);
        if (!isset($result['id']) && !isset($result['deleted'])) {
            return sendJson('error', 'Failed to delete quote', ['error' => $result]);
        }
        
        sendJson('success', 'Quote deleted');
    }

    function handleCreateSubscription($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $subData = [
            'customer' => $stripe_id,
            'billing_cycle_anchor' => $input['billing_cycle_anchor'] ?? time(),
            'trial_period_days' => $input['trial_period_days'] ?? 0
        ];
        
        if (!empty($input['price_id'])) {
            $subData['items[0][price]'] = $input['price_id'];
        }
        
        $result = stripeRequest($secrets, 'POST', '/subscriptions', $subData);
        if (!isset($result['id'])) return sendJson('error', 'Failed to create subscription', ['error' => $result]);
        
        sendJson('success', 'Subscription created', ['subscription' => $result]);
    }

    function handleUpdateSubscription($pdo, $input, $secrets) {
        if (empty($input['subscription_id'])) return sendJson('error', 'Subscription ID required');
        
        $updateData = [];
        if (isset($input['cancel_at_period_end'])) $updateData['cancel_at_period_end'] = $input['cancel_at_period_end'] ? 'true' : 'false';
        
        $result = stripeRequest($secrets, 'POST', "/subscriptions/{$input['subscription_id']}", $updateData);
        if (!isset($result['id'])) return sendJson('error', 'Failed to update subscription', ['error' => $result]);
        
        sendJson('success', 'Subscription updated', ['subscription' => $result]);
    }

    function handleCancelSubscription($pdo, $input, $secrets) {
        if (empty($input['subscription_id'])) return sendJson('error', 'Subscription ID required');
        
        $result = stripeRequest($secrets, 'DELETE', "/subscriptions/{$input['subscription_id']}", []);
        if (!isset($result['id']) && !isset($result['deleted'])) {
            return sendJson('error', 'Failed to cancel subscription', ['error' => $result]);
        }
        
        sendJson('success', 'Subscription cancelled');
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
                    'amount' => $inv['total'] / 100,
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
                    'amount' => $sub['items']['data'][0]['price']['unit_amount'] / 100 ?? 0,
                    'interval' => $sub['items']['data'][0]['price']['recurring']['interval'] ?? 'one-time',
                    'status' => $sub['status'],
                    'next_bill' => date('Y-m-d', $sub['current_period_end'] ?? time())
                ];
            }
        }
        
        sendJson('success', 'Billing Loaded', ['invoices' => $invoices, 'subscriptions' => $subscriptions, 'data' => ['invoices' => $invoices]]);
    }

    function handleGetBillingFeed($pdo, $input, $secrets) {
        $user = verifyAuth($input);
        $stripe_id = getOrSyncStripeId($pdo, $user['id']);
        if (!$stripe_id) return sendJson('error', 'No Stripe account linked');
        
        $feed = [];
        
        $invoices = stripeRequest($secrets, 'GET', "/invoices?customer=$stripe_id&limit=50&expand[]=data.customer");
        if (isset($invoices['data'])) {
            foreach ($invoices['data'] as $inv) {
                $customer = $inv['customer'] ?? [];
                $client_name = is_array($customer) ? ($customer['name'] ?? 'Client') : 'Client';
                $feed[] = [
                    'type' => 'invoice',
                    'id' => $inv['id'],
                    'title' => 'Invoice #' . $inv['number'],
                    'client_name' => $client_name,
                    'amount' => $inv['amount_due'] / 100,
                    'status' => $inv['status'],
                    'date' => $inv['created']
                ];
            }
        }
        
        $quotes = stripeRequest($secrets, 'GET', "/quotes?customer=$stripe_id&limit=50&expand[]=data.customer");
        if (isset($quotes['data'])) {
            foreach ($quotes['data'] as $q) {
                $customer = $q['customer'] ?? [];
                $client_name = is_array($customer) ? ($customer['name'] ?? 'Client') : 'Client';
                $feed[] = [
                    'type' => 'quote',
                    'id' => $q['id'],
                    'title' => 'Quote #' . ($q['number'] ?? 'N/A'),
                    'client_name' => $client_name,
                    'amount' => $q['amount_total'] / 100,
                    'status' => $q['status'],
                    'date' => $q['created']
                ];
            }
        }
        
        usort($feed, function($a, $b) { return $b['date'] - $a['date']; });
        
        sendJson('success', 'Feed loaded', ['feed' => $feed]);
    }
}
?>
