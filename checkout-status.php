<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/booking-store.php';

function loadCheckoutStatusEnv($filePath)
{
    if (!is_file($filePath)) {
        return;
    }

    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . trim($value, " \n\r\t\v\0\"'"));
        }
    }
}

loadCheckoutStatusEnv(__DIR__ . '/.env');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$sessionId = (string) ($_GET['session_id'] ?? '');
if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid']);
    exit;
}

$secretKey = trim((string) getenv('STRIPE_SECRET_KEY'));
if ($secretKey === '') {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable']);
    exit;
}

try {
    $stripe = new \Stripe\StripeClient($secretKey);
    $session = $stripe->checkout->sessions->retrieve($sessionId);
    $bookingId = (string) ($session->metadata->booking_id ?? '');
    if ($bookingId === '' || ($session->payment_status ?? '') !== 'paid') {
        echo json_encode(['status' => 'pending']);
        exit;
    }

    $booking = getBookingById(bookingDatabaseConnection(), $bookingId);
    $holdExpiresAt = $booking ? strtotime($booking['hold_expires_at'] ?? '') : false;
    if (!$booking || $booking['stripe_session_id'] !== $sessionId
        || $booking['status'] !== 'awaiting_payment' || !$holdExpiresAt || $holdExpiresAt <= time()) {
        echo json_encode(['status' => 'late']);
        exit;
    }

    echo json_encode(['status' => 'paid']);
} catch (Throwable $exception) {
    error_log('Stripe checkout status lookup failed: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode(['status' => 'unavailable']);
}
