ALTER TABLE offers
  ADD COLUMN digital_rules_json JSON NULL AFTER evidence_rules_json;

ALTER TABLE orders
  ADD COLUMN digital_rules_snapshot_json JSON NULL AFTER shipping_snapshot_json,
  ADD COLUMN digital_due_at DATETIME NULL AFTER digital_rules_snapshot_json;

ALTER TABLE digital_versions
  ADD COLUMN assets_json JSON NULL AFTER text_content;

ALTER TABLE revision_rounds
  ADD COLUMN grace_ends_at DATETIME NULL AFTER due_at;
