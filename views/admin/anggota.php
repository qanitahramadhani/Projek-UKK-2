<?php
// views/admin/anggota.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();
$msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Tambah Anggota Peminjam ──────────────────────────────────────────────
    if ($action === 'tambah_anggota') {
        $nama     = trim($_POST['nama']     ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';

        if (!$nama || !$username || !$email || !$password) {
            $msg = 'danger|Semua field wajib diisi!';
        } else {
            // Cek username/email sudah ada
            $cek = $conn->prepare("SELECT UserID FROM user WHERE Username=? OR Email=?");
            $cek->bind_param('ss', $username, $email);
            $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                $msg = 'danger|Username atau email sudah digunakan!';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare(
                    "INSERT INTO user (NamaLengkap, Username, Email, Password, Role, Status)
                     VALUES (?, ?, ?, ?, 'peminjam', 'aktif')"
                );
                $stmt->bind_param('ssss', $nama, $username, $email, $hash);
                $msg = $stmt->execute()
                    ? 'success|Anggota peminjam berhasil ditambahkan!'
                    : 'danger|Gagal: ' . $conn->error;
            }
        }
    }

    // ── Toggle Status ────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        $id     = (int)$_POST['id'];
        $status = $_POST['status'] === 'aktif' ? 'nonaktif' : 'aktif';
        $conn->query("UPDATE user SET Status='$status' WHERE UserID=$id AND Role='peminjam'");
        $msg = "success|Status berhasil diubah menjadi $status.";
    }

    // ── Hapus ────────────────────────────────────────────────────────────────
    if ($action === 'hapus') {
        $id = (int)$_POST['id'];
        // Cek apakah masih punya peminjaman aktif
        $cekPinjam = $conn->query(
            "SELECT COUNT(*) as total FROM peminjaman
             WHERE UserID=$id AND StatusPeminjaman IN ('dipinjam','terlambat')"
        );
        $totalPinjam = $cekPinjam->fetch_assoc()['total'];
        if ($totalPinjam > 0) {
            $msg = "danger|Tidak bisa menghapus anggota yang masih memiliki $totalPinjam peminjaman aktif!";
        } else {
            $conn->query("DELETE FROM user WHERE UserID=$id AND Role='peminjam'");
            $msg = 'success|Anggota berhasil dihapus.';
        }
    }
}

// ── Query ─────────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = $search
    ? "AND (NamaLengkap LIKE '%$search%' OR Username LIKE '%$search%' OR Email LIKE '%$search%')"
    : '';

$anggota = $conn->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM peminjaman p
             WHERE p.UserID = u.UserID AND p.StatusPeminjaman IN ('dipinjam','terlambat')) AS aktif_pinjam,
            (SELECT COUNT(*) FROM peminjaman p
             WHERE p.UserID = u.UserID) AS total_pinjam
     FROM user u
     WHERE u.Role='peminjam' $where
     ORDER BY u.CreatedAt DESC"
);
if (!$anggota) die("Query gagal: " . $conn->error);

[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];

