<?php
// views/admin/peminjaman.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();
$msg  = '';

// ─── Cover Base URL ────────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// Cek tabel ulasanbuku
$cekUlasan = $conn->query("SHOW TABLES LIKE 'ulasanbuku'");
$hasUlasan = $cekUlasan && $cekUlasan->num_rows > 0;

// ─── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $userId = (int)$_POST['user_id'];
        $bukuId = (int)$_POST['buku_id'];
        $tgl_p  = $_POST['tgl_pinjam'];
        $tgl_k  = $_POST['tgl_kembali'];

        $stok = $conn->query("SELECT Stok FROM buku WHERE BukuID=$bukuId")->fetch_row()[0] ?? 0;
        if ($stok < 1) {
            $msg = 'danger|Stok buku habis!';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO peminjaman (UserID, BukuID, TanggalPeminjaman, TanggalPengembalian, StatusPeminjaman)
                VALUES (?, ?, ?, ?, 'dipinjam')
            ");
            $stmt->bind_param('iiss', $userId, $bukuId, $tgl_p, $tgl_k);
            if ($stmt->execute()) {
                $conn->query("UPDATE buku SET Stok = Stok - 1 WHERE BukuID = $bukuId");
                $msg = 'success|Peminjaman berhasil dicatat!';
            } else {
                $msg = 'danger|Gagal: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

    if ($action === 'kembalikan') {
        $id        = (int)$_POST['id'];
        $bukuId    = (int)$_POST['buku_id'];
        $dendaHari = (float)($_POST['denda_per_hari'] ?? 0);

        $rowP = $conn->query(
            "SELECT TanggalPengembalian FROM peminjaman WHERE PeminjamanID = $id"
        )->fetch_assoc();

        $totalDenda  = 0;
        $statusBayar = 'Lunas';
        $hariTelat   = 0;

        if ($rowP) {
            $jatuhTempo = strtotime($rowP['TanggalPengembalian']);
            $today      = strtotime('today');
            if ($jatuhTempo < $today) {
                $hariTelat   = (int) floor(($today - $jatuhTempo) / 86400);
                $totalDenda  = $hariTelat * $dendaHari;
                $statusBayar = 'Belum';
            }
        }

        $stmt = $conn->prepare("
            UPDATE peminjaman
            SET StatusPeminjaman     = 'dikembalikan',
                TanggalKembaliAktual = CURDATE(),
                TotalDenda           = ?,
                StatusBayarDenda     = ?
            WHERE PeminjamanID = ?
        ");
        $stmt->bind_param('dsi', $totalDenda, $statusBayar, $id);
        $stmt->execute();
        $stmt->close();

        $conn->query("UPDATE buku SET Stok = Stok + 1 WHERE BukuID = $bukuId");

        if ($totalDenda > 0) {
            $msg = 'warning|Buku dikembalikan. Terlambat ' . $hariTelat . ' hari. '
                 . 'Denda Rp ' . number_format($totalDenda, 0, ',', '.') . ' tercatat BELUM DIBAYAR.';
        } else {
            $msg = 'success|Buku berhasil dikembalikan. Tidak ada denda!';
        }
    }

    if ($action === 'bayar_denda') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("
            UPDATE peminjaman SET StatusBayarDenda = 'Lunas'
            WHERE PeminjamanID = ? AND StatusPeminjaman = 'dikembalikan'
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $msg = 'success|Denda berhasil ditandai LUNAS!';
    }
}

// ─── Query ─────────────────────────────────────────────────────────────────────
$tab    = $_GET['tab']  ?? 'semua';
$searchQ = trim($_GET['q'] ?? '');

$where = '1=1';
if ($tab === 'aktif')   $where = "p.StatusPeminjaman IN ('dipinjam','terlambat')";
if ($tab === 'kembali') $where = "p.StatusPeminjaman = 'dikembalikan'";
if ($tab === 'belum')   $where = "p.StatusPeminjaman = 'dikembalikan' AND p.StatusBayarDenda = 'Belum'";

if ($searchQ) {
    $esc    = $conn->real_escape_string($searchQ);
    $where .= " AND (u.NamaLengkap LIKE '%$esc%' OR b.Judul LIKE '%$esc%')";
}

$ulasanJoin   = $hasUlasan ? "LEFT JOIN ulasanbuku ul ON b.BukuID = ul.BukuID" : "";
$ulasanSelect = $hasUlasan ? ", ROUND(AVG(ul.Rating),1) AS RataRating, COUNT(DISTINCT ul.UlasanID) AS JmlUlasan" : ", NULL AS RataRating, 0 AS JmlUlasan";

$data = $conn->query("
    SELECT
        p.PeminjamanID, p.BukuID,
        p.TanggalPeminjaman, p.TanggalPengembalian,
        p.TanggalKembaliAktual,
        p.StatusPeminjaman,
        COALESCE(p.TotalDenda, 0)             AS TotalDenda,
        COALESCE(p.StatusBayarDenda, 'Lunas') AS StatusBayarDenda,
        b.Judul, b.CoverURL, b.DendaPerHari,
        u.NamaLengkap
        $ulasanSelect
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    JOIN buku b ON p.BukuID = b.BukuID
    $ulasanJoin
    WHERE $where
    GROUP BY p.PeminjamanID
    ORDER BY p.PeminjamanID DESC
");

$users = $conn->query(
    "SELECT UserID, NamaLengkap FROM user WHERE Role = 'peminjam' ORDER BY NamaLengkap"
);
$buku  = $conn->query(
    "SELECT BukuID, Judul, Stok, CoverURL, DendaPerHari FROM buku WHERE Stok > 0 ORDER BY Judul"
);

$cntAktif  = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman IN ('dipinjam','terlambat')")->fetch_row()[0] ?? 0;
$cntBelum  = $conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'")->fetch_row()[0] ?? 0;

if (!$data) die("Query error: " . $conn->error);

$msgArr  = $msg ? explode('|', $msg, 2) : array('', '');
$msgType = $msgArr[0]; $msgText = $msgArr[1] ?? '';

function statusBadgeAdm($s) {
    switch (strtolower($s)) {
        case 'dipinjam':     return '<span class="badge badge-info">Dipinjam</span>';
        case 'dikembalikan': return '<span class="badge badge-success">Dikembalikan</span>';
        case 'terlambat':    return '<span class="badge badge-danger">Terlambat</span>';
        default:             return '<span class="badge">' . htmlspecialchars($s) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manajemen Peminjaman — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb}

.tab-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.tab-btn{padding:8px 18px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#475569;transition:all .18s;display:inline-flex;align-items:center;gap:6px}
.tab-btn:hover{border-color:#6366f1;color:#6366f1}
.tab-btn.active{border-color:#6366f1;background:#ede9fe;color:#4f46e5}
.tab-btn.active-danger{border-color:#ef4444;background:#fef2f2;color:#dc2626}
.tab-badge{background:#ef4444;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700}
.tab-badge-blue{background:#6366f1;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700}

.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;display:block}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc}

#coverPreviewModal{display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-top:10px;min-height:82px}
#coverPreviewModal img{width:60px;height:82px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0}
.cover-empty-modal{width:60px;height:82px;border-radius:6px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:28px;color:#94a3b8;background:#fff}
#coverPreviewModal .cover-info{flex:1}
#coverPreviewModal .buku-title{font-weight:600;font-size:14px;color:#1e293b}
#coverPreviewModal .buku-stok{font-size:12px;color:#64748b;margin-top:4px}
#coverPreviewModal .buku-denda{font-size:12px;color:#d97706;font-weight:600;margin-top:2px}

.badge-belum{display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700}
.badge-lunas{display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700}
.badge-nodenda{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11.5px}

.btn-kembalikan{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .18s;white-space:nowrap}
.btn-kembalikan:hover{opacity:.85}
.btn-bayar{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .18s;white-space:nowrap}
.btn-bayar:hover{opacity:.85}

.denda-wrap{display:flex;flex-direction:column;gap:2px}
.denda-rate{font-size:12px;font-weight:700;color:#d97706}
.denda-est {font-size:11px;color:#ef4444}
.denda-nominal{font-size:13px;font-weight:700;color:#dc2626}
.denda-nol    {font-size:13px;color:#94a3b8}
.telat-info{font-size:10px;color:#ef4444;display:block;margin-top:2px}

.search-form{display:flex;gap:8px;align-items:center}
.search-form input{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px;font-family:'DM Sans',sans-serif;font-size:13px;min-width:220px}
.search-form input:focus{outline:none;border-color:#6366f1}

/* Rating */
.rating-wrap{display:flex;flex-direction:column;gap:1px;}
.rating-stars-sm{font-size:11px;color:#f59e0b;}
.rating-val-sm{font-size:11px;font-weight:700;color:#d97706;}
.rating-cnt-sm{font-size:10px;color:#9ca3af;}
.btn-ulasan-sm{display:inline-flex;align-items:center;gap:3px;padding:3px 7px;font-size:10px;font-weight:600;border-radius:5px;border:1.5px solid #7c3aed;color:#7c3aed;background:#fff;cursor:pointer;transition:all .18s;font-family:'DM Sans',sans-serif;margin-top:2px;}
.btn-ulasan-sm:hover{background:#7c3aed;color:#fff;}

/* Modal Ulasan inline */
.modal-ulasan{display:none;position:fixed;inset:0;background:rgba(10,10,20,.58);backdrop-filter:blur(7px);z-index:9999;align-items:center;justify-content:center;padding:16px;}
.modal-ulasan.active{display:flex;}
.mu-box{background:#fff;border-radius:20px;width:100%;max-width:580px;max-height:88vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);}
.mu-header{background:linear-gradient(135deg,#1e1e2f,#4a4a7a);padding:18px 22px;border-radius:20px 20px 0 0;color:#fff;position:relative;}
.mu-header h3{font-family:'Playfair Display',serif;font-size:17px;margin:0 0 3px;}
.mu-header p{font-size:12px;color:rgba(255,255,255,.6);margin:0;}
.mu-close{position:absolute;top:13px;right:13px;background:rgba(255,255,255,.15);border:none;color:#fff;width:27px;height:27px;border-radius:50%;cursor:pointer;font-size:16px;line-height:27px;text-align:center;}
.mu-close:hover{background:rgba(255,255,255,.3);}
.mu-body{padding:18px 20px 22px;}
.mu-stat{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #fcd34d;border-radius:11px;padding:13px 15px;margin-bottom:16px;}
.mu-stat-big{font-size:32px;font-weight:700;font-family:'Playfair Display',serif;color:#d97706;line-height:1;}
.mu-stat-stars{font-size:18px;color:#f59e0b;margin-bottom:2px;}
.mu-stat-sub{font-size:12px;color:#92400e;}
.bar-stars{display:flex;align-items:center;gap:8px;margin-bottom:5px;}
.bar-stars .label{font-size:11px;color:#6b7280;width:35px;text-align:right;}
.bar-track{flex:1;height:7px;background:#f3f4f6;border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:4px;}
.bar-cnt{font-size:11px;color:#9ca3af;width:22px;}
.review-card-adm{background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;padding:12px 14px;margin-bottom:8px;}
.review-card-adm:last-child{margin-bottom:0;}
.rca-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
.rca-user{display:flex;align-items:center;gap:7px;}
.rca-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#1e1e2f,#4a4a7a);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
.rca-name{font-size:12.5px;font-weight:600;color:#1e1e2f;}
.rca-date{font-size:10.5px;color:#9ca3af;}
.rca-stars{font-size:12px;color:#f59e0b;}
.rca-text{font-size:12.5px;color:#374151;line-height:1.5;}
.mu-empty{text-align:center;padding:28px;color:#9ca3af;}
.mu-empty .ei{font-size:34px;margin-bottom:8px;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Manajemen Peminjaman</h1>
    </div>
    <div class="topbar-right">
      <button class="btn btn-primary" onclick="openModal('modalTambah')">+ Catat Peminjaman</button>
    </div>
  </div>

  <div class="page-content">

    <?php if ($msgText): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType === 'success' ? '✅' : ($msgType === 'warning' ? '⚠️' : '❌') ?>
        <?= htmlspecialchars($msgText) ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header" style="flex-direction:column;align-items:flex-start;gap:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;width:100%">
          <h3>🔄 Data Peminjaman</h3>
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="text" name="q" placeholder="Cari nama / judul buku..."
                   value="<?= htmlspecialchars($searchQ) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Cari</button>
            <?php if ($searchQ): ?>
              <a href="peminjaman.php?tab=<?= $tab ?>" class="btn btn-sm btn-outline">✕</a>
            <?php endif; ?>
          </form>
        </div>
        <div class="tab-nav">
          <a href="peminjaman.php?tab=semua<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'semua' ? 'active' : '' ?>">📋 Semua</a>
          <a href="peminjaman.php?tab=aktif<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'aktif' ? 'active' : '' ?>">
            🔄 Aktif <span class="tab-badge-blue"><?= $cntAktif ?></span>
          </a>
          <a href="peminjaman.php?tab=kembali<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'kembali' ? 'active' : '' ?>">↩️ Dikembalikan</a>
          <a href="peminjaman.php?tab=belum<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'belum' ? 'active-danger' : '' ?>">
            ⚠️ Denda Belum Bayar <span class="tab-badge"><?= $cntBelum ?></span>
          </a>
        </div>
      </div>

      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cover</th>
              <th>Buku</th>
              <th>Peminjam</th>
              <th>Tgl Pinjam</th>
              <th>Tgl Kembali</th>
              <th>Tgl Kembali Aktual</th>
              <th>Status</th>
              <th>Denda/Hari</th>
              <th>Total Denda</th>
              <th>Status Bayar</th>
              <th>Rating Buku</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($data && $data->num_rows > 0):
                  $no = 1;
                  while ($d = $data->fetch_assoc()):
                    $jatuhTempo    = strtotime($d['TanggalPengembalian']);
                    $today         = strtotime('today');
                    $terlambat     = ($d['StatusPeminjaman'] === 'dipinjam') && ($jatuhTempo < $today);
                    $hariTelat     = $terlambat ? (int) floor(($today - $jatuhTempo) / 86400) : 0;
                    $estimDenda    = $hariTelat * (float)($d['DendaPerHari'] ?? 0);
                    $kembaliAktual = $d['TanggalKembaliAktual'];
                    $adaDenda      = (float)$d['TotalDenda'] > 0;
                    $statusBayar   = $d['StatusBayarDenda'];
                    $rataRating    = $d['RataRating'] ? round((float)$d['RataRating'], 1) : 0;
                    $jmlUlasan     = (int)($d['JmlUlasan'] ?? 0);
            ?>
            <tr>
              <td><?= $no++ ?></td>
              <td>
                <?php if (!empty($d['CoverURL'])): ?>
                  <img src="<?= $coverBaseURL . htmlspecialchars($d['CoverURL']) ?>"
                       class="cover-thumb" alt="Cover"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="cover-placeholder" style="display:none">📚</div>
                <?php else: ?>
                  <div class="cover-placeholder">📚</div>
                <?php endif; ?>
              </td>
              <td><strong><?= htmlspecialchars($d['Judul']) ?></strong></td>
              <td><?= htmlspecialchars($d['NamaLengkap']) ?></td>
              <td><?= date('d/m/Y', strtotime($d['TanggalPeminjaman'])) ?></td>
              <td>
                <span style="color:<?= $terlambat ? '#ef4444' : 'inherit' ?>;font-weight:<?= $terlambat ? '600' : '400' ?>">
                  <?= date('d/m/Y', $jatuhTempo) ?>
                </span>
                <?php if ($terlambat): ?>
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
              <td><?= statusBadgeAdm($terlambat ? 'terlambat' : $d['StatusPeminjaman']) ?></td>
              <td>
                <div class="denda-wrap">
                  <span class="denda-rate">Rp <?= number_format((float)($d['DendaPerHari'] ?? 0), 0, ',', '.') ?>/hari</span>
                  <?php if ($terlambat && $estimDenda > 0): ?>
                    <span class="denda-est">~Rp <?= number_format($estimDenda, 0, ',', '.') ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($adaDenda): ?>
                  <span class="denda-nominal">Rp <?= number_format((float)$d['TotalDenda'], 0, ',', '.') ?></span>
                <?php else: ?>
                  <span class="denda-nol">Rp 0</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($d['StatusPeminjaman'] !== 'dikembalikan'): ?>
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
                    <button class="btn-ulasan-sm" onclick="bukaModalUlasan(<?= $d['BukuID'] ?>, '<?= addslashes(htmlspecialchars($d['Judul'])) ?>')">💬 Lihat</button>
                  </div>
                <?php else: ?>
                  <span style="color:#d1d5db;font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap">
                <?php if (in_array(strtolower($d['StatusPeminjaman']), array('dipinjam','terlambat'))): ?>
                  <form method="POST" onsubmit="return konfirmasiKembali('<?= addslashes(htmlspecialchars($d['Judul'])) ?>','<?= addslashes(htmlspecialchars($d['NamaLengkap'])) ?>',<?= (int)$terlambat ?>,<?= $hariTelat ?>,<?= $estimDenda ?>)">
                    <input type="hidden" name="action"         value="kembalikan">
                    <input type="hidden" name="id"             value="<?= $d['PeminjamanID'] ?>">
                    <input type="hidden" name="buku_id"        value="<?= $d['BukuID'] ?>">
                    <input type="hidden" name="denda_per_hari" value="<?= (float)($d['DendaPerHari'] ?? 0) ?>">
                    <button type="submit" class="btn-kembalikan">↩️ Kembalikan</button>
                  </form>
                <?php elseif ($adaDenda && $statusBayar === 'Belum'): ?>
                  <form method="POST" onsubmit="return confirm('Tandai denda Rp <?= number_format((float)$d['TotalDenda'], 0, ',', '.') ?> milik <?= addslashes(htmlspecialchars($d['NamaLengkap'])) ?> sebagai LUNAS?')">
                    <input type="hidden" name="action" value="bayar_denda">
                    <input type="hidden" name="id"     value="<?= $d['PeminjamanID'] ?>">
                    <button type="submit" class="btn-bayar">💰 Tandai Lunas</button>
                  </form>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:12px">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile;
            else: ?>
            <tr>
              <td colspan="13">
                <div class="empty-state">
                  <div class="empty-icon">📭</div>
                  <p>Tidak ada data peminjaman.</p>
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

<!-- ══ MODAL ULASAN ══ -->
<div class="modal-ulasan" id="modalUlasanAdm">
  <div class="mu-box">
    <div class="mu-header">
      <button class="mu-close" onclick="tutupModalUlasan()">×</button>
      <h3 id="muAdmJudul">—</h3>
      <p>⭐ Ulasan & Rating dari Pembaca</p>
    </div>
    <div class="mu-body" id="muAdmBody">
      <div class="mu-empty"><div class="ei">💬</div><p>Memuat...</p></div>
    </div>
  </div>
</div>

<!-- ══ MODAL CATAT PEMINJAMAN BARU ══ -->
<div class="modal-overlay" id="modalTambah">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3>🔄 Catat Peminjaman Baru</h3>
      <button class="modal-close" onclick="closeModal('modalTambah')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="tambah">
      <div class="modal-body">
        <div class="form-group">
          <label>Peminjam *</label>
          <select name="user_id" required>
            <option value="">-- Pilih Anggota --</option>
            <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
              <option value="<?= $u['UserID'] ?>"><?= htmlspecialchars($u['NamaLengkap']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Buku *</label>
          <select name="buku_id" id="selectBuku" required onchange="updateCoverPreview(this)">
            <option value="">-- Pilih Buku (stok tersedia) --</option>
            <?php $buku->data_seek(0); while ($b = $buku->fetch_assoc()): ?>
              <option value="<?= $b['BukuID'] ?>"
                      data-cover="<?= htmlspecialchars($b['CoverURL'] ?? '') ?>"
                      data-stok="<?= $b['Stok'] ?>"
                      data-judul="<?= htmlspecialchars($b['Judul']) ?>"
                      data-denda="<?= number_format((float)($b['DendaPerHari'] ?? 0), 0, ',', '.') ?>">
                <?= htmlspecialchars($b['Judul']) ?> (Stok: <?= $b['Stok'] ?>)
              </option>
            <?php endwhile; ?>
          </select>
          <div id="coverPreviewModal">
            <div class="cover-empty-modal">📚</div>
            <div class="cover-info">
              <div class="buku-title" id="previewTitle">Pilih buku untuk melihat preview</div>
              <div class="buku-stok"  id="previewStok"></div>
              <div class="buku-denda" id="previewDenda"></div>
            </div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Tanggal Pinjam *</label>
            <input type="date" name="tgl_pinjam" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Tanggal Kembali *</label>
            <input type="date" name="tgl_kembali" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalTambah')">Batal</button>
        <button type="submit" class="btn btn-primary">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php
// Ambil semua ulasan per buku untuk JS
$ulasanMap = array();
if ($hasUlasan) {
    $allUlasan = $conn->query("
        SELECT ul.BukuID, ul.Rating, ul.Ulasan, ul.CreatedAt, us.NamaLengkap
        FROM ulasanbuku ul
        JOIN user us ON ul.UserID = us.UserID
        ORDER BY ul.CreatedAt DESC
    ");
    if ($allUlasan) {
        while ($au = $allUlasan->fetch_assoc()) {
            $ulasanMap[$au['BukuID']][] = $au;
        }
    }
}
// Rating per buku
$ratingMap = array();
if ($hasUlasan) {
    $rq = $conn->query("SELECT BukuID, ROUND(AVG(Rating),1) AS rata, COUNT(*) AS jml FROM ulasanbuku GROUP BY BukuID");
    if ($rq) while ($rr = $rq->fetch_assoc()) {
        $ratingMap[$rr['BukuID']] = array('rata' => $rr['rata'], 'jml' => $rr['jml']);
    }
}
$conn->close();
?>

<script>
const COVER_BASE   = '<?= $coverBaseURL ?>';
const ULASAN_MAP   = <?= json_encode($ulasanMap, JSON_UNESCAPED_UNICODE) ?>;
const RATING_MAP   = <?= json_encode($ratingMap, JSON_UNESCAPED_UNICODE) ?>;

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

function updateCoverPreview(sel) {
    var opt   = sel.options[sel.selectedIndex];
    var cover = opt.getAttribute('data-cover') || '';
    var judul = opt.getAttribute('data-judul') || 'Pilih buku untuk melihat preview';
    var stok  = opt.getAttribute('data-stok')  || '';
    var denda = opt.getAttribute('data-denda') || '';
    var box   = document.getElementById('coverPreviewModal');
    document.getElementById('previewTitle').textContent = judul;
    document.getElementById('previewStok').textContent  = stok  ? 'Stok tersedia: ' + stok + ' buku' : '';
    document.getElementById('previewDenda').textContent = denda ? 'Denda/hari: Rp ' + denda : '';
    var oldImg   = box.querySelector('img');
    var oldEmpty = box.querySelector('.cover-empty-modal');
    if (oldImg)   oldImg.remove();
    if (oldEmpty) oldEmpty.remove();
    if (cover) {
        var img   = document.createElement('img');
        img.src   = COVER_BASE + cover;
        img.onerror = function() {
            this.remove();
            var e = document.createElement('div');
            e.className = 'cover-empty-modal'; e.textContent = '📚';
            box.prepend(e);
        };
        box.prepend(img);
    } else {
        var e = document.createElement('div');
        e.className = 'cover-empty-modal'; e.textContent = '📚';
        box.prepend(e);
    }
}

function konfirmasiKembali(judul, nama, terlambat, hariTelat, estimDenda) {
    if (terlambat) {
        var fmt = 'Rp ' + Number(estimDenda).toLocaleString('id-ID');
        return confirm(
            '⚠️ PERHATIAN — Pengembalian Terlambat!\n\n' +
            'Buku     : ' + judul + '\n' +
            'Peminjam : ' + nama  + '\n' +
            'Terlambat: ' + hariTelat + ' hari\n' +
            'Denda    : ' + fmt   + '\n\n' +
            'Denda akan tercatat BELUM DIBAYAR.\nLanjutkan?'
        );
    }
    return confirm('Konfirmasi pengembalian buku "' + judul + '" oleh ' + nama + '?');
}

// ── Modal Ulasan ──
function bukaModalUlasan(bukuId, judul) {
    var modal = document.getElementById('modalUlasanAdm');
    var body  = document.getElementById('muAdmBody');
    document.getElementById('muAdmJudul').textContent = judul;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    var ulasan  = ULASAN_MAP[bukuId] || [];
    var rInfo   = RATING_MAP[bukuId] || {rata: 0, jml: 0};

    if (!ulasan.length) {
        body.innerHTML = '<div class="mu-empty"><div class="ei">💬</div><p>Buku ini belum mendapat ulasan dari pembaca.</p></div>';
        return;
    }

    var dist = {1:0,2:0,3:0,4:0,5:0};
    ulasan.forEach(function(u) { dist[u.Rating] = (dist[u.Rating]||0)+1; });
    var maxD = Math.max.apply(null,[dist[1],dist[2],dist[3],dist[4],dist[5]]) || 1;

    var starsHtml = '';
    for(var s=1;s<=5;s++) starsHtml += s<=Math.round(rInfo.rata)?'⭐':'☆';

    var barHtml = '';
    for(var b=5;b>=1;b--){
        var pct = Math.round((dist[b]||0)/rInfo.jml*100);
        barHtml += '<div class="bar-stars"><div class="label">'+b+' ★</div>'+
            '<div class="bar-track"><div class="bar-fill" style="width:'+pct+'%"></div></div>'+
            '<div class="bar-cnt">'+(dist[b]||0)+'</div></div>';
    }

    var html = '<div class="mu-stat">' +
        '<div><div class="mu-stat-big">'+(rInfo.rata||'—')+'</div>'+
        '<div class="mu-stat-stars">'+starsHtml+'</div>'+
        '<div class="mu-stat-sub">'+rInfo.jml+' ulasan</div></div>'+
        '<div style="flex:1">'+barHtml+'</div></div>' +
        '<div style="font-size:13px;font-weight:700;color:#1e1e2f;margin-bottom:10px;">💬 Detail Ulasan</div>';

    ulasan.forEach(function(u) {
        var init = (u.NamaLengkap||'?').charAt(0).toUpperCase();
        var rSt  = '';
        for(var s=1;s<=5;s++) rSt += s<=u.Rating?'⭐':'☆';
        var tgl  = u.CreatedAt ? u.CreatedAt.substring(0,10).split('-').reverse().join('/') : '—';
        html += '<div class="review-card-adm">' +
            '<div class="rca-head">' +
              '<div class="rca-user"><div class="rca-avatar">'+init+'</div>' +
              '<div><div class="rca-name">'+escHtml(u.NamaLengkap)+'</div>' +
              '<div class="rca-date">'+tgl+'</div></div></div>' +
              '<div class="rca-stars">'+rSt+'</div>' +
            '</div>' +
            '<div class="rca-text">'+escHtml(u.Ulasan)+'</div>' +
        '</div>';
    });

    body.innerHTML = html;
}
function tutupModalUlasan() {
    document.getElementById('modalUlasanAdm').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalUlasanAdm').addEventListener('click', function(e) {
    if (e.target === this) tutupModalUlasan();
});
function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}
</script>
</body>
</html>