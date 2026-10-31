<?php
// dev/router.php  Local development only:  php -S localhost:8000 -t public dev/router.php
// Serves public/ as the web root and maps /api/... to the api/ folder outside it,
// exactly like the Apache Alias in docker/apache.conf.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    $file = realpath(__DIR__ . '/../api/' . substr($path, 5));
    $root = realpath(__DIR__ . '/../api');
    if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && str_ends_with($file, '.php') && is_file($file)) {
        chdir(dirname($file));
        require $file;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":{"code":"not_found","message":"No such endpoint"}}';
    return true;
}
if ($path === '/') { require __DIR__ . '/../public/index.html'; return true; }
return false;   // let the built-in server serve the static file
