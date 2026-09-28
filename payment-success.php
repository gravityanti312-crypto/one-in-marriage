<?php
/**
 * ONE in Marriage — Stripe success handler.
 * Stripe redirects here after checkout. We verify payment, THEN send the emails.
 */
require __DIR__ . '/payment-config.php';

$sid = isset($_GET['session_id']) ? $_GET['session_id'] : '';
if ($sid === '') { header('Location: index.html'); exit; }

// Verify the payment really happened by asking Stripe about the session.
$res = stripe_call('GET', 'checkout/sessions/' . urlencode($sid), array(), $STRIPE_SECRET_KEY);
$session = isset($res['body']) ? $res['body'] : null;

if (!$session || !isset($session['payment_status']) || $session['payment_status'] !== 'paid') {
    // Not paid (or bad session) — send them back to the site. No emails.
    header('Location: index.html');
    exit;
}

// Only send the emails once per paid session (guards against page refresh).
$flagDir = __DIR__ . '/.paid';
if (!is_dir($flagDir)) { @mkdir($flagDir, 0700); }
$flag = $flagDir . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $sid) . '.done';

if (!file_exists($flag)) {
    $fields = (isset($session['metadata']) && is_array($session['metadata'])) ? $session['metadata'] : array();
    send_quote_emails($fields, $session);
    @file_put_contents($flag, date('c'));
}

header('Location: ' . $THANK_YOU);
exit;
