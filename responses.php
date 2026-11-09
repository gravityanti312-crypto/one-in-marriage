<?php
/**
 * ONE in Marriage — everything the team needs from the survey.
 *   - the spreadsheet of every answer
 *   - one readable file per couple (with the Section 7 mutual matches)
 *   - one readable file per person
 * Nothing here is reachable without the password, and the files themselves
 * live above public_html.
 */
// The password lives in team-password.php, which is git-ignored because this
// repository is public. Fail shut: if that file is missing or still holds the
// placeholder, lock the page rather than hand out everyone's answers.
$PASSWORD = '';
if (is_file(__DIR__ . '/team-password.php')) {
    require __DIR__ . '/team-password.php';
    $PASSWORD = isset($TEAM_PASSWORD) ? (string)$TEAM_PASSWORD : '';
}
if ($PASSWORD === '' || $PASSWORD === 'CHANGE_THIS_PASSWORD') {
    http_response_code(503);
    exit('This page is not set up yet: upload team-password.php with the team password in it.');
}

// Everything the team reads is in Utah time, whatever the server is set to.
date_default_timezone_set('America/Denver');

$csv      = dirname(__DIR__) . '/questionnaire-responses.csv';
$coupleD  = dirname(__DIR__) . '/survey-data/couples';
$peopleD  = dirname(__DIR__) . '/survey-data/people';

$DIRS = [
    'couples' => $coupleD,
    'people'  => $peopleD,
];

$pw = $_POST['pw'] ?? '';
$ok = ($pw !== '' && hash_equals($PASSWORD, $pw));

function listing($dir) {
    if (!is_dir($dir)) { return []; }
    $out = [];
    foreach (glob($dir . '/*.md') as $f) {
        // Read the whole file, not just the top. In a completed couple file the
        // partners' dates sit below the Section 7 block, a long way past the
        // header, so a short peek at the start finds nothing.
        $text = (string)@file_get_contents($f, false, null, 0, 262144);

        // Every date the file states for when the survey was taken. A person
        // file has one; a couple file has one per partner, and the couple is
        // only really done on the later of them. "Generated" is deliberately
        // not matched: that is when the file was last built, not when anyone
        // answered anything. filemtime is the same trap.
        // Keep track of whether the winning date actually stated a time. An
        // older file that gives only a day would otherwise be shown as
        // "12:00am", which looks like a real time and is not one.
        $stamp = 0;
        $hasTime = false;
        if (preg_match_all('/^- \*\*(?:Submitted|Taken):\*\* (.+)$/m', $text, $lines)) {
            foreach ($lines[1] as $line) {
                if (preg_match_all('/(\d{1,2} [A-Z][a-z]+ \d{4})(?:, (\d{1,2}:\d{2}[ap]m))?/', $line, $dates, PREG_SET_ORDER)) {
                    foreach ($dates as $d) {
                        $t = (int)strtotime($d[0]);
                        if ($t > $stamp) { $stamp = $t; $hasTime = !empty($d[2]); }
                    }
                }
            }
        }

        $out[] = [
            'file'  => basename($f),
            'title' => trim(ltrim(strtok($text, "\n"), '# ')),
            'time'  => $stamp ?: filemtime($f),
            'taken' => $stamp ? date($hasTime ? 'j M Y, g:ia' : 'j M Y', $stamp) : '',
        ];
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
    $kind  = (string)($_POST['kind'] ?? '');
    $which = $DIRS[$kind] ?? $coupleD;
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
        foreach ($DIRS as $label => $dir) {
            foreach (glob($dir . '/*.md') as $f) { $zip->addFile($f, $label . '/' . basename($f)); }
        }
        if (file_exists($csv)) { $zip->addFile($csv, 'all-answers.csv'); }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="one-in-marriage-survey.zip"');
        readfile($zipPath); @unlink($zipPath); exit;
    }
}

/**
 * Submissions that left a raw copy but never made it into the spreadsheet.
 * This is the check that would have caught Marc Garrett on 5 November.
 */
