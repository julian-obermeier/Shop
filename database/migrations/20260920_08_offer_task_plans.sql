ALTER TABLE task_library
  ADD COLUMN default_required_photos INT NOT NULL DEFAULT 0 AFTER fields_json;

CREATE TABLE IF NOT EXISTS offer_task_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 task_library_id BIGINT UNSIGNED NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 fields_json JSON NULL,
 required_photos INT NOT NULL DEFAULT 0,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 schedule_type ENUM('day','interval') NOT NULL DEFAULT 'day',
 day_no INT NULL,
 start_day INT NOT NULL DEFAULT 1,
 interval_days INT NULL,
 due_time TIME NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(task_library_id) REFERENCES task_library(id) ON DELETE SET NULL,
 INDEX(offer_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_task_specs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 source_plan_id BIGINT UNSIGNED NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 fields_json JSON NULL,
 required_photos INT NOT NULL DEFAULT 0,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 schedule_type ENUM('day','interval') NOT NULL DEFAULT 'day',
 day_no INT NULL,
 start_day INT NOT NULL DEFAULT 1,
 interval_days INT NULL,
 due_time TIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(source_plan_id) REFERENCES offer_task_plans(id) ON DELETE SET NULL,
 INDEX(order_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE order_tasks
  ADD COLUMN source_spec_id BIGINT UNSIGNED NULL AFTER order_id,
  ADD COLUMN planned_day_no INT NULL AFTER due_at,
  ADD COLUMN required_photos INT NOT NULL DEFAULT 0 AFTER fields_json,
  ADD CONSTRAINT fk_order_tasks_source_spec FOREIGN KEY(source_spec_id) REFERENCES order_task_specs(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_order_task_spec_day (order_id,source_spec_id,planned_day_no);
