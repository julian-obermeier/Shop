SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(190) NOT NULL DEFAULT 'Admin',
 password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sellers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 first_name VARCHAR(120) NOT NULL,
 last_name VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sequences (
 sequence_key VARCHAR(80) PRIMARY KEY,
 next_value INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_no VARCHAR(20) NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 intro TEXT NULL,
 rules_text LONGTEXT NOT NULL,
 status ENUM('draft','sent','accepted','active','completed','cancelled') NOT NULL DEFAULT 'draft',
 sent_at DATETIME NULL,
 accepted_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 FOREIGN KEY(admin_id) REFERENCES admins(id),
 INDEX(seller_id,status), INDEX(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_positions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 position_no INT NOT NULL,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 required_success_days INT NOT NULL DEFAULT 1,
 align_to_offer_end TINYINT(1) NOT NULL DEFAULT 0,
 sync_start_with_offer TINYINT(1) NOT NULL DEFAULT 0,
 precheck_photo_count INT NOT NULL DEFAULT 1,
 precheck_instructions TEXT NOT NULL,
 daily_photo_count INT NOT NULL DEFAULT 1,
 daily_instructions TEXT NOT NULL,
 daily_event_windows_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 UNIQUE(offer_id,position_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_acceptances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 rules_snapshot LONGTEXT NOT NULL,
 positions_snapshot LONGTEXT NOT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_no VARCHAR(30) NOT NULL UNIQUE,
 offer_id BIGINT UNSIGNED NOT NULL,
 position_id BIGINT UNSIGNED NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 title_snapshot VARCHAR(190) NOT NULL,
 description_snapshot TEXT NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 required_success_days INT NOT NULL DEFAULT 1,
 precheck_photo_count INT NOT NULL DEFAULT 1,
 precheck_instructions TEXT NOT NULL,
 daily_photo_count INT NOT NULL DEFAULT 1,
 daily_instructions TEXT NOT NULL,
 daily_event_windows_json LONGTEXT NULL,
 successful_days INT NOT NULL DEFAULT 0,
 extension_days INT NOT NULL DEFAULT 0,
 is_final_day_position TINYINT(1) NOT NULL DEFAULT 0,
 align_to_offer_end TINYINT(1) NOT NULL DEFAULT 0,
 sync_start_with_offer TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('precheck','running','shipping','completed','cancelled') NOT NULL DEFAULT 'precheck',
 precheck_approved_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(position_id) REFERENCES offer_positions(id),
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(offer_id,status), INDEX(seller_id,status), INDEX idx_offer_final_day(offer_id,is_final_day_position,status), INDEX idx_offer_end_aligned(offer_id,align_to_offer_end,status), INDEX idx_offer_synced_start(offer_id,sync_start_with_offer,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS precheck_uploads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 file_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 day_no INT NOT NULL,
 is_extension TINYINT(1) NOT NULL DEFAULT 0,
 manual_extension TINYINT(1) NOT NULL DEFAULT 0,
 extension_for_day_id BIGINT UNSIGNED NULL UNIQUE,
 required_photo_count INT NOT NULL,
 status ENUM('planned','submitted','fulfilled','not_fulfilled') NOT NULL DEFAULT 'planned',
 seller_note TEXT NULL,
 admin_note TEXT NULL,
 late_submission_allowed TINYINT(1) NOT NULL DEFAULT 0,
 late_submission_note TEXT NULL,
 late_submission_requested_at DATETIME NULL,
 late_submission_requested_by BIGINT UNSIGNED NULL,
 fulfilled_by_override TINYINT(1) NOT NULL DEFAULT 0,
 submitted_at DATETIME NULL,
 reviewed_at DATETIME NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(extension_for_day_id) REFERENCES order_days(id) ON DELETE SET NULL,
 FOREIGN KEY(reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
 UNIQUE(order_id,day_no),
 INDEX(order_id,status,day_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_day_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 day_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL,
 label VARCHAR(80) NOT NULL,
 window_start TIME NULL,
 window_end TIME NULL,
 all_day TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('planned','submitted') NOT NULL DEFAULT 'planned',
 seller_note TEXT NULL,
 review_note TEXT NULL,
 resubmission_requested_at DATETIME NULL,
 resubmission_requested_by BIGINT UNSIGNED NULL,
 submitted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(day_id) REFERENCES order_days(id) ON DELETE CASCADE,
 UNIQUE(day_id,event_no),
 INDEX(day_id,status,event_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_uploads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 day_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 file_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(day_id) REFERENCES order_days(id) ON DELETE CASCADE,
 CONSTRAINT fk_day_uploads_event FOREIGN KEY(event_id) REFERENCES order_day_events(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(day_id,created_at),
 INDEX idx_day_uploads_event(event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS app_settings (
 setting_key VARCHAR(120) PRIMARY KEY,
 setting_value LONGTEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seller_payout_profiles (
 seller_id BIGINT UNSIGNED PRIMARY KEY,
 payout_method ENUM('paypal','bank') NULL,
 paypal_email_enc LONGTEXT NULL,
 bank_holder_enc LONGTEXT NULL,
 bank_iban_enc LONGTEXT NULL,
 bank_bic_enc LONGTEXT NULL,
 paypal_email VARCHAR(190) NULL,
 bank_holder VARCHAR(190) NULL,
 bank_iban VARCHAR(80) NULL,
 bank_bic VARCHAR(40) NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seller_wallet_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount DECIMAL(10,2) NOT NULL,
 status ENUM('reserved','available','paid','cancelled') NOT NULL DEFAULT 'reserved',
 payout_method ENUM('paypal','bank') NULL,
 payout_reference VARCHAR(190) NULL,
 available_at DATETIME NULL,
 paid_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(seller_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_shipments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 due_date DATE NOT NULL,
 address_keyword VARCHAR(190) NULL,
 address_name VARCHAR(190) NULL,
 street VARCHAR(190) NULL,
 postal_code VARCHAR(30) NULL,
 city VARCHAR(120) NULL,
 country VARCHAR(120) NULL,
 extra TEXT NULL,
 status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
 confirmed_at DATETIME NULL,
 tracking_number VARCHAR(190) NULL,
 seller_note TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(status,due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS seller_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 used_at DATETIME NULL,
 used_by_seller_id BIGINT UNSIGNED NULL,
 revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY(used_by_seller_id) REFERENCES sellers(id) ON DELETE SET NULL,
 INDEX(email),
 INDEX(expires_at,used_at,revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_role ENUM('admin','seller') NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 type VARCHAR(80) NOT NULL,
 title VARCHAR(190) NOT NULL,
 body TEXT NULL,
 target_url VARCHAR(500) NULL,
 dedupe_key VARCHAR(190) NULL,
 email_category VARCHAR(40) NULL,
 read_at DATETIME NULL,
 email_attempted_at DATETIME NULL,
 email_sent_at DATETIME NULL,
 email_error VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_dedupe(user_role,user_id,dedupe_key),
 INDEX(user_role,user_id,read_at,created_at),
 INDEX(type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seller_notification_preferences (
 seller_id BIGINT UNSIGNED PRIMARY KEY,
 email_offers TINYINT(1) NOT NULL DEFAULT 1,
 email_evidence TINYINT(1) NOT NULL DEFAULT 1,
 email_messages TINYINT(1) NOT NULL DEFAULT 1,
 email_upcoming TINYINT(1) NOT NULL DEFAULT 1,
 email_payouts TINYINT(1) NOT NULL DEFAULT 1,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 sender_role ENUM('admin','seller') NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 read_by_admin_at DATETIME NULL,
 read_by_seller_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(order_id,created_at),
 INDEX(sender_role,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_receipt_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL UNIQUE,
 received_at DATETIME NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 review_text TEXT NULL,
 recorded_by_admin_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(recorded_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
 INDEX(received_at),
 INDEX(rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 payout_method ENUM('paypal','bank') NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 reference VARCHAR(190) NULL,
 note TEXT NULL,
 paid_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
 FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 INDEX(seller_id,paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_batch_entries (
 payout_batch_id BIGINT UNSIGNED NOT NULL,
 wallet_entry_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount DECIMAL(10,2) NOT NULL,
 PRIMARY KEY(payout_batch_id,wallet_entry_id),
 FOREIGN KEY(payout_batch_id) REFERENCES payout_batches(id) ON DELETE CASCADE,
 FOREIGN KEY(wallet_entry_id) REFERENCES seller_wallet_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scent_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 subject VARCHAR(190) NOT NULL,
 instructions TEXT NULL,
 status ENUM('pending','answered','cancelled') NOT NULL DEFAULT 'pending',
 rating TINYINT UNSIGNED NULL,
 seller_note TEXT NULL,
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 answered_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
 FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 INDEX(seller_id,status,requested_at),
 INDEX(status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_role VARCHAR(30) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 offer_id BIGINT UNSIGNED NULL,
 order_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(100) NOT NULL,
 payload_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(offer_id,created_at), INDEX(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
