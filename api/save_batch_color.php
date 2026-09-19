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

    $color = (string)($data['color'] ?? '');
    $cells = $data['cells'] ?? [];

    if (!in_array($color, ['none', 'green', 'red'], true) || !is_array($cells)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad request']);
        return;
    }

    // Guard against an oversized batch.
    if (count($cells) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'too many cells']);
        return;
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE desk_status SET color = ? WHERE desk_id = ? AND date = ? AND period = ?");
    $chk = $pdo->prepare("SELECT id FROM desk_status WHERE desk_id = ? AND date = ? AND period = ?");
    $ins = $pdo->prepare("INSERT INTO desk_status (desk_id, date, period, text, color) VALUES (?, ?, ?, '', ?)");

    foreach ($cells as $c) {
        if (!is_array($c)) {
            continue;
        }
        $deskId = (int)($c['desk_id'] ?? 0);
        $date = (string)($c['date'] ?? '');
        $period = (string)($c['period'] ?? '');
        if ($deskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($period, ['am', 'pm'], true)) {
            continue;
        }
        $upd->execute([$color, $deskId, $date, $period]);
        if ($upd->rowCount() === 0) {
            $chk->execute([$deskId, $date, $period]);
            if (!$chk->fetch()) {
                $ins->execute([$deskId, $date, $period, $color]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'revision' => bump_revision($pdo)]);
});
