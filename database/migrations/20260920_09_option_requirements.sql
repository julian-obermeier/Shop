ALTER TABLE order_options
  ADD COLUMN requirements_snapshot_json JSON NULL AFTER price_snapshot;

ALTER TABLE order_tasks
  ADD COLUMN source_offer_option_id BIGINT UNSIGNED NULL AFTER source_spec_id,
  ADD INDEX idx_order_tasks_offer_option (order_id,source_offer_option_id,status);
