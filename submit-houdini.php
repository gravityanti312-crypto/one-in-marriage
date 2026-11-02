<?php
/**
 * ONE in Marriage — Houdini Lens Assessment handler.
 *
 * The page posts one JSON blob. We keep three things, all above public_html:
 *   houdini-responses.csv                   the spreadsheet, one row per person
 *   survey-data/houdini/<slug>.md           one readable file per person
 *   survey-data/houdini-couples/<key>.md    both partners' lenses side by side
 *
 * No email is sent. Everything is downloaded from responses.php.
 */

require __DIR__ . '/survey-files.php';   // sf_dir(), sf_slug(), sf_couple_key()

// The page checks that the reply is exactly "OK". Buffer everything so a stray
// notice or deprecation from a different PHP version can never corrupt it.
ob_start();

header('Content-Type: text/plain; charset=UTF-8');

function hd_fail($code, $message) {
    if (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    exit($message);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hd_fail(405, 'Method not allowed.');
}

$raw = (string)($_POST['payload'] ?? '');
if ($raw === '' || strlen($raw) > 65536) {
    hd_fail(400, 'Bad payload.');
}

$in = json_decode($raw, true);
if (!is_array($in) || ($in['v'] ?? null) !== 1) {
    hd_fail(400, 'Bad payload.');
}

$details = is_array($in['details'] ?? null) ? $in['details'] : [];
$answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
$scores  = is_array($in['scores']  ?? null) ? $in['scores']  : [];
$top     = is_array($in['top_lenses'] ?? null) ? $in['top_lenses'] : [];

$field = function ($key) use ($details) {
    return trim((string)($details[$key] ?? ''));
};

$email = strtolower($field('email'));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    hd_fail(400, 'Bad payload.');
}
if (count($answers) !== 20 || count($scores) !== 8) {
    hd_fail(400, 'Bad payload.');
}

$name   = trim($field('first_name') . ' ' . $field('last_name'));
$pemail = strtolower($field('partner_email'));
$pname  = trim($field('partner_first_name') . ' ' . $field('partner_last_name'));
$when   = date('c');

$clean = function ($s) {
    return trim(preg_replace('/\s+/', ' ', (string)$s));
};

// ---------------- the spreadsheet row ----------------
// The header is written once, on the first submission ever, so every later row
// has to line up with it. Build the columns in a fixed order, never in the
// order a particular person's scores happened to rank.
$LENS_ORDER = ['Savior', 'Survivor', 'Prover', 'Martyr',
               'Analyst', 'Endurer', 'Ghost', 'Chameleon'];

$row = [
    'submitted_at'        => $when,
    'course'              => $field('course'),
    'first_name'          => $field('first_name'),
    'last_name'           => $field('last_name'),
    'email'               => $email,
    'partner_first_name'  => $field('partner_first_name'),
    'partner_last_name'   => $field('partner_last_name'),
    'partner_email'       => $pemail,
    'top_lenses'          => implode(' | ', array_map($clean, $top)),
];
foreach ($LENS_ORDER as $lens) {
    $row[$lens . '_rank']   = '';
    $row[$lens . '_signal'] = '';
    $row[$lens . '_points'] = '';
}
for ($n = 1; $n <= 20; $n++) { $row['q' . $n] = ''; }

foreach ($scores as $s) {
    if (!is_array($s)) { continue; }
    $lens = (string)($s['lens'] ?? '');
    if (!in_array($lens, $LENS_ORDER, true)) { continue; }
    $row[$lens . '_rank']   = (string)(int)($s['rank'] ?? 0);
    $row[$lens . '_signal'] = (string)(0 + ($s['signal'] ?? 0));
    $row[$lens . '_points'] = (string)(int)($s['earned'] ?? 0);
}
foreach ($answers as $a) {
    if (!is_array($a)) { continue; }
    $n = (int)($a['n'] ?? 0);
    if ($n < 1 || $n > 20) { continue; }
    $letter = preg_replace('/[^A-D]/', '', strtoupper((string)($a['letter'] ?? '')));
    $row['q' . $n] = trim($letter . ' — ' . $clean($a['answer'] ?? ''));
}

