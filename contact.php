<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/booking-store.php';
require __DIR__ . '/booking-mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function loadDotEnv($filePath)
{
    if (!is_file($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \n\r\t\v\0\"'");

        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function envBool($name, $default = true)
{
    $value = getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function envFloat($name, $default)
{
    $value = getenv($name);
    if ($value === false || $value === null || trim((string) $value) === '') {
        return $default;
    }

    return (float) $value;
}

function loadStudioRooms()
{
    $defaultRooms = [
        ['id' => 'rose-room', 'name' => 'Rose Room', 'services' => ['classic-lash', 'volume-lash', 'lash-infills', 'lash-lift-tint']],
        ['id' => 'gold-room', 'name' => 'Gold Room', 'services' => ['bridal-glam', 'brow-lamination']],
        ['id' => 'london-suite', 'name' => 'London Suite', 'services' => ['classic-lash', 'volume-lash', 'bridal-glam']],
    ];

    $roomJson = getenv('BOOKING_ROOMS');
    if ($roomJson === false || trim((string) $roomJson) === '') {
        return $defaultRooms;
    }

    $decoded = json_decode($roomJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        return $defaultRooms;
    }

    return $decoded;
}

function loadMobileStaff()
{
    $defaultStaff = [
        ['id' => 'amy-wilson', 'name' => 'Amy Wilson', 'homePostcode' => 'SW1A 1AA', 'serviceAreaMiles' => 5, 'servedPostcodes' => ['SW1A 1AA', 'SW3 2AA'], 'serviceAreas' => ['SW1', 'SW3'], 'services' => ['classic-lash', 'volume-lash', 'lash-infills', 'lash-lift-tint']],
        ['id' => 'nina-park', 'name' => 'Nina Park', 'homePostcode' => 'W1A 1AA', 'serviceAreaMiles' => 8, 'servedPostcodes' => ['W1A 1AA'], 'serviceAreas' => ['W1', 'W2'], 'services' => ['bridal-glam', 'brow-lamination']],
        ['id' => 'zoe-cole', 'name' => 'Zoe Cole', 'homePostcode' => 'E1 6AA', 'serviceAreaMiles' => 10, 'servedPostcodes' => ['E1 6AA'], 'serviceAreas' => ['E1', 'EC1'], 'services' => ['classic-lash', 'bridal-glam']],
    ];

    $staffJson = getenv('BOOKING_MOBILE_STAFF');
    if ($staffJson === false || trim((string) $staffJson) === '') {
        return $defaultStaff;
    }

    $decoded = json_decode($staffJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        return $defaultStaff;
    }

    return $decoded;
}

function loadServicePricesPence()
{
    $prices = [
        'classic-lash' => 6000,
        'volume-lash' => 8000,
        'lash-infills' => 3000,
        'bridal-glam' => 9500,
        'brow-lamination' => 5500,
        'lash-lift-tint' => 4500,
    ];
    $configuredPrices = json_decode(getenv('BOOKING_SERVICE_PRICES') ?: '', true);
    if (is_array($configuredPrices)) {
        foreach ($prices as $serviceId => $defaultPrice) {
            if (isset($configuredPrices[$serviceId]) && is_numeric($configuredPrices[$serviceId])) {
                $prices[$serviceId] = max(1, (int) $configuredPrices[$serviceId]);
            }
        }
    }

    return $prices;
}

function normalizePostcode($postcode)
{
    $normalized = strtoupper(preg_replace('/\s+/', '', trim((string) $postcode)));
    if ($normalized === 'GIR0AA') {
        return $normalized;
    }

    return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $normalized) ? $normalized : '';
}

function postcodeOutwardCode($postcode)
{
    $normalized = normalizePostcode($postcode);
    return $normalized !== '' ? substr($normalized, 0, -3) : '';
}

function postcodeToApproxCoords($postcode)
{
    $normalized = normalizePostcode($postcode);
    if ($normalized === '') {
        return null;
    }

    $areaMap = [
        'SW' => [51.5074, -0.1278], 'W' => [51.5098, -0.1280], 'NW' => [51.5194, -0.1365], 'N' => [51.5383, -0.1422],
        'E' => [51.5155, -0.0726], 'SE' => [51.5072, -0.1276], 'EC' => [51.5184, -0.0910], 'WC' => [51.5148, -0.1214],
        'M' => [53.4808, -2.2426], 'L' => [53.4084, -2.9916], 'B' => [52.4862, -1.8904], 'BH' => [50.7174, -1.8808],
        'BR' => [51.4050, 0.0153], 'CR' => [51.3723, -0.0944], 'DA' => [51.4415, 0.2116], 'EN' => [51.6524, -0.1196],
        'HA' => [51.5787, -0.4172], 'KT' => [51.3906, -0.3513], 'TW' => [51.4424, -0.3150], 'UB' => [51.5304, -0.3516],
        'EH' => [55.9533, -3.1883], 'GL' => [51.8642, -2.2384], 'BS' => [51.4545, -2.5879], 'CF' => [51.4816, -3.1791],
        'CV' => [52.4064, -1.5197], 'LE' => [52.6369, -1.1398], 'NN' => [52.2405, -0.9027], 'OX' => [51.7520, -1.2577],
        'PL' => [50.3755, -4.1427], 'PO' => [50.8198, -1.0877], 'RG' => [51.4561, -0.9723], 'SO' => [50.9092, -1.4041]
    ];

    uksort($areaMap, static fn($left, $right) => strlen($right) <=> strlen($left));

    foreach ($areaMap as $prefix => $coords) {
        if (str_starts_with($normalized, $prefix)) {
            return $coords;
        }
    }

    return null;
}

function approxDistanceMilesFromPostcodes($fromPostcode, $toPostcode)
{
    $fromCoords = postcodeToApproxCoords($fromPostcode);
    $toCoords = postcodeToApproxCoords($toPostcode);
    if ($fromCoords === null || $toCoords === null) {
        return null;
    }

    [$lat1, $lon1] = $fromCoords;
    [$lat2, $lon2] = $toCoords;

    $earthRadiusMiles = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusMiles * $c;
}

function mobilePostcodeCoverageError($customerPostcode, $staffMember, $defaultRadiusMiles, $maxRadiusMiles)
{
    $normalizedCustomerPostcode = normalizePostcode($customerPostcode);
    if ($normalizedCustomerPostcode === '') {
        return 'Please enter a valid UK postcode for mobile service.';
    }

    $areaMiles = (float) ($staffMember['serviceAreaMiles'] ?? $defaultRadiusMiles);
    if ($areaMiles > $maxRadiusMiles) {
        return 'This mobile specialist service area exceeds the maximum radius of ' . $maxRadiusMiles . ' miles.';
    }

    $servedPostcodes = array_filter(array_map('normalizePostcode', (array) ($staffMember['servedPostcodes'] ?? [])));
    $serviceAreas = array_map(
        static fn($area) => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $area)),
        (array) ($staffMember['serviceAreas'] ?? [])
    );
    $serviceAreas = array_filter($serviceAreas);
    $hasExplicitCoverage = !empty($servedPostcodes) || !empty($serviceAreas);

    if ($hasExplicitCoverage) {
        $postcodeIsServed = in_array($normalizedCustomerPostcode, $servedPostcodes, true);
        $outwardCode = postcodeOutwardCode($normalizedCustomerPostcode);
        foreach ($serviceAreas as $serviceArea) {
            if (str_starts_with($outwardCode, $serviceArea)) {
                $postcodeIsServed = true;
                break;
            }
        }

        if (!$postcodeIsServed) {
            return 'The customer postcode is not in this specialist\'s configured service areas.';
        }

        $homePostcode = normalizePostcode($staffMember['homePostcode'] ?? '');
        $distanceMiles = $homePostcode !== ''
            ? approxDistanceMilesFromPostcodes($homePostcode, $normalizedCustomerPostcode)
            : null;
        if ($distanceMiles !== null && $distanceMiles > $maxRadiusMiles) {
            return 'The customer postcode is outside the maximum mobile service radius of ' . $maxRadiusMiles . ' miles.';
        }

        return '';
    }

    $homePostcode = normalizePostcode($staffMember['homePostcode'] ?? '');
    if ($homePostcode === '') {
        return 'This mobile specialist needs a valid home postcode or configured served postcodes/service areas.';
    }

    $distanceMiles = approxDistanceMilesFromPostcodes($homePostcode, $normalizedCustomerPostcode);
    if ($distanceMiles === null) {
        return 'We cannot verify this postcode with the configured area map. Add it to this specialist\'s served postcodes or service areas.';
    }
    if ($distanceMiles > $maxRadiusMiles) {
        return 'The customer postcode is outside the maximum mobile service radius of ' . $maxRadiusMiles . ' miles.';
    }
    if ($distanceMiles > $areaMiles) {
        return 'The customer postcode is outside this specialist\'s service area radius of ' . $areaMiles . ' miles.';
    }

    return '';
}

