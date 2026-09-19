<?php
require __DIR__ . '/../config.php';
require_auth_api($pdo);
header('Content-Type: application/json');

api_guard(function () use ($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid json']);
        return;
    }

    $roomId = (int)($data['room_id'] ?? 0);
    $date = (string)($data['date'] ?? '');
    $hour = (int)($data['hour'] ?? -1);
    $text = clip_text((string)($data['text'] ?? ''));

    if ($roomId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($hour, BOOKING_HOURS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad request']);
        return;
    }

    $upd = $pdo->prepare("UPDATE room_bookings SET text = ? WHERE room_id = ? AND date = ? AND hour = ?");
    $upd->execute([$text, $roomId, $date, $hour]);

    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare("SELECT id FROM room_bookings WHERE room_id = ? AND date = ? AND hour = ?");
        $chk->execute([$roomId, $date, $hour]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO room_bookings (room_id, date, hour, text) VALUES (?, ?, ?, ?)");
            $ins->execute([$roomId, $date, $hour, $text]);
        }
    }

    echo json_encode(['ok' => true, 'revision' => bump_revision($pdo)]);
});
