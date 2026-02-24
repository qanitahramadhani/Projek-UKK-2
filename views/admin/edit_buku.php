<?php
// views/admin/edit_buku.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();

// ─── Path upload cover (filesystem & URL) ────────────────────────────────────
// Filesystem path — untuk simpan/hapus file di server
$uploadDir = realpath(dirname(__FILE__) . '/../../public/uploads/covers/');
if (!$uploadDir) {
    $buatDir = dirname(__FILE__) . '/../../public/uploads/covers/';
    mkdir($buatDir, 0755, true);
    $uploadDir = realpath($buatDir);
}
$uploadDir = str_replace('\\', '/', $uploadDir) . '/';

// URL path — otomatis deteksi subfolder project di XAMPP
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// ─── DEBUG: Tampilkan path (hapus/comment baris ini jika sudah berjalan normal) ──
// Uncomment baris di bawah untuk debug path:
// echo '<pre style="background:#1e293b;color:#a5f3fc;padding:12px;font-size:12px;margin:10px;">';
// echo 'uploadDir  : ' . $uploadDir . "\n";
// echo 'coverBaseURL: ' . $coverBaseURL . "\n";
// echo 'DOCUMENT_ROOT: ' . $_SERVER['DOCUMENT_ROOT'] . "\n";
// echo '__FILE__: ' . __FILE__ . "\n";
// echo '</pre>';

// ─── Ambil ID dari URL ────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) {
    header('Location: buku.php?msg=danger|ID+buku+tidak+valid');
    exit;
}

// ─── Ambil data buku dari DB ──────────────────────────────────────────────────
$stmtBuku = $conn->prepare("SELECT * FROM buku WHERE BukuID = ?");
$stmtBuku->bind_param('i', $id);
$stmtBuku->execute();
$resBuku = $stmtBuku->get_result();

if ($resBuku->num_rows === 0) {
    header('Location: buku.php?msg=danger|Buku+tidak+ditemukan');
    exit;
}
$buku = $resBuku->fetch_assoc();

// ─── Ambil daftar kategori ────────────────────────────────────────────────────
$resKat = $conn->query("SELECT KategoriID, NamaKategori FROM kategoribuku ORDER BY NamaKategori ASC");
$semua_kategori = array();
if ($resKat) {
    while ($k = $resKat->fetch_assoc()) {
        $semua_kategori[] = $k;
    }
}

// ─── Helper: simpan cover ─────────────────────────────────────────────────────
function simpanCoverEdit($file, $dir) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) return '';
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $izin = array('jpg', 'jpeg', 'png', 'webp', 'gif');
    if (!in_array($ext, $izin)) return '__invalid__';
    if ($file['size'] > 3 * 1024 * 1024) return '__toobig__';
    $nama = 'cover_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $dir . $nama)) {
        return $nama;
    }
    return '';
}

function hapusCoverEdit($nama, $dir) {
    if ($nama && file_exists($dir . $nama)) {
        unlink($dir . $nama);
    }
}

