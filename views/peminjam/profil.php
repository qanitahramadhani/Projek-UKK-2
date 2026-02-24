<?php
// views/peminjam/profil.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn = getConnection();
$uid  = getUserId();
$msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama   = trim($_POST['nama']   ?? '');
    $email  = trim($_POST['email']  ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $pass   = $_POST['password'] ?? '';

    if (empty($nama) || empty($email)) {
        $msg = 'danger|Nama dan email tidak boleh kosong.';
    } else {
        if ($pass) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE user SET NamaLengkap=?, Email=?, Alamat=?, Password=? WHERE UserID=?");
            $stmt->bind_param('ssssi', $nama, $email, $alamat, $hash, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE user SET NamaLengkap=?, Email=?, Alamat=? WHERE UserID=?");
            $stmt->bind_param('sssi', $nama, $email, $alamat, $uid);
        }

        if ($stmt->execute()) {
            $_SESSION['nama']  = $nama;
            $_SESSION['email'] = $email;
            $msg = 'success|Profil berhasil diperbarui!';
        } else {
            $msg = 'danger|Gagal: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Ambil data user terbaru dari DB
$res  = $conn->query("SELECT * FROM user WHERE UserID='$uid'");
$user = $res ? $res->fetch_assoc() : [];

$conn->close();
[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];

// Helper: ambil nilai kolom dengan fallback key
function get_val($data, $primary_key, $secondary_key = '') {
    if (isset($data[$primary_key])) return htmlspecialchars($data[$primary_key]);
    if ($secondary_key && isset($data[$secondary_key])) return htmlspecialchars($data[$secondary_key]);
    return '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profil Saya — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .alert-success { background:#d4edda; color:#155724; }
        .alert-danger  { background:#f8d7da; color:#721c24; }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Profil Saya</h1>
    </div>
  </div>

  <div class="page-content">
    <?php if ($msgText): ?>
        <div class="alert alert-<?= $msgType ?>">
          <?= $msgType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($msgText) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width:540px">
      <div style="padding:32px 28px 0;display:flex;align-items:center;gap:20px">
        <div class="avatar" style="width:64px;height:64px;font-size:26px"><?= getInitial() ?></div>
        <div>
          <div style="font-family:'Playfair Display',serif;font-size:22px;color:var(--ink)">
            <?= get_val($user, 'NamaLengkap', 'nama_lengkap') ?>
          </div>
          <div style="font-size:13px;color:var(--text)">
            <?= get_val($user, 'Email', 'email') ?>
          </div>
          <span class="badge badge-info" style="margin-top:6px">Peminjam</span>
        </div>
      </div>

      <div class="modal-body" style="padding:24px 28px 28px">
        <form method="POST">
          <div class="form-group">
              <label>Nama Lengkap *</label>
              <input name="nama" required value="<?= get_val($user, 'NamaLengkap', 'nama_lengkap') ?>">
          </div>
          <div class="form-group">
              <label>Email *</label>
              <input type="email" name="email" required value="<?= get_val($user, 'Email', 'email') ?>">
          </div>
          <div class="form-group">
              <label>Alamat</label>
              <textarea name="alamat" rows="3"><?= get_val($user, 'Alamat', 'alamat') ?></textarea>
          </div>
          <div class="form-group">
            <label>Password Baru <span style="font-weight:400;text-transform:none;color:var(--text)">(kosongkan jika tidak ingin ubah)</span></label>
            <input type="password" name="password" placeholder="Min. 6 karakter" minlength="6">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;padding:13px">💾 Simpan Perubahan</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>