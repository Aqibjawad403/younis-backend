-- ============================================================
-- Younas DB - Complete Database Schema (v3)
-- Import via phpMyAdmin:
--   1. Left sidebar se apni database SELECT karo (u648321227_younas_db)
--   2. Import tab > Choose this file > Go
-- Database: u648321227_younas_db
-- Shared hosting par CREATE DATABASE allowed nahi hai — tables isi selected DB mein banengi.
--
-- Tables (17+):
--   cities, packings, parties, banks, inventory, sales, stock, invoices,
--   payment_receipts, bank_transactions, expense_categories,
--   expenses, outstanding_cheques, sale_returns,
--   purchase_orders, purchase_order_items,
--   delivery_orders, delivery_order_items
--
-- Features included:
--   - cities master list for party city selection
--   - packings master list (name + weight) for inventory packing dropdown
--   - parties.city_id         : Linked city
--   - inventory.packing_id    : Linked packing
--   - parties.opening_balance  : Client opening balance (required on create)
--   - banks.opening_balance    : Bank opening balance
--   - Invoice installment payments (Partial / Received / Deferred status)
--   - payment_receipts.invoice_id links installments to invoices
--   - Advance payments: payment_receipts without invoice_id
--   - Expense categories + expenses (Cash / Cheque / Online)
--   - Outstanding cheques (manual + invoice-linked)
--   - Sale returns (restore stock + reduce invoice bill on party ledger)
--   - Purchase orders + delivery orders (store management, stock on receive)
-- ============================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Auth users (login)
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(80)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop existing tables (reverse dependency order)
DROP TABLE IF EXISTS delivery_order_items;
DROP TABLE IF EXISTS delivery_orders;
DROP TABLE IF EXISTS purchase_order_items;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS bank_transactions;
DROP TABLE IF EXISTS payment_receipts;
DROP TABLE IF EXISTS outstanding_cheques;
DROP TABLE IF EXISTS sale_returns;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS stock;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS banks;
DROP TABLE IF EXISTS parties;
DROP TABLE IF EXISTS packings;
DROP TABLE IF EXISTS cities;

