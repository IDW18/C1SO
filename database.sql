

CREATE DATABASE IF NOT EXISTS c1so_tech
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE c1so_tech;

-- ---------------------------------------------------------
-- 1. USERS (Admin + Staff accounts)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100)    NOT NULL,
  username      VARCHAR(50)     NOT NULL UNIQUE,
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('superadmin','admin','staff') NOT NULL DEFAULT 'staff',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 2. CATEGORIES (Work log task categories - admin/staff can add)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 3. WORK LOG ENTRIES (daily task log, ported from the old localStorage version)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_date     DATE NOT NULL,
  log_time     VARCHAR(20)  NOT NULL,
  item_name    VARCHAR(150) DEFAULT NULL,
  description  TEXT NOT NULL,
  category_id  INT UNSIGNED DEFAULT NULL,
  status       ENUM('done','ongoing','pending') NOT NULL DEFAULT 'done',
  remarks      ENUM('ok','replace') NOT NULL DEFAULT 'ok',
  requester    VARCHAR(150) DEFAULT NULL,
  notes        TEXT DEFAULT NULL,
  note_color   ENUM('none','red','green','orange') NOT NULL DEFAULT 'none',
  created_by   INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_worklog_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_worklog_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Photos attached to a work log entry (stored as files on disk, path saved here)
