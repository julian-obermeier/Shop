CREATE TABLE IF NOT EXISTS shipping_addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 label VARCHAR(190) NOT NULL,
 recipient_name VARCHAR(190) NOT NULL,
 street VARCHAR(190) NOT NULL,
 address_extra VARCHAR(190) NULL,
 postal_code VARCHAR(30) NOT NULL,
 city VARCHAR(150) NOT NULL,
 country_code CHAR(2) NOT NULL DEFAULT 'DE',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE offers
  ADD COLUMN shipping_address_id BIGINT UNSIGNED NULL AFTER shipping_rules_json,
  ADD COLUMN shipping_cost_mode ENUM('seller','fixed','reimburse') NOT NULL DEFAULT 'seller' AFTER shipping_address_id,
  ADD COLUMN shipping_allowance DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER shipping_cost_mode,
  ADD COLUMN preferred_carrier VARCHAR(120) NULL AFTER shipping_allowance,
  ADD CONSTRAINT fk_offers_shipping_address FOREIGN KEY (shipping_address_id) REFERENCES shipping_addresses(id) ON DELETE SET NULL;

ALTER TABLE orders
  ADD COLUMN shipping_snapshot_json JSON NULL AFTER duration_days;

ALTER TABLE shipments
  ADD COLUMN claimed_shipping_cost DECIMAL(10,2) NULL AFTER carrier,
  ADD COLUMN approved_reimbursement DECIMAL(10,2) NULL AFTER claimed_shipping_cost;
