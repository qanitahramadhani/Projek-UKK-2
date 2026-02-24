<?php
// =============================================
// config/database.php
// Konfigurasi Koneksi Database
// =============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Ganti dengan username MySQL Anda
define('DB_PASS', '');           // Ganti dengan password MySQL Anda
define('DB_NAME', 'digitalibrary');

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    if ($conn->connect_error) {
        die(json_encode(['error' => 'Koneksi database gagal: ' . $conn->connect_error]));
    }
    return $conn;
}
?>
