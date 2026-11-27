<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('Database belum dapat dihubungkan. Periksa config.php dan pastikan MySQL aktif.');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
$count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
    if (!hash_equals($_SESSION['setup_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'Sesi tidak valid. Muat ulang halaman.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 10) {
            try {
                $stmt = $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,'admin','active')");
                $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
                $message = 'Admin berhasil dibuat. Demi keamanan, hapus file setup_admin.php sekarang.';
                $count = 1;
            } catch (PDOException $exception) {
                $message = (string)$exception->getCode() === '23000' ? 'Email sudah digunakan.' : 'Admin gagal dibuat. Periksa database.';
            }
        } else {
            $message = 'Lengkapi nama, email valid, dan kata sandi minimal 10 karakter.';
        }
    }
}
function setup_e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup Admin · RawatBersama</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/admin.css"></head><body class="login-body"><main class="login-card"><a class="brand" href="index.php"><span class="brand-mark">R+</span><span><b>RawatBersama</b><small>ADMINISTRATOR SETUP</small></span></a><div class="login-heading"><h1>Buat administrator pertama</h1><p>Gunakan kata sandi yang kuat dan simpan dengan aman.</p></div><?php if ($message !== ''): ?><div class="alert <?= $count > 0 ? 'success' : 'error' ?>"><?=setup_e($message)?></div><?php endif; ?><?php if ($count === 0): ?><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=setup_e($_SESSION['setup_csrf'])?>"><label>Nama lengkap<input name="name" maxlength="120" required></label><label>Email<input type="email" name="email" maxlength="190" required></label><label>Kata sandi (minimal 10 karakter)<input type="password" name="password" minlength="10" required></label><button class="btn btn-primary btn-wide" type="submit">Buat akun admin</button></form><?php else: ?><p class="login-foot">Setup dinonaktifkan karena akun admin sudah ada. Hapus file setup_admin.php melalui File Manager/FTP.</p><?php endif; ?></main></body></html>