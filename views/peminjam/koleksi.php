<?php
// views/peminjam/koleksi.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn = getConnection();
$uid  = getUserId();
$msg  = ''; $msgType = '';

// ─── Cover Base URL ────────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $bid = intval($_POST['buku_id'] ?? 0);
        if ($bid > 0) {
            $cek = $conn->query("SELECT KoleksiID FROM koleksipribadi WHERE UserID=$uid AND BukuID=$bid")->num_rows;
            if ($cek > 0) { $msg = 'Buku sudah ada di koleksi!'; $msgType = 'warning'; }
            else {
                $stmt = $conn->prepare("INSERT INTO koleksipribadi (UserID,BukuID) VALUES (?,?)");
                $stmt->bind_param('ii', $uid, $bid); $stmt->execute(); $stmt->close();
                $msg = 'Ditambahkan ke koleksi!'; $msgType = 'success';
            }
        }
    } elseif ($action === 'hapus') {
        $kid = intval($_POST['id'] ?? 0);
        if ($kid > 0) { $conn->query("DELETE FROM koleksipribadi WHERE KoleksiID=$kid AND UserID=$uid"); $msg = 'Dihapus dari koleksi!'; $msgType = 'success'; }
    } elseif ($action === 'pinjam') {
        $BukuID     = intval($_POST['buku_id'] ?? 0);
        $tglPinjam  = $_POST['tgl_pinjam']  ?? date('Y-m-d');
        $tglKembali = $_POST['tgl_kembali'] ?? date('Y-m-d', strtotime('+7 days'));
        $todayStr   = date('Y-m-d');

        if ($tglPinjam < $todayStr) { $msg = 'Tanggal pinjam tidak boleh sebelum hari ini.'; $msgType = 'danger'; }
        elseif ($tglKembali <= $tglPinjam) { $msg = 'Tanggal pengembalian harus setelah tanggal pinjam.'; $msgType = 'danger'; }
        elseif ($BukuID > 0) {
            $cekStok  = $conn->query("SELECT Stok FROM buku WHERE BukuID=$BukuID")->fetch_assoc();
            $cekAktif = $conn->query("SELECT PeminjamanID FROM peminjaman WHERE UserID=$uid AND BukuID=$BukuID AND StatusPeminjaman='dipinjam'")->num_rows;
            if (!$cekStok || $cekStok['Stok'] < 1) { $msg = 'Stok buku sudah habis.'; $msgType = 'danger'; }
            elseif ($cekAktif > 0) { $msg = 'Anda masih meminjam buku ini dan belum mengembalikannya.'; $msgType = 'warning'; }
            else {
                $stmt = $conn->prepare("INSERT INTO peminjaman (UserID,BukuID,TanggalPeminjaman,TanggalPengembalian,StatusPeminjaman) VALUES (?,?,?,?,'dipinjam')");
                $stmt->bind_param('iiss', $uid, $BukuID, $tglPinjam, $tglKembali);
                if ($stmt->execute()) { $conn->query("UPDATE buku SET Stok=Stok-1 WHERE BukuID=$BukuID"); $msg = 'Buku berhasil dipinjam! Silakan cek menu Peminjaman.'; $msgType = 'success'; }
                else { $msg = 'Gagal meminjam: ' . $conn->error; $msgType = 'danger'; }
                $stmt->close();
            }
        }
    }
}

$koleksi = $conn->query("
    SELECT kp.KoleksiID, b.BukuID, b.Judul, b.Penulis, b.Penerbit, b.TahunTerbit, b.Stok, b.CoverURL, k.NamaKategori AS Kategori
    FROM koleksipribadi kp JOIN buku b ON kp.BukuID=b.BukuID
    LEFT JOIN kategoribuku k ON b.KategoriID=k.KategoriID
    WHERE kp.UserID=$uid ORDER BY kp.KoleksiID DESC
");
if (!$koleksi) die("Kesalahan Database: " . $conn->error);

$activePage = 'koleksi';
$today      = date('Y-m-d');
$maxDate    = date('Y-m-d', strtotime('+60 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Koleksi Saya — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
/* ── Grid koleksi ── */
.koleksi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;}
.koleksi-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s;}
.koleksi-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);}