$csv = dirname(__DIR__) . '/houdini-responses.csv';
$fh  = @fopen($csv, 'a+');
if ($fh) {
    if (flock($fh, LOCK_EX)) {
        clearstatcache(true, $csv);
        if (filesize($csv) === 0) { fputcsv($fh, array_keys($row), ',', '"', ''); }
        fputcsv($fh, array_values($row), ',', '"', '');
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($csv, 0600);
}

// ---------------- one readable file per person ----------------
function hd_person_markdown($in, $when) {
    $d = is_array($in['details'] ?? null) ? $in['details'] : [];
    $g = function ($k) use ($d) { return trim((string)($d[$k] ?? '')) ?: '—'; };
    $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));

    $md  = '# ' . ($name ?: 'Assessment response') . " — Houdini Lens Assessment\n\n";
    $md .= '- **Course:** ' . $g('course') . "\n";
    $md .= '- **Email:** ' . $g('email') . "\n";
    $md .= "- **Partner:** " . (trim(($d['partner_first_name'] ?? '') . ' ' . ($d['partner_last_name'] ?? '')) ?: '—') . "\n";
    $md .= '- **Partner’s email:** ' . $g('partner_email') . "\n";
    $md .= '- **Completed:** ' . date('j F Y, g:ia', strtotime($when)) . "\n\n";

    $top = is_array($in['top_lenses'] ?? null) ? $in['top_lenses'] : [];
    $md .= '## Top lenses: ' . (implode(', ', $top) ?: '—') . "\n\n";

    $md .= "| Rank | Lens | Signal | Points | Possible |\n|---:|---|---:|---:|---:|\n";
    foreach (($in['scores'] ?? []) as $s) {
        if (!is_array($s)) { continue; }
        $md .= '| ' . (int)($s['rank'] ?? 0)
             . ' | ' . (string)($s['lens'] ?? '')
             . ' | ' . (0 + ($s['signal'] ?? 0))
             . ' | ' . (int)($s['earned'] ?? 0)
             . ' | ' . (int)($s['assigned'] ?? 0) . " |\n";
    }

    $md .= "\n---\n\n## Every answer\n\n";
    foreach (($in['answers'] ?? []) as $a) {
        if (!is_array($a)) { continue; }
        $md .= '**' . (int)($a['n'] ?? 0) . '. ' . (string)($a['question'] ?? '') . "**  \n";
        $md .= (string)($a['letter'] ?? '') . ' — ' . (string)($a['answer'] ?? '') . "\n\n";
    }

    $md .= "---\n\n_Based on Never Buy a Horse Named Houdini by Brett Harward. "
         . "A reflection tool, not a diagnosis and not a validated psychological test._\n";
    return $md;
}

$pslug = sf_slug($name) . '-' . substr(md5($email), 0, 6);
$dir   = sf_dir('houdini');
@file_put_contents($dir . '/' . $pslug . '.md', hd_person_markdown($in, $when));
@chmod($dir . '/' . $pslug . '.md', 0600);

// ---------------- both partners side by side ----------------
$key   = sf_couple_key($email, $pemail);
$store = sf_dir('houdini-raw') . '/' . $key . '.json';
$all   = file_exists($store) ? json_decode((string)file_get_contents($store), true) : [];
if (!is_array($all)) { $all = []; }
$in['_submitted_at'] = $when;
$all[$email] = $in;
@file_put_contents($store, json_encode($all));
@chmod($store, 0600);

$people = array_values($all);
$names  = array_map(function ($p) {
    $d = is_array($p['details'] ?? null) ? $p['details'] : [];
    return trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
}, $people);

$title = implode(' & ', array_filter($names)) ?: 'Couple';
$md  = "# $title — Houdini Lenses\n\n";
$md .= '- **Course:** ' . (string)($people[0]['details']['course'] ?? '—') . "\n";
$md .= '- **Status:** ' . (count($people) >= 2
        ? 'both partners have finished'
        : 'waiting on the second partner') . "\n";
$md .= '- **Generated:** ' . date('j F Y, g:ia') . "\n\n";

if (count($people) >= 2) {
    $md .= "---\n\n## Side by side\n\n";
    $md .= '| Lens | ' . ($names[0] ?: 'Partner 1') . ' | ' . ($names[1] ?: 'Partner 2') . " |\n|---|---:|---:|\n";

    $byLens = [];
    foreach ($people as $i => $p) {
        foreach (($p['scores'] ?? []) as $s) {
            if (!is_array($s)) { continue; }
            $byLens[(string)($s['lens'] ?? '')][$i] = $s;
        }
    }
    // Keep the rubric's own lens order, taken from the first partner's rows.
    foreach (($people[0]['scores'] ?? []) as $s) {
        $lens = (string)($s['lens'] ?? '');
        if ($lens === '' || !isset($byLens[$lens])) { continue; }
        $a = $byLens[$lens][0] ?? null;
        $b = $byLens[$lens][1] ?? null;
        $cell = function ($x) {
            return $x ? ((0 + ($x['signal'] ?? 0)) . ' (#' . (int)($x['rank'] ?? 0) . ')') : '—';
        };
        $md .= "| $lens | " . $cell($a) . ' | ' . $cell($b) . " |\n";
    }

    $t0 = is_array($people[0]['top_lenses'] ?? null) ? $people[0]['top_lenses'] : [];
    $t1 = is_array($people[1]['top_lenses'] ?? null) ? $people[1]['top_lenses'] : [];
    $shared = array_values(array_intersect($t0, $t1));

    $md .= "\n### Lenses they share\n\n";
    $md .= $shared
        ? ('- ' . implode("\n- ", $shared) . "\n")
        : "_No overlap in their top lenses._\n";
    $md .= "\n### " . ($names[0] ?: 'Partner 1') . " only\n\n- "
         . (implode("\n- ", array_values(array_diff($t0, $t1))) ?: '_none_') . "\n";
    $md .= "\n### " . ($names[1] ?: 'Partner 2') . " only\n\n- "
         . (implode("\n- ", array_values(array_diff($t1, $t0))) ?: '_none_') . "\n\n";
}

foreach ($people as $i => $p) {
    $md .= "---\n\n";
    $body = hd_person_markdown($p, (string)($p['_submitted_at'] ?? $when));
    $md .= preg_replace('/^#[^\n]*\n/', '# ' . ($names[$i] ?: 'Partner ' . ($i + 1)) . "\n", $body, 1);
}

$cdir = sf_dir('houdini-couples');
@file_put_contents($cdir . '/' . $key . '.md', $md);
@chmod($cdir . '/' . $key . '.md', 0600);

if (ob_get_level()) { ob_end_clean(); }
echo 'OK';
