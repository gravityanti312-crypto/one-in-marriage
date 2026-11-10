<?php
/**
 * ONE in Marriage — the 90-day survey's records.
 *
 * ONE file is the truth: survey-data/submissions.json, above public_html.
 * Every submission ever filed is in it, and everything the team downloads is
 * rebuilt from it, so the pieces can never disagree with each other:
 *
 *   questionnaire-responses.csv            each person's current answers, by program
 *   questionnaire-responses-replaced.csv   older answers a resubmission replaced (the backup)
 *   survey-data/people/*.md                one readable file per person
 *   survey-data/couples/*.md               one per couple, with the Section 7 mutual matches
 *
 * Rules agreed with Jacques on 9 Nov 2026:
 *   - Same person, same program: the newest submission replaces the older one
 *     everywhere. The older one moves to the backup file; nothing is deleted.
 *   - Same person, a different program: both are kept, one under each program.
 *   - Couples are found from first name, last name AND email, not email alone.
 *     Jacques typed the wrong address for himself and he and Marilyn were
 *     never paired, although each had named the other.
 *   - The team can pair or unpair two people by hand, and that holds even when
 *     either of them submits again.
 *
 * Why the spreadsheet is rebuilt rather than appended to: its header row was
 * written once, on the first submission ever. When the Partner A/B question
 * left the form on 7 Nov, every later row slid one column to the right under
 * the wrong headings. Writing every row from the column names makes that
 * impossible.
 */

// Everything the team reads is in Utah time, whatever the server is set to.
date_default_timezone_set('America/Denver');

/* ======================================================================
   Where things live
   ====================================================================== */

function sf_dir($sub = '') {
    $base = dirname(__DIR__) . '/survey-data';
    $path = $sub ? $base . '/' . $sub : $base;
    if (!is_dir($path)) { @mkdir($path, 0700, true); }
    return $path;
}
function sf_csv_path()          { return dirname(__DIR__) . '/questionnaire-responses.csv'; }
function sf_replaced_csv_path() { return dirname(__DIR__) . '/questionnaire-responses-replaced.csv'; }
function sf_inbox_dir()         { return dirname(__DIR__) . '/survey-inbox'; }
function sf_store_path()        { return sf_dir() . '/submissions.json'; }

function sf_slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'unknown';
}
function sf_short($s) { return substr(md5((string)$s), 0, 6); }

/* ======================================================================
   Text, names and programs
   ====================================================================== */

/** Decode entities, turn every kind of dash into "-", squash spaces. */
function sf_clean($s) {
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function sf_email($s) { return strtolower(trim((string)$s)); }
function sf_ts($at)   { $t = strtotime((string)$at); return $t ?: 0; }
function sf_when($at) { $t = sf_ts($at); return $t ? date('j F Y, g:ia', $t) : 'date unknown'; }
function sf_full($first, $last) { return trim(trim((string)$first) . ' ' . trim((string)$last)); }

/** Letters only, lower case, accents folded: "Zoë O'Neil" -> "zoeoneil". */
function sf_name_key($s) {
    $s = strtolower(sf_clean($s));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') { $s = strtolower($t); }
    }
    return preg_replace('/[^a-z]/', '', $s);
}

/** Loose on purpose: Jenn / Jennilyn, Marc / Mark, Chris / Christopher. */
function sf_first_names_match($a, $b) {
    $a = sf_name_key($a); $b = sf_name_key($b);
    if ($a === '' || $b === '') { return false; }
    if ($a === $b) { return true; }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long  = strlen($a) <= strlen($b) ? $b : $a;
    if (strlen($short) >= 3 && strpos($long, $short) === 0) { return true; }
    return strlen($short) >= 4 && levenshtein($a, $b) <= 1;
}

/** Garrett / Garret, and a double-barrelled name containing the other. */
function sf_last_names_match($a, $b) {
    $a = sf_name_key($a); $b = sf_name_key($b);
    if ($a === '' || $b === '') { return false; }
    if ($a === $b) { return true; }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long  = strlen($a) <= strlen($b) ? $b : $a;
    if (strlen($short) >= 4 && strpos($long, $short) !== false) { return true; }
    return strlen($short) >= 5 && levenshtein($a, $b) <= 1;
}

/** The fields that identify someone and the partner they named. */
function sf_who($post) {
    return [
        'first'  => sf_clean($post['first_name'] ?? ''),
        'last'   => sf_clean($post['last_name'] ?? ''),
        'email'  => sf_email($post['email'] ?? ''),
        'pfirst' => sf_clean($post['partner_first_name'] ?? ''),
        'plast'  => sf_clean($post['partner_last_name'] ?? ''),
        'pemail' => sf_email($post['partner_email'] ?? ''),
    ];
}
function sf_name_of($who)    { return sf_full($who['first'], $who['last']); }
function sf_partner_of($who) { return sf_full($who['pfirst'], $who['plast']); }

/**
 * Two submissions are the same person when they share an email and a first
 * name (a couple sharing one inbox are two people), or when the full name is
 * identical — which is how a resubmission under a corrected email still
 * replaces the old one.
 */
