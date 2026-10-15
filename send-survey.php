<?php
/**
 * ONE in Marriage — "send the survey" page.
 * Lists everyone who has registered and paid (live from Stripe), lets the team
 * tick who's in the room, and emails each partner their own survey link.
 *
 * Visit: https://oneinmarriage.com/send-survey.php
 */
require __DIR__ . '/payment-config.php';

$PASSWORD = 'CHANGE_THIS_PASSWORD';          // <-- set before uploading
$LOG      = dirname(__DIR__) . '/survey-sends.csv';
$EXTRA    = dirname(__DIR__) . '/survey-people.json';   // manually added people + hidden ones
$SURVEY   = base_url() . '/questionnaire.html';

$pw = $_POST['pw'] ?? '';
$ok = ($pw !== '' && hash_equals($PASSWORD, $pw));

/** Manually added people and hidden emails. */
function extra_load($path) {
    $d = file_exists($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($d)) { $d = []; }
    return ['added' => $d['added'] ?? [], 'hidden' => $d['hidden'] ?? []];
}
function extra_save($path, $data) { @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)); @chmod($path, 0600); }

/** Everyone who paid, one row per person (both partners). */
function paid_people($secret) {
    global $COUNT_FROM;
    $people = [];
    $after  = '';
    for ($page = 0; $page < 10; $page++) {
        $path = 'checkout/sessions?limit=100&status=complete';
        if (!empty($COUNT_FROM)) { $path .= '&created[gte]=' . (int)$COUNT_FROM; }
        if ($after !== '') { $path .= '&starting_after=' . urlencode($after); }
        $res = stripe_call('GET', $path, [], $secret);
        if ($res['code'] !== 200 || empty($res['body']['data'])) { break; }
        foreach ($res['body']['data'] as $s) {
            $after = $s['id'];
            if (($s['payment_status'] ?? '') !== 'paid') { continue; }
            $m = $s['metadata'] ?? [];
            $couple = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: 'Registration';
            if (!empty($m['email'])) {
                $people[strtolower($m['email'])] = [
                    'name'   => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
                    'email'  => $m['email'],
                    'couple' => $couple,
                    'course' => $m['date'] ?? '',
                ];
            }
            if (!empty($m['partner_email'])) {
                $people[strtolower($m['partner_email'])] = [
                    'name'   => trim(($m['partner_first_name'] ?? '') . ' ' . ($m['partner_last_name'] ?? '')),
                    'email'  => $m['partner_email'],
                    'couple' => $couple,
                    'course' => $m['date'] ?? '',
                ];
            }
        }
        if (empty($res['body']['has_more'])) { break; }
    }
    return array_values($people);
}

/** Who has already been sent the survey. */
function already_sent($log) {
    $sent = [];
    if (!file_exists($log)) { return $sent; }
    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $i => $line) {
        if ($i === 0) { continue; }
        $row = str_getcsv($line);
        if (!empty($row[1])) { $sent[strtolower($row[1])] = $row[0]; }
    }
    return $sent;
}

function survey_email($first, $survey_url) {
    $hi = $first !== '' ? esc($first) : 'there';
    return '<tr><td style="padding:28px 28px 6px;">'
      . '<div style="color:#a9824f;font-size:12px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase;">THE 90</div>'
      . '<div style="font-family:Georgia,serif;font-size:26px;color:#2b2c26;margin-top:8px;">Your Game Profile Survey, ' . $hi . '</div>'
      . '</td></tr>'
      . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">'
      . 'This is the survey we talked about today. <strong>Please complete it tonight, before tomorrow morning</strong> &mdash; your 90-day game gets built around what you share.'
      . '</td></tr>'
      . '<tr><td style="padding:10px 28px 4px;color:#5f635b;font-size:15px;line-height:1.65;">'
      . 'You and your partner each fill in your own, privately. Your answers are not shown to each other. It takes about 15 minutes, and it saves as you go &mdash; so you can stop and come back if you need to.'
      . '</td></tr>'
      . '<tr><td style="padding:24px 28px 8px;"><a href="' . esc($survey_url) . '" style="display:inline-block;padding:14px 28px;border-radius:999px;background:#7d9068;color:#ffffff;font-weight:bold;text-decoration:none;font-size:15px;">Start the survey</a></td></tr>'
      . '<tr><td style="padding:4px 28px 26px;color:#8a8d84;font-size:13px;line-height:1.6;">Or paste this into your browser:<br>' . esc($survey_url) . '</td></tr>'
      . '<tr><td style="background:#f6efe0;padding:16px 28px;color:#8a8d84;font-size:12px;text-align:center;line-height:1.5;">ONE in Marriage &middot; A Pattern-Aware Marriage Experience</td></tr>';
}

$extra = extra_load($EXTRA);

