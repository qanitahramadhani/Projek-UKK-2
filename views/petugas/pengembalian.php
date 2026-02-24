<?php
// views/petugas/pengembalian.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn = getConnection();
$msg  = '';

// ─── Cover Base URL ───────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// ─── Handle POST (logika identik dengan peminjaman.php admin) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── PROSES KEMBALIKAN ─────────────────────────────────────────────────────
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
                $hariTelat   = (int)floor(($today - $jatuhTempo) / 86400);
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

    // ── TANDAI DENDA LUNAS ────────────────────────────────────────────────────
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

// ─── Auto-update terlambat ────────────────────────────────────────────────────
$conn->query("
    UPDATE peminjaman
    SET StatusPeminjaman = 'terlambat'
    WHERE StatusPeminjaman = 'dipinjam'
      AND TanggalPengembalian < CURDATE()
");

// ─── Filter & Search ──────────────────────────────────────────────────────────
$tab     = $_GET['tab']  ?? 'aktif'; // aktif | semua | kembali | belum
$searchQ = trim($_GET['q'] ?? '');

if ($tab === 'aktif') {
    $where = "p.StatusPeminjaman IN ('dipinjam','terlambat')";
} elseif ($tab === 'kembali') {
    $where = "p.StatusPeminjaman = 'dikembalikan'";
} elseif ($tab === 'belum') {
    $where = "p.StatusPeminjaman = 'dikembalikan' AND p.StatusBayarDenda = 'Belum'";
} else {
    $where = "1=1";
}

if ($searchQ) {
    $esc    = $conn->real_escape_string($searchQ);
    $where .= " AND (u.NamaLengkap LIKE '%$esc%' OR b.Judul LIKE '%$esc%')";
}

// ─── Badge counts ─────────────────────────────────────────────────────────────
$cntAktif   = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman IN ('dipinjam','terlambat')")->fetch_row()[0] ?? 0);
$cntSemua   = (int)($conn->query("SELECT COUNT(*) FROM peminjaman")->fetch_row()[0] ?? 0);
$cntKembali = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan'")->fetch_row()[0] ?? 0);
$cntBelum   = (int)($conn->query("SELECT COUNT(*) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'")->fetch_row()[0] ?? 0);

// ─── Statistik ringkas ────────────────────────────────────────────────────────
$totalDendaLunas = (int)($conn->query("SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Lunas'")->fetch_row()[0] ?? 0);
$totalDendaBelum = (int)($conn->query("SELECT COALESCE(SUM(TotalDenda),0) FROM peminjaman WHERE StatusPeminjaman='dikembalikan' AND StatusBayarDenda='Belum'")->fetch_row()[0] ?? 0);

// ─── Query utama (identik struktur dengan peminjaman.php admin) ───────────────
$data = $conn->query("
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
        u.NamaLengkap
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    JOIN buku b ON p.BukuID = b.BukuID
    WHERE $where
    ORDER BY
        CASE p.StatusPeminjaman
            WHEN 'terlambat'    THEN 1
            WHEN 'dipinjam'     THEN 2
            ELSE 3
        END,
        p.TanggalPengembalian ASC
");

if (!$data) die("Query error: " . $conn->error);

$msgArr  = $msg ? explode('|', $msg, 2) : ['', ''];
$msgType = $msgArr[0];
$msgText = $msgArr[1] ?? '';

function statusBadgePetugas($s) {
    switch (strtolower($s)) {
        case 'dipinjam':     return '<span class="badge badge-info">Dipinjam</span>';
        case 'dikembalikan': return '<span class="badge badge-success">Dikembalikan</span>';
        case 'terlambat':    return '<span class="badge badge-danger">⚠️ Terlambat</span>';
        default:             return '<span class="badge">' . htmlspecialchars($s) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengembalian Buku — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
/* ── Alert ─────────────────────────────────────────────────── */
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid;}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb;}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba;}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb;}

