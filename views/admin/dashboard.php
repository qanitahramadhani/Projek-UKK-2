<?php
// views/admin/dashboard.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();

// Stats - Menyesuaikan nama kolom StatusPeminjaman
$totalBuku    = $conn->query("SELECT COUNT(*) FROM buku")->fetch_row()[0] ?? 0;
$totalAnggota = $conn->query("SELECT COUNT(*) FROM user WHERE Role='peminjam'")->fetch_row()[0] ?? 0;
$dipinjam     = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dipinjam'")->fetch_row()[0] ?? 0;
$terlambat    = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='terlambat'")->fetch_row()[0] ?? 0;

// Peminjaman terbaru - Menyesuaikan TanggalPeminjaman, TanggalPengembalian
$recent = $conn->query("
  SELECT p.PeminjamanID, u.NamaLengkap, b.Judul, p.TanggalPeminjaman, p.TanggalPengembalian, p.StatusPeminjaman
  FROM peminjaman p
  JOIN user u ON p.UserID = u.UserID
  JOIN buku b ON p.BukuID = b.BukuID
  ORDER BY p.PeminjamanID DESC LIMIT 8
");

// Proteksi agar baris 31 tidak Fatal Error jika query gagal
if (!$recent) {
    die("Gagal memuat data: " . $conn->error);
}

$conn->close();

function statusBadge($s) {
    switch($s) {
        case 'dipinjam':
            return '<span class="badge badge-info">Dipinjam</span>';
        case 'dikembalikan':
            return '<span class="badge badge-success">Dikembalikan</span>';
        case 'terlambat':
            return '<span class="badge badge-danger">Terlambat</span>';
        default:
            return '<span class="badge badge-default">' . ucfirst($s) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Admin — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../..//public/css/main.css">
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Dashboard</h1>
    </div>
    <div class="topbar-right">
      <span style="font-size:13px;color:var(--text)"><?= date('l, d F Y') ?></span>
      <div class="topbar-avatar"><?= getInitial() ?></div>
    </div>
  </div>

  <div class="page-content">
    <!-- Welcome -->
    <div style="margin-bottom:28px">
      <h2 style="font-family:'Playfair Display',serif;font-size:26px;color:var(--ink)">
        Halo, <?= htmlspecialchars(getNama()) ?> 👋
      </h2>
      <p style="color:var(--text);margin-top:4px">Selamat datang di panel administrator DigiLibrary.</p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card gold">
        <div class="stat-icon">📖</div>
        <div class="stat-value"><?= $totalBuku ?></div>
        <div class="stat-label">Total Koleksi Buku</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= $totalAnggota ?></div>
        <div class="stat-label">Total Anggota</div>
      </div>
      <div class="stat-card blue">
        <div class="stat-icon">🔄</div>
        <div class="stat-value"><?= $dipinjam ?></div>
        <div class="stat-label">Sedang Dipinjam</div>
      </div>
      <div class="stat-card red">
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?= $terlambat ?></div>
        <div class="stat-label">Terlambat Kembali</div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card" style="margin-bottom:24px">
      <div class="card-header"><h3>⚡ Aksi Cepat</h3></div>
      <div class="card-body padded" style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="../../views/admin/buku.php" class="btn btn-primary">📖 Tambah Buku</a>
        <a href="../../views/admin/anggota.php" class="btn btn-success">👤 Kelola Anggota</a>
        <a href="../../views/admin/peminjaman.php" class="btn btn-warning">🔄 Proses Peminjaman</a>
        <a href="../../views/admin/laporan.php" class="btn btn-outline">📊 Lihat Laporan</a>
      </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card">
      <div class="card-header">
        <h3>📋 Peminjaman Terbaru</h3>
        <a href="../../views/admin/peminjaman.php" class="btn btn-sm btn-outline">Lihat Semua</a>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Peminjam</th>
              <th>Buku</th>
              <th>Tgl Pinjam</th>
              <th>Tgl Kembali</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recent->num_rows > 0): ?>
              <?php $no = 1; while ($row = $recent->fetch_assoc()): ?>
              <tr>
                <td><?= $no++ ?></td>
                <td><?= htmlspecialchars($row['NamaLengkap']) ?></td>
                <td><?= htmlspecialchars($row['Judul']) ?></td>
                <td><?= date('d/m/Y', strtotime($row['TanggalPeminjaman'])) ?></td>
                <td><?= date('d/m/Y', strtotime($row['TanggalPengembalian'])) ?></td>
                <td><?= statusBadge($row['StatusPeminjaman']) ?></td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="6">
                <div class="empty-state">
                  <div class="empty-icon">📭</div>
                  <p>Belum ada data peminjaman</p>
                </div>
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div><!-- /page-content -->
</div><!-- /main -->

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>