loadDotEnv(__DIR__ . '/.env');

$siteName = 'Eyelash Solutions 2.0';
$siteEmail = getenv('MAIL_TO') ?: 'bookings@eyelashsolutions.example';
$siteFromEmail = getenv('MAIL_FROM') ?: $siteEmail;
$bookingOptions = [
    'studio_booking' => envBool('BOOKING_STUDIO_BOOKING', true),
    'studio_address' => envBool('BOOKING_STUDIO_ADDRESS', true),
    'mobile_service' => envBool('BOOKING_MOBILE_SERVICE', true),
];
$studioRooms = loadStudioRooms();
$mobileStaff = loadMobileStaff();
$servicePricesPence = loadServicePricesPence();
$mobileMaxRadiusMiles = max(0, min(15, envFloat('BOOKING_MOBILE_MAX_RADIUS_MILES', 15)));
$mobileDefaultRadiusMiles = max(0, min($mobileMaxRadiusMiles, envFloat('BOOKING_MOBILE_DEFAULT_RADIUS_MILES', 5)));
$paymentMode = strtolower(getenv('BOOKING_PAYMENT_MODE') ?: 'manual');
$paymentMode = in_array($paymentMode, ['manual', 'stripe'], true) ? $paymentMode : 'manual';
$depositPercent = max(1, min(100, (int) envFloat('BOOKING_DEPOSIT_PERCENT', 50)));
try {
    $settingsDatabase = bookingDatabaseConnection();
    $storedPaymentMode = getSiteSetting($settingsDatabase, 'payment_mode');
    if (in_array($storedPaymentMode, ['manual', 'stripe'], true)) {
        $paymentMode = $storedPaymentMode;
    }
    $storedDepositPercent = getSiteSetting($settingsDatabase, 'deposit_percent');
    if (ctype_digit($storedDepositPercent)) {
        $depositPercent = max(1, min(100, (int) $storedDepositPercent));
    }
} catch (Throwable $exception) {
    error_log('Booking settings lookup failed: ' . $exception->getMessage());
}
$mobileOnlyMode = !$bookingOptions['studio_booking'] && !$bookingOptions['studio_address'] && $bookingOptions['mobile_service'];

