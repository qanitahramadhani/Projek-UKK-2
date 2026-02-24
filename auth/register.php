<?php
// auth/register.php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (isLoggedIn()) { header('Location: /index.php'); exit; }

// Kode akses rahasia per role
define('KODE_PETUGAS', '12345');
define('KODE_ADMIN',   '67890');

$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama       = trim($_POST['nama'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $alamat     = trim($_POST['alamat'] ?? '');
    $password   = $_POST['password'] ?? '';
    $konfirm    = $_POST['konfirm'] ?? '';
    $role       = $_POST['role'] ?? '';
    $kodeAkses  = trim($_POST['kode_akses'] ?? '');

    $allowed_roles = ['administrator', 'petugas', 'peminjam'];

    if (!in_array($role, $allowed_roles)) {
        $error = 'Pilih peran yang valid!';
    } elseif ($role === 'administrator' && $kodeAkses !== KODE_ADMIN) {
        $error = 'Kode akses Admin tidak valid!';
    } elseif ($role === 'petugas' && $kodeAkses !== KODE_PETUGAS) {
        $error = 'Kode akses Petugas tidak valid!';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter!';
    } elseif ($password !== $konfirm) {
        $error = 'Password dan konfirmasi tidak cocok!';
    } else {
        $conn = getConnection();
        $cek = $conn->prepare("SELECT UserID FROM user WHERE Username=? OR Email=?");
        $cek->bind_param('ss', $username, $email);
        $cek->execute();
        if ($cek->get_result()->num_rows > 0) {
            $error = 'Username atau email sudah digunakan!';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO user (Username,Password,Email,NamaLengkap,Alamat,Role) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssssss', $username, $hash, $email, $nama, $alamat, $role);
            if ($stmt->execute()) {
                $success = 'Registrasi berhasil sebagai <strong>' . htmlspecialchars($role) . '</strong>! Silakan login.';
            } else {
                $error = 'Registrasi gagal, coba lagi.';
            }
        }
        $conn->close();
    }
}

$selectedRole = $_POST['role'] ?? 'peminjam';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrasi — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../public/css/auth.css">
<style>
/* ── Role selector cards ─────────────────────────────────── */
.role-selector{display:flex;gap:10px;margin-bottom:4px;}
.role-card{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:12px 8px;border:2px solid #e0ddf5;border-radius:12px;cursor:pointer;transition:all 0.2s;background:#fafafa;}
.role-card:hover{border-color:#7c6fcd;background:#f3f0ff;}
.role-card input[type="radio"]{display:none;}
.role-card .role-icon{font-size:22px;}
.role-card .role-label{font-size:12px;font-weight:600;color:#4a4a6a;}
.role-card .role-desc{font-size:10px;color:#888;text-align:center;}
.role-card.selected{border-color:#1a1a2e;background:#eee9ff;}
.role-card.selected .role-label{color:#1a1a2e;}
.alert-success strong{font-weight:700;}

/* ── Kode Akses field ────────────────────────────────────── */
.kode-akses-wrap{
    overflow:hidden;
    max-height:0;
    opacity:0;
    transition:max-height .35s ease, opacity .3s ease, margin .3s ease;
    margin-bottom:0;
}
.kode-akses-wrap.show{
    max-height:120px;
    opacity:1;
    margin-bottom:0;
}
.kode-hint{
    font-size:11px;color:#9ca3af;margin-top:5px;display:flex;align-items:center;gap:4px;
}
.kode-badge{
    display:inline-flex;align-items:center;gap:5px;
    background:linear-gradient(135deg,#fef3c7,#fff7ed);
    border:1.5px solid #fde68a;
    border-radius:8px;padding:8px 14px;margin-bottom:10px;
    font-size:12px;font-weight:600;color:#92400e;
    width:100%;box-sizing:border-box;
}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-art">
    <div class="art-inner">
      <span class="logo-mark">📚</span>
      <h1>Digi<br>Library</h1>
      <p>Bergabunglah dan nikmati kemudahan peminjaman buku secara digital.</p>
      <div class="art-features">
        <div class="art-feature"><div class="feat-icon">📚</div> Ribuan koleksi buku</div>
        <div class="art-feature"><div class="feat-icon">🔖</div> Kelola pinjaman mudah</div>
        <div class="art-feature"><div class="feat-icon">📋</div> Riwayat peminjaman lengkap</div>
      </div>
    </div>
    <div class="art-dots"></div>
  </div>

  <div class="login-form-side">
    <div class="form-card wide">
      <h2>Buat Akun Baru</h2>
      <p class="sub">Isi data diri Anda untuk mendaftar</p>

      <?php if ($error): ?><div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert-success">✅ <?= $success ?></div><?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST">

        <!-- ROLE SELECTOR -->
        <div class="field-group">
          <label>Daftar sebagai</label>
          <div class="role-selector">

            <label class="role-card <?= $selectedRole === 'administrator' ? 'selected' : '' ?>" onclick="selectRole(this)">
              <input type="radio" name="role" value="administrator"
                     <?= $selectedRole === 'administrator' ? 'checked' : '' ?> required>
              <span class="role-icon">🛡️</span>
              <span class="role-label">Admin</span>
              <span class="role-desc">Kelola sistem &amp; pengguna</span>
            </label>

            <label class="role-card <?= $selectedRole === 'petugas' ? 'selected' : '' ?>" onclick="selectRole(this)">
              <input type="radio" name="role" value="petugas"
                     <?= $selectedRole === 'petugas' ? 'checked' : '' ?>>
              <span class="role-icon">👨‍💼</span>
              <span class="role-label">Petugas</span>
              <span class="role-desc">Proses peminjaman buku</span>
            </label>

            <label class="role-card <?= $selectedRole === 'peminjam' ? 'selected' : '' ?>" onclick="selectRole(this)">
              <input type="radio" name="role" value="peminjam"
                     <?= $selectedRole === 'peminjam' ? 'checked' : '' ?>>
              <span class="role-icon">📖</span>
              <span class="role-label">Peminjam</span>
              <span class="role-desc">Pinjam &amp; kembalikan buku</span>
            </label>

          </div>
        </div>

        <!-- KODE AKSES (muncul hanya untuk admin & petugas) -->
        <div class="kode-akses-wrap <?= in_array($selectedRole, ['administrator','petugas']) ? 'show' : '' ?>"
             id="kodeAksesWrap">
          <div class="kode-badge">
            🔑 <span id="kodeAksesLabel">Masukkan kode akses khusus untuk melanjutkan</span>
          </div>
          <div class="field-group" style="margin-bottom:0;">
            <label>Kode Akses</label>
            <div class="input-wrap">
              <span class="input-icon">🔑</span>
              <input type="password" name="kode_akses" id="kodeAksesInput"
                     placeholder="Masukkan kode akses"
                     autocomplete="off"
                     value="">
            </div>
            <div class="kode-hint">🔒 Kode ini diberikan oleh pengembang sistem</div>
          </div>
        </div>

        <!-- DATA DIRI -->
        <div class="field-row">
          <div class="field-group">
            <label>Nama Lengkap</label>
            <input name="nama" placeholder="John Doe" required
                   value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label>Username</label>
            <input name="username" placeholder="johndoe" required
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>
        </div>

        <div class="field-group">
          <label>Email</label>
          <input type="email" name="email" placeholder="john@email.com" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="field-group">
          <label>Alamat</label>
          <textarea name="alamat" rows="2" placeholder="Alamat lengkap..."><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
        </div>

        <div class="field-row">
          <div class="field-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Min. 6 karakter" required>
          </div>
          <div class="field-group">
            <label>Konfirmasi Password</label>
            <input type="password" name="konfirm" placeholder="••••••••" required>
          </div>
        </div>

        <button type="submit" class="btn-primary">Daftar Sekarang →</button>
      </form>
      <?php else: ?>
        <a href="../auth/login.php" class="btn-primary"
           style="display:block;text-align:center;text-decoration:none;margin-top:16px">
          Masuk Sekarang →
        </a>
      <?php endif; ?>

      <p class="register-link">Sudah punya akun? <a href="../auth/login.php">Masuk di sini</a></p>
    </div>
  </div>
</div>

<script>
const labelMap = {
    administrator: '🛡️ Masukkan kode akses Admin untuk melanjutkan',
    petugas:       '👨‍💼 Masukkan kode akses Petugas untuk melanjutkan',
};

function selectRole(card) {
    document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    const radio = card.querySelector('input[type="radio"]');
    radio.checked = true;
    updateKodeAkses(radio.value);
}

function updateKodeAkses(role) {
    const wrap  = document.getElementById('kodeAksesWrap');
    const label = document.getElementById('kodeAksesLabel');
    const input = document.getElementById('kodeAksesInput');

    if (role === 'administrator' || role === 'petugas') {
        wrap.classList.add('show');
        label.textContent = labelMap[role] ?? 'Masukkan kode akses';
        input.required = true;
    } else {
        wrap.classList.remove('show');
        input.required = false;
        input.value = '';
    }
}

// Init on page load
document.addEventListener('DOMContentLoaded', () => {
    const checked = document.querySelector('.role-card input:checked');
    if (checked) {
        checked.closest('.role-card').classList.add('selected');
        updateKodeAkses(checked.value);
    }
});
</script>
</body>
</html>