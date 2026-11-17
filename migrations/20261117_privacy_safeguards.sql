-- Support-access grants and the sensitive-data access log.
-- Does not encrypt bank accounts or SINs and does not change payroll rows.
-- Safe to run more than once. Run after a backup, before using support access.
-- Encrypt existing bank and SIN values separately with:
--   php ops/encrypt_sensitive_columns.php
--   php ops/encrypt_sensitive_columns.php --apply

CREATE TABLE IF NOT EXISTS privacy_support_grants (
  id INT NOT NULL AUTO_INCREMENT,
  org_id INT NOT NULL,
  granted_by INT NOT NULL,
  reason VARCHAR(500) NOT NULL,
  starts_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by INT NULL,
  owner_notified_at DATETIME NULL,
  notify_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_privacy_grant_org (org_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_access_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  actor_id INT NOT NULL,
  actor_level INT NOT NULL,
  org_id INT NOT NULL,
  action VARCHAR(32) NOT NULL,
  resource VARCHAR(128) NOT NULL,
  grant_id INT NULL,
  ip VARCHAR(64) NULL,
  note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_privacy_log_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
