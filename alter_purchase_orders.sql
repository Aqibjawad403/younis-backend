-- Purchase Orders + Delivery Orders (run manually if DB already exists)
-- Store management: PO → DO → stock inward on receive/deliver

CREATE TABLE IF NOT EXISTS purchase_orders (
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

CREATE TABLE IF NOT EXISTS purchase_order_items (
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

CREATE TABLE IF NOT EXISTS delivery_orders (
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

CREATE TABLE IF NOT EXISTS delivery_order_items (
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
