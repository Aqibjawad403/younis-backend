-- Sales table migration (run manually if DB already exists)
ALTER TABLE sales ADD COLUMN inventory_id INT NOT NULL AFTER party_id;
ALTER TABLE sales DROP COLUMN article_name;
ALTER TABLE sales ADD CONSTRAINT fk_sales_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE;

-- Customer satisfaction rating for each sale/service (1.0 to 5.0)
ALTER TABLE sales ADD COLUMN satisfaction_rating DECIMAL(2, 1) NULL AFTER payment_type;

-- Bill / sale date (user-selected; falls back to created_at date)
ALTER TABLE sales ADD COLUMN sale_date DATE NULL AFTER satisfaction_rating;
UPDATE sales SET sale_date = DATE(created_at) WHERE sale_date IS NULL;

-- Stock table for inventory stock entries
CREATE TABLE IF NOT EXISTS stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  price DECIMAL(15, 2) NOT NULL DEFAULT 0,
  piece DECIMAL(12, 2) NOT NULL DEFAULT 0,
  stock_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
);
