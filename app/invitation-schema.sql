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
