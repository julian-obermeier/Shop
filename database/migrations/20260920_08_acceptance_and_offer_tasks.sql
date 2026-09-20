CREATE TABLE IF NOT EXISTS order_acceptance_confirmations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 offer_version INT NOT NULL,
 payload_json JSON NOT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 task_library_id BIGINT UNSIGNED NULL,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 fields_json JSON NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 schedule_type ENUM('day','interval','start','end') NOT NULL DEFAULT 'day',
 schedule_value INT NULL,
 due_time TIME NOT NULL DEFAULT '20:00:00',
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(task_library_id) REFERENCES task_library(id) ON DELETE SET NULL,
 INDEX(offer_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE order_tasks
  ADD COLUMN source_offer_task_id BIGINT UNSIGNED NULL AFTER order_id,
  ADD COLUMN planned_day_no INT NULL AFTER source_offer_task_id,
  ADD INDEX idx_order_tasks_source (order_id,source_offer_task_id,planned_day_no),
  ADD CONSTRAINT fk_order_tasks_offer_task FOREIGN KEY (source_offer_task_id) REFERENCES offer_tasks(id) ON DELETE SET NULL;
