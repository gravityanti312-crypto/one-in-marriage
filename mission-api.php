<?php
/**
 * Mission Control — shared game state for each couple, plus operator actions.
 *
 * Players:  game.html?k=<44 hex>  — the key names the couple and which partner
 *           is holding the phone. Both phones read and write the same game.
 * Operator: operator.html — uses the team password from team-password.php.
 *
 * Data lives ABOVE public_html in mission-data/ so it can never be downloaded:
 *   mission-data/couples/<id>.json   shared state + the two player keys
 *   mission-data/games/<id>.json     a couple's personalized game file (if any)
 *   mission-data/removed/            couples the operator removed (kept, not deleted)
 * Private journals never reach the server; they stay on each person's phone.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function out($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail($msg, $code = 400, $extra = []) { out(array_merge(['ok' => false, 'error' => $msg], $extra), $code); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Use POST.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 6000000) fail('That request is too large.', 413);
$in = json_decode($raw, true);
if (!is_array($in)) fail('Bad request.');
$action = (string)($in['action'] ?? '');

const SLOTS = ['partner_a', 'partner_b'];
const GRADES = ['A', 'B', 'C', 'D', 'F', 'U'];
const BUILTIN_TITLE = 'Mission Impossible: Operation ONE';

/* ---------- storage ---------- */
function md_dir($sub = '') {
    $d = dirname(__DIR__) . '/mission-data' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function couple_path($id) { return md_dir('couples') . '/' . $id . '.json'; }
function game_path($id) { return md_dir('games') . '/' . $id . '.json'; }
function valid_id($id) { return is_string($id) && preg_match('/^[a-f0-9]{12}$/', $id); }

function read_couple($id) {
    if (!valid_id($id)) return null;
    $p = couple_path($id);
    if (!is_file($p)) return null;
    $c = json_decode((string)file_get_contents($p), true);
    return is_array($c) ? $c : null;
}
/** Locked read-modify-write. $fn receives the couple by reference and may throw. */
function with_couple($id, $fn) {
    $p = couple_path($id);
    $fh = @fopen($p, 'c+');
    if (!$fh) fail('The game could not be opened. Please try again.', 500);
    flock($fh, LOCK_EX);
    $c = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($c)) { flock($fh, LOCK_UN); fclose($fh); fail('This game no longer exists.', 404); }
    try { $fn($c); }
    catch (RuntimeException $e) { flock($fh, LOCK_UN); fclose($fh); fail($e->getMessage(), 409, ['state' => public_state($c)]); }
    $c['rev'] = (int)($c['rev'] ?? 0) + 1;
    $c['updated'] = gmdate('c');
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return $c;
}
function create_file($path, $data) {
    $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) fail('Could not save. Please try again.', 500);
    rename($tmp, $path);
}
function need($test, $msg) { if (!$test) throw new RuntimeException($msg); }

/* ---------- dates ---------- */
function zone($tz) { try { return new DateTimeZone($tz ?: 'America/Denver'); } catch (Exception $e) { return new DateTimeZone('America/Denver'); } }
function today_in($tz) { return (new DateTime('now', zone($tz)))->format('Y-m-d'); }
function valid_date($s) { if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false; $d = DateTime::createFromFormat('!Y-m-d', $s); return $d && $d->format('Y-m-d') === $s; }
function days_between($a, $b) { return (int)round((strtotime($a . ' 12:00:00 UTC') - strtotime($b . ' 12:00:00 UTC')) / 86400); }
function started($c) {
    if (empty($c['startDate'])) return false;
    foreach (SLOTS as $s) { $m = $c['commits'][$s] ?? null; if (!$m || ($m['startDate'] ?? '') !== $c['startDate']) return false; }
    return true;
}
/** Progression: the current mission is the first day without a settled grade. Grading a day unlocks the next. */
function current_day($c) { for ($d = 1; $d <= 90; $d++) if (empty($c['grades'][$d])) return $d; return 91; }
function day_info($c) {
    $t = today_in($c['settings']['tz'] ?? 'America/Denver');
    $cur = current_day($c);
    $open = started($c) && $t >= $c['startDate'];
    return ['committed' => started($c), 'started' => $open, 'day' => min(90, $cur), 'cur' => $cur, 'finished' => $cur > 90, 'today' => $t];
}

