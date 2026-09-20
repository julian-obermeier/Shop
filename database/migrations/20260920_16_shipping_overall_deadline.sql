ALTER TABLE offers
  ADD COLUMN shipping_window_hours INT NULL AFTER preferred_carrier;

ALTER TABLE orders
  ADD COLUMN shipping_due_at DATETIME NULL AFTER shipping_snapshot_json;