function sanitizeText($value)
{
    return trim(strip_tags($value));
}

function logBookingFailure($body, $reason = '')
{
    $dateStamp = date('Y-m-d H:i:s');
    $logEntry = "[{$dateStamp}]";
    if ($reason !== '') {
        $logEntry .= " {$reason}\n";
    }
    $logEntry .= "{$body}\n\n";
    error_log($logEntry, 3, __DIR__ . '/contact.log');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Method Not Allowed</title></head><body><p>Invalid request method.</p></body></html>';
    exit;
}

$name = sanitizeText($_POST['name'] ?? '');
$email = sanitizeText($_POST['email'] ?? '');
$phone = sanitizeText($_POST['phone'] ?? '');
$message = sanitizeText($_POST['message'] ?? '');
$bookingType = sanitizeText($_POST['booking_type'] ?? 'studio_booking');
$service = sanitizeText($_POST['service'] ?? '');
$room = sanitizeText($_POST['room'] ?? '');
$mobileStaffId = sanitizeText($_POST['mobile_staff'] ?? '');
$customerPostcode = sanitizeText($_POST['customer_postcode'] ?? '');
$isCartCheckout = array_key_exists('cart_items', $_POST);

if ($mobileOnlyMode) {
    $bookingType = 'mobile_service';
}

