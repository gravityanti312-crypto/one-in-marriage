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
    notify_also($fields, $session);
    @file_put_contents($flag, date('c'));
}

header('Location: ' . $THANK_YOU);
exit;

/**
 * Copies every new paid registration to the people listed in team-notify.php
 * (for example Brett), on top of the team inbox in payment-config.php.
 * team-notify.php is git-ignored because the repository is public; upload it
 * by hand. If it is missing or empty, nothing extra is sent. A failure here
 * never stops the registrant reaching the thank-you page.
 */
function notify_also($fields, $session) {
    try {
        $ALSO_NOTIFY = array();
        if (is_file(__DIR__ . '/team-notify.php')) { include __DIR__ . '/team-notify.php'; }
        $list = array();
        foreach ((array)$ALSO_NOTIFY as $e) { $e = trim((string)$e); if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $list[] = $e; } }
        if (!$list) { return; }
        $x = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $name = trim((isset($fields['first_name']) ? $fields['first_name'] : '') . ' ' . (isset($fields['last_name']) ? $fields['last_name'] : ''));
        $rows = '';
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) { continue; }
            $label = ucfirst(str_replace('_', ' ', (string)$k));
            $rows .= '<tr><td style="padding:8px 14px 8px 0;color:#7a7d74;font-size:12px;font-weight:bold;text-transform:uppercase;vertical-align:top;white-space:nowrap">' . $x($label) . '</td>'
                   . '<td style="padding:8px 0;color:#2b2c26;font-size:15px">' . nl2br($x($v)) . '</td></tr>';
        }
        $inner = '<h2 style="font-family:Georgia,serif;color:#2b2c26;margin:0 0 8px">New paid registration</h2>'
               . '<p style="color:#5f635b;margin:0 0 18px">' . ($name !== '' ? $x($name) . ' just registered and paid.' : 'Someone just registered and paid.') . '</p>'
               . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
               . '<p style="color:#7a7d74;font-size:13px;margin-top:20px">You get this because you are on the ONE in Marriage registration notification list.</p>';
        $html = function_exists('branded_email') ? branded_email('<tr><td style="padding:26px 28px 16px;">' . $inner . '</td></tr>') : $inner;
        $subject = $name !== '' ? 'New paid registration — ' . $name : 'New paid registration — ONE in Marriage';
        foreach ($list as $to) {
            if (function_exists('send_email')) { @send_email($to, $subject, $html, 'jacques@sharingvitality.com'); }
            else { @mail($to, $subject, $html, "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"); }
        }
    } catch (Throwable $e) { /* never block the thank-you page */ }
}
