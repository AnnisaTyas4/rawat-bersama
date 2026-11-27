-- Jalankan SATU KALI setelah migrasi 001 pada database RawatBersama yang sudah ada.
-- Backup database terlebih dahulu. Jangan jalankan ulang setelah berhasil.
ALTER TABLE messages
    ADD COLUMN reply_to_id BIGINT UNSIGNED NULL AFTER message,
    ADD INDEX idx_messages_reply_to (reply_to_id),
    ADD CONSTRAINT fk_messages_reply_to
        FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL;
