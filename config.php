<?php
/**
 * Config & DB bootstrap.
 *
 * Default: SQLite, zero setup, file stored in /data/tracker.sqlite.
 * To use MySQL instead: set $USE_SQLITE = false and fill in the credentials below.
 *
 * Room types (the stored code is in parentheses; the single letter is what's shown in the grid):
 *   Offices — one or more desks (each with AM/PM presence cells), plus an optional bookable
 *   meeting table (per-room "has_table" flag; a round-table row with hourly bookings):
 *     S  (SO) - single occupancy office: starts with 1 desk
 *     D  (DO) - double occupancy office: starts with 2 desks
 *     O  (LO) - general office: any number of desks (1+); code "LO" is historical ("large office")
 *     E  (EC) - early-career scientists: like a general office but desks are numbered, starts at 3,
 *               and has its own visibility toggle
 *   Bookable-only rooms — no desks, just hourly bookings:
 *     M  - large meeting room (capacity shown)
 *     F  - focus room / small meeting room (capacity shown)
 *     T  - calling cell (single-person booth)
 */

// =============================================================================
//  PASSWORDS — change these two words to set your passwords, then save the file.
//  Each is only *required* once you switch it on in the admin page ("Access
//  control"); both are off by default. Changing a password signs everyone out of
//  that gate (so it's also how you revoke access).
// =============================================================================

// Shared password for the whole tracker (no usernames). Remembered ~90 days per device.
const AUTH_PASSWORD = 'user';

// Separate password just for the setup ("Setup rooms") page, so it can be protected even when
// the tracker is open to everyone. Re-asked once a day.
const ADMIN_PASSWORD = 'admin';

// =============================================================================
//  DATA LOCATION (SQLite). The database, its WAL side-files, and the backups all
//  live here. The default keeps it inside the app folder, protected from the web
//  by an .htaccess the app writes automatically — which works on Apache.
//  On nginx (where .htaccess is ignored) either add a server rule denying /data/,
//  or move this OUTSIDE the web root, e.g.:
//      const DATA_DIR = __DIR__ . '/../office-planner-data';
//  (Whatever path you choose must be writable by the web-server user.)
// =============================================================================
const DATA_DIR = __DIR__ . '/data';

$USE_SQLITE = true;

// --- MySQL settings (only used if $USE_SQLITE = false) ---
$MYSQL_HOST = 'localhost';
$MYSQL_DB   = 'office_tracker';
$MYSQL_USER = 'root';
$MYSQL_PASS = '';

if ($USE_SQLITE) {
    $dataDir = DATA_DIR;
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    // Deny web access to the whole data directory — the SQLite file, its WAL side-files
    // (-wal/-shm), and the backups. Created here so protection never depends on packaging.
    $dataHt = $dataDir . '/.htaccess';
    if (!is_file($dataHt)) { @file_put_contents($dataHt, "Require all denied\nDeny from all\n"); }
    $pdo = new PDO('sqlite:' . $dataDir . '/tracker.sqlite');
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Concurrency hardening for many simultaneous users:
    //  - WAL lets readers and writers work at the same time (a reader like VACUUM INTO no longer
    //    blocks saves), which removes the main backup/write race.
    //  - busy_timeout makes a briefly-locked write wait and retry instead of failing with SQLITE_BUSY.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL'); // safe with WAL; much faster under load
} else {
    $dsn = "mysql:host=$MYSQL_HOST;dbname=$MYSQL_DB;charset=utf8mb4";
    $pdo = new PDO($dsn, $MYSQL_USER, $MYSQL_PASS);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['IS_SQLITE'] = $USE_SQLITE;

// Append a version query (file modification time) to asset URLs so browsers always fetch
// the current CSS/JS after a deploy instead of serving a stale cached copy.
// --- Automatic database backups (SQLite) ---
// Rotating snapshots of data/tracker.sqlite, taken on page load when the newest is stale.
// Safe under concurrent use (VACUUM INTO makes a consistent copy without locking users out).
const BACKUP_DIR = DATA_DIR . '/backups';
const BACKUP_MIN_INTERVAL = 24 * 3600;   // at most one automatic snapshot per day
const BACKUP_DAILY_DAYS = 7;             // keep one automatic snapshot per day for a week,
const BACKUP_WEEKLY_WEEKS = 3;           // then one per week for 3 more weeks (oldest ~1 month)

function backups_ensure_dir(): void
{
    if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0775, true); }
    $ht = BACKUP_DIR . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
}

