ALTER TABLE orders ADD COLUMN archived_at DATETIME NULL AFTER completed_at;
ALTER TABLE orders ADD INDEX idx_orders_archived (archived_at);