function sf_same_person($a, $b) {
    if ($a['email'] !== '' && $a['email'] === $b['email']) {
        if (sf_first_names_match($a['first'], $b['first'])) { return true; }
        if (sf_name_key($a['first']) === '' || sf_name_key($b['first']) === '') { return true; }
    }
    $fa = sf_name_key($a['first']); $la = sf_name_key($a['last']);
    return $fa !== '' && $la !== '' && $fa === sf_name_key($b['first']) && $la === sf_name_key($b['last']);
}

/**
 * When a weekend moves, the people who chose the old date are in the new one.
 * Keys are compared after sf_clean() and lower-casing.
 */
function sf_program_aliases() {
    return [
        // moved on 2 Nov 2026
        'november 5-7, 2026 - sandy, utah' => 'December 3–5, 2026 — Alpine, Utah',
    ];
}
function sf_program($course) {
    $aliases = sf_program_aliases();
    $k = strtolower(sf_clean($course));
    if (isset($aliases[$k])) { return $aliases[$k]; }
    $c = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$course, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $c !== '' ? $c : 'No course chosen';
}

/** [first day, last day] as timestamps, or [0, 0] when the name has no date. */
function sf_program_dates($program) {
    $p = strtolower(sf_clean($program));
    if (preg_match('/([a-z]+)\s+(\d{1,2})(?:\s*-\s*(?:([a-z]+)\s+)?(\d{1,2}))?,?\s+(\d{4})/', $p, $m)) {
        $start = strtotime("$m[1] $m[2] $m[5]");
        $end   = !empty($m[4]) ? strtotime((!empty($m[3]) ? $m[3] : $m[1]) . " $m[4] $m[5]") : $start;
        if ($start) { return [$start, $end ?: $start]; }
    }
    return [0, 0];
}
function sf_program_is_past($program) {
    [$s, $e] = sf_program_dates($program);
    return $s && $e < strtotime('today');
}
/** "Dec 3–5 program" */
function sf_program_short($program) {
    [$s, $e] = sf_program_dates($program);
    if (!$s) { return $program; }
    $d = date('M j', $s);
    if ($e && $e !== $s) { $d .= '–' . (date('M', $e) === date('M', $s) ? date('j', $e) : date('M j', $e)); }
    return $d . ' program';
}
/** Upcoming programs soonest first, then past ones most recent first. */
function sf_sort_programs($programs) {
    $programs = array_values(array_unique($programs));
    $today = strtotime('today');
    $rank = function ($p) use ($today) { [$s, $e] = sf_program_dates($p); return !$s ? 2 : ($e >= $today ? 0 : 1); };
    usort($programs, function ($a, $b) use ($rank) {
        $ra = $rank($a); $rb = $rank($b);
        if ($ra !== $rb) { return $ra <=> $rb; }
        [$sa] = sf_program_dates($a); [$sb] = sf_program_dates($b);
        if ($ra === 0) { return $sa <=> $sb; }
        if ($ra === 1) { return $sb <=> $sa; }
        return strcmp($a, $b);
    });
    return $programs;
}

/* ======================================================================
   The store
   ====================================================================== */

/** Everything that reads or writes the store holds this lock. */
function sf_lock() {
    $fh = @fopen(sf_dir() . '/.lock', 'c');
    if ($fh) { @flock($fh, LOCK_EX); }
    return $fh;
}
function sf_unlock($fh) {
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
}

/** null = no store yet; false = a store we cannot read (never overwrite it). */
function sf_load() {
    $p = sf_store_path();
    if (!is_file($p)) { return null; }
    $d = json_decode((string)@file_get_contents($p), true);
    if (!is_array($d) || !isset($d['subs']) || !is_array($d['subs'])) { return false; }
    return $d + ['pairs' => [], 'unpairs' => []];
}

/** Write to a temporary file and swap it in, keeping the previous copy. */
function sf_store_save($store) {
    $p = sf_store_path();
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) { return false; }
    if (is_file($p)) { @copy($p, $p . '.previous'); @chmod($p . '.previous', 0600); }
    $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json) !== strlen($json)) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $p)) { @unlink($tmp); return false; }
    return true;
}

/** The store, creating it from the old files the first time. Hold the lock. */
function sf_open_store() {
    $s = sf_load();
    if ($s === null) { $s = sf_upgrade(); }
    return $s;
}

/** Strip what the form sends for its own purposes. */
function sf_tidy_post($post) {
    foreach (['company', 'email_confirm', 'partner', 'submitted_at', 'pw'] as $k) { unset($post[$k]); }
    return $post;
}

/**
 * File one submission. Finds which person (within the program) it belongs to,
 * and makes the newest of that person's submissions the active one — by when
 * it was taken, not when it was filed, so filing an old copy by hand can never
 * overwrite newer answers (it did on 9 Nov).
 */
