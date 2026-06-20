<?php
require_once __DIR__ . '/config.php';
require_role(['admin']);

$pageTitle = 'Bus Management';
$uploadDir = __DIR__ . '/uploads/buses/';

/** Handle a bus image upload; returns the stored relative path or null. */
function handle_bus_image_upload(string $uploadDir): ?string
{
    if (empty($_FILES['bus_image']['name'])) {
        return null;
    }
    if ($_FILES['bus_image']['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'Image upload failed. Please try again.');
        return null;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($_FILES['bus_image']['tmp_name']);
    if (!isset($allowed[$mime])) {
        flash('danger', 'Only JPG, PNG, or WEBP images are allowed.');
        return null;
    }
    if ($_FILES['bus_image']['size'] > 2 * 1024 * 1024) {
        flash('danger', 'Image must be smaller than 2MB.');
        return null;
    }

    $filename = 'bus_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];

    // Try to upload to Supabase Storage first
    $supabaseUrl = upload_to_supabase($_FILES['bus_image']['tmp_name'], $filename, $mime);
    if ($supabaseUrl !== null) {
        return $supabaseUrl;
    }

    // Fallback to local upload (useful for local development)
    // Make sure the upload directory exists locally
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $destination = $uploadDir . $filename;
    if (!move_uploaded_file($_FILES['bus_image']['tmp_name'], $destination)) {
        flash('danger', 'Could not save the uploaded image.');
        return null;
    }
    return 'uploads/buses/' . $filename;
}

// -----------------------------------------------------------------
// POST actions: create / update / delete
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = input('form_action');

    if ($postAction === 'create') {
        $busNumber  = input('bus_number');
        $busName    = input('bus_name');
        $busType    = input('bus_type');
        $totalSeats = (int)input('total_seats');
        $status     = input('status');

        $errors = [];
        if ($busNumber === '') $errors[] = 'Bus number is required.';
        if (!in_array($busType, ['AC', 'Non-AC'], true)) $errors[] = 'Invalid bus type.';
        if ($totalSeats <= 0) $errors[] = 'Total seats must be a positive number.';
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $imagePath = handle_bus_image_upload($uploadDir);
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO bus (bus_number, bus_name, bus_type, total_seats, image_path, status)
                     VALUES (:bus_number, :bus_name, :bus_type, :total_seats, :image_path, :status)
                     RETURNING bus_id'
                );
                $stmt->execute([
                    'bus_number'   => $busNumber,
                    'bus_name'     => $busName !== '' ? $busName : null,
                    'bus_type'     => $busType,
                    'total_seats'  => $totalSeats,
                    'image_path'   => $imagePath,
                    'status'       => $status,
                ]);
                flash('success', 'Bus added successfully.');
            } catch (PDOException $e) {
                flash('danger', str_contains($e->getMessage(), 'unique') ? 'A bus with this number already exists.' : 'Could not add bus.');
            }
        } else {
            flash('danger', implode(' ', $errors));
        }
        redirect('bus.php');
    }

    if ($postAction === 'update') {
        $busId      = (int)input('bus_id');
        $busNumber  = input('bus_number');
        $busName    = input('bus_name');
        $busType    = input('bus_type');
        $totalSeats = (int)input('total_seats');
        $status     = input('status');

        $errors = [];
        if ($busId <= 0) $errors[] = 'Invalid bus.';
        if ($busNumber === '') $errors[] = 'Bus number is required.';
        if (!in_array($busType, ['AC', 'Non-AC'], true)) $errors[] = 'Invalid bus type.';
        if ($totalSeats <= 0) $errors[] = 'Total seats must be a positive number.';
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $imagePath = handle_bus_image_upload($uploadDir);
            try {
                if ($imagePath !== null) {
                    $stmt = $pdo->prepare(
                        'UPDATE bus SET bus_number = :bus_number, bus_name = :bus_name, bus_type = :bus_type,
                                total_seats = :total_seats, image_path = :image_path, status = :status,
                                updated_at = CURRENT_TIMESTAMP
                         WHERE bus_id = :bus_id'
                    );
                    $stmt->execute([
                        'bus_number'  => $busNumber, 'bus_name' => $busName !== '' ? $busName : null,
                        'bus_type'    => $busType, 'total_seats' => $totalSeats,
                        'image_path'  => $imagePath, 'status' => $status, 'bus_id' => $busId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE bus SET bus_number = :bus_number, bus_name = :bus_name, bus_type = :bus_type,
                                total_seats = :total_seats, status = :status, updated_at = CURRENT_TIMESTAMP
                         WHERE bus_id = :bus_id'
                    );
                    $stmt->execute([
                        'bus_number'  => $busNumber, 'bus_name' => $busName !== '' ? $busName : null,
                        'bus_type'    => $busType, 'total_seats' => $totalSeats,
                        'status' => $status, 'bus_id' => $busId,
                    ]);
                }
                flash('success', 'Bus updated successfully.');
            } catch (PDOException $e) {
                flash('danger', str_contains($e->getMessage(), 'unique') ? 'A bus with this number already exists.' : 'Could not update bus.');
            }
        } else {
            flash('danger', implode(' ', $errors));
        }
        redirect('bus.php');
    }

    if ($postAction === 'delete') {
        $busId = (int)input('bus_id');
        $check = $pdo->prepare('SELECT COUNT(*) AS c FROM schedule WHERE bus_id = :bus_id');
        $check->execute(['bus_id' => $busId]);
        if ((int)$check->fetch()['c'] > 0) {
            flash('danger', 'This bus has existing schedules and cannot be deleted. Set its status to "inactive" instead.');
        } else {
            $pdo->prepare('DELETE FROM bus WHERE bus_id = :bus_id')->execute(['bus_id' => $busId]);
            flash('success', 'Bus deleted successfully.');
        }
        redirect('bus.php');
    }
}

