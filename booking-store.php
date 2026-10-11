<?php
date_default_timezone_set('UTC');

function bookingDatabaseConnection()
{
    $databasePath = getenv('BOOKING_DATABASE_PATH') ?: sys_get_temp_dir() . '/eyelashsolutions-2.0/bookings.sqlite';
    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0700, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Could not create the booking database directory.');
    }

    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS bookings (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            staff_id TEXT NOT NULL,
            staff_name TEXT NOT NULL,
            status TEXT NOT NULL,
            payment_status TEXT NOT NULL,
            payment_mode TEXT NOT NULL,
            booking_type TEXT NOT NULL,
            service_items TEXT NOT NULL,
            room_name TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            customer_email TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            customer_postcode TEXT NOT NULL,
            message TEXT NOT NULL,
            total_pence INTEGER NOT NULL DEFAULT 0,
            deposit_pence INTEGER NOT NULL DEFAULT 0,
            stripe_session_id TEXT NOT NULL DEFAULT "",
            hold_expires_at TEXT NOT NULL DEFAULT "",
            cancel_token_hash TEXT NOT NULL DEFAULT ""
        )'
    );
    $bookingColumns = array_column($database->query('PRAGMA table_info(bookings)')->fetchAll(), 'name');
    if (!in_array('hold_expires_at', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN hold_expires_at TEXT NOT NULL DEFAULT ""');
    }
    if (!in_array('cancel_token_hash', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN cancel_token_hash TEXT NOT NULL DEFAULT ""');
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        )'
    );

    return $database;
}