function sf_add(&$store, $post, $at, $source) {
    $post = sf_tidy_post(is_array($post) ? $post : []);
    $who  = sf_who($post);
    if ($who['email'] === '') { return ['ok' => false]; }
    $ts = sf_ts($at);
    if (!$ts) { $ts = time(); $at = date('c', $ts); }

    foreach ($store['subs'] as $s) {   // already filed (a refresh, a retry, a re-run upgrade)
        if (sf_email($s['post']['email'] ?? '') === $who['email'] && sf_ts($s['at']) === $ts) {
            return ['ok' => true, 'id' => $s['id'], 'entry' => $s['entry'], 'dupe' => true];
        }
    }

    $program = sf_program($post['course'] ?? '');
    $entry = null;
    // same email and first name first, then the same full name
    foreach ([true, false] as $byEmail) {
        foreach ($store['subs'] as $s) {
            if ($s['status'] === 'removed' || $s['program'] !== $program) { continue; }
            $sw = sf_who($s['post']);
            $hit = $byEmail
                ? ($sw['email'] === $who['email'] && sf_same_person($sw, $who))
                : sf_same_person($sw, $who);
            if ($hit) { $entry = $s['entry']; break 2; }
        }
    }
    if ($entry === null) { $entry = 'e' . bin2hex(random_bytes(5)); }

    $id = 's' . date('YmdHis', $ts) . bin2hex(random_bytes(3));
    $store['subs'][] = [
        'id' => $id, 'entry' => $entry, 'at' => $at, 'program' => $program,
        'status' => 'active', 'source' => $source, 'filed' => date('c'), 'post' => $post,
    ];
    sf_settle_entry($store, $entry);
    return ['ok' => true, 'id' => $id, 'entry' => $entry, 'dupe' => false];
}

/** Newest submission of an entry is active, the rest are replaced. */
function sf_settle_entry(&$store, $entry) {
    $best = null;
    foreach ($store['subs'] as $i => $s) {
        if ($s['entry'] !== $entry || $s['status'] === 'removed') { continue; }
        if ($best === null || sf_ts($s['at']) >= sf_ts($store['subs'][$best]['at'])) { $best = $i; }
    }
    foreach ($store['subs'] as $i => $s) {
        if ($s['entry'] !== $entry || $s['status'] === 'removed') { continue; }
        $store['subs'][$i]['status'] = ($i === $best) ? 'active' : 'replaced';
    }
}

/** Active submission for each entry, keyed by entry. */
function sf_active($store) {
    $out = [];
    foreach ($store['subs'] as $s) { if ($s['status'] === 'active') { $out[$s['entry']] = $s; } }
    return $out;
}

/** Removed entries, newest submission of each. */
function sf_removed($store) {
    $out = [];
    foreach ($store['subs'] as $s) {
        if ($s['status'] !== 'removed') { continue; }
        if (!isset($out[$s['entry']]) || sf_ts($s['at']) > sf_ts($out[$s['entry']]['at'])) { $out[$s['entry']] = $s; }
    }
    return $out;
}

/* ---------- what the team can do ---------- */

function sf_has_pair($list, $a, $b) {
    foreach ($list as $p) {
        if (($p[0] === $a && $p[1] === $b) || ($p[0] === $b && $p[1] === $a)) { return true; }
    }
    return false;
}

/** Pair two people by hand. Returns an error message, or '' on success. */
function sf_pair(&$store, $a, $b) {
    $active = sf_active($store);
    if ($a === $b) { return 'Pick two different people.'; }
    if (!isset($active[$a], $active[$b])) { return 'One of those people is no longer on the list.'; }
    if ($active[$a]['program'] !== $active[$b]['program']) { return 'Those two are in different programs, so they cannot be paired.'; }
    // each person can only be in one couple
    $store['pairs'] = array_values(array_filter($store['pairs'], function ($p) use ($a, $b) {
        return !in_array($p[0], [$a, $b], true) && !in_array($p[1], [$a, $b], true);
    }));
    $store['unpairs'] = array_values(array_filter($store['unpairs'], function ($p) use ($a, $b) {
        return !(($p[0] === $a && $p[1] === $b) || ($p[0] === $b && $p[1] === $a));
    }));
    $store['pairs'][] = [$a, $b, date('c')];
    return '';
}

/** Split a couple and stop the automatic matching from joining them again. */
function sf_unpair(&$store, $a, $b) {
    $store['pairs'] = array_values(array_filter($store['pairs'], function ($p) use ($a, $b) {
        return !(($p[0] === $a && $p[1] === $b) || ($p[0] === $b && $p[1] === $a));
    }));
    if (!sf_has_pair($store['unpairs'], $a, $b)) { $store['unpairs'][] = [$a, $b, date('c')]; }
}

function sf_remove_entry(&$store, $entry) {
    foreach ($store['subs'] as $i => $s) {
        if ($s['entry'] === $entry) { $store['subs'][$i]['status'] = 'removed'; }
    }
    $store['pairs'] = array_values(array_filter($store['pairs'], function ($p) use ($entry) {
        return $p[0] !== $entry && $p[1] !== $entry;
    }));
}

function sf_restore_entry(&$store, $entry) {
    foreach ($store['subs'] as $i => $s) {
        if ($s['entry'] === $entry) { $store['subs'][$i]['status'] = 'replaced'; }
    }
    sf_settle_entry($store, $entry);
}

/* ======================================================================
   Finding couples
   ====================================================================== */