CREATE TABLE IF NOT EXISTS work_log_photos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_id      INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_log FOREIGN KEY (log_id) REFERENCES work_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 4. INVENTORY: item categories/types (admin-defined, e.g. "Projector", "PC Parts")
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_itemtype_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5. INVENTORY ITEMS
--    An item is now identified by NAME + KIND (category), not by a
--    property number. Property numbers are entered manually per
--    deployment instead (see deployments table below), since a batch
--    of "Epson Projector" might contain several individually-tagged units.
--    quantity_total is a running total kept in sync by inventory_batches.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_name        VARCHAR(150) NOT NULL,
  item_type_id     INT UNSIGNED NOT NULL,
  quantity_total   INT UNSIGNED NOT NULL DEFAULT 0,
  low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
  date_added       DATE NOT NULL,
  created_by       INT UNSIGNED NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_type FOREIGN KEY (item_type_id) REFERENCES item_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_item_name_type (item_name, item_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5b. INVENTORY BATCHES
--    Every time stock is added to an existing item (a restock), a new
--    row is logged here with its own date and quantity, so a full
--    "10 units added Aug 1, 5 more added Sep 3" history is kept.
--    The very first stock-in for a new item also creates a batch row.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_batches (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id      INT UNSIGNED NOT NULL,
  quantity     INT UNSIGNED NOT NULL,
  batch_date   DATE NOT NULL,
  batch_time   TIME DEFAULT NULL,
  notes        VARCHAR(1000) DEFAULT NULL,
  pn_number    VARCHAR(100) DEFAULT NULL,
  po_number    VARCHAR(100) DEFAULT NULL,
  added_by     INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_batch_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5b2. INVENTORY BATCH SERIALS
--    One row per physical unit in a restock batch, holding that unit's
--    Serial Number. A batch of qty 3 gets 3 serial rows (serials may be
--    blank if not provided). Lets the multi-item Add/Restock form record a
--    distinct serial number per unit, for every item row in one delivery
--    (all rows in that delivery share the same PN Number + PO Number).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_batch_serials (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id      INT UNSIGNED NOT NULL,
  serial_number VARCHAR(150) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batchserial_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5c. INVENTORY REMARKS
--    Quick standalone remarks logged against an item from the "Remarks"
--    button on the Inventory Items list (replaces the old per-row
--    Restock/Edit/Delete action buttons).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_remarks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id     INT UNSIGNED NOT NULL,
  remark      VARCHAR(400) NOT NULL,
  remark_date DATE NOT NULL,
  added_by    INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_remark_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_remark_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5d. INVENTORY DISPOSAL FLAGS
--    Every time an item has had no activity (no new batch, no deployment,
--    no return) for 30+ days, it is automatically flagged here for
--    disposal review. Builds a running history shown on the Reports page.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_disposal_flags (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id            INT UNSIGNED NOT NULL,
  flagged_date       DATE NOT NULL,
  last_activity_date DATE DEFAULT NULL,
  days_inactive       INT UNSIGNED NOT NULL,
  status             ENUM('pending','reviewed','disposed') NOT NULL DEFAULT 'pending',
  reviewed_by        INT UNSIGNED DEFAULT NULL,
  reviewed_at        DATETIME DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_disposal_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_disposal_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 6. DEPARTMENTS (where items get deployed to)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 6b. DEPARTMENT PEOPLE (faculty/staff list per department)
--    A simple list of names per department — NOT login accounts, just who
--    a deployed item was handed to. Used by the Deployment wizard's
--    "Select/Add Faculty or Staff" step.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS department_people (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id  INT UNSIGNED NOT NULL,
  name           VARCHAR(150) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_deptperson_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_dept_person (department_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7. DEPLOYMENTS (deployment / return history of inventory items)
--    quantity_deployed lets you deploy part of a bulk item (e.g. 5 of 20 cables)
--    property_number is entered manually here per deployment (e.g. the
--    physical asset tag of the specific unit being sent out), since the
--    item record itself no longer carries a single property number.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS deployments (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id            INT UNSIGNED NOT NULL,
  department_id      INT UNSIGNED NOT NULL,
  recipient_name     VARCHAR(150) DEFAULT NULL,
  property_number    VARCHAR(100) DEFAULT NULL,
  po_number          VARCHAR(100) DEFAULT NULL,
  pn_number          VARCHAR(100) DEFAULT NULL,
  mr_number          VARCHAR(100) DEFAULT NULL,
  quantity_deployed  INT UNSIGNED NOT NULL DEFAULT 1,
  deployment_type    ENUM('new','replacement','recondition','pullout') NOT NULL DEFAULT 'new',
  replaces_deployment_id INT UNSIGNED DEFAULT NULL,
  deployed_date      DATE NOT NULL,
  deployed_time      TIME DEFAULT NULL,
  returned_date      DATE DEFAULT NULL,
  returned_time      TIME DEFAULT NULL,
  remarks            VARCHAR(255) DEFAULT NULL,
  item_details       TEXT DEFAULT NULL,
  deployed_by        INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_deploy_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_deploy_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_deploy_user FOREIGN KEY (deployed_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_deploy_replaces FOREIGN KEY (replaces_deployment_id) REFERENCES deployments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7b. DEPLOYMENT BATCH CONSUMPTION (FIFO traceability)
--    When an item is deployed, its quantity is drawn from the OLDEST
--    batch(es) first (FIFO). This table records which batch(es) — and
--    therefore which PO/Receipt Number and date — each deployment's units
--    were pulled from. Tracking/traceability only; does not change any
--    quantity totals. If a deployment is later returned, that stock
--    becomes available again from its original batch, while this
--    historical record of where it came from is kept.
--    (Placed here, AFTER both inventory_batches and deployments exist,
--    since it has a foreign key into each of them.)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS deployment_batch_consumption (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id  INT UNSIGNED NOT NULL,
  batch_id       INT UNSIGNED NOT NULL,
  quantity       INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dbc_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE,
  CONSTRAINT fk_dbc_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7c. DEPLOYMENT SPECS (superseded — see 7d below)
--    Fixed-field unit specifications (Casing, Processor, Motherboard, RAM,
--    SSD, PSU, Note) for a deployed unit — mirrors the old Excel per-unit
--    spec sheet. Optional: a deployment can have zero or one row here.
--    NOTE: keyboard_mouse/monitor columns are kept for backward
--    compatibility with earlier installs, but are no longer written to —
--    Keyboard, Mouse, and Monitor are now separate, stock-linked items
--    selected via the deployment cart (see the "deployments" table),
--    each deducting from its own inventory the same as any other item.
--    SUPERSEDED by deployment_spec_values (7e) below, which supports a
--    different Specifications field set per Kind of Item (PC/Laptop/
--    Projector/etc). Left in place only so specs saved under earlier
--    installs aren't lost; no longer written to by the app.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS deployment_specs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id  INT UNSIGNED NOT NULL,
  casing         VARCHAR(255) DEFAULT NULL,
  processor      VARCHAR(255) DEFAULT NULL,
  motherboard    VARCHAR(255) DEFAULT NULL,
  ram            VARCHAR(255) DEFAULT NULL,
  ssd            VARCHAR(255) DEFAULT NULL,
  psu            VARCHAR(255) DEFAULT NULL,
  keyboard_mouse VARCHAR(255) DEFAULT NULL,
  monitor        VARCHAR(255) DEFAULT NULL,
  spec_note      TEXT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_deployment (deployment_id),
  CONSTRAINT fk_specs_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7d. DEPLOYMENT SPEC VALUES (flexible, per Kind of Item)
--    One row per Specifications field per deployment — replaces the old
--    fixed-column "deployment_specs" approach so each Kind of Item can have
--    its own field set (PC: Casing/Processor/Motherboard/RAM/SSD/PSU/GPU/
--    Note; Laptop: Brand/Model/Processor/Storage; Projector: Brand/Class/
--    Remote) without a rigid column per possible field across every kind.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS deployment_spec_values (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deployment_id INT UNSIGNED NOT NULL,
  spec_key      VARCHAR(60) NOT NULL,
  spec_value    VARCHAR(255) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_deploy_spec (deployment_id, spec_key),
  CONSTRAINT fk_dspecv_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7e. INVENTORY ITEM SPEC VALUES (preset specs per model)
--    Same flexible key/value shape as deployment_spec_values, but scoped to
--    an inventory item (model) instead of a deployment. Filled in on the
--    Add/Restock form as that model's default specs — auto-fills into the
--    Deployment wizard's Specifications fields when that model is picked
--    (still editable there before submitting).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_item_spec_values (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id    INT UNSIGNED NOT NULL,
  spec_key   VARCHAR(60) NOT NULL,
  spec_value VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_spec (item_id, spec_key),
  CONSTRAINT fk_ispecv_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id   INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  body        TEXT NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_msg_recipient_read (recipient_id, is_read),
  INDEX idx_msg_conversation (sender_id, recipient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9. ACTIVITY LOG (visible to everyone - admin + staff actions, always shown)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  actor_name  VARCHAR(100) NOT NULL,
  actor_role  ENUM('superadmin','admin','staff') NOT NULL,
  action      VARCHAR(80) NOT NULL,
  details     VARCHAR(400) DEFAULT NULL,
  module      VARCHAR(30) NOT NULL DEFAULT 'other',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- SEED DATA
-- =========================================================

-- Default admin account
--   username: admin
--   password: admin123   <-- CHANGE THIS after first login (top-right menu > Change Password)
--   The password hash below is generated automatically by setup.php the
--   first time you open the site in your browser, so it always matches
--   your exact PHP/MySQL version. If the row does not exist yet, setup.php
--   creates it; if it exists with a blank/invalid hash, setup.php fixes it.
INSERT IGNORE INTO users (full_name, username, password_hash, role, status)
VALUES ('System Administrator', 'admin', '', 'admin', 'active');

-- Starter work-log categories
INSERT IGNORE INTO categories (name) VALUES
('Network'),('Hardware'),('Software'),('System Admin'),('Troubleshooting'),
('Maintenance'),('User Support'),('Installation'),('Documentation'),('Meeting'),('Other');

-- Starter departments (admin/staff can add more from the app)
INSERT IGNORE INTO departments (name) VALUES
('Registrar''s Office'),('Admin Office'),('Computer Lab 1'),('Computer Lab 2'),('Faculty Room');

-- NOTE: Inventory item types are NOT seeded — the admin defines these
-- entirely from scratch inside the app (Inventory > Manage Item Types),
-- as requested (e.g. "Projector", "PC Parts", etc).
