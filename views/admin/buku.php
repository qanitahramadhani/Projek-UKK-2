<?php
// views/admin/buku.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();
$msg  = '';

// ─── Path upload cover ────────────────────────────────────────────────────────
$uploadDir = realpath(dirname(__FILE__) . '/../../public/uploads/covers/');
if (!$uploadDir) {
    $buatDir = dirname(__FILE__) . '/../../public/uploads/covers/';
    mkdir($buatDir, 0755, true);
    $uploadDir = realpath($buatDir);
}
$uploadDir = str_replace('\\', '/', $uploadDir) . '/';

$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

function simpanCover($file, $dir) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) return '';
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $izin = array('jpg','jpeg','png','webp','gif');
    if (!in_array($ext, $izin)) return '';
    if ($file['size'] > 3 * 1024 * 1024) return '';
    $nama = 'cover_' . uniqid() . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dir . $nama);
    return $nama;
}
function hapusCover($nama, $dir) {
    if ($nama && file_exists($dir . $nama)) unlink($dir . $nama);
}

// ─── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $judul     = trim($_POST['judul']     ?? '');
        $penulis   = trim($_POST['penulis']   ?? '');
        $penerbit  = trim($_POST['penerbit']  ?? '');
        $tahun     = (int)($_POST['tahun']    ?? date('Y'));
        $kategori  = (int)($_POST['kategori'] ?? 0);
        $stok      = (int)($_POST['stok']     ?? 1);
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $dendaHari = (int)($_POST['denda_per_hari'] ?? 5000);

        if ($kategori === 0) {
            $msg = 'danger|Pilih kategori terlebih dahulu!';
        } else {
            $coverNama = '';
            if (!empty($_FILES['cover']['name'])) {
                $coverNama = simpanCover($_FILES['cover'], $uploadDir);
                if ($coverNama === '') $msg = 'warning|Format/ukuran cover tidak valid (maks 3 MB, format jpg/png/webp).';
            }

            $colCheck = $conn->query("SHOW COLUMNS FROM buku LIKE 'DendaPerHari'");
            $hasDenda = $colCheck && $colCheck->num_rows > 0;

            if ($hasDenda) {
                $sql  = "INSERT INTO buku (Judul,Penulis,Penerbit,TahunTerbit,KategoriID,Stok,Deskripsi,CoverURL,DendaPerHari) VALUES (?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissi', $judul, $penulis, $penerbit, $tahun, $kategori, $stok, $deskripsi, $coverNama, $dendaHari);
            } else {
                $sql  = "INSERT INTO buku (Judul,Penulis,Penerbit,TahunTerbit,KategoriID,Stok,Deskripsi,CoverURL) VALUES (?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiiss', $judul, $penulis, $penerbit, $tahun, $kategori, $stok, $deskripsi, $coverNama);
            }
            if (!$stmt) die("Prepare gagal: " . $conn->error);

            if ($stmt->execute()) {
                $msg = 'success|Buku berhasil ditambahkan!';
            } else {
                if ($coverNama) hapusCover($coverNama, $uploadDir);
                $msg = 'danger|Gagal: ' . $stmt->error;
            }
        }
    }

    if ($action === 'edit') {
        $id        = (int)($_POST['id']        ?? 0);
        $judul     = trim($_POST['judul']      ?? '');
        $penulis   = trim($_POST['penulis']    ?? '');
        $penerbit  = trim($_POST['penerbit']   ?? '');
        $tahun     = (int)($_POST['tahun']     ?? date('Y'));
        $kategori  = (int)($_POST['kategori']  ?? 0);
        $stok      = (int)($_POST['stok']      ?? 0);
        $deskripsi = trim($_POST['deskripsi']  ?? '');
        $coverLama = trim($_POST['cover_lama'] ?? '');
        $dendaHari = (int)($_POST['denda_per_hari'] ?? 5000);

        if ($id === 0 || $kategori === 0) {
            $msg = 'danger|Data tidak valid!';
        } else {
            $coverBaru = $coverLama;

            if (!empty($_FILES['cover']['name']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                $up = simpanCover($_FILES['cover'], $uploadDir);
                if ($up !== '') {
                    hapusCover($coverLama, $uploadDir);
                    $coverBaru = $up;
                } else {
                    $msg = 'warning|Format/ukuran cover tidak valid. Cover lama dipertahankan.';
                }
            }

            if (!empty($_POST['hapus_cover'])) {
                hapusCover($coverBaru, $uploadDir);
                $coverBaru = '';
            }

            $colCheck = $conn->query("SHOW COLUMNS FROM buku LIKE 'DendaPerHari'");
            $hasDenda = $colCheck && $colCheck->num_rows > 0;

            if ($hasDenda) {
                $sql  = "UPDATE buku SET Judul=?,Penulis=?,Penerbit=?,TahunTerbit=?,KategoriID=?,Stok=?,Deskripsi=?,CoverURL=?,DendaPerHari=? WHERE BukuID=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissii', $judul, $penulis, $penerbit, $tahun, $kategori, $stok, $deskripsi, $coverBaru, $dendaHari, $id);
            } else {
                $sql  = "UPDATE buku SET Judul=?,Penulis=?,Penerbit=?,TahunTerbit=?,KategoriID=?,Stok=?,Deskripsi=?,CoverURL=? WHERE BukuID=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissi', $judul, $penulis, $penerbit, $tahun, $kategori, $stok, $deskripsi, $coverBaru, $id);
            }
            if (!$stmt) die("Prepare gagal: " . $conn->error);

            if ($stmt->execute()) {
                if (!$msg) $msg = 'success|Buku berhasil diperbarui!';
            } else {
                $msg = 'danger|Gagal: ' . $stmt->error;
            }
        }
    }

    if ($action === 'hapus') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $conn->query("SELECT CoverURL FROM buku WHERE BukuID=$id")->fetch_assoc();
        if ($row && $row['CoverURL']) hapusCover($row['CoverURL'], $uploadDir);
        $conn->query("DELETE FROM buku WHERE BukuID=$id");
        $msg = 'success|Buku berhasil dihapus.';
    }
}

