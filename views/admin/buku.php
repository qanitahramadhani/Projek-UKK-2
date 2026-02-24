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
    $izin = ['jpg','jpeg','png','webp','gif'];
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

    // ── TAMBAH ────────────────────────────────────────────────────────────────
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
                // Judul(s) Penulis(s) Penerbit(s) Tahun(i) Kategori(i) Stok(i) Deskripsi(s) Cover(s) Denda(i)
                $sql  = "INSERT INTO buku (Judul,Penulis,Penerbit,TahunTerbit,KategoriID,Stok,Deskripsi,CoverURL,DendaPerHari) VALUES (?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissi', $judul, $penulis, $penerbit, $tahun, $kategori, $stok, $deskripsi, $coverNama, $dendaHari);
            } else {
                // Judul(s) Penulis(s) Penerbit(s) Tahun(i) Kategori(i) Stok(i) Deskripsi(s) Cover(s)
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

    // ── EDIT ──────────────────────────────────────────────────────────────────
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

            // Proses upload cover baru
            if (!empty($_FILES['cover']['name']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                $up = simpanCover($_FILES['cover'], $uploadDir);
                if ($up !== '') {
                    hapusCover($coverLama, $uploadDir);
                    $coverBaru = $up;
                } else {
                    $msg = 'warning|Format/ukuran cover tidak valid. Cover lama dipertahankan.';
                }
            }

            // Hapus cover jika dicentang
            if (!empty($_POST['hapus_cover'])) {
                hapusCover($coverBaru, $uploadDir);
                $coverBaru = '';
            }

            $colCheck = $conn->query("SHOW COLUMNS FROM buku LIKE 'DendaPerHari'");
            $hasDenda = $colCheck && $colCheck->num_rows > 0;

            if ($hasDenda) {
                // FIX: 'sssiiissii' — Judul(s) Penulis(s) Penerbit(s) Tahun(i) Kategori(i) Stok(i) Deskripsi(s) Cover(s) Denda(i) ID(i)
                $sql  = "UPDATE buku SET Judul=?,Penulis=?,Penerbit=?,TahunTerbit=?,KategoriID=?,Stok=?,Deskripsi=?,CoverURL=?,DendaPerHari=? WHERE BukuID=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissii',
                    $judul, $penulis, $penerbit, $tahun,
                    $kategori, $stok, $deskripsi, $coverBaru,
                    $dendaHari, $id
                );
            } else {
                // FIX: 'sssiiissi' — Judul(s) Penulis(s) Penerbit(s) Tahun(i) Kategori(i) Stok(i) Deskripsi(s) Cover(s) ID(i)
                $sql  = "UPDATE buku SET Judul=?,Penulis=?,Penerbit=?,TahunTerbit=?,KategoriID=?,Stok=?,Deskripsi=?,CoverURL=? WHERE BukuID=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sssiiissi',
                    $judul, $penulis, $penerbit, $tahun,
                    $kategori, $stok, $deskripsi, $coverBaru,
                    $id
                );
            }
            if (!$stmt) die("Prepare gagal: " . $conn->error);

            if ($stmt->execute()) {
                if (!$msg) $msg = 'success|Buku berhasil diperbarui!';
            } else {
                $msg = 'danger|Gagal: ' . $stmt->error;
            }
        }
    }

    // ── HAPUS ─────────────────────────────────────────────────────────────────
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

$result = $conn->query("
    SELECT b.*, k.NamaKategori
    FROM buku b
    LEFT JOIN kategoribuku k ON b.KategoriID = k.KategoriID
    $where
    ORDER BY b.BukuID DESC
");
if (!$result) die("Query gagal: " . $conn->error);

$katResult = $conn->query("SELECT * FROM kategoribuku ORDER BY NamaKategori");

$semua_buku     = [];
$semua_kategori = [];
while ($r = $result->fetch_assoc())    $semua_buku[]     = $r;
while ($k = $katResult->fetch_assoc()) $semua_kategori[] = $k;

[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];
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
            ⚠️ Kolom <code>DendaPerHari</code> belum ada di tabel <code>buku</code>. Jalankan SQL berikut di phpMyAdmin:
            <br><code style="background:#fff3cd;padding:4px 8px;border-radius:4px;display:inline-block;margin-top:6px;">
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
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($semua_buku) > 0): ?>
                        <?php $no=1; foreach ($semua_buku as $b): ?>
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
                        <tr><td colspan="9"><div class="empty-state"><p>Tidak ada buku ditemukan.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
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

<script>
const COVER_BASE = '<?= $coverBaseURL ?>';

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function previewCover(input, imgId, emptyId) {
    const img = document.getElementById(imgId), empty = document.getElementById(emptyId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src=e.target.result; img.style.display='block'; if(empty) empty.style.display='none'; };
        reader.readAsDataURL(input.files[0]);
    }
}

function fmtRupiah(n) {
    return 'Rp ' + Number(n).toLocaleString('id-ID');
}
function updateDendaPreview(prefix, val) {
    const v = parseInt(val) || 0;
    const pfx = prefix + '_';
    document.getElementById(pfx+'d1').textContent  = fmtRupiah(v * 1);
    document.getElementById(pfx+'d2').textContent  = fmtRupiah(v * 2);
    document.getElementById(pfx+'d3').textContent  = fmtRupiah(v * 3);
    document.getElementById(pfx+'d5').textContent  = fmtRupiah(v * 5);
    document.getElementById(pfx+'d7').textContent  = fmtRupiah(v * 7);
    document.getElementById(pfx+'dst').textContent = Number(v).toLocaleString('id-ID');
    document.getElementById(pfx+'denda_desc').innerHTML =
        `Setiap hari keterlambatan dikenakan denda <strong>${fmtRupiah(v)}</strong>`;
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

    // Reset file input & checkbox hapus
    document.getElementById('e_cover_input').value = '';
    const hapusCb = document.querySelector('#formEdit input[name="hapus_cover"]');
    if (hapusCb) hapusCb.checked = false;

    const img   = document.getElementById('e_prev_img');
    const empty = document.getElementById('e_prev_empty');
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
</script>
</body>
</html>
<?php $conn->close(); ?>