<?php
// views/petugas/buku.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn = getConnection();
$msg  = ''; $msgType = '';

// ─── Path upload cover ─────────────────────────────────────────────────────────
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

function simpanCoverPetugas($file, $dir) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) return '';
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $izin = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $izin))          return '';
    if ($file['size'] > 3 * 1024 * 1024) return '';
    $nama = 'cover_' . uniqid() . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $nama) ? $nama : '';
}

function hapusCoverPetugas($nama, $dir) {
    if ($nama && file_exists($dir . $nama)) unlink($dir . $nama);
}

// ─── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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

        if ($id === 0 || empty($judul)) {
            $msg = 'Data tidak valid!'; $msgType = 'danger';
        } else {
            $coverBaru = $coverLama;

            // 1. Upload cover baru jika ada
            if (!empty($_FILES['cover']['name']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                $up = simpanCoverPetugas($_FILES['cover'], $uploadDir);
                if ($up !== '') {
                    hapusCoverPetugas($coverLama, $uploadDir);
                    $coverBaru = $up;
                } else {
                    $msg     = 'Format/ukuran cover tidak valid. Cover lama dipertahankan.';
                    $msgType = 'warning';
                }
            }

            // 2. Hapus cover jika dicentang
            if (!empty($_POST['hapus_cover'])) {
                hapusCoverPetugas($coverBaru, $uploadDir);
                $coverBaru = '';
            }

            // FIX: 'sssiiissi' — Judul(s) Penulis(s) Penerbit(s) Tahun(i) Kategori(i) Stok(i) Deskripsi(s) Cover(s) ID(i)
            $stmt = $conn->prepare("
                UPDATE buku
                SET Judul=?, Penulis=?, Penerbit=?, TahunTerbit=?,
                    KategoriID=?, Stok=?, Deskripsi=?, CoverURL=?
                WHERE BukuID=?
            ");
            $stmt->bind_param(
                'sssiiissi',
                $judul, $penulis, $penerbit, $tahun,
                $kategori, $stok, $deskripsi, $coverBaru,
                $id
            );

            if ($stmt->execute()) {
                if (!$msg) { $msg = 'Buku berhasil diperbarui!'; $msgType = 'success'; }
            } else {
                $msg = 'Gagal memperbarui: ' . $conn->error; $msgType = 'danger';
            }
            $stmt->close();
        }
    }

    // ── HAPUS ─────────────────────────────────────────────────────────────────
    if ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);

        $cekPinjam = $conn->query(
            "SELECT COUNT(*) FROM peminjaman
             WHERE BukuID=$id AND StatusPeminjaman IN ('dipinjam','terlambat')"
        )->fetch_row()[0] ?? 0;

        if ($cekPinjam > 0) {
            $msg = 'Buku tidak bisa dihapus karena masih ada yang meminjam!';
            $msgType = 'warning';
        } else {
            $row = $conn->query("SELECT CoverURL FROM buku WHERE BukuID=$id")->fetch_assoc();
            if ($row && $row['CoverURL']) hapusCoverPetugas($row['CoverURL'], $uploadDir);
            $conn->query("DELETE FROM buku WHERE BukuID=$id");
            $msg = 'Buku berhasil dihapus.'; $msgType = 'success';
        }
    }
}

// ─── Query data ────────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$searchEsc = $conn->real_escape_string($search);
$where     = $search
    ? "WHERE b.Judul LIKE '%$searchEsc%' OR b.Penulis LIKE '%$searchEsc%'"
    : '';

