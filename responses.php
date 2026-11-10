<?php
/**
 * ONE in Marriage — everything the team needs from the survey.
 *   - each program's couples and people, in program-date order
 *   - the spreadsheet of current answers, plus a backup of replaced ones
 *   - one readable file per couple (with the Section 7 mutual matches)
 *   - one readable file per person
 *   - pair / unpair / remove, for when people's details did not line up
 * Nothing here is reachable without the password, and the files themselves
 * live above public_html. The records are in survey-data/submissions.json and
 * everything else is rebuilt from them (see survey-files.php).
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

require __DIR__ . '/survey-files.php';

$pw = $_POST['pw'] ?? '';
$ok = ($pw !== '' && hash_equals($PASSWORD, $pw));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pwfield() { global $pw; return '<input type="hidden" name="pw" value="' . h($pw) . '">'; }
function taken_text($s) { return 'Taken ' . date('j M Y, g:ia', sf_ts($s['at'])) . ' · ' . sf_program_short($s['program']); }

/* ---------------------------------------------------------------------
   Load the records, carry out whatever button was pressed, rebuild files
   --------------------------------------------------------------------- */
$notice = ''; $noticeBad = false; $broken = false; $upgradeNote = null;
$store = null; $match = []; $unfiled = []; $files = ['person' => [], 'couple' => []];

if ($ok) {
    $lock  = sf_lock();
    $store = sf_open_store();
    if (!is_array($store)) {
        $broken = true;
    } else {
        $act    = (string)($_POST['act'] ?? '');
        $active = sf_active($store);
        $nameOf = function ($e) use ($store) {
            foreach ($store['subs'] as $s) { if ($s['entry'] === $e) { $n = sf_name_of(sf_who($s['post'])); if ($n !== '') { return $n; } } }
            return 'that person';
        };
        $changed = false;
        $a = (string)($_POST['a'] ?? ''); $b = (string)($_POST['b'] ?? ''); $e = (string)($_POST['e'] ?? '');

        if (($act === 'file' || $act === 'dismiss') && !empty($_POST['inbox'])) {
            // a submission that reached survey-inbox but not the records
            $name = basename((string)$_POST['inbox']);
            $src  = sf_inbox_dir() . '/' . $name;
            $still = array_filter(sf_unfiled($store), function ($u) use ($name) { return $u['file'] === $name; });
            if (!$still || !is_file($src) || substr($name, -5) !== '.json') {
                $notice = 'That one has already been dealt with.';
            } elseif ($act === 'dismiss') {
                @mkdir(sf_inbox_dir() . '/dismissed', 0700, true);
                if (@rename($src, sf_inbox_dir() . '/dismissed/' . $name)) { $notice = 'Dismissed. The copy is kept in survey-inbox/dismissed.'; }
                else { $notice = 'Could not move that file. Nothing was changed.'; $noticeBad = true; }
            } else {
                $d = json_decode((string)@file_get_contents($src), true);
                $r = sf_add($store, $d['post'] ?? [], (string)($d['submitted_at'] ?? ''), 'filed by hand');
                if (!$r['ok']) { $notice = 'That copy has no email address, so it cannot be filed. Dismiss it instead.'; $noticeBad = true; }
                else {
                    $changed = true;
                    @mkdir(sf_inbox_dir() . '/filed', 0700, true);
                    @rename($src, sf_inbox_dir() . '/filed/' . $name);
                    $notice = 'Filed ' . h(sf_name_of(sf_who($d['post'] ?? []))) . '.';
                }
            }
        } elseif ($act === 'pair') {
            $err = sf_pair($store, $a, $b);
            if ($err !== '') { $notice = h($err); $noticeBad = true; }
            else { $changed = true; $notice = 'Paired ' . h($nameOf($a)) . ' and ' . h($nameOf($b)) . '. Their couple file now has both of them, and they stay paired if either submits again.'; }
        } elseif ($act === 'unpair' || $act === 'notcouple') {
            if ($a !== '' && $b !== '' && $a !== $b) {
                sf_unpair($store, $a, $b);
                $changed = true;
                $notice = ($act === 'unpair' ? 'Unpaired ' : 'Noted: ') . h($nameOf($a)) . ' and ' . h($nameOf($b))
                        . ($act === 'unpair' ? '. They will not be matched automatically again; use Pair to put them back together.' : ' are not a couple. The site will not suggest them again.');
            }
        } elseif ($act === 'remove' && $e !== '') {
            sf_remove_entry($store, $e);
            $changed = true;
            $notice = 'Removed ' . h($nameOf($e)) . '. Their answers are kept in the backup; "Put back" is at the bottom of the page.';
        } elseif ($act === 'restore' && $e !== '') {
            sf_restore_entry($store, $e);
            $changed = true;
            $notice = 'Put back ' . h($nameOf($e)) . '.';
        }

        // the first visit after the upgrade says what happened, once
        if (!empty($store['upgrade']) && empty($store['upgrade']['shown'])) {
            $upgradeNote = $store['upgrade'];
            $store['upgrade']['shown'] = true;
            $changed = true;
        }

        if ($changed && !sf_store_save($store)) {
            $notice = 'That could not be saved. Nothing was changed. Please try again.'; $noticeBad = true;
            $store = sf_load();
            if (!is_array($store)) { $broken = true; }
        }
        if (!$broken) {
            $built = sf_dir() . '/.built';
            $stale = $changed || !is_file($built) || @filemtime(sf_store_path()) > @filemtime($built);
            $match = $stale ? sf_rebuild($store) : sf_match($store);
            $unfiled = sf_unfiled($store);
            $files = sf_file_names($store, $match);
        }
    }
    sf_unlock($lock);
}

