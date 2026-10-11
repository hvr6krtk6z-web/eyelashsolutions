<?php
require __DIR__ . '/booking-store.php';

function loadAppointmentsEnv($filePath)
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

function appointmentsEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function managerNormalizePostcode($postcode)
{
  $postcode = strtoupper(preg_replace('/\s+/', '', trim((string) $postcode)));
  return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode) ? $postcode : '';
}

function managerStaffCoversPostcode(array $staff, $postcode)
{
  $normalized = managerNormalizePostcode($postcode);
  if ($normalized === '') {
    return false;
  }

  $servedPostcodes = array_map('managerNormalizePostcode', (array) ($staff['servedPostcodes'] ?? []));
  $outwardCode = substr($normalized, 0, -3);
  $serviceAreas = array_map(static fn($area) => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $area)), (array) ($staff['serviceAreas'] ?? []));
  if (!$servedPostcodes && !$serviceAreas) {
    return true;
  }
  if (in_array($normalized, $servedPostcodes, true)) {
    return true;
  }
  foreach ($serviceAreas as $serviceArea) {
    if ($serviceArea !== '' && str_starts_with($outwardCode, $serviceArea)) {
      return true;
    }
  }

  return false;
}

loadAppointmentsEnv(__DIR__ . '/.env');
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
$error = '';
$notice = (string) ($_SESSION['appointments_flash'] ?? '');
unset($_SESSION['appointments_flash']);
$mobileStaff = json_decode(getenv('BOOKING_MOBILE_STAFF') ?: '[]', true);
$mobileStaff = is_array($mobileStaff) ? $mobileStaff : [];
$studioRooms = json_decode(getenv('BOOKING_ROOMS') ?: '[]', true);
$studioRooms = is_array($studioRooms) ? $studioRooms : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['manager_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $error = 'Your session has expired. Reload the page and try again.';
    } else {
        $bookingId = (string) ($_POST['booking_id'] ?? '');
        $action = (string) ($_POST['booking_action'] ?? '');
        try {
        $database = bookingDatabaseConnection();
        if ($action === 'edit') {
          $booking = getBookingById($database, $bookingId);
          $items = $booking ? (json_decode($booking['service_items'], true) ?: []) : [];
          $serviceIds = array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['id'] ?? ''), $items))));
          $staffId = trim((string) ($_POST['staff_id'] ?? ''));
          $bookingType = (string) ($_POST['booking_type'] ?? '');
          $roomId = trim((string) ($_POST['room_id'] ?? ''));
          $customerPostcode = trim((string) ($_POST['customer_postcode'] ?? ''));
          $customerName = trim(strip_tags((string) ($_POST['customer_name'] ?? '')));
          $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
          $customerPhone = trim(strip_tags((string) ($_POST['customer_phone'] ?? '')));
          $message = trim(strip_tags((string) ($_POST['message'] ?? '')));
          $staffMember = null;
          foreach ($mobileStaff as $candidate) {
            if (($candidate['id'] ?? '') === $staffId) {
              $staffMember = $candidate;
              break;
            }
          }
          $staffServices = array_map('strval', (array) ($staffMember['services'] ?? []));
          $room = null;
          foreach ($studioRooms as $candidateRoom) {
            if (($candidateRoom['id'] ?? '') === $roomId) {
              $room = $candidateRoom;
              break;
            }
          }
          $roomServices = array_map('strval', (array) ($room['services'] ?? []));

          if (!$booking || !$serviceIds || !in_array($bookingType, ['studio_booking', 'studio_address', 'mobile_service'], true)
            || !$staffMember || $customerName === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || $customerPhone === '') {
            $error = 'Enter valid customer details, booking type, and assigned specialist.';
          } elseif (array_diff($serviceIds, $staffServices)) {
            $error = 'The selected specialist does not provide every service in this appointment.';
          } elseif ($bookingType !== 'mobile_service' && (!$room || array_diff($serviceIds, $roomServices))) {
            $error = 'The selected room is unavailable or does not support every service.';
          } elseif ($bookingType === 'mobile_service' && !managerStaffCoversPostcode($staffMember, $customerPostcode)) {
            $error = 'The selected specialist does not cover this valid customer postcode.';
          } else {
            $updated = updateBookingForManager($database, $bookingId, 'edit', [
              'staff_id' => $staffId,
              'staff_name' => (string) ($staffMember['name'] ?? $staffId),
              'booking_type' => $bookingType,
              'room_name' => $bookingType === 'mobile_service' ? '' : (string) ($room['name'] ?? ''),
              'customer_name' => $customerName,
              'customer_email' => $customerEmail,
              'customer_phone' => $customerPhone,
              'customer_postcode' => $bookingType === 'mobile_service' ? $customerPostcode : '',
              'message' => $message,
            ]);
            $_SESSION['appointments_flash'] = $updated ? 'Appointment details and assignment updated.' : 'This appointment can no longer be edited.';
            header('Location: appointments.php', true, 303);
            exit;
          }
        } else {
          $updated = updateBookingForManager($database, $bookingId, $action);
          $_SESSION['appointments_flash'] = $updated ? 'Appointment updated.' : 'This appointment is no longer available for that action.';
          header('Location: appointments.php', true, 303);
          exit;
        }
        } catch (Throwable $exception) {
            error_log('Manager appointment action failed: ' . $exception->getMessage());
        $error = 'The appointment could not be updated.';
        }
    }
}

