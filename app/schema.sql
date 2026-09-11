CREATE TABLE IF NOT EXISTS admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS elections (
 id BIGINT UNSIGNED PRIMARY KEY,
 title VARCHAR(160) NOT NULL DEFAULT 'Officer Election',
 phase ENUM('draft','nomination','review','voting','closed') NOT NULL DEFAULT 'draft',
 nomination_limit INT UNSIGNED NOT NULL DEFAULT 5,
 vote_limit INT UNSIGNED NOT NULL DEFAULT 3,
 officer_count INT UNSIGNED NOT NULL DEFAULT 3,
 generation INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO elections (id) VALUES (1);
CREATE TABLE IF NOT EXISTS members (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
 member_status ENUM('Officer','Member') NOT NULL DEFAULT 'Member',
 middle_initial VARCHAR(10) NOT NULL DEFAULT '', email VARCHAR(254) NOT NULL UNIQUE,
 school VARCHAR(160) NOT NULL DEFAULT '', position VARCHAR(120) NOT NULL DEFAULT '',
 active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS submissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id BIGINT UNSIGNED NOT NULL,
 stage ENUM('nomination','voting') NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY once_per_stage (member_id, stage),
 FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS choices (
 submission_id BIGINT UNSIGNED NOT NULL,
 candidate_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (submission_id,candidate_id),
 FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
 FOREIGN KEY (candidate_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nominee_decisions (
 member_id BIGINT UNSIGNED PRIMARY KEY,
 decision ENUM('pending','accepted','denied') NOT NULL DEFAULT 'pending',
 FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS otp_challenges (
 id CHAR(32) PRIMARY KEY, email VARCHAR(254) NOT NULL,
 purpose VARCHAR(40) NOT NULL, code_hash VARCHAR(255) NOT NULL,
 context_hash CHAR(64) NOT NULL, attempts INT NOT NULL DEFAULT 0,
 expires_at DATETIME NOT NULL, consumed TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY otp_email (email, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL DEFAULT 1,
 expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NULL, action VARCHAR(80) NOT NULL,
 details TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nominee_invitations (
 id CHAR(32) PRIMARY KEY,
 member_id BIGINT UNSIGNED NOT NULL,
 generation INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 delivery_status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 claim_token CHAR(32) NULL,
 claim_expires_at DATETIME NULL,
 last_attempt_at DATETIME NULL,
 sent_at DATETIME NULL,
 responded_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY one_invitation_per_round (member_id,generation),
 KEY delivery_queue (generation,delivery_status),
 FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_photos (
 member_id BIGINT UNSIGNED PRIMARY KEY,
 mime_type VARCHAR(32) NOT NULL,
 image_data MEDIUMBLOB NOT NULL,
 FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
