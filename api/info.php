<?php
/**
 * api/info.php
 * Diagnostic script to check environment variables on Vercel.
 * (Passwords are redacted for security)
 */

header('Content-Type: text/plain');

echo "--- DATABASE CONFIGURATION DIAGNOSTICS ---\n\n";

$envVars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_SSLMODE', 'SUPABASE_URL'];

foreach ($envVars as $var) {
    echo "[$var]\n";
    echo "  getenv():     " . (getenv($var) !== false ? '"' . getenv($var) . '"' : "NOT SET") . "\n";
    echo "  \$_SERVER:     " . (isset($_SERVER[$var]) ? '"' . $_SERVER[$var] . '"' : "NOT SET") . "\n";
    echo "  \$_ENV:        " . (isset($_ENV[$var]) ? '"' . $_ENV[$var] . '"' : "NOT SET") . "\n";
    echo "\n";
}

echo "[DB_PASSWORD]\n";
$hasPassGet = getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== '';
$hasPassServ = isset($_SERVER['DB_PASSWORD']) && $_SERVER['DB_PASSWORD'] !== '';
$hasPassEnv = isset($_ENV['DB_PASSWORD']) && $_ENV['DB_PASSWORD'] !== '';
echo "  getenv():     " . ($hasPassGet ? "SET (Redacted)" : "NOT SET") . "\n";
echo "  \$_SERVER:     " . ($hasPassServ ? "SET (Redacted)" : "NOT SET") . "\n";
echo "  \$_ENV:        " . ($hasPassEnv ? "SET (Redacted)" : "NOT SET") . "\n";
echo "\n";

echo "--- CONNECTIVITY TEST ---\n";
$host     = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? 'localhost'));
$port     = getenv('DB_PORT') ?: ($_SERVER['DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '5432'));
$dbname   = getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? ($_ENV['DB_NAME'] ?? 'bus_ticketing_db'));
$dbuser   = getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? ($_ENV['DB_USER'] ?? 'postgres'));
$dbpass   = getenv('DB_PASSWORD') ?: ($_SERVER['DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? ''));
$sslmode  = getenv('DB_SSLMODE') ?: ($_SERVER['DB_SSLMODE'] ?? ($_ENV['DB_SSLMODE'] ?? ''));

echo "Resolved DB_HOST: $host\n";
echo "Resolved DB_PORT: $port\n";
echo "Resolved DB_USER: $dbuser\n";
echo "Resolved DB_NAME: $dbname\n";
echo "Resolved DB_SSLMODE: $sslmode\n";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    if ($sslmode !== '') {
        $dsn .= ";sslmode={$sslmode}";
    }
    echo "DSN: $dsn\n";
    
    $start = microtime(true);
    $pdo = new PDO($dsn, $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5 // 5 seconds timeout
    ]);
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "SUCCESS: Connected to database in {$duration}ms!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
