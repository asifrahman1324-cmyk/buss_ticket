<?php
/**
 * api/index.php
 * Vercel Serverless entry point router.
 * Routes clean requests (e.g. /login) and standard PHP files (e.g. /login.php)
 * to their respective root-level PHP handlers.
 */

// Retrieve the requested URL path
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Clean up the path: if it is "/", default to "/index.php"
if ($path === '/' || $path === '') {
    $path = '/index.php';
}

// Support requests without the .php extension (e.g. /login -> /login.php)
if (substr($path, -4) !== '.php' && !is_dir(dirname(__DIR__) . $path)) {
    $phpFile = dirname(__DIR__) . $path . '.php';
    if (file_exists($phpFile) && is_file($phpFile)) {
        $path .= '.php';
    }
}

// Resolve the full file path in the parent directory
$file = dirname(__DIR__) . $path;

// Validate that it is a PHP file and exists
if (file_exists($file) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    // Override SCRIPT_FILENAME and PHP_SELF so pages can resolve headers, redirects, and sidebars correctly
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['PHP_SELF'] = $path;
    
    // Execute the PHP file
    require_once $file;
} else {
    // Return 404 for non-existent PHP routes
    http_response_code(404);
    echo "404 - Not Found";
}
