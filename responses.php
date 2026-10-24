<?php
/**
 * ONE in Marriage — everything the team needs from the survey.
 *   - the spreadsheet of every answer
 *   - one readable file per couple (with the Section 7 mutual matches)
 *   - one readable file per person
 * Nothing here is reachable without the password, and the files themselves
 * live above public_html.
 */
$PASSWORD = 'CHANGE_THIS_PASSWORD';          // <-- set this before uploading

$csv      = dirname(__DIR__) . '/questionnaire-responses.csv';
$coupleD  = dirname(__DIR__) . '/survey-data/couples';
$peopleD  = dirname(__DIR__) . '/survey-data/people';

$pw = $_POST['pw'] ?? '';
$ok = ($pw !== '' && hash_equals($PASSWORD, $pw));

function listing($dir) {
    if (!is_dir($dir)) { return []; }
    $out = [];
    foreach (glob($dir . '/*.md') as $f) {
        $out[] = ['file' => basename($f), 'title' => trim(ltrim(strtok(file_get_contents($f), "\n"), '# ')), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

if ($ok && isset($_POST['download'])) {
    if (!file_exists($csv)) { exit('No responses yet.'); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="one-in-marriage-responses.csv"');
    readfile($csv); exit;
}
if ($ok && !empty($_POST['file'])) {
    $which = ($_POST['kind'] ?? '') === 'people' ? $peopleD : $coupleD;
    $path  = $which . '/' . basename((string)$_POST['file']);
    if (is_file($path)) {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path); exit;
    }
}
if ($ok && isset($_POST['zip'])) {
    $zipPath = tempnam(sys_get_temp_dir(), 'oim') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
        foreach (['couples' => $coupleD, 'people' => $peopleD] as $label => $dir) {
            foreach (glob($dir . '/*.md') as $f) { $zip->addFile($f, $label . '/' . basename($f)); }
        }
        if (file_exists($csv)) { $zip->addFile($csv, 'all-answers.csv'); }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="one-in-marriage-survey.zip"');
        readfile($zipPath); @unlink($zipPath); exit;
    }
}

$count   = file_exists($csv) ? max(0, count(file($csv)) - 1) : 0;
$couples = $ok ? listing($coupleD) : [];
$people  = $ok ? listing($peopleD) : [];
$done    = array_filter($couples, fn($c) => str_contains(@file_get_contents($coupleD . '/' . $c['file']), 'both partners have completed'));
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Survey responses &mdash; ONE in Marriage</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@500;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
 :root{--cream:#faf6ee;--paper:#fffdf8;--ink:#2b2c26;--body:#5f635b;--muted:#7a7d74;--sage:#7d9068;--sage-deep:#5f7350;--sage-soft:#e9efe1;--tan:#a9824f;--line:#e7e0d1}
 *{box-sizing:border-box}
 body{margin:0;background:var(--cream);color:var(--ink);font-family:'Outfit',system-ui,Arial,sans-serif;line-height:1.6}
 .wrap{width:min(660px,calc(100% - 36px));margin-inline:auto;padding:46px 0 70px}
 .eyebrow{margin:0 0 10px;color:var(--tan);font-size:.72rem;font-weight:700;letter-spacing:.19em;text-transform:uppercase}
 h1{margin:0 0 8px;font-family:'Lora',Georgia,serif;font-weight:600;font-size:2rem}
 h2{margin:0 0 10px;font-family:'Lora',Georgia,serif;font-weight:600;font-size:1.2rem}
 p{color:var(--body)}
 .card{background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:22px;margin-top:18px}
 .pwrow{display:flex;gap:8px;align-items:center;margin-bottom:12px}
 input[type=password]{flex:1;padding:12px 14px;border:1px solid var(--line);border-radius:12px;font:inherit}
 button{min-height:48px;padding:0 22px;border:0;border-radius:999px;background:var(--sage);color:#fff;font:inherit;font-weight:600;cursor:pointer}
 button:hover{background:var(--sage-deep)}
 .reveal,.ghost{background:#fff;border:1px solid var(--line);color:var(--sage-deep)}
 .reveal:hover,.ghost:hover{background:var(--sage-soft);border-color:var(--sage)}
 .row{display:flex;align-items:center;gap:12px;padding:11px 14px;border:1px solid var(--line);border-radius:12px;background:#fff;margin-bottom:8px}
 .row b{flex:1;min-width:0;font-size:.95rem;font-weight:500}
 .row button{min-height:38px;padding:0 15px;font-size:.85rem}
 .tag{font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 9px;border-radius:999px;background:var(--sage-soft);color:var(--sage-deep)}
 .tag.wait{background:#f6efe0;color:#8a6c3d}
 .stat{display:flex;gap:22px;flex-wrap:wrap;margin:0 0 4px}
 .stat b{display:block;font-size:1.6rem;font-family:'Lora',Georgia,serif}
 .stat span{font-size:.82rem;color:var(--muted)}
 .bad{color:#9c4a34;font-size:.9rem}
</style></head><body>
<div class="wrap">
  <div class="eyebrow">Team only</div>
  <h1>Survey responses</h1>

<?php if (!$ok): ?>
  <div class="card">
    <?php if ($pw !== ''): ?><p class="bad">Wrong password.</p><?php endif; ?>
    <form method="POST">
      <div class="pwrow">
        <input type="password" name="pw" id="pw" placeholder="Password" autofocus>
        <button type="button" class="reveal" id="revealBtn">Show</button>
      </div>
      <button type="submit">Open</button>
    </form>
  </div>
  <script>
    var pw=document.getElementById('pw'),rb=document.getElementById('revealBtn');
    rb.addEventListener('click',function(){var s=pw.type==='text';pw.type=s?'password':'text';rb.textContent=s?'Show':'Hide';pw.focus();});
  </script>
<?php else: ?>

  <div class="card">
    <div class="stat">
      <div><b><?php echo $count; ?></b><span>people finished</span></div>
      <div><b><?php echo count($done); ?></b><span>couples complete</span></div>
      <div><b><?php echo max(0, count($couples) - count($done)); ?></b><span>waiting on a partner</span></div>
    </div>
    <form method="POST" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="pw" value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>">
      <button type="submit" name="download" value="1">Download spreadsheet</button>
      <button type="submit" name="zip" value="1" class="ghost">Download everything (.zip)</button>
    </form>
  </div>

  <div class="card">
    <h2>By couple</h2>
    <p style="margin-top:0;font-size:.9rem">Both partners in one file, with the Section 7 mutual matches worked out.</p>
    <?php if (!$couples): ?><p>Nothing yet.</p><?php endif; ?>
    <?php foreach ($couples as $c):
      $complete = str_contains(@file_get_contents($coupleD . '/' . $c['file']), 'both partners have completed'); ?>
      <form method="POST" class="row">
        <input type="hidden" name="pw" value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>">
        <input type="hidden" name="kind" value="couples">
        <b><?php echo htmlspecialchars(str_replace(' — Couple Profile', '', $c['title']), ENT_QUOTES); ?></b>
        <span class="tag <?php echo $complete ? '' : 'wait'; ?>"><?php echo $complete ? 'complete' : 'waiting'; ?></span>
        <button type="submit" name="file" value="<?php echo htmlspecialchars($c['file'], ENT_QUOTES); ?>" class="ghost">Download</button>
      </form>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>By person</h2>
    <?php if (!$people): ?><p>Nothing yet.</p><?php endif; ?>
    <?php foreach ($people as $p): ?>
      <form method="POST" class="row">
        <input type="hidden" name="pw" value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>">
        <input type="hidden" name="kind" value="people">
        <b><?php echo htmlspecialchars(str_replace(' — Game Profile', '', $p['title']), ENT_QUOTES); ?></b>
        <button type="submit" name="file" value="<?php echo htmlspecialchars($p['file'], ENT_QUOTES); ?>" class="ghost">Download</button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
</body></html>
