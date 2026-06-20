<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$pageTitle = 'Dashboard';

if (in_array($user['role'], ['admin', 'staff'], true)) {
    $totalBuses    = (int)$pdo->query("SELECT COUNT(*) AS c FROM bus WHERE status = 'active'")->fetch()['c'];
    $totalRoutes   = (int)$pdo->query("SELECT COUNT(*) AS c FROM route")->fetch()['c'];
    $totalSchedules = (int)$pdo->query("SELECT COUNT(*) AS c FROM schedule WHERE departure_date >= CURRENT_DATE")->fetch()['c'];

    $bookingsToday = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM booking WHERE booking_date::date = CURRENT_DATE AND booking_status = 'confirmed'"
    )->fetch()['c'];

    $revenueMonth = (float)$pdo->query(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM payment
         WHERE status = 'paid' AND date_trunc('month', paid_at) = date_trunc('month', CURRENT_DATE)"
    )->fetch()['total'];

    $recentBookings = $pdo->query(
        "SELECT b.booking_id, u.full_name AS customer, r.origin, r.destination, s.departure_date, b.seat_count, b.total_amount, b.booking_status
         FROM booking b
         JOIN users u ON u.user_id = b.customer_id
         JOIN schedule s ON s.schedule_id = b.schedule_id
         JOIN route r ON r.route_id = s.route_id
         ORDER BY b.booking_date DESC
         LIMIT 8"
    )->fetchAll();
} else {
    $myUpcoming = $pdo->prepare(
        "SELECT b.booking_id, r.origin, r.destination, s.departure_date, s.departure_time, b.seat_numbers, b.total_amount, b.booking_status
         FROM booking b
         JOIN schedule s ON s.schedule_id = b.schedule_id
         JOIN route r ON r.route_id = s.route_id
         WHERE b.customer_id = :uid AND b.booking_status = 'confirmed' AND s.departure_date >= CURRENT_DATE
         ORDER BY s.departure_date ASC, s.departure_time ASC
         LIMIT 5"
    );
    $myUpcoming->execute(['uid' => $user['user_id']]);
    $myUpcoming = $myUpcoming->fetchAll();

    $stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM booking WHERE customer_id = :uid");
    $stmtCount->execute(['uid' => $user['user_id']]);
    $myTotalBookings = (int)$stmtCount->fetch()['c'];
}

include __DIR__ . '/includes/header.php';
?>

<?php if (in_array($user['role'], ['admin', 'staff'], true)): ?>
    <div class="row g-3 stat-row">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon bg-navy"><i class="bi bi-bus-front-fill"></i></div>
                <div class="stat-value"><?= $totalBuses ?></div>
                <div class="stat-label">Active Buses</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon bg-teal"><i class="bi bi-signpost-split-fill"></i></div>
                <div class="stat-value"><?= $totalRoutes ?></div>
                <div class="stat-label">Routes</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon bg-amber"><i class="bi bi-ticket-perforated-fill"></i></div>
                <div class="stat-value"><?= $bookingsToday ?></div>
                <div class="stat-label">Bookings Today</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon bg-brick"><i class="bi bi-cash-coin"></i></div>
                <div class="stat-value"><?= e(money($revenueMonth)) ?></div>
                <div class="stat-label">Revenue This Month</div>
            </div>
        </div>
    </div>

    <div class="card section-card mt-4">
        <div class="section-card-header">
            <h2>Recent Bookings</h2>
            <a href="booking.php?action=history" class="btn btn-sm btn-outline-primary">View all</a>
        </div>
        <div class="table-responsive">
            <table class="table app-table align-middle mb-0">
                <thead>
                <tr>
                    <th>#</th><th>Customer</th><th>Route</th><th>Date</th><th>Seats</th><th>Amount</th><th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($recentBookings)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No bookings yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentBookings as $b): ?>
                    <tr>
                        <td>#<?= (int)$b['booking_id'] ?></td>
                        <td><?= e($b['customer']) ?></td>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e($b['departure_date']) ?></td>
                        <td><?= (int)$b['seat_count'] ?></td>
                        <td><?= e(money($b['total_amount'])) ?></td>
                        <td>
                            <?php $st = $b['booking_status']; ?>
                            <span class="badge status-badge status-<?= e($st) ?>"><?= e(ucfirst($st)) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="card section-card welcome-card">
        <div>
            <h2>Hi, <?= e($user['full_name']) ?> 👋</h2>
            <p class="text-muted mb-3">You have made <?= $myTotalBookings ?> booking(s) so far. Ready for your next trip?</p>
            <a href="booking.php" class="btn btn-primary"><i class="bi bi-search me-1"></i> Search Buses &amp; Book a Ticket</a>
        </div>
    </div>

    <div class="card section-card mt-4">
        <div class="section-card-header">
            <h2>My Upcoming Trips</h2>
            <a href="booking.php?action=history" class="btn btn-sm btn-outline-primary">Booking history</a>
        </div>
        <div class="table-responsive">
            <table class="table app-table align-middle mb-0">
                <thead>
                <tr><th>Booking</th><th>Route</th><th>Date</th><th>Time</th><th>Seats</th><th>Amount</th></tr>
                </thead>
                <tbody>
                <?php if (empty($myUpcoming)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No upcoming trips. Book one now!</td></tr>
                <?php endif; ?>
                <?php foreach ($myUpcoming as $b): ?>
                    <tr>
                        <td>#<?= (int)$b['booking_id'] ?></td>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e($b['departure_date']) ?></td>
                        <td><?= e(substr($b['departure_time'], 0, 5)) ?></td>
                        <td class="seat-mono"><?= e($b['seat_numbers']) ?></td>
                        <td><?= e(money($b['total_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>