/** Does x's partner information point at y? */
function sf_link($x, $y) {
    $last = sf_last_names_match($x['plast'], $y['last']);
    return [
        'email' => $x['pemail'] !== '' && $x['pemail'] === $y['email'],
        'names' => $last && sf_first_names_match($x['pfirst'], $y['first']),
        'last'  => $last,
    ];
}

/**
 * Per program: the couples, the people still on their own, and pairs worth a
 * look by the team.
 *
 *   1. Pairs the team made by hand always win.
 *   2. Two people who each point at the other — by email or by both names —
 *      are paired automatically, as long as nobody else is also a candidate.
 *   3. Anything weaker (only one of them points at the other, the surnames
 *      agree but the first names do not, or a three-way tie) is never guessed:
 *      it is offered to the team under "Needs a look".
 */
function sf_match($store) {
    $active = sf_active($store);
    $byProgram = [];
    foreach ($active as $e => $s) { $byProgram[$s['program']][] = $e; }

    $result = [];
    foreach (sf_sort_programs(array_keys($byProgram)) as $program) {
        $entries = $byProgram[$program];
        usort($entries, function ($a, $b) use ($active) { return sf_ts($active[$a]['at']) <=> sf_ts($active[$b]['at']); });
        $who = [];
        foreach ($entries as $e) { $who[$e] = sf_who($active[$e]['post']); }

        $taken = []; $couples = []; $suggest = [];

        foreach ($store['pairs'] as $p) {
            $a = $p[0]; $b = $p[1];
            if (!isset($who[$a], $who[$b]) || isset($taken[$a]) || isset($taken[$b])) { continue; }
            $couples[] = ['a' => $a, 'b' => $b, 'how' => 'manual'];
            $taken[$a] = $taken[$b] = true;
        }

        $strong = [];
        $n = count($entries);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $entries[$i]; $b = $entries[$j];
                if (isset($taken[$a]) || isset($taken[$b]) || sf_has_pair($store['unpairs'], $a, $b)) { continue; }
                $ab = sf_link($who[$a], $who[$b]);
                $ba = sf_link($who[$b], $who[$a]);
                $abAny = $ab['email'] || $ab['names'];
                $baAny = $ba['email'] || $ba['names'];
                if ($abAny && $baAny) { $strong[] = [$a, $b]; }
                elseif ($abAny || $baAny || ($ab['last'] && $ba['last'])) { $suggest[] = [$a, $b]; }
            }
        }
        $count = [];
        foreach ($strong as $p) { $count[$p[0]] = ($count[$p[0]] ?? 0) + 1; $count[$p[1]] = ($count[$p[1]] ?? 0) + 1; }
        foreach ($strong as $p) {
            if ($count[$p[0]] === 1 && $count[$p[1]] === 1) {
                $couples[] = ['a' => $p[0], 'b' => $p[1], 'how' => 'auto'];
                $taken[$p[0]] = $taken[$p[1]] = true;
            } else {
                $suggest[] = $p;
            }
        }
        $suggest = array_values(array_filter($suggest, function ($p) use ($taken) {
            return !isset($taken[$p[0]]) && !isset($taken[$p[1]]);
        }));

        // A is whoever answered first
        foreach ($couples as $k => $c) {
            if (sf_ts($active[$c['b']]['at']) < sf_ts($active[$c['a']]['at'])) {
                $couples[$k]['a'] = $c['b']; $couples[$k]['b'] = $c['a'];
            }
        }
        usort($couples, function ($x, $y) use ($active) {
            return sf_ts($active[$x['a']]['at']) <=> sf_ts($active[$y['a']]['at']);
        });
        $singles = array_values(array_filter($entries, function ($e) use ($taken) { return !isset($taken[$e]); }));

        $result[$program] = ['entries' => $entries, 'couples' => $couples, 'singles' => $singles, 'suggest' => $suggest];
    }
    return $result;
}

/** Plain-English account of why two people were put together. */
function sf_how_text($A, $B, $how) {
    if ($how === 'manual') { return 'Paired by hand by the team.'; }
    $ab = sf_link($A, $B); $ba = sf_link($B, $A);
    $bits = [];
    if ($ab['names'] && $ba['names'])     { $bits[] = 'Names match both ways'; }
    elseif ($ab['names'] || $ba['names']) { $bits[] = 'Names match one way'; }
    if ($ab['email'] && $ba['email']) {
        $bits[] = 'emails match both ways';
    } else {
        foreach ([[$A, $B, $ab], [$B, $A, $ba]] as $t) {
            [$x, $y, $l] = $t;
            if (!$l['email']) {
                $bits[] = $x['first'] . ' gave ' . ($x['pemail'] !== '' ? $x['pemail'] : 'no email') . ' for ' . $y['first'] . ', who used ' . $y['email'];
            }
        }
    }
    return ucfirst(implode(' · ', $bits)) . '.';
}

/** What each of two people said about their partner, for "Needs a look". */
function sf_suggest_text($A, $B) {
    $say = function ($x) {
        $p = sf_partner_of($x);
        return $x['first'] . ' named ' . ($p !== '' ? $p : 'nobody') . ($x['pemail'] !== '' ? ' (' . $x['pemail'] . ')' : '');
    };
    return $say($A) . '. ' . $say($B) . '.';
}