// ─── Query ────────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE b.Judul LIKE '%$search%' OR b.Penulis LIKE '%$search%'" : '';

$colCheck = $conn->query("SHOW COLUMNS FROM buku LIKE 'DendaPerHari'");
$hasDenda = $colCheck && $colCheck->num_rows > 0;

// Cek apakah tabel ulasanbuku ada
$cekUlasan = $conn->query("SHOW TABLES LIKE 'ulasanbuku'");
$hasUlasan = $cekUlasan && $cekUlasan->num_rows > 0;

$result = $conn->query("
    SELECT b.*, k.NamaKategori
    " . ($hasUlasan ? ", ROUND(AVG(u.Rating),1) AS RataRating, COUNT(u.UlasanID) AS JmlUlasan" : ", NULL AS RataRating, 0 AS JmlUlasan") . "
    FROM buku b
    LEFT JOIN kategoribuku k ON b.KategoriID = k.KategoriID
    " . ($hasUlasan ? "LEFT JOIN ulasanbuku u ON b.BukuID = u.BukuID" : "") . "
    $where
    GROUP BY b.BukuID
    ORDER BY b.BukuID DESC
");
if (!$result) die("Query gagal: " . $conn->error);

$katResult = $conn->query("SELECT * FROM kategoribuku ORDER BY NamaKategori");

$semua_buku     = array();
$semua_kategori = array();
while ($r = $result->fetch_assoc())    $semua_buku[]     = $r;
while ($k = $katResult->fetch_assoc()) $semua_kategori[] = $k;

// Ambil ulasan per buku untuk modal
$ulasanPerBuku = array();
if ($hasUlasan) {
    $ulasanQ = $conn->query("
        SELECT u.UlasanID, u.BukuID, u.Ulasan, u.Rating, u.CreatedAt,
               us.NamaLengkap, us.UserID
        FROM ulasanbuku u
        JOIN user us ON u.UserID = us.UserID
        ORDER BY u.CreatedAt DESC
    ");
    if ($ulasanQ) {
        while ($ur = $ulasanQ->fetch_assoc()) {
            $ulasanPerBuku[$ur['BukuID']][] = $ur;
        }
    }
}

$msgArr  = $msg ? explode('|', $msg, 2) : array('', '');
$msgType = $msgArr[0]; $msgText = $msgArr[1] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kelola Buku — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
    .cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;background:#f8fafc;display:block;}
    .cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc;}
    .cover-preview{width:120px;height:164px;object-fit:cover;border-radius:8px;border:2px solid #c7d2fe;display:block;}
    .cover-preview-empty{width:120px;height:164px;border-radius:8px;border:2px dashed #c7d2fe;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#a5b4fc;background:#f5f3ff;font-size:12px;gap:6px;text-align:center;}
    .cover-preview-empty span{font-size:30px;}
    .upload-area{display:block;border:2px dashed #c7d2fe;border-radius:10px;padding:14px 16px;text-align:center;cursor:pointer;transition:.2s;background:#f5f3ff;font-size:13px;color:#6366f1;line-height:1.6;}
    .upload-area:hover{border-color:#6366f1;background:#ede9fe;}
    .upload-area input[type=file]{display:none;}
    .cover-row{display:flex;gap:16px;align-items:flex-start;}
    .cover-row-right{flex:1;display:flex;flex-direction:column;gap:8px;}
    .hapus-cover-label{display:flex;align-items:center;gap:6px;font-size:12px;color:#ef4444;cursor:pointer;}
    .btn-warning{background:#f59e0b;color:#fff;border:none;}
    .btn-warning:hover{background:#d97706;}
    .aksi-wrap{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
    .denda-info-box{background:linear-gradient(135deg,#fff7ed,#fff3e0);border:1.5px solid #fed7aa;border-radius:12px;padding:14px 16px;margin-top:4px;}
    .denda-info-title{font-size:11.5px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:6px;margin-bottom:10px;}
    .denda-info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px;}
    .denda-info-item{background:#fff;border-radius:8px;padding:8px 10px;text-align:center;border:1px solid #fed7aa;}
    .denda-info-hari{font-size:10.5px;color:#9a3412;font-weight:600;margin-bottom:2px;}
    .denda-info-nominal{font-size:13px;font-weight:700;color:#c2410c;}
    .denda-input-row{display:flex;align-items:center;gap:10px;margin-top:10px;}
    .denda-input-row label{font-size:12px;font-weight:600;color:#9a3412;white-space:nowrap;}
    .denda-input-row input{width:130px;border:2px solid #fed7aa;border-radius:8px;padding:7px 10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;color:#c2410c;background:#fff;text-align:right;}
    .denda-input-row input:focus{outline:none;border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1);}
    .denda-preview-text{font-size:12px;color:#9a3412;margin-top:6px;}
    .denda-col{font-size:12px;font-weight:700;color:#c2410c;}

    /* ── Rating ── */
    .rating-col{display:flex;flex-direction:column;gap:2px;}
    .rating-stars{font-size:12px;color:#f59e0b;}
    .rating-val{font-size:12px;font-weight:700;color:#d97706;}
    .rating-cnt{font-size:11px;color:#9ca3af;}
    .btn-ulasan{display:inline-flex;align-items:center;gap:4px;padding:4px 9px;font-size:11px;font-weight:600;border-radius:6px;border:1.5px solid #7c3aed;color:#7c3aed;background:#fff;cursor:pointer;transition:all .18s;font-family:'DM Sans',sans-serif;}
    .btn-ulasan:hover{background:#7c3aed;color:#fff;}

    /* ── Modal Ulasan ── */
    .modal-ulasan{display:none;position:fixed;inset:0;background:rgba(10,10,20,.58);backdrop-filter:blur(7px);z-index:9999;align-items:center;justify-content:center;padding:16px;}
    .modal-ulasan.active{display:flex;}
    .mu-box{background:#fff;border-radius:20px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);}
    .mu-header{background:linear-gradient(135deg,#1e1e2f,#4a4a7a);padding:20px 22px;border-radius:20px 20px 0 0;color:#fff;position:relative;}
    .mu-header h3{font-family:'Playfair Display',serif;font-size:18px;margin:0 0 4px;}
    .mu-header p{font-size:12px;color:rgba(255,255,255,.6);margin:0;}
    .mu-close{position:absolute;top:14px;right:14px;background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:17px;line-height:28px;text-align:center;}
    .mu-close:hover{background:rgba(255,255,255,.3);}
    .mu-body{padding:20px 22px 24px;}
    .mu-stat{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #fcd34d;border-radius:12px;padding:14px 16px;margin-bottom:18px;}
    .mu-stat-big{font-size:36px;font-weight:700;font-family:'Playfair Display',serif;color:#d97706;line-height:1;}
    .mu-stat-stars{font-size:20px;color:#f59e0b;margin-bottom:3px;}
    .mu-stat-sub{font-size:12px;color:#92400e;}
    .mu-empty{text-align:center;padding:30px;color:#9ca3af;}
    .mu-empty .ei{font-size:36px;margin-bottom:8px;}

    .review-card-adm{background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;padding:13px 15px;margin-bottom:9px;}
    .review-card-adm:last-child{margin-bottom:0;}
    .rca-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
    .rca-user{display:flex;align-items:center;gap:8px;}
    .rca-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1e1e2f,#4a4a7a);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
    .rca-name{font-size:13px;font-weight:600;color:#1e1e2f;}
    .rca-date{font-size:11px;color:#9ca3af;}
    .rca-stars{font-size:13px;color:#f59e0b;}
    .rca-text{font-size:13px;color:#374151;line-height:1.5;}
    .bar-stars{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
    .bar-stars .label{font-size:11px;color:#6b7280;width:38px;text-align:right;}
    .bar-track{flex:1;height:8px;background:#f3f4f6;border-radius:4px;overflow:hidden;}
    .bar-fill{height:100%;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:4px;}
    .bar-cnt{font-size:11px;color:#9ca3af;width:24px;}
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php include '../../includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Kelola Buku</h1>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary" onclick="openModal('modalTambah')">+ Tambah Buku</button>
        </div>
    </div>

    <div class="page-content">

        <?php if ($msgText): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= $msgType==='success'?'✅':($msgType==='warning'?'⚠️':'❌') ?> <?= htmlspecialchars($msgText) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasDenda): ?>
        <div class="alert alert-warning">
            ⚠️ Kolom <code>DendaPerHari</code> belum ada. Jalankan:
            <code style="background:#fff3cd;padding:4px 8px;border-radius:4px;display:inline-block;margin-top:6px;">
                ALTER TABLE buku ADD COLUMN DendaPerHari INT NOT NULL DEFAULT 5000;
            </code>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>📖 Daftar Koleksi Buku</h3>
                <form method="GET" class="search-bar">
                    <input type="text" name="q" placeholder="Cari judul / penulis..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-outline">Cari</button>
                    <?php if ($search): ?><a href="buku.php" class="btn btn-sm">Reset</a><?php endif; ?>
                </form>
            </div>
            <div class="card-body table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cover</th>
                            <th>Judul</th>
                            <th>Penulis</th>
                            <th>Kategori</th>
                            <th>Tahun</th>
                            <th>Stok</th>
                            <th>Denda/Hari</th>
                            <th>Rating</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($semua_buku) > 0): ?>
                        <?php $no=1; foreach ($semua_buku as $b):
                            $jmlUlasan  = (int)($b['JmlUlasan'] ?? 0);
                            $rataRating = $b['RataRating'] ? round((float)$b['RataRating'], 1) : 0;
                            $bukuUlasan = $ulasanPerBuku[$b['BukuID']] ?? array();
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <?php if (!empty($b['CoverURL'])): ?>
                                    <img src="<?= $coverBaseURL . htmlspecialchars($b['CoverURL']) ?>" class="cover-thumb" alt="Cover"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                    <div class="cover-placeholder" style="display:none">📚</div>
                                <?php else: ?>
                                    <div class="cover-placeholder">📚</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($b['Judul']) ?></strong>
                                <?php if (!empty($b['Deskripsi'])): ?>
                                    <br><small style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars(mb_substr($b['Deskripsi'],0,55)) ?>…</small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($b['Penulis'] ?? '-') ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($b['NamaKategori'] ?? 'Tanpa Kategori') ?></span></td>
                            <td><?= htmlspecialchars($b['TahunTerbit'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $b['Stok']>0?'badge-success':'badge-danger' ?>"><?= (int)$b['Stok'] ?></span>
                            </td>
                            <td>
                                <span class="denda-col">
                                    Rp <?= number_format($hasDenda && isset($b['DendaPerHari']) ? (int)$b['DendaPerHari'] : 5000, 0, ',', '.') ?>/hari
                                </span>
                            </td>
                            <!-- Kolom Rating -->
                            <td>
                                <div class="rating-col">
                                    <?php if ($jmlUlasan > 0): ?>
                                        <span class="rating-val">
                                            <?php for($s=1;$s<=5;$s++) echo $s<=$rataRating ? '⭐' : '☆'; ?>
                                        </span>
                                        <span class="rating-val"><?= $rataRating ?>/5</span>
                                        <span class="rating-cnt"><?= $jmlUlasan ?> ulasan</span>
                                        <button class="btn-ulasan" onclick="bukaModalUlasan(<?= $b['BukuID'] ?>, '<?= addslashes(htmlspecialchars($b['Judul'])) ?>')">
                                            💬 Lihat
                                        </button>
                                    <?php else: ?>
                                        <span class="rating-cnt">Belum ada ulasan</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="aksi-wrap">
                                    <button type="button" class="btn btn-sm btn-warning"
                                        onclick="bukaEdit(
                                            <?= $b['BukuID'] ?>,
                                            '<?= addslashes(htmlspecialchars($b['Judul'])) ?>',
                                            '<?= addslashes(htmlspecialchars($b['Penulis'] ?? '')) ?>',
                                            '<?= addslashes(htmlspecialchars($b['Penerbit'] ?? '')) ?>',
                                            <?= (int)($b['TahunTerbit'] ?? date('Y')) ?>,
                                            <?= (int)($b['KategoriID'] ?? 0) ?>,
                                            <?= (int)$b['Stok'] ?>,
                                            '<?= addslashes(htmlspecialchars($b['Deskripsi'] ?? '')) ?>',
                                            '<?= addslashes($b['CoverURL'] ?? '') ?>',
                                            <?= $hasDenda && isset($b['DendaPerHari']) ? (int)$b['DendaPerHari'] : 5000 ?>
                                        )">✏️ Edit</button>
                                    <form method="POST" onsubmit="return confirm('Hapus buku ini?')">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id"     value="<?= $b['BukuID'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10"><div class="empty-state"><p>Tidak ada buku ditemukan.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODAL ULASAN BUKU ═══ -->
<div class="modal-ulasan" id="modalUlasan">
    <div class="mu-box">
        <div class="mu-header">
            <button class="mu-close" onclick="tutupModalUlasan()">×</button>
            <h3 id="muJudul">—</h3>
            <p>⭐ Ulasan & Rating dari Pembaca</p>
        </div>
        <div class="mu-body" id="muBody">
            <div class="mu-empty"><div class="ei">💬</div><p>Memuat ulasan...</p></div>
        </div>
    </div>
</div>

<!-- ═══ MODAL TAMBAH ═══ -->
<div class="modal-overlay" id="modalTambah">
    <div class="modal" style="max-width:640px;">
        <div class="modal-header">
            <h3>📖 Tambah Buku Baru</h3>
            <button class="modal-close" onclick="closeModal('modalTambah')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="tambah">
            <div class="modal-body">
                <div class="form-group">
                    <label>Cover Buku <small style="color:#94a3b8;">(Opsional)</small></label>
                    <div class="cover-row">
                        <div>
                            <div class="cover-preview-empty" id="t_prev_empty"><span>🖼️</span>Belum ada foto</div>
                            <img id="t_prev_img" class="cover-preview" style="display:none;" src="" alt="Preview">
                        </div>
                        <div class="cover-row-right">
                            <label class="upload-area" for="t_cover_input">
                                <input type="file" id="t_cover_input" name="cover" accept="image/jpeg,image/png,image/webp,image/gif"
                                       onchange="previewCover(this,'t_prev_img','t_prev_empty')">
                                📁 Klik untuk memilih foto cover<br>
                                <small style="color:#a5b4fc;">JPG · PNG · WEBP &nbsp;|&nbsp; Maks 3 MB</small>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label>Judul Buku *</label><input name="judul" required placeholder="Masukkan judul buku"></div>
                <div class="form-row">
                    <div class="form-group"><label>Penulis</label><input name="penulis" placeholder="Nama penulis"></div>
                    <div class="form-group"><label>Penerbit</label><input name="penerbit" placeholder="Nama penerbit"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tahun Terbit</label>
                        <input type="number" name="tahun" value="<?= date('Y') ?>" min="1800" max="<?= date('Y') ?>">
                    </div>
                    <div class="form-group">
                        <label>Kategori *</label>
                        <select name="kategori" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($semua_kategori as $k): ?>
                                <option value="<?= $k['KategoriID'] ?>"><?= htmlspecialchars($k['NamaKategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Stok</label><input type="number" name="stok" value="1" min="0"></div>
                <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi" rows="3" placeholder="Sinopsis atau keterangan singkat buku..."></textarea></div>
                <div class="form-group">
                    <label>⚠️ Ketentuan Denda Keterlambatan</label>
                    <div class="denda-info-box">
                        <div class="denda-info-title">⚠️ Denda Keterlambatan Pengembalian</div>
                        <div class="denda-info-grid">
                            <div class="denda-info-item"><div class="denda-info-hari">1 hari</div><div class="denda-info-nominal" id="t_d1">Rp 5.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">2 hari</div><div class="denda-info-nominal" id="t_d2">Rp 10.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">3 hari</div><div class="denda-info-nominal" id="t_d3">Rp 15.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">5 hari</div><div class="denda-info-nominal" id="t_d5">Rp 25.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">7 hari</div><div class="denda-info-nominal" id="t_d7">Rp 35.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">dst...</div><div class="denda-info-nominal">+Rp <span id="t_dst">5.000</span>/hari</div></div>
                        </div>
                        <div class="denda-input-row">
                            <label for="t_denda_input">Denda per hari:</label>
                            <input type="number" id="t_denda_input" name="denda_per_hari" value="5000" min="0" step="500"
                                   oninput="updateDendaPreview('t', this.value)">
                        </div>
                        <div class="denda-preview-text" id="t_denda_desc">Setiap hari keterlambatan dikenakan denda <strong>Rp 5.000</strong></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Buku</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL EDIT ═══ -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal" style="max-width:640px;">
        <div class="modal-header">
            <h3>✏️ Edit Buku</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="formEdit">
            <input type="hidden" name="action"     value="edit">
            <input type="hidden" name="id"         id="editBukuId">
            <input type="hidden" name="cover_lama" id="editCoverLama">
            <div class="modal-body">
                <div class="form-group">
                    <label>Cover Buku</label>
                    <div class="cover-row">
                        <div>
                            <div class="cover-preview-empty" id="e_prev_empty" style="display:none"><span>🖼️</span>Belum ada foto</div>
                            <img id="e_prev_img" class="cover-preview" src="" alt="Preview">
                        </div>
                        <div class="cover-row-right">
                            <label class="upload-area" for="e_cover_input">
                                <input type="file" id="e_cover_input" name="cover" accept="image/jpeg,image/png,image/webp,image/gif"
                                       onchange="previewCover(this,'e_prev_img','e_prev_empty')">
                                📁 Ganti foto cover<br>
                                <small style="color:#a5b4fc;">JPG · PNG · WEBP &nbsp;|&nbsp; Maks 3 MB</small>
                            </label>
                            <label class="hapus-cover-label">
                                <input type="checkbox" name="hapus_cover" value="1"> 🗑️ Hapus cover saat ini
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label>Judul Buku *</label><input name="judul" id="editJudul" required placeholder="Masukkan judul buku"></div>
                <div class="form-row">
                    <div class="form-group"><label>Penulis</label><input name="penulis" id="editPenulis" placeholder="Nama penulis"></div>
                    <div class="form-group"><label>Penerbit</label><input name="penerbit" id="editPenerbit" placeholder="Nama penerbit"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tahun Terbit</label>
                        <input type="number" name="tahun" id="editTahun" min="1800" max="<?= date('Y') ?>">
                    </div>
                    <div class="form-group">
                        <label>Kategori *</label>
                        <select name="kategori" id="editKategori" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($semua_kategori as $k): ?>
                                <option value="<?= $k['KategoriID'] ?>"><?= htmlspecialchars($k['NamaKategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Stok</label><input type="number" name="stok" id="editStok" min="0"></div>
                <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi" id="editDeskripsi" rows="3"></textarea></div>
                <div class="form-group">
                    <label>⚠️ Ketentuan Denda Keterlambatan</label>
                    <div class="denda-info-box">
                        <div class="denda-info-title">⚠️ Denda Keterlambatan Pengembalian</div>
                        <div class="denda-info-grid">
                            <div class="denda-info-item"><div class="denda-info-hari">1 hari</div><div class="denda-info-nominal" id="e_d1">Rp 5.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">2 hari</div><div class="denda-info-nominal" id="e_d2">Rp 10.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">3 hari</div><div class="denda-info-nominal" id="e_d3">Rp 15.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">5 hari</div><div class="denda-info-nominal" id="e_d5">Rp 25.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">7 hari</div><div class="denda-info-nominal" id="e_d7">Rp 35.000</div></div>
                            <div class="denda-info-item"><div class="denda-info-hari">dst...</div><div class="denda-info-nominal">+Rp <span id="e_dst">5.000</span>/hari</div></div>
                        </div>
                        <div class="denda-input-row">
                            <label for="e_denda_input">Denda per hari:</label>
                            <input type="number" id="e_denda_input" name="denda_per_hari" value="5000" min="0" step="500"
                                   oninput="updateDendaPreview('e', this.value)">
                        </div>
                        <div class="denda-preview-text" id="e_denda_desc">Setiap hari keterlambatan dikenakan denda <strong>Rp 5.000</strong></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<?php
// Encode data ulasan ke JSON untuk digunakan di JS
$ulasanJson = array();
foreach ($semua_buku as $b) {
    $bid = $b['BukuID'];
    $ulasanJson[$bid] = array(
        'judul'     => $b['Judul'],
        'rating'    => round((float)($b['RataRating'] ?? 0), 1),
        'jml'       => (int)($b['JmlUlasan'] ?? 0),
        'ulasan'    => $ulasanPerBuku[$bid] ?? array()
    );
}
?>

<script>
const COVER_BASE  = '<?= $coverBaseURL ?>';
const ULASAN_DATA = <?= json_encode($ulasanJson, JSON_UNESCAPED_UNICODE) ?>;

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function previewCover(input, imgId, emptyId) {
    var img = document.getElementById(imgId), empty = document.getElementById(emptyId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) { img.src=e.target.result; img.style.display='block'; if(empty) empty.style.display='none'; };
        reader.readAsDataURL(input.files[0]);
    }
}

function fmtRupiah(n) {
    return 'Rp ' + Number(n).toLocaleString('id-ID');
}
function updateDendaPreview(prefix, val) {
    var v = parseInt(val) || 0;
    var pfx = prefix + '_';
    document.getElementById(pfx+'d1').textContent  = fmtRupiah(v * 1);
    document.getElementById(pfx+'d2').textContent  = fmtRupiah(v * 2);
    document.getElementById(pfx+'d3').textContent  = fmtRupiah(v * 3);
    document.getElementById(pfx+'d5').textContent  = fmtRupiah(v * 5);
    document.getElementById(pfx+'d7').textContent  = fmtRupiah(v * 7);
    document.getElementById(pfx+'dst').textContent = Number(v).toLocaleString('id-ID');
    document.getElementById(pfx+'denda_desc').innerHTML =
        'Setiap hari keterlambatan dikenakan denda <strong>' + fmtRupiah(v) + '</strong>';
}

function bukaEdit(id, judul, penulis, penerbit, tahun, kategori, stok, deskripsi, coverUrl, dendaHari) {
    document.getElementById('editBukuId').value     = id;
    document.getElementById('editJudul').value      = judul;
    document.getElementById('editPenulis').value    = penulis;
    document.getElementById('editPenerbit').value   = penerbit;
    document.getElementById('editTahun').value      = tahun;
    document.getElementById('editKategori').value   = kategori;
    document.getElementById('editStok').value       = stok;
    document.getElementById('editDeskripsi').value  = deskripsi;
    document.getElementById('editCoverLama').value  = coverUrl;
    document.getElementById('e_denda_input').value  = dendaHari || 5000;

    document.getElementById('e_cover_input').value = '';
    var hapusCb = document.querySelector('#formEdit input[name="hapus_cover"]');
    if (hapusCb) hapusCb.checked = false;

    var img   = document.getElementById('e_prev_img');
    var empty = document.getElementById('e_prev_empty');
    if (coverUrl) {
        img.src           = COVER_BASE + coverUrl;
        img.style.display = 'block';
        empty.style.display = 'none';
    } else {
        img.src             = '';
        img.style.display   = 'none';
        empty.style.display = 'flex';
    }

    updateDendaPreview('e', dendaHari || 5000);
    openModal('modalEdit');
}

// ── Modal Ulasan ──
function bukaModalUlasan(bukuId, judul) {
    var modal = document.getElementById('modalUlasan');
    var body  = document.getElementById('muBody');
    document.getElementById('muJudul').textContent = judul;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    var data = ULASAN_DATA[bukuId];
    if (!data || data.jml === 0) {
        body.innerHTML = '<div class="mu-empty"><div class="ei">💬</div><p>Buku ini belum mendapat ulasan.</p></div>';
        return;
    }

    // Hitung distribusi bintang
    var dist = {1:0, 2:0, 3:0, 4:0, 5:0};
    data.ulasan.forEach(function(u) { dist[u.Rating] = (dist[u.Rating]||0) + 1; });
    var maxDist = Math.max.apply(null, Object.values(dist)) || 1;

    var starsHtml = '';
    for (var s=1; s<=5; s++) { starsHtml += s <= Math.round(data.rating) ? '⭐' : '☆'; }

    var barHtml = '';
    for (var b=5; b>=1; b--) {
        var pct = Math.round((dist[b]||0) / data.jml * 100);
        barHtml += '<div class="bar-stars">' +
            '<div class="label">' + b + ' ★</div>' +
            '<div class="bar-track"><div class="bar-fill" style="width:' + pct + '%"></div></div>' +
            '<div class="bar-cnt">' + (dist[b]||0) + '</div>' +
        '</div>';
    }

    var statHtml = '<div class="mu-stat">' +
        '<div><div class="mu-stat-big">' + (data.rating || '—') + '</div>' +
        '<div class="mu-stat-stars">' + starsHtml + '</div>' +
        '<div class="mu-stat-sub">' + data.jml + ' ulasan</div></div>' +
        '<div style="flex:1">' + barHtml + '</div>' +
        '</div>';

    var reviewsHtml = '';
    data.ulasan.forEach(function(u) {
        var initial = (u.NamaLengkap || '?').charAt(0).toUpperCase();
        var rStars  = '';
        for (var s=1; s<=5; s++) rStars += s <= u.Rating ? '⭐' : '☆';
        var tgl = u.CreatedAt ? u.CreatedAt.substring(0, 10).split('-').reverse().join('/') : '—';
        reviewsHtml +=
            '<div class="review-card-adm">' +
              '<div class="rca-head">' +
                '<div class="rca-user">' +
                  '<div class="rca-avatar">' + initial + '</div>' +
                  '<div><div class="rca-name">' + escHtml(u.NamaLengkap) + '</div>' +
                  '<div class="rca-date">' + tgl + '</div></div>' +
                '</div>' +
                '<div class="rca-stars">' + rStars + '</div>' +
              '</div>' +
              '<div class="rca-text">' + escHtml(u.Ulasan) + '</div>' +
            '</div>';
    });

    body.innerHTML = statHtml + '<div style="font-size:13px;font-weight:700;color:#1e1e2f;margin-bottom:10px;">💬 Semua Ulasan</div>' + reviewsHtml;
}

function tutupModalUlasan() {
    document.getElementById('modalUlasan').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalUlasan').addEventListener('click', function(e) {
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
<?php $conn->close(); ?>