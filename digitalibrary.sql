-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 24 Feb 2026 pada 07.32
-- Versi server: 10.3.16-MariaDB
-- Versi PHP: 7.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `digitalibrary`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `buku`
--

CREATE TABLE `buku` (
  `BukuID` int(11) NOT NULL,
  `Judul` varchar(255) NOT NULL,
  `Penulis` varchar(255) NOT NULL,
  `Penerbit` varchar(255) NOT NULL,
  `TahunTerbit` int(11) DEFAULT NULL,
  `Stok` int(11) DEFAULT 1,
  `Deskripsi` text DEFAULT NULL,
  `CoverURL` varchar(500) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `KategoriID` int(11) NOT NULL,
  `DendaPerHari` int(11) NOT NULL DEFAULT 5000
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data untuk tabel `buku`
--

INSERT INTO `buku` (`BukuID`, `Judul`, `Penulis`, `Penerbit`, `TahunTerbit`, `Stok`, `Deskripsi`, `CoverURL`, `CreatedAt`, `KategoriID`, `DendaPerHari`) VALUES
(4, 'lkasa', '', '2019', 2026, 6, 'cnskks', 'cover_699cfe5d4a951.png', '2026-02-20 20:54:06', 1, 5000),
(6, 'bumi', 'tereliye', 'tarzan', 2026, 4, 'novel', 'cover_699cfe1dcfbe1.png', '2026-02-20 20:56:16', 1, 5000),
(7, 'roblox gengs', 'nata', 'limbat', 2026, 5, 'roblox game baik', 'cover_6999767c87b8a.png', '2026-02-21 08:29:26', 1, 5000),
(8, 'Laskar Pelangi', 'nata', 'limbat', 2026, 16, 'aww', '', '2026-02-21 09:01:32', 1, 5000),
(9, 'roblox bagus', 'roblox', '2024', 2026, 4, 'roblox bagus wkwkwkwk', 'cover_699be3d834a9e.png', '2026-02-23 00:47:50', 1, 5000);

-- --------------------------------------------------------

--
-- Struktur dari tabel `kategoribuku`
--

CREATE TABLE `kategoribuku` (
  `KategoriID` int(11) NOT NULL,
  `NamaKategori` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data untuk tabel `kategoribuku`
--

INSERT INTO `kategoribuku` (`KategoriID`, `NamaKategori`) VALUES
(1, 'novel'),
(2, 'sejarah'),
(3, 'sains');

-- --------------------------------------------------------

--
-- Struktur dari tabel `kategoribuku_relasi`
--

CREATE TABLE `kategoribuku_relasi` (
  `KategoriBukuID` int(11) NOT NULL,
  `BukuID` int(11) NOT NULL,
  `KategoriID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struktur dari tabel `koleksipribadi`
--

CREATE TABLE `koleksipribadi` (
  `KoleksiID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `BukuID` int(11) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data untuk tabel `koleksipribadi`
--

INSERT INTO `koleksipribadi` (`KoleksiID`, `UserID`, `BukuID`, `CreatedAt`) VALUES
(3, 2, 6, '2026-02-21 07:27:22'),
(4, 2, 4, '2026-02-21 07:52:36'),
(5, 7, 4, '2026-02-23 02:14:07'),
(6, 7, 6, '2026-02-23 03:59:12');

-- --------------------------------------------------------

--
-- Struktur dari tabel `peminjaman`
--

CREATE TABLE `peminjaman` (
  `PeminjamanID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `BukuID` int(11) NOT NULL,
  `TanggalPeminjaman` date NOT NULL,
  `TanggalPengembalian` date NOT NULL,
  `TanggalKembaliAktual` date DEFAULT NULL,
  `StatusPeminjaman` enum('menunggu','dipinjam','dikembalikan','terlambat') DEFAULT 'menunggu',
  `TotalDenda` decimal(10,2) NOT NULL DEFAULT 0.00,
  `StatusBayarDenda` enum('Lunas','Belum') DEFAULT NULL,
  `Denda` decimal(10,2) DEFAULT 0.00,
  `Keterangan` text DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data untuk tabel `peminjaman`
--

INSERT INTO `peminjaman` (`PeminjamanID`, `UserID`, `BukuID`, `TanggalPeminjaman`, `TanggalPengembalian`, `TanggalKembaliAktual`, `StatusPeminjaman`, `TotalDenda`, `StatusBayarDenda`, `Denda`, `Keterangan`, `CreatedAt`) VALUES
(1, 2, 6, '2026-02-21', '2026-02-28', '2026-02-23', 'dikembalikan', '0.00', NULL, '0.00', NULL, '2026-02-21 07:19:31'),
(3, 2, 8, '2026-02-21', '2026-02-22', '2026-02-23', 'dikembalikan', '5000.00', 'Belum', '0.00', NULL, '2026-02-21 12:16:26'),
(4, 2, 4, '2026-02-23', '2026-02-26', '2026-02-23', 'dikembalikan', '0.00', NULL, '0.00', NULL, '2026-02-23 00:35:50'),
(5, 7, 6, '2026-02-23', '2026-02-24', NULL, 'dipinjam', '0.00', NULL, '0.00', NULL, '2026-02-23 02:13:21'),
(6, 7, 9, '2026-02-24', '2026-02-27', NULL, 'dipinjam', '0.00', NULL, '0.00', NULL, '2026-02-24 06:13:38');

-- --------------------------------------------------------

--
-- Struktur dari tabel `ulasanbuku`
--

CREATE TABLE `ulasanbuku` (
  `UlasanID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `BukuID` int(11) NOT NULL,
  `Ulasan` text DEFAULT NULL,
  `Rating` int(11) DEFAULT NULL
) ;

--
-- Dumping data untuk tabel `ulasanbuku`
--

INSERT INTO `ulasanbuku` (`UlasanID`, `UserID`, `BukuID`, `Ulasan`, `Rating`, `CreatedAt`) VALUES
(2, 2, 6, 'bagus banget ih', 5, '2026-02-21 07:46:54'),
(3, 7, 6, 'jelek', 3, '2026-02-23 02:15:43'),
(4, 7, 9, 'IHH JELEK BANGET', 4, '2026-02-24 06:22:12');

-- --------------------------------------------------------

--
-- Struktur dari tabel `user`
--

CREATE TABLE `user` (
  `UserID` int(11) NOT NULL,
  `Username` varchar(255) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `NamaLengkap` varchar(255) NOT NULL,
  `Alamat` text DEFAULT NULL,
  `Role` enum('administrator','petugas','peminjam') NOT NULL DEFAULT 'peminjam',
  `Status` enum('aktif','nonaktif') DEFAULT 'aktif',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data untuk tabel `user`
--

INSERT INTO `user` (`UserID`, `Username`, `Password`, `Email`, `NamaLengkap`, `Alamat`, `Role`, `Status`, `CreatedAt`) VALUES
(1, 'nataa', '$2y$10$2oi0XvRdKcc.ksbMl0x6/.rkCqMfyJ2tCYvPA.T9m1964yaHH4db6', 'nataaaitaa@gmail.com', 'qanitah', 'jl.urip sumoharjo', 'peminjam', 'aktif', '2026-02-20 13:49:56'),
(2, 'fathir', '$2y$10$YCPkYcAkRqLFDImiHSjgYOhjQF3U0vCCk6MvCYJAu/B6gn/9tUL9i', 'fathir@gmail.com', 'fathir', 'jl urip sumoharjo', 'peminjam', 'aktif', '2026-02-20 13:53:27'),
(3, 'nataaa', '$2y$10$KvsspNVVWVUJv4yfvH.4k.iS/rLEjDhUmEOZvolvN39iJ47PAZ7VC', 'nataaa@gmail.com', 'MUHAMMAD Fathir Fathur Rahman', '', 'peminjam', 'aktif', '2026-02-20 14:41:57'),
(4, 'ramdhani', '$2y$10$WSN4BqJis/mga8tU8xUwveeg/NCQfzr7VqpLCuyC93KgWeM8boElO', 'ramdhani@gmail.com', 'qanitah ramadhani nur', 'urip', 'administrator', 'aktif', '2026-02-20 20:13:46'),
(5, 'admin', '$2y$10$yTCl8ZPk1KTESWfPZDln3uHWlh5CWs6rLSuK9QgvrXXaGZQ3BVlaG', 'admin@gmail.com', 'adminperpus', '', 'administrator', 'aktif', '2026-02-21 08:07:32'),
(6, 'petugas', '$2y$10$DB9WiX2SkZfBqA.j3.syU.iPC8mIsKguS7cKH2OFovZWPngfXvQMm', 'petugas@gmail.com', 'petugas', 'mksr', 'petugas', 'aktif', '2026-02-23 01:49:49'),
(7, 'nataacantik', '$2y$10$KWF/MkMLd7a5dKh8ccu5FeB.cb90/nlkz5WPuIPJFoRnqFddPNIye', 'nataacantik@gmail.com', 'nataacantik', 'makassar', 'peminjam', 'aktif', '2026-02-23 02:11:32');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `buku`
--
ALTER TABLE `buku`
  ADD PRIMARY KEY (`BukuID`);

--
-- Indeks untuk tabel `kategoribuku`
--
ALTER TABLE `kategoribuku`
  ADD PRIMARY KEY (`KategoriID`);

--
-- Indeks untuk tabel `kategoribuku_relasi`
--
ALTER TABLE `kategoribuku_relasi`
  ADD PRIMARY KEY (`KategoriBukuID`),
  ADD KEY `BukuID` (`BukuID`),
  ADD KEY `KategoriID` (`KategoriID`);

--
-- Indeks untuk tabel `koleksipribadi`
--
ALTER TABLE `koleksipribadi`
  ADD PRIMARY KEY (`KoleksiID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `BukuID` (`BukuID`);

--
-- Indeks untuk tabel `peminjaman`
--
ALTER TABLE `peminjaman`
  ADD PRIMARY KEY (`PeminjamanID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `BukuID` (`BukuID`);

--
-- Indeks untuk tabel `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `Email` (`Email`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `buku`
--
ALTER TABLE `buku`
  MODIFY `BukuID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `kategoribuku`
--
ALTER TABLE `kategoribuku`
  MODIFY `KategoriID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `kategoribuku_relasi`
--
ALTER TABLE `kategoribuku_relasi`
  MODIFY `KategoriBukuID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `koleksipribadi`
--
ALTER TABLE `koleksipribadi`
  MODIFY `KoleksiID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `peminjaman`
--
ALTER TABLE `peminjaman`
  MODIFY `PeminjamanID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `ulasanbuku`
--
ALTER TABLE `ulasanbuku`
  MODIFY `UlasanID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `user`
--
ALTER TABLE `user`
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `kategoribuku_relasi`
--
ALTER TABLE `kategoribuku_relasi`
  ADD CONSTRAINT `kategoribuku_relasi_ibfk_1` FOREIGN KEY (`BukuID`) REFERENCES `buku` (`BukuID`) ON DELETE CASCADE,
  ADD CONSTRAINT `kategoribuku_relasi_ibfk_2` FOREIGN KEY (`KategoriID`) REFERENCES `kategoribuku` (`KategoriID`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `koleksipribadi`
--
ALTER TABLE `koleksipribadi`
  ADD CONSTRAINT `koleksipribadi_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `koleksipribadi_ibfk_2` FOREIGN KEY (`BukuID`) REFERENCES `buku` (`BukuID`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `peminjaman`
--
ALTER TABLE `peminjaman`
  ADD CONSTRAINT `peminjaman_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `peminjaman_ibfk_2` FOREIGN KEY (`BukuID`) REFERENCES `buku` (`BukuID`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
