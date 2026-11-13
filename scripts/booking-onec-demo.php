<?php

declare(strict_types=1);

$statePath = getenv('BOOKING_DEMO_ONEC_STATE');
if (! $statePath || PHP_SAPI !== 'cli-server'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
$slot = $body['slot_id'] ?? '';
$id = $body['appointment_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/events'
    || ($_GET['action'] ?? null) !== 'bookslot'
    || ! is_string($id) || ! preg_match('/\A[0-9a-f-]{36}\z/', $id)
    || ! in_array($slot, ['demo-free', 'demo-occupied', 'demo-unknown'], true)) {
    http_response_code(422);
    echo '{"code":"invalid_request"}';
    exit;
}

$bookings = is_file($statePath) ? json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR) : [];
if ($slot === 'demo-occupied' || (isset($bookings[$slot]) && $bookings[$slot] !== $id)) {
    http_response_code(409);
    echo '{"code":"slot_unavailable"}';
    exit;
}

$bookings[$slot] = $id;
file_put_contents($statePath, json_encode($bookings, JSON_THROW_ON_ERROR), LOCK_EX);

if ($slot === 'demo-unknown') {
    echo '{"status":"success"}';
    exit;
}

http_response_code(201);
echo json_encode(['status' => 'success', 'claim_id' => $id], JSON_THROW_ON_ERROR);
