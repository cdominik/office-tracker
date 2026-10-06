<?php
require __DIR__ . '/config.php';
require __DIR__ . '/assets/icons.php';
require_auth_page($pdo);
run_backup_if_due($pdo); // rotating SQLite snapshots (no cron needed)

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Phones are best opened in Day view; wider devices default to Week. Only applies when the
// URL doesn't already specify a view (so an explicit choice is always respected).
function is_mobile_ua(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool)preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $ua);
}

$view = $_GET['view'] ?? (is_mobile_ua() ? 'day' : 'week');
if (!in_array($view, ['day', 'week', 'month', 'monthx', 'room'], true)) {
    $view = 'week';
}

// Room planner ("room" view): one bookable room — an M/F/T room or an office with a meeting
// table — shown as PLAN_WEEKS stacked weeks, for planning repeated meetings.
const PLAN_WEEKS = 13;
$planRoom = null;
if ($view === 'room') {
    $st = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $st->execute([(int)($_GET['room'] ?? 0)]);
    $planRoom = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $bookable = $planRoom && (in_array($planRoom['room_type'], ['M', 'F', 'T'], true)
        || (int)($planRoom['has_table'] ?? 0) === 1);
    if (!$bookable) { $view = 'week'; $planRoom = null; } // unknown / non-bookable room: normal grid
}
$refParam = $_GET['ref'] ?? date('Y-m-d');
try {
    $ref = new DateTime($refParam);
} catch (Exception $e) {
    $ref = new DateTime('today');
}
$todayStr = (new DateTime('today'))->format('Y-m-d');

function start_of_week(DateTime $d): DateTime
{
    $dow = (int)$d->format('N'); // 1 = Monday
    $m = clone $d;
    $m->modify('-' . ($dow - 1) . ' days');
    return $m;
}

// Step one or more days in a direction, skipping weekends (used for day-view nav).
function step_weekday(DateTime $d, int $dir): DateTime
{
    $x = clone $d;
    do {
        $x->modify(($dir > 0 ? '+' : '-') . '1 day');
    } while ((int)$x->format('N') > 5);
    return $x;
}

// "month" is the plain month; "monthx" (the +Month+ view) is the same but padded out to
// whole Mon–Fri weeks. Both use the compact AM/PM layout.
$isMonth = ($view === 'month' || $view === 'monthx');
$extended = ($view === 'monthx');
$monthKey = $isMonth ? $ref->format('Y-m') : '';

// A weekend day shown in Day view (only reachable via "Today" or a direct link): all its
// cells are blocked out, matching the disabled look used in month view.
$dayBlocked = ($view === 'day' && (int)$ref->format('N') > 5);

$days = [];
if ($view === 'day') {
    $days[] = clone $ref;
    $prevRef = step_weekday($ref, -1)->format('Y-m-d'); // skip weekends
    $nextRef = step_weekday($ref, +1)->format('Y-m-d');
    $rangeLabel = $ref->format('l j F Y') . ' (W' . (int)$ref->format('W') . ')';
} elseif ($isMonth) {
    $first = new DateTime($ref->format('Y-m-01'));
    $last = new DateTime($ref->format('Y-m-t'));
    $cursor = clone $first;
    while ($cursor <= $last) {
        if ((int)$cursor->format('N') <= 5) {
            $days[] = clone $cursor;
        }
        $cursor->modify('+1 day');
    }
    // "Extend to full weeks": pad with the weekdays needed to complete the first and
    // last weeks (Mon–Fri). Those padding days belong to the neighbouring months.
    if ($extended && !empty($days)) {
        $dowFirst = (int)$days[0]->format('N');
        $prefix = [];
        for ($k = $dowFirst - 1; $k >= 1; $k--) {
            $d = clone $days[0];
            $d->modify("-$k days");
            $prefix[] = $d;
        }
        $lastDay = $days[count($days) - 1];
        $dowLast = (int)$lastDay->format('N');
        $suffix = [];
        for ($k = 1; $k <= 5 - $dowLast; $k++) {
            $d = clone $lastDay;
            $d->modify("+$k days");
            $suffix[] = $d;
        }
        $days = array_merge($prefix, $days, $suffix);
    }
    $prevRef = (clone $first)->modify('-1 month')->format('Y-m-d');
    $nextRef = (clone $first)->modify('+1 month')->format('Y-m-d');
    $w1 = (int)$days[0]->format('W');
    $w2 = (int)$days[count($days) - 1]->format('W');
    $rangeLabel = $first->format('F Y') . ' (W' . $w1 . ($w1 === $w2 ? '' : '-' . $w2) . ')';
} elseif ($view === 'room') {
    // PLAN_WEEKS weeks (Mon–Fri) starting with the week containing ref; nav steps 4 weeks so
    // consecutive pages overlap and keep context.
    $monday = start_of_week($ref);
    $planWeeks = [];
    for ($w = 0; $w < PLAN_WEEKS; $w++) {
        $wk = [];
        for ($i = 0; $i < 5; $i++) {
            $d = clone $monday;
            $d->modify('+' . ($w * 7 + $i) . ' days');
            $wk[] = $d;
        }
        $planWeeks[] = $wk;
        $days = array_merge($days, $wk);
    }
    $prevRef = (clone $monday)->modify('-28 days')->format('Y-m-d');
    $nextRef = (clone $monday)->modify('+28 days')->format('Y-m-d');
    $lastWk = $planWeeks[PLAN_WEEKS - 1];
    $rangeLabel = $planWeeks[0][0]->format('j M') . ' – ' . $lastWk[4]->format('j M Y')
        . ' (W' . (int)$planWeeks[0][0]->format('W') . '–W' . (int)$lastWk[0]->format('W') . ')';
} else { // week
    $monday = start_of_week($ref);
    for ($i = 0; $i < 5; $i++) {
        $d = clone $monday;
        $d->modify("+$i days");
        $days[] = $d;
    }
    $prevRef = (clone $monday)->modify('-7 days')->format('Y-m-d');
    $nextRef = (clone $monday)->modify('+7 days')->format('Y-m-d');
    $rangeLabel = $days[0]->format('j M') . ' – ' . $days[count($days) - 1]->format('j M Y')
        . ' (W' . (int)$days[0]->format('W') . ')';
}

