<?php
// views/admin/laporan.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

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

// Cek tabel ulasanbuku
$cekUlasan = $conn->query("SHOW TABLES LIKE 'ulasanbuku'");
$hasUlasan = $cekUlasan && $cekUlasan->num_rows > 0;

// ─── Statistik Utama ──────────────────────────────────────────────────────────
$totalPinjam  = (int)($conn->query("SELECT COUNT(*) FROM peminjaman")->fetch_row()[0] ?? 0);
$dikembalikan = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan'")->fetch_row()[0] ?? 0);
$terlambat    = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='terlambat'")->fetch_row()[0] ?? 0);
$dipinjam     = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dipinjam'")->fetch_row()[0] ?? 0);
$totalAnggota = (int)($conn->query("SELECT COUNT(*) FROM user WHERE Role='peminjam'")->fetch_row()[0] ?? 0);
$totalBuku    = (int)($conn->query("SELECT COUNT(*) FROM buku")->fetch_row()[0] ?? 0);

$totalDendaLunas = (int)($conn->query(
    "SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Lunas'"
)->fetch_row()[0] ?? 0);

$totalDendaBelum = (int)($conn->query(
    "SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'"
)->fetch_row()[0] ?? 0);

$cntDendaBelum = (int)($conn->query(
    "SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'"
)->fetch_row()[0] ?? 0);

$dendaSelect = $hasDendaCol
    ? "DATEDIFF(CURDATE(), p.TanggalPengembalian) * COALESCE(b.DendaPerHari, " . DENDA_DEFAULT . ")"
    : "DATEDIFF(CURDATE(), p.TanggalPengembalian) * " . DENDA_DEFAULT;

