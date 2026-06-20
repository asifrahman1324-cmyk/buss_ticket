<?php
/**
 * config.php
 * ------------------------------------------------------------
 * Database connection (PostgreSQL via PDO) + shared helpers.
 * Every page in this project starts with: require_once 'config.php';
 * ------------------------------------------------------------
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // keep raw errors out of the browser in "production"

// ---------------------------------------------------------------
// Database connection settings & connection (PostgreSQL via PDO)
// ---------------------------------------------------------------
$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '5432';
$dbname   = getenv('DB_NAME') ?: 'bus_ticketing_db';
$dbuser   = getenv('DB_USER') ?: 'postgres';
$dbpass   = getenv('DB_PASSWORD') ?: '';
$sslmode  = getenv('DB_SSLMODE') ?: (getenv('DB_HOST') ? 'require' : '');

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    if ($sslmode !== '') {
        $dsn .= ";sslmode={$sslmode}";
    }
    $pdo = new PDO(
        $dsn,
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// ---------------------------------------------------------------
// Custom Session Handler for database-backed sessions (Vercel support)
// ---------------------------------------------------------------
class PdoSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['data'] : '';
        } catch (PDOException $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $timestamp = time();
            $stmt = $this->pdo->prepare('
                INSERT INTO sessions (id, data, timestamp)
                VALUES (:id, :data, :timestamp)
                ON CONFLICT (id) DO UPDATE
                SET data = excluded.data, timestamp = excluded.timestamp
            ');
            return $stmt->execute([
                'id' => $id,
                'data' => $data,
                'timestamp' => $timestamp
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $old = time() - $max_lifetime;
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE timestamp < :old');
            $stmt->execute(['old' => $old]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
}

// ---------------------------------------------------------------
// Session
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // If DB_HOST is set (e.g. deployed on Vercel), use the database session handler
    if (getenv('DB_HOST')) {
        $sessionHandler = new PdoSessionHandler($pdo);
        session_set_save_handler($sessionHandler, true);
    }
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------
// Supabase Storage upload helper
// ---------------------------------------------------------------
function upload_to_supabase(string $fileTmpPath, string $filename, string $mime): ?string
{
    $supabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey = getenv('SUPABASE_KEY');
    if (!$supabaseUrl || !$supabaseKey) {
        return null;
    }

    $bucket = 'buses';
    $url = rtrim($supabaseUrl, '/') . "/storage/v1/object/{$bucket}/{$filename}";
    $fileData = @file_get_contents($fileTmpPath);
    if ($fileData === false) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$supabaseKey}",
        "apikey: {$supabaseKey}",
        "Content-Type: {$mime}"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        return rtrim($supabaseUrl, '/') . "/storage/v1/object/public/{$bucket}/{$filename}";
    }

    return null;
}

// ---------------------------------------------------------------
// Output / input helpers
// ---------------------------------------------------------------

/** Escape a string for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Trim and collapse a posted/get value to a plain string. */
function input(string $key, string $default = '', string $source = 'post'): string
{
    $bag = $source === 'get' ? $_GET : $_POST;
    return isset($bag[$key]) ? trim((string)$bag[$key]) : $default;
}

/** Format a numeric amount as currency (BDT). */
function money(float|string $amount): string
{
    return 'BDT ' . number_format((float)$amount, 2);
}

// ---------------------------------------------------------------
// Flash messages (one-time alerts after redirects)
// ---------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect(string $url): never
{
    header("Location: {$url}");
    exit;
}

// ---------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Please go back and try again.');
    }
}

// ---------------------------------------------------------------
// Authentication / authorization helpers
// ---------------------------------------------------------------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        redirect('login.php');
    }
}

/** @param string[] $roles */
function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('403 - You do not have permission to access this page.');
    }
}

function ticket_code(): string
{
    return 'TKT-' . strtoupper(bin2hex(random_bytes(4)));
}
