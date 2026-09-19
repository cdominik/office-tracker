<?php
require __DIR__ . '/../config.php';
require_auth_api($pdo);
header('Content-Type: application/json');
header('Cache-Control: no-store');
api_guard(function () use ($pdo) {
    echo json_encode(['revision' => get_revision($pdo)]);
});
