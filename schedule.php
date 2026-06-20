<?php
require_once __DIR__ . '/config.php';
require_role(['admin']);

$pageTitle = 'Schedule Management';

/** Count seats already booked (confirmed) for a schedule. */
function booked_seats_for_schedule(PDO $pdo, int $scheduleId): int
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(seat_count), 0) AS c FROM booking
         WHERE schedule_id = :sid AND booking_status = 'confirmed'"
    );
    $stmt->execute(['sid' => $scheduleId]);
    return (int)$stmt->fetch()['c'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = input('form_action');

    if ($postAction === 'create' || $postAction === 'update') {
        $scheduleId    = (int)input('schedule_id');
        $busId         = (int)input('bus_id');
        $routeId       = (int)input('route_id');
        $departureDate = input('departure_date');
        $departureTime = input('departure_time');
        $arrivalTime   = input('arrival_time');
        $fare          = input('fare');
        $availableSeats = input('available_seats');

        $errors = [];
        $bus = null;
        if ($busId <= 0) {
            $errors[] = 'Please select a bus.';
        } else {
            $busStmt = $pdo->prepare('SELECT * FROM bus WHERE bus_id = :id');
            $busStmt->execute(['id' => $busId]);
            $bus = $busStmt->fetch();
            if (!$bus) $errors[] = 'Selected bus does not exist.';
        }
        if ($routeId <= 0) $errors[] = 'Please select a route.';
        if ($departureDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) $errors[] = 'A valid departure date is required.';
        if ($departureTime === '') $errors[] = 'Departure time is required.';
        if (!is_numeric($fare) || (float)$fare < 0) $errors[] = 'Fare must be a valid non-negative number.';
        if ($availableSeats === '' || !ctype_digit($availableSeats)) {
            $errors[] = 'Available seats must be a whole number.';
        }

        if (empty($errors) && $bus) {
            $availableSeats = (int)$availableSeats;
            if ($availableSeats > (int)$bus['total_seats']) {
                $errors[] = 'Available seats cannot exceed the bus capacity (' . (int)$bus['total_seats'] . ').';
            }
            if ($postAction === 'update') {
                $already = booked_seats_for_schedule($pdo, $scheduleId);
                if (($availableSeats + $already) > (int)$bus['total_seats']) {
                    $errors[] = 'Available seats plus already booked seats (' . $already . ') cannot exceed bus capacity.';
                }
            }
        }

        if (empty($errors)) {
            if ($postAction === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO schedule (bus_id, route_id, departure_date, departure_time, arrival_time, fare, available_seats)
                     VALUES (:bus_id, :route_id, :departure_date, :departure_time, :arrival_time, :fare, :available_seats)
                     RETURNING schedule_id'
                );
                $stmt->execute([
                    'bus_id' => $busId, 'route_id' => $routeId, 'departure_date' => $departureDate,
                    'departure_time' => $departureTime, 'arrival_time' => $arrivalTime !== '' ? $arrivalTime : null,
                    'fare' => $fare, 'available_seats' => $availableSeats,
                ]);
                flash('success', 'Schedule created successfully.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE schedule SET bus_id = :bus_id, route_id = :route_id, departure_date = :departure_date,
                            departure_time = :departure_time, arrival_time = :arrival_time, fare = :fare,
                            available_seats = :available_seats, updated_at = CURRENT_TIMESTAMP
                     WHERE schedule_id = :schedule_id'
                );
                $stmt->execute([
                    'bus_id' => $busId, 'route_id' => $routeId, 'departure_date' => $departureDate,
                    'departure_time' => $departureTime, 'arrival_time' => $arrivalTime !== '' ? $arrivalTime : null,
                    'fare' => $fare, 'available_seats' => $availableSeats, 'schedule_id' => $scheduleId,
                ]);
                flash('success', 'Schedule updated successfully.');
            }
        } else {
            flash('danger', implode(' ', $errors));
        }
        redirect('schedule.php');
    }

    if ($postAction === 'delete') {
        $scheduleId = (int)input('schedule_id');
        $check = $pdo->prepare("SELECT COUNT(*) AS c FROM booking WHERE schedule_id = :sid AND booking_status = 'confirmed'");
        $check->execute(['sid' => $scheduleId]);
        if ((int)$check->fetch()['c'] > 0) {
            flash('danger', 'This schedule has active bookings and cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM schedule WHERE schedule_id = :sid')->execute(['sid' => $scheduleId]);
            flash('success', 'Schedule deleted successfully.');
        }
        redirect('schedule.php');
    }
}

$buses  = $pdo->query("SELECT bus_id, bus_number, bus_name, total_seats FROM bus WHERE status = 'active' ORDER BY bus_number")->fetchAll();
$routes = $pdo->query('SELECT route_id, origin, destination, base_fare FROM route ORDER BY origin, destination')->fetchAll();

