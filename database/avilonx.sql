-- AvilonX Clocking: MySQL / MariaDB database (XAMPP or PHP hosting).
--
-- Import once in phpMyAdmin: create a database (e.g. "avilonx", collation
-- utf8mb4_general_ci), open it, Import → this file. Then open the site: the
-- first page asks you to create the Super Admin account.
-- After an AvilonX update, import this file again: it only adds what is new
-- (every table is "IF NOT EXISTS") and keeps your data.
--
-- All times are stored in UTC (the API sets the connection time zone to
-- +00:00). Works with MariaDB 10.4+ (XAMPP) and MySQL 8.0.16+.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Accounts ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clubs (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  rfid_entry_sticker VARCHAR(10) NOT NULL DEFAULT 'required',
  form_member_boxes INT NOT NULL DEFAULT 5,
  form_prefix_boxes INT NOT NULL DEFAULT 6,
  form_number_boxes INT NOT NULL DEFAULT 8,
  single_device TINYINT(1) NOT NULL DEFAULT 0,
  logo_path VARCHAR(300) NULL,
  UNIQUE KEY clubs_name_unique (name),
  CONSTRAINT clubs_sticker_rule CHECK (rfid_entry_sticker IN ('required', 'optional')),
  CONSTRAINT clubs_form_boxes CHECK (form_member_boxes BETWEEN 1 AND 8 AND form_prefix_boxes BETWEEN 0 AND 8 AND form_number_boxes BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sign-in: one row per account (passwords as bcrypt hashes).
CREATE TABLE IF NOT EXISTS auth_users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  session_version INT NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS members (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_no VARCHAR(40) NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  first_name VARCHAR(180) NULL,
  middle_name VARCHAR(180) NULL,
  last_name VARCHAR(180) NULL,
  loft_name VARCHAR(180) NOT NULL,
  address VARCHAR(500) NULL,
  phone VARCHAR(40) NULL,
  paid SMALLINT NOT NULL DEFAULT 0,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  club_id BIGINT NOT NULL,
  email VARCHAR(254) NULL,
  email_confirmed_at DATETIME(6) NULL,
  telegram_chat_id BIGINT NULL,
  telegram_linked_at DATETIME(6) NULL,
  telegram_link_code VARCHAR(64) NULL,
  telegram_link_expires_at DATETIME(6) NULL,
  UNIQUE KEY members_club_member_no (club_id, member_no),
  UNIQUE KEY members_email_unique (email),
  CONSTRAINT members_paid CHECK (paid IN (0, 1)),
  CONSTRAINT members_latitude CHECK (latitude BETWEEN -90 AND 90),
  CONSTRAINT members_longitude CHECK (longitude BETWEEN -180 AND 180),
  CONSTRAINT members_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS app_profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  username VARCHAR(32) NULL,
  role VARCHAR(12) NOT NULL,
  member_id BIGINT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  club_id BIGINT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email VARCHAR(254) NULL,
  email_confirmed_at DATETIME(6) NULL,
  UNIQUE KEY app_profiles_username (username),
  UNIQUE KEY app_profiles_member (member_id),
  UNIQUE KEY app_profiles_email_unique (email),
  CONSTRAINT app_profiles_role CHECK (
    (role = 'superadmin' AND member_id IS NULL AND club_id IS NULL) OR
    (role IN ('admin', 'staff') AND member_id IS NULL AND club_id IS NOT NULL) OR
    (role = 'member' AND member_id IS NOT NULL AND club_id IS NOT NULL)),
  CONSTRAINT app_profiles_user FOREIGN KEY (id) REFERENCES auth_users(id) ON DELETE CASCADE,
  CONSTRAINT app_profiles_club FOREIGN KEY (club_id) REFERENCES clubs(id),
  CONSTRAINT app_profiles_member_fk FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One device per account (Club settings).
CREATE TABLE IF NOT EXISTS account_devices (
  user_id CHAR(36) NOT NULL PRIMARY KEY,
  device_id VARCHAR(80) NOT NULL,
  device_label VARCHAR(80) NOT NULL DEFAULT '',
  signed_in_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Password reset and email confirmation links (stored as SHA-256 hashes).
CREATE TABLE IF NOT EXISTS account_email_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT NULL,
  user_id CHAR(36) NULL,
  kind VARCHAR(10) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  email VARCHAR(254) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  UNIQUE KEY account_email_tokens_hash (token_hash),
  KEY account_email_tokens_member (member_id, kind, created_at),
  KEY account_email_tokens_user (user_id, kind, created_at),
  CONSTRAINT account_email_tokens_kind CHECK (kind IN ('reset', 'confirm')),
  CONSTRAINT account_email_tokens_member_fk FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT account_email_tokens_user_fk FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Races ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS race_points (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  sector VARCHAR(100) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  club_id BIGINT NOT NULL,
  CONSTRAINT race_points_latitude CHECK (latitude BETWEEN -90 AND 90),
  CONSTRAINT race_points_longitude CHECK (longitude BETWEEN -180 AND 180),
  CONSTRAINT race_points_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS races (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  race_point_id BIGINT NULL,
  release_location VARCHAR(180) NOT NULL,
  release_latitude DECIMAL(10,7) NOT NULL,
  release_longitude DECIMAL(10,7) NOT NULL,
  open_date DATE NULL,
  end_date DATE NULL,
  release_at_utc DATETIME(6) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  club_id BIGINT NOT NULL,
  is_practice TINYINT(1) NOT NULL DEFAULT 0,
  requires_show_up TINYINT(1) NOT NULL DEFAULT 1,
  nfc_expiry_days INT NULL,
  lap SMALLINT NULL,
  CONSTRAINT races_lap_range CHECK (lap IS NULL OR lap BETWEEN 1 AND 99),
  CONSTRAINT races_status CHECK (status IN ('draft', 'open', 'closed')),
  CONSTRAINT races_open_requires_release_time CHECK (status <> 'open' OR release_at_utc IS NOT NULL),
  CONSTRAINT races_dates CHECK ((open_date IS NULL AND end_date IS NULL) OR (open_date IS NOT NULL AND end_date IS NOT NULL AND end_date >= open_date)),
  CONSTRAINT races_expiry CHECK (nfc_expiry_days IS NULL OR nfc_expiry_days BETWEEN 1 AND 365),
  CONSTRAINT races_latitude CHECK (release_latitude BETWEEN -90 AND 90),
  CONSTRAINT races_longitude CHECK (release_longitude BETWEEN -180 AND 180),
  CONSTRAINT races_club FOREIGN KEY (club_id) REFERENCES clubs(id),
  CONSTRAINT races_point FOREIGN KEY (race_point_id) REFERENCES race_points(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Birds, tags and stickers ----------------------------------------------------
CREATE TABLE IF NOT EXISTS nfc_tags (
  tag_uid VARCHAR(20) NOT NULL PRIMARY KEY,
  source VARCHAR(10) NOT NULL,
  created_by CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT nfc_tags_source CHECK (source IN ('manual', 'legacy'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS barcode_stickers (
  barcode_number VARCHAR(120) NOT NULL PRIMARY KEY,
  source VARCHAR(10) NOT NULL,
  created_by CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  scratch_code VARCHAR(120) NULL,
  has_scratch_code TINYINT(1) GENERATED ALWAYS AS (scratch_code IS NOT NULL) STORED,
  is_registered TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT barcode_stickers_source CHECK (source IN ('generated', 'manual', 'legacy'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS barcode_stickers_archive (
  barcode_number VARCHAR(120) NOT NULL,
  scratch_code VARCHAR(120) NULL,
  source VARCHAR(10) NOT NULL,
  is_registered TINYINT(1) NOT NULL,
  created_by CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL,
  archived_by CHAR(36) NULL,
  archived_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pigeons (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id BIGINT NOT NULL,
  member_id BIGINT NOT NULL,
  ring_number VARCHAR(80) NOT NULL,
  bird_name VARCHAR(80) NOT NULL,
  color VARCHAR(40) NOT NULL,
  loft_name VARCHAR(120) NOT NULL,
  sex VARCHAR(4) NULL,
  hatch_year INT NULL,
  nfc_tag VARCHAR(20) NULL,
  nfc_read_with VARCHAR(10) NULL,
  photo_path VARCHAR(300) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  created_by CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  bond_type VARCHAR(4) NOT NULL DEFAULT 'rfid',
  active_nfc_tag VARCHAR(20) GENERATED ALWAYS AS (IF(status = 'active', nfc_tag, NULL)) STORED,
  UNIQUE KEY pigeons_club_ring_key (club_id, ring_number),
  UNIQUE KEY pigeons_active_nfc_tag_key (active_nfc_tag),
  CONSTRAINT pigeons_bond_type CHECK (bond_type IN ('rfid', 'ring')),
  CONSTRAINT pigeons_status CHECK (status IN ('active', 'retired')),
  CONSTRAINT pigeons_sex CHECK (sex IS NULL OR sex IN ('cock', 'hen')),
  CONSTRAINT pigeons_hatch_year CHECK (hatch_year IS NULL OR hatch_year BETWEEN 1990 AND 2100),
  CONSTRAINT pigeons_read_with CHECK (nfc_read_with IS NULL OR nfc_read_with IN ('wireless', 'wired', 'phone')),
  CONSTRAINT pigeons_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT pigeons_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS entry_remark_options (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id BIGINT NOT NULL,
  label VARCHAR(60) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY entry_remark_options_club_label_key (club_id, label),
  CONSTRAINT entry_remark_options_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Entries and clockings --------------------------------------------------------
CREATE TABLE IF NOT EXISTS race_entries (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  race_id BIGINT NOT NULL,
  member_id BIGINT NOT NULL,
  pigeon_ring VARCHAR(80) NULL,
  barcode VARCHAR(120) NULL,
  pooling SMALLINT NOT NULL DEFAULT 0,
  category VARCHAR(100) NOT NULL DEFAULT 'Open Category',
  race_type VARCHAR(100) NOT NULL DEFAULT 'Regular Race/Set',
  distance_m DECIMAL(14,3) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  nfc_tag VARCHAR(20) NULL,
  nfc_expires_at DATETIME(6) NULL,
  nfc_read_with VARCHAR(10) NULL,
  remarks VARCHAR(2000) NOT NULL DEFAULT '[]',
  pigeon_id BIGINT NULL,
  loft_latitude DECIMAL(10,7) NULL,
  loft_longitude DECIMAL(10,7) NULL,
  UNIQUE KEY unique_race_ring (race_id, pigeon_ring),
  UNIQUE KEY unique_race_barcode (race_id, barcode),
  UNIQUE KEY race_entries_race_nfc_tag_key (race_id, nfc_tag),
  UNIQUE KEY race_entries_race_pigeon_key (race_id, pigeon_id),
  CONSTRAINT race_entries_pooling CHECK (pooling IN (0, 1)),
  CONSTRAINT race_entries_distance CHECK (distance_m >= 0),
  CONSTRAINT race_entries_read_with CHECK (nfc_read_with IS NULL OR nfc_read_with IN ('wireless', 'wired', 'phone')),
  CONSTRAINT race_entries_race FOREIGN KEY (race_id) REFERENCES races(id),
  CONSTRAINT race_entries_member FOREIGN KEY (member_id) REFERENCES members(id),
  CONSTRAINT race_entries_pigeon FOREIGN KEY (pigeon_id) REFERENCES pigeons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS clock_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  race_entry_id BIGINT NOT NULL,
  pigeon_ring VARCHAR(80) NOT NULL,
  received_at_utc DATETIME(6) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'admin_manual',
  verification VARCHAR(20) NOT NULL DEFAULT 'provisional',
  created_by_admin_id BIGINT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  server_received_at_utc DATETIME(6) NULL,
  time_source VARCHAR(10) NOT NULL DEFAULT 'server',
  shown_up_at DATETIME(6) NULL,
  shown_up_by CHAR(36) NULL,
  shown_up_with VARCHAR(40) NULL,
  UNIQUE KEY unique_pigeon_clock (race_entry_id),
  CONSTRAINT clock_events_verification CHECK (verification IN ('provisional', 'verified', 'rejected')),
  CONSTRAINT clock_events_source CHECK (source IN ('admin_manual', 'barcode', 'nfc')),
  CONSTRAINT clock_events_time_source CHECK (time_source IN ('server', 'reader')),
  CONSTRAINT clock_events_entry FOREIGN KEY (race_entry_id) REFERENCES race_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS member_clock_attempts (
  member_id BIGINT NOT NULL,
  race_id BIGINT NOT NULL,
  failed INT NOT NULL DEFAULT 0,
  last_failed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (member_id, race_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS clock_videos (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clock_event_id BIGINT NOT NULL,
  member_id BIGINT NOT NULL,
  club_id BIGINT NOT NULL,
  storage_path VARCHAR(300) NOT NULL,
  duration_seconds DECIMAL(8,2) NULL,
  size_bytes BIGINT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY clock_videos_clock_event_id_key (clock_event_id),
  CONSTRAINT clock_videos_clock FOREIGN KEY (clock_event_id) REFERENCES clock_events(id) ON DELETE CASCADE,
  CONSTRAINT clock_videos_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Loft RFID readers -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rfid_devices (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id BIGINT NOT NULL,
  serial VARCHAR(12) NOT NULL,
  name VARCHAR(80) NOT NULL,
  member_id BIGINT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'register',
  key_hash CHAR(64) NOT NULL,
  last_seen_at DATETIME(6) NULL,
  last_tag VARCHAR(20) NULL,
  created_by CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_heartbeat_at DATETIME(6) NULL,
  pending_taps INT NOT NULL DEFAULT 0,
  clock_source VARCHAR(20) NULL,
  firmware VARCHAR(40) NULL,
  UNIQUE KEY rfid_devices_serial_key (serial),
  CONSTRAINT rfid_devices_mode CHECK (mode IN ('register', 'clock')),
  CONSTRAINT rfid_devices_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT rfid_devices_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS rfid_device_scans (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT NOT NULL,
  tag_uid VARCHAR(20) NOT NULL,
  mode VARCHAR(10) NOT NULL,
  result VARCHAR(200) NULL,
  scanned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY rfid_device_scans_device (device_id, scanned_at),
  CONSTRAINT rfid_device_scans_device FOREIGN KEY (device_id) REFERENCES rfid_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Telegram ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telegram_settings (
  id INT NOT NULL PRIMARY KEY DEFAULT 1,
  bot_username VARCHAR(40) NOT NULL DEFAULT '',
  function_url VARCHAR(300) NOT NULL DEFAULT '',
  secret VARCHAR(80) NOT NULL DEFAULT '',
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT telegram_settings_one CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS telegram_outbox (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT NULL,
  chat_id BIGINT NOT NULL,
  text TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at DATETIME(6) NULL,
  error TEXT NULL,
  CONSTRAINT telegram_outbox_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Change history ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id BIGINT NULL,
  table_name VARCHAR(40) NOT NULL,
  row_id BIGINT NULL,
  action VARCHAR(10) NOT NULL,
  changed_by CHAR(36) NULL,
  changed_by_name VARCHAR(80) NULL,
  changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  old_data LONGTEXT NULL,
  new_data LONGTEXT NULL,
  KEY idx_audit_log_club_time (club_id, changed_at),
  CONSTRAINT audit_log_action CHECK (action IN ('insert', 'update', 'delete')),
  CONSTRAINT audit_log_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Landing page: club requests --------------------------------------------------------
CREATE TABLE IF NOT EXISTS club_requests (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_name VARCHAR(120) NOT NULL,
  club_name VARCHAR(180) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL DEFAULT '',
  message VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  handled_at DATETIME(6) NULL,
  KEY club_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- App error reports (Super Admin → Clubs → App errors) ------------------------------
CREATE TABLE IF NOT EXISTS client_errors (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(500) NOT NULL,
  source VARCHAR(300) NOT NULL DEFAULT '',
  page VARCHAR(200) NOT NULL DEFAULT '',
  user_agent VARCHAR(300) NOT NULL DEFAULT '',
  app_version VARCHAR(40) NOT NULL DEFAULT '',
  user_id CHAR(36) NULL,
  club_id BIGINT NULL,
  count INT NOT NULL DEFAULT 1,
  first_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_client_errors_last (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Push notifications (Web Push) -------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_settings (
  id INT NOT NULL PRIMARY KEY DEFAULT 1,
  vapid_public VARCHAR(120) NOT NULL,
  vapid_private TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT push_settings_one CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) GENERATED ALWAYS AS (SHA2(endpoint, 256)) STORED,
  p256dh VARCHAR(200) NOT NULL,
  auth VARCHAR(100) NOT NULL,
  device VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY push_subscriptions_endpoint (endpoint_hash),
  KEY idx_push_subscriptions_user (user_id),
  CONSTRAINT push_subscriptions_user FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS push_outbox (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(400) NOT NULL,
  url VARCHAR(300) NOT NULL DEFAULT '',
  tag VARCHAR(60) NOT NULL DEFAULT '',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at DATETIME(6) NULL,
  error VARCHAR(300) NULL,
  KEY idx_push_outbox_created (created_at),
  CONSTRAINT push_outbox_user FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