$errors = [];
$cartQuantities = [];
$cartLines = [];
$cartSubtotalPence = 0;
$serviceLabels = [
    'classic-lash' => 'Classic Lash Set',
    'volume-lash' => 'Volume Lash Set',
    'lash-infills' => 'Lash Infills',
    'bridal-glam' => 'Bridal Glam',
    'brow-lamination' => 'Brow Lamination',
    'lash-lift-tint' => 'Lash Lift & Tint',
];

if ($isCartCheckout) {
    $requestedCart = json_decode((string) ($_POST['cart_items'] ?? ''), true);
    if (!is_array($requestedCart) || empty($requestedCart) || count($requestedCart) > count($servicePricesPence)) {
        $errors[] = 'Your cart is empty or contains too many services.';
    } else {
        foreach ($requestedCart as $item) {
            if (!is_array($item)) {
                $errors[] = 'Your cart contains an invalid service item.';
                continue;
            }
            $itemService = sanitizeText($item['id'] ?? '');
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!isset($servicePricesPence[$itemService])) {
                $errors[] = 'Your cart contains a service that is no longer available.';
                continue;
            }
            if ($quantity === false || $quantity < 1 || $quantity > 10) {
                $errors[] = 'Each service quantity must be between 1 and 10.';
                continue;
            }
            $cartQuantities[$itemService] = min(10, ($cartQuantities[$itemService] ?? 0) + $quantity);
        }
    }

    if (!empty($cartQuantities)) {
        $service = (string) array_key_first($cartQuantities);
    }
}

$serviceIds = $isCartCheckout ? array_keys($cartQuantities) : ($service !== '' ? [$service] : []);
foreach ($cartQuantities as $cartServiceId => $quantity) {
    $unitPricePence = $servicePricesPence[$cartServiceId];
    $lineTotalPence = $unitPricePence * $quantity;
    $cartLines[] = [
        'id' => $cartServiceId,
        'label' => $serviceLabels[$cartServiceId],
        'quantity' => $quantity,
        'unitPricePence' => $unitPricePence,
        'lineTotalPence' => $lineTotalPence,
    ];
    $cartSubtotalPence += $lineTotalPence;
}

if ($name === '') {
    $errors[] = 'Please enter your name.';
}

if ($phone === '') {
    $errors[] = 'Please enter your phone number.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if (!array_key_exists($bookingType, $bookingOptions) || !$bookingOptions[$bookingType]) {
    $errors[] = 'That booking type is currently unavailable. Please choose another option.';
}

if ($service === '') {
    $errors[] = 'Please choose a service.';
}

if ($isCartCheckout && empty($cartQuantities)) {
    $errors[] = 'Add at least one available service to your cart.';
}

$roomMap = [];
foreach ($studioRooms as $studioRoom) {
    $roomId = sanitizeText($studioRoom['id'] ?? '');
    $roomServices = array_map('sanitizeText', (array) ($studioRoom['services'] ?? []));
    if ($roomId !== '') {
        $roomMap[$roomId] = $roomServices;
    }
}

