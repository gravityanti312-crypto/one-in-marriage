<?php
/**
 * ONE in Marriage — 90-Day Game Profile Survey handler.
 *
 * Marc Garrett filled this in on 5 November 2026, saw the thank-you page, and
 * nothing was stored. There were four ways that could happen and every one of
 * them ended on the thank-you page, so nobody found out until Jacques noticed
 * a name missing. The order below is deliberate:
 *
 *   1. drop a raw copy of the submission on disk before doing anything else,
 *      so the answers survive even if the rest of this file falls over
 *   2. only then validate, write the spreadsheet and build the team's files
 *   3. if the spreadsheet write does not happen, say so — never show someone
 *      a thank-you page for answers that were not kept
 *
 * Everything lives above public_html so none of it is reachable from the web.
 */

date_default_timezone_set('America/Denver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }

$base   = dirname(__DIR__);
$inbox  = $base . '/survey-inbox';
$takenAt = date('c');

/**
 * The safety net. Written before any validation, any locking, any includes.
 * If everything after this fails, the answers are still here and can be
 * replayed by hand. Costs one small file per submission.
 */
function sq_keep_raw($inbox, $post, $takenAt, $note = '') {
    if (!is_dir($inbox)) { @mkdir($inbox, 0700, true); }
    $who  = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($post['email'] ?? 'unknown')));
    $name = date('Ymd-His') . '-' . substr($who, 0, 40) . ($note ? '-' . $note : '') . '.json';
    $path = $inbox . '/' . $name;
    $ok = @file_put_contents($path, json_encode(
        array('submitted_at' => $takenAt, 'note' => $note, 'post' => $post),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    if ($ok !== false) { @chmod($path, 0600); }
    return $ok !== false;
}

/** Shown instead of the thank-you page when the answers did not get filed. */
function sq_trouble($kept) {
    http_response_code(500);
    $safe = $kept
        ? '<p><strong>Your answers were saved.</strong> Something went wrong filing them, so the team will pick them up by hand. You do not need to fill this in again.</p>'
        : '<p><strong>Your answers were not saved.</strong> Please try once more, and if it happens again let us know rather than filling it in a third time.</p>';
    exit('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
       . '<style>body{margin:0;background:#faf6ee;color:#2b2c26;font-family:system-ui,-apple-system,sans-serif;line-height:1.6}'
       . '.w{width:min(560px,calc(100% - 36px));margin:60px auto}'
       . '.c{padding:26px;border:1px solid #e7e0d1;border-radius:16px;background:#fffdf8}'
       . 'h1{font-size:1.4rem;margin:0 0 10px}a{color:#5f7350}</style>'
       . '<div class="w"><div class="c"><h1>Something went wrong at our end</h1>'
       . $safe
       . '<p style="margin-bottom:0"><a href="index.html">Back to ONE in Marriage</a></p>'
       . '</div></div></html>');
}

// ---- 1. keep the raw copy first, whatever else happens ----
$kept = sq_keep_raw($inbox, $_POST, $takenAt);

// Honeypot. A real person never fills this in, but a password manager might,
// and that used to bin a genuine submission without a word. The copy above is
// already on disk and flagged, so a real person caught by it is recoverable.
if (!empty($_POST['company'])) {
    sq_keep_raw($inbox, $_POST, $takenAt, 'flagged-spam');
    header('Location: questionnaire-thanks.html');
    exit;
}

$required = array('course','first_name','last_name','email','partner_first_name','partner_last_name','partner_email','date','partner');
foreach ($required as $r) {
    if (empty(trim((string)($_POST[$r] ?? '')))) {
        http_response_code(400);
        exit('Please go back and complete the name, email and date fields.');
    }
}

require __DIR__ . '/survey-files.php';

// Lives one level above public_html so it can never be downloaded directly.
$csv = dirname(__DIR__) . '/questionnaire-responses.csv';

$_POST['submitted_at'] = $takenAt;

$row = array('submitted_at' => $takenAt);
foreach ($_POST as $k => $v) {
    if ($k === 'submitted_at') { continue; }                // already the first column
    if ($k === 'company') { continue; }
    if (substr($k, -5) === '_item') { continue; }          // hidden labels from the yes/no/maybe grid
    $row[$k] = is_array($v) ? implode(' | ', $v) : trim((string)$v);
}

// ---- 2. write the spreadsheet, and know whether it actually happened ----
$wrote = false;
$fh = @fopen($csv, 'a+');
if ($fh) {
    if (@flock($fh, LOCK_EX)) {
        clearstatcache(true, $csv);                        // filesize() is cached
        if (filesize($csv) === 0) { fputcsv($fh, array_keys($row)); }
        $wrote = (fputcsv($fh, array_values($row)) !== false);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($csv, 0600);
}

// ---- 3. never thank someone for answers we did not keep ----
if (!$wrote) { sq_trouble($kept); }

// readable files for the team: one per person, one per couple
sf_save($_POST);

header('Location: questionnaire-thanks.html');
exit;