// True for days shown in month view that fall outside the displayed month (padding days).
$offMonth = function (DateTime $d) use ($monthKey) {
    return $monthKey !== '' && $d->format('Y-m') !== $monthKey;
};

$rangeStart = $days[0]->format('Y-m-d');
$rangeEnd = $days[count($days) - 1]->format('Y-m-d');
$isCurrentRange = ($todayStr >= $rangeStart && $todayStr <= $rangeEnd);

// In month views we drop the hourly booking columns entirely, so each day is just
// two columns (AM / PM). This lets the whole month fit on one screen.
$hourly = !$isMonth;
$colsPerDay = $hourly ? 8 : 2;
$deskColspan = $hourly ? 4 : 1;

// --- Data ---

$rooms = $pdo->query("SELECT * FROM rooms ORDER BY sort_order, room_number")->fetchAll(PDO::FETCH_ASSOC);

// Room-type visibility and per-room visibility (toggled in the admin page).
$typeVisible = [
    'DO' => get_flag($pdo, 'show_DO', 1) === 1,
    'SO' => get_flag($pdo, 'show_SO', 1) === 1,
    'LO' => get_flag($pdo, 'show_LO', 1) === 1,
    'EC' => get_flag($pdo, 'show_EC', 1) === 1,
    'M'  => get_flag($pdo, 'show_M', 1) === 1,
    'F'  => get_flag($pdo, 'show_F', 1) === 1,
    'T'  => get_flag($pdo, 'show_T', 1) === 1,
];
$showAll = (($_COOKIE['op_showall'] ?? '') === '1'); // hidden "show every room" override (Cmd+A)
$focusActive = (($_COOKIE['op_hide_do'] ?? '') === '1') && !$showAll; // meeting-space focus on
$focusMinimal = get_flag($pdo, 'focus_minimal', 0) === 1; // experiment: in focus, hide tabled offices' desks
if (!$showAll) {
    $rooms = array_values(array_filter($rooms, function ($r) use ($typeVisible) {
        return ($typeVisible[$r['room_type']] ?? true) && ((int)($r['visible'] ?? 1) === 1);
    }));
}

$desks = $pdo->query("SELECT * FROM desks ORDER BY room_id, seat_index")->fetchAll(PDO::FETCH_ASSOC);
$desksByRoom = [];
foreach ($desks as $d) {
    $desksByRoom[$d['room_id']][] = $d;
}

// Short display names for narrow/mobile screens: just the first name, but if two people
// share a first name, add the last-name initial ("Anna V." / "Anna K.").
function first_char(string $s): string
{
    if ($s === '') return '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, 1, 'UTF-8');
    return preg_match('/./u', $s, $m) ? $m[0] : $s[0];
}
$firstCount = [];
foreach ($desks as $d) {
    $nm = trim($d['name']);
    if ($nm === '') continue;
    $parts = preg_split('/\s+/', $nm);
    $firstCount[mb_strtolower_safe($parts[0])] = ($firstCount[mb_strtolower_safe($parts[0])] ?? 0) + 1;
}
function mb_strtolower_safe(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function mb_strtoupper_safe(string $s): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}
$deskShort = [];
foreach ($desks as $d) {
    $nm = trim($d['name']);
    if ($nm === '') { continue; }
    $parts = preg_split('/\s+/', $nm);
    $first = $parts[0];
    if (count($parts) > 1 && ($firstCount[mb_strtolower_safe($first)] ?? 0) > 1) {
        $deskShort[$d['id']] = $first . ' ' . mb_strtoupper_safe(first_char(end($parts))) . '.';
    } else {
        $deskShort[$d['id']] = $first;
    }
}

// Emits a desk's name as a full label (desktop) plus a short label (mobile).
function desk_name_label($desk, $deskShort)
{
    $full = $desk['name'] !== '' ? $desk['name'] : '(unassigned)';
    $short = $desk['name'] !== '' ? ($deskShort[$desk['id']] ?? $full) : '—';
    return '<span class="name-full">' . h($full) . '</span>'
        . '<span class="name-short">' . h($short) . '</span>';
}