if ($bookingType !== 'mobile_service') {
    if ($room === '') {
        $errors[] = 'Please choose a room.';
    }

    if ($room !== '' && !isset($roomMap[$room])) {
        $errors[] = 'That room is not available in this studio configuration.';
    }

    if ($room !== '' && isset($roomMap[$room])) {
        foreach ($serviceIds as $serviceId) {
            if (!in_array($serviceId, $roomMap[$room], true)) {
                $errors[] = 'That room is not assigned to every selected service.';
                break;
            }
        }
    }
} else {
    $room = '';
}

$mobileStaffMap = [];
foreach ($mobileStaff as $staffMember) {
    $staffId = sanitizeText($staffMember['id'] ?? '');
    $services = array_map('sanitizeText', (array) ($staffMember['services'] ?? []));
    if ($staffId !== '') {
        $mobileStaffMap[$staffId] = $staffMember;
        $mobileStaffMap[$staffId]['services'] = $services;
    }
}

if ($mobileStaffId === '') {
    $errors[] = 'Please choose a specialist.';
} elseif (!isset($mobileStaffMap[$mobileStaffId])) {
    $errors[] = 'That specialist is not available.';
} else {
    foreach ($serviceIds as $serviceId) {
        if (!in_array($serviceId, (array) ($mobileStaffMap[$mobileStaffId]['services'] ?? []), true)) {
            $errors[] = 'That specialist does not provide every selected service.';
            break;
        }
    }
}

if ($bookingType === 'mobile_service') {
    if ($customerPostcode === '') {
        $errors[] = 'Please include the customer postcode for the mobile service area check.';
    } elseif ($mobileStaffId !== '' && isset($mobileStaffMap[$mobileStaffId])) {
        $coverageError = mobilePostcodeCoverageError(
            $customerPostcode,
            $mobileStaffMap[$mobileStaffId],
            $mobileDefaultRadiusMiles,
            $mobileMaxRadiusMiles
        );
        if ($coverageError !== '') {
            $errors[] = $coverageError;
        }
    }
}

if ($message === '' && !$isCartCheckout) {
    $errors[] = 'Please include a short message about the service you need.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Enquiry Error</title><style>body{font-family:Arial,sans-serif;padding:2rem;color:#000;} .box{max-width:680px;margin:2rem auto;border:1px solid #e6d5db;border-radius:4px;padding:2rem;} ul{color:#000;} a{color:#000;}</style></head><body><div class="box"><h1>There was a problem</h1><ul>' . implode('', array_map(fn($error) => '<li>' . htmlspecialchars($error) . '</li>', $errors)) . '</ul><p><a href="contact.html">Back to contact form</a></p></div></body></html>';
    exit;
}

$bookingTypeLabel = match ($bookingType) {
    'studio_booking' => 'Studio booking',
    'studio_address' => 'Studio address',
    'mobile_service' => 'Mobile service',
    default => 'Booking enquiry',
};

$serviceLabel = $serviceLabels[$service] ?? ($service !== '' ? ucfirst(str_replace(['-', '_'], ' ', $service)) : 'Not specified');
$serviceItems = $isCartCheckout
    ? $cartLines
    : [['id' => $service, 'label' => $serviceLabel, 'quantity' => 1, 'unitPricePence' => 0, 'lineTotalPence' => 0]];
$serviceSummary = implode(', ', array_map(static fn($item) => $item['label'] . (!empty($item['quantity']) && $item['quantity'] > 1 ? ' x ' . $item['quantity'] : ''), $serviceItems));
$totalPence = $isCartCheckout ? $cartSubtotalPence : 0;
$depositPence = $isCartCheckout ? (int) ceil($totalPence * $depositPercent / 100) : 0;
$holdExpiresAt = $isCartCheckout ? gmdate('Y-m-d H:i:s', time() + 900) : '';
$cancelToken = $isCartCheckout ? bin2hex(random_bytes(32)) : '';
$roomLabel = $room !== '' ? ($roomMap[$room][0] ?? $room) : 'Not specified';
if ($room !== '' && isset($roomMap[$room])) {
    $matchedRoomName = null;
    foreach ($studioRooms as $studioRoom) {
        if (sanitizeText($studioRoom['id'] ?? '') === $room) {
            $matchedRoomName = sanitizeText($studioRoom['name'] ?? $room);
            break;
        }
    }
    if ($matchedRoomName !== null) {
        $roomLabel = $matchedRoomName;
    }
}
if ($bookingType === 'mobile_service') {
    $roomLabel = 'Mobile service';
}