/** For the thank-you page: is this person paired yet, and with whom? */
function sf_partner_status($store, $match, $entry) {
    $active = sf_active($store);
    foreach ($match as $g) {
        foreach ($g['couples'] as $c) {
            if ($c['a'] === $entry || $c['b'] === $entry) {
                $other = $c['a'] === $entry ? $c['b'] : $c['a'];
                return ['paired' => true, 'name' => sf_name_of(sf_who($active[$other]['post']))];
            }
        }
    }
    $s = $active[$entry] ?? null;
    return ['paired' => false, 'name' => $s ? sf_partner_of(sf_who($s['post'])) : ''];
}

/* ======================================================================
   Readable files
   ====================================================================== */

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
                if (preg_match('/name="(q\d+)/', $m[2], $n)) { $map[$n[1]] = html_entity_decode(trim($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
            }
        }
    }
    return $map;
}

/** Section 7 item labels from the page, for answers that arrived without them. */
function sf_item_labels() {
    static $map = null;
    if ($map !== null) { return $map; }
    $map = [];
    $html = @file_get_contents(__DIR__ . '/questionnaire.html');
    if ($html && preg_match_all('/name="(q\d+_\d+)_item" value="([^"]*)"/', $html, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) { $map[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    }
    return $map;
}

/** Question field names in the order they appear on the page. */
function sf_form_order() {
    static $order = null;
    if ($order !== null) { return $order; }
    $order = [];
    $html = @file_get_contents(__DIR__ . '/questionnaire.html');
    if ($html && preg_match_all('/name="(q\d+(?:_\d+)?(?:_other)?)(?:\[\])?"/', $html, $mm)) {
        foreach ($mm[1] as $n) { $order[$n] = true; }
    }
    $order = array_keys($order);
    return $order;
}

/** Section 7 lives in q45/q46/q47 as "<qid>_<index>" => Yes|Maybe|No. */
function sf_intimacy($post) {
    $items = sf_item_labels();
    $out = [];
    foreach ($post as $k => $v) {
        if (preg_match('/^(q4[567])_(\d+)$/', $k, $m)) {
            $label = $post[$k . '_item'] ?? ($items[$k] ?? ($m[1] . ' item ' . $m[2]));
            $out[$label] = trim(is_array($v) ? implode(', ', $v) : (string)$v);
        }
    }
    return $out;
}

/** How many questions were answered (an "Other" box counts). */
function sf_answered($post) {
    $n = 0;
    foreach ($post as $k => $v) {
        if (!preg_match('/^q\d/', (string)$k) || substr($k, -5) === '_item') { continue; }
        $val = is_array($v) ? implode('', $v) : trim((string)$v);
        if ($val !== '') { $n++; }
    }
    return $n;
}

function sf_answers_markdown($post) {
    $labels = sf_labels();
    $md = '';
    foreach ($post as $k => $v) {
        if (!preg_match('/^q\d+$/', $k)) { continue; }
        $val = is_array($v) ? implode(', ', $v) : trim((string)$v);
        if ($val === '') { continue; }
        $other = trim((string)($post[$k . '_other'] ?? ''));
        if ($other !== '') { $val .= ($val ? ', ' : '') . 'Other: ' . $other; }
        $md .= '**' . ($labels[$k] ?? $k) . "**  \n" . $val . "\n\n";
    }
    // an "Other" written where nothing was ticked
    foreach ($post as $k => $v) {
        if (!preg_match('/^(q\d+)_other$/', $k, $m)) { continue; }
        if (isset($post[$m[1]]) || trim((string)$v) === '') { continue; }
        $md .= '**' . ($labels[$m[1]] ?? $m[1]) . "**  \nOther: " . trim((string)$v) . "\n\n";
    }
    $intimacy = sf_intimacy($post);
    if ($intimacy) {
        $md .= "---\n\n## Section 7 — Affection, romance and intimacy\n\n";
        foreach ($intimacy as $item => $answer) { $md .= "- $item: **$answer**\n"; }
        $md .= "\n";
    }
    return $md;
}

/** The header lines every file uses for one person. */
function sf_person_header($s, $partner = '', $letter = '') {
    $post = $s['post']; $w = sf_who($post);
    $md  = "- **Program:** " . $s['program'] . "\n";
    $md .= "- **Submitted:** " . sf_when($s['at']) . " · for the " . $s['program'] . " program\n";
    $course = trim(html_entity_decode((string)($post['course'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($course !== '' && strtolower(sf_clean($course)) !== strtolower(sf_clean($s['program']))) {
        $md .= "- **Course chosen on the form:** $course (now part of this program)\n";
    }
    $md .= "- **Email:** " . ($w['email'] !== '' ? $w['email'] : '—') . "\n";
    $named = sf_partner_of($w);
    $md .= "- **Partner they named:** " . ($named !== '' ? $named : '—') . ($w['pemail'] !== '' ? ' · ' . $w['pemail'] : '') . "\n";
    $md .= "- **Paired with:** " . ($partner !== '' ? $partner . ($letter ? " (this person is partner $letter)" : '') : 'not paired yet') . "\n";
    return $md;
}

function sf_person_markdown($s, $partner = '', $letter = '') {
    $name = sf_name_of(sf_who($s['post']));
    return "# " . ($name !== '' ? $name : 'Survey response') . " — Game Profile\n\n"
         . sf_person_header($s, $partner, $letter) . "\n---\n\n"
         . sf_answers_markdown($s['post']);
}

function sf_couple_markdown($A, $B, $howText) {
    $wa = sf_who($A['post']); $wb = sf_who($B['post']);
    $na = sf_name_of($wa) ?: 'Partner A'; $nb = sf_name_of($wb) ?: 'Partner B';

    $md  = "# $na & $nb — Couple Profile\n\n";
    $md .= "- **Program:** " . $A['program'] . "\n";
    $md .= "- **Status:** both partners have completed the survey\n";
    $md .= "- **Taken:** $na " . sf_when($A['at']) . " · $nb " . sf_when($B['at']) . " · for the " . $A['program'] . " program\n";
    $md .= "- **How they were matched:** $howText\n";
    $md .= "- **This file generated:** " . date('j F Y, g:ia') . "\n\n";

    $a = sf_intimacy($A['post']); $b = sf_intimacy($B['post']);
    $both = $only = [];
    $yes = function ($x) { return $x === 'Yes' || $x === 'Maybe'; };
    foreach ($a as $item => $ans) {
        $other = $b[$item] ?? null;
        if ($other === null) { continue; }
        if ($yes($ans) && $yes($other))     { $both[] = "$item — $na: $ans, $nb: $other"; }
        elseif ($yes($ans) || $yes($other)) { $only[] = "$item — $na: $ans, $nb: $other"; }
    }
    $md .= "---\n\n## Section 7 — mutual matches\n\nThese are the only intimacy items that may appear in the game: both partners answered Yes or Maybe.\n\n";
    $md .= $both ? ('- ' . implode("\n- ", $both) . "\n") : "_No mutual matches._\n";
    $md .= "\n### Not a match — keep out of the game\n\n";
    $md .= $only ? ('- ' . implode("\n- ", $only) . "\n") : "_None._\n";
    $md .= "\n";

    foreach ([[$A, $na, 'A', $nb], [$B, $nb, 'B', $na]] as $t) {
        [$s, $name, $letter, $other] = $t;
        $md .= "---\n\n# $name ($letter)\n\n" . sf_person_header($s, $other, $letter) . "\n" . sf_answers_markdown($s['post']);
    }
    return $md;
}

function sf_single_markdown($S) {
    $w = sf_who($S['post']);
    $name = sf_name_of($w) ?: 'Survey response';
    $named = sf_partner_of($w);
    $md  = "# $name — Couple Profile\n\n";
    $md .= "- **Program:** " . $S['program'] . "\n";
    $md .= "- **Status:** waiting on the second partner" . ($named !== '' ? " ($named)" : '') . "\n";
    $md .= "- **Taken:** $name " . sf_when($S['at']) . " · for the " . $S['program'] . " program\n";
    $md .= "- **This file generated:** " . date('j F Y, g:ia') . "\n\n";
    $md .= "---\n\n# $name\n\n" . sf_person_header($S) . "\n" . sf_answers_markdown($S['post']);
    return $md;
}

/* ======================================================================
   Rebuilding everything the team downloads
   ====================================================================== */

/** Write a folder of files so it holds exactly these, nothing stale left over. */
function sf_write_dir($dir, $files) {
    foreach ($files as $name => $md) {
        $p = $dir . '/' . $name;
        if (!is_file($p) || (string)@file_get_contents($p) !== $md) {
            @file_put_contents($p, $md);
            @chmod($p, 0600);
        }
    }
    foreach (glob($dir . '/*.md') ?: [] as $p) {
        if (!isset($files[basename($p)])) { @unlink($p); }
    }
}

function sf_write_csv($path, $rows) {
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $fh = @fopen($tmp, 'w');
    if (!$fh) { return false; }
    $ok = true;
    foreach ($rows as $r) { $ok = (fputcsv($fh, $r, ',', '"', '') !== false) && $ok; }
    fclose($fh);
    @chmod($tmp, 0600);
    if (!$ok || !@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Spreadsheet rows, every value under its own heading.
 * $which: 'current' (active answers) or 'replaced' (the backup: replaced and removed).
 */
function sf_csv_rows($store, $match, $which) {
    $active = sf_active($store);
    $partner = [];
    foreach ($match as $g) {
        foreach ($g['couples'] as $c) {
            $partner[$c['a']] = [sf_name_of(sf_who($active[$c['b']]['post'])), 'A'];
            $partner[$c['b']] = [sf_name_of(sf_who($active[$c['a']]['post'])), 'B'];
        }
    }
    $subs = array_values(array_filter($store['subs'], function ($s) use ($which) {
        return $which === 'current' ? $s['status'] === 'active' : $s['status'] !== 'active';
    }));
    $order = array_flip(sf_sort_programs(array_column($store['subs'], 'program')));
    usort($subs, function ($a, $b) use ($order) {
        return [$order[$a['program']] ?? 99, sf_ts($a['at'])] <=> [$order[$b['program']] ?? 99, sf_ts($b['at'])];
    });

    $fixed = ['program', 'submitted_at', 'first_name', 'last_name', 'email',
              'partner_first_name', 'partner_last_name', 'partner_email'];
    $fixed = $which === 'current'
        ? array_merge($fixed, ['paired_with', 'partner_letter', 'course', 'date'])
        : array_merge($fixed, ['status', 'course', 'date']);
    $cols = array_merge($fixed, sf_form_order());
    $seen = array_flip($cols);
    $skip = ['company' => 1, 'email_confirm' => 1, 'partner' => 1, 'submitted_at' => 1, 'pw' => 1];
    foreach ($subs as $s) {
        foreach (array_keys($s['post']) as $k) {
            if (!isset($seen[$k]) && !isset($skip[$k]) && substr($k, -5) !== '_item') { $seen[$k] = true; $cols[] = $k; }
        }
    }

    $rows = [$cols];
    foreach ($subs as $s) {
        $row = [];
        foreach ($cols as $c) {
            switch ($c) {
                case 'program':        $v = $s['program']; break;
                case 'submitted_at':   $v = date('Y-m-d H:i', sf_ts($s['at'])); break;
                case 'paired_with':    $v = $partner[$s['entry']][0] ?? ''; break;
                case 'partner_letter': $v = $partner[$s['entry']][1] ?? ''; break;
                case 'status':         $v = $s['status']; break;
                default:
                    $v = $s['post'][$c] ?? '';
                    $v = is_array($v) ? implode(' | ', $v) : trim((string)$v);
            }
            $row[] = $v;
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Rebuild every derived file from the store. Returns the couples found. */
function sf_rebuild($store) {
    $match  = sf_match($store);
    $active = sf_active($store);
    $people = []; $couples = []; $partnerOf = [];

    foreach ($match as $g) {
        foreach ($g['couples'] as $c) {
            $A = $active[$c['a']]; $B = $active[$c['b']];
            $wa = sf_who($A['post']); $wb = sf_who($B['post']);
            $partnerOf[$c['a']] = [sf_name_of($wb), 'A'];
            $partnerOf[$c['b']] = [sf_name_of($wa), 'B'];
            $file = sf_slug(sf_name_of($wa) . ' and ' . sf_name_of($wb)) . '-' . sf_short($c['a'] . $c['b']) . '.md';
            $couples[$file] = sf_couple_markdown($A, $B, sf_how_text($wa, $wb, $c['how']));
        }
        foreach ($g['singles'] as $e) {
            $S = $active[$e];
            $couples[sf_slug(sf_name_of(sf_who($S['post']))) . '-waiting-' . sf_short($e) . '.md'] = sf_single_markdown($S);
        }
    }
    foreach ($active as $e => $s) {
        $file = sf_slug(sf_name_of(sf_who($s['post']))) . '-' . sf_short($e) . '.md';
        $people[$file] = sf_person_markdown($s, $partnerOf[$e][0] ?? '', $partnerOf[$e][1] ?? '');
    }

    sf_write_dir(sf_dir('people'), $people);
    sf_write_dir(sf_dir('couples'), $couples);
    sf_write_csv(sf_csv_path(), sf_csv_rows($store, $match, 'current'));
    sf_write_csv(sf_replaced_csv_path(), sf_csv_rows($store, $match, 'replaced'));
    @touch(sf_dir() . '/.built');
    return $match;
}

/** File names of the person and couple file for an entry, for the download buttons. */
function sf_file_names($store, $match) {
    $active = sf_active($store);
    $out = ['person' => [], 'couple' => []];
    foreach ($active as $e => $s) {
        $out['person'][$e] = sf_slug(sf_name_of(sf_who($s['post']))) . '-' . sf_short($e) . '.md';
    }
    foreach ($match as $g) {
        foreach ($g['couples'] as $c) {
            $f = sf_slug(sf_name_of(sf_who($active[$c['a']]['post'])) . ' and ' . sf_name_of(sf_who($active[$c['b']]['post'])))
               . '-' . sf_short($c['a'] . $c['b']) . '.md';
            $out['couple'][$c['a']] = $out['couple'][$c['b']] = $f;
        }
        foreach ($g['singles'] as $e) {
            $out['couple'][$e] = sf_slug(sf_name_of(sf_who($active[$e]['post']))) . '-waiting-' . sf_short($e) . '.md';
        }
    }
    return $out;
}

/* ======================================================================
   The inbox safety net
   ====================================================================== */

/** Raw copies in survey-inbox that never made it into the store. */
function sf_unfiled($store) {
    $dir = sf_inbox_dir();
    if (!is_dir($dir)) { return []; }
    $have = [];
    foreach ($store['subs'] as $s) { $have[sf_email($s['post']['email'] ?? '') . '|' . sf_ts($s['at'])] = true; }
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || empty($d['post']) || !is_array($d['post'])) { continue; }
        $em   = sf_email($d['post']['email'] ?? '');
        $at   = (string)($d['submitted_at'] ?? '');
        $spam = strpos(basename($f), 'flagged-spam') !== false;
        if (!$spam && isset($have[$em . '|' . sf_ts($at)])) { continue; }
        // a repeat when the same person already got through at this time or later
        $who = sf_who($d['post']);
        $repeat = false;
        foreach ($store['subs'] as $s) {
            if ($s['status'] !== 'removed' && sf_ts($s['at']) >= sf_ts($at) && sf_same_person(sf_who($s['post']), $who)) { $repeat = true; break; }
        }
        $out[] = [
            'file'   => basename($f),
            'name'   => sf_name_of($who),
            'email'  => $em,
            'when'   => $at !== '' ? date('j M Y, g:ia', sf_ts($at)) : '',
            'spam'   => $spam,
            'repeat' => $repeat,
        ];
    }
    return $out;
}

/* ======================================================================
   One-time upgrade from the old files
   ====================================================================== */

/**
 * Build the store from everything already on the server, the first time the
 * new code runs. Backs it all up first. Sources, best first:
 *   - survey-inbox copies (every submission since 7 Nov, full answers)
 *   - survey-data/raw (what the old couple files were built from)
 *   - the old spreadsheet, for anything older — skipping rows written after
 *     7 Nov, which sit one column out; their inbox copies have them right.
 */
function sf_upgrade() {
    $root = dirname(__DIR__);
    $bk = sf_dir('backup-before-upgrade-' . date('Ymd-His'));
    if (is_file(sf_csv_path())) { @copy(sf_csv_path(), $bk . '/questionnaire-responses.csv'); }
    foreach (['people', 'couples', 'raw', 'history'] as $d) {
        $src = $root . '/survey-data/' . $d;
        if (!is_dir($src)) { continue; }
        @mkdir($bk . '/' . $d, 0700, true);
        foreach (glob($src . '/*') ?: [] as $f) { if (is_file($f)) { @copy($f, $bk . '/' . $d . '/' . basename($f)); } }
    }

    $found = [];
    $take = function ($post, $at, $from) use (&$found) {
        if (!is_array($post)) { return; }
        $email = sf_email($post['email'] ?? ''); $ts = sf_ts($at);
        if ($email === '' || !$ts) { return; }
        $k = $email . '|' . $ts;
        if (!isset($found[$k]) || count($post) > count($found[$k]['post'])) {
            $found[$k] = ['post' => $post, 'at' => $at, 'from' => $from];
        }
    };

    foreach (['', '/filed', '/dismissed'] as $sub) {
        foreach (glob(sf_inbox_dir() . $sub . '/*.json') ?: [] as $f) {
            if (strpos(basename($f), 'flagged-spam') !== false) { continue; }
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d)) { $take($d['post'] ?? null, $d['submitted_at'] ?? '', 'saved copy'); }
        }
    }
    foreach (glob($root . '/survey-data/raw/*.json') ?: [] as $f) {
        $all = json_decode((string)@file_get_contents($f), true);
        if (!is_array($all)) { continue; }
        foreach ($all as $post) {
            if (is_array($post) && !empty($post['submitted_at'])) { $take($post, $post['submitted_at'], 'couple data'); }
        }
    }
    $skippedKeys = [];
    if (is_file(sf_csv_path()) && ($fh = @fopen(sf_csv_path(), 'r'))) {
        $head = fgetcsv($fh, null, ',', '"', '');
        $iEm = $head ? array_search('email', $head, true) : false;
        $iAt = $head ? array_search('submitted_at', $head, true) : false;
        while ($head && ($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($r === [null]) { continue; }
            $key = ($iEm !== false && $iAt !== false) ? sf_email($r[$iEm] ?? '') . '|' . sf_ts($r[$iAt] ?? '') : '';
            if (count($r) !== count($head)) { $skippedKeys[] = $key; continue; }
            $row = array_combine($head, $r);
            if (isset($row['partner']) && !in_array(trim((string)$row['partner']), ['A', 'B', ''], true)) { $skippedKeys[] = $key; continue; }
            foreach ($row as $k => $v) {
                if (is_string($v) && strpos($v, ' | ') !== false) { $row[$k] = explode(' | ', $v); }
            }
            $take($row, $row['submitted_at'] ?? '', 'old spreadsheet');
        }
        fclose($fh);
    }
    $lost = 0;
    foreach ($skippedKeys as $k) { if ($k === '' || !isset($found[$k])) { $lost++; } }

    uasort($found, function ($a, $b) { return sf_ts($a['at']) <=> sf_ts($b['at']); });
    $store = ['version' => 2, 'subs' => [], 'pairs' => [], 'unpairs' => [], 'upgrade' => [
        'at' => date('c'), 'found' => count($found), 'unreadable_rows' => $lost,
        'backup' => 'survey-data/' . basename($bk), 'shown' => false,
    ]];
    foreach ($found as $f) { sf_add($store, $f['post'], $f['at'], 'upgrade: ' . $f['from']); }
    if (!sf_store_save($store)) { return false; }
    return $store;
}