// -----------------------------------------------------------------
// List buses
// -----------------------------------------------------------------
$buses = $pdo->query('SELECT * FROM bus ORDER BY bus_id DESC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="card section-card">
    <div class="section-card-header">
        <h2>All Buses</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBusModal">
            <i class="bi bi-plus-lg me-1"></i>Add Bus
        </button>
    </div>
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
            <tr><th>Photo</th><th>Bus #</th><th>Name</th><th>Type</th><th>Seats</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($buses)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No buses added yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($buses as $bus): ?>
                <tr>
                    <td>
                        <?php if ($bus['image_path']): ?>
                            <img src="<?= e($bus['image_path']) ?>" alt="<?= e($bus['bus_number']) ?>" class="bus-thumb">
                        <?php else: ?>
                            <span class="bus-thumb bus-thumb-placeholder"><i class="bi bi-bus-front"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= e($bus['bus_number']) ?></td>
                    <td><?= e($bus['bus_name'] ?: '—') ?></td>
                    <td><span class="badge text-bg-light border"><?= e($bus['bus_type']) ?></span></td>
                    <td><?= (int)$bus['total_seats'] ?></td>
                    <td><span class="badge status-badge status-<?= e($bus['status']) ?>"><?= e(ucfirst($bus['status'])) ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editBusModal<?= (int)$bus['bus_id'] ?>">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this bus? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="bus_id" value="<?= (int)$bus['bus_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editBusModal<?= (int)$bus['bus_id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="update">
                                <input type="hidden" name="bus_id" value="<?= (int)$bus['bus_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Bus - <?= e($bus['bus_number']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Bus Number</label>
                                        <input type="text" name="bus_number" class="form-control" value="<?= e($bus['bus_number']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Bus Name</label>
                                        <input type="text" name="bus_name" class="form-control" value="<?= e($bus['bus_name']) ?>">
                                    </div>
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Type</label>
                                            <select name="bus_type" class="form-select">
                                                <option value="AC" <?= $bus['bus_type'] === 'AC' ? 'selected' : '' ?>>AC</option>
                                                <option value="Non-AC" <?= $bus['bus_type'] === 'Non-AC' ? 'selected' : '' ?>>Non-AC</option>
                                            </select>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <label class="form-label">Total Seats</label>
                                            <input type="number" name="total_seats" min="1" class="form-control" value="<?= (int)$bus['total_seats'] ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <?php foreach (['active', 'inactive', 'maintenance'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $bus['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Replace Photo</label>
                                        <input type="file" name="bus_image" class="form-control" accept="image/jpeg,image/png,image/webp">
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

<!-- Add Bus Modal -->
<div class="modal fade" id="addBusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Bus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bus Number</label>
                        <input type="text" name="bus_number" class="form-control" placeholder="e.g. DHA-AC-104" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bus Name</label>
                        <input type="text" name="bus_name" class="form-control" placeholder="e.g. Green Express">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Type</label>
                            <select name="bus_type" class="form-select">
                                <option value="AC">AC</option>
                                <option value="Non-AC">Non-AC</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Total Seats</label>
                            <input type="number" name="total_seats" min="1" class="form-control" placeholder="e.g. 40" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bus Photo</label>
                        <input type="file" name="bus_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG, or WEBP. Max 2MB.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Bus</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>