function backups_list(): array
{
    if (!is_dir(BACKUP_DIR)) { return []; }
    $files = glob(BACKUP_DIR . '/tracker-*.sqlite') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // newest first
    return $files;
}

function backup_is_kept(string $file): bool
{
    // "Kept" backups (made by hand, or the safety copy before a purge) are never auto-deleted.
    return strpos(basename($file), 'tracker-keep-') === 0;
}

function backups_rotate(): void
{
    $now = time();
    $keptDay = [];   // one automatic snapshot per calendar day for the first week
    $keptWeek = [];  // then one per 7-day bucket for a few more weeks
    foreach (backups_list() as $f) {              // newest first
        if (backup_is_kept($f)) { continue; }     // manual / pre-purge backups persist until deleted by hand
        $age = $now - filemtime($f);
        $days = $age / 86400;
        if ($days <= BACKUP_DAILY_DAYS) {
            $day = date('Y-m-d', filemtime($f));
            if (isset($keptDay[$day])) { @unlink($f); } else { $keptDay[$day] = true; }
        } elseif ($days <= BACKUP_DAILY_DAYS + BACKUP_WEEKLY_WEEKS * 7) {
            $wk = (int)floor($age / (7 * 86400));
            if (isset($keptWeek[$wk])) { @unlink($f); } else { $keptWeek[$wk] = true; }
        } else {
            @unlink($f);                            // older than ~1 month
        }
    }
}

function backup_create(PDO $pdo, bool $keep = false): ?string
{
    global $USE_SQLITE;
    if (!$USE_SQLITE) { return null; } // MySQL would use mysqldump instead
    backups_ensure_dir();
    $prefix = $keep ? 'tracker-keep-' : 'tracker-';
    $base = BACKUP_DIR . '/' . $prefix . date('Y-m-d_H-i-s');
    $dest = $base . '.sqlite';
    for ($i = 1; is_file($dest); $i++) { $dest = $base . '-' . $i . '.sqlite'; } // avoid same-second clash
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($dest)); // consistent snapshot (SQLite 3.27+)
    } catch (Throwable $e) {
        @copy(DATA_DIR . '/tracker.sqlite', $dest);  // fallback for very old SQLite
    }
    backups_rotate();
    return is_file($dest) ? $dest : null;
}

