<?php
require __DIR__ . '/../config.php';
require_auth_api($pdo);
header('Content-Type: application/json');

api_guard(function () use ($pdo) {
    $deskId = (int)($_GET['desk_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    if ($deskId <= 0 || $year < 1970 || $year > 2200) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad request']);
        return;
    }

    // The desk's name (to title the calendar) and its room, for a light validity check.
    $d = $pdo->prepare("SELECT name FROM desks WHERE id = ?");
    $d->execute([$deskId]);
    $name = $d->fetchColumn();
    if ($name === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'no such desk']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT date, period, color FROM desk_status
         WHERE desk_id = ? AND date BETWEEN ? AND ? AND color <> 'none'"
    );
    $stmt->execute([$deskId, "$year-01-01", "$year-12-31"]);

    $days = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $days[$row['date']][$row['period']] = $row['color'];
    }

    echo json_encode(['ok' => true, 'name' => $name, 'year' => $year, 'days' => (object)$days]);
});