$schedules = $pdo->query(
    "SELECT s.*, b.bus_number, b.bus_name, b.total_seats, r.origin, r.destination
     FROM schedule s
     JOIN bus b ON b.bus_id = s.bus_id
     JOIN route r ON r.route_id = s.route_id
     ORDER BY s.departure_date DESC, s.departure_time DESC"
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="card section-card">
    <div class="section-card-header">
        <h2>All Schedules</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
            <i class="bi bi-plus-lg me-1"></i>Add Schedule
        </button>
    </div>
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
            <tr><th>Bus</th><th>Route</th><th>Date</th><th>Departs</th><th>Arrives</th><th>Fare</th><th>Seats Left</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($schedules)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No schedules created yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($schedules as $s): ?>
                <tr>
                    <td><?= e($s['bus_number']) ?><div class="text-muted small"><?= e($s['bus_name']) ?></div></td>
                    <td><?= e($s['origin']) ?> &rarr; <?= e($s['destination']) ?></td>
                    <td><?= e($s['departure_date']) ?></td>
                    <td><?= e(substr($s['departure_time'], 0, 5)) ?></td>
                    <td><?= $s['arrival_time'] ? e(substr($s['arrival_time'], 0, 5)) : '—' ?></td>
                    <td><?= e(money($s['fare'])) ?></td>
                    <td><span class="badge text-bg-light border"><?= (int)$s['available_seats'] ?> / <?= (int)$s['total_seats'] ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editScheduleModal<?= (int)$s['schedule_id'] ?>">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this schedule?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editScheduleModal<?= (int)$s['schedule_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="update">
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Schedule</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Bus</label>
                                        <select name="bus_id" class="form-select" required>
                                            <?php foreach ($buses as $b): ?>
                                                <option value="<?= (int)$b['bus_id'] ?>" <?= (int)$b['bus_id'] === (int)$s['bus_id'] ? 'selected' : '' ?>>
                                                    <?= e($b['bus_number']) ?> — <?= e($b['bus_name']) ?> (<?= (int)$b['total_seats'] ?> seats)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Route</label>
                                        <select name="route_id" class="form-select" required>
                                            <?php foreach ($routes as $r): ?>
                                                <option value="<?= (int)$r['route_id'] ?>" <?= (int)$r['route_id'] === (int)$s['route_id'] ? 'selected' : '' ?>>
                                                    <?= e($r['origin']) ?> &rarr; <?= e($r['destination']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row">
                                        <div class="col-4 mb-3">
                                            <label class="form-label">Date</label>
                                            <input type="date" name="departure_date" class="form-control" value="<?= e($s['departure_date']) ?>" required>
                                        </div>
                                        <div class="col-4 mb-3">
                                            <label class="form-label">Departure</label>
                                            <input type="time" name="departure_time" class="form-control" value="<?= e(substr($s['departure_time'], 0, 5)) ?>" required>
                                        </div>
                                        <div class="col-4 mb-3">
                                            <label class="form-label">Arrival</label>
                                            <input type="time" name="arrival_time" class="form-control" value="<?= e(substr((string)$s['arrival_time'], 0, 5)) ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Fare (BDT)</label>
                                            <input type="number" step="0.01" min="0" name="fare" class="form-control" value="<?= e((string)$s['fare']) ?>" required>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Available Seats</label>
                                            <input type="number" min="0" name="available_seats" class="form-control" value="<?= (int)$s['available_seats'] ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="addScheduleForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bus</label>
                        <select name="bus_id" id="schBusSelect" class="form-select" required>
                            <option value="">Select a bus</option>
                            <?php foreach ($buses as $b): ?>
                                <option value="<?= (int)$b['bus_id'] ?>" data-seats="<?= (int)$b['total_seats'] ?>">
                                    <?= e($b['bus_number']) ?> — <?= e($b['bus_name']) ?> (<?= (int)$b['total_seats'] ?> seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Route</label>
                        <select name="route_id" id="schRouteSelect" class="form-select" required>
                            <option value="">Select a route</option>
                            <?php foreach ($routes as $r): ?>
                                <option value="<?= (int)$r['route_id'] ?>" data-fare="<?= e((string)$r['base_fare']) ?>">
                                    <?= e($r['origin']) ?> &rarr; <?= e($r['destination']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-4 mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="departure_date" class="form-control" required>
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label">Departure</label>
                            <input type="time" name="departure_time" class="form-control" required>
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label">Arrival</label>
                            <input type="time" name="arrival_time" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Fare (BDT)</label>
                            <input type="number" step="0.01" min="0" name="fare" id="schFareInput" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Available Seats</label>
                            <input type="number" min="0" name="available_seats" id="schSeatsInput" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('schBusSelect')?.addEventListener('change', function () {
    const seats = this.options[this.selectedIndex]?.dataset?.seats || '';
    document.getElementById('schSeatsInput').value = seats;
});
document.getElementById('schRouteSelect')?.addEventListener('change', function () {
    const fare = this.options[this.selectedIndex]?.dataset?.fare || '';
    document.getElementById('schFareInput').value = fare;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>