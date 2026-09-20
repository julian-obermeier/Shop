CREATE TABLE IF NOT EXISTS order_bonuses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 status ENUM('reserved','released','cancelled') NOT NULL DEFAULT 'reserved',
 note VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 released_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(order_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE wallet_entries ADD COLUMN bonus_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE wallet_entries ADD INDEX idx_wallet_bonus (bonus_id);
ALTER TABLE wallet_entries ADD CONSTRAINT fk_wallet_bonus FOREIGN KEY (bonus_id) REFERENCES order_bonuses(id) ON DELETE SET NULL;
