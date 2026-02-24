<?php
// views/petugas/anggota.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn   = getConnection();
$search = trim($_GET['q'] ?? '');
$where  = $search ? "AND (NamaLengkap LIKE '%$search%' OR Username LIKE '%$search%')" : '';
$data   = $conn->query("SELECT * FROM user WHERE Role='peminjam' $where ORDER BY NamaLengkap");
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Anggota — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/css/main.css">
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar_petugas.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Data Anggota</h1>
    </div>
  </div>
  <div class="page-content">
    <div class="card">
      <div class="card-header">
        <h3>👥 Daftar Anggota</h3>
        <form method="GET" class="search-bar">
          <input type="text" name="q" placeholder="Cari nama..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-outline">Cari</button>
          <?php if ($search): ?><a href="/views/petugas/anggota.php" class="btn btn-sm btn-outline">✕</a><?php endif; ?>
        </form>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead><tr><th>#</th><th>Nama</th><th>Username</th><th>Email</th><th>Status</th><th>Bergabung</th></tr></thead>
          <tbody>
            <?php if ($data->num_rows > 0): $no=1; while($u=$data->fetch_assoc()): ?>
            <tr>
              <td><?= $no++ ?></td>
              <td><?= htmlspecialchars($u['NamaLengkap']) ?></td>
              <td><code><?= htmlspecialchars($u['Username']) ?></code></td>
              <td><?= htmlspecialchars($u['Email']) ?></td>
              <td><span class="badge <?= $u['Status']==='aktif'?'badge-success':'badge-danger' ?>"><?= ucfirst($u['Status']) ?></span></td>
              <td><?= date('d/m/Y', strtotime($u['CreatedAt'])) ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">👥</div><p>Tidak ada anggota ditemukan.</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open');}</script>
</body>
</html>