function unfiled($inboxDir, $csvPath) {
    if (!is_dir($inboxDir)) { return []; }
    $filed = [];
    if (is_file($csvPath) && ($fh = @fopen($csvPath, 'r'))) {
        $head = fgetcsv($fh, null, ",", "\"", "");
        $iEm  = $head ? array_search('email', $head, true) : false;
        $iAt  = $head ? array_search('submitted_at', $head, true) : false;
        if ($iEm !== false && $iAt !== false) {
            while (($r = fgetcsv($fh, null, ",", "\"", "")) !== false) {
                if (isset($r[$iEm], $r[$iAt])) {
                    $filed[strtolower(trim($r[$iEm])) . '|' . trim($r[$iAt])] = true;
                    $filed[strtolower(trim($r[$iEm]))] = true;      // this person is in the sheet at least once
                }
            }
        }
        fclose($fh);
    }
    $out = [];
    foreach (glob($inboxDir . '/*.json') as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || empty($d['post'])) { continue; }
        $em = strtolower(trim((string)($d['post']['email'] ?? '')));
        $at = trim((string)($d['submitted_at'] ?? ''));
        $spam = strpos(basename($f), 'flagged-spam') !== false;
        if ($spam || !isset($filed[$em . '|' . $at])) {
            $out[] = [
                'file'  => basename($f),
                'name'  => trim((string)($d['post']['first_name'] ?? '') . ' ' . (string)($d['post']['last_name'] ?? '')),
                'email' => $em,
                'when'  => $at ? date('j M Y, g:ia', strtotime($at)) : '',
                'spam'  => $spam,
                'repeat'=> $em !== '' && isset($filed[$em]),
            ];
        }
    }
    return $out;
}

$inboxDir = dirname(__DIR__) . '/survey-inbox';

/**
 * File by hand. "File it" puts an inbox copy into the spreadsheet and the
 * per-person/couple files exactly as if it had gone through first time, keeping
 * the time it was really taken. "Dismiss" is for repeats and spam. Neither
 * deletes anything: the copy moves into survey-inbox/filed or /dismissed.
 */
$notice = '';
if ($ok && !empty($_POST['inbox']) && in_array($_POST['inbox_action'] ?? '', ['file', 'dismiss'], true)) {
    $name = basename((string)$_POST['inbox']);
    $src  = $inboxDir . '/' . $name;
    $still = array_filter(unfiled($inboxDir, $csv), fn($u) => $u['file'] === $name);
    if (!$still || !is_file($src) || substr($name, -5) !== '.json') {
        $notice = 'That one has already been dealt with.';     // a refresh re-sending the form
    } elseif ($_POST['inbox_action'] === 'dismiss') {
        @mkdir($inboxDir . '/dismissed', 0700, true);
        $notice = @rename($src, $inboxDir . '/dismissed/' . $name)
            ? 'Dismissed. The copy is kept in survey-inbox/dismissed.'
            : 'Could not move that file &mdash; nothing was changed.';
    } else {
        require_once __DIR__ . '/survey-files.php';
        $d    = json_decode((string)@file_get_contents($src), true);
        $post = is_array($d['post'] ?? null) ? $d['post'] : [];
        $at   = (string)($d['submitted_at'] ?? '') ?: date('c', filemtime($src));
        if (trim((string)($post['email'] ?? '')) === '') {
            $notice = 'That copy has no email address, so it cannot be filed. Dismiss it instead.';
        } elseif (sf_append_csv($post, $at)) {
            sf_save($post);
            @mkdir($inboxDir . '/filed', 0700, true);
            @rename($src, $inboxDir . '/filed/' . $name);
            $who = trim(($post['first_name'] ?? '') . ' ' . ($post['last_name'] ?? '')) ?: $post['email'];
            $notice = 'Filed ' . htmlspecialchars($who, ENT_QUOTES) . ' into the spreadsheet and the couple files.';
        } else {
            $notice = 'The spreadsheet could not be written &mdash; nothing was changed. Try again in a moment.';
        }
    }
}

