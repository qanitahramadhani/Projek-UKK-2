<?php
// includes/form_buku.php — Partial form untuk tambah/edit buku
// Gunakan dalam modal. $conn harus tersedia.
if (!isset($conn)) {
    require_once __DIR__ . '/../config/database.php';
    $conn = getConnection();
}
$cats = $conn->query("SELECT * FROM kategoribuku ORDER BY NamaKategori");
?>
<div class="form-row">
  <div class="form-group">
    <label>Judul Buku *</label>
    <input type="text" name="judul" required placeholder="Masukkan judul buku">
  </div>
  <div class="form-group">
    <label>Penulis *</label>
    <input type="text" name="penulis" required placeholder="Nama penulis">
  </div>
</div>
<div class="form-row">
  <div class="form-group">
    <label>Penerbit</label>
    <input type="text" name="penerbit" placeholder="Nama penerbit">
  </div>
  <div class="form-group">
    <label>Tahun Terbit</label>
    <input type="number" name="tahun" placeholder="2024" min="1900" max="2099">
  </div>
</div>
<div class="form-group">
  <label>Stok</label>
  <input type="number" name="stok" value="1" min="0">
</div>
<div class="form-group">
  <label>Kategori</label>
  <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px;border:1.5px solid var(--border);border-radius:8px;background:white">
    <?php while ($cat = $cats->fetch_assoc()): ?>
      <label style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:400;cursor:pointer">
        <input type="checkbox" name="kategori[]" value="<?= $cat['KategoriID'] ?>">
        <?= htmlspecialchars($cat['NamaKategori']) ?>
      </label>
    <?php endwhile; ?>
  </div>
</div>
<div class="form-group">
  <label>Deskripsi</label>
  <textarea name="deskripsi" rows="3" placeholder="Deskripsi singkat buku..."></textarea>
</div>