/* ── Stat mini cards ─────────────────────────────────────────── */
.stat-mini-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:22px;}
.stat-mini{background:#fff;border-radius:12px;border:1px solid var(--border);padding:14px 18px;display:flex;flex-direction:column;gap:5px;box-shadow:0 2px 6px rgba(0,0,0,.04);}
.sm-icon{font-size:24px;}
.sm-val{font-size:22px;font-weight:700;font-family:'Playfair Display',serif;line-height:1;}
.sm-lbl{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;}
.sm-sub{font-size:11px;color:#9ca3af;}
.stat-mini.green {border-color:#bbf7d0;background:linear-gradient(135deg,#fff,#f0fdf4);}
.stat-mini.green .sm-val{color:#16a34a;}
.stat-mini.red   {border-color:#fca5a5;background:linear-gradient(135deg,#fff,#fef2f2);}
.stat-mini.red   .sm-val{color:#dc2626;}
.stat-mini.blue  {border-color:#bfdbfe;background:linear-gradient(135deg,#fff,#eff6ff);}
.stat-mini.blue  .sm-val{color:#2563eb;}
.stat-mini.orange{border-color:#fed7aa;background:linear-gradient(135deg,#fff,#fff7ed);}
.stat-mini.orange .sm-val{color:#ea580c;}

/* ── Cover (identik semua halaman) ───────────────────────────── */
.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;display:block;}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc;}

/* ── Denda (identik peminjaman.php) ──────────────────────────── */
.denda-wrap{display:flex;flex-direction:column;gap:2px;}
.denda-rate{font-size:12px;font-weight:700;color:#d97706;}
.denda-est {font-size:11px;color:#ef4444;}
.denda-nominal{font-size:13px;font-weight:700;color:#dc2626;}
.denda-nol    {font-size:13px;color:#94a3b8;}
.telat-info{font-size:10px;color:#ef4444;display:block;margin-top:2px;}

/* ── Status Bayar badges (identik peminjaman.php) ────────────── */
.badge-belum  {display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-lunas  {display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-nodenda{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11.5px;}

/* ── Tombol aksi (identik peminjaman.php admin) ──────────────── */
.btn-kembalikan{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .18s;white-space:nowrap;}
.btn-kembalikan:hover{opacity:.85;}
.btn-bayar{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .18s;white-space:nowrap;}
.btn-bayar:hover{opacity:.85;}

/* ── Tab navigasi (identik peminjaman.php) ───────────────────── */
.tab-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;}
.tab-btn{padding:8px 18px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#475569;transition:all .18s;display:inline-flex;align-items:center;gap:6px;}
.tab-btn:hover{border-color:#6366f1;color:#6366f1;}
.tab-btn.active{border-color:#6366f1;background:#ede9fe;color:#4f46e5;}
.tab-btn.active-danger{border-color:#ef4444;background:#fef2f2;color:#dc2626;}
.tab-badge      {background:#ef4444;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;}
.tab-badge-blue {background:#6366f1;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;}
.tab-badge-green{background:#16a34a;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;}

/* ── Search bar ──────────────────────────────────────────────── */
.search-form{display:flex;gap:8px;align-items:center;}
.search-form input{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px;font-family:'DM Sans',sans-serif;font-size:13px;min-width:220px;}
.search-form input:focus{outline:none;border-color:#6366f1;}

/* ── Row terlambat ───────────────────────────────────────────── */
tr.row-telat{background:linear-gradient(90deg,#fef2f2,#fff)!important;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>↩️ Pengembalian Buku</h1>
    </div>
  </div>

  <div class="page-content">

    <?php if ($msgText): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType === 'success' ? '✅' : ($msgType === 'warning' ? '⚠️' : '❌') ?>
        <?= htmlspecialchars($msgText) ?>
      </div>
    <?php endif; ?>

    <!-- ══ STATISTIK RINGKAS ══ -->
    <div class="stat-mini-grid">
      <div class="stat-mini orange">
        <span class="sm-icon">🔄</span>
        <div class="sm-val"><?= $cntAktif ?></div>
        <div class="sm-lbl">Perlu Dikembalikan</div>
        <div class="sm-sub">dipinjam &amp; terlambat</div>
      </div>
      <div class="stat-mini red">
        <span class="sm-icon">⚠️</span>
        <div class="sm-val"><?= $cntBelum ?></div>
        <div class="sm-lbl">Denda Belum Lunas</div>
        <div class="sm-sub">Rp <?= number_format($totalDendaBelum, 0, ',', '.') ?></div>
      </div>
      <div class="stat-mini green">
        <span class="sm-icon">💰</span>
        <div class="sm-val">Rp <?= number_format($totalDendaLunas, 0, ',', '.') ?></div>
        <div class="sm-lbl">Denda Terkumpul</div>
        <div class="sm-sub">sudah lunas</div>
      </div>
      <div class="stat-mini blue">
        <span class="sm-icon">📋</span>
        <div class="sm-val"><?= $cntSemua ?></div>
        <div class="sm-lbl">Total Transaksi</div>
      </div>
    </div>

    <!-- ══ TABEL ══ -->
    <div class="card">
      <div class="card-header" style="flex-direction:column;align-items:flex-start;gap:12px;">

        <div style="display:flex;justify-content:space-between;align-items:center;width:100%;">
          <h3>📋 Data Peminjaman &amp; Pengembalian</h3>
          <!-- Search -->
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="text" name="q" placeholder="Cari nama / judul buku..."
                   value="<?= htmlspecialchars($searchQ) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Cari</button>
            <?php if ($searchQ): ?>
              <a href="pengembalian.php?tab=<?= $tab ?>" class="btn btn-sm btn-outline">✕</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tab navigasi (identik peminjaman.php admin) -->
        <div class="tab-nav">
          <a href="pengembalian.php?tab=aktif<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'aktif' ? 'active' : '' ?>">
            🔄 Perlu Dikembalikan <span class="tab-badge-blue"><?= $cntAktif ?></span>
          </a>
          <a href="pengembalian.php?tab=semua<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'semua' ? 'active' : '' ?>">
            📋 Semua
          </a>
          <a href="pengembalian.php?tab=kembali<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
             class="tab-btn <?= $tab === 'kembali' ? 'active' : '' ?>">
            ↩️ Dikembalikan <span class="tab-badge-green"><?= $cntKembali ?></span>
          </a>
          <a href="pengembalian.php?tab=belum<?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
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
              <th>Jatuh Tempo</th>
              <th>Tgl Kembali Aktual</th>
              <th>Status</th>
              <th>Denda/Hari</th>
              <th>Total Denda</th>
              <th>Status Bayar</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($data && $data->num_rows > 0):
              $no = 1;
              while ($d = $data->fetch_assoc()):
                $jatuhTempo    = strtotime($d['TanggalPengembalian']);
                $today         = strtotime('today');
                $kembaliAktual = $d['TanggalKembaliAktual'];
                $status        = $d['StatusPeminjaman'];
                $totalDenda    = (float)$d['TotalDenda'];
                $adaDenda      = $totalDenda > 0;
                $statusBayar   = $d['StatusBayarDenda'];

                // Keterlambatan untuk yang masih aktif
                $isTelat    = in_array($status, ['dipinjam','terlambat']) && $jatuhTempo < $today;
                $hariTelat  = $isTelat ? (int)floor(($today - $jatuhTempo) / 86400) : 0;
                $estimDenda = $isTelat ? ($hariTelat * (float)($d['DendaPerHari'] ?? 0)) : 0;

                // Untuk yang sudah dikembalikan: hitung dari tanggal aktual vs jatuh tempo
                if ($status === 'dikembalikan' && $kembaliAktual) {
                    $aktualTs  = strtotime($kembaliAktual);
                    $hariTelat = ($aktualTs > $jatuhTempo) ? (int)floor(($aktualTs - $jatuhTempo) / 86400) : 0;
                    $isTelat   = $hariTelat > 0;
                }
            ?>
            <tr class="<?= ($isTelat && $status !== 'dikembalikan') ? 'row-telat' : '' ?>">
              <td><?= $no++ ?></td>

              <!-- Cover -->
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

              <!-- Jatuh Tempo -->
              <td>
                <span style="color:<?= ($isTelat && $status !== 'dikembalikan') ? '#ef4444' : 'inherit' ?>;font-weight:<?= ($isTelat && $status !== 'dikembalikan') ? '600' : '400' ?>">
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

              <!-- Status -->
              <td><?= statusBadgePetugas($status) ?></td>

              <!-- Denda/Hari + estimasi -->
              <td>
                <div class="denda-wrap">
                  <span class="denda-rate">Rp <?= number_format((float)($d['DendaPerHari'] ?? 0), 0, ',', '.') ?>/hari</span>
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

              <!-- Status Bayar Denda -->
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

              <!-- Aksi (identik logika peminjaman.php admin) -->
              <td style="white-space:nowrap;">
                <?php if (in_array(strtolower($status), ['dipinjam','terlambat'])): ?>
                  <!-- Tombol kembalikan untuk peminjaman aktif -->
                  <form method="POST" onsubmit="return konfirmasiKembali('<?= addslashes(htmlspecialchars($d['Judul'])) ?>','<?= addslashes(htmlspecialchars($d['NamaLengkap'])) ?>',<?= (int)$isTelat ?>,<?= $hariTelat ?>,<?= $estimDenda ?>)">
                    <input type="hidden" name="action"         value="kembalikan">
                    <input type="hidden" name="id"             value="<?= $d['PeminjamanID'] ?>">
                    <input type="hidden" name="buku_id"        value="<?= $d['BukuID'] ?>">
                    <input type="hidden" name="denda_per_hari" value="<?= (float)($d['DendaPerHari'] ?? 0) ?>">
                    <button type="submit" class="btn-kembalikan">↩️ Kembalikan</button>
                  </form>

                <?php elseif ($adaDenda && $statusBayar === 'Belum'): ?>
                  <!-- Tombol tandai lunas jika sudah dikembalikan tapi denda belum lunas -->
                  <form method="POST" onsubmit="return confirm('Tandai denda Rp <?= number_format($totalDenda, 0, ',', '.') ?> milik <?= addslashes(htmlspecialchars($d['NamaLengkap'])) ?> sebagai LUNAS?')">
                    <input type="hidden" name="action" value="bayar_denda">
                    <input type="hidden" name="id"     value="<?= $d['PeminjamanID'] ?>">
                    <button type="submit" class="btn-bayar">💰 Tandai Lunas</button>
                  </form>

                <?php else: ?>
                  <span style="color:#94a3b8;font-size:12px;">—</span>
                <?php endif; ?>
              </td>

            </tr>
            <?php endwhile;
            else: ?>
            <tr>
              <td colspan="12">
                <div class="empty-state">
                  <div class="empty-icon">📭</div>
                  <p>Tidak ada data<?= $searchQ ? ' untuk pencarian "'.htmlspecialchars($searchQ).'"' : '' ?>.</p>
                  <?php if ($tab !== 'aktif' || $searchQ): ?>
                    <a href="pengembalian.php" class="btn btn-sm btn-outline">Lihat Semua</a>
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

<?php $conn->close(); ?>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}

// Identik dengan konfirmasi di peminjaman.php admin
function konfirmasiKembali(judul, nama, terlambat, hariTelat, estimDenda) {
    if (terlambat) {
        const fmt = 'Rp ' + Number(estimDenda).toLocaleString('id-ID');
        return confirm(
            '⚠️ PERHATIAN — Pengembalian Terlambat!\n\n' +
            'Buku     : ' + judul + '\n' +
            'Peminjam : ' + nama  + '\n' +
            'Terlambat: ' + hariTelat + ' hari\n' +
            'Denda    : ' + fmt   + '\n\n' +
            'Denda akan tercatat BELUM DIBAYAR.\n' +
            'Lanjutkan?'
        );
    }
    return confirm('Konfirmasi pengembalian buku "' + judul + '" oleh ' + nama + '?');
}
</script>
</body>
</html>
