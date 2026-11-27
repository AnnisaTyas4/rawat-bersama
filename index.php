<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
try {
    $pdo = db();
} catch (Throwable $exception) {
    error_log('RawatBersama database connection error: ' . $exception->getMessage());
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><title>Database belum siap</title><h2>Database belum dapat dihubungkan</h2><p>Periksa konfigurasi <code>config.php</code>, pastikan MySQL aktif, dan impor database RawatBersama melalui phpMyAdmin.</p>');
}
handle_post();

$me = current_user();
$page = (string)($_GET['page'] ?? ($me ? 'dashboard' : 'login'));
$allowedPages = ['dashboard','patients','patient_detail','users','medications','visits','observations','messages','reports','audit','login'];
if (!in_array($page, $allowedPages, true)) $page = 'dashboard';
if (!$me && $page !== 'login') redirect('login');
if ($me && $page === 'login') redirect('dashboard');
if ($me && $page !== 'login' && in_array($page, ['users','audit'], true) && $me['role'] !== 'admin') {
    flash('Halaman tersebut hanya dapat diakses administrator.', 'error');
    redirect('dashboard');
}

$role = $me['role'] ?? null;
$canWriteCare = $me && in_array($role, ['admin','nurse'], true);
$pdo = $me ? db() : null;

function scoped_rows(string $sql, string $patientColumn, string $tail = '', array $extraParams = []): array
{
    [$scope, $params] = patient_scope($patientColumn);
    $stmt = db()->prepare($sql . ' WHERE ' . $scope . ' ' . $tail);
    $stmt->execute([...$params, ...$extraParams]);
    return $stmt->fetchAll();
}

function search_clause(array $columns, string $query): array
{
    if ($query === '') {
        return ['', []];
    }
    $parts = array_map(static fn(string $column): string => $column . ' LIKE ?', $columns);
    return ['(' . implode(' OR ', $parts) . ')', array_fill(0, count($columns), '%' . $query . '%')];
}

function scoped_count(string $table, string $column, string $extra = '', array $extraParams = []): int
{
    [$scope, $params] = patient_scope($column);
    $stmt = db()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $scope . ' ' . $extra);
    $stmt->execute([...$params, ...$extraParams]);
    return (int)$stmt->fetchColumn();
}

function patient_select_options(array $patients, int $selected = 0): string
{
    $html = '';
    foreach ($patients as $patient) {
        $id = (int)$patient['id'];
        $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . e($patient['name']) . '</option>';
    }
    return $html;
}

function form_hidden(string $action, string $returnPage): string
{
    return csrf_input() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="return_page" value="' . e($returnPage) . '">';
}

function page_heading(string $title, string $description): void
{
    echo '<div class="page-intro"><div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div></div>';
}

$activePatients = [];
$patientRows = [];
$patientAssignments = [];
$patientDetail = null;
$patientDetailObservation = null;
$patientDetailVisits = [];
$patientDetailObservations = [];
$patientDetailMedications = [];
$patientDetailReports = [];
$searchQuery = trim(substr((string)($_GET['q'] ?? ''), 0, 100));
$patientSearch = trim(substr((string)($_GET['patient_search'] ?? ($page === 'patients' ? $searchQuery : '')), 0, 100));
$selectedPatientFilter = max(0, (int)($_GET['patient_id'] ?? 0));
$patientStatusFilter = in_array(($_GET['status'] ?? 'all'), ['active','inactive','all'], true) ? (string)($_GET['status'] ?? 'all') : 'all';
$medicationStatusFilter = in_array(($_GET['status'] ?? 'all'), ['pending','given','all'], true) ? (string)($_GET['status'] ?? 'all') : 'all';
$users = [];
$medications = [];
$visits = [];
$observations = [];
$messages = [];
$reports = [];
$auditRows = [];
$stats = ['patients'=>0,'nurses'=>0,'meds'=>0,'reports'=>0,'visitsToday'=>0,'observationsToday'=>0];
$selectedPatient = null;
$latestObservation = null;
$recentVisits = [];
$reminders = [];
$flashMessage = take_flash();

