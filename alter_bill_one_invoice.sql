-- ============================================================
-- Multi-product bill → ONE invoice (SAFE — no data delete)
-- Run on factory DB in phpMyAdmin if soft-migrate did not run.
-- ============================================================

-- 1) Group key on sales (existing rows: bill_id = id)
ALTER TABLE sales ADD COLUMN bill_id INT NULL AFTER id;
-- If duplicate column error: ignore and continue
ALTER TABLE sales ADD INDEX idx_sales_bill (bill_id);
UPDATE sales SET bill_id = id WHERE bill_id IS NULL;

-- 2) Group key on invoices
ALTER TABLE invoices ADD COLUMN bill_id INT NULL AFTER id;
ALTER TABLE invoices ADD INDEX idx_invoices_bill (bill_id);
UPDATE invoices inv
INNER JOIN sales s ON inv.sale_id = s.id
SET inv.bill_id = COALESCE(s.bill_id, s.id)
WHERE inv.bill_id IS NULL;

-- 3) Invoice line items (products under one invoice)
CREATE TABLE IF NOT EXISTS invoice_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id   INT            NOT NULL,
  sale_id      INT            NOT NULL,
  inventory_id INT            NOT NULL,
  weight       DECIMAL(12,2)  NOT NULL DEFAULT 0,
  price        DECIMAL(15,2)  NOT NULL DEFAULT 0,
  amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ii_invoice (invoice_id),
  INDEX idx_ii_sale (sale_id),
  INDEX idx_ii_inventory (inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional FKs (skip if they already exist / error)
-- ALTER TABLE invoice_items ADD CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE;
-- ALTER TABLE invoice_items ADD CONSTRAINT fk_ii_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE;
-- ALTER TABLE invoice_items ADD CONSTRAINT fk_ii_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT;

-- 4) Backfill invoice_items for OLD 1-product invoices (only if empty)
INSERT INTO invoice_items (invoice_id, sale_id, inventory_id, weight, price, amount)
SELECT inv.id, inv.sale_id, inv.inventory_id, s.weight, s.price, inv.amount
FROM invoices inv
INNER JOIN sales s ON s.id = inv.sale_id
WHERE NOT EXISTS (
  SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = inv.id
)
AND inv.inventory_id IS NOT NULL;

-- NOTE:
-- Purani multi-product sales (jo pehle 2 alag invoices thin) merge NAHI hongi.
-- Unka data same rahega. Sirf NAYI bills ek invoice banayengi.