// Simpan ke array
$semua_anggota = [];
while ($row = $anggota->fetch_assoc()) $semua_anggota[] = $row;
$total_anggota = count($semua_anggota);
$total_aktif   = 0;
foreach ($semua_anggota as $__a) { if ($__a["Status"] === "aktif") $total_aktif++; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kelola Anggota — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
        .stat-mini {
            display: flex; gap: 14px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .stat-mini-card {
            background: #fff;
            border-radius: 12px;
            padding: 14px 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            border: 1px solid #f1f5f9;
            min-width: 140px; flex: 1;
        }
        .stat-mini-card .val {
            font-size: 26px; font-weight: 700; color: var(--primary, #6366f1);
        }
        .stat-mini-card .lbl { font-size: 12px; color: #94a3b8; margin-top: 2px; }

        .avatar-sm {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg,#6366f1,#8b5cf6);
            color: #fff; font-size: 13px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .pinjam-badge {
            font-size: 11px; padding: 2px 8px; border-radius: 20px;
            background: #ede9fe; color: #7c3aed; font-weight: 600;
        }
        .pinjam-badge.ada { background: #fef3c7; color: #b45309; }

        .btn-warning { background: #f59e0b; color: #fff; border: none; }
        .btn-warning:hover { background: #d97706; }
        .btn-success { background: #22c55e; color: #fff; border: none; }
        .btn-success:hover { background: #16a34a; }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Kelola Anggota</h1>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary" onclick="openModal('modalTambah')">+ Tambah Anggota</button>
        </div>
    </div>

    <div class="page-content">

        <?php if ($msgText): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($msgText) ?>
            </div>
        <?php endif; ?>

        <!-- Statistik mini -->
        <div class="stat-mini">
            <div class="stat-mini-card">
                <div class="val"><?= $total_anggota ?></div>
                <div class="lbl">Total Anggota</div>
            </div>
            <div class="stat-mini-card">
                <div class="val" style="color:#22c55e;"><?= $total_aktif ?></div>
                <div class="lbl">Anggota Aktif</div>
            </div>
            <div class="stat-mini-card">
                <div class="val" style="color:#ef4444;"><?= $total_anggota - $total_aktif ?></div>
                <div class="lbl">Anggota Nonaktif</div>
            </div>
        </div>

        <!-- Tabel Anggota -->
        <div class="card">
            <div class="card-header">
                <h3>👥 Daftar Anggota Peminjam</h3>
                <form method="GET" class="search-bar">
                    <input type="text" name="q"
                           placeholder="Cari nama / username / email..."
                           value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-outline">Cari</button>
                    <?php if ($search): ?>
                        <a href="anggota.php" class="btn btn-sm">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Anggota</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Peminjaman</th>
                            <th>Status</th>
                            <th>Bergabung</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($semua_anggota) > 0): ?>
                        <?php $no = 1; foreach ($semua_anggota as $a): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="avatar-sm"><?= strtoupper(substr($a['NamaLengkap'], 0, 1)) ?></div>
                                    <span><?= htmlspecialchars($a['NamaLengkap']) ?></span>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($a['Username']) ?></code></td>
                            <td><?= htmlspecialchars($a['Email']) ?></td>
                            <td>
                                <span class="pinjam-badge <?= $a['aktif_pinjam'] > 0 ? 'ada' : '' ?>">
                                    <?= $a['aktif_pinjam'] > 0
                                        ? '📖 ' . $a['aktif_pinjam'] . ' Aktif'
                                        : $a['total_pinjam'] . ' Total' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $a['Status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= ucfirst($a['Status']) ?>
                                </span>
                            </td>
                            <td><?= isset($a['CreatedAt']) ? date('d/m/Y', strtotime($a['CreatedAt'])) : '-' ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <!-- Toggle Status -->
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id"     value="<?= $a['UserID'] ?>">
                                        <input type="hidden" name="status" value="<?= $a['Status'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $a['Status'] === 'aktif' ? 'btn-warning' : 'btn-success' ?>">
                                            <?= $a['Status'] === 'aktif' ? '🚫 Nonaktif' : '✅ Aktifkan' ?>
                                        </button>
                                    </form>
                                    <!-- Hapus -->
                                    <form method="POST"
                                          onsubmit="return confirm('Hapus anggota <?= htmlspecialchars(addslashes($a['NamaLengkap'])) ?>?')">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id"     value="<?= $a['UserID'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                <?= $a['aktif_pinjam'] > 0 ? 'disabled title="Masih ada peminjaman aktif"' : '' ?>>
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <div class="empty-icon">👥</div>
                                    <p>Tidak ada anggota ditemukan.</p>
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


<!-- ═══════════════════════════════════════════════
     MODAL TAMBAH ANGGOTA PEMINJAM
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalTambah">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3>👤 Tambah Anggota Peminjam</h3>
            <button class="modal-close" onclick="closeModal('modalTambah')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="tambah_anggota">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="nama" placeholder="Masukkan nama lengkap" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" placeholder="username unik" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" placeholder="email@contoh.com" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" placeholder="Minimal 6 karakter" required minlength="6">
                </div>
                <div style="background:#f0fdf4;border-radius:8px;padding:10px 14px;
                            font-size:12px;color:#166534;border-left:3px solid #22c55e;margin-top:4px;">
                    ℹ️ Akun akan dibuat dengan role <strong>Peminjam</strong> dan status <strong>Aktif</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Buat Akun Anggota</button>
            </div>
        </form>
    </div>
</div>


<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open');    }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    })
);
</script>
</body>
</html>
<?php $conn->close(); ?>