// Is a file a genuine SQLite database with this app's tables? (guards restore/upload)
function backup_is_valid_sqlite(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) { return false; }
    $hdr = fread($fh, 16);
    fclose($fh);
    if (strncmp((string)$hdr, "SQLite format 3\000", 16) !== 0) { return false; } // magic header
    try {
        $t = new PDO('sqlite:' . $path);
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $names = $t->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $t = null;
        foreach (['rooms', 'desks', 'desk_status', 'room_bookings', 'meta'] as $req) {
            if (!in_array($req, $names, true)) { return false; }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// Replace the live database with a snapshot, after first saving the current state as a Kept
// backup so the restore is reversible. Validates the source is a real SQLite db first.
// $pdo is passed by reference and closed before the swap.
function backup_restore(PDO &$pdo, string $srcPath): bool
{
    global $USE_SQLITE;
    if (!$USE_SQLITE || !is_file($srcPath) || !backup_is_valid_sqlite($srcPath)) { return false; }
    backup_create($pdo, true); // reversible: snapshot current state (Kept) before overwriting
    $db = DATA_DIR . '/tracker.sqlite';
    try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {}
    $pdo = null; // release the connection before swapping files
    $tmp = $db . '.restore-tmp';
    if (!@copy($srcPath, $tmp)) { return false; }
    @unlink($db . '-wal'); @unlink($db . '-shm'); // stale side-files of the old db
    $ok = @rename($tmp, $db);                      // atomic swap on the same filesystem
    @unlink($db . '-wal'); @unlink($db . '-shm');
    return $ok;
}

function run_backup_if_due(PDO $pdo): void
{
    global $USE_SQLITE;
    if (!$USE_SQLITE) { return; }
    $list = backups_list();
    if ($list && (time() - filemtime($list[0])) < BACKUP_MIN_INTERVAL) { return; } // recent enough

    // Only one process should snapshot when due (40 users can pass the check at once). A
    // non-blocking lock means whoever gets it does the backup; everyone else skips instantly.
    backups_ensure_dir();
    $lock = @fopen(BACKUP_DIR . '/.lock', 'c');
    if (!$lock) { return; }
    if (flock($lock, LOCK_EX | LOCK_NB)) {
        // Re-check inside the lock in case another process just finished one.
        $list = backups_list();
        if (!$list || (time() - filemtime($list[0])) >= BACKUP_MIN_INTERVAL) {
            backup_create($pdo);
        }
        flock($lock, LOCK_UN);
    }
    fclose($lock);
}

function asset_url(string $rel): string
{
    $path = __DIR__ . '/' . $rel;
    return $rel . (is_file($path) ? '?v=' . filemtime($path) : '');
}

// Never surface PHP warnings/notices or exception details to the browser: on some hosts
// display_errors is On, which would otherwise leak stack traces (SQL, file paths) to users.
// Errors still go to the server log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Max length accepted for any free-text cell value (initials / short notes).
// Keeps a single cell from being stuffed with huge payloads.
const MAX_TEXT_LEN = 100;

// Truncate to at most MAX_TEXT_LEN characters, preferring multibyte-aware slicing
// when the mbstring extension is available, but working correctly without it.
function clip_text(string $s): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, MAX_TEXT_LEN, 'UTF-8');
    }
    // Fallback: trim to a byte length that can't exceed MAX_TEXT_LEN characters,
    // then drop any dangling partial UTF-8 sequence at the end.
    if (strlen($s) <= MAX_TEXT_LEN) {
        return $s;
    }
    $s = substr($s, 0, MAX_TEXT_LEN);
    // Remove a trailing incomplete multibyte sequence, if any.
    while ($s !== '' && (ord($s[strlen($s) - 1]) & 0xC0) === 0x80) {
        $s = substr($s, 0, -1);
    }
    // If the last byte starts a multibyte sequence but is now truncated, drop it too.
    if ($s !== '' && (ord($s[strlen($s) - 1]) & 0xC0) === 0xC0) {
        $s = substr($s, 0, -1);
    }
    return $s;
}

/**
 * Wrap an API handler so any uncaught exception becomes a clean 500 JSON response
 * instead of a stack trace. Call as: api_guard(function () use (...) { ... });
 */
function api_guard(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('office-tracker API error: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'error' => 'server error']);
    }
}

// Hours covered by the hourly grid (9:00 - 17:00 -> slots starting at 9..16)
const BOOKING_HOURS = [9, 10, 11, 12, 13, 14, 15, 16];

