<?php
// auth/unauthorized.php
require_once '../includes/auth.php';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Akses Ditolak — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../public/css/auth.css">
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; text-align:center; padding:40px; }
  .err-card { max-width:400px; }
  .err-icon { font-size:80px; margin-bottom:20px; animation:shake 0.5s ease; }
  @keyframes shake {
    0%,100% { transform:translateX(0); }
    25% { transform:translateX(-8px); }
    75% { transform:translateX(8px); }
  }
  h2 { font-family:'Playfair Display',serif; font-size:30px; color:#1a1a2e; margin-bottom:10px; }
  p  { color:#4a4a6a; margin-bottom:28px; line-height:1.6; }
  .btn-back {
    display:inline-block; padding:13px 28px;
    background:#1a1a2e; color:#e8d5b0;
    border-radius:10px; text-decoration:none;
    font-weight:600; font-size:15px;
    transition:background 0.2s, transform 0.1s;
  }
  .btn-back:hover { background:#2d2b55; transform:translateY(-1px); }
</style>
</head>
<body>
<div class="err-card">
  <div class="err-icon">🔒</div>
  <h2>Akses Ditolak</h2>
  <p>Anda tidak memiliki izin untuk mengakses halaman ini.<br>
     Silakan kembali ke halaman yang sesuai dengan peran Anda.</p>
  <?php if (isLoggedIn()): ?>
    <a href="../../index.php" class="btn-back">← Kembali ke Dashboard</a>
  <?php else: ?>
    <a href="../../auth/login.php" class="btn-back">← Kembali ke Login</a>
  <?php endif; ?>
</div>
</body>
</html>