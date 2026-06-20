<?php
require_once __DIR__ . '/config.php';
require_role(['admin', 'staff']);

$pageTitle = 'Reports';
$type = $_GET['type'] ?? 'daily';

if ($type === 'monthly') {
    $month = input('month', date('Y-m'), 'get');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $dailyStmt = $pdo->prepare(
        "SELECT to_char(p.paid_at, 'YYYY-MM-DD') AS day,
                COUNT(*) AS transactions,
                COALESCE(SUM(p.amount), 0) AS revenue
         FROM payment p
         WHERE p.status = 'paid' AND to_char(p.paid_at, 'YYYY-MM') = :month
         GROUP BY day
         ORDER BY day"
    );
    $dailyStmt->execute(['month' => $month]);
    $dailyRows = $dailyStmt->fetchAll();

    $totalsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS transactions, COALESCE(SUM(amount), 0) AS revenue
         FROM payment WHERE status = 'paid' AND to_char(paid_at, 'YYYY-MM') = :month"
    );
    $totalsStmt->execute(['month' => $month]);
    $monthTotals = $totalsStmt->fetch();

    $bookingCountStmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM booking WHERE booking_status = 'confirmed' AND to_char(booking_date, 'YYYY-MM') = :month"
    );
    $bookingCountStmt->execute(['month' => $month]);
    $monthBookingCount = (int)$bookingCountStmt->fetch()['c'];

    $maxRevenue = 0.0;
    foreach ($dailyRows as $row) {
        $maxRevenue = max($maxRevenue, (float)$row['revenue']);
    }
} else {
    $type = 'daily';
    $date = input('date', date('Y-m-d'), 'get');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $bookingsStmt = $pdo->prepare(
        "SELECT bk.*, u.full_name AS customer_name, r.origin, r.destination, s.departure_date, s.departure_time
         FROM booking bk
         JOIN users u ON u.user_id = bk.customer_id
         JOIN schedule s ON s.schedule_id = bk.schedule_id
         JOIN route r ON r.route_id = s.route_id
         WHERE bk.booking_date::date = :date
         ORDER BY bk.booking_date"
    );
    $bookingsStmt->execute(['date' => $date]);
    $dayBookings = $bookingsStmt->fetchAll();

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) FILTER (WHERE booking_status = 'confirmed') AS confirmed_count,
            COUNT(*) FILTER (WHERE booking_status = 'cancelled') AS cancelled_count,
            COALESCE(SUM(seat_count) FILTER (WHERE booking_status = 'confirmed'), 0) AS seats_sold,
            COALESCE(SUM(total_amount) FILTER (WHERE booking_status = 'confirmed'), 0) AS gross_value
         FROM booking WHERE booking_date::date = :date"
    );
    $summaryStmt->execute(['date' => $date]);
    $daySummary = $summaryStmt->fetch();

    $collectedStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS collected FROM payment
         WHERE status = 'paid' AND paid_at::date = :date"
    );
    $collectedStmt->execute(['date' => $date]);
    $dayCollected = (float)$collectedStmt->fetch()['collected'];
}

include __DIR__ . '/includes/header.php';
?>

<ul class="nav nav-pills report-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $type === 'daily' ? 'active' : '' ?>" href="reports.php?type=daily">Daily Booking Report</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'monthly' ? 'active' : '' ?>" href="reports.php?type=monthly">Monthly Sales Report</a></li>
</ul>

<?php if ($type === 'daily'): ?>

    <div class="card section-card">
        <form method="get" class="report-filter">
            <input type="hidden" name="type" value="daily">
            <label class="form-label me-2">Date</label>
            <input type="date" name="date" value="<?= e($date) ?>" class="form-control form-control-sm" style="max-width:200px;">
            <button class="btn btn-sm btn-primary" type="submit">View Report</button>
        </form>

        <div class="row g-3 stat-row mt-1">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card"><div class="stat-value"><?= (int)$daySummary['confirmed_count'] ?></div><div class="stat-label">Confirmed Bookings</div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card"><div class="stat-value"><?= (int)$daySummary['cancelled_count'] ?></div><div class="stat-label">Cancelled Bookings</div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card"><div class="stat-value"><?= (int)$daySummary['seats_sold'] ?></div><div class="stat-label">Seats Sold</div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card"><div class="stat-value"><?= e(money($dayCollected)) ?></div><div class="stat-label">Payment Collected</div></div>
            </div>
        </div>

        <h3 class="mt-4 mb-2">Bookings on <?= e($date) ?></h3>
        <div class="table-responsive">
            <table class="table app-table align-middle mb-0">
                <thead><tr><th>#</th><th>Customer</th><th>Route</th><th>Travel Date</th><th>Seats</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($dayBookings)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No bookings were made on this date.</td></tr>
                <?php endif; ?>
                <?php foreach ($dayBookings as $b): ?>
                    <tr>
                        <td>#<?= (int)$b['booking_id'] ?></td>
                        <td><?= e($b['customer_name']) ?></td>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e($b['departure_date']) ?></td>
                        <td class="seat-mono"><?= e($b['seat_numbers']) ?></td>
                        <td><?= e(money($b['total_amount'])) ?></td>
                        <td><span class="badge status-badge status-<?= e($b['booking_status']) ?>"><?= e(ucfirst($b['booking_status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="card section-card">
        <form method="get" class="report-filter">
            <input type="hidden" name="type" value="monthly">
            <label class="form-label me-2">Month</label>
            <input type="month" name="month" value="<?= e($month) ?>" class="form-control form-control-sm" style="max-width:200px;">
            <button class="btn btn-sm btn-primary" type="submit">View Report</button>
        </form>

        <div class="row g-3 stat-row mt-1">
            <div class="col-sm-6 col-lg-4">
                <div class="stat-card"><div class="stat-value"><?= $monthBookingCount ?></div><div class="stat-label">Confirmed Bookings</div></div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="stat-card"><div class="stat-value"><?= (int)$monthTotals['transactions'] ?></div><div class="stat-label">Paid Transactions</div></div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="stat-card"><div class="stat-value"><?= e(money($monthTotals['revenue'])) ?></div><div class="stat-label">Total Revenue</div></div>
            </div>
        </div>

        <h3 class="mt-4 mb-2">Daily Revenue Breakdown - <?= e($month) ?></h3>

        <?php if (empty($dailyRows)): ?>
            <div class="empty-state"><i class="bi bi-bar-chart"></i><p>No paid transactions recorded for this month yet.</p></div>
        <?php else: ?>
            <div class="bar-chart">
                <?php foreach ($dailyRows as $row): ?>
                    <?php $heightPct = $maxRevenue > 0 ? max(4, round(((float)$row['revenue'] / $maxRevenue) * 100)) : 4; ?>
                    <div class="bar-chart-col" title="<?= e($row['day']) ?>: <?= e(money($row['revenue'])) ?>">
                        <div class="bar-chart-bar" style="height: <?= $heightPct ?>%;"></div>
                        <div class="bar-chart-label"><?= e(substr($row['day'], 8, 2)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive mt-3">
                <table class="table app-table align-middle mb-0">
                    <thead><tr><th>Date</th><th>Transactions</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($dailyRows as $row): ?>
                        <tr>
                            <td><?= e($row['day']) ?></td>
                            <td><?= (int)$row['transactions'] ?></td>
                            <td><?= e(money($row['revenue'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-semibold">
                        <td>Total</td>
                        <td><?= (int)$monthTotals['transactions'] ?></td>
                        <td><?= e(money($monthTotals['revenue'])) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>