-- Jalankan SATU KALI pada database RawatBersama yang sudah berisi tabel versi awal.
-- Backup database terlebih dahulu sebelum menjalankan migrasi.
ALTER TABLE patients
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER family_contact,
    ADD INDEX idx_patients_status_name (status,name);
CREATE TABLE IF NOT EXISTS patient_families (
    user_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,patient_id),
    INDEX idx_patient_families_patient (patient_id),
    CONSTRAINT fk_patient_families_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_patient_families_patient FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
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