-- ============================================================
-- 0. Cities (master list for parties)
-- ============================================================
CREATE TABLE cities (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 0b. Packings (master list for inventory packing + weight)
-- ============================================================
CREATE TABLE packings (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)    NOT NULL,
  weight     DECIMAL(12,2)  NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_packings_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO packings (name, weight) VALUES
  ('10kg', 10),
  ('25kg', 25);

-- ============================================================
-- 1. Parties (Customers / Clients)
--    opening_balance : Previous/existing balance owed by client
--    city_id         : Linked city from cities master
-- ============================================================
CREATE TABLE parties (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)    NOT NULL,
  email         VARCHAR(180)    NULL,
  phone         VARCHAR(50)     NULL,
  company_name  VARCHAR(180)    NULL,
  city_id       INT             NULL,
  opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_parties_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
  INDEX idx_parties_city (city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Banks
--    opening_balance : Starting balance of the bank account
-- ============================================================
CREATE TABLE banks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  bank_name       VARCHAR(160)    NOT NULL,
  opening_balance DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  details         TEXT            NULL,
  created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Inventory
--    packing     : Denormalized packing name (from packings master)
--    packing_id  : Linked packing from packings master
--    piece       : Current stock quantity available
-- ============================================================
CREATE TABLE inventory (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  inventory_name VARCHAR(180)             NOT NULL,
  packing        VARCHAR(60)              NOT NULL DEFAULT '10kg',
  packing_id     INT                      NULL,
  rate           DECIMAL(15,2)            NOT NULL DEFAULT 0,
  piece          INT                      NOT NULL DEFAULT 0,
  created_at     TIMESTAMP                DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP                DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_packing FOREIGN KEY (packing_id) REFERENCES packings(id) ON DELETE RESTRICT,
  INDEX idx_inventory_packing (packing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. Sales
--    weight              : Number of pieces sold
--    price               : Price per piece
--    satisfaction_rating : Optional 1–5 star rating
-- ============================================================
CREATE TABLE sales (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  party_id            INT                              NOT NULL,
  inventory_id        INT                              NOT NULL,
  weight              DECIMAL(12,2)                   NOT NULL DEFAULT 0,
  price               DECIMAL(15,2)                   NOT NULL DEFAULT 0,
  payment_type        ENUM('Cash','Cheque','Online')   NOT NULL DEFAULT 'Cash',
  satisfaction_rating DECIMAL(2,1)                    NULL,
  sale_date           DATE                             NULL,
  created_at          TIMESTAMP                        DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP                        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_party     FOREIGN KEY (party_id)     REFERENCES parties(id)   ON DELETE CASCADE,
  CONSTRAINT fk_sales_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
  INDEX idx_sales_party     (party_id),
  INDEX idx_sales_inventory (inventory_id),
  INDEX idx_sales_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Stock (Inventory stock inward entries)
-- ============================================================
CREATE TABLE stock (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT           NOT NULL,
  price        DECIMAL(15,2) NOT NULL DEFAULT 0,
  piece        DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_date   DATE          NOT NULL,
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
  INDEX idx_stock_inventory (inventory_id),
  INDEX idx_stock_date      (stock_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. Invoices
--    sale_id        : Linked sale record
--    amount         : Total invoice amount (weight * price)
--    payment_status : Pending → Partial → Received (or Deferred)
-- ============================================================
CREATE TABLE invoices (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  sale_id        INT                                          NOT NULL,
  party_id       INT                                          NOT NULL,
  inventory_id   INT                                          NOT NULL,
  amount         DECIMAL(15,2)                                NOT NULL DEFAULT 0,
  payment_type   ENUM('Cash','Cheque','Online')               NOT NULL DEFAULT 'Cash',
  payment_status ENUM('Pending','Partial','Received','Deferred') NOT NULL DEFAULT 'Pending',
  invoice_date   TIMESTAMP                                    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_sale      FOREIGN KEY (sale_id)      REFERENCES sales(id)      ON DELETE CASCADE,
  CONSTRAINT fk_invoices_party     FOREIGN KEY (party_id)     REFERENCES parties(id)    ON DELETE CASCADE,
  CONSTRAINT fk_invoices_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id)  ON DELETE CASCADE,
  INDEX idx_invoices_party  (party_id),
  INDEX idx_invoices_status (payment_status),
  INDEX idx_invoices_date   (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6b. Sale Returns (restore stock + reduce invoice bill)
-- ============================================================
CREATE TABLE sale_returns (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT            NOT NULL,
  invoice_id   INT            NOT NULL,
  party_id     INT            NOT NULL,
  inventory_id INT            NOT NULL,
  weight       DECIMAL(12,2)  NOT NULL DEFAULT 0,
  price        DECIMAL(15,2)  NOT NULL DEFAULT 0,
  amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  return_date  DATE           NOT NULL,
  details      TEXT           NULL,
  created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sr_sale      FOREIGN KEY (sale_id)      REFERENCES sales(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_sr_invoice   FOREIGN KEY (invoice_id)   REFERENCES invoices(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_sr_party     FOREIGN KEY (party_id)     REFERENCES parties(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_sr_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id)  ON DELETE RESTRICT,
  INDEX idx_sr_sale (sale_id),
  INDEX idx_sr_invoice (invoice_id),
  INDEX idx_sr_party (party_id),
  INDEX idx_sr_date (return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. Payment Receipts
--    invoice_id : NULL for advance/general payments; set for invoice installments
--    bank_id    : NULL for Cash payments; required for Cheque/Online
--    details    : Description e.g. "Advance Balance", "Installment for Invoice #3"
-- ============================================================
CREATE TABLE payment_receipts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  party_id     INT                            NOT NULL,
  invoice_id   INT                            NULL,
  bank_id      INT                            NULL,
  amount       DECIMAL(15,2)                  NOT NULL DEFAULT 0,
  payment_type ENUM('Cash','Cheque','Online') NOT NULL DEFAULT 'Cash',
  receipt_date DATE                           NOT NULL,
  details      TEXT                           NULL,
  created_at   TIMESTAMP                      DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipts_party   FOREIGN KEY (party_id)   REFERENCES parties(id)  ON DELETE CASCADE,
  CONSTRAINT fk_receipts_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_receipts_bank    FOREIGN KEY (bank_id)    REFERENCES banks(id)    ON DELETE SET NULL,
  INDEX idx_receipts_party   (party_id),
  INDEX idx_receipts_invoice (invoice_id),
  INDEX idx_receipts_bank    (bank_id),
  INDEX idx_receipts_date    (receipt_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. Bank Transactions (Manual inflow / outflow entries)
-- ============================================================
CREATE TABLE bank_transactions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  bank_id          INT                      NOT NULL,
  type             ENUM('Inflow','Outflow') NOT NULL,
  amount           DECIMAL(15,2)            NOT NULL DEFAULT 0,
  transaction_date DATE                     NOT NULL,
  details          TEXT                     NULL,
  created_at       TIMESTAMP                DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transactions_bank FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE,
  INDEX idx_transactions_bank (bank_id),
  INDEX idx_transactions_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. Expense Categories
-- ============================================================
CREATE TABLE expense_categories (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  details    TEXT         NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. Expenses
--     category_id  : Linked expense category
--     bank_id      : NULL for Cash; required for Cheque/Online
-- ============================================================
CREATE TABLE expenses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  category_id  INT                            NOT NULL,
  bank_id      INT                            NULL,
  amount       DECIMAL(15,2)                  NOT NULL DEFAULT 0,
  payment_type ENUM('Cash','Cheque','Online') NOT NULL DEFAULT 'Cash',
  expense_date DATE                           NOT NULL,
  details      TEXT                           NULL,
  created_at   TIMESTAMP                      DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP                      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_bank     FOREIGN KEY (bank_id)     REFERENCES banks(id)              ON DELETE SET NULL,
  INDEX idx_expenses_category     (category_id),
  INDEX idx_expenses_bank         (bank_id),
  INDEX idx_expenses_date         (expense_date),
  INDEX idx_expenses_payment_type (payment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. Outstanding Cheques (Manual cheque tracking per client)
--     status : Pending → Received
-- ============================================================
CREATE TABLE outstanding_cheques (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  party_id    INT                          NOT NULL,
  amount      DECIMAL(15,2)                NOT NULL,
  cheque_date DATE                         NOT NULL,
  bank_id     INT                          NULL,
  details     TEXT                         NULL,
  status      ENUM('Pending','Received')   NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP                    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cheques_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_cheques_bank  FOREIGN KEY (bank_id)  REFERENCES banks(id)   ON DELETE SET NULL,
  INDEX idx_cheques_party  (party_id),
  INDEX idx_cheques_status (status),
  INDEX idx_cheques_date   (cheque_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. Purchase Orders (supplier buy orders)
-- ============================================================
CREATE TABLE purchase_orders (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  party_id      INT            NOT NULL,
  order_date    DATE           NOT NULL,
  status        ENUM('Draft','Ordered','Partial','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  remarks       TEXT           NULL,
  total_amount  DECIMAL(15,2)  NOT NULL DEFAULT 0,
  stock_applied TINYINT(1)     NOT NULL DEFAULT 0,
  created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_po_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
  INDEX idx_po_party (party_id),
  INDEX idx_po_date (order_date),
  INDEX idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT            NOT NULL,
  inventory_id      INT            NOT NULL,
  quantity          DECIMAL(12,2)  NOT NULL DEFAULT 0,
  rate              DECIMAL(15,2)  NOT NULL DEFAULT 0,
  amount            DECIMAL(15,2)  NOT NULL DEFAULT 0,
  CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
  INDEX idx_poi_po (purchase_order_id),
  INDEX idx_poi_inventory (inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. Delivery Orders (GRN / delivery against PO or standalone)
-- ============================================================
CREATE TABLE delivery_orders (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  party_id           INT            NOT NULL,
  purchase_order_id  INT            NULL,
  delivery_date      DATE           NOT NULL,
  status             ENUM('Pending','Dispatched','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  remarks            TEXT           NULL,
  total_amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  stock_applied      TINYINT(1)     NOT NULL DEFAULT 0,
  created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_do_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
  CONSTRAINT fk_do_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
  INDEX idx_do_party (party_id),
  INDEX idx_do_po (purchase_order_id),
  INDEX idx_do_date (delivery_date),
  INDEX idx_do_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_order_items (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  delivery_order_id  INT            NOT NULL,
  inventory_id       INT            NOT NULL,
  quantity           DECIMAL(12,2)  NOT NULL DEFAULT 0,
  rate               DECIMAL(15,2)  NOT NULL DEFAULT 0,
  amount             DECIMAL(15,2)  NOT NULL DEFAULT 0,
  CONSTRAINT fk_doi_do FOREIGN KEY (delivery_order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_doi_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
  INDEX idx_doi_do (delivery_order_id),
  INDEX idx_doi_inventory (inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Done! All tables created successfully. (Schema v3 + cities + packings + PO/DO)
-- ============================================================

