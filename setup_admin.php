<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$count=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$message='';
if($_SERVER['REQUEST_METHOD']==='POST' && $count===0){
 $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$password=$_POST['password']??'';
 if($name && filter_var($email,FILTER_VALIDATE_EMAIL) && strlen($password)>=10){
  $q=$pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,'admin','active')");
  $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
  $message='Admin berhasil dibuat. Hapus setup_admin.php sekarang, lalu masuk melalui index.php.';
  $count=1;
 }else $message='Lengkapi nama, email valid, dan kata sandi minimal 10 karakter.';
}
?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup admin</title><link rel="stylesheet" href="assets/style.css"><body class="login-body"><main class="login-card"><h1>Setup administrator</h1><?php if($message) echo '<div class="alert">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div>';if($count===0):?><form method="post"><label>Nama</label><input name="name" required><label>Email</label><input type="email" name="email" required><label>Kata sandi (min. 10 karakter)</label><input type="password" name="password" minlength="10" required><button class="btn btn-primary btn-wide">Buat admin</button></form><?php else:?><p>Setup dinonaktifkan karena akun admin sudah ada.</p><?php endif;?></main></body></html>