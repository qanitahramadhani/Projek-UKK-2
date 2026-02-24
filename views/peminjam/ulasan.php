<?php
// views/peminjam/ulasan.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole('peminjam');

$conn = getConnection();
$uid  = getUserId();
$msg  = ''; $msgType = '';

$docRoot      = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$currentPath  = str_replace('\\', '/', realpath(dirname(__FILE__)));
$subPath      = str_replace($docRoot, '', $currentPath);
$parts        = explode('/', trim($subPath, '/'));
$projectRoot  = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
$coverBaseURL = rtrim($projectRoot, '/') . '/public/uploads/covers/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $BukuID  = intval($_POST['buku_id'] ?? 0);
    $rating  = intval($_POST['rating']  ?? 0);
    $ulasan  = trim($_POST['ulasan']    ?? '');

    if ($action === 'submit' && $BukuID > 0) {
        if ($rating < 1 || $rating > 5) { $msg = 'Pilih rating bintang terlebih dahulu (1–5).'; $msgType = 'danger'; }
        elseif (strlen($ulasan) < 5)    { $msg = 'Ulasan terlalu singkat, minimal 5 karakter.'; $msgType = 'danger'; }
        else {
            $pernah = $conn->query("SELECT PeminjamanID FROM peminjaman WHERE UserID=$uid AND BukuID=$BukuID LIMIT 1")->num_rows;
            if ($pernah === 0) { $msg = 'Anda hanya bisa memberi ulasan pada buku yang pernah Anda pinjam.'; $msgType = 'warning'; }
            else {
                $sudah = $conn->query("SELECT UlasanID FROM ulasanbuku WHERE UserID=$uid AND BukuID=$BukuID")->num_rows;
                if ($sudah > 0) {
                    $ulasanEsc = $conn->real_escape_string($ulasan);
                    $conn->query("UPDATE ulasanbuku SET Ulasan='$ulasanEsc', Rating=$rating, CreatedAt=NOW() WHERE UserID=$uid AND BukuID=$BukuID");
                    $msg = 'Ulasan Anda berhasil diperbarui!'; $msgType = 'success';
                } else {
                    $stmt = $conn->prepare("INSERT INTO ulasanbuku (UserID, BukuID, Ulasan, Rating) VALUES (?,?,?,?)");
                    $stmt->bind_param('iisi', $uid, $BukuID, $ulasan, $rating);
                    if ($stmt->execute()) { $msg = 'Ulasan berhasil dikirim! Terima kasih.'; $msgType = 'success'; }
                    else { $msg = 'Gagal menyimpan ulasan: ' . $conn->error; $msgType = 'danger'; }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'hapus') {
        $UlasanID = intval($_POST['ulasan_id'] ?? 0);
        if ($UlasanID > 0) {
            $conn->query("DELETE FROM ulasanbuku WHERE UlasanID=$UlasanID AND UserID=$uid");
            $msg = 'Ulasan berhasil dihapus.'; $msgType = 'success';
        }
    }
}

// ── Tab mode: 'semua' (jelajah semua buku) atau 'saya' (buku yg pernah dipinjam) ──
$tab        = $_GET['tab']  ?? 'semua';
$bukuFilter = intval($_GET['buku'] ?? 0);
$searchQ    = trim($_GET['q'] ?? '');

// Buku yang pernah dipinjam user (untuk tab Ulasan Saya)
$bukuDipinjam = $conn->query("
    SELECT DISTINCT b.BukuID, b.Judul, b.Penulis, b.CoverURL
    FROM peminjaman p JOIN buku b ON p.BukuID=b.BukuID
    WHERE p.UserID=$uid ORDER BY b.Judul
");
$bukuList = [];
if ($bukuDipinjam) while ($b = $bukuDipinjam->fetch_assoc()) $bukuList[] = $b;

// Semua buku (untuk tab Jelajah)
$searchWhere = '';
if ($searchQ) {
    $sq = $conn->real_escape_string($searchQ);
    $searchWhere = "WHERE b.Judul LIKE '%$sq%' OR b.Penulis LIKE '%$sq%'";
}
$semuaBuku = $conn->query("
    SELECT b.BukuID, b.Judul, b.Penulis, b.CoverURL,
           ROUND(AVG(u.Rating),1) AS RataRating,
           COUNT(u.UlasanID) AS JmlUlasan
    FROM buku b
    LEFT JOIN ulasanbuku u ON b.BukuID = u.BukuID
    $searchWhere
    GROUP BY b.BukuID
    ORDER BY JmlUlasan DESC, b.Judul ASC
    LIMIT 50
");

// Ulasan saya
$myUlasan = $conn->query("
    SELECT u.*, b.Judul, b.Penulis, b.CoverURL
    FROM ulasanbuku u
    JOIN buku b ON u.BukuID=b.BukuID
    WHERE u.UserID=$uid
    ORDER BY u.CreatedAt DESC
");
if (!$myUlasan) die("DB Error: " . $conn->error);
$myRows = [];
while ($r = $myUlasan->fetch_assoc()) $myRows[] = $r;

// Detail buku yang dipilih
$bukuDetail          = null;
$semuaUlasanBuku     = null;
$ulasanSayaUntukBuku = null;
$sudahPernah         = false;

if ($bukuFilter > 0) {
    $bukuDetail = $conn->query("
        SELECT b.*, k.NamaKategori,
               ROUND(AVG(u.Rating),1) AS RataRating,
               COUNT(u.UlasanID) AS JmlUlasan
        FROM buku b
        LEFT JOIN kategoribuku k ON b.KategoriID=k.KategoriID
        LEFT JOIN ulasanbuku u ON b.BukuID=u.BukuID
        WHERE b.BukuID=$bukuFilter GROUP BY b.BukuID
    ")->fetch_assoc();

    $semuaUlasanBuku = $conn->query("
        SELECT u.*, us.NamaLengkap
        FROM ulasanbuku u JOIN user us ON u.UserID=us.UserID
        WHERE u.BukuID=$bukuFilter ORDER BY u.CreatedAt DESC
    ");

    $res = $conn->query("SELECT * FROM ulasanbuku WHERE UserID=$uid AND BukuID=$bukuFilter");
    if ($res && $res->num_rows > 0) $ulasanSayaUntukBuku = $res->fetch_assoc();

    $pernah = $conn->query("SELECT PeminjamanID FROM peminjaman WHERE UserID=$uid AND BukuID=$bukuFilter LIMIT 1");
    $sudahPernah = ($pernah && $pernah->num_rows > 0);
}

$activePage = 'ulasan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ulasan Buku — DigiLibrary</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../public/css/main.css">
<style>
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:500;border:1px solid}
.alert-success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.alert-warning{background:#fff3cd;color:#856404;border-color:#ffeeba}
.alert-danger {background:#f8d7da;color:#721c24;border-color:#f5c6cb}

/* ── Tab ── */
.tab-bar{display:flex;gap:4px;margin-bottom:22px;background:#f3f4f6;border-radius:12px;padding:5px;}
.tab-btn{flex:1;padding:10px 16px;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;color:#6b7280;background:transparent;}
.tab-btn.active{background:#fff;color:#1e1e2f;box-shadow:0 2px 8px rgba(0,0,0,.1);}

/* ── Layout ── */
.ulasan-layout{display:grid;grid-template-columns:290px 1fr;gap:22px;align-items:start;}
@media(max-width:900px){.ulasan-layout{grid-template-columns:1fr;}}

/* ── Picker ── */
.buku-picker{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;}
.buku-picker-head{background:linear-gradient(135deg,#1e1e2f,#3a3a5c);padding:14px 16px;color:#fff;}
.buku-picker-head h3{font-family:'Playfair Display',serif;font-size:15px;margin:0 0 2px;}
.buku-picker-head p{font-size:11px;color:rgba(255,255,255,.55);margin:0;}
.buku-picker-head .search-mini{margin-top:10px;display:flex;gap:6px;}
.search-mini input{flex:1;padding:7px 10px;border-radius:8px;border:1.5px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff;font-family:'DM Sans',sans-serif;font-size:12px;}
.search-mini input::placeholder{color:rgba(255,255,255,.45);}
.search-mini input:focus{outline:none;border-color:rgba(255,255,255,.5);}
.search-mini button{padding:7px 12px;border-radius:8px;border:none;background:rgba(255,255,255,.2);color:#fff;font-size:12px;cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:600;}
.buku-picker-list{padding:8px;max-height:460px;overflow-y:auto;}

.buku-picker-item{display:flex;align-items:center;gap:10px;padding:9px;border-radius:10px;cursor:pointer;transition:background .18s;text-decoration:none;border:1.5px solid transparent;margin-bottom:3px;}
.buku-picker-item:hover{background:#f5f5ff;border-color:#d1d1f0;}
.buku-picker-item.active{background:#ede9fe;border-color:#7c3aed;}

.picker-cover-wrap{flex-shrink:0;width:40px;height:55px;border-radius:5px;overflow:hidden;background:linear-gradient(135deg,#1e1e2f,#3a3a5c);display:flex;align-items:center;justify-content:center;}
.picker-cover-wrap img{width:100%;height:100%;object-fit:cover;display:block;}
.picker-cover-wrap .picker-cover-fb{font-size:18px;color:rgba(255,255,255,.6);}

.buku-picker-info{flex:1;min-width:0;}
.buku-picker-info h4{font-size:12.5px;font-weight:600;color:#1e1e2f;margin:0 0 2px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.buku-picker-info p{font-size:11px;color:#6b7280;margin:0;}
.picker-rating{display:flex;align-items:center;gap:3px;margin-top:3px;}
.picker-rating .stars{font-size:10px;}
.picker-rating .val{font-size:11px;font-weight:700;color:#f59e0b;}
.picker-rating .cnt{font-size:10px;color:#9ca3af;}

/* ── Book Header Banner ── */
.book-header-banner{position:relative;height:155px;background:linear-gradient(135deg,#1e1e2f,#3a3a5c);overflow:hidden;}
.book-header-banner .bhb-bg{position:absolute;inset:0;background-size:cover;background-position:center;filter:blur(10px) brightness(.4);transform:scale(1.1);}
.book-header-banner .bhb-content{position:relative;z-index:1;height:100%;display:flex;align-items:flex-end;padding:14px 18px;gap:14px;}
.book-header-banner .bhb-img{width:70px;height:98px;object-fit:cover;border-radius:7px;border:3px solid rgba(255,255,255,.3);box-shadow:0 4px 14px rgba(0,0,0,.4);flex-shrink:0;}
.book-header-banner .bhb-empty{width:70px;height:98px;border-radius:7px;border:2px dashed rgba(255,255,255,.25);background:rgba(255,255,255,.07);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0;}
.book-header-banner .bhb-text{flex:1;color:#fff;}
.book-header-banner .bhb-text h3{font-family:'Playfair Display',serif;font-size:17px;margin:0 0 3px;line-height:1.3;}
.book-header-banner .bhb-text p{font-size:11.5px;color:rgba(255,255,255,.65);margin:0 0 7px;}
.rating-summary-inline{display:flex;align-items:center;gap:7px;}
.rating-big-inline{font-size:20px;font-weight:700;font-family:'Playfair Display',serif;color:#fff;}
.stars-inline span{font-size:14px;}
.rating-count-inline{font-size:11px;color:rgba(255,255,255,.6);}

/* ── Form ── */
.form-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:18px;}
.form-card-body{padding:18px 20px 22px;}
.star-input-wrap{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:3px;margin-bottom:4px;}
.star-input-wrap input[type=radio]{display:none;}
.star-input-wrap label{font-size:30px;cursor:pointer;color:#d1d5db;transition:color .15s,transform .15s;line-height:1;}
.star-input-wrap label:hover,.star-input-wrap label:hover~label,.star-input-wrap input:checked~label{color:#f59e0b;}
.star-input-wrap label:hover{transform:scale(1.15);}
.star-hint{font-size:12px;color:#9ca3af;margin-bottom:14px;}
.ulasan-textarea{width:100%;box-sizing:border-box;border:2px solid #e5e7eb;border-radius:10px;padding:11px 13px;font-family:'DM Sans',sans-serif;font-size:14px;color:#1e1e2f;resize:vertical;min-height:100px;transition:border-color .2s;}
.ulasan-textarea:focus{outline:none;border-color:#1e1e2f;box-shadow:0 0 0 3px rgba(30,30,47,.08);}
.char-count{font-size:11px;color:#9ca3af;text-align:right;margin-top:3px;margin-bottom:14px;}
.btn-submit-ulasan{padding:11px 24px;background:linear-gradient(135deg,#1e1e2f,#3a3a5c);color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .2s;}
.btn-submit-ulasan:hover{opacity:.88;}

/* Notice: buku belum pernah dipinjam */
.notice-pinjam{background:#fffbeb;border:1.5px solid #fcd34d;border-radius:10px;padding:13px 15px;font-size:13px;color:#92400e;margin-bottom:16px;display:flex;gap:8px;align-items:flex-start;}

/* ── Review Cards ── */
.review-section{margin-top:0;}
.review-card{background:#fff;border-radius:12px;border:1px solid var(--border);padding:14px 16px;margin-bottom:10px;transition:box-shadow .2s;}
.review-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.07);}
.review-card.mine{border-left:4px solid #7c3aed;background:#fdfcff;}
.review-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;gap:10px;}
.reviewer-info{display:flex;align-items:center;gap:9px;}
.reviewer-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1e1e2f,#4a4a7a);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.reviewer-name{font-size:13.5px;font-weight:600;color:#1e1e2f;}
.reviewer-date{font-size:11px;color:#9ca3af;margin-top:1px;}
.stars-display{display:flex;gap:1px;}
.stars-display span{font-size:15px;}
.review-text{font-size:13.5px;color:#374151;line-height:1.6;}
.mine-badge{font-size:10px;font-weight:700;color:#7c3aed;background:#ede9fe;padding:2px 7px;border-radius:20px;}

/* ── My Reviews Mini ── */
.my-review-mini{background:#fff;border-radius:12px;border:1px solid var(--border);margin-top:12px;overflow:hidden;}
.my-review-mini-head{padding:11px 14px;border-bottom:1px solid var(--border);background:#fafafa;}
.my-review-mini-head h4{font-size:13px;font-weight:700;color:#1e1e2f;margin:0;}
.my-review-mini-item{display:flex;align-items:flex-start;gap:9px;padding:10px 13px;border-bottom:1px solid #f3f4f6;}
.my-review-mini-item:last-child{border-bottom:none;}
.mrm-cover{width:32px;height:44px;border-radius:4px;overflow:hidden;flex-shrink:0;background:linear-gradient(135deg,#1e1e2f,#3a3a5c);display:flex;align-items:center;justify-content:center;}
.mrm-cover img{width:100%;height:100%;object-fit:cover;display:block;}
.mrm-cover .mrm-fb{font-size:14px;color:rgba(255,255,255,.6);}
.mrm-info{flex:1;min-width:0;}
.mrm-title{font-size:12px;font-weight:600;color:#1e1e2f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mrm-stars{display:flex;gap:1px;margin:2px 0;}
.mrm-stars span{font-size:11px;}
.mrm-text{font-size:11px;color:#6b7280;line-height:1.4;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}

.empty-pick{text-align:center;padding:36px 20px;color:#9ca3af;font-size:14px;}
.empty-pick .ei{font-size:40px;margin-bottom:10px;}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<?php include '../../includes/sidebar.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
      <h1>&#11088; Ulasan Buku</h1>
    </div>
  </header>

  <div class="page-content">
    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType==='success'?'&#9989;':($msgType==='warning'?'&#9888;&#65039;':'&#10060;') ?> <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- TAB BAR -->
    <div class="tab-bar">
      <button class="tab-btn <?= $tab==='semua'?'active':'' ?>"
              onclick="location.href='?tab=semua<?= $bukuFilter?'&buku='.$bukuFilter:'' ?>'">
        &#128218; Jelajah Semua Buku
      </button>
      <button class="tab-btn <?= $tab==='saya'?'active':'' ?>"
              onclick="location.href='?tab=saya<?= $bukuFilter?'&buku='.$bukuFilter:'' ?>'">
        &#128221; Buku Saya (<?= count($bukuList) ?>)
      </button>
    </div>

    <div class="ulasan-layout">

      <!-- ── Kolom Kiri: Picker ── -->
      <div>
        <?php if ($tab === 'semua'): ?>
        <!-- Picker semua buku dengan search -->
        <div class="buku-picker">
          <div class="buku-picker-head">
            <h3>&#128218; Semua Buku</h3>
            <p>Pilih buku untuk melihat ulasan</p>
            <form method="GET" class="search-mini">
              <input type="hidden" name="tab" value="semua">
              <input type="text" name="q" placeholder="Cari judul / penulis..." value="<?= htmlspecialchars($searchQ) ?>">
              <button type="submit">Cari</button>
            </form>
          </div>
          <div class="buku-picker-list">
            <?php if ($semuaBuku && $semuaBuku->num_rows > 0):
              while ($sb = $semuaBuku->fetch_assoc()):
                $sbCover = !empty($sb['CoverURL']) ? $coverBaseURL . $sb['CoverURL'] : '';
            ?>
            <a href="?tab=semua&buku=<?= $sb['BukuID'] ?><?= $searchQ?'&q='.urlencode($searchQ):'' ?>"
               class="buku-picker-item <?= $bukuFilter===$sb['BukuID']?'active':'' ?>">
              <div class="picker-cover-wrap">
                <?php if ($sbCover): ?>
                  <img src="<?= htmlspecialchars($sbCover) ?>" alt=""
                       onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                  <span class="picker-cover-fb" style="display:none">&#128218;</span>
                <?php else: ?>
                  <span class="picker-cover-fb">&#128218;</span>
                <?php endif; ?>
              </div>
              <div class="buku-picker-info">
                <h4><?= htmlspecialchars($sb['Judul']) ?></h4>
                <p><?= htmlspecialchars($sb['Penulis']?:'Anonim') ?></p>
                <div class="picker-rating">
                  <?php if ($sb['JmlUlasan'] > 0): ?>
                    <span class="val"><?= $sb['RataRating'] ?></span>
                    <span class="stars">&#11088;</span>
                    <span class="cnt">(<?= $sb['JmlUlasan'] ?>)</span>
                  <?php else: ?>
                    <span class="cnt">Belum ada ulasan</span>
                  <?php endif; ?>
                </div>
              </div>
            </a>
            <?php endwhile; else: ?>
            <div class="empty-pick"><div class="ei">&#128269;</div><p>Buku tidak ditemukan.</p></div>
            <?php endif; ?>
          </div>
        </div>

        <?php else: /* tab === 'saya' */ ?>
        <!-- Picker buku yang pernah dipinjam -->
        <div class="buku-picker">
          <div class="buku-picker-head">
            <h3>&#128218; Buku Saya</h3>
            <p>Buku yang pernah Anda pinjam</p>
          </div>
          <div class="buku-picker-list">
            <?php if (empty($bukuList)): ?>
            <div class="empty-pick"><div class="ei">&#128235;</div><p>Belum ada buku yang dipinjam.</p></div>
            <?php else: foreach($bukuList as $bl):
                $blCover = !empty($bl['CoverURL']) ? $coverBaseURL . $bl['CoverURL'] : '';
            ?>
            <a href="?tab=saya&buku=<?= $bl['BukuID'] ?>"
               class="buku-picker-item <?= $bukuFilter===$bl['BukuID']?'active':'' ?>">
              <div class="picker-cover-wrap">
                <?php if ($blCover): ?>
                  <img src="<?= htmlspecialchars($blCover) ?>" alt=""
                       onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                  <span class="picker-cover-fb" style="display:none">&#128218;</span>
                <?php else: ?>
                  <span class="picker-cover-fb">&#128218;</span>
                <?php endif; ?>
              </div>
              <div class="buku-picker-info">
                <h4><?= htmlspecialchars($bl['Judul']) ?></h4>
                <p><?= htmlspecialchars($bl['Penulis']?:'Anonim') ?></p>
              </div>
            </a>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <!-- Mini daftar ulasan saya -->
        <?php if (!empty($myRows)): ?>
        <div class="my-review-mini">
          <div class="my-review-mini-head"><h4>&#128221; Ulasan Saya (<?= count($myRows) ?>)</h4></div>
          <?php foreach($myRows as $mr):
            $mrCover = !empty($mr['CoverURL']) ? $coverBaseURL . $mr['CoverURL'] : '';
          ?>
          <div class="my-review-mini-item">
            <div class="mrm-cover">
              <?php if ($mrCover): ?>
                <img src="<?= htmlspecialchars($mrCover) ?>" alt=""
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <span class="mrm-fb" style="display:none">&#128218;</span>
              <?php else: ?>
                <span class="mrm-fb">&#128218;</span>
              <?php endif; ?>
            </div>
            <div class="mrm-info">
              <div class="mrm-title"><?= htmlspecialchars($mr['Judul']) ?></div>
              <div class="mrm-stars">
                <?php for($s=1;$s<=5;$s++): ?><span><?= $s<=$mr['Rating']?'&#11088;':'&#9734;' ?></span><?php endfor; ?>
              </div>
              <div class="mrm-text"><?= htmlspecialchars($mr['Ulasan']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- ── Kolom Kanan: Detail Buku + Form + Semua Ulasan ── -->
      <div>
        <?php if ($bukuFilter === 0): ?>
          <div class="empty-pick" style="background:#fff;border-radius:14px;border:1px solid var(--border);padding:50px 20px;">
            <div class="ei">&#128072;</div>
            <p>Pilih buku dari daftar di kiri<br>untuk melihat ulasan.</p>
          </div>

        <?php else: ?>
          <!-- Header Cover Buku -->
          <div class="form-card">
            <?php $detailCover = !empty($bukuDetail['CoverURL']) ? $coverBaseURL . $bukuDetail['CoverURL'] : ''; ?>
            <div class="book-header-banner">
              <?php if ($detailCover): ?>
                <div class="bhb-bg" style="background-image:url('<?= htmlspecialchars($detailCover) ?>')"></div>
              <?php endif; ?>
              <div class="bhb-content">
                <?php if ($detailCover): ?>
                  <img class="bhb-img" src="<?= htmlspecialchars($detailCover) ?>" alt="Cover"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="bhb-empty" style="display:none">&#128218;</div>
                <?php else: ?>
                  <div class="bhb-empty">&#128218;</div>
                <?php endif; ?>
                <div class="bhb-text">
                  <h3><?= htmlspecialchars($bukuDetail['Judul'] ?? '') ?></h3>
                  <p>&#9998;&#65039; <?= htmlspecialchars($bukuDetail['Penulis']?:'Anonim') ?> &nbsp;&#183;&nbsp; &#127991;&#65039; <?= htmlspecialchars($bukuDetail['NamaKategori']??'Umum') ?></p>
                  <div class="rating-summary-inline">
                    <span class="rating-big-inline"><?= $bukuDetail['RataRating'] ?: '&#8212;' ?></span>
                    <div class="stars-inline">
                      <?php $avg=(float)($bukuDetail['RataRating']??0); for($s=1;$s<=5;$s++): ?><span><?= $s<=$avg?'&#11088;':'&#9734;' ?></span><?php endfor; ?>
                    </div>
                    <span class="rating-count-inline">(<?= $bukuDetail['JmlUlasan']??0 ?> ulasan)</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="form-card-body">
              <?php if ($sudahPernah || $tab === 'saya'): ?>
                <!-- Form tulis/edit ulasan — hanya muncul jika pernah meminjam -->
                <?php if (!$sudahPernah && $tab === 'saya'): ?>
                  <!-- tab saya tapi belum pernah pinjam buku ini (edge case) -->
                <?php else: ?>
                  <?php if (!$sudahPernah): ?>
                  <div class="notice-pinjam">
                    &#128218; Anda belum pernah meminjam buku ini. Anda hanya dapat melihat ulasan dari pembaca lain.
                  </div>
                  <?php else: ?>
                  <div style="font-weight:700;font-size:14px;color:#1e1e2f;margin-bottom:13px;">
                    <?= $ulasanSayaUntukBuku ? '&#9999;&#65039; Edit Ulasan Anda' : '&#9997;&#65039; Tulis Ulasan' ?>
                  </div>
                  <form method="POST" id="formUlasan">
                    <input type="hidden" name="action"  value="submit">
                    <input type="hidden" name="buku_id" value="<?= $bukuFilter ?>">
                    <div style="margin-bottom:5px;font-size:11px;font-weight:700;color:#1e1e2f;text-transform:uppercase;letter-spacing:.6px">&#11088; Beri Rating</div>
                    <div class="star-input-wrap">
                      <?php $existRating = $ulasanSayaUntukBuku['Rating'] ?? 0; ?>
                      <?php for($s=5;$s>=1;$s--): ?>
                        <input type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" <?= $existRating==$s?'checked':'' ?>>
                        <label for="star<?= $s ?>" title="<?= $s ?> bintang">&#9733;</label>
                      <?php endfor; ?>
                    </div>
                    <div class="star-hint" id="starHint">
                      <?= $existRating > 0 ? ['','&#128542; Mengecewakan','&#128528; Biasa','&#128578; Cukup Bagus','&#128522; Bagus','&#129321; Luar Biasa!'][$existRating] : 'Klik bintang untuk memberi penilaian' ?>
                    </div>
                    <div style="margin-bottom:5px;font-size:11px;font-weight:700;color:#1e1e2f;text-transform:uppercase;letter-spacing:.6px">&#128221; Ulasan</div>
                    <textarea name="ulasan" id="ulasanText" class="ulasan-textarea"
                              placeholder="Ceritakan pengalaman Anda membaca buku ini..."
                              maxlength="1000" required><?= htmlspecialchars($ulasanSayaUntukBuku['Ulasan'] ?? '') ?></textarea>
                    <div class="char-count"><span id="charNow"><?= strlen($ulasanSayaUntukBuku['Ulasan'] ?? '') ?></span>/1000</div>
                    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
                      <button type="submit" class="btn-submit-ulasan">
                        <?= $ulasanSayaUntukBuku ? '&#128190; Perbarui Ulasan' : '&#128640; Kirim Ulasan' ?>
                      </button>
                      <?php if ($ulasanSayaUntukBuku): ?>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Hapus ulasan ini?')">
                        <input type="hidden" name="action"    value="hapus">
                        <input type="hidden" name="buku_id"   value="<?= $bukuFilter ?>">
                        <input type="hidden" name="ulasan_id" value="<?= $ulasanSayaUntukBuku['UlasanID'] ?>">
                        <button type="submit" style="padding:11px 16px;border-radius:10px;border:1.5px solid #ef4444;background:#fff;color:#ef4444;font-family:'DM Sans',sans-serif;font-weight:600;font-size:13px;cursor:pointer;">
                          &#128465;&#65039; Hapus
                        </button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              <?php else: ?>
                <!-- Belum pernah pinjam — hanya lihat -->
                <div class="notice-pinjam">
                  &#128218; Anda belum pernah meminjam buku ini. Pinjam terlebih dahulu untuk bisa memberikan ulasan.
                  <a href="../../views/peminjam/katalog.php" style="color:#92400e;font-weight:700;margin-left:6px;">Lihat Katalog &#8594;</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Semua Ulasan Buku Ini -->
          <?php if ($semuaUlasanBuku && $semuaUlasanBuku->num_rows > 0): ?>
          <div class="review-section">
            <div style="font-family:'Playfair Display',serif;font-size:17px;color:#1e1e2f;margin-bottom:12px;font-weight:700;">
              &#128172; Ulasan Pembaca (<?= $semuaUlasanBuku->num_rows ?>)
            </div>
            <?php while ($rev = $semuaUlasanBuku->fetch_assoc()):
              $initial = mb_strtoupper(mb_substr($rev['NamaLengkap'] ?? '?', 0, 1));
              $isMine  = ($rev['UserID'] == $uid);
            ?>
            <div class="review-card <?= $isMine?'mine':'' ?>">
              <div class="review-head">
                <div class="reviewer-info">
                  <div class="reviewer-avatar"><?= $initial ?></div>
                  <div>
                    <div class="reviewer-name">
                      <?= htmlspecialchars($rev['NamaLengkap'] ?? 'Anonim') ?>
                      <?php if ($isMine): ?><span class="mine-badge">Ulasan Saya</span><?php endif; ?>
                    </div>
                    <div class="reviewer-date"><?= date('d M Y, H:i', strtotime($rev['CreatedAt'])) ?></div>
                  </div>
                </div>
                <div class="stars-display">
                  <?php for($s=1;$s<=5;$s++): ?><span><?= $s<=$rev['Rating']?'&#11088;':'&#9734;' ?></span><?php endfor; ?>
                </div>
              </div>
              <div class="review-text"><?= nl2br(htmlspecialchars($rev['Ulasan'])) ?></div>
            </div>
            <?php endwhile; ?>
          </div>
          <?php else: ?>
          <div style="background:#f9fafb;border-radius:12px;border:1px dashed #d1d5db;padding:26px;text-align:center;color:#9ca3af;">
            <div style="font-size:32px;margin-bottom:7px">&#128172;</div>
            <p style="margin:0;font-size:13.5px">Belum ada ulasan untuk buku ini.<br>
              <?php if ($sudahPernah): ?>Jadilah yang pertama mengulas!<?php endif; ?>
            </p>
          </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
const hints = ['','&#128542; Mengecewakan','&#128528; Biasa','&#128578; Cukup Bagus','&#128522; Bagus','&#129321; Luar Biasa!'];
document.querySelectorAll('.star-input-wrap input').forEach(inp => {
    inp.addEventListener('change', function() {
        const el = document.getElementById('starHint');
        if (el) el.innerHTML = hints[this.value] || '';
    });
});
const ta = document.getElementById('ulasanText');
const cn = document.getElementById('charNow');
if (ta && cn) ta.addEventListener('input', () => { cn.textContent = ta.value.length; });
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
</script>
</body>
</html>
<?php $conn->close(); ?>
