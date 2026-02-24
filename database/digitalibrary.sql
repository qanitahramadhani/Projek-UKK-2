-- =============================================
-- DATABASE: digitalibrary
-- Sistem Perpustakaan Digital
-- =============================================

CREATE DATABASE IF NOT EXISTS digitalibrary CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE digitalibrary;

-- Tabel User (Admin, Petugas, Peminjam)
CREATE TABLE user (
    UserID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Username VARCHAR(255) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Email VARCHAR(255) NOT NULL UNIQUE,
    NamaLengkap VARCHAR(255) NOT NULL,
    Alamat TEXT,
    Role ENUM('administrator','petugas','peminjam') NOT NULL DEFAULT 'peminjam',
    Status ENUM('aktif','nonaktif') DEFAULT 'aktif',
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabel Kategori Buku
CREATE TABLE kategoribuku (
    KategoriID INT(11) AUTO_INCREMENT PRIMARY KEY,
    NamaKategori VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Tabel Buku
CREATE TABLE buku (
    BukuID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Judul VARCHAR(255) NOT NULL,
    Penulis VARCHAR(255) NOT NULL,
    Penerbit VARCHAR(255) NOT NULL,
    TahunTerbit INT(11),
    Stok INT(11) DEFAULT 1,
    Deskripsi TEXT,
    CoverURL VARCHAR(500),
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Relasi Buku - Kategori (Many to Many)
CREATE TABLE kategoribuku_relasi (
    KategoriBukuID INT(11) AUTO_INCREMENT PRIMARY KEY,
    BukuID INT(11) NOT NULL,
    KategoriID INT(11) NOT NULL,
    FOREIGN KEY (BukuID) REFERENCES buku(BukuID) ON DELETE CASCADE,
    FOREIGN KEY (KategoriID) REFERENCES kategoribuku(KategoriID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel Peminjaman
CREATE TABLE peminjaman (
    PeminjamanID INT(11) AUTO_INCREMENT PRIMARY KEY,
    UserID INT(11) NOT NULL,
    BukuID INT(11) NOT NULL,
    TanggalPeminjaman DATE NOT NULL,
    TanggalPengembalian DATE NOT NULL,
    TanggalKembaliAktual DATE NULL,
    StatusPeminjaman ENUM('menunggu','dipinjam','dikembalikan','terlambat') DEFAULT 'menunggu',
    Denda DECIMAL(10,2) DEFAULT 0,
    Keterangan TEXT,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES user(UserID) ON DELETE CASCADE,
    FOREIGN KEY (BukuID) REFERENCES buku(BukuID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel Koleksi Pribadi
CREATE TABLE koleksipribadi (
    KoleksiID INT(11) AUTO_INCREMENT PRIMARY KEY,
    UserID INT(11) NOT NULL,
    BukuID INT(11) NOT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES user(UserID) ON DELETE CASCADE,
    FOREIGN KEY (BukuID) REFERENCES buku(BukuID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel Ulasan Buku
CREATE TABLE ulasanbuku (
    UlasanID INT(11) AUTO_INCREMENT PRIMARY KEY,
    UserID INT(11) NOT NULL,
    BukuID INT(11) NOT NULL,
    Ulasan TEXT,
    Rating INT(11) CHECK (Rating BETWEEN 1 AND 5),
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES user(UserID) ON DELETE CASCADE,
    FOREIGN KEY (BukuID) REFERENCES buku(BukuID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- SEED DATA
-- =============================================

-- Users (password: 'password123' di-hash dengan bcrypt)
INSERT INTO user (Username, Password, Email, NamaLengkap, Alamat, Role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@digitalibrary.com', 'Administrator', 'Jl. Perpustakaan No. 1', 'administrator'),
('petugas1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'petugas@digitalibrary.com', 'Budi Santoso', 'Jl. Merdeka No. 5', 'petugas'),
('user1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user@digitalibrary.com', 'Siti Rahayu', 'Jl. Kebon Jeruk No. 12', 'peminjam');

-- Kategori
INSERT INTO kategoribuku (NamaKategori) VALUES
('Fiksi'), ('Non-Fiksi'), ('Sains'), ('Teknologi'), ('Sejarah'), ('Sastra'), ('Pendidikan'), ('Biografi');

-- Buku
INSERT INTO buku (Judul, Penulis, Penerbit, TahunTerbit, Stok, Deskripsi) VALUES
('Laskar Pelangi', 'Andrea Hirata', 'Bentang Pustaka', 2005, 5, 'Novel tentang semangat belajar anak-anak Belitung'),
('Bumi Manusia', 'Pramoedya Ananta Toer', 'Lentera Dipantara', 1980, 3, 'Tetralogi Buru tentang perjuangan bangsa'),
('Pemrograman Web PHP', 'Wahana Komputer', 'Andi Publisher', 2020, 4, 'Panduan lengkap pemrograman PHP untuk web'),
('Sejarah Indonesia Modern', 'M.C. Ricklefs', 'Gadjah Mada University Press', 2008, 2, 'Sejarah Indonesia dari abad ke-18 hingga kini'),
('Clean Code', 'Robert C. Martin', 'Prentice Hall', 2008, 3, 'Panduan menulis kode yang bersih dan maintainable');

-- Relasi Buku-Kategori
INSERT INTO kategoribuku_relasi (BukuID, KategoriID) VALUES
(1,1),(1,6),(2,1),(2,6),(3,4),(3,7),(4,5),(5,4);

-- Sample Peminjaman
INSERT INTO peminjaman (UserID, BukuID, TanggalPeminjaman, TanggalPengembalian, StatusPeminjaman) VALUES
(3, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'dipinjam'),
(3, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'terlambat');
