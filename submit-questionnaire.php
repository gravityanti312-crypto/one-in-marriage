<?php
/**
 * ONE in Marriage — 90-Day Challenge questionnaire handler.
 * Appends each submission as a row to a CSV stored OUTSIDE the public web folder,
 * then emails the team that a new one arrived. Answers themselves are not emailed.
 */
require __DIR__ . '/payment-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!empty($_POST['company'])) { header('Location: questionnaire-thanks.html'); exit; }   // honeypot

$required = array('first_name','last_name','email','partner_email','date','partner');
foreach ($required as $r) {
    if (empty(trim((string)($_POST[$r] ?? '')))) { http_response_code(400); exit('Please go back and complete the name, email and date fields.'); }
}

// CSV lives one level above public_html so it can never be downloaded directly.
$csv = dirname(__DIR__) . '/questionnaire-responses.csv';

$row = array('submitted_at' => date('c'));
foreach ($_POST as $k => $v) {
    if ($k === 'company') { continue; }
    if (substr($k, -5) === '_item') { continue; }          // hidden labels for the yes/no/maybe grid
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

// Tell the team, without putting private answers in an email.
$name = trim($row['first_name'] . ' ' . $row['last_name']);
$inner = '<tr><td style="padding:26px 28px 6px;">'
       . '<div style="color:#a9824f;font-size:12px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase;">90-Day Challenge</div>'
       . '<div style="font-family:Georgia,serif;font-size:25px;color:#2b2c26;margin-top:7px;">' . esc($name) . ' completed the questions</div>'
       . '</td></tr>'
       . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.6;">'
       . 'Partner <strong>' . esc($row['partner']) . '</strong> &middot; ' . esc($row['email']) . '<br>'
       . 'Partner&rsquo;s email: ' . esc($row['partner_email'])
       . '</td></tr>'
       . '<tr><td style="padding:14px 28px 26px;color:#8a8d84;font-size:13px;line-height:1.6;">'
       . 'The answers themselves are private and are not included in this email. Download the full spreadsheet from the responses page.'
       . '</td></tr>';
send_email($RECIPIENT, 'Questionnaire completed — ' . $name, branded_email($inner), $row['email']);

header('Location: questionnaire-thanks.html');
exit;
