<?php
require_once __DIR__ . '/config.php';
require_login();

$pageTitle = 'Ticket Booking';
$user = current_user();
$isStaffSide = in_array($user['role'], ['admin', 'staff'], true);
$action = $_GET['action'] ?? 'search';

/** Return the list of seat numbers already booked (confirmed) for a schedule. */
function booked_seat_numbers(PDO $pdo, int $scheduleId): array
{
    $stmt = $pdo->prepare("SELECT seat_numbers FROM booking WHERE schedule_id = :sid AND booking_status = 'confirmed'");
    $stmt->execute(['sid' => $scheduleId]);
    $taken = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach (explode(',', $row['seat_numbers']) as $seat) {
            $taken[] = trim($seat);
        }
    }
    return $taken;
}

// -----------------------------------------------------------------
// POST: create a booking, or cancel a booking
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = input('form_action');

    if ($postAction === 'book') {
        $scheduleId = (int)input('schedule_id');
        $passengerNames = $_POST['passenger_names'] ?? [];
        $customerId = $isStaffSide ? (int)input('customer_id') : (int)$user['user_id'];

        $errors = [];
        if ($scheduleId <= 0) $errors[] = 'Invalid schedule.';
        if ($customerId <= 0) $errors[] = 'Please select the customer for this booking.';
        if (!is_array($passengerNames) || empty($passengerNames)) $errors[] = 'Please select at least one seat.';

        $seatNumbers = [];
        if (is_array($passengerNames)) {
            foreach ($passengerNames as $seat => $name) {
                $seat = trim((string)$seat);
                $name = trim((string)$name);
                if ($seat === '' || $name === '') {
                    $errors[] = 'Every selected seat needs a passenger name.';
                    break;
                }
                $seatNumbers[] = $seat;
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $schStmt = $pdo->prepare(
                    'SELECT s.*, b.total_seats FROM schedule s JOIN bus b ON b.bus_id = s.bus_id
                     WHERE s.schedule_id = :sid FOR UPDATE'
                );
                $schStmt->execute(['sid' => $scheduleId]);
                $schedule = $schStmt->fetch();

                if (!$schedule) {
                    throw new RuntimeException('This schedule no longer exists.');
                }

                $custStmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = :id AND role = 'customer'");
                $custStmt->execute(['id' => $customerId]);
                if (!$custStmt->fetch()) {
                    throw new RuntimeException('Selected customer account was not found.');
                }

                $taken = booked_seat_numbers($pdo, $scheduleId);
                $conflict = array_intersect($seatNumbers, $taken);
                if (!empty($conflict)) {
                    throw new RuntimeException('Seat(s) ' . implode(', ', $conflict) . ' were just booked by someone else. Please choose different seats.');
                }

                $seatCount = count($seatNumbers);
                if ($seatCount > (int)$schedule['available_seats']) {
                    throw new RuntimeException('Not enough seats available on this schedule.');
                }

                $totalAmount = (float)$schedule['fare'] * $seatCount;

                $bookingStmt = $pdo->prepare(
                    'INSERT INTO booking (customer_id, schedule_id, seat_numbers, seat_count, total_amount, booking_status)
                     VALUES (:customer_id, :schedule_id, :seat_numbers, :seat_count, :total_amount, \'confirmed\')
                     RETURNING booking_id'
                );
                $bookingStmt->execute([
                    'customer_id'  => $customerId,
                    'schedule_id'  => $scheduleId,
                    'seat_numbers' => implode(',', $seatNumbers),
                    'seat_count'   => $seatCount,
                    'total_amount' => $totalAmount,
                ]);
                $bookingId = (int)$bookingStmt->fetch()['booking_id'];

                $ticketStmt = $pdo->prepare(
                    'INSERT INTO ticket (booking_id, ticket_code, passenger_name, seat_number)
                     VALUES (:booking_id, :ticket_code, :passenger_name, :seat_number)'
                );
                foreach ($seatNumbers as $seat) {
                    $ticketStmt->execute([
                        'booking_id'      => $bookingId,
                        'ticket_code'     => ticket_code(),
                        'passenger_name'  => trim((string)$passengerNames[$seat]),
                        'seat_number'     => $seat,
                    ]);
                }

                $pdo->prepare('UPDATE schedule SET available_seats = available_seats - :n WHERE schedule_id = :sid')
                    ->execute(['n' => $seatCount, 'sid' => $scheduleId]);

                $pdo->prepare(
                    "INSERT INTO payment (booking_id, amount, status) VALUES (:booking_id, :amount, 'pending')"
                )->execute(['booking_id' => $bookingId, 'amount' => $totalAmount]);

                $pdo->commit();

                flash('success', 'Booking confirmed! Tickets generated. Please complete the payment.');
                redirect('booking.php?action=ticket&booking_id=' . $bookingId);
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('danger', $e->getMessage());
                redirect('booking.php?action=seats&schedule_id=' . $scheduleId);
            }
        } else {
            flash('danger', implode(' ', $errors));
            redirect('booking.php?action=seats&schedule_id=' . $scheduleId);
        }
    }

    if ($postAction === 'cancel') {
        $bookingId = (int)input('booking_id');

        $stmt = $pdo->prepare('SELECT * FROM booking WHERE booking_id = :id');
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            flash('danger', 'Booking not found.');
        } elseif (!$isStaffSide && (int)$booking['customer_id'] !== (int)$user['user_id']) {
            flash('danger', 'You can only cancel your own bookings.');
        } elseif ($booking['booking_status'] !== 'confirmed') {
            flash('warning', 'This booking is already ' . $booking['booking_status'] . '.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE booking SET booking_status = 'cancelled' WHERE booking_id = :id")
                ->execute(['id' => $bookingId]);
            $pdo->prepare('UPDATE schedule SET available_seats = available_seats + :n WHERE schedule_id = :sid')
                ->execute(['n' => $booking['seat_count'], 'sid' => $booking['schedule_id']]);
            $pdo->prepare("UPDATE payment SET status = 'refunded' WHERE booking_id = :id AND status = 'paid'")
                ->execute(['id' => $bookingId]);
            $pdo->commit();
            flash('success', 'Booking #' . $bookingId . ' has been cancelled.');
        }
        redirect('booking.php?action=history');
    }
}

