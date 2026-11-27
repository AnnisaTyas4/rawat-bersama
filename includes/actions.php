<?php
declare(strict_types=1);

function handle_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    verify_csrf();
    $action = post_value('action');

    if ($action === 'login') {
        $email = strtolower(post_value('email'));
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare("SELECT id, password_hash FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if ($account && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$account['id'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            audit_log((int)$account['id'], 'login', 'auth', (int)$account['id']);
            redirect('dashboard');
        }
        flash('Email atau kata sandi tidak sesuai.', 'error');
        redirect('login');
    }

    if ($action === 'logout') {
        $user = current_user();
        if ($user) {
            audit_log((int)$user['id'], 'logout', 'auth', (int)$user['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: index.php?page=login');
        exit;
    }

    $user = require_login();
    $userId = (int)$user['id'];
    $role = $user['role'];
    $pdo = db();

    try {
        switch ($action) {
            case 'save_patient': {
                require_role(['admin']);
                $id = post_id();
                $name = post_value('name');
                $age = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT);
                $gender = post_value('gender');
                if ($name === '' || text_length($name) > 150 || $age === false || $age < 0 || $age > 120 || !in_array($gender, ['Perempuan', 'Laki-laki', 'Lainnya'], true)) {
                    throw new DomainException('Nama, usia (0–120), dan jenis kelamin wajib diisi dengan benar.');
                }
                $data = [$name, $age, $gender, post_value('diagnosis'), post_value('allergies'), post_value('address'), post_value('family_contact')];
                if ($id > 0) {
                    $check = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) {
                        throw new DomainException('Pasien tidak ditemukan.');
                    }
                    $data[] = $id;
                    $pdo->prepare('UPDATE patients SET name=?, age=?, gender=?, diagnosis=?, allergies=?, address=?, family_contact=? WHERE id=?')->execute($data);
                    audit_log($userId, 'update', 'patient', $id, ['name' => $name]);
                    flash('Data pasien berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO patients(name,age,gender,diagnosis,allergies,address,family_contact) VALUES(?,?,?,?,?,?,?)')->execute($data);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($userId, 'create', 'patient', $newId, ['name' => $name]);
                    flash('Pasien baru berhasil ditambahkan.');
                }
                redirect('patients');
            }
            case 'set_patient_status': {
                require_role(['admin']);
                $id = post_id();
                $status = post_value('status');
                if ($id < 1 || !in_array($status, ['active', 'inactive'], true)) {
                    throw new DomainException('Permintaan status pasien tidak valid.');
                }
                $stmt = $pdo->prepare('UPDATE patients SET status = ? WHERE id = ?');
                $stmt->execute([$status, $id]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM patients WHERE id=?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) throw new DomainException('Pasien tidak ditemukan.');
                }
                audit_log($userId, $status === 'active' ? 'restore' : 'archive', 'patient', $id);
                flash($status === 'active' ? 'Pasien berhasil dipulihkan.' : 'Pasien berhasil diarsipkan.');
                redirect('patients');
            }
            case 'save_user': {
                require_role(['admin']);
                $name = post_value('name');
                $email = strtolower(post_value('email'));
                $password = (string)($_POST['password'] ?? '');
                $newRole = post_value('role');
                if ($name === '' || text_length($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || !in_array($newRole, ['admin', 'nurse', 'family'], true)) {
                    throw new DomainException('Lengkapi nama, email valid, peran, dan kata sandi minimal 10 karakter.');
                }
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,?,\'active\')')->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $newRole]);
                $newId = (int)$pdo->lastInsertId();
                sync_family_patients($newId, $newRole, (array)($_POST['patient_ids'] ?? []));
                $pdo->commit();
                audit_log($userId, 'create', 'user', $newId, ['email' => $email, 'role' => $newRole]);
                flash('Akun pengguna berhasil dibuat.');
                redirect('users');
            }
            case 'update_user': {
                require_role(['admin']);
                $id = post_id();
                $name = post_value('name');
                $email = strtolower(post_value('email'));
                $password = (string)($_POST['password'] ?? '');
                $newRole = post_value('role');
                if ($id < 1 || $name === '' || text_length($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($newRole, ['admin', 'nurse', 'family'], true) || ($password !== '' && strlen($password) < 10)) {
                    throw new DomainException('Data pengguna tidak valid. Kata sandi baru harus minimal 10 karakter.');
                }
                $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id=? LIMIT 1');
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old) throw new DomainException('Pengguna tidak ditemukan.');
                if ($id === $userId && $newRole !== 'admin') throw new DomainException('Peran akun admin yang sedang digunakan tidak dapat diubah sendiri.');
                if ($old['role'] === 'admin' && $newRole !== 'admin') {
                    $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
                    if ($admins <= 1) throw new DomainException('Tidak dapat mengubah peran admin terakhir.');
                }
                $pdo->beginTransaction();
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET name=?, email=?, role=?, password_hash=? WHERE id=?')->execute([$name, $email, $newRole, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name=?, email=?, role=? WHERE id=?')->execute([$name, $email, $newRole, $id]);
                }
                sync_family_patients($id, $newRole, (array)($_POST['patient_ids'] ?? []));
                $pdo->commit();
                audit_log($userId, 'update', 'user', $id, ['email' => $email, 'role' => $newRole]);
                flash('Data pengguna berhasil diperbarui.');
                redirect('users');
            }
            case 'set_user_status': {
                require_role(['admin']);
                $id = post_id();
                $status = post_value('status');
                if ($id < 1 || !in_array($status, ['active', 'inactive'], true)) throw new DomainException('Permintaan status pengguna tidak valid.');
                if ($id === $userId && $status === 'inactive') throw new DomainException('Anda tidak dapat menonaktifkan akun yang sedang digunakan.');
                $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id=?');
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) throw new DomainException('Pengguna tidak ditemukan.');
                if ($target['role'] === 'admin' && $status === 'inactive') {
                    $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
                    if ($admins <= 1) throw new DomainException('Admin aktif terakhir tidak dapat dinonaktifkan.');
                }
                $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status, $id]);
                audit_log($userId, $status === 'active' ? 'restore' : 'deactivate', 'user', $id);
                flash($status === 'active' ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.');
                redirect('users');
            }
            case 'save_medication': {
                require_role(['admin', 'nurse']);
                $id = post_id();
                $patientId = post_id('patient_id');
                $name = post_value('name');
                $dose = post_value('dose');
                $time = post_value('scheduled_time');
                if ($patientId < 1 || $name === '' || $dose === '' || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new DomainException('Pasien, nama obat, dosis, dan waktu wajib diisi dengan benar.');
                assert_patient_access($patientId, $user);
                if ($id > 0) {
                    $stmt = $pdo->prepare('SELECT patient_id FROM medications WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
                    if (!$old) throw new DomainException('Jadwal obat tidak ditemukan.');
                    assert_patient_access((int)$old['patient_id'], $user);
                    $pdo->prepare('UPDATE medications SET patient_id=?,name=?,dose=?,scheduled_time=?,instructions=? WHERE id=?')->execute([$patientId,$name,$dose,$time,post_value('instructions'),$id]);
                    audit_log($userId, 'update', 'medication', $id, ['name' => $name]);
                    flash('Jadwal obat berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO medications(patient_id,name,dose,scheduled_time,instructions,created_by) VALUES(?,?,?,?,?,?)')->execute([$patientId,$name,$dose,$time,post_value('instructions'),$userId]);
                    $newId = (int)$pdo->lastInsertId(); audit_log($userId, 'create', 'medication', $newId, ['name' => $name]);
                    flash('Jadwal obat berhasil ditambahkan.');
                }
                redirect('medications');
            }
            case 'set_medication_status': {
                require_role(['admin', 'nurse']);
                $id = post_id(); $status = post_value('status');
                if (!in_array($status, ['pending', 'given'], true)) throw new DomainException('Status obat tidak valid.');
                $stmt = $pdo->prepare('SELECT patient_id FROM medications WHERE id=?'); $stmt->execute([$id]); $record = $stmt->fetch();
                if (!$record) throw new DomainException('Jadwal obat tidak ditemukan.');
                assert_patient_access((int)$record['patient_id'], $user);
                $pdo->prepare('UPDATE medications SET status=?,given_at=IF(?=\'given\',NOW(),NULL),given_by=IF(?=\'given\',?,NULL) WHERE id=?')->execute([$status,$status,$status,$userId,$id]);
                audit_log($userId, 'status_change', 'medication', $id, ['status' => $status]);
                flash('Status pemberian obat diperbarui.'); redirect('medications');
            }
            case 'delete_medication': {
                require_role(['admin']);
                $id = post_id(); $stmt = $pdo->prepare('SELECT patient_id,name FROM medications WHERE id=?'); $stmt->execute([$id]); $record = $stmt->fetch();
                if (!$record) throw new DomainException('Jadwal obat tidak ditemukan.');
                $pdo->prepare('DELETE FROM medications WHERE id=?')->execute([$id]);
                audit_log($userId, 'delete', 'medication', $id, ['name' => $record['name']]); flash('Jadwal obat berhasil dihapus.'); redirect('medications');
            }
            case 'save_visit': {
                require_role(['admin', 'nurse']);
                $id = post_id(); $patientId = post_id('patient_id'); $visitAt = valid_datetime_local(post_value('visit_at')); $notes = post_value('notes');
                if ($patientId < 1 || !$visitAt || $notes === '') throw new DomainException('Pasien, waktu kunjungan, dan catatan wajib diisi.');
                assert_patient_access($patientId, $user);
                if ($id > 0) {
                    $old = assert_record_editable('visits', $id, $user);
                    $pdo->prepare('UPDATE visits SET patient_id=?,visit_at=?,notes=? WHERE id=?')->execute([$patientId,$visitAt,$notes,$id]);
                    audit_log($userId, 'update', 'visit', $id);
                    flash('Catatan kunjungan berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO visits(patient_id,nurse_id,visit_at,notes) VALUES(?,?,?,?)')->execute([$patientId,$userId,$visitAt,$notes]);
                    $newId = (int)$pdo->lastInsertId(); audit_log($userId, 'create', 'visit', $newId); flash('Kunjungan berhasil dicatat.');
                }
                redirect('visits');
            }
            case 'delete_visit': {
                require_role(['admin']); $id = post_id();
                $record = record_owner('visits', $id); if (!$record) throw new DomainException('Kunjungan tidak ditemukan.');
                $pdo->prepare('DELETE FROM visits WHERE id=?')->execute([$id]); audit_log($userId, 'delete', 'visit', $id); flash('Catatan kunjungan dihapus.'); redirect('visits');
            }
            case 'save_observation': {
                require_role(['admin', 'nurse']);
                $id = post_id(); $patientId = post_id('patient_id');
                $temperature = post_value('temperature'); $wound = post_value('wound_size');
                if ($patientId < 1) throw new DomainException('Pilih pasien terlebih dahulu.');
                if ($temperature !== '' && (!is_numeric($temperature) || (float)$temperature < 25 || (float)$temperature > 45)) throw new DomainException('Suhu harus berada dalam rentang 25–45 °C.');
                if ($wound !== '' && (!is_numeric($wound) || (float)$wound < 0 || (float)$wound > 999)) throw new DomainException('Ukuran luka tidak valid.');
                assert_patient_access($patientId, $user);
                $data = [$patientId, post_value('blood_pressure'), $temperature === '' ? null : (float)$temperature, $wound === '' ? null : (float)$wound, post_value('complaint'), post_value('notes')];
                if ($id > 0) {
                    assert_record_editable('observations', $id, $user);
                    $pdo->prepare('UPDATE observations SET patient_id=?,blood_pressure=?,temperature=?,wound_size=?,complaint=?,notes=? WHERE id=?')->execute([...$data,$id]);
                    audit_log($userId, 'update', 'observation', $id); flash('Pantauan kondisi berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO observations(patient_id,recorded_by,blood_pressure,temperature,wound_size,complaint,notes) VALUES(?,?,?,?,?,?,?)')->execute([$patientId,$userId,...array_slice($data,1)]);
                    $newId = (int)$pdo->lastInsertId(); audit_log($userId, 'create', 'observation', $newId); flash('Pantauan kondisi berhasil disimpan.');
                }
                redirect('observations');
            }
            case 'delete_observation': {
                require_role(['admin']); $id = post_id();
                $record = record_owner('observations', $id); if (!$record) throw new DomainException('Data pantauan tidak ditemukan.');
                $pdo->prepare('DELETE FROM observations WHERE id=?')->execute([$id]); audit_log($userId, 'delete', 'observation', $id); flash('Data pantauan dihapus.'); redirect('observations');
            }
            case 'save_report': {
                require_role(['admin', 'nurse']);
                $id = post_id(); $patientId = post_id('patient_id'); $date = post_value('report_date'); $summary = post_value('summary');
                if ($patientId < 1 || !valid_date($date) || $summary === '') throw new DomainException('Pasien, tanggal, dan ringkasan laporan wajib diisi.');
                assert_patient_access($patientId, $user);
                $actions = post_value('actions_taken'); $response = post_value('response');
                if ($id > 0) {
                    assert_record_editable('reports', $id, $user);
                    $pdo->prepare('UPDATE reports SET patient_id=?,report_date=?,summary=?,actions_taken=?,response=? WHERE id=?')->execute([$patientId,$date,$summary,$actions,$response,$id]);
                    audit_log($userId, 'update', 'report', $id); flash('Laporan berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO reports(patient_id,author_id,report_date,summary,actions_taken,response) VALUES(?,?,?,?,?,?)')->execute([$patientId,$userId,$date,$summary,$actions,$response]);
                    $newId = (int)$pdo->lastInsertId(); audit_log($userId, 'create', 'report', $newId); flash('Laporan berhasil dibuat.');
                }
                redirect('reports');
            }
            case 'delete_report': {
                require_role(['admin']); $id = post_id();
                $record = record_owner('reports', $id); if (!$record) throw new DomainException('Laporan tidak ditemukan.');
                $pdo->prepare('DELETE FROM reports WHERE id=?')->execute([$id]); audit_log($userId, 'delete', 'report', $id); flash('Laporan berhasil dihapus.'); redirect('reports');
            }
            case 'save_message': {
                $id = post_id(); $patientId = post_id('patient_id'); $message = post_value('message');
                if ($patientId < 1 || $message === '' || text_length($message) > 5000) throw new DomainException('Pilih pasien dan isi pesan (maksimal 5.000 karakter).');
                assert_patient_access($patientId, $user);
                if ($id > 0) {
                    $record = assert_record_editable('messages', $id, $user);
                    $pdo->prepare('UPDATE messages SET patient_id=?,message=? WHERE id=?')->execute([$patientId,$message,$id]);
                    audit_log($userId, 'update', 'message', $id); flash('Pesan berhasil diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO messages(patient_id,sender_id,message) VALUES(?,?,?)')->execute([$patientId,$userId,$message]);
                    $newId = (int)$pdo->lastInsertId(); audit_log($userId, 'create', 'message', $newId); flash('Pesan berhasil dikirim.');
                }
                redirect('messages');
            }
            case 'delete_message': {
                $id = post_id(); $record = assert_record_editable('messages', $id, $user);
                $pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$id]); audit_log($userId, 'delete', 'message', $id); flash('Pesan berhasil dihapus.'); redirect('messages');
            }
            default:
                flash('Tindakan tidak dikenali.', 'error');
                redirect('dashboard');
        }
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($exception->getMessage(), 'error');
        $returnPage = (string)($_POST['return_page'] ?? 'dashboard');
        $validPages = ['dashboard','patients','users','medications','visits','observations','messages','reports','audit'];
        redirect(in_array($returnPage, $validPages, true) ? $returnPage : 'dashboard');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('RawatBersama database error: ' . $exception->getMessage());
        $message = (string)$exception->getCode() === '23000'
            ? 'Data tidak dapat disimpan karena email sudah digunakan atau terdapat relasi data yang masih dipakai.'
            : 'Terjadi kendala saat menyimpan data. Periksa koneksi database dan coba kembali.';
        flash($message, 'error');
        $returnPage = (string)($_POST['return_page'] ?? 'dashboard');
        $validPages = ['dashboard','patients','users','medications','visits','observations','messages','reports','audit'];
        redirect(in_array($returnPage, $validPages, true) ? $returnPage : 'dashboard');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('RawatBersama application error: ' . $exception->getMessage());
        flash('Terjadi kesalahan aplikasi. Silakan coba kembali atau hubungi administrator.', 'error');
        $returnPage = (string)($_POST['return_page'] ?? 'dashboard');
        $validPages = ['dashboard','patients','users','medications','visits','observations','messages','reports','audit'];
        redirect(in_array($returnPage, $validPages, true) ? $returnPage : 'dashboard');
    }
}
