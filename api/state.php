<?php
require __DIR__ . '/../config.php';
require_auth_api($pdo);
header('Content-Type: application/json');
header('Cache-Control: no-store');

api_guard(function () use ($pdo) {
    $start = (string)($_GET['start'] ?? '');
    $end = (string)($_GET['end'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad request']);
        return;
    }

    $ds = $pdo->prepare("SELECT desk_id, date, period, text, color FROM desk_status WHERE date BETWEEN ? AND ?");
    $ds->execute([$start, $end]);
    $desks = $ds->fetchAll(PDO::FETCH_ASSOC);

    $rb = $pdo->prepare("SELECT room_id, date, hour, text FROM room_bookings WHERE date BETWEEN ? AND ?");
    $rb->execute([$start, $end]);
    $bookings = $rb->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'revision' => get_revision($pdo),
        'desks' => $desks,
        'bookings' => $bookings,
    ]);
});
