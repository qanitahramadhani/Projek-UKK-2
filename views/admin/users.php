<?php
// views/admin/users.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('administrator');

$conn = getConnection();
$msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Tambah Petugas ───────────────────────────────────────────────────────
    if ($action === 'tambah_petugas') {
        $nama     = trim($_POST['nama']     ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';

        if (!$nama || !$username || !$email || !$password) {
            $msg = 'danger|Semua field wajib diisi!';
        } else {
            $cek = $conn->prepare("SELECT UserID FROM user WHERE Username=? OR Email=?");
            $cek->bind_param('ss', $username, $email);
            $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                $msg = 'danger|Username atau email sudah digunakan!';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare(
                    "INSERT INTO user (Username, Password, Email, NamaLengkap, Role, Status)
                     VALUES (?, ?, ?, ?, 'petugas', 'aktif')"
                );
                $stmt->bind_param('ssss', $username, $hash, $email, $nama);
                $msg = $stmt->execute()
                    ? 'success|Petugas berhasil ditambahkan!'
                    : 'danger|Gagal: ' . $conn->error;
            }
        }
    }

    // ── Tambah Peminjam ──────────────────────────────────────────────────────
    if ($action === 'tambah_peminjam') {
        $nama     = trim($_POST['nama_p']     ?? '');
        $username = trim($_POST['username_p'] ?? '');
        $email    = trim($_POST['email_p']    ?? '');
        $password = $_POST['password_p']      ?? '';

        if (!$nama || !$username || !$email || !$password) {
            $msg = 'danger|Semua field wajib diisi!';
        } else {
            $cek = $conn->prepare("SELECT UserID FROM user WHERE Username=? OR Email=?");
            $cek->bind_param('ss', $username, $email);
            $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                $msg = 'danger|Username atau email sudah digunakan!';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare(
                    "INSERT INTO user (Username, Password, Email, NamaLengkap, Role, Status)
                     VALUES (?, ?, ?, ?, 'peminjam', 'aktif')"
                );
                $stmt->bind_param('ssss', $username, $hash, $email, $nama);
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
        $conn->query("UPDATE user SET Status='$status' WHERE UserID=$id");
        $msg = "success|Status berhasil diubah menjadi $status.";
    }

    // ── Hapus ────────────────────────────────────────────────────────────────
    if ($action === 'hapus') {
        $id = (int)$_POST['id'];
        if ($id === getUserId()) {
            $msg = 'danger|Tidak dapat menghapus akun sendiri!';
        } else {
            // Cek peminjaman aktif (khusus peminjam)
            $roleRow = $conn->query("SELECT Role FROM user WHERE UserID=$id")->fetch_assoc();
            if ($roleRow && $roleRow['Role'] === 'peminjam') {
                $cekP = $conn->query(
                    "SELECT COUNT(*) as total FROM peminjaman
                     WHERE UserID=$id AND StatusPeminjaman IN ('dipinjam','terlambat')"
                );
                if ($cekP->fetch_assoc()['total'] > 0) {
                    $msg = 'danger|Tidak bisa menghapus peminjam yang masih memiliki peminjaman aktif!';
                } else {
                    $conn->query("DELETE FROM user WHERE UserID=$id");
                    $msg = 'success|User berhasil dihapus.';
                }
            } else {
                $conn->query("DELETE FROM user WHERE UserID=$id AND Role!='administrator'");
                $msg = 'success|User berhasil dihapus.';
            }
        }
    }
}

// ── Query semua user ──────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$whereArr  = [];
if ($search) $whereArr[] = "(NamaLengkap LIKE '%$search%' OR Username LIKE '%$search%' OR Email LIKE '%$search%')";
$whereSQL  = $whereArr ? 'AND ' . implode(' AND ', $whereArr) : '';

// Admin & Petugas
$staff = $conn->query(
    "SELECT * FROM user
     WHERE Role IN ('administrator','petugas') $whereSQL
     ORDER BY Role ASC, NamaLengkap ASC"
);
if (!$staff) die("Query gagal: " . $conn->error);

// Peminjam — sertakan stats peminjaman
$peminjam = $conn->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM peminjaman p
             WHERE p.UserID = u.UserID AND p.StatusPeminjaman IN ('dipinjam','terlambat')) AS aktif_pinjam,
            (SELECT COUNT(*) FROM peminjaman p
             WHERE p.UserID = u.UserID) AS total_pinjam
     FROM user u
     WHERE u.Role='peminjam' $whereSQL
     ORDER BY u.CreatedAt DESC"
);
if (!$peminjam) die("Query gagal: " . $conn->error);

