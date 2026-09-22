<?php
declare(strict_types=1);

function migration_applied(PDO $pdo, string $key): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration_key=?');
    $q->execute([$key]);
    return (int)$q->fetchColumn()>0;
}
function mark_migration(PDO $pdo, string $key): void {
    $pdo->prepare('INSERT INTO schema_migrations(migration_key) VALUES(?)')->execute([$key]);
}
function column_exists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$table,$column]);
    return (int)$q->fetchColumn()>0;
}
function index_exists(PDO $pdo,string $table,string $index): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $q->execute([$table,$index]);
    return (int)$q->fetchColumn()>0;
}
function fk_exists(PDO $pdo,string $table,string $constraint): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?");
    $q->execute([$table,$constraint]);
    return (int)$q->fetchColumn()>0;
}

function run_migrations(): void {
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(120) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $key='20260922_01_day_events';
    if(!migration_applied($pdo,$key)){
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_day_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            day_id BIGINT UNSIGNED NOT NULL,
            event_no INT NOT NULL,
            label VARCHAR(80) NOT NULL,
            status ENUM('planned','submitted') NOT NULL DEFAULT 'planned',
            seller_note TEXT NULL,
            submitted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY(day_id) REFERENCES order_days(id) ON DELETE CASCADE,
            UNIQUE(day_id,event_no),
            INDEX(day_id,status,event_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if(!column_exists($pdo,'day_uploads','event_id')){
            $pdo->exec("ALTER TABLE day_uploads ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER day_id");
        }
        if(!index_exists($pdo,'day_uploads','idx_day_uploads_event')){
            $pdo->exec("ALTER TABLE day_uploads ADD INDEX idx_day_uploads_event(event_id)");
        }
        if(!fk_exists($pdo,'day_uploads','fk_day_uploads_event')){
            $pdo->exec("ALTER TABLE day_uploads ADD CONSTRAINT fk_day_uploads_event FOREIGN KEY(event_id) REFERENCES order_day_events(id) ON DELETE CASCADE");
        }
        mark_migration($pdo,$key);
    }

    $key='20260922_02_wallet_shipping_windows';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'offer_positions','daily_event_windows_json')){
            $pdo->exec("ALTER TABLE offer_positions ADD COLUMN daily_event_windows_json LONGTEXT NULL AFTER daily_instructions");
        }
        if(!column_exists($pdo,'orders','daily_event_windows_json')){
            $pdo->exec("ALTER TABLE orders ADD COLUMN daily_event_windows_json LONGTEXT NULL AFTER daily_instructions");
        }
        if(!column_exists($pdo,'order_day_events','window_start')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN window_start TIME NULL AFTER label");
        }
        if(!column_exists($pdo,'order_day_events','window_end')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN window_end TIME NULL AFTER window_start");
        }
        if(!column_exists($pdo,'order_day_events','all_day')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0 AFTER window_end");
        }

        $pdo->exec("ALTER TABLE orders MODIFY status ENUM('precheck','running','shipping','completed','cancelled') NOT NULL DEFAULT 'precheck'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_payout_profiles (
            seller_id BIGINT UNSIGNED PRIMARY KEY,
            payout_method ENUM('paypal','bank') NULL,
            paypal_email VARCHAR(190) NULL,
            bank_holder VARCHAR(190) NULL,
            bank_iban VARCHAR(80) NULL,
            bank_bic VARCHAR(40) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY(seller_id) REFERENCES sellers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_wallet_entries (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS order_shipments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL UNIQUE,
            due_date DATE NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("UPDATE order_day_events SET window_start='06:00:00',window_end='12:00:00',all_day=0 WHERE label='Morgens' AND window_start IS NULL");
        $pdo->exec("UPDATE order_day_events SET window_start='13:00:00',window_end='16:00:00',all_day=0 WHERE label='Mittags' AND window_start IS NULL");
        $pdo->exec("UPDATE order_day_events SET window_start='15:00:00',window_end='17:00:00',all_day=0 WHERE label='Nachmittags' AND window_start IS NULL");
        $pdo->exec("UPDATE order_day_events SET window_start='18:00:00',window_end='22:00:00',all_day=0 WHERE label='Abends' AND window_start IS NULL");
        $pdo->exec("UPDATE order_day_events SET window_start='00:00:00',window_end='23:59:00',all_day=1 WHERE window_start IS NULL");

        $pdo->exec("INSERT IGNORE INTO seller_wallet_entries(seller_id,order_id,amount,status,available_at)
            SELECT seller_id,id,compensation,
                CASE WHEN status='completed' THEN 'available' ELSE 'reserved' END,
                CASE WHEN status='completed' THEN COALESCE(completed_at,NOW()) ELSE NULL END
            FROM orders");

        mark_migration($pdo,$key);
    }
    $key='20260922_03_final_day_positions_optional_precheck';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'orders','is_final_day_position')){
            $pdo->exec("ALTER TABLE orders ADD COLUMN is_final_day_position TINYINT(1) NOT NULL DEFAULT 0 AFTER extension_days");
        }
        if(!index_exists($pdo,'orders','idx_offer_final_day')){
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_offer_final_day(offer_id,is_final_day_position,status)");
        }

        $pdo->exec("UPDATE orders child
            JOIN (
                SELECT o1.offer_id, MAX(o1.required_success_days) max_days
                FROM orders o1
                GROUP BY o1.offer_id
                HAVING max_days>1
            ) mx ON mx.offer_id=child.offer_id
            SET child.is_final_day_position=1
            WHERE child.required_success_days=1");

        mark_migration($pdo,$key);
    }

    $key='20260922_04_shipping_keyword_default_address';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'order_shipments','address_keyword')){
            $pdo->exec("ALTER TABLE order_shipments ADD COLUMN address_keyword VARCHAR(190) NULL AFTER due_date");
        }

        $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES
            ('shipping.keyword','Suzuki2026'),
            ('shipping.name','Postlagernd'),
            ('shipping.extra','Post Filiale 550'),
            ('shipping.street','Hauptstraße 16'),
            ('shipping.postal_code','35435'),
            ('shipping.city','Wettenberg'),
            ('shipping.country','Deutschland')
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");

        $pdo->exec("UPDATE order_shipments SET
            address_keyword='Suzuki2026',
            address_name='Postlagernd',
            extra='Post Filiale 550',
            street='Hauptstraße 16',
            postal_code='35435',
            city='Wettenberg',
            country='Deutschland',
            updated_at=NOW()
            WHERE status='pending'");

        mark_migration($pdo,$key);
    }

    $key='20260922_05_seller_invitations';
    if(!migration_applied($pdo,$key)){
        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_invitations (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mark_migration($pdo,$key);
    }

    $key='20260922_06_end_aligned_positions';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'offer_positions','align_to_offer_end')){
            $pdo->exec("ALTER TABLE offer_positions ADD COLUMN align_to_offer_end TINYINT(1) NOT NULL DEFAULT 0 AFTER required_success_days");
        }
        if(!column_exists($pdo,'orders','align_to_offer_end')){
            $pdo->exec("ALTER TABLE orders ADD COLUMN align_to_offer_end TINYINT(1) NOT NULL DEFAULT 0 AFTER is_final_day_position");
        }
        if(!index_exists($pdo,'orders','idx_offer_end_aligned')){
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_offer_end_aligned(offer_id,align_to_offer_end,status)");
        }

        $pdo->exec("UPDATE offer_positions SET align_to_offer_end=1 WHERE required_success_days=1");
        $pdo->exec("UPDATE orders SET align_to_offer_end=1 WHERE is_final_day_position=1 OR required_success_days=1");

        mark_migration($pdo,$key);
    }

    $key='20260922_07_synced_base_start';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'offer_positions','sync_start_with_offer')){
            $pdo->exec("ALTER TABLE offer_positions ADD COLUMN sync_start_with_offer TINYINT(1) NOT NULL DEFAULT 0 AFTER align_to_offer_end");
        }
        if(!column_exists($pdo,'orders','sync_start_with_offer')){
            $pdo->exec("ALTER TABLE orders ADD COLUMN sync_start_with_offer TINYINT(1) NOT NULL DEFAULT 0 AFTER align_to_offer_end");
        }
        if(!index_exists($pdo,'orders','idx_offer_synced_start')){
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_offer_synced_start(offer_id,sync_start_with_offer,status)");
        }
        mark_migration($pdo,$key);
    }

    $key='20260922_08_scent_requests';
    if(!migration_applied($pdo,$key)){
        $pdo->exec("CREATE TABLE IF NOT EXISTS scent_requests (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mark_migration($pdo,$key);
    }

    $key='20260922_09_late_evidence_and_override';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'order_days','late_submission_allowed')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN late_submission_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note");
        }
        if(!column_exists($pdo,'order_days','late_submission_note')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN late_submission_note TEXT NULL AFTER late_submission_allowed");
        }
        if(!column_exists($pdo,'order_days','late_submission_requested_at')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN late_submission_requested_at DATETIME NULL AFTER late_submission_note");
        }
        if(!column_exists($pdo,'order_days','late_submission_requested_by')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN late_submission_requested_by BIGINT UNSIGNED NULL AFTER late_submission_requested_at");
        }
        if(!column_exists($pdo,'order_days','fulfilled_by_override')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN fulfilled_by_override TINYINT(1) NOT NULL DEFAULT 0 AFTER late_submission_requested_by");
        }
        mark_migration($pdo,$key);
    }

    $key='20260922_10_operations_center';
    if(!migration_applied($pdo,$key)){
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_role ENUM('admin','seller') NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(80) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NULL,
            target_url VARCHAR(500) NULL,
            dedupe_key VARCHAR(190) NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notification_dedupe(user_role,user_id,dedupe_key),
            INDEX(user_role,user_id,read_at,created_at),
            INDEX(type,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS order_messages (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS payout_batches (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS payout_batch_entries (
            payout_batch_id BIGINT UNSIGNED NOT NULL,
            wallet_entry_id BIGINT UNSIGNED NOT NULL UNIQUE,
            amount DECIMAL(10,2) NOT NULL,
            PRIMARY KEY(payout_batch_id,wallet_entry_id),
            FOREIGN KEY(payout_batch_id) REFERENCES payout_batches(id) ON DELETE CASCADE,
            FOREIGN KEY(wallet_entry_id) REFERENCES seller_wallet_entries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if(!column_exists($pdo,'seller_payout_profiles','paypal_email_enc')){
            $pdo->exec("ALTER TABLE seller_payout_profiles ADD COLUMN paypal_email_enc LONGTEXT NULL AFTER payout_method");
        }
        if(!column_exists($pdo,'seller_payout_profiles','bank_holder_enc')){
            $pdo->exec("ALTER TABLE seller_payout_profiles ADD COLUMN bank_holder_enc LONGTEXT NULL AFTER paypal_email_enc");
        }
        if(!column_exists($pdo,'seller_payout_profiles','bank_iban_enc')){
            $pdo->exec("ALTER TABLE seller_payout_profiles ADD COLUMN bank_iban_enc LONGTEXT NULL AFTER bank_holder_enc");
        }
        if(!column_exists($pdo,'seller_payout_profiles','bank_bic_enc')){
            $pdo->exec("ALTER TABLE seller_payout_profiles ADD COLUMN bank_bic_enc LONGTEXT NULL AFTER bank_iban_enc");
        }

        $rows=$pdo->query("SELECT seller_id,paypal_email,bank_holder,bank_iban,bank_bic FROM seller_payout_profiles")->fetchAll();
        $up=$pdo->prepare("UPDATE seller_payout_profiles SET paypal_email_enc=?,bank_holder_enc=?,bank_iban_enc=?,bank_bic_enc=?,
            paypal_email=NULL,bank_holder=NULL,bank_iban=NULL,bank_bic=NULL WHERE seller_id=?");
        foreach($rows as $row){
            $up->execute([
                !empty($row['paypal_email'])?secure_encrypt((string)$row['paypal_email']):null,
                !empty($row['bank_holder'])?secure_encrypt((string)$row['bank_holder']):null,
                !empty($row['bank_iban'])?secure_encrypt((string)$row['bank_iban']):null,
                !empty($row['bank_bic'])?secure_encrypt((string)$row['bank_bic']):null,
                $row['seller_id']
            ]);
        }

        if(!column_exists($pdo,'order_day_events','review_note')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN review_note TEXT NULL AFTER seller_note");
        }
        if(!column_exists($pdo,'order_day_events','resubmission_requested_at')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN resubmission_requested_at DATETIME NULL AFTER review_note");
        }
        if(!column_exists($pdo,'order_day_events','resubmission_requested_by')){
            $pdo->exec("ALTER TABLE order_day_events ADD COLUMN resubmission_requested_by BIGINT UNSIGNED NULL AFTER resubmission_requested_at");
        }

        mark_migration($pdo,$key);
    }

    $key='20260922_11_manual_extension_marker';
    if(!migration_applied($pdo,$key)){
        if(!column_exists($pdo,'order_days','manual_extension')){
            $pdo->exec("ALTER TABLE order_days ADD COLUMN manual_extension TINYINT(1) NOT NULL DEFAULT 0 AFTER is_extension");
        }
        mark_migration($pdo,$key);
    }

}
