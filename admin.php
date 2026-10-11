<?php
require __DIR__ . '/booking-store.php';

function loadAdminEnv($filePath)
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

function adminEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

loadAdminEnv(__DIR__ . '/.env');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('es20_manager_session');
session_start();

if (empty($_SESSION['manager_authenticated'])) {
    header('Location: manager.php', true, 303);
    exit;
}

$_SESSION['manager_csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!hash_equals($_SESSION['manager_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Your session has expired. Reload the page and try again.');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: manager.php', true, 303);
    exit;
}

try {
    $database = bookingDatabaseConnection();
    $counts = getBookingCounts($database);
    $recentBookings = getAllBookings($database);
} catch (Throwable $exception) {
    error_log('Admin dashboard lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The admin dashboard is temporarily unavailable.');
}

$activeBookings = array_values(array_filter($recentBookings, static fn($booking) => in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit', 'request_received', 'confirmed'], true)));
$activeBookings = array_slice($activeBookings, 0, 8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manager dashboard | Eyelash Solutions 2.0</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <header class="topbar">
    <div class="container nav">
      <a class="brand" href="admin.php" aria-label="Manager dashboard">
        <span class="brand-mark">E</span>
        <span class="brand-text"><strong>Eyelash Solutions 2.0</strong><small>Manager dashboard</small></span>
      </a>
      <nav class="menu" aria-label="Manager navigation">
        <a href="admin.php" aria-current="page">Overview</a>
        <a href="appointments.php">Appointments</a>
        <a href="manager.php">Settings</a>
      </nav>
      <form action="admin.php" method="post" class="staff-logout-form">
        <input type="hidden" name="csrf_token" value="<?= adminEscape($_SESSION['manager_csrf']) ?>" />
        <button type="submit" name="logout" value="1" class="btn btn-secondary">Sign out</button>
      </form>
    </div>
  </header>

  <main class="section">
    <div class="container admin-dashboard">
      <div class="admin-page-heading">
        <div><p class="eyebrow">Operations</p><h1>Overview</h1></div>
        <a class="btn btn-primary" href="appointments.php">View appointments</a>
      </div>

      <section class="admin-stat-grid" aria-label="Appointment totals">
        <a class="admin-stat" href="appointments.php"><span>All appointments</span><strong><?= (int) $counts['all'] ?></strong></a>
        <a class="admin-stat" href="appointments.php?status=active"><span>Active</span><strong><?= (int) $counts['active'] ?></strong></a>
        <a class="admin-stat" href="appointments.php?status=awaiting_payment"><span>Awaiting payment</span><strong><?= (int) $counts['awaiting_payment'] ?></strong></a>
        <a class="admin-stat" href="appointments.php?status=completed"><span>Completed</span><strong><?= (int) $counts['completed'] ?></strong></a>
      </section>

      <section class="admin-recent-section">
        <div class="admin-section-heading"><h2>Upcoming work</h2><a href="appointments.php">All appointments</a></div>
        <?php if (empty($activeBookings)): ?>
          <div class="admin-empty">No active appointments.</div>
        <?php else: ?>
          <div class="admin-booking-list">
            <?php foreach ($activeBookings as $booking): ?>
              <?php $items = json_decode($booking['service_items'], true) ?: []; ?>
              <a class="admin-booking-row" href="appointments.php?search=<?= rawurlencode($booking['customer_name']) ?>">
                <span class="admin-booking-customer"><?= adminEscape($booking['customer_name']) ?></span>
                <span><?= adminEscape(implode(', ', array_map(static fn($item) => ($item['label'] ?? $item['id'] ?? 'Service') . (!empty($item['quantity']) && $item['quantity'] > 1 ? ' × ' . $item['quantity'] : ''), $items))) ?></span>
                <span><?= adminEscape($booking['staff_name']) ?></span>
                <span><?= adminEscape(ucwords(str_replace('_', ' ', $booking['status']))) ?></span>
                <time datetime="<?= adminEscape(gmdate('c', strtotime($booking['created_at']) ?: time())) ?>"><?= adminEscape(gmdate('j M, H:i', strtotime($booking['created_at']) ?: time())) ?> UTC</time>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
  <script src="template-config.js"></script>
</body>
</html>
