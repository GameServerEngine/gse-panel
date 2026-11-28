-- Operator-reviewed envelopes. Empty tables keep public placement closed.
CREATE TABLE IF NOT EXISTS DB_PREFIXplacement_host (
  remote_server_id INT NOT NULL PRIMARY KEY,
  purpose VARCHAR(16) NOT NULL DEFAULT 'profiling',
  calibration_json LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS DB_PREFIXplacement_profile (
  offer_id INT NOT NULL PRIMARY KEY,
  release_build VARCHAR(128) NOT NULL,
  release_checked_at BIGINT NOT NULL,
  profile_json LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS DB_PREFIXplacement_hold (
  invoice_id INT NOT NULL PRIMARY KEY,
  remote_server_id INT NOT NULL,
  offer_id INT NOT NULL,
  slots INT NOT NULL,
  expires_at BIGINT NOT NULL,
  demand_json LONGTEXT NOT NULL,
  KEY host_expiry (remote_server_id, expires_at)
) ENGINE=InnoDB;
ALTER TABLE DB_PREFIXstatus_sample ADD COLUMN resource_json LONGTEXT NULL;
ALTER TABLE DB_PREFIXgame_sample ADD COLUMN resource_json LONGTEXT NULL;
