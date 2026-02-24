<?php
// views/petugas/peminjaman.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('petugas');

$conn = getConnection();
$msg  = '';

// ─── Cover Base URL ────────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

// ─── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── CATAT PEMINJAMAN BARU ──────────────────────────────────────────────────
    if ($action === 'tambah') {
        $userId = (int)$_POST['user_id'];
        $bukuId = (int)$_POST['buku_id'];
        $tgl_p  = $_POST['tgl_pinjam'];
        $tgl_k  = $_POST['tgl_kembali'];

        $stok = $conn->query("SELECT Stok FROM buku WHERE BukuID=$bukuId")->fetch_row()[0] ?? 0;
        if ($stok < 1) {
            $msg = 'danger|Stok buku habis!';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO peminjaman
                    (UserID, BukuID, TanggalPeminjaman, TanggalPengembalian, StatusPeminjaman)
                VALUES (?, ?, ?, ?, 'dipinjam')
            ");
            $stmt->bind_param('iiss', $userId, $bukuId, $tgl_p, $tgl_k);
            if ($stmt->execute()) {
                $conn->query("UPDATE buku SET Stok = Stok - 1 WHERE BukuID = $bukuId");
                $msg = 'success|Peminjaman berhasil dicatat!';
            } else {
                $msg = 'danger|Gagal menyimpan: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

    // ── PROSES KEMBALIKAN ──────────────────────────────────────────────────────
    if ($action === 'kembalikan') {
        $id        = (int)$_POST['id'];
        $bukuId    = (int)$_POST['buku_id'];
        $dendaHari = (float)($_POST['denda_per_hari'] ?? 0);

        $rowP = $conn->query(
            "SELECT TanggalPengembalian FROM peminjaman WHERE PeminjamanID = $id"
        )->fetch_assoc();

        $totalDenda  = 0;
        $statusBayar = 'Lunas';
        $hariTelat   = 0;

        if ($rowP) {
            $jatuhTempo = strtotime($rowP['TanggalPengembalian']);
            $today      = strtotime('today');
            if ($jatuhTempo < $today) {
                $hariTelat   = (int) floor(($today - $jatuhTempo) / 86400);
                $totalDenda  = $hariTelat * $dendaHari;
                $statusBayar = 'Belum';
            }
        }

        $stmt = $conn->prepare("
            UPDATE peminjaman
            SET StatusPeminjaman     = 'dikembalikan',
                TanggalKembaliAktual = CURDATE(),
                TotalDenda           = ?,
                StatusBayarDenda     = ?
            WHERE PeminjamanID = ?
        ");
        $stmt->bind_param('dsi', $totalDenda, $statusBayar, $id);
        $stmt->execute();
        $stmt->close();

        $conn->query("UPDATE buku SET Stok = Stok + 1 WHERE BukuID = $bukuId");

        if ($totalDenda > 0) {
            $msg = 'warning|Buku dikembalikan terlambat ' . $hariTelat . ' hari. '
                 . 'Denda Rp ' . number_format($totalDenda, 0, ',', '.') . ' tercatat BELUM DIBAYAR.';
        } else {
            $msg = 'success|Buku berhasil dikembalikan. Tepat waktu, tidak ada denda!';
        }
    }
}

// ─── Query ─────────────────────────────────────────────────────────────────────
$data = $conn->query("
    SELECT p.PeminjamanID, p.BukuID,
           p.TanggalPeminjaman, p.TanggalPengembalian,
           p.StatusPeminjaman,
           COALESCE(p.TotalDenda, 0) AS TotalDenda,
           b.Judul, b.CoverURL, b.DendaPerHari,
           u.NamaLengkap
    FROM peminjaman p
    JOIN user u ON p.UserID = u.UserID
    JOIN buku b ON p.BukuID = b.BukuID
    WHERE p.StatusPeminjaman IN ('dipinjam', 'terlambat')
    ORDER BY p.TanggalPengembalian ASC
");

$users = $conn->query(
    "SELECT UserID, NamaLengkap FROM user WHERE Role = 'peminjam' ORDER BY NamaLengkap"
);
$buku  = $conn->query(
    "SELECT BukuID, Judul, Stok, CoverURL, DendaPerHari FROM buku WHERE Stok > 0 ORDER BY Judul"
);

if (!$data)  die("Query error: " . $conn->error);
if (!$users) die("Query users error: " . $conn->error);
if (!$buku)  die("Query buku error: " . $conn->error);

$msgArr  = $msg ? explode('|', $msg, 2) : ['', ''];
$msgType = $msgArr[0]; $msgText = $msgArr[1] ?? '';

function statusBadgePem($s) {
    switch (strtolower($s)) {
        case 'dipinjam':     return '<span class="badge badge-info">Dipinjam</span>';
        case 'dikembalikan': return '<span class="badge badge-success">Dikembalikan</span>';
        case 'terlambat':    return '<span class="badge badge-danger">Terlambat</span>';
        default:             return '<span class="badge">' . htmlspecialchars($s) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Proses Peminjaman — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb}

/* Cover identik dengan admin */
.cover-thumb{width:42px;height:58px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0;display:block}
.cover-placeholder{width:42px;height:58px;border-radius:5px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;background:#f8fafc}

/* Preview cover di modal */
#coverPreviewModal{display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-top:10px;min-height:82px}
#coverPreviewModal img{width:60px;height:82px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0}
.cover-empty-modal{width:60px;height:82px;border-radius:6px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:28px;color:#94a3b8;background:#fff}
#coverPreviewModal .cover-info{flex:1}
#coverPreviewModal .buku-title{font-weight:600;font-size:14px;color:#1e293b}
#coverPreviewModal .buku-stok{font-size:12px;color:#64748b;margin-top:4px}
#coverPreviewModal .buku-denda{font-size:12px;color:#d97706;font-weight:600;margin-top:2px}

/* Denda di tabel */
.denda-wrap{display:flex;flex-direction:column;gap:2px}
.denda-rate{font-size:12px;font-weight:700;color:#d97706}
.denda-est{font-size:11px;color:#ef4444}

/* Tombol kembalikan */
.btn-kembalikan{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .18s;white-space:nowrap}
.btn-kembalikan:hover{opacity:.85}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
      <h1>Proses Peminjaman</h1>
    </div>
    <div class="topbar-right">
      <a href="pengembalian.php" class="btn btn-outline" style="margin-right:8px">↩️ Riwayat Pengembalian</a>
      <button class="btn btn-primary" onclick="openModal('modalTambah')">+ Catat Peminjaman</button>
    </div>
  </div>

  <div class="page-content">

    <?php if ($msgText): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType === 'success' ? '✅' : ($msgType === 'warning' ? '⚠️' : '❌') ?>
        <?= htmlspecialchars($msgText) ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3>🔄 Daftar Peminjaman Aktif</h3>
      </div>
      <div class="card-body table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cover</th>
              <th>Buku</th>
              <th>Peminjam</th>
              <th>Tgl Pinjam</th>
              <th>Tgl Kembali</th>
              <th>Status</th>
              <th>Denda/Hari</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($data && $data->num_rows > 0):
                  $no = 1;
                  while ($d = $data->fetch_assoc()):
                    $jatuhTempo = strtotime($d['TanggalPengembalian']);
                    $today      = strtotime('today');
                    $terlambat  = $jatuhTempo < $today;
                    $hariTelat  = $terlambat ? (int) floor(($today - $jatuhTempo) / 86400) : 0;
                    $estimDenda = $hariTelat * (float)($d['DendaPerHari'] ?? 0);
            ?>
            <tr>
              <td><?= $no++ ?></td>
              <td>
                <?php if (!empty($d['CoverURL'])): ?>
                  <img src="<?= $coverBaseURL . htmlspecialchars($d['CoverURL']) ?>"
                       class="cover-thumb" alt="Cover"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="cover-placeholder" style="display:none">📚</div>
                <?php else: ?>
                  <div class="cover-placeholder">📚</div>
                <?php endif; ?>
              </td>
              <td><strong><?= htmlspecialchars($d['Judul']) ?></strong></td>
              <td><?= htmlspecialchars($d['NamaLengkap']) ?></td>
              <td><?= date('d/m/Y', strtotime($d['TanggalPeminjaman'])) ?></td>
              <td>
                <span style="color:<?= $terlambat ? '#ef4444' : 'inherit' ?>;font-weight:<?= $terlambat ? '600' : '400' ?>">
                  <?= date('d/m/Y', $jatuhTempo) ?>
                </span>
                <?php if ($terlambat): ?>
                  <br><span class="badge badge-danger" style="font-size:10px;margin-top:3px">
                    Terlambat <?= $hariTelat ?> hari
                  </span>
                <?php endif; ?>
              </td>
              <td><?= statusBadgePem($terlambat ? 'terlambat' : $d['StatusPeminjaman']) ?></td>
              <td>
                <div class="denda-wrap">
                  <span class="denda-rate">Rp <?= number_format((float)($d['DendaPerHari'] ?? 0), 0, ',', '.') ?>/hari</span>
                  <?php if ($terlambat && $estimDenda > 0): ?>
                    <span class="denda-est">Estimasi: Rp <?= number_format($estimDenda, 0, ',', '.') ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <form method="POST" onsubmit="return konfirmasiKembali('<?= addslashes(htmlspecialchars($d['Judul'])) ?>','<?= addslashes(htmlspecialchars($d['NamaLengkap'])) ?>',<?= (int)$terlambat ?>,<?= $hariTelat ?>,<?= $estimDenda ?>)">
                  <input type="hidden" name="action"          value="kembalikan">
                  <input type="hidden" name="id"              value="<?= $d['PeminjamanID'] ?>">
                  <input type="hidden" name="buku_id"         value="<?= $d['BukuID'] ?>">
                  <input type="hidden" name="denda_per_hari"  value="<?= (float)($d['DendaPerHari'] ?? 0) ?>">
                  <button type="submit" class="btn-kembalikan">↩️ Kembalikan</button>
                </form>
              </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="9"><div class="empty-state"><div class="empty-icon">✅</div><p>Tidak ada peminjaman aktif.</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal Catat Peminjaman Baru -->
<div class="modal-overlay" id="modalTambah">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3>🔄 Catat Peminjaman Baru</h3>
      <button class="modal-close" onclick="closeModal('modalTambah')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="tambah">
      <div class="modal-body">
        <div class="form-group">
          <label>Peminjam *</label>
          <select name="user_id" required>
            <option value="">-- Pilih Anggota --</option>
            <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
              <option value="<?= $u['UserID'] ?>"><?= htmlspecialchars($u['NamaLengkap']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Buku *</label>
          <select name="buku_id" id="selectBuku" required onchange="updateCoverPreview(this)">
            <option value="">-- Pilih Buku (stok tersedia) --</option>
            <?php $buku->data_seek(0); while ($b = $buku->fetch_assoc()): ?>
              <option value="<?= $b['BukuID'] ?>"
                      data-cover="<?= htmlspecialchars($b['CoverURL'] ?? '') ?>"
                      data-stok="<?= $b['Stok'] ?>"
                      data-judul="<?= htmlspecialchars($b['Judul']) ?>"
                      data-denda="<?= number_format((float)($b['DendaPerHari'] ?? 0), 0, ',', '.') ?>">
                <?= htmlspecialchars($b['Judul']) ?> (Stok: <?= $b['Stok'] ?>)
              </option>
            <?php endwhile; ?>
          </select>
          <div id="coverPreviewModal">
            <div class="cover-empty-modal">📚</div>
            <div class="cover-info">
              <div class="buku-title" id="previewTitle">Pilih buku untuk melihat preview</div>
              <div class="buku-stok"  id="previewStok"></div>
              <div class="buku-denda" id="previewDenda"></div>
            </div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Tanggal Pinjam *</label>
            <input type="date" name="tgl_pinjam" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Tanggal Kembali *</label>
            <input type="date" name="tgl_kembali" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalTambah')">Batal</button>
        <button type="submit" class="btn btn-primary">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php $conn->close(); ?>
<script>
const COVER_BASE = '<?= $coverBaseURL ?>';

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

function updateCoverPreview(sel) {
    const opt   = sel.options[sel.selectedIndex];
    const cover = opt.getAttribute('data-cover') || '';
    const judul = opt.getAttribute('data-judul') || 'Pilih buku untuk melihat preview';
    const stok  = opt.getAttribute('data-stok')  || '';
    const denda = opt.getAttribute('data-denda') || '';
    const box   = document.getElementById('coverPreviewModal');
    document.getElementById('previewTitle').textContent = judul;
    document.getElementById('previewStok').textContent  = stok  ? `Stok tersedia: ${stok} buku` : '';
    document.getElementById('previewDenda').textContent = denda ? `Denda/hari: Rp ${denda}` : '';
    const oldImg   = box.querySelector('img');
    const oldEmpty = box.querySelector('.cover-empty-modal');
    if (oldImg)   oldImg.remove();
    if (oldEmpty) oldEmpty.remove();
    if (cover) {
        const img   = document.createElement('img');
        img.src     = COVER_BASE + cover;
        img.onerror = function() {
            this.remove();
            const e = document.createElement('div');
            e.className = 'cover-empty-modal'; e.textContent = '📚';
            box.prepend(e);
        };
        box.prepend(img);
    } else {
        const e = document.createElement('div');
        e.className = 'cover-empty-modal'; e.textContent = '📚';
        box.prepend(e);
    }
}

function konfirmasiKembali(judul, nama, terlambat, hariTelat, estimDenda) {
    if (terlambat) {
        const fmt = 'Rp ' + Number(estimDenda).toLocaleString('id-ID');
        return confirm(
            '⚠️ PERHATIAN — Pengembalian Terlambat!\n\n' +
            'Buku     : ' + judul + '\n' +
            'Peminjam : ' + nama  + '\n' +
            'Terlambat: ' + hariTelat + ' hari\n' +
            'Denda    : ' + fmt   + '\n\n' +
            'Denda akan tercatat BELUM DIBAYAR di halaman pengembalian.\n' +
            'Lanjutkan proses pengembalian?'
        );
    }
    return confirm('Konfirmasi pengembalian buku "' + judul + '" oleh ' + nama + '?');
}
</script>
</body>
</html>
