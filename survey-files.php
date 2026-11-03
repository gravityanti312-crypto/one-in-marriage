<?php
/**
 * ONE in Marriage — turns a survey submission into readable files.
 *
 *   survey-data/people/<slug>.md     one file per person
 *   survey-data/couples/<slug>.md    both partners together, with the Section 7 mutual matches
 *
 * Everything lives above public_html so none of it is reachable from the web.
 */

// Everything the team reads is in Utah time, whatever the server is set to.
date_default_timezone_set('America/Denver');

function sf_dir($sub = '') {
    $base = dirname(__DIR__) . '/survey-data';
    $path = $sub ? $base . '/' . $sub : $base;
    if (!is_dir($path)) { @mkdir($path, 0700, true); }
    return $path;
}

function sf_slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'unknown';
}

/** Section 7 lives in q45/q46/q47 as "<qid>_<index>" => Yes|Maybe|No, with "<qid>_<index>_item" holding the label. */
function sf_intimacy($post) {
    $out = [];
    foreach ($post as $k => $v) {
        if (preg_match('/^(q4[567])_(\d+)$/', $k, $m)) {
            $label = $post[$k . '_item'] ?? ($m[1] . ' item ' . $m[2]);
            $out[$label] = trim((string)$v);
        }
    }
    return $out;
}

/** Human-readable label for a question id, taken from the survey page itself. */
function sf_labels() {
    static $map = null;
    if ($map !== null) { return $map; }
    $map = [];
    $html = @file_get_contents(__DIR__ . '/questionnaire.html');
    if ($html) {
        if (preg_match_all('/<div class="q[^"]*"[^>]*>\s*<p class="qlabel">(.*?)<\/p>(.*?)(?=<div class="q|<\/div>\s*<div class="page|$)/s', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $label = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
                $label = preg_replace('/\s*(Pick one|Pick up to \d+|Pick all that apply|Mark each Yes, Maybe or No)\s*$/i', '', $label);
                if (preg_match('/name="(q\d+)/', $m[2], $n)) { $map[$n[1]] = trim($label); }
            }
        }
    }
    return $map;
}

function sf_person_markdown($post) {
    $labels = sf_labels();
    $name   = trim(($post['first_name'] ?? '') . ' ' . ($post['last_name'] ?? ''));
    $md  = "# " . ($name ?: 'Survey response') . " — Game Profile\n\n";
    $md .= "- **Course:** " . ($post['course'] ?? '—') . "\n";
    $md .= "- **Partner:** " . ($post['partner'] ?? '—') . "\n";
    $md .= "- **Email:** " . ($post['email'] ?? '—') . "\n";
    $md .= "- **Partner's name:** " . trim(($post['partner_first_name'] ?? '') . ' ' . ($post['partner_last_name'] ?? '')) . "\n";
    $md .= "- **Partner's email:** " . ($post['partner_email'] ?? '—') . "\n";
    // Use the real submission time when we have it (a rebuild from the
    // spreadsheet passes it through); otherwise this is a live submission.
    $when = !empty($post['submitted_at']) ? strtotime((string)$post['submitted_at']) : false;
    $md .= "- **Submitted:** " . date('j F Y, g:ia', $when ?: time()) . "\n\n---\n\n";

    $intimacy = sf_intimacy($post);
    foreach ($post as $k => $v) {
        if (!preg_match('/^q\d+$/', $k) && !preg_match('/^q\d+$/', rtrim($k, '[]'))) { continue; }
        $qid = rtrim($k, '[]');
        $val = is_array($v) ? implode(', ', $v) : trim((string)$v);
        if ($val === '') { continue; }
        $other = trim((string)($post[$qid . '_other'] ?? ''));
        if ($other !== '') { $val .= ($val ? ', ' : '') . 'Other: ' . $other; }
        $md .= '**' . ($labels[$qid] ?? $qid) . "**  \n" . $val . "\n\n";
    }
    // any "Other" written where nothing was ticked
    foreach ($post as $k => $v) {
        if (!preg_match('/^(q\d+)_other$/', $k, $m)) { continue; }
        $qid = $m[1];
        if (isset($post[$qid]) || isset($post[$qid . '[]'])) { continue; }
        if (trim((string)$v) === '') { continue; }
        $md .= '**' . ($labels[$qid] ?? $qid) . "**  \nOther: " . trim((string)$v) . "\n\n";
    }

    if ($intimacy) {
        $md .= "---\n\n## Section 7 — Affection, romance and intimacy\n\n";
        foreach ($intimacy as $item => $answer) { $md .= "- $item: **$answer**\n"; }
        $md .= "\n";
    }
    return $md;
}

