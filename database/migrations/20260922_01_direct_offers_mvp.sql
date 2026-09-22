CREATE TABLE IF NOT EXISTS direct_offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_no VARCHAR(24) NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 intro TEXT NULL,
 rules_text LONGTEXT NOT NULL,
 status ENUM('draft','sent','accepted','precheck','active','completed','declined','cancelled') NOT NULL DEFAULT 'draft',
 sent_at DATETIME NULL,
 accepted_at DATETIME NULL,
 activated_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 FOREIGN KEY(admin_id) REFERENCES admins(id),
 INDEX(seller_id,status),
 INDEX(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_offer_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 position_no INT NOT NULL,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 duration_days INT NOT NULL DEFAULT 1,
 precheck_required_count INT NOT NULL DEFAULT 1,
 daily_required_count INT NOT NULL DEFAULT 1,
 precheck_instructions TEXT NOT NULL,
 daily_instructions TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES direct_offers(id) ON DELETE CASCADE,
 UNIQUE(offer_id,position_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_offer_acceptances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 rules_snapshot LONGTEXT NOT NULL,
 payload_json LONGTEXT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES direct_offers(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 UNIQUE(offer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_no VARCHAR(32) NOT NULL UNIQUE,
 offer_id BIGINT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 status ENUM('precheck','running','completed','cancelled') NOT NULL DEFAULT 'precheck',
 required_days INT NOT NULL DEFAULT 1,
 extension_days INT NOT NULL DEFAULT 0,
 successful_days INT NOT NULL DEFAULT 0,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 precheck_approved_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(offer_id) REFERENCES direct_offers(id) ON DELETE CASCADE,
 FOREIGN KEY(item_id) REFERENCES direct_offer_items(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 UNIQUE(item_id),
 INDEX(offer_id,status),
 INDEX(seller_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_precheck_uploads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 file_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES direct_orders(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_order_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 day_no INT NOT NULL,
 is_extension TINYINT(1) NOT NULL DEFAULT 0,
 extension_for_day_id BIGINT UNSIGNED NULL,
 required_photo_count INT NOT NULL DEFAULT 1,
 status ENUM('planned','submitted','fulfilled','not_fulfilled') NOT NULL DEFAULT 'planned',
 seller_note TEXT NULL,
 admin_note TEXT NULL,
 submitted_at DATETIME NULL,
 reviewed_at DATETIME NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES direct_orders(id) ON DELETE CASCADE,
 FOREIGN KEY(extension_for_day_id) REFERENCES direct_order_days(id) ON DELETE SET NULL,
 FOREIGN KEY(reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
 UNIQUE(order_id,day_no),
 UNIQUE(extension_for_day_id),
 INDEX(order_id,status,day_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS direct_day_uploads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 day_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 file_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(day_id) REFERENCES direct_order_days(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(day_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
