<?php
/**
 * ONE in Marriage — 90-Day Game Profile Survey handler.
 * Appends each submission as a row to a CSV stored OUTSIDE the public web folder.
 * No email is sent — answers are private and live only in the spreadsheet,
 * downloadable from responses.php.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!empty($_POST['company'])) { header('Location: questionnaire-thanks.html'); exit; }   // honeypot

$required = array('first_name','last_name','email','partner_email','date','partner');
foreach ($required as $r) {
    if (empty(trim((string)($_POST[$r] ?? '')))) {
        http_response_code(400);
        exit('Please go back and complete the name, email and date fields.');
    }
}

// Lives one level above public_html so it can never be downloaded directly.
$csv = dirname(__DIR__) . '/questionnaire-responses.csv';

$row = array('submitted_at' => date('c'));
foreach ($_POST as $k => $v) {
    if ($k === 'company') { continue; }
    if (substr($k, -5) === '_item') { continue; }          // hidden labels from the yes/no/maybe grid
    $row[$k] = is_array($v) ? implode(' | ', $v) : trim((string)$v);
}

$fh = @fopen($csv, 'a+');
if ($fh) {
    if (flock($fh, LOCK_EX)) {
        if (filesize($csv) === 0) { fputcsv($fh, array_keys($row)); }   // header row, first submission only
        fputcsv($fh, array_values($row));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($csv, 0600);
}

header('Location: questionnaire-thanks.html');
exit;