// -----------------------------------------------------------------
// GET: render the requested view
// -----------------------------------------------------------------
if ($action === 'seats') {
    $scheduleId = (int)($_GET['schedule_id'] ?? 0);
    $stmt = $pdo->prepare(
        'SELECT s.*, b.bus_number, b.bus_name, b.bus_type, b.total_seats, b.image_path, r.origin, r.destination
         FROM schedule s JOIN bus b ON b.bus_id = s.bus_id JOIN route r ON r.route_id = s.route_id
         WHERE s.schedule_id = :id'
    );
    $stmt->execute(['id' => $scheduleId]);
    $schedule = $stmt->fetch();

    if (!$schedule) {
        flash('danger', 'Schedule not found.');
        redirect('booking.php');
    }

    $takenSeats = booked_seat_numbers($pdo, $scheduleId);
    $totalSeats = (int)$schedule['total_seats'];

    if ($isStaffSide) {
        $customers = $pdo->query("SELECT user_id, full_name, email FROM users WHERE role = 'customer' ORDER BY full_name")->fetchAll();
    }
}

if ($action === 'ticket') {
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    $stmt = $pdo->prepare(
        'SELECT bk.*, u.full_name AS customer_name, u.email AS customer_email,
                r.origin, r.destination, s.departure_date, s.departure_time, s.arrival_time,
                bus.bus_number, bus.bus_name, bus.bus_type
         FROM booking bk
         JOIN users u ON u.user_id = bk.customer_id
         JOIN schedule s ON s.schedule_id = bk.schedule_id
         JOIN route r ON r.route_id = s.route_id
         JOIN bus ON bus.bus_id = s.bus_id
         WHERE bk.booking_id = :id'
    );
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        flash('danger', 'Booking not found.');
        redirect('booking.php');
    }
    if (!$isStaffSide && (int)$booking['customer_id'] !== (int)$user['user_id']) {
        http_response_code(403);
        die('403 - You do not have permission to view this ticket.');
    }

    $ticketStmt = $pdo->prepare('SELECT * FROM ticket WHERE booking_id = :id ORDER BY ticket_id');
    $ticketStmt->execute(['id' => $bookingId]);
    $tickets = $ticketStmt->fetchAll();

    $payStmt = $pdo->prepare('SELECT * FROM payment WHERE booking_id = :id ORDER BY payment_id DESC LIMIT 1');
    $payStmt->execute(['id' => $bookingId]);
    $payment = $payStmt->fetch();
}