/* ---------- state shape ---------- */
function blank_state() {
    return ['startDate' => null, 'startBy' => null, 'commits' => ['partner_a' => null, 'partner_b' => null],
        'grades' => [], 'accepted' => [], 'offDuty' => [], 'claims' => [], 'redeemed' => [], 'souvenirs' => [],
        'agreement' => [], 'pauses' => [], 'team' => '', 'villain' => '', 'rev' => 0];
}
function obj($a) { return (is_array($a) && count($a)) ? $a : new stdClass(); }
function public_state($c) {
    return ['id' => $c['id'], 'title' => $c['title'], 'names' => $c['names'], 'settings' => $c['settings'],
        'gameRef' => $c['gameRef'], 'gameHash' => $c['gameHash'] ?? null,
        'startDate' => $c['startDate'], 'startBy' => $c['startBy'], 'commits' => $c['commits'],
        'grades' => obj($c['grades']), 'accepted' => obj($c['accepted']), 'offDuty' => array_values($c['offDuty']),
        'claims' => obj($c['claims']), 'redeemed' => obj($c['redeemed']), 'souvenirs' => obj($c['souvenirs']),
        'agreement' => obj($c['agreement']), 'pauses' => $c['pauses'], 'team' => $c['team'], 'villain' => $c['villain'],
        'rev' => $c['rev'], 'today' => day_info($c)['today']];
}
function clip($s, $n) { $s = trim((string)$s); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }

/* ---------- player ops ---------- */
function apply_op(&$c, $slot, $op) {
    $t = (string)($op['type'] ?? '');
    $now = gmdate('c');
    $d = day_info($c);
    switch ($t) {
        case 'setStart':
        case 'commit':
            need(!started($c), 'Your game has already started. Day 1 stays fixed.');
            if ($t === 'commit') need(!empty($op['agreed']), 'Tick each of your commitments first.');
            $date = (string)($op['date'] ?? '');
            need(valid_date($date), 'Choose a real date.');
            if (empty($c['commits']['partner_a']) && empty($c['commits']['partner_b']) && !empty($op['tz']) && in_array($op['tz'], timezone_identifiers_list(), true)) $c['settings']['tz'] = $op['tz'];
            $today = today_in($c['settings']['tz']);
            need($date >= $today, 'Choose today or a future date.');
            need(days_between($date, $today) <= 365, 'Choose a date within the next year.');
            if ($c['startDate'] !== $date) {
                $c['startDate'] = $date; $c['startBy'] = $slot;
                foreach (SLOTS as $s) if (!empty($c['commits'][$s]) && $c['commits'][$s]['startDate'] !== $date) $c['commits'][$s] = null;
            }
            if ($t === 'commit') $c['commits'][$slot] = ['at' => $now, 'startDate' => $date];
            break;
        case 'uncommit':
            need(!started($c), 'Your game has already started.');
            $c['commits'][$slot] = null;
            break;
        case 'accept':
            need($d['started'], 'Your game hasn\'t started yet.');
            $day = (int)($op['day'] ?? 0);
            need(!$d['finished'] && $day === $d['day'], 'That mission isn\'t unlocked yet.');
            if (empty($c['accepted'][$day])) $c['accepted'][$day] = ['at' => $now, 'by' => $slot, 'small' => !empty($op['small'])];
            $c['offDuty'] = array_values(array_filter($c['offDuty'], function ($x) use ($day) { return (int)$x !== $day; }));
            break;
        case 'version':
            $day = (int)($op['day'] ?? 0);
            need(!empty($c['accepted'][$day]), 'Accept the mission first.');
            $c['accepted'][$day]['small'] = !empty($op['small']);
            break;
        case 'offDuty':
            need($d['started'], 'Your game hasn\'t started yet.');
            $day = (int)($op['day'] ?? 0);
            need(!$d['finished'] && $day === $d['day'], 'Off Duty is only for the current mission.');
            $c['offDuty'] = array_values(array_filter($c['offDuty'], function ($x) use ($day) { return (int)$x !== $day; }));
            if (!empty($op['on'])) $c['offDuty'][] = $day;
            break;
        case 'grade':
            need($d['started'], 'Your game hasn\'t started yet.');
            need(!empty($op['agreed']), 'Choose the grade together.');
            $g = (string)($op['grade'] ?? '');
            need(in_array($g, GRADES, true), 'Choose a whole-day grade.');
            $day = (int)($op['day'] ?? 0);
            if (!empty($c['grades'][$day])) {
                $who = $c['names'][$c['grades'][$day]['by']] ?? 'Your partner';
                throw new RuntimeException($who . ' already settled Mission ' . $day . ' as ' . $c['grades'][$day]['grade'] . '.');
            }
            need(!$d['finished'] && $day === $d['day'], 'Only the current mission can be graded.');
            $c['grades'][$day] = ['grade' => $g, 'at' => $now, 'by' => $slot];
            break;
        case 'claim':
            $day = (int)($op['day'] ?? 0);
            $cid = (string)($op['cardId'] ?? '');
            need(preg_match('/^[A-Za-z0-9_-]{1,40}$/', $cid), 'Choose a reward.');
            need(!empty($c['grades'][$day]) && in_array($c['grades'][$day]['grade'], ['A', 'B'], true), 'Only A and B days earn a daily reward.');
            need(empty($c['claims'][$day]), 'That day\'s reward was already chosen.');
            $c['claims'][$day] = ['cardId' => $cid, 'at' => $now, 'by' => $slot];
            break;
        case 'redeem':
            $key = (string)($op['key'] ?? '');
            need(preg_match('/^(daily:\d{1,2}|once:[A-Za-z0-9_-]{1,40})$/', $key), 'Choose an earned reward.');
            if (strpos($key, 'daily:') === 0) need(!empty($c['claims'][(int)substr($key, 6)]), 'Choose an earned reward.');
            if (empty($c['redeemed'][$key])) $c['redeemed'][$key] = $now;
            break;
        case 'souvenir':
            $id = (string)($op['id'] ?? '');
            need(preg_match('/^[A-Za-z0-9_-]{1,12}$/', $id), 'Choose a mission.');
            $text = clip($op['text'] ?? '', 500);
            if ($text === '') unset($c['souvenirs'][$id]); else $c['souvenirs'][$id] = ['text' => $text, 'at' => $now];
            break;
        case 'agreement':
            $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];
            foreach (['I', 'II', 'III', 'IV', 'V', 'sigA', 'sigB'] as $k) if (array_key_exists($k, $f)) $c['agreement'][$k] = clip($f[$k], strpos($k, 'sig') === 0 ? 80 : 3000);
            if (!empty($c['agreement']['sigA']) && !empty($c['agreement']['sigB'])) { if (empty($c['agreement']['signedOn'])) $c['agreement']['signedOn'] = $d['today']; }
            else unset($c['agreement']['signedOn']);
            break;
        case 'team':    $c['team'] = clip($op['value'] ?? '', 60); break;
        case 'villain': $c['villain'] = clip($op['value'] ?? '', 60); break;
        default:
            throw new RuntimeException('Unknown action.');
    }
}

function player_auth($k) {
    if (!is_string($k) || !preg_match('/^[a-f0-9]{44}$/', $k)) fail('This link isn\'t valid. Ask Jacques for your personal link.', 403);
    $id = substr($k, 0, 12); $secret = substr($k, 12);
    $c = read_couple($id);
    if (!$c) fail('This game link has expired or was removed. Ask Jacques for a new one.', 404);
    foreach (SLOTS as $s) if (hash_equals((string)$c['keys'][$s], $secret)) return [$c, $s];
    usleep(300000);
    fail('This link was replaced with a new one. Ask Jacques for your current link.', 403);
}