$statusStmt = $pdo->prepare("SELECT * FROM desk_status WHERE date BETWEEN ? AND ?");
$statusStmt->execute([$rangeStart, $rangeEnd]);
$statusMap = [];
foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $statusMap[$s['desk_id']][$s['date']][$s['period']] = ['text' => $s['text'], 'color' => $s['color']];
}

$bookingStmt = $pdo->prepare("SELECT * FROM room_bookings WHERE date BETWEEN ? AND ?");
$bookingStmt->execute([$rangeStart, $rangeEnd]);
$bookingMap = [];
foreach ($bookingStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $bookingMap[$b['room_id']][$b['date']][(int)$b['hour']] = $b['text'];
}

$typeLabel = ['DO' => 'DO', 'SO' => 'SO', 'M' => 'M', 'F' => 'F', 'T' => 'T'];

// Room identifier shown in the "Room" column: a single-letter type code + the room number.
// Stored codes map to display letters: DO->D, SO->S, LO->O (general office), EC->E; M/F/T unchanged.
function room_ident(array $room): string
{
    // Note: 'LO' is the internal code for the "Office" type (formerly "Large office"); shown as O.
    static $disp = ['DO' => 'D', 'SO' => 'S', 'LO' => 'O', 'EC' => 'E', 'M' => 'M', 'F' => 'F', 'T' => 'T'];
    $type = $disp[$room['room_type']] ?? $room['room_type'];
    $num = trim((string)$room['room_number']);
    return '<span class="room-ident">'
        . '<span class="type-abbr">' . h($type) . '</span>'
        . '<span class="room-num">' . ($num !== '' ? h($num) : '') . '</span>'
        . '</span>';
}

function render_desk_cell($desk, $dateStr, $period, $statusMap, $colspan = 4, $extra = '', $officeRoom = 0)
{
    $cell = $statusMap[$desk['id']][$dateStr][$period] ?? ['text' => '', 'color' => 'none'];
    $colorClass = 'color-' . h($cell['color']);
    $extra = $extra !== '' ? ' ' . $extra : '';
    echo '<td colspan="' . (int)$colspan . '" class="cell desk-cell ' . $colorClass . $extra . '"'
        . ' data-kind="desk" data-row-key="desk-' . (int)$desk['id'] . '"'
        . ' data-desk="' . (int)$desk['id'] . '" data-date="' . h($dateStr) . '" data-period="' . h($period) . '"'
        . ($officeRoom > 0 ? ' data-office-room="' . (int)$officeRoom . '"' : '')
        . ($cell['text'] !== '' ? ' data-tip="' . h($cell['text']) . '"' : '')
        . ' tabindex="0">' . h($cell['text']) . '</td>';
}

// $rowKey: selection row id (default one row per room; the room planner passes one per week).
// $todayStr: when set (room planner), today's slots get classes that draw a thick frame round the day.
function render_booking_cells($days, $roomId, $bookingMap, $occByDate = null, $rowKey = null, $todayStr = '')
{
    $rk = $rowKey !== null ? $rowKey : 'book-' . (int)$roomId;
    $firstHour = BOOKING_HOURS[0];
    $lastHour = BOOKING_HOURS[count(BOOKING_HOURS) - 1];
    foreach ($days as $d) {
        $dateStr = $d->format('Y-m-d');
        foreach (BOOKING_HOURS as $hour) {
            $text = $bookingMap[$roomId][$dateStr][$hour] ?? '';
            $colorClass = $text !== '' ? 'color-red' : '';
            $edgeClass = '';
            if ($hour === 9) { $edgeClass = ' day-start'; }
            elseif ($hour === 13) { $edgeClass = ' pm-start'; }
            // Office-occupied hint (any office's meeting table): if any desk in the office is
            // occupied for this half-day, faintly flag the table hour.
            $period = ($hour <= 12) ? 'am' : 'pm';
            $occ = $occByDate && !empty($occByDate[$dateStr][$period]);
            $todayCls = '';
            if ($todayStr !== '' && $dateStr === $todayStr) {
                $todayCls = ' rp-today' . ($hour === $firstHour ? ' rp-today-start' : '') . ($hour === $lastHour ? ' rp-today-end' : '');
            }
            echo '<td class="cell hour-cell ' . $colorClass . $edgeClass . ($occ ? ' office-occ-hint' : '') . $todayCls . '"'
                . ' data-kind="booking" data-row-key="' . h($rk) . '"'
                . ($occ ? ' data-office-occ="1"' : '')
                . ' data-room="' . (int)$roomId . '" data-date="' . h($dateStr) . '" data-hour="' . (int)$hour . '"'
                . ($text !== '' ? ' data-tip="' . h($text) . '"' : '') // custom hover tooltip shows the full text
                . ' tabindex="0">' . h($text) . '</td>';
        }
    }
}

