<?php
/**
 * Office Planner — smoke test.
 *
 * Who:   whoever changes the code, before committing or publishing a new version.
 * How:   php tests/smoke.php          (needs only PHP 8+ with pdo_sqlite — the same PHP that runs the app)
 * What:  copies the app to a temporary folder, starts PHP's built-in web server on it with a
 *        throwaway database, and checks the server-side essentials: pages load, saving works, the
 *        password gates hold, backups/restore work, and an old-format database upgrades cleanly.
 *        Your real data directory is never touched; the temporary copy is deleted afterwards.
 * Report: one PASS/FAIL line per check on the terminal, then a summary. Exit code 0 = all passed,
 *        1 = something failed (usable from a git hook or CI). Nothing is sent or stored anywhere.
 *
 * MAINTAINERS (human or AI): whenever a change adds or alters server-side behaviour — a new page,
 * endpoint, setting, database column, or migration — add a check for it here in the same commit,
 * and keep the whole suite passing. That way this test keeps covering the app as it grows.
 *
 * Not covered (browser behaviour — click through by hand after JavaScript changes):
 *   - Select desk cells by dragging; Free / Occ / Clear; right-click menu acts on the selection.
 *   - Type into a selection + Enter fills all; ⌘/Ctrl+Z / Shift+Z undo / redo.
 *   - One-tap booking in the grid; the occupied-office tint and "Book anyway" confirmation.
 *   - Year planner (double-click a desk name): click / drag / right-click, today frame, undo,
 *     and the grid updating when it closes.
 *   - Room planner (double-click a room name): drag down a column, type, Enter fills every week.
 *   - Long-text tooltip on hover (only when clipped); current-time line in Day / Week view.
 *   - Meeting-space focus (⌘/Ctrl+E); colour-blind palette and dark mode.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run from the command line: php tests/smoke.php\n"); }

$src = dirname(__DIR__);
$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/op-smoke-' . getmypid() . '-' . bin2hex(random_bytes(3));
$log = $tmp . '-server.log';
$server = null;
$base = '';
$jar = [];
$passed = 0;
$failed = 0;

// ---------- helpers ----------

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0775, true);
    foreach (scandir($from) as $f) {
        if (in_array($f, ['.', '..', 'data', '.git', 'tests'], true)) continue;
        $a = "$from/$f"; $b = "$to/$f";
        is_dir($a) ? copy_tree($a, $b) : copy($a, $b);
    }
}
function rm_tree(string $p): void
{
    if (!file_exists($p)) return;
    if (is_dir($p) && !is_link($p)) {
        foreach (scandir($p) as $f) if ($f !== '.' && $f !== '..') rm_tree("$p/$f");
        @rmdir($p);
    } else {
        @unlink($p);
    }
}
function start_server(): void
{
    global $server, $base, $tmp, $log;
    $sock = stream_socket_server('tcp://127.0.0.1:0');           // let the OS pick a free port
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $base = "http://127.0.0.1:$port/";
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $tmp],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    for ($i = 0; $i < 50; $i++) {                                 // wait up to ~5 s for it to answer
        usleep(100000);
        if (@file_get_contents($base . 'login.php') !== false) return;
    }
    fwrite(STDERR, "Could not start PHP's built-in server.\n");
    exit(1);
}
function stop_server(): void
{
    global $server;
    if ($server) { proc_terminate($server); proc_close($server); $server = null; }
}
function http(string $method, string $path, ?string $body = null, string $type = ''): array
{
    global $base, $jar;
    $headers = [];
    if ($type !== '') $headers[] = "Content-Type: $type";
    if ($jar) $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
    ]]);
    $res = @file_get_contents($base . $path, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $status = preg_match('#^HTTP/\S+\s+(\d+)#', $hdrs[0] ?? '', $m) ? (int)$m[1] : 0;
    $location = '';
    foreach ($hdrs as $h) {
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $c)) {
            if ($c[2] === '' || stripos($h, 'expires=Thu, 01 Jan 1970') !== false) unset($jar[$c[1]]);
            else $jar[$c[1]] = $c[2];
        }
        if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
    }
    return ['status' => $status, 'body' => (string)$res, 'location' => $location];
}
function get(string $path): array { return http('GET', $path); }
function post_json(string $path, array $data): array
{
    $r = http('POST', $path, json_encode($data), 'application/json');
    $r['json'] = json_decode($r['body'], true);
    return $r;
}
function post_form(string $path, array $data): array
{
    return http('POST', $path, http_build_query($data), 'application/x-www-form-urlencoded');
}
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  PASS  $name\n"; }
    else { $failed++; echo "  FAIL  $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function section(string $title): void { echo "\n$title\n"; }
function db(): PDO
{
    global $tmp;
    $p = new PDO('sqlite:' . $tmp . '/data/tracker.sqlite');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout = 5000');
    return $p;
}
function config_value(string $const): string
{
    global $tmp;
    $code = 'require ' . var_export($tmp . '/config.php', true) . '; echo ' . $const . ';';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
}
function state(string $date): array
{
    $r = get("api/state.php?start=$date&end=$date");
    return json_decode($r['body'], true) ?: [];
}
function kept_backups(): array
{
    global $tmp;
    return glob($tmp . '/data/backups/tracker-keep-*.sqlite') ?: [];
}

register_shutdown_function(function () use (&$tmp, &$log) {
    stop_server();
    rm_tree($tmp);
    @unlink($log);
});

// ---------- run ----------

echo "Office Planner smoke test (PHP " . PHP_VERSION . ")\n";
copy_tree($src, $tmp);
start_server();
$D = '2030-01-08'; // a fixed future Tuesday, far from any real data

section('Fresh install');
$r = get('index.php?view=week');
check('Tracker loads (week view)', $r['status'] === 200 && strpos($r['body'], 'class="grid"') !== false, "HTTP {$r['status']}");
check('Database created', is_file("$tmp/data/tracker.sqlite"));
check('Data folder protected by .htaccess', is_file("$tmp/data/.htaccess"));
$pdo = db();
check('Demo rooms seeded', (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn() > 0);
$mRoom = (int)$pdo->query("SELECT id FROM rooms WHERE room_type IN ('M','F','T') ORDER BY id LIMIT 1")->fetchColumn();
$desk = (int)$pdo->query('SELECT id FROM desks ORDER BY id LIMIT 1')->fetchColumn();
$version = config_value('APP_VERSION');
$authPw = config_value('AUTH_PASSWORD');
$adminPw = config_value('ADMIN_PASSWORD');

section('Pages');
foreach (['day', 'month', 'monthx'] as $v) {
    $r = get("index.php?view=$v&ref=$D");
    check("Tracker loads ($v view)", $r['status'] === 200 && strpos($r['body'], 'class="grid"') !== false, "HTTP {$r['status']}");
}
$r = get("index.php?view=room&room=$mRoom&ref=$D");
check('Room planner loads', $r['status'] === 200 && strpos($r['body'], 'room-plan') !== false, "HTTP {$r['status']}");
$r = get("index.php?view=room&room=999999");
check('Room planner with unknown room falls back to the grid', $r['status'] === 200 && strpos($r['body'], 'room-plan') === false);
$r = get('admin.php');
check("Setup page loads and shows version $version", $r['status'] === 200 && strpos($r['body'], "Office Planner $version") !== false, "HTTP {$r['status']}");
check('Login page passes straight through while no password is on', get('login.php')['status'] === 302);

section('Saving and reading back');
$rev0 = (int)(json_decode(get('api/revision.php')['body'], true)['revision'] ?? -1);
$r = post_json('api/save_desk_cell.php', ['desk_id' => $desk, 'date' => $D, 'period' => 'am', 'text' => 'smoke']);
check('Desk text saves', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = post_json('api/save_batch_color.php', ['cells' => [['desk_id' => $desk, 'date' => $D, 'period' => 'am']], 'color' => 'red']);
check('Desk colour saves', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = post_json('api/save_booking_cell.php', ['room_id' => $mRoom, 'date' => $D, 'hour' => 10, 'text' => 'MT']);
check('Room booking saves', ($r['json']['ok'] ?? false) === true, $r['body']);
$s = state($D);
$deskRow = array_values(array_filter($s['desks'] ?? [], fn($x) => (int)$x['desk_id'] === $desk && $x['period'] === 'am'))[0] ?? [];
check('Desk text and colour read back', ($deskRow['text'] ?? '') === 'smoke' && ($deskRow['color'] ?? '') === 'red');
$book = array_values(array_filter($s['bookings'] ?? [], fn($x) => (int)$x['room_id'] === $mRoom && (int)$x['hour'] === 10))[0] ?? [];
check('Room booking reads back', ($book['text'] ?? '') === 'MT');
$rev1 = (int)(json_decode(get('api/revision.php')['body'], true)['revision'] ?? -1);
check('Change counter advances (drives live refresh)', $rev1 > $rev0, "$rev0 -> $rev1");
$r = post_json('api/save_booking_cell.php', ['room_id' => $mRoom, 'date' => $D, 'hour' => 99, 'text' => 'x']);
check('Invalid booking is rejected', $r['status'] === 400 && ($r['json']['ok'] ?? true) === false, "HTTP {$r['status']}");

section('Setup actions');
post_form('admin.php', ['action' => 'add_room', 'room_number' => 'SMOKE1', 'room_type' => 'LO', 'desk_count' => 2]);
$newRoom = (int)$pdo->query("SELECT id FROM rooms WHERE room_number = 'SMOKE1'")->fetchColumn();
check('Add a room (general office, 2 desks)', $newRoom > 0
    && (int)$pdo->query("SELECT COUNT(*) FROM desks WHERE room_id = $newRoom")->fetchColumn() === 2);
$r = post_json('api/admin_update.php', ['field' => 'room_has_table', 'room_id' => $newRoom, 'value' => 1]);
check('Give it a meeting table', ($r['json']['ok'] ?? false) === true
    && (int)$pdo->query("SELECT has_table FROM rooms WHERE id = $newRoom")->fetchColumn() === 1, $r['body']);
check('Its table row offers the room planner', strpos(get('index.php?view=week')['body'], 'data-room="' . $newRoom . '"') !== false);

section('Password gates');
$r = post_json('api/admin_update.php', ['field' => 'flag', 'key' => 'auth_enabled', 'value' => 1]);
check('Switch on the tracker password', ($r['json']['ok'] ?? false) === true, $r['body']);
$jar = [];
$r = get('index.php');
check('Tracker redirects to login without the password', $r['status'] === 302 && strpos($r['location'], 'login.php') !== false, "HTTP {$r['status']}");
$r = get('login.php');
check('Login page shows the password form', $r['status'] === 200 && strpos($r['body'], 'name="password"') !== false, "HTTP {$r['status']}");
check('API refuses without the password (401)', get("api/state.php?start=$D&end=$D")['status'] === 401);
post_form('login.php', ['password' => 'definitely-wrong-' . bin2hex(random_bytes(2)), 'next' => 'index.php']);
check('Wrong password is not accepted', !isset($jar['op_auth']));
post_form('login.php', ['password' => $authPw, 'next' => 'index.php']);
check('Correct password gives access', isset($jar['op_auth']) && get('index.php')['status'] === 200);
$r = post_json('api/admin_update.php', ['field' => 'flag', 'key' => 'admin_auth_enabled', 'value' => 1]);
check('Switch on the setup password', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = get('admin.php');
check('Setup page redirects to the setup login', $r['status'] === 302 && strpos($r['location'], 'admin=1') !== false, "HTTP {$r['status']}");
$r = post_json('api/admin_update.php', ['field' => 'flag', 'key' => 'show_M', 'value' => 1]);
check('Setup API refuses without the setup password (403)', $r['status'] === 403, "HTTP {$r['status']}");
post_form('login.php', ['admin' => 1, 'password' => $adminPw, 'next' => 'admin.php']);
check('Setup password gives access', isset($jar['op_admin']) && get('admin.php')['status'] === 200);
post_json('api/admin_update.php', ['field' => 'flag', 'key' => 'admin_auth_enabled', 'value' => 0]);
post_json('api/admin_update.php', ['field' => 'flag', 'key' => 'auth_enabled', 'value' => 0]);
$jar = [];
check('Both gates switched off again', get('index.php')['status'] === 200 && get('admin.php')['status'] === 200);

section('Backups');
$before = count(kept_backups());
post_form('admin.php', ['action' => 'backup_now']);
$kept = kept_backups();
check('"Back up now" writes a kept backup', count($kept) === $before + 1);
check('Backups folder protected by .htaccess', is_file("$tmp/data/backups/.htaccess"));
$snap = basename(end($kept));
$r = get('admin.php?action=download_backup&file=' . urlencode($snap));
check('Backup downloads as a SQLite file', $r['status'] === 200 && strncmp($r['body'], 'SQLite format 3', 15) === 0);
check('Download refuses other files', get('admin.php?action=download_backup&file=' . urlencode('../config.php'))['status'] === 404);
post_json('api/save_booking_cell.php', ['room_id' => $mRoom, 'date' => $D, 'hour' => 10, 'text' => 'CHANGED']);
$beforeRestore = count(kept_backups());
$r = post_form('admin.php', ['action' => 'restore_backup', 'file' => $snap]);
$pdo = null; $pdo = db(); // the file was swapped underneath us
$book = array_values(array_filter(state($D)['bookings'] ?? [], fn($x) => (int)$x['room_id'] === $mRoom && (int)$x['hour'] === 10))[0] ?? [];
check('Restore brings back the snapshot', strpos($r['location'], 'restored=1') !== false && ($book['text'] ?? '') === 'MT', $r['location']);
check('Restore saved the previous state first (undoable)', count(kept_backups()) === $beforeRestore + 1);
$beforePurge = count(kept_backups());
$r = post_json('api/admin_update.php', ['field' => 'purge_old']);
check('Purge runs and takes a backup first', ($r['json']['ok'] ?? false) === true && count(kept_backups()) === $beforePurge + 1, $r['body']);

section('Upgrade from an old database');
stop_server();
$pdo = null;
foreach (['', '-wal', '-shm'] as $sfx) @unlink("$tmp/data/tracker.sqlite$sfx");
$old = new PDO("sqlite:$tmp/data/tracker.sqlite");
$old->exec("CREATE TABLE rooms (id INTEGER PRIMARY KEY AUTOINCREMENT, room_number TEXT, room_type TEXT, capacity INTEGER, sort_order INTEGER);
    CREATE TABLE desks (id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER, seat_index INTEGER, name TEXT);
    CREATE TABLE desk_status (id INTEGER PRIMARY KEY AUTOINCREMENT, desk_id INTEGER, date TEXT, period TEXT, text TEXT, color TEXT);
    CREATE TABLE room_bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER, date TEXT, hour INTEGER, text TEXT);
    CREATE TABLE meta (k TEXT PRIMARY KEY, v INTEGER);
    INSERT INTO rooms (room_number, room_type, sort_order) VALUES ('OLD1', 'SO', 0);
    INSERT INTO desks (room_id, seat_index, name) VALUES (1, 0, 'Legacy Person');
    INSERT INTO desk_status (desk_id, date, period, text, color) VALUES (1, '$D', 'am', '', 'red');
    INSERT INTO room_bookings (room_id, date, hour, text) VALUES (1, '$D', 11, 'OLDBOOK');");
$old = null;
start_server();
$r = get("index.php?view=week&ref=$D");
check('Old database opens with the new code', $r['status'] === 200 && strpos($r['body'], 'Legacy Person') !== false, "HTTP {$r['status']}");
$pdo = db();
$cols = array_column($pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check('Missing columns added (visible, has_table)', in_array('visible', $cols, true) && in_array('has_table', $cols, true));
check('Single office keeps its meeting table', (int)$pdo->query("SELECT has_table FROM rooms WHERE room_number = 'OLD1'")->fetchColumn() === 1);
check('Old bookings still there', strpos(json_encode(state($D)), 'OLDBOOK') !== false);
check('Old demo data not re-seeded into a real database', (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn() === 1);

section('Server log');
stop_server();
$errors = preg_grep('/PHP (Warning|Fatal|Notice|Deprecated|Parse)/', file($log) ?: []);
check('No PHP warnings or errors during the run', !$errors, $errors ? trim(reset($errors)) : '');

echo "\n" . ($failed === 0 ? "All $passed checks passed." : "$failed of " . ($passed + $failed) . " checks FAILED.") . "\n";
exit($failed === 0 ? 0 : 1);
