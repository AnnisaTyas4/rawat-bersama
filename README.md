# RawatBersama

Aplikasi koordinasi perawatan pasien untuk administrator, perawat, dan keluarga. Teknologi: PHP Native, MySQL/MariaDB, HTML5, CSS3, dan JavaScript.

## Fitur
- Dashboard ringkasan aktivitas perawatan.
- Role-based access: admin, nurse, family.
- Keluarga hanya dapat melihat pasien yang ditautkan administrator ke akunnya.
- Pendaftaran pasien terpadu: admin dan perawat dapat mendaftarkan pasien sekaligus membuat akun keluarga dan menghubungkannya dalam satu transaksi database. Jika proses pembuatan akun gagal, data pasien ikut dibatalkan.
- Manajemen pasien: admin dapat mengedit, mengarsipkan, dan memulihkan pasien; perawat dapat mendaftarkan pasien baru.
- Manajemen pengguna: admin dapat membuat/mengelola akun admin dan perawat, mengubah kata sandi/peran, serta menonaktifkan atau mengaktifkan kembali akun. Akun keluarga baru wajib dibuat melalui pendaftaran pasien terpadu.
- Jadwal obat: tambah, edit, ubah status diberikan/menunggu, dan hapus (admin).
- Kunjungan dan pantauan kondisi: tambah, edit data milik sendiri (atau seluruh data oleh admin), hapus (admin).
- Pesan: kirim pesan baru, balas pesan tertentu dengan kutipan konteks, tampilkan riwayat percakapan kronologis, edit/hapus pesan milik sendiri; admin dapat mengelola semua pesan.
- Laporan: tambah, edit laporan sendiri; admin dapat mengelola dan menghapus semua laporan.
- Riwayat aktivitas admin untuk mencatat login, tambah, perubahan, arsip, status, dan penghapusan.
- Validasi server, PDO prepared statements, CSRF token, password hashing, session cookie HttpOnly/SameSite.
- Layout responsif desktop, tablet, dan ponsel.

## Persyaratan
- PHP 8.1+ (PDO MySQL; ekstensi mbstring opsional).
- MySQL 5.7+ atau MariaDB 10.4+.
- Apache dengan AllowOverride jika memakai aturan .htaccess.

## Instalasi baru dengan XAMPP
1. Salin/clone repository ke htdocs/rawat-bersama.
2. Jalankan Apache dan MySQL.
3. Buka phpMyAdmin dan impor database/rawat_bersama.sql.
4. Salin config.example.php menjadi config.php, lalu sesuaikan host, nama database, username, dan password.
5. Buka http://localhost/rawat-bersama/setup_admin.php untuk membuat akun admin pertama (password minimal 10 karakter).
6. Setelah akun admin berhasil dibuat, hapus setup_admin.php dari server.
7. Login di http://localhost/rawat-bersama/.
8. Dari menu Manajemen pengguna, buat akun admin/perawat. Untuk mendaftarkan pasien, buka Data Pasien lalu pilih **Daftarkan pasien + akun keluarga**. Isi identitas pasien dan data login keluarga dalam formulir yang sama.

## Upgrade dari database versi awal
Jika database versi awal sudah berisi tabel users, patients, medications, visits, observations, messages, dan reports, jangan impor ulang rawat_bersama.sql. Backup database, lalu jalankan satu kali database/migrations/001_access_control_and_audit.sql melalui phpMyAdmin. Migrasi menambahkan status arsip pasien, relasi keluarga-pasien, dan audit log.

## Upgrade fitur balasan pesan
Jika database sudah menjalankan migrasi 001, backup database lalu jalankan `database/migrations/002_message_replies.sql` satu kali melalui phpMyAdmin. Migrasi ini menambahkan relasi balasan pada tabel `messages` tanpa menghapus pesan lama. Untuk instalasi baru, kolom balasan sudah termasuk dalam `database/rawat_bersama.sql`.

## Hak akses
- **Admin:** semua pasien, pengguna, audit log, seluruh CRUD, pengarsipan dan pemulihan.
- **Perawat:** melihat pasien aktif, mendaftarkan pasien baru beserta akun keluarga, mencatat/mengedit data perawatan yang dibuat sendiri, mengelola jadwal obat dan pesan; pengeditan/arsip data identitas pasien serta penghapusan catatan dibatasi untuk admin.
- **Keluarga:** hanya membaca data pasien yang otomatis ditautkan saat pendaftaran, melihat informasi perawatan, dan mengirim/mengelola pesan miliknya sendiri.

Penghapusan pasien dan pengguna dilakukan dengan arsip/nonaktif agar rekam jejak perawatan dan relasi historis tetap terjaga. Penghapusan catatan perawatan dilakukan secara permanen dan dicatat dalam audit log.

## Catatan keamanan dan penggunaan
- Jangan commit config.php atau kredensial database ke GitHub.
- Gunakan HTTPS pada hosting publik dan backup database secara rutin.
- Batasi akses akun admin; audit log bukan pengganti backup atau audit keamanan independen.
- Sebelum dipakai untuk data pasien nyata, lakukan uji akses tiap role, peninjauan keamanan, kebijakan privasi/retensi, dan validasi alur klinis oleh tenaga kesehatan. Aplikasi ini merupakan alat dokumentasi dan koordinasi, bukan alat diagnosis atau pemberi keputusan medis.