// Month view: booking rows show a single muted, non-selectable cell per day.
// Mondays get a "week-start" class so a thicker divider marks each week boundary.
function render_booking_disabled($days, $colsPerDay, $offMonth)
{
    foreach ($days as $d) {
        $ws = ((int)$d->format('N') === 1) ? ' week-start' : ' day-start';
        if ($offMonth($d)) $ws .= ' off-month';
        echo '<td colspan="' . (int)$colsPerDay . '" class="booking-off' . $ws . '" aria-hidden="true"></td>';
    }
}

// Day view on a weekend: the whole day's schedule is blocked out (one inert striped cell).
function render_blocked_day($colsPerDay)
{
    echo '<td colspan="' . (int)$colsPerDay . '" class="booking-off" aria-hidden="true"></td>';
}

// A small 12-dot (4×3) glyph shown after a desk name to open its year planner (desktop).
function year_open_icon(): string
{
    $dots = '';
    for ($r = 0; $r < 3; $r++) {
        for ($c = 0; $c < 4; $c++) {
            $dots .= '<circle cx="' . (2 + $c * 4) . '" cy="' . (2 + $r * 4) . '" r="1.15"/>';
        }
    }
    return '<button type="button" class="year-open" title="Open year planner" aria-label="Open year planner">'
        . '<svg viewBox="0 0 16 12" width="15" height="12" fill="currentColor">' . $dots . '</svg></button>';
}

// Glyph after a bookable room's name to open its 3-month room planner (desktop): three stacked
// weeks crossed by one solid column — "the same slot, week after week".
function plan_open_icon(): string
{
    return '<button type="button" class="year-open plan-open" title="Open 3-month room planner" aria-label="Open 3-month room planner">'
        . '<svg viewBox="0 0 16 12" width="15" height="12" fill="currentColor">'
        . '<rect x="0" y="0.6" width="16" height="2.4" rx="1" opacity="0.45"/>'
        . '<rect x="0" y="4.8" width="16" height="2.4" rx="1" opacity="0.45"/>'
        . '<rect x="0" y="9" width="16" height="2.4" rx="1" opacity="0.45"/>'
        . '<rect x="6.2" y="0" width="3.6" height="12" rx="1.2"/>'
        . '</svg></button>';
}

$roomTypeName = ['DO' => 'Double occupancy office', 'SO' => 'Single occupancy office', 'LO' => 'General office',
    'EC' => 'Early-career office', 'M' => 'Large meeting room', 'F' => 'Focus room', 'T' => 'Calling cell'];

// Room planner: the office's "occupied" half-days (any desk red), for the table's faint hint.
$planOcc = null;
if ($view === 'room' && (int)($planRoom['has_table'] ?? 0) === 1) {
    $planOcc = [];
    foreach ($days as $d) {
        $ds = $d->format('Y-m-d');
        $amOcc = false; $pmOcc = false;
        foreach ($desksByRoom[$planRoom['id']] ?? [] as $dk) {
            if (($statusMap[$dk['id']][$ds]['am']['color'] ?? 'none') === 'red') $amOcc = true;
            if (($statusMap[$dk['id']][$ds]['pm']['color'] ?? 'none') === 'red') $pmOcc = true;
        }
        $planOcc[$ds] = ['am' => $amOcc, 'pm' => $pmOcc];
    }
}
$navRoom = ($view === 'room') ? '&room=' . (int)$planRoom['id'] : ''; // keep the room when navigating
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Office Planner</title>
<script src="<?= asset_url('assets/theme.js') ?>"></script>
<link rel="icon" href="<?= asset_url('favicon.ico') ?>" sizes="any">
<link rel="icon" href="<?= asset_url('assets/favicon.svg') ?>" type="image/svg+xml">
<link rel="icon" href="<?= asset_url('assets/favicon-32.png') ?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?= asset_url('assets/favicon-16.png') ?>" sizes="16x16" type="image/png">
<link rel="apple-touch-icon" href="<?= asset_url('assets/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/style.css') ?>">
</head>
<body class="app-tracker view-<?= h($view) ?><?= $isMonth ? ' view-monthlike' : '' ?><?= (($_COOKIE['op_palette'] ?? '') === 'cb') ? ' palette-cb' : '' ?><?= ((($_COOKIE['op_hide_do'] ?? '') === '1') && !$showAll) ? ' hide-do' : '' ?><?= $showAll ? ' show-all' : '' ?>">

<div class="topbar">
    <h1>Office Planner</h1>
    <div class="topbar-right">
        <span class="palette-wrap">
            <button type="button" class="palette-disc" id="paletteBtn" title="Switch colour palette" aria-label="Switch colour palette"></button>
            <span class="palette-toast" id="paletteToast" role="status"></span>
        </span>
        <span class="palette-wrap">
            <button type="button" class="theme-disc" id="themeBtn" title="Switch light / dark" aria-label="Switch light or dark theme"></button>
            <span class="palette-toast" id="themeToast" role="status"></span>
        </span>
        <a class="btn btn-ghost" id="adminLink" href="admin.php">Setup rooms</a>
    </div>
</div>

<?php if ($showAll): ?>
<div class="showall-badge" id="showAllBadge" title="Showing every room, ignoring all visibility settings — click or press ⌘/Ctrl+A to exit">Showing all rooms ✕</div>
<?php endif; ?>

