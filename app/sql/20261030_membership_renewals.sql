-- Preserves existing registrations and payment proofs.
CREATE TABLE IF NOT EXISTS membership_renewals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id BIGINT UNSIGNED NOT NULL,
 academic_year VARCHAR(11) NOT NULL,
 payment_date DATE NOT NULL, payment_amount DECIMAL(10,2) NOT NULL,
 proof_mime VARCHAR(40) NOT NULL, proof_data MEDIUMBLOB NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 reviewed_at DATETIME NULL, reviewed_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY member_year (member_id,academic_year),
 FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
