<?php
// views/peminjam/peminjaman.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn = getConnection();
$uid  = getUserId();
$msg  = ''; $msgType = '';

// ─── Cover Base URL ───────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// ─── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action']        ?? '';
    $PeminjamanID = intval($_POST['peminjaman_id'] ?? 0);

    if ($action === 'edit' && $PeminjamanID > 0) {
        $tglKembaliBar = $_POST['tgl_kembali_baru'] ?? '';
        $data = $conn->query("SELECT * FROM peminjaman WHERE PeminjamanID=$PeminjamanID AND UserID=$uid AND StatusPeminjaman IN ('dipinjam','menunggu')")->fetch_assoc();
        if (!$data)                               { $msg = 'Peminjaman tidak ditemukan atau tidak bisa diedit.'; $msgType = 'danger'; }
        elseif (empty($tglKembaliBar))             { $msg = 'Tanggal kembali tidak boleh kosong.'; $msgType = 'danger'; }
        elseif ($tglKembaliBar <= $data['TanggalPeminjaman']) { $msg = 'Tanggal kembali harus setelah tanggal pinjam.'; $msgType = 'danger'; }
        elseif ($tglKembaliBar <= date('Y-m-d'))  { $msg = 'Tanggal kembali harus setelah hari ini.'; $msgType = 'danger'; }
        else {
            $stmt = $conn->prepare("UPDATE peminjaman SET TanggalPengembalian=? WHERE PeminjamanID=? AND UserID=?");
            $stmt->bind_param('sii', $tglKembaliBar, $PeminjamanID, $uid);
            if ($stmt->execute()) { $msg = 'Tanggal pengembalian berhasil diperbarui!'; $msgType = 'success'; }
            else                  { $msg = 'Gagal memperbarui: ' . $conn->error; $msgType = 'danger'; }
            $stmt->close();
        }
    }
    elseif ($action === 'hapus' && $PeminjamanID > 0) {
        $data = $conn->query("SELECT * FROM peminjaman WHERE PeminjamanID=$PeminjamanID AND UserID=$uid")->fetch_assoc();
        if (!$data) { $msg = 'Data tidak ditemukan.'; $msgType = 'danger'; }
        elseif (!in_array($data['StatusPeminjaman'], ['menunggu','dikembalikan'])) {
            $msg = 'Peminjaman yang sedang aktif tidak bisa dihapus. Hubungi petugas untuk pengembalian.'; $msgType = 'warning';
        } else {
            if ($data['StatusPeminjaman'] === 'menunggu')
                $conn->query("UPDATE buku SET Stok=Stok+1 WHERE BukuID={$data['BukuID']}");
            $conn->query("DELETE FROM peminjaman WHERE PeminjamanID=$PeminjamanID AND UserID=$uid");
            $msg = 'Riwayat peminjaman berhasil dihapus.'; $msgType = 'success';
        }
    }
    elseif ($action === 'pinjam_lagi') {
        $BukuID     = intval($_POST['buku_id']   ?? 0);
        $tglPinjam  = $_POST['tgl_pinjam']  ?? date('Y-m-d');
        $tglKembali = $_POST['tgl_kembali'] ?? date('Y-m-d', strtotime('+7 days'));
        $todayStr   = date('Y-m-d');
        if ($tglPinjam < $todayStr)          { $msg = 'Tanggal pinjam tidak boleh sebelum hari ini.'; $msgType = 'danger'; }
        elseif ($tglKembali <= $tglPinjam)   { $msg = 'Tanggal pengembalian harus setelah tanggal pinjam.'; $msgType = 'danger'; }
        elseif ($BukuID > 0) {
            $cekStok  = $conn->query("SELECT Judul, Stok FROM buku WHERE BukuID=$BukuID")->fetch_assoc();
            $cekAktif = $conn->query("SELECT PeminjamanID FROM peminjaman WHERE UserID=$uid AND BukuID=$BukuID AND StatusPeminjaman='dipinjam'")->num_rows;
            if (!$cekStok || $cekStok['Stok'] < 1) { $msg = 'Stok buku sudah habis.'; $msgType = 'danger'; }
            elseif ($cekAktif > 0)                 { $msg = 'Anda masih meminjam buku ini.'; $msgType = 'warning'; }
            else {
                $stmt = $conn->prepare("INSERT INTO peminjaman (UserID,BukuID,TanggalPeminjaman,TanggalPengembalian,StatusPeminjaman) VALUES (?,?,?,?,'dipinjam')");
                $stmt->bind_param('iiss', $uid, $BukuID, $tglPinjam, $tglKembali);
                if ($stmt->execute()) {
                    $conn->query("UPDATE buku SET Stok=Stok-1 WHERE BukuID=$BukuID");
                    $msg = 'Buku "'.htmlspecialchars($cekStok['Judul']).'" berhasil dipinjam kembali!'; $msgType = 'success';
                } else { $msg = 'Gagal meminjam: ' . $conn->error; $msgType = 'danger'; }
                $stmt->close();
            }
        }
    }
}

// ─── Filter ───────────────────────────────────────────────────────────────────
$filter = $_GET['status'] ?? '';
$where  = "WHERE p.UserID=$uid";
if ($filter) { $fe = $conn->real_escape_string($filter); $where .= " AND p.StatusPeminjaman='$fe'"; }

