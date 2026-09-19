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

    $deskId = (int)($data['desk_id'] ?? 0);
    $date = (string)($data['date'] ?? '');
    $period = (string)($data['period'] ?? '');
    $text = clip_text((string)($data['text'] ?? ''));

    if ($deskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($period, ['am', 'pm'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad request']);
        return;
    }

    $upd = $pdo->prepare("UPDATE desk_status SET text = ? WHERE desk_id = ? AND date = ? AND period = ?");
    $upd->execute([$text, $deskId, $date, $period]);

    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare("SELECT id FROM desk_status WHERE desk_id = ? AND date = ? AND period = ?");
        $chk->execute([$deskId, $date, $period]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO desk_status (desk_id, date, period, text, color) VALUES (?, ?, ?, ?, 'none')");
            $ins->execute([$deskId, $date, $period, $text]);
        }
    }

    echo json_encode(['ok' => true, 'revision' => bump_revision($pdo)]);
});
