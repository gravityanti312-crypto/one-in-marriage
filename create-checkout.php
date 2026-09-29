<?php
/**
 * ONE in Marriage — starts Stripe Checkout for the $3,000 program.
 * The "Begin the Experience" form posts here. On success, redirects the person to Stripe.
 */
require __DIR__ . '/payment-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!empty($_POST['company'])) { header('Location: ' . $THANK_YOU); exit; } // honeypot

// Collect form fields into Stripe metadata (so we can email them AFTER payment).
$metadata = array();
$customer_email = '';
foreach ($_POST as $k => $v) {
    if ($k === 'company') { continue; }
    if (is_array($v)) { $v = implode(', ', $v); }
    $v = trim($v);
    if ($v === '') { continue; }
    if ($k === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL)) { $customer_email = $v; }
    $metadata[$k] = function_exists('mb_substr') ? mb_substr($v, 0, 480) : substr($v, 0, 480); // Stripe metadata value limit
}

// ---- capacity check: stop selling once November is full ----
$paid = count_paid_registrations($STRIPE_SECRET_KEY);
if ($paid !== null && $paid >= $MAX_COUPLES) {
    header('Location: sold-out.html');
    exit;
}

$base = base_url();
$params = array(
    'mode'        => 'payment',
    'success_url' => $base . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => $base . '/index.html',
    'line_items'  => array(
        array(
            'quantity'   => 1,
            'price_data' => array(
                'currency'     => 'usd',
                'unit_amount'  => $PRICE_CENTS,
                'product_data' => array('name' => $PRODUCT_NAME),
            ),
        ),
    ),
    'metadata' => $metadata,
    'custom_text' => array(
        'submit' => array('message' => $REFUND_POLICY),
    ),
);
if ($customer_email !== '') { $params['customer_email'] = $customer_email; }

$res = stripe_call('POST', 'checkout/sessions', $params, $STRIPE_SECRET_KEY);

if ($res['code'] === 200 && !empty($res['body']['url'])) {
    header('Location: ' . $res['body']['url']);
    exit;
}

http_response_code(500);
$msg = isset($res['body']['error']['message']) ? $res['body']['error']['message'] : 'Unknown error. Check that the Stripe secret key in payment-config.php is correct.';
exit('Sorry — we could not start checkout right now. (' . esc($msg) . ')');