$requestedStatus = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'active', 'awaiting_payment', 'awaiting_manual_deposit', 'request_received', 'confirmed', 'completed', 'cancelled', 'expired'];
$statusFilter = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'all';
$staffIdFilter = trim((string) ($_GET['staff_id'] ?? ''));
$search = trim(substr((string) ($_GET['search'] ?? ''), 0, 100));
$staffFilterIsValid = $staffIdFilter === '' || in_array($staffIdFilter, array_column($mobileStaff, 'id'), true);
if (!$staffFilterIsValid) {
    $staffIdFilter = '';
}

try {
    $database = bookingDatabaseConnection();
    $counts = getBookingCounts($database);
    $bookings = getAllBookings($database, [
        'status' => in_array($statusFilter, ['active', 'all'], true) ? '' : $statusFilter,
        'staff_id' => $staffIdFilter,
        'search' => $search,
    ]);
    if ($statusFilter === 'active') {
        $bookings = array_values(array_filter($bookings, static fn($booking) => in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit', 'request_received', 'confirmed'], true)));
    }
} catch (Throwable $exception) {
    error_log('Manager appointments lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Appointments are temporarily unavailable.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Appointments | Eyelash Solutions 2.0</title>
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
        <a href="admin.php">Overview</a>
        <a href="appointments.php" aria-current="page">Appointments</a>
        <a href="manager.php">Settings</a>
      </nav>
      <form action="admin.php" method="post" class="staff-logout-form">
        <input type="hidden" name="csrf_token" value="<?= appointmentsEscape($_SESSION['manager_csrf']) ?>" />
        <button type="submit" name="logout" value="1" class="btn btn-secondary">Sign out</button>
      </form>
    </div>
  </header>

  <main class="section">
    <div class="container admin-dashboard">
      <div class="admin-page-heading">
        <div><p class="eyebrow">Schedule overview</p><h1>Appointments</h1></div>
        <p><?= count($bookings) ?> result<?= count($bookings) === 1 ? '' : 's' ?></p>
      </div>

      <?php if ($error !== ''): ?><p class="staff-message" role="alert"><?= appointmentsEscape($error) ?></p><?php endif; ?>
      <?php if ($notice !== ''): ?><p class="manager-notice" role="status"><?= appointmentsEscape($notice) ?></p><?php endif; ?>

      <form action="appointments.php" method="get" class="appointments-filters">
        <div class="field">
          <label for="appointment-search">Search customer</label>
          <input id="appointment-search" name="search" type="search" value="<?= appointmentsEscape($search) ?>" placeholder="Name, email, phone or reference" />
        </div>
        <div class="field">
          <label for="appointment-status">Status</label>
          <select id="appointment-status" name="status">
            <?php foreach ($allowedStatuses as $statusOption): ?>
              <option value="<?= appointmentsEscape($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= appointmentsEscape(ucwords(str_replace('_', ' ', $statusOption))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="appointment-staff">Specialist</label>
          <select id="appointment-staff" name="staff_id">
            <option value="">All specialists</option>
            <?php foreach ($mobileStaff as $staffMember): ?>
              <option value="<?= appointmentsEscape($staffMember['id'] ?? '') ?>" <?= $staffIdFilter === ($staffMember['id'] ?? '') ? 'selected' : '' ?>><?= appointmentsEscape($staffMember['name'] ?? $staffMember['id'] ?? 'Specialist') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" type="submit">Filter appointments</button>
      </form>

      <section class="appointments-overview" aria-label="All appointments">
        <?php if (empty($bookings)): ?>
          <div class="admin-empty">No appointments match these filters.</div>
        <?php else: ?>
          <div class="admin-appointment-list">
            <?php foreach ($bookings as $booking): ?>
              <?php $items = json_decode($booking['service_items'], true) ?: []; ?>
              <article class="admin-appointment">
                <div class="admin-appointment-heading">
                  <div><p class="eyebrow"><?= appointmentsEscape(ucwords(str_replace('_', ' ', $booking['booking_type']))) ?></p><h2><?= appointmentsEscape($booking['customer_name']) ?></h2></div>
                  <span class="staff-status"><?= appointmentsEscape(ucwords(str_replace('_', ' ', $booking['status']))) ?></span>
                </div>
                <dl class="admin-appointment-details">
                  <div><dt>Received</dt><dd><?= appointmentsEscape(gmdate('j M Y, H:i', strtotime($booking['created_at']) ?: time())) ?> UTC</dd></div>
                  <div><dt>Services</dt><dd><?= appointmentsEscape(implode(', ', array_map(static fn($item) => ($item['label'] ?? $item['id'] ?? 'Service') . (!empty($item['quantity']) && $item['quantity'] > 1 ? ' × ' . $item['quantity'] : ''), $items))) ?></dd></div>
                  <div><dt>Specialist</dt><dd><?= appointmentsEscape($booking['staff_name']) ?></dd></div>
                  <?php if ($booking['room_name'] !== ''): ?><div><dt>Room</dt><dd><?= appointmentsEscape($booking['room_name']) ?></dd></div><?php endif; ?>
                  <?php if ($booking['customer_postcode'] !== ''): ?><div><dt>Postcode</dt><dd><?= appointmentsEscape($booking['customer_postcode']) ?></dd></div><?php endif; ?>
                  <div><dt>Contact</dt><dd><a href="mailto:<?= appointmentsEscape($booking['customer_email']) ?>"><?= appointmentsEscape($booking['customer_email']) ?></a><?php if ($booking['customer_phone'] !== ''): ?><br /><a href="tel:<?= appointmentsEscape($booking['customer_phone']) ?>"><?= appointmentsEscape($booking['customer_phone']) ?></a><?php endif; ?></dd></div>
                  <div><dt>Payment</dt><dd><?= appointmentsEscape(ucwords(str_replace('_', ' ', $booking['payment_status']))) ?><?php if ((int) $booking['total_pence'] > 0): ?> · £<?= appointmentsEscape(number_format($booking['deposit_pence'] / 100, 2)) ?> / £<?= appointmentsEscape(number_format($booking['total_pence'] / 100, 2)) ?><?php endif; ?></dd></div>
                  <?php if ($booking['hold_expires_at'] !== '' && in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit'], true)): ?><div><dt>Hold expires</dt><dd><?= appointmentsEscape(gmdate('j M Y, H:i', strtotime($booking['hold_expires_at']) ?: time())) ?> UTC</dd></div><?php endif; ?>
                </dl>
                <?php if ($booking['message'] !== ''): ?><p class="staff-booking-message"><?= nl2br(appointmentsEscape($booking['message'])) ?></p><?php endif; ?>
                <?php if (in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit', 'request_received', 'confirmed'], true)): ?>
                  <form action="appointments.php" method="post" class="staff-booking-actions">
                    <input type="hidden" name="csrf_token" value="<?= appointmentsEscape($_SESSION['manager_csrf']) ?>" />
                    <input type="hidden" name="booking_id" value="<?= appointmentsEscape($booking['id']) ?>" />
                    <?php if ($booking['status'] !== 'awaiting_payment'): ?><button class="btn btn-primary" type="submit" name="booking_action" value="complete">Complete</button><?php endif; ?>
                    <?php if (in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit', 'request_received'], true)): ?><button class="btn btn-secondary" type="submit" name="booking_action" value="cancel">Cancel</button><?php endif; ?>
                  </form>
                <?php endif; ?>
                <?php if (!in_array($booking['status'], ['completed', 'cancelled', 'expired'], true)): ?>
                  <details class="appointment-edit">
                    <summary>Edit details and assignment</summary>
                    <form action="appointments.php" method="post" class="appointment-edit-form">
                      <input type="hidden" name="csrf_token" value="<?= appointmentsEscape($_SESSION['manager_csrf']) ?>" />
                      <input type="hidden" name="booking_id" value="<?= appointmentsEscape($booking['id']) ?>" />
                      <input type="hidden" name="booking_action" value="edit" />
                      <div class="field">
                        <label for="edit-name-<?= appointmentsEscape($booking['id']) ?>">Customer name</label>
                        <input id="edit-name-<?= appointmentsEscape($booking['id']) ?>" name="customer_name" value="<?= appointmentsEscape($booking['customer_name']) ?>" required />
                      </div>
                      <div class="field">
                        <label for="edit-email-<?= appointmentsEscape($booking['id']) ?>">Email</label>
                        <input id="edit-email-<?= appointmentsEscape($booking['id']) ?>" name="customer_email" type="email" value="<?= appointmentsEscape($booking['customer_email']) ?>" required />
                      </div>
                      <div class="field">
                        <label for="edit-phone-<?= appointmentsEscape($booking['id']) ?>">Phone</label>
                        <input id="edit-phone-<?= appointmentsEscape($booking['id']) ?>" name="customer_phone" type="tel" value="<?= appointmentsEscape($booking['customer_phone']) ?>" required />
                      </div>
                      <div class="field">
                        <label for="edit-type-<?= appointmentsEscape($booking['id']) ?>">Booking type</label>
                        <select id="edit-type-<?= appointmentsEscape($booking['id']) ?>" name="booking_type" required>
                          <?php foreach (['studio_booking' => 'Studio booking', 'studio_address' => 'Studio address', 'mobile_service' => 'Mobile service'] as $typeId => $typeLabel): ?>
                            <option value="<?= appointmentsEscape($typeId) ?>" <?= $booking['booking_type'] === $typeId ? 'selected' : '' ?>><?= appointmentsEscape($typeLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label for="edit-staff-<?= appointmentsEscape($booking['id']) ?>">Assigned specialist</label>
                        <select id="edit-staff-<?= appointmentsEscape($booking['id']) ?>" name="staff_id" required>
                          <?php foreach ($mobileStaff as $staffMember): ?>
                            <option value="<?= appointmentsEscape($staffMember['id'] ?? '') ?>" <?= $booking['staff_id'] === ($staffMember['id'] ?? '') ? 'selected' : '' ?>><?= appointmentsEscape($staffMember['name'] ?? $staffMember['id'] ?? 'Specialist') ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label for="edit-room-<?= appointmentsEscape($booking['id']) ?>">Studio room</label>
                        <select id="edit-room-<?= appointmentsEscape($booking['id']) ?>" name="room_id">
                          <option value="">No room (mobile)</option>
                          <?php foreach ($studioRooms as $roomOption): ?>
                            <option value="<?= appointmentsEscape($roomOption['id'] ?? '') ?>" <?= $booking['room_name'] === ($roomOption['name'] ?? '') ? 'selected' : '' ?>><?= appointmentsEscape($roomOption['name'] ?? $roomOption['id'] ?? 'Room') ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label for="edit-postcode-<?= appointmentsEscape($booking['id']) ?>">Customer postcode</label>
                        <input id="edit-postcode-<?= appointmentsEscape($booking['id']) ?>" name="customer_postcode" value="<?= appointmentsEscape($booking['customer_postcode']) ?>" maxlength="10" />
                      </div>
                      <div class="field edit-message-field">
                        <label for="edit-message-<?= appointmentsEscape($booking['id']) ?>">Appointment note</label>
                        <textarea id="edit-message-<?= appointmentsEscape($booking['id']) ?>" name="message"><?= appointmentsEscape($booking['message']) ?></textarea>
                      </div>
                      <button class="btn btn-primary" type="submit">Save changes</button>
                    </form>
                  </details>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
  <script src="template-config.js"></script>
</body>
</html>
