-- Packings master + inventory.packing_id
-- Run manually in phpMyAdmin if needed (also auto-migrates in db.php)

CREATE TABLE IF NOT EXISTS packings (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)    NOT NULL,
  weight     DECIMAL(12,2)  NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_packings_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO packings (name, weight)
SELECT '10kg', 10 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packings WHERE LOWER(name) = '10kg');

INSERT INTO packings (name, weight)
SELECT '25kg', 25 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packings WHERE LOWER(name) = '25kg');

-- Allow any packing name (was ENUM 10kg/25kg)
ALTER TABLE inventory
  MODIFY COLUMN packing VARCHAR(60) NOT NULL DEFAULT '10kg';

ALTER TABLE inventory
  ADD COLUMN packing_id INT NULL AFTER packing;

UPDATE inventory i
INNER JOIN packings p ON LOWER(p.name) = LOWER(i.packing)
SET i.packing_id = p.id
WHERE i.packing_id IS NULL;

ALTER TABLE inventory
  ADD CONSTRAINT fk_inventory_packing FOREIGN KEY (packing_id) REFERENCES packings(id) ON DELETE RESTRICT;

ALTER TABLE inventory
  ADD INDEX idx_inventory_packing (packing_id);
