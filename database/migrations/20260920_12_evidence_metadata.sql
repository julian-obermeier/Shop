ALTER TABLE evidences
  ADD COLUMN metadata_json JSON NULL AFTER sha256,
  ADD COLUMN quality_flags_json JSON NULL AFTER metadata_json;