if ($me) {
    $activePatients = $role === 'family'
        ? scoped_rows('SELECT p.* FROM patients p', 'p.id', "AND p.status='active' ORDER BY p.name")
        : $pdo->query("SELECT * FROM patients WHERE status='active' ORDER BY name")->fetchAll();

    [$patientSearchSql, $patientSearchParams] = search_clause(
        ['p.name', 'p.diagnosis', 'p.family_contact', 'CAST(p.id AS CHAR)'],
        $patientSearch
    );
    [$patientScope, $patientScopeParams] = patient_scope('p.id', $me);
    $patientWhere = [$patientScope];
    $patientParams = $patientScopeParams;
    if ($role !== 'admin' || $patientStatusFilter !== 'all') {
        $patientWhere[] = "p.status = ?";
        $patientParams[] = $role === 'admin' ? $patientStatusFilter : 'active';
    }
    if ($patientSearchSql !== '') {
        $patientWhere[] = $patientSearchSql;
        $patientParams = [...$patientParams, ...$patientSearchParams];
    }
    $patientSql = 'SELECT p.* FROM patients p WHERE ' . implode(' AND ', $patientWhere) . ' ORDER BY p.status ASC, p.name ASC';
    $patientStmt = $pdo->prepare($patientSql);
    $patientStmt->execute($patientParams);
    $patientRows = $patientStmt->fetchAll();

    if ($page === 'patient_detail') {
        $detailId = max(0, (int)($_GET['id'] ?? 0));
        $detailStmt = $pdo->prepare("SELECT p.* FROM patients p WHERE p.id = ? AND p.status = 'active' LIMIT 1");
        $detailStmt->execute([$detailId]);
        $patientDetail = $detailStmt->fetch() ?: null;
        if (!$patientDetail || !in_array($detailId, accessible_patient_ids($me), true)) {
            flash('Profil pasien tidak ditemukan atau Anda tidak memiliki akses.', 'error');
            redirect('patients');
        }
        $detailStmt = $pdo->prepare('SELECT o.*,u.name AS recorder FROM observations o INNER JOIN users u ON u.id=o.recorded_by WHERE o.patient_id=? ORDER BY o.recorded_at DESC,o.id DESC LIMIT 1');
        $detailStmt->execute([$detailId]);
        $patientDetailObservation = $detailStmt->fetch() ?: null;
        $detailStmt = $pdo->prepare('SELECT v.*,u.name AS nurse FROM visits v INNER JOIN users u ON u.id=v.nurse_id WHERE v.patient_id=? ORDER BY v.visit_at DESC,v.id DESC LIMIT 6');
        $detailStmt->execute([$detailId]);
        $patientDetailVisits = $detailStmt->fetchAll();
        $detailStmt = $pdo->prepare('SELECT * FROM observations WHERE patient_id=? ORDER BY recorded_at DESC,id DESC LIMIT 6');
        $detailStmt->execute([$detailId]);
        $patientDetailObservations = $detailStmt->fetchAll();
        $detailStmt = $pdo->prepare('SELECT * FROM medications WHERE patient_id=? ORDER BY status ASC,scheduled_time ASC,id DESC LIMIT 8');
        $detailStmt->execute([$detailId]);
        $patientDetailMedications = $detailStmt->fetchAll();
        $detailStmt = $pdo->prepare('SELECT r.*,u.name AS author FROM reports r INNER JOIN users u ON u.id=r.author_id WHERE r.patient_id=? ORDER BY r.report_date DESC,r.id DESC LIMIT 5');
        $detailStmt->execute([$detailId]);
        $patientDetailReports = $detailStmt->fetchAll();
    }

    if ($page === 'dashboard') {
        $selectedId = (int)($_GET['patient_id'] ?? ($activePatients[0]['id'] ?? 0));
        foreach ($activePatients as $candidate) {
            if ((int)$candidate['id'] === $selectedId) { $selectedPatient = $candidate; break; }
        }
        if (!$selectedPatient && $activePatients) $selectedPatient = $activePatients[0];
        if ($selectedPatient) {
            $stmt = $pdo->prepare('SELECT * FROM observations WHERE patient_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1');
            $stmt->execute([(int)$selectedPatient['id']]);
            $latestObservation = $stmt->fetch() ?: null;
        }
        $stats['patients'] = count($activePatients);
        $stats['meds'] = scoped_count('medications','patient_id',"AND medications.status='pending' AND EXISTS (SELECT 1 FROM patients p WHERE p.id=medications.patient_id AND p.status='active')");
        $stats['reports'] = scoped_count('reports','patient_id',"AND report_date=CURDATE() AND EXISTS (SELECT 1 FROM patients p WHERE p.id=reports.patient_id AND p.status='active')");
        $stats['visitsToday'] = scoped_count('visits','patient_id',"AND DATE(visit_at)=CURDATE() AND EXISTS (SELECT 1 FROM patients p WHERE p.id=visits.patient_id AND p.status='active')");
        $stats['observationsToday'] = scoped_count('observations','patient_id',"AND DATE(recorded_at)=CURDATE() AND EXISTS (SELECT 1 FROM patients p WHERE p.id=observations.patient_id AND p.status='active')");
        if ($role === 'admin') $stats['nurses'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='nurse' AND status='active'")->fetchColumn();
        $recentVisits = scoped_rows('SELECT v.*,p.name AS patient,u.name AS nurse FROM visits v INNER JOIN patients p ON p.id=v.patient_id INNER JOIN users u ON u.id=v.nurse_id','v.patient_id',"AND p.status='active' ORDER BY v.visit_at DESC,v.id DESC LIMIT 5");
        $reminders = scoped_rows("SELECT m.*,p.name AS patient FROM medications m INNER JOIN patients p ON p.id=m.patient_id",'m.patient_id',"AND p.status='active' AND m.status='pending' ORDER BY m.scheduled_time ASC,m.id DESC LIMIT 5");
    }

    if ($page === 'patients') {
        // Patient rows are already scoped above. Admin sees archived rows to restore them.
    } elseif ($page === 'users' && $role === 'admin') {
        [$usersSearchSql, $usersSearchParams] = search_clause(['name','email','role'], $searchQuery);
        $usersStatus = in_array(($_GET['status'] ?? 'all'), ['active','inactive','all'], true) ? (string)($_GET['status'] ?? 'all') : 'all';
        $usersWhere = [];
        if ($usersSearchSql !== '') $usersWhere[] = $usersSearchSql;
        if ($usersStatus !== 'all') { $usersWhere[] = 'status = ?'; $usersSearchParams[] = $usersStatus; }
        $usersSql = 'SELECT id,name,email,role,status,created_at FROM users' . ($usersWhere ? ' WHERE ' . implode(' AND ', $usersWhere) : '') . ' ORDER BY created_at DESC,id DESC';
        $usersStmt = $pdo->prepare($usersSql); $usersStmt->execute($usersSearchParams); $users = $usersStmt->fetchAll();
        foreach ($pdo->query('SELECT user_id,patient_id FROM patient_families') as $assignment) {
            $patientAssignments[(int)$assignment['user_id']][] = (int)$assignment['patient_id'];
        }
    } elseif ($page === 'medications') {
        [$searchSql, $searchParams] = search_clause(['p.name','m.name','m.dose','m.instructions'], $searchQuery);
        $filters = [];
        $params = $searchParams;
        if ($searchSql !== '') $filters[] = $searchSql;
        if ($selectedPatientFilter > 0) { $filters[] = 'm.patient_id = ?'; $params[] = $selectedPatientFilter; }
        if ($medicationStatusFilter !== 'all') { $filters[] = 'm.status = ?'; $params[] = $medicationStatusFilter; }
        $tail = ($filters ? 'AND ' . implode(' AND ', $filters) . ' ' : '') . 'ORDER BY m.scheduled_time ASC,m.id DESC';
        $medications = scoped_rows('SELECT m.*,p.name AS patient,g.name AS given_by_name FROM medications m INNER JOIN patients p ON p.id=m.patient_id LEFT JOIN users g ON g.id=m.given_by','m.patient_id',$tail,$params);
    } elseif ($page === 'visits') {
        [$searchSql, $params] = search_clause(['p.name','u.name','v.notes'], $searchQuery);
        $tail = ($searchSql !== '' ? 'AND ' . $searchSql . ' ' : '') . ($selectedPatientFilter > 0 ? 'AND v.patient_id = ? ' : '') . 'ORDER BY v.visit_at DESC,v.id DESC';
        if ($selectedPatientFilter > 0) $params[] = $selectedPatientFilter;
        $visits = scoped_rows('SELECT v.*,p.name AS patient,u.name AS nurse FROM visits v INNER JOIN patients p ON p.id=v.patient_id INNER JOIN users u ON u.id=v.nurse_id','v.patient_id',$tail,$params);
    } elseif ($page === 'observations') {
        [$searchSql, $params] = search_clause(['p.name','o.blood_pressure','o.complaint','o.notes'], $searchQuery);
        $tail = ($searchSql !== '' ? 'AND ' . $searchSql . ' ' : '') . ($selectedPatientFilter > 0 ? 'AND o.patient_id = ? ' : '') . 'ORDER BY o.recorded_at DESC,o.id DESC';
        if ($selectedPatientFilter > 0) $params[] = $selectedPatientFilter;
        $observations = scoped_rows('SELECT o.*,p.name AS patient,u.name AS recorder FROM observations o INNER JOIN patients p ON p.id=o.patient_id INNER JOIN users u ON u.id=o.recorded_by','o.patient_id',$tail,$params);
    } elseif ($page === 'messages') {
        [$searchSql, $params] = search_clause(['p.name','u.name','m.message'], $searchQuery);
        $tail = ($searchSql !== '' ? 'AND ' . $searchSql . ' ' : '') . ($selectedPatientFilter > 0 ? 'AND m.patient_id = ? ' : '') . 'ORDER BY m.created_at DESC,m.id DESC LIMIT 200';
        if ($selectedPatientFilter > 0) $params[] = $selectedPatientFilter;
        $messages = array_reverse(scoped_rows('SELECT m.*,p.name AS patient,u.name AS sender,u.role AS sender_role,pm.message AS reply_message,pu.name AS reply_sender,pu.role AS reply_sender_role FROM messages m INNER JOIN patients p ON p.id=m.patient_id INNER JOIN users u ON u.id=m.sender_id LEFT JOIN messages pm ON pm.id=m.reply_to_id LEFT JOIN users pu ON pu.id=pm.sender_id','m.patient_id',$tail,$params));
    } elseif ($page === 'reports') {
        [$searchSql, $params] = search_clause(['p.name','u.name','r.summary','r.actions_taken','r.response'], $searchQuery);
        $tail = ($searchSql !== '' ? 'AND ' . $searchSql . ' ' : '') . ($selectedPatientFilter > 0 ? 'AND r.patient_id = ? ' : '') . 'ORDER BY r.report_date DESC,r.id DESC';
        if ($selectedPatientFilter > 0) $params[] = $selectedPatientFilter;
        $reports = scoped_rows('SELECT r.*,p.name AS patient,u.name AS author FROM reports r INNER JOIN patients p ON p.id=r.patient_id INNER JOIN users u ON u.id=r.author_id','r.patient_id',$tail,$params);
    } elseif ($page === 'audit' && $role === 'admin') {
        [$searchSql, $params] = search_clause(['u.name','a.action','a.entity','a.details','CAST(a.entity_id AS CHAR)'], $searchQuery);
        $sql = 'SELECT a.*,u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id' . ($searchSql !== '' ? ' WHERE ' . $searchSql : '') . ' ORDER BY a.created_at DESC,a.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $auditRows = $stmt->fetchAll();
    }
}

$roleDashboardTitle = $role === 'admin' ? 'Ringkasan Admin' : ($role === 'nurse' ? 'Ringkasan Perawat' : 'Ringkasan Keluarga');
$pageTitles = [
    'dashboard'=>$roleDashboardTitle, 'patients'=>'Data pasien', 'patient_detail'=>'Profil pasien', 'users'=>'Manajemen pengguna',
    'medications'=>'Jadwal obat', 'visits'=>'Kunjungan', 'observations'=>'Pantauan kondisi',
    'messages'=>'Pesan', 'reports'=>'Laporan harian', 'audit'=>'Riwayat aktivitas',
];
$navItems = [
    ['dashboard','▦','Ringkasan'], ['patients','♡','Data pasien'], ['medications','◷','Jadwal obat'],
    ['visits','＋','Kunjungan'], ['observations','⌁','Pantauan kondisi'], ['messages','✉','Pesan'], ['reports','▤','Laporan'],
];
if ($role === 'admin') {
    $navItems[] = ['users','♙','Manajemen pengguna'];
    $navItems[] = ['audit','◷','Riwayat aktivitas'];
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#132d3b">
    <title>RawatBersama · <?=e($pageTitles[$page] ?? 'Masuk')?></title>
    <link rel="stylesheet" href="assets/style.css?v=5">
    <link rel="stylesheet" href="assets/admin.css?v=5">
</head>
<body class="<?=!$me ? 'login-body' : ''?>">
<?php if (!$me): ?>
    <main class="login-card">
        <a class="brand" href="index.php"><span class="brand-mark">R+</span><span><b>RawatBersama</b><small>CARE MANAGEMENT</small></span></a>
        <div class="login-heading"><span class="eyebrow">SELAMAT DATANG</span><h1>Masuk ke akun Anda</h1><p>Kelola informasi perawatan dalam satu tempat.</p></div>
        <?php if ($flashMessage): ?><div class="alert <?=e($flashMessage['type'])?>"><?=e($flashMessage['message'])?></div><?php endif; ?>
        <form method="post" action="index.php?page=login" class="stack-form">
            <?=csrf_input()?>
            <input type="hidden" name="action" value="login">
            <label>Email<input type="email" name="email" autocomplete="username" placeholder="nama@email.com" required></label>
            <label>Kata sandi<input type="password" name="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required></label>
            <button class="btn btn-primary btn-wide" type="submit">Masuk <span>→</span></button>
        </form>
        <p class="login-foot">Akses khusus keluarga, perawat, dan administrator.</p>
    </main>
<?php else: ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php?page=dashboard"><span class="brand-mark">R+</span><span><b>RawatBersama</b><small>CARE MANAGEMENT</small></span></a>
        <div class="side-label">WORKSPACE</div>
        <nav class="side-nav">
            <?php foreach ($navItems as [$key,$icon,$label]): ?>
                <a class="<?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?=e($key)?>"><span class="nav-icon"><?=e($icon)?></span><?=e($label)?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="help-card"><b>Butuh bantuan?</b><p>Hubungi administrator sistem untuk bantuan akses.</p></div>
            <div class="user-mini"><div class="avatar"><?=e(strtoupper(substr($me['name'],0,1)))?></div><div><b><?=e($me['name'])?></b><small><?=e(['admin'=>'Administrator','nurse'=>'Perawat','family'=>'Keluarga'][$role] ?? 'Pengguna')?></small></div>
                <form method="post" class="logout-form" data-confirm="Yakin ingin keluar dari akun RawatBersama? Anda perlu login kembali untuk mengakses data."><?=csrf_input()?><input type="hidden" name="action" value="logout"><button type="submit" aria-label="Keluar dari akun" title="Keluar">↗</button></form>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
    <main class="main-area">
        <header class="topbar">
            <button type="button" class="mobile-menu" id="mobileMenu" aria-label="Buka menu navigasi" aria-controls="sidebar" aria-expanded="false">☰</button>
            <div><span class="eyebrow">RAWATBERSAMA / <?=e(strtoupper($page))?></span><h2><?=e($pageTitles[$page] ?? 'Ringkasan')?></h2></div>
            <div class="top-actions">
                <form method="get" action="index.php" class="global-search" role="search">
                    <input type="hidden" name="page" value="patients">
                    <label class="sr-only" for="globalPatientSearch">Cari pasien</label>
                    <input id="globalPatientSearch" type="search" name="patient_search" aria-label="Cari pasien berdasarkan nama, diagnosis, kontak, atau ID" maxlength="100" value="<?=e($patientSearch)?>" placeholder="Cari pasien, diagnosis, atau ID...">
                    <button type="submit" aria-label="Cari pasien" title="Cari pasien">⌕</button>
                </form>
                <span class="date-chip"><?=e(date('d M Y'))?></span><div class="avatar avatar-small"><?=e(strtoupper(substr($me['name'],0,1)))?></div>
            </div>
        </header>
        <section class="content">
            <?php if ($flashMessage): ?><div class="alert <?=e($flashMessage['type'])?>" role="status"><?=e($flashMessage['message'])?></div><?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="welcome-row">
                    <div>
                        <span class="eyebrow"><?=e($role === 'admin' ? 'ADMINISTRATOR WORKSPACE' : ($role === 'nurse' ? 'NURSE WORKSPACE' : 'FAMILY PORTAL'))?></span>
                        <h1><?=e($role === 'admin' ? 'Selamat datang, ' . explode(' ', $me['name'])[0] : ($role === 'nurse' ? 'Halo, ' . explode(' ', $me['name'])[0] : 'Halo, ' . explode(' ', $me['name'])[0]))?> 👋</h1>
                        <p><?=e($role === 'admin' ? 'Pantau data pasien, tenaga perawat, jadwal obat, dan laporan layanan.' : ($role === 'nurse' ? 'Pantau pasien, kunjungan, catatan kondisi, dan jadwal obat yang perlu ditindaklanjuti.' : 'Pantau informasi pasien, jadwal obat, dan perkembangan perawatan keluarga Anda.'))?></p>
                    </div>
                    <?php if (count($activePatients) > 1): ?>
                        <form method="get" class="patient-filter"><input type="hidden" name="page" value="dashboard"><label for="patient_id">Pilih pasien</label><select id="patient_id" name="patient_id" onchange="this.form.submit()"><?=patient_select_options($activePatients,(int)($selectedPatient['id'] ?? 0))?></select></form>
                    <?php elseif ($selectedPatient): ?>
                        <div class="selected-patient-pill"><span>Pasien yang ditampilkan</span><b><?=e($selectedPatient['name'])?></b></div>
                    <?php endif; ?>
                </div>

                <div class="stat-grid">
                    <?php if ($role === 'admin'): ?>
                        <article class="stat-card"><span>Pasien aktif</span><strong><?=number_format($stats['patients'])?></strong><small>Data pasien yang sedang terdaftar</small></article>
                        <article class="stat-card"><span>Perawat aktif</span><strong><?=number_format($stats['nurses'])?></strong><small>Akun perawat berstatus aktif</small></article>
                        <article class="stat-card"><span>Obat menunggu</span><strong><?=number_format($stats['meds'])?></strong><small>Jadwal obat pasien aktif</small></article>
                        <article class="stat-card"><span>Laporan hari ini</span><strong><?=number_format($stats['reports'])?></strong><small>Laporan perawatan tersimpan hari ini</small></article>
                    <?php elseif ($role === 'nurse'): ?>
                        <article class="stat-card"><span>Pasien aktif</span><strong><?=number_format($stats['patients'])?></strong><small>Pasien yang dapat Anda akses</small></article>
                        <article class="stat-card"><span>Kunjungan hari ini</span><strong><?=number_format($stats['visitsToday'])?></strong><small>Kunjungan yang sudah dicatat</small></article>
                        <article class="stat-card"><span>Pemantauan hari ini</span><strong><?=number_format($stats['observationsToday'])?></strong><small>Catatan kondisi yang dibuat</small></article>
                        <article class="stat-card"><span>Obat menunggu</span><strong><?=number_format($stats['meds'])?></strong><small>Jadwal yang belum ditandai diberikan</small></article>
                    <?php else: ?>
                        <article class="stat-card"><span>Pasien terhubung</span><strong><?=number_format($stats['patients'])?></strong><small>Pasien yang ditautkan ke akun Anda</small></article>
                        <article class="stat-card"><span>Obat menunggu</span><strong><?=number_format($stats['meds'])?></strong><small>Jadwal obat yang perlu diperhatikan</small></article>
                        <article class="stat-card"><span>Kunjungan hari ini</span><strong><?=number_format($stats['visitsToday'])?></strong><small>Kunjungan tim perawat</small></article>
                        <article class="stat-card"><span>Pembaruan kondisi hari ini</span><strong><?=number_format($stats['observationsToday'])?></strong><small>Catatan kondisi pasien terbaru</small></article>
                    <?php endif; ?>
                </div>

                <div class="dashboard-shortcuts">
                    <span class="eyebrow">AKSES CEPAT</span>
                    <?php if ($role === 'admin'): ?>
                        <a class="btn btn-outline" href="index.php?page=patients">Kelola pasien →</a>
                        <a class="btn btn-outline" href="index.php?page=users">Kelola pengguna →</a>
                        <a class="btn btn-outline" href="index.php?page=audit">Riwayat aktivitas →</a>
                    <?php elseif ($role === 'nurse'): ?>
                        <a class="btn btn-outline" href="index.php?page=visits">Catat kunjungan →</a>
                        <a class="btn btn-outline" href="index.php?page=observations">Pantau kondisi →</a>
                        <a class="btn btn-outline" href="index.php?page=medications">Jadwal obat →</a>
                    <?php else: ?>
                        <a class="btn btn-outline" href="index.php?page=messages">Hubungi perawat →</a>
                        <a class="btn btn-outline" href="index.php?page=medications">Lihat jadwal obat →</a>
                        <a class="btn btn-outline" href="index.php?page=reports">Lihat laporan →</a>
                    <?php endif; ?>
                </div>

                <?php if ($selectedPatient): ?>
                    <div class="section-title"><div><span class="eyebrow"><?=e($role === 'family' ? 'INFORMASI PASIEN ANDA' : 'PROFIL PASIEN')?></span><h3><?=e($selectedPatient['name'])?></h3><p><?=e($selectedPatient['age'])?> tahun · <?=e($selectedPatient['gender'])?> · <?=e($selectedPatient['diagnosis'] ?: 'Diagnosis belum diisi')?></p></div><a class="btn btn-outline" href="index.php?page=patients"><?=e($role === 'admin' ? 'Kelola data pasien →' : 'Lihat daftar pasien →')?></a></div>
                    <div class="two-col">
                        <article class="panel-card"><div class="panel-head"><h3><?=e($role === 'family' ? 'Kondisi terakhir pasien' : 'Pantauan terakhir')?></h3><span class="tag">Kondisi</span></div>
                            <?php if ($latestObservation): ?><div class="vital-grid"><div><small>Tekanan darah</small><b><?=e($latestObservation['blood_pressure'] ?: '—')?></b><small>mmHg</small></div><div><small>Suhu tubuh</small><b><?=e($latestObservation['temperature'] ?: '—')?>°</b><small>Celsius</small></div><div><small>Ukuran luka</small><b><?=e($latestObservation['wound_size'] ?: '—')?></b><small>cm</small></div></div><p class="muted"><?=e($latestObservation['complaint'] ?: 'Tidak ada keluhan tercatat')?></p>
                            <?php else: ?><p class="empty">Belum ada data pemantauan untuk pasien ini.</p><?php endif; ?>
                        </article>
                        <article class="panel-card"><div class="panel-head"><h3>Informasi penting</h3><span class="tag tag-warn">Perhatian</span></div><p><b>Alergi</b><br><?=e($selectedPatient['allergies'] ?? 'Belum dicatat')?></p><p><b>Alamat</b><br><?=e($selectedPatient['address'] ?? 'Belum dicatat')?></p><?php if ($role !== 'family'): ?><p><b>Kontak keluarga</b><br><?=e($selectedPatient['family_contact'] ?? 'Belum dicatat')?></p><?php endif; ?></article>
                    </div>
                <?php else: ?>
                    <div class="panel-card empty"><?=e($role === 'admin' ? 'Belum ada pasien aktif. Tambahkan data pasien untuk mulai mengelola perawatan.' : ($role === 'nurse' ? 'Belum ada pasien aktif yang tersedia untuk dipantau.' : 'Belum ada pasien yang terhubung dengan akun ini. Hubungi administrator untuk mengatur akses pasien.'))?></div>
                <?php endif; ?>

                <div class="two-col">
                    <article class="panel-card"><div class="panel-head"><h3><?=e($role === 'family' ? 'Aktivitas perawatan terbaru' : 'Kunjungan terbaru')?></h3><a href="index.php?page=visits">Lihat semua</a></div>
                        <?php foreach ($recentVisits as $visit): ?><div class="activity"><span class="activity-dot"></span><div><b><?=e($visit['nurse'])?></b> · kunjungan <?=e($visit['patient'])?><p><?=e($visit['notes'])?></p><small><?=e($visit['visit_at'])?></small></div></div><?php endforeach; ?>
                        <?php if (!$recentVisits): ?><p class="empty">Belum ada aktivitas kunjungan.</p><?php endif; ?>
                    </article>
                    <article class="panel-card"><div class="panel-head"><h3><?=e($role === 'family' ? 'Jadwal obat pasien' : 'Pengingat obat')?></h3><a href="index.php?page=medications">Jadwal obat</a></div>
                        <?php foreach ($reminders as $med): ?><div class="reminder"><span class="time-pill"><?=e(substr($med['scheduled_time'],0,5))?></span><div><b><?=e($med['name'])?></b><small><?=e($med['patient'])?> · <?=e($med['dose'])?></small></div><span class="tag tag-warn">Menunggu</span></div><?php endforeach; ?>
                        <?php if (!$reminders): ?><p class="empty">Tidak ada obat tertunda.</p><?php endif; ?>
                    </article>
                </div>

            <?php elseif ($page === 'patient_detail' && $patientDetail): ?>
                <?php page_heading('Profil pasien', 'Ringkasan identitas dan catatan perawatan pasien dalam satu tampilan.'); ?>
                <div class="detail-toolbar"><a class="btn btn-outline" href="index.php?page=patients">← Kembali ke data pasien</a><span class="tag tag-green">Pasien aktif</span></div>
                <section class="patient-hero">
                    <div class="patient-identity">
                        <div class="patient-avatar"><?=e(strtoupper(substr($patientDetail['name'],0,1)))?></div>
                        <div><span class="eyebrow">PROFIL PASIEN · ID #<?=e($patientDetail['id'])?></span><h2><?=e($patientDetail['name'])?></h2><p><?=e($patientDetail['age'])?> tahun <span>·</span> <?=e($patientDetail['gender'])?></p></div>
                    </div>
                    <div class="patient-quick-actions">
                        <?php if ($canWriteCare): ?>
                            <a class="btn btn-outline" href="index.php?page=visits&patient_id=<?=e($patientDetail['id'])?>">＋ Kunjungan</a>
                            <a class="btn btn-outline" href="index.php?page=observations&patient_id=<?=e($patientDetail['id'])?>">＋ Pantauan</a>
                            <a class="btn btn-primary" href="index.php?page=medications&patient_id=<?=e($patientDetail['id'])?>">Lihat jadwal obat</a>
                        <?php else: ?>
                            <a class="btn btn-primary" href="index.php?page=messages&patient_id=<?=e($patientDetail['id'])?>">Hubungi perawat</a>
                        <?php endif; ?>
                    </div>
                </section>
                <div class="profile-grid">
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Informasi medis</h3><span class="tag">Identitas</span></div>
                        <div class="profile-info-row"><span>Diagnosis / kondisi</span><b><?=e($patientDetail['diagnosis'] ?: 'Belum dicatat')?></b></div>
                        <div class="profile-info-row"><span>Alergi</span><b class="<?=$patientDetail['allergies'] ? 'text-alert' : ''?>"><?=e($patientDetail['allergies'] ?: 'Belum dicatat')?></b></div>
                        <div class="profile-info-row"><span>Alamat</span><b><?=e($patientDetail['address'] ?: 'Belum dicatat')?></b></div>
                        <?php if ($role !== 'family'): ?><div class="profile-info-row"><span>Kontak keluarga</span><b><?=e($patientDetail['family_contact'] ?: 'Belum dicatat')?></b></div><?php endif; ?>
                    </article>
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Pemantauan kondisi terakhir</h3><span class="tag">Dokumentasi</span></div>
                        <?php if ($patientDetailObservation): ?>
                            <div class="vital-grid">
                                <div><small>Tekanan darah</small><b><?=e($patientDetailObservation['blood_pressure'] ?: '—')?></b><small>mmHg</small></div>
                                <div><small>Suhu tubuh</small><b><?=e($patientDetailObservation['temperature'] !== null ? $patientDetailObservation['temperature'] . '°' : '—')?></b><small>Celsius</small></div>
                                <div><small>Ukuran luka</small><b><?=e($patientDetailObservation['wound_size'] !== null ? $patientDetailObservation['wound_size'] : '—')?></b><small>cm</small></div>
                            </div>
                            <div class="profile-info-row"><span>Keluhan</span><b><?=e($patientDetailObservation['complaint'] ?: 'Tidak ada keluhan tercatat')?></b></div>
                            <div class="profile-info-row"><span>Catatan</span><b><?=e($patientDetailObservation['notes'] ?: '—')?></b></div>
                            <p class="profile-timestamp">Dicatat <?=e($patientDetailObservation['recorded_at'])?> oleh <?=e($patientDetailObservation['recorder'])?></p>
                        <?php else: ?><p class="empty">Belum ada pemantauan kondisi yang dicatat.</p><?php endif; ?>
                    </article>
                </div>
                <div class="profile-grid profile-grid-secondary">
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Riwayat kunjungan</h3><a href="index.php?page=visits&patient_id=<?=e($patientDetail['id'])?>">Lihat semua</a></div>
                        <?php foreach ($patientDetailVisits as $visit): ?><div class="profile-list-item"><b><?=e($visit['nurse'])?></b><span><?=e($visit['visit_at'])?></span><p><?=e($visit['notes'])?></p></div><?php endforeach; ?>
                        <?php if (!$patientDetailVisits): ?><p class="empty">Belum ada kunjungan tercatat.</p><?php endif; ?>
                    </article>
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Jadwal obat</h3><a href="index.php?page=medications&patient_id=<?=e($patientDetail['id'])?>">Lihat semua</a></div>
                        <?php foreach ($patientDetailMedications as $med): ?><div class="profile-list-item medication-profile-item"><div><b><?=e($med['name'])?></b><span><?=e($med['dose'])?> · <?=e(substr($med['scheduled_time'],0,5))?></span></div><span class="tag <?=$med['status']==='given'?'tag-green':'tag-warn'?>"><?=$med['status']==='given'?'Sudah diberikan':'Menunggu'?></span><p><?=e($med['instructions'] ?: 'Tidak ada instruksi tambahan')?></p></div><?php endforeach; ?>
                        <?php if (!$patientDetailMedications): ?><p class="empty">Belum ada jadwal obat.</p><?php endif; ?>
                    </article>
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Pantauan sebelumnya</h3><a href="index.php?page=observations&patient_id=<?=e($patientDetail['id'])?>">Lihat semua</a></div>
                        <?php foreach ($patientDetailObservations as $obs): ?><div class="profile-list-item"><b><?=e($obs['recorded_at'])?></b><span>TD <?=e($obs['blood_pressure'] ?: '—')?> · Suhu <?=e($obs['temperature'] !== null ? $obs['temperature'] . '°C' : '—')?></span><p><?=e($obs['complaint'] ?: ($obs['notes'] ?: 'Tidak ada catatan tambahan'))?></p></div><?php endforeach; ?>
                        <?php if (!$patientDetailObservations): ?><p class="empty">Belum ada riwayat pemantauan.</p><?php endif; ?>
                    </article>
                    <article class="panel-card profile-card">
                        <div class="panel-head"><h3>Laporan perawatan</h3><a href="index.php?page=reports&patient_id=<?=e($patientDetail['id'])?>">Lihat semua</a></div>
                        <?php foreach ($patientDetailReports as $report): ?><div class="profile-list-item"><b><?=e($report['report_date'])?></b><span><?=e($report['author'])?></span><p><?=e($report['summary'])?></p></div><?php endforeach; ?>
                        <?php if (!$patientDetailReports): ?><p class="empty">Belum ada laporan perawatan.</p><?php endif; ?>
                    </article>
                </div>

            <?php elseif ($page === 'patients'): ?>
                <?php page_heading('Data pasien', $role === 'admin' ? 'Daftarkan pasien beserta akun keluarga dalam satu proses, lalu kelola data pasien.' : ($role === 'nurse' ? 'Daftarkan pasien baru sekaligus buatkan akun keluarga.' : 'Daftar pasien yang terhubung dengan akun keluarga Anda.')); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search">
                    <input type="hidden" name="page" value="patients">
                    <div class="filter-search"><input type="search" name="patient_search" aria-label="Cari nama, diagnosis, kontak, atau ID pasien" maxlength="100" value="<?=e($patientSearch)?>" placeholder="Cari nama, diagnosis, kontak, atau ID pasien..."></div>
                    <?php if ($role === 'admin'): ?><select name="status" aria-label="Filter status pasien"><option value="all" <?=$patientStatusFilter==='all'?'selected':''?>>Semua status</option><option value="active" <?=$patientStatusFilter==='active'?'selected':''?>>Aktif</option><option value="inactive" <?=$patientStatusFilter==='inactive'?'selected':''?>>Diarsipkan</option></select><?php endif; ?>
                    <button class="btn btn-primary" type="submit">Cari pasien</button>
                    <?php if ($patientSearch !== '' || ($role === 'admin' && $patientStatusFilter !== 'all')): ?><a class="btn btn-outline" href="index.php?page=patients">Reset</a><?php endif; ?>
                    <span class="result-count"><?=count($patientRows)?> hasil</span>
                </form>
                <?php if (in_array($role, ['admin','nurse'], true)): ?>
                    <details class="panel-card form-disclosure registration-disclosure">
                        <summary class="btn btn-primary">＋ Daftarkan pasien + akun keluarga</summary>
                        <form method="post" class="form-panel registration-form" autocomplete="off">
                            <?=form_hidden('save_patient','patients')?>
                            <section class="registration-section">
                                <div class="registration-section-head">
                                    <span class="registration-step">01</span>
                                    <div><h3>Informasi pasien</h3><p>Isi identitas dan informasi dasar pasien yang akan didaftarkan.</p></div>
                                </div>
                                <div class="form-grid">
                                    <label>Nama lengkap pasien<input name="name" maxlength="150" placeholder="Nama sesuai identitas" required></label>
                                    <label>Usia (tahun)<input type="number" name="age" min="0" max="120" placeholder="Contoh: 45" required></label>
                                    <label>Jenis kelamin<select name="gender" required><option value="">Pilih jenis kelamin</option><option value="Perempuan">Perempuan</option><option value="Laki-laki">Laki-laki</option><option value="Lainnya">Lainnya</option></select></label>
                                    <label>Diagnosis<input name="diagnosis" maxlength="2000" placeholder="Diagnosis atau kondisi pasien"></label>
                                    <label>Alergi<input name="allergies" maxlength="2000" placeholder="Tuliskan alergi, jika ada"></label>
                                    <label>Kontak keluarga<input name="family_contact" maxlength="100" placeholder="Nomor telepon keluarga (opsional)"></label>
                                    <label class="span-2">Alamat pasien<textarea name="address" rows="2" placeholder="Alamat lengkap (opsional)"></textarea></label>
                                </div>
                            </section>
                            <section class="registration-section">
                                <div class="registration-section-head">
                                    <span class="registration-step">02</span>
                                    <div><h3>Buat akun keluarga</h3><p>Akun ini langsung dihubungkan dengan pasien di atas. Keluarga akan menggunakan email dan kata sandi untuk masuk.</p></div>
                                </div>
                                <div class="form-grid">
                                    <label>Nama anggota keluarga<input name="family_name" maxlength="120" placeholder="Nama lengkap anggota keluarga" autocomplete="name" required></label>
                                    <label>Email untuk login<input type="email" name="family_email" maxlength="190" placeholder="keluarga@email.com" autocomplete="email" required></label>
                                    <label>Kata sandi awal (minimal 10 karakter)<input type="password" name="family_password" minlength="10" maxlength="200" autocomplete="new-password" placeholder="Buat kata sandi awal" required></label>
                                    <label>Ulangi kata sandi<input type="password" name="family_password_confirm" minlength="10" maxlength="200" autocomplete="new-password" placeholder="Ulangi kata sandi" required></label>
                                </div>
                                <p class="registration-note"><span>✓</span> Akun keluarga akan dibuat otomatis dengan peran Keluarga dan hanya dapat mengakses pasien yang didaftarkan pada formulir ini.</p>
                            </section>
                            <div class="registration-submit">
                                <button class="btn btn-primary" type="submit">Simpan pasien & buat akun keluarga</button>
                                <small class="field-help">Pastikan email belum terdaftar dan data sudah benar sebelum menyimpan.</small>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Nama pasien</th><th>Usia</th><th>Jenis kelamin</th><th>Diagnosis</th><th>Status</th><th>Profil</th><?php if ($role==='admin'): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($patientRows as $patient): ?><tr>
                        <td><b><?=e($patient['name'])?></b><small>ID #<?=e($patient['id'])?></small></td><td><?=e($patient['age'])?> th</td><td><?=e($patient['gender'])?></td><td><?=e($patient['diagnosis'] ?: '—')?></td><td><span class="tag <?=$patient['status']==='active'?'tag-green':'tag-warn'?>"><?=$patient['status']==='active'?'Aktif':'Diarsipkan'?></span></td><td><?php if ($patient['status']==='active'): ?><a class="btn btn-sm btn-outline" href="index.php?page=patient_detail&id=<?=e($patient['id'])?>">Lihat profil</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <?php if ($role==='admin'): ?><td class="actions-cell"><details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('save_patient','patients')?><input type="hidden" name="id" value="<?=e($patient['id'])?>"><div class="form-grid"><label>Nama<input name="name" maxlength="150" value="<?=e($patient['name'])?>" required></label><label>Usia<input type="number" name="age" min="0" max="120" value="<?=e($patient['age'])?>" required></label><label>Jenis kelamin<select name="gender"><option value="Perempuan" <?=$patient['gender']==='Perempuan'?'selected':''?>>Perempuan</option><option value="Laki-laki" <?=$patient['gender']==='Laki-laki'?'selected':''?>>Laki-laki</option><option value="Lainnya" <?=$patient['gender']==='Lainnya'?'selected':''?>>Lainnya</option></select></label><label>Diagnosis<input name="diagnosis" value="<?=e($patient['diagnosis'])?>"></label><label>Alergi<input name="allergies" value="<?=e($patient['allergies'])?>"></label><label>Kontak keluarga<input name="family_contact" value="<?=e($patient['family_contact'])?>"></label><label class="span-2">Alamat<textarea name="address" rows="2"><?=e($patient['address'])?></textarea></label></div><button class="btn btn-primary" type="submit">Simpan perubahan</button></form></details>
                            <form method="post" class="inline-action" onsubmit="return confirm('<?= $patient['status']==='active' ? 'Arsipkan pasien ini? Data perawatan tetap disimpan.' : 'Pulihkan pasien ini?' ?>')"><?=form_hidden('set_patient_status','patients')?><input type="hidden" name="id" value="<?=e($patient['id'])?>"><input type="hidden" name="status" value="<?=$patient['status']==='active'?'inactive':'active'?>"><button class="btn btn-sm" type="submit"><?=$patient['status']==='active'?'Arsipkan':'Pulihkan'?></button></form></td><?php endif; ?>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$patientRows): ?><p class="empty">Belum ada pasien yang dapat ditampilkan.</p><?php endif; ?></div>

            <?php elseif ($page === 'users' && $role === 'admin'): ?>
                <?php page_heading('Manajemen pengguna','Kelola akun administrator dan perawat. Akun keluarga dibuat otomatis saat pendaftaran pasien.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="users"><div class="filter-search"><input type="search" name="q" aria-label="Cari nama, email, atau peran pengguna" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari nama, email, atau peran pengguna..."></div><select name="status" aria-label="Filter status akun"><option value="all" <?=($_GET['status'] ?? 'all')==='all'?'selected':''?>>Semua status</option><option value="active" <?=($_GET['status'] ?? '')==='active'?'selected':''?>>Aktif</option><option value="inactive" <?=($_GET['status'] ?? '')==='inactive'?'selected':''?>>Nonaktif</option></select><button class="btn btn-primary" type="submit">Cari pengguna</button><?php if ($searchQuery!=='' || (($_GET['status'] ?? 'all')!=='all')): ?><a class="btn btn-outline" href="index.php?page=users">Reset</a><?php endif; ?><span class="result-count"><?=count($users)?> hasil</span></form>
                <details class="panel-card form-disclosure"><summary class="btn btn-primary">＋ Tambah pengguna</summary><form method="post" class="form-panel"><?=form_hidden('save_user','users')?>
                    <div class="form-grid"><label>Nama lengkap<input name="name" maxlength="120" required></label><label>Email<input type="email" name="email" maxlength="190" required></label><label>Kata sandi awal (min. 10 karakter)<input type="password" name="password" minlength="10" required></label><label>Peran<select name="role" required><option value="nurse">Perawat</option><option value="admin">Admin</option></select></label></div><p class="field-help">Untuk membuat akun keluarga, gunakan formulir pendaftaran pasien agar akun langsung terhubung dengan pasien.</p><button class="btn btn-primary" type="submit">Buat akun</button>
                </form></details>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Nama pengguna</th><th>Email</th><th>Peran</th><th>Pasien terhubung</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                    <?php foreach ($users as $account): $assigned = $patientAssignments[(int)$account['id']] ?? []; $assignedNames = array_values(array_map(static fn($p) => $p['name'], array_filter($activePatients, static fn($p) => in_array((int)$p['id'], $assigned, true)))); ?>
                    <tr><td><b><?=e($account['name'])?></b><small>ID #<?=e($account['id'])?></small></td><td><?=e($account['email'])?></td><td><?=e(ucfirst($account['role']))?></td><td><?=e($assignedNames ? implode(', ', $assignedNames) : '—')?></td><td><span class="tag <?=$account['status']==='active'?'tag-green':'tag-warn'?>"><?=e($account['status']==='active'?'Aktif':'Nonaktif')?></span></td><td class="actions-cell">
                        <details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('update_user','users')?><input type="hidden" name="id" value="<?=e($account['id'])?>"><div class="form-grid"><label>Nama<input name="name" value="<?=e($account['name'])?>" required></label><label>Email<input type="email" name="email" value="<?=e($account['email'])?>" required></label><label>Kata sandi baru (kosongkan jika tetap)<input type="password" name="password" minlength="10"></label><label>Peran<select name="role"><option value="admin" <?=$account['role']==='admin'?'selected':''?>>Admin</option><option value="nurse" <?=$account['role']==='nurse'?'selected':''?>>Perawat</option><?php if ($account['role']==='family'): ?><option value="family" selected>Keluarga</option><?php endif; ?></select></label><?php if ($account['role']==='family'): ?><label class="span-2">Pasien untuk akun keluarga <select name="patient_ids[]" multiple size="4"><?php foreach ($activePatients as $patient): ?><option value="<?=e($patient['id'])?>" <?=in_array((int)$patient['id'],$assigned,true)?'selected':''?>><?=e($patient['name'])?></option><?php endforeach; ?></select><small class="field-help">Pilih minimal satu pasien aktif yang boleh diakses akun ini.</small></label><?php endif; ?></div><button class="btn btn-primary" type="submit">Simpan pengguna</button></form></details>
                        <?php if ((int)$account['id'] !== (int)$me['id']): ?><form method="post" class="inline-action" onsubmit="return confirm('<?= $account['status']==='active' ? 'Nonaktifkan akun ini? Riwayat tetap disimpan.' : 'Aktifkan kembali akun ini?' ?>')"><?=form_hidden('set_user_status','users')?><input type="hidden" name="id" value="<?=e($account['id'])?>"><input type="hidden" name="status" value="<?=$account['status']==='active'?'inactive':'active'?>"><button class="btn btn-sm" type="submit"><?=$account['status']==='active'?'Nonaktifkan':'Aktifkan'?></button></form><?php endif; ?>
                    </td></tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$users): ?><p class="empty">Belum ada pengguna.</p><?php endif; ?></div>

            <?php elseif ($page === 'medications'): ?>
                <?php page_heading('Jadwal obat','Catat obat, dosis, waktu, dan status pemberiannya.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="medications"><div class="filter-search"><input type="search" name="q" aria-label="Cari nama pasien, obat, dosis, atau instruksi" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari nama pasien, obat, dosis, atau instruksi..."></div><select name="patient_id" aria-label="Filter pasien"><option value="0">Semua pasien</option><?=patient_select_options($activePatients,$selectedPatientFilter)?></select><select name="status" aria-label="Filter status obat"><option value="all" <?=$medicationStatusFilter==='all'?'selected':''?>>Semua status</option><option value="pending" <?=$medicationStatusFilter==='pending'?'selected':''?>>Menunggu</option><option value="given" <?=$medicationStatusFilter==='given'?'selected':''?>>Sudah diberikan</option></select><button class="btn btn-primary" type="submit">Filter</button><?php if ($searchQuery!=='' || $selectedPatientFilter>0 || $medicationStatusFilter!=='all'): ?><a class="btn btn-outline" href="index.php?page=medications">Reset</a><?php endif; ?><span class="result-count"><?=count($medications)?> hasil</span></form>
                <?php if ($canWriteCare && $activePatients): ?><details class="panel-card form-disclosure"><summary class="btn btn-primary">＋ Tambah jadwal obat</summary><form method="post" class="form-panel"><?=form_hidden('save_medication','medications')?><div class="form-grid"><label>Pasien<select name="patient_id" required><?=patient_select_options($activePatients,$selectedPatientFilter)?></select></label><label>Nama obat<input name="name" maxlength="150" required></label><label>Dosis<input name="dose" maxlength="100" required></label><label>Waktu<input type="time" name="scheduled_time" required></label><label class="span-2">Instruksi<input name="instructions" maxlength="1000"></label></div><button class="btn btn-primary" type="submit">Simpan jadwal</button></form></details><?php endif; ?>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pasien</th><th>Obat</th><th>Dosis</th><th>Status</th><?php if ($canWriteCare): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($medications as $med): ?><tr><td><?=e(substr($med['scheduled_time'],0,5))?></td><td><?=e($med['patient'])?></td><td><b><?=e($med['name'])?></b><small><?=e($med['instructions'])?></small></td><td><?=e($med['dose'])?></td><td><span class="tag <?=$med['status']==='given'?'tag-green':'tag-warn'?>"><?=$med['status']==='given'?'Sudah diberikan':'Menunggu'?></span><?php if ($med['given_at']): ?><small><?=e($med['given_at'])?></small><?php endif; ?></td>
                        <?php if ($canWriteCare): ?><td class="actions-cell"><details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('save_medication','medications')?><input type="hidden" name="id" value="<?=e($med['id'])?>"><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,(int)$med['patient_id'])?></select></label><label>Nama obat<input name="name" value="<?=e($med['name'])?>" required></label><label>Dosis<input name="dose" value="<?=e($med['dose'])?>" required></label><label>Waktu<input type="time" name="scheduled_time" value="<?=e(substr($med['scheduled_time'],0,5))?>" required></label><label class="span-2">Instruksi<input name="instructions" value="<?=e($med['instructions'])?>"></label></div><button class="btn btn-primary" type="submit">Simpan perubahan</button></form></details>
                            <form method="post" class="inline-action"><?=form_hidden('set_medication_status','medications')?><input type="hidden" name="id" value="<?=e($med['id'])?>"><input type="hidden" name="status" value="<?=$med['status']==='given'?'pending':'given'?>"><button class="btn btn-sm" type="submit"><?=$med['status']==='given'?'Batalkan status':'Tandai diberikan'?></button></form>
                            <?php if ($role==='admin'): ?><form method="post" class="inline-action" onsubmit="return confirm('Hapus jadwal obat ini?')"><?=form_hidden('delete_medication','medications')?><input type="hidden" name="id" value="<?=e($med['id'])?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$medications): ?><p class="empty">Belum ada jadwal obat untuk pasien yang dapat diakses.</p><?php endif; ?></div>

            <?php elseif ($page === 'visits'): ?>
                <?php page_heading('Catatan kunjungan','Dokumentasikan waktu kunjungan dan catatan perawat.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="visits"><div class="filter-search"><input type="search" name="q" aria-label="Cari pasien, perawat, atau catatan kunjungan" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari pasien, perawat, atau catatan kunjungan..."></div><select name="patient_id" aria-label="Filter pasien"><option value="0">Semua pasien</option><?=patient_select_options($activePatients,$selectedPatientFilter)?></select><button class="btn btn-primary" type="submit">Cari</button><?php if ($searchQuery!=='' || $selectedPatientFilter>0): ?><a class="btn btn-outline" href="index.php?page=visits">Reset</a><?php endif; ?><span class="result-count"><?=count($visits)?> hasil</span></form>
                <?php if ($canWriteCare && $activePatients): ?><details class="panel-card form-disclosure"><summary class="btn btn-primary">＋ Catat kunjungan</summary><form method="post" class="form-panel"><?=form_hidden('save_visit','visits')?><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,$selectedPatientFilter)?></select></label><label>Tanggal & waktu<input type="datetime-local" name="visit_at" value="<?=e(date('Y-m-d\\TH:i'))?>" required></label><label class="span-2">Catatan<textarea name="notes" rows="3" required></textarea></label></div><button class="btn btn-primary" type="submit">Simpan kunjungan</button></form></details><?php endif; ?>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pasien</th><th>Petugas</th><th>Catatan</th><?php if ($canWriteCare): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($visits as $visit): $canEdit = $role==='admin' || (int)$visit['nurse_id']===(int)$me['id']; ?><tr><td><?=e($visit['visit_at'])?></td><td><?=e($visit['patient'])?></td><td><?=e($visit['nurse'])?></td><td><?=e($visit['notes'])?></td>
                        <?php if ($canWriteCare): ?><td class="actions-cell"><?php if ($canEdit): ?><details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('save_visit','visits')?><input type="hidden" name="id" value="<?=e($visit['id'])?>"><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,(int)$visit['patient_id'])?></select></label><label>Tanggal & waktu<input type="datetime-local" name="visit_at" value="<?=e(date('Y-m-d\\TH:i',strtotime($visit['visit_at'])))?>" required></label><label class="span-2">Catatan<textarea name="notes" required><?=e($visit['notes'])?></textarea></label></div><button class="btn btn-primary" type="submit">Simpan perubahan</button></form></details><?php endif; ?><?php if ($role==='admin'): ?><form method="post" class="inline-action" onsubmit="return confirm('Hapus catatan kunjungan ini?')"><?=form_hidden('delete_visit','visits')?><input type="hidden" name="id" value="<?=e($visit['id'])?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$visits): ?><p class="empty">Belum ada catatan kunjungan.</p><?php endif; ?></div>

            <?php elseif ($page === 'observations'): ?>
                <?php page_heading('Pantauan kondisi','Catat tanda vital, keluhan, ukuran luka, dan perkembangan pasien.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="observations"><div class="filter-search"><input type="search" name="q" aria-label="Cari pasien, keluhan, tekanan darah, atau catatan" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari pasien, keluhan, tekanan darah, atau catatan..."></div><select name="patient_id" aria-label="Filter pasien"><option value="0">Semua pasien</option><?=patient_select_options($activePatients,$selectedPatientFilter)?></select><button class="btn btn-primary" type="submit">Cari</button><?php if ($searchQuery!=='' || $selectedPatientFilter>0): ?><a class="btn btn-outline" href="index.php?page=observations">Reset</a><?php endif; ?><span class="result-count"><?=count($observations)?> hasil</span></form>
                <?php if ($canWriteCare && $activePatients): ?><details class="panel-card form-disclosure"><summary class="btn btn-primary">＋ Tambah pantauan</summary><form method="post" class="form-panel"><?=form_hidden('save_observation','observations')?><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,$selectedPatientFilter)?></select></label><label>Tekanan darah<input name="blood_pressure" maxlength="20" placeholder="120/80"></label><label>Suhu (°C)<input type="number" step="0.1" min="25" max="45" name="temperature"></label><label>Ukuran luka (cm)<input type="number" step="0.01" min="0" max="999" name="wound_size"></label><label>Keluhan<input name="complaint" maxlength="2000"></label><label>Catatan<textarea name="notes" rows="2"></textarea></label></div><button class="btn btn-primary" type="submit">Simpan pantauan</button></form></details><?php endif; ?>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pasien</th><th>Tekanan darah</th><th>Suhu</th><th>Ukuran luka</th><th>Keluhan</th><?php if ($canWriteCare): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($observations as $obs): $canEdit = $role==='admin' || (int)$obs['recorded_by']===(int)$me['id']; ?><tr><td><?=e($obs['recorded_at'])?></td><td><?=e($obs['patient'])?></td><td><?=e($obs['blood_pressure'] ?: '—')?></td><td><?=e($obs['temperature'] ?: '—')?></td><td><?=e($obs['wound_size'] ?: '—')?></td><td><?=e($obs['complaint'] ?: '—')?></td>
                        <?php if ($canWriteCare): ?><td class="actions-cell"><?php if ($canEdit): ?><details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('save_observation','observations')?><input type="hidden" name="id" value="<?=e($obs['id'])?>"><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,(int)$obs['patient_id'])?></select></label><label>Tekanan darah<input name="blood_pressure" value="<?=e($obs['blood_pressure'])?>"></label><label>Suhu (°C)<input type="number" step="0.1" min="25" max="45" name="temperature" value="<?=e($obs['temperature'])?>"></label><label>Ukuran luka (cm)<input type="number" step="0.01" min="0" max="999" name="wound_size" value="<?=e($obs['wound_size'])?>"></label><label>Keluhan<input name="complaint" value="<?=e($obs['complaint'])?>"></label><label>Catatan<textarea name="notes" rows="2"><?=e($obs['notes'])?></textarea></label></div><button class="btn btn-primary" type="submit">Simpan perubahan</button></form></details><?php endif; ?><?php if ($role==='admin'): ?><form method="post" class="inline-action" onsubmit="return confirm('Hapus data pantauan ini?')"><?=form_hidden('delete_observation','observations')?><input type="hidden" name="id" value="<?=e($obs['id'])?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$observations): ?><p class="empty">Belum ada data pantauan.</p><?php endif; ?></div>

            <?php elseif ($page === 'messages'): ?>
                <?php page_heading('Percakapan perawatan','Kirim pesan baru atau balas pesan keluarga dan tim perawat.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="messages"><div class="filter-search"><input type="search" name="q" aria-label="Cari isi pesan, nama pengirim, atau pasien" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari isi pesan, nama pengirim, atau pasien..."></div><select name="patient_id" aria-label="Filter pasien"><option value="0">Semua pasien</option><?=patient_select_options($activePatients,$selectedPatientFilter)?></select><button class="btn btn-primary" type="submit">Cari pesan</button><?php if ($searchQuery!=='' || $selectedPatientFilter>0): ?><a class="btn btn-outline" href="index.php?page=messages">Reset</a><?php endif; ?><span class="result-count"><?=count($messages)?> hasil</span></form>
                <?php if ($activePatients): ?>
                    <details class="panel-card form-disclosure" open>
                        <summary class="btn btn-primary">＋ Tulis pesan baru</summary>
                        <form method="post" class="form-panel">
                            <?=form_hidden('save_message','messages')?>
                            <div class="form-grid">
                                <label>Pasien<select name="patient_id" required><?=patient_select_options($activePatients,$selectedPatientFilter)?></select></label>
                                <label class="span-2">Pesan<textarea name="message" rows="3" maxlength="5000" placeholder="Tulis pesan untuk keluarga atau tim perawat..." required></textarea></label>
                            </div>
                            <button class="btn btn-primary" type="submit">Kirim pesan</button>
                        </form>
                    </details>
                <?php else: ?>
                    <div class="panel-card empty">Tidak ada pasien yang terhubung. Hubungi administrator untuk mendapatkan akses.</div>
                <?php endif; ?>
                <div class="panel-card conversation-card">
                    <div class="panel-head"><div><h3>Riwayat percakapan</h3><small class="field-help">Pesan ditampilkan dari yang paling lama ke terbaru.</small></div><span class="tag"><?=count($messages)?> pesan</span></div>
                    <?php foreach ($messages as $message): $canEdit = $role==='admin' || (int)$message['sender_id']===(int)$me['id']; ?>
                        <article class="message-item">
                            <div class="message-meta">
                                <b><?=e($message['sender'])?></b>
                                <span class="tag"><?=e($message['patient'])?></span>
                                <span class="sender-role"><?=e(ucfirst($message['sender_role']))?></span>
                                <small><?=e($message['created_at'])?></small>
                            </div>
                            <?php if (!empty($message['reply_to_id']) && $message['reply_message'] !== null): ?>
                                <div class="reply-quote">
                                    <div class="reply-quote-label">↪ Membalas <?=e($message['reply_sender'] ?: 'pesan sebelumnya')?> · <?=e(ucfirst($message['reply_sender_role'] ?? ''))?></div>
                                    <p><?=nl2br(e($message['reply_message']))?></p>
                                </div>
                            <?php endif; ?>
                            <p class="message-text"><?=nl2br(e($message['message']))?></p>
                            <div class="message-actions">
                                <details class="row-details reply-details">
                                    <summary class="btn btn-sm">↩ Balas</summary>
                                    <form method="post" class="form-panel row-form">
                                        <?=form_hidden('save_message','messages')?>
                                        <input type="hidden" name="reply_to_id" value="<?=e($message['id'])?>">
                                        <label>Balasan untuk <?=e($message['sender'])?><textarea name="message" rows="3" maxlength="5000" placeholder="Tulis balasan..." required></textarea></label>
                                        <button class="btn btn-primary" type="submit">Kirim balasan</button>
                                    </form>
                                </details>
                                <?php if ($canEdit): ?>
                                    <details class="row-details">
                                        <summary class="btn btn-sm">Edit</summary>
                                        <form method="post" class="form-panel row-form">
                                            <?=form_hidden('save_message','messages')?>
                                            <input type="hidden" name="id" value="<?=e($message['id'])?>">
                                            <label>Edit pesan<textarea name="message" rows="3" maxlength="5000" required><?=e($message['message'])?></textarea></label>
                                            <button class="btn btn-primary" type="submit">Simpan perubahan</button>
                                        </form>
                                    </details>
                                    <form method="post" class="inline-action" onsubmit="return confirm('Hapus pesan ini?')">
                                        <?=form_hidden('delete_message','messages')?>
                                        <input type="hidden" name="id" value="<?=e($message['id'])?>">
                                        <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$messages): ?><p class="empty">Belum ada percakapan. Kirim pesan pertama untuk memulai komunikasi.</p><?php endif; ?>
                </div>

            <?php elseif ($page === 'reports'): ?>
                <?php page_heading('Laporan harian','Dokumentasi kondisi, tindakan, dan respons pasien.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="reports"><div class="filter-search"><input type="search" name="q" aria-label="Cari pasien, penulis, ringkasan, atau tindakan" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari pasien, penulis, ringkasan, atau tindakan..."></div><select name="patient_id" aria-label="Filter pasien"><option value="0">Semua pasien</option><?=patient_select_options($activePatients,$selectedPatientFilter)?></select><button class="btn btn-primary" type="submit">Cari laporan</button><?php if ($searchQuery!=='' || $selectedPatientFilter>0): ?><a class="btn btn-outline" href="index.php?page=reports">Reset</a><?php endif; ?><span class="result-count"><?=count($reports)?> hasil</span></form>
                <?php if ($canWriteCare && $activePatients): ?><details class="panel-card form-disclosure"><summary class="btn btn-primary">＋ Buat laporan</summary><form method="post" class="form-panel"><?=form_hidden('save_report','reports')?><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,$selectedPatientFilter)?></select></label><label>Tanggal<input type="date" name="report_date" value="<?=e(date('Y-m-d'))?>" required></label><label class="span-2">Ringkasan<textarea name="summary" rows="3" required></textarea></label><label class="span-2">Tindakan<textarea name="actions_taken" rows="2"></textarea></label><label class="span-2">Respons pasien<textarea name="response" rows="2"></textarea></label></div><button class="btn btn-primary" type="submit">Simpan laporan</button></form></details><?php endif; ?>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Pasien</th><th>Penulis</th><th>Ringkasan</th><th>Tindakan</th><th>Respons</th><?php if ($canWriteCare): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($reports as $report): $canEdit = $role==='admin' || (int)$report['author_id']===(int)$me['id']; ?><tr><td><?=e($report['report_date'])?></td><td><?=e($report['patient'])?></td><td><?=e($report['author'])?></td><td><?=e($report['summary'])?></td><td><?=e($report['actions_taken'])?></td><td><?=e($report['response'])?></td>
                        <?php if ($canWriteCare): ?><td class="actions-cell"><?php if ($canEdit): ?><details class="row-details"><summary class="btn btn-sm">Edit</summary><form method="post" class="form-panel row-form"><?=form_hidden('save_report','reports')?><input type="hidden" name="id" value="<?=e($report['id'])?>"><div class="form-grid"><label>Pasien<select name="patient_id"><?=patient_select_options($activePatients,(int)$report['patient_id'])?></select></label><label>Tanggal<input type="date" name="report_date" value="<?=e($report['report_date'])?>" required></label><label class="span-2">Ringkasan<textarea name="summary" required><?=e($report['summary'])?></textarea></label><label class="span-2">Tindakan<textarea name="actions_taken"><?=e($report['actions_taken'])?></textarea></label><label class="span-2">Respons<textarea name="response"><?=e($report['response'])?></textarea></label></div><button class="btn btn-primary" type="submit">Simpan perubahan</button></form></details><?php endif; ?><?php if ($role==='admin'): ?><form method="post" class="inline-action" onsubmit="return confirm('Hapus laporan ini?')"><?=form_hidden('delete_report','reports')?><input type="hidden" name="id" value="<?=e($report['id'])?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$reports): ?><p class="empty">Belum ada laporan.</p><?php endif; ?></div>

            <?php elseif ($page === 'audit' && $role === 'admin'): ?>
                <?php page_heading('Riwayat aktivitas','Catatan tindakan pengguna untuk membantu penelusuran perubahan data.'); ?>
                <form method="get" action="index.php" class="filter-toolbar" role="search"><input type="hidden" name="page" value="audit"><div class="filter-search"><input type="search" name="q" aria-label="Cari pengguna, tindakan, modul, atau ID data" maxlength="100" value="<?=e($searchQuery)?>" placeholder="Cari pengguna, tindakan, modul, atau ID data..."></div><button class="btn btn-primary" type="submit">Cari aktivitas</button><?php if ($searchQuery!==''): ?><a class="btn btn-outline" href="index.php?page=audit">Reset</a><?php endif; ?><span class="result-count"><?=count($auditRows)?> hasil terbaru</span></form>
                <div class="panel-card table-card"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Tindakan</th><th>Modul</th><th>ID data</th><th>Detail</th><th>IP</th></tr></thead><tbody>
                    <?php foreach ($auditRows as $log): ?><tr><td><?=e($log['created_at'])?></td><td><?=e($log['actor'] ?: 'Pengguna dihapus')?></td><td><span class="tag"><?=e($log['action'])?></span></td><td><?=e($log['entity'])?></td><td><?=e($log['entity_id'] ?? '—')?></td><td><?=e($log['details'] ?: '—')?></td><td><?=e($log['ip_address'] ?: '—')?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php if (!$auditRows): ?><p class="empty">Belum ada aktivitas yang tercatat.</p><?php endif; ?></div>
            <?php endif; ?>

            <footer class="footer">© <?=e(date('Y'))?> RawatBersama <span>Dokumentasi perawatan bukan pengganti keputusan klinis tenaga kesehatan.</span></footer>
        </section>
    </main>
</div>
<?php endif; ?>
<script src="assets/app.js?v=5"></script>
</body>
</html>