if ($ok && isset($_POST['add_person'])) {
    $n = trim((string)($_POST['new_name'] ?? ''));
    $e = trim((string)($_POST['new_email'] ?? ''));
    if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
        $extra['added'][strtolower($e)] = ['name' => $n, 'email' => $e];
        $extra['hidden'] = array_values(array_diff($extra['hidden'], [strtolower($e)]));
        extra_save($EXTRA, $extra);
    } else {
        $addError = 'That email address doesn\'t look right.';
    }
}
if ($ok && !empty($_POST['hide'])) {
    $h = strtolower(trim((string)$_POST['hide']));
    if (!in_array($h, $extra['hidden'], true)) { $extra['hidden'][] = $h; }
    unset($extra['added'][$h]);
    extra_save($EXTRA, $extra);
}
if ($ok && !empty($_POST['unhide'])) {
    $extra['hidden'] = array_values(array_diff($extra['hidden'], [strtolower(trim((string)$_POST['unhide']))]));
    extra_save($EXTRA, $extra);
}

$results = [];
if ($ok && isset($_POST['send']) && !empty($_POST['to']) && is_array($_POST['to'])) {
    $fh = fopen($LOG, 'a+');
    if ($fh && filesize($LOG) === 0) { fputcsv($fh, ['sent_at', 'email', 'name']); }
    foreach ($_POST['to'] as $entry) {
        [$email, $name] = array_pad(explode('|', $entry, 2), 2, '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
        $first = trim(explode(' ', trim($name))[0] ?? '');
        $sent  = send_email($email, 'Your 90-Day Game Profile Survey', branded_email(survey_email($first, $SURVEY)), $RECIPIENT);
        $results[] = [$email, $sent];
        if ($fh && $sent) { fputcsv($fh, [date('c'), $email, $name]); }
    }
    if ($fh) { fclose($fh); }
}

$people = $ok ? paid_people($STRIPE_SECRET_KEY) : [];
foreach ($extra['added'] as $a) {
    $people[] = ['name' => $a['name'], 'email' => $a['email'], 'couple' => 'Added by hand', 'course' => ''];
}
$hiddenPeople = [];
$people = array_values(array_filter($people, function ($p) use ($extra, &$hiddenPeople) {
    if (in_array(strtolower($p['email']), $extra['hidden'], true)) { $hiddenPeople[] = $p; return false; }
    return true;
}));
$sentLog = already_sent($LOG);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Send the survey &mdash; ONE in Marriage</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@500;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
 :root{--cream:#faf6ee;--paper:#fffdf8;--ink:#2b2c26;--body:#5f635b;--muted:#7a7d74;--sage:#7d9068;--sage-deep:#5f7350;--sage-soft:#e9efe1;--tan:#a9824f;--line:#e7e0d1}
 *{box-sizing:border-box}
 body{margin:0;background:var(--cream);color:var(--ink);font-family:'Outfit',system-ui,Arial,sans-serif;line-height:1.6}
 .wrap{width:min(660px,calc(100% - 36px));margin-inline:auto;padding:46px 0 70px}
 .eyebrow{margin:0 0 10px;color:var(--tan);font-size:.72rem;font-weight:700;letter-spacing:.19em;text-transform:uppercase}
 h1{margin:0 0 8px;font-family:'Lora',Georgia,serif;font-weight:600;font-size:2rem}
 p{color:var(--body)}
 .card{background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:24px;margin-top:20px}
 input[type=password]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:12px;font:inherit;margin-bottom:12px}
 .person{display:flex;align-items:center;gap:12px;padding:11px 14px;border:1px solid var(--line);border-radius:12px;background:#fff;margin-bottom:8px}
 .person input{accent-color:var(--sage);width:18px;height:18px;flex:none}
 .who{flex:1;min-width:0}
 .who b{display:block;font-size:.95rem}
 .who span{color:var(--muted);font-size:.85rem;word-break:break-all}
 .tag{flex:none;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--sage-deep);background:var(--sage-soft);padding:4px 9px;border-radius:999px}
 button{min-height:52px;padding:0 26px;border:0;border-radius:999px;background:var(--sage);color:#fff;font:inherit;font-weight:600;cursor:pointer}
 button:hover{background:var(--sage-deep)}
 .pwrow{display:flex;gap:8px;align-items:center;margin-bottom:12px}
 .pwrow input{margin-bottom:0}
 .reveal{min-height:44px;padding:0 16px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--sage-deep);font:inherit;font-weight:600;cursor:pointer}
 .reveal:hover{border-color:var(--sage);background:var(--sage-soft)}
 .addrow{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
 .addrow input{flex:1;min-width:150px;padding:11px 13px;border:1px solid var(--line);border-radius:12px;font:inherit}
 .addrow button{min-height:46px;padding:0 20px}
 .hidebtn{flex:none;background:none;border:0;color:var(--muted);font:inherit;font-size:.8rem;font-weight:600;text-decoration:underline;cursor:pointer;min-height:0;padding:0}
 .hidebtn:hover{color:#9c4a34;background:none}
 .hiddenlist{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
 .hiddenlist p{margin:0 0 8px;font-size:.84rem;color:var(--muted)}
 .hiddenlist .row{display:flex;justify-content:space-between;gap:10px;font-size:.85rem;color:var(--muted);padding:4px 0}
 .bad{color:#9c4a34;font-weight:600}
 .ok{color:var(--sage-deep);font-weight:600}
 .bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap}
 .mini{background:none;border:0;color:var(--sage-deep);font:inherit;font-weight:600;text-decoration:underline;cursor:pointer;padding:0;min-height:0}
</style></head><body>
<div class="wrap">
  <div class="eyebrow">Team only</div>
  <h1>Send the survey</h1>
  <p>Emails each person their own private link to the Game Profile Survey.</p>

<?php if (!$ok): ?>
  <div class="card">
    <?php if ($pw !== ''): ?><p class="bad">Wrong password.</p><?php endif; ?>
    <form method="POST">
      <div class="pwrow">
        <input type="password" name="pw" id="pw" placeholder="Password" autofocus>
        <button type="button" class="reveal" id="revealBtn" aria-label="Show password">Show</button>
      </div>
      <button type="submit">Continue</button>
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
<?php else: ?>

  <?php if ($results): ?>
    <div class="card">
      <p class="ok"><?php echo count(array_filter($results, fn($r) => $r[1])); ?> sent.</p>
      <?php foreach ($results as $r): if (!$r[1]): ?>
        <p class="bad">Failed: <?php echo esc($r[0]); ?></p>
      <?php endif; endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="pw" value="<?php echo esc($pw); ?>">
    <div class="card">
      <?php if (!$people): ?>
        <p>No paid registrations found yet.</p>
      <?php else: ?>
        <p style="margin-top:0"><strong><?php echo count($people); ?> people</strong> from <?php echo count(array_unique(array_column($people,'couple'))); ?> couples. Untick anyone who shouldn&rsquo;t get it.</p>
        <div class="bar" style="margin:0 0 14px">
          <button type="button" class="mini" onclick="document.querySelectorAll('.person input').forEach(c=>c.checked=true)">Select all</button>
          <button type="button" class="mini" onclick="document.querySelectorAll('.person input').forEach(c=>c.checked=false)">Select none</button>
        </div>
        <?php foreach ($people as $p):
          $key  = strtolower($p['email']);
          $done = $sentLog[$key] ?? null; ?>
          <label class="person">
            <input type="checkbox" name="to[]" value="<?php echo esc($p['email'] . '|' . $p['name']); ?>" <?php echo $done ? '' : 'checked'; ?>>
            <span class="who">
              <b><?php echo esc($p['name'] !== '' ? $p['name'] : $p['email']); ?></b>
              <span><?php echo esc($p['email']); ?> &middot; <?php echo esc($p['couple']); ?></span>
            </span>
            <?php if ($done): ?><span class="tag">sent <?php echo esc(substr($done, 0, 10)); ?></span><?php endif; ?>
          </label>
          <div style="margin:-6px 0 10px 14px"><button type="submit" form="hideform" class="hidebtn" name="hide" value="<?php echo esc($p['email']); ?>">Remove from this list</button></div>
        <?php endforeach; ?>
        <div class="bar">
          <button type="submit" name="send" value="1">Send the survey</button>
          <span style="color:var(--muted);font-size:.85rem">Each person gets their own email.</span>
        </div>
      <?php endif; ?>
    </div>
  </form>

  <form method="POST" id="hideform">
    <input type="hidden" name="pw" value="<?php echo esc($pw); ?>">
  </form>

  <div class="card">
    <p style="margin-top:0"><strong>Someone not on the list?</strong> Add anyone who didn&rsquo;t register through the website.</p>
    <?php if (!empty($addError)): ?><p class="bad"><?php echo esc($addError); ?></p><?php endif; ?>
    <form method="POST" class="addrow">
      <input type="hidden" name="pw" value="<?php echo esc($pw); ?>">
      <input type="text" name="new_name" placeholder="First and last name">
      <input type="email" name="new_email" placeholder="their@email.com">
      <button type="submit" name="add_person" value="1">Add</button>
    </form>

    <?php if (!empty($hiddenPeople) || !empty($extra['hidden'])): ?>
      <div class="hiddenlist">
        <p>Removed from the list &mdash; they won&rsquo;t be emailed:</p>
        <?php foreach ($extra['hidden'] as $h): ?>
          <div class="row">
            <span><?php echo esc($h); ?></span>
            <button type="submit" form="hideform" class="hidebtn" name="unhide" value="<?php echo esc($h); ?>">Put back</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
</body></html>
