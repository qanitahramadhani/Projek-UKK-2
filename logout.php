<?php
// logout.php — letakkan di: digitalibrary/logout.php (ROOT project)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Deteksi nama subfolder project otomatis
$docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$scriptDir   = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
$subPath     = str_replace($docRoot, '', $scriptDir);
$parts       = explode('/', trim($subPath, '/'));
$projectName = $parts[0] ?? '';
$baseURL     = $projectName ? '/' . $projectName : '';

// Menggunakan path yang benar: /NamaProject/auth/login.php
header('Location: ' . $baseURL . '/auth/login.php');
exit;
?>