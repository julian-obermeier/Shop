ALTER TABLE payout_profiles
  ADD COLUMN preferred_method ENUM('bank','paypal') NULL AFTER paypal;
