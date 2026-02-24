<?php
// views/admin/kategori.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();
$msg  = '';

// 1. Proses penambahan dan penghapusan data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // Tambah Kategori
    if ($action === 'tambah') {
        $nama = isset($_POST['nama']) ? trim($_POST['nama']) : '';
        if ($nama === '') {
            $msg = 'danger|Nama kategori tidak boleh kosong!';
        } else {
            $stmt = $conn->prepare("INSERT INTO kategoribuku (NamaKategori) VALUES (?)");
            $stmt->bind_param('s', $nama);
            $msg = $stmt->execute() ? 'success|Kategori berhasil ditambahkan!' : 'danger|Gagal: ' . $conn->error;
        }
    }

    // Hapus Kategori
    if ($action === 'hapus') {
        $id  = (int)$_POST['id'];
        $cek = $conn->query("SELECT COUNT(*) as total FROM buku WHERE KategoriID=$id");
        if ($cek) {
            $row = $cek->fetch_assoc();
            if ($row['total'] > 0) {
                $msg = 'danger|Kategori tidak bisa dihapus karena masih memiliki ' . $row['total'] . ' buku!';
            } else {
                $conn->query("DELETE FROM kategoribuku WHERE KategoriID=$id");
                $msg = 'success|Kategori berhasil dihapus.';
            }
        } else {
            $msg = 'danger|Gagal cek buku: ' . $conn->error;
        }
    }
}

// 2. Ambil semua kategori, hitung buku per kategori secara manual
//    (menghindari masalah GROUP BY / JOIN di beberapa versi MySQL/MariaDB)
$semua_kategori = array();

$resKat = $conn->query("SELECT KategoriID, NamaKategori FROM kategoribuku ORDER BY NamaKategori ASC");

if (!$resKat) {
    die("<div style='padding:20px;font-family:sans-serif;background:#fff3cd;border-left:4px solid #f59e0b;margin:20px;border-radius:6px;'>
        <strong>Error Database:</strong> " . htmlspecialchars($conn->error) . "<br><br>
        Pastikan nama tabel adalah <b>kategoribuku</b> dan kolom adalah <b>KategoriID</b> &amp; <b>NamaKategori</b>.
    </div>");
}

while ($krow = $resKat->fetch_assoc()) {
    $kid     = (int)$krow['KategoriID'];
    $resBuku = $conn->query("SELECT COUNT(*) as total FROM buku WHERE KategoriID=$kid");
    $krow['jumlah_buku'] = ($resBuku) ? (int)$resBuku->fetch_assoc()['total'] : 0;
    $semua_kategori[]    = $krow;
}

$_tmp    = $msg ? explode('|', $msg, 2) : array('', '');
$msgType = $_tmp[0];
$msgText = $_tmp[1];

$total_kategori = count($semua_kategori);
$total_buku     = 0;
foreach ($semua_kategori as $sk) {
    $total_buku += (int)$sk['jumlah_buku'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kategori Buku — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Kategori Buku</h1>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary" onclick="openModal('modalTambah')">+ Tambah Kategori</button>
        </div>
    </div>

    <div class="page-content">

        <?php if ($msgText): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($msgText) ?>
            </div>
        <?php endif; ?>

        <!-- Ringkasan Statistik -->
        <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
            <div class="card" style="flex:1;min-width:160px;padding:16px 20px;">
                <div style="font-size:13px;color:var(--text-muted);">Total Kategori</div>
                <div style="font-size:28px;font-weight:700;color:var(--primary);"><?= $total_kategori ?></div>
            </div>
            <div class="card" style="flex:1;min-width:160px;padding:16px 20px;">
                <div style="font-size:13px;color:var(--text-muted);">Total Buku Terkategori</div>
                <div style="font-size:28px;font-weight:700;color:var(--primary);"><?= $total_buku ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>🏷️ Daftar Kategori</h3></div>
            <div class="card-body table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Kategori</th>
                            <th>Jumlah Buku</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($semua_kategori) > 0): ?>
                            <?php $no = 1; foreach ($semua_kategori as $k): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><strong><?= htmlspecialchars($k['NamaKategori']) ?></strong></td>
                                <td>
                                    <?php if ($k['jumlah_buku'] > 0): ?>
                                        <a href="buku.php" style="text-decoration:none;">
                                            <span class="badge badge-success"><?= $k['jumlah_buku'] ?> Buku</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge badge-info">0 Buku</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($k['jumlah_buku'] == 0): ?>
                                        <form method="POST" onsubmit="return confirm('Hapus kategori ini?')">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= $k['KategoriID'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️ Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px;color:var(--text-muted)">Ada buku — tidak bisa dihapus</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <p>Belum ada kategori. Tambahkan kategori baru!</p>
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

<!-- ===== MODAL TAMBAH KATEGORI ===== -->
<div class="modal-overlay" id="modalTambah">
    <div class="modal">
        <div class="modal-header">
            <h3>🏷️ Tambah Kategori Baru</h3>
            <button class="modal-close" onclick="closeModal('modalTambah')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="tambah">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Kategori *</label>
                    <input type="text" name="nama" placeholder="Contoh: Sains, Novel, Sejarah" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kategori</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
</script>
</body>
</html>
<?php $conn->close(); ?>
