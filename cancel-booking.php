<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/booking-store.php';

function loadCancelBookingEnv($filePath)
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

loadCancelBookingEnv(__DIR__ . '/.env');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$bookingId = (string) ($_POST['booking_id'] ?? '');
$cancelToken = (string) ($_POST['cancel_token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $bookingId) || !preg_match('/^[a-f0-9]{64}$/', $cancelToken)) {
    http_response_code(400);
    exit('This booking hold cannot be cancelled.');
}

try {
    $database = bookingDatabaseConnection();
    $booking = getBookingById($database, $bookingId);
    $cancelled = cancelBookingForCustomer($database, $bookingId, $cancelToken);
    if (!$cancelled) {
        http_response_code(409);
        exit('This booking hold has already expired, been completed, or been cancelled.');
    }

    if (!empty($booking['stripe_session_id']) && getenv('STRIPE_SECRET_KEY')) {
        try {
            $stripe = new \Stripe\StripeClient(getenv('STRIPE_SECRET_KEY'));
            $stripe->checkout->sessions->expire($booking['stripe_session_id']);
        } catch (Throwable $exception) {
            error_log('Could not expire cancelled Stripe Checkout session: ' . $exception->getMessage());
        }
    }

    header('Location: cart.html?hold=cancelled', true, 303);
    exit;
} catch (Throwable $exception) {
    error_log('Booking cancellation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The booking hold could not be cancelled. Please contact the studio.');
}
