CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_cancellation_requests` (
 request_id INT NOT NULL AUTO_INCREMENT, home_id INT NOT NULL, order_id INT NOT NULL, user_id INT NOT NULL,
 mode ENUM('immediate','end_of_term') NOT NULL, status ENUM('pending','approved','rejected','completed','failed') NOT NULL DEFAULT 'pending',
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL, decided_by INT NULL,
 refund_amount DECIMAL(10,2) NULL, refund_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
 refund_status ENUM('none','pending','refunded','manual_required','failed') NOT NULL DEFAULT 'none',
 refund_txid VARCHAR(255) NULL, note TEXT NULL, PRIMARY KEY(request_id), KEY(home_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `DB_PREFIXbilling_config` ADD COLUMN refund_full_days INT NOT NULL DEFAULT 30;
ALTER TABLE `DB_PREFIXserver_homes` ADD COLUMN cancel_at_expiry TINYINT(1) NOT NULL DEFAULT 0;