if ($action === 'history') {
    if ($isStaffSide) {
        $filterDate = input('date', '', 'get');
        $sql = "SELECT bk.*, u.full_name AS customer_name, r.origin, r.destination, s.departure_date, s.departure_time
                FROM booking bk
                JOIN users u ON u.user_id = bk.customer_id
                JOIN schedule s ON s.schedule_id = bk.schedule_id
                JOIN route r ON r.route_id = s.route_id";
        $params = [];
        if ($filterDate !== '') {
            $sql .= ' WHERE bk.booking_date::date = :d';
            $params['d'] = $filterDate;
        }
        $sql .= ' ORDER BY bk.booking_date DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT bk.*, r.origin, r.destination, s.departure_date, s.departure_time
             FROM booking bk
             JOIN schedule s ON s.schedule_id = bk.schedule_id
             JOIN route r ON r.route_id = s.route_id
             WHERE bk.customer_id = :uid
             ORDER BY bk.booking_date DESC"
        );
        $stmt->execute(['uid' => $user['user_id']]);
        $bookings = $stmt->fetchAll();
    }
}

if ($action === 'search') {
    $fromCity = input('from', '', 'get');
    $toCity   = input('to', '', 'get');
    $date     = input('date', '', 'get');

    $results = [];
    if ($fromCity !== '' || $toCity !== '' || $date !== '') {
        $sql = "SELECT s.*, b.bus_number, b.bus_name, b.bus_type, b.image_path, b.total_seats, r.origin, r.destination, r.estimated_duration
                FROM schedule s JOIN bus b ON b.bus_id = s.bus_id JOIN route r ON r.route_id = s.route_id
                WHERE s.departure_date >= CURRENT_DATE AND s.available_seats > 0";
        $params = [];
        if ($fromCity !== '') { $sql .= ' AND r.origin ILIKE :from'; $params['from'] = '%' . $fromCity . '%'; }
        if ($toCity !== '')   { $sql .= ' AND r.destination ILIKE :to'; $params['to'] = '%' . $toCity . '%'; }
        if ($date !== '')     { $sql .= ' AND s.departure_date = :date'; $params['date'] = $date; }
        $sql .= ' ORDER BY s.departure_date, s.departure_time';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($action === 'search'): ?>

    <div class="card section-card search-card">
        <h2 class="mb-3">Search Buses</h2>
        <form method="get" action="booking.php" class="row g-3">
            <input type="hidden" name="action" value="search">
            <div class="col-md-4">
                <label class="form-label">From</label>
                <input type="text" name="from" class="form-control" placeholder="e.g. Dhaka" value="<?= e($fromCity) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">To</label>
                <input type="text" name="to" class="form-control" placeholder="e.g. Sylhet" value="<?= e($toCity) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?= e($date) ?>">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <div class="row g-3 mt-1">
            <?php foreach ($results as $r): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card trip-card h-100">
                        <div class="trip-card-img">
                            <?php if ($r['image_path']): ?>
                                <img src="<?= e($r['image_path']) ?>" alt="<?= e($r['bus_number']) ?>">
                            <?php else: ?>
                                <div class="trip-card-img-placeholder"><i class="bi bi-bus-front-fill"></i></div>
                            <?php endif; ?>
                            <span class="badge trip-type-badge"><?= e($r['bus_type']) ?></span>
                        </div>
                        <div class="card-body">
                            <h3 class="trip-route"><?= e($r['origin']) ?> <i class="bi bi-arrow-right"></i> <?= e($r['destination']) ?></h3>
                            <div class="trip-meta">
                                <span><i class="bi bi-calendar3"></i> <?= e($r['departure_date']) ?></span>
                                <span><i class="bi bi-clock"></i> <?= e(substr($r['departure_time'], 0, 5)) ?></span>
                                <?php if ($r['estimated_duration']): ?><span><i class="bi bi-hourglass-split"></i> <?= e($r['estimated_duration']) ?></span><?php endif; ?>
                            </div>
                            <div class="trip-bus-name"><?= e($r['bus_number']) ?> &middot; <?= e($r['bus_name']) ?></div>
                            <div class="trip-footer">
                                <div class="trip-fare"><?= e(money($r['fare'])) ?></div>
                                <div class="trip-seats-left"><?= (int)$r['available_seats'] ?> seats left</div>
                            </div>
                            <a href="booking.php?action=seats&schedule_id=<?= (int)$r['schedule_id'] ?>" class="btn btn-primary w-100 mt-2">Select Seats</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($fromCity !== '' || $toCity !== '' || $date !== ''): ?>
        <div class="empty-state mt-4">
            <i class="bi bi-emoji-frown"></i>
            <p>No buses found for this search. Try a different date or route.</p>
        </div>
    <?php endif; ?>

<?php elseif ($action === 'seats'): ?>

    <div class="card section-card">
        <div class="seat-page-header">
            <div>
                <h2><?= e($schedule['origin']) ?> <i class="bi bi-arrow-right"></i> <?= e($schedule['destination']) ?></h2>
                <div class="text-muted">
                    <?= e($schedule['bus_number']) ?> &middot; <?= e($schedule['bus_name']) ?> (<?= e($schedule['bus_type']) ?>) &middot;
                    <?= e($schedule['departure_date']) ?> at <?= e(substr($schedule['departure_time'], 0, 5)) ?>
                </div>
            </div>
            <div class="seat-fare-tag"><?= e(money($schedule['fare'])) ?> <span>/ seat</span></div>
        </div>

        <form method="post" id="bookingForm">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="book">
            <input type="hidden" name="schedule_id" value="<?= (int)$schedule['schedule_id'] ?>">

            <div class="seat-layout-wrap">
                <div class="bus-shape">
                    <div class="bus-driver" aria-hidden="true"></div>
                    <div class="seat-grid">
                        <?php
                        $col = 0;
                        for ($seatNo = 1; $seatNo <= $totalSeats; $seatNo++):
                            $seatLabel = (string)$seatNo;
                            $isTaken = in_array($seatLabel, $takenSeats, true);
                            $col++;
                            if ($col === 3): // aisle gap after every 2 seats
                        ?>
                            <div class="seat-aisle"></div>
                        <?php
                                $col = 1;
                            endif;
                        ?>
                            <button type="button"
                                    class="seat-btn <?= $isTaken ? 'seat-taken' : 'seat-free' ?>"
                                    data-seat="<?= e($seatLabel) ?>"
                                    <?= $isTaken ? 'disabled' : '' ?>>
                                <?= e($seatLabel) ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="seat-side-panel">
                    <div class="seat-legend">
                        <span><i class="seat-dot seat-dot-free"></i> Available</span>
                        <span><i class="seat-dot seat-dot-taken"></i> Booked</span>
                        <span><i class="seat-dot seat-dot-selected"></i> Selected</span>
                    </div>

                    <?php if ($isStaffSide): ?>
                        <div class="mb-3">
                            <label class="form-label">Booking For (Customer)</label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">Select customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['user_id'] ?>"><?= e($c['full_name']) ?> (<?= e($c['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">No account yet? <a href="register.php" target="_blank">Register the customer</a> first.</div>
                        </div>
                    <?php endif; ?>

                    <h3 class="seat-side-title">Passengers</h3>
                    <div id="passengerList" class="passenger-list">
                        <p class="text-muted small" id="noSeatHint">Click on a seat to begin.</p>
                    </div>

                    <div class="seat-summary">
                        <div>Seats selected: <strong id="seatCountLabel">0</strong></div>
                        <div>Total: <strong id="seatTotalLabel"><?= e(money(0)) ?></strong></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="confirmBookingBtn" disabled>Confirm Booking</button>
                    <a href="booking.php" class="btn btn-link w-100">&larr; Back to search</a>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        const fare = <?= (float)$schedule['fare'] ?>;
        const selected = new Set();
        const passengerList = document.getElementById('passengerList');
        const noSeatHint = document.getElementById('noSeatHint');
        const seatCountLabel = document.getElementById('seatCountLabel');
        const seatTotalLabel = document.getElementById('seatTotalLabel');
        const confirmBtn = document.getElementById('confirmBookingBtn');

        function refreshSummary() {
            seatCountLabel.textContent = selected.size;
            seatTotalLabel.textContent = 'BDT ' + (fare * selected.size).toFixed(2);
            confirmBtn.disabled = selected.size === 0;
            noSeatHint.style.display = selected.size === 0 ? 'block' : 'none';
        }

        document.querySelectorAll('.seat-btn.seat-free').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const seat = btn.dataset.seat;
                if (selected.has(seat)) {
                    selected.delete(seat);
                    btn.classList.remove('seat-selected');
                    const row = document.getElementById('passenger-row-' + seat);
                    if (row) row.remove();
                } else {
                    selected.add(seat);
                    btn.classList.add('seat-selected');
                    const row = document.createElement('div');
                    row.className = 'passenger-row';
                    row.id = 'passenger-row-' + seat;
                    row.innerHTML = '<label>Seat ' + seat + '</label>' +
                        '<input type="text" name="passenger_names[' + seat + ']" class="form-control form-control-sm" placeholder="Passenger name" required>';
                    passengerList.appendChild(row);
                }
                refreshSummary();
            });
        });

        document.getElementById('bookingForm').addEventListener('submit', function (e) {
            if (selected.size === 0) {
                e.preventDefault();
                alert('Please select at least one seat.');
            }
        });

        refreshSummary();
    })();
    </script>

