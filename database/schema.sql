SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sellers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL,
 birth_date DATE NOT NULL,
 street VARCHAR(190) NOT NULL,
 postal_code VARCHAR(20) NOT NULL,
 city VARCHAR(120) NOT NULL,
 phone VARCHAR(80) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 email_verified_at DATETIME NULL,
 deleted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 INDEX(email_verified_at), INDEX(deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 parent_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NOT NULL,
 slug VARCHAR(190) NOT NULL UNIQUE,
 icon VARCHAR(80) NULL,
 is_system TINYINT(1) NOT NULL DEFAULT 1,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS category_fields (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 category_id BIGINT UNSIGNED NOT NULL,
 field_key VARCHAR(100) NOT NULL,
 label VARCHAR(190) NOT NULL,
 field_type ENUM('text','number','select','multiselect','boolean','date') NOT NULL DEFAULT 'text',
 options_json JSON NULL,
 required TINYINT(1) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 UNIQUE(category_id,field_key),
 FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipping_addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 label VARCHAR(190) NOT NULL,
 recipient_name VARCHAR(190) NOT NULL,
 street VARCHAR(190) NOT NULL,
 address_extra VARCHAR(190) NULL,
 postal_code VARCHAR(30) NOT NULL,
 city VARCHAR(150) NOT NULL,
 country_code CHAR(2) NOT NULL DEFAULT 'DE',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 category_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 slug VARCHAR(190) NOT NULL UNIQUE,
 description TEXT NOT NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 duration_days INT NULL,
 shipping_snapshot_json JSON NULL,
 fulfillment_type ENUM('days','units','one_time','digital','mixed') NOT NULL DEFAULT 'days',
 evidence_rules_json JSON NULL,
 shipping_rules_json JSON NULL,
 shipping_address_id BIGINT UNSIGNED NULL,
 shipping_cost_mode ENUM('seller','fixed','reimburse') NOT NULL DEFAULT 'seller',
 shipping_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
 preferred_carrier VARCHAR(120) NULL,
 status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
 visibility ENUM('public','private') NOT NULL DEFAULT 'public',
 current_version INT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(category_id) REFERENCES categories(id),
 FOREIGN KEY(shipping_address_id) REFERENCES shipping_addresses(id) ON DELETE SET NULL,
 INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 version_no INT NOT NULL,
 snapshot_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(offer_id,version_no),
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(190) NOT NULL,
 price DECIMAL(10,2) NOT NULL DEFAULT 0,
 requirements_json JSON NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_no VARCHAR(20) NOT NULL UNIQUE,
 seller_id BIGINT UNSIGNED NOT NULL,
 offer_id BIGINT UNSIGNED NOT NULL,
 offer_version INT NOT NULL,
 status ENUM('precheck','running','shipping','review','payout','completed','rejected','archived') NOT NULL DEFAULT 'precheck',
 base_compensation DECIMAL(10,2) NOT NULL,
 total_compensation DECIMAL(10,2) NOT NULL,
 released_amount DECIMAL(10,2) NULL,
 duration_days INT NULL,
 planned_start_date DATE NULL,
 precheck_approved_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 archived_at DATETIME NULL,
 rejection_reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 FOREIGN KEY(offer_id) REFERENCES offers(id),
 INDEX(seller_id,status), INDEX(status), INDEX(archived_at), INDEX(status,precheck_approved_at,planned_start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_start_date_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 requested_date DATE NOT NULL,
 reason TEXT NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 admin_note TEXT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(order_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 offer_option_id BIGINT UNSIGNED NOT NULL,
 label_snapshot VARCHAR(190) NOT NULL,
 price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(order_id,offer_option_id),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(offer_option_id) REFERENCES offer_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 run_no INT NOT NULL,
 item_label VARCHAR(190) NULL,
 started_at DATETIME NULL,
 ended_at DATETIME NULL,
 restart_reason TEXT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'precheck',
 UNIQUE(order_id,run_no),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 order_run_id BIGINT UNSIGNED NULL,
 label VARCHAR(190) NOT NULL,
 size_value VARCHAR(100) NULL,
 color_value VARCHAR(100) NULL,
 brand_value VARCHAR(120) NULL,
 material_value VARCHAR(120) NULL,
 attributes_json JSON NULL,
 locked_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(order_run_id) REFERENCES order_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evidences (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 order_run_id BIGINT UNSIGNED NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 evidence_type ENUM('precheck','daily','spontaneous','task','damage','shipping','digital') NOT NULL,
 day_no INT NULL,
 window_key VARCHAR(50) NULL,
 source_type VARCHAR(50) NULL,
 source_id BIGINT UNSIGNED NULL,
 file_path VARCHAR(255) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 is_late TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('submitted','accepted','rejected') NOT NULL DEFAULT 'submitted',
 rejection_reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 INDEX(source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS violations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 violation_type VARCHAR(80) NOT NULL,
 source_key VARCHAR(190) NULL UNIQUE,
 status ENUM('open','reviewed','confirmed','discarded') NOT NULL DEFAULT 'open',
 reason TEXT NULL,
 extension_days INT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extra_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 source_type ENUM('violation','manual','damage') NOT NULL,
 source_id BIGINT UNSIGNED NULL,
 status ENUM('provisional','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
 paid TINYINT(1) NOT NULL DEFAULT 0,
 amount DECIMAL(10,2) NOT NULL DEFAULT 0,
 reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS damage_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 reason TEXT NOT NULL,
 status ENUM('reported','evidence_requested','review','approved','rejected','restarted') NOT NULL DEFAULT 'reported',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_bonuses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 status ENUM('reserved','released','cancelled') NOT NULL DEFAULT 'reserved',
 note VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 released_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX(order_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NULL,
 bonus_id BIGINT UNSIGNED NULL,
 entry_type ENUM('reserved','review','available','paid','cancelled','adjustment') NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 description VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
 FOREIGN KEY(bonus_id) REFERENCES order_bonuses(id) ON DELETE SET NULL,
 INDEX(seller_id,entry_type), INDEX(bonus_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL UNIQUE,
 iban VARCHAR(80) NULL,
 bic VARCHAR(30) NULL,
 account_holder VARCHAR(190) NULL,
 paypal VARCHAR(190) NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 fee DECIMAL(10,2) NOT NULL DEFAULT 0,
 net_amount DECIMAL(10,2) NOT NULL,
 method VARCHAR(40) NOT NULL,
 payment_snapshot_json JSON NOT NULL,
 status ENUM('requested','review','released','paid','withdrawn','rejected') NOT NULL DEFAULT 'requested',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 FOREIGN KEY(seller_id) REFERENCES sellers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 sender_type ENUM('seller','admin','system') NOT NULL,
 sender_id BIGINT UNSIGNED NULL,
 message TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS digital_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 version_no INT NOT NULL,
 file_path VARCHAR(255) NULL,
 text_content LONGTEXT NULL,
 mime_type VARCHAR(120) NULL,
 sha256 CHAR(64) NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'draft',
 review_note TEXT NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(order_id,version_no),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS revision_rounds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 round_no INT NOT NULL,
 status ENUM('open','submitted','reviewed') NOT NULL DEFAULT 'open',
 due_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(order_id,round_no),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS revision_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 revision_round_id BIGINT UNSIGNED NOT NULL,
 description TEXT NOT NULL,
 location_ref VARCHAR(190) NULL,
 priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
 status ENUM('open','done','insufficient','change_again') NOT NULL DEFAULT 'open',
 FOREIGN KEY(revision_round_id) REFERENCES revision_rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(190) PRIMARY KEY,
 setting_value LONGTEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS number_sequences (
 sequence_key VARCHAR(80) PRIMARY KEY,
 next_value INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NULL,
 order_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(100) NOT NULL,
 payload_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(order_id), INDEX(seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS order_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 order_run_id BIGINT UNSIGNED NULL,
 day_no INT NOT NULL,
 day_type ENUM('regular','violation','manual','damage') NOT NULL DEFAULT 'regular',
 calendar_date DATE NULL,
 status ENUM('planned','active','completed','missed') NOT NULL DEFAULT 'planned',
 source_ref VARCHAR(120) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(order_id,order_run_id,day_no),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evidence_windows (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 order_run_id BIGINT UNSIGNED NULL,
 day_no INT NOT NULL,
 window_key ENUM('morning','midday','evening','custom') NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 grace_ends_at DATETIME NULL,
 required_count INT NOT NULL DEFAULT 1,
 status ENUM('planned','open','submitted','missed','waived') NOT NULL DEFAULT 'planned',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(order_run_id) REFERENCES order_runs(id) ON DELETE SET NULL,
 INDEX(order_id,order_run_id,day_no,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spontaneous_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 instructions TEXT NOT NULL,
 required_count INT NOT NULL DEFAULT 1,
 due_at DATETIME NOT NULL,
 grace_ends_at DATETIME NULL,
 status ENUM('requested','seen','confirmed','uploaded','reviewed','missed') NOT NULL DEFAULT 'requested',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 due_at DATETIME NULL,
 fields_json JSON NULL,
 submission_json JSON NULL,
 compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 status ENUM('open','submitted','accepted','rejected') NOT NULL DEFAULT 'open',
 submitted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_library (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(190) NOT NULL,
 description TEXT NULL,
 fields_json JSON NULL,
 default_compensation DECIMAL(10,2) NOT NULL DEFAULT 0,
 violation_enabled TINYINT(1) NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 acceptance_deadline DATETIME NOT NULL,
 status ENUM('assigned','accepted','declined','expired') NOT NULL DEFAULT 'assigned',
 decline_reason TEXT NULL,
 reminded_24h_at DATETIME NULL,
 reminded_1h_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 UNIQUE(offer_id,seller_id),
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
 INDEX(seller_id,status,acceptance_deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offer_shipping_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 offer_id BIGINT UNSIGNED NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 instructions TEXT NULL,
 required_photos INT NOT NULL DEFAULT 0,
 requires_text TINYINT(1) NOT NULL DEFAULT 0,
 requires_checkbox TINYINT(1) NOT NULL DEFAULT 0,
 is_dispatch_step TINYINT(1) NOT NULL DEFAULT 0,
 deadline_hours INT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE,
 INDEX(offer_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_shipping_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 source_step_id BIGINT UNSIGNED NULL,
 sort_order INT NOT NULL DEFAULT 0,
 title VARCHAR(190) NOT NULL,
 instructions TEXT NULL,
 required_photos INT NOT NULL DEFAULT 0,
 requires_text TINYINT(1) NOT NULL DEFAULT 0,
 requires_checkbox TINYINT(1) NOT NULL DEFAULT 0,
 is_dispatch_step TINYINT(1) NOT NULL DEFAULT 0,
 deadline_hours INT NULL,
 due_at DATETIME NULL,
 status ENUM('locked','open','completed') NOT NULL DEFAULT 'locked',
 submission_json JSON NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(source_step_id) REFERENCES offer_shipping_steps(id) ON DELETE SET NULL,
 INDEX(order_id,sort_order,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 tracking_number VARCHAR(190) NULL,
 carrier VARCHAR(120) NULL,
 claimed_shipping_cost DECIMAL(10,2) NULL,
 approved_reimbursement DECIMAL(10,2) NULL,
 proof_evidence_id BIGINT UNSIGNED NULL,
 status ENUM('preparing','shipped','received','review') NOT NULL DEFAULT 'preparing',
 shipped_at DATETIME NULL,
 received_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rights_acceptances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 seller_id BIGINT UNSIGNED NOT NULL,
 terms_version VARCHAR(80) NOT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 payload_json JSON NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(seller_id) REFERENCES sellers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id BIGINT UNSIGNED NULL,
 admin_id BIGINT UNSIGNED NULL,
 notification_type VARCHAR(100) NOT NULL,
 title VARCHAR(190) NOT NULL,
 body TEXT NULL,
 link VARCHAR(255) NULL,
 dedupe_key VARCHAR(190) NULL UNIQUE,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(seller_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS migrations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 migration VARCHAR(190) NOT NULL UNIQUE,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