<div class="ctx-menu" id="ctxMenu" hidden></div>

<div class="year-overlay" id="yearOverlay" hidden>
    <div class="year-panel" role="dialog" aria-modal="true" aria-label="Year planner">
        <div class="year-head">
            <span class="year-title" id="yearTitle">—</span>
            <span class="year-tools">
                <button type="button" class="color-btn color-green" id="yearFree">Free</button>
                <button type="button" class="color-btn color-red" id="yearOcc">Occ</button>
                <button type="button" class="color-btn color-clear" id="yearClear">Clear</button>
            </span>
            <span class="year-nav">
                <button type="button" class="btn btn-small" id="yearPrev" title="Previous year (⌘/Ctrl+←)">&larr;</button>
                <span class="year-label" id="yearLabel">—</span>
                <button type="button" class="btn btn-small" id="yearNext" title="Next year (⌘/Ctrl+→)">&rarr;</button>
            </span>
            <button type="button" class="btn btn-small year-close" id="yearClose" title="Close (Esc — first Esc clears a selection)">&times;</button>
        </div>
        <p class="year-hint">Click a weekday to cycle occ → free → clear. Drag or Shift-click to select a range, then Free / Occ / Clear. Right-click for occ / free / half-day options. Weekends are greyed.</p>
        <div class="year-grid" id="yearGrid"></div>
    </div>
</div>

<div class="frozen-bars">
<div class="controlbar">
    <div class="nav-group">
        <a class="btn nav-btn" href="?view=<?= h($view) ?><?= $navRoom ?>&ref=<?= h($prevRef) ?>" title="Previous — ⌘/Ctrl+←">&larr;</a>
        <a class="btn nav-btn today-btn <?= $isCurrentRange ? 'is-current' : '' ?>" href="?view=<?= h($view) ?><?= $navRoom ?>&ref=<?= h($todayStr) ?>" title="Jump to today — ⌘/Ctrl+. or Home">Today</a>
        <a class="btn nav-btn" href="?view=<?= h($view) ?><?= $navRoom ?>&ref=<?= h($nextRef) ?>" title="Next — ⌘/Ctrl+→">&rarr;</a>
    </div>
    <div class="range-label"><span><?= h($rangeLabel) ?></span></div>
    <?php if ($view === 'room'): ?>
    <div class="view-group">
        <a class="btn year-close plan-close" id="planClose" href="?view=week&ref=<?= h($ref->format('Y-m-d')) ?>" title="Close — back to the grid (Esc; first Esc clears a selection)" aria-label="Close">&times;</a>
    </div>
    <?php else: ?>
    <div class="view-group">
        <a class="btn view-btn <?= $view === 'day' ? 'active' : '' ?>" href="?view=day&ref=<?= h($ref->format('Y-m-d')) ?>" title="Day — ⌘/Ctrl+1"><span class="v-full">Day</span><span class="v-short">D</span></a>
        <a class="btn view-btn <?= $view === 'week' ? 'active' : '' ?>" href="?view=week&ref=<?= h($ref->format('Y-m-d')) ?>" title="Week — ⌘/Ctrl+2"><span class="v-full">Week</span><span class="v-short">W</span></a>
        <a class="btn view-btn view-month <?= $view === 'month' ? 'active' : '' ?>" href="?view=month&ref=<?= h($ref->format('Y-m-d')) ?>" title="Month — ⌘/Ctrl+3"><span class="v-full">Month</span><span class="v-short">M</span></a>
        <a class="btn view-btn view-month <?= $view === 'monthx' ? 'active' : '' ?>" href="?view=monthx&ref=<?= h($ref->format('Y-m-d')) ?>" title="Month padded out to full Mon–Fri weeks — ⌘/Ctrl+4"><span class="v-full">+Month+</span><span class="v-short">+M+</span></a>
    </div>
    <?php endif; ?>
</div>

