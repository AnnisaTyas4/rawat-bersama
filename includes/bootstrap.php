<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private');
    if ($isHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><title>Konfigurasi belum tersedia</title><h2>Konfigurasi database belum tersedia</h2><p>Salin <code>config.example.php</code> menjadi <code>config.php</code>, lalu isi koneksi database.</p>');
}
require $configFile;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = (string)($_POST['csrf'] ?? '');
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid atau sudah kedaluwarsa. Muat ulang halaman dan coba lagi.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: index.php?page=' . rawurlencode($page));
    exit;
}

function current_user(): ?array
{
    static $loaded = false;
    static $cached = null;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        return null;
    }
    $cached = $user;
    return $cached;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        flash('Anda tidak memiliki izin untuk melakukan tindakan tersebut.', 'error');
        redirect('dashboard');
    }
    return $user;
}

function audit_log(int $actorId, string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, entity, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $actorId,
        $action,
        $entity,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function accessible_patient_ids(?array $user = null): array
{
    $user ??= require_login();
    if (in_array($user['role'], ['admin', 'nurse'], true)) {
        $rows = db()->query("SELECT id FROM patients WHERE status = 'active'")->fetchAll();
        return array_map('intval', array_column($rows, 'id'));
    }
    $stmt = db()->prepare("SELECT pf.patient_id FROM patient_families pf INNER JOIN patients p ON p.id = pf.patient_id WHERE pf.user_id = ? AND p.status = 'active' ORDER BY p.name");
    $stmt->execute([(int)$user['id']]);
    return array_map('intval', array_column($stmt->fetchAll(), 'patient_id'));
}

function patient_scope(string $column, ?array $user = null): array
{
    $user ??= require_login();
    if ($user['role'] !== 'family') {
        return ['1=1', []];
    }
    $ids = accessible_patient_ids($user);
    if (!$ids) {
        return ['1=0', []];
    }
    return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function assert_patient_access(int $patientId, ?array $user = null): void
{
    $user ??= require_login();
    $stmt = db()->prepare("SELECT id FROM patients WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$patientId]);
    if (!$stmt->fetch()) {
        throw new DomainException('Pasien tidak ditemukan atau sudah diarsipkan.');
    }
    if ($user['role'] === 'family') {
        $stmt = db()->prepare('SELECT 1 FROM patient_families WHERE user_id = ? AND patient_id = ? LIMIT 1');
        $stmt->execute([(int)$user['id'], $patientId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Anda tidak memiliki akses ke data pasien tersebut.');
        }
    }
}

function record_owner(string $table, int $id): ?array
{
    $allowed = ['visits', 'observations', 'reports', 'messages'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Jenis data tidak valid.');
    }
    $ownerColumn = [
        'visits' => 'nurse_id',
        'observations' => 'recorded_by',
        'reports' => 'author_id',
        'messages' => 'sender_id',
    ][$table];
    $stmt = db()->prepare("SELECT id, patient_id, {$ownerColumn} AS owner_id FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function assert_record_editable(string $table, int $id, array $user): array
{
    $record = record_owner($table, $id);
    if (!$record) {
        throw new DomainException('Data tidak ditemukan.');
    }
    assert_patient_access((int)$record['patient_id'], $user);
    if ($user['role'] !== 'admin' && (int)$record['owner_id'] !== (int)$user['id']) {
        throw new DomainException('Anda hanya dapat mengubah data yang Anda buat.');
    }
    return $record;
}

function sync_family_patients(int $userId, string $role, array $patientIds): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM patient_families WHERE user_id = ?')->execute([$userId]);
    if ($role !== 'family') {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $patientIds), static fn($id) => $id > 0)));
    if (!$ids) {
        throw new DomainException('Setiap akun keluarga harus terhubung dengan minimal satu pasien.');
    }
    $check = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND status = 'active'");
    $insert = $pdo->prepare('INSERT INTO patient_families (user_id, patient_id) VALUES (?, ?)');
    foreach ($ids as $patientId) {
        $check->execute([$patientId]);
        if (!$check->fetchColumn()) {
            throw new DomainException('Salah satu pasien yang dipilih tidak tersedia.');
        }
        $insert->execute([$userId, $patientId]);
    }
}

function post_value(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_id(string $key = 'id'): int
{
    $id = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $id && $id > 0 ? (int)$id : 0;
}

function valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function valid_datetime_local(string $value): ?string
{
    $date = DateTime::createFromFormat('!Y-m-d\\TH:i', $value);
    return $date && $date->format('Y-m-d\\TH:i') === $value ? $date->format('Y-m-d H:i:s') : null;
}

require __DIR__ . '/actions.php';
