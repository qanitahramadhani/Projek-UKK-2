<?php
// views/petugas/laporan.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn = getConnection();

define('DENDA_DEFAULT', 5000);

// ─── Cover Base URL ───────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// Cek kolom DendaPerHari
$colCheck    = $conn->query("SHOW COLUMNS FROM buku LIKE 'DendaPerHari'");
$hasDendaCol = $colCheck && $colCheck->num_rows > 0;

// ─── Statistik Ringkas (petugas) ──────────────────────────────────────────────
$totalPinjam  = (int)($conn->query("SELECT COUNT(*) FROM peminjaman")->fetch_row()[0] ?? 0);
$dikembalikan = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan'")->fetch_row()[0] ?? 0);
$terlambat    = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='terlambat'")->fetch_row()[0] ?? 0);
$dipinjam     = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dipinjam'")->fetch_row()[0] ?? 0);

$cntDendaBelum   = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'")->fetch_row()[0] ?? 0);
$totalDendaBelum = (int)($conn->query("SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'")->fetch_row()[0] ?? 0);
$totalDendaLunas = (int)($conn->query("SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Lunas'")->fetch_row()[0] ?? 0);

// Estimasi denda aktif
$dendaSelect = $hasDendaCol
    ? "DATEDIFF(CURDATE(), p.TanggalPengembalian) * COALESCE(b.DendaPerHari, " . DENDA_DEFAULT . ")"
    : "DATEDIFF(CURDATE(), p.TanggalPengembalian) * " . DENDA_DEFAULT;