$buku = $conn->query("
    SELECT b.*, k.NamaKategori
    FROM buku b
    LEFT JOIN kategoribuku k ON b.KategoriID = k.KategoriID
    $where
    ORDER BY b.Judul
");
if (!$buku) die("Query error: " . $conn->error);

$kategoriList = $conn->query("SELECT * FROM kategoribuku ORDER BY NamaKategori");
$semuaKat = [];
if ($kategoriList) while ($k = $kategoriList->fetch_assoc()) $semuaKat[] = $k;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Buku — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb}
.aksi-wrap{display:flex;gap:6px;align-items:center;flex-wrap:nowrap}
.btn-aksi{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;font-size:11.5px;font-weight:600;border-radius:7px;border:1.5px solid;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .18s;white-space:nowrap;background:#fff}
.btn-aksi-edit {border-color:#f59e0b;color:#d97706}
.btn-aksi-edit:hover{background:#f59e0b;color:#fff}
.btn-aksi-hapus{border-color:#ef4444;color:#ef4444}
.btn-aksi-hapus:hover{background:#ef4444;color:#fff}
.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;background:#f8fafc;display:block}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc}
.cover-preview{width:120px;height:164px;object-fit:cover;border-radius:8px;border:2px solid #fed7aa;display:block}
.cover-preview-empty{width:120px;height:164px;border-radius:8px;border:2px dashed #fed7aa;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#f59e0b;background:#fffbeb;font-size:12px;gap:6px;text-align:center}
.cover-preview-empty span{font-size:30px}
.upload-area{display:block;border:2px dashed #fed7aa;border-radius:10px;padding:14px 16px;text-align:center;cursor:pointer;transition:.2s;background:#fffbeb;font-size:13px;color:#d97706;line-height:1.6}
.upload-area:hover{border-color:#f59e0b;background:#fef3c7}
.upload-area input[type=file]{display:none}
.cover-row{display:flex;gap:16px;align-items:flex-start}
.cover-row-right{flex:1;display:flex;flex-direction:column;gap:8px}
.hapus-cover-label{display:flex;align-items:center;gap:6px;font-size:12px;color:#ef4444;cursor:pointer;padding:6px 10px;background:#fef2f2;border-radius:8px;border:1px solid #fca5a5}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,10,20,.55);backdrop-filter:blur(6px);z-index:9999;align-items:center;justify-content:center;padding:16px}
.modal-overlay.active{display:flex}
.modal-box{background:#fff;border-radius:20px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);animation:popIn .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes popIn{from{opacity:0;transform:scale(.87) translateY(26px)}to{opacity:1;transform:scale(1) translateY(0)}}
.mh{padding:22px 24px 18px;color:#fff;position:relative;border-radius:20px 20px 0 0}
.mh-edit  {background:linear-gradient(135deg,#92400e,#f59e0b)}
.mh-hapus {background:linear-gradient(135deg,#7f1d1d,#ef4444)}
.mh h3{font-family:'Playfair Display',serif;font-size:19px;margin:0 0 3px;color:#fff}
.mh p{font-size:13px;color:rgba(255,255,255,.65);margin:0}
.mh-close{position:absolute;top:13px;right:13px;background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:18px;line-height:30px;text-align:center;transition:background .2s}
.mh-close:hover{background:rgba(255,255,255,.3)}
.mb{padding:22px 24px 8px}
.mb .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.mb .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.mb .form-group label{font-size:11px;font-weight:700;color:#1e1e2f;text-transform:uppercase;letter-spacing:.6px}
.mb .form-group input,.mb .form-group select,.mb .form-group textarea{border:2px solid #e5e7eb;border-radius:10px;padding:9px 12px;font-family:'DM Sans',sans-serif;font-size:14px;color:#1e1e2f;width:100%;box-sizing:border-box;transition:border-color .2s,box-shadow .2s}
.mb .form-group input:focus,.mb .form-group select:focus,.mb .form-group textarea:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1)}
.mf{padding:16px 24px 22px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #f1f5f9;margin-top:4px}
.btn-submit-edit{padding:11px 22px;background:linear-gradient(135deg,#92400e,#f59e0b);color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s}
.btn-submit-edit:hover{opacity:.87}
.btn-submit-hapus{padding:11px 22px;background:linear-gradient(135deg,#7f1d1d,#ef4444);color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s}
.btn-submit-hapus:hover{opacity:.87}
.hapus-warn{background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px 16px;font-size:13px;color:#991b1b;line-height:1.6}
.cover-status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.cover-status-badge.has-cover{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.cover-status-badge.no-cover {background:#fef3c7;color:#92400e;border:1px solid #fde68a}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<!-- ═══ MODAL EDIT ═══ -->
<div class="modal-overlay" id="modalEdit">
  <div class="modal-box">
    <div class="mh mh-edit">
      <button class="mh-close" onclick="tutupModal('modalEdit')">×</button>
      <h3>✏️ Edit Buku</h3>
      <p>Perbarui informasi &amp; foto cover buku</p>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="edit">
      <input type="hidden" name="id"         id="editId">
      <input type="hidden" name="cover_lama" id="editCoverLama">
      <div class="mb">
        <div class="form-group">
          <label>📷 Foto Cover Buku</label>
          <div class="cover-row">
            <div>
              <div class="cover-preview-empty" id="editCoverEmpty"><span>📚</span>Belum ada foto</div>
              <img id="editCoverImg" class="cover-preview" src="" alt="Preview Cover" style="display:none"
                   onerror="this.style.display='none';document.getElementById('editCoverEmpty').style.display='flex'">
            </div>
            <div class="cover-row-right">
              <label class="upload-area" for="editCoverInput">
                <input type="file" id="editCoverInput" name="cover"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       onchange="previewCover(this,'editCoverImg','editCoverEmpty')">
                📁 <strong>Klik untuk ganti foto</strong><br>
                <small style="color:#d97706">JPG · PNG · WEBP &nbsp;|&nbsp; Maks 3 MB</small>
              </label>
              <label class="hapus-cover-label" id="hapusCoverWrap">
                <input type="checkbox" name="hapus_cover" value="1" onchange="toggleHapusCover(this)">
                🗑️ Hapus foto cover saat ini
              </label>
              <div id="editCoverStatusBadge"></div>
            </div>
          </div>
        </div>
        <div class="form-group"><label>Judul Buku *</label><input type="text" name="judul" id="editJudul" required placeholder="Masukkan judul buku"></div>
        <div class="form-row">
          <div class="form-group"><label>Penulis</label><input type="text" name="penulis" id="editPenulis" placeholder="Nama penulis"></div>
          <div class="form-group"><label>Penerbit</label><input type="text" name="penerbit" id="editPenerbit" placeholder="Nama penerbit"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Tahun Terbit</label><input type="number" name="tahun" id="editTahun" min="1800" max="<?= date('Y') ?>"></div>
          <div class="form-group">
            <label>Kategori</label>
            <select name="kategori" id="editKategori">
              <option value="0">-- Pilih Kategori --</option>
              <?php foreach ($semuaKat as $k): ?>
                <option value="<?= $k['KategoriID'] ?>"><?= htmlspecialchars($k['NamaKategori']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label>Stok</label><input type="number" name="stok" id="editStok" min="0"></div>
        <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi" id="editDeskripsi" rows="3" placeholder="Sinopsis atau keterangan buku..."></textarea></div>
      </div>
      <div class="mf">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalEdit')">Batal</button>
        <button type="submit" class="btn-submit-edit">💾 Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ MODAL HAPUS ═══ -->
<div class="modal-overlay" id="modalHapus">
  <div class="modal-box" style="max-width:420px">
    <div class="mh mh-hapus">
      <button class="mh-close" onclick="tutupModal('modalHapus')">×</button>
      <h3>🗑️ Hapus Buku</h3>
      <p>Konfirmasi penghapusan data buku</p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="hapus">
      <input type="hidden" name="id"     id="hapusId">
      <div class="mb">
        <div class="hapus-warn">
          ⚠️ Yakin ingin menghapus buku <strong id="hapusJudul">"ini"</strong>?<br>
          Tindakan ini tidak dapat dibatalkan dan foto cover juga akan ikut dihapus.
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalHapus')">Batal</button>
        <button type="submit" class="btn-submit-hapus">🗑️ Ya, Hapus</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ KONTEN UTAMA ═══ -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Data Buku</h1>
    </div>
  </div>
  <div class="page-content">
    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType==='success'?'✅':($msgType==='warning'?'⚠️':'❌') ?>
        <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h3>📚 Koleksi Buku</h3>
        <form method="GET" class="search-bar">
          <input type="text" name="q" placeholder="Cari buku..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-outline">Cari</button>
          <?php if ($search): ?>
            <a href="buku.php" class="btn btn-sm btn-outline">✕ Reset</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th><th>Cover</th><th>Judul</th><th>Penulis</th>
              <th>Kategori</th><th>Tahun</th><th>Stok</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($buku->num_rows > 0):
              $no = 1;
              while ($b = $buku->fetch_assoc()): ?>
            <tr>
              <td><?= $no++ ?></td>
              <td>
                <?php if (!empty($b['CoverURL'])): ?>
                  <img src="<?= $coverBaseURL . htmlspecialchars($b['CoverURL']) ?>"
                       class="cover-thumb" alt="Cover"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="cover-placeholder" style="display:none">📚</div>
                <?php else: ?>
                  <div class="cover-placeholder">📚</div>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= htmlspecialchars($b['Judul']) ?></strong>
                <?php if (!empty($b['Deskripsi'])): ?>
                  <br><small style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars(mb_substr($b['Deskripsi'], 0, 50)) ?>…</small>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($b['Penulis'] ?: '-') ?></td>
              <td><?= $b['NamaKategori'] ? '<span class="badge badge-info">'.htmlspecialchars($b['NamaKategori']).'</span>' : '—' ?></td>
              <td><?= htmlspecialchars($b['TahunTerbit'] ?: '-') ?></td>
              <td><span class="badge <?= $b['Stok']>0?'badge-success':'badge-danger' ?>"><?= (int)$b['Stok'] ?></span></td>
              <td>
                <div class="aksi-wrap">
                  <button class="btn-aksi btn-aksi-edit"
                    onclick="bukaEdit(
                      <?= $b['BukuID'] ?>,
                      '<?= addslashes(htmlspecialchars($b['Judul'])) ?>',
                      '<?= addslashes(htmlspecialchars($b['Penulis'] ?? '')) ?>',
                      '<?= addslashes(htmlspecialchars($b['Penerbit'] ?? '')) ?>',
                      <?= (int)($b['TahunTerbit'] ?? date('Y')) ?>,
                      <?= (int)($b['KategoriID'] ?? 0) ?>,
                      <?= (int)$b['Stok'] ?>,
                      '<?= addslashes(htmlspecialchars($b['Deskripsi'] ?? '')) ?>',
                      '<?= addslashes($b['CoverURL'] ?? '') ?>'
                    )">✏️ Edit</button>
                  <button class="btn-aksi btn-aksi-hapus"
                    onclick="bukaHapus(<?= $b['BukuID'] ?>,'<?= addslashes(htmlspecialchars($b['Judul'])) ?>')">
                    🗑️ Hapus
                  </button>
                </div>
              </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">📭</div><p>Tidak ada buku ditemukan.</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php $conn->close(); ?>
<script>
const COVER_BASE = '<?= $coverBaseURL ?>';

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function tutupModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}
function bukaModal(id) {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) { m.classList.remove('active'); document.body.style.overflow = ''; } });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => { m.classList.remove('active'); document.body.style.overflow = ''; });
});

function previewCover(input, imgId, emptyId) {
    const img = document.getElementById(imgId), empty = document.getElementById(emptyId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; if (empty) empty.style.display = 'none'; };
        reader.readAsDataURL(input.files[0]);
        // Reset checkbox hapus jika milih foto baru
        const hapusChk = document.querySelector('input[name="hapus_cover"]');
        if (hapusChk) hapusChk.checked = false;
    }
}

function toggleHapusCover(checkbox) {
    const img = document.getElementById('editCoverImg');
    if (checkbox.checked) {
        img.style.opacity = '0.3';
        img.style.filter  = 'grayscale(100%)';
        // Reset file input agar tidak ada foto baru yang dikirim
        document.getElementById('editCoverInput').value = '';
    } else {
        img.style.opacity = '1';
        img.style.filter  = 'none';
    }
}

function bukaEdit(id, judul, penulis, penerbit, tahun, kategori, stok, deskripsi, coverUrl) {
    document.getElementById('editId').value        = id;
    document.getElementById('editJudul').value     = judul;
    document.getElementById('editPenulis').value   = penulis;
    document.getElementById('editPenerbit').value  = penerbit;
    document.getElementById('editTahun').value     = tahun;
    document.getElementById('editKategori').value  = kategori;
    document.getElementById('editStok').value      = stok;
    document.getElementById('editDeskripsi').value = deskripsi;
    document.getElementById('editCoverLama').value = coverUrl;

    // Reset file input & checkbox
    document.getElementById('editCoverInput').value = '';
    const hapusChk = document.querySelector('input[name="hapus_cover"]');
    if (hapusChk) hapusChk.checked = false;

    const img   = document.getElementById('editCoverImg');
    const empty = document.getElementById('editCoverEmpty');
    const badge = document.getElementById('editCoverStatusBadge');
    img.style.opacity = '1';
    img.style.filter  = 'none';

    if (coverUrl && coverUrl.trim() !== '') {
        img.src           = COVER_BASE + coverUrl;
        img.style.display = 'block';
        empty.style.display = 'none';
        badge.innerHTML = '<span class="cover-status-badge has-cover">✅ Sudah ada foto cover</span>';
        document.getElementById('hapusCoverWrap').style.display = 'flex';
    } else {
        img.src             = '';
        img.style.display   = 'none';
        empty.style.display = 'flex';
        badge.innerHTML = '<span class="cover-status-badge no-cover">⚠️ Belum ada foto cover</span>';
        document.getElementById('hapusCoverWrap').style.display = 'none';
    }
    bukaModal('modalEdit');
}

function bukaHapus(id, judul) {
    document.getElementById('hapusId').value          = id;
    document.getElementById('hapusJudul').textContent = '"' + judul + '"';
    bukaModal('modalHapus');
}
</script>
</body>
</html>
