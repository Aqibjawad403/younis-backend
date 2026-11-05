-- Cities master + party.city_id (run manually if needed; also auto in db.php)

CREATE TABLE IF NOT EXISTS cities (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add city_id to parties (ignore error if already exists)
ALTER TABLE parties
  ADD COLUMN city_id INT NULL AFTER company_name;

ALTER TABLE parties
  ADD CONSTRAINT fk_parties_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL;

ALTER TABLE parties
  ADD INDEX idx_parties_city (city_id);