// ─── Query utama — disamakan dengan admin/petugas ─────────────────────────────
// Tambah: TotalDenda, StatusBayarDenda, TanggalKembaliAktual, DendaPerHari
$pinjam = $conn->query("
    SELECT
        p.*,
        b.BukuID, b.Judul, b.Penulis, b.Stok, b.CoverURL,
        COALESCE(b.DendaPerHari, 5000)        AS DendaPerHari,
        COALESCE(p.TotalDenda, 0)             AS TotalDenda,
        COALESCE(p.StatusBayarDenda, 'Lunas') AS StatusBayarDenda,
        p.TanggalKembaliAktual,
        DATEDIFF(CURDATE(), p.TanggalPengembalian) AS HariTelat
    FROM peminjaman p
    JOIN buku b ON p.BukuID = b.BukuID
    $where
    ORDER BY p.CreatedAt DESC
");
if (!$pinjam) die("DB Error: " . $conn->error);

$rows             = [];
$totalDendaAktif  = 0; // estimasi denda buku yang masih dipinjam/terlambat
$totalDendaBelum  = 0; // denda sudah dikembalikan tapi belum lunas
$jumlahTelat      = 0;

while ($row = $pinjam->fetch_assoc()) {
    $status     = $row['StatusPeminjaman'];
    $ht         = max(0, (int)$row['HariTelat']);
    $dendaHari  = (float)($row['DendaPerHari'] ?? 5000);
    $totalDenda = (float)$row['TotalDenda'];
    $statusBayar= $row['StatusBayarDenda'];

    if (in_array($status, ['dipinjam','terlambat']) && $ht > 0) {
        // Aktif terlambat: hitung estimasi
        $row['_denda']      = $ht * $dendaHari;
        $row['_hariTelat']  = $ht;
        $row['_dendaType']  = 'estimasi'; // bukan dari DB, belum pasti
        $totalDendaAktif   += $row['_denda'];
        $jumlahTelat++;
    } elseif ($status === 'dikembalikan') {
        // Sudah dikembalikan: pakai TotalDenda dari DB (yang dicatat petugas)
        $row['_denda']      = $totalDenda;
        $row['_hariTelat']  = 0;
        $row['_dendaType']  = 'final';
        if ($totalDenda > 0 && $statusBayar === 'Belum') {
            $totalDendaBelum += $totalDenda;
        }
    } else {
        $row['_denda']      = 0;
        $row['_hariTelat']  = 0;
        $row['_dendaType']  = 'none';
    }

    $row['_coverUrl']   = !empty($row['CoverURL']) ? $coverBaseURL . $row['CoverURL'] : '';
    $row['_dendaHari']  = $dendaHari;
    $rows[] = $row;
}

$activePage = 'peminjaman';
$today      = date('Y-m-d');
$minKembali = date('Y-m-d', strtotime('+1 day'));
$maxDate    = date('Y-m-d', strtotime('+60 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Peminjaman Saya — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
/* ── Alert ── */
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb}

/* ── Cover (identik semua halaman) ── */
.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;display:block;}
.cover-placeholder{width:42px;height:58px;border-radius:6px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc;}

/* ── Denda cells (identik admin/petugas) ── */
.denda-wrap   {display:flex;flex-direction:column;gap:2px;}
.denda-rate   {font-size:12px;font-weight:700;color:#d97706;}
.denda-est    {font-size:11px;color:#ef4444;}
.denda-nominal{font-size:13px;font-weight:700;color:#dc2626;}
.denda-nol    {font-size:13px;color:#94a3b8;}
.telat-info   {font-size:10px;color:#ef4444;display:block;margin-top:2px;}

/* ── Status Bayar badges (identik admin/petugas) ── */
.badge-belum  {display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-lunas  {display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;}
.badge-nodenda{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11.5px;}

/* ── Stat mini cards ── */
.stat-mini-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.stat-mini{background:#fff;border-radius:12px;border:1px solid var(--border);padding:14px 18px;display:flex;flex-direction:column;gap:4px;box-shadow:0 2px 6px rgba(0,0,0,.04);}
.sm-icon{font-size:22px;}
.sm-val {font-size:21px;font-weight:700;font-family:'Playfair Display',serif;line-height:1;}
.sm-lbl {font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;}
.sm-sub {font-size:11px;color:#9ca3af;}
.stat-mini.red   {border-color:#fca5a5;background:linear-gradient(135deg,#fff,#fef2f2);} .stat-mini.red .sm-val{color:#dc2626;}
.stat-mini.orange{border-color:#fed7aa;background:linear-gradient(135deg,#fff,#fff7ed);} .stat-mini.orange .sm-val{color:#ea580c;}
.stat-mini.green {border-color:#bbf7d0;background:linear-gradient(135deg,#fff,#f0fdf4);} .stat-mini.green .sm-val{color:#16a34a;}
.stat-mini.blue  {border-color:#bfdbfe;background:linear-gradient(135deg,#fff,#eff6ff);} .stat-mini.blue .sm-val{color:#2563eb;}

/* ── Banner denda ── */
.denda-banner{background:linear-gradient(135deg,#fef2f2,#fee2e2);border:2px solid #fca5a5;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.denda-banner-icon{font-size:34px;flex-shrink:0}
.denda-banner-body h4{font-family:'Playfair Display',serif;font-size:16px;color:#7f1d1d;margin:0 0 2px}
.denda-banner-body p{font-size:13px;color:#991b1b;margin:0}
.denda-banner-total{margin-left:auto;text-align:right;flex-shrink:0}
.denda-banner-total .lbl{font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.5px}
.denda-banner-total .amt{font-size:22px;font-weight:700;color:#dc2626;font-family:'Playfair Display',serif}

/* ── Banner belum lunas ── */
.belum-lunas-banner{background:linear-gradient(135deg,#fff7ed,#ffedd5);border:2px solid #fed7aa;border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.belum-lunas-banner h4{font-family:'Playfair Display',serif;font-size:15px;color:#9a3412;margin:0 0 2px}
.belum-lunas-banner p{font-size:13px;color:#c2410c;margin:0}
.blb-total{margin-left:auto;text-align:right;}
.blb-total .lbl{font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.5px}
.blb-total .amt{font-size:20px;font-weight:700;color:#c2410c;font-family:'Playfair Display',serif}

/* ── Info petugas banner ── */
.info-petugas{background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #93c5fd;border-radius:12px;padding:13px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#1e40af}
.info-petugas strong{font-weight:700}

/* ── Tombol aksi ── */
.aksi-group{display:flex;gap:5px;flex-wrap:nowrap;align-items:center}
.btn-aksi{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;font-size:11.5px;font-weight:600;border-radius:7px;border:1.5px solid;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .18s;white-space:nowrap;text-decoration:none;line-height:1;background:#fff}
.btn-aksi-edit    {border-color:#3b82f6;color:#3b82f6} .btn-aksi-edit:hover{background:#3b82f6;color:#fff}
.btn-aksi-hapus   {border-color:#ef4444;color:#ef4444} .btn-aksi-hapus:hover{background:#ef4444;color:#fff}
.btn-aksi-repinjam{border-color:#7c3aed;color:#7c3aed} .btn-aksi-repinjam:hover{background:#7c3aed;color:#fff}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,10,20,.58);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:9999;align-items:center;justify-content:center;padding:16px}
.modal-overlay.active{display:flex}
.modal-box{background:#fff;border-radius:20px;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);animation:popIn .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes popIn{from{opacity:0;transform:scale(.87) translateY(26px)}to{opacity:1;transform:scale(1) translateY(0)}}

.mh{padding:0;color:#fff;position:relative;border-radius:20px 20px 0 0;overflow:hidden;}
.mh-cover-bg{position:absolute;inset:0;background-size:cover;background-position:center;filter:blur(8px) brightness(.35);transform:scale(1.1);}
.mh-content{position:relative;z-index:1;padding:20px 22px 16px;display:flex;gap:14px;align-items:flex-end;}
.mh-book-img{width:72px;height:100px;object-fit:cover;border-radius:8px;border:3px solid rgba(255,255,255,.3);box-shadow:0 4px 16px rgba(0,0,0,.4);flex-shrink:0;}
.mh-book-empty{width:72px;height:100px;border-radius:8px;border:2px dashed rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:32px;flex-shrink:0;background:rgba(255,255,255,.08);}
.mh-text h3{font-family:'Playfair Display',serif;font-size:17px;margin:0 0 3px;color:#fff;line-height:1.3;}
.mh-text p{font-size:12px;color:rgba(255,255,255,.65);margin:0;}
.mh-close{position:absolute;top:12px;right:12px;z-index:2;background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:17px;line-height:28px;text-align:center;transition:background .2s}
.mh-close:hover{background:rgba(255,255,255,.3)}
.mb{padding:20px 22px 24px}
.mb-label{font-size:11px;font-weight:700;color:#1e1e2f;text-transform:uppercase;letter-spacing:.65px;margin-bottom:6px;display:block}
.mb-input{border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-family:'DM Sans',sans-serif;font-size:14px;color:#1e1e2f;width:100%;box-sizing:border-box;transition:border-color .2s,box-shadow .2s}
.mb-input:focus{outline:none;border-color:#1e1e2f;box-shadow:0 0 0 3px rgba(30,30,47,.09)}
.modal-info{border-radius:10px;padding:13px 15px;font-size:13px;margin:14px 0;line-height:1.5}
.modal-info.ok  {background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.modal-info.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.modal-info.err {background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.shortcut-row{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0 16px}
.sc-btn{padding:6px 13px;border-radius:20px;border:1.5px solid #d1d5db;background:#fff;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;color:#374151;cursor:pointer;transition:all .18s}
.sc-btn:hover{border-color:#1e1e2f;background:#1e1e2f;color:#fff}
.dur-bar{border-radius:10px;padding:11px 14px;display:flex;align-items:center;gap:9px;font-size:13px;min-height:44px;transition:background .22s,color .22s;background:#f3f4f6;color:#374151;margin:12px 0}
.dur-bar.ok{background:#ecfdf5;color:#065f46}
.dur-bar.warn{background:#fff7ed;color:#9a3412}
.dur-bar.err{background:#fef2f2;color:#991b1b}
.dur-bar .di{font-size:19px;flex-shrink:0} .dur-bar .dt{font-weight:600;line-height:1.4}

/* ── Denda box di modal (sekarang dinamis) ── */
.denda-box{background:linear-gradient(135deg,#fff7ed,#fff3e0);border:1.5px solid #fed7aa;border-radius:11px;padding:13px 15px;margin:14px 0}
.denda-box-title{font-size:11.5px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.6px;margin-bottom:9px;display:flex;align-items:center;gap:5px}
.denda-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.denda-item{background:#fff;border-radius:8px;padding:8px 9px;text-align:center;border:1px solid #fed7aa}
.denda-hari{font-size:10.5px;color:#9a3412;font-weight:600;margin-bottom:1px}
.denda-nominal{font-size:13px;font-weight:700;color:#c2410c}

.btn-modal-submit{width:100%;padding:12px;border:none;border-radius:11px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s,transform .15s;letter-spacing:.3px;color:#fff;margin-top:16px}
.btn-modal-submit:hover{opacity:.87;transform:translateY(-1px)}
.btn-modal-submit:disabled{opacity:.38;cursor:not-allowed;transform:none}
.btn-modal-submit.blue  {background:linear-gradient(135deg,#1e3a8a,#3b82f6)}
.btn-modal-submit.red   {background:linear-gradient(135deg,#7f1d1d,#ef4444)}
.btn-modal-submit.purple{background:linear-gradient(135deg,#1e1e2f,#3a3a5c)}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php include '../../includes/sidebar.php'; ?>

<!-- ══ MODAL EDIT ══ -->
<div class="modal-overlay" id="modalEdit">
  <div class="modal-box">
    <div class="mh">
      <div class="mh-cover-bg" id="editCoverBg" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6)"></div>
      <button class="mh-close" onclick="tutupModal('modalEdit')">&#215;</button>
      <div class="mh-content">
        <img id="editCoverImg" class="mh-book-img" src="" alt="" style="display:none;"
             onerror="this.style.display='none';document.getElementById('editCoverEmpty').style.display='flex'">
        <div id="editCoverEmpty" class="mh-book-empty">&#9999;&#65039;</div>
        <div class="mh-text">
          <h3 id="editJudul">—</h3>
          <p>&#9999;&#65039; Edit tanggal pengembalian</p>
        </div>
      </div>
    </div>
    <div class="mb">
      <form method="POST" id="formEdit">
        <input type="hidden" name="action"        value="edit">
        <input type="hidden" name="peminjaman_id" id="editId" value="">
        <div class="modal-info ok" style="margin-bottom:14px">
          &#128197; Tanggal pinjam: <strong id="editTglPinjamTxt">—</strong>
        </div>
        <span class="mb-label">&#9889; Perpanjang Cepat</span>
        <div class="shortcut-row">
          <button type="button" class="sc-btn" onclick="tambahHariEdit(3)">+3 Hari</button>
          <button type="button" class="sc-btn" onclick="tambahHariEdit(7)">+7 Hari</button>
          <button type="button" class="sc-btn" onclick="tambahHariEdit(14)">+14 Hari</button>
          <button type="button" class="sc-btn" onclick="tambahHariEdit(30)">+1 Bulan</button>
        </div>
        <span class="mb-label">&#128276; Tanggal Kembali Baru</span>
        <input type="date" name="tgl_kembali_baru" id="editTglKembali" class="mb-input"
               min="<?= $minKembali ?>" max="<?= $maxDate ?>" required>
        <div class="dur-bar ok" id="editDurBar">
          <span class="di" id="editDurIkon">&#9989;</span>
          <span class="dt" id="editDurTeks">—</span>
        </div>
        <button type="submit" class="btn-modal-submit blue">&#128190; Simpan Perubahan</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL HAPUS ══ -->
<div class="modal-overlay" id="modalHapus">
  <div class="modal-box">
    <div class="mh">
      <div class="mh-cover-bg" id="hapusCoverBg" style="background:linear-gradient(135deg,#7f1d1d,#ef4444)"></div>
      <button class="mh-close" onclick="tutupModal('modalHapus')">&#215;</button>
      <div class="mh-content">
        <img id="hapusCoverImg" class="mh-book-img" src="" alt="" style="display:none;"
             onerror="this.style.display='none';document.getElementById('hapusCoverEmpty').style.display='flex'">
        <div id="hapusCoverEmpty" class="mh-book-empty">&#128465;&#65039;</div>
        <div class="mh-text">
          <h3 id="hapusJudul">—</h3>
          <p>&#128465;&#65039; Hapus riwayat peminjaman</p>
        </div>
      </div>
    </div>
    <div class="mb">
      <form method="POST">
        <input type="hidden" name="action"        value="hapus">
        <input type="hidden" name="peminjaman_id" id="hapusId" value="">
        <div class="modal-info err">&#9888;&#65039; Anda yakin ingin menghapus riwayat peminjaman ini? Data yang dihapus tidak dapat dipulihkan.</div>
        <button type="submit" class="btn-modal-submit red">&#128465;&#65039; Ya, Hapus Sekarang</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL PINJAM LAGI ══ -->
<div class="modal-overlay" id="modalPinjamLagi">
  <div class="modal-box">
    <div class="mh">
      <div class="mh-cover-bg" id="repinjamCoverBg" style="background:linear-gradient(135deg,#1e1e2f,#3a3a5c)"></div>
      <button class="mh-close" onclick="tutupModal('modalPinjamLagi')">&#215;</button>
      <div class="mh-content">
        <img id="repinjamCoverImg" class="mh-book-img" src="" alt="" style="display:none;"
             onerror="this.style.display='none';document.getElementById('repinjamCoverEmpty').style.display='flex'">
        <div id="repinjamCoverEmpty" class="mh-book-empty">&#128260;</div>
        <div class="mh-text">
          <h3 id="repinjamJudul">—</h3>
          <p>&#128260; Pinjam buku kembali</p>
        </div>
      </div>
    </div>
    <div class="mb">
      <form method="POST" id="formRepinjam">
        <input type="hidden" name="action"  value="pinjam_lagi">
        <input type="hidden" name="buku_id" id="repinjamBukuId" value="">
        <span class="mb-label">&#9889; Pilih Cepat Durasi</span>
        <div class="shortcut-row">
          <button type="button" class="sc-btn" onclick="setDurasiRepinjam(3)">3 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasiRepinjam(7)">7 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasiRepinjam(14)">14 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasiRepinjam(30)">1 Bulan</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:4px">
          <div>
            <span class="mb-label">&#128197; Tanggal Pinjam</span>
            <input type="date" name="tgl_pinjam" id="repinjamTglPinjam" class="mb-input"
                   min="<?= $today ?>" max="<?= $maxDate ?>" value="<?= $today ?>" required>
          </div>
          <div>
            <span class="mb-label">&#128276; Tanggal Kembali</span>
            <input type="date" name="tgl_kembali" id="repinjamTglKembali" class="mb-input"
                   min="<?= $minKembali ?>" max="<?= $maxDate ?>"
                   value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
          </div>
        </div>
        <div class="dur-bar ok" id="repinjamDurBar">
          <span class="di" id="repinjamDurIkon">&#9989;</span>
          <span class="dt" id="repinjamDurTeks">Durasi peminjaman: 7 hari</span>
        </div>
        <!-- Denda box — dinamis dari DendaPerHari buku -->
        <div class="denda-box">
          <div class="denda-box-title">&#9888;&#65039; Denda Keterlambatan Buku Ini</div>
          <div class="denda-grid">
            <div class="denda-item"><div class="denda-hari">1 hari</div><div class="denda-nominal" id="rd1">Rp 5.000</div></div>
            <div class="denda-item"><div class="denda-hari">2 hari</div><div class="denda-nominal" id="rd2">Rp 10.000</div></div>
            <div class="denda-item"><div class="denda-hari">3 hari</div><div class="denda-nominal" id="rd3">Rp 15.000</div></div>
            <div class="denda-item"><div class="denda-hari">5 hari</div><div class="denda-nominal" id="rd5">Rp 25.000</div></div>
            <div class="denda-item"><div class="denda-hari">7 hari</div><div class="denda-nominal" id="rd7">Rp 35.000</div></div>
            <div class="denda-item"><div class="denda-hari">dst...</div><div class="denda-nominal">+Rp <span id="rdrate">5.000</span>/hari</div></div>
          </div>
        </div>
        <button type="submit" class="btn-modal-submit purple" id="btnRepinjamSubmit">&#128218; Konfirmasi Peminjaman</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ MAIN ══ -->
<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
      <h1>&#128203; Peminjaman Saya</h1>
    </div>
  </header>
  <div class="page-content">

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType==='success'?'✅':($msgType==='warning'?'⚠️':'❌') ?>
        <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- ══ STAT MINI CARDS ══ -->
    <?php
    $cntAktif    = count(array_filter($rows, fn($r) => in_array($r['StatusPeminjaman'], ['dipinjam','terlambat'])));
    $cntKembali  = count(array_filter($rows, fn($r) => $r['StatusPeminjaman'] === 'dikembalikan'));
    $cntTerlambat= count(array_filter($rows, fn($r) => $r['StatusPeminjaman'] === 'terlambat'));
    ?>
    <div class="stat-mini-grid">
      <div class="stat-mini blue">
        <span class="sm-icon">📖</span>
        <div class="sm-val"><?= $cntAktif ?></div>
        <div class="sm-lbl">Sedang Dipinjam</div>
      </div>
      <div class="stat-mini <?= $cntTerlambat > 0 ? 'red' : 'green' ?>">
        <span class="sm-icon"><?= $cntTerlambat > 0 ? '⚠️' : '✅' ?></span>
        <div class="sm-val"><?= $cntTerlambat ?></div>
        <div class="sm-lbl">Terlambat</div>
        <?php if ($totalDendaAktif > 0): ?>
          <div class="sm-sub">Estimasi Rp <?= number_format($totalDendaAktif, 0, ',', '.') ?></div>
        <?php endif; ?>
      </div>
      <?php if ($totalDendaBelum > 0): ?>
      <div class="stat-mini orange">
        <span class="sm-icon">💸</span>
        <div class="sm-val">Rp <?= number_format($totalDendaBelum, 0, ',', '.') ?></div>
        <div class="sm-lbl">Denda Belum Lunas</div>
        <div class="sm-sub">hubungi petugas</div>
      </div>
      <?php endif; ?>
      <div class="stat-mini green">
        <span class="sm-icon">📚</span>
        <div class="sm-val"><?= $cntKembali ?></div>
        <div class="sm-lbl">Dikembalikan</div>
      </div>
    </div>

    <!-- Info pengembalian lewat petugas -->
    <div class="info-petugas">
      <span style="font-size:20px">ℹ️</span>
      <span><strong>Pengembalian buku</strong> dilakukan melalui petugas perpustakaan di meja layanan. Hubungi petugas untuk memproses pengembalian dan pembayaran denda.</span>
    </div>

    <!-- Banner denda aktif (estimasi) -->
    <?php if ($totalDendaAktif > 0): ?>
    <div class="denda-banner">
      <div class="denda-banner-icon">⚠️</div>
      <div class="denda-banner-body">
        <h4>Estimasi Denda Keterlambatan</h4>
        <p><?= $jumlahTelat ?> buku terlambat dikembalikan. Segera hubungi petugas untuk pengembalian.</p>
      </div>
      <div class="denda-banner-total">
        <div class="lbl">Estimasi Total Denda</div>
        <div class="amt">Rp <?= number_format($totalDendaAktif, 0, ',', '.') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Banner denda belum lunas (dari buku yang sudah dikembalikan) -->
    <?php if ($totalDendaBelum > 0): ?>
    <div class="belum-lunas-banner">
      <span style="font-size:28px;flex-shrink:0">💸</span>
      <div>
        <h4>Denda Belum Dibayar</h4>
        <p>Anda memiliki denda yang belum dilunasi. Hubungi petugas untuk pembayaran.</p>
      </div>
      <div class="blb-total">
        <div class="lbl">Total Belum Lunas</div>
        <div class="amt">Rp <?= number_format($totalDendaBelum, 0, ',', '.') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ TABEL ══ -->
    <div class="card">
      <div class="card-header">
        <h3>Riwayat &amp; Status Peminjaman</h3>
        <form method="GET" class="search-bar">
          <select name="status" style="padding:9px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px">
            <option value="">Semua Status</option>
            <?php foreach(['menunggu','dipinjam','dikembalikan','terlambat'] as $s): ?>
              <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline btn-sm">Filter</button>
        </form>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cover</th>
              <th>Judul Buku</th>
              <th>Tgl Pinjam</th>
              <th>Jatuh Tempo</th>
              <th>Tgl Kembali Aktual</th>
              <th>Sisa / Info</th>
              <th>Denda/Hari</th>
              <th>Total Denda</th>
              <th>Status Bayar</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="12">
              <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p>Belum ada riwayat peminjaman.</p>
                <?php if (!$filter): ?>
                  <a href="../../views/peminjam/katalog.php" class="btn btn-sm btn-primary">Lihat Katalog</a>
                <?php endif; ?>
              </div>
            </td></tr>
          <?php else: ?>
          <?php $no=1; foreach($rows as $row):
            $status      = $row['StatusPeminjaman'];
            $isDipinjam  = in_array($status, ['dipinjam','terlambat']);
            $jatuhTempo  = strtotime($row['TanggalPengembalian']);
            $today_ts    = strtotime('today');
            $sisaHari    = (int)ceil(($jatuhTempo - $today_ts) / 86400);
            $isTelat     = ($isDipinjam && $jatuhTempo < $today_ts);
            $hariTelat   = $row['_hariTelat'];
            $dendaHari   = $row['_dendaHari'];
            $dendaDB     = (float)$row['TotalDenda'];
            $statusBayar = $row['StatusBayarDenda'];
            $adaDenda    = $row['_denda'] > 0;
            $coverUrl    = $row['_coverUrl'];
            $kembaliAktual = $row['TanggalKembaliAktual'] ?? null;
            $bMap        = ['dipinjam'=>'badge-info','dikembalikan'=>'badge-success','terlambat'=>'badge-danger','menunggu'=>'badge-warning'];
          ?>
          <tr class="<?= $isTelat ? 'row-telat' : '' ?>">
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

            <!-- Judul -->
            <td>
              <strong><?= htmlspecialchars($row['Judul']) ?></strong><br>
              <small style="color:#94a3b8"><?= htmlspecialchars($row['Penulis']) ?></small>
            </td>

            <!-- Tgl Pinjam -->
            <td><?= date('d/m/Y', strtotime($row['TanggalPeminjaman'])) ?></td>

            <!-- Jatuh Tempo (identik admin: merah jika terlambat) -->
            <td>
              <span style="color:<?= $isTelat ? '#ef4444' : 'inherit' ?>;font-weight:<?= $isTelat ? '600' : '400' ?>">
                <?= date('d/m/Y', $jatuhTempo) ?>
              </span>
              <?php if ($isTelat): ?>
                <span class="telat-info">Terlambat <?= $hariTelat ?> hari</span>
              <?php endif; ?>
            </td>

            <!-- Tgl Kembali Aktual (identik admin) -->
            <td>
              <?php if ($kembaliAktual): ?>
                <strong><?= date('d/m/Y', strtotime($kembaliAktual)) ?></strong>
              <?php else: ?>
                <span style="color:#94a3b8">—</span>
              <?php endif; ?>
            </td>

            <!-- Sisa / Info -->
            <td>
              <?php if ($status === 'dikembalikan'): ?>
                <span style="color:#16a34a;font-size:13px">✅ Kembali</span>
              <?php elseif ($status === 'menunggu'): ?>
                <span style="color:#d97706;font-size:13px">🕐 Menunggu</span>
              <?php elseif ($isTelat): ?>
                <span class="badge badge-danger">🚨 <?= $hariTelat ?> hari telat</span>
              <?php elseif ($sisaHari === 0): ?>
                <span class="badge badge-warning">Kembalikan hari ini!</span>
              <?php else: ?>
                <span class="badge badge-info"><?= $sisaHari ?> hari lagi</span>
              <?php endif; ?>
            </td>

            <!-- Denda/Hari + estimasi (identik admin/petugas) -->
            <td>
              <div class="denda-wrap">
                <span class="denda-rate">Rp <?= number_format($dendaHari, 0, ',', '.') ?>/hari</span>
                <?php if ($isTelat && $row['_denda'] > 0): ?>
                  <span class="denda-est">~Rp <?= number_format($row['_denda'], 0, ',', '.') ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Total Denda (identik admin/petugas) -->
            <td>
              <?php if ($status === 'dikembalikan' && $dendaDB > 0): ?>
                <span class="denda-nominal">Rp <?= number_format($dendaDB, 0, ',', '.') ?></span>
              <?php elseif ($status === 'dikembalikan'): ?>
                <span class="denda-nol">Rp 0</span>
              <?php elseif ($isTelat && $row['_denda'] > 0): ?>
                <span style="font-size:12px;color:#d97706;font-style:italic;">~Rp <?= number_format($row['_denda'], 0, ',', '.') ?></span>
              <?php else: ?>
                <span style="color:#d1d5db;font-size:12px">—</span>
              <?php endif; ?>
            </td>

            <!-- Status Bayar (identik admin/petugas) -->
            <td>
              <?php if ($status !== 'dikembalikan'): ?>
                <span class="badge-nodenda">— Belum kembali</span>
              <?php elseif ($dendaDB <= 0): ?>
                <span class="badge-nodenda">— Tidak ada denda</span>
              <?php elseif ($statusBayar === 'Belum'): ?>
                <span class="badge-belum">❌ Belum Dibayar</span>
              <?php else: ?>
                <span class="badge-lunas">✅ Lunas</span>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td><span class="badge <?= $bMap[$status] ?? 'badge-default' ?>"><?= ucfirst($status) ?></span></td>

            <!-- Aksi -->
            <td>
              <div class="aksi-group">
                <?php if ($isDipinjam): ?>
                  <button class="btn-aksi btn-aksi-edit"
                    onclick="bukaEdit(
                      <?= $row['PeminjamanID'] ?>,
                      '<?= addslashes(htmlspecialchars($row['Judul'])) ?>',
                      '<?= $row['TanggalPeminjaman'] ?>',
                      '<?= $row['TanggalPengembalian'] ?>',
                      '<?= addslashes($coverUrl) ?>'
                    )">✏️ Edit</button>
                <?php endif; ?>
                <?php if ($status === 'dikembalikan' && $row['Stok'] > 0): ?>
                  <button class="btn-aksi btn-aksi-repinjam"
                    onclick="bukaRepinjam(
                      <?= $row['BukuID'] ?>,
                      '<?= addslashes(htmlspecialchars($row['Judul'])) ?>',
                      '<?= addslashes($coverUrl) ?>',
                      <?= (float)$dendaHari ?>
                    )">🔄 Lagi</button>
                <?php endif; ?>
                <?php if (in_array($status, ['menunggu','dikembalikan'])): ?>
                  <button class="btn-aksi btn-aksi-hapus"
                    onclick="bukaHapus(
                      <?= $row['PeminjamanID'] ?>,
                      '<?= addslashes(htmlspecialchars($row['Judul'])) ?>',
                      '<?= addslashes($coverUrl) ?>'
                    )">🗑️</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<style>
/* Row terlambat (identik admin/petugas) */
tr.row-telat{background:linear-gradient(90deg,#fef2f2,#fff)!important;}
</style>

<script>
const TODAY   = '<?= $today ?>';
const MAXDATE = '<?= $maxDate ?>';

function addDays(str, n) {
    const d = new Date(str); d.setDate(d.getDate() + n);
    return d.toISOString().split('T')[0];
}
function fmt(str) {
    if (!str) return '—';
    const [y, m, d] = str.split('-');
    return `${d}/${m}/${y}`;
}
function fmtRupiah(n) {
    return 'Rp ' + Number(n).toLocaleString('id-ID');
}
function tutupModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function bukaModal(id)  {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => {
    if (e.target === m) { m.classList.remove('active'); document.body.style.overflow = ''; }
}));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => {
        m.classList.remove('active'); document.body.style.overflow = '';
    });
});

function setCoverModal(prefix, coverUrl) {
    const img   = document.getElementById(prefix + 'CoverImg');
    const empty = document.getElementById(prefix + 'CoverEmpty');
    const bg    = document.getElementById(prefix + 'CoverBg');
    if (coverUrl) {
        img.src = coverUrl; img.style.display = 'block'; empty.style.display = 'none';
        if (bg) bg.style.backgroundImage = `url('${coverUrl}')`;
    } else {
        img.style.display = 'none'; empty.style.display = 'flex';
    }
}

// ── MODAL EDIT ──────────────────────────────────────────────────────────────
function bukaEdit(pid, judul, tglPinjam, tglKembaliLama, coverUrl) {
    document.getElementById('editId').value = pid;
    document.getElementById('editJudul').textContent = judul;
    document.getElementById('editTglPinjamTxt').textContent = fmt(tglPinjam);
    document.getElementById('editTglKembali').value = tglKembaliLama;
    document.getElementById('editTglKembali').min = addDays(TODAY, 1);
    setCoverModal('edit', coverUrl);
    hitungEdit();
    bukaModal('modalEdit');
}
function tambahHariEdit(n) {
    const cur  = document.getElementById('editTglKembali').value || TODAY;
    const base = cur > TODAY ? cur : TODAY;
    document.getElementById('editTglKembali').value = addDays(base, n);
    hitungEdit();
}
function hitungEdit() {
    const k    = document.getElementById('editTglKembali').value;
    const bar  = document.getElementById('editDurBar');
    const ikon = document.getElementById('editDurIkon');
    const teks = document.getElementById('editDurTeks');
    if (!k || k <= TODAY) {
        bar.className = 'dur-bar err'; ikon.textContent = '❌';
        teks.textContent = 'Tanggal kembali harus setelah hari ini.'; return;
    }
    const days = Math.round((new Date(k) - new Date(TODAY)) / 86400000);
    bar.className = days > 30 ? 'dur-bar warn' : 'dur-bar ok';
    ikon.textContent = days > 30 ? '⚠️' : '✅';
    teks.textContent = `Jatuh tempo baru: ${fmt(k)} (${days} hari dari sekarang)`;
}
document.getElementById('editTglKembali').addEventListener('change', hitungEdit);

// ── MODAL HAPUS ─────────────────────────────────────────────────────────────
function bukaHapus(pid, judul, coverUrl) {
    document.getElementById('hapusId').value = pid;
    document.getElementById('hapusJudul').textContent = judul;
    setCoverModal('hapus', coverUrl);
    bukaModal('modalHapus');
}

// ── MODAL PINJAM LAGI (sekarang terima dendaHari dari buku) ────────────────
let repinjamDendaHari = 5000;

function bukaRepinjam(bukuId, judul, coverUrl, dendaHari) {
    repinjamDendaHari = dendaHari || 5000;
    document.getElementById('repinjamBukuId').value = bukuId;
    document.getElementById('repinjamJudul').textContent = judul;
    document.getElementById('repinjamTglPinjam').value   = TODAY;
    document.getElementById('repinjamTglKembali').value  = addDays(TODAY, 7);
    setCoverModal('repinjam', coverUrl);
    updateDendaModal(repinjamDendaHari);
    hitungRepinjam();
    bukaModal('modalPinjamLagi');
}

function updateDendaModal(rate) {
    const r = parseInt(rate) || 5000;
    document.getElementById('rd1').textContent    = fmtRupiah(r * 1);
    document.getElementById('rd2').textContent    = fmtRupiah(r * 2);
    document.getElementById('rd3').textContent    = fmtRupiah(r * 3);
    document.getElementById('rd5').textContent    = fmtRupiah(r * 5);
    document.getElementById('rd7').textContent    = fmtRupiah(r * 7);
    document.getElementById('rdrate').textContent = Number(r).toLocaleString('id-ID');
}

function setDurasiRepinjam(days) {
    const p = document.getElementById('repinjamTglPinjam').value || TODAY;
    document.getElementById('repinjamTglKembali').value = addDays(p, days);
    hitungRepinjam();
}
function hitungRepinjam() {
    const p    = document.getElementById('repinjamTglPinjam').value;
    const k    = document.getElementById('repinjamTglKembali').value;
    const bar  = document.getElementById('repinjamDurBar');
    const ikon = document.getElementById('repinjamDurIkon');
    const teks = document.getElementById('repinjamDurTeks');
    const btn  = document.getElementById('btnRepinjamSubmit');
    document.getElementById('repinjamTglKembali').min = addDays(p || TODAY, 1);
    if (!p || !k || k <= p) {
        bar.className = 'dur-bar err'; ikon.textContent = '❌';
        teks.textContent = 'Tanggal pengembalian harus setelah tanggal pinjam.';
        btn.disabled = true; return;
    }
    const days = Math.round((new Date(k) - new Date(p)) / 86400000);
    btn.disabled = false;
    if (days > 30) {
        bar.className = 'dur-bar warn'; ikon.textContent = '⚠️';
        teks.textContent = `Durasi ${days} hari — pastikan tepat waktu.`;
    } else {
        bar.className = 'dur-bar ok'; ikon.textContent = '✅';
        teks.textContent = `Durasi peminjaman: ${days} hari.`;
    }
}
document.getElementById('repinjamTglPinjam').addEventListener('change', function() {
    const k = document.getElementById('repinjamTglKembali');
    if (k.value <= this.value) k.value = addDays(this.value, 1);
    hitungRepinjam();
});
document.getElementById('repinjamTglKembali').addEventListener('change', hitungRepinjam);
document.getElementById('formRepinjam').addEventListener('submit', function(e) {
    const p    = document.getElementById('repinjamTglPinjam').value;
    const k    = document.getElementById('repinjamTglKembali').value;
    const days = Math.round((new Date(k) - new Date(p)) / 86400000);
    if (days <= 0) { e.preventDefault(); return; }
    const judul = document.getElementById('repinjamJudul').textContent;
    const dendaTeks = fmtRupiah(repinjamDendaHari);
    if (!confirm(`Pinjam "${judul}" selama ${days} hari?\n📅 Pinjam  : ${fmt(p)}\n🔔 Kembali : ${fmt(k)}\n⚠️ Denda keterlambatan ${dendaTeks}/hari.`)) e.preventDefault();
});

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>
<?php $conn->close(); ?>