<?php
// includes/sidebar.php
$user    = getCurrentUser();
$initial = strtoupper(substr($user['name'], 0, 1));

// ─── Hitung base URL project secara otomatis ──────────────────────────────────
$docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$scriptDir   = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
$subPath     = str_replace($docRoot, '', $scriptDir);
$parts       = explode('/', trim($subPath, '/'));
$projectName = $parts[0] ?? '';
$baseURL     = $projectName ? '/' . $projectName : '';

// PERBAIKAN DI SINI: Mengarahkan langsung ke logout.php di root folder project
$logoutURL = $baseURL . '/logout.php';

$menus = [
  'administrator' => [
    ['section' => 'Utama'],
    ['label' => 'Dashboard',        'icon' => '&#127968;', 'url' => $baseURL . '/views/admin/dashboard.php',  'page' => 'dashboard'],
    ['section' => 'Manajemen'],
    ['label' => 'Data Pengguna',    'icon' => '&#128101;', 'url' => $baseURL . '/views/admin/users.php',      'page' => 'users'],
    ['label' => 'Data Buku',        'icon' => '&#128218;', 'url' => $baseURL . '/views/admin/buku.php',       'page' => 'buku'],
    ['label' => 'Kategori',         'icon' => '&#127991;', 'url' => $baseURL . '/views/admin/kategori.php',   'page' => 'kategori'],
    ['label' => 'Peminjaman',       'icon' => '&#128203;', 'url' => $baseURL . '/views/admin/peminjaman.php', 'page' => 'peminjaman'],
    ['label' => 'Kelola Anggota',   'icon' => '&#128100;', 'url' => $baseURL . '/views/admin/anggota.php',    'page' => 'anggota'],
    ['section' => 'Laporan'],
    ['label' => 'Generate Laporan', 'icon' => '&#128202;', 'url' => $baseURL . '/views/admin/laporan.php',    'page' => 'laporan'],
  ],
  'petugas' => [
    ['section' => 'Utama'],
    ['label' => 'Dashboard',        'icon' => '&#127968;', 'url' => $baseURL . '/views/petugas/dashboard.php',  'page' => 'dashboard'],
    ['section' => 'Layanan'],
    ['label' => 'Data Buku',        'icon' => '&#128218;', 'url' => $baseURL . '/views/petugas/buku.php',       'page' => 'buku'],
    ['label' => 'Peminjaman',       'icon' => '&#128203;', 'url' => $baseURL . '/views/petugas/peminjaman.php', 'page' => 'peminjaman'],
    ['label' => 'Pengembalian',       'icon' => '&#128203;', 'url' => $baseURL . '/views/petugas/pengembalian.php', 'page' => 'pengembalian'],
    ['section' => 'Laporan'],
    ['label' => 'Generate Laporan', 'icon' => '&#128202;', 'url' => $baseURL . '/views/petugas/laporan.php',    'page' => 'laporan'],
  ],
  'peminjam' => [
    ['section' => 'Utama'],
    ['label' => 'Dashboard',       'icon' => '&#127968;', 'url' => $baseURL . '/views/peminjam/dashboard.php',  'page' => 'dashboard'],
    ['section' => 'Perpustakaan'],
    ['label' => 'Katalog Buku',    'icon' => '&#128218;', 'url' => $baseURL . '/views/peminjam/katalog.php',    'page' => 'katalog'],
    ['label' => 'Peminjaman Saya', 'icon' => '&#128203;', 'url' => $baseURL . '/views/peminjam/peminjaman.php', 'page' => 'peminjaman'],
    ['label' => 'Koleksi Saya',    'icon' => '&#10084;',  'url' => $baseURL . '/views/peminjam/koleksi.php',    'page' => 'koleksi'],
    ['label' => 'Ulasan Saya',     'icon' => '&#11088;',  'url' => $baseURL . '/views/peminjam/ulasan.php',     'page' => 'ulasan'],
    ['section' => 'Akun'],
    ['label' => 'Profil Saya',     'icon' => '&#128100;', 'url' => $baseURL . '/views/peminjam/profil.php',     'page' => 'profil'],
  ],
];

$roleMenus = $menus[$user['role']] ?? [];
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <span class="logo-icon">&#128218;</span>
    <h2>DigiLibrary</h2>
    <span class="role-badge <?= htmlspecialchars($user['role']) ?>"><?= ucfirst($user['role']) ?></span>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($roleMenus as $item): ?>
      <?php if (isset($item['section'])): ?>
        <div class="nav-section"><?= $item['section'] ?></div>
      <?php else: ?>
        <a href="<?= $item['url'] ?>"
           class="nav-item <?= (isset($activePage) && $activePage === $item['page']) ? 'active' : '' ?>">
          <span class="icon"><?= $item['icon'] ?></span>
          <?= $item['label'] ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= $initial ?></div>
      <div>
        <div class="uname"><?= htmlspecialchars($user['name']) ?></div>
        <div class="uemail"><?= htmlspecialchars($user['email']) ?></div>
      </div>
    </div>
    <a href="<?= $logoutURL ?>" class="btn-logout">&#128682; Keluar</a>
  </div>
</aside>