/* ---------------------------------------------------------------------
   Downloads
   --------------------------------------------------------------------- */
$DIRS = ['couples' => dirname(__DIR__) . '/survey-data/couples', 'people' => dirname(__DIR__) . '/survey-data/people'];

if ($ok && !$broken && isset($_POST['download'])) {
    $path = $_POST['download'] === 'backup' ? sf_replaced_csv_path() : sf_csv_path();
    if (!is_file($path)) { exit('No responses yet.'); }
    $name = $_POST['download'] === 'backup' ? 'one-in-marriage-replaced-answers.csv' : 'one-in-marriage-responses.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path); exit;
}
if ($ok && !$broken && !empty($_POST['file'])) {
    $kind  = (string)($_POST['kind'] ?? '');
    $which = $DIRS[$kind] ?? $DIRS['couples'];
    $path  = $which . '/' . basename((string)$_POST['file']);
    if (is_file($path)) {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path); exit;
    }
    $notice = 'That file has changed since the page was opened. Here is the latest list.';
}
if ($ok && !$broken && isset($_POST['zip'])) {
    $zipPath = tempnam(sys_get_temp_dir(), 'oim') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
        foreach ($DIRS as $label => $dir) {
            foreach (glob($dir . '/*.md') ?: [] as $f) { $zip->addFile($f, $label . '/' . basename($f)); }
        }
        if (is_file(sf_csv_path())) { $zip->addFile(sf_csv_path(), 'all-answers.csv'); }
        if (is_file(sf_replaced_csv_path())) { $zip->addFile(sf_replaced_csv_path(), 'backup/replaced-answers.csv'); }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="one-in-marriage-survey.zip"');
        readfile($zipPath); @unlink($zipPath); exit;
    }
}

/* ---------------------------------------------------------------------
   Numbers for the page
   --------------------------------------------------------------------- */
