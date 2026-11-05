-- Expense categories & expenses (run manually if DB already exists)

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  bank_id INT NULL,
  amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
  payment_type ENUM('Cash', 'Cheque', 'Online') NOT NULL DEFAULT 'Cash',
  expense_date DATE NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_bank FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
  INDEX idx_expenses_category (category_id),
  INDEX idx_expenses_bank (bank_id),
  INDEX idx_expenses_date (expense_date),
  INDEX idx_expenses_payment_type (payment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