<?php elseif ($action === 'ticket'): ?>

    <div class="card section-card ticket-wrap">
        <div class="ticket-actions no-print">
            <a href="booking.php?action=history" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
            <div>
                <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer"></i> Print</button>
                <?php if ($payment && $payment['status'] !== 'paid' && $booking['booking_status'] === 'confirmed'): ?>
                    <a href="payment.php?action=pay&booking_id=<?= (int)$booking['booking_id'] ?>" class="btn btn-primary"><i class="bi bi-credit-card"></i> Proceed to Payment</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="ticket-paper">
            <div class="ticket-header">
                <div>
                    <div class="ticket-brand">ICT BD Bus Services</div>
                    <div class="text-muted">E-Ticket / Booking Confirmation</div>
                </div>
                <span class="badge status-badge status-<?= e($booking['booking_status']) ?>"><?= e(ucfirst($booking['booking_status'])) ?></span>
            </div>

            <div class="ticket-route">
                <span><?= e($booking['origin']) ?></span>
                <i class="bi bi-arrow-right"></i>
                <span><?= e($booking['destination']) ?></span>
            </div>

            <div class="ticket-grid">
                <div><span class="ticket-label">Booking ID</span><span class="ticket-mono">#<?= (int)$booking['booking_id'] ?></span></div>
                <div><span class="ticket-label">Date</span><?= e($booking['departure_date']) ?></div>
                <div><span class="ticket-label">Departure</span><?= e(substr($booking['departure_time'], 0, 5)) ?></div>
                <div><span class="ticket-label">Arrival</span><?= $booking['arrival_time'] ? e(substr($booking['arrival_time'], 0, 5)) : '—' ?></div>
                <div><span class="ticket-label">Bus</span><?= e($booking['bus_number']) ?> (<?= e($booking['bus_type']) ?>)</div>
                <div><span class="ticket-label">Customer</span><?= e($booking['customer_name']) ?></div>
                <div><span class="ticket-label">Amount Paid</span><?= e(money($booking['total_amount'])) ?></div>
                <div><span class="ticket-label">Payment Status</span><?= e(ucfirst($payment['status'] ?? 'pending')) ?></div>
            </div>

            <table class="table app-table ticket-passenger-table">
                <thead><tr><th>Seat</th><th>Passenger</th><th>Ticket Code</th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="ticket-mono"><?= e($t['seat_number']) ?></td>
                        <td><?= e($t['passenger_name']) ?></td>
                        <td class="ticket-mono"><?= e($t['ticket_code']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-muted small mb-0">Please carry a valid photo ID matching the passenger name. Arrive 30 minutes before departure.</p>
        </div>
    </div>

<?php elseif ($action === 'history'): ?>

    <div class="card section-card">
        <div class="section-card-header">
            <h2><?= $isStaffSide ? 'All Bookings' : 'My Booking History' ?></h2>
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
                    <th>#</th>
                    <?php if ($isStaffSide): ?><th>Customer</th><?php endif; ?>
                    <th>Route</th><th>Date</th><th>Seats</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No bookings found.</td></tr>
                <?php endif; ?>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td>#<?= (int)$b['booking_id'] ?></td>
                        <?php if ($isStaffSide): ?><td><?= e($b['customer_name']) ?></td><?php endif; ?>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e($b['departure_date']) ?> <?= e(substr($b['departure_time'], 0, 5)) ?></td>
                        <td class="seat-mono"><?= e($b['seat_numbers']) ?></td>
                        <td><?= e(money($b['total_amount'])) ?></td>
                        <td><span class="badge status-badge status-<?= e($b['booking_status']) ?>"><?= e(ucfirst($b['booking_status'])) ?></span></td>
                        <td class="text-end">
                            <a href="booking.php?action=ticket&booking_id=<?= (int)$b['booking_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <?php if ($b['booking_status'] === 'confirmed'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Cancel booking #<?= (int)$b['booking_id'] ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="cancel">
                                    <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>