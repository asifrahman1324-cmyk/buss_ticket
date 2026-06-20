<?php
/**
 * api/setup.php
 * -------------------------------------------------------
 * One-time database setup endpoint.
 * Visit /api/setup.php?key=install2024 to create all tables.
 * After tables are created, this endpoint can be removed.
 * -------------------------------------------------------
 */

header('Content-Type: text/html; charset=utf-8');

// Simple security key to prevent accidental/malicious runs
$setupKey = $_GET['key'] ?? '';
if ($setupKey !== 'install2024') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;">';
    echo '<h1>🔒 Setup Locked</h1>';
    echo '<p>Append <code>?key=install2024</code> to the URL to run database setup.</p>';
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------
// Connect to database directly (bypass config.php session logic)
// ---------------------------------------------------------------
$host     = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? 'localhost'));
$port     = getenv('DB_PORT') ?: ($_SERVER['DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '5432'));
$dbname   = getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? ($_ENV['DB_NAME'] ?? 'bus_ticketing_db'));
$dbuser   = getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? ($_ENV['DB_USER'] ?? 'postgres'));
$dbpass   = getenv('DB_PASSWORD') ?: ($_SERVER['DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? ''));
$sslmode  = getenv('DB_SSLMODE') ?: ($_SERVER['DB_SSLMODE'] ?? ($_ENV['DB_SSLMODE'] ?? 'require'));

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
if ($sslmode !== '') {
    $dsn .= ";sslmode={$sslmode}";
}

$results = [];

try {
    $pdo = new PDO($dsn, $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $results[] = ['✅ Database connection', 'Connected successfully'];
} catch (PDOException $e) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;">';
    echo '<h1>❌ Database Connection Failed</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------
// Schema: Create all tables
// ---------------------------------------------------------------
$schemaStatements = [

    // Sessions table (required for Vercel serverless sessions)
    'sessions' => "
        CREATE TABLE IF NOT EXISTS sessions (
            id          VARCHAR(255) PRIMARY KEY,
            data        TEXT NOT NULL,
            timestamp   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sessions_timestamp ON sessions(timestamp);
    ",

    // Users
    'users' => "
        CREATE TABLE IF NOT EXISTS users (
            user_id        SERIAL PRIMARY KEY,
            full_name      VARCHAR(150) NOT NULL,
            email          VARCHAR(150) NOT NULL UNIQUE,
            phone          VARCHAR(20),
            password_hash  VARCHAR(255) NOT NULL,
            role           VARCHAR(20) NOT NULL DEFAULT 'customer'
                           CHECK (role IN ('admin', 'staff', 'customer')),
            is_active      BOOLEAN NOT NULL DEFAULT TRUE,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Employee details
    'employee_details' => "
        CREATE TABLE IF NOT EXISTS employee_details (
            employee_id   SERIAL PRIMARY KEY,
            user_id       INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            designation   VARCHAR(100),
            joining_date  DATE,
            salary        NUMERIC(10,2),
            address       TEXT,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Bus
    'bus' => "
        CREATE TABLE IF NOT EXISTS bus (
            bus_id        SERIAL PRIMARY KEY,
            bus_number    VARCHAR(50) NOT NULL UNIQUE,
            bus_name      VARCHAR(100),
            bus_type      VARCHAR(10) NOT NULL DEFAULT 'Non-AC'
                          CHECK (bus_type IN ('AC', 'Non-AC')),
            total_seats   INTEGER NOT NULL CHECK (total_seats > 0),
            image_path    VARCHAR(255),
            status        VARCHAR(20) NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active', 'inactive', 'maintenance')),
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Route
    'route' => "
        CREATE TABLE IF NOT EXISTS route (
            route_id            SERIAL PRIMARY KEY,
            origin              VARCHAR(100) NOT NULL,
            destination         VARCHAR(100) NOT NULL,
            distance_km         NUMERIC(6,2),
            estimated_duration  VARCHAR(50),
            base_fare           NUMERIC(10,2) NOT NULL CHECK (base_fare >= 0),
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Schedule
    'schedule' => "
        CREATE TABLE IF NOT EXISTS schedule (
            schedule_id      SERIAL PRIMARY KEY,
            bus_id           INTEGER NOT NULL REFERENCES bus(bus_id) ON DELETE CASCADE,
            route_id         INTEGER NOT NULL REFERENCES route(route_id) ON DELETE CASCADE,
            departure_date   DATE NOT NULL,
            departure_time   TIME NOT NULL,
            arrival_time     TIME,
            fare             NUMERIC(10,2) NOT NULL CHECK (fare >= 0),
            available_seats  INTEGER NOT NULL CHECK (available_seats >= 0),
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Booking
    'booking' => "
        CREATE TABLE IF NOT EXISTS booking (
            booking_id      SERIAL PRIMARY KEY,
            customer_id     INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            schedule_id     INTEGER NOT NULL REFERENCES schedule(schedule_id) ON DELETE CASCADE,
            seat_numbers    VARCHAR(150) NOT NULL,
            seat_count      INTEGER NOT NULL CHECK (seat_count > 0),
            total_amount    NUMERIC(10,2) NOT NULL CHECK (total_amount >= 0),
            booking_status  VARCHAR(20) NOT NULL DEFAULT 'confirmed'
                            CHECK (booking_status IN ('confirmed', 'cancelled', 'completed')),
            booking_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Ticket
    'ticket' => "
        CREATE TABLE IF NOT EXISTS ticket (
            ticket_id        SERIAL PRIMARY KEY,
            booking_id       INTEGER NOT NULL REFERENCES booking(booking_id) ON DELETE CASCADE,
            ticket_code      VARCHAR(50) NOT NULL UNIQUE,
            passenger_name   VARCHAR(150) NOT NULL,
            seat_number      VARCHAR(10) NOT NULL,
            issued_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ",

    // Payment
    'payment' => "
        CREATE TABLE IF NOT EXISTS payment (
            payment_id       SERIAL PRIMARY KEY,
            booking_id       INTEGER NOT NULL REFERENCES booking(booking_id) ON DELETE CASCADE,
            amount           NUMERIC(10,2) NOT NULL CHECK (amount >= 0),
            method           VARCHAR(20) NOT NULL DEFAULT 'cash'
                             CHECK (method IN ('cash', 'card', 'mobile_banking', 'bank_transfer')),
            status           VARCHAR(20) NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending', 'paid', 'refunded', 'failed')),
            transaction_ref  VARCHAR(100),
            paid_at          TIMESTAMP
        );
    ",
];

foreach ($schemaStatements as $table => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ["✅ Table: {$table}", 'Created / already exists'];
    } catch (PDOException $e) {
        $results[] = ["❌ Table: {$table}", $e->getMessage()];
    }
}

// ---------------------------------------------------------------
// Indexes (safe to re-run with IF NOT EXISTS)
// ---------------------------------------------------------------
$indexes = [
    'CREATE INDEX IF NOT EXISTS idx_schedule_bus       ON schedule(bus_id)',
    'CREATE INDEX IF NOT EXISTS idx_schedule_route     ON schedule(route_id)',
    'CREATE INDEX IF NOT EXISTS idx_schedule_date      ON schedule(departure_date)',
    'CREATE INDEX IF NOT EXISTS idx_booking_customer   ON booking(customer_id)',
    'CREATE INDEX IF NOT EXISTS idx_booking_schedule   ON booking(schedule_id)',
    'CREATE INDEX IF NOT EXISTS idx_booking_date       ON booking(booking_date)',
    'CREATE INDEX IF NOT EXISTS idx_ticket_booking     ON ticket(booking_id)',
    'CREATE INDEX IF NOT EXISTS idx_payment_booking    ON payment(booking_id)',
    'CREATE INDEX IF NOT EXISTS idx_employee_user      ON employee_details(user_id)',
];

foreach ($indexes as $idx) {
    try {
        $pdo->exec($idx);
    } catch (PDOException $e) {
        // ignore duplicate index errors
    }
}
$results[] = ['✅ Indexes', 'All indexes created'];

// ---------------------------------------------------------------
// Optional: Seed sample data (only if bus table is empty)
// ---------------------------------------------------------------
$seedData = isset($_GET['seed']) && $_GET['seed'] === '1';
if ($seedData) {
    $busCount = (int)$pdo->query("SELECT COUNT(*) FROM bus")->fetchColumn();
    if ($busCount === 0) {
        try {
            $pdo->exec("
                INSERT INTO bus (bus_number, bus_name, bus_type, total_seats, status) VALUES
                ('DHA-AC-101', 'Green Express',   'AC',     36, 'active'),
                ('DHA-AC-102', 'Royal Coach',     'AC',     40, 'active'),
                ('DHA-NA-201', 'City Link',       'Non-AC', 44, 'active'),
                ('DHA-NA-202', 'Highway King',    'Non-AC', 44, 'active'),
                ('DHA-AC-103', 'Silver Star',     'AC',     32, 'active');
            ");

            $pdo->exec("
                INSERT INTO route (origin, destination, distance_km, estimated_duration, base_fare) VALUES
                ('Dhaka',     'Chittagong', 264.00, '6h 30m', 850.00),
                ('Dhaka',     'Sylhet',     247.00, '5h 45m', 750.00),
                ('Dhaka',     'Khulna',     334.00, '7h 00m', 900.00),
                ('Dhaka',     'Rajshahi',   256.00, '5h 30m', 700.00),
                ('Chittagong','Cox''s Bazar', 152.00, '3h 30m', 450.00);
            ");

            $pdo->exec("
                INSERT INTO schedule (bus_id, route_id, departure_date, departure_time, arrival_time, fare, available_seats) VALUES
                (1, 1, CURRENT_DATE,     '08:00', '14:30', 850.00, 36),
                (2, 1, CURRENT_DATE,     '22:00', '04:30', 900.00, 40),
                (3, 2, CURRENT_DATE,     '09:30', '15:15', 750.00, 44),
                (4, 4, CURRENT_DATE + 1, '07:00', '12:30', 700.00, 44),
                (1, 3, CURRENT_DATE + 1, '21:00', '04:00', 950.00, 36),
                (5, 5, CURRENT_DATE + 1, '10:00', '13:30', 450.00, 32),
                (2, 2, CURRENT_DATE + 2, '23:00', '04:45', 800.00, 40),
                (3, 1, CURRENT_DATE + 2, '08:30', '15:00', 850.00, 44);
            ");

            $results[] = ['✅ Sample Data', 'Buses, routes & schedules seeded'];
        } catch (PDOException $e) {
            $results[] = ['❌ Sample Data', $e->getMessage()];
        }
    } else {
        $results[] = ['ℹ️ Sample Data', "Skipped — bus table already has {$busCount} row(s)"];
    }
}

// ---------------------------------------------------------------
// Output results as a nice HTML page
// ---------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - ICT BD Bus Services</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px 20px; }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { font-size: 1.8rem; margin-bottom: 8px; color: #38bdf8; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; }
        .result-table { width: 100%; border-collapse: collapse; }
        .result-table td { padding: 12px 16px; border-bottom: 1px solid #1e293b; }
        .result-table td:first-child { font-weight: 600; white-space: nowrap; }
        .result-table td:last-child { color: #94a3b8; word-break: break-all; }
        .actions { margin-top: 30px; display: flex; gap: 12px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.95rem; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #334155; color: #e2e8f0; }
        .btn:hover { opacity: 0.9; }
        .note { margin-top: 24px; padding: 16px; background: #1e293b; border-radius: 8px; border-left: 4px solid #f59e0b; color: #fbbf24; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚌 Database Setup Complete</h1>
        <p class="subtitle">ICT BD Bus Services — Supabase PostgreSQL</p>

        <table class="result-table">
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r[0]) ?></td>
                    <td><?= htmlspecialchars($r[1]) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="actions">
            <?php if (!$seedData): ?>
                <a href="setup.php?key=install2024&seed=1" class="btn btn-secondary">🌱 Run Again + Seed Sample Data</a>
            <?php endif; ?>
            <a href="/" class="btn btn-primary">🏠 Go to Homepage</a>
        </div>

        <div class="note">
            <strong>⚠️ Next Steps:</strong><br>
            1. Register your first account at <code>/register.php</code> — it will automatically become <strong>admin</strong>.<br>
            2. After setup is working, you can delete this <code>api/setup.php</code> file for security.
        </div>
    </div>
</body>
</html>
