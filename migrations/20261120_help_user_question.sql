-- Questions a signed-in person asks from a payroll page.
-- Safe to run more than once. Does not change help_qa or payroll rows.
-- Run on the payroll database after a backup. This file does not deploy itself.

CREATE TABLE IF NOT EXISTS help_user_question (
  id INT AUTO_INCREMENT PRIMARY KEY,
  app VARCHAR(32) NOT NULL DEFAULT 'payroll',
  page_key VARCHAR(191) NOT NULL,
  org_id INT NULL,
  question VARCHAR(500) NOT NULL,
  asked_by VARCHAR(191) NOT NULL,
  status ENUM('new','answered','dismissed') NOT NULL DEFAULT 'new',
  help_qa_id INT NULL,
  handled_by VARCHAR(191) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_help_user_question_app_status (app, status),
  KEY idx_help_user_question_page (page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