$totalDendaAktifQ = $conn->query("
    SELECT COALESCE(SUM(
        CASE WHEN p.StatusPeminjaman IN ('dipinjam','terlambat') AND p.TanggalPengembalian < CURDATE()
             THEN $dendaSelect ELSE 0 END
    ), 0) AS total
    FROM peminjaman p
    JOIN buku b ON p.BukuID = b.BukuID
");
$totalDendaAktif = (int)($totalDendaAktifQ ? $totalDendaAktifQ->fetch_row()[0] : 0);

// ─── Auto-update terlambat ────────────────────────────────────────────────────
$conn->query("
    UPDATE peminjaman SET StatusPeminjaman = 'terlambat'
    WHERE StatusPeminjaman = 'dipinjam' AND TanggalPengembalian < CURDATE()
");

// ─── Filter & Search (identik laporan admin) ──────────────────────────────────
$filterTab = $_GET['filter'] ?? 'semua';
$cariQ     = trim($_GET['q'] ?? '');

$whereFilter = "WHERE 1=1";
if ($filterTab === 'dipinjam')         $whereFilter .= " AND p.StatusPeminjaman = 'dipinjam'";
elseif ($filterTab === 'terlambat')    $whereFilter .= " AND p.StatusPeminjaman = 'terlambat'";
elseif ($filterTab === 'dikembalikan') $whereFilter .= " AND p.StatusPeminjaman = 'dikembalikan'";
elseif ($filterTab === 'belum')        $whereFilter .= " AND p.StatusPeminjaman = 'dikembalikan' AND p.StatusBayarDenda = 'Belum'";
if ($cariQ) {
    $cEsc = $conn->real_escape_string($cariQ);
    $whereFilter .= " AND (b.Judul LIKE '%$cEsc%' OR u.NamaLengkap LIKE '%$cEsc%')";
}

// ─── Query utama (identik laporan admin) ──────────────────────────────────────
$daftarPeminjam = $conn->query("
    SELECT
        p.PeminjamanID, p.BukuID,
        p.TanggalPeminjaman, p.TanggalPengembalian, p.TanggalKembaliAktual,
        p.StatusPeminjaman,
        COALESCE(p.TotalDenda, 0)             AS TotalDenda,
        COALESCE(p.StatusBayarDenda, 'Lunas') AS StatusBayarDenda,
        b.Judul, b.CoverURL, b.DendaPerHari,
        u.NamaLengkap,
        DATEDIFF(CURDATE(), p.TanggalPengembalian) AS HariTelat
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    JOIN buku b ON p.BukuID = b.BukuID
    $whereFilter
    ORDER BY
        CASE p.StatusPeminjaman
            WHEN 'terlambat' THEN 1
            WHEN 'dipinjam'  THEN 2
            WHEN 'menunggu'  THEN 3
            ELSE 4
        END,
        p.TanggalPengembalian ASC
");

// Badge counts per tab
$tabCounts = ['semua' => $totalPinjam, 'dipinjam' => 0, 'terlambat' => 0, 'dikembalikan' => 0, 'belum' => $cntDendaBelum];
$tabQ = $conn->query("SELECT StatusPeminjaman, COUNT(*) AS n FROM peminjaman GROUP BY StatusPeminjaman");
if ($tabQ) while ($tc = $tabQ->fetch_assoc()) {
    $s = $tc['StatusPeminjaman'];
    if (isset($tabCounts[$s])) $tabCounts[$s] = (int)$tc['n'];
}

// ─── Tren Peminjaman 6 bulan (identik laporan admin) ─────────────────────────
$perBulan = $conn->query("
    SELECT DATE_FORMAT(TanggalPeminjaman, '%Y-%m') AS bulan,
           DATE_FORMAT(TanggalPeminjaman, '%b %Y')  AS label,
           COUNT(*) AS total
    FROM peminjaman
    WHERE TanggalPeminjaman >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY bulan, label
    ORDER BY bulan ASC
");
$chartLabels = [];
$chartData   = [];
if ($perBulan) while ($r = $perBulan->fetch_assoc()) {
    $chartLabels[] = $r['label'];
    $chartData[]   = (int)$r['total'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laporan — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
/* ── Section Title (identik laporan admin) ── */
.section-title{font-family:'Playfair Display',serif;font-size:18px;color:#1e1e2f;margin:28px 0 14px;display:flex;align-items:center;gap:10px;}
.section-title::after{content:'';flex:1;height:2px;background:linear-gradient(90deg,#e5e7eb,transparent);}

/* ── Stat Cards (identik laporan admin) ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:14px;margin-bottom:8px;}
.stat-card-v2{background:#fff;border-radius:14px;border:1px solid var(--border);padding:18px 20px;display:flex;flex-direction:column;gap:6px;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:transform .2s,box-shadow .2s;}
.stat-card-v2:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.sc-icon{font-size:26px;}
.sc-val{font-size:24px;font-weight:700;font-family:'Playfair Display',serif;color:#1e1e2f;line-height:1;}
.sc-lbl{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;}
.sc-sub{font-size:12px;color:#9ca3af;}
.stat-card-v2.red   {border-color:#fca5a5;background:linear-gradient(135deg,#fff,#fef2f2);} .stat-card-v2.red .sc-val{color:#dc2626;}
.stat-card-v2.orange{border-color:#fed7aa;background:linear-gradient(135deg,#fff,#fff7ed);} .stat-card-v2.orange .sc-val{color:#ea580c;}
.stat-card-v2.green {border-color:#bbf7d0;background:linear-gradient(135deg,#fff,#f0fdf4);} .stat-card-v2.green .sc-val{color:#16a34a;}
.stat-card-v2.blue  {border-color:#bfdbfe;background:linear-gradient(135deg,#fff,#eff6ff);} .stat-card-v2.blue .sc-val{color:#2563eb;}
.stat-card-v2.gold  {border-color:#fde68a;background:linear-gradient(135deg,#fff,#fffbeb);} .stat-card-v2.gold .sc-val{color:#d97706;}

/* ── Banner Terlambat (identik laporan admin) ── */
.terlambat-banner{background:linear-gradient(135deg,#fef2f2,#fee2e2);border:2px solid #fca5a5;border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.tb-icon{font-size:30px;flex-shrink:0;}
.tb-body h4{font-family:'Playfair Display',serif;font-size:15px;color:#7f1d1d;margin:0 0 2px;}
.tb-body p{font-size:13px;color:#991b1b;margin:0;}
.tb-total{margin-left:auto;text-align:right;flex-shrink:0;}
.tb-total .lbl{font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.5px;}
.tb-total .amt{font-size:20px;font-weight:700;color:#dc2626;font-family:'Playfair Display',serif;}

/* ── Cover (identik semua halaman) ── */
.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;display:block;}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc;}

/* ── Denda (identik laporan admin) ── */
.denda-wrap{display:flex;flex-direction:column;gap:2px;}
.denda-rate{font-size:12px;font-weight:700;color:#d97706;}
.denda-est{font-size:11px;color:#ef4444;}
.denda-nominal{font-size:13px;font-weight:700;color:#dc2626;}
.denda-nol{font-size:13px;color:#94a3b8;}
.telat-info{font-size:10px;color:#ef4444;display:block;margin-top:2px;}

/* ── Status Bayar badges (identik semua halaman) ── */
.badge-belum  {display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-lunas  {display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-nodenda{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11.5px;}

/* ── Chart (identik laporan admin) ── */
.chart-wrap{background:#fff;border-radius:14px;border:1px solid var(--border);padding:20px 22px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.chart-wrap h4{font-family:'Playfair Display',serif;font-size:16px;color:#1e1e2f;margin:0 0 16px;}
.chart-canvas{width:100%;height:220px;}

/* ── Filter Tabs (identik laporan admin) ── */
.filter-tabs{display:flex;gap:0;flex-wrap:wrap;margin-bottom:0;}
.filter-tab{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;font-size:13px;font-weight:600;border:1.5px solid var(--border);border-bottom:none;background:#f9fafb;color:#6b7280;text-decoration:none;transition:all .18s;border-radius:10px 10px 0 0;position:relative;top:1px;}
.filter-tab:hover{background:#fff;color:#1e1e2f;}
.filter-tab.active{background:#fff;color:#1e1e2f;border-color:#e5e7eb;z-index:1;}
.filter-tab.tab-blue.active  {color:#2563eb;border-top:2.5px solid #2563eb;}
.filter-tab.tab-red.active   {color:#dc2626;border-top:2.5px solid #dc2626;}
.filter-tab.tab-green.active {color:#16a34a;border-top:2.5px solid #16a34a;}
.filter-tab.tab-orange.active{color:#d97706;border-top:2.5px solid #d97706;}
.filter-tab.tab-default.active{color:#1e1e2f;border-top:2.5px solid #1e1e2f;}
.tab-count{font-size:11px;font-weight:700;background:#e5e7eb;color:#374151;border-radius:20px;padding:1px 7px;min-width:20px;text-align:center;transition:background .18s,color .18s;}
.filter-tab.tab-blue.active   .tab-count{background:#dbeafe;color:#1d4ed8;}
.filter-tab.tab-red.active    .tab-count{background:#fee2e2;color:#b91c1c;}
.filter-tab.tab-green.active  .tab-count{background:#dcfce7;color:#15803d;}
.filter-tab.tab-orange.active .tab-count{background:#ffedd5;color:#c2410c;}
.filter-tab.tab-default.active .tab-count{background:#f3f4f6;color:#1e1e2f;}

/* ── Row terlambat ── */
tr.row-telat{background:linear-gradient(90deg,#fef2f2,#fff)!important;}

/* ── Search bar ── */
.search-form{display:flex;gap:8px;align-items:center;}
.search-form input{border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-family:'DM Sans',sans-serif;font-size:13px;flex:1;min-width:220px;}
.search-form input:focus{outline:none;border-color:#6366f1;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>📊 Laporan Peminjaman</h1>
    </div>
    <div class="topbar-right">
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Cetak</button>
    </div>
  </div>

  <div class="page-content">

    <!-- ══ STATISTIK RINGKAS ══ -->
    <div class="section-title">📈 Ringkasan</div>
    <div class="stat-grid">
      <div class="stat-card-v2 gold">
        <span class="sc-icon">📋</span>
        <div class="sc-val"><?= $totalPinjam ?></div>
        <div class="sc-lbl">Total Transaksi</div>
      </div>
      <div class="stat-card-v2 blue">
        <span class="sc-icon">📖</span>
        <div class="sc-val"><?= $dipinjam ?></div>
        <div class="sc-lbl">Sedang Dipinjam</div>
      </div>
      <div class="stat-card-v2 green">
        <span class="sc-icon">✅</span>
        <div class="sc-val"><?= $dikembalikan ?></div>
        <div class="sc-lbl">Dikembalikan</div>
      </div>
      <div class="stat-card-v2 red">
        <span class="sc-icon">⚠️</span>
        <div class="sc-val"><?= $terlambat ?></div>
        <div class="sc-lbl">Terlambat</div>
      </div>
      <div class="stat-card-v2 orange">
        <span class="sc-icon">💸</span>
        <div class="sc-val">Rp <?= number_format($totalDendaAktif, 0, ',', '.') ?></div>
        <div class="sc-lbl">Estimasi Denda Aktif</div>
        <div class="sc-sub"><?= $terlambat ?> peminjam terlambat</div>
      </div>
      <div class="stat-card-v2 red">
        <span class="sc-icon">❌</span>
        <div class="sc-val">Rp <?= number_format($totalDendaBelum, 0, ',', '.') ?></div>
        <div class="sc-lbl">Denda Belum Dibayar</div>
        <div class="sc-sub"><?= $cntDendaBelum ?> transaksi belum lunas</div>
      </div>
      <div class="stat-card-v2 green">
        <span class="sc-icon">💰</span>
        <div class="sc-val">Rp <?= number_format($totalDendaLunas, 0, ',', '.') ?></div>
        <div class="sc-lbl">Denda Lunas</div>
        <div class="sc-sub">terkumpul dari pengembalian</div>
      </div>
    </div>

    <!-- ══ BANNER TERLAMBAT ══ -->
    <?php if ($terlambat > 0): ?>
    <div class="terlambat-banner" style="margin-top:20px;">
      <div class="tb-icon">🚨</div>
      <div class="tb-body">
        <h4>Ada <?= $terlambat ?> Peminjam Terlambat Mengembalikan!</h4>
        <p>Segera proses pengembalian melalui menu <strong>Pengembalian Buku</strong> dan tagihkan denda kepada peminjam.</p>
      </div>
      <div class="tb-total">
        <div class="lbl">Estimasi Total Denda</div>
        <div class="amt">Rp <?= number_format($totalDendaAktif, 0, ',', '.') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ TREN PEMINJAMAN ══ -->
    <?php if (!empty($chartLabels)): ?>
    <div class="section-title">📅 Tren Peminjaman (6 Bulan Terakhir)</div>
    <div class="chart-wrap">
      <canvas id="chartBulan" class="chart-canvas"></canvas>
    </div>
    <?php endif; ?>

    <!-- ══ TABEL DAFTAR PEMINJAM ══ -->
    <div class="section-title">📋 Daftar Peminjam</div>

    <!-- Search bar -->
    <form method="GET" class="search-form" style="margin-bottom:14px;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filterTab) ?>">
      <input type="text" name="q" placeholder="🔍 Cari nama peminjam / judul buku..."
             value="<?= htmlspecialchars($cariQ) ?>">
      <button type="submit" class="btn btn-outline btn-sm">Cari</button>
      <?php if ($cariQ): ?>
        <a href="laporan.php?filter=<?= $filterTab ?>" class="btn btn-sm">✕ Reset</a>
      <?php endif; ?>
    </form>

    <!-- Filter tabs (identik laporan admin) -->
    <div class="filter-tabs">
      <?php
      $tabs = [
          'semua'        => ['label' => 'Semua',             'icon' => '📋', 'color' => 'default'],
          'dipinjam'     => ['label' => 'Sedang Dipinjam',   'icon' => '📖', 'color' => 'blue'],
          'terlambat'    => ['label' => 'Terlambat',         'icon' => '🚨', 'color' => 'red'],
          'dikembalikan' => ['label' => 'Dikembalikan',      'icon' => '✅', 'color' => 'green'],
          'belum'        => ['label' => 'Denda Belum Bayar', 'icon' => '⚠️', 'color' => 'orange'],
      ];
      foreach ($tabs as $key => $tab):
          $active = $filterTab === $key;
          $count  = $tabCounts[$key] ?? 0;
          $url    = 'laporan.php?filter=' . $key . ($cariQ ? '&q=' . urlencode($cariQ) : '');
      ?>
      <a href="<?= $url ?>" class="filter-tab <?= $active ? 'active tab-'.$tab['color'] : '' ?>">
        <span><?= $tab['icon'] ?> <?= $tab['label'] ?></span>
        <span class="tab-count"><?= $count ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Tabel (identik laporan admin) -->
    <div class="card" style="margin-top:0;border-radius:0 14px 14px 14px;">
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cover</th>
              <th>Buku</th>
              <th>Peminjam</th>
              <th>Tgl Pinjam</th>
              <th>Jatuh Tempo</th>
              <th>Tgl Kembali Aktual</th>
              <th>Sisa / Telat</th>
              <th>Status</th>
              <th>Denda/Hari</th>
              <th>Total Denda</th>
              <th>Status Bayar</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($daftarPeminjam && $daftarPeminjam->num_rows > 0):
            $no = 1;
            while ($t = $daftarPeminjam->fetch_assoc()):
              $coverUrl      = !empty($t['CoverURL']) ? $coverBaseURL . $t['CoverURL'] : '';
              $status        = $t['StatusPeminjaman'];
              $jatuhTempo    = strtotime($t['TanggalPengembalian']);
              $today         = strtotime('today');
              $hariTelat     = max(0, (int)$t['HariTelat']);
              $sisaHari      = (int)ceil(($jatuhTempo - $today) / 86400);
              $isTelat       = ($status === 'terlambat') || ($status === 'dipinjam' && $jatuhTempo < $today);
              $estimDenda    = $isTelat ? ($hariTelat * (float)($t['DendaPerHari'] ?? 0)) : 0;
              $totalDenda    = (float)$t['TotalDenda'];
              $statusBayar   = $t['StatusBayarDenda'];
              $adaDenda      = $totalDenda > 0;
              $kembaliAktual = $t['TanggalKembaliAktual'];
          ?>
          <tr class="<?= $isTelat && $status !== 'dikembalikan' ? 'row-telat' : '' ?>">
            <td><?= $no++ ?></td>

            <!-- Cover -->
            <td>
              <?php if ($coverUrl): ?>
                <img src="<?= htmlspecialchars($coverUrl) ?>" class="cover-thumb" alt="Cover"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="cover-placeholder" style="display:none">📚</div>
              <?php else: ?>
                <div class="cover-placeholder">📚</div>
              <?php endif; ?>
            </td>

            <td><strong><?= htmlspecialchars($t['Judul']) ?></strong></td>
            <td><?= htmlspecialchars($t['NamaLengkap']) ?></td>
            <td><?= date('d/m/Y', strtotime($t['TanggalPeminjaman'])) ?></td>

            <!-- Jatuh Tempo -->
            <td>
              <span style="color:<?= $isTelat && $status !== 'dikembalikan' ? '#ef4444' : 'inherit' ?>;font-weight:<?= $isTelat && $status !== 'dikembalikan' ? '600' : '400' ?>">
                <?= date('d/m/Y', $jatuhTempo) ?>
              </span>
              <?php if ($isTelat && $status !== 'dikembalikan'): ?>
                <span class="telat-info">Terlambat <?= $hariTelat ?> hari</span>
              <?php endif; ?>
            </td>

            <!-- Tgl Kembali Aktual -->
            <td>
              <?php if ($kembaliAktual): ?>
                <strong><?= date('d/m/Y', strtotime($kembaliAktual)) ?></strong>
              <?php else: ?>
                <span style="color:#94a3b8">—</span>
              <?php endif; ?>
            </td>

            <!-- Sisa / Telat -->
            <td>
              <?php if ($status === 'dikembalikan'): ?>
                <span style="color:#16a34a;font-size:12px;font-weight:600">✅ Kembali</span>
              <?php elseif ($hariTelat > 0): ?>
                <span class="badge badge-danger">🚨 <?= $hariTelat ?> hari telat</span>
              <?php elseif ($sisaHari === 0): ?>
                <span class="badge badge-warning">Hari ini!</span>
              <?php elseif ($sisaHari > 0): ?>
                <span class="badge badge-info"><?= $sisaHari ?> hari lagi</span>
              <?php else: ?>
                <span class="badge badge-default">—</span>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td>
              <?php
                $bMap = [
                  'dipinjam'     => '<span class="badge badge-info">Dipinjam</span>',
                  'dikembalikan' => '<span class="badge badge-success">Dikembalikan</span>',
                  'terlambat'    => '<span class="badge badge-danger">⚠️ Terlambat</span>',
                  'menunggu'     => '<span class="badge badge-warning">Menunggu</span>',
                ];
                echo $bMap[$status] ?? '<span class="badge">' . htmlspecialchars($status) . '</span>';
              ?>
            </td>

            <!-- Denda/Hari -->
            <td>
              <div class="denda-wrap">
                <span class="denda-rate">Rp <?= number_format((float)($t['DendaPerHari'] ?? 0), 0, ',', '.') ?>/hari</span>
                <?php if ($isTelat && $status !== 'dikembalikan' && $estimDenda > 0): ?>
                  <span class="denda-est">Estimasi: Rp <?= number_format($estimDenda, 0, ',', '.') ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Total Denda -->
            <td>
              <?php if ($status === 'dikembalikan' && $adaDenda): ?>
                <span class="denda-nominal">Rp <?= number_format($totalDenda, 0, ',', '.') ?></span>
              <?php elseif ($status === 'dikembalikan'): ?>
                <span class="denda-nol">Rp 0</span>
              <?php elseif ($isTelat && $estimDenda > 0): ?>
                <span style="font-size:12px;color:#d97706;font-style:italic;">~Rp <?= number_format($estimDenda, 0, ',', '.') ?></span>
              <?php else: ?>
                <span style="color:#d1d5db;font-size:12px">—</span>
              <?php endif; ?>
            </td>

            <!-- Status Bayar -->
            <td>
              <?php if ($status !== 'dikembalikan'): ?>
                <span class="badge-nodenda">— Belum kembali</span>
              <?php elseif (!$adaDenda): ?>
                <span class="badge-nodenda">— Tidak ada denda</span>
              <?php elseif ($statusBayar === 'Belum'): ?>
                <span class="badge-belum">❌ Belum Dibayar</span>
              <?php else: ?>
                <span class="badge-lunas">✅ Lunas</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
            <tr>
              <td colspan="12">
                <div class="empty-state">
                  <div class="empty-icon">📭</div>
                  <p>Tidak ada data peminjaman<?= $cariQ ? ' untuk pencarian "'.htmlspecialchars($cariQ).'"' : '' ?>.</p>
                  <?php if ($filterTab !== 'semua' || $cariQ): ?>
                    <a href="laporan.php" class="btn btn-sm btn-outline">Lihat Semua</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main -->

<?php if (!empty($chartLabels)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('chartBulan').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Jumlah Peminjaman',
            data:  <?= json_encode($chartData) ?>,
            backgroundColor: 'rgba(99,102,241,.18)',
            borderColor:     'rgba(99,102,241,1)',
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y} peminjaman` } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'DM Sans', size: 12 } }, grid: { color: '#f3f4f6' } },
            x: { ticks: { font: { family: 'DM Sans', size: 12 } }, grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>
<?php $conn->close(); ?>