/* ---------- operator ---------- */
function operator_auth($pw) {
    $PASSWORD = '';
    if (is_file(__DIR__ . '/team-password.php')) { require __DIR__ . '/team-password.php'; $PASSWORD = isset($TEAM_PASSWORD) ? (string)$TEAM_PASSWORD : ''; }
    if ($PASSWORD === '' || $PASSWORD === 'CHANGE_THIS_PASSWORD') fail('Operator login is not set up: upload team-password.php.', 503);
    if (!is_string($pw) || $pw === '' || !hash_equals($PASSWORD, $pw)) { usleep(600000); fail('That password isn\'t right.', 403); }
}
function link_for($c, $slot) { return $c['id'] . $c['keys'][$slot]; }
function summary($c) {
    $d = day_info($c);
    $graded = count($c['grades']);
    return ['id' => $c['id'], 'title' => $c['title'], 'names' => $c['names'], 'created' => $c['created'], 'gameRef' => $c['gameRef'],
        'settings' => $c['settings'], 'startDate' => $c['startDate'],
        'committed' => ['partner_a' => !empty($c['commits']['partner_a']) && $c['commits']['partner_a']['startDate'] === $c['startDate'],
                        'partner_b' => !empty($c['commits']['partner_b']) && $c['commits']['partner_b']['startDate'] === $c['startDate']],
        'started' => $d['committed'], 'open' => $d['started'], 'day' => $d['day'], 'finished' => $d['finished'], 'graded' => $graded,
        'keys' => ['partner_a' => link_for($c, 'partner_a'), 'partner_b' => link_for($c, 'partner_b')], 'updated' => $c['updated'] ?? $c['created']];
}
function clean_game($g) {
    if (!is_array($g)) fail('That isn\'t a game file.');
    if (!in_array($g['schema_version'] ?? '', ['1.0.0', '2.0.0', '2.1.0'], true)) fail('Unsupported game format. Use the current 2.1.0 file.');
    if (!isset($g['missions']) || !is_array($g['missions']) || count($g['missions']) !== 90) fail('A game file needs exactly 90 daily missions.');
    foreach ($g['missions'] as $i => $m) if (!is_array($m) || (int)($m['day'] ?? 0) !== $i + 1 || empty($m['title'])) fail('Mission ' . ($i + 1) . ' is missing or out of order.');
    if (!isset($g['support_cards']) || !is_array($g['support_cards'])) fail('The game file has no support cards.');
    if (!isset($g['partners']) || !is_array($g['partners']) || count($g['partners']) !== 2) fail('The game file needs two partners.');
    unset($g['delivery']);
    foreach ($g['partners'] as &$p) { unset($p['email'], $p['contact']); } unset($p);
    return $g;
}
function settings_from($in, $g) {
    $basis = $in['basis'] ?? null; $target = $in['target'] ?? null;
    if ($basis === null || $target === null || $target === '') { $basis = null; $target = null; }
    else { if (!in_array($basis, ['A', 'A+B'], true)) fail('Choose A or A+B days.'); $target = (int)$target; if ($target < 1 || $target > 90) fail('Choose a target from 1 to 90.'); }
    $budget = (string)($in['budget'] ?? 'free');
    $plans = ['free'];
    if ($g && !empty($g['budget_plans'])) foreach ($g['budget_plans'] as $p) $plans[] = (string)($p['id'] ?? '');
    if (!in_array($budget, $plans, true)) $budget = 'free';
    return ['basis' => $basis, 'target' => $target, 'budget' => $budget];
}

/* ---------- routes ---------- */
if ($action === 'load' || $action === 'act') {
    list($c, $slot) = player_auth($in['k'] ?? '');
    if ($action === 'act') {
        $op = is_array($in['op'] ?? null) ? $in['op'] : [];
        $c = with_couple($c['id'], function (&$c) use ($slot, $op) { apply_op($c, $slot, $op); });
    }
    $res = ['ok' => true, 'slot' => $slot, 'state' => public_state($c)];
    if ($action === 'load' && $c['gameRef'] !== 'builtin' && ($in['have'] ?? '') !== ($c['gameHash'] ?? '')) {
        $g = json_decode((string)@file_get_contents(game_path($c['id'])), true);
        if (!is_array($g)) fail('This couple\'s game file is missing. Ask Jacques to check it.', 500);
        $res['game'] = $g;
    }
    out($res);
}