function ensure_schema(PDO $pdo, bool $sqlite): void
{
    if ($sqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_number TEXT NOT NULL,
            room_type TEXT NOT NULL,
            capacity INTEGER,
            sort_order INTEGER DEFAULT 0,
            visible INTEGER NOT NULL DEFAULT 1,
            has_table INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS desks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL,
            seat_index INTEGER NOT NULL DEFAULT 0,
            name TEXT DEFAULT '',
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS desk_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            desk_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            period TEXT NOT NULL,
            text TEXT DEFAULT '',
            color TEXT DEFAULT 'none',
            UNIQUE(desk_id, date, period),
            FOREIGN KEY(desk_id) REFERENCES desks(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            hour INTEGER NOT NULL,
            text TEXT DEFAULT '',
            UNIQUE(room_id, date, hour),
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v INTEGER NOT NULL DEFAULT 0
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_number VARCHAR(50) NOT NULL,
            room_type VARCHAR(5) NOT NULL,
            capacity INT NULL,
            sort_order INT DEFAULT 0,
            visible TINYINT NOT NULL DEFAULT 1,
            has_table TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS desks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            seat_index INT NOT NULL DEFAULT 0,
            name VARCHAR(150) DEFAULT '',
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS desk_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            desk_id INT NOT NULL,
            date DATE NOT NULL,
            period VARCHAR(2) NOT NULL,
            text VARCHAR(100) DEFAULT '',
            color VARCHAR(10) DEFAULT 'none',
            UNIQUE KEY uniq_desk_date_period (desk_id, date, period),
            FOREIGN KEY(desk_id) REFERENCES desks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            date DATE NOT NULL,
            hour TINYINT NOT NULL,
            text VARCHAR(100) DEFAULT '',
            UNIQUE KEY uniq_room_date_hour (room_id, date, hour),
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
            k VARCHAR(50) PRIMARY KEY,
            v BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
    }
}

// A single global counter, bumped on every write. Clients poll it cheaply to learn
// whether anything changed before fetching the (larger) cell state.
function ensure_revision_row(PDO $pdo, bool $sqlite): void
{
    if ($sqlite) {
        $pdo->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('revision', 0)");
    } else {
        $pdo->exec("INSERT IGNORE INTO meta (k, v) VALUES ('revision', 0)");
    }
}

function bump_revision(PDO $pdo): int
{
    $pdo->exec("UPDATE meta SET v = v + 1 WHERE k = 'revision'");
    return get_revision($pdo);
}

function get_revision(PDO $pdo): int
{
    $v = $pdo->query("SELECT v FROM meta WHERE k = 'revision'")->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

// Generic on/off settings, stored in the same meta table. Absent key => default.
function get_flag(PDO $pdo, string $key, int $default = 1): int
{
    $stmt = $pdo->prepare("SELECT v FROM meta WHERE k = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (int)$v;
}

function set_flag(PDO $pdo, string $key, int $val): void
{
    if (!empty($GLOBALS['IS_SQLITE'])) {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)");
    } else {
        $stmt = $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    }
    $stmt->execute([$key, $val ? 1 : 0]);
}

// --- Authentication (single shared password, toggled from the admin page) ---

function auth_enabled(PDO $pdo): bool
{
    return get_flag($pdo, 'auth_enabled', 0) === 1;
}

// The value stored in the auth cookie: an HMAC of the password, so the cookie never
// contains the password itself and changing AUTH_PASSWORD invalidates old cookies.
function auth_token(): string
{
    return hash_hmac('sha256', 'office-presence-auth-v1', AUTH_PASSWORD);
}

function is_authed(): bool
{
    return isset($_COOKIE['op_auth']) && hash_equals(auth_token(), (string)$_COOKIE['op_auth']);
}

function auth_set_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('op_auth', auth_token(), [
        'expires'  => time() + 90 * 24 * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
}

function auth_clear_cookie(): void
{
    setcookie('op_auth', '', ['expires' => time() - 3600, 'path' => '/']);
}

// For full pages: redirect to the login screen when auth is on and the visitor isn't authed.
function require_auth_page(PDO $pdo): void
{
    if (auth_enabled($pdo) && !is_authed()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
}

// --- Separate authentication for the setup ("Setup rooms") page ---

function admin_auth_enabled(PDO $pdo): bool
{
    return get_flag($pdo, 'admin_auth_enabled', 0) === 1;
}
// The setup session is bound to the calendar day, so it stops working the next day even if the
// cookie lingers — the setup password is asked again each day it's used.
function admin_auth_token(): string
{
    return hash_hmac('sha256', 'office-planner-admin-auth-v1|' . date('Y-m-d'), ADMIN_PASSWORD);
}
function is_admin_authed(): bool
{
    return isset($_COOKIE['op_admin']) && hash_equals(admin_auth_token(), (string)$_COOKIE['op_admin']);
}
function admin_auth_set_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('op_admin', admin_auth_token(), [
        'expires'  => time() + 24 * 3600, // one day
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
}
// Gate the setup page with its own password when enabled.
function require_admin_page(PDO $pdo): void
{
    if (admin_auth_enabled($pdo) && !is_admin_authed()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'admin.php';
        header('Location: login.php?admin=1&next=' . urlencode($next));
        exit;
    }
}

// For API endpoints: respond 401 JSON instead of redirecting.
function require_auth_api(PDO $pdo): void
{
    if (auth_enabled($pdo) && !is_authed()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'auth required']);
        exit;
    }
}

function seed_demo_data(PDO $pdo): void
{
    $count = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $insRoom = $pdo->prepare("INSERT INTO rooms (room_number, room_type, capacity, sort_order) VALUES (?, ?, ?, ?)");
    $insDesk = $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, ?, ?)");

    // Double occupancy offices
    $insRoom->execute(['1010', 'DO', null, 0]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Anna de Vries']);
    $insDesk->execute([$r, 1, 'Mark Jansen']);

    $insRoom->execute(['1020', 'DO', null, 1]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Tom Visser']);
    $insDesk->execute([$r, 1, 'Lisa Peters']);

    // Single occupancy offices
    $insRoom->execute(['1030', 'SO', null, 2]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Sophie Bakker']);

    $insRoom->execute(['1040', 'SO', null, 3]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Youssef El Amrani']);

    // General office (variable desk count, minimum 1)
    $insRoom->execute(['1050', 'LO', null, 4]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Nadia Haddad']);
    $insDesk->execute([$r, 1, 'Pieter Bos']);
    $insDesk->execute([$r, 2, 'Ravi Menon']);
    $insDesk->execute([$r, 3, 'Emma Wolff']);

    // Early-career scientists office (numbered desks; starts at 3, minimum 1)
    $insRoom->execute(['1060', 'EC', null, 5]);
    $r = (int)$pdo->lastInsertId();
    $insDesk->execute([$r, 0, 'Sara Ilić']);
    $insDesk->execute([$r, 1, 'Jonas Berg']);
    $insDesk->execute([$r, 2, 'Mei Lin']);

    // Large meeting room
    $insRoom->execute(['2000', 'M', 15, 6]);

    // Focus rooms
    $insRoom->execute(['2010', 'F', 4, 7]);
    $insRoom->execute(['2011', 'F', 4, 8]);

    // Calling cell
    $insRoom->execute(['2020', 'T', 1, 9]);

    // Single offices keep their bookable table; one double office gets one too, to show the feature.
    $pdo->exec("UPDATE rooms SET has_table = 1 WHERE room_type = 'SO'");
    $pdo->prepare("UPDATE rooms SET has_table = 1 WHERE room_number = ?")->execute(['1010']);
}

// Add columns to existing databases created by earlier versions (CREATE TABLE IF NOT EXISTS
// won't alter an existing table). Safe to run every load.
function migrate_columns(PDO $pdo, bool $sqlite): void
{
    // Which columns exist on `rooms`?
    if ($sqlite) {
        $existing = array_column($pdo->query("PRAGMA table_info(rooms)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    } else {
        $existing = array_column($pdo->query("SHOW COLUMNS FROM rooms")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }

    if (!in_array('visible', $existing, true)) {
        $pdo->exec($sqlite
            ? "ALTER TABLE rooms ADD COLUMN visible INTEGER NOT NULL DEFAULT 1"
            : "ALTER TABLE rooms ADD COLUMN visible TINYINT NOT NULL DEFAULT 1");
    }

    // has_table: a bookable meeting table can now belong to any office. Existing single offices (SO)
    // keep their table (unless the old global toggle had them off), so behaviour is unchanged.
    if (!in_array('has_table', $existing, true)) {
        $pdo->exec($sqlite
            ? "ALTER TABLE rooms ADD COLUMN has_table INTEGER NOT NULL DEFAULT 0"
            : "ALTER TABLE rooms ADD COLUMN has_table TINYINT NOT NULL DEFAULT 0");
        $soTablesOn = (get_flag($pdo, 'show_SO_table', 1) === 1) ? 1 : 0;
        $pdo->prepare("UPDATE rooms SET has_table = ? WHERE room_type = 'SO'")->execute([$soTablesOn]);
    }
}

ensure_schema($pdo, $USE_SQLITE);
migrate_columns($pdo, $USE_SQLITE);
ensure_revision_row($pdo, $USE_SQLITE);
seed_demo_data($pdo);
