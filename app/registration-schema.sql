CREATE TABLE IF NOT EXISTS membership_registrations (
 member_id BIGINT UNSIGNED PRIMARY KEY,
 academic_year VARCHAR(11) NOT NULL,
 contact_number VARCHAR(40) NOT NULL, birthday DATE NOT NULL,
 department VARCHAR(160) NOT NULL, institution_address VARCHAR(500) NOT NULL,
 joined_year SMALLINT UNSIGNED NOT NULL, membership_type VARCHAR(10) NOT NULL DEFAULT 'New',
 payment_date DATE NOT NULL, payment_amount DECIMAL(10,2) NOT NULL,
 interests TEXT NOT NULL, willing_speaker TINYINT(1) NOT NULL, suggestions TEXT NOT NULL,
 proof_mime VARCHAR(40) NOT NULL, proof_data MEDIUMBLOB NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 consent_at DATETIME NOT NULL, reviewed_at DATETIME NULL, reviewed_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
