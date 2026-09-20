ALTER TABLE digital_versions ADD COLUMN review_note TEXT NULL AFTER status;
ALTER TABLE digital_versions ADD COLUMN reviewed_at DATETIME NULL AFTER review_note;
