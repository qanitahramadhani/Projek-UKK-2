<?php
// index.php — Root redirect
require_once 'includes/auth.php';
if (isLoggedIn()) {
    $role = getRole();
    if ($role === 'administrator') header('Location: /views/admin/dashboard.php');
    elseif ($role === 'petugas')   header('Location: /views/petugas/dashboard.php');
    else                           header('Location: /views/peminjam/dashboard.php');
} else {
    header('Location: /auth/login.php');
}
exit;