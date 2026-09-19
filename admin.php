<?php
require __DIR__ . '/config.php';
require_auth_page($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $redirect = 'admin.php';

    if ($action === 'add_room') {
        $number = trim($_POST['room_number'] ?? '');
        $type = $_POST['room_type'] ?? '';
        $capacity = null;
        if (!in_array($type, ['DO', 'SO', 'M', 'F', 'T'], true)) {
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
            if ($type === 'DO') {
                $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, 0, '')")->execute([$roomId]);
                $firstDeskId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, 1, '')")->execute([$roomId]);
            } elseif ($type === 'SO') {
                $pdo->prepare("INSERT INTO desks (room_id, seat_index, name) VALUES (?, 0, '')")->execute([$roomId]);
                $firstDeskId = (int)$pdo->lastInsertId();
            }
            // For DO/SO, land the cursor in the first desk name field of the new room.
            if ($firstDeskId > 0) {
                $redirect = 'admin.php?focus_desk=' . $firstDeskId;
            }
        }
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
$showM = get_flag($pdo, 'show_M', 1);
$showF = get_flag($pdo, 'show_F', 1);
$showT = get_flag($pdo, 'show_T', 1);
$showSOtable = get_flag($pdo, 'show_SO_table', 1);
$authEnabled = auth_enabled($pdo);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$typeNames = [
    'DO' => 'Double occupancy office',
    'SO' => 'Single occupancy office',
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
<title>Manage Rooms &amp; Desks</title>
<script src="<?= asset_url('assets/theme.js') ?>"></script>
<link rel="stylesheet" href="<?= asset_url('assets/style.css') ?>">
</head>
<body>
<div class="topbar">
    <h1>Manage rooms &amp; desks</h1>
    <a class="btn" href="index.php">&larr; Back to tracker</a>
</div>

<div class="admin-wrap">
    <div class="admin-office-card visibility-card">
        <strong>Show room types in the tracker</strong>
        <div class="visibility-form">
            <label><input type="checkbox" class="auto-flag" data-key="show_DO" <?= $showDO ? 'checked' : '' ?>> Double offices (D)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_SO" <?= $showSO ? 'checked' : '' ?>> Single offices (S)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_M" <?= $showM ? 'checked' : '' ?>> Meeting rooms (M)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_F" <?= $showF ? 'checked' : '' ?>> Focus rooms (F)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_T" <?= $showT ? 'checked' : '' ?>> Calling cells (T)</label>
            <label><input type="checkbox" class="auto-flag" data-key="show_SO_table" <?= $showSOtable ? 'checked' : '' ?>> Meeting tables (SO offices)</label>
        </div>
        <p class="admin-note">Unchecking a type hides those rooms from the tracker grid. Their data is kept and reappears when you re-enable the type. (Reopen the tracker to see the change.)</p>
    </div>

    <div class="admin-office-card visibility-card">
        <strong>Access control</strong>
        <div class="visibility-form">
            <label><input type="checkbox" class="auto-flag" data-key="auth_enabled" <?= $authEnabled ? 'checked' : '' ?>> Require the shared password to open the tracker</label>
        </div>
        <p class="admin-note">
            One shared password for everyone (no usernames), set in <code>config.php</code>. When enabled,
            each person enters it once per device and it's remembered for about 90 days.
        </p>
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
                <?php if ($type === 'SO'): ?>
                    <p class="admin-note">This office also has a shared meeting table (bookable in 1-hour slots — no name needed).</p>
                <?php endif; ?>
            <?php elseif ($type === 'M'): ?>
                <label class="admin-capacity-label">Capacity:
                    <input type="number" class="auto-field capacity-input" data-field="capacity" data-room-id="<?= (int)$roomId ?>" min="1" max="100" value="<?= (int)$room['capacity'] ?>">
                </label>
            <?php else: ?>
                <p class="admin-note">No desks — this room is bookable in 1-hour slots only.</p>
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
                <option value="DO">D — Double occupancy office</option>
                <option value="SO">S — Single occupancy office</option>
                <option value="M">M — Large meeting room</option>
                <option value="F">F — Focus room</option>
                <option value="T">T — Calling cell</option>
            </select>
            <label id="capacityField" class="admin-capacity-label" style="display:none;">Capacity:
                <input type="number" name="capacity" min="1" max="100" value="15">
            </label>
            <button type="submit" class="btn btn-small">Add room</button>
        </form>
    </div>

    <div class="admin-office-card">
        <strong>Maintenance</strong>
        <div class="visibility-form" style="margin-top:6px;">
            <button type="button" id="purgeBtn" class="btn btn-danger btn-small">Delete data older than one month</button>
        </div>
        <p class="admin-note">Removes presence and booking entries with a date more than a month ago. Rooms, desks, and everything from the last month onward are kept. This can't be undone.</p>
    </div>
</div>

<div class="save-indicator" id="saveIndicator"></div>

<script>
document.getElementById('roomTypeSelect').addEventListener('change', function () {
    document.getElementById('capacityField').style.display = (this.value === 'M') ? 'inline-flex' : 'none';
});
</script>
<script src="<?= asset_url('assets/admin.js') ?>"></script>

</body>
</html>
