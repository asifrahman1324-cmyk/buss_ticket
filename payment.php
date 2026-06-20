<?php
require_once __DIR__ . '/config.php';
require_login();

$pageTitle = 'Payment';
$user = current_user();
$isStaffSide = in_array($user['role'], ['admin', 'staff'], true);
$action = $_GET['action'] ?? 'history';

// -----------------------------------------------------------------
// POST: record a payment
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = input('form_action');

    if ($postAction === 'record') {
        $bookingId = (int)input('booking_id');
        $method    = input('method');
        $transRef  = input('transaction_ref');

        $bkStmt = $pdo->prepare(
            'SELECT bk.*, p.payment_id, p.status AS payment_status
             FROM booking bk LEFT JOIN payment p ON p.booking_id = bk.booking_id
             WHERE bk.booking_id = :id'
        );
        $bkStmt->execute(['id' => $bookingId]);
        $booking = $bkStmt->fetch();

        $errors = [];
        if (!$booking) {
            $errors[] = 'Booking not found.';
        } elseif (!$isStaffSide && (int)$booking['customer_id'] !== (int)$user['user_id']) {
            $errors[] = 'You can only pay for your own bookings.';
        } elseif ($booking['booking_status'] !== 'confirmed') {
            $errors[] = 'This booking is ' . $booking['booking_status'] . ' and cannot be paid for.';
        } elseif ($booking['payment_status'] === 'paid') {
            $errors[] = 'This booking has already been paid for.';
        }
        if (!in_array($method, ['cash', 'card', 'mobile_banking', 'bank_transfer'], true)) {
            $errors[] = 'Please select a valid payment method.';
        }

        if (empty($errors)) {
            if ($booking['payment_id']) {
                $pdo->prepare(
                    "UPDATE payment SET amount = :amount, method = :method, status = 'paid',
                            transaction_ref = :ref, paid_at = CURRENT_TIMESTAMP
                     WHERE payment_id = :pid"
                )->execute([
                    'amount' => $booking['total_amount'], 'method' => $method,
                    'ref' => $transRef !== '' ? $transRef : null, 'pid' => $booking['payment_id'],
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO payment (booking_id, amount, method, status, transaction_ref, paid_at)
                     VALUES (:bid, :amount, :method, 'paid', :ref, CURRENT_TIMESTAMP)"
                )->execute([
                    'bid' => $bookingId, 'amount' => $booking['total_amount'], 'method' => $method,
                    'ref' => $transRef !== '' ? $transRef : null,
                ]);
            }
            flash('success', 'Payment recorded successfully for booking #' . $bookingId . '.');
            redirect('booking.php?action=ticket&booking_id=' . $bookingId);
        }
        flash('danger', implode(' ', $errors));
        redirect('payment.php?action=pay&booking_id=' . $bookingId);
    }
}

// -----------------------------------------------------------------
// GET views
// -----------------------------------------------------------------
if ($action === 'pay') {
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    $stmt = $pdo->prepare(
        'SELECT bk.*, u.full_name AS customer_name, r.origin, r.destination, s.departure_date, s.departure_time,
                p.status AS payment_status
         FROM booking bk
         JOIN users u ON u.user_id = bk.customer_id
         JOIN schedule s ON s.schedule_id = bk.schedule_id
         JOIN route r ON r.route_id = s.route_id
         LEFT JOIN payment p ON p.booking_id = bk.booking_id
         WHERE bk.booking_id = :id'
    );
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        flash('danger', 'Booking not found.');
        redirect('payment.php');
    }
    if (!$isStaffSide && (int)$booking['customer_id'] !== (int)$user['user_id']) {
        http_response_code(403);
        die('403 - You do not have permission to pay for this booking.');
    }
    if ($booking['payment_status'] === 'paid') {
        flash('warning', 'This booking has already been paid for.');
        redirect('booking.php?action=ticket&booking_id=' . $bookingId);
    }
}

if ($action === 'history') {
    if ($isStaffSide) {
        $filterDate = input('date', '', 'get');
        $sql = "SELECT p.*, bk.booking_id, u.full_name AS customer_name, r.origin, r.destination
                FROM payment p
                JOIN booking bk ON bk.booking_id = p.booking_id
                JOIN users u ON u.user_id = bk.customer_id
                JOIN schedule s ON s.schedule_id = bk.schedule_id
                JOIN route r ON r.route_id = s.route_id";
        $params = [];
        if ($filterDate !== '') {
            $sql .= ' WHERE p.paid_at::date = :d';
            $params['d'] = $filterDate;
        }
        $sql .= ' ORDER BY p.payment_id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT p.*, bk.booking_id, r.origin, r.destination
             FROM payment p
             JOIN booking bk ON bk.booking_id = p.booking_id
             JOIN schedule s ON s.schedule_id = bk.schedule_id
             JOIN route r ON r.route_id = s.route_id
             WHERE bk.customer_id = :uid
             ORDER BY p.payment_id DESC"
        );
        $stmt->execute(['uid' => $user['user_id']]);
        $payments = $stmt->fetchAll();
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($action === 'pay'): ?>

    <div class="card section-card pay-card">
        <h2>Complete Payment</h2>
        <div class="pay-summary">
            <div><span>Booking</span><strong>#<?= (int)$booking['booking_id'] ?></strong></div>
            <div><span>Customer</span><strong><?= e($booking['customer_name']) ?></strong></div>
            <div><span>Route</span><strong><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></strong></div>
            <div><span>Date</span><strong><?= e($booking['departure_date']) ?> <?= e(substr($booking['departure_time'], 0, 5)) ?></strong></div>
            <div><span>Seats</span><strong class="seat-mono"><?= e($booking['seat_numbers']) ?></strong></div>
            <div class="pay-amount"><span>Amount Due</span><strong><?= e(money($booking['total_amount'])) ?></strong></div>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="record">
            <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
            <div class="mb-3">
                <label class="form-label">Payment Method</label>
                <select name="method" class="form-select" required>
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="mobile_banking">Mobile Banking</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Transaction Reference (optional)</label>
                <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. TRX123456">
            </div>
            <button type="submit" class="btn btn-primary w-100">Confirm Payment of <?= e(money($booking['total_amount'])) ?></button>
        </form>
    </div>

<?php else: ?>

    <div class="card section-card">
        <div class="section-card-header">
            <h2><?= $isStaffSide ? 'All Payments' : 'My Payment History' ?></h2>
            <?php if ($isStaffSide): ?>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="action" value="history">
                <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table app-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Payment #</th><th>Booking</th>
                    <?php if ($isStaffSide): ?><th>Customer</th><?php endif; ?>
                    <th>Route</th><th>Amount</th><th>Method</th><th>Status</th><th>Paid At</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No payments found.</td></tr>
                <?php endif; ?>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>#<?= (int)$p['payment_id'] ?></td>
                        <td><a href="booking.php?action=ticket&booking_id=<?= (int)$p['booking_id'] ?>">#<?= (int)$p['booking_id'] ?></a></td>
                        <?php if ($isStaffSide): ?><td><?= e($p['customer_name']) ?></td><?php endif; ?>
                        <td><?= e($p['origin']) ?> &rarr; <?= e($p['destination']) ?></td>
                        <td><?= e(money($p['amount'])) ?></td>
                        <td><span class="badge text-bg-light border"><?= e(ucwords(str_replace('_', ' ', $p['method']))) ?></span></td>
                        <td><span class="badge status-badge status-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                        <td><?= $p['paid_at'] ? e($p['paid_at']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>