$semua_staff    = [];
while ($r = $staff->fetch_assoc())    $semua_staff[]    = $r;
$semua_peminjam = [];
while ($r = $peminjam->fetch_assoc()) $semua_peminjam[] = $r;

[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kelola User — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
        /* Pemisah section */
        .section-label {
            display: flex; align-items: center; gap: 12px;
            margin: 30px 0 14px;
        }
        .section-label h2 {
            font-size: 17px; font-weight: 700; color: #1e293b;
            white-space: nowrap; margin: 0;
        }
        .section-label .divider {
            flex: 1; height: 1px; background: #e2e8f0;
        }
        .section-label .count-pill {
            background: #ede9fe; color: #7c3aed;
            font-size: 12px; font-weight: 700;
            padding: 2px 10px; border-radius: 20px;
        }

        /* Avatar */
        .avatar-sm {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg,#6366f1,#8b5cf6);
            color: #fff; font-size: 13px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .avatar-sm.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
        .avatar-sm.blue  { background: linear-gradient(135deg,#3b82f6,#1d4ed8); }

        /* Pinjam badge */
        .pinjam-badge {
            font-size: 11px; padding: 2px 9px; border-radius: 20px;
            background: #f1f5f9; color: #64748b; font-weight: 600;
        }
        .pinjam-badge.ada { background: #fef3c7; color: #b45309; }

        /* Buttons */
        .btn-warning { background: #f59e0b; color: #fff; border: none; }
        .btn-warning:hover { background: #d97706; }
        .btn-success { background: #22c55e; color: #fff; border: none; }
        .btn-success:hover { background: #16a34a; }

        /* Search global */
        .top-search-wrap {
            display: flex; gap: 10px; align-items: center;
            margin-bottom: 20px;
        }
        .top-search-wrap input {
            flex: 1; padding: 9px 14px; border-radius: 8px;
            border: 1px solid #e2e8f0; font-size: 14px;
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Kelola User</h1>
        </div>
        <div class="topbar-right" style="gap:8px;">
            <button class="btn btn-outline" onclick="openModal('modalTambahPeminjam')">+ Peminjam</button>
            <button class="btn btn-primary"  onclick="openModal('modalTambahPetugas')">+ Petugas</button>
        </div>
    </div>

    <div class="page-content">

        <?php if ($msgText): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($msgText) ?>
            </div>
        <?php endif; ?>

        <!-- Pencarian global -->
        <form method="GET" class="top-search-wrap">
            <input type="text" name="q"
                   placeholder="🔍 Cari semua user (nama / username / email)..."
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-outline">Cari</button>
            <?php if ($search): ?>
                <a href="users.php" class="btn btn-sm">Reset</a>
            <?php endif; ?>
        </form>


        <!-- ══════════════════════════════════════════════════════════
             TABEL 1: ADMINISTRATOR & PETUGAS
        ══════════════════════════════════════════════════════════ -->
        <div class="section-label">
            <h2>⚙️ Administrator & Petugas</h2>
            <div class="divider"></div>
            <span class="count-pill"><?= count($semua_staff) ?> User</span>
        </div>

        <div class="card" style="margin-bottom:8px;">
            <div class="card-body table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Bergabung</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($semua_staff) > 0): ?>
                        <?php $no = 1; foreach ($semua_staff as $u): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="avatar-sm <?= $u['Role'] === 'administrator' ? '' : 'blue' ?>">
                                        <?= strtoupper(substr($u['NamaLengkap'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($u['NamaLengkap']) ?>
                                    <?php if ($u['UserID'] === getUserId()): ?>
                                        <span style="font-size:11px;color:#94a3b8;">(Anda)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($u['Username']) ?></code></td>
                            <td><?= htmlspecialchars($u['Email']) ?></td>
                            <td>
                                <span class="badge <?= $u['Role'] === 'administrator' ? 'badge-warning' : 'badge-info' ?>">
                                    <?= ucfirst($u['Role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $u['Status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= ucfirst($u['Status'] ?? 'aktif') ?>
                                </span>
                            </td>
                            <td><?= isset($u['CreatedAt']) ? date('d/m/Y', strtotime($u['CreatedAt'])) : '-' ?></td>
                            <td>
                                <?php if ($u['UserID'] !== getUserId() && $u['Role'] === 'petugas'): ?>
                                    <div style="display:flex;gap:6px;">
                                        <!-- Toggle status -->
                                        <form method="POST">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id"     value="<?= $u['UserID'] ?>">
                                            <input type="hidden" name="status" value="<?= $u['Status'] ?? 'aktif' ?>">
                                            <button type="submit"
                                                    class="btn btn-sm <?= ($u['Status'] ?? 'aktif') === 'aktif' ? 'btn-warning' : 'btn-success' ?>">
                                                <?= ($u['Status'] ?? 'aktif') === 'aktif' ? '🚫 Nonaktif' : '✅ Aktifkan' ?>
                                            </button>
                                        </form>
                                        <!-- Hapus -->
                                        <form method="POST"
                                              onsubmit="return confirm('Hapus petugas <?= htmlspecialchars(addslashes($u['NamaLengkap'])) ?>?')">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id"     value="<?= $u['UserID'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state"><p>Tidak ada data staff ditemukan.</p></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- ══════════════════════════════════════════════════════════
             TABEL 2: ANGGOTA PEMINJAM
        ══════════════════════════════════════════════════════════ -->
        <div class="section-label">
            <h2>👤 Anggota Peminjam</h2>
            <div class="divider"></div>
            <span class="count-pill" style="background:#dcfce7;color:#166534;">
                <?= count($semua_peminjam) ?> Anggota
            </span>
        </div>

        <div class="card">
            <div class="card-body table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Peminjaman</th>
                            <th>Status</th>
                            <th>Bergabung</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($semua_peminjam) > 0): ?>
                        <?php $no = 1; foreach ($semua_peminjam as $u): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="avatar-sm green">
                                        <?= strtoupper(substr($u['NamaLengkap'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($u['NamaLengkap']) ?>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($u['Username']) ?></code></td>
                            <td><?= htmlspecialchars($u['Email']) ?></td>
                            <td>
                                <span class="pinjam-badge <?= $u['aktif_pinjam'] > 0 ? 'ada' : '' ?>">
                                    <?= $u['aktif_pinjam'] > 0
                                        ? '📖 ' . $u['aktif_pinjam'] . ' Aktif'
                                        : $u['total_pinjam'] . ' Total Pinjam' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $u['Status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= ucfirst($u['Status']) ?>
                                </span>
                            </td>
                            <td><?= isset($u['CreatedAt']) ? date('d/m/Y', strtotime($u['CreatedAt'])) : '-' ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <!-- Toggle Status -->
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id"     value="<?= $u['UserID'] ?>">
                                        <input type="hidden" name="status" value="<?= $u['Status'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $u['Status'] === 'aktif' ? 'btn-warning' : 'btn-success' ?>">
                                            <?= $u['Status'] === 'aktif' ? '🚫 Nonaktif' : '✅ Aktifkan' ?>
                                        </button>
                                    </form>
                                    <!-- Hapus -->
                                    <form method="POST"
                                          onsubmit="return confirm('Hapus anggota <?= htmlspecialchars(addslashes($u['NamaLengkap'])) ?>?')">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id"     value="<?= $u['UserID'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                <?= $u['aktif_pinjam'] > 0 ? 'disabled title="Masih ada peminjaman aktif"' : '' ?>>
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
                                    <p>Tidak ada anggota peminjam ditemukan.</p>
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
     MODAL TAMBAH PETUGAS
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalTambahPetugas">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>⚙️ Tambah Petugas</h3>
            <button class="modal-close" onclick="closeModal('modalTambahPetugas')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="tambah_petugas">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="nama" placeholder="Nama lengkap petugas" required>
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
                <div style="background:#eff6ff;border-radius:8px;padding:10px 14px;
                            font-size:12px;color:#1e40af;border-left:3px solid #3b82f6;margin-top:4px;">
                    ℹ️ Akun akan dibuat dengan role <strong>Petugas</strong> dan status <strong>Aktif</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalTambahPetugas')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Petugas</button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════
     MODAL TAMBAH PEMINJAM
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalTambahPeminjam">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>👥 Tambah Anggota Peminjam</h3>
            <button class="modal-close" onclick="closeModal('modalTambahPeminjam')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="tambah_peminjam">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="nama_p" placeholder="Nama lengkap anggota" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username_p" placeholder="username unik" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email_p" placeholder="email@contoh.com" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password_p" placeholder="Minimal 6 karakter" required minlength="6">
                </div>
                <div style="background:#f0fdf4;border-radius:8px;padding:10px 14px;
                            font-size:12px;color:#166534;border-left:3px solid #22c55e;margin-top:4px;">
                    ℹ️ Akun akan dibuat dengan role <strong>Peminjam</strong> dan status <strong>Aktif</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalTambahPeminjam')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Buat Akun Peminjam</button>
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