<div class="toolbar">
    <?php if ($view === 'room'):
        $cap = (int)($planRoom['capacity'] ?? 0); ?>
    <span class="rp-title"><?= room_ident($planRoom) ?> <span class="rp-type"><?= h($roomTypeName[$planRoom['room_type']] ?? '') ?><?= in_array($planRoom['room_type'], ['DO', 'SO', 'LO', 'EC'], true) ? ' · meeting table' : '' ?><?= ($cap > 0 && in_array($planRoom['room_type'], ['M', 'F', 'T'], true)) ? ' · ' . $cap . ($cap === 1 ? ' seat' : ' seats') : '' ?></span></span>
    <?php endif; ?>
    <span class="toolbar-label">Desks:</span>
    <button type="button" class="color-btn color-green" data-color="green" title="Mark selected desks free — ⌘/Ctrl+F">Free</button>
    <button type="button" class="color-btn color-red" data-color="red" title="Mark selected desks occupied — ⌘/Ctrl+O">Occ</button>
    <button type="button" class="color-btn color-clear" data-color="none" title="Clear colour — ⌘/Ctrl+C">Clear</button>
    <button type="button" class="you-chip" id="youChip" title="Your initials for one-tap room booking — click to change"><span class="you-label">You: </span><span id="youInitials">—</span></button>
    <span class="palette-wrap do-wrap">
        <button type="button" class="do-disc" id="doToggle" title="Focus the view on finding a meeting space — hides the desk offices (⌘/Ctrl+E; pinch vertically on mobile)" aria-label="Focus on meeting space" aria-pressed="false"><svg class="mtg-icon" viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="3.4" r="1.8"/><circle cx="19.4" cy="7.7" r="1.8"/><circle cx="19.4" cy="16.3" r="1.8"/><circle cx="12" cy="20.6" r="1.8"/><circle cx="4.6" cy="16.3" r="1.8"/><circle cx="4.6" cy="7.7" r="1.8"/></svg></button>
        <span class="palette-toast" id="doToast" role="status"></span>
    </span>
    <span class="toolbar-hint" id="toolbarHint"><?php if ($view === 'room'): ?>Click or drag to select slots — drag down a column for the same slot every week; ⌘/Ctrl-click adds single slots. Then type (e.g. initials) and press Enter to fill them all. Delete clears; Esc clears the selection, then closes.<?php else: ?>Select desk cells (drag, or Shift to extend), then Free / Occ / Clear. Tap a meeting-room or table slot to book it with your initials; tap your own booking again to clear it.<?php endif; ?></span>
    <span class="help-dot" tabindex="0" aria-label="Shortcuts and tips">?<span class="help-tip" role="tooltip"><b class="ht-title">Shortcuts &amp; tips</b><span class="ht-sec"><span class="ht-h">Editing</span>Drag, Shift or ⌘/Ctrl-click to select cells<br>⌘/Ctrl+F / +O / +C — free / occ / clear<br>Type into a selection, then Enter — fill all<br>Arrows &amp; Tab — move between cells<br>⌘/Ctrl+Z / +Shift+Z — undo / redo<br>Double-click a booking slot to edit it<br>Right-click a desk cell for Free / Occ / Clear</span><span class="ht-sec"><span class="ht-h">Navigate</span>⌘/Ctrl+1 / 2 / 3 / 4 — Day / Week / Month / +Month+<br>⌘/Ctrl+← / → — previous / next<br>⌘/Ctrl+. (or Home) — today<br>⌘/Ctrl+↑ / ↓ — scroll the grid</span><span class="ht-sec"><span class="ht-h">View</span>Double-click a desk name — open its year planner<br>Double-click a room name — open its 3-month room planner<br>⌘/Ctrl+E — focus on meeting space (hide desk offices)<br>⌘/Ctrl+A — show every room (ignore hide settings)<br>Two-colour disc — colour-blind palette</span></span></span>
</div>
</div>

