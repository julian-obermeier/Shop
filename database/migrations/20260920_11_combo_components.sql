CREATE TABLE IF NOT EXISTS offer_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 category_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 component_type ENUM('physical','digital') NOT NULL DEFAULT 'physical',
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 duration_days INT NULL,
 required TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(category_id) REFERENCES categories(id),
 INDEX(offer_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 source_component_id BIGINT UNSIGNED NULL,
 category_id BIGINT UNSIGNED NOT NULL,
 title_snapshot VARCHAR(190) NOT NULL,
 component_type ENUM('physical','digital') NOT NULL DEFAULT 'physical',
 compensation_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0,
 duration_days INT NULL,
 required TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 status ENUM('preparation','execution','shipping','review','completed','rejected') NOT NULL DEFAULT 'preparation',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(source_component_id) REFERENCES offer_components(id) ON DELETE SET NULL,
 FOREIGN KEY(category_id) REFERENCES categories(id),
 INDEX(order_id,status),
 INDEX(category_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
