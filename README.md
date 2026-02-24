# 📚 DigiLibrary — Sistem Perpustakaan Digital

Sistem manajemen perpustakaan berbasis web (PHP + MySQL) dengan 3 tingkat akses: **Administrator**, **Petugas**, dan **Peminjam**.

---

## 📁 Struktur Folder

```
digitalibrary/
│
├── index.php                    ← Entry point (auto-redirect)
│
├── config/
│   └── database.php             ← Konfigurasi koneksi database
│
├── database/
│   └── digitalibrary.sql        ← File SQL (import ke MySQL)
│
├── includes/
│   ├── auth.php                 ← Helper autentikasi & sesi
│   ├── sidebar.php              ← Komponen sidebar (reusable)
│   └── form_buku.php            ← Form buku (partial reusable)
│
├── auth/
│   ├── login.php                ← Halaman login
│   ├── register.php             ← Halaman registrasi
│   ├── logout.php               ← Proses logout
│   └── unauthorized.php         ← Halaman akses ditolak
│
├── public/
│   └── css/
│       ├── auth.css             ← Style halaman login/register
│       └── main.css             ← Style utama dashboard
│
└── views/
    ├── admin/
    │   ├── dashboard.php        ← Dashboard administrator
    │   ├── users.php            ← CRUD pengguna
    │   ├── buku.php             ← CRUD buku
    │   ├── kategori.php         ← CRUD kategori
    │   ├── peminjaman.php       ← CRUD peminjaman
    │   └── laporan.php          ← Generate laporan
    │
    ├── petugas/
    │   ├── dashboard.php        ← Dashboard petugas
    │   ├── buku.php             ← CRUD buku
    │   ├── peminjaman.php       ← Kelola peminjaman
    │   └── laporan.php          ← Generate laporan
    │
    └── peminjam/
        ├── dashboard.php        ← Dashboard peminjam
        ├── katalog.php          ← Katalog + pinjam buku
        ├── peminjaman.php       ← Riwayat peminjaman
        ├── koleksi.php          ← Koleksi/wishlist pribadi
        └── profil.php           ← Edit profil & password
```

---

## ⚙️ Cara Instalasi

### 1. Persyaratan
- PHP >= 7.4
- MySQL >= 5.7
- Web Server: Apache / XAMPP / WAMP / Laragon

### 2. Setup Database
```sql
-- Buka phpMyAdmin atau MySQL CLI
-- Import file:
database/digitalibrary.sql
```

### 3. Konfigurasi Database
Edit file `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // ← username MySQL Anda
define('DB_PASS', '');           // ← password MySQL Anda
define('DB_NAME', 'digitalibrary');
```

### 4. Tempatkan di Web Server
- **XAMPP**: Salin folder ke `C:/xampp/htdocs/digitalibrary`
- **Laragon**: Salin ke `C:/laragon/www/digitalibrary`

### 5. Akses di Browser
```
http://localhost/digitalibrary/
```

---

## 🔑 Akun Default (password: `password`)

| Role           | Username   | Password  |
|----------------|------------|-----------|
| Administrator  | admin      | password  |
| Petugas        | petugas1   | password  |
| Peminjam       | user1      | password  |

> ⚠️ **Ganti password** setelah pertama kali masuk!

---

## 🎯 Fitur per Role

| Fitur                   | Admin | Petugas | Peminjam |
|-------------------------|:-----:|:-------:|:--------:|
| Login / Logout          | ✅    | ✅      | ✅       |
| Registrasi              |       |         | ✅       |
| CRUD Pengguna           | ✅    |         |          |
| CRUD Buku               | ✅    | ✅      |          |
| CRUD Kategori           | ✅    |         |          |
| Kelola Peminjaman       | ✅    | ✅      |          |
| Lihat Katalog & Pinjam  |       |         | ✅       |
| Riwayat Peminjaman      |       |         | ✅       |
| Koleksi Pribadi         |       |         | ✅       |
| Generate Laporan        | ✅    | ✅      |          |
| Edit Profil             |       |         | ✅       |

---

## 🗄️ Skema Database

- **user** — Data semua pengguna (admin/petugas/peminjam)
- **buku** — Koleksi buku perpustakaan
- **kategoribuku** — Master kategori buku
- **kategoribuku_relasi** — Relasi many-to-many buku-kategori
- **peminjaman** — Transaksi peminjaman buku
- **koleksipribadi** — Wishlist pribadi peminjam
- **ulasanbuku** — Ulasan dan rating buku

---

## 🔒 Keamanan
- Password di-hash menggunakan `password_hash()` PHP (bcrypt)
- Setiap halaman dicek role dengan `requireRole()`
- Input form di-sanitasi dengan `htmlspecialchars()` dan prepared statements

---

*Dibuat untuk keperluan sistem perpustakaan digital. Lisensi bebas digunakan.*