$unfiled  = $ok ? unfiled($inboxDir, $csv) : [];
$sentBy   = array_count_values(array_column($unfiled, 'email'));

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
 .when{margin-left:auto;font-size:.82rem;color:var(--muted);white-space:nowrap}
 .alert{margin:18px 0 0;padding:16px 18px;border-left:4px solid #c08a2e;border-radius:0 12px 12px 0;background:#fdf4e3;color:#6b4e18;font-size:.92rem;line-height:1.55}
 .alert ul{margin:10px 0 0;padding-left:20px}
 .alert li{margin-bottom:8px}
 .alert code{font-size:.82rem;color:#8a6c3d;overflow-wrap:anywhere}
 .alert .hint{margin:10px 0 0;color:#6b4e18;font-size:.88rem}
 .inbox{list-style:none;padding-left:0 !important}
 .inbox li{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid #efdcb4;margin:0 !important}
 .inbox li > div{min-width:0}
 .acts{display:flex;gap:8px;flex-shrink:0}
 .acts button{min-height:38px;padding:0 15px;font-size:.85rem}
 .notice{margin:18px 0 0;padding:14px 18px;border-left:4px solid var(--sage);border-radius:0 12px 12px 0;background:var(--sage-soft);color:var(--sage-deep);font-size:.92rem}
 @media(max-width:620px){ .inbox li{flex-direction:column;align-items:flex-start} }
 /* On a phone the name, the date and the button cannot share one line. */
 @media(max-width:620px){
   .row{flex-wrap:wrap}
   .row b{flex:1 0 100%}
   .when{margin-left:0;margin-right:auto}
 }
 .stat{display:flex;gap:22px;flex-wrap:wrap;margin:0 0 4px}
 .stat b{display:block;font-size:1.6rem;font-family:'Lora',Georgia,serif}
 .stat span{font-size:.82rem;color:var(--muted)}
 .bad{color:#9c4a34;font-size:.9rem}
 .sectionhead{margin:38px 0 0;padding-bottom:8px;border-bottom:1px solid var(--line);font-size:1.05rem;letter-spacing:.02em;color:var(--sage-deep)}
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

  <?php if ($notice): ?><div class="notice"><?php echo $notice; ?></div><?php endif; ?>

  <?php if ($unfiled): ?>
    <div class="alert">
      <strong><?php echo count($unfiled); ?> submission<?php echo count($unfiled) === 1 ? '' : 's'; ?> did not reach the spreadsheet.</strong>
      The answers were kept, so nothing is lost. Press <b>File it</b> to add one to the spreadsheet and the couple files, or <b>Dismiss</b> for a repeat or spam.
      <?php if (max($sentBy ?: [0]) > 1 || in_array(true, array_column($unfiled, 'repeat'), true)): ?>
        <p class="hint">When the same person appears more than once they were pressing the button again. File the <b>latest</b> one and dismiss the rest. Anything marked <b>already filed</b> is a repeat of someone who is in the spreadsheet.</p>
      <?php endif; ?>
      <ul class="inbox">
        <?php $seen = []; foreach (array_reverse($unfiled) as $u):
          $latest = !isset($seen[$u['email']]); $seen[$u['email']] = true; ?>
          <li>
            <div>
              <?php echo htmlspecialchars($u['name'] ?: $u['email'], ENT_QUOTES); ?>
              <?php if ($u['when']): ?> &middot; <?php echo htmlspecialchars($u['when'], ENT_QUOTES); ?><?php endif; ?>
              <?php if ($u['spam']): ?> &middot; <em>caught by the spam check</em><?php endif; ?>
              <?php if ($u['repeat']): ?> <span class="tag wait">already filed</span>
              <?php elseif ($sentBy[$u['email']] > 1 && $latest): ?> <span class="tag">latest</span><?php endif; ?>
              <br /><code><?php echo htmlspecialchars($u['file'], ENT_QUOTES); ?></code>
            </div>
            <form method="POST" class="acts">
              <input type="hidden" name="pw" value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>">
              <input type="hidden" name="inbox" value="<?php echo htmlspecialchars($u['file'], ENT_QUOTES); ?>">
              <button type="submit" name="inbox_action" value="file">File it</button>
              <button type="submit" name="inbox_action" value="dismiss" class="ghost"
                onclick="return confirm('Dismiss this copy? It is kept in survey-inbox/dismissed, not deleted.')">Dismiss</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <h2 class="sectionhead">THE 90 &mdash; Game Profile Survey</h2>

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
        <span class="when"><?php echo $c['taken'] ? 'taken ' . htmlspecialchars($c['taken'], ENT_QUOTES) : ''; ?></span>
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
        <span class="when"><?php echo $p['taken'] ? 'taken ' . htmlspecialchars($p['taken'], ENT_QUOTES) : ''; ?></span>
        <button type="submit" name="file" value="<?php echo htmlspecialchars($p['file'], ENT_QUOTES); ?>" class="ghost">Download</button>
      </form>
    <?php endforeach; ?>
  </div>

<?php endif; ?>
</div>
</body></html>
