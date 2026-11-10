<?php
/**
 * ONE in Marriage — 90-Day Game Profile Survey handler.
 *
 * Marc Garrett filled this in on 5 November 2026, saw the thank-you page, and
 * nothing was stored. Nobody found out until Jacques noticed a missing name,
 * by which time his answers were gone from his browser too. Four separate
 * paths could do that and all four ended on the thank-you page.
 *
 * The order here is deliberate:
 *   1. drop a raw copy on disk before anything else, so the answers survive
 *      even if the rest of this file falls over
 *   2. only then validate, write the spreadsheet, build the team's files
 *   3. never report success for answers that were not kept
 *
 * The page posts this by fetch and waits for the verdict before clearing the
 * answers it has saved in the browser, so a failure costs a retry rather than
 * thirty-six questions. A plain form post still works if JavaScript is off.
 */

date_default_timezone_set('America/Denver');
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }

$base    = dirname(__DIR__);
$inbox   = $base . '/survey-inbox';
$takenAt = date('c');
$isAjax  = !empty($_SERVER['HTTP_X_OIM_AJAX']);

/**
 * The safety net. Written before any validation, locking or includes. If
 * everything after this fails the answers are still here and can be replayed
 * by hand. Returns the reference so the person can quote it.
 */
function sq_keep_raw($inbox, $post, $takenAt, $note = '') {
    if (!is_dir($inbox)) { @mkdir($inbox, 0700, true); }
    $who  = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($post['email'] ?? 'unknown')));
    $ref  = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4)
          . '-' . substr($who, 0, 40) . ($note ? '-' . $note : '');
    $path = $inbox . '/' . $ref . '.json';
    $ok = @file_put_contents($path, json_encode(
        array('submitted_at' => $takenAt, 'note' => $note, 'post' => $post),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    if ($ok !== false) { @chmod($path, 0600); return $ref; }
    return false;
}

/** Answers did not get filed. Say so; never show a thank-you for them. */
function sq_trouble($kept, $isAjax) {
    http_response_code(500);
    if (ob_get_level()) { ob_end_clean(); }
    if ($isAjax) {
        header('Content-Type: application/json');
        exit(json_encode(array('ok' => false, 'kept' => (bool)$kept, 'ref' => $kept ?: null)));
    }
    $safe = $kept
        ? '<p><strong>Your answers were saved.</strong> Something went wrong filing them, so the team will pick them up by hand. You do not need to fill this in again.</p>'
        : '<p><strong>Your answers were not saved.</strong> Please try once more, and if it happens again let us know rather than filling it in a third time.</p>';
    exit('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
       . '<style>body{margin:0;background:#faf6ee;color:#2b2c26;font-family:system-ui,-apple-system,sans-serif;line-height:1.6}'
       . '.w{width:min(560px,calc(100% - 36px));margin:60px auto}'
       . '.c{padding:26px;border:1px solid #e7e0d1;border-radius:16px;background:#fffdf8}'
       . 'h1{font-size:1.4rem;margin:0 0 10px}a{color:#5f7350}</style>'
       . '<div class="w"><div class="c"><h1>Something went wrong at our end</h1>' . $safe
       . '<p style="margin-bottom:0"><a href="index.html">Back to ONE in Marriage</a></p></div></div></html>');
}

// ---- 1. keep the raw copy first, whatever else happens ----
$ref = sq_keep_raw($inbox, $_POST, $takenAt);

// If anything below dies outright (9 Nov 2026: an old survey-files.php on the
// server lacked sf_partner_letter()), PHP sends a bare 500 and the page told
// Jacques to "press the button again" four times while every copy sat safely
// in the inbox. Turn any fatal into the same honest answer as sq_trouble().
// Once the spreadsheet row is written the answers are filed, so a crash in the
// per-person files or the receipt email still counts as received.
$GLOBALS['sq_filed'] = false;
register_shutdown_function(function () use ($ref, $isAjax) {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { return; }
    @error_log('submit-questionnaire fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    while (ob_get_level()) { ob_end_clean(); }
    if (!$GLOBALS['sq_filed']) { sq_trouble($ref, $isAjax); }
    if ($isAjax) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => true, 'ref' => $ref));
    } else {
        http_response_code(303);
        header('Location: questionnaire-thanks.html');
    }
});

// Honeypot. A real person never fills this in, but a password manager might,
// and that used to bin a genuine submission without a word. The copy above is
// already on disk, so anyone caught by it can be recovered.
if (!empty($_POST['company'])) {
    sq_keep_raw($inbox, $_POST, $takenAt, 'flagged-spam');
    if (ob_get_level()) { ob_end_clean(); }
    if ($isAjax) { header('Content-Type: application/json'); exit(json_encode(array('ok' => true, 'quiet' => true))); }
    header('Location: questionnaire-thanks.html');
    exit;
}

