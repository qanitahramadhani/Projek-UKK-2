<?php
// views/petugas/dashboard.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn = getConnection();

// ── Perbaikan: nama kolom StatusPeminjaman, bukan Status ──
$dipinjam  = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dipinjam'")->fetch_row()[0]  ?? 0;
$terlambat = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='terlambat'")->fetch_row()[0] ?? 0;
$totalBuku = $conn->query("SELECT COUNT(*) FROM buku")->fetch_row()[0] ?? 0;

$recent = $conn->query("
    SELECT p.PeminjamanID, u.NamaLengkap, b.Judul,
           p.TanggalPeminjaman, p.TanggalPengembalian, p.StatusPeminjaman
    FROM peminjaman p
    JOIN user u ON p.UserID  = u.UserID
    JOIN buku b ON p.BukuID  = b.BukuID
    WHERE p.StatusPeminjaman = 'dipinjam'
    ORDER BY p.TanggalPengembalian ASC
    LIMIT 8
");

// ── Perbaikan: ganti match() → switch() agar kompatibel PHP 7 ──
function statusBadge($s) {
    switch ($s) {
        case 'dipinjam':     return '<span class="badge badge-info">Dipinjam</span>';
        case 'dikembalikan': return '<span class="badge badge-success">Dikembalikan</span>';
        case 'terlambat':    return '<span class="badge badge-danger">Terlambat</span>';
        default:             return '<span class="badge badge-default">' . htmlspecialchars($s) . '</span>';
    }
}

// ── Deteksi base URL project ──
$docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$scriptDir   = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
$subPath     = str_replace($docRoot, '', $scriptDir);
$parts       = explode('/', trim($subPath, '/'));
$projectName = $parts[0] ?? '';
$baseURL     = $projectName ? '/' . $projectName : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Petugas — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $baseURL ?>/public/css/main.css">
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
      <h1>Dashboard Petugas</h1>
    </div>
    <div class="topbar-right">
      <span style="font-size:13px;color:var(--text)"><?= date('d F Y') ?></span>
      <div class="topbar-avatar"><?= getInitial() ?></div>
    </div>
  </div>
  <div class="page-content">
    <div style="margin-bottom:28px">
      <h2 style="font-family:'Playfair Display',serif;font-size:26px;color:var(--ink)">
        Halo, <?= htmlspecialchars(getNama()) ?> &#128075;
      </h2>
      <p style="color:var(--text);margin-top:4px">Panel petugas perpustakaan DigiLibrary.</p>
    </div>

    <div class="stats-grid">
      <div class="stat-card gold">
        <div class="stat-icon">&#128218;</div>
        <div class="stat-value"><?= $totalBuku ?></div>
        <div class="stat-label">Total Buku</div>
      </div>
      <div class="stat-card blue">
        <div class="stat-icon">&#128260;</div>
        <div class="stat-value"><?= $dipinjam ?></div>
        <div class="stat-label">Sedang Dipinjam</div>
      </div>
      <div class="stat-card red">
        <div class="stat-icon">&#9888;&#65039;</div>
        <div class="stat-value"><?= $terlambat ?></div>
        <div class="stat-label">Terlambat</div>
      </div>
    </div>

    <div class="card" style="margin-bottom:24px">
      <div class="card-header"><h3>&#9889; Aksi Cepat</h3></div>
      <div class="card-body padded" style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="<?= $baseURL ?>/views/petugas/peminjaman.php"   class="btn btn-primary">&#128260; Proses Peminjaman</a>
        <a href="<?= $baseURL ?>/views/petugas/pengembalian.php" class="btn btn-success">&#8617;&#65039; Proses Pengembalian</a>
        <a href="<?= $baseURL ?>/views/petugas/buku.php"         class="btn btn-outline">&#128218; Lihat Buku</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>&#128276; Peminjaman Aktif (Terdekat Jatuh Tempo)</h3></div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Peminjam</th>
              <th>Buku</th>
              <th>Tgl Pinjam</th>
              <th>Jatuh Tempo</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recent && $recent->num_rows > 0): ?>
              <?php while ($d = $recent->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($d['NamaLengkap']) ?></td>
                <td><?= htmlspecialchars($d['Judul']) ?></td>
                <td><?= date('d/m/Y', strtotime($d['TanggalPeminjaman'])) ?></td>
                <td><?= date('d/m/Y', strtotime($d['TanggalPengembalian'])) ?></td>
                <td><?= statusBadge($d['StatusPeminjaman']) ?></td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5">
                  <div class="empty-state">
                    <div class="empty-icon">&#9989;</div>
                    <p>Tidak ada peminjaman aktif.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
<?php $conn->close(); ?>
<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>
