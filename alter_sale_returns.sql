-- Sale returns: restore stock + reduce invoice bill amount (party ledger)
CREATE TABLE IF NOT EXISTS sale_returns (
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
  stock_id     INT            NULL,
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

-- Link return to stock ledger row for clean reverse on delete
-- ALTER TABLE sale_returns ADD COLUMN stock_id INT NULL AFTER details;