function getSiteSetting(PDO $database, $key, $default = '')
{
    $statement = $database->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
    $statement->execute([':key' => $key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : $value;
}

function setSiteSetting(PDO $database, $key, $value)
{
    $statement = $database->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
    );
    $statement->execute([':key' => $key, ':value' => $value]);
}

function storeBooking(PDO $database, array $booking)
{
    $statement = $database->prepare(
        'INSERT INTO bookings (
            id, created_at, staff_id, staff_name, status, payment_status, payment_mode,
            booking_type, service_items, room_name, customer_name, customer_email,
            customer_phone, customer_postcode, message, total_pence, deposit_pence, stripe_session_id
            , hold_expires_at, cancel_token_hash
        ) VALUES (
            :id, :created_at, :staff_id, :staff_name, :status, :payment_status, :payment_mode,
            :booking_type, :service_items, :room_name, :customer_name, :customer_email,
            :customer_phone, :customer_postcode, :message, :total_pence, :deposit_pence, :stripe_session_id,
            :hold_expires_at, :cancel_token_hash
        )'
    );
    $statement->execute([
        ':id' => $booking['id'],
        ':created_at' => $booking['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ':staff_id' => $booking['staff_id'],
        ':staff_name' => $booking['staff_name'],
        ':status' => $booking['status'],
        ':payment_status' => $booking['payment_status'],
        ':payment_mode' => $booking['payment_mode'],
        ':booking_type' => $booking['booking_type'],
        ':service_items' => json_encode($booking['service_items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':room_name' => $booking['room_name'],
        ':customer_name' => $booking['customer_name'],
        ':customer_email' => $booking['customer_email'],
        ':customer_phone' => $booking['customer_phone'],
        ':customer_postcode' => $booking['customer_postcode'],
        ':message' => $booking['message'],
        ':total_pence' => $booking['total_pence'],
        ':deposit_pence' => $booking['deposit_pence'],
        ':stripe_session_id' => $booking['stripe_session_id'] ?? '',
        ':hold_expires_at' => $booking['hold_expires_at'] ?? '',
        ':cancel_token_hash' => $booking['cancel_token_hash'] ?? '',
    ]);
}

function expireStaleBookingHolds(PDO $database)
{
    $statement = $database->prepare(
        'UPDATE bookings
         SET status = :status, payment_status = :payment_status
         WHERE status IN (:awaiting_payment, :awaiting_manual_deposit)
           AND hold_expires_at != "" AND hold_expires_at <= :now'
    );
    $statement->execute([
        ':status' => 'expired',
        ':payment_status' => 'expired',
        ':awaiting_payment' => 'awaiting_payment',
        ':awaiting_manual_deposit' => 'awaiting_manual_deposit',
        ':now' => gmdate('Y-m-d H:i:s'),
    ]);
    return $statement->rowCount();
}

function cancelBookingForCustomer(PDO $database, $bookingId, $cancelToken)
{
    expireStaleBookingHolds($database);
    $booking = getBookingById($database, $bookingId);
    if (!$booking || $booking['cancel_token_hash'] === ''
        || !hash_equals($booking['cancel_token_hash'], hash('sha256', (string) $cancelToken))
        || !in_array($booking['status'], ['awaiting_payment', 'awaiting_manual_deposit'], true)) {
        return false;
    }

    $statement = $database->prepare(
        'UPDATE bookings SET status = :status, payment_status = :payment_status WHERE id = :id'
    );
    $statement->execute([':status' => 'cancelled', ':payment_status' => 'cancelled', ':id' => $bookingId]);
    return $statement->rowCount() === 1;
}

function cancelBookingForStaff(PDO $database, $bookingId, $staffId)
{
    expireStaleBookingHolds($database);
    $statement = $database->prepare(
        'UPDATE bookings
         SET status = :status,
             payment_status = CASE WHEN payment_status IN (:pending_payment, :manual_due) THEN :cancelled_payment ELSE payment_status END
         WHERE id = :id AND staff_id = :staff_id
           AND status IN (:awaiting_payment, :awaiting_manual_deposit, :request_received)'
    );
    $statement->execute([
        ':status' => 'cancelled',
        ':pending_payment' => 'pending',
        ':manual_due' => 'manual_due',
        ':cancelled_payment' => 'cancelled',
        ':id' => $bookingId,
        ':staff_id' => $staffId,
        ':awaiting_payment' => 'awaiting_payment',
        ':awaiting_manual_deposit' => 'awaiting_manual_deposit',
        ':request_received' => 'request_received',
    ]);
    return $statement->rowCount() === 1;
}

function completeBookingForStaff(PDO $database, $bookingId, $staffId)
{
    expireStaleBookingHolds($database);
    $statement = $database->prepare(
        'UPDATE bookings SET status = :status WHERE id = :id AND staff_id = :staff_id
         AND status IN (:awaiting_manual_deposit, :request_received, :confirmed)'
    );
    $statement->execute([
        ':status' => 'completed',
        ':id' => $bookingId,
        ':staff_id' => $staffId,
        ':awaiting_manual_deposit' => 'awaiting_manual_deposit',
        ':request_received' => 'request_received',
        ':confirmed' => 'confirmed',
    ]);
    return $statement->rowCount() === 1;
}

function setBookingStripeSession(PDO $database, $bookingId, $sessionId)
{
    $statement = $database->prepare('UPDATE bookings SET stripe_session_id = :session_id WHERE id = :id');
    $statement->execute([':session_id' => $sessionId, ':id' => $bookingId]);
}

function confirmStripeBooking(PDO $database, $bookingId, $sessionId)
{
    $statement = $database->prepare(
        'UPDATE bookings
         SET status = :status, payment_status = :payment_status
         WHERE id = :id AND stripe_session_id = :session_id
           AND status = :awaiting_status AND hold_expires_at > :now'
    );
    $statement->execute([
        ':status' => 'confirmed',
        ':payment_status' => 'paid',
        ':id' => $bookingId,
        ':session_id' => $sessionId,
        ':awaiting_status' => 'awaiting_payment',
        ':now' => gmdate('Y-m-d H:i:s'),
    ]);

    return $statement->rowCount() === 1;
}

function markStripeBookingRefunded(PDO $database, $bookingId, $sessionId)
{
    $statement = $database->prepare(
        'UPDATE bookings SET payment_status = :payment_status
         WHERE id = :id AND stripe_session_id = :session_id
           AND status IN (:expired_status, :cancelled_status)'
    );
    $statement->execute([
        ':payment_status' => 'refunded',
        ':id' => $bookingId,
        ':session_id' => $sessionId,
        ':expired_status' => 'expired',
        ':cancelled_status' => 'cancelled',
    ]);
    return $statement->rowCount() === 1;
}

function getBookingsForStaff(PDO $database, $staffId)
{
    expireStaleBookingHolds($database);
    $statement = $database->prepare(
        'SELECT * FROM bookings WHERE staff_id = :staff_id ORDER BY created_at DESC LIMIT 100'
    );
    $statement->execute([':staff_id' => $staffId]);
    return $statement->fetchAll();
}

function getAllBookings(PDO $database, array $filters = [])
{
    expireStaleBookingHolds($database);
    $conditions = [];
    $parameters = [];

    if (!empty($filters['status'])) {
        $conditions[] = 'status = :status';
        $parameters[':status'] = $filters['status'];
    }
    if (!empty($filters['staff_id'])) {
        $conditions[] = 'staff_id = :staff_id';
        $parameters[':staff_id'] = $filters['staff_id'];
    }
    if (!empty($filters['search'])) {
        $conditions[] = '(customer_name LIKE :search OR customer_email LIKE :search OR customer_phone LIKE :search OR id LIKE :search)';
        $parameters[':search'] = '%' . $filters['search'] . '%';
    }

    $sql = 'SELECT * FROM bookings';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 500';

    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function getBookingCounts(PDO $database)
{
    expireStaleBookingHolds($database);
    $rows = $database->query('SELECT status, COUNT(*) AS total FROM bookings GROUP BY status')->fetchAll();
    $counts = ['all' => 0, 'active' => 0, 'awaiting_payment' => 0, 'confirmed' => 0, 'completed' => 0];
    foreach ($rows as $row) {
        $status = $row['status'];
        $total = (int) $row['total'];
        $counts['all'] += $total;
        if (in_array($status, ['awaiting_payment', 'awaiting_manual_deposit', 'request_received', 'confirmed'], true)) {
            $counts['active'] += $total;
        }
        if (in_array($status, ['awaiting_payment', 'awaiting_manual_deposit'], true)) {
            $counts['awaiting_payment'] += $total;
        }
        if ($status === 'confirmed') {
            $counts['confirmed'] += $total;
        }
        if ($status === 'completed') {
            $counts['completed'] += $total;
        }
    }

    return $counts;
}

function updateBookingForManager(PDO $database, $bookingId, $action, array $fields = [])
{
    expireStaleBookingHolds($database);
    if ($action === 'edit') {
        $statement = $database->prepare(
            'UPDATE bookings SET
                staff_id = :staff_id,
                staff_name = :staff_name,
                booking_type = :booking_type,
                room_name = :room_name,
                customer_name = :customer_name,
                customer_email = :customer_email,
                customer_phone = :customer_phone,
                customer_postcode = :customer_postcode,
                message = :message
             WHERE id = :id AND status NOT IN (:cancelled, :expired, :completed)'
        );
        $statement->execute([
            ':staff_id' => $fields['staff_id'],
            ':staff_name' => $fields['staff_name'],
            ':booking_type' => $fields['booking_type'],
            ':room_name' => $fields['room_name'],
            ':customer_name' => $fields['customer_name'],
            ':customer_email' => $fields['customer_email'],
            ':customer_phone' => $fields['customer_phone'],
            ':customer_postcode' => $fields['customer_postcode'],
            ':message' => $fields['message'],
            ':id' => $bookingId,
            ':cancelled' => 'cancelled',
            ':expired' => 'expired',
            ':completed' => 'completed',
        ]);
        return $statement->rowCount() === 1;
    }

    if ($action === 'complete') {
        $statement = $database->prepare(
            'UPDATE bookings SET status = :status WHERE id = :id
             AND status IN (:manual_hold, :request_received, :confirmed)'
        );
        $statement->execute([
            ':status' => 'completed',
            ':id' => $bookingId,
            ':manual_hold' => 'awaiting_manual_deposit',
            ':request_received' => 'request_received',
            ':confirmed' => 'confirmed',
        ]);
        return $statement->rowCount() === 1;
    }
    if ($action === 'cancel') {
        $statement = $database->prepare(
            'UPDATE bookings
             SET status = :status,
                 payment_status = CASE WHEN payment_status IN (:pending_payment, :manual_due) THEN :cancelled_payment ELSE payment_status END
             WHERE id = :id AND status IN (:stripe_hold, :manual_hold, :request_received)'
        );
        $statement->execute([
            ':status' => 'cancelled',
            ':pending_payment' => 'pending',
            ':manual_due' => 'manual_due',
            ':cancelled_payment' => 'cancelled',
            ':id' => $bookingId,
            ':stripe_hold' => 'awaiting_payment',
            ':manual_hold' => 'awaiting_manual_deposit',
            ':request_received' => 'request_received',
        ]);
        return $statement->rowCount() === 1;
    }

    return false;
}

function getBookingById(PDO $database, $bookingId)
{
    $statement = $database->prepare('SELECT * FROM bookings WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $bookingId]);
    $booking = $statement->fetch();
    return $booking ?: null;
}