<div class="grid-wrap">
<?php if ($view === 'room'): ?>
<table class="grid room-plan">
    <colgroup>
        <col class="col-office">
        <col class="col-name">
        <?php for ($i = 0; $i < 5 * count(BOOKING_HOURS); $i++): ?><col class="col-hour"><?php endfor; ?>
    </colgroup>
    <thead>
    <tr>
        <th class="col-office sticky-col" rowspan="2">Week</th>
        <th class="col-name sticky-col sticky-col-2" rowspan="2">Dates</th>
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $dn): ?>
            <th colspan="<?= count(BOOKING_HOURS) ?>" class="day-header day-start"><?= $dn ?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php for ($k = 0; $k < 5; $k++): foreach (BOOKING_HOURS as $hour):
            $edge = $hour === 9 ? ' day-start' : ($hour === 13 ? ' pm-start' : ''); ?>
            <th class="hour-label<?= $edge ?>"><?= $hour ?></th>
        <?php endforeach; endfor; ?>
    </tr>
    </thead>
    <tbody>
    <?php
    $thisMonday = start_of_week(new DateTime('today'))->format('Y-m-d');
    foreach ($planWeeks as $wk):
        $wkMon = $wk[0]->format('Y-m-d'); ?>
        <tr class="<?= $wkMon === $thisMonday ? 'rp-this-week' : '' ?>">
            <td class="col-office sticky-col rp-week">W<?= (int)$wk[0]->format('W') ?></td>
            <td class="col-name sticky-col sticky-col-2 rp-dates"><?= h($wk[0]->format('j M')) ?> – <?= h($wk[4]->format('j M')) ?></td>
            <?php render_booking_cells($wk, (int)$planRoom['id'], $bookingMap, $planOcc, 'plan-' . $wkMon, $todayStr); ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<table class="grid">
    <colgroup>
        <col class="col-office">
        <col class="col-name">
        <?php foreach ($days as $d): for ($i = 0; $i < $colsPerDay; $i++): ?>
            <col class="<?= $hourly ? 'col-hour' : 'col-ampm' ?>">
        <?php endfor; endforeach; ?>
    </colgroup>
    <thead>
    <tr>
        <th class="col-office sticky-col" rowspan="2">Room</th>
        <th class="col-name sticky-col sticky-col-2" rowspan="2">Desk / Table</th>
        <?php foreach ($days as $d):
            $edge = $hourly ? ' day-start' : ((int)$d->format('N') === 1 ? ' week-start' : ' day-start');
            if ($offMonth($d)) $edge .= ' off-month';
        ?>
            <th colspan="<?= $colsPerDay ?>" class="day-header<?= $edge ?> <?= $d->format('Y-m-d') === $todayStr ? 'today-col' : '' ?>">
                <?= h($d->format('D')) ?> <span class="date-sub"><?= h($d->format('j M')) ?></span>
            </th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php if ($hourly): ?>
            <?php foreach ($days as $d): foreach (BOOKING_HOURS as $hour):
                $edge = $hour === 9 ? ' day-start' : ($hour === 13 ? ' pm-start' : '');
            ?>
                <th class="hour-label<?= $edge ?>"><?= $hour ?></th>
            <?php endforeach; endforeach; ?>
        <?php else: ?>
            <?php foreach ($days as $d):
                $amEdge = ((int)$d->format('N') === 1) ? ' week-start' : ' day-start';
                $om = $offMonth($d) ? ' off-month' : '';
            ?>
                <th class="ampm-label<?= $amEdge . $om ?>">AM</th>
                <th class="ampm-label<?= $om ?>">PM</th>
            <?php endforeach; ?>
        <?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php
    // Faint identity tint applied to the two left label cells of bookable rooms.
    $tintClass = ['M' => 'tint-m', 'F' => 'tint-f', 'T' => 'tint-t'];
    foreach ($rooms as $room):
        $roomId = $room['id'];
        $type = $room['room_type'];
        $tint = $tintClass[$type] ?? '';
        // Vertical dividers. Week/day view: thick day line before AM (start of day),
        // medium line between AM/PM. Month view: thick week line on Mondays, medium day
        // line before AM (between days), and a thin default line between AM and PM.
        // Padding days (outside the displayed month) also get an "off-month" mute class.
        $amExtra = function ($d) use ($hourly, $offMonth) {
            $c = $hourly ? 'day-start' : ((int)$d->format('N') === 1 ? 'week-start' : 'day-start');
            if ($offMonth($d)) $c .= ' off-month';
            return $c;
        };
        $pmExtra = function ($d) use ($hourly, $offMonth) {
            $c = $hourly ? 'pm-start' : '';
            if ($offMonth($d)) $c = trim($c . ' off-month');
            return $c;
        };
    ?>
        <?php if (in_array($type, ['DO', 'SO', 'LO', 'EC'], true)):
            $roomDesks = $desksByRoom[$roomId] ?? [];
            usort($roomDesks, fn($a, $b) => (int)$a['seat_index'] <=> (int)$b['seat_index']);
            $nDesks = count($roomDesks);
            $isEC = ($type === 'EC');
            $hasTable = ((int)($room['has_table'] ?? 0) === 1);
            // Experiment: in meeting-space focus, a tabled office can show just its table (desks hidden),
            // relying on the occupied hint. Rendered server-side so the room number lands on the table row.
            $renderDesks = !($focusActive && $focusMinimal && $hasTable);
            $rowspan = ($renderDesks ? max(1, $nDesks) : 0) + ($hasTable ? 1 : 0);
            if ($rowspan < 1) { $rowspan = 1; }
            // Meeting-space focus hides desk-only offices; an office WITH a table stays visible,
            // because you need its desks' presence to judge whether the table may be taken.
            $focusHide = $hasTable ? '' : 'room-do';
            // The table's "office occupied" hint is on if ANY desk is occupied (red) that half-day.
            $occByDate = [];
            if ($hasTable) {
                foreach ($days as $d) {
                    $ds = $d->format('Y-m-d');
                    $amOcc = false; $pmOcc = false;
                    foreach ($roomDesks as $dk) {
                        if (($statusMap[$dk['id']][$ds]['am']['color'] ?? 'none') === 'red') $amOcc = true;
                        if (($statusMap[$dk['id']][$ds]['pm']['color'] ?? 'none') === 'red') $pmOcc = true;
                    }
                    $occByDate[$ds] = ['am' => $amOcc, 'pm' => $pmOcc];
                }
            }
            $firstRow = true;
        ?>
            <?php if ($renderDesks): foreach ($roomDesks as $i => $desk): ?>
                <tr class="<?= $focusHide ?> <?= $firstRow ? 'room-start' : '' ?>">
                    <?php if ($firstRow): ?>
                        <td class="col-office sticky-col <?= $tint ?>" rowspan="<?= $rowspan ?>"><?= room_ident($room) ?></td>
                    <?php endif; ?>
                    <td class="col-name sticky-col sticky-col-2 desk-name-cell <?= $tint ?>" data-desk="<?= (int)$desk['id'] ?>" data-desk-name="<?= h($desk['name'] !== '' ? $desk['name'] : 'Desk ' . ((int)$desk['seat_index'] + 1)) ?>">
                        <span class="pictogram"><?= icon_for_room_line('desk') ?></span>
                        <span class="line-label"<?= $desk['name'] !== '' ? ' data-tip="' . h($desk['name']) . '"' : '' ?>><?php if ($isEC): ?><span class="desk-num"><?= $i + 1 ?></span><?php endif; ?><?= desk_name_label($desk, $deskShort) ?></span>
                        <?= year_open_icon() ?>
                    </td>
                    <?php if ($dayBlocked): render_blocked_day($colsPerDay); else: foreach ($days as $d):
                        $dateStr = $d->format('Y-m-d');
                        render_desk_cell($desk, $dateStr, 'am', $statusMap, $deskColspan, $amExtra($d), $roomId);
                        render_desk_cell($desk, $dateStr, 'pm', $statusMap, $deskColspan, $pmExtra($d), $roomId);
                    endforeach; endif; ?>
                </tr>
                <?php $firstRow = false; ?>
            <?php endforeach; endif; ?>
            <?php if ($hasTable): ?>
            <tr class="<?= $focusHide ?> <?= $firstRow ? 'room-start' : '' ?>">
                <?php if ($firstRow): ?>
                    <td class="col-office sticky-col <?= $tint ?>" rowspan="<?= $rowspan ?>"><?= room_ident($room) ?></td>
                <?php endif; ?>
                <td class="col-name sticky-col sticky-col-2 room-name-cell <?= $tint ?>" data-room="<?= (int)$roomId ?>">
                    <span class="pictogram"><?= icon_for_room_line('table') ?></span>
                    <?= plan_open_icon() ?>
                </td>
                <?php
                if ($dayBlocked) { render_blocked_day($colsPerDay); }
                elseif ($hourly) { render_booking_cells($days, $roomId, $bookingMap, $occByDate); }
                else { render_booking_disabled($days, $colsPerDay, $offMonth); }
                ?>
            </tr>
            <?php $firstRow = false; ?>
            <?php endif; ?>
            <?php if ($renderDesks && $nDesks === 0 && !$hasTable): // safety: office with neither desks nor a table ?>
                <tr class="<?= $focusHide ?> room-start">
                    <td class="col-office sticky-col <?= $tint ?>"><?= room_ident($room) ?></td>
                    <td class="col-name sticky-col sticky-col-2 <?= $tint ?>"><span class="line-label">—</span></td>
                    <?php if ($dayBlocked) { render_blocked_day($colsPerDay); } else { render_booking_disabled($days, $colsPerDay, $offMonth); } ?>
                </tr>
            <?php endif; ?>

        <?php else: // M, F, T ?>
            <tr class="room-start">
                <td class="col-office sticky-col <?= $tint ?>"><?= room_ident($room) ?></td>
                <td class="col-name sticky-col sticky-col-2 room-name-cell <?= $tint ?>" data-room="<?= (int)$roomId ?>">
                    <span class="pictogram"><?= icon_for_room_line('room', $type) ?></span>
                    <?= plan_open_icon() ?>
                    <?php if (($type === 'M' || $type === 'F' || $type === 'T') && (int)$room['capacity'] > 0):
                        $cap = (int)$room['capacity']; ?>
                        <span class="line-label cap-label"><?= $cap ?> <?= $cap === 1 ? 'seat' : 'seats' ?></span>
                    <?php endif; ?>
                </td>
                <?php if ($dayBlocked) { render_blocked_day($colsPerDay); } elseif ($hourly) { render_booking_cells($days, $roomId, $bookingMap); } else { render_booking_disabled($days, $colsPerDay, $offMonth); } ?>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (empty($rooms)): ?>
        <tr><td colspan="<?= 2 + count($days) * $colsPerDay ?>" class="empty-msg">No rooms yet. Go to "Setup rooms" to add some.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
