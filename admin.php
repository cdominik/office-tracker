<?php
require __DIR__ . '/config.php';
require_auth_page($pdo);
require_admin_page($pdo);

// Stream a backup snapshot for download (they live behind an .htaccess deny, so serve via PHP).
// Only a validated file name from the backups directory is allowed.
if (($_GET['action'] ?? '') === 'download_backup') {
    $name = basename($_GET['file'] ?? '');
    $path = BACKUP_DIR . '/' . $name;
    if (preg_match('/^tracker-[a-z0-9_\-]+\.sqlite$/', $name) && is_file($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Backup not found.');
}

// Sort key for a room number with an optional letter suffix: "005b" -> [5, "b"], "12" -> [12, ""].
// Non-numeric numbers sort to the end, alphabetically.
function room_sort_key(string $num): array
{
    $num = trim($num);
    if (preg_match('/^(\d+)\s*([A-Za-z]*)/', $num, $m)) {
        return [(int)$m[1], strtolower($m[2]), strtolower($num)];
    }
    return [PHP_INT_MAX, strtolower($num), strtolower($num)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $redirect = 'admin.php';

    if ($action === 'add_room') {
        $number = trim($_POST['room_number'] ?? '');
        $type = $_POST['room_type'] ?? '';
        $capacity = null;
        if (!in_array($type, ['DO', 'SO', 'LO', 'EC', 'M', 'F', 'T'], true)) {
            $type = '';
        }
        if ($type === 'M') {
            $capacity = max(1, (int)($_POST['capacity'] ?? 15));
        } elseif ($type === 'F') {
            $capacity = 4;
        } elseif ($type === 'T') {
            $capacity = 1;
        }

        if ($number !== '' && $type !== '') {
            $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM rooms")->fetchColumn();
            $pdo->prepare("INSERT INTO rooms (room_number, room_type, capacity, sort_order) VALUES (?, ?, ?, ?)")
                ->execute([$number, $type, $capacity, $nextOrder]);
            $roomId = (int)$pdo->lastInsertId();

            $firstDeskId = 0;
            $ins = $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, ?, '')");
            if ($type === 'DO') {
                $ins->execute([$roomId, 0]);
                $firstDeskId = (int)$pdo->lastInsertId();
                $ins->execute([$roomId, 1]);
            } elseif ($type === 'SO') {
                $ins->execute([$roomId, 0]);
                $firstDeskId = (int)$pdo->lastInsertId();
            } elseif ($type === 'LO' || $type === 'EC') {
                $default = ($type === 'EC') ? 3 : 1; // early-career starts at 3, but any number is allowed
                $n = max(1, (int)($_POST['desk_count'] ?? $default));
                for ($i = 0; $i < $n; $i++) {
                    $ins->execute([$roomId, $i]);
                    if ($i === 0) $firstDeskId = (int)$pdo->lastInsertId();
                }
            }
            // Land the cursor in the first desk name field of the new room.
            if ($firstDeskId > 0) {
                $redirect = 'admin.php?focus_desk=' . $firstDeskId;
            }
        }
    } elseif ($action === 'add_desk') {
        // Add a desk to a large / early-career office.
        $roomId = (int)($_POST['room_id'] ?? 0);
        $rtype = $pdo->prepare("SELECT room_type FROM rooms WHERE id = ?");
        $rtype->execute([$roomId]);
        if (in_array($rtype->fetchColumn(), ['LO', 'EC'], true)) {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(seat_index), -1) + 1 FROM desks WHERE room_id = " . $roomId)->fetchColumn();
            $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, ?, '')")->execute([$roomId, $next]);
            $newDeskId = (int)$pdo->lastInsertId();
            $redirect = 'admin.php?focus_desk=' . $newDeskId;
        }
    } elseif ($action === 'delete_desk') {
        // Remove a desk from an Office / early-career office (Office keeps >=1, early-career >=3).
        $deskId = (int)($_POST['desk_id'] ?? 0);
        $row = $pdo->prepare("SELECT d.room_id, r.room_type FROM desks d JOIN rooms r ON r.id = d.room_id WHERE d.id = ?");
        $row->execute([$deskId]);
        $info = $row->fetch(PDO::FETCH_ASSOC);
        if ($info && in_array($info['room_type'], ['LO', 'EC'], true)) {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM desks WHERE room_id = " . (int)$info['room_id'])->fetchColumn();
            if ($cnt > 1) { // keep at least one desk
                $pdo->prepare("DELETE FROM desks WHERE id = ?")->execute([$deskId]);
            }
        }
    } elseif ($action === 'backup_now') {
        backup_create($pdo, true); // manual backups are kept until deleted by hand
    } elseif ($action === 'delete_backup') {
        $name = basename($_POST['file'] ?? '');
        $path = BACKUP_DIR . '/' . $name;
        if (preg_match('/^tracker-[a-z0-9_\-]+\.sqlite$/', $name) && is_file($path)) {
            @unlink($path);
        }
    } elseif ($action === 'restore_backup') {
        // Replace the live database with an existing snapshot (current state saved first).
        $name = basename($_POST['file'] ?? '');
        $path = BACKUP_DIR . '/' . $name;
        if (preg_match('/^tracker-[a-z0-9_\-]+\.sqlite$/', $name) && is_file($path) && backup_restore($pdo, $path)) {
            $redirect = 'admin.php?restored=1';
        } else {
            $redirect = 'admin.php?restore_failed=1';
        }
    } elseif ($action === 'upload_restore') {
        // Restore from an uploaded backup file (e.g. an off-site copy or a migration).
        $redirect = 'admin.php?restore_failed=1';
        if (isset($_FILES['backup']) && $_FILES['backup']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['backup']['tmp_name'];
            if (is_uploaded_file($tmp) && backup_is_valid_sqlite($tmp) && backup_restore($pdo, $tmp)) {
                $redirect = 'admin.php?restored=1';
            }
        }
    } elseif ($action === 'sort_rooms' || $action === 'sort_rooms_ec_last') {
        // Sort all rooms by number (letter suffix aware). For "ec_last", early-career rooms
        // are grouped at the end, sorted among themselves.
        $ecLast = ($action === 'sort_rooms_ec_last');
        $all = $pdo->query("SELECT id, room_number, room_type FROM rooms")->fetchAll(PDO::FETCH_ASSOC);
        usort($all, function ($a, $b) use ($ecLast) {
            if ($ecLast) {
                $ea = $a['room_type'] === 'EC' ? 1 : 0;
                $eb = $b['room_type'] === 'EC' ? 1 : 0;
                if ($ea !== $eb) return $ea <=> $eb;
            }
            return room_sort_key($a['room_number']) <=> room_sort_key($b['room_number']);
        });
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE rooms SET sort_order = ? WHERE id = ?");
        foreach ($all as $i => $r) { $upd->execute([$i, $r['id']]); }
        $pdo->commit();
    } elseif ($action === 'delete_room') {
        $id = (int)($_POST['room_id'] ?? 0);
        $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
    } elseif ($action === 'move_room') {
        $id = (int)($_POST['room_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        // Normalize sort_order to 0..n-1 in current display order, then swap with the neighbour.
        $ordered = $pdo->query("SELECT id FROM rooms ORDER BY sort_order, room_number, id")->fetchAll(PDO::FETCH_COLUMN);
        $pos = array_search($id, array_map('intval', $ordered), true);
        if ($pos !== false) {
            $target = $pos + $dir;
            if ($target >= 0 && $target < count($ordered)) {
                $pdo->beginTransaction();
                $norm = $pdo->prepare("UPDATE rooms SET sort_order = ? WHERE id = ?");
                foreach ($ordered as $i => $rid) {
                    $norm->execute([$i, $rid]);
                }
                // swap positions
                $norm->execute([$target, $ordered[$pos]]);
                $norm->execute([$pos, $ordered[$target]]);
                $pdo->commit();
            }
        }
    } elseif ($action === 'update_room_number') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $number = trim($_POST['room_number'] ?? '');
        if ($roomId > 0 && $number !== '') {
            $pdo->prepare("UPDATE rooms SET room_number = ? WHERE id = ?")->execute([$number, $roomId]);
        }
    } elseif ($action === 'update_auth') {
        set_flag($pdo, 'auth_enabled', isset($_POST['auth_enabled']) ? 1 : 0);
    } elseif ($action === 'update_visibility') {
        set_flag($pdo, 'show_M', isset($_POST['show_m']) ? 1 : 0);
        set_flag($pdo, 'show_F', isset($_POST['show_f']) ? 1 : 0);
        set_flag($pdo, 'show_T', isset($_POST['show_t']) ? 1 : 0);
    } elseif ($action === 'update_desk_name') {
        $deskId = (int)($_POST['desk_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $pdo->prepare("UPDATE desks SET name = ? WHERE id = ?")->execute([$name, $deskId]);
    } elseif ($action === 'update_capacity') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $capacity = max(1, (int)($_POST['capacity'] ?? 15));
        $pdo->prepare("UPDATE rooms SET capacity = ? WHERE id = ?")->execute([$capacity, $roomId]);
    }

    header('Location: ' . $redirect);
    exit;
}

$rooms = $pdo->query("SELECT * FROM rooms ORDER BY sort_order, room_number")->fetchAll(PDO::FETCH_ASSOC);
$desks = $pdo->query("SELECT * FROM desks ORDER BY room_id, seat_index")->fetchAll(PDO::FETCH_ASSOC);
$desksByRoom = [];
foreach ($desks as $d) {
    $desksByRoom[$d['room_id']][] = $d;
}

$showDO = get_flag($pdo, 'show_DO', 1);
$showSO = get_flag($pdo, 'show_SO', 1);
$showLO = get_flag($pdo, 'show_LO', 1);
$showEC = get_flag($pdo, 'show_EC', 1);
$showM = get_flag($pdo, 'show_M', 1);
$showF = get_flag($pdo, 'show_F', 1);
$showT = get_flag($pdo, 'show_T', 1);
$authEnabled = auth_enabled($pdo);
$adminAuthEnabled = admin_auth_enabled($pdo);
$confirmOcc = get_flag($pdo, 'confirm_occ_booking', 1);
$focusMinimal = get_flag($pdo, 'focus_minimal', 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$typeNames = [
    'DO' => 'Double occupancy office',
    'SO' => 'Single occupancy office',
    'LO' => 'General office',
    'EC' => 'Early-career scientists',
    'M'  => 'Large meeting room',
    'F'  => 'Focus room',
    'T'  => 'Calling cell',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup rooms</title>
<script src="<?= asset_url('assets/theme.js') ?>"></script>
<link rel="icon" href="<?= asset_url('favicon.ico') ?>" sizes="any">
<link rel="icon" href="<?= asset_url('assets/favicon.svg') ?>" type="image/svg+xml">
<link rel="icon" href="<?= asset_url('assets/favicon-32.png') ?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?= asset_url('assets/favicon-16.png') ?>" sizes="16x16" type="image/png">
<link rel="apple-touch-icon" href="<?= asset_url('assets/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/style.css') ?>">
</head>
<body>
<div class="topbar">
    <h1>Setup rooms</h1>
</div>

<div class="admin-wrap">
    <?php if (isset($_GET['restored'])): ?>
        <div class="admin-banner ok">Database restored. The previous state was saved as a “Kept” backup, so you can undo this by restoring that one.</div>
    <?php elseif (isset($_GET['restore_failed'])): ?>
        <div class="admin-banner err">Restore failed — nothing was changed. The file must be a valid Office Planner database.</div>
    <?php endif; ?>

    <nav class="admin-jumpbar">
        <a href="#settings">Settings</a>
        <a href="#rooms">Rooms</a>
        <a href="#danger">Danger zone</a>
        <a class="jump-back" href="index.php">&larr; Back to tracker</a>
    </nav>

    <section id="settings" class="admin-section sec-settings">
    <h2 class="admin-section-title">Settings</h2>
    <div class="admin-office-card visibility-card">
        <strong>Show room types in the tracker</strong>
        <div class="visibility-form">
            <label><input type="checkbox" class="auto-flag" data-key="show_DO" <?= $showDO ? 'checked' : '' ?>> Double offices (D)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_SO" <?= $showSO ? 'checked' : '' ?>> Single offices (S)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_LO" <?= $showLO ? 'checked' : '' ?>> Offices (O)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_EC" <?= $showEC ? 'checked' : '' ?>> Early-career (E)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_M" <?= $showM ? 'checked' : '' ?>> Meeting rooms (M)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_F" <?= $showF ? 'checked' : '' ?>> Focus rooms (F)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_T" <?= $showT ? 'checked' : '' ?>> Calling cells (T)</label>

        </div>
        <p class="admin-note">Unchecking a type hides those rooms from the tracker grid. Their data is kept and reappears when you re-enable the type. (Reopen the tracker to see the change.)</p>
        <p class="admin-note"><b>Tip:</b> on the tracker, press <kbd>⌘/Ctrl+A</kbd> to preview <em>every</em> room at once — ignoring these type toggles, the per-room "Show" checkboxes, and meeting-space focus. Press it again (or click the "Showing all rooms" badge) to return to the normal view.</p>
    </div>

    <div class="admin-office-card visibility-card">
        <strong>Access control</strong>
        <div class="visibility-form">
            <label><input type="checkbox" class="auto-flag" data-key="auth_enabled" <?= $authEnabled ? 'checked' : '' ?>> Require the shared password to open the tracker</label>
            <label><input type="checkbox" class="auto-flag" data-key="admin_auth_enabled" <?= $adminAuthEnabled ? 'checked' : '' ?>> Require a separate password to open this setup page</label>
        </div>
        <p class="admin-note">
            Two independent passwords, both set in <code>config.php</code>. The shared tracker password is
            remembered per device for about 90 days; the setup password is asked again each day (it's cached
            for one day only). If you turn on the setup password and forget it, edit <code>config.php</code>
            or set <code>admin_auth_enabled</code> to 0 in the database to get back in.
        </p>
    </div>
    <div class="admin-office-card visibility-card">
        <strong>Meeting-space focus</strong>
        <p class="admin-note" style="margin-top:4px;">What the toolbar's meeting-space focus button hides when it's on:</p>
        <div class="visibility-form radio-group">
            <label><input type="radio" name="focus_mode" class="auto-flag-radio" data-key="focus_minimal" value="0" <?= $focusMinimal ? '' : 'checked' ?>> Hide offices without a meeting table <em>(default)</em></label>
            <label><input type="radio" name="focus_mode" class="auto-flag-radio" data-key="focus_minimal" value="1" <?= $focusMinimal ? 'checked' : '' ?>> Hide all desks, also those in offices with a meeting table</label>
        </div>
        <p class="admin-note">The first keeps tabled offices fully visible (desks and all). The second collapses
            every office to just its bookable table, leaving the faint occupied tint to show whether it's in use —
            a more compact "find a room" view. (With the second option, switching focus reloads the page.)</p>
    </div>

    <div class="admin-office-card visibility-card">
        <strong>Booking etiquette</strong>
        <div class="visibility-form">
            <label><input type="checkbox" class="auto-flag" data-key="confirm_occ_booking" <?= $confirmOcc ? 'checked' : '' ?>> Ask for confirmation when booking a single-office meeting table while that office is marked occupied</label>
        </div>
        <p class="admin-note">A gentle reminder not to take someone's office as a meeting room while they're
            in it. The occupied half-days are always tinted faintly on the table row; this only controls the
            pop-up confirmation. Turn it off once the habit is established.</p>
    </div>
    </section>

    <section id="rooms" class="admin-section sec-rooms">
    <h2 class="admin-section-title">Rooms</h2>
    <div class="admin-office-card visibility-card">
        <strong>Room order</strong>
        <div class="visibility-form" style="margin-top:6px; gap:8px;">
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="sort_rooms">
                <button type="submit" class="btn btn-small">Sort rooms by number</button>
            </form>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="sort_rooms_ec_last">
                <button type="submit" class="btn btn-small">Sort, early-career last</button>
            </form>
        </div>
        <p class="admin-note">Reorders all rooms by their number, handling a letter suffix (e.g. 5, 5a, 5b, 6).
            "Early-career last" keeps the E rooms grouped at the end, sorted among themselves. You can still
            fine-tune with the ↑ / ↓ buttons on each room afterwards.</p>
    </div>
    <?php $roomCount = count($rooms); ?>
    <?php foreach ($rooms as $idx => $room):
        $type = $room['room_type'];
        $roomId = $room['id'];
    ?>
        <div class="admin-office-card">
            <div class="admin-office-header">
                <span class="reorder-group">
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="move_room">
                        <input type="hidden" name="room_id" value="<?= (int)$roomId ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="btn btn-small reorder-btn" title="Move up" <?= $idx === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="move_room">
                        <input type="hidden" name="room_id" value="<?= (int)$roomId ?>">
                        <input type="hidden" name="dir" value="down">
                        <button type="submit" class="btn btn-small reorder-btn" title="Move down" <?= $idx === $roomCount - 1 ? 'disabled' : '' ?>>&darr;</button>
                    </form>
                </span>
                <span class="room-number-form">
                    <label class="room-number-label">Room</label>
                    <input type="text" class="auto-field room-number-input" data-field="room_number" data-room-id="<?= (int)$roomId ?>" value="<?= h($room['room_number']) ?>">
                </span>
                <span class="admin-type-tag"><?= h($typeNames[$type] ?? $type) ?></span>
                <label class="room-show"><input type="checkbox" class="auto-roomvis" data-room-id="<?= (int)$roomId ?>" <?= ((int)($room['visible'] ?? 1) === 1) ? 'checked' : '' ?>> Show</label>
                <form method="post" onsubmit="return confirm('Delete this room and all its data?');">
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" value="<?= (int)$roomId ?>">
                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                </form>
            </div>

            <?php if ($type === 'DO' || $type === 'SO'): ?>
                <ul class="admin-people-list">
                    <?php foreach ($desksByRoom[$roomId] ?? [] as $desk): ?>
                        <li>
                            <input type="text" class="auto-field desk-name-input" data-field="desk_name" data-desk-id="<?= (int)$desk['id'] ?>" value="<?= h($desk['name']) ?>" placeholder="Desk <?= (int)$desk['seat_index'] + 1 ?> — name">
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ($type === 'LO' || $type === 'EC'):
                $loDesks = $desksByRoom[$roomId] ?? [];
                $canRemove = count($loDesks) > 1; // keep at least one desk
            ?>
                <ul class="admin-people-list">
                    <?php foreach ($loDesks as $i => $desk): ?>
                        <li class="lo-desk-row">
                            <input type="text" class="auto-field desk-name-input" data-field="desk_name" data-desk-id="<?= (int)$desk['id'] ?>" value="<?= h($desk['name']) ?>" placeholder="Desk <?= $i + 1 ?> — name">
                            <?php if ($canRemove): ?>
                                <form method="post" class="lo-desk-del" onsubmit="return confirm('Remove this desk and its data?');">
                                    <input type="hidden" name="action" value="delete_desk">
                                    <input type="hidden" name="desk_id" value="<?= (int)$desk['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-small" title="Remove desk">&times;</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" class="lo-add-desk">
                    <input type="hidden" name="action" value="add_desk">
                    <input type="hidden" name="room_id" value="<?= (int)$roomId ?>">
                    <button type="submit" class="btn btn-small">+ Add desk</button>
                </form>
                <p class="admin-note"><?= $type === 'EC' ? 'Early-career office' : 'General office' ?> — <?= count($loDesks) ?> desk<?= count($loDesks) === 1 ? '' : 's' ?> (minimum 1).</p>
            <?php elseif ($type === 'M'): ?>
                <label class="admin-capacity-label">Capacity:
                    <input type="number" class="auto-field capacity-input" data-field="capacity" data-room-id="<?= (int)$roomId ?>" min="1" max="100" value="<?= (int)$room['capacity'] ?>">
                </label>
            <?php else: ?>
                <p class="admin-note">No desks — this room is bookable in 1-hour slots only.</p>
            <?php endif; ?>
            <?php if (in_array($type, ['DO', 'SO', 'LO', 'EC'], true)): ?>
                <label class="room-table-toggle"><input type="checkbox" class="auto-roomtable" data-room-id="<?= (int)$roomId ?>" <?= ((int)($room['has_table'] ?? 0) === 1) ? 'checked' : '' ?>> Bookable meeting table (hourly slots; shown as an extra row)</label>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="admin-office-card add-office-card">
        <strong>Add a new room</strong>
        <form method="post" class="add-room-form">
            <input type="hidden" name="action" value="add_room">
            <input type="text" name="room_number" placeholder="Room number, e.g. 2000" required>
            <select name="room_type" id="roomTypeSelect" required>
                <option value="">Choose type…</option>
                <option value="SO">S — Single occupancy office</option>
                <option value="DO">D — Double occupancy office</option>
                <option value="LO">O — General office</option>
                <option value="EC">E — Early-career scientists (numbered desks)</option>
                <option value="M">M — Large meeting room</option>
                <option value="F">F — Focus room</option>
                <option value="T">T — Calling cell</option>
            </select>
            <label id="capacityField" class="admin-capacity-label" style="display:none;">Capacity:
                <input type="number" name="capacity" min="1" max="100" value="15">
            </label>
            <label id="deskCountField" class="admin-capacity-label" style="display:none;">Desks:
                <input type="number" name="desk_count" min="1" max="60" value="1">
            </label>
            <button type="submit" class="btn btn-small">Add room</button>
        </form>
    </div>
    </section>

    <section id="danger" class="admin-section sec-danger">
    <h2 class="admin-section-title">Danger zone</h2>
    <div class="admin-office-card">
        <strong>Maintenance</strong>
        <div class="visibility-form" style="margin-top:6px;">
            <button type="button" id="purgeBtn" class="btn btn-danger btn-small">Delete data older than one month</button>
        </div>
        <p class="admin-note">Removes presence and booking entries with a date more than a month ago. Rooms, desks, and everything from the last month onward are kept. A backup is taken automatically just before this runs.</p>
    </div>

    <?php $backups = backups_list(); ?>
    <div class="admin-office-card">
        <strong>Backups</strong>
        <div class="visibility-form" style="margin-top:6px;">
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="backup_now">
                <button type="submit" class="btn btn-small">Back up now</button>
            </form>
        </div>
        <p class="admin-note">Automatic snapshots are taken once a day while the app is in use — kept daily for a week, then weekly, so the oldest is about a month old. <strong>Kept</strong> backups (made with "Back up now", or taken automatically just before a purge) are never auto-deleted — remove them yourself when you no longer need them.</p>

        <div class="admin-warning">
            <strong>⚠ Restoring replaces the entire database.</strong> Everyone's presence and bookings will be
            rolled back to the moment the snapshot was taken — any changes made since then, by anyone, are lost.
            It's safest to restore when nobody is mid-edit. As a safety net, the current state is automatically
            saved as a <strong>Kept</strong> backup first, so a restore can itself be undone.
        </div>

        <?php if (!$backups): ?>
            <p class="admin-note">No snapshots yet — one will be created shortly after the tracker is next opened.</p>
        <?php else: ?>
            <ul class="admin-people-list">
                <?php foreach (array_slice($backups, 0, 20) as $bfile):
                    $bn = basename($bfile);
                    $kept = backup_is_kept($bfile);
                    $when = date('D j M Y, H:i', filemtime($bfile)); ?>
                    <li class="lo-desk-row">
                        <span class="line-label" style="max-width:none;">
                            <span class="backup-tag <?= $kept ? 'tag-kept' : 'tag-auto' ?>"><?= $kept ? 'Kept' : 'Auto' ?></span>
                            <?= h($when) ?> · <?= number_format(filesize($bfile) / 1024, 0) ?> KB
                        </span>
                        <a class="btn btn-small" href="admin.php?action=download_backup&amp;file=<?= h($bn) ?>">Download</a>
                        <form method="post" class="lo-desk-del" onsubmit="return confirm('Replace ALL current data with the snapshot from <?= h($when) ?>?\n\nEveryone\u2019s changes since then will be lost. The current state will be saved as a Kept backup first, so you can undo this.');">
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="file" value="<?= h($bn) ?>">
                            <button type="submit" class="btn btn-small" title="Restore this backup">Restore</button>
                        </form>
                        <form method="post" class="lo-desk-del" onsubmit="return confirm('Delete this backup?');">
                            <input type="hidden" name="action" value="delete_backup">
                            <input type="hidden" name="file" value="<?= h($bn) ?>">
                            <button type="submit" class="btn btn-danger btn-small" title="Delete this backup">&times;</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div style="margin-top:12px; border-top:1px solid var(--border); padding-top:12px;">
            <strong style="font-size:13px;">Restore from a file</strong>
            <form method="post" enctype="multipart/form-data" class="visibility-form" style="margin-top:6px; align-items:center; gap:8px;"
                  onsubmit="return confirm('Replace ALL current data with the uploaded file?\n\nEveryone\u2019s changes will be lost. The current state will be saved as a Kept backup first, so you can undo this.');">
                <input type="hidden" name="action" value="upload_restore">
                <input type="file" name="backup" accept=".sqlite" required>
                <button type="submit" class="btn btn-small">Upload &amp; restore</button>
            </form>
            <p class="admin-note">Use a snapshot you previously downloaded (an off-site copy, or when moving to a new host). The file is validated as an Office Planner database before anything is replaced.</p>
        </div>
    </div>
    </section>

    <p class="admin-version">Office Planner <?= h(APP_VERSION) ?> · see <code>CHANGELOG.md</code> for changes between versions</p>
</div>

<div class="save-indicator" id="saveIndicator"></div>

<script>
document.getElementById('roomTypeSelect').addEventListener('change', function () {
    document.getElementById('capacityField').style.display = (this.value === 'M') ? 'inline-flex' : 'none';
    const showDesks = (this.value === 'LO' || this.value === 'EC');
    document.getElementById('deskCountField').style.display = showDesks ? 'inline-flex' : 'none';
    const inp = document.querySelector('#deskCountField input');
    if (inp) {
        inp.min = 1;                                   // both allow a single desk
        inp.value = (this.value === 'EC') ? 3 : 1;     // early-career starts at 3 by default
    }
});
</script>
<script src="<?= asset_url('assets/admin.js') ?>"></script>

</body>
</html>