$mobileStaffLabel = 'Not selected';
if ($mobileStaffId !== '' && isset($mobileStaffMap[$mobileStaffId])) {
    $mobileStaffLabel = sanitizeText($mobileStaffMap[$mobileStaffId]['name'] ?? $mobileStaffId);
}

$body = "New booking request from {$siteName}\n\n";
$body .= "Booking type: {$bookingTypeLabel}\n";
$body .= "Services: {$serviceSummary}\n";
$body .= "Room: {$roomLabel}\n";
$body .= "Assigned specialist: {$mobileStaffLabel}\n";
$body .= "Customer postcode: {$customerPostcode}\n";
$body .= "Name: {$name}\n";
$body .= "Email: {$email}\n";
if ($phone !== '') {
    $body .= "Phone: {$phone}\n";
}
if ($isCartCheckout) {
    $body .= "Services subtotal: £" . number_format($totalPence / 100, 2) . "\n";
    $body .= "Deposit ({$depositPercent}%): £" . number_format($depositPence / 100, 2) . "\n";
    $body .= "Remaining balance: £" . number_format(($totalPence - $depositPence) / 100, 2) . "\n";
}
$body .= "\nMessage:\n{$message}\n";

$bookingId = bin2hex(random_bytes(16));
$bookingRecord = [
    'id' => $bookingId,
    'staff_id' => $mobileStaffId,
    'staff_name' => $mobileStaffLabel,
    'status' => $isCartCheckout ? ($paymentMode === 'stripe' ? 'awaiting_payment' : 'awaiting_manual_deposit') : 'request_received',
    'payment_status' => $isCartCheckout ? ($paymentMode === 'stripe' ? 'pending' : 'manual_due') : 'not_required',
    'payment_mode' => $isCartCheckout ? $paymentMode : 'manual',
    'booking_type' => $bookingType,
    'service_items' => $serviceItems,
    'room_name' => $roomLabel === 'Mobile service' ? '' : $roomLabel,
    'customer_name' => $name,
    'customer_email' => $email,
    'customer_phone' => $phone,
    'customer_postcode' => $customerPostcode,
    'message' => $message,
    'total_pence' => $totalPence,
    'deposit_pence' => $depositPence,
    'hold_expires_at' => $holdExpiresAt,
    'cancel_token_hash' => $cancelToken !== '' ? hash('sha256', $cancelToken) : '',
];

