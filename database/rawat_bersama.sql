CREATE DATABASE IF NOT EXISTS rawat_bersama CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rawat_bersama;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','nurse','family') NOT NULL DEFAULT 'family',
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_users_role_status (role,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS patients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 age TINYINT UNSIGNED NOT NULL,
 gender ENUM('Perempuan','Laki-laki','Lainnya') NOT NULL,
 diagnosis TEXT NULL,
 allergies TEXT NULL,
 address TEXT NULL,
 family_contact VARCHAR(100) NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_patients_status_name (status,name)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS patient_families (
 user_id BIGINT UNSIGNED NOT NULL,
 patient_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id,patient_id),
 INDEX idx_patient_families_patient (patient_id),
 CONSTRAINT fk_patient_families_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_patient_families_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS medications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 dose VARCHAR(100) NOT NULL,
 scheduled_time TIME NOT NULL,
 instructions TEXT NULL,
 status ENUM('pending','given') NOT NULL DEFAULT 'pending',
 given_at DATETIME NULL,
 given_by BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_medications_patient_status_time (patient_id,status,scheduled_time),
 CONSTRAINT fk_med_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
 CONSTRAINT fk_med_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_med_given_by FOREIGN KEY(given_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS visits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 nurse_id BIGINT UNSIGNED NOT NULL,
 visit_at DATETIME NOT NULL,
 notes TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_visits_patient_time (patient_id,visit_at),
 CONSTRAINT fk_visits_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
 CONSTRAINT fk_visits_nurse FOREIGN KEY(nurse_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS observations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 blood_pressure VARCHAR(20) NULL,
 temperature DECIMAL(4,1) NULL,
 wound_size DECIMAL(5,2) NULL,
 complaint TEXT NULL,
 notes TEXT NULL,
 recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_observations_patient_time (patient_id,recorded_at),
 CONSTRAINT fk_observations_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
 CONSTRAINT fk_observations_recorder FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 message TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_messages_patient_time (patient_id,created_at),
 CONSTRAINT fk_messages_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
 CONSTRAINT fk_messages_sender FOREIGN KEY(sender_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 report_date DATE NOT NULL,
 summary TEXT NOT NULL,
 actions_taken TEXT NULL,
 response TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_report_patient_day(patient_id,report_date),
 INDEX idx_reports_date (report_date),
 CONSTRAINT fk_reports_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
 CONSTRAINT fk_reports_author FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(40) NOT NULL,
 entity VARCHAR(50) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 details TEXT NULL,
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_created_at (created_at),
 INDEX idx_audit_actor (actor_id),
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- Buat akun admin pertama melalui setup_admin.php setelah database diimpor.