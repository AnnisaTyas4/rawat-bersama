# RawatBersama

Aplikasi koordinasi perawatan pasien untuk keluarga, perawat, dan administrator. Dibangun dengan PHP Native, MySQL, HTML, CSS, dan JavaScript.

## Fitur
- Login berbasis peran: admin, perawat, keluarga.
- Dashboard admin dengan statistik pasien, perawat, jadwal obat, dan laporan.
- Manajemen pasien dan pengguna.
- Jadwal obat dan pencatatan status pemberian.
- Catatan kunjungan dan pemantauan kondisi.
- Pesan keluarga/perawat.
- Laporan harian pasien.
- Layout responsif untuk desktop dan mobile.
- Query database menggunakan PDO prepared statements, password hashing, dan token CSRF.

## Instalasi XAMPP
1. Clone/download repository ke folder `htdocs/rawat-bersama`.
2. Impor `database/rawat_bersama.sql` melalui phpMyAdmin.
3. Salin `config.example.php` menjadi `config.php`, lalu sesuaikan kredensial database.
4. Buka `http://localhost/rawat-bersama/setup_admin.php` dan buat admin (password minimal 10 karakter).
5. Hapus `setup_admin.php` setelah admin berhasil dibuat.
6. Masuk melalui `http://localhost/rawat-bersama/`.

## Persyaratan
PHP 8.1+ dengan PDO MySQL, MySQL 5.7+/MariaDB 10.4+, Apache/Nginx.

## Catatan keamanan
File `config.php` tidak disertakan agar kredensial tidak masuk Git. Buat akun perawat/keluarga melalui Manajemen pengguna. Untuk penggunaan klinis/produksi, tambahkan pembatasan akses per pasien, audit log immutable, backup terenkripsi, kebijakan retensi, HTTPS, serta peninjauan keamanan dan alur klinis. Aplikasi ini bukan alat diagnosis.