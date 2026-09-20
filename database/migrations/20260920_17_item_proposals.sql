ALTER TABLE evidences
  MODIFY evidence_type ENUM('precheck','daily','spontaneous','task','damage','shipping','digital','item_proposal') NOT NULL;

ALTER TABLE order_items
  ADD COLUMN source_proposal_id BIGINT UNSIGNED NULL AFTER order_component_id,
  ADD INDEX idx_order_items_source_proposal (source_proposal_id);

CREATE TABLE IF NOT EXISTS order_item_proposals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 order_run_id BIGINT UNSIGNED NULL,
 order_component_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 evidence_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(190) NOT NULL,
 size_value VARCHAR(100) NULL,
 color_value VARCHAR(100) NULL,
 brand_value VARCHAR(120) NULL,
 material_value VARCHAR(120) NULL,
 attributes_json JSON NULL,
 status ENUM('proposed','selected','rejected') NOT NULL DEFAULT 'proposed',
 admin_note TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(order_run_id) REFERENCES order_runs(id) ON DELETE SET NULL,
 FOREIGN KEY(order_component_id) REFERENCES order_components(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 FOREIGN KEY(evidence_id) REFERENCES evidences(id) ON DELETE CASCADE,
 INDEX(order_id,order_run_id,order_component_id,status),
 INDEX(seller_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
