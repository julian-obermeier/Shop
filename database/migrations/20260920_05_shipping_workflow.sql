CREATE TABLE IF NOT EXISTS offer_shipping_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 instructions TEXT NULL,
 required_photos INT NOT NULL DEFAULT 0,
 requires_text TINYINT(1) NOT NULL DEFAULT 0,
 requires_checkbox TINYINT(1) NOT NULL DEFAULT 0,
 is_dispatch_step TINYINT(1) NOT NULL DEFAULT 0,
 deadline_hours INT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 INDEX(offer_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_shipping_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 source_step_id BIGINT UNSIGNED NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 instructions TEXT NULL,
 required_photos INT NOT NULL DEFAULT 0,
 requires_text TINYINT(1) NOT NULL DEFAULT 0,
 requires_checkbox TINYINT(1) NOT NULL DEFAULT 0,
 is_dispatch_step TINYINT(1) NOT NULL DEFAULT 0,
 due_at DATETIME NULL,
 status ENUM('locked','open','completed') NOT NULL DEFAULT 'locked',
 submission_json JSON NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(source_step_id) REFERENCES offer_shipping_steps(id) ON DELETE SET NULL,
 INDEX(order_id,sort_order,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
