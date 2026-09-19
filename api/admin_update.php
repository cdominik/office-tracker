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

    $field = (string)($data['field'] ?? '');

    if ($field === 'room_number') {
        $roomId = (int)($data['room_id'] ?? 0);
        $value = trim((string)($data['value'] ?? ''));
        if ($roomId <= 0 || $value === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad request']);
            return;
        }
        $pdo->prepare("UPDATE rooms SET room_number = ? WHERE id = ?")->execute([$value, $roomId]);

    } elseif ($field === 'desk_name') {
        $deskId = (int)($data['desk_id'] ?? 0);
        $value = trim((string)($data['value'] ?? ''));
        if ($deskId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad request']);
            return;
        }
        $pdo->prepare("UPDATE desks SET name = ? WHERE id = ?")->execute([$value, $deskId]);

    } elseif ($field === 'capacity') {
        $roomId = (int)($data['room_id'] ?? 0);
        $value = max(1, (int)($data['value'] ?? 1));
        if ($roomId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad request']);
            return;
        }
        $pdo->prepare("UPDATE rooms SET capacity = ? WHERE id = ?")->execute([$value, $roomId]);

    } elseif ($field === 'purge_old') {
        // Delete presence + booking data older than one month (keeps rooms/desks).
        $cutoff = (new DateTime('today'))->modify('-1 month')->format('Y-m-d');
        $d1 = $pdo->prepare("DELETE FROM desk_status WHERE date < ?");
        $d1->execute([$cutoff]);
        $d2 = $pdo->prepare("DELETE FROM room_bookings WHERE date < ?");
        $d2->execute([$cutoff]);
        bump_revision($pdo);
        echo json_encode(['ok' => true, 'deleted' => $d1->rowCount() + $d2->rowCount(), 'cutoff' => $cutoff]);
        return;

    } elseif ($field === 'room_visible') {
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad request']);
            return;
        }
        $pdo->prepare("UPDATE rooms SET visible = ? WHERE id = ?")
            ->execute([!empty($data['value']) ? 1 : 0, $roomId]);

    } elseif ($field === 'flag') {
        $key = (string)($data['key'] ?? '');
        if (!in_array($key, ['show_DO', 'show_SO', 'show_M', 'show_F', 'show_T', 'show_SO_table', 'auth_enabled'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad flag']);
            return;
        }
        set_flag($pdo, $key, !empty($data['value']) ? 1 : 0);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown field']);
        return;
    }

    echo json_encode(['ok' => true]);
});