/* Cover */
.koleksi-cover{
    height:180px;
    position:relative;
    overflow:hidden;
    background:linear-gradient(135deg,#1e1e2f,#3a3a5c);
    display:flex;align-items:center;justify-content:center;
}
.koleksi-cover img.cover-real{
    width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s;
}
.koleksi-card:hover .koleksi-cover img.cover-real{transform:scale(1.06);}
.koleksi-cover .cover-fallback{font-size:56px;opacity:.55;}
.koleksi-cover::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(to bottom,transparent 50%,rgba(0,0,0,.3) 100%);
    pointer-events:none;
}
.stok-badge-ov{
    position:absolute;bottom:8px;left:8px;z-index:5;
    font-size:10px;font-weight:700;padding:3px 7px;border-radius:5px;
    background:rgba(255,255,255,.9);color:#15803d;
}
.stok-badge-ov.habis{color:#dc2626;}

.koleksi-body{padding:16px;flex:1;display:flex;flex-direction:column;gap:6px;}
.koleksi-body h4{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);margin:0;line-height:1.3;}
.koleksi-body .meta{font-size:12px;color:var(--text);}
.koleksi-body .cat{font-size:10px;color:var(--gold);font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.koleksi-card-footer{display:flex;gap:8px;padding:0 16px 16px;margin-top:auto;}

/* Alert */
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-warning{background:#fff3cd;color:#856404;border:1px solid #ffeeba;}
.alert-danger {background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,10,20,.58);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:9999;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.active{display:flex;}
.modal-box{background:#fff;border-radius:22px;width:100%;max-width:500px;max-height:92vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);animation:popIn .32s cubic-bezier(.34,1.56,.64,1);}
@keyframes popIn{from{opacity:0;transform:scale(.86) translateY(28px)}to{opacity:1;transform:scale(1) translateY(0)}}

/* Modal header dengan cover */
.mh{background:linear-gradient(135deg,#1e1e2f 0%,#3a3a5c 100%);border-radius:22px 22px 0 0;padding:0;color:#fff;position:relative;overflow:hidden;}
.mh-cover-bg{position:absolute;inset:0;background-size:cover;background-position:center;filter:blur(8px) brightness(.4);transform:scale(1.1);}
.mh-content{position:relative;z-index:1;padding:22px 24px 18px;display:flex;gap:16px;align-items:flex-end;}
.mh-book-img{width:80px;height:110px;object-fit:cover;border-radius:8px;border:3px solid rgba(255,255,255,.3);box-shadow:0 4px 16px rgba(0,0,0,.4);flex-shrink:0;}
.mh-book-empty{width:80px;height:110px;border-radius:8px;border:2px dashed rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:36px;flex-shrink:0;background:rgba(255,255,255,.08);}
.mh-text{flex:1;}
.mh-text h3{font-family:'Playfair Display',serif;font-size:18px;margin:0 0 4px;color:#fff;line-height:1.3;}
.mh-text p{font-size:12px;color:rgba(255,255,255,.65);margin:0;}
.mh-close{position:absolute;top:13px;right:13px;z-index:2;background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:19px;line-height:30px;text-align:center;transition:background .2s;}
.mh-close:hover{background:rgba(255,255,255,.3);}

.mb{padding:22px 24px 26px;}
.shortcut-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:18px;}
.sc-btn{padding:6px 14px;border-radius:20px;border:1.5px solid #d1d5db;background:#fff;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;color:#374151;cursor:pointer;transition:all .18s;}
.sc-btn:hover{border-color:#1e1e2f;background:#1e1e2f;color:#fff;}
.sc-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;display:block;}
.date-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
.df{display:flex;flex-direction:column;gap:5px;}
.df label{font-size:11px;font-weight:700;color:#1e1e2f;text-transform:uppercase;letter-spacing:.6px;}
.df input[type=date]{border:2px solid #e5e7eb;border-radius:10px;padding:10px 11px;font-family:'DM Sans',sans-serif;font-size:14px;color:#1e1e2f;width:100%;box-sizing:border-box;cursor:pointer;transition:border-color .2s,box-shadow .2s;}
.df input[type=date]:focus{outline:none;border-color:#1e1e2f;box-shadow:0 0 0 3px rgba(30,30,47,.1);}
.dur-bar{border-radius:10px;padding:12px 15px;display:flex;align-items:center;gap:10px;font-size:13px;min-height:46px;transition:background .25s,color .25s;background:#f3f4f6;color:#374151;margin-bottom:14px;}
.dur-bar.ok{background:#ecfdf5;color:#065f46;}
.dur-bar.warn{background:#fff7ed;color:#9a3412;}
.dur-bar.err{background:#fef2f2;color:#991b1b;}
.dur-bar .di{font-size:20px;flex-shrink:0;}
.dur-bar .dt{font-weight:600;line-height:1.4;}
.denda-box{background:linear-gradient(135deg,#fff7ed,#fff3e0);border:1.5px solid #fed7aa;border-radius:12px;padding:14px 16px;margin-bottom:18px;}
.denda-box-title{font-size:12px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:6px;margin-bottom:10px;}
.denda-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.denda-item{background:#fff;border-radius:8px;padding:9px 10px;text-align:center;border:1px solid #fed7aa;}
.denda-hari{font-size:11px;color:#9a3412;font-weight:600;margin-bottom:2px;}
.denda-nominal{font-size:14px;font-weight:700;color:#c2410c;}
.btn-modal-submit{width:100%;padding:13px;background:linear-gradient(135deg,#1e1e2f,#3a3a5c);color:#fff;border:none;border-radius:12px;font-family:'DM Sans',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:opacity .2s,transform .15s;letter-spacing:.3px;}
.btn-modal-submit:hover{opacity:.88;transform:translateY(-1px);}
.btn-modal-submit:disabled{opacity:.38;cursor:not-allowed;transform:none;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php include '../../includes/sidebar.php'; ?>

<!-- ═══ MODAL PINJAM ═══ -->
<div class="modal-overlay" id="modalPinjam">
  <div class="modal-box">
    <div class="mh">
      <div class="mh-cover-bg" id="modalCoverBg"></div>
      <button class="mh-close" onclick="tutupModal()">×</button>
      <div class="mh-content">
        <img id="modalCoverImg" class="mh-book-img" src="" alt="Cover" style="display:none;"
             onerror="this.style.display='none';document.getElementById('modalCoverEmpty').style.display='flex'">
        <div id="modalCoverEmpty" class="mh-book-empty">📖</div>
        <div class="mh-text">
          <h3 id="modalJudul">—</h3>
          <p>📅 Pilih tanggal peminjaman</p>
        </div>
      </div>
    </div>
    <div class="mb">
      <form method="POST" id="formPinjam">
        <input type="hidden" name="action"  value="pinjam">
        <input type="hidden" name="buku_id" id="modalBukuId" value="">

        <span class="sc-label">⚡ Pilih Cepat Durasi</span>
        <div class="shortcut-row">
          <button type="button" class="sc-btn" onclick="setDurasi(3)">3 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasi(7)">7 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasi(14)">14 Hari</button>
          <button type="button" class="sc-btn" onclick="setDurasi(30)">1 Bulan</button>
        </div>

        <div class="date-row">
          <div class="df">
            <label>📅 Tanggal Pinjam</label>
            <input type="date" name="tgl_pinjam" id="tglPinjam"
                   min="<?= $today ?>" max="<?= $maxDate ?>" value="<?= $today ?>" required>
          </div>
          <div class="df">
            <label>🔔 Tanggal Kembali</label>
            <input type="date" name="tgl_kembali" id="tglKembali"
                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>" max="<?= $maxDate ?>"
                   value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
          </div>
        </div>

        <div class="dur-bar ok" id="durBar">
          <span class="di" id="durIkon">✅</span>
          <span class="dt" id="durTeks">Durasi peminjaman: 7 hari</span>
        </div>

        <div class="denda-box">
          <div class="denda-box-title">⚠️ Informasi Denda Keterlambatan</div>
          <div class="denda-grid">
            <div class="denda-item"><div class="denda-hari">1 hari</div><div class="denda-nominal">Rp 5.000</div></div>
            <div class="denda-item"><div class="denda-hari">2 hari</div><div class="denda-nominal">Rp 10.000</div></div>
            <div class="denda-item"><div class="denda-hari">3 hari</div><div class="denda-nominal">Rp 15.000</div></div>
            <div class="denda-item"><div class="denda-hari">5 hari</div><div class="denda-nominal">Rp 25.000</div></div>
            <div class="denda-item"><div class="denda-hari">7 hari</div><div class="denda-nominal">Rp 35.000</div></div>
            <div class="denda-item"><div class="denda-hari">dst...</div><div class="denda-nominal">+Rp 5.000/hari</div></div>
          </div>
        </div>

        <button type="submit" class="btn-modal-submit" id="btnSubmit">📖 Konfirmasi Peminjaman</button>
      </form>
    </div>
  </div>
</div>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>❤️ Koleksi Saya</h1>
    </div>
    <a href="../../views/peminjam/katalog.php" class="btn btn-primary">+ Tambah dari Katalog</a>
  </header>
  <div class="page-content">
    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType==='success' ? '✅' : ($msgType==='warning' ? '⚠️' : '❌') ?> <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <?php $rows=[]; while($row=$koleksi->fetch_assoc()) $rows[]=$row; ?>

    <?php if (empty($rows)): ?>
      <div class="card"><div class="card-body"><div class="empty-state">
        <div class="empty-icon">❤️</div>
        <p>Belum ada buku di koleksi Anda.<br>Tambahkan dari <a href="../../views/peminjam/katalog.php" style="color:var(--gold)">Katalog Buku</a>.</p>
      </div></div></div>
    <?php else: ?>
    <div class="koleksi-grid">
      <?php foreach($rows as $row):
          $hasCover = !empty($row['CoverURL']);
          $coverUrl = $hasCover ? $coverBaseURL . $row['CoverURL'] : '';
      ?>
      <div class="koleksi-card">
        <!-- Cover -->
        <div class="koleksi-cover">
          <?php if ($hasCover): ?>
            <img class="cover-real"
                 src="<?= htmlspecialchars($coverUrl) ?>"
                 alt="Cover <?= htmlspecialchars($row['Judul']) ?>"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <span class="cover-fallback" style="display:none">📚</span>
          <?php else: ?>
            <span class="cover-fallback">📚</span>
          <?php endif; ?>
          <span class="stok-badge-ov <?= $row['Stok'] <= 0 ? 'habis' : '' ?>">
            <?= $row['Stok'] > 0 ? "📦 Stok: {$row['Stok']}" : '❌ Habis' ?>
          </span>
        </div>

        <div class="koleksi-body">
          <h4><?= htmlspecialchars($row['Judul']) ?></h4>
          <div class="meta">✍️ <?= htmlspecialchars($row['Penulis']?:'Anonim') ?></div>
          <div class="meta">🏢 <?= htmlspecialchars($row['Penerbit']?:'-') ?> · <?= $row['TahunTerbit']?:'-' ?></div>
          <div class="cat"><?= htmlspecialchars($row['Kategori']??'Umum') ?></div>
        </div>

        <div class="koleksi-card-footer">
          <?php if($row['Stok']>0): ?>
            <button class="btn btn-primary btn-sm" style="flex:1"
              onclick="bukaModal(
                <?= $row['BukuID'] ?>,
                '<?= addslashes(htmlspecialchars($row['Judul'])) ?>',
                '<?= addslashes($coverUrl) ?>'
              )">
              📖 Pinjam
            </button>
          <?php else: ?>
            <button class="btn btn-outline btn-sm" disabled style="flex:1">Stok habis</button>
          <?php endif; ?>
          <form method="POST" onsubmit="return confirm('Hapus dari koleksi?')">
            <input type="hidden" name="action" value="hapus">
            <input type="hidden" name="id"     value="<?= $row['KoleksiID'] ?>">
            <button class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const TODAY   = '<?= $today ?>';
const MAXDATE = '<?= $maxDate ?>';
function addDays(str,n){const d=new Date(str);d.setDate(d.getDate()+n);return d.toISOString().split('T')[0];}

function bukaModal(bukuId, judul, coverUrl) {
    document.getElementById('modalBukuId').value      = bukuId;
    document.getElementById('modalJudul').textContent = judul;
    document.getElementById('tglPinjam').value        = TODAY;
    document.getElementById('tglKembali').value       = addDays(TODAY, 7);

    const imgEl   = document.getElementById('modalCoverImg');
    const emptyEl = document.getElementById('modalCoverEmpty');
    const bgEl    = document.getElementById('modalCoverBg');

    if (coverUrl) {
        imgEl.src             = coverUrl;
        imgEl.style.display   = 'block';
        emptyEl.style.display = 'none';
        bgEl.style.backgroundImage = `url('${coverUrl}')`;
    } else {
        imgEl.style.display   = 'none';
        emptyEl.style.display = 'flex';
        bgEl.style.backgroundImage = '';
    }

    hitungDurasi();
    document.getElementById('modalPinjam').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function tutupModal(){document.getElementById('modalPinjam').classList.remove('active');document.body.style.overflow='';}
document.getElementById('modalPinjam').addEventListener('click',e=>{if(e.target===e.currentTarget)tutupModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')tutupModal();});
function setDurasi(days){const p=document.getElementById('tglPinjam').value||TODAY;document.getElementById('tglKembali').value=addDays(p,days);hitungDurasi();}
function hitungDurasi(){
    const p=document.getElementById('tglPinjam').value;const k=document.getElementById('tglKembali').value;
    const bar=document.getElementById('durBar');const ikon=document.getElementById('durIkon');const teks=document.getElementById('durTeks');const btn=document.getElementById('btnSubmit');
    document.getElementById('tglKembali').min=addDays(p||TODAY,1);
    if(!p||!k||k<=p){bar.className='dur-bar err';ikon.textContent='❌';teks.textContent='Tanggal pengembalian harus setelah tanggal pinjam.';btn.disabled=true;return;}
    const days=Math.round((new Date(k)-new Date(p))/86400000);btn.disabled=false;
    if(days>30){bar.className='dur-bar warn';ikon.textContent='⚠️';teks.textContent=`Durasi ${days} hari — pastikan tepat waktu.`;}
    else{bar.className='dur-bar ok';ikon.textContent='✅';teks.textContent=`Durasi peminjaman: ${days} hari.`;}
}
document.getElementById('tglPinjam').addEventListener('change',function(){const k=document.getElementById('tglKembali');if(k.value<=this.value)k.value=addDays(this.value,1);hitungDurasi();});
document.getElementById('tglKembali').addEventListener('change',hitungDurasi);
document.getElementById('formPinjam').addEventListener('submit',function(e){
    const p=document.getElementById('tglPinjam').value;const k=document.getElementById('tglKembali').value;
    const days=Math.round((new Date(k)-new Date(p))/86400000);
    if(days<=0){e.preventDefault();return;}
    const judul=document.getElementById('modalJudul').textContent;
    if(!confirm(`Pinjam "${judul}" selama ${days} hari?\n\n📅 Pinjam  : ${p}\n🔔 Kembali : ${k}\n\n⚠️ Denda keterlambatan Rp 5.000/hari.`)){e.preventDefault();}
});
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open');}
hitungDurasi();
</script>
</body>
</html>
<?php $conn->close(); ?>