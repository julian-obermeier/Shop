ALTER TABLE evidences ADD COLUMN reference_type VARCHAR(60) NULL AFTER status;
ALTER TABLE evidences ADD COLUMN reference_id BIGINT UNSIGNED NULL AFTER reference_type;
ALTER TABLE violations ADD COLUMN source_key VARCHAR(190) NULL UNIQUE AFTER violation_type;
ALTER TABLE extra_days ADD COLUMN status ENUM('provisional','confirmed','removed') NOT NULL DEFAULT 'confirmed' AFTER source_id;

CREATE TABLE IF NOT EXISTS order_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 offer_option_id BIGINT UNSIGNED NULL,
 label_snapshot VARCHAR(190) NOT NULL,
 price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0,
 requirements_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(offer_option_id) REFERENCES offer_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
