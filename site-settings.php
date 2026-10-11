<?php
require __DIR__ . '/booking-store.php';

function loadPublicSettingsEnv($filePath)
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

function publicSettingBool($name, $default = true)
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

loadPublicSettingsEnv(__DIR__ . '/.env');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$defaultLogoUrl = '';
$logoFiles = glob(__DIR__ . '/images/*', GLOB_NOSORT) ?: [];
foreach ($logoFiles as $logoFile) {
    $logoName = strtolower(basename($logoFile));
    if (preg_match('/\.(png|jpe?g|webp)$/i', $logoName) && preg_match('/logo|logotipo/', $logoName)) {
        $defaultLogoUrl = 'images/' . basename($logoFile);
        break;
    }
}

$settings = [
    'logoUrl' => $defaultLogoUrl,
    'backgroundColor' => '#fbfaf7',
    'bookingOptions' => [
        'studio_booking' => publicSettingBool('BOOKING_STUDIO_BOOKING', true),
        'studio_address' => publicSettingBool('BOOKING_STUDIO_ADDRESS', true),
        'mobile_service' => publicSettingBool('BOOKING_MOBILE_SERVICE', true),
    ],
    'paymentMode' => strtolower(getenv('BOOKING_PAYMENT_MODE') ?: 'manual') === 'stripe' ? 'stripe' : 'manual',
    'depositPercent' => max(1, min(100, (int) (getenv('BOOKING_DEPOSIT_PERCENT') ?: 50))),
    'servicePricesPence' => [
        'classic-lash' => 6000,
        'volume-lash' => 8000,
        'lash-infills' => 3000,
        'bridal-glam' => 9500,
        'brow-lamination' => 5500,
        'lash-lift-tint' => 4500,
    ],
];

$configuredPrices = json_decode(getenv('BOOKING_SERVICE_PRICES') ?: '', true);
if (is_array($configuredPrices)) {
    foreach ($settings['servicePricesPence'] as $serviceId => $defaultPrice) {
        if (isset($configuredPrices[$serviceId]) && is_numeric($configuredPrices[$serviceId])) {
            $settings['servicePricesPence'][$serviceId] = max(1, (int) $configuredPrices[$serviceId]);
        }
    }
}

try {
    $database = bookingDatabaseConnection();
    $storedPaymentMode = getSiteSetting($database, 'payment_mode');
    if (in_array($storedPaymentMode, ['manual', 'stripe'], true)) {
        $settings['paymentMode'] = $storedPaymentMode;
    }
    $storedDepositPercent = getSiteSetting($database, 'deposit_percent');
    if (ctype_digit($storedDepositPercent)) {
        $settings['depositPercent'] = max(1, min(100, (int) $storedDepositPercent));
    }

    $logoPath = getSiteSetting($database, 'brand_logo');
    if (preg_match('#^uploads/brand-[a-f0-9]{32}\.(png|jpg|webp)$#', $logoPath)
        && is_file(__DIR__ . '/' . $logoPath)) {
        $settings['logoUrl'] = $logoPath;
    }

    $backgroundColor = strtoupper(getSiteSetting($database, 'brand_background', '#fbfaf7'));
    if (preg_match('/^#[0-9A-F]{6}$/', $backgroundColor)) {
        $settings['backgroundColor'] = $backgroundColor;
    }
} catch (Throwable $exception) {
    error_log('Public site settings lookup failed: ' . $exception->getMessage());
}

echo json_encode($settings, JSON_UNESCAPED_SLASHES);
