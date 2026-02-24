<?php
// =============================================
// includes/auth.php
// Helper Autentikasi & Sesi
// =============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getRole() {
    return $_SESSION['role'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../../auth/login.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!in_array(getRole(), (array)$roles)) {
        header('Location: ../../auth/unauthorized.php');
        exit;
    }
}

// --- FUNGSI TAMBAHAN UNTUK DASHBOARD ---

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getNama() {
    return $_SESSION['nama'] ?? 'User';
}

function getInitial() {
    $nama = getNama();
    return strtoupper(substr($nama, 0, 1));
}

function getCurrentUser() {
    return [
        'id'    => $_SESSION['user_id'] ?? null,
        'name'  => $_SESSION['nama'] ?? '',
        'role'  => $_SESSION['role'] ?? '',
        'email' => $_SESSION['email'] ?? '',
    ];
}

function logout() {
    session_destroy();
    header('Location: ../../auth/login.php');
    exit;
}
?>