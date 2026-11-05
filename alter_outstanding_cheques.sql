-- Manual outstanding cheques (client, date, amount)
CREATE TABLE IF NOT EXISTS outstanding_cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  amount DECIMAL(15, 2) NOT NULL,
  cheque_date DATE NOT NULL,
  bank_id INT NULL,
  details TEXT NULL,
  status ENUM('Pending', 'Received') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL
);
