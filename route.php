<?php
require_once __DIR__ . '/config.php';
require_role(['admin']);

$pageTitle = 'Route Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = input('form_action');

    if ($postAction === 'create' || $postAction === 'update') {
        $routeId    = (int)input('route_id');
        $origin     = input('origin');
        $destination = input('destination');
        $distance   = input('distance_km');
        $duration   = input('estimated_duration');
        $baseFare   = input('base_fare');

        $errors = [];
        if ($origin === '') $errors[] = 'Origin is required.';
        if ($destination === '') $errors[] = 'Destination is required.';
        if (strcasecmp($origin, $destination) === 0) $errors[] = 'Origin and destination cannot be the same.';
        if (!is_numeric($baseFare) || (float)$baseFare < 0) $errors[] = 'Base fare must be a valid non-negative number.';
        if ($distance !== '' && (!is_numeric($distance) || (float)$distance < 0)) $errors[] = 'Distance must be a valid non-negative number.';

        if (empty($errors)) {
            if ($postAction === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO route (origin, destination, distance_km, estimated_duration, base_fare)
                     VALUES (:origin, :destination, :distance_km, :estimated_duration, :base_fare)
                     RETURNING route_id'
                );
                $stmt->execute([
                    'origin' => $origin, 'destination' => $destination,
                    'distance_km' => $distance !== '' ? $distance : null,
                    'estimated_duration' => $duration !== '' ? $duration : null,
                    'base_fare' => $baseFare,
                ]);
                flash('success', 'Route added successfully.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE route SET origin = :origin, destination = :destination, distance_km = :distance_km,
                            estimated_duration = :estimated_duration, base_fare = :base_fare, updated_at = CURRENT_TIMESTAMP
                     WHERE route_id = :route_id'
                );
                $stmt->execute([
                    'origin' => $origin, 'destination' => $destination,
                    'distance_km' => $distance !== '' ? $distance : null,
                    'estimated_duration' => $duration !== '' ? $duration : null,
                    'base_fare' => $baseFare, 'route_id' => $routeId,
                ]);
                flash('success', 'Route updated successfully.');
            }
        } else {
            flash('danger', implode(' ', $errors));
        }
        redirect('route.php');
    }

    if ($postAction === 'delete') {
        $routeId = (int)input('route_id');
        $check = $pdo->prepare('SELECT COUNT(*) AS c FROM schedule WHERE route_id = :route_id');
        $check->execute(['route_id' => $routeId]);
        if ((int)$check->fetch()['c'] > 0) {
            flash('danger', 'This route has existing schedules and cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM route WHERE route_id = :route_id')->execute(['route_id' => $routeId]);
            flash('success', 'Route deleted successfully.');
        }
        redirect('route.php');
    }
}

$routes = $pdo->query('SELECT * FROM route ORDER BY route_id DESC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="card section-card">
    <div class="section-card-header">
        <h2>All Routes</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRouteModal">
            <i class="bi bi-plus-lg me-1"></i>Add Route
        </button>
    </div>
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
            <tr><th>Origin</th><th>Destination</th><th>Distance</th><th>Duration</th><th>Base Fare</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($routes)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No routes added yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($routes as $route): ?>
                <tr>
                    <td class="fw-semibold"><?= e($route['origin']) ?></td>
                    <td class="fw-semibold"><?= e($route['destination']) ?></td>
                    <td><?= $route['distance_km'] !== null ? e($route['distance_km']) . ' km' : '—' ?></td>
                    <td><?= e($route['estimated_duration'] ?: '—') ?></td>
                    <td><?= e(money($route['base_fare'])) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editRouteModal<?= (int)$route['route_id'] ?>">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this route?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="route_id" value="<?= (int)$route['route_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editRouteModal<?= (int)$route['route_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="update">
                                <input type="hidden" name="route_id" value="<?= (int)$route['route_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Route</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Origin</label>
                                            <input type="text" name="origin" class="form-control" value="<?= e($route['origin']) ?>" required>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Destination</label>
                                            <input type="text" name="destination" class="form-control" value="<?= e($route['destination']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Distance (km)</label>
                                            <input type="number" step="0.01" name="distance_km" class="form-control" value="<?= e((string)$route['distance_km']) ?>">
                                        </div>
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Estimated Duration</label>
                                            <input type="text" name="estimated_duration" class="form-control" value="<?= e($route['estimated_duration']) ?>" placeholder="e.g. 6h 30m">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Base Fare (BDT)</label>
                                        <input type="number" step="0.01" min="0" name="base_fare" class="form-control" value="<?= e((string)$route['base_fare']) ?>" required>
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

<div class="modal fade" id="addRouteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Origin</label>
                            <input type="text" name="origin" class="form-control" placeholder="e.g. Dhaka" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Destination</label>
                            <input type="text" name="destination" class="form-control" placeholder="e.g. Sylhet" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Distance (km)</label>
                            <input type="number" step="0.01" name="distance_km" class="form-control" placeholder="e.g. 247">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Estimated Duration</label>
                            <input type="text" name="estimated_duration" class="form-control" placeholder="e.g. 5h 45m">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Base Fare (BDT)</label>
                        <input type="number" step="0.01" min="0" name="base_fare" class="form-control" placeholder="e.g. 750" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Route</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>