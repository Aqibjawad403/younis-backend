-- Users table for CRM login (per-tenant DB)
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seeded automatically by backend (per database / tenant):
--   Main  (younissonsindustry.com)          → younis@gmail.com  / younis123@
--   Factory (factory.younissonsindustry.com) → factory@gmail.com / factory123@
--   Pre     (pre.younissonsindustry.com)     → younis@gmail.com  / younis123@
