<?php
/**
 * Password-protected download of the questionnaire spreadsheet.
 * Visit https://oneinmarriage.com/responses.php and enter the password.
 */
$PASSWORD = 'CHANGE_THIS_PASSWORD';          // <-- set this before uploading
$csv = dirname(__DIR__) . '/questionnaire-responses.csv';

$given = $_POST['pw'] ?? '';
$ok = ($given !== '' && hash_equals($PASSWORD, $given));

if ($ok && isset($_POST['download'])) {
    if (!file_exists($csv)) { exit('No responses yet.'); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="one-in-marriage-responses.csv"');
    readfile($csv);
    exit;
}

$count = file_exists($csv) ? max(0, count(file($csv)) - 1) : 0;
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Questionnaire responses</title>
<style>
 body{margin:0;min-height:100vh;display:grid;place-items:center;background:#faf6ee;color:#2b2c26;font-family:system-ui,Arial,sans-serif}
 .card{width:min(420px,92vw);background:#fffdf8;border:1px solid #e7e0d1;border-radius:22px;padding:34px}
 h1{margin:0 0 6px;font-size:1.3rem}
 p{color:#5f635b;font-size:.95rem}
 input,button{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #e7e0d1;font:inherit;margin-top:10px}
 button{background:#7d9068;color:#fff;border:0;font-weight:600;cursor:pointer}
 .pwrow{display:flex;gap:8px;align-items:center}
 .pwrow input{margin-top:10px}
 .reveal{width:auto;min-height:44px;margin-top:10px;padding:0 16px;border:1px solid #e7e0d1;border-radius:12px;background:#fff;color:#5f7350;font:inherit;font-weight:600;cursor:pointer}
 .bad{color:#9c4a34;font-size:.9rem}
</style></head><body>
<div class="card">
  <h1>Questionnaire responses</h1>
  <p><?php echo $count; ?> submission<?php echo $count === 1 ? '' : 's'; ?> so far. Opens in Excel or Google Sheets.</p>
  <?php if ($given !== '' && !$ok) { echo '<p class="bad">Wrong password.</p>'; } ?>
  <form method="POST">
    <div class="pwrow">
      <input type="password" name="pw" id="pw" placeholder="Password" autofocus>
      <button type="button" class="reveal" id="revealBtn" aria-label="Show password">Show</button>
    </div>
    <button type="submit" name="download" value="1">Download spreadsheet</button>
  </form>
  <script>
    var pw = document.getElementById('pw'), rb = document.getElementById('revealBtn');
    rb.addEventListener('click', function () {
      var showing = pw.type === 'text';
      pw.type = showing ? 'password' : 'text';
      rb.textContent = showing ? 'Show' : 'Hide';
      rb.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      pw.focus();
    });
  </script>
</div>
</body></html>
