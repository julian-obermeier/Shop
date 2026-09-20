CREATE TABLE IF NOT EXISTS order_evidence_plan_overrides (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 effective_day_no INT NOT NULL,
 rules_json JSON NOT NULL,
 reason TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(admin_id) REFERENCES admins(id),
 INDEX(order_id,effective_day_no,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
