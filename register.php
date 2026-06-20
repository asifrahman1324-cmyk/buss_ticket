<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$values = [
    'full_name' => '',
    'email' => '',
    'phone' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        csrf_verify();

        $values['full_name'] = input('full_name');
        $values['email'] = input('email');
        $values['phone'] = input('phone');

        $password = input('password');
        $confirmPassword = input('confirm_password');

        if ($values['full_name'] === '') {
            $errors[] = 'Full name is required.';
        }

        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {

            $check = $pdo->prepare(
                "SELECT 1 FROM users WHERE email = :email"
            );

            $check->execute([
                'email' => $values['email']
            ]);

            if ($check->fetch()) {
                $errors[] = 'An account with this email already exists.';
            }
        }

        if (empty($errors)) {

            $countStmt = $pdo->query(
                "SELECT COUNT(*) AS total FROM users"
            );

            $count = $countStmt->fetch(PDO::FETCH_ASSOC);

            $isFirstUser = ((int)$count['total']) === 0;

            $role = $isFirstUser
                ? 'admin'
                : 'customer';

            $hash = password_hash(
                $password,
                PASSWORD_BCRYPT
            );

            $insert = $pdo->prepare(
                "INSERT INTO users
                (
                    full_name,
                    email,
                    phone,
                    password_hash,
                    role
                )

                VALUES
                (
                    :full_name,
                    :email,
                    :phone,
                    :password_hash,
                    :role
                )

                RETURNING user_id"
            );

            $insert->execute([

                'full_name' => $values['full_name'],

                'email' => $values['email'],

                'phone' => $values['phone'] !== ''
                    ? $values['phone']
                    : null,

                'password_hash' => $hash,

                'role' => $role

            ]);

            $user = $insert->fetch(PDO::FETCH_ASSOC);

            flash(
                'success',
                'Registration successful. Please login.'
            );

            redirect('login.php');
        }

    }
    catch (Exception $e) {

        die($e->getMessage());

    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Register</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<div class="container mt-5">

<h2>Register</h2>

<?php foreach($errors as $error): ?>

<div class="alert alert-danger">

<?= e($error) ?>

</div>

<?php endforeach; ?>


<form method="POST">

<?= csrf_field() ?>

<div class="mb-3">

<label>Full Name</label>

<input
type="text"
name="full_name"
class="form-control"
value="<?= e($values['full_name']) ?>">

</div>


<div class="mb-3">

<label>Email</label>

<input
type="email"
name="email"
class="form-control"
value="<?= e($values['email']) ?>">

</div>


<div class="mb-3">

<label>Phone</label>

<input
type="text"
name="phone"
class="form-control"
value="<?= e($values['phone']) ?>">

</div>


<div class="mb-3">

<label>Password</label>

<input
type="password"
name="password"
class="form-control">

</div>


<div class="mb-3">

<label>Confirm Password</label>

<input
type="password"
name="confirm_password"
class="form-control">

</div>


<button class="btn btn-primary">

Create Account

</button>

</form>

</div>

</body>

</html>