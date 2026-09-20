CREATE TABLE IF NOT EXISTS task_submissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_task_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 payload_json JSON NULL,
 status ENUM('submitted','accepted','rejected') NOT NULL DEFAULT 'submitted',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY(order_task_id) REFERENCES order_tasks(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
