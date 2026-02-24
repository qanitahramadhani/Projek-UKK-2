<?php
// views/peminjam/katalog.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn   = getConnection();
$userID = getUserId();
$msg    = ''; $msgType = '';

// ─── Cover Base URL ────────────────────────────────────────────────────────────
$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $BukuID = (int)($_POST['buku_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'pinjam' && $BukuID > 0) {
        $tglPinjam  = $_POST['tgl_pinjam']  ?? date('Y-m-d');
        $tglKembali = $_POST['tgl_kembali'] ?? date('Y-m-d', strtotime('+7 days'));
        $todayStr   = date('Y-m-d');

        if ($tglPinjam < $todayStr) { $msg = 'Tanggal pinjam tidak boleh sebelum hari ini.'; $msgType = 'danger'; }
        elseif ($tglKembali <= $tglPinjam) { $msg = 'Tanggal pengembalian harus setelah tanggal pinjam.'; $msgType = 'danger'; }
        else {
            $cekStok  = $conn->query("SELECT Stok FROM buku WHERE BukuID=$BukuID")->fetch_assoc();
            $cekAktif = $conn->query("SELECT PeminjamanID FROM peminjaman WHERE UserID=$userID AND BukuID=$BukuID AND StatusPeminjaman='dipinjam'")->num_rows;
            if (!$cekStok || $cekStok['Stok'] < 1) { $msg = 'Maaf, stok buku sudah habis.'; $msgType = 'danger'; }
            elseif ($cekAktif > 0) { $msg = 'Anda sudah meminjam buku ini dan belum mengembalikannya.'; $msgType = 'warning'; }
            else {
                $stmt = $conn->prepare("INSERT INTO peminjaman (UserID,BukuID,TanggalPeminjaman,TanggalPengembalian,StatusPeminjaman) VALUES (?,?,?,?,'dipinjam')");
                $stmt->bind_param('iiss', $userID, $BukuID, $tglPinjam, $tglKembali);
                if ($stmt->execute()) { $conn->query("UPDATE buku SET Stok=Stok-1 WHERE BukuID=$BukuID"); $msg = 'Buku berhasil dipinjam! Silakan cek menu Peminjaman.'; $msgType = 'success'; }
                else { $msg = 'Gagal meminjam: ' . $conn->error; $msgType = 'danger'; }
                $stmt->close();
            }
        }
    }

    if ($action === 'koleksi' && $BukuID > 0) {
        $cek = $conn->query("SELECT KoleksiID FROM koleksipribadi WHERE UserID=$userID AND BukuID=$BukuID");
        if ($cek->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO koleksipribadi (UserID,BukuID) VALUES (?,?)");
            $stmt->bind_param('ii', $userID, $BukuID); $stmt->execute(); $stmt->close();
            $msg = 'Buku ditambahkan ke Koleksi Saya!'; $msgType = 'success';
        } else { $msg = 'Buku sudah ada di daftar koleksi Anda.'; $msgType = 'warning'; }
    }
}

$search    = trim($_GET['q'] ?? '');
$searchEsc = $conn->real_escape_string($search);
$where     = $search ? "WHERE b.Judul LIKE '%$searchEsc%' OR b.Penulis LIKE '%$searchEsc%' OR k.NamaKategori LIKE '%$searchEsc%'" : '';

