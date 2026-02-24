<?php
// views/peminjam/dashboard.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn = getConnection();
$uid  = getUserId();

$dipinjam    = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE UserID='$uid' AND StatusPeminjaman='dipinjam'")->fetch_row()[0] ?? 0;
$totalPinjam = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE UserID='$uid'")->fetch_row()[0] ?? 0;
$totalBuku   = $conn->query("SELECT COUNT(*) FROM buku WHERE Stok>0")->fetch_row()[0] ?? 0;

$aktif = $conn->query("
  SELECT p.PeminjamanID, b.Judul, p.TanggalPeminjaman, p.TanggalPengembalian, p.StatusPeminjaman
  FROM peminjaman p
  JOIN buku b ON p.BukuID=b.BukuID
  WHERE p.UserID='$uid' AND p.StatusPeminjaman='dipinjam'
  ORDER BY p.TanggalPengembalian ASC LIMIT 5
");
?>
<?php require_once '../../includes/sidebar.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Beranda — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Beranda</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-avatar"><?= getInitial() ?></div>
    </div>
  </div>

  <div class="page-content">
    <div style="margin-bottom:28px">
      <h2 style="font-family:'Playfair Display',serif;font-size:26px;color:var(--ink)">Halo, <?= htmlspecialchars(getNama()) ?> 👋</h2>
      <p style="color:var(--text);margin-top:4px">Selamat datang di DigiLibrary. Selamat membaca!</p>
    </div>

    <div class="stats-grid">
      <div class="stat-card blue"><div class="stat-icon">📖</div><div class="stat-value"><?= $dipinjam ?></div><div class="stat-label">Sedang Dipinjam</div></div>
      <div class="stat-card gold"><div class="stat-icon">📋</div><div class="stat-value"><?= $totalPinjam ?></div><div class="stat-label">Total Riwayat Pinjam</div></div>
      <div class="stat-card green"><div class="stat-icon">📚</div><div class="stat-value"><?= $totalBuku ?></div><div class="stat-label">Buku Tersedia</div></div>
    </div>

    <div class="card">
      <div class="card-header">
          <h3>🔖 Buku Sedang Dipinjam</h3>
          <a href="../../views/peminjam/peminjaman.php" class="btn btn-sm btn-outline">Lihat Semua</a>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead><tr><th>Buku</th><th>Tgl Pinjam</th><th>Jatuh Tempo</th><th>Sisa Hari</th></tr></thead>
          <tbody>
            <?php if ($aktif && $aktif->num_rows > 0): while($d=$aktif->fetch_assoc()): 
              $sisa = ceil((strtotime($d['TanggalPengembalian']) - strtotime('today')) / 86400);
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($d['Judul']) ?></strong></td>
              <td><?= date('d/m/Y', strtotime($d['TanggalPeminjaman'])) ?></td>
              <td><?= date('d/m/Y', strtotime($d['TanggalPengembalian'])) ?></td>
              <td>
                <?php if ($sisa < 0): ?>
                  <span class="badge badge-danger">Terlambat <?= abs($sisa) ?> hari</span>
                <?php elseif ($sisa <= 2): ?>
                  <span class="badge badge-warning"><?= $sisa ?> hari lagi</span>
                <?php else: ?>
                  <span class="badge badge-success"><?= $sisa ?> hari lagi</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="4"><div class="empty-state"><div class="empty-icon">📭</div><p>Anda belum meminjam buku. <a href="../../views/peminjam/katalog.php" style="color:var(--gold)">Lihat katalog →</a></p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>
<?php $conn->close(); ?>