/** The person needs to fix something on page 1. Their answers stay in the browser. */
function sq_fix_first($msg, $ref, $isAjax) {
    // They are told to fix it and send again, so this copy is not a lost
    // submission: keep it out of the team's "did not get filed" list.
    global $inbox;
    if ($ref && is_file($inbox . '/' . $ref . '.json')) {
        @mkdir($inbox . '/sent-back', 0700, true);
        @rename($inbox . '/' . $ref . '.json', $inbox . '/sent-back/' . $ref . '.json');
    }
    http_response_code(400);
    if (ob_get_level()) { ob_end_clean(); }
    if ($isAjax) { header('Content-Type: application/json'); exit(json_encode(array('ok' => false, 'error' => $msg, 'kept' => (bool)$ref))); }
    exit($msg);
}

$required = array('course','first_name','last_name','email','partner_first_name','partner_last_name','partner_email','date');
foreach ($required as $r) {
    if (empty(trim((string)($_POST[$r] ?? '')))) {
        sq_fix_first('Please go back to page 1 and complete the name, email and date fields.', $ref, $isAjax);
    }
}

// Couples are matched on these, so a typo here is what split Jacques and
// Marilyn. The page checks the same things; this is for anyone on an old copy
// of the page (those do not send email_confirm, so that check is skipped).
$email = strtolower(trim((string)$_POST['email']));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sq_fix_first('Your email address on page 1 does not look complete. Please check it.', $ref, $isAjax);
}
if (isset($_POST['email_confirm']) && strtolower(trim((string)$_POST['email_confirm'])) !== $email) {
    sq_fix_first('The two email addresses you typed on page 1 do not match. Please check them.', $ref, $isAjax);
}
if (!filter_var(trim((string)$_POST['partner_email']), FILTER_VALIDATE_EMAIL)) {
    sq_fix_first("Your partner's email address on page 1 does not look complete. Please check it.", $ref, $isAjax);
}

require __DIR__ . '/survey-files.php';

// ---- 2. file it in the survey records, and know whether that worked ----
// The same routine the "File it" button on responses.php uses, so a submission
// filed by hand from the inbox ends up exactly like one that went through first time.
$post   = $_POST;
$lock   = sf_lock();
$store  = sf_open_store();
$filed  = is_array($store) ? sf_add($store, $post, $takenAt, 'survey page') : array('ok' => false);
$saved  = $filed['ok'] && ($filed['dupe'] || sf_store_save($store));

// ---- 3. never report success for answers we did not keep ----
if (!$saved) { sf_unlock($lock); sq_trouble($ref, $isAjax); }
$GLOBALS['sq_filed'] = true;

// readable files and the spreadsheet for the team, rebuilt from the records
$match   = sf_rebuild($store);
$partner = sf_partner_status($store, $match, $filed['entry']);
$program = sf_program($post['course'] ?? '');
sf_unlock($lock);
$answered = sf_answered(sf_tidy_post($post));

// ---- 4. tell the person it arrived, so silence means something ----
$first = trim((string)$_POST['first_name']);
$to    = trim((string)$_POST['email']);
$when  = date('j F Y, g:ia', strtotime($takenAt));

if (is_file(__DIR__ . '/payment-config.php')) {
    require_once __DIR__ . '/payment-config.php';
}
if (function_exists('send_email') && function_exists('branded_email') && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $inner =
          '<tr><td style="padding:28px 28px 6px;">'
        . '<div style="color:#a9824f;font-size:12px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase;">Survey Received</div>'
        . '<div style="font-family:Georgia,serif;font-size:26px;color:#2b2c26;margin-top:8px;">Thank you, ' . esc($first) . '.</div>'
        . '</td></tr>'
        . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">We have your 90-Day Game Profile Survey &mdash; <strong>' . (int)$answered . ' answers</strong>, received on ' . esc($when) . ', for the <strong>' . esc($program) . '</strong> program.</td></tr>'
        . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">'
        . ($partner['paired']
            ? 'You are matched with <strong>' . esc($partner['name']) . '</strong> &mdash; both of your answers are in.'
            : 'We will match you with <strong>' . esc($partner['name'] ?: 'your partner') . '</strong> as soon as they finish theirs.')
        . '</td></tr>'
        . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">There is nothing else to do. If you did not get this email within a few minutes of finishing, your answers did not reach us &mdash; please tell us rather than filling it in again.</td></tr>'
        . '<tr><td style="padding:14px 28px 6px;color:#8a8d84;font-size:13px;">Reference: ' . esc((string)$ref) . '</td></tr>'
        . '<tr><td style="background:#f6efe0;padding:16px 28px;color:#8a8d84;font-size:12px;text-align:center;">ONE in Marriage &middot; A Pattern-Aware Marriage Experience</td></tr>';
    $emailed = (@send_email($to, 'We have your survey — ONE in Marriage', branded_email($inner), $to) !== false);
}

// ---- done ----
if (ob_get_level()) { ob_end_clean(); }
if ($isAjax) {
    header('Content-Type: application/json');
    exit(json_encode(array(
        'ok' => true, 'name' => $first, 'answered' => $answered, 'when' => $when, 'ref' => $ref,
        'program' => $program, 'partner' => $partner, 'email' => $to, 'emailed' => !empty($emailed),
    )));
}
header('Location: questionnaire-thanks.html');
exit;