$active  = $ok && !$broken ? sf_active($store) : [];
$removed = $ok && !$broken ? sf_removed($store) : [];
$nCouples = 0; $nSingles = 0; $suggestAll = [];
foreach ($match as $program => $g) {
    $nCouples += count($g['couples']);
    $nSingles += count($g['singles']);
    foreach ($g['suggest'] as $p) { $suggestAll[] = [$program, $p[0], $p[1]]; }
}
$nReplaced = 0;
if ($ok && !$broken) { foreach ($store['subs'] as $s) { if ($s['status'] !== 'active') { $nReplaced++; } } }
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Survey responses &mdash; ONE in Marriage</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@500;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
 :root{--cream:#faf6ee;--paper:#fffdf8;--ink:#2b2c26;--body:#5f635b;--muted:#7a7d74;--sage:#7d9068;--sage-deep:#5f7350;--sage-soft:#e9efe1;--tan:#a9824f;--line:#e7e0d1}
 *{box-sizing:border-box}
 body{margin:0;background:var(--cream);color:var(--ink);font-family:'Outfit',system-ui,Arial,sans-serif;line-height:1.6}
 .wrap{width:min(720px,calc(100% - 32px));margin-inline:auto;padding:46px 0 70px}
 .eyebrow{margin:0 0 10px;color:var(--tan);font-size:.72rem;font-weight:700;letter-spacing:.19em;text-transform:uppercase}
 h1{margin:0 0 8px;font-family:'Lora',Georgia,serif;font-weight:600;font-size:2rem}
 h2{margin:0 0 10px;font-family:'Lora',Georgia,serif;font-weight:600;font-size:1.2rem}
 h3{margin:0}
 p{color:var(--body)}
 .card{background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:22px;margin-top:16px}
 .card > .sub{margin:-4px 0 12px;font-size:.88rem;color:var(--muted)}
 .pwrow{display:flex;gap:8px;align-items:center;margin-bottom:12px}
 input[type=password]{flex:1;padding:12px 14px;border:1px solid var(--line);border-radius:12px;font:inherit}
 button{min-height:44px;padding:0 20px;border:0;border-radius:999px;background:var(--sage);color:#fff;font:inherit;font-weight:600;cursor:pointer}
 button:hover{background:var(--sage-deep)}
 .reveal,.ghost{background:#fff;border:1px solid var(--line);color:var(--sage-deep)}
 .reveal:hover,.ghost:hover{background:var(--sage-soft);border-color:var(--sage)}
 .quiet{background:transparent;border:0;color:var(--muted);text-decoration:underline;text-underline-offset:3px;padding:0 6px;min-height:36px}
 .quiet:hover{background:transparent;color:#9c4a34}
 .row{display:flex;align-items:center;gap:10px;padding:11px 14px;border:1px solid var(--line);border-radius:12px;background:#fff;margin-bottom:8px}
 .who{flex:1;min-width:0}
 .who b{font-size:.96rem;font-weight:600;display:block}
 .meta{display:block;font-size:.8rem;color:var(--muted);line-height:1.45;overflow-wrap:anywhere}
 .meta.how{color:#6a7560}
 .acts{display:flex;gap:6px;align-items:center;flex-shrink:0;margin:0}
 .row button{min-height:36px;padding:0 14px;font-size:.84rem}
 .tag{font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 9px;border-radius:999px;background:var(--sage-soft);color:var(--sage-deep);white-space:nowrap}
 .tag.wait{background:#f6efe0;color:#8a6c3d}
 .subhead{margin:16px 0 8px;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
 .alert{margin:18px 0 0;padding:16px 18px;border-left:4px solid #c08a2e;border-radius:0 12px 12px 0;background:#fdf4e3;color:#6b4e18;font-size:.92rem;line-height:1.55}
 .alert code{font-size:.82rem;color:#8a6c3d;overflow-wrap:anywhere}
 .alert .hint{margin:10px 0 0;color:#6b4e18;font-size:.88rem}
 .inbox{list-style:none;padding:0;margin:10px 0 0}
 .inbox li{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid #efdcb4}
 .inbox li > div{min-width:0}
 .notice{margin:18px 0 0;padding:14px 18px;border-left:4px solid var(--sage);border-radius:0 12px 12px 0;background:var(--sage-soft);color:var(--sage-deep);font-size:.92rem}
 .notice.bad{border-color:#b5523b;background:#f8e9e4;color:#8a3a26}
 .look{border-color:#e6cf9f;background:#fffaf0}
 .look .row{background:#fffdf8}
 .stat{display:flex;gap:22px;flex-wrap:wrap;margin:0 0 4px}
 .stat b{display:block;font-size:1.6rem;font-family:'Lora',Georgia,serif}
 .stat span{font-size:.82rem;color:var(--muted)}
 .dl{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
 .bad{color:#9c4a34;font-size:.9rem}
 .sectionhead{margin:38px 0 0;padding-bottom:8px;border-bottom:1px solid var(--line);font-size:1.05rem;letter-spacing:.02em;color:var(--sage-deep)}
 .program{margin-top:30px}
 .proghead{font-family:'Lora',Georgia,serif;font-weight:600;font-size:1.25rem;line-height:1.3}
 .progmeta{display:block;margin-top:2px;font-family:'Outfit',system-ui,sans-serif;font-size:.84rem;font-weight:500;color:var(--muted)}
 .pairform{margin-top:16px;padding-top:14px;border-top:1px dashed var(--line)}
 .pairform label{display:block;font-weight:600;font-size:.92rem;margin-bottom:6px}
 .pairsel{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
 .pairsel select{flex:1 1 200px;min-width:0;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:#fff;font:inherit;font-size:.9rem}
 .pairsel span{color:var(--muted)}
 .small{font-size:.82rem;color:var(--muted);margin:6px 0 0}
 details.removed{margin-top:30px}
 details.removed summary{cursor:pointer;color:var(--muted);font-size:.9rem}
 /* On a phone the name, the tag and the buttons cannot share one line. */
 @media(max-width:620px){
   .row{flex-wrap:wrap}
   .who{flex:1 0 100%}
   .inbox li{flex-direction:column;align-items:flex-start}
 }
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

<?php elseif ($broken): ?>
  <div class="notice bad">
    The survey records file (<code>survey-data/submissions.json</code>) could not be read, so this page has stopped rather than risk overwriting it.
    New submissions are still being kept in <code>survey-inbox</code>. A copy of the previous version is next to it as <code>submissions.json.previous</code>.
  </div>

<?php else: ?>

  <?php if ($notice): ?><div class="notice<?php echo $noticeBad ? ' bad' : ''; ?>"><?php echo $notice; ?></div><?php endif; ?>

  <?php if ($upgradeNote): ?>
    <div class="notice">
      <strong>Survey records upgraded.</strong>
      <?php echo (int)$upgradeNote['found']; ?> submissions were found and organised by program. Where someone submitted more than once for the same program, only their newest answers are shown; the older ones are in the backup spreadsheet.
      <?php if (!empty($upgradeNote['unreadable_rows'])): ?><br><strong><?php echo (int)$upgradeNote['unreadable_rows']; ?> spreadsheet row(s) could not be read</strong> and need a look by hand.<?php endif; ?>
      Everything from before the upgrade was backed up to <code><?php echo h($upgradeNote['backup']); ?></code>.
    </div>
  <?php endif; ?>

  <?php if ($unfiled): $sentBy = array_count_values(array_column($unfiled, 'email')); ?>
    <div class="alert">
      <strong><?php echo count($unfiled); ?> submission<?php echo count($unfiled) === 1 ? '' : 's'; ?> did not get filed.</strong>
      The answers were kept, so nothing is lost. Press <b>File it</b> to add one, or <b>Dismiss</b> for a repeat or spam.
      <?php if (max($sentBy ?: [0]) > 1 || in_array(true, array_column($unfiled, 'repeat'), true)): ?>
        <p class="hint">When the same person appears more than once they were pressing the button again. Filing an older copy never replaces newer answers, so it is safe either way. Anything marked <b>already filed</b> is a repeat.</p>
      <?php endif; ?>
      <ul class="inbox">
        <?php $seen = []; foreach (array_reverse($unfiled) as $u):
          $latest = !isset($seen[$u['email']]); $seen[$u['email']] = true; ?>
          <li>
            <div>
              <?php echo h($u['name'] ?: $u['email']); ?>
              <?php if ($u['when']): ?> &middot; <?php echo h($u['when']); ?><?php endif; ?>
              <?php if ($u['spam']): ?> &middot; <em>caught by the spam check</em><?php endif; ?>
              <?php if ($u['repeat']): ?> <span class="tag wait">already filed</span>
              <?php elseif ($sentBy[$u['email']] > 1 && $latest): ?> <span class="tag">latest</span><?php endif; ?>
              <br /><code><?php echo h($u['file']); ?></code>
            </div>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="inbox" value="<?php echo h($u['file']); ?>">
              <button type="submit" name="act" value="file">File it</button>
              <button type="submit" name="act" value="dismiss" class="ghost"
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
      <div><b><?php echo count($active); ?></b><span>people finished</span></div>
      <div><b><?php echo $nCouples; ?></b><span>couples complete</span></div>
      <div><b><?php echo $nSingles; ?></b><span>waiting on a partner</span></div>
    </div>
    <form method="POST" class="dl">
      <?php echo pwfield(); ?>
      <button type="submit" name="download" value="current">Download spreadsheet</button>
      <button type="submit" name="zip" value="1" class="ghost">Download everything (.zip)</button>
      <?php if ($nReplaced): ?>
        <button type="submit" name="download" value="backup" class="quiet" title="Older answers that a resubmission replaced, and anyone removed">Backup of replaced answers (<?php echo $nReplaced; ?>)</button>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($suggestAll): ?>
    <div class="card look">
      <h2>Needs a look</h2>
      <p class="sub">These might be couples, but their names and emails don&rsquo;t fully line up, so the site hasn&rsquo;t paired them on its own.</p>
      <?php foreach ($suggestAll as $sg): [$program, $x, $y] = $sg;
        $wx = sf_who($active[$x]['post']); $wy = sf_who($active[$y]['post']); ?>
        <div class="row">
          <div class="who">
            <b><?php echo h(sf_name_of($wx) . ' & ' . sf_name_of($wy)); ?></b>
            <span class="meta"><?php echo h(sf_suggest_text($wx, $wy)); ?></span>
            <span class="meta"><?php echo h(sf_program_short($program)); ?></span>
          </div>
          <form method="POST" class="acts">
            <?php echo pwfield(); ?>
            <input type="hidden" name="a" value="<?php echo h($x); ?>"><input type="hidden" name="b" value="<?php echo h($y); ?>">
            <button type="submit" name="act" value="pair">Pair them</button>
            <button type="submit" name="act" value="notcouple" class="ghost">Not a couple</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$match): ?><div class="card"><p>Nothing yet.</p></div><?php endif; ?>

  <?php foreach ($match as $program => $g):
    $past = sf_program_is_past($program);
    $nPeople = count($g['entries']); ?>
    <section class="program">
      <h3 class="proghead"><?php echo h($program); ?>
        <span class="progmeta"><?php echo $past ? 'Past program' : 'Upcoming program'; ?> &middot;
          <?php echo $nPeople; ?> <?php echo $nPeople === 1 ? 'person' : 'people'; ?> &middot;
          <?php echo count($g['couples']); ?> <?php echo count($g['couples']) === 1 ? 'couple' : 'couples'; ?>
          <?php if ($g['singles']): ?> &middot; <?php echo count($g['singles']); ?> waiting<?php endif; ?></span>
      </h3>

      <div class="card">
        <h2>By couple</h2>
        <p class="sub">Both partners in one file, with the Section 7 mutual matches worked out.</p>
        <?php foreach ($g['couples'] as $c):
          $A = $active[$c['a']]; $B = $active[$c['b']];
          $wa = sf_who($A['post']); $wb = sf_who($B['post']); ?>
          <div class="row">
            <div class="who">
              <b><?php echo h(sf_name_of($wa) . ' & ' . sf_name_of($wb)); ?></b>
              <span class="meta">Taken <?php echo h(date('j M, g:ia', sf_ts($A['at']))); ?> and <?php echo h(date('j M Y, g:ia', sf_ts($B['at']))); ?> &middot; <?php echo h(sf_program_short($program)); ?></span>
              <span class="meta how"><?php echo h(sf_how_text($wa, $wb, $c['how'])); ?></span>
            </div>
            <span class="tag">complete</span>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="kind" value="couples">
              <button type="submit" name="file" value="<?php echo h($files['couple'][$c['a']] ?? ''); ?>" class="ghost">Download</button>
            </form>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="a" value="<?php echo h($c['a']); ?>"><input type="hidden" name="b" value="<?php echo h($c['b']); ?>">
              <button type="submit" name="act" value="unpair" class="quiet"
                onclick="return confirm('Unpair <?php echo h(addslashes(sf_name_of($wa) . ' and ' . sf_name_of($wb))); ?>? They will not be matched automatically again.')">Unpair</button>
            </form>
          </div>
        <?php endforeach; ?>

        <?php if ($g['singles']): ?>
          <p class="subhead">Waiting on a partner</p>
          <?php foreach ($g['singles'] as $e):
            $S = $active[$e]; $w = sf_who($S['post']); $named = sf_partner_of($w); ?>
            <div class="row">
              <div class="who">
                <b><?php echo h(sf_name_of($w)); ?></b>
                <span class="meta"><?php echo h(taken_text($S)); ?></span>
                <span class="meta">Named <?php echo h($named !== '' ? $named : 'nobody'); ?><?php echo $w['pemail'] !== '' ? ' (' . h($w['pemail']) . ')' : ''; ?> as their partner</span>
              </div>
              <span class="tag wait">waiting</span>
              <form method="POST" class="acts">
                <?php echo pwfield(); ?>
                <input type="hidden" name="kind" value="couples">
                <button type="submit" name="file" value="<?php echo h($files['couple'][$e] ?? ''); ?>" class="ghost">Download</button>
              </form>
            </div>
          <?php endforeach; ?>

          <?php if (count($g['singles']) >= 2): ?>
            <form method="POST" class="pairform">
              <?php echo pwfield(); ?>
              <label for="pa-<?php echo h(sf_short($program)); ?>">Pair two people by hand</label>
              <div class="pairsel">
                <?php foreach (['a', 'b'] as $i => $field): ?>
                  <?php if ($i === 1): ?><span>&amp;</span><?php endif; ?>
                  <select name="<?php echo $field; ?>" id="p<?php echo $field; ?>-<?php echo h(sf_short($program)); ?>" required>
                    <option value="">Choose a person</option>
                    <?php foreach ($g['singles'] as $e): $w = sf_who($active[$e]['post']); ?>
                      <option value="<?php echo h($e); ?>"><?php echo h(sf_name_of($w) . ' · ' . $w['email']); ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endforeach; ?>
                <button type="submit" name="act" value="pair">Pair them</button>
              </div>
              <p class="small">For when you know two people are a couple but their names or emails didn&rsquo;t line up. They stay paired even if either of them submits again.</p>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>By person</h2>
        <p class="sub">In the order they took the survey.</p>
        <?php foreach ($g['entries'] as $e): $S = $active[$e]; $w = sf_who($S['post']); ?>
          <div class="row">
            <div class="who">
              <b><?php echo h(sf_name_of($w)); ?></b>
              <span class="meta"><?php echo h(taken_text($S)); ?> &middot; <?php echo h($w['email']); ?></span>
            </div>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="kind" value="people">
              <button type="submit" name="file" value="<?php echo h($files['person'][$e] ?? ''); ?>" class="ghost">Download</button>
            </form>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="e" value="<?php echo h($e); ?>">
              <button type="submit" name="act" value="remove" class="quiet"
                onclick="return confirm('Remove <?php echo h(addslashes(sf_name_of($w))); ?> from this program? Their answers are kept in the backup and you can put them back.')">Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($removed): ?>
    <details class="removed">
      <summary>Removed (<?php echo count($removed); ?>)</summary>
      <div class="card">
        <?php foreach ($removed as $e => $S): $w = sf_who($S['post']); ?>
          <div class="row">
            <div class="who">
              <b><?php echo h(sf_name_of($w)); ?></b>
              <span class="meta"><?php echo h(taken_text($S)); ?> &middot; <?php echo h($w['email']); ?></span>
            </div>
            <form method="POST" class="acts">
              <?php echo pwfield(); ?>
              <input type="hidden" name="e" value="<?php echo h($e); ?>">
              <button type="submit" name="act" value="restore" class="ghost">Put back</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

<?php endif; ?>
</div>
</body></html>
