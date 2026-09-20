ALTER TABLE payout_requests
  ADD COLUMN scheduled_processing_date DATE NULL AFTER payment_snapshot_json;
