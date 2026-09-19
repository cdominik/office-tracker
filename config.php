<?php
/**
 * Config & DB bootstrap.
 *
 * Default: SQLite, zero setup, file stored in /data/tracker.sqlite.
 * To use MySQL instead: set $USE_SQLITE = false and fill in the credentials below.
 *
 * Room types:
 *   DO - double occupancy office: 2 desks, each with AM/PM presence cells
 *   SO - single occupancy office: 1 desk (AM/PM) + 1 meeting table (hourly bookings)
 *   M  - large meeting room: hourly bookings only
 *   F  - focus room (small meeting room): hourly bookings only
 *   T  - calling cell: hourly bookings only
 */

$USE_SQLITE = true;

// --- MySQL settings (only used if $USE_SQLITE = false) ---
$MYSQL_HOST = 'localhost';
$MYSQL_DB   = 'office_tracker';
$MYSQL_USER = 'root';
$MYSQL_PASS = '';

// --- Access control ---
// The single shared password for the whole tracker (no usernames). Change this word to
// change the password. Whether the password is actually required is toggled from the
// admin page ("Access control"); it's off by default.
const AUTH_PASSWORD = 'user';

if ($USE_SQLITE) {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/tracker.sqlite');
    $pdo->exec('PRAGMA foreign_keys = ON');
} else {
    $dsn = "mysql:host=$MYSQL_HOST;dbname=$MYSQL_DB;charset=utf8mb4";
    $pdo = new PDO($dsn, $MYSQL_USER, $MYSQL_PASS);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['IS_SQLITE'] = $USE_SQLITE;

// Append a version query (file modification time) to asset URLs so browsers always fetch
// the current CSS/JS after a deploy instead of serving a stale cached copy.
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
            visible INTEGER NOT NULL DEFAULT 1
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
            visible TINYINT NOT NULL DEFAULT 1
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

    // Large meeting room
    $insRoom->execute(['2000', 'M', 15, 4]);

    // Focus rooms
    $insRoom->execute(['2010', 'F', 4, 5]);
    $insRoom->execute(['2011', 'F', 4, 6]);

    // Calling cell
    $insRoom->execute(['2020', 'T', 1, 7]);
}

// Add columns to existing databases created by earlier versions (CREATE TABLE IF NOT EXISTS
// won't alter an existing table). Safe to run every load.
function migrate_columns(PDO $pdo, bool $sqlite): void
{
    if ($sqlite) {
        $cols = $pdo->query("PRAGMA table_info(rooms)")->fetchAll(PDO::FETCH_ASSOC);
        $has = false;
        foreach ($cols as $c) { if ($c['name'] === 'visible') { $has = true; break; } }
        if (!$has) {
            $pdo->exec("ALTER TABLE rooms ADD COLUMN visible INTEGER NOT NULL DEFAULT 1");
        }
    } else {
        $has = $pdo->query("SHOW COLUMNS FROM rooms LIKE 'visible'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE rooms ADD COLUMN visible TINYINT NOT NULL DEFAULT 1");
        }
    }
}

ensure_schema($pdo, $USE_SQLITE);
migrate_columns($pdo, $USE_SQLITE);
ensure_revision_row($pdo, $USE_SQLITE);
seed_demo_data($pdo);