// ─── Handle POST (simpan perubahan) ───────────────────────────────────────────
$msg     = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $judul     = isset($_POST['judul'])     ? trim($_POST['judul'])     : '';
    $penulis   = isset($_POST['penulis'])   ? trim($_POST['penulis'])   : '';
    $penerbit  = isset($_POST['penerbit'])  ? trim($_POST['penerbit'])  : '';
    $tahun     = isset($_POST['tahun'])     ? (int)$_POST['tahun']      : (int)date('Y');
    $kategori  = isset($_POST['kategori'])  ? (int)$_POST['kategori']   : 0;
    $stok      = isset($_POST['stok'])      ? (int)$_POST['stok']       : 0;
    $deskripsi = isset($_POST['deskripsi']) ? trim($_POST['deskripsi'])  : '';
    $coverLama = isset($_POST['cover_lama']) ? trim($_POST['cover_lama']) : '';
    $hapusCov  = isset($_POST['hapus_cover']) ? true : false;

    // Validasi
    if ($judul === '') {
        $msg     = 'Judul buku tidak boleh kosong!';
        $msgType = 'danger';
    } elseif ($kategori === 0) {
        $msg     = 'Pilih kategori terlebih dahulu!';
        $msgType = 'danger';
    } else {
        $coverBaru = $coverLama; // default: pakai cover lama

        // Proses upload cover baru
        if (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = simpanCoverEdit($_FILES['cover'], $uploadDir);
            if ($up === '__invalid__') {
                $msg     = 'Format file tidak didukung! Gunakan JPG, PNG, atau WEBP.';
                $msgType = 'warning';
            } elseif ($up === '__toobig__') {
                $msg     = 'Ukuran file terlalu besar! Maksimal 3 MB.';
                $msgType = 'warning';
            } elseif ($up !== '') {
                // Upload sukses — hapus cover lama
                hapusCoverEdit($coverLama, $uploadDir);
                $coverBaru = $up;
            }
        }

        // Hapus cover jika dicentang
        if ($hapusCov) {
            hapusCoverEdit($coverBaru, $uploadDir);
            $coverBaru = '';
        }

        // Simpan ke database jika tidak ada error
        if ($msgType !== 'warning' && $msgType !== 'danger') {
            $stmtUpd = $conn->prepare(
                "UPDATE buku
                 SET Judul=?, Penulis=?, Penerbit=?, TahunTerbit=?, KategoriID=?, Stok=?, Deskripsi=?, CoverURL=?
                 WHERE BukuID=?"
            );
            if (!$stmtUpd) {
                $msg     = 'Prepare gagal: ' . $conn->error;
                $msgType = 'danger';
            } else {
                $stmtUpd->bind_param('ssssiissi',
                    $judul, $penulis, $penerbit, $tahun,
                    $kategori, $stok, $deskripsi, $coverBaru, $id
                );

                if ($stmtUpd->execute()) {
                    // Refresh data buku setelah update
                    $buku['Judul']       = $judul;
                    $buku['Penulis']     = $penulis;
                    $buku['Penerbit']    = $penerbit;
                    $buku['TahunTerbit'] = $tahun;
                    $buku['KategoriID']  = $kategori;
                    $buku['Stok']        = $stok;
                    $buku['Deskripsi']   = $deskripsi;
                    $buku['CoverURL']    = $coverBaru;

                    $msg     = 'Data buku berhasil diperbarui!';
                    $msgType = 'success';
                } else {
                    $msg     = 'Gagal menyimpan: ' . $stmtUpd->error;
                    $msgType = 'danger';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Buku — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
        /* ─── Layout dua kolom ─── */
        .edit-layout {
            display: flex;
            gap: 24px;
            align-items: flex-start;
        }

        /* ─── Kolom kiri: cover ─── */
        .cover-col {
            width: 220px;
            flex-shrink: 0;
        }
        .cover-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            border: 1px solid #f1f5f9;
            text-align: center;
        }
        .cover-card h4 {
            font-size: 14px; font-weight: 600;
            color: #1e293b; margin: 0 0 16px;
        }
        .cover-big {
            width: 160px; height: 220px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #e0e7ff;
            display: block; margin: 0 auto 14px;
        }
        .cover-big-empty {
            width: 160px; height: 220px;
            border-radius: 10px;
            border: 2px dashed #c7d2fe;
            background: #f5f3ff;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 8px; color: #a5b4fc;
            margin: 0 auto 14px;
            font-size: 13px;
        }
        .cover-big-empty span { font-size: 44px; }

        .upload-label {
            display: block;
            border: 2px dashed #c7d2fe;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            font-size: 12px;
            color: #6366f1;
            background: #f5f3ff;
            transition: .2s;
            line-height: 1.6;
        }
        .upload-label:hover { border-color: #6366f1; background: #ede9fe; }
        .upload-label input[type=file] { display: none; }

        .hapus-cov-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #ef4444;
            cursor: pointer; margin-top: 8px;
            justify-content: center;
        }

        /* ─── Kolom kanan: form ─── */
        .form-col { flex: 1; min-width: 0; }

        .form-card {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            border: 1px solid #f1f5f9;
        }
        .form-card h3 {
            font-size: 17px; font-weight: 700;
            color: #1e293b; margin: 0 0 22px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .field-group {
            margin-bottom: 18px;
        }
        .field-group label {
            display: block;
            font-size: 13px; font-weight: 600;
            color: #475569; margin-bottom: 6px;
        }
        .field-group input[type=text],
        .field-group input[type=number],
        .field-group select,
        .field-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: #1e293b;
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
            box-sizing: border-box;
        }
        .field-group input:focus,
        .field-group select:focus,
        .field-group textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.1);
        }
        .field-group textarea { resize: vertical; min-height: 90px; }

        .field-row {
            display: flex; gap: 16px;
        }
        .field-row .field-group { flex: 1; }

        /* ─── Alert ─── */
        .alert-box {
            border-radius: 10px; padding: 12px 16px;
            font-size: 14px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-box.success { background: #f0fdf4; color: #166534; border-left: 4px solid #22c55e; }
        .alert-box.danger  { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-box.warning { background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; }

        /* ─── Tombol aksi ─── */
        .form-actions {
            display: flex; gap: 12px; align-items: center;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
            margin-top: 4px;
        }
        .btn-save {
            padding: 11px 28px;
            background: #6366f1; color: #fff;
            border: none; border-radius: 9px;
            font-size: 14px; font-weight: 600;
            cursor: pointer; transition: .2s;
        }
        .btn-save:hover { background: #4f46e5; }
        .btn-back {
            padding: 11px 20px;
            background: #f1f5f9; color: #475569;
            border: none; border-radius: 9px;
            font-size: 14px; font-weight: 500;
            cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            transition: .2s;
        }
        .btn-back:hover { background: #e2e8f0; }

        /* ─── Badge stok ─── */
        .stok-info {
            font-size: 12px; color: #64748b; margin-top: 4px;
        }

        /* ─── Responsive ─── */
        @media (max-width: 700px) {
            .edit-layout { flex-direction: column; }
            .cover-col   { width: 100%; }
            .field-row   { flex-direction: column; gap: 0; }
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php include '../../includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Edit Buku</h1>
        </div>
        <div class="topbar-right">
            <a href="buku.php" class="btn-back">← Kembali ke Data Buku</a>
        </div>
    </div>

    <div class="page-content">

        <!-- Breadcrumb -->
        <div style="font-size:13px;color:#94a3b8;margin-bottom:20px;">
            <a href="buku.php" style="color:#6366f1;text-decoration:none;">Data Buku</a>
            &rsaquo; Edit: <strong style="color:#1e293b;"><?= htmlspecialchars($buku['Judul']) ?></strong>
        </div>

        <!-- Alert -->
        <?php if ($msg): ?>
            <div class="alert-box <?= $msgType ?>">
                <?php
                if ($msgType === 'success') echo '✅';
                elseif ($msgType === 'warning') echo '⚠️';
                else echo '❌';
                ?>
                <?= htmlspecialchars($msg) ?>
                <?php if ($msgType === 'success'): ?>
                    &nbsp;—&nbsp;
                    <a href="buku.php" style="color:#166534;font-weight:600;">Kembali ke daftar buku</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="cover_lama" id="cover_lama" value="<?= htmlspecialchars($buku['CoverURL'] ?? '') ?>">

            <div class="edit-layout">

                <!-- ═══ KOLOM KIRI: COVER ═══ -->
                <div class="cover-col">
                    <div class="cover-card">
                        <h4>🖼️ Cover Buku</h4>

                        <!-- Preview -->
                        <?php if (!empty($buku['CoverURL'])): ?>
                            <img id="preview_img"
                                 src="<?= $coverBaseURL . htmlspecialchars($buku['CoverURL']) ?>"
                                 class="cover-big"
                                 alt="Cover"
                                 onerror="this.style.display='none';document.getElementById('preview_empty').style.display='flex'">
                            <div id="preview_empty" class="cover-big-empty" style="display:none;">
                                <span>📚</span>Tidak ada cover
                            </div>
                        <?php else: ?>
                            <div id="preview_empty" class="cover-big-empty">
                                <span>📚</span>Tidak ada cover
                            </div>
                            <img id="preview_img" class="cover-big" style="display:none;" src="" alt="Preview">
                        <?php endif; ?>

                        <!-- Upload -->
                        <label class="upload-label" for="cover_input">
                            <input type="file" id="cover_input" name="cover"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   onchange="previewGambar(this)">
                            🔄 Ganti Cover<br>
                            <small style="color:#a5b4fc;">JPG · PNG · WEBP · Maks 3MB</small>
                        </label>

                        <!-- Hapus cover -->
                        <?php if (!empty($buku['CoverURL'])): ?>
                            <label class="hapus-cov-label" id="hapus_wrap">
                                <input type="checkbox" name="hapus_cover" id="hapus_cover" value="1"
                                       onchange="toggleHapusCover(this)">
                                Hapus cover saat ini
                            </label>
                        <?php endif; ?>

                        <!-- Info file -->
                        <?php if (!empty($buku['CoverURL'])): ?>
                            <div style="margin-top:10px;font-size:11px;color:#94a3b8;word-break:break-all;">
                                <?= htmlspecialchars($buku['CoverURL']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ KOLOM KANAN: FORM ═══ -->
                <div class="form-col">
                    <div class="form-card">
                        <h3>📖 Informasi Buku — ID #<?= $id ?></h3>

                        <!-- Judul -->
                        <div class="field-group">
                            <label>Judul Buku *</label>
                            <input type="text" name="judul"
                                   value="<?= htmlspecialchars($buku['Judul']) ?>"
                                   placeholder="Masukkan judul buku" required>
                        </div>

                        <!-- Penulis & Penerbit -->
                        <div class="field-row">
                            <div class="field-group">
                                <label>Penulis</label>
                                <input type="text" name="penulis"
                                       value="<?= htmlspecialchars($buku['Penulis'] ?? '') ?>"
                                       placeholder="Nama penulis">
                            </div>
                            <div class="field-group">
                                <label>Penerbit</label>
                                <input type="text" name="penerbit"
                                       value="<?= htmlspecialchars($buku['Penerbit'] ?? '') ?>"
                                       placeholder="Nama penerbit">
                            </div>
                        </div>

                        <!-- Tahun & Kategori -->
                        <div class="field-row">
                            <div class="field-group">
                                <label>Tahun Terbit</label>
                                <input type="number" name="tahun"
                                       value="<?= htmlspecialchars($buku['TahunTerbit'] ?? date('Y')) ?>"
                                       min="1800" max="<?= date('Y') ?>">
                            </div>
                            <div class="field-group">
                                <label>Kategori *</label>
                                <select name="kategori" required>
                                    <option value="">-- Pilih Kategori --</option>
                                    <?php foreach ($semua_kategori as $kat): ?>
                                        <option value="<?= $kat['KategoriID'] ?>"
                                            <?= (int)$buku['KategoriID'] === (int)$kat['KategoriID'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($kat['NamaKategori']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Stok -->
                        <div class="field-group">
                            <label>Stok</label>
                            <input type="number" name="stok"
                                   value="<?= (int)$buku['Stok'] ?>"
                                   min="0" style="max-width:160px;">
                            <div class="stok-info">
                                Stok saat ini: <strong><?= (int)$buku['Stok'] ?></strong> eksemplar
                            </div>
                        </div>

                        <!-- Deskripsi -->
                        <div class="field-group">
                            <label>Deskripsi / Sinopsis</label>
                            <textarea name="deskripsi"
                                      placeholder="Sinopsis atau keterangan singkat buku..."><?= htmlspecialchars($buku['Deskripsi'] ?? '') ?></textarea>
                        </div>

                        <!-- Tombol -->
                        <div class="form-actions">
                            <button type="submit" class="btn-save">💾 Simpan Perubahan</button>
                            <a href="buku.php" class="btn-back">✕ Batal</a>
                        </div>
                    </div>
                </div>

            </div><!-- /edit-layout -->
        </form>

    </div><!-- /page-content -->
</div><!-- /main -->

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}

// Preview gambar sebelum upload
function previewGambar(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img   = document.getElementById('preview_img');
            var empty = document.getElementById('preview_empty');
            img.src           = e.target.result;
            img.style.display = 'block';
            if (empty) empty.style.display = 'none';

            // Jika ada checkbox hapus, uncheck dulu
            var cb = document.getElementById('hapus_cover');
            if (cb) cb.checked = false;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Toggle preview saat hapus cover dicentang
function toggleHapusCover(cb) {
    var img   = document.getElementById('preview_img');
    var empty = document.getElementById('preview_empty');
    if (cb.checked) {
        img.style.display   = 'none';
        if (empty) empty.style.display = 'flex';
        // Reset file input
        document.getElementById('cover_input').value = '';
    } else {
        var lama = document.getElementById('cover_lama').value;
        if (lama) {
            img.src           = '<?= $coverBaseURL ?>' + lama;
            img.style.display = 'block';
            if (empty) empty.style.display = 'none';
        }
    }
}
</script>
</body>
</html>
<?php $conn->close(); ?>
