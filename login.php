<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $emailValue = input('email');
    $password   = input('password');

    if ($emailValue === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, password_hash, role, is_active
             FROM users
             WHERE email = :email'
        );
        $stmt->execute(['email' => $emailValue]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } elseif (!$user['is_active']) {
            $errors[] = 'This account has been deactivated. Please contact the administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'user_id'   => $user['user_id'],
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
                'role'      => $user['role'],
            ];
            flash('success', 'Welcome back, ' . $user['full_name'] . '!');
            redirect('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ICT BD Bus Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-wrap">
        <div class="auth-card card">
            <div class="auth-brand">
                <span class="auth-brand-mark">&#9709;</span>
                <div>
                    <div class="auth-brand-name">ICT BD Bus Services</div>
                    <div class="auth-brand-sub">Bus Ticketing Management System</div>
                </div>
            </div>

            <?php if ($flash = get_flash()): ?>
                <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger')) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endforeach; ?>

            <form method="post" action="login.php" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" value="<?= e($emailValue) ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>

            <p class="auth-footnote">Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</body>
</html>