ALTER TABLE evidences
  ADD COLUMN source_type VARCHAR(50) NULL AFTER window_key,
  ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type,
  ADD INDEX idx_evidences_source (source_type, source_id);

ALTER TABLE violations
  ADD COLUMN source_key VARCHAR(190) NULL UNIQUE AFTER violation_type;

ALTER TABLE extra_days
  ADD COLUMN status ENUM('provisional','confirmed','cancelled') NOT NULL DEFAULT 'confirmed' AFTER source_id;

ALTER TABLE order_tasks
  ADD COLUMN submission_json JSON NULL AFTER fields_json,
  ADD COLUMN submitted_at DATETIME NULL AFTER status;

ALTER TABLE offers
  ADD COLUMN visibility ENUM('public','private') NOT NULL DEFAULT 'public' AFTER status;

CREATE TABLE IF NOT EXISTS task_library (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 fields_json JSON NULL,
 default_compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 acceptance_deadline DATETIME NOT NULL,
 status ENUM('assigned','accepted','declined','expired') NOT NULL DEFAULT 'assigned',
 decline_reason TEXT NULL,
 reminded_24h_at DATETIME NULL,
 reminded_1h_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 UNIQUE(offer_id,seller_id),
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
 INDEX(seller_id,status,acceptance_deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
