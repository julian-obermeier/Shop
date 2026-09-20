ALTER TABLE orders
  ADD COLUMN planned_start_date DATE NULL AFTER duration_days,
  ADD COLUMN precheck_approved_at DATETIME NULL AFTER planned_start_date,
  ADD INDEX idx_orders_planned_start (status, precheck_approved_at, planned_start_date);

CREATE TABLE IF NOT EXISTS order_start_date_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 requested_date DATE NOT NULL,
 reason TEXT NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 admin_note TEXT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(order_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
