ALTER TABLE evidence_windows ADD COLUMN order_run_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE evidence_windows ADD INDEX idx_evidence_windows_run (order_id, order_run_id, day_no, status);
ALTER TABLE evidence_windows ADD CONSTRAINT fk_evidence_windows_run FOREIGN KEY (order_run_id) REFERENCES order_runs(id) ON DELETE SET NULL;
ALTER TABLE notifications ADD COLUMN dedupe_key VARCHAR(190) NULL UNIQUE AFTER link;