if (strpos($action, 'admin_') === 0) {
    operator_auth($in['pw'] ?? '');
    switch ($action) {
        case 'admin_login': out(['ok' => true]);
        case 'admin_list':
            $list = [];
            foreach (glob(md_dir('couples') . '/*.json') ?: [] as $f) { $c = json_decode((string)file_get_contents($f), true); if (is_array($c) && isset($c['id'])) $list[] = summary($c); }
            usort($list, function ($a, $b) { return strcmp($b['created'], $a['created']); });
            out(['ok' => true, 'couples' => $list]);
        case 'admin_create':
            $na = clip($in['names']['partner_a'] ?? '', 30); $nb = clip($in['names']['partner_b'] ?? '', 30);
            if ($na === '' || $nb === '') fail('Add both first names.');
            $g = isset($in['game']) && $in['game'] !== null ? clean_game($in['game']) : null;
            $id = bin2hex(random_bytes(6));
            $c = array_merge(blank_state(), [
                'id' => $id, 'created' => gmdate('c'), 'updated' => gmdate('c'),
                'title' => $g ? clip($g['title'] ?? 'Mission Control', 120) : BUILTIN_TITLE,
                'gameRef' => $g ? 'file' : 'builtin', 'gameHash' => null,
                'names' => ['partner_a' => $na, 'partner_b' => $nb],
                'settings' => array_merge(settings_from($in, $g), ['tz' => 'America/Denver']),
                'keys' => ['partner_a' => bin2hex(random_bytes(16)), 'partner_b' => bin2hex(random_bytes(16))],
                'team' => $g ? clip($g['team']['name'] ?? '', 60) : '',
            ]);
            if ($g) { $json = json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $c['gameHash'] = substr(hash('sha256', $json), 0, 16); if (@file_put_contents(game_path($id), $json) === false) fail('Could not save the game file.', 500); }
            create_file(couple_path($id), $c);
            out(['ok' => true, 'couple' => summary($c)]);
        case 'admin_update':
            $id = (string)($in['id'] ?? '');
            if (!read_couple($id)) fail('That couple was not found.', 404);
            $c = with_couple($id, function (&$c) use ($in) {
                if (isset($in['names'])) foreach (SLOTS as $s) { $v = clip($in['names'][$s] ?? '', 30); need($v !== '', 'Names can\'t be empty.'); $c['names'][$s] = $v; }
                if (array_key_exists('target', $in)) { need(!started($c), 'The target is fixed once both partners have committed.'); $c['settings'] = array_merge($c['settings'], settings_from($in, null)); }
            });
            out(['ok' => true, 'couple' => summary($c)]);
        case 'admin_relink':
            $id = (string)($in['id'] ?? ''); $slot = (string)($in['slot'] ?? '');
            if (!read_couple($id) || !in_array($slot, SLOTS, true)) fail('That couple was not found.', 404);
            $c = with_couple($id, function (&$c) use ($slot) { $c['keys'][$slot] = bin2hex(random_bytes(16)); });
            out(['ok' => true, 'couple' => summary($c)]);
        case 'admin_remove':
            $id = (string)($in['id'] ?? '');
            if (!read_couple($id)) fail('That couple was not found.', 404);
            $stamp = gmdate('Ymd-His');
            rename(couple_path($id), md_dir('removed') . "/$id-$stamp.json");
            if (is_file(game_path($id))) rename(game_path($id), md_dir('removed') . "/$id-$stamp-game.json");
            out(['ok' => true]);
        case 'admin_game':
            $c = read_couple((string)($in['id'] ?? ''));
            if (!$c) fail('That couple was not found.', 404);
            $g = $c['gameRef'] === 'builtin' ? null : json_decode((string)@file_get_contents(game_path($c['id'])), true);
            out(['ok' => true, 'game' => $g, 'names' => $c['names'], 'settings' => $c['settings']]);
    }
}
fail('Unknown action.');
