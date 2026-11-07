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

$required = array('course','first_name','last_name','email','partner_first_name','partner_last_name','partner_email','date','partner');
foreach ($required as $r) {
    if (empty(trim((string)($_POST[$r] ?? '')))) {
        http_response_code(400);
        $msg = 'Please go back and complete the name, email and date fields.';
        if (ob_get_level()) { ob_end_clean(); }
        if ($isAjax) { header('Content-Type: application/json'); exit(json_encode(array('ok' => false, 'error' => $msg, 'kept' => (bool)$ref))); }
        exit($msg);
    }
}

require __DIR__ . '/survey-files.php';

// Lives one level above public_html so it can never be downloaded directly.
$csv = dirname(__DIR__) . '/questionnaire-responses.csv';

$_POST['submitted_at'] = $takenAt;

$row = array('submitted_at' => $takenAt);
$answered = 0;
foreach ($_POST as $k => $v) {
    if ($k === 'submitted_at') { continue; }                // already the first column
    if ($k === 'company') { continue; }
    if (substr($k, -5) === '_item') { continue; }           // hidden labels from the yes/no/maybe grid
    $val = is_array($v) ? implode(' | ', $v) : trim((string)$v);
    $row[$k] = $val;
    if ($k[0] === 'q' && $val !== '') { $answered++; }
}

// ---- 2. write the spreadsheet, and know whether it actually wrote ----
$wrote = false;
$fh = @fopen($csv, 'a+');
if ($fh) {
    if (@flock($fh, LOCK_EX)) {
        clearstatcache(true, $csv);                         // filesize() is cached
        if (filesize($csv) === 0) { fputcsv($fh, array_keys($row), ',', '"', ''); }
        $wrote = (fputcsv($fh, array_values($row), ',', '"', '') !== false);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($csv, 0600);
}

// ---- 3. never report success for answers we did not keep ----
if (!$wrote) { sq_trouble($ref, $isAjax); }

// readable files for the team: one per person, one per couple
sf_save($_POST);

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
        . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">We have your 90-Day Game Profile Survey &mdash; <strong>' . (int)$answered . ' answers</strong>, received on ' . esc($when) . '.</td></tr>'
        . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">There is nothing else to do. If you did not get this email within a few minutes of finishing, your answers did not reach us &mdash; please tell us rather than filling it in again.</td></tr>'
        . '<tr><td style="padding:14px 28px 6px;color:#8a8d84;font-size:13px;">Reference: ' . esc((string)$ref) . '</td></tr>'
        . '<tr><td style="background:#f6efe0;padding:16px 28px;color:#8a8d84;font-size:12px;text-align:center;">ONE in Marriage &middot; A Pattern-Aware Marriage Experience</td></tr>';
    @send_email($to, 'We have your survey — ONE in Marriage', branded_email($inner), $to);
}

// ---- done ----
if (ob_get_level()) { ob_end_clean(); }
if ($isAjax) {
    header('Content-Type: application/json');
    exit(json_encode(array('ok' => true, 'name' => $first, 'answered' => $answered, 'when' => $when, 'ref' => $ref)));
}
header('Location: questionnaire-thanks.html');
exit;