if ($isCartCheckout && $paymentMode === 'stripe') {
    $stripeSecretKey = trim((string) getenv('STRIPE_SECRET_KEY'));
    $siteBaseUrl = rtrim(trim((string) getenv('BOOKING_SITE_URL')), '/');
    $siteUrlParts = parse_url($siteBaseUrl);
    $isLocalHttpUrl = is_array($siteUrlParts)
        && ($siteUrlParts['scheme'] ?? '') === 'http'
        && in_array($siteUrlParts['host'] ?? '', ['localhost', '127.0.0.1'], true);
    if ($stripeSecretKey === '' || !filter_var($siteBaseUrl, FILTER_VALIDATE_URL)
        || (($siteUrlParts['scheme'] ?? '') !== 'https' && !$isLocalHttpUrl)) {
        http_response_code(503);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Online payment unavailable</title></head><body><h1>Online payment is not configured.</h1><p>Please contact the studio or choose manual payment after the manager enables it.</p><p><a href="cart.html">Return to cart</a></p></body></html>';
        exit;
    }

    try {
        $stripe = new \Stripe\StripeClient($stripeSecretKey);
        $checkoutSession = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'expires_at' => time() + 1800,
            'customer_email' => $email,
            'phone_number_collection' => ['enabled' => true],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'gbp',
                    'product_data' => [
                        'name' => $depositPercent . '% booking deposit',
                        'description' => substr($serviceSummary, 0, 300),
                    ],
                    'unit_amount' => $depositPence,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $siteBaseUrl . '/cart.html?payment=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $siteBaseUrl . '/cart.html?payment=cancelled&booking_id=' . rawurlencode($bookingId) . '&cancel_token=' . rawurlencode($cancelToken),
            'metadata' => ['booking_id' => $bookingId],
        ]);
        $bookingRecord['stripe_session_id'] = $checkoutSession->id;
        storeBooking(bookingDatabaseConnection(), $bookingRecord);
        header('Location: ' . $checkoutSession->url, true, 303);
        exit;
    } catch (Throwable $exception) {
        logBookingFailure($body, 'Stripe checkout creation failed: ' . $exception->getMessage());
        http_response_code(502);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Online payment unavailable</title></head><body><h1>We could not start the secure payment.</h1><p>Your booking has not been charged. Please try again or contact the studio.</p><p><a href="cart.html">Return to cart</a></p></body></html>';
        exit;
    }
}

try {
    storeBooking(bookingDatabaseConnection(), $bookingRecord);
} catch (Throwable $exception) {
    error_log('Booking storage failed: ' . $exception->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Booking unavailable</title></head><body><h1>We could not save your booking request.</h1><p>Please try again later or contact the studio.</p></body></html>';
    exit;
}

$sent = sendBookingNotification(
    $siteName,
    $siteEmail,
    $siteFromEmail,
    $email,
    $name,
    'New booking request from ' . $siteName . ' [' . $bookingTypeLabel . ']',
    $body
);

if (!$sent) {
    logBookingFailure($body, 'mail delivery failed');
}

$confirmationTitle = $isCartCheckout ? 'Booking request received.' : 'Thanks for your enquiry.';
$confirmationContent = $isCartCheckout
    ? '<p>Services: ' . htmlspecialchars($serviceSummary) . '</p><p>Your total is £' . number_format($totalPence / 100, 2) . '. The ' . $depositPercent . '% deposit of £' . number_format($depositPence / 100, 2) . ' is due; the remaining balance is £' . number_format(($totalPence - $depositPence) / 100, 2) . '.</p><p>Your booking is held for 15 minutes, until ' . gmdate('H:i:s', strtotime($holdExpiresAt)) . ' UTC. The studio will contact you to arrange the deposit.</p>'
    : '<p>Your message has been sent to ' . htmlspecialchars($siteName) . '.</p><p>We will be in touch shortly to confirm your appointment.</p>';
$cancelAction = $isCartCheckout
    ? '<form action="cancel-booking.php" method="post"><input type="hidden" name="booking_id" value="' . htmlspecialchars($bookingId) . '"><input type="hidden" name="cancel_token" value="' . htmlspecialchars($cancelToken) . '"><button type="submit">Cancel this booking hold</button></form>'
    : '';
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking request received</title><style>body{font-family:Arial,sans-serif;background:#fff8f6;color:#000;padding:2rem;} .box{max-width:640px;margin:2rem auto;background:#fff;border:1px solid #efdce2;border-radius:4px;padding:2rem;} a{color:#000;text-decoration:none;font-weight:700;}button{min-height:44px;padding:.7rem 1rem;border:1px solid #000;border-radius:4px;background:#fff;color:#000;font-weight:700;cursor:pointer;}</style></head><body><div class="box"><h1>' . $confirmationTitle . '</h1>' . $confirmationContent . $cancelAction . '<p><a href="index.html">Return home</a></p></div></body></html>';

echo $html;