/** One file per couple, keyed on the two email addresses in either order. */
function sf_couple_key($a, $b) {
    $pair = [strtolower(trim((string)$a)), strtolower(trim((string)$b))];
    sort($pair);
    return sf_slug($pair[0] . '--' . $pair[1]);
}

function sf_save($post) {
    if (empty($post['submitted_at'])) { $post['submitted_at'] = date('c'); }
    $name  = trim(($post['first_name'] ?? '') . ' ' . ($post['last_name'] ?? ''));
    $email = strtolower(trim((string)($post['email'] ?? '')));
    $pemail = strtolower(trim((string)($post['partner_email'] ?? '')));
    if ($email === '') { return; }

    // --- per person ---
    $pslug = sf_slug($name) . '-' . substr(md5($email), 0, 6);
    @file_put_contents(sf_dir('people') . '/' . $pslug . '.md', sf_person_markdown($post));

    // --- remember this person's answers for the couple file ---
    $key   = sf_couple_key($email, $pemail);
    $store = sf_dir('raw') . '/' . $key . '.json';
    $all   = file_exists($store) ? json_decode((string)file_get_contents($store), true) : [];
    if (!is_array($all)) { $all = []; }
    $all[$email] = $post;
    @file_put_contents($store, json_encode($all));
    @chmod($store, 0600);

    sf_write_couple($key, $all);
}

function sf_write_couple($key, $all) {
    $labels = sf_labels();
    $people = array_values($all);
    $names  = array_map(function ($p) { return trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')); }, $people);
    $title  = implode(' & ', array_filter($names)) ?: 'Couple';

    $md  = "# $title — Couple Profile\n\n";
    $md .= "- **Course:** " . ($people[0]['course'] ?? '—') . "\n";
    $md .= "- **Status:** " . (count($people) >= 2 ? "both partners have completed the survey" : "waiting on the second partner") . "\n";

    $taken = [];
    foreach ($people as $i => $p) {
        $when = !empty($p['submitted_at']) ? strtotime((string)$p['submitted_at']) : false;
        $taken[] = ($names[$i] ?: 'Partner ' . ($i + 1)) . ' ' . ($when ? date('j F Y', $when) : 'date unknown');
    }
    $md .= "- **Taken:** " . implode(' · ', $taken) . "\n";
    $md .= "- **This file generated:** " . date('j F Y, g:ia') . "\n\n";

    if (count($people) >= 2) {
        $a = sf_intimacy($people[0]);
        $b = sf_intimacy($people[1]);
        $both = $only = [];
        foreach ($a as $item => $ans) {
            $other = $b[$item] ?? null;
            if ($other === null) { continue; }
            $yes = function ($x) { return $x === 'Yes' || $x === 'Maybe'; };
            if ($yes($ans) && $yes($other)) { $both[] = "$item — {$names[0]}: $ans, {$names[1]}: $other"; }
            elseif ($yes($ans) || $yes($other)) { $only[] = "$item — {$names[0]}: $ans, {$names[1]}: $other"; }
        }
        $md .= "---\n\n## Section 7 — mutual matches\n\nThese are the only intimacy items that may appear in the game: both partners answered Yes or Maybe.\n\n";
        $md .= $both ? ('- ' . implode("\n- ", $both) . "\n") : "_No mutual matches._\n";
        $md .= "\n### Not a match — keep out of the game\n\n";
        $md .= $only ? ('- ' . implode("\n- ", $only) . "\n") : "_None._\n";
        $md .= "\n";
    }

    foreach ($people as $i => $p) {
        $md .= "---\n\n# " . ($names[$i] ?: 'Partner ' . ($i + 1)) . " (" . ($p['partner'] ?? '?') . ")\n\n";
        $body = sf_person_markdown($p);
        $md .= preg_replace('/^#[^\n]*\n/', '', $body);
    }

    @file_put_contents(sf_dir('couples') . '/' . $key . '.md', $md);
    @chmod(sf_dir('couples') . '/' . $key . '.md', 0600);
}