</div>

<div class="save-indicator" id="saveIndicator"></div>

<div class="modal-overlay" id="adminGate" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="adminGateTitle">
        <p class="modal-msg" id="adminGateTitle">Only the administrator should access this page.</p>
        <div class="modal-actions">
            <button type="button" class="btn" id="adminGateCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="adminGateGo">Go to the page, I am the administrator</button>
        </div>
    </div>
</div>

<div class="select-bar" id="selectBar" hidden>
    <span class="select-count" id="selectCount">0 selected</span>
    <button type="button" class="sb-btn sb-free" data-color="green">Free</button>
    <button type="button" class="sb-btn sb-occ" data-color="red">Occ</button>
    <button type="button" class="sb-btn sb-clear" data-color="none">Clear</button>
    <button type="button" class="sb-cancel" id="selectCancel">Cancel</button>
</div>

<script>
window.TRACKER = {
    view: <?= json_encode($view) ?>,
    room: <?= $view === 'room' ? (int)$planRoom['id'] : 'null' ?>,
    ref: <?= json_encode($ref->format('Y-m-d')) ?>,
    prevRef: <?= json_encode($prevRef) ?>,
    nextRef: <?= json_encode($nextRef) ?>,
    todayRef: <?= json_encode($todayStr) ?>,
    rangeStart: <?= json_encode($rangeStart) ?>,
    rangeEnd: <?= json_encode($rangeEnd) ?>,
    confirmOcc: <?= get_flag($pdo, 'confirm_occ_booking', 1) === 1 ? 'true' : 'false' ?>,
    focusMinimal: <?= $focusMinimal ? 'true' : 'false' ?>,
    revision: <?= (int)get_revision($pdo) ?>
};
</script>
<script src="<?= asset_url('assets/app.js') ?>"></script>
</body>
</html>