$totalDendaAktifQ = $conn->query("
    SELECT COALESCE(SUM(
        CASE WHEN p.StatusPeminjaman IN ('dipinjam','terlambat') AND p.TanggalPengembalian < CURDATE()
             THEN $dendaSelect
             ELSE 0
        END
    ), 0) AS total
    FROM peminjaman p
    JOIN buku b ON p.BukuID = b.BukuID
");
$totalDendaAktif = (int)($totalDendaAktifQ ? $totalDendaAktifQ->fetch_row()[0] : 0);

// Total ulasan
$totalUlasan = 0;
if ($hasUlasan) {
    $totalUlasan = (int)($conn->query("SELECT COUNT(*) FROM ulasanbuku")->fetch_row()[0] ?? 0);
}

// ─── Auto-update terlambat ────────────────────────────────────────────────────
$conn->query("
    UPDATE peminjaman
    SET StatusPeminjaman = 'terlambat'
    WHERE StatusPeminjaman = 'dipinjam'
      AND TanggalPengembalian < CURDATE()
");

// ─── Filter tabel daftar peminjam ────────────────────────────────────────────
$filterTab = $_GET['filter'] ?? 'semua';
$cariQ     = trim($_GET['q'] ?? '');

$whereFilter = "WHERE 1=1";
if ($filterTab === 'dipinjam')     $whereFilter .= " AND p.StatusPeminjaman = 'dipinjam'";
elseif ($filterTab === 'terlambat')    $whereFilter .= " AND p.StatusPeminjaman = 'terlambat'";
elseif ($filterTab === 'dikembalikan') $whereFilter .= " AND p.StatusPeminjaman = 'dikembalikan'";
elseif ($filterTab === 'belum')        $whereFilter .= " AND p.StatusPeminjaman = 'dikembalikan' AND p.StatusBayarDenda = 'Belum'";
if ($cariQ) {
    $cEsc = $conn->real_escape_string($cariQ);
    $whereFilter .= " AND (b.Judul LIKE '%$cEsc%' OR u.NamaLengkap LIKE '%$cEsc%')";
}

$ulasanJoin    = $hasUlasan ? "LEFT JOIN ulasanbuku ul ON b.BukuID = ul.BukuID" : "";
$ulasanSelect  = $hasUlasan ? ", ROUND(AVG(ul.Rating),1) AS RataRating, COUNT(DISTINCT ul.UlasanID) AS JmlUlasan" : ", NULL AS RataRating, 0 AS JmlUlasan";

$daftarPeminjam = $conn->query("
    SELECT
        p.PeminjamanID,
        p.BukuID,
        p.TanggalPeminjaman,
        p.TanggalPengembalian,
        p.TanggalKembaliAktual,
        p.StatusPeminjaman,
        COALESCE(p.TotalDenda, 0)             AS TotalDenda,
        COALESCE(p.StatusBayarDenda, 'Lunas') AS StatusBayarDenda,
        b.Judul,
        b.CoverURL,
        b.DendaPerHari,
        u.NamaLengkap,
        DATEDIFF(CURDATE(), p.TanggalPengembalian) AS HariTelat
        $ulasanSelect
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    JOIN buku b ON p.BukuID = b.BukuID
    $ulasanJoin
    $whereFilter
    GROUP BY p.PeminjamanID
    ORDER BY
        CASE p.StatusPeminjaman
            WHEN 'terlambat'    THEN 1
            WHEN 'dipinjam'     THEN 2
            WHEN 'menunggu'     THEN 3
            ELSE 4
        END,
        p.TanggalPengembalian ASC
");

// Tab counts
$tabCounts = array();
$tabQ = $conn->query("SELECT StatusPeminjaman, COUNT(*) AS n FROM peminjaman GROUP BY StatusPeminjaman");
$tabCounts['semua']        = $totalPinjam;
$tabCounts['dipinjam']     = 0;
$tabCounts['terlambat']    = 0;
$tabCounts['dikembalikan'] = 0;
$tabCounts['belum']        = $cntDendaBelum;
if ($tabQ) while ($tc = $tabQ->fetch_assoc()) {
    $s = $tc['StatusPeminjaman'];
    if (isset($tabCounts[$s])) $tabCounts[$s] = (int)$tc['n'];
}

// ─── Buku Paling Populer (dengan rating) ──────────────────────────────────────
$bukuPopulerQ = "
    SELECT b.BukuID, b.Judul, b.Penulis, b.CoverURL, COUNT(p.PeminjamanID) AS total
    " . ($hasUlasan ? ", ROUND(AVG(ul.Rating),1) AS RataRating, COUNT(DISTINCT ul.UlasanID) AS JmlUlasan" : ", NULL AS RataRating, 0 AS JmlUlasan") . "
    FROM peminjaman p
    JOIN buku b ON p.BukuID = b.BukuID
    " . ($hasUlasan ? "LEFT JOIN ulasanbuku ul ON b.BukuID = ul.BukuID" : "") . "
    GROUP BY b.BukuID
    ORDER BY total DESC
    LIMIT 5
";
$bukuPopuler = $conn->query($bukuPopulerQ);

// ─── Peminjaman Per Bulan ─────────────────────────────────────────────────────
$perBulan = $conn->query("
    SELECT DATE_FORMAT(TanggalPeminjaman, '%Y-%m') AS bulan,
           DATE_FORMAT(TanggalPeminjaman, '%b %Y')  AS label,
           COUNT(*) AS total
    FROM peminjaman
    WHERE TanggalPeminjaman >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY bulan, label
    ORDER BY bulan ASC
");
$chartLabels = array();
$chartData   = array();
if ($perBulan) {
    while ($r = $perBulan->fetch_assoc()) {
        $chartLabels[] = $r['label'];
        $chartData[]   = (int)$r['total'];
    }
}

// ─── Kategori Terpopuler ──────────────────────────────────────────────────────
$kategoriPopuler = $conn->query("
    SELECT k.NamaKategori, COUNT(p.PeminjamanID) AS total
    FROM peminjaman p
    JOIN buku b ON p.BukuID = b.BukuID
    JOIN kategoribuku k ON b.KategoriID = k.KategoriID
    GROUP BY k.KategoriID
    ORDER BY total DESC
    LIMIT 5
");

// ─── Anggota Paling Aktif ─────────────────────────────────────────────────────
$anggotaAktif = $conn->query("
    SELECT u.NamaLengkap, COUNT(p.PeminjamanID) AS total,
           SUM(CASE WHEN p.StatusPeminjaman='terlambat' THEN 1 ELSE 0 END) AS telat
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    GROUP BY p.UserID
    ORDER BY total DESC
    LIMIT 5
");

// ─── Ulasan Terbaru (jika ada) ────────────────────────────────────────────────
$ulasanTerbaru = null;
if ($hasUlasan) {
    $ulasanTerbaru = $conn->query("
        SELECT ul.UlasanID, ul.Rating, ul.Ulasan, ul.CreatedAt,
               b.Judul, b.CoverURL,
               us.NamaLengkap
        FROM ulasanbuku ul
        JOIN buku b ON ul.BukuID = b.BukuID
        JOIN user us ON ul.UserID = us.UserID
        ORDER BY ul.CreatedAt DESC
        LIMIT 6
    ");
}

// ─── Buku dengan rating tertinggi ─────────────────────────────────────────────
$bukuRatingTerbaik = null;
if ($hasUlasan) {
    $bukuRatingTerbaik = $conn->query("
        SELECT b.BukuID, b.Judul, b.Penulis, b.CoverURL,
               ROUND(AVG(ul.Rating),1) AS RataRating,
               COUNT(ul.UlasanID) AS JmlUlasan
        FROM buku b
        JOIN ulasanbuku ul ON b.BukuID = ul.BukuID
        GROUP BY b.BukuID
        HAVING JmlUlasan >= 1
        ORDER BY RataRating DESC, JmlUlasan DESC
        LIMIT 5
    ");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laporan — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
.section-title{font-family:'Playfair Display',serif;font-size:18px;color:#1e1e2f;margin:28px 0 14px;display:flex;align-items:center;gap:10px;}
.section-title::after{content:'';flex:1;height:2px;background:linear-gradient(90deg,#e5e7eb,transparent);}

.stat-grid-6{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:8px;}
.stat-card-v2{background:#fff;border-radius:14px;border:1px solid var(--border);padding:18px 20px;display:flex;flex-direction:column;gap:6px;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:transform .2s,box-shadow .2s;}
.stat-card-v2:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.sc-icon{font-size:28px;}
.sc-val{font-size:26px;font-weight:700;font-family:'Playfair Display',serif;color:#1e1e2f;line-height:1;}
.sc-lbl{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;}
.sc-sub{font-size:12px;color:#9ca3af;}
.stat-card-v2.red   {border-color:#fca5a5;background:linear-gradient(135deg,#fff,#fef2f2);} .stat-card-v2.red .sc-val{color:#dc2626;}
.stat-card-v2.orange{border-color:#fed7aa;background:linear-gradient(135deg,#fff,#fff7ed);} .stat-card-v2.orange .sc-val{color:#ea580c;}
.stat-card-v2.green {border-color:#bbf7d0;background:linear-gradient(135deg,#fff,#f0fdf4);} .stat-card-v2.green .sc-val{color:#16a34a;}
.stat-card-v2.blue  {border-color:#bfdbfe;background:linear-gradient(135deg,#fff,#eff6ff);} .stat-card-v2.blue .sc-val{color:#2563eb;}
.stat-card-v2.purple{border-color:#ddd6fe;background:linear-gradient(135deg,#fff,#f5f3ff);} .stat-card-v2.purple .sc-val{color:#7c3aed;}
.stat-card-v2.gold  {border-color:#fde68a;background:linear-gradient(135deg,#fff,#fffbeb);} .stat-card-v2.gold .sc-val{color:#d97706;}
.stat-card-v2.pink  {border-color:#fbcfe8;background:linear-gradient(135deg,#fff,#fdf2f8);} .stat-card-v2.pink .sc-val{color:#db2777;}

.terlambat-banner{background:linear-gradient(135deg,#fef2f2,#fee2e2);border:2px solid #fca5a5;border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.tb-icon{font-size:30px;flex-shrink:0;}
.tb-body h4{font-family:'Playfair Display',serif;font-size:15px;color:#7f1d1d;margin:0 0 2px;}
.tb-body p {font-size:13px;color:#991b1b;margin:0;}
.tb-total{margin-left:auto;text-align:right;flex-shrink:0;}
.tb-total .lbl{font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.5px;}
.tb-total .amt{font-size:20px;font-weight:700;color:#dc2626;font-family:'Playfair Display',serif;}

.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;display:block;}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc;}

.denda-wrap{display:flex;flex-direction:column;gap:2px;}
.denda-rate{font-size:12px;font-weight:700;color:#d97706;}
.denda-est {font-size:11px;color:#ef4444;}
.denda-nominal{font-size:13px;font-weight:700;color:#dc2626;}
.denda-nol    {font-size:13px;color:#94a3b8;}
.telat-info{font-size:10px;color:#ef4444;display:block;margin-top:2px;}

.badge-belum{display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-lunas{display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-nodenda{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11.5px;}

/* Rating col di tabel */
.rating-wrap{display:flex;flex-direction:column;gap:1px;}
.rating-stars-sm{font-size:11px;color:#f59e0b;}
.rating-val-sm{font-size:11px;font-weight:700;color:#d97706;}
.rating-cnt-sm{font-size:10px;color:#9ca3af;}

.chart-wrap{background:#fff;border-radius:14px;border:1px solid var(--border);padding:20px 22px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.chart-wrap h4{font-family:'Playfair Display',serif;font-size:16px;color:#1e1e2f;margin:0 0 16px;}
.chart-canvas{width:100%;height:220px;}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
@media(max-width:800px){.two-col{grid-template-columns:1fr;}}

.populer-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.populer-item:last-child{border-bottom:none;}
.pop-rank{font-size:20px;width:32px;text-align:center;flex-shrink:0;}
.pop-cover{width:36px;height:50px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;flex-shrink:0;}
.pop-cover-empty{width:36px;height:50px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:16px;color:#94a3b8;flex-shrink:0;}
.pop-info{flex:1;}
.pop-info h5{font-size:13px;font-weight:600;color:#1e1e2f;margin:0 0 2px;line-height:1.3;}
.pop-info p{font-size:11px;color:#6b7280;margin:0;}
.pop-info .pop-rating{font-size:11px;color:#f59e0b;margin-top:2px;}
.pop-count{font-size:13px;font-weight:700;color:#7c3aed;background:#f5f3ff;border-radius:6px;padding:3px 8px;flex-shrink:0;}

.kat-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.kat-bar-label{font-size:12px;font-weight:600;color:#374151;width:110px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.kat-bar-track{flex:1;height:10px;background:#f3f4f6;border-radius:6px;overflow:hidden;}
.kat-bar-fill{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:6px;transition:width .6s ease;}
.kat-bar-val{font-size:11px;font-weight:700;color:#6366f1;width:30px;text-align:right;flex-shrink:0;}

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

tr.row-telat{background:linear-gradient(90deg,#fef2f2,#fff)!important;}

.anggota-item{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid #f3f4f6;}
.anggota-item:last-child{border-bottom:none;}
.anggota-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1e1e2f,#4a4a7a);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.anggota-name{flex:1;font-size:13px;font-weight:600;color:#1e1e2f;}
.anggota-stats{display:flex;gap:6px;flex-shrink:0;}

/* Ulasan terbaru */
.ulasan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;}
.ulasan-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:13px 15px;}
.uc-head{display:flex;align-items:center;gap:9px;margin-bottom:8px;}
.uc-cover{width:36px;height:50px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;flex-shrink:0;}
.uc-cover-empty{width:36px;height:50px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:16px;color:#94a3b8;flex-shrink:0;}
.uc-info h5{font-size:12px;font-weight:700;color:#1e1e2f;margin:0 0 1px;line-height:1.3;}
.uc-info p{font-size:11px;color:#6b7280;margin:0;}
.uc-stars{font-size:12px;color:#f59e0b;margin:3px 0;}
.uc-text{font-size:12px;color:#374151;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
.uc-user{font-size:11px;color:#9ca3af;margin-top:6px;}

/* Buku rating terbaik */
.rating-top-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f3f4f6;}
.rating-top-item:last-child{border-bottom:none;}
.rta-cover{width:34px;height:47px;object-fit:cover;border-radius:4px;border:1px solid #e2e8f0;flex-shrink:0;}
.rta-cover-empty{width:34px;height:47px;border-radius:4px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:14px;color:#94a3b8;flex-shrink:0;}
.rta-info{flex:1;}
.rta-info h5{font-size:12.5px;font-weight:600;color:#1e1e2f;margin:0 0 2px;line-height:1.3;}
.rta-info p{font-size:11px;color:#6b7280;margin:0;}
.rta-score{text-align:right;flex-shrink:0;}
.rta-score .val{font-size:16px;font-weight:700;color:#f59e0b;font-family:'Playfair Display',serif;}
.rta-score .cnt{font-size:10px;color:#9ca3af;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>📊 Laporan & Statistik</h1>
    </div>
    <div class="topbar-right">
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Cetak</button>
    </div>
  </div>

  <div class="page-content">

    <!-- ══ STATISTIK UTAMA ══ -->
    <div class="section-title">📈 Ringkasan Keseluruhan</div>
    <div class="stat-grid-6">
      <div class="stat-card-v2 gold"><span class="sc-icon">📋</span><div class="sc-val"><?= $totalPinjam ?></div><div class="sc-lbl">Total Transaksi</div></div>
      <div class="stat-card-v2 blue"><span class="sc-icon">📖</span><div class="sc-val"><?= $dipinjam ?></div><div class="sc-lbl">Sedang Dipinjam</div></div>
      <div class="stat-card-v2 green"><span class="sc-icon">✅</span><div class="sc-val"><?= $dikembalikan ?></div><div class="sc-lbl">Dikembalikan</div></div>
      <div class="stat-card-v2 red"><span class="sc-icon">⚠️</span><div class="sc-val"><?= $terlambat ?></div><div class="sc-lbl">Terlambat</div></div>
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
      </div>
      <div class="stat-card-v2 purple"><span class="sc-icon">👥</span><div class="sc-val"><?= $totalAnggota ?></div><div class="sc-lbl">Total Anggota</div></div>
      <div class="stat-card-v2 blue"><span class="sc-icon">📚</span><div class="sc-val"><?= $totalBuku ?></div><div class="sc-lbl">Koleksi Buku</div></div>
      <?php if ($hasUlasan): ?>
      <div class="stat-card-v2 pink"><span class="sc-icon">⭐</span><div class="sc-val"><?= $totalUlasan ?></div><div class="sc-lbl">Total Ulasan</div><div class="sc-sub">dari seluruh pembaca</div></div>
      <?php endif; ?>
    </div>

    <!-- ══ BANNER TERLAMBAT ══ -->
    <?php if ($terlambat > 0): ?>
    <div class="terlambat-banner" style="margin-top:20px;">
      <div class="tb-icon">🚨</div>
      <div class="tb-body">
        <h4>Ada <?= $terlambat ?> Peminjam Terlambat Mengembalikan!</h4>
        <p>Segera proses pengembalian melalui menu <strong>Manajemen Peminjaman</strong>.</p>
      </div>
      <div class="tb-total">
        <div class="lbl">Estimasi Total Denda</div>
        <div class="amt">Rp <?= number_format($totalDendaAktif, 0, ',', '.') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ GRAFIK TREN ══ -->
    <?php if (!empty($chartLabels)): ?>
    <div class="section-title">📅 Tren Peminjaman (6 Bulan Terakhir)</div>
    <div class="chart-wrap">
      <canvas id="chartBulan" class="chart-canvas"></canvas>
    </div>
    <?php endif; ?>

    <!-- ══ TABEL DAFTAR PEMINJAM ══ -->
    <div class="section-title">📋 Daftar Peminjam</div>

    <form method="GET" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filterTab) ?>">
      <input type="text" name="q" placeholder="🔍 Cari nama peminjam / judul buku..."
             value="<?= htmlspecialchars($cariQ) ?>"
             style="flex:1;min-width:220px;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:13px;">
      <button type="submit" class="btn btn-outline btn-sm">Cari</button>
      <?php if ($cariQ): ?>
        <a href="laporan.php?filter=<?= $filterTab ?>" class="btn btn-sm">✕ Reset</a>
      <?php endif; ?>
    </form>

    <div class="filter-tabs">
      <?php
      $tabs = array(
          'semua'        => array('label' => 'Semua',             'icon' => '📋', 'color' => 'default'),
          'dipinjam'     => array('label' => 'Sedang Dipinjam',   'icon' => '📖', 'color' => 'blue'),
          'terlambat'    => array('label' => 'Terlambat',         'icon' => '🚨', 'color' => 'red'),
          'dikembalikan' => array('label' => 'Dikembalikan',      'icon' => '✅', 'color' => 'green'),
          'belum'        => array('label' => 'Denda Belum Bayar', 'icon' => '⚠️', 'color' => 'orange'),
      );
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
              <th>Rating Buku</th>
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
              $rataRating    = $t['RataRating'] ? round((float)$t['RataRating'], 1) : 0;
              $jmlUlasan     = (int)($t['JmlUlasan'] ?? 0);
          ?>
          <tr class="<?= $isTelat && $status !== 'dikembalikan' ? 'row-telat' : '' ?>">
            <td><?= $no++ ?></td>
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
            <td>
              <span style="color:<?= $isTelat && $status !== 'dikembalikan' ? '#ef4444' : 'inherit' ?>;font-weight:<?= $isTelat && $status !== 'dikembalikan' ? '600' : '400' ?>">
                <?= date('d/m/Y', $jatuhTempo) ?>
              </span>
              <?php if ($isTelat && $status !== 'dikembalikan'): ?>
                <span class="telat-info">Terlambat <?= $hariTelat ?> hari</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($kembaliAktual): ?>
                <strong><?= date('d/m/Y', strtotime($kembaliAktual)) ?></strong>
              <?php else: ?>
                <span style="color:#94a3b8">—</span>
              <?php endif; ?>
            </td>
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
            <td>
              <?php
                $bMap = array(
                  'dipinjam'     => '<span class="badge badge-info">Dipinjam</span>',
                  'dikembalikan' => '<span class="badge badge-success">Dikembalikan</span>',
                  'terlambat'    => '<span class="badge badge-danger">⚠️ Terlambat</span>',
                  'menunggu'     => '<span class="badge badge-warning">Menunggu</span>',
                );
                echo $bMap[$status] ?? '<span class="badge">' . htmlspecialchars($status) . '</span>';
              ?>
            </td>
            <td>
              <div class="denda-wrap">
                <span class="denda-rate">Rp <?= number_format((float)($t['DendaPerHari'] ?? 0), 0, ',', '.') ?>/hari</span>
                <?php if ($isTelat && $status !== 'dikembalikan' && $estimDenda > 0): ?>
                  <span class="denda-est">~Rp <?= number_format($estimDenda, 0, ',', '.') ?></span>
                <?php endif; ?>
              </div>
            </td>
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
            <!-- Kolom Rating Buku -->
            <td>
              <?php if ($hasUlasan && $jmlUlasan > 0): ?>
                <div class="rating-wrap">
                  <span class="rating-stars-sm"><?php for($s=1;$s<=5;$s++) echo $s<=$rataRating?'⭐':'☆'; ?></span>
                  <span class="rating-val-sm"><?= $rataRating ?>/5</span>
                  <span class="rating-cnt-sm"><?= $jmlUlasan ?> ulasan</span>
                </div>
              <?php else: ?>
                <span style="color:#d1d5db;font-size:12px">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
            <tr>
              <td colspan="13">
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

    <!-- ══ ULASAN TERBARU ══ -->
    <?php if ($hasUlasan && $ulasanTerbaru && $ulasanTerbaru->num_rows > 0): ?>
    <div class="section-title">⭐ Ulasan Terbaru dari Pembaca</div>
    <div class="ulasan-grid">
      <?php while ($ub = $ulasanTerbaru->fetch_assoc()):
        $ubCover = !empty($ub['CoverURL']) ? $coverBaseURL . $ub['CoverURL'] : '';
        $ubStars = '';
        for ($s=1;$s<=5;$s++) $ubStars .= $s<=$ub['Rating']?'⭐':'☆';
      ?>
      <div class="ulasan-card">
        <div class="uc-head">
          <?php if ($ubCover): ?>
            <img src="<?= htmlspecialchars($ubCover) ?>" class="uc-cover" alt=""
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="uc-cover-empty" style="display:none">📚</div>
          <?php else: ?>
            <div class="uc-cover-empty">📚</div>
          <?php endif; ?>
          <div class="uc-info">
            <h5><?= htmlspecialchars($ub['Judul']) ?></h5>
            <div class="uc-stars"><?= $ubStars ?></div>
          </div>
        </div>
        <div class="uc-text">"<?= htmlspecialchars($ub['Ulasan']) ?>"</div>
        <div class="uc-user">— <?= htmlspecialchars($ub['NamaLengkap']) ?> · <?= date('d M Y', strtotime($ub['CreatedAt'])) ?></div>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <!-- ══ DUA KOLOM: POPULER + RATING TERBAIK / KATEGORI ══ -->
    <div class="section-title">🏆 Statistik Buku & Kategori</div>
    <div class="two-col">

      <!-- Buku Populer -->
      <div class="chart-wrap">
        <h4>📖 Buku Paling Sering Dipinjam</h4>
        <?php if ($bukuPopuler && $bukuPopuler->num_rows > 0):
          $rank=1; while($r=$bukuPopuler->fetch_assoc()):
            $cUrl = !empty($r['CoverURL']) ? $coverBaseURL . $r['CoverURL'] : '';
            $rr   = $r['RataRating'] ? round((float)$r['RataRating'],1) : 0;
        ?>
        <div class="populer-item">
          <div class="pop-rank">
            <?php if ($rank===1) echo '🥇'; elseif ($rank===2) echo '🥈'; elseif ($rank===3) echo '🥉'; else echo "#$rank"; ?>
          </div>
          <?php if ($cUrl): ?>
            <img src="<?= htmlspecialchars($cUrl) ?>" class="pop-cover" alt=""
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="pop-cover-empty" style="display:none">📚</div>
          <?php else: ?>
            <div class="pop-cover-empty">📚</div>
          <?php endif; ?>
          <div class="pop-info">
            <h5><?= htmlspecialchars($r['Judul']) ?></h5>
            <p><?= htmlspecialchars($r['Penulis'] ?? '-') ?></p>
            <?php if ($hasUlasan && $rr > 0): ?>
              <div class="pop-rating"><?php for($s=1;$s<=5;$s++) echo $s<=$rr?'⭐':'☆'; ?> <?= $rr ?> (<?= $r['JmlUlasan'] ?> ulasan)</div>
            <?php endif; ?>
          </div>
          <div class="pop-count"><?= (int)$r['total'] ?>×</div>
        </div>
        <?php $rank++; endwhile; else: ?>
        <div style="text-align:center;padding:30px;color:#9ca3af;">Belum ada data peminjaman.</div>
        <?php endif; ?>
      </div>

      <!-- Rating Terbaik / Kategori -->
      <?php if ($hasUlasan && $bukuRatingTerbaik && $bukuRatingTerbaik->num_rows > 0): ?>
      <div class="chart-wrap">
        <h4>⭐ Buku Rating Tertinggi</h4>
        <?php $rank=1; while ($rbt = $bukuRatingTerbaik->fetch_assoc()):
          $rbtCover = !empty($rbt['CoverURL']) ? $coverBaseURL . $rbt['CoverURL'] : '';
        ?>
        <div class="rating-top-item">
          <div class="pop-rank"><?php if ($rank===1) echo '🥇'; elseif ($rank===2) echo '🥈'; elseif ($rank===3) echo '🥉'; else echo "#$rank"; $rank++; ?></div>
          <?php if ($rbtCover): ?>
            <img src="<?= htmlspecialchars($rbtCover) ?>" class="rta-cover" alt=""
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="rta-cover-empty" style="display:none">📚</div>
          <?php else: ?>
            <div class="rta-cover-empty">📚</div>
          <?php endif; ?>
          <div class="rta-info">
            <h5><?= htmlspecialchars($rbt['Judul']) ?></h5>
            <p><?= htmlspecialchars($rbt['Penulis'] ?? '-') ?></p>
          </div>
          <div class="rta-score">
            <div class="val">⭐ <?= $rbt['RataRating'] ?></div>
            <div class="cnt"><?= $rbt['JmlUlasan'] ?> ulasan</div>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php else: ?>
      <div class="chart-wrap">
        <h4>🏷️ Kategori Paling Diminati</h4>
        <?php
        $maxKat = 1; $katRows = array();
        if ($kategoriPopuler) while ($k = $kategoriPopuler->fetch_assoc()) {
            $katRows[] = $k; if ((int)$k['total'] > $maxKat) $maxKat = (int)$k['total'];
        }
        if (!empty($katRows)): foreach ($katRows as $k): ?>
        <div class="kat-bar-row">
          <div class="kat-bar-label" title="<?= htmlspecialchars($k['NamaKategori']) ?>"><?= htmlspecialchars($k['NamaKategori']) ?></div>
          <div class="kat-bar-track"><div class="kat-bar-fill" style="width:<?= round((int)$k['total'] / $maxKat * 100) ?>%"></div></div>
          <div class="kat-bar-val"><?= (int)$k['total'] ?></div>
        </div>
        <?php endforeach; else: ?>
        <div style="text-align:center;padding:30px;color:#9ca3af;">Belum ada data.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ KATEGORI (jika ada rating) ══ -->
    <?php if ($hasUlasan): ?>
    <div class="section-title">🏷️ Statistik Kategori</div>
    <div class="chart-wrap">
      <h4>Kategori Paling Diminati</h4>
      <?php
      $maxKat = 1; $katRows = array();
      if ($kategoriPopuler) {
          $kategoriPopuler->data_seek(0);
          while ($k = $kategoriPopuler->fetch_assoc()) {
              $katRows[] = $k; if ((int)$k['total'] > $maxKat) $maxKat = (int)$k['total'];
          }
      }
      if (!empty($katRows)): foreach ($katRows as $k): ?>
      <div class="kat-bar-row">
        <div class="kat-bar-label"><?= htmlspecialchars($k['NamaKategori']) ?></div>
        <div class="kat-bar-track"><div class="kat-bar-fill" style="width:<?= round((int)$k['total'] / $maxKat * 100) ?>%"></div></div>
        <div class="kat-bar-val"><?= (int)$k['total'] ?></div>
      </div>
      <?php endforeach; else: ?>
      <div style="text-align:center;padding:24px;color:#9ca3af;">Belum ada data.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ANGGOTA AKTIF ══ -->
    <div class="section-title">👥 Anggota Paling Aktif</div>
    <div class="chart-wrap">
      <h4>Top 5 Peminjam Terbanyak</h4>
      <?php if ($anggotaAktif && $anggotaAktif->num_rows > 0):
        while ($a = $anggotaAktif->fetch_assoc()):
          $initial = mb_strtoupper(mb_substr($a['NamaLengkap'], 0, 1));
      ?>
      <div class="anggota-item">
        <div class="anggota-avatar"><?= $initial ?></div>
        <div class="anggota-name"><?= htmlspecialchars($a['NamaLengkap']) ?></div>
        <div class="anggota-stats">
          <span class="badge badge-info"><?= (int)$a['total'] ?> pinjaman</span>
          <?php if ((int)$a['telat'] > 0): ?>
            <span class="badge badge-danger"><?= (int)$a['telat'] ?> terlambat</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endwhile; else: ?>
      <div style="text-align:center;padding:24px;color:#9ca3af;">Belum ada data anggota aktif.</div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if (!empty($chartLabels)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
var ctx = document.getElementById('chartBulan').getContext('2d');
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
        plugins: { legend: { display: false } },
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