$buku = $conn->query("
    SELECT b.*, k.NamaKategori,
           ROUND(AVG(u.Rating),1) AS RataRating,
           COUNT(u.UlasanID)      AS JmlUlasan
    FROM buku b
    LEFT JOIN kategoribuku k   ON b.KategoriID  = k.KategoriID
    LEFT JOIN ulasanbuku u      ON b.BukuID      = u.BukuID
    $where
    GROUP BY b.BukuID
    ORDER BY b.Judul
");
if (!$buku) die("Kesalahan Database: " . $conn->error);

$today   = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+60 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Katalog Buku — DigiLibrary</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/main.css">
    <style>
    .book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:25px;margin-top:20px}
    .book-card{background:#fff;border-radius:14px;border:1px solid #eee;box-shadow:0 4px 6px rgba(0,0,0,.05);overflow:hidden;position:relative;display:flex;flex-direction:column;transition:.3s}
    .book-card:hover{transform:translateY(-5px);box-shadow:0 10px 20px rgba(0,0,0,.12)}

    /* ── Cover area ── */
    .book-cover{
        height:200px;
        position:relative;
        overflow:hidden;
        background:linear-gradient(135deg,#1e1e2f,#3f3f5f);
        display:flex;align-items:center;justify-content:center;
    }
    .book-cover img.cover-real{
        width:100%;height:100%;
        object-fit:cover;
        display:block;
        transition:transform .3s;
    }
    .book-card:hover .book-cover img.cover-real{transform:scale(1.05);}
    .book-cover .cover-fallback{
        font-size:64px;
        opacity:.6;
    }
    /* Overlay gradien tipis di bawah cover supaya teks lebih terbaca */
    .book-cover::after{
        content:'';
        position:absolute;inset:0;
        background:linear-gradient(to bottom, transparent 50%, rgba(0,0,0,.35) 100%);
        pointer-events:none;
    }

    .btn-love{position:absolute;top:10px;right:10px;z-index:10;background:rgba(255,255,255,.92);border:none;width:34px;height:34px;border-radius:50%;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.18);transition:.2s;font-size:15px;display:flex;align-items:center;justify-content:center;}
    .btn-love:hover{transform:scale(1.2);}

    /* Badge stok di atas cover */
    .stok-badge-overlay{
        position:absolute;bottom:10px;left:10px;z-index:10;
        font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;
        background:rgba(255,255,255,.92);
        color:#15803d;
        box-shadow:0 1px 4px rgba(0,0,0,.15);
    }
    .stok-badge-overlay.habis{color:#dc2626;}

    .book-info{padding:16px;flex-grow:1;display:flex;flex-direction:column;}
    .book-title{font-family:'Playfair Display',serif;font-size:15px;margin-bottom:4px;color:#1e1e2f;line-height:1.3;}
    .book-author{font-size:12px;color:#666;margin-bottom:8px;}
    .book-rating{display:flex;align-items:center;gap:5px;margin-bottom:8px;}
    .book-rating .stars{font-size:12px;letter-spacing:1px;}
    .book-rating .val{font-size:12px;font-weight:700;color:#f59e0b;}
    .book-rating .cnt{font-size:11px;color:#9ca3af;}
    .book-meta{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
    .btn-full{width:100%;}
    .btn-group-card{display:flex;gap:8px;margin-top:auto;}
    .btn-ulasan{flex:0 0 auto;padding:9px 12px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;color:#374151;cursor:pointer;transition:all .18s;text-decoration:none;display:flex;align-items:center;gap:4px;white-space:nowrap;}
    .btn-ulasan:hover{border-color:#1e1e2f;color:#1e1e2f;}
    .alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;}
    .alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
    .alert-danger {background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
    .alert-warning{background:#fff3cd;color:#856404;border:1px solid #ffeeba;}

    /* ── Modal ── */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,10,20,.58);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:9999;align-items:center;justify-content:center;padding:16px;}
    .modal-overlay.active{display:flex;}
    .modal-box{background:#fff;border-radius:22px;width:100%;max-width:520px;max-height:92vh;overflow-y:auto;box-shadow:0 28px 70px rgba(0,0,0,.22);animation:popIn .32s cubic-bezier(.34,1.56,.64,1);}
    @keyframes popIn{from{opacity:0;transform:scale(.86) translateY(28px)}to{opacity:1;transform:scale(1) translateY(0)}}

    /* Modal header dengan cover buku */
    .mh{background:linear-gradient(135deg,#1e1e2f 0%,#3a3a5c 100%);border-radius:22px 22px 0 0;padding:0;color:#fff;position:relative;overflow:hidden;}
    .mh-cover-bg{
        position:absolute;inset:0;
        background-size:cover;background-position:center;
        filter:blur(8px) brightness(.4);
        transform:scale(1.1);
    }
    .mh-content{position:relative;z-index:1;padding:22px 24px 18px;display:flex;gap:16px;align-items:flex-end;}
    .mh-book-img{
        width:80px;height:110px;
        object-fit:cover;
        border-radius:8px;
        border:3px solid rgba(255,255,255,.3);
        box-shadow:0 4px 16px rgba(0,0,0,.4);
        flex-shrink:0;
    }
    .mh-book-empty{
        width:80px;height:110px;
        border-radius:8px;
        border:2px dashed rgba(255,255,255,.3);
        display:flex;align-items:center;justify-content:center;
        font-size:36px;flex-shrink:0;
        background:rgba(255,255,255,.08);
    }
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
      <!-- Background blur dari cover buku -->
      <div class="mh-cover-bg" id="modalCoverBg" style=""></div>
      <button class="mh-close" onclick="tutupModal()">×</button>
      <div class="mh-content">
        <!-- Thumbnail cover buku -->
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
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Katalog Buku</h1>
        </div>
        <div class="topbar-right">
            <form method="GET" class="search-bar">
                <input type="text" name="q" placeholder="Cari judul, penulis..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-outline">Cari</button>
            </form>
        </div>
    </div>

    <div class="page-content">
        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= $msgType==='success'?'✅':($msgType==='warning'?'⚠️':'❌') ?> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($buku->num_rows > 0): ?>
            <div class="book-grid">
                <?php while($b = $buku->fetch_assoc()):
                    $rata     = (float)($b['RataRating'] ?? 0);
                    $jml      = (int)($b['JmlUlasan']  ?? 0);
                    $hasCover = !empty($b['CoverURL']);
                    $coverUrl = $hasCover ? $coverBaseURL . $b['CoverURL'] : '';
                ?>
                    <div class="book-card">
                        <div class="book-cover">
                            <?php if ($hasCover): ?>
                                <img class="cover-real"
                                     src="<?= htmlspecialchars($coverUrl) ?>"
                                     alt="Cover <?= htmlspecialchars($b['Judul']) ?>"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                                <span class="cover-fallback" style="display:none">📖</span>
                            <?php else: ?>
                                <span class="cover-fallback">📖</span>
                            <?php endif; ?>

                            <!-- Tombol simpan ke koleksi -->
                            <form method="POST" style="position:absolute;top:10px;right:10px;z-index:10;">
                                <input type="hidden" name="buku_id" value="<?= $b['BukuID'] ?>">
                                <input type="hidden" name="action"  value="koleksi">
                                <button type="submit" class="btn-love" title="Simpan ke Koleksi">❤️</button>
                            </form>

                            <!-- Badge stok overlay -->
                            <span class="stok-badge-overlay <?= $b['Stok'] <= 0 ? 'habis' : '' ?>">
                                <?= $b['Stok'] > 0 ? "📦 Stok: {$b['Stok']}" : '❌ Habis' ?>
                            </span>
                        </div>
                        <div class="book-info">
                            <h3 class="book-title"><?= htmlspecialchars($b['Judul']) ?></h3>
                            <p class="book-author">Oleh: <?= htmlspecialchars($b['Penulis'] ?: 'Anonim') ?></p>

                            <!-- Rating -->
                            <div class="book-rating">
                                <span class="stars">
                                    <?php for($s=1;$s<=5;$s++) echo $s<=$rata?'⭐':'☆'; ?>
                                </span>
                                <?php if ($rata > 0): ?>
                                    <span class="val"><?= $rata ?></span>
                                    <span class="cnt">(<?= $jml ?> ulasan)</span>
                                <?php else: ?>
                                    <span class="cnt">Belum ada ulasan</span>
                                <?php endif; ?>
                            </div>

                            <div class="book-meta">
                                <span class="badge badge-info"><?= htmlspecialchars($b['NamaKategori'] ?? 'Umum') ?></span>
                            </div>

                            <div class="btn-group-card">
                                <?php if ($b['Stok'] > 0): ?>
                                    <button type="button" class="btn btn-primary btn-full"
                                        onclick="bukaModal(
                                            <?= $b['BukuID'] ?>,
                                            '<?= addslashes(htmlspecialchars($b['Judul'])) ?>',
                                            '<?= addslashes($coverUrl) ?>'
                                        )">
                                        Pinjam
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline btn-full" disabled>Tidak Tersedia</button>
                                <?php endif; ?>
                                <a href="../../views/peminjam/ulasan.php?buku=<?= $b['BukuID'] ?>"
                                   class="btn-ulasan" title="Lihat & Tulis Ulasan">
                                   ⭐ Ulasan
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p>Tidak ada buku ditemukan<?= $search?' untuk "'.htmlspecialchars($search).'"':'' ?>.</p>
                <?php if ($search): ?><a href="katalog.php" class="btn btn-sm btn-primary">Lihat Semua Buku</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const TODAY   = '<?= $today ?>';
const MAXDATE = '<?= $maxDate ?>';

function addDays(str,n){const d=new Date(str);d.setDate(d.getDate()+n);return d.toISOString().split('T')[0];}

function bukaModal(bukuId, judul, coverUrl) {
    document.getElementById('modalBukuId').value        = bukuId;
    document.getElementById('modalJudul').textContent   = judul;
    document.getElementById('tglPinjam').value          = TODAY;
    document.getElementById('tglKembali').value         = addDays(TODAY, 7);

    // Set cover di modal header
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

function tutupModal(){
    document.getElementById('modalPinjam').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalPinjam').addEventListener('click', e=>{if(e.target===e.currentTarget)tutupModal();});
document.addEventListener('keydown', e=>{if(e.key==='Escape')tutupModal();});

function setDurasi(days){const p=document.getElementById('tglPinjam').value||TODAY;document.getElementById('tglKembali').value=addDays(p,days);hitungDurasi();}

function hitungDurasi(){
    const p=document.getElementById('tglPinjam').value;const k=document.getElementById('tglKembali').value;
    const bar=document.getElementById('durBar');const ikon=document.getElementById('durIkon');const teks=document.getElementById('durTeks');const btn=document.getElementById('btnSubmit');
    document.getElementById('tglKembali').min=addDays(p||TODAY,1);
    if(!p||!k||k<=p){bar.className='dur-bar err';ikon.textContent='❌';teks.textContent='Tanggal pengembalian harus setelah tanggal pinjam.';btn.disabled=true;return;}
    const days=Math.round((new Date(k)-new Date(p))/86400000);btn.disabled=false;
    if(days>30){bar.className='dur-bar warn';ikon.textContent='⚠️';teks.textContent=`Durasi ${days} hari — pastikan Anda bisa tepat waktu.`;}
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