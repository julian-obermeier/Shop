ALTER TABLE order_items
  ADD COLUMN order_component_id BIGINT UNSIGNED NULL AFTER order_run_id,
  ADD INDEX idx_order_items_component (order_id,order_run_id,order_component_id),
  ADD CONSTRAINT fk_order_items_component FOREIGN KEY (order_component_id) REFERENCES order_components(id) ON DELETE SET NULL;

ALTER TABLE evidences
  ADD COLUMN order_component_id BIGINT UNSIGNED NULL AFTER order_run_id,
  ADD INDEX idx_evidences_component (order_id,order_run_id,order_component_id,evidence_type),
  ADD CONSTRAINT fk_evidences_component FOREIGN KEY (order_component_id) REFERENCES order_components(id) ON DELETE SET NULL;

ALTER TABLE damage_cases
  ADD COLUMN order_component_id BIGINT UNSIGNED NULL AFTER order_id,
  ADD INDEX idx_damage_component (order_id,order_component_id),
  ADD CONSTRAINT fk_damage_component FOREIGN KEY (order_component_id) REFERENCES order_components(id) ON DELETE SET NULL;
