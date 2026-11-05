-- Invoice installment payments (run manually if DB already exists)

ALTER TABLE invoices
  MODIFY payment_status ENUM('Pending', 'Partial', 'Received', 'Deferred') NOT NULL DEFAULT 'Pending';

ALTER TABLE payment_receipts
  ADD COLUMN invoice_id INT NULL AFTER party_id,
  ADD INDEX idx_receipts_invoice (invoice_id),
  ADD CONSTRAINT fk_receipts_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL;
